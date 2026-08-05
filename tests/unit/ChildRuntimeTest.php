<?php

declare( strict_types=1 );

use Novamira\MainWP\Child_Runtime;
use PHPUnit\Framework\TestCase;

final class ChildRuntimeTest extends TestCase {
	public function test_script_embeds_a_fixed_one_shot_operation_without_persistent_child_code(): void {
		$script  = Child_Runtime::script( 'credential-create', array( 'username' => 'admin', 'label' => 'Novamira MainWP' ) );
		$payload = nmm_child_action( array( 'code' => $script ) );

		self::assertSame( 'credential-create', $payload['action'] );
		self::assertSame( 'admin', $payload['params']['username'] );
		self::assertStringNotContainsString( '__NMM_PAYLOAD__', $script );
		self::assertStringNotContainsString( 'save_snippet', $script );
		self::assertStringNotContainsString( 'mainwp_child_extra_execution', $script );
		self::assertStringContainsString( 'mainwp_child_connected_admin', $script );
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
