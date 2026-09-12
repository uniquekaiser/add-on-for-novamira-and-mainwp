<?php

declare( strict_types=1 );

use Novamira\MainWP\Confirmation;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/confirmation-database.php';

final class ConfirmationTest extends TestCase {
	private $database;
	private $previous_database;

	protected function setUp(): void {
		$this->previous_database = $GLOBALS['wpdb'];
		$this->database = new NMM_Confirmation_WPDB();
		$GLOBALS['wpdb'] = $this->database;
		$GLOBALS['nmm_capabilities'] = array();
		$GLOBALS['nmm_current_user_id'] = 7;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_database;
		$GLOBALS['nmm_capabilities'] = array();
		$GLOBALS['nmm_current_user_id'] = 7;
	}

	public function test_random_tokens_store_no_plaintext_token_or_arguments_and_expire_in_five_minutes(): void {
		$first = Confirmation::issue( 'ability', array( 1 ), array( 'license_key' => 'secret-license-value' ) );
		$second = Confirmation::issue( 'ability', array( 1 ), array( 'license_key' => 'secret-license-value' ) );
		self::assertSame( 300, $first['expires_in'] );
		self::assertEqualsWithDelta( time() + 300, $first['expires_at'], 1 );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}\.[0-9]{10}$/', $first['confirmation_token'] );
		self::assertNotSame( $first['confirmation_token'], $second['confirmation_token'] );
		$stored = json_encode( $this->database->confirmation_rows );
		self::assertStringNotContainsString( $first['confirmation_token'], $stored );
		self::assertStringNotContainsString( 'secret-license-value', $stored );
		self::assertStringNotContainsString( 'license_key', $stored );
	}

	public function test_canonical_objects_and_exact_site_sets_allow_only_one_consumption(): void {
		$input = array( 'b' => array( 'z' => 3, 'a' => 1 ), 'a' => array( 'second', 'first' ) );
		$token = Confirmation::issue( 'ability', array( 2, 1 ), $input )['confirmation_token'];
		$reordered = array( 'a' => array( 'second', 'first' ), 'b' => array( 'a' => 1, 'z' => 3 ) );
		self::assertTrue( Confirmation::consume( $token, 'ability', array( 1, 2 ), $reordered ) );
		self::assertInstanceOf( WP_Error::class, Confirmation::consume( $token, 'ability', array( 1, 2 ), $reordered ) );
	}

	public function test_wrong_user_ability_site_set_and_arguments_never_authorize(): void {
		$input = array( 'arguments' => array( 'ids' => array( 1, 2 ), 'force' => true ) );
		$token = Confirmation::issue( 'ability', array( 1, 2 ), $input )['confirmation_token'];
		$GLOBALS['nmm_current_user_id'] = 8;
		self::assertInstanceOf( WP_Error::class, Confirmation::consume( $token, 'ability', array( 1, 2 ), $input ) );
		$GLOBALS['nmm_current_user_id'] = 7;
		self::assertInstanceOf( WP_Error::class, Confirmation::consume( $token, 'other-ability', array( 1, 2 ), $input ) );
		self::assertInstanceOf( WP_Error::class, Confirmation::consume( $token, 'ability', array( 1 ), $input ) );
		self::assertInstanceOf( WP_Error::class, Confirmation::consume( $token, 'ability', array( 1, 3 ), $input ) );
		self::assertInstanceOf( WP_Error::class, Confirmation::consume( $token, 'ability', array( 1, 2, 3 ), $input ) );
		self::assertInstanceOf( WP_Error::class, Confirmation::consume( $token, 'ability', array( 1, 2 ), array( 'arguments' => array( 'ids' => array( 2, 1 ), 'force' => true ) ) ) );
		self::assertInstanceOf( WP_Error::class, Confirmation::consume( $token, 'ability', array( 1, 2 ), array( 'arguments' => array( 'ids' => array( 1, 2 ), 'force' => 1 ) ) ) );
		self::assertTrue( Confirmation::consume( $token, 'ability', array( 1, 2 ), $input ) );
	}

	public function test_atomic_delete_rejects_a_racing_consumer_even_after_a_successful_read(): void {
		$token = Confirmation::issue( 'ability', array( 1 ), array() )['confirmation_token'];
		$winner = null;
		$this->database->before_consume = static function () use ( $token, &$winner ): void {
			$winner = Confirmation::consume( $token, 'ability', array( 1 ), array() );
		};
		$loser = Confirmation::consume( $token, 'ability', array( 1 ), array() );
		self::assertTrue( $winner );
		self::assertInstanceOf( WP_Error::class, $loser );
		self::assertSame( array(), $this->database->confirmation_rows );
	}

	public function test_stale_reads_never_authorize_replay(): void {
		$token = Confirmation::issue( 'ability', array( 1 ), array() )['confirmation_token'];
		$this->database->stale_value = reset( $this->database->confirmation_rows );
		self::assertTrue( Confirmation::consume( $token, 'ability', array( 1 ), array() ) );
		self::assertInstanceOf( WP_Error::class, Confirmation::consume( $token, 'ability', array( 1 ), array() ) );
	}

	public function test_expired_tokens_are_rejected_and_removed_by_next_preview(): void {
		$issued = Confirmation::issue( 'ability', array( 1 ), array() );
		$record = json_decode( reset( $this->database->confirmation_rows ), true );
		$expires = time() - 1;
		$record['expires_at'] = $expires;
		$token = substr( $issued['confirmation_token'], 0, 64 ) . '.' . $expires;
		$expired_key = 'novamira_mainwp_confirm_' . $expires . '_' . hash( 'sha256', $token );
		$this->database->confirmation_rows[ $expired_key ] = json_encode( $record );
		$this->database->confirmation_rows['other_plugin_option'] = 'retained';
		self::assertInstanceOf( WP_Error::class, Confirmation::consume( $token, 'ability', array( 1 ), array() ) );
		Confirmation::issue( 'ability', array( 1 ), array() );
		self::assertArrayNotHasKey( $expired_key, $this->database->confirmation_rows );
		self::assertSame( 'retained', $this->database->confirmation_rows['other_plugin_option'] );
	}

	public function test_storage_failure_and_denied_capabilities_fail_closed(): void {
		$this->database->fail_insert = true;
		self::assertInstanceOf( WP_Error::class, Confirmation::issue( 'ability', array( 1 ), array() ) );
		$this->database->fail_insert = false;
		$token = Confirmation::issue( 'ability', array( 1 ), array() )['confirmation_token'];
		$GLOBALS['nmm_capabilities']['manage_options'] = false;
		self::assertInstanceOf( WP_Error::class, Confirmation::issue( 'ability', array( 1 ), array() ) );
		self::assertInstanceOf( WP_Error::class, Confirmation::consume( $token, 'ability', array( 1 ), array() ) );
		$GLOBALS['nmm_capabilities'] = array();
		$GLOBALS['nmm_current_user_id'] = 0;
		self::assertInstanceOf( WP_Error::class, Confirmation::issue( 'ability', array( 1 ), array() ) );
	}

	public function test_non_json_arguments_and_non_integer_sites_fail_closed(): void {
		self::assertInstanceOf( WP_Error::class, Confirmation::issue( 'ability', array( '1' ), array() ) );
		self::assertInstanceOf( WP_Error::class, Confirmation::issue( 'ability', array( 0 ), array() ) );
		self::assertInstanceOf( WP_Error::class, Confirmation::issue( 'ability', array(), array() ) );
		self::assertInstanceOf( WP_Error::class, Confirmation::issue( 'ability', array( 1 ), array( 'invalid' => NAN ) ) );
		self::assertInstanceOf( WP_Error::class, Confirmation::issue( 'ability', array( 1 ), array( 'invalid' => "\xC3\x28" ) ) );
		self::assertInstanceOf( WP_Error::class, Confirmation::issue( 'ability', array( 1 ), array( 'invalid' => new DateTimeImmutable() ) ) );
	}
}
