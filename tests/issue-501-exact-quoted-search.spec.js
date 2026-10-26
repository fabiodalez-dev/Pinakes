// @ts-check
/**
 * Issue #501: "haffner" also found "Häffner" because the collation folds
 * accents. Unquoted searches keep that tolerance; a quoted term matches the
 * exact spelling. Driven through the real search boxes a librarian types in:
 * the admin book list, the admin author list and the public catalogue.
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'E2E credentials not configured (set E2E_ADMIN_EMAIL, E2E_ADMIN_PASS, E2E_DB_*)');

function dbQuery(sql) {
  const args = [];
  if (DB_HOST) {
    args.push('-h', DB_HOST);
    if (DB_PORT) args.push('-P', DB_PORT);
  } else if (DB_SOCKET) {
    args.push('-S', DB_SOCKET);
  }
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '--default-character-set=utf8mb4', '-e', sql);
  return execFileSync('mysql', args, {
    encoding: 'utf-8', timeout: 10000,
    env: { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}

// Letters only: the token becomes part of a FULLTEXT word in every title.
const TOKEN = 'zq' + Math.random().toString(36).replace(/[^a-z]/g, '').slice(0, 6);
const PLAIN = `Rolf Haffner ${TOKEN}`;
const UMLAUT = `Anna Häffner ${TOKEN}`;
const TITLE_PLAIN = `${TOKEN} Brückenbuch`;
const TITLE_UMLAUT = `${TOKEN} Gedichtband`;
const ids = { authors: [], books: [] };

/** Insert an author + one principal-author book; ids come from the same connection. */
function seedBook(author, title) {
  const out = dbQuery(
    `INSERT INTO autori (nome) VALUES ('${author}'); SET @a = LAST_INSERT_ID();`
    + ` INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES ('${title}', 1, 1); SET @b = LAST_INSERT_ID();`
    + ` INSERT INTO libri_autori (libro_id, autore_id, ruolo) VALUES (@b, @a, 'principale');`
    + ' SELECT @a, @b;',
  );
  const [authorId, bookId] = out.split(/\s+/).map(Number);
  ids.authors.push(authorId);
  ids.books.push(bookId);
  return bookId;
}

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const email = page.locator('input[name="email"]');
  if (await email.isVisible({ timeout: 4000 }).catch(() => false)) {
    await email.fill(ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/.*(?:dashboard|admin).*/, { timeout: 15000 });
  }
}

/** Type into a DataTables filter box and wait for the reload it triggers. */
async function typeAndWait(page, selector, value, apiPath) {
  const reloaded = page.waitForResponse((r) => r.url().includes(apiPath) && r.status() === 200, { timeout: 15000 });
  await page.fill(selector, value);
  await reloaded;
  // The table repaints after the JSON lands.
  await page.waitForTimeout(300);
}

test.describe.serial('#501 quoted search = exact spelling', () => {
  test.beforeAll(() => {
    seedBook(PLAIN, TITLE_PLAIN);
    seedBook(UMLAUT, TITLE_UMLAUT);
    // Same rebuild the save paths run, so search_index holds the author names.
    const root = path.join(__dirname, '..');
    execFileSync('php', ['-r', `
      require '${root}/vendor/autoload.php';
      $db = getenv('E2E_DB_HOST')
        ? new mysqli(getenv('E2E_DB_HOST'), getenv('E2E_DB_USER'), getenv('E2E_DB_PASS'), getenv('E2E_DB_NAME'), (int) (getenv('E2E_DB_PORT') ?: 3306))
        : new mysqli(null, getenv('E2E_DB_USER'), getenv('E2E_DB_PASS'), getenv('E2E_DB_NAME'), 0, getenv('E2E_DB_SOCKET'));
      $db->set_charset('utf8mb4');
      App\\Support\\SearchIndexBuilder::rebuildMany($db, [${ids.books.join(',')}]);
    `], { encoding: 'utf-8', timeout: 20000, env: process.env });
  });

  test.afterAll(() => {
    if (ids.books.length) {
      dbQuery(`DELETE FROM libri_autori WHERE libro_id IN (${ids.books.join(',')})`);
      dbQuery(`DELETE FROM libri WHERE id IN (${ids.books.join(',')})`);
    }
    if (ids.authors.length) {
      dbQuery(`DELETE FROM autori WHERE id IN (${ids.authors.join(',')})`);
    }
  });

  test('admin book list: unquoted finds both, quoted finds one spelling', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books`);
    const table = page.locator('table').first();

    await typeAndWait(page, '#search_text', `${TOKEN} haffner`, '/api/libri');
    await expect(table).toContainText(TITLE_PLAIN);
    await expect(table).toContainText(TITLE_UMLAUT);

    await typeAndWait(page, '#search_text', `${TOKEN} "haffner"`, '/api/libri');
    await expect(table).toContainText(TITLE_PLAIN);
    await expect(table).not.toContainText(TITLE_UMLAUT);

    await typeAndWait(page, '#search_text', `${TOKEN} "Häffner`, '/api/libri');
    await expect(table).toContainText(TITLE_UMLAUT);
    await expect(table).not.toContainText(TITLE_PLAIN);
  });

  test('admin author list: quoted name excludes the umlaut spelling', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/authors`);
    const table = page.locator('table').first();

    await typeAndWait(page, '#search_nome', `haffner ${TOKEN}`, '/api/autori');
    await expect(table).toContainText(PLAIN);
    await expect(table).toContainText(UMLAUT);

    await typeAndWait(page, '#search_nome', `"haffner ${TOKEN}"`, '/api/autori');
    await expect(table).toContainText(PLAIN);
    await expect(table).not.toContainText(UMLAUT);
  });

  test('public catalogue: quoted search from the search box', async ({ page }) => {
    await page.goto(`${BASE}/catalogo`);
    const grid = page.locator('main');

    const search = async (value) => {
      const done = page.waitForResponse((r) => r.url().includes('search=') && r.status() === 200, { timeout: 15000 });
      await page.fill('#search-input', value);
      await page.locator('#search-input').press('End');
      await done;
      await page.waitForTimeout(300);
    };

    await search(`${TOKEN} haffner`);
    await expect(grid).toContainText(TITLE_PLAIN);
    await expect(grid).toContainText(TITLE_UMLAUT);

    await search(`${TOKEN} "haffner"`);
    await expect(grid).toContainText(TITLE_PLAIN);
    await expect(grid).not.toContainText(TITLE_UMLAUT);
  });
});
