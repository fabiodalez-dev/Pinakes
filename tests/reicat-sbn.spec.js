// @ts-check
/**
 * REICAT/SBN cataloguing layer E2E tests — issue #133.
 *
 * Extends the z39-server plugin. Covers:
 *   1. Book form REICAT/SBN panel: import from SBN (mocked), BID/polo capture,
 *      Nuovo Soggettario picker (mocked) → chip → hidden field wiring.
 *   2. Author form SBN authority panel: lookup CCN (mocked) → candidate →
 *      apply authorized form + qualifier dates.
 *
 * The external SBN/SRU and BNCF responses are mocked at the browser layer via
 * page.route() so the test is deterministic and offline (this is the "mocked
 * SRU response" required by the issue acceptance criteria). The UNIMARC parser
 * round-trip and the persistence hook are covered by PHP-level tests.
 *
 * Run: /tmp/run-e2e.sh tests/reicat-sbn.spec.js --config=tests/playwright.config.js --workers=1
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE        = process.env.E2E_BASE_URL    || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const DB_HOST     = process.env.E2E_DB_HOST     || 'localhost';
const DB_USER     = process.env.E2E_DB_USER     || '';
const DB_PASS     = process.env.E2E_DB_PASS     || '';
const DB_NAME     = process.env.E2E_DB_NAME     || '';
const DB_SOCKET   = process.env.E2E_DB_SOCKET   || '';
const DB_PORT     = process.env.E2E_DB_PORT     || '';

test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'reicat-sbn requires E2E_ADMIN_EMAIL, E2E_ADMIN_PASS, E2E_DB_USER, E2E_DB_NAME',
);

function mysqlArgs(sql) {
  const args = ['-N', '-B', '-e', sql];
  if (DB_HOST && !DB_SOCKET) args.push('-h', DB_HOST);
  if (DB_PORT && !DB_SOCKET) args.push('-P', DB_PORT);
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER);
  if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
  args.push(DB_NAME);
  return args;
}
function dbQuery(sql) {
  try {
    return execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout: 10000 }).trim();
  } catch (_) {
    return '';
  }
}

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin`);
  if (page.url().includes('/admin') && !page.url().match(/login|accedi|anmelden/)) return;
  for (const slug of ['login', 'accedi', 'anmelden']) {
    const resp = await page.goto(`${BASE}/${slug}`).catch(() => null);
    if (resp && resp.status() === 200) {
      const cnt = await page.locator('input[name="email"]').count();
      if (cnt > 0) break;
    }
  }
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await Promise.all([
    page.waitForURL(/admin/, { timeout: 15000 }),
    page.locator('button[type="submit"]').click(),
  ]);
}

// ════════════════════════════════════════════════════════════════════════════
// Phase 1 — Book form: REICAT/SBN panel
// ════════════════════════════════════════════════════════════════════════════
test.describe.serial('REICAT/SBN — book form', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  let bookId = 0;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);
    const id = dbQuery("SELECT id FROM libri WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    bookId = parseInt(id, 10) || 0;
  });
  test.afterAll(async () => { await context?.close(); });

  test('1.1 REICAT/SBN panel renders on the book edit form', async () => {
    test.skip(bookId === 0, 'no book available');
    await page.goto(`${BASE}/admin/libri/modifica/${bookId}`);
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('#reicat-sbn-panel')).toBeVisible();
    await expect(page.locator('#reicat-sbn-panel')).toContainText(/REICAT/i);
    await expect(page.locator('#sbn_bid')).toHaveCount(1);
    await expect(page.locator('#reicat_soggettario_search')).toBeVisible();
  });

  test('1.2 Import from SBN (mocked) populates form fields + BID', async () => {
    test.skip(bookId === 0, 'no book available');
    // Mock the import-sbn endpoint with a deterministic SBN/UNIMARC-derived record.
    await page.route('**/admin/libri/import-sbn', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          sbn_bid: 'IT\\ICCU\\RMB\\0769708',
          sbn_polo: 'RMB',
          book: {
            title: 'Il nome della rosa',
            subtitle: 'romanzo',
            author: 'Umberto Eco',
            year: 1980,
            pages: 503,
            isbn13: '9788845292866',
            series: 'Tascabili',
          },
        }),
      });
    });

    await page.goto(`${BASE}/admin/libri/modifica/${bookId}`);
    await page.waitForLoadState('domcontentloaded');
    await page.fill('#reicat_import_isbn', '9788845292866');
    await page.locator('#reicat-import-btn').click();

    await expect(page.locator('#titolo')).toHaveValue('Il nome della rosa');
    await expect(page.locator('#sottotitolo')).toHaveValue('romanzo');
    await expect(page.locator('#anno_pubblicazione')).toHaveValue('1980');
    await expect(page.locator('#sbn_bid')).toHaveValue('IT\\ICCU\\RMB\\0769708');
    await expect(page.locator('#sbn_polo')).toHaveValue('RMB');
    await page.unroute('**/admin/libri/import-sbn');
  });

  test('1.3 Nuovo Soggettario picker (mocked) adds a controlled subject chip', async () => {
    test.skip(bookId === 0, 'no book available');
    await page.route('**/admin/libri/soggettario-search**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          results: [
            { termine: 'Romanzi storici', bncf_id: '12345', uri: 'https://thes.bncf.firenze.sbn.it/termine.php?id=12345' },
          ],
        }),
      });
    });

    await page.goto(`${BASE}/admin/libri/modifica/${bookId}`);
    await page.waitForLoadState('domcontentloaded');
    await page.fill('#reicat_soggettario_search', 'Romanzi');
    // Wait for the mocked dropdown then click the result.
    const result = page.locator('#reicat_soggettario_results button', { hasText: 'Romanzi storici' });
    await result.waitFor({ state: 'visible', timeout: 5000 });
    await result.click();

    // Chip rendered + hidden JSON carries the controlled term with its BNCF id.
    await expect(page.locator('#reicat_soggetti_chips')).toContainText('Romanzi storici');
    const hidden = await page.locator('#reicat_soggetti').inputValue();
    const parsed = JSON.parse(hidden);
    expect(parsed.some((s) => s.bncf_id === '12345' && /Romanzi storici/.test(s.termine))).toBeTruthy();
    await page.unroute('**/admin/libri/soggettario-search**');
  });

  test('1.4 Free-text subject can be added with Enter', async () => {
    test.skip(bookId === 0, 'no book available');
    await page.goto(`${BASE}/admin/libri/modifica/${bookId}`);
    await page.waitForLoadState('domcontentloaded');
    await page.fill('#reicat_soggettario_search', 'Soggetto libero E2E');
    await page.locator('#reicat_soggettario_search').press('Enter');
    await expect(page.locator('#reicat_soggetti_chips')).toContainText('Soggetto libero E2E');
    const hidden = await page.locator('#reicat_soggetti').inputValue();
    const parsed = JSON.parse(hidden);
    expect(parsed.some((s) => s.termine === 'Soggetto libero E2E' && !s.bncf_id)).toBeTruthy();
  });

  test('1.5 UNIMARC export endpoint returns a valid MARCXchange document', async () => {
    test.skip(bookId === 0, 'no book available');
    const resp = await page.request.get(`${BASE}/admin/libri/${bookId}/export.unimarc.xml`);
    expect(resp.status()).toBe(200);
    expect(resp.headers()['content-type']).toContain('xml');
    const xml = await resp.text();
    expect(xml).toContain('info:lc/xmlns/marcxchange-v2');
    expect(xml).toMatch(/<datafield tag="200"/);
  });
});

// ════════════════════════════════════════════════════════════════════════════
// Phase 2 — Author form: SBN authority panel
// ════════════════════════════════════════════════════════════════════════════
test.describe.serial('REICAT/SBN — author authority', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  let authorId = 0;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);
    const id = dbQuery("SELECT id FROM autori ORDER BY id LIMIT 1");
    authorId = parseInt(id, 10) || 0;
  });
  test.afterAll(async () => { await context?.close(); });

  test('2.1 Authority panel renders on the author edit form', async () => {
    test.skip(authorId === 0, 'no author available');
    await page.goto(`${BASE}/admin/autori/modifica/${authorId}`);
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('#reicat-authority-panel')).toBeVisible();
    await expect(page.locator('#sbn_authorized_form')).toHaveCount(1);
    await expect(page.locator('#qualifier_dates')).toHaveCount(1);
  });

  test('2.2 Lookup CCN (mocked) shows a candidate and applies it', async () => {
    test.skip(authorId === 0, 'no author available');
    await page.route(`**/admin/autori/${authorId}/lookup-ccn`, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          name: 'Italo Calvino',
          candidates: [
            { authorized_form: 'Calvino, Italo', qualifier_dates: '1923-1985', raw: 'Calvino, Italo <1923-1985>', count: 25, sample_bid: 'IT\\ICCU\\RMB\\0588390' },
          ],
        }),
      });
    });
    // apply-authority must not actually mutate in this UI test → mock it too.
    await page.route(`**/admin/autori/${authorId}/apply-authority`, async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true }) });
    });

    await page.goto(`${BASE}/admin/autori/modifica/${authorId}`);
    await page.waitForLoadState('domcontentloaded');
    await page.locator('#reicat-lookup-btn').click();

    const apply = page.locator('#reicat-lookup-results button', { hasText: /Applica|Apply|Anwenden|Appliquer/ });
    await apply.waitFor({ state: 'visible', timeout: 8000 });
    await apply.click();

    await expect(page.locator('#sbn_authorized_form')).toHaveValue('Calvino, Italo');
    await expect(page.locator('#qualifier_dates')).toHaveValue('1923-1985');

    await page.unroute(`**/admin/autori/${authorId}/lookup-ccn`);
    await page.unroute(`**/admin/autori/${authorId}/apply-authority`);
  });
});
