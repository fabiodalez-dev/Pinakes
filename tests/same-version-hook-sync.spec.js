// @ts-check
/**
 * Regression test for the same-version hook re-sync edge case.
 *
 * Scenario: a bundled plugin is at disk_version == db_version but plugin_hooks
 * is empty (simulating a same-version re-deploy that added a new hook which was
 * never registered because autoRegisterBundledPlugins skipped onActivate).
 *
 * The fix (PluginManager elseif branch) must call onActivate when:
 *   disk_version === db_version AND is_active === 1
 *
 * This test just triggers the code path by navigating to /admin/plugins.
 * The reinstall-test.sh step verifies hook count before/after via MySQL.
 *
 * Requires env vars: E2E_BASE_URL, E2E_ADMIN_EMAIL, E2E_ADMIN_PASS
 */

const { test, expect } = require('@playwright/test');

const BASE  = process.env.E2E_BASE_URL;
const EMAIL = process.env.E2E_ADMIN_EMAIL;
const PASS  = process.env.E2E_ADMIN_PASS;

test.skip(!BASE || !EMAIL || !PASS, 'Set E2E_BASE_URL, E2E_ADMIN_EMAIL, E2E_ADMIN_PASS');

test('visit /admin/plugins to trigger autoRegisterBundledPlugins hook re-sync', async ({ page }) => {
    await page.goto(BASE + '/accedi', { waitUntil: 'domcontentloaded' });

    if (page.url().includes('/installer/')) {
        throw new Error('App not installed — run installer first');
    }

    await page.getByRole('textbox', { name: /email/i }).fill(EMAIL);
    await page.getByRole('textbox', { name: /password/i }).fill(PASS);
    await page.getByRole('button', { name: /accedi/i }).click();
    await page.waitForURL(/admin\/dashboard/, { timeout: 20_000 });

    // This page load calls getAllPlugins() → autoRegisterBundledPlugins()
    // which must execute the elseif(diskVersion === dbVersion && is_active) branch
    // and call onActivate() to restore the missing hooks.
    await page.goto(BASE + '/admin/plugins', { waitUntil: 'domcontentloaded' });

    // Verify the page loaded correctly (not a 500 error)
    await expect(page.locator('body')).not.toContainText('500');
    await expect(page.locator('body')).not.toContainText('Fatal error');
    const title = await page.title();
    expect(title).not.toBe('404 Not Found');
});
