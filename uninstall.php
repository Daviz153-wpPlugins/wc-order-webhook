<?php
// WordPress가 직접 호출한 경우에만 실행
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// 로그 테이블 삭제
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}wcmw_logs`" );

// 플러그인 옵션 삭제
delete_option( 'wcmw_webhook_url' );
delete_option( 'wcmw_test_url' );
delete_option( 'wcmw_fields' );
delete_option( 'wcmw_db_version' );

// WP Cron 예약 이벤트 삭제
wp_unschedule_hook( 'wcmw_do_send' );

// 업데이터 캐시 삭제
delete_transient( 'wcow_github_release' );

// 상품 메타 삭제 (_wcmw_product_enabled, _wcmw_product_url)
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_wcmw_product_enabled' ) );
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_wcmw_product_url' ) );

// 주문 메타 삭제 (_wcmw_sent_products)
// HPOS 환경과 기존 방식 모두 대응
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_wcmw_sent_products' ) );
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wc_orders_meta' ) ) ) {
	$wpdb->delete( "{$wpdb->prefix}wc_orders_meta", array( 'meta_key' => '_wcmw_sent_products' ) );
}
