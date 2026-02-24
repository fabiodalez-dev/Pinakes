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

const RUN_ID = Date.now().toString(36);

test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_PASS || !DB_NAME,
  'E2E credentials not configured',
);

function dbQuery(sql) {
  const args = ['-u', DB_USER, `-p${DB_PASS}`, DB_NAME, '-N', '-B', '-e', sql];
  if (DB_SOCKET) args.splice(3, 0, '-S', DB_SOCKET);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000 }).trim();
}

async function getCsrfToken(page) {
  return page.evaluate(() => {
    const el = document.querySelector('input[name="csrf_token"]') ||
               document.querySelector('meta[name="csrf-token"]');
    if (!el) return '';
    return el.getAttribute('value') || el.getAttribute('content') || '';
  });
}

/** Generate a bcrypt hash using PHP CLI — avoids shell escaping issues. */
function phpHash(password) {
  return execFileSync('php', ['-r', `echo password_hash('${password}', PASSWORD_BCRYPT, ['cost' => 10]);`], { encoding: 'utf-8', timeout: 5000 }).trim();
}

function todayISO() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

// ════════════════════════════════════════════════════════════════════════
// Group 1: Public Frontend (4 tests)
// ════════════════════════════════════════════════════════════════════════
test.describe.serial('Public Frontend', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
  });
  test.afterAll(async () => { await context.close(); });

  test('Test 1: Catalog page loads with search and grid', async () => {
    const resp = await page.goto(`${BASE}/catalogo`);
    await page.waitForLoadState('networkidle');

    // Verify page loads successfully
    expect(resp.status()).toBe(200);

    // Verify key elements are present
    const searchInput = page.locator('#search-input');
    await expect(searchInput).toBeVisible({ timeout: 5000 });
    const booksGrid = page.locator('#books-grid');
    await expect(booksGrid).toBeVisible({ timeout: 5000 });
    const totalCount = page.locator('#total-count');
    await expect(totalCount).toBeVisible({ timeout: 5000 });

    // Verify total count is a positive number
    const countText = await totalCount.textContent();
    expect(parseInt(countText.replace(/\D/g, ''))).toBeGreaterThan(0);

    // Test search via URL parameter (server-side filtering)
    const searchResp = await page.goto(`${BASE}/catalogo?q=zzz-nonexistent-xyz`);
    await page.waitForLoadState('networkidle');
    expect(searchResp.status()).toBe(200);
    // Count should be 0 for nonsense search
    const filteredCount = await page.locator('#total-count').textContent();
    expect(parseInt(filteredCount.replace(/\D/g, ''))).toBe(0);
  });

  test('Test 2: Public events list loads', async () => {
    // Seed an event
    const eventTitle = `E2E Event ${RUN_ID}`;
    const eventSlug = `e2e-event-${RUN_ID}`;
    dbQuery(`INSERT INTO events (title, slug, event_date, is_active, content, created_at) VALUES ('${eventTitle}', '${eventSlug}', '${todayISO()}', 1, '<p>Test event content</p>', NOW())`);

    try {
      // Visit events page (try both Italian and English routes)
      const resp = await page.goto(`${BASE}/eventi`);
      if (!resp || resp.status() === 404) {
        await page.goto(`${BASE}/events`);
      }

      await page.waitForLoadState('networkidle');
      // Verify the seeded event title appears on the page
      await expect(page.locator(`text=${eventTitle}`)).toBeVisible({ timeout: 5000 });
    } finally {
      dbQuery(`DELETE FROM events WHERE slug = '${eventSlug}'`);
    }
  });

  test('Test 3: Contact form submission', async () => {
    const testEmail = `e2e-contact-${RUN_ID}@test.local`;

    // Navigate to contact page
    const resp = await page.goto(`${BASE}/contatti`);
    if (!resp || resp.status() === 404) {
      await page.goto(`${BASE}/contattaci`);
    }
    await page.waitForLoadState('networkidle');

    // Fill form fields
    await page.fill('#nome', 'E2E Test');
    await page.fill('#cognome', `Contact ${RUN_ID}`);
    await page.fill('#email', testEmail);
    await page.fill('#telefono', '3331234567');
    await page.fill('textarea#messaggio', `Automated contact test ${RUN_ID}`);
    await page.check('#privacy');

    // Submit form — target the contact form's submit button specifically
    await page.locator('#contact-form button[type="submit"], form[id*="contact"] button[type="submit"], form[action*="contatt"] button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Verify redirect with success
    expect(page.url()).toContain('success');

    // DB verify
    try {
      const row = dbQuery(`SELECT email FROM contact_messages WHERE email = '${testEmail}'`);
      expect(row).toContain(testEmail);
    } finally {
      dbQuery(`DELETE FROM contact_messages WHERE email = '${testEmail}'`);
    }
  });

  test('Test 4: Author archive page shows books', async () => {
    // Get an author that has books (autori table has single 'nome' field for full name)
    const authorData = dbQuery(`SELECT a.id, a.nome FROM autori a INNER JOIN libri_autori la ON a.id = la.autore_id INNER JOIN libri l ON la.libro_id = l.id AND l.deleted_at IS NULL LIMIT 1`);
    test.skip(!authorData, 'No authors with books found');

    const [authorId, nome] = authorData.split('\t');

    await page.goto(`${BASE}/autore/${authorId}`);
    await page.waitForLoadState('networkidle');

    // Verify page loads (not 404/500)
    const heading = page.locator('h1');
    await expect(heading).toBeVisible({ timeout: 5000 });

    // Verify at least one book card or book reference exists
    const bookElements = page.locator('.book-card, .grid a[href*="/libro/"]');
    const count = await bookElements.count();
    expect(count).toBeGreaterThan(0);
  });
});

// ════════════════════════════════════════════════════════════════════════
// Group 2: User Profile & Dashboard (4 tests)
// ════════════════════════════════════════════════════════════════════════
test.describe.serial('User Profile & Dashboard', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  let testUserId = '';
  const testUserEmail = `e2e-profile-${RUN_ID}@test.local`;
  const testUserPass = 'Test1234!';

  test.beforeAll(async ({ browser }) => {
    // Create a test user via DB
    const passHash = phpHash(testUserPass);
    dbQuery(`INSERT INTO utenti (nome, cognome, email, password, tipo_utente, stato, codice_tessera, privacy_accettata, email_verificata, created_at) VALUES ('E2E', 'Profile${RUN_ID}', '${testUserEmail}', '${passHash}', 'standard', 'attivo', 'E2E-${RUN_ID}', 1, 1, NOW())`);
    testUserId = dbQuery(`SELECT id FROM utenti WHERE email = '${testUserEmail}'`);

    // Login as test user
    context = await browser.newContext();
    page = await context.newPage();
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', testUserEmail);
    await page.fill('input[name="password"]', testUserPass);
    await page.locator('button[type="submit"]').click();
    // Wait for navigation away from login page
    await page.waitForFunction(
      () => !window.location.pathname.includes('accedi') && !window.location.pathname.includes('login'),
      { timeout: 15000 },
    );
  });

  test.afterAll(async () => {
    await context.close();
    if (testUserId) {
      dbQuery(`DELETE FROM wishlist WHERE utente_id = ${testUserId}`);
      dbQuery(`DELETE FROM user_sessions WHERE utente_id = ${testUserId}`);
      dbQuery(`DELETE FROM utenti WHERE id = ${testUserId}`);
    }
  });

  test('Test 5: User dashboard loads with stats', async () => {
    const resp = await page.goto(`${BASE}/utente/bacheca`);
    await page.waitForLoadState('networkidle');

    // Dashboard should load successfully
    expect(resp.status()).toBe(200);

    // Verify stat cards or dashboard content is visible
    const statCards = page.locator('.stat-card, .stat-number, .dashboard-hero');
    const count = await statCards.count();
    expect(count).toBeGreaterThan(0);
  });

  test('Test 6: User profile edit — change phone number', async () => {
    await page.goto(`${BASE}/profilo`);
    await page.waitForLoadState('networkidle');

    const newPhone = `333${Date.now().toString().slice(-7)}`;
    await page.fill('#telefono', newPhone);

    // Submit profile update form
    const form = page.locator('form[action*="aggiorna"], form[action*="update"]').first();
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // DB verify
    const dbPhone = dbQuery(`SELECT telefono FROM utenti WHERE id = ${testUserId}`);
    expect(dbPhone).toBe(newPhone);
  });

  test('Test 7: User change password', async () => {
    // Get current password hash for later comparison
    const oldHash = dbQuery(`SELECT password FROM utenti WHERE id = ${testUserId}`);

    await page.goto(`${BASE}/profilo`);
    await page.waitForLoadState('networkidle');

    // Fill password change form
    const newPassword = 'NewPass9876!';
    await page.fill('#password', newPassword);
    await page.fill('#password_confirm', newPassword);

    // Submit password form
    const pwForm = page.locator('form[action*="password"]').first();
    await pwForm.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // DB verify: hash should have changed
    const newHash = dbQuery(`SELECT password FROM utenti WHERE id = ${testUserId}`);
    expect(newHash).not.toBe(oldHash);

    // Restore original password via direct DB update
    dbQuery(`UPDATE utenti SET password = '${oldHash.replace(/'/g, "\\'")}' WHERE id = ${testUserId}`);
  });

  test('Test 8: Wishlist toggle + wishlist page', async () => {
    // Get a book ID
    const bookId = dbQuery(`SELECT id FROM libri WHERE deleted_at IS NULL LIMIT 1`);
    test.skip(!bookId, 'No books found');

    // Need a page with CSRF token
    await page.goto(`${BASE}/catalogo`);
    await page.waitForLoadState('networkidle');
    const csrf = await getCsrfToken(page);

    // Toggle wishlist ON via API
    const addResult = await page.evaluate(async ({ bookId, csrf, base }) => {
      const resp = await fetch(`${base}/api/user/wishlist/toggle`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ libro_id: bookId, csrf_token: csrf }),
      });
      return resp.json();
    }, { bookId, csrf, base: BASE });
    expect(addResult.favorite).toBe(true);

    // DB verify: row exists
    const wishRow = dbQuery(`SELECT COUNT(*) FROM wishlist WHERE utente_id = ${testUserId} AND libro_id = ${bookId}`);
    expect(parseInt(wishRow)).toBe(1);

    // Visit wishlist page
    const wishResp = await page.goto(`${BASE}/lista-desideri`);
    await page.waitForLoadState('networkidle');
    expect(wishResp.status()).toBe(200);

    // Toggle wishlist OFF
    await page.goto(`${BASE}/catalogo`);
    await page.waitForLoadState('networkidle');
    const csrf2 = await getCsrfToken(page);

    const removeResult = await page.evaluate(async ({ bookId, csrf, base }) => {
      const resp = await fetch(`${base}/api/user/wishlist/toggle`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ libro_id: bookId, csrf_token: csrf }),
      });
      return resp.json();
    }, { bookId, csrf: csrf2, base: BASE });
    expect(removeResult.favorite).toBe(false);

    // DB verify: row removed
    const wishRow2 = dbQuery(`SELECT COUNT(*) FROM wishlist WHERE utente_id = ${testUserId} AND libro_id = ${bookId}`);
    expect(parseInt(wishRow2)).toBe(0);
  });
});

// ════════════════════════════════════════════════════════════════════════
// Group 3: Admin User Management (2 tests)
// ════════════════════════════════════════════════════════════════════════
test.describe.serial('Admin User Management', () => {
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
  test.afterAll(async () => { await context.close(); });

  test('Test 9: Admin creates a user manually', async () => {
    const newEmail = `e2e-newuser-${RUN_ID}@test.local`;

    await page.goto(`${BASE}/admin/utenti/crea`);
    await page.waitForLoadState('networkidle');

    await page.fill('input[name="nome"]', 'E2E');
    await page.fill('input[name="cognome"]', `NewUser${RUN_ID}`);
    await page.fill('input[name="email"]', newEmail);
    await page.fill('input[name="telefono"]', '3339999999');

    // Select user type
    const tipoSelect = page.locator('select[name="tipo_utente"]');
    if (await tipoSelect.isVisible().catch(() => false)) {
      await tipoSelect.selectOption('standard');
    }

    // Set password
    const pwField = page.locator('input[name="password"]');
    if (await pwField.isVisible().catch(() => false)) {
      await pwField.fill('Test1234!');
    }

    await page.locator('button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Verify redirect (not still on create page)
    expect(page.url()).not.toContain('/crea');

    // DB verify
    try {
      const userId = dbQuery(`SELECT id FROM utenti WHERE email = '${newEmail}'`);
      expect(userId).toBeTruthy();
    } finally {
      dbQuery(`DELETE FROM user_sessions WHERE utente_id IN (SELECT id FROM utenti WHERE email = '${newEmail}')`);
      dbQuery(`DELETE FROM utenti WHERE email = '${newEmail}'`);
    }
  });

  test('Test 10: Admin soft-deletes a book', async () => {
    // Create a test book via DB
    const bookTitle = `E2E Delete Test ${RUN_ID}`;
    dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at) VALUES ('${bookTitle}', 1, 1, NOW())`);
    const bookId = dbQuery(`SELECT id FROM libri WHERE titolo = '${bookTitle}' AND deleted_at IS NULL`);
    test.skip(!bookId, 'Could not create test book');

    try {
      // Navigate to book list and trigger delete via API
      await page.goto(`${BASE}/admin/libri`);
      await page.waitForLoadState('networkidle');
      const csrf = await getCsrfToken(page);

      // POST to delete endpoint
      // POST to delete endpoint (follows redirect automatically)
      const result = await page.evaluate(async ({ bookId, csrf, base }) => {
        const resp = await fetch(`${base}/admin/libri/delete/${bookId}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ csrf_token: csrf }),
        });
        return resp.status;
      }, { bookId, csrf, base: BASE });

      // Should succeed (200 after redirect)
      expect(result).toBe(200);

      // DB verify: deleted_at should be set
      const deletedAt = dbQuery(`SELECT deleted_at FROM libri WHERE id = ${bookId}`);
      expect(deletedAt).not.toBe('NULL');
      expect(deletedAt).toBeTruthy();
    } finally {
      dbQuery(`DELETE FROM libri WHERE id = ${bookId}`);
    }
  });
});

// ════════════════════════════════════════════════════════════════════════
// Group 4: Book Management (2 tests)
// ════════════════════════════════════════════════════════════════════════
test.describe.serial('Book Management', () => {
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
  test.afterAll(async () => { await context.close(); });

  test('Test 11: Increase book copies via API', async () => {
    // Create a test book with 1 copy
    const bookTitle = `E2E Copies ${RUN_ID}`;
    dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at) VALUES ('${bookTitle}', 1, 1, NOW())`);
    const bookId = dbQuery(`SELECT id FROM libri WHERE titolo = '${bookTitle}' AND deleted_at IS NULL`);
    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato, created_at) VALUES (${bookId}, CONCAT('E2E-', ${bookId}, '-', UNIX_TIMESTAMP()), 'disponibile', NOW())`);

    try {
      await page.goto(`${BASE}/admin/libri`);
      await page.waitForLoadState('networkidle');
      const csrf = await getCsrfToken(page);

      const result = await page.evaluate(async ({ bookId, csrf, base }) => {
        const resp = await fetch(`${base}/api/libri/${bookId}/increase-copies`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ copies: '2', csrf_token: csrf }),
        });
        return resp.json();
      }, { bookId, csrf, base: BASE });

      expect(result.success).toBe(true);

      // DB verify: copies increased
      const totalCopies = dbQuery(`SELECT copie_totali FROM libri WHERE id = ${bookId}`);
      expect(parseInt(totalCopies)).toBe(3); // 1 original + 2 added

      const copyCount = dbQuery(`SELECT COUNT(*) FROM copie WHERE libro_id = ${bookId}`);
      expect(parseInt(copyCount)).toBe(3);
    } finally {
      dbQuery(`DELETE FROM copie WHERE libro_id = ${bookId}`);
      dbQuery(`DELETE FROM libri WHERE id = ${bookId}`);
    }
  });

  test('Test 12: Admin loan renew', async () => {
    // Create test book + copy + user + active loan
    const bookTitle = `E2E Renew ${RUN_ID}`;
    dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at) VALUES ('${bookTitle}', 1, 0, NOW())`);
    const bookId = dbQuery(`SELECT id FROM libri WHERE titolo = '${bookTitle}' AND deleted_at IS NULL`);
    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato, created_at) VALUES (${bookId}, CONCAT('E2E-P-', ${bookId}, '-', UNIX_TIMESTAMP()), 'prestato', NOW())`);
    const copyId = dbQuery(`SELECT id FROM copie WHERE libro_id = ${bookId} LIMIT 1`);

    // Create test borrower
    const borrowerEmail = `e2e-borrower-${RUN_ID}@test.local`;
    const borrowerHash = phpHash('Test1234!');
    dbQuery(`INSERT INTO utenti (nome, cognome, email, password, tipo_utente, stato, codice_tessera, privacy_accettata, email_verificata, created_at) VALUES ('E2E', 'Borrower${RUN_ID}', '${borrowerEmail}', '${borrowerHash}', 'standard', 'attivo', 'BRW-${RUN_ID}', 1, 1, NOW())`);
    const borrowerId = dbQuery(`SELECT id FROM utenti WHERE email = '${borrowerEmail}'`);

    // Create active loan
    const dueDate = new Date();
    dueDate.setDate(dueDate.getDate() + 7);
    const dueDateStr = `${dueDate.getFullYear()}-${String(dueDate.getMonth() + 1).padStart(2, '0')}-${String(dueDate.getDate()).padStart(2, '0')}`;
    dbQuery(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, renewals, created_at) VALUES (${bookId}, ${copyId}, ${borrowerId}, '${todayISO()}', '${dueDateStr}', 'in_corso', 0, NOW())`);
    const loanId = dbQuery(`SELECT id FROM prestiti WHERE libro_id = ${bookId} AND utente_id = ${borrowerId} AND stato = 'in_corso' LIMIT 1`);

    try {
      await page.goto(`${BASE}/admin/prestiti`);
      await page.waitForLoadState('networkidle');
      const csrf = await getCsrfToken(page);

      const result = await page.evaluate(async ({ loanId, csrf, base }) => {
        const resp = await fetch(`${base}/admin/prestiti/rinnova/${loanId}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ csrf_token: csrf }),
        });
        return resp.status;
      }, { loanId, csrf, base: BASE });

      // After redirect-follow, should get 200
      expect(result).toBe(200);

      // DB verify: renewals incremented, due date extended
      const renewals = dbQuery(`SELECT renewals FROM prestiti WHERE id = ${loanId}`);
      expect(parseInt(renewals)).toBe(1);

      const newDueDate = dbQuery(`SELECT data_scadenza FROM prestiti WHERE id = ${loanId}`);
      // Due date should be later than original
      expect(new Date(newDueDate).getTime()).toBeGreaterThan(dueDate.getTime());
    } finally {
      dbQuery(`DELETE FROM prestiti WHERE id = ${loanId}`);
      dbQuery(`DELETE FROM copie WHERE libro_id = ${bookId}`);
      dbQuery(`DELETE FROM user_sessions WHERE utente_id = ${borrowerId}`);
      dbQuery(`DELETE FROM utenti WHERE id = ${borrowerId}`);
      dbQuery(`DELETE FROM libri WHERE id = ${bookId}`);
    }
  });
});

// ════════════════════════════════════════════════════════════════════════
// Group 5: Admin Events & Reviews (3 tests)
// ════════════════════════════════════════════════════════════════════════
test.describe.serial('Admin Events & Reviews', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  let eventId = '';

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
    if (eventId) {
      dbQuery(`DELETE FROM events WHERE id = ${eventId}`);
    }
    await context.close();
  });

  test('Test 13: Admin creates an event', async () => {
    const eventTitle = `E2E Event Create ${RUN_ID}`;

    await page.goto(`${BASE}/admin/cms/events/create`);
    await page.waitForLoadState('networkidle');

    await page.fill('#event_title', eventTitle);

    // Set date using the date input directly (bypass Flatpickr)
    await page.locator('#event_date').evaluate((el, dateStr) => {
      el.value = dateStr;
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }, todayISO());

    // Check is_active
    const activeCheckbox = page.locator('#is_active');
    if (!await activeCheckbox.isChecked()) {
      await activeCheckbox.check();
    }

    // TinyMCE content — set via the hidden textarea
    await page.locator('#event_content').evaluate((el) => {
      el.value = '<p>E2E test event content</p>';
    });
    // Also try setting via TinyMCE API if available
    await page.evaluate(() => {
      if (typeof tinymce !== 'undefined' && tinymce.get('event_content')) {
        tinymce.get('event_content').setContent('<p>E2E test event content</p>');
      }
    }).catch(() => {});

    await page.locator('button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Should redirect to events list
    expect(page.url()).toContain('/admin/cms/events');
    expect(page.url()).not.toContain('/create');

    // DB verify
    eventId = dbQuery(`SELECT id FROM events WHERE title = '${eventTitle}' LIMIT 1`);
    expect(eventId).toBeTruthy();
  });

  test('Test 14: Admin edits and deletes event', async () => {
    test.skip(!eventId, 'No event from Test 13');

    const newTitle = `E2E Event Edited ${RUN_ID}`;

    // Edit the event
    await page.goto(`${BASE}/admin/cms/events/edit/${eventId}`);
    await page.waitForLoadState('networkidle');
    await page.fill('#event_title', newTitle);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // DB verify title updated
    const dbTitle = dbQuery(`SELECT title FROM events WHERE id = ${eventId}`);
    expect(dbTitle).toBe(newTitle);

    // Delete the event
    const csrf = await getCsrfToken(page);
    await page.evaluate(async ({ eventId, csrf, base }) => {
      await fetch(`${base}/admin/cms/events/delete/${eventId}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ csrf_token: csrf }),
        redirect: 'manual',
      });
    }, { eventId, csrf, base: BASE });

    // DB verify deleted
    const exists = dbQuery(`SELECT COUNT(*) FROM events WHERE id = ${eventId}`);
    expect(parseInt(exists)).toBe(0);
    eventId = ''; // Already deleted
  });

  test('Test 15: User submits review, admin approves', async () => {
    // Setup: create user + book + completed loan
    const reviewerEmail = `e2e-reviewer-${RUN_ID}@test.local`;
    const reviewerHash = phpHash('Test1234!');
    dbQuery(`INSERT INTO utenti (nome, cognome, email, password, tipo_utente, stato, codice_tessera, privacy_accettata, email_verificata, created_at) VALUES ('E2E', 'Reviewer${RUN_ID}', '${reviewerEmail}', '${reviewerHash}', 'standard', 'attivo', 'REV-${RUN_ID}', 1, 1, NOW())`);
    const reviewerId = dbQuery(`SELECT id FROM utenti WHERE email = '${reviewerEmail}'`);

    const bookTitle = `E2E Review Book ${RUN_ID}`;
    dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at) VALUES ('${bookTitle}', 1, 1, NOW())`);
    const bookId = dbQuery(`SELECT id FROM libri WHERE titolo = '${bookTitle}' AND deleted_at IS NULL`);
    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato, created_at) VALUES (${bookId}, CONCAT('E2E-', ${bookId}, '-', UNIX_TIMESTAMP()), 'disponibile', NOW())`);
    const copyId = dbQuery(`SELECT id FROM copie WHERE libro_id = ${bookId} LIMIT 1`);

    // Create a completed loan (stato='restituito')
    dbQuery(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, created_at) VALUES (${bookId}, ${copyId}, ${reviewerId}, '${todayISO()}', '${todayISO()}', 'restituito', NOW())`);
    const loanId = dbQuery(`SELECT id FROM prestiti WHERE libro_id = ${bookId} AND utente_id = ${reviewerId} LIMIT 1`);

    let reviewId = '';

    try {
      // Login as reviewer in a new context
      const reviewerCtx = await page.context().browser().newContext();
      const reviewerPage = await reviewerCtx.newPage();
      await reviewerPage.goto(`${BASE}/accedi`);
      await reviewerPage.fill('input[name="email"]', reviewerEmail);
      await reviewerPage.fill('input[name="password"]', 'Test1234!');
      await reviewerPage.locator('button[type="submit"]').click();
      await reviewerPage.waitForFunction(
        () => !window.location.pathname.includes('accedi') && !window.location.pathname.includes('login'),
        { timeout: 15000 },
      );

      // Get CSRF from a page
      await reviewerPage.goto(`${BASE}/catalogo`);
      await reviewerPage.waitForLoadState('networkidle');
      const userCsrf = await getCsrfToken(reviewerPage);

      // Submit review via API
      const reviewResult = await reviewerPage.evaluate(async ({ bookId, csrf, base }) => {
        const resp = await fetch(`${base}/api/user/recensioni`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf,
          },
          body: JSON.stringify({
            libro_id: parseInt(bookId),
            stelle: 4,
            titolo: 'E2E Test Review',
            descrizione: 'This is an automated test review.',
          }),
        });
        return resp.json();
      }, { bookId, csrf: userCsrf, base: BASE });

      expect(reviewResult.success).toBe(true);
      await reviewerCtx.close();

      // DB verify: review exists and is pending
      reviewId = dbQuery(`SELECT id FROM recensioni WHERE libro_id = ${bookId} AND utente_id = ${reviewerId} LIMIT 1`);
      const stato = dbQuery(`SELECT stato FROM recensioni WHERE id = ${reviewId}`);
      expect(stato).toBe('pendente');

      // Admin approves the review
      const csrf = await getCsrfToken(page);
      const approveResult = await page.evaluate(async ({ reviewId, csrf, base }) => {
        const resp = await fetch(`${base}/admin/recensioni/${reviewId}/approve`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrf,
          },
          body: new URLSearchParams({ csrf_token: csrf }),
        });
        return resp.json();
      }, { reviewId, csrf, base: BASE });

      expect(approveResult.success).toBe(true);

      // DB verify: review approved
      const newStato = dbQuery(`SELECT stato FROM recensioni WHERE id = ${reviewId}`);
      expect(newStato).toBe('approvata');
    } finally {
      if (reviewId) dbQuery(`DELETE FROM recensioni WHERE id = ${reviewId}`);
      dbQuery(`DELETE FROM prestiti WHERE id = ${loanId}`);
      dbQuery(`DELETE FROM copie WHERE libro_id = ${bookId}`);
      dbQuery(`DELETE FROM user_sessions WHERE utente_id = ${reviewerId}`);
      dbQuery(`DELETE FROM utenti WHERE id = ${reviewerId}`);
      dbQuery(`DELETE FROM libri WHERE id = ${bookId}`);
    }
  });
});

// ════════════════════════════════════════════════════════════════════════
// Group 6: Infrastructure (3 tests)
// ════════════════════════════════════════════════════════════════════════
test.describe.serial('Infrastructure', () => {
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
  test.afterAll(async () => { await context.close(); });

  test('Test 16: Collocazione — create scaffale + mensola', async () => {
    const scaffaleCode = `E2E${RUN_ID}`.toUpperCase().slice(0, 20);

    await page.goto(`${BASE}/admin/collocazione`);
    await page.waitForLoadState('networkidle');

    // Create scaffale
    await page.fill('input[name="codice"]', scaffaleCode);
    await page.fill('input[name="nome"]', `E2E Shelf ${RUN_ID}`);
    // Submit scaffale form (first form on page)
    const scaffaleForm = page.locator('form[action*="scaffali"]').first();
    await scaffaleForm.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // DB verify scaffale
    const scaffaleId = dbQuery(`SELECT id FROM scaffali WHERE codice = '${scaffaleCode}'`);
    expect(scaffaleId).toBeTruthy();

    try {
      // Create mensola — reload page to get updated scaffale dropdown
      await page.goto(`${BASE}/admin/collocazione`);
      await page.waitForLoadState('networkidle');

      const mensolaForm = page.locator('form[action*="mensole"]').first();
      await mensolaForm.locator('select[name="scaffale_id"], #add-mensola-scaffale').selectOption(scaffaleId);
      const livelloInput = mensolaForm.locator('input[name="numero_livello"]');
      await livelloInput.fill('1');
      await mensolaForm.locator('button[type="submit"]').click();
      await page.waitForLoadState('networkidle');

      // DB verify mensola
      const mensolaId = dbQuery(`SELECT id FROM mensole WHERE scaffale_id = ${scaffaleId} AND numero_livello = 1`);
      expect(mensolaId).toBeTruthy();

      // Cleanup
      dbQuery(`DELETE FROM posizioni WHERE mensola_id = ${mensolaId}`);
      dbQuery(`DELETE FROM mensole WHERE id = ${mensolaId}`);
    } finally {
      dbQuery(`DELETE FROM mensole WHERE scaffale_id IN (SELECT id FROM scaffali WHERE codice = '${scaffaleCode}')`);
      dbQuery(`DELETE FROM scaffali WHERE codice = '${scaffaleCode}'`);
    }
  });

  test('Test 17: Admin statistics page loads', async () => {
    const resp = await page.goto(`${BASE}/admin/statistiche`);
    await page.waitForLoadState('networkidle');

    // Verify HTTP 200 (not 500 error)
    expect(resp.status()).toBe(200);

    // Should have some stats content — heading or stat elements
    const heading = page.locator('h1, h2, .page-title').first();
    await expect(heading).toBeVisible({ timeout: 5000 });
  });

  test('Test 18: Password reset flow', async () => {
    // Navigate to forgot password page
    await page.goto(`${BASE}/password-dimenticata`);
    await page.waitForLoadState('networkidle');

    const csrf = await getCsrfToken(page);

    // Submit admin email — should show same success page regardless (no enumeration)
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Verify success page or message (no email enumeration)
    const bodyText = await page.locator('body').textContent();
    expect(bodyText.toLowerCase()).not.toContain('not found');
    expect(bodyText.toLowerCase()).not.toContain('non trovata');

    // Inject a reset token directly in DB
    const testToken = 'e2etesttoken' + RUN_ID;
    dbQuery(`UPDATE utenti SET token_reset_password = SHA2('${testToken}', 256), data_token_reset = DATE_ADD(NOW(), INTERVAL 2 HOUR) WHERE email = '${ADMIN_EMAIL}'`);

    // Navigate to reset password page with token
    await page.goto(`${BASE}/reimposta-password?token=${testToken}`);
    await page.waitForLoadState('networkidle');

    // Page should load (not show invalid token error initially)
    const resetBody = await page.locator('body').textContent();
    // Should have password fields
    const pwField = page.locator('#password');
    await expect(pwField).toBeVisible({ timeout: 5000 });

    // Submit new password
    const newPassword = 'ResetPass1234!';
    await page.fill('#password', newPassword);
    await page.fill('#password_confirm', newPassword);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // DB verify: token should be cleared
    const tokenAfter = dbQuery(`SELECT IFNULL(token_reset_password, 'NULL') FROM utenti WHERE email = '${ADMIN_EMAIL}'`);
    expect(tokenAfter).toBe('NULL');

    // Restore original password — login with new password, but easier to just set via DB
    // Get the bcrypt hash of the original password by looking at what the admin currently has
    // Since we changed the password, restore it directly
    dbQuery(`UPDATE utenti SET password = (SELECT password FROM (SELECT password FROM utenti WHERE email = '${ADMIN_EMAIL}') t) WHERE email = '${ADMIN_EMAIL}'`);

    // Actually, the password was already changed. We need to set it back to ADMIN_PASS.
    // Use PHP to generate the hash, or rely on the login test in the next group to fail gracefully.
    // For safety, let's use a known bcrypt hash of ADMIN_PASS if we can generate it.
    // Better approach: log in with new password and change back via profile
    const loginCtx = await page.context().browser().newContext();
    const loginPage = await loginCtx.newPage();
    await loginPage.goto(`${BASE}/accedi`);
    await loginPage.fill('input[name="email"]', ADMIN_EMAIL);
    await loginPage.fill('input[name="password"]', newPassword);
    await loginPage.locator('button[type="submit"]').click();
    await loginPage.waitForURL(/admin|bacheca|dashboard/, { timeout: 15000 });

    // Change password back via profile
    await loginPage.goto(`${BASE}/profilo`);
    await loginPage.waitForLoadState('networkidle');
    await loginPage.fill('#password', ADMIN_PASS);
    await loginPage.fill('#password_confirm', ADMIN_PASS);
    const pwForm = loginPage.locator('form[action*="password"]').first();
    await pwForm.locator('button[type="submit"]').click();
    await loginPage.waitForLoadState('networkidle');
    await loginCtx.close();
  });
});

// ════════════════════════════════════════════════════════════════════════
// Group 7: Messages & Theme (2 tests)
// ════════════════════════════════════════════════════════════════════════
test.describe.serial('Messages & Theme', () => {
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
  test.afterAll(async () => { await context.close(); });

  test('Test 19: Admin messages inbox (JSON API)', async () => {
    const testEmail = `e2e-msg-${RUN_ID}@test.local`;

    // Seed a message via DB
    dbQuery(`INSERT INTO contact_messages (nome, cognome, email, messaggio, privacy_accepted, created_at) VALUES ('E2E', 'Message${RUN_ID}', '${testEmail}', 'E2E test message ${RUN_ID}', 1, NOW())`);
    const msgId = dbQuery(`SELECT id FROM contact_messages WHERE email = '${testEmail}' LIMIT 1`);

    try {
      // First navigate to admin dashboard to get CSRF token from a page with meta tag
      await page.goto(`${BASE}/admin/dashboard`);
      await page.waitForLoadState('networkidle');
      const csrf = await getCsrfToken(page);

      // GET messages list via fetch (JSON API)
      const listResp = await page.evaluate(async ({ base }) => {
        const resp = await fetch(`${base}/admin/messages`);
        return { status: resp.status, text: await resp.text() };
      }, { base: BASE });
      expect(listResp.status).toBe(200);

      // GET single message (marks as read)
      const detailResp = await page.evaluate(async ({ msgId, base }) => {
        const resp = await fetch(`${base}/admin/messages/${msgId}`);
        return { status: resp.status };
      }, { msgId, base: BASE });
      expect(detailResp.status).toBe(200);

      // DB verify: marked as read
      const isRead = dbQuery(`SELECT is_read FROM contact_messages WHERE id = ${msgId}`);
      expect(parseInt(isRead)).toBe(1);

      // Archive message
      const archiveResp = await page.evaluate(async ({ msgId, csrf, base }) => {
        const resp = await fetch(`${base}/admin/messages/${msgId}/archive`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrf,
          },
          body: new URLSearchParams({ csrf_token: csrf }),
        });
        return { status: resp.status, body: await resp.json() };
      }, { msgId, csrf, base: BASE });
      expect(archiveResp.body.success).toBe(true);

      // DB verify: archived
      const isArchived = dbQuery(`SELECT is_archived FROM contact_messages WHERE id = ${msgId}`);
      expect(parseInt(isArchived)).toBe(1);

      // Delete message
      const deleteResp = await page.evaluate(async ({ msgId, csrf, base }) => {
        const resp = await fetch(`${base}/admin/messages/${msgId}`, {
          method: 'DELETE',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrf,
          },
        });
        return { status: resp.status, body: await resp.json() };
      }, { msgId, csrf, base: BASE });
      expect(deleteResp.body.success).toBe(true);

      // DB verify: deleted
      const exists = dbQuery(`SELECT COUNT(*) FROM contact_messages WHERE id = ${msgId}`);
      expect(parseInt(exists)).toBe(0);
    } finally {
      dbQuery(`DELETE FROM contact_messages WHERE email = '${testEmail}'`);
    }
  });

  test('Test 20: Theme list and customization', async () => {
    const resp = await page.goto(`${BASE}/admin/themes`);
    await page.waitForLoadState('networkidle');

    // Verify page loads successfully (HTTP 200)
    expect(resp.status()).toBe(200);

    // Find a customize link
    const customizeLink = page.locator('a[href*="/admin/themes/"][href*="/customize"]').first();
    const isVisible = await customizeLink.isVisible().catch(() => false);
    test.skip(!isVisible, 'No theme customize links found');

    // Navigate to customization
    const href = await customizeLink.getAttribute('href');
    await page.goto(`${BASE}${href}`);
    await page.waitForLoadState('networkidle');

    // Change primary color
    const colorInput = page.locator('#color-primary');
    await expect(colorInput).toBeVisible({ timeout: 5000 });

    // Save original value
    const originalColor = await colorInput.inputValue();

    // Set new color
    await colorInput.evaluate((el) => {
      el.value = '#ff5733';
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });

    // Submit the theme form
    const themeForm = page.locator('#theme-form, form[action*="themes"]').first();
    await themeForm.locator('button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Verify save completed (page loaded after redirect)
    await page.waitForLoadState('networkidle');

    // Reset to defaults via API
    // Extract theme ID from the URL
    const themeId = href.match(/themes\/(\d+)/)?.[1] || href.match(/themes\/([^/]+)/)?.[1];
    if (themeId) {
      const csrf = await getCsrfToken(page);
      await page.evaluate(async ({ themeId, csrf, base }) => {
        await fetch(`${base}/admin/themes/${themeId}/reset`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ csrf_token: csrf }),
        });
      }, { themeId, csrf, base: BASE });
    }
  });
});
