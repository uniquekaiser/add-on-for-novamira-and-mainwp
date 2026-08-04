<?php
/**
 * Restore state created by child-gateway-setup.php.
 *
 * @package NovamiraMainWP
 */

$missing = '__novamira_mainwp_missing_option__';
$state   = get_option( 'novamira_mainwp_local_e2e_cleanup', array() );
if ( ! is_array( $state ) ) {
	$state = array();
}

if ( ! empty( $state['user_id'] ) && ! empty( $state['uuid'] ) ) {
	WP_Application_Passwords::delete_application_password( (int) $state['user_id'], (string) $state['uuid'] );
}

$restore = static function ( string $option, $value ) use ( $missing ): void {
	if ( $missing === $value ) {
		delete_option( $option );
		return;
	}
	update_option( $option, $value, false );
};

$restore( 'novamira_ai_abilities_enabled', $state['manual'] ?? $missing );
$restore( 'novamira_ai_abilities_domain', $state['domain'] ?? $missing );
$restore( \Novamira\MainWP\Child_Companion::POLICY_OPTION, $state['policy'] ?? $missing );
$restore( \Novamira\MainWP\Child_Companion::LEASE_OPTION, $state['leases'] ?? $missing );
$restore( \MainWP\MCP\Bridge\Settings::OPTION, $state['bridge'] ?? $missing );
delete_option( 'novamira_mainwp_local_e2e_cleanup' );

echo wp_json_encode(
	array(
		'cleaned'        => true,
		'manual_enabled' => in_array( get_option( 'novamira_ai_abilities_enabled', false ), array( true, 1, '1' ), true ),
		'active_leases'  => count( \Novamira\MainWP\Child_Companion::live_leases() ),
	)
) . PHP_EOL;
