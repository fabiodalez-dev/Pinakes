// @ts-check
const { test, expect, chromium } = require('@playwright/test');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const DB_HOST     = process.env.E2E_DB_HOST     || '';
const DB_USER     = process.env.E2E_DB_USER     || '';
const DB_PASS     = process.env.E2E_DB_PASS     || '';
const DB_NAME     = process.env.E2E_DB_NAME     || '';
const DB_SOCKET   = process.env.E2E_DB_SOCKET   || '';

// Skip all tests when credentials are not configured
test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'E2E credentials not configured (set E2E_ADMIN_EMAIL, E2E_ADMIN_PASS, E2E_DB_USER, E2E_DB_NAME)',
);

// ────────────────────────────────────────────────────────────────────────
// Full lifecycle smoke test: fresh install → login → CRUD operations
// Uses a shared browser context so PHP sessions (cookies) persist.
// ────────────────────────────────────────────────────────────────────────
test.describe.serial('Smoke: clean install + core operations', () => {

  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  let createdBookId = 0;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
  });

  test.afterAll(async () => {
    await context.close();
  });

  // ── Step 0: Language Selection ──────────────────────────────────────
  test('Installer step 0: select Italian language', async () => {
    await page.goto(`${BASE}/installer/?step=0`);
    await page.locator('input[name="language"][value="it"]').check();
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/step=1/);
  });

  // ── Step 1: Requirements + start ───────────────────────────────────
  test('Installer step 1: verify requirements and start', async () => {
    // All requirements should be met
    await expect(page.locator('li.not-met')).toHaveCount(0);
    await page.locator('button[type="submit"].btn-primary').click();
    await page.waitForURL(/step=2/);
  });

  // ── Step 2: Database Configuration ─────────────────────────────────
  test('Installer step 2: configure DB and test connection', async () => {
    await page.fill('#db_host', DB_HOST || 'localhost');
    await page.fill('#db_username', DB_USER);
    await page.fill('#db_password', DB_PASS);
    await page.fill('#db_database', DB_NAME);
    if (DB_SOCKET) {
      await page.fill('#db_socket', DB_SOCKET);
    }

    // Test connection (required to enable Continue button)
    await page.click('#test-connection-btn');
    // Wait for the result div to become visible (success or error)
    await page.waitForFunction(
      () => {
        const el = document.getElementById('connection-result');
        return el && el.style.display !== 'none' && el.textContent.trim().length > 0;
      },
      { timeout: 15000 }
    );
    // Check it was a success
    const resultClass = await page.locator('#connection-result').getAttribute('class');
    expect(resultClass).toContain('alert-success');

    // Continue button should now be enabled
    await expect(page.locator('#continue-btn')).toBeEnabled();
    await page.click('#continue-btn');
    // Step 2 POST → redirect to step=3 → auto-import → redirect to step=4
    // step=3 may redirect so fast we never see it, so wait for step=4 directly
    await page.waitForURL(/step=[34]/, { timeout: 30000 });
  });

  // ── Step 3: DB Import (auto-redirect) ──────────────────────────────
  test('Installer step 3: wait for DB schema import', async () => {
    // If we're already on step=4, the import already completed
    const currentUrl = page.url();
    if (currentUrl.includes('step=4')) return;
    // Otherwise wait for the redirect from step=3 to step=4
    await page.waitForURL(/step=4/, { timeout: 60000 });
  });

  // ── Step 4: Create Admin User ──────────────────────────────────────
  test('Installer step 4: create admin user', async () => {
    await page.fill('input[name="nome"]', 'Fabio');
    await page.fill('input[name="cognome"]', 'Dalez');
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('#password', ADMIN_PASS);
    await page.fill('#password_confirm', ADMIN_PASS);

    await page.locator('button[type="submit"].btn-primary').click();
    await page.waitForURL(/step=5/, { timeout: 15000 });
  });

  // ── Step 5: Application Settings ───────────────────────────────────
  test('Installer step 5: set app name', async () => {
    await page.fill('input[name="app_name"]', 'Pinakes');
    await page.locator('button[type="submit"].btn-primary').click();
    await page.waitForURL(/step=6/, { timeout: 15000 });
  });

  // ── Step 6: Email Configuration ────────────────────────────────────
  test('Installer step 6: configure email (mail driver)', async () => {
    await page.selectOption('#email_driver', 'mail');
    await page.fill('input[name="from_email"]', 'noreply@example.com');
    await page.fill('input[name="from_name"]', 'Pinakes');
    await page.locator('button[type="submit"].btn-primary').click();
    await page.waitForURL(/step=7/, { timeout: 30000 });
  });

  // ── Step 7: Installation Complete ──────────────────────────────────
  test('Installer step 7: verify completion and go to app', async () => {
    // Wait for finalization to complete (plugin install, .htaccess, permissions)
    await expect(page.locator('.alert-success').first()).toBeVisible({ timeout: 30000 });

    // Click "Vai all'Applicazione"
    await page.locator('a.btn-primary').click();
    // Should land on the app homepage (not the installer)
    await page.waitForURL(url => !url.toString().includes('installer'), { timeout: 15000 });
  });

  // ── Login as Admin ─────────────────────────────────────────────────
  test('Login as admin', async () => {
    await page.goto(`${BASE}/admin/dashboard`);
    // Should redirect to login page
    const emailField = page.locator('input[name="email"]');
    if (await emailField.isVisible({ timeout: 3000 }).catch(() => false)) {
      await emailField.fill(ADMIN_EMAIL);
      await page.fill('input[name="password"]', ADMIN_PASS);
      await page.locator('button[type="submit"]').click();
    }
    // Should reach dashboard
    await page.waitForURL(/admin/, { timeout: 15000 });
    await expect(page).toHaveURL(/admin/);
  });

  // ── Add a Genre ────────────────────────────────────────────────────
  test('Add genre: Narrativa', async () => {
    await page.goto(`${BASE}/admin/generi/crea`);
    await page.fill('#nome', 'Narrativa');
    await page.locator('button[type="submit"]').click();

    // Should redirect to genres list or detail
    await page.waitForURL(/admin\/generi/, { timeout: 10000 });
    // Verify genre exists in the list
    await page.goto(`${BASE}/admin/generi`);
    await expect(page.getByRole('heading', { name: 'Narrativa' })).toBeVisible({ timeout: 5000 });
  });

  // ── Add a Book ─────────────────────────────────────────────────────
  test('Add book with inline author creation', async () => {
    await page.goto(`${BASE}/admin/libri/crea`);
    await page.waitForLoadState('networkidle');

    // Fill title
    await page.fill('#titolo', 'Il Nome della Rosa');

    // Create author inline via Choices.js
    // Target the author search input specifically (not the publisher one)
    const authorInput = page.locator('.choices__input--cloned[aria-label*="autori"]');
    await authorInput.fill('Umberto Eco');
    await authorInput.press('Enter');

    // Wait for the author item to appear in the Choices.js widget
    await expect(
      page.locator('.choices__list--multiple .choices__item')
    ).toBeVisible({ timeout: 5000 });

    // Select genre: wait for radice dropdown to be populated by API
    await page.waitForFunction(() => {
      const sel = document.querySelector('#radice_select');
      return sel && sel.options.length > 1;
    }, { timeout: 10000 });
    await page.selectOption('#radice_select', { label: 'Narrativa' });

    // Submit — the form uses SweetAlert2 confirmation dialog
    await page.locator('#bookForm button[type="submit"]').click();

    // Wait for SweetAlert confirmation popup and click confirm
    await page.waitForSelector('.swal2-confirm', { timeout: 5000 });
    await page.locator('.swal2-confirm').click();

    // Wait for navigation after fetch-based submit
    await page.waitForURL(/admin\/libri(?!.*crea)/, { timeout: 15000 });

    // Get the book ID from the API for later tests
    const listResp = await page.request.get(
      `${BASE}/api/libri?start=0&length=5&search[value]=${encodeURIComponent('Il Nome della Rosa')}`
    );
    const listData = await listResp.json();
    expect(listData.data.length).toBeGreaterThan(0);
    createdBookId = listData.data[0].id;
    expect(createdBookId).toBeGreaterThan(0);
  });

  // ── Simulate a Loan ────────────────────────────────────────────────
  test('Create a loan for the book', async () => {
    await page.goto(`${BASE}/admin/prestiti/crea`);
    await page.waitForLoadState('networkidle');

    // Search for user (admin is the only user)
    await page.fill('#utente_search', 'Fabio');
    await page.waitForSelector('#utente_suggest .suggestion-item', { timeout: 10000 });
    await page.locator('#utente_suggest .suggestion-item').first().click();

    // Verify utente_id was set
    const utenteId = await page.locator('#utente_id').inputValue();
    expect(Number(utenteId)).toBeGreaterThan(0);

    // Search for the book
    await page.fill('#libro_search', 'Nome della Rosa');
    await page.waitForSelector('#libro_suggest .suggestion-item', { timeout: 10000 });
    await page.locator('#libro_suggest .suggestion-item').first().click();

    // Verify libro_id was set
    const libroId = await page.locator('#libro_id').inputValue();
    expect(Number(libroId)).toBeGreaterThan(0);

    // Dates are auto-filled; submit
    await page.locator('button[type="submit"]').click();

    // Should redirect to loans list or detail
    await page.waitForURL(/admin\/prestiti/, { timeout: 15000 });
  });

  // ── Edit a Book ────────────────────────────────────────────────────
  test('Edit book: change title', async () => {
    expect(createdBookId).toBeGreaterThan(0);

    await page.goto(`${BASE}/admin/libri/modifica/${createdBookId}`);
    await page.waitForLoadState('networkidle');

    // Change the title
    await page.fill('#titolo', 'Il Nome della Rosa - Edizione Rivista');

    // Submit — SweetAlert2 confirmation dialog
    await page.locator('#bookForm button[type="submit"]').click();
    await page.waitForSelector('.swal2-confirm', { timeout: 5000 });
    await page.locator('.swal2-confirm').click();
    await page.waitForURL(/admin\/libri(?!.*modifica)/, { timeout: 15000 });

    // Verify the title was updated via API
    const listResp = await page.request.get(
      `${BASE}/api/libri?start=0&length=5&search[value]=${encodeURIComponent('Edizione Rivista')}`
    );
    const listData = await listResp.json();
    expect(listData.data.length).toBeGreaterThan(0);
    expect(listData.data[0].titolo).toContain('Edizione Rivista');
  });
});
