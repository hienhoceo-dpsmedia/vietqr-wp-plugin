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
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			ip_address varchar(45) NOT NULL,
			user_identifier varchar(191) DEFAULT '' NOT NULL,
			action varchar(50) NOT NULL,
			status varchar(20) NOT NULL,
			message text DEFAULT '' NOT NULL,
			PRIMARY KEY  (id),
			KEY ip_address (ip_address),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function log( string $ip_address, string $user_identifier, string $action, string $status, string $message = '' ): void {
		global $wpdb;
		$table_name = self::get_table_name();

		$wpdb->insert(
			$table_name,
			array(
				'created_at'      => current_time( 'mysql' ),
				'ip_address'      => sanitize_text_field( $ip_address ),
				'user_identifier' => sanitize_text_field( $user_identifier ),
				'action'          => sanitize_text_field( $action ),
				'status'          => sanitize_text_field( $status ),
				'message'         => sanitize_text_field( $message ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public static function purge_old_logs( int $days = 30 ): void {
		global $wpdb;
		$table_name = self::get_table_name();
		$days       = max( 1, $days );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);
	}

	public static function get_logs( int $limit = 20, int $offset = 0, string $search = '' ): array {
		global $wpdb;
		$table_name = self::get_table_name();

		if ( ! empty( $search ) ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM $table_name WHERE ip_address LIKE %s OR user_identifier LIKE %s OR action LIKE %s OR status LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d",
					$like,
					$like,
					$like,
					$like,
					$limit,
					$offset
				),
				ARRAY_A
			) ?: array();
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name ORDER BY id DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		) ?: array();
	}

	public static function count_logs( string $search = '' ): int {
		global $wpdb;
		$table_name = self::get_table_name();

		if ( ! empty( $search ) ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM $table_name WHERE ip_address LIKE %s OR user_identifier LIKE %s OR action LIKE %s OR status LIKE %s",
					$like,
					$like,
					$like,
					$like
				)
			);
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
	}
}
