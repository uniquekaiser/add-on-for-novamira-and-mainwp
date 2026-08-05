<?php
/**
 * Fixed one-shot operations executed through MainWP Child's signed Code Snippets channel.
 *
 * Nothing from this class is installed or persisted on a child site. MainWP Child
 * evaluates the generated operation for the current authenticated request only.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class Child_Runtime {
	private const MARKER = 'NOVAMIRA_MAINWP_RESULT:';

	/**
	 * Build an embedded, versioned child operation. Parameters are transported as
	 * base64 JSON, never interpolated as PHP source.
	 *
	 * @param array<string,mixed> $params Operation parameters.
	 */
	public static function script( string $action, array $params = array() ): string {
		$payload = base64_encode(
			wp_json_encode(
				array(
					'action' => sanitize_key( $action ),
					'params' => $params,
				)
			)
		); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Safe transport inside fixed PHP source.

		$script = <<<'PHP'
$__nmm_payload = json_decode( base64_decode( '__NMM_PAYLOAD__' ), true );
$__nmm_action  = is_array( $__nmm_payload ) && isset( $__nmm_payload['action'] ) ? sanitize_key( (string) $__nmm_payload['action'] ) : '';
$__nmm_params  = is_array( $__nmm_payload ) && isset( $__nmm_payload['params'] ) && is_array( $__nmm_payload['params'] ) ? $__nmm_payload['params'] : array();
$__nmm_marker  = 'NOVAMIRA_MAINWP_RESULT:';
$__nmm_reply   = static function ( $ok, $data = array(), $code = '', $message = '' ) use ( $__nmm_marker ) {
	$body = array( 'ok' => (bool) $ok, 'data' => is_array( $data ) ? $data : array() );
	if ( ! $ok ) {
		$body['error'] = array( 'code' => sanitize_key( (string) $code ), 'message' => (string) $message );
	}
	echo "\n" . $__nmm_marker . base64_encode( wp_json_encode( $body ) ) . "\n";
};
$__nmm_plugin_state = static function ( $plugins, $file ) {
	return array(
		'installed' => isset( $plugins[ $file ] ),
		'active'    => isset( $plugins[ $file ] ) && is_plugin_active( $file ),
		'version'   => isset( $plugins[ $file ] ) ? (string) ( $plugins[ $file ]['Version'] ?? '' ) : '',
	);
};
$__nmm_manual_enabled = static function () {
	$enabled = get_option( 'novamira_ai_abilities_enabled', false );
	$locked  = (string) get_option( 'novamira_ai_abilities_domain', '' );
	$current = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	return in_array( $enabled, array( true, 1, '1' ), true ) && '' !== $current && hash_equals( $locked, $current );
};
$__nmm_production = static function () {
	if ( function_exists( 'novamira_looks_like_production' ) ) {
		return (bool) novamira_looks_like_production();
	}
	$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
	$host        = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	if ( in_array( $environment, array( 'local', 'development', 'staging' ), true ) ) {
		return false;
	}
	return '' === $host || ( false !== strpos( $host, '.' ) && ! preg_match( '/(^|\.)(local|test|localhost)$/', $host ) );
};

try {
	if ( 'status' === $__nmm_action ) {
		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins  = get_plugins();
		$free     = $__nmm_plugin_state( $plugins, 'novamira/novamira.php' );
		$pro      = $__nmm_plugin_state( $plugins, 'novamira-pro/novamira-pro.php' );
		$username = isset( $__nmm_params['username'] ) ? sanitize_user( (string) $__nmm_params['username'] ) : '';
		$uuid     = isset( $__nmm_params['credential_uuid'] ) ? sanitize_text_field( (string) $__nmm_params['credential_uuid'] ) : '';
		$healthy  = null;
		if ( '' !== $username && '' !== $uuid ) {
			$user    = get_user_by( 'login', $username );
			$healthy = $user instanceof WP_User && is_array( WP_Application_Passwords::get_user_application_password( $user->ID, $uuid ) );
		}
		$abilities = array();
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( wp_get_abilities() as $ability ) {
				if ( is_object( $ability ) && method_exists( $ability, 'get_name' ) ) {
					$name = (string) $ability->get_name();
					if ( 0 === strpos( $name, 'novamira/' ) ) {
						$abilities[] = $name;
					}
				}
			}
			sort( $abilities, SORT_STRING );
		}
		$rules = function_exists( 'novamira_get_ability_rules' ) ? novamira_get_ability_rules() : array();
		$pro['license_active'] = function_exists( 'Novamira\\Pro\\is_license_active' ) && Novamira\Pro\is_license_active();
		$pro['license_masked'] = function_exists( 'Novamira\\Pro\\license_key_masked' ) ? Novamira\Pro\license_key_masked() : '';
		$pro['license_error']  = function_exists( 'Novamira\\Pro\\license_error' ) ? Novamira\Pro\license_error() : '';
		$__nmm_reply(
			true,
			array(
				'free'                      => $free,
				'pro'                       => $pro,
				'ai'                        => array(
					'manual_enabled' => $__nmm_manual_enabled(),
					'production'     => $__nmm_production(),
				),
				'application_passwords'     => array(
					'supported'          => function_exists( 'wp_is_application_passwords_supported' ) && wp_is_application_passwords_supported(),
					'credential_healthy' => $healthy,
				),
				'ability_rules'             => is_array( $rules ) ? $rules : array(),
				'available_abilities'       => $abilities,
				'available_abilities_known' => $__nmm_manual_enabled(),
				'runtime'                   => array( 'transport' => 'mainwp-child', 'persistent_code' => false ),
			)
		);
	} elseif ( 'credential-create' === $__nmm_action ) {
		$connected = sanitize_user( (string) get_option( 'mainwp_child_connected_admin', '' ) );
		$requested = isset( $__nmm_params['username'] ) ? sanitize_user( (string) $__nmm_params['username'] ) : '';
		$label     = isset( $__nmm_params['label'] ) ? sanitize_text_field( (string) $__nmm_params['label'] ) : 'Novamira MainWP';
		$user      = get_user_by( 'login', $connected );
		if ( '' === $connected || ( '' !== $requested && ! hash_equals( $connected, $requested ) ) ) {
			$__nmm_reply( false, array(), 'connected_user_mismatch', 'Credentials may be created only for the MainWP-connected administrator.' );
		} elseif ( ! $user instanceof WP_User || ( ! user_can( $user, 'manage_options' ) && ! ( is_multisite() && is_super_admin( $user->ID ) ) ) ) {
			$__nmm_reply( false, array(), 'connected_user_forbidden', 'The MainWP-connected user is not an administrator.' );
		} elseif ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			$__nmm_reply( false, array(), 'application_passwords_unavailable', 'Application Passwords are unavailable for the connected administrator.' );
		} else {
			$created = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => $label ) );
			if ( is_wp_error( $created ) ) {
				$__nmm_reply( false, array(), $created->get_error_code(), $created->get_error_message() );
			} else {
				$__nmm_reply( true, array( 'username' => $connected, 'password' => (string) $created[0], 'uuid' => (string) ( $created[1]['uuid'] ?? '' ), 'created' => (int) ( $created[1]['created'] ?? time() ) ) );
			}
		}
	} elseif ( 'credential-revoke' === $__nmm_action ) {
		$connected = sanitize_user( (string) get_option( 'mainwp_child_connected_admin', '' ) );
		$requested = isset( $__nmm_params['username'] ) ? sanitize_user( (string) $__nmm_params['username'] ) : '';
		$uuid      = isset( $__nmm_params['uuid'] ) ? sanitize_text_field( (string) $__nmm_params['uuid'] ) : '';
		$user      = get_user_by( 'login', $connected );
		if ( '' === $connected || '' === $uuid || ( '' !== $requested && ! hash_equals( $connected, $requested ) ) || ! $user instanceof WP_User ) {
			$__nmm_reply( false, array(), 'credential_revoke_invalid', 'The managed credential could not be resolved for the connected administrator.' );
		} else {
			$__nmm_reply( true, array( 'revoked' => (bool) WP_Application_Passwords::delete_application_password( $user->ID, $uuid ) ) );
		}
	} elseif ( 'set-rules' === $__nmm_action ) {
		$rules = array();
		foreach ( (array) ( $__nmm_params['disabled_abilities'] ?? array() ) as $name ) {
			$name = is_scalar( $name ) ? (string) $name : '';
			if ( 1 === preg_match( '/^novamira\/[a-z0-9][a-z0-9\/-]*$/', $name ) ) {
				$rules[ $name ] = array( 'disabled' => true );
			}
		}
		if ( function_exists( 'novamira_update_ability_rules' ) ) {
			novamira_update_ability_rules( $rules );
		}
		$__nmm_reply( true, array( 'ability_rules' => $rules ) );
	} elseif ( 'ai-set' === $__nmm_action ) {
		if ( ! defined( 'NOVAMIRA_VERSION' ) ) {
			throw new RuntimeException( 'Novamira Free must be active before its AI settings can be changed.' );
		}
		$enabled = ! empty( $__nmm_params['enabled'] );
		if ( $enabled ) {
			update_option( 'novamira_ai_abilities_enabled', '1', false );
			update_option( 'novamira_ai_abilities_domain', (string) wp_parse_url( home_url(), PHP_URL_HOST ), false );
		} else {
			update_option( 'novamira_ai_abilities_enabled', '0', false );
			delete_option( 'novamira_ai_abilities_domain' );
		}
		$__nmm_reply( true, array( 'manual_enabled' => $__nmm_manual_enabled() ) );
	} elseif ( 'ai-open' === $__nmm_action ) {
		if ( ! defined( 'NOVAMIRA_VERSION' ) ) {
			throw new RuntimeException( 'Novamira Free must be active before the MCP gateway can open an access window.' );
		}
		$lifecycle = isset( $__nmm_params['lifecycle'] ) ? sanitize_key( (string) $__nmm_params['lifecycle'] ) : 'just-in-time';
		$production_allowed = ! empty( $__nmm_params['production_allowed'] );
		$is_production = $__nmm_production();
		if ( 'disabled' === $lifecycle ) {
			$__nmm_reply( false, array(), 'ai_disabled_by_policy', 'AI access is disabled by the MainWP site policy.' );
		} elseif ( $is_production && ! $production_allowed ) {
			$__nmm_reply( false, array(), 'production_not_approved', 'Production gateway access has not been approved for this site.' );
		} elseif ( 'manual-only' === $lifecycle && ! $__nmm_manual_enabled() ) {
			$__nmm_reply( false, array(), 'manual_enable_required', 'Enable Novamira AI abilities manually before using this site.' );
		} else {
			$missing = 'novamira-mainwp-missing-' . wp_generate_uuid4();
			$old_enabled = get_option( 'novamira_ai_abilities_enabled', $missing );
			$old_domain  = get_option( 'novamira_ai_abilities_domain', $missing );
			$changed = 'just-in-time' === $lifecycle && ! $__nmm_manual_enabled();
			if ( $changed ) {
				update_option( 'novamira_ai_abilities_enabled', '1', false );
				update_option( 'novamira_ai_abilities_domain', (string) wp_parse_url( home_url(), PHP_URL_HOST ), false );
			}
			$__nmm_reply( true, array( 'changed' => $changed, 'production' => $is_production, 'restore' => array( 'missing' => $missing, 'enabled' => $old_enabled, 'domain' => $old_domain ) ) );
		}
	} elseif ( 'ai-restore' === $__nmm_action ) {
		$restore = isset( $__nmm_params['restore'] ) && is_array( $__nmm_params['restore'] ) ? $__nmm_params['restore'] : array();
		$missing = isset( $restore['missing'] ) ? (string) $restore['missing'] : '';
		if ( '' === $missing ) {
			$__nmm_reply( false, array(), 'restore_state_missing', 'The AI restore state is missing.' );
		} else {
			if ( isset( $restore['enabled'] ) && $missing !== $restore['enabled'] ) {
				update_option( 'novamira_ai_abilities_enabled', $restore['enabled'], false );
			} else {
				delete_option( 'novamira_ai_abilities_enabled' );
			}
			if ( isset( $restore['domain'] ) && $missing !== $restore['domain'] ) {
				update_option( 'novamira_ai_abilities_domain', $restore['domain'], false );
			} else {
				delete_option( 'novamira_ai_abilities_domain' );
			}
			$__nmm_reply( true, array( 'restored' => true, 'manual_enabled' => $__nmm_manual_enabled() ) );
		}
	} elseif ( 'pro-license' === $__nmm_action ) {
		$operation = isset( $__nmm_params['operation'] ) ? sanitize_key( (string) $__nmm_params['operation'] ) : '';
		$key       = isset( $__nmm_params['license_key'] ) ? trim( (string) $__nmm_params['license_key'] ) : '';
		$success   = false;
		$message   = '';
		if ( 'activate' === $operation && '' !== $key && function_exists( 'Novamira\\Pro\\activate_new_license_key' ) ) {
			list( $success, $message ) = Novamira\Pro\activate_new_license_key( $key );
		} elseif ( 'deactivate' === $operation && function_exists( 'Novamira\\Pro\\deactivate_license' ) ) {
			list( $success, $message ) = Novamira\Pro\deactivate_license();
		} elseif ( 'refresh' === $operation && function_exists( 'Novamira\\Pro\\refresh_and_repair_license_status' ) ) {
			Novamira\Pro\refresh_and_repair_license_status();
			$success = function_exists( 'Novamira\\Pro\\is_license_active' ) && Novamira\Pro\is_license_active();
			$message = function_exists( 'Novamira\\Pro\\license_error' ) ? Novamira\Pro\license_error() : '';
		} else {
			$__nmm_reply( false, array(), 'invalid_pro_operation', 'Novamira Pro is unavailable or the requested license operation is unsupported.' );
			$operation = '';
		}
		if ( in_array( $operation, array( 'activate', 'deactivate', 'refresh' ), true ) ) {
			$__nmm_reply( (bool) $success, array( 'active' => function_exists( 'Novamira\\Pro\\is_license_active' ) && Novamira\Pro\is_license_active(), 'masked' => function_exists( 'Novamira\\Pro\\license_key_masked' ) ? Novamira\Pro\license_key_masked() : '', 'message' => (string) $message ), 'pro_license_failed', (string) $message );
		}
	} else {
		$__nmm_reply( false, array(), 'unknown_operation', 'Unknown Novamira MainWP child operation.' );
	}
} catch ( Throwable $error ) {
	$__nmm_reply( false, array(), 'child_runtime_error', $error->getMessage() );
}
PHP;

		return str_replace( '__NMM_PAYLOAD__', $payload, $script );
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function decode( $response ) {
		if ( ! is_array( $response ) ) {
			return new \WP_Error( 'novamira_mainwp_child_invalid_response', 'MainWP Child returned an invalid runtime response.' );
		}
		if ( isset( $response['error'] ) ) {
			return new \WP_Error( 'novamira_mainwp_child_request_failed', (string) $response['error'], $response );
		}
		if ( 'SUCCESS' !== (string) ( $response['status'] ?? '' ) ) {
			return new \WP_Error( 'novamira_mainwp_child_runtime_failed', (string) ( $response['result'] ?? 'The MainWP Child runtime operation failed.' ) );
		}
		$output = (string) ( $response['result'] ?? '' );
		$offset = strrpos( $output, self::MARKER );
		if ( false === $offset ) {
			return new \WP_Error( 'novamira_mainwp_child_marker_missing', 'MainWP Child did not return the Novamira runtime marker.' );
		}
		$encoded = trim( substr( $output, $offset + strlen( self::MARKER ) ) );
		$decoded = json_decode( (string) base64_decode( $encoded, true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the fixed child result envelope.
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'novamira_mainwp_child_payload_invalid', 'MainWP Child returned an invalid Novamira payload.' );
		}
		if ( true !== ( $decoded['ok'] ?? false ) ) {
			$error = isset( $decoded['error'] ) && is_array( $decoded['error'] ) ? $decoded['error'] : array();
			return new \WP_Error( sanitize_key( (string) ( $error['code'] ?? 'novamira_mainwp_child_error' ) ), (string) ( $error['message'] ?? 'The child operation failed.' ) );
		}
		return isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array();
	}
}
