// @ts-check
// Holidays for every country and calendar on /admin/calendar, as a librarian
// uses them: every country of the world is in the list with its official
// holidays (estimated lunar dates marked, community and optional categories
// unticked), and the library adds holidays of its own — on a fixed day, in
// the Islamic or another calendar, for one branch only — then edits,
// suspends and deletes them. A loan due on a custom holiday moves to the next
// open day. DB only for setup and verification; dates in the app timezone.
//
// Run: /tmp/run-e2e.sh tests/holidays-world.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const F = require('./helpers/circulation-fixtures');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const ROOT = path.resolve(__dirname, '..');

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME, 'E2E credentials not configured');

function db(sql) {
  const args = [];
  if (DB_HOST) { args.push('-h', DB_HOST); if (DB_PORT) args.push('-P', DB_PORT); } else if (DB_SOCKET) { args.push('-S', DB_SOCKET); }
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}
const S = (v) => `'${String(v).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
function addDays(iso, days) {
  const d = new Date(`${iso}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}
const daysBetween = (from, to) => Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86400000);
/** The app's own calendar conversion, called the way the server does (no HTTP). */
function phpDates(calendar, month, day, year) {
  const code = `require 'vendor/autoload.php'; echo implode(',', App\\Support\\Holidays\\WorldCalendars::datesIn('${calendar}', ${month}, ${day}, ${year}));`;
  return execFileSync('php', ['-r', code], { cwd: ROOT, encoding: 'utf-8' }).trim().split(',').filter(Boolean);
}

const RUN = Date.now().toString().slice(-7);
const TAG = `E2E-WORLD-${RUN}`;
let today = '';
let year = 0;
let branchId = 0;
const SETTINGS = [['calendar', 'holiday_country'], ['calendar', 'holiday_region'], ['calendar', 'holiday_rules'], ['loans', 'closed_weekdays'], ['loans', 'loan_duration_days'], ['loans', 'max_loan_duration_days'], ['features', 'closures_calendar']];
/** @type {Record<string, string|null>} */
const saved = {};
let startMaxClosure = 0;
const getSetting = (cat, key) => {
  const v = db(`SELECT IFNULL((SELECT setting_value FROM system_settings WHERE category=${S(cat)} AND setting_key=${S(key)}), '\\N')`);
  return v === '\\N' || v === 'NULL' ? null : v;
};
const setSetting = (cat, key, value) => {
  if (value === null) db(`DELETE FROM system_settings WHERE category=${S(cat)} AND setting_key=${S(key)}`);
  else db(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES (${S(cat)}, ${S(key)}, ${S(value)}) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
};

async function login(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(/admin/, { timeout: 30000 });
}
async function chooseCountry(page, country) {
  await page.goto(`${BASE}/admin/calendar`);
  if ((await page.locator('#holiday_country').inputValue()) !== country) {
    await Promise.all([page.waitForURL(new RegExp(`holiday_country=${country}`)), page.selectOption('#holiday_country', country)]);
  }
}
const row = (page, id) => page.locator(`[data-holiday-row="${id}"]`);
const customRow = (page, name) => page.locator('[data-custom-holiday]', { hasText: name });
/** Fill the "holidays of the library" form the way a librarian does and save it. */
async function addCustom(page, fields) {
  await page.goto(`${BASE}/admin/calendar#calendar-custom`);
  await page.fill('#custom_name', fields.name);
  await page.selectOption('#custom_kind', fields.kind);
  if (fields.kind === 'fixed') {
    await page.fill('#custom_fixed_day', String(fields.day));
    await page.selectOption('#custom_fixed_month', String(fields.month));
  }
  if (fields.kind === 'calendar') {
    await page.selectOption('#custom_calendar', fields.calendar);
    await page.selectOption(`#custom_cal_month_${fields.calendar}`, String(fields.month));
    await page.fill('#custom_cal_day', String(fields.day));
  }
  if (fields.kind === 'dates') await page.fill('#custom_dates', fields.dates.join('\n'));
  if (fields.days) await page.fill('#custom_days', String(fields.days));
  if (fields.branch) await page.selectOption('#custom_branch', String(fields.branch));
  await Promise.all([page.waitForURL(/\/admin\/calendar/), page.locator('#save-custom-holiday').click()]);
}
/** Closed or open in the monthly preview of the calendar page (optionally of one branch). */
async function previewClosed(page, ymd, branch = 0) {
  await page.goto(`${BASE}/admin/calendar?month=${ymd.slice(0, 7)}${branch ? `&sede=${branch}` : ''}`);
  return (await page.locator(`[data-calendar-day="${ymd}"]`).getAttribute('data-closed')) === '1';
}

test.describe.configure({ mode: 'serial' });

test.describe('Holidays for every country and calendar', () => {
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    for (const [cat, key] of SETTINGS) saved[`${cat}.${key}`] = getSetting(cat, key);
    startMaxClosure = parseInt(db('SELECT COALESCE(MAX(id), 0) FROM calendario_chiusure') || '0', 10);
    setSetting('features', 'closures_calendar', '1');
    setSetting('loans', 'closed_weekdays', '');
    const tz = db("SELECT setting_value FROM system_settings WHERE category='app' AND setting_key='timezone' LIMIT 1") || 'Europe/Rome';
    today = new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    year = parseInt(today.slice(0, 4), 10);
    db(`INSERT INTO sedi (nome) VALUES (${S(TAG + ' branch')})`);
    branchId = parseInt(db(`SELECT id FROM sedi WHERE nome=${S(TAG + ' branch')}`), 10);
    page = await browser.newPage();
    await login(page);
  });

  test.afterAll(async () => {
    db(`DELETE FROM festivita_personalizzate WHERE nome LIKE ${S(TAG + '%')}`);
    db(`DELETE FROM calendario_chiusure WHERE id > ${startMaxClosure}`);
    db(`DELETE FROM sedi WHERE id = ${branchId}`);
    for (const [cat, key] of SETTINGS) setSetting(cat, key, saved[`${cat}.${key}`]);
    await page?.close();
  });

  test('1. every country of the world is in the list, named in the interface language', async () => {
    await page.goto(`${BASE}/admin/calendar`);
    const codes = await page.locator('#holiday_country option').evaluateAll((options) => options.map((o) => /** @type {HTMLOptionElement} */ (o).value));
    expect(codes.length).toBeGreaterThanOrEqual(249);
    for (const code of ['SA', 'JP', 'KE', 'BD', 'MA', 'IN', 'CN', 'BR', 'IT', 'NG', 'ID', 'TR']) expect(codes).toContain(code);
    await expect(page.locator('#holiday_country option[value="JP"]')).not.toHaveText('JP');
  });

  test('2. a country from the open data lists its official holidays with their own names; lunar estimates are marked', async () => {
    await chooseCountry(page, 'MA');
    await expect(page.locator('[data-holiday-source]')).toBeVisible();
    const eid = row(page, 'MA:eid_al_fitr');
    await expect(eid).toBeVisible();
    await expect(eid).toContainText('Eid al-Fitr');
    await expect(eid).toContainText('عيد الفطر');
    await expect(page.locator('[data-holiday-row] [data-holiday-estimated]').first()).toBeVisible();
  });

  test('3. community and optional holidays are offered but unticked; public ones are ticked', async () => {
    await chooseCountry(page, 'KE');
    await expect(page.locator('input[data-holiday-rule][value="KE:jamhuri_day"]')).toBeChecked();
    const community = page.locator('[data-holiday-category="islamic"], [data-holiday-category="hindu"]').first();
    await expect(community).toBeVisible();
    const communityBox = page.locator('[data-holiday-row]', { has: page.locator('[data-holiday-category="islamic"], [data-holiday-category="hindu"]') }).first().locator('input[data-holiday-rule]');
    await expect(communityBox).not.toBeChecked();
    await expect(community).toContainText(/comunità|community|Gemeinschaft|communauté|samfund/);
  });

  test('4. applying the holidays of a country from the open data closes the library on those days', async () => {
    await chooseCountry(page, 'JP');
    await page.locator('[data-holiday-select="none"]').click();
    await page.locator('input[data-holiday-rule][value="JP:culture_day"]').check();
    await Promise.all([page.waitForURL(/\/admin\/calendar/), page.locator('#import-preset').click()]);
    const culture = `${year}-11-03` >= today ? `${year}-11-03` : `${year + 1}-11-03`;
    expect(await previewClosed(page, culture)).toBe(true);
    expect(await previewClosed(page, addDays(culture, 1))).toBe(false);
  });

  test('5. a holiday of the library on a fixed day appears with its next dates and closes the day', async () => {
    const name = `${TAG} patron`;
    await addCustom(page, { name, kind: 'fixed', day: 24, month: 6 });
    await expect(page.locator('body')).toContainText(/Festività aggiunta|Holiday added/);
    const listed = customRow(page, name);
    await expect(listed).toBeVisible();
    const next = (await listed.locator('[data-custom-next]').getAttribute('data-custom-next')) || '';
    const expected = `${year}-06-24` >= today ? `${year}-06-24` : `${year + 1}-06-24`;
    expect(next.split(',')[0]).toBe(expected);
    expect(await previewClosed(page, expected)).toBe(true);
  });

  test('6. a holiday in the Islamic calendar lasts three days from the computed date', async () => {
    const name = `${TAG} eid`;
    await addCustom(page, { name, kind: 'calendar', calendar: 'islamic-umalqura', month: 10, day: 1, days: 3 });
    const next = ((await customRow(page, name).locator('[data-custom-next]').getAttribute('data-custom-next')) || '').split(',');
    const candidates = [...phpDates('islamic-umalqura', 10, 1, year), ...phpDates('islamic-umalqura', 10, 1, year + 1)];
    const start = /** @type {string} */ (candidates.find((d) => d >= today));
    expect(start).toBeTruthy();
    // Unless today falls inside this year's three days, the list starts on the computed date.
    if (!candidates.some((d) => d < today && addDays(d, 2) >= today)) {
      expect(next).toEqual([start, addDays(start, 1), addDays(start, 2)]);
    }
    expect(await previewClosed(page, start)).toBe(true);
    expect(await previewClosed(page, addDays(start, 2))).toBe(true);
    await expect(customRow(page, name)).toContainText(/Islamico|Islamic|Islamisch|Islamique|Islamisk/);
  });

  test('7. a holiday of one branch closes that branch only', async () => {
    const day = addDays(today, 40);
    const name = `${TAG} branch day`;
    await addCustom(page, { name, kind: 'dates', dates: [day], branch: branchId });
    expect(await previewClosed(page, day, branchId)).toBe(true);
    expect(await previewClosed(page, day)).toBe(false);
  });

  test('8. a loan due on a holiday of the library moves to the next open day', async () => {
    const day = addDays(today, 30);
    const name = `${TAG} loan day`;
    await addCustom(page, { name, kind: 'dates', dates: [day] });
    setSetting('loans', 'max_loan_duration_days', String(Math.max(daysBetween(today, day), 90)));
    setSetting('loans', 'loan_duration_days', String(daysBetween(today, day)));
    let expected = addDays(day, 1);
    while (await previewClosed(page, expected)) expected = addDays(expected, 1);
    await page.goto(`${BASE}/admin/loans/create`);
    await expect(page.locator('#data_scadenza')).toHaveValue(expected);
  });

  test('9. editing, suspending and deleting a holiday of the library', async () => {
    const name = `${TAG} patron`;
    await page.goto(`${BASE}/admin/calendar#calendar-custom`);
    await customRow(page, name).locator('[data-action="edit-custom-holiday"]').click();
    await expect(page.locator('#custom_name')).toHaveValue(name);
    await page.fill('#custom_name', `${name} renamed`);
    await Promise.all([page.waitForURL(/\/admin\/calendar/), page.locator('#save-custom-holiday').click()]);
    await expect(customRow(page, `${name} renamed`)).toBeVisible();
    // A rename keeps how the day is computed: still 24 June, read back into the form.
    expect(db(`SELECT CONCAT(tipo, '/', mese, '/', giorno) FROM festivita_personalizzate WHERE nome=${S(name + ' renamed')}`)).toBe('fixed/6/24');
    await customRow(page, `${name} renamed`).locator('[data-action="edit-custom-holiday"]').click();
    await expect(page.locator('#custom_kind')).toHaveValue('fixed');
    await expect(page.locator('#custom_fixed_day')).toHaveValue('24');
    await expect(page.locator('#custom_fixed_month')).toHaveValue('6');
    await page.goto(`${BASE}/admin/calendar#calendar-custom`);
    const expected = `${year}-06-24` >= today ? `${year}-06-24` : `${year + 1}-06-24`;

    await Promise.all([page.waitForURL(/\/admin\/calendar/), customRow(page, `${name} renamed`).locator('[data-action="toggle-custom-holiday"]').click()]);
    await expect(customRow(page, `${name} renamed`).locator('[data-badge="inactive"]')).toBeVisible();
    expect(await previewClosed(page, expected)).toBe(false);

    await page.goto(`${BASE}/admin/calendar#calendar-custom`);
    await customRow(page, `${name} renamed`).locator('[data-action="delete-custom-holiday"]').click();
    await Promise.all([page.waitForURL(/\/admin\/calendar/), page.locator('.swal2-confirm').click()]);
    await expect(customRow(page, `${name} renamed`)).toHaveCount(0);
    expect(db(`SELECT COUNT(*) FROM festivita_personalizzate WHERE nome=${S(name + ' renamed')}`)).toBe('0');
  });

  test('10. an impossible date is refused with a message and nothing is stored', async () => {
    const name = `${TAG} impossible`;
    await addCustom(page, { name, kind: 'fixed', day: 31, month: 2 });
    await expect(page.locator('body')).toContainText(/La data indicata non esiste|That date does not exist/);
    expect(db(`SELECT COUNT(*) FROM festivita_personalizzate WHERE nome=${S(name)}`)).toBe('0');
    // The form comes back with what was typed, ready to be corrected.
    await expect(page.locator('#custom_name')).toHaveValue(name);
    await expect(page.locator('#custom_kind')).toHaveValue('fixed');
    await expect(page.locator('#custom_fixed_day')).toHaveValue('31');
    await expect(page.locator('#custom_fixed_month')).toHaveValue('2');
    await page.fill('#custom_fixed_day', '28');
    await Promise.all([page.waitForURL(/\/admin\/calendar/), page.locator('#save-custom-holiday').click()]);
    expect(db(`SELECT CONCAT(mese, '/', giorno) FROM festivita_personalizzate WHERE nome=${S(name)}`)).toBe('2/28');
  });

  test('11. a refused edit keeps the typed values on the same holiday; a vanished branch is refused', async () => {
    const name = `${TAG} impossible`;
    const id = db(`SELECT id FROM festivita_personalizzate WHERE nome=${S(name)}`);
    await page.goto(`${BASE}/admin/calendar#calendar-custom`);
    await customRow(page, name).locator('[data-action="edit-custom-holiday"]').click();
    await page.fill('#custom_name', `${name} edited`);
    // Each year is valid on its own, so only the server can refuse the pair.
    await page.fill('#custom_from', '2040');
    await page.fill('#custom_until', '2030');
    await Promise.all([page.waitForURL(/custom_edit=/), page.locator('#save-custom-holiday').click()]);
    await expect(page.locator('body')).toContainText(/L’ultimo anno non può precedere il primo|The last year cannot come before the first/);
    await expect(page.locator('#custom_name')).toHaveValue(`${name} edited`);
    await expect(page.locator('#custom_from')).toHaveValue('2040');
    await expect(page.locator('#custom_until')).toHaveValue('2030');
    await expect(page.locator('#custom-holiday-form')).toHaveAttribute('action', new RegExp(`/custom-holidays/${id}/update$`));
    expect(db(`SELECT CONCAT(nome, '|', COALESCE(anno_inizio, '-'), '|', COALESCE(anno_fine, '-')) FROM festivita_personalizzate WHERE id=${id}`)).toBe(`${name}|-|-`);

    // A branch removed while the form was open: the holiday is refused, not widened to every branch.
    const csrf = String(await page.locator('meta[name="csrf-token"]').getAttribute('content'));
    const r = await page.request.post(`${BASE}/admin/calendar/custom-holidays`, {
      form: { csrf_token: csrf, nome: `${TAG} ghost branch`, tipo: 'fixed', fixed_mese: '5', fixed_giorno: '5', sede_id: '999999', attiva: '1' },
      maxRedirects: 0,
    });
    expect(r.status()).toBe(302);
    expect(db(`SELECT COUNT(*) FROM festivita_personalizzate WHERE nome=${S(TAG + ' ghost branch')}`)).toBe('0');
  });

  test('13. a chosen holiday the dataset no longer knows is named on the page, not silently dropped', async () => {
    const before = ['holiday_country', 'holiday_region', 'holiday_rules'].map((k) => getSetting('calendar', k));
    setSetting('calendar', 'holiday_country', 'IT');
    setSetting('calendar', 'holiday_region', '');
    setSetting('calendar', 'holiday_rules', '["IT:new_year","IT:zz_vanished_rule","IT:christmas"]');
    try {
      await page.goto(`${BASE}/admin/calendar`);
      const notice = page.locator('[data-holiday-missing]');
      await expect(notice).toBeVisible();
      await expect(notice).toContainText('zz_vanished_rule');
      await expect(notice).not.toContainText('new_year');
      // The known choices are still ticked; the vanished one is not a row.
      await expect(page.locator('input[data-holiday-rule][value="IT:christmas"]')).toBeChecked();
      await expect(page.locator('[data-holiday-row="IT:zz_vanished_rule"]')).toHaveCount(0);
      // Another country previewed: the notice is about the saved selection, so it stays.
      await chooseCountry(page, 'DE');
      await expect(page.locator('[data-holiday-missing]')).toContainText('zz_vanished_rule');
      // Saving the choice again stores only known ids and the notice goes.
      await chooseCountry(page, 'IT');
      await Promise.all([page.waitForURL(/\/admin\/calendar/), page.locator('#import-preset').click()]);
      await expect(page.locator('[data-holiday-missing]')).toHaveCount(0);
      expect(db("SELECT setting_value FROM system_settings WHERE category='calendar' AND setting_key='holiday_rules'")).not.toContain('zz_vanished_rule');
    } finally {
      ['holiday_country', 'holiday_region', 'holiday_rules'].forEach((k, i) => setSetting('calendar', k, before[i]));
    }
  });

  test('12. staff cannot add, change, suspend or delete a holiday of the library; a form without its token is refused', async ({ browser }) => {
    const id = db(`SELECT id FROM festivita_personalizzate WHERE nome=${S(TAG + ' impossible')}`);
    const snapshot = () => db(`SELECT CONCAT(COUNT(*), '|', COALESCE(SUM(attiva), 0), '|', COALESCE(GROUP_CONCAT(nome ORDER BY id), '')) FROM festivita_personalizzate WHERE nome LIKE ${S(TAG + '%')}`);
    const before = snapshot();
    const staff = F.createPatron(`HWS${RUN}`, { role: 'staff', password: 'Staff1234!' });
    const staffPage = await (await browser.newContext()).newPage();
    try {
      await F.loginAs(staffPage, staff.email, 'Staff1234!');
      await staffPage.goto(`${BASE}/admin/dashboard`);
      const csrf = String(await staffPage.locator('meta[name="csrf-token"]').getAttribute('content'));
      const fields = { tipo: 'fixed', fixed_mese: '5', fixed_giorno: '5', attiva: '1' };
      for (const [route, form] of [
        ['/admin/calendar/custom-holidays', { ...fields, nome: `${TAG} staff` }],
        [`/admin/calendar/custom-holidays/${id}/update`, { ...fields, nome: `${TAG} staff edit` }],
        [`/admin/calendar/custom-holidays/${id}/toggle`, {}],
        [`/admin/calendar/custom-holidays/${id}/delete`, {}],
      ]) {
        const r = await staffPage.request.post(`${BASE}${route}`, { form: { csrf_token: csrf, ...form }, maxRedirects: 0 });
        expect(r.status(), route).toBe(302);
        expect(r.headers()['location'] || '', route).toMatch(/\/admin\/dashboard/);
      }
    } finally {
      await staffPage.context().close();
      F.deletePatrons([staff.id]);
    }
    expect(snapshot()).toBe(before);

    const noToken = await page.request.post(`${BASE}/admin/calendar/custom-holidays/${id}/delete`, { form: {}, maxRedirects: 0 });
    expect(noToken.status()).toBe(403);
    expect(snapshot()).toBe(before);
  });
});
