<?php

declare( strict_types=1 );

use Novamira\MainWP\Novamira_Settings;
use Novamira\MainWP\Child_Runtime;
use PHPUnit\Framework\TestCase;

final class NovamiraSettingsTest extends TestCase {
	public function test_a_partial_patch_preserves_explicit_off_and_empty_values(): void {
		$patch = array( 'approval_instructions' => '', 'hold_emails' => false );
		self::assertSame( $patch, Novamira_Settings::validate( $patch ) );
	}

	public function test_invalid_settings_are_rejected_without_coercion(): void {
		foreach ( array(
			array( 'hold_emails' => 'false' ),
			array( 'approval_level' => 'custom' ),
			array( 'license_key' => 'secret' ),
			array( 'feature' => array( 'id' => 'novamira/chat', 'enabled' => true ), 'hold_emails' => true ),
			array( 'ability_approval' => array( 'id' => 'bad ability', 'choice' => 'yes' ) ),
		) as $patch ) {
			self::assertInstanceOf( WP_Error::class, Novamira_Settings::validate( $patch ) );
		}
	}

	public function test_old_child_reports_settings_are_unsupported(): void {
		ob_start();
		eval( Child_Runtime::script( 'settings-inspect' ) );
		$result = Child_Runtime::decode( array( 'status' => 'SUCCESS', 'result' => ob_get_clean() ) );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'novamira_settings_unsupported', $result->get_error_code() );
	}

	public function test_confirmations_bind_the_selected_child_patch_and_browser_session(): void {
		require_once dirname( __DIR__ ) . '/confirmation-database.php';
		$database = $GLOBALS['wpdb'];
		$filters  = $GLOBALS['nmm_filters'];
		$sites    = NMM_Test_MainWP_DB::$sites;
		$session  = $GLOBALS['nmm_session_token'] ?? null;
		$GLOBALS['wpdb'] = new NMM_Confirmation_WPDB();
		$GLOBALS['nmm_filters'] = array();
		$GLOBALS['nmm_session_token'] = 'first-browser';
		NMM_Test_MainWP_DB::$sites = array(
			41 => (object) array( 'id' => 41 ),
			42 => (object) array( 'id' => 42 ),
			43 => (object) array( 'id' => 43, 'suspended' => true ),
		);
		$calls = array();
		$revision = str_repeat( 'a', 64 );
		add_filter(
			'mainwp_fetchurlauthed',
			static function ( $plugin, $key, $id, $what, $params ) use ( &$calls, $revision ) {
				$calls[] = nmm_child_action( $params )['action'];
				return nmm_child_response( $params, array( 'revision' => $revision ) );
			},
			10,
			5
		);
		try {
			$patch = array( 'approval_level' => 'most' );
			$preview = Novamira_Settings::request( 41, array( 'step' => 'preview', 'patch' => $patch ) );
			self::assertIsArray( $preview );
			$apply = array( 'step' => 'apply', 'patch' => $patch, 'confirmation_token' => $preview['confirmation_token'], 'revision' => $revision );
			self::assertInstanceOf( WP_Error::class, Novamira_Settings::request( 42, $apply ) );
			self::assertInstanceOf( WP_Error::class, Novamira_Settings::request( 43, $apply ) );
			self::assertInstanceOf( WP_Error::class, Novamira_Settings::request( 41, array( 'patch' => array( 'approval_level' => 'skip' ) ) + $apply ) );
			$GLOBALS['nmm_session_token'] = 'different-browser';
			self::assertInstanceOf( WP_Error::class, Novamira_Settings::request( 41, $apply ) );
			self::assertSame( array( 'settings-preview' ), $calls );
			$GLOBALS['nmm_session_token'] = 'first-browser';
			self::assertIsArray( Novamira_Settings::request( 41, $apply ) );
			self::assertInstanceOf( WP_Error::class, Novamira_Settings::request( 41, $apply ) );
			self::assertSame( array( 'settings-preview', 'settings-apply' ), $calls );
		} finally {
			$GLOBALS['wpdb'] = $database;
			$GLOBALS['nmm_filters'] = $filters;
			$GLOBALS['nmm_session_token'] = $session;
			NMM_Test_MainWP_DB::$sites = $sites;
		}
	}
}
