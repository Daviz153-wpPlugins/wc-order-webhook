# Changelog

## v1.0.2 — 2026-06-06

### 추가
- 웹훅 발송 실패 시 1시간 후 자동 재시도 (WP Cron `wcmw_retry_failed_webhooks`)
- 재시도 실패 시 `permanently_failed` 상태로 영구 처리
- 결제 실패 시 관리자 이메일 알림 — 상품명, 주문자, 이메일, 연락처, 주문 링크 포함
- DB v3 마이그레이션: `retry_count`, `next_retry_at` 컬럼 및 `retry_idx` 인덱스 추가

### 변경
- 로그 화면 상태 표시 3단계로 세분화: ✅ 성공 / ⏳ 재시도 예정 / ❌ 영구 실패
- 90일 이상 된 실패/영구실패 로그 자동 정리 (기존 실패 로그만 정리하던 것 확장)

### 수정
- 관리자 이메일 발송 시 `$order` 재조회 없이 이미 로드된 객체 재사용 (중복 쿼리 제거)
- `render_settings()`의 미사용 변수 `$test_url`, `$action_url` 제거
- `@unlink()` → `wp_delete_file()` 교체 (WordPress 코딩 표준 준수)

---

## v1.0.1 — 2026-06-06

### 추가
- GitHub Releases 기반 자동 업데이트 (`class-updater.php`)
  - `pre_set_site_transient_update_plugins` 필터로 최신 버전 비교
  - `upgrader_source_selection` 필터로 zipball 폴더명 자동 교정
  - Private 저장소 지원: `wp-config.php`에 `WCOW_GITHUB_TOKEN` 상수 추가
- 언인스톨 시 플러그인 데이터 전체 삭제 (`uninstall.php`)
- 버전 상수 단일화 — 플러그인 헤더 `Version:` 필드에서 자동 읽기

### 변경
- 인라인 JavaScript → `assets/admin.js` 외부 파일 분리, `wp_localize_script()` 사용
- `global $post` → `get_the_ID()` 교체
- `wp_send_json()` 호출 후 명시적 `return` 추가

### 수정
- `$wp_filesystem` null 체크 및 초기화 보강
- GitHub API 응답 구조 검증 추가
- `save_product_meta()` 권한 체크(`current_user_can`) 추가
- `uninstall.php` `prepare()` 적용 및 트랜지언트 삭제
- `cleanup()` 1% 샘플링으로 성능 개선

---

## v1.0.0 — 2026-06-06

### 추가
- WooCommerce 결제 완료(`woocommerce_payment_complete`) 시 상품별 웹훅 자동 발송
- 상품 편집 페이지 내 "웹훅" 탭 — URL 입력 및 활성화 토글
- 발송 필드 선택: 주문번호, 주문일시, 주문자명, 이메일, 연락처, 상품명, 결제금액, 통화
- 중복 발송 방지 — `_wcmw_sent_products` 주문 메타로 성공한 상품 ID 추적
- 발송 로그 테이블 — 최근 200건, 30일 경과 성공 로그 자동 정리
- 테스트 발송 기능 (설정 페이지)
- HPOS(Custom Order Tables) 호환 선언
- WooCommerce 미설치 시 관리자 알림 표시
