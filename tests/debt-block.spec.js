// @ts-check
/**
 * Debt block (plan §7): a reader who owes more than the threshold set in
 * Settings → Loans cannot request, receive or renew a loan, nor have a
 * pending request approved, on any path, and gets the dedicated message. The DB is used only to seed the debt and the
 * loans (dates in the application timezone) and to verify the outcome.
 *
 * Run: /tmp/run-e2e.sh tests/debt-block.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const { appTodayISO, appDateOffsetISO } = require('./helpers/app-date');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME, 'E2E credentials not configured');

const RUN = Date.now().toString(36);
const PATRON_EMAIL = `zz-debt-${RUN}@test.local`;
const PATRON_PASS = 'Test1234!';
const SURNAME = `Debt${RUN}`;
const TITLE_PREFIX = `ZZ_DEBT_${RUN}`;
const DEBT_MESSAGE = 'Saldo a debito oltre la soglia consentita';
const SETTING_KEYS = ['daily_fine', 'fine_grace_days', 'fine_max_per_loan', 'debt_block_threshold', 'fine_charged_email', 'auto_approve_requests'];

function dbQuery(sql) {
  const args = [];
  if (DB_HOST) args.push('-h', DB_HOST); else if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 15000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}
const esc = (s) => String(s).replace(/\\/g, '\\\\').replace(/'/g, "''");
const phpHash = (p) => execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(p)}, PASSWORD_DEFAULT);`], { encoding: 'utf-8' }).trim();
function readSetting(key) {
  return dbQuery(`SELECT COUNT(*) FROM system_settings WHERE category='loans' AND setting_key='${key}'`) === '1'
    ? dbQuery(`SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='${key}'`)
    : null;
}
function restoreSetting(key, value) {
  if (value === null) dbQuery(`DELETE FROM system_settings WHERE category='loans' AND setting_key='${key}'`);
  else dbQuery(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans','${key}','${esc(value)}') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
}

const saved = {};
const state = { patronId: 0, bookFree: 0, bookOut: 0, activeLoan: 0, bookTitleFree: '' };

function newBook(key, available) {
  const title = `${TITLE_PREFIX}_${key}`;
  dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES ('${esc(title)}', 1, ${available ? 1 : 0}, '${available ? 'disponibile' : 'prestato'}')`);
  const id = Number(dbQuery(`SELECT id FROM libri WHERE titolo='${esc(title)}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`));
  dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${id}, '${esc(`ZZD-${RUN}-${key}`)}', '${available ? 'disponibile' : 'prestato'}')`);
  // The admin book picker searches the denormalised FULLTEXT search_index,
  // which only the app's save path fills: give the fixture a searchable token.
  const token = `zzdebt${RUN}${key}`.toLowerCase();
  dbQuery(`UPDATE libri SET search_index='${esc(`${title} ${token}`)}' WHERE id=${id}`);
  return { id, title: token };
}
const balanceCents = () => Math.round(Number(dbQuery(`SELECT COALESCE(SUM(importo),0) FROM movimenti_lettore WHERE utente_id=${state.patronId}`)) * 100);

// Run-scoped: only this run's reader and books, so a concurrent run on the
// same database keeps its fixtures. Leftovers of a crashed run (older than a
// day) go in a separate pass.
function purge(userCond, bookCond) {
  dbQuery(`DELETE m FROM movimenti_lettore m JOIN utenti u ON u.id=m.utente_id WHERE ${userCond}`);
  dbQuery(`DELETE r FROM prenotazioni r JOIN utenti u ON u.id=r.utente_id WHERE ${userCond}`);
  dbQuery(`DELETE p FROM prestiti p JOIN libri l ON l.id=p.libro_id WHERE ${bookCond}`);
  dbQuery(`DELETE p FROM prestiti p JOIN utenti u ON u.id=p.utente_id WHERE ${userCond}`);
  dbQuery(`DELETE c FROM copie c JOIN libri l ON l.id=c.libro_id WHERE ${bookCond}`);
  dbQuery(`DELETE lm FROM log_modifiche lm JOIN libri l ON l.id=lm.record_id AND lm.tabella='libri' WHERE ${bookCond}`);
  dbQuery(`DELETE l FROM libri l WHERE ${bookCond}`);
  dbQuery(`DELETE s FROM user_sessions s JOIN utenti u ON u.id=s.utente_id WHERE ${userCond}`);
  dbQuery(`DELETE u FROM utenti u WHERE ${userCond}`);
}
function cleanup() {
  purge(`u.email='${esc(PATRON_EMAIL)}'`, `l.titolo LIKE '${esc(TITLE_PREFIX.replace(/_/g, '\\_'))}\\_%'`);
}
function cleanupStale() {
  purge("u.email LIKE 'zz-debt-%@test.local' AND u.created_at < NOW() - INTERVAL 1 DAY", "l.titolo LIKE 'ZZ\\_DEBT\\_%' AND l.created_at < NOW() - INTERVAL 1 DAY");
}

async function loginAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const email = page.locator('input[name="email"]');
  if (await email.isVisible({ timeout: 3000 }).catch(() => false)) {
    await email.fill(ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/.*(?:dashboard|admin).*/, { timeout: 15000 });
  }
}
async function loginReader(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', PATRON_EMAIL);
  await page.fill('input[name="password"]', PATRON_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL((u) => !u.toString().includes('/accedi'), { timeout: 30000 });
}
/** The reader asks for the book from its public page (SweetAlert date picker). */
async function requestFromBookPage(page, bookId) {
  await page.goto(`${BASE}/libro/${bookId}`, { waitUntil: 'domcontentloaded' });
  const button = page.locator('#btn-request-loan');
  await expect(button, await page.locator('main').innerText().catch(() => '')).toBeVisible({ timeout: 15000 });
  await button.click();
  await page.waitForSelector('.swal2-popup', { timeout: 20000 });
  await page.waitForFunction(() => {
    const el = document.querySelector('#swal-date-start');
    return el && /** @type {any} */ (el)._flatpickr;
  }, null, { timeout: 8000 });
  const start = appTodayISO();
  await page.evaluate((iso) => { /** @type {any} */ (document.querySelector('#swal-date-start'))._flatpickr.setDate(iso, true); }, start);
  await page.locator('.swal2-confirm').click();
  await page.waitForFunction(() => !!document.querySelector('.swal2-icon-success, .swal2-icon-error'), null, { timeout: 15000 });
  const ok = await page.locator('.swal2-icon-success').isVisible().catch(() => false);
  const text = (await page.locator('.swal2-popup').innerText().catch(() => '')) || '';
  const btn = page.locator('.swal2-confirm');
  if (await btn.isVisible().catch(() => false)) await btn.click();
  return { ok, text };
}
async function fillAutocomplete(page, input, suggest, query, api) {
  for (let attempt = 1; attempt <= 3; attempt++) {
    await page.fill(input, '');
    const resp = page.waitForResponse((r) => r.url().includes(api) && r.status() === 200, { timeout: 15000 });
    await page.locator(input).pressSequentially(query, { delay: 50 });
    await resp;
    const item = page.locator(`${suggest} .suggestion-item`).first();
    if (await item.isVisible({ timeout: 3000 }).catch(() => false)) { await item.click(); return; }
  }
  await page.locator(`${suggest} .suggestion-item`).first().click({ timeout: 5000 });
}

test.describe.serial('Debt block', () => {
  test.describe.configure({ timeout: 240000 });
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    for (const k of SETTING_KEYS) saved[k] = readSetting(k);
    cleanupStale();
    cleanup();
    restoreSetting('auto_approve_requests', '0');
    dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, email_verificata, tipo_utente, privacy_accettata, locale) VALUES ('${`ZZD${RUN}`.slice(0, 20)}', 'Zz', '${SURNAME}', '${PATRON_EMAIL}', '${esc(phpHash(PATRON_PASS))}', 'attivo', 1, 'standard', 1, 'it_IT')`);
    state.patronId = Number(dbQuery(`SELECT id FROM utenti WHERE email='${PATRON_EMAIL}'`));
    const free = newBook('FREE', true);
    state.bookFree = free.id;
    state.bookTitleFree = free.title;
    const out = newBook('OUT', false);
    state.bookOut = out.id;
    // An active loan, due in the future, that the reader may want to renew.
    const copyOut = Number(dbQuery(`SELECT id FROM copie WHERE libro_id=${out.id} LIMIT 1`));
    dbQuery(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (${out.id}, ${copyOut}, ${state.patronId}, '${appDateOffsetISO(-3)}', '${appDateOffsetISO(10)}', 'in_corso', 'diretto', 1)`);
    state.activeLoan = Number(dbQuery(`SELECT id FROM prestiti WHERE libro_id=${out.id} ORDER BY id DESC LIMIT 1`));
    // The debt: a manual charge of 8,00 recorded earlier at the desk.
    dbQuery(`INSERT INTO movimenti_lettore (utente_id, tipo, importo, descrizione, created_at) VALUES (${state.patronId}, 'addebito_manuale', 8.00, 'Tessera smarrita', NOW())`);
    page = await browser.newPage();
    await loginAdmin(page);
  });

  test.afterAll(async () => {
    for (const k of SETTING_KEYS) restoreSetting(k, saved[k]);
    cleanup();
    await page?.close();
  });

  test('1. the librarian sets the debt threshold in Settings → Loans', async () => {
    await page.goto(`${BASE}/admin/settings?tab=loans`);
    await page.fill('#debt_block_threshold', '5');
    await page.locator('form[action$="/admin/settings/loans"] button[type="submit"]').click();
    await page.waitForURL(/tab=loans/);
    expect(readSetting('debt_block_threshold')).toBe('5.00');
    await expect(page.locator('#debt_block_threshold')).toHaveValue('5.00');
    // The reader record now says the reader is blocked.
    await page.goto(`${BASE}/admin/users/details/${state.patronId}`);
    await expect(page.locator('[data-ledger-blocked]')).toContainText('Prestiti bloccati');
  });

  test('2. the reader is refused at the book page with the debt message', async ({ browser }) => {
    const reader = await browser.newPage();
    try {
      await loginReader(reader);
      const { ok, text } = await requestFromBookPage(reader, state.bookFree);
      expect(ok).toBe(false);
      expect(text).toContain(DEBT_MESSAGE);
    } finally {
      await reader.close();
    }
    expect(Number(dbQuery(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${state.patronId} AND libro_id=${state.bookFree}`))).toBe(0);
  });

  test('2b. the app asking for the same book gets the debt message (code not_eligible kept)', async ({ request, browser }) => {
    const F = require('./helpers/mobile-api-fixtures');
    const helper = await browser.newPage();
    let gate = null;
    try {
      gate = await F.ensureMobileApiReady(helper);
      const token = await F.login(request, PATRON_EMAIL, PATRON_PASS, `zz-debt-${RUN}`);
      const r = await F.api(request, 'POST', '/reservations', { token, body: { book_id: state.bookFree } });
      expect(r.status, r.text.slice(0, 300)).toBe(403);
      expect(r.json.error.code).toBe('not_eligible');
      expect(r.json.error.message).toContain(DEBT_MESSAGE);
    } finally {
      F.restoreGate(gate);
      dbQuery(`DELETE FROM mobile_app_tokens WHERE user_id=${state.patronId}`);
      await helper.close();
    }
    expect(Number(dbQuery(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${state.patronId} AND libro_id=${state.bookFree}`))).toBe(0);
  });

  test('3. a loan created by the librarian is refused with the same message', async () => {
    await page.goto(`${BASE}/admin/loans/create`);
    await fillAutocomplete(page, '#utente_search', '#utente_suggest', SURNAME, '/api/search/utenti');
    expect(Number(await page.locator('#utente_id').inputValue())).toBe(state.patronId);
    await fillAutocomplete(page, '#libro_search', '#libro_suggest', state.bookTitleFree, '/api/search/libri');
    expect(Number(await page.locator('#libro_id').inputValue())).toBe(state.bookFree);
    await page.evaluate(() => document.querySelectorAll('.flatpickr-calendar.open').forEach((c) => c.classList.remove('open')));
    await page.locator('button[type="submit"]:not([name="save_and_new"])').click();
    await page.waitForURL(/error=debt_block/, { timeout: 20000 });
    await expect(page.locator('.bg-red-100')).toContainText(DEBT_MESSAGE);
    expect(Number(dbQuery(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${state.patronId} AND libro_id=${state.bookFree}`))).toBe(0);
  });

  test('4. renewing the active loan is refused with the same message', async () => {
    const dueBefore = dbQuery(`SELECT data_scadenza FROM prestiti WHERE id=${state.activeLoan}`);
    await page.goto(`${BASE}/admin/books/${state.bookOut}`);
    await page.getByRole('button', { name: /Rinnova prestito/ }).click();
    await page.locator('.swal2-confirm').click();
    await page.waitForURL(/error=debt_block/, { timeout: 20000 });
    await expect(page.locator('[role="alert"]').filter({ hasText: DEBT_MESSAGE })).toBeVisible();
    expect(dbQuery(`SELECT CONCAT(data_scadenza,'|',renewals) FROM prestiti WHERE id=${state.activeLoan}`)).toBe(`${dueBefore}|0`);
  });

  test('5. a waiting reservation is not promoted while the reader is blocked', async () => {
    // A queued reservation for a book whose copy is free today: the queue would
    // hand it over, the debt block keeps it waiting (eligibleUserWhere fragment).
    dbQuery(`INSERT INTO prenotazioni (libro_id, utente_id, data_prenotazione, data_inizio_richiesta, data_fine_richiesta, data_scadenza_prenotazione, queue_position, stato) VALUES (${state.bookFree}, ${state.patronId}, NOW(), '${appTodayISO()}', '${appDateOffsetISO(14)}', '${appDateOffsetISO(14)} 23:59:59', 1, 'attiva')`);
    const resId = Number(dbQuery(`SELECT id FROM prenotazioni WHERE utente_id=${state.patronId} ORDER BY id DESC LIMIT 1`));
    const out = execFileSync('php', ['-r', `
      require 'vendor/autoload.php';
      $env = Dotenv\\Dotenv::parse(file_get_contents('.env'));
      $sock = $env['DB_SOCKET'] ?? '';
      $db = ($sock !== '' && file_exists($sock))
        ? new mysqli(null, $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], 0, $sock)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306));
      $db->set_charset('utf8mb4');
      App\\Support\\DateHelper::synchronizeDatabaseSession($db);
      echo (new App\\Controllers\\ReservationManager($db))->processBookAvailability(${state.bookFree}) ? 'promoted' : 'kept';
    `], { cwd: require('path').resolve(__dirname, '..'), encoding: 'utf-8' }).trim();
    expect(out).toBe('kept');
    expect(dbQuery(`SELECT stato FROM prenotazioni WHERE id=${resId}`)).toBe('attiva');
    expect(Number(dbQuery(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${state.patronId} AND libro_id=${state.bookFree}`))).toBe(0);
    dbQuery(`DELETE FROM prenotazioni WHERE id=${resId}`);
  });

  test('5b. approving a pending request of the blocked reader is refused with debt_block', async () => {
    // A request sent before the debt (or before the threshold was set): the
    // approval re-checks eligibility under the user lock, as the desk does.
    dbQuery(`INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (${state.bookFree}, ${state.patronId}, '${appTodayISO()}', '${appDateOffsetISO(14)}', 'pendente', 'richiesta', 0)`);
    const loanId = Number(dbQuery(`SELECT id FROM prestiti WHERE utente_id=${state.patronId} AND stato='pendente' ORDER BY id DESC LIMIT 1`));
    await page.goto(`${BASE}/admin/loans/pending`);
    const csrfInputs = page.locator('input[name="csrf_token"]');
    const csrf = (await csrfInputs.count()) > 0 ? await csrfInputs.first().inputValue() : '';
    const res = await page.request.post(`${BASE}/admin/loans/approve`, { form: { csrf_token: csrf, loan_id: String(loanId) }, headers: { Accept: 'application/json' } });
    expect(res.status(), await res.text()).toBe(403);
    const body = await res.json();
    expect(body.success).toBe(false);
    expect(String(body.message)).toContain(DEBT_MESSAGE);
    expect(dbQuery(`SELECT CONCAT(stato,'|',attivo) FROM prestiti WHERE id=${loanId}`)).toBe('pendente|0');
    expect(Number(dbQuery(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${state.patronId} AND libro_id=${state.bookFree} AND attivo=1`))).toBe(0);
    dbQuery(`DELETE FROM prestiti WHERE id=${loanId}`);
  });

  test('6. once the reader pays below the threshold, the same request goes through', async ({ browser }) => {
    await page.goto(`${BASE}/admin/users/details/${state.patronId}#movimenti`);
    await page.fill('#ledger_pay_amount', '4');
    await page.selectOption('#ledger_pay_method', 'pos');
    await page.locator('#ledger-payment-form button[type="submit"]').click();
    await expect(page.locator('[data-ledger-flash="paid"]')).toBeVisible();
    expect(balanceCents()).toBe(400);
    await expect(page.locator('[data-ledger-blocked]')).toHaveCount(0);

    const reader = await browser.newPage();
    try {
      await loginReader(reader);
      const { ok, text } = await requestFromBookPage(reader, state.bookFree);
      expect(ok, text).toBe(true);
    } finally {
      await reader.close();
    }
    expect(Number(dbQuery(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${state.patronId} AND libro_id=${state.bookFree} AND stato='pendente'`))).toBe(1);
  });

  test('7. with the threshold cleared nobody is blocked, whatever the balance', async () => {
    dbQuery(`INSERT INTO movimenti_lettore (utente_id, tipo, importo, descrizione, created_at) VALUES (${state.patronId}, 'addebito_manuale', 50.00, 'Prova', NOW())`);
    await page.goto(`${BASE}/admin/settings?tab=loans`);
    await page.fill('#debt_block_threshold', '');
    await page.locator('form[action$="/admin/settings/loans"] button[type="submit"]').click();
    await page.waitForURL(/tab=loans/);
    expect(readSetting('debt_block_threshold')).toBe('');
    await page.goto(`${BASE}/admin/users/details/${state.patronId}`);
    await expect(page.locator('[data-ledger-balance]')).toHaveAttribute('data-ledger-balance', '5400');
    await expect(page.locator('[data-ledger-blocked]')).toHaveCount(0);
    const dueBefore = dbQuery(`SELECT data_scadenza FROM prestiti WHERE id=${state.activeLoan}`);
    await page.goto(`${BASE}/admin/books/${state.bookOut}`);
    await page.getByRole('button', { name: /Rinnova prestito/ }).click();
    await page.locator('.swal2-confirm').click();
    await page.waitForFunction(() => !window.location.search.includes('error='), null, { timeout: 20000 }).catch(() => {});
    expect(dbQuery(`SELECT renewals FROM prestiti WHERE id=${state.activeLoan}`)).toBe('1');
    expect(dbQuery(`SELECT data_scadenza > '${dueBefore}' FROM prestiti WHERE id=${state.activeLoan}`)).toBe('1');
  });
});
