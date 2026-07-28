// @ts-check
// Issue #298 — catalogue grid alignment. A subtitle is optional and used to be
// rendered only when present, so a card WITH a subtitle pushed its author /
// publisher / "Details" down relative to neighbouring cards WITHOUT one. The fix
// keeps the subtitle conditional (no wasted space on rows where nobody has one)
// but a per-row script reserves the subtitle's height on the other cards of any
// row that DOES contain one — so everything lines up row by row.
//
// This asserts, on a real catalogue: (a) rows that contain a subtitle keep their
// authors aligned across cards, (b) a spacer is actually injected there, and
// (c) rows with no subtitle get no spacer (stay compact). Pure layout behaviour.
//
// Run: /tmp/run-e2e.sh tests/catalog-subtitle-align-298.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(!DB_USER || !DB_NAME, 'DB credentials not configured (this spec seeds the catalogue directly)');

function db(sql) {
  const args = [];
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}
function sqlq(s) { return "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'"; }

const TAG = 'ZZ298ALIGN';
// A handful of books so a 2/3-column grid puts a subtitled card next to a
// plain one: several plain titles + two with a subtitle.
const SEED = [
  { t: `${TAG} Alpha`, s: null },
  { t: `${TAG} Bravo`, s: null },
  { t: `${TAG} Charlie has a rather long subtitle`, s: 'An explanatory subtitle that runs across the card width' },
  { t: `${TAG} Delta`, s: null },
  { t: `${TAG} Echo`, s: null },
  { t: `${TAG} Foxtrot`, s: 'Another subtitle, second one' },
];

test.beforeAll(() => {
  db(`DELETE FROM libri WHERE titolo LIKE ${sqlq(TAG + '%')}`);
  for (const b of SEED) {
    const sub = b.s === null ? 'NULL' : sqlq(b.s);
    db(`INSERT INTO libri (titolo, sottotitolo, stato) VALUES (${sqlq(b.t)}, ${sub}, 'disponibile')`);
  }
});
test.afterAll(() => {
  db(`DELETE FROM libri WHERE titolo LIKE ${sqlq(TAG + '%')}`);
});

test('#298: subtitle space is reserved per row so cards stay aligned', async ({ page }) => {
  await page.setViewportSize({ width: 1200, height: 1400 });
  await page.goto(`${BASE}/catalogo`);
  // The alignment script runs on load and settles; wait for it rather than networkidle.
  await page.waitForFunction(() => document.querySelectorAll('.books-grid .book-card').length > 0, { timeout: 15000 });
  await page.waitForTimeout(600);

  const r = await page.evaluate(() => {
    const cards = Array.from(document.querySelectorAll('.books-grid .book-card'));
    // group by visual row
    const rows = [];
    cards.forEach((c) => {
      const top = Math.round(c.getBoundingClientRect().top);
      let row = rows.find((x) => Math.abs(x.top - top) < 8);
      if (!row) { row = { top, cards: [] }; rows.push(row); }
      row.cards.push(c);
    });
    let rowsWithSubtitle = 0, spacers = 0, misalignedRows = 0, badSpacerRows = 0;
    rows.forEach((row) => {
      const hasSub = row.cards.some((c) => c.querySelector('.book-subtitle:not(.subtitle-ph)'));
      if (!hasSub) {
        // compact row: no placeholder must have been injected
        if (row.cards.some((c) => c.querySelector('.subtitle-ph'))) badSpacerRows++;
        return;
      }
      rowsWithSubtitle++;
      spacers += row.cards.filter((c) => c.querySelector('.subtitle-ph')).length;
      // authors must line up across the row (allow a couple px for sub-pixel)
      const tops = row.cards
        .map((c) => c.querySelector('.book-author'))
        .filter(Boolean)
        .map((a) => Math.round(a.getBoundingClientRect().top));
      if (tops.length > 1 && Math.max(...tops) - Math.min(...tops) > 3) misalignedRows++;
    });
    return { total: cards.length, rowsWithSubtitle, spacers, misalignedRows, badSpacerRows };
  });

  expect(r.total, 'catalogue rendered cards').toBeGreaterThan(0);
  expect(r.rowsWithSubtitle, 'at least one row contains a subtitle (seeded)').toBeGreaterThan(0);
  expect(r.misalignedRows, 'every row with a subtitle keeps its authors aligned').toBe(0);
  expect(r.badSpacerRows, 'rows without a subtitle get no spacer (stay compact)').toBe(0);
  expect(r.spacers, 'a spacer was reserved on the plain cards of subtitle rows').toBeGreaterThan(0);
});
