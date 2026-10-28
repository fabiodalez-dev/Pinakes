// @ts-check
//
// Patron photo (plan phase 7). Chromium runs with a fake camera
// (--use-fake-device-for-media-stream) and auto-accepted permission prompt
// (--use-fake-ui-for-media-stream), so "Scatta foto" goes through the real
// getUserMedia → canvas → upload path. The photo must be stored outside the
// web root, shown to staff, printed on the card, and erasable.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const { serverFileExists, readServerFile, removeServerFile } = require('./helpers/server-files');
const os = require('os');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ROOT = path.resolve(__dirname, '..');
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

let pdfimages = true;
try { execFileSync('pdfimages', ['-v'], { stdio: 'ignore' }); } catch { pdfimages = false; }

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME || (!DB_HOST && !DB_SOCKET), 'E2E credentials not configured');

test.use({
  launchOptions: { args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'] },
  permissions: ['camera'],
});

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
const storedPhoto = (id) => dbQuery(`SELECT IFNULL(foto_profilo_url, '') FROM utenti WHERE id = ${Number(id)}`);
const photoFile = (stored) => path.join(ROOT, 'storage', stored);

const RUN = Date.now().toString(36).toUpperCase();

async function login(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL((u) => u.toString().includes('/admin'), { timeout: 15000 });
}

/** A small valid PNG written with a raw zlib stream (no image library needed). */
function writePng(file, w = 64, h = 80) {
  const zlib = require('zlib');
  const crcTable = Array.from({ length: 256 }, (_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c >>> 0; });
  const crc = (buf) => { let c = 0xffffffff; for (const b of buf) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };
  const chunk = (type, data) => {
    const len = Buffer.alloc(4); len.writeUInt32BE(data.length);
    const body = Buffer.concat([Buffer.from(type), data]);
    const c = Buffer.alloc(4); c.writeUInt32BE(crc(body));
    return Buffer.concat([len, body, c]);
  };
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2; ihdr[10] = 0; ihdr[11] = 0; ihdr[12] = 0;
  const raw = Buffer.alloc((w * 3 + 1) * h);
  for (let y = 0; y < h; y++) {
    raw[y * (w * 3 + 1)] = 0;
    for (let x = 0; x < w; x++) { const o = y * (w * 3 + 1) + 1 + x * 3; raw[o] = 20; raw[o + 1] = 120 + (y % 100); raw[o + 2] = 200; }
  }
  const png = Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
  fs.writeFileSync(file, png);
}

test.describe.serial('Patron photo (phase 7)', () => {
  let userId = 0;
  let bookId = 0;
  const code = `ZPH${RUN}`;
  const files = [];

  test.beforeAll(() => {
    dbQuery(`INSERT INTO utenti (nome, cognome, email, password, codice_tessera, stato, tipo_utente, email_verificata, telefono)
             VALUES ('Fotina', ${sqlStr(`Webcam${RUN}`)}, ${sqlStr(`zph-${RUN.toLowerCase()}@example.invalid`)}, 'x', ${sqlStr(code)}, 'attivo', 'standard', 1, '3331234567')`);
    userId = Number(dbQuery(`SELECT id FROM utenti WHERE codice_tessera = ${sqlStr(code)}`));
  });

  test.afterAll(() => {
    const stored = userId ? storedPhoto(userId) : '';
    if (stored) files.push(photoFile(stored));
    if (userId) dbQuery(`DELETE FROM prestiti WHERE utente_id = ${userId}`);
    if (userId) dbQuery(`DELETE FROM utenti WHERE id = ${userId}`);
    if (bookId) dbQuery(`DELETE FROM libri WHERE id = ${bookId}`);
    for (const f of files) removeServerFile(f);
  });

  test('"Scatta foto" captures a frame from the camera and saves it outside the web root', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/users/edit/${userId}`);
    const widget = page.locator('#patron-photo-widget');
    await expect(widget).toBeVisible();
    await expect(page.locator('#photo-placeholder')).toBeVisible();
    await expect(page.locator('#photo-delete')).toBeHidden();

    await widget.getByRole('button', { name: 'Scatta foto' }).click();
    const video = page.locator('#photo-video');
    await expect(video).toBeVisible();
    // Wait until the fake camera delivers frames.
    await expect.poll(() => video.evaluate((v) => /** @type {HTMLVideoElement} */ (v).videoWidth), { timeout: 15000 }).toBeGreaterThan(0);
    await page.locator('#photo-capture').click();

    await expect(page.locator('#photo-status')).toHaveText('Foto salvata.', { timeout: 15000 });
    await expect(page.locator('#photo-preview')).toBeVisible();
    await expect(video).toBeHidden();
    await expect(page.locator('#photo-delete')).toBeVisible();

    const stored = storedPhoto(userId);
    expect(stored).toMatch(new RegExp(`^patron_photos/${userId}-[a-f0-9]{16}\\.jpg$`));
    const file = photoFile(stored);
    expect(serverFileExists(file)).toBe(true);
    // Re-encoded JPEG, not the browser upload as-is; portrait crop from the canvas.
    const head = readServerFile(file).subarray(0, 3);
    expect(head.equals(Buffer.from([0xff, 0xd8, 0xff]))).toBe(true);
    // Nothing reachable under the public web root.
    const direct = await page.request.get(`${BASE}/storage/${stored}`, { maxRedirects: 0 });
    expect(direct.status()).not.toBe(200);
  });

  test('the reader page shows the photo through the protected route, and the camera permission is same-origin only', async ({ page }) => {
    await login(page);
    const resp = await page.goto(`${BASE}/admin/users/details/${userId}`);
    expect(resp?.headers()['permissions-policy']).toBe('geolocation=(), microphone=(), camera=(self)');
    const img = page.locator('#patron-photo');
    await expect(img).toBeVisible();
    await expect.poll(() => img.evaluate((el) => /** @type {HTMLImageElement} */ (el).naturalWidth)).toBeGreaterThan(0);
    const src = String(await img.getAttribute('src'));
    expect(src).toContain(`/admin/users/${userId}/photo`);
    const photo = await page.request.get(src.startsWith('http') ? src : `${BASE}${src}`);
    expect(photo.status()).toBe(200);
    expect(photo.headers()['content-type']).toBe('image/jpeg');
    expect(photo.headers()['cache-control']).toContain('no-store');

    const anon = await page.context().browser().newContext();
    const denied = await anon.request.get(`${BASE}/admin/users/${userId}/photo`, { maxRedirects: 0 });
    expect(denied.status()).not.toBe(200);
    await anon.close();
  });

  test('the photo is printed on the library card', async ({ page }) => {
    test.skip(!pdfimages, 'poppler pdfimages not installed');
    await login(page);
    const resp = await page.request.get(`${BASE}/admin/users/${userId}/card-pdf?format=single`);
    expect(resp.status()).toBe(200);
    const file = path.join(os.tmpdir(), `photo-card-${RUN}.pdf`);
    fs.writeFileSync(file, await resp.body());
    const list = execFileSync('pdfimages', ['-list', file], { encoding: 'utf-8' });
    const text = execFileSync('pdftotext', [file, '-'], { encoding: 'utf-8' });
    fs.unlinkSync(file);
    // Header lines + at least the photo (the logo may add one more).
    const images = list.trim().split('\n').slice(2).filter((l) => /\bimage\b/.test(l));
    expect(images.length).toBeGreaterThanOrEqual(1);
    expect(text).toContain(code);
    expect(text).not.toContain('FW'); // initials placeholder replaced by the photo
  });

  test('"Carica foto" replaces the photo with a picked file and refuses a non-image', async ({ page }) => {
    await login(page);
    const before = storedPhoto(userId);
    await page.goto(`${BASE}/admin/users/edit/${userId}`);
    const png = path.join(os.tmpdir(), `zph-${RUN}.png`);
    writePng(png);
    await page.locator('#photo-file').setInputFiles(png);
    await expect(page.locator('#photo-status')).toHaveText('Foto salvata.', { timeout: 15000 });
    const after = storedPhoto(userId);
    expect(after).not.toBe(before);
    expect(serverFileExists(photoFile(before))).toBe(false); // the old file is gone
    expect(serverFileExists(photoFile(after))).toBe(true);
    fs.unlinkSync(png);

    const fake = path.join(os.tmpdir(), `zph-${RUN}.jpg`);
    fs.writeFileSync(fake, '<?php echo "not an image";');
    await page.locator('#photo-file').setInputFiles(fake);
    await expect(page.locator('#photo-status')).toHaveText(/JPEG, PNG o WebP/, { timeout: 15000 });
    expect(storedPhoto(userId)).toBe(after); // unchanged
    fs.unlinkSync(fake);
  });

  test('"Elimina foto" on the reader page erases file and reference (GDPR)', async ({ page }) => {
    await login(page);
    const stored = storedPhoto(userId);
    expect(stored).not.toBe('');
    await page.goto(`${BASE}/admin/users/details/${userId}`);
    await page.locator('#delete-photo-form button[type="submit"]').click();
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await Promise.all([page.waitForURL(new RegExp(`/admin/users/details/${userId}`)), page.locator('.swal2-confirm').click()]);
    await expect(page.locator('#patron-photo-empty')).toBeVisible();
    await expect(page.locator('#patron-photo')).toHaveCount(0);
    expect(storedPhoto(userId)).toBe('');
    expect(serverFileExists(photoFile(stored))).toBe(false);
    const gone = await page.request.get(`${BASE}/admin/users/${userId}/photo`);
    expect(gone.status()).toBe(404);
  });

  test('the edit form deletes a fresh webcam photo, and saving the form afterwards keeps it deleted', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/users/edit/${userId}`);
    await page.locator('#photo-camera-start').click();
    const video = page.locator('#photo-video');
    await expect.poll(() => video.evaluate((v) => /** @type {HTMLVideoElement} */ (v).videoWidth), { timeout: 15000 }).toBeGreaterThan(0);
    await page.locator('#photo-capture').click();
    await expect(page.locator('#photo-status')).toHaveText('Foto salvata.', { timeout: 15000 });
    const stored = storedPhoto(userId);
    expect(stored).not.toBe('');

    await page.locator('#photo-delete').click();
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await page.locator('.swal2-confirm').click();
    await expect(page.locator('#photo-status')).toHaveText('Foto eliminata.', { timeout: 15000 });
    await expect(page.locator('#photo-placeholder')).toBeVisible();
    await expect(page.locator('#photo-delete')).toBeHidden();
    expect(storedPhoto(userId)).toBe('');
    expect(serverFileExists(photoFile(stored))).toBe(false);

    // Saving the rest of the form afterwards leaves the photo state alone
    // (the address may be a required registration field on this install).
    await page.locator('#indirizzo').fill('Via Roma 1, Milano');
    await page.locator('#user-form button[type="submit"]').click();
    await page.waitForURL((u) => !u.toString().includes('/edit/'), { timeout: 15000 });
    expect(storedPhoto(userId)).toBe('');
  });

  test('deleting a reader with loan history keeps the account as suspended but still erases the photo', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/users/edit/${userId}`);
    const png = path.join(os.tmpdir(), `zph-loan-${RUN}.png`);
    writePng(png);
    await page.locator('#photo-file').setInputFiles(png);
    await expect(page.locator('#photo-status')).toHaveText('Foto salvata.', { timeout: 15000 });
    fs.unlinkSync(png);
    const stored = storedPhoto(userId);
    expect(stored).not.toBe('');
    files.push(photoFile(stored));
    // A returned loan: the account cannot be deleted, only marked "sospeso".
    const title = `Photo loan ${RUN}`;
    dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES (${sqlStr(title)}, 1, 1)`);
    bookId = Number(dbQuery(`SELECT id FROM libri WHERE titolo = ${sqlStr(title)} ORDER BY id DESC LIMIT 1`));
    dbQuery(`INSERT INTO prestiti (utente_id, libro_id, data_prestito, data_scadenza, stato, attivo) VALUES (${userId}, ${bookId}, CURDATE(), CURDATE(), 'restituito', 0)`);

    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const res = await page.request.post(`${BASE}/admin/users/delete/${userId}`, { form: { csrf_token: String(csrf) }, maxRedirects: 0 });
    expect(res.status()).toBe(302);
    expect(dbQuery(`SELECT stato FROM utenti WHERE id = ${userId}`)).toBe('sospeso');
    expect(dbQuery(`SELECT COUNT(*) FROM prestiti WHERE utente_id = ${userId}`)).toBe('1');
    expect(storedPhoto(userId)).toBe('');
    expect(serverFileExists(photoFile(stored))).toBe(false);
    const gone = await page.request.get(`${BASE}/admin/users/${userId}/photo`);
    expect(gone.status()).toBe(404);
  });
});
