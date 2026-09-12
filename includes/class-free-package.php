<?php
/**
 * Independently authenticated Free packages staged for MainWP fleet deployment.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class Free_Package {
	private const INFO_URL = 'https://license.dynamic.ooo/api/novamira/info';

	/**
	 * Trust must come from administrator-owned configuration, never from the
	 * release service. Map each approved version to sha256 and download_url.
	 * The highest approved version is selected to prevent metadata rollback.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function release( string $minimum_version ) {
		$trusted = apply_filters( 'novamira_mainwp_trusted_free_releases', array() );
		if ( ! is_array( $trusted ) || empty( $trusted ) ) {
			return new \WP_Error( 'novamira_mainwp_free_trust_missing', 'Configure an independently verified Novamira Free version, SHA-256, and package URL before deployment.' );
		}
		$versions = array_keys( $trusted );
		usort( $versions, 'version_compare' );
		$version = (string) end( $versions );
		$pin     = $trusted[ $version ];
		if ( ! is_array( $pin ) || 1 !== preg_match( '/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/D', $version ) || ! isset( $pin['sha256'], $pin['download_url'] ) || ! is_string( $pin['sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/iD', $pin['sha256'] ) || ! is_string( $pin['download_url'] ) || ! self::valid_package_url( $pin['download_url'] ) ) {
			return new \WP_Error( 'novamira_mainwp_free_trust_invalid', 'The administrator-configured Free package trust record is invalid.' );
		}
		if ( version_compare( $version, $minimum_version, '<' ) ) {
			return new \WP_Error( 'novamira_mainwp_free_too_old', 'The approved Novamira Free version is older than the supported baseline.' );
		}
		$hash       = strtolower( $pin['sha256'] );
		$mainwp_dir = \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_dir();
		$directory  = trailingslashit( $mainwp_dir[0] ) . 'bulk/novamira-mainwp/';
		$path       = $directory . 'novamira-free-' . sanitize_file_name( $version ) . '-' . $hash . '.zip';
		$cache_key  = 'novamira_mainwp_free_' . hash( 'sha256', $version . ':' . $hash );
		if ( true === get_transient( $cache_key ) && self::matches_hash( $path, $hash ) ) {
			return self::package( $path, $version, $hash );
		}
		$response = wp_safe_remote_get(
			self::INFO_URL,
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'sslverify'           => true,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => 65536,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) ) {
			return new \WP_Error( 'novamira_mainwp_free_release_invalid', 'The Novamira release service returned an invalid response or redirect.' );
		}
		// Pin the complete package URL as well as its origin; metadata cannot choose a new destination.
		if ( 'novamira' !== ( $data['slug'] ?? '' ) || ( $data['version'] ?? '' ) !== $version || ( $data['download_url'] ?? '' ) !== $pin['download_url'] ) {
			return new \WP_Error( 'novamira_mainwp_free_release_untrusted', 'The Novamira release metadata does not match the independently approved version and URL.' );
		}
		$inspection = self::inspect_free_package( $pin['download_url'], $version, $hash );
		if ( is_wp_error( $inspection ) ) {
			return $inspection;
		}
		$temporary = $inspection['path'];
		if ( ! wp_mkdir_p( $directory ) || ! WP_Filesystem() ) {
			wp_delete_file( $temporary );
			return new \WP_Error( 'novamira_mainwp_free_package_storage_failed', 'Could not initialize private MainWP package storage.' );
		}
		global $wp_filesystem;
		if ( ! is_object( $wp_filesystem ) || ! $wp_filesystem->move( $temporary, $path, true ) || ! self::matches_hash( $path, $hash ) ) {
			wp_delete_file( $temporary );
			return new \WP_Error( 'novamira_mainwp_free_package_storage_failed', 'Could not store the authenticated Novamira Free package.' );
		}
		set_transient( $cache_key, true, 3600 );
		return self::package( $path, $version, $hash );
	}

	private static function valid_package_url( string $url ): bool {
		return '' !== MCP_Endpoint::origin( $url ) && 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) && null === wp_parse_url( $url, PHP_URL_QUERY ) && null === wp_parse_url( $url, PHP_URL_FRAGMENT );
	}

	private static function matches_hash( string $path, string $hash ): bool {
		return ! is_link( $path ) && is_file( $path ) && hash_equals( $hash, (string) hash_file( 'sha256', $path ) );
	}

	/** @return array<string,mixed> */
	private static function package( string $path, string $version, string $hash ): array {
		$mainwp_dir = \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_dir();
		$relative   = ltrim( substr( wp_normalize_path( $path ), strlen( wp_normalize_path( trailingslashit( $mainwp_dir[0] ) ) ) ), '/' );
		return array(
			'version'      => $version,
			'sha256'       => $hash,
			'path'         => $path,
			'download_url' => admin_url( '?sig=' . \MainWP\Dashboard\MainWP_System_Utility::get_download_sig( $path ) . '&mwpdl=' . rawurlencode( $relative ) ),
		);
	}

	/** @return array{sha256:string,path:string}|\WP_Error */
	private static function inspect_free_package( string $url, string $expected_version, string $expected_hash ) {
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
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
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

		$hash = hash_file( 'sha256', $temporary );
		if ( ! is_string( $hash ) || ! hash_equals( $expected_hash, $hash ) ) {
			wp_delete_file( $temporary );
			return new \WP_Error( 'novamira_mainwp_free_package_unauthenticated', 'The Novamira Free package does not match the independently trusted SHA-256.' );
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
		if ( '' === $main || false === stripos( $plugin_name, 'Novamira' ) || $expected_version !== $version ) {
			wp_delete_file( $temporary );
			return new \WP_Error( 'novamira_mainwp_free_zip_header_invalid', 'The Novamira Free package headers or version do not match the HTTPS release metadata.' );
		}
		return array(
			'sha256' => $hash,
			'path'   => $temporary,
		);
	}
}
