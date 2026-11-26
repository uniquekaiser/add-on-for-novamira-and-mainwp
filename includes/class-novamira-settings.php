<?php
/**
 * Dashboard-admin bulk settings for Novamira 2.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class Novamira_Settings {
	private const OPERATION = 'novamira-mainwp/admin-settings';

	/** @return array<string, string> */
	public static function levels(): array {
		return array(
			'most'        => __( 'Ask more', 'mainwp-novamira-addon' ),
			'big'         => __( 'Balanced', 'mainwp-novamira-addon' ),
			'almost_none' => __( 'Ask less', 'mainwp-novamira-addon' ),
			'skip'        => __( 'Skip approvals', 'mainwp-novamira-addon' ),
		);
	}

	/** @param mixed $patch @return array<string, mixed>|\WP_Error */
	public static function validate( $patch ) {
		if ( ! is_array( $patch ) || empty( $patch ) ) {
			return new \WP_Error( 'novamira_mainwp_settings_empty', 'Select at least one setting to change.' );
		}
		$scopes = array_intersect( array_keys( $patch ), array( 'feature', 'ability_approval', 'ability_enabled', 'read_mode', 'ghost_mode', 'reset_destinations' ) );
		if ( $scopes && 1 !== count( $patch ) ) {
			return new \WP_Error( 'novamira_mainwp_settings_scope', 'Apply this setting in its own batch.' );
		}
		foreach ( $patch as $field => $value ) {
			if ( ! self::valid_field( $field, $value ) ) {
				return new \WP_Error( 'novamira_mainwp_settings_invalid', 'A Novamira setting is unknown or contains an invalid value.' );
			}
		}
		foreach ( array( 'approval_instructions', 'user_context' ) as $field ) {
			if ( isset( $patch[ $field ] ) ) {
				$patch[ $field ] = sanitize_textarea_field( $patch[ $field ] );
			}
		}
		ksort( $patch );
		return $patch;
	}

	/** @param int|string $field @param mixed $value */
	private static function valid_field( $field, $value ): bool {
		switch ( $field ) {
			case 'approval_level':
				return is_string( $value ) && array_key_exists( $value, self::levels() );
			case 'hold_emails':
			case 'read_mode':
			case 'ghost_mode':
				return is_bool( $value );
			case 'reset_destinations':
				return true === $value;
			case 'approval_instructions':
			case 'user_context':
				$limit = 'approval_instructions' === $field ? 2000 : 8000;
				return is_string( $value ) && preg_match( '/^.{0,' . $limit . '}$/usD', $value ) === 1;
			case 'feature':
			case 'ability_enabled':
			case 'ability_approval':
				if ( ! is_array( $value ) || 2 !== count( $value ) || ! is_string( $value['id'] ?? null ) || ! preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+(?:\/[a-z0-9-]+)*$/D', $value['id'] ) || strlen( $value['id'] ) > 191 ) {
					return false;
				}
				return 'ability_approval' === $field
					? in_array( $value['choice'] ?? null, array( 'level', 'yes', 'no' ), true )
					: is_bool( $value['enabled'] ?? null );
			default:
				return false;
		}
	}

	/**
	 * A browser administrator either inspects a target, previews an exact patch,
	 * or consumes its signed confirmation before applying it.
	 *
	 * @param array<string, mixed> $input Browser request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function request( int $site_id, array $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'novamira_mainwp_permission_denied', 'Administrator access is required.' );
		}
		$site = MainWP_Client::site( $site_id );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		$step = $input['step'] ?? '';
		if ( 'inspect' === $step ) {
			return MainWP_Client::child_operation( $site_id, 'settings-inspect' );
		}
		$patch = self::validate( $input['patch'] ?? null );
		if ( is_wp_error( $patch ) ) {
			return $patch;
		}
		if ( 'preview' === $step ) {
			if ( self::policy_blocks_enable( $site_id, $patch ) ) {
				return self::policy_block_error();
			}
			$preview = MainWP_Client::child_operation( $site_id, 'settings-preview', array( 'patch' => $patch ) );
			if ( is_wp_error( $preview ) ) {
				return $preview;
			}
			if ( ! is_string( $preview['revision'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $preview['revision'] ) ) {
				return new \WP_Error( 'novamira_mainwp_settings_preview_invalid', 'The child did not return a valid settings preview.' );
			}
			$confirmation = Confirmation::issue( self::OPERATION, array( $site_id ), self::binding( $patch, $preview['revision'] ) );
			return is_wp_error( $confirmation ) ? $confirmation : $preview + $confirmation;
		}
		if ( 'apply' !== $step || ! is_string( $input['confirmation_token'] ?? null ) || ! is_string( $input['revision'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $input['revision'] ) ) {
			return new \WP_Error( 'novamira_mainwp_settings_confirm', 'Preview settings before applying them.' );
		}
		$lock = Database_Lock::acquire( 'policy-' . $site_id );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			if ( self::policy_blocks_enable( $site_id, $patch ) ) {
				return self::policy_block_error();
			}
			$revision  = $input['revision'];
			$confirmed = Confirmation::consume( $input['confirmation_token'], self::OPERATION, array( $site_id ), self::binding( $patch, $revision ) );
			if ( is_wp_error( $confirmed ) ) {
				return $confirmed;
			}
			$started = microtime( true );
			$result  = MainWP_Client::child_operation(
				$site_id,
				'settings-apply',
				array(
					'patch'    => $patch,
					'revision' => $revision,
				)
			);
			Audit::record( $site_id, 'novamira-settings', is_wp_error( $result ) ? 'error' : 'success', (int) ( ( microtime( true ) - $started ) * 1000 ), $patch, '', is_wp_error( $result ) ? $result->get_error_code() : '' );
			return $result;
		} finally {
			$lock->release();
		}
	}

	/** @param array<string,mixed> $patch */
	private static function policy_blocks_enable( int $site_id, array $patch ): bool {
		return ! empty( $patch['ability_enabled']['enabled'] ) && in_array( $patch['ability_enabled']['id'], Storage::policy( $site_id )['disabled_abilities'], true );
	}

	private static function policy_block_error(): \WP_Error {
		return new \WP_Error( 'novamira_mainwp_ability_policy_blocked', 'Remove the Dashboard policy block before enabling this ability.' );
	}

	/** @param array<string,mixed> $patch @return array<string,mixed> */
	private static function binding( array $patch, string $revision ): array {
		return array(
			'patch'    => $patch,
			'revision' => $revision,
			'session'  => wp_get_session_token(),
		);
	}
}
