# WC Order Webhook — 개발 로그

## 프로젝트 개요

WooCommerce 결제 완료 시 **상품별로 지정한 URL**로 웹훅을 발송하는 WordPress 플러그인.
Flowmattic(유료) 대체 목적. 상품마다 Make.com 시나리오가 달라 상품 단위 URL이 필수.

- **GitHub:** https://github.com/Daviz153/wc-order-webhook
- **현재 버전:** 1.0.0
- **개발 환경:** Docker WordPress (localhost:8080), PHP 8.2

---

## 파일 구조

```
wc-order-webhook/
├── wc-order-webhook.php       메인: 상수, HPOS 선언, 훅 등록
├── uninstall.php              삭제 시 DB·옵션·메타 전부 제거
├── includes/
│   ├── class-logger.php       로그 테이블 CRUD
│   ├── class-webhook.php      결제완료 훅, 상품별 발송, 중복방지
│   ├── class-admin.php        설정/로그 UI, 상품 탭, AJAX 핸들러
│   └── class-updater.php      GitHub Releases 자동 업데이트
└── assets/
    ├── admin.css
    └── admin.js               외부 JS (인라인 제거, wp_localize_script 사용)
```

---

## 핵심 설계 결정

### 상품별 웹훅 (글로벌 발송 없음)
- 각 상품 편집 페이지 → "웹훅" 탭에서 URL + 활성화 토글 설정
- `_wcmw_product_enabled` / `_wcmw_product_url` post meta 사용
- **Order Bump 포함 모든 라인 아이템을 동일하게 처리.** 범프 상품도 개별 상품으로 간주 — 웹훅 URL이 설정돼 있으면 발송, 없으면 스킵. 범프 여부로 동작을 달리하지 않음.

### 중복 발송 방지
- `_wcmw_sent_products` 배열을 주문 메타에 저장 (HPOS 호환)
- 성공한 product_id만 배열에 추가 → 실패 시 재시도 가능

### DB 스키마 (v2)
```sql
CREATE TABLE wp_wcmw_logs (
  id            BIGINT UNSIGNED AUTO_INCREMENT,
  order_id      BIGINT UNSIGNED NOT NULL,
  webhook_url   VARCHAR(500) NOT NULL DEFAULT '',
  status        VARCHAR(20) NOT NULL,
  error_message TEXT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY order_id (order_id)
);
```

### GitHub 자동 업데이트
- `pre_set_site_transient_update_plugins` → GitHub API `/releases/latest` 비교
- `upgrader_source_selection` → zipball 폴더명 `Daviz153-wc-order-webhook-{hash}/` → `wc-order-webhook/` 교정
- Private repo: `wp-config.php`에 `define('WCOW_GITHUB_TOKEN', 'ghp_xxx')` 추가
- 릴리즈 방법: GitHub → Releases → 태그 `v1.x.x` 형식으로 생성

---

## 주요 상수·옵션·메타

| 종류 | 키 | 설명 |
|------|-----|------|
| 상수 | `WCMW_VERSION` | 플러그인 헤더에서 자동 읽음 |
| 상수 | `WCMW_PATH`, `WCMW_URL` | 파일/URL 경로 |
| 옵션 | `wcmw_test_url` | 설정 페이지 테스트 발송 URL |
| 옵션 | `wcmw_fields` | 발송 데이터 필드 선택 (배열) |
| 옵션 | `wcmw_db_version` | DB 마이그레이션 버전 (현재 2) |
| 상품 메타 | `_wcmw_product_enabled` | '1' or '' |
| 상품 메타 | `_wcmw_product_url` | 상품 전용 웹훅 URL |
| 주문 메타 | `_wcmw_sent_products` | 발송 완료된 product_id 배열 |
| 트랜지언트 | `wcow_github_release` | GitHub API 응답 캐시 (12h) |

---

## 코드 리뷰 수정 내역 (2026-06-06)

사전 출시 전문가 코드 리뷰 후 9개 항목 전부 수정 완료.

### 즉시 (보안·안정성)
1. **`class-updater.php`** — `$wp_filesystem` null 체크 + `WP_Filesystem()` 초기화 추가
2. **`class-updater.php`** — GitHub API 응답 구조 검증 (`tag_name` 존재 여부 확인)
3. **`class-logger.php`** — `cleanup()` 매 insert 실행 → 1% 샘플링 (`mt_rand(1,100) !== 1`)

### 권장 (보안·유지보수)
4. **`class-admin.php`** — `save_product_meta()` 자체 권한 체크 추가 (`current_user_can('edit_post', $post_id)`)
5. **`uninstall.php`** — `SHOW TABLES LIKE` → `$wpdb->prepare()` 사용, `wcow_github_release` 트랜지언트 삭제 추가
6. **`class-admin.php`** — `global $post` 의존 제거 → `get_the_ID()` / `get_post_type()` 사용
7. **`class-admin.php`** — `ajax_product_test_send()` 내 `wp_send_json()` 후 명시적 `return` 추가

### 선택 (구조·유지보수)
8. **`assets/admin.js` 신규 생성** — 3곳의 인라인 `<script>` 블록을 외부 파일로 분리. nonce는 `wp_localize_script()`로 전달
9. **`wc-order-webhook.php`** — `WCMW_VERSION` 하드코딩 제거, `get_file_data()` 로 플러그인 헤더에서 자동 읽도록 단일화

---

## E2E 테스트 결과

### 기능 테스트 (초기 개발 완료 시)
- ✅ 상품별 웹훅 발송
- ✅ 잘못된 URL → `failed` + cURL 오류 기록
- ✅ HTTP 404 → `failed` + "HTTP 404" 기록
- ✅ 필드 일부 제외 → payload 정확히 제외
- ✅ 웹훅 비활성 상품 → 로그 0건
- ✅ 중복 발송 방지
- ✅ 로그 초기화
- ✅ 연속 10건 성공
- ✅ 로그 테이블 발송 URL 컬럼 표시

### 코드 리뷰 수정 후 재테스트 (2026-06-06)
- ✅ `admin.js` 외부 파일 로드 + `wcmwAdmin` nonce localize 확인
- ✅ 설정 페이지 테스트 발송 버튼 → 발송 성공 (HTTP 200)
- ✅ 로그 페이지 정상 표시
- ✅ 상품 편집 페이지 웹훅 탭 토글 + 테스트 발송 → 발송 성공 (HTTP 200)

---

## 개발 환경 셋업

```bash
# Docker 실행
cd <wordpress-dev-path>
docker compose up -d

# 플러그인 디렉터리 (볼륨 마운트됨)
<wp-plugin-path>/wc-order-webhook/

# WordPress 관리자
http://localhost:8080/wp-admin
ID: <admin-email>

# GitHub 저장소 클론 (로컬 비어있을 때)
cd <wp-plugin-path>/wc-order-webhook
git clone https://github.com/Daviz153/wc-order-webhook.git .
```

---

## 다음 작업 (남은 항목)

- 🔲 현재 수정 내용 GitHub 브랜치 → PR → 머지
- 🔲 v1.0.1 또는 v1.0.2 릴리즈 생성 (자동 업데이트 최종 E2E 확인)
- 🔲 Phase 2: 주문 취소 / 환불 이벤트 웹훅

---

## 로드맵

| Phase | 내용 | 상태 |
|-------|------|------|
| 1 | MVP (결제 완료 웹훅, 상품별 URL, 로그) | ✅ 완료 |
| 2 | 취소/환불/재발송 이벤트 | 🔲 예정 |
| 3 | FluentAuth 카카오 연동 | 🔲 예정 |
| 4 | FluentCRM 연동 | 🔲 예정 |
| 5 | FluentCommunity 연동 | 🔲 예정 |
