// @ts-check
// Issue #292 — SERVER-SIDE hero background upload, exhaustive coverage.
//
// The earlier "fix" only proved the file reached the FORM (client). It never
// proved the server actually SAVES the image, serves it, and rejects bad input.
// Two real server bugs were hiding behind that gap:
//
//   1. PATH MISMATCH — the file is written under public/uploads/assets/ but the
//      DB stored "/assets/…", which resolves to public/assets/ (a different
//      directory) and 404s. So even a perfectly uploaded photo NEVER rendered.
//      This is the actual bug the reporter hit.
//   2. SILENT UPLOAD FAILURE — a phone photo bigger than PHP's
//      upload_max_filesize makes getError() != UPLOAD_ERR_OK; the old code fell
//      through with no error and the page reported "saved successfully" with no
//      image. The fix surfaces a real error.
//
// This drives real multipart POSTs to /admin/cms/home and, for every case,
// asserts the DB + on-disk file + HTTP-served URL + flash message. The HTTP-200
// check on the stored path is the assertion that would have caught bug #1: a
// file on disk that no URL can reach is worthless to the user.
//
// Run: /tmp/run-e2e.sh tests/hero-upload-server-292.spec.js --config=tests/playwright.config.js --workers=1
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

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME || !INSTALL_ROOT,
  'E2E credentials / install root not configured');

function db(sql) {
  const args = [];
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}
function sqlq(s) { return "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'"; }

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'hero292-'));
const files = {
  jpg: path.join(tmp, 'photo.jpg'),
  png: path.join(tmp, 'photo.png'),
  webp: path.join(tmp, 'photo.webp'),
  big: path.join(tmp, 'big.jpg'),          // 6MB: passes PHP, trips the 5MB app limit
  huge: path.join(tmp, 'huge.jpg'),        // sized in beforeAll: > upload_max_filesize, < post_max_size
  txt: path.join(tmp, 'notes.txt'),        // wrong extension
  gif: path.join(tmp, 'anim.gif'),         // gif is NOT in the whitelist
  svg: path.join(tmp, 'evil.svg'),         // svg is an XSS vector, must be rejected
  fakejpg: path.join(tmp, 'fake.jpg'),     // .jpg extension, text content (MIME mismatch)
};

// PHP has two independent upload limits with DIFFERENT failure modes:
//   - over upload_max_filesize  -> that one file arrives with error=UPLOAD_ERR_INI_SIZE,
//     the rest of $_POST is intact. THIS is what the getError() fix handles.
//   - over post_max_size        -> PHP discards the WHOLE body: $_POST and $_FILES are
//     empty and the CSRF token is gone. A different failure entirely.
// To exercise the INI_SIZE path we need a file that is > upload_max_filesize but
// < post_max_size, which is only possible when upload_max_filesize < post_max_size.
// Read both from the running PHP (CLI shares the app's php.ini here) and skip the
// sub-case with a clear message when the environment can't produce it.
function phpBytes(v) {
  const m = String(v).trim().match(/^(\d+)\s*([KMG]?)$/i);
  if (!m) return parseInt(v, 10) || 0;
  const n = parseInt(m[1], 10);
  return n * ({ '': 1, K: 1024, M: 1024 * 1024, G: 1024 * 1024 * 1024 }[m[2].toUpperCase()]);
}
let iniUploadMax = 0, iniPostMax = 0, iniSizeTestable = false;

let priorRow = null;          // snapshot of the hero background_image for restore
const assetsDir = INSTALL_ROOT ? path.join(INSTALL_ROOT, 'public/uploads/assets') : '';
let createdAssets = [];       // hero_bg_* files already present, so we only clean ours

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

async function csrf(page) {
  await page.goto(`${BASE}/admin/cms/home`);
  await page.waitForLoadState('domcontentloaded');
  return page.locator('input[name="csrf_token"]').first().inputValue();
}

// POST the hero form with an optional file. Returns { status }.
async function postHero(page, { title, subtitle, filePath, mime, removeBg }) {
  const token = await csrf(page);
  const multipart = {
    csrf_token: token,
    'hero[title]': title ?? 'HackEm Books',
    'hero[subtitle]': subtitle ?? 'a public library',
  };
  if (removeBg) multipart['hero[remove_background]'] = '1';
  if (filePath) {
    multipart.hero_background = { name: path.basename(filePath), mimeType: mime || 'application/octet-stream', buffer: fs.readFileSync(filePath) };
  }
  const res = await page.request.post(`${BASE}/admin/cms/home`, { multipart, maxRedirects: 0 });
  return { status: res.status() };
}

function heroBackground() {
  return db("SELECT COALESCE(background_image,'<NULL>') FROM home_content WHERE section_key='hero'");
}
function heroTitleSubtitle() {
  return db("SELECT CONCAT(COALESCE(title,''),'|',COALESCE(subtitle,'')) FROM home_content WHERE section_key='hero'");
}
// Fetch the stored background over HTTP the way a visitor's browser would.
async function httpStatus(page, urlPath) {
  const res = await page.request.get(`${BASE}${urlPath}`, { maxRedirects: 0 });
  return res.status();
}

test.beforeAll(() => {
  // Real raster images via GD so the finfo magic-number check passes.
  execFileSync('php', ['-r',
    `$im=imagecreatetruecolor(8,8);` +
    `imagejpeg($im,${JSON.stringify(files.jpg)});` +
    `imagepng($im,${JSON.stringify(files.png)});` +
    `imagewebp($im,${JSON.stringify(files.webp)});` +
    `imagedestroy($im);`,
  ], { timeout: 15000 });

  const jpegMagic = fs.readFileSync(files.jpg);
  fs.writeFileSync(files.big, Buffer.concat([jpegMagic, Buffer.alloc(6 * 1024 * 1024, 0x20)]));   // >5MB app limit

  // Read PHP's two upload limits and decide whether the INI_SIZE sub-case is
  // producible here. Size `huge` to sit strictly between them when it is.
  const ini = execFileSync('php', ['-r', 'echo ini_get("upload_max_filesize")."|".ini_get("post_max_size");'],
    { encoding: 'utf-8', timeout: 10000 }).trim();
  [iniUploadMax, iniPostMax] = ini.split('|').map(phpBytes);
  iniSizeTestable = iniUploadMax > 0 && iniPostMax > iniUploadMax;
  if (iniSizeTestable) {
    // Strictly > upload_max_filesize and strictly < post_max_size.
    const hugeSize = iniUploadMax + Math.floor((iniPostMax - iniUploadMax) / 2);
    fs.writeFileSync(files.huge, Buffer.concat([jpegMagic, Buffer.alloc(Math.max(hugeSize - jpegMagic.length, 1), 0x20)]));
    console.log(`[#292] INI_SIZE sub-case ENABLED: upload_max=${iniUploadMax}B post_max=${iniPostMax}B, huge=${hugeSize}B`);
  } else {
    console.log(`[#292] INI_SIZE sub-case SKIPPED: upload_max_filesize (${iniUploadMax}B) >= post_max_size (${iniPostMax}B); ` +
      `cannot produce an isolated UPLOAD_ERR_INI_SIZE (a file over post_max_size empties $_POST/$_FILES instead).`);
  }
  fs.writeFileSync(files.txt, 'just some notes, not an image');
  fs.writeFileSync(files.gif, Buffer.from('GIF89a' + '\x01\x00\x01\x00\x00\x00\x00;', 'binary'));
  fs.writeFileSync(files.svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
  fs.writeFileSync(files.fakejpg, 'this is text pretending to be a jpg');

  const exists = db("SELECT EXISTS(SELECT 1 FROM home_content WHERE section_key='hero')") === '1';
  priorRow = exists ? heroBackground() : null;
  createdAssets = assetsDir && fs.existsSync(assetsDir) ? fs.readdirSync(assetsDir) : [];
});

test.afterAll(() => {
  if (priorRow !== null) {
    const val = priorRow === '<NULL>' ? 'NULL' : sqlq(priorRow);
    db(`UPDATE home_content SET background_image=${val} WHERE section_key='hero'`);
  }
  if (assetsDir && fs.existsSync(assetsDir)) {
    for (const f of fs.readdirSync(assetsDir)) {
      if (f.startsWith('hero_bg_') && !createdAssets.includes(f)) {
        try { fs.unlinkSync(path.join(assetsDir, f)); } catch { /* ignore */ }
      }
    }
  }
  try { fs.rmSync(tmp, { recursive: true, force: true }); } catch { /* ignore */ }
});

test('#292 hero upload: saves, serves, renders, and rejects correctly', async ({ page }) => {
  await login(page);

  // --- 1) Valid JPEG: saved to the CORRECT path, on disk, AND served over HTTP.
  {
    const { status } = await postHero(page, { title: 'HackEm Books', subtitle: 'a public library', filePath: files.jpg, mime: 'image/jpeg' });
    expect(status, 'valid upload redirects (303)').toBe(303);
    const bg = heroBackground();
    // The regression guard: the stored path MUST be under /uploads/assets/, the
    // only place the file is actually served from. A "/assets/…" path is the bug.
    expect(bg, 'DB path is under /uploads/assets/').toMatch(/^\/uploads\/assets\/hero_bg_[0-9a-f]+\.jpg$/);
    expect(fs.existsSync(path.join(INSTALL_ROOT, 'public', bg)), 'file exists on disk').toBe(true);
    expect(await httpStatus(page, bg), 'stored URL is reachable (200) — the check that catches the path bug').toBe(200);
    // title/subtitle persisted alongside.
    expect(heroTitleSubtitle()).toBe('HackEm Books|a public library');
  }

  // --- 2) The frontend home actually references AND can load that background.
  {
    const bg = heroBackground();
    await page.goto(`${BASE}/`);
    await page.waitForLoadState('domcontentloaded');
    const html = await page.content();
    expect(html, 'home references the uploaded hero background').toContain(bg);
    expect(await httpStatus(page, bg), 'the referenced background loads (200)').toBe(200);
  }

  // --- 3) Valid PNG replaces it: new path, on disk, served.
  {
    const before = heroBackground();
    const { status } = await postHero(page, { filePath: files.png, mime: 'image/png' });
    expect(status).toBe(303);
    const bg = heroBackground();
    expect(bg).toMatch(/^\/uploads\/assets\/hero_bg_[0-9a-f]+\.png$/);
    expect(bg, 'background changed').not.toBe(before);
    expect(await httpStatus(page, bg), 'new PNG served (200)').toBe(200);
  }

  // --- 4) Valid WebP is accepted (whitelist includes webp).
  {
    const { status } = await postHero(page, { filePath: files.webp, mime: 'image/webp' });
    expect(status).toBe(303);
    const bg = heroBackground();
    expect(bg).toMatch(/^\/uploads\/assets\/hero_bg_[0-9a-f]+\.webp$/);
    expect(await httpStatus(page, bg), 'webp served (200)').toBe(200);
  }

  // --- 5) Oversized (6MB > 5MB app limit): error surfaced, background UNCHANGED.
  {
    const before = heroBackground();
    const { status } = await postHero(page, { filePath: files.big, mime: 'image/jpeg' });
    expect(status).toBe(303);
    expect(heroBackground(), 'oversized upload must not change the background').toBe(before);
    await page.goto(`${BASE}/admin/cms/home`);
    await expect(page.locator('[role="alert"]')).toContainText(/troppo grande|Max|supera il limite/i);
  }

  // --- 6) Over upload_max_filesize (but under post_max_size): the SILENT-FAILURE
  //        fix. PHP hands the file to the app with error=UPLOAD_ERR_INI_SIZE; the
  //        old code only handled UPLOAD_ERR_OK, so $errors stayed empty and the
  //        page reported success with no image. Now it must be a visible error and
  //        the background must be untouched. Only runs where the environment can
  //        produce an isolated INI_SIZE (upload_max_filesize < post_max_size).
  if (iniSizeTestable) {
    const before = heroBackground();
    const { status } = await postHero(page, { filePath: files.huge, mime: 'image/jpeg' });
    expect(status).toBe(303);
    expect(heroBackground(), 'over-upload_max_filesize upload must not change the background').toBe(before);
    await page.goto(`${BASE}/admin/cms/home`);
    await expect(page.locator('[role="alert"]'),
      'a file beyond upload_max_filesize must produce a visible error, not a silent success')
      .toContainText(/supera il limite|troppo grande|interrotto|Max/i);
  } else {
    console.log('[#292] skipping INI_SIZE assertion (see beforeAll message).');
  }

  // --- 7) Wrong extension (.txt): rejected, background unchanged.
  {
    const before = heroBackground();
    const { status } = await postHero(page, { filePath: files.txt, mime: 'text/plain' });
    expect(status).toBe(303);
    expect(heroBackground()).toBe(before);
    await page.goto(`${BASE}/admin/cms/home`);
    await expect(page.locator('[role="alert"]')).toContainText(/Formato immagine non supportato|JPG, PNG/i);
  }

  // --- 8) .gif is not in the whitelist: rejected, background unchanged.
  {
    const before = heroBackground();
    const { status } = await postHero(page, { filePath: files.gif, mime: 'image/gif' });
    expect(status).toBe(303);
    expect(heroBackground(), 'gif is not an allowed hero format').toBe(before);
    await page.goto(`${BASE}/admin/cms/home`);
    await expect(page.locator('[role="alert"]')).toContainText(/Formato immagine non supportato|JPG, PNG/i);
  }

  // --- 9) .svg (XSS vector): rejected, background unchanged.
  {
    const before = heroBackground();
    const { status } = await postHero(page, { filePath: files.svg, mime: 'image/svg+xml' });
    expect(status).toBe(303);
    expect(heroBackground(), 'svg must never be accepted as a hero image').toBe(before);
    await page.goto(`${BASE}/admin/cms/home`);
    await expect(page.locator('[role="alert"]')).toContainText(/Formato immagine non supportato|JPG, PNG/i);
  }

  // --- 10) MIME mismatch (.jpg extension, text content): rejected on magic-number.
  {
    const before = heroBackground();
    const { status } = await postHero(page, { filePath: files.fakejpg, mime: 'image/jpeg' });
    expect(status).toBe(303);
    expect(heroBackground()).toBe(before);
    await page.goto(`${BASE}/admin/cms/home`);
    await expect(page.locator('[role="alert"]')).toContainText(/immagine reale|Tipo di file non valido/i);
  }

  // --- 11) A text-only save (no file) must KEEP the existing background.
  {
    const before = heroBackground();
    expect(before, 'a background is set from the earlier cases').not.toBe('<NULL>');
    const { status } = await postHero(page, { title: 'HackEm Books', subtitle: 'edited, no new image' });
    expect(status).toBe(303);
    expect(heroBackground(), 'text-only save keeps the background').toBe(before);
    expect(heroTitleSubtitle()).toBe('HackEm Books|edited, no new image');
    expect(await httpStatus(page, heroBackground()), 'kept background still serves (200)').toBe(200);
  }

  // --- 12) remove_background=1 clears it.
  {
    const { status } = await postHero(page, { removeBg: true });
    expect(status).toBe(303);
    expect(heroBackground(), 'remove_background clears it').toBe('<NULL>');
  }
});
