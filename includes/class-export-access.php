<?php
/**
 * Explicit, short-lived authorization for plaintext credential downloads.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class Export_Access {
	public static function enabled(): bool {
		return true === apply_filters( 'novamira_mainwp_credential_export_enabled', false );
	}

	/** @param array<int,mixed> $site_ids @return array<int,int>|\WP_Error */
	public static function authorize( array $site_ids, string $format ) {
		if ( ! current_user_can( 'manage_options' ) || ! self::enabled() ) {
			return new \WP_Error( 'novamira_mainwp_export_disabled', 'Plaintext credential export is disabled by default.' );
		}
		if ( ! isset( Config_Export::formats()[ $format ] ) || empty( $site_ids ) || count( $site_ids ) > 100 ) {
			return new \WP_Error( 'novamira_mainwp_export_selection', 'Select between 1 and 100 sites and a supported format.' );
		}
		$selected = array();
		foreach ( $site_ids as $site_id ) {
			if ( ! is_scalar( $site_id ) || ! ctype_digit( (string) $site_id ) || (int) $site_id < 1 ) {
				return new \WP_Error( 'novamira_mainwp_export_selection', 'Every selected site must have a positive numeric MainWP ID.' );
			}
			$site = MainWP_Client::site( (int) $site_id );
			if ( is_wp_error( $site ) ) {
				return $site;
			}
			$selected[] = (int) $site_id;
		}
		$selected = array_values( array_unique( $selected ) );
		sort( $selected, SORT_NUMERIC );
		// Integrators must verify recent authentication for this user and browser session.
		if ( '' === wp_get_session_token() || true !== apply_filters( 'novamira_mainwp_credential_export_step_up', false, get_current_user_id(), $selected ) ) {
			return new \WP_Error( 'novamira_mainwp_export_step_up', 'Recent authentication is required before exporting administrator-grade credentials.' );
		}
		return $selected;
	}

	/** @param array<int,mixed> $site_ids @return array<string,mixed>|\WP_Error */
	public static function preview( array $site_ids, string $format ) {
		$selected = self::authorize( $site_ids, $format );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		return Confirmation::issue( 'export-direct-configs', $selected, self::arguments( $format ) );
	}

	/** @param array<int,mixed> $site_ids @return array<int,int>|\WP_Error */
	public static function consume( array $site_ids, string $format, string $token, bool $user_confirmed ) {
		$selected = self::authorize( $site_ids, $format );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		if ( ! $user_confirmed ) {
			return new \WP_Error( 'novamira_mainwp_export_confirmation', 'Confirm the exact selected credential download.' );
		}
		$result = Confirmation::consume( $token, 'export-direct-configs', $selected, self::arguments( $format ) );
		return is_wp_error( $result ) ? $result : $selected;
	}

	/** @return array<string,string> */
	private static function arguments( string $format ): array {
		return array(
			'format'  => $format,
			'session' => wp_get_session_token(),
		);
	}
}
