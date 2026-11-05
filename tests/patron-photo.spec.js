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

// The card test reads the PDF with both poppler tools: pdfimages for the
// photo, pdftotext for the name.
let poppler = true;
try { execFileSync('pdfimages', ['-v'], { stdio: 'ignore' }); execFileSync('pdftotext', ['-v'], { stdio: 'ignore' }); } catch { poppler = false; }

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

async function login(page, email = ADMIN_EMAIL, password = ADMIN_PASS) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  const rejectCookies = page.locator('#silktide-wrapper .reject-all');
  await rejectCookies.click({ timeout: 1500 }).catch(() => {});
  await page.locator('button[type="submit"]').click();
  await page.waitForURL((u) => u.toString().includes('/admin'), { timeout: 15000 });
}

/** Give a reader a photo the way a librarian does: "Carica foto" on the edit form. */
async function uploadPhoto(page, id, name) {
  await page.goto(`${BASE}/admin/users/edit/${id}`);
  const png = path.join(os.tmpdir(), `${name}-${RUN}.png`);
  writePng(png);
  await page.locator('#photo-file').setInputFiles(png);
  await expect(page.locator('#photo-status')).toHaveText('Foto salvata.', { timeout: 15000 });
  fs.unlinkSync(png);
  const stored = storedPhoto(id);
  expect(stored).not.toBe('');
  return stored;
}

/**
 * A small valid PNG written with a raw zlib stream (no image library needed).
 * `paint(x, y)` may return the [r, g, b] of a pixel; the default is a gradient.
 */
function writePng(file, w = 64, h = 80, paint = null) {
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
    for (let x = 0; x < w; x++) {
      const o = y * (w * 3 + 1) + 1 + x * 3;
      const [r, g, b] = paint ? paint(x, y) : [20, 120 + (y % 100), 200];
      raw[o] = r; raw[o + 1] = g; raw[o + 2] = b;
    }
  }
  const png = Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
  fs.writeFileSync(file, png);
}

test.describe.serial('Patron photo (phase 7)', () => {
  let userId = 0;
  let bookId = 0;
  let roleId = 0;
  const code = `ZPH${RUN}`;
  const files = [];
  const extraUsers = [];

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
    for (const id of extraUsers) {
      const extra = storedPhoto(id);
      if (extra) files.push(photoFile(extra));
      dbQuery(`DELETE FROM utenti WHERE id = ${id}`);
    }
    if (roleId) dbQuery(`DELETE FROM ruoli WHERE id = ${roleId}`);
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

  test('on a phone "Scatta foto" opens the camera app and stores the shot as a 4:5 card photo', async ({ browser }) => {
    const { devices } = require('@playwright/test');
    const context = await browser.newContext({ ...devices['iPhone 13'] });
    const page = await context.newPage();
    try {
      await login(page);
      await page.goto(`${BASE}/admin/users/edit/${userId}`);
      // A tap must not start the inline webcam: the phone's own camera opens.
      await page.evaluate(() => { /** @type {any} */ (window).__gum = 0; if (navigator.mediaDevices) navigator.mediaDevices.getUserMedia = async () => { /** @type {any} */ (window).__gum++; throw new Error('inline webcam'); }; });
      const [chooser] = await Promise.all([page.waitForEvent('filechooser'), page.locator('#patron-photo-widget').getByRole('button', { name: 'Scatta foto' }).tap()]);
      expect(await chooser.element().getAttribute('capture')).toBe('environment');
      await expect(page.locator('#photo-video')).toBeHidden();
      // A portrait shot, larger than the card: the 4:5 crop keeps the centre
      // 900×1125 (rows 238–1362), painted green; the bands above and below,
      // painted red, must not reach the card. A whole picture squeezed to
      // 480×600 would show red at its top and bottom edges.
      const shot = path.join(os.tmpdir(), `phone-shot-${RUN}.png`);
      writePng(shot, 900, 1600, (x, y) => (y < 238 || y >= 1362 ? [220, 20, 20] : [20, 200, 40]));
      await chooser.setFiles(shot);
      fs.unlinkSync(shot);
      await expect(page.locator('#photo-status')).toHaveText('Foto salvata.', { timeout: 15000 });
      const size = await page.locator('#photo-preview').evaluate(async (img) => { const i = /** @type {HTMLImageElement} */ (img); await i.decode(); return [i.naturalWidth, i.naturalHeight]; });
      expect(size).toEqual([480, 600]);
      const edges = await page.locator('#photo-preview').evaluate((img) => {
        const c = document.createElement('canvas'); c.width = 480; c.height = 600;
        const x = c.getContext('2d'); x.drawImage(/** @type {HTMLImageElement} */ (img), 0, 0);
        return [x.getImageData(240, 3, 1, 1).data, x.getImageData(240, 596, 1, 1).data].map((d) => [d[0], d[1]]);
      });
      for (const [r, g] of edges) { expect(g).toBeGreaterThan(150); expect(r).toBeLessThan(90); }
      expect(await page.evaluate(() => /** @type {any} */ (window).__gum)).toBe(0);
      const stored = storedPhoto(userId);
      expect(stored).toMatch(new RegExp(`^patron_photos/${userId}-[a-f0-9]{16}\\.jpg$`));
      files.push(photoFile(stored));
    } finally {
      await context.close();
    }
  });

  test('an Android tablet (no Mobile token in its user agent) opens the camera app', async ({ browser }) => {
    const { devices } = require('@playwright/test');
    const context = await browser.newContext({ ...devices['Galaxy Tab S4'] });
    // Chrome on an Android tablet reports userAgentData.mobile === false.
    await context.addInitScript(() => {
      Object.defineProperty(navigator, 'userAgentData', { configurable: true, get: () => ({ mobile: false, platform: 'Android', brands: [] }) });
    });
    const page = await context.newPage();
    try {
      await login(page);
      await page.goto(`${BASE}/admin/users/edit/${userId}`);
      expect(await page.evaluate(() => [/Mobile/.test(navigator.userAgent), navigator.userAgentData.mobile])).toEqual([false, false]);
      const [chooser] = await Promise.all([page.waitForEvent('filechooser'), page.locator('#patron-photo-widget').getByRole('button', { name: 'Scatta foto' }).tap()]);
      expect(await chooser.element().getAttribute('capture')).toBe('environment');
      await expect(page.locator('#photo-video')).toBeHidden();
    } finally {
      await context.close();
    }
  });

  test('a phone shot with EXIF orientation 6 is turned upright before the 4:5 crop', async ({ browser }) => {
    const { devices } = require('@playwright/test');
    const context = await browser.newContext({ ...devices['iPhone 13'] });
    const page = await context.newPage();
    try {
      await login(page);
      await page.goto(`${BASE}/admin/users/edit/${userId}`);
      const [chooser] = await Promise.all([page.waitForEvent('filechooser'), page.locator('#patron-photo-widget').getByRole('button', { name: 'Scatta foto' }).tap()]);
      // Stored 1600×900 with Orientation 6 (turn 90° clockwise): shown upright
      // it is 900×1600, the stored top band (blue) on its right edge and the
      // stored left band (red) on top, outside the centred 4:5 crop, and a
      // yellow band (stored columns 1250-1300) across the lower part of the
      // card. Read as stored, the crop would show blue at the top instead;
      // turned upside down, the yellow band would sit near the top.
      await chooser.setFiles(path.join(__dirname, 'fixtures', 'photo-exif-orientation-6.jpg'));
      await expect(page.locator('#photo-status')).toHaveText('Foto salvata.', { timeout: 15000 });
      const px = await page.locator('#photo-preview').evaluate(async (img) => {
        const i = /** @type {HTMLImageElement} */ (img); await i.decode();
        const c = document.createElement('canvas'); c.width = i.naturalWidth; c.height = i.naturalHeight;
        const x = c.getContext('2d'); x.drawImage(i, 0, 0);
        const at = (px, py) => Array.from(x.getImageData(px, py, 1, 1).data.slice(0, 3));
        return { size: [i.naturalWidth, i.naturalHeight], top: at(240, 4), right: at(475, 300), left: at(4, 300), middle: at(240, 300), lower: at(240, 553), upper: at(240, 46) };
      });
      expect(px.size).toEqual([480, 600]);
      const isBlue = ([r, g, b]) => b > 150 && r < 90 && g < 110;
      const isGreen = ([r, g, b]) => g > 150 && r < 90 && b < 110;
      expect(isBlue(px.right), `right edge ${px.right}`).toBe(true);
      expect(isGreen(px.top), `top edge ${px.top}`).toBe(true);
      expect(isGreen(px.left), `left edge ${px.left}`).toBe(true);
      expect(isGreen(px.middle), `middle ${px.middle}`).toBe(true);
      const isYellow = ([r, g, b]) => r > 170 && g > 150 && b < 100;
      expect(isYellow(px.lower), `lower band ${px.lower}`).toBe(true);
      expect(isGreen(px.upper), `upper area ${px.upper}`).toBe(true);
      files.push(photoFile(storedPhoto(userId)));
    } finally {
      await context.close();
    }
  });

  test('a touch laptop with a desktop browser keeps the inline webcam', async ({ browser }) => {
    const context = await browser.newContext({ hasTouch: true, viewport: { width: 1280, height: 800 } });
    const page = await context.newPage();
    try {
      await login(page);
      await page.goto(`${BASE}/admin/users/edit/${userId}`);
      expect(await page.evaluate(() => matchMedia('(pointer: coarse)').matches)).toBe(true);
      let chooser = false;
      page.on('filechooser', () => { chooser = true; });
      await page.locator('#patron-photo-widget').getByRole('button', { name: 'Scatta foto' }).tap();
      await expect(page.locator('#photo-video')).toBeVisible();
      expect(chooser).toBe(false);
      await page.locator('#photo-camera-cancel').click();
    } finally {
      await context.close();
    }
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

  test('a custom role with users.manage sees the photos of the readers it may edit, not of the others', async ({ page }) => {
    // AdminAuthMiddleware lets this role through to /admin/users/...; the
    // per-reader check of PatronCardsController::canRead() is what refuses
    // a reader the role may not edit (here one that holds a role itself).
    await login(page);
    roleId = Number(dbQuery(`INSERT INTO ruoli (nome, permessi) VALUES (${sqlStr(`Foto ${RUN}`)}, '["backend.access","users.manage"]'); SELECT LAST_INSERT_ID();`));
    const password = `Zph-${RUN}-pw`;
    const hash = execFileSync('php', ['-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', password], { encoding: 'utf-8' });
    const managerEmail = `zph-manager-${RUN.toLowerCase()}@example.invalid`;
    const managerId = Number(dbQuery(`INSERT INTO utenti (nome, cognome, email, password, codice_tessera, stato, tipo_utente, ruolo_id, email_verificata, privacy_accettata)
             VALUES ('Gestore', ${sqlStr(`Foto${RUN}`)}, ${sqlStr(managerEmail)}, ${sqlStr(hash)}, ${sqlStr(`ZPM${RUN}`)}, 'attivo', 'standard', ${roleId}, 1, 1); SELECT LAST_INSERT_ID();`));
    extraUsers.push(managerId);
    const otherId = Number(dbQuery(`INSERT INTO utenti (nome, cognome, email, password, codice_tessera, stato, tipo_utente, ruolo_id, email_verificata)
             VALUES ('Altro', ${sqlStr(`Ruolo${RUN}`)}, ${sqlStr(`zph-other-${RUN.toLowerCase()}@example.invalid`)}, 'x', ${sqlStr(`ZPO${RUN}`)}, 'attivo', 'standard', ${roleId}, 1); SELECT LAST_INSERT_ID();`));
    extraUsers.push(otherId);
    await uploadPhoto(page, otherId, 'zph-other');

    const manager = await page.context().browser().newContext();
    const managerPage = await manager.newPage();
    await login(managerPage, managerEmail, password);
    const allowed = await managerPage.request.get(`${BASE}/admin/users/${userId}/photo`);
    expect(allowed.status()).toBe(200); // a plain reader: the role may edit it
    const refused = await managerPage.request.get(`${BASE}/admin/users/${otherId}/photo`, { maxRedirects: 0 });
    expect(refused.status()).toBe(403); // the reader exists: refused by canRead(), not missing
    await manager.close();
  });

  test('the photo is printed on the library card', async ({ page }) => {
    test.skip(!poppler, 'poppler (pdfimages/pdftotext) not installed');
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
    // Initials placeholder replaced by the photo. The surname and the card code carry
    // this run's random id, which can contain the same two letters.
    expect(text.split(RUN).join('')).not.toContain('FW');
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

  test('deleting a reader without loans removes the account and its photo', async ({ page }) => {
    await login(page);
    const id = Number(dbQuery(`INSERT INTO utenti (nome, cognome, email, password, codice_tessera, stato, tipo_utente, email_verificata)
             VALUES ('Senza', ${sqlStr(`Prestiti${RUN}`)}, ${sqlStr(`zph-noloan-${RUN.toLowerCase()}@example.invalid`)}, 'x', ${sqlStr(`ZPN${RUN}`)}, 'attivo', 'standard', 1); SELECT LAST_INSERT_ID();`));
    extraUsers.push(id);
    const stored = await uploadPhoto(page, id, 'zph-noloan');
    files.push(photoFile(stored));
    expect(dbQuery(`SELECT COUNT(*) FROM prestiti WHERE utente_id = ${id}`)).toBe('0');

    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const res = await page.request.post(`${BASE}/admin/users/delete/${id}`, { form: { csrf_token: String(csrf) }, maxRedirects: 0 });
    expect(res.status()).toBe(302);
    expect(dbQuery(`SELECT COUNT(*) FROM utenti WHERE id = ${id}`)).toBe('0');
    expect(serverFileExists(photoFile(stored))).toBe(false);
    const gone = await page.request.get(`${BASE}/admin/users/${id}/photo`);
    expect(gone.status()).toBe(404);
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
