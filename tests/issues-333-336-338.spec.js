// @ts-check
//
// End-to-end regression coverage for the three user-visible fixes shipped in
// v0.7.59. The existing PHP guards pin the implementation structure; these
// tests prove the real admin routes, rendered pages and persisted data agree.
//
// Run:
//   /tmp/run-e2e.sh tests/issues-333-336-338.spec.js \
//     --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');
const {
  BASE_URL,
  createTempAdminUser,
  createTempBook,
  dbExec,
  dbQuery,
  deleteTempAdminUser,
  deleteTempBook,
} = require('./helpers/e2e-fixtures');
const { appDateOffsetISO } = require('./helpers/app-date');

test.describe.serial('issues #333, #336 and #338', () => {
  // This suite drives the admin login and reads/writes the DB directly, so skip
  // (rather than fail in beforeAll) when the CI-injected credentials are absent.
  test.skip(
    !process.env.E2E_ADMIN_EMAIL ||
      !process.env.E2E_ADMIN_PASS ||
      !process.env.E2E_DB_USER ||
      !process.env.E2E_DB_NAME,
    'E2E admin and database credentials are required',
  );

  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  /** @type {{id: number, email: string, password: string, locale: string}} */
  let admin;
  /** @type {{id: number, email: string, password: string, locale: string}} */
  let reservationUser;
  /** @type {{id: number, title: string}} */
  let book;

  let cancelledLoanId = 0;
  let editableLoanId = 0;
  const seriesName = `Issue338Series${Date.now()}`;
  const originalDueDate = appDateOffsetISO(10);
  const shortenedDueDate = appDateOffsetISO(8);

  test.beforeAll(async ({ browser }) => {
    admin = createTempAdminUser('it_IT');
    reservationUser = createTempAdminUser('it_IT');
    book = createTempBook('Issues 333 336');

    cancelledLoanId = Number(dbQuery(`
      INSERT INTO prestiti (
        libro_id, utente_id, data_prestito, data_scadenza,
        stato, attivo, note, created_at, updated_at
      ) VALUES (
        ${book.id}, ${admin.id}, '${appDateOffsetISO(-20)}', '${appDateOffsetISO(-5)}',
        'annullato', 0, '[User] Annullato dall utente', NOW(), NOW()
      );
      SELECT LAST_INSERT_ID();
    `));

    editableLoanId = Number(dbQuery(`
      INSERT INTO prestiti (
        libro_id, utente_id, data_prestito, data_scadenza,
        stato, origine, attivo, created_at, updated_at
      ) VALUES (
        ${book.id}, ${admin.id}, '${appDateOffsetISO(0)}', '${originalDueDate}',
        'in_corso', 'diretto', 1, NOW(), NOW()
      );
      SELECT LAST_INSERT_ID();
    `));

    // This reservation already coexists with the loan's current window. Before
    // #336, update() rechecked the whole edited window, counted this commitment
    // against the one-copy capacity and rejected even a shortening operation.
    dbExec(`
      INSERT INTO prenotazioni (
        libro_id, utente_id, data_prenotazione,
        data_inizio_richiesta, data_fine_richiesta,
        queue_position, stato, created_at, updated_at
      ) VALUES (
        ${book.id}, ${reservationUser.id}, NOW(),
        '${appDateOffsetISO(2)}', '${appDateOffsetISO(4)}',
        1, 'attiva', NOW(), NOW()
      )
    `);

    // Seed only the pre-existing column: if the #338 migration is missing, the
    // dedicated test fails on the absent checkbox instead of preventing the
    // unrelated #333/#336 cases from running at all.
    dbExec(`INSERT INTO collane (nome) VALUES ('${seriesName}')`);

    expect(cancelledLoanId).toBeGreaterThan(0);
    expect(editableLoanId).toBeGreaterThan(0);

    context = await browser.newContext();
    page = await context.newPage();
    await page.goto(`${BASE_URL}/accedi`);
    await page.getByRole('textbox', { name: 'Email' }).fill(admin.email);
    await page.getByRole('textbox', { name: 'Password' }).fill(admin.password);
    await page.getByRole('button', { name: 'Accedi' }).click();
    await page.waitForURL('**/admin/dashboard');
  });

  test.afterAll(async () => {
    await context?.close();

    if (book) {
      // deleteTempAdminUser and deleteTempBook are intentionally redundant for
      // loan/reservation rows, making cleanup safe after a partially-run suite.
      try { dbExec(`DELETE FROM prenotazioni WHERE libro_id = ${book.id}`); } catch {}
      try { dbExec(`DELETE FROM prestiti WHERE libro_id = ${book.id}`); } catch {}
    }
    try { dbExec(`DELETE FROM collane WHERE nome = '${seriesName}'`); } catch {}
    if (reservationUser) {
      try { deleteTempAdminUser(reservationUser.id); } catch {}
    }
    if (admin) {
      try { deleteTempAdminUser(admin.id); } catch {}
    }
    if (book) {
      try { deleteTempBook(book.id); } catch {}
    }
  });

  test('#333 renders a cancelled loan as Annullato, not Unknown or still pending return', async () => {
    await page.goto(`${BASE_URL}/admin/loans/details/${cancelledLoanId}`);

    const statusRow = page.getByText('Stato:', { exact: true }).locator('..');
    await expect(statusRow).toContainText('Annullato');
    await expect(statusRow).not.toContainText(/Sconosciuto|Unknown/i);

    const returnDateRow = page.getByText('Data Restituzione:', { exact: true }).locator('..');
    await expect(returnDateRow).toContainText('—');
    await expect(returnDateRow).not.toContainText('Non ancora restituito');
  });

  test('#336 allows shortening a loan when an existing reservation overlaps only held days', async () => {
    await page.goto(`${BASE_URL}/admin/loans/edit/${editableLoanId}`);

    const form = page.locator(`form[action$="/admin/loans/update/${editableLoanId}"]`);
    const dueDateInput = form.locator('input[name="data_scadenza"]');
    await expect(dueDateInput).toHaveValue(originalDueDate);
    // The shared Flatpickr initializer hides the original ISO input and exposes
    // a localized alternate input. Drive the real widget API so both values and
    // its change event match a user selection.
    await dueDateInput.evaluate((element, value) => {
      const input = /** @type {HTMLInputElement & {_flatpickr?: {setDate: Function}}} */ (element);
      if (input._flatpickr) {
        input._flatpickr.setDate(value, true, 'Y-m-d');
      } else {
        input.value = value;
        input.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }, shortenedDueDate);
    await expect(dueDateInput).toHaveValue(shortenedDueDate);

    await Promise.all([
      page.waitForURL((url) => url.pathname.endsWith('/admin/loans')),
      form.getByRole('button', { name: 'Salva modifiche' }).click(),
    ]);

    expect(new URL(page.url()).searchParams.has('error')).toBe(false);
    expect(dbQuery(`SELECT data_scadenza FROM prestiti WHERE id = ${editableLoanId}`))
      .toBe(shortenedDueDate);
    expect(dbQuery(`
      SELECT COUNT(*)
        FROM prenotazioni
       WHERE libro_id = ${book.id}
         AND stato = 'attiva'
         AND data_inizio_richiesta <= '${shortenedDueDate}'
         AND data_fine_richiesta >= '${appDateOffsetISO(0)}'
    `)).toBe('1');
  });

  test('#338 persists and displays the complete-series flag, then clears it', async () => {
    const detailUrl = `${BASE_URL}/admin/series/detail?nome=${encodeURIComponent(seriesName)}`;
    await page.goto(detailUrl);

    const form = page.locator('form[action$="/admin/series/description"]');
    const checkbox = form.locator('input[name="is_completa"]');
    await expect(checkbox).not.toBeChecked();
    await checkbox.check();
    await Promise.all([
      page.waitForURL((url) => (
        url.pathname.endsWith('/admin/series/detail')
          && url.searchParams.get('nome') === seriesName
      )),
      form.getByRole('button', { name: 'Salva modifiche' }).click(),
    ]);

    await expect(checkbox).toBeChecked();
    expect(dbQuery(`SELECT is_completa FROM collane WHERE nome = '${seriesName}'`)).toBe('1');
    await expect(page.locator('h1')).toContainText('Serie completa');

    await page.goto(`${BASE_URL}/admin/series`);
    const seriesRow = page.locator('tbody tr').filter({ hasText: seriesName });
    await expect(seriesRow).toHaveCount(1);
    await expect(seriesRow.locator('[role="img"][aria-label="Serie completa"]')).toBeVisible();

    await page.goto(detailUrl);
    const clearForm = page.locator('form[action$="/admin/series/description"]');
    const clearCheckbox = clearForm.locator('input[name="is_completa"]');
    await clearCheckbox.uncheck();
    await Promise.all([
      page.waitForURL((url) => (
        url.pathname.endsWith('/admin/series/detail')
          && url.searchParams.get('nome') === seriesName
      )),
      clearForm.getByRole('button', { name: 'Salva modifiche' }).click(),
    ]);

    await expect(clearCheckbox).not.toBeChecked();
    expect(dbQuery(`SELECT is_completa FROM collane WHERE nome = '${seriesName}'`)).toBe('0');

    await page.goto(`${BASE_URL}/admin/series`);
    const clearedRow = page.locator('tbody tr').filter({ hasText: seriesName });
    await expect(clearedRow.locator('[role="img"][aria-label="Serie completa"]')).toHaveCount(0);
  });
});
