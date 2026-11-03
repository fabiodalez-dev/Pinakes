// @ts-check
// A closure or a holiday added AFTER a loan was made. The loan is lent at the
// desk first (admin UI); the library then marks its due day closed from
// Admin → Calendar (a closure, a library holiday, a weekly closed day, the
// national preset) or switches the closures module on. A due date still to
// come moves to the next open day, with an entry in the book history; a move
// the copy cannot take (another reader booked it from the next day) leaves
// the loan as it was. A due date already past is not moved, but the reader
// returning on the first open day after it is neither late nor fined, and the
// overdue sweep waits for that day too. Expected dates are computed here.
//
// Run: /tmp/run-e2e.sh tests/closures-calendar-existing-loans.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const F = require('./helpers/circulation-fixtures');
const ft = require('./helpers/feature-toggles');
const { snapshotLoans, restoreLoans } = require('./helpers/calendar-loan-snapshot');

test.skip(!F.CONFIGURED, 'E2E credentials not configured');

const ROOT = path.resolve(__dirname, '..');
const RUN = String(Date.now()).slice(-6);
const TAG = `E2E-RETRO-${RUN}`;
const db = F.dbQuery;

function addDays(iso, days) {
  const [y, m, d] = iso.split('-').map(Number);
  const t = new Date(Date.UTC(y, m - 1, d));
  t.setUTCDate(t.getUTCDate() + days);
  return t.toISOString().slice(0, 10);
}
const weekday = (iso) => { const [y, m, d] = iso.split('-').map(Number); return new Date(Date.UTC(y, m - 1, d)).getUTCDay(); };
const loanRow = (id) => db(`SELECT CONCAT(stato, '|', data_scadenza, '|', IFNULL(pickup_deadline, '-')) FROM prestiti WHERE id=${id}`);
const dueMovedEvents = (bookId) => F.dbInt(`SELECT COUNT(*) FROM log_modifiche WHERE tabella='libri' AND record_id=${bookId} AND JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi, '$._activity.event'))='loan.due_moved'`);

/** Settings this suite changes, by [category, key]. */
const SETTINGS = [['loans', 'closed_weekdays'], ['loans', 'daily_fine'], ['loans', 'fine_grace_days'], ['loans', 'fine_max_per_loan']];
const prev = {
  ready: false,
  /** @type {Record<string, any>} */ settings: {},
  /** @type {string[][]} */ holidaySettings: [],
  closureBackup: `e2e_retro_closures_${RUN}`,
  /** @type {string[]} */ closureColumns: [],
  /** @type {number[]} */ activeCustomIds: [],
  /** @type {Record<string, string>} */ features: {},
};
/** Active loans of other readers, restored after the run. */
let loanSnap = null;
const created = { closures: /** @type {Set<number>} */ (new Set()), customs: /** @type {Set<number>} */ (new Set()) };
const ids = (set) => [...set].join(',') || '0';
/** @type {number[]} */ const titles = [];
let patron; let other;
let today = '';

async function submitAndLand(page, button) {
  await Promise.all([page.waitForURL(/\/admin\/calendar/, { timeout: 15000 }), page.locator(button).click()]);
}
async function addClosure(page, day) {
  await page.goto(`${F.BASE}/admin/calendar#calendar-closure-form`);
  await page.fill('#closure_start', day);
  await page.fill('#closure_description', `${TAG} ${day}`);
  await submitAndLand(page, '#save-closure');
  await expect(page.locator('#calendar-closures')).toContainText(`${TAG} ${day}`);
  created.closures.add(F.dbInt(`SELECT id FROM calendario_chiusure WHERE descrizione='${TAG} ${day}' ORDER BY id DESC LIMIT 1`));
}
async function addLibraryHoliday(page, day) {
  await page.goto(`${F.BASE}/admin/calendar#calendar-custom`);
  await page.fill('#custom_name', `${TAG} ${day}`);
  await page.selectOption('#custom_kind', 'fixed');
  await page.fill('#custom_fixed_day', String(Number(day.slice(8, 10))));
  await page.selectOption('#custom_fixed_month', String(Number(day.slice(5, 7))));
  await page.fill('#custom_days', '1');
  await submitAndLand(page, '#save-custom-holiday');
  created.customs.add(F.dbInt(`SELECT id FROM festivita_personalizzate WHERE nome='${TAG} ${day}' ORDER BY id DESC LIMIT 1`));
}
async function setWeekdays(page, days) {
  await page.goto(`${F.BASE}/admin/calendar#calendar-weekdays`);
  for (let w = 0; w <= 6; w++) {
    const box = page.locator(`#closed_weekday_${w}`);
    if ((await box.isChecked()) !== days.includes(w)) await box.click();
  }
  await submitAndLand(page, '#save-weekdays');
}
/** A desk loan as a librarian makes it; `due` picks another due date in the form. */
async function deskLoan(page, book, { due = '', immediate = true } = {}) {
  await page.goto(`${F.BASE}/admin/loans/create`);
  await F.fillAutocomplete(page, '#utente_search', '#utente_suggest', patron.surname, '/api/search/utenti');
  await F.fillAutocomplete(page, '#libro_search', '#libro_suggest', book.title, '/api/search/libri');
  await expect(page.locator('#libro_id')).toHaveValue(String(book.id));
  await page.waitForTimeout(400);
  if (due) {
    await expect.poll(() => page.evaluate(() => !!(/** @type {any} */ (document.getElementById('data_scadenza')))._flatpickr)).toBe(true);
    await page.evaluate((d) => { /** @type {any} */ (document.getElementById('data_scadenza'))._flatpickr.setDate(d, true); }, due);
    await expect(page.locator('#data_scadenza')).toHaveValue(due);
  }
  if (!immediate) await page.uncheck('#consegna_immediata');
  await page.uncheck('#scarica_pdf').catch(() => {});
  await Promise.all([
    page.waitForURL((u) => u.toString().includes('created=1') || u.toString().includes('error='), { timeout: 20000 }),
    page.click('form[action$="/admin/loans/create"] button[type="submit"]:not([name="save_and_new"])'),
  ]);
  expect(page.url()).toContain('created=1');
  return F.dbInt(`SELECT id FROM prestiti WHERE libro_id=${book.id} AND utente_id=${patron.id} ORDER BY id DESC LIMIT 1`);
}
/** A loan lent `daysAgo` days ago and due yesterday: the desk cannot lend in the past. */
function pastLoan(book, due) {
  const copy = book.copyIds[0];
  db(`UPDATE copie SET stato='prestato' WHERE id=${copy}`);
  db(`UPDATE libri SET copie_disponibili=0, stato='prestato' WHERE id=${book.id}`);
  db(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (${book.id}, ${copy}, ${patron.id}, '${addDays(due, -14)}', '${due}', 'in_corso', 'diretto', 1)`);
  return F.dbInt(`SELECT id FROM prestiti WHERE libro_id=${book.id} AND attivo=1 ORDER BY id DESC LIMIT 1`);
}
function title(tag) {
  const book = F.createTitle(`${RUN} ${tag}`, 'libro', 1);
  titles.push(book.id);
  return book;
}
/** The fine the return screen announces (cents), 0 when it shows none. */
async function previewedFine(page, loanId) {
  await page.goto(`${F.BASE}/admin/loans/returned/${loanId}`);
  await expect(page.getByRole('button', { name: 'Conferma restituzione' })).toBeVisible();
  const box = page.locator('#fine-preview');
  if (!(await box.isVisible().catch(() => false))) return 0;
  return Number(await box.getAttribute('data-fine-cents'));
}
function runMaintenance() {
  execFileSync('php', [path.join(ROOT, 'cron/full-maintenance.php')], { cwd: ROOT, encoding: 'utf-8', timeout: 180000 });
}

test.describe.serial('Closures added after the loan', () => {
  test.describe.configure({ timeout: 180000 });
  /** @type {import('@playwright/test').Page} */ let page;
  let ctx;

  test.beforeAll(async ({ browser }) => {
    // Snapshot first, every piece, before changing anything.
    for (const [category, key] of SETTINGS) prev.settings[key] = F.readSettingState(category, key);
    prev.holidaySettings = db("SELECT CONCAT(HEX(setting_key), ' ', HEX(setting_value)) FROM system_settings WHERE category='calendar' AND setting_key LIKE 'holiday\\_%'")
      .split('\n').filter(Boolean).map((line) => line.split(' '));
    prev.activeCustomIds = db('SELECT id FROM festivita_personalizzate WHERE attiva=1').split('\n').filter(Boolean).map(Number);
    prev.closureColumns = db("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='calendario_chiusure' ORDER BY ORDINAL_POSITION").split('\n').filter(Boolean);
    db(`CREATE TABLE ${prev.closureBackup} AS SELECT * FROM calendario_chiusure`);
    loanSnap = snapshotLoans(db, `e2e_retro_loans_${RUN}`);
    prev.features = ft.readFeatures(['closures_calendar', 'fines', 'automatic_fines']);
    prev.ready = true;

    // Only the closures this suite adds: the library's own are off for the run.
    await ft.writeFeatures({ closures_calendar: '1', fines: '1' });
    db('UPDATE calendario_chiusure SET attiva=0 WHERE attiva=1');
    db(`UPDATE festivita_personalizzate SET attiva=0 WHERE id IN (${prev.activeCustomIds.join(',') || '0'})`);
    db("DELETE FROM system_settings WHERE category='calendar' AND setting_key LIKE 'holiday\\_%'");
    db("DELETE FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'");

    patron = F.createPatron(`RETRO${RUN}`);
    other = F.createPatron(`RETROB${RUN}`);
    ctx = await browser.newContext();
    page = await ctx.newPage();
    await F.loginAsAdmin(page);
  });

  test.beforeEach(() => {
    db(`UPDATE calendario_chiusure SET attiva=0 WHERE attiva=1 AND id IN (SELECT id FROM ${prev.closureBackup})`);
    db(`DELETE FROM calendario_chiusure WHERE id IN (${ids(created.closures)})`);
    db(`DELETE FROM festivita_personalizzate WHERE id IN (${ids(created.customs)})`);
    created.closures.clear();
    created.customs.clear();
    db("DELETE FROM system_settings WHERE category='calendar' AND setting_key LIKE 'holiday\\_%'");
    db("DELETE FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'");
    today = F.appTodayISO();
  });

  test.afterAll(async () => {
    try { db(`DELETE FROM calendario_chiusure WHERE id IN (${ids(created.closures)})`); } catch { /* */ }
    try { db(`DELETE FROM festivita_personalizzate WHERE id IN (${ids(created.customs)})`); } catch { /* */ }
    if (prev.ready) {
      const cols = prev.closureColumns.map((c) => `\`${c}\``);
      const set = cols.filter((c) => c !== '`id`').map((c) => `c.${c}=b.${c}`).join(', ');
      db(`UPDATE calendario_chiusure c JOIN ${prev.closureBackup} b ON b.id=c.id SET ${set}`);
      db(`INSERT INTO calendario_chiusure (${cols.join(', ')}) SELECT ${cols.map((c) => `b.${c}`).join(', ')} FROM ${prev.closureBackup} b LEFT JOIN calendario_chiusure c ON c.id=b.id WHERE c.id IS NULL`);
      db(`UPDATE festivita_personalizzate SET attiva=1 WHERE id IN (${prev.activeCustomIds.join(',') || '0'})`);
      db("DELETE FROM system_settings WHERE category='calendar' AND setting_key LIKE 'holiday\\_%'");
      for (const [key, value] of prev.holidaySettings) {
        db(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('calendar', UNHEX('${key}'), UNHEX('${value}'))`);
      }
      for (const [category, key] of SETTINGS) F.restoreSettingState(category, key, prev.settings[key]);
      await ft.writeFeatures(prev.features);
    }
    restoreLoans(db, loanSnap);
    db(`DROP TABLE IF EXISTS ${prev.closureBackup}`);
    for (const p of [patron, other]) {
      if (!p) continue;
      try { db(`DELETE FROM movimenti_lettore WHERE utente_id=${p.id}`); } catch { /* */ }
    }
    F.deleteTitles(titles);
    F.deletePatrons([patron?.id, other?.id].filter(Boolean));
    await ctx?.close();
  });

  // ── Due dates still to come ──────────────────────────────────────────────
  test('Move 1: a closure added on the due date of a loan out moves it to the next day, with a history entry', async () => {
    const book = title('closure');
    const loanId = await deskLoan(page, book);
    const due = loanRow(loanId).split('|')[1];
    // The due-soon reminder already went out for the old date.
    db(`UPDATE prestiti SET warning_sent=1 WHERE id=${loanId}`);
    await addClosure(page, due);
    expect(loanRow(loanId)).toBe(`in_corso|${addDays(due, 1)}|-`);
    expect(dueMovedEvents(book.id)).toBe(1);
    // ...and is sent again before the new one.
    expect(db(`SELECT warning_sent FROM prestiti WHERE id=${loanId}`)).toBe('0');
    // The book page history says why the date changed.
    await page.goto(`${F.BASE}/admin/books/${book.id}`);
    await expect(page.getByText('Scadenza spostata: la biblioteca è chiusa quel giorno').first()).toBeVisible();
  });

  test('Move 2: a library holiday added on the due date moves it', async () => {
    const book = title('holiday');
    const loanId = await deskLoan(page, book);
    const due = loanRow(loanId).split('|')[1];
    await addLibraryHoliday(page, due);
    expect(loanRow(loanId).split('|')[1]).toBe(addDays(due, 1));
  });

  test('Move 3: a weekly closed day saved afterwards moves a due date on that weekday', async () => {
    const book = title('weekly');
    const loanId = await deskLoan(page, book);
    const due = loanRow(loanId).split('|')[1];
    await setWeekdays(page, [weekday(due)]);
    expect(loanRow(loanId).split('|')[1]).toBe(addDays(due, 1));
  });

  test('Move 4: the Italian preset applied afterwards moves a loan due on Christmas past St Stephen', async () => {
    let christmas = `${today.slice(0, 4)}-12-25`;
    if (christmas <= addDays(today, 1)) christmas = `${Number(today.slice(0, 4)) + 1}-12-25`;
    test.skip(christmas > addDays(today, 85), 'Christmas is further than a loan can last');
    const book = title('christmas');
    const loanId = await deskLoan(page, book, { due: christmas });
    expect(loanRow(loanId).split('|')[1]).toBe(christmas);
    await page.goto(`${F.BASE}/admin/calendar?holiday_country=IT#calendar-preset`);
    await page.locator('[data-holiday-select="none"]').click();
    for (const id of ['christmas', 'st_stephen']) await page.locator(`[data-holiday-row="IT:${id}"] input[data-holiday-rule]`).check();
    const before = F.dbInt('SELECT COALESCE(MAX(id), 0) FROM calendario_chiusure');
    await submitAndLand(page, '#import-preset');
    for (const id of db(`SELECT id FROM calendario_chiusure WHERE id > ${before} AND regola_festivita LIKE 'IT:%'`).split('\n').filter(Boolean)) created.closures.add(Number(id));
    expect(loanRow(loanId).split('|')[1]).toBe(addDays(christmas, 2));
  });

  test('Move 5: a pickup still waiting gets the new due date, and its deadline stays within it', async () => {
    const book = title('pickup');
    const loanId = await deskLoan(page, book, { immediate: false });
    const [, due] = loanRow(loanId).split('|');
    await addClosure(page, due);
    const [state, newDue, deadline] = loanRow(loanId).split('|');
    expect(state).toBe('da_ritirare');
    expect(newDue).toBe(addDays(due, 1));
    expect(deadline <= newDue).toBe(true);
  });

  test('Move 6: when another reader booked the copy from the next day, the loan keeps its date and the closure is still saved', async () => {
    const book = title('booked');
    const loanId = await deskLoan(page, book);
    const due = loanRow(loanId).split('|')[1];
    // The other reader's booking starts the day after: moving would overlap.
    db(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (${book.id}, ${book.copyIds[0]}, ${other.id}, '${addDays(due, 1)}', '${addDays(due, 15)}', 'prenotato', 'prenotazione', 1)`);
    const errors = [];
    page.on('response', (r) => { if (r.status() >= 500) errors.push(r.url()); });
    await addClosure(page, due);
    expect(errors).toEqual([]);
    expect(loanRow(loanId).split('|')[1]).toBe(due);
    expect(dueMovedEvents(book.id)).toBe(0);
  });

  test('Move 8: when the added day is promised to a reservation without a copy, the loan keeps its date', async () => {
    const book = title('promised');
    const loanId = await deskLoan(page, book);
    const due = loanRow(loanId).split('|')[1];
    // Another reader's booking of the title (no copy pinned yet) from the next day.
    db(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (${book.id}, NULL, ${other.id}, '${addDays(due, 1)}', '${addDays(due, 15)}', 'prenotato', 'prenotazione', 1)`);
    await addClosure(page, due);
    expect(loanRow(loanId).split('|')[1]).toBe(due);
    expect(dueMovedEvents(book.id)).toBe(0);
  });

  test('Move 9: the maintenance an admin login runs moves a due date closed without a calendar save, as the system', async ({ browser }) => {
    const book = title('cron');
    const loanId = await deskLoan(page, book);
    const due = loanRow(loanId).split('|')[1];
    // A closure that reached the table without the calendar page (an import).
    db(`INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, attiva) VALUES ('${due}', '${due}', '${TAG} cron', 1)`);
    created.closures.add(F.dbInt(`SELECT id FROM calendario_chiusure WHERE descrizione='${TAG} cron'`));
    expect(loanRow(loanId).split('|')[1]).toBe(due);
    // An administrator logging in runs the maintenance pass (once an hour):
    // with someone in session, the move must still be the system's.
    const lastRun = F.readSettingState('maintenance', 'last_run');
    const fresh = await browser.newContext();
    try {
      db("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('maintenance','last_run','0') ON DUPLICATE KEY UPDATE setting_value='0'");
      await F.loginAsAdmin(await fresh.newPage());
      await expect.poll(() => loanRow(loanId).split('|')[1], { timeout: 30000 }).toBe(addDays(due, 1));
    } finally {
      await fresh.close();
      F.restoreSettingState('maintenance', 'last_run', lastRun);
    }
    expect(db(`SELECT IFNULL(utente_id, 'system') FROM log_modifiche WHERE tabella='libri' AND record_id=${book.id} AND JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi, '$._activity.event'))='loan.due_moved'`)).toBe('system');
  });

  test('Move 7: switching the closures module on moves a due date that falls on a stored closure', async () => {
    const book = title('module');
    await ft.setModuleInUi(page, 'closures_calendar', false);
    let loanId = 0;
    try {
      loanId = await deskLoan(page, book);
      const due = loanRow(loanId).split('|')[1];
      db(`INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, attiva) VALUES ('${due}', '${due}', '${TAG} module', 1)`);
      created.closures.add(F.dbInt(`SELECT id FROM calendario_chiusure WHERE descrizione='${TAG} module'`));
      expect(loanRow(loanId).split('|')[1]).toBe(due);
      await ft.setModuleInUi(page, 'closures_calendar', true);
      expect(loanRow(loanId).split('|')[1]).toBe(addDays(due, 1));
    } finally {
      await ft.writeFeatures({ closures_calendar: '1' });
    }
  });

  // ── Due dates already past ───────────────────────────────────────────────
  test('Past 1: returned on the first open day after a due day closed afterwards, the loan has no fine', async () => {
    // Fines on with 1 € a day, from Settings → Loans as an operator does it.
    await page.goto(`${F.BASE}/admin/settings?tab=loans`);
    for (const key of ['fines', 'automatic_fines']) {
      if (!(await page.locator(`#feature_${key}`).isChecked())) await page.locator(`label:has(#feature_${key})`).click();
    }
    await page.fill('#daily_fine', '1,00');
    await page.fill('#fine_grace_days', '0');
    await page.fill('#fine_max_per_loan', '');
    await page.locator('form[action$="/admin/settings/loans"] button[type="submit"]').click();
    await page.waitForURL(/tab=loans/, { timeout: 15000 });
    expect(db("SELECT CONCAT((SELECT setting_value FROM system_settings WHERE category='features' AND setting_key='fines'), '|', (SELECT setting_value FROM system_settings WHERE category='features' AND setting_key='automatic_fines'), '|', (SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='daily_fine'))")).toMatch(/^1\|1\|1[.,]00$/);
    const yesterday = addDays(today, -1);
    const closedDue = pastLoan(title('fine-closed'), yesterday);
    // Control: the same loan without the closure owes one day.
    expect(await previewedFine(page, closedDue)).toBe(100);
    await addClosure(page, yesterday);
    expect(loanRow(closedDue).split('|')[1]).toBe(yesterday);
    expect(await previewedFine(page, closedDue)).toBe(0);
  });

  test('Past 3: a loan already marked late goes back to "in corso" when its due day is closed afterwards', async () => {
    const yesterday = addDays(today, -1);
    const loanId = pastLoan(title('reconcile'), yesterday);
    db(`UPDATE prestiti SET stato='in_ritardo', overdue_notification_sent=1 WHERE id=${loanId}`);
    await addClosure(page, yesterday);
    expect(loanRow(loanId).split('|')[0]).toBe('in_corso');
    // The notice already sent stays recorded: no second overdue e-mail later.
    expect(db(`SELECT overdue_notification_sent FROM prestiti WHERE id=${loanId}`)).toBe('1');
  });

  test('Past 2: the overdue sweep waits for the first open day after a due day closed afterwards', async () => {
    const yesterday = addDays(today, -1);
    const closedDue = pastLoan(title('sweep-closed'), yesterday);
    const openDue = pastLoan(title('sweep-open'), yesterday);
    // A closure of the first loan's copy branch only: the other stays open.
    db(`INSERT INTO sedi (nome) VALUES ('${TAG} sede')`);
    const sede = F.dbInt(`SELECT id FROM sedi WHERE nome='${TAG} sede'`);
    const closedCopy = F.dbInt(`SELECT copia_id FROM prestiti WHERE id=${closedDue}`);
    try {
      db(`UPDATE copie SET sede_id=${sede} WHERE id=${closedCopy}`);
      await page.goto(`${F.BASE}/admin/calendar#calendar-closure-form`);
      await page.fill('#closure_start', yesterday);
      await page.fill('#closure_description', `${TAG} branch ${yesterday}`);
      await page.selectOption('#closure_branch', String(sede));
      await submitAndLand(page, '#save-closure');
      created.closures.add(F.dbInt(`SELECT id FROM calendario_chiusure WHERE descrizione='${TAG} branch ${yesterday}'`));
      runMaintenance();
      expect(loanRow(closedDue).split('|')[0]).toBe('in_corso');
      expect(loanRow(openDue).split('|')[0]).toBe('in_ritardo');
    } finally {
      db(`UPDATE copie SET sede_id=NULL WHERE id=${closedCopy}`);
      db(`DELETE FROM calendario_chiusure WHERE id IN (${ids(created.closures)})`);
      created.closures.clear();
      db(`DELETE FROM sedi WHERE id=${sede}`);
    }
  });
});
