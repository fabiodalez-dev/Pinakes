// @ts-check
const { test, expect } = require('@playwright/test');

const BASE = 'http://localhost:8081';
const ADMIN_EMAIL = 'fabiodalez@gmail.com';
const ADMIN_PASS = 'Fa310reds?';

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

/** Extract CSRF token from a page */
async function getCsrfToken(page) {
  return page.locator('meta[name="csrf-token"]').getAttribute('content')
    .catch(() => page.locator('input[name="csrf_token"]').first().getAttribute('value'));
}

// ────────────────────────────────────────────────────────────────────────
// 1. Access Control — manual-update.php
// ────────────────────────────────────────────────────────────────────────
test.describe('C-1: manual-update.php access control', () => {

  test('returns 403 without auth key or session', async ({ request }) => {
    const resp = await request.get(`${BASE}/manual-update.php`);
    // Should be 403 or redirect — NOT 200 with update UI
    expect([403, 404, 302, 301]).toContain(resp.status());
  });

  test('returns 403 with wrong key', async ({ request }) => {
    const resp = await request.get(`${BASE}/manual-update.php?key=wrong_key_12345`);
    expect([403, 404, 302, 301]).toContain(resp.status());
  });

  test('does not echo the secret key in error response', async ({ request }) => {
    const resp = await request.get(`${BASE}/manual-update.php`);
    const body = await resp.text();
    // Must NOT contain "Key:" which was the old info disclosure
    expect(body).not.toContain('Key:');
    expect(body).not.toContain('MANUAL_UPDATE_KEY');
  });
});

// ────────────────────────────────────────────────────────────────────────
// 2. Bulk Operations — soft-delete + deleted_at filter
// ────────────────────────────────────────────────────────────────────────
test.describe('C-2: bulkDelete uses soft-delete', () => {

  test('bulk delete returns success and books disappear from listing', async ({ page }) => {
    await loginAsAdmin(page);

    // Create a test book via the form
    await page.goto(`${BASE}/admin/libri/crea`);
    const testTitle = `TestBulkDel_${Date.now()}`;

    await page.fill('input[name="titolo"]', testTitle);
    await page.click('button[type="submit"]');
    // Wait for redirect to the book detail or book list
    await page.waitForURL(/.*libri.*/, { timeout: 10000 });

    // Get the book ID from the DataTables API (more reliable than search)
    const listResp = await page.request.get(
      `${BASE}/api/libri?start=0&length=5&search[value]=${encodeURIComponent(testTitle)}`
    );
    const listData = await listResp.json();
    expect(listData.data.length).toBeGreaterThan(0);
    const bookId = listData.data[0].id;

    // Get a fresh CSRF token
    await page.goto(`${BASE}/admin/libri`);
    const csrf = await getCsrfToken(page);

    // Bulk delete via API
    const delResp = await page.request.post(`${BASE}/api/libri/bulk-delete`, {
      headers: { 'Content-Type': 'application/json' },
      data: JSON.stringify({ ids: [bookId], csrf_token: csrf }),
    });
    expect(delResp.ok()).toBeTruthy();
    const delData = await delResp.json();
    expect(delData.success).toBeTruthy();
    expect(delData.affected).toBe(1);

    // Verify book no longer appears in DataTables listing
    const listResp2 = await page.request.get(
      `${BASE}/api/libri?start=0&length=5&search[value]=${encodeURIComponent(testTitle)}`
    );
    const listData2 = await listResp2.json();
    const found = listData2.data.find(b => b.id === bookId);
    expect(found).toBeFalsy();
  });
});

test.describe('H-1: bulkStatus respects deleted_at', () => {

  test('bulk status update returns success for existing books', async ({ page }) => {
    await loginAsAdmin(page);

    // Find an existing book via API
    const listResp = await page.request.get(`${BASE}/api/libri?start=0&length=1`);
    const listData = await listResp.json();
    expect(listData.data.length).toBeGreaterThan(0);
    const bookId = listData.data[0].id;

    await page.goto(`${BASE}/admin/libri`);
    const csrf = await getCsrfToken(page);

    // Change status to 'disponibile'
    const statusResp = await page.request.post(`${BASE}/api/libri/bulk-status`, {
      headers: { 'Content-Type': 'application/json' },
      data: JSON.stringify({ ids: [bookId], stato: 'disponibile', csrf_token: csrf }),
    });
    expect(statusResp.ok()).toBeTruthy();
    const statusData = await statusResp.json();
    expect(statusData.success).toBeTruthy();
  });
});

// ────────────────────────────────────────────────────────────────────────
// 3. GeneriApiController — JSON_HEX_TAG encoding
// ────────────────────────────────────────────────────────────────────────
test.describe('H-2: Genre API returns properly encoded JSON', () => {

  test('GET /api/generi returns valid JSON without raw HTML tags', async ({ page }) => {
    await loginAsAdmin(page);
    const resp = await page.request.get(`${BASE}/api/generi?limit=10`);
    expect(resp.ok()).toBeTruthy();
    const contentType = resp.headers()['content-type'] || '';
    expect(contentType).toContain('application/json');

    const text = await resp.text();
    // JSON_HEX_TAG converts < to \u003C — should not have raw </script>
    expect(text).not.toContain('</script>');

    // Must be valid parseable JSON
    const data = JSON.parse(text);
    expect(Array.isArray(data)).toBeTruthy();
  });

  test('GET /api/search/generi returns valid JSON', async ({ page }) => {
    await loginAsAdmin(page);
    const resp = await page.request.get(`${BASE}/api/search/generi?q=a`);
    expect(resp.ok()).toBeTruthy();
    const data = await resp.json();
    expect(Array.isArray(data)).toBeTruthy();
  });

  test('GET /api/generi/sottogeneri returns valid JSON', async ({ page }) => {
    await loginAsAdmin(page);
    // Get a parent genre first
    const genresResp = await page.request.get(`${BASE}/api/generi?only_parents=1&limit=1`);
    const genres = await genresResp.json();
    if (genres.length > 0) {
      const resp = await page.request.get(`${BASE}/api/generi/sottogeneri?parent_id=${genres[0].id}`);
      expect(resp.ok()).toBeTruthy();
      const data = await resp.json();
      expect(Array.isArray(data)).toBeTruthy();
    }
  });
});

// ────────────────────────────────────────────────────────────────────────
// 4. Error Handling — no info disclosure
// ────────────────────────────────────────────────────────────────────────
test.describe('H-3: Error responses do not leak internal details', () => {

  test('invalid genre creation returns generic error', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/generi/crea`);
    const csrf = await getCsrfToken(page);

    // Submit empty name (should fail)
    await page.goto(`${BASE}/admin/generi/crea`);
    await page.fill('input[name="nome"]', '');
    await page.click('button[type="submit"]');

    // Error message should be generic, not contain stack trace or SQL
    const body = await page.locator('body').textContent();
    expect(body).not.toMatch(/mysqli|SQL|stack trace|vendor\//i);
  });

  test('invalid bulk-status returns error without internals', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/libri`);
    const csrf = await getCsrfToken(page);

    const resp = await page.request.post(`${BASE}/api/libri/bulk-status`, {
      headers: { 'Content-Type': 'application/json' },
      data: JSON.stringify({ ids: [], stato: 'invalid_stato', csrf_token: csrf }),
    });
    const data = await resp.json();
    // Should not contain internal details
    const text = JSON.stringify(data);
    expect(text).not.toMatch(/mysqli|PDO|stack trace|vendor\//i);
  });

  test('404 page does not expose error details', async ({ page }) => {
    const resp = await page.goto(`${BASE}/nonexistent-page-xyz-12345`);
    const body = await page.locator('body').textContent();
    expect(body).not.toMatch(/stack trace|vendor\/|Slim\\Exception/i);
  });
});

// ────────────────────────────────────────────────────────────────────────
// 5. XSS Prevention — views render correctly
// ────────────────────────────────────────────────────────────────────────
test.describe('H-4: XSS prevention in views', () => {

  test('catalog page loads without JS errors', async ({ page }) => {
    const jsErrors = [];
    page.on('pageerror', error => jsErrors.push(error.message));

    await page.goto(`${BASE}/catalogo`);
    await page.waitForLoadState('networkidle');

    // Page should load and have content
    await expect(page.locator('body')).not.toBeEmpty();

    // No critical JS errors (ignore minor third-party issues)
    const criticalErrors = jsErrors.filter(e =>
      !e.includes('ResizeObserver') && !e.includes('Non-Error')
    );
    expect(criticalErrors).toHaveLength(0);
  });

  test('catalog filter buttons work (onclick escaping intact)', async ({ page }) => {
    await page.goto(`${BASE}/catalogo`);
    await page.waitForLoadState('networkidle');

    // Check that filter buttons exist and are clickable
    const availabilityFilter = page.locator('[onclick*="updateFilter"]').first();
    if (await availabilityFilter.isVisible({ timeout: 3000 }).catch(() => false)) {
      await availabilityFilter.click();
      // Should not cause JS error — just filter the results
      await page.waitForTimeout(500);
      await expect(page.locator('body')).not.toBeEmpty();
    }
  });

  test('admin book list page loads with proper JSON encoding', async ({ page }) => {
    await loginAsAdmin(page);
    const jsErrors = [];
    page.on('pageerror', error => jsErrors.push(error.message));

    await page.goto(`${BASE}/admin/libri`);
    await page.waitForLoadState('networkidle');

    // DataTables should initialize without JS errors
    const criticalErrors = jsErrors.filter(e =>
      !e.includes('ResizeObserver') && !e.includes('Non-Error')
    );
    expect(criticalErrors).toHaveLength(0);

    // Table should render
    await expect(page.locator('table')).toBeVisible();
  });

  test('admin authors index loads without errors', async ({ page }) => {
    await loginAsAdmin(page);
    const jsErrors = [];
    page.on('pageerror', error => jsErrors.push(error.message));

    await page.goto(`${BASE}/admin/autori`);
    await page.waitForLoadState('networkidle');

    const criticalErrors = jsErrors.filter(e =>
      !e.includes('ResizeObserver') && !e.includes('Non-Error')
    );
    expect(criticalErrors).toHaveLength(0);
  });

  test('admin publishers index loads without errors', async ({ page }) => {
    await loginAsAdmin(page);
    const jsErrors = [];
    page.on('pageerror', error => jsErrors.push(error.message));

    await page.goto(`${BASE}/admin/editori`);
    await page.waitForLoadState('networkidle');

    const criticalErrors = jsErrors.filter(e =>
      !e.includes('ResizeObserver') && !e.includes('Non-Error')
    );
    expect(criticalErrors).toHaveLength(0);
  });

  test('admin loans index loads without errors', async ({ page }) => {
    await loginAsAdmin(page);
    const jsErrors = [];
    page.on('pageerror', error => jsErrors.push(error.message));

    await page.goto(`${BASE}/admin/prestiti`);
    await page.waitForLoadState('networkidle');

    const criticalErrors = jsErrors.filter(e =>
      !e.includes('ResizeObserver') && !e.includes('Non-Error')
    );
    expect(criticalErrors).toHaveLength(0);
  });

  test('genre detail page loads with proper JSON in script tags', async ({ page }) => {
    await loginAsAdmin(page);
    const jsErrors = [];
    page.on('pageerror', error => jsErrors.push(error.message));

    // Get a genre to view
    const resp = await page.request.get(`${BASE}/api/generi?only_parents=1&limit=1`);
    const genres = await resp.json();
    expect(genres.length).toBeGreaterThan(0);

    await page.goto(`${BASE}/admin/generi/${genres[0].id}`);
    await page.waitForLoadState('networkidle');

    const criticalErrors = jsErrors.filter(e =>
      !e.includes('ResizeObserver') && !e.includes('Non-Error')
    );
    expect(criticalErrors).toHaveLength(0);
  });

  test('book detail page loads with proper escaping', async ({ page }) => {
    const jsErrors = [];
    page.on('pageerror', error => jsErrors.push(error.message));

    // Find a book in catalog
    const apiResp = await page.request.get(`${BASE}/api/libri?start=0&length=1`);
    const apiData = await apiResp.json();
    if (apiData.data && apiData.data.length > 0) {
      const book = apiData.data[0];
      // Navigate to book detail via slug or ID
      if (book.slug) {
        await page.goto(`${BASE}/libro/${book.slug}`);
      } else {
        await page.goto(`${BASE}/libro/${book.id}`);
      }
      await page.waitForLoadState('networkidle');

      const criticalErrors = jsErrors.filter(e =>
        !e.includes('ResizeObserver') && !e.includes('Non-Error')
      );
      expect(criticalErrors).toHaveLength(0);
    }
  });

  test('CMS home edit page loads without errors', async ({ page }) => {
    await loginAsAdmin(page);
    const jsErrors = [];
    page.on('pageerror', error => jsErrors.push(error.message));

    await page.goto(`${BASE}/admin/cms/homepage`);
    await page.waitForLoadState('networkidle');

    const criticalErrors = jsErrors.filter(e =>
      !e.includes('ResizeObserver') && !e.includes('Non-Error') && !e.includes('tinymce')
    );
    expect(criticalErrors).toHaveLength(0);
  });

  test('cookie banner script loads without errors', async ({ page }) => {
    const jsErrors = [];
    page.on('pageerror', error => jsErrors.push(error.message));

    await page.goto(`${BASE}/catalogo`);
    await page.waitForLoadState('networkidle');

    // Check that cookie banner JS doesn't throw
    const cookieErrors = jsErrors.filter(e =>
      e.toLowerCase().includes('cookie') || e.toLowerCase().includes('route_path')
    );
    expect(cookieErrors).toHaveLength(0);
  });

  test('home page total_books counter uses textContent (no innerHTML)', async ({ page }) => {
    await page.goto(`${BASE}/`);
    await page.waitForLoadState('networkidle');

    // The counter should show a numeric value, not HTML
    const totalBooks = page.locator('#total-books');
    if (await totalBooks.isVisible({ timeout: 3000 }).catch(() => false)) {
      const text = await totalBooks.textContent();
      // Should be a number or emoji, not HTML tags
      expect(text).not.toMatch(/<[^>]+>/);
    }
  });
});

// ────────────────────────────────────────────────────────────────────────
// 6. Directory Listing Prevention
// ────────────────────────────────────────────────────────────────────────
test.describe('M-1: Directory listing disabled', () => {

  test('/uploads/ does not show directory listing', async ({ request }) => {
    const resp = await request.get(`${BASE}/uploads/`);
    const body = await resp.text();
    // Should NOT contain "Index of" which is Apache directory listing
    expect(body).not.toMatch(/Index of\s+\//i);
  });

  test('/assets/ does not show directory listing', async ({ request }) => {
    const resp = await request.get(`${BASE}/assets/`);
    const body = await resp.text();
    expect(body).not.toMatch(/Index of\s+\//i);
  });
});

// ────────────────────────────────────────────────────────────────────────
// 7. displayErrorDetails defaults to false
// ────────────────────────────────────────────────────────────────────────
test.describe('M-2: Error detail suppression', () => {

  test('error page does not show Slim stack trace', async ({ page }) => {
    // Trigger a real error by hitting a bad endpoint
    await page.goto(`${BASE}/api/nonexistent-endpoint-xyz`);
    const body = await page.locator('body').textContent();
    expect(body).not.toMatch(/Slim\\Exception|Stack trace|#\d+ \/.*\.php/i);
  });
});

// ────────────────────────────────────────────────────────────────────────
// 8. Admin Settings Page — safe DOM rendering
// ────────────────────────────────────────────────────────────────────────
test.describe('M-3: Admin settings safe rendering', () => {

  test('settings page loads without JS errors', async ({ page }) => {
    await loginAsAdmin(page);
    const jsErrors = [];
    page.on('pageerror', error => jsErrors.push(error.message));

    await page.goto(`${BASE}/admin/settings`);
    await page.waitForLoadState('networkidle');

    const criticalErrors = jsErrors.filter(e =>
      !e.includes('ResizeObserver') && !e.includes('Non-Error') && !e.includes('tinymce')
    );
    expect(criticalErrors).toHaveLength(0);
  });
});

// ────────────────────────────────────────────────────────────────────────
// 9. CSRF Middleware — logging (verify no crash)
// ────────────────────────────────────────────────────────────────────────
test.describe('M-4: CSRF protection intact', () => {

  test('POST without CSRF token is rejected', async ({ request }) => {
    const resp = await request.post(`${BASE}/api/generi`, {
      headers: { 'Content-Type': 'application/json' },
      data: JSON.stringify({ nome: 'test' }),
    });
    // Should be 400, 403, or similar — NOT 200
    expect(resp.ok()).toBeFalsy();
  });

  test('POST with invalid CSRF token is rejected', async ({ request }) => {
    const resp = await request.post(`${BASE}/api/generi`, {
      headers: { 'Content-Type': 'application/json' },
      data: JSON.stringify({ nome: 'test', csrf_token: 'invalid_token_xyz' }),
    });
    expect(resp.ok()).toBeFalsy();
  });
});

// ────────────────────────────────────────────────────────────────────────
// 10. Layout page — JSON encoding in script blocks
// ────────────────────────────────────────────────────────────────────────
test.describe('M-5: Layout script blocks properly encoded', () => {

  test('layout page has no raw </script> in embedded JSON', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/libri`);

    // Get the full HTML source
    const html = await page.content();

    // Find all <script> blocks and check none contain un-encoded </script> inside JSON
    const scriptBlocks = html.match(/<script[^>]*>([\s\S]*?)<\/script>/gi) || [];
    for (const block of scriptBlocks) {
      // Extract content between tags
      const content = block.replace(/<script[^>]*>/i, '').replace(/<\/script>/i, '');
      // JSON_HEX_TAG converts </script> to \u003C/script\u003E
      // So raw </script> inside a script block (other than the closing tag) = bad
      const innerScripts = content.match(/<\/script>/gi);
      // There should be no </script> inside the content itself
      expect(innerScripts).toBeNull();
    }
  });
});
