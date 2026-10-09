// @ts-check
// An uploaded image must be SERVED, not only saved (#292's class of bug: the
// file lands under one path, the stored URL points to another). This drives
// the real admin create-book endpoint with a multipart body (the same request
// the form sends), reads the URL the app stored, and asserts the public URL
// answers 200 with an image. The hero-photo upload tests that covered this
// path were retired with the 2026 design; this keeps the invariant.
//
// Run: /tmp/run-e2e.sh tests/upload-served-url.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME, 'E2E admin and database credentials required');

function dbQuery(sql) {
  const args = ['--default-character-set=utf8mb4', '-N', '-B', '-e', sql];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_PORT) args.push('-P', DB_PORT);
  if (!DB_HOST && DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 15000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}

const RUN = Date.now().toString(36);
const TITLE = `Upload served ${RUN}`;

// A real 64x64 JPEG, made by PHP's GD so the server-side signature check passes.
function makeJpeg() {
  const b64 = execFileSync('php', ['-r', '$im=imagecreatetruecolor(64,64);imagefilledrectangle($im,0,0,64,64,imagecolorallocate($im,40,80,200));ob_start();imagejpeg($im);echo base64_encode(ob_get_clean());'], { encoding: 'utf-8', timeout: 15000 });
  return Buffer.from(b64.trim(), 'base64');
}

async function login(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForFunction(() => !location.pathname.includes('accedi') && !location.pathname.includes('login'), null, { timeout: 15000 });
}

test.describe.serial('uploaded image is served from the stored URL', () => {
  let bookId = 0;

  test.afterAll(() => {
    if (bookId > 0) {
      try { dbQuery(`DELETE FROM libri WHERE id = ${bookId}`); } catch { /* best effort */ }
    }
  });

  test('book cover: create with a file, the stored copertina_url answers 200 image/*', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/books/create`);
    const csrf = await page.locator('input[name="csrf_token"]').first().inputValue();
    expect(csrf.length).toBeGreaterThan(10);

    // page.request shares the logged-in session cookies.
    const res = await page.request.post(`${BASE}/admin/books/create`, {
      multipart: {
        csrf_token: csrf,
        titolo: TITLE,
        copie_totali: '1',
        copertina: { name: `cover-${RUN}.jpg`, mimeType: 'image/jpeg', buffer: makeJpeg() },
      },
      maxRedirects: 0,
    });
    expect([200, 302, 303]).toContain(res.status());

    const row = dbQuery(`SELECT id, IFNULL(copertina_url, '') FROM libri WHERE titolo = '${TITLE}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`);
    expect(row, 'the book was created').not.toBe('');
    const [idText, coverUrl] = row.split('\t');
    bookId = parseInt(idText, 10);
    expect(bookId).toBeGreaterThan(0);
    expect(coverUrl, 'a cover URL was stored').toMatch(/^\/uploads\/copertine\/.+\.(jpe?g|png|webp|gif)$/i);

    const img = await page.request.get(`${BASE}${coverUrl}`, { maxRedirects: 0 });
    expect(img.status(), `GET ${coverUrl} is served`).toBe(200);
    expect(img.headers()['content-type'] || '', 'served as an image').toMatch(/^image\//);
    expect((await img.body()).length).toBeGreaterThan(100);

    // And the public book page points at the same, served file.
    await page.goto(`${BASE}/libro/${bookId}`);
    const shown = await page.locator('#book-cover-image, img.book-cover-large').first().getAttribute('src');
    expect(shown || '').toContain(coverUrl.split('/').pop());
  });
});
