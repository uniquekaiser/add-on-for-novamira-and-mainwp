<?php
/** Small deterministic database adapter for security-state unit tests. */

trait NMM_Test_Security_Database {
	public $options = 'wp_options';
	public $last_error = '';
	public $named_locks = array();
	public $lock_unavailable = false;
	public $lock_lost = false;
	public $fail_option = '';
	public $fail_read_option = '';

	public function get_var( string $query ) {
		$this->last_error = '';
		if ( preg_match( "/SELECT GET_LOCK\('([^']+)'/", $query, $matches ) ) {
			if ( $this->lock_unavailable ) return '0';
			$this->named_locks[ $matches[1] ] = true;
			return '1';
		}
		if ( preg_match( "/SELECT IS_USED_LOCK\('([^']+)'/", $query, $matches ) ) return ! $this->lock_lost && ! empty( $this->named_locks[ $matches[1] ] ) ? '1' : '0';
		if ( preg_match( "/SELECT RELEASE_LOCK\('([^']+)'/", $query, $matches ) ) { unset( $this->named_locks[ $matches[1] ] ); return '1'; }
		if ( preg_match( "/SELECT option_value FROM .* WHERE option_name = '([^']+)'/", $query, $matches ) ) {
			if ( $matches[1] === $this->fail_read_option ) { $this->last_error = 'simulated read failure'; return null; }
			return array_key_exists( $matches[1], $GLOBALS['nmm_options'] ) ? maybe_serialize( $GLOBALS['nmm_options'][ $matches[1] ] ) : null;
		}
		return null;
	}

	public function query( string $query ) {
		$this->last_error = '';
		if ( preg_match( "/INSERT INTO .* \(option_name, option_value, autoload\) VALUES \('([^']+)', '(.*)', 'off'\) ON DUPLICATE KEY UPDATE/s", $query, $matches ) ) {
			if ( $matches[1] === $this->fail_option ) { $this->last_error = 'simulated write failure'; return false; }
			$GLOBALS['nmm_options'][ $matches[1] ] = maybe_unserialize( $matches[2] );
			return 1;
		}
		if ( preg_match( "/DELETE FROM .* WHERE option_name = '([^']+)'/", $query, $matches ) ) {
			if ( $matches[1] === $this->fail_option ) { $this->last_error = 'simulated delete failure'; return false; }
			unset( $GLOBALS['nmm_options'][ $matches[1] ] );
			return 1;
		}
		return 0;
	}
}

function maybe_serialize( $value ) { return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value; }
function maybe_unserialize( $value ) {
	if ( ! is_string( $value ) || ! preg_match( '/^(a|O|s|i|b|d|N)[:;]/', $value ) ) return $value;
	return unserialize( $value, array( 'allowed_classes' => false ) );
}
function wp_cache_delete( string $key, string $group = '' ): bool { return true; }
