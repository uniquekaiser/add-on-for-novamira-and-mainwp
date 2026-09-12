<?php
/**
 * Redacted fleet audit records.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class Audit {
	/** @param array<string, mixed> $arguments */
	public static function record( int $site_id, string $operation, string $outcome, int $duration_ms, array $arguments = array(), string $correlation_id = '', string $error_code = '' ): string {
		global $wpdb;
		if ( '' === $correlation_id ) {
			$correlation_id = wp_generate_uuid4();
		}
		$keys    = array_values( array_filter( array_map( 'sanitize_key', array_keys( $arguments ) ) ) );
		$surface = defined( 'REST_REQUEST' ) && REST_REQUEST ? 'rest' : ( wp_doing_cron() ? 'cron' : 'admin' );
		$record  = array(
			'event_time'     => current_time( 'mysql', true ),
			'user_id'        => get_current_user_id(),
			'site_id'        => $site_id,
			'operation'      => substr( sanitize_text_field( $operation ), 0, 191 ),
			'outcome'        => substr( sanitize_key( $outcome ), 0, 32 ),
			'duration_ms'    => max( 0, $duration_ms ),
			'correlation_id' => $correlation_id,
			'argument_keys'  => wp_json_encode( $keys ),
			'error_code'     => 1 === preg_match( '/^[a-zA-Z0-9_-]{1,64}$/D', $error_code ) ? $error_code : '',
			'surface'        => $surface,
			'client'         => substr( sanitize_key( (string) apply_filters( 'novamira_mainwp_audit_client', '', $surface ) ), 0, 64 ),
		);
		$stored  = $wpdb->insert( Storage::audit_table(), $record ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Add-on-owned audit table.
		// Forward only the same redacted record, including whether local persistence succeeded.
		do_action( 'novamira_mainwp_audit_record', $record, false !== $stored );
		return $correlation_id;
	}

	/** Delete expired records in bounded batches; never copy secrets into retention state. */
	public static function prune(): void {
		global $wpdb;
		$days   = max( 1, min( 3650, (int) apply_filters( 'novamira_mainwp_audit_retention_days', 90 ) ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE event_time < %s LIMIT 10000', Storage::audit_table(), $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled bounded retention cleanup.
	}

	/** @return array<int, array<string, mixed>> */
	public static function recent( int $limit = 100 ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', Storage::audit_table(), $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded admin audit view.
		return is_array( $rows ) ? $rows : array();
	}
}
