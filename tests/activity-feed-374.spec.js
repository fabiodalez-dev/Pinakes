// E2E: activity feed (#374) through the real browser.
// The unit suite covers ActivityLog's read/write logic; THIS spec proves the
// user-visible surface: a real admin edit produces a timeline entry on the
// book page, the dashboard feed renders with working (allow-listed) filters,
// and the section is gated away from patrons. Conventions follow
// import-csv-e2e.spec.js (env parsing, MYSQL_PWD-based dbQuery, skip guard).
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';

const e2e = (key) => {
  const v = process.env[key];
  return v === undefined || v === 'undefined' ? '' : v;
};
const DB_USER = e2e('E2E_DB_USER');
const DB_PASS = e2e('E2E_DB_PASS');
const DB_HOST = e2e('E2E_DB_HOST');
const DB_PORT = e2e('E2E_DB_PORT');
const DB_SOCKET = e2e('E2E_DB_SOCKET');
const DB_NAME = e2e('E2E_DB_NAME');

function dbQuery(sql) {
  // Password via MYSQL_PWD env, never as a -p argv (visible in ps//proc).
  const args = ['-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql];
  if (DB_HOST) {
    args.splice(2, 0, '-h', DB_HOST);
    if (DB_PORT) args.splice(4, 0, '-P', DB_PORT);
  } else if (DB_SOCKET) {
    args.splice(2, 0, '-S', DB_SOCKET);
  }
  return execFileSync('mysql', args, {
    encoding: 'utf-8',
    timeout: 10000,
    env: { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}

const FEED_SKIP = !e2e('E2E_ADMIN_EMAIL') || !e2e('E2E_ADMIN_PASS') || !DB_USER || !DB_PASS || !DB_NAME;

const sqlEscape = (s) => String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
const RUN = Date.now().toString(36) + 'af';
const TITLE_V1 = `ActivityFeedLibro ${RUN}`;
const TITLE_V2 = `ActivityFeedLibroDue ${RUN}`;
const PATRON_EMAIL = `zz-feed-patron-${RUN}@test.local`;
const BORROWER_EMAIL = `zz-feed-borrower-${RUN}@test.local`;
const BORROWER_SURNAME = `CopyProbe${RUN}`;
const COPY_INVENTORY = `INV-374-${RUN}`.toUpperCase();
let bookId = '';

async function fillAutocomplete(page, inputSelector, suggestSelector, query, apiUrlFragment) {
  for (let attempt = 1; attempt <= 3; attempt++) {
    await page.fill(inputSelector, '');
    const responsePromise = page.waitForResponse(
      (resp) => resp.url().includes(apiUrlFragment) && resp.status() === 200,
      { timeout: 15000 },
    );
    await page.locator(inputSelector).pressSequentially(query, { delay: 50 });
    await responsePromise;
    const suggestionItem = page.locator(`${suggestSelector} .suggestion-item`).first();
    if (await suggestionItem.isVisible({ timeout: 3000 }).catch(() => false)) {
      await suggestionItem.click();
      return;
    }
    await page.waitForTimeout(300);
  }
  throw new Error(`autocomplete never suggested for ${query}`);
}

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const email = page.locator('input[name="email"]');
  if (await email.isVisible({ timeout: 3000 }).catch(() => false)) {
    await email.fill(process.env.E2E_ADMIN_EMAIL);
    await page.fill('input[name="password"]', process.env.E2E_ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/.*(?:dashboard|admin).*/, { timeout: 15000 });
  }
}

function feedCleanup() {
  if (bookId) {
    dbQuery(`DELETE FROM log_modifiche WHERE tabella='libri' AND record_id=${Number(bookId)}`);
  }
  dbQuery(
    `DELETE lm FROM log_modifiche lm JOIN libri l ON l.id=lm.record_id AND lm.tabella='libri' WHERE l.titolo IN ('${sqlEscape(TITLE_V1)}','${sqlEscape(TITLE_V2)}');`
    + `DELETE p FROM prestiti p JOIN libri l ON l.id=p.libro_id WHERE l.titolo IN ('${sqlEscape(TITLE_V1)}','${sqlEscape(TITLE_V2)}');`
    + `DELETE c FROM copie c JOIN libri l ON l.id=c.libro_id WHERE l.titolo IN ('${sqlEscape(TITLE_V1)}','${sqlEscape(TITLE_V2)}');`
    + `DELETE FROM libri WHERE titolo IN ('${sqlEscape(TITLE_V1)}','${sqlEscape(TITLE_V2)}');`
    + `DELETE FROM utenti WHERE email='${sqlEscape(PATRON_EMAIL)}';`
    + `DELETE FROM utenti WHERE email='${sqlEscape(BORROWER_EMAIL)}';`,
  );
}

test.describe.serial('Activity feed (#374)', () => {
  test.skip(FEED_SKIP, 'E2E credentials not configured');
  test.beforeAll(() => {
    feedCleanup();
    dbQuery(
      `INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES ('${sqlEscape(TITLE_V1)}', 1, 1, 'disponibile')`,
    );
    bookId = dbQuery(`SELECT id FROM libri WHERE titolo='${sqlEscape(TITLE_V1)}' AND deleted_at IS NULL LIMIT 1`);
  });
  test.afterAll(() => feedCleanup());

  test('a real admin edit produces a timeline entry on the book page', async ({ page }) => {
    test.setTimeout(120000);
    await loginAsAdmin(page);

    // Real edit through the form (same driving pattern as full-test Phase 6).
    await page.goto(`${BASE}/admin/books/edit/${bookId}`);
    await expect(page.locator('#titolo')).toBeVisible({ timeout: 10000 });
    await page.waitForFunction(() => (document.getElementById('titolo') || {}).value !== '');
    await page.fill('#titolo', TITLE_V2);
    await page.locator('#bookForm button[type="submit"]').click();
    await page.waitForSelector('.swal2-popup:visible', { timeout: 10000 });
    await page.locator('.swal2-confirm:visible').click();
    await page.waitForFunction(
      (id) => !window.location.pathname.endsWith(`/admin/books/edit/${id}`),
      bookId,
      { timeout: 15000 },
    );

    // DB truth: an ActivityLog row exists for the edit.
    const rows = dbQuery(
      `SELECT COUNT(*) FROM log_modifiche WHERE tabella='libri' AND record_id=${Number(bookId)} AND azione='aggiornamento'`,
    );
    expect(Number(rows)).toBeGreaterThan(0);

    // UI truth: the book page renders the timeline with the event and operator.
    const errors = [];
    // Resource-load failures (e.g. a fixture book without a cover → 404 image)
    // surface as console errors; only real JS errors should fail the test.
    // "Applying inline style" CSP notices are a PRE-EXISTING book-page quirk
    // for copy-less books (a library injects empty style elements) — verified
    // absent on normal books and unrelated to the feed.
    page.on('console', (m) => {
      if (m.type() !== 'error') return;
      const t = m.text();
      if (/Failed to load resource|Applying inline style/i.test(t)) return;
      errors.push(t);
    });
    await page.goto(`${BASE}/admin/books/${bookId}`);
    const feed = page.locator('#activity-feed');
    await expect(feed).toBeVisible({ timeout: 10000 });
    await expect(feed).toContainText('Cronologia modifiche');
    // book.updated renders its EVENT_LABELS entry, not the bare action fallback.
    await expect(feed).toContainText('Libro aggiornato');
    const adminName = dbQuery(
      `SELECT TRIM(CONCAT(nome,' ',cognome)) FROM utenti WHERE email='${sqlEscape(process.env.E2E_ADMIN_EMAIL)}' LIMIT 1`,
    );
    if (adminName) {
      await expect(feed).toContainText(adminName);
    }

    // Type filter on the book context: 'edit' keeps the event, 'loan'
    // (allow-listed but with no matching rows) yields the empty state.
    await page.goto(`${BASE}/admin/books/${bookId}?activity_type=edit`);
    await expect(page.locator('#activity-feed')).toContainText('Libro aggiornato', { timeout: 10000 });
    await page.goto(`${BASE}/admin/books/${bookId}?activity_type=loan`);
    await expect(page.locator('#activity-feed')).toContainText('Nessuna attività registrata');
    // Out-of-allow-list value is ignored (falls back to unfiltered).
    await page.goto(`${BASE}/admin/books/${bookId}?activity_type=<script>x</script>`);
    await expect(page.locator('#activity-feed')).toContainText('Libro aggiornato');

    expect(errors).toEqual([]);
  });

  test('a real admin loan records the physical copy and the timeline shows its inventory number', async ({ page }) => {
    test.setTimeout(120000);
    await loginAsAdmin(page);

    // Real fixtures: a named borrower and a physical copy with a known
    // inventory number. The LOAN itself goes through the real admin form,
    // so the copy assignment is the production allocator's, not ours.
    const adminHash = dbQuery(
      `SELECT password FROM utenti WHERE email='${sqlEscape(process.env.E2E_ADMIN_EMAIL)}' LIMIT 1`,
    );
    dbQuery(
      `INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata)
       VALUES ('CP${RUN.slice(0, 8).toUpperCase()}', 'Probe', '${sqlEscape(BORROWER_SURNAME)}', '${sqlEscape(BORROWER_EMAIL)}', '${sqlEscape(adminHash)}', 'standard', 'attivo', 1)`,
    );
    dbQuery(
      `INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${Number(bookId)}, '${sqlEscape(COPY_INVENTORY)}', 'disponibile')`,
    );
    const copyId = dbQuery(`SELECT id FROM copie WHERE numero_inventario='${sqlEscape(COPY_INVENTORY)}' LIMIT 1`);
    dbQuery(`UPDATE libri SET copie_totali=1, copie_disponibili=1 WHERE id=${Number(bookId)}`);

    // Create the loan through the REAL admin form (same driving pattern as
    // full-test Phase 14.2: autocomplete pickers + app-timezone loan date).
    await page.goto(`${BASE}/admin/loans/create`);
    await page.waitForLoadState('domcontentloaded');
    await fillAutocomplete(page, '#utente_search', '#utente_suggest', BORROWER_SURNAME, '/api/search/utenti');
    await fillAutocomplete(page, '#libro_search', '#libro_suggest', TITLE_V2, '/api/search/libri');
    expect(Number(await page.locator('#libro_id').inputValue())).toBe(Number(bookId));
    const appLoanDate = await page.locator('#data_prestito').inputValue();
    expect(appLoanDate).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    await page.locator('form button[type="submit"]').first().click();
    await page.waitForURL((url) => url.searchParams.get('created') === '1', { timeout: 20000 });

    // DB truth: the production allocator bound OUR physical copy to the loan.
    const loanCopy = dbQuery(
      `SELECT copia_id FROM prestiti WHERE libro_id=${Number(bookId)} AND attivo=1 ORDER BY id DESC LIMIT 1`,
    );
    expect(Number(loanCopy)).toBe(Number(copyId));

    // UI truth: the timeline shows the loan with the resolved inventory
    // number and the borrower's name — never the raw ids.
    await page.goto(`${BASE}/admin/books/${bookId}?activity_type=loan`);
    const feed = page.locator('#activity-feed');
    await expect(feed).toBeVisible({ timeout: 10000 });
    await expect(feed).toContainText('Prestito creato');
    await expect(feed).toContainText(COPY_INVENTORY);
    await expect(feed).toContainText(BORROWER_SURNAME);
    await expect(feed).not.toContainText(`#${copyId}`);
  });

  test('the dashboard feed renders and the type filter is allow-listed', async ({ page }) => {
    test.setTimeout(90000);
    await loginAsAdmin(page);

    const errors = [];
    // Same filter as the book-page test: resource 404s and the pre-existing
    // "Applying inline style" CSP notices (dashboard JS injects styles) are
    // page-wide noise unrelated to the feed.
    page.on('console', (m) => {
      if (m.type() !== 'error') return;
      const t = m.text();
      if (/Failed to load resource|Applying inline style/i.test(t)) return;
      errors.push(t);
    });

    await page.goto(`${BASE}/admin/dashboard`);
    const feed = page.locator('#activity-feed');
    await expect(feed).toBeVisible({ timeout: 10000 });
    await expect(feed).toContainText('Attività recenti');

    // Valid filter keeps our edit event visible.
    await page.goto(`${BASE}/admin/dashboard?activity_type=edit`);
    await expect(page.locator('#activity-feed')).toContainText(TITLE_V2, { timeout: 10000 });

    // An out-of-allow-list value must be ignored, not crash the page.
    await page.goto(`${BASE}/admin/dashboard?activity_type=<script>alert(1)</script>`);
    await expect(page.locator('#activity-feed')).toBeVisible({ timeout: 10000 });

    // Server-side search: a matching query keeps the entry, a nonsense one
    // yields the empty state; LIKE wildcards match literally (no widening).
    await page.goto(`${BASE}/admin/dashboard?activity_q=${encodeURIComponent(TITLE_V2)}`);
    await expect(page.locator('#activity-feed')).toContainText(TITLE_V2, { timeout: 10000 });
    await page.goto(`${BASE}/admin/dashboard?activity_q=zz-nessun-match-${RUN}`);
    await expect(page.locator('#activity-feed')).toContainText('Nessuna attività registrata');
    await page.goto(`${BASE}/admin/dashboard?activity_q=${encodeURIComponent('%')}`);
    await expect(page.locator('#activity-feed')).toContainText('Nessuna attività registrata');

    // AJAX enhancement: changing the type filter and typing in the search
    // box swap the feed in place — no full page reload. The __stay flag
    // would be wiped by any navigation.
    await page.goto(`${BASE}/admin/dashboard`);
    await page.evaluate(() => { window.__stay = true; });
    await page.selectOption('#activity-type', 'edit');
    await expect(page.locator('#activity-feed')).toContainText(TITLE_V2, { timeout: 10000 });
    await page.fill('#activity-q', `zz-nessun-match-${RUN}`);
    await expect(page.locator('#activity-feed')).toContainText('Nessuna attività registrata', { timeout: 10000 });
    expect(await page.evaluate(() => window.__stay === true)).toBe(true);
    // replaceState keeps the URL shareable even without a navigation.
    expect(await page.evaluate(() => window.location.search)).toContain('activity_q=');

    // The list scrolls inside its own container instead of growing the page.
    await page.goto(`${BASE}/admin/dashboard`);
    const overflow = await page.locator('#activity-feed .activity-scroll').evaluate(
      (el) => getComputedStyle(el).overflowY,
    );
    expect(overflow).toBe('auto');

    expect(errors).toEqual([]);
  });

  test('patrons never see the activity feed', async ({ browser }) => {
    test.setTimeout(90000);
    // Standard (patron) account seeded directly — same pattern as loan specs.
    const hash = dbQuery(
      `SELECT password FROM utenti WHERE email='${sqlEscape(process.env.E2E_ADMIN_EMAIL)}' LIMIT 1`,
    );
    dbQuery(
      `INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata)
       VALUES ('ZZFEED${RUN.slice(0, 8).toUpperCase()}', 'Feed', 'Patron', '${sqlEscape(PATRON_EMAIL)}', '${sqlEscape(hash)}', 'standard', 'attivo', 1)`,
    );

    const context = await browser.newContext();
    const page = await context.newPage();
    try {
      await page.goto(`${BASE}/accedi`);
      await page.fill('input[name="email"]', PATRON_EMAIL);
      await page.fill('input[name="password"]', process.env.E2E_ADMIN_PASS);
      await page.click('button[type="submit"]');
      await page.waitForLoadState('domcontentloaded');

      await page.goto(`${BASE}/admin/dashboard`);
      await page.waitForLoadState('domcontentloaded');
      // Whether the patron lands on the shared dashboard or gets redirected,
      // the administrative feed must never render for them.
      await expect(page.locator('#activity-feed')).toHaveCount(0);
    } finally {
      await context.close();
    }
  });
});
