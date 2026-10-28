// @ts-check
// Public holiday presets on /admin/calendar, as a librarian uses them: pick
// the country (and region), look at the holidays with their dates for the
// preview year, tick the ones the library closes for and apply. Applying
// again changes nothing, unticking removes only that holiday, switching
// country replaces the preset, closures added by hand or from an ICS file
// stay, yearly closures can be switched off without deleting them, and the
// rows of the former Italian preset are adopted instead of duplicated. A loan
// due on a ticked holiday moves to the next open day; on an unticked one it
// stays. DB only for setup and verification; dates in the app timezone.
//
// Run: /tmp/run-e2e.sh tests/holidays-preset.spec.js --config=tests/playwright.config.js --workers=1
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
const S = (v) => `'${String(v).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
function addDays(iso, days) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  if (!m) throw new Error(`not an ISO date: ${iso}`);
  const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}
function daysBetween(from, to) {
  return Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86400000);
}
function weekday(iso) {
  return new Date(`${iso}T00:00:00Z`).getUTCDay();
}
/** Gregorian Easter Sunday (anonymous algorithm), independent of the app. */
function easter(year) {
  const a = year % 19, b = Math.floor(year / 100), c = year % 100, d = Math.floor(b / 4), e = b % 4;
  const f = Math.floor((b + 8) / 25), g = Math.floor((b - f + 1) / 3), h = (19 * a + b - d - g + 15) % 30;
  const i = Math.floor(c / 4), k = c % 4, l = (32 + 2 * e + 2 * i - h - k) % 7, m = Math.floor((a + 11 * h + 22 * l) / 451);
  const month = Math.floor((h + l - 7 * m + 114) / 31), day = ((h + l - 7 * m + 114) % 31) + 1;
  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

const RUN = Date.now().toString().slice(-7);
const TAG = `E2E-HOL-${RUN}`;
let startMaxId = 0;
let today = '';
let year = 0;
let farYear = 0;
/** @type {string} snapshot of the rows that existed before the run */
let oldRows = '';
/** @type {Record<string, string|null>} */
const savedSettings = {};
const SETTINGS = [['calendar', 'holiday_country'], ['calendar', 'holiday_region'], ['calendar', 'holiday_rules'], ['loans', 'closed_weekdays'], ['loans', 'loan_duration_days'], ['loans', 'max_loan_duration_days']];
const getSetting = (cat, key) => {
  const v = db(`SELECT IFNULL((SELECT setting_value FROM system_settings WHERE category=${S(cat)} AND setting_key=${S(key)}), '\\N')`);
  return v === '\\N' || v === 'NULL' ? null : v;
};
const setSetting = (cat, key, value) => {
  if (value === null) db(`DELETE FROM system_settings WHERE category=${S(cat)} AND setting_key=${S(key)}`);
  else db(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES (${S(cat)}, ${S(key)}, ${S(value)}) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
};
const presetCount = (where = '1=1') => parseInt(db(`SELECT COUNT(*) FROM calendario_chiusure WHERE regola_festivita IS NOT NULL AND ${where}`), 10);
const nonPresetSnapshot = () => db(`SELECT IFNULL(GROUP_CONCAT(CONCAT_WS('|', id, data_inizio, data_fine, descrizione, ricorrente_annuale, attiva) ORDER BY id SEPARATOR ';'), '') FROM calendario_chiusure WHERE regola_festivita IS NULL AND descrizione LIKE ${S(TAG + '%')}`);

async function login(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(/admin/, { timeout: 30000 });
}
async function submitAndLand(page, button) {
  await Promise.all([page.waitForURL(/\/admin\/calendar/, { timeout: 15000 }), page.locator(button).click()]);
}
/** Choose a country (and region) in the card, as the librarian does: each change reloads the list. */
async function chooseCountry(page, country, region = '') {
  await page.goto(`${BASE}/admin/calendar`);
  if ((await page.locator('#holiday_country').inputValue()) !== country) {
    await Promise.all([page.waitForURL(new RegExp(`holiday_country=${country}`)), page.selectOption('#holiday_country', country)]);
  }
  if (region) {
    await Promise.all([page.waitForURL(new RegExp(`holiday_region=${region}`)), page.selectOption('#holiday_region', region)]);
  }
}
const box = (page, id) => page.locator(`input[data-holiday-rule][value="${id}"]`);
const holidayDate = (page, id) => page.locator(`[data-holiday-row="${id}"] [data-holiday-date]`).getAttribute('data-holiday-date');

test.describe.configure({ mode: 'serial' });

test.describe('Public holiday presets', () => {
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    startMaxId = parseInt(db('SELECT COALESCE(MAX(id), 0) FROM calendario_chiusure') || '0', 10);
    oldRows = db("SELECT IFNULL(GROUP_CONCAT(CONCAT_WS('|', id, IFNULL(regola_festivita,'-'), descrizione, attiva) ORDER BY id SEPARATOR ';'), '') FROM calendario_chiusure");
    for (const [cat, key] of SETTINGS) savedSettings[`${cat}.${key}`] = getSetting(cat, key);
    // A clean start: no choice saved, no weekly closed day, no preset rows.
    for (const [cat, key] of SETTINGS.slice(0, 3)) setSetting(cat, key, null);
    setSetting('loans', 'closed_weekdays', '');
    db('DELETE FROM calendario_chiusure WHERE regola_festivita IS NOT NULL');
    const tz = db("SELECT setting_value FROM system_settings WHERE category='app' AND setting_key='timezone' LIMIT 1") || 'Europe/Rome';
    today = new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    year = parseInt(today.slice(0, 4), 10);
    farYear = year + 3;
    page = await browser.newPage();
    await login(page);
  });

  test.afterAll(async () => {
    db(`DELETE FROM calendario_chiusure WHERE id > ${startMaxId}`);
    // Rows older than the run: back to what they were (adoption changes them).
    for (const row of oldRows ? oldRows.split(';') : []) {
      const [id, rule, description, active] = row.split('|');
      db(`UPDATE calendario_chiusure SET regola_festivita=${rule === '-' ? 'NULL' : S(rule)}, descrizione=${S(description)}, attiva=${parseInt(active, 10)} WHERE id=${parseInt(id, 10)}`);
    }
    for (const [cat, key] of SETTINGS) setSetting(cat, key, savedSettings[`${cat}.${key}`]);
    await page?.close();
  });

  test('1. the card preselects the country of the installation and lists its holidays with the dates of the preview year', async () => {
    await page.goto(`${BASE}/admin/calendar`);
    const locale = db("SELECT setting_value FROM system_settings WHERE category='app' AND setting_key='locale' LIMIT 1") || 'it_IT';
    const expected = { it: 'IT', en: 'US', de: 'DE', fr: 'FR', da: 'DK' }[locale.slice(0, 2)] || 'IT';
    await expect(page.locator('#holiday_country')).toHaveValue(expected);
    await expect(page.locator('#calendar-preset h2')).toContainText(/Festività nazionali|Public holidays|Feiertage|Jours fériés|Helligdage/);

    await chooseCountry(page, 'IT');
    await expect(page.locator('#holiday_region')).toHaveCount(0);
    await expect(page.locator('input[data-holiday-rule]')).toHaveCount(13);
    // Native names, the computed date of the preview year, every statutory one ticked.
    await expect(page.locator('[data-holiday-row="IT:republic"]')).toContainText('Festa della Repubblica');
    expect(await holidayDate(page, 'IT:easter_monday')).toBe(addDays(easter(year), 1));
    expect(await holidayDate(page, 'IT:christmas')).toBe(`${year}-12-25`);
    await expect(page.locator('input[data-holiday-rule]:checked')).toHaveCount(13);
    // Another preview year: the movable dates follow.
    await Promise.all([page.waitForURL(/holiday_year=/), page.selectOption('#holiday_year', String(year + 1))]);
    expect(await holidayDate(page, 'IT:easter_monday')).toBe(addDays(easter(year + 1), 1));
    expect(presetCount()).toBe(0);
  });

  test('2. Germany shows the Länder: Sachsen adds Buß- und Bettag on a Wednesday, Bayern adds Fronleichnam', async () => {
    await chooseCountry(page, 'DE');
    await expect(page.locator('#holiday_region option')).toHaveCount(17);
    await expect(box(page, 'DE:fronleichnam')).toHaveCount(0);
    await expect(box(page, 'DE:tag_der_deutschen_einheit')).toHaveCount(1);
    await Promise.all([page.waitForURL(/holiday_region=SN/), page.selectOption('#holiday_region', 'SN')]);
    const buss = await holidayDate(page, 'DE:buss_und_bettag');
    expect(buss).toMatch(new RegExp(`^${year}-11-(1[6-9]|2[0-2])$`));
    expect(weekday(/** @type {string} */ (buss))).toBe(3);
    await expect(page.locator('[data-holiday-row="DE:buss_und_bettag"]')).toContainText(/regionale|regional|régional/);
    await Promise.all([page.waitForURL(/holiday_region=BY/), page.selectOption('#holiday_region', 'BY')]);
    await expect(box(page, 'DE:buss_und_bettag')).toHaveCount(0);
    expect(await holidayDate(page, 'DE:fronleichnam')).toBe(addDays(easter(year), 60));
  });

  test('3. ticking a subset and applying stores only those holidays, shown as holidays without edit or delete', async () => {
    // A closure by hand and an ICS import first: the preset must leave them alone.
    await page.goto(`${BASE}/admin/calendar`);
    await page.fill('#closure_start', `${farYear}-03-03`);
    await page.fill('#closure_description', `${TAG} manual`);
    await submitAndLand(page, '#save-closure');
    const ics = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//E2E//Pinakes//IT',
      'BEGIN:VEVENT', 'UID:h1@e2e', `DTSTART;VALUE=DATE:${farYear}0707`, `SUMMARY:${TAG} ics once`, 'END:VEVENT',
      'BEGIN:VEVENT', 'UID:h2@e2e', `DTSTART;VALUE=DATE:${farYear}0909`, 'RRULE:FREQ=YEARLY', `SUMMARY:${TAG} ics yearly`, 'END:VEVENT',
      'END:VCALENDAR', ''].join('\r\n');
    await page.setInputFiles('#ics_file', { name: 'chiusure.ics', mimeType: 'text/calendar', buffer: Buffer.from(ics) });
    await submitAndLand(page, '#import-ics');
    expect(nonPresetSnapshot().split(';')).toHaveLength(3);

    await chooseCountry(page, 'US');
    await page.locator('[data-holiday-select="none"]').click();
    await expect(page.locator('input[data-holiday-rule]:checked')).toHaveCount(0);
    for (const id of ['US:new_year', 'US:independence_day', 'US:thanksgiving', 'US:christmas']) await box(page, id).check();
    await submitAndLand(page, '#import-preset');
    await expect(page.locator('body')).toContainText(/Festività applicate: 12 aggiunte, 0 rimosse|Holidays applied: 12 added, 0 removed/);
    expect(db("SELECT GROUP_CONCAT(DISTINCT regola_festivita ORDER BY regola_festivita) FROM calendario_chiusure WHERE regola_festivita IS NOT NULL"))
      .toBe('US:christmas,US:independence_day,US:new_year,US:thanksgiving');
    // Observed rules: one row per year, from this year to two years ahead.
    expect(presetCount("regola_festivita='US:thanksgiving' AND ricorrente_annuale=0")).toBe(3);
    // The choice is what the card shows from now on.
    await expect(page.locator('#holiday_country')).toHaveValue('US');
    await expect(box(page, 'US:thanksgiving')).toBeChecked();
    await expect(box(page, 'US:labor_day')).not.toBeChecked();
    // The list marks them as holidays; they are changed from the selection only.
    const row = page.locator('#calendar-closures tr', { has: page.locator('[data-badge="holiday"]') }).filter({ hasText: 'Thanksgiving Day' }).first();
    await expect(row).toBeVisible();
    await expect(row.locator('[data-action="delete-closure"]')).toHaveCount(0);
    await expect(row.locator('[data-action="edit-closure"]')).toHaveCount(0);
  });

  test('4. applying the same selection again adds and removes nothing', async () => {
    const before = db('SELECT COUNT(*) FROM calendario_chiusure');
    await chooseCountry(page, 'US');
    await submitAndLand(page, '#import-preset');
    await expect(page.locator('body')).toContainText(/Festività applicate: 0 aggiunte, 0 rimosse|Holidays applied: 0 added, 0 removed/);
    expect(db('SELECT COUNT(*) FROM calendario_chiusure')).toBe(before);
  });

  test('5. unticking one holiday removes only its rows', async () => {
    await chooseCountry(page, 'US');
    await box(page, 'US:christmas').uncheck();
    await submitAndLand(page, '#import-preset');
    await expect(page.locator('body')).toContainText(/Festività applicate: 0 aggiunte, 3 rimosse|Holidays applied: 0 added, 3 removed/);
    expect(presetCount("regola_festivita='US:christmas'")).toBe(0);
    expect(presetCount()).toBe(9);
    await expect(box(page, 'US:christmas')).not.toBeChecked();
  });

  test('6. a loan due on a ticked holiday moves to the next open day, on an unticked holiday it stays', async () => {
    // The loan duration is chosen (setup in the DB) so that the default due
    // date of the admin loan form lands on the holiday; the form is used as-is.
    const minDays = 2;
    const ticked = db(`SELECT MIN(data_inizio) FROM calendario_chiusure WHERE regola_festivita IS NOT NULL AND data_inizio >= ${S(addDays(today, minDays))}`);
    expect(ticked).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    const closed = new Set(db(`SELECT data_inizio FROM calendario_chiusure WHERE regola_festivita IS NOT NULL OR descrizione LIKE ${S(TAG + '%')}`).split('\n'));
    let expectedDue = addDays(ticked, 1);
    while (closed.has(expectedDue)) expectedDue = addDays(expectedDue, 1);
    const dueFor = async (target) => {
      const days = daysBetween(today, target);
      setSetting('loans', 'max_loan_duration_days', String(Math.max(days, 90)));
      setSetting('loans', 'loan_duration_days', String(days));
      await page.goto(`${BASE}/admin/loans/create`);
      return page.locator('#data_scadenza').inputValue();
    };
    expect(await dueFor(ticked)).toBe(expectedDue);

    // Labor Day (first Monday of September) is not ticked: a loan due then stays.
    await chooseCountry(page, 'US');
    let labor = await holidayDate(page, 'US:labor_day');
    if (!labor || labor < addDays(today, minDays)) {
      await Promise.all([page.waitForURL(/holiday_year=/), page.selectOption('#holiday_year', String(year + 1))]);
      labor = await holidayDate(page, 'US:labor_day');
    }
    expect(weekday(/** @type {string} */ (labor))).toBe(1);
    expect(await dueFor(/** @type {string} */ (labor))).toBe(labor);
  });

  test('7. switching country replaces the preset and select none clears it; manual and ICS closures stay', async () => {
    const manual = nonPresetSnapshot();
    await chooseCountry(page, 'DE', 'BY');
    await box(page, 'DE:buss_und_bettag').count().then((n) => expect(n).toBe(0));
    await submitAndLand(page, '#import-preset');
    await expect(page.locator('body')).toContainText(/9 rimosse|9 removed/);
    expect(presetCount("regola_festivita LIKE 'US:%'")).toBe(0);
    expect(presetCount("regola_festivita='DE:fronleichnam'")).toBe(3);
    expect(presetCount("regola_festivita='DE:neujahr' AND ricorrente_annuale=1")).toBe(1);
    expect(nonPresetSnapshot()).toBe(manual);
    // Bayern closes on Corpus Christi.
    const corpus = addDays(easter(year + 1), 60);
    await page.goto(`${BASE}/admin/calendar?month=${corpus.slice(0, 7)}`);
    await expect(page.locator(`[data-calendar-day="${corpus}"]`)).toHaveAttribute('data-closed', '1');

    await chooseCountry(page, 'DE', 'BY');
    await page.locator('[data-holiday-select="none"]').click();
    await submitAndLand(page, '#import-preset');
    expect(presetCount()).toBe(0);
    expect(nonPresetSnapshot()).toBe(manual);
    await page.goto(`${BASE}/admin/calendar?month=${corpus.slice(0, 7)}`);
    await expect(page.locator(`[data-calendar-day="${corpus}"]`)).toHaveAttribute('data-closed', '0');
    await expect(page.locator('#calendar-closures')).toContainText(`${TAG} ics yearly`);
    await expect(page.locator('#calendar-closures')).toContainText(`${TAG} manual`);
  });

  test('8. a yearly closure added by hand can be switched off and on without deleting it', async () => {
    const day = `${farYear}-10-13`;
    await page.goto(`${BASE}/admin/calendar`);
    await page.fill('#closure_start', day);
    await page.fill('#closure_description', `${TAG} patrono`);
    await page.check('#closure_yearly');
    await submitAndLand(page, '#save-closure');
    const id = parseInt(db(`SELECT id FROM calendario_chiusure WHERE descrizione=${S(TAG + ' patrono')}`), 10);
    await page.goto(`${BASE}/admin/calendar`);
    // The ICS yearly closure is listed as well; the one-off ones are not.
    await expect(page.locator('#calendar-yearly')).toContainText(`${TAG} ics yearly`);
    await expect(page.locator('#calendar-yearly')).not.toContainText(`${TAG} manual`);
    await expect(page.locator(`#yearly_closure_${id}`)).toBeChecked();
    await page.locator(`#yearly_closure_${id}`).uncheck();
    await submitAndLand(page, '#save-yearly');
    await expect(page.locator('body')).toContainText(/Chiusure annuali salvate: 1 modificate|Yearly closures saved: 1 changed/);
    expect(db(`SELECT CONCAT(COUNT(*), '|', MIN(attiva)) FROM calendario_chiusure WHERE id=${id}`)).toBe('1|0');
    await expect(page.locator(`#calendar-closures tr[data-closure-id="${id}"] [data-badge="inactive"]`)).toBeVisible();
    await page.goto(`${BASE}/admin/calendar?month=${farYear + 1}-10`);
    await expect(page.locator(`[data-calendar-day="${farYear + 1}-10-13"]`)).toHaveAttribute('data-closed', '0');

    await page.goto(`${BASE}/admin/calendar`);
    await page.locator(`#yearly_closure_${id}`).check();
    await submitAndLand(page, '#save-yearly');
    expect(db(`SELECT attiva FROM calendario_chiusure WHERE id=${id}`)).toBe('1');
    await page.goto(`${BASE}/admin/calendar?month=${farYear + 1}-10`);
    await expect(page.locator(`[data-calendar-day="${farYear + 1}-10-13"]`)).toHaveAttribute('data-closed', '1');
  });

  test('9. the rows of the former Italian preset are recognised, not duplicated', async () => {
    // What the earlier importer stored: fixed holidays as yearly rows, Easter
    // Monday dated for one year, labels in the installation language.
    const pasquetta = addDays(easter(year + 1), 1);
    db(`INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, ricorrente_annuale) VALUES
      ('${year}-12-25','${year}-12-25','Natale',1), ('${year}-08-15','${year}-08-15','Ferragosto',1), ('${pasquetta}','${pasquetta}','Lunedì dell''Angelo (Pasquetta)',0)`);
    const legacyIds = db("SELECT GROUP_CONCAT(id ORDER BY id) FROM calendario_chiusure WHERE regola_festivita IS NULL AND descrizione IN ('Natale','Ferragosto','Lunedì dell''Angelo (Pasquetta)')");
    await chooseCountry(page, 'IT');
    await page.locator('[data-holiday-select="all"]').click();
    await submitAndLand(page, '#import-preset');
    await expect(page.locator('body')).toContainText(/3 già presenti riconosciute|3 already present recognised/);
    expect(db(`SELECT COUNT(*) FROM calendario_chiusure WHERE ricorrente_annuale=1 AND DATE_FORMAT(data_inizio,'%m-%d')='12-25' AND sede_id IS NULL`)).toBe('1');
    expect(db(`SELECT COUNT(*) FROM calendario_chiusure WHERE data_inizio='${pasquetta}' AND ricorrente_annuale=0`)).toBe('1');
    expect(db("SELECT GROUP_CONCAT(id ORDER BY id) FROM calendario_chiusure WHERE regola_festivita IN ('IT:christmas','IT:ferragosto') OR (regola_festivita='IT:easter_monday' AND data_inizio=" + S(pasquetta) + ')')).toBe(legacyIds);
    // Applying once more is a no-op.
    await chooseCountry(page, 'IT');
    await submitAndLand(page, '#import-preset');
    await expect(page.locator('body')).toContainText(/Festività applicate: 0 aggiunte, 0 rimosse|Holidays applied: 0 added, 0 removed/);
  });

  test('10. the United Kingdom always works on a nation: Scotland has 2 January and St Andrew, not Easter Monday', async () => {
    await chooseCountry(page, 'GB');
    // No "national holidays only" choice: England and Wales is the default nation.
    await expect(page.locator('#holiday_region')).toHaveValue('ENG');
    await expect(page.locator('#holiday_region option[value=""]')).toHaveCount(0);
    await expect(box(page, 'GB:easter_monday')).toHaveCount(1);
    await Promise.all([page.waitForURL(/holiday_region=SCT/), page.selectOption('#holiday_region', 'SCT')]);
    await expect(box(page, 'GB:easter_monday')).toHaveCount(0);
    await expect(box(page, 'GB:second_january')).toHaveCount(1);
    const andrew = /** @type {string} */ (await holidayDate(page, 'GB:st_andrew'));
    // 30 November, or the Monday after when it falls on a weekend.
    expect([`${year}-11-30`, `${year}-12-01`, `${year}-12-02`]).toContain(andrew);
    expect([0, 6]).not.toContain(weekday(andrew));
  });

  test('11. a holiday abolished before the preview year is listed as not held that year (Store bededag in Denmark)', async () => {
    await chooseCountry(page, 'DK');
    const row = page.locator('[data-holiday-row="DK:store_bededag"]');
    await expect(row).toContainText(/non prevista nel|not held in/);
    expect(await holidayDate(page, 'DK:store_bededag')).toBe('');
    // Customary days start unticked, statutory ones ticked.
    await expect(box(page, 'DK:juleaftensdag')).not.toBeChecked();
    await expect(box(page, 'DK:grundlovsdag')).toBeChecked();
    await expect(page.locator('[data-holiday-row="DK:juleaftensdag"]')).toContainText(/facoltativa|optional/);
  });

  test('12. France: Alsace-Moselle adds Good Friday and St Stephen to the national list', async () => {
    await chooseCountry(page, 'FR');
    const national = await page.locator('input[data-holiday-rule]').count();
    await expect(box(page, 'FR:vendredi_saint')).toHaveCount(0);
    await Promise.all([page.waitForURL(/holiday_region=ALM/), page.selectOption('#holiday_region', 'ALM')]);
    await expect(page.locator('input[data-holiday-rule]')).toHaveCount(national + 2);
    expect(await holidayDate(page, 'FR:vendredi_saint')).toBe(addDays(easter(year), -2));
    expect(await holidayDate(page, 'FR:saint_etienne')).toBe(`${year}-12-26`);
  });
});
