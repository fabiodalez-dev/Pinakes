// @ts-check
/**
 * E2E coverage for every SweetAlert in the "users" cluster.
 *
 * Views under test:
 *   - app/Views/utenti/index.php
 *   - app/Views/utenti/dettagli_utente.php
 *
 * Each test asserts BOTH that the SweetAlert is shown AND that the
 * underlying outcome happened (DB state change, redirect, or toast text).
 *
 * Run with:
 *   npx playwright test tests/swal-users.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS ?? '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

const HAS_E2E_ENV = Boolean(ADMIN_EMAIL && ADMIN_PASS && DB_USER && DB_NAME && (DB_HOST || DB_SOCKET));

test.skip(!HAS_E2E_ENV, 'Missing E2E env vars for users SweetAlert tests');

function mysqlArgs(sql = '', batch = false) {
  const args = [];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER);
  if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
  args.push(DB_NAME);
  if (batch) args.push('-N', '-B');
  if (sql !== '') args.push('-e', sql);
  return args;
}

function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql, true), { encoding: 'utf-8', timeout: 10000 }).trim();
}

function dbExec(sql, timeout = 10000) {
  execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout });
}

function escapeSql(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

/**
 * Wait until the #utenti-table DataTable has finished initialising. The export
 * / clear-filter / pending-widget click handlers are bound inside DataTables'
 * initComplete callback (index.php), so clicking a (statically-rendered) button
 * before init leaves the handler unbound and the SweetAlert never fires.
 */
async function waitUsersTableReady(page) {
  await page.waitForSelector('#utenti-table_wrapper', { timeout: 15000 }).catch(() => {});
  await page.waitForFunction(() => {
    const info = document.querySelector('#utenti-table_info');
    return !!(info && info.textContent && info.textContent.trim().length > 0);
  }, { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(300);
}

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const emailField = page.locator('input[name="email"]');
  if (await emailField.isVisible({ timeout: 3000 }).catch(() => false)) {
    await emailField.fill(ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
      page.waitForURL(/\/admin\//, { timeout: 15000 }),
      page.click('button[type="submit"]'),
    ]);
  }
}

// Per-run unique suffix (mirrors series-cycles TAG style).
const TAG = 'E2E_SWAL_USERS_' + Date.now();

/**
 * Insert a fresh suspended user and return its id. A suspended user with
 * NO loan history is the precondition for both the activate-directly
 * triggers (index pending widget + detail page) and is also safely
 * hard-deletable by the deleteUser flow.
 */
let __userSeq = 0;
function createSuspendedUser(label) {
  // codice_tessera is varchar(20) + UNIQUE. TAG alone already fills the 20-char
  // budget, so `${TAG}_${label}`.slice(0,20) collided across labels. Use a short
  // per-call unique code instead (cleanup still matches rows by nome = TAG).
  const tessera = `U${(__userSeq++).toString(36)}_${Date.now().toString(36)}`.slice(0, 20);
  const email = `${TAG}_${label}@example.test`.toLowerCase();
  dbExec(`
    INSERT INTO utenti
      (codice_tessera, nome, cognome, email, password, stato, tipo_utente, created_at, updated_at)
    VALUES
      ('${escapeSql(tessera)}', '${escapeSql(TAG)}', '${escapeSql(label)}',
       '${escapeSql(email)}', '${escapeSql('$2y$10$abcdefghijklmnopqrstuv')}',
       'sospeso', 'standard', NOW(), NOW())
  `);
  return Number(dbQuery(`SELECT id FROM utenti WHERE email = '${escapeSql(email)}' LIMIT 1`));
}

function cleanupUsers() {
  // FK-safe: delete only the rows we created (no loans were attached).
  dbExec(`DELETE FROM utenti WHERE nome = '${escapeSql(TAG)}' OR codice_tessera LIKE '${escapeSql(TAG)}%'`);
}

test.describe.serial('SweetAlerts — users cluster', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    cleanupUsers();
    context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);
  });

  test.afterAll(async () => {
    try { cleanupUsers(); } catch { /* best-effort */ }
    await context?.close();
  });

  // ── index.php:124 — confirm-action: activate-directly from the pending widget
  test('index.php:124 — pending-widget activate-directly confirm activates the user', async () => {
    const userId = createSuspendedUser('idx_activate');
    await page.goto(`${BASE}/admin/users`);
    // The pending-approval widget renders the data-swal-confirm form for this user.
    const form = page.locator(`form[action$="/admin/users/${userId}/activate-directly"][data-swal-confirm]`);
    await expect(form).toBeVisible();
    await form.locator('button[type="submit"]').click();

    // (a) SweetAlert appears
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-popup')).toBeVisible();

    // (b) Outcome: confirm -> POST -> redirect to detail with success flag, stato becomes attivo
    await Promise.all([
      page.waitForURL(/\/admin\/users\/details\/\d+\?success=activated_directly/, { timeout: 15000 }),
      page.locator('.swal2-confirm').click(),
    ]);
    expect(dbQuery(`SELECT stato FROM utenti WHERE id = ${userId}`)).toBe('attivo');
  });

  // ── index.php:537 — success-toast: clear-filters
  test('index.php:537 — clear-filters shows the "Filtri cancellati" success toast', async () => {
    await page.goto(`${BASE}/admin/users`);
    await waitUsersTableReady(page);
    // Wait for the DataTable to wire the clear-filters handler (initComplete).
    await page.waitForSelector('#clear-filters', { timeout: 10000 });
    // Give the filter a value so clearing is meaningful, then click.
    await page.fill('#search_text', 'zzz');
    await page.click('#clear-filters');

    // (a) + (b): the success toast appears with the expected text.
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-popup')).toContainText('Filtri cancellati');
    await expect(page.locator('.swal2-popup')).toContainText('Tutti i filtri sono stati rimossi');
    // Filters were actually reset.
    await expect(page.locator('#search_text')).toHaveValue('');
  });

  // ── index.php:563 — confirm-destructive: deleteUser confirm dialog -> row removed
  test('index.php:563 — deleteUser destructive confirm removes the user row', async () => {
    const userId = createSuspendedUser('idx_delete');
    await page.goto(`${BASE}/admin/users`);
    await page.waitForSelector('#utenti-table', { timeout: 10000 });

    // Trigger the delete flow directly (DataTable rows are server-side paged;
    // calling the exposed window.deleteUser is the documented entry point).
    await page.evaluate((id) => window.deleteUser(id), userId);

    // (a) destructive SweetAlert appears
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-popup')).toContainText('Sei sicuro?');

    // (b) confirm -> fetch POST delete -> row gone from DB
    await page.locator('.swal2-confirm').click();
    await expect(page.locator('.swal2-popup')).toContainText('Eliminato!', { timeout: 10000 });
    expect(dbQuery(`SELECT COUNT(*) FROM utenti WHERE id = ${userId}`)).toBe('0');
  });

  // ── index.php:580 — success-toast: deleteUser success
  test('index.php:580 — deleteUser success toast "Eliminato!" shown after delete', async () => {
    const userId = createSuspendedUser('idx_delete_ok');
    await page.goto(`${BASE}/admin/users`);
    await page.waitForSelector('#utenti-table', { timeout: 10000 });

    await page.evaluate((id) => window.deleteUser(id), userId);
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await page.locator('.swal2-confirm').click();

    // (a) + (b): success toast text confirms the happy path completed.
    await page.waitForSelector('.swal2-icon-success', { timeout: 10000 });
    await expect(page.locator('.swal2-popup')).toContainText('Eliminato!');
    await expect(page.locator('.swal2-popup')).toContainText("L'utente è stato eliminato.");
    expect(dbQuery(`SELECT COUNT(*) FROM utenti WHERE id = ${userId}`)).toBe('0');
  });

  // ── index.php:584 — error-alert: deleteUser error when the POST returns non-ok
  test('index.php:584 — deleteUser error alert on a failing delete request', async () => {
    await page.goto(`${BASE}/admin/users`);
    await page.waitForSelector('#utenti-table', { timeout: 10000 });

    // Deliberately surface the error branch: stub fetch so the delete POST
    // resolves to a non-ok, non-redirected response (response.ok === false).
    await page.evaluate(() => {
      const realFetch = window.fetch.bind(window);
      window.fetch = (input, init) => {
        const url = typeof input === 'string' ? input : (input && input.url) || '';
        if (url.includes('/admin/users/delete/')) {
          return Promise.resolve(new Response('forced failure', { status: 500 }));
        }
        return realFetch(input, init);
      };
    });

    // Any id works — the stubbed fetch never reaches the server.
    await page.evaluate(() => window.deleteUser(999999999));
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await page.locator('.swal2-confirm').click();

    // (a) + (b): the error SweetAlert appears.
    await page.waitForSelector('.swal2-icon-error', { timeout: 10000 });
    await expect(page.locator('.swal2-icon-error')).toBeVisible();
    await expect(page.locator('.swal2-popup')).toContainText(
      "Non è stato possibile eliminare l'utente"
    );
  });

  // ── index.php:650 — info: CSV export "Generazione CSV in corso..." toast
  test('index.php:650 — CSV export shows the "Generazione CSV in corso..." info toast', async () => {
    await page.goto(`${BASE}/admin/users`);
    await waitUsersTableReady(page);
    await page.waitForSelector('#export-excel', { timeout: 10000 });

    // The handler shows the toast then sets location.href to the CSV endpoint.
    // Aborting that navigation still tears down the toast; instead fulfil it as a
    // file download (Content-Disposition: attachment) so the browser downloads
    // rather than navigates — the page (and its info toast) survive.
    await page.route('**/admin/users/export/csv*', route =>
      route.fulfill({
        status: 200,
        headers: { 'content-type': 'text/csv', 'content-disposition': 'attachment; filename="users.csv"' },
        body: 'id,nome\n1,test\n',
      }),
    );

    await page.click('#export-excel');

    // (a) + (b): the info toast appears with the expected title.
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-icon-info')).toBeVisible();
    await expect(page.locator('.swal2-popup')).toContainText('Generazione CSV in corso...');
  });

  // ── index.php:674 — info: PDF export empty-state "Nessun dato"
  test('index.php:674 — PDF export with no rows shows the "Nessun dato" info alert', async () => {
    // Filter to a value that returns zero rows so the table data array is empty.
    await page.goto(`${BASE}/admin/users`);
    await waitUsersTableReady(page);
    await page.waitForSelector('#export-pdf', { timeout: 10000 });
    // The table is serverSide; the search input reloads on 'keyup', which fill()
    // does not fire — type the value so the ajax reload triggers, then wait for
    // DataTables to render its empty-result row.
    await page.locator('#search_text').pressSequentially(`${TAG}_NO_SUCH_USER`, { delay: 25 });
    await expect(page.locator('#utenti-table td.dt-empty')).toBeVisible({ timeout: 10000 });

    await page.click('#export-pdf');

    // (a) + (b): the empty-state info alert appears.
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-icon-info')).toBeVisible();
    await expect(page.locator('.swal2-popup')).toContainText('Nessun dato');
    await expect(page.locator('.swal2-popup')).toContainText('Non ci sono dati da esportare');
  });

  // ── index.php:756 — success-toast: PDF export success after jsPDF generates
  test('index.php:756 — PDF export success toast "PDF generato!" after generation', async () => {
    // Need at least one row in the (filtered) table; create a normal user.
    const userId = createSuspendedUser('pdf_ok');
    await page.goto(`${BASE}/admin/users`);
    await waitUsersTableReady(page);
    await page.waitForSelector('#export-pdf', { timeout: 10000 });
    // Filter to our unique user so exactly one row is present (serverSide search
    // reloads on keyup — type it; fill() would not trigger the reload).
    await page.locator('#search_text').pressSequentially(`${TAG}_pdf_ok`, { delay: 25 });
    await expect(page.locator('#utenti-table tbody')).toContainText(TAG, { timeout: 10000 });

    // Stop the actual file save from blocking; let jsPDF run then fire the toast.
    await page.click('#export-pdf');

    // (a) + (b): jsPDF loads/generates, then the success toast appears.
    await page.waitForSelector('.swal2-icon-success', { timeout: 20000 });
    await expect(page.locator('.swal2-popup')).toContainText('PDF generato!');
    await expect(page.locator('.swal2-popup')).toContainText('Il download dovrebbe iniziare automaticamente');

    dbExec(`DELETE FROM utenti WHERE id = ${userId}`);
  });

  // ── dettagli_utente.php:247 — confirm-action: detail-page activate-directly
  test('dettagli_utente.php:247 — detail-page activate-directly confirm activates the user', async () => {
    const userId = createSuspendedUser('detail_activate');
    await page.goto(`${BASE}/admin/users/details/${userId}`);

    // The approval section (data-swal-confirm form) only renders for stato=sospeso.
    const form = page.locator(`form[action$="/admin/users/${userId}/activate-directly"][data-swal-confirm]`);
    await expect(form).toBeVisible();
    await form.locator('button[type="submit"]').click();

    // (a) SweetAlert appears
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-popup')).toContainText('Conferma attivazione');

    // (b) confirm -> POST -> redirect with success flag, stato becomes attivo
    await Promise.all([
      page.waitForURL(/\/admin\/users\/details\/\d+\?success=activated_directly/, { timeout: 15000 }),
      page.locator('.swal2-confirm').click(),
    ]);
    expect(dbQuery(`SELECT stato FROM utenti WHERE id = ${userId}`)).toBe('attivo');
  });
});
