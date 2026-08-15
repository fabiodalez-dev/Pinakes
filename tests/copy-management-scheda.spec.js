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
  // Pass the password via MYSQL_PWD, never -p… on argv (visible in the CI process list).
  const args = ['-N', '-B', '-e', sql];
  if (DB_HOST) { args.push('-h', DB_HOST); if (DB_PORT) args.push('-P', DB_PORT); }
  else if (DB_SOCKET) { args.push('-S', DB_SOCKET); }
  args.push('-u', DB_USER, DB_NAME);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
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
    await expect(page.locator('text=Puoi eliminare solo copie fuori circolazione')).toBeVisible();

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

  // ---- PR #356 review fixes ---------------------------------------------

  // #2: in_restauro / in_trasferimento are out-of-circulation states and must be
  // deletable from the UI (with no loan history), like perso/danneggiato/manutenzione.
  test('14. copies in_restauro and in_trasferimento are deletable from the UI (#356)', async ({ page }) => {
    await loginAsAdmin(page);
    const delTitle = `CopyMgmtDel ${RUN}`;
    dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at) VALUES ('${sqlEscape(delTitle)}', 0, 0, NOW(), NOW())`);
    const delBookId = Number(dbQuery(`SELECT id FROM libri WHERE titolo='${sqlEscape(delTitle)}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`));
    try {
      for (const stato of ['in_restauro', 'in_trasferimento']) {
        dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato, created_at) VALUES (${delBookId}, 'DEL-${RUN}-${stato}', '${stato}', NOW())`);
        const copyId = Number(dbQuery(`SELECT id FROM copie WHERE libro_id=${delBookId} AND stato='${stato}' ORDER BY id DESC LIMIT 1`));
        await page.goto(`${BASE}/admin/books/${delBookId}`);
        // The row exposes a delete button (view $canDelete now includes the state).
        await expect(page.locator(`button[onclick^="confirmDeleteCopy(${copyId}"]`)).toBeVisible();
        await page.evaluate((id) => window.confirmDeleteCopy(id, 'X'), copyId);
        const confirm = page.locator('.swal2-confirm');
        await expect(confirm).toBeVisible({ timeout: 3000 });
        await Promise.all([
          page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
          confirm.click(),
        ]);
        // The controller allow-list now permits the delete → the row is gone.
        expect(dbQuery(`SELECT COUNT(*) FROM copie WHERE id=${copyId}`)).toBe('0');
      }
    } finally {
      dbQuery(`DELETE FROM libri WHERE id=${delBookId}`);
    }
  });

  // #3: a zero-copy book (now a valid state) must pluralise the header as "0 copie".
  test('15. a zero-copy book pluralises the header count as "0 copie" (#356)', async ({ page }) => {
    await loginAsAdmin(page);
    const zeroTitle = `CopyMgmtZero ${RUN}`;
    dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at) VALUES ('${sqlEscape(zeroTitle)}', 0, 0, NOW(), NOW())`);
    const zeroId = Number(dbQuery(`SELECT id FROM libri WHERE titolo='${sqlEscape(zeroTitle)}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`));
    try {
      await page.goto(`${BASE}/admin/books/${zeroId}`);
      const header = page.locator('#physical-copies');
      await expect(header).toContainText('0 copie');
      expect(await header.innerText()).not.toContain('0 copia');
    } finally {
      dbQuery(`DELETE FROM libri WHERE id=${zeroId}`);
    }
  });

  // #5: copie_totali is read-only + visibly disabled on edit, editable on create.
  test('16. copie_totali is read-only and greyed on edit, editable on create (#356)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/edit/${bookId}`);
    const input = page.locator('#copie_totali');
    await expect(input).toHaveAttribute('readonly', '');
    expect(await input.getAttribute('class')).toContain('cursor-not-allowed');
    await page.goto(`${BASE}/admin/books/create`);
    expect(await page.locator('#copie_totali').getAttribute('readonly')).toBeNull();
  });

  // #6: the Add-copy modal title matches the button label ("Aggiungi copia").
  test('17. the Add-copy modal title matches the button label (#356)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/${bookId}`);
    await page.evaluate(() => window.openAddCopyModal());
    await expect(page.locator('#add-copy-modal')).toBeVisible();
    expect((await page.locator('#add-copy-modal h3').first().innerText()).trim()).toBe('Aggiungi copia');
    // The old mixed-case "Aggiungi Copia" string is gone from the modal.
    expect(await page.locator('#add-copy-modal').innerText()).not.toContain('Aggiungi Copia');
  });

  // #1: copie_totali is derived server-side on edit — a crafted POST that bypasses
  // the client-side readonly and sends 0 must NOT delete the book's copies.
  test('18. a tampered copie_totali=0 on edit does not delete copies (#356)', async ({ page }) => {
    await loginAsAdmin(page);
    const tamperTitle = `CopyMgmtTamper ${RUN}`;
    dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at) VALUES ('${sqlEscape(tamperTitle)}', 2, 2, NOW(), NOW())`);
    const tId = Number(dbQuery(`SELECT id FROM libri WHERE titolo='${sqlEscape(tamperTitle)}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`));
    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato, created_at) VALUES (${tId}, 'TMP-${RUN}-C1', 'disponibile', NOW()), (${tId}, 'TMP-${RUN}-C2', 'disponibile', NOW())`);
    try {
      await page.goto(`${BASE}/admin/books/edit/${tId}`);
      const marker = `${tamperTitle} EDITED`;
      // Simulate a crafted POST: strip the readonly guard and zero the field, plus
      // a benign title change so we can prove the update ran to completion.
      await page.evaluate((title) => {
        const el = document.getElementById('copie_totali');
        el.removeAttribute('readonly');
        el.value = '0';
        const t = document.querySelector('input[name="titolo"]');
        if (t) t.value = title;
      }, marker);
      await page.click('#bookForm button[type="submit"]');
      const confirm = page.locator('.swal2-confirm');
      if (await confirm.isVisible({ timeout: 3000 }).catch(() => false)) {
        await Promise.all([
          page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
          confirm.click(),
        ]);
      }
      // The benign edit persisted (update ran) …
      expect(dbQuery(`SELECT titolo FROM libri WHERE id=${tId}`)).toBe(marker);
      // … but the copies survived and the derived total is intact.
      expect(copieCount(tId)).toBe(2);
      expect(copieTotali(tId)).toBe(2);
    } finally {
      dbQuery(`DELETE FROM libri WHERE id=${tId}`);
    }
  });

  // (The bulk-import dialog wording "Copie in circolazione" is a static view
  // string covered by the locale-parity gate; the increase-copies behaviour
  // itself is exercised by test 23, so the brittle page.content() source-check
  // that used to live here was removed.)

  // CodeRabbit follow-up: store() must reject a non-integer copie_totali before
  // casting — "7abc" must NOT be coerced to 7 copies (nor an array to 1).
  test('20. store() rejects a non-integer copie_totali → zero copies, not coerced (#356)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/create`);
    const csrf = await page.locator('#bookForm input[name="csrf_token"]').first().inputValue();
    const badTitle = `CopyMgmtBadCount ${RUN}`;
    // A crafted POST that bypasses the numeric input and sends a non-integer.
    const resp = await page.request.post(`${BASE}/admin/books/create`, {
      form: { csrf_token: csrf, titolo: badTitle, copie_totali: '7abc' },
    });
    expect(resp.status()).toBeLessThan(400);
    const badId = Number(dbQuery(`SELECT id FROM libri WHERE titolo='${sqlEscape(badTitle)}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`));
    try {
      expect(badId).toBeGreaterThan(0);          // the book was created …
      expect(copieCount(badId)).toBe(0);         // … with ZERO copies (not 7)
      expect(copieTotali(badId)).toBe(0);
    } finally {
      if (badId > 0) dbQuery(`DELETE FROM libri WHERE id=${badId}`);
    }
  });

  test('21. add-copy rejects array-shaped status, note and inventory inputs (#356)', async ({ page }) => {
    await loginAsAdmin(page);
    for (const field of ['stato', 'note', 'numero_inventario']) {
      await page.goto(`${BASE}/admin/books/${emptyBookId}`);
      const before = copieCount(emptyBookId);
      await page.evaluate(() => window.openAddCopyModal());
      await expect(page.locator('#add-copy-modal')).toBeVisible();
      await page.locator(`#add-copy-form [name="${field}"]`).evaluate((el) => {
        el.name = `${el.name}[]`;
      });
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
        page.click('#add-copy-form button[type="submit"]'),
      ]);
      const errorAlert = page.getByRole('alert');
      await expect(errorAlert).toBeVisible();
      await expect(errorAlert).toContainText('Impossibile aggiungere la copia');
      expect(copieCount(emptyBookId)).toBe(before);
    }
  });

  test('22. edit-copy normalizes control characters and caps notes at 500 characters', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/${bookId}`);
    const target = dbQuery(`SELECT id, stato FROM copie WHERE libro_id=${bookId} ORDER BY id LIMIT 1`).split('\t');
    const targetId = Number(target[0]);
    const currentStatus = target[1];
    await page.evaluate(
      ({ id, status }) => window.openEditCopyModal(id, status, ''),
      { id: targetId, status: currentStatus }
    );
    await expect(page.locator('#edit-copy-modal')).toBeVisible();
    await page.locator('#edit-copy-note').evaluate((element) => {
      element.value = `  ${'x'.repeat(510)}\u0001  `;
      element.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await page.click('#edit-copy-form button[type="submit"]');
    const confirm = page.locator('.swal2-confirm');
    if (await confirm.isVisible({ timeout: 3000 }).catch(() => false)) {
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
        confirm.click(),
      ]);
    }
    expect(dbQuery(`SELECT note FROM copie WHERE id=${targetId}`)).toBe('x'.repeat(500));
  });

  // M2: /api/libri/{id}/increase-copies must allocate collision-free codes and stay
  // atomic. With an out-of-circulation copy present, copie_totali excludes it, so the
  // old "{base}-C{copie_totali+i}" scheme reused an existing code → 1062 → 500 with an
  // inflated counter. The allocator + transaction fix that.
  test('23. increase-copies allocates collision-free codes with an out-of-circulation copy (M2)', async ({ page }) => {
    await loginAsAdmin(page);
    const base = `M2-${RUN}`;
    const title = `CopyMgmtM2 ${RUN}`;
    // Book whose inventory base matches its copies; C2 is "perso" so copie_totali=1
    // while the code -C2 already exists — the exact collision precondition.
    dbQuery(`INSERT INTO libri (titolo, numero_inventario, copie_totali, copie_disponibili, created_at, updated_at) VALUES ('${sqlEscape(title)}', '${base}', 1, 1, NOW(), NOW())`);
    const id = Number(dbQuery(`SELECT id FROM libri WHERE titolo='${sqlEscape(title)}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`));
    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato, created_at) VALUES (${id}, '${base}-C1', 'disponibile', NOW()), (${id}, '${base}-C2', 'perso', NOW())`);
    try {
      await page.goto(`${BASE}/admin/books/edit/${id}`);
      const csrf = await page.locator('#bookForm input[name="csrf_token"]').first().inputValue();
      const resp = await page.request.post(`${BASE}/api/libri/${id}/increase-copies`, {
        headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
        data: { copies: 1 },
      });
      // Old code raised an unhandled 1062 → 500; the fix returns 200.
      expect(resp.status()).toBe(200);
      // The new copy skipped the colliding -C2 and took the next free code.
      expect(copieCount(id)).toBe(3);
      expect(dbQuery(`SELECT numero_inventario FROM copie WHERE libro_id=${id} AND stato='disponibile' AND numero_inventario NOT IN ('${base}-C1') ORDER BY id DESC LIMIT 1`)).toBe(`${base}-C3`);
      // No duplicate codes, and the counter is derived (C1 + C3 available; C2 excluded).
      expect(Number(dbQuery(`SELECT COUNT(*) - COUNT(DISTINCT numero_inventario) FROM copie WHERE libro_id=${id}`))).toBe(0);
      expect(copieTotali(id)).toBe(2);
    } finally {
      dbQuery(`DELETE FROM libri WHERE id=${id}`);
    }
  });

  test('24. increase-copies respects the case/accent-insensitive inventory collation', async ({ page }) => {
    await loginAsAdmin(page);
    const base = `COLL-${RUN}-CAFÉ`;
    const existingCode = `coll-${RUN}-cafe-c1`;
    const title = `CopyMgmtCollation ${RUN}`;
    dbQuery(`INSERT INTO libri (titolo, numero_inventario, copie_totali, copie_disponibili) VALUES ('${sqlEscape(title)}', '${sqlEscape(base)}', 1, 1)`);
    const id = Number(dbQuery(`SELECT id FROM libri WHERE titolo='${sqlEscape(title)}' ORDER BY id DESC LIMIT 1`));
    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${id}, '${sqlEscape(existingCode)}', 'disponibile')`);
    try {
      await page.goto(`${BASE}/admin/books/edit/${id}`);
      const csrf = await page.locator('#bookForm input[name="csrf_token"]').first().inputValue();
      const resp = await page.request.post(`${BASE}/api/libri/${id}/increase-copies`, {
        headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
        data: { copies: 1 },
      });
      expect(resp.status()).toBe(200);
      expect(dbQuery(`SELECT numero_inventario FROM copie WHERE libro_id=${id} ORDER BY id`)).toBe(`${existingCode}\n${base}-C2`);
    } finally {
      dbQuery(`DELETE FROM libri WHERE id=${id}`);
    }
  });

  test('25. increase-copies assigns a blocked copy-less HOLDING before exposing capacity', async ({ page }) => {
    await loginAsAdmin(page);
    const title = `CopyMgmtBlockedHold ${RUN}`;
    const email = `copy-mgmt-blocked-${RUN}@example.test`;
    dbQuery(`INSERT INTO libri (titolo, numero_inventario, stato, copie_totali, copie_disponibili) VALUES ('${sqlEscape(title)}', 'BH-${RUN}', 'non_disponibile', 0, 0)`);
    const id = Number(dbQuery(`SELECT id FROM libri WHERE titolo='${sqlEscape(title)}' ORDER BY id DESC LIMIT 1`));
    dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata, privacy_accettata) VALUES ('BH-${RUN}', 'Blocked', 'Holder', '${sqlEscape(email)}', 'not-used', 'attivo', 'standard', 1, 1)`);
    const userId = Number(dbQuery(`SELECT id FROM utenti WHERE email='${sqlEscape(email)}'`));
    dbQuery(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (${id}, NULL, ${userId}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'prenotato', 'prenotazione', 1)`);
    const loanId = Number(dbQuery(`SELECT id FROM prestiti WHERE libro_id=${id} AND utente_id=${userId} ORDER BY id DESC LIMIT 1`));
    try {
      await page.goto(`${BASE}/admin/books/edit/${id}`);
      const csrf = await page.locator('#bookForm input[name="csrf_token"]').first().inputValue();
      const resp = await page.request.post(`${BASE}/api/libri/${id}/increase-copies`, {
        headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
        data: { copies: 1 },
      });
      expect(resp.status()).toBe(200);
      const linked = dbQuery(`SELECT CONCAT(p.copia_id, ':', c.libro_id, ':', c.stato) FROM prestiti p JOIN copie c ON c.id=p.copia_id WHERE p.id=${loanId}`);
      expect(linked).toMatch(new RegExp(`^\\d+:${id}:prenotato$`));
      expect(Number(dbQuery(`SELECT copie_disponibili FROM libri WHERE id=${id}`))).toBe(0);
    } finally {
      dbQuery(`DELETE FROM prestiti WHERE libro_id=${id}`);
      dbQuery(`DELETE FROM libri WHERE id=${id}`);
      dbQuery(`DELETE FROM utenti WHERE id=${userId}`);
    }
  });

  test('26. increase-copies rejects non-scalar, fractional and oversized batches', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/edit/${emptyBookId}`);
    const csrf = await page.locator('#bookForm input[name="csrf_token"]').first().inputValue();
    const before = copieCount(emptyBookId);
    for (const copies of [['1'], '1.5', 101]) {
      const resp = await page.request.post(`${BASE}/api/libri/${emptyBookId}/increase-copies`, {
        headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
        data: { copies },
      });
      expect(resp.status()).toBe(400);
    }
    expect(copieCount(emptyBookId)).toBe(before);
  });

  test('27. a reserved copy accepts note edits but cannot cancel its HOLDING via copy status', async ({ page }) => {
    await loginAsAdmin(page);
    const title = `CopyMgmtReservedNote ${RUN}`;
    const email = `copy-mgmt-reserved-${RUN}@example.test`;
    dbQuery(`INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES ('${sqlEscape(title)}', 'prenotato', 1, 0)`);
    const id = Number(dbQuery(`SELECT id FROM libri WHERE titolo='${sqlEscape(title)}' ORDER BY id DESC LIMIT 1`));
    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${id}, 'RN-${RUN}-C1', 'prenotato')`);
    const copyId = Number(dbQuery(`SELECT id FROM copie WHERE libro_id=${id}`));
    dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata, privacy_accettata) VALUES ('RN-${RUN}', 'Reserved', 'Holder', '${sqlEscape(email)}', 'not-used', 'attivo', 'standard', 1, 1)`);
    const userId = Number(dbQuery(`SELECT id FROM utenti WHERE email='${sqlEscape(email)}'`));
    dbQuery(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (${id}, ${copyId}, ${userId}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'prenotato', 'prenotazione', 1)`);
    try {
      await page.goto(`${BASE}/admin/books/${id}`);
      await page.evaluate((copy) => window.openEditCopyModal(copy, 'prenotato', ''), copyId);
      await expect(page.locator('#edit-copy-modal')).toBeVisible();
      await page.fill('#edit-copy-note', 'reserved-note-saved');
      await page.click('#edit-copy-form button[type="submit"]');
      let confirm = page.locator('.swal2-confirm');
      if (await confirm.isVisible({ timeout: 3000 }).catch(() => false)) {
        await Promise.all([
          page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
          confirm.click(),
        ]);
      }
      expect(dbQuery(`SELECT CONCAT(stato, ':', note) FROM copie WHERE id=${copyId}`)).toBe('prenotato:reserved-note-saved');

      await page.goto(`${BASE}/admin/books/${id}`);
      await editCopyStatus(page, copyId, 'disponibile', 'prenotato');
      expect(dbQuery(`SELECT stato FROM copie WHERE id=${copyId}`)).toBe('prenotato');
      expect(Number(dbQuery(`SELECT copia_id FROM prestiti WHERE libro_id=${id} AND utente_id=${userId} AND attivo=1`))).toBe(copyId);
      await expect(page.getByRole('alert')).toContainText('utilizza il sistema Prestiti');
    } finally {
      dbQuery(`DELETE FROM prestiti WHERE libro_id=${id}`);
      dbQuery(`DELETE FROM libri WHERE id=${id}`);
      dbQuery(`DELETE FROM utenti WHERE id=${userId}`);
    }
  });
});
