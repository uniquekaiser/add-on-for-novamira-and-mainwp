<?php

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Novamira\MainWP\Child_Companion;

final class ProIsolationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['nmm_options'] = array();
		$GLOBALS['nmm_looks_production'] = false;
	}

	public function test_missing_pro_returns_a_scoped_error_without_disabling_free_gateway(): void {
		$pro = Child_Companion::dispatch( 'pro-license', array( 'operation' => 'refresh' ) );
		self::assertSame( 'novamira_mainwp_pro_unavailable', $pro->get_error_code() );
		$lease = Child_Companion::dispatch( 'lease-acquire', array() );
		self::assertFalse( is_wp_error( $lease ) );
		self::assertSame( 300, $lease['ttl'] );
	}
}
