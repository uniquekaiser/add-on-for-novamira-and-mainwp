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
		$site = MainWP_Client::site( $site_id );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		$policy = Storage::policy( $site_id );
		if ( ! $policy['gateway_enabled'] ) {
			return new \WP_Error( 'novamira_mainwp_gateway_disabled', 'The MainWP gateway is disabled for this site.' );
		}
		// Serialize the entire shared option, including changes to different sites.
		$lock = Database_Lock::acquire( 'runtime-state' );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			$state = self::state();
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			$key   = (string) $site_id;
			$entry = $state[ $key ] ?? array();
			if ( ! empty( $entry ) && ( (int) ( $entry['expires'] ?? 0 ) <= time() || empty( $entry['leases'] ) ) ) {
				$recovered = self::release_locked( $site_id, '', true, $state, $lock );
				if ( is_wp_error( $recovered ) ) {
					return $recovered;
				}
				$entry = $state[ $key ] ?? array();
			}
			if ( ! empty( $entry['opening'] ) ) {
				return new \WP_Error( 'novamira_mainwp_runtime_recovering', 'An interrupted AI access window is awaiting recovery.' );
			}
			try {
				$token = bin2hex( random_bytes( 32 ) );
			} catch ( \Throwable $error ) {
				return new \WP_Error( 'novamira_mainwp_runtime_random_failed', 'A secure access-window token could not be generated.' );
			}
			$expires = time() + self::TTL;
			$opening = empty( $entry );
			if ( $opening ) {
				// Read without enabling AI, then durably journal the original snapshot before mutation.
				$snapshot = MainWP_Client::child_operation( $site_id, 'ai-snapshot' );
				if ( is_wp_error( $snapshot ) ) {
					return $snapshot;
				}
				if ( ! isset( $snapshot['restore'] ) || ! is_array( $snapshot['restore'] ) || empty( $snapshot['restore']['missing'] ) ) {
					return new \WP_Error( 'novamira_mainwp_runtime_snapshot_missing', 'The child did not return an AI restore snapshot.' );
				}
				$entry = array(
					'restore' => $snapshot['restore'],
					'changed' => false,
					'leases'  => array(),
					'opening' => true,
				);
			}
			$entry['leases'][ $token ] = $expires;
			$entry['expires']          = max( array_map( 'intval', $entry['leases'] ) );
			$state[ $key ]             = $entry;
			if ( ! self::save( $state, $lock ) ) {
				return self::persistence_error();
			}
			// Schedule before ai-open so both a lost response and a process crash remain recoverable.
			if ( ! wp_schedule_single_event( $expires, 'novamira_mainwp_runtime_cleanup', array( $site_id, $token ) ) ) {
				self::release_locked( $site_id, $token, false, $state, $lock );
				return new \WP_Error( 'novamira_mainwp_runtime_cleanup_failed', 'AI cleanup could not be scheduled.' );
			}
			if ( $opening ) {
				if ( ! $lock->refresh() ) {
					return self::persistence_error();
				}
				$opened = MainWP_Client::child_operation(
					$site_id,
					'ai-open',
					array(
						'lifecycle'          => $policy['ai_lifecycle'],
						'production_allowed' => $policy['production_allowed'],
						'restore'            => $entry['restore'],
						'expires'            => $expires,
					)
				);
				if ( is_wp_error( $opened ) ) {
					if ( in_array( $opened->get_error_code(), array( 'ai_disabled_by_policy', 'production_not_approved', 'manual_enable_required', 'restore_state_changed', 'access_window_expired' ), true ) ) {
						// These child rejections occur before mutation; do not overwrite a newer manual setting.
						unset( $state[ $key ] );
						self::save( $state, $lock );
					}
					// Keep the journal: a transport error does not prove that the child stayed disabled.
					return $opened;
				}
				if ( ! isset( $opened['changed'] ) || ! is_bool( $opened['changed'] ) ) {
					return new \WP_Error( 'novamira_mainwp_runtime_open_invalid', 'The child did not confirm whether AI settings changed. The pending window will be recovered after expiry.' );
				}
				$entry['opening'] = false;
				$entry['changed'] = $opened['changed'];
				$state[ $key ]    = $entry;
				if ( ! self::save( $state, $lock ) ) {
					return self::persistence_error();
				}
			}
			return array(
				'token'   => $token,
				'expires' => $expires,
				'changed' => ! empty( $entry['changed'] ),
			);
		} finally {
			$lock->release();
		}
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function release( int $site_id, string $token, bool $expired = false ) {
		$lock = Database_Lock::acquire( 'runtime-state' );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			$state = self::state();
			return is_wp_error( $state ) ? $state : self::release_locked( $site_id, $token, $expired, $state, $lock );
		} finally {
			$lock->release();
		}
	}

	/**
	 * @param array<string,array<string,mixed>> $state The complete state under its database lock.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function release_locked( int $site_id, string $token, bool $expired, array &$state, Database_Lock $lock ) {
		$key = (string) $site_id;
		if ( ! isset( $state[ $key ] ) ) {
			return array(
				'released' => false,
				'restored' => false,
			);
		}
		$entry  = $state[ $key ];
		$leases = isset( $entry['leases'] ) && is_array( $entry['leases'] ) ? $entry['leases'] : array();
		$now    = time();
		if ( '' !== $token && isset( $leases[ $token ] ) && ( ! $expired || (int) $leases[ $token ] <= $now ) ) {
			unset( $leases[ $token ] );
		}
		foreach ( $leases as $lease_token => $lease_expiry ) {
			if ( (int) $lease_expiry <= $now ) {
				unset( $leases[ $lease_token ] );
			}
		}
		$entry['leases']  = $leases;
		$entry['expires'] = empty( $leases ) ? $now + 60 : max( array_map( 'intval', $leases ) );
		$state[ $key ]    = $entry;
		if ( ! self::save( $state, $lock ) ) {
			return self::persistence_error();
		}
		if ( ! empty( $leases ) ) {
			return array(
				'released' => true,
				'restored' => false,
			);
		}
		if ( empty( $entry['opening'] ) && empty( $entry['changed'] ) ) {
			// A confirmed unchanged window must never undo a user's later manual AI setting.
			unset( $state[ $key ] );
			return self::save( $state, $lock ) ? array(
				'released' => true,
				'restored' => false,
			) : self::persistence_error();
		}
		wp_schedule_single_event( $now + 60, 'novamira_mainwp_runtime_cleanup', array( $site_id, '' ) );
		$restored = MainWP_Client::restore_runtime_window( $site_id, $lock );
		if ( is_wp_error( $restored ) ) {
			Audit::record( $site_id, 'ai-restore', 'error', 0, array(), '', $restored->get_error_code() );
			return $restored;
		}
		unset( $state[ $key ] );
		if ( ! self::save( $state, $lock ) ) {
			return self::persistence_error();
		}
		Audit::record( $site_id, 'ai-restore', 'success', 0 );
		return array(
			'released' => true,
			'restored' => true,
		);
	}

	/** WP-Cron callback for crash-safe access-window cleanup. */
	public static function cleanup( int $site_id, string $token = '' ): void {
		$result = self::release( $site_id, $token, true );
		if ( is_wp_error( $result ) ) {
			wp_schedule_single_event( time() + 60, 'novamira_mainwp_runtime_cleanup', array( $site_id, $token ) );
		}
	}

	/** Recover expired windows on ordinary requests as a WP-Cron fallback. */
	public static function recover_expired( int $only_site_id = 0 ): void {
		$state = self::state();
		if ( is_wp_error( $state ) ) {
			return;
		}
		foreach ( $state as $site_id => $entry ) {
			$id = (int) $site_id;
			if ( ( 0 === $only_site_id || $only_site_id === $id ) && (int) ( $entry['expires'] ?? 0 ) <= time() ) {
				self::cleanup( $id );
			}
		}
	}

	/** @return array<string,array<string,mixed>>|\WP_Error */
	private static function state() {
		$value = Database_Lock::read_option( self::STATE_OPTION, array() );
		if ( is_wp_error( $value ) ) {
			return $value;
		}
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'novamira_mainwp_runtime_state_invalid', 'The AI access-window journal is invalid.' );
		}
		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) ) {
				return new \WP_Error( 'novamira_mainwp_runtime_state_invalid', 'The AI access-window journal is invalid.' );
			}
		}
		return $value;
	}

	/** @param array<string,array<string,mixed>> $state */
	private static function save( array $state, Database_Lock $lock ): bool {
		return $lock->refresh() && ( empty( $state ) ? Database_Lock::remove_option( self::STATE_OPTION ) : Database_Lock::write_option( self::STATE_OPTION, $state ) );
	}

	private static function persistence_error(): \WP_Error {
		return new \WP_Error( 'novamira_mainwp_runtime_persistence_failed', 'The AI access-window journal could not be safely persisted. Any pending window will be recovered after expiry.' );
	}
}
