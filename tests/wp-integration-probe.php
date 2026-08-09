<?php
/**
 * WP-CLI probe for a disposable MainWP Dashboard installation.
 *
 * @package NovamiraMainWP
 */

global $wpdb;

$table_prefix = $wpdb->prefix . 'novamira_mainwp_';
$tables       = $wpdb->get_col(
	$wpdb->prepare(
		'SHOW TABLES LIKE %s',
		$wpdb->esc_like( $table_prefix ) . '%'
	)
);
$settings     = \MainWP\MCP\Bridge\Settings::get();
$abilities    = array_values(
	array_filter(
		array_keys( wp_get_abilities() ),
		static function ( string $name ): bool {
			return 0 === strpos( $name, 'novamira-mainwp/' );
		}
	)
);
$extensions   = apply_filters( 'mainwp_getextensions', array() );
$registered   = false;
foreach ( $extensions as $extension ) {
	if ( isset( $extension['plugin'] ) && false !== strpos( (string) $extension['plugin'], 'mainwp-novamira-addon.php' ) ) {
		$registered = true;
		break;
	}
}
$encrypted        = \Novamira\MainWP\Crypto::encrypt( 'local-integration-probe' );
$crypto_roundtrip = ! is_wp_error( $encrypted )
	&& 'local-integration-probe' === \Novamira\MainWP\Crypto::decrypt( $encrypted );
if ( is_string( $encrypted ) ) {
	\Novamira\MainWP\Crypto::delete_key( $encrypted );
}
$sync_options          = apply_filters( 'mainwp_sync_extensions_options', array() );
$onboarding_registered = isset( $sync_options['mainwp-novamira-addon'] )
	&& 'novamira/novamira.php' === ( $sync_options['mainwp-novamira-addon']['plugin_slug'] ?? '' )
	&& ! empty( $sync_options['mainwp-novamira-addon']['action_after_install'] );
$updater_checker = \Novamira\MainWP\GitHub_Updater::get_checker();

$result = array(
	'db_version'                   => get_option( 'novamira_mainwp_db_version' ),
	'tables'                       => $tables,
	'namespaces'                   => $settings['ability_namespaces'],
	'novamira_fleet_ability_count' => count( $abilities ),
	'novamira_fleet_abilities'     => $abilities,
	'extension_registered'         => $registered,
	'onboarding_registered'        => $onboarding_registered,
	'crypto_roundtrip'             => $crypto_roundtrip,
	'github_updater_initialized'   => is_object( $updater_checker ),
	'github_updater_class'         => is_object( $updater_checker ) ? get_class( $updater_checker ) : '',
);

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI diagnostic output.

$expected_tables = array(
	$wpdb->prefix . 'novamira_mainwp_audit',
	$wpdb->prefix . 'novamira_mainwp_sites',
);
sort( $expected_tables );
sort( $tables );
if (
	'0.6.0' !== $result['db_version']
	|| $expected_tables !== $tables
	|| ! in_array( 'novamira-mainwp', $result['namespaces'], true )
	|| 13 !== $result['novamira_fleet_ability_count']
	|| ! $registered
	|| ! $onboarding_registered
	|| ! $crypto_roundtrip
	|| ! $result['github_updater_initialized']
) {
	exit( 1 );
}
