// @ts-check
// Closures calendar, admin page /admin/calendar: a librarian manages the days
// the library is closed — closures (add, edit, delete, yearly), the weekly
// closed days, the Italian holidays preset and an ICS import — and checks the
// result in the monthly preview. DB only for setup and verification.
//
// Run: /tmp/run-e2e.sh tests/closures-calendar-admin.spec.js --config=tests/playwright.config.js --workers=1
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

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME, 'E2E credentials not configured');

function db(sql) {
  const args = [];
  if (DB_HOST) { args.push('-h', DB_HOST); if (DB_PORT) args.push('-P', DB_PORT); } else if (DB_SOCKET) { args.push('-S', DB_SOCKET); }
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}

function addDays(iso, days) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  if (!m) throw new Error(`not an ISO date: ${iso}`);
  const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}
function weekday(iso) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  return new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]))).getUTCDay();
}

const RUN = Date.now().toString().slice(-7);
const TAG = `E2E-CAL-${RUN}`;
let startMaxId = 0;
// The preset rows carry the holiday names, not TAG: their ids are recorded so
// the teardown removes exactly what this suite added.
const presetIds = [];
let prevWeekdays = null;
let sedeId = 0;
let today = '';
// A month far enough ahead that no seeded closure of this run lands in it by accident.
let farYear = 0;

async function login(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(/admin/, { timeout: 30000 });
}

async function submitAndLand(page, button) {
  await Promise.all([
    page.waitForURL(/\/admin\/calendar/, { timeout: 15000 }),
    page.locator(button).click(),
  ]);
}

async function addClosure(page, { start, end = '', description, yearly = false, branch = '' }) {
  await page.goto(`${BASE}/admin/calendar`);
  await page.fill('#closure_start', start);
  if (end) await page.fill('#closure_end', end);
  await page.fill('#closure_description', description);
  if (branch) await page.selectOption('#closure_branch', branch);
  if (yearly) await page.check('#closure_yearly');
  await submitAndLand(page, '#save-closure');
}

const rowsFor = (description) => db(`SELECT COUNT(*) FROM calendario_chiusure WHERE descrizione='${description}'`);

test.describe.configure({ mode: 'serial' });

test.describe('Closures calendar — admin page', () => {
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    startMaxId = parseInt(db('SELECT COALESCE(MAX(id), 0) FROM calendario_chiusure') || '0', 10);
    prevWeekdays = db("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'");
    db("DELETE FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'");
    db(`INSERT INTO sedi (nome) VALUES ('${TAG} Succursale')`);
    sedeId = parseInt(db(`SELECT id FROM sedi WHERE nome='${TAG} Succursale'`), 10);
    const tz = db("SELECT setting_value FROM system_settings WHERE category='app' AND setting_key='timezone' LIMIT 1") || 'Europe/Rome';
    today = new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    farYear = parseInt(today.slice(0, 4), 10) + 3;
    page = await browser.newPage();
    await login(page);
  });

  test.afterAll(async () => {
    // Only the rows this suite created: TAG in the description, or a preset
    // row recorded when it was imported. A closure another run added in the
    // meantime is left alone.
    db(`DELETE FROM calendario_chiusure WHERE descrizione LIKE '${TAG}%'${presetIds.length ? ` OR id IN (${presetIds.join(',')})` : ''}`);
    if (prevWeekdays === '') {
      db("DELETE FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'");
    } else {
      db(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans','closed_weekdays','${prevWeekdays}') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
    }
    if (sedeId) db(`DELETE FROM sedi WHERE id=${sedeId}`);
    await page?.close();
  });

  // ── Closures CRUD ────────────────────────────────────────────────────────
  test('CRUD 1: a one-day closure added from the form is listed and closes that day in the preview', async () => {
    const day = `${farYear}-05-14`;
    await addClosure(page, { start: day, description: `${TAG} one-day` });
    await expect(page.locator('#calendar-closures')).toContainText(`${TAG} one-day`);
    expect(db(`SELECT CONCAT(data_inizio,'|',data_fine,'|',ricorrente_annuale,'|',IFNULL(sede_id,'-')) FROM calendario_chiusure WHERE descrizione='${TAG} one-day'`))
      .toBe(`${day}|${day}|0|-`);
    // The redirect lands on the closure's month.
    await expect(page.locator(`[data-calendar-day="${day}"]`)).toHaveAttribute('data-closed', '1');
    await expect(page.locator(`[data-calendar-day="${addDays(day, 1)}"]`)).toHaveAttribute('data-closed', '0');
  });

  test('CRUD 2: a yearly range is stored as recurring and shown as "every year"', async () => {
    await addClosure(page, { start: `${farYear}-08-10`, end: `${farYear}-08-20`, description: `${TAG} summer`, yearly: true });
    expect(db(`SELECT ricorrente_annuale FROM calendario_chiusure WHERE descrizione='${TAG} summer'`)).toBe('1');
    const row = page.locator('#calendar-closures tr', { hasText: `${TAG} summer` });
    await expect(row).toContainText(/Ogni anno/);
    // Recurs in a later year.
    await page.goto(`${BASE}/admin/calendar?month=${farYear + 1}-08`);
    await expect(page.locator(`[data-calendar-day="${farYear + 1}-08-15"]`)).toHaveAttribute('data-closed', '1');
    await expect(page.locator(`[data-calendar-day="${farYear + 1}-08-21"]`)).toHaveAttribute('data-closed', '0');
  });

  test('CRUD 3: editing a closure changes its dates and description', async () => {
    await page.goto(`${BASE}/admin/calendar`);
    const row = page.locator('#calendar-closures tr', { hasText: `${TAG} one-day` });
    await row.locator('[data-action="edit-closure"]').click();
    await expect(page.locator('#closure_start')).toHaveValue(`${farYear}-05-14`);
    await page.fill('#closure_end', `${farYear}-05-16`);
    await page.fill('#closure_description', `${TAG} three-days`);
    await submitAndLand(page, '#save-closure');
    expect(rowsFor(`${TAG} one-day`)).toBe('0');
    expect(db(`SELECT data_fine FROM calendario_chiusure WHERE descrizione='${TAG} three-days'`)).toBe(`${farYear}-05-16`);
    await expect(page.locator(`[data-calendar-day="${farYear}-05-16"]`)).toHaveAttribute('data-closed', '1');
  });

  test('CRUD 4: deleting a closure asks for confirmation and removes it', async () => {
    await page.goto(`${BASE}/admin/calendar`);
    const row = page.locator('#calendar-closures tr', { hasText: `${TAG} three-days` });
    await row.locator('[data-action="delete-closure"]').click();
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await Promise.all([
      page.waitForURL(/\/admin\/calendar/, { timeout: 15000 }),
      page.locator('.swal2-confirm').click(),
    ]);
    await expect(page.locator('#calendar-closures')).not.toContainText(`${TAG} three-days`);
    expect(rowsFor(`${TAG} three-days`)).toBe('0');
  });

  test('CRUD 5: an end date before the start is refused and nothing is stored', async () => {
    await addClosure(page, { start: `${farYear}-06-10`, end: `${farYear}-06-01`, description: `${TAG} inverted` });
    await expect(page.locator('body')).toContainText(/La data di fine non può precedere la data di inizio/);
    expect(rowsFor(`${TAG} inverted`)).toBe('0');
  });

  test('CRUD 7: updating a closure that no longer exists reports "non trovata" and stores nothing', async () => {
    const missingId = parseInt(db('SELECT COALESCE(MAX(id), 0) + 100000 FROM calendario_chiusure'), 10);
    const before = db('SELECT COUNT(*) FROM calendario_chiusure');
    await page.goto(`${BASE}/admin/calendar`);
    await page.fill('#closure_start', `${farYear}-06-20`);
    await page.fill('#closure_description', `${TAG} ghost`);
    // Same form, same CSRF token, the update route of an id that does not exist.
    await page.evaluate((id) => {
      const form = document.querySelector('#save-closure').closest('form');
      form.action = form.action.replace(/\/admin\/calendar\/closures.*$/, `/admin/calendar/closures/${id}/update`);
    }, missingId);
    await submitAndLand(page, '#save-closure');
    await expect(page.locator('body')).toContainText(/Chiusura non trovata/);
    await expect(page.locator('body')).not.toContainText(/Chiusura aggiornata/);
    expect(db('SELECT COUNT(*) FROM calendario_chiusure')).toBe(before);
    expect(rowsFor(`${TAG} ghost`)).toBe('0');
  });

  test('CRUD 6: a closure for one branch is stored with that branch', async () => {
    await addClosure(page, { start: `${farYear}-07-01`, description: `${TAG} branch`, branch: String(sedeId) });
    expect(db(`SELECT sede_id FROM calendario_chiusure WHERE descrizione='${TAG} branch'`)).toBe(String(sedeId));
    await expect(page.locator('#calendar-closures tr', { hasText: `${TAG} branch` })).toContainText(`${TAG} Succursale`);
    // The whole-library preview stays open that day; the branch preview is closed.
    await page.goto(`${BASE}/admin/calendar?month=${farYear}-07`);
    await expect(page.locator(`[data-calendar-day="${farYear}-07-01"]`)).toHaveAttribute('data-closed', '0');
    await page.goto(`${BASE}/admin/calendar?month=${farYear}-07&sede=${sedeId}`);
    await expect(page.locator(`[data-calendar-day="${farYear}-07-01"]`)).toHaveAttribute('data-closed', '1');
  });

  // ── Weekly closed days ───────────────────────────────────────────────────
  test('Weekly 1: Sunday and Monday saved as closed weekdays', async () => {
    await page.goto(`${BASE}/admin/calendar`);
    await page.check('#closed_weekday_0');
    await page.check('#closed_weekday_1');
    await submitAndLand(page, '#save-weekdays');
    await expect(page.locator('body')).toContainText(/Giorni di chiusura settimanale salvati/);
    expect(db("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'")).toBe('0,1');
  });

  test('Weekly 2: the saved days stay checked after a reload', async () => {
    await page.goto(`${BASE}/admin/calendar`);
    await expect(page.locator('#closed_weekday_0')).toBeChecked();
    await expect(page.locator('#closed_weekday_1')).toBeChecked();
    await expect(page.locator('#closed_weekday_2')).not.toBeChecked();
  });

  test('Weekly 3: the preview marks every Sunday and Monday closed, Tuesday open', async () => {
    await page.goto(`${BASE}/admin/calendar?month=${farYear}-09`);
    const days = await page.locator('[data-calendar-day]').evaluateAll((els) => els.map((e) => [e.getAttribute('data-calendar-day'), e.getAttribute('data-closed')]));
    expect(days.length).toBe(30);
    for (const [iso, closed] of days) {
      const w = weekday(iso);
      expect(closed, iso).toBe(w === 0 || w === 1 ? '1' : '0');
    }
  });

  test('Weekly 4: closing all seven days is refused and the setting is kept', async () => {
    await page.goto(`${BASE}/admin/calendar`);
    for (let w = 0; w <= 6; w++) await page.check(`#closed_weekday_${w}`);
    await submitAndLand(page, '#save-weekdays');
    await expect(page.locator('body')).toContainText(/Almeno un giorno della settimana deve restare aperto/);
    expect(db("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'")).toBe('0,1');
  });

  test('Weekly 5: unchecking every day reopens the week', async () => {
    await page.goto(`${BASE}/admin/calendar`);
    await page.uncheck('#closed_weekday_0');
    await page.uncheck('#closed_weekday_1');
    await submitAndLand(page, '#save-weekdays');
    expect(db("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'")).toBe('');
    await page.goto(`${BASE}/admin/calendar?month=${farYear}-09`);
    await expect(page.locator('[data-calendar-day][data-closed="1"]')).toHaveCount(0);
  });

  test('Weekly 6: the page is reachable from the sidebar and from Settings > Loans', async () => {
    await page.goto(`${BASE}/admin/settings?tab=loans`);
    await Promise.all([page.waitForURL(/\/admin\/calendar/), page.locator('#open-closures-calendar').click()]);
    await expect(page.locator('h1')).toContainText(/Calendario chiusure/);
    await page.goto(`${BASE}/admin/dashboard`);
    await expect(page.locator(`a.nav-link[href$="/admin/calendar"]`).first()).toHaveCount(1);
  });

  // ── Italian holidays preset ──────────────────────────────────────────────
  const presetYear = () => parseInt(today.slice(0, 4), 10) + 1;

  test('Preset 1: importing the Italian holidays adds 10 yearly holidays plus Easter and Easter Monday', async () => {
    const beforeImport = parseInt(db('SELECT COALESCE(MAX(id), 0) FROM calendario_chiusure') || '0', 10);
    await page.goto(`${BASE}/admin/calendar`);
    await page.selectOption('#preset_year', String(presetYear()));
    await submitAndLand(page, '#import-preset');
    await expect(page.locator('body')).toContainText(/Festività nazionali importate/);
    const presetYearValue = presetYear();
    const added = db(`SELECT GROUP_CONCAT(id) FROM calendario_chiusure WHERE id > ${beforeImport} AND sede_id IS NULL AND (ricorrente_annuale=1 OR YEAR(data_inizio)=${presetYearValue}) AND descrizione IN ('Natale','Santo Stefano','Capodanno','Epifania','Ferragosto','Ognissanti','Immacolata Concezione','Festa della Liberazione','Festa del Lavoro','Festa della Repubblica','Pasqua','Lunedì dell''Angelo (Pasquetta)')`);
    if (added && added !== 'NULL') presetIds.push(...added.split(',').map((id) => parseInt(id, 10)).filter((id) => id > 0));
    const count = parseInt(db(`SELECT COUNT(*) FROM calendario_chiusure WHERE id > ${startMaxId} AND descrizione IN ('Natale','Santo Stefano','Capodanno','Epifania','Ferragosto','Ognissanti','Immacolata Concezione','Festa della Liberazione','Festa del Lavoro','Festa della Repubblica') AND ricorrente_annuale=1`), 10);
    // Fixed holidays already present before this run are not duplicated.
    expect(count).toBeLessThanOrEqual(10);
    expect(parseInt(db("SELECT COUNT(DISTINCT DATE_FORMAT(data_inizio,'%m-%d')) FROM calendario_chiusure WHERE ricorrente_annuale=1 AND sede_id IS NULL AND DATE_FORMAT(data_inizio,'%m-%d') IN ('01-01','01-06','04-25','05-01','06-02','08-15','11-01','12-08','12-25','12-26')"), 10)).toBe(10);
  });

  test('Preset 2: Easter and Easter Monday are dated by the algorithm for that year', async () => {
    // Known Easter Sundays (Gregorian).
    const easter = { 2026: '2026-04-05', 2027: '2027-03-28', 2028: '2028-04-16', 2029: '2029-04-01', 2030: '2030-04-21' }[presetYear()];
    test.skip(!easter, 'no reference Easter date for this year');
    expect(db(`SELECT COUNT(*) FROM calendario_chiusure WHERE data_inizio='${easter}' AND ricorrente_annuale=0`)).toBe('1');
    expect(db(`SELECT COUNT(*) FROM calendario_chiusure WHERE data_inizio='${addDays(easter, 1)}' AND ricorrente_annuale=0`)).toBe('1');
    await page.goto(`${BASE}/admin/calendar?month=${addDays(easter, 1).slice(0, 7)}`);
    await expect(page.locator(`[data-calendar-day="${addDays(easter, 1)}"]`)).toHaveAttribute('data-closed', '1');
    await expect(page.locator(`[data-calendar-day="${addDays(easter, 2)}"]`)).toHaveAttribute('data-closed', '0');
  });

  test('Preset 3: importing the same year again adds nothing', async () => {
    const before = db('SELECT COUNT(*) FROM calendario_chiusure');
    await page.goto(`${BASE}/admin/calendar`);
    await page.selectOption('#preset_year', String(presetYear()));
    await submitAndLand(page, '#import-preset');
    await expect(page.locator('body')).toContainText(/0 nuove chiusure/);
    expect(db('SELECT COUNT(*) FROM calendario_chiusure')).toBe(before);
  });

  test('Preset 4: Christmas and St Stephen close every December, the 27th stays open', async () => {
    await page.goto(`${BASE}/admin/calendar?month=${farYear}-12`);
    await expect(page.locator(`[data-calendar-day="${farYear}-12-25"]`)).toHaveAttribute('data-closed', '1');
    await expect(page.locator(`[data-calendar-day="${farYear}-12-26"]`)).toHaveAttribute('data-closed', '1');
    await expect(page.locator(`[data-calendar-day="${farYear}-12-27"]`)).toHaveAttribute('data-closed', '0');
  });

  test('Preset 5: the holidays appear in the list as recurring rows', async () => {
    await page.goto(`${BASE}/admin/calendar`);
    const row = page.locator('#calendar-closures tr', { hasText: 'Ferragosto' }).first();
    await expect(row).toContainText(/Ogni anno/);
    await expect(row).toContainText(/Tutte le sedi/);
  });

  // ── ICS import ───────────────────────────────────────────────────────────
  const ics = () => [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//E2E//Pinakes//IT',
    'BEGIN:VEVENT', 'UID:a@e2e', `DTSTART;VALUE=DATE:${farYear}1102`, `DTEND;VALUE=DATE:${farYear}1105`, `SUMMARY:${TAG} inventario`, 'END:VEVENT',
    'BEGIN:VEVENT', 'UID:b@e2e', `DTSTART;VALUE=DATE:${farYear}0317`, 'RRULE:FREQ=YEARLY', `SUMMARY:${TAG} patrono`, 'END:VEVENT',
    'END:VCALENDAR', '',
  ].join('\r\n');

  async function importIcs(content, branch = '', encoding = 'utf-8') {
    await page.goto(`${BASE}/admin/calendar`);
    await page.setInputFiles('#ics_file', { name: 'chiusure.ics', mimeType: 'text/calendar', buffer: Buffer.from(content, encoding) });
    if (branch) await page.selectOption('#ics_branch', branch);
    await submitAndLand(page, '#import-ics');
  }

  test('ICS 1: an ICS file adds one closure per event, with the exclusive DTEND honoured', async () => {
    await importIcs(ics());
    await expect(page.locator('body')).toContainText(/2 chiusure aggiunte, 0 già presenti/);
    expect(db(`SELECT CONCAT(data_inizio,'|',data_fine) FROM calendario_chiusure WHERE descrizione='${TAG} inventario'`)).toBe(`${farYear}-11-02|${farYear}-11-04`);
  });

  test('ICS 2: a yearly event (RRULE FREQ=YEARLY) becomes a recurring closure', async () => {
    expect(db(`SELECT ricorrente_annuale FROM calendario_chiusure WHERE descrizione='${TAG} patrono'`)).toBe('1');
    await page.goto(`${BASE}/admin/calendar?month=${farYear + 2}-03`);
    await expect(page.locator(`[data-calendar-day="${farYear + 2}-03-17"]`)).toHaveAttribute('data-closed', '1');
  });

  test('ICS 3: importing the same file again adds nothing', async () => {
    await importIcs(ics());
    await expect(page.locator('body')).toContainText(/0 chiusure aggiunte, 2 già presenti/);
    expect(rowsFor(`${TAG} inventario`)).toBe('1');
  });

  test('ICS 4: a file imported for one branch stores that branch', async () => {
    const branchIcs = ['BEGIN:VCALENDAR', 'BEGIN:VEVENT', `DTSTART;VALUE=DATE:${farYear}0410`, `SUMMARY:${TAG} sede ics`, 'END:VEVENT', 'END:VCALENDAR', ''].join('\r\n');
    await importIcs(branchIcs, String(sedeId));
    expect(db(`SELECT sede_id FROM calendario_chiusure WHERE descrizione='${TAG} sede ics'`)).toBe(String(sedeId));
  });

  test('ICS 6: a Windows-1252 export with accented names is imported in UTF-8', async () => {
    const latinIcs = ['BEGIN:VCALENDAR', 'BEGIN:VEVENT', `DTSTART;VALUE=DATE:${farYear}0612`, `SUMMARY:${TAG} festività à è`, 'END:VEVENT', 'END:VCALENDAR', ''].join('\r\n');
    await importIcs(latinIcs, '', 'latin1');
    await expect(page.locator('body')).toContainText(/1 chiusure aggiunte, 0 già presenti/);
    expect(db(`SELECT data_inizio FROM calendario_chiusure WHERE descrizione='${TAG} festività à è'`)).toBe(`${farYear}-06-12`);
    await expect(page.locator('#calendar-closures')).toContainText(`${TAG} festività à è`);
  });

  test('ICS 7: a UTF-8 character split by a folded line is rebuilt, not read as Windows-1252', async () => {
    const head = Buffer.from(['BEGIN:VCALENDAR', 'BEGIN:VEVENT', `DTSTART;VALUE=DATE:${farYear}0613`, `SUMMARY:${TAG} caff`].join('\r\n'), 'utf-8');
    // "é" is C3 A9: the fold falls between the two bytes.
    const tail = Buffer.from(['', 'END:VEVENT', 'END:VCALENDAR', ''].join('\r\n'), 'utf-8');
    const buffer = Buffer.concat([head, Buffer.from([0xc3]), Buffer.from('\r\n ', 'utf-8'), Buffer.from([0xa9]), tail]);
    await page.goto(`${BASE}/admin/calendar`);
    await page.setInputFiles('#ics_file', { name: 'chiusure.ics', mimeType: 'text/calendar', buffer });
    await submitAndLand(page, '#import-ics');
    expect(db(`SELECT data_inizio FROM calendario_chiusure WHERE descrizione='${TAG} caffé'`)).toBe(`${farYear}-06-13`);
  });

  test('ICS 8: a yearly event longer than a year is discarded with a reason; the other events and the open days stay', async () => {
    // A ten-year RRULE:FREQ=YEARLY event, stored as a yearly row, would have
    // closed every day of every year: the import applies the form's limits.
    const decadeIcs = [
      'BEGIN:VCALENDAR', 'VERSION:2.0',
      'BEGIN:VEVENT', 'UID:decade@e2e', `DTSTART;VALUE=DATE:${farYear}0101`, `DTEND;VALUE=DATE:${farYear + 10}0101`, 'RRULE:FREQ=YEARLY', `SUMMARY:${TAG} decennio`, 'END:VEVENT',
      'BEGIN:VEVENT', 'UID:fine@e2e', `DTSTART;VALUE=DATE:${farYear}0921`, `SUMMARY:${TAG} ics valido`, 'END:VEVENT',
      'END:VCALENDAR', '',
    ].join('\r\n');
    await importIcs(decadeIcs);
    await expect(page.locator('body')).toContainText(/1 chiusure aggiunte, 0 già presenti, 1 scartate perché più lunghe del limite/);
    expect(rowsFor(`${TAG} decennio`)).toBe('0');
    expect(rowsFor(`${TAG} ics valido`)).toBe('1');
    // Weekdays of the next years are still open: nothing closed every day.
    await page.goto(`${BASE}/admin/calendar?month=${farYear + 2}-09`);
    await expect(page.locator(`[data-calendar-day="${farYear + 2}-09-21"]`)).toHaveAttribute('data-closed', '0');
    await expect(page.locator(`[data-calendar-day="${farYear + 2}-09-22"]`)).toHaveAttribute('data-closed', '0');
    await page.goto(`${BASE}/admin/calendar?month=${farYear}-09`);
    await expect(page.locator(`[data-calendar-day="${farYear}-09-21"]`)).toHaveAttribute('data-closed', '1');
  });

  test('ICS 5: a file that is not a calendar is refused', async () => {
    const before = db('SELECT COUNT(*) FROM calendario_chiusure');
    await importIcs('this is not a calendar');
    await expect(page.locator('body')).toContainText(/non è un calendario ICS valido/);
    expect(db('SELECT COUNT(*) FROM calendario_chiusure')).toBe(before);
  });
});
