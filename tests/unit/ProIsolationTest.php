<?php

declare( strict_types=1 );

use Novamira\MainWP\Fleet_Service;
use Novamira\MainWP\Runtime_Access;
use Novamira\MainWP\Storage;
use PHPUnit\Framework\TestCase;

final class ProIsolationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['nmm_filters']         = array();
		$GLOBALS['nmm_options']         = array();
		$GLOBALS['nmm_scheduled']       = array();
		$GLOBALS['nmm_pro_actions']     = array();
		$GLOBALS['wpdb']->site_records  = array();
		NMM_Test_MainWP_DB::$sites = array(
			14 => (object) array( 'id' => 14, 'name' => 'Free only', 'url' => 'https://free-only.test', 'adminname' => 'admin', 'suspended' => 0 ),
		);
		add_filter(
			'mainwp_fetchurlauthed',
			static function ( $plugin_file, $key, int $site_id, string $what, array $params ): array {
				$payload = nmm_child_action( $params );
				$action  = (string) ( $payload['action'] ?? '' );
				$GLOBALS['nmm_pro_actions'][] = $action;
				if ( 'pro-license' === $action ) {
					return nmm_child_response( $params, array(), false, 'invalid_pro_operation', 'Novamira Pro is unavailable.' );
				}
				$data = 'ai-open' === $action
					? array( 'changed' => false, 'restore' => array( 'missing' => 'missing-14', 'enabled' => '1', 'domain' => 'free-only.test' ) )
					: array( 'restored' => true );
				return nmm_child_response( $params, $data );
			},
			10,
			6
		);
		Storage::update_site( 14, array( 'policy' => array( 'gateway_enabled' => true, 'production_allowed' => true, 'ai_lifecycle' => 'just-in-time' ) ) );
	}

	public function test_missing_pro_is_scoped_and_free_gateway_still_opens(): void {
		$pro    = Fleet_Service::manage_pro_license( 14, 'refresh' );
		$access = Runtime_Access::acquire( 14 );

		self::assertSame( 'invalid_pro_operation', $pro->get_error_code() );
		self::assertFalse( is_wp_error( $access ) );
		self::assertSame( array( 'pro-license', 'ai-open' ), $GLOBALS['nmm_pro_actions'] );
		Runtime_Access::release( 14, $access['token'] );
	}
}
