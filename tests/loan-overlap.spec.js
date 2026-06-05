// @ts-check
/**
 * Loan / reservation OVERLAP suite (#157) — 35 real-world scenarios.
 *
 * Validates the unified "a copy is occupied" model end-to-end:
 *
 *   occupied(copy, [start,end]) ⇔ ∃ loan row on that copy whose
 *   [data_prestito, data_scadenza] intersects [start,end] AND
 *     (attivo = 1 AND stato IN ('in_corso','in_ritardo','da_ritirare','prenotato'))
 *     OR (stato = 'pendente' AND copia_id IS NOT NULL)
 *
 * The same predicate must hold at every layer:
 *   - the DB triggers (BEFORE INSERT/UPDATE on prestiti),
 *   - the copy-finding / availability SQL used by store / approveLoan /
 *     ReservationManager,
 *   - the per-date availability API the calendars read.
 *
 * Tests are DB- and API-level for determinism (the occupancy logic lives in
 * SQL + triggers), each isolated on its own book/copies, cleaned up at the end.
 *
 * Run:
 *   /tmp/run-e2e.sh tests/loan-overlap.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE        = process.env.E2E_BASE_URL  || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const DB_USER     = process.env.E2E_DB_USER   || '';
const DB_PASS     = process.env.E2E_DB_PASS   || '';
const DB_SOCKET   = process.env.E2E_DB_SOCKET || '';
const DB_NAME     = process.env.E2E_DB_NAME   || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME, 'E2E credentials not configured');

const TAG = 'E2E_OVERLAP_' + Date.now();

function dbQuery(sql) {
  const args = [];
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}
function qInt(sql) { return parseInt(dbQuery(sql) || '0', 10); }
function esc(v) { return String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'"); }

/** Insert a loan row; return {ok:true} or {ok:false, error} if a trigger/constraint rejects it. */
function tryInsertLoan({ bookId, copyId, userId, start, end, stato, attivo, origine = 'diretto' }) {
  const copia = copyId === null ? 'NULL' : copyId;
  try {
    dbQuery(`INSERT INTO prestiti (libro_id, utente_id, copia_id, data_prestito, data_scadenza, stato, origine, attivo, created_at, updated_at)
             VALUES (${bookId}, ${userId}, ${copia}, '${start}', '${end}', '${esc(stato)}', '${esc(origine)}', ${attivo}, NOW(), NOW())`);
    return { ok: true };
  } catch (e) {
    return { ok: false, error: String(e && e.message || e) };
  }
}

function dISO(off) {
  const d = new Date(); d.setDate(d.getDate() + off);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

test.describe.serial('Loan overlap model (#157) — 35 scenarios', () => {
  /** @type {number} */ let u1;
  /** @type {number} */ let u2;
  /** @type {number} */ let u3;
  const books = [];

  function mkBook(label) {
    dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at)
             VALUES ('${esc(TAG)} ${esc(label)}', 1, 1, NOW(), NOW())`);
    const id = qInt(`SELECT id FROM libri WHERE titolo='${esc(TAG)} ${esc(label)}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`);
    books.push(id);
    return id;
  }
  function mkCopy(bookId, n, stato = 'disponibile') {
    const inv = `${TAG}-${bookId}-${n}`;
    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato, created_at, updated_at)
             VALUES (${bookId}, '${esc(inv)}', '${esc(stato)}', NOW(), NOW())`);
    return qInt(`SELECT id FROM copie WHERE numero_inventario='${esc(inv)}' ORDER BY id DESC LIMIT 1`);
  }
  /** Copies of `bookId` NOT occupied by a held loan overlapping [start,end] — the canonical availability query. */
  function freeCopies(bookId, start, end) {
    return qInt(`
      SELECT COUNT(*) FROM copie c
      WHERE c.libro_id = ${bookId}
        AND c.stato NOT IN ('perso','danneggiato','manutenzione','in_restauro','in_trasferimento')
        AND NOT EXISTS (
          SELECT 1 FROM prestiti p
          WHERE p.copia_id = c.id
            AND p.data_prestito <= '${end}' AND p.data_scadenza >= '${start}'
            AND ((p.attivo = 1 AND p.stato IN ('in_corso','in_ritardo','da_ritirare','prenotato'))
                 OR (p.stato = 'pendente' AND p.copia_id IS NOT NULL))
        )`);
  }

  test.beforeAll(() => {
    // Two verified, active users (codice_tessera is NOT NULL + UNIQUE).
    for (const [slot, label] of [['A', u1], ['B', u2]]) { void slot; void label; }
    const mkUser = (sfx) => {
      const tess = `OV${Date.now().toString(36).slice(-7)}${sfx}`.slice(0, 20);
      const email = `${TAG}_${sfx}@example.test`.toLowerCase();
      dbQuery(`DELETE FROM utenti WHERE email='${esc(email)}'`);
      dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, email_verificata, tipo_utente, created_at, updated_at)
               VALUES ('${esc(tess)}', '${esc(TAG)}', '${esc(sfx)}', '${esc(email)}', 'x', 'attivo', 1, 'standard', NOW(), NOW())`);
      return qInt(`SELECT id FROM utenti WHERE email='${esc(email)}' LIMIT 1`);
    };
    u1 = mkUser('A');
    u2 = mkUser('B');
    u3 = mkUser('C');
  });

  test.afterAll(() => {
    try {
      if (books.length) {
        const ids = books.join(',');
        dbQuery(`DELETE FROM prestiti WHERE libro_id IN (${ids})`);
        dbQuery(`DELETE FROM prenotazioni WHERE libro_id IN (${ids})`);
        dbQuery(`DELETE FROM copie WHERE libro_id IN (${ids})`);
        dbQuery(`DELETE FROM libri WHERE id IN (${ids})`);
      }
      dbQuery(`DELETE FROM utenti WHERE nome='${esc(TAG)}'`);
    } catch { /* best effort */ }
  });

  // ════════════════════════════════════════════════════════════════════════
  // GROUP A — DB trigger: same-copy overlap rejection (defense-in-depth)
  // ════════════════════════════════════════════════════════════════════════

  test('A.1 active loan blocks an overlapping active loan on the SAME copy (trigger SIGNAL)', () => {
    const b = mkBook('A1'); const c = mkCopy(b, 1);
    expect(tryInsertLoan({ bookId: b, copyId: c, userId: u1, start: dISO(1), end: dISO(10), stato: 'in_corso', attivo: 1 }).ok).toBe(true);
    const r = tryInsertLoan({ bookId: b, copyId: c, userId: u2, start: dISO(5), end: dISO(15), stato: 'in_corso', attivo: 1 });
    expect(r.ok).toBe(false);
    expect(r.error).toMatch(/sovrapposto|45000/i);
  });

  test('A.2 same copy, DISJOINT date windows are allowed (sequential loans)', () => {
    const b = mkBook('A2'); const c = mkCopy(b, 1);
    expect(tryInsertLoan({ bookId: b, copyId: c, userId: u1, start: dISO(1), end: dISO(10), stato: 'in_corso', attivo: 1 }).ok).toBe(true);
    expect(tryInsertLoan({ bookId: b, copyId: c, userId: u2, start: dISO(11), end: dISO(20), stato: 'prenotato', attivo: 1 }).ok).toBe(true);
  });

  test('A.3 a pending-WITH-copy blocks a later overlapping active loan on the same copy', () => {
    const b = mkBook('A3'); const c = mkCopy(b, 1);
    expect(tryInsertLoan({ bookId: b, copyId: c, userId: u1, start: dISO(1), end: dISO(10), stato: 'pendente', attivo: 0, origine: 'prenotazione' }).ok).toBe(true);
    const r = tryInsertLoan({ bookId: b, copyId: c, userId: u2, start: dISO(3), end: dISO(8), stato: 'in_corso', attivo: 1 });
    expect(r.ok).toBe(false);
  });

  test('A.4 an active loan blocks a later overlapping pending-WITH-copy on the same copy', () => {
    const b = mkBook('A4'); const c = mkCopy(b, 1);
    expect(tryInsertLoan({ bookId: b, copyId: c, userId: u1, start: dISO(1), end: dISO(10), stato: 'in_corso', attivo: 1 }).ok).toBe(true);
    const r = tryInsertLoan({ bookId: b, copyId: c, userId: u2, start: dISO(3), end: dISO(8), stato: 'pendente', attivo: 0, origine: 'prenotazione' });
    expect(r.ok).toBe(false);
  });

  test('A.5 a bare pending (copia_id NULL) never trips the trigger and never blocks', () => {
    const b = mkBook('A5'); const c = mkCopy(b, 1);
    expect(tryInsertLoan({ bookId: b, copyId: null, userId: u1, start: dISO(1), end: dISO(10), stato: 'pendente', attivo: 0, origine: 'richiesta' }).ok).toBe(true);
    // An active loan on the copy is still allowed (the bare pending holds nothing).
    expect(tryInsertLoan({ bookId: b, copyId: c, userId: u2, start: dISO(1), end: dISO(10), stato: 'in_corso', attivo: 1 }).ok).toBe(true);
  });

  test('A.6 da_ritirare occupies the copy (overlapping active loan rejected)', () => {
    const b = mkBook('A6'); const c = mkCopy(b, 1);
    expect(tryInsertLoan({ bookId: b, copyId: c, userId: u1, start: dISO(1), end: dISO(10), stato: 'da_ritirare', attivo: 1 }).ok).toBe(true);
    expect(tryInsertLoan({ bookId: b, copyId: c, userId: u2, start: dISO(2), end: dISO(9), stato: 'in_corso', attivo: 1 }).ok).toBe(false);
  });

  test('A.7 in_ritardo (overdue, still active) occupies the copy', () => {
    const b = mkBook('A7'); const c = mkCopy(b, 1);
    expect(tryInsertLoan({ bookId: b, copyId: c, userId: u1, start: dISO(-20), end: dISO(-5), stato: 'in_ritardo', attivo: 1 }).ok).toBe(true);
    expect(tryInsertLoan({ bookId: b, copyId: c, userId: u2, start: dISO(-10), end: dISO(2), stato: 'in_corso', attivo: 1 }).ok).toBe(false);
  });

  test('A.8 a RETURNED loan (attivo=0, restituito) does NOT occupy the copy', () => {
    const b = mkBook('A8'); const c = mkCopy(b, 1);
    dbQuery(`INSERT INTO prestiti (libro_id, utente_id, copia_id, data_prestito, data_scadenza, data_restituzione, stato, origine, attivo, created_at, updated_at)
             VALUES (${b}, ${u1}, ${c}, '${dISO(1)}', '${dISO(10)}', '${dISO(4)}', 'restituito', 'diretto', 0, NOW(), NOW())`);
    expect(tryInsertLoan({ bookId: b, copyId: c, userId: u2, start: dISO(2), end: dISO(9), stato: 'in_corso', attivo: 1 }).ok).toBe(true);
  });

  // ════════════════════════════════════════════════════════════════════════
  // GROUP B — book-level availability (canonical free-copy query)
  // ════════════════════════════════════════════════════════════════════════

  test('B.9 single copy, no loans → 1 free copy for any window', () => {
    const b = mkBook('B9'); mkCopy(b, 1);
    expect(freeCopies(b, dISO(1), dISO(10))).toBe(1);
  });

  test('B.10 single copy held by an overlapping active loan → 0 free', () => {
    const b = mkBook('B10'); const c = mkCopy(b, 1);
    tryInsertLoan({ bookId: b, copyId: c, userId: u1, start: dISO(1), end: dISO(10), stato: 'in_corso', attivo: 1 });
    expect(freeCopies(b, dISO(3), dISO(8))).toBe(0);
  });

  test('B.11 single copy with a DISJOINT loan → still 1 free for the gap window', () => {
    const b = mkBook('B11'); const c = mkCopy(b, 1);
    tryInsertLoan({ bookId: b, copyId: c, userId: u1, start: dISO(1), end: dISO(10), stato: 'in_corso', attivo: 1 });
    expect(freeCopies(b, dISO(11), dISO(20))).toBe(1);
  });

  test('B.12 single copy held by a pending-WITH-copy → 0 free', () => {
    const b = mkBook('B12'); const c = mkCopy(b, 1);
    tryInsertLoan({ bookId: b, copyId: c, userId: u1, start: dISO(1), end: dISO(10), stato: 'pendente', attivo: 0, origine: 'prenotazione' });
    expect(freeCopies(b, dISO(2), dISO(9))).toBe(0);
  });

  test('B.13 single copy with only a bare pending (no copy) → still 1 free', () => {
    const b = mkBook('B13'); mkCopy(b, 1);
    tryInsertLoan({ bookId: b, copyId: null, userId: u1, start: dISO(1), end: dISO(10), stato: 'pendente', attivo: 0, origine: 'richiesta' });
    expect(freeCopies(b, dISO(2), dISO(9))).toBe(1);
  });

  test('B.14 two copies, one held → 1 free', () => {
    const b = mkBook('B14'); const c1 = mkCopy(b, 1); mkCopy(b, 2);
    tryInsertLoan({ bookId: b, copyId: c1, userId: u1, start: dISO(1), end: dISO(10), stato: 'in_corso', attivo: 1 });
    expect(freeCopies(b, dISO(2), dISO(9))).toBe(1);
  });

  test('B.15 two copies, both held (1 active + 1 pending-with-copy) → 0 free', () => {
    const b = mkBook('B15'); const c1 = mkCopy(b, 1); const c2 = mkCopy(b, 2);
    tryInsertLoan({ bookId: b, copyId: c1, userId: u1, start: dISO(1), end: dISO(10), stato: 'in_corso', attivo: 1 });
    tryInsertLoan({ bookId: b, copyId: c2, userId: u2, start: dISO(1), end: dISO(10), stato: 'pendente', attivo: 0, origine: 'prenotazione' });
    expect(freeCopies(b, dISO(2), dISO(9))).toBe(0);
  });

  test('B.16 a non-lendable copy (manutenzione) is never counted free', () => {
    const b = mkBook('B16'); mkCopy(b, 1, 'manutenzione');
    expect(freeCopies(b, dISO(1), dISO(10))).toBe(0);
  });

  test('B.17 future prenotato window blocks only its own dates, not earlier', () => {
    const b = mkBook('B17'); const c = mkCopy(b, 1);
    tryInsertLoan({ bookId: b, copyId: c, userId: u1, start: dISO(20), end: dISO(30), stato: 'prenotato', attivo: 1 });
    expect(freeCopies(b, dISO(1), dISO(10))).toBe(1);   // before the reservation
    expect(freeCopies(b, dISO(25), dISO(28))).toBe(0);  // inside it
  });

  // ════════════════════════════════════════════════════════════════════════
  // GROUP C — admin manual loan (PrestitiController::store) respects occupancy
  // ════════════════════════════════════════════════════════════════════════

  async function adminLogin(page) {
    await page.goto(`${BASE}/admin/dashboard`);
    const email = page.locator('input[name="email"]');
    if (await email.isVisible({ timeout: 3000 }).catch(() => false)) {
      await email.fill(ADMIN_EMAIL);
      await page.fill('input[name="password"]', ADMIN_PASS);
      await Promise.all([page.waitForURL(/\/admin\//, { timeout: 15000 }), page.click('button[type="submit"]')]);
    }
  }
  async function adminStoreLoan(page, { bookId, userId, start, end }) {
    const csrf = await page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
    return page.evaluate(async ({ base, bookId, userId, start, end, csrf }) => {
      const body = new URLSearchParams({ csrf_token: csrf, libro_id: String(bookId), utente_id: String(userId), data_prestito: start, data_scadenza: end });
      // follow the redirect so r.url carries the ?error= / ?success= query
      const r = await fetch(base + '/admin/prestiti/crea', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
      return { status: r.status, location: r.url || '' };
    }, { base: BASE, bookId, userId, start, end, csrf });
  }

  test('C.18 admin manual loan succeeds on a free copy', async ({ page }) => {
    await adminLogin(page);
    await page.goto(`${BASE}/admin/dashboard`);
    const b = mkBook('C18'); mkCopy(b, 1);
    const res = await adminStoreLoan(page, { bookId: b, userId: u1, start: dISO(2), end: dISO(9) });
    expect(res.status).toBeLessThan(400);
    expect(res.location).not.toMatch(/no_copies_available/);
    expect(qInt(`SELECT COUNT(*) FROM prestiti WHERE libro_id=${b} AND utente_id=${u1} AND stato IN ('da_ritirare','in_corso','prenotato')`)).toBeGreaterThanOrEqual(1);
  });

  test('C.19 admin manual loan is REFUSED when the single copy is held by an overlapping active loan', async ({ page }) => {
    await adminLogin(page);
    await page.goto(`${BASE}/admin/dashboard`);
    const b = mkBook('C19'); const c = mkCopy(b, 1);
    tryInsertLoan({ bookId: b, copyId: c, userId: u1, start: dISO(1), end: dISO(10), stato: 'in_corso', attivo: 1 });
    const res = await adminStoreLoan(page, { bookId: b, userId: u2, start: dISO(3), end: dISO(8) });
    expect(res.location).toMatch(/no_copies_available/);
    expect(qInt(`SELECT COUNT(*) FROM prestiti WHERE libro_id=${b} AND utente_id=${u2}`)).toBe(0);
  });

  test('C.20 admin manual loan is REFUSED when the single copy is held by a pending-WITH-copy', async ({ page }) => {
    await adminLogin(page);
    await page.goto(`${BASE}/admin/dashboard`);
    const b = mkBook('C20'); const c = mkCopy(b, 1);
    tryInsertLoan({ bookId: b, copyId: c, userId: u1, start: dISO(1), end: dISO(10), stato: 'pendente', attivo: 0, origine: 'prenotazione' });
    const res = await adminStoreLoan(page, { bookId: b, userId: u2, start: dISO(3), end: dISO(8) });
    expect(res.location).toMatch(/no_copies_available/);
  });

  test('C.21 admin manual loan SUCCEEDS in a disjoint window even when the copy is held elsewhere', async ({ page }) => {
    await adminLogin(page);
    await page.goto(`${BASE}/admin/dashboard`);
    const b = mkBook('C21'); const c = mkCopy(b, 1);
    tryInsertLoan({ bookId: b, copyId: c, userId: u1, start: dISO(1), end: dISO(10), stato: 'in_corso', attivo: 1 });
    const res = await adminStoreLoan(page, { bookId: b, userId: u2, start: dISO(11), end: dISO(20) });
    expect(res.location || '').not.toMatch(/no_copies_available/);
  });

  test('C.22 admin manual loan SUCCEEDS when a bare pending (no copy) exists for the same window', async ({ page }) => {
    await adminLogin(page);
    await page.goto(`${BASE}/admin/dashboard`);
    const b = mkBook('C22'); mkCopy(b, 1);
    tryInsertLoan({ bookId: b, copyId: null, userId: u1, start: dISO(1), end: dISO(10), stato: 'pendente', attivo: 0, origine: 'richiesta' });
    const res = await adminStoreLoan(page, { bookId: b, userId: u2, start: dISO(2), end: dISO(9) });
    expect(res.location || '').not.toMatch(/no_copies_available/);
  });

  test('C.23 admin manual loan: 2 copies both held over the window → refused', async ({ page }) => {
    await adminLogin(page);
    await page.goto(`${BASE}/admin/dashboard`);
    const b = mkBook('C23'); const c1 = mkCopy(b, 1); const c2 = mkCopy(b, 2);
    tryInsertLoan({ bookId: b, copyId: c1, userId: u1, start: dISO(1), end: dISO(10), stato: 'in_corso', attivo: 1 });
    tryInsertLoan({ bookId: b, copyId: c2, userId: u2, start: dISO(1), end: dISO(10), stato: 'pendente', attivo: 0, origine: 'prenotazione' });
    // u3 has no loan on this book, so the per-user duplicate guard doesn't pre-empt
    // the capacity check — both copies are held, so it must be refused.
    const res = await adminStoreLoan(page, { bookId: b, userId: u3, start: dISO(2), end: dISO(9) });
    expect(res.location).toMatch(/no_copies_available/);
  });

  // ════════════════════════════════════════════════════════════════════════
  // GROUP D — admin approval (LoanApprovalController::approveLoan) occupancy
  // ════════════════════════════════════════════════════════════════════════

  async function adminApprove(page, loanId) {
    const csrf = await page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
    return page.evaluate(async ({ base, loanId, csrf }) => {
      const body = new URLSearchParams({ csrf_token: csrf, loan_id: String(loanId) });
      const r = await fetch(`${base}/admin/loans/approve`, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf }, body: body.toString() });
      let body2 = null; try { body2 = await r.json(); } catch { /* */ }
      return { status: r.status, body: body2 };
    }, { base: BASE, loanId, csrf });
  }
  function mkPending(bookId, userId, start, end, copyId = null) {
    const copia = copyId === null ? 'NULL' : copyId;
    dbQuery(`INSERT INTO prestiti (libro_id, utente_id, copia_id, data_prestito, data_scadenza, stato, origine, attivo, created_at, updated_at)
             VALUES (${bookId}, ${userId}, ${copia}, '${start}', '${end}', 'pendente', 'richiesta', 0, NOW(), NOW())`);
    return qInt(`SELECT id FROM prestiti WHERE libro_id=${bookId} AND utente_id=${userId} AND stato='pendente' ORDER BY id DESC LIMIT 1`);
  }

  test('D.24 approving a bare pending assigns a free copy and makes it da_ritirare', async ({ page }) => {
    await adminLogin(page);
    const b = mkBook('D24'); mkCopy(b, 1);
    const id = mkPending(b, u1, dISO(2), dISO(9));
    await page.goto(`${BASE}/admin/prestiti`);
    const res = await adminApprove(page, id);
    expect(res.status).toBeLessThan(500);
    const stato = dbQuery(`SELECT stato FROM prestiti WHERE id=${id}`);
    // Approved + copy assigned: da_ritirare (start today) or prenotato (future start).
    expect(['da_ritirare', 'prenotato']).toContain(stato);
    expect(qInt(`SELECT copia_id IS NOT NULL FROM prestiti WHERE id=${id}`)).toBe(1);
  });

  test('D.25 approval REFUSED when the only copy is already held by an overlapping active loan', async ({ page }) => {
    await adminLogin(page);
    const b = mkBook('D25'); const c = mkCopy(b, 1);
    tryInsertLoan({ bookId: b, copyId: c, userId: u2, start: dISO(1), end: dISO(10), stato: 'in_corso', attivo: 1 });
    const id = mkPending(b, u1, dISO(3), dISO(8));
    await page.goto(`${BASE}/admin/prestiti`);
    const res = await adminApprove(page, id);
    // No free copy → not approved; loan stays pendente, no second copy allocated.
    expect(dbQuery(`SELECT stato FROM prestiti WHERE id=${id}`)).toBe('pendente');
    expect(res.body && res.body.success).not.toBe(true);
  });

  test('D.26 approval REFUSED when the only copy is held by another pending-WITH-copy', async ({ page }) => {
    await adminLogin(page);
    const b = mkBook('D26'); const c = mkCopy(b, 1);
    tryInsertLoan({ bookId: b, copyId: c, userId: u2, start: dISO(1), end: dISO(10), stato: 'pendente', attivo: 0, origine: 'prenotazione' });
    const id = mkPending(b, u1, dISO(3), dISO(8));
    await page.goto(`${BASE}/admin/prestiti`);
    await adminApprove(page, id);
    expect(dbQuery(`SELECT stato FROM prestiti WHERE id=${id}`)).toBe('pendente');
  });

  test('D.27 approval SUCCEEDS when a 2nd copy is free over the window', async ({ page }) => {
    await adminLogin(page);
    const b = mkBook('D27'); const c1 = mkCopy(b, 1); mkCopy(b, 2);
    tryInsertLoan({ bookId: b, copyId: c1, userId: u2, start: dISO(1), end: dISO(10), stato: 'in_corso', attivo: 1 });
    const id = mkPending(b, u1, dISO(3), dISO(8));
    await page.goto(`${BASE}/admin/prestiti`);
    await adminApprove(page, id);
    const st = dbQuery(`SELECT stato FROM prestiti WHERE id=${id}`); expect(['da_ritirare','prenotato']).toContain(st);
  });

  test('D.28 approval SUCCEEDS in a disjoint window even though the copy is held earlier', async ({ page }) => {
    await adminLogin(page);
    const b = mkBook('D28'); const c = mkCopy(b, 1);
    tryInsertLoan({ bookId: b, copyId: c, userId: u2, start: dISO(1), end: dISO(10), stato: 'in_corso', attivo: 1 });
    const id = mkPending(b, u1, dISO(11), dISO(20));
    await page.goto(`${BASE}/admin/prestiti`);
    await adminApprove(page, id);
    const st = dbQuery(`SELECT stato FROM prestiti WHERE id=${id}`); expect(['da_ritirare','prenotato']).toContain(st);
  });

  // ════════════════════════════════════════════════════════════════════════
  // GROUP E — per-date availability API (calendars read this)
  // ════════════════════════════════════════════════════════════════════════

  async function availabilityByDate(page, bookId) {
    return page.evaluate(async ({ base, bookId }) => {
      const r = await fetch(`${base}/api/books/${bookId}/availability-calendar`, { credentials: 'same-origin' });
      try { return await r.json(); } catch { return null; }
    }, { base: BASE, bookId });
  }

  test('E.29 availability calendar: a held window shows 0 available on overlapping days', async ({ page }) => {
    await adminLogin(page);
    const b = mkBook('E29'); const c = mkCopy(b, 1);
    tryInsertLoan({ bookId: b, copyId: c, userId: u1, start: dISO(2), end: dISO(6), stato: 'in_corso', attivo: 1 });
    const data = await availabilityByDate(page, b);
    expect(data).toBeTruthy();
    // The endpoint returns days[] ({date, available, …}); build a date→available
    // map from it and assert the mid day is PRESENT before checking its value, so
    // a regression that empties days[] or trims the range fails loudly instead of
    // skipping the assertion silently.
    const byDate = Object.fromEntries((Array.isArray(data.days) ? data.days : []).map(d => [d.date, Number(d.available)]));
    const mid = dISO(4);
    expect(byDate[mid], `availability-calendar days[] must include ${mid}`).not.toBeUndefined();
    expect(byDate[mid]).toBe(0);
  });

  test('E.30 availability calendar: a pending-WITH-copy reduces availability like an active loan', async ({ page }) => {
    await adminLogin(page);
    const b = mkBook('E30'); const c = mkCopy(b, 1);
    tryInsertLoan({ bookId: b, copyId: c, userId: u1, start: dISO(2), end: dISO(6), stato: 'pendente', attivo: 0, origine: 'prenotazione' });
    const data = await availabilityByDate(page, b);
    expect(data).toBeTruthy();
    const byDate = Object.fromEntries((Array.isArray(data.days) ? data.days : []).map(d => [d.date, Number(d.available)]));
    const mid = dISO(4);
    expect(byDate[mid], `availability-calendar days[] must include ${mid}`).not.toBeUndefined();
    expect(byDate[mid]).toBe(0);
    // The canonical DB query must agree with the API.
    expect(freeCopies(b, mid, mid)).toBe(0);
  });

  test('E.31 availability calendar: a bare pending (no copy) does NOT reduce availability', async ({ page }) => {
    await adminLogin(page);
    const b = mkBook('E31'); mkCopy(b, 1);
    tryInsertLoan({ bookId: b, copyId: null, userId: u1, start: dISO(2), end: dISO(6), stato: 'pendente', attivo: 0, origine: 'richiesta' });
    expect(freeCopies(b, dISO(4), dISO(4))).toBe(1);
    const data = await availabilityByDate(page, b);
    expect(data).toBeTruthy();
    const byDate = Object.fromEntries((Array.isArray(data.days) ? data.days : []).map(d => [d.date, Number(d.available)]));
    const mid = dISO(4);
    expect(byDate[mid], `availability-calendar days[] must include ${mid}`).not.toBeUndefined();
    expect(byDate[mid]).toBeGreaterThanOrEqual(1);
  });

  test('E.32 availability calendar: free days outside any held window show availability', async ({ page }) => {
    await adminLogin(page);
    const b = mkBook('E32'); const c = mkCopy(b, 1);
    tryInsertLoan({ bookId: b, copyId: c, userId: u1, start: dISO(2), end: dISO(6), stato: 'in_corso', attivo: 1 });
    const free = dISO(20);
    expect(freeCopies(b, free, free)).toBe(1);
    // Also assert through the calendar API: the free day (within the default
    // 60-day window) must be present in days[] and show full availability.
    const data = await availabilityByDate(page, b);
    expect(data).toBeTruthy();
    const byDate = Object.fromEntries((Array.isArray(data.days) ? data.days : []).map(d => [d.date, Number(d.available)]));
    expect(byDate[free], `availability-calendar days[] must include the free day ${free}`).not.toBeUndefined();
    expect(byDate[free]).toBeGreaterThanOrEqual(1);
  });

  // ════════════════════════════════════════════════════════════════════════
  // GROUP F — lifecycle transitions free / hold the copy consistently
  // ════════════════════════════════════════════════════════════════════════

  test('F.33 returning an active loan frees the copy for an overlapping new loan', () => {
    const b = mkBook('F33'); const c = mkCopy(b, 1);
    tryInsertLoan({ bookId: b, copyId: c, userId: u1, start: dISO(1), end: dISO(10), stato: 'in_corso', attivo: 1 });
    expect(freeCopies(b, dISO(3), dISO(8))).toBe(0);
    dbQuery(`UPDATE prestiti SET stato='restituito', attivo=0, data_restituzione='${dISO(4)}' WHERE libro_id=${b} AND copia_id=${c}`);
    expect(freeCopies(b, dISO(3), dISO(8))).toBe(1);
  });

  test('F.34 rejecting (deleting) a pending-with-copy frees its copy', () => {
    const b = mkBook('F34'); const c = mkCopy(b, 1);
    tryInsertLoan({ bookId: b, copyId: c, userId: u1, start: dISO(1), end: dISO(10), stato: 'pendente', attivo: 0, origine: 'prenotazione' });
    expect(freeCopies(b, dISO(3), dISO(8))).toBe(0);
    dbQuery(`DELETE FROM prestiti WHERE libro_id=${b} AND stato='pendente'`);
    expect(freeCopies(b, dISO(3), dISO(8))).toBe(1);
  });

  test('F.35 picking up (pendente→da_ritirare→in_corso) keeps the copy held throughout', () => {
    const b = mkBook('F35'); const c = mkCopy(b, 1);
    const id = qInt((dbQuery(`INSERT INTO prestiti (libro_id, utente_id, copia_id, data_prestito, data_scadenza, stato, origine, attivo, created_at, updated_at)
             VALUES (${b}, ${u1}, ${c}, '${dISO(1)}', '${dISO(10)}', 'pendente', 'prenotazione', 0, NOW(), NOW())`),
      `SELECT id FROM prestiti WHERE libro_id=${b} ORDER BY id DESC LIMIT 1`));
    expect(freeCopies(b, dISO(3), dISO(8))).toBe(0);            // pending-with-copy holds it
    dbQuery(`UPDATE prestiti SET stato='da_ritirare', attivo=1 WHERE id=${id}`);
    expect(freeCopies(b, dISO(3), dISO(8))).toBe(0);            // da_ritirare holds it
    dbQuery(`UPDATE prestiti SET stato='in_corso' WHERE id=${id}`);
    expect(freeCopies(b, dISO(3), dISO(8))).toBe(0);            // in_corso holds it
  });
});
