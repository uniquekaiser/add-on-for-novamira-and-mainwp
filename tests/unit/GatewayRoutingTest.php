<?php

declare( strict_types=1 );

use Novamira\MainWP\Crypto;
use Novamira\MainWP\MainWP_Client;
use Novamira\MainWP\Remote_MCP_Client;
use Novamira\MainWP\Storage;
use PHPUnit\Framework\TestCase;

final class GatewayRoutingTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['nmm_filters']      = array();
		$GLOBALS['nmm_mcp_payloads'] = array();
		$GLOBALS['nmm_child_calls'] = array();
		$GLOBALS['nmm_options']     = array();
		$GLOBALS['nmm_scheduled']   = array();
		$GLOBALS['nmm_mcp_headers'] = array();
		$GLOBALS['nmm_mcp_urls'] = array();
		$GLOBALS['nmm_fail_mcp_url_once'] = '';
		$GLOBALS['nmm_status_endpoints'] = array(
			1 => array( 'endpoint' => 'https://one.test/wp-json/mcp/novamira', 'query_endpoint' => 'https://one.test/?rest_route=/mcp/novamira' ),
			2 => array( 'endpoint' => 'https://two.test/wp-json/mcp/novamira', 'query_endpoint' => 'https://two.test/?rest_route=/mcp/novamira' ),
		);
		$GLOBALS['nmm_http_deletes'] = array();
		$GLOBALS['wpdb']->site_records = array();
		NMM_Test_MainWP_DB::$sites = array(
			1 => (object) array( 'id' => 1, 'url' => 'https://one.test', 'adminname' => 'admin-one', 'suspended' => 0 ),
			2 => (object) array( 'id' => 2, 'url' => 'https://two.test', 'adminname' => 'admin-two', 'suspended' => 0 ),
		);

		add_filter( 'mainwp_encrypt_key_value', static function ( $unused, string $plaintext ): array { return array( 'encrypted_val' => strrev( $plaintext ), 'file_key' => 'key.php' ); }, 10, 2 );
		add_filter( 'mainwp_decrypt_key_value', static function ( $unused, array $envelope ): string { return strrev( $envelope['encrypted_val'] ); }, 10, 2 );
		add_filter(
			'mainwp_fetchurlauthed',
			static function ( $plugin_file, $key, int $site_id, string $what, array $params ): array {
				$payload = nmm_child_action( $params );
				$action  = (string) ( $payload['action'] ?? '' );
				$GLOBALS['nmm_child_calls'][] = array( 'site_id' => $site_id, 'what' => $what, 'action' => $action );
				if ( 'ai-open' === $action ) {
					$data = array( 'changed' => true, 'restore' => array( 'missing' => 'missing-' . $site_id, 'enabled' => '0', 'domain' => 'missing-' . $site_id ) );
				} elseif ( 'status' === $action ) {
					$data = array( 'mcp' => array_merge( $GLOBALS['nmm_status_endpoints'][ $site_id ], array( 'registered' => true, 'adapter_available' => true, 'dependency_error_code' => '' ) ) );
				} else {
					$data = array( 'restored' => true );
				}
				return nmm_child_response( $params, $data );
			},
			10,
			6
		);

		foreach ( array( 1, 2 ) as $site_id ) {
			Storage::update_site(
				$site_id,
				array(
					'credential_username' => 'admin-' . $site_id,
					'credential_secret'   => Crypto::encrypt( 'password-' . $site_id ),
					'credential_uuid'     => 'uuid-' . $site_id,
					'status_cache'        => array( 'mcp' => array( 'endpoint' => $GLOBALS['nmm_status_endpoints'][ $site_id ]['endpoint'] ) ),
					'status_checked_at'   => '2026-09-02 12:00:00',
				)
			);
		}

		$GLOBALS['nmm_http_handler'] = static function ( string $url, array $args ): array {
			$payload = json_decode( (string) $args['body'], true );
			$GLOBALS['nmm_mcp_payloads'][] = $payload;
			$GLOBALS['nmm_mcp_urls'][] = $url;
			$method  = (string) ( $payload['method'] ?? '' );
			$GLOBALS['nmm_mcp_headers'][]  = $args['headers'];
			if ( 'initialize' === $method && '' !== $GLOBALS['nmm_fail_mcp_url_once'] && $url === $GLOBALS['nmm_fail_mcp_url_once'] ) {
				$GLOBALS['nmm_fail_mcp_url_once'] = '';
				return array( 'response' => array( 'code' => 404 ), 'headers' => array(), 'body' => '' );
			}
			if ( 'notifications/initialized' === $method ) {
				return array( 'response' => array( 'code' => 202 ), 'headers' => array(), 'body' => '' );
			}
			$is_one  = false !== strpos( $url, 'one.test' );
			$tool    = $is_one ? 'site-one-read' : 'site-two-read';
			$result  = array();
			if ( 'tools/list' === $method ) {
				$result = array(
					'tools' => array(
						array( 'name' => $tool, 'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ) ),
						array( 'name' => $is_one ? 'site-one-write' : 'site-two-write', 'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ) ),
					),
				);
			} elseif ( 'tools/call' === $method ) {
				$result = array( 'structuredContent' => array( 'site' => $is_one ? 1 : 2, 'tool' => $payload['params']['name'] ) );
			} elseif ( 'resources/read' === $method ) {
				$result = array( 'contents' => array( array( 'uri' => $payload['params']['uri'], 'text' => $is_one ? 'one' : 'two' ) ) );
			} elseif ( 'prompts/get' === $method ) {
				$result = array( 'description' => $is_one ? 'one prompt' : 'two prompt', 'messages' => array() );
			} else {
				$result = array( 'protocolVersion' => '2025-11-25', 'capabilities' => array() );
			}
			return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 42, 'result' => $result ) ), 'headers' => 'initialize' === $method ? array( 'Mcp-Session-Id' => $is_one ? 'session-one' : 'session-two' ) : array() );
		};
	}

	public function test_numeric_site_ids_route_to_two_distinct_child_servers_and_restore_access_windows(): void {
		$GLOBALS['nmm_child_calls'] = array();
		$one = Remote_MCP_Client::call_tool( 1, 'site-one-read', array(), 'read' );
		$two = Remote_MCP_Client::call_tool( 2, 'site-two-read', array(), 'read' );
		self::assertSame( 1, $one['structuredContent']['site'] );
		self::assertSame( 2, $two['structuredContent']['site'] );
		self::assertCount( 2, $GLOBALS['nmm_http_deletes'] );
		self::assertSame( array( 'ai-open', 'ai-restore', 'ai-open', 'ai-restore' ), array_column( $GLOBALS['nmm_child_calls'], 'action' ) );
		self::assertSame( array( 'code_snippet', 'code_snippet', 'code_snippet', 'code_snippet' ), array_column( $GLOBALS['nmm_child_calls'], 'what' ) );
		self::assertArrayNotHasKey( 'X-Novamira-MainWP-Lease', $GLOBALS['nmm_mcp_headers'][0] );
		self::assertArrayNotHasKey( 'X-Novamira-MainWP-Lease', $GLOBALS['nmm_http_deletes'][0][1]['headers'] );
		$notifications = array_values(
			array_filter(
				$GLOBALS['nmm_mcp_payloads'],
				static function ( array $payload ): bool {
					return 'notifications/initialized' === ( $payload['method'] ?? '' );
				}
			)
		);
		self::assertCount( 2, $notifications );
		self::assertArrayNotHasKey( 'id', $notifications[0] );
	}

	public function test_arbitrary_or_suspended_site_ids_fail_before_remote_http(): void {
		NMM_Test_MainWP_DB::$sites[2]->suspended = 1;
		self::assertSame( 'novamira_mainwp_site_not_found', MainWP_Client::site( 999 )->get_error_code() );
		self::assertSame( 'novamira_mainwp_site_suspended', Remote_MCP_Client::read_resource( 2, 'novamira://status' )->get_error_code() );
		self::assertCount( 0, $GLOBALS['nmm_http_deletes'] );
	}

	public function test_resources_and_prompts_use_the_same_ephemeral_session_path(): void {
		$resource = Remote_MCP_Client::read_resource( 1, 'novamira://status' );
		$prompt   = Remote_MCP_Client::get_prompt( 2, 'site-summary' );
		self::assertSame( 'one', $resource['contents'][0]['text'] );
		self::assertSame( 'two prompt', $prompt['description'] );
		self::assertCount( 2, $GLOBALS['nmm_http_deletes'] );
	}

	public function test_tool_classification_fails_closed_and_separates_reads_from_writes(): void {
		$missing = Remote_MCP_Client::call_tool( 1, 'unknown-tool', array(), 'read' );
		$blocked = Remote_MCP_Client::call_tool( 1, 'site-one-write', array(), 'read' );
		$write   = Remote_MCP_Client::call_tool( 1, 'site-one-write', array(), 'write' );

		self::assertSame( 'novamira_mainwp_tool_not_found', $missing->get_error_code() );
		self::assertSame( 'novamira_mainwp_read_classification_failed', $blocked->get_error_code() );
		self::assertSame( 'site-one-write', $write['structuredContent']['tool'] );
	}

	public function test_signed_child_endpoint_overrides_a_mainwp_subpath_alias(): void {
		NMM_Test_MainWP_DB::$sites[1]->url = 'https://one.test/client-alias/';
		Storage::update_site( 1, array( 'status_cache' => array(), 'status_checked_at' => null ) );

		$result = Remote_MCP_Client::list_components( 1, 'tools' );

		self::assertFalse( is_wp_error( $result ) );
		self::assertSame( 'https://one.test/?rest_route=/mcp/novamira', $GLOBALS['nmm_mcp_urls'][0] );
		self::assertContains( 'status', array_column( $GLOBALS['nmm_child_calls'], 'action' ) );
	}

	public function test_read_initialization_self_heals_one_stale_404_endpoint(): void {
		NMM_Test_MainWP_DB::$sites[1]->url = 'https://one.test/client-alias/';
		$stale = 'https://one.test/client-alias/wp-json/mcp/novamira';
		Storage::update_site( 1, array( 'status_cache' => array( 'mcp' => array( 'endpoint' => $stale ) ) ) );
		$GLOBALS['nmm_fail_mcp_url_once'] = $stale;

		$result = Remote_MCP_Client::list_components( 1, 'tools' );

		self::assertFalse( is_wp_error( $result ) );
		self::assertSame( $stale, $GLOBALS['nmm_mcp_urls'][0] );
		self::assertSame( 'https://one.test/?rest_route=/mcp/novamira', $GLOBALS['nmm_mcp_urls'][1] );
		self::assertContains( 'status', array_column( $GLOBALS['nmm_child_calls'], 'action' ) );
	}

	public function test_write_initialization_does_not_retry_a_stale_404_endpoint(): void {
		NMM_Test_MainWP_DB::$sites[1]->url = 'https://one.test/client-alias/';
		$stale = 'https://one.test/client-alias/wp-json/mcp/novamira';
		Storage::update_site( 1, array( 'status_cache' => array( 'mcp' => array( 'endpoint' => $stale ) ) ) );
		$GLOBALS['nmm_fail_mcp_url_once'] = $stale;

		$result = Remote_MCP_Client::call_tool( 1, 'site-one-write', array(), 'write' );

		self::assertTrue( is_wp_error( $result ) );
		self::assertSame( 'novamira_mainwp_mcp_http_error', $result->get_error_code() );
		self::assertSame( array( $stale ), $GLOBALS['nmm_mcp_urls'] );
		self::assertNotContains( 'status', array_column( $GLOBALS['nmm_child_calls'], 'action' ) );
	}
}
