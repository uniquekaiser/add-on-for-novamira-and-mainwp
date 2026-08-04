<?php

declare( strict_types=1 );

use Novamira\MainWP\Crypto;
use Novamira\MainWP\Fleet_Service;
use Novamira\MainWP\Storage;
use PHPUnit\Framework\TestCase;

final class DashboardCredentialRotationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['nmm_filters']             = array();
		$GLOBALS['nmm_actions']             = array();
		$GLOBALS['nmm_dashboard_calls']     = array();
		$GLOBALS['wpdb']->site_records     = array();
		NMM_Test_MainWP_DB::$sites[5] = (object) array(
			'id'        => 5,
			'name'      => 'Credential site',
			'url'       => 'https://credential.test',
			'adminname' => 'admin',
			'suspended' => 0,
		);

		add_filter( 'mainwp_encrypt_key_value', static function ( $unused, string $plaintext ): array { return array( 'encrypted_val' => strrev( $plaintext ), 'file_key' => 'key-' . $plaintext . '.php' ); }, 10, 2 );
		add_filter(
			'mainwp_fetchurlauthed',
			static function ( $plugin_file, $key, int $site_id, string $what, array $params ): array {
				$action = (string) ( $params['novamira_mainwp_action'] ?? '' );
				$GLOBALS['nmm_dashboard_calls'][] = array( 'action' => $action, 'params' => $params['novamira_mainwp_params'] ?? array() );
				$data = 'credential-create' === $action
					? array( 'username' => 'admin', 'password' => 'new-password', 'uuid' => 'new-uuid', 'created' => time() )
					: array( 'revoked' => true );
				return array( 'novamira_mainwp' => array( 'ok' => true, 'data' => $data ) );
			},
			10,
			6
		);

		Storage::update_site(
			5,
			array(
				'credential_username' => 'admin',
				'credential_secret'   => Crypto::encrypt( 'old-password' ),
				'credential_uuid'     => 'old-uuid',
			)
		);
		$GLOBALS['nmm_dashboard_calls'] = array();
	}

	public function test_old_password_is_revoked_only_after_replacement_is_stored(): void {
		$result = Fleet_Service::rotate_credential( 5, false );
		$stored = Storage::get_site( 5 );

		self::assertSame( 'new-uuid', $result['uuid'] );
		self::assertSame( 'new-uuid', $stored['credential_uuid'] );
		self::assertSame( array( 'credential-create', 'credential-revoke' ), array_column( $GLOBALS['nmm_dashboard_calls'], 'action' ) );
		self::assertSame( 'old-uuid', $GLOBALS['nmm_dashboard_calls'][1]['params']['uuid'] );
		self::assertNotContains( 'old_uuid', array_keys( $GLOBALS['nmm_dashboard_calls'][0]['params'] ) );
	}
}
