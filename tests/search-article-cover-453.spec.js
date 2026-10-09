// @ts-check
/**
 * #453: the header search's suggestions show an article with its image, as
 * books show their covers — the article's own cover, else its issue's, else
 * the masthead's logo (the same rule as the article's page). With no image at
 * all the row keeps the newspaper icon, in a frame the size of a book cover.
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const e2e = (key) => {
  const v = process.env[key];
  return v === undefined || v === 'undefined' ? '' : v;
};

function db(sql) {
  const args = ['-u', e2e('E2E_DB_USER'), e2e('E2E_DB_NAME'), '-N', '-B', '--raw', '-e', sql];
  if (e2e('E2E_DB_HOST')) {
    args.splice(2, 0, '-h', e2e('E2E_DB_HOST'));
    if (e2e('E2E_DB_PORT')) args.splice(4, 0, '-P', e2e('E2E_DB_PORT'));
  } else if (e2e('E2E_DB_SOCKET')) {
    args.splice(2, 0, '-S', e2e('E2E_DB_SOCKET'));
  }
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: e2e('E2E_DB_PASS') } }).trim();
}

const COVER = '/assets/brand/logo_small.png';
let article = null; // { id, cover, term, updatedAt }

test.describe.serial('#453 article images in the search suggestions', () => {
  test.beforeAll(() => {
    test.skip(!e2e('E2E_DB_USER'), 'Run with /tmp/run-e2e.sh');
    const hasTable = db("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emeroteca_contributi'") === '1';
    test.skip(!hasTable, 'emeroteca plugin not installed');
    const row = db("SELECT JSON_ARRAY(id, copertina_url, titolo, updated_at) FROM emeroteca_contributi WHERE pubblico = 1 AND CHAR_LENGTH(titolo) >= 6 ORDER BY id LIMIT 1");
    test.skip(row === '', 'no published article');
    const [id, cover, title, updatedAt] = JSON.parse(row);
    // A word of the title long enough to be a search term on its own.
    const term = title.split(/\s+/).map(w => w.replace(/[^\p{L}\p{N}]/gu, '')).sort((a, b) => b.length - a.length)[0];
    article = { id: Number(id), cover, term, updatedAt };
  });

  test.afterAll(() => {
    if (article) {
      const sqlValue = value => value === null ? 'NULL' : "'" + value.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
      db(`UPDATE emeroteca_contributi SET copertina_url=${sqlValue(article.cover)}, updated_at=${sqlValue(article.updatedAt)} WHERE id=${article.id}`);
    }
  });

  async function suggestion(page) {
    await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });
    const input = page.locator('input[type="search"]:visible').first();
    await input.fill(article.term);
    const row = page.locator(`.article-result[href$="/emeroteca/articolo/${article.id}"]`);
    await expect(row).toBeVisible({ timeout: 10000 });
    return row;
  }

  test('an article with a cover shows it, sized like a book cover', async ({ page, request }) => {
    db(`UPDATE emeroteca_contributi SET copertina_url='${COVER}' WHERE id=${article.id}`);
    const res = await request.get(`${BASE}/api/search/preview?q=${encodeURIComponent(article.term)}`);
    const hit = (await res.json()).find(r => r.type === 'article' && String(r.url).endsWith(`/${article.id}`));
    expect(hit && hit.cover).toMatch(/\/assets\/brand\/logo_small\.png$/);

    const row = await suggestion(page);
    const img = row.locator('img.search-article-cover');
    await expect(img).toHaveCount(1);
    const box = await img.evaluate(el => ({ w: el.getBoundingClientRect().width, h: el.getBoundingClientRect().height, loaded: el.naturalWidth > 0 }));
    expect(box).toEqual({ w: 40, h: 60, loaded: true });
  });

  test('an article with no image keeps the newspaper icon in a cover-sized frame', async ({ page }) => {
    const fallback = db(`SELECT COALESCE(NULLIF(f.copertina_url, ''), NULLIF(t.logo_url, ''), '') FROM emeroteca_contributi c LEFT JOIN emeroteca_fascicoli f ON f.id = c.fascicolo_id LEFT JOIN emeroteca_testate t ON t.id = c.testata_id WHERE c.id = ${article.id}`);
    test.skip(fallback !== '', 'the article has an issue cover or a masthead logo to fall back on');
    db(`UPDATE emeroteca_contributi SET copertina_url=NULL WHERE id=${article.id}`);
    const row = await suggestion(page);
    await expect(row.locator('img')).toHaveCount(0);
    const frame = row.locator('.search-article-cover');
    await expect(frame.locator('.fa-newspaper')).toHaveCount(1);
    expect(await frame.evaluate(el => [el.getBoundingClientRect().width, el.getBoundingClientRect().height])).toEqual([40, 60]);
  });
});
