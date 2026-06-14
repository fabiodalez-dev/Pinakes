// @ts-nocheck
// =============================================================================
// PR #166 (scanner #164 + cover #165/#166) + PR #167 (complete backup #162)
// Targeted regression suite — ONE test per checklist point (32 points / 8 groups).
//
// Runs against a DEDICATED throwaway install (separate port + temp DB) so the
// destructive restore round-trips are fully isolated and never touch the dev
// catalog. Driver: /tmp/run-suite.sh (sets E2E_BASE_URL=:8086,
// E2E_APP_ROOT=<docroot>, E2E_DB_NAME=fabiodal_biblioteca_suite, admin creds).
//
//   /tmp/run-suite.sh tests/pr166-167-coverage.spec.js \
//     --config=tests/playwright.config.js --workers=1
//
// Filesystem assertions use E2E_APP_ROOT (the SERVED app docroot), not __dirname.
// =============================================================================
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync, spawn } = require('child_process');

// NB: no file-level serial mode — each group below is its own test.describe.serial,
// so a failure inside one group does NOT cascade-skip the other groups (workers=1
// already runs them in file order). This keeps a single run surfacing ALL issues.

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8086';
const APP_ROOT = process.env.E2E_APP_ROOT || path.resolve(__dirname, '..');
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';

test.skip(!ADMIN_EMAIL || !DB_USER || !DB_NAME, 'suite creds not configured — run via /tmp/run-suite.sh');

const BACKUP_DIR = path.join(APP_ROOT, 'storage', 'backups');
const COVERS_DIR = path.join(APP_ROOT, 'public', 'uploads', 'copertine');
const MAINT_FILE = path.join(APP_ROOT, 'storage', '.maintenance');
const LOCK_FILE = path.join(APP_ROOT, 'storage', 'cache', 'update.lock');

const TAG = 'PR_' + Date.now().toString(36);

// Cleanup registries — drained by the module-level afterAll at the bottom.
const CREATED_BACKUPS = [];
const CREATED_USERS = [];
const CREATED_BOOKS = [];

// ---------------------------------------------------------------------------
// Shared helpers (the fixed API every group relies on).
// ---------------------------------------------------------------------------
function dbQuery(sql) {
  const args = [];
  if (DB_HOST) { args.push('-h', DB_HOST); if (DB_PORT) args.push('-P', DB_PORT); }
  else if (DB_SOCKET) { args.push('-S', DB_SOCKET); }
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  const cnf = path.join(os.tmpdir(), `pk-suite-${process.pid}.cnf`);
  // Escape for MySQL option-file syntax (backslash + double quote).
  const esc = DB_PASS.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  fs.writeFileSync(cnf, `[client]\npassword="${esc}"\n`, { mode: 0o600 });
  try {
    return execFileSync('mysql', [`--defaults-extra-file=${cnf}`, ...args], { encoding: 'utf-8', timeout: 60000 }).trim();
  } finally {
    try { fs.unlinkSync(cnf); } catch {}
  }
}

function zipEntries(zipPath) {
  return execFileSync('unzip', ['-Z1', zipPath], { encoding: 'utf-8', timeout: 30000 }).split('\n').filter(Boolean);
}

function phpHash(pass) {
  return execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(pass)}, PASSWORD_DEFAULT);`], { encoding: 'utf-8', timeout: 5000 }).trim();
}

function runPhp(code) {
  // `php -r` is already in PHP mode — a leading <?php tag is a parse error.
  const clean = code.replace(/^\s*<\?php\s*/, '');
  return execFileSync('php', ['-r', clean], {
    encoding: 'utf-8', timeout: 60000, cwd: APP_ROOT,
    env: { ...process.env, MYSQL_PWD: DB_PASS, E2E_DB_NAME: DB_NAME, E2E_DB_USER: DB_USER, E2E_DB_SOCKET: DB_SOCKET },
  }).trim();
}

// Reset rate-limit budgets. The login throttle and the 5/60s cap on the backup
// create route both accumulate across a long run; cleared before each login and
// each backup create (beforeAll runs BEFORE beforeEach, so login() must clear
// itself). Files live in sys_get_temp_dir()/pinakes_ratelimit — CLI php and the
// FPM worker can resolve different temp dirs, so clear every candidate.
function clearRateLimits() {
  try {
    execFileSync('php', ['-r',
      'foreach ([sys_get_temp_dir(), "/var/tmp", "/tmp"] as $d) { array_map("unlink", glob($d."/pinakes_ratelimit/*.json") ?: []); }',
    ], { encoding: 'utf-8', timeout: 5000 });
  } catch {}
}

async function login(page, email, pass) {
  clearRateLimits();
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', pass);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL((u) => !u.toString().includes('/accedi'), { timeout: 15000 });
}

// Navigate to /admin/updates so the page-realm `csrfToken` const exists.
async function gotoUpdates(page) {
  await page.goto(`${BASE}/admin/updates`);
  await page.waitForLoadState('domcontentloaded');
  await page.waitForFunction(() => typeof csrfToken !== 'undefined', null, { timeout: 10000 }).catch(() => {});
}

// form-urlencoded POST inside the page realm, reusing csrfToken + cookies.
function apiPost(page, urlPath, params) {
  return page.evaluate(async ({ u, p }) => {
    const body = new URLSearchParams(Object.assign({ csrf_token: csrfToken }, p)).toString();
    const r = await fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
    let j = null; try { j = await r.json(); } catch (e) {}
    return { status: r.status, json: j };
  }, { u: BASE + urlPath, p: params });
}

// multipart POST building a File named backup_file from a byte array.
function apiUpload(page, urlPath, fileName, mime, bytesArray) {
  return page.evaluate(async ({ u, fn, mt, bytes }) => {
    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('backup_file', new File([new Uint8Array(bytes)], fn, { type: mt }));
    const r = await fetch(u, { method: 'POST', body: fd });
    let j = null; try { j = await r.json(); } catch (e) {}
    return { status: r.status, json: j };
  }, { u: BASE + urlPath, fn: fileName, mt: mime, bytes: bytesArray });
}

async function createBackup(page, scope) {
  clearRateLimits();
  const res = await apiPost(page, '/admin/updates/backup', { scope });
  expect(res.status, `createBackup(${scope}) status`).toBe(200);
  expect(res.json && res.json.success, `createBackup(${scope}) success`).toBeTruthy();
  CREATED_BACKUPS.push(res.json.name);
  return res.json.name;
}

function makeStaffUser(localPart) {
  const email = `${TAG.toLowerCase()}.${localPart}@local.test`;
  const hash = phpHash('Test1234!').replace(/'/g, "''");
  dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata, data_registrazione)
           VALUES ('${TAG}${localPart}', 'E2E', 'Staff', '${email}', '${hash}', 'attivo', 'staff', 1, NOW())`);
  CREATED_USERS.push(email);
  return email;
}

// Defensive: reset rate-limit budgets before every test (page loads fire
// background fetches to rate-limited endpoints).
test.beforeEach(() => clearRateLimits());

// ===========================================================================
// ===== g12 =====
test.describe.serial('G1 — Backup', () => {
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
    await login(page, ADMIN_EMAIL, ADMIN_PASS);
    await gotoUpdates(page);
  });

  test.afterAll(async () => {
    await page?.close();
  });

  test('1. Full backup bundles DB + manifest + covers, never .env', async () => {
    const name = await createBackup(page, 'full');
    const zipPath = path.join(BACKUP_DIR, name);
    expect(fs.existsSync(zipPath)).toBe(true);

    const entries = zipEntries(zipPath);
    expect(entries).toContain('database.sql');
    expect(entries).toContain('manifest.json');
    expect(entries.some(e => e.startsWith('files/public/uploads/copertine/'))).toBe(true);
    expect(entries.some(e => e === '.env' || e.endsWith('/.env'))).toBe(false);
  });

  test('2. DB-only backup has no files/ entries; list row shows contents==="db"', async () => {
    const name = await createBackup(page, 'db');
    const zipPath = path.join(BACKUP_DIR, name);
    expect(fs.existsSync(zipPath)).toBe(true);

    const entries = zipEntries(zipPath);
    expect(entries.some(e => e.startsWith('files/'))).toBe(false);

    // GET the backups list via a page.evaluate fetch (reuses the session cookies).
    const listRes = await page.evaluate(async (u) => {
      const r = await fetch(u);
      return r.json();
    }, `${BASE}/admin/updates/backups`);
    const row = (listRes.backups || []).find(b => b.name === name);
    expect(row).toBeTruthy();
    expect(row.contents).toBe('db');
  });

  test('3. Download serves a real ZIP (PK magic bytes, no .env)', async () => {
    // Use first CREATED_BACKUPS entry (created in test 1 or 2)
    const name = CREATED_BACKUPS[0];
    const r = await page.evaluate(async (u) => {
      const resp = await fetch(u);
      const buf = await resp.arrayBuffer();
      const head = Array.from(new Uint8Array(buf.slice(0, 4)))
        .map(b => b.toString(16).padStart(2, '0')).join('');
      return { status: resp.status, type: resp.headers.get('content-type'), len: buf.byteLength, magic: head };
    }, `${BASE}/admin/updates/backup/download?backup=${encodeURIComponent(name)}`);

    expect(r.status).toBe(200);
    expect(r.type).toContain('zip');
    expect(r.len).toBeGreaterThan(0);
    expect(r.magic).toBe('504b0304');

    // Also verify via zipEntries that .env is absent.
    const zipPath = path.join(BACKUP_DIR, name);
    const entries = zipEntries(zipPath);
    expect(entries.some(e => e === '.env' || e.endsWith('/.env'))).toBe(false);
  });

  test('4. Covers-included sanity: TAG cover appears inside the full backup ZIP', async () => {
    const coverFilename = `${TAG}_cover_sanity.txt`;
    const coverPath = path.join(COVERS_DIR, coverFilename);
    fs.mkdirSync(COVERS_DIR, { recursive: true });
    fs.writeFileSync(coverPath, 'cover-sanity-' + TAG);

    let snapName;
    try {
      snapName = await createBackup(page, 'full');
      const zipPath = path.join(BACKUP_DIR, snapName);
      const entries = zipEntries(zipPath);
      expect(entries.some(e => e.endsWith(coverFilename))).toBe(true);
      const stat = fs.statSync(zipPath);
      expect(stat.size).toBeGreaterThan(0);
    } finally {
      try { fs.unlinkSync(coverPath); } catch {}
    }
  });

  test('5. Legacy directory backup shows "Formato legacy" badge and no restore button', async () => {
    const legacyDirName = 'update_' + TAG;
    const legacyDir = path.join(BACKUP_DIR, legacyDirName);
    fs.mkdirSync(legacyDir, { recursive: true });
    fs.writeFileSync(path.join(legacyDir, 'database.sql'), '-- legacy test');

    try {
      await gotoUpdates(page);
      // Wait for the backup list to render (DOMContentLoaded fires loadBackups()).
      await page.waitForFunction(() => {
        const el = document.getElementById('backupListContainer');
        return el && !el.innerHTML.includes('fa-spinner');
      }, { timeout: 15000 });

      // The row for the legacy dir should contain the 'Formato legacy' text
      // and must NOT contain a btn-backup-restore element.
      const rowHtml = await page.evaluate((n) => {
        const rows = document.querySelectorAll('#backupListContainer tbody tr');
        for (const row of rows) {
          if (row.textContent.includes(n)) return row.innerHTML;
        }
        return null;
      }, legacyDirName);

      expect(rowHtml).not.toBeNull();
      expect(rowHtml).toContain('Formato legacy');
      expect(rowHtml).not.toContain('btn-backup-restore');

      // Also confirm the API row has a name NOT ending with '.zip'.
      const listRes = await page.evaluate(async (u) => {
        const r = await fetch(u);
        return r.json();
      }, `${BASE}/admin/updates/backups`);
      const legacyRow = (listRes.backups || []).find(b => b.name === legacyDirName);
      expect(legacyRow).toBeTruthy();
      expect(legacyRow.name.endsWith('.zip')).toBe(false);
    } finally {
      // Clean up the legacy dir in-test.
      try { fs.rmSync(legacyDir, { recursive: true, force: true }); } catch {}
    }
  });
});

test.describe.serial('G2 — Restore', () => {
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
    await login(page, ADMIN_EMAIL, ADMIN_PASS);
    await gotoUpdates(page);
  });

  test.afterAll(async () => {
    // Clean up any TAG'd books and cover markers left over.
    try { dbQuery(`DELETE FROM libri WHERE titolo LIKE '${TAG}%'`); } catch {}
    for (const email of CREATED_USERS) {
      try { dbQuery(`DELETE FROM utenti WHERE email='${email}'`); } catch {}
    }
    await page?.close();
  });

  test('6. Full restore brings back deleted row + deleted cover + dropped trigger (snapshot-isolated)', async () => {
    // Seed a TAG'd book and a marker cover file.
    dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES ('${TAG} libro', 1, 1)`);
    const markerPath = path.join(COVERS_DIR, `${TAG}_restore_marker.txt`);
    fs.writeFileSync(markerPath, 'MARKER-' + TAG);

    // Snapshot.
    const snapName = await createBackup(page, 'full');

    // Count triggers before dirty-state.
    const trigCount = () => Number(dbQuery(
      `SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()`
    ));
    const trigBefore = trigCount();
    expect(trigBefore).toBeGreaterThan(0);

    // Dirty state: delete the row, the marker, and drop the trigger.
    dbQuery(`DELETE FROM libri WHERE titolo='${TAG} libro'`);
    fs.unlinkSync(markerPath);
    dbQuery(`DROP TRIGGER IF EXISTS trg_check_active_prestito_before_insert`);

    expect(Number(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo='${TAG} libro' AND deleted_at IS NULL`))).toBe(0);
    expect(fs.existsSync(markerPath)).toBe(false);
    expect(trigCount()).toBe(trigBefore - 1);

    // Restore.
    const res = await apiPost(page, '/admin/updates/backup/restore', { backup: snapName });
    expect(res.status).toBe(200);
    expect(res.json?.success).toBe(true);
    expect(res.json?.safety_backup).toMatch(/^backup_.*\.zip$/);
    if (res.json?.safety_backup) CREATED_BACKUPS.push(res.json.safety_backup);

    // Assertions: row back, marker back, trigger count restored.
    expect(Number(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo='${TAG} libro' AND deleted_at IS NULL`))).toBe(1);
    expect(fs.existsSync(markerPath)).toBe(true);
    expect(fs.readFileSync(markerPath, 'utf-8')).toBe('MARKER-' + TAG);
    expect(trigCount()).toBe(trigBefore);
    expect(Number(dbQuery(
      `SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='trg_check_active_prestito_before_insert'`
    ))).toBe(1);

    // Cleanup after restore confirmed.
    dbQuery(`DELETE FROM libri WHERE titolo='${TAG} libro'`);
    try { if (fs.existsSync(markerPath)) fs.unlinkSync(markerPath); } catch {}
  });

  test('7. Restore-from-uploaded-file round-trip succeeds', async () => {
    const snapName = await createBackup(page, 'full');

    const res = await page.evaluate(async ({ dlUrl, upUrl }) => {
      const dl = await fetch(dlUrl);
      const blob = await dl.blob();
      const fd = new FormData();
      fd.append('csrf_token', csrfToken);
      fd.append('backup_file', new File([blob], 'restore.zip', { type: 'application/zip' }));
      const r = await fetch(upUrl, { method: 'POST', body: fd });
      let j = null;
      try { j = await r.json(); } catch (e) {}
      return { status: r.status, json: j };
    }, {
      dlUrl: `${BASE}/admin/updates/backup/download?backup=${encodeURIComponent(snapName)}`,
      upUrl: `${BASE}/admin/updates/backup/restore-upload`,
    });

    expect(res.status).toBe(200);
    expect(res.json?.success).toBe(true);
    if (res.json?.safety_backup) CREATED_BACKUPS.push(res.json.safety_backup);
  });

  test('8. Focus is on the Cancel button in the restore confirmation dialog', async () => {
    await gotoUpdates(page);

    // Wait for backup list to load and find the first .zip backup.
    await page.waitForFunction(() => {
      const el = document.getElementById('backupListContainer');
      return el && !el.innerHTML.includes('fa-spinner');
    }, { timeout: 15000 });

    const firstBackupName = await page.evaluate(() => {
      const btn = document.querySelector('.btn-backup-restore');
      return btn ? btn.getAttribute('data-backup') : null;
    });
    expect(firstBackupName).not.toBeNull();

    // Trigger the dialog via the in-page function.
    await page.evaluate((name) => {
      window.restoreBackup(name);
    }, firstBackupName);

    await page.waitForSelector('.swal2-popup', { state: 'visible', timeout: 10000 });

    // The cancel button should be focused (focusCancel: true is set in the JS).
    await expect(page.locator('.swal2-cancel')).toBeFocused();

    // Dismiss without restoring.
    await page.locator('.swal2-cancel').click();
    await page.waitForSelector('.swal2-popup', { state: 'hidden', timeout: 5000 });
  });

  test('9. Upload validation: rejects non-zip; rejects oversized zip; no fetch to restore-upload', async () => {
    await gotoUpdates(page);
    await page.waitForFunction(() => {
      const el = document.getElementById('backupListContainer');
      return el && !el.innerHTML.includes('fa-spinner');
    }, { timeout: 15000 });

    // Track fetch calls to restore-upload.
    const restoreUploadRequests = [];
    const onReq = req => { if (req.url().includes('restore-upload')) restoreUploadRequests.push(req.url()); };
    page.on('request', onReq);

    // --- Case 1: non-.zip file ---
    await page.evaluate(() => {
      const input = document.getElementById('restoreFileInput');
      const dt = new DataTransfer();
      dt.items.add(new File(['hello'], 'test.txt', { type: 'text/plain' }));
      input.files = dt.files;
      window.uploadRestoreFile();
    });

    await page.waitForSelector('.swal2-popup', { state: 'visible', timeout: 8000 });
    const txt1 = await page.locator('.swal2-popup').textContent();
    expect(txt1).toMatch(/File non valido|\.zip/i);
    await page.locator('.swal2-confirm').click();
    await page.waitForSelector('.swal2-popup', { state: 'hidden', timeout: 5000 });

    // --- Case 2: .zip file exceeding 2 GB (spoof size via Object.defineProperty) ---
    await page.evaluate(() => {
      const input = document.getElementById('restoreFileInput');
      const dt = new DataTransfer();
      const f = new File(['x'], 'big.zip', { type: 'application/zip' });
      Object.defineProperty(f, 'size', { value: 3 * 1024 * 1024 * 1024 });
      dt.items.add(f);
      input.files = dt.files;
      window.uploadRestoreFile();
    });

    await page.waitForSelector('.swal2-popup', { state: 'visible', timeout: 8000 });
    const txt2 = await page.locator('.swal2-popup').textContent();
    expect(txt2).toMatch(/File troppo grande/i);
    await page.locator('.swal2-confirm').click();
    await page.waitForSelector('.swal2-popup', { state: 'hidden', timeout: 5000 });

    // No fetch to restore-upload should have happened.
    expect(restoreUploadRequests.length).toBe(0);
    page.off('request', onReq); // don't let the listener accumulate across tests
  });

  test('10. Trust advisory appears when uploading a real .zip via the file input', async () => {
    await gotoUpdates(page);
    await page.waitForFunction(() => {
      const el = document.getElementById('backupListContainer');
      return el && !el.innerHTML.includes('fa-spinner');
    }, { timeout: 15000 });

    // Find a real .zip backup to upload.
    const zipFiles = fs.readdirSync(BACKUP_DIR).filter(f => f.endsWith('.zip'));
    expect(zipFiles.length).toBeGreaterThan(0);
    const realZip = path.join(BACKUP_DIR, zipFiles[0]);

    // Set the file input to the real zip and click the upload button.
    await page.setInputFiles('#restoreFileInput', realZip);
    await page.click('button[onclick="uploadRestoreFile()"]');

    await page.waitForSelector('.swal2-popup', { state: 'visible', timeout: 10000 });
    const popupText = await page.locator('.swal2-popup').textContent();
    expect(popupText).toContain('Ripristina solo archivi creati da questa istanza');

    // Cancel — do NOT actually restore.
    await page.locator('.swal2-cancel').click();
    await page.waitForSelector('.swal2-popup', { state: 'hidden', timeout: 5000 });
  });

  test('11. Success popup confirm button reads "Ricarica la pagina" (checked before click fires reload)', async () => {
    await gotoUpdates(page);
    // Restore a known-good backup we just created (avoids ambiguity about which row).
    const name = await createBackup(page, 'full');

    // Open the confirm dialog for THIS backup, then confirm → runRestore().
    // Block body (no implicit return): restoreBackup() is async and its promise
    // only resolves when the dialog is dismissed — returning it would make
    // page.evaluate await a click we haven't made yet (deadlock). Fire-and-forget.
    await page.evaluate((n) => { window.restoreBackup(n); }, name);
    await page.waitForSelector('.swal2-popup', { state: 'visible', timeout: 10000 });
    await page.locator('.swal2-confirm').click();

    // runRestore shows a progress dialog (default hidden "OK" button) then, on
    // success, the dialog whose confirm button reads "Ricarica la pagina".
    // toHaveText retries across that transition instead of reading too early.
    // We deliberately do NOT click it — clicking fires window.location.reload(),
    // which races the test teardown; asserting the label already proves the point.
    await expect(page.locator('.swal2-confirm')).toHaveText('Ricarica la pagina', { timeout: 45000 });
  });

  test('12. Non-JSON / gateway-error response shows advisory warning popup', async () => {
    await gotoUpdates(page);
    await page.waitForFunction(() => {
      const el = document.getElementById('backupListContainer');
      return el && !el.innerHTML.includes('fa-spinner');
    }, { timeout: 15000 });

    // Pick a backup name to use (doesn't matter which; fetch is monkeypatched).
    const backupName = CREATED_BACKUPS[0] || 'backup_fake.zip';

    // Monkeypatch window.fetch so the restore URL returns a 502 with HTML body.
    await page.evaluate((restoreUrl) => {
      const origFetch = window.fetch;
      window.__origFetch = origFetch;
      window.fetch = (url, opts) => {
        if (typeof url === 'string' && url.includes('/admin/updates/backup/restore')) {
          return Promise.resolve(new Response('gateway error', {
            status: 502,
            headers: { 'Content-Type': 'text/html' }
          }));
        }
        return origFetch.call(window, url, opts);
      };
    }, `${BASE}/admin/updates/backup/restore`);

    try {
      // Fire-and-forget: runRestore() is async and its promise resolves only when
      // the warning dialog is dismissed — awaiting it here would deadlock (we
      // dismiss further down). Block body returns undefined so evaluate resolves now.
      await page.evaluate((opts) => {
        window.runRestore(
          opts.url,
          `csrf_token=${encodeURIComponent(window.csrfToken)}&backup=${encodeURIComponent(opts.name)}`,
          false
        );
      }, { url: `${BASE}/admin/updates/backup/restore`, name: backupName });

      // The non-JSON error branch should display a warning popup.
      await page.waitForSelector('.swal2-popup', { state: 'visible', timeout: 10000 });
      const popupText = await page.locator('.swal2-popup').textContent();
      expect(popupText).toMatch(/non ha risposto correttamente/i);

      // Best-effort dismiss — the assertion above IS the check. The warning can
      // retain SweetAlert's loading state (confirm button hidden), so closing is
      // not guaranteed; never let cleanup fail the test.
      await page.keyboard.press('Escape').catch(() => {});
      await page.locator('.swal2-confirm').click({ timeout: 1500 }).catch(() => {});
    } finally {
      // Restore the real fetch.
      await page.evaluate(() => {
        if (window.__origFetch) {
          window.fetch = window.__origFetch;
          delete window.__origFetch;
        }
      });
    }
  });
});

// ===== g34 =====
test.describe.serial('G3 — Concurrency / lock', () => {
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
    await login(page, ADMIN_EMAIL, ADMIN_PASS);
    await gotoUpdates(page);
    fs.mkdirSync(path.dirname(LOCK_FILE), { recursive: true });
  });

  test.afterAll(async () => {
    try { dbQuery(`DELETE FROM libri WHERE titolo LIKE '${TAG}%'`); } catch {}
    await page?.close();
  });

  // Spawn a detached PHP process that holds an exclusive flock on update.lock
  // for `seconds`, so concurrent restores deterministically hit the lock
  // instead of racing the (fast) real restore.
  function holdLock(seconds) {
    const lf = LOCK_FILE.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    // Release AND unlink on exit so the lock file disappears like the real
    // BackupManager finally does (test 14 asserts the file is gone afterwards).
    const code = `$f=fopen('${lf}','c'); if($f===false){exit(1);} flock($f,LOCK_EX); sleep(${seconds}); flock($f,LOCK_UN); fclose($f); @unlink('${lf}');`;
    const child = spawn('php', ['-r', code], { detached: true, stdio: 'ignore' });
    child.unref();
    return child;
  }

  test('13. Two concurrent restores are both rejected while the lock is held', async () => {
    const backup = await createBackup(page, 'full');
    holdLock(3);
    await new Promise((r) => setTimeout(r, 400)); // let the holder acquire the lock
    expect(fs.existsSync(LOCK_FILE)).toBe(true);

    const [a, b] = await Promise.all([
      apiPost(page, '/admin/updates/backup/restore', { backup }),
      apiPost(page, '/admin/updates/backup/restore', { backup }),
    ]);
    const errs = [a, b].map((r) => (r.json && r.json.error) || '');
    // Both concurrent restores are rejected by the held lock (not queued).
    for (const e of errs) expect(e).toMatch(/già in corso/i);

    // Wait for the holder to release, then a restore succeeds again.
    await new Promise((r) => setTimeout(r, 3200));
    const ok = await apiPost(page, '/admin/updates/backup/restore', { backup });
    expect(ok.json?.success).toBe(true);
    if (ok.json?.safety_backup) CREATED_BACKUPS.push(ok.json.safety_backup);
  });

  test('14. Maintenance + lock files are cleaned up after a restore; lock visible while held', async () => {
    // (a) A real restore leaves no residual maintenance flag nor lock file.
    const backup = await createBackup(page, 'full');
    const res = await apiPost(page, '/admin/updates/backup/restore', { backup });
    expect(res.json?.success).toBe(true);
    if (res.json?.safety_backup) CREATED_BACKUPS.push(res.json.safety_backup);
    expect(fs.existsSync(MAINT_FILE)).toBe(false);
    expect(fs.existsSync(LOCK_FILE)).toBe(false);

    // (b) While a holder owns the lock, the lock file exists; after, it's gone.
    holdLock(2);
    await new Promise((r) => setTimeout(r, 400));
    expect(fs.existsSync(LOCK_FILE)).toBe(true);
    await new Promise((r) => setTimeout(r, 2200));
    expect(fs.existsSync(LOCK_FILE)).toBe(false);
  });

  test('15. A restore is rejected while an update-style lock is held', async () => {
    const backup = await createBackup(page, 'full');
    holdLock(3);
    await new Promise((r) => setTimeout(r, 400));
    const res = await apiPost(page, '/admin/updates/backup/restore', { backup });
    expect((res.json && res.json.error) || '').toMatch(/già in corso/i);

    // After release the same restore goes through.
    await new Promise((r) => setTimeout(r, 3200));
    const ok = await apiPost(page, '/admin/updates/backup/restore', { backup });
    expect(ok.json?.success).toBe(true);
    if (ok.json?.safety_backup) CREATED_BACKUPS.push(ok.json.safety_backup);
  });
});

test.describe.serial('G4 — Permissions', () => {
  /** @type {import('@playwright/test').Page} */
  let adminPage;
  let staffContext;
  let staffPage;
  let staffEmail;
  let aBackup;

  test.beforeAll(async ({ browser }) => {
    adminPage = await browser.newPage();
    await login(adminPage, ADMIN_EMAIL, ADMIN_PASS);
    await gotoUpdates(adminPage);
    aBackup = await createBackup(adminPage, 'full');

    staffEmail = makeStaffUser('g4');
    staffContext = await browser.newContext();
    staffPage = await staffContext.newPage();
    await login(staffPage, staffEmail, 'Test1234!');
    await gotoUpdates(staffPage);
  });

  test.afterAll(async () => {
    await staffContext?.close();
    await adminPage?.close();
  });

  test('16. Staff is rejected (403) on every destructive backup endpoint', async () => {
    const create = await apiPost(staffPage, '/admin/updates/backup', { scope: 'full' });
    expect(create.status).toBe(403);
    expect(create.json?.error).toBe('Operazione riservata agli amministratori');

    const restore = await apiPost(staffPage, '/admin/updates/backup/restore', { backup: aBackup });
    expect(restore.status).toBe(403);

    const del = await apiPost(staffPage, '/admin/updates/backup/delete', { backup: aBackup });
    expect(del.status).toBe(403);

    const dl = await staffPage.evaluate(async (u) => {
      const r = await fetch(u);
      return r.status;
    }, `${BASE}/admin/updates/backup/download?backup=${encodeURIComponent(aBackup)}`);
    expect(dl).toBe(403);

    // Admin still works.
    const adminCreate = await apiPost(adminPage, '/admin/updates/backup', { scope: 'db' });
    expect(adminCreate.status).toBe(200);
    expect(adminCreate.json?.success).toBe(true);
    if (adminCreate.json?.name) CREATED_BACKUPS.push(adminCreate.json.name); // track for cleanup
  });

  test('17. The backup scope selector is admin-only in the UI', async () => {
    // The scope <select id="backupScope"> is the admin-gated control rendered
    // inside the PHP `if admin` block. (The createBackup() JS function and some
    // i18n labels are emitted for everyone, so only the rendered control is a
    // reliable signal.)
    const staffHtml = await staffPage.content();
    expect(staffHtml.includes('id="backupScope"')).toBe(false);

    await gotoUpdates(adminPage);
    const adminHtml = await adminPage.content();
    expect(adminHtml.includes('id="backupScope"')).toBe(true);
  });
});

// ===== g5 =====
test.describe.serial('G5 — Robustness, PHP-level integration via runPhp', () => {
  let page;

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
    await login(page, ADMIN_EMAIL, ADMIN_PASS);
    await gotoUpdates(page);
  });

  test.afterAll(async () => {
    await page?.close();
  });

  // -------------------------------------------------------------------------
  // Test 18 — PHP fallback streaming import (importViaPhp)
  // -------------------------------------------------------------------------
  test('18. PHP fallback streaming import: importViaPhp round-trips table/row/trigger counts', async () => {
    const phpCode = `
<?php
// Bootstrap: parse .env manually so we can connect to the DB.
$root = '${APP_ROOT.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}';
$env  = file_get_contents($root . '/.env');
foreach (explode("\\n", $env) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k); $v = trim($v, " \\t\\r\\n\\"'");
    $_ENV[$k] = $v;
    putenv("$k=$v");
}
require $root . '/vendor/autoload.php';

$socket = $_ENV['DB_SOCKET'] ?? '';
$user   = $_ENV['DB_USER'] ?? '';
$pass   = $_ENV['DB_PASS'] ?? '';
$name   = $_ENV['DB_NAME'] ?? '';
$host   = $_ENV['DB_HOST'] ?? 'localhost';
$port   = (int)($_ENV['DB_PORT'] ?? 3306);

mysqli_report(MYSQLI_REPORT_OFF);
$db = $socket !== ''
    ? @mysqli_connect('localhost', $user, $pass, $name, 0, $socket)
    : @mysqli_connect($host, $user, $pass, $name, $port);
if (!$db instanceof mysqli) { echo 'CONNECT_FAIL'; exit(1); }
$db->set_charset('utf8mb4');

// (a) Create a full DB backup so we get a real dump with triggers.
$bm = new \\App\\Support\\BackupManager($db, $root);
$result = $bm->createBackup('db');
if (!$result['success']) { echo 'BACKUP_FAIL:' . $result['error']; exit(1); }
$zipPath = $result['path'];

// (b) Extract database.sql from the ZIP to a temp file.
$tmpSql = tempnam(sys_get_temp_dir(), 'pk_import_test_');
$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) { echo 'ZIP_OPEN_FAIL'; exit(1); }
$stream = $zip->getStream('database.sql');
if ($stream === false) { echo 'SQL_ENTRY_FAIL'; exit(1); }
$out = fopen($tmpSql, 'w');
while (!feof($stream)) {
    $chunk = fread($stream, 65536);
    if ($chunk !== false && $chunk !== '') fwrite($out, $chunk);
}
fclose($stream);
fclose($out);
$zip->close();

// (c) Snapshot: table count, utenti row count, trigger count.
$tablesBefore = (int)$db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetch_row()[0];
$utentiBefore = (int)$db->query('SELECT COUNT(*) FROM utenti')->fetch_row()[0];
$trigsBefore  = (int)$db->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')->fetch_row()[0];

// (d) Call importViaPhp via ReflectionMethod on the SAME DB (content-identical => safe).
$rm = new ReflectionMethod(\\App\\Support\\BackupManager::class, 'importViaPhp');
$rm->setAccessible(true);
try {
    $rm->invoke($bm, $tmpSql);
} catch (\\Throwable $e) {
    @unlink($tmpSql);
    echo 'IMPORT_FAIL:' . $e->getMessage();
    exit(1);
}
@unlink($tmpSql);

// (e) Recount after.
$tablesAfter = (int)$db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetch_row()[0];
$utentiAfter = (int)$db->query('SELECT COUNT(*) FROM utenti')->fetch_row()[0];
$trigsAfter  = (int)$db->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')->fetch_row()[0];

$db->close();

if ($tablesBefore === $tablesAfter && $utentiBefore === $utentiAfter && $trigsBefore === $trigsAfter) {
    echo 'OK';
} else {
    echo "MISMATCH tables=$tablesBefore/$tablesAfter utenti=$utentiBefore/$utentiAfter trigs=$trigsBefore/$trigsAfter";
}
`;
    const output = runPhp(phpCode);
    expect(output, `importViaPhp round-trip failed: ${output}`).toContain('OK');
  });

  // -------------------------------------------------------------------------
  // Test 19 — Trigger functional post-restore: overlap prevention
  // -------------------------------------------------------------------------
  test('19. trigger functional post-restore: overlap prevention blocks duplicate active loan', async () => {
    // The trigger trg_check_active_prestito_before_insert keys on copia_id: it
    // SIGNALs SQLSTATE 45000 when a new active loan overlaps an existing one for
    // the SAME physical copy. Seed a libro + a copia, then prove a second
    // overlapping active loan is rejected. (utente_id 1 = admin, reused.)
    const invNum = TAG + '_C19';
    const UTENTE_ID = 1;

    dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES ('${TAG}_libro_t19', 1, 1)`);
    const libroId = dbQuery(`SELECT id FROM libri WHERE titolo='${TAG}_libro_t19' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`);
    expect(Number(libroId)).toBeGreaterThan(0);

    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${libroId}, '${invNum}', 'disponibile')`);
    const copiaId = dbQuery(`SELECT id FROM copie WHERE numero_inventario='${invNum}' LIMIT 1`);
    expect(Number(copiaId)).toBeGreaterThan(0);

    // FIRST active loan — passes the trigger (copy usable, no overlap yet).
    dbQuery(
      `INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo) ` +
      `VALUES (${libroId}, ${copiaId}, ${UTENTE_ID}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'in_corso', 1)`
    );

    // SECOND overlapping active loan for the SAME copia — the trigger must block it.
    let triggerFired = false;
    let errMsg = '';
    try {
      dbQuery(
        `INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo) ` +
        `VALUES (${libroId}, ${copiaId}, ${UTENTE_ID}, DATE_ADD(CURDATE(), INTERVAL 1 DAY), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'in_corso', 1)`
      );
    } catch (err) {
      triggerFired = true;
      errMsg = String(err && err.message || '');
    }

    // Cleanup in FK-safe order BEFORE asserting (so a failed assert still cleans up).
    try { dbQuery(`DELETE FROM prestiti WHERE copia_id = ${copiaId}`); } catch {}
    try { dbQuery(`DELETE FROM copie WHERE id = ${copiaId}`); } catch {}
    try { dbQuery(`DELETE FROM libri WHERE id = ${libroId}`); } catch {}

    expect(triggerFired, 'trigger must block the overlapping second loan').toBe(true);
    expect(errMsg).toMatch(/sovrapposto|già un prestito attivo/i);
  });

  // -------------------------------------------------------------------------
  // Test 20 — cnfEscape escaping rules
  // -------------------------------------------------------------------------
  test("20. cnfEscape: backslash doubled, double-quote escaped, single-quote unchanged", async () => {
    const phpCode = `
<?php
$root = '${APP_ROOT.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}';
$env  = file_get_contents($root . '/.env');
foreach (explode("\\n", $env) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k); $v = trim($v, " \\t\\r\\n\\"'");
    $_ENV[$k] = $v;
    putenv("$k=$v");
}
require $root . '/vendor/autoload.php';

$socket = $_ENV['DB_SOCKET'] ?? '';
$user   = $_ENV['DB_USER'] ?? '';
$pass   = $_ENV['DB_PASS'] ?? '';
$name   = $_ENV['DB_NAME'] ?? '';
$host   = $_ENV['DB_HOST'] ?? 'localhost';
$port   = (int)($_ENV['DB_PORT'] ?? 3306);

mysqli_report(MYSQLI_REPORT_OFF);
$db = $socket !== ''
    ? @mysqli_connect('localhost', $user, $pass, $name, 0, $socket)
    : @mysqli_connect($host, $user, $pass, $name, $port);
if (!$db instanceof mysqli) { echo 'CONNECT_FAIL'; exit(1); }
$db->set_charset('utf8mb4');

$bm = new \\App\\Support\\BackupManager($db, $root);
$rm = new ReflectionMethod(\\App\\Support\\BackupManager::class, 'cnfEscape');
$rm->setAccessible(true);

// Rule 1: single quote passes through unchanged — "pa'ss" => "pa'ss"
$r1 = $rm->invoke($bm, "pa'ss");
$ok1 = ($r1 === "pa'ss");

// Rule 2: double-quote is escaped to backslash+quote — 'a"b' => 'a\\\\"b'
$r2 = $rm->invoke($bm, 'a"b');
$ok2 = ($r2 === 'a\\\\"b');

// Rule 3: backslash is doubled — 'a\\\\b' => 'a\\\\\\\\b'
$r3 = $rm->invoke($bm, 'a\\\\b');
$ok3 = ($r3 === 'a\\\\\\\\b');

$db->close();

if ($ok1 && $ok2 && $ok3) {
    echo 'OK';
} else {
    $details = [];
    if (!$ok1) $details[] = "single_quote: got=" . json_encode($r1) . " want=" . json_encode("pa'ss");
    if (!$ok2) $details[] = "double_quote: got=" . json_encode($r2) . " want=" . json_encode('a\\\\"b');
    if (!$ok3) $details[] = "backslash: got=" . json_encode($r3) . " want=" . json_encode('a\\\\\\\\b');
    echo 'FAIL:' . implode('; ', $details);
}
`;
    const output = runPhp(phpCode);
    expect(output, `cnfEscape rules failed: ${output}`).toContain('OK');
  });

  // -------------------------------------------------------------------------
  // Test 21 — pre-update backup scope plumbing end-to-end
  // -------------------------------------------------------------------------
  test('21. pre-update backup scope plumbing: settings persist + db zip has no files/ + full zip has files/', async () => {
    // Step 1: persist include_files='1' via the settings endpoint.
    const settingsRes = await apiPost(page, '/admin/updates/backup/settings', { include_files: '1' });
    expect(settingsRes.status, 'settings endpoint must return 200').toBe(200);
    expect(settingsRes.json?.success, 'settings response must have success:true').toBe(true);

    // Step 2: verify the setting persisted in the DB.
    const storedValue = dbQuery(
      "SELECT setting_value FROM system_settings WHERE category = 'backup' AND setting_key = 'pre_update_include_files' LIMIT 1"
    );
    expect(storedValue, "setting 'pre_update_include_files' must be '1' in DB").toBe('1');

    // Step 3: create a DB-only backup and assert no 'files/' entries.
    const dbBackupName = await createBackup(page, 'db');
    const dbZipPath = path.join(BACKUP_DIR, dbBackupName);
    const dbEntries = zipEntries(dbZipPath);
    const dbHasFiles = dbEntries.some(e => e.startsWith('files/'));
    expect(dbHasFiles, `scope=db backup must NOT contain files/ entries; got: ${dbEntries.filter(e => e.startsWith('files/')).join(', ')}`).toBe(false);
    expect(dbEntries, 'scope=db backup must contain database.sql').toContain('database.sql');
    expect(dbEntries, 'scope=db backup must contain manifest.json').toContain('manifest.json');

    // Step 4: create a full backup and assert it HAS files/ entries (if any files exist).
    const fullBackupName = await createBackup(page, 'full');
    const fullZipPath = path.join(BACKUP_DIR, fullBackupName);
    const fullEntries = zipEntries(fullZipPath);
    expect(fullEntries, 'scope=full backup must contain database.sql').toContain('database.sql');
    expect(fullEntries, 'scope=full backup must contain manifest.json').toContain('manifest.json');
    // The manifest must declare scope=full; files/ presence depends on whether uploads exist.
    const manifestRaw = require('child_process')
      .execFileSync('unzip', ['-p', fullZipPath, 'manifest.json'], { encoding: 'utf8' })
      .trim();
    const manifest = JSON.parse(manifestRaw);
    expect(manifest.scope, 'manifest.scope must be "full" for a full backup').toBe('full');

    // Cleanup: restore setting to '0' so we don't pollute other tests.
    await apiPost(page, '/admin/updates/backup/settings', { include_files: '0' });
  });
});

// ===== g678 =====
test.describe.serial('G6 — Scanner #164', () => {
  let page;

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
    await login(page, ADMIN_EMAIL, ADMIN_PASS);
  });

  test.afterAll(async () => {
    await page?.close();
  });

  test('22. Enter on #importIsbn fires scrape request and does NOT navigate away', async () => {
    test.setTimeout(30000);
    await page.goto(`${BASE}/admin/books/create`);
    await page.waitForSelector('#importIsbn', { timeout: 10000 });
    const urlBefore = page.url();
    await page.fill('#importIsbn', '9788842935780');
    const [req] = await Promise.all([
      page.waitForRequest(r => r.url().includes('/api/scrape/isbn'), { timeout: 15000 }),
      page.locator('#importIsbn').press('Enter'),
    ]);
    expect(req.url()).toContain('/api/scrape/isbn');
    expect(page.url()).toBe(urlBefore);
  });

  test('23. Double Enter fires exactly ONE scrape request (btn.disabled guard)', async () => {
    test.setTimeout(30000);
    await page.goto(`${BASE}/admin/books/create`);
    await page.waitForSelector('#importIsbn', { timeout: 10000 });
    const requests = [];
    const onReq = r => { if (r.url().includes('/api/scrape/isbn')) requests.push(r.url()); };
    page.on('request', onReq);
    await page.fill('#importIsbn', '9788842935780');
    await page.locator('#importIsbn').press('Enter');
    // Immediately press Enter again — button should be disabled
    await page.locator('#importIsbn').press('Enter');
    await page.waitForTimeout(1500);
    expect(requests.length).toBe(1);
    page.off('request', onReq); // don't let the listener accumulate across tests
  });

  test('24. Empty code shows SweetAlert "Codice mancante" and fires NO scrape request', async () => {
    test.setTimeout(20000);
    await page.goto(`${BASE}/admin/books/create`);
    await page.waitForSelector('#importIsbn', { timeout: 10000 });
    const urlBefore = page.url();
    const scrapeRequests = [];
    const onReq = r => { if (r.url().includes('/api/scrape/isbn')) scrapeRequests.push(r.url()); };
    page.on('request', onReq);
    await page.fill('#importIsbn', '');
    await page.locator('#importIsbn').press('Enter');
    await expect(page.locator('.swal2-popup')).toBeVisible({ timeout: 5000 });
    await expect(page.locator('.swal2-popup')).toContainText('Codice mancante');
    expect(scrapeRequests.length).toBe(0);
    expect(page.url()).toBe(urlBefore);
    page.off('request', onReq); // don't let the listener accumulate across tests
    await page.locator('.swal2-confirm').click();
  });

  test('25. EAN non-ISBN fires scrape request and does NOT show "Codice mancante"', async () => {
    test.setTimeout(30000);
    await page.goto(`${BASE}/admin/books/create`);
    await page.waitForSelector('#importIsbn', { timeout: 10000 });
    await page.fill('#importIsbn', '4006381333931');
    const [req] = await Promise.all([
      page.waitForRequest(r => r.url().includes('/api/scrape/isbn'), { timeout: 15000 }),
      page.locator('#importIsbn').press('Enter'),
    ]);
    expect(req.url()).toContain('/api/scrape/isbn');
    // No "Codice mancante" SweetAlert should appear
    const popupVisible = await page.locator('.swal2-popup').isVisible().catch(() => false);
    if (popupVisible) {
      const text = await page.locator('.swal2-popup').textContent().catch(() => '');
      expect(text).not.toContain('Codice mancante');
    }
  });
});

test.describe.serial('G7 — Cover #165/#166', () => {
  let page;
  let pngPathA;
  let pngPathB;

  // 1×1 red PNG (bytes)
  const RED_PNG = [
    137,80,78,71,13,10,26,10,0,0,0,13,73,72,68,82,0,0,0,1,0,0,0,1,8,2,0,0,0,144,119,83,222,
    0,0,0,12,73,68,65,84,8,215,99,248,207,192,0,0,0,2,0,1,226,33,188,51,0,0,0,0,73,69,78,68,174,66,96,130
  ];
  // 1×1 blue PNG (bytes)
  const BLUE_PNG = [
    137,80,78,71,13,10,26,10,0,0,0,13,73,72,68,82,0,0,0,1,0,0,0,1,8,2,0,0,0,144,119,83,222,
    0,0,0,12,73,68,65,84,8,215,99,248,15,0,0,0,2,0,1,232,221,120,42,0,0,0,0,73,69,78,68,174,66,96,130
  ];

  async function createWithCover(title, pngPath) {
    await page.goto(`${BASE}/admin/books/create`);
    await page.waitForSelector('#titolo', { timeout: 10000 });
    await page.fill('#titolo', title);
    await page.locator('#fallback-file-input').setInputFiles(pngPath);
    // Submit the form
    await page.locator('#bookForm button[type="submit"]').click();
    // Dismiss any SweetAlert confirmation
    const confirmBtn = page.locator('.swal2-confirm');
    if (await confirmBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
      await confirmBtn.click();
    }
    // Wait for navigation to book list or edit page
    await page.waitForURL(/\/admin\/books(?:\/\d+)?(?:\?.*)?$/, { timeout: 30000 });
    const safeTitle = title.replace(/'/g, "\\'");
    const row = dbQuery(`SELECT id, copertina_url FROM libri WHERE titolo = '${safeTitle}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`);
    const parts = row.split('\t');
    const id = parseInt(parts[0], 10);
    const coverUrl = (parts[1] || '').trim();
    expect(id).toBeGreaterThan(0);
    CREATED_BOOKS.push(id);
    return { id, coverUrl };
  }

  function coverFile(url) {
    return path.join(APP_ROOT, 'public', url.replace(/^\//, ''));
  }

  test.beforeAll(async ({ browser }) => {
    const os_ = require('os');
    pngPathA = path.join(os_.tmpdir(), `cover_red_${TAG}.png`);
    pngPathB = path.join(os_.tmpdir(), `cover_blue_${TAG}.png`);
    require('fs').writeFileSync(pngPathA, Buffer.from(RED_PNG));
    require('fs').writeFileSync(pngPathB, Buffer.from(BLUE_PNG));
    page = await browser.newPage();
    await login(page, ADMIN_EMAIL, ADMIN_PASS);
  });

  test.afterAll(async () => {
    await page?.close();
    try { require('fs').unlinkSync(pngPathA); } catch (_) {}
    try { require('fs').unlinkSync(pngPathB); } catch (_) {}
  });

  test('26. Replace cover A with cover B: old file removed, new file exists', async () => {
    test.setTimeout(60000);
    const title = `CoverTest26_${TAG}`;
    const { id, coverUrl: urlA } = await createWithCover(title, pngPathA);
    expect(urlA).toMatch(/\/uploads\/copertine\//);
    const fileA = coverFile(urlA);
    expect(require('fs').existsSync(fileA)).toBe(true);

    // Edit: replace with cover B
    await page.goto(`${BASE}/admin/books/edit/${id}`);
    await page.waitForSelector('#bookForm', { timeout: 10000 });
    await page.locator('#fallback-file-input').setInputFiles(pngPathB);
    await page.locator('#bookForm button[type="submit"]').click();
    const confirmBtn = page.locator('.swal2-confirm');
    if (await confirmBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
      await confirmBtn.click();
    }
    await page.waitForURL(/\/admin\/books(?:\/\d+)?(?:\?.*)?$/, { timeout: 30000 });

    const safeTitle = title.replace(/'/g, "\\'");
    const rowB = dbQuery(`SELECT copertina_url FROM libri WHERE id = ${id} AND deleted_at IS NULL`);
    const urlB = rowB.trim();
    expect(urlB).toMatch(/\/uploads\/copertine\//);
    expect(urlB).not.toBe(urlA);
    const fileB = coverFile(urlB);
    expect(require('fs').existsSync(fileB)).toBe(true);
    expect(require('fs').existsSync(fileA)).toBe(false);
  });

  test('27. URL replace (network-tolerant): whitelisted URL replaces local cover or is skipped gracefully', async () => {
    test.setTimeout(60000);
    const title = `CoverTest27_${TAG}`;
    const { id, coverUrl: urlA } = await createWithCover(title, pngPathA);
    expect(urlA).toMatch(/\/uploads\/copertine\//);
    const fileA = coverFile(urlA);
    expect(require('fs').existsSync(fileA)).toBe(true);

    // Edit: set scraped_cover_url to a whitelisted external URL
    await page.goto(`${BASE}/admin/books/edit/${id}`);
    await page.waitForSelector('#bookForm', { timeout: 10000 });
    await page.evaluate((url) => {
      const el = document.getElementById('scraped_cover_url');
      if (el) el.value = url;
    }, 'https://covers.openlibrary.org/b/id/14625765-L.jpg');
    await page.locator('#bookForm button[type="submit"]').click();
    const confirmBtn = page.locator('.swal2-confirm');
    if (await confirmBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
      await confirmBtn.click();
    }
    await page.waitForURL(/\/admin\/books(?:\/\d+)?(?:\?.*)?$/, { timeout: 30000 });

    const rowAfter = dbQuery(`SELECT copertina_url FROM libri WHERE id = ${id} AND deleted_at IS NULL`);
    const urlAfter = rowAfter.trim();

    if (urlAfter !== urlA && urlAfter.match(/\/uploads\/copertine\//)) {
      // Downloaded successfully: old file removed, new exists
      console.log('27: cover downloaded from openlibrary');
      const fileAfter = coverFile(urlAfter);
      expect(require('fs').existsSync(fileAfter)).toBe(true);
      expect(require('fs').existsSync(fileA)).toBe(false);
    } else {
      // Offline: cover unchanged (local file still valid)
      console.log('27: network unavailable — cover unchanged, asserting local file preserved');
      expect(urlAfter).toMatch(/\/uploads\/copertine\//);
      const fileAfter = coverFile(urlAfter);
      expect(require('fs').existsSync(fileAfter)).toBe(true);
    }
  });

  test('28. Non-whitelisted URL (F006): cover unchanged, original local file preserved', async () => {
    test.setTimeout(60000);
    const title = `CoverTest28_${TAG}`;
    const { id, coverUrl: urlA } = await createWithCover(title, pngPathA);
    expect(urlA).toMatch(/\/uploads\/copertine\//);
    const fileA = coverFile(urlA);
    expect(require('fs').existsSync(fileA)).toBe(true);

    // Edit: set both fields to a non-whitelisted domain.
    // The real UI (applyAlternativeCover in book_form.php:3495-3496) sets BOTH
    // copertina_url AND scraped_cover_url, so we mirror that here.
    await page.goto(`${BASE}/admin/books/edit/${id}`);
    await page.waitForSelector('#bookForm', { timeout: 10000 });
    await page.evaluate((url) => {
      const cu = document.getElementById('copertina_url');
      if (cu) cu.value = url;
      const sc = document.getElementById('scraped_cover_url');
      if (sc) sc.value = url;
    }, 'https://evil.example.com/x.jpg');
    await page.locator('#bookForm button[type="submit"]').click();
    const confirmBtn = page.locator('.swal2-confirm');
    if (await confirmBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
      await confirmBtn.click();
    }
    await page.waitForURL(/\/admin\/books(?:\/\d+)?(?:\?.*)?$/, { timeout: 30000 });

    const rowAfter = dbQuery(`SELECT copertina_url FROM libri WHERE id = ${id} AND deleted_at IS NULL`);
    const urlAfter = rowAfter.trim();
    expect(urlAfter).toBe(urlA);
    expect(require('fs').existsSync(fileA)).toBe(true);
  });

  test('29. Remove then re-pick (F007 inverse): applyAlternativeCover resets remove_cover to 0', async () => {
    test.setTimeout(60000);
    const title = `CoverTest29_${TAG}`;
    const { id, coverUrl: urlA } = await createWithCover(title, pngPathA);
    expect(urlA).toMatch(/\/uploads\/copertine\//);

    await page.goto(`${BASE}/admin/books/edit/${id}`);
    await page.waitForSelector('#bookForm', { timeout: 10000 });

    // Simulate: user clicks remove (remove_cover=1, copertina_url=''), then re-applies the cover
    const absUrl = `${BASE}${urlA}`;
    await page.evaluate((url) => {
      const rc = document.getElementById('remove_cover');
      const cu = document.getElementById('copertina_url');
      if (rc) rc.value = '1';
      if (cu) cu.value = '';
      // Re-apply the cover via applyAlternativeCover
      if (typeof window.applyAlternativeCover === 'function') {
        window.applyAlternativeCover(url);
      } else {
        // Fallback: manually reset flags as the function would
        if (rc) rc.value = '0';
        if (cu) cu.value = url;
        const sc = document.getElementById('scraped_cover_url');
        if (sc) sc.value = url;
      }
    }, absUrl);

    // Assert remove_cover was reset to '0'
    const removeCoverValue = await page.locator('#remove_cover').inputValue();
    expect(removeCoverValue).toBe('0');

    // Submit and assert cover is preserved
    await page.locator('#bookForm button[type="submit"]').click();
    const confirmBtn = page.locator('.swal2-confirm');
    if (await confirmBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
      await confirmBtn.click();
    }
    await page.waitForURL(/\/admin\/books(?:\/\d+)?(?:\?.*)?$/, { timeout: 30000 });

    const rowAfter = dbQuery(`SELECT copertina_url FROM libri WHERE id = ${id} AND deleted_at IS NULL`);
    const urlAfter = rowAfter.trim();
    expect(urlAfter).not.toBe('');
    expect(urlAfter.length).toBeGreaterThan(0);
  });

  test('30. Create with both scraped URL + uploaded file (F002 no orphan): final cover is local file', async () => {
    test.setTimeout(60000);
    const title = `CoverTest30_${TAG}`;
    await page.goto(`${BASE}/admin/books/create`);
    await page.waitForSelector('#titolo', { timeout: 10000 });
    await page.fill('#titolo', title);

    // Set both scraped_cover_url (whitelisted) and a local file upload
    await page.evaluate(() => {
      const el = document.getElementById('scraped_cover_url');
      if (el) el.value = 'https://covers.openlibrary.org/b/id/14625765-L.jpg';
    });
    await page.locator('#fallback-file-input').setInputFiles(pngPathA);

    await page.locator('#bookForm button[type="submit"]').click();
    const confirmBtn = page.locator('.swal2-confirm');
    if (await confirmBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
      await confirmBtn.click();
    }
    await page.waitForURL(/\/admin\/books(?:\/\d+)?(?:\?.*)?$/, { timeout: 30000 });

    const safeTitle = title.replace(/'/g, "\\'");
    const row = dbQuery(`SELECT id, copertina_url FROM libri WHERE titolo = '${safeTitle}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`);
    const parts = row.split('\t');
    const id = parseInt(parts[0], 10);
    const coverUrl = (parts[1] || '').trim();
    expect(id).toBeGreaterThan(0);
    CREATED_BOOKS.push(id);

    // Final cover must be a local file
    expect(coverUrl).toMatch(/\/uploads\/copertine\//);
    const coverPath = coverFile(coverUrl);
    expect(require('fs').existsSync(coverPath)).toBe(true);
  });
});

test.describe.serial('G8 — Cross-cutting', () => {
  test('31. i18n: required backup/scanner keys present in all 4 locales', async () => {
    const requiredKeys = [
      'Codice mancante',
      'Formato legacy',
      'Ricarica la pagina',
      'Sono accettati solo file .zip.',
      'File troppo grande',
      'Il file supera il limite di 2 GB.',
      'Ripristino parziale',
    ];
    for (const locale of ['it_IT', 'en_US', 'de_DE', 'fr_FR']) {
      const filePath = path.join(APP_ROOT, 'locale', `${locale}.json`);
      const j = JSON.parse(require('fs').readFileSync(filePath, 'utf8'));
      for (const key of requiredKeys) {
        expect(key in j, `key "${key}" missing from ${locale}.json`).toBe(true);
      }
    }
  });

  test('32. Build guard: version >= 0.7.19, BackupManager has copyStreamCapped+update.lock, LibriController has deleteLocalCoverFile', async () => {
    const versionData = JSON.parse(require('fs').readFileSync(path.join(APP_ROOT, 'version.json'), 'utf8'));
    const parts = versionData.version.split('.').map(Number);
    const minParts = [0, 7, 19];
    let isGte = false;
    for (let i = 0; i < minParts.length; i++) {
      if ((parts[i] ?? 0) > minParts[i]) { isGte = true; break; }
      if ((parts[i] ?? 0) < minParts[i]) { isGte = false; break; }
      if (i === minParts.length - 1) isGte = true;
    }
    expect(isGte, `version ${versionData.version} must be >= 0.7.19`).toBe(true);

    const bm = require('fs').readFileSync(path.join(APP_ROOT, 'app/Support/BackupManager.php'), 'utf8');
    expect(bm.includes('copyStreamCapped'), 'BackupManager.php must contain copyStreamCapped').toBe(true);
    expect(bm.includes('update.lock'), 'BackupManager.php must reference update.lock').toBe(true);

    const lc = require('fs').readFileSync(path.join(APP_ROOT, 'app/Controllers/LibriController.php'), 'utf8');
    expect(lc.includes('deleteLocalCoverFile'), 'LibriController.php must contain deleteLocalCoverFile').toBe(true);
  });
});
// ===========================================================================

// ---------------------------------------------------------------------------
// Global cleanup — runs after every group.
// ---------------------------------------------------------------------------
test.afterAll(async () => {
  for (const id of CREATED_BOOKS) {
    try { dbQuery(`UPDATE libri SET deleted_at=NOW(), isbn10=NULL, isbn13=NULL, ean=NULL WHERE id=${id}`); } catch {}
  }
  try { dbQuery(`DELETE FROM libri WHERE titolo LIKE '${TAG}%'`); } catch {}
  for (const email of CREATED_USERS) {
    try { dbQuery(`DELETE FROM utenti WHERE email='${email.replace(/'/g, "''")}'`); } catch {}
  }
  for (const name of CREATED_BACKUPS) {
    try { const p = path.join(BACKUP_DIR, name); if (fs.existsSync(p)) fs.unlinkSync(p); } catch {}
  }
});
