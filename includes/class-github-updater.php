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
		if ( empty( $sections['changelog'] ) ) {
			$sections['changelog'] = '<h4>0.2.1</h4><ul><li><strong>[NEW]</strong> Published the first public release of the Dashboard fleet control plane, child companion, and routed MCP gateway.</li><li><strong>[SECURITY]</strong> Added exact-asset dashboard updates that reject source archives and unrelated ZIP files.</li><li><strong>[FIX]</strong> Made distribution inspection portable across Windows and Linux release runners.</li></ul>';
		}
		$info->sections = $sections;

		return $info;
	}
}
