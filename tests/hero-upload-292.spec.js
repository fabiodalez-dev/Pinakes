// @ts-check
// Issue #292: uploading a hero background image failed under a strict
// `connect-src` CSP because the file-added handler did
// fetch(URL.createObjectURL(file.data)) and blob: is not always allowed.
// The fix uses the Uppy File object directly (no blob: fetch), so the file
// reaches the hidden #hero-background-input regardless of the site's CSP.
//
// This drives the real admin home-editor: logs in, opens /admin/cms/home,
// drops a PNG into the Uppy hero uploader, and asserts the hidden file input
// gets populated with NO "Error converting file" / CSP console error.
//
// Run: /tmp/run-e2e.sh tests/hero-upload-292.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const os = require('os');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'E2E admin credentials not configured');

// A tiny valid PNG (100x40, steel-blue) written to a temp file for the upload.
function writeTestPng() {
  const zlib = require('zlib');
  const w = 100, h = 40;
  const chunk = (type, data) => {
    const tc = Buffer.concat([Buffer.from(type), data]);
    const len = Buffer.alloc(4); len.writeUInt32BE(data.length, 0);
    const crc = Buffer.alloc(4); crc.writeUInt32BE(zlib.crc32 ? zlib.crc32(tc) >>> 0 : require('zlib').crc32(tc) >>> 0, 0);
    return Buffer.concat([len, tc, crc]);
  };
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4);
  ihdr[8] = 8; ihdr[9] = 2; ihdr[10] = 0; ihdr[11] = 0; ihdr[12] = 0;
  const row = Buffer.concat([Buffer.from([0]), Buffer.concat(Array(w).fill(Buffer.from([70, 130, 180])))]);
  const raw = Buffer.concat(Array(h).fill(row));
  const png = Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr),
    chunk('IDAT', zlib.deflateSync(raw)),
    chunk('IEND', Buffer.alloc(0)),
  ]);
  const p = path.join(os.tmpdir(), `test-hero-${Date.now()}.png`);
  fs.writeFileSync(p, png);
  return p;
}

test('#292: hero background upload populates the file input with no blob:/CSP error', async ({ page }) => {
  const consoleErrors = [];
  page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });

  // Login through the real form.
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  // Login redirects away from the auth page (not necessarily to /admin).
  await page.waitForFunction(
    () => !window.location.pathname.includes('accedi') && !window.location.pathname.includes('login'),
    { timeout: 15000 },
  );

  // Open the home-page editor that hosts the Uppy hero uploader.
  await page.goto(`${BASE}/admin/cms/home`);
  await page.waitForLoadState('networkidle');

  // Uppy must be loaded and the hero uploader + hidden input present.
  await expect.poll(() => page.evaluate(() => typeof window.Uppy !== 'undefined'), { timeout: 10000 }).toBe(true);
  await expect(page.locator('#hero-background-input')).toHaveCount(1);

  // Drop the PNG into Uppy's file input (DragDrop renders an <input type=file>).
  const pngPath = writeTestPng();
  const heroInput = page.locator('#uppy-hero-upload input[type="file"]').first();
  await expect(heroInput).toHaveCount(1);
  await heroInput.setInputFiles(pngPath);

  // The fixed file-added handler must populate the hidden #hero-background-input
  // directly from file.data — no fetch of a blob: URL involved.
  await expect.poll(
    () => page.evaluate(() => {
      const inp = /** @type {HTMLInputElement} */ (document.getElementById('hero-background-input'));
      return inp && inp.files ? inp.files.length : 0;
    }),
    { timeout: 8000 },
  ).toBeGreaterThan(0);

  // And the file name/size actually made it in.
  const fileInfo = await page.evaluate(() => {
    const inp = /** @type {HTMLInputElement} */ (document.getElementById('hero-background-input'));
    const f = inp && inp.files && inp.files[0];
    return f ? { name: f.name, size: f.size, type: f.type } : null;
  });
  expect(fileInfo, 'hero file present in the form input').not.toBeNull();
  expect(fileInfo.size).toBeGreaterThan(0);

  // Uppy may load blob URLs internally for previews. The regression contract
  // is that our handler populates the real input without a conversion/CSP error.
  const relevantErrors = consoleErrors.filter((e) => /Error converting file|connect-src|blob:/i.test(e));
  expect(relevantErrors, `console errors: ${consoleErrors.join(' | ')}`).toHaveLength(0);

  fs.unlinkSync(pngPath);
});
