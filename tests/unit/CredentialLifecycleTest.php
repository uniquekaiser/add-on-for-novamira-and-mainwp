<?php

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Novamira\MainWP\Child_Companion;

final class CredentialLifecycleTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['nmm_options'] = array( 'mainwp_child_connected_admin' => 'admin' );
		WP_Application_Passwords::$records = array( 1 => array( 'old-uuid' => array( 'uuid' => 'old-uuid', 'name' => 'old' ) ) );
	}

	public function test_rotation_is_limited_to_connected_admin_and_returns_plaintext_once(): void {
		$denied = Child_Companion::dispatch( 'credential-create', array( 'username' => 'another-admin' ) );
		self::assertSame( 'novamira_mainwp_connected_user_mismatch', $denied->get_error_code() );

		$created = Child_Companion::dispatch( 'credential-create', array( 'username' => 'admin', 'label' => 'Novamira MainWP — Dashboard' ) );
		self::assertFalse( is_wp_error( $created ) );
		self::assertStringStartsWith( 'plain-', $created['password'] );
		self::assertArrayHasKey( 'old-uuid', WP_Application_Passwords::$records[1], 'The old credential stays valid until the Dashboard stores the replacement.' );
		self::assertArrayHasKey( $created['uuid'], WP_Application_Passwords::$records[1] );

		$old_revoked = Child_Companion::dispatch( 'credential-revoke', array( 'username' => 'admin', 'uuid' => 'old-uuid' ) );
		self::assertTrue( $old_revoked['revoked'] );

		$revoked = Child_Companion::dispatch( 'credential-revoke', array( 'username' => 'admin', 'uuid' => $created['uuid'] ) );
		self::assertTrue( $revoked['revoked'] );
		self::assertArrayNotHasKey( $created['uuid'], WP_Application_Passwords::$records[1] );
	}
}
