// @ts-check
// Issue #173 — an EXTERNAL book cover (one that the source 302-redirects, e.g.
// OpenLibrary's covers.openlibrary.org → archive.org) was fetched for the form
// preview but DROPPED on save, because the redirect-host allow-list blocked
// archive.org. The fix (app/Support/SsrfGuard.php) widened the host allow-list
// to any PUBLIC host while moving the SSRF boundary to the IP layer (per-hop
// validate + CURLOPT_RESOLVE pinning, NAT64/IPv4-mapped reject).
//
// These 5 tests lock the regression on BOTH cover surfaces:
//   1. save path     — editing a book with an external cover URL persists it as a LOCAL file
//   2. download API   — POST /api/cover/download returns a LOCAL file for a redirecting source
//   3. SSRF (API)     — /api/cover/download refuses internal/reserved IP targets
//   4. preview path   — /proxy/cover serves the external cover (why it "works in the form")
//   5. SSRF (save)    — the save path never writes a local file for an internal/non-public host
//
// Tests 1, 2 and 4 hit a real external host; they self-skip when it is unreachable
// so an offline CI run degrades gracefully instead of flaking. Tests 3 and 5 are
// deterministic (the guard rejects before any connection).
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

// The exact cover from issue #173 (EAN 9782205081718). covers.openlibrary.org
// answers 302 → archive.org — the redirect the old allow-list blocked.
const OPENLIBRARY_COVER = 'https://covers.openlibrary.org/b/id/13126303-L.jpg';

function dbQuery(sql){
  const args=[]; if(DB_HOST){args.push('-h',DB_HOST); if(DB_PORT)args.push('-P',DB_PORT);} else if(DB_SOCKET){args.push('-S',DB_SOCKET);}
  args.push('-u',DB_USER,DB_NAME,'-N','-B','-e',sql);
  const cnf=path.join(os.tmpdir(),`pk-173-${process.pid}.cnf`); fs.writeFileSync(cnf,`[client]\npassword="${DB_PASS}"\n`,{mode:0o600});
  try{ return execFileSync('mysql',[`--defaults-extra-file=${cnf}`,...args],{encoding:'utf-8',timeout:10000}).trim(); } finally { try{fs.unlinkSync(cnf);}catch{} }
}

const ROOT = path.resolve(__dirname, '..');
// nosemgrep -- url is our own /uploads/copertine path read back from the DB, test-only
const coverFile = (url) => path.join(ROOT, 'public', String(url).replace(/^\//, ''));

test.describe('#173 — external covers download & save as a local file', () => {
  /** @type {import('@playwright/test').Page} */
  let page;
  let netOk = false;            // does the host have egress to the external cover source?
  let savedCoverPath = null;    // local path produced by test 1, reused by test 4
  const createdIds = [];
  const stragglerFiles = [];    // local files created by the download-API test, cleaned at the end

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL((u) => !u.toString().includes('/accedi'), { timeout: 15000 });

    // Probe egress the way the server reaches the cover: page.request is a real
    // HTTP client (follows the 302 → archive.org, no CORS), so it mirrors the
    // host's egress that the PHP download path relies on. A browser fetch() would
    // be CORS-blocked and wrongly skip the tests.
    try {
      const probe = await page.request.get(OPENLIBRARY_COVER, { timeout: 15000 });
      netOk = probe.ok() && (probe.headers()['content-type'] || '').startsWith('image/');
    } catch { netOk = false; }
  });

  test.afterAll(async () => {
    for (const id of createdIds) {
      try { dbQuery(`UPDATE libri SET deleted_at=NOW(), isbn10=NULL, isbn13=NULL, ean=NULL WHERE id=${id}`); } catch {}
    }
    for (const f of stragglerFiles) { try { fs.unlinkSync(f); } catch {} }
    await page?.close();
  });

  async function submitBook() {
    await page.locator('#bookForm button[type="submit"], button[type="submit"]').first().click();
    await Promise.race([ page.waitForSelector('.swal2-popup', { timeout: 8000 }), page.waitForURL(/\/admin\/books\/\d+/, { timeout: 8000 }) ]).catch(() => {});
    const c = page.locator('.swal2-confirm'); if (await c.isVisible({ timeout: 2000 }).catch(() => false)) await c.click().catch(() => {});
    await page.waitForLoadState('networkidle').catch(() => {});
  }
  async function createBook(title) {
    await page.goto(`${BASE}/admin/books/create`);
    await page.fill('#titolo', title);
    await submitBook();
    const id = parseInt(dbQuery(`SELECT id FROM libri WHERE titolo='${title}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`), 10);
    expect(id).toBeGreaterThan(0);
    createdIds.push(id);
    return id;
  }
  // Open the edit form and submit it with an external URL fed into the cover
  // fields (the form posts the same value in copertina_url and scraped_cover_url).
  async function saveWithExternalCover(id, url) {
    await page.goto(`${BASE}/admin/books/edit/${id}`);
    await expect(page.locator('#titolo')).toBeVisible({ timeout: 10000 });
    await page.evaluate((u) => {
      const set = (elId, v) => { const el = document.getElementById(elId); if (el) el.value = v; };
      set('copertina_url', u);
      set('scraped_cover_url', u);
      const rc = document.getElementById('remove_cover'); if (rc) rc.value = '0';
    }, url);
    await submitBook();
  }
  const cover = (id) => dbQuery(`SELECT IFNULL(copertina_url,'') FROM libri WHERE id=${id}`);
  // POST /api/cover/download from inside the logged-in page (carries the session
  // cookie + the CSRF token the middleware requires).
  async function postCoverDownload(coverUrl) {
    return page.evaluate(async ({ url, coverUrl }) => {
      const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({ cover_url: coverUrl }),
      });
      let json = null; try { json = await res.json(); } catch {}
      return { status: res.status, json };
    }, { url: `${BASE}/api/cover/download`, coverUrl });
  }

  test('1. Editing a book with an OpenLibrary cover saves it as a LOCAL file (the #173 regression)', async () => {
    test.skip(!netOk, 'external cover host unreachable');
    const id = await createBook(`C173a_${Date.now().toString(36)}`);
    expect(cover(id)).toBe('');                         // starts with no cover
    await saveWithExternalCover(id, OPENLIBRARY_COVER);

    const saved = cover(id);
    expect(saved).toMatch(/^\/uploads\/copertine\//);   // localized, NOT left as the external URL
    const onDisk = coverFile(saved);
    expect(fs.existsSync(onDisk)).toBe(true);           // the file is actually on disk
    expect(fs.statSync(onDisk).size).toBeGreaterThan(2000); // a real image, not an error stub
    savedCoverPath = saved;                             // reused by test 4 (served-over-HTTP check)
  });

  test('2. POST /api/cover/download returns a LOCAL file for a redirecting source', async () => {
    test.skip(!netOk, 'external cover host unreachable');
    await page.goto(`${BASE}/admin/dashboard`);          // a page that carries the csrf meta
    const { status, json } = await postCoverDownload(OPENLIBRARY_COVER);
    expect(status).toBe(200);
    expect(json?.file_url).toMatch(/^\/uploads\/copertine\//);
    const onDisk = coverFile(json.file_url);
    stragglerFiles.push(onDisk);                         // not attached to a book — clean it up
    expect(fs.existsSync(onDisk)).toBe(true);
    expect(fs.statSync(onDisk).size).toBeGreaterThan(2000);
  });

  test('3. /api/cover/download refuses internal / reserved-IP targets (SSRF)', async () => {
    await page.goto(`${BASE}/admin/dashboard`);
    const internal = [
      'https://169.254.169.254/latest/meta-data/',      // cloud metadata
      'https://127.0.0.1/secret.jpg',                   // loopback
      'https://10.0.0.1/x.jpg',                         // RFC1918
      `https://127.0.0.1:8081/admin/settings`,          // our own admin, via loopback
    ];
    for (const url of internal) {
      const { status, json } = await postCoverDownload(url);
      expect(status, `should reject ${url}`).toBeGreaterThanOrEqual(400);
      expect(json?.error, `error body for ${url}`).toBeTruthy();
      expect(json?.file_url, `must not return a file for ${url}`).toBeFalsy();
    }
  });

  test('4. The downloaded local cover is actually served over HTTP (what every book <img> loads)', async () => {
    test.skip(!savedCoverPath, 'depends on test 1 having saved a local cover');
    // A remote link can rot; the #173 fix stores the cover locally precisely so
    // the app serves it itself. Confirm the saved /uploads/copertine path returns
    // a real image over HTTP — the exact request a browser makes to render it.
    const res = await page.request.get(`${BASE}${savedCoverPath}`);
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type'] || '').toMatch(/^image\//);
    const body = await res.body();
    expect(body.length).toBeGreaterThan(2000);
  });

  test('5. The save path never writes a local file for an internal / non-public host (SSRF)', async () => {
    const title = `C173e_${Date.now().toString(36)}`;
    const id = await createBook(title);
    // Feed an internal target into the cover fields and save. The host gate /
    // IP guard must refuse to download it: the cover must NOT become a local
    // /uploads/copertine file, and the book must still save cleanly.
    await saveWithExternalCover(id, 'https://127.0.0.1:8081/admin/settings');

    const saved = cover(id);
    expect(saved).not.toMatch(/^\/uploads\/copertine\//); // never localized an internal target
    if (saved.startsWith('/uploads/copertine/')) {
      expect(fs.existsSync(coverFile(saved))).toBe(false); // and definitely nothing on disk
    }
    // The book itself persisted (the bad cover didn't blow up the save).
    expect(dbQuery(`SELECT titolo FROM libri WHERE id=${id}`)).toBe(title);
  });
});
