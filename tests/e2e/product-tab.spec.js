const { test, expect } = require('@playwright/test');
const { login } = require('./helpers');

test.describe('상품 편집 — 웹훅 탭', () => {
    let productId;

    test.beforeAll(async ({ browser }) => {
        // 테스트용 상품 생성
        const page = await browser.newPage();
        await login(page);
        await page.goto('/wp-admin/post-new.php?post_type=product');
        await page.fill('#title', 'E2E 테스트 상품');
        await page.evaluate(() => {
            document.querySelector('#publish').click();
        });
        await page.waitForURL('**/post.php?post=**');
        const url = page.url();
        productId = new URL(url).searchParams.get('post');
        await page.close();
    });

    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('상품 편집 페이지에 웹훅 탭이 존재한다', async ({ page }) => {
        await page.goto(`/wp-admin/post.php?post=${productId}&action=edit`);
        await expect(page.locator('a[href="#wcmw_product_data"]')).toBeVisible();
    });

    test('웹훅 탭 클릭 시 패널이 표시된다', async ({ page }) => {
        await page.goto(`/wp-admin/post.php?post=${productId}&action=edit`);
        await page.click('a[href="#wcmw_product_data"]');
        await expect(page.locator('#wcmw_product_data')).toBeVisible();
        await expect(page.locator('#wcmw-toggle-wrap')).toBeVisible();
    });

    test('토글 클릭 시 URL 입력 필드가 표시된다', async ({ page }) => {
        await page.goto(`/wp-admin/post.php?post=${productId}&action=edit`);
        await page.click('a[href="#wcmw_product_data"]');

        const checkbox = page.locator('#wcmw_product_enabled');
        const urlField = page.locator('#wcmw_url_field');
        const isChecked = await checkbox.isChecked();

        if (!isChecked) {
            await page.click('#wcmw-toggle-wrap');
            await expect(urlField).toBeVisible();
        } else {
            await expect(urlField).toBeVisible();
        }
    });

    test('웹훅 URL 저장 후 유지된다', async ({ page }) => {
        await page.goto(`/wp-admin/post.php?post=${productId}&action=edit`);
        await page.click('a[href="#wcmw_product_data"]');

        const checkbox = page.locator('#wcmw_product_enabled');
        if (!(await checkbox.isChecked())) {
            await page.click('#wcmw-toggle-wrap');
        }

        const testUrl = 'https://httpbin.org/post';
        await page.fill('#wcmw_product_url', testUrl);
        await page.click('#publish');
        await page.waitForURL(`**/post=${productId}**`);
        await page.click('a[href="#wcmw_product_data"]');
        await expect(page.locator('#wcmw_product_url')).toHaveValue(testUrl);
    });

    test('테스트 발송 버튼 클릭 시 결과가 표시된다', async ({ page }) => {
        await page.goto(`/wp-admin/post.php?post=${productId}&action=edit`);
        await page.click('a[href="#wcmw_product_data"]');

        const checkbox = page.locator('#wcmw_product_enabled');
        if (!(await checkbox.isChecked())) {
            await page.click('#wcmw-toggle-wrap');
        }
        await page.fill('#wcmw_product_url', 'https://httpbin.org/post');

        await page.click('#wcmw-product-test-btn');
        const result = page.locator('#wcmw-product-test-result');
        await expect(result).not.toBeEmpty({ timeout: 15000 });
    });

    test('admin.js가 상품 편집 페이지에 로드된다', async ({ page }) => {
        await page.goto(`/wp-admin/post.php?post=${productId}&action=edit`);
        const adminJs = await page.evaluate(() =>
            Array.from(document.querySelectorAll('script[src]'))
                .some(s => s.src.includes('admin.js'))
        );
        expect(adminJs).toBe(true);
    });
});
