// @ts-check
// Issue #165 — an auto-imported cover must be replaceable in one step. Covered
// conditions on the edit form: (1) uploading a new cover REPLACES the existing
// one (no remove-first); (2) editing WITHOUT touching the cover PRESERVES it
// (the field-update reorder must not drop it); (3) the explicit "remove" action
// still clears the cover.
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

const PNG_RED  = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
const PNG_BLUE = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
const tmpdir = fs.mkdtempSync(path.join(os.tmpdir(), 'pk165-'));
const coverA = path.join(tmpdir, 'a.png'); const coverB = path.join(tmpdir, 'b.png');
fs.writeFileSync(coverA, Buffer.from(PNG_RED, 'base64'));
fs.writeFileSync(coverB, Buffer.from(PNG_BLUE, 'base64'));
test.afterAll(() => { try { fs.rmSync(tmpdir, { recursive: true, force: true }); } catch {} });

function dbQuery(sql){
  const args=[]; if(DB_HOST){args.push('-h',DB_HOST); if(DB_PORT)args.push('-P',DB_PORT);} else if(DB_SOCKET){args.push('-S',DB_SOCKET);}
  args.push('-u',DB_USER,DB_NAME,'-N','-B','-e',sql);
  const cnf=path.join(os.tmpdir(),`pk-165-${process.pid}.cnf`); fs.writeFileSync(cnf,`[client]\npassword="${DB_PASS}"\n`,{mode:0o600});
  try{ return execFileSync('mysql',[`--defaults-extra-file=${cnf}`,...args],{encoding:'utf-8',timeout:10000}).trim(); } finally { try{fs.unlinkSync(cnf);}catch{} }
}

test.describe('#165 — replace a book cover in one step', () => {
  /** @type {import('@playwright/test').Page} */
  let page;
  const createdIds = [];

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL((u) => !u.toString().includes('/accedi'), { timeout: 15000 });
  });
  test.afterAll(async () => {
    for (const id of createdIds) {
      try { dbQuery(`UPDATE libri SET deleted_at=NOW(), isbn10=NULL, isbn13=NULL, ean=NULL WHERE id=${id}`); } catch {}
    }
    await page?.close();
  });

  async function submitBook() {
    await page.locator('#bookForm button[type="submit"], button[type="submit"]').first().click();
    await Promise.race([ page.waitForSelector('.swal2-popup', { timeout: 8000 }), page.waitForURL(/\/admin\/books\/\d+/, { timeout: 8000 }) ]).catch(() => {});
    const c = page.locator('.swal2-confirm'); if (await c.isVisible({ timeout: 2000 }).catch(() => false)) await c.click().catch(() => {});
    await page.waitForLoadState('networkidle').catch(() => {});
  }
  async function createWithCover(title, coverPath) {
    await page.goto(`${BASE}/admin/books/create`);
    await page.fill('#titolo', title);
    await page.setInputFiles('#fallback-file-input', coverPath);
    await submitBook();
    const id = parseInt(dbQuery(`SELECT id FROM libri WHERE titolo='${title}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`), 10);
    expect(id).toBeGreaterThan(0);
    createdIds.push(id);
    return id;
  }
  const cover = (id) => dbQuery(`SELECT IFNULL(copertina_url,'') FROM libri WHERE id=${id}`);
  const ROOT = path.resolve(__dirname, '..');
  const coverFile = (url) => path.join(ROOT, 'public', url.replace(/^\//, '')); // nosemgrep -- url is our own /uploads/copertine path from the DB, test-only

  test('1. Uploading a new cover in edit REPLACES the existing one', async () => {
    const id = await createWithCover(`C165a_${Date.now().toString(36)}`, coverA);
    const before = cover(id); expect(before.length).toBeGreaterThan(0);
    await page.goto(`${BASE}/admin/books/edit/${id}`);
    await expect(page.locator('#titolo')).toBeVisible({ timeout: 10000 });
    await page.setInputFiles('#fallback-file-input', coverB);
    await submitBook();
    const after = cover(id);
    expect(after.length).toBeGreaterThan(0);
    expect(after).not.toBe(before);       // replaced, not reverted
  });

  test('2. Editing WITHOUT touching the cover PRESERVES it', async () => {
    const id = await createWithCover(`C165b_${Date.now().toString(36)}`, coverA);
    const before = cover(id); expect(before.length).toBeGreaterThan(0);
    await page.goto(`${BASE}/admin/books/edit/${id}`);
    await expect(page.locator('#titolo')).toBeVisible({ timeout: 10000 });
    await page.fill('#titolo', `C165b_renamed_${Date.now().toString(36)}`); // change only the title
    await submitBook();
    const after = cover(id);
    expect(after).toBe(before);           // cover unchanged (no accidental drop)
  });

  test('3. The explicit remove action clears the cover', async () => {
    const id = await createWithCover(`C165c_${Date.now().toString(36)}`, coverA);
    expect(cover(id).length).toBeGreaterThan(0);
    await page.goto(`${BASE}/admin/books/edit/${id}`);
    await expect(page.locator('#titolo')).toBeVisible({ timeout: 10000 });
    // The "Rimuovi" button (removeCoverImage) clears these hidden fields after a
    // confirm dialog (it also clears scraped_cover_url — that path is exercised
    // by test 5). Drive them directly so the test exercises the backend remove
    // path without depending on the confirm-dialog implementation.
    await page.evaluate(() => {
      document.getElementById('remove_cover').value = '1';
      document.getElementById('copertina_url').value = '';
    });
    await submitBook();
    expect(cover(id)).toBe('');           // cover removed
  });

  test('4. Remove wins over a stale scraped_cover_url (#F007 regression)', async () => {
    // Reproduces the F007 bug: after the store()/update() reorder, a "Rimuovi"
    // could be silently undone when scraped_cover_url was still populated from an
    // earlier ISBN scrape in the same session — handleCoverUrl() ran last and
    // re-added the cover. WITHOUT the backend remove_cover guard this test fails
    // (the cover comes back); WITH it the removal stays durable.
    const id = await createWithCover(`C165d_${Date.now().toString(36)}`, coverA);
    const saved = cover(id);
    expect(saved.length).toBeGreaterThan(0);
    await page.goto(`${BASE}/admin/books/edit/${id}`);
    await expect(page.locator('#titolo')).toBeVisible({ timeout: 10000 });
    // Simulate "scrape populated scraped_cover_url, then user clicks Rimuovi":
    // feed the book's own saved local cover path back as the scraped URL (local,
    // deterministic — no external fetch) and drive the remove fields.
    await page.evaluate((scrapedUrl) => {
      const sc = document.getElementById('scraped_cover_url');
      if (sc) sc.value = scrapedUrl;
      document.getElementById('remove_cover').value = '1';
      document.getElementById('copertina_url').value = '';
    }, saved);
    await submitBook();
    expect(cover(id)).toBe('');           // removal wins; cover NOT re-added
  });

  test('5. Choosing a new cover after Rimuovi keeps it (#F007 inverse order)', async () => {
    // The inverse of test 4: the user clicks "Rimuovi" and THEN picks a new cover
    // (here via the real applyAlternativeCover) in the same session. Before the
    // fix, remove_cover stayed '1', so update() nulled the cover and the guard
    // skipped re-adding it — the freshly chosen cover was silently lost. The fix
    // resets remove_cover to '0' at every cover-add site, so the last action wins.
    const id = await createWithCover(`C165e_${Date.now().toString(36)}`, coverA);
    const saved = cover(id);
    expect(saved.length).toBeGreaterThan(0);
    await page.goto(`${BASE}/admin/books/edit/${id}`);
    await expect(page.locator('#titolo')).toBeVisible({ timeout: 10000 });
    // Simulate Rimuovi, then apply a new cover via the actual JS path. Feed the
    // book's own saved cover back as an absolute same-origin URL (handleCoverUrl
    // Case 2 persists it locally — deterministic, no external fetch).
    const removeFlag = await page.evaluate((absUrl) => {
      document.getElementById('remove_cover').value = '1';
      document.getElementById('copertina_url').value = '';
      // The real cover-add path the fix patches:
      applyAlternativeCover(absUrl);
      return document.getElementById('remove_cover').value;
    }, `${BASE}${saved}`);
    expect(removeFlag).toBe('0');          // the fix cancelled the pending removal
    await submitBook();
    expect(cover(id).length).toBeGreaterThan(0);  // new cover kept, not lost
  });

  test('6. Replacing a cover deletes the old local file (no orphan, #166 review)', async () => {
    const id = await createWithCover(`C165f_${Date.now().toString(36)}`, coverA);
    const before = cover(id);
    expect(before).toMatch(/^\/uploads\/copertine\//);
    const oldFile = coverFile(before);
    expect(fs.existsSync(oldFile)).toBe(true);   // the original local cover is on disk

    await page.goto(`${BASE}/admin/books/edit/${id}`);
    await expect(page.locator('#titolo')).toBeVisible({ timeout: 10000 });
    await page.setInputFiles('#fallback-file-input', coverB);
    await submitBook();

    const after = cover(id);
    expect(after).not.toBe(before);              // DB repointed to the new file
    expect(fs.existsSync(coverFile(after))).toBe(true);  // new cover present
    expect(fs.existsSync(oldFile)).toBe(false);  // old file removed, not orphaned
  });
});
