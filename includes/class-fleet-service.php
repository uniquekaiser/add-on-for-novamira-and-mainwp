<?php
/**
 * Fleet orchestration shared by UI and abilities.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class Fleet_Service {
	private const FREE_INFO_URL    = 'https://license.dynamic.ooo/api/novamira/info';
	private const MIN_FREE_VERSION = '1.11.1';
	private const FREE_PLUGIN      = 'novamira/novamira.php';
	private const PRO_PLUGIN       = 'novamira-pro/novamira-pro.php';

	/** @return array<string, mixed> */
	public static function list_sites( int $page = 1, int $per_page = 50, string $search = '' ): array {
		$items = array();
		foreach ( MainWP_Client::sites( $page, $per_page, $search ) as $site ) {
			$stored  = Storage::get_site( (int) $site->id );
			$status  = is_array( $stored['status_cache'] ) ? $stored['status_cache'] : array();
			$items[] = self::format_site( $site, $stored, $status );
		}
		return array(
			'items'    => $items,
			'page'     => $page,
			'per_page' => $per_page,
			'count'    => count( $items ),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function free_package() {
		return self::free_release();
	}
	/** @return array<string, mixed>|\WP_Error */
	public static function get_site( int $site_id, bool $refresh = false ) {
		$site = MainWP_Client::site( $site_id );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		if ( $refresh ) {
			$refreshed = self::refresh_status( $site_id );
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
		}
		$stored = Storage::get_site( $site_id );
		return self::format_site( $site, $stored, (array) $stored['status_cache'] );
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function refresh_status( int $site_id ) {
		$started = microtime( true );
		$status  = MainWP_Client::status( $site_id );
		if ( is_wp_error( $status ) ) {
			Audit::record( $site_id, 'status', 'error', self::duration( $started ) );
			return $status;
		}
		Storage::update_site(
			$site_id,
			array(
				'status_cache'      => $status,
				'status_checked_at' => current_time( 'mysql', true ),
				'last_success'      => current_time( 'mysql', true ),
			)
		);
		Audit::record( $site_id, 'status', 'success', self::duration( $started ) );
		return $status;
	}

	/**
	 * @param array<string, mixed> $policy
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function set_policy( int $site_id, array $policy ) {
		$clean  = array(
			'gateway_enabled'     => true === ( $policy['gateway_enabled'] ?? false ),
			'production_allowed'  => true === ( $policy['production_allowed'] ?? false ),
			'ai_lifecycle'        => in_array( $policy['ai_lifecycle'] ?? '', array( 'just-in-time', 'manual-only', 'disabled' ), true ) ? (string) $policy['ai_lifecycle'] : 'just-in-time',
			'fanout_read_allowed' => true === ( $policy['fanout_read_allowed'] ?? false ),
			'allowed_abilities'   => self::ability_names( $policy['allowed_abilities'] ?? array() ),
			'disabled_abilities'  => self::ability_names( $policy['disabled_abilities'] ?? array() ),
		);
		$remote = MainWP_Client::child_operation(
			$site_id,
			'set-rules',
			array(
				'disabled_abilities' => $clean['disabled_abilities'],
			)
		);
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}
		Storage::update_site( $site_id, array( 'policy' => $clean ) );
		Audit::record( $site_id, 'set-policy', 'success', 0, $clean );
		return array(
			'site_id' => $site_id,
			'policy'  => $clean,
			'child'   => $remote,
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function rotate_credential( int $site_id, bool $include_one_time_secret = false ) {
		$site = MainWP_Client::site( $site_id );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		$stored = Storage::get_site( $site_id );
		$host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$result = MainWP_Client::child_operation(
			$site_id,
			'credential-create',
			array(
				'username' => isset( $site->adminname ) ? (string) $site->adminname : '',
				'label'    => 'Novamira MainWP — ' . $host,
			)
		);
		if ( is_wp_error( $result ) ) {
			Audit::record( $site_id, 'rotate-credential', 'error', 0 );
			return $result;
		}
		$password  = isset( $result['password'] ) ? (string) $result['password'] : '';
		$encrypted = Crypto::encrypt( $password );
		if ( is_wp_error( $encrypted ) ) {
			MainWP_Client::child_operation(
				$site_id,
				'credential-revoke',
				array(
					'username' => (string) $result['username'],
					'uuid'     => (string) $result['uuid'],
				)
			);
			return $encrypted;
		}
		$old_secret   = (string) $stored['credential_secret'];
		$old_username = (string) $stored['credential_username'];
		$old_uuid     = (string) $stored['credential_uuid'];
		Storage::update_site(
			$site_id,
			array(
				'credential_username' => (string) $result['username'],
				'credential_secret'   => $encrypted,
				'credential_uuid'     => (string) $result['uuid'],
			)
		);
		if ( '' !== $old_secret ) {
			Crypto::delete_key( $old_secret );
		}
		$warning = '';
		if ( '' !== $old_uuid ) {
			$revoked = MainWP_Client::child_operation(
				$site_id,
				'credential-revoke',
				array(
					'username' => $old_username,
					'uuid'     => $old_uuid,
				)
			);
			if ( is_wp_error( $revoked ) ) {
				$warning = 'The new credential is active, but the previous application password could not be revoked automatically.';
				Audit::record( $site_id, 'rotate-credential-old-revoke', 'error', 0 );
			}
		}
		Audit::record( $site_id, 'rotate-credential', 'success', 0 );

		$output = array(
			'site_id'  => $site_id,
			'username' => (string) $result['username'],
			'uuid'     => (string) $result['uuid'],
			'created'  => (int) $result['created'],
			'one_time' => $include_one_time_secret,
		);
		if ( '' !== $warning ) {
			$output['warning'] = $warning;
		}
		if ( $include_one_time_secret ) {
			$output['password'] = $password;
		}
		return $output;
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function revoke_credential( int $site_id ) {
		$stored = Storage::get_site( $site_id );
		if ( '' === (string) $stored['credential_uuid'] ) {
			return array(
				'site_id' => $site_id,
				'revoked' => false,
				'message' => 'No managed credential exists.',
			);
		}
		$result = MainWP_Client::child_operation(
			$site_id,
			'credential-revoke',
			array(
				'username' => (string) $stored['credential_username'],
				'uuid'     => (string) $stored['credential_uuid'],
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		Crypto::delete_key( (string) $stored['credential_secret'] );
		Storage::update_site(
			$site_id,
			array(
				'credential_username' => '',
				'credential_secret'   => '',
				'credential_uuid'     => '',
			)
		);
		Audit::record( $site_id, 'revoke-credential', 'success', 0 );
		return array(
			'site_id' => $site_id,
			'revoked' => true,
		);
	}

	/**
	 * @param array<int, int> $site_ids
	 * @return array<string, mixed>
	 */
	public static function provision( array $site_ids, string $operation, bool $dry_run ): array {
		$allowed = array( 'refresh-status', 'repair-free', 'install-free', 'activate-free', 'update-free', 'enable-ai', 'disable-ai', 'install-pro', 'install-activate-pro', 'activate-pro', 'update-pro' );
		if ( ! in_array( $operation, $allowed, true ) ) {
			return array( 'error' => 'Unsupported provisioning operation.' );
		}
		$site_ids = array_slice( array_values( array_unique( array_map( 'intval', $site_ids ) ) ), 0, 100 );
		if ( $dry_run ) {
			$preview = array();
			foreach ( $site_ids as $site_id ) {
				$site      = MainWP_Client::site( $site_id );
				$preview[] = is_wp_error( $site )
					? array(
						'site_id' => $site_id,
						'ok'      => false,
						'error'   => $site->get_error_message(),
					)
					: array(
						'site_id'   => $site_id,
						'ok'        => true,
						'site'      => (string) $site->name,
						'operation' => $operation,
					);
			}
			return array(
				'dry_run'   => true,
				'operation' => $operation,
				'site_ids'  => $site_ids,
				'count'     => count( $site_ids ),
				'results'   => $preview,
			);
		}

		$results = array();
		foreach ( $site_ids as $site_id ) {
			$started = microtime( true );
			$result  = 'refresh-status' === $operation ? self::refresh_status( $site_id ) : self::provision_one( $site_id, $operation );
			if ( ! is_wp_error( $result ) && 'refresh-status' !== $operation ) {
				$status = self::refresh_status( $site_id );
				$result = array(
					'operation' => $result,
					'status'    => is_wp_error( $status ) ? null : $status,
					'warning'   => is_wp_error( $status ) ? $status->get_error_message() : '',
				);
			}
			$results[] = is_wp_error( $result )
				? array(
					'site_id' => $site_id,
					'ok'      => false,
					'error'   => $result->get_error_message(),
				)
				: array(
					'site_id' => $site_id,
					'ok'      => true,
					'result'  => $result,
				);
			Audit::record( $site_id, 'provision-' . $operation, is_wp_error( $result ) ? 'error' : 'success', self::duration( $started ) );
		}
		return array(
			'operation' => $operation,
			'results'   => $results,
		);
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function list_components( int $site_id, string $type, string $search = '', string $cursor = '' ) {
		$policy = Storage::policy( $site_id );
		if ( ! $policy['gateway_enabled'] ) {
			return new \WP_Error( 'novamira_mainwp_gateway_disabled', 'The gateway is disabled for this site.' );
		}
		$started = microtime( true );
		$result  = Remote_MCP_Client::list_components( $site_id, $type, '' !== $cursor ? array( 'cursor' => $cursor ) : array() );
		Audit::record(
			$site_id,
			'mcp-list-' . sanitize_key( $type ),
			is_wp_error( $result ) ? 'error' : 'success',
			self::duration( $started ),
			array(
				'type'   => $type,
				'search' => $search,
				'cursor' => $cursor,
			)
		);
		if ( is_wp_error( $result ) || '' === $search ) {
			return $result;
		}
		$key = $type;
		if ( ! isset( $result[ $key ] ) || ! is_array( $result[ $key ] ) ) {
			return $result;
		}
		$needle         = strtolower( $search );
		$result[ $key ] = array_values(
			array_filter(
				$result[ $key ],
				static function ( $item ) use ( $needle ): bool {
					return is_array( $item ) && false !== strpos( strtolower( (string) ( $item['name'] ?? $item['uri'] ?? '' ) ), $needle );
				}
			)
		);
		return $result;
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function read_resource( int $site_id, string $uri ) {
		$policy = Storage::policy( $site_id );
		if ( ! $policy['gateway_enabled'] ) {
			return new \WP_Error( 'novamira_mainwp_gateway_disabled', 'The gateway is disabled for this site.' );
		}
		$started = microtime( true );
		$result  = Remote_MCP_Client::read_resource( $site_id, $uri );
		Audit::record( $site_id, 'mcp-read-resource', is_wp_error( $result ) ? 'error' : 'success', self::duration( $started ), array( 'uri' => $uri ) );
		return $result;
	}

	/** @param array<string, mixed> $arguments @return array<string, mixed>|\WP_Error */
	public static function get_prompt( int $site_id, string $name, array $arguments = array() ) {
		$policy = Storage::policy( $site_id );
		if ( ! $policy['gateway_enabled'] ) {
			return new \WP_Error( 'novamira_mainwp_gateway_disabled', 'The gateway is disabled for this site.' );
		}
		$started = microtime( true );
		$result  = Remote_MCP_Client::get_prompt( $site_id, $name, $arguments );
		Audit::record(
			$site_id,
			'mcp-get-prompt',
			is_wp_error( $result ) ? 'error' : 'success',
			self::duration( $started ),
			array(
				'name'      => $name,
				'arguments' => $arguments,
			)
		);
		return $result;
	}

	/** @param array<string, mixed> $arguments @return array<string, mixed>|\WP_Error */
	public static function call_tool( int $site_id, string $tool_name, array $arguments, string $mode ) {
		$policy = Storage::policy( $site_id );
		if ( ! $policy['gateway_enabled'] ) {
			return new \WP_Error( 'novamira_mainwp_gateway_disabled', 'The gateway is disabled for this site.' );
		}
		$ability_name = isset( $arguments['ability_name'] ) && is_string( $arguments['ability_name'] ) ? $arguments['ability_name'] : '';
		if ( '' !== $ability_name && ! self::ability_allowed( $ability_name, $policy ) ) {
			return new \WP_Error( 'novamira_mainwp_ability_blocked', 'This Novamira ability is blocked by the site policy.' );
		}
		$started = microtime( true );
		$result  = Remote_MCP_Client::call_tool( $site_id, $tool_name, $arguments, $mode );
		Audit::record( $site_id, 'mcp-' . $mode . '-' . sanitize_key( $tool_name ), is_wp_error( $result ) ? 'error' : 'success', self::duration( $started ), $arguments );
		if ( ! is_wp_error( $result ) ) {
			Storage::update_site( $site_id, array( 'last_success' => current_time( 'mysql', true ) ) );
		}
		return $result;
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function manage_pro_license( int $site_id, string $operation, string $license_key = '' ) {
		if ( 'activate' === $operation ) {
			if ( '' !== $license_key ) {
				$encrypted = Crypto::encrypt( $license_key );
				if ( is_wp_error( $encrypted ) ) {
					return $encrypted;
				}
				$stored = Storage::get_site( $site_id );
				Crypto::delete_key( (string) $stored['pro_license_secret'] );
				Storage::update_site( $site_id, array( 'pro_license_secret' => $encrypted ) );
			} else {
				$stored      = Storage::get_site( $site_id );
				$license_key = Crypto::decrypt( (string) $stored['pro_license_secret'] );
				if ( is_wp_error( $license_key ) || '' === $license_key ) {
					$license_key = Storage::default_pro_license();
				}
				if ( is_wp_error( $license_key ) || '' === $license_key ) {
					return new \WP_Error( 'novamira_mainwp_pro_license_missing', 'No Pro license is configured for this site.' );
				}
			}
		}
		$result = MainWP_Client::child_operation(
			$site_id,
			'pro-license',
			array(
				'operation'   => $operation,
				'license_key' => $license_key,
			)
		);
		Audit::record( $site_id, 'pro-license-' . $operation, is_wp_error( $result ) ? 'error' : 'success', 0, array( 'operation' => $operation ) );
		return $result;
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function provision_one( int $site_id, string $operation ) {
		if ( 'repair-free' === $operation ) {
			$release = self::free_release();
			return is_wp_error( $release ) ? $release : MainWP_Client::install_plugin( $site_id, (string) $release['download_url'], true, true );
		}
		if ( in_array( $operation, array( 'enable-ai', 'disable-ai' ), true ) ) {
			return MainWP_Client::child_operation(
				$site_id,
				'ai-set',
				array( 'enabled' => 'enable-ai' === $operation )
			);
		}
		if ( 'install-free' === $operation ) {
			$release = self::free_release();
			return is_wp_error( $release ) ? $release : MainWP_Client::install_plugin( $site_id, (string) $release['download_url'], true, false );
		}
		if ( 'activate-free' === $operation ) {
			return MainWP_Client::activate_plugin( $site_id, self::FREE_PLUGIN );
		}
		if ( 'update-free' === $operation ) {
			return MainWP_Client::update_plugin( $site_id, self::FREE_PLUGIN );
		}
		if ( 'activate-pro' === $operation ) {
			$activated = MainWP_Client::activate_plugin( $site_id, self::PRO_PLUGIN );
			if ( is_wp_error( $activated ) ) {
				return new \WP_Error( $activated->get_error_code(), 'Pro plugin activation failed: ' . $activated->get_error_message() );
			}
			$licensed = self::manage_pro_license( $site_id, 'activate' );
			if ( is_wp_error( $licensed ) ) {
				return new \WP_Error( $licensed->get_error_code(), 'The Pro plugin is active, but license activation failed: ' . $licensed->get_error_message() );
			}
			return array(
				'plugin'  => $activated,
				'license' => $licensed,
			);
		}
		$package = Pro_Package::active();
		if ( is_wp_error( $package ) ) {
			return $package;
		}
		if ( 'install-activate-pro' === $operation ) {
			$installed = MainWP_Client::install_plugin( $site_id, (string) $package['download_url'], true, false );
			if ( is_wp_error( $installed ) ) {
				return new \WP_Error( $installed->get_error_code(), 'Pro installation or plugin activation failed: ' . $installed->get_error_message() );
			}
			$licensed = self::manage_pro_license( $site_id, 'activate' );
			if ( is_wp_error( $licensed ) ) {
				return new \WP_Error( $licensed->get_error_code(), 'The Pro plugin is installed and active, but license activation failed: ' . $licensed->get_error_message() );
			}
			return array(
				'plugin'  => $installed,
				'license' => $licensed,
			);
		}
		return MainWP_Client::install_plugin( $site_id, (string) $package['download_url'], 'update-pro' === $operation, 'update-pro' === $operation );
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function free_release() {
		$cached = get_transient( 'novamira_mainwp_free_release' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$response = wp_remote_get(
			self::FREE_INFO_URL,
			array(
				'timeout'     => 15,
				'redirection' => 2,
				'sslverify'   => true,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( wp_remote_retrieve_response_code( $response ) !== 200 || ! is_array( $data ) ) {
			return new \WP_Error( 'novamira_mainwp_free_release_invalid', 'The Novamira release service returned an invalid response.' );
		}
		$url     = isset( $data['download_url'] ) && is_string( $data['download_url'] ) ? $data['download_url'] : '';
		$version = isset( $data['version'] ) && is_string( $data['version'] ) ? $data['version'] : '';
		$slug    = isset( $data['slug'] ) && is_string( $data['slug'] ) ? $data['slug'] : '';
		if ( 'novamira' !== $slug || 1 !== preg_match( '#^https://#i', $url ) || 1 !== preg_match( '/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $version ) ) {
			return new \WP_Error( 'novamira_mainwp_free_release_unsafe', 'The Novamira release metadata failed URL or version validation.' );
		}
		if ( version_compare( $version, self::MIN_FREE_VERSION, '<' ) ) {
			return new \WP_Error( 'novamira_mainwp_free_too_old', 'The upstream Novamira Free release is older than the supported baseline.' );
		}
		$inspection = self::inspect_free_package( $url, $version );
		if ( is_wp_error( $inspection ) ) {
			return $inspection;
		}
		$release = array(
			'version'      => $version,
			'download_url' => $url,
			'sha256'       => $inspection['sha256'],
		);
		set_transient( 'novamira_mainwp_free_release', $release, 3600 );
		return $release;
	}

	/** @return array{sha256:string}|\WP_Error */
	private static function inspect_free_package( string $url, string $expected_version ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'novamira_mainwp_zip_unavailable', 'ZipArchive is required to validate Novamira Free packages.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$temporary = wp_tempnam( 'novamira-free.zip' );
		if ( ! is_string( $temporary ) || '' === $temporary ) {
			return new \WP_Error( 'novamira_mainwp_temp_file_failed', 'Could not create a temporary package file.' );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 30,
				'redirection'         => 2,
				'sslverify'           => true,
				'stream'              => true,
				'filename'            => $temporary,
				'limit_response_size' => 52428800,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			wp_delete_file( $temporary );
			return is_wp_error( $response ) ? $response : new \WP_Error( 'novamira_mainwp_free_download_failed', 'The Novamira Free package could not be downloaded safely.' );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $temporary ) ) {
			wp_delete_file( $temporary );
			return new \WP_Error( 'novamira_mainwp_free_zip_invalid', 'The Novamira Free package is not a readable ZIP.' );
		}
		if ( $zip->numFiles > 5000 ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$zip->close();
			wp_delete_file( $temporary );
			return new \WP_Error( 'novamira_mainwp_free_zip_too_many_files', 'The Novamira Free package contains too many files.' );
		}

		$main              = '';
		$uncompressed_size = 0;
		for ( $index = 0; $index < $zip->numFiles; ++$index ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$name               = (string) $zip->getNameIndex( $index );
			$stat               = $zip->statIndex( $index );
			$uncompressed_size += is_array( $stat ) ? (int) ( $stat['size'] ?? 0 ) : 0;
			$attributes         = 0;
			$operations         = 0;
			$is_symlink         = $zip->getExternalAttributesIndex( $index, $operations, $attributes ) && 0120000 === ( ( $attributes >> 16 ) & 0170000 );
			if ( $uncompressed_size > 209715200 || $is_symlink || false !== strpos( $name, "\0" ) || false !== strpos( $name, '\\' ) || false !== strpos( $name, '../' ) || 0 === strpos( $name, '/' ) || 0 !== strpos( $name, 'novamira/' ) ) {
				$zip->close();
				wp_delete_file( $temporary );
				return new \WP_Error( 'novamira_mainwp_free_zip_unsafe', 'The Novamira Free package contains an unsafe path or invalid root directory.' );
			}
			if ( 'novamira/novamira.php' === $name ) {
				$main = (string) $zip->getFromIndex( $index );
			}
		}
		$zip->close();

		$plugin_name = preg_match( '/^[ \t\/*#@]*Plugin Name:\s*([^\r\n]+)/mi', $main, $name_match ) ? trim( $name_match[1] ) : '';
		$version     = preg_match( '/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi', $main, $version_match ) ? trim( $version_match[1] ) : '';
		$hash        = hash_file( 'sha256', $temporary );
		wp_delete_file( $temporary );
		if ( '' === $main || false === stripos( $plugin_name, 'Novamira' ) || $expected_version !== $version || ! is_string( $hash ) ) {
			return new \WP_Error( 'novamira_mainwp_free_zip_header_invalid', 'The Novamira Free package headers or version do not match the HTTPS release metadata.' );
		}
		return array( 'sha256' => $hash );
	}

	/** @param object $site @param array<string,mixed> $stored @param array<string,mixed> $status */
	private static function format_site( $site, array $stored, array $status ): array {
		$free_inventory = self::plugin_inventory_status( $site, self::FREE_PLUGIN );
		$pro_inventory  = self::plugin_inventory_status( $site, self::PRO_PLUGIN );
		$status_known   = ! empty( $stored['status_checked_at'] ) && ! empty( $status );
		$free           = isset( $status['free'] ) && is_array( $status['free'] )
			? array_merge( $free_inventory, $status['free'] )
			: $free_inventory;
		$pro            = isset( $status['pro'] ) && is_array( $status['pro'] )
			? array_merge( $pro_inventory, array( 'license_active' => false ), $status['pro'] )
			: $pro_inventory;

		return array(
			'id'                        => (int) $site->id,
			'name'                      => isset( $site->name ) ? (string) $site->name : '',
			'url'                       => isset( $site->url ) ? (string) $site->url : '',
			'suspended'                 => ! empty( $site->suspended ),
			'sync_error'                => isset( $site->sync_errors ) ? (string) $site->sync_errors : '',
			'credential'                => array(
				'managed'            => '' !== (string) $stored['credential_uuid'],
				'username'           => (string) $stored['credential_username'],
				'healthy'            => $status['application_passwords']['credential_healthy'] ?? null,
				'supported'          => $status['application_passwords']['supported'] ?? null,
				'available_for_user' => $status['application_passwords']['available_for_user'] ?? null,
			),
			'free'                      => $free,
			'pro'                       => $pro,
			'ai'                        => $status_known && isset( $status['ai'] ) && is_array( $status['ai'] ) ? $status['ai'] : array(),
			'available_abilities'       => $status['available_abilities'] ?? array(),
			'available_abilities_known' => $status['available_abilities_known'] ?? false,
			'status_known'              => $status_known,
			'policy'                    => Storage::policy( (int) $site->id ),
			'last_success'              => $stored['last_success'],
			'status_updated_at'         => $stored['status_checked_at'],
		);
	}

	/**
	 * Fall back to MainWP's last synchronized plugin inventory when the child
	 * one-shot status operation is unavailable.
	 *
	 * @param object $site MainWP site row.
	 * @return array<string, mixed>
	 */
	private static function plugin_inventory_status( $site, string $plugin_file ): array {
		$plugins = ! empty( $site->plugins ) ? json_decode( (string) $site->plugins, true ) : array();
		$updates = ! empty( $site->plugin_upgrades ) ? json_decode( (string) $site->plugin_upgrades, true ) : array();
		$plugins = is_array( $plugins ) ? $plugins : array();
		$updates = is_array( $updates ) ? $updates : array();

		$found = null;
		foreach ( $plugins as $key => $plugin ) {
			if ( ! is_array( $plugin ) ) {
				continue;
			}
			$slug = is_string( $key ) && '' !== $key ? $key : (string) ( $plugin['slug'] ?? '' );
			if ( $plugin_file === $slug ) {
				$found = $plugin;
				break;
			}
		}

		$update_version = '';
		if ( isset( $updates[ $plugin_file ] ) && is_array( $updates[ $plugin_file ] ) ) {
			$update         = $updates[ $plugin_file ];
			$update_version = isset( $update['update']['new_version'] )
				? (string) $update['update']['new_version']
				: (string) ( $update['new_version'] ?? '' );
		}

		return array(
			'installed'        => is_array( $found ),
			'active'           => is_array( $found ) && ! empty( $found['active'] ),
			'version'          => is_array( $found ) ? (string) ( $found['version'] ?? '' ) : '',
			'update_available' => '' !== $update_version,
			'update_version'   => $update_version,
			'source'           => 'mainwp-sync',
		);
	}

	/** @param mixed $names @return array<int, string> */
	private static function ability_names( $names ): array {
		if ( ! is_array( $names ) ) {
			return array();
		}
		$clean = array();
		foreach ( $names as $name ) {
			if ( ! is_scalar( $name ) ) {
				continue;
			}
			$name = (string) $name;
			if ( 1 === preg_match( '/^[a-z0-9-]+\/[a-z0-9-\/]+$/', $name ) ) {
				$clean[] = $name;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/** @param array<string, mixed> $policy */
	private static function ability_allowed( string $ability_name, array $policy ): bool {
		$blocked = isset( $policy['disabled_abilities'] ) && is_array( $policy['disabled_abilities'] ) ? $policy['disabled_abilities'] : array();
		$allowed = isset( $policy['allowed_abilities'] ) && is_array( $policy['allowed_abilities'] ) ? $policy['allowed_abilities'] : array();
		if ( in_array( $ability_name, $blocked, true ) ) {
			return false;
		}
		return empty( $allowed ) || in_array( $ability_name, $allowed, true );
	}

	private static function duration( float $started ): int {
		return (int) round( ( microtime( true ) - $started ) * 1000 );
	}
}
