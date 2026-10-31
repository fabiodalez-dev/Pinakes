// @ts-check
/**
 * Issue #501, part 5 of 9: the back office. Every list a librarian filters by
 * typing — books, authors, publishers, users, loans, reservations — plus the
 * global search, the pickers of the new-loan form and the CSV exports, which
 * must export what the filtered list shows. Unquoted keeps finding every
 * spelling; a quoted value finds only its exact spelling.
 */
const { test, expect } = require('@playwright/test');
// e2e-fixtures throws while loading when the E2E environment is missing, so
// it is required only when the suite is going to run; the skip reports why.
const E2E_READY = Boolean(process.env.E2E_BASE_URL && process.env.E2E_DB_USER && process.env.E2E_DB_NAME
  && (process.env.E2E_DB_HOST || process.env.E2E_DB_SOCKET));
test.skip(!E2E_READY, 'E2E environment not configured (use /tmp/run-e2e.sh)');
const { createTempAdminUser, deleteTempAdminUser } = E2E_READY ? require('./helpers/e2e-fixtures') : {};
const { makeToken, seed, clean, login, typeAndWait, expectTitles } = require('./helpers/exact-search-501');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';

const enc = encodeURIComponent;

test.describe.serial('#501 admin lists honour quotes', () => {
  /** @type {ReturnType<typeof seed>} */
  let f;
  /** @type {{id:number,email:string,password:string}} */
  let admin;
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    f = seed(makeToken());
    admin = createTempAdminUser('it_IT');
    context = await browser.newContext();
    page = await context.newPage();
    await login(page, BASE, admin.email, admin.password);
  });
  test.afterAll(async () => {
    await context?.close();
    clean(f);
    deleteTempAdminUser(admin?.id);
  });

  const table = () => page.locator('table').first();
  // DataTables with horizontal scroll splits header and body into two tables.
  const main = () => page.locator('main');

  // ── Books ────────────────────────────────────────────────────────────────
  test('01 books: unquoted author name finds every spelling', async () => {
    await page.goto(`${BASE}/admin/books`);
    await typeAndWait(page, '#search_text', `${f.token} haffner`, '/api/libri');
    await expectTitles(table(), [f.titlePlain, f.titleUmlaut, f.titleUpper], []);
  });

  test('02 books: quoted "haffner" drops the Häffner book', async () => {
    await typeAndWait(page, '#search_text', `${f.token} "haffner"`, '/api/libri');
    await expectTitles(table(), [f.titlePlain, f.titleUpper], [f.titleUmlaut]);
  });

  test('03 books: quoted "Häffner" keeps only the Häffner book', async () => {
    await typeAndWait(page, '#search_text', `${f.token} "Häffner"`, '/api/libri');
    await expectTitles(table(), [f.titleUmlaut], [f.titlePlain, f.titleUpper]);
  });

  // ── Authors ──────────────────────────────────────────────────────────────
  test('04 authors: unquoted finds every spelling', async () => {
    await page.goto(`${BASE}/admin/authors`);
    await typeAndWait(page, '#search_nome', `${f.token} haffner`, '/api/autori');
    await expectTitles(table(), [f.authorPlain, f.authorUmlaut, f.authorUpper], []);
  });

  test('05 authors: quoted "haffner" drops Häffner, keeps the case variant', async () => {
    await typeAndWait(page, '#search_nome', `"${f.token} haffner"`, '/api/autori');
    await expectTitles(table(), [f.authorPlain, f.authorUpper], [f.authorUmlaut]);
  });

  test('06 authors: quoted "Häffner" keeps only Häffner', async () => {
    await typeAndWait(page, '#search_nome', `"${f.token} Häffner"`, '/api/autori');
    await expectTitles(table(), [f.authorUmlaut], [f.authorPlain]);
  });

  test('07 authors: two quoted groups in the box are one phrase', async () => {
    await typeAndWait(page, '#search_nome', `"${f.token}" "häffner"`, '/api/autori');
    await expectTitles(table(), [f.authorUmlaut], [f.authorPlain]);
  });

  // ── Publishers ───────────────────────────────────────────────────────────
  test('08 publishers: unquoted finds every spelling', async () => {
    await page.goto(`${BASE}/admin/publishers`);
    await typeAndWait(page, '#search_nome', `verlag ${f.token} haffner`, '/api/editori');
    await expectTitles(table(), [f.publisherPlain, f.publisherUmlaut], []);
  });

  test('09 publishers: quoted Haffner drops Häffner', async () => {
    await typeAndWait(page, '#search_nome', `"verlag ${f.token} haffner"`, '/api/editori');
    await expectTitles(table(), [f.publisherPlain], [f.publisherUmlaut]);
  });

  test('10 publishers: quoted Häffner drops Haffner', async () => {
    await typeAndWait(page, '#search_nome', `"verlag ${f.token} Häffner"`, '/api/editori');
    await expectTitles(table(), [f.publisherUmlaut], [f.publisherPlain]);
  });

  test('11 publishers: a typed % is a literal character', async () => {
    await typeAndWait(page, '#search_nome', `${f.token} 50%`, '/api/editori');
    await expectTitles(table(), [f.publisherPercent], [f.publisherPlain, f.publisherUmlaut]);
  });

  // ── Users ────────────────────────────────────────────────────────────────
  test('12 users: unquoted surname finds both borrowers', async () => {
    await page.goto(`${BASE}/admin/users`);
    await typeAndWait(page, '#search_text', 'haffner', '/api/utenti');
    await expect(main()).toContainText(f.token);
    await expect(main()).toContainText('Häffner');
  });

  test('13 users: quoted "Häffner" drops Haffner', async () => {
    await typeAndWait(page, '#search_text', '"Häffner"', '/api/utenti');
    await expect(main()).toContainText('Häffner');
    await expect(main().locator('tr', { hasText: f.token }).filter({ hasText: /Haffner/ })).toHaveCount(0);
  });

  test('14 users: quoted "Haffner" drops Häffner', async () => {
    await typeAndWait(page, '#search_text', '"Haffner"', '/api/utenti');
    await expect(main().locator('tr', { hasText: f.token }).filter({ hasText: 'Haffner' })).toHaveCount(1);
    await expect(main().locator('tr', { hasText: f.token }).filter({ hasText: 'Häffner' })).toHaveCount(0);
  });

  // ── Loans ────────────────────────────────────────────────────────────────
  // The pending-requests panel above the table lists every pending loan
  // whatever is searched, so the checks look at the loans table only.
  test('15 loans: unquoted title finds both loans', async () => {
    await page.goto(`${BASE}/admin/loans`);
    await typeAndWait(page, '.dt-search input', `${f.token}`, '/api/prestiti');
    await expectTitles(page.locator('#prestiti-table'), [f.titlePlain, f.titleUmlaut], []);
  });

  test('16 loans: quoted borrower "Häffner" keeps only that loan', async () => {
    await typeAndWait(page, '.dt-search input', `"${f.userUmlaut}"`, '/api/prestiti');
    await expectTitles(page.locator('#prestiti-table'), [f.titleUmlaut], [f.titlePlain]);
  });

  // ── Reservations ─────────────────────────────────────────────────────────
  /** @param {string} field @param {string} value */
  async function filterReservations(field, value) {
    await page.goto(`${BASE}/admin/reservations`);
    await page.fill(field, value);
    await Promise.all([page.waitForURL(/[?&]q_/), page.getByRole('button', { name: 'Cerca' }).click()]);
  }

  test('17 reservations: unquoted borrower finds both', async () => {
    await filterReservations('#admin_filter_utente', `${f.token} haffner`);
    await expectTitles(page.locator('main'), [f.titlePlain, f.titleUmlaut], []);
  });

  test('18 reservations: quoted borrower "Häffner" keeps only that reservation', async () => {
    await filterReservations('#admin_filter_utente', `"${f.userUmlaut}"`);
    await expectTitles(page.locator('main'), [f.titleUmlaut], [f.titlePlain]);
  });

  test('19 reservations: quoted title "Brückenbuch" keeps only that reservation', async () => {
    await filterReservations('#admin_filter_libro', `"${f.titlePlain}"`);
    await expectTitles(page.locator('main'), [f.titlePlain], [f.titleUmlaut]);
  });

  // ── Global search and the new-loan pickers ───────────────────────────────
  test('20 global search: unquoted lists both authors', async () => {
    await page.goto(`${BASE}/admin/dashboard`);
    await typeAndWait(page, '#global-search', `${f.token} haffner`, '/api/search/unified');
    await expectTitles(page.locator('#global-search-results'), [f.authorPlain, f.authorUmlaut], []);
  });

  test('21 global search: quoted "Häffner" lists only Häffner', async () => {
    await typeAndWait(page, '#global-search', `${f.token} "häffner"`, '/api/search/unified');
    await expectTitles(page.locator('#global-search-results'), [f.authorUmlaut], [f.authorPlain]);
  });

  test('22 new loan: the borrower picker honours quotes', async () => {
    await page.goto(`${BASE}/admin/loans/create`);
    await typeAndWait(page, '#utente_search', `"${f.userUmlaut}"`, '/api/search/utenti');
    await expect(page.locator('#utente_suggest')).toContainText('Häffner');
    await expect(page.locator('#utente_suggest')).not.toContainText(`${f.token} Haffner`);
  });

  test('23 new loan: the book picker honours quotes', async () => {
    await typeAndWait(page, '#libro_search', `${f.token} "häffner"`, '/api/search/libri');
    await expectTitles(page.locator('#libro_suggest'), [f.titleUmlaut], [f.titlePlain]);
  });

  // ── CSV exports ──────────────────────────────────────────────────────────
  test('24 books CSV export follows the quoted search', async () => {
    const res = await page.request.get(`${BASE}/admin/books/export/csv?search=${enc(`"${f.titleUmlaut}"`)}`);
    expect(res.status()).toBe(200);
    const body = await res.text();
    expect(body).toContain(f.titleUmlaut);
    expect(body).not.toContain(f.titlePlain);
  });

  test('25 users CSV export follows the quoted search', async () => {
    const res = await page.request.get(`${BASE}/admin/users/export/csv?search_text=${enc('"Häffner"')}`);
    expect(res.status()).toBe(200);
    const body = await res.text();
    expect(body).toContain(f.token.toLowerCase() + '.1@example.test');
    expect(body).not.toContain(f.token.toLowerCase() + '.0@example.test');
  });

  test('26 LibraryThing export follows the quoted search', async () => {
    const res = await page.request.get(`${BASE}/admin/books/export/librarything?search=${enc(`"${f.titlePlain}"`)}`);
    expect(res.status()).toBe(200);
    const body = await res.text();
    expect(body).toContain(f.titlePlain);
    expect(body).not.toContain(f.titleUmlaut);
  });
});
