// @ts-check
/**
 * The privacy tab: two subjects, two forms, and neither one writing the
 * other's settings.
 *
 * The tab used to interleave them. A single button labelled "Salva Privacy
 * Policy" saved the privacy page, the cookie policy page, the banner on/off
 * switch, two links and the category visibility, while the texts of that same
 * banner sat in a second form with a second button and nothing on the page
 * said so. It is now split by subject: the two public pages in one form, the
 * whole cookie banner — whether it appears, what it offers, what it says — in
 * the other.
 *
 * The regression this guards against is specific and silent. An unchecked
 * checkbox is simply absent from the post, so a handler that writes a flag its
 * own form does not carry writes `false` on every save. Move one switch across
 * the boundary and saving the pages starts turning the cookie banner off, with
 * a success message and no other sign. These tests assert the boundary from
 * both sides: what each form contains, and that saving one leaves the other's
 * stored values exactly as they were.
 *
 * Run with:
 *   /tmp/run-e2e.sh tests/settings-privacy-cookie-split.spec.js --config=tests/playwright.config.js --workers=1
 *
 * NOTE: shared DB — the orchestrator runs the suite serially.
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS ?? '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

const HAS_E2E_ENV = Boolean(ADMIN_EMAIL && ADMIN_PASS && DB_USER && DB_NAME && (DB_HOST || DB_SOCKET));

test.skip(!HAS_E2E_ENV, 'Missing E2E env vars for the privacy/cookie split tests');

function mysqlArgs(sql = '', batch = false) {
  const args = [];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER);
  if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
  args.push(DB_NAME);
  if (batch) args.push('-N', '-B');
  if (sql !== '') args.push('-e', sql);
  return args;
}

function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql, true), { encoding: 'utf-8', timeout: 10000 }).trim();
}

/**
 * The settings this tab owns, as one comparable snapshot. A missing row and an
 * empty row are different things here, so the absent ones are named rather
 * than collapsed away.
 */
function snapshot() {
  const rows = dbQuery(`
    SELECT CONCAT(category, '.', setting_key, '=', COALESCE(setting_value, ''))
    FROM system_settings
    WHERE (category = 'privacy' AND setting_key IN
            ('page_title', 'cookie_policy_content', 'cookie_banner_enabled',
             'cookie_statement_link', 'cookie_technologies_link'))
       OR (category = 'cookie_banner' AND setting_key IN ('show_analytics', 'show_marketing'))
    ORDER BY 1
  `);
  const out = {};
  rows.split('\n').filter(Boolean).forEach((line) => {
    const at = line.indexOf('=');
    out[line.slice(0, at)] = line.slice(at + 1);
  });
  return out;
}

const bannerKeys = [
  'privacy.cookie_banner_enabled',
  'privacy.cookie_statement_link',
  'privacy.cookie_technologies_link',
  'cookie_banner.show_analytics',
  'cookie_banner.show_marketing',
];

function only(snap, keys) {
  const out = {};
  keys.forEach((k) => { out[k] = snap[k]; });
  return out;
}

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const emailField = page.locator('input[name="email"]');
  if (await emailField.isVisible({ timeout: 3000 }).catch(() => false)) {
    await emailField.fill(ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
      page.waitForURL(/\/admin\//, { timeout: 15000 }),
      page.click('button[type="submit"]'),
    ]);
  }
}

async function openPrivacyTab(page) {
  await page.goto(`${BASE}/admin/settings?tab=privacy`, { waitUntil: 'networkidle' });
  await page.locator('form[action*="settings/privacy"]').first().waitFor({ timeout: 15000 });
}

/** Submit one of the two forms through its own button, as a person would. */
async function submitForm(page, actionFragment) {
  await page.evaluate(() => { if (window.tinymce) { window.tinymce.triggerSave(); } });
  await Promise.all([
    page.waitForLoadState('networkidle'),
    page.evaluate((fragment) => {
      const form = document.querySelector(`form[action*="${fragment}"]`);
      form.querySelector('button[type="submit"]').click();
    }, actionFragment),
  ]);
  await page.waitForTimeout(500);
}

test.describe.serial('Privacy tab — pages and cookie banner are saved apart', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  /** @type {Record<string,string>} */
  let original;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    original = snapshot();
    await loginAsAdmin(page);
  });

  test.afterAll(async () => {
    await context?.close();
  });

  // -------------------------------------------------------------------------
  test('1. Each form carries one subject and nothing from the other', async () => {
    await openPrivacyTab(page);

    const shape = await page.evaluate(() => {
      const names = (selector) => {
        const form = document.querySelector(selector);
        if (!form) { return null; }
        return Array.from(form.elements)
          .filter((el) => el.name && el.name !== 'csrf_token')
          .map((el) => el.name);
      };
      return {
        pages: names('form[action*="settings/privacy"]'),
        banner: names('form[action*="cookie-banner"]'),
      };
    });

    expect(shape.pages, 'the pages form must exist').not.toBeNull();
    expect(shape.banner, 'the banner form must exist').not.toBeNull();

    // The pages form holds the two public pages and stops there.
    expect(shape.pages.sort()).toEqual(['cookie_policy_content', 'page_content', 'page_title']);

    // Every switch the banner owns is in the banner form…
    ['cookie_banner_enabled', 'show_analytics', 'show_marketing',
      'cookie_statement_link', 'cookie_technologies_link'].forEach((field) => {
      expect(shape.banner, `${field} belongs to the banner form`).toContain(field);
    });
    // …and its wording is there too, rather than behind a second button.
    ['cookie_banner_description', 'cookie_accept_all_text', 'cookie_preferences_title',
      'cookie_analytics_name'].forEach((field) => {
      expect(shape.banner, `${field} is saved with the rest of the banner`).toContain(field);
    });

    // No field appears in both: that overlap is what makes a save of one
    // silently rewrite the other.
    const overlap = shape.pages.filter((f) => shape.banner.includes(f));
    expect(overlap, 'no field may belong to both forms').toEqual([]);
  });

  // -------------------------------------------------------------------------
  test('2. Saving the banner writes the banner and leaves the pages alone', async () => {
    await openPrivacyTab(page);

    const pageTitleBefore = await page.inputValue('#privacy_page_title');

    await page.evaluate(() => {
      const form = document.querySelector('form[action*="cookie-banner"]');
      form.querySelector('#cookie_banner_enabled').checked = true;
      form.querySelector('#show_analytics').checked = true;
      form.querySelector('#show_marketing').checked = false;
      form.querySelector('#cookie_statement_link').value = 'https://example.org/cookie-policy';
    });
    await submitForm(page, 'cookie-banner');

    const after = snapshot();
    expect(after['privacy.cookie_banner_enabled'], 'the banner switch is stored').toBe('1');
    expect(after['cookie_banner.show_analytics'], 'analytics stays offered').toBe('1');
    expect(after['cookie_banner.show_marketing'], 'marketing is withdrawn').toBe('0');
    expect(after['privacy.cookie_statement_link'], 'the link is stored')
      .toBe('https://example.org/cookie-policy');

    expect(after['privacy.page_title'], 'the privacy page title is untouched').toBe(pageTitleBefore);
  });

  // -------------------------------------------------------------------------
  // The one that matters: under the old arrangement the pages button also
  // wrote these five, so a save here would have turned the banner off.
  test('3. Saving the pages does not touch a single banner setting', async () => {
    const before = only(snapshot(), bannerKeys);
    expect(before['privacy.cookie_banner_enabled'], 'precondition: the banner is on').toBe('1');
    expect(before['cookie_banner.show_marketing'], 'precondition: marketing is off').toBe('0');

    await openPrivacyTab(page);
    const newTitle = `Informativa ${Date.now()}`;
    await page.fill('#privacy_page_title', newTitle);
    await submitForm(page, 'settings/privacy');

    const after = snapshot();
    expect(after['privacy.page_title'], 'the page was saved').toBe(newTitle);
    expect(only(after, bannerKeys), 'every banner setting survives a pages save').toEqual(before);
  });

  // -------------------------------------------------------------------------
  test('4. And the reverse: saving the banner does not touch the pages', async () => {
    const before = snapshot();

    await openPrivacyTab(page);
    await page.evaluate(() => {
      document.querySelector('form[action*="cookie-banner"] #show_marketing').checked = true;
    });
    await submitForm(page, 'cookie-banner');

    const after = snapshot();
    expect(after['cookie_banner.show_marketing'], 'marketing is offered again').toBe('1');
    expect(after['privacy.page_title'], 'the privacy page title is untouched')
      .toBe(before['privacy.page_title']);
    expect(after['privacy.cookie_policy_content'], 'the cookie policy page is untouched')
      .toBe(before['privacy.cookie_policy_content']);
  });

  // -------------------------------------------------------------------------
  test('5. A malformed link refuses the save instead of storing an empty one', async () => {
    const before = snapshot();

    await openPrivacyTab(page);
    await page.evaluate(() => {
      const field = document.querySelector('form[action*="cookie-banner"] #cookie_statement_link');
      // type="url" would block this client-side; the server rule is the subject.
      field.setAttribute('type', 'text');
      field.value = 'javascript:alert(1)';
    });
    await submitForm(page, 'cookie-banner');

    const after = snapshot();
    expect(after['privacy.cookie_statement_link'], 'the previous link is kept, not blanked')
      .toBe(before['privacy.cookie_statement_link']);
    await expect(page.locator('body'), 'and the refusal is said out loud')
      .toContainText(/URL HTTP o HTTPS validi|valid HTTP or HTTPS|gültige HTTP|URL HTTP ou HTTPS|gyldige HTTP/i);
  });

  // -------------------------------------------------------------------------
  test('6. The tab is left as it was found', async () => {
    await openPrivacyTab(page);
    await page.evaluate((want) => {
      const banner = document.querySelector('form[action*="cookie-banner"]');
      banner.querySelector('#cookie_banner_enabled').checked = want['privacy.cookie_banner_enabled'] === '1';
      banner.querySelector('#show_analytics').checked = want['cookie_banner.show_analytics'] !== '0';
      banner.querySelector('#show_marketing').checked = want['cookie_banner.show_marketing'] !== '0';
      banner.querySelector('#cookie_statement_link').value = want['privacy.cookie_statement_link'] || '';
      banner.querySelector('#cookie_technologies_link').value = want['privacy.cookie_technologies_link'] || '';
    }, original);
    await submitForm(page, 'cookie-banner');

    await openPrivacyTab(page);
    await page.fill('#privacy_page_title', original['privacy.page_title'] || 'Privacy Policy');
    await submitForm(page, 'settings/privacy');

    const restored = snapshot();
    expect(only(restored, bannerKeys)).toEqual(only(original, bannerKeys));
    expect(restored['privacy.page_title']).toBe(original['privacy.page_title'] || 'Privacy Policy');
  });
});
