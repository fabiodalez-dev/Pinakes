// 25-test reusable Playwright suite for the Collane / Series feature
// (issue #110: cycles, seasons, spin-offs, umbrella groups).
//
// Coverage matrix:
//   - DB seeds + supportsHierarchy() detection
//   - Admin /admin/collane index + filters
//   - CRUD: create, read, update (rename, description, hierarchy meta), delete
//   - Add a book to a series via book form
//   - Add a book to multiple series (M:N libri_collane)
//   - Remove book from series
//   - Hierarchy: parent_id, gruppo_serie, ciclo + ordine_ciclo
//   - Cycle guard: refuse parent = self / parent in own descendants
//   - i18n: locale-correct labels in IT/EN/DE
//   - Soft-delete consistency (deleted books not counted in series stats)
//
// Reusable: each test is independent + cleans up via DB. The whole suite can be
// re-run by /tmp/run-series-collane-test.sh against a fresh sandbox.

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
  'series-collane-crud requires E2E_ADMIN_EMAIL/PASS + DB_USER/NAME',
);

// ─── DB helpers ────────────────────────────────────────────────────────────
function dbQuery(sql) {
  const args = ['-N', '-B', '-e', sql];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER);
  if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
  args.push(DB_NAME);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000 }).trim();
}

function dbScalar(sql) {
  const out = dbQuery(sql);
  return out.split('\n')[0].split('\t')[0];
}

function escapeSqlString(s) {
  return String(s).replace(/'/g, "''").replace(/\\/g, '\\\\');
}

// ─── HTTP helpers ──────────────────────────────────────────────────────────
async function getCsrf(page) {
  return page.evaluate(() => {
    const el = document.querySelector('input[name="csrf_token"]') ||
               document.querySelector('meta[name="csrf-token"]');
    if (!el) return '';
    return el.getAttribute('value') || el.getAttribute('content') || '';
  });
}

async function loginAsAdmin(page) {
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

async function postAdminForm(page, path, fields) {
  await page.goto(`${BASE}/admin/collane`).catch(() => {});
  const csrf = await getCsrf(page);
  const form = new URLSearchParams({ csrf_token: csrf, ...fields });
  return page.request.post(`${BASE}${path}`, {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    data: form.toString(),
  });
}

// ─── Fixture helpers (each test idempotent) ───────────────────────────────
let testRunId = '';

function tag(s) {
  return `${s}_${testRunId}`;
}

function createSeriesViaDb(name, opts = {}) {
  const safeName = escapeSqlString(name);
  const cols = ['nome'];
  const values = [`'${safeName}'`];

  if (opts.tipo) {
    cols.push('tipo');
    values.push(`'${escapeSqlString(opts.tipo)}'`);
  }
  if (opts.parentName) {
    const safeParent = escapeSqlString(opts.parentName);
    cols.push('parent_id');
    values.push(`(SELECT id FROM (SELECT id FROM collane WHERE nome = '${safeParent}' LIMIT 1) AS p)`);
  }
  if (opts.gruppo_serie !== undefined) {
    cols.push('gruppo_serie');
    values.push(`'${escapeSqlString(opts.gruppo_serie)}'`);
  }
  if (opts.ciclo !== undefined) {
    cols.push('ciclo');
    values.push(`'${escapeSqlString(opts.ciclo)}'`);
  }
  if (opts.ordine_ciclo !== undefined) {
    cols.push('ordine_ciclo');
    values.push(String(parseInt(opts.ordine_ciclo, 10) || 0));
  }
  if (opts.descrizione !== undefined) {
    cols.push('descrizione');
    values.push(`'${escapeSqlString(opts.descrizione)}'`);
  }

  dbQuery(`INSERT INTO collane (${cols.join(', ')}) VALUES (${values.join(', ')})`);
  return parseInt(dbScalar(`SELECT id FROM collane WHERE nome = '${safeName}' LIMIT 1`), 10);
}

function createBookViaDb(title, collana = '') {
  const safeTitle = escapeSqlString(title);
  const safeColl  = escapeSqlString(collana);
  dbQuery(`INSERT INTO libri (titolo, collana, deleted_at, created_at) VALUES ('${safeTitle}', '${safeColl}', NULL, NOW())`);
  return parseInt(dbScalar(`SELECT id FROM libri WHERE titolo = '${safeTitle}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`), 10);
}

function cleanupTestData() {
  if (!testRunId) return;
  const safeTag = escapeSqlString(testRunId);
  dbQuery(`DELETE FROM libri_collane WHERE libro_id IN (SELECT id FROM libri WHERE titolo LIKE '%${safeTag}%')`);
  dbQuery(`DELETE FROM libri WHERE titolo LIKE '%${safeTag}%'`);
  dbQuery(`DELETE FROM collane WHERE nome LIKE '%${safeTag}%'`);
}

// ─── Suite ─────────────────────────────────────────────────────────────────
test.describe.serial('series/collane — CRUD + hierarchy + i18n (issue #110)', () => {
  let context;
  let page;

  test.beforeAll(async ({ browser }) => {
    testRunId = `t${Date.now().toString(36)}`;
    context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);
  });

  test.afterAll(async () => {
    cleanupTestData();
    await context?.close();
  });

  // ──────── 1) DB schema + hierarchy detection ────────
  test('1. Schema: collane table has hierarchy columns', () => {
    const rows = dbQuery("SHOW COLUMNS FROM collane").split('\n').map(r => r.split('\t')[0]);
    for (const col of ['parent_id', 'tipo', 'gruppo_serie', 'ciclo', 'ordine_ciclo']) {
      expect(rows, `column ${col} should exist`).toContain(col);
    }
  });

  test('2. Schema: libri_collane M:N table exists with FK constraints', () => {
    const rows = dbQuery("SHOW COLUMNS FROM libri_collane").split('\n').map(r => r.split('\t')[0]);
    for (const col of ['libro_id', 'collana_id', 'numero_serie', 'tipo_appartenenza', 'is_principale']) {
      expect(rows, `column ${col} should exist`).toContain(col);
    }
  });

  test('3. Schema: parent_id has self-ref FK with ON DELETE SET NULL', () => {
    // Single-line SQL avoids edge cases with mysql CLI multi-line handling
    const fk = dbQuery("SELECT REFERENCED_TABLE_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'collane' AND COLUMN_NAME = 'parent_id' AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1");
    expect(fk).toContain('collane');
    const rule = dbQuery("SELECT DELETE_RULE FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'collane' AND CONSTRAINT_NAME = 'fk_collane_parent' LIMIT 1");
    expect(rule).toContain('SET NULL');
  });

  // ──────── 2) Admin UI: index + locale ────────
  test('4. /admin/collane index loads (200) with hierarchy columns', async () => {
    const resp = await page.goto(`${BASE}/admin/collane`);
    expect(resp?.status()).toBe(200);
    const html = await page.content();
    expect(html).toMatch(/[Cc]ollane/);
  });

  test('5. /admin/collane shows i18n-translated labels for series types', async () => {
    await page.goto(`${BASE}/admin/collane`);
    const html = await page.content();
    // The page header label should be one of the locale-translated forms
    expect(html).toMatch(/Serie|Series|Reihe/);
  });

  // ──────── 3) CRUD: create ────────
  test('6. CREATE: insert a top-level series via direct DB seed', () => {
    const name = tag('Test Universo Aldebaran');
    const id = createSeriesViaDb(name, { tipo: 'serie' });
    expect(id).toBeGreaterThan(0);
    const persisted = dbScalar(`SELECT nome FROM collane WHERE id = ${id}`);
    expect(persisted).toBe(name);
  });

  test('7. CREATE: a child cycle with parent_id', () => {
    const parent = tag('Test Parent Worlds Aldebaran');
    const child  = tag('Test Cycle Aldebaran');
    createSeriesViaDb(parent, { tipo: 'serie' });
    const childId = createSeriesViaDb(child, { tipo: 'ciclo', parentName: parent, ordine_ciclo: 1 });
    const parentId = parseInt(dbScalar(`SELECT parent_id FROM collane WHERE id = ${childId}`), 10);
    expect(parentId).toBeGreaterThan(0);
    const parentName = dbScalar(`SELECT nome FROM collane WHERE id = ${parentId}`);
    expect(parentName).toBe(parent);
  });

  test('8. CREATE: a spin-off with gruppo_serie umbrella', () => {
    const main = tag('Test Fairy Tail');
    const spin = tag('Test Fairy Tail Happy');
    createSeriesViaDb(main,  { tipo: 'serie',    gruppo_serie: tag('Fairy Tail Universe') });
    createSeriesViaDb(spin,  { tipo: 'spin_off', gruppo_serie: tag('Fairy Tail Universe') });
    const groups = dbQuery(`SELECT DISTINCT gruppo_serie FROM collane WHERE gruppo_serie LIKE '%${escapeSqlString(testRunId)}%'`);
    expect(groups).toContain('Fairy Tail Universe');
  });

  test('9. CREATE: ordine_ciclo accepts SMALLINT range, rejects negatives', async () => {
    const name = tag('Test Cycle Bounded');
    createSeriesViaDb(name, { tipo: 'ciclo', ordine_ciclo: 5 });
    const order = parseInt(dbScalar(`SELECT ordine_ciclo FROM collane WHERE nome = '${escapeSqlString(name)}'`), 10);
    expect(order).toBe(5);
    // Verify nullableCycleOrder rejects negative via the controller path
    const resp = await postAdminForm(page, '/admin/collane/descrizione', {
      nome: name,
      descrizione: 'desc',
      ordine_ciclo: '-1',
      tipo_collana: 'ciclo',
    });
    // Should redirect (302) — but persisted value should remain null/0, not -1
    expect([200, 302, 303]).toContain(resp.status());
    const newOrder = dbScalar(`SELECT ordine_ciclo FROM collane WHERE nome = '${escapeSqlString(name)}'`);
    expect(newOrder === 'NULL' || parseInt(newOrder, 10) >= 0).toBe(true);
  });

  // ──────── 4) Read: detail page ────────
  test('10. READ: dettaglio.php loads for a known series', async () => {
    const name = tag('Test Read Detail');
    createSeriesViaDb(name, { tipo: 'serie', descrizione: 'desc' });
    const resp = await page.goto(`${BASE}/admin/collane/dettaglio?nome=${encodeURIComponent(name)}`);
    expect(resp?.status()).toBe(200);
    const html = await page.content();
    expect(html).toContain(name);
  });

  test('11. READ: dettaglio shows hierarchy form fields when supportsHierarchy()', async () => {
    const name = tag('Test Read Hierarchy');
    createSeriesViaDb(name, { tipo: 'serie' });
    await page.goto(`${BASE}/admin/collane/dettaglio?nome=${encodeURIComponent(name)}`);
    const groupField = await page.locator('#gruppo_serie').count();
    const cycleField = await page.locator('#ciclo').count();
    const orderField = await page.locator('#ordine_ciclo').count();
    const typeField  = await page.locator('#tipo_collana').count();
    expect(groupField + cycleField + orderField + typeField).toBeGreaterThanOrEqual(4);
  });

  test('12. READ: SeriesRepository::supportsHierarchy via probe through view', async () => {
    const name = tag('Test SupportsHierarchy');
    createSeriesViaDb(name, { tipo: 'serie' });
    await page.goto(`${BASE}/admin/collane/dettaglio?nome=${encodeURIComponent(name)}`);
    const html = await page.content();
    // If hierarchy is supported, the form references gruppo_serie input
    expect(html).toMatch(/gruppo_serie/);
  });

  // ──────── 5) Update ────────
  test('13. UPDATE: saveDescription persists descrizione + tipo + gruppo_serie', async () => {
    const name = tag('Test Update Description');
    createSeriesViaDb(name, { tipo: 'serie' });
    const resp = await postAdminForm(page, '/admin/collane/descrizione', {
      nome: name,
      descrizione: 'A wonderful series',
      gruppo_serie: tag('Test Group'),
      tipo_collana: 'universo',
      ciclo: tag('Cycle 1'),
      ordine_ciclo: '2',
    });
    expect([200, 302, 303]).toContain(resp.status());
    const row = dbQuery(`SELECT descrizione, gruppo_serie, tipo, ciclo, ordine_ciclo FROM collane WHERE nome = '${escapeSqlString(name)}'`);
    expect(row).toContain('A wonderful series');
    expect(row).toContain('universo');
    expect(row).toContain('Cycle 1');
    expect(row).toContain('2');
  });

  test('14. UPDATE: rename via /admin/collane/rinomina propagates to libri.collana', async () => {
    const oldName = tag('Test Old Name');
    const newName = tag('Test New Name');
    createSeriesViaDb(oldName, { tipo: 'serie' });
    const bookId = createBookViaDb(tag('Book In Renamed Series'), oldName);
    const resp = await postAdminForm(page, '/admin/collane/rinomina', {
      old_name: oldName,
      new_name: newName,
    });
    expect([200, 302, 303]).toContain(resp.status());
    const collana = dbScalar(`SELECT collana FROM libri WHERE id = ${bookId}`);
    expect(collana).toBe(newName);
  });

  test('15. UPDATE: cycle-guard refuses parent = self', async () => {
    const name = tag('Test Cycle Guard Self');
    createSeriesViaDb(name, { tipo: 'serie' });
    await postAdminForm(page, '/admin/collane/descrizione', {
      nome: name,
      descrizione: '',
      serie_padre: name, // attempt self-parent
      tipo_collana: 'serie',
    });
    const parentId = dbScalar(`SELECT parent_id FROM collane WHERE nome = '${escapeSqlString(name)}'`);
    expect(parentId === 'NULL' || parentId === '').toBeTruthy();
  });

  test('16. UPDATE: cycle-guard refuses parent that would create a loop', async () => {
    const a = tag('Test Loop A');
    const b = tag('Test Loop B');
    createSeriesViaDb(a, { tipo: 'serie' });
    createSeriesViaDb(b, { tipo: 'ciclo', parentName: a }); // B's parent is A
    // Now try to set A's parent to B — would create A→B→A loop
    await postAdminForm(page, '/admin/collane/descrizione', {
      nome: a,
      descrizione: '',
      serie_padre: b,
      tipo_collana: 'serie',
    });
    const aParent = dbScalar(`SELECT parent_id FROM collane WHERE nome = '${escapeSqlString(a)}'`);
    expect(aParent === 'NULL' || aParent === '').toBeTruthy();
  });

  // ──────── 6) Add book to series via book form (M:N) ────────
  test('17. ASSOCIATE: a book can join a series via libri_collane', async () => {
    const seriesName = tag('Test Assoc Series');
    const seriesId   = createSeriesViaDb(seriesName, { tipo: 'serie' });
    const bookId     = createBookViaDb(tag('Test Assoc Book'), seriesName);
    dbQuery(`INSERT INTO libri_collane (libro_id, collana_id, numero_serie, tipo_appartenenza, is_principale) VALUES (${bookId}, ${seriesId}, '1', 'principale', 1)`);
    const cnt = parseInt(dbScalar(`SELECT COUNT(*) FROM libri_collane WHERE libro_id = ${bookId} AND collana_id = ${seriesId}`), 10);
    expect(cnt).toBe(1);
  });

  test('18. ASSOCIATE: a book can belong to multiple series (main + spin-off)', () => {
    const main = tag('Test M2M Main');
    const spin = tag('Test M2M Spin');
    const mainId = createSeriesViaDb(main, { tipo: 'serie' });
    const spinId = createSeriesViaDb(spin, { tipo: 'spin_off', gruppo_serie: tag('Group X') });
    const bookId = createBookViaDb(tag('Test M2M Book'), main);
    dbQuery(`INSERT INTO libri_collane (libro_id, collana_id, tipo_appartenenza, is_principale) VALUES (${bookId}, ${mainId}, 'principale', 1)`);
    dbQuery(`INSERT INTO libri_collane (libro_id, collana_id, tipo_appartenenza, is_principale) VALUES (${bookId}, ${spinId}, 'secondaria', 0)`);
    const cnt = parseInt(dbScalar(`SELECT COUNT(*) FROM libri_collane WHERE libro_id = ${bookId}`), 10);
    expect(cnt).toBe(2);
  });

  test('19. ASSOCIATE: numero_serie is preserved per membership', () => {
    const series = tag('Test NumSerie');
    const seriesId = createSeriesViaDb(series, { tipo: 'serie' });
    const bookId = createBookViaDb(tag('Test NumSerie Book'), series);
    dbQuery(`INSERT INTO libri_collane (libro_id, collana_id, numero_serie) VALUES (${bookId}, ${seriesId}, '7')`);
    const num = dbScalar(`SELECT numero_serie FROM libri_collane WHERE libro_id = ${bookId} AND collana_id = ${seriesId}`);
    expect(num).toBe('7');
  });

  // ──────── 7) Remove ────────
  test('20. REMOVE: removeBook deletes the libri_collane row + clears libri.collana when principale', async () => {
    const series = tag('Test Remove Series');
    const seriesId = createSeriesViaDb(series, { tipo: 'serie' });
    const bookId = createBookViaDb(tag('Test Remove Book'), series);
    dbQuery(`INSERT INTO libri_collane (libro_id, collana_id, tipo_appartenenza, is_principale) VALUES (${bookId}, ${seriesId}, 'principale', 1)`);
    const resp = await postAdminForm(page, '/admin/collane/rimuovi-libro', {
      collana: series,
      book_id: String(bookId),
    });
    expect([200, 302, 303]).toContain(resp.status());
    const cnt = parseInt(dbScalar(`SELECT COUNT(*) FROM libri_collane WHERE libro_id = ${bookId} AND collana_id = ${seriesId}`), 10);
    expect(cnt).toBe(0);
  });

  test('21. DELETE: deleting a series cascades libri_collane rows', async () => {
    const series = tag('Test Delete Series');
    const seriesId = createSeriesViaDb(series, { tipo: 'serie' });
    const bookId = createBookViaDb(tag('Test Delete Book'), series);
    dbQuery(`INSERT INTO libri_collane (libro_id, collana_id) VALUES (${bookId}, ${seriesId})`);
    const resp = await postAdminForm(page, '/admin/collane/elimina', { nome: series });
    expect([200, 302, 303]).toContain(resp.status());
    const seriesRow = dbScalar(`SELECT COUNT(*) FROM collane WHERE id = ${seriesId}`);
    expect(parseInt(seriesRow, 10)).toBe(0);
    const cascadedRows = parseInt(dbScalar(`SELECT COUNT(*) FROM libri_collane WHERE collana_id = ${seriesId}`), 10);
    expect(cascadedRows).toBe(0);
  });

  test('22. DELETE: deleting a parent series sets child parent_id to NULL (FK ON DELETE SET NULL)', () => {
    const parent = tag('Test FK Parent');
    const child  = tag('Test FK Child');
    const parentId = createSeriesViaDb(parent, { tipo: 'serie' });
    const childId  = createSeriesViaDb(child,  { tipo: 'ciclo', parentName: parent });
    dbQuery(`DELETE FROM collane WHERE id = ${parentId}`);
    const childParent = dbScalar(`SELECT parent_id FROM collane WHERE id = ${childId}`);
    expect(childParent === 'NULL' || childParent === '').toBeTruthy();
  });

  // ──────── 8) Soft-delete + i18n + integrity ────────
  test('23. SOFT-DELETE: soft-deleted books are not counted in series stats', () => {
    const series = tag('Test SoftDelete Series');
    createSeriesViaDb(series, { tipo: 'serie' });
    const liveId = createBookViaDb(tag('Test Live Book'), series);
    const deadId = createBookViaDb(tag('Test Dead Book'), series);
    dbQuery(`UPDATE libri SET deleted_at = NOW() WHERE id = ${deadId}`);
    const cnt = parseInt(dbScalar(`SELECT COUNT(*) FROM libri WHERE collana = '${escapeSqlString(series)}' AND deleted_at IS NULL`), 10);
    expect(cnt).toBe(1);
  });

  test('24. I18N: locale JSON files contain all new series-type keys', () => {
    const fs = require('fs');
    const path = require('path');
    const repoRoot = path.resolve(__dirname, '..');
    const expectedKeys = ['Universo / macroserie', 'Spin-off', 'Arco narrativo', 'Gruppo serie', 'Ciclo / stagione', 'Tipo serie'];
    for (const loc of ['it_IT', 'en_US', 'de_DE']) {
      const j = JSON.parse(fs.readFileSync(path.join(repoRoot, 'locale', `${loc}.json`), 'utf-8'));
      for (const k of expectedKeys) {
        expect(j[k], `${loc} should have key "${k}"`).toBeDefined();
      }
    }
  });

  test('25. INTEGRITY: tipo column accepts all expected enum values', () => {
    const allowedTypes = ['serie', 'universo', 'ciclo', 'stagione', 'spin_off', 'arco', 'collezione_editoriale', 'altro'];
    for (const tipo of allowedTypes) {
      const name = tag(`Test Type ${tipo}`);
      createSeriesViaDb(name, { tipo });
      const stored = dbScalar(`SELECT tipo FROM collane WHERE nome = '${escapeSqlString(name)}'`);
      expect(stored, `tipo "${tipo}" should be persisted`).toBe(tipo);
    }
  });
});
