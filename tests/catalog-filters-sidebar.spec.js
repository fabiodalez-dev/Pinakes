// @ts-check
/**
 * On a desktop the catalogue filters are a sidebar beside the books, whatever
 * the names in them. A live library has publishers named in a hundred
 * characters ("Cross-National Research Group, European Research Centre,
 * Loughborough University of Technology"): shown with an ellipsis, they still
 * counted at full length in the column's minimum width, the column grew to
 * the whole page and the books went under the filters.
 *
 * The data is seeded (a publisher with such a name on three books), the check
 * is what a visitor sees: the filters on the left, at most 300px, the books
 * beside them.
 *
 * Run: /tmp/run-e2e.sh tests/catalog-filters-sidebar.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const LONG = 'Cross-National Research Group, European Research Centre, Loughborough University of Technology E2E';

function db(sql) {
  const args = ['--default-character-set=utf8mb4', '-N', '-B', '--raw', '-e', sql];
  if (process.env.E2E_DB_HOST) args.push('-h', process.env.E2E_DB_HOST, ...(process.env.E2E_DB_PORT ? ['-P', process.env.E2E_DB_PORT] : []));
  else if (process.env.E2E_DB_SOCKET) args.push('-S', process.env.E2E_DB_SOCKET);
  args.push('-u', process.env.E2E_DB_USER || '', process.env.E2E_DB_NAME || '');
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 15000, env: { ...process.env, MYSQL_PWD: process.env.E2E_DB_PASS || '' } }).trim();
}

test.skip(!process.env.E2E_DB_USER, 'database credentials required');

test.describe.serial('Catalogue filters sidebar', () => {
  let publisherId = '';
  /** @type {Array<[string, string]>} */
  let books = [];

  test.beforeAll(() => {
    db(`INSERT INTO editori (nome) VALUES ('${LONG}')`);
    publisherId = db(`SELECT id FROM editori WHERE nome = '${LONG}' ORDER BY id DESC LIMIT 1`);
    books = db('SELECT id, IFNULL(editore_id, \'NULL\') FROM libri WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 3')
      .split('\n').filter(Boolean).map((l) => /** @type {[string, string]} */ (l.split('\t')));
    for (const [id] of books) {
      db(`UPDATE libri SET editore_id = ${publisherId} WHERE id = ${id}`);
      db(`INSERT IGNORE INTO libri_editori (libro_id, editore_id, ordine) VALUES (${id}, ${publisherId}, 99)`);
    }
  });

  test.afterAll(() => {
    try {
      for (const [id, prev] of books) db(`UPDATE libri SET editore_id = ${prev} WHERE id = ${id}`);
      if (publisherId) {
        db(`DELETE FROM libri_editori WHERE editore_id = ${publisherId}`);
        db(`DELETE FROM editori WHERE id = ${publisherId}`);
      }
    } catch { /* best effort */ }
  });

  for (const width of [1440, 1024]) {
    test(`at ${width}px the filters stay a sidebar beside the books`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`${BASE}/catalogo`, { waitUntil: 'networkidle' });
      await expect(page.locator('#publishers-filter')).toContainText('Loughborough');
      const r = await page.evaluate(() => {
        const f = document.querySelector('.pk-filters').getBoundingClientRect();
        const g = document.querySelector('.pk-results').getBoundingClientRect();
        return { fw: Math.round(f.width), fRight: Math.round(f.right), gLeft: Math.round(g.left), sameRow: Math.abs(f.top - g.top) < 120 };
      });
      expect(r.fw, 'the sidebar keeps its width').toBeLessThanOrEqual(300);
      expect(r.sameRow, 'the books sit beside the filters, not under them').toBe(true);
      expect(r.gLeft, 'the books start right of the filters').toBeGreaterThan(r.fRight);
    });
  }
});
