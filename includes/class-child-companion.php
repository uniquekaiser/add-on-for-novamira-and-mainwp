<?php
/**
 * Child-site companion and authenticated MainWP extension contract.
 *
 * This file deliberately has no dependency on Novamira internals. A valid
 * request lease changes the two public Novamira enablement options only for
 * the current request through WordPress' pre_option filters.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class Child_Companion {
	public const CONTRACT_VERSION = 1;
	public const LEASE_TTL        = 300;
	public const LEASE_OPTION     = 'novamira_mainwp_companion_leases';
	public const POLICY_OPTION    = 'novamira_mainwp_companion_policy';
	public const RULES_OPTION     = 'novamira_mainwp_companion_ability_rules';

	/**
	 * Run as soon as this plugin file is loaded, before Novamira evaluates its
	 * enablement option and decides whether to register its MCP server.
	 */
	public static function preload(): void {
		if ( ! self::request_has_valid_lease() ) {
			return;
		}

		add_filter( 'pre_option_novamira_ai_abilities_enabled', array( self::class, 'lease_enabled_option' ), 1, 3 );
		add_filter( 'pre_option_novamira_ai_abilities_domain', array( self::class, 'lease_domain_option' ), 1, 3 );
	}

	/** @param mixed $pre @return string */
	public static function lease_enabled_option( $pre, string $option = '', $default_value = false ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return '1';
	}

	/** @param mixed $pre @return string */
	public static function lease_domain_option( $pre, string $option = '', $default_value = false ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/** Register only on a site that actually runs MainWP Child. */
	public static function register_contract(): void {
		if ( self::is_child_site() ) {
			add_filter( 'mainwp_child_extra_execution', array( self::class, 'extra_execution' ), 20, 2 );
			add_action( 'admin_init', array( self::class, 'repair_load_order' ) );
		}
	}

	public static function is_child_site(): bool {
		return defined( 'MAINWP_CHILD_FILE' )
			|| defined( 'MAINWP_CHILD_PLUGIN_FILE' )
			|| defined( 'MAINWP_CHILD_VERSION' )
			|| class_exists( '\MainWP\Child\MainWP_Child' );
	}

	/**
	 * Keep the companion before Novamira in WordPress' active-plugin list. This
	 * lets the request-scoped option filters exist before stock Novamira boots.
	 */
	public static function repair_load_order(): void {
		self::promote_in_option( 'active_plugins' );
		if ( is_multisite() ) {
			self::promote_network_plugin();
		}
	}

	public static function after_activation( string $plugin ): void {
		if ( plugin_basename( NOVAMIRA_MAINWP_FILE ) === $plugin ) {
			self::repair_load_order();
		}
	}

	private static function promote_in_option( string $option ): void {
		$plugins = get_option( $option, array() );
		$file    = plugin_basename( NOVAMIRA_MAINWP_FILE );
		if ( ! is_array( $plugins ) || ! in_array( $file, $plugins, true ) || reset( $plugins ) === $file ) {
			return;
		}
		$plugins = array_values( array_diff( $plugins, array( $file ) ) );
		array_unshift( $plugins, $file );
		update_option( $option, $plugins );
	}

	private static function promote_network_plugin(): void {
		$plugins = get_site_option( 'active_sitewide_plugins', array() );
		$file    = plugin_basename( NOVAMIRA_MAINWP_FILE );
		if ( ! is_array( $plugins ) || ! isset( $plugins[ $file ] ) || array_key_first( $plugins ) === $file ) {
			return;
		}
		$activated = $plugins[ $file ];
		unset( $plugins[ $file ] );
		$plugins = array( $file => $activated ) + $plugins;
		update_site_option( 'active_sitewide_plugins', $plugins );
	}

	/**
	 * @param array<string, mixed> $information Existing MainWP Child response.
	 * @param mixed                $post Signed extra_execution parameters.
	 * @return array<string, mixed>
	 */
	public static function extra_execution( array $information, $post ): array {
		if ( ! is_array( $post ) || 'v1' !== ( $post['novamira_mainwp_contract'] ?? '' ) ) {
			return $information;
		}

		$action = isset( $post['novamira_mainwp_action'] ) && is_scalar( $post['novamira_mainwp_action'] )
			? sanitize_key( (string) $post['novamira_mainwp_action'] )
			: '';
		$params = isset( $post['novamira_mainwp_params'] ) && is_array( $post['novamira_mainwp_params'] )
			? $post['novamira_mainwp_params']
			: array();

		try {
			$result = self::dispatch( $action, $params );
			if ( is_wp_error( $result ) ) {
				$information['novamira_mainwp'] = array(
					'ok'               => false,
					'error'            => array(
						'code'    => (string) $result->get_error_code(),
						'message' => $result->get_error_message(),
						'data'    => $result->get_error_data(),
					),
					'contract_version' => self::CONTRACT_VERSION,
				);
				return $information;
			}

			$information['novamira_mainwp'] = array(
				'ok'               => true,
				'data'             => $result,
				'contract_version' => self::CONTRACT_VERSION,
			);
		} catch ( \Throwable $error ) {
			$information['novamira_mainwp'] = array(
				'ok'               => false,
				'error'            => array(
					'code'    => 'novamira_mainwp_unhandled_error',
					'message' => $error->getMessage(),
				),
				'contract_version' => self::CONTRACT_VERSION,
			);
		}

		return $information;
	}

	/** @param array<string, mixed> $params @return array<string, mixed>|\WP_Error */
	public static function dispatch( string $action, array $params ) {
		switch ( $action ) {
			case 'status':
				return self::status( $params );
			case 'set-policy':
				return self::set_policy( $params );
			case 'credential-create':
				return self::create_credential( $params );
			case 'credential-revoke':
				return self::revoke_credential( $params );
			case 'lease-acquire':
				return self::acquire_lease();
			case 'lease-release':
				return self::release_lease( $params );
			case 'pro-license':
				return self::manage_pro_license( $params );
			default:
				return new \WP_Error( 'novamira_mainwp_unknown_action', 'Unknown Novamira MainWP child action.' );
		}
	}

	/** @return array{gateway_enabled:bool,production_allowed:bool,ai_lifecycle:string} */
	public static function policy(): array {
		$stored    = get_option( self::POLICY_OPTION, array() );
		$stored    = is_array( $stored ) ? $stored : array();
		$lifecycle = isset( $stored['ai_lifecycle'] ) && is_string( $stored['ai_lifecycle'] ) ? sanitize_key( $stored['ai_lifecycle'] ) : 'just-in-time';
		if ( ! in_array( $lifecycle, array( 'just-in-time', 'manual-only', 'disabled' ), true ) ) {
			$lifecycle = 'just-in-time';
		}
		return array(
			'gateway_enabled'    => in_array( $stored['gateway_enabled'] ?? true, array( true, 1, '1' ), true ),
			'production_allowed' => in_array( $stored['production_allowed'] ?? false, array( true, 1, '1' ), true ),
			'ai_lifecycle'       => $lifecycle,
		);
	}

	/** @param array<string, mixed> $params @return array<string, mixed> */
	private static function status( array $params ): array {
		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		$free    = self::plugin_state( $plugins, 'novamira/novamira.php' );
		$pro     = self::plugin_state( $plugins, 'novamira-pro/novamira-pro.php' );

		$username           = isset( $params['username'] ) && is_scalar( $params['username'] ) ? sanitize_user( (string) $params['username'] ) : '';
		$uuid               = isset( $params['credential_uuid'] ) && is_scalar( $params['credential_uuid'] ) ? sanitize_text_field( (string) $params['credential_uuid'] ) : '';
		$credential_healthy = null;
		if ( '' !== $username && '' !== $uuid ) {
			$user               = get_user_by( 'login', $username );
			$credential_healthy = $user instanceof \WP_User
				&& is_array( \WP_Application_Passwords::get_user_application_password( $user->ID, $uuid ) );
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

		$manual                = self::manual_enabled();
		$pro['license_active'] = function_exists( 'Novamira\\Pro\\is_license_active' ) && \Novamira\Pro\is_license_active();
		$pro['license_masked'] = function_exists( 'Novamira\\Pro\\license_key_masked' ) ? \Novamira\Pro\license_key_masked() : '';
		$pro['license_error']  = function_exists( 'Novamira\\Pro\\license_error' ) ? \Novamira\Pro\license_error() : '';

		return array(
			'companion'                 => array(
				'installed'        => true,
				'active'           => true,
				'version'          => NOVAMIRA_MAINWP_VERSION,
				'contract_version' => self::CONTRACT_VERSION,
				'load_order_ok'    => self::load_order_ok(),
				'jit_ready'        => self::load_order_ok() && $free['active'],
			),
			'free'                      => $free,
			'pro'                       => $pro,
			'ai'                        => array(
				'manual_enabled'     => $manual,
				'production'         => self::looks_like_production(),
				'gateway_enabled'    => self::policy()['gateway_enabled'],
				'production_allowed' => self::policy()['production_allowed'],
				'lifecycle'          => self::policy()['ai_lifecycle'],
				'active_leases'      => count( self::live_leases() ),
			),
			'application_passwords'     => array(
				'supported'          => function_exists( 'wp_is_application_passwords_supported' ) && wp_is_application_passwords_supported(),
				'credential_healthy' => $credential_healthy,
			),
			'ability_rules'             => self::ability_rules(),
			'available_abilities'       => $abilities,
			'available_abilities_known' => $manual,
		);
	}

	/** @param array<string, array<string, mixed>> $plugins @return array<string, mixed> */
	private static function plugin_state( array $plugins, string $file ): array {
		return array(
			'installed' => isset( $plugins[ $file ] ),
			'active'    => isset( $plugins[ $file ] ) && is_plugin_active( $file ),
			'version'   => isset( $plugins[ $file ] ) ? (string) ( $plugins[ $file ]['Version'] ?? '' ) : '',
		);
	}

	private static function manual_enabled(): bool {
		$enabled = get_option( 'novamira_ai_abilities_enabled', false );
		$locked  = (string) get_option( 'novamira_ai_abilities_domain', '' );
		$current = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return in_array( $enabled, array( true, 1, '1' ), true ) && '' !== $current && hash_equals( $locked, $current );
	}

	private static function looks_like_production(): bool {
		if ( function_exists( 'novamira_looks_like_production' ) ) {
			return (bool) novamira_looks_like_production();
		}
		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$host        = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( in_array( $environment, array( 'local', 'development', 'staging' ), true ) ) {
			return false;
		}
		return '' === $host || ( false !== strpos( $host, '.' ) && ! preg_match( '/(^|\.)(local|test|localhost)$/', $host ) );
	}

	/** @param array<string, mixed> $params @return array<string, mixed> */
	private static function set_policy( array $params ): array {
		$lifecycle = isset( $params['ai_lifecycle'] ) && is_scalar( $params['ai_lifecycle'] ) ? sanitize_key( (string) $params['ai_lifecycle'] ) : 'just-in-time';
		if ( ! in_array( $lifecycle, array( 'just-in-time', 'manual-only', 'disabled' ), true ) ) {
			$lifecycle = 'just-in-time';
		}
		$policy = array(
			'gateway_enabled'    => in_array( $params['gateway_enabled'] ?? true, array( true, 1, '1' ), true ),
			'production_allowed' => in_array( $params['production_allowed'] ?? false, array( true, 1, '1' ), true ),
			'ai_lifecycle'       => $lifecycle,
		);
		update_option( self::POLICY_OPTION, $policy, false );

		$rules = array();
		if ( isset( $params['disabled_abilities'] ) && is_array( $params['disabled_abilities'] ) ) {
			foreach ( $params['disabled_abilities'] as $name ) {
				if ( is_string( $name ) && self::valid_ability_name( $name ) ) {
					$rules[ $name ] = array( 'disabled' => true );
				}
			}
		}
		update_option( self::RULES_OPTION, $rules, false );
		if ( function_exists( 'novamira_update_ability_rules' ) ) {
			novamira_update_ability_rules( $rules );
		}

		return array(
			'policy'        => $policy,
			'ability_rules' => self::ability_rules(),
		);
	}

	/** @return array<string, mixed> */
	private static function ability_rules(): array {
		if ( function_exists( 'novamira_get_ability_rules' ) ) {
			$rules = novamira_get_ability_rules();
			return is_array( $rules ) ? $rules : array();
		}
		$rules = get_option( self::RULES_OPTION, array() );
		return is_array( $rules ) ? $rules : array();
	}

	private static function valid_ability_name( string $name ): bool {
		if ( function_exists( 'novamira_is_valid_ability_name' ) ) {
			return (bool) novamira_is_valid_ability_name( $name );
		}
		return 1 === preg_match( '/^novamira\/[a-z0-9][a-z0-9\/-]*$/', $name );
	}

	/** @param array<string, mixed> $params @return array<string, mixed>|\WP_Error */
	private static function create_credential( array $params ) {
		$requested = isset( $params['username'] ) && is_scalar( $params['username'] ) ? sanitize_user( (string) $params['username'] ) : '';
		$username  = sanitize_user( (string) get_option( 'mainwp_child_connected_admin', '' ) );
		$label     = isset( $params['label'] ) && is_scalar( $params['label'] ) ? sanitize_text_field( (string) $params['label'] ) : 'Novamira MainWP';
		$user      = get_user_by( 'login', $username );
		if ( '' === $username || ( '' !== $requested && ! hash_equals( $username, $requested ) ) ) {
			return new \WP_Error( 'novamira_mainwp_connected_user_mismatch', 'Credentials may be created only for the MainWP-connected administrator.' );
		}
		if ( ! $user instanceof \WP_User || ( ! user_can( $user, 'manage_options' ) && ! ( is_multisite() && is_super_admin( $user->ID ) ) ) ) {
			return new \WP_Error( 'novamira_mainwp_invalid_user', 'The connected MainWP user must be an administrator.' );
		}
		if ( ! function_exists( 'wp_is_application_passwords_available_for_user' ) || ! wp_is_application_passwords_available_for_user( $user ) ) {
			return new \WP_Error( 'novamira_mainwp_application_passwords_unavailable', 'WordPress Application Passwords are unavailable for this user.' );
		}
		$created = \WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array(
				'name'   => substr( $label, 0, 255 ),
				'app_id' => wp_generate_uuid4(),
			)
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$password = $created[0];
		$item     = $created[1];
		return array(
			'username' => $user->user_login,
			'password' => $password,
			'uuid'     => (string) ( $item['uuid'] ?? '' ),
			'name'     => (string) ( $item['name'] ?? $label ),
			'created'  => (int) ( $item['created'] ?? time() ),
		);
	}

	/** @param array<string, mixed> $params @return array<string, mixed>|\WP_Error */
	private static function revoke_credential( array $params ) {
		$requested = isset( $params['username'] ) && is_scalar( $params['username'] ) ? sanitize_user( (string) $params['username'] ) : '';
		$username  = sanitize_user( (string) get_option( 'mainwp_child_connected_admin', '' ) );
		$uuid      = isset( $params['uuid'] ) && is_scalar( $params['uuid'] ) ? sanitize_text_field( (string) $params['uuid'] ) : '';
		$user      = get_user_by( 'login', $username );
		if ( '' === $username || ( '' !== $requested && ! hash_equals( $username, $requested ) ) ) {
			return new \WP_Error( 'novamira_mainwp_connected_user_mismatch', 'Credentials may be revoked only for the MainWP-connected administrator.' );
		}
		if ( ! $user instanceof \WP_User || '' === $uuid ) {
			return new \WP_Error( 'novamira_mainwp_invalid_credential', 'A valid connected user and credential UUID are required.' );
		}
		$deleted = \WP_Application_Passwords::delete_application_password( $user->ID, $uuid );
		return is_wp_error( $deleted ) ? $deleted : array(
			'revoked' => (bool) $deleted,
			'uuid'    => $uuid,
		);
	}

	/** @return array<string, int> */
	public static function live_leases(): array {
		$stored = get_option( self::LEASE_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$now    = time();
		$live   = array();
		foreach ( $stored as $hash => $expires_at ) {
			if ( is_string( $hash ) && is_numeric( $expires_at ) && (int) $expires_at > $now ) {
				$live[ $hash ] = (int) $expires_at;
			}
		}
		if ( $live !== $stored ) {
			update_option( self::LEASE_OPTION, $live, false );
		}
		return $live;
	}

	public static function lease_hash( string $token ): string {
		$key = '';
		foreach ( array( 'AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_KEY', 'SECURE_AUTH_SALT' ) as $constant ) {
			if ( defined( $constant ) ) {
				$key .= (string) constant( $constant );
			}
		}
		if ( '' === $key && function_exists( 'wp_salt' ) ) {
			$key = wp_salt( 'auth' );
		}
		return hash_hmac( 'sha256', $token, '' !== $key ? $key : self::LEASE_OPTION . __FILE__ );
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function acquire_lease() {
		$policy = self::policy();
		if ( ! $policy['gateway_enabled'] ) {
			return new \WP_Error( 'novamira_mainwp_gateway_disabled', 'The MainWP gateway is disabled for this site.' );
		}
		if ( 'disabled' === $policy['ai_lifecycle'] ) {
			return new \WP_Error( 'novamira_mainwp_ai_disabled', 'MainWP AI access is disabled by this site policy.' );
		}
		if ( 'manual-only' === $policy['ai_lifecycle'] && ! self::manual_enabled() ) {
			return new \WP_Error( 'novamira_mainwp_manual_enablement_required', 'This site policy requires Novamira AI abilities to be enabled manually.' );
		}
		if ( self::looks_like_production() && ! $policy['production_allowed'] ) {
			return new \WP_Error( 'novamira_mainwp_production_denied', 'Just-in-time AI access is not approved for this production site.' );
		}
		try {
			$token = bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'novamira_mainwp_random_failed', 'Unable to create a secure lease token.' );
		}
		$expires_at                           = time() + self::LEASE_TTL;
		$leases                               = self::live_leases();
		$leases[ self::lease_hash( $token ) ] = $expires_at;
		update_option( self::LEASE_OPTION, $leases, false );
		return array(
			'token'      => $token,
			'expires_at' => $expires_at,
			'ttl'        => self::LEASE_TTL,
		);
	}

	/** @param array<string, mixed> $params @return array<string, mixed>|\WP_Error */
	private static function release_lease( array $params ) {
		$token = isset( $params['token'] ) && is_scalar( $params['token'] ) ? (string) $params['token'] : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return new \WP_Error( 'novamira_mainwp_invalid_lease', 'A valid lease token is required.' );
		}
		$leases   = self::live_leases();
		$hash     = self::lease_hash( $token );
		$released = isset( $leases[ $hash ] );
		unset( $leases[ $hash ] );
		update_option( self::LEASE_OPTION, $leases, false );
		return array( 'released' => $released );
	}

	private static function request_token(): string {
		$token = isset( $_SERVER['HTTP_X_NOVAMIRA_MAINWP_LEASE'] ) && is_string( $_SERVER['HTTP_X_NOVAMIRA_MAINWP_LEASE'] )
			? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_NOVAMIRA_MAINWP_LEASE'] ) ) )
			: '';
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $token ) ? $token : '';
	}

	public static function request_has_valid_lease(): bool {
		$token = self::request_token();
		return '' !== $token && isset( self::live_leases()[ self::lease_hash( $token ) ] );
	}

	private static function load_order_ok(): bool {
		$file            = plugin_basename( NOVAMIRA_MAINWP_FILE );
		$plugins         = get_option( 'active_plugins', array() );
		$plugins         = is_array( $plugins ) ? $plugins : array();
		$network_plugins = is_multisite() ? get_site_option( 'active_sitewide_plugins', array() ) : array();
		$network_plugins = is_array( $network_plugins ) ? array_keys( $network_plugins ) : array();
		$companion_site  = array_search( $file, $plugins, true );
		$novamira_site   = array_search( 'novamira/novamira.php', $plugins, true );
		$companion_net   = array_search( $file, $network_plugins, true );
		$novamira_net    = array_search( 'novamira/novamira.php', $network_plugins, true );

		if ( false !== $companion_net ) {
			return false === $novamira_net || (int) $companion_net < (int) $novamira_net;
		}
		if ( false !== $novamira_net ) {
			return false;
		}
		if ( false !== $companion_site ) {
			return false === $novamira_site || (int) $companion_site < (int) $novamira_site;
		}
		return true;
	}

	/** @param array<string, mixed> $params @return array<string, mixed>|\WP_Error */
	private static function manage_pro_license( array $params ) {
		if ( ! defined( 'NOVAMIRA_PRO_VERSION' ) ) {
			return new \WP_Error( 'novamira_mainwp_pro_unavailable', 'Novamira Pro is not installed and active.' );
		}
		$operation = isset( $params['operation'] ) && is_scalar( $params['operation'] ) ? sanitize_key( (string) $params['operation'] ) : '';
		if ( 'activate' === $operation ) {
			$key = isset( $params['license_key'] ) && is_scalar( $params['license_key'] ) ? trim( (string) $params['license_key'] ) : '';
			if ( '' === $key || ! function_exists( 'Novamira\\Pro\\activate_new_license_key' ) ) {
				return new \WP_Error( 'novamira_mainwp_invalid_license', 'A license key is required.' );
			}
			list( $success, $message ) = \Novamira\Pro\activate_new_license_key( $key );
		} elseif ( 'deactivate' === $operation && function_exists( 'Novamira\\Pro\\deactivate_license' ) ) {
			list( $success, $message ) = \Novamira\Pro\deactivate_license();
		} elseif ( 'refresh' === $operation && function_exists( 'Novamira\\Pro\\refresh_and_repair_license_status' ) ) {
			\Novamira\Pro\refresh_and_repair_license_status();
			$success = \Novamira\Pro\is_license_active();
			$message = \Novamira\Pro\license_error();
		} else {
			return new \WP_Error( 'novamira_mainwp_invalid_pro_operation', 'Unsupported Novamira Pro license operation.' );
		}
		return array(
			'success' => (bool) $success,
			'message' => (string) $message,
			'active'  => function_exists( 'Novamira\\Pro\\is_license_active' ) && \Novamira\Pro\is_license_active(),
			'masked'  => function_exists( 'Novamira\\Pro\\license_key_masked' ) ? \Novamira\Pro\license_key_masked() : '',
		);
	}
}
