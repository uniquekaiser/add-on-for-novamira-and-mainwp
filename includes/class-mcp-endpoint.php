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
		$site_host      = strtolower( (string) wp_parse_url( $site_url, PHP_URL_HOST ) );
		$candidate_host = strtolower( (string) wp_parse_url( $candidate, PHP_URL_HOST ) );
		$scheme         = strtolower( (string) wp_parse_url( $candidate, PHP_URL_SCHEME ) );
		$path           = (string) wp_parse_url( $candidate, PHP_URL_PATH );
		$query          = (string) wp_parse_url( $candidate, PHP_URL_QUERY );
		$fragment       = (string) wp_parse_url( $candidate, PHP_URL_FRAGMENT );

		if ( '' === $site_host || '' === $candidate_host || ! hash_equals( $site_host, $candidate_host ) ) {
			return new \WP_Error( 'novamira_mainwp_mcp_endpoint_origin_mismatch', 'The child-reported Novamira MCP endpoint does not match the managed site host.' );
		}

		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$local_http  = 'http' === $scheme
			&& ( in_array( $candidate_host, array( 'localhost', '127.0.0.1', '::1' ), true ) || '.local' === substr( $candidate_host, -6 ) )
			&& in_array( $environment, array( 'local', 'development' ), true );
		if ( 'https' !== $scheme && ! $local_http ) {
			return new \WP_Error( 'novamira_mainwp_insecure_child_url', 'Remote Novamira MCP connections require verified HTTPS outside a local environment.' );
		}

		parse_str( $query, $query_args );
		$route = isset( $query_args['rest_route'] ) && is_string( $query_args['rest_route'] ) ? $query_args['rest_route'] : '';
		if ( '' !== $fragment || ( ! self::has_mcp_path( $path ) && '/mcp/novamira' !== $route ) ) {
			return new \WP_Error( 'novamira_mainwp_mcp_endpoint_invalid', 'The child-reported URL is not a Novamira MCP endpoint.' );
		}

		return $candidate;
	}

	private static function has_mcp_path( string $path ): bool {
		return 1 === preg_match( '#/mcp/novamira/?$#', $path );
	}
}
