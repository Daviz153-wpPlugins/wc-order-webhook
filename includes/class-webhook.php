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
				$error = $response->get_error_message();
				WCMW_Logger::insert( $order_id, 'failed', $error, $url );
				self::notify_admin( $order_id, $url, $error, false, $item->get_name() );
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 300 ) {
				$sent_products[] = $product_id;
				$updated         = true;
				WCMW_Logger::insert( $order_id, 'success', '', $url );
			} else {
				$error = "HTTP {$code}";
				WCMW_Logger::insert( $order_id, 'failed', $error, $url );
				self::notify_admin( $order_id, $url, $error, false, $item->get_name() );
			}
		}

		if ( $updated ) {
			$order->update_meta_data( '_wcmw_sent_products', $sent_products );
			$order->save();
		}
	}

	public static function retry_failed(): void {
		$pending = WCMW_Logger::get_pending_retries();
		if ( empty( $pending ) ) {
			return;
		}

		$fields = get_option( 'wcmw_fields', self::default_fields() );

		foreach ( $pending as $log ) {
			$log_id   = (int) $log['id'];
			$order_id = (int) $log['order_id'];
			$url      = $log['webhook_url'];

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				WCMW_Logger::mark_retried( $log_id, 'permanently_failed', '주문을 찾을 수 없음' );
				self::notify_admin( $order_id, $url, '주문을 찾을 수 없음', true, '' );
				continue;
			}

			// Find the item whose product URL matches the logged URL
			$matched_item = null;
			foreach ( $order->get_items() as $item ) {
				if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
					continue;
				}
				if ( get_post_meta( $item->get_product_id(), '_wcmw_product_url', true ) === $url ) {
					$matched_item = $item;
					break;
				}
			}

			if ( ! $matched_item ) {
				// URL was changed or product removed — treat as permanently failed
				WCMW_Logger::mark_retried( $log_id, 'permanently_failed', '상품 웹훅 URL이 변경되었거나 상품이 삭제됨' );
				continue;
			}

			$payload  = self::build_payload( $order, $matched_item, $fields );
			$response = wp_remote_post(
				$url,
				array(
					'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
					'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
					'timeout' => 10,
				)
			);

			if ( is_wp_error( $response ) ) {
				$error = $response->get_error_message();
				WCMW_Logger::mark_retried( $log_id, 'permanently_failed', $error );
				self::notify_admin( $order_id, $url, $error, true, $matched_item->get_name() );
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 300 ) {
				WCMW_Logger::mark_retried( $log_id, 'success', '' );

				// Update the duplicate guard so future payment_complete calls don't re-send
				$sent   = $order->get_meta( '_wcmw_sent_products' ) ?: array();
				$sent[] = $matched_item->get_product_id();
				$order->update_meta_data( '_wcmw_sent_products', array_unique( $sent ) );
				$order->save();
			} else {
				$error = "HTTP {$code}";
				WCMW_Logger::mark_retried( $log_id, 'permanently_failed', $error );
				self::notify_admin( $order_id, $url, $error, true, $matched_item->get_name() );
			}
		}
	}

	private static function notify_admin( int $order_id, string $url, string $error, bool $is_permanent = false, string $product_name = '' ): void {
		$admin_email = get_option( 'admin_email' );
		$site_name   = get_bloginfo( 'name' );
		$logs_url    = admin_url( 'admin.php?page=wc-order-webhook&tab=logs' );
		$order_url   = admin_url( "admin.php?page=wc-orders&action=edit&id={$order_id}" );

		// 주문자 정보 조회
		$customer_lines = '';
		$order          = wc_get_order( $order_id );
		if ( $order ) {
			$name  = $order->get_formatted_billing_full_name();
			$email = $order->get_billing_email();
			$phone = $order->get_billing_phone();

			$customer_lines .= $name ? "주문자: {$name}\n" : '';
			$customer_lines .= $email ? "이메일: {$email}\n" : '';
			$customer_lines .= $phone ? "연락처: {$phone}\n" : '';
		}

		$order_info = ( $product_name ? "상품명: {$product_name}\n" : '' ) . $customer_lines;

		if ( $is_permanent ) {
			$subject = "[{$site_name}] 웹훅 재시도 실패 (수동 확인 필요) — 주문 #{$order_id}";
			$body    = "주문 #{$order_id}의 웹훅이 자동 재시도 후에도 실패하였습니다.\n\n"
					. $order_info
					. "\n웹훅 URL: {$url}\n"
					. "오류: {$error}\n\n"
					. "주문 확인: {$order_url}\n"
					. "발송 로그: {$logs_url}";
		} else {
			$subject = "[{$site_name}] 웹훅 발송 실패 — 주문 #{$order_id}";
			$body    = "주문 #{$order_id}의 웹훅 발송에 실패하였습니다.\n\n"
					. $order_info
					. "\n웹훅 URL: {$url}\n"
					. "오류: {$error}\n\n"
					. "1시간 후 자동으로 재시도됩니다.\n"
					. "주문 확인: {$order_url}\n"
					. "발송 로그: {$logs_url}";
		}

		wp_mail( $admin_email, $subject, $body );
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
