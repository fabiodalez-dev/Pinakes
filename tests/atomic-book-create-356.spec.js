// @ts-check
//
// E2E proof for the atomic book+copies create in LibriController::store()
// (PR #356): the book row and its initial physical copies now persist in ONE
// transaction, including availability reconciliation and committed before any
// plugin hook fires. Driven through the
// REAL admin create form + controller + DB:
//
//   1. creating with 1 copy persists exactly one copie row and derived
//      counters (DataIntegrity) of 1:1:disponibile;
//   2. creating with 3 copies persists exactly three uniform -C1/-C2/-C3 codes;
//   3. creating with 0 copies persists a valid zero-holding record;
//   4. ROLLBACK PROOF: a forced copy-insert failure (DB trigger on copie)
//      makes the real POST fail AND leaves no orphan book, no partial copies,
//      and no orphan series (collane) metadata;
//   5. ROLLBACK PROOF: a forced availability-recalc failure removes the book
//      and the physical copies that had already been inserted;
//   6. a non-integer copie_totali still falls back to zero copies;
//   7. copie_totali is still clamped to the 9999 upper bound;
//   8. book.save.after still fires post-commit: with the book-club plugin
//      active, an external club proposal with the same ISBN is reconciled
//      (acquired_libro_id set) by the hook handler — which runs its own
//      transaction — without corrupting the created book or its copies.
//   9. two concurrent creates sharing an inventory prefix both succeed and
//      receive four globally unique, gap-free physical-copy codes.
//
// Reusable: marker-scoped to titles `ZZ_ATOMIC356_%`, cleans up in afterAll.
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
    encoding: 'utf-8', timeout: 20000,
    env: { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}

const RUN = Date.now().toString(36);
const MARKER = 'ZZ_ATOMIC356_';
const TITLE_ONE = `${MARKER}ONE_${RUN}`;
const TITLE_THREE = `${MARKER}THREE_${RUN}`;
const TITLE_ZERO = `${MARKER}ZERO_${RUN}`;
const TITLE_FAIL = `${MARKER}FAIL_${RUN}`;
const TITLE_RECALC_FAIL = `${MARKER}RECALC_FAIL_${RUN}`;
const TITLE_NONINT = `${MARKER}NONINT_${RUN}`;
const TITLE_CLAMP = `${MARKER}CLAMP_${RUN}`;
const TITLE_HOOK = `${MARKER}HOOK_${RUN}`;
const TITLE_RACE_A = `${MARKER}RACE_A_${RUN}`;
const TITLE_RACE_B = `${MARKER}RACE_B_${RUN}`;
const PREFIX_ONE = `A356A-${RUN}`;
const PREFIX_THREE = `A356B-${RUN}`;
const PREFIX_FAIL = `A356F-${RUN}`;
const PREFIX_RECALC_FAIL = `A356R-${RUN}`;
const PREFIX_RACE = `A356C-${RUN}`;
const SERIES_FAIL = `${MARKER}SERIES_${RUN}`;
const CLUB_SLUG = `zz-atomic356-${RUN}`;
const TRIGGER = 'zz_atomic356_fail_copy';
const RECALC_TRIGGER = 'zz_atomic356_fail_recalc';
const SLOW_COPY_TRIGGER = 'zz_atomic356_slow_copy';

/** Valid ISBN-13 unique to this run (per-run digits + computed check digit). */
function makeIsbn13() {
  const body = ('978' + Date.now().toString().slice(-10)).slice(0, 12);
  let sum = 0;
  for (let i = 0; i < 12; i++) sum += Number(body[i]) * (i % 2 === 0 ? 1 : 3);
  return body + String((10 - (sum % 10)) % 10);
}
const HOOK_ISBN = makeIsbn13();

function cleanup() {
  try { dbQuery(`DROP TRIGGER IF EXISTS ${TRIGGER}`); } catch { /* best-effort */ }
  try { dbQuery(`DROP TRIGGER IF EXISTS ${RECALC_TRIGGER}`); } catch { /* best-effort */ }
  try { dbQuery(`DROP TRIGGER IF EXISTS ${SLOW_COPY_TRIGGER}`); } catch { /* best-effort */ }
  // Book-club fixture rows (ignore errors if the plugin tables are absent).
  try {
    dbQuery(`DELETE FROM bookclub_books WHERE club_id IN (SELECT id FROM bookclub_clubs WHERE slug='${CLUB_SLUG}')`);
    dbQuery(`DELETE FROM bookclub_external_books WHERE club_id IN (SELECT id FROM bookclub_clubs WHERE slug='${CLUB_SLUG}')`);
    dbQuery(`DELETE FROM bookclub_clubs WHERE slug='${CLUB_SLUG}'`);
  } catch { /* plugin tables may not exist */ }
  dbQuery(`DELETE FROM collane WHERE nome LIKE '${MARKER}%'`);
  // copie rows cascade with the book (FK ON DELETE CASCADE).
  dbQuery(`DELETE FROM libri WHERE titolo LIKE '${MARKER}%'`);
}

const bookIdByTitle = (title) =>
  Number(dbQuery(`SELECT id FROM libri WHERE titolo='${title}' AND deleted_at IS NULL LIMIT 1`) || '0');
const copieCount = (id) => Number(dbQuery(`SELECT COUNT(*) FROM copie WHERE libro_id=${id}`));
const copieCodes = (id) => dbQuery(`SELECT numero_inventario FROM copie WHERE libro_id=${id} ORDER BY id`)
  .split('\n').filter(Boolean);

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

/**
 * Submit #bookForm (SweetAlert confirm) and wait for the store() response.
 * Returns the HTTP status of the POST, without assuming success.
 */
async function submitBookForm(page) {
  const responsePromise = page.waitForResponse(
    (r) => r.url().includes('/admin/books/create') && r.request().method() === 'POST',
    { timeout: 60000 },
  );
  await page.locator('#bookForm button[type="submit"]').click();
  const confirm = page.locator('.swal2-confirm:visible');
  if (await confirm.isVisible({ timeout: 3000 }).catch(() => false)) {
    await confirm.click();
  }
  const response = await responsePromise;
  return response.status();
}

async function openCreateForm(page, title) {
  await page.goto(`${BASE}/admin/books/create`);
  await expect(page.locator('#titolo')).toBeVisible({ timeout: 10000 });
  await page.fill('#titolo', title);
}

test.describe.serial('atomic book+copies create (#356)', () => {
  test.beforeAll(() => {
    // Pre-clean leftovers from a previous aborted run.
    dbQuery(`DELETE FROM libri WHERE titolo LIKE '${MARKER}%'`);
    try { dbQuery(`DROP TRIGGER IF EXISTS ${TRIGGER}`); } catch { /* best-effort */ }
    try { dbQuery(`DROP TRIGGER IF EXISTS ${RECALC_TRIGGER}`); } catch { /* best-effort */ }
    try { dbQuery(`DROP TRIGGER IF EXISTS ${SLOW_COPY_TRIGGER}`); } catch { /* best-effort */ }
  });

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test.afterAll(() => cleanup());

  test('1) create with 1 copy persists exactly one copie row + derived counters', async ({ page }) => {
    await openCreateForm(page, TITLE_ONE);
    await page.fill('#copie_totali', '1');
    await page.fill('#numero_inventario', PREFIX_ONE);
    const status = await submitBookForm(page);
    expect(status).toBeLessThan(400);

    const id = bookIdByTitle(TITLE_ONE);
    expect(id).toBeGreaterThan(0);
    expect(copieCodes(id)).toEqual([`${PREFIX_ONE}-C1`]);
    // DataIntegrity ran inside the transaction and derived canonical counters.
    expect(dbQuery(`SELECT CONCAT(copie_totali, ':', copie_disponibili, ':', stato) FROM libri WHERE id=${id}`))
      .toBe('1:1:disponibile');
  });

  test('2) create with 3 copies persists exactly three uniform -C1/-C2/-C3 codes', async ({ page }) => {
    await openCreateForm(page, TITLE_THREE);
    await page.fill('#copie_totali', '3');
    await page.fill('#numero_inventario', PREFIX_THREE);
    const status = await submitBookForm(page);
    expect(status).toBeLessThan(400);

    const id = bookIdByTitle(TITLE_THREE);
    expect(id).toBeGreaterThan(0);
    expect(copieCodes(id)).toEqual([`${PREFIX_THREE}-C1`, `${PREFIX_THREE}-C2`, `${PREFIX_THREE}-C3`]);
    expect(dbQuery(`SELECT CONCAT(copie_totali, ':', copie_disponibili) FROM libri WHERE id=${id}`)).toBe('3:3');
  });

  test('3) create with 0 copies persists a valid zero-holding record', async ({ page }) => {
    await openCreateForm(page, TITLE_ZERO);
    await page.fill('#copie_totali', '0');
    const status = await submitBookForm(page);
    expect(status).toBeLessThan(400);

    const id = bookIdByTitle(TITLE_ZERO);
    expect(id).toBeGreaterThan(0);
    expect(copieCount(id)).toBe(0);
    expect(dbQuery(`SELECT CONCAT(copie_totali, ':', copie_disponibili, ':', stato) FROM libri WHERE id=${id}`))
      .toBe('0:0:non_disponibile');
  });

  test('4) ROLLBACK: forced copy failure leaves no orphan book, copies or series rows', async ({ page }) => {
    // Force the multi-row copie INSERT to fail server-side, exactly like a
    // production insert error would, through the REAL controller path.
    // A single-statement trigger stays marker-scoped, so this spec remains safe
    // when Playwright runs other files against the same database in parallel.
    dbQuery(`CREATE TRIGGER ${TRIGGER} BEFORE INSERT ON copie FOR EACH ROW SET NEW.numero_inventario=IF(NEW.numero_inventario LIKE '${PREFIX_FAIL}%', NULL, NEW.numero_inventario)`);
    try {
      await openCreateForm(page, TITLE_FAIL);
      await page.fill('#copie_totali', '2');
      await page.fill('#numero_inventario', PREFIX_FAIL);
      // Series metadata travels with the same submit: on rollback it must
      // never be persisted either (it now runs only after the commit).
      await page.evaluate((name) => {
        const el = document.getElementById('collana');
        if (el) el.value = name;
      }, SERIES_FAIL);
      const status = await submitBookForm(page);
      expect(status).toBeGreaterThanOrEqual(500);
    } finally {
      dbQuery(`DROP TRIGGER IF EXISTS ${TRIGGER}`);
    }

    // No orphan book (not even soft-deleted), no partial copies, no series.
    expect(Number(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo='${TITLE_FAIL}'`))).toBe(0);
    expect(Number(dbQuery(`SELECT COUNT(*) FROM copie WHERE numero_inventario LIKE '${PREFIX_FAIL}%'`))).toBe(0);
    expect(Number(dbQuery(`SELECT COUNT(*) FROM collane WHERE nome='${SERIES_FAIL}'`))).toBe(0);
  });

  test('5) ROLLBACK: forced availability-recalc failure removes book and copies', async ({ page }) => {
    // Fail only the marked book's DataIntegrity UPDATE. Assigning NULL to the
    // non-null primary key is a portable one-statement trigger failure and does
    // not disturb parallel specs updating unrelated books.
    dbQuery(`CREATE TRIGGER ${RECALC_TRIGGER} BEFORE UPDATE ON libri FOR EACH ROW SET NEW.id=IF(NEW.titolo='${TITLE_RECALC_FAIL}', NULL, NEW.id)`);
    try {
      await openCreateForm(page, TITLE_RECALC_FAIL);
      await page.fill('#copie_totali', '2');
      await page.fill('#numero_inventario', PREFIX_RECALC_FAIL);
      const status = await submitBookForm(page);
      expect(status).toBeGreaterThanOrEqual(500);
    } finally {
      dbQuery(`DROP TRIGGER IF EXISTS ${RECALC_TRIGGER}`);
    }

    expect(Number(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo='${TITLE_RECALC_FAIL}'`))).toBe(0);
    expect(Number(dbQuery(`SELECT COUNT(*) FROM copie WHERE numero_inventario LIKE '${PREFIX_RECALC_FAIL}%'`))).toBe(0);
  });

  test('6) non-integer copie_totali still falls back to zero copies', async ({ page }) => {
    await openCreateForm(page, TITLE_NONINT);
    // Bypass the number input to deliver a genuinely non-integer POST value
    // (the form is novalidate; the controller must reject it, not the browser).
    await page.evaluate(() => {
      const el = document.getElementById('copie_totali');
      el.type = 'text';
      el.value = 'abc';
    });
    const status = await submitBookForm(page);
    expect(status).toBeLessThan(400);

    const id = bookIdByTitle(TITLE_NONINT);
    expect(id).toBeGreaterThan(0);
    expect(copieCount(id)).toBe(0);
    expect(dbQuery(`SELECT CONCAT(copie_totali, ':', copie_disponibili) FROM libri WHERE id=${id}`)).toBe('0:0');
  });

  test('7) copie_totali above the bound is clamped to 9999', async ({ page }) => {
    test.setTimeout(120000); // 9999 copie rows: one multi-row INSERT + recalc
    await openCreateForm(page, TITLE_CLAMP);
    await page.evaluate(() => {
      const el = document.getElementById('copie_totali');
      el.removeAttribute('max');
      el.value = '10000';
    });
    const status = await submitBookForm(page);
    expect(status).toBeLessThan(400);

    const id = bookIdByTitle(TITLE_CLAMP);
    expect(id).toBeGreaterThan(0);
    expect(copieCount(id)).toBe(9999);
    expect(Number(dbQuery(`SELECT copie_totali FROM libri WHERE id=${id}`))).toBe(9999);
  });

  test('8) book.save.after fires post-commit: book-club reconciles by ISBN without corrupting the create', async ({ page }) => {
    let bookClubActive = false;
    try {
      bookClubActive = dbQuery(`SELECT is_active FROM plugins WHERE name='book-club'`) === '1';
    } catch {
      // Optional plugin infrastructure is absent in some supported installs.
    }
    test.skip(!bookClubActive, 'book-club plugin not active in this environment');

    // Fixture: a club with an outstanding external proposal for HOOK_ISBN.
    const ics = RUN.padEnd(32, '0').slice(0, 32);
    dbQuery(`INSERT INTO bookclub_clubs (slug, name, ics_token) VALUES ('${CLUB_SLUG}', 'ZZ Atomic356 Club', '${ics}')`);
    const clubId = Number(dbQuery(`SELECT id FROM bookclub_clubs WHERE slug='${CLUB_SLUG}'`));
    dbQuery(`INSERT INTO bookclub_external_books (club_id, titolo, isbn) VALUES (${clubId}, '${TITLE_HOOK}', '${HOOK_ISBN}')`);
    const extId = Number(dbQuery(`SELECT id FROM bookclub_external_books WHERE club_id=${clubId}`));
    dbQuery(`INSERT INTO bookclub_books (club_id, external_book_id, state) VALUES (${clubId}, ${extId}, 'proposed')`);

    await openCreateForm(page, TITLE_HOOK);
    await page.fill('#isbn13', HOOK_ISBN);
    await page.fill('#copie_totali', '1');
    const status = await submitBookForm(page);
    expect(status).toBeLessThan(400);

    const id = bookIdByTitle(TITLE_HOOK);
    expect(id).toBeGreaterThan(0);
    // The hook handler ran (its own transaction, post-commit) and linked the
    // external proposal to the new catalogue book…
    expect(Number(dbQuery(`SELECT acquired_libro_id FROM bookclub_external_books WHERE id=${extId}`))).toBe(id);
    // …without corrupting the atomic create: the copy and the derived
    // counters are exactly what the form requested.
    expect(copieCount(id)).toBe(1);
    expect(dbQuery(`SELECT CONCAT(copie_totali, ':', copie_disponibili) FROM libri WHERE id=${id}`)).toBe('1:1');
  });

  test('9) concurrent creates sharing an inventory prefix allocate unique physical copies', async ({ page, browser }) => {
    test.setTimeout(90000);
    const secondContext = await browser.newContext();
    const secondPage = await secondContext.newPage();
    try {
      await loginAsAdmin(secondPage);
      await Promise.all([
        openCreateForm(page, TITLE_RACE_A),
        openCreateForm(secondPage, TITLE_RACE_B),
      ]);
      await page.fill('#copie_totali', '2');
      await page.fill('#numero_inventario', PREFIX_RACE);
      await secondPage.fill('#copie_totali', '2');
      await secondPage.fill('#numero_inventario', PREFIX_RACE);

      // Keep both INSERT statements overlapped. Without locking/retry, both
      // requests can select the same free C1/C2 codes and one fails on UNIQUE.
      dbQuery(`CREATE TRIGGER ${SLOW_COPY_TRIGGER} BEFORE INSERT ON copie FOR EACH ROW DO IF(NEW.numero_inventario LIKE '${PREFIX_RACE}%', SLEEP(0.35), 0)`);
      const firstSubmit = submitBookForm(page);
      await page.waitForTimeout(100);
      const secondSubmit = submitBookForm(secondPage);
      const statuses = await Promise.all([firstSubmit, secondSubmit]);
      expect(statuses.every((status) => status < 400)).toBe(true);
    } finally {
      dbQuery(`DROP TRIGGER IF EXISTS ${SLOW_COPY_TRIGGER}`);
      await secondContext.close();
    }

    const firstId = bookIdByTitle(TITLE_RACE_A);
    const secondId = bookIdByTitle(TITLE_RACE_B);
    expect(firstId).toBeGreaterThan(0);
    expect(secondId).toBeGreaterThan(0);
    const allCodes = [...copieCodes(firstId), ...copieCodes(secondId)].sort();
    expect(allCodes).toEqual([
      `${PREFIX_RACE}-C1`, `${PREFIX_RACE}-C2`,
      `${PREFIX_RACE}-C3`, `${PREFIX_RACE}-C4`,
    ]);
  });
});
