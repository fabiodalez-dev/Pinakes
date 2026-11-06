// @ts-check
// Visitor log: the desk field also finds a member by name. Picking one records
// the entry for that member (one who forgot the card), and a card reader that
// types the code and sends Enter is never captured by the suggestion list.
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

const featureToggles = require('./helpers/feature-toggles');
let featureSnapshot;
test.beforeAll(async () => { featureSnapshot = await featureToggles.enableFeatures(['visitor_log']); });
test.afterAll(async () => { await featureToggles.restoreFeatures(featureSnapshot); });
const h = require('./helpers/minor-features-090');

test.skip(!h.configured, 'E2E credentials not configured');

const STAMP = Date.now().toString(36);
const CARD = `E2ES${STAMP}`.slice(0, 20);
const CARD_B = `E2ET${STAMP}`.slice(0, 20);
// A surname no other row has, so the search returns only these two members.
const SURNAME = `Presenzi${STAMP}`;

test.describe.serial('Visitor log: member search by name', () => {
  let context;
  let page;
  let withCard = 0;
  let bruno = 0;

  const visits = (userId) => parseInt(h.dbQuery(`SELECT COUNT(*) FROM presenze WHERE utente_id = ${userId} AND tipo = 'socio'`), 10);
  const suggestions = () => page.locator('#attendance-member-suggest .suggestion-item[data-id]');
  const submitAndWait = (action) => Promise.all([page.waitForURL(/\/admin\/attendance$/), action()]);

  test.beforeAll(async ({ browser }) => {
    h.dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente) VALUES (${h.sqlString(CARD)}, 'Anna', ${h.sqlString(SURNAME)}, ${h.sqlString(`ps-a-${STAMP}@example.test`)}, 'x', 'attivo', 'standard')`);
    withCard = parseInt(h.dbQuery(`SELECT id FROM utenti WHERE codice_tessera = ${h.sqlString(CARD)}`), 10);
    h.dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente) VALUES (${h.sqlString(CARD_B)}, 'Bruno', ${h.sqlString(SURNAME)}, ${h.sqlString(`ps-b-${STAMP}@example.test`)}, 'x', 'attivo', 'standard')`);
    bruno = parseInt(h.dbQuery(`SELECT id FROM utenti WHERE codice_tessera = ${h.sqlString(CARD_B)}`), 10);
    context = await browser.newContext();
    page = await context.newPage();
    await h.loginAsAdmin(page);
  });

  test.afterAll(async () => {
    try { h.dbQuery(`DELETE FROM presenze WHERE utente_id IN (${withCard}, ${bruno})`); } catch (_) { /* cleanup */ }
    try { h.dbQuery(`DELETE FROM utenti WHERE id IN (${withCard}, ${bruno})`); } catch (_) { /* cleanup */ }
    await context?.close();
  });

  test('1. Typing the surname lists the members; a click picks one and the entry is recorded for them', async () => {
    await page.goto(`${h.BASE}/admin/attendance`);
    await page.locator('#codice_tessera').pressSequentially(SURNAME.slice(0, 12));
    await expect(suggestions()).toHaveCount(2);
    await expect(suggestions().filter({ hasText: 'Anna' })).toContainText(`Tessera: ${CARD}`);
    await suggestions().filter({ hasText: 'Anna' }).click();
    await expect(page.locator('#attendance-member-suggest')).toBeHidden();
    await expect(page.locator('#attendance-member-id')).toHaveValue(String(withCard));
    // The focus stays in the field, so the next swipe cannot confirm this pick.
    await expect(page.locator('#codice_tessera')).toBeFocused();
    await submitAndWait(() => page.click('#attendance-scan-form button[type="submit"]'));
    await expect(page.getByText(/Benvenuto\/a, Anna P\.: presenza registrata\./)).toBeVisible();
    expect(visits(withCard)).toBe(1);
    expect(visits(bruno)).toBe(0);
  });

  test('2. A member is picked with the arrows and Enter, and recorded', async () => {
    await page.goto(`${h.BASE}/admin/attendance`);
    await page.locator('#codice_tessera').pressSequentially(`Bruno ${SURNAME}`);
    await expect(suggestions()).toHaveCount(1);
    await page.locator('#codice_tessera').press('ArrowDown');
    await expect(suggestions().first()).toHaveClass(/selected/);
    // The first Enter picks the highlighted member and does not submit.
    await page.locator('#codice_tessera').press('Enter');
    await expect(page.locator('#attendance-member-id')).toHaveValue(String(bruno));
    expect(visits(bruno)).toBe(0);
    await submitAndWait(() => page.keyboard.press('Enter'));
    await expect(page.getByText(/Benvenuto\/a, Bruno P\.: presenza registrata\./)).toBeVisible();
    expect(visits(bruno)).toBe(1);
  });

  test('3. A card typed by a reader is sent with Enter even while the list is open', async () => {
    await page.goto(`${h.BASE}/admin/attendance`);
    // The code also matches the search, so the list opens on it; nothing is
    // highlighted, so Enter sends the code as before.
    await page.locator('#codice_tessera').pressSequentially(CARD);
    await expect(suggestions()).toHaveCount(1);
    await submitAndWait(() => page.locator('#codice_tessera').press('Enter'));
    await expect(page.getByText(/Benvenuto\/a, Anna P\.: presenza registrata\./)).toBeVisible();
    expect(visits(withCard)).toBe(2);
  });

  test('4. Editing the field after a pick drops the picked member', async () => {
    await page.goto(`${h.BASE}/admin/attendance`);
    await page.locator('#codice_tessera').pressSequentially(`Bruno ${SURNAME}`);
    await suggestions().first().click();
    await expect(page.locator('#attendance-member-id')).toHaveValue(String(bruno));
    await page.locator('#codice_tessera').fill(`NOPE${STAMP}`.slice(0, 20));
    await expect(page.locator('#attendance-member-id')).toHaveValue('');
    await submitAndWait(() => page.click('#attendance-scan-form button[type="submit"]'));
    await expect(page.getByText('Tessera non riconosciuta. Controlla il codice, scegli il socio dai suggerimenti o registra la visita come ospite.')).toBeVisible();
    expect(visits(bruno)).toBe(1);
  });

  test('5. A picked id that matches no member is refused and nothing is recorded', async () => {
    await page.goto(`${h.BASE}/admin/attendance`);
    const before = parseInt(h.dbQuery('SELECT COUNT(*) FROM presenze'), 10);
    const missing = parseInt(h.dbQuery('SELECT COALESCE(MAX(id), 0) + 1000 FROM utenti'), 10);
    await page.evaluate((id) => { /** @type {HTMLInputElement} */ (document.getElementById('attendance-member-id')).value = String(id); }, missing);
    await page.evaluate(() => { /** @type {HTMLInputElement} */ (document.getElementById('codice_tessera')).value = 'x'; });
    await submitAndWait(() => page.click('#attendance-scan-form button[type="submit"]'));
    await expect(page.getByText('Utente non trovato')).toBeVisible();
    expect(parseInt(h.dbQuery('SELECT COUNT(*) FROM presenze'), 10)).toBe(before);
  });
});

// Not serial: each case stands alone, so one failure cannot hide the others.
test.describe('Visitor log: member search edge cases', () => {
  const EDGE = `Bordi${STAMP}`;
  const EDGE_CARD = `E2EU${STAMP}`.slice(0, 20);
  const NEXT_CARD = `E2EW${STAMP}`.slice(0, 20);
  const ROLE = `ZZ Presenze ${STAMP}`;
  const OPERATOR_EMAIL = `ps-op-${STAMP}@example.test`;
  const OPERATOR_PASS = `Op!${STAMP}Aa1`;
  let member = 0;
  let nextReader = 0;
  let operator = 0;
  let role = 0;

  const field = (page) => page.locator('#codice_tessera');
  const box = (page) => page.locator('#attendance-member-suggest');
  const options = (page) => page.locator('#attendance-member-suggest .suggestion-item[data-id]');
  const visits = () => parseInt(h.dbQuery(`SELECT COUNT(*) FROM presenze WHERE utente_id = ${member} AND tipo = 'socio'`), 10);
  const sent = (page, action) => Promise.all([page.waitForURL(/\/admin\/attendance$/, { timeout: 8000 }), action()]);
  const openDesk = async (page) => {
    await h.loginAsAdmin(page);
    await page.goto(`${h.BASE}/admin/attendance`);
  };
  const forge = (page, id) => page.evaluate((value) => {
    /** @type {HTMLInputElement} */ (document.getElementById('attendance-member-id')).value = value;
    /** @type {HTMLInputElement} */ (document.getElementById('codice_tessera')).value = 'x';
  }, id);

  test.beforeAll(() => {
    h.dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente) VALUES (${h.sqlString(EDGE_CARD)}, 'Carla', ${h.sqlString(EDGE)}, ${h.sqlString(`ps-c-${STAMP}@example.test`)}, 'x', 'attivo', 'standard')`);
    member = parseInt(h.dbQuery(`SELECT id FROM utenti WHERE codice_tessera = ${h.sqlString(EDGE_CARD)}`), 10);
    h.dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente) VALUES (${h.sqlString(NEXT_CARD)}, 'Dario', ${h.sqlString(`Dopo${STAMP}`)}, ${h.sqlString(`ps-d-${STAMP}@example.test`)}, 'x', 'attivo', 'standard')`);
    nextReader = parseInt(h.dbQuery(`SELECT id FROM utenti WHERE codice_tessera = ${h.sqlString(NEXT_CARD)}`), 10);
    h.dbQuery(`INSERT INTO ruoli (nome, permessi) VALUES (${h.sqlString(ROLE)}, '["backend.access","loans.manage"]')`);
    role = parseInt(h.dbQuery(`SELECT id FROM ruoli WHERE nome = ${h.sqlString(ROLE)}`), 10);
    const hash = execFileSync('php', ['-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', OPERATOR_PASS], { encoding: 'utf-8' });
    h.dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, email_verificata, tipo_utente, ruolo_id, privacy_accettata) VALUES (${h.sqlString(`E2EV${STAMP}`.slice(0, 20))}, 'Olga', 'Operatrice', ${h.sqlString(OPERATOR_EMAIL)}, ${h.sqlString(hash)}, 'attivo', 1, 'standard', ${role}, 1)`);
    operator = parseInt(h.dbQuery(`SELECT id FROM utenti WHERE email = ${h.sqlString(OPERATOR_EMAIL)}`), 10);
  });

  test.afterAll(() => {
    try { h.dbQuery(`DELETE FROM presenze WHERE utente_id IN (${member}, ${nextReader}, ${operator})`); } catch (_) { /* cleanup */ }
    try { h.dbQuery(`DELETE FROM utenti WHERE id IN (${member}, ${nextReader}, ${operator})`); } catch (_) { /* cleanup */ }
    try { h.dbQuery(`DELETE FROM ruoli WHERE id = ${role}`); } catch (_) { /* cleanup */ }
  });

  test('A highlight does not survive more typing: Enter then sends the text', async ({ page }) => {
    await openDesk(page);
    await field(page).pressSequentially(`Carla ${EDGE}`);
    await expect(options(page)).toHaveCount(1);
    await field(page).press('ArrowDown');
    await expect(options(page).first()).toHaveClass(/selected/);
    // A reader firing into the field: new text and Enter, before the list refreshes.
    await field(page).press('x');
    await sent(page, () => field(page).press('Enter'));
    await expect(page.getByText('Tessera non riconosciuta. Controlla il codice, scegli il socio dai suggerimenti o registra la visita come ospite.')).toBeVisible();
    expect(visits()).toBe(0);
  });

  test('After a pick left unconfirmed, the next reader\'s swipe records that reader', async ({ page }) => {
    await openDesk(page);
    const nextVisits = () => parseInt(h.dbQuery(`SELECT COUNT(*) FROM presenze WHERE utente_id = ${nextReader} AND tipo = 'socio'`), 10);
    const before = [visits(), nextVisits()];
    await field(page).pressSequentially(`Carla ${EDGE}`);
    await options(page).first().click();
    await expect(page.locator('#attendance-member-id')).toHaveValue(String(member));
    // A keyboard-wedge reader types the next card and sends Enter.
    await field(page).pressSequentially(NEXT_CARD);
    await expect(page.locator('#attendance-member-id')).toHaveValue('');
    await sent(page, () => field(page).press('Enter'));
    await expect(page.getByText(/Benvenuto\/a, Dario D\.: presenza registrata\./)).toBeVisible();
    expect([visits(), nextVisits()]).toEqual([before[0], before[1] + 1]);
  });

  test('The list of the previous text goes as soon as the text changes', async ({ page }) => {
    await openDesk(page);
    await field(page).pressSequentially(`Carla ${EDGE}`);
    await expect(options(page)).toHaveCount(1);
    // Hold the next answer, so only the old list could still be on screen.
    await page.route('**/api/search/utenti*', async (route) => { await new Promise((r) => setTimeout(r, 1500)); await route.continue(); });
    await field(page).press('x');
    await expect(options(page)).toHaveCount(0);
    await expect(box(page)).toBeHidden();
    await page.unroute('**/api/search/utenti*');
  });

  test('Escape also stops a search still waiting to start', async ({ page }) => {
    await openDesk(page);
    await field(page).pressSequentially(`Carla ${EDGE}`);
    await field(page).press('Escape');
    await page.waitForTimeout(900);
    await expect(box(page)).toBeHidden();
  });

  test('A failed search says so instead of claiming nobody matches', async ({ page }) => {
    await openDesk(page);
    await page.route('**/api/search/utenti*', (route) => route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":true}' }));
    await field(page).pressSequentially(EDGE);
    await expect(box(page)).toContainText('Ricerca non disponibile. Riprova.');
    await page.unroute('**/api/search/utenti*');
    await page.route('**/api/search/utenti*', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: '{"error":true}' }));
    // Start the second search from an empty field, so the notice of the first
    // error is gone and only the non-array answer can bring it back.
    await field(page).fill('');
    await expect(box(page)).toBeHidden();
    await field(page).pressSequentially(`${EDGE}a`);
    await expect(box(page)).toContainText('Ricerca non disponibile. Riprova.');
    await expect(box(page)).not.toContainText('Nessun risultato');
    await page.unroute('**/api/search/utenti*');
    await field(page).fill('');
    await field(page).pressSequentially(`Nessuno${STAMP}`);
    await expect(box(page)).toContainText('Nessun risultato');
  });

  test('Leaving the field closes the list; pressing inside it keeps the field', async ({ page }) => {
    await openDesk(page);
    await field(page).pressSequentially(`Nessuno${STAMP}`);
    await expect(box(page)).toContainText('Nessun risultato');
    // The line is aria-disabled, so Playwright would wait for it forever:
    // force the press, which is exactly what this case is about.
    await box(page).locator('.suggestion-item').click({ force: true });
    await expect(field(page)).toBeFocused();
    await expect(box(page)).toBeVisible();
    await field(page).press('Tab');
    await expect(box(page)).toBeHidden();
  });

  test('The list is announced to assistive technology', async ({ page }) => {
    await openDesk(page);
    await field(page).pressSequentially(`Carla ${EDGE}`);
    await expect(options(page)).toHaveCount(1);
    await expect(box(page)).toHaveAttribute('aria-label', /.+/);
    await field(page).press('ArrowDown');
    const optionId = await options(page).first().getAttribute('id');
    expect(optionId).toBeTruthy();
    await expect(field(page)).toHaveAttribute('aria-activedescendant', String(optionId));
    await field(page).press('Escape');
    await expect(field(page)).not.toHaveAttribute('aria-activedescendant', /.+/);
    await field(page).fill('');
    await field(page).pressSequentially(`Nessuno${STAMP}`);
    await expect(box(page).locator('.suggestion-item')).toHaveAttribute('role', 'option');
  });

  test('On a phone the field and its list stay inside the screen', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await openDesk(page);
    await field(page).pressSequentially(`Carla ${EDGE}`);
    await expect(options(page)).toHaveCount(1);
    const fits = await page.evaluate(() => {
      const edge = (id) => { const r = document.getElementById(id).getBoundingClientRect(); return { left: r.left, right: r.right, width: r.width }; };
      return { page: document.documentElement.scrollWidth, view: window.innerWidth, field: edge('codice_tessera'), list: edge('attendance-member-suggest'), scan: edge('attendance-card-scan') };
    });
    expect(fits.page).toBeLessThanOrEqual(fits.view);
    expect(fits.list.left).toBeGreaterThanOrEqual(0);
    expect(fits.list.right).toBeLessThanOrEqual(fits.view);
    expect(fits.scan.right).toBeLessThanOrEqual(fits.view);
    expect(fits.field.width).toBeGreaterThan(120);
  });

  test('A malformed member id is not read as a number', async ({ page }) => {
    await openDesk(page);
    const before = visits();
    await forge(page, `${member}abc`);
    await sent(page, () => page.click('#attendance-scan-form button[type="submit"]'));
    await expect(page.getByText('Tessera non riconosciuta. Controlla il codice, scegli il socio dai suggerimenti o registra la visita come ospite.')).toBeVisible();
    expect(visits()).toBe(before);
  });

  test('A custom-role desk operator finds readers, and cannot reach a built-in account by id', async ({ page }) => {
    await page.goto(`${h.BASE}/accedi`);
    await page.fill('input[name="email"]', OPERATOR_EMAIL);
    await page.fill('input[name="password"]', OPERATOR_PASS);
    const rejectCookies = page.locator('#silktide-wrapper .reject-all');
    if (await rejectCookies.isVisible()) await rejectCookies.click();
    await Promise.all([page.waitForURL((url) => !/accedi/.test(url.pathname)), page.locator('button[type="submit"]').first().click()]);
    await page.goto(`${h.BASE}/admin/attendance`);
    const before = visits();
    await field(page).pressSequentially(`Carla ${EDGE}`);
    await expect(options(page)).toHaveCount(1);
    await options(page).first().click();
    await sent(page, () => page.click('#attendance-scan-form button[type="submit"]'));
    await expect(page.getByText(/Benvenuto\/a, Carla B\.: presenza registrata\./)).toBeVisible();
    expect(visits()).toBe(before + 1);
    const admin = parseInt(h.dbQuery("SELECT id FROM utenti WHERE tipo_utente = 'admin' ORDER BY id LIMIT 1"), 10);
    const adminVisits = () => parseInt(h.dbQuery(`SELECT COUNT(*) FROM presenze WHERE utente_id = ${admin}`), 10);
    const adminBefore = adminVisits();
    await forge(page, String(admin));
    await sent(page, () => page.click('#attendance-scan-form button[type="submit"]'));
    await expect(page.getByText('Utente non trovato')).toBeVisible();
    expect(adminVisits()).toBe(adminBefore);
  });
});
