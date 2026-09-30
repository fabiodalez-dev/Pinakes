// @ts-check
//
// The catalogue header (title and subtitle above the search) is edited per
// language from Settings → CMS. A language left empty keeps the shipped,
// translated wording.
//
// Run:
//   /tmp/run-e2e.sh tests/catalog-header-cms.spec.js --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';

test.skip(
  !process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASS || !process.env.E2E_DB_USER || !process.env.E2E_DB_NAME,
  'E2E credentials not configured (set E2E_ADMIN_*, E2E_DB_*)',
);

function db(sql) {
  const args = ['-u', process.env.E2E_DB_USER, process.env.E2E_DB_NAME, '-N', '-B', '-e', sql];
  if (process.env.E2E_DB_SOCKET) args.unshift('-S', process.env.E2E_DB_SOCKET);
  return execFileSync('mysql', args, { encoding: 'utf8', env: { ...process.env, MYSQL_PWD: process.env.E2E_DB_PASS } }).trim();
}

async function login(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  if (await page.locator('input[name=email]').isVisible()) {
    await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || '');
    await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASS || '');
    await page.locator('button[type=submit]').click();
    await page.waitForURL(u => !u.pathname.includes('accedi') && !u.pathname.includes('login'));
  }
}

async function catalogHeader(page, locale) {
  await page.goto(`${BASE}/language/${locale}`);
  // Public routes follow the installation language, whatever the visitor reads.
  await page.goto(`${BASE}${process.env.E2E_CATALOG_PATH || '/catalogo'}`);
  const header = page.locator('.catalog-header-content');
  return {
    title: (await header.locator('h1.catalog-title').innerText()).trim(),
    subtitle: (await header.locator('p.catalog-subtitle').innerText()).trim(),
  };
}

test.describe.serial('Catalogue header editable per language (Settings → CMS)', () => {
  // One row per setting, key and value in hex: the backup survives newlines,
  // any length and NULL values, and goes back exactly as it was.
  let saved = [];

  test.beforeAll(() => {
    saved = db("SELECT HEX(setting_key), IF(setting_value IS NULL, 'NULL', HEX(setting_value)) FROM system_settings WHERE category='catalog'")
      .split('\n').filter(Boolean).map((line) => line.split('\t'));
    db("DELETE FROM system_settings WHERE category='catalog'");
  });

  test.afterAll(async ({ browser }) => {
    db("DELETE FROM system_settings WHERE category='catalog'");
    for (const [keyHex, valueHex] of saved) {
      const value = valueHex === 'NULL' ? 'NULL' : `CONVERT(UNHEX('${valueHex}') USING utf8mb4)`;
      db(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('catalog', CONVERT(UNHEX('${keyHex}') USING utf8mb4), ${value})`);
    }
    // Back to the admin's language for whatever runs next.
    const page = await browser.newPage();
    await page.goto(`${BASE}/language/it_IT`);
    await page.close();
  });

  test('without overrides the catalogue shows the translated default', async ({ page }) => {
    await login(page);
    expect(await catalogHeader(page, 'it_IT')).toEqual({
      title: 'Catalogo',
      subtitle: 'Scopri migliaia di titoli nella nostra collezione digitale',
    });
    expect(await catalogHeader(page, 'en_US')).toEqual({
      title: 'Catalog',
      subtitle: 'Discover thousands of titles in our digital collection',
    });
  });

  test('the CMS tab edits each language separately', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/language/it_IT`);
    await page.goto(`${BASE}/admin/settings?tab=cms#cms`);
    const form = page.locator('#catalog-header-form');
    await expect(form).toBeVisible();
    // Every language has its own pair, with its default as placeholder.
    await expect(form.locator('input[name="catalog_title[en_US]"]')).toHaveAttribute('placeholder', 'Catalog');
    await expect(form.locator('input[name="catalog_title[it_IT]"]')).toHaveAttribute('placeholder', 'Catalogo');

    await form.locator('input[name="catalog_title[it_IT]"]').fill('Catalogo della Biblioteca femminista');
    await form.locator('input[name="catalog_subtitle[it_IT]"]').fill('Libri, riviste e <b>archivi</b> del movimento');
    await form.locator('input[name="catalog_title[en_US]"]').fill('Feminist Library catalogue');
    // en_US subtitle left empty: it keeps the default.
    await form.locator('button[type=submit]').click();
    await page.waitForURL(/\/admin\/settings\?tab=cms/);
    // The settings page confirms with its inline banner, not a SweetAlert dialog.
    await expect(page.getByText('Intestazione del catalogo aggiornata.')).toBeVisible();
    await expect(page.locator('.swal2-confirm')).toHaveCount(0);

    // The form shows what was saved.
    await expect(page.locator('input[name="catalog_title[it_IT]"]')).toHaveValue('Catalogo della Biblioteca femminista');
    // Markup is not kept: the header is plain text.
    expect(db("SELECT setting_value FROM system_settings WHERE category='catalog' AND setting_key='subtitle.it_IT'"))
      .toBe('Libri, riviste e archivi del movimento');
    expect(db("SELECT COUNT(*) FROM system_settings WHERE category='catalog' AND setting_key='subtitle.en_US'")).toBe('0');
  });

  test('each language reads its own header', async ({ page }) => {
    expect(await catalogHeader(page, 'it_IT')).toEqual({
      title: 'Catalogo della Biblioteca femminista',
      subtitle: 'Libri, riviste e archivi del movimento',
    });
    await expect(page).toHaveTitle(/Catalogo della Biblioteca femminista/);
    expect(await catalogHeader(page, 'en_US')).toEqual({
      title: 'Feminist Library catalogue',
      subtitle: 'Discover thousands of titles in our digital collection',
    });
  });

  test('emptying a field goes back to the default', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/language/it_IT`);
    await page.goto(`${BASE}/admin/settings?tab=cms#cms`);
    await page.locator('input[name="catalog_title[it_IT]"]').fill('');
    await page.locator('#catalog-header-form button[type=submit]').click();
    await page.waitForURL(/\/admin\/settings\?tab=cms/);
    await expect(page.getByText('Intestazione del catalogo aggiornata.')).toBeVisible();
    await expect(page.locator('.swal2-confirm')).toHaveCount(0);
    expect(db("SELECT COUNT(*) FROM system_settings WHERE category='catalog' AND setting_key='title.it_IT'")).toBe('0');
    expect((await catalogHeader(page, 'it_IT')).title).toBe('Catalogo');
  });

  test('a malformed post changes nothing', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/settings?tab=cms#cms`);
    const before = db("SELECT COUNT(*) FROM system_settings WHERE category='catalog'");
    expect(Number(before)).toBeGreaterThan(0);
    const csrf = await page.locator('#catalog-header-form input[name=csrf_token]').inputValue();
    // A scalar instead of the locale map must not be read as "reset every language".
    const status = await page.evaluate(async (token) => {
      const body = new URLSearchParams({ csrf_token: token, catalog_title: 'testo', catalog_subtitle: 'testo' });
      const response = await fetch(`${window.BASE_PATH || ''}/admin/settings/catalog-header`, {
        method: 'POST', body, redirect: 'manual', credentials: 'same-origin',
      });
      return response.type;
    }, csrf);
    expect(status, 'the save answers with a redirect back to the CMS tab').toBe('opaqueredirect');
    expect(db("SELECT COUNT(*) FROM system_settings WHERE category='catalog'")).toBe(before);
    // reload(): a goto to the same URL with #cms would only move the fragment.
    await page.reload();
    await expect(page.getByText('Intestazione del catalogo non salvata: dati del modulo non validi.')).toBeVisible();
  });
});
