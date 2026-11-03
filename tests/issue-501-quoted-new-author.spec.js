// @ts-check
/**
 * Issue #501, decision of 2026-10-27: when only "Haffner" is on file, a book
 * saved with author "Häffner" is still linked to Haffner — the accent-tolerant
 * dedup is what merges "Garcia Marquez" with "García Márquez". Typed IN
 * QUOTES, the name means that exact spelling: a new author is created, stored
 * without the quotes. Driven through the real book form (Choices.js + save).
 */
const { test, expect } = require('@playwright/test');

// e2e-fixtures throws while loading when the E2E environment is missing, so
// it is required only when the suite is going to run; the skip reports why.
const E2E_READY = Boolean(process.env.E2E_BASE_URL && process.env.E2E_DB_USER && process.env.E2E_DB_NAME
  && (process.env.E2E_DB_HOST || process.env.E2E_DB_SOCKET));
test.skip(!E2E_READY, 'E2E environment not configured (use /tmp/run-e2e.sh)');
const { createTempAdminUser, deleteTempAdminUser, dbQuery, dbExec } = E2E_READY ? require('./helpers/e2e-fixtures') : {};
const { makeToken, login } = require('./helpers/exact-search-501');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';

test.describe.serial('#501 quoted new author = separate person', () => {
  const token = makeToken();
  const plain = `${token} Haffner`;
  const umlaut = `${token} Häffner`;
  /** @type {{id:number,email:string,password:string}} */
  let admin;
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  let plainId = 0;

  test.beforeAll(async ({ browser }) => {
    dbExec(`INSERT INTO autori (nome) VALUES ('${plain}')`);
    plainId = Number(dbQuery(`SELECT id FROM autori WHERE nome = '${plain}' ORDER BY id DESC LIMIT 1`));
    admin = createTempAdminUser('it_IT');
    context = await browser.newContext();
    page = await context.newPage();
    await login(page, BASE, admin.email, admin.password);
  });

  test.afterAll(async () => {
    await context?.close();
    try {
      dbExec(`DELETE la FROM libri_autori la JOIN libri l ON l.id = la.libro_id WHERE l.titolo LIKE '${token}%'`);
      dbExec(`DELETE FROM libri WHERE titolo LIKE '${token}%'`);
      dbExec(`DELETE FROM autori WHERE nome LIKE '${token}%' OR nome LIKE '"${token}%'`);
    } finally {
      deleteTempAdminUser(admin?.id);
    }
  });

  /**
   * Create a book from the admin form with one author typed in the picker,
   * and return the ids of the authors it was saved with.
   * @param {string} title
   * @param {string} typedAuthor
   */
  async function saveBookWithAuthor(title, typedAuthor) {
    await page.goto(`${BASE}/admin/books/create`);
    await page.fill('#titolo', title);
    const authorInput = page.locator('label[for="autori_select"]')
      .locator('xpath=following::input[contains(@class,"choices__input--cloned")][1]');
    await expect(authorInput).toBeVisible({ timeout: 10000 });
    await authorInput.click();
    await authorInput.fill(typedAuthor);
    await expect(authorInput).toHaveValue(typedAuthor);
    // Enter is the picker's contract for "use what I typed" (#74): it commits
    // the typed text even while a similar existing author is highlighted, so
    // it is the only action that covers both the existing and the new name.
    // The chip text then proves which choice was taken, independent of when
    // the server suggestions arrived: a highlighted "Haffner" picked instead
    // of the typed "Häffner" fails here.
    await authorInput.press('Enter');
    const chip = page.locator('.choices__list--multiple .choices__item').first();
    await expect(chip).toBeVisible({ timeout: 10000 });
    await expect(chip).toContainText(typedAuthor.replace(/["“”„«»]/g, ''));
    const genre = page.locator('#radice_select');
    if (await genre.count()) {
      await page.waitForFunction(() => {
        const sel = /** @type {HTMLSelectElement|null} */ (document.querySelector('#radice_select'));
        return !sel || sel.options.length > 1;
      }, null, { timeout: 10000 });
      const options = await genre.locator('option').allInnerTexts();
      if (options.length > 1) {
        await genre.selectOption({ index: 1 });
      }
    }
    await page.locator('#bookForm button[type="submit"]').click();
    await page.locator('.swal2-confirm').click({ timeout: 10000 });
    await page.waitForURL(/admin\/books(?!.*create)/, { timeout: 20000 });
    const bookId = Number(dbQuery(`SELECT id FROM libri WHERE titolo = '${title}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`));
    expect(bookId).toBeGreaterThan(0);
    return dbQuery(`SELECT la.autore_id FROM libri_autori la WHERE la.libro_id = ${bookId} ORDER BY la.autore_id`)
      .split(/\s+/).filter(Boolean).map(Number);
  }

  test('1/4 without quotes, Häffner is still merged into the only Haffner', async () => {
    const authors = await saveBookWithAuthor(`${token} Buch eins`, umlaut);
    expect(authors).toEqual([plainId]);
  });

  test('2/4 in quotes, Häffner becomes a new author', async () => {
    const authors = await saveBookWithAuthor(`${token} Buch zwei`, `"${umlaut}"`);
    expect(authors).toHaveLength(1);
    expect(authors[0]).not.toBe(plainId);
  });

  test('3/4 the new author is stored without the quotes', async () => {
    // Compared as hex bytes: the mysql CLI behind dbQuery does not speak
    // utf8mb4, so a plain read would garble the umlaut.
    const stored = dbQuery(`SELECT HEX(nome) FROM autori WHERE nome LIKE '%${token}%' ORDER BY id`).split('\n');
    const hex = (/** @type {string} */ v) => Buffer.from(v, 'utf8').toString('hex').toUpperCase();
    expect(stored).toEqual([hex(plain), hex(umlaut)]);
  });

  test('4/4 once both exist, unquoted Häffner links to Häffner, not Haffner', async () => {
    const umlautId = Number(dbQuery(`SELECT id FROM autori WHERE HEX(nome) = '${Buffer.from(umlaut, 'utf8').toString('hex').toUpperCase()}' LIMIT 1`));
    const authors = await saveBookWithAuthor(`${token} Buch drei`, umlaut);
    expect(authors).toEqual([umlautId]);
  });
});
