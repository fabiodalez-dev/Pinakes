// @ts-check
/**
 * Circulation policy — consultation-only material and copies.
 * A: a matrix rule makes a material "Solo consultazione" for a patron type
 *    (or for everyone): no request button, staff desk and API refuse it.
 * B: a single copy is kept for in-house consultation (copie.solo_consultazione).
 */
const { test, expect } = require('@playwright/test');
const F = require('./helpers/circulation-fixtures');

test.skip(!F.CONFIGURED, 'E2E credentials not configured');

const RUN = String(Date.now()).slice(-6);
const PREFIX = `e2ecs${RUN}`;

/** POST the book-page request endpoint from the reader's page, as the button does. */
async function apiRequest(page, bookId) {
  return page.evaluate(async ({ id, start }) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const r = await fetch(`${window.BASE_PATH || ''}/api/libro/${id}/reservation`, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ start_date: start }),
    });
    return { status: r.status, body: await r.json() };
  }, { id: bookId, start: F.appTodayISO() });
}

test.describe.serial('Consultation-only material (matrix rule)', () => {
  /** @type {import('@playwright/test').Page} */ let admin;
  /** @type {import('@playwright/test').Page} */ let child;
  /** @type {import('@playwright/test').Page} */ let adultPage;
  /** @type {import('@playwright/test').Page} */ let anon;
  const ctxs = [];
  let kidType = 0; let kid; let adult; let dvd; let other;
  const errors = [];

  test.beforeAll(async ({ browser }) => {
    for (let i = 0; i < 4; i++) ctxs.push(await browser.newContext());
    [admin, child, adultPage, anon] = await Promise.all(ctxs.map((c) => c.newPage()));
    for (const p of [admin, child, adultPage, anon]) p.on('response', (r) => { if (r.status() >= 500) errors.push(`${r.status()} ${r.url()}`); });
    await F.loginAsAdmin(admin);
    kidType = await F.createTypeViaUi(admin, { nome: `Bambino ${RUN}`, codice: `${PREFIX}-kid` });
    kid = F.createPatron(`CSK${RUN}`, { typeId: kidType });
    adult = F.createPatron(`CSA${RUN}`);
    dvd = F.createTitle(`${RUN} DVD`, 'dvd');
    other = F.createTitle(`${RUN} OBJ`, 'altro');
  });
  test.afterEach(() => { expect(errors.splice(0), 'no HTTP 5xx').toEqual([]); });
  test.afterAll(async () => {
    try { F.dbQuery("DELETE FROM regole_prestito WHERE tipologia_id=0 AND tipo_media='altro'"); } catch { /* */ }
    F.deleteTitles([dvd?.id, other?.id]);
    F.deletePatrons([kid?.id, adult?.id]);
    F.deleteTypes(PREFIX);
    for (const c of ctxs) await c.close();
  });

  test('the librarian marks DVDs "consultation only" for children in the matrix', async () => {
    await F.setMatrixCell(admin, kidType, 'dvd', { loanable: 0 });
    expect(F.dbQuery(`SELECT prestabile FROM regole_prestito WHERE tipologia_id=${kidType} AND tipo_media='dvd'`)).toBe('0');
    await F.openCirculationTab(admin);
    await expect(admin.locator(`#rule-${kidType}-dvd-loanable`)).toHaveValue('0');
  });

  test('the child sees "Solo consultazione" instead of the request button', async () => {
    await F.loginAs(child, kid.email, kid.password);
    await child.goto(`${F.BASE}/libro/${dvd.id}`);
    await expect(child.locator('#consultation-only')).toBeVisible();
    await expect(child.locator('#btn-request-loan')).toHaveCount(0);
  });

  test('a request sent anyway is refused with not_loanable', async () => {
    const res = await apiRequest(child, dvd.id);
    expect(res.status).toBe(409);
    expect(res.body.error).toBe('not_loanable');
    expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${kid.id}`) + F.dbInt(`SELECT COUNT(*) FROM prenotazioni WHERE utente_id=${kid.id}`)).toBe(0);
  });

  test('the staff desk cannot lend it to the child either', async () => {
    const url = await F.staffLoan(admin, kid, dvd);
    expect(url).toContain('error=not_loanable');
    await expect(admin.locator('body')).toContainText(/consultazione|consultation/i);
  });

  test('an adult (default type) still sees the request button and can borrow', async () => {
    await F.loginAs(adultPage, adult.email, adult.password);
    await adultPage.goto(`${F.BASE}/libro/${dvd.id}`);
    await expect(adultPage.locator('#btn-request-loan')).toBeVisible();
    await expect(adultPage.locator('#consultation-only')).toHaveCount(0);
    expect(await F.staffLoan(admin, adult, dvd)).not.toContain('error=');
  });

  test('an all-types rule makes "altro" consultation only, also for anonymous visitors', async () => {
    await F.setMatrixCell(admin, 0, 'altro', { loanable: 0 });
    await anon.goto(`${F.BASE}/libro/${other.id}`);
    await expect(anon.locator('#consultation-only')).toBeVisible();
    await adultPage.goto(`${F.BASE}/libro/${other.id}`);
    await expect(adultPage.locator('#consultation-only')).toBeVisible();
    const url = await F.staffLoan(admin, adult, other);
    expect(url).toContain('error=not_loanable');
  });
});

test.describe.serial('Consultation-only copy (single copy flag)', () => {
  /** @type {import('@playwright/test').Page} */ let admin;
  /** @type {import('@playwright/test').Page} */ let reader;
  let ctxA; let ctxR; let patron; let title;
  const errors = [];

  test.beforeAll(async ({ browser }) => {
    ctxA = await browser.newContext(); admin = await ctxA.newPage();
    ctxR = await browser.newContext(); reader = await ctxR.newPage();
    for (const p of [admin, reader]) p.on('response', (r) => { if (r.status() >= 500) errors.push(`${r.status()} ${r.url()}`); });
    await F.loginAsAdmin(admin);
    patron = F.createPatron(`CSC${RUN}`);
    title = F.createTitle(`${RUN} TWO`, 'libro', 2);
  });
  test.afterEach(() => { expect(errors.splice(0), 'no HTTP 5xx').toEqual([]); });
  test.afterAll(async () => {
    F.deleteTitles([title?.id]);
    F.deletePatrons([patron?.id]);
    await ctxA?.close(); await ctxR?.close();
  });

  /** Click the consultation toggle of a copy row on the admin book page. */
  async function toggleCopy(copyId) {
    await admin.goto(`${F.BASE}/admin/books/${title.id}`);
    await admin.locator(`form[action$="/admin/books/copies/${copyId}/consultation"] [data-testid="copy-consultation-toggle"]`).click();
    await admin.waitForURL((u) => u.pathname.endsWith(`/admin/books/${title.id}`));
  }

  test('the librarian keeps the first copy for consultation from the book page', async () => {
    await toggleCopy(title.copyIds[0]);
    expect(F.dbInt(`SELECT solo_consultazione FROM copie WHERE id=${title.copyIds[0]}`)).toBe(1);
    await expect(admin.locator('[data-testid="copy-consultation-badge"]')).toHaveCount(1);
    await expect(admin.locator('[role="alert"]').filter({ hasText: /consultazione|consultation/i }).first()).toBeVisible();
  });

  test('lending that copy by its inventory code is refused', async () => {
    const url = await F.staffLoan(admin, patron, title, { copyCode: F.copyCodeOf(title.copyIds[0]) });
    expect(url).toContain('error=not_loanable');
    expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${patron.id}`)).toBe(0);
  });

  test('a loan without a code takes the other copy', async () => {
    expect(await F.staffLoan(admin, patron, title)).not.toContain('error=');
    expect(F.dbInt(`SELECT copia_id FROM prestiti WHERE utente_id=${patron.id} AND attivo=1 ORDER BY id DESC LIMIT 1`)).toBe(title.copyIds[1]);
  });

  test('a copy held by a loan cannot be reserved for consultation', async () => {
    await toggleCopy(title.copyIds[1]);
    expect(F.dbInt(`SELECT solo_consultazione FROM copie WHERE id=${title.copyIds[1]}`)).toBe(0);
    await expect(admin.locator('[role="alert"]').filter({ hasText: /impegnata|held/i }).first()).toBeVisible();
  });

  test('with every copy kept for consultation the title is consultation only; making one lendable restores the button', async () => {
    F.dbQuery(`DELETE FROM prestiti WHERE utente_id=${patron.id}`);
    F.dbQuery(`UPDATE copie SET stato='disponibile' WHERE libro_id=${title.id}`);
    await toggleCopy(title.copyIds[1]);
    expect(F.dbInt(`SELECT SUM(solo_consultazione) FROM copie WHERE libro_id=${title.id}`)).toBe(2);
    await F.loginAs(reader, patron.email, patron.password);
    await reader.goto(`${F.BASE}/libro/${title.id}`);
    await expect(reader.locator('#consultation-only')).toBeVisible();
    await toggleCopy(title.copyIds[0]);
    expect(F.dbInt(`SELECT solo_consultazione FROM copie WHERE id=${title.copyIds[0]}`)).toBe(0);
    await reader.goto(`${F.BASE}/libro/${title.id}`);
    await expect(reader.locator('#btn-request-loan')).toBeVisible();
  });
});
