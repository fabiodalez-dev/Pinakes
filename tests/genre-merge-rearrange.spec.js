// @ts-check
const { test, expect } = require('@playwright/test');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';

// Skip all tests when credentials are not configured
test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'E2E admin credentials not configured (set E2E_ADMIN_EMAIL and E2E_ADMIN_PASS)');

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const emailField = page.locator('input[name="email"]');
  if (await emailField.isVisible({ timeout: 2000 }).catch(() => false)) {
    await emailField.fill(ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/.*(?:dashboard|admin).*/);
  }
}

// Unique suffix per run to avoid stale data collisions
const RUN_ID = Date.now().toString(36);

// ────────────────────────────────────────────────────────────────────────
// Genre Merge + Rearrange (Issue #64 continuation)
// ────────────────────────────────────────────────────────────────────────
test.describe('Genre Merge & Rearrange', () => {

  test('rearrange: move a child genre to a different parent', async ({ page }) => {
    await loginAsAdmin(page);

    // Create two test parent genres (unique names per run)
    await page.goto(`${BASE}/admin/generi/crea`);
    await page.fill('input[name="nome"]', `ParentA_${RUN_ID}`);
    await page.click('button[type="submit"]');
    await page.waitForURL(/.*generi\/\d+.*/);
    const parentAId = page.url().match(/generi\/(\d+)/)[1];

    await page.goto(`${BASE}/admin/generi/crea`);
    await page.fill('input[name="nome"]', `ParentB_${RUN_ID}`);
    await page.click('button[type="submit"]');
    await page.waitForURL(/.*generi\/\d+.*/);
    const parentBId = page.url().match(/generi\/(\d+)/)[1];

    // Create a child under ParentA
    await page.goto(`${BASE}/admin/generi/${parentAId}`);
    await page.fill('#nome_sottogenere', `Child_${RUN_ID}`);
    await page.click('form:has(input[name="parent_id"]) button[type="submit"]');
    await page.waitForURL(/.*generi\/\d+.*/);
    const childId = page.url().match(/generi\/(\d+)/)[1];

    // Now rearrange: move child to ParentB
    await page.goto(`${BASE}/admin/generi/${childId}`);
    await page.click('#btn-edit-genre');
    await page.waitForSelector('#edit-genre-form:not(.hidden)', { timeout: 3000 });
    await page.selectOption('#edit_parent_id', parentBId);

    // Use waitForResponse to capture POST, then waitForLoadState for redirect
    await Promise.all([
      page.waitForResponse(resp => resp.url().includes('/admin/generi/') && resp.request().method() === 'POST'),
      page.click('#edit-genre-form button[type="submit"]'),
    ]);
    await page.waitForLoadState('networkidle');

    // Verify success message
    await expect(page.locator('body')).toContainText('aggiornato');

    // Verify the child now shows ParentB as parent
    await expect(page.locator('body')).toContainText(`ParentB_${RUN_ID}`);

    // Cleanup: delete child, then both parents
    await page.goto(`${BASE}/admin/generi/${childId}`);
    const deleteForm = page.locator('form[action*="/elimina"]');
    if (await deleteForm.isVisible({ timeout: 2000 }).catch(() => false)) {
      await deleteForm.locator('button[type="submit"]').click();
      await page.waitForURL(/.*generi.*/);
    }
    for (const pid of [parentAId, parentBId]) {
      await page.goto(`${BASE}/admin/generi/${pid}`);
      const df = page.locator('form[action*="/elimina"]');
      if (await df.isVisible({ timeout: 2000 }).catch(() => false)) {
        await df.locator('button[type="submit"]').click();
        await page.waitForURL(/.*generi.*/);
      }
    }
  });

  test('merge: combine two genres and verify redirect + stats', async ({ page }) => {
    await loginAsAdmin(page);

    // Create source genre (unique name per run)
    await page.goto(`${BASE}/admin/generi/crea`);
    await page.fill('input[name="nome"]', `Source_${RUN_ID}`);
    await page.click('button[type="submit"]');
    await page.waitForURL(/.*generi\/\d+.*/);
    const sourceId = page.url().match(/generi\/(\d+)/)[1];

    // Create target genre
    await page.goto(`${BASE}/admin/generi/crea`);
    await page.fill('input[name="nome"]', `Target_${RUN_ID}`);
    await page.click('button[type="submit"]');
    await page.waitForURL(/.*generi\/\d+.*/);
    const targetId = page.url().match(/generi\/(\d+)/)[1];

    // Go to source genre detail, select target in merge dropdown
    await page.goto(`${BASE}/admin/generi/${sourceId}`);
    await expect(page.locator('#merge-genre-form')).toBeVisible();

    // Accept confirm dialog
    page.on('dialog', dialog => dialog.accept());

    await page.selectOption('#merge_target_id', targetId);
    await page.click('#merge-genre-form button[type="submit"]');

    // Should redirect to target genre
    await page.waitForURL(new RegExp(`generi/${targetId}`));
    await expect(page.locator('body')).toContainText('uniti con successo');

    // Cleanup: delete target
    const df = page.locator('form[action*="/elimina"]');
    if (await df.isVisible({ timeout: 2000 }).catch(() => false)) {
      await df.locator('button[type="submit"]').click();
      await page.waitForURL(/.*generi.*/);
    }
  });

  test('merge card shows grouped dropdown', async ({ page }) => {
    await loginAsAdmin(page);

    // Navigate to any genre
    const resp = await page.request.get(`${BASE}/api/generi?only_parents=1&limit=5`);
    const genres = await resp.json();
    expect(genres.length).toBeGreaterThan(0);

    await page.goto(`${BASE}/admin/generi/${genres[0].id}`);

    // Merge form should be visible
    await expect(page.locator('#merge-genre-form')).toBeVisible();

    // Dropdown should have optgroups
    const optgroups = page.locator('#merge_target_id optgroup');
    const count = await optgroups.count();
    expect(count).toBeGreaterThan(0);
  });

  test('edit form has parent selector', async ({ page }) => {
    await loginAsAdmin(page);

    // Get all genres and find a child whose parent is top-level
    const resp = await page.request.get(`${BASE}/api/generi?limit=100`);
    const genres = await resp.json();
    const topLevelIds = new Set(genres.filter(g => g.parent_id === null).map(g => g.id));
    const childGenre = genres.find(g => g.parent_id !== null && topLevelIds.has(g.parent_id));
    expect(childGenre).toBeTruthy();

    await page.goto(`${BASE}/admin/generi/${childGenre.id}`);
    await page.click('#btn-edit-genre');

    const parentSelect = page.locator('#edit_parent_id');
    await expect(parentSelect).toBeVisible();

    // Should have current parent pre-selected
    const selectedValue = await parentSelect.inputValue();
    expect(parseInt(selectedValue, 10)).toBe(childGenre.parent_id);
  });
});
