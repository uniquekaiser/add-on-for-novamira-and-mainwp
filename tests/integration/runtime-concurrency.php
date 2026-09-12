<?php
/**
 * Real MySQL, independent PHP process lease-race and crash-recovery test.
 *
 * Run with mysqli enabled and NMM_TEST_DB_HOST, NMM_TEST_DB_PORT,
 * NMM_TEST_DB_USER, NMM_TEST_DB_PASSWORD set for a disposable local MySQL.
 * Creates and drops only a randomly named nmm_security_* database.
 * Never loads a real WordPress site or contacts any managed child.
 */

declare( strict_types=1 );

require dirname( __DIR__ ) . '/bootstrap.php';

use Novamira\MainWP\Database_Lock;
use Novamira\MainWP\Runtime_Access;

final class NMM_Integration_DB extends NMM_Test_WPDB {
	public $connection;
	public function __construct( mysqli $connection ) { $this->connection = $connection; }
	public function prepare( string $query, ...$args ): string {
		$index = 0;
		return (string) preg_replace_callback( '/%[isd]/', function ( array $match ) use ( $args, &$index ): string {
			$value = $args[ $index++ ];
			if ( '%d' === $match[0] ) return (string) (int) $value;
			if ( '%i' === $match[0] ) return '`' . str_replace( '`', '``', (string) $value ) . '`';
			return "'" . $this->connection->real_escape_string( (string) $value ) . "'";
		}, $query );
	}
	public function get_var( string $query ) {
		$this->last_error = '';
		try {
			$result = $this->connection->query( $query );
			return $result instanceof mysqli_result ? ( $result->fetch_row()[0] ?? null ) : null;
		} catch ( mysqli_sql_exception $error ) { $this->last_error = $error->getMessage(); return null; }
	}
	public function query( string $query ) {
		$this->last_error = '';
		if ( false !== strpos( $query, 'novamira_mainwp_audit' ) ) return 0;
		try { $this->connection->query( $query ); return $this->connection->affected_rows; }
		catch ( mysqli_sql_exception $error ) { $this->last_error = $error->getMessage(); return false; }
	}
}

function nmm_assert( bool $condition, string $message ): void {
	if ( ! $condition ) throw new RuntimeException( $message );
}

function nmm_wait_file( string $file ): void {
	$deadline = microtime( true ) + 20;
	while ( ! is_file( $file ) ) {
		if ( microtime( true ) > $deadline ) throw new RuntimeException( 'Timed out waiting for ' . basename( $file ) );
		usleep( 10000 );
	}
}

function nmm_test_connect( string $database = '' ): mysqli {
	return new mysqli( getenv( 'NMM_TEST_DB_HOST' ) ?: '127.0.0.1', getenv( 'NMM_TEST_DB_USER' ) ?: 'root', getenv( 'NMM_TEST_DB_PASSWORD' ) ?: '', $database, (int) ( getenv( 'NMM_TEST_DB_PORT' ) ?: 3306 ) );
}

function nmm_install_child_mock( NMM_Integration_DB $database ): void {
	$GLOBALS['wpdb'] = $database;
	NMM_Test_MainWP_DB::$sites = array(
		9 => (object) array( 'id' => 9, 'name' => 'Test nine', 'url' => 'https://nine.test', 'suspended' => 0 ),
		10 => (object) array( 'id' => 10, 'name' => 'Test ten', 'url' => 'https://ten.test', 'suspended' => 0 ),
	);
	add_filter( 'mainwp_fetchurlauthed', static function ( $unused, $key, int $site, $what, array $params ) use ( $database ) {
		$payload = nmm_child_action( $params );
		$action = $payload['action'];
		$child = Database_Lock::read_option( 'nmm_child_' . $site, array( 'enabled' => '0', 'domain' => 'nmm-test-missing' ) );
		$database->query( $database->prepare( 'INSERT INTO events (action, site_id) VALUES (%s, %d)', $action, $site ) );
		if ( 'ai-snapshot' === $action ) {
			// Keep the first critical section open long enough for the competing PHP process to arrive.
			usleep( 300000 );
			return nmm_child_response( $params, array( 'restore' => array( 'missing' => 'nmm-test-missing', 'enabled' => $child['enabled'], 'domain' => $child['domain'] ) ) );
		}
		if ( 'ai-open' === $action ) {
			$entry = Database_Lock::read_option( 'novamira_mainwp_runtime_state' )[ (string) $site ];
			nmm_assert( true === $entry['opening'] && '0' === $entry['restore']['enabled'], 'Original restore snapshot must be durable before opening.' );
			nmm_assert( $entry['restore'] === $payload['params']['restore'], 'The remote open must use the journaled snapshot.' );
			nmm_assert( Database_Lock::write_option( 'nmm_child_' . $site, array( 'enabled' => '1', 'domain' => 'child.test' ) ), 'Child mock storage failed.' );
			if ( ! empty( $GLOBALS['nmm_crash_after_open'] ) ) exit( 23 );
			return nmm_child_response( $params, array( 'changed' => true ) );
		}
		if ( 'ai-restore' === $action ) {
			$restore = $payload['params']['restore'];
			nmm_assert( '0' === $restore['enabled'], 'Cleanup must preserve the original disabled snapshot.' );
			nmm_assert( Database_Lock::write_option( 'nmm_child_' . $site, array( 'enabled' => $restore['enabled'], 'domain' => $restore['domain'] ) ), 'Child restore failed.' );
			return nmm_child_response( $params, array( 'restored' => true ) );
		}
		throw new RuntimeException( 'Unexpected child operation.' );
	}, 10, 6 );
}

function nmm_start_worker( string $database, string $directory, string $worker, int $site, bool $crash = false ): array {
	$command = array( PHP_BINARY );
	if ( php_ini_loaded_file() ) { $command[] = '-c'; $command[] = php_ini_loaded_file(); }
	$command[] = '-d'; $command[] = 'extension_dir=' . ini_get( 'extension_dir' );
	$command[] = '-d'; $command[] = 'extension=' . ( '\\' === DIRECTORY_SEPARATOR ? 'php_mysqli.dll' : 'mysqli' );
	$command = array_merge( $command, array( __FILE__, '--worker', $database, $directory, $worker, (string) $site, $crash ? 'crash' : 'normal' ) );
	$log = $directory . '/' . $worker . '.log';
	$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $log, 'a' ), 2 => array( 'file', $log, 'a' ) ), $pipes );
	if ( ! is_resource( $process ) ) throw new RuntimeException( 'Could not start an independent PHP process.' );
	fclose( $pipes[0] );
	return array( $process, $log );
}

if ( '--worker' === ( $argv[1] ?? '' ) ) {
	list( , , $database, $directory, $worker, $site, $mode ) = $argv;
	nmm_install_child_mock( new NMM_Integration_DB( nmm_test_connect( $database ) ) );
	$GLOBALS['nmm_crash_after_open'] = 'crash' === $mode;
	file_put_contents( $directory . '/' . $worker . '.ready', 'ready' );
	nmm_wait_file( $directory . '/acquire.go' );
	$lease = Runtime_Access::acquire( (int) $site );
	nmm_assert( is_array( $lease ), 'Worker acquisition failed: ' . ( is_wp_error( $lease ) ? $lease->get_error_code() : 'invalid' ) );
	file_put_contents( $directory . '/' . $worker . '.acquired', json_encode( $lease ) );
	nmm_wait_file( $directory . '/release.go' );
	$released = Runtime_Access::release( (int) $site, $lease['token'] );
	nmm_assert( is_array( $released ), 'Worker release failed.' );
	file_put_contents( $directory . '/' . $worker . '.released', json_encode( $released ) );
	exit( 0 );
}

if ( ! extension_loaded( 'mysqli' ) ) { fwrite( STDERR, "Enable mysqli to run the real database concurrency tests.\n" ); exit( 2 ); }
$database_name = 'nmm_security_' . bin2hex( random_bytes( 8 ) );
$directory = sys_get_temp_dir() . '/nmm-runtime-' . bin2hex( random_bytes( 8 ) );
$connection = nmm_test_connect();
$processes = array();
mkdir( $directory, 0700 );
try {
	$connection->query( 'CREATE DATABASE `' . $database_name . '`' );
	$connection->select_db( $database_name );
	$connection->query( "CREATE TABLE wp_options (option_id bigint unsigned AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) UNIQUE NOT NULL, option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT 'off') ENGINE=InnoDB" );
	$connection->query( 'CREATE TABLE events (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, action varchar(32), site_id int) ENGINE=InnoDB' );
	$database = new NMM_Integration_DB( $connection );
	nmm_install_child_mock( $database );
	foreach ( array( 'same-site' => array( 9, 9 ), 'different-sites' => array( 9, 10 ) ) as $scenario => $sites ) {
		$round = $directory . '/' . $scenario;
		mkdir( $round, 0700 );
		$connection->query( 'TRUNCATE events' );
		$processes = array( nmm_start_worker( $database_name, $round, 'a', $sites[0] ), nmm_start_worker( $database_name, $round, 'b', $sites[1] ) );
		nmm_wait_file( $round . '/a.ready' ); nmm_wait_file( $round . '/b.ready' );
		file_put_contents( $round . '/acquire.go', 'go' );
		nmm_wait_file( $round . '/a.acquired' ); nmm_wait_file( $round . '/b.acquired' );
		$state = Database_Lock::read_option( 'novamira_mainwp_runtime_state' );
		$expected_windows = count( array_unique( $sites ) );
		nmm_assert( is_array( $state ) && count( $state ) === $expected_windows, 'A site state was lost under concurrent acquisition.' );
		nmm_assert( 2 === array_sum( array_map( static function ( array $entry ): int { return count( $entry['leases'] ); }, $state ) ), 'A concurrent lease was lost.' );
		nmm_assert( $expected_windows === (int) $database->get_var( "SELECT COUNT(*) FROM events WHERE action='ai-open'" ), 'AI opened more than once for an overlapping site window.' );
		file_put_contents( $round . '/release.go', 'go' );
		nmm_wait_file( $round . '/a.released' ); nmm_wait_file( $round . '/b.released' );
		foreach ( $processes as $process ) nmm_assert( 0 === proc_close( $process[0] ), 'Worker failed: ' . file_get_contents( $process[1] ) );
		$processes = array();
		nmm_assert( array() === Database_Lock::read_option( 'novamira_mainwp_runtime_state', array() ), 'Final release did not clear all leases.' );
		nmm_assert( $expected_windows === (int) $database->get_var( "SELECT COUNT(*) FROM events WHERE action='ai-restore'" ), 'Final release must restore each site exactly once.' );
		foreach ( array_unique( $sites ) as $site ) nmm_assert( '0' === Database_Lock::read_option( 'nmm_child_' . $site )['enabled'], 'AI remained enabled after release.' );
		echo 'PASS: simultaneous ' . $scenario . " acquisitions and releases\n";
	}
	$round = $directory . '/crash'; mkdir( $round, 0700 );
	$process = nmm_start_worker( $database_name, $round, 'crash', 9, true );
	$processes = array( $process );
	nmm_wait_file( $round . '/crash.ready' ); file_put_contents( $round . '/acquire.go', 'go' );
	nmm_assert( 23 === proc_close( $process[0] ), 'Expected the worker to terminate immediately after AI opened.' );
	$processes = array();
	$lock = Database_Lock::acquire( 'runtime-state' );
	nmm_assert( $lock instanceof Database_Lock, 'Process death must release its database lock.' );
	$state = Database_Lock::read_option( 'novamira_mainwp_runtime_state' );
	nmm_assert( true === $state['9']['opening'] && '0' === $state['9']['restore']['enabled'], 'The crash lost its original durable snapshot.' );
	$state['9']['expires'] = time() - 1;
	$state['9']['leases'] = array_fill_keys( array_keys( $state['9']['leases'] ), time() - 1 );
	nmm_assert( Database_Lock::write_option( 'novamira_mainwp_runtime_state', $state ), 'Could not simulate lease expiry.' );
	$lock->release();
	Runtime_Access::recover_expired();
	nmm_assert( array() === Database_Lock::read_option( 'novamira_mainwp_runtime_state', array() ), 'Crash recovery did not clear the journal.' );
	nmm_assert( '0' === Database_Lock::read_option( 'nmm_child_9' )['enabled'], 'Crash recovery did not disable AI.' );
	echo "PASS: abrupt process death after opening retains snapshot and recovers after expiry\n";
} finally {
	foreach ( $processes as $process ) { if ( is_resource( $process[0] ) ) { proc_terminate( $process[0] ); proc_close( $process[0] ); } }
	if ( preg_match( '/^nmm_security_[a-f0-9]{16}$/', $database_name ) ) $connection->query( 'DROP DATABASE IF EXISTS `' . $database_name . '`' );
	// Keep diagnostic files on failures; all database changes were isolated and removed.
}
