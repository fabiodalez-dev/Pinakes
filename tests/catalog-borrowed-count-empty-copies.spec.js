// @ts-check
// The catalogue availability filter derived "On loan" from copie_disponibili <= 0,
// which also matches books with NO copies at all (copie_totali = 0 → copie_disponibili
// = 0), so records with zero copies inflated the "On loan" count. The fix requires
// copie_totali > 0 for "On loan", and counts "All books" directly (a no-copies book
// is neither available nor on loan, so available + borrowed would under-count it).
//
// This seeds books of each kind and checks the filter badges by DELTA: adding one
// available, one on-loan and two no-copies books raises "On loan" by 1 (not 3),
// "Available" by 1 and "All books" by 4.
//
// Run: /tmp/run-e2e.sh tests/catalog-borrowed-count-empty-copies.spec.js --config=tests/playwright.config.js --workers=1
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

const TAG = 'ZZBORROW298';
async function counts(page) {
  await page.goto(`${BASE}/catalogo`);
  await page.waitForSelector('#borrowed-books-count', { timeout: 15000 });
  const read = async (id) => {
    const txt = (await page.locator('#' + id).first().textContent()) || '0';
    return parseInt(txt.replace(/[^0-9]/g, ''), 10) || 0;
  };
  return { all: await read('total-books-count'), available: await read('available-books-count'), borrowed: await read('borrowed-books-count') };
}

test.afterAll(() => { db(`DELETE FROM libri WHERE titolo LIKE ${sqlq(TAG + '%')}`); });

test('#298-borrowed: no-copies books are not counted as "on loan"', async ({ page }) => {
  db(`DELETE FROM libri WHERE titolo LIKE ${sqlq(TAG + '%')}`);
  const before = await counts(page);

  // 1 available, 1 truly on loan (has a copy, none available), 2 with no copies at all
  db(`INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (${sqlq(TAG + ' available')}, 'disponibile', 1, 1)`);
  db(`INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (${sqlq(TAG + ' on loan')},   'prestato',    1, 0)`);
  db(`INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (${sqlq(TAG + ' no copies 1')},'disponibile', 0, 0)`);
  db(`INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (${sqlq(TAG + ' no copies 2')},'disponibile', 0, 0)`);

  const after = await counts(page);

  // "On loan" rises by exactly 1 — the two no-copies books must NOT count.
  expect(after.borrowed - before.borrowed, '"On loan" counts only the real loan, not the no-copies records').toBe(1);
  // "Available" rises by 1.
  expect(after.available - before.available, '"Available" counts the one with a free copy').toBe(1);
  // "All books" rises by 4 (all of them, including the two no-copies records).
  expect(after.all - before.all, '"All books" counts every record, including no-copies ones').toBe(4);
});
