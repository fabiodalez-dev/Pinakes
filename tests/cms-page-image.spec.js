// @ts-check
// The image uploaded for a CMS page ("Chi siamo") is SHOWN, in the editor's
// preview and on the public page. Up to 0.8.0 the upload was written to
// storage/uploads/cms (outside the web root) while the /uploads/cms/<file>
// URL was saved: the image answered 404 everywhere. Also covers the repair of
// an image an older version left in storage/uploads/cms.
//
// Run: /tmp/run-e2e.sh tests/cms-page-image.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const APP_ROOT = path.resolve(__dirname, '..');

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME, 'E2E admin and database credentials required');

function db(sql) {
  const args = ['--default-character-set=utf8mb4', '-N', '-B', '-e', sql];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_PORT) args.push('-P', DB_PORT);
  if (!DB_HOST && DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 15000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}
const q = (v) => "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'cmsimg-'));
const JPG = path.join(tmp, 'about.jpg');

async function login(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForFunction(() => !location.pathname.includes('accedi') && !location.pathname.includes('login'), { timeout: 15000 });
}

test.describe.serial('CMS page image', () => {
  /** @type {{slug:string, image:string}|null} */
  let saved = null;
  const created = [];

  test.beforeAll(() => {
    execFileSync('php', ['-r', `$im=imagecreatetruecolor(120,80);imagefilledrectangle($im,0,0,120,80,imagecolorallocate($im,30,140,90));imagejpeg($im,${JSON.stringify(JPG)});`], { timeout: 15000 });
  });

  test.afterAll(() => {
    if (saved) {
      try { db(`UPDATE cms_pages SET image = ${saved.image === 'NULL' ? 'NULL' : q(saved.image)} WHERE slug = ${q(saved.slug)}`); } catch { /* best effort */ }
    }
    for (const f of created) { try { fs.rmSync(f, { force: true }); } catch { /* ignore */ } }
    try { fs.rmSync(tmp, { recursive: true, force: true }); } catch { /* ignore */ }
  });

  test('an image uploaded in the editor is shown in the preview and on the public page', async ({ page }) => {
    const consoleErrors = [];
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
    await login(page);
    await page.goto(`${BASE}/admin/cms/chi-siamo`, { waitUntil: 'networkidle' });
    const slug = decodeURIComponent(new URL(page.url()).pathname.split('/').pop() || 'chi-siamo');
    const before = db(`SELECT IFNULL(image, 'NULL') FROM cms_pages WHERE slug = ${q(slug)} LIMIT 1`);
    saved = { slug, image: before };

    await page.locator('#uppy-container input[type="file"]').first().setInputFiles(JPG);
    await page.locator('#uppy-container .uppy-StatusBar-actionBtn--upload').first().click();
    await expect(page.locator('#image-url')).toHaveValue(/^\/uploads\/cms\/cms_[a-f0-9]{32}\.jpg$/, { timeout: 15000 });
    const url = await page.inputValue('#image-url');
    created.push(path.join(APP_ROOT, 'public', url));

    // The preview really loads (naturalWidth > 0), the stored URL is served.
    await expect.poll(() => page.evaluate(() => /** @type {HTMLImageElement} */ (document.getElementById('preview-img')).naturalWidth)).toBe(120);
    const served = await page.request.get(`${BASE}${url}`);
    expect(served.status()).toBe(200);
    expect(served.headers()['content-type'] || '').toMatch(/^image\//);
    expect(consoleErrors.filter((e) => /reading 'error'/.test(e)), 'no Uppy state error after the upload').toEqual([]);

    await Promise.all([page.waitForURL(/saved=1/, { timeout: 15000 }), page.locator('form button[type="submit"]').last().click()]);

    await page.goto(`${BASE}/chi-siamo`, { waitUntil: 'networkidle' });
    const img = page.locator('img.static-image');
    await expect(img).toHaveAttribute('src', new RegExp(url.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&') + '$'));
    await expect.poll(() => img.evaluate((el) => /** @type {HTMLImageElement} */ (el).naturalWidth)).toBe(120);
  });

  test('an image an older version stored outside the web root is repaired and shown', async ({ page }) => {
    test.skip(saved === null, 'needs the page from the first test');
    const name = `cms_${crypto.randomBytes(16).toString('hex')}.jpg`;
    const legacyDir = path.join(APP_ROOT, 'storage', 'uploads', 'cms');
    fs.mkdirSync(legacyDir, { recursive: true });
    const legacy = path.join(legacyDir, name);
    fs.copyFileSync(JPG, legacy);
    created.push(legacy, path.join(APP_ROOT, 'public', 'uploads', 'cms', name));
    db(`UPDATE cms_pages SET image = ${q('/uploads/cms/' + name)} WHERE slug = ${q(saved.slug)}`);

    await page.goto(`${BASE}/chi-siamo`, { waitUntil: 'networkidle' });
    const img = page.locator('img.static-image');
    await expect.poll(() => img.evaluate((el) => /** @type {HTMLImageElement} */ (el).naturalWidth)).toBe(120);
  });
});
