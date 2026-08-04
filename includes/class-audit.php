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
	public static function record( int $site_id, string $operation, string $outcome, int $duration_ms, array $arguments = array(), string $correlation_id = '' ): string {
		global $wpdb;
		if ( '' === $correlation_id ) {
			$correlation_id = wp_generate_uuid4();
		}
		$keys = array_values( array_filter( array_map( 'sanitize_key', array_keys( $arguments ) ) ) );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Add-on-owned audit table.
			Storage::audit_table(),
			array(
				'event_time'     => current_time( 'mysql', true ),
				'user_id'        => get_current_user_id(),
				'site_id'        => $site_id,
				'operation'      => substr( sanitize_text_field( $operation ), 0, 191 ),
				'outcome'        => substr( sanitize_key( $outcome ), 0, 32 ),
				'duration_ms'    => max( 0, $duration_ms ),
				'correlation_id' => $correlation_id,
				'argument_keys'  => wp_json_encode( $keys ),
			)
		);
		return $correlation_id;
	}

	/** @return array<int, array<string, mixed>> */
	public static function recent( int $limit = 100 ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', Storage::audit_table(), $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded admin audit view.
		return is_array( $rows ) ? $rows : array();
	}
}
