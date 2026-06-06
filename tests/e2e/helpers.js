const WP_ADMIN = process.env.WP_ADMIN || 'admin';
const WP_PASS  = process.env.WP_PASS  || 'password';

async function login(page) {
    await page.goto('/wp-login.php');
    await page.fill('#user_login', WP_ADMIN);
    await page.fill('#user_pass', WP_PASS);
    await page.evaluate(() => document.querySelector('#wp-submit').form.submit());
    await page.waitForURL('**/wp-admin/**');
}

module.exports = { login };
