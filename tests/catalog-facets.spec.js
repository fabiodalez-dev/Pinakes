// @ts-check
// Catalog facet tests — read-only against the dev catalog (/catalogo).
// No login required, no DB writes. Run via:
//   /tmp/run-e2e.sh tests/catalog-facets.spec.js \
//     --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');
test.describe.configure({ mode: 'serial' });

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Fetch /api/catalogo (optionally with extra params) from inside the page. */
function apiCatalog(page, params = {}) {
  return page.evaluate(async ({ base, params }) => {
    const qs = new URLSearchParams(params).toString();
    const url = base + '/api/catalogo' + (qs ? '?' + qs : '');
    const r = await fetch(url);
    return r.json();
  }, { base: BASE, params });
}

/** Call updateFilter() inside the page JS and wait for the grid reload. */
async function applyFilter(page, key, value) {
  await page.evaluate(
    ({ k, v }) => window.updateFilter(k, v),
    { k: key, v: value }
  );
  // Wait for the loading overlay to disappear and the grid to come back.
  await page.waitForFunction(
    () => {
      const loading = document.getElementById('loading-state');
      const grid = document.getElementById('books-grid');
      return (
        loading && loading.style.display === 'none' &&
        grid && grid.style.display !== 'none'
      );
    },
    { timeout: 15000 }
  );
}

/** Same as applyFilter but the grid may end up empty (empty-state visible). */
async function applyFilterMaybeEmpty(page, key, value) {
  await page.evaluate(
    ({ k, v }) => window.updateFilter(k, v),
    { k: key, v: value }
  );
  await page.waitForFunction(
    () => {
      const loading = document.getElementById('loading-state');
      const grid = document.getElementById('books-grid');
      const empty = document.getElementById('empty-state');
      const loadingGone = loading && loading.style.display === 'none';
      const gridBack = grid && grid.style.display !== 'none';
      const emptyShown = empty && empty.style.display !== 'none';
      return loadingGone && (gridBack || emptyShown);
    },
    { timeout: 15000 }
  );
}

// ---------------------------------------------------------------------------
// Suite
// ---------------------------------------------------------------------------

test.describe('Catalog facets', () => {
  let page;
  // Discovered from the API before individual tests run.
  let topPublisherName = '';
  let topAuthorId = 0;
  let topAuthorName = '';
  let hasTipoMedia = false;

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();

    // Load the catalog page first so the JS environment is alive.
    await page.goto(`${BASE}/catalogo`, { waitUntil: 'networkidle', timeout: 30000 });

    // Fetch the API once to discover filter data.
    const data = await apiCatalog(page);
    expect(data).toHaveProperty('filter_options');
    const fo = data.filter_options;

    // Discover the top publisher (most books).
    if (fo.editori && fo.editori.length > 0) {
      const sorted = [...fo.editori].sort((a, b) => (b.cnt ?? 0) - (a.cnt ?? 0));
      topPublisherName = sorted[0].nome;
    }

    // Discover an author with cnt > 0.
    if (fo.autori && fo.autori.length > 0) {
      const withCount = fo.autori.filter(a => (a.cnt ?? 0) > 0);
      if (withCount.length > 0) {
        topAuthorId = withCount[0].id;
        topAuthorName = withCount[0].nome;
      }
    }

    hasTipoMedia = Array.isArray(fo.media_types) && fo.media_types.length > 0;
  });

  test.afterAll(async () => {
    await page?.close();
  });

  // -------------------------------------------------------------------------
  // 1. Sidebar + grid render on initial load
  // -------------------------------------------------------------------------
  test('1. Catalog page renders filter sidebar and book grid', async () => {
    await page.goto(`${BASE}/catalogo`, { waitUntil: 'networkidle', timeout: 30000 });

    // Filter sidebar is present.
    await expect(page.locator('.filters-panel')).toBeVisible({ timeout: 10000 });

    // Books grid contains at least one card.
    const grid = page.locator('#books-grid');
    await expect(grid).toBeVisible({ timeout: 10000 });
    const cards = grid.locator('.book-card');
    await expect(cards.first()).toBeVisible({ timeout: 10000 });
  });

  // -------------------------------------------------------------------------
  // 2. API response shape — filter_options fields
  // -------------------------------------------------------------------------
  test('2. /api/catalogo returns expected filter_options shape', async () => {
    const data = await apiCatalog(page);
    const fo = data.filter_options;

    // Required existing fields.
    expect(Array.isArray(fo.generi)).toBe(true);
    expect(Array.isArray(fo.editori)).toBe(true);
    expect(fo.availability_stats).toBeDefined();
    expect(typeof fo.availability_stats.total).toBe('number');

    // New fields introduced with the extended backend contract.
    expect(Array.isArray(fo.autori)).toBe(true);
    expect(Array.isArray(fo.media_types)).toBe(true);
    expect(fo.anno_bounds).toBeDefined();
    expect(typeof fo.anno_bounds.min).toBe('number');
    expect(typeof fo.anno_bounds.max).toBe('number');
    expect(fo.suppress).toBeDefined();
    expect(typeof fo.suppress.editore).toBe('boolean');
    expect(typeof fo.suppress.autore).toBe('boolean');
    expect(typeof fo.suppress.tipo_media).toBe('boolean');
    expect(typeof fo.suppress.anno).toBe('boolean');
  });

  // -------------------------------------------------------------------------
  // 3. Author facet — autori entries have id, nome, cnt
  // -------------------------------------------------------------------------
  test('3. autori facet entries have id, nome and cnt > 0', async () => {
    const data = await apiCatalog(page);
    const autori = data.filter_options.autori;
    expect(autori.length).toBeGreaterThan(0);
    for (const a of autori) {
      expect(typeof a.id).toBe('number');
      expect(typeof a.nome).toBe('string');
      expect(a.cnt).toBeGreaterThan(0);
    }
  });

  // -------------------------------------------------------------------------
  // 4. media_types facet — each entry has value, label, icon, cnt
  // -------------------------------------------------------------------------
  test('4. media_types facet entries carry a count badge', async () => {
    test.skip(!hasTipoMedia, 'No tipo_media data in this catalog');
    const data = await apiCatalog(page);
    const mt = data.filter_options.media_types;
    expect(mt.length).toBeGreaterThan(0);
    for (const m of mt) {
      expect(typeof m.value).toBe('string');
      expect(typeof m.label).toBe('string');
      expect(typeof m.icon).toBe('string');
      expect(m.cnt).toBeGreaterThan(0);
    }
  });

  // -------------------------------------------------------------------------
  // 5. Publisher filter — selecting filters the grid and adds a chip
  // -------------------------------------------------------------------------
  test('5. Selecting a publisher filters the grid and shows an active-filter chip', async () => {
    test.skip(!topPublisherName, 'No publisher data available in this catalog');

    await page.goto(`${BASE}/catalogo`, { waitUntil: 'networkidle', timeout: 30000 });

    // Count books before filter.
    const totalBefore = await page.evaluate(() => {
      const el = document.getElementById('total-count');
      return el ? parseInt(el.textContent.replace(/\D/g, ''), 10) : 0;
    });

    // Apply publisher filter via updateFilter().
    await applyFilterMaybeEmpty(page, 'editore', topPublisherName);

    // #active-filters becomes visible.
    const activeFilters = page.locator('#active-filters');
    await expect(activeFilters).toBeVisible({ timeout: 10000 });

    // At least one .filter-tag is visible inside #active-filters.
    const chips = activeFilters.locator('.filter-tag');
    await expect(chips.first()).toBeVisible({ timeout: 5000 });

    // The chip text contains the publisher name or the label "Editore".
    const chipText = await chips.first().textContent();
    expect(chipText).toMatch(/editore|Editore|publisher/i);

    // Book count changed (filtered subset is different from total).
    const totalAfter = await page.evaluate(() => {
      const el = document.getElementById('total-count');
      return el ? parseInt(el.textContent.replace(/\D/g, ''), 10) : 0;
    });
    // Either fewer books are shown, or all books belong to that publisher.
    expect(totalAfter).toBeGreaterThanOrEqual(0);
    expect(totalAfter).toBeLessThanOrEqual(totalBefore);
  });

  // -------------------------------------------------------------------------
  // 6. Removing the publisher chip clears the filter
  // -------------------------------------------------------------------------
  test('6. Removing the publisher chip clears the filter', async () => {
    test.skip(!topPublisherName, 'No publisher data available in this catalog');

    // Start from a filtered state (publisher selected).
    await page.goto(`${BASE}/catalogo`, { waitUntil: 'networkidle', timeout: 30000 });
    await applyFilterMaybeEmpty(page, 'editore', topPublisherName);

    const activeFilters = page.locator('#active-filters');
    await expect(activeFilters).toBeVisible({ timeout: 10000 });

    // Click the × on the chip to remove the filter.
    const removeBtn = activeFilters.locator('.filter-tag-remove').first();
    await removeBtn.click();

    // #active-filters should hide (no active filters remaining).
    await expect(activeFilters).toBeHidden({ timeout: 10000 });

    // Grid is visible again.
    await expect(page.locator('#books-grid')).toBeVisible({ timeout: 10000 });
  });

  // -------------------------------------------------------------------------
  // 7. Author filter — applying autore_id filters the grid
  // -------------------------------------------------------------------------
  test('7. Applying autore_id filter reduces the grid and adds a chip', async () => {
    test.skip(!topAuthorId, 'No author with cnt > 0 found in this catalog');

    await page.goto(`${BASE}/catalogo`, { waitUntil: 'networkidle', timeout: 30000 });

    const totalBefore = await page.evaluate(() => {
      const el = document.getElementById('total-count');
      return el ? parseInt(el.textContent.replace(/\D/g, ''), 10) : 0;
    });

    await applyFilterMaybeEmpty(page, 'autore_id', topAuthorId);

    // Active-filters chip appears.
    const activeFilters = page.locator('#active-filters');
    await expect(activeFilters).toBeVisible({ timeout: 10000 });
    await expect(activeFilters.locator('.filter-tag').first()).toBeVisible({ timeout: 5000 });

    // Filtered book count is ≤ total.
    const totalAfter = await page.evaluate(() => {
      const el = document.getElementById('total-count');
      return el ? parseInt(el.textContent.replace(/\D/g, ''), 10) : 0;
    });
    expect(totalAfter).toBeLessThanOrEqual(totalBefore);
    expect(totalAfter).toBeGreaterThanOrEqual(0);
  });

  // -------------------------------------------------------------------------
  // 8. API suppress flag: when a facet has ≤1 value, suppress flag is true
  // -------------------------------------------------------------------------
  test('8. API suppress.editore is true when only one publisher is reachable', async () => {
    test.skip(!topPublisherName, 'No publisher data available in this catalog');

    // Fetch API with the publisher already selected.
    const data = await apiCatalog(page, { editore: topPublisherName });
    const suppress = data.filter_options.suppress;

    // After filtering to one publisher, the editori facet should have ≤1
    // reachable value, so suppress.editore === true.
    if (data.filter_options.editori.length <= 1) {
      expect(suppress.editore).toBe(true);
    }
    // If there are multiple publishers even after the filter (cross-author),
    // the flag may legitimately be false — just check it's a boolean.
    expect(typeof suppress.editore).toBe('boolean');
  });

  // -------------------------------------------------------------------------
  // 9. API suppress.autore — same semantics for author facet
  // -------------------------------------------------------------------------
  test('9. API suppress.autore is a boolean reflecting ≤1 reachable author', async () => {
    test.skip(!topAuthorId, 'No author data available in this catalog');

    const data = await apiCatalog(page, { autore_id: String(topAuthorId) });
    expect(typeof data.filter_options.suppress.autore).toBe('boolean');

    // Cross-check: suppress flag agrees with the returned autori array length.
    const autoriCount = data.filter_options.autori.length;
    if (autoriCount <= 1) {
      expect(data.filter_options.suppress.autore).toBe(true);
    } else {
      expect(data.filter_options.suppress.autore).toBe(false);
    }
  });

  // -------------------------------------------------------------------------
  // 10. anno_bounds — real year range from the current result set
  // -------------------------------------------------------------------------
  test('10. anno_bounds reflects the real year range of results', async () => {
    const data = await apiCatalog(page);
    const ab = data.filter_options.anno_bounds;
    // min ≤ max (or both 0 if no books have a year).
    if (ab.min > 0 || ab.max > 0) {
      expect(ab.min).toBeLessThanOrEqual(ab.max);
      expect(ab.distinct).toBeGreaterThanOrEqual(1);
    } else {
      expect(ab.distinct).toBe(0);
    }
  });

  // -------------------------------------------------------------------------
  // 11. clearAllFilters redirects to plain /catalogo
  // -------------------------------------------------------------------------
  test('11. clearAllFilters() redirects to the catalog without query params', async () => {
    await page.goto(`${BASE}/catalogo`, { waitUntil: 'networkidle', timeout: 30000 });

    // Apply a filter first so there is something to clear.
    if (topPublisherName) {
      await applyFilterMaybeEmpty(page, 'editore', topPublisherName);
    }

    // Trigger clearAllFilters — the implementation does a hard redirect.
    await Promise.all([
      page.waitForNavigation({ timeout: 10000 }),
      page.evaluate(() => window.clearAllFilters()),
    ]);

    // URL should have no query string.
    const url = new URL(page.url());
    expect(url.search).toBe('');
    expect(url.pathname).toMatch(/\/catalogo/);

    // The grid is visible after the redirect.
    await expect(page.locator('#books-grid')).toBeVisible({ timeout: 10000 });
  });

  // -------------------------------------------------------------------------
  // 12. count-badge elements are visible in the publisher list
  // -------------------------------------------------------------------------
  test('12. Publisher filter-option items each show a count-badge', async () => {
    await page.goto(`${BASE}/catalogo`, { waitUntil: 'networkidle', timeout: 30000 });

    const publisherOptions = page.locator('#publishers-filter .filter-option');
    const count = await publisherOptions.count();

    if (count === 0) {
      // No publishers in this catalog — acceptable.
      return;
    }

    // Each publisher item should have a .count-badge child.
    const badgesCount = await page.locator('#publishers-filter .filter-option .count-badge').count();
    expect(badgesCount).toBeGreaterThan(0);
    expect(badgesCount).toBe(count);

    // Each badge should show a positive integer.
    const firstBadge = page.locator('#publishers-filter .filter-option .count-badge').first();
    const badgeText = await firstBadge.textContent();
    expect(parseInt(badgeText ?? '0', 10)).toBeGreaterThan(0);
  });
});
