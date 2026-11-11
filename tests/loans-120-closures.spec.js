// @ts-check
// Loans 120 — part 1 of 4: closing days, opening days and holidays (30 tests).
//
// The library closes for a day, for a stretch, every week on some days, every
// year on a date, in one branch only; before the loan is made or after it.
// Each test lends at the desk (or starts from a loan lent days ago) and
// configures the calendar from Admin → Calendar, as the librarian does. The
// expected dates are computed in the test from the closed days it declared.
//
// Run: /tmp/run-e2e.sh loans-120-closures.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const { createLab, addDays, weekday } = require('./helpers/loan-lab');

const lab = createLab('LC');
const { F, db, dbInt } = lab;

test.skip(!F.CONFIGURED, 'E2E credentials not configured');

test.describe('Loans 120 · closures, opening days and holidays', () => {
  test.describe.configure({ timeout: 180000 });
  /** @type {import('@playwright/test').Page} */ let page;
  let ctx;
  let patron; let other;

  test.beforeAll(async ({ browser }) => {
    await lab.setup({ features: { fines: '1', automatic_fines: '1' } });
    patron = lab.patron('A');
    other = lab.patron('B');
    ctx = await browser.newContext();
    page = await ctx.newPage();
    await F.loginAsAdmin(page);
  });
  test.beforeEach(async () => { await lab.reset(); });
  test.afterAll(async () => {
    await lab.teardown();
    await ctx?.close();
  });

  // ── The due date meets a closure that is already in the calendar ─────────
  test('C01: a three-day closure over the due date moves it to the day after the closure ends', async () => {
    const raw = addDays(lab.today, lab.loanDays);
    await lab.addClosure(page, addDays(raw, -1), addDays(raw, 1));
    const loan = await lab.deskLoan(page, patron, lab.book('range'));
    expect(loan.ok, loan.error).toBe(true);
    expect(loan.proposedDue).toBe(addDays(raw, 2));
    expect(lab.loan(loan.id).due).toBe(addDays(raw, 2));
  });

  test('C02: a closure that starts the day after the due date moves nothing', async () => {
    const raw = addDays(lab.today, lab.loanDays);
    await lab.addClosure(page, addDays(raw, 1), addDays(raw, 5));
    const loan = await lab.deskLoan(page, patron, lab.book('after'));
    expect(lab.loan(loan.id).due).toBe(raw);
  });

  test('C03: a closure that ends the day before the due date moves nothing', async () => {
    const raw = addDays(lab.today, lab.loanDays);
    await lab.addClosure(page, addDays(raw, -6), addDays(raw, -1));
    const loan = await lab.deskLoan(page, patron, lab.book('before'));
    expect(lab.loan(loan.id).due).toBe(raw);
  });

  test('C04: two separate closures on consecutive days are skipped together', async () => {
    const raw = addDays(lab.today, lab.loanDays);
    await lab.addClosure(page, raw);
    await lab.addClosure(page, addDays(raw, 1));
    const loan = await lab.deskLoan(page, patron, lab.book('adjacent'));
    expect(lab.loan(loan.id).due).toBe(addDays(raw, 2));
  });

  test('C05: a closure followed by two weekly closed days moves the due date past all three', async () => {
    const raw = addDays(lab.today, lab.loanDays);
    await lab.addClosure(page, raw);
    await lab.setWeekdays(page, [weekday(addDays(raw, 1)), weekday(addDays(raw, 2))]);
    const loan = await lab.deskLoan(page, patron, lab.book('chain'));
    expect(lab.loan(loan.id).due).toBe(addDays(raw, 3));
    expect(lab.loan(loan.id).due).toBe(lab.nextOpen(raw));
  });

  test('C06: with the library open one day a week, the due date lands on that day', async () => {
    const raw = addDays(lab.today, lab.loanDays);
    const openDay = weekday(addDays(raw, 4));
    await lab.setWeekdays(page, [0, 1, 2, 3, 4, 5, 6].filter((w) => w !== openDay));
    const loan = await lab.deskLoan(page, patron, lab.book('oneday'));
    expect(loan.ok, loan.error).toBe(true);
    const due = lab.loan(loan.id).due;
    expect(weekday(due)).toBe(openDay);
    expect(due).toBe(addDays(raw, 4));
  });

  test('C07: closing all seven days of the week is refused and the previous days stay', async () => {
    await lab.setWeekdays(page, [0]);
    const stored = await lab.noServerErrors(page, () => lab.setWeekdays(page, [0, 1, 2, 3, 4, 5, 6]));
    expect(stored).toEqual([0]);
    const loan = await lab.deskLoan(page, patron, lab.book('seven'));
    expect(loan.ok, loan.error).toBe(true);
    expect(weekday(lab.loan(loan.id).due)).not.toBe(0);
  });

  test('C08: a closure repeated every year, entered with last year\'s date, closes the due date this year', async () => {
    const raw = addDays(lab.today, lab.loanDays);
    const lastYear = `${Number(raw.slice(0, 4)) - 1}${raw.slice(4)}`;
    const closure = await lab.addClosure(page, lastYear, '', { yearly: true });
    expect(closure.saved).toBe(true);
    lab.markClosed(raw);
    const loan = await lab.deskLoan(page, patron, lab.book('yearly'));
    expect(lab.loan(loan.id).due).toBe(addDays(raw, 1));
  });

  test('C09: a closure whose end is before its start is never stored inverted', async () => {
    const raw = addDays(lab.today, lab.loanDays);
    await lab.noServerErrors(page, () => lab.addClosure(page, addDays(raw, 2), raw, { model: false }));
    expect(dbInt(`SELECT COUNT(*) FROM calendario_chiusure WHERE descrizione LIKE '${lab.TAG}%' AND data_fine < data_inizio`)).toBe(0);
    // Whatever was kept of it, the due date of a new loan is an open day by the stored calendar.
    const loan = await lab.deskLoan(page, patron, lab.book('inverted'));
    const due = lab.loan(loan.id).due;
    expect(dbInt(`SELECT COUNT(*) FROM calendario_chiusure WHERE attiva=1 AND descrizione LIKE '${lab.TAG}%' AND '${due}' BETWEEN data_inizio AND data_fine`)).toBe(0);
  });

  test('C10: a yearly closure from 31 December to 1 January moves a loan due on New Year\'s Eve to 2 January', async () => {
    const year = Number(lab.today.slice(0, 4));
    let eve = `${year}-12-31`;
    if (eve <= addDays(lab.today, 1)) eve = `${year + 1}-12-31`;
    test.skip(eve > addDays(lab.today, lab.maxLoanDays - 3), 'New Year\'s Eve is further than a loan can last');
    const book = lab.book('newyear');
    const loan = await lab.deskLoan(page, patron, book, { due: eve });
    expect(loan.ok, loan.error).toBe(true);
    expect(lab.loan(loan.id).due).toBe(eve);
    const closure = await lab.addClosure(page, `${Number(eve.slice(0, 4)) - 2}-12-31`, `${Number(eve.slice(0, 4)) - 1}-01-01`, { yearly: true });
    expect(closure.saved).toBe(true);
    expect(lab.loan(loan.id).due).toBe(addDays(eve, 2));
    // From now on the desk calendar refuses both days and takes 2 January.
    await page.goto(`${F.BASE}/admin/loans/create`);
    await expect.poll(() => page.evaluate(() => !!(/** @type {any} */ (document.getElementById('data_scadenza')))._flatpickr)).toBe(true);
    const enabled = await page.evaluate((days) => days.map((d) => /** @type {any} */ (document.getElementById('data_scadenza'))._flatpickr.isEnabled(d)), [eve, addDays(eve, 1), addDays(eve, 2)]);
    expect(enabled).toEqual([false, false, true]);
  });

  test('C11: Christmas Eve closed by hand, Christmas and St Stephen from the national preset: one stretch', async () => {
    const year = Number(lab.today.slice(0, 4));
    let eve = `${year}-12-24`;
    if (eve <= addDays(lab.today, 1)) eve = `${year + 1}-12-24`;
    test.skip(eve > addDays(lab.today, lab.maxLoanDays - 5), 'Christmas is further than a loan can last');
    const book = lab.book('xmas');
    const loan = await lab.deskLoan(page, patron, book, { due: eve });
    expect(loan.ok, loan.error).toBe(true);
    expect(lab.loan(loan.id).due).toBe(eve);
    await lab.applyItalianPreset(page, ['christmas', 'st_stephen']);
    lab.markClosed(addDays(eve, 1), addDays(eve, 2));
    // Nothing closes the Eve yet.
    expect(lab.loan(loan.id).due).toBe(eve);
    await lab.addClosure(page, eve);
    expect(lab.loan(loan.id).due).toBe(addDays(eve, 3));
    await lab.setWeekdays(page, [weekday(addDays(eve, 3))]);
    expect(lab.loan(loan.id).due).toBe(addDays(eve, 4));
    expect(lab.loan(loan.id).due).toBe(lab.nextOpen(eve));
  });

  test('C12: a yearly closure on 29 February closes neither 28 February nor 1 March of a common year', async () => {
    // The reader's request calendar receives the closed days it can show.
    const year = Number(lab.today.slice(0, 4));
    const isLeap = (y) => (y % 4 === 0 && y % 100 !== 0) || y % 400 === 0;
    let target = year;
    while (isLeap(target) || `${target}-02-28` <= lab.today) target++;
    test.skip(`${target}-03-01` > addDays(lab.today, 140), 'the end of February is past what the request calendar shows');
    await lab.addClosure(page, '2024-02-29', '', { yearly: true });
    const book = lab.book('leap');
    const reader = await ctx.browser().newContext();
    try {
      const rp = await reader.newPage();
      await F.loginAs(rp, patron.email, patron.password);
      const r = await lab.readerRequest(rp, book, { submit: false });
      expect(r.picker).toBe(true);
      const closed = r.availability.closed_dates || [];
      expect(closed).not.toContain(`${target}-02-28`);
      expect(closed).not.toContain(`${target}-03-01`);
    } finally { await reader.close(); }
  });

  test('C13: a closure of one branch does not move the loan of a copy kept in another branch', async () => {
    const raw = addDays(lab.today, lab.loanDays);
    const closedBranch = lab.branch('chiusa');
    const openBranch = lab.branch('aperta');
    const book = lab.book('branch-other');
    db(`UPDATE copie SET sede_id=${openBranch} WHERE id=${book.copyIds[0]}`);
    await lab.addClosure(page, raw, '', { branch: closedBranch });
    const loan = await lab.deskLoan(page, patron, book);
    expect(loan.ok, loan.error).toBe(true);
    expect(lab.loan(loan.id).due).toBe(raw);
  });

  test('C14: a closure of the copy\'s own branch moves the due date; a library-wide closure moves it for every branch', async () => {
    const raw = addDays(lab.today, lab.loanDays);
    const branch = lab.branch('sua');
    const book = lab.book('branch-own');
    const elsewhere = lab.book('branch-wide');
    db(`UPDATE copie SET sede_id=${branch} WHERE id IN (${book.copyIds[0]}, ${elsewhere.copyIds[0]})`);
    await lab.addClosure(page, raw, '', { branch });
    const own = await lab.deskLoan(page, patron, book);
    expect(lab.loan(own.id).due).toBe(addDays(raw, 1));
    // The whole library closes the day after as well.
    await lab.addClosure(page, addDays(raw, 1));
    const wide = await lab.deskLoan(page, other, elsewhere);
    expect(lab.loan(wide.id).due).toBe(addDays(raw, 2));
    // ...and the first loan, due on that day since its move, follows.
    expect(lab.loan(own.id).due).toBe(addDays(raw, 2));
  });

  // ── The closure arrives after the loan ───────────────────────────────────
  test('C15: a five-day closure saved afterwards moves a loan due in its middle to the day after its end', async () => {
    const book = lab.book('retro-range');
    const loan = await lab.deskLoan(page, patron, book);
    const due = lab.loan(loan.id).due;
    await lab.addClosure(page, addDays(due, -2), addDays(due, 2));
    expect(lab.loan(loan.id).due).toBe(addDays(due, 3));
    expect(lab.dueMoved(book)).toBe(1);
  });

  test('C16: a closure saved afterwards that does not include the due date leaves the loan and its history alone', async () => {
    const book = lab.book('retro-miss');
    const loan = await lab.deskLoan(page, patron, book);
    const due = lab.loan(loan.id).due;
    await lab.addClosure(page, addDays(due, 1), addDays(due, 3));
    await lab.addClosure(page, addDays(due, -3), addDays(due, -1));
    expect(lab.loan(loan.id).due).toBe(due);
    expect(lab.dueMoved(book)).toBe(0);
  });

  test('C17: two loans of different readers due on the same day both move', async () => {
    const a = await lab.deskLoan(page, patron, lab.book('retro-two-a'));
    const b = await lab.deskLoan(page, other, lab.book('retro-two-b'));
    const due = lab.loan(a.id).due;
    expect(lab.loan(b.id).due).toBe(due);
    await lab.addClosure(page, due);
    expect(lab.loan(a.id).due).toBe(addDays(due, 1));
    expect(lab.loan(b.id).due).toBe(addDays(due, 1));
  });

  test('C18: a returned loan keeps the due date it had, whatever is closed afterwards', async () => {
    const book = lab.book('retro-returned');
    const loan = await lab.deskLoan(page, patron, book);
    const due = lab.loan(loan.id).due;
    const back = await lab.returnLoan(page, loan.id);
    expect(back.ok).toBe(true);
    expect(lab.loan(loan.id).stato).toBe('restituito');
    await lab.addClosure(page, due);
    expect(lab.loan(loan.id).due).toBe(due);
    expect(lab.dueMoved(book)).toBe(0);
  });

  test('C19: on a title with two copies, the other copy being booked from the next day does not stop the move', async () => {
    const book = lab.book('retro-twocopies', 2);
    const loan = await lab.deskLoan(page, patron, book, { copyCode: F.copyCodeOf(book.copyIds[0]) });
    expect(loan.ok, loan.error).toBe(true);
    const due = lab.loan(loan.id).due;
    expect(lab.loan(loan.id).copy).toBe(book.copyIds[0]);
    lab.seedLoan(book, other, { start: addDays(due, 1), due: addDays(due, 15), stato: 'prenotato', copy: book.copyIds[1], origine: 'prenotazione' });
    await lab.addClosure(page, due);
    expect(lab.loan(loan.id).due).toBe(addDays(due, 1));
    lab.expectCoherent(book);
  });

  test('C20: lengthening a closure from the calendar moves the loan again, to the day after the new end', async () => {
    const book = lab.book('retro-edit');
    const loan = await lab.deskLoan(page, patron, book);
    const due = lab.loan(loan.id).due;
    const closure = await lab.addClosure(page, due);
    expect(lab.loan(loan.id).due).toBe(addDays(due, 1));
    // Edit the closure: it now lasts three days.
    await page.goto(`${F.BASE}/admin/calendar?edit=${closure.id}#calendar-closure-form`);
    await expect(page.locator('#closure_start')).toHaveValue(due);
    await page.fill('#closure_end', addDays(due, 2));
    await Promise.all([page.waitForURL(/\/admin\/calendar/, { timeout: 20000 }), page.locator('#save-closure').click()]);
    expect(db(`SELECT data_fine FROM calendario_chiusure WHERE id=${closure.id}`)).toBe(addDays(due, 2));
    expect(lab.loan(loan.id).due).toBe(addDays(due, 3));
  });

  test('C21: deleting a closure never leaves a loan due earlier than it was before the closure', async () => {
    const book = lab.book('retro-delete');
    const loan = await lab.deskLoan(page, patron, book);
    const due = lab.loan(loan.id).due;
    const closure = await lab.addClosure(page, due, addDays(due, 3));
    expect(lab.loan(loan.id).due).toBe(addDays(due, 4));
    await lab.deleteClosure(page, closure);
    const after = lab.loan(loan.id).due;
    expect(after).toBe(addDays(due, 4));
    expect(lab.loan(loan.id).stato).toBe('in_corso');
  });

  test('C22: a closure typed a year too long, then deleted, does not leave loans due a year later', async () => {
    // The librarian means 1–3 of a month and types next year's end by mistake,
    // notices at once and deletes the closure. The reader must not keep the
    // book for a year because of it.
    const book = lab.book('retro-typo');
    const loan = await lab.deskLoan(page, patron, book);
    const due = lab.loan(loan.id).due;
    const wrongEnd = `${Number(due.slice(0, 4)) + 1}${due.slice(4)}`;
    const closure = await lab.addClosure(page, due, wrongEnd, { model: false });
    test.skip(!closure.saved, 'the calendar refuses a closure this long');
    await lab.deleteClosure(page, closure, { undoExtensions: true });
    const after = lab.loan(loan.id).due;
    expect(after, `due date ${due} was moved to ${after} by a closure that no longer exists`).toBe(due);
  });

  test('C23: a loan long overdue stays overdue when a day after its due date is closed', async () => {
    const book = lab.book('retro-late');
    const due = addDays(lab.today, -10);
    const loanId = lab.seedLoan(book, patron, { start: addDays(due, -20), due, stato: 'in_ritardo' });
    await lab.addClosure(page, addDays(due, 1), addDays(due, 2));
    expect(lab.loan(loanId).stato).toBe('in_ritardo');
    expect(lab.loan(loanId).due).toBe(due);
  });

  // ── Late days, fines and deadlines over closed days ──────────────────────
  test('C24: the fine counts only the days the library was open after the due date', async () => {
    await lab.setSetting('daily_fine', '1.00');
    await lab.setSetting('fine_grace_days', '0');
    await lab.setSetting('fine_max_per_loan', '');
    const due = addDays(lab.today, -6);
    const loanId = lab.seedLoan(lab.book('fine-range'), patron, { start: addDays(due, -20), due, stato: 'in_corso' });
    // Control first: six days late, six euros.
    expect(await lab.previewedFine(page, loanId)).toBe(600);
    await lab.addClosure(page, addDays(due, 1), addDays(due, 3));
    expect(lab.openDaysBetween(due, lab.today)).toBe(3);
    expect(await lab.previewedFine(page, loanId)).toBe(300);
  });

  test('C25: a reader who could not come back because every day since the due date was closed owes nothing', async () => {
    await lab.setSetting('daily_fine', '1.00');
    await lab.setSetting('fine_grace_days', '0');
    const due = addDays(lab.today, -3);
    const loanId = lab.seedLoan(lab.book('fine-allclosed'), patron, { start: addDays(due, -20), due, stato: 'in_corso' });
    await lab.addClosure(page, addDays(due, 1), lab.today);
    expect(await lab.previewedFine(page, loanId)).toBe(0);
    const back = await lab.noServerErrors(page, () => lab.returnLoan(page, loanId));
    expect(back.ok).toBe(true);
    expect(lab.loan(loanId).stato).toBe('restituito');
    expect(dbInt(`SELECT COALESCE(sanzione,0)*100 FROM prestiti WHERE id=${loanId}`)).toBe(0);
  });

  test('C26: the nightly sweep does not mark late a loan whose due date and every day since were closed', async () => {
    const due = addDays(lab.today, -2);
    const closedLoan = lab.seedLoan(lab.book('sweep-closed'), patron, { start: addDays(due, -20), due, stato: 'in_corso' });
    const openLoan = lab.seedLoan(lab.book('sweep-open'), other, { start: addDays(due, -20), due, stato: 'in_corso' });
    const branch = lab.branch('sweep');
    db(`UPDATE copie SET sede_id=${branch} WHERE id=${lab.loan(closedLoan).copy}`);
    lab.seedClosure(due, lab.today, { branch });
    lab.runMaintenance();
    expect(lab.loan(closedLoan).stato).toBe('in_corso');
    expect(lab.loan(openLoan).stato).toBe('in_ritardo');
  });

  test('C27: the pickup deadline counts open days only and is itself an open day', async () => {
    await lab.addClosure(page, addDays(lab.today, 1), addDays(lab.today, 2));
    await lab.setWeekdays(page, [weekday(addDays(lab.today, 4))]);
    test.skip(!lab.isOpen(lab.today), 'the library is closed today in this calendar');
    const loan = await lab.deskLoan(page, patron, lab.book('pickup-open'), { immediate: false });
    expect(loan.ok, loan.error).toBe(true);
    const row = lab.loan(loan.id);
    expect(row.stato).toBe('da_ritirare');
    expect(row.pickup).toBe(lab.addOpenDays(lab.today, lab.pickupDays, row.due));
    expect(lab.isOpen(row.pickup)).toBe(true);
  });

  test('C28: a pickup whose deadline fell in a closed stretch gets the first open day; without closures it expires and frees the copy', async () => {
    const book = lab.book('pickup-expiry');
    const control = lab.book('pickup-expiry-control');
    const yesterday = addDays(lab.today, -1);
    const loanId = lab.seedLoan(book, patron, { start: addDays(lab.today, -4), due: addDays(lab.today, 20), stato: 'da_ritirare', pickup: yesterday });
    const controlId = lab.seedLoan(control, other, { start: addDays(lab.today, -4), due: addDays(lab.today, 20), stato: 'da_ritirare', pickup: yesterday });
    // Only the first copy's branch was closed yesterday and today.
    const branch = lab.branch('pickup');
    db(`UPDATE copie SET sede_id=${branch} WHERE id=${book.copyIds[0]}`);
    lab.seedClosure(yesterday, lab.today, { branch });
    lab.runMaintenance();
    const kept = lab.loan(loanId);
    expect(kept.stato).toBe('da_ritirare');
    expect(kept.pickup > lab.today, `pickup deadline ${kept.pickup} leaves the reader no open day`).toBe(true);
    expect(lab.loan(controlId).stato).toBe('scaduto');
    expect(lab.loan(controlId).attivo).toBe(0);
    expect(lab.copyState(control.copyIds[0])).toBe('disponibile');
    lab.expectCoherent(control);
    lab.expectCoherent(book);
  });

  test('C29: a renewal that would end inside a closed stretch ends the day after it', async () => {
    const book = lab.book('renew-range');
    const due = addDays(lab.today, 5);
    const loanId = lab.seedLoan(book, patron, { start: addDays(lab.today, -5), due, stato: 'in_corso' });
    // Control: the same loan on another title, renewed with no closure.
    const controlId = lab.seedLoan(lab.book('renew-control'), other, { start: addDays(lab.today, -5), due, stato: 'in_corso' });
    const control = await lab.renew(page, controlId);
    expect(control.offered).toBe(true);
    expect(control.renewals).toBe(1);
    expect(control.due > due).toBe(true);
    await lab.addClosure(page, addDays(control.due, -1), addDays(control.due, 2));
    const renewed = await lab.renew(page, loanId);
    expect(renewed.offered).toBe(true);
    expect(renewed.renewals).toBe(1);
    expect(renewed.due).toBe(addDays(control.due, 3));
  });

  test('C30: no due date written by the desk, an edit, a renewal or a calendar save is a closed day', async () => {
    // One calendar with a bit of everything, then every way of writing a due date.
    const raw = addDays(lab.today, lab.loanDays);
    await lab.setWeekdays(page, [weekday(raw), weekday(addDays(raw, 1))]);
    await lab.addClosure(page, addDays(raw, 2), addDays(raw, 3));
    await lab.addLibraryHoliday(page, addDays(raw, 4));
    const book = lab.book('everything');
    const desk = await lab.deskLoan(page, patron, book);
    expect(desk.ok, desk.error).toBe(true);
    expect(lab.loan(desk.id).due).toBe(lab.nextOpen(raw));
    expect(lab.loan(desk.id).due).toBe(addDays(raw, 5));
    // Edit onto an open day, which the library then closes.
    const target = addDays(raw, 9);
    const edit = await lab.editLoan(page, desk.id, { due: target });
    expect(edit.error).toBe('');
    expect(lab.loan(desk.id).due).toBe(lab.nextOpen(target));
    await lab.addClosure(page, lab.loan(desk.id).due);
    expect(lab.isOpen(lab.loan(desk.id).due)).toBe(true);
    // Renewal.
    const renewed = await lab.renew(page, desk.id);
    expect(renewed.offered).toBe(true);
    expect(lab.isOpen(renewed.due)).toBe(true);
    // A closure on the renewed due date.
    await lab.addClosure(page, renewed.due);
    expect(lab.isOpen(lab.loan(desk.id).due)).toBe(true);
    lab.expectCoherent(book);
  });
});
