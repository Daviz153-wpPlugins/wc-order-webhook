const { test, expect } = require('@playwright/test');
const { login } = require('./helpers');

// CI에서 WP CLI로 미리 생성한 상품 ID를 환경변수로 전달
const PRODUCT_ID = process.env.E2E_PRODUCT_ID || '';

test.describe('상품 편집 — 웹훅 탭', () => {
    test.beforeAll(async () => {
        if (!PRODUCT_ID) {
            console.warn('E2E_PRODUCT_ID 환경변수 없음 — 상품 탭 테스트 건너뜀');
        }
    });

    test.beforeEach(async ({ page }) => {
        if (!PRODUCT_ID) test.skip();
        await login(page);
    });

    test('상품 편집 페이지에 웹훅 탭이 존재한다', async ({ page }) => {
        await page.goto(`/wp-admin/post.php?post=${PRODUCT_ID}&action=edit`);
        await expect(page.locator('a[href="#wcmw_product_data"]')).toBeVisible({ timeout: 20000 });
    });

    test('웹훅 탭 클릭 시 패널이 표시된다', async ({ page }) => {
        await page.goto(`/wp-admin/post.php?post=${PRODUCT_ID}&action=edit`);
        await page.click('a[href="#wcmw_product_data"]');
        await expect(page.locator('#wcmw_product_data')).toBeVisible();
        await expect(page.locator('#wcmw-toggle-wrap')).toBeVisible();
    });

    test('토글 클릭 시 URL 입력 필드가 표시된다', async ({ page }) => {
        await page.goto(`/wp-admin/post.php?post=${PRODUCT_ID}&action=edit`);
        await page.click('a[href="#wcmw_product_data"]');

        const checkbox = page.locator('#wcmw_product_enabled');
        const urlField = page.locator('#wcmw_url_field');

        if (!(await checkbox.isChecked())) {
            await page.click('#wcmw-toggle-wrap');
        }
        await expect(urlField).toBeVisible();
    });

    test('웹훅 URL 저장 후 유지된다', async ({ page }) => {
        await page.goto(`/wp-admin/post.php?post=${PRODUCT_ID}&action=edit`);
        await page.click('a[href="#wcmw_product_data"]');

        const checkbox = page.locator('#wcmw_product_enabled');
        if (!(await checkbox.isChecked())) {
            await page.click('#wcmw-toggle-wrap');
        }

        const testUrl = 'https://httpbin.org/post';
        await page.fill('#wcmw_product_url', testUrl);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'load', timeout: 30000 }),
            page.click('#publish'),
        ]);
        await page.click('a[href="#wcmw_product_data"]');
        await expect(page.locator('#wcmw_product_url')).toHaveValue(testUrl);
    });

    test('테스트 발송 버튼 클릭 시 결과가 표시된다', async ({ page }) => {
        await page.goto(`/wp-admin/post.php?post=${PRODUCT_ID}&action=edit`);
        await page.click('a[href="#wcmw_product_data"]');

        const checkbox = page.locator('#wcmw_product_enabled');
        if (!(await checkbox.isChecked())) {
            await page.click('#wcmw-toggle-wrap');
        }
        await page.fill('#wcmw_product_url', 'https://httpbin.org/post');

        await page.click('#wcmw-product-test-btn');
        const result = page.locator('#wcmw-product-test-result');
        await expect(result).not.toBeEmpty({ timeout: 20000 });
    });

    test('admin.js가 상품 편집 페이지에 로드된다', async ({ page }) => {
        await page.goto(`/wp-admin/post.php?post=${PRODUCT_ID}&action=edit`);
        const adminJs = await page.evaluate(() =>
            Array.from(document.querySelectorAll('script[src]'))
                .some(s => s.src.includes('admin.js'))
        );
        expect(adminJs).toBe(true);
    });
});
