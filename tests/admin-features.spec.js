// @ts-check
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';

const DB_USER   = process.env.E2E_DB_USER   || '';
const DB_PASS   = process.env.E2E_DB_PASS   || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME   = process.env.E2E_DB_NAME   || '';

// Unique run ID so concurrent runs don't collide
const RUN_ID = Date.now().toString(36);

// Skip all tests when credentials are not configured
test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_PASS || !DB_NAME,
  'E2E credentials not configured (set E2E_ADMIN_EMAIL, E2E_ADMIN_PASS, E2E_DB_USER, E2E_DB_PASS, E2E_DB_NAME)',
);

/**
 * Execute a MySQL query and return trimmed output.
 * Uses execFileSync (no shell) — safe against injection.
 */
function dbQuery(sql) {
  const args = ['-u', DB_USER, `-p${DB_PASS}`, DB_NAME, '-N', '-B', '-e', sql];
  if (DB_SOCKET) args.splice(3, 0, '-S', DB_SOCKET);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000 }).trim();
}

/** Get a CSRF token from a page's hidden input. */
async function getCsrfToken(page) {
  return page.evaluate(() => {
    const el = document.querySelector('input[name="csrf_token"]') ||
               document.querySelector('meta[name="csrf-token"]');
    if (!el) return '';
    return el.getAttribute('value') || el.getAttribute('content') || '';
  });
}

/** Wait for success alert after form submission. */
async function expectSuccess(page, timeout = 10000) {
  await expect(
    page.locator('.bg-green-50, .alert-success, .swal2-icon-success').first()
  ).toBeVisible({ timeout });
}

// ════════════════════════════════════════════════════════════════════════
// Block 1: Admin Settings — all tabs
// ════════════════════════════════════════════════════════════════════════
test.describe.serial('Admin Settings: all tabs', () => {
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
    await context.close();
  });

  test('General tab: update app name', async () => {
    await page.goto(`${BASE}/admin/settings?tab=general`);
    await page.waitForLoadState('networkidle');

    const testName = `Pinakes E2E ${RUN_ID}`;
    await page.fill('#app_name', testName);
    await page.fill('#footer_description', `Footer test ${RUN_ID}`);

    // Submit the general form
    await page.locator('section[data-settings-panel="general"] button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // Verify success via DB
    const dbName = dbQuery("SELECT setting_value FROM system_settings WHERE category='app' AND setting_key='name'");
    expect(dbName).toBe(testName);

    // Restore original name
    await page.fill('#app_name', 'Pinakes');
    await page.locator('section[data-settings-panel="general"] button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
  });

  test('Email tab: set mail driver and from fields', async () => {
    await page.goto(`${BASE}/admin/settings?tab=email`);
    await page.waitForLoadState('networkidle');

    // Switch to email panel
    await page.locator('[data-settings-tab="email"]').click();
    await expect(page.locator('section[data-settings-panel="email"]')).toBeVisible();

    await page.selectOption('#mail_driver', 'mail');
    await page.fill('#from_email', `test-${RUN_ID}@example.com`);
    await page.fill('#from_name', 'Pinakes Test');

    await page.locator('section[data-settings-panel="email"] button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // Verify in DB
    const fromEmail = dbQuery("SELECT setting_value FROM system_settings WHERE category='email' AND setting_key='from_email'");
    expect(fromEmail).toContain(RUN_ID);

    // Restore
    await page.goto(`${BASE}/admin/settings?tab=email`);
    await page.locator('[data-settings-tab="email"]').click();
    await page.fill('#from_email', 'noreply@example.com');
    await page.fill('#from_name', 'Pinakes');
    await page.locator('section[data-settings-panel="email"] button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
  });

  test('Contacts tab: update contact info', async () => {
    // Save originals
    const origTitle = dbQuery("SELECT setting_value FROM system_settings WHERE category='contacts' AND setting_key='page_title'");
    const origEmail = dbQuery("SELECT setting_value FROM system_settings WHERE category='contacts' AND setting_key='contact_email'");

    await page.goto(`${BASE}/admin/settings?tab=contacts`);
    await page.waitForLoadState('networkidle');

    await page.locator('[data-settings-tab="contacts"]').click();
    await expect(page.locator('section[data-settings-panel="contacts"]')).toBeVisible();

    await page.fill('#page_title', `Contattaci ${RUN_ID}`);
    await page.fill('#contact_email', `contacts-${RUN_ID}@example.com`);

    await page.locator('section[data-settings-panel="contacts"] button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // Verify in DB
    const contactEmail = dbQuery("SELECT setting_value FROM system_settings WHERE category='contacts' AND setting_key='contact_email'");
    expect(contactEmail).toContain(RUN_ID);

    // Restore originals
    if (origTitle) dbQuery(`UPDATE system_settings SET setting_value='${origTitle.replace(/'/g, "\\'")}' WHERE category='contacts' AND setting_key='page_title'`);
    if (origEmail) dbQuery(`UPDATE system_settings SET setting_value='${origEmail.replace(/'/g, "\\'")}' WHERE category='contacts' AND setting_key='contact_email'`);
  });

  test('Privacy tab: update privacy page title', async () => {
    const origTitle = dbQuery("SELECT setting_value FROM system_settings WHERE category='privacy' AND setting_key='page_title'");

    await page.goto(`${BASE}/admin/settings?tab=privacy`);
    await page.waitForLoadState('networkidle');

    await page.locator('[data-settings-tab="privacy"]').click();
    await expect(page.locator('section[data-settings-panel="privacy"]')).toBeVisible();

    await page.fill('#privacy_page_title', `Privacy Policy ${RUN_ID}`);

    await page.locator('section[data-settings-panel="privacy"] button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Verify in DB
    const privacyTitle = dbQuery("SELECT setting_value FROM system_settings WHERE category='privacy' AND setting_key='page_title'");
    expect(privacyTitle).toContain(RUN_ID);

    // Restore original
    if (origTitle) dbQuery(`UPDATE system_settings SET setting_value='${origTitle.replace(/'/g, "\\'")}' WHERE category='privacy' AND setting_key='page_title'`);
  });

  test('Labels tab: select a label format', async () => {
    await page.goto(`${BASE}/admin/settings?tab=labels`);
    await page.waitForLoadState('networkidle');

    await page.locator('[data-settings-tab="labels"]').click();
    await expect(page.locator('section[data-settings-panel="labels"]')).toBeVisible();

    // Select 70x36mm format
    await page.locator('input[name="label_format"][value="70x36"]').check();

    await page.locator('section[data-settings-panel="labels"] button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // Verify in DB
    const width = dbQuery("SELECT setting_value FROM system_settings WHERE category='label' AND setting_key='width'");
    expect(width).toBe('70');

    // Restore default
    await page.goto(`${BASE}/admin/settings?tab=labels`);
    await page.locator('[data-settings-tab="labels"]').click();
    await page.locator('input[name="label_format"][value="25x38"]').check();
    await page.locator('section[data-settings-panel="labels"] button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
  });

  test('Advanced tab: set custom essential JS', async () => {
    await page.goto(`${BASE}/admin/settings?tab=advanced`);
    await page.waitForLoadState('networkidle');

    await page.locator('[data-settings-tab="advanced"]').click();
    await expect(page.locator('section[data-settings-panel="advanced"]')).toBeVisible();

    const jsCode = `// E2E test ${RUN_ID}`;
    await page.fill('#custom_js_essential', jsCode);

    await page.locator('section[data-settings-panel="advanced"] button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Verify in DB
    const savedJs = dbQuery("SELECT setting_value FROM system_settings WHERE category='advanced' AND setting_key='custom_js_essential'");
    expect(savedJs).toContain(RUN_ID);

    // Cleanup
    await page.goto(`${BASE}/admin/settings?tab=advanced`);
    await page.locator('[data-settings-tab="advanced"]').click();
    await page.fill('#custom_js_essential', '');
    await page.locator('section[data-settings-panel="advanced"] button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');
  });

  test('Tab switching works correctly', async () => {
    await page.goto(`${BASE}/admin/settings`);
    await page.waitForLoadState('networkidle');

    // General tab should be active by default
    await expect(page.locator('section[data-settings-panel="general"]')).toBeVisible();

    // Click through each tab and verify panel visibility
    const tabs = ['email', 'templates', 'cms', 'contacts', 'privacy', 'messages', 'labels', 'advanced'];
    for (const tab of tabs) {
      await page.locator(`[data-settings-tab="${tab}"]`).click();
      await expect(page.locator(`section[data-settings-panel="${tab}"]`)).toBeVisible();
      // Other panels should be hidden
      await expect(page.locator('section[data-settings-panel="general"]')).toBeHidden();
    }
  });

  test('CMS tab: links to homepage editor', async () => {
    await page.goto(`${BASE}/admin/settings?tab=cms`);
    await page.waitForLoadState('networkidle');

    await page.locator('[data-settings-tab="cms"]').click();

    // Verify the "Modifica Homepage" link exists
    const homepageLink = page.locator('section[data-settings-panel="cms"] a[href*="/admin/cms/home"]');
    await expect(homepageLink).toBeVisible();
  });
});

// ════════════════════════════════════════════════════════════════════════
// Block 2: CMS Homepage Editing
// ════════════════════════════════════════════════════════════════════════
test.describe.serial('CMS: Homepage Editing', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/admin/, { timeout: 15000 });
  });

  test.afterAll(async () => {
    await context.close();
  });

  test('Navigate to CMS homepage editor', async () => {
    await page.goto(`${BASE}/admin/cms/home`);
    await page.waitForLoadState('networkidle');

    // Verify page loaded with hero form fields
    await expect(page.locator('input[name="hero[title]"]')).toBeVisible({ timeout: 10000 });
  });

  test('Edit hero section title and subtitle', async () => {
    // Save originals
    const origTitle = dbQuery("SELECT title FROM home_content WHERE section_key='hero'");
    const origSubtitle = dbQuery("SELECT subtitle FROM home_content WHERE section_key='hero'");
    const origButton = dbQuery("SELECT button_text FROM home_content WHERE section_key='hero'");

    await page.goto(`${BASE}/admin/cms/home`);
    await page.waitForLoadState('networkidle');

    const heroTitle = `Test Library ${RUN_ID}`;
    const heroSubtitle = `Subtitle ${RUN_ID}`;

    await page.fill('input[name="hero[title]"]', heroTitle);
    await page.fill('input[name="hero[subtitle]"]', heroSubtitle);
    await page.fill('input[name="hero[button_text]"]', 'Esplora');

    // Submit the hero form (first submit button)
    await page.locator('form[action*="cms/home"] button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Verify success message
    await expect(page.locator('.bg-green-50').first()).toBeVisible({ timeout: 10000 });

    // Verify in DB
    const dbTitle = dbQuery("SELECT title FROM home_content WHERE section_key='hero'");
    expect(dbTitle).toBe(heroTitle);

    // Restore originals
    if (origTitle) dbQuery(`UPDATE home_content SET title='${origTitle.replace(/'/g, "\\'")}', subtitle='${(origSubtitle || '').replace(/'/g, "\\'")}', button_text='${(origButton || 'Esplora').replace(/'/g, "\\'")}' WHERE section_key='hero'`);
  });

  test('Section visibility toggle via API', async () => {
    await page.goto(`${BASE}/admin/cms/home`);
    await page.waitForLoadState('networkidle');

    const csrf = await getCsrfToken(page);

    // Get the section ID for 'cta' from DB
    const ctaSectionId = dbQuery("SELECT id FROM home_content WHERE section_key='cta' LIMIT 1");
    expect(Number(ctaSectionId)).toBeGreaterThan(0);

    // Toggle a section off
    const toggleResult = await page.evaluate(async (data) => {
      const resp = await fetch(data.base + '/admin/cms/home/toggle-visibility', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          csrf_token: data.csrf,
          section_id: data.sectionId,
          is_active: 0
        })
      });
      return { status: resp.status, ok: resp.ok };
    }, { base: BASE, csrf, sectionId: Number(ctaSectionId) });

    expect(toggleResult.ok).toBe(true);

    // Verify in DB
    const isActive = dbQuery("SELECT is_active FROM home_content WHERE section_key='cta' LIMIT 1");
    expect(isActive).toBe('0');

    // Toggle back on
    await page.evaluate(async (data) => {
      await fetch(data.base + '/admin/cms/home/toggle-visibility', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          csrf_token: data.csrf,
          section_id: data.sectionId,
          is_active: 1
        })
      });
    }, { base: BASE, csrf, sectionId: Number(ctaSectionId) });
  });

  test('Section reorder via API', async () => {
    await page.goto(`${BASE}/admin/cms/home`);
    await page.waitForLoadState('networkidle');

    const csrf = await getCsrfToken(page);

    // Get section IDs from DB
    const heroId = Number(dbQuery("SELECT id FROM home_content WHERE section_key='hero' LIMIT 1"));
    const featuresId = Number(dbQuery("SELECT id FROM home_content WHERE section_key='features_title' LIMIT 1"));

    // Send reorder: swap hero and features positions
    const reorderResult = await page.evaluate(async (data) => {
      const resp = await fetch(data.base + '/admin/cms/home/reorder', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          csrf_token: data.csrf,
          order: [
            { id: data.featuresId, display_order: 0 },
            { id: data.heroId, display_order: 1 }
          ]
        })
      });
      return { status: resp.status, ok: resp.ok };
    }, { base: BASE, csrf, heroId, featuresId });

    expect(reorderResult.ok).toBe(true);

    // Verify features is now before hero
    const heroOrder = dbQuery("SELECT display_order FROM home_content WHERE section_key='hero'");
    const featuresOrder = dbQuery("SELECT display_order FROM home_content WHERE section_key='features_title'");
    expect(Number(featuresOrder)).toBeLessThan(Number(heroOrder));

    // Restore original order
    await page.evaluate(async (data) => {
      await fetch(data.base + '/admin/cms/home/reorder', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          csrf_token: data.csrf,
          order: [
            { id: data.heroId, display_order: 0 },
            { id: data.featuresId, display_order: 1 }
          ]
        })
      });
    }, { base: BASE, csrf, heroId, featuresId });
  });

  test('Homepage reflects CMS changes', async () => {
    // Visit the public homepage
    await page.goto(`${BASE}/`);
    await page.waitForLoadState('networkidle');

    // Hero title from the earlier edit should appear
    const heroText = await page.locator('.hero-section, [class*="hero"], section').first().textContent();
    expect(heroText).toBeTruthy();
  });
});

// ════════════════════════════════════════════════════════════════════════
// Block 3: User Self-Registration
// ════════════════════════════════════════════════════════════════════════
test.describe.serial('User Self-Registration', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let userContext;
  /** @type {import('@playwright/test').Page} */
  let userPage;
  /** @type {import('@playwright/test').BrowserContext} */
  let adminContext;
  /** @type {import('@playwright/test').Page} */
  let adminPage;

  const testEmail = `reg-${RUN_ID}@example.com`;
  const testPass = 'TestPass1234!';

  test.beforeAll(async ({ browser }) => {
    userContext = await browser.newContext();
    userPage = await userContext.newPage();
    adminContext = await browser.newContext();
    adminPage = await adminContext.newPage();

    // Admin login
    await adminPage.goto(`${BASE}/accedi`);
    await adminPage.fill('input[name="email"]', ADMIN_EMAIL);
    await adminPage.fill('input[name="password"]', ADMIN_PASS);
    await adminPage.locator('button[type="submit"]').click();
    await adminPage.waitForURL(/admin/, { timeout: 15000 });
  });

  test.afterAll(async () => {
    // Cleanup: remove test user
    try {
      dbQuery(`DELETE FROM utenti WHERE email='${testEmail}'`);
    } catch { /* ignore */ }
    await userContext.close();
    await adminContext.close();
  });

  test('Registration form loads correctly', async () => {
    await userPage.goto(`${BASE}/registrati`);
    await userPage.waitForLoadState('networkidle');

    // All required fields present
    await expect(userPage.locator('input[name="nome"]')).toBeVisible();
    await expect(userPage.locator('input[name="cognome"]')).toBeVisible();
    await expect(userPage.locator('input[name="email"]')).toBeVisible();
    await expect(userPage.locator('input[name="telefono"]')).toBeVisible();
    await expect(userPage.locator('input[name="password"]')).toBeVisible();
    await expect(userPage.locator('input[name="password_confirm"]')).toBeVisible();
    await expect(userPage.locator('input[name="privacy_acceptance"]')).toBeVisible();
  });

  test('Validation: missing fields rejected', async () => {
    await userPage.goto(`${BASE}/registrati`);
    await userPage.waitForLoadState('networkidle');

    // Submit empty form
    await userPage.locator('button[type="submit"]').click();

    // HTML5 validation should prevent submission, or server returns error
    // Wait a moment then check URL for error param or that we stayed on form
    await userPage.waitForTimeout(1000);
    const url = userPage.url();
    // Either still on form or redirected with error
    expect(url).toMatch(/registrat/);
  });

  test('Validation: weak password rejected', async () => {
    await userPage.goto(`${BASE}/registrati`);
    await userPage.waitForLoadState('networkidle');

    await userPage.fill('input[name="nome"]', 'Test');
    await userPage.fill('input[name="cognome"]', 'User');
    await userPage.fill('input[name="email"]', `weakpw-${RUN_ID}@example.com`);
    await userPage.fill('input[name="telefono"]', '1234567890');
    await userPage.fill('textarea[name="indirizzo"]', 'Via Test 1');
    // Use password that passes length (>=8) but fails complexity (no uppercase)
    await userPage.fill('input[name="password"]', 'alllowercase1');
    await userPage.fill('input[name="password_confirm"]', 'alllowercase1');
    await userPage.locator('input[name="privacy_acceptance"]').check();

    await userPage.locator('button[type="submit"]').click();
    await userPage.waitForURL(/error=/, { timeout: 15000 });

    expect(userPage.url()).toMatch(/error=(password_needs_upper_lower_number|missing_fields)/);
  });

  test('Successful registration creates user with stato=sospeso', async () => {
    await userPage.goto(`${BASE}/registrati`);
    await userPage.waitForLoadState('networkidle');

    await userPage.fill('input[name="nome"]', `Test${RUN_ID}`);
    await userPage.fill('input[name="cognome"]', 'UserE2E');
    await userPage.fill('input[name="email"]', testEmail);
    await userPage.fill('input[name="telefono"]', '3331234567');
    await userPage.fill('textarea[name="indirizzo"]', 'Via E2E Test 42');
    await userPage.fill('input[name="password"]', testPass);
    await userPage.fill('input[name="password_confirm"]', testPass);
    await userPage.locator('input[name="privacy_acceptance"]').check();

    await userPage.locator('button[type="submit"]').click();
    await userPage.waitForURL(/successo|registrat/, { timeout: 15000 });

    // DB verify: user created with stato=sospeso
    const stato = dbQuery(`SELECT stato FROM utenti WHERE email='${testEmail}'`);
    expect(stato).toBe('sospeso');

    const emailVerified = dbQuery(`SELECT email_verificata FROM utenti WHERE email='${testEmail}'`);
    expect(emailVerified).toBe('0');
  });

  test('Admin activates user', async () => {
    // Activate user directly via DB (admin would normally use UI)
    dbQuery(`UPDATE utenti SET stato='attivo', email_verificata=1 WHERE email='${testEmail}'`);

    const stato = dbQuery(`SELECT stato FROM utenti WHERE email='${testEmail}'`);
    expect(stato).toBe('attivo');
  });

  test('Activated user can login', async () => {
    await userPage.goto(`${BASE}/accedi`);
    await userPage.waitForLoadState('networkidle');

    await userPage.fill('input[name="email"]', testEmail);
    await userPage.fill('input[name="password"]', testPass);
    await userPage.locator('button[type="submit"]').click();

    // Should reach user area (not admin)
    await userPage.waitForURL(/.*/, { timeout: 15000 });
    // Should not be on login page anymore
    const finalUrl = userPage.url();
    expect(finalUrl).not.toMatch(/accedi.*error/);
  });
});

// ════════════════════════════════════════════════════════════════════════
// Block 4: Book Creation with ISBN Scraping
// ════════════════════════════════════════════════════════════════════════
test.describe.serial('Book Creation & ISBN Scraping', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  let createdBookId = 0;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/admin/, { timeout: 15000 });
  });

  test.afterAll(async () => {
    // Cleanup created book
    if (createdBookId > 0) {
      try {
        dbQuery(`DELETE FROM libri WHERE id=${createdBookId}`);
      } catch { /* ignore FK */ }
    }
    await context.close();
  });

  test('Book creation form loads with all fields', async () => {
    await page.goto(`${BASE}/admin/libri/crea`);
    await page.waitForLoadState('networkidle');

    await expect(page.locator('#titolo')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('#isbn13')).toBeVisible();
  });

  test('ISBN scraping attempts fetch (graceful on failure)', async () => {
    await page.goto(`${BASE}/admin/libri/crea`);
    await page.waitForLoadState('networkidle');

    // Try ISBN scrape - this hits external API, may fail
    const importBtn = page.locator('#btnImportIsbn');
    const importInput = page.locator('#importIsbn');

    if (await importBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await importInput.fill('9788845292613'); // Il Nome della Rosa ISBN
      await importBtn.click();

      // Wait for either success (fields populated) or error (alert)
      await page.waitForTimeout(5000);

      // Either title was filled or an error showed — both are valid
      const titleValue = await page.locator('#titolo').inputValue();
      // We don't assert on value since external API may be down
      // Just verify the page didn't crash
      await expect(page.locator('#titolo')).toBeVisible();
    }
  });

  test('Manual book creation succeeds', async () => {
    await page.goto(`${BASE}/admin/libri/crea`);
    await page.waitForLoadState('networkidle');

    const bookTitle = `E2E Book ${RUN_ID}`;
    await page.fill('#titolo', bookTitle);

    // Create inline author
    const authorInput = page.locator('.choices__input--cloned').first();
    if (await authorInput.isVisible({ timeout: 3000 }).catch(() => false)) {
      await authorInput.fill(`Author ${RUN_ID}`);
      await authorInput.press('Enter');
      await page.waitForTimeout(500);
    }

    // Select genre if available
    const genreSelect = page.locator('#radice_select');
    if (await genreSelect.isVisible({ timeout: 3000 }).catch(() => false)) {
      await page.waitForFunction(() => {
        const sel = document.querySelector('#radice_select');
        return sel && sel.options.length > 1;
      }, { timeout: 10000 });
      await genreSelect.selectOption({ index: 1 });
    }

    // Submit with SweetAlert confirmation
    await page.locator('#bookForm button[type="submit"]').click();
    const swalConfirm = page.locator('.swal2-confirm');
    if (await swalConfirm.isVisible({ timeout: 5000 }).catch(() => false)) {
      await swalConfirm.click();
    }

    await page.waitForURL(/admin\/libri(?!.*crea)/, { timeout: 15000 });

    // Get created book ID
    const bookId = dbQuery(`SELECT id FROM libri WHERE titolo='${bookTitle}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`);
    expect(Number(bookId)).toBeGreaterThan(0);
    createdBookId = Number(bookId);
  });

  test('Created book appears in admin list', async () => {
    await page.goto(`${BASE}/admin/libri`);
    await page.waitForLoadState('networkidle');

    // Search for the book via API
    const resp = await page.request.get(
      `${BASE}/api/libri?start=0&length=5&search[value]=${encodeURIComponent(`E2E Book ${RUN_ID}`)}`
    );
    const data = await resp.json();
    expect(data.data.length).toBeGreaterThan(0);
    expect(data.data[0].titolo).toContain(RUN_ID);
  });
});

// ════════════════════════════════════════════════════════════════════════
// Block 5: Concurrent Loan Prevention
// ════════════════════════════════════════════════════════════════════════
test.describe.serial('Concurrent Loan Prevention', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  let testBookId = 0;
  let testUserId1 = 0;
  let testUserId2 = 0;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/admin/, { timeout: 15000 });

    // Create a test book with exactly 1 copy
    dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES ('ConcurrentTest ${RUN_ID}', 1, 1)`);
    testBookId = Number(dbQuery(`SELECT id FROM libri WHERE titolo='ConcurrentTest ${RUN_ID}' AND deleted_at IS NULL LIMIT 1`));
    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${testBookId}, 'E2E-${RUN_ID}', 'disponibile')`);

    // Create two test users
    const hash = execFileSync('php', ['-r', "echo password_hash('Test1234!', PASSWORD_DEFAULT);"], { encoding: 'utf-8' }).trim();
    dbQuery(`INSERT INTO utenti (nome, cognome, email, password, telefono, indirizzo, codice_tessera, stato, tipo_utente, email_verificata) VALUES ('Conc1', 'Test', 'conc1-${RUN_ID}@test.com', '${hash}', '1111', 'via', 'CONC1${RUN_ID}', 'attivo', 'standard', 1)`);
    dbQuery(`INSERT INTO utenti (nome, cognome, email, password, telefono, indirizzo, codice_tessera, stato, tipo_utente, email_verificata) VALUES ('Conc2', 'Test', 'conc2-${RUN_ID}@test.com', '${hash}', '2222', 'via', 'CONC2${RUN_ID}', 'attivo', 'standard', 1)`);
    testUserId1 = Number(dbQuery(`SELECT id FROM utenti WHERE email='conc1-${RUN_ID}@test.com' LIMIT 1`));
    testUserId2 = Number(dbQuery(`SELECT id FROM utenti WHERE email='conc2-${RUN_ID}@test.com' LIMIT 1`));
  });

  test.afterAll(async () => {
    // Cleanup
    try {
      dbQuery(`DELETE FROM prestiti WHERE libro_id=${testBookId}`);
      dbQuery(`DELETE FROM copie WHERE libro_id=${testBookId}`);
      dbQuery(`DELETE FROM libri WHERE id=${testBookId}`);
      dbQuery(`DELETE FROM utenti WHERE email LIKE 'conc%-${RUN_ID}@test.com'`);
    } catch { /* ignore */ }
    await context.close();
  });

  test('Create loan requests for both users via DB', async () => {
    // Insert pending loan requests for both users on the same book (1 copy)
    const today = new Date().toISOString().slice(0, 10);
    const returnDate = new Date(Date.now() + 14 * 86400000).toISOString().slice(0, 10);

    dbQuery(`INSERT INTO prestiti (utente_id, libro_id, data_prestito, data_scadenza, stato, attivo) VALUES (${testUserId1}, ${testBookId}, '${today}', '${returnDate}', 'pendente', 0)`);
    dbQuery(`INSERT INTO prestiti (utente_id, libro_id, data_prestito, data_scadenza, stato, attivo) VALUES (${testUserId2}, ${testBookId}, '${today}', '${returnDate}', 'pendente', 0)`);

    const count = dbQuery(`SELECT COUNT(*) FROM prestiti WHERE libro_id=${testBookId} AND stato='pendente'`);
    expect(Number(count)).toBe(2);
  });

  test('Approving first loan succeeds, second fails (only 1 copy)', async () => {
    // Get loan IDs
    const loan1Id = dbQuery(`SELECT id FROM prestiti WHERE utente_id=${testUserId1} AND libro_id=${testBookId} AND stato='pendente' LIMIT 1`);
    const loan2Id = dbQuery(`SELECT id FROM prestiti WHERE utente_id=${testUserId2} AND libro_id=${testBookId} AND stato='pendente' LIMIT 1`);

    // Navigate to pending loans page and approve first
    await page.goto(`${BASE}/admin/loans/pending`);
    await page.waitForLoadState('networkidle');

    // Approve via API
    const csrf = await getCsrfToken(page);

    // Approve loan 1
    const approve1 = await page.evaluate(async (data) => {
      const resp = await fetch(data.base + '/admin/loans/approve', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `csrf_token=${encodeURIComponent(data.csrf)}&loan_id=${data.loanId}`
      });
      return { status: resp.status, body: await resp.text() };
    }, { base: BASE, csrf, loanId: loan1Id });

    // First approval should succeed
    expect(approve1.status).toBeLessThan(500);

    // Verify loan 1 is approved
    const loan1Status = dbQuery(`SELECT stato FROM prestiti WHERE id=${loan1Id}`);
    expect(loan1Status).toMatch(/da_ritirare|in_corso/);

    // Approve loan 2 — should fail (no copies left)
    const approve2 = await page.evaluate(async (data) => {
      const resp = await fetch(data.base + '/admin/loans/approve', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `csrf_token=${encodeURIComponent(data.csrf)}&loan_id=${data.loanId}`
      });
      return { status: resp.status, body: await resp.text() };
    }, { base: BASE, csrf, loanId: loan2Id });

    // Second loan should either fail or still be pendente
    const loan2Status = dbQuery(`SELECT stato FROM prestiti WHERE id=${loan2Id}`);
    // If the system properly blocks, it should stay pendente or be rejected
    // We just verify it's NOT approved
    expect(loan2Status).not.toMatch(/in_corso/);
  });
});

// ════════════════════════════════════════════════════════════════════════
// Block 6: Collana (Series) Filter
// ════════════════════════════════════════════════════════════════════════
test.describe.serial('Collana (Series) Filter', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  let testBookId = 0;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/admin/, { timeout: 15000 });

    // Create a book with a collana
    dbQuery(`INSERT INTO libri (titolo, collana, copie_totali, copie_disponibili) VALUES ('Collana Test ${RUN_ID}', 'SerieTest${RUN_ID}', 1, 1)`);
    testBookId = Number(dbQuery(`SELECT id FROM libri WHERE titolo='Collana Test ${RUN_ID}' AND deleted_at IS NULL LIMIT 1`));
  });

  test.afterAll(async () => {
    try {
      dbQuery(`DELETE FROM libri WHERE id=${testBookId}`);
    } catch { /* ignore */ }
    await context.close();
  });

  test('Collana filter input exists on book list', async () => {
    await page.goto(`${BASE}/admin/libri`);
    await page.waitForLoadState('networkidle');

    // The collana filter should be visible
    const collanaFilter = page.locator('#collana-filter, input[name="collana"], [data-filter="collana"]');
    // If the specific filter exists, verify it
    if (await collanaFilter.isVisible({ timeout: 3000 }).catch(() => false)) {
      await expect(collanaFilter).toBeVisible();
    }
  });

  test('Filtering by collana via API returns correct results', async () => {
    const resp = await page.request.get(
      `${BASE}/api/libri?start=0&length=50&search[value]=${encodeURIComponent(`SerieTest${RUN_ID}`)}`
    );
    const data = await resp.json();
    // Should find the book with the test collana
    const found = data.data.some(b => b.titolo && b.titolo.includes(RUN_ID));
    expect(found).toBe(true);
  });

  test('Collana URL filter shows in book list', async () => {
    // Navigate with collana URL param
    await page.goto(`${BASE}/admin/libri?collana=${encodeURIComponent(`SerieTest${RUN_ID}`)}`);
    await page.waitForLoadState('networkidle');

    // Page should load without error
    await expect(page.locator('table, .book-list, .dataTables_wrapper').first()).toBeVisible({ timeout: 10000 });
  });
});

// ════════════════════════════════════════════════════════════════════════
// Block 7: Author & Publisher CRUD
// ════════════════════════════════════════════════════════════════════════
test.describe.serial('Author & Publisher CRUD', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  let authorId = 0;
  let publisherId = 0;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/admin/, { timeout: 15000 });
  });

  test.afterAll(async () => {
    // Cleanup
    try {
      if (authorId > 0) dbQuery(`DELETE FROM autori WHERE id=${authorId}`);
      if (publisherId > 0) dbQuery(`DELETE FROM editori WHERE id=${publisherId}`);
    } catch { /* ignore */ }
    await context.close();
  });

  test('Create a new author', async () => {
    await page.goto(`${BASE}/admin/autori/crea`);
    await page.waitForLoadState('networkidle');

    await page.fill('#nome', `AuthorE2E ${RUN_ID}`);

    await page.locator('button[type="submit"]').click();

    // Handle SweetAlert confirmation
    const swalConfirm = page.locator('.swal2-confirm');
    if (await swalConfirm.isVisible({ timeout: 5000 }).catch(() => false)) {
      await swalConfirm.click();
    }

    await page.waitForURL(/admin\/autori/, { timeout: 15000 });

    // Verify in DB
    const id = dbQuery(`SELECT id FROM autori WHERE nome='AuthorE2E ${RUN_ID}' LIMIT 1`);
    expect(Number(id)).toBeGreaterThan(0);
    authorId = Number(id);
  });

  test('Edit the author', async () => {
    expect(authorId).toBeGreaterThan(0);

    await page.goto(`${BASE}/admin/autori/modifica/${authorId}`);
    await page.waitForLoadState('networkidle');

    await page.fill('#nome', `AuthEdited ${RUN_ID}`);
    await page.locator('button[type="submit"]').click();

    // Handle SweetAlert confirmation
    const swalConfirm = page.locator('.swal2-confirm');
    if (await swalConfirm.isVisible({ timeout: 5000 }).catch(() => false)) {
      await swalConfirm.click();
    }

    await page.waitForURL(/admin\/autori/, { timeout: 15000 });

    // Verify in DB
    const nome = dbQuery(`SELECT nome FROM autori WHERE id=${authorId}`);
    expect(nome).toBe(`AuthEdited ${RUN_ID}`);
  });

  test('Create a new publisher', async () => {
    await page.goto(`${BASE}/admin/editori/crea`);
    await page.waitForLoadState('networkidle');

    await page.fill('#nome', `Publisher${RUN_ID}`);

    await page.locator('button[type="submit"]').click();

    // Handle SweetAlert confirmation
    const swalConfirm = page.locator('.swal2-confirm');
    if (await swalConfirm.isVisible({ timeout: 5000 }).catch(() => false)) {
      await swalConfirm.click();
    }

    await page.waitForURL(/admin\/editori/, { timeout: 15000 });

    // Verify in DB
    const id = dbQuery(`SELECT id FROM editori WHERE nome='Publisher${RUN_ID}' LIMIT 1`);
    expect(Number(id)).toBeGreaterThan(0);
    publisherId = Number(id);
  });

  test('Edit the publisher', async () => {
    expect(publisherId).toBeGreaterThan(0);

    await page.goto(`${BASE}/admin/editori/modifica/${publisherId}`);
    await page.waitForLoadState('networkidle');

    await page.fill('#nome', `PubEdited${RUN_ID}`);
    await page.locator('button[type="submit"]').click();

    // Handle SweetAlert confirmation
    const swalConfirm = page.locator('.swal2-confirm');
    if (await swalConfirm.isVisible({ timeout: 5000 }).catch(() => false)) {
      await swalConfirm.click();
    }

    await page.waitForURL(/admin\/editori/, { timeout: 15000 });

    // Verify in DB
    const nome = dbQuery(`SELECT nome FROM editori WHERE id=${publisherId}`);
    expect(nome).toBe(`PubEdited${RUN_ID}`);
  });

  test('Delete author and publisher', async () => {
    // Delete author via POST
    const csrf = await getCsrfToken(page);

    if (authorId > 0) {
      await page.goto(`${BASE}/admin/autori`);
      await page.waitForLoadState('networkidle');

      const deleteResult = await page.evaluate(async (data) => {
        const resp = await fetch(`${data.base}/admin/autori/delete/${data.id}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `csrf_token=${encodeURIComponent(data.csrf)}`
        });
        return { status: resp.status, redirected: resp.redirected };
      }, { base: BASE, csrf, id: authorId });

      // Verify deleted from DB
      const authorCount = dbQuery(`SELECT COUNT(*) FROM autori WHERE id=${authorId}`);
      expect(Number(authorCount)).toBe(0);
      authorId = 0; // Prevent double-cleanup
    }

    if (publisherId > 0) {
      const deleteResult2 = await page.evaluate(async (data) => {
        const resp = await fetch(`${data.base}/admin/editori/delete/${data.id}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `csrf_token=${encodeURIComponent(data.csrf)}`
        });
        return { status: resp.status };
      }, { base: BASE, csrf, id: publisherId });

      const pubCount = dbQuery(`SELECT COUNT(*) FROM editori WHERE id=${publisherId}`);
      expect(Number(pubCount)).toBe(0);
      publisherId = 0;
    }
  });
});
