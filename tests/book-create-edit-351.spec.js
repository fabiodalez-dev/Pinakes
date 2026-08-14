// Behavioral E2E: create a book, edit it, and verify the fix for issue #351
// (the "Availability" / #stato select in the book editor is read-only because
// availability is derived from physical copies, not user input).
//
// Form under test: app/Views/libri/partials/book_form.php
//   - create: GET /admin/books/create → POST (LibriController::store)
//   - edit:   GET /admin/books/edit/<id> → POST (LibriController::update)
//   - #stato is rendered with `disabled aria-readonly="true"` and update()
//     unsets $fields['stato'] server-side.
//
// Requires an installed Pinakes instance and admin login. Skips when env is
// incomplete to avoid silent failures.

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8082';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const DB_HOST = process.env.E2E_DB_HOST   || 'localhost';
const DB_USER = process.env.E2E_DB_USER   || '';
const DB_PASS = process.env.E2E_DB_PASS   || '';
const DB_NAME = process.env.E2E_DB_NAME   || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const CREATE_BOOK_URL = `${BASE}/admin/books/create`;

const TAG = Date.now();
const CREATE_TITLE = `E2E CreateEdit351 ${TAG}`;
const EDITED_SUBTITLE = `E2E Edited Subtitle 351 ${TAG}`;

test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'book-create-edit-351 requires E2E_ADMIN_EMAIL/PASS + DB_USER/NAME',
);

function dbQuery(sql) {
  const args = ['-N', '-B', '-e', sql];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER);
  if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
  args.push(DB_NAME);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000 }).trim();
}

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin`);
  if (page.url().includes('admin') && !page.url().match(/login|accedi|anmelden/)) return;
  for (const slug of ['accedi', 'login', 'anmelden']) {
    const resp = await page.goto(`${BASE}/${slug}`).catch(() => null);
    if (resp && resp.status() === 200 && (await page.locator('input[name="email"]').count()) > 0) break;
  }
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await Promise.all([
    page.waitForURL(/admin/, { timeout: 15000 }),
    page.locator('button[type="submit"]').click(),
  ]);
}

// Submits #bookForm and clicks through the SweetAlert confirm if one appears
// (duplicate-check / info dialogs use SweetAlert2, not native dialogs).
async function submitBookForm(page) {
  await page.locator('#bookForm button[type="submit"]').click();
  const swalConfirm = page.locator('.swal2-confirm');
  if (await swalConfirm.isVisible({ timeout: 5000 }).catch(() => false)) {
    await swalConfirm.click();
  }
}

test.describe.serial('book create + edit — #351 read-only availability', () => {
  let context;
  let page;
  let bookId = 0;
  let statoBeforeEdit = '';

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);
  });

  test.afterAll(async () => {
    if (bookId > 0) {
      // FK-safe order: copies first, then the book. Hard delete — this is
      // isolated test data tagged with a timestamp.
      try { dbQuery(`DELETE FROM copie WHERE libro_id = ${bookId}`); } catch (_) { /* test cleanup */ }
      try { dbQuery(`DELETE FROM libri WHERE id = ${bookId}`); } catch (_) { /* test cleanup */ }
    }
    await context?.close();
  });

  test('1. Create a book with minimal required fields and verify it in the DB', async () => {
    test.setTimeout(60000);
    await page.goto(CREATE_BOOK_URL);
    await expect(page.locator('#bookForm')).toBeVisible({ timeout: 10000 });

    // titolo is the only field store() enforces; anno/lingua keep the record realistic.
    await page.fill('#titolo', CREATE_TITLE);
    await page.fill('#anno_pubblicazione', '2024');
    await page.fill('#lingua', 'Italiano');

    await submitBookForm(page);

    await expect.poll(
      () => dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo = '${CREATE_TITLE}' AND deleted_at IS NULL`),
      { timeout: 30000 },
    ).toBe('1');
    await page.waitForURL(/\/admin\/books(?:\/\d+)?(?:\?.*)?$/, { timeout: 30000 });

    const row = dbQuery(`SELECT id, titolo FROM libri WHERE titolo = '${CREATE_TITLE}' AND deleted_at IS NULL`);
    expect(row, 'created book must exist exactly once in DB').toContain(CREATE_TITLE);
    bookId = parseInt(row.split('\t')[0], 10);
    expect(bookId).toBeGreaterThan(0);

    // Baseline for test 5: the availability value the disabled field must not alter.
    statoBeforeEdit = dbQuery(`SELECT stato FROM libri WHERE id = ${bookId}`);
    expect(statoBeforeEdit.length).toBeGreaterThan(0);
  });

  test('2. Editor loads the created book with its fields populated', async () => {
    test.skip(bookId === 0, 'requires test 1 to have created a book');
    await page.goto(`${BASE}/admin/books/edit/${bookId}`);
    await expect(page.locator('#bookForm')).toBeVisible({ timeout: 10000 });

    const dataMode = await page.locator('#bookForm').getAttribute('data-mode');
    expect(['edit', 'modifica']).toContain(String(dataMode || '').toLowerCase());
    await expect(page.locator('#titolo')).toHaveValue(CREATE_TITLE);
    await expect(page.locator('#anno_pubblicazione')).toHaveValue('2024');
  });

  test('3. #351 — Availability (#stato) select is disabled (read-only)', async () => {
    test.skip(bookId === 0, 'requires test 1 to have created a book');
    await page.goto(`${BASE}/admin/books/edit/${bookId}`);
    await expect(page.locator('#bookForm')).toBeVisible({ timeout: 10000 });

    const stato = page.locator('#stato');
    await expect(stato).toHaveCount(1);
    await expect(stato).toBeDisabled();
    await expect(stato).toHaveAttribute('aria-readonly', 'true');

    // It must also be disabled on the create form — availability is derived
    // from copies in both modes.
    await page.goto(CREATE_BOOK_URL);
    await expect(page.locator('#bookForm')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('#stato')).toBeDisabled();
  });

  test('4. Editing the subtitle persists to the DB', async () => {
    test.skip(bookId === 0, 'requires test 1 to have created a book');
    test.setTimeout(60000);
    await page.goto(`${BASE}/admin/books/edit/${bookId}`);
    await expect(page.locator('#bookForm')).toBeVisible({ timeout: 10000 });

    await page.fill('#sottotitolo', EDITED_SUBTITLE);
    await submitBookForm(page);

    await expect.poll(
      () => dbQuery(`SELECT sottotitolo FROM libri WHERE id = ${bookId}`),
      { timeout: 30000 },
    ).toBe(EDITED_SUBTITLE);
    // Title untouched by the edit.
    expect(dbQuery(`SELECT titolo FROM libri WHERE id = ${bookId}`)).toBe(CREATE_TITLE);
  });

  test('5. #351 — the disabled availability field did not alter libri.stato', async () => {
    test.skip(bookId === 0 || statoBeforeEdit === '', 'requires tests 1 and 4');
    const statoAfterEdit = dbQuery(`SELECT stato FROM libri WHERE id = ${bookId}`);
    expect(statoAfterEdit, 'stato must be unchanged after an edit-save with the disabled select').toBe(statoBeforeEdit);
  });
});
