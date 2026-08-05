<?php

declare( strict_types=1 );

use Novamira\MainWP\Runtime_Access;
use Novamira\MainWP\Storage;
use PHPUnit\Framework\TestCase;

final class RuntimeAccessTest extends TestCase {
	protected function setUp(): void {
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
				$data = 'ai-open' === $action
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

	public function test_concurrent_windows_open_once_and_restore_after_final_release(): void {
		$first  = Runtime_Access::acquire( 9 );
		$second = Runtime_Access::acquire( 9 );

		self::assertFalse( is_wp_error( $first ) );
		self::assertNotSame( $first['token'], $second['token'] );
		self::assertSame( array( 'ai-open' ), array_column( $GLOBALS['nmm_runtime_actions'], 'action' ) );
		self::assertFalse( Runtime_Access::release( 9, $first['token'] )['restored'] );
		self::assertTrue( Runtime_Access::release( 9, $second['token'] )['restored'] );
		self::assertSame( array( 'ai-open', 'ai-restore' ), array_column( $GLOBALS['nmm_runtime_actions'], 'action' ) );
		self::assertSame( array(), get_option( 'novamira_mainwp_runtime_state', array() ) );
	}

	public function test_expired_window_is_restored_by_request_fallback(): void {
		$lease = Runtime_Access::acquire( 9 );
		$state = get_option( 'novamira_mainwp_runtime_state', array() );
		$state['9']['leases'][ $lease['token'] ] = time() - 1;
		$state['9']['expires'] = time() - 1;
		update_option( 'novamira_mainwp_runtime_state', $state, false );

		Runtime_Access::recover_expired( 9 );

		self::assertSame( array( 'ai-open', 'ai-restore' ), array_column( $GLOBALS['nmm_runtime_actions'], 'action' ) );
		self::assertSame( array(), get_option( 'novamira_mainwp_runtime_state', array() ) );
	}

	public function test_disabled_gateway_fails_before_child_execution(): void {
		Storage::update_site( 9, array( 'policy' => array( 'gateway_enabled' => false ) ) );
		$result = Runtime_Access::acquire( 9 );

		self::assertSame( 'novamira_mainwp_gateway_disabled', $result->get_error_code() );
		self::assertSame( array(), $GLOBALS['nmm_runtime_actions'] );
	}
}
