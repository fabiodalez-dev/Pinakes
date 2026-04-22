// @ts-check
/**
 * Archives E2E documents (persistent).
 *
 * Applies tests/seeds/archives-feature-20.sql, keeps those records in the DB,
 * and verifies every seeded archival document through admin and public routes.
 * Run with:
 *   npx playwright test tests/archives-feature-documents.spec.js --config=tests/playwright.config.js
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

const ROOT = path.resolve(__dirname, '..');
const MIGRATION_SQL = path.join(ROOT, 'installer/database/migrations/migrate_0.5.9.sql');
const SEED_SQL = path.join(__dirname, 'seeds/archives-feature-20.sql');
const seedSql = fs.readFileSync(SEED_SQL, 'utf8');

function mysqlArgs(sql = '', batch = false) {
  const args = ['-u', DB_USER];
  if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
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

function parseCases(sql) {
  const rx = /^-- CASE\s+(E2E_ARCHIVE_\d{3})\s+level=(\w+)\s+material=(\w+)\s+color=(NULL|\w+)\s+status=(\w+)\s+parent=(NONE|E2E_ARCHIVE_\d{3})$/gm;
  return Array.from(sql.matchAll(rx), (match) => ({
    reference: match[1],
    level: match[2],
    material: match[3],
    color: match[4],
    status: match[5],
    parent: match[6],
  }));
}

function ensureArchivesPluginIsRoutable() {
  if (fs.existsSync(MIGRATION_SQL)) {
    dbPipe(fs.readFileSync(MIGRATION_SQL, 'utf8'), 90000);
  }

  dbExec(`
    UPDATE plugins
       SET is_active = 1,
           path = 'archives',
           main_file = 'wrapper.php'
     WHERE name = 'archives'
  `);

  dbExec(`
    INSERT INTO plugin_hooks
      (plugin_id, hook_name, callback_class, callback_method, priority, is_active, created_at)
    SELECT p.id, 'app.routes.register', 'ArchivesPlugin', 'registerRoutes', 10, 1, NOW()
      FROM plugins p
     WHERE p.name = 'archives'
       AND NOT EXISTS (
         SELECT 1 FROM plugin_hooks ph
          WHERE ph.plugin_id = p.id
            AND ph.hook_name = 'app.routes.register'
            AND ph.callback_method = 'registerRoutes'
       )
  `);
  dbExec(`
    UPDATE plugin_hooks ph
    JOIN plugins p ON p.id = ph.plugin_id
       SET ph.callback_class = 'ArchivesPlugin',
           ph.callback_method = 'registerRoutes',
           ph.priority = 10,
           ph.is_active = 1
     WHERE p.name = 'archives'
       AND ph.hook_name = 'app.routes.register'
       AND ph.callback_method = 'registerRoutes'
  `);

  dbExec(`
    INSERT INTO plugin_hooks
      (plugin_id, hook_name, callback_class, callback_method, priority, is_active, created_at)
    SELECT p.id, 'admin.menu.render', 'ArchivesPlugin', 'renderAdminMenuEntry', 10, 1, NOW()
      FROM plugins p
     WHERE p.name = 'archives'
       AND NOT EXISTS (
         SELECT 1 FROM plugin_hooks ph
          WHERE ph.plugin_id = p.id
            AND ph.hook_name = 'admin.menu.render'
            AND ph.callback_method = 'renderAdminMenuEntry'
       )
  `);
  dbExec(`
    UPDATE plugin_hooks ph
    JOIN plugins p ON p.id = ph.plugin_id
       SET ph.callback_class = 'ArchivesPlugin',
           ph.callback_method = 'renderAdminMenuEntry',
           ph.priority = 10,
           ph.is_active = 1
     WHERE p.name = 'archives'
       AND ph.hook_name = 'admin.menu.render'
       AND ph.callback_method = 'renderAdminMenuEntry'
  `);
}

test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'Missing E2E env vars for persistent Archives document tests'
);

test.describe.serial('Archives persistent documents', () => {
  const cases = parseCases(seedSql);
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    expect(cases).toHaveLength(20);
    ensureArchivesPluginIsRoutable();
    dbPipe(seedSql);

    context = await browser.newContext();
    page = await context.newPage();
    await page.goto(`${BASE}/login`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
      page.waitForURL((url) => !/\/(login|accedi)(\?|$)/.test(url.pathname + url.search), { timeout: 15000 }),
      page.click('button[type="submit"]'),
    ]);
  });

  test.afterAll(async () => {
    await context?.close();
  });

  for (const [index, fixture] of cases.entries()) {
    test(`A${String(index + 1).padStart(2, '0')}. ${fixture.reference} renders as persistent ${fixture.level}/${fixture.material} archive document`, async () => {
      const row = dbQuery(`
        SELECT CONCAT_WS('|',
                 id,
                 constructed_title,
                 level,
                 specific_material,
                 IFNULL(color_mode, ''),
                 material_status,
                 IFNULL(parent_id, ''),
                 scope_content,
                 local_classification
               )
          FROM archival_units
         WHERE reference_code = '${fixture.reference}'
           AND institution_code = 'PINAKES'
           AND deleted_at IS NULL
         LIMIT 1
      `);
      expect(row, `${fixture.reference} should exist after seed`).not.toBe('');
      const [id, title, level, material, color, status, parentId, scope, localClass] = row.split('|');
      expect(level).toBe(fixture.level);
      expect(material).toBe(fixture.material);
      expect(status).toBe(fixture.status);
      expect(color || 'NULL').toBe(fixture.color);
      if (fixture.parent === 'NONE') {
        expect(parentId).toBe('');
      } else {
        expect(parentId).toMatch(/^\d+$/);
      }

      await page.goto(`${BASE}/admin/archives/${id}`);
      await page.waitForLoadState('domcontentloaded');
      await expect(page.locator('body')).toContainText(title);
      await expect(page.locator('body')).toContainText(fixture.reference);
      await expect(page.locator('body')).toContainText(scope.slice(0, 30));
      await expect(page.locator('body')).toContainText(localClass);

      await page.goto(`${BASE}/archive/${id}`);
      await page.waitForLoadState('domcontentloaded');
      await expect(page.locator('body')).toContainText(title);
      await expect(page.locator('body')).toContainText(fixture.reference);
      await expect(page.locator('body')).toContainText(scope.slice(0, 30));
    });
  }
});
