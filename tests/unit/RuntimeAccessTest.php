<?php

declare( strict_types=1 );

use Novamira\MainWP\Runtime_Access;
use Novamira\MainWP\Storage;
use Novamira\MainWP\Database_Lock;
use Novamira\MainWP\MainWP_Client;
use PHPUnit\Framework\TestCase;

final class RuntimeAccessTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb']                 = new NMM_Test_WPDB();
		$GLOBALS['nmm_filters']          = array();
		$GLOBALS['nmm_options']          = array();
		$GLOBALS['nmm_scheduled']        = array();
		$GLOBALS['nmm_runtime_actions']  = array();
		$GLOBALS['wpdb']->site_records   = array();
		NMM_Test_MainWP_DB::$sites = array(
			9 => (object) array( 'id' => 9, 'name' => 'Runtime site', 'url' => 'https://runtime.test', 'adminname' => 'admin', 'suspended' => 0 ),
		);
		add_filter(
			'mainwp_fetchurlauthed',
			static function ( $plugin_file, $key, int $site_id, string $what, array $params ): array {
				$payload = nmm_child_action( $params );
				$action  = (string) ( $payload['action'] ?? '' );
				$GLOBALS['nmm_runtime_actions'][] = array( 'action' => $action, 'what' => $what );
				$data = in_array( $action, array( 'ai-open', 'ai-snapshot' ), true )
					? array( 'changed' => true, 'restore' => array( 'missing' => 'missing-9', 'enabled' => '0', 'domain' => 'missing-9' ) )
					: array( 'restored' => true );
				return nmm_child_response( $params, $data );
			},
			10,
			6
		);
		Storage::update_site(
			9,
			array(
				'policy' => array(
					'gateway_enabled'     => true,
					'production_allowed'  => true,
					'ai_lifecycle'        => 'just-in-time',
					'fanout_read_allowed' => false,
				),
			)
		);
	}

	public function test_overlapping_windows_open_once_and_restore_after_final_release(): void {
		$first  = Runtime_Access::acquire( 9 );
		$second = Runtime_Access::acquire( 9 );

		self::assertFalse( is_wp_error( $first ) );
		self::assertNotSame( $first['token'], $second['token'] );
		self::assertSame( array( 'ai-snapshot', 'ai-open' ), array_column( $GLOBALS['nmm_runtime_actions'], 'action' ) );
		self::assertFalse( Runtime_Access::release( 9, $first['token'] )['restored'] );
		self::assertTrue( Runtime_Access::release( 9, $second['token'] )['restored'] );
		self::assertSame( array( 'ai-snapshot', 'ai-open', 'ai-restore' ), array_column( $GLOBALS['nmm_runtime_actions'], 'action' ) );
		self::assertSame( array(), get_option( 'novamira_mainwp_runtime_state', array() ) );
	}

	public function test_expired_window_is_restored_by_request_fallback(): void {
		$lease = Runtime_Access::acquire( 9 );
		$state = get_option( 'novamira_mainwp_runtime_state', array() );
		$state['9']['leases'][ $lease['token'] ] = time() - 1;
		$state['9']['expires'] = time() - 1;
		update_option( 'novamira_mainwp_runtime_state', $state, false );

		Runtime_Access::recover_expired( 9 );

		self::assertSame( array( 'ai-snapshot', 'ai-open', 'ai-restore' ), array_column( $GLOBALS['nmm_runtime_actions'], 'action' ) );
		self::assertSame( array(), get_option( 'novamira_mainwp_runtime_state', array() ) );
	}

	public function test_disabled_gateway_fails_before_child_execution(): void {
		Storage::update_site( 9, array( 'policy' => array( 'gateway_enabled' => false ) ) );
		$result = Runtime_Access::acquire( 9 );

		self::assertSame( 'novamira_mainwp_gateway_disabled', $result->get_error_code() );
		self::assertSame( array(), $GLOBALS['nmm_runtime_actions'] );
	}

	public function test_database_lock_failure_prevents_any_child_call(): void {
		$GLOBALS['wpdb']->lock_unavailable = true;
		self::assertSame( 'novamira_mainwp_state_locked', Runtime_Access::acquire( 9 )->get_error_code() );
		self::assertSame( array(), $GLOBALS['nmm_runtime_actions'] );
	}

	public function test_restore_snapshot_is_durable_before_ai_is_opened(): void {
		add_filter( 'mainwp_fetchurlauthed', static function ( $response, $key, $site_id, $what, $params ) {
			if ( 'ai-open' === nmm_child_action( $params )['action'] ) {
				$entry = get_option( 'novamira_mainwp_runtime_state' )['9'];
				self::assertTrue( $entry['opening'] );
				self::assertSame( '0', $entry['restore']['enabled'] );
				self::assertCount( 1, $entry['leases'] );
				self::assertNotEmpty( $GLOBALS['nmm_scheduled'] );
			}
			return $response;
		}, 20, 6 );
		self::assertIsArray( Runtime_Access::acquire( 9 ) );
		self::assertSame( array(), $GLOBALS['wpdb']->named_locks );
	}

	public function test_journal_write_failure_prevents_ai_open(): void {
		$GLOBALS['wpdb']->fail_option = 'novamira_mainwp_runtime_state';
		self::assertSame( 'novamira_mainwp_runtime_persistence_failed', Runtime_Access::acquire( 9 )->get_error_code() );
		self::assertSame( array( 'ai-snapshot' ), array_column( $GLOBALS['nmm_runtime_actions'], 'action' ) );
		self::assertSame( array(), $GLOBALS['wpdb']->named_locks );
	}

	public function test_read_failure_does_not_open_a_new_window(): void {
		$GLOBALS['wpdb']->fail_read_option = 'novamira_mainwp_runtime_state';
		self::assertSame( 'novamira_mainwp_state_read_failed', Runtime_Access::acquire( 9 )->get_error_code() );
		self::assertSame( array(), $GLOBALS['nmm_runtime_actions'] );
	}

	public function test_lost_open_response_retains_snapshot_and_blocks_join_until_recovery(): void {
		add_filter( 'mainwp_fetchurlauthed', static function ( $response, $key, $site_id, $what, $params ) {
			return 'ai-open' === nmm_child_action( $params )['action'] ? new WP_Error( 'transport_timeout', 'Timeout.' ) : $response;
		}, 20, 6 );
		self::assertSame( 'transport_timeout', Runtime_Access::acquire( 9 )->get_error_code() );
		self::assertSame( 'novamira_mainwp_runtime_recovering', Runtime_Access::acquire( 9 )->get_error_code() );
		$state = get_option( 'novamira_mainwp_runtime_state' );
		self::assertSame( '0', $state['9']['restore']['enabled'] );
		$state['9']['expires'] = time() - 1;
		$state['9']['leases'] = array_fill_keys( array_keys( $state['9']['leases'] ), time() - 1 );
		update_option( 'novamira_mainwp_runtime_state', $state );
		Runtime_Access::recover_expired();
		self::assertSame( array(), get_option( 'novamira_mainwp_runtime_state', array() ) );
		self::assertSame( array( 'ai-snapshot', 'ai-open', 'ai-restore' ), array_column( $GLOBALS['nmm_runtime_actions'], 'action' ) );
	}

	public function test_failed_restore_retains_journal_for_retry(): void {
		$lease = Runtime_Access::acquire( 9 );
		add_filter( 'mainwp_fetchurlauthed', static function () { return new WP_Error( 'restore_timeout', 'Timeout.' ); }, 20, 6 );
		self::assertSame( 'restore_timeout', Runtime_Access::release( 9, $lease['token'] )->get_error_code() );
		$entry = get_option( 'novamira_mainwp_runtime_state' )['9'];
		self::assertSame( array(), $entry['leases'] );
		self::assertSame( '0', $entry['restore']['enabled'] );
		self::assertGreaterThan( time(), $entry['expires'] );
	}

	public function test_early_cleanup_does_not_remove_an_active_lease(): void {
		$lease = Runtime_Access::acquire( 9 );
		Runtime_Access::cleanup( 9, $lease['token'] );
		self::assertArrayHasKey( $lease['token'], get_option( 'novamira_mainwp_runtime_state' )['9']['leases'] );
		self::assertCount( 2, $GLOBALS['nmm_runtime_actions'] );
	}

	public function test_confirmed_unchanged_window_does_not_restore_over_later_manual_settings(): void {
		add_filter( 'mainwp_fetchurlauthed', static function ( $response, $key, $site_id, $what, $params ) {
			return 'ai-open' === nmm_child_action( $params )['action'] ? nmm_child_response( $params, array( 'changed' => false ) ) : $response;
		}, 20, 6 );
		$lease = Runtime_Access::acquire( 9 );
		self::assertFalse( $lease['changed'] );
		self::assertFalse( Runtime_Access::release( 9, $lease['token'] )['restored'] );
		self::assertSame( array( 'ai-snapshot', 'ai-open' ), array_column( $GLOBALS['nmm_runtime_actions'], 'action' ) );
		self::assertSame( array(), get_option( 'novamira_mainwp_runtime_state', array() ) );
	}

	public function test_missing_changed_field_keeps_uncertain_window_pending(): void {
		add_filter( 'mainwp_fetchurlauthed', static function ( $response, $key, $site_id, $what, $params ) {
			return 'ai-open' === nmm_child_action( $params )['action'] ? nmm_child_response( $params, array() ) : $response;
		}, 20, 6 );
		self::assertSame( 'novamira_mainwp_runtime_open_invalid', Runtime_Access::acquire( 9 )->get_error_code() );
		self::assertTrue( get_option( 'novamira_mainwp_runtime_state' )['9']['opening'] );
	}

	/** @dataProvider background_users */
	public function test_expired_cleanup_restores_only_journaled_state_without_current_site_permission( int $user_id ): void {
		$lease = Runtime_Access::acquire( 9 );
		$GLOBALS['nmm_current_user_id'] = $user_id;
		NMM_Test_MainWP_DB::$sites[9]->forbidden = true;
		self::assertSame( 'novamira_mainwp_site_forbidden', MainWP_Client::site( 9 )->get_error_code() );
		$state = get_option( 'novamira_mainwp_runtime_state' );
		$state['9']['leases'][ $lease['token'] ] = time() - 1;
		$state['9']['expires'] = time() - 1;
		update_option( 'novamira_mainwp_runtime_state', $state );
		Runtime_Access::cleanup( 9, $lease['token'] );
		self::assertSame( array(), get_option( 'novamira_mainwp_runtime_state', array() ) );
		self::assertSame( array( 'ai-snapshot', 'ai-open', 'ai-restore' ), array_column( $GLOBALS['nmm_runtime_actions'], 'action' ) );
	}

	public function background_users(): array { return array( 'cron' => array( 0 ), 'unrelated-user' => array( 22 ) ); }

	public function test_internal_restore_rejects_wrong_lock_and_live_leases(): void {
		Runtime_Access::acquire( 9 );
		$wrong_lock = Database_Lock::acquire( 'default-license' );
		self::assertSame( 'novamira_mainwp_restore_not_authorized', MainWP_Client::restore_runtime_window( 9, $wrong_lock )->get_error_code() );
		$wrong_lock->release();
		$runtime_lock = Database_Lock::acquire( 'runtime-state' );
		self::assertSame( 'novamira_mainwp_restore_not_authorized', MainWP_Client::restore_runtime_window( 9, $runtime_lock )->get_error_code() );
		$runtime_lock->release();
		self::assertCount( 2, $GLOBALS['nmm_runtime_actions'] );
	}

	public function test_cron_cleanup_uses_signed_transport_when_extension_hook_denies_logged_out_user(): void {
		$lease = Runtime_Access::acquire( 9 );
		$GLOBALS['nmm_current_user_id'] = 0;
		NMM_Test_MainWP_DB::$sites[9]->forbidden = true;
		$GLOBALS['nmm_filters']['mainwp_fetchurlauthed'] = array();
		$GLOBALS['nmm_direct_restore_handler'] = static function ( $site, $what, $params ) {
			self::assertSame( 9, $site->id );
			self::assertSame( 'code_snippet', $what );
			$payload = nmm_child_action( $params );
			self::assertSame( 'ai-restore', $payload['action'] );
			self::assertSame( '0', $payload['params']['restore']['enabled'] );
			return nmm_child_response( $params, array( 'restored' => true ) );
		};
		$state = get_option( 'novamira_mainwp_runtime_state' );
		$state['9']['leases'][ $lease['token'] ] = time() - 1;
		$state['9']['expires'] = time() - 1;
		update_option( 'novamira_mainwp_runtime_state', $state );
		Runtime_Access::cleanup( 9, $lease['token'] );
		self::assertSame( array(), get_option( 'novamira_mainwp_runtime_state', array() ) );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = new NMM_Test_WPDB();
		unset( $GLOBALS['nmm_current_user_id'] );
		unset( $GLOBALS['nmm_direct_restore_handler'] );
	}
}
