// @ts-check
/**
 * #489 — a copy received through Desiderata carries the book's own
 * "Prefisso inventario copie", through the real admin screens.
 *
 * Each wanted book is created from the admin book form with a prefix, then
 * received through one of the three buttons an operator actually has:
 *
 *   A. edit the record, untick "Desiderata", save with two copies
 *   B. "Libro donato: registra la copia" on /admin/desiderata
 *   C. a donor's proposal: "Accetta proposta", then
 *      "Libro arrivato: registra una copia"
 *
 * The code is read where the operator reads it: the Copie Fisiche table of the
 * book page. A fourth book without a prefix keeps the LIB-<id>-C1 default.
 *
 * Run: /tmp/run-e2e.sh tests/desiderata-inventory-prefix-489.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const crypto = require('node:crypto');
const { ensurePluginActive } = require('./helpers/plugin-activation');

// The receipt buttons confirm through pk-actions.js (data-pk-confirm), which
// uses SweetAlert2 where the admin layout loads it: click, then confirm.
async function clickAndConfirm(page, locator) {
  await locator.click();
  const confirm = page.locator('.swal2-confirm');
  await confirm.waitFor({ state: 'visible', timeout: 15000 });
  await confirm.click();
}

const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_NAME, 'Requires an installed app, E2E_ADMIN_* and E2E_DB_* credentials');
test.describe.configure({ mode: 'serial' });

// Unique per run: copy codes are globally unique, so a fixed prefix would
// continue a previous run's -C<n> family instead of starting at -C1.
const TAG = 'E2E489' + crypto.randomBytes(4).toString('hex');
const bookIds = [];

function dbQuery(sql) {
  const args = ['-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql];
  if (DB_SOCKET) args.splice(2, 0, '-S', DB_SOCKET);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}

async function login(page) {
  await page.goto('/accedi');
  await page.getByRole('textbox', { name: 'Email', exact: true }).fill(ADMIN_EMAIL);
  await page.getByRole('textbox', { name: 'Password', exact: true }).fill(ADMIN_PASS);
  await page.getByRole('button', { name: 'Accedi', exact: true }).click();
  await page.waitForURL(/\/admin/);
}

/** Submit the core book form, confirming its SweetAlert when it asks. */
async function submitBookForm(page) {
  await page.locator('#bookForm button[type="submit"]').click();
  const confirm = page.locator('.swal2-confirm');
  if (await confirm.isVisible({ timeout: 5000 }).catch(() => false)) {
    await confirm.click();
  }
  await page.waitForURL(/\/admin\/books\/(?!create|edit)\d+/, { timeout: 15000 });
}

/** A wanted book created from the admin form, as an operator does it. */
async function createWanted(page, label, prefix) {
  const title = `${TAG} ${label}`;
  await page.goto('/admin/books/create');
  await page.fill('#titolo', title);
  await page.getByRole('checkbox', { name: 'Desiderata: cerchiamo questo libro', exact: true }).check();
  if (prefix !== null) {
    await page.fill('#numero_inventario', prefix);
  }
  await submitBookForm(page);
  const id = Number(dbQuery(`SELECT id FROM libri WHERE titolo = '${title}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`));
  expect(id).toBeGreaterThan(0);
  bookIds.push(id);
  expect(dbQuery(`SELECT is_desiderata FROM libri WHERE id = ${id}`)).toBe('1');
  return { id, title };
}

/** The codes the operator sees in the book page's Copie Fisiche table. */
async function expectCopyCodes(page, bookId, codes) {
  await page.goto(`/admin/books/${bookId}#physical-copies`);
  const section = page.locator('#physical-copies');
  for (const code of codes) {
    await expect(section).toContainText(code);
  }
  const stored = dbQuery(`SELECT GROUP_CONCAT(numero_inventario ORDER BY id SEPARATOR ',') FROM copie WHERE libro_id = ${bookId}`);
  expect(stored).toBe(codes.join(','));
}

test.beforeAll(async ({ browser }) => {
  const context = await browser.newContext();
  try {
    const page = await context.newPage();
    await login(page);
    await ensurePluginActive(page, 'desiderata', { smokePath: '/desiderata' });
  } finally {
    await context.close();
  }
});

test.afterAll(() => {
  if (bookIds.length === 0) return;
  const list = bookIds.join(',');
  dbQuery(`DELETE FROM desiderata_offers WHERE book_id IN (${list}) OR received_book_id IN (${list})`);
  dbQuery(`DELETE FROM admin_notifications WHERE title LIKE '%${TAG}%' OR message LIKE '%${TAG}%'`);
  dbQuery(`DELETE FROM copie WHERE libro_id IN (${list})`);
  dbQuery(`DELETE FROM log_modifiche WHERE tabella = 'libri' AND record_id IN (${list})`);
  dbQuery(`DELETE FROM libri WHERE id IN (${list})`);
});

test('A — closing the request from the book form uses the prefix', async ({ page }) => {
  await login(page);
  const prefix = `BIB-${TAG}A`;
  const book = await createWanted(page, 'form', prefix);
  await page.goto(`/admin/books/edit/${book.id}`);
  await page.getByRole('checkbox', { name: 'Desiderata: cerchiamo questo libro', exact: true }).uncheck();
  await page.fill('#desiderata_copies', '2');
  await submitBookForm(page);
  await expectCopyCodes(page, book.id, [`${prefix}-C1`, `${prefix}-C2`]);
});

test('B — "Libro donato" on the requests list uses the prefix', async ({ page }) => {
  await login(page);
  const prefix = `BIB-${TAG}B`;
  const book = await createWanted(page, 'direct', prefix);
  page.on('dialog', (dialog) => dialog.accept());
  await page.goto('/admin/desiderata');
  const row = page.locator('li').filter({ hasText: book.title });
  // The click starts on /admin/desiderata, so waiting for that URL returns at once:
  // wait for the receipt POST itself before reading the copies.
  await Promise.all([
    page.waitForResponse((res) => res.request().method() === 'POST' && /\/admin\/desiderata\/books\/\d+\/received/.test(res.url())),
    clickAndConfirm(page, row.getByRole('button', { name: 'Libro donato: registra la copia', exact: true })),
  ]);
  await expectCopyCodes(page, book.id, [`${prefix}-C1`]);
});

test('C — a donor proposal received through the admin buttons uses the prefix', async ({ page }) => {
  await login(page);
  const prefix = `BIB-${TAG}C`;
  const book = await createWanted(page, 'offer', prefix);
  // The donor's side is not under test here: the proposal row is what the
  // public form writes, inserted directly so this spec does not depend on the
  // anonymous form's rate limiter or captcha settings.
  dbQuery(`INSERT INTO desiderata_offers (book_id, donor_name, donor_email, title, notes, status) VALUES (${book.id}, 'E2E donor', 'donor-${TAG}@example.invalid', '${book.title}', '', 'pending')`);
  page.on('dialog', (dialog) => dialog.accept());
  await page.goto('/admin/desiderata');
  let offer = page.locator('article').filter({ hasText: book.title });
  await offer.getByRole('button', { name: 'Accetta proposta', exact: true }).click();
  offer = page.locator('article').filter({ hasText: book.title });
  await expect(offer).toContainText('In attesa della consegna');
  await clickAndConfirm(page, offer.getByRole('button', { name: 'Libro arrivato: registra una copia', exact: true }));
  await expect(page.locator('article').filter({ hasText: book.title })).toContainText('Ricevuta');
  await expectCopyCodes(page, book.id, [`${prefix}-C1`]);
});

test('without a prefix the copy keeps the LIB-<id> default', async ({ page }) => {
  await login(page);
  const book = await createWanted(page, 'no prefix', null);
  page.on('dialog', (dialog) => dialog.accept());
  await page.goto('/admin/desiderata');
  const row = page.locator('li').filter({ hasText: book.title });
  // The click starts on /admin/desiderata, so waiting for that URL returns at once:
  // wait for the receipt POST itself before reading the copies.
  await Promise.all([
    page.waitForResponse((res) => res.request().method() === 'POST' && /\/admin\/desiderata\/books\/\d+\/received/.test(res.url())),
    clickAndConfirm(page, row.getByRole('button', { name: 'Libro donato: registra la copia', exact: true })),
  ]);
  await expectCopyCodes(page, book.id, [`LIB-${book.id}-C1`]);
});
