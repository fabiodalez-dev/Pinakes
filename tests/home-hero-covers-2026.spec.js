// @ts-check
/**
 * Home hero covers (2026 design). The hero shows a fan of book covers beside
 * the title: by default the latest catalogued covers, or up to four books
 * picked in Admin -> CMS -> Homepage. The setting is stored as JSON in the
 * hero row's `content`; the old background-image upload is gone.
 *
 * Run: /tmp/run-e2e.sh tests/home-hero-covers-2026.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';

const e2e = (key) => {
  const v = process.env[key];
  return v === undefined || v === 'undefined' ? '' : v;
};

function db(sql) {
  // TCP when a host is set (CI), the socket otherwise (local dev); the
  // password goes through MYSQL_PWD, never argv.
  const args = ['-u', e2e('E2E_DB_USER'), e2e('E2E_DB_NAME'), '-N', '-B', '-e', sql];
  if (e2e('E2E_DB_HOST')) {
    args.splice(2, 0, '-h', e2e('E2E_DB_HOST'));
    if (e2e('E2E_DB_PORT')) args.splice(4, 0, '-P', e2e('E2E_DB_PORT'));
  } else if (e2e('E2E_DB_SOCKET')) {
    args.splice(2, 0, '-S', e2e('E2E_DB_SOCKET'));
  }
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: e2e('E2E_DB_PASS') } }).trim();
}

async function login(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', process.env.E2E_ADMIN_EMAIL || '');
  await page.fill('input[name="password"]', process.env.E2E_ADMIN_PASS || '');
  await page.locator('button[type=submit]').click();
  await page.waitForURL(u => !u.pathname.includes('accedi'));
}

let original = null;
let hasWanted = false;
let lent = null;
let pick = { id: 0, title: '' };

test.describe.serial('Home hero covers (2026)', () => {
  test.beforeAll(() => {
    if (!process.env.E2E_DB_USER) throw new Error('Run with /tmp/run-e2e.sh');
    original = db("SELECT COALESCE(content, '') FROM home_content WHERE section_key='hero'");
    // is_desiderata belongs to the desiderata plugin: filter on it only where
    // the plugin has added it (a fresh CI install may not have it).
    hasWanted = db("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'is_desiderata'") === '1';
    const notWanted = hasWanted ? ' AND COALESCE(is_desiderata,0)=0' : '';
    let found = db(`SELECT id, titolo FROM libri WHERE deleted_at IS NULL${notWanted} AND copertina_url <> '' AND copertina_url NOT LIKE '%placeholder%' ORDER BY id LIMIT 1`);
    if (found === '') {
      // The hero shows covered books only. A catalogue seeded without covers
      // (CI) lends one book a cover for the run; afterAll gives it back.
      // A book the picker can find: its search runs on search_index, which
      // rows written straight into the table do not have.
      const plain = db(`SELECT id, COALESCE(copertina_url, '') FROM libri WHERE deleted_at IS NULL${notWanted} AND COALESCE(search_index, '') <> '' ORDER BY id LIMIT 1`).split('\t');
      lent = { id: Number(plain[0]), cover: plain[1] || '' };
      db(`UPDATE libri SET copertina_url='/assets/brand/logo_small.png' WHERE id=${lent.id}`);
      found = db(`SELECT id, titolo FROM libri WHERE id=${lent.id}`);
    }
    const row = found.split('\t');
    pick = { id: Number(row[0]), title: row[1] };
    expect(pick.id, 'a catalogued book with a cover exists').toBeGreaterThan(0);
  });

  test.afterAll(() => {
    if (lent && lent.id > 0) {
      const cover = lent.cover === '' ? 'NULL' : "'" + lent.cover.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
      db(`UPDATE libri SET copertina_url=${cover} WHERE id=${lent.id}`);
    }
    if (original !== null) {
      const value = original === '' ? 'NULL' : "'" + original.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
      db(`UPDATE home_content SET content=${value} WHERE section_key='hero'`);
    }
  });

  test('1 The CMS offers latest covers or picked books, and no background upload', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/cms/home`);
    await expect(page.locator('input[name="hero[cover_mode]"][value="latest"]')).toHaveCount(1);
    await expect(page.locator('input[name="hero[cover_mode]"][value="selected"]')).toHaveCount(1);
    await expect(page.locator('#uppy-hero-upload, input[name="hero_background"]')).toHaveCount(0);
  });

  test('2 A picked book is saved and leads the hero fan', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/cms/home`);
    await page.locator('input[name="hero[cover_mode]"][value="selected"]').check();
    await expect(page.locator('#hero-cover-picker')).toBeVisible();
    await page.locator('#hero-cover-selected .hero-cover-remove').evaluateAll(btns => btns.forEach(b => b.click()));
    // The whole title: a prefix can match another edition listed first.
    await page.locator('#hero-cover-search').fill(pick.title);
    const option = page.locator('#hero-cover-results button', { hasText: pick.title }).first();
    await expect(option).toBeVisible({ timeout: 10000 });
    await option.click();
    await expect(page.locator(`#hero-cover-selected input[value="${pick.id}"]`)).toHaveCount(1);
    await page.locator('form[action$="/admin/cms/home"] button[type=submit]').last().click();
    await page.waitForLoadState('networkidle');
    const stored = JSON.parse(db("SELECT content FROM home_content WHERE section_key='hero'"));
    expect(stored.cover_mode).toBe('selected');
    expect(stored.cover_books).toEqual([pick.id]);

    const visitor = await (await page.context().browser().newContext()).newPage();
    try {
      await visitor.goto(`${BASE}/`);
      const fan = visitor.locator('.pk-fan .pk-fan__book');
      await expect(fan).toHaveCount(1);
      await expect(fan.first()).toHaveAttribute('href', new RegExp(`/${pick.id}$`));
    } finally {
      await visitor.context().close();
    }
  });

  test('3 Back to the latest covers', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/cms/home`);
    await page.locator('input[name="hero[cover_mode]"][value="latest"]').check();
    await expect(page.locator('#hero-cover-picker')).toBeHidden();
    await page.locator('form[action$="/admin/cms/home"] button[type=submit]').last().click();
    await page.waitForLoadState('networkidle');
    expect(JSON.parse(db("SELECT content FROM home_content WHERE section_key='hero'")).cover_mode).toBe('latest');
    const visitor = await (await page.context().browser().newContext()).newPage();
    try {
      await visitor.goto(`${BASE}/`);
      await expect(visitor.locator('.pk-fan .pk-fan__book').first()).toBeVisible();
      await expect(visitor.locator('.pk-fan .pk-fan__book')).toHaveCount(Number(db(`SELECT LEAST(4, COUNT(*)) FROM libri WHERE deleted_at IS NULL${hasWanted ? ' AND COALESCE(is_desiderata,0)=0' : ''} AND copertina_url <> '' AND copertina_url NOT LIKE '%placeholder%'`)));
    } finally {
      await visitor.context().close();
    }
  });
});
