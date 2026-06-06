<?php
/**
 * Plugin Name: WC Order Webhook
 * Description: WooCommerce 결제 완료 시 지정한 URL로 웹훅을 발송합니다.
 * Version:     1.0.3
 * Author:      CRMBiz
 * Requires PHP: 8.2
 * WC requires at least: 8.0
 * WC tested up to: 9.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$_wcmw_data = get_file_data( __FILE__, array( 'Version' => 'Version' ) );
define( 'WCMW_VERSION', $_wcmw_data['Version'] );
unset( $_wcmw_data );

define( 'WCMW_PATH', plugin_dir_path( __FILE__ ) );
define( 'WCMW_URL', plugin_dir_url( __FILE__ ) );

// HPOS(Custom Order Tables) 호환 선언
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

register_activation_hook(
	__FILE__,
	function () {
		require_once WCMW_PATH . 'includes/class-logger.php';
		WCMW_Logger::create_table();
		wp_schedule_event( time(), 'hourly', 'wcmw_retry_failed_webhooks' );
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'wcmw_retry_failed_webhooks' );
	}
);

// 모든 플러그인 로드 후 WooCommerce 확인
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p><strong>WC Order Webhook:</strong> WooCommerce가 활성화되어 있어야 합니다.</p></div>';
				}
			);
			return;
		}

		require_once WCMW_PATH . 'includes/class-logger.php';
		require_once WCMW_PATH . 'includes/class-webhook.php';
		require_once WCMW_PATH . 'includes/class-admin.php';
		require_once WCMW_PATH . 'includes/class-updater.php';

		WCMW_Logger::maybe_upgrade();
		new WCOW_Updater();

		add_action( 'woocommerce_payment_complete', array( 'WCMW_Webhook', 'send' ), 10, 1 );
		add_action( 'wcmw_retry_failed_webhooks', array( 'WCMW_Webhook', 'retry_failed' ) );

		// 기존 플러그인 업데이트 시 cron이 없을 수 있으므로 보장
		if ( ! wp_next_scheduled( 'wcmw_retry_failed_webhooks' ) ) {
			wp_schedule_event( time(), 'hourly', 'wcmw_retry_failed_webhooks' );
		}

		new WCMW_Admin();
	}
);
