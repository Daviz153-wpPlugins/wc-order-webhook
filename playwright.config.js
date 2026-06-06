const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests/e2e',
  timeout: 60000,
  retries: 1,
  use: {
    baseURL: process.env.WP_URL || 'http://localhost:8888',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
  reporter: [['list'], ['html', { open: 'never' }]],
});
