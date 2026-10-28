// @ts-check
// /admin/statistics in 0.9.0: loans by material, visitors from the visitor
// log, the outcomes of the last closed stocktake, loans by patron type
// (readers without a type count under the default type), and the fines
// section from the patron ledger (hidden with no fine and no movement).
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
    h.dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, telefono, indirizzo, stato, tipo_utente) VALUES (${h.sqlString(CARD)}, 'Lia', 'Statistica', ${h.sqlString(`stats-${STAMP}@example.test`)}, 'x', '0000000', 'Via E2E 1', 'attivo', 'standard')`);
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

  // Same rendering as FineParameters::format() for it_IT / EUR.
  const euro = (cents) => {
    const abs = Math.abs(cents);
    const units = Math.floor(abs / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return `${cents < 0 ? '-' : ''}${units},${String(abs % 100).padStart(2, '0')} €`;
  };
  const cents = (sql) => Math.round(Number(h.dbQuery(sql) || '0') * 100);
  const fineValue = (label) => page.locator('#stats-fines tr', { hasText: label }).locator('td').last();

  test('5. The fines section is hidden with no fine configured and an empty ledger', async () => {
    const ledgerRows = parseInt(h.dbQuery('SELECT COUNT(*) FROM movimenti_lettore'), 10);
    const configured = parseInt(h.dbQuery(`SELECT
        (SELECT COUNT(*) FROM system_settings WHERE category = 'loans' AND setting_key = 'daily_fine' AND setting_value REGEXP '^[0-9]' AND CAST(REPLACE(setting_value, ',', '.') AS DECIMAL(10,2)) > 0)
      + (SELECT COUNT(*) FROM tipologie_lettore WHERE multa_giornaliera > 0)
      + (SELECT COUNT(*) FROM regole_prestito WHERE multa_giornaliera > 0)`), 10);
    await page.goto(`${h.BASE}/admin/statistics`);
    await expect(page.locator('#stats-loans-by-media')).toBeVisible();
    await expect(page.locator('#stats-patron-types')).toBeVisible();
    await expect(page.locator('#stats-fines')).toHaveCount(ledgerRows === 0 && configured === 0 ? 0 : 1);
  });

  test('5b. With ledger movements the fines section reports charged, collected and what readers owe', async () => {
    // A late fine of 3.50 and a payment of 1.00 for the test reader (ledger setup only).
    h.dbQuery(`INSERT INTO movimenti_lettore (utente_id, tipo, importo, descrizione) VALUES (${userId}, 'multa_ritardo', 3.50, 'E2E statistiche'), (${userId}, 'pagamento', -1.00, 'E2E statistiche')`);
    try {
      const period = "created_at >= DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 12 MONTH), INTERVAL 1 DAY)";
      const charged = cents(`SELECT COALESCE(SUM(importo), 0) FROM movimenti_lettore WHERE tipo IN ('multa_ritardo','addebito_perdita','addebito_danno','addebito_manuale') AND ${period}`);
      const collected = cents(`SELECT COALESCE(-SUM(importo), 0) FROM movimenti_lettore WHERE tipo = 'pagamento' AND ${period}`);
      const owed = cents('SELECT COALESCE(SUM(s), 0) FROM (SELECT SUM(importo) AS s FROM movimenti_lettore GROUP BY utente_id HAVING SUM(importo) > 0) d');
      const debtors = h.dbQuery('SELECT COUNT(*) FROM (SELECT utente_id FROM movimenti_lettore GROUP BY utente_id HAVING SUM(importo) > 0) d');
      expect(charged).toBeGreaterThanOrEqual(350);
      await page.goto(`${h.BASE}/admin/statistics`);
      await expect(page.locator('#stats-fines')).toBeVisible();
      await expect(fineValue('Addebitato')).toHaveText(euro(charged));
      await expect(fineValue('Incassato')).toHaveText(euro(collected));
      await expect(fineValue('Saldo da incassare')).toHaveText(euro(owed));
      await expect(fineValue('Lettori con saldo a debito')).toHaveText(debtors);
    } finally {
      h.dbQuery(`DELETE FROM movimenti_lettore WHERE utente_id = ${userId}`);
    }
  });

  test('6. Loans by patron type: a typed reader counts under the type, an untyped one under the default type', async () => {
    const typeCode = `e2est${STAMP}`.slice(0, 40);
    const typeName = `E2E Tipologia statistiche ${STAMP}`;
    const typeCount = (sqlTypeCond) => parseInt(h.dbQuery(
      `SELECT COUNT(*) FROM prestiti p JOIN libri l ON l.id = p.libro_id AND l.deleted_at IS NULL
       LEFT JOIN utenti u ON u.id = p.utente_id
       WHERE p.stato IN ('in_corso','in_ritardo','restituito','perso','danneggiato')
         AND p.data_prestito >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
         AND ${sqlTypeCond}`), 10);
    const defaultId = parseInt(h.dbQuery(`SELECT id FROM tipologie_lettore WHERE predefinita = 1 ORDER BY id LIMIT 1`), 10);
    const defaultName = h.dbQuery(`SELECT nome FROM tipologie_lettore WHERE id = ${defaultId}`);
    const defaultCount = () => typeCount(`COALESCE(u.tipologia_id, ${defaultId}) = ${defaultId}`);
    h.dbQuery(`INSERT INTO tipologie_lettore (codice, nome, attiva, ordinamento) VALUES (${h.sqlString(typeCode)}, ${h.sqlString(typeName)}, 1, 95)`);
    const typeId = parseInt(h.dbQuery(`SELECT id FROM tipologie_lettore WHERE codice = ${h.sqlString(typeCode)}`), 10);
    const section = page.locator('#stats-patron-types');
    const value = (label) => section.locator('tr', { has: page.locator('td', { hasText: new RegExp(`^${label}$`) }) }).locator('td').nth(1);
    try {
      // A new active type with no loans is listed with 0.
      await page.goto(`${h.BASE}/admin/statistics`);
      await expect(section.getByRole('heading', { name: 'Prestiti per tipologia di lettore' })).toBeVisible();
      await expect(value(typeName)).toHaveText('0');
      await expect(value(defaultName)).toHaveText(String(defaultCount()));
      await expect(section).toContainText(`I lettori senza tipologia sono contati in «${defaultName}»`);

      // The reader of the DVD loan (test 2) has no type: the loan is under the default type.
      expect(h.dbQuery(`SELECT COALESCE(tipologia_id, 'NULL') FROM utenti WHERE id = ${userId}`)).toBe('NULL');
      const defaultBefore = defaultCount();

      // The librarian gives the reader the new type from the edit form.
      await page.goto(`${h.BASE}/admin/users/edit/${userId}`);
      await page.locator('select[name="tipologia_id"]').selectOption(String(typeId));
      await page.locator('#user-form button[type="submit"]').click();
      await page.waitForURL((u) => !u.pathname.includes('/edit/'), { timeout: 20000 });
      expect(page.url()).not.toContain('error=');
      expect(h.dbQuery(`SELECT tipologia_id FROM utenti WHERE id = ${userId}`)).toBe(String(typeId));

      await page.goto(`${h.BASE}/admin/statistics`);
      const typed = typeCount(`u.tipologia_id = ${typeId}`);
      expect(typed).toBeGreaterThan(0);
      await expect(value(typeName)).toHaveText(String(typed));
      await expect(value(defaultName)).toHaveText(String(defaultBefore - typed));

      // A deactivated type with loans is still listed; without loans it disappears.
      h.dbQuery(`UPDATE tipologie_lettore SET attiva = 0 WHERE id = ${typeId}`);
      await page.goto(`${h.BASE}/admin/statistics`);
      await expect(value(typeName)).toHaveText(String(typed));
      h.dbQuery(`UPDATE utenti SET tipologia_id = NULL WHERE id = ${userId}`);
      await page.goto(`${h.BASE}/admin/statistics`);
      await expect(section.locator('td', { hasText: new RegExp(`^${typeName}$`) })).toHaveCount(0);
      await expect(value(defaultName)).toHaveText(String(defaultBefore));
    } finally {
      h.dbQuery(`UPDATE utenti SET tipologia_id = NULL WHERE id = ${userId}`);
      h.dbQuery(`DELETE FROM tipologie_lettore WHERE id = ${typeId}`);
    }
  });

  test('7. The last closed stocktake shows its outcomes', async () => {
    const name = `E2E Revisione statistiche ${STAMP}`;
    const bookId = h.seedBook({ titolo: `E2E Statistiche revisione ${STAMP}` });
    const copies = ['A', 'B', 'C'].map((suffix) => {
      const code = `E2ESR${STAMP}${suffix}`.slice(0, 40);
      h.dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${bookId}, ${h.sqlString(code)}, 'disponibile')`);
      return { code, id: parseInt(h.dbQuery(`SELECT id FROM copie WHERE numero_inventario = ${h.sqlString(code)}`), 10) };
    });
    let sessionId = 0;
    try {
      // Setup: a session closed now, three expected copies, one found, one
      // misplaced, one never scanned, plus one unknown code.
      h.dbQuery(`INSERT INTO inventario_sessioni (nome, stato, totale_attese, aperta_il, chiusa_il) VALUES (${h.sqlString(name)}, 'chiusa', 3, NOW() - INTERVAL 1 HOUR, NOW())`);
      sessionId = parseInt(h.dbQuery(`SELECT MAX(id) FROM inventario_sessioni WHERE nome = ${h.sqlString(name)}`), 10);
      h.dbQuery(`INSERT INTO inventario_attese (sessione_id, copia_id, stato_all_apertura) VALUES ${copies.map((c) => `(${sessionId}, ${c.id}, 'disponibile')`).join(', ')}`);
      h.dbQuery(`INSERT INTO inventario_scansioni (sessione_id, copia_id, codice_letto, esito) VALUES
                 (${sessionId}, ${copies[0].id}, ${h.sqlString(copies[0].code)}, 'trovata'),
                 (${sessionId}, ${copies[1].id}, ${h.sqlString(copies[1].code)}, 'fuori_posto'),
                 (${sessionId}, NULL, ${h.sqlString(`E2EUNKNOWN${STAMP}`)}, 'sconosciuta')`);
      const closed = parseInt(h.dbQuery(`SELECT COUNT(*) FROM inventario_sessioni WHERE stato = 'chiusa'`), 10);

      await page.goto(`${h.BASE}/admin/statistics`);
      const section = page.locator('#stats-stocktake');
      await expect(section).toBeVisible();
      await expect(section.getByRole('heading', { name: 'Esiti delle revisioni inventariali' })).toBeVisible();
      const value = (label) => section.locator('tr', { has: page.locator('td', { hasText: new RegExp(`^${label}$`) }) }).locator('td').nth(1);
      await expect(value('Copie attese')).toHaveText('3');
      await expect(value('Trovata')).toHaveText('1');
      await expect(value('Fuori posto')).toHaveText('1');
      await expect(value('In prestito')).toHaveText('0');
      await expect(value('Mancanti')).toHaveText('1');
      await expect(value('Codici sconosciuti')).toHaveText('1');
      await expect(section).toContainText(`Ultima revisione chiusa: «${name}»`);
      await expect(section).toContainText(`Revisioni chiuse in totale: ${closed}.`);
    } finally {
      if (sessionId > 0) {
        try { h.dbQuery(`DELETE FROM inventario_sessioni WHERE id = ${sessionId}`); } catch (_) { /* cleanup */ }
      }
      h.deleteBooks([bookId]);
    }
  });
});
