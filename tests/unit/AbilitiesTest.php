<?php

declare( strict_types=1 );

use Novamira\MainWP\Abilities;
use PHPUnit\Framework\TestCase;

final class AbilitiesTest extends TestCase {
	protected function setUp(): void { $GLOBALS['nmm_abilities'] = array(); }

	public function test_all_versioned_fleet_interfaces_are_registered(): void {
		Abilities::register();
		$expected = array( 'list-sites-v1', 'get-site-v1', 'provision-sites-v1', 'set-site-policy-v1', 'rotate-credential-v1', 'revoke-credential-v1', 'manage-pro-license-v1', 'list-components-v1', 'call-read-tool-v1', 'fanout-read-tool-v1', 'call-write-tool-v1', 'read-resource-v1', 'get-prompt-v1' );
		self::assertSame( array_map( static function ( string $slug ): string { return 'novamira-mainwp/' . $slug; }, $expected ), array_keys( $GLOBALS['nmm_abilities'] ) );
		$write = $GLOBALS['nmm_abilities']['novamira-mainwp/call-write-tool-v1'];
		self::assertTrue( $write['meta']['annotations']['destructive'] );
		self::assertArrayHasKey( 'confirm', $write['input_schema']['properties'] );
		self::assertArrayHasKey( 'dry_run', $write['input_schema']['properties'] );
		self::assertTrue( $GLOBALS['nmm_abilities']['novamira-mainwp/list-sites-v1']['meta']['annotations']['readonly'] );
		$operations = $GLOBALS['nmm_abilities']['novamira-mainwp/provision-sites-v1']['input_schema']['properties']['operation']['enum'];
		self::assertContains( 'install-free', $operations );
		self::assertContains( 'repair-free', $operations );
		self::assertContains( 'disable-ai', $operations );
	}
}
