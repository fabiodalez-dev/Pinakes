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
  await page.waitForFunction(() => !location.pathname.includes('accedi') && !location.pathname.includes('login'), null, { timeout: 15000 });
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
    // The seed stores the About page as 'about-us'; the editor answers on
    // /admin/cms/chi-siamo and must edit that row, never create a second one.
    const stored = db("SELECT slug FROM cms_pages WHERE locale = 'it_IT' AND slug IN ('chi-siamo', 'about-us') ORDER BY slug = 'chi-siamo' DESC LIMIT 1") || 'chi-siamo';
    const rowsBefore = db("SELECT COUNT(*) FROM cms_pages WHERE locale = 'it_IT'");
    await page.goto(`${BASE}/admin/cms/chi-siamo`, { waitUntil: 'networkidle' });
    expect(db("SELECT COUNT(*) FROM cms_pages WHERE locale = 'it_IT'"), 'opening the editor creates no duplicate page').toBe(rowsBefore);
    // The editor may show it under the locale's slug; the row keeps its own.
    const slug = stored;
    const before = db(`SELECT IFNULL(image, 'NULL') FROM cms_pages WHERE slug = ${q(slug)} LIMIT 1`);
    saved = { slug, image: before };

    // As a librarian does: choose the image, then Save straight away. There
    // is no separate "Upload" step to remember: the image goes up when it is
    // chosen, and Save waits for it if it is still on its way.
    // A slow connection: Save is pressed while the image is still going up.
    await page.route('**/admin/cms/upload', async (route) => { await new Promise((r) => setTimeout(r, 1500)); await route.continue(); });
    await page.locator('#uppy-container input[type="file"]').first().setInputFiles(JPG);
    await Promise.all([page.waitForURL(/saved=1/, { timeout: 20000 }), page.locator('form button[type="submit"]').last().click()]);
    await page.unroute('**/admin/cms/upload');

    const url = db(`SELECT IFNULL(image, '') FROM cms_pages WHERE slug = ${q(slug)} LIMIT 1`);
    expect(url, 'the image chosen before Save is stored with the page').toMatch(/^\/uploads\/cms\/cms_[a-f0-9]{32}\.jpg$/);
    created.push(path.join(APP_ROOT, 'public', url));
    const served = await page.request.get(`${BASE}${url}`);
    expect(served.status()).toBe(200);
    expect(served.headers()['content-type'] || '').toMatch(/^image\//);

    // Back in the editor, the preview shows the saved image.
    await expect(page.locator('#image-url')).toHaveValue(url);
    await expect.poll(() => page.evaluate(() => /** @type {HTMLImageElement} */ (document.getElementById('preview-img')).naturalWidth)).toBe(120);
    await expect(page.locator('#image-preview')).toBeVisible();
    expect(consoleErrors.filter((e) => /reading 'error'/.test(e)), 'no Uppy state error after the upload').toEqual([]);

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

  test('an image removed while it is still uploading is not saved', async ({ page }) => {
    test.skip(saved === null, 'needs the page from the first test');
    await login(page);
    await page.goto(`${BASE}/admin/cms/chi-siamo`, { waitUntil: 'networkidle' });
    const kept = await page.locator('#image-url').inputValue();
    await page.route('**/admin/cms/upload', async (route) => { await new Promise((r) => setTimeout(r, 2000)); await route.continue().catch(() => {}); });
    await page.locator('#uppy-container input[type="file"]').first().setInputFiles(JPG);
    // The librarian changes their mind and removes the image, then saves.
    await page.locator('#remove-image-btn').click();
    await Promise.all([page.waitForURL(/saved=1/, { timeout: 20000 }), page.locator('form button[type="submit"]').last().click()]);
    await page.unroute('**/admin/cms/upload');
    expect(db(`SELECT IFNULL(image, '') FROM cms_pages WHERE slug = ${q(saved.slug)} LIMIT 1`), 'the removed image is not stored').toBe('');
    await expect(page.locator('#image-url')).toHaveValue('');
    // Put the page's image back for the tests that follow.
    if (kept) db(`UPDATE cms_pages SET image = ${q(kept)} WHERE slug = ${q(saved.slug)}`);
  });

  test('an upload that fails while Save waits for it is reported, and Save is usable again', async ({ page }) => {
    test.skip(saved === null, 'needs the page from the first test');
    await login(page);
    await page.goto(`${BASE}/admin/cms/chi-siamo`, { waitUntil: 'networkidle' });
    await page.route('**/admin/cms/upload', async (route) => {
      await new Promise((r) => setTimeout(r, 1500));
      await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ error: 'Errore del server' }) });
    });
    await page.locator('#uppy-container input[type="file"]').first().setInputFiles(JPG);
    const save = page.locator('form button[type="submit"]').last();
    await save.click();
    await expect(page.locator('.swal2-popup'), 'the failure is on screen').toBeVisible({ timeout: 10000 });
    await expect(save, 'Save does not stay blocked').toBeEnabled();
    expect(page.url(), 'the page was not saved without its image').not.toMatch(/saved=1/);
    await page.unroute('**/admin/cms/upload');
  });

  test('a refused file is reported on screen instead of being silently dropped', async ({ page }) => {
    test.skip(saved === null, 'needs the page from the first test');
    await login(page);
    await page.goto(`${BASE}/admin/cms/chi-siamo`, { waitUntil: 'networkidle' });
    const txt = path.join(tmp, 'not-an-image.txt');
    fs.writeFileSync(txt, 'plain text');
    await page.locator('#uppy-container input[type="file"]').first().setInputFiles(txt);
    await expect(page.locator('.swal2-popup')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('#image-url')).toHaveValue(/.*/);
  });
});
