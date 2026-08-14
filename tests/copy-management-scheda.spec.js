// E2E: manage physical copies from the book summary page (admin).
//
// New capability: /admin/books/{id} always shows the "Copie Fisiche" section
// with an "Aggiungi copia" button (even when the book has no copies), a create
// modal, and per-copy status editing. A lost/damaged/maintenance copy is
// excluded from libri.copie_totali (DataIntegrity::recalculateBookAvailability),
// so marking a copy lost lowers the total.
//
// Real browser flow (per project rule): drives the actual modals and asserts the
// effect in the DB.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_PASS || !DB_NAME, 'E2E credentials not configured');

const sqlEscape = (s) => String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
function dbQuery(sql) {
  const args = ['-u', DB_USER, `-p${DB_PASS}`, DB_NAME, '-N', '-B', '-e', sql];
  if (DB_HOST) { args.splice(3, 0, '-h', DB_HOST); if (DB_PORT) args.splice(5, 0, '-P', DB_PORT); }
  else if (DB_SOCKET) { args.splice(3, 0, '-S', DB_SOCKET); }
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000 }).trim();
}

const RUN = Date.now().toString(36);
const TITLE = `CopyMgmt ${RUN}`;             // book that starts with one copy
const TITLE_EMPTY = `CopyMgmtEmpty ${RUN}`;  // book with zero copie rows
const TITLE_QUEUE = `CopyMgmtQueue ${RUN}`;  // zero-copy book with an active wait-list entry
const QUEUE_EMAIL = `copy-mgmt-${RUN}@example.test`;
let bookId = 0;
let emptyBookId = 0;
let queueBookId = 0;
let queueUserId = 0;

function cleanupByTitle() {
  const titles = [TITLE, TITLE_EMPTY, TITLE_QUEUE].map((title) => `'${sqlEscape(title)}'`).join(',');
  // Loans/reservations restrict book deletion; copies cascade with the book.
  dbQuery(`DELETE FROM prestiti WHERE libro_id IN (SELECT id FROM libri WHERE titolo IN (${titles}))`);
  dbQuery(`DELETE FROM prenotazioni WHERE libro_id IN (SELECT id FROM libri WHERE titolo IN (${titles}))`);
  dbQuery(`DELETE FROM libri WHERE titolo IN (${titles})`);
  dbQuery(`DELETE FROM utenti WHERE email='${sqlEscape(QUEUE_EMAIL)}'`);
}
const copieCount = (id) => Number(dbQuery(`SELECT COUNT(*) FROM copie WHERE libro_id=${id}`));
const copieTotali = (id) => Number(dbQuery(`SELECT copie_totali FROM libri WHERE id=${id}`));
const bookStato = (id) => dbQuery(`SELECT stato FROM libri WHERE id=${id}`);

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const email = page.locator('input[name="email"]');
  if (await email.isVisible({ timeout: 3000 }).catch(() => false)) {
    await email.fill(ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/.*(?:dashboard|admin).*/, { timeout: 15000 });
  }
}

// Open the "Aggiungi copia" modal, fill it, submit, wait for the reload.
async function addCopy(page, { inventario = '', stato = 'disponibile', note = '' } = {}) {
  await page.evaluate(() => window.openAddCopyModal());
  await expect(page.locator('#add-copy-modal')).toBeVisible();
  await page.fill('#add-copy-inventario', inventario);
  await page.selectOption('#add-copy-stato', stato);
  if (note) await page.fill('#add-copy-note', note);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    page.click('#add-copy-form button[type="submit"]'),
  ]);
}

// Edit a copy's status via its row modal (with the SweetAlert confirm).
async function editCopyStatus(page, copyId, stato, currentStato = 'disponibile') {
  await page.evaluate(({ id, current }) => window.openEditCopyModal(id, current, ''), { id: copyId, current: currentStato });
  await expect(page.locator('#edit-copy-modal')).toBeVisible();
  await page.selectOption('#edit-copy-stato', stato);
  await page.click('#edit-copy-form button[type="submit"]');
  const confirm = page.locator('.swal2-confirm');
  if (await confirm.isVisible({ timeout: 3000 }).catch(() => false)) {
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
      confirm.click(),
    ]);
  }
}

test.describe.serial('Copy management from the book summary (#238/#351)', () => {
  test.beforeAll(async () => {
    cleanupByTitle();
    dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at) VALUES ('${sqlEscape(TITLE)}', 1, 1, NOW(), NOW())`);
    bookId = Number(dbQuery(`SELECT id FROM libri WHERE titolo='${sqlEscape(TITLE)}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`));
    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato, created_at) VALUES (${bookId}, 'CM-${RUN}-C1', 'disponibile', NOW())`);
    // A second book with NO copie rows (legacy/never-loaned) for the empty-state test.
    dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at) VALUES ('${sqlEscape(TITLE_EMPTY)}', 1, 1, NOW(), NOW())`);
    emptyBookId = Number(dbQuery(`SELECT id FROM libri WHERE titolo='${sqlEscape(TITLE_EMPTY)}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`));

    dbQuery(`INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili, created_at, updated_at) VALUES ('${sqlEscape(TITLE_QUEUE)}', 'non_disponibile', 0, 0, NOW(), NOW())`);
    queueBookId = Number(dbQuery(`SELECT id FROM libri WHERE titolo='${sqlEscape(TITLE_QUEUE)}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`));
    dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata, privacy_accettata) VALUES ('CM-${RUN}', 'Copy', 'Queue', '${sqlEscape(QUEUE_EMAIL)}', 'not-used', 'attivo', 'standard', 1, 1)`);
    queueUserId = Number(dbQuery(`SELECT id FROM utenti WHERE email='${sqlEscape(QUEUE_EMAIL)}' LIMIT 1`));
    dbQuery(`INSERT INTO prenotazioni (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta, data_scadenza_prenotazione, queue_position, stato) VALUES (${queueBookId}, ${queueUserId}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), DATE_ADD(NOW(), INTERVAL 14 DAY), 1, 'attiva')`);
  });
  test.afterAll(() => cleanupByTitle());

  test('1. book summary shows the Copie Fisiche section and Aggiungi copia button', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/${bookId}`);
    await expect(page.locator('text=Copie Fisiche')).toBeVisible();
    await expect(page.locator('button:has-text("Aggiungi copia")').first()).toBeVisible();
  });

  test('2. a book with no copies shows the empty-state and the add button', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/${emptyBookId}`);
    await expect(page.locator('text=Nessuna copia fisica')).toBeVisible();
    await expect(page.locator('button:has-text("Aggiungi copia")').first()).toBeVisible();
    expect(copieCount(emptyBookId)).toBe(0);
  });

  test('3. add a copy with auto-assigned inventory → +1 copy, copie_totali +1', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/${bookId}`);
    const before = copieCount(bookId);
    await addCopy(page, { stato: 'disponibile', note: 'auto' });
    expect(copieCount(bookId)).toBe(before + 1);
    expect(copieTotali(bookId)).toBe(before + 1);
    // auto code follows the {base}-C{N} family
    expect(dbQuery(`SELECT numero_inventario FROM copie WHERE libro_id=${bookId} AND note='auto' LIMIT 1`)).toMatch(/-C\d+$/);
  });

  test('4. add a copy with an explicit inventory number', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/${bookId}`);
    const code = `CM-${RUN}-EXPLICIT`;
    await addCopy(page, { inventario: code, stato: 'disponibile', note: 'explicit' });
    expect(dbQuery(`SELECT COUNT(*) FROM copie WHERE libro_id=${bookId} AND numero_inventario='${code}'`)).toBe('1');
  });

  test('5. a duplicate inventory number is rejected (no new copy)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/${bookId}`);
    const dup = `CM-${RUN}-EXPLICIT`; // created in test 4
    const before = copieCount(bookId);
    await addCopy(page, { inventario: dup, stato: 'disponibile', note: 'dup' });
    expect(copieCount(bookId)).toBe(before); // unchanged
    await expect(page.locator('text=Esiste già una copia con questo numero di inventario')).toBeVisible();
  });

  test('6. a copy created as "danneggiato" does NOT raise copie_totali', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/${bookId}`);
    const beforeCount = copieCount(bookId);
    const beforeTotal = copieTotali(bookId);
    await addCopy(page, { stato: 'danneggiato', note: 'born-damaged' });
    expect(copieCount(bookId)).toBe(beforeCount + 1);      // the row exists
    expect(copieTotali(bookId)).toBe(beforeTotal);          // but total unchanged
  });

  test('7. editing a copy to "perso" lowers copie_totali', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/${bookId}`);
    const targetId = Number(dbQuery(`SELECT id FROM copie WHERE libro_id=${bookId} AND stato='disponibile' ORDER BY id DESC LIMIT 1`));
    const beforeTotal = copieTotali(bookId);
    await editCopyStatus(page, targetId, 'perso');
    expect(dbQuery(`SELECT stato FROM copie WHERE id=${targetId}`)).toBe('perso');
    expect(copieTotali(bookId)).toBe(beforeTotal - 1);
  });

  test('8. damaged then available round-trips copie_totali', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/${bookId}`);
    const targetId = Number(dbQuery(`SELECT id FROM copie WHERE libro_id=${bookId} AND stato='disponibile' ORDER BY id DESC LIMIT 1`));
    const start = copieTotali(bookId);
    await editCopyStatus(page, targetId, 'danneggiato');
    expect(copieTotali(bookId)).toBe(start - 1);
    await page.goto(`${BASE}/admin/books/${bookId}`);
    await editCopyStatus(page, targetId, 'disponibile');
    expect(copieTotali(bookId)).toBe(start);
  });

  test('9. an available copy is protected from deletion; an out-of-circulation one is deletable', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/${bookId}`);
    const targetId = Number(dbQuery(`SELECT id FROM copie WHERE libro_id=${bookId} AND stato='disponibile' ORDER BY id DESC LIMIT 1`));

    // Guard: an available copy cannot be deleted directly.
    await page.evaluate((id) => window.confirmDeleteCopy(id, 'X'), targetId);
    let confirm = page.locator('.swal2-confirm');
    await expect(confirm).toBeVisible({ timeout: 3000 });
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
      confirm.click(),
    ]);
    expect(dbQuery(`SELECT COUNT(*) FROM copie WHERE id=${targetId}`)).toBe('1'); // still there
    await expect(page.locator('text=Puoi eliminare solo copie perse')).toBeVisible();

    // Mark it damaged, then it can be deleted → the row count drops by one.
    await page.goto(`${BASE}/admin/books/${bookId}`);
    await editCopyStatus(page, targetId, 'danneggiato');
    const beforeCount = copieCount(bookId);
    await page.goto(`${BASE}/admin/books/${bookId}`);
    await page.evaluate((id) => window.confirmDeleteCopy(id, 'X'), targetId);
    confirm = page.locator('.swal2-confirm');
    await expect(confirm).toBeVisible({ timeout: 3000 });
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
      confirm.click(),
    ]);
    expect(copieCount(bookId)).toBe(beforeCount - 1);
    expect(dbQuery(`SELECT COUNT(*) FROM copie WHERE id=${targetId}`)).toBe('0');
  });

  test('10. when every copy is out of circulation the book becomes non_disponibile', async ({ page }) => {
    await loginAsAdmin(page);
    // Reduce to a single available copy, then lose it.
    dbQuery(`DELETE FROM copie WHERE libro_id=${bookId}`);
    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato, created_at) VALUES (${bookId}, 'CM-${RUN}-LAST', 'disponibile', NOW())`);
    await page.goto(`${BASE}/admin/books/${bookId}`);
    const targetId = Number(dbQuery(`SELECT id FROM copie WHERE libro_id=${bookId} ORDER BY id DESC LIMIT 1`));
    await editCopyStatus(page, targetId, 'perso');
    expect(copieTotali(bookId)).toBe(0);
    expect(bookStato(bookId)).toBe('non_disponibile');
  });

  test('11. restoration and transfer states round-trip through add/edit and render readable badges', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/${emptyBookId}`);

    await addCopy(page, { stato: 'in_restauro', note: 'restoration-roundtrip' });
    const restorationId = Number(dbQuery(`SELECT id FROM copie WHERE libro_id=${emptyBookId} AND note='restoration-roundtrip' LIMIT 1`));
    await expect(page.locator('#physical-copies')).toContainText('In restauro');
    await editCopyStatus(page, restorationId, 'disponibile', 'in_restauro');
    expect(dbQuery(`SELECT stato FROM copie WHERE id=${restorationId}`)).toBe('disponibile');

    await page.goto(`${BASE}/admin/books/${emptyBookId}`);
    await addCopy(page, { stato: 'in_trasferimento', note: 'transfer-readable' });
    expect(dbQuery(`SELECT stato FROM copie WHERE libro_id=${emptyBookId} AND note='transfer-readable' LIMIT 1`)).toBe('in_trasferimento');
    await expect(page.locator('#physical-copies')).toContainText('In trasferimento');
  });

  test('12. an inventory value emptied by sanitisation falls back to an automatic code', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/${emptyBookId}`);
    await page.evaluate(() => window.openAddCopyModal());
    await page.locator('#add-copy-inventario').evaluate((el) => { el.value = '\u0001\u0002'; });
    await page.fill('#add-copy-note', 'sanitized-auto');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
      page.click('#add-copy-form button[type="submit"]'),
    ]);
    const code = dbQuery(`SELECT numero_inventario FROM copie WHERE libro_id=${emptyBookId} AND note='sanitized-auto' LIMIT 1`);
    expect(code).toMatch(/-C\d+$/);
  });

  test('13. adding an available copy promotes the wait-list and links the loan to that physical copy', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/${queueBookId}`);
    await addCopy(page, { stato: 'disponibile', note: 'queue-capacity' });

    expect(dbQuery(`SELECT stato FROM prenotazioni WHERE libro_id=${queueBookId} AND utente_id=${queueUserId}`)).toBe('completata');
    const linked = dbQuery(`SELECT CONCAT(p.copia_id, ':', c.libro_id, ':', p.origine, ':', p.stato) FROM prestiti p JOIN copie c ON c.id=p.copia_id WHERE p.libro_id=${queueBookId} AND p.utente_id=${queueUserId} ORDER BY p.id DESC LIMIT 1`);
    expect(linked).toMatch(new RegExp(`^\\d+:${queueBookId}:prenotazione:pendente$`));
    expect(copieTotali(queueBookId)).toBe(1);
    expect(Number(dbQuery(`SELECT copie_disponibili FROM libri WHERE id=${queueBookId}`))).toBe(0);
  });
});
