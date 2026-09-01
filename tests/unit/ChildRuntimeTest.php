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
}
