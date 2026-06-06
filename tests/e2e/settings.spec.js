const { test, expect } = require('@playwright/test');
const { login } = require('./helpers');

test.describe('설정 페이지', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('설정 탭이 올바르게 렌더링된다', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=wc-order-webhook&tab=settings');
        await expect(page.locator('h1')).toContainText('WC 웹훅 발송');
        await expect(page.locator('#wcmw_test_url')).toBeVisible();
        await expect(page.locator('#wcmw-test-btn')).toBeVisible();
    });

    test('발송 데이터 체크박스 7개가 존재한다', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=wc-order-webhook&tab=settings');
        const checkboxes = page.locator('input[name^="wcmw_fields"]');
        await expect(checkboxes).toHaveCount(7);
    });

    test('테스트 URL 저장 후 유지된다', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=wc-order-webhook&tab=settings');
        const testUrl = 'https://httpbin.org/post';
        await page.fill('#wcmw_test_url', testUrl);
        await page.click('[name="submit"]');
        await page.waitForURL('**/saved=1**');
        await expect(page.locator('#wcmw_test_url')).toHaveValue(testUrl);
    });

    test('테스트 발송 버튼 클릭 시 결과가 표시된다', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=wc-order-webhook&tab=settings');
        await page.fill('#wcmw_test_url', 'https://httpbin.org/post');
        await page.click('[name="submit"]');
        await page.waitForURL('**/saved=1**');

        await page.click('#wcmw-test-btn');
        const result = page.locator('#wcmw-test-result');
        await expect(result).toBeVisible({ timeout: 15000 });
        await expect(result).not.toBeEmpty();
    });

    test('로그 탭이 올바르게 렌더링된다', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=wc-order-webhook&tab=logs');
        await expect(page.locator('.wcmw-log-table')).toBeVisible();
        await expect(page.locator('#wcmw-clear-btn')).toBeVisible();
        await expect(page.locator('.wcmw-log-table thead th')).toHaveCount(6);
    });

    test('로그 초기화 버튼이 confirm 창을 띄운다', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=wc-order-webhook&tab=logs');
        let dialogShown = false;
        page.once('dialog', dialog => {
            dialogShown = true;
            dialog.dismiss();
        });
        await page.click('#wcmw-clear-btn');
        expect(dialogShown).toBe(true);
    });
});
