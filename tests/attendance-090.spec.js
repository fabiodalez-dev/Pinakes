// @ts-check
// Visitor log (0.9.0): member card scan, guest form, statistics by day and
// hour slot, CSV export, GDPR retention setting and purge (manual and cron).
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const h = require('./helpers/minor-features-090');

test.skip(!h.configured, 'E2E credentials not configured');

const STAMP = Date.now().toString(36);
const CARD = `E2EP${STAMP}`.slice(0, 20);
const REASON = `E2E studio ${STAMP}`;

test.describe.serial('Visitor log (presenze)', () => {
  let context;
  let page;
  let userId = 0;
  let previousRetention = null;

  const visits = (where) => parseInt(h.dbQuery(`SELECT COUNT(*) FROM presenze WHERE ${where}`), 10);

  test.beforeAll(async ({ browser }) => {
    h.dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente) VALUES (${h.sqlString(CARD)}, 'Mario', 'Rossini', ${h.sqlString(`presenze-${STAMP}@example.test`)}, 'x', 'attivo', 'standard')`);
    userId = parseInt(h.dbQuery(`SELECT id FROM utenti WHERE codice_tessera = ${h.sqlString(CARD)}`), 10);
    const prev = h.dbQuery("SELECT setting_value FROM system_settings WHERE category = 'attendance' AND setting_key = 'retention_days'");
    previousRetention = prev === '' ? null : prev;
    context = await browser.newContext();
    page = await context.newPage();
    await h.loginAsAdmin(page);
  });

  test.afterAll(async () => {
    try { h.dbQuery(`DELETE FROM presenze WHERE utente_id = ${userId} OR motivo LIKE 'E2E %${STAMP}'`); } catch (_) { /* cleanup */ }
    try { h.dbQuery(`DELETE FROM utenti WHERE id = ${userId}`); } catch (_) { /* cleanup */ }
    try {
      h.dbQuery(previousRetention === null
        ? "DELETE FROM system_settings WHERE category = 'attendance' AND setting_key = 'retention_days'"
        : `UPDATE system_settings SET setting_value = ${h.sqlString(previousRetention)} WHERE category = 'attendance' AND setting_key = 'retention_days'`);
    } catch (_) { /* cleanup */ }
    await context?.close();
  });

  test('1. Scanning a member card records the visit and greets the member', async () => {
    await page.goto(`${h.BASE}/admin/attendance`);
    await expect(page.locator('#codice_tessera')).toBeFocused();
    // A card reader types the code and sends Enter.
    await page.locator('#codice_tessera').fill(CARD);
    await Promise.all([page.waitForURL(/\/admin\/attendance$/), page.locator('#codice_tessera').press('Enter')]);
    await expect(page.getByText('Benvenuto/a, Mario R.: presenza registrata.')).toBeVisible();
    expect(visits(`utente_id = ${userId} AND tipo = 'socio'`)).toBe(1);
    // The kiosk feed shows the shortened name only.
    await expect(page.locator('#attendance-recent')).toContainText('Mario R.');
    await expect(page.locator('#attendance-recent')).not.toContainText('Rossini');
  });

  test('2. An unknown card is refused and nothing is recorded', async () => {
    const before = visits('1=1');
    await page.fill('#codice_tessera', `NOPE${STAMP}`.slice(0, 20));
    await Promise.all([page.waitForURL(/\/admin\/attendance$/), page.click('#attendance-scan-form button[type="submit"]')]);
    await expect(page.getByText('Tessera non riconosciuta. Controlla il codice o registra la visita come ospite.')).toBeVisible();
    expect(visits('1=1')).toBe(before);
  });

  test('3. A guest is recorded with the optional reason', async () => {
    await page.fill('#motivo', REASON);
    await Promise.all([page.waitForURL(/\/admin\/attendance$/), page.click('#attendance-guest-form button[type="submit"]')]);
    await expect(page.getByText("Presenza dell'ospite registrata.")).toBeVisible();
    expect(h.dbQuery(`SELECT CONCAT(tipo, '|', IFNULL(utente_id, 'NULL'), '|', IFNULL(nome_ospite, 'NULL')) FROM presenze WHERE motivo = ${h.sqlString(REASON)}`)).toBe('ospite|NULL|NULL');
  });

  test('4. Statistics count the day and the hour slot, and export as CSV', async () => {
    // Expected values come from the database (Europe/Rome via the app), never the runner clock.
    const day = h.dbQuery(`SELECT DATE(created_at) FROM presenze WHERE utente_id = ${userId} LIMIT 1`);
    const hour = parseInt(h.dbQuery(`SELECT HOUR(created_at) FROM presenze WHERE utente_id = ${userId} LIMIT 1`), 10);
    const dayTotal = h.dbQuery(`SELECT CONCAT(SUM(tipo='socio'), '|', SUM(tipo='ospite'), '|', COUNT(*)) FROM presenze WHERE DATE(created_at) = '${day}'`);
    const hourTotal = h.dbQuery(`SELECT COUNT(*) FROM presenze WHERE DATE(created_at) >= '${day}' - INTERVAL 29 DAY AND HOUR(created_at) = ${hour}`);

    await page.goto(`${h.BASE}/admin/attendance`);
    const row = page.locator(`#attendance-daily tr[data-day="${day}"]`);
    const [soci, ospiti, totale] = dayTotal.split('|');
    await expect(row.locator('[data-col="soci"]')).toHaveText(soci);
    await expect(row.locator('[data-col="ospiti"]')).toHaveText(ospiti);
    await expect(row.locator('[data-col="totale"]')).toHaveText(totale);
    await expect(page.locator(`#attendance-hourly tr[data-hour="${hour}"] [data-hour-count]`)).toHaveText(hourTotal);
    await expect(page.locator('#attendance-today')).toHaveText(totale);

    await page.getByRole('link', { name: '7 giorni' }).click();
    await expect(page).toHaveURL(/days=7/);
    await expect(page.locator(`#attendance-daily tr[data-day="${day}"] [data-col="totale"]`)).toHaveText(totale);

    const csv = await page.request.get(`${h.BASE}/admin/attendance/export.csv?days=7`);
    expect(csv.headers()['content-type']).toContain('text/csv');
    const lines = (await csv.text()).replace(/^﻿/, '').trim().split(/\r?\n/);
    expect(lines[0]).toBe('Data;Soci;Ospiti;Totale');
    expect(lines).toHaveLength(8);
    expect(lines).toContain(`${day};${soci};${ospiti};${totale}`);
  });

  test('5. The admin sets the retention and the GDPR note follows it', async () => {
    await page.goto(`${h.BASE}/admin/attendance`);
    await page.fill('#retention_days', '30');
    await Promise.all([page.waitForURL(/\/admin\/attendance$/), page.click('#attendance-retention-form button[type="submit"]')]);
    await expect(page.getByText('Conservazione aggiornata: le presenze più vecchie di 30 giorni verranno cancellate.')).toBeVisible();
    expect(h.dbQuery("SELECT setting_value FROM system_settings WHERE category = 'attendance' AND setting_key = 'retention_days'")).toBe('30');
    await expect(page.locator('#retention_days')).toHaveValue('30');
    await expect(page.locator('#attendance-gdpr-note')).toContainText('conservate per 30 giorni');
  });

  test('6. "Apply now" purges only the visits older than the retention', async () => {
    h.dbQuery(`INSERT INTO presenze (tipo, motivo, created_at) VALUES ('ospite', 'E2E old ${STAMP}', NOW() - INTERVAL 400 DAY), ('ospite', 'E2E recent ${STAMP}', NOW() - INTERVAL 10 DAY)`);
    await page.goto(`${h.BASE}/admin/attendance`);
    await Promise.all([page.waitForURL(/\/admin\/attendance$/), page.click('#attendance-purge-form button[type="submit"]')]);
    await expect(page.getByText(/Presenze cancellate: [1-9]\d*\./)).toBeVisible();
    expect(visits(`motivo = 'E2E old ${STAMP}'`)).toBe(0);
    expect(visits(`motivo = 'E2E recent ${STAMP}'`)).toBe(1);
    expect(visits(`utente_id = ${userId}`)).toBe(1);
  });

  test('7. The scheduled maintenance applies the same retention', async () => {
    test.setTimeout(180000);
    await page.goto(`${h.BASE}/admin/attendance`);
    await page.fill('#retention_days', '5');
    await Promise.all([page.waitForURL(/\/admin\/attendance$/), page.click('#attendance-retention-form button[type="submit"]')]);
    expect(visits(`motivo = 'E2E recent ${STAMP}'`)).toBe(1);

    const out = execFileSync('php', [path.join(__dirname, '..', 'cron', 'full-maintenance.php')], { encoding: 'utf-8', timeout: 150000 });
    expect(out).toMatch(/Visitor log rows purged \(retention\): [1-9]\d*/);
    expect(visits(`motivo = 'E2E recent ${STAMP}'`)).toBe(0);
    expect(visits(`utente_id = ${userId}`)).toBe(1);
  });
});
