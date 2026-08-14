// @ts-check
//
// Behavioral E2E for the 0.7.25 book-field / derived-status fixes, driven
// through the REAL admin form + controller (this is where the original bug
// report started: "tipo_acquisizione might not work"). Complements the model
// suite tests/book-field-types.unit.php and the static tests/book-field-types-
// static.spec.js by proving the end-to-end HTTP path:
//
//   1. a free-text `tipo_acquisizione` typed in the form round-trips through
//      LibriController -> BookRepository -> DB -> back into the edit form;
//   2. editing a book and submitting a (wrong) `stato` does NOT overwrite the
//      derived libri.stato — LibriController unset()s it, the loan engine owns it;
//   3. the book-detail badge renders a localized, underscore-free label for the
//      new `non_disponibile` state with its own (non-fallback) colour.
//   4. copy counts are managed from the book page after creation, while a new
//      catalogue record creates exactly the requested initial physical copies
//      (including the legitimate zero-copy case).
//
// Reusable: marker-scoped to titles `ZZ_BFTFORM_%`, cleans up in afterAll.

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

const RUN_ID = Date.now().toString(36);
const TITLE = `ZZ_BFTFORM_${RUN_ID}`;
const ZERO_TITLE = `ZZ_BFTFORM_ZERO_${RUN_ID}`;
const THREE_TITLE = `ZZ_BFTFORM_THREE_${RUN_ID}`;
const THREE_PREFIX = `ZZ3-${RUN_ID}`;

test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'E2E credentials not configured',
);

/** Run a MySQL statement, return trimmed stdout. */
function dbQuery(sql) {
  const args = ['-N', '-B', '-e', sql];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_PORT) args.push('-P', DB_PORT);
  if (!DB_HOST && DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME);
  return execFileSync('mysql', args, {
    encoding: 'utf-8', timeout: 10000,
    env: { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}

async function loginAsAdmin(page) {
  for (let attempt = 0; attempt < 2; attempt++) {
    await page.goto(`${BASE}/accedi`);
    await page.waitForLoadState('domcontentloaded');
    const email = page.locator('input[name="email"]');
    if (await email.isVisible({ timeout: 3000 }).catch(() => false)) {
      await email.fill(ADMIN_EMAIL);
      await page.fill('input[name="password"]', ADMIN_PASS);
      await page.locator('button[type="submit"]').click();
      try {
        await page.waitForURL(u => u.toString().includes('/admin'), { timeout: 30000 });
        return;
      } catch {
        if (attempt === 0) continue;
        throw new Error('loginAsAdmin: not redirected to /admin');
      }
    } else if (page.url().includes('/admin')) {
      return;
    }
  }
}

/** Submit #bookForm, tolerate a SweetAlert confirm, wait to leave the form. */
async function saveBookForm(page) {
  await page.locator('#bookForm button[type="submit"]').click();
  const confirm = page.locator('.swal2-confirm:visible');
  if (await confirm.isVisible({ timeout: 3000 }).catch(() => false)) {
    await confirm.click();
  }
  await page.waitForFunction(
    () => !window.location.pathname.endsWith('/admin/books/create')
      && !window.location.pathname.includes('/admin/books/edit/'),
    null,
    { timeout: 30_000 },
  );
  await page.waitForLoadState('domcontentloaded');
}

test.describe.serial('book fields + derived status — form/controller behavior', () => {
  let bookId = 0;

  test.beforeAll(async () => {
    // Pre-clean any leftover from a previous aborted run.
    dbQuery(`DELETE FROM libri WHERE titolo LIKE 'ZZ_BFTFORM_%'`);
  });

  // Playwright gives each test a fresh context (no shared cookies), so every
  // test must authenticate independently.
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test.afterAll(async () => {
    if (bookId) {
      dbQuery(`DELETE FROM copie WHERE libro_id = ${bookId}`);
    }
    dbQuery(`DELETE FROM libri WHERE titolo LIKE 'ZZ_BFTFORM_%'`);
  });

  test('1) free-text tipo_acquisizione round-trips through the real form', async ({ page }) => {
    await page.goto(`${BASE}/admin/books/create`);
    await expect(page.locator('#titolo')).toBeVisible({ timeout: 10000 });

    await page.fill('#titolo', TITLE);
    // Free-text value that is NOT one of the legacy enum options.
    await page.fill('#tipo_acquisizione', 'Deposito legale');
    await saveBookForm(page);

    // The controller persisted the row; find it by our marker title.
    bookId = parseInt(dbQuery(`SELECT id FROM libri WHERE titolo = '${TITLE}' AND deleted_at IS NULL LIMIT 1`) || '0', 10);
    expect(bookId).toBeGreaterThan(0);

    // Free text survived (was NOT coerced to an enum default).
    const stored = dbQuery(`SELECT tipo_acquisizione FROM libri WHERE id = ${bookId}`);
    expect(stored).toBe('Deposito legale');

    // And it round-trips back into the edit form's input value.
    await page.goto(`${BASE}/admin/books/edit/${bookId}`);
    await expect(page.locator('#tipo_acquisizione')).toBeVisible({ timeout: 10000 });
    expect(await page.locator('#tipo_acquisizione').inputValue()).toBe('Deposito legale');
  });

  test('2) editing a wrong stato does NOT overwrite the derived value', async ({ page }) => {
    expect(bookId).toBeGreaterThan(0);
    // Baseline: whatever the loan engine derived at creation (a fresh 1-copy book).
    const before = dbQuery(`SELECT stato FROM libri WHERE id = ${bookId}`);
    expect(before).not.toBe('perso');

    await page.goto(`${BASE}/admin/books/edit/${bookId}`);
    // #stato lives in a non-active form section, so it's attached but not
    // "visible". We're testing the CONTROLLER (does it honour a crafted
    // submitted stato?), so enable it, inject the per-copy value and submit.
    await page.locator('#stato').waitFor({ state: 'attached', timeout: 10000 });
    await page.locator('#stato').evaluate((el, v) => {
      // The UI intentionally exposes only canonical BOOK states; inject an
      // invalid per-copy state to verify that a crafted POST is still ignored.
      if (![...el.options].some((option) => option.value === v)) {
        el.add(new Option(v, v));
      }
      el.disabled = false;
      el.value = v;
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }, 'perso');
    expect(await page.locator('#stato').inputValue()).toBe('perso'); // the POST will carry 'perso'
    await saveBookForm(page);

    // The controller unset()s stato → DB is unchanged, still the derived value.
    const after = dbQuery(`SELECT stato FROM libri WHERE id = ${bookId}`);
    expect(after).not.toBe('perso');
    expect(after).toBe(before);
  });

  test('3) non_disponibile badge renders localized + non-fallback colour', async ({ page }) => {
    expect(bookId).toBeGreaterThan(0);
    // Force the derived summary to the new state to exercise the badge rendering
    // (view-layer test: the page does not recalc on read).
    dbQuery(`UPDATE libri SET stato = 'non_disponibile' WHERE id = ${bookId}`);

    await page.goto(`${BASE}/admin/books/${bookId}`);
    await page.waitForLoadState('domcontentloaded');

    // The primary status badge shows the localized label, never the raw key.
    const badge = page.locator('span:has-text("Non Disponibile")').first();
    await expect(badge).toBeVisible({ timeout: 10000 });
    const badgeText = (await badge.innerText()).trim();
    expect(badgeText).not.toContain('_');
    expect(badgeText.toLowerCase()).toContain('non disponibile');

    // Its colour is the dedicated non_disponibile shade, not the slate-500 fallback.
    const html = await page.content();
    expect(html).toContain('bg-gray-600');
  });

  test('4) edit delegates copy management; create persists exactly zero or three physical copies', async ({ page }) => {
    expect(bookId).toBeGreaterThan(0);
    await page.goto(`${BASE}/admin/books/edit/${bookId}`);
    const editCopies = page.locator('#copie_totali');
    await expect(editCopies).toHaveAttribute('readonly', '');
    await expect(page.locator(`a[href$="/admin/books/${bookId}#physical-copies"]`)).toBeAttached();
    await expect(page.locator('label[for="numero_inventario"]')).toContainText('Prefisso inventario copie');

    await page.goto(`${BASE}/admin/books/create`);
    const createCopies = page.locator('#copie_totali');
    await expect(createCopies).not.toHaveAttribute('readonly', '');
    await expect(createCopies).toHaveAttribute('min', '0');
    await createCopies.fill('0');
    expect(await createCopies.inputValue()).toBe('0');
    await page.fill('#titolo', ZERO_TITLE);
    await saveBookForm(page);

    const zeroBookId = Number(dbQuery(`SELECT id FROM libri WHERE titolo='${ZERO_TITLE}' AND deleted_at IS NULL LIMIT 1`));
    expect(zeroBookId).toBeGreaterThan(0);
    expect(dbQuery(`SELECT CONCAT(copie_totali, ':', copie_disponibili, ':', stato) FROM libri WHERE id=${zeroBookId}`)).toBe('0:0:non_disponibile');
    expect(Number(dbQuery(`SELECT COUNT(*) FROM copie WHERE libro_id=${zeroBookId}`))).toBe(0);

    await page.goto(`${BASE}/admin/books/create`);
    await page.fill('#titolo', THREE_TITLE);
    await page.fill('#copie_totali', '3');
    await page.fill('#numero_inventario', THREE_PREFIX);
    await saveBookForm(page);

    const threeBookId = Number(dbQuery(`SELECT id FROM libri WHERE titolo='${THREE_TITLE}' AND deleted_at IS NULL LIMIT 1`));
    expect(threeBookId).toBeGreaterThan(0);
    expect(dbQuery(`SELECT CONCAT(copie_totali, ':', copie_disponibili, ':', stato) FROM libri WHERE id=${threeBookId}`)).toBe('3:3:disponibile');
    expect(Number(dbQuery(`SELECT COUNT(*) FROM copie WHERE libro_id=${threeBookId}`))).toBe(3);
    expect(dbQuery(`SELECT GROUP_CONCAT(numero_inventario ORDER BY id SEPARATOR ',') FROM copie WHERE libro_id=${threeBookId}`))
      .toBe(`${THREE_PREFIX}-C1,${THREE_PREFIX}-C2,${THREE_PREFIX}-C3`);
  });
});
