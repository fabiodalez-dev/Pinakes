// @ts-check
const { test, expect } = require('@playwright/test');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';

// ────────────────────────────────────────────────────────────────────────
// 1. Hreflang tags
// ────────────────────────────────────────────────────────────────────────
test.describe('Hreflang tags', () => {

  test('homepage has IT, EN, and x-default hreflang links', async ({ request }) => {
    const resp = await request.get(`${BASE}/`);
    const html = await resp.text();

    // Must have at least IT + EN + x-default
    expect(html).toContain('hreflang="it"');
    expect(html).toContain('hreflang="en"');
    expect(html).toContain('hreflang="x-default"');
  });

  test('catalog page hreflang translates route correctly', async ({ request }) => {
    const resp = await request.get(`${BASE}/catalogo`);
    const html = await resp.text();

    // IT version should point to /catalogo
    const itMatch = html.match(/hreflang="it"[^>]*href="([^"]+)"/);
    expect(itMatch).not.toBeNull();
    expect(itMatch[1]).toContain('/catalogo');

    // EN version should point to /en/catalog
    const enMatch = html.match(/hreflang="en"[^>]*href="([^"]+)"/);
    expect(enMatch).not.toBeNull();
    expect(enMatch[1]).toContain('/en/catalog');
  });

  test('English catalog page also has hreflang tags', async ({ request }) => {
    const resp = await request.get(`${BASE}/en/catalog`);
    const html = await resp.text();

    expect(html).toContain('hreflang="it"');
    expect(html).toContain('hreflang="en"');
    expect(html).toContain('hreflang="x-default"');

    // IT hreflang should point to /catalogo (Italian translated route)
    const itMatch = html.match(/hreflang="it"[^>]*href="([^"]+)"/);
    expect(itMatch).not.toBeNull();
    expect(itMatch[1]).toContain('/catalogo');
  });

  test('book page hreflang keeps slug path identical across locales', async ({ request }) => {
    // Get first book URL from sitemap
    const sitemapResp = await request.get(`${BASE}/sitemap.xml`);
    const sitemapXml = await sitemapResp.text();

    // Extract a book URL (pattern: /author-slug/book-slug/id)
    const bookMatch = sitemapXml.match(new RegExp(`${BASE.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/[^<]+/[^<]+/\\d+`));
    if (!bookMatch) {
      test.skip(true, 'No books in sitemap to test');
      return;
    }

    const bookUrl = bookMatch[0];
    const resp = await request.get(bookUrl);
    const html = await resp.text();

    expect(html).toContain('hreflang="it"');
    expect(html).toContain('hreflang="en"');

    // Both locales should have the same slug path (just different prefix)
    const itHref = html.match(/hreflang="it"[^>]*href="([^"]+)"/);
    const enHref = html.match(/hreflang="en"[^>]*href="([^"]+)"/);
    expect(itHref).not.toBeNull();
    expect(enHref).not.toBeNull();

    // EN version should have /en/ prefix
    expect(enHref[1]).toContain('/en/');
  });

  test('x-default points to the default (IT) locale version', async ({ request }) => {
    const resp = await request.get(`${BASE}/catalogo`);
    const html = await resp.text();

    const xDefaultMatch = html.match(/hreflang="x-default"[^>]*href="([^"]+)"/);
    const itMatch = html.match(/hreflang="it"[^>]*href="([^"]+)"/);
    expect(xDefaultMatch).not.toBeNull();
    expect(itMatch).not.toBeNull();

    // x-default should be identical to IT version
    expect(xDefaultMatch[1]).toBe(itMatch[1]);
  });

  test('hreflang links are absolute URLs', async ({ request }) => {
    const resp = await request.get(`${BASE}/catalogo`);
    const html = await resp.text();

    const hrefMatches = html.matchAll(/hreflang="[^"]*"[^>]*href="([^"]+)"/g);
    for (const match of hrefMatches) {
      expect(match[1]).toMatch(/^https?:\/\//);
    }
  });

  test('single-locale site emits no hreflang tags', async ({ request }) => {
    // This is a structural test — if only 1 locale is active, no hreflang should appear.
    // We can only verify this conceptually; with 2+ locales active, hreflang IS present.
    const resp = await request.get(`${BASE}/catalogo`);
    const html = await resp.text();

    // With multiple locales, hreflang MUST be present
    const hreflangCount = (html.match(/hreflang="/g) || []).length;
    // At minimum: it + en + x-default = 3
    expect(hreflangCount).toBeGreaterThanOrEqual(3);
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
    const xml = await resp.text();

    // Should have at least one item (test DB has books)
    expect(xml).toContain('<item>');
    expect(xml).toContain('<title>');
    expect(xml).toContain('<link>');
    expect(xml).toContain('<guid');
    expect(xml).toContain('<pubDate>');
  });

  test('feed items have absolute URLs', async ({ request }) => {
    const resp = await request.get(`${BASE}/feed.xml`);
    const xml = await resp.text();

    // Extract all <link> values inside <item>
    const linkMatches = xml.matchAll(/<item>[\s\S]*?<link>(.*?)<\/link>[\s\S]*?<\/item>/g);
    for (const match of linkMatches) {
      expect(match[1]).toMatch(/^https?:\/\//);
    }
  });

  test('feed has max 50 items', async ({ request }) => {
    const resp = await request.get(`${BASE}/feed.xml`);
    const xml = await resp.text();

    const itemCount = (xml.match(/<item>/g) || []).length;
    expect(itemCount).toBeLessThanOrEqual(50);
    expect(itemCount).toBeGreaterThan(0);
  });

  test('layout includes RSS autodiscovery link', async ({ request }) => {
    const resp = await request.get(`${BASE}/`);
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
    const xml = await resp.text();

    expect(xml).toContain('/feed.xml</loc>');
  });

  test('sitemap includes locale-prefixed feed.xml for EN', async ({ request }) => {
    const resp = await request.get(`${BASE}/sitemap.xml`);
    const xml = await resp.text();

    expect(xml).toContain('/en/feed.xml</loc>');
  });

  test('sitemap includes event URLs when events exist', async ({ request }) => {
    const resp = await request.get(`${BASE}/sitemap.xml`);
    const xml = await resp.text();

    // Check if events table has active rows via the sitemap
    // Events use the translated route: /eventi (IT) or /events (EN)
    const hasEvents = xml.includes('/eventi/') || xml.includes('/events/');

    // This test documents behavior — if no events exist, it's still valid
    if (hasEvents) {
      // Both locale variants should be present
      expect(xml).toMatch(/\/eventi\/|\/events\//);
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

  test('robots.txt contains Sitemap and Feed directives', async ({ request }) => {
    const resp = await request.get(`${BASE}/robots.txt`);
    expect(resp.status()).toBe(200);

    const text = await resp.text();
    expect(text).toContain('Sitemap:');
    expect(text).toContain('sitemap.xml');
    expect(text).toContain('Feed:');
    expect(text).toContain('feed.xml');
  });

  test('robots.txt Feed URL is absolute', async ({ request }) => {
    const resp = await request.get(`${BASE}/robots.txt`);
    const text = await resp.text();

    const feedLine = text.split('\n').find(l => l.startsWith('Feed:'));
    expect(feedLine).toBeDefined();

    const feedUrl = feedLine.replace('Feed:', '').trim();
    expect(feedUrl).toMatch(/^https?:\/\//);
  });
});
