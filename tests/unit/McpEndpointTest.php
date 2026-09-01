<?php

declare( strict_types=1 );

use Novamira\MainWP\MCP_Endpoint;
use PHPUnit\Framework\TestCase;

final class McpEndpointTest extends TestCase {
	public function test_signed_same_origin_endpoint_overrides_a_mainwp_alias_path(): void {
		$result = MCP_Endpoint::resolve(
			'https://example.test/client-alias/',
			array( 'mcp' => array( 'endpoint' => 'https://example.test/wp-json/mcp/novamira' ) )
		);

		self::assertSame( 'https://example.test/wp-json/mcp/novamira', $result );
	}

	public function test_plain_permalink_rest_route_is_supported(): void {
		$result = MCP_Endpoint::resolve(
			'https://example.test/',
			array( 'mcp' => array( 'endpoint' => 'https://example.test/?rest_route=/mcp/novamira' ) )
		);

		self::assertSame( 'https://example.test/?rest_route=/mcp/novamira', $result );
	}

	public function test_signed_query_endpoint_is_preferred_over_a_pretty_rest_url(): void {
		$result = MCP_Endpoint::resolve(
			'https://example.test/subsite/',
			array(
				'mcp' => array(
					'endpoint'       => 'https://example.test/subsite/wp-json/mcp/novamira',
					'query_endpoint' => 'https://example.test/subsite/?rest_route=%2Fmcp%2Fnovamira',
				),
			)
		);

		self::assertSame( 'https://example.test/subsite/?rest_route=%2Fmcp%2Fnovamira', $result );
	}

	public function test_cross_origin_and_insecure_reported_endpoints_fail_closed(): void {
		$cross_origin = MCP_Endpoint::resolve(
			'https://example.test/',
			array( 'mcp' => array( 'endpoint' => 'https://attacker.test/wp-json/mcp/novamira' ) )
		);
		$insecure = MCP_Endpoint::resolve(
			'https://example.test/',
			array( 'mcp' => array( 'endpoint' => 'http://example.test/wp-json/mcp/novamira' ) )
		);

		self::assertSame( 'novamira_mainwp_mcp_endpoint_origin_mismatch', $cross_origin->get_error_code() );
		self::assertSame( 'novamira_mainwp_insecure_child_url', $insecure->get_error_code() );
	}

	public function test_legacy_fallback_remains_available_until_first_refresh(): void {
		$result = MCP_Endpoint::resolve( 'https://example.test/subsite/' );

		self::assertSame( 'https://example.test/subsite/?rest_route=/mcp/novamira', $result );
		self::assertFalse( MCP_Endpoint::is_authoritative( array() ) );
	}
}
