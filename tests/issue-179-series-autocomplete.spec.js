// @ts-check
// Issue #179 — the book-form "universe / group / cycle / series" fields must
// propose EXISTING values (Choices.js autocomplete) so a typo no longer spawns a
// duplicate universe, while still letting the user create a brand-new value.
const { test, expect } = require('@playwright/test');
test.describe.configure({ mode: 'serial' });

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';

let page;

test.beforeAll(async ({ browser }) => {
  test.skip(!ADMIN_EMAIL, 'creds not configured (use /tmp/run-e2e.sh)');
  page = await browser.newPage();
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.click('button[type="submit"]');
  await page.waitForLoadState('networkidle');
});
test.afterAll(async () => { await page?.close(); });

// The serie_padre (universe) Choices wrapper + its hidden value input.
const universeWrapper = () => page.locator('.choices', { has: page.locator('#serie_padre_select') });

test('Universe field suggests an EXISTING universe and writes it to the hidden input', async () => {
  await page.goto(`${BASE}/admin/books/create`);
  await page.waitForSelector('#serie_padre_select', { state: 'attached', timeout: 10000 });

  // Open the single-select Choices and type into its search box.
  await universeWrapper().click();
  const search = universeWrapper().locator('input[type="search"], .choices__input--cloned').first();
  await search.fill('seed');

  // An existing universe (seeded: "Seed: Fairy Tail Universe") must appear.
  const suggestion = page.locator('.choices__list--dropdown .choices__item--selectable', {
    hasText: 'Seed: Fairy Tail Universe',
  }).first();
  await expect(suggestion).toBeVisible({ timeout: 8000 });
  await suggestion.click();

  // The hidden field that submits to the form carries the picked value.
  await expect(page.locator('#serie_padre')).toHaveValue('Seed: Fairy Tail Universe');
});

test('Typing a brand-new universe name commits it (create-new path)', async () => {
  await page.goto(`${BASE}/admin/books/create`);
  await page.waitForSelector('#serie_padre_select', { state: 'attached', timeout: 10000 });

  const NEW = 'Universo Test 179 ' + Date.now();
  await universeWrapper().click();
  const search = universeWrapper().locator('input[type="search"], .choices__input--cloned').first();
  await search.fill(NEW);
  // The typed value is always offered as a selectable option; Enter commits it.
  await search.press('Enter');

  await expect(page.locator('#serie_padre')).toHaveValue(NEW);
});

// All four series fields share the same generic autocomplete: each suggests its
// own existing values (from the right collane source) and writes the picked
// value to its hidden input.
for (const f of [
  { sel: '#gruppo_serie_select', hidden: '#gruppo_serie', q: 'fairy', expect: 'Fairy Tail' },
  { sel: '#ciclo_serie_select',  hidden: '#ciclo_serie',  q: 'ciclo', expect: 'Ciclo 1' },
  { sel: '#collana_select',      hidden: '#collana',      q: 'fairy', expect: 'Seed: Fairy Tail' },
]) {
  test(`Field ${f.sel} suggests existing values and writes the pick to ${f.hidden}`, async () => {
    await page.goto(`${BASE}/admin/books/create`);
    await page.waitForSelector(f.sel, { state: 'attached', timeout: 10000 });
    const wrapper = page.locator('.choices', { has: page.locator(f.sel) });
    await wrapper.click();
    const search = wrapper.locator('input[type="search"], .choices__input--cloned').first();
    await search.fill(f.q);
    const item = page.locator('.choices__list--dropdown .choices__item--selectable', { hasText: f.expect }).first();
    await expect(item).toBeVisible({ timeout: 8000 });
    await item.click();
    await expect(page.locator(f.hidden)).toHaveValue(f.expect);
  });
}
