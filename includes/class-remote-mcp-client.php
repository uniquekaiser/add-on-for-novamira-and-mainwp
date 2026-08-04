<?php
/**
 * Request-scoped, leased MCP client for a Novamira child site.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class Remote_MCP_Client {
	private const PROTOCOL_VERSION = '2025-11-25';
	private const MAX_BODY_BYTES   = 2097152;

	/**
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function list_components( int $site_id, string $type, array $params = array() ) {
		$methods = array(
			'tools'     => 'tools/list',
			'resources' => 'resources/list',
			'prompts'   => 'prompts/list',
		);
		if ( ! isset( $methods[ $type ] ) ) {
			return new \WP_Error( 'novamira_mainwp_invalid_component_type', 'Component type must be tools, resources, or prompts.' );
		}
		return self::with_session(
			$site_id,
			static function ( callable $send ) use ( $methods, $type, $params ) {
				return $send( $methods[ $type ], $params );
			}
		);
	}

	/**
	 * @param array<string, mixed> $arguments
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function call_tool( int $site_id, string $tool_name, array $arguments, string $mode ) {
		return self::with_session(
			$site_id,
			static function ( callable $send ) use ( $tool_name, $arguments, $mode ) {
				$classification = self::classify_tool( $send, $tool_name, $arguments );
				if ( is_wp_error( $classification ) ) {
					return $classification;
				}
				$is_read = true === ( $classification['readonly'] ?? false ) && true !== ( $classification['destructive'] ?? true );
				if ( 'read' === $mode && ! $is_read ) {
					return new \WP_Error( 'novamira_mainwp_read_classification_failed', 'The remote tool is not verified as read-only.' );
				}
				if ( 'write' === $mode && $is_read ) {
					return new \WP_Error( 'novamira_mainwp_write_tool_is_readonly', 'Use the read-tool gateway for this read-only operation.' );
				}
				return $send(
					'tools/call',
					array(
						'name'      => $tool_name,
						'arguments' => $arguments,
					)
				);
			},
			'read' === $mode
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function read_resource( int $site_id, string $uri ) {
		return self::with_session(
			$site_id,
			static function ( callable $send ) use ( $uri ) {
				return $send( 'resources/read', array( 'uri' => $uri ) );
			}
		);
	}

	/** @param array<string, mixed> $arguments @return array<string, mixed>|\WP_Error */
	public static function get_prompt( int $site_id, string $name, array $arguments = array() ) {
		return self::with_session(
			$site_id,
			static function ( callable $send ) use ( $name, $arguments ) {
				return $send(
					'prompts/get',
					array(
						'name'      => $name,
						'arguments' => $arguments,
					)
				);
			}
		);
	}

	/**
	 * @param callable $callback Receives a JSON-RPC send callable.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function with_session( int $site_id, callable $callback, bool $retry_initialization = true ) {
		$site = MainWP_Client::site( $site_id );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		$stored   = Storage::get_site( $site_id );
		$username = (string) $stored['credential_username'];
		$secret   = Crypto::decrypt( (string) $stored['credential_secret'] );
		if ( '' === $username || is_wp_error( $secret ) || '' === $secret ) {
			return is_wp_error( $secret ) ? $secret : new \WP_Error( 'novamira_mainwp_credential_missing', 'Create a managed Novamira credential for this site first.' );
		}

		$lease = MainWP_Client::child_call( $site_id, 'lease-acquire' );
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}
		$token       = isset( $lease['token'] ) ? (string) $lease['token'] : '';
		$url         = trailingslashit( (string) $site->url ) . 'wp-json/mcp/novamira';
		$session_id  = '';
		$host        = (string) wp_parse_url( $url, PHP_URL_HOST );
		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$local_http  = 1 === preg_match( '#^http://#i', $url )
			&& ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) || '.local' === substr( strtolower( $host ), -6 ) )
			&& in_array( $environment, array( 'local', 'development' ), true );
		if ( 1 !== preg_match( '#^https://#i', $url ) && ! $local_http ) {
			MainWP_Client::child_call( $site_id, 'lease-release', array( 'token' => $token ) );
			return new \WP_Error( 'novamira_mainwp_insecure_child_url', 'Remote Novamira MCP connections require verified HTTPS outside a local environment.' );
		}

		try {
			$attempts    = $retry_initialization ? 2 : 1;
			$initialized = null;
			for ( $attempt = 0; $attempt < $attempts; ++$attempt ) {
				$session_id  = '';
				$initialized = self::request(
					$url,
					$username,
					$secret,
					$token,
					'',
					'initialize',
					array(
						'protocolVersion' => self::PROTOCOL_VERSION,
						'capabilities'    => new \stdClass(),
						'clientInfo'      => array(
							'name'    => 'Novamira for MainWP',
							'version' => NOVAMIRA_MAINWP_VERSION,
						),
					),
					$session_id
				);
				if ( ! is_wp_error( $initialized ) ) {
					break;
				}
			}
			if ( is_wp_error( $initialized ) ) {
				return $initialized;
			}
			if ( '' === $session_id ) {
				return new \WP_Error( 'novamira_mainwp_mcp_session_missing', 'The child MCP server did not return a session ID.' );
			}
			$notified = self::request(
				$url,
				$username,
				$secret,
				$token,
				$session_id,
				'notifications/initialized',
				array(),
				$session_id,
				true
			);
			if ( is_wp_error( $notified ) ) {
				return $notified;
			}

			$send = static function ( string $method, array $params = array() ) use ( $url, $username, $secret, $token, &$session_id ) {
				return self::request( $url, $username, $secret, $token, $session_id, $method, $params, $session_id );
			};
			return $callback( $send );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'novamira_mainwp_mcp_request_failed', $error->getMessage() );
		} finally {
			self::terminate( $url, $username, $secret, $token, $session_id );
			if ( '' !== $token ) {
				MainWP_Client::child_call( $site_id, 'lease-release', array( 'token' => $token ) );
			}
		}
	}

	/**
	 * @param callable                   $send
	 * @param array<string, mixed>       $arguments
	 * @return array{readonly:bool,destructive:bool}|\WP_Error
	 */
	private static function classify_tool( callable $send, string $tool_name, array $arguments ) {
		if ( in_array( $tool_name, array( 'mcp-adapter-execute-ability', 'mcp_adapter_execute_ability', 'execute-ability', 'execute_ability' ), true ) ) {
			$ability_name = isset( $arguments['ability_name'] ) && is_string( $arguments['ability_name'] ) ? $arguments['ability_name'] : '';
			if ( '' === $ability_name ) {
				return new \WP_Error( 'novamira_mainwp_ability_name_required', 'ability_name is required to classify execute-ability.' );
			}
			$info = $send(
				'tools/call',
				array(
					'name'      => 'mcp-adapter-get-ability-info',
					'arguments' => array( 'ability_name' => $ability_name ),
				)
			);
			if ( is_wp_error( $info ) ) {
				return $info;
			}
			$data        = self::tool_data( $info );
			$annotations = isset( $data['meta']['annotations'] ) && is_array( $data['meta']['annotations'] ) ? $data['meta']['annotations'] : array();
			return array(
				'readonly'    => true === ( $annotations['readonly'] ?? false ),
				'destructive' => true !== ( $annotations['readonly'] ?? false ) || true === ( $annotations['destructive'] ?? true ),
			);
		}

		$list = $send( 'tools/list', array() );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		foreach ( (array) ( $list['tools'] ?? array() ) as $tool ) {
			if ( is_array( $tool ) && ( $tool['name'] ?? '' ) === $tool_name ) {
				$annotations = isset( $tool['annotations'] ) && is_array( $tool['annotations'] ) ? $tool['annotations'] : array();
				return array(
					'readonly'    => true === ( $annotations['readOnlyHint'] ?? false ),
					'destructive' => true !== ( $annotations['readOnlyHint'] ?? false ) || true === ( $annotations['destructiveHint'] ?? true ),
				);
			}
		}
		return new \WP_Error( 'novamira_mainwp_tool_not_found', 'The remote MCP tool was not found.' );
	}

	/** @param array<string, mixed> $result @return array<string, mixed> */
	private static function tool_data( array $result ): array {
		if ( isset( $result['structuredContent'] ) && is_array( $result['structuredContent'] ) ) {
			return $result['structuredContent'];
		}
		foreach ( (array) ( $result['content'] ?? array() ) as $content ) {
			if ( is_array( $content ) && 'text' === ( $content['type'] ?? '' ) && isset( $content['text'] ) ) {
				$decoded = json_decode( (string) $content['text'], true );
				if ( is_array( $decoded ) ) {
					if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
						return $decoded['data'];
					}
					return $decoded;
				}
			}
		}
		return array();
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function request( string $url, string $username, string $password, string $lease, string $session_id, string $method, array $params, string &$response_session, bool $notification = false ) {
		$headers = array(
			'Authorization'           => 'Basic ' . base64_encode( $username . ':' . $password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'Accept'                  => 'application/json, text/event-stream',
			'Content-Type'            => 'application/json',
			'X-Novamira-MainWP-Lease' => $lease,
		);
		if ( '' !== $session_id ) {
			$headers['Mcp-Session-Id']       = $session_id;
			$headers['Mcp-Protocol-Version'] = self::PROTOCOL_VERSION;
		}
		$payload = array(
			'jsonrpc' => '2.0',
			'method'  => $method,
			'params'  => (object) $params,
		);
		if ( ! $notification ) {
			$payload['id'] = wp_rand( 1, PHP_INT_MAX );
		}
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => $headers,
				'body'        => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			return new \WP_Error( 'novamira_mainwp_mcp_response_too_large', 'The remote MCP response exceeded the two-megabyte safety limit.' );
		}
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'novamira_mainwp_mcp_http_error', 'The remote MCP server returned HTTP ' . $code . '.', array( 'status' => $code ) );
		}
		$header_session = wp_remote_retrieve_header( $response, 'mcp-session-id' );
		if ( is_string( $header_session ) && '' !== $header_session ) {
			$response_session = $header_session;
		}
		if ( $notification && '' === trim( $body ) ) {
			return array();
		}
		$decoded = self::decode_response_body( $body );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'novamira_mainwp_mcp_invalid_json', 'The remote MCP server returned invalid JSON.' );
		}
		if ( isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
			return new \WP_Error(
				'novamira_mainwp_mcp_rpc_error',
				isset( $decoded['error']['message'] ) ? (string) $decoded['error']['message'] : 'The remote MCP request failed.',
				$decoded['error']
			);
		}
		return isset( $decoded['result'] ) && is_array( $decoded['result'] ) ? $decoded['result'] : array();
	}

	/** @return array<string, mixed>|null */
	private static function decode_response_body( string $body ): ?array {
		$decoded = json_decode( $body, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		$events = preg_split( '/\r?\n\r?\n/', $body );
		if ( false === $events ) {
			return null;
		}
		foreach ( array_reverse( $events ) as $event ) {
			$data  = array();
			$lines = preg_split( '/\r?\n/', $event );
			foreach ( false === $lines ? array() : $lines as $line ) {
				if ( 0 === strpos( $line, 'data:' ) ) {
					$data[] = ltrim( substr( $line, 5 ) );
				}
			}
			$decoded = json_decode( implode( "\n", $data ), true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return null;
	}

	private static function terminate( string $url, string $username, string $password, string $lease, string $session_id ): void {
		if ( '' === $session_id ) {
			return;
		}
		wp_remote_request(
			$url,
			array(
				'method'      => 'DELETE',
				'timeout'     => 5,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Authorization'           => 'Basic ' . base64_encode( $username . ':' . $password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Mcp-Session-Id'          => $session_id,
					'Mcp-Protocol-Version'    => self::PROTOCOL_VERSION,
					'X-Novamira-MainWP-Lease' => $lease,
				),
			)
		);
	}
}
