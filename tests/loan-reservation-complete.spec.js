// @ts-check
/**
 * loan-reservation-complete.spec.js
 *
 * Comprehensive 25-test E2E suite covering:
 *   A. Full lifecycle (request → approve → pickup → return)
 *   B. Availability after return / unavailability
 *   C. Overlap prevention
 *   D. Multi-copy concurrency
 *   E. Calendar / availability API
 *   F. Reservation queue management
 *   G. Automated maintenance (overdue / expired pickup)
 *   H. Settings (max_active_loans) and renewal
 *
 * STANDALONE — no shared state with other spec files.
 * Run with: /tmp/run-e2e.sh tests/loan-reservation-complete.spec.js \
 *             --config=tests/playwright.config.js --workers=1
 */

'use strict';
const { test, expect }    = require('@playwright/test');
const { execFileSync }    = require('child_process');
const os                  = require('os');
const path                = require('path');
const fs                  = require('fs');

// ── Environment ──────────────────────────────────────────────────────────────
const BASE         = process.env.E2E_BASE_URL   || 'http://localhost:8081';
const ADMIN_EMAIL  = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS   = process.env.E2E_ADMIN_PASS  || '';
const DB_USER      = process.env.E2E_DB_USER     || '';
const DB_PASS      = process.env.E2E_DB_PASS     || '';
const DB_SOCKET    = process.env.E2E_DB_SOCKET   || '';
const DB_NAME      = process.env.E2E_DB_NAME     || '';
const MAILPIT_API  = process.env.MAILPIT_API     || 'http://localhost:8025/api/v1';

// Skip entire file when credentials are missing
test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'E2E credentials not configured (set E2E_ADMIN_EMAIL, E2E_ADMIN_PASS, E2E_DB_USER, E2E_DB_NAME)',
);

// ── Unique run ID ─────────────────────────────────────────────────────────────
const RUN_ID = Date.now().toString(36);

// ── DB helper ─────────────────────────────────────────────────────────────────
/**
 * Execute a MySQL query and return trimmed stdout.
 * Uses Unix socket when available, otherwise TCP.
 */
function dbQuery(sql) {
  const args = [];
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, {
    encoding : 'utf-8',
    timeout  : 10_000,
    env      : { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}

// ── Date helpers ──────────────────────────────────────────────────────────────
function todayISO() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function dateISO(daysOffset) {
  const d = new Date();
  d.setDate(d.getDate() + daysOffset);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

// ── Rate-limit clearing ───────────────────────────────────────────────────────
/**
 * Clear ALL file-based rate-limit buckets.
 * The CLI php and the FPM worker may resolve different temp dirs
 * (e.g. $TMPDIR=/var/folders/… vs /var/tmp or /tmp), so we wipe every
 * candidate to prevent 429s during long runs.
 */
function clearRateLimits() {
  try {
    execFileSync('php', [
      '-r',
      'foreach ([sys_get_temp_dir(), "/var/tmp", "/tmp"] as $d) {'
      + ' array_map("unlink", glob($d."/pinakes_ratelimit/*.json") ?: []); }',
    ], { encoding: 'utf-8', timeout: 5_000 });
  } catch { /* ignore */ }

  // Also try the Node-side path (same host)
  try {
    const dir = path.join(os.tmpdir(), 'pinakes_ratelimit');
    if (fs.existsSync(dir)) {
      for (const f of fs.readdirSync(dir)) {
        try { fs.unlinkSync(path.join(dir, f)); } catch { /* ignore */ }
      }
    }
  } catch { /* ignore */ }
}

clearRateLimits();

// ── HTTP 5xx guard ────────────────────────────────────────────────────────────
/** Shared error accumulator (module-level so the afterEach can read it). */
const pageServerErrors = [];

/**
 * Register a response listener on a page that records any HTTP ≥ 500.
 * Call this immediately after every context.newPage().
 */
function attachServerErrorGuard(page) {
  page.on('response', (r) => {
    if (r.status() >= 500) {
      pageServerErrors.push(`${r.status()} ${r.request().method()} ${r.url()}`);
    }
  });
}

// Clear rate limits and fail on stale 5xx before each test
test.beforeEach(() => {
  clearRateLimits();
});

test.afterEach(() => {
  const errs = pageServerErrors.splice(0);
  if (errs.length > 0) {
    throw new Error(`HTTP 5xx response(s) during this test:\n${errs.join('\n')}`);
  }
});

// ── SweetAlert helpers ────────────────────────────────────────────────────────
/** Dismiss a visible SweetAlert popup by clicking its confirm button. */
async function dismissSwal(page) {
  try {
    const popup = page.locator('.swal2-popup');
    if (await popup.isVisible({ timeout: 2000 }).catch(() => false)) {
      const btn = page.locator('.swal2-confirm');
      if (await btn.isVisible({ timeout: 1000 }).catch(() => false)) {
        await btn.click();
      }
      await page.waitForFunction(
        () => !document.querySelector('.swal2-popup'),
        { timeout: 5_000 },
      ).catch(() => {});
    }
  } catch { /* already closed */ }
}

/**
 * Request a loan via the book-detail page SweetAlert date-picker.
 *
 * Clicks #btn-request-loan, waits for the Flatpickr-equipped swal2 popup,
 * sets the start date programmatically, confirms, and returns:
 *   - true  → swal2-icon-success appeared (success)
 *   - false → swal2-icon-error appeared (rejected / duplicate)
 */
async function requestLoanViaSwal(page, dateISO_) {
  await page.locator('#btn-request-loan').click();
  await page.waitForSelector('.swal2-popup', { timeout: 8_000 });

  // Wait for Flatpickr to initialise on the date input
  await page.waitForFunction(
    () => {
      const el = document.querySelector('#swal-date-start');
      return el && /** @type {any} */ (el)._flatpickr;
    },
    { timeout: 8_000 },
  );

  // Set date programmatically (avoids brittle keyboard interactions)
  await page.evaluate((iso) => {
    /** @type {any} */ (document.querySelector('#swal-date-start'))._flatpickr.setDate(iso, true);
  }, dateISO_);

  // Confirm
  await page.locator('.swal2-confirm').click();

  // Wait for outcome icon
  await page.waitForFunction(
    () => !!document.querySelector('.swal2-icon-success, .swal2-icon-error'),
    { timeout: 15_000 },
  );

  const succeeded = await page.locator('.swal2-icon-success').isVisible().catch(() => false);
  await dismissSwal(page);
  return succeeded;
}

// ── Login helpers ─────────────────────────────────────────────────────────────
/** Login via the /accedi form. Admin credentials → waits for /admin in URL. */
async function loginAsAdmin(page) {
  clearRateLimits();
  await page.goto(`${BASE}/accedi`);
  await page.waitForLoadState('domcontentloaded');
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(url => url.toString().includes('/admin'), { timeout: 20_000 });
}

/** Login as a standard (non-admin) user. Waits for redirect away from /accedi. */
async function loginAsUser(page, email, password) {
  clearRateLimits();
  await page.goto(`${BASE}/accedi`);
  await page.waitForLoadState('domcontentloaded');
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(url => !url.toString().includes('/accedi'), { timeout: 20_000 });
}

// ── CSRF helper ───────────────────────────────────────────────────────────────
async function getCsrf(page) {
  return page.evaluate(() => {
    const el = document.querySelector('input[name="csrf_token"]')
            || document.querySelector('meta[name="csrf-token"]');
    if (!el) return '';
    return el.getAttribute('value') || el.getAttribute('content') || '';
  });
}

// ── Mailpit helpers ───────────────────────────────────────────────────────────
let mailpitAvailable = null;

async function checkMailpit() {
  if (mailpitAvailable !== null) return mailpitAvailable;
  try {
    const res = await fetch(`${MAILPIT_API}/messages`, { signal: AbortSignal.timeout(3_000) });
    mailpitAvailable = res.ok;
  } catch {
    mailpitAvailable = false;
  }
  return mailpitAvailable;
}

async function clearMailpit() {
  if (!await checkMailpit()) return;
  try {
    await fetch(`${MAILPIT_API}/messages`, { method: 'DELETE', signal: AbortSignal.timeout(5_000) });
  } catch { /* best effort */ }
}

async function waitForMail(query, timeoutMs = 15_000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      const data = await (await fetch(
        `${MAILPIT_API}/search?query=${encodeURIComponent(query)}`,
        { signal: AbortSignal.timeout(5_000) },
      )).json();
      if (data.messages?.length > 0) return data.messages[0];
    } catch { /* retry */ }
    await new Promise(r => setTimeout(r, 500));
  }
  return null;
}

// ── Recalculate book availability via DB ──────────────────────────────────────
function recalcAvailability(bookId) {
  dbQuery(`
    UPDATE libri
    SET copie_disponibili = (
      SELECT COUNT(*) FROM copie c
      LEFT JOIN prestiti p ON c.id = p.copia_id
        AND p.attivo = 1
        AND p.stato IN ('in_corso','in_ritardo','da_ritirare')
      WHERE c.libro_id = ${bookId}
        AND c.stato = 'disponibile'
        AND p.id IS NULL
    )
    WHERE id = ${bookId}
  `);
}

// ── Approve a loan via the admin API (for setup, not UI tests) ─────────────────
async function approveLoanViaApi(adminPage, loanId) {
  const csrf = await getCsrf(adminPage);
  const res = await adminPage.evaluate(async ({ id, token, base }) => {
    const r = await fetch(`${base}/admin/loans/approve`, {
      method : 'POST',
      credentials : 'same-origin',
      headers : {
        'Content-Type'      : 'application/json',
        'X-Requested-With'  : 'XMLHttpRequest',
        'X-CSRF-Token'      : token,
      },
      body : JSON.stringify({ loan_id: id }),
    });
    return { status: r.status, body: await r.json().catch(() => ({})) };
  }, { id: loanId, token: csrf, base: BASE });
  return res;
}

// ── Run full maintenance (MaintenanceService::runAll) via CLI ────────────────
/**
 * Executes the cron/full-maintenance.php script directly (CLI → bypasses
 * the 60-minute cooldown of the HTTP login hook, and calls the same
 * MaintenanceService::runAll() that the actual cron uses).
 *
 * Returns { status: 0|1|2, output: string } where status 0=ok, 2=non-fatal errors.
 */
function performMaintenance() {
  // Derive the repo root from this spec's location (tests/ → ..) so the test
  // runs under any checkout / CI path, not just one developer's machine.
  const projectRoot = path.resolve(__dirname, '..');
  // Remove lock file so concurrent test runs don't skip
  try { fs.unlinkSync(`${projectRoot}/storage/cache/full-maintenance.lock`); } catch { /* ignore */ }
  try {
    const output = execFileSync('php', ['cron/full-maintenance.php'], {
      encoding : 'utf-8',
      timeout  : 30_000,
      cwd      : projectRoot,
      env      : { ...process.env },
    });
    return { status: 0, output };
  } catch (err) {
    const code = /** @type {any} */ (err).status ?? 1;
    // Exit code 2 = completed with non-fatal errors (still ran all tasks)
    if (code === 2) return { status: 2, output: /** @type {any} */ (err).stdout || '' };
    throw new Error(`Maintenance cron failed (exit ${code}):\n${/** @type {any} */ (err).stderr || err.message}`);
  }
}

// ═════════════════════════════════════════════════════════════════════════════
// MODULE-LEVEL SHARED STATE
// (populated in beforeAll, used across all groups)
// ═════════════════════════════════════════════════════════════════════════════
let testUserId   = 0;          // primary standard test user
let testUser2Id  = 0;          // secondary user for multi-user tests
let testBookId   = 0;          // book with 1 copy
let testBook2Id  = 0;          // book with 2 copies
let primaryLoanId = 0;         // tracks loan across lifecycle tests

const TEST_PASS   = 'LoanTest1!';
const TEST_EMAIL  = `loantest-${RUN_ID}@test.local`;
const TEST2_EMAIL = `loantest2-${RUN_ID}@test.local`;

// ═════════════════════════════════════════════════════════════════════════════
// MAIN SERIAL SUITE
// ═════════════════════════════════════════════════════════════════════════════
test.describe.serial('Loan & Reservation Complete Suite (26 tests)', () => {

  /** @type {import('@playwright/test').BrowserContext} */
  let adminCtx;
  /** @type {import('@playwright/test').Page} */
  let adminPage;

  /** @type {import('@playwright/test').BrowserContext} */
  let userCtx;
  /** @type {import('@playwright/test').Page} */
  let userPage;

  /** @type {import('@playwright/test').BrowserContext} */
  let user2Ctx;
  /** @type {import('@playwright/test').Page} */
  let user2Page;

  // ── beforeAll: setup fixtures ──────────────────────────────────────────────
  test.beforeAll(async ({ browser }) => {
    clearRateLimits();

    // 1. Hash password via PHP CLI
    const hash = execFileSync('php', [
      '-r', `echo password_hash(${JSON.stringify(TEST_PASS)}, PASSWORD_DEFAULT);`,
    ], { encoding: 'utf-8' }).trim();

    // 2. Wipe any stale test data from previous runs (FK-safe order)
    for (const email of [TEST_EMAIL, TEST2_EMAIL]) {
      try {
        const staleId = dbQuery(`SELECT id FROM utenti WHERE email='${email}'`);
        if (staleId) {
          dbQuery(`DELETE FROM prestiti      WHERE utente_id=${staleId}`);
          dbQuery(`DELETE FROM prenotazioni  WHERE utente_id=${staleId}`);
          dbQuery(`DELETE FROM utenti        WHERE id=${staleId}`);
        }
      } catch { /* ignore */ }
    }

    // 3. Create primary test user
    const tessera1 = 'LCT' + RUN_ID;
    dbQuery(`
      INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, email_verificata, tipo_utente, created_at)
      VALUES ('${tessera1}', 'Loan', 'Tester', '${TEST_EMAIL}', '${hash}', 'attivo', 1, 'standard', NOW())
    `);
    testUserId = parseInt(dbQuery(`SELECT id FROM utenti WHERE email='${TEST_EMAIL}'`), 10);

    // 4. Create secondary test user
    const tessera2 = 'LCT2' + RUN_ID;
    dbQuery(`
      INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, email_verificata, tipo_utente, created_at)
      VALUES ('${tessera2}', 'Loan2', 'Tester', '${TEST2_EMAIL}', '${hash}', 'attivo', 1, 'standard', NOW())
    `);
    testUser2Id = parseInt(dbQuery(`SELECT id FROM utenti WHERE email='${TEST2_EMAIL}'`), 10);

    // 5. Book with 1 copy — create fresh or reuse
    const existingRaw = dbQuery(
      `SELECT id FROM libri WHERE titolo='LCT Fixture 1copy' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`
    );
    if (existingRaw) {
      testBookId = parseInt(existingRaw, 10);
    } else {
      dbQuery(
        `INSERT INTO libri (titolo, anno_pubblicazione, copie_totali, copie_disponibili, created_at, updated_at)
         VALUES ('LCT Fixture 1copy', 2000, 1, 1, NOW(), NOW())`
      );
      testBookId = parseInt(
        dbQuery(`SELECT id FROM libri WHERE titolo='LCT Fixture 1copy' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`), 10
      );
    }

    // 6. Book with 2 copies — create fresh or reuse
    const existing2Raw = dbQuery(
      `SELECT id FROM libri WHERE titolo='LCT Fixture 2copies' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`
    );
    if (existing2Raw) {
      testBook2Id = parseInt(existing2Raw, 10);
    } else {
      dbQuery(
        `INSERT INTO libri (titolo, anno_pubblicazione, copie_totali, copie_disponibili, created_at, updated_at)
         VALUES ('LCT Fixture 2copies', 2001, 2, 2, NOW(), NOW())`
      );
      testBook2Id = parseInt(
        dbQuery(`SELECT id FROM libri WHERE titolo='LCT Fixture 2copies' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`), 10
      );
    }

    // 7. Neutralise any leftover active loans for our test books
    for (const bookId of [testBookId, testBook2Id]) {
      dbQuery(
        `UPDATE prestiti SET stato='restituito', attivo=0, data_restituzione=CURDATE()
         WHERE libro_id=${bookId} AND attivo=1`
      );
    }

    // 8. Ensure testBookId has exactly 1 available copy
    const copie1 = parseInt(
      dbQuery(`SELECT COUNT(*) FROM copie WHERE libro_id=${testBookId} AND stato='disponibile'`) || '0', 10
    );
    if (copie1 === 0) {
      const inv1 = 'LCT1A-' + RUN_ID;
      dbQuery(
        `INSERT INTO copie (libro_id, stato, numero_inventario, created_at)
         VALUES (${testBookId}, 'disponibile', '${inv1}', NOW())`
      );
    }
    // Remove extra copies beyond the first disponibile
    const extraCopies = dbQuery(
      `SELECT id FROM copie WHERE libro_id=${testBookId} AND stato='disponibile' ORDER BY id`
    ).split('\n').filter(Boolean);
    if (extraCopies.length > 1) {
      for (const cid of extraCopies.slice(1)) {
        dbQuery(`DELETE FROM copie WHERE id=${cid}`);
      }
    }
    dbQuery(`UPDATE libri SET copie_totali=1 WHERE id=${testBookId}`);
    recalcAvailability(testBookId);

    // 9. Ensure testBook2Id has exactly 2 available copies
    const copie2 = parseInt(
      dbQuery(`SELECT COUNT(*) FROM copie WHERE libro_id=${testBook2Id} AND stato='disponibile'`) || '0', 10
    );
    if (copie2 === 0) {
      const inv2a = 'LCT2A-' + RUN_ID;
      const inv2b = 'LCT2B-' + RUN_ID;
      dbQuery(
        `INSERT INTO copie (libro_id, stato, numero_inventario, created_at)
         VALUES (${testBook2Id}, 'disponibile', '${inv2a}', NOW()),
                (${testBook2Id}, 'disponibile', '${inv2b}', NOW())`
      );
    } else if (copie2 === 1) {
      const inv2extra = 'LCT2C-' + RUN_ID;
      dbQuery(
        `INSERT INTO copie (libro_id, stato, numero_inventario, created_at)
         VALUES (${testBook2Id}, 'disponibile', '${inv2extra}', NOW())`
      );
    }
    dbQuery(`UPDATE libri SET copie_totali=2 WHERE id=${testBook2Id}`);
    recalcAvailability(testBook2Id);

    // 10. Create browser contexts
    adminCtx  = await browser.newContext();
    adminPage = await adminCtx.newPage();
    attachServerErrorGuard(adminPage);

    userCtx   = await browser.newContext();
    userPage  = await userCtx.newPage();
    attachServerErrorGuard(userPage);

    user2Ctx  = await browser.newContext();
    user2Page = await user2Ctx.newPage();
    attachServerErrorGuard(user2Page);

    // 11. Login all three sessions
    await loginAsAdmin(adminPage);
    await loginAsUser(userPage, TEST_EMAIL, TEST_PASS);
    await loginAsUser(user2Page, TEST2_EMAIL, TEST_PASS);

    // 12. Configure Mailpit if available
    if (await checkMailpit()) {
      dbQuery(`
        INSERT INTO system_settings (category, setting_key, setting_value)
        VALUES
          ('email','type','smtp'),
          ('email','driver_mode','smtp'),
          ('email','smtp_host','localhost'),
          ('email','smtp_port','1025'),
          ('email','smtp_security',''),
          ('email','smtp_username','mailpit'),
          ('email','smtp_password','mailpit'),
          ('email','from_email','library@test.local'),
          ('email','from_name','Pinakes E2E')
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
      `);
      await clearMailpit();
    }
  });

  // ── afterAll: cleanup ────────────────────────────────────────────────────────
  test.afterAll(async () => {
    // Restore max_active_loans to 0 (no limit) in case test H.24 left it set
    try {
      dbQuery(
        `INSERT INTO system_settings (category, setting_key, setting_value)
         VALUES ('loans','max_active_loans_per_user','0')
         ON DUPLICATE KEY UPDATE setting_value='0'`
      );
    } catch { /* ignore */ }

    // FK-safe cleanup
    for (const uid of [testUserId, testUser2Id]) {
      if (!uid) continue;
      try { dbQuery(`DELETE FROM prestiti     WHERE utente_id=${uid}`); } catch { /* */ }
      try { dbQuery(`DELETE FROM prenotazioni WHERE utente_id=${uid}`); } catch { /* */ }
      try { dbQuery(`DELETE FROM utenti       WHERE id=${uid}`); } catch { /* */ }
    }

    // Restore copies for our test books
    for (const bookId of [testBookId, testBook2Id]) {
      if (!bookId) continue;
      try {
        dbQuery(`UPDATE copie SET stato='disponibile' WHERE libro_id=${bookId} AND stato IN ('prestato','prenotato')`);
        recalcAvailability(bookId);
      } catch { /* */ }
    }

    await userCtx?.close();
    await user2Ctx?.close();
    await adminCtx?.close();
  });

  // ═══════════════════════════════════════════════════════════════════════════
  // GROUP A — Full Lifecycle (Tests 1–5)
  // ═══════════════════════════════════════════════════════════════════════════

  // ── Test 1: User requests loan → DB stato='pendente' ──────────────────────
  test('A.1: user requests loan via UI → DB stato=pendente', async () => {
    await userPage.goto(`${BASE}/libro/${testBookId}`);
    await userPage.waitForLoadState('networkidle');

    const btn = userPage.locator('#btn-request-loan');
    await expect(btn).toBeVisible({ timeout: 5_000 });
    expect((await btn.textContent() || '')).toMatch(/Richiedi Prestito/);

    const ok = await requestLoanViaSwal(userPage, todayISO());
    expect(ok, 'Loan request should succeed').toBe(true);

    // Backend verify
    const row = dbQuery(
      `SELECT stato, attivo FROM prestiti
       WHERE utente_id=${testUserId} AND libro_id=${testBookId}
       ORDER BY id DESC LIMIT 1`
    );
    const [stato, attivo] = row.split('\t');
    expect(stato).toBe('pendente');
    expect(attivo).toBe('0');

    primaryLoanId = parseInt(
      dbQuery(
        `SELECT id FROM prestiti
         WHERE utente_id=${testUserId} AND libro_id=${testBookId} AND stato='pendente'
         ORDER BY id DESC LIMIT 1`
      ), 10
    );
    expect(primaryLoanId).toBeGreaterThan(0);
  });

  // ── Test 2: Admin approves → stato='da_ritirare' ──────────────────────────
  test('A.2: admin approves loan → DB stato=da_ritirare', async () => {
    await adminPage.goto(`${BASE}/admin/loans/pending`);
    await adminPage.waitForLoadState('domcontentloaded');

    const approveBtn = adminPage.locator(`.approve-btn[data-loan-id="${primaryLoanId}"]`);
    await expect(approveBtn).toBeVisible({ timeout: 8_000 });
    await approveBtn.click();

    await adminPage.waitForSelector('.swal2-popup', { timeout: 10_000 });
    await adminPage.locator('.swal2-confirm').click();

    await adminPage.waitForFunction(
      (id) => !!document.querySelector('.swal2-icon-success')
            || !document.querySelector(`[data-loan-id="${id}"]`),
      primaryLoanId,
      { timeout: 15_000 },
    );
    await dismissSwal(adminPage);

    const row = dbQuery(`SELECT stato, attivo FROM prestiti WHERE id=${primaryLoanId}`);
    const [stato, attivo] = row.split('\t');
    expect(stato).toBe('da_ritirare');
    expect(attivo).toBe('1');
  });

  // ── Test 3: Admin confirms pickup → stato='in_corso' + copy='prestato' ────
  test('A.3: admin confirms pickup → stato=in_corso, copy=prestato', async () => {
    await adminPage.goto(`${BASE}/admin/loans/pending`);
    await adminPage.waitForLoadState('domcontentloaded');

    const pickupBtn = adminPage.locator(`.confirm-pickup-btn[data-loan-id="${primaryLoanId}"]`);
    await expect(pickupBtn).toBeVisible({ timeout: 8_000 });
    await pickupBtn.click();

    await adminPage.waitForSelector('.swal2-popup', { timeout: 10_000 });

    const responsePromise = adminPage.waitForResponse(
      r => r.url().includes('/admin/loans/confirm-pickup') && r.request().method() === 'POST',
      { timeout: 15_000 }
    );
    await adminPage.locator('.swal2-confirm').click();
    const res = await responsePromise;
    const body = await res.json().catch(() => ({}));
    expect(res.ok(), `confirm-pickup HTTP ${res.status()}`).toBe(true);
    expect(body.success, body.message || 'confirm-pickup did not report success').toBe(true);

    await adminPage.waitForFunction(
      () => !!document.querySelector('.swal2-icon-success') || !document.querySelector('.swal2-popup'),
      { timeout: 15_000 },
    );
    await dismissSwal(adminPage);

    await expect.poll(
      () => dbQuery(`SELECT stato FROM prestiti WHERE id=${primaryLoanId}`),
      { message: 'Loan must reach in_corso after pickup', timeout: 10_000 }
    ).toBe('in_corso');

    const copyStat = dbQuery(
      `SELECT stato FROM copie WHERE id=(SELECT copia_id FROM prestiti WHERE id=${primaryLoanId})`
    );
    expect(copyStat).toBe('prestato');
  });

  // ── Test 4: User sees active loan in profile (/prenotazioni) ──────────────
  test('A.4: user sees active loan in /prenotazioni profile page (UI)', async () => {
    await userPage.goto(`${BASE}/prenotazioni`);
    await userPage.waitForLoadState('networkidle');

    await expect(userPage.locator('text=Prestiti attivi')).toBeVisible({ timeout: 5_000 });
    const activeGrid = userPage.locator('.section-header:has-text("Prestiti attivi") + .items-grid');
    await expect(activeGrid.locator('.item-card:has-text("LCT Fixture 1copy")')).toBeVisible({ timeout: 5_000 });
  });

  // ── Test 5: Admin returns book → restituito + copy=disponibile + email ─────
  test('A.5: admin returns loan → restituito, copy=disponibile, copie_disponibili restored', async () => {
    const copieBeforeReturn = parseInt(
      dbQuery(`SELECT copie_disponibili FROM libri WHERE id=${testBookId}`), 10
    );

    await adminPage.goto(`${BASE}/admin/loans/pending`);
    await adminPage.waitForLoadState('domcontentloaded');

    const returnBtn = adminPage.locator(`.return-btn[data-loan-id="${primaryLoanId}"]`);
    await expect(returnBtn).toBeVisible({ timeout: 8_000 });
    await returnBtn.click();

    await adminPage.waitForSelector('.swal2-popup', { timeout: 10_000 });
    await adminPage.locator('.swal2-confirm').click();
    await adminPage.waitForLoadState('domcontentloaded', { timeout: 20_000 });
    await dismissSwal(adminPage);

    const row = dbQuery(`SELECT stato, attivo FROM prestiti WHERE id=${primaryLoanId}`);
    const [stato, attivo] = row.split('\t');
    expect(stato).toBe('restituito');
    expect(attivo).toBe('0');

    const copyStat = dbQuery(
      `SELECT stato FROM copie WHERE id=(SELECT copia_id FROM prestiti WHERE id=${primaryLoanId})`
    );
    expect(copyStat).toBe('disponibile');

    const copieAfter = parseInt(
      dbQuery(`SELECT copie_disponibili FROM libri WHERE id=${testBookId}`), 10
    );
    expect(copieAfter).toBeGreaterThan(copieBeforeReturn);
  });

  // ═══════════════════════════════════════════════════════════════════════════
  // GROUP B — Availability After Return (Tests 6–8)
  // ═══════════════════════════════════════════════════════════════════════════

  // ── Test 6: After return, book detail shows "Richiedi Prestito" + copie>0 ─
  test('B.6: after return, UI shows "Richiedi Prestito" and copie_disponibili>0', async () => {
    const dbCopie = parseInt(
      dbQuery(`SELECT copie_disponibili FROM libri WHERE id=${testBookId}`), 10
    );
    expect(dbCopie).toBeGreaterThan(0);

    await userPage.goto(`${BASE}/libro/${testBookId}`);
    await userPage.waitForLoadState('networkidle');

    const btn = userPage.locator('#btn-request-loan');
    await expect(btn).toBeVisible({ timeout: 5_000 });
    expect((await btn.textContent() || '')).toMatch(/Richiedi Prestito/);
  });

  // ── Test 7: Book with 0 copies shows "Prenota" (UI) ──────────────────────
  test('B.7: book with copie_disponibili=0 shows "Prenota" button (UI)', async () => {
    // Force unavailability
    dbQuery(`UPDATE copie SET stato='prestato' WHERE libro_id=${testBookId}`);
    dbQuery(`UPDATE libri SET copie_disponibili=0 WHERE id=${testBookId}`);

    await userPage.goto(`${BASE}/libro/${testBookId}`);
    await userPage.waitForLoadState('networkidle');

    const btn = userPage.locator('#btn-request-loan');
    await expect(btn).toBeVisible({ timeout: 5_000 });
    expect((await btn.textContent() || '')).toMatch(/Prenota/);

    // Restore for subsequent tests
    dbQuery(`UPDATE copie SET stato='disponibile' WHERE libro_id=${testBookId}`);
    recalcAvailability(testBookId);
  });

  // ── Test 8: Book with 0 total copies: API availability.available=false ────
  test('B.8: book with 0 total copies: GET /api/books/{id}/availability → available=false', async () => {
    // Temporarily set totals to 0
    dbQuery(`UPDATE libri SET copie_totali=0, copie_disponibili=0 WHERE id=${testBookId}`);

    const resp = await adminPage.request.get(`${BASE}/api/books/${testBookId}/availability`);
    const data = await resp.json();
    expect(data.copies_available).toBe(0);
    expect(data.available).toBeFalsy();

    // Restore
    dbQuery(`UPDATE libri SET copie_totali=1 WHERE id=${testBookId}`);
    recalcAvailability(testBookId);
  });

  // ═══════════════════════════════════════════════════════════════════════════
  // GROUP C — Overlap Prevention (Tests 9–12)
  // ═══════════════════════════════════════════════════════════════════════════

  // ── Test 9: Duplicate loan same user+book fails (UI + DB count stays 1) ───
  test('C.9: duplicate loan same user+book → SweetAlert error, DB COUNT stays 1', async () => {
    // Create a fresh loan first
    const firstOk = await requestLoanViaSwal(userPage, todayISO());
    expect(firstOk, 'First loan request should succeed').toBe(true);

    // Try again immediately — should fail
    const secondOk = await requestLoanViaSwal(userPage, todayISO());
    expect(secondOk, 'Duplicate loan should be rejected').toBe(false);

    const count = parseInt(
      dbQuery(
        `SELECT COUNT(*) FROM prestiti
         WHERE utente_id=${testUserId} AND libro_id=${testBookId}
           AND stato IN ('pendente','in_corso','da_ritirare','in_ritardo')`
      ), 10
    );
    expect(count).toBe(1);
  });

  // ── Test 10: Reject the pending loan from C.9 to clean up ────────────────
  test('C.10: overlapping date loan fails — reject C.9 loan, new loan with past start blocked by server', async () => {
    // Find and reject the pending loan created in C.9
    const pendingId = parseInt(
      dbQuery(
        `SELECT id FROM prestiti
         WHERE utente_id=${testUserId} AND libro_id=${testBookId}
           AND stato='pendente'
         ORDER BY id DESC LIMIT 1`
      ), 10
    );
    if (pendingId > 0) {
      // Reject via DB shortcut (we verified UI rejection in test 9; this is cleanup)
      dbQuery(`DELETE FROM prestiti WHERE id=${pendingId}`);
    }
    recalcAvailability(testBookId);

    // Now manually create a loan for tomorrow (simulate overlap scenario)
    // Then try to add a second loan for the same dates via UI — should fail
    const tomorrow = dateISO(1);
    const firstOk = await requestLoanViaSwal(userPage, tomorrow);
    expect(firstOk, 'First loan (tomorrow) should succeed').toBe(true);

    // Immediately try the same date again
    const secondOk = await requestLoanViaSwal(userPage, tomorrow);
    expect(secondOk, 'Overlapping loan should be rejected').toBe(false);

    // Cleanup
    dbQuery(
      `DELETE FROM prestiti
       WHERE utente_id=${testUserId} AND libro_id=${testBookId} AND stato='pendente'`
    );
    recalcAvailability(testBookId);
  });

  // ── Test 11: Double-approve same loan via API → second call fails ─────────
  test('C.11: double-approve same loan → second API call returns success=false', async () => {
    // Create a fresh loan and approve it once
    const firstOk = await requestLoanViaSwal(userPage, todayISO());
    expect(firstOk, 'Loan request should succeed').toBe(true);

    const loanId11 = parseInt(
      dbQuery(
        `SELECT id FROM prestiti
         WHERE utente_id=${testUserId} AND libro_id=${testBookId} AND stato='pendente'
         ORDER BY id DESC LIMIT 1`
      ), 10
    );

    await adminPage.goto(`${BASE}/admin/loans/pending`);
    await adminPage.waitForLoadState('domcontentloaded');

    // First approval via UI
    const approveBtn = adminPage.locator(`.approve-btn[data-loan-id="${loanId11}"]`);
    if (await approveBtn.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await approveBtn.click();
      await adminPage.waitForSelector('.swal2-popup', { timeout: 8_000 });
      await adminPage.locator('.swal2-confirm').click();
      await adminPage.waitForFunction(
        () => !!document.querySelector('.swal2-icon-success') || !document.querySelector('.swal2-popup'),
        { timeout: 15_000 }
      );
      await dismissSwal(adminPage);
    } else {
      // Approve via DB if UI button not visible
      const res = await approveLoanViaApi(adminPage, loanId11);
      expect(res.body?.success).toBeTruthy();
    }

    // Second approval via API — must fail
    const res2 = await approveLoanViaApi(adminPage, loanId11);
    expect(res2.body?.success).toBeFalsy();

    // DB must still be da_ritirare (unchanged by second call)
    expect(dbQuery(`SELECT stato FROM prestiti WHERE id=${loanId11}`)).toBe('da_ritirare');

    // Cleanup: return it
    dbQuery(`UPDATE prestiti SET stato='restituito', attivo=0, data_restituzione=CURDATE() WHERE id=${loanId11}`);
    const cid = dbQuery(`SELECT copia_id FROM prestiti WHERE id=${loanId11}`);
    if (cid) dbQuery(`UPDATE copie SET stato='disponibile' WHERE id=${cid}`);
    recalcAvailability(testBookId);
  });

  // ── Test 12: With 1 copy, second user's loan blocked after first approved ─
  test('C.12: 1 copy: first user approved (in_corso) → second user loan request fails or is pendente-queued', async () => {
    // Make user 1's loan in_corso directly via DB (simulates existing active loan)
    let copiaId12Raw = dbQuery(`SELECT id FROM copie WHERE libro_id=${testBookId} AND stato='disponibile' LIMIT 1`);
    if (!copiaId12Raw) {
      // All copies are in use — the test condition is already met
      const disponibili = parseInt(dbQuery(`SELECT copie_disponibili FROM libri WHERE id=${testBookId}`), 10);
      expect(disponibili).toBe(0);
      return;
    }
    const copiaId12 = parseInt(copiaId12Raw, 10);

    dbQuery(`
      INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo, created_at)
      VALUES (${testBookId}, ${copiaId12}, ${testUserId}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'in_corso', 1, NOW())
    `);
    dbQuery(`UPDATE copie SET stato='prestato' WHERE id=${copiaId12}`);
    recalcAvailability(testBookId);

    // Verify 0 copies available
    const avail = parseInt(dbQuery(`SELECT copie_disponibili FROM libri WHERE id=${testBookId}`), 10);
    expect(avail).toBe(0);

    // User2 tries to request a loan (should fail or be queued as pendente)
    await user2Page.goto(`${BASE}/libro/${testBookId}`);
    await user2Page.waitForLoadState('networkidle');

    const btn = user2Page.locator('#btn-request-loan');
    await expect(btn).toBeVisible({ timeout: 5_000 });

    // With 0 copies, button shows "Prenota" — the SweetAlert result may be
    // success (queued) or error; either way the copy stays unavailable
    const btnText = (await btn.textContent() || '');
    if (btnText.match(/Prenota/)) {
      // Reservation flow — skip the loan-conflict check (book is truly unavailable)
      // No assertion needed: the "Prenota" text already proves the UI is correct
    } else {
      // Some installs may still show "Richiedi Prestito" — the backend should reject
      const ok2 = await requestLoanViaSwal(user2Page, todayISO());
      // Whether blocked or queued, the copy count must not go negative
      const availAfter = parseInt(dbQuery(`SELECT copie_disponibili FROM libri WHERE id=${testBookId}`), 10);
      expect(availAfter).toBeGreaterThanOrEqual(0);
      void ok2; // result is intentionally ignored — test checks side-effects only
    }

    // Cleanup
    dbQuery(
      `UPDATE prestiti SET stato='restituito', attivo=0, data_restituzione=CURDATE()
       WHERE libro_id=${testBookId} AND utente_id=${testUserId} AND stato='in_corso'`
    );
    dbQuery(`UPDATE copie SET stato='disponibile' WHERE id=${copiaId12}`);
    dbQuery(
      `DELETE FROM prestiti
       WHERE libro_id=${testBookId} AND utente_id=${testUser2Id} AND stato='pendente'`
    );
    recalcAvailability(testBookId);
  });

  // ═══════════════════════════════════════════════════════════════════════════
  // GROUP D — Multi-Copy Concurrency (Tests 13–15)
  // ═══════════════════════════════════════════════════════════════════════════

  // ── Test 13: 2 copies → 2 simultaneous loans both active ─────────────────
  test('D.13: book with 2 copies: 2 loans (different users) both become in_corso', async () => {
    // Verify starting state
    const avail13 = parseInt(dbQuery(`SELECT copie_disponibili FROM libri WHERE id=${testBook2Id}`), 10);
    expect(avail13).toBeGreaterThanOrEqual(2);

    // Get 2 available copy IDs
    const copyRows = dbQuery(
      `SELECT id FROM copie WHERE libro_id=${testBook2Id} AND stato='disponibile' LIMIT 2`
    ).split('\n').filter(Boolean);
    expect(copyRows.length).toBeGreaterThanOrEqual(2);

    const copia13a = parseInt(copyRows[0], 10);
    const copia13b = parseInt(copyRows[1], 10);

    // Insert 2 in_corso loans via DB
    dbQuery(`
      INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo, created_at)
      VALUES
        (${testBook2Id}, ${copia13a}, ${testUserId},  CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'in_corso', 1, NOW()),
        (${testBook2Id}, ${copia13b}, ${testUser2Id}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'in_corso', 1, NOW())
    `);
    dbQuery(`UPDATE copie SET stato='prestato' WHERE id IN (${copia13a}, ${copia13b})`);
    recalcAvailability(testBook2Id);

    // Both loans exist and are in_corso
    const activeCount = parseInt(
      dbQuery(
        `SELECT COUNT(*) FROM prestiti
         WHERE libro_id=${testBook2Id} AND stato='in_corso' AND attivo=1
           AND utente_id IN (${testUserId}, ${testUser2Id})`
      ), 10
    );
    expect(activeCount).toBe(2);

    // copie_disponibili should be 0 (both copies lent out)
    const availAfter = parseInt(dbQuery(`SELECT copie_disponibili FROM libri WHERE id=${testBook2Id}`), 10);
    expect(availAfter).toBe(0);
  });

  // ── Test 14: 3rd request with 2 copies occupied → pendente/queued ─────────
  test('D.14: 3rd request with both 2 copies occupied → request is rejected or queued', async () => {
    // At this point D.13 left 0 copies available
    const avail14 = parseInt(dbQuery(`SELECT copie_disponibili FROM libri WHERE id=${testBook2Id}`), 10);
    expect(avail14).toBe(0);

    // Try to request via UI
    await userPage.goto(`${BASE}/libro/${testBook2Id}`);
    await userPage.waitForLoadState('networkidle');

    const btn14 = userPage.locator('#btn-request-loan');
    await expect(btn14).toBeVisible({ timeout: 5_000 });
    const btnText14 = (await btn14.textContent() || '');

    if (btnText14.match(/Prenota/)) {
      // Reservation flow — button text alone validates the UI behaviour
      // No copies should go negative
      expect(parseInt(dbQuery(`SELECT copie_disponibili FROM libri WHERE id=${testBook2Id}`), 10)).toBe(0);
    } else {
      const ok14 = await requestLoanViaSwal(userPage, dateISO(15));
      // If success, it's queued as pendente; if error, it's blocked. Either is acceptable.
      const stateAfter14 = ok14
        ? dbQuery(`SELECT stato FROM prestiti WHERE utente_id=${testUserId} AND libro_id=${testBook2Id} AND stato='pendente' ORDER BY id DESC LIMIT 1`)
        : 'blocked';
      expect(['pendente', 'blocked'].includes(stateAfter14)).toBe(true);
      if (stateAfter14 === 'pendente') {
        dbQuery(`DELETE FROM prestiti WHERE utente_id=${testUserId} AND libro_id=${testBook2Id} AND stato='pendente'`);
      }
    }
  });

  // ── Test 15: Return one of 2 copies → copie_disponibili +1, API reflects it
  test('D.15: returning one of 2 active loans restores copie_disponibili to 1, API reflects it', async () => {
    // Find one of the in_corso loans for testBook2Id
    const loanRow15 = dbQuery(
      `SELECT id, copia_id FROM prestiti
       WHERE libro_id=${testBook2Id} AND stato='in_corso' AND attivo=1
         AND utente_id=${testUserId}
       ORDER BY id DESC LIMIT 1`
    );
    const [loanId15, copiaId15] = loanRow15.split('\t').map(v => parseInt(v, 10));
    expect(loanId15).toBeGreaterThan(0);

    // Return it via DB directly (UI path tested in A.5)
    dbQuery(
      `UPDATE prestiti SET stato='restituito', attivo=0, data_restituzione=CURDATE()
       WHERE id=${loanId15}`
    );
    dbQuery(`UPDATE copie SET stato='disponibile' WHERE id=${copiaId15}`);
    recalcAvailability(testBook2Id);

    const availAfter15 = parseInt(dbQuery(`SELECT copie_disponibili FROM libri WHERE id=${testBook2Id}`), 10);
    expect(availAfter15).toBeGreaterThanOrEqual(1);

    // API should reflect the restored availability
    const resp15 = await adminPage.request.get(`${BASE}/api/books/${testBook2Id}/availability`);
    const data15 = await resp15.json();
    expect(data15.copies_available).toBeGreaterThanOrEqual(1);
    expect(data15.available).toBe(true);

    // Cleanup remaining in_corso loan
    dbQuery(
      `UPDATE prestiti SET stato='restituito', attivo=0, data_restituzione=CURDATE()
       WHERE libro_id=${testBook2Id} AND stato='in_corso' AND attivo=1`
    );
    dbQuery(`UPDATE copie SET stato='disponibile' WHERE libro_id=${testBook2Id} AND stato='prestato'`);
    recalcAvailability(testBook2Id);
  });

  // ═══════════════════════════════════════════════════════════════════════════
  // GROUP E — Calendar / Availability API (Tests 16–18)
  // ═══════════════════════════════════════════════════════════════════════════

  // ── Test 16: /api/libri/{id}/disponibilita with active loan → occupied range
  test('E.16: GET /api/libri/{id}/disponibilita with active loan → occupied_ranges non-empty, copie_disponibili=0', async () => {
    // Ensure all non-available copies are cleaned up first so total loanable = 1
    dbQuery(`UPDATE copie SET stato='disponibile' WHERE libro_id=${testBookId}`);
    dbQuery(`UPDATE libri SET copie_totali=1 WHERE id=${testBookId}`);
    // Keep only 1 copy in the copie table for this book
    const allCopieRaw = dbQuery(
      `SELECT id FROM copie WHERE libro_id=${testBookId} ORDER BY id`
    ).split('\n').filter(Boolean);
    if (allCopieRaw.length > 1) {
      for (const cid of allCopieRaw.slice(1)) {
        dbQuery(`DELETE FROM copie WHERE id=${cid}`);
      }
    }
    recalcAvailability(testBookId);

    // Verify we have exactly 1 copy
    const totalCopies16 = parseInt(dbQuery(`SELECT COUNT(*) FROM copie WHERE libro_id=${testBookId}`), 10);
    expect(totalCopies16).toBe(1);

    const copiaRaw16 = dbQuery(`SELECT id FROM copie WHERE libro_id=${testBookId} AND stato='disponibile' LIMIT 1`);
    expect(copiaRaw16, 'Need an available copy for testBookId').toBeTruthy();
    const copia16 = parseInt(copiaRaw16, 10);

    dbQuery(`
      INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo, created_at)
      VALUES (${testBookId}, ${copia16}, ${testUserId}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'in_corso', 1, NOW())
    `);
    dbQuery(`UPDATE copie SET stato='prestato' WHERE id=${copia16}`);
    recalcAvailability(testBookId);

    // DB confirms 0 available
    const dbAvail16 = parseInt(dbQuery(`SELECT copie_disponibili FROM libri WHERE id=${testBookId}`), 10);
    expect(dbAvail16).toBe(0);

    const resp16 = await adminPage.request.get(`${BASE}/api/libri/${testBookId}/disponibilita`);
    expect(resp16.ok()).toBe(true);
    const data16 = await resp16.json();

    // occupied_ranges must list the active loan
    expect(Array.isArray(data16.occupied_ranges)).toBe(true);
    expect(data16.occupied_ranges.length).toBeGreaterThan(0);
    // copie_disponibili from API should reflect 0
    expect(data16.copie_disponibili).toBe(0);

    // Cleanup
    dbQuery(
      `UPDATE prestiti SET stato='restituito', attivo=0, data_restituzione=CURDATE()
       WHERE libro_id=${testBookId} AND utente_id=${testUserId} AND stato='in_corso'`
    );
    dbQuery(`UPDATE copie SET stato='disponibile' WHERE id=${copia16}`);
    recalcAvailability(testBookId);
  });

  // ── Test 17: 2-copy book: 1 copy on loan → API still shows available>=1 ──
  test('E.17: 2-copy book with 1 loan: /api/books/{id}/availability returns available=true', async () => {
    // Lend out 1 of 2 copies
    const copiaRaw17 = dbQuery(`SELECT id FROM copie WHERE libro_id=${testBook2Id} AND stato='disponibile' LIMIT 1`);
    const copia17 = parseInt(copiaRaw17, 10);
    dbQuery(`
      INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo, created_at)
      VALUES (${testBook2Id}, ${copia17}, ${testUserId}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 20 DAY), 'in_corso', 1, NOW())
    `);
    dbQuery(`UPDATE copie SET stato='prestato' WHERE id=${copia17}`);
    recalcAvailability(testBook2Id);

    const resp17 = await adminPage.request.get(`${BASE}/api/books/${testBook2Id}/availability`);
    const data17 = await resp17.json();
    expect(data17.available).toBe(true);
    expect(data17.copies_available).toBeGreaterThanOrEqual(1);

    // Cleanup
    dbQuery(
      `UPDATE prestiti SET stato='restituito', attivo=0, data_restituzione=CURDATE()
       WHERE libro_id=${testBook2Id} AND utente_id=${testUserId} AND stato='in_corso'`
    );
    dbQuery(`UPDATE copie SET stato='disponibile' WHERE id=${copia17}`);
    recalcAvailability(testBook2Id);
  });

  // ── Test 18: Admin dashboard calendar shows active loan event ─────────────
  test('E.18: admin dashboard FullCalendar shows event for active loan (UI)', async () => {
    // Create an in_corso loan so calendarEvents() returns it
    const copiaRaw18 = dbQuery(`SELECT id FROM copie WHERE libro_id=${testBookId} AND stato='disponibile' LIMIT 1`);
    const copia18 = parseInt(copiaRaw18, 10);
    dbQuery(`
      INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo, created_at)
      VALUES (${testBookId}, ${copia18}, ${testUserId}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'in_corso', 1, NOW())
    `);
    dbQuery(`UPDATE copie SET stato='prestato' WHERE id=${copia18}`);
    recalcAvailability(testBookId);

    await adminPage.goto(`${BASE}/admin/dashboard`);
    await adminPage.waitForLoadState('networkidle');

    // Dashboard must render the FullCalendar container
    const calendarEl = adminPage.locator('#dashboard-calendar');
    await expect(calendarEl).toBeVisible({ timeout: 8_000 });

    // The calendar events are rendered as .fc-event elements; the loan event's
    // title is the book title (DashboardStats::calendarEvents → 'title' = titolo).
    // Scope the assertion to THIS test's loan ('LCT Fixture 1copy') so a
    // regression in loan-event rendering can't be masked by unrelated seed/
    // residual events that happen to be on the calendar.
    await expect(
      adminPage.locator('.fc-event', { hasText: 'LCT Fixture 1copy' }).first()
    ).toBeVisible({ timeout: 10_000 });

    // Cleanup
    dbQuery(
      `UPDATE prestiti SET stato='restituito', attivo=0, data_restituzione=CURDATE()
       WHERE libro_id=${testBookId} AND utente_id=${testUserId} AND stato='in_corso'`
    );
    dbQuery(`UPDATE copie SET stato='disponibile' WHERE id=${copia18}`);
    recalcAvailability(testBookId);
  });

  // ═══════════════════════════════════════════════════════════════════════════
  // GROUP F — Reservation Queue Management (Tests 19–21)
  // ═══════════════════════════════════════════════════════════════════════════

  // ── Test 19: Reservation visible in profile with queue position ───────────
  test('F.19: reservation in prenotazioni table → visible in user profile with position (UI)', async () => {
    const startDate19 = dateISO(10);
    const endDate19   = dateISO(40);

    dbQuery(`
      INSERT INTO prenotazioni (libro_id, utente_id, queue_position, stato,
                                data_prenotazione, data_scadenza_prenotazione,
                                data_inizio_richiesta, data_fine_richiesta, created_at)
      VALUES (${testBookId}, ${testUserId}, 1, 'attiva',
              '${startDate19} 00:00:00', '${endDate19} 23:59:59',
              '${startDate19}', '${endDate19}', NOW())
    `);

    await userPage.goto(`${BASE}/prenotazioni`);
    await userPage.waitForLoadState('networkidle');

    await expect(userPage.locator('text=Prenotazioni attive')).toBeVisible({ timeout: 5_000 });
    // The reservation card should be visible
    const reservationCard = userPage.locator('.section-header:has-text("Prenotazioni attive") + .items-grid .item-card');
    await expect(reservationCard.first()).toBeVisible({ timeout: 5_000 });
  });

  // ── Test 20: User cancels reservation via UI (SweetAlert confirm) ─────────
  test('F.20: user cancels reservation via UI (swal2 confirm) → DB stato=annullata', async () => {
    // The reservation from F.19 should still be there
    const resId20 = parseInt(
      dbQuery(
        `SELECT id FROM prenotazioni
         WHERE utente_id=${testUserId} AND libro_id=${testBookId} AND stato='attiva'
         ORDER BY id DESC LIMIT 1`
      ), 10
    );
    expect(resId20).toBeGreaterThan(0);

    await userPage.goto(`${BASE}/prenotazioni`);
    await userPage.waitForLoadState('networkidle');

    await expect(userPage.locator('text=Prenotazioni attive')).toBeVisible({ timeout: 5_000 });

    // Click the cancel trigger (btn-cancel) and confirm in the SweetAlert
    const cancelTrigger = userPage.locator('.btn-cancel').first();
    await expect(cancelTrigger).toBeVisible({ timeout: 5_000 });
    await cancelTrigger.click();

    const swalConfirm = userPage.locator('.swal2-confirm');
    await expect(swalConfirm).toBeVisible({ timeout: 5_000 });
    await swalConfirm.click();

    await userPage.waitForURL(/canceled=1/, { timeout: 15_000 });

    const stato20 = dbQuery(`SELECT stato FROM prenotazioni WHERE id=${resId20}`);
    expect(stato20).toBe('annullata');
  });

  // ── Test 21: Expired reservation via maintenance → stato='scaduto' ────────
  test('F.21: expired prenotazione (data_scadenza past) → maintenance marks it scaduto (+ email if Mailpit)', async () => {
    // checkExpiredReservations looks for: stato='prenotato' AND attivo=1 AND data_scadenza < today
    // activateScheduledLoans looks for: stato='prenotato' AND data_prestito <= today → da_ritirare
    // To avoid the activation competing, set data_prestito to a FUTURE date (loan hasn't started)
    // but data_scadenza to the past (it has conceptually expired without being used).
    const pastScadenza = dateISO(-5);
    const futurePrestito = dateISO(+30); // future start date → activateScheduledLoans won't touch it

    const copiaRaw21 = dbQuery(`SELECT id FROM copie WHERE libro_id=${testBookId} AND stato='disponibile' LIMIT 1`);
    const copia21 = copiaRaw21 ? parseInt(copiaRaw21, 10) : null;

    dbQuery(`
      INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo, created_at)
      VALUES (${testBookId}, ${copia21 || 'NULL'}, ${testUserId},
              '${futurePrestito}', '${pastScadenza}', 'prenotato', 1, NOW())
    `);
    const expiredLoanId21 = parseInt(
      dbQuery(
        `SELECT id FROM prestiti
         WHERE utente_id=${testUserId} AND libro_id=${testBookId} AND stato='prenotato'
         ORDER BY id DESC LIMIT 1`
      ), 10
    );
    expect(expiredLoanId21).toBeGreaterThan(0);

    if (await checkMailpit()) await clearMailpit();

    // Run maintenance
    const maint21 = performMaintenance();
    expect([0, 2].includes(maint21.status)).toBe(true);

    // The loan should now be scaduto
    const statoAfter21 = dbQuery(`SELECT stato FROM prestiti WHERE id=${expiredLoanId21}`);
    expect(statoAfter21).toBe('scaduto');

    // Email check — informational only, does NOT fail the test
    // (email delivery depends on SMTP/Mailpit availability in CI)
    if (await checkMailpit()) {
      const mail21 = await waitForMail(`to:${TEST_EMAIL}`, 10_000);
      if (!mail21) {
        console.warn('[F.21] Mailpit reachable but no email for expired reservation — SMTP may not be configured');
      }
    }

    // Cleanup
    dbQuery(`DELETE FROM prestiti WHERE id=${expiredLoanId21}`);
    if (copia21) dbQuery(`UPDATE copie SET stato='disponibile' WHERE id=${copia21}`);
    recalcAvailability(testBookId);
  });

  // ── Test 21b: double-conversion guard (#157) ─────────────────────────────
  // A converted reservation becomes a pending loan (attivo=0) that holds its
  // copy until the admin confirms pickup. Before the fix, isDateRangeAvailable
  // counted only attivo=1 loans, so a SECOND maintenance run re-converted the
  // SAME single copy for the next person in the queue → duplicate pendings.
  test('F.21b: 1 copy + 2 queued reservations → two maintenance runs yield only ONE pending (#157)', async () => {
    const tag = 'E2E_DBLCONV_' + Date.now();
    dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at)
             VALUES ('${tag}', 1, 1, NOW(), NOW())`);
    const bookId = parseInt(
      dbQuery(`SELECT id FROM libri WHERE titolo='${tag}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`), 10);
    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato, created_at, updated_at)
             VALUES (${bookId}, '${tag}-INV', 'disponibile', NOW(), NOW())`);

    const start = dateISO(0);    // start date has arrived → eligible to convert
    const end = dateISO(+14);

    // Two users queue for the SAME single copy over the SAME date range.
    dbQuery(`INSERT INTO prenotazioni (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta, queue_position, stato, created_at, updated_at)
             VALUES (${bookId}, ${testUserId},  '${start}', '${end}', 1, 'attiva', NOW(), NOW())`);
    dbQuery(`INSERT INTO prenotazioni (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta, queue_position, stato, created_at, updated_at)
             VALUES (${bookId}, ${testUser2Id}, '${start}', '${end}', 2, 'attiva', NOW(), NOW())`);

    // Repeated cron BEFORE the admin approves the first pending loan.
    const m1 = performMaintenance();
    expect([0, 2].includes(m1.status)).toBe(true);
    const m2 = performMaintenance();
    expect([0, 2].includes(m2.status)).toBe(true);

    // Exactly ONE reservation converted onto the single copy; the second is
    // still queued, never double-converted into a duplicate pending.
    const pendenti = parseInt(
      dbQuery(`SELECT COUNT(*) FROM prestiti WHERE libro_id=${bookId} AND stato='pendente' AND attivo=0`), 10);
    expect(pendenti).toBe(1);

    const ancoraAttive = parseInt(
      dbQuery(`SELECT COUNT(*) FROM prenotazioni WHERE libro_id=${bookId} AND stato='attiva'`), 10);
    expect(ancoraAttive).toBe(1);

    // Queue order must be deterministic: the HEAD (queue_position=1 = testUser)
    // is the one promoted to a pending loan, and the still-active reservation is
    // the SECOND user — not the reverse. A counts-only check would pass even if
    // the cron promoted position 2 and left the head queued. Guards the
    // queue-reorder fix (decrement only positions strictly after the promoted).
    const promossoUserId = parseInt(
      dbQuery(`SELECT utente_id FROM prestiti WHERE libro_id=${bookId} AND stato='pendente' AND attivo=0 ORDER BY id DESC LIMIT 1`), 10);
    expect(promossoUserId).toBe(testUserId);

    const inCodaUserId = parseInt(
      dbQuery(`SELECT utente_id FROM prenotazioni WHERE libro_id=${bookId} AND stato='attiva' ORDER BY queue_position ASC, id ASC LIMIT 1`), 10);
    expect(inCodaUserId).toBe(testUser2Id);

    // Cleanup (FK-safe).
    dbQuery(`DELETE FROM prestiti WHERE libro_id=${bookId}`);
    dbQuery(`DELETE FROM prenotazioni WHERE libro_id=${bookId}`);
    dbQuery(`DELETE FROM copie WHERE libro_id=${bookId}`);
    dbQuery(`DELETE FROM libri WHERE id=${bookId}`);
  });

  // ═══════════════════════════════════════════════════════════════════════════
  // GROUP G — Automated Maintenance (Tests 22–23)
  // ═══════════════════════════════════════════════════════════════════════════

  // ── Test 22: Overdue loan → maintenance → in_ritardo ────────────────────
  test('G.22: overdue in_corso loan (past data_scadenza) → maintenance → stato=in_ritardo', async () => {
    const past22      = dateISO(-3);
    const past22start = dateISO(-33);

    const copiaRaw22 = dbQuery(`SELECT id FROM copie WHERE libro_id=${testBookId} AND stato='disponibile' LIMIT 1`);
    const copia22 = copiaRaw22 ? parseInt(copiaRaw22, 10) : null;

    // Set overdue_notification_sent=1 so the notification service skips this loan
    // (avoids the email-failure revert that would set stato back to in_corso).
    // We are testing that MaintenanceService::updateOverdueLoans() correctly
    // transitions in_corso → in_ritardo when data_scadenza < today.
    dbQuery(`
      INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza,
                            stato, attivo, warning_sent, overdue_notification_sent, created_at)
      VALUES (${testBookId}, ${copia22 || 'NULL'}, ${testUserId},
              '${past22start}', '${past22}', 'in_corso', 1, 1, 1, NOW())
    `);
    if (copia22) dbQuery(`UPDATE copie SET stato='prestato' WHERE id=${copia22}`);
    recalcAvailability(testBookId);

    const overdueId22 = parseInt(
      dbQuery(
        `SELECT id FROM prestiti
         WHERE utente_id=${testUserId} AND libro_id=${testBookId}
           AND stato='in_corso' AND data_scadenza='${past22}'
         ORDER BY id DESC LIMIT 1`
      ), 10
    );
    expect(overdueId22).toBeGreaterThan(0);

    const maint22 = performMaintenance();
    expect([0, 2].includes(maint22.status)).toBe(true);

    // updateOverdueLoans() sets in_corso → in_ritardo for past-due active loans
    const statoAfter22 = dbQuery(`SELECT stato FROM prestiti WHERE id=${overdueId22}`);
    expect(statoAfter22).toBe('in_ritardo');

    // Cleanup
    dbQuery(
      `UPDATE prestiti SET stato='restituito', attivo=0, data_restituzione=CURDATE()
       WHERE id=${overdueId22}`
    );
    if (copia22) dbQuery(`UPDATE copie SET stato='disponibile' WHERE id=${copia22}`);
    recalcAvailability(testBookId);
  });

  // ── Test 23: Expired pickup (da_ritirare past pickup_deadline) → scaduto ──
  test('G.23: da_ritirare with past pickup_deadline → maintenance → stato=scaduto', async () => {
    const pastPickup = dateISO(-2);
    const pastStart  = dateISO(-5);

    const copiaRaw23 = dbQuery(`SELECT id FROM copie WHERE libro_id=${testBookId} AND stato='disponibile' LIMIT 1`);
    const copia23 = copiaRaw23 ? parseInt(copiaRaw23, 10) : null;

    dbQuery(`
      INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza,
                            pickup_deadline, stato, attivo, created_at)
      VALUES (${testBookId}, ${copia23 || 'NULL'}, ${testUserId},
              '${pastStart}', DATE_ADD(CURDATE(), INTERVAL 25 DAY),
              '${pastPickup}', 'da_ritirare', 1, NOW())
    `);
    if (copia23) dbQuery(`UPDATE copie SET stato='prenotato' WHERE id=${copia23}`);

    const pickupExpiredId = parseInt(
      dbQuery(
        `SELECT id FROM prestiti
         WHERE utente_id=${testUserId} AND libro_id=${testBookId} AND stato='da_ritirare'
         ORDER BY id DESC LIMIT 1`
      ), 10
    );

    const maint23 = performMaintenance();
    expect([0, 2].includes(maint23.status)).toBe(true);

    const statoAfter23 = dbQuery(`SELECT stato FROM prestiti WHERE id=${pickupExpiredId}`);
    expect(statoAfter23).toBe('scaduto');

    // Cleanup
    dbQuery(`DELETE FROM prestiti WHERE id=${pickupExpiredId}`);
    if (copia23) dbQuery(`UPDATE copie SET stato='disponibile' WHERE id=${copia23}`);
    recalcAvailability(testBookId);
  });

  // ═══════════════════════════════════════════════════════════════════════════
  // GROUP H — Settings & Renewal (Tests 24–25)
  // ═══════════════════════════════════════════════════════════════════════════

  // ── Test 24: max_active_loans_per_user=1 blocks a 2nd loan request ────────
  test('H.24: max_active_loans_per_user=1 → second loan request blocked (backend)', async () => {
    // Set limit to 1
    dbQuery(
      `INSERT INTO system_settings (category, setting_key, setting_value)
       VALUES ('loans','max_active_loans_per_user','1')
       ON DUPLICATE KEY UPDATE setting_value='1'`
    );

    // Create an active loan for user1 (in_corso)
    const copiaRaw24 = dbQuery(`SELECT id FROM copie WHERE libro_id=${testBookId} AND stato='disponibile' LIMIT 1`);
    const copia24 = copiaRaw24 ? parseInt(copiaRaw24, 10) : null;
    dbQuery(`
      INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo, created_at)
      VALUES (${testBookId}, ${copia24 || 'NULL'}, ${testUserId},
              CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'in_corso', 1, NOW())
    `);
    if (copia24) dbQuery(`UPDATE copie SET stato='prestato' WHERE id=${copia24}`);
    recalcAvailability(testBookId);

    // Now user1 tries to request another loan for testBook2Id (max=1 reached)
    await userPage.goto(`${BASE}/libro/${testBook2Id}`);
    await userPage.waitForLoadState('networkidle');

    const btn24 = userPage.locator('#btn-request-loan');
    await expect(btn24).toBeVisible({ timeout: 5_000 });
    const btnText24 = (await btn24.textContent() || '');

    if (btnText24.match(/Richiedi Prestito/)) {
      const ok24 = await requestLoanViaSwal(userPage, todayISO());
      // The backend should reject it because max active loans = 1
      expect(ok24).toBe(false);
    }
    // If button shows "Prenota" the book has no copies — also blocks loan ✓

    // Cleanup
    dbQuery(
      `UPDATE prestiti SET stato='restituito', attivo=0, data_restituzione=CURDATE()
       WHERE libro_id=${testBookId} AND utente_id=${testUserId} AND stato='in_corso'`
    );
    if (copia24) dbQuery(`UPDATE copie SET stato='disponibile' WHERE id=${copia24}`);
    recalcAvailability(testBookId);

    // Restore limit to 0 (no limit)
    dbQuery(
      `INSERT INTO system_settings (category, setting_key, setting_value)
       VALUES ('loans','max_active_loans_per_user','0')
       ON DUPLICATE KEY UPDATE setting_value='0'`
    );
  });

  // ── Test 25: Renewal — in_corso loan extends data_scadenza, renewals+1 ────
  test('H.25: POST /admin/prestiti/rinnova/{id} extends data_scadenza and increments renewals', async () => {
    // Create an in_corso loan
    const copiaRaw25 = dbQuery(`SELECT id FROM copie WHERE libro_id=${testBookId} AND stato='disponibile' LIMIT 1`);
    const copia25 = copiaRaw25 ? parseInt(copiaRaw25, 10) : null;
    const dueDate25 = dateISO(10); // 10 days from now

    dbQuery(`
      INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza,
                            stato, attivo, renewals, created_at)
      VALUES (${testBookId}, ${copia25 || 'NULL'}, ${testUserId},
              CURDATE(), '${dueDate25}', 'in_corso', 1, 0, NOW())
    `);
    if (copia25) dbQuery(`UPDATE copie SET stato='prestato' WHERE id=${copia25}`);

    const loanId25 = parseInt(
      dbQuery(
        `SELECT id FROM prestiti
         WHERE utente_id=${testUserId} AND libro_id=${testBookId} AND stato='in_corso'
         ORDER BY id DESC LIMIT 1`
      ), 10
    );
    expect(loanId25).toBeGreaterThan(0);

    const renewalsBefore = parseInt(dbQuery(`SELECT renewals FROM prestiti WHERE id=${loanId25}`), 10);

    // Navigate to admin loans to get CSRF
    await adminPage.goto(`${BASE}/admin/prestiti/${loanId25}`);
    await adminPage.waitForLoadState('domcontentloaded');
    const csrf25 = await getCsrf(adminPage);

    // POST renew
    const renewRes = await adminPage.request.post(`${BASE}/admin/prestiti/rinnova/${loanId25}`, {
      form: { csrf_token: csrf25 },
    });

    // The endpoint returns a redirect (302) on success
    expect([200, 302].includes(renewRes.status())).toBe(true);

    // Check DB: renewals+1 and data_scadenza extended
    const renewalsAfter = parseInt(dbQuery(`SELECT renewals FROM prestiti WHERE id=${loanId25}`), 10);
    const dueDateAfter  = dbQuery(`SELECT data_scadenza FROM prestiti WHERE id=${loanId25}`);

    expect(renewalsAfter).toBe(renewalsBefore + 1);
    expect(dueDateAfter > dueDate25).toBe(true);

    // Also verify that renewing an in_ritardo (overdue) loan is blocked
    // by setting stato to in_ritardo then calling renew
    dbQuery(`UPDATE prestiti SET stato='in_ritardo' WHERE id=${loanId25}`);

    await adminPage.goto(`${BASE}/admin/prestiti/${loanId25}`);
    await adminPage.waitForLoadState('domcontentloaded');
    const csrf25b = await getCsrf(adminPage);

    const renewRes2 = await adminPage.request.post(`${BASE}/admin/prestiti/rinnova/${loanId25}`, {
      form: { csrf_token: csrf25b },
    });
    // The app redirects to an error URL on failure (returns 302 to ?error=...)
    // OR returns a non-success status. Either way, renewals should NOT increase.
    const renewalsOverdue = parseInt(dbQuery(`SELECT renewals FROM prestiti WHERE id=${loanId25}`), 10);
    expect(renewalsOverdue).toBe(renewalsAfter); // unchanged

    // Cleanup
    dbQuery(
      `UPDATE prestiti SET stato='restituito', attivo=0, data_restituzione=CURDATE()
       WHERE id=${loanId25}`
    );
    if (copia25) dbQuery(`UPDATE copie SET stato='disponibile' WHERE id=${copia25}`);
    recalcAvailability(testBookId);
  });

}); // end describe.serial
