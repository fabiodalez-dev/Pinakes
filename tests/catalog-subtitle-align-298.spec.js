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
const { flushCache } = require('./helpers/flush-cache');

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

// Wait until the alignment pass has run AFTER web fonts settle: resolve
// document.fonts.ready, then two animation frames guarantee the coalesced
// align() frame has painted. Avoids measuring a pre-font-swap layout, which
// would be flaky or hide a real reflow-after-swap bug (#302 review).
async function waitForAligned(page) {
  await page.evaluate(() => new Promise((resolve) => {
    const settle = () => requestAnimationFrame(() => requestAnimationFrame(resolve));
    if (document.fonts && document.fonts.ready && typeof document.fonts.ready.then === 'function') {
      document.fonts.ready.then(settle);
    } else {
      settle();
    }
  }));
}

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

test.beforeAll(async () => {
  db(`DELETE FROM libri WHERE titolo LIKE ${sqlq(TAG + '%')}`);
  for (const b of SEED) {
    const sub = b.s === null ? 'NULL' : sqlq(b.s);
    db(`INSERT INTO libri (titolo, sottotitolo, stato) VALUES (${sqlq(b.t)}, ${sub}, 'disponibile')`);
  }
  // Direct SQL seeding bypasses ContentCache — the bare /catalogo page 1 these
  // tests pin their fixture to may be cached from an earlier spec in the run.
  await flushCache();
});
test.afterAll(async () => {
  db(`DELETE FROM libri WHERE titolo LIKE ${sqlq(TAG + '%')}`);
  await flushCache();
});

test('#298: subtitle space is reserved per row so cards stay aligned', async ({ page }) => {
  await page.setViewportSize({ width: 1200, height: 1400 });
  await page.goto(`${BASE}/catalogo`);
  // The alignment script runs on load and settles; wait for it rather than networkidle.
  await page.waitForFunction(() => document.querySelectorAll('.books-grid .book-card').length > 0, null, { timeout: 15000 });
  await waitForAligned(page);

  // Pin the assertion to the seeded fixture: if ambient data pushed the seeds off
  // page 1 (LIMIT 12, created_at DESC), fail clearly rather than silently
  // measuring unrelated books (#302 review).
  const seedsVisible = await page.evaluate((tag) =>
    Array.from(document.querySelectorAll('.books-grid .book-title'))
      .filter((t) => (t.textContent || '').includes(tag)).length, TAG);
  expect(seedsVisible, 'seeded fixture cards are on the first page').toBeGreaterThan(0);

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

test('#298: single-column (mobile) layout injects no spurious placeholder', async ({ page }) => {
  // At a narrow viewport the auto-fill grid collapses to one column, so each
  // visual row holds exactly one card. A subtitled card alone must keep its own
  // subtitle (no placeholder), a plain card alone must get nothing, and no title
  // may collapse to zero height (#302 review — this path was previously untested).
  await page.setViewportSize({ width: 375, height: 1600 });
  await page.goto(`${BASE}/catalogo`);
  await page.waitForFunction(() => document.querySelectorAll('.books-grid .book-card').length > 0, null, { timeout: 15000 });
  await waitForAligned(page);

  const r = await page.evaluate(() => {
    const grid = document.querySelector('.books-grid');
    const cards = Array.from(grid.querySelectorAll('.book-card'));
    const cols = new Set(cards.map((c) => Math.round(c.getBoundingClientRect().left))).size;
    const placeholders = grid.querySelectorAll('.subtitle-ph').length;
    const zeroHeightTitles = cards.map((c) => c.querySelector('.book-title'))
      .filter(Boolean).filter((t) => t.offsetHeight === 0).length;
    return { cards: cards.length, cols, placeholders, zeroHeightTitles };
  });

  expect(r.cards, 'catalogue rendered cards').toBeGreaterThan(0);
  expect(r.cols, 'layout collapses to a single column at 375px').toBe(1);
  expect(r.placeholders, 'no spurious subtitle placeholder in single-column layout').toBe(0);
  expect(r.zeroHeightTitles, 'no title collapsed to zero height').toBe(0);
});

test('#298: AJAX catalogue refresh realigns the replacement cards', async ({ page }) => {
  await page.setViewportSize({ width: 1200, height: 1400 });
  await page.goto(`${BASE}/catalogo`);
  await page.waitForFunction(() => document.querySelectorAll('#books-grid .book-card').length > 0, null, { timeout: 15000 });

  // Remove the initial alignment so a placeholder can only reappear if the
  // post-AJAX replacement explicitly triggers a fresh alignment pass.
  await page.evaluate(() => {
    document.querySelectorAll('#books-grid .subtitle-ph').forEach((el) => el.remove());
    document.querySelectorAll('#books-grid .book-title, #books-grid .book-subtitle').forEach((el) => { el.style.height = ''; });
  });

  const response = page.waitForResponse((res) =>
    res.request().resourceType() === 'fetch' && res.url().includes('catalog') && res.ok()
  );
  await page.evaluate(() => updateFilter('sort', 'title_desc'));
  await response;
  await page.waitForFunction(() => document.querySelectorAll('#books-grid .subtitle-ph').length > 0, null, { timeout: 15000 });

  const result = await page.evaluate(() => {
    const cards = Array.from(document.querySelectorAll('#books-grid .book-card'));
    const rows = [];
    cards.forEach((card) => {
      const top = Math.round(card.getBoundingClientRect().top);
      let row = rows.find((candidate) => Math.abs(candidate.top - top) < 8);
      if (!row) { row = { top, cards: [] }; rows.push(row); }
      row.cards.push(card);
    });

    let subtitleRows = 0;
    let misalignedRows = 0;
    rows.forEach((row) => {
      if (!row.cards.some((card) => card.querySelector('.book-subtitle:not(.subtitle-ph)'))) return;
      subtitleRows++;
      const authorTops = row.cards
        .map((card) => card.querySelector('.book-author'))
        .filter(Boolean)
        .map((author) => Math.round(author.getBoundingClientRect().top));
      if (authorTops.length > 1 && Math.max(...authorTops) - Math.min(...authorTops) > 3) misalignedRows++;
    });
    return { subtitleRows, misalignedRows };
  });

  expect(result.subtitleRows, 'AJAX result contains a row with a subtitle').toBeGreaterThan(0);
  expect(result.misalignedRows, 'replacement cards are realigned after AJAX').toBe(0);
});

// Regression for the CodeRabbit finding: alignment must be scoped per .books-grid.
// Two side-by-side grids share the same vertical position on their first row; a
// subtitle in grid A must NOT inject a placeholder into grid B (which would happen
// if align() grouped all .book-card elements on the page globally).
test('#298: two grids on one page are aligned independently', async ({ page }) => {
  await page.setViewportSize({ width: 1200, height: 1000 });
  await page.goto(`${BASE}/catalogo`);
  // wait until the page's own script (with align()/resize listener) is live
  await page.waitForFunction(() => document.querySelectorAll('.books-grid').length > 0, null, { timeout: 15000 });

  const res = await page.evaluate(async () => {
    function card(title, subtitle) {
      var c = document.createElement('div'); c.className = 'book-card';
      var content = document.createElement('div'); content.className = 'book-content';
      var t = document.createElement('h3'); t.className = 'book-title'; t.textContent = title; content.appendChild(t);
      if (subtitle) { var s = document.createElement('p'); s.className = 'book-subtitle'; s.textContent = subtitle; content.appendChild(s); }
      var a = document.createElement('p'); a.className = 'book-author'; a.textContent = 'Author name'; content.appendChild(a);
      c.appendChild(content); return c;
    }
    var wrap = document.createElement('div');
    wrap.id = 'twogrid-regression'; wrap.style.display = 'flex'; wrap.style.gap = '20px';
    // force two columns so A1|A2 (and B1|B2) sit on the same row
    var gA = document.createElement('div'); gA.className = 'books-grid';
    gA.style.width = '340px'; gA.style.gridTemplateColumns = '1fr 1fr';
    gA.appendChild(card('A1 title', 'A subtitle that reserves a line')); gA.appendChild(card('A2 title', null));
    var gB = document.createElement('div'); gB.className = 'books-grid';
    gB.style.width = '340px'; gB.style.gridTemplateColumns = '1fr 1fr';
    gB.appendChild(card('B1 title', null)); gB.appendChild(card('B2 title', null));
    wrap.appendChild(gA); wrap.appendChild(gB);
    document.body.appendChild(wrap);

    window.dispatchEvent(new Event('resize')); // align() is debounced ~120ms
    await new Promise(function (r) { setTimeout(r, 350); });

    var out = {
      gridA_placeholders: gA.querySelectorAll('.subtitle-ph').length,
      gridB_placeholders: gB.querySelectorAll('.subtitle-ph').length,
    };
    wrap.remove();
    return out;
  });

  // grid A's plain card gets a placeholder (its row has a subtitle)…
  expect(res.gridA_placeholders, 'grid A plain card reserves the subtitle space').toBe(1);
  // …but grid B, which has no subtitle, must be untouched despite sharing the row's top.
  expect(res.gridB_placeholders, 'grid B is not affected by grid A (per-grid scope)').toBe(0);
});
