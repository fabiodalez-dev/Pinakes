// @ts-check
// Canonical loan/reservation state-model verification (fix/loan-state-bugs).
// Asserts the NEW behaviours not covered by loan-reservation.spec.js:
//   BUG1  — editing a returned loan must NOT reactivate it (I1)
//   BUG5  — a late return sets restituito_in_ritardo=1, never stato='in_ritardo'+attivo=0
//   BUG10 — the waitlist occupies its promised period: an overlapping admin
//           reservation on a full book is rejected; a disjoint one succeeds
//   Invariant sweep — the canonical invariants hold across the whole DB
const { test, expect } = require('@playwright/test');
test.describe.configure({ mode: 'serial' });
const { execFileSync } = require('child_process');
const fs = require('fs'); const os = require('os'); const path = require('path');
const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '', DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '', DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '', DB_NAME = process.env.E2E_DB_NAME || '';
test.skip(!ADMIN_EMAIL || !DB_USER || !DB_NAME, 'creds not configured');

function dbQuery(sql){
  const args=[]; if(DB_HOST){args.push('-h',DB_HOST); if(DB_PORT)args.push('-P',DB_PORT);} else if(DB_SOCKET){args.push('-S',DB_SOCKET);}
  args.push('-u',DB_USER,DB_NAME,'-N','-B','-e',sql);
  const cnf=path.join(os.tmpdir(),`pk-lsm-${process.pid}.cnf`); fs.writeFileSync(cnf,`[client]\npassword="${DB_PASS}"\n`,{mode:0o600});
  try{ return execFileSync('mysql',[`--defaults-extra-file=${cnf}`,...args],{encoding:'utf-8',timeout:10000}).trim(); } finally { try{fs.unlinkSync(cnf);}catch{} }
}
const q1 = (sql) => dbQuery(sql).split('\n')[0].trim();
const esc = (s) => String(s).replace(/'/g, "''");
const addDays = (n) => { const d = new Date(); d.setDate(d.getDate()+n); return d.toISOString().slice(0,10); };

test.describe('canonical loan/reservation state model', () => {
  /** @type {import('@playwright/test').Page} */
  let page;
  let bookId = 0, copyId = 0, userA = 0, userB = 0;
  const tag = `LSM${Date.now()}`;

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL((u) => !u.toString().includes('/accedi'), { timeout: 15000 });

    // 1-copy book + two borrowers, created directly for a deterministic fixture.
    dbQuery(`INSERT INTO libri (titolo, created_at, updated_at) VALUES ('${tag} Book', NOW(), NOW())`);
    bookId = parseInt(q1(`SELECT id FROM libri WHERE titolo='${tag} Book' ORDER BY id DESC LIMIT 1`), 10);
    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato, created_at, updated_at) VALUES (${bookId}, '${tag}-INV1', 'disponibile', NOW(), NOW())`);
    copyId = parseInt(q1(`SELECT id FROM copie WHERE libro_id=${bookId} ORDER BY id DESC LIMIT 1`), 10);
    for (const s of ['A', 'B']) {
      const em = `${tag.toLowerCase()}_${s}@test.local`;
      dbQuery(`INSERT INTO utenti (nome, cognome, email, password, codice_tessera, tipo_utente, created_at, updated_at) VALUES ('${tag}${s}', 'Test', '${em}', 'x', '${tag}${s}', 'standard', NOW(), NOW())`);
      const id = parseInt(q1(`SELECT id FROM utenti WHERE email='${em}' LIMIT 1`), 10);
      if (s === 'A') userA = id; else userB = id;
    }
    dbQuery(`UPDATE libri SET copie_totali=1, copie_disponibili=1 WHERE id=${bookId}`);
    expect(bookId).toBeGreaterThan(0); expect(copyId).toBeGreaterThan(0);
    expect(userA).toBeGreaterThan(0); expect(userB).toBeGreaterThan(0);
  });

  test.afterAll(async () => {
    if (bookId) {
      try { dbQuery(`DELETE FROM prestiti WHERE libro_id=${bookId}`); } catch {}
      try { dbQuery(`DELETE FROM prenotazioni WHERE libro_id=${bookId}`); } catch {}
      try { dbQuery(`DELETE FROM copie WHERE libro_id=${bookId}`); } catch {}
      try { dbQuery(`DELETE FROM libri WHERE id=${bookId}`); } catch {}
    }
    if (userA) try { dbQuery(`DELETE FROM utenti WHERE id IN (${userA}, ${userB})`); } catch {}
    await page?.close();
  });

  async function csrfFrom(urlPath) {
    return await page.evaluate(async (p) => {
      const r = await fetch(p, { credentials: 'same-origin' });
      const html = await r.text();
      const m = html.match(/name="csrf_token"\s+value="([^"]+)"/);
      return m ? m[1] : '';
    }, urlPath);
  }
  async function postForm(urlPath, fields) {
    // Follow the redirect (manual mode yields an unreadable opaque-redirect with
    // status 0) and report the final URL, which carries the ?error=... code.
    return await page.evaluate(async ({ p, f }) => {
      const r = await fetch(p, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(f).toString(), redirect: 'follow',
      });
      return { status: r.status, url: r.url };
    }, { p: urlPath, f: fields });
  }
  async function postJson(urlPath, obj, csrf) {
    return await page.evaluate(async ({ p, o, c }) => {
      const r = await fetch(p, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': c },
        body: JSON.stringify(o), redirect: 'follow',
      });
      let body = {}; try { body = await r.json(); } catch {}
      return { status: r.status, body };
    }, { p: urlPath, o: obj, c: csrf });
  }

  test('BUG1 — editing a returned loan does not reactivate it', async () => {
    // A returned loan: attivo=0, restituito, data_restituzione set, copy of the book.
    dbQuery(`INSERT INTO prestiti (libro_id, utente_id, copia_id, data_prestito, data_scadenza, data_restituzione, stato, attivo, created_at)
             VALUES (${bookId}, ${userA}, ${copyId}, '${addDays(-20)}', '${addDays(-6)}', '${addDays(-6)}', 'restituito', 0, NOW())`);
    const loanId = parseInt(q1(`SELECT id FROM prestiti WHERE libro_id=${bookId} AND stato='restituito' ORDER BY id DESC LIMIT 1`), 10);
    // CSRF token is session-global; fetch from a page that always renders a form
    // (the loan edit page 404s for a closed loan, yielding no token).
    const csrf = await csrfFrom('/admin/reservations/create');
    const res = await postForm(`/admin/loans/update/${loanId}`, {
      csrf_token: csrf, utente_id: String(userA), data_prestito: addDays(-3), data_scadenza: addDays(11),
    });
    // Rejected as a closed loan; the row stays closed.
    expect(res.url).toContain('error=loan_closed');
    const row = dbQuery(`SELECT attivo, IFNULL(data_restituzione,''), stato FROM prestiti WHERE id=${loanId}`).split('\t');
    expect(row[0]).toBe('0');           // still inactive
    expect(row[1]).not.toBe('');        // data_restituzione preserved
    expect(row[2]).toBe('restituito');  // still returned
    dbQuery(`DELETE FROM prestiti WHERE id=${loanId}`);
  });

  test('BUG5 — a late return sets restituito_in_ritardo=1', async () => {
    dbQuery(`UPDATE copie SET stato='prestato' WHERE id=${copyId}`);
    dbQuery(`INSERT INTO prestiti (libro_id, utente_id, copia_id, data_prestito, data_scadenza, stato, attivo, created_at)
             VALUES (${bookId}, ${userA}, ${copyId}, '${addDays(-30)}', '${addDays(-3)}', 'in_corso', 1, NOW())`);
    const loanId = parseInt(q1(`SELECT id FROM prestiti WHERE libro_id=${bookId} AND stato='in_corso' ORDER BY id DESC LIMIT 1`), 10);
    const csrf = await csrfFrom('/admin/reservations/create');
    const res = await postJson('/admin/loans/return', { loan_id: loanId }, csrf);
    expect(res.status).toBe(200);
    const row = dbQuery(`SELECT stato, attivo, restituito_in_ritardo FROM prestiti WHERE id=${loanId}`).split('\t');
    expect(row[0]).toBe('restituito');
    expect(row[1]).toBe('0');
    expect(row[2]).toBe('1'); // flagged late, NOT left as stato='in_ritardo'
    dbQuery(`DELETE FROM prestiti WHERE id=${loanId}`);
    dbQuery(`UPDATE copie SET stato='disponibile' WHERE id=${copyId}`);
  });

  test('BUG10 — the waitlist occupies its period: overlapping admin reservation rejected, disjoint allowed', async () => {
    const startA = addDays(5), endA = addDays(10);
    const csrf1 = await csrfFrom('/admin/reservations/create');
    const r1 = await postForm('/admin/reservations/create', {
      csrf_token: csrf1, libro_id: String(bookId), utente_id: String(userA),
      data_prenotazione: startA, data_scadenza: endA,
      data_inizio_richiesta: startA, data_fine_richiesta: endA,
    });
    const resCount = parseInt(q1(`SELECT COUNT(*) FROM prenotazioni WHERE libro_id=${bookId} AND utente_id=${userA} AND stato='attiva'`), 10);
    expect(resCount).toBe(1); // first reservation created

    // user B, overlapping window → must be rejected (book has only 1 copy, fully occupied for the period)
    const csrf2 = await csrfFrom('/admin/reservations/create');
    const r2 = await postForm('/admin/reservations/create', {
      csrf_token: csrf2, libro_id: String(bookId), utente_id: String(userB),
      data_prenotazione: addDays(6), data_scadenza: addDays(9),
      data_inizio_richiesta: addDays(6), data_fine_richiesta: addDays(9),
    });
    expect(r2.url).toContain('error=capacity_full');
    const overlapCount = parseInt(q1(`SELECT COUNT(*) FROM prenotazioni WHERE libro_id=${bookId} AND utente_id=${userB} AND stato='attiva'`), 10);
    expect(overlapCount).toBe(0); // overlapping reservation NOT created

    // user B, disjoint window → allowed
    const csrf3 = await csrfFrom('/admin/reservations/create');
    const r3 = await postForm('/admin/reservations/create', {
      csrf_token: csrf3, libro_id: String(bookId), utente_id: String(userB),
      data_prenotazione: addDays(40), data_scadenza: addDays(45),
      data_inizio_richiesta: addDays(40), data_fine_richiesta: addDays(45),
    });
    const disjointCount = parseInt(q1(`SELECT COUNT(*) FROM prenotazioni WHERE libro_id=${bookId} AND utente_id=${userB} AND stato='attiva'`), 10);
    expect(disjointCount).toBe(1); // disjoint reservation created
    dbQuery(`DELETE FROM prenotazioni WHERE libro_id=${bookId}`);
  });

  test('Invariant sweep — canonical invariants hold across the whole DB', async () => {
    expect(q1(`SELECT COUNT(*) FROM prestiti WHERE stato='completato'`)).toBe('0');                                   // I8
    expect(q1(`SELECT COUNT(*) FROM prestiti WHERE attivo=0 AND stato='in_ritardo'`)).toBe('0');                       // I4
    expect(q1(`SELECT COUNT(*) FROM prestiti WHERE attivo=1 AND data_restituzione IS NOT NULL`)).toBe('0');            // I1 / BUG1
    expect(q1(`SELECT COUNT(*) FROM prestiti WHERE stato IN ('restituito','perso','danneggiato','annullato','scaduto') AND attivo=1`)).toBe('0'); // I1
    expect(q1(`SELECT COUNT(*) FROM prestiti p WHERE p.copia_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM copie c WHERE c.id=p.copia_id AND c.libro_id=p.libro_id)`)).toBe('0'); // I7
  });
});
