// @ts-check
/**
 * Shared fixtures for the issue #501 E2E suites (quoted search = exact
 * spelling): a per-run token, the seeded rows that differ only by an umlaut,
 * and the waits a librarian's typing triggers.
 */
const path = require('path');
const { execFileSync } = require('child_process');
const { expect } = require('@playwright/test');

const ROOT = path.join(__dirname, '..', '..');
const SEED = path.join(__dirname, 'exact-search-501-seed.php');

/** Letters only, capitalised: the token becomes part of FULLTEXT words. */
function makeToken() {
  const letters = Math.random().toString(36).replace(/[^a-z]/g, '').slice(0, 6).padEnd(6, 'x');
  return 'Zq' + letters;
}

/**
 * Seed the fixtures and describe them.
 * @param {string} token
 */
function seed(token, fields = false) {
  const out = execFileSync('php', [SEED, fields ? 'seed-fields' : 'seed', token], { cwd: ROOT, encoding: 'utf-8', timeout: 30000, env: process.env });
  const ids = JSON.parse(out.trim());
  return {
    token,
    ids,
    authorPlain: `${token} Haffner`,
    authorUmlaut: `${token} Häffner`,
    authorUpper: `${token} HAFFNER`,
    publisherPlain: `Verlag ${token} Haffner`,
    publisherUmlaut: `Verlag ${token} Häffner`,
    publisherPercent: `Verlag ${token} 50% Rabatt`,
    titlePlain: `${token} Brückenbuch`,
    titleUmlaut: `${token} Gedichtband`,
    titleUpper: `${token} Großbuch`,
    userPlain: `${token} Haffner`,
    userUmlaut: `${token} Häffner`,
  };
}

/** @param {{ids: object}} fixtures */
function clean(fixtures) {
  if (!fixtures) return;
  execFileSync('php', [SEED, 'clean', JSON.stringify(fixtures.ids)], { cwd: ROOT, encoding: 'utf-8', timeout: 30000, env: process.env });
}

/**
 * Log in through the real login form.
 * @param {import('@playwright/test').Page} page
 * @param {string} base
 * @param {string} email
 * @param {string} password
 */
async function login(page, base, email, password) {
  await page.goto(`${base}/accedi`);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.endsWith('/accedi'), { timeout: 20000 }),
    page.click('button[type="submit"]'),
  ]);
}

/**
 * Type into a filter box the way a person does and wait for the request the
 * typing triggers. Assertions afterwards use Playwright's auto-waiting
 * expectations, positive first, so no fixed sleep is needed.
 * @param {import('@playwright/test').Page} page
 * @param {string} selector
 * @param {string} value
 * @param {string|RegExp} urlPart
 */
async function typeAndWait(page, selector, value, urlPart) {
  const matches = (/** @type {string} */ url) => (typeof urlPart === 'string' ? url.includes(urlPart) : urlPart.test(url));
  const done = page.waitForResponse((r) => matches(r.url()) && r.status() === 200, { timeout: 20000 });
  await page.fill(selector, value);
  await page.locator(selector).press('End');
  await done;
}

/**
 * Expect a region to show every title in `present` and none in `absent`.
 * @param {import('@playwright/test').Locator} region
 * @param {string[]} present
 * @param {string[]} absent
 */
async function expectTitles(region, present, absent) {
  for (const title of present) {
    await expect(region).toContainText(title);
  }
  for (const title of absent) {
    await expect(region).not.toContainText(title);
  }
}

module.exports = { makeToken, seed, clean, login, typeAndWait, expectTitles };
