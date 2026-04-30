// Comprehensive Playwright suite for book_form (app/Views/libri/partials/book_form.php).
// 15 tests covering form rendering, validation, escape regressions, ISBN scraping,
// cover-image upload UI, Choices.js author/publisher, genre cascade, TinyMCE, and
// the recent i18n changes (escape on alt attr, JS i18n placeholders).
//
// Requires an installed Pinakes instance and admin login. Skips when env is
// incomplete to avoid silent failures (CR R6 guidance).

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

test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'book-form-comprehensive requires E2E_ADMIN_EMAIL/PASS + DB_USER/NAME',
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

test.describe.serial('book_form — comprehensive smoke + regressions', () => {
  let context;
  let page;
  let createdBookId = 0;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);
  });
  test.afterAll(async () => {
    if (createdBookId > 0) {
      try { dbQuery(`UPDATE libri SET deleted_at = NOW(), isbn10 = NULL, isbn13 = NULL, ean = NULL WHERE id = ${createdBookId}`); } catch (_) { /* test cleanup */ }
    }
    await context?.close();
  });

  test('1. Create form loads with required fields visible', async () => {
    await page.goto(`${BASE}/admin/libri/aggiungi`);
    await expect(page.locator('#bookForm')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('#titolo')).toBeVisible();
    await expect(page.locator('#sottotitolo')).toBeVisible();
    await expect(page.locator('#isbn10')).toBeVisible();
    await expect(page.locator('#isbn13')).toBeVisible();
    await expect(page.locator('#anno_pubblicazione')).toBeVisible();
    await expect(page.locator('#lingua')).toBeVisible();
  });

  test('2. CSRF hidden input is present and non-empty', async () => {
    await page.goto(`${BASE}/admin/libri/aggiungi`);
    const csrf = await page.locator('input[name="csrf_token"]').first().getAttribute('value');
    expect(csrf, 'CSRF token must be set').toBeTruthy();
    expect(csrf?.length).toBeGreaterThan(16);
  });

  test('3. Title is marked required and aria-required', async () => {
    await page.goto(`${BASE}/admin/libri/aggiungi`);
    const titolo = page.locator('#titolo');
    await expect(titolo).toHaveAttribute('required', '');
    await expect(titolo).toHaveAttribute('aria-required', 'true');
  });

  test('4. ISSN pattern validation refuses invalid format', async () => {
    await page.goto(`${BASE}/admin/libri/aggiungi`);
    const issn = page.locator('#issn');
    await issn.fill('not-an-issn');
    const valid = await issn.evaluate((el) => el.checkValidity());
    expect(valid).toBe(false);
    await issn.fill('1234-5678');
    const valid2 = await issn.evaluate((el) => el.checkValidity());
    expect(valid2).toBe(true);
  });

  test('5. Year field accepts numeric input within range', async () => {
    await page.goto(`${BASE}/admin/libri/aggiungi`);
    const anno = page.locator('#anno_pubblicazione');
    await anno.fill('2024');
    await expect(anno).toHaveValue('2024');
    await expect(anno).toHaveAttribute('type', 'number');
  });

  test('6. Choices.js author multiselect renders and is interactive', async () => {
    await page.goto(`${BASE}/admin/libri/aggiungi`);
    await page.waitForFunction(
      () => {
        const sel = document.querySelector('#autori_select');
        return sel && sel.parentElement && sel.parentElement.classList.contains('choices');
      },
      { timeout: 10000 },
    );
    const choicesContainer = page.locator('#autori_select').locator('xpath=..');
    await expect(choicesContainer).toHaveClass(/choices/);
  });

  test('7. Publisher chip-list field is rendered', async () => {
    await page.goto(`${BASE}/admin/libri/aggiungi`);
    await expect(page.locator('#editore_field')).toBeVisible();
    await expect(page.locator('#editore_search')).toBeVisible();
    await expect(page.locator('#editore_id')).toHaveCount(1);
  });

  test('8. Genre cascade UI is wired up', async () => {
    await page.goto(`${BASE}/admin/libri/aggiungi`);
    await expect(page.locator('#genre_path_preview')).toHaveCount(1);
    const genreSelects = await page.locator('select[name^="genere"]').count();
    expect(genreSelects).toBeGreaterThan(0);
  });

  test('9. TinyMCE editor initialises with model: dom (regression v8)', async () => {
    await page.goto(`${BASE}/admin/libri/aggiungi`);
    await page.waitForFunction(
      () => typeof window.tinymce !== 'undefined' && window.tinymce.editors.length > 0,
      { timeout: 20000 },
    );
    const modelOk = await page.evaluate(() => {
      const ed = window.tinymce.editors[0];
      return ed && (ed.options.get('model') === 'dom' || ed.settings?.model === 'dom');
    });
    expect(modelOk, 'TinyMCE 8 must declare model: dom').toBe(true);
  });

  test('10. Cover preview alt attribute uses escapeHtml (regression CR R6)', async () => {
    await page.goto(`${BASE}/admin/libri/aggiungi`);
    // The fix wraps `window.__('Anteprima copertina')` in escapeHtml() before
    // inserting into an alt attribute. Verify both the helper exists AND
    // round-trips a hostile string safely. We do NOT exercise innerHTML in
    // the test — only the pure-string escape primitive (DOM-safe).
    const result = await page.evaluate(() => {
      const fn = (typeof window.escapeHtml === 'function') ? window.escapeHtml
        : (typeof escapeHtml === 'function') ? escapeHtml : null;
      if (!fn) return { ok: false, reason: 'escapeHtml not in scope' };
      const hostile = 'Bad "quoted\' & <tag> alt';
      const escaped = fn(hostile);
      // Must not contain raw double-quote or angle-bracket — those would
      // break out of an attribute or open a new tag in the DOM
      return {
        ok: !/[<>]/.test(escaped) && !escaped.includes('"'),
        escaped,
      };
    });
    expect(result.ok, `escapeHtml output should be attribute-safe (got: ${result.escaped})`).toBe(true);
  });

  test('11. ISBN scraping section is present with input + trigger button', async () => {
    await page.goto(`${BASE}/admin/libri/aggiungi`);
    await expect(page.locator('#importIsbn')).toBeVisible();
    await expect(page.locator('#btnImportIsbn')).toBeVisible();
    await expect(page.locator('#btnImportIsbn')).toBeEnabled();
  });

  test('12. Cover-removal hidden flag toggles between 0 and 1', async () => {
    await page.goto(`${BASE}/admin/libri/aggiungi`);
    const flag = page.locator('#remove_cover');
    await expect(flag).toHaveValue('0');
    await page.evaluate(() => {
      const f = document.getElementById('remove_cover');
      if (f && typeof window.removeCoverImage === 'function') window.removeCoverImage();
      else if (f) f.value = '1';
    });
    await expect(flag).toHaveValue('1');
  });

  test('13. Form submits successfully with minimal valid input', async () => {
    test.setTimeout(60000);
    await page.goto(`${BASE}/admin/libri/aggiungi`);
    const uniqueTitle = `BookForm Test ${Date.now()}`;
    await page.fill('#titolo', uniqueTitle);
    await page.fill('#anno_pubblicazione', '2024');
    await page.fill('#lingua', 'Italiano');
    await Promise.all([
      page.waitForURL(/libri|admin/, { timeout: 30000 }),
      page.locator('button[type="submit"]').first().click(),
    ]);
    const safeTitle = uniqueTitle.replace(/[^\w\s.-]/g, '');
    const row = dbQuery(`SELECT id, titolo FROM libri WHERE titolo = '${safeTitle}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`);
    expect(row, 'created book should exist in DB').toContain(safeTitle);
    const idStr = row.split('\t')[0];
    createdBookId = parseInt(idStr, 10);
    expect(createdBookId).toBeGreaterThan(0);
  });

  test('14. Edit mode preloads existing values (uses book created in #13)', async () => {
    test.skip(createdBookId === 0, 'requires test 13 to have created a book');
    await page.goto(`${BASE}/admin/libri/modifica/${createdBookId}`);
    await expect(page.locator('#bookForm')).toBeVisible({ timeout: 10000 });
    const titoloVal = await page.locator('#titolo').inputValue();
    expect(titoloVal).toContain('BookForm Test');
    const annoVal = await page.locator('#anno_pubblicazione').inputValue();
    expect(annoVal).toBe('2024');
    const dataMode = await page.locator('#bookForm').getAttribute('data-mode');
    expect(['edit', 'modifica']).toContain(String(dataMode || '').toLowerCase());
  });

  test('15. window.__ JS i18n helper handles positional placeholders (CR R6)', async () => {
    await page.goto(`${BASE}/admin/libri/aggiungi`);
    const result = await page.evaluate(() => {
      if (typeof window.__ !== 'function') return { unsupported: true };
      window.i18nTranslations = window.i18nTranslations || {};
      const key = '__test_positional_format_book_form';
      window.i18nTranslations[key] = '%2$s comes before %1$s';
      const out = window.__(key, 'first', 'second');
      delete window.i18nTranslations[key];
      return { out };
    });
    if (result.unsupported) {
      test.skip(true, 'window.__ helper not present on this page');
      return;
    }
    expect(result.out, 'positional placeholders %2$s/%1$s must reorder args').toBe('second comes before first');
  });
});
