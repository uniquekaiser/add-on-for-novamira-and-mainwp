<?php

declare( strict_types=1 );

use Novamira\MainWP\Child_Runtime;
use PHPUnit\Framework\TestCase;

final class ChildRuntimeTest extends TestCase {
	public function test_script_embeds_a_fixed_one_shot_operation_without_persistent_child_code(): void {
		$script  = Child_Runtime::script( 'credential-create', array( 'username' => 'admin', 'label' => 'Novamira MainWP' ) );
		$source  = nmm_child_source( array( 'code' => $script ) );
		$payload = nmm_child_action( array( 'code' => $script ) );

		self::assertSame( 'credential-create', $payload['action'] );
		self::assertSame( 'admin', $payload['params']['username'] );
		self::assertStringNotContainsString( '__NMM_PAYLOAD__', $script );
		self::assertStringNotContainsString( 'save_snippet', $script );
		self::assertStringNotContainsString( 'mainwp_child_extra_execution', $script );
		self::assertStringContainsString( 'mainwp_child_connected_admin', $source );
		self::assertStringContainsString( "rest_url( 'mcp/novamira' )", $source );
		self::assertStringContainsString( "add_query_arg( 'rest_route', '/mcp/novamira', site_url( '/' ) )", $source );
		self::assertStringContainsString( "isset( \$mcp_routes['/mcp/novamira'] )", $source );
		self::assertStringContainsString( 'novamira_is_mcp_adapter_available', $source );
		self::assertStringNotContainsString( 'mainwp_child_connected_admin', $script );
		self::assertStringContainsString( 'base64_decode', $script );
	}

	public function test_transport_wrapper_executes_the_fixed_source_without_parse_errors(): void {
		$script = Child_Runtime::script( 'unknown' );

		ob_start();
		eval( $script );
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'NOVAMIRA_MAINWP_RESULT:', $output );
	}

	public function test_decoder_normalizes_success_and_scoped_child_errors(): void {
		$params  = array( 'code' => Child_Runtime::script( 'status' ) );
		$success = Child_Runtime::decode( nmm_child_response( $params, array( 'free' => array( 'active' => true ) ) ) );
		$failed  = Child_Runtime::decode( nmm_child_response( $params, array(), false, 'production_not_approved', 'Approval required.' ) );

		self::assertTrue( $success['free']['active'] );
		self::assertSame( 'production_not_approved', $failed->get_error_code() );
		self::assertSame( 'Approval required.', $failed->get_error_message() );
	}

	public function test_snapshot_does_not_enable_ai_and_open_requires_unchanged_snapshot(): void {
		if ( ! defined( 'NOVAMIRA_VERSION' ) ) define( 'NOVAMIRA_VERSION', 'test' );
		$GLOBALS['nmm_options'] = array( 'novamira_ai_abilities_enabled' => '0' );
		$snapshot = $this->execute_ai( 'ai-snapshot' );
		self::assertSame( '0', get_option( 'novamira_ai_abilities_enabled' ) );
		self::assertSame( $snapshot['restore']['missing'], $snapshot['restore']['domain'] );
		$params = array( 'restore' => $snapshot['restore'], 'expires' => time() + 300, 'production_allowed' => true );
		update_option( 'novamira_ai_abilities_enabled', 'manual-new-setting' );
		self::assertSame( 'restore_state_changed', $this->execute_ai( 'ai-open', $params )->get_error_code() );
		self::assertSame( 'manual-new-setting', get_option( 'novamira_ai_abilities_enabled' ) );
	}

	public function test_open_and_restore_preserve_original_missing_options(): void {
		if ( ! defined( 'NOVAMIRA_VERSION' ) ) define( 'NOVAMIRA_VERSION', 'test' );
		$GLOBALS['nmm_options'] = array();
		$snapshot = $this->execute_ai( 'ai-snapshot' );
		$opened = $this->execute_ai( 'ai-open', array( 'restore' => $snapshot['restore'], 'expires' => time() + 300, 'production_allowed' => true ) );
		self::assertTrue( $opened['changed'] );
		self::assertSame( '1', get_option( 'novamira_ai_abilities_enabled' ) );
		self::assertTrue( $this->execute_ai( 'ai-restore', $snapshot )['restored'] );
		self::assertFalse( get_option( 'novamira_ai_abilities_enabled' ) );
		self::assertFalse( get_option( 'novamira_ai_abilities_domain' ) );
	}

	public function test_expired_open_is_rejected_without_mutation(): void {
		if ( ! defined( 'NOVAMIRA_VERSION' ) ) define( 'NOVAMIRA_VERSION', 'test' );
		$GLOBALS['nmm_options'] = array();
		$snapshot = $this->execute_ai( 'ai-snapshot' );
		$result = $this->execute_ai( 'ai-open', array( 'restore' => $snapshot['restore'], 'expires' => time() - 1, 'production_allowed' => true ) );
		self::assertSame( 'access_window_expired', $result->get_error_code() );
		self::assertSame( array(), $GLOBALS['nmm_options'] );
	}

	private function execute_ai( string $action, array $params = array() ) {
		ob_start();
		eval( Child_Runtime::script( $action, $params ) );
		return Child_Runtime::decode( array( 'status' => 'SUCCESS', 'result' => (string) ob_get_clean() ) );
	}
}
