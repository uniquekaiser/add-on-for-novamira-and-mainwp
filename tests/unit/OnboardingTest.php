<?php

declare( strict_types=1 );

use Novamira\MainWP\Onboarding;
use Novamira\MainWP\Storage;
use PHPUnit\Framework\TestCase;

final class OnboardingTest extends TestCase {
	protected function setUp(): void {
		$_POST                            = array();
		$GLOBALS['nmm_options']           = array();
		$GLOBALS['nmm_transients']        = array();
		$GLOBALS['nmm_filters']           = array();
		$GLOBALS['wpdb']->site_records    = array();
		\WP_Application_Passwords::$records  = array();
		NMM_Test_MainWP_DB::$sites        = array(
			91 => (object) array(
				'id'         => 91,
				'name'       => 'New Client',
				'url'        => 'https://new-client.test',
				'adminname'  => 'admin',
				'plugins'    => wp_json_encode( array() ),
				'suspended'  => 0,
				'sync_errors' => '',
			),
		);
		add_filter(
			'mainwp_encrypt_key_value',
			static function ( $value, string $plaintext ): array {
				return array( 'encrypted_val' => base64_encode( $plaintext ), 'file_key' => 'onboarding-test-key' );
			},
			10,
			4
		);
	}

	public function test_registers_native_mainwp_install_and_safe_setup_choices(): void {
		$options = Onboarding::sync_options( array() );
		self::assertSame( 'novamira/novamira.php', $options['mainwp-novamira-addon']['plugin_slug'] );
		self::assertSame( 'Novamira Free', $options['mainwp-novamira-addon']['plugin_name'] );
		self::assertTrue( $options['mainwp-novamira-addon']['no_setting'] );
		self::assertStringContainsString( 'managed credential', $options['mainwp-novamira-addon']['action_after_install'] );
	}

	public function test_scoped_mainwp_package_request_uses_only_the_validated_release(): void {
		$GLOBALS['nmm_transients']['novamira_mainwp_free_release'] = array(
			'version'      => '1.12.0',
			'download_url' => 'https://packages.example/novamira-1.12.0.zip',
			'sha256'       => str_repeat( 'a', 64 ),
		);
		$_POST = array(
			'action' => 'mainwp_ext_prepareinstallplugintheme',
			'type'   => 'plugin',
		);

		$info = Onboarding::plugin_information( false, 'plugin_information', (object) array( 'slug' => 'novamira' ) );
		self::assertIsObject( $info );
		self::assertSame( '1.12.0', $info->version );
		self::assertSame( 'https://packages.example/novamira-1.12.0.zip', $info->download_link );
		self::assertSame(
			$info->download_link,
			Onboarding::prepare_download_url( 'https://downloads.wordpress.org/untrusted.zip', array( 'type' => 'plugin', 'slug' => 'novamira' ) )
		);
	}

	public function test_safe_setup_keeps_ai_jit_production_denied_and_creates_one_credential(): void {
		add_filter(
			'mainwp_fetchurlauthed',
			static function ( $file, $key, $site_id, $what, $params ) {
				if ( 'code_snippet' !== $what ) {
					return array();
				}
				$action = nmm_child_action( $params );
				if ( 'status' === ( $action['action'] ?? '' ) ) {
					return nmm_child_response(
						$params,
						array(
							'free'                  => array( 'active' => true, 'version' => '1.12.0' ),
							'pro'                   => array( 'active' => false, 'license_active' => false ),
							'ai'                    => array( 'manual_enabled' => false ),
							'application_passwords' => array( 'supported' => true, 'available_for_user' => true ),
						)
					);
				}
				if ( 'credential-create' === ( $action['action'] ?? '' ) ) {
					return nmm_child_response( $params, array( 'username' => 'admin', 'password' => 'one-time-password', 'uuid' => 'managed-uuid', 'created' => 123 ) );
				}
				return nmm_child_response( $params, array( 'ability_rules' => array() ) );
			},
			10,
			5
		);

		$result = Onboarding::setup_site( 91 );
		self::assertIsArray( $result );
		self::assertTrue( $result['credential_created'] );
		self::assertTrue( $result['policy']['gateway_enabled'] );
		self::assertFalse( $result['policy']['production_allowed'] );
		self::assertSame( 'just-in-time', $result['policy']['ai_lifecycle'] );
		self::assertFalse( $result['policy']['fanout_read_allowed'] );
		self::assertSame( 'managed-uuid', Storage::get_site( 91 )['credential_uuid'] );
	}

	public function test_plugin_boot_contains_the_mainwp_615_onboarding_contract(): void {
		$plugin = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-plugin.php' );
		self::assertIsString( $plugin );
		self::assertStringContainsString( 'mainwp_sync_extensions_options', $plugin );
		self::assertStringContainsString( 'mainwp_prepare_install_download_url', $plugin );
		self::assertStringContainsString( 'mainwp_applypluginsettings_mainwp-novamira-addon', $plugin );
	}
}
