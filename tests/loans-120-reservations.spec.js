// @ts-check
// Loans 120 — part 3 of 4: reservations and the waiting queue (30 tests).
//
// The book is out and readers queue for it from its page; they cancel, the
// librarian cancels, the book comes back and the first in line gets it;
// reservations expire; the librarian adds and edits them from the admin
// pages. Queue rows that must already exist when the test begins (made days
// ago, their first day now reached) are seeded; everything else is done on
// the pages.
//
// Run: /tmp/run-e2e.sh loans-120-reservations.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const { createLab, addDays, weekday } = require('./helpers/loan-lab');

const lab = createLab('LR');
const { F, db, dbInt } = lab;

test.skip(!F.CONFIGURED, 'E2E credentials not configured');

test.describe('Loans 120 · reservations and the queue', () => {
  test.describe.configure({ timeout: 180000 });
  /** @type {import('@playwright/test').Page} */ let admin;
  /** @type {Record<string, import('@playwright/test').Page>} */ const pages = {};
  /** @type {any[]} */ const contexts = [];
  let holder; let anna; let bruno; let carla;

  /** A title whose only copy `holder` has had for five days, due in `dueIn` days. */
  const outBook = (tag, dueIn = 10, copies = 1) => {
    const book = lab.book(tag, copies);
    const loanId = lab.seedLoan(book, holder, { start: addDays(lab.today, -5), due: addDays(lab.today, dueIn) });
    return { book, loanId, due: addDays(lab.today, dueIn) };
  };
  /** Readers who queued days ago for a window that has begun. */
  const queueOf = (book, readers) => readers.map((r, i) => lab.seedReservation(book, r, { start: addDays(lab.today, -1), end: addDays(lab.today, 20), position: i + 1 }));
  const q = (book) => lab.queue(book);
  const pos = (...pairs) => pairs.map(([p, n]) => `${p.id}:${n}`).join(',');

  test.beforeAll(async ({ browser }) => {
    await lab.setup();
    holder = lab.patron('Holder');
    anna = lab.patron('Anna');
    bruno = lab.patron('Bruno');
    carla = lab.patron('Carla');
    const open = async (email, password) => {
      const ctx = await browser.newContext();
      contexts.push(ctx);
      const p = await ctx.newPage();
      await F.loginAs(p, email, password);
      return p;
    };
    admin = await open(F.ADMIN_EMAIL, F.ADMIN_PASS);
    pages.holder = await open(holder.email, holder.password);
    pages.anna = await open(anna.email, anna.password);
    pages.bruno = await open(bruno.email, bruno.password);
    pages.carla = await open(carla.email, carla.password);
  });
  test.beforeEach(async () => { await lab.reset(); });
  test.afterAll(async () => {
    await lab.teardown();
    await Promise.all(contexts.map((c) => c.close()));
  });

  // ── Joining the queue ────────────────────────────────────────────────────
  test('R01: a book that is out offers "reserve when available" and the reader joins the queue', async () => {
    const { book, due } = outBook('join');
    const r = await lab.readerRequest(pages.anna, book, { start: addDays(due, 1) });
    expect(r.label).toMatch(/Prenota Quando Disponibile/i);
    expect(r.ok, JSON.stringify(r.body)).toBe(true);
    expect(r.body.status).toBe('reserved');
    expect(q(book)).toBe(pos([anna, 1]));
    expect(lab.lastLoanId(book, anna), 'a queue reservation is not a loan').toBe(0);
    expect(lab.reservation(lab.lastReservationId(book, anna)).start).toBe(addDays(due, 1));
  });

  test('R02: three readers queue in the order they asked, each for the first days the calendar still offers', async () => {
    const { book, due } = outBook('order');
    /** @type {string[][]} */ const windows = [];
    for (const [who, patron] of [['bruno', bruno], ['anna', anna], ['carla', carla]]) {
      const r = await lab.readerRequest(pages[who], book, {});
      expect(r.ok, `${who}: ${r.status} ${JSON.stringify(r.body)} ${r.title}`).toBe(true);
      expect(r.body.status).toBe('reserved');
      const res = lab.reservation(lab.lastReservationId(book, patron));
      windows.push([res.start, res.end]);
    }
    expect(q(book)).toBe(pos([bruno, 1], [anna, 2], [carla, 3]));
    // Nobody is promised days that are still the holder's, or another reader's.
    expect(windows[0][0] > due).toBe(true);
    expect(windows[1][0] > windows[0][1]).toBe(true);
    expect(windows[2][0] > windows[1][1]).toBe(true);
    lab.expectCoherent(book);
  });

  test('R03: a reader already in the queue gets a notice, not a second reservation', async () => {
    const { book, due } = outBook('once');
    await lab.readerRequest(pages.anna, book, { start: addDays(due, 1) });
    const again = await lab.noServerErrors(pages.anna, () => lab.readerRequest(pages.anna, book, { start: addDays(due, 3) }));
    expect(again.ok).toBe(false);
    expect(dbInt(`SELECT COUNT(*) FROM prenotazioni WHERE libro_id=${book.id} AND utente_id=${anna.id} AND stato='attiva'`)).toBe(1);
  });

  test('R04: the reader who has the book cannot queue for it', async () => {
    const { book, due } = outBook('own');
    const r = await lab.noServerErrors(pages.holder, () => lab.readerRequest(pages.holder, book, { start: addDays(due, 1) }));
    expect(r.ok).toBe(false);
    expect(q(book)).toBe('');
    expect(dbInt(`SELECT COUNT(*) FROM prestiti WHERE libro_id=${book.id} AND utente_id=${holder.id}`)).toBe(1);
  });

  test('R05: days when the only copy is out cannot be asked for, and nothing is saved', async () => {
    const { book, due } = outBook('busy');
    const cal = await lab.readerCalendar(pages.anna, book, [lab.today, addDays(lab.today, 3), due, addDays(due, 1)]);
    expect(cal.picker).toBe(true);
    expect(cal.enabled).toEqual([false, false, false, true]);
    const r = await lab.readerRequest(pages.anna, book, { start: addDays(lab.today, 3) });
    expect(r.ok).toBe(false);
    expect(lab.lastLoanId(book, anna)).toBe(0);
    expect(q(book)).toBe('');
  });

  test('R06: when the loan is overdue, other readers can still queue for the book', async () => {
    const book = lab.book('overdue-queue');
    lab.seedLoan(book, holder, { start: addDays(lab.today, -40), due: addDays(lab.today, -5), stato: 'in_ritardo' });
    const offer = await lab.bookPage(pages.anna, book);
    expect(offer.present && !offer.disabled, `the book page offers "${offer.label}"${offer.disabled ? ' (disabled)' : ''}`).toBe(true);
    const r = await lab.noServerErrors(pages.anna, () => lab.readerRequest(pages.anna, book, {}));
    expect(r.ok, `request answered ${r.status} ${JSON.stringify(r.body)} ${r.title}`).toBe(true);
    expect(q(book)).toBe(pos([anna, 1]));
  });

  test('R07: with one of two copies free the reader gets a loan request, not a place in the queue', async () => {
    const { book } = outBook('onefree', 10, 2);
    const offer = await lab.bookPage(pages.anna, book);
    expect(offer.label).toMatch(/Richiedi Prestito/i);
    const r = await lab.readerRequest(pages.anna, book, { start: lab.today });
    expect(r.ok, JSON.stringify(r.body)).toBe(true);
    expect(r.body.status).toBe('pending_approval');
    expect(q(book)).toBe('');
  });

  // ── Leaving the queue ────────────────────────────────────────────────────
  test('R08: the first in line cancels from her page and everyone moves up', async () => {
    const { book } = outBook('cancel-head');
    const [first] = queueOf(book, [anna, bruno, carla]);
    const res = await lab.readerCancelReservation(pages.anna, first);
    expect(res.offered).toBe(true);
    expect(res.url).toContain('canceled=1');
    expect(lab.reservation(first).stato).toBe('annullata');
    expect(q(book)).toBe(pos([bruno, 1], [carla, 2]));
    expect(await lab.readerCard(pages.bruno, book)).toMatch(/Posizione:?\s*1/);
    expect(await lab.readerCard(pages.carla, book)).toMatch(/Posizione:?\s*2/);
  });

  test('R09: the one in the middle cancels and the queue has no gap', async () => {
    const { book } = outBook('cancel-mid');
    const [, second] = queueOf(book, [anna, bruno, carla]);
    await lab.readerCancelReservation(pages.bruno, second);
    expect(q(book)).toBe(pos([anna, 1], [carla, 2]));
    lab.expectCoherent(book);
  });

  test('R10: the librarian cancels a reservation from the pending page and the queue moves up', async () => {
    const { book } = outBook('cancel-admin');
    const [first] = queueOf(book, [anna, bruno]);
    const res = await lab.cancelReservationAsAdmin(admin, first, 'Tessera scaduta');
    expect(res.ok, res.message).toBe(true);
    expect(lab.reservation(first).stato).toBe('annullata');
    expect(q(book)).toBe(pos([bruno, 1]));
    expect(await lab.readerCard(pages.anna, book)).not.toMatch(/Posizione/);
  });

  test('R11: a reader cannot cancel someone else\'s reservation', async () => {
    const { book } = outBook('cancel-other');
    const [first] = queueOf(book, [anna, bruno]);
    const res = await lab.readerCancelReservation(pages.bruno, first);
    expect(res.offered).toBe(false);
    // ...nor by sending the form by hand with the other reader's reservation.
    await pages.bruno.goto(`${F.BASE}/prenotazioni`);
    const status = await pages.bruno.evaluate(async (id) => {
      const token = /** @type {HTMLInputElement|null} */ (document.querySelector('input[name="csrf_token"]'))?.value || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const body = new URLSearchParams({ reservation_id: String(id), csrf_token: token });
      const r = await fetch(`${window.location.origin}${/** @type {any} */ (window).BASE_PATH || ''}/reservation/cancel`, { method: 'POST', body, credentials: 'same-origin' });
      return r.status;
    }, first);
    expect(status).toBeLessThan(500);
    expect(lab.reservation(first).stato).toBe('attiva');
    expect(q(book)).toBe(pos([anna, 1], [bruno, 2]));
  });

  // ── The book comes back ──────────────────────────────────────────────────
  test('R12: when the book is returned the first in line gets a loan on that copy and the others move up', async () => {
    const { book, loanId } = outBook('promote');
    const [first] = queueOf(book, [anna, bruno, carla]);
    await lab.returnLoan(admin, loanId);
    expect(lab.reservation(first).stato).toBe('completata');
    const promoted = lab.loan(lab.lastLoanId(book, anna));
    expect(promoted.stato).toBe('pendente');
    expect(promoted.copy).toBe(book.copyIds[0]);
    expect(q(book)).toBe(pos([bruno, 1], [carla, 2]));
    expect(lab.lastLoanId(book, bruno), 'only one reader is promoted for one copy').toBe(0);
    lab.expectCoherent(book);
  });

  test('R13: the promoted reader\'s loan is approved, collected and returned, and then the next one is promoted', async () => {
    const { book, loanId } = outBook('promote-chain');
    queueOf(book, [anna, bruno]);
    await lab.returnLoan(admin, loanId);
    const annaLoan = lab.lastLoanId(book, anna);
    expect((await lab.approve(admin, annaLoan)).ok).toBe(true);
    expect(lab.loan(annaLoan).stato).toBe('da_ritirare');
    expect(lab.lastLoanId(book, bruno), 'the second in line is promoted while the copy waits for the first').toBe(0);
    expect((await lab.confirmPickup(admin, annaLoan)).ok).toBe(true);
    await lab.returnLoan(admin, annaLoan);
    const brunoLoan = lab.lastLoanId(book, bruno);
    expect(brunoLoan).toBeGreaterThan(0);
    expect(lab.loan(brunoLoan).copy).toBe(book.copyIds[0]);
    expect(q(book)).toBe('');
    lab.expectCoherent(book);
  });

  test('R14: a reservation for later days is not turned into a loan when the book comes back early', async () => {
    const { book, loanId, due } = outBook('early');
    await lab.readerRequest(pages.anna, book, { start: addDays(due, 1) });
    const resId = lab.lastReservationId(book, anna);
    await lab.returnLoan(admin, loanId);
    expect(lab.reservation(resId).stato).toBe('attiva');
    expect(lab.lastLoanId(book, anna)).toBe(0);
    expect(q(book)).toBe(pos([anna, 1]));
    expect(await lab.readerCard(pages.anna, book)).toMatch(/Posizione:?\s*1/);
  });

  test('R15: a suspended reader at the head of the queue is passed over, and keeps her place', async () => {
    const { book, loanId } = outBook('skip-suspended');
    const [first, second] = queueOf(book, [carla, bruno]);
    db(`UPDATE utenti SET stato='sospeso' WHERE id=${carla.id}`);
    try {
      await lab.returnLoan(admin, loanId);
      expect(lab.reservation(second).stato).toBe('completata');
      expect(lab.loan(lab.lastLoanId(book, bruno)).stato).toBe('pendente');
      expect(lab.lastLoanId(book, carla)).toBe(0);
      expect(lab.reservation(first).stato).toBe('attiva');
      lab.expectCoherent(book);
    } finally { db(`UPDATE utenti SET stato='attivo' WHERE id=${carla.id}`); }
  });

  test('R16: a reader at her loan limit at the head of the queue is passed over', async () => {
    await lab.setSetting('max_active_loans_per_user', '1');
    const { book, loanId } = outBook('skip-limit');
    lab.seedLoan(lab.book('skip-limit-held'), anna, { start: addDays(lab.today, -3), due: addDays(lab.today, 20) });
    const [first, second] = queueOf(book, [anna, bruno]);
    await lab.returnLoan(admin, loanId);
    expect(lab.lastLoanId(book, anna)).toBe(0);
    expect(lab.reservation(first).stato).toBe('attiva');
    expect(lab.reservation(second).stato).toBe('completata');
    expect(lab.loan(lab.lastLoanId(book, bruno)).copy).toBe(book.copyIds[0]);
  });

  test('R17: with two copies out and three readers waiting, each return promotes exactly one', async () => {
    const book = lab.book('two-out', 2);
    const l1 = lab.seedLoan(book, holder, { start: addDays(lab.today, -5), due: addDays(lab.today, 10), copy: book.copyIds[0] });
    const extra = lab.patron('Extra');
    const l2 = lab.seedLoan(book, extra, { start: addDays(lab.today, -5), due: addDays(lab.today, 12), copy: book.copyIds[1] });
    queueOf(book, [anna, bruno, carla]);
    await lab.returnLoan(admin, l1);
    expect(dbInt(`SELECT COUNT(*) FROM prestiti WHERE libro_id=${book.id} AND stato='pendente'`)).toBe(1);
    expect(lab.loan(lab.lastLoanId(book, anna)).copy).toBe(book.copyIds[0]);
    expect(q(book)).toBe(pos([bruno, 1], [carla, 2]));
    await lab.returnLoan(admin, l2);
    expect(dbInt(`SELECT COUNT(*) FROM prestiti WHERE libro_id=${book.id} AND stato='pendente'`)).toBe(2);
    expect(lab.loan(lab.lastLoanId(book, bruno)).copy).toBe(book.copyIds[1]);
    expect(q(book)).toBe(pos([carla, 1]));
    lab.expectCoherent(book);
  });

  test('R18: when the librarian refuses the promoted reader, the next in line is promoted', async () => {
    const { book, loanId } = outBook('refuse-promoted');
    queueOf(book, [anna, bruno]);
    await lab.returnLoan(admin, loanId);
    const annaLoan = lab.lastLoanId(book, anna);
    expect((await lab.reject(admin, annaLoan, 'Tessera non valida')).ok).toBe(true);
    expect(lab.loan(annaLoan).stato).toBe('annullato');
    const brunoLoan = lab.lastLoanId(book, bruno);
    expect(brunoLoan, 'nobody was promoted after the refusal').toBeGreaterThan(0);
    expect(lab.loan(brunoLoan).copy).toBe(book.copyIds[0]);
    lab.expectCoherent(book);
  });

  test('R19: when the promoted reader gives up, the next in line is promoted', async () => {
    const { book, loanId } = outBook('giveup-promoted');
    queueOf(book, [anna, bruno]);
    await lab.returnLoan(admin, loanId);
    const annaLoan = lab.lastLoanId(book, anna);
    const res = await lab.readerCancelLoan(pages.anna, annaLoan);
    expect(res.offered).toBe(true);
    expect(lab.loan(annaLoan).attivo).toBe(0);
    expect(lab.lastLoanId(book, bruno), 'nobody was promoted after the reader gave up').toBeGreaterThan(0);
    lab.expectCoherent(book);
  });

  // ── Reservations that run out ────────────────────────────────────────────
  test('R20: a reservation whose last day has passed is cancelled by the nightly sweep and the queue moves up', async () => {
    const { book } = outBook('expire');
    const old = lab.seedReservation(book, anna, { start: addDays(lab.today, -10), end: addDays(lab.today, -1), position: 1 });
    lab.seedReservation(book, bruno, { start: addDays(lab.today, -1), end: addDays(lab.today, 20), position: 2 });
    lab.runMaintenance();
    expect(lab.reservation(old).stato).toBe('annullata');
    expect(q(book)).toBe(pos([bruno, 1]));
  });

  test('R21: nothing expires on a day the library is closed', async () => {
    const { book } = outBook('expire-closed');
    const old = lab.seedReservation(book, anna, { start: addDays(lab.today, -10), end: addDays(lab.today, -1), position: 1 });
    const closure = lab.seedClosure(addDays(lab.today, -1), lab.today);
    lab.runMaintenance();
    expect(lab.reservation(old).stato).toBe('attiva');
    // A day that was closed until yesterday only: today the sweep runs.
    lab.dropClosure(closure);
    lab.seedClosure(addDays(lab.today, -3), addDays(lab.today, -2));
    lab.runMaintenance();
    expect(lab.reservation(old).stato).toBe('annullata');
  });

  // ── The librarian's reservation pages ────────────────────────────────────
  test('R22: a reservation added by the librarian for a book that is out goes to the end of the queue', async () => {
    const { book, due } = outBook('admin-add');
    lab.seedReservation(book, anna, { start: addDays(due, 1), end: addDays(due, 15), position: 1 });
    // The same days are Anna's: refused. The days after hers: accepted, second in line.
    const same = await lab.adminReserve(admin, bruno, book, addDays(due, 1), addDays(due, 15));
    expect(same.ok).toBe(false);
    expect(q(book)).toBe(pos([anna, 1]));
    const res = await lab.adminReserve(admin, bruno, book, addDays(due, 16), addDays(due, 30));
    expect(res.ok, res.error).toBe(true);
    expect(q(book)).toBe(pos([anna, 1], [bruno, 2]));
  });

  test('R23: the librarian cannot add a second reservation of the same title for the same reader', async () => {
    const { book, due } = outBook('admin-dup');
    lab.seedReservation(book, anna, { start: addDays(due, 1), end: addDays(due, 15), position: 1 });
    const res = await lab.adminReserve(admin, anna, book, addDays(due, 3), addDays(due, 12));
    expect(res.ok).toBe(false);
    expect(res.error).toBe('duplicate');
    expect(q(book)).toBe(pos([anna, 1]));
  });

  test('R24: a reservation added by the librarian from today for a book on the shelf becomes a loan request at once', async () => {
    const book = lab.book('admin-free');
    const res = await lab.adminReserve(admin, anna, book, lab.today, addDays(lab.today, 14));
    expect(res.ok, res.error).toBe(true);
    const loanId = lab.lastLoanId(book, anna);
    expect(loanId, `the copy is on the shelf and the reservation starts today, yet the reader is left "in coda" (queue: ${q(book)})`).toBeGreaterThan(0);
  });

  test('R25: the librarian cannot move a reservation to days that are already past', async () => {
    const book = lab.book('admin-past');
    const resId = lab.seedReservation(book, anna, { start: addDays(lab.today, 5), end: addDays(lab.today, 19), position: 1 });
    const edit = await lab.noServerErrors(admin, () => lab.adminEditReservation(admin, resId, { start: addDays(lab.today, -12), end: addDays(lab.today, -5) }));
    const loanId = lab.lastLoanId(book, anna);
    const due = loanId ? lab.loan(loanId).due : '';
    expect(loanId === 0 && lab.reservation(resId).end >= lab.today,
      `edit answered "${edit.ok ? 'updated' : edit.error}"; reservation ends ${lab.reservation(resId).end}${loanId ? `; a loan request was created that is due ${due}, before today` : ''}`).toBe(true);
  });

  test('R26: a reservation marked "completata" by hand, with no loan behind it, does not announce the book as available', async () => {
    const { book, due } = outBook('admin-completed');
    const resId = lab.seedReservation(book, anna, { start: addDays(due, 1), end: addDays(due, 15), position: 1 });
    const edit = await lab.adminEditReservation(admin, resId, { stato: 'completata' });
    test.skip(!edit.ok, `the edit form refuses the change (${edit.error})`);
    lab.runMaintenance();
    expect(lab.lastLoanId(book, anna)).toBe(0);
    expect(db(`SELECT notifica_inviata FROM prenotazioni WHERE id=${resId}`), 'the "book available" notice went out for a book that is still out').toBe('0');
  });

  // ── Dates, closed days and proposals ─────────────────────────────────────
  test('R27: a closed day cannot be chosen as the first day of a reservation', async () => {
    const { book, due } = outBook('closed-start');
    const closedDay = addDays(due, 2);
    lab.seedClosure(closedDay);
    const cal = await lab.readerCalendar(pages.anna, book, [addDays(due, 1), closedDay, addDays(due, 3)]);
    expect(cal.availability.closed_dates || []).toContain(closedDay);
    expect(cal.enabled).toEqual([true, false, true]);
  });

  test('R28: when the days asked for do not fit, the shorter period proposed can really be booked', async () => {
    // Saturday and Sunday closed; Bruno has the copy from a Monday.
    db("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans','closed_weekdays','0,6') ON DUPLICATE KEY UPDATE setting_value='0,6'");
    lab.closedWeekdays = new Set([0, 6]);
    let monday = addDays(lab.today, 8);
    while (weekday(monday) !== 1) monday = addDays(monday, 1);
    const book = lab.book('proposal');
    lab.seedLoan(book, bruno, { start: monday, due: addDays(monday, 11), stato: 'prenotato', origine: 'richiesta' });
    db(`UPDATE copie SET stato='disponibile' WHERE id=${book.copyIds[0]}`);
    lab.recount(book);
    const start = addDays(monday, -6);
    const first = await lab.readerRequest(pages.anna, book, { start });
    if (first.ok) {
      expect(first.pickedEnd).toBeTruthy();
      expect(lab.isOpen(first.pickedEnd), 'the calendar proposes an open return day').toBe(true);
      expect(first.pickedEnd < monday, 'the accepted period ends before the next loan').toBe(true);
      expect(first.body.end_date).toBe(first.pickedEnd);
      lab.expectCoherent(book);
      return;
    }
    const proposed = first.body.max_end_date || '';
    expect(proposed, `refusal carries no proposal: ${JSON.stringify(first.body)}`).not.toBe('');
    expect(lab.isOpen(proposed), `the proposed last day ${proposed} is a day the library is closed`).toBe(true);
    expect(proposed < monday).toBe(true);
    const second = await lab.readerRequest(pages.anna, book, { start, end: proposed });
    expect(second.ok, `the proposed period ${start} → ${proposed} was refused too: ${JSON.stringify(second.body)}`).toBe(true);
    lab.expectCoherent(book);
  });

  test('R29: two readers ask for the last copy for the same days; only one of the requests can be approved', async () => {
    const book = lab.book('last-copy');
    const a = await lab.readerRequest(pages.anna, book, { start: lab.today });
    const b = await lab.readerRequest(pages.bruno, book, { start: lab.today });
    expect(a.ok, JSON.stringify(a.body)).toBe(true);
    const annaLoan = lab.lastLoanId(book, anna);
    expect((await lab.approve(admin, annaLoan)).ok).toBe(true);
    if (b.ok && lab.lastLoanId(book, bruno)) {
      const brunoLoan = lab.lastLoanId(book, bruno);
      const second = await lab.noServerErrors(admin, () => lab.approve(admin, brunoLoan));
      expect(second.ok, 'both requests for the only copy were approved').toBe(false);
      expect(['pendente', 'annullato', 'scaduto']).toContain(lab.loan(brunoLoan).stato);
    }
    expect(dbInt(`SELECT COUNT(*) FROM prestiti WHERE libro_id=${book.id} AND attivo=1`)).toBe(1);
    lab.expectCoherent(book);
  });

  test('R30: a long sequence on one title leaves the copy, the counter and the queue consistent at every step', async () => {
    const { book, loanId } = outBook('storm');
    queueOf(book, [anna, bruno, carla]);
    const step = () => lab.expectCoherent(book);
    await lab.readerCancelReservation(pages.bruno, lab.lastReservationId(book, bruno)); step();
    await lab.returnLoan(admin, loanId); step();
    const annaLoan = lab.lastLoanId(book, anna);
    expect(annaLoan).toBeGreaterThan(0);
    await lab.approve(admin, annaLoan); step();
    await lab.confirmPickup(admin, annaLoan); step();
    expect(lab.loan(annaLoan).stato).toBe('in_corso');
    await lab.renew(admin, annaLoan); step();
    await lab.returnLoan(admin, annaLoan); step();
    const carlaLoan = lab.lastLoanId(book, carla);
    expect(carlaLoan).toBeGreaterThan(0);
    await lab.reject(admin, carlaLoan, 'fine prova'); step();
    expect(q(book)).toBe('');
    expect(dbInt(`SELECT COUNT(*) FROM prestiti WHERE libro_id=${book.id} AND attivo=1`)).toBe(0);
    expect(lab.available(book)).toBe(1);
    expect(lab.copyState(book.copyIds[0])).toBe('disponibile');
    expect((await lab.bookPage(pages.bruno, book)).label).toMatch(/Richiedi Prestito/i);
  });
});
