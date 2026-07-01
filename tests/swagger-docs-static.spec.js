// @ts-check

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');

test.describe('Mobile API Swagger docs static contract', () => {
  test('header and diagnostic links target /api/v1 endpoints, never route-relative parents', () => {
    const source = fs.readFileSync(
      path.join(ROOT, 'storage/plugins/mobile-api/src/Controllers/SwaggerUiController.php'),
      'utf-8',
    );

    expect(source).toContain('GET /api/v1/docs');
    expect(source).toContain("/api/v1/openapi.json'");
    expect(source).toContain("/api/v1/health'");
    expect(source).toContain('href="{$healthHref}"');
    expect(source).toContain('href="{$openApiHref}"');
    expect(source).not.toContain('href="../health"');
    expect(source).not.toContain('href="../openapi.json"');
  });
});
