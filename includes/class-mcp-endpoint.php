<?php
/**
 * Resolve and validate the child site's authoritative Novamira MCP endpoint.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class MCP_Endpoint {
	/**
	 * Prefer the endpoint reported by the signed child runtime, while retaining a
	 * validated legacy fallback until a site has been refreshed by this release.
	 *
	 * @param array<string, mixed> $status Child status cache.
	 * @return string|\WP_Error
	 */
	public static function resolve( string $site_url, array $status = array() ) {
		$mcp = isset( $status['mcp'] ) && is_array( $status['mcp'] ) ? $status['mcp'] : array();
		if ( isset( $mcp['query_endpoint'] ) && is_string( $mcp['query_endpoint'] ) && '' !== trim( $mcp['query_endpoint'] ) ) {
			$candidate = trim( $mcp['query_endpoint'] );
		} elseif ( isset( $mcp['endpoint'] ) && is_string( $mcp['endpoint'] ) && '' !== trim( $mcp['endpoint'] ) ) {
			$candidate = trim( $mcp['endpoint'] );
		} else {
			$candidate = trailingslashit( $site_url ) . '?rest_route=/mcp/novamira';
		}

		return self::validate( $site_url, $candidate );
	}

	/** @param array<string, mixed> $status */
	public static function is_authoritative( array $status ): bool {
		if ( ! isset( $status['mcp'] ) || ! is_array( $status['mcp'] ) ) {
			return false;
		}
		foreach ( array( 'query_endpoint', 'endpoint' ) as $key ) {
			if ( isset( $status['mcp'][ $key ] ) && is_string( $status['mcp'][ $key ] ) && '' !== trim( $status['mcp'][ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return string|\WP_Error */
	private static function validate( string $site_url, string $candidate ) {
		$site_origin = self::origin( $site_url );
		$origin      = self::origin( $candidate );
		if ( '' === $site_origin || '' === $origin ) {
			return new \WP_Error( 'novamira_mainwp_mcp_endpoint_invalid', 'The Novamira MCP URL contains invalid authority information.' );
		}
		$candidate_host = strtolower( (string) wp_parse_url( $candidate, PHP_URL_HOST ) );
		$scheme         = strtolower( (string) wp_parse_url( $candidate, PHP_URL_SCHEME ) );
		$path           = (string) wp_parse_url( $candidate, PHP_URL_PATH );
		$query          = (string) wp_parse_url( $candidate, PHP_URL_QUERY );

		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$local_http  = 'http' === $scheme
			&& ( in_array( $candidate_host, array( 'localhost', '127.0.0.1', '::1' ), true ) || '.local' === substr( $candidate_host, -6 ) )
			&& in_array( $environment, array( 'local', 'development' ), true );
		if ( 'https' !== $scheme && ! $local_http ) {
			return new \WP_Error( 'novamira_mainwp_insecure_child_url', 'Remote Novamira MCP connections require verified HTTPS outside a local environment.' );
		}
		// Only administrator-owned configuration can authorize another exact origin.
		$allowed = apply_filters( 'novamira_mainwp_mcp_allowed_origins', array(), $site_url );
		if ( ! hash_equals( $site_origin, $origin ) && ! self::origin_allowed( $origin, $allowed ) ) {
			return new \WP_Error( 'novamira_mainwp_mcp_endpoint_origin_mismatch', 'The child-reported Novamira MCP endpoint does not match the managed site scheme, host, and port.' );
		}

		// Parse exactly one route parameter; duplicate keys and extra query options are forbidden.
		$query_route = 1 === preg_match( '#^rest_route=[^&;=]+$#D', $query ) && 'rest_route=/mcp/novamira' === rawurldecode( $query );
		if ( false !== strpos( $candidate, '#' ) || ( '' !== $query && ! $query_route ) || ( ! self::has_mcp_path( $path ) && ! $query_route ) ) {
			return new \WP_Error( 'novamira_mainwp_mcp_endpoint_invalid', 'The child-reported URL is not a Novamira MCP endpoint.' );
		}

		return $candidate;
	}

	/** Return a canonical exact origin, rejecting ambiguous URL authority syntax. */
	public static function origin( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || empty( $parts['host'] ) || ! isset( $parts['scheme'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . strtolower( (string) $parts['host'] ) . ':' . $port;
	}

	/** @param mixed $allowed Administrator-configured origin list. */
	private static function origin_allowed( string $origin, $allowed ): bool {
		foreach ( is_array( $allowed ) ? $allowed : array() as $entry ) {
			if ( is_string( $entry ) && self::origin( $entry ) === $origin && null === wp_parse_url( $entry, PHP_URL_QUERY ) && null === wp_parse_url( $entry, PHP_URL_FRAGMENT ) && in_array( (string) wp_parse_url( $entry, PHP_URL_PATH ), array( '', '/' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * SSRF-hardened transport. Private destinations/nonstandard ports require an
	 * explicit administrator-owned origin allowlist, scoped to this request only.
	 *
	 * @param array<string,mixed> $args HTTP arguments.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function request( string $url, array $args ) {
		$args['reject_unsafe_urls'] = true;
		$args['redirection']        = 0;
		$args['sslverify']          = true;
		$origin                     = self::origin( $url );
		$allowed                    = apply_filters( 'novamira_mainwp_mcp_private_origins', array() );
		$exception                  = '' !== $origin && self::origin_allowed( $origin, $allowed );
		$host_filter                = static function ( $external, $host, $request_url ) use ( $url ) {
			return $request_url === $url ? true : $external;
		};
		$port_filter                = static function ( $ports, $host, $request_url ) use ( $url ) {
			if ( $request_url === $url ) {
				$scheme  = wp_parse_url( $url, PHP_URL_SCHEME );
				$ports[] = (int) ( wp_parse_url( $url, PHP_URL_PORT ) ?? ( 'https' === $scheme ? 443 : 80 ) );
			}
			return $ports;
		};
		if ( $exception ) {
			add_filter( 'http_request_host_is_external', $host_filter, 10, 3 );
			add_filter( 'http_allowed_safe_ports', $port_filter, 10, 3 );
		}
		try {
			return 'DELETE' === ( $args['method'] ?? '' ) ? wp_remote_request( $url, $args ) : wp_safe_remote_post( $url, $args );
		} finally {
			if ( $exception ) {
				remove_filter( 'http_request_host_is_external', $host_filter, 10 );
				remove_filter( 'http_allowed_safe_ports', $port_filter, 10 );
			}
		}
	}

	private static function has_mcp_path( string $path ): bool {
		return 1 === preg_match( '#/mcp/novamira/?$#', $path );
	}
}
