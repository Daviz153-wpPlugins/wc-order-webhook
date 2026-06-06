# WC Order Webhook — Claude 작업 지침

## 프로젝트 개요

WooCommerce 결제 완료 시 **상품별로 지정한 URL**로 웹훅을 발송하는 WordPress 플러그인.
Flowmattic(유료) 대체 목적. 장기 운영 핵심 플러그인으로 WooCommerce 업데이트마다 호환성 유지 필요.

- **GitHub:** https://github.com/Daviz153-wpPlugins/wc-order-webhook
- **PHP:** 8.2 이상 / **WooCommerce:** 8.0 이상 / **WordPress:** 6.0 이상

## 파일 구조

```
wc-order-webhook/
├── wc-order-webhook.php       메인: 상수, HPOS 선언, 훅 등록
├── uninstall.php              삭제 시 DB·옵션·메타 전부 제거
├── includes/
│   ├── class-logger.php       로그 테이블 CRUD
│   ├── class-webhook.php      결제완료 훅, 상품별 발송, 중복방지, 재시도
│   ├── class-admin.php        설정/로그 UI, 상품 탭, AJAX 핸들러
│   └── class-updater.php      GitHub Releases 자동 업데이트
└── assets/
    ├── admin.css
    └── admin.js               wp_localize_script()로 nonce 전달
```

## 핵심 설계 원칙

- **상품별 웹훅** — 글로벌 URL 없음. 각 상품 편집 페이지 → 웹훅 탭에서 개별 설정
- **Order Bump 포함 모든 라인 아이템 동일 처리** — 범프 상품도 개별 상품으로 간주
- **중복 발송 방지** — `_wcmw_sent_products` 주문 메타로 성공한 product_id 추적
- **HPOS 호환** — `FeaturesUtil::declare_compatibility('custom_order_tables')` 선언 완료
- **안정성 우선** — 하위 호환성과 안정성을 코드 간결함보다 우선

## 주요 상수·옵션·메타

| 종류 | 키 | 설명 |
|------|----|------|
| 상수 | `WCMW_VERSION` | 플러그인 헤더 `Version:` 필드에서 자동 읽음 |
| 상수 | `WCMW_PATH`, `WCMW_URL` | 파일/URL 경로 |
| 옵션 | `wcmw_test_url` | 설정 페이지 테스트 발송 URL |
| 옵션 | `wcmw_fields` | 발송 데이터 필드 선택 배열 |
| 옵션 | `wcmw_db_version` | DB 마이그레이션 버전 (현재 3) |
| 상품 메타 | `_wcmw_product_enabled` | '1' or '' |
| 상품 메타 | `_wcmw_product_url` | 상품 전용 웹훅 URL |
| 주문 메타 | `_wcmw_sent_products` | 발송 완료된 product_id 배열 |
| 트랜지언트 | `wcow_github_release` | GitHub API 응답 캐시 (12h) |

## DB 스키마 (v3)

```sql
CREATE TABLE wp_wcmw_logs (
  id            BIGINT UNSIGNED AUTO_INCREMENT,
  order_id      BIGINT UNSIGNED NOT NULL,
  webhook_url   VARCHAR(500) NOT NULL DEFAULT '',
  status        VARCHAR(20) NOT NULL,   -- success / failed / permanently_failed
  error_message TEXT NULL,
  retry_count   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_retry_at DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY order_id (order_id), KEY retry_idx (status, next_retry_at)
);
```

## 훅 등록 (`wc-order-webhook.php`)

```php
add_action('woocommerce_payment_complete', ['WCMW_Webhook', 'send'], 10, 1);
add_action('wcmw_retry_failed_webhooks',   ['WCMW_Webhook', 'retry_failed']);
```

## GitHub 자동 업데이트

- `GITHUB_USER = 'Daviz153-wpPlugins'` (오거나이제션)
- `pre_set_site_transient_update_plugins` → GitHub API `/releases/latest` 비교
- 릴리즈 방법: `v1.x.x` 태그 형식으로 GitHub Release 생성

## 개발 환경

- Docker WordPress (localhost:8080)
- 플러그인 디렉터리가 Docker 볼륨으로 마운트되어 있어 파일 수정 즉시 반영

## 코드 작업 시 주의사항

- WooCommerce deprecated API 사용 여부 항상 확인
- 버전 변경 시 `wc-order-webhook.php` 헤더 `Version:` + `CHANGELOG.md` 함께 수정
- 공개 저장소이므로 이메일·로컬경로·토큰 등 개인정보 커밋 금지
- `WCMW_VERSION`은 `get_file_data()`로 헤더에서 자동 읽음 — 하드코딩 금지

## 로드맵

| Phase | 내용 | 상태 |
|-------|------|------|
| 1 | MVP (결제 완료 웹훅, 상품별 URL, 로그, 자동 업데이트) | ✅ 완료 |
| 2 | 취소/환불 이벤트 웹훅 | 🔲 예정 |
| 3 | FluentAuth 카카오 연동 | 🔲 예정 |
| 4 | FluentCRM 연동 | 🔲 예정 |
| 5 | FluentCommunity 연동 | 🔲 예정 |
