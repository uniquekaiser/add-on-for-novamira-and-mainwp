<?php

declare( strict_types=1 );

use Novamira\MainWP\Fleet_Service;
use Novamira\MainWP\Free_Package;
use Novamira\MainWP\Onboarding;
use PHPUnit\Framework\TestCase;

final class FreePackageTest extends TestCase {
	private $files = array();
	private $requests = array();
	private $metadata = array();
	private $bytes = '';
	private $package_status = 200;
	private $metadata_status = 200;

	protected function setUp(): void {
		$GLOBALS['nmm_filters'] = array();
		$GLOBALS['nmm_transients'] = array();
		$GLOBALS['nmm_options'] = array();
		$this->metadata = array( 'slug' => 'novamira', 'version' => '1.12.0', 'download_url' => 'https://packages.example/novamira-1.12.0.zip' );
		$GLOBALS['nmm_http_handler'] = function ( $url, $args ) {
			$this->requests[] = $url;
			self::assertSame( 0, $args['redirection'] );
			self::assertTrue( $args['reject_unsafe_urls'] );
			self::assertTrue( $args['sslverify'] );
			if ( ! empty( $args['stream'] ) ) {
				$this->files[] = $args['filename'];
				file_put_contents( $args['filename'], $this->bytes );
				return array( 'response' => array( 'code' => $this->package_status ), 'body' => '' );
			}
			return array( 'response' => array( 'code' => $this->metadata_status ), 'body' => wp_json_encode( $this->metadata ) );
		};
	}

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) unlink( $file );
		}
	}

	private function trust( string $hash = '' ): void {
		$record = array( '1.12.0' => array( 'sha256' => '' === $hash ? hash( 'sha256', $this->bytes ) : $hash, 'download_url' => 'https://packages.example/novamira-1.12.0.zip' ) );
		add_filter( 'novamira_mainwp_trusted_free_releases', static function () use ( $record ) { return $record; } );
	}

	private function zip( string $extra_path = '', string $version = '1.12.0' ): void {
		self::assertTrue( class_exists( 'ZipArchive' ), 'Security ZIP tests require the ZipArchive PHP extension.' );
		$file = tempnam( sys_get_temp_dir(), 'nmm-zip-test-' );
		$this->files[] = $file;
		$zip = new ZipArchive();
		self::assertTrue( $zip->open( $file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		$zip->addFromString( 'novamira/novamira.php', "<?php\n/* Plugin Name: Novamira\nVersion: " . $version . "\n*/\n// " . bin2hex( random_bytes( 8 ) ) );
		if ( '' !== $extra_path ) $zip->addFromString( $extra_path, '<?php /* malicious path */' );
		$zip->close();
		$this->bytes = file_get_contents( $file );
	}

	public function test_missing_independent_trust_fails_before_any_http_despite_old_cache(): void {
		$GLOBALS['nmm_transients']['novamira_mainwp_free_release'] = $this->metadata + array( 'sha256' => str_repeat( 'a', 64 ) );
		self::assertSame( 'novamira_mainwp_free_trust_missing', Fleet_Service::free_package()->get_error_code() );
		self::assertSame( array(), $this->requests );
	}

	/** @dataProvider changed_metadata */
	public function test_metadata_cannot_change_independently_pinned_identity( string $field, string $value ): void {
		$this->trust( str_repeat( 'a', 64 ) );
		$this->metadata[ $field ] = $value;
		self::assertSame( 'novamira_mainwp_free_release_untrusted', Fleet_Service::free_package()->get_error_code() );
		self::assertSame( array( 'https://license.dynamic.ooo/api/novamira/info' ), $this->requests );
	}

	public static function changed_metadata(): array {
		return array(
			array( 'download_url', 'https://attacker.test/novamira.zip' ),
			array( 'download_url', 'https://packages.example:8443/novamira-1.12.0.zip' ),
			array( 'download_url', 'https://packages.example/novamira-1.12.0.zip?redirect=evil' ),
			array( 'version', '1.11.1' ),
			array( 'version', '99.0.0' ),
			array( 'slug', 'novamira-pro' ),
		);
	}

	public function test_metadata_redirect_is_rejected_before_package_request(): void {
		$this->trust( str_repeat( 'a', 64 ) );
		$this->metadata_status = 302;
		self::assertSame( 'novamira_mainwp_free_release_invalid', Fleet_Service::free_package()->get_error_code() );
		self::assertCount( 1, $this->requests );
	}

	public function test_package_redirect_is_rejected(): void {
		$this->zip();
		$this->trust();
		$this->package_status = 302;
		self::assertSame( 'novamira_mainwp_free_download_failed', Fleet_Service::free_package()->get_error_code() );
		self::assertCount( 2, $this->requests );
		self::assertFileDoesNotExist( end( $this->files ) );
	}

	public function test_matching_headers_and_metadata_hash_do_not_authenticate_malicious_bytes(): void {
		$this->zip();
		$this->trust( str_repeat( 'a', 64 ) );
		$this->metadata['sha256'] = hash( 'sha256', $this->bytes );
		self::assertSame( 'novamira_mainwp_free_package_unauthenticated', Fleet_Service::free_package()->get_error_code() );
		self::assertFileDoesNotExist( end( $this->files ) );
	}

	public function test_trusted_digest_does_not_bypass_zip_path_validation(): void {
		$this->zip( 'novamira/../../backdoor.php' );
		$this->trust();
		self::assertSame( 'novamira_mainwp_free_zip_unsafe', Fleet_Service::free_package()->get_error_code() );
	}

	public function test_trusted_digest_does_not_bypass_plugin_version_validation(): void {
		$this->zip( '', '9.9.9' );
		$this->trust();
		self::assertSame( 'novamira_mainwp_free_zip_header_invalid', Fleet_Service::free_package()->get_error_code() );
	}

	public function test_authenticated_bytes_are_staged_and_cache_is_rehashed_before_reuse(): void {
		$this->zip();
		$this->trust();
		$result = Fleet_Service::free_package();
		self::assertIsArray( $result );
		$this->files[] = $result['path'];
		self::assertSame( $this->bytes, file_get_contents( $result['path'] ) );
		self::assertStringStartsWith( 'https://dashboard.test/wp-admin/?sig=', $result['download_url'] );
		self::assertStringContainsString( $result['sha256'], $result['path'] );
		self::assertSame( $result, Fleet_Service::free_package() );
		self::assertCount( 2, $this->requests );
		self::assertSame( $result['download_url'], Onboarding::prepare_download_url( 'https://attacker.test/novamira.zip', array( 'type' => 'plugin', 'slug' => 'novamira' ) ) );
		file_put_contents( $result['path'], 'tampered local cache' );
		$this->bytes = 'tampered upstream bytes';
		self::assertSame( 'novamira_mainwp_free_package_unauthenticated', Fleet_Service::free_package()->get_error_code() );
		self::assertCount( 4, $this->requests );
	}

	public function test_removing_trust_revokes_an_authenticated_cached_package(): void {
		$this->zip();
		$this->trust();
		$result = Fleet_Service::free_package();
		$this->files[] = $result['path'];
		$GLOBALS['nmm_filters'] = array();
		self::assertSame( 'novamira_mainwp_free_trust_missing', Fleet_Service::free_package()->get_error_code() );
		self::assertCount( 2, $this->requests );
	}

	/** @dataProvider free_deploy_operations */
	public function test_all_free_deployment_routes_send_only_the_authenticated_staged_url( string $operation ): void {
		$this->zip();
		$this->trust();
		$release = Fleet_Service::free_package();
		$this->files[] = $release['path'];
		$calls = array();
		NMM_Test_MainWP_DB::$sites[123] = (object) array( 'id' => 123, 'url' => 'https://child.test', 'suspended' => 0 );
		add_filter( 'mainwp_fetchurlauthed', static function ( $file, $key, $site_id, $what, $params ) use ( &$calls ) {
			if ( 'code_snippet' === $what ) {
				return nmm_child_response( $params, array( 'free' => array( 'active' => true ) ) );
			}
			$calls[] = array( $what, $params );
			return new WP_Error( 'test_stopped_before_child_install', 'The simulated child rejects the installation.' );
		}, 10, 5 );
		$result = Fleet_Service::provision( array( 123 ), $operation, false );
		self::assertFalse( $result['results'][0]['ok'] );
		self::assertCount( 1, $calls );
		self::assertSame( 'installplugintheme', $calls[0][0] );
		self::assertSame( $release['download_url'], json_decode( $calls[0][1]['url'], true ) );
		self::assertCount( 2, $this->requests );
	}

	public static function free_deploy_operations(): array {
		return array( array( 'install-free' ), array( 'repair-free' ), array( 'update-free' ) );
	}

	/** @dataProvider activation_states */
	public function test_update_preserves_live_activation_state( bool $active ): void {
		$this->zip();
		$this->trust();
		$release = Fleet_Service::free_package();
		$this->files[] = $release['path'];
		$calls = array();
		NMM_Test_MainWP_DB::$sites[123] = (object) array( 'id' => 123, 'url' => 'https://child.test', 'suspended' => 0 );
		add_filter( 'mainwp_fetchurlauthed', static function ( $file, $key, $site_id, $what, $params ) use ( &$calls, $active ) {
			$calls[] = array( $what, $params );
			if ( 'code_snippet' === $what ) {
				self::assertSame( 'status', nmm_child_action( $params )['action'] );
				return nmm_child_response( $params, array( 'free' => array( 'active' => $active ) ) );
			}
			return new WP_Error( 'test_stopped_before_child_install', 'Simulated installation boundary.' );
		}, 10, 5 );
		Fleet_Service::provision( array( 123 ), 'update-free', false );
		self::assertCount( 2, $calls );
		self::assertSame( 'installplugintheme', $calls[1][0] );
		self::assertSame( $active ? 'yes' : 'no', $calls[1][1]['activatePlugin'] );
		self::assertTrue( $calls[1][1]['overwrite'] );
	}

	public static function activation_states(): array {
		return array( array( true ), array( false ) );
	}

	/** @dataProvider invalid_activation_states */
	public function test_update_rejects_unverifiable_activation_before_downloading( $status ): void {
		$this->trust( str_repeat( 'a', 64 ) );
		$calls = array();
		NMM_Test_MainWP_DB::$sites[123] = (object) array( 'id' => 123, 'url' => 'https://child.test', 'suspended' => 0 );
		add_filter( 'mainwp_fetchurlauthed', static function ( $file, $key, $site_id, $what, $params ) use ( &$calls, $status ) {
			$calls[] = $what;
			return null === $status ? new WP_Error( 'status_unavailable', 'Status failed.' ) : nmm_child_response( $params, $status );
		}, 10, 5 );
		$result = Fleet_Service::provision( array( 123 ), 'update-free', false );
		self::assertFalse( $result['results'][0]['ok'] );
		self::assertSame( array( 'code_snippet' ), $calls );
		self::assertSame( array(), $this->requests );
	}

	public static function invalid_activation_states(): array {
		return array( array( null ), array( array() ), array( array( 'free' => array( 'active' => 'yes' ) ) ), array( array( 'free' => array( 'active' => null ) ) ) );
	}
}
