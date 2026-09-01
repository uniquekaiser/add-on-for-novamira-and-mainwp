<?php

declare( strict_types=1 );

use Novamira\MainWP\Config_Export;
use PHPUnit\Framework\TestCase;

final class ConfigExportTest extends TestCase {
	/** @return array<int,array{id:int,name:string,url:string,endpoint?:string,username:string,password:string}> */
	private function sites(): array {
		return array(
			array(
				'id'       => 40,
				'name'     => 'Herbivore',
				'url'      => 'https://herbivoreprotein.co/',
				'username' => 'manager',
				'password' => 'secret-one',
			),
			array(
				'id'       => 77,
				'name'     => 'Plugin Repo',
				'url'      => 'https://synergetic-col.com/pluginrepo/',
				'endpoint' => 'https://synergetic-col.com/pluginrepo/?rest_route=/mcp/novamira',
				'username' => 'admin',
				'password' => 'secret-two',
			),
		);
	}

	public function test_codex_export_combines_unique_direct_child_servers(): void {
		$export = Config_Export::build( $this->sites(), 'codex' );
		self::assertFalse( is_wp_error( $export ) );
		self::assertSame( 2, $export['count'] );
		self::assertSame( 'toml', $export['extension'] );
		self::assertStringContainsString( '[mcp_servers.novamira-herbivoreprotein-co-40]', $export['content'] );
		self::assertStringContainsString( '[mcp_servers.novamira-synergetic-col-com-77]', $export['content'] );
		self::assertStringContainsString( 'https://synergetic-col.com/pluginrepo/?rest_route=/mcp/novamira', $export['content'] );
		self::assertStringNotContainsString( 'https://synergetic-col.com/pluginrepo/wp-json/mcp/novamira', $export['content'] );
		self::assertStringContainsString( 'secret-two', $export['content'] );
	}

	public function test_json_export_combines_provider_server_map(): void {
		$export = Config_Export::build( $this->sites(), 'cursor' );
		self::assertFalse( is_wp_error( $export ) );
		$decoded = json_decode( $export['content'], true );
		self::assertIsArray( $decoded );
		self::assertCount( 2, $decoded['mcpServers'] );
		self::assertSame( 'secret-one', $decoded['mcpServers']['novamira-herbivoreprotein-co-40']['env']['WP_API_PASSWORD'] );
	}

	public function test_unknown_export_format_fails_closed(): void {
		$export = Config_Export::build( $this->sites(), 'unknown-client' );
		self::assertTrue( is_wp_error( $export ) );
		self::assertSame( 'novamira_mainwp_export_format_invalid', $export->get_error_code() );
	}
}
