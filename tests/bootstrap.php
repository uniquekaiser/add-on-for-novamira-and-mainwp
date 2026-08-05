<?php
/** PHPUnit bootstrap with small WordPress stubs. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/tmp-wordpress/' );
define( 'NOVAMIRA_MAINWP_VERSION', '0.4.0' );
define( 'NOVAMIRA_MAINWP_DIR', dirname( __DIR__ ) . '/' );
define( 'NOVAMIRA_MAINWP_FILE', dirname( __DIR__ ) . '/mainwp-novamira-addon.php' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'AUTH_KEY', 'unit-test-auth-key' );
define( 'AUTH_SALT', 'unit-test-auth-salt' );

$GLOBALS['nmm_options']             = array();
$GLOBALS['nmm_filters']             = array();
$GLOBALS['nmm_actions']             = array();
$GLOBALS['nmm_abilities']           = array();
$GLOBALS['nmm_uuid']                = 0;
$GLOBALS['nmm_manual_enabled']      = false;
$GLOBALS['nmm_looks_production']    = false;
$GLOBALS['nmm_ability_rules']       = array();
$GLOBALS['nmm_http_handler']        = null;
$GLOBALS['nmm_http_deletes']        = array();
$GLOBALS['nmm_scheduled']           = array();

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( string $code = '', string $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class WP_User { public $ID = 1; public $user_login = 'admin'; }
class WP_Application_Passwords {
	public static $records = array();
	public static function create_new_application_password( int $user_id, array $args ) {
		$uuid = wp_generate_uuid4();
		$item = array( 'uuid' => $uuid, 'name' => $args['name'], 'created' => time() );
		self::$records[ $user_id ][ $uuid ] = $item;
		return array( 'plain-' . $uuid, $item );
	}
	public static function delete_application_password( int $user_id, string $uuid ) { $exists = isset( self::$records[ $user_id ][ $uuid ] ); unset( self::$records[ $user_id ][ $uuid ] ); return $exists; }
	public static function get_user_application_password( int $user_id, string $uuid ) { return self::$records[ $user_id ][ $uuid ] ?? null; }
}
class NMM_Test_WPDB {
	public $prefix = 'wp_';
	public $last_insert = array();
	public $site_records = array();
	public function insert( string $table, array $data ) { $this->last_insert = compact( 'table', 'data' ); return 1; }
	public function prepare( string $query, ...$args ): string { return (string) vsprintf( str_replace( array( '%d', '%s', '%i' ), array( '%u', "'%s'", '`%s`' ), $query ), $args ); }
	public function get_row( string $query, string $output = ARRAY_A ) { preg_match( '/site_id\s*=\s*(\d+)/', $query, $matches ); $id = isset( $matches[1] ) ? (int) $matches[1] : 0; return $this->site_records[ $id ] ?? null; }
	public function replace( string $table, array $data ) { $this->site_records[ (int) $data['site_id'] ] = $data; return 1; }
}
$GLOBALS['wpdb'] = new NMM_Test_WPDB();

class NMM_Test_MainWP_DB {
	public static $sites = array();
	public static function instance(): self { static $instance; return $instance instanceof self ? $instance : ( $instance = new self() ); }
	public function get_website_by_id( int $site_id ) { return self::$sites[ $site_id ] ?? null; }
	public function get_websites_for_current_user( array $args ): array { return array_values( self::$sites ); }
}
class NMM_Test_MainWP_Utility {
	public static function can_edit_website( $site ): bool { return is_object( $site ) && empty( $site->forbidden ); }
}
class_alias( NMM_Test_MainWP_DB::class, 'MainWP\\Dashboard\\MainWP_DB' );
class_alias( NMM_Test_MainWP_Utility::class, 'MainWP\\Dashboard\\MainWP_System_Utility' );

function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function __( string $text, string $domain = 'default' ): string { return $text; }
function wp_json_encode( $value, int $flags = 0 ) { return json_encode( $value, $flags ); }
function get_option( string $key, $default_value = false ) { return array_key_exists( $key, $GLOBALS['nmm_options'] ) ? $GLOBALS['nmm_options'][ $key ] : $default_value; }
function update_option( string $key, $value, $autoload = null ): bool { $GLOBALS['nmm_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['nmm_options'][ $key ] ); return true; }
function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool { $GLOBALS['nmm_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args ); return true; }
function has_filter( string $hook ): bool { return ! empty( $GLOBALS['nmm_filters'][ $hook ] ); }
function apply_filters( string $hook, $value, ...$args ) {
	if ( empty( $GLOBALS['nmm_filters'][ $hook ] ) ) return $value;
	ksort( $GLOBALS['nmm_filters'][ $hook ] );
	foreach ( $GLOBALS['nmm_filters'][ $hook ] as $callbacks ) foreach ( $callbacks as $item ) $value = $item[0]( ...array_slice( array_merge( array( $value ), $args ), 0, $item[1] ) );
	return $value;
}
function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool { return add_filter( $hook, $callback, $priority, $accepted_args ); }
function do_action( string $hook, ...$args ): void { $GLOBALS['nmm_actions'][] = array( $hook, $args ); }
function wp_register_ability( string $name, array $args ): void { $GLOBALS['nmm_abilities'][ $name ] = $args; }
function current_user_can( string $capability ): bool { return true; }
function esc_html__( string $text, string $domain = 'default' ): string { return $text; }
function wp_kses( string $html, array $allowed_html ): string { return strip_tags( $html, '<h4><ul><li><strong>' ); }
function sanitize_key( string $value ): string { return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ); }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function sanitize_textarea_field( string $value ): string { return trim( strip_tags( $value ) ); }
function sanitize_user( string $value ): string { return strtolower( (string) preg_replace( '/[^a-z0-9_.@\-]/i', '', $value ) ); }
function wp_unslash( $value ) { return $value; }
function current_time( string $type, bool $gmt = false ): string { return '2026-08-04 12:00:00'; }
function get_current_user_id(): int { return 7; }
function wp_generate_uuid4(): string { ++$GLOBALS['nmm_uuid']; return sprintf( '00000000-0000-4000-8000-%012d', $GLOBALS['nmm_uuid'] ); }
function wp_salt( string $scheme = 'auth' ): string { return 'unit-test-salt-' . $scheme; }
function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
function home_url( string $path = '' ): string { return 'https://dashboard.test' . $path; }
function trailingslashit( string $value ): string { return rtrim( $value, '/\\' ) . '/'; }
function wp_get_environment_type(): string { return 'production'; }
function wp_rand( int $min = 0, int $max = 0 ): int { return 42; }
function wp_remote_post( string $url, array $args ) { return is_callable( $GLOBALS['nmm_http_handler'] ) ? $GLOBALS['nmm_http_handler']( $url, $args ) : new WP_Error( 'no_handler', 'No HTTP handler.' ); }
function wp_remote_request( string $url, array $args ) { $GLOBALS['nmm_http_deletes'][] = array( $url, $args ); return array( 'response' => array( 'code' => 204 ), 'body' => '', 'headers' => array() ); }
function wp_remote_retrieve_response_code( array $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( array $response ): string { return (string) ( $response['body'] ?? '' ); }
function wp_remote_retrieve_header( array $response, string $name ) { $headers = array_change_key_case( (array) ( $response['headers'] ?? array() ), CASE_LOWER ); return $headers[ strtolower( $name ) ] ?? ''; }
function is_multisite(): bool { return false; }
function is_super_admin( int $user_id = 0 ): bool { return false; }
function user_can( $user, string $capability ): bool { return true; }
function get_user_by( string $field, string $value ) { return 'login' === $field && 'admin' === $value ? new WP_User() : false; }
function wp_is_application_passwords_available_for_user( WP_User $user ): bool { return true; }
function novamira_is_manually_enabled(): bool { return (bool) $GLOBALS['nmm_manual_enabled']; }
function novamira_looks_like_production(): bool { return (bool) $GLOBALS['nmm_looks_production']; }
function novamira_is_valid_ability_name( string $name ): bool { return 1 === preg_match( '/^[a-z0-9-]+\/[a-z0-9-\/]+$/', $name ); }
function novamira_get_ability_rules(): array { return $GLOBALS['nmm_ability_rules']; }
function novamira_update_ability_rules( array $rules ): void { $GLOBALS['nmm_ability_rules'] = $rules; }

function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string { return substr( str_repeat( 'a1b2c3d4', 16 ), 0, $length ); }
function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool { $GLOBALS['nmm_scheduled'][] = compact( 'timestamp', 'hook', 'args' ); return true; }
function wp_next_scheduled( string $hook, array $args = array() ) {
	foreach ( $GLOBALS['nmm_scheduled'] as $event ) {
		if ( $hook === $event['hook'] && $args === $event['args'] ) return $event['timestamp'];
	}
	return false;
}
function wp_unschedule_event( int $timestamp, string $hook, array $args = array() ): bool {
	foreach ( $GLOBALS['nmm_scheduled'] as $index => $event ) {
		if ( $timestamp === $event['timestamp'] && $hook === $event['hook'] && $args === $event['args'] ) unset( $GLOBALS['nmm_scheduled'][ $index ] );
	}
	$GLOBALS['nmm_scheduled'] = array_values( $GLOBALS['nmm_scheduled'] );
	return true;
}
function nmm_child_source( array $params ): string {
	$code = isset( $params['code'] ) ? (string) $params['code'] : '';
	if ( 1 === preg_match( "/base64_decode\\('([^']+)',true\\)/", $code, $matches ) ) {
		$decoded = base64_decode( $matches[1], true );
		if ( is_string( $decoded ) ) return $decoded;
	}
	return $code;
}
function nmm_child_action( array $params ): array {
	$code = nmm_child_source( $params );
	if ( 1 !== preg_match( "/base64_decode\\( '([^']+)' \\)/", $code, $matches ) ) return array();
	$decoded = json_decode( (string) base64_decode( $matches[1], true ), true );
	return is_array( $decoded ) ? $decoded : array();
}
function nmm_child_response( array $params, array $data = array(), bool $ok = true, string $code = '', string $message = '' ): array {
	$body = array( 'ok' => $ok, 'data' => $data );
	if ( ! $ok ) $body['error'] = array( 'code' => $code, 'message' => $message );
	return array( 'status' => 'SUCCESS', 'result' => "\nNOVAMIRA_MAINWP_RESULT:" . base64_encode( wp_json_encode( $body ) ) . "\n" );
}
require_once dirname( __DIR__ ) . '/includes/provider-config-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-github-updater.php';
require_once dirname( __DIR__ ) . '/includes/class-crypto.php';
require_once dirname( __DIR__ ) . '/includes/class-storage.php';
require_once dirname( __DIR__ ) . '/includes/class-pro-package.php';
require_once dirname( __DIR__ ) . '/includes/class-audit.php';
require_once dirname( __DIR__ ) . '/includes/class-child-runtime.php';
require_once dirname( __DIR__ ) . '/includes/class-mainwp-client.php';
require_once dirname( __DIR__ ) . '/includes/class-runtime-access.php';
require_once dirname( __DIR__ ) . '/includes/class-remote-mcp-client.php';
require_once dirname( __DIR__ ) . '/includes/class-fleet-service.php';
require_once dirname( __DIR__ ) . '/includes/class-abilities.php';
