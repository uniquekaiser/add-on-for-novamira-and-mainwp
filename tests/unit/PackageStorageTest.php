<?php

declare( strict_types=1 );

use Novamira\MainWP\Storage;
use PHPUnit\Framework\TestCase;

final class PackageStorageTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['nmm_options'] = array();
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
}
