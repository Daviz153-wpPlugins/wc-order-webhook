<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCMW_Webhook {

	public static function send( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$fields        = get_option( 'wcmw_fields', self::default_fields() );
		$sent_products = $order->get_meta( '_wcmw_sent_products' ) ?: array();
		$updated       = false;

		foreach ( $order->get_items() as $item ) {
			if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
				continue;
			}

			$product_id = $item->get_product_id();

			// Skip already-sent products (duplicate send guard)
			if ( in_array( $product_id, $sent_products, true ) ) {
				continue;
			}

			$enabled = get_post_meta( $product_id, '_wcmw_product_enabled', true );
			$url     = get_post_meta( $product_id, '_wcmw_product_url', true );

			if ( ! $enabled || empty( $url ) ) {
				continue;
			}

			$payload  = self::build_payload( $order, $item, $fields );
			$response = wp_remote_post(
				$url,
				array(
					'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
					'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
					'timeout' => 10,
				)
			);

			if ( is_wp_error( $response ) ) {
				WCMW_Logger::insert( $order_id, 'failed', $response->get_error_message(), $url );
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 300 ) {
				$sent_products[] = $product_id;
				$updated         = true;
				WCMW_Logger::insert( $order_id, 'success', '', $url );
			} else {
				WCMW_Logger::insert( $order_id, 'failed', "HTTP {$code}", $url );
			}
		}

		if ( $updated ) {
			$order->update_meta_data( '_wcmw_sent_products', $sent_products );
			$order->save();
		}
	}

	private static function build_payload( WC_Order $order, WC_Order_Item_Product $item, array $fields ): array {
		$paid_date = $order->get_date_paid() ?? $order->get_date_created();

		$map = array(
			'order_id'       => (string) $order->get_id(),
			'order_date'     => $paid_date?->date( 'Y-m-d H:i' ) ?? '',
			'customer_name'  => $order->get_formatted_billing_full_name(),
			'customer_email' => $order->get_billing_email(),
			'customer_phone' => $order->get_billing_phone(),
			'product_name'   => $item->get_name(),
			'total_amount'   => (string) $item->get_total(),
			'currency'       => $order->get_currency(),
		);

		$payload = array( 'event' => 'payment_complete' );
		foreach ( $fields as $key => $enabled ) {
			if ( $enabled && array_key_exists( $key, $map ) ) {
				$payload[ $key ] = $map[ $key ];
			}
		}
		return $payload;
	}

	public static function default_fields(): array {
		return array(
			'order_id'       => true,
			'customer_name'  => true,
			'customer_email' => true,
			'customer_phone' => true,
			'product_name'   => true,
			'total_amount'   => true,
			'order_date'     => true,
		);
	}

	public static function test_send(): array {
		$url = get_option( 'wcmw_test_url', '' );
		if ( empty( $url ) ) {
			return array( 'success' => false, 'message' => '테스트 URL이 설정되지 않았습니다.' );
		}

		$payload = array(
			'event'          => 'payment_complete',
			'order_id'       => 'TEST-001',
			'order_date'     => current_time( 'Y-m-d H:i' ),
			'customer_name'  => '테스트 고객',
			'customer_email' => 'test@example.com',
			'customer_phone' => '010-0000-0000',
			'product_name'   => '테스트 상품',
			'total_amount'   => '10000',
			'currency'       => 'KRW',
		);

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return array( 'success' => true, 'message' => "발송 성공 (HTTP {$code})" );
		}
		return array( 'success' => false, 'message' => "발송 실패 (HTTP {$code})" );
	}
}
