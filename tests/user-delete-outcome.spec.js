// @ts-check
/**
 * What the administrator is told after pressing "delete" on a reader in
 * app/Views/utenti/index.php.
 *
 * The list used to say "Eliminato! L'utente è stato eliminato." for any
 * answer that was ok or redirected. A reader with a reservation could not be
 * erased (prenotazioni.utente_id forbids it): the server rolled back and
 * redirected with ?error=db_error, fetch() followed the redirect, and the
 * operator read "deleted" while the reader stayed active. The same toast was
 * shown for a reader who was only suspended because of the loan history.
 *
 * Each test presses the real button of the reader's row and reads both the
 * dialog and the database.
 *
 * Run with (the wrapper exports the admin and database variables this file
 * needs; without them every test here is skipped):
 *   /tmp/run-e2e.sh tests/user-delete-outcome.spec.js --config=tests/playwright.config.js --workers=1
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
const DB_PORT = process.env.E2E_DB_PORT || '';

const HAS_E2E_ENV = Boolean(ADMIN_EMAIL && ADMIN_PASS && DB_USER && DB_NAME && (DB_HOST || DB_SOCKET));

test.skip(!HAS_E2E_ENV, 'Missing E2E env vars for the user delete outcome tests');

function mysqlArgs(sql = '', batch = false) {
  const args = [];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_PORT) args.push('-P', DB_PORT);
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

function dbExec(sql) {
  execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout: 10000 });
}

const RUN = Date.now().toString(36);
const TAG = `E2EUDEL${RUN}`;

let userSeq = 0;
/** A fresh active reader with nothing attached; returns id and surname. */
function createReader(label) {
  const n = userSeq++;
  // codice_tessera is varchar(20) + UNIQUE.
  const card = `UD${RUN}${n}`.slice(0, 20);
  const surname = `${TAG}${label}`;
  const email = `${TAG}-${label}@example.test`.toLowerCase();
  dbExec(`
    INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata, created_at, updated_at)
    VALUES ('${card}', '${TAG}', '${surname}', '${email}', 'x', 'attivo', 'standard', 1, NOW(), NOW())
  `);
  return { id: Number(dbQuery(`SELECT id FROM utenti WHERE email = '${email}' LIMIT 1`)), surname };
}

function createBook() {
  dbExec(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at) VALUES ('${TAG} book', 1, 1, NOW(), NOW())`);
  return Number(dbQuery(`SELECT id FROM libri WHERE titolo = '${TAG} book' ORDER BY id DESC LIMIT 1`));
}

function cleanup() {
  dbExec(`DELETE p FROM prenotazioni p JOIN utenti u ON u.id = p.utente_id WHERE u.nome = '${TAG}'`);
  dbExec(`DELETE p FROM prenotazioni p JOIN libri l ON l.id = p.libro_id WHERE l.titolo = '${TAG} book'`);
  dbExec(`DELETE FROM libri WHERE titolo = '${TAG} book'`);
  dbExec(`DELETE FROM utenti WHERE nome = '${TAG}'`);
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

/** Open the list, find the reader by surname, press the row's delete button and confirm. */
async function deleteFromList(page, reader) {
  await page.goto(`${BASE}/admin/users`);
  await page.waitForSelector('#utenti-table_wrapper', { timeout: 15000 });
  await page.fill('#search_text', reader.surname);
  const button = page.locator(`#utenti-table button[data-pk-action="users-delete"][data-user-id="${reader.id}"]`);
  await expect(button).toBeVisible({ timeout: 15000 });
  await button.click();
  await expect(page.locator('.swal2-popup')).toContainText('Sei sicuro?', { timeout: 8000 });
  await page.locator('.swal2-confirm').click();
}

test.describe.serial('Deleting a reader says what really happened', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  let bookId = 0;

  test.beforeAll(async ({ browser }) => {
    cleanup();
    bookId = createBook();
    context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);
  });

  test.afterAll(async () => {
    try { cleanup(); } catch { /* best-effort */ }
    await context?.close();
  });

  test('a reader with only a cancelled reservation is suspended, and the dialog says so', async () => {
    const reader = createReader('resv');
    dbExec(`
      INSERT INTO prenotazioni (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta, data_scadenza_prenotazione, queue_position, stato)
      VALUES (${bookId}, ${reader.id}, CURDATE(), CURDATE(), CONCAT(CURDATE(), ' 23:59:59'), 1, 'annullata')
    `);

    await deleteFromList(page, reader);

    // The confirmation dialog is replaced by the outcome: a warning carrying
    // the server's message, never the "deleted" success.
    const popup = page.locator('.swal2-popup');
    await expect(popup).toContainText('sospeso', { timeout: 10000 });
    await expect(page.locator('.swal2-popup.swal2-icon-warning')).toBeVisible();
    await expect(popup).not.toContainText('Eliminato!');
    await expect(popup).not.toContainText("L'utente è stato eliminato.");

    expect(dbQuery(`SELECT stato FROM utenti WHERE id = ${reader.id}`)).toBe('sospeso');

    // The list shows the same thing: the row is still there, now suspended.
    await page.locator('.swal2-confirm').click();
    await expect(page.locator(`#utenti-table button[data-pk-action="users-delete"][data-user-id="${reader.id}"]`)).toBeVisible();
  });

  test('a reader with no history is erased, and the dialog says "deleted"', async () => {
    const reader = createReader('plain');

    await deleteFromList(page, reader);

    const popup = page.locator('.swal2-popup');
    await expect(page.locator('.swal2-popup.swal2-icon-success')).toBeVisible({ timeout: 10000 });
    await expect(popup).toContainText('Eliminato!');
    expect(dbQuery(`SELECT COUNT(*) FROM utenti WHERE id = ${reader.id}`)).toBe('0');

    await page.locator('.swal2-confirm').click();
    await expect(page.locator(`#utenti-table button[data-pk-action="users-delete"][data-user-id="${reader.id}"]`)).toHaveCount(0);
  });

  test('a delete the server refused is an error, even when the answer ends in a page that loads', async () => {
    const reader = createReader('refused');
    // What the old server did and what an expired session still does: the
    // request ends, after a redirect, on an ordinary HTML page with status 200.
    await page.route('**/admin/users/delete/*', (route) => route.fulfill({
      status: 200,
      contentType: 'text/html; charset=utf-8',
      body: '<!doctype html><html><body>Utenti</body></html>',
    }));

    await deleteFromList(page, reader);

    const popup = page.locator('.swal2-popup');
    await expect(page.locator('.swal2-popup.swal2-icon-error')).toBeVisible({ timeout: 10000 });
    await expect(popup).not.toContainText('Eliminato!');
    await page.unroute('**/admin/users/delete/*');

    expect(dbQuery(`SELECT stato FROM utenti WHERE id = ${reader.id}`)).toBe('attivo');
  });

  test('the reason the server gives for a failure is shown to the operator', async () => {
    const reader = createReader('failed');
    await page.route('**/admin/users/delete/*', (route) => route.fulfill({
      status: 500,
      contentType: 'application/json',
      body: JSON.stringify({ success: false, error: "Errore durante l'eliminazione" }),
    }));

    await deleteFromList(page, reader);

    const popup = page.locator('.swal2-popup');
    await expect(page.locator('.swal2-popup.swal2-icon-error')).toBeVisible({ timeout: 10000 });
    await expect(popup).toContainText("Errore durante l'eliminazione");
    await page.unroute('**/admin/users/delete/*');

    expect(dbQuery(`SELECT stato FROM utenti WHERE id = ${reader.id}`)).toBe('attivo');
  });
});
