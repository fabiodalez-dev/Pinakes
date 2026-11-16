// @ts-check
/**
 * E2E coverage for every SweetAlert in the "reviews" admin cluster.
 *
 * View under test: app/Views/admin/reviews/index.php
 *   - confirmAction modal (:303) bound to .approve-btn / .reject-btn / .delete-btn
 *   - showFeedback (:321) success toast / error alert after the fetch resolves
 *
 * Routes (English literals — admin):
 *   POST   /admin/reviews/{id}/approve  -> recensioni.stato = 'approvata'
 *   POST   /admin/reviews/{id}/reject   -> recensioni.stato = 'rifiutata'
 *   DELETE /admin/reviews/{id}          -> hard DELETE FROM recensioni
 *
 * Run with:
 *   npx playwright test tests/swal-reviews.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS ?? '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

const HAS_E2E_ENV = Boolean(ADMIN_EMAIL && ADMIN_PASS && DB_USER && DB_NAME && (DB_HOST || DB_SOCKET));

test.skip(
  !HAS_E2E_ENV,
  'Missing E2E env vars for reviews SweetAlert tests'
);

function mysqlArgs(sql = '', batch = false) {
  const args = [];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER);
  if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
  args.push(DB_NAME);
  if (batch) args.push('-N', '-B');
  if (sql !== '') args.push('-e', sql);
  return args;
}

function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql, true), { encoding: 'utf-8', timeout: 10000 }).trim();
}

function dbExec(sql, timeout = 10000) {
  execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout });
}

function escapeSql(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const emailField = page.locator('input[name="email"]');
  if (await emailField.isVisible({ timeout: 3000 }).catch(() => false)) {
    await emailField.fill(ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
      page.waitForURL(/\/admin\//, { timeout: 15000 }),
      page.click('button[type="submit"]'),
    ]);
  }
}

// ---------------------------------------------------------------------------
// Per-run unique suffix (same style as the templates: Date.now() tag)
// ---------------------------------------------------------------------------
const TAG = 'E2E_SWAL_REVIEWS_' + Date.now();

function detectReviewsSchema() {
  if (!HAS_E2E_ENV) return false;
  try {
    const count = dbQuery(`
      SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME IN ('recensioni', 'libri', 'utenti')
    `);
    return count === '3';
  } catch {
    return false;
  }
}

test.skip(
  HAS_E2E_ENV && !detectReviewsSchema(),
  'E2E database is reachable but recensioni/libri/utenti tables are missing'
);

async function gotoReviews(page) {
  // A prior test's success Swal may trigger a location.reload(); retry the
  // navigation if it is interrupted by that in-flight reload.
  for (let i = 0; i < 4; i++) {
    try { await page.goto(`${BASE}/admin/reviews`, { waitUntil: 'domcontentloaded' }); return; }
    catch (e) { if (!/interrupted by another navigation/.test(String(e))) throw e; await page.waitForTimeout(400); }
  }
  await page.goto(`${BASE}/admin/reviews`, { waitUntil: 'domcontentloaded' });
}

test.describe.serial('Reviews cluster SweetAlerts', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;

  /** @type {number} */ let bookId;
  /** @type {number} */ let userId;

  // Create one fixture review and return its id (defaults to pending).
  function createReview(stato = 'pendente') {
    dbExec(`
      INSERT INTO recensioni (libro_id, utente_id, stelle, titolo, descrizione, stato, data_recensione, created_at, updated_at)
      VALUES (${bookId}, ${userId}, 4, '${escapeSql(TAG)} title', '${escapeSql(TAG)} body', '${escapeSql(stato)}', NOW(), NOW(), NOW())
    `);
    return Number(dbQuery(`
      SELECT id FROM recensioni
       WHERE libro_id = ${bookId} AND utente_id = ${userId}
       ORDER BY id DESC LIMIT 1
    `));
  }

  function cleanupReviews() {
    if (!bookId || !userId) return;
    dbExec(`DELETE FROM recensioni WHERE libro_id = ${bookId} OR utente_id = ${userId}`);
  }

  test.beforeAll(async ({ browser }) => {
    // FK-safe fixtures: a book + a user the review can reference.
    dbExec(`
      INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at)
      VALUES ('${escapeSql(TAG)} Reviewed Book', 1, 1, NOW(), NOW())
    `);
    bookId = Number(dbQuery(`
      SELECT id FROM libri
       WHERE titolo = '${escapeSql(TAG)} Reviewed Book'
       ORDER BY id DESC LIMIT 1
    `));

    dbExec(`
      INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, privacy_accettata, created_at, updated_at)
      VALUES ('RVW${Date.now().toString().slice(-9)}', 'SwalReview', 'Tester', '${escapeSql(TAG)}@example.test', 'x', 'attivo', 1, NOW(), NOW())
    `);
    userId = Number(dbQuery(`
      SELECT id FROM utenti
       WHERE email = '${escapeSql(TAG)}@example.test'
       ORDER BY id DESC LIMIT 1
    `));

    cleanupReviews();

    context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);
  });

  test.afterAll(async () => {
    try {
      cleanupReviews();
      if (bookId) dbExec(`DELETE FROM libri WHERE id = ${bookId}`);
      if (userId) dbExec(`DELETE FROM utenti WHERE id = ${userId}`);
    } catch { /* best-effort cleanup */ }
    await context?.close();
  });

  // -------------------------------------------------------------------------
  // Trigger 1 — .approve-btn (confirm-action) — index.php:303
  // Shown: confirmAction warning modal. Outcome: stato -> 'approvata'.
  // -------------------------------------------------------------------------
  test('approve-btn shows confirm modal and sets stato=approvata', async () => {
    const reviewId = createReview('pendente');

    await gotoReviews(page);
    const btn = page.locator(`.approve-btn[data-review-id="${reviewId}"]`);
    await btn.waitFor({ state: 'visible', timeout: 10000 });
    await btn.click();

    // (a) the SweetAlert appears
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-popup')).toBeVisible();

    // (b) confirm -> server updates stato
    await page.locator('.swal2-confirm').click();

    await expect.poll(
      () => dbQuery(`SELECT stato FROM recensioni WHERE id = ${reviewId}`),
      { timeout: 8000 }
    ).toBe('approvata');

    cleanupReviews();
  });

  // -------------------------------------------------------------------------
  // Trigger 2 — .reject-btn (confirm-action) — index.php:303
  // Shown: confirmAction warning modal. Outcome: stato -> 'rifiutata'.
  // -------------------------------------------------------------------------
  test('reject-btn shows confirm modal and sets stato=rifiutata', async () => {
    const reviewId = createReview('pendente');

    await gotoReviews(page);
    const btn = page.locator(`.reject-btn[data-review-id="${reviewId}"]`);
    await btn.waitFor({ state: 'visible', timeout: 10000 });
    await btn.click();

    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-popup')).toBeVisible();

    await page.locator('.swal2-confirm').click();

    await expect.poll(
      () => dbQuery(`SELECT stato FROM recensioni WHERE id = ${reviewId}`),
      { timeout: 8000 }
    ).toBe('rifiutata');

    cleanupReviews();
  });

  // -------------------------------------------------------------------------
  // Trigger 3 — .delete-btn (confirm-destructive) — index.php:303
  // Shown: confirmAction warning modal. Outcome: row hard-deleted.
  // The delete button lives inside the collapsible "approved"/"rejected"
  // sections, so seed an APPROVED review and expand that section first.
  // -------------------------------------------------------------------------
  test('delete-btn shows confirm modal and permanently removes the review', async () => {
    const reviewId = createReview('approvata');

    await gotoReviews(page);

    // Expand the "approved" collapsible so the delete button is interactable.
    await page.click('button[data-pk-action="reviews-toggle-section"][data-section="approved"]');

    const btn = page.locator(`.delete-btn[data-review-id="${reviewId}"]`);
    await btn.waitFor({ state: 'visible', timeout: 10000 });
    await btn.click();

    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-popup')).toBeVisible();

    await page.locator('.swal2-confirm').click();

    // (b) the row is gone (hard delete: DELETE FROM recensioni)
    await expect.poll(
      () => dbQuery(`SELECT COUNT(*) FROM recensioni WHERE id = ${reviewId}`),
      { timeout: 8000 }
    ).toBe('0');

    cleanupReviews();
  });

  // -------------------------------------------------------------------------
  // Trigger 4 — showFeedback('success', ...) (success-toast) — index.php:321
  // After a successful approve the success Swal must surface with the
  // pre-translated "Recensione approvata" text.
  // -------------------------------------------------------------------------
  test('success feedback Swal appears after a successful approve', async () => {
    const reviewId = createReview('pendente');
    // A second pending review (on a different book — recensioni has a UNIQUE
    // (utente_id, libro_id) constraint) keeps an .approve-btn on the page after
    // the first card is removed, so handleAction does NOT immediately
    // location.reload() (which would wipe the success Swal before we assert).
    dbExec(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at)
            VALUES ('${escapeSql(TAG)} KeepAlive Book', 1, 1, NOW(), NOW())`);
    const book2Id = Number(dbQuery(`SELECT id FROM libri WHERE titolo='${escapeSql(TAG)} KeepAlive Book' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`));
    dbExec(`INSERT INTO recensioni (libro_id, utente_id, stelle, titolo, descrizione, stato, data_recensione, created_at, updated_at)
            VALUES (${book2Id}, ${userId}, 4, '${escapeSql(TAG)} keepalive', '${escapeSql(TAG)} body', 'pendente', NOW(), NOW(), NOW())`);

    await gotoReviews(page);
    const btn = page.locator(`.approve-btn[data-review-id="${reviewId}"]`);
    await btn.waitFor({ state: 'visible', timeout: 10000 });
    await btn.click();

    // First popup = the confirm modal
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await page.locator('.swal2-confirm').click();

    // Second popup = the success feedback. Wait for the success icon + text.
    await page.waitForSelector('.swal2-icon-success', { timeout: 8000 });
    await expect(page.locator('.swal2-popup')).toBeVisible();
    await expect(page.locator('.swal2-popup')).toContainText('Recensione approvata');

    // And the underlying state actually changed.
    expect(dbQuery(`SELECT stato FROM recensioni WHERE id = ${reviewId}`)).toBe('approvata');

    cleanupReviews();
  });

  // -------------------------------------------------------------------------
  // Trigger 5 — showFeedback('error', ...) (error-alert) — index.php:321
  // Force a server-side failure by tampering the button's data-review-id to a
  // non-existent review. The approve fetch returns success=false / non-ok, so
  // the error Swal (icon-error) must surface with the "Impossibile..." prefix.
  // -------------------------------------------------------------------------
  test('error feedback Swal appears when the action fails server-side', async () => {
    const reviewId = createReview('pendente');

    await gotoReviews(page);
    const btn = page.locator(`.approve-btn[data-review-id="${reviewId}"]`);
    await btn.waitFor({ state: 'visible', timeout: 10000 });

    // Force the server-side action to report failure so the error-feedback Swal
    // branch fires deterministically. We intercept the approve fetch and return
    // success=false (this is what showFeedback('error', ...) keys on).
    await page.route('**/admin/reviews/*/approve', route =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'forced failure' }) }),
    );

    await btn.click();

    // Confirm modal -> confirm
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await page.locator('.swal2-confirm').click();

    // Error feedback Swal must appear.
    await page.waitForSelector('.swal2-icon-error', { timeout: 8000 });
    await expect(page.locator('.swal2-icon-error')).toBeVisible();
    await expect(page.locator('.swal2-popup')).toContainText('Impossibile approvare');

    cleanupReviews();
  });
});
