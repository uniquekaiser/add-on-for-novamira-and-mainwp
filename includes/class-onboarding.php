<?php
/**
 * Native MainWP Add Site onboarding integration.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class Onboarding {
	private const EXTENSION_SLUG = 'mainwp-novamira-addon';
	private const FREE_SLUG      = 'novamira';

	/** @param array<string, array<string, mixed>> $options @return array<string, array<string, mixed>> */
	public static function sync_options( array $options ): array {
		$options[ self::EXTENSION_SLUG ] = array(
			'plugin_slug'          => 'novamira/novamira.php',
			'plugin_name'          => __( 'Novamira Free', 'mainwp-novamira-addon' ),
			'no_setting'           => true,
			'action_after_install' => __( 'Create a managed credential and apply safe gateway defaults', 'mainwp-novamira-addon' ),
		);
		return $options;
	}

	/**
	 * Supply MainWP's scoped plugin-information request from Novamira's validated upstream release.
	 *
	 * @param mixed  $result Existing Plugins API result.
	 * @param string $action Plugins API action.
	 * @param mixed  $args   Plugins API arguments.
	 * @return mixed
	 */
	public static function plugin_information( $result, string $action, $args ) {
		if ( ! self::is_prepare_request( $action, $args ) ) {
			return $result;
		}

		$release = Fleet_Service::free_package();
		return (object) array(
			'name'          => 'Novamira Free',
			'slug'          => self::FREE_SLUG,
			'version'       => is_wp_error( $release ) ? '' : (string) $release['version'],
			'download_link' => is_wp_error( $release ) ? '' : (string) $release['download_url'],
		);
	}

	/** @param mixed $url @param array<string, mixed> $request */
	public static function prepare_download_url( $url, array $request ): string {
		if ( 'plugin' !== ( $request['type'] ?? '' ) || self::FREE_SLUG !== ( $request['slug'] ?? '' ) ) {
			return is_string( $url ) ? $url : '';
		}
		$release = Fleet_Service::free_package();
		return is_wp_error( $release ) ? '' : (string) $release['download_url'];
	}

	public static function apply_defaults( int $site_id ): void {
		$result = self::setup_site( $site_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json( array( 'error' => $result->get_error_message() ) );
		}
		wp_send_json(
			array(
				'result'  => 'success',
				'message' => __( 'Novamira Free is active; safe gateway defaults and a managed credential are ready.', 'mainwp-novamira-addon' ),
			)
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function setup_site( int $site_id ) {
		$site = MainWP_Client::site( $site_id );
		if ( is_wp_error( $site ) ) {
			return $site;
		}

		$status = Fleet_Service::refresh_status( $site_id );
		if ( is_wp_error( $status ) ) {
			Audit::record( $site_id, 'onboarding-defaults', 'error', 0 );
			return $status;
		}
		if ( empty( $status['free']['active'] ) ) {
			Audit::record( $site_id, 'onboarding-defaults', 'error', 0 );
			return new \WP_Error( 'novamira_mainwp_onboarding_free_required', 'Novamira Free must be installed and active. Select both Novamira onboarding checkboxes when adding the site.' );
		}

		$policy        = array(
			'gateway_enabled'     => true,
			'production_allowed'  => false,
			'ai_lifecycle'        => 'just-in-time',
			'fanout_read_allowed' => false,
			'allowed_abilities'   => array(),
			'disabled_abilities'  => array(),
		);
		$policy_result = Fleet_Service::set_policy( $site_id, $policy );
		if ( is_wp_error( $policy_result ) ) {
			Audit::record( $site_id, 'onboarding-defaults', 'error', 0 );
			return $policy_result;
		}

		$stored             = Storage::get_site( $site_id );
		$credential_created = false;
		if ( '' === (string) $stored['credential_uuid'] ) {
			$credential = Fleet_Service::rotate_credential( $site_id, false );
			if ( is_wp_error( $credential ) ) {
				Audit::record( $site_id, 'onboarding-defaults', 'error', 0 );
				return new \WP_Error( 'novamira_mainwp_onboarding_credential_failed', 'Safe gateway defaults were applied, but the managed credential could not be created: ' . $credential->get_error_message() );
			}
			$credential_created = true;
		}

		$refreshed = Fleet_Service::refresh_status( $site_id );
		if ( is_wp_error( $refreshed ) ) {
			Audit::record( $site_id, 'onboarding-defaults', 'error', 0 );
			return new \WP_Error( 'novamira_mainwp_onboarding_refresh_failed', 'Setup completed, but the final Novamira status refresh failed: ' . $refreshed->get_error_message() );
		}

		Audit::record( $site_id, 'onboarding-defaults', 'success', 0 );
		return array(
			'site_id'            => $site_id,
			'policy'             => $policy_result['policy'],
			'credential_created' => $credential_created,
			'status'             => $refreshed,
		);
	}

	/** @param mixed $args */
	private static function is_prepare_request( string $action, $args ): bool {
		$request_action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- MainWP validates its AJAX nonce before invoking the Plugins API.
		$request_type   = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Request scoping only; no state change.
		return 'plugin_information' === $action
			&& is_object( $args )
			&& self::FREE_SLUG === (string) ( $args->slug ?? '' )
			&& 'mainwp_ext_prepareinstallplugintheme' === $request_action
			&& 'plugin' === $request_type;
	}
}
