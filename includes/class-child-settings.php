<?php
/**
 * Settings operations run through the signed MainWP Child transport.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class Child_Settings {
	public static function source(): string {
		return <<<'PHP'
$settings = static function ( $action, $params ) {
	if ( ! defined( 'NOVAMIRA_VERSION' ) || version_compare( NOVAMIRA_VERSION, '2.0.0', '<' )
		|| ! function_exists( 'Novamira\Confirmations\save_settings' )
		|| ! function_exists( 'Novamira\Features\features' ) ) {
		return new \WP_Error( 'novamira_settings_unsupported', 'Activate Novamira 2.0 or later to manage settings on this site.' );
	}

	$manager = \Novamira\Features\features();
	$approval = \Novamira\Confirmations\settings();
	$level = $approval['skip_approvals'] ? 'skip' : \Novamira\Confirmations\level_of( $approval['cutoff'] );
	$read_mode = novamira_is_read_mode();

	if ( 'settings-inspect' === $action ) {
		$features = array();
		foreach ( $manager->definitions() as $id => $definition ) {
			if ( $definition->toggleable && $definition->visible ) {
				$features[ $id ] = array( 'label' => $definition->label(), 'active' => $manager->is_active( $id ) );
			}
		}
		$abilities = array();
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( array_keys( wp_get_abilities() ) as $name ) {
				if ( ! novamira_ability_is_hub_hidden( $name ) ) {
					$abilities[] = $name;
				}
			}
			$abilities = array_unique( array_merge( $abilities, array_keys( novamira_get_ability_rules() ) ) );
			sort( $abilities, SORT_STRING );
		}
		return array(
			'version' => NOVAMIRA_VERSION,
			'approval_level' => $level ?? 'Custom (' . $approval['cutoff'] . ')',
			'hold_emails' => $approval['hold_emails'],
			'read_mode' => $read_mode,
			'ghost_mode' => function_exists( 'Novamira\GhostMode\status' ) ? \Novamira\GhostMode\status()['state'] : null,
			'approved_destinations' => count( \Novamira\Confirmations\approved_destinations() ),
			'features' => $features,
			'abilities' => $abilities,
		);
	}

	$patch = $params['patch'] ?? array();
	if ( ! is_array( $patch ) || ! $patch || array_diff( array_keys( $patch ), array( 'approval_level', 'hold_emails', 'approval_instructions', 'user_context', 'feature', 'ability_approval', 'ability_enabled', 'read_mode', 'ghost_mode', 'reset_destinations' ) ) ) {
		return new \WP_Error( 'novamira_settings_invalid', 'The settings patch is invalid.' );
	}

	$before = array();
	$after = array();
	$notes = array();
	$rest_fields = array();
	$dependent_state = array();

	if ( isset( $patch['read_mode'] ) || isset( $patch['ghost_mode'] ) ) {
		$mode = isset( $patch['read_mode'] ) ? 'read_mode' : 'ghost_mode';
		$enable = $patch[ $mode ];
		$connected = get_user_by( 'login', (string) get_option( 'mainwp_child_connected_admin', '' ) );
		if ( ! $connected instanceof \WP_User || ! novamira_user_can_manage( $connected ) ) {
			return new \WP_Error( 'novamira_mode_admin_required', 'The MainWP-connected child administrator could not be verified.' );
		}
		$gate = 'read_mode' === $mode ? 'Novamira\Pro\ReadMode\enable_result' : 'Novamira\Pro\GhostMode\save_result';
		if ( $enable && ( ! defined( 'NOVAMIRA_PRO_VERSION' ) || ! function_exists( 'Novamira\Pro\is_license_active' ) || ! \Novamira\Pro\is_license_active() || ! function_exists( $gate ) ) ) {
			return new \WP_Error( 'novamira_' . $mode . '_license_required', 'Turning on this mode requires licensed Novamira Pro with support for it.' );
		}
		if ( 'read_mode' === $mode ) {
			$mode_state = \Novamira\ReadMode\status()['state'];
			$dependent_state = array( $connected->ID, \Novamira\ReadMode\read_state() );
			if ( ! $enable ) {
				$notes[] = 'Turning off Read Mode restores access under the child approval settings.';
			}
		} else {
			if ( ! function_exists( 'Novamira\GhostMode\status' ) ) {
				return new \WP_Error( 'novamira_ghost_mode_unavailable', 'Ghost Mode is unavailable on this site.' );
			}
			$ghost = \Novamira\GhostMode\read_policy();
			$mode_state = \Novamira\GhostMode\status()['state'];
			$owners = $ghost['visible_to'];
			$dependent_state = array( $connected->ID, $ghost );
			if ( $ghost['schema_version'] > \Novamira\GhostMode\SCHEMA_VERSION || ( $enable && ( 'unreadable' === $mode_state || $ghost['malformed'] || ! in_array( $connected->ID, $owners, true ) || count( \Novamira\GhostMode\valid_owner_ids( $owners ) ) !== count( $owners ) ) ) ) {
				return new \WP_Error( 'novamira_ghost_mode_owners_required', 'Verify Ghost Mode owners on the child, including the MainWP-connected administrator.' );
			}
			$notes[] = $enable ? 'Ghost Mode hides Novamira from users outside this site’s existing owner list.' : 'Turning off Ghost Mode makes Novamira visible under ordinary WordPress permissions.';
		}
		$before[ $mode ] = $mode_state;
		$after[ $mode ] = $enable ? 'on' : 'off';
	} elseif ( isset( $patch['reset_destinations'] ) ) {
		$destinations = \Novamira\Confirmations\approved_destinations();
		$dependent_state = $destinations;
		$before['approved_destinations'] = count( $destinations );
		$after['approved_destinations'] = 0;
		$notes[] = 'Previously approved destinations will need approval again when approvals are active.';
	} elseif ( isset( $patch['feature'] ) ) {
		$id = $patch['feature']['id'];
		$enabled = $patch['feature']['enabled'];
		$definition = $manager->definition( $id );
		if ( $read_mode || ! $definition || ! $definition->toggleable || ! $definition->visible ) {
			return new \WP_Error( 'novamira_feature_unavailable', 'This feature cannot be changed on this site. Check availability and Read Mode.' );
		}
		$transition = $enabled ? $manager->preview_activation( $id ) : $manager->preview_deactivation( $id );
		if ( ! $transition->applied ) {
			return new \WP_Error( 'novamira_feature_blocked', implode( ' ', $transition->blockers ) ?: 'Novamira blocked this feature change.' );
		}
		foreach ( $transition->features as $feature ) {
			$before[ $feature ] = $manager->is_active( $feature );
			$after[ $feature ] = $transition->states[ $feature ] ?? $before[ $feature ];
		}
		if ( ! $enabled && isset( $after['novamira/ai-change-history'] ) && $approval['hold_emails'] ) {
			$notes[] = 'Turning off AI change history also stops holding new AI emails.';
		}
	} elseif ( isset( $patch['ability_approval'] ) || isset( $patch['ability_enabled'] ) ) {
		$field = isset( $patch['ability_approval'] ) ? 'ability_approval' : 'ability_enabled';
		$id = $patch[ $field ]['id'];
		$known = function_exists( 'wp_has_ability' ) && wp_has_ability( $id );
		$disabled = novamira_ability_has_disabled_rule( $id );
		if ( ( ! $known && ! $disabled ) || novamira_ability_is_hub_hidden( $id ) ) {
			return new \WP_Error( 'novamira_ability_unavailable', 'This ability is unavailable in the child Abilities Hub.' );
		}
		if ( 'ability_approval' === $field ) {
			$desired = $patch[ $field ]['choice'];
			if ( ! novamira_confirmations_are_on() || ! in_array( $desired, \Novamira\Confirmations\allowed_choices( $id ), true ) ) {
				return new \WP_Error( 'novamira_ability_approval_unavailable', 'This approval choice is unavailable for the selected ability.' );
			}
			$before[ $id ] = \Novamira\Confirmations\ability_choice( $id );
		} else {
			$desired = $patch[ $field ]['enabled'];
			if ( ! novamira_ability_can_be_managed_individually( $id ) ) {
				return new \WP_Error( 'novamira_ability_feature_owned', 'Change the owning feature to manage this ability.' );
			}
			$managed = get_option( 'novamira_mainwp_managed_ability_blocks', array() );
			if ( $desired && is_array( $managed ) && in_array( $id, $managed, true ) ) {
				return new \WP_Error( 'novamira_ability_policy_blocked', 'Remove the Dashboard policy block before enabling this ability.' );
			}
			$before[ $id ] = ! $disabled;
		}
		$after[ $id ] = $desired;
	} else {
		foreach ( $patch as $field => $value ) {
			switch ( $field ) {
				case 'approval_level':
					$before[ $field ] = $level ?? 'Custom (' . $approval['cutoff'] . ')';
					$rest_fields['level'] = $value;
					if ( $level !== $value ) {
						$notes[] = 'Novamira cancels pending requests when the approval threshold or skip mode changes.';
					}
					break;
				case 'hold_emails':
					$before[ $field ] = $approval['hold_emails'];
					$rest_fields['hold_emails'] = $value;
					if ( $value && ! \Novamira\Confirmations\history_is_on() ) {
						$notes[] = 'Email holding takes effect when AI change history is active.';
					}
					break;
				case 'approval_instructions':
					$before[ $field ] = \Novamira\Confirmations\approval_instructions();
					$value = mb_substr( trim( sanitize_textarea_field( $value ) ), 0, \Novamira\Confirmations\INSTRUCTIONS_MAX );
					$rest_fields['instructions'] = $value;
					break;
				case 'user_context':
					if ( ! function_exists( 'Novamira\Context\instructions_update_content' ) ) {
						return new \WP_Error( 'novamira_context_unavailable', 'User context is unavailable on this site.' );
					}
					$before[ $field ] = \Novamira\Context\instructions_get_content();
					break;
				default:
					return new \WP_Error( 'novamira_settings_invalid', 'The settings patch mixes incompatible changes.' );
			}
			$after[ $field ] = $value;
		}
	}

	$revision = hash_hmac( 'sha256', wp_json_encode( array( NOVAMIRA_VERSION, $patch, $before, $after, $notes, $approval, $read_mode, $dependent_state ) ), wp_salt( 'auth' ) );
	if ( 'settings-preview' === $action ) {
		foreach ( array( 'approval_instructions', 'user_context' ) as $field ) {
			if ( isset( $before[ $field ] ) ) {
				$before[ $field ] = '' === $before[ $field ] ? '(empty)' : '(text already saved)';
			}
		}
		return array( 'revision' => $revision, 'before' => $before, 'after' => $after, 'notes' => $notes );
	}
	if ( 'settings-apply' !== $action || ! is_string( $params['revision'] ?? null ) || ! hash_equals( $revision, $params['revision'] ) ) {
		return new \WP_Error( 'novamira_settings_changed', 'Settings changed since the preview. Preview again before applying.' );
	}

	if ( isset( $mode ) ) {
		if ( $mode_state === $after[ $mode ] ) {
			return array( 'message' => 'Already in the requested state.' );
		}
		if ( 'read_mode' === $mode ) {
			$result = $enable ? \Novamira\ReadMode\enable( $connected->ID, 'pro', NOVAMIRA_PRO_VERSION ) : \Novamira\ReadMode\disable( $connected->ID, 'free-ui' );
			$actual = \Novamira\ReadMode\status()['state'];
		} else {
			$result = $enable ? \Novamira\GhostMode\save_policy( true, $owners, $connected->ID, 'pro', NOVAMIRA_PRO_VERSION ) : \Novamira\GhostMode\disable( $connected->ID, 'free-ui' );
			$actual = \Novamira\GhostMode\status()['state'];
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $actual !== $after[ $mode ] ) {
			return new \WP_Error( 'novamira_settings_save_failed', 'The mode change could not be verified.' );
		}
	} elseif ( isset( $patch['reset_destinations'] ) ) {
		\Novamira\Confirmations\forget_destinations( $destinations );
		if ( \Novamira\Confirmations\approved_destinations() ) {
			return new \WP_Error( 'novamira_settings_save_failed', 'Some approved destinations remain. Preview the current state before retrying.' );
		}
	} elseif ( isset( $patch['feature'] ) ) {
		$transition = $enabled ? $manager->activate( $id ) : $manager->deactivate( $id );
		if ( ! $transition->applied ) {
			return new \WP_Error( 'novamira_feature_blocked', implode( ' ', $transition->blockers ) ?: 'Novamira blocked this feature change.' );
		}
		$stored = get_option( \Novamira\Features\OPTION_STATES, array() );
		foreach ( $after as $feature => $desired ) {
			$definition = $manager->definition( $feature );
			if ( $definition && $definition->toggleable && ( ! is_array( $stored ) || ( $stored[ $feature ] ?? null ) !== $desired ) ) {
				return new \WP_Error( 'novamira_settings_save_failed', 'The feature change could not be verified. Preview again to check the current state.' );
			}
		}
	} elseif ( isset( $patch['ability_approval'] ) ) {
		if ( ! \Novamira\Confirmations\set_ability_choice( $id, $desired ) || $desired !== \Novamira\Confirmations\ability_choice( $id ) ) {
			return new \WP_Error( 'novamira_settings_save_failed', 'The ability approval choice could not be saved.' );
		}
	} elseif ( isset( $patch['ability_enabled'] ) ) {
		$rules = novamira_get_ability_rules();
		if ( $desired ) {
			unset( $rules[ $id ] );
		} else {
			$rules[ $id ] = array( 'disabled' => true );
		}
		novamira_update_ability_rules( $rules );
		if ( $desired === novamira_ability_has_disabled_rule( $id ) ) {
			return new \WP_Error( 'novamira_settings_save_failed', 'The ability state could not be saved.' );
		}
	} else {
		if ( $rest_fields ) {
			$request = new \WP_REST_Request( 'POST', '/novamira/v1/confirmations/settings' );
			foreach ( $rest_fields as $field => $value ) {
				$request->set_param( $field, $value );
			}
			$result = \Novamira\Confirmations\save_settings( $request );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$saved = \Novamira\Confirmations\settings();
			$saved_level = $saved['skip_approvals'] ? 'skip' : \Novamira\Confirmations\level_of( $saved['cutoff'] );
			if ( ( isset( $after['approval_level'] ) && ( $after['approval_level'] !== $saved_level || ! $saved['level_chosen'] ) )
				|| ( isset( $after['hold_emails'] ) && $after['hold_emails'] !== $saved['hold_emails'] )
				|| ( isset( $after['approval_instructions'] ) && $after['approval_instructions'] !== \Novamira\Confirmations\approval_instructions() )
			) {
				return new \WP_Error( 'novamira_settings_save_failed', 'Some approval settings could not be saved. Preview again to check the current state.' );
			}
		}
		if ( isset( $after['user_context'] ) ) {
			\Novamira\Context\instructions_update_content( $after['user_context'] );
			if ( $after['user_context'] !== \Novamira\Context\instructions_get_content() ) {
				return new \WP_Error( 'novamira_settings_save_failed', 'User context could not be saved. Preview again to check the current state.' );
			}
		}
	}
	return array( 'message' => 'Novamira settings saved.' );
};

try {
	$result = $settings( $__nmm_action, $__nmm_params );
	if ( is_wp_error( $result ) ) {
		$__nmm_reply( false, array(), $result->get_error_code(), $result->get_error_message() );
	} else {
		$__nmm_reply( true, $result );
	}
} catch ( \Throwable $error ) {
	$__nmm_reply( false, array(), 'novamira_settings_failed', 'The child could not complete the settings operation. Preview again to check the current state.' );
}
return;
PHP;
	}
}
