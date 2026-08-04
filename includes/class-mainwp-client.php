<?php
/**
 * MainWP Dashboard and signed child-site operations.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class MainWP_Client {
	public static function ready(): bool {
		return class_exists( '\MainWP\Dashboard\MainWP_DB' ) && has_filter( 'mainwp_fetchurlauthed' );
	}

	/** @return object|\WP_Error */
	public static function site( int $site_id ) {
		if ( ! self::ready() ) {
			return new \WP_Error( 'novamira_mainwp_dashboard_unavailable', 'MainWP Dashboard is unavailable.' );
		}
		$site = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id );
		if ( ! is_object( $site ) || empty( $site->id ) ) {
			return new \WP_Error( 'novamira_mainwp_site_not_found', 'The MainWP site was not found.', array( 'status' => 404 ) );
		}
		if ( ! \MainWP\Dashboard\MainWP_System_Utility::can_edit_website( $site ) ) {
			return new \WP_Error( 'novamira_mainwp_site_forbidden', 'You cannot manage this MainWP site.', array( 'status' => 403 ) );
		}
		if ( ! empty( $site->suspended ) ) {
			return new \WP_Error( 'novamira_mainwp_site_suspended', 'The MainWP site is suspended.', array( 'status' => 409 ) );
		}
		return $site;
	}

	/** @return array<int, object> */
	public static function sites( int $page = 1, int $per_page = 50, string $search = '' ): array {
		if ( ! self::ready() ) {
			return array();
		}
		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$rows     = \MainWP\Dashboard\MainWP_DB::instance()->get_websites_for_current_user(
			array(
				'search_site' => $search,
				'offset'      => ( $page - 1 ) * $per_page,
				'rowcount'    => $per_page,
				'full_data'   => true,
			)
		);
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_object' ) ) : array();
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function child_call( int $site_id, string $action, array $params = array() ) {
		$site = self::site( $site_id );
		if ( is_wp_error( $site ) ) {
			return $site;
		}

		$response = self::fetch(
			$site_id,
			'extra_execution',
			array(
				'novamira_mainwp_contract' => 'v1',
				'novamira_mainwp_action'   => $action,
				'novamira_mainwp_params'   => $params,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! isset( $response['novamira_mainwp'] ) || ! is_array( $response['novamira_mainwp'] ) ) {
			return new \WP_Error( 'novamira_mainwp_child_contract_missing', 'The child site does not expose the Novamira MainWP v1 contract.' );
		}
		$contract = $response['novamira_mainwp'];
		if ( true !== ( $contract['ok'] ?? false ) ) {
			$error   = isset( $contract['error'] ) && is_array( $contract['error'] ) ? $contract['error'] : array();
			$code    = isset( $error['code'] ) ? sanitize_key( (string) $error['code'] ) : 'novamira_mainwp_child_error';
			$message = isset( $error['message'] ) ? (string) $error['message'] : 'The Novamira child action failed.';
			return new \WP_Error( $code, $message, $error['data'] ?? null );
		}
		return isset( $contract['data'] ) && is_array( $contract['data'] ) ? $contract['data'] : array();
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function status( int $site_id ) {
		$stored = Storage::get_site( $site_id );
		return self::child_call(
			$site_id,
			'status',
			array(
				'username'        => (string) $stored['credential_username'],
				'credential_uuid' => (string) $stored['credential_uuid'],
			)
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function install_plugin( int $site_id, string $package_url, bool $activate = true, bool $overwrite = false ) {
		$host        = (string) wp_parse_url( $package_url, PHP_URL_HOST );
		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$local_http  = 1 === preg_match( '#^http://#i', $package_url )
			&& ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) || '.local' === substr( strtolower( $host ), -6 ) )
			&& in_array( $environment, array( 'local', 'development' ), true );
		if ( 1 !== preg_match( '#^https://#i', $package_url ) && ! $local_http ) {
			return new \WP_Error( 'novamira_mainwp_invalid_package_url', 'Plugin packages must use HTTPS outside a local environment.' );
		}
		$params = array(
			'type'           => 'plugin',
			'url'            => wp_json_encode( $package_url ),
			'activatePlugin' => $activate ? 'yes' : 'no',
		);
		if ( $overwrite ) {
			$params['overwrite'] = true;
		}
		return self::fetch( $site_id, 'installplugintheme', $params );
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function activate_plugin( int $site_id, string $plugin_file ) {
		return self::fetch(
			$site_id,
			'plugin_action',
			array(
				'action' => 'activate',
				'plugin' => $plugin_file,
			)
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function update_plugin( int $site_id, string $plugin_file ) {
		return self::fetch(
			$site_id,
			'upgradeplugintheme',
			array(
				'type' => 'plugin',
				'list' => $plugin_file,
			)
		);
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function fetch( int $site_id, string $what, array $params ) {
		$key      = md5( NOVAMIRA_MAINWP_FILE . '-SNNonceAdder' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_md5
		$response = apply_filters( 'mainwp_fetchurlauthed', NOVAMIRA_MAINWP_FILE, $key, $site_id, $what, $params, null );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! is_array( $response ) ) {
			return new \WP_Error( 'novamira_mainwp_child_invalid_response', 'MainWP returned an invalid child-site response.' );
		}
		if ( isset( $response['error'] ) ) {
			return new \WP_Error(
				isset( $response['errorCode'] ) ? sanitize_key( (string) $response['errorCode'] ) : 'novamira_mainwp_child_request_failed',
				(string) $response['error'],
				$response
			);
		}
		return $response;
	}
}
