// @ts-check
/**
 * Review fixes made late in the 0.9.0 stack, driven like a librarian would:
 *
 * A. the closures calendar and the visitor-log retention are admin-only:
 *    staff gets neither the forms nor the actions (GET and every POST);
 * B. the reader-ledger desk forms are recorded once (charge and waiver sent
 *    twice), and a replayed key with other values is refused;
 * C. stocktake: a copy recorded as damaged that turns up is reported as
 *    found again, and a "mark missing as lost" close leaves it alone;
 * D. MARC: 111/711 meeting entries ($e unit, $j relator), an overwrite that
 *    changes the media type clears stale RDA overrides (same type keeps
 *    them), an oversized record is exported reduced instead of skipped;
 * E. #501 quoted search on the search boxes the stack added: the suppliers
 *    list (client-side filter), the users list with the patron type filter,
 *    the book list with the supplier filter.
 *
 * The DB is used only for fixtures and to verify the effects.
 *
 * Run: /tmp/run-e2e.sh tests/stack-090-review-fixes.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');

// 0.9.0 optional modules (Settings → Modules / Loans): this suite tests
// visitor_log / stocktake / suppliers / rda_types / fines, so it switches them on for its run and puts back what was there.
const featureToggles = require('./helpers/feature-toggles');
let featureSnapshot;
test.beforeAll(async () => { featureSnapshot = await featureToggles.enableFeatures(['visitor_log', 'stocktake', 'suppliers', 'rda_types', 'fines']); });
test.afterAll(async () => { await featureToggles.restoreFeatures(featureSnapshot); });
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const F = require('./helpers/circulation-fixtures');
const MARC = require('./helpers/marc-e2e');

test.skip(!F.CONFIGURED, 'E2E credentials not configured');

const RUN = String(Date.now()).slice(-7);
const ROOT = path.join(__dirname, '..');
const PASS = 'Circ1234!';
const S = F.sqlStr;

function isbn13(twelve) {
  const digits = String(twelve).padStart(12, '0').slice(-12);
  const sum = digits.split('').reduce((acc, d, i) => acc + Number(d) * (i % 2 ? 3 : 1), 0);
  return digits + String((10 - (sum % 10)) % 10);
}
const xmlEsc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
/** A MARCXML collection file; records = [{leader, fields: [[tag, ind1, ind2, [[code, value]...]]...]}]. */
function marcXmlFile(name, records) {
  const body = records.map((r) => `<record><leader>${r.leader}</leader>`
    + r.fields.map(([tag, i1, i2, subs]) => `<datafield tag="${tag}" ind1="${i1}" ind2="${i2}">${subs.map(([c, v]) => `<subfield code="${c}">${xmlEsc(v)}</subfield>`).join('')}</datafield>`).join('')
    + '</record>').join('');
  const file = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'pinakes-rf-')), name);
  fs.writeFileSync(file, `<?xml version="1.0" encoding="UTF-8"?><collection xmlns="http://www.loc.gov/MARC21/slim">${body}</collection>`);
  return file;
}

// ── A. Admin-only settings for staff ─────────────────────────────────────
test.describe.serial('A. closures calendar and visitor-log retention are reserved to administrators', () => {
  /** @type {import('@playwright/test').Page} */ let staffPage;
  /** @type {import('@playwright/test').Page} */ let adminPage;
  let staff;
  let closureId = 0;
  let presenzaId = 0;
  const TAG = `E2E-RFA-${RUN}`;
  let savedWeekdays = null;
  let savedRetention = null;
  const calendarSnapshot = () => F.dbQuery("SELECT CONCAT(COUNT(*),'|',COALESCE(SUM(CRC32(CONCAT_WS('|',id,data_inizio,data_fine,ricorrente_annuale,IFNULL(sede_id,''),IFNULL(descrizione,'')))),0)) FROM calendario_chiusure")
    + '#' + F.dbQuery("SELECT IFNULL(MAX(setting_value),'-') FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'");

  test.beforeAll(async ({ browser }) => {
    savedWeekdays = F.dbQuery("SELECT IFNULL(MAX(setting_value),'') FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'");
    savedRetention = F.dbQuery("SELECT IFNULL(MAX(setting_value),'') FROM system_settings WHERE category='attendance' AND setting_key='retention_days'");
    adminPage = await (await browser.newContext()).newPage();
    await F.loginAsAdmin(adminPage);
    // A closure made by the administrator from the calendar page.
    await adminPage.goto(`${F.BASE}/admin/calendar`);
    await adminPage.fill('#closure_start', F.appDateOffsetISO(40));
    await adminPage.fill('#closure_description', TAG);
    await Promise.all([adminPage.waitForURL(/\/admin\/calendar/), adminPage.locator('#save-closure').click()]);
    closureId = F.dbInt(`SELECT id FROM calendario_chiusure WHERE descrizione=${S(TAG)}`);
    expect(closureId).toBeGreaterThan(0);
    // A visit older than any retention, which a purge would remove.
    F.dbQuery(`INSERT INTO presenze (tipo, nome_ospite, motivo, created_at) VALUES ('ospite', ${S(TAG)}, 'E2E', DATE_SUB(NOW(), INTERVAL 4000 DAY))`);
    presenzaId = F.dbInt(`SELECT id FROM presenze WHERE nome_ospite=${S(TAG)}`);
    staff = F.createPatron(`RFA${RUN}`, { role: 'staff', password: PASS });
    staffPage = await (await browser.newContext()).newPage();
    await F.loginAs(staffPage, staff.email, PASS);
  });

  test.afterAll(async () => {
    F.dbQuery(`DELETE FROM calendario_chiusure WHERE descrizione=${S(TAG)}`);
    F.dbQuery(`DELETE FROM presenze WHERE nome_ospite=${S(TAG)}`);
    if (savedWeekdays === '') F.dbQuery("DELETE FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'");
    else F.dbQuery(`UPDATE system_settings SET setting_value=${S(savedWeekdays)} WHERE category='loans' AND setting_key='closed_weekdays'`);
    if (savedRetention === '') F.dbQuery("DELETE FROM system_settings WHERE category='attendance' AND setting_key='retention_days'");
    else F.dbQuery(`UPDATE system_settings SET setting_value=${S(savedRetention)} WHERE category='attendance' AND setting_key='retention_days'`);
    F.deletePatrons([staff?.id]);
    await staffPage?.context().close();
    await adminPage?.context().close();
  });

  test('A1. staff opening the calendar page lands on the dashboard with the admin-only message', async () => {
    await staffPage.goto(`${F.BASE}/admin/calendar`);
    await expect(staffPage).toHaveURL(/\/admin\/dashboard/);
    await expect(staffPage.locator('body')).toContainText(/riservata agli amministratori/i);
    await expect(staffPage.locator('#save-closure')).toHaveCount(0);
  });

  test('A2. every calendar action sent by staff is refused and changes nothing', async () => {
    const before = calendarSnapshot();
    await staffPage.goto(`${F.BASE}/admin/dashboard`);
    const csrf = String(await staffPage.locator('meta[name="csrf-token"]').getAttribute('content'));
    const day = F.appDateOffsetISO(41);
    const posts = [
      ['/admin/calendar/weekdays', { 'closed_weekdays[]': '0' }],
      ['/admin/calendar/closures', { data_inizio: day, data_fine: day, descrizione: `${TAG} intruso` }],
      [`/admin/calendar/closures/${closureId}/update`, { data_inizio: day, data_fine: day, descrizione: `${TAG} cambiato` }],
      [`/admin/calendar/closures/${closureId}/delete`, {}],
      ['/admin/calendar/preset', { year: day.slice(0, 4) }],
    ];
    for (const [route, fields] of posts) {
      const r = await staffPage.request.post(`${F.BASE}${route}`, { form: { csrf_token: csrf, ...fields }, maxRedirects: 0 });
      expect(r.status(), route).toBe(302);
      expect(r.headers()['location'] || '', route).toMatch(/\/admin\/dashboard/);
    }
    const ics = `BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:${day.replace(/-/g, '')}\r\nSUMMARY:${TAG} ics\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n`;
    const r = await staffPage.request.post(`${F.BASE}/admin/calendar/import-ics`, {
      multipart: { csrf_token: csrf, ics_file: { name: 'chiusure.ics', mimeType: 'text/calendar', buffer: Buffer.from(ics) } },
      maxRedirects: 0,
    });
    expect(r.status()).toBe(302);
    expect(r.headers()['location'] || '').toMatch(/\/admin\/dashboard/);
    expect(calendarSnapshot()).toBe(before);
    expect(F.dbQuery(`SELECT descrizione FROM calendario_chiusure WHERE id=${closureId}`)).toBe(TAG);
  });

  test('A3. the administrator edits the same closure from the calendar page', async () => {
    await adminPage.goto(`${F.BASE}/admin/calendar?edit=${closureId}`);
    await expect(adminPage.locator('#closure_description')).toHaveValue(TAG);
    await adminPage.fill('#closure_description', `${TAG}`);
    await adminPage.fill('#closure_end', F.appDateOffsetISO(42));
    await Promise.all([adminPage.waitForURL(/\/admin\/calendar/), adminPage.locator('#save-closure').click()]);
    expect(F.dbQuery(`SELECT data_fine FROM calendario_chiusure WHERE id=${closureId}`)).toBe(F.appDateOffsetISO(42));
  });

  test('A4. staff sees no retention forms on the visitor log, and posting them changes nothing', async () => {
    await staffPage.goto(`${F.BASE}/admin/attendance`);
    await expect(staffPage).toHaveURL(/\/admin\/attendance/);
    await expect(staffPage.locator('#attendance-retention-form')).toHaveCount(0);
    await expect(staffPage.locator('#attendance-purge-form')).toHaveCount(0);
    const csrf = String(await staffPage.locator('meta[name="csrf-token"]').getAttribute('content'));
    for (const [route, fields] of [['/admin/attendance/settings', { retention_days: '1' }], ['/admin/attendance/purge', {}]]) {
      const r = await staffPage.request.post(`${F.BASE}${route}`, { form: { csrf_token: csrf, ...fields }, maxRedirects: 0 });
      expect(r.status(), route).toBe(302);
    }
    expect(F.dbQuery("SELECT IFNULL(MAX(setting_value),'') FROM system_settings WHERE category='attendance' AND setting_key='retention_days'")).toBe(savedRetention);
    expect(F.dbInt(`SELECT COUNT(*) FROM presenze WHERE id=${presenzaId}`), 'the old visit is still there').toBe(1);
    // The administrator's purge removes it. The purge deletes every visit
    // older than the retention, not only this one: the other rows are
    // snapshotted first and put back afterwards, so the shared DB keeps them.
    const otherVisits = F.stashRows('presenze', `id<>${presenzaId}`);
    try {
      await adminPage.goto(`${F.BASE}/admin/attendance`);
      await adminPage.locator('#attendance-purge-form button[type="submit"]').click();
      const confirm = adminPage.locator('.swal2-confirm');
      if (await confirm.isVisible({ timeout: 3000 }).catch(() => false)) await confirm.click();
      await expect.poll(() => F.dbInt(`SELECT COUNT(*) FROM presenze WHERE id=${presenzaId}`), { timeout: 15000 }).toBe(0);
    } finally {
      F.unstashRows(otherVisits);
    }
  });
});

// ── B. Ledger desk forms recorded once ───────────────────────────────────
test.describe.serial('B. reader ledger desk forms', () => {
  /** @type {import('@playwright/test').Page} */ let page;
  let patron;
  const movements = (tipo) => F.dbInt(`SELECT COUNT(*) FROM movimenti_lettore WHERE utente_id=${patron.id} AND tipo=${S(tipo)}`);
  const balance = () => Math.round(Number(F.dbQuery(`SELECT COALESCE(SUM(importo),0) FROM movimenti_lettore WHERE utente_id=${patron.id}`)) * 100);

  /** Fill a form, read it as the browser would send it, post it $times times. */
  async function sendTwice(formSelector, fill) {
    await page.goto(`${F.BASE}/admin/users/details/${patron.id}`);
    await fill();
    const form = page.locator(formSelector);
    const fields = await form.evaluate((f) => Object.fromEntries(new FormData(/** @type {HTMLFormElement} */ (f)).entries()));
    expect(String(fields.request_key)).toMatch(/^[a-f0-9]{32}$/);
    const action = new URL(String(await form.getAttribute('action')), F.BASE).toString();
    const locations = [];
    for (let i = 0; i < 2; i++) {
      const r = await page.request.post(action, { form: /** @type {Record<string, string>} */ (fields), maxRedirects: 0 });
      expect(r.status()).toBe(302);
      locations.push(r.headers()['location'] || '');
    }
    return { fields, action, locations };
  }

  test.beforeAll(async ({ browser }) => {
    patron = F.createPatron(`RFB${RUN}`, { password: PASS });
    page = await (await browser.newContext()).newPage();
    await F.loginAsAdmin(page);
  });
  test.afterAll(async () => {
    F.deletePatrons([patron?.id]);
    await page?.context().close();
  });

  test('B1. a manual charge sent twice (double click) is recorded once', async () => {
    const { locations } = await sendTwice('#ledger-charge-form', async () => {
      await page.fill('#ledger_charge_amount', '6,00');
      await page.fill('#ledger_charge_description', `Tessera smarrita ${RUN}`);
    });
    expect(locations.every((l) => !l.includes('ledger_error'))).toBe(true);
    expect(movements('addebito_manuale')).toBe(1);
    expect(balance()).toBe(600);
  });

  test('B2. a waiver sent twice is recorded once', async () => {
    const { locations } = await sendTwice('#ledger-waive-form', async () => {
      await page.fill('#ledger_waive_amount', '2,00');
      await page.fill('#ledger_waive_reason', 'Primo anno di iscrizione');
    });
    expect(locations.every((l) => !l.includes('ledger_error'))).toBe(true);
    expect(movements('condono')).toBe(1);
    expect(balance()).toBe(400);
  });

  test('B3. the same form key replayed with another amount is refused, not answered with the old movement', async () => {
    const { fields, action } = await sendTwice('#ledger-payment-form', async () => {
      await page.fill('#ledger_pay_amount', '1,00');
    });
    expect(movements('pagamento')).toBe(1);
    expect(balance()).toBe(300);
    const r = await page.request.post(action, { form: { ...fields, importo: '3,00' }, maxRedirects: 0 });
    expect(r.status()).toBe(302);
    expect(r.headers()['location'] || '').toContain('ledger_error=duplicate_request');
    expect(movements('pagamento')).toBe(1);
    expect(balance()).toBe(300);
    // The page tells the operator what happened.
    await page.goto(new URL(String(r.headers()['location']), F.BASE).toString());
    await expect(page.locator('body')).toContainText('Richiesta già registrata');
  });
});

// ── C. Stocktake: a damaged copy turns up ────────────────────────────────
test.describe.serial('C. stocktake and copies recorded out of circulation', () => {
  /** @type {import('@playwright/test').Page} */ let page;
  const TAG = `RFC${RUN}`;
  const fx = { shelf: 0, book: 0, copies: {}, session: 0 };
  const code = (c) => `${TAG}-${c}`;
  const state = (c) => F.dbQuery(`SELECT stato FROM copie WHERE id=${fx.copies[c]}`);

  async function scan(value) {
    const answered = page.waitForResponse((r) => r.url().includes('/scan') && r.request().method() === 'POST');
    await page.locator('#inventory-code').pressSequentially(value, { delay: 10 });
    await page.locator('#inventory-code').press('Enter');
    const body = await (await answered).json();
    await expect(page.locator('#inventory-feedback')).toHaveAttribute('data-esito', body.result.esito);
    return body.result;
  }

  test.beforeAll(async ({ browser }) => {
    F.dbQuery(`INSERT INTO scaffali (codice, nome, lettera) VALUES (${S(TAG)}, ${S('Revisione ' + TAG)}, 'R')`);
    fx.shelf = F.dbInt(`SELECT id FROM scaffali WHERE codice=${S(TAG)}`);
    F.dbQuery(`INSERT INTO libri (titolo, scaffale_id, collocazione, copie_totali, copie_disponibili) VALUES (${S(TAG + ' libro')}, ${fx.shelf}, ${S(TAG + '.1')}, 3, 2)`);
    fx.book = F.dbInt(`SELECT id FROM libri WHERE titolo=${S(TAG + ' libro')} AND deleted_at IS NULL`);
    for (const [c, st] of [['OK', 'disponibile'], ['MISS', 'disponibile'], ['DMG', 'danneggiato']]) {
      F.dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${fx.book}, ${S(code(c))}, ${S(st)})`);
      fx.copies[c] = F.dbInt(`SELECT id FROM copie WHERE numero_inventario=${S(code(c))}`);
    }
    page = await (await browser.newContext()).newPage();
    await F.loginAsAdmin(page);
  });
  test.afterAll(async () => {
    const sessions = F.dbQuery(`SELECT id FROM inventario_sessioni WHERE nome LIKE ${S(TAG + '%')}`).split('\n').filter(Boolean);
    if (sessions.length) {
      F.dbQuery(`DELETE FROM log_modifiche WHERE tabella='inventario_sessioni' AND record_id IN (${sessions.join(',')})`);
      // Children first, in FK order, without relying on ON DELETE CASCADE.
      F.dbQuery(`DELETE FROM inventario_scansioni WHERE sessione_id IN (${sessions.join(',')})`);
      F.dbQuery(`DELETE FROM inventario_attese WHERE sessione_id IN (${sessions.join(',')})`);
      F.dbQuery(`DELETE FROM inventario_sessioni WHERE id IN (${sessions.join(',')})`);
    }
    if (fx.book) {
      F.dbQuery(`DELETE FROM log_modifiche WHERE tabella='libri' AND record_id=${fx.book}`);
      F.dbQuery(`DELETE FROM copie WHERE libro_id=${fx.book}`);
      F.dbQuery(`DELETE FROM libri WHERE id=${fx.book}`);
    }
    F.dbQuery(`DELETE FROM scaffali WHERE codice=${S(TAG)}`);
    await page?.context().close();
  });

  test('C1. a copy recorded as damaged that turns up is reported as found again, with its recorded state', async () => {
    await page.goto(`${F.BASE}/admin/inventory`);
    await page.fill('#inventory-name', `${TAG} danneggiata`);
    await page.selectOption('#inventory-shelf', String(fx.shelf));
    await page.locator('#inventory-open-form button[type="submit"]').click();
    await page.waitForURL(/\/admin\/inventory\/\d+$/, { timeout: 15000 });
    fx.session = Number(new URL(page.url()).pathname.split('/').pop());
    // The damaged copy is not expected on the shelf: it is out of the snapshot.
    expect(F.dbInt(`SELECT COUNT(*) FROM inventario_attese WHERE sessione_id=${fx.session}`)).toBe(2);
    expect((await scan(code('OK'))).esito).toBe('trovata');
    const turnedUp = await scan(code('DMG'));
    expect(turnedUp.esito).toBe('fuori_ambito');
    expect(turnedUp.fuori_circolazione).toBe(true);
    await expect(page.locator('#inventory-feedback')).toContainText('risulta persa o fuori circolazione, ma è stata ritrovata');
    await expect(page.locator('#inventory-feedback')).toContainText('(danneggiato)');
    await expect(page.locator('#inventory-feedback')).not.toContainText('non rientra nel filtro della revisione');
  });

  test('C2. closing with "mark missing as lost" marks only the missing copy and leaves the turned-up one as recorded', async () => {
    await page.goto(`${F.BASE}/admin/inventory/${fx.session}`);
    await page.locator('#inventory-close-form input[value="perso"]').check();
    await page.locator('#inventory-close-form button[type="submit"]').click();
    await expect(page.locator('.swal2-confirm')).toBeVisible({ timeout: 10000 });
    await page.locator('.swal2-confirm').click();
    await page.waitForURL(new RegExp(`/admin/inventory/${fx.session}$`));
    await expect(page.locator('#inventory-state')).toHaveText('Chiusa');
    await expect(page.locator('#inventory-report tr[data-categoria="mancante"]')).toContainText(code('MISS'));
    expect(state('MISS')).toBe('perso');
    expect(state('OK')).toBe('disponibile');
    expect(state('DMG')).toBe('danneggiato');
  });
});

// ── D. MARC review fixes ─────────────────────────────────────────────────
test.describe.serial('D. MARC import and export review fixes', () => {
  test.describe.configure({ timeout: 180000 });
  /** @type {import('@playwright/test').Page} */ let page;
  const MRUN = `RFD${RUN}`;
  const isbnMeeting = isbn13(`978${RUN}11`);
  const isbnDvd = isbn13(`978${RUN}22`);
  const isbnSame = isbn13(`978${RUN}33`);

  test.beforeAll(async ({ browser }) => {
    page = await (await browser.newContext()).newPage();
    await MARC.loginAsAdmin(page);
  });
  test.afterAll(async () => {
    MARC.cleanupRun(MRUN);
    await page?.context().close();
  });

  test('D1. a meeting entry (111/711) keeps its subordinate unit ($e) in the name and reads $j as the relator', async () => {
    const file = marcXmlFile(`meeting-${MRUN}.xml`, [{
      leader: '00000cam a2200000 a 4500',
      fields: [
        ['020', ' ', ' ', [['a', isbnMeeting]]],
        ['111', '2', ' ', [['a', `Convegno ${MRUN}`], ['e', 'Comitato scientifico.'], ['d', '(2001 : Milano)']]],
        ['245', '1', '0', [['a', `Atti ${MRUN}`]]],
        ['711', '2', ' ', [['a', `Seminario ${MRUN}`], ['e', 'Sezione storica.'], ['j', 'editor.']]],
      ],
    }]);
    const upload = await MARC.uploadForPreview(page, file);
    expect(upload.success).toBe(true);
    const result = await MARC.confirmImport(page);
    expect(result.imported).toBe(1);
    const roles = F.dbQuery(`SELECT GROUP_CONCAT(CONCAT(la.ruolo,'=',a.nome) ORDER BY a.nome SEPARATOR ';') FROM libri l JOIN libri_autori la ON la.libro_id=l.id JOIN autori a ON a.id=la.autore_id WHERE l.titolo=${S(`Atti ${MRUN}`)} AND l.deleted_at IS NULL`);
    expect(roles).toBe(`principale=Convegno ${MRUN}. Comitato scientifico;curatore=Seminario ${MRUN}. Sezione storica`);
  });

  test('D2. an overwrite that turns a book into a DVD clears the RDA overrides of the old material', async () => {
    const title = `Film ${MRUN}`;
    F.dbQuery(`INSERT INTO libri (titolo, isbn13, tipo_media, rda_media_type, rda_carrier_type, copie_totali, copie_disponibili) VALUES (${S(title)}, ${S(isbnDvd)}, 'libro', 'h', 'nb', 1, 1)`);
    const file = marcXmlFile(`dvd-${MRUN}.xml`, [{
      leader: '00000cgm a2200000 a 4500',
      fields: [
        ['020', ' ', ' ', [['a', isbnDvd]]],
        ['245', '1', '0', [['a', title]]],
        ['336', ' ', ' ', [['a', 'two-dimensional moving image'], ['b', 'tdi'], ['2', 'rdacontent']]],
      ],
    }]);
    const upload = await MARC.uploadForPreview(page, file, { duplicates: 'overwrite' });
    expect(upload.preview[0].existing_id).toBeGreaterThan(0);
    const result = await MARC.confirmImport(page);
    expect(result.updated).toBe(1);
    expect(F.dbQuery(`SELECT CONCAT_WS('|', tipo_media, IFNULL(rda_content_type,'-'), IFNULL(rda_media_type,'-'), IFNULL(rda_carrier_type,'-')) FROM libri WHERE isbn13=${S(isbnDvd)} AND deleted_at IS NULL`))
      .toBe('dvd|-|-|-');
    // The book page now describes a video, not a sheet of microform.
    const id = F.dbInt(`SELECT id FROM libri WHERE isbn13=${S(isbnDvd)} AND deleted_at IS NULL`);
    await page.goto(`${F.BASE}/admin/books/${id}`);
    await expect(page.locator('body')).not.toContainText('microform');
  });

  test('D3. an overwrite with the same media type keeps the book\'s RDA overrides', async () => {
    const title = `Mappa ${MRUN}`;
    F.dbQuery(`INSERT INTO libri (titolo, isbn13, tipo_media, rda_media_type, rda_carrier_type, copie_totali, copie_disponibili) VALUES (${S(title)}, ${S(isbnSame)}, 'libro', 'h', 'nb', 1, 1)`);
    const file = marcXmlFile(`same-${MRUN}.xml`, [{
      leader: '00000cam a2200000 a 4500',
      fields: [['020', ' ', ' ', [['a', isbnSame]]], ['245', '1', '0', [['a', `${title} (nuova edizione)`]]]],
    }]);
    await MARC.uploadForPreview(page, file, { duplicates: 'overwrite' });
    const result = await MARC.confirmImport(page);
    expect(result.updated).toBe(1);
    expect(F.dbQuery(`SELECT CONCAT_WS('|', titolo, tipo_media, IFNULL(rda_media_type,'-'), IFNULL(rda_carrier_type,'-')) FROM libri WHERE isbn13=${S(isbnSame)} AND deleted_at IS NULL`))
      .toBe(`${title} (nuova edizione)|libro|h|nb`);
  });

  test('D4. a book too large for ISO 2709 even shortened is exported as a reduced record, never skipped', async () => {
    const title = `Enciclopedia ${MRUN}`;
    const token = `zzbig${MRUN}`.toLowerCase();
    F.dbQuery(`INSERT INTO libri (titolo, search_index, descrizione, copie_totali, copie_disponibili) VALUES (${S(title)}, ${S(`${title} ${token}`)}, REPEAT('lunga descrizione ', 800), 1, 1)`);
    const bookId = F.dbInt(`SELECT id FROM libri WHERE titolo=${S(title)} AND deleted_at IS NULL`);
    // 450 contributors with long names: more than 99,999 bytes of 700 fields.
    F.dbQuery(`INSERT INTO autori (nome) WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 450)
      SELECT CONCAT('Autore ${MRUN} ', LPAD(i, 3, '0'), ' ', REPEAT('n', 220)) FROM n`);
    F.dbQuery(`INSERT INTO libri_autori (libro_id, autore_id, ruolo) SELECT ${bookId}, id, IF(nome LIKE 'Autore ${MRUN} 001 %', 'principale', 'co-autore') FROM autori WHERE nome LIKE 'Autore ${MRUN} %'`);
    expect(F.dbInt(`SELECT COUNT(*) FROM libri_autori WHERE libro_id=${bookId}`)).toBe(450);

    await page.goto(`${F.BASE}/admin/books?keywords=${encodeURIComponent(token)}`);
    await expect(page.locator('.row-select')).toHaveCount(1, { timeout: 15000 });
    await page.locator('#select-all').check();
    await expect(page.locator('#selected-count')).toHaveText('1');
    await page.click('#bulk-export-marc');
    await page.locator('.swal2-select').selectOption('marc21');
    const [download] = await Promise.all([page.waitForEvent('download'), page.click('.swal2-confirm')]);
    const file = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'pinakes-rf-export-')), download.suggestedFilename());
    await download.saveAs(file);
    const read = JSON.parse(execFileSync('php', [path.join(ROOT, 'tests', 'helpers', 'marc-inspect.php'), file], { encoding: 'utf-8' }));
    expect(read.errors).toBe(0);
    expect(read.records).toHaveLength(1);
    expect(read.records[0].titolo).toBe(title);
    // Reduced: at most 20 contributors, the first one kept.
    expect(read.records[0].autori.length).toBeGreaterThan(0);
    expect(read.records[0].autori.length).toBeLessThanOrEqual(20);
    expect(read.records[0].autori[0]).toContain(`Autore ${MRUN} 001`);
  });
});

// ── E. #501 quoted search on the boxes the stack added ───────────────────
test.describe.serial('E. quoted search (#501) on the new search boxes', () => {
  /** @type {import('@playwright/test').Page} */ let page;
  const ERUN = `RFE${RUN}`;
  const suppliers = {};
  const books = [];
  const patrons = [];
  let typeId = 0;
  const visibleSuppliers = async () => (await page.locator('[data-supplier-row]:not(.hidden) td:first-child a').allInnerTexts()).map((t) => t.trim()).sort();

  async function filterSuppliers(value) {
    await page.locator('#search_fornitore').fill(value);
    await page.locator('#search_fornitore').dispatchEvent('input');
  }

  test.beforeAll(async ({ browser }) => {
    for (const [key, name] of [['umlaut', `Bücherei Müller ${ERUN}`], ['plain', `Bucherei Muller ${ERUN}`], ['other', `Libreria Rossi ${ERUN}`]]) {
      F.dbQuery(`INSERT INTO fornitori (nome) VALUES (${S(name)})`);
      suppliers[key] = { id: F.dbInt(`SELECT id FROM fornitori WHERE nome=${S(name)} COLLATE utf8mb4_bin`), name };
    }
    F.dbQuery(`INSERT INTO tipologie_lettore (codice, nome, predefinita, attiva, ordinamento) VALUES (${S(ERUN.toLowerCase())}, ${S('Tipo ' + ERUN)}, 0, 1, 99)`);
    typeId = F.dbInt(`SELECT id FROM tipologie_lettore WHERE codice=${S(ERUN.toLowerCase())}`);
    // Own inserts: the card codes must differ by more than an accent (unique index, unicode_ci).
    for (const [i, surname, type] of [['a', `Häffner${ERUN}`, typeId], ['b', `Haffner${ERUN}`, typeId], ['c', `Häffner${ERUN}`, null]]) {
      const email = `rfe-${i}-${RUN}@circ.test`;
      F.dbQuery(`INSERT INTO utenti (nome, cognome, email, password, tipo_utente, stato, email_verificata, codice_tessera, privacy_accettata, tipologia_id, created_at)
        VALUES ('Circ', ${S(surname)}, ${S(email)}, ${S(F.phpHash(PASS))}, 'standard', 'attivo', 1, ${S(`RFE${i}${RUN}`)}, 1, ${type || 'NULL'}, NOW())`);
      patrons.push({ id: F.dbInt(`SELECT id FROM utenti WHERE email=${S(email)}`), email });
    }
    for (const [title, supplier] of [[`Brücke ${ERUN}`, suppliers.umlaut.id], [`Brucke ${ERUN}`, suppliers.umlaut.id], [`Brücke ${ERUN} senza`, null]]) {
      F.dbQuery(`INSERT INTO libri (titolo, search_index, fornitore_id, copie_totali, copie_disponibili) VALUES (${S(title)}, ${S(title)}, ${supplier || 'NULL'}, 1, 1)`);
      books.push(F.dbInt(`SELECT id FROM libri WHERE titolo=${S(title)} COLLATE utf8mb4_bin AND deleted_at IS NULL`));
    }
    page = await (await browser.newContext()).newPage();
    await F.loginAsAdmin(page);
  });
  test.afterAll(async () => {
    F.deleteTitles(books);
    F.deletePatrons(patrons.map((p) => p.id));
    F.dbQuery(`DELETE FROM tipologie_lettore WHERE id=${typeId}`);
    F.dbQuery(`DELETE FROM fornitori WHERE nome LIKE ${S(`%${ERUN}`)}`);
    await page?.context().close();
  });

  test('E1. suppliers list: an unquoted word finds every spelling, accents or not', async () => {
    await page.goto(`${F.BASE}/admin/suppliers`);
    await expect(page.locator('#search_fornitore_hint')).toContainText('virgolette');
    await filterSuppliers(`muller ${ERUN}`);
    expect(await visibleSuppliers()).toEqual([suppliers.plain.name, suppliers.umlaut.name].sort());
    await filterSuppliers(`MÜLLER ${ERUN}`);
    expect(await visibleSuppliers()).toEqual([suppliers.plain.name, suppliers.umlaut.name].sort());
  });

  test('E2. suppliers list: a quoted word keeps only the exact spelling, case ignored', async () => {
    await page.goto(`${F.BASE}/admin/suppliers`);
    await filterSuppliers(`"Müller" ${ERUN}`);
    expect(await visibleSuppliers()).toEqual([suppliers.umlaut.name]);
    await filterSuppliers(`"muller" ${ERUN}`);
    expect(await visibleSuppliers()).toEqual([suppliers.plain.name]);
    await filterSuppliers(`“bücherei müller” ${ERUN}`);
    expect(await visibleSuppliers()).toEqual([suppliers.umlaut.name]);
  });

  test('E3. suppliers list: each word keeps its own rule, and a box with only quotes filters nothing', async () => {
    await page.goto(`${F.BASE}/admin/suppliers`);
    await filterSuppliers(`bucherei "Muller" ${ERUN}`);
    expect(await visibleSuppliers()).toEqual([suppliers.plain.name]);
    await filterSuppliers(`"Rossi" muller ${ERUN}`);
    expect(await visibleSuppliers()).toEqual([]);
    await filterSuppliers('""');
    expect((await visibleSuppliers()).filter((n) => n.endsWith(ERUN))).toEqual(Object.values(suppliers).map((s) => s.name).sort());
  });

  /** The next list response whose query string has every given value. */
  const listResponse = (api, params) => page.waitForResponse((r) => {
    if (!r.url().includes(api)) return false;
    const q = new URL(r.url()).searchParams;
    return Object.entries(params).every(([k, v]) => (q.get(k) || '') === String(v));
  }, { timeout: 15000 });

  test('E4. users list: the patron type filter and a quoted surname combine', async () => {
    await page.goto(`${F.BASE}/admin/users`);
    let resp = listResponse('/api/utenti', { patron_type_filter: typeId });
    await page.locator('#patron_type_filter').selectOption(String(typeId));
    let json = await (await resp).json();
    const rowsOf = (j) => (j.data || []).map((r) => JSON.stringify(r)).join(' ');
    resp = listResponse('/api/utenti', { patron_type_filter: typeId, search_text: `"Häffner${ERUN}"` });
    await page.locator('#search_text').fill(`"Häffner${ERUN}"`);
    await page.locator('#search_text').dispatchEvent('keyup');
    json = await (await resp).json();
    expect(json.recordsFiltered).toBe(1);
    expect(rowsOf(json)).toContain(patrons[0].email);
    resp = listResponse('/api/utenti', { patron_type_filter: typeId, search_text: `haffner${ERUN}` });
    await page.locator('#search_text').fill(`haffner${ERUN}`);
    await page.locator('#search_text').dispatchEvent('keyup');
    json = await (await resp).json();
    // Unquoted: both spellings of this type, never the reader of the default type.
    expect(json.recordsFiltered).toBe(2);
    expect(rowsOf(json)).not.toContain(patrons[2].email);
  });

  test('E5. book list: the supplier filter and a quoted title combine', async () => {
    // The librarian follows "Libri di questo fornitore" from the suppliers list.
    await page.goto(`${F.BASE}/admin/suppliers`);
    let resp = listResponse('/api/libri', { fornitore_filter: suppliers.umlaut.id });
    await page.locator(`[data-supplier-row] a[href$="/admin/books?fornitore=${suppliers.umlaut.id}"]`).click();
    let json = await (await resp).json();
    const rowsOf = (j) => (j.data || []).map((r) => JSON.stringify(r)).join(' ');
    expect(json.recordsFiltered).toBe(2);
    resp = listResponse('/api/libri', { fornitore_filter: suppliers.umlaut.id, search_text: `"Brücke" ${ERUN}` });
    await page.locator('#search_text').fill(`"Brücke" ${ERUN}`);
    await page.locator('#search_text').dispatchEvent('keyup');
    json = await (await resp).json();
    expect(json.recordsFiltered).toBe(1);
    expect(rowsOf(json)).toContain(`Brücke ${ERUN}`);
    expect(rowsOf(json)).not.toContain('senza');
    resp = listResponse('/api/libri', { fornitore_filter: suppliers.umlaut.id, search_text: `brucke ${ERUN}` });
    await page.locator('#search_text').fill(`brucke ${ERUN}`);
    await page.locator('#search_text').dispatchEvent('keyup');
    json = await (await resp).json();
    expect(json.recordsFiltered).toBe(2);
    expect(rowsOf(json)).not.toContain('senza');
  });
});
