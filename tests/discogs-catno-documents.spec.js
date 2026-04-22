// @ts-check
/**
 * Discogs Cat# E2E documents (persistent).
 *
 * Seeds 20 reusable music records that mirror Discogs Cat#/barcode imports,
 * leaves them in the database, and verifies the admin API/detail surface via
 * Playwright. Run with:
 *   npx playwright test tests/discogs-catno-documents.spec.js --config=tests/playwright.config.js
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const documents = require('./seeds/discogs-catno-documents.json');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

function mysqlArgs(sql, batch = false) {
  const args = ['-u', DB_USER];
  if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push(DB_NAME);
  if (batch) args.push('-N', '-B');
  if (sql !== '') args.push('-e', sql);
  return args;
}

function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql, true), { encoding: 'utf-8', timeout: 10000 }).trim();
}

function dbExec(sql) {
  execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout: 10000 });
}

function sqlString(value) {
  if (value === null || value === undefined || value === '') return 'NULL';
  return `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
}

function tracklistHtml(doc) {
  return `<ol class="tracklist">${doc.tracks.map((track) => `<li>${track}</li>`).join('')}</ol>`;
}

function seedDocument(doc) {
  const note = `Cat#: ${doc.identifier}\nLabel: ${doc.publisher}\nE2E seed key: ${doc.key}`;
  const ean = doc.kind === 'barcode' ? doc.canonical : '';
  const updateSql = `
    UPDATE libri
       SET formato = ${sqlString(doc.format)},
           tipo_media = 'disco',
           ean = ${sqlString(ean)},
           isbn10 = NULL,
           isbn13 = NULL,
           copie_totali = 1,
           copie_disponibili = 1,
           descrizione = ${sqlString(tracklistHtml(doc))},
           note_varie = ${sqlString(note)},
           anno_pubblicazione = ${sqlString(doc.year)},
           deleted_at = NULL,
           updated_at = NOW()
     WHERE titolo = ${sqlString(doc.title)}
  `;
  dbExec(updateSql);

  let id = dbQuery(`SELECT id FROM libri WHERE titolo = ${sqlString(doc.title)} AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`);
  if (id !== '') return Number(id);

  dbExec(`
    INSERT INTO libri
      (titolo, formato, tipo_media, ean, isbn10, isbn13, copie_totali, copie_disponibili,
       descrizione, note_varie, anno_pubblicazione, created_at, updated_at)
    VALUES
      (${sqlString(doc.title)}, ${sqlString(doc.format)}, 'disco', ${sqlString(ean)}, NULL, NULL, 1, 1,
       ${sqlString(tracklistHtml(doc))}, ${sqlString(note)}, ${sqlString(doc.year)}, NOW(), NOW())
  `);
  id = dbQuery(`SELECT id FROM libri WHERE titolo = ${sqlString(doc.title)} AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`);
  return Number(id);
}

test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'Missing E2E env vars for persistent Discogs document tests'
);

test.describe.serial('Discogs Cat# persistent documents', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  /** @type {Map<string, number>} */
  const seededIds = new Map();

  test.beforeAll(async ({ browser }) => {
    expect(documents).toHaveLength(20);

    for (const doc of documents) {
      seededIds.set(doc.key, seedDocument(doc));
    }

    context = await browser.newContext();
    page = await context.newPage();
    await page.goto(`${BASE}/login`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
      page.waitForURL((url) => !/\/(login|accedi)(\?|$)/.test(url.pathname + url.search), { timeout: 15000 }),
      page.click('button[type="submit"]'),
    ]);
  });

  test.afterAll(async () => {
    await context?.close();
  });

  for (const [index, doc] of documents.entries()) {
    test(`D${String(index + 1).padStart(2, '0')}. ${doc.key} remains a reusable Discogs ${doc.kind} document`, async () => {
      const id = seededIds.get(doc.key);
      expect(id, `${doc.key} should have been seeded`).toBeTruthy();

      const row = dbQuery(`SELECT CONCAT_WS('|', tipo_media, formato, IFNULL(ean,''), note_varie)
                             FROM libri
                            WHERE id = ${id}`);
      const [tipoMedia, formato, ean, noteVarie] = row.split('|');
      expect(tipoMedia).toBe('disco');
      expect(formato).toBe(doc.format);
      expect(noteVarie).toContain(`Cat#: ${doc.identifier}`);
      if (doc.kind === 'catno') {
        expect(ean).toBe('');
      } else {
        expect(ean).toBe(doc.canonical);
      }

      await page.goto(`${BASE}/admin/libri/${id}`);
      await page.waitForLoadState('domcontentloaded');
      await expect(page.locator('body')).toContainText(doc.title);
      await expect(page.locator('body')).toContainText(doc.identifier);
      await expect(page.locator('body')).toContainText(doc.tracks[0]);
      await expect(page.locator('body')).toContainText(doc.publisher);
    });
  }
});
