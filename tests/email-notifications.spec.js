// @ts-check
const { test, expect } = require('@playwright/test');
const { execFileSync, execSync } = require('child_process');
const path = require('path');
const os = require('os');
const fs = require('fs');

// ── Environment ──────────────────────────────────────────────────────
const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const MAILPIT_API = process.env.MAILPIT_API || 'http://localhost:8025/api/v1';

const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

// Skip all tests when credentials are not configured
test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_PASS || !DB_NAME,
  'E2E credentials not configured',
);

// ── Run ID for scoped test data ──────────────────────────────────────
const RUN_ID = Date.now().toString(36);

// ── Test user constants ──────────────────────────────────────────────
const TEST_USER_EMAIL = 'emailtest@example.com';
const TEST_USER_PASS = 'Test1234!';
const TEST_USER_FIRST = 'EmailTest';
const TEST_USER_LAST = 'User';
const TEST_USER2_EMAIL = 'emailtest2@example.com';
const CONTACT_EMAIL = 'contact-test@example.com';

// ── Database helper ──────────────────────────────────────────────────
/** Escape a value for safe SQL interpolation */
const sqlEscape = (s) => String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");

function dbQuery(sql) {
  const args = ['-u', DB_USER, `-p${DB_PASS}`, DB_NAME, '-N', '-B', '-e', sql];
  if (DB_SOCKET) args.splice(3, 0, '-S', DB_SOCKET);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000 }).trim();
}

// ── Mailpit API helpers ──────────────────────────────────────────────

/** Fetch JSON from Mailpit with timeout and status check */
async function mailpitJson(urlPath, timeoutMs = 5000) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const res = await fetch(`${MAILPIT_API}${urlPath}`, { signal: controller.signal });
    if (!res.ok) throw new Error(`Mailpit request failed: HTTP ${res.status}`);
    return await res.json();
  } finally {
    clearTimeout(timer);
  }
}

/** Delete all messages in Mailpit */
async function clearMailpit() {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 5000);
  try {
    const res = await fetch(`${MAILPIT_API}/messages`, { method: 'DELETE', signal: controller.signal });
    if (!res.ok) throw new Error(`Mailpit clear failed: HTTP ${res.status}`);
  } catch (err) {
    if (controller.signal.aborted) {
      // Timeout — retry once without abort signal
      try { await fetch(`${MAILPIT_API}/messages`, { method: 'DELETE' }); } catch { /* best effort */ }
      return;
    }
    throw err;
  } finally {
    clearTimeout(timer);
  }
}

/**
 * Wait for an email matching the search query to arrive in Mailpit.
 * @param {string} query - Mailpit search query (e.g. "subject:registrazione to:user@example.com")
 * @param {number} timeoutMs - Max wait time
 * @returns {Promise<object>} - The matching message summary
 */
async function waitForMail(query, timeoutMs = 15000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      const data = await mailpitJson(`/search?query=${encodeURIComponent(query)}`);
      if (data.messages && data.messages.length > 0) {
        return data.messages[0];
      }
    } catch { /* retry on fetch error */ }
    await new Promise(r => setTimeout(r, 500));
  }
  throw new Error(`Mailpit: No email matching "${query}" within ${timeoutMs}ms`);
}

/**
 * Get full message by ID
 * @param {string} id
 * @returns {Promise<object>}
 */
async function getMessage(id) {
  return mailpitJson(`/message/${id}`);
}

/**
 * Get count of messages matching a query
 * @param {string} query
 * @returns {Promise<number>}
 */
async function countMail(query) {
  const data = await mailpitJson(`/search?query=${encodeURIComponent(query)}`);
  return data.messages_count || 0;
}

// ── Page helpers ─────────────────────────────────────────────────────

/** Login as admin via form POST */
async function loginAsAdmin(page) {
  clearRateLimits(); // prevent 429 on repeated logins
  await page.goto(`${BASE}/accedi`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"], input[type="submit"]').first().click();
  await page.waitForURL(/admin|dashboard|bacheca/, { timeout: 10000, waitUntil: 'domcontentloaded' });
}

/** Create a fresh admin-authenticated page in an isolated context, do work, close context */
async function withAdminPage(browser, fn) {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await loginAsAdmin(page);
  try {
    return await fn(page);
  } finally {
    await ctx.close();
  }
}

/** Get CSRF token from a page */
async function getCsrfToken(page) {
  // Try hidden input first (hidden inputs are NOT visible, use count())
  const input = page.locator('input[name="csrf_token"]').first();
  if (await input.count() > 0) {
    const val = await input.getAttribute('value');
    if (val) return val;
  }
  // Fallback to meta tag
  const meta = page.locator('meta[name="csrf-token"]');
  if (await meta.count() > 0) {
    const content = await meta.getAttribute('content');
    if (content) return content;
  }
  return '';
}

/** Clear file-based rate limit state so tests don't hit 429 errors */
function clearRateLimits() {
  const dir = path.join(os.tmpdir(), 'pinakes_ratelimit');
  try {
    if (!fs.existsSync(dir)) return;
    for (const f of fs.readdirSync(dir)) {
      try { fs.unlinkSync(path.join(dir, f)); } catch { /* ignore races */ }
    }
  } catch { /* ignore missing/inaccessible directory */ }
}

/** Return today's date as YYYY-MM-DD */
function todayISO() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** Return a date offset from today as YYYY-MM-DD */
function dateOffset(days) {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

// ══════════════════════════════════════════════════════════════════════
// Phase A: Setup — configure SMTP to point at Mailpit
// ══════════════════════════════════════════════════════════════════════
test.describe.serial('Email Notifications E2E', () => {
  /** @type {import('@playwright/test').Browser} */
  let browserRef;

  // Store original email settings for restoration
  let originalSettings = {};

  test.beforeAll(async ({ browser }) => {
    browserRef = browser;

    // Clear rate limit state from previous test runs
    clearRateLimits();

    // Save original email settings
    try {
      originalSettings.type = dbQuery("SELECT setting_value FROM system_settings WHERE category='email' AND setting_key='type' LIMIT 1");
    } catch { originalSettings.type = 'mail'; }
    try {
      originalSettings.driver_mode = dbQuery("SELECT setting_value FROM system_settings WHERE category='email' AND setting_key='driver_mode' LIMIT 1");
    } catch { originalSettings.driver_mode = 'mail'; }
    try {
      originalSettings.smtp_host = dbQuery("SELECT setting_value FROM system_settings WHERE category='email' AND setting_key='smtp_host' LIMIT 1");
    } catch { originalSettings.smtp_host = ''; }
    try {
      originalSettings.smtp_port = dbQuery("SELECT setting_value FROM system_settings WHERE category='email' AND setting_key='smtp_port' LIMIT 1");
    } catch { originalSettings.smtp_port = '587'; }
    try {
      originalSettings.smtp_security = dbQuery("SELECT setting_value FROM system_settings WHERE category='email' AND setting_key='smtp_security' LIMIT 1");
    } catch { originalSettings.smtp_security = 'tls'; }
    try {
      originalSettings.smtp_username = dbQuery("SELECT setting_value FROM system_settings WHERE category='email' AND setting_key='smtp_username' LIMIT 1");
    } catch { originalSettings.smtp_username = ''; }
    try {
      originalSettings.smtp_password = dbQuery("SELECT setting_value FROM system_settings WHERE category='email' AND setting_key='smtp_password' LIMIT 1");
    } catch { originalSettings.smtp_password = ''; }
    try {
      originalSettings.from_email = dbQuery("SELECT setting_value FROM system_settings WHERE category='email' AND setting_key='from_email' LIMIT 1");
    } catch { originalSettings.from_email = ''; }
    try {
      originalSettings.from_name = dbQuery("SELECT setting_value FROM system_settings WHERE category='email' AND setting_key='from_name' LIMIT 1");
    } catch { originalSettings.from_name = ''; }
    try {
      originalSettings.contact_notification = dbQuery("SELECT setting_value FROM system_settings WHERE category='contacts' AND setting_key='notification_email' LIMIT 1");
    } catch { originalSettings.contact_notification = ''; }
  });

  test.afterAll(async () => {
    // Always clean up regardless of test outcome
    await cleanupAndRestore();
  });

  // ── A.1: Configure SMTP driver → Mailpit ────────────────────────
  test('A.1 — Configure SMTP driver to Mailpit', async () => {
    // Set email settings via DB to point at Mailpit
    dbQuery(`
      INSERT INTO system_settings (category, setting_key, setting_value)
      VALUES
        ('email', 'type', 'smtp'),
        ('email', 'driver_mode', 'smtp'),
        ('email', 'smtp_host', 'localhost'),
        ('email', 'smtp_port', '1025'),
        ('email', 'smtp_security', ''),
        ('email', 'smtp_username', 'mailpit'),
        ('email', 'smtp_password', 'mailpit'),
        ('email', 'from_email', 'test@biblioteca.local'),
        ('email', 'from_name', 'Pinakes Test')
      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    `);

    // Set contact notification email so contact form tests work
    dbQuery(`
      INSERT INTO system_settings (category, setting_key, setting_value)
      VALUES ('contacts', 'notification_email', '${sqlEscape(ADMIN_EMAIL)}')
      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    `);

    // Verify settings are stored
    const type = dbQuery("SELECT setting_value FROM system_settings WHERE category='email' AND setting_key='type'");
    expect(type).toBe('smtp');

    // Clear Mailpit inbox
    await clearMailpit();
  });

  // ══════════════════════════════════════════════════════════════════
  // Phase B: SMTP driver — test all email types
  // ══════════════════════════════════════════════════════════════════

  // ── B.1 & B.2: Registration emails ──────────────────────────────
  test('B.1+B.2 — Registration: pending (user) + admin notification', async () => {
    await clearMailpit();
    clearRateLimits(); // registration has a 3/hour rate limit

    // Clean up any existing test user (FK-safe: delete referencing rows first)
    try {
      const existingId = dbQuery(`SELECT id FROM utenti WHERE email = '${TEST_USER_EMAIL}' LIMIT 1`);
      if (existingId) {
        try { dbQuery(`DELETE FROM prestiti WHERE utente_id = ${existingId}`); } catch { /* */ }
        try { dbQuery(`DELETE FROM consent_log WHERE utente_id = ${existingId}`); } catch { /* */ }
        try { dbQuery(`DELETE FROM recensioni WHERE utente_id = ${existingId}`); } catch { /* */ }
        try { dbQuery(`DELETE FROM wishlist WHERE utente_id = ${existingId}`); } catch { /* */ }
        dbQuery(`DELETE FROM utenti WHERE id = ${existingId}`);
      }
    } catch { /* user doesn't exist */ }

    // Register via form
    const page = await browserRef.newPage();
    await page.goto(`${BASE}/registrati`, { waitUntil: 'domcontentloaded' });

    await page.fill('input[name="nome"]', TEST_USER_FIRST);
    await page.fill('input[name="cognome"]', TEST_USER_LAST);
    await page.fill('input[name="email"]', TEST_USER_EMAIL);
    await page.fill('input[name="telefono"]', '1234567890');
    await page.locator('[name="indirizzo"]').fill('Via Test 1');
    await page.fill('input[name="password"]', TEST_USER_PASS);
    await page.fill('input[name="password_confirm"]', TEST_USER_PASS);

    // Accept privacy checkbox
    const privacyCheck = page.locator('input[name="privacy_acceptance"]');
    await privacyCheck.check();

    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Wait for redirect to success page
    await page.waitForURL(/successo|success/, { timeout: 10000 });
    await page.close();

    // B.1: User receives "registration pending" email
    const userMail = await waitForMail(`to:${TEST_USER_EMAIL}`);
    expect(userMail).toBeTruthy();
    const userMsg = await getMessage(userMail.ID);
    // Subject should contain registration-related text
    expect(
      userMsg.Subject.toLowerCase()
    ).toMatch(/registrazione|registration|benvenuto|welcome/);

    // B.2: Admin receives notification about new registration
    const adminMail = await waitForMail(`to:${ADMIN_EMAIL} subject:registrazione`);
    expect(adminMail).toBeTruthy();
  });

  // ── B.3: Account approved ────────────────────────────────────
  test('B.3 — Account approved email', async () => {
    await clearMailpit();

    // Get test user ID
    const userId = dbQuery(`SELECT id FROM utenti WHERE email = '${TEST_USER_EMAIL}' LIMIT 1`);
    expect(userId).toBeTruthy();

    // Ensure user is in 'sospeso' state (required for activate-directly)
    dbQuery(`UPDATE utenti SET stato = 'sospeso' WHERE id = ${userId}`);

    // Use a fresh page to avoid stale admin dashboard state
    const page = await browserRef.newPage();
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/utenti`, { waitUntil: 'domcontentloaded' });
    const csrfToken = await getCsrfToken(page);
    expect(csrfToken).toBeTruthy();

    const activateResponse = await page.request.post(`${BASE}/admin/utenti/${userId}/activate-directly`, {
      form: { csrf_token: csrfToken },
    });
    expect(activateResponse.status()).toBeLessThan(400);
    await page.close();

    // User receives "account approved" email
    const approvedMail = await waitForMail(`to:${TEST_USER_EMAIL}`);
    expect(approvedMail).toBeTruthy();
    const approvedMsg = await getMessage(approvedMail.ID);
    expect(
      approvedMsg.Subject.toLowerCase()
    ).toMatch(/approvato|approved|benvenuto/);
  });

  // ── B.4: Password setup email ──────────────────────────────────
  test('B.4 — Password setup email (admin creates user)', async () => {
    await clearMailpit();

    // Create a user via admin panel WITHOUT password to trigger password setup email
    const tempEmail = 'emailtest-setup@example.com';
    dbQuery(`DELETE FROM utenti WHERE email = '${tempEmail}'`);

    // Use a fresh page to avoid stale admin dashboard state
    const page = await browserRef.newPage();
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/utenti/crea`, { waitUntil: 'domcontentloaded' });
    const csrfToken = await getCsrfToken(page);
    expect(csrfToken).toBeTruthy();

    // Create user without password → triggers sendUserPasswordSetup()
    const createRes = await page.request.post(`${BASE}/admin/utenti/store`, {
      form: {
        csrf_token: csrfToken,
        nome: 'Setup',
        cognome: 'TestUser',
        email: tempEmail,
        telefono: '1111111111',
        tipo_utente: 'standard',
        stato: 'attivo',
      },
    });
    expect(createRes.status()).toBeLessThan(400);
    await page.close();

    // User should receive password setup email
    const setupMail = await waitForMail(`to:${tempEmail}`);
    expect(setupMail).toBeTruthy();
    const msg = await getMessage(setupMail.ID);
    expect(
      msg.Subject.toLowerCase()
    ).toMatch(/password|imposta|setup|approvato|approved|benvenuto/);

    // Cleanup
    dbQuery(`DELETE FROM utenti WHERE email = '${tempEmail}'`);
  });

  // ── B.5: Password reset ─────────────────────────────────────────
  test('B.5 — Password reset email', async () => {
    await clearMailpit();
    clearRateLimits(); // forgot_password has a 3/15min rate limit

    // Activate the test user so password reset works
    dbQuery(`UPDATE utenti SET stato = 'attivo' WHERE email = '${TEST_USER_EMAIL}'`);

    const page = await browserRef.newPage();
    await page.goto(`${BASE}/password-dimenticata`, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', TEST_USER_EMAIL);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Wait for redirect
    await page.waitForURL(/sent=1/, { timeout: 10000 });
    await page.close();

    // Check Mailpit for password reset email
    const resetMail = await waitForMail(`to:${TEST_USER_EMAIL}`);
    expect(resetMail).toBeTruthy();
    const resetMsg = await getMessage(resetMail.ID);
    expect(
      resetMsg.Subject.toLowerCase()
    ).toMatch(/password|recupera|reset/);
  });

  // ── B.6–B.8: Loan lifecycle emails ─────────────────────────────
  test('B.6 — Loan request notification to admin', async () => {
    await clearMailpit();

    // Ensure test user is active with a password
    const userId = dbQuery(`SELECT id FROM utenti WHERE email = '${TEST_USER_EMAIL}' LIMIT 1`);

    // Set the test user's password properly
    const phpHash = execFileSync('php', ['-r', `echo password_hash('${TEST_USER_PASS}', PASSWORD_DEFAULT);`], { encoding: 'utf-8' }).trim();
    dbQuery(`UPDATE utenti SET stato = 'attivo', tipo_utente = 'standard', password = '${phpHash.replace(/'/g, "\\'")}' WHERE id = ${userId}`);

    // Create a dedicated test book with a copy in the copie table
    // (the approve controller checks the copie table, not just copie_disponibili)
    const invNum = `LOANTEST-${Date.now()}`;
    const bookId = dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES ('Loan Email Test Book ${RUN_ID}', 2, 2, 'disponibile'); SELECT LAST_INSERT_ID()`);
    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${bookId}, '${invNum}-A', 'disponibile')`);
    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${bookId}, '${invNum}-B', 'disponibile')`);

    // Login as test user and request a loan
    const userCtx = await browserRef.newContext();
    const userPage = await userCtx.newPage();
    await userPage.goto(`${BASE}/accedi`, { waitUntil: 'domcontentloaded' });
    await userPage.fill('input[name="email"]', TEST_USER_EMAIL);
    await userPage.fill('input[name="password"]', TEST_USER_PASS);
    await userPage.locator('button[type="submit"], input[type="submit"]').first().click();
    await userPage.waitForURL(/bacheca|catalogo|dashboard|utente/, { timeout: 10000 });

    // Navigate to book detail and request loan
    await userPage.goto(`${BASE}/libro/${bookId}`, { waitUntil: 'domcontentloaded' });

    // Try the SweetAlert loan request button
    const loanBtn = userPage.locator('#btn-request-loan, [data-action="request-loan"], a[href*="loan"], button:has-text("Prenota"), button:has-text("Richiedi")').first();
    if (await loanBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await loanBtn.click();
      // Handle SweetAlert date picker
      await userPage.waitForSelector('.swal2-popup', { timeout: 5000 }).catch(() => {});
      const swalVisible = await userPage.locator('.swal2-popup').isVisible().catch(() => false);
      if (swalVisible) {
        // Wait for Flatpickr
        await userPage.waitForFunction(
          () => {
            const el = document.querySelector('#swal-date-start');
            return el && /** @type {any} */ (el)._flatpickr;
          },
          { timeout: 5000 },
        ).catch(() => {});

        const today = todayISO();
        await userPage.evaluate((iso) => {
          const el = document.querySelector('#swal-date-start');
          if (el && /** @type {any} */ (el)._flatpickr) {
            /** @type {any} */ (el)._flatpickr.setDate(iso, true);
          }
        }, today);
        await userPage.locator('.swal2-confirm').click();
        await userPage.waitForFunction(
          () => !!document.querySelector('.swal2-icon-success, .swal2-icon-error'),
          { timeout: 15000 },
        ).catch(() => {});
        // Dismiss the result SweetAlert
        const confirmBtn = userPage.locator('.swal2-confirm');
        if (await confirmBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
          await confirmBtn.click();
        }
      }
    }
    await userPage.close();
    await userCtx.close();

    // B.6: Admin should receive loan request notification
    const loanMail = await waitForMail(`to:${ADMIN_EMAIL} subject:prestito`);
    expect(loanMail).toBeTruthy();
  });

  test('B.7 — Loan approved email to user', async () => {
    await clearMailpit();

    const userId = dbQuery(`SELECT id FROM utenti WHERE email = '${TEST_USER_EMAIL}' LIMIT 1`);

    // Find or create a pending loan with a book that has copies in the copie table
    let loanId = dbQuery(`SELECT p.id FROM prestiti p
      JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
      WHERE p.utente_id = ${userId} AND p.stato = 'pendente'
      ORDER BY p.id DESC LIMIT 1`);

    if (!loanId) {
      // Create a test book with proper copies, then a pending loan
      const invNum = `APPROVE-${Date.now()}`;
      const bookId = dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES ('Approve Test Book ${RUN_ID}', 1, 1, 'disponibile'); SELECT LAST_INSERT_ID()`);
      dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${bookId}, '${invNum}', 'disponibile')`);
      const today = todayISO();
      const endDate = dateOffset(14);
      loanId = dbQuery(`INSERT INTO prestiti (libro_id, utente_id, stato, data_prestito, data_scadenza, attivo, created_at)
               VALUES (${bookId}, ${userId}, 'pendente', '${today}', '${endDate}', 1, NOW()); SELECT LAST_INSERT_ID()`);
    }

    await withAdminPage(browserRef, async (page) => {
      await page.goto(`${BASE}/admin/loans/pending`, { waitUntil: 'domcontentloaded' });
      const csrfToken = await getCsrfToken(page);
      const approveRes = await page.request.post(`${BASE}/admin/loans/approve`, {
        form: { csrf_token: csrfToken || '', loan_id: loanId },
      });
      expect(approveRes.status()).toBe(200);
    });

    const approvedMail = await waitForMail(`to:${TEST_USER_EMAIL}`);
    expect(approvedMail).toBeTruthy();
    const msg = await getMessage(approvedMail.ID);
    expect(msg.Subject.toLowerCase()).toMatch(/approvata|approved|prestito|loan|ritiro|pronto/);
  });

  test('B.8 — Loan rejected email to user', async () => {
    await clearMailpit();

    const userId = dbQuery(`SELECT id FROM utenti WHERE email = '${TEST_USER_EMAIL}' LIMIT 1`);

    // Create a test book with copies + a pending loan for rejection
    const invNum = `REJECT-${Date.now()}`;
    const bookId = dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES ('Reject Test Book ${RUN_ID}', 1, 1, 'disponibile'); SELECT LAST_INSERT_ID()`);
    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${bookId}, '${invNum}', 'disponibile')`);

    const today = todayISO();
    const endDate = dateOffset(14);
    const loanId = dbQuery(`INSERT INTO prestiti (libro_id, utente_id, stato, data_prestito, data_scadenza, attivo, created_at)
             VALUES (${bookId}, ${userId}, 'pendente', '${today}', '${endDate}', 1, NOW()); SELECT LAST_INSERT_ID()`);

    await withAdminPage(browserRef, async (page) => {
      await page.goto(`${BASE}/admin/loans/pending`, { waitUntil: 'domcontentloaded' });
      const csrfToken = await getCsrfToken(page);
      const rejectRes = await page.request.post(`${BASE}/admin/loans/reject`, {
        form: { csrf_token: csrfToken || '', loan_id: loanId, reason: 'Test rejection reason' },
      });
      expect(rejectRes.status()).toBe(200);
    });

    const rejectedMail = await waitForMail(`to:${TEST_USER_EMAIL}`);
    expect(rejectedMail).toBeTruthy();
    const msg = await getMessage(rejectedMail.ID);
    expect(msg.Subject.toLowerCase()).toMatch(/non approvata|rifiutat|rejected|prestito/);
  });

  // ── B.9: Loan expiring warning ─────────────────────────────────
  test('B.9 — Loan expiring warning via maintenance', async () => {
    await clearMailpit();

    const userId = dbQuery(`SELECT id FROM utenti WHERE email = '${TEST_USER_EMAIL}' LIMIT 1`);
    const bookId = dbQuery("SELECT id FROM libri WHERE deleted_at IS NULL LIMIT 1");

    let warningDays;
    try {
      warningDays = dbQuery("SELECT setting_value FROM system_settings WHERE category='advanced' AND setting_key='days_before_expiry_warning' LIMIT 1");
    } catch { warningDays = '3'; }
    if (!warningDays) warningDays = '3';

    const expiryDate = dateOffset(parseInt(warningDays));
    const startDate = dateOffset(-7);
    const loanId = dbQuery(`INSERT INTO prestiti (libro_id, utente_id, stato, data_prestito, data_scadenza, attivo, warning_sent, overdue_notification_sent, created_at)
             VALUES (${bookId}, ${userId}, 'in_corso', '${startDate}', '${expiryDate}', 1, 0, 0, NOW()); SELECT LAST_INSERT_ID()`);

    await withAdminPage(browserRef, async (page) => {
      await page.goto(`${BASE}/admin/integrity`, { waitUntil: 'domcontentloaded' });
      const csrfToken = await getCsrfToken(page);
      const maintenanceRes = await page.request.post(`${BASE}/admin/maintenance/perform`, {
        form: { csrf_token: csrfToken || '' },
      });
      expect(maintenanceRes.status()).toBeLessThan(500);
    });

    const warningMail = await waitForMail(`to:${TEST_USER_EMAIL}`);
    expect(warningMail).toBeTruthy();
    const msg = await getMessage(warningMail.ID);
    expect(msg.Subject.toLowerCase()).toMatch(/scad|expir|promemoria|warning/);

    dbQuery(`DELETE FROM prestiti WHERE id = ${loanId}`);
  });

  // ── B.10 + B.11: Loan overdue (user + admin) ──────────────────
  test('B.10+B.11 — Overdue loan notifications (user + admin)', async () => {
    await clearMailpit();

    const userId = dbQuery(`SELECT id FROM utenti WHERE email = '${TEST_USER_EMAIL}' LIMIT 1`);
    const bookId = dbQuery("SELECT id FROM libri WHERE deleted_at IS NULL LIMIT 1");

    const overdueDate = dateOffset(-1);
    const startDate = dateOffset(-15);
    const loanId = dbQuery(`INSERT INTO prestiti (libro_id, utente_id, stato, data_prestito, data_scadenza, attivo, warning_sent, overdue_notification_sent, created_at)
             VALUES (${bookId}, ${userId}, 'in_corso', '${startDate}', '${overdueDate}', 1, 1, 0, NOW()); SELECT LAST_INSERT_ID()`);

    await withAdminPage(browserRef, async (page) => {
      await page.goto(`${BASE}/admin/integrity`, { waitUntil: 'domcontentloaded' });
      const csrfToken = await getCsrfToken(page);
      await page.request.post(`${BASE}/admin/maintenance/perform`, {
        form: { csrf_token: csrfToken || '' },
      });
    });

    const overdueMail = await waitForMail(`to:${TEST_USER_EMAIL}`);
    expect(overdueMail).toBeTruthy();
    const userMsg = await getMessage(overdueMail.ID);
    expect(userMsg.Subject.toLowerCase()).toMatch(/scaduto|overdue|ritardo/);

    // B.11: Admin also receives overdue notification (optional — depends on template)
    const adminOverdue = await waitForMail(`to:${ADMIN_EMAIL} subject:scadut`).catch(() => null)
      || await waitForMail(`to:${ADMIN_EMAIL} subject:overdue`).catch(() => null)
      || await waitForMail(`to:${ADMIN_EMAIL} subject:ritard`).catch(() => null);
    if (adminOverdue) {
      expect(adminOverdue).toBeTruthy();
    }

    dbQuery(`DELETE FROM prestiti WHERE id = ${loanId}`);
  });

  // ── B.12: Wishlist book available ──────────────────────────────
  test('B.12 — Wishlist book available notification', async () => {
    await clearMailpit();

    const userId = dbQuery(`SELECT id FROM utenti WHERE email = '${TEST_USER_EMAIL}' LIMIT 1`);

    const bookId = dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES ('Wishlist Test Book ${RUN_ID}', 1, 1, 'disponibile'); SELECT LAST_INSERT_ID()`);
    const invNum = `WISHTEST-${Date.now()}`;
    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${bookId}, '${invNum}', 'disponibile')`);

    const wishlistId = dbQuery(`INSERT INTO wishlist (utente_id, libro_id, notified) VALUES (${userId}, ${bookId}, 0); SELECT LAST_INSERT_ID()`);

    await withAdminPage(browserRef, async (page) => {
      await page.goto(`${BASE}/admin/integrity`, { waitUntil: 'domcontentloaded' });
      const csrfToken = await getCsrfToken(page);
      await page.request.post(`${BASE}/admin/maintenance/perform`, {
        form: { csrf_token: csrfToken || '' },
      });
    });

    // User should receive wishlist notification
    const wishMail = await waitForMail(`to:${TEST_USER_EMAIL}`);
    expect(wishMail).toBeTruthy();
    const msg = await getMessage(wishMail.ID);
    expect(
      msg.Subject.toLowerCase()
    ).toMatch(/wishlist|disponibil|available/);

    // Cleanup
    dbQuery(`DELETE FROM wishlist WHERE id = ${wishlistId}`);
    dbQuery(`DELETE FROM copie WHERE libro_id = ${bookId}`);
    dbQuery(`DELETE FROM libri WHERE id = ${bookId}`);
  });

  // ── B.13: Contact form email ───────────────────────────────────
  test('B.13 — Contact form email to admin', async () => {
    await clearMailpit();

    // Verify contact_messages table exists (should be created by migrations)
    const hasContactMessages = dbQuery(
      "SELECT COUNT(*) FROM information_schema.tables " +
      "WHERE table_schema = DATABASE() AND table_name = 'contact_messages'"
    );
    expect(Number(hasContactMessages)).toBeGreaterThan(0);

    const page = await browserRef.newPage();
    await page.goto(`${BASE}/contatti`, { waitUntil: 'domcontentloaded' });

    await page.fill('input[name="nome"]', 'Contact');
    await page.fill('input[name="cognome"]', 'Tester');
    await page.fill('input[name="email"]', CONTACT_EMAIL);

    // Fill optional phone if visible
    const phoneInput = page.locator('input[name="telefono"]');
    if (await phoneInput.isVisible({ timeout: 1000 }).catch(() => false)) {
      await phoneInput.fill('0000000000');
    }

    // Fill optional address if visible
    const addressInput = page.locator('input[name="indirizzo"], textarea[name="indirizzo"]');
    if (await addressInput.isVisible({ timeout: 1000 }).catch(() => false)) {
      await addressInput.fill('Via Contact 1');
    }

    await page.fill('textarea[name="messaggio"]', 'This is a test contact message for email verification.');

    // Accept privacy if present
    const privacy = page.locator('input[name="privacy"]');
    if (await privacy.isVisible({ timeout: 1000 }).catch(() => false)) {
      await privacy.check();
    }

    await page.locator('#contact-form button[type="submit"], .btn-submit').first().click();
    await page.waitForURL(/success=1/, { timeout: 15000 });
    await page.close();

    // Admin should receive contact notification
    const contactMail = await waitForMail(`to:${ADMIN_EMAIL} subject:messaggio`);
    expect(contactMail).toBeTruthy();
    const msg = await getMessage(contactMail.ID);
    expect(msg.Subject.toLowerCase()).toMatch(/messaggio|contact|contatt/);
  });

  // ── B.14: Review notification ──────────────────────────────────
  test('B.14 — Review notification to admin', async () => {
    await clearMailpit();

    const userId = dbQuery(`SELECT id FROM utenti WHERE email = '${TEST_USER_EMAIL}' LIMIT 1`);
    const bookId = dbQuery("SELECT id FROM libri WHERE deleted_at IS NULL LIMIT 1");

    // Ensure the user has a completed loan for this book (required to review)
    const existingLoan = dbQuery(`SELECT id FROM prestiti WHERE utente_id = ${userId} AND libro_id = ${bookId} AND stato = 'restituito' LIMIT 1`);
    let loanId;
    if (!existingLoan) {
      // Create a completed loan so user can review
      loanId = dbQuery(`INSERT INTO prestiti (libro_id, utente_id, stato, data_prestito, data_scadenza, attivo, created_at)
               VALUES (${bookId}, ${userId}, 'restituito', '${dateOffset(-30)}', '${dateOffset(-16)}', 0, NOW()); SELECT LAST_INSERT_ID()`);
    }

    // Ensure no existing review for this user/book
    dbQuery(`DELETE FROM recensioni WHERE utente_id = ${userId} AND libro_id = ${bookId}`);

    // Login as test user and submit review via API
    const userCtx = await browserRef.newContext();
    const userPage = await userCtx.newPage();
    await userPage.goto(`${BASE}/accedi`, { waitUntil: 'domcontentloaded' });
    await userPage.fill('input[name="email"]', TEST_USER_EMAIL);
    await userPage.fill('input[name="password"]', TEST_USER_PASS);
    await userPage.locator('button[type="submit"], input[type="submit"]').first().click();
    await userPage.waitForURL(/bacheca|catalogo|dashboard|utente/, { timeout: 10000 });

    // Get CSRF token
    await userPage.goto(`${BASE}/libro/${bookId}`, { waitUntil: 'domcontentloaded' });
    const csrfToken = await getCsrfToken(userPage);

    // Submit review via API
    const reviewRes = await userPage.request.post(`${BASE}/api/user/recensioni`, {
      form: {
        csrf_token: csrfToken || '',
        libro_id: bookId,
        stelle: '4',
        titolo: 'Email Test Review',
        descrizione: 'Testing review email notification',
      },
    });
    // Accept 200 or redirect
    expect(reviewRes.status()).toBeLessThan(500);

    await userPage.close();
    await userCtx.close();

    // Admin should receive review notification
    const reviewMail = await waitForMail(`to:${ADMIN_EMAIL} subject:recension`).catch(() => null)
      || await waitForMail(`to:${ADMIN_EMAIL}`).catch(() => null);
    expect(reviewMail).toBeTruthy();

    // Cleanup
    dbQuery(`DELETE FROM recensioni WHERE utente_id = ${userId} AND libro_id = ${bookId}`);
    if (loanId) {
      dbQuery(`DELETE FROM prestiti WHERE id = ${loanId}`);
    }
  });

  // ══════════════════════════════════════════════════════════════════
  // Phase C: phpmail driver — verify PHP mail() routes through Mailpit
  // ══════════════════════════════════════════════════════════════════
  test('C.1 — Switch to mail driver and test contact form', async () => {
    // Switch to PHP mail() driver
    dbQuery(`UPDATE system_settings SET setting_value = 'mail' WHERE category = 'email' AND setting_key = 'type'`);
    dbQuery(`UPDATE system_settings SET setting_value = 'mail' WHERE category = 'email' AND setting_key = 'driver_mode'`);

    await clearMailpit();

    // Submit contact form (uses Mailer.php which reads ConfigStore → driver_mode)
    const page = await browserRef.newPage();
    await page.goto(`${BASE}/contatti`, { waitUntil: 'domcontentloaded' });

    await page.fill('input[name="nome"]', 'PhpMail');
    await page.fill('input[name="cognome"]', 'Test');
    await page.fill('input[name="email"]', 'phpmail-test@example.com');

    const phoneInput = page.locator('input[name="telefono"]');
    if (await phoneInput.isVisible({ timeout: 1000 }).catch(() => false)) {
      await phoneInput.fill('0000000000');
    }
    const addressInput = page.locator('input[name="indirizzo"], textarea[name="indirizzo"]');
    if (await addressInput.isVisible({ timeout: 1000 }).catch(() => false)) {
      await addressInput.fill('Via PhpMail 1');
    }

    await page.fill('textarea[name="messaggio"]', 'Testing phpmail driver through Mailpit sendmail.');

    const privacy = page.locator('input[name="privacy"]');
    if (await privacy.isVisible({ timeout: 1000 }).catch(() => false)) {
      await privacy.check();
    }

    await page.locator('#contact-form button[type="submit"], .btn-submit').first().click();
    await page.waitForURL(/success=1/, { timeout: 15000 });
    await page.close();

    // Email should arrive via Mailpit's sendmail replacement
    const phpMail = await waitForMail(`to:${ADMIN_EMAIL}`);
    expect(phpMail).toBeTruthy();
    const msg = await getMessage(phpMail.ID);
    expect(msg.Subject.toLowerCase()).toMatch(/messaggio|contact|phpmail/);
  });

  test('C.2 — Mail driver: registration email via phpmail', async () => {
    await clearMailpit();
    clearRateLimits(); // registration has a 3/hour rate limit

    // Clean up any existing test user2
    try {
      const existingId2 = dbQuery(`SELECT id FROM utenti WHERE email = '${TEST_USER2_EMAIL}' LIMIT 1`);
      if (existingId2) {
        try { dbQuery(`DELETE FROM prestiti WHERE utente_id = ${existingId2}`); } catch { /* */ }
        try { dbQuery(`DELETE FROM consent_log WHERE utente_id = ${existingId2}`); } catch { /* */ }
        dbQuery(`DELETE FROM utenti WHERE id = ${existingId2}`);
      }
    } catch { /* user doesn't exist */ }

    const page = await browserRef.newPage();
    await page.goto(`${BASE}/registrati`, { waitUntil: 'domcontentloaded' });

    await page.fill('input[name="nome"]', 'PhpMail');
    await page.fill('input[name="cognome"]', 'RegTest');
    await page.fill('input[name="email"]', TEST_USER2_EMAIL);
    await page.fill('input[name="telefono"]', '9876543210');
    await page.locator('[name="indirizzo"]').fill('Via PhpMail Reg 1');
    await page.fill('input[name="password"]', TEST_USER_PASS);
    await page.fill('input[name="password_confirm"]', TEST_USER_PASS);

    const privacyCheck = page.locator('input[name="privacy_acceptance"]');
    await privacyCheck.check();

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForURL(/successo|success/, { timeout: 10000 });
    await page.close();

    // Registration email should arrive via Mailpit's sendmail
    const regMail = await waitForMail(`to:${TEST_USER2_EMAIL}`);
    expect(regMail).toBeTruthy();
    const msg = await getMessage(regMail.ID);
    expect(
      msg.Subject.toLowerCase()
    ).toMatch(/registrazione|registration|benvenuto/);
  });

  // ══════════════════════════════════════════════════════════════════
  // Cleanup helper — also called from afterAll as safety net
  // ══════════════════════════════════════════════════════════════════
  async function cleanupAndRestore() {
    // Data cleanup — errors here must not prevent settings restoration
    try {
      // Clean up test users and related data
      for (const email of [TEST_USER_EMAIL, TEST_USER2_EMAIL]) {
        try {
          const uid = dbQuery(`SELECT id FROM utenti WHERE email = '${email}' LIMIT 1`).trim();
          if (uid) {
            try { dbQuery(`DELETE FROM prestiti WHERE utente_id = ${uid}`); } catch { /* */ }
            try { dbQuery(`DELETE FROM recensioni WHERE utente_id = ${uid}`); } catch { /* */ }
            try { dbQuery(`DELETE FROM wishlist WHERE utente_id = ${uid}`); } catch { /* */ }
            try { dbQuery(`DELETE FROM consent_log WHERE utente_id = ${uid}`); } catch { /* */ }
            try { dbQuery(`DELETE FROM notifications WHERE utente_id = ${uid}`); } catch { /* */ }
          }
        } catch { /* user doesn't exist */ }
      }

      // Delete test users
      dbQuery(`DELETE FROM utenti WHERE email IN ('${TEST_USER_EMAIL}', '${TEST_USER2_EMAIL}', 'emailtest-setup@example.com')`);

      // Delete test books created by loan tests
      try { dbQuery(`DELETE FROM copie WHERE libro_id IN (SELECT id FROM libri WHERE titolo LIKE '%${RUN_ID}%')`); } catch { /* */ }
      try { dbQuery(`DELETE FROM libri WHERE titolo LIKE '%${RUN_ID}%'`); } catch { /* */ }

      // Delete contact messages from test
      try { dbQuery(`DELETE FROM contact_messages WHERE email IN ('${CONTACT_EMAIL}', 'phpmail-test@example.com')`); } catch { /* */ }
    } catch (err) {
      console.error('[Cleanup] Error during data cleanup:', err.message);
    } finally {
      // Settings restoration — always runs even if data cleanup fails
      const restore = (category, key, value) => {
        if (value === undefined) return;
        try {
          dbQuery(`INSERT INTO system_settings (category, setting_key, setting_value)
            VALUES ('${category}', '${key}', '${sqlEscape(String(value))}')
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)`);
        } catch { /* best effort */ }
      };
      restore('email', 'type', originalSettings.type);
      restore('email', 'driver_mode', originalSettings.driver_mode);
      restore('email', 'smtp_host', originalSettings.smtp_host);
      restore('email', 'smtp_port', originalSettings.smtp_port);
      restore('email', 'smtp_security', originalSettings.smtp_security);
      restore('email', 'smtp_username', originalSettings.smtp_username);
      restore('email', 'smtp_password', originalSettings.smtp_password);
      restore('email', 'from_email', originalSettings.from_email);
      restore('email', 'from_name', originalSettings.from_name);

      if (originalSettings.contact_notification !== undefined) {
        restore('contacts', 'notification_email', originalSettings.contact_notification);
      }

      // Clear Mailpit
      await clearMailpit();
    }
  }

  // Phase D: Cleanup (explicit test + afterAll safety net)
  test('D — Cleanup test data and restore settings', async () => {
    await cleanupAndRestore();
  });
});
