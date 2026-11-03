// @ts-check
/**
 * features.patron_cards: PDF library cards, the webcam/photo widget and the
 * Cards settings tab follow the module (Settings → Modules).
 *
 * Run: /Users/fabio/Documents/GitHub/run-e2e-wt3.sh tests/feature-toggle-patron-cards.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const ft = require('./helpers/feature-toggles');

test.skip(!ft.configured, 'E2E credentials not configured');

const RUN = Date.now().toString(36);
const READER = `zz-ft-card-${RUN}@test.local`;
// 1×1 white JPEG.
const JPEG = Buffer.from('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/yQALCAABAAEBAREA/8wABgAQEAX/2gAIAQEAAD8A0s8g/9k=', 'base64');

test.describe.serial('Feature toggle: patron cards and photos', () => {
  test.describe.configure({ timeout: 180000 });
  /** @type {import('@playwright/test').Page} */
  let page;
  let snapshot;
  let readerId = 0;

  test.beforeAll(async ({ browser }) => {
    snapshot = ft.readFeatures(['patron_cards', 'patron_photos']);
    await ft.writeFeatures({ patron_photos: '1' });
    ft.removeUsers('zz-ft-card-%@test.local');
    readerId = ft.createUser(READER, 'Test1234!', 'standard');
    page = await browser.newPage();
    await ft.loginAdmin(page);
  });

  test.afterAll(async () => {
    await ft.restoreFeatures(snapshot);
    ft.removeUsers('zz-ft-card-%@test.local');
    await page?.close();
  });

  test('1. printing off: administrators can still prepare card settings', async () => {
    await ft.setModuleInUi(page, 'patron_cards', false);
    await page.goto(`${ft.BASE}/admin/settings?tab=cards`);
    await expect(page.locator('a[data-settings-section="cards"]').first()).toBeAttached();
    await expect(page.locator('section[data-settings-panel="cards"]')).toBeVisible();

  });

  test('2. printing off: photo capture and display remain available', async () => {
    await page.goto(`${ft.BASE}/admin/users/details/${readerId}`);
    await expect(page.locator('h1').first()).toBeVisible();
    await expect(page.locator('#print-card')).toHaveCount(0);
    await expect(page.locator('#patron-card-section')).toBeVisible();
    await page.goto(`${ft.BASE}/admin/users/edit/${readerId}`);
    await expect(page.locator('input[name="email"]')).toBeVisible();
    await expect(page.locator('#patron-photo-widget')).toBeVisible();
    await page.goto(`${ft.BASE}/admin/users`);
    await expect(page.locator('#bulk-cards-sheet')).toHaveCount(0);
  });

  test('3. switched off: the card PDF is refused', async () => {
    for (const url of [`/admin/users/${readerId}/card-pdf`, `/admin/users/cards-pdf?format=sheet&ids=${readerId}`]) {
      const res = await page.request.get(`${ft.BASE}${url}`, { maxRedirects: 0 });
      expect(res.status()).toBe(302);
      expect(String(res.headers()['content-type'] || '')).not.toContain('pdf');
    }
  });

  test('4. photos switched off independently: upload is refused and nothing is stored', async () => {
    await ft.setModuleInUi(page, 'patron_photos', false);
    const csrf = await ft.csrfToken(page);
    const res = await page.request.post(`${ft.BASE}/admin/users/${readerId}/photo`, {
      headers: { Accept: 'application/json' },
      multipart: { csrf_token: csrf, photo: { name: 'p.jpg', mimeType: 'image/jpeg', buffer: JPEG } },
      maxRedirects: 0,
    });
    expect(res.status()).toBe(404);
    expect((await res.json()).code).toBe('feature_disabled');
    expect(ft.dbQuery(`SELECT COALESCE(foto_profilo_url, '') FROM utenti WHERE id=${readerId}`)).toBe('');
  });

  test('5. switched back on: settings tab, buttons and the PDF card are back', async () => {
    await ft.setModuleInUi(page, 'patron_cards', true);
    await page.goto(`${ft.BASE}/admin/settings?tab=cards`);
    await expect(page.locator('section[data-settings-panel="cards"]')).toBeVisible();
    await page.goto(`${ft.BASE}/admin/users/details/${readerId}`);
    await expect(page.locator('#print-card')).toBeVisible();
    await expect(page.locator('#patron-card-section')).toHaveCount(0);
    const res = await page.request.get(`${ft.BASE}/admin/users/${readerId}/card-pdf`);
    expect(res.status()).toBe(200);
    expect(String(res.headers()['content-type'] || '')).toContain('application/pdf');
    await page.goto(`${ft.BASE}/admin/users/edit/${readerId}`);
    await expect(page.locator('#patron-photo-widget')).toHaveCount(0);
    await ft.setModuleInUi(page, 'patron_photos', true);
    await page.goto(`${ft.BASE}/admin/users/edit/${readerId}`);
    await expect(page.locator('#patron-photo-widget')).toBeVisible();
  });
});
