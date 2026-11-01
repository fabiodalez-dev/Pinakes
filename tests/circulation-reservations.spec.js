// @ts-check
/**
 * Circulation policy — reservation rules of the patron type.
 * A: a type without reservations: the waitlist button disappears, a queued
 *    request is refused, the staff can override explicitly.
 * B: max_prenotazioni counts queue reservations + requests awaiting approval.
 */
const { test, expect } = require('@playwright/test');
const F = require('./helpers/circulation-fixtures');

test.skip(!F.CONFIGURED, 'E2E credentials not configured');

const RUN = String(Date.now()).slice(-6);
const PREFIX = `e2ers${RUN}`;

/** POST the book-page request endpoint from the reader's page, as the button does. */
async function apiRequest(page, bookId, start) {
  return page.evaluate(async ({ id, s }) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const r = await fetch(`${window.BASE_PATH || ''}/api/libro/${id}/reservation`, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ start_date: s }),
    });
    return { status: r.status, body: await r.json() };
  }, { id: bookId, s: start });
}

/** Make the only copy of a title busy for 20 days (another patron's loan). */
function makeBusy(title, otherUserId) {
  F.dbQuery(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo, origine)
             VALUES (${title.id}, ${title.copyIds[0]}, ${otherUserId}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 20 DAY), 'in_corso', 1, 'diretto')`);
  F.dbQuery(`UPDATE copie SET stato='prestato' WHERE id=${title.copyIds[0]}`);
  F.dbQuery(`UPDATE libri SET copie_disponibili=0 WHERE id=${title.id}`);
}

/** Create a reservation from the admin form; returns the redirect URL. */
async function adminReserve(page, patron, title, { force = false, start = F.appDateOffsetISO(25) } = {}) {
  await page.goto(`${F.BASE}/admin/reservations/create`);
  await page.locator('#libro_id').selectOption(String(title.id));
  await page.locator('#utente_id').selectOption(String(patron.id));
  // After the other patron's loan: a free window, queued behind the copy.
  await page.evaluate((d) => {
    const el = /** @type {any} */ (document.querySelector('#data_prenotazione'));
    if (el && el._flatpickr) el._flatpickr.setDate(d, true, 'Y-m-d'); else if (el) el.value = d;
  }, start);
  if (force) await page.locator('#force_limits').check();
  await page.locator('form[action$="/admin/reservations/create"] button[type="submit"]').click();
  await page.waitForURL((u) => u.search.includes('error=') || u.search.includes('created=1'), { timeout: 20000 });
  return page.url();
}

test.describe.serial('Patron type without reservations', () => {
  /** @type {import('@playwright/test').Page} */ let admin;
  /** @type {import('@playwright/test').Page} */ let reader;
  let ctxA; let ctxR; let typeId = 0; let patron; let other; let busy; let free;
  const errors = [];

  test.beforeAll(async ({ browser }) => {
    ctxA = await browser.newContext(); admin = await ctxA.newPage();
    ctxR = await browser.newContext(); reader = await ctxR.newPage();
    for (const p of [admin, reader]) p.on('response', (r) => { if (r.status() >= 500) errors.push(`${r.status()} ${r.url()}`); });
    await F.loginAsAdmin(admin);
    typeId = await F.createTypeViaUi(admin, { nome: `Ospite ${RUN}`, codice: `${PREFIX}-guest` }, { reservations: false });
    patron = F.createPatron(`RSG${RUN}`, { typeId });
    other = F.createPatron(`RSO${RUN}`);
    busy = F.createTitle(`${RUN} BUSY`, 'libro');
    free = F.createTitle(`${RUN} FREE`, 'libro');
    makeBusy(busy, other.id);
    await F.loginAs(reader, patron.email, patron.password);
  });
  test.afterEach(() => { expect(errors.splice(0), 'no HTTP 5xx').toEqual([]); });
  test.afterAll(async () => {
    F.deleteTitles([busy?.id, free?.id]);
    F.deletePatrons([patron?.id, other?.id]);
    F.deleteTypes(PREFIX);
    await ctxA?.close(); await ctxR?.close();
  });

  test('the "reserve when available" button is replaced for this patron', async () => {
    await reader.goto(`${F.BASE}/libro/${busy.id}`);
    await expect(reader.locator('#reservations-disabled')).toBeVisible();
    await expect(reader.locator('#btn-request-loan')).toHaveCount(0);
  });

  test('a patron of the default type still sees the button on the same title', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await F.loginAs(page, other.email, other.password);
    await page.goto(`${F.BASE}/libro/${busy.id}`);
    await expect(page.locator('#btn-request-loan')).toBeVisible();
    await ctx.close();
  });

  test('a loan request on an available title still works', async () => {
    const res = await F.requestFromBookPage(reader, free.id, F.appTodayISO());
    expect(res.ok, res.text).toBe(true);
    expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${patron.id} AND libro_id=${free.id}`)).toBe(1);
  });

  test('a request that would join the waitlist is refused with reservations_disabled', async () => {
    await reader.goto(`${F.BASE}/libro/${free.id}`);
    // A start after the other loan ends: capacity is free, but the copy is
    // still out, so the request would wait in the queue.
    const res = await apiRequest(reader, busy.id, F.appDateOffsetISO(25));
    expect(res.status).toBe(409);
    expect(res.body.error).toBe('reservations_disabled');
    expect(F.dbInt(`SELECT COUNT(*) FROM prenotazioni WHERE utente_id=${patron.id}`)).toBe(0);
  });

  test('the admin form refuses it too, and accepts it with the explicit override', async () => {
    const refused = await adminReserve(admin, patron, busy);
    expect(refused).toContain('error=reservations_disabled');
    await expect(admin.locator('[data-testid="reservation-policy-error"]')).toBeVisible();
    const forced = await adminReserve(admin, patron, busy, { force: true });
    expect(forced).toContain('created=1');
    expect(F.dbInt(`SELECT COUNT(*) FROM prenotazioni WHERE utente_id=${patron.id} AND libro_id=${busy.id} AND stato='attiva'`)).toBe(1);
  });
});

test.describe.serial('Reservation limit of the patron type', () => {
  /** @type {import('@playwright/test').Page} */ let admin;
  /** @type {import('@playwright/test').Page} */ let reader;
  let ctxA; let ctxR; let typeId = 0; let patron; let other; let a; let b; let c;
  const errors = [];

  test.beforeAll(async ({ browser }) => {
    ctxA = await browser.newContext(); admin = await ctxA.newPage();
    ctxR = await browser.newContext(); reader = await ctxR.newPage();
    for (const p of [admin, reader]) p.on('response', (r) => { if (r.status() >= 500) errors.push(`${r.status()} ${r.url()}`); });
    await F.loginAsAdmin(admin);
    typeId = await F.createTypeViaUi(admin, { nome: `Limitato ${RUN}`, codice: `${PREFIX}-lim`, max_prenotazioni: 1 });
    patron = F.createPatron(`RSL${RUN}`, { typeId });
    other = F.createPatron(`RSP${RUN}`);
    a = F.createTitle(`${RUN} LA`, 'libro');
    b = F.createTitle(`${RUN} LB`, 'libro');
    c = F.createTitle(`${RUN} LC`, 'libro');
    makeBusy(c, other.id);
    await F.loginAs(reader, patron.email, patron.password);
  });
  test.afterEach(() => { expect(errors.splice(0), 'no HTTP 5xx').toEqual([]); });
  test.afterAll(async () => {
    F.deleteTitles([a?.id, b?.id, c?.id]);
    F.deletePatrons([patron?.id, other?.id]);
    F.deleteTypes(PREFIX);
    await ctxA?.close(); await ctxR?.close();
  });

  test('the first request is accepted (it awaits approval)', async () => {
    const res = await F.requestFromBookPage(reader, a.id, F.appTodayISO());
    expect(res.ok, res.text).toBe(true);
    expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${patron.id} AND stato='pendente'`)).toBe(1);
  });

  test('the second request is refused: the pending one counts against the limit of 1', async () => {
    const res = await F.requestFromBookPage(reader, b.id, F.appTodayISO());
    expect(res.ok).toBe(false);
    expect(res.text).toMatch(/prenotazioni|reservations/i);
    expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${patron.id} AND libro_id=${b.id}`)).toBe(0);
  });

  test('once the librarian approves the first request the slot is free again', async () => {
    const loanId = F.dbInt(`SELECT id FROM prestiti WHERE utente_id=${patron.id} AND libro_id=${a.id} AND stato='pendente'`);
    await admin.goto(`${F.BASE}/admin/loans/details/${loanId}`);
    await admin.locator('.approve-btn').click();
    const confirm = admin.locator('.swal2-confirm');
    if (await confirm.isVisible({ timeout: 3000 }).catch(() => false)) await confirm.click();
    await expect.poll(() => F.dbQuery(`SELECT stato FROM prestiti WHERE id=${loanId}`), { timeout: 15000 }).not.toBe('pendente');
    const res = await F.requestFromBookPage(reader, b.id, F.appTodayISO());
    expect(res.ok, res.text).toBe(true);
  });

  test('a queue reservation counts too: the admin form refuses a second one', async () => {
    // b is now a pending request (1 of 1): a reservation on c is over the limit.
    const refused = await adminReserve(admin, patron, c);
    expect(refused).toContain('error=reservation_limit_reached');
    await expect(admin.locator('[data-testid="reservation-policy-error"]')).toContainText(/prenotazioni|reservations/i);
  });

  test('the explicit override lets the staff queue it anyway, and then the reader is at the limit', async () => {
    F.dbQuery(`DELETE FROM prestiti WHERE utente_id=${patron.id} AND libro_id=${b.id}`);
    const forced = await adminReserve(admin, patron, c, { force: true });
    expect(forced).toContain('created=1');
    await reader.goto(`${F.BASE}/libro/${b.id}`);
    const res = await apiRequest(reader, b.id, F.appTodayISO());
    expect(res.status).toBe(409);
    expect(res.body.error).toBe('reservation_limit_reached');
  });
});
