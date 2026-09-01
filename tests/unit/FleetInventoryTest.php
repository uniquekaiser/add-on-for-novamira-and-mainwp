<?php

declare( strict_types=1 );

use Novamira\MainWP\Fleet_Service;
use PHPUnit\Framework\TestCase;

final class FleetInventoryTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['nmm_filters']          = array();
		$GLOBALS['wpdb']->site_records = array();
		add_filter(
			'mainwp_fetchurlauthed',
			static function ( $value ) {
				return $value;
			}
		);
	}

	public function test_synced_inventory_reports_inactive_free_and_active_optional_pro(): void {
		NMM_Test_MainWP_DB::$sites = array(
			12 => (object) array(
				'id'              => 12,
				'name'            => 'Bootstrap site',
				'url'             => 'https://bootstrap.test',
				'suspended'       => 0,
				'plugins'         => wp_json_encode(
					array(
						'novamira/novamira.php' => array( 'name' => 'Novamira', 'version' => '1.11.2', 'active' => false ),
						array( 'slug' => 'novamira-pro/novamira-pro.php', 'name' => 'Novamira Pro', 'version' => '1.8.1', 'active' => true ),
					)
				),
				'plugin_upgrades' => wp_json_encode(
					array(
						'novamira/novamira.php' => array( 'update' => array( 'new_version' => '1.11.3' ) ),
					)
				),
			),
		);

		$result = Fleet_Service::list_sites();

		self::assertCount( 1, $result['items'] );
		self::assertTrue( $result['items'][0]['free']['installed'] );
		self::assertFalse( $result['items'][0]['free']['active'] );
		self::assertSame( '1.11.2', $result['items'][0]['free']['version'] );
		self::assertTrue( $result['items'][0]['free']['update_available'] );
		self::assertSame( '1.11.3', $result['items'][0]['free']['update_version'] );
		self::assertTrue( $result['items'][0]['pro']['installed'] );
		self::assertTrue( $result['items'][0]['pro']['active'] );
		self::assertArrayNotHasKey( 'license_active', $result['items'][0]['pro'] );
		self::assertFalse( $result['items'][0]['status_known'] );
	}

	public function test_refreshed_child_status_reports_existing_ai_and_pro_license_settings(): void {
		NMM_Test_MainWP_DB::$sites = array(
			77 => (object) array(
				'id'              => 77,
				'name'            => 'Existing Novamira site',
				'url'             => 'https://existing.test',
				'suspended'       => 0,
				'plugins'         => wp_json_encode(
					array(
						'novamira/novamira.php'         => array( 'version' => '1.11.2', 'active' => true ),
						'novamira-pro/novamira-pro.php' => array( 'version' => '1.8.2', 'active' => true ),
					)
				),
				'plugin_upgrades' => '{}',
			),
		);
		\Novamira\MainWP\Storage::update_site(
			77,
			array(
				'status_checked_at' => '2026-08-05 18:00:00',
				'status_cache'      => array(
					'free'                      => array( 'installed' => true, 'active' => true, 'version' => '1.11.2' ),
					'pro'                       => array( 'installed' => true, 'active' => true, 'version' => '1.8.2', 'license_known' => true, 'license_active' => true, 'license_masked' => 'ABCD?WXYZ' ),
					'ai'                        => array( 'manual_enabled' => true, 'production' => true ),
					'application_passwords'     => array( 'supported' => true, 'available_for_user' => true, 'credential_healthy' => null ),
					'available_abilities'       => array( 'novamira/site-info', 'novamira/content-read' ),
					'available_abilities_known' => true,
					'mcp'                       => array( 'endpoint' => 'https://existing.test/wp-json/mcp/novamira', 'query_endpoint' => 'https://existing.test/?rest_route=/mcp/novamira', 'registered' => true, 'adapter_available' => true ),
				),
			)
		);

		$result = Fleet_Service::list_sites();

		self::assertTrue( $result['items'][0]['status_known'] );
		self::assertSame( '2026-08-05 18:00:00', $result['items'][0]['status_updated_at'] );
		self::assertTrue( $result['items'][0]['ai']['manual_enabled'] );
		self::assertTrue( $result['items'][0]['pro']['license_active'] );
		self::assertTrue( $result['items'][0]['credential']['available_for_user'] );
		self::assertCount( 2, $result['items'][0]['available_abilities'] );
		self::assertSame( 'https://existing.test/wp-json/mcp/novamira', $result['items'][0]['mcp']['endpoint'] );
		self::assertSame( 'https://existing.test/?rest_route=/mcp/novamira', $result['items'][0]['mcp']['query_endpoint'] );
	}
}
