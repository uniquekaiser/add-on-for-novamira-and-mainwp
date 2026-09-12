<?php

declare( strict_types=1 );

use Novamira\MainWP\MCP_Endpoint;
use PHPUnit\Framework\TestCase;

final class McpEndpointTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['nmm_filters'] = array();
		$GLOBALS['nmm_environment'] = 'production';
	}

	/** @dataProvider malicious_endpoints */
	public function test_malicious_authorities_and_queries_fail_closed( string $url ): void {
		$result = MCP_Endpoint::resolve( 'https://example.test/', array( 'mcp' => array( 'endpoint' => $url ) ) );
		self::assertInstanceOf( WP_Error::class, $result );
	}

	public static function malicious_endpoints(): array {
		return array_map( static function ( $url ) { return array( $url ); }, array(
			'https://example.test:8443/wp-json/mcp/novamira',
			'https://user:password@example.test/wp-json/mcp/novamira',
			'https://example.test@attacker.test/wp-json/mcp/novamira',
			'https://example.test/wp-json/mcp/novamira?redirect=https://attacker.test',
			'https://example.test/?rest_route=/mcp/novamira&context=edit',
			'https://example.test/?rest_route=/wp/v2/users&rest_route=/mcp/novamira',
			'https://example.test/?rest_route%5B%5D=/mcp/novamira',
			'https://example.test/?rest_route=/mcp/novamira;',
			'https://example.test/wp-json/mcp/novamira#',
			'https://example.test./wp-json/mcp/novamira',
			'https://example.test\\@attacker.test/wp-json/mcp/novamira',
		) );
	}

	public function test_explicit_default_port_and_matching_nonstandard_ports_are_same_origin(): void {
		self::assertSame( 'https://EXAMPLE.test:443/wp-json/mcp/novamira', MCP_Endpoint::resolve( 'https://example.test/', array( 'mcp' => array( 'endpoint' => 'https://EXAMPLE.test:443/wp-json/mcp/novamira' ) ) ) );
		self::assertSame( 'https://example.test:8443/wp-json/mcp/novamira', MCP_Endpoint::resolve( 'https://example.test:8443/', array( 'mcp' => array( 'endpoint' => 'https://example.test:8443/wp-json/mcp/novamira' ) ) ) );
	}

	public function test_origin_exception_must_be_exact_and_administrator_configured(): void {
		add_filter( 'novamira_mainwp_mcp_allowed_origins', static function ( $origins, $site ) {
			return 'https://example.test/' === $site ? array( 'https://example.test:8443' ) : array();
		}, 10, 2 );
		$url = 'https://example.test:8443/wp-json/mcp/novamira';
		self::assertSame( $url, MCP_Endpoint::resolve( 'https://example.test/', array( 'mcp' => array( 'endpoint' => $url ) ) ) );
		self::assertInstanceOf( WP_Error::class, MCP_Endpoint::resolve( 'https://example.test/', array( 'mcp' => array( 'endpoint' => str_replace( '8443', '9443', $url ) ) ) ) );
	}

	public function test_private_transport_exception_is_scoped_and_filters_are_removed(): void {
		$url = 'https://child.local:9443/wp-json/mcp/novamira';
		add_filter( 'novamira_mainwp_mcp_private_origins', static function () { return array( 'https://child.local:9443' ); } );
		$GLOBALS['nmm_http_handler'] = static function ( $request_url, $args ) use ( $url ) {
			self::assertTrue( $args['reject_unsafe_urls'] );
			self::assertSame( 0, $args['redirection'] );
			self::assertTrue( apply_filters( 'http_request_host_is_external', false, 'child.local', $url ) );
			self::assertFalse( apply_filters( 'http_request_host_is_external', false, 'other.local', 'https://other.local/' ) );
			self::assertContains( 9443, apply_filters( 'http_allowed_safe_ports', array( 80, 443 ), 'child.local', $url ) );
			self::assertNotContains( 9443, apply_filters( 'http_allowed_safe_ports', array( 80, 443 ), 'other.local', 'https://other.local/' ) );
			return new WP_Error( 'transport_failure', 'Deliberate failure.' );
		};
		self::assertInstanceOf( WP_Error::class, MCP_Endpoint::request( $url, array() ) );
		self::assertFalse( apply_filters( 'http_request_host_is_external', false, 'child.local', $url ) );
		self::assertNotContains( 9443, apply_filters( 'http_allowed_safe_ports', array( 80, 443 ), 'child.local', $url ) );
	}

	public function test_local_http_requires_local_environment_and_exact_managed_origin(): void {
		$url = 'http://child.local:8000/?rest_route=/mcp/novamira';
		$status = array( 'mcp' => array( 'endpoint' => $url ) );
		self::assertInstanceOf( WP_Error::class, MCP_Endpoint::resolve( 'http://child.local:8000/', $status ) );
		$GLOBALS['nmm_environment'] = 'local';
		self::assertSame( $url, MCP_Endpoint::resolve( 'http://child.local:8000/', $status ) );
		self::assertInstanceOf( WP_Error::class, MCP_Endpoint::resolve( 'http://child.local:9000/', $status ) );
		$GLOBALS['nmm_environment'] = 'production';
	}

	public function test_private_transport_has_no_implicit_safe_url_bypass(): void {
		$url = 'https://child.local:9443/wp-json/mcp/novamira';
		$GLOBALS['nmm_http_handler'] = static function ( $request_url, $args ) use ( $url ) {
			self::assertTrue( $args['reject_unsafe_urls'] );
			self::assertFalse( apply_filters( 'http_request_host_is_external', false, 'child.local', $url ) );
			self::assertSame( array( 80, 443 ), apply_filters( 'http_allowed_safe_ports', array( 80, 443 ), 'child.local', $url ) );
			return new WP_Error( 'unsafe_url', 'Simulated WordPress safe transport rejection.' );
		};
		self::assertInstanceOf( WP_Error::class, MCP_Endpoint::request( $url, array() ) );
	}

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
