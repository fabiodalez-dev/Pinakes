// @ts-check
/**
 * Multi-publisher cataloguing E2E tests — issue #143.
 *
 * Books can now have more than one publisher (Choices.js multi-select, mirroring
 * authors). `libri.editore_id` is kept as the PRIMARY publisher; the full set
 * lives in the `libri_editori` junction. These 10 tests exercise the loading /
 * creation / editing flow with multiple publishers and verify persistence and
 * display.
 *
 * Run: /tmp/run-e2e.sh tests/multi-publisher.spec.js --config=tests/playwright.config.js --workers=1
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE        = process.env.E2E_BASE_URL    || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const DB_HOST     = process.env.E2E_DB_HOST     || 'localhost';
const DB_USER     = process.env.E2E_DB_USER     || '';
const DB_PASS     = process.env.E2E_DB_PASS     || '';
const DB_NAME     = process.env.E2E_DB_NAME     || '';
const DB_SOCKET   = process.env.E2E_DB_SOCKET   || '';
const DB_PORT     = process.env.E2E_DB_PORT     || '';

const RUN_ID = Date.now().toString(36);

test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'multi-publisher requires E2E_ADMIN_EMAIL, E2E_ADMIN_PASS, E2E_DB_USER, E2E_DB_NAME',
);

// ── DB helpers ───────────────────────────────────────────────────────────────
/**
 * Build the mysql CLI argument list for a query, honouring socket vs host/port.
 * @param {string} sql
 * @returns {string[]}
 */
function mysqlArgs(sql) {
  const args = ['-N', '-B', '-e', sql];
  if (DB_HOST && !DB_SOCKET) args.push('-h', DB_HOST);
  if (DB_PORT && !DB_SOCKET) args.push('-P', DB_PORT);
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER);
  if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
  args.push(DB_NAME);
  return args;
}
/**
 * Run a SQL query via the mysql CLI and return trimmed stdout ('' on error).
 * @param {string} sql
 * @returns {string}
 */
function dbQuery(sql) {
  try { return execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout: 10000 }).trim(); }
  catch (_) { return ''; }
}
/**
 * Escape a value for safe inlining into a single-quoted SQL literal (tests only).
 * @param {string} s
 * @returns {string}
 */
function sqlEsc(s) { return String(s).replace(/'/g, "''"); }
/**
 * Look up the most recent non-deleted book id by exact title.
 * @param {string} title
 * @returns {number} book id, or 0 if none
 */
function bookIdByTitle(title) {
  const r = dbQuery(`SELECT id FROM libri WHERE titolo='${sqlEsc(title)}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`);
  return parseInt(r, 10) || 0;
}
/**
 * Return the ordered publisher names attached to a book via libri_editori.
 * @param {number} bookId
 * @returns {string[]}
 */
function publisherRows(bookId) {
  const r = dbQuery(`SELECT e.nome FROM libri_editori le JOIN editori e ON e.id=le.editore_id WHERE le.libro_id=${bookId} ORDER BY le.ordine`);
  return r ? r.split('\n').filter(Boolean) : [];
}
/**
 * Return the primary publisher id (libri.editore_id) of a book.
 * @param {number} bookId
 * @returns {number}
 */
function primaryPublisherId(bookId) {
  return parseInt(dbQuery(`SELECT editore_id FROM libri WHERE id=${bookId}`), 10) || 0;
}

// ── Auth ─────────────────────────────────────────────────────────────────────
/**
 * Log in as the admin user, handling the locale-specific login slug.
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<void>}
 */
async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin`);
  if (page.url().includes('/admin') && !page.url().match(/login|accedi|anmelden/)) return;
  for (const slug of ['login', 'accedi', 'anmelden']) {
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

// ── Form helpers ─────────────────────────────────────────────────────────────
/**
 * Locate the publishers Choices.js wrapper and its cloned text input.
 * @param {import('@playwright/test').Page} page
 * @returns {{wrapper: import('@playwright/test').Locator, input: import('@playwright/test').Locator}}
 */
function publisherInput(page) {
  const wrapper = page.locator('#editori_select').locator('xpath=ancestor::*[contains(@class,"choices")]').first();
  return { wrapper, input: wrapper.locator('.choices__input--cloned') };
}

/**
 * Add a NEW publisher by typing the name and pressing Enter (the deterministic
 * create-new path for a `<select multiple>` Choices.js, via the _onEnterKey patch).
 * @param {import('@playwright/test').Page} page
 * @param {string} name
 * @returns {Promise<void>}
 */
async function addNewPublisher(page, name) {
  const { input } = publisherInput(page);
  await input.click();
  await input.fill(name);
  await input.press('Enter');
  await page.waitForTimeout(250);
}

/**
 * Add an EXISTING publisher by typing and clicking the matching dropdown option.
 * @param {import('@playwright/test').Page} page
 * @param {string} name
 * @returns {Promise<void>}
 */
async function addExistingPublisher(page, name) {
  const { wrapper, input } = publisherInput(page);
  await input.click();
  await input.fill(name);
  await page.waitForTimeout(700);
  const option = wrapper.locator('.choices__list--dropdown .choices__item', { hasText: name }).first();
  await expect(option).toBeVisible({ timeout: 8000 });
  await option.click();
  await page.waitForTimeout(250);
}

/**
 * Submit the book form and settle: handle the SweetAlert confirm and wait for
 * navigation/idle.
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<void>}
 */
async function submitBook(page) {
  await page.locator('#bookForm button[type="submit"], button[type="submit"]').first().click();
  await Promise.race([
    page.waitForSelector('.swal2-popup', { timeout: 12000 }),
    page.waitForURL(/admin\/books(?!.*create)(?!.*edit)/, { timeout: 12000 }),
  ]).catch(() => {});
  const confirm = page.locator('.swal2-confirm');
  if (await confirm.isVisible({ timeout: 2500 }).catch(() => false)) {
    await confirm.click({ force: true, timeout: 5000 }).catch(() => {});
  }
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(500);
}

/**
 * Create a book via the form with the given NEW publishers.
 * @param {import('@playwright/test').Page} page
 * @param {string} title
 * @param {string[]} publishers
 * @returns {Promise<number>} the created book id
 */
async function createBook(page, title, publishers) {
  await page.goto(`${BASE}/admin/books/create`);
  await expect(page.locator('#titolo')).toBeVisible({ timeout: 10000 });
  await page.fill('#titolo', title);
  for (const p of publishers) {
    await addNewPublisher(page, p);
  }
  await submitBook(page);
  return bookIdByTitle(title);
}

// ════════════════════════════════════════════════════════════════════════════
test.describe.serial('Multi-publisher (issue #143)', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  const created = [];

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);
  });

  test.afterAll(async () => {
    // Clean up books created by this run (FK cascade drops libri_editori rows).
    for (const id of created) {
      if (id > 0) dbQuery(`DELETE FROM libri WHERE id=${id}`);
    }
    dbQuery(`DELETE FROM editori WHERE nome LIKE 'MP_${RUN_ID}_%'`);
    await context?.close();
  });

  test('1. Create a book with two NEW publishers → both persisted', async () => {
    const title = `MP Book One ${RUN_ID}`;
    const id = await createBook(page, title, [`MP_${RUN_ID}_A`, `MP_${RUN_ID}_B`]);
    created.push(id);
    expect(id, 'book should be created').toBeGreaterThan(0);
    const pubs = publisherRows(id);
    expect(pubs).toContain(`MP_${RUN_ID}_A`);
    expect(pubs).toContain(`MP_${RUN_ID}_B`);
    expect(pubs.length).toBe(2);
  });

  test('2. Create a book with three NEW publishers → three junction rows', async () => {
    const title = `MP Book Three ${RUN_ID}`;
    const id = await createBook(page, title, [`MP_${RUN_ID}_C`, `MP_${RUN_ID}_D`, `MP_${RUN_ID}_E`]);
    created.push(id);
    expect(id).toBeGreaterThan(0);
    expect(publisherRows(id).length).toBe(3);
  });

  test('3. Primary editore_id equals the first selected publisher', async () => {
    const title = `MP Book Primary ${RUN_ID}`;
    const id = await createBook(page, title, [`MP_${RUN_ID}_F`, `MP_${RUN_ID}_G`]);
    created.push(id);
    expect(id).toBeGreaterThan(0);
    const firstId = parseInt(dbQuery(`SELECT id FROM editori WHERE nome='MP_${RUN_ID}_F' LIMIT 1`), 10) || -1;
    expect(primaryPublisherId(id)).toBe(firstId);
  });

  test('4. Create with an EXISTING publisher → linked by id, no duplicate editori row', async () => {
    const existing = `MP_${RUN_ID}_EXIST`;
    // Seed the publisher.
    await page.goto(`${BASE}/admin/publishers/create`);
    await page.fill('input[name="nome"]', existing);
    await page.click('button[type="submit"]');
    const c = page.locator('.swal2-confirm').first();
    if (await c.isVisible({ timeout: 4000 }).catch(() => false)) {
      // Match the list page (/admin/publishers[?...]), NOT /admin/publishers/create
      // we're already on — otherwise the wait resolves immediately and the SELECT
      // below can read before the insert's redirect has landed.
      await Promise.all([page.waitForURL(/\/admin\/publishers(?:$|\?)/, { timeout: 10000 }), c.click()]).catch(() => {});
    }
    const seededId = parseInt(dbQuery(`SELECT id FROM editori WHERE nome='${existing}' LIMIT 1`), 10) || 0;
    expect(seededId).toBeGreaterThan(0);

    const title = `MP Book Existing ${RUN_ID}`;
    await page.goto(`${BASE}/admin/books/create`);
    await page.fill('#titolo', title);
    await addExistingPublisher(page, existing);
    await addNewPublisher(page, `MP_${RUN_ID}_NEW1`);
    await submitBook(page);
    const id = bookIdByTitle(title);
    created.push(id);
    expect(id).toBeGreaterThan(0);

    const pubs = publisherRows(id);
    expect(pubs).toContain(existing);
    expect(pubs.length).toBe(2);
    // The existing publisher must not be duplicated as a new editori row.
    const dupCount = parseInt(dbQuery(`SELECT COUNT(*) FROM editori WHERE nome='${existing}'`), 10);
    expect(dupCount).toBe(1);
  });

  test('5. Typing the same publisher twice does not create duplicate junction rows', async () => {
    const title = `MP Book Dedup ${RUN_ID}`;
    await page.goto(`${BASE}/admin/books/create`);
    await page.fill('#titolo', title);
    await addNewPublisher(page, `MP_${RUN_ID}_DUP`);
    await addNewPublisher(page, `MP_${RUN_ID}_DUP`); // duplicate
    await submitBook(page);
    const id = bookIdByTitle(title);
    created.push(id);
    expect(id).toBeGreaterThan(0);
    expect(publisherRows(id).length).toBe(1);
  });

  test('6. Edit a book: add a publisher → junction grows', async () => {
    const title = `MP Book Edit Add ${RUN_ID}`;
    const id = await createBook(page, title, [`MP_${RUN_ID}_H`]);
    created.push(id);
    expect(publisherRows(id).length).toBe(1);

    await page.goto(`${BASE}/admin/books/edit/${id}`);
    await expect(page.locator('#editori_select')).toHaveCount(1);
    await addNewPublisher(page, `MP_${RUN_ID}_I`);
    await submitBook(page);

    const pubs = publisherRows(id);
    expect(pubs.length).toBe(2);
    expect(pubs).toContain(`MP_${RUN_ID}_I`);
  });

  test('7. Edit a book: remove a publisher → junction shrinks', async () => {
    const title = `MP Book Edit Remove ${RUN_ID}`;
    const id = await createBook(page, title, [`MP_${RUN_ID}_J`, `MP_${RUN_ID}_K`]);
    created.push(id);
    expect(publisherRows(id).length).toBe(2);

    await page.goto(`${BASE}/admin/books/edit/${id}`);
    const wrapper = page.locator('#editori_select').locator('xpath=ancestor::*[contains(@class,"choices")]').first();
    // Remove the first chip via its Choices remove button.
    const removeBtn = wrapper.locator('.choices__item .choices__button').first();
    await expect(removeBtn).toBeVisible({ timeout: 8000 });
    await removeBtn.click();
    await page.waitForTimeout(300);
    await submitBook(page);

    expect(publisherRows(id).length).toBe(1);
  });

  test('8. Edit form pre-populates existing publisher chips on load', async () => {
    const title = `MP Book Preload ${RUN_ID}`;
    const id = await createBook(page, title, [`MP_${RUN_ID}_L`, `MP_${RUN_ID}_M`]);
    created.push(id);

    await page.goto(`${BASE}/admin/books/edit/${id}`);
    const wrapper = page.locator('#editori_select').locator('xpath=ancestor::*[contains(@class,"choices")]').first();
    // Two selected chips rendered.
    await expect(wrapper.locator('.choices__list--multiple .choices__item')).toHaveCount(2, { timeout: 8000 });
    // Two existing-id hidden inputs.
    await expect(page.locator('#editori_hidden input[name="editori_ids[]"]')).toHaveCount(2);
  });

  test('9. Admin book detail page lists all publishers', async () => {
    const title = `MP Book AdminView ${RUN_ID}`;
    const id = await createBook(page, title, [`MP_${RUN_ID}_N`, `MP_${RUN_ID}_O`]);
    created.push(id);

    await page.goto(`${BASE}/admin/books/${id}`);
    const body = await page.locator('body').innerText();
    expect(body).toContain(`MP_${RUN_ID}_N`);
    expect(body).toContain(`MP_${RUN_ID}_O`);
  });

  test('10. Frontend book detail page shows all publishers', async () => {
    const title = `MP Book FrontView ${RUN_ID}`;
    const id = await createBook(page, title, [`MP_${RUN_ID}_P`, `MP_${RUN_ID}_Q`]);
    created.push(id);

    // Navigate to the frontend detail by id (controller redirects to slug URL).
    await page.goto(`${BASE}/admin/books/${id}`);
    const frontLink = page.locator('a[href*="/libro/"], a[href*="/book/"]').first();
    if (await frontLink.count() > 0) {
      const href = await frontLink.getAttribute('href');
      if (href) await page.goto(href.startsWith('http') ? href : `${BASE}${href}`);
    } else {
      // Fallback: try the canonical frontend route by id.
      await page.goto(`${BASE}/libro/${id}`).catch(() => {});
    }
    const body = await page.locator('body').innerText();
    expect(body).toContain(`MP_${RUN_ID}_P`);
    expect(body).toContain(`MP_${RUN_ID}_Q`);
  });
});
