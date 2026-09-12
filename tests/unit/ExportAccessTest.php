<?php

declare( strict_types=1 );

use Novamira\MainWP\Export_Access;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/confirmation-database.php';

final class ExportAccessTest extends TestCase {
	private $previous_db;
	protected function setUp(): void {
		$this->previous_db = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new NMM_Confirmation_WPDB();
		$GLOBALS['nmm_filters'] = array();
		add_filter( 'mainwp_fetchurlauthed', static function ( $value ) { return $value; } );
		$GLOBALS['nmm_capabilities'] = array();
		$GLOBALS['nmm_current_user_id'] = 7;
		$GLOBALS['nmm_session_token'] = 'browser-one';
		NMM_Test_MainWP_DB::$sites = array(
			1 => (object) array( 'id' => 1, 'name' => 'One', 'url' => 'https://one.test', 'suspended' => 0 ),
			2 => (object) array( 'id' => 2, 'name' => 'Two', 'url' => 'https://two.test', 'suspended' => 0 ),
		);
	}
	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_db;
		$GLOBALS['nmm_filters'] = array();
		$GLOBALS['nmm_capabilities'] = array();
		unset( $GLOBALS['nmm_current_user_id'], $GLOBALS['nmm_session_token'] );
	}
	private function enable(): void {
		add_filter( 'novamira_mainwp_credential_export_enabled', static function () { return true; } );
		add_filter( 'novamira_mainwp_credential_export_step_up', static function () { return true; } );
	}
	public function test_export_defaults_disabled_and_opt_in_alone_does_not_bypass_step_up(): void {
		self::assertSame( 'novamira_mainwp_export_disabled', Export_Access::preview( array( 1 ), 'codex' )->get_error_code() );
		add_filter( 'novamira_mainwp_credential_export_enabled', static function () { return true; } );
		self::assertSame( 'novamira_mainwp_export_step_up', Export_Access::preview( array( 1 ), 'codex' )->get_error_code() );
	}
	public function test_empty_or_forbidden_selection_and_denied_user_fail_closed(): void {
		$this->enable();
		self::assertTrue( is_wp_error( Export_Access::preview( array(), 'codex' ) ) );
		self::assertTrue( is_wp_error( Export_Access::preview( array( '1oops' ), 'codex' ) ) );
		NMM_Test_MainWP_DB::$sites[2]->forbidden = true;
		self::assertTrue( is_wp_error( Export_Access::preview( array( 1, 2 ), 'codex' ) ) );
		$GLOBALS['nmm_capabilities']['manage_options'] = false;
		self::assertTrue( is_wp_error( Export_Access::preview( array( 1 ), 'codex' ) ) );
	}
	public function test_download_is_bound_to_sites_format_and_browser_and_is_single_use(): void {
		$this->enable();
		$preview = Export_Access::preview( array( 1 ), 'codex' );
		self::assertIsArray( $preview );
		$token = $preview['confirmation_token'];
		self::assertTrue( is_wp_error( Export_Access::consume( array( 1 ), 'codex', $token, false ) ) );
		self::assertTrue( is_wp_error( Export_Access::consume( array( 1, 2 ), 'codex', $token, true ) ) );
		self::assertTrue( is_wp_error( Export_Access::consume( array( 1 ), 'cursor', $token, true ) ) );
		$GLOBALS['nmm_session_token'] = 'browser-two';
		self::assertTrue( is_wp_error( Export_Access::consume( array( 1 ), 'codex', $token, true ) ) );
		$GLOBALS['nmm_session_token'] = 'browser-one';
		self::assertSame( array( 1 ), Export_Access::consume( array( 1 ), 'codex', $token, true ) );
		self::assertTrue( is_wp_error( Export_Access::consume( array( 1 ), 'codex', $token, true ) ) );
	}
	public function test_step_up_and_site_authorization_are_checked_again_at_download(): void {
		$this->enable();
		$preview = Export_Access::preview( array( 1 ), 'codex' );
		NMM_Test_MainWP_DB::$sites[1]->forbidden = true;
		self::assertTrue( is_wp_error( Export_Access::consume( array( 1 ), 'codex', $preview['confirmation_token'], true ) ) );
		NMM_Test_MainWP_DB::$sites[1]->forbidden = false;
		$GLOBALS['nmm_filters']['novamira_mainwp_credential_export_step_up'] = array();
		self::assertSame( 'novamira_mainwp_export_step_up', Export_Access::consume( array( 1 ), 'codex', $preview['confirmation_token'], true )->get_error_code() );
	}
}
