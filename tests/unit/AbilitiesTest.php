<?php

declare( strict_types=1 );

use Novamira\MainWP\Abilities;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/confirmation-database.php';

final class AbilitiesTest extends TestCase {
	private $previous_database;
	private $database;

	protected function setUp(): void {
		$GLOBALS['nmm_abilities'] = array();
		$GLOBALS['nmm_capabilities'] = array();
		$GLOBALS['nmm_current_user_id'] = 7;
		$GLOBALS['nmm_filters'] = array();
		$this->previous_database = $GLOBALS['wpdb'];
		$this->database = new NMM_Confirmation_WPDB();
		$GLOBALS['wpdb'] = $this->database;
		NMM_Test_MainWP_DB::$sites = array(
			1 => (object) array( 'id' => 1, 'name' => 'First site', 'url' => 'https://first.example', 'adminname' => 'admin' ),
			2 => (object) array( 'id' => 2, 'name' => 'Second site', 'url' => 'https://second.example', 'adminname' => 'admin' ),
		);
		add_filter( 'mainwp_fetchurlauthed', static function () { return new WP_Error( 'test_remote_not_expected', 'This test must not contact a child.' ); } );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_database;
		$GLOBALS['nmm_capabilities'] = array();
		$GLOBALS['nmm_current_user_id'] = 7;
		$GLOBALS['nmm_filters'] = array();
		NMM_Test_MainWP_DB::$sites = array();
	}

	public function test_all_versioned_fleet_interfaces_are_registered(): void {
		Abilities::register();
		$expected = array( 'list-sites-v1', 'get-site-v1', 'provision-sites-v1', 'set-site-policy-v1', 'rotate-credential-v1', 'revoke-credential-v1', 'manage-pro-license-v1', 'list-components-v1', 'call-read-tool-v1', 'fanout-read-tool-v1', 'call-write-tool-v1', 'read-resource-v1', 'get-prompt-v1' );
		self::assertSame( array_map( static function ( string $slug ): string { return 'novamira-mainwp/' . $slug; }, $expected ), array_keys( $GLOBALS['nmm_abilities'] ) );
		$write = $GLOBALS['nmm_abilities']['novamira-mainwp/call-write-tool-v1'];
		self::assertTrue( $write['meta']['annotations']['destructive'] );
		self::assertArrayNotHasKey( 'confirm', $write['input_schema']['properties'] );
		self::assertArrayHasKey( 'user_confirmed', $write['input_schema']['properties'] );
		self::assertArrayHasKey( 'confirmation_token', $write['input_schema']['properties'] );
		self::assertArrayHasKey( 'dry_run', $write['input_schema']['properties'] );
		self::assertTrue( $GLOBALS['nmm_abilities']['novamira-mainwp/list-sites-v1']['meta']['annotations']['readonly'] );
		$operations = $GLOBALS['nmm_abilities']['novamira-mainwp/provision-sites-v1']['input_schema']['properties']['operation']['enum'];
		self::assertContains( 'install-free', $operations );
		self::assertContains( 'repair-free', $operations );
		self::assertContains( 'disable-ai', $operations );
		self::assertContains( 'install-activate-pro', $operations );
		self::assertContains( 'activate-pro-license', $operations );
	}

	public function test_every_ability_exposes_a_complete_standard_discovery_contract(): void {
		Abilities::register();

		foreach ( $GLOBALS['nmm_abilities'] as $ability ) {
			self::assertNotSame( '', $ability['label'] );
			self::assertNotSame( '', $ability['description'] );
			self::assertSame( 'novamira-mainwp', $ability['category'] );
			self::assertSame( 'object', $ability['input_schema']['type'] );
			self::assertSame( 'object', $ability['output_schema']['type'] );
			self::assertIsCallable( $ability['execute_callback'] );
			self::assertIsCallable( $ability['permission_callback'] );
			self::assertTrue( $ability['meta']['public'] );
			self::assertTrue( $ability['meta']['show_in_rest'] );
			self::assertTrue( $ability['meta']['mcp']['public'] );
			self::assertSame( 'tool', $ability['meta']['mcp']['type'] );
			self::assertArrayHasKey( 'readonly', $ability['meta']['annotations'] );
			self::assertArrayHasKey( 'destructive', $ability['meta']['annotations'] );
			self::assertArrayHasKey( 'idempotent', $ability['meta']['annotations'] );
			self::assertArrayHasKey( 'instructions', $ability['meta']['annotations'] );
		}
	}

	public function test_every_registered_callback_denies_users_without_manage_options(): void {
		Abilities::register();
		$GLOBALS['nmm_capabilities'] = array( 'read' => true, 'manage_options' => false );
		foreach ( $GLOBALS['nmm_abilities'] as $ability ) {
			self::assertInstanceOf( WP_Error::class, call_user_func( $ability['permission_callback'] ) );
			self::assertInstanceOf( WP_Error::class, call_user_func( $ability['execute_callback'], array() ) );
		}
		self::assertInstanceOf( WP_Error::class, Abilities::revoke_credential( array( 'site_id' => 1, 'dry_run' => true ) ) );
		self::assertSame( array(), $this->database->confirmation_rows );
	}

	public function test_each_destructive_ability_rejects_boolean_only_execution_and_issues_a_bound_preview(): void {
		Abilities::register();
		foreach ( $GLOBALS['nmm_abilities'] as $name => $ability ) {
			if ( ! $ability['meta']['annotations']['destructive'] ) continue;
			$input = 'novamira-mainwp/provision-sites-v1' === $name
				? array( 'site_ids' => array( 1, 2 ), 'operation' => 'disable-ai' )
				: array( 'site_id' => 1 );
			$legacy = call_user_func( $ability['execute_callback'], $input + array( 'confirm' => true ) );
			self::assertInstanceOf( WP_Error::class, $legacy );
			self::assertSame( 'novamira_mainwp_confirmation_required', $legacy->get_error_code() );
			$boolean = call_user_func( $ability['execute_callback'], $input + array( 'user_confirmed' => true ) );
			self::assertInstanceOf( WP_Error::class, $boolean );
			$preview = call_user_func( $ability['execute_callback'], $input + array( 'dry_run' => true ) );
			self::assertIsArray( $preview );
			self::assertSame( $name, $preview['ability'] );
			self::assertNotEmpty( $preview['confirmation_token'] );
		}
	}

	public function test_preview_denies_forbidden_suspended_missing_and_non_integer_sites(): void {
		NMM_Test_MainWP_DB::$sites[2]->forbidden = true;
		self::assertSame( 'novamira_mainwp_site_forbidden', Abilities::revoke_credential( array( 'site_id' => 2, 'dry_run' => true ) )->get_error_code() );
		self::assertSame( 'novamira_mainwp_site_forbidden', Abilities::provision( array( 'site_ids' => array( 1, 2 ), 'operation' => 'disable-ai', 'dry_run' => true ) )->get_error_code() );
		NMM_Test_MainWP_DB::$sites[2]->forbidden = false;
		NMM_Test_MainWP_DB::$sites[2]->suspended = true;
		self::assertSame( 'novamira_mainwp_site_suspended', Abilities::revoke_credential( array( 'site_id' => 2, 'dry_run' => true ) )->get_error_code() );
		self::assertSame( 'novamira_mainwp_site_not_found', Abilities::revoke_credential( array( 'site_id' => 3, 'dry_run' => true ) )->get_error_code() );
		self::assertSame( 'novamira_mainwp_confirmation_invalid_input', Abilities::revoke_credential( array( 'site_id' => '1', 'dry_run' => true ) )->get_error_code() );
		self::assertSame( array(), $this->database->confirmation_rows );
	}

	public function test_site_authorization_is_rechecked_before_consuming_a_valid_token(): void {
		$preview = Abilities::revoke_credential( array( 'site_id' => 1, 'dry_run' => true ) );
		$input = array( 'site_id' => 1, 'user_confirmed' => true, 'confirmation_token' => $preview['confirmation_token'] );
		NMM_Test_MainWP_DB::$sites[1]->forbidden = true;
		self::assertSame( 'novamira_mainwp_site_forbidden', Abilities::revoke_credential( $input )->get_error_code() );
		self::assertCount( 1, $this->database->confirmation_rows );
		NMM_Test_MainWP_DB::$sites[1]->forbidden = false;
		self::assertIsArray( Abilities::revoke_credential( $input ) );
		self::assertSame( 'novamira_mainwp_confirmation_invalid', Abilities::revoke_credential( $input )->get_error_code() );
	}

	public function test_changed_tool_arguments_and_license_secrets_do_not_match_the_preview(): void {
		$input = array( 'site_id' => 1, 'tool_name' => 'delete-post', 'arguments' => array( 'id' => 12 ) );
		$preview = Abilities::call_write_tool( $input + array( 'dry_run' => true ) );
		self::assertArrayNotHasKey( 'arguments', $preview['preview'] );
		self::assertSame( array( 'id' ), $preview['preview']['argument_keys'] );
		$input['arguments']['id'] = 13;
		self::assertSame( 'novamira_mainwp_confirmation_invalid', Abilities::call_write_tool( $input + array( 'user_confirmed' => true, 'confirmation_token' => $preview['confirmation_token'] ) )->get_error_code() );
		$license = array( 'site_id' => 1, 'operation' => 'activate', 'license_key' => 'first-secret' );
		$preview = Abilities::manage_pro_license( $license + array( 'dry_run' => true ) );
		self::assertArrayNotHasKey( 'license_key', $preview['preview'] );
		$license['license_key'] = 'second-secret';
		self::assertSame( 'novamira_mainwp_confirmation_invalid', Abilities::manage_pro_license( $license + array( 'user_confirmed' => true, 'confirmation_token' => $preview['confirmation_token'] ) )->get_error_code() );
	}

	public function test_token_is_consumed_before_even_a_failed_remote_action(): void {
		$input = array( 'site_id' => 1, 'gateway_enabled' => false, 'production_allowed' => false, 'fanout_read_allowed' => false );
		$preview = Abilities::set_policy( $input + array( 'dry_run' => true ) );
		$execution = $input + array( 'user_confirmed' => true, 'confirmation_token' => $preview['confirmation_token'] );
		self::assertSame( 'test_remote_not_expected', Abilities::set_policy( $execution )->get_error_code() );
		self::assertSame( array(), $this->database->confirmation_rows );
		self::assertSame( 'novamira_mainwp_confirmation_invalid', Abilities::set_policy( $execution )->get_error_code() );
	}
}
