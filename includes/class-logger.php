<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCMW_Logger {

	const DB_VERSION = 3;

	public static function create_table(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'wcmw_logs';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id          BIGINT UNSIGNED NOT NULL,
            webhook_url       VARCHAR(500)    NOT NULL DEFAULT '',
            status            VARCHAR(20)     NOT NULL,
            error_message     TEXT NULL,
            retry_count       TINYINT UNSIGNED NOT NULL DEFAULT 0,
            next_retry_at     DATETIME NULL DEFAULT NULL,
            created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY retry_idx (status, next_retry_at)
        ) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( 'wcmw_db_version', self::DB_VERSION );
	}

	public static function maybe_upgrade(): void {
		$current = (int) get_option( 'wcmw_db_version', 1 );
		if ( $current >= self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wcmw_logs';

		if ( $current < 2 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, not user input
			$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'webhook_url' ) );
			if ( ! $exists ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `webhook_url` VARCHAR(500) NOT NULL DEFAULT '' AFTER `order_id`" );
			}
		}

		if ( $current < 3 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'retry_count' ) );
			if ( ! $exists ) {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `retry_count` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `error_message`" );
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `next_retry_at` DATETIME NULL DEFAULT NULL AFTER `retry_count`" );
				$wpdb->query( "ALTER TABLE `{$table}` ADD KEY `retry_idx` (`status`, `next_retry_at`)" );
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		update_option( 'wcmw_db_version', self::DB_VERSION );
	}

	public static function insert( int $order_id, string $status, string $error_message = '', string $webhook_url = '' ): void {
		global $wpdb;

		$data    = array(
			'order_id'      => $order_id,
			'webhook_url'   => $webhook_url,
			'status'        => $status,
			'error_message' => $error_message ?: null,
			'created_at'    => current_time( 'mysql' ),
		);
		$formats = array( '%d', '%s', '%s', '%s', '%s' );

		if ( 'failed' === $status ) {
			$data['next_retry_at'] = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
			$formats[]             = '%s';
		}

		$wpdb->insert( $wpdb->prefix . 'wcmw_logs', $data, $formats );
		self::cleanup();
	}

	public static function get_pending_retries(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wcmw_logs';
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
				"SELECT * FROM `{$table}` WHERE status = 'failed' AND retry_count = 0 AND next_retry_at <= %s LIMIT 20",
				current_time( 'mysql' )
			),
			ARRAY_A
		);
	}

	public static function mark_retried( int $log_id, string $status, string $error_message = '' ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'wcmw_logs',
			array(
				'status'        => $status,
				'error_message' => $error_message ?: null,
				'retry_count'   => 1,
				'next_retry_at' => null,
			),
			array( 'id' => $log_id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	private static function cleanup(): void {
		if ( mt_rand( 1, 100 ) !== 1 ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wcmw_logs';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
		$wpdb->query( "DELETE FROM `{$table}` WHERE status = 'success' AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)" );
		$wpdb->query( "DELETE FROM `{$table}` WHERE status IN ('failed', 'permanently_failed') AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)" );
		$wpdb->query(
			"
            DELETE FROM `{$table}`
            WHERE id NOT IN (
                SELECT id FROM (SELECT id FROM `{$table}` ORDER BY id DESC LIMIT 1000) AS sub
            )
        "
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function get_logs(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
		return $wpdb->get_results(
			"SELECT * FROM `{$wpdb->prefix}wcmw_logs` ORDER BY id DESC LIMIT 200",
			ARRAY_A
		);
	}

	public static function clear(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}wcmw_logs`" );
	}
}
