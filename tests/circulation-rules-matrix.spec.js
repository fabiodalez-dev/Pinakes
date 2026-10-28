// @ts-check
/**
 * Circulation policy — rules matrix patron type × material.
 * "Studente" may hold 2 loans in total and at most 1 DVD; a book borrowed
 * by a student lasts 10 days. The librarian sets the matrix cell from the UI,
 * lends from the staff desk and the reader requests from the book page.
 */
const { test, expect } = require('@playwright/test');
const F = require('./helpers/circulation-fixtures');

test.skip(!F.CONFIGURED, 'E2E credentials not configured');

const RUN = String(Date.now()).slice(-6);
const PREFIX = `e2emx${RUN}`;

test.describe.serial('Rules matrix: total and per-material limits, due date per type', () => {
  /** @type {import('@playwright/test').Page} */ let admin;
  /** @type {import('@playwright/test').Page} */ let reader;
  let ctxA; let ctxR;
  let studentType = 0;
  let student; let adult;
  let dvd1; let dvd2; let book1; let book2; let audio;
  const errors = [];

  test.beforeAll(async ({ browser }) => {
    ctxA = await browser.newContext();
    admin = await ctxA.newPage();
    ctxR = await browser.newContext();
    reader = await ctxR.newPage();
    for (const p of [admin, reader]) p.on('response', (r) => { if (r.status() >= 500) errors.push(`${r.status()} ${r.url()}`); });
    await F.loginAsAdmin(admin);
    studentType = await F.createTypeViaUi(admin, { nome: `Studente ${RUN}`, codice: `${PREFIX}-stud`, max_prestiti: 2, durata_prestito_giorni: 10 });
    student = F.createPatron(`MXS${RUN}`, { typeId: studentType });
    adult = F.createPatron(`MXA${RUN}`);
    dvd1 = F.createTitle(`${RUN} D1`, 'dvd');
    dvd2 = F.createTitle(`${RUN} D2`, 'dvd');
    book1 = F.createTitle(`${RUN} B1`, 'libro');
    book2 = F.createTitle(`${RUN} B2`, 'libro');
    audio = F.createTitle(`${RUN} A1`, 'audiolibro');
  });
  test.afterEach(() => { expect(errors.splice(0), 'no HTTP 5xx').toEqual([]); });
  test.afterAll(async () => {
    F.deleteTitles([dvd1?.id, dvd2?.id, book1?.id, book2?.id, audio?.id]);
    F.deletePatrons([student?.id, adult?.id]);
    try { F.dbQuery(`DELETE FROM regole_prestito WHERE tipologia_id=0 AND tipo_media='audiolibro'`); } catch { /* */ }
    F.deleteTypes(PREFIX);
    await ctxA?.close(); await ctxR?.close();
  });

  test('the matrix saves a cell and greys the inherited values elsewhere', async () => {
    await F.setMatrixCell(admin, studentType, 'dvd', { max: 1 });
    expect(F.dbQuery(`SELECT CONCAT(IFNULL(prestabile,'-'),'|',max_prestiti,'|',IFNULL(durata_prestito_giorni,'-')) FROM regole_prestito WHERE tipologia_id=${studentType} AND tipo_media='dvd'`)).toBe('-|1|-');
    await F.openCirculationTab(admin);
    await expect(admin.locator(`#rule-${studentType}-dvd-max`)).toHaveValue('1');
    // An empty cell shows what it inherits: the type's 10 days, the global renewals.
    await expect(admin.locator(`#rule-${studentType}-libro-duration`)).toHaveValue('');
    await expect(admin.locator(`#rule-${studentType}-libro-duration`)).toHaveAttribute('placeholder', '10');
    // No row was written for the cells left empty.
    expect(F.dbInt(`SELECT COUNT(*) FROM regole_prestito WHERE tipologia_id=${studentType}`)).toBe(1);
  });

  test('the staff desk lends the first DVD with the student due date (10 days)', async () => {
    const url = await F.staffLoan(admin, student, dvd1);
    expect(url).not.toContain('error=');
    const due = F.dbQuery(`SELECT DATEDIFF(data_scadenza, data_prestito) FROM prestiti WHERE utente_id=${student.id} AND libro_id=${dvd1.id} ORDER BY id DESC LIMIT 1`);
    expect(Number(due)).toBe(10);
  });

  test('a second DVD is refused: limit for the material type', async () => {
    const url = await F.staffLoan(admin, student, dvd2);
    expect(url).toContain('error=media_limit_reached');
    await expect(admin.locator('body')).toContainText(/tipo di materiale|material type/i);
    expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${student.id} AND libro_id=${dvd2.id}`)).toBe(0);
  });

  test('the reader is refused the second DVD from the book page too', async () => {
    await F.loginAs(reader, student.email, student.password);
    const res = await F.requestFromBookPage(reader, dvd2.id, F.appTodayISO());
    expect(res.ok).toBe(false);
    expect(res.text).toMatch(/tipo di materiale|material type/i);
    expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${student.id} AND libro_id=${dvd2.id}`)).toBe(0);
  });

  test('a book still passes (different material), then the total limit of 2 stops the next one', async () => {
    expect(await F.staffLoan(admin, student, book1)).not.toContain('error=');
    expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${student.id} AND attivo=1`)).toBe(2);
    const url = await F.staffLoan(admin, student, book2);
    expect(url).toContain('error=max_loans_reached');
  });

  test('a matrix cell with 0 renewals makes the student book loan not renewable', async () => {
    await F.setMatrixCell(admin, studentType, 'libro', { renewals: 0 });
    const loanId = F.dbInt(`SELECT id FROM prestiti WHERE utente_id=${student.id} AND libro_id=${book1.id} AND attivo=1 ORDER BY id DESC LIMIT 1`);
    // The staff desk loan starts as "to collect": the patron picks it up.
    F.dbQuery(`UPDATE prestiti SET stato='in_corso' WHERE id=${loanId}`);
    await admin.goto(`${F.BASE}/admin/books/${book1.id}`);
    await expect(admin.locator('body')).toContainText(/Limite massimo rinnovi raggiunto|Maximum renewals reached/);
    await expect(admin.locator(`form[action$="/admin/loans/renew/${loanId}"]`)).toHaveCount(0);
  });

  test('a patron of the default type is not affected by the student rules', async () => {
    expect(await F.staffLoan(admin, adult, dvd2)).not.toContain('error=');
    const globalDays = Number(F.dbQuery("SELECT IFNULL((SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='loan_duration_days'), '30')"));
    const due = Number(F.dbQuery(`SELECT DATEDIFF(data_scadenza, data_prestito) FROM prestiti WHERE utente_id=${adult.id} AND libro_id=${dvd2.id} ORDER BY id DESC LIMIT 1`));
    expect(due).toBe(globalDays > 0 ? globalDays : 30);
  });

  test('an all-types rule for audiobooks sets 5 days for everyone', async () => {
    await F.setMatrixCell(admin, 0, 'audiolibro', { duration: 5 });
    expect(await F.staffLoan(admin, adult, audio)).not.toContain('error=');
    const due = Number(F.dbQuery(`SELECT DATEDIFF(data_scadenza, data_prestito) FROM prestiti WHERE utente_id=${adult.id} AND libro_id=${audio.id} ORDER BY id DESC LIMIT 1`));
    expect(due).toBe(5);
  });

  test('the reader request of a book gets the student duration', async () => {
    // Free one slot: the librarian returns the DVD.
    F.dbQuery(`UPDATE prestiti SET stato='restituito', attivo=0, data_restituzione=CURDATE() WHERE utente_id=${student.id} AND libro_id=${dvd1.id}`);
    F.dbQuery(`UPDATE copie SET stato='disponibile' WHERE libro_id=${dvd1.id}`);
    const res = await F.requestFromBookPage(reader, book2.id, F.appTodayISO());
    expect(res.ok, res.text).toBe(true);
    const days = Number(F.dbQuery(`SELECT DATEDIFF(data_scadenza, data_prestito) FROM prestiti WHERE utente_id=${student.id} AND libro_id=${book2.id} ORDER BY id DESC LIMIT 1`));
    expect(days).toBe(10);
  });
});
