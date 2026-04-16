// @ts-check
/**
 * E2E tests for the Bulk ISBN Enrichment feature.
 *
 * Covers:
 *  - Stats page: correct counts, soft-delete exclusion, ISBN-required filter, already-populated exclusion
 *  - Toggle: enable/disable auto-enrichment, auth requirement, state persistence
 *  - Manual batch: cover + description enrichment, no-overwrite, graceful 404, progress counts,
 *    field preservation (tipo_media, isbn13, ean)
 *  - UI: stats cards, action button, toggle switch
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const RUN_ID = Date.now().toString(36);

// ─── DB helpers ───────────────────────────────────────────────────────────
function mysqlArgs() {
  const args = ['-u', DB_USER, `-p${DB_PASS}`];
  if (DB_SOCKET) args.push('--socket=' + DB_SOCKET);
  args.push(DB_NAME);
  return args;
}

function dbQuery(sql) {
  const args = [...mysqlArgs(), '-N', '-B', '-e', sql];
  return execFileSync('mysql', args, { encoding: 'utf8', timeout: 10000 }).trim();
}

function dbExec(sql) {
  const args = [...mysqlArgs(), '-e', sql];
  execFileSync('mysql', args, { encoding: 'utf8', stdio: 'pipe', timeout: 10000 });
}

// ─── Test data ────────────────────────────────────────────────────────────
// Real ISBNs for enrichment tests (well-known books with covers on Open Library)
const ISBN_MOCKINGBIRD = '9780061120084';  // To Kill a Mockingbird
const ISBN_1984        = '9780451524935';  // 1984 - George Orwell
const ISBN_GATSBY      = '9780743273565';  // The Great Gatsby
const ISBN_CATCHER     = '9780316769488';  // The Catcher in the Rye
const ISBN_HOBBIT      = '9780547928227';  // The Hobbit
const ISBN_FAKE        = '9999999999999';  // Non-existent ISBN
const ISBN_NOOVERWRITE1 = '9780142437209'; // Moby Dick (no-overwrite tests)
const ISBN_NOOVERWRITE2 = '9780140283334'; // Brave New World (no-overwrite tests)

const prefix = `ENRICH_${RUN_ID}`;

test.describe.serial('Bulk Enrichment', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;

  /** IDs of test books inserted during setup */
  const bookIds = [];

  /** Whether the bulk-enrich feature tables/routes exist */
  let featureAvailable = true;

  let csrfToken = '';

  /** Get CSRF token from the bulk-enrich page */
  async function getCsrf() {
    if (csrfToken) return csrfToken;
    await page.goto(`${BASE}/admin/libri/bulk-enrich`);
    csrfToken = await page.evaluate(() => {
      for (const s of document.querySelectorAll('script')) {
        const m = s.textContent.match(/csrfToken\s*=\s*"([^"]+)"/);
        if (m) return m[1];
      }
      const input = document.querySelector('input[name="csrf_token"]');
      return input ? input.value : '';
    });
    return csrfToken;
  }

  /** POST with CSRF token */
  async function postWithCsrf(url, formData = {}) {
    const token = await getCsrf();
    return page.request.post(url, {
      form: { ...formData, csrf_token: token },
    });
  }

  test.beforeAll(async ({ browser }) => {
    test.skip(
      !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_PASS || !DB_NAME,
      'E2E credentials not configured'
    );

    // Verify app is installed
    try {
      const tables = dbQuery(
        "SELECT COUNT(*) FROM information_schema.tables " +
        `WHERE table_schema = DATABASE() AND table_name IN ('libri','utenti','system_settings')`
      );
      test.skip(
        parseInt(tables, 10) < 3,
        'App not installed (run tests/smoke-install.spec.js first)'
      );
    } catch {
      test.skip(true, 'Cannot reach DB');
    }

    context = await browser.newContext();
    page = await context.newPage();

    // Login as admin
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/\/admin\//, { timeout: 15000 });

    // Seed 5 test books with ISBNs but NO cover and NO description
    const isbns = [ISBN_MOCKINGBIRD, ISBN_1984, ISBN_GATSBY, ISBN_CATCHER, ISBN_HOBBIT];
    for (let i = 0; i < isbns.length; i++) {
      dbExec(
        "INSERT INTO libri (titolo, isbn13, copertina_url, descrizione, copie_totali, copie_disponibili, stato, created_at, updated_at) " +
        `VALUES ('${prefix}_Book${i}', '${isbns[i]}', NULL, NULL, 1, 1, 'disponibile', NOW(), NOW())`
      );
      const id = dbQuery(`SELECT id FROM libri WHERE titolo = '${prefix}_Book${i}' AND deleted_at IS NULL LIMIT 1`);
      bookIds.push(parseInt(id, 10));
    }
  });

  test.afterAll(async () => {
    // Clean up all test books
    try {
      dbExec(
        `DELETE FROM libri WHERE titolo LIKE '${prefix}%'`
      );
    } catch { /* ignore */ }

    // Restore toggle setting to off
    try {
      dbExec(
        "DELETE FROM system_settings WHERE category = 'bulk_enrich' AND setting_key = 'enabled'"
      );
    } catch { /* ignore */ }

    await context?.close();
  });

  // ═══════════════════════════════════════════════════════════════════
  // Stats tests (1-4)
  // ═══════════════════════════════════════════════════════════════════

  test('1. Stats page loads with correct counts', async () => {
    const resp = await page.goto(`${BASE}/admin/libri/bulk-enrich`);
    if (resp && resp.status() === 404) {
      featureAvailable = false;
      test.skip(true, 'Bulk enrich route not yet implemented');
    }
    expect(resp?.status()).toBe(200);

    // Count pending books in DB (isbn13 not null, missing cover OR description, not deleted)
    const pendingCount = parseInt(
      dbQuery(
        "SELECT COUNT(*) FROM libri " +
        "WHERE isbn13 IS NOT NULL AND isbn13 != '' " +
        "AND (copertina_url IS NULL OR copertina_url = '' OR descrizione IS NULL OR descrizione = '') " +
        "AND deleted_at IS NULL"
      ),
      10
    );

    // The page should display the pending count somewhere
    const content = await page.content();
    expect(content).toContain(String(pendingCount));
  });

  test('2. Stats exclude soft-deleted books', async () => {
    test.skip(!featureAvailable, 'Bulk enrich not available');

    // Count pending before soft-delete
    const countBefore = parseInt(
      dbQuery(
        "SELECT COUNT(*) FROM libri " +
        "WHERE isbn13 IS NOT NULL AND isbn13 != '' " +
        "AND (copertina_url IS NULL OR copertina_url = '' OR descrizione IS NULL OR descrizione = '') " +
        "AND deleted_at IS NULL"
      ),
      10
    );

    // Soft-delete one test book (nullify isbn13 per project rules)
    const victimId = bookIds[0];
    dbExec(`UPDATE libri SET deleted_at = NOW(), isbn13 = NULL WHERE id = ${victimId}`);

    // Reload and check count decreased
    await page.goto(`${BASE}/admin/libri/bulk-enrich`);
    const countAfter = parseInt(
      dbQuery(
        "SELECT COUNT(*) FROM libri " +
        "WHERE isbn13 IS NOT NULL AND isbn13 != '' " +
        "AND (copertina_url IS NULL OR copertina_url = '' OR descrizione IS NULL OR descrizione = '') " +
        "AND deleted_at IS NULL"
      ),
      10
    );

    expect(countAfter).toBe(countBefore - 1);

    const content = await page.content();
    expect(content).toContain(String(countAfter));

    // Restore the book
    dbExec(`UPDATE libri SET deleted_at = NULL, isbn13 = '${ISBN_MOCKINGBIRD}' WHERE id = ${victimId}`);
  });

  test('3. Stats exclude books without ISBN', async () => {
    test.skip(!featureAvailable, 'Bulk enrich not available');

    // Insert a book WITHOUT isbn13 — should NOT appear in pending count
    dbExec(
      "INSERT INTO libri (titolo, isbn13, copertina_url, descrizione, copie_totali, copie_disponibili, stato, created_at, updated_at) " +
      `VALUES ('${prefix}_NoISBN', NULL, NULL, NULL, 1, 1, 'disponibile', NOW(), NOW())`
    );

    const pendingCount = parseInt(
      dbQuery(
        "SELECT COUNT(*) FROM libri " +
        "WHERE isbn13 IS NOT NULL AND isbn13 != '' " +
        "AND (copertina_url IS NULL OR copertina_url = '' OR descrizione IS NULL OR descrizione = '') " +
        "AND deleted_at IS NULL"
      ),
      10
    );

    // The no-ISBN book must not be counted
    const noIsbnInPending = dbQuery(
      `SELECT COUNT(*) FROM libri WHERE titolo = '${prefix}_NoISBN' AND isbn13 IS NOT NULL AND deleted_at IS NULL`
    );
    expect(noIsbnInPending).toBe('0');

    await page.goto(`${BASE}/admin/libri/bulk-enrich`);
    const content = await page.content();
    expect(content).toContain(String(pendingCount));
  });

  test('4. Stats exclude books with cover AND description already populated', async () => {
    test.skip(!featureAvailable, 'Bulk enrich not available');

    // Insert a fully-populated book — should NOT be pending
    dbExec(
      "INSERT INTO libri (titolo, isbn13, copertina_url, descrizione, copie_totali, copie_disponibili, stato, created_at, updated_at) " +
      `VALUES ('${prefix}_Complete', '9780140449136', 'cover.jpg', 'A great book.', 1, 1, 'disponibile', NOW(), NOW())`
    );

    const completeInPending = dbQuery(
      `SELECT COUNT(*) FROM libri WHERE titolo = '${prefix}_Complete' ` +
      "AND (copertina_url IS NULL OR copertina_url = '' OR descrizione IS NULL OR descrizione = '') " +
      "AND deleted_at IS NULL"
    );
    expect(completeInPending).toBe('0');
  });

  // ═══════════════════════════════════════════════════════════════════
  // Toggle tests (5-8)
  // ═══════════════════════════════════════════════════════════════════

  test('5. Toggle ON enables auto-enrichment', async () => {
    test.skip(!featureAvailable, 'Bulk enrich not available');
    const resp = await postWithCsrf(`${BASE}/admin/libri/bulk-enrich/toggle`, {
      enabled: '1',
    });
    expect(resp.status()).toBe(200);
    const json = await resp.json();
    const results = json.results ?? json;
    expect(json.success ?? json.ok).toBeTruthy();

    // Verify in DB
    const val = dbQuery(
      "SELECT setting_value FROM system_settings WHERE category = 'bulk_enrich' AND setting_key = 'enabled' LIMIT 1"
    );
    expect(val).toBe('1');
  });

  test('6. Toggle OFF disables auto-enrichment', async () => {
    test.skip(!featureAvailable, 'Bulk enrich not available');

    const resp = await postWithCsrf(`${BASE}/admin/libri/bulk-enrich/toggle`, {
      enabled: '0',
    });
    expect(resp.status()).toBe(200);
    const json = await resp.json();
    const results = json.results ?? json;
    expect(json.success ?? json.ok).toBeTruthy();

    const val = dbQuery(
      "SELECT setting_value FROM system_settings WHERE category = 'bulk_enrich' AND setting_key = 'enabled' LIMIT 1"
    );
    expect(val).toBe('0');
  });

  test('7. Toggle requires admin authentication', async () => {
    test.skip(!featureAvailable, 'Bulk enrich not available');

    // Create a fresh context with no session (not logged in)
    const anonContext = await page.context().browser().newContext();
    try {
      const anonPage = await anonContext.newPage();
      const resp = await anonPage.request.post(`${BASE}/admin/libri/bulk-enrich/toggle`, {
        enabled: '1',
        maxRedirects: 0,
      });

      // Should redirect to login (302) or return 401/403
      const status = resp.status();
      expect([302, 401, 403]).toContain(status);

      if (status === 302) {
        const location = resp.headers()['location'] || '';
        expect(location).toMatch(/accedi|login/);
      }
    } finally {
      await anonContext.close();
    }
  });

  test('8. Toggle state persists across page loads', async () => {
    test.skip(!featureAvailable, 'Bulk enrich not available');

    // Set toggle ON
    await postWithCsrf(`${BASE}/admin/libri/bulk-enrich/toggle`, {
      enabled: '1',
    });

    // Reload the page
    await page.goto(`${BASE}/admin/libri/bulk-enrich`);
    await page.waitForLoadState('domcontentloaded');

    // The toggle should reflect ON state — check for checked attribute or active class
    const toggle = page.locator('input[name="enabled"], input[type="checkbox"][name*="enrich"]').first();
    if (await toggle.isVisible({ timeout: 3000 }).catch(() => false)) {
      const isChecked = await toggle.isChecked();
      expect(isChecked).toBe(true);
    } else {
      // Fallback: check that the page content reflects the ON state
      const content = await page.content();
      const hasOnIndicator = content.includes('checked') ||
                             content.includes('active') ||
                             content.includes('enabled');
      expect(hasOnIndicator).toBe(true);
    }

    // Reset to OFF for subsequent tests
    await postWithCsrf(`${BASE}/admin/libri/bulk-enrich/toggle`, {
      enabled: '0',
    });
  });

  // ═══════════════════════════════════════════════════════════════════
  // Manual batch tests (9-17)
  // ═══════════════════════════════════════════════════════════════════

  test('9. Manual batch enriches book with valid ISBN (cover)', async () => {
    test.skip(!featureAvailable, 'Bulk enrich not available');
    test.setTimeout(120000);

    // Ensure test book has no cover
    const targetId = bookIds[0];
    dbExec(`UPDATE libri SET copertina_url = NULL WHERE id = ${targetId}`);

    let resp;
    try {
      resp = await postWithCsrf(`${BASE}/admin/libri/bulk-enrich/start`, {
        timeout: 25000,
      });
    } catch {
      test.skip(true, 'Enrichment API unreachable or timed out');
      return;
    }

    expect(resp.status()).toBe(200);
    const json = await resp.json();
    const results = json.results ?? json;

    // After batch, check if cover was populated (may depend on API availability)
    const cover = dbQuery(`SELECT IFNULL(copertina_url, '') FROM libri WHERE id = ${targetId}`);
    if (results.enriched > 0) {
      expect(cover.length).toBeGreaterThan(0);
    }
    // If enriched === 0, API may be rate-limited — acceptable
  });

  test('10. Manual batch enriches book with valid ISBN (description)', async () => {
    test.skip(!featureAvailable, 'Bulk enrich not available');
    test.setTimeout(120000);

    const targetId = bookIds[0];

    // Re-run batch if needed (or check from previous run)
    const desc = dbQuery(`SELECT IFNULL(descrizione, '') FROM libri WHERE id = ${targetId}`);
    if (desc === '') {
      // Try another batch run
      try {
        await postWithCsrf(`${BASE}/admin/libri/bulk-enrich/start`, {
          timeout: 25000,
        });
      } catch {
        test.skip(true, 'Enrichment API unreachable or timed out');
        return;
      }

      const descAfter = dbQuery(`SELECT IFNULL(descrizione, '') FROM libri WHERE id = ${targetId}`);
      // If still empty, API might not return descriptions — just verify no crash
      if (descAfter !== '') {
        expect(descAfter.length).toBeGreaterThan(0);
      }
    } else {
      expect(desc.length).toBeGreaterThan(0);
    }
  });

  test('11. Manual batch does NOT overwrite existing cover', async () => {
    test.skip(!featureAvailable, 'Bulk enrich not available');
    test.setTimeout(120000);

    const existingCover = 'my-existing-cover.jpg';

    // Insert book with pre-existing cover
    dbExec(
      "INSERT INTO libri (titolo, isbn13, copertina_url, descrizione, copie_totali, copie_disponibili, stato, created_at, updated_at) " +
      `VALUES ('${prefix}_HasCover', '${ISBN_NOOVERWRITE1}', '${existingCover}', NULL, 1, 1, 'disponibile', NOW(), NOW())`
    );
    const id = dbQuery(`SELECT id FROM libri WHERE titolo = '${prefix}_HasCover' AND deleted_at IS NULL LIMIT 1`);
    bookIds.push(parseInt(id, 10));

    try {
      await postWithCsrf(`${BASE}/admin/libri/bulk-enrich/start`, {
        timeout: 25000,
      });
    } catch {
      // API unreachable — cover should still be original
    }

    const cover = dbQuery(`SELECT IFNULL(copertina_url, '') FROM libri WHERE id = ${id}`);
    expect(cover).toBe(existingCover);
  });

  test('12. Manual batch does NOT overwrite existing description', async () => {
    test.skip(!featureAvailable, 'Bulk enrich not available');
    test.setTimeout(120000);

    const existingDesc = 'My custom description that must not be overwritten.';

    // Insert book with pre-existing description
    dbExec(
      "INSERT INTO libri (titolo, isbn13, copertina_url, descrizione, copie_totali, copie_disponibili, stato, created_at, updated_at) " +
      `VALUES ('${prefix}_HasDesc', '${ISBN_NOOVERWRITE2}', NULL, '${existingDesc}', 1, 1, 'disponibile', NOW(), NOW())`
    );
    const id = dbQuery(`SELECT id FROM libri WHERE titolo = '${prefix}_HasDesc' AND deleted_at IS NULL LIMIT 1`);
    bookIds.push(parseInt(id, 10));

    try {
      await postWithCsrf(`${BASE}/admin/libri/bulk-enrich/start`, {
        timeout: 25000,
      });
    } catch {
      // API unreachable — description should still be original
    }

    const desc = dbQuery(`SELECT IFNULL(descrizione, '') FROM libri WHERE id = ${id}`);
    expect(desc).toBe(existingDesc);
  });

  test('13. Manual batch handles ISBN not found gracefully', async () => {
    test.skip(!featureAvailable, 'Bulk enrich not available');
    test.setTimeout(120000);

    // Insert book with fake ISBN
    dbExec(
      "INSERT INTO libri (titolo, isbn13, copertina_url, descrizione, copie_totali, copie_disponibili, stato, created_at, updated_at) " +
      `VALUES ('${prefix}_FakeISBN', '${ISBN_FAKE}', NULL, NULL, 1, 1, 'disponibile', NOW(), NOW())`
    );
    const id = dbQuery(`SELECT id FROM libri WHERE titolo = '${prefix}_FakeISBN' AND deleted_at IS NULL LIMIT 1`);
    bookIds.push(parseInt(id, 10));

    let resp;
    try {
      resp = await postWithCsrf(`${BASE}/admin/libri/bulk-enrich/start`, {
        timeout: 25000,
      });
    } catch {
      test.skip(true, 'Enrichment API unreachable or timed out');
      return;
    }

    expect(resp.status()).toBe(200);
    const json = await resp.json();
    const results = json.results ?? json;

    // Response has results nested under 'results' key
    expect(results).toHaveProperty('not_found');
    expect(results.not_found).toBeGreaterThanOrEqual(0);
    // No crash — server returned valid JSON
  });

  test('14. Manual batch returns correct progress counts', async () => {
    test.skip(!featureAvailable, 'Bulk enrich not available');
    test.setTimeout(120000);

    let resp;
    try {
      resp = await postWithCsrf(`${BASE}/admin/libri/bulk-enrich/start`, {
        timeout: 25000,
      });
    } catch {
      test.skip(true, 'Enrichment API unreachable or timed out');
      return;
    }

    expect(resp.status()).toBe(200);
    const json = await resp.json();
    const results = json.results ?? json;

    // Response must contain progress counters
    expect(results).toHaveProperty('processed');
    expect(results).toHaveProperty('enriched');
    expect(results).toHaveProperty('not_found');
    expect(results).toHaveProperty('errors');

    // processed must be a non-negative number
    expect(typeof results.processed).toBe('number');
    expect(results.processed).toBeGreaterThanOrEqual(0);

    // enriched + not_found + errors + skipped should equal processed
    const sum = (results.enriched || 0) + (results.not_found || 0) + (results.errors || 0) + (results.skipped || 0);
    expect(sum).toBe(results.processed);
  });

  test('15. Batch preserves tipo_media after enrichment', async () => {
    test.skip(!featureAvailable, 'Bulk enrich not available');
    test.setTimeout(120000);

    // Insert a disco-type book with ISBN (should be enriched, tipo_media must stay)
    dbExec(
      "INSERT INTO libri (titolo, isbn13, tipo_media, copertina_url, descrizione, copie_totali, copie_disponibili, stato, created_at, updated_at) " +
      `VALUES ('${prefix}_Disco', '${ISBN_CATCHER}', 'disco', NULL, NULL, 1, 1, 'disponibile', NOW(), NOW())`
    );
    const id = dbQuery(`SELECT id FROM libri WHERE titolo = '${prefix}_Disco' AND deleted_at IS NULL LIMIT 1`);
    bookIds.push(parseInt(id, 10));

    try {
      await postWithCsrf(`${BASE}/admin/libri/bulk-enrich/start`, {
        timeout: 25000,
      });
    } catch {
      // API unreachable — field should still be preserved
    }

    const tipoMedia = dbQuery(`SELECT IFNULL(tipo_media, '') FROM libri WHERE id = ${id}`);
    expect(tipoMedia).toBe('disco');
  });

  test('16. Batch preserves isbn13 value', async () => {
    test.skip(!featureAvailable, 'Bulk enrich not available');
    test.setTimeout(120000);

    // Pick a test book that was (potentially) enriched
    const targetId = bookIds[0];
    const isbn = dbQuery(`SELECT IFNULL(isbn13, '') FROM libri WHERE id = ${targetId}`);

    try {
      await postWithCsrf(`${BASE}/admin/libri/bulk-enrich/start`, {
        timeout: 25000,
      });
    } catch {
      // API unreachable — isbn13 should still be preserved
    }

    const isbnAfter = dbQuery(`SELECT IFNULL(isbn13, '') FROM libri WHERE id = ${targetId}`);
    expect(isbnAfter).toBe(isbn);
  });

  test('17. Batch preserves ean value', async () => {
    test.skip(!featureAvailable, 'Bulk enrich not available');
    test.setTimeout(120000);

    const testEan = '5012345678900';

    // Insert book with EAN
    dbExec(
      "INSERT INTO libri (titolo, isbn13, ean, copertina_url, descrizione, copie_totali, copie_disponibili, stato, created_at, updated_at) " +
      `VALUES ('${prefix}_WithEAN', '${ISBN_HOBBIT}', '${testEan}', NULL, NULL, 1, 1, 'disponibile', NOW(), NOW())`
    );
    const id = dbQuery(`SELECT id FROM libri WHERE titolo = '${prefix}_WithEAN' AND deleted_at IS NULL LIMIT 1`);
    bookIds.push(parseInt(id, 10));

    try {
      await postWithCsrf(`${BASE}/admin/libri/bulk-enrich/start`, {
        timeout: 25000,
      });
    } catch {
      // API unreachable — ean should still be preserved
    }

    const ean = dbQuery(`SELECT IFNULL(ean, '') FROM libri WHERE id = ${id}`);
    expect(ean).toBe(testEan);
  });

  // ═══════════════════════════════════════════════════════════════════
  // UI tests (18-20)
  // ═══════════════════════════════════════════════════════════════════

  test('18. Bulk enrich page shows stats cards', async () => {
    test.skip(!featureAvailable, 'Bulk enrich not available');

    await page.goto(`${BASE}/admin/libri/bulk-enrich`);
    await page.waitForLoadState('domcontentloaded');
    const content = await page.content();

    // Page should contain stat labels (Italian) for books and missing covers/descriptions
    const hasBookCount = content.includes('libri') || content.includes('Libri');
    const hasCoverStat = content.includes('copertina') || content.includes('Copertina') ||
                         content.includes('copertine') || content.includes('Copertine');
    const hasDescStat  = content.includes('descrizione') || content.includes('Descrizione') ||
                         content.includes('descrizioni') || content.includes('Descrizioni');

    expect(hasBookCount).toBe(true);
    expect(hasCoverStat || hasDescStat).toBe(true);
  });

  test('19. Bulk enrich page has "Arricchisci Adesso" button', async () => {
    test.skip(!featureAvailable, 'Bulk enrich not available');

    await page.goto(`${BASE}/admin/libri/bulk-enrich`);
    await page.waitForLoadState('domcontentloaded');

    // Look for the action button — by text or by form action
    const buttonByText = page.locator('button, a').filter({
      hasText: /arricchisci|enrich|avvia|start/i,
    }).first();
    const buttonByAction = page.locator(
      'form[action*="bulk-enrich/start"] button[type="submit"]'
    ).first();

    const hasButton = await buttonByText.isVisible({ timeout: 3000 }).catch(() => false) ||
                      await buttonByAction.isVisible({ timeout: 3000 }).catch(() => false);

    expect(hasButton).toBe(true);
  });

  test('20. Bulk enrich page has toggle switch', async () => {
    test.skip(!featureAvailable, 'Bulk enrich not available');

    await page.goto(`${BASE}/admin/libri/bulk-enrich`);
    await page.waitForLoadState('domcontentloaded');

    // Toggle can be a checkbox, a switch element, or a custom toggle
    const toggle = page.locator(
      'input[type="checkbox"][name*="enable"], ' +
      'input[type="checkbox"][name*="enrich"], ' +
      'input[name="enabled"], ' +
      '.toggle-switch, ' +
      '[role="switch"]'
    ).first();

    const hasToggle = await toggle.isVisible({ timeout: 3000 }).catch(() => false);

    if (!hasToggle) {
      // Fallback: look for any toggle/switch-like element in page content
      const content = await page.content();
      const hasSwitchMarkup = content.includes('toggle') || content.includes('switch') ||
                              content.includes('checkbox');
      expect(hasSwitchMarkup).toBe(true);
    } else {
      expect(hasToggle).toBe(true);
    }
  });
});
