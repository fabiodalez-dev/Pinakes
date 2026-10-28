// @ts-check
//
// User import from CSV (plan phase 7). A librarian uploads 20 rows — 17 new
// readers, 2 duplicates (one email and one card code already in use) and 1 bad
// row — checks the preview, fixes the column mapping by hand, runs the import
// with invitations, and downloads the errors. The "Tipologia" column carries a
// patron type code (in either case, one unknown, one empty) and is assigned by
// code through PatronTypeImport. Invitations are checked in
// Mailpit, filtered by this run's recipient domain (the inbox is shared).
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { serverFileExists, listServerDir, writeServerFile } = require('./helpers/server-files');
const { appDateOffsetISO } = require('./helpers/app-date');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ROOT = path.resolve(__dirname, '..');
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const MAILPIT_API = process.env.MAILPIT_API || 'http://localhost:8025/api/v1';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME || (!DB_HOST && !DB_SOCKET), 'E2E credentials not configured');

function mysqlArgs(sql) {
  const args = [];
  if (DB_HOST) { args.push('-h', DB_HOST); if (DB_PORT) args.push('-P', DB_PORT); } else if (DB_SOCKET) { args.push('-S', DB_SOCKET); }
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return args;
}
function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}
function sqlStr(s) { return "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "''") + "'"; }

async function mailpitAvailable() {
  try { const r = await fetch(`${MAILPIT_API}/info`); return r.ok; } catch { return false; }
}
async function mailpitSearch(query) {
  const r = await fetch(`${MAILPIT_API}/search?query=${encodeURIComponent(query)}&limit=200`);
  if (!r.ok) throw new Error(`Mailpit HTTP ${r.status}`);
  return r.json();
}

function clearConfigCache() {
  const dir = path.join(ROOT, 'storage', 'cache');
  if (!fs.existsSync(dir)) return;
  for (const name of fs.readdirSync(dir)) {
    if (name.startsWith('pinakes_config_settings_raw_')) { try { fs.unlinkSync(path.join(dir, name)); } catch { /* best effort */ } }
  }
}

const RUN = Date.now().toString(36);
const DOMAIN = `pci-${RUN}.example.com`;
const RUN_UP = RUN.toUpperCase();
const CUSTOM_LABEL = `Social${RUN}`;
const EXISTING_EMAIL = `existing.${RUN}@${DOMAIN}`;
const EXISTING_CODE = `ZEX${RUN_UP}`;
const SECRET = 'Secret123!';
const TYPE_CODE = `st${RUN}`;
const TYPE_NAME = `Studenti ${RUN}`;
const TYPE_CARD_DAYS = 200;
const UNKNOWN_TYPE = `nessuna${RUN}`;
const MANUAL_EXPIRY = '2031-12-31';

/**
 * 20 data rows: 17 valid, a duplicate email (line 6), a duplicate card code (line 13), a bad email (line 17).
 * Patron type: readers 1-15 carry the type, as its name (every third one, TYPE_NAME) or as TYPE_CODE
 * (upper case for the even ones, lower case otherwise); 16 none, 17 (line 21) an unknown value.
 * Card expiry: only reader 3 carries one (MANUAL_EXPIRY); every other row leaves the column empty.
 */
function buildCsv() {
  const lines = [['Nome', 'Cognome', 'E-mail', 'Tel. cellulare', 'Data di nascita', 'Tessera', 'Password', CUSTOM_LABEL, 'Tipologia', 'Scadenza tessera'].join(';')];
  let n = 0;
  for (let row = 1; row <= 20; row++) {
    const line = row + 1;
    if (line === 6) { lines.push(['Doppia', 'Email', EXISTING_EMAIL, '', '', '', SECRET, '', '', ''].join(';')); continue; }
    if (line === 13) { lines.push(['Doppio', 'Codice', `dupcode.${RUN}@${DOMAIN}`, '', '', EXISTING_CODE, SECRET, '', '', ''].join(';')); continue; }
    if (line === 17) { lines.push(['Sbagliata', 'Riga', 'not-an-email', '', '', '', SECRET, '', '', ''].join(';')); continue; }
    n++;
    const code = n % 2 === 0 ? `ZIM${RUN_UP}${n}` : '';
    const type = n === 17 ? UNKNOWN_TYPE : n === 16 ? '' : n % 3 === 0 ? TYPE_NAME : (n % 2 === 0 ? TYPE_CODE.toUpperCase() : TYPE_CODE);
    const expiry = n === 3 ? MANUAL_EXPIRY : '';
    lines.push([`Imp${n}`, `Run${RUN}`, `imp${n}.${RUN}@${DOMAIN}`, `333000${String(n).padStart(4, '0')}`, `${String(n).padStart(2, '0')}/03/1990`, code, SECRET, `@imp${n}`, type, expiry].join(';'));
  }
  return lines.join('\n') + '\n';
}

test.describe.serial('User import from CSV (phase 7)', () => {
  /** @type {import('@playwright/test').Page} */
  let page;
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  let customId = 0;
  let existingId = 0;
  let typeId = 0;
  let mailpit = false;
  /** @type {Record<string,string>} */
  const savedEmail = {};
  const csvFile = path.join(os.tmpdir(), `utenti-${RUN}.csv`);

  test.beforeAll(async ({ browser }) => {
    mailpit = await mailpitAvailable();
    fs.writeFileSync(csvFile, buildCsv());
    dbQuery(`INSERT INTO registrazione_campi (etichetta, tipo, obbligatorio, attivo, ordine) VALUES (${sqlStr(CUSTOM_LABEL)}, 'text', 0, 1, 99)`);
    customId = Number(dbQuery(`SELECT id FROM registrazione_campi WHERE etichetta = ${sqlStr(CUSTOM_LABEL)}`));
    dbQuery(`INSERT INTO utenti (nome, cognome, email, password, codice_tessera, stato, tipo_utente, email_verificata)
             VALUES ('Gia', 'Presente', ${sqlStr(EXISTING_EMAIL)}, 'x', ${sqlStr(EXISTING_CODE)}, 'attivo', 'standard', 1)`);
    existingId = Number(dbQuery(`SELECT id FROM utenti WHERE email = ${sqlStr(EXISTING_EMAIL)}`));
    // The type carries a card duration: imported readers of this type get today + TYPE_CARD_DAYS.
    dbQuery(`INSERT INTO tipologie_lettore (codice, nome, attiva, ordinamento, durata_tessera_giorni) VALUES (${sqlStr(TYPE_CODE)}, ${sqlStr(TYPE_NAME)}, 1, 90, ${TYPE_CARD_DAYS})`);
    typeId = Number(dbQuery(`SELECT id FROM tipologie_lettore WHERE codice = ${sqlStr(TYPE_CODE)}`));

    // Route the application mail to Mailpit for this suite, restored afterwards.
    for (const k of ['type', 'driver_mode', 'smtp_host', 'smtp_port', 'smtp_security', 'smtp_username', 'smtp_password']) {
      savedEmail[k] = dbQuery(`SELECT COALESCE((SELECT setting_value FROM system_settings WHERE category='email' AND setting_key=${sqlStr(k)} LIMIT 1), '__absent__')`);
    }
    if (mailpit) {
      dbQuery(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES
               ('email','type','smtp'),('email','driver_mode','smtp'),('email','smtp_host','localhost'),('email','smtp_port','1025'),
               ('email','smtp_security',''),('email','smtp_username',''),('email','smtp_password','')
               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)`);
      clearConfigCache();
    }

    context = await browser.newContext();
    page = await context.newPage();
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL((u) => u.toString().includes('/admin'), { timeout: 15000 });
    if (mailpit) {
      // Re-save the contacts tab unchanged: ConfigStore::set() invalidates the
      // settings cache inside the web server (APCu) for the SMTP values above.
      await page.goto(`${BASE}/admin/settings?tab=contacts`);
      const form = page.locator('form[action$="/admin/settings/contacts"]');
      const data = await form.evaluate((el) => Object.fromEntries(new FormData(/** @type {HTMLFormElement} */ (el)).entries()));
      await page.request.post(`${BASE}/admin/settings/contacts`, { form: data, maxRedirects: 0 });
    }
  });

  test.afterAll(async () => {
    dbQuery(`DELETE FROM utenti_campi_valori WHERE campo_id = ${customId}`);
    dbQuery(`DELETE FROM registrazione_campi WHERE id = ${customId}`);
    dbQuery(`DELETE FROM utenti WHERE email LIKE ${sqlStr(`%@${DOMAIN}`)}`);
    if (typeId) dbQuery(`DELETE FROM tipologie_lettore WHERE id = ${typeId}`);
    dbQuery(`DELETE FROM import_logs WHERE file_name = ${sqlStr(path.basename(csvFile))}`);
    for (const [k, v] of Object.entries(savedEmail)) {
      if (v === '__absent__') dbQuery(`DELETE FROM system_settings WHERE category='email' AND setting_key=${sqlStr(k)}`);
      else dbQuery(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('email', ${sqlStr(k)}, ${sqlStr(v)}) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)`);
    }
    clearConfigCache();
    try { fs.unlinkSync(csvFile); } catch { /* gone */ }
    if (mailpit) {
      const found = await mailpitSearch(`to:${DOMAIN}`).catch(() => ({ messages: [] }));
      const ids = (found.messages || []).map((m) => m.ID);
      if (ids.length) await fetch(`${MAILPIT_API}/messages`, { method: 'DELETE', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ IDs: ids }) });
    }
    await context?.close();
  });

  test('the users list leads to the import page, and the upload shows a preview of the first rows', async () => {
    await page.goto(`${BASE}/admin/users`);
    await page.locator('a[href$="/admin/users/import"]:visible').first().click();
    await expect(page).toHaveURL(/\/admin\/users\/import$/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Importa utenti da CSV');

    await page.locator('#user_import_file').setInputFiles(csvFile);
    await page.locator('#user-import-upload-btn').click();
    const mapping = page.locator('#user-import-step-mapping');
    await expect(mapping).toBeVisible();
    await expect(page.locator('#user-import-row-count')).toHaveText('20 righe da importare');
    await expect(page.locator('#user-import-preview thead tr').first()).toContainText('Tel. cellulare');
    await expect(page.locator('#user-import-preview tbody tr')).toHaveCount(5);
    await expect(page.locator('#user-import-preview tbody tr').first()).toContainText(`imp1.${RUN}@${DOMAIN}`);
  });

  test('the mapping is guessed from the headers; passwords cannot be mapped; the rest is fixed by hand', async () => {
    const select = (i) => page.locator(`select[data-import-column="${i}"]`);
    await expect(select(0)).toHaveValue('nome');
    await expect(select(1)).toHaveValue('cognome');
    await expect(select(2)).toHaveValue('email');
    await expect(select(3)).toHaveValue(''); // "Tel. cellulare" is not a known header
    await expect(select(4)).toHaveValue('data_nascita');
    await expect(select(5)).toHaveValue('codice_tessera');
    await expect(select(6)).toHaveValue(''); // Password: never mapped
    await expect(select(6).locator('option[value="password"]')).toHaveCount(0);
    await expect(select(7)).toHaveValue(`custom:${customId}`); // custom registration field
    // Patron types exist: the "Tipologia" header is recognised as the patron type (code or name).
    await expect(select(8).locator('option[value="tipologia"]')).toHaveCount(1);
    await expect(select(8)).toHaveValue('tipologia');
    await expect(select(9)).toHaveValue('data_scadenza_tessera');

    // Without an email column the import refuses to start.
    await select(2).selectOption('');
    await page.locator('#user-import-start').click();
    await expect(page.locator('#user-import-alert')).toBeVisible();
    await expect(page.locator('#user-import-alert-text')).toContainText('Email');
    await expect(page.locator('#user-import-step-mapping')).toBeVisible();

    await select(2).selectOption('email');
    await select(3).selectOption('telefono');
    await expect(select(3)).toHaveValue('telefono');
  });

  test('importing 20 rows creates 17 readers, skips 2 duplicates, reports 1 bad row and sends 17 invitations', async () => {
    test.setTimeout(180_000);
    if (mailpit) await page.locator('#user_import_send_invites').check();
    await page.locator('#user-import-start').click();
    const summary = page.locator('#user-import-summary');
    await expect(summary).toBeVisible({ timeout: 120_000 });
    await expect(page.locator('[data-import-count="created"]')).toHaveText('17');
    await expect(page.locator('[data-import-count="duplicates"]')).toHaveText('2');
    await expect(page.locator('[data-import-count="failed"]')).toHaveText('1');
    await expect(page.locator('[data-import-count="invited"]')).toHaveText(mailpit ? '17' : '0');
    await expect(page.locator('#user-import-errors tr[data-import-error="duplicate"]')).toHaveCount(2);
    await expect(page.locator('#user-import-errors tr[data-import-error="validation"]')).toHaveCount(1);
    await expect(page.locator('#user-import-errors tr[data-import-error="validation"]')).toContainText('17');
    // The unknown patron type (neither a code nor a name) is a warning: the reader is created with the default type.
    const warning = page.locator('#user-import-errors tr[data-import-error="warning"]');
    await expect(warning).toHaveCount(1);
    await expect(warning).toContainText('21');
    await expect(warning).toContainText(UNKNOWN_TYPE);

    // Database: 17 new readers, the existing one untouched, no duplicate rows.
    expect(dbQuery(`SELECT COUNT(*) FROM utenti WHERE email LIKE ${sqlStr(`imp%.${RUN}@${DOMAIN}`)}`)).toBe('17');
    expect(dbQuery(`SELECT COUNT(*) FROM utenti WHERE email = ${sqlStr(EXISTING_EMAIL)}`)).toBe('1');
    expect(dbQuery(`SELECT CONCAT(nome,' ',cognome) FROM utenti WHERE id = ${existingId}`)).toBe('Gia Presente');
    expect(dbQuery(`SELECT COUNT(*) FROM utenti WHERE codice_tessera = ${sqlStr(EXISTING_CODE)}`)).toBe('1');
    expect(dbQuery(`SELECT COUNT(*) FROM utenti WHERE email = ${sqlStr(`dupcode.${RUN}@${DOMAIN}`)}`)).toBe('0');
    // Mapped values arrived: phone (hand-mapped), date, given and generated card codes, custom field.
    expect(dbQuery(`SELECT CONCAT(telefono,'|',data_nascita,'|',codice_tessera,'|',stato,'|',tipo_utente) FROM utenti WHERE email = ${sqlStr(`imp2.${RUN}@${DOMAIN}`)}`))
      .toBe(`3330000002|1990-03-02|ZIM${RUN_UP}2|attivo|standard`);
    expect(dbQuery(`SELECT codice_tessera FROM utenti WHERE email = ${sqlStr(`imp1.${RUN}@${DOMAIN}`)}`)).toMatch(/^T[0-9A-F]{10}$/);
    expect(dbQuery(`SELECT COUNT(*) FROM utenti_campi_valori v JOIN utenti u ON u.id = v.utente_id WHERE v.campo_id = ${customId} AND u.email LIKE ${sqlStr(`%@${DOMAIN}`)}`)).toBe('17');
    // The CSV password was never stored: the hash does not verify it.
    const hash = dbQuery(`SELECT password FROM utenti WHERE email = ${sqlStr(`imp3.${RUN}@${DOMAIN}`)}`);
    const verifies = execFileSync('php', ['-r', 'echo password_verify($argv[1], $argv[2]) ? "yes" : "no";', SECRET, hash], { encoding: 'utf-8' });
    expect(verifies).toBe('no');
  });

  test('every new reader receives the password-setup invitation in Mailpit', async () => {
    test.skip(!mailpit, 'Mailpit not running on :8025');
    await expect.poll(async () => (await mailpitSearch(`to:${DOMAIN}`)).messages_count ?? (await mailpitSearch(`to:${DOMAIN}`)).total, { timeout: 30_000 }).toBe(17);
    const found = await mailpitSearch(`to:imp5.${RUN}@${DOMAIN}`);
    expect(found.messages.length).toBe(1);
    const msg = await (await fetch(`${MAILPIT_API}/message/${found.messages[0].ID}`)).json();
    expect(String(msg.HTML || msg.Text)).toMatch(/token=[a-f0-9]{64}/);
    // No mail to the duplicates or the bad row.
    expect((await mailpitSearch(`to:${EXISTING_EMAIL}`)).messages.length).toBe(0);
    expect((await mailpitSearch(`to:dupcode.${RUN}@${DOMAIN}`)).messages.length).toBe(0);
    // The token stored for the reader is a hash with a future expiry.
    expect(dbQuery(`SELECT IF(LENGTH(token_reset_password)=64 AND data_token_reset > UTC_TIMESTAMP(), 'ok', 'bad') FROM utenti WHERE email = ${sqlStr(`imp5.${RUN}@${DOMAIN}`)}`)).toBe('ok');
  });

  test('the errors download lists the duplicates and the bad row with their line numbers', async () => {
    const link = page.locator('#user-import-download-errors');
    await expect(link).toBeVisible();
    const href = String(await link.getAttribute('href'));
    // Served under /admin/users: users.manage is enough, catalog.manage is not needed.
    expect(href).toContain('/admin/users/import/errors?import_id=');
    const [download] = await Promise.all([page.waitForEvent('download'), link.click()]);
    const file = await download.path();
    const csv = fs.readFileSync(String(file), 'utf-8');
    const rows = csv.trim().split(/\r?\n/).slice(1);
    expect(rows.length).toBe(4);
    expect(rows.find((r) => r.startsWith('6,'))).toContain('duplicate');
    expect(rows.find((r) => r.startsWith('13,'))).toContain('duplicate');
    expect(rows.find((r) => r.startsWith('17,'))).toContain('validation');
    expect(rows.find((r) => r.startsWith('21,'))).toContain(UNKNOWN_TYPE);
    expect(csv).toContain(EXISTING_EMAIL);
    expect(csv).toContain(EXISTING_CODE);
  });

  test('the patron type column assigns the type by code or by name, case-insensitively; empty and unknown values keep the default', async () => {
    // Readers 1-15: the code in lower (odd) and upper (even) case, and the name (every third), all resolve to the type.
    expect(dbQuery(`SELECT COUNT(*) FROM utenti WHERE email LIKE ${sqlStr(`imp%.${RUN}@${DOMAIN}`)} AND tipologia_id = ${typeId}`)).toBe('15');
    for (const n of [3, 6, 15]) {
      expect(dbQuery(`SELECT COALESCE(tipologia_id, 'NULL') FROM utenti WHERE email = ${sqlStr(`imp${n}.${RUN}@${DOMAIN}`)}`), `reader ${n} by name`).toBe(String(typeId));
    }
    expect(dbQuery(`SELECT COALESCE(tipologia_id, 'NULL') FROM utenti WHERE email = ${sqlStr(`imp16.${RUN}@${DOMAIN}`)}`)).toBe('NULL');
    expect(dbQuery(`SELECT COALESCE(tipologia_id, 'NULL') FROM utenti WHERE email = ${sqlStr(`imp17.${RUN}@${DOMAIN}`)}`)).toBe('NULL');
    expect(dbQuery(`SELECT COALESCE(tipologia_id, 'NULL') FROM utenti WHERE id = ${existingId}`)).toBe('NULL');

    // The librarian sees it on the reader page: the imported type, or the default one.
    const typed = dbQuery(`SELECT id FROM utenti WHERE email = ${sqlStr(`imp4.${RUN}@${DOMAIN}`)}`);
    await page.goto(`${BASE}/admin/users/details/${typed}`);
    await expect(page.getByTestId('patron-type-name')).toContainText(TYPE_NAME);
    await expect(page.getByTestId('patron-type-name')).not.toContainText('predefinita');
    const unknown = dbQuery(`SELECT id FROM utenti WHERE email = ${sqlStr(`imp17.${RUN}@${DOMAIN}`)}`);
    await page.goto(`${BASE}/admin/users/details/${unknown}`);
    await expect(page.getByTestId('patron-type-name')).toContainText('predefinita');
  });

  test('the card expiry follows the patron type duration unless the CSV gives one', async () => {
    // Readers of the 200-day type without an expiry in the file: today + 200.
    const typedExpiry = appDateOffsetISO(TYPE_CARD_DAYS);
    expect(dbQuery(`SELECT COUNT(*) FROM utenti WHERE email LIKE ${sqlStr(`imp%.${RUN}@${DOMAIN}`)} AND tipologia_id = ${typeId} AND data_scadenza_tessera = ${sqlStr(typedExpiry)}`)).toBe('14');
    // The explicit date in the CSV wins over the type duration.
    expect(dbQuery(`SELECT IFNULL(data_scadenza_tessera,'NULL') FROM utenti WHERE email = ${sqlStr(`imp3.${RUN}@${DOMAIN}`)}`)).toBe(MANUAL_EXPIRY);
    // Readers on the default type (no code, unknown code) get its duration, or no expiry when it has none.
    const defaultDays = dbQuery('SELECT IFNULL(durata_tessera_giorni, \'\') FROM tipologie_lettore WHERE predefinita = 1 LIMIT 1');
    const expected = defaultDays === '' ? 'NULL' : appDateOffsetISO(Number(defaultDays));
    for (const n of [16, 17]) {
      expect(dbQuery(`SELECT IFNULL(data_scadenza_tessera,'NULL') FROM utenti WHERE email = ${sqlStr(`imp${n}.${RUN}@${DOMAIN}`)}`), `reader ${n}`).toBe(expected);
    }
  });

  test('the run is in the import history as a users import', async () => {
    const importId = dbQuery(`SELECT import_id FROM import_logs WHERE file_name = ${sqlStr(path.basename(csvFile))} ORDER BY id DESC LIMIT 1`);
    expect(importId).not.toBe('');
    expect(dbQuery(`SELECT CONCAT(import_type,'|',total_rows,'|',imported,'|',failed,'|',status) FROM import_logs WHERE import_id = ${sqlStr(importId)}`)).toBe('users|20|17|1|completed');
    await page.goto(`${BASE}/admin/imports-history`);
    const row = page.locator('tr', { hasText: path.basename(csvFile) });
    await expect(row).toBeVisible();
    await expect(row).toContainText('Utenti');
  });
  test('the users-scoped errors download refuses an import that is not a users import', async () => {
    const other = `zz-other-${RUN}`;
    dbQuery(`INSERT INTO import_logs (import_id, import_type, file_name, user_id, total_rows, imported, failed, status, errors_json)
             VALUES (${sqlStr(other)}, 'csv', ${sqlStr(path.basename(csvFile))}, NULL, 1, 0, 1, 'completed', '[]')`);
    try {
      const res = await page.request.get(`${BASE}/admin/users/import/errors?import_id=${encodeURIComponent(other)}`);
      expect(res.status()).toBe(404);
    } finally {
      dbQuery(`DELETE FROM import_logs WHERE import_id = ${sqlStr(other)}`);
    }
  });

  test('"Annulla" on the preview deletes the uploaded copy, and stale copies are swept', async () => {
    const uploads = path.join(ROOT, 'writable', 'uploads');
    // writable/uploads belongs to the PHP user (www-data in CI).
    const listCopies = () => listServerDir(uploads).filter((n) => /^user_import_[0-9a-f]+\.csv$/.test(n));
    // A copy left behind by an abandoned session two hours ago.
    const stale = path.join(uploads, `user_import_${'ab'.repeat(6)}${RUN.replace(/[^0-9a-f]/g, '').padEnd(12, '0').slice(0, 12)}.csv`);
    writeServerFile(stale, 'Nome;Email\n', new Date(Date.now() - 2 * 3600 * 1000));

    const before = new Set(listCopies());
    await page.goto(`${BASE}/admin/users/import`);
    expect(serverFileExists(stale), 'a copy untouched for over an hour is deleted when the page is opened').toBe(false);

    await page.locator('#user_import_file').setInputFiles(csvFile);
    await page.locator('#user-import-upload-btn').click();
    await expect(page.locator('#user-import-step-mapping')).toBeVisible();
    const added = listCopies().filter((n) => !before.has(n));
    expect(added.length, 'the upload keeps one copy for the import').toBe(1);

    await page.locator('#user-import-cancel').click();
    await expect(page).toHaveURL(/\/admin\/users\/import$/);
    await expect(page.locator('#user_import_file')).toBeVisible();
    expect(serverFileExists(path.join(uploads, added[0])), 'cancelling the preview deletes the copy').toBe(false);
  });

  test('a CSV saved as Windows-1252 is converted on upload: the preview shows "Caffè" and the reader is created with that spelling', async () => {
    test.setTimeout(120_000);
    const file = path.join(os.tmpdir(), `utenti-1252-${RUN}.csv`);
    const email = `caffe.${RUN}@${DOMAIN}`;
    // "Caffè" as Excel on Windows writes it: è is the single byte 0xE8, not UTF-8.
    fs.writeFileSync(file, Buffer.concat([
      Buffer.from('Nome;Cognome;E-mail\n'),
      Buffer.from([0x43, 0x61, 0x66, 0x66, 0xe8]),
      Buffer.from(`;Latino;${email}\n`),
    ]));
    try {
      await page.goto(`${BASE}/admin/users/import`);
      await page.locator('#user_import_file').setInputFiles(file);
      await page.locator('#user-import-upload-btn').click();
      await expect(page.locator('#user-import-step-mapping')).toBeVisible();
      await expect(page.locator('#user-import-notice')).toBeVisible();
      await expect(page.locator('#user-import-notice')).toContainText('Windows-1252');
      await expect(page.locator('#user-import-preview tbody tr').first()).toContainText('Caffè');
      await page.locator('#user-import-start').click();
      await expect(page.locator('#user-import-summary')).toBeVisible({ timeout: 60_000 });
      await expect(page.locator('[data-import-count="created"]')).toHaveText('1');
      await expect(page.locator('[data-import-count="failed"]')).toHaveText('0');
      // UTF-8 bytes of "Caffè" in the database, whatever the mysql client's own charset.
      expect(dbQuery(`SELECT HEX(nome) FROM utenti WHERE email = ${sqlStr(email)}`)).toBe('43616666C3A8');
    } finally {
      dbQuery(`DELETE FROM import_logs WHERE file_name = ${sqlStr(path.basename(file))}`);
      try { fs.unlinkSync(file); } catch { /* gone */ }
    }
  });

  test('a failure after the account is created is a warning on a created row, not a failed row', async () => {
    test.setTimeout(120_000);
    const trigger = `zz_ui_probe_${RUN.replace(/[^a-z0-9]/gi, '')}`;
    const file = path.join(os.tmpdir(), `utenti-post-${RUN}.csv`);
    fs.writeFileSync(file, [['Nome', 'Cognome', 'E-mail', CUSTOM_LABEL].join(';'),
      ['Post', 'Uno', `post1.${RUN}@${DOMAIN}`, 'ZZFAIL'].join(';'),
      ['Post', 'Due', `post2.${RUN}@${DOMAIN}`, 'ok'].join(';')].join('\n') + '\n');
    // Make saving the custom field fail for the first row only.
    dbQuery(`DELIMITER //\nCREATE TRIGGER ${trigger} BEFORE INSERT ON utenti_campi_valori FOR EACH ROW BEGIN IF NEW.valore = 'ZZFAIL' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'probe'; END IF; END//\nDELIMITER ;`);
    try {
      await page.goto(`${BASE}/admin/users/import`);
      await page.locator('#user_import_file').setInputFiles(file);
      await page.locator('#user-import-upload-btn').click();
      await expect(page.locator('#user-import-step-mapping')).toBeVisible();
      await expect(page.locator('select[data-import-column="3"]')).toHaveValue(`custom:${customId}`);
      await page.locator('#user-import-start').click();
      await expect(page.locator('#user-import-summary')).toBeVisible({ timeout: 60_000 });
      await expect(page.locator('[data-import-count="created"]')).toHaveText('2');
      await expect(page.locator('[data-import-count="failed"]')).toHaveText('0');
      await expect(page.locator('#user-import-errors tr[data-import-error="warning"]')).toHaveCount(1);
      await expect(page.locator('#user-import-errors tr[data-import-error="warning"]')).toContainText('campi personalizzati');
      expect(dbQuery(`SELECT COUNT(*) FROM utenti WHERE email LIKE ${sqlStr(`post_.${RUN}@${DOMAIN}`)}`)).toBe('2');
    } finally {
      dbQuery(`DROP TRIGGER IF EXISTS ${trigger}`);
      dbQuery(`DELETE FROM import_logs WHERE file_name = ${sqlStr(path.basename(file))}`);
      try { fs.unlinkSync(file); } catch { /* gone */ }
    }
  });
});
