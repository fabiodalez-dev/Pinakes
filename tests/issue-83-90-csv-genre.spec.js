// @ts-check
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';

const DB_USER   = process.env.E2E_DB_USER   || '';
const DB_PASS   = process.env.E2E_DB_PASS   || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME   = process.env.E2E_DB_NAME   || '';

test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_PASS || !DB_NAME,
  'E2E credentials not configured',
);

function dbQuery(sql) {
  const args = ['-u', DB_USER, `-p${DB_PASS}`, DB_NAME, '-N', '-B', '-e', sql];
  if (DB_SOCKET) args.splice(3, 0, '-S', DB_SOCKET);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000 }).trim();
}

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const emailField = page.locator('input[name="email"]');
  if (await emailField.isVisible({ timeout: 3000 }).catch(() => false)) {
    await emailField.fill(ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/.*(?:dashboard|admin).*/, { timeout: 15000 });
  }
}

// ════════════════════════════════════════════════════════════════════════════
// Issue #83: CSV export — \r in description must not shift columns
// ════════════════════════════════════════════════════════════════════════════
test.describe.serial('Issue #83: CSV column alignment', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  let testBookId = 0;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);

    // Insert a book with \r\n in description (the exact pattern from #83)
    dbQuery(`INSERT INTO libri (titolo, descrizione, stato, copie_totali, copie_disponibili)
      VALUES ('CSV Test CR Book', 'First paragraph.\\r\\n\\r\\nSecond paragraph.\\r\\nThird line.', 'disponibile', 1, 1)`);
    testBookId = Number(dbQuery("SELECT MAX(id) FROM libri WHERE titolo = 'CSV Test CR Book' AND deleted_at IS NULL"));
  });

  test.afterAll(async () => {
    if (testBookId > 0) {
      dbQuery(`DELETE FROM libri WHERE id = ${testBookId}`);
    }
    await context?.close();
  });

  test('CSV export has no \\r characters in output', async () => {
    const resp = await page.request.get(`${BASE}/admin/libri/export/csv?ids=${testBookId}`);
    expect(resp.ok()).toBeTruthy();
    const csv = await resp.text();

    // Must NOT contain \r — our fix strips it
    expect(csv).not.toContain('\r');

    // Should still contain the description text (without \r)
    expect(csv).toContain('First paragraph.');
    expect(csv).toContain('Second paragraph.');
  });

  test('CSV columns stay aligned — same number of fields per record', async () => {
    const resp = await page.request.get(`${BASE}/admin/libri/export/csv?ids=${testBookId}`);
    expect(resp.ok()).toBeTruthy();
    const csv = await resp.text();

    // Parse CSV records respecting quoted fields (which may contain \n)
    const records = [];
    let current = '';
    let inQuotes = false;
    for (const ch of csv) {
      if (ch === '"') {
        inQuotes = !inQuotes;
        current += ch;
      } else if (ch === '\n' && !inQuotes) {
        if (current.trim() !== '') records.push(current);
        current = '';
      } else {
        current += ch;
      }
    }
    if (current.trim() !== '') records.push(current);

    expect(records.length).toBeGreaterThanOrEqual(2); // header + at least 1 data row

    // Count semicolons outside quotes in each record
    function countDelimiters(record) {
      let count = 0;
      let q = false;
      for (const c of record) {
        if (c === '"') q = !q;
        else if (c === ';' && !q) count++;
      }
      return count;
    }

    const headerDelimiters = countDelimiters(records[0]);
    for (let i = 1; i < records.length; i++) {
      const rowDelimiters = countDelimiters(records[i]);
      expect(rowDelimiters).toBe(headerDelimiters);
    }
  });

  test('CSV correctly quotes fields containing newlines', async () => {
    const resp = await page.request.get(`${BASE}/admin/libri/export/csv?ids=${testBookId}`);
    expect(resp.ok()).toBeTruthy();
    const csv = await resp.text();

    // The description field should be quoted because it contains \n
    // Pattern: ;"First paragraph..."; (quoted field with semicolons around it)
    expect(csv).toMatch(/"[^"]*First paragraph\.[^"]*Second paragraph\.[^"]*"/);
  });
});

// ════════════════════════════════════════════════════════════════════════════
// Issue #90: Admin genre display — clickable hierarchy + filter badge name
// ════════════════════════════════════════════════════════════════════════════
test.describe.serial('Issue #90: Admin genre display', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  let testBookId = 0;
  let rootGenreId = 0;
  let childGenreId = 0;
  let rootGenreName = '';
  let childGenreName = '';

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);

    // Find a root genre and a child genre for testing
    rootGenreId = Number(dbQuery("SELECT id FROM generi WHERE parent_id IS NULL LIMIT 1"));
    rootGenreName = dbQuery(`SELECT nome FROM generi WHERE id = ${rootGenreId}`);
    childGenreId = Number(dbQuery(`SELECT id FROM generi WHERE parent_id = ${rootGenreId} LIMIT 1`));

    if (childGenreId > 0) {
      childGenreName = dbQuery(`SELECT nome FROM generi WHERE id = ${childGenreId}`);
    }

    // Create or find a book with genre set
    dbQuery(`INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili, genere_id)
      VALUES ('Genre Display Test', 'disponibile', 1, 1, ${childGenreId > 0 ? childGenreId : rootGenreId})`);
    testBookId = Number(dbQuery("SELECT MAX(id) FROM libri WHERE titolo = 'Genre Display Test' AND deleted_at IS NULL"));
  });

  test.afterAll(async () => {
    if (testBookId > 0) {
      dbQuery(`DELETE FROM libri WHERE id = ${testBookId}`);
    }
    await context?.close();
  });

  test('Admin book detail shows separate clickable genre links', async () => {
    test.skip(rootGenreId === 0, 'No genres in database');
    await page.goto(`${BASE}/admin/libri/${testBookId}`);
    await page.waitForLoadState('domcontentloaded');

    const genreDisplay = page.locator('[data-testid="genre-display"]');
    await expect(genreDisplay).toBeVisible({ timeout: 5000 });

    // Each genre level should be a separate <a> tag
    const genreLinks = genreDisplay.locator('a');
    const linkCount = await genreLinks.count();
    expect(linkCount).toBeGreaterThanOrEqual(1);

    // Each link should point to /admin/libri?genere=<id>
    for (let i = 0; i < linkCount; i++) {
      const href = await genreLinks.nth(i).getAttribute('href');
      expect(href).toMatch(/\/admin\/libri\?genere=\d+/);
    }

    // The genre name text should be present
    const text = await genreDisplay.textContent();
    if (childGenreId > 0 && childGenreName) {
      // Extract leaf name from "Parent - Child" format
      const leafName = childGenreName.includes(' - ')
        ? childGenreName.split(' - ').pop().trim()
        : childGenreName;
      expect(text).toContain(leafName);
    }
  });

  test('Admin book detail genre links have arrow separator', async () => {
    test.skip(rootGenreId === 0 || childGenreId === 0, 'Need root+child genre');
    await page.goto(`${BASE}/admin/libri/${testBookId}`);
    await page.waitForLoadState('domcontentloaded');

    const genreDisplay = page.locator('[data-testid="genre-display"]');
    const links = genreDisplay.locator('a');
    const count = await links.count();

    if (count >= 2) {
      // Should have arrow separator between links
      const arrows = genreDisplay.locator('span.text-gray-400');
      const arrowCount = await arrows.count();
      expect(arrowCount).toBe(count - 1);
    }
  });

  test('Genre filter badge shows name instead of #ID', async () => {
    test.skip(rootGenreId === 0, 'No genres in database');
    const filterGenreId = childGenreId > 0 ? childGenreId : rootGenreId;
    const filterGenreName = childGenreId > 0 ? childGenreName : rootGenreName;

    await page.goto(`${BASE}/admin/libri?genere=${filterGenreId}`);
    await page.waitForLoadState('domcontentloaded');

    // Wait for the filter flash banner to appear
    const banner = page.locator('#url-filter-flash');
    await expect(banner).toBeVisible({ timeout: 5000 });

    const bannerText = await banner.textContent();

    // Should contain the genre NAME, not just #ID
    // Extract leaf name for display comparison
    const displayName = filterGenreName.includes(' - ')
      ? filterGenreName.split(' - ').pop().trim()
      : filterGenreName;
    expect(bannerText).toContain(displayName);

    // Should NOT contain the raw #ID pattern
    expect(bannerText).not.toMatch(new RegExp(`#${filterGenreId}\\b`));
  });
});
