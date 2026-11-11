// @ts-check
// Loans 120 — part 2 of 4: the life of a loan (30 tests).
//
// A reader asks for a book from its page; the librarian approves, hands it
// over, renews it, takes it back — or refuses, cancels, edits. Loans go late,
// copies come back damaged or not at all, readers hit their limit. Every step
// is made on the page a person uses; the database is read for the outcome and
// seeded only with what cannot be done today (a loan lent weeks ago).
//
// Run: /tmp/run-e2e.sh loans-120-lifecycle.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const { createLab, addDays } = require('./helpers/loan-lab');

const lab = createLab('LL');
const { F, db, dbInt } = lab;

test.skip(!F.CONFIGURED, 'E2E credentials not configured');

test.describe('Loans 120 · the life of a loan', () => {
  test.describe.configure({ timeout: 180000 });
  /** @type {import('@playwright/test').Page} */ let admin;
  /** @type {import('@playwright/test').Page} */ let reader;
  /** @type {import('@playwright/test').Page} */ let reader2;
  let adminCtx; let readerCtx; let reader2Ctx;
  let anna; let bruno; let carla;

  test.beforeAll(async ({ browser }) => {
    await lab.setup();
    anna = lab.patron('Anna');
    bruno = lab.patron('Bruno');
    carla = lab.patron('Carla');
    adminCtx = await browser.newContext();
    admin = await adminCtx.newPage();
    await F.loginAsAdmin(admin);
    readerCtx = await browser.newContext();
    reader = await readerCtx.newPage();
    await F.loginAs(reader, anna.email, anna.password);
    reader2Ctx = await browser.newContext();
    reader2 = await reader2Ctx.newPage();
    await F.loginAs(reader2, bruno.email, bruno.password);
  });
  test.beforeEach(async () => { await lab.reset(); });
  test.afterAll(async () => {
    await lab.teardown();
    await Promise.all([adminCtx?.close(), readerCtx?.close(), reader2Ctx?.close()]);
  });

  // ── Request → approval → pickup → return ─────────────────────────────────
  test('L01: a request from the book page waits for approval and holds no copy', async () => {
    const book = lab.book('request');
    const r = await lab.readerRequest(reader, book, { start: lab.today });
    expect(r.ok, JSON.stringify(r.body)).toBe(true);
    expect(r.body.status).toBe('pending_approval');
    const loan = lab.loan(lab.lastLoanId(book, anna));
    expect(loan.stato).toBe('pendente');
    expect(loan.start).toBe(lab.today);
    expect(loan.due).toBe(addDays(lab.today, lab.loanDays));
    expect(lab.available(book)).toBe(1);
    expect(await lab.readerCard(reader, book)).toContain(book.title);
  });

  test('L02: approval, pickup and return take the loan and its copy through every state', async () => {
    const book = lab.book('fullcycle');
    await lab.readerRequest(reader, book, { start: lab.today });
    const id = lab.lastLoanId(book, anna);
    const approved = await lab.approve(admin, id);
    expect(approved.ok, approved.message).toBe(true);
    let loan = lab.loan(id);
    expect(loan.stato).toBe('da_ritirare');
    expect(loan.copy).toBe(book.copyIds[0]);
    expect(loan.pickup).toBe(addDays(lab.today, lab.pickupDays));
    expect(lab.available(book)).toBe(0);
    const picked = await lab.confirmPickup(admin, id);
    expect(picked.ok, picked.message).toBe(true);
    loan = lab.loan(id);
    expect(loan.stato).toBe('in_corso');
    expect(lab.copyState(book.copyIds[0])).toBe('prestato');
    const back = await lab.returnLoan(admin, id);
    expect(back.ok).toBe(true);
    loan = lab.loan(id);
    expect([loan.stato, loan.attivo]).toEqual(['restituito', 0]);
    expect(db(`SELECT data_restituzione FROM prestiti WHERE id=${id}`)).toBe(lab.today);
    expect(lab.copyState(book.copyIds[0])).toBe('disponibile');
    expect(lab.available(book)).toBe(1);
    lab.expectCoherent(book);
  });

  test('L03: once returned, the book can be asked for again by another reader the same day', async () => {
    const book = lab.book('again');
    const id = lab.seedLoan(book, anna, { start: addDays(lab.today, -10), due: addDays(lab.today, 20) });
    expect((await lab.bookPage(reader2, book)).label).not.toMatch(/Richiedi Prestito/i);
    await lab.returnLoan(admin, id);
    const page2 = await lab.bookPage(reader2, book);
    expect(page2.label).toMatch(/Richiedi Prestito/i);
    const r = await lab.readerRequest(reader2, book, { start: lab.today });
    expect(r.ok, JSON.stringify(r.body)).toBe(true);
    expect(r.body.status).toBe('pending_approval');
  });

  test('L04: a refused request is closed with the reason and the reader can ask again', async () => {
    const book = lab.book('reject');
    await lab.readerRequest(reader, book, { start: lab.today });
    const id = lab.lastLoanId(book, anna);
    const refused = await lab.reject(admin, id, 'Copia in riparazione');
    expect(refused.ok, refused.message).toBe(true);
    expect(lab.loan(id).stato).toBe('annullato');
    expect(lab.loan(id).attivo).toBe(0);
    expect(lab.available(book)).toBe(1);
    const again = await lab.readerRequest(reader, book, { start: lab.today });
    expect(again.ok, JSON.stringify(again.body)).toBe(true);
    expect(lab.lastLoanId(book, anna)).toBeGreaterThan(id);
  });

  test('L05: the reader withdraws a request still waiting, from the reservations page', async () => {
    const book = lab.book('withdraw');
    await lab.readerRequest(reader, book, { start: lab.today });
    const id = lab.lastLoanId(book, anna);
    const cancelled = await lab.readerCancelLoan(reader, id);
    expect(cancelled.offered).toBe(true);
    expect(cancelled.url).toContain('canceled=1');
    expect(lab.loan(id).stato).toBe('annullato');
    expect(await lab.approve(admin, id)).toMatchObject({ ok: false });
    expect(lab.loan(id).stato).toBe('annullato');
  });

  test('L06: the reader gives up a book waiting at the desk and the copy is free again', async () => {
    const book = lab.book('giveup');
    await lab.readerRequest(reader, book, { start: lab.today });
    const id = lab.lastLoanId(book, anna);
    await lab.approve(admin, id);
    expect(lab.available(book)).toBe(0);
    const cancelled = await lab.readerCancelLoan(reader, id);
    expect(cancelled.offered).toBe(true);
    expect(lab.loan(id).attivo).toBe(0);
    expect(lab.copyState(book.copyIds[0])).toBe('disponibile');
    expect(lab.available(book)).toBe(1);
    lab.expectCoherent(book);
  });

  test('L07: the librarian cancels a pickup that never happened and the copy is free again', async () => {
    const book = lab.book('cancelpickup');
    const desk = await lab.deskLoan(admin, anna, book, { immediate: false });
    expect(lab.loan(desk.id).stato).toBe('da_ritirare');
    const cancelled = await lab.cancelPickup(admin, desk.id, 'Non si è presentata');
    expect(cancelled.ok, cancelled.message).toBe(true);
    expect(lab.loan(desk.id).stato).toBe('annullato');
    expect(lab.copyState(book.copyIds[0])).toBe('disponibile');
    lab.expectCoherent(book);
  });

  test('L08: a request approved twice from two open tabs is approved once', async () => {
    const book = lab.book('twotabs');
    await lab.readerRequest(reader, book, { start: lab.today });
    const id = lab.lastLoanId(book, anna);
    const tab = await adminCtx.newPage();
    try {
      await tab.goto(`${F.BASE}/admin/loans/pending`);
      await expect(tab.locator(`.approve-btn[data-loan-id="${id}"]`).first()).toBeVisible();
      expect((await lab.approve(admin, id)).ok).toBe(true);
      // The second tab still shows the button.
      const resP = tab.waitForResponse((r) => r.url().includes('/admin/loans/approve') && r.request().method() === 'POST');
      await tab.locator(`.approve-btn[data-loan-id="${id}"]`).first().click();
      await tab.locator('.swal2-confirm').click();
      const res = await resP;
      expect(res.status()).toBeLessThan(500);
      expect((await res.json().catch(() => ({}))).success).not.toBe(true);
    } finally { await tab.close(); }
    expect(lab.loan(id).stato).toBe('da_ritirare');
    expect(dbInt(`SELECT COUNT(*) FROM prestiti WHERE libro_id=${book.id} AND attivo=1`)).toBe(1);
    lab.expectCoherent(book);
  });

  // ── Loans booked for later ───────────────────────────────────────────────
  test('L09: a desk loan that starts next week is booked; the copy can still go out and come back before it', async () => {
    const book = lab.book('later');
    const start = addDays(lab.today, 7);
    const booked = await lab.deskLoan(admin, anna, book, { start, due: addDays(start, 10) });
    expect(booked.ok, booked.error).toBe(true);
    expect(lab.loan(booked.id).stato).toBe('prenotato');
    // Bruno takes it today and brings it back the day before: allowed.
    const short = await lab.deskLoan(admin, bruno, book, { due: addDays(start, -1) });
    expect(short.ok, `a loan ending before the booking was refused: ${short.error}`).toBe(true);
    expect(lab.loan(short.id).stato).toBe('in_corso');
    lab.expectCoherent(book);
  });

  test('L10: a desk loan that would run into a booking of the same copy is refused', async () => {
    const book = lab.book('collide');
    const start = addDays(lab.today, 7);
    await lab.deskLoan(admin, anna, book, { start, due: addDays(start, 10) });
    const clash = await lab.deskLoan(admin, bruno, book, { due: start });
    expect(clash.ok).toBe(false);
    expect(clash.error).not.toBe('');
    expect(lab.lastLoanId(book, bruno)).toBe(0);
    lab.expectCoherent(book);
  });

  test('L11: on its first day a booked loan becomes a pickup with a deadline', async () => {
    const book = lab.book('activate');
    const id = lab.seedLoan(book, anna, { start: lab.today, due: addDays(lab.today, 14), stato: 'prenotato', origine: 'richiesta' });
    lab.runMaintenance();
    const loan = lab.loan(id);
    expect(loan.stato).toBe('da_ritirare');
    expect(loan.pickup).toBe(addDays(lab.today, lab.pickupDays));
    expect(await lab.readerCard(reader, book)).toContain(book.title);
  });

  test('L12: the librarian can cancel a loan booked for a later date from the pending page', async () => {
    const book = lab.book('cancel-later');
    const start = addDays(lab.today, 7);
    const booked = await lab.deskLoan(admin, anna, book, { start, due: addDays(start, 10) });
    expect(lab.loan(booked.id).stato).toBe('prenotato');
    const offered = await lab.pendingOffers(admin, 'cancel-pickup-btn', booked.id);
    expect(offered, 'the "Prestiti Programmati" card has no cancel button').toBe(true);
    const cancelled = await lab.cancelPickup(admin, booked.id, 'Lettrice ha rinunciato');
    expect(cancelled.ok, cancelled.message).toBe(true);
    expect(lab.loan(booked.id).attivo).toBe(0);
  });

  // ── Late loans ───────────────────────────────────────────────────────────
  test('L13: a loan due yesterday is marked late by the nightly sweep and listed as late', async () => {
    const book = lab.book('late');
    const id = lab.seedLoan(book, anna, { start: addDays(lab.today, -31), due: addDays(lab.today, -1) });
    const future = lab.seedLoan(lab.book('ontime'), bruno, { start: addDays(lab.today, -29), due: lab.today });
    lab.runMaintenance();
    expect(lab.loan(id).stato).toBe('in_ritardo');
    expect(lab.loan(future).stato, 'a loan due today is not late yet').toBe('in_corso');
    await admin.goto(`${F.BASE}/admin/loans/pending`);
    await expect(admin.locator(`.return-btn[data-loan-id="${id}"]`).first()).toBeVisible();
    expect(await lab.readerCard(reader, book)).toMatch(/ritardo/i);
  });

  test('L14: a late loan returned is closed as returned late and the copy is free', async () => {
    const book = lab.book('late-return');
    const id = lab.seedLoan(book, anna, { start: addDays(lab.today, -40), due: addDays(lab.today, -5), stato: 'in_ritardo' });
    const back = await lab.returnLoan(admin, id);
    expect(back.ok).toBe(true);
    expect(db(`SELECT CONCAT(stato,'|',restituito_in_ritardo,'|',attivo) FROM prestiti WHERE id=${id}`)).toBe('restituito|1|0');
    expect(lab.copyState(book.copyIds[0])).toBe('disponibile');
    lab.expectCoherent(book);
  });

  test('L15: a late loan cannot be renewed', async () => {
    const book = lab.book('late-renew');
    const due = addDays(lab.today, -5);
    const id = lab.seedLoan(book, anna, { start: addDays(lab.today, -40), due, stato: 'in_ritardo' });
    const renewed = await lab.renew(admin, id);
    expect(renewed.offered).toBe(false);
    expect(lab.loan(id).due).toBe(due);
    await expect(admin.getByText('Non rinnovabile: prestito in ritardo').first()).toBeVisible();
  });

  // ── Renewals and extensions ──────────────────────────────────────────────
  test('L16: renewals stop at the number the library allows', async () => {
    await lab.setSetting('max_renewals', '2');
    const book = lab.book('renew-max');
    const id = lab.seedLoan(book, anna, { start: addDays(lab.today, -5), due: addDays(lab.today, 3) });
    const first = await lab.renew(admin, id);
    expect([first.offered, first.renewals]).toEqual([true, 1]);
    expect(first.due > addDays(lab.today, 3)).toBe(true);
    const second = await lab.renew(admin, id);
    expect([second.offered, second.renewals]).toEqual([true, 2]);
    expect(second.due > first.due).toBe(true);
    const third = await lab.renew(admin, id);
    expect(third.offered).toBe(false);
    expect(third.renewals).toBe(2);
    expect(third.due).toBe(second.due);
  });

  test('L17: a renewal never runs into the next reader\'s booking of the same copy', async () => {
    const book = lab.book('renew-booked');
    const due = addDays(lab.today, 3);
    const id = lab.seedLoan(book, anna, { start: addDays(lab.today, -5), due });
    const nextStart = addDays(due, 4);
    lab.seedLoan(book, bruno, { start: nextStart, due: addDays(nextStart, 14), stato: 'prenotato', origine: 'richiesta' });
    lab.recount(book);
    db(`UPDATE copie SET stato='prestato' WHERE id=${book.copyIds[0]}`);
    await lab.noServerErrors(admin, () => lab.renew(admin, id));
    expect(lab.loan(id).due < nextStart, `renewed to ${lab.loan(id).due}, the next booking starts ${nextStart}`).toBe(true);
    lab.expectCoherent(book);
  });

  test('L18: a renewal never runs over a reader waiting in the queue for those days', async () => {
    const book = lab.book('renew-queue');
    const due = addDays(lab.today, 3);
    const id = lab.seedLoan(book, anna, { start: addDays(lab.today, -5), due });
    const wanted = addDays(due, 2);
    lab.seedReservation(book, bruno, { start: wanted, end: addDays(wanted, 14), position: 1 });
    await lab.noServerErrors(admin, () => lab.renew(admin, id));
    expect(lab.loan(id).due < wanted, `renewed to ${lab.loan(id).due}, a reader is waiting from ${wanted}`).toBe(true);
  });

  test('L19: "extend by N days" on the loans list extends every selected loan', async () => {
    const a = lab.seedLoan(lab.book('bulk-a'), anna, { start: addDays(lab.today, -5), due: addDays(lab.today, 4) });
    const b = lab.seedLoan(lab.book('bulk-b'), bruno, { start: addDays(lab.today, -5), due: addDays(lab.today, 6) });
    const res = await lab.bulkExtend(admin, [a, b], 7, `${lab.RUN}LL bulk`);
    expect(res.offered).toBe(true);
    expect(lab.loan(a).due).toBe(addDays(lab.today, 11));
    expect(lab.loan(b).due).toBe(addDays(lab.today, 13));
  });

  test('L20: when one of the selected loans cannot be extended, none is', async () => {
    const bookA = lab.book('bulkx-a');
    const a = lab.seedLoan(bookA, anna, { start: addDays(lab.today, -5), due: addDays(lab.today, 4) });
    const b = lab.seedLoan(lab.book('bulkx-b'), bruno, { start: addDays(lab.today, -5), due: addDays(lab.today, 6) });
    // Carla has booked the first copy from the day after it is due.
    lab.seedLoan(bookA, carla, { start: addDays(lab.today, 5), due: addDays(lab.today, 19), stato: 'prenotato', origine: 'richiesta' });
    db(`UPDATE copie SET stato='prestato' WHERE id=${bookA.copyIds[0]}`);
    await lab.noServerErrors(admin, () => lab.bulkExtend(admin, [a, b], 7, `${lab.RUN}LL bulkx`));
    expect(lab.loan(a).due).toBe(addDays(lab.today, 4));
    expect(lab.loan(b).due, 'the second loan was extended although the batch failed').toBe(addDays(lab.today, 6));
    lab.expectCoherent(bookA);
  });

  // ── How the copy comes back ──────────────────────────────────────────────
  test('L21: the quick return by inventory code keeps the note written on the loan', async () => {
    const book = lab.book('bycode');
    const id = lab.seedLoan(book, anna, { start: addDays(lab.today, -5), due: addDays(lab.today, 20), note: 'Con custodia e CD allegato' });
    await lab.noServerErrors(admin, () => lab.returnByCode(admin, F.copyCodeOf(book.copyIds[0])));
    expect(lab.loan(id).stato).toBe('restituito');
    expect(db(`SELECT IFNULL(note,'<NULL>') FROM prestiti WHERE id=${id}`)).toBe('Con custodia e CD allegato');
  });

  test('L22: a copy declared lost at return closes the loan and is not lent again', async () => {
    const book = lab.book('lost');
    const id = lab.seedLoan(book, anna, { start: addDays(lab.today, -5), due: addDays(lab.today, 20) });
    const back = await lab.returnLoan(admin, id, { stato: 'perso' });
    expect(back.ok).toBe(true);
    expect([lab.loan(id).stato, lab.loan(id).attivo]).toEqual(['perso', 0]);
    expect(lab.copyState(book.copyIds[0])).toBe('perso');
    expect(lab.available(book)).toBe(0);
    const again = await lab.deskLoan(admin, bruno, book);
    expect(again.ok).toBe(false);
    expect((await lab.bookPage(reader2, book)).label).not.toMatch(/Richiedi Prestito/i);
  });

  test('L23: a copy sent to maintenance at return closes the loan as returned but is not available', async () => {
    const book = lab.book('maint', 2);
    const id = lab.seedLoan(book, anna, { start: addDays(lab.today, -5), due: addDays(lab.today, 20), copy: book.copyIds[0] });
    const back = await lab.returnLoan(admin, id, { stato: 'manutenzione' });
    expect(back.ok).toBe(true);
    expect(lab.loan(id).stato).toBe('restituito');
    expect(lab.copyState(book.copyIds[0])).toBe('manutenzione');
    expect(lab.available(book)).toBe(1);
    // The other copy is the one that goes out.
    const next = await lab.deskLoan(admin, bruno, book);
    expect(next.ok, next.error).toBe(true);
    expect(lab.loan(next.id).copy).toBe(book.copyIds[1]);
    const third = await lab.deskLoan(admin, carla, book);
    expect(third.ok).toBe(false);
    lab.expectCoherent(book);
  });

  // ── Limits and readers who cannot borrow ─────────────────────────────────
  test('L24: with a limit of one loan a second is refused, and allowed again after the return', async () => {
    // A reader of her own: the limit counts every open loan, and the shared
    // readers carry the loans of the tests before this one.
    const dora = lab.patron('Dora');
    await lab.setSetting('max_active_loans_per_user', '1');
    const first = await lab.deskLoan(admin, dora, lab.book('limit-1'));
    expect(first.ok, first.error).toBe(true);
    const bookTwo = lab.book('limit-2');
    const second = await lab.deskLoan(admin, dora, bookTwo);
    expect(second.ok).toBe(false);
    expect(second.error).toBe('max_loans_reached');
    expect(lab.lastLoanId(bookTwo, dora)).toBe(0);
    await lab.returnLoan(admin, first.id);
    const third = await lab.deskLoan(admin, dora, bookTwo);
    expect(third.ok, third.error).toBe(true);
  });

  test('L25: at her limit, a reader\'s request from the book page is refused and nothing is saved', async () => {
    await lab.setSetting('max_active_loans_per_user', '1');
    lab.seedLoan(lab.book('limit-held'), anna, { start: addDays(lab.today, -3), due: addDays(lab.today, 20) });
    const book = lab.book('limit-ask');
    const r = await lab.noServerErrors(reader, () => lab.readerRequest(reader, book, { start: lab.today }));
    expect(r.ok).toBe(false);
    expect(lab.lastLoanId(book, anna)).toBe(0);
    expect(lab.lastReservationId(book, anna)).toBe(0);
  });

  test('L26: a suspended reader is refused at the desk and on the book page', async () => {
    const book = lab.book('suspended');
    db(`UPDATE utenti SET stato='sospeso' WHERE id=${carla.id}`);
    try {
      const desk = await lab.deskLoan(admin, carla, book);
      expect(desk.ok).toBe(false);
      expect(desk.error).toBe('user_suspended');
      expect(lab.lastLoanId(book, carla)).toBe(0);
    } finally { db(`UPDATE utenti SET stato='attivo' WHERE id=${carla.id}`); }
  });

  test('L27: the same reader cannot hold two copies of one title unless the library allows it', async () => {
    const book = lab.book('twice', 2);
    const first = await lab.deskLoan(admin, anna, book);
    expect(first.ok, first.error).toBe(true);
    const second = await lab.deskLoan(admin, anna, book);
    expect(second.ok).toBe(false);
    expect(lab.available(book)).toBe(1);
    await lab.setSetting('allow_multiple_loans_same_book', '1');
    const allowed = await lab.deskLoan(admin, anna, book);
    expect(allowed.ok, allowed.error).toBe(true);
    expect(lab.loan(allowed.id).copy).not.toBe(lab.loan(first.id).copy);
    expect(lab.available(book)).toBe(0);
    lab.expectCoherent(book);
  });

  // ── Several copies ───────────────────────────────────────────────────────
  test('L28: with two copies two readers borrow, the third is refused until one comes back', async () => {
    const book = lab.book('copies', 2);
    const a = await lab.deskLoan(admin, anna, book);
    const b = await lab.deskLoan(admin, bruno, book);
    expect([a.ok, b.ok]).toEqual([true, true]);
    expect(lab.loan(a.id).copy).not.toBe(lab.loan(b.id).copy);
    expect(lab.available(book)).toBe(0);
    const c = await lab.deskLoan(admin, carla, book);
    expect(c.ok).toBe(false);
    await lab.returnLoan(admin, a.id);
    expect(lab.available(book)).toBe(1);
    const c2 = await lab.deskLoan(admin, carla, book);
    expect(c2.ok, c2.error).toBe(true);
    expect(lab.loan(c2.id).copy).toBe(lab.loan(a.id).copy);
    lab.expectCoherent(book);
  });

  // ── Editing a loan ───────────────────────────────────────────────────────
  test('L29: a loan of one day is treated the same at the desk and in the edit form', async () => {
    const book = lab.book('oneday');
    const desk = await lab.deskLoan(admin, anna, book, { due: lab.today });
    const control = lab.book('oneday-edit');
    const made = await lab.deskLoan(admin, bruno, control);
    const edit = await lab.editLoan(admin, made.id, { due: lab.today });
    expect(desk.ok, desk.error).toBe(true);
    expect(lab.loan(desk.id).due).toBe(lab.today);
    expect(edit.refusedByCalendar).toBe(false);
    expect(edit.error).toBe('');
    expect(lab.loan(made.id).due).toBe(lab.today);
  });

  test('L30: a book already out cannot be given a start date in the future', async () => {
    const book = lab.book('edit-future');
    const originalStart = addDays(lab.today, -5);
    const id = lab.seedLoan(book, anna, { start: originalStart, due: addDays(lab.today, 20) });
    const edit = await lab.noServerErrors(admin, () => lab.editLoan(admin, id, { start: addDays(lab.today, 5) }));
    expect(edit.error).toBe('future_start_out');
    const loan = lab.loan(id);
    expect(loan.stato).toBe('in_corso');
    expect(loan.start).toBe(originalStart);
  });
});
