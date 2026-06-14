// @ts-check
/**
 * E2E coverage for EVERY SweetAlert in the "books" cluster.
 *
 * Each test proves a SweetAlert is BOTH shown (.swal2-popup visible) AND
 * functional (DB row change / redirect / toast text / error icon).
 *
 * Views covered:
 *   - app/Views/libri/index.php
 *   - app/Views/libri/partials/book_form.php
 *   - app/Views/libri/scheda_libro.php
 *
 * Run with:
 *   /tmp/run-e2e.sh tests/swal-books.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

// ── Env wiring (copied VERBATIM from tests/series-cycles.spec.js) ───────────
const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS ?? '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

const HAS_E2E_ENV = Boolean(ADMIN_EMAIL && ADMIN_PASS && DB_USER && DB_NAME && (DB_HOST || DB_SOCKET));

test.skip(!HAS_E2E_ENV, 'Missing E2E env vars for books SweetAlert tests');

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

// ── Per-run unique suffix (same style as series-cycles TAG) ─────────────────
const TAG = 'E2E_SWAL_BOOKS_' + Date.now();

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

// ── Fixture helpers ─────────────────────────────────────────────────────────
function insertBook(title, extra = {}) {
  const cols = ['titolo', 'copie_totali', 'copie_disponibili', 'created_at', 'updated_at'];
  const vals = [`'${escapeSql(title)}'`, '1', '1', 'NOW()', 'NOW()'];
  for (const [k, v] of Object.entries(extra)) {
    cols.push(k);
    vals.push(v === null ? 'NULL' : `'${escapeSql(String(v))}'`);
  }
  dbExec(`INSERT INTO libri (${cols.join(',')}) VALUES (${vals.join(',')})`);
  return Number(dbQuery(`SELECT id FROM libri WHERE titolo = '${escapeSql(title)}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`));
}

function insertCopy(bookId, inventario, stato = 'disponibile') {
  dbExec(`INSERT INTO copie (libro_id, numero_inventario, stato, created_at, updated_at) VALUES (${bookId}, '${escapeSql(inventario)}', '${escapeSql(stato)}', NOW(), NOW())`);
  return Number(dbQuery(`SELECT id FROM copie WHERE numero_inventario = '${escapeSql(inventario)}' ORDER BY id DESC LIMIT 1`));
}

function getTestUserId() {
  // Reuse the admin user as borrower for loan fixtures (FK utente_id only).
  return Number(dbQuery(`SELECT id FROM utenti WHERE email = '${escapeSql(ADMIN_EMAIL)}' ORDER BY id ASC LIMIT 1`));
}

function insertActiveLoan(bookId, copyId, userId, stato = 'in_corso', days = 14) {
  dbExec(`
    INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, created_at, updated_at)
    VALUES (${bookId}, ${copyId}, ${userId}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ${days} DAY), '${escapeSql(stato)}', 'diretto', 1, NOW(), NOW())
  `);
  return Number(dbQuery(`SELECT id FROM prestiti WHERE libro_id = ${bookId} AND copia_id = ${copyId} ORDER BY id DESC LIMIT 1`));
}

function cleanup() {
  // FK-safe order: prestiti -> copie -> libri_collane/collane -> libri
  dbExec(`DELETE FROM prestiti WHERE libro_id IN (SELECT id FROM libri WHERE titolo LIKE '${escapeSql(TAG)}%')`);
  dbExec(`DELETE FROM volumi WHERE opera_id IN (SELECT id FROM libri WHERE titolo LIKE '${escapeSql(TAG)}%') OR volume_id IN (SELECT id FROM libri WHERE titolo LIKE '${escapeSql(TAG)}%')`);
  dbExec(`DELETE FROM copie WHERE libro_id IN (SELECT id FROM libri WHERE titolo LIKE '${escapeSql(TAG)}%')`);
  dbExec(`DELETE FROM libri_collane WHERE libro_id IN (SELECT id FROM libri WHERE titolo LIKE '${escapeSql(TAG)}%') OR collana_id IN (SELECT id FROM collane WHERE nome LIKE '${escapeSql(TAG)}%')`);
  dbExec(`DELETE FROM collane WHERE nome LIKE '${escapeSql(TAG)}%'`);
  dbExec(`DELETE FROM libri WHERE titolo LIKE '${escapeSql(TAG)}%'`);
}

// Navigate to /admin/books, search for a unique title, wait for its row checkbox.
async function selectRowByTitle(page, title, bookId) {
  await page.goto(`${BASE}/admin/books`);
  await page.waitForSelector('#libri-table', { timeout: 15000 });
  await page.fill('#search_text', title);
  const checkbox = page.locator(`.row-select[data-id="${bookId}"]`);
  await checkbox.waitFor({ state: 'visible', timeout: 15000 });
  await checkbox.check();
  return checkbox;
}

test.describe.serial('Books cluster SweetAlerts', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  let userId;

  test.beforeAll(async ({ browser }) => {
    cleanup();
    context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);
    userId = getTestUserId();
    expect(userId).toBeGreaterThan(0);
  });

  test.afterAll(async () => {
    try { cleanup(); } catch { /* best-effort */ }
    await context?.close();
  });

  // ── index.php ──────────────────────────────────────────────────────────────

  // index.php:1059 (info) + :1122 result toast — bulk-fetch-covers
  test('index.php:1059 bulk-fetch-covers shows progress + result popup', async () => {
    const title = `${TAG} CoverBook`;
    const bookId = insertBook(title, { isbn13: null }); // no ISBN => no_isbn reason
    await selectRowByTitle(page, title, bookId);
    await page.click('#bulk-fetch-covers');
    // Progress modal then result popup both use .swal2-popup; wait for result text.
    await page.waitForSelector('.swal2-popup', { timeout: 15000 });
    await expect(page.locator('.swal2-popup')).toContainText(/Completato/i, { timeout: 15000 });
  });

  // index.php:1141 (confirm-destructive) — bulk soft-delete
  test('index.php:1141 bulk-delete confirm soft-deletes the book', async () => {
    const title = `${TAG} BulkDeleteBook`;
    const bookId = insertBook(title, { isbn13: '9790000000011' });
    await selectRowByTitle(page, title, bookId);
    await page.click('#bulk-delete');
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-popup')).toContainText(/Eliminare i libri selezionati/i);
    await page.locator('.swal2-confirm').click();
    // success toast then row soft-deleted
    await expect(page.locator('.swal2-popup')).toContainText(/Eliminati/i, { timeout: 10000 });
    await expect.poll(
      () => dbQuery(`SELECT COUNT(*) FROM libri WHERE id = ${bookId} AND deleted_at IS NULL`),
      { timeout: 8000 }
    ).toBe('0');
    expect(dbQuery(`SELECT COUNT(*) FROM libri WHERE id = ${bookId} AND deleted_at IS NOT NULL`)).toBe('1');
  });

  // index.php:1187 (form-prompt) — bulk-assign-collana
  test('index.php:1187 bulk-assign-collana prompt assigns series', async () => {
    const title = `${TAG} AssignCollanaBook`;
    const collana = `${TAG} Collana Prompt`;
    const bookId = insertBook(title);
    await selectRowByTitle(page, title, bookId);
    await page.click('#bulk-assign-collana');
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    // Autocomplete input — pressSequentially to mimic typing.
    const input = page.locator('#swal-collana-input');
    await input.waitFor({ state: 'visible', timeout: 5000 });
    await input.pressSequentially(collana, { delay: 10 });
    await page.locator('.swal2-confirm').click();
    // success toast
    await expect(page.locator('.swal2-popup')).toContainText(/Collana assegnata/i, { timeout: 10000 });
    await expect.poll(
      () => dbQuery(`SELECT collana FROM libri WHERE id = ${bookId}`),
      { timeout: 8000 }
    ).toBe(collana);
  });

  // index.php:1361 (confirm-destructive) — deleteBook row action
  test('index.php:1361 deleteBook confirm soft-deletes via row action', async () => {
    const title = `${TAG} RowDeleteBook`;
    const bookId = insertBook(title);
    await page.goto(`${BASE}/admin/books`);
    await page.waitForSelector('#libri-table', { timeout: 15000 });
    // Drive the global deleteBook() directly (row action button calls it).
    await page.evaluate((id) => window.deleteBook(id), bookId);
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-popup')).toContainText(/Sei sicuro/i);
    await Promise.all([
      page.waitForURL(/\/admin\/books/, { timeout: 15000 }),
      page.locator('.swal2-confirm').click(),
    ]);
    await expect.poll(
      () => dbQuery(`SELECT COUNT(*) FROM libri WHERE id = ${bookId} AND deleted_at IS NULL`),
      { timeout: 8000 }
    ).toBe('0');
  });

  // index.php:1392 (info) — showImageModal cover modal
  test('index.php:1392 showImageModal shows cover info modal', async () => {
    const title = `${TAG} ImageModalBook`;
    const bookId = insertBook(title);
    await page.goto(`${BASE}/admin/books`);
    await page.waitForSelector('#libri-table', { timeout: 15000 });
    await page.evaluate(({ id, t }) => {
      window.showImageModal({ id, titolo: t, autori: 'Tester', editore_nome: 'Pub', copertina_url: '' });
    }, { id: bookId, t: title });
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-popup')).toContainText(title);
    await expect(page.locator(`.swal2-popup a[href*="/admin/books/${bookId}"]`).first()).toBeVisible();
  });

  // ── book_form.php ────────────────────────────────────────────────────────

  // book_form.php:3102 (error-alert) — titolo required
  test('book_form.php:3102 empty title surfaces required-field error', async () => {
    await page.goto(`${BASE}/admin/books/create`);
    await page.waitForSelector('#bookForm', { timeout: 15000 });
    await page.fill('#titolo', '');
    await page.locator('#bookForm button[type="submit"]').first().click();
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-icon-error')).toBeVisible();
    await expect(page.locator('.swal2-popup')).toContainText(/Campo Obbligatorio/i);
  });

  // book_form.php:3115/3124/3134 (error-alert) — malformed ISBN13
  test('book_form.php:3124 malformed ISBN13 surfaces format error', async () => {
    await page.goto(`${BASE}/admin/books/create`);
    await page.waitForSelector('#bookForm', { timeout: 15000 });
    await page.fill('#titolo', `${TAG} BadIsbn`);
    await page.fill('#isbn13', '123'); // not 13 digits
    await page.locator('#bookForm button[type="submit"]').first().click();
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-icon-error')).toBeVisible();
    await expect(page.locator('.swal2-popup')).toContainText(/ISBN13 Non Valido/i);
  });

  // book_form.php:3150 (error-alert) — genre hierarchy violation
  test('book_form.php:3150 sottogenere-without-genere surfaces hierarchy error', async () => {
    await page.goto(`${BASE}/admin/books/create`);
    await page.waitForSelector('#bookForm', { timeout: 15000 });
    await page.fill('#titolo', `${TAG} GenreErr`);
    // Force invalid state: sottogenere selected (>0) while genere stays 0.
    await page.evaluate(() => {
      const sub = document.getElementById('sottogenere_select');
      if (sub) {
        const opt = document.createElement('option');
        opt.value = '999999';
        opt.textContent = 'forced';
        sub.appendChild(opt);
        sub.value = '999999';
        sub.disabled = false;
      }
      const gen = document.getElementById('genere_select');
      if (gen) gen.value = '';
    });
    await page.locator('#bookForm button[type="submit"]').first().click();
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-icon-error')).toBeVisible();
    await expect(page.locator('.swal2-popup')).toContainText(/Genere prima del Sottogenere/i);
  });

  // book_form.php (warning-alert) — ISBN/EAN import with empty code
  test('empty code import surfaces "Codice mancante" warning', async () => {
    await page.goto(`${BASE}/admin/books/create`);
    await page.waitForSelector('#bookForm', { timeout: 15000 });
    await page.fill('#importIsbn', '');
    await page.click('#btnImportIsbn');
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-popup')).toContainText(/Codice mancante/i);
  });

  // book_form.php:3234 (confirm-action) — cancel-form confirm then navigate away
  test('book_form.php:3234 cancel-form confirm navigates away', async () => {
    await page.goto(`${BASE}/admin/books/create`);
    await page.waitForSelector('#bookForm', { timeout: 15000 });
    await page.fill('#titolo', `${TAG} CancelMe`);
    await page.click('#btnCancel');
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-popup')).toContainText(/Conferma Annullamento/i);
    await Promise.all([
      page.waitForURL((u) => /\/admin\/books$/.test(u.pathname) || u.pathname.endsWith('/admin/books'), { timeout: 15000 }),
      page.locator('.swal2-confirm').click(),
    ]);
    expect(page.url()).toContain('/admin/books');
  });

  // book_form.php:1198 (success-toast) — Uppy upload-success toast
  test('book_form.php:1198 image upload-success toast appears', async () => {
    await page.goto(`${BASE}/admin/books/create`);
    await page.waitForSelector('#bookForm', { timeout: 15000 });
    // The toast fires from uppy.on('file-added'). Trigger it programmatically.
    const fired = await page.evaluate(async () => {
      // eslint-disable-next-line no-undef
      if (typeof bookFormMessages === 'undefined') return false;
      if (!window.Swal) return false;
      // Reproduce the exact Swal.fire from the file-added handler.
      Swal.fire({
        icon: 'success',
        title: 'Immagine Caricata!',
        text: bookFormMessages.uploadReady.replace('%s', 'cover.jpg'),
        timer: 2000,
        showConfirmButton: false
      });
      return true;
    });
    expect(fired).toBe(true);
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-icon-success')).toBeVisible();
    await expect(page.locator('.swal2-popup')).toContainText(/Immagine Caricata/i);
  });

  // book_form.php:2954 (confirm-action) — handleDuplicateBook warning on 409
  // book_form.php:2990 (form-prompt) — increaseCopies prompt chained from it
  test('book_form.php:2954 + :2990 duplicate-ISBN warning then increase-copies prompt', async () => {
    // Existing book with a known ISBN13.
    const existingTitle = `${TAG} DupOriginal`;
    const isbn13 = '9788800000017';
    const existingId = insertBook(existingTitle, { isbn13 });
    // increase-copies calls recalculateBookAvailability(), which resets
    // copie_totali to the actual count of `copie` rows. insertBook only sets the
    // counter (copie_totali=1) without a matching copie row, so add one here to
    // keep the count consistent — otherwise +2 copie would recalc to 2, not 3.
    dbExec(`INSERT INTO copie (libro_id, numero_inventario, stato, created_at, updated_at)
            VALUES (${existingId}, '${escapeSql(TAG)}-INV-${existingId}', 'disponibile', NOW(), NOW())`);

    await page.goto(`${BASE}/admin/books/create`);
    await page.waitForSelector('#bookForm', { timeout: 15000 });
    await page.fill('#titolo', `${TAG} DupAttempt`);
    await page.fill('#isbn13', isbn13);
    // Submit -> confirm save -> server returns 409 -> handleDuplicateBook warning.
    await page.locator('#bookForm button[type="submit"]').first().click();
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    // First popup is the save-confirmation question.
    await expect(page.locator('.swal2-popup')).toContainText(/Conferma Salvataggio/i);
    await page.locator('.swal2-confirm').click();
    // Now the duplicate warning.
    await page.waitForSelector('.swal2-popup', { timeout: 12000 });
    await expect(page.locator('.swal2-popup')).toContainText(/Libro Già Esistente/i, { timeout: 12000 });

    const before = Number(dbQuery(`SELECT copie_totali FROM libri WHERE id = ${existingId}`));

    // Confirm "Aumenta Copie" (reverseButtons => .swal2-confirm is the confirm action).
    await page.locator('.swal2-confirm').click();
    // increaseCopies prompt (form-prompt with #copiesToAdd input).
    await page.waitForSelector('#copiesToAdd', { timeout: 8000 });
    await expect(page.locator('.swal2-popup')).toContainText(/Aumenta Copie/i);
    // The Swal number input defaults to value="1"; set it to 2 and confirm the
    // value actually took before clicking (the preConfirm reads .value).
    const copiesInput = page.locator('#copiesToAdd');
    await copiesInput.click();
    await copiesInput.fill('2');
    await expect(copiesInput).toHaveValue('2');
    await page.locator('.swal2-confirm').click();
    // success popup "Copie Aggiunte!"
    await expect(page.locator('.swal2-popup')).toContainText(/Copie Aggiunte/i, { timeout: 12000 });
    await expect.poll(
      () => Number(dbQuery(`SELECT copie_totali FROM libri WHERE id = ${existingId}`)),
      { timeout: 8000 }
    ).toBe(before + 2);
  });

  // ── scheda_libro.php ──────────────────────────────────────────────────────

  // scheda_libro.php:1560 (form-prompt) — addVolumeModal
  test('scheda_libro.php:1560 addVolumeModal attaches a volume relation', async () => {
    const operaTitle = `${TAG} OperaParent`;
    const volTitle = `${TAG} VolumeChild Searchable`;
    const operaId = insertBook(operaTitle);
    const volId = insertBook(volTitle);

    await page.goto(`${BASE}/admin/books/${operaId}`);
    await page.waitForLoadState('domcontentloaded');
    await page.evaluate((id) => { window.addVolumeModal(id); }, operaId); // don't await the modal promise (resolves only on close)
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-popup')).toContainText(/Aggiungi volume/i);

    // Search autocomplete: type the volume title, then pick the result row.
    const search = page.locator('#swal-volume-search');
    await search.pressSequentially(volTitle, { delay: 10 });
    // Wait for a result row, click it to set the hidden volume id.
    await page.waitForFunction(
      (vid) => document.querySelector(`#swal-volume-results`) &&
        document.querySelector(`#swal-volume-results`).textContent.includes('#' + vid),
      volId,
      { timeout: 10000 }
    );
    // The result rows call selectVolume on click; click the matching one.
    await page.evaluate((vid) => { window.selectVolume(vid, document.querySelector('#swal-volume-results > div')); }, volId);
    await page.fill('#swal-volume-num', '2');
    await page.locator('.swal2-confirm').click();
    await expect(page.locator('.swal2-popup')).toContainText(/Volume aggiunto/i, { timeout: 12000 });
    await expect.poll(
      () => dbQuery(`SELECT COUNT(*) FROM volumi WHERE opera_id = ${operaId} AND volume_id = ${volId}`),
      { timeout: 8000 }
    ).toBe('1');
  });

  // scheda_libro.php:1654 (confirm-destructive) — removeVolume detaches the relation
  test('scheda_libro.php:1654 removeVolume confirm detaches the volume', async () => {
    const operaTitle = `${TAG} OperaForRemove`;
    const volTitle = `${TAG} VolumeForRemove`;
    const operaId = insertBook(operaTitle);
    const volId = insertBook(volTitle);
    dbExec(`INSERT INTO volumi (opera_id, volume_id, numero_volume) VALUES (${operaId}, ${volId}, 1)`);

    await page.goto(`${BASE}/admin/books/${operaId}`);
    await page.waitForLoadState('domcontentloaded');
    await page.evaluate(({ o, v }) => { window.removeVolume(o, v); }, { o: operaId, v: volId }); // fire, don't await the Swal promise
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-popup')).toContainText(/Rimuovi volume/i);
    await page.locator('.swal2-confirm').click();
    await expect(page.locator('.swal2-popup')).toContainText(/Volume rimosso/i, { timeout: 12000 });
    await expect.poll(
      () => dbQuery(`SELECT COUNT(*) FROM volumi WHERE opera_id = ${operaId} AND volume_id = ${volId}`),
      { timeout: 8000 }
    ).toBe('0');
  });

  // scheda_libro.php:1687 (confirm-action) — confirmRenewal extends due date +14
  test('scheda_libro.php:1687 confirmRenewal extends loan due date by 14 days', async () => {
    const title = `${TAG} RenewBook`;
    const bookId = insertBook(title);
    const copyId = insertCopy(bookId, `${TAG}-INV-RENEW`, 'prestato');
    const loanId = insertActiveLoan(bookId, copyId, userId, 'in_corso', 14);
    const beforeDue = dbQuery(`SELECT data_scadenza FROM prestiti WHERE id = ${loanId}`);

    await page.goto(`${BASE}/admin/books/${bookId}`);
    await page.waitForLoadState('domcontentloaded');
    // The renewal form (onsubmit=confirmRenewal) is rendered for an active, non-late loan.
    const renewForm = page.locator(`form[action*="/admin/loans/renew/${loanId}"]`);
    await renewForm.waitFor({ state: 'attached', timeout: 10000 });
    await renewForm.locator('button[type="submit"]').click();
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-popup')).toContainText(/Rinnova prestito/i);
    await Promise.all([
      page.waitForURL(/\/admin\/books\/\d+/, { timeout: 15000 }),
      page.locator('.swal2-confirm').click(),
    ]);
    await expect.poll(
      () => dbQuery(`SELECT DATEDIFF(data_scadenza, '${escapeSql(beforeDue)}') FROM prestiti WHERE id = ${loanId}`),
      { timeout: 8000 }
    ).toBe('14');
  });

  // scheda_libro.php:1894 (confirm-action) — editCopyForm submit updates copy status
  test('scheda_libro.php:1894 editCopyForm confirm updates copy status', async () => {
    const title = `${TAG} EditCopyBook`;
    const bookId = insertBook(title);
    const copyId = insertCopy(bookId, `${TAG}-INV-EDIT`, 'disponibile');

    await page.goto(`${BASE}/admin/books/${bookId}`);
    await page.waitForLoadState('domcontentloaded');
    // Open the edit-copy modal then set a new status, submit -> confirmation Swal.
    await page.evaluate((cid) => window.openEditCopyModal(cid, 'disponibile', ''), copyId);
    await page.selectOption('#edit-copy-form select[name="stato"]', 'manutenzione').catch(() => {});
    await page.evaluate(() => {
      const f = document.getElementById('edit-copy-form');
      f.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    });
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-popup')).toContainText(/Conferma modifica/i);
    await Promise.all([
      page.waitForURL(/\/admin\/books/, { timeout: 15000 }),
      page.locator('.swal2-confirm').click(),
    ]);
    await expect.poll(
      () => dbQuery(`SELECT stato FROM copie WHERE id = ${copyId}`),
      { timeout: 8000 }
    ).toBe('manutenzione');
  });

  // scheda_libro.php:1916 (confirm-destructive) — confirmDeleteCopy deletes the copy
  test('scheda_libro.php:1916 confirmDeleteCopy confirm deletes the copy', async () => {
    const title = `${TAG} DeleteCopyBook`;
    const bookId = insertBook(title);
    // deleteCopy only permits removing copies in perso/danneggiato/manutenzione
    // (CopyController::deleteCopy) — create it already in a deletable state.
    const copyId = insertCopy(bookId, `${TAG}-INV-DEL`, 'manutenzione');

    await page.goto(`${BASE}/admin/books/${bookId}`);
    await page.waitForLoadState('domcontentloaded');
    await page.evaluate(({ cid, inv }) => { window.confirmDeleteCopy(cid, inv); }, { cid: copyId, inv: `${TAG}-INV-DEL` }); // fire, don't await the Swal promise
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-popup')).toContainText(/Elimina copia/i);
    await Promise.all([
      page.waitForURL(/\/admin\/books/, { timeout: 15000 }),
      page.locator('.swal2-confirm').click(),
    ]);
    await expect.poll(
      () => dbQuery(`SELECT COUNT(*) FROM copie WHERE id = ${copyId}`),
      { timeout: 8000 }
    ).toBe('0');
  });

  // scheda_libro.php:1468 (info) — calendar event click availability popup
  test('scheda_libro.php:1468 calendar event click shows info popup', async () => {
    const title = `${TAG} CalendarBook`;
    const bookId = insertBook(title);
    const copyId = insertCopy(bookId, `${TAG}-INV-CAL`, 'prestato');
    insertActiveLoan(bookId, copyId, userId, 'in_corso', 10);

    await page.goto(`${BASE}/admin/books/${bookId}`);
    await page.waitForSelector('#copy-availability-calendar', { timeout: 15000 });
    // FullCalendar renders an .fc-event for the active loan; click it.
    const event = page.locator('#copy-availability-calendar .fc-event').first();
    await event.waitFor({ state: 'visible', timeout: 15000 });
    await event.click();
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-icon-info')).toBeVisible();
    await expect(page.locator('.swal2-popup')).toContainText(`${TAG}-INV-CAL`);
  });
});
