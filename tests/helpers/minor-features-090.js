// Shared helpers for the 0.9.0 minor-feature specs (visitor log, catalogue
// cards, suppliers, RDA overrides, statistics). DB access is only for setup
// and verification; every action under test goes through the UI.
const { execFileSync } = require('child_process');
const zlib = require('zlib');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

const configured = Boolean(ADMIN_EMAIL && ADMIN_PASS && DB_USER && DB_NAME);

function mysqlArgs(extra) {
  const args = [];
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER);
  if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
  args.push(DB_NAME, ...extra);
  return args;
}

/** Run SQL, return the tab-separated output without headers. */
function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(['-N', '-B', '-e', sql]), { encoding: 'utf-8', timeout: 15000 }).trim();
}

/** SQL string literal (single quotes doubled, backslashes escaped). */
function sqlString(value) {
  return `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
}

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  if (/\/admin\//.test(page.url()) && (await page.locator('input[name="password"]').count()) === 0) return;
  for (const slug of ['accedi', 'login', 'anmelden', 'connexion', 'log-ind']) {
    const resp = await page.goto(`${BASE}/${slug}`).catch(() => null);
    if (resp && resp.status() === 200 && (await page.locator('input[name="email"]').count()) > 0) break;
  }
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await Promise.all([
    page.waitForURL(/\/admin\//, { timeout: 45000, waitUntil: 'domcontentloaded' }),
    page.locator('button[type="submit"]').first().click(),
  ]);
}

/**
 * Submit #bookForm, confirm the SweetAlert dialog when one appears and wait
 * for the redirect away from the form (book page or list).
 */
async function submitBookForm(page) {
  await page.locator('#bookForm button[type="submit"]').click();
  const swalConfirm = page.locator('.swal2-confirm');
  if (await swalConfirm.isVisible({ timeout: 5000 }).catch(() => false)) {
    await swalConfirm.click();
  }
  await page.waitForURL(/\/admin\/books(?:\/\d+)?(?:\?.*)?$/, { timeout: 30000 });
  await page.waitForLoadState('domcontentloaded');
}

/** Insert a minimal catalogue record (setup only) and return its id. */
function seedBook(fields) {
  const cols = Object.keys(fields);
  const vals = cols.map((c) => (fields[c] === null ? 'NULL' : sqlString(fields[c])));
  dbQuery(`INSERT INTO libri (${cols.join(', ')}) VALUES (${vals.join(', ')})`);
  return parseInt(dbQuery(`SELECT MAX(id) FROM libri WHERE titolo = ${sqlString(fields.titolo)}`), 10);
}

function deleteBooks(ids) {
  const list = ids.filter((id) => id > 0);
  if (list.length === 0) return;
  const inList = list.join(',');
  try { dbQuery(`DELETE FROM libri_autori WHERE libro_id IN (${inList})`); } catch (_) { /* cleanup */ }
  try { dbQuery(`DELETE FROM copie WHERE libro_id IN (${inList})`); } catch (_) { /* cleanup */ }
  try { dbQuery(`DELETE FROM libri WHERE id IN (${inList})`); } catch (_) { /* cleanup */ }
}

/**
 * Text drawn in a TCPDF document. Content streams are zlib-compressed and
 * TCPDF writes Unicode text as UTF-16BE, so every stream is inflated and the
 * NUL high bytes of ASCII characters are dropped.
 */
function pdfText(buffer) {
  const bin = buffer.toString('latin1');
  let out = '';
  const re = /stream\r?\n/g;
  let m;
  while ((m = re.exec(bin)) !== null) {
    const start = m.index + m[0].length;
    const end = bin.indexOf('endstream', start);
    if (end < 0) break;
    const chunk = Buffer.from(bin.slice(start, end), 'latin1');
    try {
      out += zlib.inflateSync(chunk).toString('latin1');
    } catch (_) {
      // Not a deflated stream (fonts, images): ignore.
    }
    re.lastIndex = end;
  }
  return out.replace(/\x00/g, '');
}

function pdfPageCount(buffer) {
  return (buffer.toString('latin1').match(/\/Type\s*\/Page[^s]/g) || []).length;
}

module.exports = {
  BASE, ADMIN_EMAIL, ADMIN_PASS, configured,
  dbQuery, sqlString, loginAsAdmin, submitBookForm, seedBook, deleteBooks, pdfText, pdfPageCount,
};
