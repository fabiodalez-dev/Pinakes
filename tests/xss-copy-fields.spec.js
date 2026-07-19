// @ts-check
//
// End-to-end proof that staff-writable per-copy fields (numero_inventario, note)
// cannot execute script on the admin book-detail page. These fields (copy
// tracking #238) were emitted into inline onclick handlers with only
// htmlspecialchars(ENT_QUOTES) — the wrong encoding for a JS string inside an
// HTML attribute — and numero_inventario was also dropped raw into a SweetAlert
// `html:` template. A copy code like `');window.x=1//<img src=x onerror=…>` then
// ran in another admin's browser on click of the copy edit/delete button.
//
// The fix json_encode()s the values for the onclick handlers and escapeHtml()s
// the SweetAlert body. This spec seeds a copy carrying a live payload straight
// into the DB, opens the REAL book-detail page, clicks the buttons, and asserts
// nothing executes — while the pure-PHP encoding is covered by
// tests/xss-copy-fields-encoding.unit.php.
//
// Run: /tmp/run-e2e.sh tests/xss-copy-fields.spec.js \
//        --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME, 'Missing E2E env (admin/DB)');

function mysqlArgs(sql) {
  const args = [];
  if (DB_HOST) { args.push('-h', DB_HOST); if (DB_PORT) args.push('-P', DB_PORT); }
  else if (DB_SOCKET) { args.push('-S', DB_SOCKET); }
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return args;
}
function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql), {
    encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}
const sqlStr = (s) => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const emailField = page.locator('input[name="email"]');
  if (await emailField.isVisible({ timeout: 3000 }).catch(() => false)) {
    await emailField.fill(ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/.*(?:dashboard|admin).*/);
  }
}

test.describe('Per-copy field XSS hardening', () => {
  const uniq = Date.now();
  // numero_inventario feeds BOTH the confirmDeleteCopy onclick AND the SweetAlert
  // html; note feeds the openEditCopyModal onclick. Each payload flips a distinct
  // window flag iff it executes.
  const invPayload = `');window.__xssInvJs=true;//<img src=x onerror="window.__xssInvHtml=true">_${uniq}`;
  const notePayload = `');window.__xssNoteJs=true;//_${uniq}`;
  let bookId = 0;
  let copyId = 0;

  test.beforeAll(() => {
    bookId = parseInt(dbQuery('SELECT id FROM libri WHERE deleted_at IS NULL ORDER BY id LIMIT 1'), 10);
    if (!bookId) return;
    // 'danneggiato' → the book-detail view shows BOTH the edit and delete buttons.
    // Grab the inserted row id in the SAME connection (LAST_INSERT_ID is
    // connection-scoped) so afterAll always has an id to clean up.
    copyId = parseInt(dbQuery(
      `INSERT INTO copie (libro_id, numero_inventario, stato, note) VALUES ` +
      `(${bookId}, ${sqlStr(invPayload)}, 'danneggiato', ${sqlStr(notePayload)}); ` +
      `SELECT LAST_INSERT_ID()`
    ), 10);
  });

  test.afterAll(() => {
    if (copyId) dbQuery(`DELETE FROM copie WHERE id = ${copyId}`);
  });

  test('malicious copy code/note never execute on the book-detail page', async ({ page }) => {
    test.skip(!bookId || !copyId, 'could not seed a copy (no book in DB)');

    let dialogFired = false;
    page.on('dialog', (d) => { dialogFired = true; d.dismiss().catch(() => {}); });

    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/${bookId}`);
    await page.waitForLoadState('domcontentloaded');

    const delBtn = page.locator(`button[onclick^="confirmDeleteCopy(${copyId},"]`);
    const editBtn = page.locator(`button[onclick^="openEditCopyModal(${copyId},"]`);
    await expect(delBtn).toHaveCount(1);

    // Structural: the fix emits a json-encoded string (double-quote delimited),
    // never the old single-quoted `confirmDeleteCopy(id, '…')` breakout form.
    const delOnclick = await delBtn.getAttribute('onclick');
    expect(delOnclick).toMatch(new RegExp(`^confirmDeleteCopy\\(${copyId},\\s*"`));
    const editOnclick = await editBtn.getAttribute('onclick');
    expect(editOnclick).toMatch(new RegExp(`^openEditCopyModal\\(${copyId},`));

    // Behavioural — DELETE path: onclick must not break out, and the SweetAlert
    // body must escape the code (no injected <img> onerror).
    await delBtn.click();
    await page.locator('.swal2-popup').waitFor({ state: 'visible', timeout: 8000 });
    // The payload must appear as inert, escaped TEXT (the serialization contract:
    // present and readable, not stripped) and NOT as a live <img> element.
    await expect(page.locator('.swal2-html-container')).toContainText(invPayload);
    const liveImg = await page.evaluate(() =>
      document.querySelectorAll('.swal2-html-container img[src="x"]').length);
    expect(liveImg, 'the SweetAlert body must not contain a live injected <img>').toBe(0);
    await page.locator('.swal2-cancel').click();
    await page.locator('.swal2-popup').waitFor({ state: 'hidden', timeout: 5000 });

    // Behavioural — EDIT path: onclick must not break out, and the note must
    // round-trip verbatim into the edit textarea (not stripped by the encoding).
    await editBtn.click();
    await expect(page.locator('#edit-copy-note')).toHaveValue(notePayload);
    await page.waitForTimeout(300); // let the modal + any (non-)injected code settle

    // Nothing the payloads would have set may exist, and no dialog may have fired.
    const flags = await page.evaluate(() => ({
      invJs: /** @type {any} */ (window).__xssInvJs ?? null,
      invHtml: /** @type {any} */ (window).__xssInvHtml ?? null,
      noteJs: /** @type {any} */ (window).__xssNoteJs ?? null,
    }));
    expect(flags.invJs, 'confirmDeleteCopy onclick must not break out').toBeNull();
    expect(flags.invHtml, 'SweetAlert html must escape the copy code').toBeNull();
    expect(flags.noteJs, 'openEditCopyModal onclick must not break out').toBeNull();
    expect(dialogFired, 'no dialog may be raised by the payloads').toBe(false);
  });
});
