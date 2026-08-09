<?php
/**
 * Add-on bootstrap and MainWP registration.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class Plugin {
	public static function activate(): void {
		if ( MainWP_Client::ready() ) {
			Storage::install();
		}
	}

	public static function boot(): void {
		if ( ! MainWP_Client::ready() ) {
			add_action( 'admin_notices', array( self::class, 'dependency_notice' ) );
			return;
		}
		if ( get_option( 'novamira_mainwp_db_version' ) !== NOVAMIRA_MAINWP_VERSION ) {
			Storage::install();
		}

		add_filter( 'mainwp_getextensions', array( self::class, 'register_extension' ) );
		add_filter( 'mainwp_getsubpages_sites', array( self::class, 'register_site_subpage' ) );
		add_filter( 'mainwp_sync_extensions_options', array( Onboarding::class, 'sync_options' ) );
		add_filter( 'plugins_api', array( Onboarding::class, 'plugin_information' ), 100, 3 );
		add_filter( 'mainwp_prepare_install_download_url', array( Onboarding::class, 'prepare_download_url' ), 100, 2 );
		add_action( 'mainwp_applypluginsettings_mainwp-novamira-addon', array( Onboarding::class, 'apply_defaults' ) );
		add_filter( 'mainwp_mcp_bridge_ability_namespaces', array( self::class, 'ability_namespaces' ) );
		add_action( 'wp_abilities_api_categories_init', array( Abilities::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( Abilities::class, 'register' ) );
		add_action( 'admin_notices', array( self::class, 'dependency_notice' ) );
		add_action( 'admin_enqueue_scripts', array( Admin::class, 'enqueue_assets' ) );
		add_action( 'wp_ajax_novamira_mainwp_bulk_site', array( Admin::class, 'ajax_bulk_site' ) );
		add_action( 'admin_post_novamira_mainwp_export_configs', array( Admin::class, 'download_fleet_configs' ) );
		add_action( 'novamira_mainwp_runtime_cleanup', array( Runtime_Access::class, 'cleanup' ), 10, 2 );
		Runtime_Access::recover_expired();
	}

	/** @param array<int, array<string, mixed>> $extensions @return array<int, array<string, mixed>> */
	public static function register_extension( array $extensions ): array {
		$extensions[] = array(
			'plugin'           => NOVAMIRA_MAINWP_FILE,
			'name'             => __( 'Add-on for Novamira and MainWP', 'mainwp-novamira-addon' ),
			'callback'         => array( Admin::class, 'render' ),
			'on_load_callback' => array( Admin::class, 'on_load' ),
		);
		return $extensions;
	}

	/** @param array<int, array<string, mixed>> $subpages @return array<int, array<string, mixed>> */
	public static function register_site_subpage( array $subpages ): array {
		$subpages[] = array(
			'title'       => __( 'Novamira', 'mainwp-novamira-addon' ),
			'slug'        => 'NovamiraMainWP',
			'sitetab'     => true,
			'menu_hidden' => true,
			'callback'    => array( Admin::class, 'render_site' ),
		);
		return $subpages;
	}

	/** @param array<int, string> $namespaces @return array<int, string> */
	public static function ability_namespaces( array $namespaces ): array {
		$namespaces[] = 'novamira-mainwp';
		return array_values( array_unique( $namespaces ) );
	}

	public static function dependency_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || MainWP_Client::ready() ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Add-on for Novamira and MainWP is inactive:', 'mainwp-novamira-addon' ) . '</strong> ' . esc_html__( 'MainWP Dashboard 6.0 or newer is required.', 'mainwp-novamira-addon' ) . '</p></div>';
	}
}
