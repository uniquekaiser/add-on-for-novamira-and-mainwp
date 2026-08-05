<?php
/**
 * Plugin Name: Novamira for MainWP
 * Plugin URI:  https://github.com/uniquekaiser/novamira-for-mainwp/
 * Description: Centrally provisions, secures, and routes Novamira MCP servers for MainWP child sites.
 * Version:     0.3.1
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author:      Synergetic
 * License:     GPL-3.0-or-later
 * Text Domain: mainwp-novamira-addon
 * Update URI:  https://github.com/uniquekaiser/novamira-for-mainwp/
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NOVAMIRA_MAINWP_VERSION', '0.3.1' );
define( 'NOVAMIRA_MAINWP_FILE', __FILE__ );
define( 'NOVAMIRA_MAINWP_DIR', plugin_dir_path( __FILE__ ) );

require_once NOVAMIRA_MAINWP_DIR . 'includes/class-github-updater.php';

require_once NOVAMIRA_MAINWP_DIR . 'includes/class-crypto.php';
require_once NOVAMIRA_MAINWP_DIR . 'includes/class-storage.php';
require_once NOVAMIRA_MAINWP_DIR . 'includes/class-audit.php';
require_once NOVAMIRA_MAINWP_DIR . 'includes/class-child-runtime.php';
require_once NOVAMIRA_MAINWP_DIR . 'includes/class-runtime-access.php';
require_once NOVAMIRA_MAINWP_DIR . 'includes/class-mainwp-client.php';
require_once NOVAMIRA_MAINWP_DIR . 'includes/class-remote-mcp-client.php';
require_once NOVAMIRA_MAINWP_DIR . 'includes/class-fleet-service.php';
require_once NOVAMIRA_MAINWP_DIR . 'includes/class-abilities.php';
require_once NOVAMIRA_MAINWP_DIR . 'includes/provider-config-registry.php';
require_once NOVAMIRA_MAINWP_DIR . 'includes/class-admin.php';
require_once NOVAMIRA_MAINWP_DIR . 'includes/class-plugin.php';

register_activation_hook( NOVAMIRA_MAINWP_FILE, array( \Novamira\MainWP\Plugin::class, 'activate' ) );
add_action( 'plugins_loaded', array( \Novamira\MainWP\GitHub_Updater::class, 'boot' ), 5 );
add_action( 'plugins_loaded', array( \Novamira\MainWP\Plugin::class, 'boot' ), 25 );
