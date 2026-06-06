<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCMW_Admin {

	private string $page_hook = '';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_wcmw_save_settings', array( $this, 'save_settings' ) );
		add_action( 'wp_ajax_wcmw_test_send', array( $this, 'ajax_test_send' ) );
		add_action( 'wp_ajax_wcmw_clear_logs', array( $this, 'ajax_clear_logs' ) );
		add_action( 'wp_ajax_wcmw_product_test_send', array( $this, 'ajax_product_test_send' ) );

		// Product-level webhook
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_product_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_meta' ) );

		// 플러그인 목록에 "업데이트 확인" 링크 추가
		add_filter( 'plugin_action_links_wc-order-webhook/wc-order-webhook.php', array( $this, 'add_action_links' ) );
		add_action( 'admin_init', array( $this, 'handle_check_update' ) );

		// 자동 업데이트 토글 추가
		add_filter( 'plugin_auto_update_setting_html', array( $this, 'auto_update_setting_html' ), 10, 2 );
	}

	public function add_action_links( array $links ): array {
		$check_url = wp_nonce_url(
			add_query_arg( 'wcmw_check_update', '1', admin_url( 'plugins.php' ) ),
			'wcmw_check_update'
		);
		array_unshift( $links, '<a href="' . esc_url( $check_url ) . '">업데이트 확인</a>' );
		return $links;
	}

	public function auto_update_setting_html( string $html, string $plugin_file ): string {
		if ( $plugin_file !== 'wc-order-webhook/wc-order-webhook.php' ) {
			return $html;
		}

		$auto_updates = (array) get_site_option( 'auto_update_plugins', array() );
		$enabled      = in_array( $plugin_file, $auto_updates, true );

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => $enabled ? 'disable-auto-update' : 'enable-auto-update',
					'plugin' => urlencode( $plugin_file ),
				),
				admin_url( 'plugins.php' )
			),
			'updates'
		);

		return $enabled
			? '<a href="' . esc_url( $url ) . '">자동 업데이트 비활성화</a>'
			: '<a href="' . esc_url( $url ) . '">자동 업데이트 활성화</a>';
	}

	public function handle_check_update(): void {
		if (
			! isset( $_GET['wcmw_check_update'] ) ||
			! check_admin_referer( 'wcmw_check_update' ) ||
			! current_user_can( 'update_plugins' )
		) {
			return;
		}

		delete_transient( 'wcow_github_release' );
		delete_site_transient( 'update_plugins' );

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	public function add_menu(): void {
		$this->page_hook = add_submenu_page(
			'woocommerce',
			'웹훅 발송 설정',
			'웹훅 발송',
			'manage_woocommerce',
			'wc-order-webhook',
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook === $this->page_hook ) {
			wp_enqueue_style( 'wcmw-admin', WCMW_URL . 'assets/admin.css', array(), WCMW_VERSION );
			wp_enqueue_script( 'wcmw-admin', WCMW_URL . 'assets/admin.js', array(), WCMW_VERSION, true );
			wp_localize_script(
				'wcmw-admin',
				'wcmwAdmin',
				array(
					'testNonce'  => wp_create_nonce( 'wcmw_test_send' ),
					'clearNonce' => wp_create_nonce( 'wcmw_clear_logs' ),
				)
			);
			return;
		}
		// Also load on product edit pages (for toggle styles)
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && get_post_type() === 'product' ) {
			wp_enqueue_style( 'wcmw-admin', WCMW_URL . 'assets/admin.css', array(), WCMW_VERSION );
			wp_enqueue_script( 'wcmw-admin', WCMW_URL . 'assets/admin.js', array(), WCMW_VERSION, true );
		}
	}

	public function render_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = sanitize_key( $_GET['tab'] ?? 'settings' );

		echo '<div class="wrap wcmw-wrap">';
		echo '<h1>WC 웹훅 발송</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>설정이 저장되었습니다.</p></div>';
		}

		echo '<nav class="nav-tab-wrapper">';
		$this->nav_tab( 'settings', '설정', $tab );
		$this->nav_tab( 'logs', '로그', $tab );
		echo '</nav>';

		if ( $tab === 'settings' ) {
			$this->render_settings();
		} else {
			$this->render_logs();
		}

		echo '</div>';
	}

	private function nav_tab( string $id, string $label, string $current ): void {
		$active = $id === $current ? 'nav-tab-active' : '';
		$url    = esc_url( add_query_arg( array( 'page' => 'wc-order-webhook', 'tab' => $id ), admin_url( 'admin.php' ) ) );
		// $url is already escaped via esc_url() above
		echo '<a href="' . $url . '" class="nav-tab ' . esc_attr( $active ) . '">' . esc_html( $label ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function render_settings(): void {
		$fields = get_option( 'wcmw_fields', WCMW_Webhook::default_fields() );

		$field_labels = array(
			'order_id'       => '주문번호',
			'customer_name'  => '고객명',
			'customer_email' => '이메일',
			'customer_phone' => '연락처',
			'product_name'   => '상품명',
			'total_amount'   => '결제금액',
			'order_date'     => '결제일시',
		);

		$nonce_field = wp_nonce_field( 'wcmw_save_settings', 'wcmw_nonce', true, false );
		?>
		<form method="post" action="<?= esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?= $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<input type="hidden" name="action" value="wcmw_save_settings">

			<table class="form-table">
				<tr>
					<th>발송할 데이터 선택</th>
					<td>
						<?php foreach ( $field_labels as $key => $label ) : ?>
						<label class="wcmw-checkbox">
							<input type="checkbox"
									name="wcmw_fields[<?= esc_attr( $key ); ?>]"
									value="1"
									<?= ! empty( $fields[ $key ] ) ? 'checked' : ''; ?>>
							<?= esc_html( $label ); ?>
						</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th>이벤트 선택</th>
					<td>
						<label class="wcmw-checkbox">
							<input type="checkbox" checked disabled> 결제 완료
						</label>
						<label class="wcmw-checkbox wcmw-disabled">
							<input type="checkbox" disabled> 주문 취소 <small>(v2 예정)</small>
						</label>
						<label class="wcmw-checkbox wcmw-disabled">
							<input type="checkbox" disabled> 환불 <small>(v2 예정)</small>
						</label>
					</td>
				</tr>
				<tr>
					<th><label for="wcmw_test_url">테스트 발송 URL</label></th>
					<td>
						<input type="url" id="wcmw_test_url" name="wcmw_test_url"
								value="<?= esc_attr( get_option( 'wcmw_test_url', '' ) ); ?>" class="regular-text"
								placeholder="https://hook.example.com/xxxxx">
						<p class="description">실제 주문에 사용되지 않습니다. 상품별 웹훅 URL은 각 상품 편집 페이지 → <strong>웹훅</strong> 탭에서 설정하세요.</p>
					</td>
				</tr>
			</table>

			<div class="wcmw-actions">
				<button type="button" id="wcmw-test-btn" class="button button-secondary">테스트 발송</button>
				<?php submit_button( '저장', 'primary', 'submit', false ); ?>
			</div>
			<div id="wcmw-test-result" class="wcmw-notice" style="display:none"></div>
		</form>
		<?php
	}

	private function render_logs(): void {
		$logs = WCMW_Logger::get_logs();
		?>
		<div class="wcmw-log-header">
			<h2 style="margin:0">발송 로그</h2>
			<button id="wcmw-clear-btn" class="button button-secondary">로그 초기화</button>
		</div>

		<table class="wp-list-table widefat fixed striped wcmw-log-table">
			<thead>
				<tr>
					<th style="width:50px">순번</th>
					<th style="width:100px">일시</th>
					<th style="width:80px">주문번호</th>
					<th style="width:80px">상태</th>
					<th>발송 URL</th>
					<th style="width:200px">오류내용</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $logs ) ) : ?>
				<tr><td colspan="6" style="text-align:center;padding:20px">로그가 없습니다.</td></tr>
				<?php else : ?>
					<?php foreach ( $logs as $i => $log ) : ?>
				<tr>
					<td><?= absint( $i + 1 ); ?></td>
					<td><?= esc_html( gmdate( 'm-d H:i', strtotime( $log['created_at'] ) ) ); ?></td>
					<td><?= esc_html( $log['order_id'] ); ?></td>
					<td>
						<?=
						match ( $log['status'] ) {
							'success'            => '✅ 성공',
							'permanently_failed' => '❌ 영구 실패',
							default              => '⏳ 재시도 예정',
						};
	?>
						</td>
					<td class="wcmw-url-cell"><?= esc_html( $log['webhook_url'] ); ?></td>
					<td><?= esc_html( $log['error_message'] ); ?></td>
				</tr>
				<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php
	}

	public function save_settings(): void {
		if ( ! check_admin_referer( 'wcmw_save_settings', 'wcmw_nonce' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( '권한이 없습니다.' );
		}

		update_option( 'wcmw_test_url', esc_url_raw( wp_unslash( $_POST['wcmw_test_url'] ?? '' ) ) );

		$fields = WCMW_Webhook::default_fields();
		foreach ( array_keys( $fields ) as $key ) {
			$fields[ $key ] = ! empty( $_POST['wcmw_fields'][ $key ] );
		}
		update_option( 'wcmw_fields', $fields );

		wp_safe_redirect( add_query_arg( array( 'page' => 'wc-order-webhook', 'saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function ajax_test_send(): void {
		if ( ! check_ajax_referer( 'wcmw_test_send', 'nonce', false ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json( array( 'success' => false, 'message' => '권한이 없습니다.' ) );
		}
		wp_send_json( WCMW_Webhook::test_send() );
	}

	public function ajax_clear_logs(): void {
		if ( ! check_ajax_referer( 'wcmw_clear_logs', 'nonce', false ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json( array( 'success' => false ) );
		}
		WCMW_Logger::clear();
		wp_send_json( array( 'success' => true ) );
	}

	public function ajax_product_test_send(): void {
		if ( ! check_ajax_referer( 'wcmw_product_test_send', 'nonce', false ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json( array( 'success' => false, 'message' => '권한이 없습니다.' ) );
		}

		$url = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		if ( empty( $url ) ) {
			wp_send_json( array( 'success' => false, 'message' => 'URL이 없습니다.' ) );
			return;
		}

		$product_id   = absint( wp_unslash( $_POST['product_id'] ?? 0 ) );
		$product_name = $product_id ? get_the_title( $product_id ) : '테스트 상품';

		$payload = array(
			'event'          => 'payment_complete',
			'order_id'       => 'TEST-001',
			'order_date'     => current_time( 'Y-m-d H:i' ),
			'customer_name'  => '테스트 고객',
			'customer_email' => 'test@example.com',
			'customer_phone' => '010-0000-0000',
			'product_name'   => $product_name,
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
			wp_send_json( array( 'success' => false, 'message' => $response->get_error_message() ) );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			wp_send_json( array( 'success' => true, 'message' => "발송 성공 (HTTP {$code})" ) );
		}
		wp_send_json( array( 'success' => false, 'message' => "발송 실패 (HTTP {$code})" ) );
	}

	// ── Product-level webhook ────────────────────────────────────────────────

	public function add_product_tab( array $tabs ): array {
		$tabs['wcmw'] = array(
			'label'  => '웹훅',
			'target' => 'wcmw_product_data',
			'class'  => array(),
		);
		return $tabs;
	}

	public function render_product_panel(): void {
		$product_id  = get_the_ID();
		$enabled     = get_post_meta( $product_id, '_wcmw_product_enabled', true );
		$url         = esc_attr( get_post_meta( $product_id, '_wcmw_product_url', true ) );
		$thumb_left  = $enabled ? '22px' : '2px';
		$track_color = $enabled ? '#2271b1' : '#ccc';
		$hidden      = $enabled ? '' : 'display:none;';
		?>
		<div id="wcmw_product_data" class="panel woocommerce_options_panel">
			<div class="options_group" style="padding:12px 16px">

				<!-- 토글 -->
				<p style="margin:0 0 12px;padding:0">
					<span id="wcmw-toggle-wrap" style="display:inline-flex;align-items:center;gap:10px;cursor:pointer">
						<input type="checkbox" name="_wcmw_product_enabled" value="1"
								id="wcmw_product_enabled" style="display:none"
								<?= $enabled ? 'checked' : ''; ?>>
						<span id="wcmw-track"
								style="position:relative;display:inline-block;width:44px;height:24px;border-radius:12px;flex-shrink:0;transition:background .2s;background:<?= esc_attr( $track_color ); ?>">
							<span id="wcmw-thumb"
									style="position:absolute;width:20px;height:20px;background:#fff;border-radius:50%;top:2px;left:<?= esc_attr( $thumb_left ); ?>;transition:left .2s;box-shadow:0 1px 3px rgba(0,0,0,.3)"></span>
						</span>
						<span style="font-size:13px;color:#1d2327">결제 완료 시 웹훅 발송 활성화</span>
					</span>
				</p>

				<!-- URL 입력 -->
				<p id="wcmw_url_field" style="margin:0 0 8px;padding:0;<?= esc_attr( $hidden ); ?>">
					<span style="display:block;font-size:12px;font-weight:600;color:#50575e;margin-bottom:4px">웹훅 URL</span>
					<input type="url" id="wcmw_product_url" name="_wcmw_product_url"
							value="<?= esc_attr( $url ); ?>" style="width:100%;max-width:420px"
							placeholder="https://hook.example.com/xxxxx">
					<span style="display:block;font-size:12px;color:#757575;margin-top:3px">이 상품 결제 완료 시 발송할 웹훅 URL</span>
				</p>

				<!-- 테스트 발송 -->
				<p id="wcmw_test_field" style="margin:0;padding:0;<?= esc_attr( $hidden ); ?>">
					<button type="button" id="wcmw-product-test-btn" class="button button-secondary"
							data-product-id="<?= (int) $product_id; ?>"
							data-nonce="<?= esc_attr( wp_create_nonce( 'wcmw_product_test_send' ) ); ?>">
						테스트 발송
					</button>
					<span id="wcmw-product-test-result" style="margin-left:10px;font-size:13px;font-weight:500"></span>
				</p>

			</div>
		</div>
		<?php
	}

	public function save_product_meta( int $post_id ): void {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce already verifies nonce in woocommerce_process_product_meta
		update_post_meta( $post_id, '_wcmw_product_enabled', ! empty( $_POST['_wcmw_product_enabled'] ) ? '1' : '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_post_meta( $post_id, '_wcmw_product_url', esc_url_raw( wp_unslash( $_POST['_wcmw_product_url'] ?? '' ) ) );
	}
}
