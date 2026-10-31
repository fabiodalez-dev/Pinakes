// @ts-check
//
// Printable library cards (plan phase 7). A librarian configures the card in
// Settings → Tessere, prints one reader's card from the reader's page and many
// cards from the users list selection. Every PDF is fetched through the real
// endpoint and inspected with poppler (pdfinfo for the page size, pdftotext for
// the content): it must be a 200 application/pdf that carries the card code.
const { test, expect } = require('@playwright/test');
const { openSettingsSection } = require('./helpers/settings-navigation');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

let poppler = true;
try { execFileSync('pdftotext', ['-v'], { stdio: 'ignore' }); execFileSync('pdfinfo', ['-v'], { stdio: 'ignore' }); } catch { poppler = false; }

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME || (!DB_HOST && !DB_SOCKET), 'E2E credentials not configured');
test.skip(!poppler, 'poppler (pdftotext/pdfinfo) not installed');

function mysqlArgs(sql) {
  const args = [];
  if (DB_HOST) { args.push('-h', DB_HOST); if (DB_PORT) args.push('-P', DB_PORT); } else if (DB_SOCKET) { args.push('-S', DB_SOCKET); }
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return args;
}
function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}
function sqlStr(s) { return "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "''") + "'"; }

const RUN = Date.now().toString(36).toUpperCase();
// Three readers; the first name gives the "QX" initials the classic layout
// prints in the empty photo box (the minimal layout has no photo box).
const READERS = [
  { nome: 'Quirino', cognome: `Xenon${RUN}`, code: `ZCA${RUN}1` },
  { nome: 'Rosa', cognome: `Yarrow${RUN}`, code: `ZCA${RUN}2` },
  { nome: 'Sara', cognome: `Zinnia${RUN}`, code: `ZCA${RUN}3` },
];
const BACK_TEXT = `Regolamento ${RUN}: restituire i libri entro 30 giorni.`;
const MM = 2.83465;

async function login(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL((u) => u.toString().includes('/admin'), { timeout: 15000 });
}

/**
 * Fetch a card PDF and return its text, page count, first page size (pt) and,
 * per page, every word with its left edge (pt) from `pdftotext -bbox`.
 */
async function inspectPdf(page, url) {
  const resp = await page.request.get(url.startsWith('http') ? url : `${BASE}${url}`);
  expect(resp.status(), `GET ${url}`).toBe(200);
  expect(resp.headers()['content-type']).toContain('application/pdf');
  const file = path.join(os.tmpdir(), `card-${RUN}-${Math.random().toString(36).slice(2)}.pdf`);
  fs.writeFileSync(file, await resp.body());
  try {
    const text = execFileSync('pdftotext', ['-layout', file, '-'], { encoding: 'utf-8' });
    const info = execFileSync('pdfinfo', [file], { encoding: 'utf-8' });
    const size = info.match(/Page size:\s*([\d.]+)\s*x\s*([\d.]+)\s*pts/i);
    const pages = info.match(/Pages:\s*(\d+)/);
    const pageTexts = [];
    /** @type {{text: string, xMin: number}[][]} */
    const pageWords = [];
    const n = pages ? Number(pages[1]) : 0;
    for (let p = 1; p <= n; p++) {
      pageTexts.push(execFileSync('pdftotext', ['-f', String(p), '-l', String(p), '-layout', file, '-'], { encoding: 'utf-8' }));
      const bbox = execFileSync('pdftotext', ['-f', String(p), '-l', String(p), '-bbox', file, '-'], { encoding: 'utf-8' });
      pageWords.push([...bbox.matchAll(/<word xMin="([\d.]+)"[^>]*>([^<]*)<\/word>/g)].map((m) => ({ text: m[2], xMin: parseFloat(m[1]) })));
    }
    return { text, pages: n, pageTexts, pageWords, width: size ? parseFloat(size[1]) : 0, height: size ? parseFloat(size[2]) : 0 };
  } finally {
    fs.unlinkSync(file);
  }
}

/**
 * Click a control that opens the PDF in a new tab and return the URL the tab
 * requested. A PDF tab may never commit a URL in headless Chromium (it turns
 * into a download), so the request is what proves where the click leads.
 */
async function openedPdfUrl(page, locator, pathPart) {
  const [request] = await Promise.all([
    page.context().waitForEvent('request', (r) => r.url().includes(pathPart)),
    locator.click(),
  ]);
  for (const p of page.context().pages()) {
    if (p !== page) await p.close().catch(() => {});
  }
  return request.url();
}

async function saveCardSettings(page, { layout, codeType, format, backText }) {
  await page.goto(`${BASE}/admin/settings?tab=cards`);
  await openSettingsSection(page, 'cards');
  const panel = page.locator('section[data-settings-panel="cards"]');
  if (layout) await panel.locator(`input[name="card_layout"][value="${layout}"]`).check();
  if (codeType) await panel.locator(`input[name="card_code_type"][value="${codeType}"]`).check();
  if (format) await panel.locator(`input[name="card_format"][value="${format}"]`).check();
  if (backText !== undefined) await panel.locator('textarea[name="card_back_text"]').fill(backText);
  await panel.locator('button[type="submit"]').click();
  await page.waitForURL(/tab=cards/);
  await expect(page.locator('section[data-settings-panel="cards"]')).toBeVisible();
}

test.describe.serial('Library cards (phase 7)', () => {
  /** @type {number[]} */
  const ids = [];
  /** @type {string[][]} */
  let savedSettings = [];

  test.beforeAll(() => {
    // HEX on both sides: mysql -B would escape newlines, tabs and backslashes
    // in the values (card_back_text has newlines) and the restore would store
    // the escaped text.
    const raw = dbQuery("SELECT HEX(setting_key), IF(setting_value IS NULL, 'NULL', HEX(setting_value)) FROM system_settings WHERE category = 'cards'");
    savedSettings = raw === '' ? [] : raw.split('\n').map((line) => line.split('\t'));
    dbQuery("DELETE FROM system_settings WHERE category = 'cards'");
    for (const r of READERS) {
      dbQuery(`INSERT INTO utenti (nome, cognome, email, password, codice_tessera, data_scadenza_tessera, stato, tipo_utente, email_verificata)
               VALUES (${sqlStr(r.nome)}, ${sqlStr(r.cognome)}, ${sqlStr(`${r.code.toLowerCase()}@example.invalid`)}, 'x', ${sqlStr(r.code)}, '2027-12-31', 'attivo', 'standard', 1)`);
      ids.push(Number(dbQuery(`SELECT id FROM utenti WHERE codice_tessera = ${sqlStr(r.code)}`)));
    }
  });

  test.afterAll(() => {
    if (ids.length) dbQuery(`DELETE FROM utenti WHERE id IN (${ids.join(',')})`);
    dbQuery("DELETE FROM system_settings WHERE category = 'cards'");
    for (const [k, v] of savedSettings) {
      if (!/^[0-9A-F]*$/.test(k) || (v !== 'NULL' && !/^[0-9A-F]*$/.test(v))) continue;
      dbQuery(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('cards', CONVERT(UNHEX('${k}') USING utf8mb4), ${v === 'NULL' ? 'NULL' : `CONVERT(UNHEX('${v}') USING utf8mb4)`})`);
    }
  });

  test('zero-config: the reader page prints a CR80 Code128 card carrying the code, name and expiry', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/users/details/${ids[0]}`);
    const link = page.locator('#print-card');
    await expect(link).toBeVisible();
    const opened = await openedPdfUrl(page, link, '/card-pdf');
    expect(opened).toContain(`/admin/users/${ids[0]}/card-pdf`);

    const pdf = await inspectPdf(page, `/admin/users/${ids[0]}/card-pdf`);
    expect(pdf.pages).toBe(1); // no back text configured → no back page
    expect(Math.abs(pdf.width - 85.6 * MM)).toBeLessThan(2);
    expect(Math.abs(pdf.height - 54 * MM)).toBeLessThan(2);
    expect(pdf.text).toContain(READERS[0].code);
    expect(pdf.text).toContain(`Quirino ${READERS[0].cognome}`);
    expect(pdf.text).toContain('31/12/2027');
    expect(pdf.text).toContain('QX'); // classic layout: initials in the empty photo box
    expect(pdf.text).not.toContain(READERS[1].code);
  });

  test('the settings tab stores layout, code, format and back text; the back text becomes a back page', async ({ page }) => {
    await login(page);
    // Non-default code type and format, so a save that ignored them would show.
    await saveCardSettings(page, { layout: 'classic', codeType: 'qr', format: 'sheet', backText: BACK_TEXT });
    expect(dbQuery("SELECT setting_value FROM system_settings WHERE category='cards' AND setting_key='back_text'")).toBe(BACK_TEXT);
    expect(dbQuery("SELECT setting_value FROM system_settings WHERE category='cards' AND setting_key='layout'")).toBe('classic');
    expect(dbQuery("SELECT setting_value FROM system_settings WHERE category='cards' AND setting_key='code_type'")).toBe('qr');
    expect(dbQuery("SELECT setting_value FROM system_settings WHERE category='cards' AND setting_key='format'")).toBe('sheet');
    // The saved values are shown again when the tab is reopened.
    await expect(page.locator('section[data-settings-panel="cards"] textarea[name="card_back_text"]')).toHaveValue(BACK_TEXT);

    // No ?format: the stored default (sheet) applies.
    const pdf = await inspectPdf(page, `/admin/users/${ids[0]}/card-pdf`);
    expect(Math.abs(pdf.width - 595.28)).toBeLessThan(2);
    expect(pdf.pages).toBe(2);
    expect(pdf.pageTexts[0]).toContain(READERS[0].code);
    expect(pdf.pageTexts[1]).toContain(`Regolamento ${RUN}`);
    expect(pdf.pageTexts[1]).not.toContain(READERS[0].code);
  });

  test('the A4 sheet from the reader page is an A4 page with the card and its back', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/users/details/${ids[1]}`);
    const sheetLink = page.locator('#patron-card-section a[href*="card-pdf?format=sheet"]');
    await expect(sheetLink).toBeVisible();
    const href = await sheetLink.getAttribute('href');
    const pdf = await inspectPdf(page, String(href));
    expect(Math.abs(pdf.width - 595.28)).toBeLessThan(2);
    expect(Math.abs(pdf.height - 841.89)).toBeLessThan(2);
    expect(pdf.pages).toBe(2); // front sheet + mirrored back sheet
    expect(pdf.pageTexts[0]).toContain(READERS[1].code);
    expect(pdf.pageTexts[1]).toContain(`Regolamento ${RUN}`);
    // The back sheet mirrors the columns: the lone card sits in the left
    // column of the front and its back in the right column, so a long-edge
    // duplex print puts the back behind the front.
    const half = pdf.width / 2;
    const frontCode = pdf.pageWords[0].find((w) => w.text.includes(READERS[1].code));
    const backWord = pdf.pageWords[1].find((w) => w.text === 'Regolamento');
    expect(frontCode, 'card code on the front sheet').toBeTruthy();
    expect(backWord, 'back text on the back sheet').toBeTruthy();
    expect(/** @type {{xMin: number}} */ (frontCode).xMin).toBeLessThan(half);
    expect(/** @type {{xMin: number}} */ (backWord).xMin).toBeGreaterThan(half);
  });

  test('each layout: minimal (no photo box), portrait (vertical card), QR code still prints the code', async ({ page }) => {
    await login(page);
    await saveCardSettings(page, { layout: 'minimal', codeType: 'code128', format: 'single', backText: '' });
    let pdf = await inspectPdf(page, `/admin/users/${ids[0]}/card-pdf`);
    expect(pdf.pages).toBe(1);
    expect(pdf.width).toBeGreaterThan(pdf.height);
    expect(pdf.text).toContain(READERS[0].code);
    expect(pdf.text).not.toContain('QX');

    await saveCardSettings(page, { layout: 'portrait', codeType: 'qr' });
    pdf = await inspectPdf(page, `/admin/users/${ids[0]}/card-pdf`);
    expect(Math.abs(pdf.width - 54 * MM)).toBeLessThan(2);
    expect(Math.abs(pdf.height - 85.6 * MM)).toBeLessThan(2);
    expect(pdf.text).toContain(READERS[0].code);
    expect(pdf.text).toContain('QX');

    await saveCardSettings(page, { layout: 'classic', codeType: 'qr' });
    pdf = await inspectPdf(page, `/admin/users/${ids[0]}/card-pdf`);
    expect(pdf.width).toBeGreaterThan(pdf.height);
    expect(pdf.text).toContain(READERS[0].code);
  });

  test('bulk print from the users list selection: one A4 sheet with every selected card', async ({ page }) => {
    await login(page);
    await saveCardSettings(page, { layout: 'classic', codeType: 'code128', format: 'single', backText: '' });
    await page.goto(`${BASE}/admin/users`);
    await page.locator('#search_text').fill(RUN);
    for (const id of ids) {
      await expect(page.locator(`#utenti-table .row-select[data-id="${id}"]`)).toBeVisible();
    }
    await expect(page.locator('#bulk-actions-bar')).toBeHidden();
    for (const id of ids) {
      await page.locator(`#utenti-table .row-select[data-id="${id}"]`).check();
    }
    await expect(page.locator('#bulk-actions-bar')).toBeVisible();
    await expect(page.locator('#selected-count')).toHaveText('3');

    const url = await openedPdfUrl(page, page.locator('#bulk-cards-sheet'), '/admin/users/cards-pdf');
    expect(url).toContain('/admin/users/cards-pdf');
    expect(url).toContain('format=sheet');
    const pdf = await inspectPdf(page, url);
    expect(pdf.pages).toBe(1);
    expect(Math.abs(pdf.width - 595.28)).toBeLessThan(2);
    for (const r of READERS) {
      expect(pdf.text).toContain(r.code);
    }

    // Single cards for the same selection: one CR80 page per reader.
    const url2 = await openedPdfUrl(page, page.locator('#bulk-cards-single'), '/admin/users/cards-pdf');
    expect(url2).toContain('format=single');
    const singles = await inspectPdf(page, url2);
    expect(singles.pages).toBe(3);
    expect(Math.abs(singles.width - 85.6 * MM)).toBeLessThan(2);

    await page.locator('#deselect-all').click();
    await expect(page.locator('#bulk-actions-bar')).toBeHidden();
  });

  test('cards are staff-only: an anonymous request gets no PDF', async ({ browser }) => {
    const context = await browser.newContext();
    const resp = await context.request.get(`${BASE}/admin/users/${ids[0]}/card-pdf`, { maxRedirects: 0 });
    expect(resp.status()).not.toBe(200);
    expect(resp.headers()['content-type'] || '').not.toContain('application/pdf');
    const bulk = await context.request.get(`${BASE}/admin/users/cards-pdf?ids=${ids.join(',')}`, { maxRedirects: 0 });
    expect(bulk.status()).not.toBe(200);
    await context.close();
  });
});
