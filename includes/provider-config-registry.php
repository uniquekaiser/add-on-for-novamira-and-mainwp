<?php
/**
 * Add-on-owned MCP client configuration templates for the MainWP gateway.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Novamira_Provider_Config_Registry', false ) ) {
	final class Novamira_Provider_Config_Registry {
		/** @return array<string, array{code:string,hint:string,paths:array<string,string>,isShell:bool}> */
		public static function build( string $url, string $username, string $password, string $name, bool $self_signed = false, string $text_domain = 'novamira' ): array {
			$options  = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
			$server   = self::server( $url, $username, $password, $self_signed );
			$standard = self::standard( $name, $server, $options, $text_domain );
			$special  = array(
				'claude-code' => array(
					'code'    => self::claude_code( $name, $url, $username, $password, $self_signed ),
					'hint'    => self::text( 'Run in your terminal.', $text_domain ),
					'paths'   => array(),
					'isShell' => true,
				),
				'codex'       => array(
					'code'    => self::codex( $name, $url, $username, $password, $self_signed ),
					'hint'    => sprintf( self::text( 'Add to %s.', $text_domain ), '<code>config.toml</code>' ),
					'paths'   => array(
						'macOS / Linux' => '~/.codex/config.toml',
						'Windows'       => '%USERPROFILE%\.codex\config.toml',
					),
					'isShell' => false,
				),
				'zed'         => array(
					'code'    => (string) wp_json_encode(
						array(
							'context_servers' => array(
								$name => array_merge(
									array(
										'source'  => 'custom',
										'enabled' => true,
									),
									$server
								),
							),
						),
						$options
					),
					'hint'    => sprintf( self::text( 'Add to %s.', $text_domain ), '<code>settings.json</code>' ),
					'paths'   => array( 'macOS / Linux' => '~/.config/zed/settings.json' ),
					'isShell' => false,
				),
				'opencode'    => array(
					'code'    => self::opencode( $name, $url, $username, $password, $self_signed, $options ),
					'hint'    => sprintf( self::text( 'Add to %s.', $text_domain ), '<code>opencode.json</code>' ),
					'paths'   => array(
						self::text( 'Project', $text_domain ) => 'opencode.json',
						self::text( 'Global', $text_domain )  => '~/.config/opencode/opencode.json',
					),
					'isShell' => false,
				),
			);
			return array_merge( $standard, $special );
		}

		private static function text( string $value, string $domain ): string {
			return __( $value, $domain ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.NonSingularStringLiteralDomain -- This registry is shared by two host plugins.
		}

		/** @return array<string, mixed> */
		private static function server( string $url, string $username, string $password, bool $self_signed ): array {
			$environment = array(
				'WP_API_URL'      => $url,
				'WP_API_USERNAME' => $username,
				'WP_API_PASSWORD' => $password,
			);
			if ( $self_signed ) {
				$environment['NODE_TLS_REJECT_UNAUTHORIZED'] = '0';
			}
			return array(
				'command' => 'npx',
				'args'    => array( '-y', '@automattic/mcp-wordpress-remote@latest' ),
				'env'     => $environment,
			);
		}

		/** @param array<string,mixed> $server @return array<string, array{code:string,hint:string,paths:array<string,string>,isShell:bool}> */
		private static function standard( string $name, array $server, int $options, string $domain ): array {
			$mcp    = (string) wp_json_encode( array( 'mcpServers' => array( $name => $server ) ), $options );
			$vscode = (string) wp_json_encode( array( 'servers' => array( $name => $server ) ), $options );
			$hint   = static function ( string $file ) use ( $domain ): string {
				return sprintf( self::text( 'Add to %s.', $domain ), '<code>' . $file . '</code>' );
			};
			return array(
				'claude-desktop' => array(
					'code'    => $mcp,
					'hint'    => $hint( 'claude_desktop_config.json' ),
					'paths'   => array(
						'macOS'   => '~/Library/Application Support/Claude/claude_desktop_config.json',
						'Windows' => '%APPDATA%\Claude\claude_desktop_config.json',
					),
					'isShell' => false,
				),
				'cursor'         => array(
					'code'    => $mcp,
					'hint'    => $hint( 'mcp.json' ),
					'paths'   => array(
						self::text( 'Global', $domain )  => '~/.cursor/mcp.json',
						self::text( 'Project', $domain ) => '.cursor/mcp.json',
					),
					'isShell' => false,
				),
				'vscode'         => array(
					'code'    => $vscode,
					'hint'    => $hint( 'mcp.json' ),
					'paths'   => array(
						self::text( 'Workspace', $domain ) => '.vscode/mcp.json',
						self::text( 'User', $domain )      => self::text( 'Run: MCP: Open User Configuration (command palette)', $domain ),
					),
					'isShell' => false,
				),
				'windsurf'       => array(
					'code'    => $mcp,
					'hint'    => $hint( 'mcp_config.json' ),
					'paths'   => array(
						'macOS / Linux' => '~/.codeium/windsurf/mcp_config.json',
						'Windows'       => '%USERPROFILE%\.codeium\windsurf\mcp_config.json',
					),
					'isShell' => false,
				),
				'cline'          => array(
					'code'    => $mcp,
					'hint'    => $hint( 'cline_mcp_settings.json' ),
					'paths'   => array( self::text( 'Via UI', $domain ) => self::text( 'Cline sidebar → MCP Servers → Configure MCP Servers', $domain ) ),
					'isShell' => false,
				),
				'roo-code'       => array(
					'code'    => $mcp,
					'hint'    => $hint( 'mcp.json' ),
					'paths'   => array(
						self::text( 'Project', $domain ) => '.roo/mcp.json',
						self::text( 'Via UI', $domain )  => self::text( 'Roo Code sidebar → MCP Servers → Configure MCP Servers', $domain ),
					),
					'isShell' => false,
				),
				'kilo-code'      => array(
					'code'    => $mcp,
					'hint'    => $hint( 'mcp.json' ),
					'paths'   => array(
						self::text( 'Project', $domain ) => '.kilocode/mcp.json',
						self::text( 'Via UI', $domain )  => self::text( 'Kilo Code sidebar → MCP Servers → Configure MCP Servers', $domain ),
					),
					'isShell' => false,
				),
				'github-copilot' => array(
					'code'    => $vscode,
					'hint'    => $hint( 'mcp.json' ),
					'paths'   => array( self::text( 'Project', $domain ) => '.github/copilot/mcp.json' ),
					'isShell' => false,
				),
				'amazon-q'       => array(
					'code'    => $mcp,
					'hint'    => $hint( 'mcp.json' ),
					'paths'   => array(
						self::text( 'Global', $domain )  => '~/.aws/amazonq/mcp.json',
						self::text( 'Project', $domain ) => '.amazonq/mcp.json',
					),
					'isShell' => false,
				),
				'gemini-cli'     => array(
					'code'    => $mcp,
					'hint'    => $hint( 'settings.json' ),
					'paths'   => array(
						self::text( 'Global', $domain )  => '~/.gemini/settings.json',
						self::text( 'Project', $domain ) => '.gemini/settings.json',
					),
					'isShell' => false,
				),
				'antigravity'    => array(
					'code'    => $mcp,
					'hint'    => $hint( 'mcp_config.json' ),
					'paths'   => array(
						'macOS / Linux' => '~/.gemini/config/mcp_config.json',
						'Windows'       => '%USERPROFILE%\.gemini\config\mcp_config.json',
					),
					'isShell' => false,
				),
			);
		}

		private static function codex( string $name, string $url, string $username, string $password, bool $self_signed ): string {
			$quote = static function ( string $value ): string {
				return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value ) . '"';
			};
			$lines = array( '[mcp_servers.' . $name . ']', 'command = "npx"', 'args = ["-y", "@automattic/mcp-wordpress-remote@latest"]', '', '[mcp_servers.' . $name . '.env]', 'WP_API_URL = ' . $quote( $url ), 'WP_API_USERNAME = ' . $quote( $username ), 'WP_API_PASSWORD = ' . $quote( $password ) );
			if ( $self_signed ) {
				$lines[] = 'NODE_TLS_REJECT_UNAUTHORIZED = "0"';
			}
			return implode( "\n", $lines );
		}

		private static function claude_code( string $name, string $url, string $username, string $password, bool $self_signed ): string {
			$quote = static function ( string $value ): string {
				return "'" . str_replace( "'", "'\\''", $value ) . "'";
			};
			$parts = array( 'claude mcp add ' . $quote( $name ), '--env WP_API_URL=' . $quote( $url ), '--env WP_API_USERNAME=' . $quote( $username ), '--env WP_API_PASSWORD=' . $quote( $password ) );
			if ( $self_signed ) {
				$parts[] = '--env NODE_TLS_REJECT_UNAUTHORIZED=' . $quote( '0' );
			}
			$parts[] = '-- npx -y @automattic/mcp-wordpress-remote@latest';
			return implode( " \\\n  ", $parts );
		}

		private static function opencode( string $name, string $url, string $username, string $password, bool $self_signed, int $options ): string {
			$environment = array(
				'WP_API_URL'      => $url,
				'WP_API_USERNAME' => $username,
				'WP_API_PASSWORD' => $password,
			);
			if ( $self_signed ) {
				$environment['NODE_TLS_REJECT_UNAUTHORIZED'] = '0';
			}
			return (string) wp_json_encode(
				array(
					'mcp' => array(
						$name => array(
							'type'        => 'local',
							'command'     => array( 'npx', '-y', '@automattic/mcp-wordpress-remote@latest' ),
							'environment' => $environment,
						),
					),
				),
				$options
			);
		}
	}
}
