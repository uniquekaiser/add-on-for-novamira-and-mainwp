<?php

declare( strict_types=1 );

use Novamira\MainWP\Storage;
use PHPUnit\Framework\TestCase;

final class PackageStorageTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb']        = new NMM_Test_WPDB();
		$GLOBALS['nmm_actions'] = array();
		$GLOBALS['nmm_filters'] = array();
		$GLOBALS['nmm_options'] = array();
		add_filter(
			'mainwp_encrypt_key_value',
			static function ( $value, string $plaintext ): array {
				return array( 'encrypted_val' => base64_encode( $plaintext ), 'file_key' => 'package-test-' . hash( 'sha256', $plaintext ) );
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
	}

	public function test_pro_package_source_switch_is_idempotent(): void {
		self::assertTrue( Storage::set_pro_package_source( 'dashboard' ) );
		self::assertSame( 'dashboard', Storage::pro_package_source() );
		self::assertTrue( Storage::set_pro_package_source( 'dashboard' ) );
		self::assertTrue( Storage::set_pro_package_source( 'upload' ) );
		self::assertSame( 'upload', Storage::pro_package_source() );
		self::assertFalse( Storage::set_pro_package_source( 'unexpected' ) );
	}

	public function test_default_license_indicator_never_requires_plaintext_output(): void {
		self::assertFalse( Storage::default_pro_license_is_stored() );
		self::assertTrue( Storage::set_default_pro_license( 'license-secret' ) );
		self::assertTrue( Storage::default_pro_license_is_stored() );
		self::assertSame( 'license-secret', Storage::default_pro_license() );
	}

	public function test_failed_encryption_preserves_previous_license_and_key(): void {
		self::assertTrue( Storage::set_default_pro_license( 'previous-license' ) );
		add_filter( 'mainwp_encrypt_key_value', static function () { return false; }, 20, 4 );
		self::assertTrue( is_wp_error( Storage::set_default_pro_license( 'replacement-license' ) ) );
		self::assertSame( 'previous-license', Storage::default_pro_license() );
		self::assertSame( array(), $GLOBALS['nmm_actions'] );
	}

	public function test_failed_write_preserves_previous_key_and_discards_unused_replacement(): void {
		self::assertTrue( Storage::set_default_pro_license( 'previous-license' ) );
		$GLOBALS['wpdb']->fail_option = Storage::DEFAULT_LICENSE_OPTION;
		self::assertSame( 'novamira_mainwp_license_persistence_failed', Storage::set_default_pro_license( 'replacement-license' )->get_error_code() );
		self::assertSame( 'previous-license', Storage::default_pro_license() );
		self::assertSame( array( array( 'mainwp_delete_key_file', array( 'package-test-' . hash( 'sha256', 'replacement-license' ) ) ) ), $GLOBALS['nmm_actions'] );
	}

	public function test_successful_replacement_deletes_previous_key_only_after_verified_write(): void {
		self::assertTrue( Storage::set_default_pro_license( 'previous-license' ) );
		self::assertTrue( Storage::set_default_pro_license( 'replacement-license' ) );
		self::assertSame( 'replacement-license', Storage::default_pro_license() );
		self::assertSame( array( array( 'mainwp_delete_key_file', array( 'package-test-' . hash( 'sha256', 'previous-license' ) ) ) ), $GLOBALS['nmm_actions'] );
		self::assertSame( array(), $GLOBALS['wpdb']->named_locks );
	}

	public function test_failed_removal_preserves_previous_license_and_key(): void {
		self::assertTrue( Storage::set_default_pro_license( 'previous-license' ) );
		$GLOBALS['wpdb']->fail_option = Storage::DEFAULT_LICENSE_OPTION;
		self::assertTrue( is_wp_error( Storage::set_default_pro_license( '' ) ) );
		self::assertSame( 'previous-license', Storage::default_pro_license() );
		self::assertSame( array(), $GLOBALS['nmm_actions'] );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = new NMM_Test_WPDB();
	}
}
