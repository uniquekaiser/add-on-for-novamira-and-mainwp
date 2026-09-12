<?php
/**
 * Expiring, operation-bound, single-use confirmation tokens.
 *
 * Tokens enforce the preview/execution contract. The adapter must still obtain
 * human approval: an authenticated caller able to run both steps can automate it.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class Confirmation {

	private const PREFIX   = 'novamira_mainwp_confirm_';
	private const LIFETIME = 300;

	/**
	 * @param array<int, int>      $site_ids Exact MainWP site set.
	 * @param array<string, mixed> $arguments Arguments, without confirmation controls.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function issue( string $ability, array $site_ids, array $arguments ) {
		$binding = self::binding( $ability, $site_ids, $arguments );
		if ( is_wp_error( $binding ) ) {
			return $binding;
		}
		try {
			$expires = time() + self::LIFETIME;
			$token   = bin2hex( random_bytes( 32 ) ) . '.' . $expires;
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'novamira_mainwp_confirmation_unavailable', 'Secure confirmation tokens are unavailable.' );
		}
		$value = wp_json_encode(
			array(
				'expires_at' => $expires,
				'binding'    => $binding,
			)
		);
		global $wpdb;
		// Bypass option caches; expiry in the indexed name lets new previews remove expired records.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name >= %s AND option_name < %s", self::PREFIX, self::PREFIX . sprintf( '%010d', time() + 1 ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$saved = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::key( $token, $expires ), $value ) );
		if ( 1 !== $saved ) {
			return new \WP_Error( 'novamira_mainwp_confirmation_unavailable', 'The confirmation could not be stored. No action was authorized.' );
		}
		return array(
			'confirmation_token' => $token,
			'expires_at'         => $expires,
			'expires_in'         => self::LIFETIME,
		);
	}

	/**
	 * Consume before executing. Only one concurrent request can delete the record.
	 *
	 * @param array<int, int>      $site_ids Exact MainWP site set.
	 * @param array<string, mixed> $arguments Arguments, without confirmation controls.
	 * @return true|\WP_Error
	 */
	public static function consume( string $token, string $ability, array $site_ids, array $arguments ) {
		$binding = self::binding( $ability, $site_ids, $arguments );
		if ( is_wp_error( $binding ) ) {
			return $binding;
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}\.([0-9]{10})$/D', $token, $matches ) ) {
			return self::invalid();
		}
		$expires = (int) $matches[1];
		if ( $expires <= time() ) {
			return self::invalid();
		}
		global $wpdb;
		$key = self::key( $token, $expires );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
		$record = is_string( $value ) ? json_decode( $value, true ) : null;
		if ( ! is_array( $record ) || ( $record['expires_at'] ?? null ) !== $expires || ! is_string( $record['binding'] ?? null ) || ! hash_equals( $record['binding'], $binding ) ) {
			return self::invalid();
		}
		// This conditional DELETE is the authorization decision, not the preceding read.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$consumed = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $value ) );
		// A blocked database delete may complete after the approval window closed.
		return 1 === $consumed && $expires > time() ? true : self::invalid();
	}

	private static function key( string $token, int $expires ): string {
		return self::PREFIX . sprintf( '%010d', $expires ) . '_' . hash( 'sha256', $token );
	}

	/**
	 * @param array<int, int>      $site_ids Exact MainWP site set.
	 * @param array<string, mixed> $arguments Approved arguments.
	 * @return string|\WP_Error
	 */
	private static function binding( string $ability, array $site_ids, array $arguments ) {
		if ( get_current_user_id() < 1 || ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'novamira_mainwp_permission_denied', 'Administrator access is required.' );
		}
		if ( '' === $ability || empty( $site_ids ) ) {
			return new \WP_Error( 'novamira_mainwp_confirmation_invalid_input', 'An ability and an exact non-empty MainWP site set are required.' );
		}
		foreach ( $site_ids as $site_id ) {
			if ( ! is_int( $site_id ) || $site_id < 1 ) {
				return new \WP_Error( 'novamira_mainwp_confirmation_invalid_input', 'MainWP site IDs must be positive integers.' );
			}
		}
		$site_ids = array_values( array_unique( $site_ids ) );
		sort( $site_ids, SORT_NUMERIC );
		try {
			$canonical = self::canonicalize(
				array(
					'user_id'   => get_current_user_id(),
					'ability'   => $ability,
					'site_ids'  => $site_ids,
					'arguments' => $arguments,
				)
			);
			// Invalid UTF-8 must fail, not be repaired into another request's binding.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			$json = json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'novamira_mainwp_confirmation_invalid_input', 'Confirmation arguments must contain valid JSON values.' );
		}
		if ( ! is_string( $json ) ) {
			return new \WP_Error( 'novamira_mainwp_confirmation_invalid_input', 'Confirmation arguments could not be encoded.' );
		}
		// HMAC prevents offline guessing of low-entropy secrets inside approved arguments.
		return hash_hmac( 'sha256', $json, wp_salt( 'auth' ) );
	}

	/** @return mixed */
	private static function canonicalize( $value, int $depth = 0 ) {
		if ( $depth > 64 ) {
			throw new \InvalidArgumentException( 'Confirmation argument nesting is too deep.' );
		}
		if ( $value instanceof \stdClass ) {
			$properties = get_object_vars( $value );
			ksort( $properties, SORT_STRING );
			$object = new \stdClass();
			foreach ( $properties as $key => $item ) {
				$object->$key = self::canonicalize( $item, $depth + 1 );
			}
			return $object;
		}
		if ( is_array( $value ) ) {
			if ( array() !== $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				ksort( $value, SORT_STRING );
			}
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::canonicalize( $item, $depth + 1 );
			}
			return $value;
		}
		if ( null === $value || is_string( $value ) || is_int( $value ) || is_bool( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) {
			return $value;
		}
		throw new \InvalidArgumentException( 'Confirmation arguments must contain only JSON values.' );
	}

	private static function invalid(): \WP_Error {
		return new \WP_Error( 'novamira_mainwp_confirmation_invalid', 'The confirmation is expired, used, or does not match this user and operation. Request a new dry run.' );
	}
}
