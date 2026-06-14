<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub Releases 기반 자동 업데이트.
 *
 * 사용법:
 *   - public 저장소: 별도 설정 없이 동작.
 *   - private 저장소: wp-config.php에 아래 상수 추가.
 *       define('WCOW_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxx');
 *   - 저장소 변경 시 wp-config.php에 아래 상수 추가 (선택).
 *       define('WCOW_GITHUB_USER', 'your-org');
 *       define('WCOW_GITHUB_REPO', 'your-repo');
 *
 * 릴리즈 방법:
 *   GitHub → Releases → "Create a new release" → 태그를 v1.1.0 형식으로 생성.
 *   워드프레스 관리자 → 업데이트 메뉴에 자동으로 표시됩니다.
 */
class WCOW_Updater {

	private string $github_user;
	private string $github_repo;
	private const PLUGIN_FILE = 'wc-order-webhook/wc-order-webhook.php';
	private const PLUGIN_SLUG = 'wc-order-webhook';
	private const CACHE_KEY   = 'wcow_github_release';
	private const CACHE_TTL   = 12 * HOUR_IN_SECONDS;

	public function __construct() {
		$this->github_user = defined( 'WCOW_GITHUB_USER' ) ? WCOW_GITHUB_USER : 'Daviz153-wpPlugins';
		$this->github_repo = defined( 'WCOW_GITHUB_REPO' ) ? WCOW_GITHUB_REPO : 'wc-order-webhook';

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_filter( 'upgrader_pre_download', array( $this, 'download_with_auth' ), 10, 3 );
	}

	// 새 버전이 있으면 WordPress 업데이트 트랜지언트에 주입
	public function check_update( object $transient ): object {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$latest = ltrim( $release['tag_name'], 'v' );
		if ( version_compare( $latest, WCMW_VERSION, '>' ) ) {
			$transient->response[ self::PLUGIN_FILE ] = (object) array(
				'id'           => $this->github_repo,
				'slug'         => self::PLUGIN_SLUG,
				'plugin'       => self::PLUGIN_FILE,
				'new_version'  => $latest,
				'url'          => 'https://github.com/' . $this->github_user . '/' . $this->github_repo,
				'package'      => $release['zipball_url'],
				'icons'        => array(),
				'banners'      => array(),
				'requires'     => '6.0',
				'requires_php' => '8.2',
			);
		}
		return $transient;
	}

	// 플러그인 정보 팝업 (업데이트 화면에서 "버전 상세보기" 클릭 시)
	public function plugin_info( mixed $result, string $action, object $args ): mixed {
		if ( $action !== 'plugin_information' || $args->slug !== self::PLUGIN_SLUG ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'WC Order Webhook',
			'slug'          => self::PLUGIN_SLUG,
			'version'       => ltrim( $release['tag_name'], 'v' ),
			'author'        => '<a href="https://github.com/' . $this->github_user . '">CRMBiz</a>',
			'homepage'      => 'https://github.com/' . $this->github_user . '/' . $this->github_repo,
			'requires'      => '6.0',
			'requires_php'  => '8.2',
			'sections'      => array(
				'changelog' => '<pre>' . esc_html( $release['body'] ?? '변경 내역 없음' ) . '</pre>',
			),
			'download_link' => $release['zipball_url'],
			'last_updated'  => $release['published_at'] ?? '',
		);
	}

	// GitHub zipball 폴더명(Daviz153-wc-order-webhook-hash/) → wc-order-webhook/ 으로 교정
	public function fix_source_dir( string $source, string $remote_source, object $upgrader, array $hook_extra ): string {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== self::PLUGIN_FILE ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		$corrected = trailingslashit( $remote_source ) . self::PLUGIN_SLUG . '/';

		if ( $source !== $corrected && $wp_filesystem->move( $source, $corrected ) ) {
			return $corrected;
		}
		return $source;
	}

	// private 저장소의 경우 Authorization 헤더를 붙여 직접 다운로드
	public function download_with_auth( mixed $reply, string $package, object $upgrader ): mixed {
		$token = defined( 'WCOW_GITHUB_TOKEN' ) ? WCOW_GITHUB_TOKEN : '';
		if ( ! $token || strpos( $package, 'api.github.com' ) === false ) {
			return $reply;
		}

		$tmp      = wp_tempnam( $package );
		$response = wp_remote_get(
			$package,
			array(
				'headers'  => array(
					'Authorization' => "token {$token}",
					'Accept'        => 'application/vnd.github.v3+json',
					'User-Agent'    => 'WC-Order-Webhook/' . WCMW_VERSION,
				),
				'timeout'  => 60,
				'stream'   => true,
				'filename' => $tmp,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'download_failed', "GitHub 다운로드 실패 (HTTP {$code})" );
		}

		return $tmp;
	}

	private function get_latest_release(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( $cached !== false ) {
			return $cached ?: null;
		}

		$headers = array(
			'Accept'     => 'application/vnd.github.v3+json',
			'User-Agent' => 'WC-Order-Webhook/' . WCMW_VERSION,
		);
		if ( defined( 'WCOW_GITHUB_TOKEN' ) && WCOW_GITHUB_TOKEN ) {
			$headers['Authorization'] = 'token ' . WCOW_GITHUB_TOKEN;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $this->github_user . '/' . $this->github_repo . '/releases/latest',
			array( 'headers' => $headers, 'timeout' => 10 )
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			set_transient( self::CACHE_KEY, array(), HOUR_IN_SECONDS );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, array(), HOUR_IN_SECONDS );
			return null;
		}
		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}
}
