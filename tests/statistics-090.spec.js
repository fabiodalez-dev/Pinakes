// @ts-check
// /admin/statistics in 0.9.0: loans by material, visitors from the visitor
// log, and the extension sections (patron types, fines, stocktake) hidden
// until another feature provides them.
const { test, expect } = require('@playwright/test');
const h = require('./helpers/minor-features-090');

test.skip(!h.configured, 'E2E credentials not configured');

const STAMP = Date.now().toString(36);
const CARD = `E2ES${STAMP}`.slice(0, 20);
const DVD_TITLE = `E2E Statistiche DVD ${STAMP}`;

test.describe.serial('Statistics 0.9.0 sections', () => {
  let context;
  let page;
  let userId = 0;
  let dvdId = 0;

  const mediaCount = (media) => parseInt(h.dbQuery(
    `SELECT COUNT(*) FROM prestiti p JOIN libri l ON l.id = p.libro_id AND l.deleted_at IS NULL
     WHERE COALESCE(NULLIF(l.tipo_media, ''), 'libro') = '${media}'
       AND p.stato IN ('in_corso','in_ritardo','restituito','perso','danneggiato')
       AND p.data_prestito >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)`), 10);
  const shownCount = (media) => page.locator(`#stats-loans-by-media tr[data-media="${media}"] [data-count]`);

  test.beforeAll(async ({ browser }) => {
    h.dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente) VALUES (${h.sqlString(CARD)}, 'Lia', 'Statistica', ${h.sqlString(`stats-${STAMP}@example.test`)}, 'x', 'attivo', 'standard')`);
    userId = parseInt(h.dbQuery(`SELECT id FROM utenti WHERE codice_tessera = ${h.sqlString(CARD)}`), 10);
    dvdId = h.seedBook({ titolo: DVD_TITLE, tipo_media: 'dvd' });
    context = await browser.newContext();
    page = await context.newPage();
    await h.loginAsAdmin(page);
  });

  test.afterAll(async () => {
    try { h.dbQuery(`DELETE FROM prestiti WHERE libro_id = ${dvdId}`); } catch (_) { /* cleanup */ }
    try { h.dbQuery(`DELETE FROM presenze WHERE utente_id = ${userId}`); } catch (_) { /* cleanup */ }
    h.deleteBooks([dvdId]);
    try { h.dbQuery(`DELETE FROM utenti WHERE id = ${userId}`); } catch (_) { /* cleanup */ }
    await context?.close();
  });

  test('1. Loans by material list every media type with the database counts', async () => {
    await page.goto(`${h.BASE}/admin/statistics`);
    await expect(page.locator('#stats-loans-by-media')).toBeVisible();
    for (const media of ['libro', 'disco', 'audiolibro', 'dvd', 'altro']) {
      await expect(shownCount(media)).toHaveText(String(mediaCount(media)));
    }
    // The existing sections are still there.
    await expect(page.locator('#loansPerMonthChart')).toBeAttached();
    await expect(page.getByText('Top 10 Libri Più Prestati')).toBeVisible();
  });

  test('2. A returned DVD loan is counted under DVD', async () => {
    const before = mediaCount('dvd');
    h.dbQuery(`INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, data_restituzione, stato, attivo, origine)
               VALUES (${dvdId}, ${userId}, CURDATE() - INTERVAL 20 DAY, CURDATE() - INTERVAL 5 DAY, CURDATE() - INTERVAL 6 DAY, 'restituito', 0, 'diretto')`);
    expect(mediaCount('dvd')).toBe(before + 1);
    await page.goto(`${h.BASE}/admin/statistics`);
    await expect(shownCount('dvd')).toHaveText(String(before + 1));
  });

  test('3. Loans of a deleted record are not counted', async () => {
    const before = mediaCount('dvd');
    h.dbQuery(`UPDATE libri SET deleted_at = NOW() WHERE id = ${dvdId}`);
    await page.goto(`${h.BASE}/admin/statistics`);
    await expect(shownCount('dvd')).toHaveText(String(before - 1));
    h.dbQuery(`UPDATE libri SET deleted_at = NULL WHERE id = ${dvdId}`);
  });

  test('4. A visit recorded at the kiosk shows up among the visitors', async () => {
    await page.goto(`${h.BASE}/admin/statistics`);
    const read = async (key) => parseInt((await page.locator(`#stats-visitors [data-visitors="${key}"]`).innerText()).replace(/\D/g, ''), 10);
    const totale = await read('totale');
    const soci = await read('soci');

    await page.goto(`${h.BASE}/admin/attendance`);
    await page.fill('#codice_tessera', CARD);
    await Promise.all([page.waitForURL(/\/admin\/attendance$/), page.press('#codice_tessera', 'Enter')]);
    await expect(page.getByText('Benvenuto/a, Lia S.: presenza registrata.')).toBeVisible();

    await page.goto(`${h.BASE}/admin/statistics`);
    expect(await read('totale')).toBe(totale + 1);
    expect(await read('soci')).toBe(soci + 1);
    await page.locator('#stats-visitors').getByRole('link', { name: 'Dettaglio per giorno e fascia oraria' }).click();
    await expect(page).toHaveURL(/\/admin\/attendance$/);
  });

  test('5. Patron-type, fines and stocktake sections stay hidden until a feature provides them', async () => {
    await page.goto(`${h.BASE}/admin/statistics`);
    await expect(page.locator('#stats-loans-by-media')).toBeVisible();
    for (const id of ['patron-types', 'fines', 'stocktake']) {
      await expect(page.locator(`#stats-${id}`)).toHaveCount(0);
    }
  });
});
