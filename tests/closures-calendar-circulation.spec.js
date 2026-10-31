// @ts-check
// Closures calendar on top of the circulation policy, as a librarian and a
// reader see it: the loan length and the renewal days of the reader's PATRON
// TYPE (not the global settings) are what lands on a closed day, and both the
// admin desk and the reader's request calendar move that due date forward to
// the next open day; a renewal under the type's renewal rules does the same.
// Patron types, closures and loans are set up in the DB; every action under
// test is a click in the UI. Dates come from the application clock.
//
// Run: /tmp/run-e2e.sh tests/closures-calendar-circulation.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const F = require('./helpers/circulation-fixtures');

test.skip(!F.CONFIGURED, 'E2E credentials not configured');

const RUN = String(Date.now()).slice(-6);
const PREFIX = `e2ecal${RUN}`;
const TAG = `E2E-CALCIRC-${RUN}`;
const TYPE_DAYS = 10;
const RENEW_DAYS = 7;

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
function closeDay(from, to = from) {
  F.dbQuery(`INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione) VALUES ('${from}', '${to}', '${TAG}')`);
}
function setWeekdays(value) {
  F.dbQuery(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans','closed_weekdays','${value}') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
}

test.describe.configure({ mode: 'serial' });

test.describe('Closures calendar — patron-type durations and renewals', () => {
  let today = '';
  let prevWeekdays = '';
  let typeId = 0;
  /** @type {{id:number,email:string,password:string,surname:string}} */
  let patron;
  /** @type {Record<string, {id:number,title:string,copyIds:number[]}>} */
  const books = {};
  const errors = [];

  test.beforeAll(() => {
    today = F.appTodayISO();
    prevWeekdays = F.dbQuery("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'");
    setWeekdays('');
    // A patron type whose loan lasts 10 days (request window 10 too, so the
    // maximum itself is what lands on the closure) and whose renewal adds 7.
    F.dbQuery(`INSERT INTO tipologie_lettore (codice, nome, durata_prestito_giorni, max_durata_richiesta_giorni, giorni_rinnovo, max_rinnovi, ordinamento)
               VALUES ('${PREFIX}', 'Cal ${RUN}', ${TYPE_DAYS}, ${TYPE_DAYS}, ${RENEW_DAYS}, 3, 90)`);
    typeId = F.dbInt(`SELECT id FROM tipologie_lettore WHERE codice='${PREFIX}'`);
    patron = F.createPatron(`CALC${RUN}`, { typeId });
    for (const key of ['desk', 'deskweekly', 'deskzero', 'request', 'requestend', 'renew', 'renewweekly']) {
      books[key] = F.createTitle(`${PREFIX}-${key}`);
    }
  });

  test.afterAll(() => {
    F.dbQuery(`DELETE FROM calendario_chiusure WHERE descrizione='${TAG}'`);
    if (prevWeekdays === '') F.dbQuery("DELETE FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'");
    else setWeekdays(prevWeekdays);
    const ids = Object.values(books).map((b) => b.id);
    if (ids.length) F.dbQuery(`DELETE FROM admin_notifications WHERE related_id IN (SELECT id FROM prestiti WHERE libro_id IN (${ids.join(',')}))`);
    F.deleteTitles(ids);
    F.deletePatrons([patron?.id]);
    F.deleteTypes(PREFIX);
  });

  test.beforeEach(({ page }) => {
    page.on('response', (r) => { if (r.status() >= 500) errors.push(`${r.status()} ${r.url()}`); });
  });
  test.afterEach(() => {
    F.dbQuery(`DELETE FROM calendario_chiusure WHERE descrizione='${TAG}'`);
    setWeekdays('');
    expect(errors.splice(0), 'no HTTP 5xx').toEqual([]);
  });

  // ── Admin desk ─────────────────────────────────────────────────────────
  /** Choose the borrower and the copy; resolves once the type's duration has been applied. */
  async function fillDesk(page, book) {
    await page.goto(`${F.BASE}/admin/loans/create`);
    await F.fillAutocomplete(page, '#utente_search', '#utente_suggest', patron.surname, '/api/search/utenti');
    await expect(page.locator('#utente_id')).toHaveValue(String(patron.id));
    const availability = page.waitForResponse((r) => r.url().includes('/availability') && r.url().includes(`for_user=${patron.id}`), { timeout: 15000 });
    const resolved = page.waitForResponse((r) => r.url().includes('/admin/copies/by-code'));
    await page.locator('#copy_code').fill(F.copyCodeOf(book.copyIds[0]));
    await page.locator('#copy_code').dispatchEvent('change');
    await resolved;
    await expect(page.locator('#libro_id')).toHaveValue(String(book.id));
    const data = await (await availability).json();
    expect(Number(data.availability.staff_loan_duration_days)).toBe(TYPE_DAYS);
  }
  async function submitDesk(page) {
    await page.evaluate(() => document.querySelectorAll('.flatpickr-calendar.open').forEach((c) => c.classList.remove('open')));
    await page.uncheck('#scarica_pdf').catch(() => {});
    await Promise.all([
      page.waitForURL((u) => u.toString().includes('created=1') || u.toString().includes('error='), { timeout: 20000 }),
      page.locator('form[action$="/admin/loans/create"] button[type="submit"]:not([name="save_and_new"])').click(),
    ]);
    expect(page.url()).toContain('created=1');
  }
  const loanOf = (book) => F.dbQuery(`SELECT data_scadenza FROM prestiti WHERE libro_id=${book.id} AND utente_id=${patron.id} ORDER BY id DESC LIMIT 1`);

  test('Desk 1: the 10-day loan of the patron type ending on a closure is proposed and stored on the next open day', async ({ page }) => {
    const raw = addDays(today, TYPE_DAYS);
    closeDay(raw);
    await F.loginAsAdmin(page);
    await fillDesk(page, books.desk);
    await expect(page.locator('#data_scadenza')).toHaveValue(addDays(raw, 1));
    await submitDesk(page);
    expect(loanOf(books.desk)).toBe(addDays(raw, 1));
  });

  test('Desk 2: a two-day closure on the type due date moves it past both days', async ({ page }) => {
    const raw = addDays(today, TYPE_DAYS);
    closeDay(raw, addDays(raw, 1));
    await F.loginAsAdmin(page);
    await fillDesk(page, books.deskweekly);
    await expect(page.locator('#data_scadenza')).toHaveValue(addDays(raw, 2));
    await submitDesk(page);
    expect(loanOf(books.deskweekly)).toBe(addDays(raw, 2));
  });

  test('Desk 3: without closures the patron type duration is applied exactly', async ({ page }) => {
    await F.loginAsAdmin(page);
    await fillDesk(page, books.deskzero);
    await expect(page.locator('#data_scadenza')).toHaveValue(addDays(today, TYPE_DAYS));
    await submitDesk(page);
    expect(loanOf(books.deskzero)).toBe(addDays(today, TYPE_DAYS));
  });

  // ── Reader's request ───────────────────────────────────────────────────
  async function openRequest(page, book, start) {
    await page.goto(`${F.BASE}/libro/${book.id}`);
    await page.locator('#btn-request-loan').click();
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await page.waitForFunction(() => { const el = document.querySelector('#swal-date-start'); return el && /** @type {any} */ (el)._flatpickr; }, null, { timeout: 8000 });
    await page.evaluate((iso) => { /** @type {any} */ (document.querySelector('#swal-date-start'))._flatpickr.setDate(iso, true); }, start);
  }

  test('Request 1: the reader calendar proposes the type return date moved past a closure', async ({ page }) => {
    const start = addDays(today, 1);
    const raw = addDays(start, TYPE_DAYS);
    closeDay(raw);
    await F.loginAs(page, patron.email, patron.password);
    await openRequest(page, books.request, start);
    await expect(page.locator('#swal-date-end')).toHaveValue(addDays(raw, 1));
    // The note under the field stays empty: no conflict caps this loan.
    await expect(page.locator('#swal-loan-limit')).toBeHidden();
  });

  test('Request 2: confirming that proposal stores the open day, accepted although it passes the type maximum', async ({ page }) => {
    const start = addDays(today, 1);
    const raw = addDays(start, TYPE_DAYS);
    closeDay(raw);
    await F.loginAs(page, patron.email, patron.password);
    await openRequest(page, books.requestend, start);
    await expect(page.locator('#swal-date-end')).toHaveValue(addDays(raw, 1));
    await page.locator('.swal2-confirm').click();
    await page.waitForFunction(() => !!document.querySelector('.swal2-icon-success, .swal2-icon-error'), null, { timeout: 15000 });
    await expect(page.locator('.swal2-icon-success')).toBeVisible();
    expect(F.dbQuery(`SELECT CONCAT(data_prestito,'|',data_scadenza) FROM prestiti WHERE libro_id=${books.requestend.id} AND utente_id=${patron.id} ORDER BY id DESC LIMIT 1`))
      .toBe(`${start}|${addDays(raw, 1)}`);
  });

  // ── Renewal under the type's renewal rules ─────────────────────────────
  function activeLoan(book, due) {
    const copy = book.copyIds[0];
    F.dbQuery(`UPDATE copie SET stato='prestato' WHERE id=${copy}`);
    F.dbQuery(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo, renewals) VALUES (${book.id}, ${copy}, ${patron.id}, '${today}', '${due}', 'in_corso', 1, 0)`);
    F.dbQuery(`UPDATE libri SET copie_disponibili=0 WHERE id=${book.id}`);
    return F.dbInt(`SELECT id FROM prestiti WHERE libro_id=${book.id} AND utente_id=${patron.id} AND attivo=1`);
  }
  async function renewFromBookPage(page, book, loanId) {
    await page.goto(`${F.BASE}/admin/books/${book.id}`);
    const form = page.locator(`form[action*="/admin/loans/renew/${loanId}"]`).last();
    await form.waitFor({ state: 'attached', timeout: 10000 });
    await form.locator('button[type="submit"]').click();
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await Promise.all([
      page.waitForURL(/\/admin\/books\/\d+/, { timeout: 15000 }),
      page.locator('.swal2-confirm').click(),
    ]);
  }
  const renewalState = (loanId) => F.dbQuery(`SELECT CONCAT(data_scadenza,'|',renewals) FROM prestiti WHERE id=${loanId}`);

  test('Renew 1: the 7 renewal days of the type ending on a closure land on the next open day', async ({ page }) => {
    const due = addDays(today, 5);
    const loanId = activeLoan(books.renew, due);
    const raw = addDays(due, RENEW_DAYS);
    closeDay(raw);
    await F.loginAsAdmin(page);
    await renewFromBookPage(page, books.renew, loanId);
    await expect.poll(() => renewalState(loanId), { timeout: 8000 }).toBe(`${addDays(raw, 1)}|1`);
  });

  test('Renew 2: a second renewal of the type from the moved date skips a weekly closed day', async ({ page }) => {
    const loanId = F.dbInt(`SELECT id FROM prestiti WHERE libro_id=${books.renew.id} AND utente_id=${patron.id} AND attivo=1`);
    const due = F.dbQuery(`SELECT data_scadenza FROM prestiti WHERE id=${loanId}`);
    const raw = addDays(due, RENEW_DAYS);
    setWeekdays(String(weekday(raw)));
    await F.loginAsAdmin(page);
    await renewFromBookPage(page, books.renew, loanId);
    await expect.poll(() => renewalState(loanId), { timeout: 8000 }).toBe(`${addDays(raw, 1)}|2`);
  });

  test('Renew 3: without closures the renewal adds exactly the type renewal days', async ({ page }) => {
    const due = addDays(today, 4);
    const loanId = activeLoan(books.renewweekly, due);
    await F.loginAsAdmin(page);
    await renewFromBookPage(page, books.renewweekly, loanId);
    await expect.poll(() => renewalState(loanId), { timeout: 8000 }).toBe(`${addDays(due, RENEW_DAYS)}|1`);
  });
});
