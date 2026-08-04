<?php

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Novamira\MainWP\Child_Companion;

final class LeaseTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['nmm_options'] = array();
		$GLOBALS['nmm_manual_enabled'] = false;
		$GLOBALS['nmm_looks_production'] = false;
		unset( $_SERVER['HTTP_X_NOVAMIRA_MAINWP_LEASE'] );
	}

	public function test_leases_are_hashed_concurrent_and_independently_released(): void {
		$first  = Child_Companion::dispatch( 'lease-acquire', array() );
		$second = Child_Companion::dispatch( 'lease-acquire', array() );
		self::assertFalse( is_wp_error( $first ) );
		self::assertFalse( is_wp_error( $second ) );
		$stored = get_option( Child_Companion::LEASE_OPTION, array() );
		self::assertCount( 2, $stored );
		self::assertArrayNotHasKey( $first['token'], $stored );
		$_SERVER['HTTP_X_NOVAMIRA_MAINWP_LEASE'] = $first['token'];
		self::assertTrue( Child_Companion::request_has_valid_lease() );
		Child_Companion::dispatch( 'lease-release', array( 'token' => $first['token'] ) );
		self::assertFalse( Child_Companion::request_has_valid_lease() );
		$_SERVER['HTTP_X_NOVAMIRA_MAINWP_LEASE'] = $second['token'];
		self::assertTrue( Child_Companion::request_has_valid_lease() );
	}

	public function test_expired_lease_is_removed_and_denied(): void {
		$token = str_repeat( 'a', 64 );
		update_option( Child_Companion::LEASE_OPTION, array( Child_Companion::lease_hash( $token ) => time() - 1 ) );
		$_SERVER['HTTP_X_NOVAMIRA_MAINWP_LEASE'] = $token;
		self::assertFalse( Child_Companion::request_has_valid_lease() );
		self::assertSame( array(), get_option( Child_Companion::LEASE_OPTION ) );
	}

	public function test_valid_lease_adds_request_local_novamira_option_filters(): void {
		$GLOBALS['nmm_filters'] = array();
		$lease                  = Child_Companion::dispatch( 'lease-acquire', array() );
		$_SERVER['HTTP_X_NOVAMIRA_MAINWP_LEASE'] = $lease['token'];

		Child_Companion::preload();

		self::assertTrue( has_filter( 'pre_option_novamira_ai_abilities_enabled' ) );
		self::assertTrue( has_filter( 'pre_option_novamira_ai_abilities_domain' ) );
		self::assertSame( '1', apply_filters( 'pre_option_novamira_ai_abilities_enabled', false, 'novamira_ai_abilities_enabled', false ) );
		self::assertSame( 'dashboard.test', apply_filters( 'pre_option_novamira_ai_abilities_domain', false, 'novamira_ai_abilities_domain', false ) );
		self::assertSame( false, get_option( 'novamira_ai_abilities_enabled', false ) );
	}

	public function test_production_and_lifecycle_policies_fail_closed(): void {
		$GLOBALS['nmm_looks_production'] = true;
		$result = Child_Companion::dispatch( 'lease-acquire', array() );
		self::assertSame( 'novamira_mainwp_production_denied', $result->get_error_code() );

		$GLOBALS['nmm_looks_production'] = false;
		Child_Companion::dispatch( 'set-policy', array( 'gateway_enabled' => true, 'ai_lifecycle' => 'disabled' ) );
		$result = Child_Companion::dispatch( 'lease-acquire', array() );
		self::assertSame( 'novamira_mainwp_ai_disabled', $result->get_error_code() );

		Child_Companion::dispatch( 'set-policy', array( 'gateway_enabled' => true, 'ai_lifecycle' => 'manual-only' ) );
		$result = Child_Companion::dispatch( 'lease-acquire', array() );
		self::assertSame( 'novamira_mainwp_manual_enablement_required', $result->get_error_code() );
	}
}
