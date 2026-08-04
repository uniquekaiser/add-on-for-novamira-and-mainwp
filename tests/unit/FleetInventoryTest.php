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

	public function test_synced_inventory_reports_inactive_free_and_active_pro_without_child_contract(): void {
		NMM_Test_MainWP_DB::$sites = array(
			12 => (object) array(
				'id'              => 12,
				'name'            => 'Bootstrap site',
				'url'             => 'https://bootstrap.test',
				'suspended'       => 0,
				'plugins'         => wp_json_encode(
					array(
						'mainwp-novamira-addon/mainwp-novamira-addon.php' => array( 'name' => 'Novamira for MainWP', 'version' => '0.2.0', 'active' => true ),
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
		self::assertTrue( $result['items'][0]['companion']['active'] );
		self::assertSame( '0.2.0', $result['items'][0]['companion']['version'] );
		self::assertTrue( $result['items'][0]['free']['installed'] );
		self::assertFalse( $result['items'][0]['free']['active'] );
		self::assertSame( '1.11.2', $result['items'][0]['free']['version'] );
		self::assertTrue( $result['items'][0]['free']['update_available'] );
		self::assertSame( '1.11.3', $result['items'][0]['free']['update_version'] );
		self::assertTrue( $result['items'][0]['pro']['installed'] );
		self::assertTrue( $result['items'][0]['pro']['active'] );
		self::assertFalse( $result['items'][0]['pro']['license_active'] );
	}
}
