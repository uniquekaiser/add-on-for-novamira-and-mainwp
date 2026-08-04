<?php
/**
 * Prepare a disposable leased Novamira HTTP session test.
 *
 * The caller must capture this script's JSON output and always run the paired
 * cleanup script. Run only on a disposable local WordPress fixture.
 *
 * @package NovamiraMainWP
 */

$missing = '__novamira_mainwp_missing_option__';
$state   = array(
	'manual' => get_option( 'novamira_ai_abilities_enabled', $missing ),
	'domain' => get_option( 'novamira_ai_abilities_domain', $missing ),
	'policy' => get_option( \Novamira\MainWP\Child_Companion::POLICY_OPTION, $missing ),
	'leases' => get_option( \Novamira\MainWP\Child_Companion::LEASE_OPTION, $missing ),
	'bridge' => get_option( \MainWP\MCP\Bridge\Settings::OPTION, $missing ),
);

update_option( 'novamira_ai_abilities_enabled', '0', false );
update_option(
	\Novamira\MainWP\Child_Companion::POLICY_OPTION,
	array(
		'gateway_enabled'    => true,
		'production_allowed' => true,
		'ai_lifecycle'       => 'just-in-time',
	),
	false
);
$bridge_settings                  = \MainWP\MCP\Bridge\Settings::get();
$bridge_settings['enabled']       = true;
$bridge_settings['exposure_mode'] = \MainWP\MCP\Bridge\Settings::EXPOSURE_BOTH;
update_option( \MainWP\MCP\Bridge\Settings::OPTION, $bridge_settings, false );

if ( ! defined( 'MAINWP_CHILD_VERSION' ) ) {
	define( 'MAINWP_CHILD_VERSION', '6.1.5' );
}
\Novamira\MainWP\Child_Companion::register_contract();

$users = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
$user  = reset( $users );
if ( ! $user instanceof WP_User || ! wp_is_application_passwords_available_for_user( $user ) ) {
	throw new RuntimeException( 'A local administrator with Application Password support is required.' );
}

$created = WP_Application_Passwords::create_new_application_password(
	$user->ID,
	array(
		'name'   => 'Novamira MainWP local E2E',
		'app_id' => wp_generate_uuid4(),
	)
);
if ( is_wp_error( $created ) ) {
	throw new RuntimeException( $created->get_error_message() );
}

$lease = \Novamira\MainWP\Child_Companion::dispatch( 'lease-acquire', array() );
if ( is_wp_error( $lease ) ) {
	WP_Application_Passwords::delete_application_password( $user->ID, (string) $created[1]['uuid'] );
	throw new RuntimeException( $lease->get_error_message() );
}

$stored_leases = get_option( \Novamira\MainWP\Child_Companion::LEASE_OPTION, array() );
if ( false !== strpos( wp_json_encode( $stored_leases ), (string) $lease['token'] ) ) {
	throw new RuntimeException( 'The plaintext lease token was persisted.' );
}

$state['user_id'] = $user->ID;
$state['uuid']    = (string) $created[1]['uuid'];
update_option( 'novamira_mainwp_local_e2e_cleanup', $state, false );

echo wp_json_encode(
	array(
		'url'                       => rest_url( 'mcp/novamira' ),
		'mainwp_url'                => rest_url( 'mcp/mainwp' ),
		'username'                  => $user->user_login,
		'password'                  => $created[0],
		'lease'                     => $lease['token'],
		'uuid'                      => $created[1]['uuid'],
		'contract_filter_registered' => false !== has_filter( 'mainwp_child_extra_execution', array( \Novamira\MainWP\Child_Companion::class, 'extra_execution' ) ),
	)
);
