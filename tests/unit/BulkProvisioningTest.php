<?php

declare( strict_types=1 );

use Novamira\MainWP\Fleet_Service;
use Novamira\MainWP\Storage;
use PHPUnit\Framework\TestCase;

final class BulkProvisioningTest extends TestCase {
	/** @var string */
	private $package;

	protected function setUp(): void {
		$GLOBALS['nmm_filters']        = array();
		$GLOBALS['nmm_options']        = array();
		$GLOBALS['nmm_bulk_requests']  = array();
		$GLOBALS['nmm_child_payloads'] = array();
		$GLOBALS['wpdb']->site_records = array();
		NMM_Test_MainWP_DB::$sites     = array(
			71 => (object) array( 'id' => 71, 'name' => 'Licensed child', 'url' => 'https://licensed.test', 'adminname' => 'admin', 'suspended' => 0 ),
		);
		$this->package                 = dirname( __DIR__ ) . '/tmp-pro-package.zip';
		file_put_contents( $this->package, 'package' );
		Storage::save_packages(
			array(
				'pro' => array(
					'path'    => $this->package,
					'version' => '1.8.2',
					'source'  => 'upload',
				),
			)
		);
		Storage::set_pro_package_source( 'upload' );
		add_filter(
			'mainwp_encrypt_key_value',
			static function ( $value, string $plaintext ): array {
				return array( 'encrypted_val' => base64_encode( $plaintext ), 'file_key' => 'unit-test-key' );
			},
			10,
			4
		);
		add_filter(
			'mainwp_decrypt_key_value',
			static function ( $value, array $payload ): string {
				return (string) base64_decode( (string) $payload['encrypted_val'], true );
			},
			10,
			3
		);
		Storage::set_default_pro_license( 'license-123' );
		add_filter(
			'mainwp_fetchurlauthed',
			static function ( $plugin_file, $key, int $site_id, string $what, array $params ): array {
				$GLOBALS['nmm_bulk_requests'][] = array( 'site_id' => $site_id, 'what' => $what, 'params' => $params );
				if ( 'code_snippet' === $what ) {
					$payload                           = nmm_child_action( $params );
					$GLOBALS['nmm_child_payloads'][] = $payload;
					return nmm_child_response( $params, array( 'ok' => true ) );
				}
				return array( 'result' => true );
			},
			10,
			6
		);
	}

	protected function tearDown(): void {
		if ( is_file( $this->package ) ) {
			unlink( $this->package );
		}
	}

	public function test_install_and_activate_pro_also_activates_the_license(): void {
		$result = Fleet_Service::provision( array( 71 ), 'install-activate-pro', false );
		self::assertTrue( $result['results'][0]['ok'] );
		self::assertSame( 'installplugintheme', $GLOBALS['nmm_bulk_requests'][0]['what'] );
		self::assertSame( 'yes', $GLOBALS['nmm_bulk_requests'][0]['params']['activatePlugin'] );
		$license = array_values( array_filter( $GLOBALS['nmm_child_payloads'], static function ( array $payload ): bool { return 'pro-license' === ( $payload['action'] ?? '' ); } ) );
		self::assertCount( 1, $license );
		self::assertSame( 'activate', $license[0]['params']['operation'] );
		self::assertSame( 'license-123', $license[0]['params']['license_key'] );
	}

	public function test_install_only_leaves_the_pro_plugin_inactive(): void {
		$result = Fleet_Service::provision( array( 71 ), 'install-pro', false );
		self::assertTrue( $result['results'][0]['ok'] );
		self::assertSame( 'no', $GLOBALS['nmm_bulk_requests'][0]['params']['activatePlugin'] );
		$license = array_filter( $GLOBALS['nmm_child_payloads'], static function ( array $payload ): bool { return 'pro-license' === ( $payload['action'] ?? '' ); } );
		self::assertCount( 0, $license );
	}
}
