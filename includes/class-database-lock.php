<?php
/**
 * Connection-owned database locks and uncached durable option storage.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks and security state must bypass option and object caches.
final class Database_Lock {
	private const TTL = 60;
	private $name;
	private $owner;
	private $option;

	private function __construct( string $name, string $owner ) {
		$this->name   = $name;
		$this->owner  = $owner;
		$this->option = 'novamira_mainwp_lock_' . $name;
	}

	/** @return self|\WP_Error */
	public static function acquire( string $scope ) {
		global $wpdb;
		$name = self::lock_name( $scope );
		try {
			$owner = bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'novamira_mainwp_lock_random_failed', 'A secure lock owner could not be generated.' );
		}
		// A time-based option alone could be stolen while a slow child call is still running.
		// GET_LOCK stays exclusive until release or connection loss, even after metadata expiry.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $name ) ) ) {
			return new \WP_Error( 'novamira_mainwp_state_locked', 'Security state is busy or database locking is unavailable. Retry shortly.' );
		}
		$lock = new self( $name, $owner );
		if ( ! $lock->refresh() ) {
			$lock->release();
			return new \WP_Error( 'novamira_mainwp_lock_failed', 'The security state lock could not be persisted.' );
		}
		return $lock;
	}

	public function protects( string $scope ): bool {
		return hash_equals( $this->name, self::lock_name( $scope ) ) && $this->refresh();
	}

	private static function lock_name( string $scope ): string {
		global $wpdb;
		$database = defined( 'DB_NAME' ) ? DB_NAME : '';
		return 'nmm:' . substr( hash( 'sha256', $database . '|' . $wpdb->options . '|' . $scope ), 0, 55 );
	}

	/** Refuse further writes after a database reconnect has lost our connection-owned lock. */
	public function refresh(): bool {
		global $wpdb;
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $this->name ) ) ) {
			return false;
		}
		return self::write_option(
			$this->option,
			array(
				'owner'   => $this->owner,
				'expires' => time() + self::TTL,
			)
		);
	}

	public function release(): void {
		global $wpdb;
		if ( '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $this->name ) ) ) {
			$stored = self::read_option( $this->option, array() );
			if ( is_array( $stored ) && ( $stored['owner'] ?? '' ) === $this->owner ) {
				self::remove_option( $this->option );
			}
		}
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name ) );
	}

	/** @return mixed */
	public static function read_option( string $name, $default_value = false ) {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
		if ( '' !== $wpdb->last_error ) {
			return new \WP_Error( 'novamira_mainwp_state_read_failed', 'Security state could not be read from the database.' );
		}
		return null === $value ? $default_value : maybe_unserialize( $value );
	}

	/** Caller must hold the lock protecting this option's complete state. */
	public static function write_option( string $name, $value ): bool {
		global $wpdb;
		$encoded = maybe_serialize( $value );
		$result  = $wpdb->query( $wpdb->prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'off'", $wpdb->options, $name, $encoded ) );
		self::invalidate( $name );
		return false !== $result && self::read_option( $name ) === $value;
	}

	public static function remove_option( string $name ): bool {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
		self::invalidate( $name );
		return false !== $result && null === self::read_option( $name, null );
	}

	private static function invalidate( string $name ): void {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
