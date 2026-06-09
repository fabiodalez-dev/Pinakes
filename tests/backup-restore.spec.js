// @ts-check
// Issue #162 — complete backup system: a manual backup must bundle the DB *and*
// the uploaded files, be downloadable, and be restorable (DB + files) so the
// admin can roll back. Covered: (1) full backup ZIP contains the DB dump, the
// manifest and the cover files, and never .env; (2) download streams a ZIP;
// (3) restore round-trip — delete a row + a cover file, restore, both come back,
// and a pre-restore safety backup is created; (4) restore is admin-only (a staff
// session is rejected); (5) delete removes a backup.
const { test, expect } = require('@playwright/test');
test.describe.configure({ mode: 'serial' });
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '', DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '', DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '', DB_NAME = process.env.E2E_DB_NAME || '';
// Credentials come from the E2E wrapper (/tmp/run-e2e.sh / ci-e2e.yml), which
// exports the E2E_* env this reads — that IS the project's bootstrap. The skip
// only fires when the suite is run outside that wrapper (same pattern as the
// other specs); under CI/the wrapper the env is always present, so it runs.
test.skip(!ADMIN_EMAIL || !DB_USER || !DB_NAME, 'creds not configured');

const ROOT = path.resolve(__dirname, '..');
const BACKUP_DIR = path.join(ROOT, 'storage', 'backups');
const COVERS_DIR = path.join(ROOT, 'public', 'uploads', 'copertine');

function dbQuery(sql) {
  const args = [];
  if (DB_HOST) { args.push('-h', DB_HOST); if (DB_PORT) args.push('-P', DB_PORT); } else if (DB_SOCKET) { args.push('-S', DB_SOCKET); }
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  const cnf = path.join(os.tmpdir(), `pk-162-${process.pid}.cnf`);
  fs.writeFileSync(cnf, `[client]\npassword="${DB_PASS}"\n`, { mode: 0o600 });
  try { return execFileSync('mysql', [`--defaults-extra-file=${cnf}`, ...args], { encoding: 'utf-8', timeout: 60000 }).trim(); } finally { try { fs.unlinkSync(cnf); } catch {} }
}
function zipEntries(zipPath) {
  return execFileSync('unzip', ['-Z1', zipPath], { encoding: 'utf-8', timeout: 30000 }).split('\n').filter(Boolean);
}
async function login(page, email, pass) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', pass);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL((u) => !u.toString().includes('/accedi'), { timeout: 15000 });
}
// POST inside the page realm so it reuses the page's own `csrfToken` const + cookies.
function apiPost(page, urlPath, params, isForm) {
  return page.evaluate(async ({ u, p }) => {
    const body = new URLSearchParams(Object.assign({ csrf_token: csrfToken }, p)).toString();
    const r = await fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
    let j = null; try { j = await r.json(); } catch (e) {}
    return { status: r.status, json: j };
  }, { u: BASE + urlPath, p: params });
}

test.describe('#162 — complete backup + restore', () => {
  /** @type {import('@playwright/test').Page} */
  let page;
  const TAG = 'BK162_' + Date.now().toString(36);
  const markerCover = path.join(COVERS_DIR, `${TAG}.txt`);
  const createdBackups = [];
  let staffEmail = null;

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
    await login(page, ADMIN_EMAIL, ADMIN_PASS);
    await page.goto(`${BASE}/admin/updates`);
    await page.waitForLoadState('domcontentloaded');
  });
  test.afterAll(async () => {
    try { dbQuery(`DELETE FROM libri WHERE titolo LIKE '${TAG}%'`); } catch {}
    if (staffEmail) { try { dbQuery(`DELETE FROM utenti WHERE email='${staffEmail}'`); } catch {} }
    try { if (fs.existsSync(markerCover)) fs.unlinkSync(markerCover); } catch {}
    for (const name of createdBackups) {
      try { const p = path.join(BACKUP_DIR, name); if (fs.existsSync(p)) fs.unlinkSync(p); } catch {}
    }
    await page?.close();
  });

  test('1. Full backup bundles DB + manifest + covers, never .env', async () => {
    const res = await apiPost(page, '/admin/updates/backup', { scope: 'full' });
    expect(res.status).toBe(200);
    expect(res.json?.success).toBeTruthy();
    const name = res.json.name;
    expect(name).toMatch(/^backup_.*\.zip$/);
    createdBackups.push(name);

    const zipPath = path.join(BACKUP_DIR, name); // nosemgrep -- backup name is the controlled filename from our own API, test-only
    expect(fs.existsSync(zipPath)).toBe(true);
    const entries = zipEntries(zipPath);
    expect(entries).toContain('database.sql');
    expect(entries).toContain('manifest.json');
    expect(entries.some((e) => e.startsWith('files/public/uploads/copertine/'))).toBe(true);
    expect(entries.some((e) => e === '.env' || e.endsWith('/.env'))).toBe(false);
  });

  test('2. Download serves a real ZIP (PK magic), not just bytes', async () => {
    const name = createdBackups[0];
    const r = await page.evaluate(async (u) => {
      const resp = await fetch(u);
      const buf = await resp.arrayBuffer();
      const head = Array.from(new Uint8Array(buf.slice(0, 4))).map((b) => b.toString(16).padStart(2, '0')).join('');
      return { status: resp.status, type: resp.headers.get('content-type'), len: buf.byteLength, magic: head };
    }, `${BASE}/admin/updates/backup/download?backup=${encodeURIComponent(name)}`);
    expect(r.status).toBe(200);
    expect(r.type).toContain('zip');
    expect(r.len).toBeGreaterThan(0);
    expect(r.magic).toBe('504b0304'); // local-file-header signature → an actual ZIP, not an error page
  });

  test('3. Restore brings back a deleted row AND a deleted cover file (+ safety backup)', async () => {
    // Seed a marker row + a marker cover file, then snapshot.
    dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES ('${TAG} libro', 1, 1)`);
    fs.writeFileSync(markerCover, 'MARKER-' + TAG);
    expect(Number(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo='${TAG} libro'`))).toBe(1);

    const snap = await apiPost(page, '/admin/updates/backup', { scope: 'full' });
    expect(snap.json?.success).toBeTruthy();
    const snapName = snap.json.name;
    createdBackups.push(snapName);

    const backupsBefore = fs.readdirSync(BACKUP_DIR).filter((f) => f.startsWith('backup_')).length;
    // The loan/expiry invariant triggers must survive a restore (#167 P1): a
    // dump that DROP/CREATEs tables drops their triggers, so the restore has to
    // recreate them. Prove it by dropping one and asserting it comes back.
    const trigCount = () => Number(dbQuery(`SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()`));
    const trigBefore = trigCount();
    expect(trigBefore).toBeGreaterThan(0);

    // Simulate a dirty state: drop the row, the file, and a protective trigger.
    dbQuery(`DELETE FROM libri WHERE titolo='${TAG} libro'`);
    fs.unlinkSync(markerCover);
    dbQuery(`DROP TRIGGER IF EXISTS trg_check_active_prestito_before_insert`);
    expect(Number(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo='${TAG} libro'`))).toBe(0);
    expect(fs.existsSync(markerCover)).toBe(false);
    expect(trigCount()).toBe(trigBefore - 1);

    // Restore the snapshot.
    const res = await apiPost(page, '/admin/updates/backup/restore', { backup: snapName });
    expect(res.status).toBe(200);
    expect(res.json?.success).toBeTruthy();
    expect(res.json?.safety_backup).toMatch(/^backup_.*\.zip$/);
    if (res.json?.safety_backup) createdBackups.push(res.json.safety_backup);

    // DB row back, cover file back, triggers recreated, and a pre-restore safety backup was created.
    expect(Number(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo='${TAG} libro'`))).toBe(1);
    expect(fs.existsSync(markerCover)).toBe(true);
    expect(fs.readFileSync(markerCover, 'utf-8')).toBe('MARKER-' + TAG);
    expect(trigCount()).toBe(trigBefore);
    expect(Number(dbQuery(`SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='trg_check_active_prestito_before_insert'`))).toBe(1);
    const backupsAfter = fs.readdirSync(BACKUP_DIR).filter((f) => f.startsWith('backup_')).length;
    expect(backupsAfter).toBeGreaterThan(backupsBefore);
  });

  test('4. A downloaded backup re-uploaded restores the data (upload-restore path)', async () => {
    // Proves the *downloaded* artifact is a valid, restorable backup — and
    // exercises the "Ripristina da file" upload path (restoreFromUploadedZip).
    const tag2 = TAG + 'up';
    const cover2 = path.join(COVERS_DIR, `${tag2}.txt`); // nosemgrep -- controlled test tag, not user input
    dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES ('${tag2} libro', 1, 1)`);
    fs.writeFileSync(cover2, 'UPLOAD-' + TAG);

    const snap = await apiPost(page, '/admin/updates/backup', { scope: 'full' });
    expect(snap.json?.success).toBeTruthy();
    createdBackups.push(snap.json.name);

    // Dirty state, then download the backup and re-upload it to /restore-upload —
    // the whole download→upload→restore cycle runs in the browser (cookies + CSRF).
    dbQuery(`DELETE FROM libri WHERE titolo='${tag2} libro'`);
    fs.unlinkSync(cover2);
    expect(Number(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo='${tag2} libro'`))).toBe(0);

    const res = await page.evaluate(async ({ dlUrl, upUrl }) => {
      const dl = await fetch(dlUrl);
      const blob = await dl.blob();
      const fd = new FormData();
      fd.append('csrf_token', csrfToken);
      fd.append('backup_file', new File([blob], 'restore.zip', { type: 'application/zip' }));
      const r = await fetch(upUrl, { method: 'POST', body: fd });
      let j = null; try { j = await r.json(); } catch (e) {}
      return { status: r.status, json: j, dlBytes: blob.size };
    }, {
      dlUrl: `${BASE}/admin/updates/backup/download?backup=${encodeURIComponent(snap.json.name)}`,
      upUrl: `${BASE}/admin/updates/backup/restore-upload`,
    });

    expect(res.dlBytes).toBeGreaterThan(0);
    expect(res.status).toBe(200);
    expect(res.json?.success).toBeTruthy();
    if (res.json?.safety_backup) createdBackups.push(res.json.safety_backup);

    // The row and the cover file are back — restored from the downloaded artifact.
    expect(Number(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo='${tag2} libro'`))).toBe(1);
    expect(fs.existsSync(cover2)).toBe(true);
    expect(fs.readFileSync(cover2, 'utf-8')).toBe('UPLOAD-' + TAG);

    dbQuery(`DELETE FROM libri WHERE titolo='${tag2} libro'`);
    try { if (fs.existsSync(cover2)) fs.unlinkSync(cover2); } catch {}
  });

  test('5. Restore is admin-only — a staff session is rejected (403)', async ({ browser }) => {
    // Create a staff user with a known password.
    const hash = execFileSync('php', ['-r', 'echo password_hash("Test1234!", PASSWORD_DEFAULT);'], { encoding: 'utf-8' }).trim();
    staffEmail = `${TAG.toLowerCase()}.staff@local.test`;
    dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata, data_registrazione) VALUES ('${TAG}ST', 'E2E', 'Staff', '${staffEmail}', '${hash.replace(/'/g, "''")}', 'attivo', 'staff', 1, NOW())`);

    const ctx = await browser.newContext();
    const staffPage = await ctx.newPage();
    try {
      await login(staffPage, staffEmail, 'Test1234!');
      await staffPage.goto(`${BASE}/admin/updates`);
      await staffPage.waitForLoadState('domcontentloaded');
      const res = await apiPost(staffPage, '/admin/updates/backup/restore', { backup: createdBackups[0] });
      expect(res.status).toBe(403);
      expect(res.json?.success).toBeFalsy();
    } finally {
      await ctx.close();
    }
  });

  test('6. Delete removes the backup', async () => {
    const name = createdBackups[0];
    const res = await apiPost(page, '/admin/updates/backup/delete', { backup: name });
    expect(res.json?.success).toBeTruthy();
    expect(fs.existsSync(path.join(BACKUP_DIR, name))).toBe(false);
  });
});
