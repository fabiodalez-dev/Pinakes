// @ts-check
const { test, expect } = require('@playwright/test');

const BASE = process.env.BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';

test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS,
  'E2E credentials not configured (set E2E_ADMIN_EMAIL, E2E_ADMIN_PASS)',
);

/**
 * Social Sharing E2E Tests
 *
 * Tests the sharing feature end-to-end:
 * 1. Admin settings: sharing tab, toggle providers, save
 * 2. Frontend: verify share buttons render on book detail
 * 3. OG meta tags on book detail page
 */

test.describe.serial('Social Sharing', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    // Login as admin
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/admin/, { timeout: 15000 });
  });

  test.afterAll(async () => {
    await context?.close();
  });

  test('1. Settings: sharing tab loads with provider checkboxes', async () => {
    await page.goto(`${BASE}/admin/settings?tab=sharing`);
    await page.waitForLoadState('networkidle');

    // The sharing tab panel should be visible
    const sharingPanel = page.locator('[data-settings-panel="sharing"]');
    await expect(sharingPanel).toBeVisible();

    // Should have provider checkboxes
    const checkboxes = sharingPanel.locator('input[type="checkbox"][name="sharing_providers[]"]');
    const count = await checkboxes.count();
    expect(count).toBeGreaterThanOrEqual(10); // We have 16 providers

    // Facebook should be present
    await expect(sharingPanel.locator('input[value="facebook"]')).toBeVisible();

    // WhatsApp should be present
    await expect(sharingPanel.locator('input[value="whatsapp"]')).toBeVisible();

    // Threads should be present (new provider)
    await expect(sharingPanel.locator('input[value="threads"]')).toBeVisible();

    // Bluesky should be present (new provider)
    await expect(sharingPanel.locator('input[value="bluesky"]')).toBeVisible();
  });

  test('2. Settings: save sharing providers', async () => {
    await page.goto(`${BASE}/admin/settings?tab=sharing`);
    await page.waitForLoadState('networkidle');

    const panel = page.locator('[data-settings-panel="sharing"]');

    // Uncheck all first
    const allCheckboxes = panel.locator('input[type="checkbox"][name="sharing_providers[]"]');
    const count = await allCheckboxes.count();
    for (let i = 0; i < count; i++) {
      if (await allCheckboxes.nth(i).isChecked()) {
        await allCheckboxes.nth(i).uncheck();
      }
    }

    // Enable specific providers: facebook, x, whatsapp, telegram, email
    for (const slug of ['facebook', 'x', 'whatsapp', 'telegram', 'email']) {
      await panel.locator(`input[value="${slug}"]`).check();
    }

    // Save
    await panel.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // Should redirect back with success
    expect(page.url()).toContain('tab=sharing');

    // Verify the saved state: checked providers should remain checked
    await page.goto(`${BASE}/admin/settings?tab=sharing`);
    await page.waitForLoadState('networkidle');

    const panelAfter = page.locator('[data-settings-panel="sharing"]');
    await expect(panelAfter.locator('input[value="facebook"]')).toBeChecked();
    await expect(panelAfter.locator('input[value="x"]')).toBeChecked();
    await expect(panelAfter.locator('input[value="whatsapp"]')).toBeChecked();
    await expect(panelAfter.locator('input[value="telegram"]')).toBeChecked();
    await expect(panelAfter.locator('input[value="email"]')).toBeChecked();

    // linkedin should NOT be checked
    await expect(panelAfter.locator('input[value="linkedin"]')).not.toBeChecked();
  });

  test('3. Settings: preview updates live', async () => {
    await page.goto(`${BASE}/admin/settings?tab=sharing`);
    await page.waitForLoadState('networkidle');

    const panel = page.locator('[data-settings-panel="sharing"]');

    // The preview area should exist
    const preview = panel.locator('#sharing-preview');
    await expect(preview).toBeVisible();

    // Preview should show icons for checked providers
    const fbIcon = preview.locator('.fab.fa-facebook-f, .fa-facebook-f');
    await expect(fbIcon).toBeVisible();
  });

  test('4. Frontend: share buttons appear on book detail page', async () => {
    // Find a book to check — go to catalogue and pick the first one
    await page.goto(`${BASE}/catalogo`);
    await page.waitForLoadState('networkidle');

    // Click first book link
    const bookLink = page.locator('.book-card a, .card a').filter({ hasText: /.+/ }).first();
    await bookLink.click();
    await page.waitForLoadState('networkidle');

    // Share card should be visible
    const shareCard = page.locator('#book-share-card');
    await expect(shareCard).toBeVisible();

    // Should have share buttons for our enabled providers
    const shareButtons = shareCard.locator('.social-share-btn');
    const btnCount = await shareButtons.count();
    expect(btnCount).toBeGreaterThanOrEqual(5); // 5 providers + possibly Web Share

    // Facebook share button
    await expect(shareCard.locator('.fa-facebook-f').first()).toBeVisible();

    // X/Twitter share button
    await expect(shareCard.locator('.fa-x-twitter').first()).toBeVisible();

    // WhatsApp share button
    await expect(shareCard.locator('.fa-whatsapp').first()).toBeVisible();

    // Telegram share button
    await expect(shareCard.locator('.fa-telegram').first()).toBeVisible();

    // Email share button
    await expect(shareCard.locator('.fa-envelope').first()).toBeVisible();

    // LinkedIn should NOT appear (we unchecked it)
    await expect(shareCard.locator('.fa-linkedin-in')).toHaveCount(0);
  });

  test('5. Frontend: share links have correct URLs', async () => {
    // We should still be on a book detail page from previous test
    const shareCard = page.locator('#book-share-card');

    // Facebook link should point to facebook sharer
    const fbLink = shareCard.locator('a').filter({ has: page.locator('.fa-facebook-f') });
    const fbHref = await fbLink.getAttribute('href');
    expect(fbHref).toContain('facebook.com/sharer');

    // X link should point to twitter intent
    const xLink = shareCard.locator('a').filter({ has: page.locator('.fa-x-twitter') });
    const xHref = await xLink.getAttribute('href');
    expect(xHref).toContain('twitter.com/intent/tweet');

    // WhatsApp link
    const waLink = shareCard.locator('a').filter({ has: page.locator('.fa-whatsapp') });
    const waHref = await waLink.getAttribute('href');
    expect(waHref).toContain('wa.me');

    // Telegram link
    const tgLink = shareCard.locator('a').filter({ has: page.locator('.fa-telegram') });
    const tgHref = await tgLink.getAttribute('href');
    expect(tgHref).toContain('t.me/share');

    // Email link should be mailto:
    const emailLink = shareCard.locator('a').filter({ has: page.locator('.fa-envelope') });
    const emailHref = await emailLink.getAttribute('href');
    expect(emailHref).toContain('mailto:');

    // All external links should have target="_blank" and rel="noopener noreferrer"
    const externalLinks = shareCard.locator('a[target="_blank"]');
    const extCount = await externalLinks.count();
    for (let i = 0; i < extCount; i++) {
      const rel = await externalLinks.nth(i).getAttribute('rel');
      expect(rel).toContain('noopener');
    }
  });

  test('6. Frontend: OG meta tags present on book page', async () => {
    // Still on book detail page
    const ogTitle = await page.locator('meta[property="og:title"]').getAttribute('content');
    expect(ogTitle).toBeTruthy();
    expect(ogTitle.length).toBeGreaterThan(0);

    const ogUrl = await page.locator('meta[property="og:url"]').getAttribute('content');
    expect(ogUrl).toContain('http');

    const ogType = await page.locator('meta[property="og:type"]').getAttribute('content');
    expect(ogType).toBe('book');

    const ogImage = await page.locator('meta[property="og:image"]').getAttribute('content');
    expect(ogImage).toBeTruthy();

    // Twitter card
    const twitterCard = await page.locator('meta[name="twitter:card"]').getAttribute('content');
    expect(twitterCard).toBe('summary_large_image');

    const twitterTitle = await page.locator('meta[name="twitter:title"]').getAttribute('content');
    expect(twitterTitle).toBeTruthy();
  });

  test('7. Settings: add more providers and verify frontend', async () => {
    // Go back to settings and add threads + bluesky
    await page.goto(`${BASE}/admin/settings?tab=sharing`);
    await page.waitForLoadState('networkidle');

    const panel = page.locator('[data-settings-panel="sharing"]');
    await panel.locator('input[value="threads"]').check();
    await panel.locator('input[value="bluesky"]').check();
    await panel.locator('input[value="copylink"]').check();

    await panel.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // Go to a book page and verify new providers appear
    await page.goto(`${BASE}/catalogo`);
    await page.waitForLoadState('networkidle');
    await page.locator('.book-card a, .card a').filter({ hasText: /.+/ }).first().click();
    await page.waitForLoadState('networkidle');

    const shareCard = page.locator('#book-share-card');

    // Threads icon
    await expect(shareCard.locator('.fa-threads')).toBeVisible();

    // Bluesky icon
    await expect(shareCard.locator('.fa-bluesky')).toBeVisible();

    // Copy link button
    await expect(shareCard.locator('[data-share-copy]')).toBeVisible();

    // Threads link should point to threads.com (not threads.net)
    const threadsLink = shareCard.locator('a').filter({ has: page.locator('.fa-threads') });
    const threadsHref = await threadsLink.getAttribute('href');
    expect(threadsHref).toContain('threads.com');

    // Bluesky link should point to bsky.app
    const bskyLink = shareCard.locator('a').filter({ has: page.locator('.fa-bluesky') });
    const bskyHref = await bskyLink.getAttribute('href');
    expect(bskyHref).toContain('bsky.app');
  });

  test('8. Settings: disable all providers hides share card', async () => {
    // Uncheck all providers
    await page.goto(`${BASE}/admin/settings?tab=sharing`);
    await page.waitForLoadState('networkidle');

    const panel = page.locator('[data-settings-panel="sharing"]');
    const allCheckboxes = panel.locator('input[type="checkbox"][name="sharing_providers[]"]');
    const count = await allCheckboxes.count();
    for (let i = 0; i < count; i++) {
      if (await allCheckboxes.nth(i).isChecked()) {
        await allCheckboxes.nth(i).uncheck();
      }
    }
    await panel.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // Go to book page — share card should NOT be visible
    await page.goto(`${BASE}/catalogo`);
    await page.waitForLoadState('networkidle');
    await page.locator('.book-card a, .card a').filter({ hasText: /.+/ }).first().click();
    await page.waitForLoadState('networkidle');

    const shareCard = page.locator('#book-share-card');
    await expect(shareCard).toHaveCount(0);
  });

  test('9. Cleanup: restore default providers', async () => {
    // Re-enable default providers
    await page.goto(`${BASE}/admin/settings?tab=sharing`);
    await page.waitForLoadState('networkidle');

    const panel = page.locator('[data-settings-panel="sharing"]');
    for (const slug of ['facebook', 'x', 'whatsapp', 'email']) {
      await panel.locator(`input[value="${slug}"]`).check();
    }
    await panel.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // Verify restored
    await page.goto(`${BASE}/admin/settings?tab=sharing`);
    await page.waitForLoadState('networkidle');
    const panelAfter = page.locator('[data-settings-panel="sharing"]');
    await expect(panelAfter.locator('input[value="facebook"]')).toBeChecked();
    await expect(panelAfter.locator('input[value="x"]')).toBeChecked();
    await expect(panelAfter.locator('input[value="whatsapp"]')).toBeChecked();
    await expect(panelAfter.locator('input[value="email"]')).toBeChecked();
  });
});
