// @ts-check
//
// Data-integrity audit — 21 E2E tests, one per fixed point of the extreme
// data-consistency review. Each test drives the REAL app on :8081 (browser UI
// and/or HTTP endpoints) and asserts against the live DB, so a fix that "looks
// right" but doesn't hold end-to-end fails here.
//
// Coverage map (finding → test):
//   #21 copies not deleted on edit-save        → "edit-save keeps out-of-circulation copies"
//   #3  create recalculates availability       → "create recomputes copie_disponibili/stato"
//   #6  genre merge preserves shelf positions  → "genre merge keeps shelf positions"
//   collocazione format parity                 → "collocazione export is dash-padded"
//   #11 export illustratore + dewey            → "export includes illustratore + dewey"
//   #12 export multi-publisher                 → "export joins co-publishers"
//   #9  export curatore                        → "export includes curatore"
//   #17 co-author/colorist role roundtrip      → "export/reimport preserves contributor roles"
//   #11 reimport keeps illustratore + dewey    → import block
//   #12 import splits publishers               → import block
//   #14 formula unescape                       → import block
//   #16 genre create-on-import                 → import block
//   #20 copie clamp raised                     → import block
//   #13 duplicate-header collision             → "import keeps first non-empty on header collision"
//   #22 empty author cell preserved            → "reimport with empty author keeps authors"
//   #10 descrizione_plain synced               → "import syncs descrizione_plain"
//   #15 rich HTML preserved on roundtrip       → "reimport of plain text keeps rich description"
//   counts DISTINCT (author 2 roles = 1 book)  → "author book count is distinct"
//   #7  import normalizes contributor columns   → "import normalizes the free-text contributor columns"
//   home/catalog badge for maintenance book    → "unavailable book shows Non disponibile"
//   publisher facet merges duplicate names     → "duplicate publisher names collapse in facet"

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const RUN = Date.now().toString(36);
const NRUN = String(Date.now()).slice(-9); // 9 digits, for valid ISBNs
// Build a checksum-valid ISBN-13 from a 12-digit base (normalizeIsbn rejects
// a wrong check digit, so test ISBNs must be genuinely valid).
function isbn13(base12) {
  let s = 0;
  for (let i = 0; i < 12; i++) s += Number(base12[i]) * (i % 2 === 0 ? 1 : 3);
  return base12 + ((10 - (s % 10)) % 10);
}

// This spec hits the DB directly via q()/exec()/insertId(), so it needs the
// DB config too — without it mysqlArgs() would splice undefined into argv.
test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !process.env.E2E_DB_USER || !process.env.E2E_DB_NAME,
  'E2E admin credentials or database configuration not available'
);

// ---- DB helpers (mysql CLI, mirrors tests/helpers/e2e-fixtures.js) ----------
function mysqlArgs(sql) {
  const args = ['-N', '-B', '-e', sql];
  if (process.env.E2E_DB_HOST) args.push('-h', process.env.E2E_DB_HOST);
  if (process.env.E2E_DB_SOCKET) args.push('-S', process.env.E2E_DB_SOCKET);
  args.push('-u', process.env.E2E_DB_USER);
  if ((process.env.E2E_DB_PASS || '') !== '') args.push(`-p${process.env.E2E_DB_PASS}`);
  args.push(process.env.E2E_DB_NAME);
  return args;
}
function q(sql) {
  return execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf8', timeout: 15000 }).trim();
}
function exec(sql) {
  execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf8', timeout: 15000 });
}
// INSERT + return the new id in the SAME connection (each mysql CLI call is a
// fresh connection, so a separate LAST_INSERT_ID() query would return 0).
function insertId(sql) {
  const out = q(`${sql}; SELECT LAST_INSERT_ID()`);
  const lines = out.split(/\n/).filter(Boolean);
  return Number(lines[lines.length - 1]);
}
function esc(v) { return String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'"); }

// ---- UI helpers -------------------------------------------------------------
async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const email = page.locator('input[name="email"]');
  if (await email.isVisible({ timeout: 4000 }).catch(() => false)) {
    await email.fill(ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/.*(?:dashboard|admin).*/, { timeout: 15000 });
  }
}

// Drive the real CSV import UI (Uppy input + chunk loop). Returns the final
// chunk summary {complete, errors, ...}.
async function importCsv(page, csvPath) {
  await page.goto(`${BASE}/admin/books/import`);
  let lastChunk = null;
  const statuses = [];
  page.on('response', async (r) => {
    const u = r.url();
    if (u.includes('/admin/books/import/upload') || u.includes('/admin/books/import/chunk')) {
      statuses.push(r.status());
      if (u.includes('/chunk')) { try { lastChunk = JSON.parse(await r.text()); } catch { /* */ } }
    }
  });
  await page.setInputFiles('#csv_file', csvPath);
  await page.evaluate(() => { const b = document.getElementById('submitBtn'); if (b) b.disabled = false; });
  await page.click('#submitBtn');
  await expect.poll(() => (lastChunk && lastChunk.complete === true) ? true : false,
    { timeout: 120000, intervals: [1500] }).toBe(true);
  expect(statuses.every((s) => s === 200)).toBeTruthy();
  return lastChunk;
}

// Write a ';'-delimited CSV (BOM + CRLF, cells quoted when needed) to a temp file.
function writeCsv(name, headers, rows) {
  const cell = (v) => {
    const s = v == null ? '' : String(v);
    return /[";\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };
  const lines = [headers.join(';'), ...rows.map((r) => r.map(cell).join(';'))];
  const p = path.join(os.tmpdir(), `di-${RUN}-${name}.csv`);
  fs.writeFileSync(p, '﻿' + lines.join('\r\n') + '\r\n');
  return p;
}

// A valid subset of the standard import columns, in export order. The importer
// maps columns by NAME, so a CSV built from this subset is valid input even
// though the real export also emits co_autori / colorista (those roles are
// simply "not provided" here, which the importer preserves). The full-fidelity
// 26-column round-trip is exercised by the "#17" test, which re-imports the
// actual export output rather than this subset.
const STD_HEADERS = [
  'id', 'isbn10', 'isbn13', 'ean', 'titolo', 'sottotitolo', 'descrizione',
  'autori', 'editore', 'anno_pubblicazione', 'lingua', 'edizione',
  'numero_pagine', 'genere', 'formato', 'tipo_media', 'prezzo', 'copie_totali',
  'collana', 'numero_serie', 'traduttore', 'illustratore', 'curatore',
  'classificazione_dewey', 'parole_chiave',
];

// track ids to clean up
const created = { libri: [], editori: [], generi: [], autori: [] };
function seedBook(fields) {
  const cols = Object.keys(fields);
  const vals = cols.map((c) => `'${esc(fields[c])}'`);
  const id = insertId(`INSERT INTO libri (${cols.join(',')}, created_at, updated_at) VALUES (${vals.join(',')}, NOW(), NOW())`);
  created.libri.push(id);
  return id;
}

test.afterAll(() => {
  for (const id of created.libri) {
    try {
      exec(`DELETE FROM libri_editori WHERE libro_id=${id}`);
      exec(`DELETE FROM libri_autori WHERE libro_id=${id}`);
      exec(`DELETE FROM copie WHERE libro_id=${id}`);
      exec(`DELETE FROM libri WHERE id=${id}`);
    } catch { /* */ }
  }
  for (const id of created.editori) { try { exec(`DELETE FROM editori WHERE id=${id}`); } catch { /* */ } }
  for (const id of created.generi) { try { exec(`DELETE FROM generi WHERE id=${id}`); } catch { /* */ } }
  for (const id of created.autori) { try { exec(`DELETE FROM autori WHERE id=${id}`); } catch { /* */ } }
});

// ============================================================================
// 1) COPIES & AVAILABILITY
// ============================================================================
test.describe.serial('Copies & availability', () => {
  test('#21 edit-save keeps out-of-circulation copies', async ({ page }) => {
    test.setTimeout(60000);
    const title = `DI21_${RUN}`;
    const id = seedBook({ titolo: title, copie_totali: 1, copie_disponibili: 1, stato: 'disponibile' });
    // 2 physical copies: 1 available, 1 in maintenance (out of circulation).
    exec(`INSERT INTO copie (libro_id, numero_inventario, stato, created_at) VALUES
      (${id}, 'DI21-${RUN}-C1', 'disponibile', NOW()),
      (${id}, 'DI21-${RUN}-C2', 'manutenzione', NOW())`);
    // Recalc so libri.copie_totali reflects the in-circulation count (=1).
    exec(`UPDATE libri SET copie_totali=1, copie_disponibili=1 WHERE id=${id}`);

    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/edit/${id}`);
    // Save the form unchanged (copie_totali pre-filled = 1).
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');

    const copies = Number(q(`SELECT COUNT(*) FROM copie WHERE libro_id=${id}`));
    expect(copies).toBe(2); // the maintenance copy must NOT be deleted
    const maint = Number(q(`SELECT COUNT(*) FROM copie WHERE libro_id=${id} AND stato='manutenzione'`));
    expect(maint).toBe(1);
  });

  test('#3 create recomputes copie_disponibili/stato', async ({ page }) => {
    test.setTimeout(60000);
    const title = `DI3_${RUN}`;
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/create`);
    // Direct POST with the form CSRF token — the create form has client-side JS
    // (Choices.js/TinyMCE) that makes a raw UI submit brittle; the server store()
    // path (which now runs the availability recalc) is what we exercise here.
    const csrf = await page.inputValue('input[name="csrf_token"]').catch(() => '');
    const res = await page.request.post(`${BASE}/admin/books/create`, {
      form: { titolo: title, copie_totali: '3', lingua: 'italiano', csrf_token: csrf },
    });
    expect([200, 302]).toContain(res.status());

    const row = q(`SELECT id, copie_disponibili, stato FROM libri WHERE titolo='${esc(title)}' ORDER BY id DESC LIMIT 1`);
    expect(row).not.toBe('');
    const [id, disp, stato] = row.split('\t');
    created.libri.push(Number(id));
    expect(Number(disp)).toBe(3);           // recalc ran on create
    expect(stato).toBe('disponibile');
  });

  test('unavailable (maintenance) book shows Non disponibile badge', async ({ page }) => {
    const title = `DIbadge_${RUN}`;
    // List the book via an editore filter (a name match, NOT the FULLTEXT `q`
    // search — the dev MySQL FULLTEXT index rots and makes `?q=` flaky). This
    // deterministically returns exactly this book's card.
    const pubName = `DIbadgePub_${RUN}`;
    const pubId = insertId(`INSERT INTO editori (nome, created_at) VALUES ('${esc(pubName)}', NOW())`); created.editori.push(pubId);
    const id = seedBook({ titolo: title, editore_id: pubId, copie_totali: 0, copie_disponibili: 0, stato: 'non_disponibile' });
    exec(`INSERT INTO copie (libro_id, numero_inventario, stato, created_at) VALUES (${id}, 'DIbadge-${RUN}', 'manutenzione', NOW())`);
    const res = await page.request.get(`${BASE}/catalogo?editore=${encodeURIComponent(pubName)}`);
    const html = await res.text();
    expect(html).toContain(title);
    // Around the book card: the "Non disponibile" badge label must be rendered,
    // and the book must NOT be advertised as "In prestito" when nothing is on
    // loan. (Assert the visible label, not the CSS class name, which also appears
    // in the page's <style> block regardless of any badge.)
    const at = html.indexOf(title);
    const slice = html.slice(Math.max(0, at - 2500), at + 2500);
    expect(slice.toLowerCase()).not.toContain('in prestito');
    expect(slice).toContain('Non disponibile');
  });
});

// ============================================================================
// 2) EXPORT
// ============================================================================
test.describe.serial('CSV export', () => {
  let ctx;
  test.beforeAll(async ({ browser }) => {
    ctx = await browser.newContext();
    const page = await ctx.newPage();
    await loginAsAdmin(page);
    await page.close();
  });
  test.afterAll(async () => { if (ctx) await ctx.close(); });

  test('#11/#9 export includes illustratore, curatore and dewey columns', async () => {
    const page = await ctx.newPage();
    const title = `DIexp_${RUN}`;
    const id = seedBook({
      titolo: title, copie_totali: 1, copie_disponibili: 1, stato: 'disponibile',
      illustratore: 'Ilaria Illus', curatore: 'Carlo Cura', classificazione_dewey: '853.914',
    });
    const res = await page.request.get(`${BASE}/admin/books/export/csv?ids=${id}`);
    const csv = await res.text();
    await page.close();
    const header = csv.split(/\r?\n/)[0];
    expect(header).toContain('illustratore');
    expect(header).toContain('curatore');
    expect(header).toContain('classificazione_dewey');
    expect(csv).toContain('Ilaria Illus');
    expect(csv).toContain('Carlo Cura');
    expect(csv).toContain('853.914');
  });

  test('#12 export joins primary + co-publishers in the editore cell', async () => {
    const page = await ctx.newPage();
    const title = `DIpub_${RUN}`;
    const pA = insertId(`INSERT INTO editori (nome, created_at) VALUES ('DIPrimary_${RUN}', NOW())`); created.editori.push(pA);
    const pB = insertId(`INSERT INTO editori (nome, created_at) VALUES ('DICo_${RUN}', NOW())`); created.editori.push(pB);
    const id = seedBook({ titolo: title, editore_id: pA, copie_totali: 1, copie_disponibili: 1, stato: 'disponibile' });
    exec(`INSERT INTO libri_editori (libro_id, editore_id, ordine) VALUES (${id}, ${pA}, 0), (${id}, ${pB}, 1)`);
    const res = await page.request.get(`${BASE}/admin/books/export/csv?ids=${id}`);
    const csv = await res.text();
    await page.close();
    expect(csv).toContain(`DIPrimary_${RUN};DICo_${RUN}`.replace(';', ';')); // both names, ';'-joined
    expect(csv).toMatch(new RegExp(`DIPrimary_${RUN}[^\\n]*DICo_${RUN}`));
  });

  test('#17 export/reimport preserves principal, co-author and colorist roles', async () => {
    test.setTimeout(150000);
    const page = await ctx.newPage();
    const title = `DIroles_${RUN}`;
    const id = seedBook({ titolo: title, copie_totali: 1, copie_disponibili: 1, stato: 'disponibile' });
    const principalName = `DI Principal ${RUN}`;
    const coauthorName = `DI Coauthor ${RUN}`;
    const coloristName = `DI Colorist ${RUN}`;
    const principalId = insertId(`INSERT INTO autori (nome, created_at) VALUES ('${esc(principalName)}', NOW())`);
    const coauthorId = insertId(`INSERT INTO autori (nome, created_at) VALUES ('${esc(coauthorName)}', NOW())`);
    const coloristId = insertId(`INSERT INTO autori (nome, created_at) VALUES ('${esc(coloristName)}', NOW())`);
    created.autori.push(principalId, coauthorId, coloristId);
    exec(`INSERT INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito) VALUES
      (${id}, ${principalId}, 'principale', 1),
      (${id}, ${coauthorId}, 'co-autore', 2),
      (${id}, ${coloristId}, 'colorista', 3)`);

    const res = await page.request.get(`${BASE}/admin/books/export/csv?ids=${id}&autore_id=${principalId}`);
    const csv = await res.text();
    const lines = csv.replace(/^\uFEFF/, '').trim().split(/\r?\n/);
    const headers = lines[0].split(';');
    const cells = lines[1].split(';').map((cell) => {
      if (cell.startsWith('"') && cell.endsWith('"')) {
        return cell.slice(1, -1).replace(/""/g, '"');
      }
      return cell;
    });
    expect(cells[headers.indexOf('autori')]).toBe(principalName);
    expect(cells[headers.indexOf('co_autori')]).toBe(coauthorName);
    expect(cells[headers.indexOf('colorista')]).toBe(coloristName);

    // Prove the exported role columns are authoritative on re-import, rather
    // than merely present in the file: erase all three links and restore them
    // only through the real chunked CSV import route.
    exec(`DELETE FROM libri_autori WHERE libro_id=${id}`);
    const csvPath = path.join(os.tmpdir(), `di-${RUN}-role-roundtrip.csv`);
    fs.writeFileSync(csvPath, csv);
    const summary = await importCsv(page, csvPath);
    expect(summary.errors).toBe(0);

    const roles = q(`SELECT CONCAT(a.nome, ':', la.ruolo)
      FROM libri_autori la JOIN autori a ON a.id=la.autore_id
      WHERE la.libro_id=${id}
      ORDER BY FIELD(la.ruolo, 'principale', 'co-autore', 'colorista'), a.nome`).split('\n');
    expect(roles).toEqual([
      `${principalName}:principale`,
      `${coauthorName}:co-autore`,
      `${coloristName}:colorista`,
    ]);
    await page.close();
  });
});

// ============================================================================
// 3) CSV IMPORT ROUNDTRIP (single import, multiple assertions)
// ============================================================================
test.describe.serial('CSV import roundtrip', () => {
  let summary;
  const rtTitle = `DIrt_${RUN}`;
  const fxTitle = `=SUM(1+1)_${RUN}`;
  const genreName = `DIgenre_${RUN}`;
  // Books are identified by their unique TITLE (RUN is base36, so it can't be
  // used to build a valid-length numeric ISBN — normalizeIsbn would drop it).
  const rtId = () => Number(q(`SELECT id FROM libri WHERE titolo='${esc(rtTitle)}' ORDER BY id DESC LIMIT 1`));

  test.beforeAll(async ({ browser }) => {
    const page = await (await browser.newContext()).newPage();
    await loginAsAdmin(page);
    const rows = [
      // illustratore + curatore + dewey (#11/#9), multi-publisher (#12),
      // new genre (#16), 150 copies (#20)
      ['', '', '', '', rtTitle, '', 'Una descrizione semplice.',
       'Primo Autore', `DIimpA_${RUN};DIimpB_${RUN}`, '2020', 'Italiano', '',
       '200', genreName, 'cartaceo', '', '10.00', '150',
       '', '', 'Trad Uno', 'Illus Due', 'Cura Tre', '823.912', 'kw1, kw2'],
      // formula-injection escaped title (#14)
      ['', '', '', '', `'${fxTitle}`, '', '',
       'Autore Formula', '', '2021', 'Italiano', '', '', '', 'cartaceo', '', '', '1',
       '', '', '', '', '', '', ''],
    ];
    const csv = writeCsv('roundtrip', STD_HEADERS, rows);
    summary = await importCsv(page, csv);
    await page.close();
    // track created rows for cleanup
    for (const r of q(`SELECT id FROM libri WHERE titolo='${esc(rtTitle)}' OR titolo='${esc(fxTitle)}'`).split(/\n/).filter(Boolean)) {
      created.libri.push(Number(r));
    }
    for (const r of q(`SELECT id FROM editori WHERE nome IN ('DIimpA_${RUN}','DIimpB_${RUN}')`).split(/\n/).filter(Boolean)) created.editori.push(Number(r));
    for (const r of q(`SELECT id FROM generi WHERE nome='${esc(genreName)}'`).split(/\n/).filter(Boolean)) created.generi.push(Number(r));
    for (const r of q(`SELECT id FROM autori WHERE nome IN ('Primo Autore','Autore Formula')`).split(/\n/).filter(Boolean)) created.autori.push(Number(r));
  });

  test('import completed with no row errors', () => {
    expect(summary).toBeTruthy();
    expect(summary.errors).toBe(0);
  });

  test('#11 reimport keeps illustratore + classificazione_dewey', () => {
    const row = q(`SELECT illustratore, curatore, classificazione_dewey FROM libri WHERE id=${rtId()}`);
    const [ill, cur, dewey] = row.split('\t');
    expect(ill).toBe('Illus Due');
    expect(cur).toBe('Cura Tre');
    expect(dewey).toBe('823.912');
  });

  test('#12 import splits editore into primary + co-publisher', () => {
    const id = rtId();
    const primary = q(`SELECT e.nome FROM libri l JOIN editori e ON l.editore_id=e.id WHERE l.id=${id}`);
    expect(primary).toBe(`DIimpA_${RUN}`);
    const co = q(`SELECT COUNT(*) FROM libri_editori le JOIN editori e ON le.editore_id=e.id WHERE le.libro_id=${id} AND e.nome='DIimpB_${RUN}'`);
    expect(Number(co)).toBe(1);
    // NO bogus single "A;B" publisher was created.
    const bogus = Number(q(`SELECT COUNT(*) FROM editori WHERE nome='DIimpA_${RUN};DIimpB_${RUN}'`));
    expect(bogus).toBe(0);
  });

  test('#16 genre created on import', () => {
    const gid = q(`SELECT id FROM generi WHERE nome='${esc(genreName)}'`);
    expect(gid).not.toBe('');
    const linked = Number(q(`SELECT COUNT(*) FROM libri WHERE id=${rtId()} AND genere_id=${Number(gid)}`));
    expect(linked).toBe(1);
  });

  test('#20 copie_totali above the old cap of 100 is preserved', () => {
    const copies = Number(q(`SELECT COUNT(*) FROM copie WHERE libro_id=${rtId()}`));
    expect(copies).toBe(150);
  });

  test('#14 formula-injection apostrophe is stripped on import', () => {
    const t = q(`SELECT titolo FROM libri WHERE titolo='${esc(fxTitle)}'`);
    expect(t).toBe(fxTitle);
  });

  test('#10 descrizione_plain is synced from descrizione', () => {
    const plain = q(`SELECT descrizione_plain FROM libri WHERE id=${rtId()}`);
    expect(plain).toBe('Una descrizione semplice.');
  });
});

// ============================================================================
// 4) HEADER COLLISION (#13)
// ============================================================================
test.describe.serial('Duplicate-header collision', () => {
  test('#13 first non-empty value wins on colliding headers', async ({ browser }) => {
    test.setTimeout(150000);
    const page = await (await browser.newContext()).newPage();
    await loginAsAdmin(page);
    const isbn = isbn13(`978${NRUN}`);        // valid ISBN-13 (first ISBN column)
    const isbnOther = isbn13(`979${NRUN}`);   // valid, distinct (the ISBNs column)
    // 'Tags' and 'Subjects' both map to parole_chiave; 'ISBN' + 'ISBNs' → isbn13.
    const headers = ['titolo', 'ISBN', 'ISBNs', 'Tags', 'Subjects'];
    const rows = [[`DIcol_${RUN}`, isbn, isbnOther, 'alpha-first', 'beta-second']];
    const csv = writeCsv('collision', headers, rows);
    const summary = await importCsv(page, csv);
    await page.close();
    expect(summary.errors).toBe(0);
    for (const r of q(`SELECT id FROM libri WHERE titolo='DIcol_${RUN}'`).split(/\n/).filter(Boolean)) created.libri.push(Number(r));
    const row = q(`SELECT isbn13, parole_chiave FROM libri WHERE titolo='DIcol_${RUN}'`);
    const [storedIsbn, kw] = row.split('\t');
    expect(storedIsbn).toBe(isbn);   // first ISBN column won, not the ISBNs value
    expect(kw).toBe('alpha-first');  // first tag column won, not Subjects
  });
});

// ============================================================================
// 5) EMPTY AUTHOR PRESERVED (#22) + DESCRIPTION PRESERVE (#15)
// ============================================================================
test.describe.serial('Re-import safety', () => {
  test('#22 re-import with empty author cell keeps existing authors', async ({ browser }) => {
    test.setTimeout(150000);
    const page = await (await browser.newContext()).newPage();
    await loginAsAdmin(page);
    const id = seedBook({ titolo: `DI22_${RUN}`, copie_totali: 1, copie_disponibili: 1, stato: 'disponibile' });
    const aid = insertId(`INSERT INTO autori (nome, created_at) VALUES ('DI22 Author_${RUN}', NOW())`); created.autori.push(aid);
    exec(`INSERT INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito) VALUES (${id}, ${aid}, 'principale', 1)`);

    // Re-import a row matching by ID with an EMPTY author cell.
    const rows = [[String(id), '', '', '', `DI22_${RUN}`, '', 'nuova descrizione', '', '', '2019', 'Italiano', '', '', '', 'cartaceo', '', '', '1', '', '', '', '', '', '', '']];
    const csv = writeCsv('emptyauthor', STD_HEADERS, rows);
    const summary = await importCsv(page, csv);
    await page.close();
    expect(summary.errors).toBe(0);
    const links = Number(q(`SELECT COUNT(*) FROM libri_autori WHERE libro_id=${id} AND ruolo='principale'`));
    expect(links).toBe(1); // author NOT wiped by the empty cell
  });

  test('#15 re-import of plain text keeps the rich HTML description', async ({ browser }) => {
    test.setTimeout(150000);
    const page = await (await browser.newContext()).newPage();
    await loginAsAdmin(page);
    const richHtml = '<p>Riga <strong>uno</strong>.</p><p>Riga due.</p>';
    const plain = 'Riga uno.\n\nRiga due.';
    const id = seedBook({ titolo: `DI15_${RUN}`, descrizione: richHtml, descrizione_plain: plain, copie_totali: 1, copie_disponibili: 1, stato: 'disponibile' });

    // Re-import the PLAIN projection (what an export would produce), matched by ID.
    const rows = [[String(id), '', '', '', `DI15_${RUN}`, '', plain, '', '', '2018', 'Italiano', '', '', '', 'cartaceo', '', '', '1', '', '', '', '', '', '', '']];
    const csv = writeCsv('descpreserve', STD_HEADERS, rows);
    const summary = await importCsv(page, csv);
    await page.close();
    expect(summary.errors).toBe(0);
    const stored = q(`SELECT descrizione FROM libri WHERE id=${id}`);
    expect(stored).toContain('<strong>'); // rich HTML preserved on pure round-trip
  });
});

// ============================================================================
// 6) GENRE / LOCATION
// ============================================================================
test.describe.serial('Genre & location', () => {
  test('#6 genre merge keeps shelf positions and shelved books', async ({ page }) => {
    test.setTimeout(90000);
    await loginAsAdmin(page);
    // Source + target genres
    const src = insertId(`INSERT INTO generi (nome, created_at) VALUES ('DIsrc_${RUN}', NOW())`); created.generi.push(src);
    const dst = insertId(`INSERT INTO generi (nome, created_at) VALUES ('DIdst_${RUN}', NOW())`); created.generi.push(dst);
    // Shelf + shelf-unit + position bound to the SOURCE genre
    const scaff = insertId(`INSERT INTO scaffali (nome, codice, lettera, created_at) VALUES ('DIshelf_${RUN}', 'DS${RUN.slice(-6)}', 'Z', NOW())`);
    const mens = insertId(`INSERT INTO mensole (scaffale_id, numero_livello, genere_id, created_at) VALUES (${scaff}, 1, ${src}, NOW())`);
    const pos = insertId(`INSERT INTO posizioni (scaffale_id, mensola_id, genere_id, created_at) VALUES (${scaff}, ${mens}, ${src}, NOW())`);
    const id = seedBook({ titolo: `DI6_${RUN}`, genere_id: src, posizione_id: pos, copie_totali: 1, copie_disponibili: 1, stato: 'disponibile' });

    // Merge source → target via the admin endpoint.
    await page.goto(`${BASE}/admin/genres`);
    const csrf = await page.getAttribute('meta[name="csrf-token"]', 'content').catch(() => null);
    const res = await page.request.post(`${BASE}/admin/genres/${src}/merge`, {
      form: { target_id: String(dst), ...(csrf ? { csrf_token: csrf } : {}) },
    });
    expect([200, 302]).toContain(res.status());

    // The position row must survive (repointed to the target), and the book keeps it.
    const posStillThere = Number(q(`SELECT COUNT(*) FROM posizioni WHERE id=${pos}`));
    expect(posStillThere).toBe(1);
    const bookPos = q(`SELECT posizione_id FROM libri WHERE id=${id}`);
    expect(bookPos).toBe(String(pos)); // book NOT unshelved
    // cleanup extra rows
    try { exec(`DELETE FROM posizioni WHERE id=${pos}`); exec(`DELETE FROM mensole WHERE id=${mens}`); exec(`DELETE FROM scaffali WHERE id=${scaff}`); } catch { /* */ }
  });

  test('collocazione export uses the dash-padded canonical format', async ({ page }) => {
    await loginAsAdmin(page);
    // The collocazione export only lists books actually assigned to a shelf.
    const gid = insertId(`INSERT INTO generi (nome, created_at) VALUES ('DIcolG_${RUN}', NOW())`); created.generi.push(gid);
    const scaff = insertId(`INSERT INTO scaffali (nome, codice, lettera, created_at) VALUES ('DIcolS_${RUN}', 'CC${RUN.slice(-6)}', 'A', NOW())`);
    const mens = insertId(`INSERT INTO mensole (scaffale_id, numero_livello, genere_id, created_at) VALUES (${scaff}, 2, ${gid}, NOW())`);
    const id = seedBook({ titolo: `DIcol2_${RUN}`, scaffale_id: scaff, mensola_id: mens, collocazione: 'A-2-03', copie_totali: 1, copie_disponibili: 1, stato: 'disponibile' });
    const res = await page.request.get(`${BASE}/api/collocazione/export-csv`);
    expect(res.status()).toBe(200);
    const csv = await res.text();
    // Stored dash-padded value emitted; the old dotted 'A.2.3' form must not appear.
    expect(csv).toContain('A-2-03');
    expect(csv).not.toContain('A.2.3');
    try { exec(`DELETE FROM mensole WHERE id=${mens}`); exec(`DELETE FROM scaffali WHERE id=${scaff}`); } catch { /* */ }
  });
});

// ============================================================================
// 7) COUNTS / FACETS / API
// ============================================================================
test.describe.serial('Counts, facets & API parity', () => {
  test('author book count is distinct across multiple roles', async ({ page }) => {
    await loginAsAdmin(page);
    const aid = insertId(`INSERT INTO autori (nome, created_at) VALUES ('DIcount_${RUN}', NOW())`); created.autori.push(aid);
    const id = seedBook({ titolo: `DIcount_${RUN}`, copie_totali: 1, copie_disponibili: 1, stato: 'disponibile' });
    // Same author on the SAME book in two roles → must still count as 1 book.
    exec(`INSERT INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito) VALUES (${id}, ${aid}, 'principale', 1), (${id}, ${aid}, 'curatore', 2)`);
    const res = await page.request.get(`${BASE}/api/autori?search_text=${encodeURIComponent(`DIcount_${RUN}`)}`);
    const body = await res.json();
    const list = Array.isArray(body) ? body : (body.data || body.autori || []);
    const mine = list.find((a) => (a.nome || '').includes(`DIcount_${RUN}`));
    expect(mine).toBeTruthy();
    const count = Number(mine.libri_count ?? mine.num_libri ?? mine.books_count ?? mine.count);
    expect(count).toBe(1);
  });

  test('#7 import normalizes the free-text contributor columns', async ({ browser }) => {
    test.setTimeout(150000);
    const page = await (await browser.newContext()).newPage();
    await loginAsAdmin(page);
    const title = `DInorm_${RUN}`;
    // "Rossi, Mario" (inverted Surname, Forename) must be stored as "Mario Rossi"
    // — the same AuthorNormalizer every other writer applies.
    const rows = [['', '', '', '', title, '', '', 'Autore Norm', '', '2017', 'Italiano', '', '', '', 'cartaceo', '', '', '1', '', '', 'Rossi, Mario', 'Bianchi, Anna', 'Verdi, Luca', '', '']];
    const csv = writeCsv('normalizer', STD_HEADERS, rows);
    const summary = await importCsv(page, csv);
    await page.close();
    expect(summary.errors).toBe(0);
    for (const r of q(`SELECT id FROM libri WHERE titolo='${esc(title)}'`).split(/\n/).filter(Boolean)) created.libri.push(Number(r));
    for (const r of q(`SELECT id FROM autori WHERE nome='Autore Norm'`).split(/\n/).filter(Boolean)) created.autori.push(Number(r));
    const row = q(`SELECT traduttore, illustratore, curatore FROM libri WHERE titolo='${esc(title)}'`);
    const [trad, ill, cur] = row.split('\t');
    expect(trad).toBe('Mario Rossi');
    expect(ill).toBe('Anna Bianchi');
    expect(cur).toBe('Luca Verdi');
  });

  test('duplicate publisher names filter as one (union of same-named ids)', async ({ page }) => {
    const name = `DIfacet_${RUN}`;
    const e1 = insertId(`INSERT INTO editori (nome, created_at) VALUES ('${esc(name)}', NOW())`); created.editori.push(e1);
    const e2 = insertId(`INSERT INTO editori (nome, created_at) VALUES ('${esc(name)}', NOW())`); created.editori.push(e2);
    seedBook({ titolo: `DIfacet1_${RUN}`, editore_id: e1, copie_totali: 1, copie_disponibili: 1, stato: 'disponibile' });
    seedBook({ titolo: `DIfacet2_${RUN}`, editore_id: e2, copie_totali: 1, copie_disponibili: 1, stato: 'disponibile' });
    // The facet groups by NAME, and the filter matches by NAME: filtering by the
    // duplicate name must return BOTH books (the union of the same-named ids),
    // so the single facet entry's count agrees with what the filter returns.
    const res = await page.request.get(`${BASE}/catalogo?editore=${encodeURIComponent(name)}`);
    const html = await res.text();
    expect(html).toContain(`DIfacet1_${RUN}`);
    expect(html).toContain(`DIfacet2_${RUN}`);
  });
});
