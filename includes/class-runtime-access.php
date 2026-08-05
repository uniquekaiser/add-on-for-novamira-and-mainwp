<?php
/**
 * Dashboard-owned, request-scoped Novamira AI access windows.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class Runtime_Access {
	private const STATE_OPTION = 'novamira_mainwp_runtime_state';
	private const TTL          = 300;

	/**
	 * Open or join a short access window on one child site.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function acquire( int $site_id ) {
		$policy = Storage::policy( $site_id );
		if ( ! $policy['gateway_enabled'] ) {
			return new \WP_Error( 'novamira_mainwp_gateway_disabled', 'The MainWP gateway is disabled for this site.' );
		}

		self::recover_expired( $site_id );
		$state = self::state();
		$key   = (string) $site_id;
		$entry = isset( $state[ $key ] ) && is_array( $state[ $key ] ) ? $state[ $key ] : array();

		if ( empty( $entry['restore'] ) ) {
			$opened = MainWP_Client::child_operation(
				$site_id,
				'ai-open',
				array(
					'lifecycle'          => $policy['ai_lifecycle'],
					'production_allowed' => $policy['production_allowed'],
				)
			);
			if ( is_wp_error( $opened ) ) {
				return $opened;
			}
			$entry = array(
				'restore' => isset( $opened['restore'] ) && is_array( $opened['restore'] ) ? $opened['restore'] : array(),
				'changed' => ! empty( $opened['changed'] ),
				'leases'  => array(),
			);
		}

		try {
			$token = bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $error ) {
			$token = wp_generate_password( 64, false, false );
		}
		$expires                   = time() + self::TTL;
		$entry['leases'][ $token ] = $expires;
		$entry['expires']          = max( array_map( 'intval', $entry['leases'] ) );
		$state[ $key ]             = $entry;
		self::save( $state );
		wp_schedule_single_event( $expires, 'novamira_mainwp_runtime_cleanup', array( $site_id, $token ) );

		return array(
			'token'   => $token,
			'expires' => $expires,
			'changed' => ! empty( $entry['changed'] ),
		);
	}

	/**
	 * Release one access-window participant and restore after the final release.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function release( int $site_id, string $token, bool $expired = false ) {
		$state = self::state();
		$key   = (string) $site_id;
		if ( ! isset( $state[ $key ] ) || ! is_array( $state[ $key ] ) ) {
			return array(
				'released' => false,
				'restored' => false,
			);
		}
		$entry  = $state[ $key ];
		$leases = isset( $entry['leases'] ) && is_array( $entry['leases'] ) ? $entry['leases'] : array();
		if ( '' !== $token && isset( $leases[ $token ] ) ) {
			unset( $leases[ $token ] );
			if ( ! $expired ) {
				$timestamp = wp_next_scheduled( 'novamira_mainwp_runtime_cleanup', array( $site_id, $token ) );
				if ( false !== $timestamp ) {
					wp_unschedule_event( $timestamp, 'novamira_mainwp_runtime_cleanup', array( $site_id, $token ) );
				}
			}
		}
		$now = time();
		foreach ( $leases as $lease_token => $lease_expiry ) {
			if ( (int) $lease_expiry <= $now ) {
				unset( $leases[ $lease_token ] );
			}
		}
		if ( ! empty( $leases ) ) {
			$entry['leases']  = $leases;
			$entry['expires'] = max( array_map( 'intval', $leases ) );
			$state[ $key ]    = $entry;
			self::save( $state );
			return array(
				'released' => true,
				'restored' => false,
			);
		}

		$restored = MainWP_Client::child_operation(
			$site_id,
			'ai-restore',
			array( 'restore' => isset( $entry['restore'] ) && is_array( $entry['restore'] ) ? $entry['restore'] : array() )
		);
		if ( is_wp_error( $restored ) ) {
			$entry['leases']  = array();
			$entry['expires'] = $now + 60;
			$state[ $key ]    = $entry;
			self::save( $state );
			wp_schedule_single_event( $now + 60, 'novamira_mainwp_runtime_cleanup', array( $site_id, '' ) );
			Audit::record( $site_id, 'ai-restore', 'error', 0 );
			return $restored;
		}

		unset( $state[ $key ] );
		self::save( $state );
		Audit::record( $site_id, 'ai-restore', 'success', 0 );
		return array(
			'released' => true,
			'restored' => true,
		);
	}

	/**
	 * WP-Cron callback for crash-safe access-window cleanup.
	 */
	public static function cleanup( int $site_id, string $token = '' ): void {
		self::release( $site_id, $token, true );
	}

	/**
	 * Recover expired windows on ordinary Dashboard requests as a WP-Cron fallback.
	 */
	public static function recover_expired( int $only_site_id = 0 ): void {
		$state = self::state();
		$now   = time();
		foreach ( $state as $site_id => $entry ) {
			$id = (int) $site_id;
			if ( $only_site_id > 0 && $only_site_id !== $id ) {
				continue;
			}
			$expires = is_array( $entry ) ? (int) ( $entry['expires'] ?? 0 ) : 0;
			if ( $expires > 0 && $expires <= $now ) {
				self::release( $id, '', true );
			}
		}
	}

	/** @return array<string,array<string,mixed>> */
	private static function state(): array {
		$value = get_option( self::STATE_OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/** @param array<string,array<string,mixed>> $state */
	private static function save( array $state ): void {
		if ( empty( $state ) ) {
			delete_option( self::STATE_OPTION );
			return;
		}
		update_option( self::STATE_OPTION, $state, false );
	}
}
