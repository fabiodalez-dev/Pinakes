// @ts-check
// Issue #163 — author photo (upload OR external URL) + source/website links.
// Exercises the behaviours hardened after the CodeRabbit review of PR #170:
//   - uploaded image is validated SERVER-SIDE (real bytes, not the client MIME)
//     and re-encoded, then stored under /uploads/autori/;
//   - replacing a local photo drops the OLD file only AFTER the DB write (deferred
//     cleanup), never leaving the row pointing at a deleted file;
//   - a non-image masquerading as .png is REJECTED (photo unchanged, no orphan);
//   - an external https URL is stored verbatim (preview must not url()-wrap it);
//   - the "remove" action clears the photo and deletes the local file;
//   - posting an update for a non-existent author returns 404 (no masked redirect).
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
test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME, 'creds not configured');

// 1x1 PNGs (distinct bytes so we can tell A from B on disk).
const PNG_RED  = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
const PNG_BLUE = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
const REPO_ROOT = path.resolve(__dirname, '..');
const UPLOAD_DIR = path.join(REPO_ROOT, 'public', 'uploads', 'autori');
const tmpdir = fs.mkdtempSync(path.join(os.tmpdir(), 'pk163-'));
const photoA = path.join(tmpdir, 'a.png'); const photoB = path.join(tmpdir, 'b.png');
const fakePng = path.join(tmpdir, 'fake.png');
fs.writeFileSync(photoA, Buffer.from(PNG_RED, 'base64'));
fs.writeFileSync(photoB, Buffer.from(PNG_BLUE, 'base64'));
fs.writeFileSync(fakePng, 'this is definitely not an image, just text bytes');
test.afterAll(() => { try { fs.rmSync(tmpdir, { recursive: true, force: true }); } catch {} });

function dbQuery(sql){
  const args=[]; if(DB_HOST){args.push('-h',DB_HOST); if(DB_PORT)args.push('-P',DB_PORT);} else if(DB_SOCKET){args.push('-S',DB_SOCKET);}
  args.push('-u',DB_USER,DB_NAME,'-N','-B','-e',sql);
  const cnf=path.join(os.tmpdir(),`pk-163-${process.pid}.cnf`); fs.writeFileSync(cnf,`[client]\npassword="${DB_PASS}"\n`,{mode:0o600});
  try{ return execFileSync('mysql',[`--defaults-extra-file=${cnf}`,...args],{encoding:'utf-8',timeout:10000}).trim(); } finally { try{fs.unlinkSync(cnf);}catch{} }
}
const fotoOf = (id) => dbQuery(`SELECT IFNULL(foto,'') FROM autori WHERE id=${id}`);
const diskPathOf = (foto) => path.join(REPO_ROOT, 'public', foto.replace(/^\//, ''));

test.describe('#163 — author photo lifecycle (CodeRabbit-hardened)', () => {
  /** @type {import('@playwright/test').Page} */
  let page; let authorId = 0;

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL((u) => !u.toString().includes('/accedi'), { timeout: 15000 });
    const nome = `CR Test Author ${Date.now()}`;
    dbQuery(`INSERT INTO autori (nome, created_at, updated_at) VALUES ('${nome.replace(/'/g, "''")}', NOW(), NOW())`);
    authorId = parseInt(dbQuery(`SELECT id FROM autori WHERE nome='${nome.replace(/'/g, "''")}' ORDER BY id DESC LIMIT 1`), 10);
    expect(authorId).toBeGreaterThan(0);
  });

  test.afterAll(async () => {
    try {
      const foto = fotoOf(authorId);
      if (foto.startsWith('/uploads/autori/') && fs.existsSync(diskPathOf(foto))) fs.unlinkSync(diskPathOf(foto));
    } catch {}
    try { dbQuery(`DELETE FROM autori WHERE id=${authorId}`); } catch {}
    await page?.close();
  });

  async function openEdit() {
    await page.goto(`${BASE}/admin/authors/edit/${authorId}`);
    await page.waitForSelector('#edit-author-form', { timeout: 10000 });
  }
  async function saveEdit() {
    await page.locator('#edit-author-form button[type="submit"]').click();
    const confirm = page.locator('.swal2-confirm');
    if (await confirm.isVisible({ timeout: 6000 }).catch(() => false)) await confirm.click().catch(() => {});
    await page.waitForURL(/\/admin\/authors(\?|$|\/)/, { timeout: 15000 }).catch(() => {});
    await page.waitForLoadState('networkidle').catch(() => {});
  }

  test('1. uploaded image is validated, re-encoded and stored under /uploads/autori', async () => {
    await openEdit();
    await page.setInputFiles('#author-fallback-file-input', photoA);
    await saveEdit();
    const foto = fotoOf(authorId);
    expect(foto).toMatch(/^\/uploads\/autori\/autore_[0-9a-f]+\.png$/);
    expect(fs.existsSync(diskPathOf(foto))).toBe(true);
    // re-encoded PNG is a valid image
    const info = execFileSync('file', ['-b', diskPathOf(foto)], { encoding: 'utf-8' });
    expect(info.toLowerCase()).toContain('png image');
  });

  test('2. replacing the photo drops the OLD file only after a successful save', async () => {
    const oldFoto = fotoOf(authorId);
    const oldDisk = diskPathOf(oldFoto);
    expect(fs.existsSync(oldDisk)).toBe(true);
    await openEdit();
    await page.setInputFiles('#author-fallback-file-input', photoB);
    await saveEdit();
    const newFoto = fotoOf(authorId);
    expect(newFoto).not.toBe(oldFoto);
    expect(fs.existsSync(diskPathOf(newFoto))).toBe(true); // new present
    expect(fs.existsSync(oldDisk)).toBe(false);            // old cleaned up (deferred)
  });

  test('3. a non-image masquerading as .png is rejected (photo unchanged, no orphan)', async () => {
    const before = fotoOf(authorId);
    const beforeCount = fs.existsSync(UPLOAD_DIR) ? fs.readdirSync(UPLOAD_DIR).length : 0;
    await openEdit();
    await page.setInputFiles('#author-fallback-file-input', fakePng);
    await saveEdit();
    const after = fotoOf(authorId);
    expect(after).toBe(before); // unchanged — server-side validation refused it
    const afterCount = fs.existsSync(UPLOAD_DIR) ? fs.readdirSync(UPLOAD_DIR).length : 0;
    expect(afterCount).toBe(beforeCount); // no orphan written
  });

  test('4. external https URL is stored verbatim and the local file is dropped', async () => {
    const localBefore = fotoOf(authorId);
    const localDisk = diskPathOf(localBefore);
    const ext = 'https://upload.wikimedia.org/wikipedia/commons/a/a0/example.jpg';
    await openEdit();
    await page.fill('#foto_url', ext);
    await saveEdit();
    expect(fotoOf(authorId)).toBe(ext);
    if (localBefore.startsWith('/uploads/autori/')) {
      expect(fs.existsSync(localDisk)).toBe(false); // switching to URL removed the local file
    }
    // preview must use the URL directly, not url()-wrapped
    await openEdit();
    const src = await page.locator('#author-photo-current img').getAttribute('src');
    expect(src).toBe(ext);
  });

  test('5. "remove photo" clears the stored value', async () => {
    await openEdit();
    // re-upload a local file first so there is something to remove
    await page.setInputFiles('#author-fallback-file-input', photoA);
    await saveEdit();
    const local = fotoOf(authorId);
    expect(local).toMatch(/^\/uploads\/autori\//);
    await openEdit();
    await page.check('input[name="rimuovi_foto"]');
    await saveEdit();
    expect(fotoOf(authorId)).toBe('');
    expect(fs.existsSync(diskPathOf(local))).toBe(false);
  });

  test('6. updating a non-existent author returns 404 (no masked redirect)', async () => {
    // Fetch the CSRF token from the author created in beforeAll (not a hardcoded
    // id=1, which may not exist on a clean DB and would fail CSRF retrieval).
    const token = await page.evaluate(async (id) => {
      const r = await fetch('/admin/authors/edit/' + id, { credentials: 'same-origin' });
      const html = await r.text();
      const m = html.match(/name="csrf_token"\s+value="([^"]+)"/);
      return m ? m[1] : '';
    }, authorId);
    const status = await page.evaluate(async (csrf) => {
      const body = new URLSearchParams({ csrf_token: csrf, nome: 'X' });
      const r = await fetch('/admin/authors/update/99999999', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(), redirect: 'manual',
      });
      return r.status;
    }, token);
    expect(status).toBe(404);
  });
});
