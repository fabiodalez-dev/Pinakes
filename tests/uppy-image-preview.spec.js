// @ts-check
// Native Uppy image preview across EVERY image uploader in the app.
//
// Uppy's Dashboard shows a thumbnail on its own; DragDrop does not. The image
// uploaders here all use DragDrop, so #292's "I picked a file and nothing
// happened" was partly the missing preview. This drives a REAL browser (loads
// the bundle, feeds a real JPEG into each Uppy's file input) and asserts a
// visible preview <img> appears with a blob:/data: source — the ThumbnailGenerator
// output — for every uploader, one test each.
//
// Run: /tmp/run-e2e.sh tests/uppy-image-preview.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

const INSTALL_ROOT = process.env.E2E_INSTALL_ROOT || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME, 'E2E credentials not configured');

function db(sql) {
  const args = [];
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}
function sqlq(s) { return "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'"; }

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'uppyprev-'));
const JPG = path.join(tmp, 'preview.jpg');

test.beforeAll(() => {
  execFileSync('php', ['-r', `$im=imagecreatetruecolor(64,64);imagefilledrectangle($im,0,0,64,64,imagecolorallocate($im,200,80,40));imagejpeg($im,${JSON.stringify(JPG)});imagedestroy($im);`], { timeout: 15000 });
});
test.afterAll(() => { try { fs.rmSync(tmp, { recursive: true, force: true }); } catch { /* ignore */ } });

async function login(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForFunction(
    () => !location.pathname.includes('accedi') && !location.pathname.includes('login'),
    { timeout: 15000 },
  );
}

// Feed a real JPEG into the file input Uppy's DragDrop renders inside `mountSel`,
// then assert BOTH:
//   (a) the preview <img> at `previewImgSel` gets a browser-generated blob:/data: src, and
//   (b) `hiddenInputSel` — the real <input type=file> the form submits — is now
//       populated. (b) is what proves the file will actually be saved: it's the
//       Uppy→hidden-input transfer #292 broke. `requireVisible` is false for
//       uploaders inside a hidden tab (the img has a src, just not on screen).
async function expectPreview(page, mountSel, previewImgSel, hiddenInputSel, requireVisible = true) {
  const dragInput = page.locator(`${mountSel} input[type="file"]`).first();
  await expect(dragInput, `Uppy DragDrop file input exists under ${mountSel}`).toHaveCount(1);
  await dragInput.setInputFiles(JPG);

  const img = page.locator(previewImgSel).first();
  await expect(async () => {
    const src = await img.getAttribute('src');
    expect(src, `preview ${previewImgSel} has a blob:/data: src`).toMatch(/^(blob:|data:image\/)/);
  }).toPass({ timeout: 10000 });
  if (requireVisible) {
    await expect(img, `preview image ${previewImgSel} is visible`).toBeVisible();
  }

  // The file must reach the hidden input the <form> serialises, or the save is lost.
  await expect(async () => {
    const count = await page.locator(hiddenInputSel).evaluate((el) => el.files ? el.files.length : 0);
    expect(count, `hidden input ${hiddenInputSel} received the file (save prerequisite)`).toBe(1);
  }).toPass({ timeout: 10000 });
}

test.describe.serial('native Uppy image preview', () => {
  test('hero background (cms/edit-home)', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/cms/home`);
    await page.waitForLoadState('networkidle');
    await expectPreview(page, '#uppy-hero-upload', '#hero-preview-img', '#hero-background-input');
  });

  test('book cover (libri/book_form)', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/books/create`);
    await page.waitForLoadState('networkidle');
    await expectPreview(page, '#uppy-upload', '#cover-preview-container img', '#fallback-file-input');
  });

  test('author photo (autori/crea_autore)', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/authors/create`);
    await page.waitForLoadState('networkidle');
    await expectPreview(page, '#author-uppy-upload', '#author-photo-preview img', '#author-fallback-file-input');
  });

  test('event image (events/form)', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/cms/events/create`);
    await page.waitForLoadState('networkidle');
    await expectPreview(page, '#uppy-event-upload', '#event-image-preview-img', '#event-image-input');
  });

  test('site logo (settings) — svg-safe object-URL preview', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/settings`);
    await page.waitForLoadState('networkidle');
    // The logo uploader may sit in a non-active settings tab; the <img> still
    // gets its src, so check the src rather than on-screen visibility.
    await expectPreview(page, '#uppy-logo-upload', '#logo-preview-image', '#logo-file-input', false);
  });

  // The full #292 loop, through the real UI: pick a file in Uppy, submit the
  // actual form, and prove the image is persisted under /uploads/assets AND
  // served over HTTP. This is the end-to-end the reporter's flow exercises.
  test('hero end-to-end: pick in Uppy → submit → saved under /uploads/assets and served (200)', async ({ page }) => {
    const priorRow = db("SELECT EXISTS(SELECT 1 FROM home_content WHERE section_key='hero')") === '1'
      ? db("SELECT COALESCE(background_image,'<NULL>') FROM home_content WHERE section_key='hero'")
      : null;
    const assetsDir = INSTALL_ROOT ? path.join(INSTALL_ROOT, 'public/uploads/assets') : '';
    const preexisting = assetsDir && fs.existsSync(assetsDir) ? fs.readdirSync(assetsDir) : [];

    try {
      await login(page);
      await page.goto(`${BASE}/admin/cms/home`);
      await page.waitForLoadState('networkidle');

      await page.locator('#uppy-hero-upload input[type="file"]').first().setInputFiles(JPG);
      await expect(async () => {
        const n = await page.locator('#hero-background-input').evaluate((el) => el.files ? el.files.length : 0);
        expect(n).toBe(1);
      }).toPass({ timeout: 10000 });

      await Promise.all([
        page.waitForURL(/\/admin\/cms\/home/, { timeout: 15000 }),
        page.locator('form[action$="/admin/cms/home"] button[type="submit"]').first().click(),
      ]);

      const bg = db("SELECT COALESCE(background_image,'<NULL>') FROM home_content WHERE section_key='hero'");
      expect(bg, 'hero background saved under /uploads/assets/').toMatch(/^\/uploads\/assets\/hero_bg_[0-9a-f]+\.jpg$/);
      const res = await page.request.get(`${BASE}${bg}`, { maxRedirects: 0 });
      expect(res.status(), 'saved hero image is served over HTTP (200)').toBe(200);
      if (INSTALL_ROOT) {
        expect(fs.existsSync(path.join(INSTALL_ROOT, 'public', bg)), 'file exists on disk').toBe(true);
      }
    } finally {
      if (priorRow !== null) {
        const val = priorRow === '<NULL>' ? 'NULL' : sqlq(priorRow);
        db(`UPDATE home_content SET background_image=${val} WHERE section_key='hero'`);
      }
      if (assetsDir && fs.existsSync(assetsDir)) {
        for (const f of fs.readdirSync(assetsDir)) {
          if (f.startsWith('hero_bg_') && !preexisting.includes(f)) {
            try { fs.unlinkSync(path.join(assetsDir, f)); } catch { /* ignore */ }
          }
        }
      }
    }
  });
});
