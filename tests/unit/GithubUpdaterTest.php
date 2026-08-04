<?php

declare( strict_types=1 );

use Novamira\MainWP\GitHub_Updater;
use PHPUnit\Framework\TestCase;

final class GithubUpdaterTest extends TestCase {
	/** @dataProvider accepted_assets */
	public function test_accepts_only_exact_versioned_distribution_assets( string $filename ): void {
		self::assertTrue( GitHub_Updater::is_release_asset( $filename ) );
	}

	/** @return array<string, array{string}> */
	public function accepted_assets(): array {
		return array(
			'current'     => array( 'mainwp-novamira-addon-0.2.3.zip' ),
			'multi-digit' => array( 'mainwp-novamira-addon-12.34.56.zip' ),
		);
	}

	/** @dataProvider rejected_assets */
	public function test_rejects_source_archives_and_unrelated_assets( string $filename ): void {
		self::assertFalse( GitHub_Updater::is_release_asset( $filename ) );
	}

	/** @return array<string, array{string}> */
	public function rejected_assets(): array {
		return array(
			'unversioned' => array( 'mainwp-novamira-addon.zip' ),
			'source'      => array( 'novamira-for-mainwp-0.2.0.zip' ),
			'prerelease'  => array( 'mainwp-novamira-addon-0.2.0-beta.1.zip' ),
			'extra'       => array( 'mainwp-novamira-addon-0.2.0-backup.zip' ),
			'path'        => array( 'dist/mainwp-novamira-addon-0.2.0.zip' ),
		);
	}

	public function test_update_detection_keeps_only_latest_release(): void {
		$latest = static function (): void {};
		self::assertSame(
			array( 'latest_release' => $latest ),
			GitHub_Updater::latest_release_only(
				array(
					'latest_release' => $latest,
					'latest_tag'     => static function (): void {},
					'stable_branch'  => static function (): void {},
				)
			)
		);
	}

	public function test_update_metadata_is_complete(): void {
		$info = GitHub_Updater::complete_metadata(
			(object) array(
				'sections' => array(
					'changelog' => '<h1>0.2.4</h1><ul><li>[FIX] Future release note.</li></ul>',
				),
			)
		);
		self::assertIsObject( $info );
		self::assertSame( 'mainwp-novamira-addon', $info->slug );
		self::assertSame( '6.9', $info->requires );
		self::assertSame( '7.0', $info->tested );
		self::assertSame( '7.4', $info->requires_php );
		self::assertStringEndsWith( '/assets/icon.svg', $info->icons['svg'] );
		self::assertArrayHasKey( 'description', $info->sections );
		self::assertArrayHasKey( 'changelog', $info->sections );
		self::assertStringContainsString( '<strong>[FIX]</strong>', $info->sections['changelog'] );
		self::assertStringContainsString( '<h4>0.2.4</h4>', $info->sections['changelog'] );
		self::assertStringContainsString( 'Future release note.', $info->sections['changelog'] );
		self::assertStringNotContainsString( '<h1>', $info->sections['changelog'] );
	}

	public function test_fresh_and_cached_update_rows_receive_complete_metadata(): void {
		$key       = 'mainwp-novamira-addon/mainwp-novamira-addon.php';
		$transient = (object) array(
			'response' => array( $key => (object) array( 'icons' => array() ) ),
			'no_update' => array( $key => array() ),
		);

		$result = GitHub_Updater::complete_update_transient( $transient );
		self::assertSame( '6.9', $result->response[ $key ]->requires );
		self::assertSame( '7.4', $result->response[ $key ]->requires_php );
		self::assertStringEndsWith( '/assets/icon.svg', $result->response[ $key ]->icons['svg'] );
		self::assertSame( '6.9', $result->no_update[ $key ]['requires'] );
	}
}
