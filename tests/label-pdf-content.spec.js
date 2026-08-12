// @ts-check
//
// Real E2E for the copy-label PDF (Nikola #238): it isn't enough that the
// endpoint returns 200 — the produced PDF must actually honour the settings.
// This drives the real settings UI, generates the real PDF via the real
// endpoint, then inspects the bytes with pdfinfo (page size) and pdftotext
// (content) to assert:
//   • custom label size (89×41mm) becomes the PDF page size;
//   • a per-copy print emits ONLY that copy's label (not every copy);
//   • the "label content" checkboxes really add/remove fields (app name off →
//     absent, subtitle on → present, Dewey off → absent).
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const DB_USER   = process.env.E2E_DB_USER   || '';
const DB_PASS   = process.env.E2E_DB_PASS   || '';
const DB_HOST   = process.env.E2E_DB_HOST   || '';
const DB_PORT   = process.env.E2E_DB_PORT   || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME   = process.env.E2E_DB_NAME   || '';

// pdftotext/pdfinfo (poppler) are required to inspect the PDF.
let poppler = true;
try { execFileSync('pdftotext', ['-v'], { stdio: 'ignore' }); execFileSync('pdfinfo', ['-v'], { stdio: 'ignore' }); }
catch { poppler = false; }

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME || (!DB_HOST && !DB_SOCKET), 'E2E credentials not configured');
test.skip(!poppler, 'poppler (pdftotext/pdfinfo) not installed');

function mysqlArgs(sql, batch) {
  const args = [];
  if (DB_HOST) { args.push('-h', DB_HOST); if (DB_PORT) args.push('-P', DB_PORT); }
  else if (DB_SOCKET) { args.push('-S', DB_SOCKET); }
  args.push('-u', DB_USER, DB_NAME);
  if (batch) args.push('-N', '-B');
  args.push('-e', sql);
  return args;
}
function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql, true), { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}
function dbExec(sql) { execFileSync('mysql', mysqlArgs(sql, false), { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }); }
function sqlStr(s) { return "'" + String(s).replace(/'/g, "''") + "'"; }

const RUN = Date.now().toString(36);
const TITLE = `ZLabelTitle ${RUN}`;
const SUBTITLE = `ZLabelSub ${RUN}`;
const DEWEY = '823.92';
const CODE_A = `ZLBL-${RUN}-C2`;
const CODE_B = `ZLBL-${RUN}-C10`;

/** Download the label PDF to a temp file and return {text, widthPt, heightPt}. */
async function inspectLabel(page, urlPath) {
  const resp = await page.request.get(`${BASE}${urlPath}`);
  expect(resp.status(), `GET ${urlPath}`).toBe(200);
  expect(resp.headers()['content-type']).toContain('application/pdf');
  const file = path.join(os.tmpdir(), `label-${RUN}-${Math.random().toString(36).slice(2)}.pdf`);
  fs.writeFileSync(file, await resp.body());
  const text = execFileSync('pdftotext', ['-layout', file, '-'], { encoding: 'utf-8' });
  const info = execFileSync('pdfinfo', [file], { encoding: 'utf-8' });
  fs.unlinkSync(file);
  const m = info.match(/Page size:\s*([\d.]+)\s*x\s*([\d.]+)\s*pts/i);
  return { text, widthPt: m ? parseFloat(m[1]) : 0, heightPt: m ? parseFloat(m[2]) : 0 };
}

/**
 * Height (pt) of the tallest occurrence of `word` in the label PDF, via
 * pdftotext -bbox glyph boxes. Used to prove the content SCALES with the label
 * size (#238) rather than staying a fixed size amid growing margins.
 */
async function measureWordHeight(page, urlPath, word) {
  const resp = await page.request.get(`${BASE}${urlPath}`);
  expect(resp.status(), `GET ${urlPath}`).toBe(200);
  const file = path.join(os.tmpdir(), `bbox-${RUN}-${Math.random().toString(36).slice(2)}.pdf`);
  fs.writeFileSync(file, await resp.body());
  const bbox = execFileSync('pdftotext', ['-bbox', file, '-'], { encoding: 'utf-8' });
  fs.unlinkSync(file);
  let maxH = 0;
  const re = new RegExp(`<word xMin="([\\d.]+)" yMin="([\\d.]+)" xMax="([\\d.]+)" yMax="([\\d.]+)">${word}</word>`, 'gi');
  let mm;
  while ((mm = re.exec(bbox)) !== null) {
    maxH = Math.max(maxH, parseFloat(mm[4]) - parseFloat(mm[2]));
  }
  return maxH;
}

/** Set label size + padding directly in system_settings (the scaling under test
 *  is a property of the renderer; the settings form save path is covered by the
 *  first test). Keeps this test fast and free of UI-timing flakiness. */
function setLabelSize(w, h, padding = 0) {
  const up = (k, v) => dbExec(
    `INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('label', ${sqlStr(k)}, ${sqlStr(String(v))})
     ON DUPLICATE KEY UPDATE setting_value=${sqlStr(String(v))}`
  );
  up('width', w);
  up('height', h);
  up('padding', padding);
  up('show_title', '1');
}

test.describe.serial('Copy-label PDF content (#238)', () => {
  let bookId = 0, copyAId = 0, copyBId = 0;
  /** @type {Record<string,string>} */
  const savedLabel = {};

  test.beforeAll(async () => {
    dbExec(`INSERT INTO libri (titolo, sottotitolo, classificazione_dewey, copie_totali, copie_disponibili, created_at, updated_at)
            VALUES (${sqlStr(TITLE)}, ${sqlStr(SUBTITLE)}, ${sqlStr(DEWEY)}, 2, 2, NOW(), NOW())`);
    bookId = Number(dbQuery(`SELECT id FROM libri WHERE titolo=${sqlStr(TITLE)} ORDER BY id DESC LIMIT 1`));
    dbExec(`INSERT INTO copie (libro_id, stato, numero_inventario, created_at) VALUES
            (${bookId}, 'disponibile', ${sqlStr(CODE_A)}, NOW()), (${bookId}, 'disponibile', ${sqlStr(CODE_B)}, NOW())`);
    copyAId = Number(dbQuery(`SELECT id FROM copie WHERE numero_inventario=${sqlStr(CODE_A)}`));
    copyBId = Number(dbQuery(`SELECT id FROM copie WHERE numero_inventario=${sqlStr(CODE_B)}`));
    // Snapshot label settings so we can restore them.
    for (const k of ['width', 'height', 'padding', 'format_name', 'show_app_name', 'show_title', 'show_subtitle', 'show_author_publisher', 'show_dewey']) {
      savedLabel[k] = dbQuery(`SELECT COALESCE((SELECT setting_value FROM system_settings WHERE category='label' AND setting_key=${sqlStr(k)} LIMIT 1),'')`);
    }
  });

  test.afterAll(async () => {
    dbExec(`DELETE FROM copie WHERE libro_id=${bookId}`);
    dbExec(`DELETE FROM libri WHERE id=${bookId}`);
    // Restore label settings exactly as they were.
    for (const [k, v] of Object.entries(savedLabel)) {
      if (v === '') { dbExec(`DELETE FROM system_settings WHERE category='label' AND setting_key=${sqlStr(k)}`); }
      else { dbExec(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('label', ${sqlStr(k)}, ${sqlStr(v)})
                     ON DUPLICATE KEY UPDATE setting_value=${sqlStr(v)}`); }
    }
  });

  test('custom size 89×41 becomes the page size; per-copy prints only that copy; field toggles apply', async ({ page }) => {
    // Drive the REAL settings UI: custom size + content checkboxes.
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(u => u.toString().includes('/admin'), { timeout: 15000 });

    await page.goto(`${BASE}/admin/settings?tab=labels`);
    await page.locator('[data-settings-tab="labels"]').click();
    await expect(page.locator('section[data-settings-panel="labels"]')).toBeVisible();
    await page.locator('input[name="label_format"][value="custom"]').check();
    await page.locator('input[name="custom_width"]').fill('89');
    await page.locator('input[name="custom_height"]').fill('41');
    await page.locator('input[name="show_app_name"]').uncheck();     // OFF → must be absent
    await page.locator('input[name="show_title"]').check();
    await page.locator('input[name="show_subtitle"]').check();       // ON → must be present
    await page.locator('input[name="show_dewey"]').uncheck();        // OFF → must be absent
    await page.locator('section[data-settings-panel="labels"] button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
    expect(dbQuery(`SELECT setting_value FROM system_settings WHERE category='label' AND setting_key='width'`)).toBe('89');

    // The physical-copy table must use the same human order as the printed
    // labels: C2 precedes C10 instead of VARCHAR's C10, C2 ordering.
    await page.goto(`${BASE}/admin/books/${bookId}`);
    const copyRows = page.locator('a[href*="copy-labels-pdf?copy_id="]').locator('xpath=ancestor::tr');
    await expect(copyRows).toHaveCount(2);
    const inventoryCodes = await copyRows.locator('td:first-child').allTextContents();
    expect(inventoryCodes.map((code) => code.trim())).toEqual([CODE_A, CODE_B]);

    // Single copy A.
    const a = await inspectLabel(page, `/admin/books/${bookId}/copy-labels-pdf?copy_id=${copyAId}`);
    // 89mm × 41mm in points (1mm = 2.83465pt): 252.3 × 116.2, allow ±3pt.
    expect(Math.abs(a.widthPt - 252.28)).toBeLessThan(3);
    expect(Math.abs(a.heightPt - 116.22)).toBeLessThan(3);
    expect(a.text).toContain(CODE_A);                 // this copy's code…
    expect(a.text).not.toContain(CODE_B);             // …and ONLY this copy
    expect(a.text).toContain(TITLE);                  // title shown
    expect(a.text).toContain(SUBTITLE);               // subtitle ON → present
    expect(a.text).not.toContain('Pinakes');          // app name OFF → absent
    expect(a.text).not.toContain(DEWEY);              // Dewey OFF → absent

    // Whole book (no copy_id) → BOTH copies' labels.
    const all = await inspectLabel(page, `/admin/books/${bookId}/copy-labels-pdf`);
    expect(all.text).toContain(CODE_A);
    expect(all.text).toContain(CODE_B);
    expect(all.text.indexOf(CODE_A)).toBeLessThan(all.text.indexOf(CODE_B));
  });

  test('content scales with the label size (#238) and padding insets it', async ({ page }) => {
    // Fresh page → authenticate before hitting the (admin-only) label endpoint.
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(u => u.toString().includes('/admin'), { timeout: 15000 });

    // The title word we measure. TITLE is `ZLabelTitle ${RUN}`.
    const word = 'ZLabelTitle';

    // Small label vs a much larger one: the title must grow substantially, not
    // stay a fixed size (the reported bug was fixed-size content + growing margins).
    setLabelSize(25, 38, 0);
    const small = await measureWordHeight(page, `/admin/books/${bookId}/copy-labels-pdf?copy_id=${copyAId}`, word);

    setLabelSize(89, 41, 0);
    const large = await measureWordHeight(page, `/admin/books/${bookId}/copy-labels-pdf?copy_id=${copyAId}`, word);

    expect(small, 'title measurable on the small label').toBeGreaterThan(0);
    expect(large, 'title measurable on the large label').toBeGreaterThan(0);
    // Proportional scaling: the title on the ~3× taller/wider label is clearly bigger.
    expect(large).toBeGreaterThan(small * 1.8);

    // Padding must shrink the drawn content vs no padding on the same size.
    const noPad = await measureWordHeight(page, `/admin/books/${bookId}/copy-labels-pdf?copy_id=${copyAId}`, word);
    setLabelSize(89, 41, 10);
    const withPad = await measureWordHeight(page, `/admin/books/${bookId}/copy-labels-pdf?copy_id=${copyAId}`, word);
    expect(withPad, 'title still present with padding').toBeGreaterThan(0);
    expect(withPad).toBeLessThan(noPad);
  });
});
