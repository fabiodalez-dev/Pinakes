// @ts-check
/**
 * Every text the CMS edits is the text the site shows. Driven only through
 * the admin pages, as a librarian does: read the field, compare it with the
 * public page, change it or empty it, Save, look at the page again.
 *
 *  - Homepage hero subtitle (Admin → CMS → Homepage)
 *  - Catalogue header and Events header (Settings → CMS), per language
 *  - the Settings address carries the tab once (?tab=cms, no #cms)
 *
 * Run: /tmp/run-e2e.sh tests/cms-texts-editable.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';

function db(sql) {
  const args = ['--default-character-set=utf8mb4', '-N', '-B', '-e', sql];
  if (process.env.E2E_DB_HOST) args.push('-h', process.env.E2E_DB_HOST, ...(process.env.E2E_DB_PORT ? ['-P', process.env.E2E_DB_PORT] : []));
  else if (process.env.E2E_DB_SOCKET) args.push('-S', process.env.E2E_DB_SOCKET);
  args.push('-u', process.env.E2E_DB_USER || '', process.env.E2E_DB_NAME || '');
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 15000, env: { ...process.env, MYSQL_PWD: process.env.E2E_DB_PASS || '' } }).trim();
}

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !process.env.E2E_DB_USER, 'admin and database credentials required');

test.describe.serial('CMS texts are what the site shows', () => {
  /** @type {import('@playwright/test').Page} */
  let page;
  let heroBackup = '';
  let heroFieldBackup = '';
  let settingsBackup = '';
  const stamp = Date.now();

  test.beforeAll(async ({ browser }) => {
    heroBackup = db("SELECT JSON_OBJECT('subtitle', subtitle) FROM home_content WHERE section_key = 'hero'");
    settingsBackup = db("SELECT IFNULL(JSON_ARRAYAGG(JSON_ARRAY(category, setting_key, setting_value)), JSON_ARRAY()) FROM system_settings WHERE category IN ('catalog', 'events_page')");
    page = await browser.newPage();
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL((u) => !u.pathname.includes('accedi'), { timeout: 30000 });
    // Start from a home the site has rendered from what the CMS holds: save
    // the homepage form once, unchanged, as the administrator would.
    await page.goto(`${BASE}/admin/cms/home`);
    heroFieldBackup = await page.locator('#hero_subtitle').inputValue();
    await saveHome();
  });

  test.afterAll(async () => {
    try {
      if (eventTitle) db(`DELETE FROM events WHERE title = '${eventTitle}'`);
      if (featuresWasOn === false) {
        await page.goto(`${BASE}/admin/cms/home`);
        await page.locator('#features_visible').uncheck();
        await saveHome();
      }
      // The homepage is put back through its form, so the site's cache follows.
      await page.goto(`${BASE}/admin/cms/home`);
      await page.locator('#hero_subtitle').fill(heroFieldBackup);
      await saveHome();
      db("DELETE FROM system_settings WHERE category IN ('catalog', 'events_page')");
      for (const [cat, key, value] of JSON.parse(settingsBackup || '[]')) {
        const v = String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
        db(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('${cat}', '${key}', '${v}')`);
      }
    } catch { /* best effort */ }
    await page?.close();
  });

  async function saveHome() {
    await Promise.all([
      page.waitForLoadState('load'),
      page.locator('form[action$="/admin/cms/home"] button[type=submit]').last().click(),
    ]);
  }

  test('Homepage: the subtitle field holds what the hero shows; emptied, the hero shows none; written, it shows it', async ({ browser }) => {
    const visitor = await (await browser.newContext()).newPage();
    try {
      await page.goto(`${BASE}/admin/cms/home`);
      const field = page.locator('#hero_subtitle');
      const inField = (await field.inputValue()).trim();
      await visitor.goto(`${BASE}/`);
      const shown = visitor.locator('.pk-hero__subtitle');
      if (inField === '') {
        await expect(shown, 'an empty field shows no subtitle').toHaveCount(0);
      } else {
        await expect(shown).toHaveText(inField);
      }

      await field.fill('');
      await saveHome();
      await visitor.goto(`${BASE}/`);
      await expect(visitor.locator('.pk-hero__subtitle'), 'no hidden default replaces an emptied subtitle').toHaveCount(0);

      const mine = `Sottotitolo scritto dal CMS ${stamp}`;
      await page.goto(`${BASE}/admin/cms/home`);
      await page.locator('#hero_subtitle').fill(mine);
      await saveHome();
      await visitor.goto(`${BASE}/`);
      await expect(visitor.locator('.pk-hero__subtitle')).toHaveText(mine);
      await page.goto(`${BASE}/admin/cms/home`);
      await expect(page.locator('#hero_subtitle')).toHaveValue(mine);
    } finally {
      await visitor.context().close();
    }
  });

  // The features section and the events band appear only when switched on and
  // when an event exists: set that up as the administrator does, from the admin.
  let featuresWasOn = null;
  let eventTitle = '';
  test('setup: the administrator switches on the features section and publishes an event', async () => {
    await page.goto(`${BASE}/admin/cms/home`);
    const toggle = page.locator('#features_visible');
    featuresWasOn = await toggle.isChecked();
    if (!featuresWasOn) { await toggle.check(); await saveHome(); }

    eventTitle = `Evento CMS ${stamp}`;
    await page.goto(`${BASE}/admin/cms/events/create`);
    await page.waitForLoadState('networkidle');
    await page.fill('#event_title', eventTitle);
    const inAWeek = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
    await page.locator('#event_date').evaluate((el, d) => { el.value = d; el.dispatchEvent(new Event('change', { bubbles: true })); }, inAWeek);
    if (!(await page.locator('#is_active').isChecked())) await page.locator('#is_active').check();
    await page.evaluate(() => { if (typeof tinymce !== 'undefined' && tinymce.get('event_content')) tinymce.get('event_content').setContent('<p>Evento di prova</p>'); }).catch(() => {});
    await page.locator('button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');
    expect(db(`SELECT COUNT(*) FROM events WHERE title = '${eventTitle}'`)).toBe('1');
  });

  // Every other text of the home: the field holds what the page shows, a new
  // text appears as written, an emptied field shows nothing.
  const HOME_TEXTS = [
    ['#features_title', '[data-section="features_title"] .section-title'],
    ['#features_subtitle', '[data-section="features_title"] .section-subtitle'],
    ['#latest_title', '[data-section="latest_books_title"] .section-title'],
    ['#latest_subtitle', '[data-section="latest_books_title"] .section-subtitle'],
    ['#genre_carousel_title', '[data-section="genre_carousel"] .section-title'],
    ['#genre_carousel_subtitle', '[data-section="genre_carousel"] .section-subtitle'],
    ['#events_title', '.home-events__title'],
    ['#events_subtitle', '.home-events__subtitle'],
    ['#cta_title', '.cta-title'],
    ['#cta_subtitle', '.cta-subtitle'],
  ];
  for (const [fieldSel, pageSel] of HOME_TEXTS) {
    test(`Homepage: ${fieldSel} is the text of ${pageSel}, and the page follows every change`, async ({ browser }) => {
      const visitor = await (await browser.newContext()).newPage();
      let original = null;
      try {
        await page.goto(`${BASE}/admin/cms/home`);
        const field = page.locator(fieldSel);
        original = await field.inputValue();
        // A section switched off, or the events band with no event, is not on the page at all.
        const mine = `Testo CMS ${fieldSel.slice(1)} ${stamp}`;
        await field.fill(mine);
        await saveHome();
        await visitor.goto(`${BASE}/`);
        const shown = visitor.locator(pageSel);
        test.skip(await shown.count() === 0 && await visitor.locator(pageSel.split(' ')[0]).count() === 0, 'this section is not on the home');
        await expect(shown.first()).toHaveText(mine);

        await page.goto(`${BASE}/admin/cms/home`);
        await expect(page.locator(fieldSel), 'the field shows what was saved').toHaveValue(mine);
        await page.locator(fieldSel).fill('');
        await saveHome();
        await visitor.goto(`${BASE}/`);
        await expect(visitor.locator(pageSel), 'an emptied field shows nothing: no hidden default').toHaveCount(0);
      } finally {
        if (original !== null) {
          await page.goto(`${BASE}/admin/cms/home`);
          await page.locator(fieldSel).fill(original);
          await saveHome();
        }
        await visitor.context().close();
      }
    });
  }

  for (const [pageKey, publicPath, formId] of [['catalog', '/catalogo', '#catalog-header-form'], ['events', '/eventi', '#events-header-form']]) {
    test(`Settings → CMS: the ${pageKey} header fields hold what the page shows, and the page follows them`, async ({ browser }) => {
      const visitor = await (await browser.newContext()).newPage();
      try {
        await page.goto(`${BASE}/admin/settings?tab=cms`);
        expect(new URL(page.url()).hash, 'the tab is in the address once').toBe('');
        const form = page.locator(formId);
        const title = form.locator(`input[name="${pageKey}_title[it_IT]"]`);
        const subtitle = form.locator(`input[name="${pageKey}_subtitle[it_IT]"]`);
        await expect(title, 'the field is filled with the text, not left blank behind a placeholder').not.toHaveValue('');

        await visitor.goto(`${BASE}${publicPath}`);
        await expect(visitor.locator('h1.catalog-title')).toHaveText(await title.inputValue());

        const mineTitle = `Titolo ${pageKey} ${stamp}`;
        await title.fill(mineTitle);
        await subtitle.fill('');
        await Promise.all([page.waitForLoadState('load'), form.locator('button[type=submit]').click()]);
        expect(new URL(page.url()).hash).toBe('');

        await visitor.goto(`${BASE}${publicPath}`);
        await expect(visitor.locator('h1.catalog-title')).toHaveText(mineTitle);
        await expect(visitor.locator('.catalog-header .catalog-subtitle'), 'an emptied subtitle shows nothing').toHaveCount(0);

        await page.goto(`${BASE}/admin/settings?tab=cms`);
        await expect(page.locator(formId).locator(`input[name="${pageKey}_title[it_IT]"]`)).toHaveValue(mineTitle);
        await expect(page.locator(formId).locator(`input[name="${pageKey}_subtitle[it_IT]"]`)).toHaveValue('');
      } finally {
        await visitor.context().close();
      }
    });
  }
});
