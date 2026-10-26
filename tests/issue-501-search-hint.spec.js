// @ts-check
/**
 * Issue #501, part 9 of 9: people must be able to find out that quotes exist.
 * The catalogue shows a hint under its search box; the header search and the
 * back-office filter boxes carry it as their accessible description (tooltip).
 * Checked in every shipped locale, logged in as a user of that locale, and
 * always as a DESCRIPTION: the hint must never replace the field's name for a
 * screen reader.
 */
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
// e2e-fixtures throws while loading when the E2E environment is missing, so
// it is required only when the suite is going to run; the skip reports why.
const E2E_READY = Boolean(process.env.E2E_BASE_URL && process.env.E2E_DB_USER && process.env.E2E_DB_NAME
  && (process.env.E2E_DB_HOST || process.env.E2E_DB_SOCKET));
test.skip(!E2E_READY, 'E2E environment not configured (use /tmp/run-e2e.sh)');
const { createTempAdminUser, deleteTempAdminUser } = E2E_READY ? require('./helpers/e2e-fixtures') : {};
const { login } = require('./helpers/exact-search-501');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';

const KEY = 'Usa le virgolette per la grafia esatta: "Häffner" trova solo Häffner, non Haffner.';
const LOCALES = ['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'];

/** @param {string} locale */
function hintFor(locale) {
  const file = path.join(__dirname, '..', 'locale', `${locale}.json`);
  const value = JSON.parse(fs.readFileSync(file, 'utf-8'))[KEY];
  if (typeof value !== 'string' || value === '') {
    throw new Error(`missing translation of the quote hint in ${locale}`);
  }
  return value;
}

for (const locale of LOCALES) {
  test.describe.serial(`#501 quote hint — ${locale}`, () => {
    const hint = hintFor(locale);
    /** @type {{id:number,email:string,password:string}} */
    let user;
    /** @type {import('@playwright/test').BrowserContext} */
    let context;
    /** @type {import('@playwright/test').Page} */
    let page;

    test.beforeAll(async ({ browser }) => {
      user = createTempAdminUser(locale);
      context = await browser.newContext();
      page = await context.newPage();
      await login(page, BASE, user.email, user.password);
    });
    test.afterAll(async () => {
      await context?.close();
      deleteTempAdminUser(user?.id);
    });

    test(`${locale} 1/5 the catalogue shows the hint under its search box`, async () => {
      await page.goto(`${BASE}/catalogo`);
      await expect(page.locator('#search-hint')).toBeVisible();
      await expect(page.locator('#search-hint')).toHaveText(hint);
    });

    test(`${locale} 2/5 the catalogue search box is described by the hint`, async () => {
      await expect(page.locator('#search-input')).toHaveAccessibleDescription(hint);
    });

    test(`${locale} 3/5 the header search box carries the hint as its description`, async () => {
      const box = page.locator('form.search-form input[name="q"]:visible');
      await expect(box).toHaveAttribute('title', hint);
      await expect(box).not.toHaveAccessibleName(hint);
    });

    test(`${locale} 4/5 the admin book filter carries the hint without losing its label`, async () => {
      await page.goto(`${BASE}/admin/books`);
      const box = page.locator('#search_text');
      await expect(box).toHaveAccessibleDescription(hint);
      await expect(box).not.toHaveAccessibleName(hint);
    });

    test(`${locale} 5/5 the admin author filter carries the hint without losing its label`, async () => {
      await page.goto(`${BASE}/admin/authors`);
      const box = page.locator('#search_nome');
      await expect(box).toHaveAccessibleDescription(hint);
      await expect(box).not.toHaveAccessibleName(hint);
    });
  });
}
