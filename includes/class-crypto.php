<?php
/**
 * MainWP-backed secret encryption.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class Crypto {
	/** @return string|\WP_Error */
	public static function encrypt( string $plaintext ) {
		if ( '' === $plaintext ) {
			return '';
		}
		if ( ! has_filter( 'mainwp_encrypt_key_value' ) ) {
			return new \WP_Error( 'novamira_mainwp_encryption_unavailable', 'MainWP secret encryption is unavailable.' );
		}

		$encrypted = apply_filters( 'mainwp_encrypt_key_value', false, $plaintext, 'novamira_mainwp_', false );
		if ( ! is_array( $encrypted ) || empty( $encrypted['encrypted_val'] ) || empty( $encrypted['file_key'] ) ) {
			return new \WP_Error( 'novamira_mainwp_encryption_failed', 'MainWP could not encrypt the secret.' );
		}

		return (string) wp_json_encode( $encrypted );
	}

	/** @return string|\WP_Error */
	public static function decrypt( string $payload ) {
		if ( '' === $payload ) {
			return '';
		}
		if ( ! has_filter( 'mainwp_decrypt_key_value' ) ) {
			return new \WP_Error( 'novamira_mainwp_decryption_unavailable', 'MainWP secret decryption is unavailable.' );
		}

		$decoded = json_decode( $payload, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'novamira_mainwp_invalid_secret', 'The encrypted secret payload is invalid.' );
		}
		$plaintext = apply_filters( 'mainwp_decrypt_key_value', false, $decoded, false );
		if ( ! is_string( $plaintext ) ) {
			return new \WP_Error( 'novamira_mainwp_decryption_failed', 'MainWP could not decrypt the secret.' );
		}
		return $plaintext;
	}

	public static function delete_key( string $payload ): void {
		$decoded = json_decode( $payload, true );
		if ( is_array( $decoded ) && isset( $decoded['file_key'] ) && is_string( $decoded['file_key'] ) ) {
			do_action( 'mainwp_delete_key_file', $decoded['file_key'] );
		}
	}
}
