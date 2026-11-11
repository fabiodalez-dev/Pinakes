// @ts-check
// Loans 120 — part 4 of 4: dates at the edge, deadlines, automatic approval
// and what each page shows (30 tests).
//
// First and last days of a period, loans across New Year, the longest and the
// shortest loan, a pickup deadline that meets the due date, the library
// closed today, requests approved by the system, copies chosen by inventory
// code, titles withdrawn from the catalogue.
//
// Run: /tmp/run-e2e.sh loans-120-boundaries.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const { createLab, addDays, weekday } = require('./helpers/loan-lab');
const { flushCache } = require('./helpers/flush-cache');

const lab = createLab('LB');
const { F, db, dbInt } = lab;

test.skip(!F.CONFIGURED, 'E2E credentials not configured');

const it = (iso) => `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}`;

test.describe('Loans 120 · boundaries, deadlines and automatic approval', () => {
  test.describe.configure({ timeout: 180000 });
  /** @type {import('@playwright/test').Page} */ let admin;
  /** @type {import('@playwright/test').Page} */ let reader;
  /** @type {import('@playwright/test').Page} */ let reader2;
  /** @type {any[]} */ const contexts = [];
  let anna; let bruno; let carla;

  test.beforeAll(async ({ browser }) => {
    await lab.setup();
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
    reader = await open(anna.email, anna.password);
    reader2 = await open(bruno.email, bruno.password);
  });
  test.beforeEach(async () => { await lab.reset(); });
  test.afterAll(async () => {
    await lab.teardown();
    await Promise.all(contexts.map((c) => c.close()));
  });

  // ── What the forms propose ───────────────────────────────────────────────
  test('B01: the desk form proposes today and today plus the loan duration, in the library\'s time zone', async () => {
    const loan = await lab.deskLoan(admin, anna, lab.book('proposal'));
    expect(loan.proposedStart).toBe(lab.today);
    expect(loan.proposedDue).toBe(addDays(lab.today, lab.loanDays));
    const row = lab.loan(loan.id);
    expect([row.stato, row.start, row.due]).toEqual(['in_corso', lab.today, addDays(lab.today, lab.loanDays)]);
  });

  test('B02: a shorter loan duration changes what the desk and the reader\'s calendar propose, not the loans already out', async () => {
    const old = await lab.deskLoan(admin, anna, lab.book('duration-old'));
    await lab.setSetting('loan_duration_days', '14');
    const desk = await lab.deskLoan(admin, bruno, lab.book('duration-desk'));
    expect(desk.proposedDue).toBe(addDays(lab.today, 14));
    expect(lab.loan(desk.id).due).toBe(addDays(lab.today, 14));
    const book = lab.book('duration-reader');
    const r = await lab.readerRequest(reader, book, { start: addDays(lab.today, 1) });
    expect(r.ok, JSON.stringify(r.body)).toBe(true);
    expect(lab.loan(lab.lastLoanId(book, anna)).due).toBe(addDays(lab.today, 15));
    expect(lab.loan(old.id).due).toBe(addDays(lab.today, 30));
  });

  test('B03: a ninety-day loan across New Year is due exactly ninety days later', async () => {
    await lab.setSetting('loan_duration_days', '90');
    const loan = await lab.deskLoan(admin, anna, lab.book('ninety'));
    expect(loan.ok, loan.error).toBe(true);
    expect(lab.loan(loan.id).due).toBe(addDays(lab.today, 90));
  });

  // ── The reader's calendar ────────────────────────────────────────────────
  test('B04: the reader\'s calendar refuses yesterday and accepts today', async () => {
    const book = lab.book('past');
    const cal = await lab.readerCalendar(reader, book, [addDays(lab.today, -1), lab.today, addDays(lab.today, 1)]);
    expect(cal.enabled).toEqual([false, true, true]);
    const r = await lab.readerRequest(reader, book, { start: addDays(lab.today, -1) });
    expect(r.ok).toBe(false);
    expect(lab.lastLoanId(book, anna)).toBe(0);
  });

  test('B05: a request cannot last longer than the longest loan the library allows, and can last exactly that', async () => {
    await lab.setSetting('loan_duration_days', '10');
    await lab.setSetting('max_loan_duration_days', '20');
    const book = lab.book('toolong');
    const start = addDays(lab.today, 1);
    const long = await lab.noServerErrors(reader, () => lab.readerRequest(reader, book, { start, end: addDays(start, 25) }));
    expect(long.ok).toBe(false);
    expect(lab.lastLoanId(book, anna)).toBe(0);
    const exact = await lab.readerRequest(reader, book, { start, end: addDays(start, 20) });
    expect(exact.ok, `a ${20}-day request was refused: ${JSON.stringify(exact.body)} picked ${exact.pickedEnd}`).toBe(true);
    expect(lab.loan(lab.lastLoanId(book, anna)).due).toBe(addDays(start, 20));
  });

  test('B06: a request for a single day is saved as one day', async () => {
    const book = lab.book('singleday');
    const day = addDays(lab.today, 2);
    const r = await lab.noServerErrors(reader, () => lab.readerRequest(reader, book, { start: day, end: day }));
    expect(r.pickedEnd).toBe(day);
    expect(r.ok, JSON.stringify(r.body)).toBe(true);
    const loan = lab.loan(lab.lastLoanId(book, anna));
    expect([loan.start, loan.due]).toEqual([day, day]);
  });

  test('B07: a request whose last day is a weekly closed day is saved with the next open day', async () => {
    const start = addDays(lab.today, 1);
    const raw = addDays(start, lab.loanDays);
    db(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans','closed_weekdays','${weekday(raw)}') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
    lab.closedWeekdays = new Set([weekday(raw)]);
    await flushCache();
    test.skip(!lab.isOpen(start), 'tomorrow is the weekly closed day in this calendar');
    const book = lab.book('weeklyend');
    const r = await lab.readerRequest(reader, book, { start });
    expect(r.ok, JSON.stringify(r.body)).toBe(true);
    const loan = lab.loan(lab.lastLoanId(book, anna));
    expect(loan.due).toBe(addDays(raw, 1));
    expect(r.body.end_date || loan.due).toBe(loan.due);
  });

  test('B08: when the library is closed today the reader\'s first possible day is the next open one', async () => {
    lab.seedClosure(lab.today, addDays(lab.today, 1));
    const book = lab.book('closedtoday');
    const cal = await lab.readerCalendar(reader, book, [lab.today, addDays(lab.today, 1), addDays(lab.today, 2)]);
    expect(cal.enabled).toEqual([false, false, true]);
    const r = await lab.readerRequest(reader, book, {});
    expect(r.ok, JSON.stringify(r.body)).toBe(true);
    const loan = lab.loan(lab.lastLoanId(book, anna));
    expect(loan.start).toBe(addDays(lab.today, 2));
    expect(lab.isOpen(loan.due)).toBe(true);
  });

  // ── First and last day of a period ───────────────────────────────────────
  test('B09: the reader\'s calendar blocks every day of a loan, first and last included, and frees the day after', async () => {
    const book = lab.book('inclusive');
    const start = addDays(lab.today, 5);
    const due = addDays(lab.today, 12);
    lab.seedLoan(book, bruno, { start, due, stato: 'prenotato', origine: 'richiesta' });
    db(`UPDATE copie SET stato='disponibile' WHERE id=${book.copyIds[0]}`);
    lab.recount(book);
    const cal = await lab.readerCalendar(reader, book, [addDays(start, -1), start, addDays(start, 3), due, addDays(due, 1)]);
    expect(cal.picker).toBe(true);
    expect(cal.enabled).toEqual([true, false, false, false, true]);
    const blocked = cal.availability.unavailable_dates || [];
    expect(blocked).toContain(start);
    expect(blocked).toContain(due);
    expect(blocked).not.toContain(addDays(due, 1));
    expect(blocked).not.toContain(addDays(start, -1));
  });

  test('B10: at the desk the next loan of a copy cannot start on the day the current one is due, and can start the day after', async () => {
    const book = lab.book('handover');
    const due = addDays(lab.today, 10);
    lab.seedLoan(book, anna, { start: addDays(lab.today, -4), due });
    const sameDay = await lab.deskLoan(admin, bruno, book, { start: due, due: addDays(due, 10) });
    expect(sameDay.ok && sameDay.typedStart === due, 'a second loan starts on the day the first is still out').toBe(false);
    expect(lab.lastLoanId(book, bruno)).toBe(0);
    const nextDay = await lab.deskLoan(admin, bruno, book, { start: addDays(due, 1), due: addDays(due, 11) });
    expect(nextDay.ok, `the day after the due date was refused: ${nextDay.error}`).toBe(true);
    expect(lab.loan(nextDay.id).stato).toBe('prenotato');
    lab.expectCoherent(book);
  });

  test('B11: a reader can have the book for the days before someone else\'s booking begins', async () => {
    const book = lab.book('before-booking');
    const start = addDays(lab.today, 9);
    lab.seedLoan(book, bruno, { start, due: addDays(start, 14), stato: 'prenotato', origine: 'richiesta' });
    db(`UPDATE copie SET stato='disponibile' WHERE id=${book.copyIds[0]}`);
    lab.recount(book);
    const r = await lab.readerRequest(reader, book, { start: lab.today, end: addDays(start, -1) });
    expect(r.ok, `refused although the copy is free until ${addDays(start, -1)}: ${JSON.stringify(r.body)} (picked end "${r.pickedEnd}")`).toBe(true);
    const loan = lab.loan(lab.lastLoanId(book, anna));
    expect(loan.due < start).toBe(true);
    lab.expectCoherent(book);
  });

  // ── Copies ───────────────────────────────────────────────────────────────
  test('B12: a loan made by inventory code goes out on exactly that copy', async () => {
    const book = lab.book('bycopy', 3);
    const loan = await lab.deskLoan(admin, anna, book, { copyCode: F.copyCodeOf(book.copyIds[2]) });
    expect(loan.ok, loan.error).toBe(true);
    expect(lab.loan(loan.id).copy).toBe(book.copyIds[2]);
    expect(lab.copyState(book.copyIds[2])).toBe('prestato');
    expect(lab.copyState(book.copyIds[0])).toBe('disponibile');
    expect(lab.available(book)).toBe(2);
  });

  test('B13: the inventory code of a copy that is out is refused, even with other copies on the shelf', async () => {
    const book = lab.book('copyout', 2);
    lab.seedLoan(book, anna, { start: addDays(lab.today, -3), due: addDays(lab.today, 20), copy: book.copyIds[0] });
    const loan = await lab.deskLoan(admin, bruno, book, { copyCode: F.copyCodeOf(book.copyIds[0]) });
    expect(loan.ok).toBe(false);
    expect(lab.lastLoanId(book, bruno)).toBe(0);
    lab.expectCoherent(book);
  });

  test('B14: a copy in maintenance is not counted: with the other one out the title can only be reserved', async () => {
    const book = lab.book('maintenance', 2);
    db(`UPDATE copie SET stato='manutenzione' WHERE id=${book.copyIds[1]}`);
    lab.seedLoan(book, bruno, { start: addDays(lab.today, -3), due: addDays(lab.today, 10), copy: book.copyIds[0] });
    await flushCache();
    expect(lab.available(book)).toBe(0);
    const offer = await lab.bookPage(reader, book);
    expect(offer.label).not.toMatch(/Richiedi Prestito/i);
    const desk = await lab.deskLoan(admin, carla, book);
    expect(desk.ok).toBe(false);
    expect(lab.copyState(book.copyIds[1])).toBe('manutenzione');
  });

  test('B15: a title withdrawn from the catalogue disappears from the reader\'s pages and cannot be asked for', async () => {
    const book = lab.book('withdrawn');
    db(`UPDATE libri SET deleted_at=NOW() WHERE id=${book.id}`);
    await flushCache();
    const res = await reader.goto(`${F.BASE}/libro/${book.id}`);
    expect(res?.status()).toBe(404);
    const api = await reader.evaluate(async (id) => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const base = `${window.location.origin}${/** @type {any} */ (window).BASE_PATH || ''}`;
      const a = await fetch(`${base}/api/libro/${id}/availability`, { credentials: 'same-origin' });
      const r = await fetch(`${base}/api/libro/${id}/reservation`, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ start_date: '2099-01-01', csrf_token: csrf }) });
      return [a.status, r.status];
    }, book.id);
    expect(api[0]).toBe(404);
    expect(api[1]).toBeGreaterThanOrEqual(400);
    expect(api[1]).toBeLessThan(500);
    expect(lab.lastLoanId(book, anna)).toBe(0);
    expect(lab.lastReservationId(book, anna)).toBe(0);
  });

  test('B16: a copy returned as lost with readers waiting does not promote anyone', async () => {
    const book = lab.book('lost-queue');
    const loanId = lab.seedLoan(book, carla, { start: addDays(lab.today, -5), due: addDays(lab.today, 10) });
    const resId = lab.seedReservation(book, anna, { start: addDays(lab.today, -1), end: addDays(lab.today, 20), position: 1 });
    const back = await lab.noServerErrors(admin, () => lab.returnLoan(admin, loanId, { stato: 'perso' }));
    expect(back.ok).toBe(true);
    expect(lab.loan(loanId).stato).toBe('perso');
    expect(lab.lastLoanId(book, anna), 'a reader was promoted onto a lost copy').toBe(0);
    expect(lab.reservation(resId).stato).toBe('attiva');
    lab.expectCoherent(book);
  });

  // ── Pickup deadlines ─────────────────────────────────────────────────────
  test('B17: with one day to collect, the deadline is tomorrow', async () => {
    await lab.setSetting('pickup_expiry_days', '1');
    const loan = await lab.deskLoan(admin, anna, lab.book('pickup-one'), { immediate: false });
    expect(lab.loan(loan.id).stato).toBe('da_ritirare');
    expect(lab.loan(loan.id).pickup).toBe(addDays(lab.today, 1));
  });

  test('B18: the pickup deadline is never after the day the book is due back', async () => {
    const due = addDays(lab.today, 2);
    const loan = await lab.deskLoan(admin, anna, lab.book('pickup-cap'), { immediate: false, due });
    expect(loan.ok, loan.error).toBe(true);
    const row = lab.loan(loan.id);
    expect(row.due).toBe(due);
    expect(row.pickup).toBe(due);
  });

  test('B19: a pickup cannot be confirmed on a day the library is closed, and can the day it reopens', async () => {
    const book = lab.book('pickup-closed');
    const loan = await lab.deskLoan(admin, anna, book, { immediate: false });
    const closure = lab.seedClosure(lab.today);
    const refused = await lab.confirmPickup(admin, loan.id);
    expect(refused.ok).toBe(false);
    expect(refused.message).toMatch(/chius/i);
    expect(lab.loan(loan.id).stato).toBe('da_ritirare');
    lab.dropClosure(closure);
    lab.closed = new Set();
    const done = await lab.confirmPickup(admin, loan.id);
    expect(done.ok, done.message).toBe(true);
    expect(lab.loan(loan.id).stato).toBe('in_corso');
  });

  test('B20: a request approved on a closed day is booked from the next open day', async () => {
    const book = lab.book('approve-closed');
    await lab.readerRequest(reader, book, { start: lab.today });
    const id = lab.lastLoanId(book, anna);
    lab.seedClosure(lab.today, addDays(lab.today, 1));
    const approved = await lab.approve(admin, id);
    expect(approved.ok, approved.message).toBe(true);
    const loan = lab.loan(id);
    expect(loan.stato).toBe('prenotato');
    expect(loan.start).toBe(addDays(lab.today, 2));
    expect(lab.isOpen(loan.due)).toBe(true);
    lab.expectCoherent(book);
  });

  test('B21: a pickup past its deadline expires overnight and the first reader waiting is promoted', async () => {
    const book = lab.book('pickup-queue');
    const loanId = lab.seedLoan(book, carla, { start: addDays(lab.today, -5), due: addDays(lab.today, 20), stato: 'da_ritirare', pickup: addDays(lab.today, -1) });
    const resId = lab.seedReservation(book, anna, { start: addDays(lab.today, -1), end: addDays(lab.today, 20), position: 1 });
    lab.runMaintenance();
    expect(lab.loan(loanId).stato).toBe('scaduto');
    expect(lab.reservation(resId).stato).toBe('completata');
    const promoted = lab.loan(lab.lastLoanId(book, anna));
    expect(promoted.copy).toBe(book.copyIds[0]);
    lab.expectCoherent(book);
  });

  test('B22: the reader gives up a pickup after its deadline: the loan is closed and the copy is free', async () => {
    const book = lab.book('pickup-late-cancel');
    const loanId = lab.seedLoan(book, anna, { start: addDays(lab.today, -5), due: addDays(lab.today, 20), stato: 'da_ritirare', pickup: addDays(lab.today, -1) });
    const res = await lab.noServerErrors(reader, () => lab.readerCancelLoan(reader, loanId));
    expect(res.offered).toBe(true);
    const loan = lab.loan(loanId);
    expect(loan.attivo).toBe(0);
    expect(['annullato', 'scaduto']).toContain(loan.stato);
    expect(lab.copyState(book.copyIds[0])).toBe('disponibile');
    lab.expectCoherent(book);
  });

  // ── Requests nobody answered ─────────────────────────────────────────────
  test('B23: a request whose days have all passed without an answer is closed as expired', async () => {
    const book = lab.book('stale');
    const id = lab.seedLoan(book, anna, { start: addDays(lab.today, -12), due: addDays(lab.today, -2), stato: 'pendente', copy: null, origine: 'richiesta' });
    lab.runMaintenance();
    expect(lab.loan(id).stato).toBe('scaduto');
    expect(lab.available(book)).toBe(1);
    expect(await lab.pendingOffers(admin, 'approve-btn', id)).toBe(false);
  });

  test('B24: approving a request whose days have passed gives the same explanation with or without closed days in the calendar', async () => {
    const stale = (tag) => lab.seedLoan(lab.book(tag), anna, { start: addDays(lab.today, -12), due: addDays(lab.today, -2), stato: 'pendente', copy: null, origine: 'richiesta' });
    const plain = await lab.approve(admin, stale('stale-plain'));
    test.skip(plain.missing, 'the pending page no longer offers an elapsed request');
    expect(plain.ok).toBe(false);
    lab.seedClosure(addDays(lab.today, 40));
    const withRules = await lab.approve(admin, stale('stale-rules'));
    expect(withRules.ok).toBe(false);
    expect(withRules.message, `without closures: "${plain.message}"`).toBe(plain.message);
  });

  // ── Automatic approval ───────────────────────────────────────────────────
  test('B25: with automatic approval a request for today is ready to collect at once, with a copy and a deadline', async () => {
    await lab.setSetting('auto_approve_requests', '1');
    const book = lab.book('auto-today');
    const r = await lab.readerRequest(reader, book, { start: lab.today });
    expect(r.ok, JSON.stringify(r.body)).toBe(true);
    expect(r.body.auto_approved).toBe(true);
    const loan = lab.loan(lab.lastLoanId(book, anna));
    expect(loan.stato).toBe('da_ritirare');
    expect(loan.copy).toBe(book.copyIds[0]);
    expect(loan.pickup).toBe(addDays(lab.today, lab.pickupDays));
    expect(lab.available(book)).toBe(0);
    // The second reader finds it taken.
    expect((await lab.bookPage(reader2, book)).label).not.toMatch(/Richiedi Prestito/i);
  });

  test('B26: with automatic approval a request for next week is booked, not ready to collect', async () => {
    await lab.setSetting('auto_approve_requests', '1');
    const book = lab.book('auto-later');
    const start = addDays(lab.today, 7);
    const r = await lab.readerRequest(reader, book, { start });
    expect(r.ok, JSON.stringify(r.body)).toBe(true);
    const loan = lab.loan(lab.lastLoanId(book, anna));
    expect(loan.stato).toBe('prenotato');
    expect(loan.start).toBe(start);
    expect(loan.pickup).toBe('-');
    lab.expectCoherent(book);
  });

  test('B27: automatic approval never gives the last copy to two readers', async () => {
    await lab.setSetting('auto_approve_requests', '1');
    const book = lab.book('auto-two');
    const a = await lab.readerRequest(reader, book, { start: lab.today });
    expect(a.ok).toBe(true);
    await lab.noServerErrors(reader2, () => lab.readerRequest(reader2, book, {}));
    expect(dbInt(`SELECT COUNT(*) FROM prestiti WHERE libro_id=${book.id} AND attivo=1 AND copia_id=${book.copyIds[0]} AND stato IN ('da_ritirare','in_corso')`)).toBe(1);
    lab.expectCoherent(book);
  });

  // ── What the pages show ──────────────────────────────────────────────────
  test('B28: the reader\'s page shows the due date of a loan and marks a late one', async () => {
    const onTime = lab.book('shown-ontime');
    const late = lab.book('shown-late');
    const due = addDays(lab.today, 9);
    lab.seedLoan(onTime, anna, { start: addDays(lab.today, -3), due });
    lab.seedLoan(late, anna, { start: addDays(lab.today, -40), due: addDays(lab.today, -4), stato: 'in_ritardo' });
    const cardOnTime = await lab.readerCard(reader, onTime);
    expect(cardOnTime).toContain(it(due));
    expect(cardOnTime).not.toMatch(/in ritardo/i);
    const cardLate = await lab.readerCard(reader, late);
    expect(cardLate).toMatch(/ritardo/i);
    await expect(reader.locator('.item-card').filter({ hasText: late.title }).locator('.badge-overdue').first()).toBeVisible();
  });

  test('B29: the reader\'s page shows the last day to collect a book that is ready', async () => {
    const book = lab.book('shown-pickup');
    const loan = await lab.deskLoan(admin, anna, book, { immediate: false });
    const deadline = lab.loan(loan.id).pickup;
    const card = await lab.readerCard(reader, book);
    expect(card, `the card does not mention ${it(deadline)}: "${card}"`).toContain(it(deadline));
  });

  test('B30: on the pending page every open loan appears in exactly one section, the one of its state', async () => {
    const mk = (tag, patron, opts) => { const b = lab.book(tag); return { book: b, id: lab.seedLoan(b, patron, opts) }; };
    const late = mk('sec-late', anna, { start: addDays(lab.today, -40), due: addDays(lab.today, -4), stato: 'in_ritardo' });
    const ready = mk('sec-ready', bruno, { start: lab.today, due: addDays(lab.today, 20), stato: 'da_ritirare', pickup: addDays(lab.today, 3) });
    const asked = mk('sec-asked', carla, { start: lab.today, due: addDays(lab.today, 20), stato: 'pendente', copy: null, origine: 'richiesta' });
    const booked = mk('sec-booked', anna, { start: addDays(lab.today, 6), due: addDays(lab.today, 20), stato: 'prenotato', origine: 'richiesta' });
    const out = mk('sec-out', bruno, { start: addDays(lab.today, -3), due: addDays(lab.today, 20) });
    await admin.goto(`${F.BASE}/admin/loans/pending`);
    await admin.waitForLoadState('domcontentloaded');
    /** Heading of the section that holds a card with this title, for every card found. */
    const sectionsOf = (title) => admin.evaluate((t) => {
      const found = [];
      for (const h3 of Array.from(document.querySelectorAll('h3'))) {
        if (!(h3.textContent || '').includes(t)) continue;
        let node = /** @type {Element|null} */ (h3);
        let heading = '';
        while (node && !heading) {
          let prev = node.previousElementSibling;
          while (prev && !heading) { heading = (prev.matches('h2') ? prev.textContent : prev.querySelector('h2')?.textContent) || ''; prev = prev.previousElementSibling; }
          if (!heading) heading = node.parentElement?.querySelector(':scope > * h2, :scope > h2')?.textContent || '';
          node = node.parentElement;
        }
        found.push(heading.trim());
      }
      return found;
    }, title);
    expect(await sectionsOf(late.book.title)).toEqual(['Prestiti in Ritardo']);
    expect(await sectionsOf(ready.book.title)).toEqual(['Ritiri da Confermare']);
    expect(await sectionsOf(asked.book.title)).toEqual(['Richieste in Attesa']);
    expect(await sectionsOf(booked.book.title)).toEqual(['Prestiti Programmati']);
    expect(await sectionsOf(out.book.title)).toEqual(['Prestiti in Corso']);
  });
});
