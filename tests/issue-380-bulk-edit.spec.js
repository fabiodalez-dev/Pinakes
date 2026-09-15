// Issue #380: correcting the same field on a shelf of books, one book at a
// time, is the reason people stop correcting their catalogue.
//
// This drives the operator's path end to end — select in the list, open the
// bulk bar, pick field and mode, type a value, confirm — and then reads the
// database to check that a bulk edit leaves the books exactly where the
// single-book form would have left them. It also re-checks that the bulk
// actions that already existed are still wired up.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || process.env.APP_URL || 'http://localhost:8081';
const marker = `ZZ380E2E${Date.now()}`;

function db(sql) {
  const args = ['-u', process.env.E2E_DB_USER, process.env.E2E_DB_NAME, '-N', '-B', '-e', sql];
  if (process.env.E2E_DB_SOCKET) args.unshift('-S', process.env.E2E_DB_SOCKET);
  return execFileSync('mysql', args, { encoding: 'utf8', env: { ...process.env, MYSQL_PWD: process.env.E2E_DB_PASS } }).trim();
}

async function login(page) {
  await page.goto(BASE + '/admin/dashboard');
  if (await page.locator('input[name=email]').isVisible()) {
    await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL);
    await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASS);
    await page.locator('button[type=submit]').click();
    await page.waitForURL(u => !u.pathname.includes('accedi') && !u.pathname.includes('login'));
  }
}

// The list filters through the denormalized FULLTEXT column, so the seeded
// books carry their own index entry — exactly what a saved book would have.
function seedBook(title) {
  db(`INSERT INTO libri (titolo, search_index) VALUES ('${title}', '${title}')`);
  return Number(db(`SELECT id FROM libri WHERE titolo='${title}'`));
}

function creditsFor(bookId, role) {
  const out = db(`SELECT a.nome FROM libri_autori la JOIN autori a ON a.id=la.autore_id WHERE la.libro_id=${bookId} AND la.ruolo='${role}' ORDER BY a.nome`);
  return out === '' ? [] : out.split('\n');
}

// Fill the modal and apply. `mode` is 'add' or 'replace'.
async function bulkEdit(page, field, mode, value) {
  await page.locator('#bulk-edit-field').click();
  await expect(page.locator('#swal-bulk-field')).toBeVisible();
  await page.locator('#swal-bulk-field').selectOption(field);
  await page.locator(`input[name="swal-bulk-mode"][value="${mode}"]`).check();
  await page.locator('#swal-bulk-value').fill(value);
  await page.locator('.swal2-confirm').click();
  if (mode === 'replace') {
    // Replacing across a selection asks for a confirmation first.
    await expect(page.locator('.swal2-title')).toContainText('sostituzione');
    await page.locator('.swal2-confirm').click();
  }
}

let bookOne; let bookTwo; let existingAuthorId;

test.describe.serial('Issue 380 — manual bulk edit from the books list', () => {
  test.beforeAll(() => {
    if (!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_DB_USER) throw new Error('Run with /tmp/run-e2e.sh');
    bookOne = seedBook(`${marker} Uno`);
    bookTwo = seedBook(`${marker} Due`);
    // The first book already credits somebody: "add" must not cost it.
    db(`INSERT INTO autori (nome) VALUES ('${marker} Autore Esistente')`);
    existingAuthorId = Number(db(`SELECT id FROM autori WHERE nome='${marker} Autore Esistente'`));
    db(`INSERT INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito) VALUES (${bookOne}, ${existingAuthorId}, 'principale', 0)`);
  });

  test.afterAll(() => {
    try {
      db(`DELETE FROM libri WHERE id IN (${bookOne}, ${bookTwo})`);
      db(`DELETE FROM autori WHERE nome LIKE '${marker}%'`);
      db(`DELETE FROM editori WHERE nome LIKE '${marker}%'`);
    } catch (e) { console.error('Scoped cleanup failed:', e.message); }
  });

  test('one value reaches every selected book, without losing what was there', async ({ page }) => {
    await login(page);
    await page.goto(BASE + `/admin/books?keywords=${encodeURIComponent(marker)}`);

    const rows = page.locator('.row-select');
    await expect(rows).toHaveCount(2, { timeout: 15000 });
    await page.locator('#select-all').check();
    await expect(page.locator('#selected-count')).toHaveText('2');

    // Every bulk action that existed before must still be on the bar.
    for (const id of ['bulk-export', 'bulk-assign-collana', 'bulk-fetch-covers', 'bulk-delete', 'bulk-edit-field']) {
      await expect(page.locator('#' + id)).toBeVisible();
    }

    await bulkEdit(page, 'illustratori', 'add', `${marker} Illustratore`);
    await expect(page.locator('.swal2-title')).toContainText('Modifica applicata');
    await page.waitForLoadState('load');

    expect(creditsFor(bookOne, 'illustratore')).toEqual([`${marker} Illustratore`]);
    expect(creditsFor(bookTwo, 'illustratore')).toEqual([`${marker} Illustratore`]);
    // The name was typed once: it must be one author, not one per book.
    expect(db(`SELECT COUNT(*) FROM autori WHERE nome='${marker} Illustratore'`)).toBe('1');
    // And the denormalized column CSV export reads must follow.
    expect(db(`SELECT illustratore FROM libri WHERE id=${bookOne}`)).toBe(`${marker} Illustratore`);
  });

  test('add keeps the existing credits, replace takes their place', async ({ page }) => {
    await login(page);
    await page.goto(BASE + `/admin/books?keywords=${encodeURIComponent(marker)}`);
    await expect(page.locator('.row-select')).toHaveCount(2, { timeout: 15000 });
    await page.locator(`.row-select[data-id="${bookOne}"]`).check();

    await bulkEdit(page, 'autori', 'add', `${marker} Autore Nuovo`);
    await expect(page.locator('.swal2-title')).toContainText('Modifica applicata');
    await page.waitForLoadState('load');
    expect(creditsFor(bookOne, 'principale').sort()).toEqual(
      [`${marker} Autore Esistente`, `${marker} Autore Nuovo`].sort()
    );

    await page.goto(BASE + `/admin/books?keywords=${encodeURIComponent(marker)}`);
    await expect(page.locator('.row-select')).toHaveCount(2, { timeout: 15000 });
    await page.locator(`.row-select[data-id="${bookOne}"]`).check();
    await bulkEdit(page, 'autori', 'replace', `${marker} Autore Solo`);
    await expect(page.locator('.swal2-title')).toContainText('Modifica applicata');
    await page.waitForLoadState('load');
    expect(creditsFor(bookOne, 'principale')).toEqual([`${marker} Autore Solo`]);

    // The catalogue must stop finding the book under the author it no longer has.
    const indexed = db(`SELECT search_index FROM libri WHERE id=${bookOne}`);
    expect(indexed).toContain(`${marker} Autore Solo`);
    expect(indexed).not.toContain(`${marker} Autore Esistente`);
  });

  test('a publisher applies to the whole selection and becomes the primary one', async ({ page }) => {
    await login(page);
    await page.goto(BASE + `/admin/books?keywords=${encodeURIComponent(marker)}`);
    await expect(page.locator('.row-select')).toHaveCount(2, { timeout: 15000 });
    await page.locator('#select-all').check();

    await bulkEdit(page, 'editore', 'replace', `${marker} Editore`);
    await expect(page.locator('.swal2-title')).toContainText('Modifica applicata');
    await page.waitForLoadState('load');

    const publisherId = Number(db(`SELECT id FROM editori WHERE nome='${marker} Editore'`));
    expect(publisherId).toBeGreaterThan(0);
    expect(db(`SELECT COUNT(*) FROM libri WHERE id IN (${bookOne}, ${bookTwo}) AND editore_id=${publisherId}`)).toBe('2');
    expect(db(`SELECT COUNT(*) FROM libri_editori WHERE libro_id IN (${bookOne}, ${bookTwo}) AND editore_id=${publisherId}`)).toBe('2');
  });

  test('a value the catalogue does not know is refused, and nothing is written', async ({ page }) => {
    await login(page);
    await page.goto(BASE + `/admin/books?keywords=${encodeURIComponent(marker)}`);
    await expect(page.locator('.row-select')).toHaveCount(2, { timeout: 15000 });
    await page.locator('#select-all').check();

    await bulkEdit(page, 'genere', 'replace', `${marker} Genere Che Non Esiste`);
    // Genres are a curated taxonomy: a typo must not become one.
    await expect(page.locator('.swal2-title')).toContainText('Errore');
    expect(db(`SELECT COUNT(*) FROM libri WHERE id IN (${bookOne}, ${bookTwo}) AND genere_id IS NOT NULL`)).toBe('0');
  });
});
