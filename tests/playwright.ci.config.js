// @ts-check
const { defineConfig } = require('@playwright/test');
const base = require('./playwright.config');

module.exports = defineConfig(base, {
  forbidOnly: true,
  workers: 1,
  retries: 1,
  reporter: [
    ['line'],
    ['json', { outputFile: process.env.PLAYWRIGHT_JSON_OUTPUT_FILE || 'test-results/results.json' }],
    ['html', { outputFolder: process.env.PLAYWRIGHT_HTML_OUTPUT_DIR || 'playwright-report', open: 'never' }],
  ],
  use: {
    ...base.use,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
});
