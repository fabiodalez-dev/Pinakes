// @ts-check
// Public sections of the Emeroteca and Book Club plugins follow the install
// locale (route keys `periodicals` and `book_club` in locale/routes_*.json),
// and their historical literal bases (/emeroteca, /book-club) keep answering
// so existing links, bookmarks and the Android app do not break:
//  - the localized base of the default install locale answers 200;
//  - the legacy base answers 200 too, as does every other bundled spelling;
//  - the links the public pages print use the localized base, also after a
//    visitor switches to another language.
//
// Run: /tmp/run-e2e.sh tests/plugin-localized-routes.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(!DB_USER || !DB_NAME, 'E2E database credentials required');

function db(sql) {
  const args = ['--default-character-set=utf8mb4', '-N', '-B', '-e', sql];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_PORT) args.push('-P', DB_PORT);
  if (!DB_HOST && DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 15000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}
const q = (v) => "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";

const LOCALES = ['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'];
const routes = (locale) => JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'locale', `routes_${locale}.json`), 'utf-8'));
const LEGACY = { periodicals: '/emeroteca', book_club: '/book-club' };

const RUN = Date.now().toString(36);
const CLUB_SLUG = `e2e-l10n-${RUN}`;
const CLUB_NAME = `E2E L10n Club ${RUN}`;
const MASTHEAD = `E2E L10n Testata ${RUN}`;

/** Every href on the page, as a path (same-origin only). */
async function internalPaths(page) {
  const hrefs = await page.locator('a[href], form[action]').evaluateAll((nodes) =>
    nodes.map((n) => n.getAttribute('href') ?? n.getAttribute('action') ?? ''));
  const origin = new URL(BASE).origin;
  return hrefs
    .map((h) => { try { return new URL(h, BASE); } catch { return null; } })
    .filter((u) => u !== null && u.origin === origin)
    .map((u) => /** @type {URL} */ (u).pathname);
}

/** Public-menu links pointing at any spelling of the Emeroteca base. */
async function menuEmerotecaPaths(page) {
  const spellings = new Set([LEGACY.periodicals, ...LOCALES.map((l) => routes(l).periodicals)]);
  const paths = await page.locator('.header-container a, .mobile-menu a').evaluateAll((nodes) =>
    nodes.map((n) => new URL(n.getAttribute('href') || '', location.href).pathname));
  return paths.filter((p) => spellings.has(p));
}

/**
 * The Emeroteca entry of the public menu points at the localized base — when
 * the admin keeps it in the menu (cms.emeroteca_in_menu, on by default).
 */
async function expectMenuEntry(page, periodicals) {
  const inMenu = db("SELECT setting_value FROM system_settings WHERE category = 'cms' AND setting_key = 'emeroteca_in_menu' LIMIT 1");
  const paths = await menuEmerotecaPaths(page);
  if (inMenu === '' || inMenu === '1') {
    expect(paths.length, 'the public menu shows the Emeroteca entry').toBeGreaterThan(0);
  }
  for (const p of paths) expect(p, 'the Emeroteca menu entry uses the localized base').toBe(periodicals);
}

test.describe.serial('Localized public routes of the Emeroteca and Book Club plugins', () => {
  let defaultLocale = 'it_IT';
  let mastheadId = 0;

  test.beforeAll(() => {
    const active = db("SELECT GROUP_CONCAT(name ORDER BY name) FROM plugins WHERE name IN ('emeroteca','book-club') AND is_active = 1");
    test.skip(active !== 'book-club,emeroteca', 'emeroteca and book-club plugins must both be active');
    const configured = db('SELECT code FROM languages WHERE is_default = 1 LIMIT 1');
    if (LOCALES.includes(configured)) defaultLocale = configured;

    db(`INSERT INTO emeroteca_testate (titolo) VALUES (${q(MASTHEAD)})`);
    mastheadId = parseInt(db(`SELECT id FROM emeroteca_testate WHERE titolo = ${q(MASTHEAD)} LIMIT 1`), 10);
    db(`INSERT INTO bookclub_clubs (slug, name, description, privacy, ics_token, created_by, is_active)
        VALUES (${q(CLUB_SLUG)}, ${q(CLUB_NAME)}, ${q('Club pubblico di prova')}, 'public', ${q(crypto.randomBytes(16).toString('hex'))}, NULL, 1)`);
  });

  test.afterAll(() => {
    try { db(`DELETE FROM bookclub_clubs WHERE slug = ${q(CLUB_SLUG)}`); } catch { /* best effort */ }
    try { db(`DELETE FROM emeroteca_testate WHERE titolo = ${q(MASTHEAD)}`); } catch { /* best effort */ }
  });

  test('the localized base of the install locale and the legacy base both answer 200', async ({ request }) => {
    const r = routes(defaultLocale);
    const periodicals = r.periodicals;
    const bookClub = r.book_club;
    expect(periodicals, `routes_${defaultLocale}.json declares "periodicals"`).toMatch(/^\/[a-z-]+$/);
    expect(bookClub, `routes_${defaultLocale}.json declares "book_club"`).toMatch(/^\/[a-z-]+$/);

    for (const url of [
      periodicals, `${periodicals}/articoli`, `${periodicals}/${mastheadId}`,
      bookClub, `${bookClub}/${CLUB_SLUG}`,
      LEGACY.periodicals, `${LEGACY.periodicals}/articoli`, `${LEGACY.periodicals}/${mastheadId}`,
      LEGACY.book_club, `${LEGACY.book_club}/${CLUB_SLUG}`,
    ]) {
      const res = await request.get(BASE + url, { maxRedirects: 0 });
      expect(res.status(), `GET ${url}`).toBe(200);
    }
  });

  test('every bundled spelling of both bases answers 200', async ({ request }) => {
    for (const locale of LOCALES) {
      const r = routes(locale);
      for (const url of [r.periodicals, `${r.book_club}/${CLUB_SLUG}`]) {
        const res = await request.get(BASE + url, { maxRedirects: 0 });
        expect(res.status(), `GET ${url} (${locale})`).toBe(200);
      }
    }
  });

  test('the public pages link through the localized base', async ({ page }) => {
    const { periodicals, book_club: bookClub } = routes(defaultLocale);

    // Book Club index → the club card links to the localized club page.
    await page.goto(`${BASE}${bookClub}`);
    await expect(page.locator(`a[href$="${bookClub}/${CLUB_SLUG}"]`).first()).toBeVisible();
    if (bookClub !== LEGACY.book_club) {
      const legacy = (await internalPaths(page)).filter((p) => p.startsWith(LEGACY.book_club + '/') || p === LEGACY.book_club);
      expect(legacy, 'no link on the club index uses the legacy /book-club base').toEqual([]);
    }

    // Club page reached through the LEGACY base still prints localized links.
    await page.goto(`${BASE}${LEGACY.book_club}/${CLUB_SLUG}`);
    await expect(page.getByRole('heading', { name: CLUB_NAME }).first()).toBeVisible();
    const clubPaths = await internalPaths(page);
    expect(clubPaths.some((p) => p.startsWith(`${bookClub}/${CLUB_SLUG}`) || p === bookClub),
      'the club page links back into the localized club section').toBe(true);
    if (bookClub !== LEGACY.book_club) {
      expect(clubPaths.filter((p) => p.startsWith(LEGACY.book_club + '/')), 'no legacy /book-club link on the club page').toEqual([]);
    }

    // Emeroteca index → masthead link and the public menu entry.
    await page.goto(`${BASE}${periodicals}?q=${encodeURIComponent(RUN)}`);
    await expect(page.locator(`a[href$="${periodicals}/${mastheadId}"]`).first()).toBeVisible();
    await expectMenuEntry(page, periodicals);
  });

  test('after a language switch the links follow the visitor\'s locale', async ({ page }) => {
    const other = LOCALES.find((l) => l !== defaultLocale && routes(l).book_club !== LEGACY.book_club && routes(l).periodicals !== LEGACY.periodicals);
    test.skip(!other, 'no other bundled locale with distinct bases');
    const active = db(`SELECT is_active FROM languages WHERE code = ${q(other)} LIMIT 1`);
    test.skip(active !== '1', `${other} is not an active language`);
    const { periodicals, book_club: bookClub } = routes(/** @type {string} */ (other));

    await page.goto(`${BASE}/language/${other}`);
    await page.goto(`${BASE}${bookClub}`);
    await expect(page.locator(`a[href$="${bookClub}/${CLUB_SLUG}"]`).first()).toBeVisible();

    await page.goto(`${BASE}${periodicals}?q=${encodeURIComponent(RUN)}`);
    await expect(page.locator(`a[href$="${periodicals}/${mastheadId}"]`).first()).toBeVisible();
    await expectMenuEntry(page, periodicals);
  });
});
