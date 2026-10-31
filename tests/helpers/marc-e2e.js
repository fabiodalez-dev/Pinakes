// Shared helpers for the MARC bulk import / export / copy cataloguing specs.
const path = require('path');
const fs = require('fs');
const os = require('os');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ROOT = path.join(__dirname, '..', '..');

function dbQuery(sql) {
  const args = ['-u', process.env.E2E_DB_USER || '', process.env.E2E_DB_NAME || '', '-N', '-B', '-e', sql];
  if (process.env.E2E_DB_HOST) {
    args.splice(2, 0, '-h', process.env.E2E_DB_HOST);
    if (process.env.E2E_DB_PORT) args.splice(4, 0, '-P', process.env.E2E_DB_PORT);
  } else if (process.env.E2E_DB_SOCKET) {
    args.splice(2, 0, '-S', process.env.E2E_DB_SOCKET);
  }
  return execFileSync('mysql', args, {
    encoding: 'utf-8',
    timeout: 20000,
    env: { ...process.env, MYSQL_PWD: process.env.E2E_DB_PASS || '' },
  }).trim();
}

const sqlEscape = (s) => String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const email = page.locator('input[name="email"]');
  if (await email.isVisible({ timeout: 3000 }).catch(() => false)) {
    await email.fill(process.env.E2E_ADMIN_EMAIL);
    await page.fill('input[name="password"]', process.env.E2E_ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/.*(?:dashboard|admin).*/, { timeout: 15000 });
  }
}

/** A MARC file of the E2E series (see tests/helpers/marc-fixtures.php). */
function marcFile(run, count, kind, first = 1) {
  const ext = kind === 'xml' ? 'xml' : 'mrc';
  const file = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'pinakes-marc-')), `books-${run}-${kind}-${first}.${ext}`);
  execFileSync('php', [path.join(ROOT, 'tests', 'helpers', 'marc-fixtures.php'), 'e2e', file, run, String(count), kind, String(first)]);
  return file;
}

/** Remove every book, author, publisher and series a run created. */
function cleanupRun(run) {
  const like = `'%${sqlEscape(run)}%'`;
  const w = `l.titolo LIKE ${like}`;
  dbQuery(
    `DELETE lais FROM libri_autori_import_sources lais JOIN libri l ON l.id=lais.libro_id WHERE ${w};`
    + `DELETE la FROM libri_autori la JOIN libri l ON l.id=la.libro_id WHERE ${w};`
    + `DELETE le FROM libri_editori le JOIN libri l ON l.id=le.libro_id WHERE ${w};`
    + `DELETE lc FROM libri_collane lc JOIN libri l ON l.id=lc.libro_id WHERE ${w};`
    + `DELETE c FROM copie c JOIN libri l ON l.id=c.libro_id WHERE ${w};`
    + `DELETE FROM libri WHERE titolo LIKE ${like};`
    + `DELETE FROM autori WHERE nome LIKE ${like};`
    + `DELETE FROM editori WHERE nome LIKE ${like};`
    + `DELETE FROM collane WHERE nome LIKE ${like};`
    + `DELETE FROM import_logs WHERE file_name LIKE ${like};`
    + `DELETE FROM fornitori WHERE nome LIKE ${like};`
  );
}

/** Upload a file on the MARC import page and wait for the preview. */
async function uploadForPreview(page, file, { duplicates = 'skip', covers = false, flavour = 'auto', supplier = 0 } = {}) {
  await page.goto(`${BASE}/admin/books/import/marc`);
  await page.setInputFiles('#marc_file', file);
  await page.selectOption('#marc_flavour', flavour);
  await page.check(`#duplicates_${duplicates}`);
  if (covers) await page.check('#download_covers'); else await page.uncheck('#download_covers');
  if (supplier) await page.selectOption('#fornitore_id', String(supplier));
  const uploaded = page.waitForResponse((r) => r.url().includes('/admin/books/import/marc/upload'));
  await page.click('#previewBtn');
  const response = await uploaded;
  return response.json();
}

/** Confirm the previewed import and wait for the last chunk. */
async function confirmImport(page) {
  let last = null;
  const onResponse = async (r) => {
    if (r.url().includes('/admin/books/import/marc/chunk')) {
      try { const j = await r.json(); if (j.complete) last = j; } catch { /* asserted below */ }
    }
  };
  page.on('response', onResponse);
  await page.click('#confirmImportBtn');
  await page.locator('#marc-result').waitFor({ state: 'visible', timeout: 120000 });
  page.off('response', onResponse);
  return last;
}

module.exports = { BASE, ROOT, dbQuery, sqlEscape, loginAsAdmin, marcFile, cleanupRun, uploadForPreview, confirmImport };
