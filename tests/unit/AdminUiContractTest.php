<?php

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class AdminUiContractTest extends TestCase {
	public function test_fleet_ui_exposes_select_all_missing_install_and_isolated_queue(): void {
		$admin = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-admin.php' );
		$js    = file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin.js' );
		self::assertStringContainsString( 'id="nmm-select-all"', $admin );
		self::assertStringContainsString( 'id="nmm-install-check-modal"', $admin );
		self::assertStringContainsString( 'install-activate-pro', $admin );
		self::assertStringContainsString( 'activate-pro-license', $admin . $js );
		self::assertStringContainsString( 'Activate stored Pro license only (plugin must be active)', $admin . $js );
		self::assertStringContainsString( 'novamira_mainwp_bulk_site', $js );
		self::assertStringContainsString( 'Other sites will continue', $admin . $js );
		self::assertStringContainsString( 'maxWorkers', $js );
	}
}
