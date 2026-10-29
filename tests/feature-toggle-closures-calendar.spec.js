// @ts-check
/**
 * features.closures_calendar (Settings → Loans): off, the calendar is not
 * applied (due dates and the reader's request calendar see a library open
 * every day, as before 0.9.0) and its pages are gone; the closures already
 * stored are kept and apply again when the module is switched back on.
 *
 * Run: /Users/fabio/Documents/GitHub/run-e2e-wt3.sh tests/feature-toggle-closures-calendar.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const ft = require('./helpers/feature-toggles');
const mf = require('./helpers/minor-features-090');

test.skip(!ft.configured, 'E2E credentials not configured');

const RUN = Date.now().toString(36);
const TITLE = `ZZ_FT_CAL_${RUN}`;
const NOTE = `ZZ_FT_CAL ${RUN}`;

test.describe.serial('Feature toggle: closures calendar', () => {
  test.describe.configure({ timeout: 180000 });
  /** @type {import('@playwright/test').Page} */
  let page;
  let snapshot;
  let bookId = 0;
  let closed = '';
  let yearlyId = 0;

  /** closed_dates of the public availability the request calendar reads. */
  async function closedDates() {
    const res = await page.request.get(`${ft.BASE}/api/libro/${bookId}/availability`);
    expect(res.status()).toBe(200);
    const body = await res.json();
    const availability = body.availability || body.data?.availability || body;
    return availability.closed_dates || [];
  }

  test.beforeAll(async ({ browser }) => {
    snapshot = ft.readFeatures(['closures_calendar']);
    await ft.writeFeatures({ closures_calendar: '1' });
    closed = ft.dbQuery('SELECT DATE_FORMAT(CURDATE() + INTERVAL 3 DAY, \'%Y-%m-%d\')');
    ft.dbQuery(`INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione) VALUES ('${closed}', '${closed}', ${ft.sqlString(NOTE)})`);
    ft.dbQuery(`INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, ricorrente_annuale) VALUES ('2026-08-14', '2026-08-14', ${ft.sqlString(NOTE + ' yearly')}, 1)`);
    yearlyId = Number(ft.dbQuery(`SELECT id FROM calendario_chiusure WHERE descrizione=${ft.sqlString(NOTE + ' yearly')}`));
    bookId = mf.seedBook({ titolo: TITLE, copie_totali: 1, copie_disponibili: 1 });
    ft.dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${bookId}, ${ft.sqlString(`ZZFTK-${RUN}`)}, 'disponibile')`);
    page = await browser.newPage();
    await ft.loginAdmin(page);
  });

  test.afterAll(async () => {
    await ft.restoreFeatures(snapshot);
    ft.dbQuery(`DELETE FROM calendario_chiusure WHERE descrizione LIKE 'ZZ_FT_CAL %'`);
    mf.deleteBooks([bookId]);
    await page?.close();
  });

  test('1. switched on: the closed day is published to the request calendar', async () => {
    expect(await closedDates()).toContain(closed);
  });

  test('2. switched off from Settings → Loans: no sidebar entry, no calendar page, no button', async () => {
    await ft.setModuleInUi(page, 'closures_calendar', false);
    await expect(page.locator('#feature_closures_calendar')).not.toBeChecked();
    await expect(page.locator('#open-closures-calendar')).toHaveCount(0);
    await expect(page.locator('a.nav-link[href$="/admin/calendar"]')).toHaveCount(0);
    await page.goto(`${ft.BASE}/admin/calendar`);
    await expect(page).toHaveURL(/\/admin\/dashboard/);
  });

  test('3. switched off: the request calendar has no closed day', async () => {
    expect(await closedDates()).not.toContain(closed);
  });

  test('4. switched off: a closure posted to the calendar is refused', async () => {
    const csrf = await ft.csrfToken(page);
    const res = await page.request.post(`${ft.BASE}/admin/calendar/closures`, {
      form: { csrf_token: csrf, data_inizio: closed, data_fine: closed, descrizione: `${NOTE} refused` }, maxRedirects: 0,
    });
    expect(res.status()).toBe(302);
    expect(ft.dbQuery(`SELECT COUNT(*) FROM calendario_chiusure WHERE descrizione=${ft.sqlString(NOTE + ' refused')}`)).toBe('0');
  });

  test('5. switched off: no holiday card, and the holiday and yearly-closure forms are refused', async () => {
    const holidayRows = () => ft.dbQuery('SELECT COUNT(*) FROM calendario_chiusure WHERE regola_festivita IS NOT NULL');
    const holidayChoice = () => ft.dbQuery("SELECT COALESCE(GROUP_CONCAT(CONCAT(setting_key, '=', setting_value) ORDER BY setting_key), '') FROM system_settings WHERE category = 'calendar' AND setting_key LIKE 'holiday\\_%'");
    const rowsBefore = holidayRows();
    const choiceBefore = holidayChoice();
    await page.goto(`${ft.BASE}/admin/calendar#calendar-preset`);
    await expect(page).toHaveURL(/\/admin\/dashboard/);
    await expect(page.locator('#calendar-preset')).toHaveCount(0);
    await expect(page.locator('#calendar-yearly')).toHaveCount(0);
    const csrf = await ft.csrfToken(page);
    const post = (path, body) => page.request.post(`${ft.BASE}${path}`, {
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, data: new URLSearchParams(body).toString(), maxRedirects: 0,
    });
    const preset = await post('/admin/calendar/preset', [['csrf_token', csrf], ['country', 'IT'], ['region', ''], ['rules[]', 'IT:easter_monday'], ['rules[]', 'IT:christmas']]);
    expect(preset.status()).toBe(302);
    expect(preset.headers()['location'] || '').toMatch(/\/admin\/dashboard/);
    expect(holidayRows()).toBe(rowsBefore);
    expect(holidayChoice()).toBe(choiceBefore);
    // The yearly closure is listed and not ticked: on, it would be switched off.
    const yearly = await post('/admin/calendar/yearly', [['csrf_token', csrf], ['listed[]', String(yearlyId)]]);
    expect(yearly.status()).toBe(302);
    expect(yearly.headers()['location'] || '').toMatch(/\/admin\/dashboard/);
    expect(ft.dbQuery(`SELECT attiva FROM calendario_chiusure WHERE id=${yearlyId}`)).toBe('1');
  });

  test('6. switched back on: entry, page and the closure stored before are back', async () => {
    await ft.setModuleInUi(page, 'closures_calendar', true);
    await expect(page.locator('#open-closures-calendar')).toBeVisible();
    await page.locator('#open-closures-calendar').click();
    await expect(page).toHaveURL(/\/admin\/calendar/);
    await expect(page.locator('body')).toContainText(NOTE);
    await expect(page.locator('#calendar-preset')).toBeVisible();
    await expect(page.locator(`#yearly_closure_${yearlyId}`)).toBeChecked();
    expect(await closedDates()).toContain(closed);
  });
});
