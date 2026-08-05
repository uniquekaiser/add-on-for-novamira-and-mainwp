<?php
/**
 * Novamira Pro package selection and Dashboard-copy packaging.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class Pro_Package {
	private const PLUGIN_FILE = 'novamira-pro/novamira-pro.php';
	private const MAX_BYTES   = 209715200;

	/** @return array<string,mixed>|\WP_Error */
	public static function active() {
		if ( 'upload' === Storage::pro_package_source() ) {
			$packages = Storage::packages();
			if ( empty( $packages['pro']['path'] ) || ! is_file( (string) $packages['pro']['path'] ) ) {
				return new \WP_Error( 'novamira_mainwp_pro_package_missing', 'Upload an audited Novamira Pro ZIP or switch to the copy installed on this MainWP Dashboard.' );
			}
			return self::with_download_url( $packages['pro'] );
		}
		return self::sync_dashboard();
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function dashboard_status() {
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			return new \WP_Error( 'novamira_mainwp_plugin_directory_missing', 'The WordPress plugin directory is unavailable.' );
		}
		$main_file = WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE;
		$directory = dirname( $main_file );
		$real_root = realpath( WP_PLUGIN_DIR );
		$real_dir  = realpath( $directory );
		if ( ! is_string( $real_root ) || ! is_string( $real_dir ) || ! is_file( $main_file ) ) {
			return new \WP_Error( 'novamira_mainwp_dashboard_pro_missing', 'Novamira Pro is not installed on this MainWP Dashboard.' );
		}
		$root = trailingslashit( wp_normalize_path( $real_root ) );
		$dir  = trailingslashit( wp_normalize_path( $real_dir ) );
		if ( 0 !== strpos( $dir, $root ) ) {
			return new \WP_Error( 'novamira_mainwp_dashboard_pro_unsafe', 'The installed Novamira Pro directory could not be validated.' );
		}
		$header  = file_get_contents( $main_file, false, null, 0, 16384 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local validated plugin header.
		$header  = is_string( $header ) ? $header : '';
		$name    = preg_match( '/^[ \t\/*#@]*Plugin Name:\s*([^\r\n]+)/mi', $header, $name_matches ) ? trim( $name_matches[1] ) : '';
		$version = preg_match( '/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi', $header, $version_matches ) ? trim( $version_matches[1] ) : '';
		if ( 0 !== strcasecmp( $name, 'Novamira Pro' ) || 1 !== preg_match( '/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $version ) ) {
			return new \WP_Error( 'novamira_mainwp_dashboard_pro_header_invalid', 'The installed Novamira Pro copy has unexpected plugin headers.' );
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return array(
			'installed'    => true,
			'active'       => is_plugin_active( self::PLUGIN_FILE ),
			'version'      => $version,
			'directory'    => untrailingslashit( $dir ),
			'source_mtime' => (int) filemtime( $main_file ),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function sync_dashboard( bool $force = false ) {
		$status = self::dashboard_status();
		if ( is_wp_error( $status ) ) {
			return $status;
		}
		$packages = Storage::packages();
		$current  = isset( $packages['pro'] ) && is_array( $packages['pro'] ) ? $packages['pro'] : array();
		if (
			! $force
			&& 'dashboard' === ( $current['source'] ?? '' )
			&& (string) ( $current['version'] ?? '' ) === (string) $status['version']
			&& (int) ( $current['source_mtime'] ?? 0 ) === (int) $status['source_mtime']
			&& ! empty( $current['path'] )
			&& is_file( (string) $current['path'] )
			&& hash_equals( (string) ( $current['sha256'] ?? '' ), (string) hash_file( 'sha256', (string) $current['path'] ) )
		) {
			return self::with_download_url( $current );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'novamira_mainwp_zip_unavailable', 'ZipArchive is required to package the installed Novamira Pro copy.' );
		}
		$mainwp_dir = \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_dir();
		$target_dir = trailingslashit( $mainwp_dir[0] ) . 'bulk/novamira-mainwp/';
		if ( ! wp_mkdir_p( $target_dir ) ) {
			return new \WP_Error( 'novamira_mainwp_package_directory_failed', 'Could not create the private MainWP package directory.' );
		}
		$target    = $target_dir . 'novamira-pro-dashboard-' . sanitize_file_name( (string) $status['version'] ) . '.zip';
		$temporary = $target . '.tmp-' . wp_generate_uuid4();
		$zip       = new \ZipArchive();
		if ( true !== $zip->open( $temporary, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return new \WP_Error( 'novamira_mainwp_package_create_failed', 'Could not create the Novamira Pro package.' );
		}
		$zip->addEmptyDir( 'novamira-pro' );
		$bytes    = 0;
		$main     = false;
		$excluded = array( '.git', '.github', '.idea', '.vscode', 'node_modules', 'tests' );
		$filter   = new \RecursiveCallbackFilterIterator(
			new \RecursiveDirectoryIterator( (string) $status['directory'], \FilesystemIterator::SKIP_DOTS ),
			static function ( \SplFileInfo $entry ) use ( $excluded ): bool {
				return ! $entry->isLink() && ! ( $entry->isDir() && in_array( $entry->getFilename(), $excluded, true ) );
			}
		);
		$iterator = new \RecursiveIteratorIterator( $filter, \RecursiveIteratorIterator::LEAVES_ONLY );
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || ! $file->isFile() || $file->isLink() ) {
				continue;
			}
			$relative = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( (string) $status['directory'] ) ) ), '/' );
			if ( '' === $relative || false !== strpos( $relative, '../' ) || in_array( $file->getFilename(), array( '.env', '.DS_Store' ), true ) ) {
				continue;
			}
			$bytes += (int) $file->getSize();
			if ( $bytes > self::MAX_BYTES || ! $zip->addFile( $file->getPathname(), 'novamira-pro/' . $relative ) ) {
				$zip->close();
				wp_delete_file( $temporary );
				return new \WP_Error( 'novamira_mainwp_package_build_failed', 'The installed Novamira Pro copy is too large or could not be packaged safely.' );
			}
			$main = $main || 'novamira-pro.php' === $relative;
		}
		if ( ! $main || ! $zip->close() ) {
			$zip->close();
			wp_delete_file( $temporary );
			return new \WP_Error( 'novamira_mainwp_package_main_missing', 'The installed Novamira Pro main plugin file could not be packaged.' );
		}
		$inspection = self::inspect_zip( $temporary );
		if ( is_wp_error( $inspection ) ) {
			wp_delete_file( $temporary );
			return $inspection;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			wp_delete_file( $temporary );
			return new \WP_Error( 'novamira_mainwp_filesystem_unavailable', 'WordPress could not initialize its filesystem for the Novamira Pro package.' );
		}
		global $wp_filesystem;
		if ( ! is_object( $wp_filesystem ) || ! $wp_filesystem->move( $temporary, $target, true ) ) {
			wp_delete_file( $temporary );
			return new \WP_Error( 'novamira_mainwp_package_move_failed', 'Could not store the packaged Novamira Pro copy.' );
		}
		$packages['pro'] = array(
			'path'         => $target,
			'version'      => $inspection['version'],
			'sha256'       => hash_file( 'sha256', $target ),
			'stored_at'    => current_time( 'mysql', true ),
			'source'       => 'dashboard',
			'source_mtime' => $status['source_mtime'],
		);
		Storage::save_packages( $packages );
		return self::with_download_url( $packages['pro'] );
	}

	/** @return array{version:string}|\WP_Error */
	public static function inspect_zip( string $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'novamira_mainwp_zip_unavailable', 'ZipArchive is required to inspect plugin packages.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new \WP_Error( 'novamira_mainwp_zip_invalid', 'The uploaded file is not a readable ZIP.' );
		}
		if ( $zip->numFiles > 5000 ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$zip->close();
			return new \WP_Error( 'novamira_mainwp_zip_too_many_files', 'The plugin ZIP contains too many files.' );
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
			if ( $uncompressed_size > self::MAX_BYTES || $is_symlink || false !== strpos( $name, "\0" ) || false !== strpos( $name, '\\' ) || false !== strpos( $name, '../' ) || 0 === strpos( $name, '/' ) || 0 !== strpos( $name, 'novamira-pro/' ) ) {
				$zip->close();
				return new \WP_Error( 'novamira_mainwp_zip_unsafe', 'The plugin ZIP contains an unsafe path or invalid root directory.' );
			}
			if ( 'novamira-pro/novamira-pro.php' === $name ) {
				$main = (string) $zip->getFromIndex( $index );
			}
		}
		$zip->close();
		$plugin_name = preg_match( '/^[ \t\/*#@]*Plugin Name:\s*([^\r\n]+)/mi', $main, $name_matches ) ? trim( $name_matches[1] ) : '';
		$version     = preg_match( '/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi', $main, $version_matches ) ? trim( $version_matches[1] ) : '';
		if ( '' === $main || 0 !== strcasecmp( $plugin_name, 'Novamira Pro' ) || 1 !== preg_match( '/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $version ) ) {
			return new \WP_Error( 'novamira_mainwp_zip_header_missing', 'The ZIP does not contain the expected plugin root, main file, name, and version header.' );
		}
		return array( 'version' => $version );
	}

	/** @param array<string,mixed> $package @return array<string,mixed> */
	private static function with_download_url( array $package ): array {
		$mainwp_dir              = \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_dir();
		$path                    = (string) $package['path'];
		$relative                = ltrim( str_replace( wp_normalize_path( $mainwp_dir[0] ), '', wp_normalize_path( $path ) ), '/' );
		$package['download_url'] = admin_url( '?sig=' . \MainWP\Dashboard\MainWP_System_Utility::get_download_sig( $path ) . '&mwpdl=' . rawurlencode( $relative ) );
		return $package;
	}
}
