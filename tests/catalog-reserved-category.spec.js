// @ts-check
// The catalogue availability facets now mirror the recomputed libri.stato so the
// filter, the card badge and the book page agree by construction:
//   - "Available"  → copie_disponibili > 0
//   - "Reserved"   → l.stato = 'prenotato'  (held by a scheduled loan / pending
//                    request / slot reservation — present but not lendable now)
//   - "On loan"    → l.stato = 'prestato'   (a copy is actually checked out)
// A book whose copies are all out of circulation (l.stato = 'non_disponibile')
// belongs to none of the three and shows a distinct "Not available" card badge.
//
// The old proxy (copie_disponibili <= 0 AND copie_totali > 0) lumped reserved,
// pending and on-loan together under "On loan". These tests pin the split.
//
// Run: /tmp/run-e2e.sh tests/catalog-reserved-category.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(!DB_USER || !DB_NAME, 'DB credentials not configured (this spec seeds the catalogue)');

function db(sql) {
  const args = [];
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}
function sqlq(s) { return "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'"; }

const TAG = 'ZZRESV310';
const T_AVAIL = `${TAG} disponibile`;
const T_RESV  = `${TAG} prenotato`;
const T_LOAN  = `${TAG} prestato`;
const T_UNAV  = `${TAG} non disponibile`;
const T_EMPTY = `${TAG} senza copie`;

async function counts(page) {
  await page.goto(`${BASE}/catalogo`);
  await page.waitForSelector('#borrowed-books-count', { timeout: 15000 });
  const read = async (id) => {
    const el = page.locator('#' + id).first();
    if (await el.count() === 0) return 0;
    const txt = (await el.textContent()) || '0';
    return parseInt(txt.replace(/[^0-9]/g, ''), 10) || 0;
  };
  return {
    all: await read('total-books-count'),
    available: await read('available-books-count'),
    reserved: await read('reserved-books-count'),
    borrowed: await read('borrowed-books-count'),
  };
}

function seed() {
  db(`DELETE FROM libri WHERE titolo LIKE ${sqlq(TAG + '%')}`);
  // (stato, copie_totali, copie_disponibili) mirror the recomputed post-loan state.
  db(`INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (${sqlq(T_AVAIL)}, 'disponibile', 1, 1)`);
  db(`INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (${sqlq(T_RESV)},  'prenotato',   1, 0)`);
  db(`INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (${sqlq(T_LOAN)},  'prestato',    1, 0)`);
  db(`INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (${sqlq(T_UNAV)},  'non_disponibile', 1, 0)`);
  db(`INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (${sqlq(T_EMPTY)}, 'disponibile', 0, 0)`);
}

test.describe.serial('catalogue reserved category', () => {
  let before;

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    db(`DELETE FROM libri WHERE titolo LIKE ${sqlq(TAG + '%')}`);
    before = await counts(page);
    seed();
    await page.close();
  });

  test.afterAll(() => { db(`DELETE FROM libri WHERE titolo LIKE ${sqlq(TAG + '%')}`); });

  test('Reserved count rises by exactly 1 (the prenotato book)', async ({ page }) => {
    const after = await counts(page);
    expect(after.reserved - before.reserved).toBe(1);
  });

  test('On-loan count rises by exactly 1 (the prestato book only)', async ({ page }) => {
    const after = await counts(page);
    expect(after.borrowed - before.borrowed).toBe(1);
  });

  test('Available count rises by exactly 1 (the disponibile book)', async ({ page }) => {
    const after = await counts(page);
    expect(after.available - before.available).toBe(1);
  });

  test('All-books count rises by 5 (every seeded record, incl. no-copies & non_disponibile)', async ({ page }) => {
    const after = await counts(page);
    expect(after.all - before.all).toBe(5);
  });

  test('reserved and on-loan are NOT merged: borrowed delta stays 1 with a reserved book present', async ({ page }) => {
    const after = await counts(page);
    // The old proxy would have counted BOTH prenotato and prestato → delta 2.
    expect(after.borrowed - before.borrowed).toBe(1);
    expect(after.reserved - before.reserved).toBe(1);
  });

  test('?disponibilita=prenotato shows the reserved book', async ({ page }) => {
    await page.goto(`${BASE}/catalogo?disponibilita=prenotato`);
    await expect(page.locator('.book-card', { hasText: T_RESV })).toHaveCount(1);
  });

  test('?disponibilita=prenotato labels the active filter as reserved, not on loan', async ({ page }) => {
    await page.goto(`${BASE}/catalogo?disponibilita=prenotato`);
    const activeFilter = page.locator('#active-filters-list .filter-tag');
    await expect(activeFilter).toContainText(/Prenotato/i);
    await expect(activeFilter).not.toContainText(/In prestito/i);
  });

  test('?disponibilita=prenotato does NOT show the on-loan book', async ({ page }) => {
    await page.goto(`${BASE}/catalogo?disponibilita=prenotato`);
    await expect(page.locator('.book-card', { hasText: T_LOAN })).toHaveCount(0);
  });

  test('?disponibilita=prestato shows the on-loan book but not the reserved one', async ({ page }) => {
    await page.goto(`${BASE}/catalogo?disponibilita=prestato`);
    await expect(page.locator('.book-card', { hasText: T_LOAN })).toHaveCount(1);
    await expect(page.locator('.book-card', { hasText: T_RESV })).toHaveCount(0);
  });

  test('?disponibilita=disponibile shows the available book but not the reserved one', async ({ page }) => {
    await page.goto(`${BASE}/catalogo?disponibilita=disponibile`);
    await expect(page.locator('.book-card', { hasText: T_AVAIL })).toHaveCount(1);
    await expect(page.locator('.book-card', { hasText: T_RESV })).toHaveCount(0);
  });

  test('reserved book card shows the "Prenotato" badge (not "In prestito")', async ({ page }) => {
    await page.goto(`${BASE}/catalogo?disponibilita=prenotato`);
    const badge = page.locator('.book-card', { hasText: T_RESV }).locator('.book-status-badge');
    await expect(badge).toHaveText(/Prenotato/i);
  });

  test('on-loan book card shows the "In prestito" badge', async ({ page }) => {
    await page.goto(`${BASE}/catalogo?disponibilita=prestato`);
    const badge = page.locator('.book-card', { hasText: T_LOAN }).locator('.book-status-badge');
    await expect(badge).toHaveText(/In prestito/i);
  });

  test('non_disponibile book shows a distinct "Non disponibile" badge under "All"', async ({ page }) => {
    await page.goto(`${BASE}/catalogo`);
    const card = page.locator('.book-card', { hasText: T_UNAV });
    // Present in the unfiltered catalogue…
    await expect(card).toHaveCount(1);
    await expect(card.locator('.book-status-badge')).toHaveText(/Non disponibile/i);
  });
});
