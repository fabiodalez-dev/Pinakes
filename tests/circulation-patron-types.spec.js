// @ts-check
/**
 * Circulation policy — patron types (Settings → Circulation).
 * A librarian creates, edits, orders, makes default and deletes patron types,
 * assigns one to a reader from the user form, reads the effective rules on the
 * reader's page and filters/exports the user list by type.
 */
const { test, expect } = require('@playwright/test');
const F = require('./helpers/circulation-fixtures');

test.skip(!F.CONFIGURED, 'E2E credentials not configured');

const RUN = String(Date.now()).slice(-6);
const PREFIX = `e2ept${RUN}`;

test.describe.serial('Patron types CRUD', () => {
  /** @type {import('@playwright/test').Page} */
  let page;
  let context;
  let typeA = 0;
  let typeB = 0;
  let patron;
  const errors = [];

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    page.on('response', (r) => { if (r.status() >= 500) errors.push(`${r.status()} ${r.url()}`); });
    await F.loginAsAdmin(page);
    patron = F.createPatron(`PT${RUN}`);
  });
  test.afterEach(() => { expect(errors.splice(0), 'no HTTP 5xx').toEqual([]); });
  test.afterAll(async () => {
    F.deletePatrons([patron?.id]);
    F.deleteTypes(PREFIX);
    await context?.close();
  });

  test('the circulation tab lists the seeded default type', async () => {
    await F.openCirculationTab(page);
    const row = page.locator('#circulation-types-table tr[data-type-code="standard"]');
    await expect(row).toBeVisible();
    await expect(row.locator('[data-testid="default-type-badge"]')).toBeVisible();
  });

  test('create a "Studente" type from the form', async () => {
    typeA = await F.createTypeViaUi(page, { nome: `Studente ${RUN}`, codice: `${PREFIX}-stud`, max_prestiti: 2, durata_prestito_giorni: 14 });
    await expect(page.locator('.alert, [role="alert"]').filter({ hasText: /creata|created/i }).first()).toBeVisible();
    const row = page.locator(`#circulation-types-table tr[data-type-code="${PREFIX}-stud"]`);
    await expect(row).toContainText(`Studente ${RUN}`);
    await expect(row).toContainText('2');
    expect(F.dbQuery(`SELECT CONCAT(max_prestiti,'|',durata_prestito_giorni,'|',IFNULL(max_rinnovi,'NULL')) FROM tipologie_lettore WHERE id=${typeA}`)).toBe('2|14|NULL');
  });

  test('a duplicate code is refused with a message', async () => {
    await F.openCirculationTab(page);
    await page.locator('#circulation-new-type summary').click();
    const form = page.locator('[data-testid="circulation-type-create"]');
    await form.locator('[name="nome"]').fill('Doppione');
    await form.locator('[name="codice"]').fill(`${PREFIX}-stud`);
    await form.locator('button[type="submit"]').click();
    await expect(page.locator('[role="alert"]').filter({ hasText: /codice|code/i }).first()).toBeVisible();
    expect(F.dbInt(`SELECT COUNT(*) FROM tipologie_lettore WHERE codice='${PREFIX}-stud'`)).toBe(1);
  });

  test('edit the type: rename, disable reservations, set a card duration', async () => {
    await F.openCirculationTab(page);
    await page.locator(`#circulation-types-table tr[data-type-code="${PREFIX}-stud"] [data-testid="edit-type"]`).click();
    const form = page.locator('[data-testid="circulation-type-edit"]');
    await expect(form).toBeVisible();
    await form.locator('[name="nome"]').fill(`Studenti ${RUN}`);
    await form.locator('[name="durata_tessera_giorni"]').fill('365');
    await form.locator('input[type="checkbox"][name="prenotazioni_abilitate"]').uncheck();
    await form.locator('button[type="submit"]').click();
    await page.waitForURL((u) => u.searchParams.get('tab') === 'circulation');
    expect(F.dbQuery(`SELECT CONCAT(nome,'|',prenotazioni_abilitate,'|',durata_tessera_giorni) FROM tipologie_lettore WHERE id=${typeA}`)).toBe(`Studenti ${RUN}|0|365`);
    await expect(page.locator(`#circulation-types-table tr[data-type-code="${PREFIX}-stud"]`)).toContainText(/Prenotazioni disattivate|Reservations disabled/);
  });

  test('order: the second type moves above the first', async () => {
    typeB = await F.createTypeViaUi(page, { nome: `Docente ${RUN}`, codice: `${PREFIX}-doc` });
    const order = () => F.dbQuery(`SELECT GROUP_CONCAT(codice ORDER BY ordinamento, nome, id) FROM tipologie_lettore WHERE codice LIKE '${PREFIX}%'`);
    expect(order()).toBe(`${PREFIX}-stud,${PREFIX}-doc`);
    await F.openCirculationTab(page);
    await page.locator(`#circulation-types-table tr[data-type-code="${PREFIX}-doc"] [data-testid="move-up"]`).click();
    await page.waitForURL((u) => u.searchParams.get('tab') === 'circulation');
    expect(order()).toBe(`${PREFIX}-doc,${PREFIX}-stud`);
  });

  test('make a type the default, then back: exactly one default', async () => {
    await F.openCirculationTab(page);
    await page.locator(`#circulation-types-table tr[data-type-code="${PREFIX}-doc"] [data-testid="make-default"]`).click();
    await page.waitForURL((u) => u.searchParams.get('tab') === 'circulation');
    expect(F.dbQuery('SELECT GROUP_CONCAT(codice) FROM tipologie_lettore WHERE predefinita=1')).toBe(`${PREFIX}-doc`);
    await page.locator('#circulation-types-table tr[data-type-code="standard"] [data-testid="make-default"]').click();
    await page.waitForURL((u) => u.searchParams.get('tab') === 'circulation');
    expect(F.dbQuery('SELECT GROUP_CONCAT(codice) FROM tipologie_lettore WHERE predefinita=1')).toBe('standard');
    await expect(page.locator('#circulation-types-table tr[data-type-code="standard"] [data-testid="delete-type"]')).toHaveCount(0);
  });

  test('assign the type from the user edit form and read the effective rules', async () => {
    await page.goto(`${F.BASE}/admin/users/edit/${patron.id}`);
    await page.locator('#tipologia_id').selectOption(String(typeA));
    await page.locator('#user-form button[type="submit"]').click();
    await page.waitForURL((u) => !u.pathname.includes('/edit/'), { timeout: 20000 });
    expect(page.url()).not.toContain('error=');
    expect(F.dbInt(`SELECT IFNULL(tipologia_id,0) FROM utenti WHERE id=${patron.id}`)).toBe(typeA);
    await page.goto(`${F.BASE}/admin/users/details/${patron.id}`);
    await expect(page.locator('[data-testid="patron-type-name"]')).toContainText(`Studenti ${RUN}`);
    const rules = page.locator('[data-testid="patron-effective-rules"]');
    await expect(rules).toContainText('14');
    await expect(rules).toContainText(/Prenotazioni disattivate|Reservations disabled/);
    await expect(rules).toContainText('365');
  });

  test('the user list filters by patron type and the CSV carries the column', async () => {
    await page.goto(`${F.BASE}/admin/users`);
    const listResponse = page.waitForResponse((r) => r.url().includes('/api/utenti') && r.url().includes(`patron_type_filter=${typeA}`));
    await page.locator('#patron_type_filter').selectOption(String(typeA));
    const json = await (await listResponse).json();
    const emails = (json.data || []).map((r) => JSON.stringify(r));
    expect(emails.join(' ')).toContain(patron.email);
    expect(json.recordsFiltered).toBe(1);
    const csv = await page.request.get(`${F.BASE}/admin/users/export/csv?patron_type_filter=${typeA}`);
    expect(csv.status()).toBe(200);
    const text = await csv.text();
    expect(text).toMatch(/Tipologia lettore|Patron type/);
    expect(text).toContain(patron.email);
    expect(text).toContain(`Studenti ${RUN}`);
  });

  test('deleting a type moves its patrons back to the default type', async () => {
    await F.openCirculationTab(page);
    page.once('dialog', (d) => d.accept());
    await page.locator(`#circulation-types-table tr[data-type-code="${PREFIX}-stud"] [data-testid="delete-type"]`).click();
    await page.waitForURL((u) => u.searchParams.get('tab') === 'circulation');
    expect(F.dbInt(`SELECT COUNT(*) FROM tipologie_lettore WHERE id=${typeA}`)).toBe(0);
    expect(F.dbQuery(`SELECT IFNULL(tipologia_id,'NULL') FROM utenti WHERE id=${patron.id}`)).toBe('NULL');
    await page.goto(`${F.BASE}/admin/users/details/${patron.id}`);
    await expect(page.locator('[data-testid="patron-type-name"]')).toContainText('Standard');
  });
});
