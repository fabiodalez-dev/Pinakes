// @ts-check
//
// E2E — Inventory stocktake sessions (plan §8, /admin/inventory).
//
// Everything a librarian does is clicked in the browser: open a session on a
// shelf, read codes with the keyboard (a wedge scanner types and presses
// Enter), upload a list, watch the live counters, filter "only my scans",
// close marking the missing copies as lost (admin only) or leaving them, take
// the PDF/CSV report, cancel a session. The DB is used only to provision the
// fixtures and to verify the effects.
//
// Self-provisions an admin, a staff operator, two shelves, three books and
// their copies (tokenised), and removes them in afterAll.

const { test, expect } = require('@playwright/test');

// 0.9.0 optional modules (Settings → Modules / Loans): this suite tests
// stocktake, so it switches it on for its run and puts back what was there.
const featureToggles = require('./helpers/feature-toggles');
let featureSnapshot;
test.beforeAll(async () => { featureSnapshot = await featureToggles.enableFeatures(['stocktake']); });
test.afterAll(async () => { await featureToggles.restoreFeatures(featureSnapshot); });
const { execFileSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || process.env.APP_URL || 'http://localhost:8081';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(!DB_USER || !DB_NAME, 'E2E DB credentials not configured (E2E_DB_USER, E2E_DB_NAME)');
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
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 15000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}
const S = (v) => "'" + String(v).replace(/'/g, "''") + "'";
const phpHash = (pw) => execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(pw)}, PASSWORD_DEFAULT);`], { encoding: 'utf-8' }).trim();

const RUN = crypto.randomBytes(4).toString('hex');
const TAG = `ST${RUN.toUpperCase()}`;
const PASS = 'Stocktake!2026';
const ADMIN = { email: `st-admin-${RUN}@stocktake.test.local`, id: 0 };
const STAFF = { email: `st-staff-${RUN}@stocktake.test.local`, id: 0 };
const code = (c) => `${TAG}-${c}`;
const fx = { shelfX: 0, shelfY: 0, books: {}, copies: {}, borrower: 0, loan: 0, sessions: [] };

function makeUser(user, role, name) {
  dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata, privacy_accettata, locale)
    VALUES (${S(TAG + role.slice(0, 2).toUpperCase())}, ${S(name)}, ${S(TAG)}, ${S(user.email)}, ${S(phpHash(PASS))}, ${S(role)}, 'attivo', 1, 1, 'it_IT')`);
  user.id = Number(dbQuery(`SELECT id FROM utenti WHERE email=${S(user.email)}`));
}

async function login(page, user) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', user.email);
  await page.fill('input[name="password"]', PASS);
  await page.locator('form button[type="submit"]').first().click();
  await page.waitForURL((url) => !url.toString().includes('/accedi'), { timeout: 15000 });
}

/** Minimal RFC 4180 reader: quoted fields, doubled quotes, CRLF or LF. */
function parseCsv(text) {
  const rows = [];
  let row = [];
  let field = '';
  let quoted = false;
  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    if (quoted) {
      if (ch === '"' && text[i + 1] === '"') { field += '"'; i++; } else if (ch === '"') { quoted = false; } else { field += ch; }
    } else if (ch === '"') { quoted = true; } else if (ch === ',') { row.push(field); field = ''; } else if (ch === '\n' || ch === '\r') {
      if (ch === '\r' && text[i + 1] === '\n') i++;
      row.push(field); rows.push(row); row = []; field = '';
    } else { field += ch; }
  }
  if (field !== '' || row.length) { row.push(field); rows.push(row); }
  return rows;
}

const lastSession = () => fx.sessions[fx.sessions.length - 1];
const copyState = (c) => dbQuery(`SELECT stato FROM copie WHERE id=${fx.copies[c]}`);
const counter = (page, key) => page.locator(`#inventory-counters [data-counter="${key}"]`);

/** Type a code like a wedge scanner (characters + Enter) and wait for the server's answer. */
async function scan(page, value) {
  const answered = page.waitForResponse((r) => r.url().includes('/scan') && r.request().method() === 'POST');
  await page.locator('#inventory-code').pressSequentially(value, { delay: 10 });
  await page.locator('#inventory-code').press('Enter');
  const response = await answered;
  expect(response.status()).toBe(200);
  const body = await response.json();
  await expect(page.locator('#inventory-feedback')).toHaveAttribute('data-esito', body.result.esito);
  return body.result.esito;
}

async function openSession(page, name, shelfId) {
  await page.goto(`${BASE}/admin/inventory`);
  await page.fill('#inventory-name', name);
  if (shelfId) await page.selectOption('#inventory-shelf', String(shelfId));
  await page.locator('#inventory-open-form button[type="submit"]').click();
  await page.waitForURL(/\/admin\/inventory\/\d+$/, { timeout: 15000 });
  const id = Number(new URL(page.url()).pathname.split('/').pop());
  fx.sessions.push(id);
  return id;
}

async function confirmSwal(page) {
  const button = page.locator('.swal2-confirm');
  await expect(button).toBeVisible({ timeout: 10000 });
  await button.click();
}

test.beforeAll(() => {
  makeUser(ADMIN, 'admin', 'Admin');
  makeUser(STAFF, 'staff', 'Staff');
  for (const [key, letter] of [['shelfX', 'X'], ['shelfY', 'Y']]) {
    dbQuery(`INSERT INTO scaffali (codice, nome, lettera) VALUES (${S(TAG + letter)}, ${S('Stocktake ' + letter)}, ${S(letter)})`);
    fx[key] = Number(dbQuery(`SELECT id FROM scaffali WHERE codice=${S(TAG + letter)}`));
  }
  const book = (key, shelf, copies) => {
    const title = `${TAG} ${key}`;
    dbQuery(`INSERT INTO libri (titolo, scaffale_id, collocazione, copie_totali, copie_disponibili) VALUES (${S(title)}, ${shelf}, ${S(TAG + '.' + key)}, ${copies.length}, ${copies.length})`);
    fx.books[key] = Number(dbQuery(`SELECT id FROM libri WHERE titolo=${S(title)}`));
    for (const c of copies) {
      dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${fx.books[key]}, ${S(code(c))}, 'disponibile')`);
      fx.copies[c] = Number(dbQuery(`SELECT id FROM copie WHERE numero_inventario=${S(code(c))}`));
    }
  };
  book('B1', fx.shelfX, ['A1', 'A2', 'A3', 'A4']);
  book('B2', fx.shelfX, ['L1']);
  book('B3', fx.shelfY, ['Y1']);
  // L1 is out on a real active loan.
  dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata, privacy_accettata, locale)
    VALUES (${S(TAG + 'BR')}, 'Borrower', ${S(TAG)}, ${S(`st-b-${RUN}@stocktake.test.local`)}, ${S(phpHash(PASS))}, 'standard', 'attivo', 1, 1, 'it_IT')`);
  fx.borrower = Number(dbQuery(`SELECT id FROM utenti WHERE email=${S(`st-b-${RUN}@stocktake.test.local`)}`));
  dbQuery(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo)
    VALUES (${fx.books.B2}, ${fx.copies.L1}, ${fx.borrower}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'in_corso', 1)`);
  fx.loan = Number(dbQuery(`SELECT id FROM prestiti WHERE copia_id=${fx.copies.L1} ORDER BY id DESC LIMIT 1`));
  dbQuery(`UPDATE copie SET stato='prestato' WHERE id=${fx.copies.L1}`);
  dbQuery(`UPDATE libri SET copie_disponibili=0 WHERE id=${fx.books.B2}`);
});

test.afterAll(() => {
  // A session saved before a navigation failed never reaches fx.sessions:
  // collect every session opened by the test users or named after this run too.
  const users = [ADMIN.id, STAFF.id].filter(Boolean);
  const found = dbQuery(`SELECT id FROM inventario_sessioni WHERE nome LIKE ${S(TAG + '%')}`
    + (users.length ? ` OR aperta_da IN (${users.join(',')})` : '')).split('\n').filter(Boolean).map(Number);
  const sessions = [...new Set([...fx.sessions, ...found])];
  if (sessions.length) {
    // inventario_attese and inventario_scansioni go with the session (ON DELETE CASCADE).
    dbQuery(`DELETE FROM log_modifiche WHERE tabella='inventario_sessioni' AND record_id IN (${sessions.join(',')})`);
    dbQuery(`DELETE FROM inventario_sessioni WHERE id IN (${sessions.join(',')})`);
  }
  const books = Object.values(fx.books);
  if (books.length) {
    dbQuery(`DELETE FROM log_modifiche WHERE tabella='libri' AND record_id IN (${books.join(',')})`);
    dbQuery(`DELETE FROM prestiti WHERE libro_id IN (${books.join(',')})`);
    dbQuery(`DELETE FROM copie WHERE libro_id IN (${books.join(',')})`);
    dbQuery(`DELETE FROM libri WHERE id IN (${books.join(',')})`);
  }
  dbQuery(`DELETE FROM scaffali WHERE codice IN (${S(TAG + 'X')}, ${S(TAG + 'Y')})`);
  dbQuery(`DELETE FROM utenti WHERE email LIKE ${S(`%-${RUN}@stocktake.test.local`)}`);
});

test('1. the librarian opens a session on a shelf from the sidebar and the expected copies are frozen', async ({ page }) => {
  await login(page, ADMIN);
  await page.goto(`${BASE}/admin/dashboard`);
  await page.locator('#sidebar a[href$="/admin/inventory"]').click();
  await expect(page).toHaveURL(/\/admin\/inventory$/);
  const id = await openSession(page, `${TAG} scaffale X`, fx.shelfX);
  await expect(page.locator('[role="alert"]').first()).toContainText('Revisione aperta');
  await expect(counter(page, 'attese')).toHaveText('5');
  await expect(counter(page, 'mancanti')).toHaveText('4');
  await expect(counter(page, 'in_prestito')).toHaveText('1');
  await expect(page.locator('#inventory-code')).toBeFocused();
  expect(Number(dbQuery(`SELECT totale_attese FROM inventario_sessioni WHERE id=${id}`))).toBe(5);
  expect(dbQuery(`SELECT GROUP_CONCAT(copia_id ORDER BY copia_id) FROM inventario_attese WHERE sessione_id=${id}`))
    .toBe(['A1', 'A2', 'A3', 'A4', 'L1'].map((c) => fx.copies[c]).sort((a, b) => a - b).join(','));
  // The current-shelf selector starts on the session's shelf.
  await expect(page.locator('#inventory-current-shelf')).toHaveValue(String(fx.shelfX));
});

test('2. codes typed like a wedge scanner get the right outcome, colour and live counters', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await login(page, ADMIN);
  await page.goto(`${BASE}/admin/inventory/${lastSession()}`);
  // The self-hosted camera scanner (#238) is wired to the code field.
  await expect(page.locator('button[data-copy-scan][data-copy-scan-target="inventory-code"]')).toHaveAttribute('data-copy-scan-bound', '1');
  expect(await scan(page, code('A1'))).toBe('trovata');
  await expect(page.locator('#inventory-feedback')).toHaveClass(/bg-green-50/);
  await expect(counter(page, 'trovate')).toHaveText('1');
  expect(await scan(page, code('A1'))).toBe('duplicata');
  expect(await scan(page, code('Y1'))).toBe('fuori_ambito');
  expect(await scan(page, code('L1'))).toBe('in_prestito');
  expect(await scan(page, `${TAG}-NOPE`)).toBe('sconosciuta');
  await expect(page.locator('#inventory-feedback')).toHaveClass(/bg-red-50/);
  // The operator moves to shelf Y and finds A2 there: misplaced.
  await page.selectOption('#inventory-current-shelf', String(fx.shelfY));
  expect(await scan(page, code('A2'))).toBe('fuori_posto');
  await expect(page.locator('#inventory-feedback')).toContainText(`[${TAG}X]`);
  await page.selectOption('#inventory-current-shelf', String(fx.shelfX));

  await expect(counter(page, 'trovate')).toHaveText('1');
  await expect(counter(page, 'fuori_posto')).toHaveText('1');
  await expect(counter(page, 'in_prestito')).toHaveText('1');
  await expect(counter(page, 'mancanti')).toHaveText('2');
  await expect(counter(page, 'sconosciute')).toHaveText('1');
  await expect(counter(page, 'fuori_ambito')).toHaveText('1');
  await expect(counter(page, 'duplicate')).toHaveText('1');
  await expect(page.locator('#inventory-scans-body tr')).toHaveCount(6);
  expect(dbQuery(`SELECT GROUP_CONCAT(esito ORDER BY id) FROM inventario_scansioni WHERE sessione_id=${lastSession()}`))
    .toBe('trovata,duplicata,fuori_ambito,in_prestito,sconosciuta,fuori_posto');
  expect(errors).toEqual([]);
});

test('3. an uploaded .txt list is recorded line by line', async ({ page }) => {
  await login(page, ADMIN);
  await page.goto(`${BASE}/admin/inventory/${lastSession()}`);
  const file = path.join(os.tmpdir(), `stocktake-${RUN}.txt`);
  fs.writeFileSync(file, `codice\r\n${code('A3')}\r\n${code('A3')}\r\n\r\n`);
  try {
    await page.setInputFiles('#inventory-file', file);
    await page.locator('#inventory-upload-form button[type="submit"]').click();
    await page.waitForURL(new RegExp(`/admin/inventory/${lastSession()}$`));
  } finally {
    fs.unlinkSync(file);
  }
  await expect(page.locator('[role="alert"]').first()).toContainText('Elaborati 2 codici dal file.');
  await expect(counter(page, 'trovate')).toHaveText('2');
  await expect(counter(page, 'duplicate')).toHaveText('2');
  await expect(counter(page, 'mancanti')).toHaveText('1');
});

test('4. a second operator scans in parallel: live counters and "only my scans"', async ({ page, browser }) => {
  const staffContext = await browser.newContext();
  const staffPage = await staffContext.newPage();
  try {
    await login(staffPage, STAFF);
    await staffPage.goto(`${BASE}/admin/inventory/${lastSession()}`);
    expect(await scan(staffPage, `${TAG}-STAFF1`)).toBe('sconosciuta');
    await expect(staffPage.locator('[data-counter="mie"]')).toHaveText('1');
  } finally {
    await staffContext.close();
  }

  await login(page, ADMIN);
  await page.goto(`${BASE}/admin/inventory/${lastSession()}`);
  await expect(counter(page, 'sconosciute')).toHaveText('2');
  await expect(page.locator('[data-counter="scansioni"]')).toHaveText('9');
  await expect(page.locator('[data-counter="mie"]')).toHaveText('8');
  await expect(page.locator('#inventory-scans-body')).toContainText(`${TAG}-STAFF1`);

  await page.locator('#inventory-mine').check();
  await expect(page.locator('#inventory-scans-body')).not.toContainText(`${TAG}-STAFF1`);
  await expect(page.locator('#inventory-scans-body tr')).toHaveCount(8);
  await expect(page).toHaveURL(/mine=1/);
  await page.locator('#inventory-mine').uncheck();
  await expect(page.locator('#inventory-scans-body')).toContainText(`${TAG}-STAFF1`);
});

test('5. staff can scan but cannot close marking the missing copies as lost', async ({ page }) => {
  await login(page, STAFF);
  await page.goto(`${BASE}/admin/inventory/${lastSession()}`);
  const lostRadio = page.locator('#inventory-close-form input[value="perso"]');
  await expect(lostRadio).toBeDisabled();
  await expect(page.locator('#inventory-close-form')).toContainText('Solo un amministratore');
  // Even a forged submission is refused on the server.
  await page.evaluate(() => {
    const radio = /** @type {HTMLInputElement} */ (document.querySelector('#inventory-close-form input[value="perso"]'));
    radio.disabled = false;
    radio.checked = true;
    /** @type {HTMLFormElement} */ (document.getElementById('inventory-close-form')).submit();
  });
  await page.waitForURL(new RegExp(`/admin/inventory/${lastSession()}$`));
  await expect(page.locator('[role="alert"]').first()).toContainText('Solo un amministratore');
  expect(dbQuery(`SELECT stato FROM inventario_sessioni WHERE id=${lastSession()}`)).toBe('aperta');
  expect(copyState('A4')).toBe('disponibile');
});

test('6. the admin closes marking the missing copy as lost: state, availability and audit log follow', async ({ page }) => {
  await login(page, ADMIN);
  await page.goto(`${BASE}/admin/inventory/${lastSession()}`);
  await page.locator('#inventory-close-form input[value="perso"]').check();
  await page.locator('#inventory-close-form button[type="submit"]').click();
  await confirmSwal(page);
  await page.waitForURL(new RegExp(`/admin/inventory/${lastSession()}$`));
  await expect(page.locator('[role="alert"]').first()).toContainText('1 copie mancanti segnate come perse');
  await expect(page.locator('#inventory-state')).toHaveText('Chiusa');
  await expect(page.locator('#inventory-code')).toHaveCount(0);
  await expect(page.locator(`#inventory-report tr[data-categoria="mancante"]`)).toContainText(code('A4'));

  expect(copyState('A4')).toBe('perso');
  for (const c of ['A1', 'A2', 'A3']) expect(copyState(c)).toBe('disponibile');
  expect(copyState('L1')).toBe('prestato');
  expect(Number(dbQuery(`SELECT copie_disponibili FROM libri WHERE id=${fx.books.B1}`))).toBe(3);
  expect(Number(dbQuery(`SELECT COUNT(*) FROM log_modifiche WHERE tabella='libri' AND record_id=${fx.books.B1}
    AND JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi, '$._activity.event'))='copy.inventory_lost'`))).toBe(1);
  expect(dbQuery(`SELECT CONCAT(stato, ':', chiusa_da) FROM inventario_sessioni WHERE id=${lastSession()}`)).toBe(`chiusa:${ADMIN.id}`);

  // The book's activity feed shows the event to the librarian.
  await page.goto(`${BASE}/admin/books/${fx.books.B1}`);
  await expect(page.locator('body')).toContainText('Copia dichiarata persa in revisione inventariale');
});

test('7. the PDF and CSV reports download with the expected content', async ({ page }) => {
  await login(page, ADMIN);
  await page.goto(`${BASE}/admin/inventory/${lastSession()}`);
  const [csvDownload] = await Promise.all([page.waitForEvent('download'), page.locator('#inventory-csv').click()]);
  const csv = fs.readFileSync(await csvDownload.path(), 'utf-8');
  expect(csvDownload.suggestedFilename()).toMatch(new RegExp(`^revisione-${lastSession()}-.*\\.csv$`));
  expect(csv.charCodeAt(0)).toBe(0xfeff); // BOM for spreadsheet apps
  const rows = parseCsv(csv.slice(1));
  expect(rows[0].slice(0, 5)).toEqual(['Esito', 'Codice copia', 'Titolo', 'Collocazione', 'Scaffale atteso']);
  const byCode = Object.fromEntries(rows.slice(1).map((r) => [r[1], r]));
  expect(byCode[code('A4')].slice(0, 3)).toEqual(['Mancante', code('A4'), `${TAG} B1`]);
  expect(byCode[code('A2')][0]).toBe('Fuori posto');
  expect(byCode[code('A2')][4]).toBe(`[${TAG}X] Stocktake X`);
  expect(byCode[code('L1')][0]).toBe('In prestito o in attesa di ritiro');
  expect(byCode[`${TAG}-NOPE`][0]).toBe('Codice sconosciuto');
  expect(byCode[code('Y1')][0]).toBe('Fuori ambito');
  expect(rows.filter((r) => r[0] === 'Trovata').map((r) => r[1]).sort()).toEqual([code('A1'), code('A3')]);

  const pdf = await page.request.get(`${BASE}/admin/inventory/${lastSession()}/report.pdf`);
  expect(pdf.status()).toBe(200);
  expect(pdf.headers()['content-type']).toContain('application/pdf');
  const bytes = await pdf.body();
  expect(bytes.subarray(0, 5).toString('latin1')).toBe('%PDF-');
  // The document title carries the session name (TCPDF writes it as UTF-16BE).
  const name = `${TAG} scaffale X`;
  const utf16be = Buffer.from(name, 'utf16le').swap16();
  expect(bytes.includes(utf16be) || bytes.includes(Buffer.from(name, 'latin1'))).toBe(true);
});

test('8. closing while leaving the missing copies as they are changes no copy', async ({ page }) => {
  await login(page, ADMIN);
  const id = await openSession(page, `${TAG} lascia`, fx.shelfX);
  expect(await scan(page, code('A1'))).toBe('trovata');
  // A camera read fills the field and fires input/change, as copy-scanner.js does: it is recorded at once.
  const cameraAnswer = page.waitForResponse((r) => r.url().includes('/scan') && r.request().method() === 'POST');
  await page.evaluate((value) => {
    const input = /** @type {HTMLInputElement} */ (document.getElementById('inventory-code'));
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }, code('A2'));
  expect((await (await cameraAnswer).json()).result.esito).toBe('trovata');
  await expect(page.locator('#inventory-code')).toHaveValue('');
  await expect(counter(page, 'trovate')).toHaveText('2');
  const before = dbQuery(`SELECT GROUP_CONCAT(CONCAT(id, ':', stato) ORDER BY id) FROM copie WHERE libro_id IN (${Object.values(fx.books).join(',')})`);
  await page.locator('#inventory-close-form button[type="submit"]').click();
  await confirmSwal(page);
  await page.waitForURL(new RegExp(`/admin/inventory/${id}$`));
  await expect(page.locator('[role="alert"]').first()).toContainText('le copie mancanti sono rimaste invariate');
  expect(dbQuery(`SELECT GROUP_CONCAT(CONCAT(id, ':', stato) ORDER BY id) FROM copie WHERE libro_id IN (${Object.values(fx.books).join(',')})`)).toBe(before);
  expect(dbQuery(`SELECT stato FROM inventario_sessioni WHERE id=${id}`)).toBe('chiusa');
  expect(dbQuery(`SELECT JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi, '$.azione_mancanti')) FROM log_modifiche WHERE tabella='inventario_sessioni' AND record_id=${id}
    AND JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi, '$._activity.event'))='inventory.closed'`)).toBe('nessuna');
});

test('9. a session can be cancelled: it stays listed as cancelled and refuses scans', async ({ page }) => {
  await login(page, ADMIN);
  const id = await openSession(page, `${TAG} annulla`, fx.shelfY);
  await expect(counter(page, 'attese')).toHaveText('1');
  await page.locator('#inventory-cancel-form button[type="submit"]').click();
  await confirmSwal(page);
  await page.waitForURL(/\/admin\/inventory$/);
  await expect(page.locator('[role="alert"]').first()).toContainText('Revisione annullata');
  await expect(page.locator(`#inventory-sessions tr[data-session-id="${id}"]`)).toContainText('Annullata');
  expect(dbQuery(`SELECT stato FROM inventario_sessioni WHERE id=${id}`)).toBe('annullata');
  expect(copyState('Y1')).toBe('disponibile');

  const refused = await page.request.post(`${BASE}/admin/inventory/${id}/scan`, {
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': await page.evaluate(() => /** @type {HTMLMetaElement|null} */ (document.querySelector('meta[name="csrf-token"]'))?.content || '') },
    data: { codice: code('Y1') },
  });
  expect(refused.status()).toBe(422);
  expect(Number(dbQuery(`SELECT COUNT(*) FROM inventario_scansioni WHERE sessione_id=${id}`))).toBe(0);
});

test('10. a copy recorded as lost that turns up is reported as found again, not as outside the filter', async ({ page }) => {
  // P1 is already lost: it is left out of every snapshot, so a scan of it is out of scope (review #504).
  dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${fx.books.B1}, ${S(code('P1'))}, 'perso')`);
  fx.copies.P1 = Number(dbQuery(`SELECT id FROM copie WHERE numero_inventario=${S(code('P1'))}`));
  await login(page, ADMIN);
  const id = await openSession(page, `${TAG} ritrovata`, fx.shelfX);
  expect(Number(dbQuery(`SELECT COUNT(*) FROM inventario_attese WHERE sessione_id=${id} AND copia_id=${fx.copies.P1}`))).toBe(0);
  expect(await scan(page, code('Y1'))).toBe('fuori_ambito');
  await expect(page.locator('#inventory-feedback')).toContainText('non rientra nel filtro della revisione');
  expect(await scan(page, code('P1'))).toBe('fuori_ambito');
  await expect(page.locator('#inventory-feedback')).toContainText('risulta persa o fuori circolazione, ma è stata ritrovata');
  await expect(page.locator('#inventory-feedback')).toContainText('(perso)');
  await expect(page.locator('#inventory-feedback')).not.toContainText('non rientra nel filtro della revisione');
  expect(copyState('P1')).toBe('perso');
});
