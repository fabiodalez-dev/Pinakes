// @ts-check
/**
 * E2E coverage for series groups, spin-offs and cycles/seasons.
 *
 * Run with:
 *   npx playwright test tests/series-cycles.spec.js --config=tests/playwright.config.js
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS ?? '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

const ROOT = path.resolve(__dirname, '..');
const SERIES_MIGRATION = path.join(ROOT, 'installer/database/migrations/migrate_0.5.9.5.sql');

const TAG = 'E2E_SERIES_CYCLES_' + Date.now();
const GROUP_FAIRY = `${TAG} Fairy Tail Universe`;
const GROUP_WORLDS = `${TAG} Worlds of Aldebaran`;
const MAIN_SERIES = `${TAG} Fairy Tail`;
const SPINOFF_SERIES = `${TAG} Fairy Tail 100 Year Quest`;
const HAPPY_SERIES = `${TAG} Fairy Tail Happy`;
const CYCLE_ONE_SERIES = `${TAG} Aldebaran`;
const CYCLE_TWO_SERIES = `${TAG} Betelgeuse`;
const HAS_E2E_ENV = Boolean(ADMIN_EMAIL && ADMIN_PASS && DB_USER && DB_NAME && (DB_HOST || DB_SOCKET));

test.skip(
  !HAS_E2E_ENV,
  'Missing E2E env vars for series/cycles tests'
);

function mysqlArgs(sql = '', batch = false) {
  const args = [];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER);
  if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
  args.push(DB_NAME);
  if (batch) args.push('-N', '-B');
  if (sql !== '') args.push('-e', sql);
  return args;
}

function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql, true), { encoding: 'utf-8', timeout: 10000 }).trim();
}

function dbExec(sql, timeout = 10000) {
  execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout });
}

function dbPipe(sql, timeout = 60000) {
  execFileSync('mysql', mysqlArgs(), { input: sql, encoding: 'utf-8', timeout });
}

function escapeSql(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

function cleanupRows() {
  dbExec(`
    DELETE FROM libri_collane
     WHERE libro_id IN (
       SELECT id FROM libri
        WHERE titolo LIKE '${escapeSql(TAG)}%'
           OR collana LIKE '${escapeSql(TAG)}%'
     )
        OR collana_id IN (
       SELECT id FROM collane
        WHERE nome LIKE '${escapeSql(TAG)}%'
           OR gruppo_serie LIKE '${escapeSql(TAG)}%'
    )
  `);
  dbExec(`
    DELETE FROM copie
     WHERE libro_id IN (
       SELECT id FROM libri
        WHERE titolo LIKE '${escapeSql(TAG)}%'
           OR collana LIKE '${escapeSql(TAG)}%'
     )
  `);
  dbExec(`
    DELETE FROM libri
     WHERE titolo LIKE '${escapeSql(TAG)}%'
        OR collana LIKE '${escapeSql(TAG)}%'
  `);
  dbExec(`
    DELETE FROM collane
     WHERE nome LIKE '${escapeSql(TAG)}%'
        OR gruppo_serie LIKE '${escapeSql(TAG)}%'
  `);
}

function applySeriesMigration() {
  dbPipe(fs.readFileSync(SERIES_MIGRATION, 'utf8'), 90000);
}

function detectBaseSchema() {
  if (!HAS_E2E_ENV) {
    return false;
  }
  try {
    const count = dbQuery(`
      SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME IN ('libri', 'copie', 'collane')
    `);
    return count === '3';
  } catch {
    return false;
  }
}

test.skip(
  HAS_E2E_ENV && !detectBaseSchema(),
  'E2E database is reachable but Pinakes base tables are missing'
);

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const emailField = page.locator('input[name="email"]');
  if (await emailField.isVisible({ timeout: 3000 }).catch(() => false)) {
    await emailField.fill(ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
      page.waitForURL(/\/admin\//, { timeout: 15000 }),
      page.click('button[type="submit"]'),
    ]);
  }
}

async function submitBookForm(page, expectedId = null) {
  await page.click('button[type="submit"]');
  await page.locator('.swal2-confirm').click();
  if (expectedId) {
    await page.waitForURL(new RegExp(`/admin/libri/${expectedId}$`), { timeout: 15000 });
    return expectedId;
  }
  await page.waitForURL(/\/admin\/libri\/\d+$/, { timeout: 15000 });
  const match = page.url().match(/\/admin\/libri\/(\d+)$/);
  expect(match).not.toBeNull();
  return Number(match?.[1]);
}

async function createBookWithSeries(page, data) {
  await page.goto(`${BASE}/admin/libri/crea`);
  await page.waitForLoadState('domcontentloaded');
  await page.fill('#titolo', data.title);
  await page.fill('#gruppo_serie', data.group || '');
  await page.fill('#serie_padre', data.parent || '');
  await page.selectOption('#tipo_collana', data.type || 'serie');
  await page.fill('#collana', data.series || '');
  await page.fill('#numero_serie', data.number || '');
  await page.fill('#ciclo_serie', data.cycle || '');
  await page.fill('#ordine_ciclo', data.cycleOrder ? String(data.cycleOrder) : '');
  await page.fill('#altre_collane', data.otherSeries || '');
  return submitBookForm(page);
}

test.describe.serial('Series groups and cycles', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  const ids = new Map();

  test.beforeAll(async ({ browser }) => {
    applySeriesMigration();
    cleanupRows();
    context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);
  });

  test.afterAll(async () => {
    try {
      cleanupRows();
    } catch { /* best-effort cleanup */ }
    await context?.close();
  });

  test('01. series hierarchy columns are available', async () => {
    const columns = dbQuery(`
      SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME)
        FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'collane'
         AND COLUMN_NAME IN ('gruppo_serie', 'ciclo', 'ordine_ciclo', 'parent_id', 'tipo')
    `);
    expect(columns).toBe('ciclo,gruppo_serie,ordine_ciclo,parent_id,tipo');
    expect(dbQuery(`
      SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'libri_collane'
    `)).toBe('1');
  });

  test('02. create an archive/catalog record in a main series group', async () => {
    const id = await createBookWithSeries(page, {
      title: `${TAG} Main volume 1`,
      group: GROUP_FAIRY,
      parent: GROUP_FAIRY,
      type: 'serie',
      series: MAIN_SERIES,
      number: '1',
      otherSeries: SPINOFF_SERIES,
    });
    ids.set('main1', id);

    const row = dbQuery(`
      SELECT CONCAT_WS('|', l.collana, l.numero_serie, c.gruppo_serie, c.tipo, p.nome)
        FROM libri l
        JOIN collane c ON c.nome = l.collana
        LEFT JOIN collane p ON p.id = c.parent_id
       WHERE l.id = ${id}
    `);
    expect(row).toBe(`${MAIN_SERIES}|1|${GROUP_FAIRY}|serie|${GROUP_FAIRY}`);
    expect(dbQuery(`
      SELECT GROUP_CONCAT(CONCAT(c.nome, ':', lc.tipo_appartenenza, ':', lc.is_principale) ORDER BY lc.is_principale DESC, c.nome SEPARATOR '|')
        FROM libri_collane lc
        JOIN collane c ON c.id = lc.collana_id
       WHERE lc.libro_id = ${id}
    `)).toBe(`${MAIN_SERIES}:principale:1|${SPINOFF_SERIES}:secondaria:0`);
  });

  test('03. main series detail shows saved group metadata', async () => {
    await page.goto(`${BASE}/admin/collane/dettaglio?nome=${encodeURIComponent(MAIN_SERIES)}`);
    await expect(page.locator('#gruppo_serie')).toHaveValue(GROUP_FAIRY);
    await expect(page.locator('body')).toContainText(`${TAG} Main volume 1`);
  });

  test('04. create a spin-off series under the same group', async () => {
    const id = await createBookWithSeries(page, {
      title: `${TAG} Spinoff volume 1`,
      group: GROUP_FAIRY,
      parent: GROUP_FAIRY,
      type: 'spin_off',
      series: SPINOFF_SERIES,
      number: '1',
    });
    ids.set('spinoff1', id);

    const group = dbQuery(`
      SELECT CONCAT_WS('|', c.gruppo_serie, c.tipo, p.nome)
        FROM collane c
        LEFT JOIN collane p ON p.id = c.parent_id
       WHERE c.nome = '${escapeSql(SPINOFF_SERIES)}'
    `);
    expect(group).toBe(`${GROUP_FAIRY}|spin_off|${GROUP_FAIRY}`);
  });

  test('05. collane list aggregates multiple separate series by group', async () => {
    await page.goto(`${BASE}/admin/collane`);
    await expect(page.locator('table')).toContainText(GROUP_FAIRY);
    await expect(page.locator('table')).toContainText(MAIN_SERIES);
    await expect(page.locator('table')).toContainText(SPINOFF_SERIES);
  });

  test('06. series detail links other spin-offs in the same group', async () => {
    await page.goto(`${BASE}/admin/collane/dettaglio?nome=${encodeURIComponent(MAIN_SERIES)}`);
    await expect(page.locator('body')).toContainText('Altre serie nello stesso gruppo');
    await expect(page.locator(`a[href*="${encodeURIComponent(SPINOFF_SERIES)}"]`)).toContainText(SPINOFF_SERIES);
  });

  test('07. save cycle metadata from the series admin page', async () => {
    await page.goto(`${BASE}/admin/collane/dettaglio?nome=${encodeURIComponent(MAIN_SERIES)}`);
    await page.fill('#ciclo', 'Original series');
    await page.fill('#ordine_ciclo', '1');
    await page.click('button:has-text("Salva descrizione")');
    await page.waitForLoadState('networkidle');

    const row = dbQuery(`SELECT CONCAT_WS('|', ciclo, ordine_ciclo) FROM collane WHERE nome = '${escapeSql(MAIN_SERIES)}'`);
    expect(row).toBe('Original series|1');
  });

  test('08. create a first numbered cycle for a LibraryThing-style universe', async () => {
    const id = await createBookWithSeries(page, {
      title: `${TAG} Aldebaran episode 1`,
      group: GROUP_WORLDS,
      parent: GROUP_WORLDS,
      type: 'ciclo',
      series: CYCLE_ONE_SERIES,
      cycle: 'Cycle 1 - Aldebaran',
      cycleOrder: 1,
      number: '1',
    });
    ids.set('cycle1book', id);

    const row = dbQuery(`SELECT CONCAT_WS('|', gruppo_serie, ciclo, ordine_ciclo) FROM collane WHERE nome = '${escapeSql(CYCLE_ONE_SERIES)}'`);
    expect(row).toBe(`${GROUP_WORLDS}|Cycle 1 - Aldebaran|1`);
  });

  test('09. create a second numbered cycle in the same universe', async () => {
    const id = await createBookWithSeries(page, {
      title: `${TAG} Betelgeuse episode 1`,
      group: GROUP_WORLDS,
      parent: GROUP_WORLDS,
      type: 'ciclo',
      series: CYCLE_TWO_SERIES,
      cycle: 'Cycle 2 - Betelgeuse',
      cycleOrder: 2,
      number: '1',
    });
    ids.set('cycle2book', id);

    const row = dbQuery(`SELECT CONCAT_WS('|', gruppo_serie, ciclo, ordine_ciclo) FROM collane WHERE nome = '${escapeSql(CYCLE_TWO_SERIES)}'`);
    expect(row).toBe(`${GROUP_WORLDS}|Cycle 2 - Betelgeuse|2`);
  });

  test('10. grouped cycle ordering is stable in the collane list', async () => {
    const names = dbQuery(`
      SELECT GROUP_CONCAT(nome ORDER BY ordine_ciclo, nome SEPARATOR '|')
        FROM collane
       WHERE gruppo_serie = '${escapeSql(GROUP_WORLDS)}'
    `);
    expect(names).toBe(`${CYCLE_ONE_SERIES}|${CYCLE_TWO_SERIES}`);

    await page.goto(`${BASE}/admin/collane`);
    const tableText = await page.locator('table').textContent();
    expect(tableText).toContain(CYCLE_ONE_SERIES);
    expect(tableText).toContain(CYCLE_TWO_SERIES);
    expect(tableText.indexOf(CYCLE_ONE_SERIES)).toBeLessThan(tableText.indexOf(CYCLE_TWO_SERIES));
  });

  test('11. editing a book updates its series cycle metadata', async () => {
    const id = ids.get('cycle2book');
    await page.goto(`${BASE}/admin/libri/modifica/${id}`);
    await page.fill('#ciclo_serie', 'Cycle 3 - Betelgeuse revised');
    await page.fill('#ordine_ciclo', '3');
    await expect(page.locator('#serie_padre')).toHaveValue(GROUP_WORLDS);
    await expect(page.locator('#tipo_collana')).toHaveValue('ciclo');
    await submitBookForm(page, id);

    const row = dbQuery(`SELECT CONCAT_WS('|', ciclo, ordine_ciclo) FROM collane WHERE nome = '${escapeSql(CYCLE_TWO_SERIES)}'`);
    expect(row).toBe('Cycle 3 - Betelgeuse revised|3');
  });

  test('12. bulk assign adds an existing record to a new spin-off series', async () => {
    dbExec(`
      INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at)
      VALUES ('${escapeSql(TAG)} Happy volume 1', 1, 1, NOW(), NOW())
    `);
    const bookId = Number(dbQuery(`SELECT id FROM libri WHERE titolo = '${escapeSql(TAG)} Happy volume 1' ORDER BY id DESC LIMIT 1`));
    ids.set('happy1', bookId);

    await page.goto(`${BASE}/admin/collane`);
    const csrf = await page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
    const resp = await page.evaluate(async ({ base, bookId, collana, csrf }) => {
      const r = await fetch(base + '/admin/collane/bulk-assign', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ book_ids: [bookId], collana })
      });
      return { status: r.status, body: await r.json() };
    }, { base: BASE, bookId, collana: HAPPY_SERIES, csrf });

    expect(resp.status).toBe(200);
    expect(resp.body.success).toBe(true);
    expect(dbQuery(`SELECT collana FROM libri WHERE id = ${bookId}`)).toBe(HAPPY_SERIES);
    expect(dbQuery(`
      SELECT CONCAT_WS('|', c.nome, lc.tipo_appartenenza, lc.is_principale)
        FROM libri_collane lc
        JOIN collane c ON c.id = lc.collana_id
       WHERE lc.libro_id = ${bookId}
    `)).toBe(`${HAPPY_SERIES}|principale|1`);
    expect(dbQuery(`SELECT COUNT(*) FROM collane WHERE nome = '${escapeSql(HAPPY_SERIES)}'`)).toBe('1');
  });

  test('13. series detail can add group metadata to the bulk-assigned series', async () => {
    await page.goto(`${BASE}/admin/collane/dettaglio?nome=${encodeURIComponent(HAPPY_SERIES)}`);
    await page.fill('#gruppo_serie', GROUP_FAIRY);
    await page.fill('#ciclo', 'Happy spin-off');
    await page.fill('#ordine_ciclo', '4');
    await page.click('button:has-text("Salva descrizione")');
    await page.waitForLoadState('networkidle');

    const row = dbQuery(`SELECT CONCAT_WS('|', gruppo_serie, ciclo, ordine_ciclo) FROM collane WHERE nome = '${escapeSql(HAPPY_SERIES)}'`);
    expect(row).toBe(`${GROUP_FAIRY}|Happy spin-off|4`);
  });

  test('14. update volume number inside a series', async () => {
    const bookId = ids.get('happy1');
    await page.goto(`${BASE}/admin/collane/dettaglio?nome=${encodeURIComponent(HAPPY_SERIES)}`);
    const csrf = await page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
    const resp = await page.evaluate(async ({ base, bookId, csrf }) => {
      const r = await fetch(base + '/admin/collane/ordine', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ book_id: bookId, numero_serie: '2' })
      });
      return { status: r.status, body: await r.json() };
    }, { base: BASE, bookId, csrf });

    expect(resp.status).toBe(200);
    expect(resp.body.success).toBe(true);
    expect(dbQuery(`SELECT numero_serie FROM libri WHERE id = ${bookId}`)).toBe('2');
    expect(dbQuery(`
      SELECT lc.numero_serie
        FROM libri_collane lc
        JOIN collane c ON c.id = lc.collana_id
       WHERE lc.libro_id = ${bookId}
         AND c.nome = '${escapeSql(HAPPY_SERIES)}'
    `)).toBe('2');
  });

  test('15. remove a book from one series, then delete a whole series', async () => {
    const mainBookId = ids.get('main1');
    await page.goto(`${BASE}/admin/collane/dettaglio?nome=${encodeURIComponent(SPINOFF_SERIES)}`);
    let csrf = await page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
    let resp = await page.evaluate(async ({ base, bookId, collana, csrf }) => {
      const body = new URLSearchParams({ book_id: String(bookId), collana, csrf_token: csrf });
      const r = await fetch(base + '/admin/collane/rimuovi-libro', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body
      });
      return { status: r.status, location: r.url };
    }, { base: BASE, bookId: mainBookId, collana: SPINOFF_SERIES, csrf });

    expect(resp.status).toBe(200);
    expect(dbQuery(`
      SELECT COUNT(*)
        FROM libri_collane lc
        JOIN collane c ON c.id = lc.collana_id
       WHERE lc.libro_id = ${mainBookId}
         AND c.nome = '${escapeSql(SPINOFF_SERIES)}'
    `)).toBe('0');
    expect(dbQuery(`SELECT collana FROM libri WHERE id = ${mainBookId}`)).toBe(MAIN_SERIES);

    const bookId = ids.get('happy1');
    await page.goto(`${BASE}/admin/collane/dettaglio?nome=${encodeURIComponent(HAPPY_SERIES)}`);
    page.once('dialog', dialog => dialog.accept());
    await Promise.all([
      page.waitForURL(/\/admin\/collane$/, { timeout: 10000 }),
      page.click('button:has-text("Elimina collana")'),
    ]);

    const row = dbQuery(`SELECT CONCAT_WS('|', titolo, IFNULL(collana, ''), IFNULL(numero_serie, '')) FROM libri WHERE id = ${bookId}`);
    expect(row).toBe(`${TAG} Happy volume 1||`);
    expect(dbQuery(`SELECT COUNT(*) FROM collane WHERE nome = '${escapeSql(HAPPY_SERIES)}'`)).toBe('0');
    expect(dbQuery(`
      SELECT COUNT(*)
        FROM libri_collane lc
        LEFT JOIN collane c ON c.id = lc.collana_id
       WHERE lc.libro_id = ${bookId}
          OR c.nome = '${escapeSql(HAPPY_SERIES)}'
    `)).toBe('0');
  });
});
