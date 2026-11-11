// @ts-check
/**
 * Shared bench for the four `loans-120-*.spec.js` suites (120 end-to-end tests
 * on loans, reservations and the closures calendar).
 *
 * What a test does goes through the pages a librarian or a reader uses: the
 * desk loan form, the pending-loans page, the return form, the book page with
 * its request calendar, the reader's "Prenotazioni" page, Admin → Calendar.
 * The database is used for fixtures (a loan lent in the past cannot be made
 * at the desk) and to read the outcome.
 *
 * The bench isolates the calendar for the run: the library's own closures and
 * holidays are switched off and put back afterwards, so every expected date
 * depends only on what the test configured. Expected dates are computed here,
 * from the closed days the test declared, never by the application.
 */
const { expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const F = require('./circulation-fixtures');
const ft = require('./feature-toggles');
const { flushCache } = require('./flush-cache');
const { snapshotLoans, restoreLoans } = require('./calendar-loan-snapshot');

const ROOT = path.resolve(__dirname, '../..');
const db = F.dbQuery;
const dbInt = F.dbInt;

// ── Dates (pure, UTC) ──────────────────────────────────────────────────────
function addDays(iso, days) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  if (!m) throw new Error(`not an ISO date: ${iso}`);
  const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}
function weekday(iso) {
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d)).getUTCDay();
}
function daysBetween(a, b) {
  return Math.round((Date.parse(`${b}T00:00:00Z`) - Date.parse(`${a}T00:00:00Z`)) / 86400000);
}

/** system_settings rows (category loans) a suite may change; all restored. */
const LOAN_SETTINGS = ['closed_weekdays', 'max_active_loans_per_user', 'max_renewals', 'pickup_expiry_days', 'auto_approve_requests',
  'loan_duration_days', 'max_loan_duration_days', 'allow_multiple_loans_same_book', 'daily_fine', 'fine_grace_days', 'fine_max_per_loan',
  'same_day_handover_after_closure'];
const FEATURES = ['closures_calendar', 'fines', 'automatic_fines', 'circulation_rules', 'debt_block', 'consultation_only'];

/**
 * @param {string} prefix short tag, unique per suite (e.g. "LC")
 */
function createLab(prefix) {
  const RUN = String(Date.now()).slice(-6);
  const TAG = `E2E-${prefix}-${RUN}`;
  const lab = {
    RUN, TAG, BASE: F.BASE, F, db, dbInt, addDays, weekday, daysBetween,
    today: '',
    loanDays: 30, maxLoanDays: 90, pickupDays: 3,
    /** @type {Set<string>} */ closed: new Set(),
    /** @type {Set<number>} */ closedWeekdays: new Set(),
    /** @type {number[]} */ titles: [],
    /** @type {number[]} */ patrons: [],
    /** @type {number[]} */ branches: [],
  };
  const prev = {
    ready: false,
    /** @type {Record<string, any>} */ settings: {},
    /** @type {string[][]} */ holidaySettings: [],
    closureBackup: `e2e_${prefix.toLowerCase()}_closures_${RUN}`,
    /** @type {string[]} */ closureColumns: [],
    /** @type {number[]} */ activeCustomIds: [],
    /** @type {Record<string, any>} */ features: {},
  };
  /** @type {any} */ let loanSnap = null;
  const created = { closures: /** @type {Set<number>} */ (new Set()), customs: /** @type {Set<number>} */ (new Set()) };
  const ids = (set) => [...set].join(',') || '0';
  let seq = 0;

  // ── Expected-date model ──────────────────────────────────────────────────
  lab.isOpen = (d) => !lab.closed.has(d) && !lab.closedWeekdays.has(weekday(d));
  lab.nextOpen = (day) => { let d = day; for (let i = 0; i < 800 && !lab.isOpen(d); i++) d = addDays(d, 1); return d; };
  /** N open days after `from`, optionally capped. */
  lab.addOpenDays = (from, n, capAt = '') => {
    let d = from;
    for (let counted = 0, i = 0; counted < n && i < 800; i++) { d = addDays(d, 1); if (lab.isOpen(d)) counted++; }
    return capAt && d > capAt ? capAt : d;
  };
  /** Open days strictly after `from` up to `to` included. */
  lab.openDaysBetween = (from, to) => { let n = 0; for (let d = addDays(from, 1); d <= to; d = addDays(d, 1)) if (lab.isOpen(d)) n++; return n; };
  lab.markClosed = (start, end = start) => { for (let d = start; d <= end; d = addDays(d, 1)) lab.closed.add(d); };

  // ── Environment ──────────────────────────────────────────────────────────
  lab.setting = (key, def) => {
    const v = db(`SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key=${F.sqlStr(key)} LIMIT 1`);
    return v === '' ? def : v;
  };
  lab.setSetting = async (key, value) => {
    if (!LOAN_SETTINGS.includes(key)) throw new Error(`setting ${key} is not snapshotted`);
    if (value === null) db(`DELETE FROM system_settings WHERE category='loans' AND setting_key=${F.sqlStr(key)}`);
    else db(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans', ${F.sqlStr(key)}, ${F.sqlStr(value)}) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
    await flushCache();
  };
  lab.refreshNumbers = () => {
    lab.loanDays = parseInt(lab.setting('loan_duration_days', '30'), 10) || 30;
    lab.maxLoanDays = parseInt(lab.setting('max_loan_duration_days', '90'), 10) || 90;
    lab.pickupDays = Math.max(1, parseInt(lab.setting('pickup_expiry_days', '3'), 10) || 3);
  };

  lab.setup = async ({ features = {} } = {}) => {
    for (const key of LOAN_SETTINGS) prev.settings[key] = F.readSettingState('loans', key);
    prev.holidaySettings = db("SELECT CONCAT(HEX(setting_key), ' ', HEX(setting_value)) FROM system_settings WHERE category='calendar' AND setting_key LIKE 'holiday\\_%'")
      .split('\n').filter(Boolean).map((line) => line.split(' '));
    prev.activeCustomIds = db('SELECT id FROM festivita_personalizzate WHERE attiva=1').split('\n').filter(Boolean).map(Number);
    prev.closureColumns = db("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='calendario_chiusure' ORDER BY ORDINAL_POSITION").split('\n').filter(Boolean);
    db(`CREATE TABLE ${prev.closureBackup} AS SELECT * FROM calendario_chiusure`);
    loanSnap = snapshotLoans(db, `e2e_${prefix.toLowerCase()}_loans_${RUN}`);
    prev.features = ft.readFeatures(FEATURES);
    prev.ready = true;

    await ft.writeFeatures({ closures_calendar: '1', fines: '0', automatic_fines: '0', circulation_rules: '0', debt_block: '0', consultation_only: '0', ...features });
    db('UPDATE calendario_chiusure SET attiva=0 WHERE attiva=1');
    db(`UPDATE festivita_personalizzate SET attiva=0 WHERE id IN (${prev.activeCustomIds.join(',') || '0'})`);
    db("DELETE FROM system_settings WHERE category='calendar' AND setting_key LIKE 'holiday\\_%'");
    for (const key of ['closed_weekdays', 'same_day_handover_after_closure']) db(`DELETE FROM system_settings WHERE category='loans' AND setting_key='${key}'`);
    for (const [key, value] of [['max_active_loans_per_user', '0'], ['auto_approve_requests', '0'], ['allow_multiple_loans_same_book', '0'],
      ['loan_duration_days', '30'], ['max_loan_duration_days', '90'], ['pickup_expiry_days', '3'], ['max_renewals', '3']]) {
      db(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans', '${key}', '${value}') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
    }
    await flushCache();
    lab.today = F.appTodayISO();
    lab.refreshNumbers();
  };

  /** Every test starts from an empty calendar and the default loan settings. */
  lab.reset = async ({ keepSettings = false } = {}) => {
    db(`UPDATE calendario_chiusure SET attiva=0 WHERE attiva=1 AND id IN (SELECT id FROM ${prev.closureBackup})`);
    db(`DELETE FROM calendario_chiusure WHERE id IN (${ids(created.closures)}) OR descrizione LIKE '${TAG}%'`);
    db(`DELETE FROM festivita_personalizzate WHERE id IN (${ids(created.customs)}) OR nome LIKE '${TAG}%'`);
    created.closures.clear();
    created.customs.clear();
    db("DELETE FROM system_settings WHERE category='calendar' AND setting_key LIKE 'holiday\\_%'");
    for (const key of ['closed_weekdays', 'same_day_handover_after_closure']) db(`DELETE FROM system_settings WHERE category='loans' AND setting_key='${key}'`);
    if (!keepSettings) {
      for (const [key, value] of [['max_active_loans_per_user', '0'], ['auto_approve_requests', '0'], ['allow_multiple_loans_same_book', '0'],
        ['loan_duration_days', '30'], ['max_loan_duration_days', '90'], ['pickup_expiry_days', '3'], ['max_renewals', '3']]) {
        db(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans', '${key}', '${value}') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
      }
    }
    await flushCache();
    lab.closed = new Set();
    lab.closedWeekdays = new Set();
    lab.today = F.appTodayISO();
    lab.refreshNumbers();
  };

  lab.teardown = async () => {
    await F.runAll([
      () => { db(`DELETE FROM calendario_chiusure WHERE id IN (${ids(created.closures)}) OR descrizione LIKE '${TAG}%'`); },
      () => { db(`DELETE FROM festivita_personalizzate WHERE id IN (${ids(created.customs)}) OR nome LIKE '${TAG}%'`); },
      async () => {
        if (!prev.ready) return;
        const cols = prev.closureColumns.map((c) => `\`${c}\``);
        const set = cols.filter((c) => c !== '`id`').map((c) => `c.${c}=b.${c}`).join(', ');
        db(`UPDATE calendario_chiusure c JOIN ${prev.closureBackup} b ON b.id=c.id SET ${set}`);
        db(`INSERT INTO calendario_chiusure (${cols.join(', ')}) SELECT ${cols.map((c) => `b.${c}`).join(', ')} FROM ${prev.closureBackup} b LEFT JOIN calendario_chiusure c ON c.id=b.id WHERE c.id IS NULL`);
        db(`UPDATE festivita_personalizzate SET attiva=1 WHERE id IN (${prev.activeCustomIds.join(',') || '0'})`);
        db("DELETE FROM system_settings WHERE category='calendar' AND setting_key LIKE 'holiday\\_%'");
        for (const [key, value] of prev.holidaySettings) {
          db(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('calendar', UNHEX('${key}'), UNHEX('${value}'))`);
        }
        for (const key of LOAN_SETTINGS) F.restoreSettingState('loans', key, prev.settings[key]);
        await ft.writeFeatures(prev.features);
      },
      () => { restoreLoans(db, loanSnap, F.ADMIN_EMAIL); },
      () => { db(`DROP TABLE IF EXISTS ${prev.closureBackup}`); },
      () => {
        const t = lab.titles.join(',') || '0';
        db(`DELETE FROM admin_notifications WHERE related_id IN (SELECT id FROM prestiti WHERE libro_id IN (${t})) AND (type IN ('new_loan_request','overdue_loan') OR link LIKE '%/admin/loans%')`);
      },
      () => { F.deleteTitles(lab.titles); },
      () => { F.deletePatrons(lab.patrons); },
      () => { for (const id of lab.branches) { db(`UPDATE copie SET sede_id=NULL WHERE sede_id=${id}`); db(`DELETE FROM sedi WHERE id=${id}`); } },
      () => flushCache(),
    ]);
  };

  // ── Fixtures ─────────────────────────────────────────────────────────────
  lab.book = (tag, copies = 1) => {
    const b = F.createTitle(`${RUN}${prefix} ${tag} ${++seq}`, 'libro', copies);
    lab.titles.push(b.id);
    return b;
  };
  lab.patron = (tag) => {
    const p = F.createPatron(`${prefix}${tag}${RUN}${++seq}`);
    lab.patrons.push(p.id);
    return p;
  };
  lab.branch = (tag) => {
    const name = `${TAG} ${tag} ${++seq}`;
    db(`INSERT INTO sedi (nome) VALUES (${F.sqlStr(name)})`);
    const id = dbInt(`SELECT id FROM sedi WHERE nome=${F.sqlStr(name)}`);
    lab.branches.push(id);
    return id;
  };
  /**
   * A loan that already exists when the test begins (lent in the past, or in
   * a state the desk cannot produce today). Copy and book counters are set
   * the way the application leaves them.
   */
  lab.seedLoan = (book, patron, { start, due, stato = 'in_corso', copy = book.copyIds[0], origine = 'diretto', pickup = null, note = null } = {}) => {
    const attivo = stato === 'pendente' ? 0 : 1;
    db(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, pickup_deadline, note)
        VALUES (${book.id}, ${copy === null ? 'NULL' : copy}, ${patron.id}, '${start}', '${due}', '${stato}', '${origine}', ${attivo}, ${pickup ? `'${pickup}'` : 'NULL'}, ${note ? F.sqlStr(note) : 'NULL'})`);
    const id = dbInt(`SELECT id FROM prestiti WHERE libro_id=${book.id} AND utente_id=${patron.id} ORDER BY id DESC LIMIT 1`);
    if (copy !== null && ['in_corso', 'in_ritardo'].includes(stato)) db(`UPDATE copie SET stato='prestato' WHERE id=${copy}`);
    if (copy !== null && ['da_ritirare', 'prenotato'].includes(stato)) db(`UPDATE copie SET stato='prenotato' WHERE id=${copy}`);
    lab.recount(book);
    return id;
  };
  /** A queue reservation that already exists (the reader made it days ago). */
  lab.seedReservation = (book, patron, { start, end, position }) => {
    db(`INSERT INTO prenotazioni (libro_id, utente_id, data_prenotazione, data_inizio_richiesta, data_fine_richiesta, data_scadenza_prenotazione, queue_position, stato)
        VALUES (${book.id}, ${patron.id}, NOW(), '${start}', '${end}', '${end} 23:59:59', ${position}, 'attiva')`);
    return dbInt(`SELECT id FROM prenotazioni WHERE libro_id=${book.id} AND utente_id=${patron.id} ORDER BY id DESC LIMIT 1`);
  };
  lab.recount = (book) => {
    db(`UPDATE libri SET copie_disponibili = (SELECT COUNT(*) FROM copie c WHERE c.libro_id=${book.id} AND c.stato='disponibile') WHERE id=${book.id}`);
    db(`UPDATE libri SET stato = IF(copie_disponibili > 0, 'disponibile', 'prestato') WHERE id=${book.id}`);
  };

  // ── Reading the outcome ──────────────────────────────────────────────────
  /** stato|data_prestito|data_scadenza|pickup_deadline|copia_id|attivo */
  lab.loan = (id) => {
    const [stato, start, due, pickup, copy, attivo, renewals] = db(`SELECT CONCAT_WS('|', stato, data_prestito, data_scadenza, IFNULL(pickup_deadline,'-'), IFNULL(copia_id,0), attivo, IFNULL(renewals,0)) FROM prestiti WHERE id=${id}`).split('|');
    return { stato, start, due, pickup, copy: Number(copy), attivo: Number(attivo), renewals: Number(renewals) };
  };
  lab.lastLoanId = (book, patron) => dbInt(`SELECT COALESCE(MAX(id),0) FROM prestiti WHERE libro_id=${book.id} AND utente_id=${patron.id}`);
  lab.reservation = (id) => {
    const [stato, pos, start, end] = db(`SELECT CONCAT_WS('|', stato, IFNULL(queue_position,0), IFNULL(data_inizio_richiesta,'-'), IFNULL(data_fine_richiesta,'-')) FROM prenotazioni WHERE id=${id}`).split('|');
    return { stato, position: Number(pos), start, end };
  };
  lab.lastReservationId = (book, patron) => dbInt(`SELECT COALESCE(MAX(id),0) FROM prenotazioni WHERE libro_id=${book.id} AND utente_id=${patron.id}`);
  /** Active queue of a title, as "patronId:position" in queue order. */
  lab.queue = (book) => db(`SELECT GROUP_CONCAT(CONCAT(utente_id, ':', queue_position) ORDER BY queue_position, id SEPARATOR ',') FROM prenotazioni WHERE libro_id=${book.id} AND stato='attiva'`).replace(/^NULL$/, '');
  lab.available = (book) => dbInt(`SELECT copie_disponibili FROM libri WHERE id=${book.id}`);
  lab.copyState = (copyId) => db(`SELECT stato FROM copie WHERE id=${copyId}`);
  lab.dueMoved = (book) => dbInt(`SELECT COUNT(*) FROM log_modifiche WHERE tabella='libri' AND record_id=${book.id} AND JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi, '$._activity.event'))='loan.due_moved'`);
  /**
   * The invariants no sequence of operations may break on a title:
   *  - a copy never carries two open loans whose periods overlap;
   *  - the counter on the title equals the copies really free;
   *  - the active queue is numbered 1..n without gaps or repeats.
   */
  lab.expectCoherent = (book) => {
    const overlaps = dbInt(`SELECT COUNT(*) FROM prestiti a JOIN prestiti b ON b.copia_id=a.copia_id AND b.id>a.id
        WHERE a.libro_id=${book.id} AND a.attivo=1 AND b.attivo=1 AND a.copia_id IS NOT NULL
          AND a.stato IN ('prenotato','da_ritirare','in_corso','in_ritardo') AND b.stato IN ('prenotato','da_ritirare','in_corso','in_ritardo')
          AND a.data_prestito <= b.data_scadenza AND b.data_prestito <= a.data_scadenza`);
    expect(overlaps, 'two open loans overlap on the same copy').toBe(0);
    const [total, lendable, out] = db(`SELECT CONCAT_WS('|',
        (SELECT COUNT(*) FROM copie WHERE libro_id=${book.id}),
        (SELECT COUNT(*) FROM copie WHERE libro_id=${book.id} AND stato NOT IN ('perso','danneggiato','manutenzione','in_restauro','in_trasferimento')),
        (SELECT COUNT(DISTINCT copia_id) FROM prestiti WHERE libro_id=${book.id} AND copia_id IS NOT NULL AND ((attivo=1 AND stato IN ('in_corso','in_ritardo','da_ritirare')) OR stato='pendente')))`).split('|').map(Number);
    const available = lab.available(book);
    expect(available, 'copie_disponibili is negative or above the copies owned').toBeGreaterThanOrEqual(0);
    expect(available, 'copie_disponibili counts a copy that is out or waiting for pickup').toBeLessThanOrEqual(Math.max(0, lendable - out));
    // A request promoted from the queue waits for approval with its copy already set aside.
    if (dbInt(`SELECT COUNT(*) FROM prestiti WHERE libro_id=${book.id} AND (attivo=1 OR (stato='pendente' AND copia_id IS NOT NULL))`) === 0) {
      expect(available, 'no open loan left, yet a lendable copy is not available').toBe(lendable);
    }
    expect(total).toBeGreaterThan(0);
    const positions = db(`SELECT queue_position FROM prenotazioni WHERE libro_id=${book.id} AND stato='attiva' ORDER BY queue_position, id`).split('\n').filter(Boolean).map(Number);
    expect(positions, 'queue positions are not 1..n').toEqual(positions.map((_, i) => i + 1));
  };

  // ── Admin → Calendar ─────────────────────────────────────────────────────
  const land = async (page, button) => {
    await Promise.all([page.waitForURL(/\/admin\/calendar/, { timeout: 20000 }), page.locator(button).click()]);
    await page.waitForLoadState('domcontentloaded');
  };
  /** A closure from `start` to `end` (one day when omitted); returns whether it was saved. */
  lab.addClosure = async (page, start, end = '', { yearly = false, branch = 0, label = '', model = true } = {}) => {
    const desc = `${TAG} ${label || start}${end ? `-${end}` : ''} ${++seq}`;
    await page.goto(`${F.BASE}/admin/calendar#calendar-closure-form`);
    await page.fill('#closure_start', start);
    if (end) await page.fill('#closure_end', end);
    await page.fill('#closure_description', desc);
    if (yearly) await page.check('#closure_yearly');
    if (branch) await page.selectOption('#closure_branch', String(branch));
    await land(page, '#save-closure');
    const id = dbInt(`SELECT COALESCE((SELECT id FROM calendario_chiusure WHERE descrizione=${F.sqlStr(desc)} ORDER BY id DESC LIMIT 1), 0)`);
    if (id) created.closures.add(id);
    if (id && model && !yearly && !branch) lab.markClosed(start, end || start);
    return { id, desc, saved: id > 0 };
  };
  lab.deleteClosure = async (page, closure) => {
    await page.goto(`${F.BASE}/admin/calendar#calendar-closures`);
    const row = page.locator('#calendar-closures tr, #calendar-closures li, #calendar-closures > div').filter({ hasText: closure.desc }).last();
    await row.locator('[data-action="delete-closure"]').click();
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await Promise.all([page.waitForURL(/\/admin\/calendar/, { timeout: 20000 }), page.locator('.swal2-confirm').click()]);
    await expect.poll(() => dbInt(`SELECT COUNT(*) FROM calendario_chiusure WHERE id=${closure.id}`), { timeout: 8000 }).toBe(0);
    created.closures.delete(closure.id);
  };
  lab.addLibraryHoliday = async (page, iso, days = 1) => {
    const name = `${TAG} ${iso} ${++seq}`;
    await page.goto(`${F.BASE}/admin/calendar#calendar-custom`);
    await page.fill('#custom_name', name);
    await page.selectOption('#custom_kind', 'fixed');
    await page.fill('#custom_fixed_day', String(Number(iso.slice(8, 10))));
    await page.selectOption('#custom_fixed_month', String(Number(iso.slice(5, 7))));
    await page.fill('#custom_days', String(days));
    await land(page, '#save-custom-holiday');
    const id = dbInt(`SELECT COALESCE((SELECT id FROM festivita_personalizzate WHERE nome=${F.sqlStr(name)} ORDER BY id DESC LIMIT 1), 0)`);
    if (id) { created.customs.add(id); lab.markClosed(iso, addDays(iso, days - 1)); }
    return { id, name };
  };
  lab.setWeekdays = async (page, days) => {
    await page.goto(`${F.BASE}/admin/calendar#calendar-weekdays`);
    for (let w = 0; w <= 6; w++) {
      const box = page.locator(`#closed_weekday_${w}`);
      if ((await box.isChecked()) !== days.includes(w)) await box.click();
    }
    await land(page, '#save-weekdays');
    const stored = db("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'").split(',').filter((s) => s !== '').map(Number).sort();
    lab.closedWeekdays = new Set(stored);
    return stored;
  };
  lab.applyItalianPreset = async (page, rules) => {
    const before = dbInt('SELECT COALESCE(MAX(id), 0) FROM calendario_chiusure');
    await page.goto(`${F.BASE}/admin/calendar?holiday_country=IT#calendar-preset`);
    await page.locator('[data-holiday-select="none"]').click();
    for (const id of rules) await page.locator(`[data-holiday-row="IT:${id}"] input[data-holiday-rule]`).check();
    await land(page, '#import-preset');
    for (const id of db(`SELECT id FROM calendario_chiusure WHERE id > ${before} AND regola_festivita LIKE 'IT:%'`).split('\n').filter(Boolean)) created.closures.add(Number(id));
  };
  /** A closure that reached the table without the calendar page (the day is simply closed). */
  lab.seedClosure = (start, end = start, { branch = 0, yearly = false, model = true } = {}) => {
    const desc = `${TAG} seed ${start} ${++seq}`;
    db(`INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, ricorrente_annuale, sede_id, attiva) VALUES ('${start}', '${end}', ${F.sqlStr(desc)}, ${yearly ? 1 : 0}, ${branch || 'NULL'}, 1)`);
    const id = dbInt(`SELECT id FROM calendario_chiusure WHERE descrizione=${F.sqlStr(desc)}`);
    created.closures.add(id);
    if (model && !branch && !yearly) lab.markClosed(start, end);
    return { id, desc };
  };
  lab.dropClosure = (closure) => { db(`DELETE FROM calendario_chiusure WHERE id=${closure.id}`); created.closures.delete(closure.id); };

  // ── The desk ─────────────────────────────────────────────────────────────
  const setPicker = async (page, selector, iso) => {
    await expect.poll(() => page.evaluate((s) => !!(/** @type {any} */ (document.querySelector(s)))?._flatpickr, selector), { timeout: 8000 }).toBe(true);
    await page.evaluate(([s, d]) => { /** @type {any} */ (document.querySelector(s))._flatpickr.setDate(d, true); }, [selector, iso]);
    return page.locator(selector).inputValue();
  };
  lab.setPicker = setPicker;
  /** Type a date in a field that may be a plain date input or a flatpickr calendar; returns what the field kept. */
  const typeDate = async (page, selector, iso) => {
    await page.waitForTimeout(300);
    if (await page.evaluate((s) => !!(/** @type {any} */ (document.querySelector(s)))?._flatpickr, selector)) return setPicker(page, selector, iso);
    await page.locator(selector).fill(iso);
    return page.locator(selector).inputValue();
  };
  /**
   * A loan made at the desk form. Returns { ok, error, id, proposedStart,
   * proposedDue, typedStart, typedDue } — typed* is what the picker kept of
   * a date the test asked for ('' when the calendar refuses that day).
   */
  lab.deskLoan = async (page, patron, book, { start = '', due = '', immediate = true, copyCode = '', note = '' } = {}) => {
    const before = lab.lastLoanId(book, patron);
    await page.goto(`${F.BASE}/admin/loans/create`);
    await page.waitForLoadState('domcontentloaded');
    const proposedStart = await page.locator('#data_prestito').inputValue();
    const proposedDue0 = await page.locator('#data_scadenza').inputValue();
    await F.fillAutocomplete(page, '#utente_search', '#utente_suggest', patron.surname, '/api/search/utenti');
    await expect(page.locator('#utente_id')).toHaveValue(String(patron.id));
    const availability = page.waitForResponse((r) => r.url().includes('/availability'), { timeout: 15000 }).catch(() => null);
    if (copyCode) {
      const resolved = page.waitForResponse((r) => r.url().includes('/admin/copies/by-code'), { timeout: 15000 }).catch(() => null);
      await page.locator('#copy_code').fill(copyCode);
      await page.locator('#copy_code').dispatchEvent('change');
      await resolved;
    } else {
      await F.fillAutocomplete(page, '#libro_search', '#libro_suggest', book.title, '/api/search/libri');
    }
    await expect(page.locator('#libro_id')).toHaveValue(String(book.id));
    await availability;
    await page.waitForTimeout(400);
    const proposedDue = await page.locator('#data_scadenza').inputValue();
    let typedStart = ''; let typedDue = '';
    if (start) typedStart = await setPicker(page, '#data_prestito', start);
    if (due) typedDue = await setPicker(page, '#data_scadenza', due);
    if (note) await page.fill('#note', note);
    if (!immediate && await page.locator('#consegna_immediata').isVisible().catch(() => false)) await page.uncheck('#consegna_immediata');
    await page.uncheck('#scarica_pdf').catch(() => {});
    await page.evaluate(() => document.querySelectorAll('.flatpickr-calendar.open').forEach((c) => c.classList.remove('open')));
    await Promise.all([
      page.waitForURL((u) => u.toString().includes('created=1') || u.toString().includes('error='), { timeout: 30000 }),
      page.click('form[action$="/admin/loans/create"] button[type="submit"]:not([name="save_and_new"])'),
    ]);
    const url = new URL(page.url());
    const id = lab.lastLoanId(book, patron);
    return { ok: url.searchParams.get('created') === '1', error: url.searchParams.get('error') || '', id: id > before ? id : 0,
      proposedStart, proposedDue: proposedDue || proposedDue0, typedStart, typedDue };
  };

  /** One of the buttons of Admin → pending loans, with its confirmation dialog. */
  const pendingAction = async (page, cls, attr, id, endpoint, reason = '') => {
    await page.goto(`${F.BASE}/admin/loans/pending`);
    await page.waitForLoadState('domcontentloaded');
    const btn = page.locator(`.${cls}[${attr}="${id}"]`).first();
    if (!(await btn.isVisible({ timeout: 4000 }).catch(() => false))) return { ok: false, missing: true, status: 0, message: '' };
    await btn.click();
    await page.waitForSelector('.swal2-popup', { timeout: 10000 });
    const text = page.locator('.swal2-textarea');
    if (await text.isVisible().catch(() => false)) await text.fill(reason || 'E2E');
    const input = page.locator('.swal2-input');
    if (await input.isVisible().catch(() => false)) await input.fill(reason || 'E2E');
    const resP = page.waitForResponse((r) => r.url().includes(endpoint) && r.request().method() === 'POST', { timeout: 20000 });
    await page.locator('.swal2-confirm').click();
    const res = await resP;
    const body = await res.json().catch(() => ({}));
    await page.waitForFunction(() => !!document.querySelector('.swal2-icon-success, .swal2-icon-error') || !document.querySelector('.swal2-popup'), null, { timeout: 15000 }).catch(() => {});
    return { ok: res.ok() && body.success === true, missing: false, status: res.status(), message: String(body.message || body.error || '') };
  };
  lab.approve = (page, loanId) => pendingAction(page, 'approve-btn', 'data-loan-id', loanId, '/admin/loans/approve');
  lab.reject = (page, loanId, reason = 'E2E') => pendingAction(page, 'reject-btn', 'data-loan-id', loanId, '/admin/loans/reject', reason);
  lab.confirmPickup = (page, loanId) => pendingAction(page, 'confirm-pickup-btn', 'data-loan-id', loanId, '/admin/loans/confirm-pickup');
  lab.cancelPickup = (page, loanId, reason = 'E2E') => pendingAction(page, 'cancel-pickup-btn', 'data-loan-id', loanId, '/admin/loans/cancel-pickup', reason);
  lab.cancelReservationAsAdmin = (page, reservationId, reason = 'E2E') => pendingAction(page, 'cancel-reservation-btn', 'data-reservation-id', reservationId, '/admin/loans/cancel-reservation', reason);
  /** Whether Admin → pending loans offers that button for the loan. */
  lab.pendingOffers = async (page, cls, loanId) => {
    await page.goto(`${F.BASE}/admin/loans/pending`);
    await page.waitForLoadState('domcontentloaded');
    return page.locator(`.${cls}[data-loan-id="${loanId}"]`).first().isVisible({ timeout: 3000 }).catch(() => false);
  };

  /** The return form of a loan; `stato` is the condition the copy comes back in. */
  lab.returnLoan = async (page, loanId, { stato = 'restituito' } = {}) => {
    await page.goto(`${F.BASE}/admin/loans/returned/${loanId}`);
    const button = page.getByRole('button', { name: 'Conferma restituzione' });
    if (!(await button.isVisible({ timeout: 5000 }).catch(() => false))) return { ok: false, url: page.url() };
    if (stato !== 'restituito') await page.selectOption('#stato', stato);
    await Promise.all([page.waitForURL((u) => !/\/returned\/\d+$/.test(u.pathname) || u.search !== '', { timeout: 20000 }), button.click()]);
    return { ok: !page.url().includes('error='), url: page.url() };
  };
  /** The fine the return form announces, in cents (0 when it shows none). */
  lab.previewedFine = async (page, loanId) => {
    await page.goto(`${F.BASE}/admin/loans/returned/${loanId}`);
    await expect(page.getByRole('button', { name: 'Conferma restituzione' })).toBeVisible();
    const box = page.locator('#fine-preview');
    if (!(await box.isVisible().catch(() => false))) return 0;
    return Number(await box.getAttribute('data-fine-cents'));
  };
  /** The quick return by inventory code on the loans list. */
  lab.returnByCode = async (page, code) => {
    await page.goto(`${F.BASE}/admin/loans`);
    await page.fill('#return_copy_code', code);
    await Promise.all([page.waitForNavigation({ timeout: 20000 }), page.locator('form[action$="/admin/loans/return-by-code"] button[type="submit"]').click()]);
    await page.waitForLoadState('domcontentloaded');
    return page.url();
  };
  /** The "Rinnova" button on the admin book page. */
  lab.renew = async (page, loanId) => {
    const bookId = dbInt(`SELECT libro_id FROM prestiti WHERE id=${loanId}`);
    await page.goto(`${F.BASE}/admin/books/${bookId}`);
    const submit = page.locator(`form[action$="/admin/loans/renew/${loanId}"] button[type="submit"]`).last();
    if (!(await submit.isVisible({ timeout: 4000 }).catch(() => false))) return { offered: false, ...lab.loan(loanId) };
    await submit.click();
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await page.locator('.swal2-confirm').click();
    await page.waitForLoadState('networkidle').catch(() => {});
    return { offered: true, url: page.url(), ...lab.loan(loanId) };
  };
  /** Admin → loan → edit dates. */
  lab.editLoan = async (page, loanId, { start = '', due = '' } = {}) => {
    await page.goto(`${F.BASE}/admin/loans/edit/${loanId}`);
    const form = page.locator(`form[action$="/admin/loans/update/${loanId}"]`);
    if (!(await form.isVisible({ timeout: 5000 }).catch(() => false))) return { offered: false, error: '', url: page.url() };
    /** A date field of the edit form: a flatpickr calendar when the page built one. */
    const type = async (name, iso) => {
      const sel = `form[action$="/admin/loans/update/${loanId}"] input[name="${name}"]`;
      await page.waitForTimeout(300);
      if (await page.evaluate((s) => !!(/** @type {any} */ (document.querySelector(s)))?._flatpickr, sel)) return setPicker(page, sel, iso);
      await page.locator(sel).fill(iso);
      return page.locator(sel).inputValue();
    };
    let typedStart = ''; let typedDue = '';
    if (start) typedStart = await type('data_prestito', start);
    if (due) typedDue = await type('data_scadenza', due);
    if ((start && typedStart !== start) || (due && typedDue !== due)) return { offered: true, refusedByCalendar: true, error: '', url: page.url(), typedStart, typedDue };
    await Promise.all([page.waitForURL((u) => !/\/edit\/\d+$/.test(u.pathname) || u.search !== '', { timeout: 20000 }), form.locator('button[type="submit"]').click()]);
    const url = new URL(page.url());
    return { offered: true, refusedByCalendar: false, error: url.searchParams.get('error') || '', url: page.url(), typedStart, typedDue };
  };
  /** Loans list: tick the loans, "Estendi di N giorni". */
  lab.bulkExtend = async (page, loanIds, days, search) => {
    await page.goto(`${F.BASE}/admin/loans`);
    await page.waitForLoadState('networkidle');
    const box = page.locator('input[type="search"]').first();
    if (await box.isVisible({ timeout: 3000 }).catch(() => false)) { await box.fill(search); await page.waitForTimeout(700); }
    for (const id of loanIds) {
      const cb = page.locator(`.loan-select[data-id="${id}"]`);
      if (!(await cb.isVisible({ timeout: 8000 }).catch(() => false))) return { offered: false, url: page.url() };
      await cb.check();
    }
    await expect(page.locator('#loans-bulk-bar')).toBeVisible();
    await page.fill('#loans-bulk-days', String(days));
    await page.locator('#loans-bulk-extend').click();
    const confirm = page.locator('.swal2-confirm');
    if (await confirm.isVisible({ timeout: 3000 }).catch(() => false)) await confirm.click();
    await page.waitForLoadState('networkidle');
    return { offered: true, url: page.url() };
  };
  /** Admin → reservations → new. */
  lab.adminReserve = async (page, patron, book, start, end) => {
    await page.goto(`${F.BASE}/admin/reservations/create`);
    await page.selectOption('#libro_id', String(book.id));
    await page.selectOption('#utente_id', String(patron.id));
    const typedStart = await typeDate(page, '#data_prenotazione', start);
    const typedEnd = end ? await typeDate(page, '#data_scadenza', end) : '';
    if (typedStart !== start || (end && typedEnd !== end)) return { ok: false, refusedByCalendar: true, error: '', id: 0, typedStart, typedEnd };
    await Promise.all([page.waitForURL((u) => u.search.includes('created=1') || u.search.includes('error='), { timeout: 20000 }),
      page.locator('form[action$="/admin/reservations/create"] button[type="submit"]').first().click()]);
    const url = new URL(page.url());
    return { ok: url.searchParams.get('created') === '1', refusedByCalendar: false, error: url.searchParams.get('error') || '', id: lab.lastReservationId(book, patron) };
  };
  /** Admin → reservations → edit. */
  lab.adminEditReservation = async (page, reservationId, { start = '', end = '', stato = '' } = {}) => {
    await page.goto(`${F.BASE}/admin/reservations/edit/${reservationId}`);
    const form = page.locator(`form[action$="/admin/reservations/update/${reservationId}"]`);
    await expect(form).toBeVisible({ timeout: 8000 });
    const sel = (name) => `form[action$="/admin/reservations/update/${reservationId}"] input[name="${name}"]`;
    const typedStart = start ? await typeDate(page, sel('data_prenotazione'), start) : '';
    const typedEnd = end ? await typeDate(page, sel('data_scadenza_prenotazione'), end) : '';
    if ((start && typedStart !== start) || (end && typedEnd !== end)) return { ok: false, refusedByCalendar: true, error: '' };
    if (stato) await form.locator('select[name="stato"]').selectOption(stato);
    await Promise.all([page.waitForURL((u) => u.search.includes('updated=1') || u.search.includes('error='), { timeout: 20000 }), form.locator('button').last().click()]);
    const url = new URL(page.url());
    return { ok: url.searchParams.get('updated') === '1', refusedByCalendar: false, error: url.searchParams.get('error') || '' };
  };

  // ── The reader ───────────────────────────────────────────────────────────
  /** What the book page offers the logged-in reader. */
  lab.bookPage = async (page, book) => {
    await page.goto(`${F.BASE}/libro/${book.id}`);
    const btn = page.locator('#btn-request-loan');
    const present = await btn.isVisible({ timeout: 5000 }).catch(() => false);
    return { status: 200, present, label: present ? ((await btn.textContent()) || '').trim() : '', disabled: present ? await btn.isDisabled() : true };
  };
  /**
   * The reader opens the book page, presses the request button, picks the
   * dates in the calendar and confirms. Returns:
   *   picker  false when the click shows a notice instead of the calendar
   *   availability  what the calendar received
   *   pickedStart / pickedEnd  what the calendar kept ('' = day refused)
   *   ok, status, body, title   the answer to the request
   */
  lab.readerRequest = async (page, book, { start = '', end = '', submit = true } = {}) => {
    await page.goto(`${F.BASE}/libro/${book.id}`);
    const btn = page.locator('#btn-request-loan');
    await expect(btn).toBeVisible({ timeout: 8000 });
    const label = ((await btn.textContent()) || '').trim();
    const availP = page.waitForResponse((r) => /\/availability(\?|$)/.test(r.url()) && r.request().method() === 'GET', { timeout: 15000 }).catch(() => null);
    await btn.click();
    await page.waitForSelector('.swal2-popup', { timeout: 10000 });
    const availRes = await Promise.race([availP, page.waitForTimeout(6000).then(() => null)]);
    const availability = availRes ? ((await availRes.json().catch(() => ({}))).availability || {}) : {};
    const hasPicker = await page.locator('#swal-date-start').count();
    if (!hasPicker) {
      const text = ((await page.locator('.swal2-popup').textContent()) || '').trim();
      return { picker: false, label, availability, ok: false, status: 0, body: {}, title: text, pickedStart: '', pickedEnd: '' };
    }
    let pickedStart = await page.locator('#swal-date-start').inputValue();
    if (start) pickedStart = await setPicker(page, '#swal-date-start', start);
    // No day asked for: the reader takes the first one the calendar offers.
    // (earliest_queue: no copy free for a loan, but a place in the queue.)
    else if (!pickedStart && (availability.earliest_available || availability.earliest_queue)) {
      pickedStart = await setPicker(page, '#swal-date-start', String(availability.earliest_available || availability.earliest_queue).slice(0, 10));
    }
    if (!start && !pickedStart) return { picker: true, label, availability, ok: false, status: 0, body: {}, title: 'no day offered', pickedStart, pickedEnd: '' };
    await page.waitForTimeout(200);
    let pickedEnd = await page.locator('#swal-date-end').inputValue().catch(() => '');
    if (end) pickedEnd = await setPicker(page, '#swal-date-end', end);
    if (!submit || (start && pickedStart !== start) || (end && pickedEnd !== end)) {
      return { picker: true, label, availability, ok: false, status: 0, body: {}, title: '', pickedStart, pickedEnd };
    }
    const resP = page.waitForResponse((r) => /\/reservation(\?|$)/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 });
    await page.locator('.swal2-confirm').click();
    const res = await resP;
    const body = await res.json().catch(() => ({}));
    await page.waitForFunction(() => !!document.querySelector('.swal2-icon-success, .swal2-icon-error, .swal2-icon-warning, .swal2-icon-info'), null, { timeout: 15000 }).catch(() => {});
    const title = ((await page.locator('.swal2-title').textContent().catch(() => '')) || '').trim();
    const text = ((await page.locator('.swal2-html-container').textContent().catch(() => '')) || '').trim();
    return { picker: true, label, availability, ok: res.ok() && body.success === true, status: res.status(), body, title, text, pickedStart, pickedEnd };
  };
  /** Days of the reader's request calendar: enabled or not, by ISO date. */
  lab.readerCalendar = async (page, book, days) => {
    const r = await lab.readerRequest(page, book, { submit: false });
    if (!r.picker) return { ...r, enabled: [] };
    const enabled = await page.evaluate((list) => {
      const fp = /** @type {any} */ (document.querySelector('#swal-date-start'))._flatpickr;
      return list.map((d) => fp.isEnabled(d));
    }, days);
    return { ...r, enabled };
  };
  /** The reader's "Prenotazioni" page: cancel a request / pickup, or a queue reservation. */
  lab.readerCancelLoan = async (page, loanId) => {
    await page.goto(`${F.BASE}/prenotazioni`);
    const form = page.locator(`form[action$="/loan/cancel"]:has(input[name="loan_id"][value="${loanId}"])`);
    if (!(await form.isVisible({ timeout: 5000 }).catch(() => false))) return { offered: false, url: page.url() };
    await form.locator('button.btn-cancel').click();
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await Promise.all([page.waitForURL((u) => u.search !== '', { timeout: 20000 }), page.locator('.swal2-confirm').click()]);
    return { offered: true, url: page.url() };
  };
  lab.readerCancelReservation = async (page, reservationId) => {
    await page.goto(`${F.BASE}/prenotazioni`);
    const button = page.locator(`button.btn-cancel[data-reservation-id="${reservationId}"]`);
    if (!(await button.isVisible({ timeout: 5000 }).catch(() => false))) return { offered: false, url: page.url() };
    await button.click();
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await Promise.all([page.waitForURL((u) => u.search !== '', { timeout: 20000 }), page.locator('.swal2-confirm').click()]);
    return { offered: true, url: page.url() };
  };
  /** Text of the reader's card for a title on the "Prenotazioni" page ('' when absent). */
  lab.readerCard = async (page, book) => {
    await page.goto(`${F.BASE}/prenotazioni`);
    const card = page.locator('.item-card').filter({ hasText: book.title }).first();
    if (!(await card.isVisible({ timeout: 4000 }).catch(() => false))) return '';
    return ((await card.textContent()) || '').replace(/\s+/g, ' ').trim();
  };

  // ── Time passing ─────────────────────────────────────────────────────────
  /** The nightly maintenance (cron/full-maintenance.php): sweeps, activations, promotions. */
  lab.runMaintenance = () => {
    try { fs.unlinkSync(path.join(ROOT, 'storage/cache/full-maintenance.lock')); } catch { /* no lock */ }
    try {
      return execFileSync('php', [path.join(ROOT, 'cron/full-maintenance.php')], { cwd: ROOT, encoding: 'utf-8', timeout: 180000 });
    } catch (e) {
      // Exit status 2 = finished with non-fatal warnings (e.g. no SMTP).
      if (/** @type {any} */ (e).status === 2) return String(/** @type {any} */ (e).stdout || '');
      throw e;
    }
  };

  /** Fail the test on any 5xx answer while `fn` runs. */
  lab.noServerErrors = async (page, fn) => {
    /** @type {string[]} */ const errors = [];
    const listener = (r) => { if (r.status() >= 500) errors.push(`${r.status()} ${r.url()}`); };
    page.on('response', listener);
    try { return await fn(); } finally { page.off('response', listener); expect(errors, 'server errors').toEqual([]); }
  };

  return lab;
}

module.exports = { createLab, addDays, weekday, daysBetween };
