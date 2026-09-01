// @ts-check
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const DB_USER   = process.env.E2E_DB_USER   || '';
const DB_PASS   = process.env.E2E_DB_PASS   || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME   = process.env.E2E_DB_NAME   || '';
const DB_HOST   = process.env.E2E_DB_HOST   || '';
const DB_PORT   = process.env.E2E_DB_PORT   || '';

function dbQuery(sql) {
  const args = [];
  if (DB_HOST) { args.push('-h', DB_HOST); if (DB_PORT) args.push('-P', DB_PORT); }
  else if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, {
    encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}

function dbExec(sql) {
  const args = [];
  if (DB_HOST) { args.push('-h', DB_HOST); if (DB_PORT) args.push('-P', DB_PORT); }
  else if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME, '-e', sql);
  execFileSync('mysql', args, {
    encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS },
  });
}

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL((url) => !url.toString().includes('/accedi'), { timeout: 15000 });
}

async function setLlmsTxt(page, enabled) {
  await page.goto(`${BASE}/admin/settings?tab=advanced`);
  const toggle = page.locator('#llms_txt_enabled');
  await toggle.waitFor({ state: 'attached', timeout: 10000 });
  const result = await toggle.evaluate(async (el, on) => {
    el.checked = on;
    const form = el.closest('form');
    if (!form) return { status: 0, url: '' };
    const response = await fetch(form.action, {
      method: 'POST', body: new FormData(form), credentials: 'same-origin',
    });
    return { status: response.status, url: response.url };
  }, enabled);
  expect(result.status).toBe(200);
  expect(new URL(result.url).pathname).toBe('/admin/settings');
  expect(dbQuery("SELECT setting_value FROM system_settings WHERE category='seo' AND setting_key='llms_txt_enabled'"))
    .toBe(enabled ? '1' : '0');
}

// ────────────────────────────────────────────────────────────────────────
// 1. Hreflang tags
// ────────────────────────────────────────────────────────────────────────
test.describe('Hreflang tags', () => {

  // Since 0.7.65 hreflang is intentionally NOT emitted: translated routes live
  // at the root and the response language follows the session locale, not the
  // URL, so path-prefixed alternates ("/en/...") pointed at 404s. These tests
  // lock the "no hreflang" contract; restore the per-locale assertions from git
  // history if locale path-prefix routing is ever implemented.
  test('homepage emits no hreflang alternates', async ({ request }) => {
    const resp = await request.get(`${BASE}/`);
    expect(resp.status()).toBe(200);
    expect(await resp.text()).not.toContain('hreflang=');
  });

  test('catalog emits no hreflang alternates', async ({ request }) => {
    const resp = await request.get(`${BASE}/catalogo`);
    expect(resp.status()).toBe(200);
    expect(await resp.text()).not.toContain('hreflang=');
  });

  test('book page emits no hreflang alternates', async ({ request }) => {
    const sitemapResp = await request.get(`${BASE}/sitemap.xml`);
    expect(sitemapResp.status()).toBe(200);
    const sitemapXml = await sitemapResp.text();
    const bookMatch = sitemapXml.match(new RegExp(`${BASE.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/[^<]+/[^<]+/\\d+`));
    if (!bookMatch) {
      test.skip(true, 'No books in sitemap to test');
      return;
    }
    const resp = await request.get(bookMatch[0]);
    expect(resp.status()).toBe(200);
    expect(await resp.text()).not.toContain('hreflang=');
  });
});

// ────────────────────────────────────────────────────────────────────────
// 2. RSS Feed
// ────────────────────────────────────────────────────────────────────────
test.describe('RSS Feed', () => {

  test('feed.xml returns valid RSS 2.0 XML', async ({ request }) => {
    const resp = await request.get(`${BASE}/feed.xml`);
    expect(resp.status()).toBe(200);

    const contentType = resp.headers()['content-type'] || '';
    expect(contentType).toContain('application/rss+xml');

    const xml = await resp.text();
    expect(xml).toContain('<?xml version="1.0"');
    expect(xml).toContain('<rss version="2.0"');
    expect(xml).toContain('<channel>');
    expect(xml).toContain('</channel>');
    expect(xml).toContain('</rss>');
  });

  test('feed contains channel metadata', async ({ request }) => {
    const resp = await request.get(`${BASE}/feed.xml`);
    expect(resp.status()).toBe(200);
    const xml = await resp.text();

    expect(xml).toContain('<title>');
    expect(xml).toContain('<link>');
    expect(xml).toContain('<description>');
    expect(xml).toContain('<language>');
    expect(xml).toContain('<lastBuildDate>');
    // Atom self link for feed validators
    expect(xml).toContain('rel="self"');
    expect(xml).toContain('type="application/rss+xml"');
  });

  test('feed contains book items with required fields', async ({ request }) => {
    const resp = await request.get(`${BASE}/feed.xml`);
    expect(resp.status()).toBe(200);
    const xml = await resp.text();

    // Skip if DB has no books (e.g. after cleanup phase)
    if (!xml.includes('<item>')) {
      test.skip(true, 'No books in DB — feed is empty');
    }
    expect(xml).toContain('<title>');
    expect(xml).toContain('<link>');
    expect(xml).toContain('<guid');
    expect(xml).toContain('<pubDate>');
  });

  test('feed items have absolute URLs', async ({ request }) => {
    const resp = await request.get(`${BASE}/feed.xml`);
    expect(resp.status()).toBe(200);
    const xml = await resp.text();

    // Extract all <link> values inside <item>
    const linkMatches = [...xml.matchAll(/<item>[\s\S]*?<link>(.*?)<\/link>[\s\S]*?<\/item>/g)];
    if (linkMatches.length === 0) {
      test.skip(true, 'No books in DB — feed is empty');
    }
    for (const match of linkMatches) {
      expect(match[1]).toMatch(/^https?:\/\//);
    }
  });

  test('feed has max 50 items', async ({ request }) => {
    const resp = await request.get(`${BASE}/feed.xml`);
    expect(resp.status()).toBe(200);
    const xml = await resp.text();

    const itemCount = (xml.match(/<item>/g) || []).length;
    expect(itemCount).toBeLessThanOrEqual(50);
    if (itemCount === 0) {
      test.skip(true, 'No books in DB — feed is empty');
    }
  });

  test('lastBuildDate is stable and derived from latest item', async ({ request }) => {
    const resp1 = await request.get(`${BASE}/feed.xml`);
    expect(resp1.status()).toBe(200);
    const xml1 = await resp1.text();

    const resp2 = await request.get(`${BASE}/feed.xml`);
    expect(resp2.status()).toBe(200);
    const xml2 = await resp2.text();

    const date1 = xml1.match(/<lastBuildDate>(.*?)<\/lastBuildDate>/);
    const date2 = xml2.match(/<lastBuildDate>(.*?)<\/lastBuildDate>/);
    expect(date1).not.toBeNull();
    expect(date2).not.toBeNull();

    // Same content → same lastBuildDate (not dynamic gmdate)
    expect(date1[1]).toBe(date2[1]);
  });

  test('layout includes RSS autodiscovery link', async ({ request }) => {
    const resp = await request.get(`${BASE}/`);
    expect(resp.status()).toBe(200);
    const html = await resp.text();

    expect(html).toContain('type="application/rss+xml"');
    expect(html).toContain('feed.xml');
  });
});

// ────────────────────────────────────────────────────────────────────────
// 3. Sitemap expansion
// ────────────────────────────────────────────────────────────────────────
test.describe('Sitemap', () => {

  test('sitemap includes feed.xml entry', async ({ request }) => {
    const resp = await request.get(`${BASE}/sitemap.xml`);
    expect(resp.status()).toBe(200);
    const xml = await resp.text();

    expect(xml).toContain('/feed.xml</loc>');
  });

  test('sitemap has only one global feed.xml entry (not locale-prefixed)', async ({ request }) => {
    const resp = await request.get(`${BASE}/sitemap.xml`);
    expect(resp.status()).toBe(200);
    const xml = await resp.text();

    // feed.xml is a global endpoint — only one entry, no /en/ prefix
    expect(xml).toContain('/feed.xml</loc>');
    const feedCount = (xml.match(/feed\.xml<\/loc>/g) || []).length;
    expect(feedCount).toBe(1);
  });

  test('sitemap includes event URLs when events exist', async ({ request }) => {
    const resp = await request.get(`${BASE}/sitemap.xml`);
    expect(resp.status()).toBe(200);
    const xml = await resp.text();

    // Events use the translated route: /eventi (IT) or /events (EN)
    const hasItEvents = xml.includes('/eventi/');
    const hasEnEvents = xml.includes('/events/');

    // If events exist, both locale variants should be present
    if (hasItEvents || hasEnEvents) {
      expect(hasItEvents).toBe(true);
      expect(hasEnEvents).toBe(true);
    }
  });

  test('sitemap returns valid XML with correct content-type', async ({ request }) => {
    const resp = await request.get(`${BASE}/sitemap.xml`);
    expect(resp.status()).toBe(200);

    const contentType = resp.headers()['content-type'] || '';
    expect(contentType).toContain('application/xml');

    const xml = await resp.text();
    expect(xml).toContain('<?xml');
    expect(xml).toContain('<urlset');
    expect(xml).toContain('</urlset>');
  });
});

// ────────────────────────────────────────────────────────────────────────
// 4. Robots.txt
// ────────────────────────────────────────────────────────────────────────
test.describe('Robots.txt', () => {

  test('robots.txt contains Sitemap directive with absolute URL', async ({ request }) => {
    const resp = await request.get(`${BASE}/robots.txt`);
    expect(resp.status()).toBe(200);

    const text = await resp.text();
    expect(text).toContain('Sitemap:');
    expect(text).toContain('sitemap.xml');

    const sitemapLine = text.split('\n').find(l => l.startsWith('Sitemap:'));
    expect(sitemapLine).toBeDefined();
    const sitemapUrl = sitemapLine.replace('Sitemap:', '').trim();
    expect(sitemapUrl).toMatch(/^https?:\/\//);
  });
});

// ────────────────────────────────────────────────────────────────────────
// 5. llms.txt
// ────────────────────────────────────────────────────────────────────────
test.describe.serial('llms.txt', () => {
  let llmsWasEnabled = false;
  let adminContext;
  let adminPage;

  test.beforeAll(async ({ browser }) => {
    if (!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME) {
      throw new Error('E2E admin and database credentials are required');
    }
    const currentVal = dbQuery(`SELECT setting_value FROM system_settings WHERE category='seo' AND setting_key='llms_txt_enabled'`);
    llmsWasEnabled = currentVal === '1';
    adminContext = await browser.newContext();
    adminPage = await adminContext.newPage();
    await loginAsAdmin(adminPage);
    await setLlmsTxt(adminPage, true);
  });

  test.afterAll(async () => {
    try {
      if (adminPage) await setLlmsTxt(adminPage, llmsWasEnabled);
    } finally {
      await adminContext?.close();
    }
  });

  test('/llms.txt returns 200 with text/plain content-type', async ({ request }) => {
    const resp = await request.get(`${BASE}/llms.txt`);
    expect(resp.status()).toBe(200);

    const contentType = resp.headers()['content-type'] || '';
    expect(contentType).toContain('text/plain');
  });

  test('response starts with H1 heading per llms.txt spec', async ({ request }) => {
    const resp = await request.get(`${BASE}/llms.txt`);
    expect(resp.status()).toBe(200);
    const text = await resp.text();

    expect(text).toMatch(/^# /);
  });

  test('contains blockquote summary line', async ({ request }) => {
    const resp = await request.get(`${BASE}/llms.txt`);
    expect(resp.status()).toBe(200);
    const text = await resp.text();

    expect(text).toContain('\n> ');
    // Summary should mention collection stats (locale-agnostic: check for numbers)
    const summaryLine = text.split('\n').find(l => l.startsWith('> '));
    expect(summaryLine).toBeDefined();
    expect(summaryLine).toMatch(/\d+/);
  });

  test('contains Main Pages section with absolute URLs', async ({ request }) => {
    const resp = await request.get(`${BASE}/llms.txt`);
    expect(resp.status()).toBe(200);
    const text = await resp.text();

    // Should have a section with page links (heading text is locale-dependent)
    expect(text).toMatch(/^## .+/m);
    // All URLs should be absolute
    const urlMatches = [...text.matchAll(/\]\((https?:\/\/[^)]+)\)/g)];
    expect(urlMatches.length).toBeGreaterThan(0);
    for (const match of urlMatches) {
      expect(match[1]).toMatch(/^https?:\/\//);
    }
  });

  test('contains Feeds & Discovery section with feed and sitemap links', async ({ request }) => {
    const resp = await request.get(`${BASE}/llms.txt`);
    expect(resp.status()).toBe(200);
    const text = await resp.text();

    // Feed and sitemap links should be present (section heading is locale-dependent)
    expect(text).toContain('feed.xml');
    expect(text).toContain('sitemap.xml');
  });

  test('robots.txt includes llms.txt directive', async ({ request }) => {
    const resp = await request.get(`${BASE}/robots.txt`);
    expect(resp.status()).toBe(200);
    const text = await resp.text();

    expect(text).toContain('llms.txt:');
    const llmsLine = text.split('\n').find(l => l.startsWith('llms.txt:'));
    expect(llmsLine).toBeDefined();
    const llmsUrl = llmsLine.replace('llms.txt:', '').trim();
    expect(llmsUrl).toMatch(/^https?:\/\//);
    expect(llmsUrl).toContain('/llms.txt');
  });
});
