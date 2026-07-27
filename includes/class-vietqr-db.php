<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class VietQR_DB {

	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'vietqr_logs';
	}

	public static function activate(): void {
		global $wpdb;
		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			ip_address varchar(45) NOT NULL,
			user_identifier varchar(191) NOT NULL DEFAULT 'Guest',
			action varchar(50) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'success',
			details text NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY ip_address (ip_address),
			KEY user_identifier (user_identifier),
			KEY action (action),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function log( string $ip_address, string $user_identifier, string $action, string $status = 'success', ?string $details = null ): bool {
		global $wpdb;
		$table_name = self::get_table_name();

		$result = $wpdb->insert(
			$table_name,
			array(
				'ip_address'      => sanitize_text_field( $ip_address ),
				'user_identifier' => sanitize_text_field( $user_identifier ),
				'action'          => sanitize_text_field( $action ),
				'status'          => sanitize_text_field( $status ),
				'details'         => $details ? sanitize_text_field( $details ) : null,
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $result;
	}

	public static function get_logs( int $limit = 20, int $offset = 0, string $search = '', string $status = '' ): array {
		global $wpdb;
		$table_name = self::get_table_name();
		$where      = array( '1=1' );
		$params     = array();

		if ( ! empty( $search ) ) {
			$where[]  = '(ip_address LIKE %s OR user_identifier LIKE %s OR action LIKE %s OR details LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( ! empty( $status ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$where_clause = implode( ' AND ', $where );
		$sql          = "SELECT * FROM $table_name WHERE $where_clause ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$params[]     = $limit;
		$params[]     = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: array();
	}

	public static function get_logs_count( string $search = '', string $status = '' ): int {
		global $wpdb;
		$table_name = self::get_table_name();
		$where      = array( '1=1' );
		$params     = array();

		if ( ! empty( $search ) ) {
			$where[]  = '(ip_address LIKE %s OR user_identifier LIKE %s OR action LIKE %s OR details LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( ! empty( $status ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$where_clause = implode( ' AND ', $where );
		$sql          = "SELECT COUNT(*) FROM $table_name WHERE $where_clause";

		if ( ! empty( $params ) ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		}

		return (int) $wpdb->get_var( $sql );
	}

	public static function purge_old_logs( int $days = 30 ): int {
		global $wpdb;
		$table_name = self::get_table_name();
		$sql        = "DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)";
		$result     = $wpdb->query( $wpdb->prepare( $sql, $days ) );
		return false === $result ? 0 : (int) $result;
	}

	public static function purge_all_logs(): bool {
		global $wpdb;
		$table_name = self::get_table_name();
		return false !== $wpdb->query( "TRUNCATE TABLE $table_name" );
	}
}
