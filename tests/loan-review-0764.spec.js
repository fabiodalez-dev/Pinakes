// @ts-check
//
// E2E — Loan edit-flow review for 0.7.64 (F1: due-date backdating guard).
//
// Exercises the REAL admin edit-loan flow (browser + Apache + PrestitiController
// ::update + the per-copy circulation triggers). The reviewed change: actively
// backdating an active loan's due date into the past must fail with the CLEAR
// `expired_window` message and redirect to /admin/loans?error=expired_window —
// never the opaque `loan_update_failed` — while editing an already-overdue loan
// whose due date is left UNCHANGED must still succeed (the guard only fires when
// the due date is actually being moved into the past).
//
// Self-provisions its own admin, borrower, book and copy (tokenised) and cleans
// everything up in afterAll. Requires a real installed DB — the runner
// (/tmp/run-e2e.sh) supplies E2E_DB_* and the base URL.

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const crypto = require('crypto');

const BASE = process.env.E2E_BASE_URL || process.env.APP_URL || 'http://localhost:8081';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(
  !DB_USER || !DB_NAME,
  'E2E DB credentials not configured (set E2E_DB_USER, E2E_DB_NAME via /tmp/run-e2e.sh)',
);

// Serial: the tests share one loan row and reseed it between runs.
test.describe.configure({ mode: 'serial' });

function dbQuery(sql) {
  const args = [];
  if (DB_HOST) {
    args.push('-h', DB_HOST);
    if (DB_PORT) args.push('-P', DB_PORT);
  } else if (DB_SOCKET) {
    args.push('-S', DB_SOCKET);
  }
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, {
    encoding: 'utf-8',
    timeout: 15000,
    env: { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}

/** Single-quote + escape a value for inlining as a SQL string literal. */
const S = (v) => "'" + String(v).replace(/'/g, "''") + "'";

/** bcrypt hash the app's password_verify() will accept. */
function phpPasswordHash(pw) {
  return execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(pw)}, PASSWORD_DEFAULT);`], {
    encoding: 'utf-8',
    timeout: 15000,
  }).trim();
}

/** The application's local "today" (DateHelper::today) — matches the update() guard. */
function appToday() {
  return execFileSync('php', ['-r', "require 'vendor/autoload.php'; echo App\\Support\\DateHelper::today();"], {
    encoding: 'utf-8',
    timeout: 15000,
    cwd: process.cwd(),
  }).trim();
}

/** yyyy-mm-dd offset from the app's today. */
function dayFromToday(base, offsetDays) {
  const dt = new Date(base + 'T00:00:00Z');
  dt.setUTCDate(dt.getUTCDate() + offsetDays);
  return dt.toISOString().slice(0, 10);
}

const RUN = crypto.randomBytes(4).toString('hex');
const DOMAIN = '@0764rev.test.local';
const ADMIN_EMAIL = `rev-admin-${RUN}${DOMAIN}`;
const ADMIN_PASS = 'Rev0764Pass!';

/** @type {{adminId:number, borrowerId:number, bookId:number, copyId:number, today:string}} */
const fx = { adminId: 0, borrowerId: 0, bookId: 0, copyId: 0, today: '' };

async function loginAdmin(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('form button[type="submit"], button[type="submit"]').first().click();
  await page.waitForURL((url) => !url.toString().includes('/accedi'), { timeout: 15000 });
}

/** Delete any loan on the shared copy and seed exactly one active loan. Returns its id. */
function seedLoan(startDate, dueDate, stato = 'in_corso') {
  dbQuery(`DELETE FROM prestiti WHERE libro_id=${fx.bookId}`);
  dbQuery(`UPDATE copie SET stato='disponibile' WHERE id=${fx.copyId}`);
  dbQuery(
    `INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
     VALUES (${fx.bookId}, ${fx.copyId}, ${fx.borrowerId}, ${S(startDate)}, ${S(dueDate)}, ${S(stato)}, 'diretto', 1)`,
  );
  return Number(dbQuery(`SELECT id FROM prestiti WHERE libro_id=${fx.bookId} ORDER BY id DESC LIMIT 1`));
}

/** Open the edit form, set the due date, submit, and return the landing URL. */
async function editDueDate(page, loanId, newDue, { newStart = null } = {}) {
  await page.goto(`${BASE}/admin/loans/edit/${loanId}`);
  await page.waitForLoadState('domcontentloaded');
  const form = page.locator('form[action*="/admin/loans/update/"]');
  await form.waitFor({ timeout: 15000 });
  // The global flatpickr-init converts these type="date" inputs (altInput:true),
  // hiding the real name="data_*" input and showing a formatted altInput. Drive
  // the underlying value through flatpickr's own API (dateFormat 'Y-m-d').
  await page.waitForFunction(
    () => {
      const el = document.querySelector('form[action*="/admin/loans/update/"] input[name="data_scadenza"]');
      return !!(el && el._flatpickr);
    },
    null,
    { timeout: 15000 },
  );
  const setDate = async (name, value) => {
    await form.locator(`input[name="${name}"]`).evaluate((el, v) => {
      if (el._flatpickr) {
        el._flatpickr.setDate(v, true);
      } else {
        el.value = v;
        el.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }, value);
  };
  if (newStart !== null) {
    await setDate('data_prestito', newStart);
  }
  if (newDue !== null) {
    await setDate('data_scadenza', newDue);
  }
  await form.locator('button[type="submit"]').first().click();
  await page.waitForURL((url) => url.pathname.endsWith('/admin/loans'), { timeout: 15000 });
  return page.url();
}

test.beforeAll(() => {
  fx.today = appToday();

  const adminHash = phpPasswordHash(ADMIN_PASS);
  dbQuery(
    `INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata, privacy_accettata, locale)
     VALUES (${S('REVADM' + RUN.toUpperCase())}, 'Admin', ${S('Rev' + RUN)}, ${S(ADMIN_EMAIL)}, ${S(adminHash)}, 'admin', 'attivo', 1, 1, 'it_IT')`,
  );
  fx.adminId = Number(dbQuery(`SELECT id FROM utenti WHERE email=${S(ADMIN_EMAIL)} LIMIT 1`));

  const borrowerHash = phpPasswordHash('Borrow0764!');
  const borrowerEmail = `rev-b-${RUN}${DOMAIN}`;
  dbQuery(
    `INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata, privacy_accettata, locale)
     VALUES (${S('REVB' + RUN.toUpperCase())}, 'Borrower', ${S('Rev' + RUN)}, ${S(borrowerEmail)}, ${S(borrowerHash)}, 'standard', 'attivo', 1, 1, 'it_IT')`,
  );
  fx.borrowerId = Number(dbQuery(`SELECT id FROM utenti WHERE email=${S(borrowerEmail)} LIMIT 1`));

  dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES (${S('ZZ0764REV ' + RUN + ' Edit Guard Book')}, 1, 1)`);
  fx.bookId = Number(dbQuery(`SELECT id FROM libri WHERE titolo=${S('ZZ0764REV ' + RUN + ' Edit Guard Book')} ORDER BY id DESC LIMIT 1`));
  const inv = `ZZ0764REV-${RUN}-A`;
  dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${fx.bookId}, ${S(inv)}, 'disponibile')`);
  fx.copyId = Number(dbQuery(`SELECT id FROM copie WHERE numero_inventario=${S(inv)} LIMIT 1`));
});

test.afterAll(() => {
  if (fx.bookId) {
    dbQuery(`DELETE FROM prestiti WHERE libro_id=${fx.bookId}`);
    dbQuery(`DELETE FROM copie WHERE libro_id=${fx.bookId}`);
    dbQuery(`DELETE FROM libri WHERE id=${fx.bookId}`);
  }
  dbQuery(`DELETE FROM user_sessions WHERE utente_id IN (SELECT id FROM utenti WHERE email LIKE ${S('%' + RUN + DOMAIN)})`);
  dbQuery(`DELETE FROM utenti WHERE email LIKE ${S('%' + RUN + DOMAIN)}`);
});

test('1. backdating an active loan due date redirects to error=expired_window', async ({ page }) => {
  const loanId = seedLoan(fx.today, dayFromToday(fx.today, 14));
  await loginAdmin(page);
  const url = await editDueDate(page, loanId, dayFromToday(fx.today, -5), { newStart: dayFromToday(fx.today, -10) });
  expect(url).toContain('error=expired_window');
});

test('2. the expired_window banner shows the CLEAR message, not the opaque update-failed default', async ({ page }) => {
  const loanId = seedLoan(fx.today, dayFromToday(fx.today, 14));
  await loginAdmin(page);
  await editDueDate(page, loanId, dayFromToday(fx.today, -5), { newStart: dayFromToday(fx.today, -10) });
  const banner = page.locator('[role="alert"]');
  await expect(banner).toContainText('non può essere nel passato');
  // The opaque generic fallback ("Errore durante l'aggiornamento del prestito")
  // must NOT be what the user sees.
  await expect(banner).not.toContainText("Errore durante l'aggiornamento del prestito");
});

test('3. the rejected backdate leaves the stored due date UNCHANGED', async ({ page }) => {
  const originalDue = dayFromToday(fx.today, 14);
  const loanId = seedLoan(fx.today, originalDue);
  await loginAdmin(page);
  await editDueDate(page, loanId, dayFromToday(fx.today, -5), { newStart: dayFromToday(fx.today, -10) });
  const stored = dbQuery(`SELECT data_scadenza FROM prestiti WHERE id=${loanId}`);
  expect(stored).toBe(originalDue);
});

test('4. editing an already-overdue loan without moving its due date still SUCCEEDS', async ({ page }) => {
  // Due date already in the past, left unchanged; only the start date is nudged.
  const overdueDue = dayFromToday(fx.today, -3);
  const loanId = seedLoan(dayFromToday(fx.today, -10), overdueDue);
  await loginAdmin(page);
  const url = await editDueDate(page, loanId, overdueDue, { newStart: dayFromToday(fx.today, -12) });
  expect(url).not.toContain('error=');
  // The loan is still active and its (past) due date is intact — the guard did
  // not false-block an unrelated edit.
  const row = dbQuery(`SELECT attivo, data_scadenza FROM prestiti WHERE id=${loanId}`).split('\t');
  expect(row[0]).toBe('1');
  expect(row[1]).toBe(overdueDue);
});

test('5. moving the due date to a valid FUTURE date succeeds and persists', async ({ page }) => {
  const loanId = seedLoan(fx.today, dayFromToday(fx.today, 14));
  const newDue = dayFromToday(fx.today, 40);
  await loginAdmin(page);
  const url = await editDueDate(page, loanId, newDue);
  expect(url).not.toContain('error=');
  expect(dbQuery(`SELECT data_scadenza FROM prestiti WHERE id=${loanId}`)).toBe(newDue);
});

test('6. an inverted range (due before start) is rejected with the distinct invalid_dates code', async ({ page }) => {
  const loanId = seedLoan(fx.today, dayFromToday(fx.today, 14));
  await loginAdmin(page);
  // Keep the due date in the FUTURE but move the start date after it, so this is
  // an inverted-range rejection (invalid_dates), never the expired_window guard.
  const url = await editDueDate(page, loanId, dayFromToday(fx.today, 5), { newStart: dayFromToday(fx.today, 20) });
  expect(url).toContain('error=invalid_dates');
  expect(url).not.toContain('error=expired_window');
});
