// E2E: Emeroteca plugin through the real browser.
// The unit suite (tests/emeroteca.unit.php) covers schema + kardex logic;
// THIS spec proves the user-visible surface: bundled-plugin activation from
// the real /admin/plugins UI, testata creation from the real form, annata +
// fascicoli creation from the issues page, and the public /emeroteca
// frontend (index, testata grid, fascicolo page). Conventions follow
// activity-feed-374.spec.js (env parsing, MYSQL_PWD-based dbQuery, skip
// guard, console-error guard, FK-safe cleanup).
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';

const e2e = (key) => {
  const v = process.env[key];
  return v === undefined || v === 'undefined' ? '' : v;
};
const DB_USER = e2e('E2E_DB_USER');
const DB_PASS = e2e('E2E_DB_PASS');
const DB_HOST = e2e('E2E_DB_HOST');
const DB_PORT = e2e('E2E_DB_PORT');
const DB_SOCKET = e2e('E2E_DB_SOCKET');
const DB_NAME = e2e('E2E_DB_NAME');

function dbQuery(sql) {
  // Password via MYSQL_PWD env, never as a -p argv (visible in ps//proc).
  const args = ['-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql];
  if (DB_HOST) {
    args.splice(2, 0, '-h', DB_HOST);
    if (DB_PORT) args.splice(4, 0, '-P', DB_PORT);
  } else if (DB_SOCKET) {
    args.splice(2, 0, '-S', DB_SOCKET);
  }
  return execFileSync('mysql', args, {
    encoding: 'utf-8',
    timeout: 10000,
    env: { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}

const EM_SKIP = !e2e('E2E_ADMIN_EMAIL') || !e2e('E2E_ADMIN_PASS') || !DB_USER || !DB_PASS || !DB_NAME;

const sqlEscape = (s) => String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
const RUN = Date.now().toString(36) + 'em';
const TITLE = `EmerotecaE2E Rivista ${RUN}`;
const ANNO = 2024;
let testataId = '';
let annataId = '';
let fascicoloIds = [];

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const email = page.locator('input[name="email"]');
  if (await email.isVisible({ timeout: 3000 }).catch(() => false)) {
    await email.fill(process.env.E2E_ADMIN_EMAIL);
    await page.fill('input[name="password"]', process.env.E2E_ADMIN_PASS);
    await page.click('button[type="submit"]');
    // Guarda il pathname, non l'URL intero: un login fallito resta su
    // /accedi?redirect=%2Fadmin%2F... che matcherebbe /admin/ nell'URL.
    await page.waitForFunction(
      () => !location.pathname.includes('accedi') && !location.pathname.includes('login'),
      { timeout: 15000 },
    );
  }
}

// Console-error guard: resource-load 404s from optional fixture images are
// tolerated, while CSP violations must always fail the suite.
function attachConsoleGuard(page, errors) {
  page.on('console', (m) => {
    if (m.type() !== 'error') return;
    const t = m.text();
    if (/Failed to load resource/i.test(t)) return;
    errors.push(t);
  });
}

// FK-safe cleanup: articoli → fascicoli → annate → testate, all scoped to
// the fixture title. Tolerates missing tables (plugin never activated).
function emCleanup() {
  const t = sqlEscape(TITLE);
  try {
    const files = dbQuery(
      `SELECT COALESCE(tt.logo_url,''), COALESCE(f.copertina_url,''), COALESCE(f.pdf_path,'')
       FROM emeroteca_testate tt
       LEFT JOIN emeroteca_annate a ON a.testata_id=tt.id
       LEFT JOIN emeroteca_fascicoli f ON f.annata_id=a.id
       WHERE tt.titolo='${t}'`,
    ).split('\n').filter(Boolean);
    for (const row of files) {
      const [logo, cover, pdf] = row.split('\t');
      for (const url of [logo, cover]) {
        if (url && url.startsWith('/uploads/emeroteca/') && path.basename(url) === url.slice('/uploads/emeroteca/'.length)) {
          fs.rmSync(path.join(__dirname, '..', 'public', url), { force: true });
        }
      }
      if (pdf && path.basename(pdf) === pdf && pdf.toLowerCase().endsWith('.pdf')) {
        fs.rmSync(path.join(__dirname, '..', 'storage', 'uploads', 'emeroteca', pdf), { force: true });
      }
    }
    dbQuery(
      `DELETE ar FROM emeroteca_articoli ar JOIN emeroteca_fascicoli f ON ar.fascicolo_id=f.id JOIN emeroteca_annate a ON f.annata_id=a.id JOIN emeroteca_testate tt ON a.testata_id=tt.id WHERE tt.titolo='${t}';`
      + `DELETE f FROM emeroteca_fascicoli f JOIN emeroteca_annate a ON f.annata_id=a.id JOIN emeroteca_testate tt ON a.testata_id=tt.id WHERE tt.titolo='${t}';`
      + `DELETE a FROM emeroteca_annate a JOIN emeroteca_testate tt ON a.testata_id=tt.id WHERE tt.titolo='${t}';`
      + `DELETE FROM emeroteca_testate WHERE titolo='${t}';`,
    );
  } catch (e) {
    // Tables absent → nothing to clean.
  }
}

test.describe.serial('Emeroteca plugin (E2E)', () => {
  test.skip(EM_SKIP, 'E2E credentials not configured');
  test.beforeAll(() => emCleanup());
  test.afterAll(() => emCleanup());

  test('activate the emeroteca plugin from the admin UI (if inactive)', async ({ page }) => {
    test.setTimeout(120000);
    await loginAsAdmin(page);

    // emeroteca IS in App\Support\BundledPlugins::LIST, so visiting
    // /admin/plugins auto-registers it (same flow as full-test 5.2b for
    // book-club). Then activate through the real UI when inactive.
    await page.goto(`${BASE}/admin/plugins`);
    await page.waitForLoadState('domcontentloaded');
    const emId = Number(String(dbQuery(
      `SELECT id FROM plugins WHERE name='emeroteca' LIMIT 1`,
    )).trim() || '0');
    expect(emId, 'emeroteca must be auto-registered as a bundled plugin').toBeGreaterThan(0);

    await page.waitForSelector('[data-plugin-id]', { timeout: 10000 }).catch(() => {});
    const card = page.locator(`[data-plugin-id="${emId}"]`).first();
    expect(await card.isVisible({ timeout: 3000 }).catch(() => false)).toBeTruthy();

    // Async activation POST + racy swal timing: retry the whole
    // click→confirm→settle up to 3 times and gate on the DB truth
    // (is_active=1), exactly like full-test 5.2b.
    const isActive = () =>
      String(dbQuery(`SELECT is_active FROM plugins WHERE id=${emId}`)).trim() === '1';
    for (let attempt = 0; attempt < 3 && !isActive(); attempt++) {
      const activateBtn = card.locator('button:has-text("Attiva")');
      if (!await activateBtn.isVisible({ timeout: 1500 }).catch(() => false)) {
        await page.goto(`${BASE}/admin/plugins`);
        await page.waitForLoadState('domcontentloaded');
        continue;
      }
      await activateBtn.click();
      const confirmBtn = page.locator('.swal2-confirm:visible');
      if (await confirmBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
        await confirmBtn.click();
      }
      const sawError = await page.locator('.swal2-popup .swal2-icon.swal2-error')
        .isVisible({ timeout: 4000 }).catch(() => false);
      expect(sawError, 'emeroteca activation must not raise a schema error').toBeFalsy();
      await page.waitForFunction(
        () => document.querySelector('.swal2-popup .swal2-icon.swal2-success') !== null,
        { timeout: 10000 },
      ).catch(() => {});
      await page.keyboard.press('Enter').catch(() => {});
      await page.waitForLoadState('domcontentloaded');
      await page.waitForTimeout(500);
    }
    expect(String(dbQuery(`SELECT is_active FROM plugins WHERE id=${emId}`)).trim()).toBe('1');

    // Every plugin table exists after activation.
    for (const t of ['emeroteca_testate', 'emeroteca_annate', 'emeroteca_fascicoli', 'emeroteca_articoli']) {
      const exists = String(dbQuery(
        `SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='${t}'`,
      )).trim();
      expect(exists, `${t} must exist after emeroteca activation`).toBe('1');
    }
  });

  test('create a testata from the real admin form', async ({ page }) => {
    test.setTimeout(90000);
    await loginAsAdmin(page);

    await page.goto(`${BASE}/admin/periodicals/create`);
    await expect(page.locator('#titolo')).toBeVisible({ timeout: 10000 });
    await page.fill('#titolo', TITLE);
    await page.selectOption('#tipo', 'rivista');
    // 'mensile' so the Kardex quick-action is enabled on the issues page.
    await page.selectOption('#periodicita', 'mensile');
    await page.selectOption('#stato_raccolta', 'attiva');
    await page.locator('form button[type="submit"]:has-text("Crea testata")').click();

    // createSubmit redirects (303) to /admin/periodicals/{id}/issues.
    await page.waitForURL(/\/admin\/periodicals\/\d+\/issues/, { timeout: 15000 });

    testataId = String(dbQuery(
      `SELECT id FROM emeroteca_testate WHERE titolo='${sqlEscape(TITLE)}' LIMIT 1`,
    )).trim();
    expect(Number(testataId)).toBeGreaterThan(0);
    const periodicita = String(dbQuery(
      `SELECT periodicita FROM emeroteca_testate WHERE id=${Number(testataId)}`,
    )).trim();
    expect(periodicita).toBe('mensile');
  });

  test('add an annata and two fascicoli from the issues page', async ({ page }) => {
    test.setTimeout(90000);
    await loginAsAdmin(page);

    await page.goto(`${BASE}/admin/periodicals/${testataId}/issues`);
    await expect(page.locator('#ann-anno')).toBeVisible({ timeout: 10000 });

    // Add the annata through the real quick-action form.
    await page.fill('#ann-anno', String(ANNO));
    await Promise.all([
      page.waitForEvent('load', { timeout: 15000 }),
      page.locator('form:has(input[name="action"][value="add_annata"]) button[type="submit"]').click(),
    ]);

    annataId = String(dbQuery(
      `SELECT id FROM emeroteca_annate WHERE testata_id=${Number(testataId)} AND anno=${ANNO} LIMIT 1`,
    )).trim();
    expect(Number(annataId)).toBeGreaterThan(0);

    // Add two fascicoli (n. 1 and n. 2) through the per-annata form.
    for (const numero of ['1', '2']) {
      const numInput = page.locator(`#fsc-num-${annataId}`);
      await expect(numInput).toBeVisible({ timeout: 10000 });
      await numInput.fill(numero);
      await Promise.all([
        page.waitForEvent('load', { timeout: 15000 }),
        page.locator(`form:has(#fsc-num-${annataId}) button[type="submit"]`).click(),
      ]);
    }

    const rows = dbQuery(
      `SELECT id, numero FROM emeroteca_fascicoli WHERE annata_id=${Number(annataId)} ORDER BY CAST(numero AS UNSIGNED)`,
    ).split('\n').filter(Boolean);
    expect(rows.length).toBe(2);
    fascicoloIds = rows.map((r) => r.split('\t')[0]);
    expect(rows.map((r) => r.split('\t')[1])).toEqual(['1', '2']);

    // The manage page shows both issues in the annata card.
    await page.goto(`${BASE}/admin/periodicals/${testataId}/issues`);
    await expect(page.locator('details', { hasText: String(ANNO) }).first()).toContainText('2 fascicoli');
  });

  test('public frontend: index, testata grid and fascicolo page', async ({ page }) => {
    test.setTimeout(90000);
    const errors = [];
    attachConsoleGuard(page, errors);

    // /emeroteca index lists the testata.
    await page.goto(`${BASE}/emeroteca`);
    await expect(page.locator('#emeroteca-index')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('#emeroteca-index')).toContainText(TITLE);

    // Testata page shows the covers grid with the 2 fascicoli of 2024.
    // Each owned issue renders TWO links (cover box + caption), so count
    // the issue cards (one caption per fascicolo), then check that both
    // fascicolo detail pages are linked.
    await page.goto(`${BASE}/emeroteca/${testataId}`);
    await expect(page.locator('h1', { hasText: TITLE })).toBeVisible({ timeout: 10000 });
    await expect(page.locator('.emeroteca-issue-caption')).toHaveCount(2);
    for (const fid of fascicoloIds) {
      await expect(
        page.locator(`a[href$="/emeroteca/fascicolo/${fid}"]`).first(),
      ).toBeVisible();
    }

    // Fascicolo page responds 200 with the right issue number.
    const resp = await page.goto(`${BASE}/emeroteca/fascicolo/${fascicoloIds[0]}`);
    expect(resp.status()).toBe(200);
    await expect(page.locator('h1')).toContainText('n. 1');
    await expect(page.locator('h1')).toContainText(TITLE);

    // Unknown fascicolo → 404 rendered inside the public layout.
    const missing = await page.goto(`${BASE}/emeroteca/fascicolo/99999999`);
    expect(missing.status()).toBe(404);

    expect(errors).toEqual([]);
  });

  test('validation: empty title and malformed ISSN are rejected', async ({ page }) => {
    test.setTimeout(60000);
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/periodicals/create`);
    await expect(page.locator('#titolo')).toBeVisible({ timeout: 10000 });
    // Empty title: HTML5 required blocks submit client-side; strip it to
    // exercise the SERVER validation, which is the real contract.
    await page.evaluate(() => document.getElementById('titolo').removeAttribute('required'));
    await page.fill('#issn', 'not-an-issn');
    await Promise.all([
      page.waitForEvent('load', { timeout: 15000 }),
      page.locator('form button[type="submit"]:has-text("Crea testata")').click(),
    ]);
    const created = dbQuery(`SELECT COUNT(*) FROM emeroteca_testate WHERE issn='not-an-issn'`);
    expect(Number(created)).toBe(0);
    await expect(page).not.toHaveURL(/\/issues/);
  });

  test('edit testata: subtitle and a valid ISSN persist', async ({ page }) => {
    test.setTimeout(60000);
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/periodicals/edit/${testataId}`);
    await expect(page.locator('#titolo')).toHaveValue(TITLE, { timeout: 10000 });
    await page.fill('#sottotitolo', 'Mensile di prova E2E');
    await page.fill('#issn', '1125-3460');
    await Promise.all([
      page.waitForEvent('load', { timeout: 15000 }),
      page.locator('form button[type="submit"]').first().click(),
    ]);
    const row = dbQuery(`SELECT sottotitolo, issn FROM emeroteca_testate WHERE id=${Number(testataId)}`);
    expect(row).toContain('Mensile di prova E2E');
    expect(row).toContain('1125-3460');
  });

  test('logo upload uses Uppy and persists a served image', async ({ page }) => {
    test.setTimeout(90000);
    await loginAsAdmin(page);
    const png = Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
      'base64',
    );
    const tmpPng = path.join(os.tmpdir(), `emeroteca-logo-${Date.now()}.png`);
    fs.writeFileSync(tmpPng, png);
    try {
      await page.goto(`${BASE}/admin/periodicals/edit/${testataId}`);
      const uppyInput = page.locator('#emt-logo-upload input[type="file"]');
      await expect(uppyInput).toBeAttached({ timeout: 10000 });
      await uppyInput.setInputFiles(tmpPng);
      await expect(page.locator('#emt-logo-preview-image')).toBeVisible({ timeout: 10000 });
      await page.locator('form button[type="submit"]').first().click();
      await page.waitForURL(/\/admin\/periodicals$/, { timeout: 15000 });
    } finally {
      fs.rmSync(tmpPng, { force: true });
    }
    const logoUrl = dbQuery(`SELECT logo_url FROM emeroteca_testate WHERE id=${Number(testataId)}`).trim();
    expect(logoUrl).toMatch(/^\/uploads\/emeroteca\/testata_/);
    expect((await page.request.get(`${BASE}${logoUrl}`)).status()).toBe(200);
  });

  test('bulk series creation adds a run of issues in one submit', async ({ page }) => {
    test.setTimeout(60000);
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/periodicals/${testataId}/issues`);
    await expect(page.locator('#blk-anno')).toBeVisible({ timeout: 10000 });
    await page.fill('#blk-anno', String(ANNO + 1));
    await page.fill('#blk-da', '1');
    await page.fill('#blk-a', '6');
    await Promise.all([
      page.waitForEvent('load', { timeout: 15000 }),
      page.locator('form:has(#blk-anno) button[type="submit"]').click(),
    ]);
    const count = dbQuery(
      `SELECT COUNT(*) FROM emeroteca_fascicoli f JOIN emeroteca_annate a ON a.id=f.annata_id
       WHERE a.testata_id=${Number(testataId)} AND a.anno=${ANNO + 1}`,
    );
    expect(Number(count)).toBe(6);
  });

  test('kardex: generate expected issues from the frequency', async ({ page }) => {
    test.setTimeout(60000);
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/periodicals/${testataId}/issues`);
    await expect(page.locator('#krd-anno')).toBeVisible({ timeout: 10000 });
    await page.fill('#krd-anno', String(ANNO + 2));
    await Promise.all([
      page.waitForEvent('load', { timeout: 15000 }),
      page.locator('form:has(#krd-anno) button[type="submit"]').click(),
    ]);
    // Monthly testata → 12 expected issues for the empty year.
    const attesi = dbQuery(
      `SELECT COUNT(*) FROM emeroteca_fascicoli f JOIN emeroteca_annate a ON a.id=f.annata_id
       WHERE a.testata_id=${Number(testataId)} AND a.anno=${ANNO + 2} AND f.stato='atteso'`,
    );
    expect(Number(attesi)).toBe(12);
  });

  test('kardex: receiving an expected issue flips it to owned', async ({ page }) => {
    test.setTimeout(60000);
    await loginAsAdmin(page);
    const firstAtteso = dbQuery(
      `SELECT f.id FROM emeroteca_fascicoli f JOIN emeroteca_annate a ON a.id=f.annata_id
       WHERE a.testata_id=${Number(testataId)} AND a.anno=${ANNO + 2} AND f.stato='atteso'
       ORDER BY CAST(f.numero AS UNSIGNED) LIMIT 1`,
    ).trim();
    await page.goto(`${BASE}/admin/periodicals/${testataId}/issues`);
    const receiveForm = page.locator(`form:has(input[name="action"][value="receive_issue"]):has(input[name="fascicolo_id"][value="${firstAtteso}"])`);
    await expect(receiveForm.locator('button[type="submit"]')).toBeVisible({ timeout: 10000 });
    await Promise.all([
      page.waitForEvent('load', { timeout: 15000 }),
      receiveForm.locator('button[type="submit"]').click(),
    ]);
    const stato = dbQuery(`SELECT stato FROM emeroteca_fascicoli WHERE id=${Number(firstAtteso)}`).trim();
    expect(stato).toBe('posseduto');
  });

  test('kardex: mark-missing converts the remaining expected issues', async ({ page }) => {
    test.setTimeout(60000);
    await loginAsAdmin(page);
    const annataK = dbQuery(
      `SELECT id FROM emeroteca_annate WHERE testata_id=${Number(testataId)} AND anno=${ANNO + 2} LIMIT 1`,
    ).trim();
    await page.goto(`${BASE}/admin/periodicals/${testataId}/issues`);
    const markForm = page.locator(`form:has(input[name="action"][value="mark_missing"]):has(input[name="annata_id"][value="${annataK}"])`);
    await expect(markForm.locator('button[type="submit"]')).toBeVisible({ timeout: 10000 });
    page.once('dialog', (d) => d.accept().catch(() => {}));
    await Promise.all([
      page.waitForEvent('load', { timeout: 15000 }),
      markForm.locator('button[type="submit"]').click(),
    ]);
    const rows = dbQuery(
      `SELECT SUM(stato='mancante'), SUM(stato='atteso'), SUM(stato='posseduto') FROM emeroteca_fascicoli WHERE annata_id=${Number(annataK)}`,
    ).trim().split('\t');
    expect(Number(rows[0])).toBe(11);
    expect(Number(rows[1])).toBe(0);
    expect(Number(rows[2])).toBe(1);
  });

  test('issue detail form persists title, pages and a damaged state', async ({ page }) => {
    test.setTimeout(60000);
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/periodicals/issue/${fascicoloIds[1]}`);
    await expect(page.locator('input[name="titolo_fascicolo"]')).toBeVisible({ timeout: 10000 });
    await page.fill('input[name="titolo_fascicolo"]', 'Numero monografico E2E');
    await page.fill('input[name="pagine"]', '96');
    await page.selectOption('select[name="stato"]', 'danneggiato');
    await Promise.all([
      page.waitForEvent('load', { timeout: 15000 }),
      page.locator('form button[type="submit"]').first().click(),
    ]);
    const row = dbQuery(`SELECT titolo_fascicolo, pagine, stato FROM emeroteca_fascicoli WHERE id=${Number(fascicoloIds[1])}`);
    expect(row).toContain('Numero monografico E2E');
    expect(row).toContain('96');
    expect(row).toContain('danneggiato');
  });

  test('cover upload through Uppy stores a served image', async ({ page }) => {
    test.setTimeout(90000);
    await loginAsAdmin(page);
    // Minimal valid 1x1 PNG written on the fly — a REAL multipart upload.
    const png = Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
      'base64',
    );
    const tmpPng = path.join(os.tmpdir(), `emeroteca-cover-${Date.now()}.png`);
    fs.writeFileSync(tmpPng, png);
    await page.goto(`${BASE}/admin/periodicals/issue/${fascicoloIds[0]}`);
    const uppyInput = page.locator('#emt-cover-upload input[type="file"]');
    await expect(uppyInput).toBeAttached({ timeout: 10000 });
    await uppyInput.setInputFiles(tmpPng);
    await expect(page.locator('#emt-cover-preview-image')).toBeVisible({ timeout: 10000 });
    await Promise.all([
      page.waitForEvent('load', { timeout: 15000 }),
      page.locator('form button[type="submit"]').first().click(),
    ]);
    fs.unlinkSync(tmpPng);
    const url = dbQuery(`SELECT copertina_url FROM emeroteca_fascicoli WHERE id=${Number(fascicoloIds[0])}`).trim();
    expect(url.length).toBeGreaterThan(0);
    const resp = await page.request.get(`${BASE}${url.startsWith('/') ? '' : '/'}${url}`);
    expect(resp.status()).toBe(200);
  });

  test('PDF upload uses Uppy, stays private by default and can be published', async ({ page }) => {
    test.setTimeout(90000);
    await loginAsAdmin(page);
    const pdf = Buffer.from(
      '%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n'
      + '2 0 obj\n<< /Type /Pages /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n',
      'utf8',
    );
    const tmpPdf = path.join(os.tmpdir(), `emeroteca-issue-${Date.now()}.pdf`);
    fs.writeFileSync(tmpPdf, pdf);
    try {
      await page.goto(`${BASE}/admin/periodicals/issue/${fascicoloIds[0]}`);
      const uppyInput = page.locator('#emt-pdf-upload input[type="file"]');
      await expect(uppyInput).toBeAttached({ timeout: 10000 });
      await uppyInput.setInputFiles(tmpPdf);
      await expect(page.locator('#emt-pdf-result')).toContainText(path.basename(tmpPdf));
      await Promise.all([
        page.waitForEvent('load', { timeout: 15000 }),
        page.locator('form button[type="submit"]').first().click(),
      ]);
    } finally {
      fs.rmSync(tmpPdf, { force: true });
    }

    const stored = dbQuery(
      `SELECT pdf_path, pdf_nome_originale, pdf_pubblico FROM emeroteca_fascicoli WHERE id=${Number(fascicoloIds[0])}`,
    ).trim().split('\t');
    expect(stored[0]).toMatch(/^fascicolo_.*\.pdf$/);
    expect(stored[1]).toMatch(/^emeroteca-issue-.*\.pdf$/);
    expect(stored[2]).toBe('0');
    const adminPdf = await page.request.get(`${BASE}/admin/periodicals/issue/${fascicoloIds[0]}/pdf`);
    expect(adminPdf.status()).toBe(200);
    expect(adminPdf.headers()['content-type']).toContain('application/pdf');
    expect((await page.request.get(`${BASE}/emeroteca/fascicolo/${fascicoloIds[0]}/pdf`)).status()).toBe(404);

    await page.goto(`${BASE}/admin/periodicals/issue/${fascicoloIds[0]}`);
    await page.check('input[name="pdf_pubblico"]');
    await Promise.all([
      page.waitForEvent('load', { timeout: 15000 }),
      page.locator('form button[type="submit"]').first().click(),
    ]);
    expect(dbQuery(`SELECT pdf_pubblico FROM emeroteca_fascicoli WHERE id=${Number(fascicoloIds[0])}`).trim()).toBe('1');
    expect((await page.request.get(`${BASE}/emeroteca/fascicolo/${fascicoloIds[0]}/pdf`)).status()).toBe(200);
    await page.goto(`${BASE}/emeroteca/fascicolo/${fascicoloIds[0]}`);
    await expect(page.locator('a', { hasText: 'Consulta PDF' })).toBeVisible();

    // Removal clears both metadata and the private file after the DB commit.
    await page.goto(`${BASE}/admin/periodicals/issue/${fascicoloIds[0]}`);
    await page.check('input[name="rimuovi_pdf"]');
    await Promise.all([
      page.waitForEvent('load', { timeout: 15000 }),
      page.locator('form button[type="submit"]').first().click(),
    ]);
    expect(dbQuery(
      `SELECT COALESCE(pdf_path,'') FROM emeroteca_fascicoli WHERE id=${Number(fascicoloIds[0])}`,
    ).trim()).toBe('');
    expect(fs.existsSync(path.join(__dirname, '..', 'storage', 'uploads', 'emeroteca', stored[0]))).toBeFalsy();
    expect((await page.request.get(`${BASE}/admin/periodicals/issue/${fascicoloIds[0]}/pdf`)).status()).toBe(404);
  });

  test('table of contents: two articles saved from the issue form', async ({ page }) => {
    test.setTimeout(60000);
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/periodicals/issue/${fascicoloIds[0]}`);
    // Article rows are stamped from a <template> by the add button: the DOM
    // starts with zero rows, one click = one row.
    const addBtn = page.locator('#emt-art-add');
    await expect(addBtn).toBeVisible({ timeout: 10000 });
    await addBtn.click();
    await addBtn.click();
    const titoli = page.locator('input[name="art_titolo[]"]');
    await expect(titoli).toHaveCount(2);
    await titoli.nth(0).fill('Editoriale di prova');
    await page.locator('input[name="art_autori[]"]').nth(0).fill('Anna Verdi');
    await page.locator('input[name="art_pag_da[]"]').nth(0).fill('3');
    await titoli.nth(1).fill('Intervista sulla catalogazione');
    await page.locator('input[name="art_autori[]"]').nth(1).fill('Luca Neri');
    await page.locator('input[name="art_pag_da[]"]').nth(1).fill('12');
    await Promise.all([
      page.waitForEvent('load', { timeout: 15000 }),
      page.locator('form button[type="submit"]').first().click(),
    ]);
    const count = dbQuery(`SELECT COUNT(*) FROM emeroteca_articoli WHERE fascicolo_id=${Number(fascicoloIds[0])}`);
    expect(Number(count)).toBe(2);
  });

  test('admin list shows the computed holdings statement', async ({ page }) => {
    test.setTimeout(60000);
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/periodicals`);
    const row = page.locator('tr', { hasText: TITLE }).first();
    await expect(row).toBeVisible({ timeout: 10000 });
    // Years 2024–2026 exist; 2026 carries 11 lacune from the kardex test.
    await expect(row).toContainText(String(ANNO));
    await expect(row).toContainText(String(ANNO + 2));
  });

  test('public index: search finds the testata, alternative views respond', async ({ page }) => {
    test.setTimeout(60000);
    const errors = [];
    attachConsoleGuard(page, errors);
    await page.goto(`${BASE}/emeroteca`);
    const optionValues = await page.locator('#eme-tipo option').evaluateAll((options) =>
      options.map((option) => option.value).filter(Boolean),
    );
    const populatedTypes = dbQuery(
      'SELECT tipo FROM emeroteca_testate GROUP BY tipo HAVING COUNT(*) > 0 ORDER BY tipo',
    ).split('\n').filter(Boolean);
    expect([...optionValues].sort()).toEqual(populatedTypes.sort());
    await page.selectOption('#eme-tipo', 'rivista');
    await Promise.all([
      page.waitForURL(/(?:\?|&)tipo=rivista(?:&|$)/),
      page.locator('.emeroteca-search button[type="submit"]').click(),
    ]);
    await expect(page.locator('#emeroteca-index')).toContainText(TITLE, { timeout: 10000 });
    await page.goto(`${BASE}/emeroteca?q=${encodeURIComponent(TITLE)}`);
    await expect(page.locator('#emeroteca-index')).toContainText(TITLE, { timeout: 10000 });
    await page.goto(`${BASE}/emeroteca?q=${encodeURIComponent('Anna Verdi')}`);
    await expect(page.locator('#emeroteca-index')).toContainText(TITLE, { timeout: 10000 });
    const css = await page.request.get(`${BASE}/plugins/emeroteca/assets/css/emeroteca.css`);
    expect(css.status()).toBe(200);
    expect(css.headers()['content-type']).toContain('text/css');
    await page.goto(`${BASE}/emeroteca?q=zz-nessuna-testata-cosi`);
    await expect(page.locator('#emeroteca-index')).not.toContainText(TITLE);
    for (const vista of ['editore', 'argomento']) {
      const resp = await page.goto(`${BASE}/emeroteca?vista=${vista}`);
      expect(resp.status()).toBe(200);
      await expect(page.locator('#emeroteca-index')).toContainText(TITLE);
    }
    expect(errors).toEqual([]);
  });

  test('public testata page: missing issues render as placeholders', async ({ page }) => {
    test.setTimeout(60000);
    await page.goto(`${BASE}/emeroteca/${testataId}?anno=${ANNO + 2}`);
    await expect(page.locator('h1', { hasText: TITLE })).toBeVisible({ timeout: 10000 });
    const bodyText = await page.locator('body').innerText();
    expect(bodyText).toContain('Mancante');
  });

  test('public issue page shows the table of contents', async ({ page }) => {
    test.setTimeout(60000);
    await page.goto(`${BASE}/emeroteca/fascicolo/${fascicoloIds[0]}`);
    await expect(page.locator('body')).toContainText('Editoriale di prova', { timeout: 10000 });
    await expect(page.locator('body')).toContainText('Intervista sulla catalogazione');
    await expect(page.locator('body')).toContainText('Anna Verdi');
  });

  test('public issue page navigates to the next issue in the annata', async ({ page }) => {
    test.setTimeout(60000);
    await page.goto(`${BASE}/emeroteca/fascicolo/${fascicoloIds[0]}`);
    const next = page.locator(`a[href$="/emeroteca/fascicolo/${fascicoloIds[1]}"]`).first();
    await expect(next).toBeVisible({ timeout: 10000 });
    await next.click();
    await expect(page.locator('h1')).toContainText('n. 2', { timeout: 10000 });
  });

  test('schema.org: Periodical on the testata, PublicationIssue on the issue', async ({ page }) => {
    test.setTimeout(60000);
    await page.goto(`${BASE}/emeroteca/${testataId}`);
    const periodical = await page.locator('script[type="application/ld+json"]').allTextContents();
    expect(periodical.join(' ')).toContain('Periodical');
    await page.goto(`${BASE}/emeroteca/fascicolo/${fascicoloIds[0]}`);
    const issueLd = await page.locator('script[type="application/ld+json"]').allTextContents();
    expect(issueLd.join(' ')).toContain('PublicationIssue');
  });

  test('access control: public emeroteca is anonymous, admin is not', async ({ browser }) => {
    test.setTimeout(60000);
    const context = await browser.newContext();
    const anon = await context.newPage();
    try {
      const pub = await anon.goto(`${BASE}/emeroteca`);
      expect(pub.status()).toBe(200);
      await anon.goto(`${BASE}/admin/periodicals`);
      await anon.waitForLoadState('domcontentloaded');
      // Anonymous hit on the admin area must land on the login form, never
      // on the periodicals management page.
      await expect(anon.locator('input[name="password"]')).toBeVisible({ timeout: 10000 });
    } finally {
      await context.close();
    }
  });

});
