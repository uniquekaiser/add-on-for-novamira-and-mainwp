<?php

declare( strict_types=1 );

/** SQL-backed confirmation behavior without WordPress's option cache. */
class NMM_Confirmation_WPDB extends NMM_Test_WPDB {
	public $options = 'wp_options';
	public $confirmation_rows = array();
	public $fail_insert = false;
	public $before_consume = null;
	public $stale_value = null;

	public function get_var( string $query ) {
		if ( false !== strpos( $query, "'nmm:" ) || false !== strpos( $query, "'novamira_mainwp_lock_" ) ) {
			return parent::get_var( $query );
		}
		if ( preg_match( "/^SELECT option_value FROM wp_options WHERE option_name = '([^']+)'$/", $query, $matches ) ) {
			return $this->stale_value ?? ( $this->confirmation_rows[ $matches[1] ] ?? null );
		}
		throw new RuntimeException( 'Unexpected confirmation SELECT: ' . $query );
	}

	public function query( string $query ) {
		if ( false !== strpos( $query, "'novamira_mainwp_lock_" ) ) {
			return parent::query( $query );
		}
		if ( preg_match( "/^INSERT INTO wp_options .* VALUES \\('([^']+)', '([^']+)', 'no'\\)$/", $query, $matches ) ) {
			if ( $this->fail_insert || isset( $this->confirmation_rows[ $matches[1] ] ) ) return false;
			$this->confirmation_rows[ $matches[1] ] = $matches[2];
			return 1;
		}
		if ( preg_match( "/^DELETE FROM wp_options WHERE option_name >= '([^']+)' AND option_name < '([^']+)'$/", $query, $matches ) ) {
			$count = 0;
			foreach ( array_keys( $this->confirmation_rows ) as $key ) {
				if ( strcmp( $key, $matches[1] ) >= 0 && strcmp( $key, $matches[2] ) < 0 ) {
					unset( $this->confirmation_rows[ $key ] );
					++$count;
				}
			}
			return $count;
		}
		if ( preg_match( "/^DELETE FROM wp_options WHERE option_name = '([^']+)' AND option_value = '([^']+)'$/", $query, $matches ) ) {
			if ( is_callable( $this->before_consume ) ) {
				$callback = $this->before_consume;
				$this->before_consume = null;
				$callback();
			}
			if ( ( $this->confirmation_rows[ $matches[1] ] ?? null ) !== $matches[2] ) return 0;
			unset( $this->confirmation_rows[ $matches[1] ] );
			return 1;
		}
		throw new RuntimeException( 'Unexpected confirmation query: ' . $query );
	}
}
