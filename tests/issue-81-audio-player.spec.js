// @ts-check
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const DB_HOST   = process.env.E2E_DB_HOST   || '';
const DB_USER   = process.env.E2E_DB_USER   || '';
const DB_PASS   = process.env.E2E_DB_PASS   || '';
const DB_NAME   = process.env.E2E_DB_NAME   || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME, 'E2E credentials not configured');

function dbQuery(sql) {
  const args = ['-N', '-B', '-e', sql];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER);
  if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
  args.push(DB_NAME);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000 }).trim();
}

test.describe.serial('Issue #81: Audiobook MP3 Player', () => {
  let context;
  let page;
  let testBookId = 0;
  let originalAudioUrl = null;
  const FAKE_AUDIO_URL = '/uploads/test-audio.mp3';

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();

    // Find a book and preserve its original audio_url
    const row = dbQuery("SELECT id, (audio_url IS NULL) AS audio_is_null, COALESCE(audio_url, '') AS audio_url FROM libri WHERE deleted_at IS NULL LIMIT 1");
    if (row) {
      const [idRaw, isNullRaw, audioUrlRaw = ''] = row.split('\t');
      testBookId = Number(idRaw);
      originalAudioUrl = isNullRaw === '1' ? null : audioUrlRaw;
    }
    if (testBookId) {
      dbQuery(`UPDATE libri SET audio_url='${FAKE_AUDIO_URL}' WHERE id=${testBookId}`);
    }
  });

  test.afterAll(async () => {
    // Restore original audio_url
    if (testBookId) {
      const restored = originalAudioUrl === null
        ? 'NULL'
        : `'${String(originalAudioUrl).replace(/'/g, "''")}'`;
      dbQuery(`UPDATE libri SET audio_url=${restored} WHERE id=${testBookId}`);
    }
    await context?.close();
  });

  test('Frontend: Green Audio Player CSS/JS is loaded on book detail', async () => {
    test.skip(!testBookId, 'No test book available');

    await page.goto(`${BASE}/libro/${testBookId}`);
    await page.waitForLoadState('networkidle');

    // Check that do_action('assets.head') loaded the GAP assets
    const gapCss = page.locator('link[href*="green-audio-player"]');
    const gapJs = page.locator('script[src*="green-audio-player"]');

    await expect(gapCss).toHaveCount(1);
    await expect(gapJs).toHaveCount(1);
  });

  test('Frontend: Audio player button and container render', async () => {
    test.skip(!testBookId, 'No test book available');

    await page.goto(`${BASE}/libro/${testBookId}`);
    await page.waitForLoadState('networkidle');

    // The toggle button should be visible
    const toggleBtn = page.locator('#btn-toggle-audiobook');
    await expect(toggleBtn).toBeVisible();

    // Player container should be hidden initially
    const playerContainer = page.locator('#audiobook-player-container');
    await expect(playerContainer).toBeHidden();

    // Click the button to show the player
    await toggleBtn.click();

    // Player container should now be visible
    await expect(playerContainer).toBeVisible();

    // The player should contain an audio element
    const audioEl = playerContainer.locator('audio');
    await expect(audioEl).toHaveCount(1);

    // Check no JS errors related to GreenAudioPlayer
    const consoleMessages = [];
    page.on('console', msg => consoleMessages.push(msg.text()));

    // The player-digital-library div should contain the GAP-rendered player
    // (or at least native controls as fallback)
    const playerDiv = playerContainer.locator('.player-digital-library');
    await expect(playerDiv).toBeVisible();
  });

  test('Admin: Audio toggle button reveals inline player', async () => {
    test.skip(!testBookId, 'No test book available');

    // Login as admin
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/admin/, { timeout: 15000 });

    // Navigate to the book detail in admin
    await page.goto(`${BASE}/admin/libri/${testBookId}`);
    await page.waitForLoadState('networkidle');

    // No target="_blank" audio link
    const audioTargetBlank = page.locator('a[href*="audio"][target="_blank"], a[href*=".mp3"][target="_blank"]');
    await expect(audioTargetBlank).toHaveCount(0);

    // Toggle button should be visible, player hidden initially
    const toggleBtn = page.locator('#btn-admin-audio-toggle');
    await expect(toggleBtn).toBeVisible();

    const playerDiv = page.locator('#admin-audio-player');
    await expect(playerDiv).toBeHidden();

    // Click to reveal inline player
    await toggleBtn.click();
    await expect(playerDiv).toBeVisible();

    // Player should contain an audio element
    const audioEl = playerDiv.locator('audio');
    await expect(audioEl).toHaveCount(1);

    // Click again to hide
    await toggleBtn.click();
    await expect(playerDiv).toBeHidden();
  });
});
