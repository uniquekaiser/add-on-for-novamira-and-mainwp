<?php

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase {
	public function test_registry_covers_supported_clients_and_builds_gateway_credentials(): void {
		$configs = Novamira_Provider_Config_Registry::build( 'https://dashboard.test/wp-json/mcp/mainwp', 'manager', 'secret', 'mainwp-novamira', false, 'mainwp-novamira-addon' );
		foreach ( array( 'claude-code', 'claude-desktop', 'codex', 'cursor', 'vscode', 'github-copilot', 'gemini-cli', 'zed', 'opencode' ) as $client ) {
			self::assertArrayHasKey( $client, $configs );
		}
		self::assertStringContainsString( 'https://dashboard.test/wp-json/mcp/mainwp', $configs['codex']['code'] );
		self::assertStringContainsString( 'secret', $configs['claude-desktop']['code'] );
	}

	public function test_registry_is_owned_by_the_addon_not_injected_into_free(): void {
		$dashboard = dirname( __DIR__, 2 ) . '/includes/provider-config-registry.php';
		$free      = dirname( __DIR__, 3 ) . '/novamira/includes/provider-config-registry.php';
		$contract  = dirname( __DIR__, 3 ) . '/novamira/includes/mainwp-child-integration.php';
		self::assertFileExists( $dashboard );
		self::assertFileDoesNotExist( $free );
		self::assertFileDoesNotExist( $contract );
	}
}
