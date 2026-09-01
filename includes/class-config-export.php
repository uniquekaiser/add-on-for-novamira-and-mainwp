<?php
/**
 * Direct child-site MCP configuration exports.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class Config_Export {
	/** @return array<string, array{label:string,extension:string,mime:string}> */
	public static function formats(): array {
		return array(
			'codex'          => array(
				'label'     => 'Codex (config.toml)',
				'extension' => 'toml',
				'mime'      => 'text/plain',
			),
			'claude-desktop' => array(
				'label'     => 'Claude Desktop',
				'extension' => 'json',
				'mime'      => 'application/json',
			),
			'claude-code'    => array(
				'label'     => 'Claude Code commands',
				'extension' => 'sh',
				'mime'      => 'text/x-shellscript',
			),
			'cursor'         => array(
				'label'     => 'Cursor',
				'extension' => 'json',
				'mime'      => 'application/json',
			),
			'vscode'         => array(
				'label'     => 'VS Code',
				'extension' => 'json',
				'mime'      => 'application/json',
			),
			'github-copilot' => array(
				'label'     => 'GitHub Copilot',
				'extension' => 'json',
				'mime'      => 'application/json',
			),
			'gemini-cli'     => array(
				'label'     => 'Gemini CLI',
				'extension' => 'json',
				'mime'      => 'application/json',
			),
			'zed'            => array(
				'label'     => 'Zed',
				'extension' => 'json',
				'mime'      => 'application/json',
			),
			'opencode'       => array(
				'label'     => 'OpenCode',
				'extension' => 'json',
				'mime'      => 'application/json',
			),
			'windsurf'       => array(
				'label'     => 'Windsurf',
				'extension' => 'json',
				'mime'      => 'application/json',
			),
			'cline'          => array(
				'label'     => 'Cline',
				'extension' => 'json',
				'mime'      => 'application/json',
			),
			'roo-code'       => array(
				'label'     => 'Roo Code',
				'extension' => 'json',
				'mime'      => 'application/json',
			),
			'kilo-code'      => array(
				'label'     => 'Kilo Code',
				'extension' => 'json',
				'mime'      => 'application/json',
			),
			'amazon-q'       => array(
				'label'     => 'Amazon Q',
				'extension' => 'json',
				'mime'      => 'application/json',
			),
			'antigravity'    => array(
				'label'     => 'Antigravity',
				'extension' => 'json',
				'mime'      => 'application/json',
			),
		);
	}

	/**
	 * @param array<int, array{id:int,name:string,url:string,endpoint?:string,username:string,password:string}> $sites Sites with decrypted one-time export credentials.
	 * @return array{content:string,extension:string,mime:string,count:int}|\WP_Error
	 */
	public static function build( array $sites, string $format ) {
		$formats = self::formats();
		if ( ! isset( $formats[ $format ] ) ) {
			return new \WP_Error( 'novamira_mainwp_export_format_invalid', 'Choose a supported MCP configuration format.' );
		}
		if ( empty( $sites ) ) {
			return new \WP_Error( 'novamira_mainwp_export_empty', 'No managed child-site credentials are available to export.' );
		}

		$snippets = array();
		$merged   = array();
		foreach ( $sites as $site ) {
			$name = self::server_name( $site );
			$url  = MCP_Endpoint::resolve(
				$site['url'],
				array(
					'mcp' => array( 'endpoint' => isset( $site['endpoint'] ) ? $site['endpoint'] : '' ),
				)
			);
			if ( is_wp_error( $url ) ) {
				return $url;
			}
			$configs = \Novamira_Provider_Config_Registry::build( $url, $site['username'], $site['password'], $name, false, 'mainwp-novamira-addon' );
			if ( ! isset( $configs[ $format ]['code'] ) ) {
				return new \WP_Error( 'novamira_mainwp_export_template_missing', 'The selected provider template is unavailable.' );
			}
			$code = (string) $configs[ $format ]['code'];
			if ( in_array( $format, array( 'codex', 'claude-code' ), true ) ) {
				$snippets[] = $code;
				continue;
			}
			$decoded = json_decode( $code, true );
			if ( ! is_array( $decoded ) ) {
				return new \WP_Error( 'novamira_mainwp_export_template_invalid', 'A provider template could not be combined safely.' );
			}
			$merged = array_replace_recursive( $merged, $decoded );
		}

		if ( 'codex' === $format ) {
			$content = implode( "\n\n", $snippets ) . "\n";
		} elseif ( 'claude-code' === $format ) {
			$content = "#!/usr/bin/env sh\nset -eu\n\n" . implode( "\n\n", $snippets ) . "\n";
		} else {
			$content = (string) wp_json_encode( $merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
		}

		return array(
			'content'   => $content,
			'extension' => $formats[ $format ]['extension'],
			'mime'      => $formats[ $format ]['mime'],
			'count'     => count( $sites ),
		);
	}

	/** @param array{id:int,name:string,url:string,endpoint?:string,username:string,password:string} $site */
	private static function server_name( array $site ): string {
		$host = (string) wp_parse_url( $site['url'], PHP_URL_HOST );
		$host = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', $host ) );
		$host = trim( $host, '-' );
		return 'novamira-' . ( '' !== $host ? $host : 'site' ) . '-' . $site['id'];
	}
}
