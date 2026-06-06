<?php
if (!defined('ABSPATH')) exit;

class WCMW_Logger {

    const DB_VERSION = 2;

    public static function create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . 'wcmw_logs';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id      BIGINT UNSIGNED NOT NULL,
            webhook_url   VARCHAR(500)    NOT NULL DEFAULT '',
            status        VARCHAR(20)     NOT NULL,
            error_message TEXT NULL,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option('wcmw_db_version', self::DB_VERSION);
    }

    public static function maybe_upgrade(): void {
        if ((int) get_option('wcmw_db_version', 1) >= self::DB_VERSION) return;

        global $wpdb;
        $table  = $wpdb->prefix . 'wcmw_logs';
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'webhook_url'));
        if (!$exists) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `webhook_url` VARCHAR(500) NOT NULL DEFAULT '' AFTER `order_id`");
        }
        update_option('wcmw_db_version', self::DB_VERSION);
    }

    public static function insert(int $order_id, string $status, string $error_message = '', string $webhook_url = ''): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'wcmw_logs',
            [
                'order_id'      => $order_id,
                'webhook_url'   => $webhook_url,
                'status'        => $status,
                'error_message' => $error_message,
                'created_at'    => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
        self::cleanup();
    }

    private static function cleanup(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'wcmw_logs';
        $wpdb->query("DELETE FROM `{$table}` WHERE status = 'success' AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $wpdb->query("DELETE FROM `{$table}` WHERE status = 'failed'  AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
        $wpdb->query("
            DELETE FROM `{$table}`
            WHERE id NOT IN (
                SELECT id FROM (SELECT id FROM `{$table}` ORDER BY id DESC LIMIT 1000) AS sub
            )
        ");
    }

    public static function get_logs(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM `{$wpdb->prefix}wcmw_logs` ORDER BY id DESC LIMIT 200",
            ARRAY_A
        );
    }

    public static function clear(): void {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE `{$wpdb->prefix}wcmw_logs`");
    }
}
