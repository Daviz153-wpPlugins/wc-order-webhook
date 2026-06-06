# WC Order Webhook

WooCommerce 결제 완료 시 **상품별로 지정한 URL**로 웹훅을 자동 발송하는 WordPress 플러그인.

상품마다 서로 다른 Make.com(또는 n8n, Zapier 등) 시나리오로 데이터를 보내야 할 때 사용합니다.

## 기능

- **상품별 웹훅 URL** — 상품 편집 페이지 내 "웹훅" 탭에서 URL과 활성화 여부를 개별 설정
- **발송 필드 선택** — 주문번호, 주문일시, 주문자명, 이메일, 연락처, 상품명, 결제금액, 통화
- **자동 재시도** — 발송 실패 시 1시간 후 WP Cron으로 자동 재시도, 재시도 실패 시 영구 실패 처리
- **관리자 이메일 알림** — 실패(및 영구 실패) 시 상품명, 주문자 정보, 주문 링크 포함 알림 발송
- **중복 발송 방지** — 성공한 상품은 주문 메타에 기록하여 재발송 차단
- **발송 로그** — 최근 200건 조회, 상태별 표시(성공 / 재시도 예정 / 영구 실패), 90일 자동 정리
- **GitHub 자동 업데이트** — WordPress 관리자 업데이트 화면에서 직접 업데이트 가능
- **HPOS 호환** — WooCommerce Custom Order Tables 완전 지원

## 요구사항

| 항목 | 버전 |
|------|------|
| WordPress | 6.0 이상 |
| WooCommerce | 8.0 이상 |
| PHP | 8.2 이상 |

## 설치

1. [Releases](https://github.com/Daviz153/wc-order-webhook/releases/latest)에서 최신 zip 다운로드
2. WordPress 관리자 → 플러그인 → 새로 추가 → 플러그인 업로드
3. 활성화

또는 이미 설치된 경우: WordPress 관리자 → 업데이트 메뉴에서 자동 업데이트.

## 사용법

### 상품별 웹훅 설정

1. WooCommerce → 상품 → 원하는 상품 편집
2. 상품 데이터 탭 중 **웹훅** 탭 선택
3. 웹훅 URL 입력 후 활성화 체크박스 체크
4. 저장

### 발송 필드 설정

WooCommerce → WC Webhook → **설정** 탭에서 페이로드에 포함할 필드를 선택합니다.

### 로그 확인

WooCommerce → WC Webhook → **발송 로그** 탭에서 발송 이력을 확인합니다.

## 웹훅 페이로드

```json
{
  "event": "payment_complete",
  "order_id": "12345",
  "order_date": "2026-06-06 16:00",
  "customer_name": "홍길동",
  "customer_email": "user@example.com",
  "customer_phone": "010-1234-5678",
  "product_name": "프리미엄 코칭",
  "total_amount": "150000",
  "currency": "KRW"
}
```

필드는 설정 페이지에서 개별적으로 포함/제외할 수 있습니다.

## 자동 재시도

발송 실패 시 동작 순서:

1. `failed` 상태로 로그 기록 + 관리자 이메일 알림 발송
2. 1시간 후 WP Cron이 자동 재시도
3. 재시도 성공 → `success` 처리
4. 재시도 실패 → `permanently_failed` 처리 + 관리자 이메일 알림 재발송

## 자동 업데이트 (Private 저장소)

Public 저장소는 별도 설정 없이 자동 업데이트가 동작합니다.

Private 저장소로 전환 시 `wp-config.php`에 GitHub Personal Access Token을 추가합니다.

```php
define('WCOW_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxx');
```

## 라이선스

GPL v2 or later
