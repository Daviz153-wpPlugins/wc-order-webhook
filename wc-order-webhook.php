<?php
/**
 * Plugin Name: WC Order Webhook
 * Description: WooCommerce 결제 완료 시 지정한 URL로 웹훅을 발송합니다.
 * Version:     1.0.0
 * Author:      CRMBiz
 * Requires PHP: 8.2
 * WC requires at least: 8.0
 * WC tested up to: 9.9
 */

if (!defined('ABSPATH')) exit;

define('WCMW_VERSION', '1.0.0');
define('WCMW_PATH', plugin_dir_path(__FILE__));
define('WCMW_URL', plugin_dir_url(__FILE__));

// HPOS(Custom Order Tables) 호환 선언
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

register_activation_hook(__FILE__, function () {
    require_once WCMW_PATH . 'includes/class-logger.php';
    WCMW_Logger::create_table();
});

// 모든 플러그인 로드 후 WooCommerce 확인
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>WC Order Webhook:</strong> WooCommerce가 활성화되어 있어야 합니다.</p></div>';
        });
        return;
    }

    require_once WCMW_PATH . 'includes/class-logger.php';
    require_once WCMW_PATH . 'includes/class-webhook.php';
    require_once WCMW_PATH . 'includes/class-admin.php';

    WCMW_Logger::maybe_upgrade();

    add_action('woocommerce_payment_complete', ['WCMW_Webhook', 'send'], 10, 1);

    new WCMW_Admin();
});
