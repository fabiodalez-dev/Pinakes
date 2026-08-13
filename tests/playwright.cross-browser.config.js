// @ts-check
const { defineConfig, devices } = require('@playwright/test');
const base = require('./playwright.ci.config');

module.exports = defineConfig(base, {
  testMatch: /accessibility-cross-browser\.spec\.js/,
  retries: 0,
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },
  ],
});
