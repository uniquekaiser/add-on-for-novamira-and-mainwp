<?php
/**
 * Add-on-owned fleet state.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class Storage {
	public const PACKAGE_OPTION         = 'novamira_mainwp_packages';
	public const DEFAULT_LICENSE_OPTION = 'novamira_mainwp_default_pro_license';

	public static function site_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'novamira_mainwp_sites';
	}

	public static function audit_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'novamira_mainwp_audit';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$sites   = self::site_table();
		$audit   = self::audit_table();

		dbDelta(
			"CREATE TABLE {$sites} (
			site_id bigint(20) unsigned NOT NULL,
			credential_username varchar(191) NOT NULL DEFAULT '',
			credential_secret longtext NULL,
			credential_uuid varchar(191) NOT NULL DEFAULT '',
			policy longtext NULL,
			status_cache longtext NULL,
			pro_license_secret longtext NULL,
			last_success datetime NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (site_id)
		) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$audit} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_time datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			site_id bigint(20) unsigned NOT NULL DEFAULT 0,
			operation varchar(191) NOT NULL,
			outcome varchar(32) NOT NULL,
			duration_ms bigint(20) unsigned NOT NULL DEFAULT 0,
			correlation_id varchar(64) NOT NULL,
			argument_keys text NULL,
			PRIMARY KEY  (id),
			KEY site_time (site_id,event_time),
			KEY correlation (correlation_id)
		) {$charset};"
		);
		update_option( 'novamira_mainwp_db_version', NOVAMIRA_MAINWP_VERSION, false );
	}

	/** @return array<string, mixed> */
	public static function get_site( int $site_id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE site_id = %d', self::site_table(), $site_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Add-on-owned keyed state table.
		if ( ! is_array( $row ) ) {
			return self::defaults( $site_id );
		}
		$row['site_id']      = (int) $row['site_id'];
		$row['policy']       = self::decode_array( $row['policy'] ?? '' );
		$row['status_cache'] = self::decode_array( $row['status_cache'] ?? '' );
		return array_merge( self::defaults( $site_id ), $row );
	}

	/** @param array<string, mixed> $fields */
	public static function update_site( int $site_id, array $fields ): bool {
		global $wpdb;
		$current = self::get_site( $site_id );
		$allowed = array(
			'credential_username',
			'credential_secret',
			'credential_uuid',
			'policy',
			'status_cache',
			'pro_license_secret',
			'last_success',
		);
		$data    = array(
			'site_id'    => $site_id,
			'updated_at' => current_time( 'mysql', true ),
		);
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$value        = $fields[ $key ];
				$data[ $key ] = in_array( $key, array( 'policy', 'status_cache' ), true ) ? wp_json_encode( is_array( $value ) ? $value : array() ) : $value;
			} elseif ( array_key_exists( $key, $current ) ) {
				$value        = $current[ $key ];
				$data[ $key ] = in_array( $key, array( 'policy', 'status_cache' ), true ) ? wp_json_encode( is_array( $value ) ? $value : array() ) : $value;
			}
		}

		return false !== $wpdb->replace( self::site_table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Add-on-owned keyed state table.
	}

	/** @return array<string, mixed> */
	public static function policy( int $site_id ): array {
		$site          = self::get_site( $site_id );
		$policy        = is_array( $site['policy'] ) ? $site['policy'] : array();
		$lifecycle     = isset( $policy['ai_lifecycle'] ) && in_array( $policy['ai_lifecycle'], array( 'just-in-time', 'manual-only', 'disabled' ), true ) ? (string) $policy['ai_lifecycle'] : 'just-in-time';
		$ability_names = static function ( $values ): array {
			if ( ! is_array( $values ) ) {
				return array();
			}
			$clean = array();
			foreach ( $values as $value ) {
				if ( ! is_scalar( $value ) ) {
					continue;
				}
				$name = (string) $value;
				if ( 1 === preg_match( '/^[a-z0-9-]+\/[a-z0-9-\/]+$/', $name ) ) {
					$clean[] = $name;
				}
			}
			return array_values( array_unique( $clean ) );
		};
		return array(
			'gateway_enabled'     => ! array_key_exists( 'gateway_enabled', $policy ) || true === $policy['gateway_enabled'],
			'production_allowed'  => true === ( $policy['production_allowed'] ?? false ),
			'ai_lifecycle'        => $lifecycle,
			'fanout_read_allowed' => true === ( $policy['fanout_read_allowed'] ?? false ),
			'allowed_abilities'   => $ability_names( $policy['allowed_abilities'] ?? array() ),
			'disabled_abilities'  => $ability_names( $policy['disabled_abilities'] ?? array() ),
		);
	}

	/** @return array<string, mixed> */
	public static function packages(): array {
		$value = get_option( self::PACKAGE_OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/** @param array<string, mixed> $packages */
	public static function save_packages( array $packages ): void {
		update_option( self::PACKAGE_OPTION, $packages, false );
	}

	/** @return string|\WP_Error */
	public static function default_pro_license() {
		$payload = get_option( self::DEFAULT_LICENSE_OPTION, '' );
		return is_string( $payload ) ? Crypto::decrypt( $payload ) : '';
	}

	/** @return true|\WP_Error */
	public static function set_default_pro_license( string $license ) {
		$old = get_option( self::DEFAULT_LICENSE_OPTION, '' );
		if ( is_string( $old ) && '' !== $old ) {
			Crypto::delete_key( $old );
		}
		if ( '' === trim( $license ) ) {
			delete_option( self::DEFAULT_LICENSE_OPTION );
			return true;
		}
		$encrypted = Crypto::encrypt( trim( $license ) );
		if ( is_wp_error( $encrypted ) ) {
			return $encrypted;
		}
		update_option( self::DEFAULT_LICENSE_OPTION, $encrypted, false );
		return true;
	}

	/** @return array<string, mixed> */
	private static function defaults( int $site_id ): array {
		return array(
			'site_id'             => $site_id,
			'credential_username' => '',
			'credential_secret'   => '',
			'credential_uuid'     => '',
			'policy'              => array(),
			'status_cache'        => array(),
			'pro_license_secret'  => '',
			'last_success'        => null,
			'updated_at'          => '',
		);
	}

	/** @return array<string, mixed> */
	private static function decode_array( $value ): array {
		$decoded = is_string( $value ) ? json_decode( $value, true ) : null;
		return is_array( $decoded ) ? $decoded : array();
	}
}
