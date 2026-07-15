// @ts-check
/**
 * Issue #237 — browser regression for contributor entities and pseudonyms.
 *
 * Hermetic fixtures cover the complete UI contract:
 *   - search/select an existing principal by pseudonym;
 *   - save an illustrator as an author entity with the right role;
 *   - display "Pseudonym (Canonical name)" and the illustrator publicly;
 *   - resubmit an existing co-author without promoting/duplicating the role.
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

test.skip(!DB_USER || !DB_NAME, 'Set the E2E_DB_* variables');

const RUN_ID = `${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
const ADMIN_EMAIL = `issue237_${RUN_ID}@test.local`;
const ADMIN_PASS = 'Test1234!Aa';
const REAL_NAME = `Issue237 Real ${RUN_ID}`;
const PSEUDONYM = `Issue237Pseudo${RUN_ID}`;
const ILLUSTRATOR = `Issue237 Illustrator ${RUN_ID}`;
const COAUTHOR = `Issue237 Coauthor ${RUN_ID}`;
const BOOK_TITLE = `Issue237 Book ${RUN_ID}`;

function mysqlArgs(sql, batch = false) {
  const args = [];
  if (DB_HOST) {
    args.push('-h', DB_HOST);
    if (DB_PORT) args.push('-P', DB_PORT);
  } else if (DB_SOCKET) {
    args.push('-S', DB_SOCKET);
  }
  args.push('-u', DB_USER, DB_NAME);
  if (batch) args.push('-N', '-B');
  args.push('-e', sql);
  return args;
}

function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql, true), {
    encoding: 'utf8',
    env: { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}

function dbExec(sql) {
  execFileSync('mysql', mysqlArgs(sql), {
    encoding: 'utf8',
    env: { ...process.env, MYSQL_PWD: DB_PASS },
  });
}

async function selectExisting(page, selectId, query, expectedLabel) {
  const wrapper = page.locator('.choices').filter({ has: page.locator(`#${selectId}`) }).first();
  await expect(wrapper).toBeVisible();
  await wrapper.click();
  const input = wrapper.locator('input.choices__input').first();
  await input.fill(query);
  const suggestion = wrapper.locator('.choices__list--dropdown .choices__item--selectable')
    .filter({ hasText: expectedLabel }).first();
  await expect(suggestion).toBeVisible({ timeout: 10_000 });
  await suggestion.click();
  await expect(wrapper.locator('.choices__list--multiple .choices__item').filter({ hasText: expectedLabel }))
    .toBeVisible();
}

async function submitBookForm(page) {
  const button = page.locator('#bookForm button[type="submit"], button[type="submit"]').first();
  await button.scrollIntoViewIfNeeded();
  await button.click();
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

test.describe.serial('Issue #237 — contributor roles and pseudonyms', () => {
  let context;
  let page;
  let adminId = 0;
  let principalId = 0;
  let illustratorId = 0;
  let coauthorId = 0;
  let bookId = 0;

  test.beforeAll(async ({ browser }) => {
    const hash = execFileSync(
      'php',
      ['-r', `echo password_hash(${JSON.stringify(ADMIN_PASS)}, PASSWORD_DEFAULT);`],
      { encoding: 'utf8' },
    ).trim().replace(/'/g, "''");

    dbExec(`INSERT INTO utenti
      (codice_tessera, nome, cognome, email, password, privacy_accettata,
       data_accettazione_privacy, tipo_utente, stato, email_verificata, locale)
      VALUES ('I237${RUN_ID.replace(/\D/g, '').slice(-12)}', 'Issue', '237', '${ADMIN_EMAIL}',
              '${hash}', 1, NOW(), 'admin', 'attivo', 1, 'it_IT')`);
    adminId = Number(dbQuery(`SELECT id FROM utenti WHERE email='${ADMIN_EMAIL}'`));

    dbExec(`INSERT INTO autori (nome, pseudonimo) VALUES
      ('${REAL_NAME}', '${PSEUDONYM}'),
      ('${ILLUSTRATOR}', NULL),
      ('${COAUTHOR}', NULL)`);
    principalId = Number(dbQuery(`SELECT id FROM autori WHERE nome='${REAL_NAME}'`));
    illustratorId = Number(dbQuery(`SELECT id FROM autori WHERE nome='${ILLUSTRATOR}'`));
    coauthorId = Number(dbQuery(`SELECT id FROM autori WHERE nome='${COAUTHOR}'`));

    context = await browser.newContext();
    page = await context.newPage();
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
      page.waitForURL(url => url.toString().includes('/admin'), { timeout: 20_000 }),
      page.locator('button[type="submit"]').click(),
    ]);
  });

  test.afterAll(async () => {
    try {
      if (bookId > 0) {
        dbExec(`DELETE FROM libri WHERE id=${bookId}`);
      }
      dbExec(`DELETE FROM autori WHERE id IN (${[principalId, illustratorId, coauthorId].filter(Boolean).join(',') || '0'})`);
      if (adminId > 0) {
        dbExec(`DELETE FROM user_sessions WHERE utente_id=${adminId}`);
        dbExec(`DELETE FROM utenti WHERE id=${adminId}`);
      }
    } finally {
      await context?.close();
    }
  });

  test('pseudonym search and illustrator entity survive save and public display', async () => {
    await page.goto(`${BASE}/admin/books/create`);
    await page.locator('input[name="titolo"]').fill(BOOK_TITLE);
    await selectExisting(page, 'autori_select', PSEUDONYM, `${PSEUDONYM} (${REAL_NAME})`);
    await selectExisting(page, 'illustratori_select', ILLUSTRATOR, ILLUSTRATOR);
    await submitBookForm(page);

    bookId = Number(dbQuery(`SELECT id FROM libri WHERE titolo='${BOOK_TITLE}' AND deleted_at IS NULL`));
    expect(bookId).toBeGreaterThan(0);
    expect(Number(dbQuery(`SELECT COUNT(*) FROM libri_autori WHERE libro_id=${bookId} AND autore_id=${principalId} AND ruolo='principale'`))).toBe(1);
    expect(Number(dbQuery(`SELECT COUNT(*) FROM libri_autori WHERE libro_id=${bookId} AND autore_id=${illustratorId} AND ruolo='illustratore'`))).toBe(1);

    await page.goto(`${BASE}/libro/${bookId}`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('body')).toContainText(`${PSEUDONYM} (${REAL_NAME})`);
    await expect(page.locator('body')).toContainText(ILLUSTRATOR);
  });

  test('editing a book preserves an existing co-author role', async () => {
    dbExec(`INSERT INTO libri_autori (libro_id, autore_id, ruolo) VALUES (${bookId}, ${coauthorId}, 'co-autore')`);
    await page.goto(`${BASE}/admin/books/edit/${bookId}`, { waitUntil: 'domcontentloaded' });
    const authorWrapper = page.locator('.choices').filter({ has: page.locator('#autori_select') }).first();
    await expect(authorWrapper.locator('.choices__list--multiple')).toContainText(COAUTHOR);
    await submitBookForm(page);

    const roles = dbQuery(`SELECT ruolo FROM libri_autori WHERE libro_id=${bookId} AND autore_id=${coauthorId} ORDER BY ruolo`);
    expect(roles).toBe('co-autore');
  });
});
