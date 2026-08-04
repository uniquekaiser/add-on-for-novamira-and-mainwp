<?php

declare( strict_types=1 );

use Novamira\MainWP\Audit;
use Novamira\MainWP\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoAuditTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['nmm_filters'] = array();
		$GLOBALS['nmm_actions'] = array();
		$GLOBALS['wpdb']->last_insert = array();
	}

	public function test_secret_round_trip_uses_mainwp_envelope_and_key_cleanup(): void {
		add_filter( 'mainwp_encrypt_key_value', static function ( $unused, string $plaintext ): array { return array( 'encrypted_val' => strrev( $plaintext ), 'file_key' => 'key.php' ); }, 10, 2 );
		add_filter( 'mainwp_decrypt_key_value', static function ( $unused, array $envelope ): string { return strrev( $envelope['encrypted_val'] ); }, 10, 2 );
		$payload = Crypto::encrypt( 'top-secret' );
		self::assertIsString( $payload );
		self::assertStringNotContainsString( 'top-secret', $payload );
		self::assertSame( 'top-secret', Crypto::decrypt( $payload ) );
		Crypto::delete_key( $payload );
		self::assertSame( 'mainwp_delete_key_file', $GLOBALS['nmm_actions'][0][0] );
		self::assertSame( 'key.php', $GLOBALS['nmm_actions'][0][1][0] );
	}

	public function test_audit_stores_only_argument_keys(): void {
		Audit::record( 21, 'mcp-write', 'success', 8, array( 'password' => 'never-store-me', 'arguments' => array( 'secret' => 'payload' ) ) );
		$data = $GLOBALS['wpdb']->last_insert['data'];
		self::assertSame( array( 'password', 'arguments' ), json_decode( $data['argument_keys'], true ) );
		self::assertStringNotContainsString( 'never-store-me', wp_json_encode( $data ) );
		self::assertStringNotContainsString( 'payload', wp_json_encode( $data ) );
	}
}
