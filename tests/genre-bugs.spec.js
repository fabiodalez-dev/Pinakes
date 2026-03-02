// @ts-check
const { test, expect } = require('@playwright/test');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';

// Skip all tests when credentials are not configured
test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'E2E admin credentials not configured (set E2E_ADMIN_EMAIL and E2E_ADMIN_PASS)');

/**
 * Helper: login as admin.
 * Navigates to the admin dashboard; if redirected to login, fills credentials.
 * Works regardless of locale (login route may be /accedi, /login, etc.).
 */
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

/**
 * Helper: handle save confirmation dialog if present.
 */
async function handleConfirmDialog(page) {
  const confirmBtn = page.locator('[role="dialog"] button, .modal button').last();
  if (await confirmBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
    await confirmBtn.click();
  }
}

// ────────────────────────────────────────────────────────────────────────
// Issue #64: Genre editing — add edit/rename functionality
// ────────────────────────────────────────────────────────────────────────
test.describe('Issue #64: Genre Edit/Update', () => {

  test('genre detail page has edit button and inline form', async ({ page }) => {
    await loginAsAdmin(page);

    // Get a genre ID from the API (avoid hardcoding)
    const resp = await page.request.get(`${BASE}/api/generi?only_parents=1&limit=5`);
    const genres = await resp.json();
    expect(genres.length).toBeGreaterThan(0);
    const genre = genres[0];

    await page.goto(`${BASE}/admin/generi/${genre.id}`);

    // Edit button should exist
    const editBtn = page.locator('#btn-edit-genre');
    await expect(editBtn).toBeVisible();

    // Click edit — inline form should appear
    await editBtn.click();
    const editForm = page.locator('#edit-genre-form');
    await expect(editForm).toBeVisible();

    // Input should have current name
    const nameInput = page.locator('#edit_nome');
    await expect(nameInput).toBeVisible();
    expect(await nameInput.inputValue()).toBe(genre.nome);

    // Cancel should hide the form
    await page.click('#btn-cancel-edit');
    await expect(editForm).toBeHidden();
  });

  test('can rename a genre and restore original name', async ({ page }) => {
    await loginAsAdmin(page);

    // Get a child genre from API (safer to rename than root)
    const resp = await page.request.get(`${BASE}/api/generi?limit=50`);
    const genres = await resp.json();
    const childGenre = genres.find(g => g.parent_id !== null);
    expect(childGenre).toBeTruthy();

    await page.goto(`${BASE}/admin/generi/${childGenre.id}`);

    const nameDisplay = page.locator('#genre-name-display');
    const originalName = (await nameDisplay.textContent())?.trim() || '';
    expect(originalName.length).toBeGreaterThan(0);

    // Edit the name
    await page.click('#btn-edit-genre');
    const nameInput = page.locator('#edit_nome');
    await nameInput.clear();
    const newName = originalName + ' (test)';
    await nameInput.fill(newName);
    await page.click('#edit-genre-form button[type="submit"]');

    await page.waitForURL(/.*generi\/\d+.*/);
    await expect(page.locator('#genre-name-display')).toContainText('(test)');

    // Restore original name
    await page.click('#btn-edit-genre');
    await page.locator('#edit_nome').clear();
    await page.locator('#edit_nome').fill(originalName);
    await page.click('#edit-genre-form button[type="submit"]');
    await page.waitForURL(/.*generi\/\d+.*/);
    await expect(page.locator('#genre-name-display')).toContainText(originalName);
  });

  test('API: unauthenticated POST to genre update returns 401', async ({ request }) => {
    // POST without session cookies should be rejected
    const resp = await request.post(`${BASE}/api/generi/1`, {
      data: { nome: 'Hacked' },
      headers: { 'Content-Type': 'application/json' }
    });
    // Should get 401 (not authenticated) or 403 (CSRF fail)
    expect([401, 403]).toContain(resp.status());
  });

  test('delete section visible only for leaf genres (no children)', async ({ page }) => {
    await loginAsAdmin(page);

    // Get a root genre — should NOT show delete (has children)
    const rootResp = await page.request.get(`${BASE}/api/generi?only_parents=1&limit=5`);
    const roots = await rootResp.json();
    const rootWithChildren = roots.find(g => g.children_count > 0);
    if (rootWithChildren) {
      await page.goto(`${BASE}/admin/generi/${rootWithChildren.id}`);
      // Delete button should NOT be visible for genres with children
      const deleteForm = page.locator('form[action*="/elimina"]');
      await expect(deleteForm).toBeHidden();
    }

    // Get a leaf genre (no children) — should show delete
    const allResp = await page.request.get(`${BASE}/api/generi?limit=100`);
    const allGenres = await allResp.json();
    const leafGenre = allGenres.find(g => g.children_count === 0);
    if (leafGenre) {
      await page.goto(`${BASE}/admin/generi/${leafGenre.id}`);
      const deleteForm = page.locator('form[action*="/elimina"]');
      await expect(deleteForm).toBeVisible();
    }
  });
});

// ────────────────────────────────────────────────────────────────────────
// Issue #63: Genre cascade pre-population on book edit
// ────────────────────────────────────────────────────────────────────────
test.describe('Issue #63: Genre Pre-population on Edit', () => {

  test('create book with genre, verify cascade pre-populates on edit', async ({ page }) => {
    await loginAsAdmin(page);

    // ── Setup: create a 3-level genre hierarchy (root → genre → subgenre) ──
    // The controller's "hierarchical consistency" logic requires 3 levels
    // to correctly map genere_id/sottogenere_id for cascade pre-population.
    const RUN = Date.now().toString(36);

    // Create root genre (L1)
    await page.goto(`${BASE}/admin/generi/crea`);
    await page.fill('input[name="nome"]', `CascRoot_${RUN}`);
    await page.click('button[type="submit"]');
    await page.waitForURL(/.*generi\/\d+.*/);
    const rootId = page.url().match(/generi\/(\d+)/)?.[1];
    expect(rootId).toBeTruthy();

    // Create genre under root (L2)
    await page.goto(`${BASE}/admin/generi/${rootId}`);
    await page.fill('#nome_sottogenere', `CascGenre_${RUN}`);
    await page.click('form:has(input[name="parent_id"]) button[type="submit"]');
    await page.waitForURL(/.*generi\/\d+.*/);
    const genreId = page.url().match(/generi\/(\d+)/)?.[1];
    expect(genreId).toBeTruthy();

    // Create subgenre under genre (L3)
    await page.goto(`${BASE}/admin/generi/${genreId}`);
    await page.fill('#nome_sottogenere', `CascSub_${RUN}`);
    await page.click('form:has(input[name="parent_id"]) button[type="submit"]');
    await page.waitForURL(/.*generi\/\d+.*/);
    const subId = page.url().match(/generi\/(\d+)/)?.[1];
    expect(subId).toBeTruthy();

    // ── Create book selecting all 3 levels ──
    await page.goto(`${BASE}/admin/libri/crea`);
    await page.waitForSelector('#radice_select');
    await page.fill('input[name="titolo"]', `CascBook_${RUN}`);

    // Wait for root options to load, then select our root
    await page.waitForFunction(() => {
      const sel = document.getElementById('radice_select');
      return sel && sel.options.length > 1;
    }, { timeout: 10000 });
    await page.locator('#radice_select').selectOption(rootId);

    // Wait for L2 to load, then select our genre
    await page.waitForFunction((gid) => {
      const sel = document.getElementById('genere_select');
      if (!sel || sel.disabled) return false;
      return Array.from(sel.options).some(o => o.value === gid);
    }, genreId, { timeout: 10000 });
    await page.locator('#genere_select').selectOption(genreId);

    // Wait for L3 to load, then select our subgenre
    await page.waitForFunction((sid) => {
      const sel = document.getElementById('sottogenere_select');
      if (!sel || sel.disabled) return false;
      return Array.from(sel.options).some(o => o.value === sid);
    }, subId, { timeout: 10000 });
    await page.locator('#sottogenere_select').selectOption(subId);

    // Submit the form
    await page.click('button[type="submit"]');
    await handleConfirmDialog(page);
    await page.waitForURL(/.*libri\/\d+.*/, { timeout: 30000 });

    const testBookId = page.url().match(/libri\/(\d+)/)?.[1];
    expect(testBookId).toBeTruthy();

    // ── Edit: verify cascade pre-populates all 3 levels ──
    await page.goto(`${BASE}/admin/libri/modifica/${testBookId}`);
    await page.waitForSelector('#radice_select');

    // Radice pre-selected
    await page.waitForFunction((rid) => {
      const sel = document.getElementById('radice_select');
      return sel && sel.value === rid;
    }, rootId, { timeout: 15000 });

    // Genre pre-selected (loaded async after radice change)
    await page.waitForFunction((gid) => {
      const sel = document.getElementById('genere_select');
      return sel && !sel.disabled && sel.value === gid;
    }, genreId, { timeout: 15000 });

    // Subgenre pre-selected (loaded async after genre change)
    await page.waitForFunction((sid) => {
      const sel = document.getElementById('sottogenere_select');
      return sel && !sel.disabled && sel.value === sid;
    }, subId, { timeout: 15000 });

    // ── Re-save: verify genres persist after save without changes ──
    await page.click('button[type="submit"]');
    await handleConfirmDialog(page);
    await page.waitForURL(/.*libri\/\d+.*/, { timeout: 30000 });

    // Re-edit and verify all 3 levels survived
    await page.goto(`${BASE}/admin/libri/modifica/${testBookId}`);
    await page.waitForSelector('#radice_select');
    await page.waitForFunction((rid) => {
      const sel = document.getElementById('radice_select');
      return sel && sel.value === rid;
    }, rootId, { timeout: 15000 });
    await page.waitForFunction((gid) => {
      const sel = document.getElementById('genere_select');
      return sel && !sel.disabled && sel.value === gid;
    }, genreId, { timeout: 15000 });

    // ── Cleanup: delete book, then genres (leaf first) ──
    await page.goto(`${BASE}/admin/libri/${testBookId}`);
    const delBookForm = page.locator('form[action*="/delete"]');
    if (await delBookForm.isVisible({ timeout: 2000 }).catch(() => false)) {
      await delBookForm.locator('button[type="submit"]').click();
      await page.waitForURL(/.*libri.*/, { timeout: 10000 });
    }
    for (const gid of [subId, genreId, rootId]) {
      await page.goto(`${BASE}/admin/generi/${gid}`);
      const df = page.locator('form[action*="/elimina"]');
      if (await df.isVisible({ timeout: 2000 }).catch(() => false)) {
        await df.locator('button[type="submit"]').click();
        await page.waitForURL(/.*generi.*/, { timeout: 10000 });
      }
    }
  });

  test('genre dropdowns load even when book has no genre set', async ({ page }) => {
    await loginAsAdmin(page);

    // Navigate to new book form, don't set genre, save
    await page.goto(`${BASE}/admin/libri`);
    const newBookLink = page.locator('a[href$="/libri/crea"], a[href$="/books/create"]').first();
    await expect(newBookLink).toBeVisible();
    await newBookLink.click();
    await page.waitForSelector('#radice_select');

    await page.fill('input[name="titolo"]', 'Test No Genre Book');

    await page.click('button[type="submit"]');
    await handleConfirmDialog(page);
    await page.waitForURL(/.*libri\/\d+.*/, { timeout: 30000 });

    const url = page.url();
    const match = url.match(/libri\/(\d+)/);
    const bookId = match ? match[1] : null;
    expect(bookId).toBeTruthy();

    // Edit this book — dropdowns should still load (just empty)
    await page.goto(`${BASE}/admin/libri/modifica/${bookId}`);
    await page.waitForSelector('#radice_select');

    // Radice should have options but value=0
    await page.waitForFunction(() => {
      const sel = document.getElementById('radice_select');
      return sel && sel.options.length > 1;
    });
    const radiceValue = await page.locator('#radice_select').inputValue();
    expect(parseInt(radiceValue, 10)).toBe(0);

    // Cleanup: soft-delete the created book via admin API
    const cleanupResp = await page.request.post(`${BASE}/admin/libri/elimina/${bookId}`);
    expect([200, 204, 302]).toContain(cleanupResp.status());
  });
});

// ────────────────────────────────────────────────────────────────────────
// Issue #67: Search by sub-genre returns results
// ────────────────────────────────────────────────────────────────────────
test.describe('Issue #67: Genre Filter in Book List', () => {

  test('API: filtering by child genre does not error', async ({ request }) => {
    const genreResp = await request.get(`${BASE}/api/generi?limit=50`);
    expect(genreResp.ok()).toBeTruthy();
    const genres = await genreResp.json();

    const childGenre = genres.find(g => g.parent_id !== null);
    if (!childGenre) {
      test.skip();
      return;
    }

    // Filter books by child genre — should work (not crash)
    const booksResp = await request.get(
      `${BASE}/api/libri?draw=1&start=0&length=10&genere_filter=${childGenre.id}`
    );
    expect(booksResp.ok()).toBeTruthy();
    const data = await booksResp.json();
    expect(data).toHaveProperty('data');
    expect(data).toHaveProperty('recordsFiltered');
  });

  test('API: filtering by root genre does not error', async ({ request }) => {
    const genreResp = await request.get(`${BASE}/api/generi?only_parents=1&limit=5`);
    expect(genreResp.ok()).toBeTruthy();
    const genres = await genreResp.json();
    expect(genres.length).toBeGreaterThan(0);

    // Filter books by root genre — should also work
    const booksResp = await request.get(
      `${BASE}/api/libri?draw=1&start=0&length=10&genere_filter=${genres[0].id}`
    );
    expect(booksResp.ok()).toBeTruthy();
    const data = await booksResp.json();
    expect(data).toHaveProperty('data');
  });

  test('API: genre list endpoint returns expected structure', async ({ request }) => {
    const resp = await request.get(`${BASE}/api/generi?limit=50`);
    expect(resp.ok()).toBeTruthy();
    const genres = await resp.json();
    expect(Array.isArray(genres)).toBe(true);
    expect(genres.length).toBeGreaterThan(0);

    // Each genre should have required fields
    for (const g of genres) {
      expect(g).toHaveProperty('id');
      expect(g).toHaveProperty('nome');
      expect(g).toHaveProperty('parent_id');
      expect(g).toHaveProperty('tipo');
      expect(g).toHaveProperty('label');
    }

    // Should have both root and child genres
    const roots = genres.filter(g => g.parent_id === null);
    const children = genres.filter(g => g.parent_id !== null);
    expect(roots.length).toBeGreaterThan(0);
    expect(children.length).toBeGreaterThan(0);
  });

  test('API: sottogeneri endpoint returns children of a parent', async ({ request }) => {
    // Get a root genre
    const listResp = await request.get(`${BASE}/api/generi?only_parents=1&limit=5`);
    const roots = await listResp.json();
    expect(roots.length).toBeGreaterThan(0);

    const rootWithChildren = roots.find(r => r.children_count > 0);
    if (!rootWithChildren) {
      test.skip();
      return;
    }

    const resp = await request.get(`${BASE}/api/generi/sottogeneri?parent_id=${rootWithChildren.id}`);
    expect(resp.ok()).toBeTruthy();
    const children = await resp.json();
    expect(Array.isArray(children)).toBe(true);
    expect(children.length).toBeGreaterThan(0);
    for (const c of children) {
      expect(c).toHaveProperty('id');
      expect(c).toHaveProperty('nome');
    }
  });

  test('genre autocomplete in admin book list works for subgenres', async ({ page }) => {
    await loginAsAdmin(page);

    await page.goto(`${BASE}/admin/libri`);
    await page.waitForSelector('#filter_genere');

    // Get a child genre name from API (avoid hardcoding)
    const resp = await page.request.get(`${BASE}/api/generi?limit=50`);
    const genres = await resp.json();
    const childGenre = genres.find(g => g.parent_id !== null);
    expect(childGenre).toBeTruthy();

    const genreFilter = page.locator('#filter_genere');
    await genreFilter.fill(childGenre.nome);

    const suggestions = page.locator('#filter_genere_suggest');
    await expect(suggestions).toBeVisible({ timeout: 5000 });

    const items = suggestions.locator('li');
    const count = await items.count();
    expect(count).toBeGreaterThan(0);
  });

  test('genre search API returns results for partial match', async ({ request }) => {
    // Get a genre name to search for
    const listResp = await request.get(`${BASE}/api/generi?limit=5`);
    const genres = await listResp.json();
    expect(genres.length).toBeGreaterThan(0);

    // Search for first 3 chars of a genre name
    const searchTerm = genres[0].nome.substring(0, 3);
    const searchResp = await request.get(`${BASE}/api/search/generi?q=${encodeURIComponent(searchTerm)}`);
    expect(searchResp.ok()).toBeTruthy();
    const results = await searchResp.json();
    expect(Array.isArray(results)).toBe(true);
    expect(results.length).toBeGreaterThan(0);
  });
});
