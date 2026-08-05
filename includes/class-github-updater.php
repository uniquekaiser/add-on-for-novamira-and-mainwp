<?php
/**
 * Public GitHub Release updater.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

final class GitHub_Updater {
	public const REPOSITORY_URL = 'https://github.com/uniquekaiser/novamira-for-mainwp/';
	public const ASSET_PATTERN  = '/^mainwp-novamira-addon-\d+\.\d+\.\d+\.zip$/i';

	/** @var object|null */
	private static $checker;

	private static bool $unavailable = false;

	public static function boot(): void {
		$bootstrap = NOVAMIRA_MAINWP_DIR . 'vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';
		if ( ! is_readable( $bootstrap ) ) {
			self::mark_unavailable();
			return;
		}

		require_once $bootstrap;
		if ( ! class_exists( PucFactory::class ) ) {
			self::mark_unavailable();
			return;
		}

		$checker = PucFactory::buildUpdateChecker(
			self::REPOSITORY_URL,
			NOVAMIRA_MAINWP_FILE,
			'mainwp-novamira-addon'
		);
		if ( ! is_object( $checker ) || ! method_exists( $checker, 'getVcsApi' ) || ! method_exists( $checker, 'addFilter' ) ) {
			self::mark_unavailable();
			return;
		}

		$api = $checker->getVcsApi();
		if ( ! is_object( $api ) || ! method_exists( $api, 'enableReleaseAssets' ) ) {
			self::mark_unavailable();
			return;
		}

		$checker->setBranch( 'main' );
		$api->enableReleaseAssets( self::ASSET_PATTERN, 2 );
		$checker->addFilter( 'vcs_update_detection_strategies', array( self::class, 'latest_release_only' ) );
		$checker->addFilter( 'request_info_result', array( self::class, 'complete_metadata' ) );
		add_filter( 'site_transient_update_plugins', array( self::class, 'complete_update_transient' ), 100 );
		self::$checker = $checker;
	}

	private static function mark_unavailable(): void {
		self::$unavailable = true;
		add_action( 'admin_notices', array( self::class, 'missing_runtime_notice' ) );
	}

	public static function missing_runtime_notice(): void {
		if ( ! self::$unavailable || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Novamira for MainWP automatic updates are unavailable because the packaged GitHub updater runtime is missing or incompatible. Reinstall an official release ZIP.', 'mainwp-novamira-addon' ) . '</p></div>';
	}

	/** @return object|null */
	public static function get_checker() {
		return self::$checker;
	}

	public static function is_release_asset( string $filename ): bool {
		return 1 === preg_match( self::ASSET_PATTERN, $filename );
	}

	/** @param array<string, mixed> $strategies @return array<string, mixed> */
	public static function latest_release_only( array $strategies ): array {
		return isset( $strategies['latest_release'] )
			? array( 'latest_release' => $strategies['latest_release'] )
			: array();
	}

	/** @param object|null $info @return object|null */
	public static function complete_metadata( $info ) {
		if ( ! is_object( $info ) ) {
			return $info;
		}

		$info->name         = 'Novamira for MainWP';
		$info->slug         = 'mainwp-novamira-addon';
		$info->requires     = '6.9';
		$info->tested       = '7.0';
		$info->requires_php = '7.4';
		$info->homepage     = self::REPOSITORY_URL;

		$icon        = 'https://raw.githubusercontent.com/uniquekaiser/novamira-for-mainwp/main/assets/icon.svg';
		$icons       = isset( $info->icons ) && is_array( $info->icons ) ? $info->icons : array();
		$info->icons = array_merge(
			array(
				'1x'  => $icon,
				'2x'  => $icon,
				'svg' => $icon,
			),
			$icons
		);

		$sections = isset( $info->sections ) && is_array( $info->sections ) ? $info->sections : array();
		if ( empty( $sections['description'] ) ) {
			$sections['description'] = '<p>Manage Novamira across MainWP child sites and route approved child MCP servers through one MainWP MCP connection.</p>';
		}
		$remote_changelog = isset( $sections['changelog'] ) && is_string( $sections['changelog'] )
			? self::normalize_changelog_html( $sections['changelog'] )
			: '';
		$local_changelog  = self::canonical_changelog_html();
		if ( '' !== $remote_changelog ) {
			$sections['changelog'] = $remote_changelog;
		} elseif ( '' !== $local_changelog ) {
			$sections['changelog'] = $local_changelog;
		} elseif ( empty( $sections['changelog'] ) ) {
			$sections['changelog'] = '<h4>0.3.0</h4><ul><li><strong>[IMPROVE]</strong> Completed the WordPress update metadata.</li></ul>';
		}
		$info->sections = $sections;

		return $info;
	}

	/** @param mixed $transient @return mixed */
	public static function complete_update_transient( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$key = 'mainwp-novamira-addon/mainwp-novamira-addon.php';
		foreach ( array( 'response', 'no_update' ) as $bucket ) {
			if ( ! isset( $transient->{$bucket} ) || ! is_array( $transient->{$bucket} ) || ! isset( $transient->{$bucket}[ $key ] ) ) {
				continue;
			}
			$entry = $transient->{$bucket}[ $key ];
			if ( is_object( $entry ) ) {
				$entry->requires     = '6.9';
				$entry->requires_php = '7.4';
				$entry->icons        = self::complete_icons( isset( $entry->icons ) ? (array) $entry->icons : array() );
			} elseif ( is_array( $entry ) ) {
				$entry['requires']     = '6.9';
				$entry['requires_php'] = '7.4';
				$entry['icons']        = self::complete_icons( isset( $entry['icons'] ) ? (array) $entry['icons'] : array() );
			}
			$transient->{$bucket}[ $key ] = $entry;
		}

		return $transient;
	}

	/** @param array<string, string> $icons @return array<string, string> */
	private static function complete_icons( array $icons ): array {
		$icon = 'https://raw.githubusercontent.com/uniquekaiser/novamira-for-mainwp/main/assets/icon.svg';
		return array_merge(
			array(
				'1x'  => $icon,
				'2x'  => $icon,
				'svg' => $icon,
			),
			$icons
		);
	}

	private static function canonical_changelog_html(): string {
		$contents = file_get_contents( NOVAMIRA_MAINWP_DIR . 'CHANGELOG.md' );
		if ( ! is_string( $contents ) || '' === trim( $contents ) ) {
			return '';
		}

		$sections = array();
		$current  = '';
		$lines    = preg_split( '/\r?\n/', $contents );
		if ( ! is_array( $lines ) ) {
			return '';
		}
		foreach ( $lines as $line ) {
			if ( preg_match( '/^##\s+(\d+\.\d+\.\d+)(?:\s+-.*)?$/', $line, $matches ) ) {
				$current              = $matches[1];
				$sections[ $current ] = array();
				continue;
			}
			if ( '' !== $current && preg_match( '/^-\s+\[([A-Z]+)\]\s+(.+)$/', $line, $matches ) ) {
				$sections[ $current ][] = array( $matches[1], $matches[2] );
			}
		}

		$html = '';
		foreach ( $sections as $version => $items ) {
			if ( empty( $items ) ) {
				continue;
			}
			$html .= '<h4>' . htmlspecialchars( $version, ENT_QUOTES, 'UTF-8' ) . '</h4><ul>';
			foreach ( $items as $item ) {
				$html .= '<li><strong>[' . htmlspecialchars( $item[0], ENT_QUOTES, 'UTF-8' ) . ']</strong> ' . htmlspecialchars( $item[1], ENT_QUOTES, 'UTF-8' ) . '</li>';
			}
			$html .= '</ul>';
		}

		return $html;
	}

	private static function normalize_changelog_html( string $html ): string {
		$html = preg_replace( '/<h[1-6][^>]*>/i', '<h4>', $html );
		$html = preg_replace( '/<\/h[1-6]>/i', '</h4>', (string) $html );
		$html = preg_replace_callback(
			'/<li[^>]*>\s*(?:<p[^>]*>\s*)?\[([A-Z]+)\]\s*(.*?)(?:\s*<\/p>)?\s*<\/li>/is',
			static function ( array $matches ): string {
				return '<li><strong>[' . htmlspecialchars( $matches[1], ENT_QUOTES, 'UTF-8' ) . ']</strong> ' . trim( $matches[2] ) . '</li>';
			},
			(string) $html
		);
		if ( function_exists( 'wp_kses' ) ) {
			$html = wp_kses(
				(string) $html,
				array(
					'h4'     => array(),
					'ul'     => array(),
					'li'     => array(),
					'strong' => array(),
				)
			);
		} else {
			$html = strip_tags( (string) $html, '<h4><ul><li><strong>' );
		}

		return trim( (string) $html );
	}
}
