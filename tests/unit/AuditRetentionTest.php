<?php

declare( strict_types=1 );

use Novamira\MainWP\Audit;
use PHPUnit\Framework\TestCase;

final class AuditRetentionTest extends TestCase {
	protected function tearDown(): void { $GLOBALS['nmm_filters'] = array(); unset( $GLOBALS['nmm_doing_cron'] ); }
	public function test_redacted_forwarding_includes_safe_error_and_origin(): void {
		$GLOBALS['nmm_filters'] = array();
		$GLOBALS['nmm_actions'] = array();
		$GLOBALS['nmm_doing_cron'] = true;
		add_filter( 'novamira_mainwp_audit_client', static function () { return 'trusted-adapter'; } );
		Audit::record( 1, 'mcp-write', 'error', 1, array( 'secret' => 'hidden-password' ), '', 'remote_denied' );
		$record = $GLOBALS['wpdb']->last_insert['data'];
		self::assertSame( 'remote_denied', $record['error_code'] );
		self::assertSame( 'cron', $record['surface'] );
		self::assertSame( 'trusted-adapter', $record['client'] );
		self::assertStringNotContainsString( 'hidden-password', json_encode( $GLOBALS['nmm_actions'] ) );
		self::assertSame( 'novamira_mainwp_audit_record', $GLOBALS['nmm_actions'][0][0] );
		self::assertSame( $record, $GLOBALS['nmm_actions'][0][1][0] );
		Audit::record( 1, 'status', 'error', 0, array(), '', 'unsafe error details' );
		self::assertSame( '', $GLOBALS['wpdb']->last_insert['data']['error_code'] );
	}
	public function test_retention_clamps_configuration_and_deletes_in_bounded_batches(): void {
		$previous = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class extends NMM_Test_WPDB { public $query_text; public function query( string $query ) { $this->query_text = $query; return 0; } };
		try {
			$GLOBALS['nmm_filters'] = array();
			add_filter( 'novamira_mainwp_audit_retention_days', static function () { return 0; } );
			Audit::prune();
			self::assertStringContainsString( 'LIMIT 10000', $GLOBALS['wpdb']->query_text );
			self::assertStringContainsString( gmdate( 'Y-m-d', time() - 86400 ), $GLOBALS['wpdb']->query_text );
			self::assertStringContainsString( 'WHERE event_time <', $GLOBALS['wpdb']->query_text );
		} finally { $GLOBALS['wpdb'] = $previous; }
	}
}
