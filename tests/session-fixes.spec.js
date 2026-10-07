// @ts-check
// E2E regression for the 0.7.18.1 / 0.7.19-rc.4 fixes, exercised the way a real
// user hits them. Run via:
//   /tmp/run-e2e.sh tests/session-fixes.spec.js \
//     --config=tests/playwright.config.js --workers=1
//
// Covers: /chi-siamo CMS slug fallback (+ canonical), relaxed login rate limit,
// docroot routing sanity, and the book-detail "Cerca su" / genre-separator UI.

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

test.describe.configure({ mode: 'serial' });

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_HOST = process.env.E2E_DB_HOST || '';

/** Run a SQL statement via the mysql CLI (password passed through an option file). */
function dbQuery(sql) {
  const args = [];
  if (DB_HOST) args.push('-h', DB_HOST);
  else if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  const cnf = path.join(os.tmpdir(), `pk-sessfix-${process.pid}.cnf`);
  const esc = DB_PASS.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  fs.writeFileSync(cnf, `[client]\npassword="${esc}"\n`, { mode: 0o600 });
  try {
    return execFileSync('mysql', [`--defaults-extra-file=${cnf}`, ...args], { encoding: 'utf-8', timeout: 30000 }).trim();
  } finally {
    try { fs.unlinkSync(cnf); } catch {}
  }
}

let bookUrl = null;      // any non-deleted book (resolved in beforeAll)
let genreBookUrl = null;  // a book that actually has a genre breadcrumb

test.beforeAll(async ({ browser }) => {
  // Pick a non-deleted book to exercise the book-detail UI on.
  const page = await browser.newPage();
  try {
    await page.goto(`${BASE}/catalogo`, { waitUntil: 'networkidle', timeout: 30000 });
    const href = await page.evaluate(() => {
      const a = document.querySelector('a[href*="/libro/"]');
      return a ? a.getAttribute('href') : null;
    });
    if (href) bookUrl = href.startsWith('http') ? href : BASE + href;
  } catch { /* fall through to DB lookup */ }
  await page.close();

  // Fallback: the catalog grid is AJAX-rendered, so grab a real book id from
  // the DB and hit /libro/{id} directly (it 301s to the canonical slug URL).
  if (DB_USER && DB_NAME) {
    try {
      if (!bookUrl) {
        const id = dbQuery('SELECT id FROM libri WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        if (id) bookUrl = `${BASE}/libro/${id}`;
      }
      // A book that has a genre, so the breadcrumb (.genre-tags) actually renders.
      const gid = dbQuery('SELECT id FROM libri WHERE deleted_at IS NULL AND genere_id > 0 ORDER BY id LIMIT 1');
      if (gid) genreBookUrl = `${BASE}/libro/${gid}`;
    } catch { /* leave null -> book tests skip */ }
  }
});

// ===========================================================================
// CMS page slug fallback — the /chi-siamo 404 fix
// ===========================================================================
test.describe.serial('CMS /chi-siamo slug resolution', () => {
  // 1. The local install was seeded with the legacy 'about-us' slug while the
  //    IT route resolves 'chi-siamo' — this only returns 200 thanks to the
  //    resilient fallback in CmsController.
  test('chi-siamo resolves via the resilient fallback (about-us seed)', async ({ page }) => {
    const resp = await page.goto(`${BASE}/chi-siamo`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    expect(resp && resp.status()).toBe(200);
    const body = (await page.textContent('body')) || '';
    expect(body).not.toContain('Pagina non trovata');
    expect(body.length).toBeGreaterThan(200);
  });

  // 2. The seeded About content actually renders (not just any 200).
  test('chi-siamo renders the seeded About content', async ({ page }) => {
    await page.goto(`${BASE}/chi-siamo`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    const body = (await page.textContent('body')) || '';
    expect(body).toContain('Benvenuto in Pinakes');
  });

  // 3. The canonical slug path also works: flip the row to 'chi-siamo' and back.
  test('chi-siamo resolves via the canonical slug too', async ({ page }) => {
    test.skip(!DB_USER || !DB_NAME, 'DB creds not configured');
    dbQuery("UPDATE cms_pages SET slug='chi-siamo' WHERE slug='about-us' AND locale='it_IT'");
    try {
      const resp = await page.goto(`${BASE}/chi-siamo`, { waitUntil: 'domcontentloaded', timeout: 30000 });
      expect(resp && resp.status()).toBe(200);
      expect((await page.textContent('body')) || '').toContain('Benvenuto in Pinakes');
    } finally {
      // Restore the legacy seed so test #1 keeps exercising the fallback.
      dbQuery("UPDATE cms_pages SET slug='about-us' WHERE slug='chi-siamo' AND locale='it_IT'");
    }
  });
});

// ===========================================================================
// Login rate limit — relaxed from 5/300 to 15/300
// ===========================================================================
test.describe.serial('Login rate limit', () => {
  // 4. Six+ rapid login attempts must NOT be rate-limited (the old 5/300 cap
  //    blocked the 6th). We POST straight at /accedi; the rate-limit middleware
  //    counts every request before auth, so a 429 would mean the cap tripped.
  test('allows more than 5 login attempts (no 429 at the 6th/7th)', async ({ request }) => {
    // Start from a clean window using PHP's own temp dir (matches RateLimiter).
    try {
      execFileSync('php', ['-r', 'array_map("unlink", glob(sys_get_temp_dir()."/pinakes_ratelimit/*.json"));'], { timeout: 10000 });
    } catch { /* best effort */ }

    const statuses = [];
    for (let i = 0; i < 7; i++) {
      const r = await request.post(`${BASE}/accedi`, {
        form: { email: `nobody+${i}@local.test`, password: 'definitely-wrong' },
        failOnStatusCode: false,
        maxRedirects: 0,
      });
      statuses.push(r.status());
    }
    // None of the 7 attempts may be 429 Too Many Requests.
    expect(statuses.filter((s) => s === 429)).toHaveLength(0);
    // And specifically the 6th attempt (old limit + 1) is allowed.
    expect(statuses[5]).not.toBe(429);
  });
});

// ===========================================================================
// Routing sanity — the .htaccess self-heal keeps deep routes working
// ===========================================================================
test.describe.serial('App routing', () => {
  // 5. A deep route (not just "/") resolves — this is what broke when the root
  //    .htaccess was missing on docroot=project-root installs.
  test('deep route /catalogo returns 200', async ({ page }) => {
    const resp = await page.goto(`${BASE}/catalogo`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    expect(resp && resp.status()).toBe(200);
    expect((await page.textContent('body')) || '').not.toContain('Pagina non trovata');
  });
});

// ===========================================================================
// Book detail UI — goodlib "Cerca su" + genre separators
// ===========================================================================
test.describe.serial('Book detail UI', () => {
  // 6. The goodlib external-search block is present on the book page.
  test('goodlib "Cerca su" block is present', async ({ page }) => {
    test.skip(!bookUrl, 'no catalog book available');
    await page.goto(bookUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await expect(page.locator('text=Cerca su:').first()).toBeVisible();
  });

  // 7. The "Cerca su" block sits on its own row. It used to be a flex child of
  //    #book-action-buttons forced to flex-basis:100%; the 2026 book page shows
  //    it under the quick facts, in the digital-content column. Either way the
  //    invariant is the same: full width, nothing else on its line.
  test('"Cerca su" wrapper sits on its own row', async ({ page }) => {
    test.skip(!bookUrl, 'no catalog book available');
    await page.goto(bookUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
    const row = await page.evaluate(() => {
      const label = Array.from(document.querySelectorAll('main *'))
        .find((el) => /Cerca su:/.test(el.textContent || '') && el.children.length <= 3);
      if (!label) return null;
      const wrap = label.closest('.plugin-source-search') || label;
      const parent = wrap.parentElement;
      const box = wrap.getBoundingClientRect();
      const shared = Array.from(parent.children).filter((sib) => {
        if (sib === wrap) return false;
        const r = sib.getBoundingClientRect();
        return r.height > 0 && r.top < box.bottom - 1 && r.bottom > box.top + 1;
      }).length;
      return { width: box.width, parentWidth: parent.getBoundingClientRect().width, shared };
    });
    // Test 6 already requires the block on this page, so its absence is a
    // failure here too, not a reason to skip.
    expect(row, '"Cerca su" block present on the book page').not.toBeNull();
    if (row === null) return;
    expect(row.width).toBeGreaterThanOrEqual(row.parentWidth - 1);
    expect(row.shared).toBe(0);
  });

  // 8. Genre breadcrumb pills are vertically centered with their separators.
  test('genre tags are vertically centered (align-items:center)', async ({ page }) => {
    test.skip(!genreBookUrl, 'no book with a genre breadcrumb available');
    await page.goto(genreBookUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
    const tags = page.locator('.genre-tags').first();
    await expect(tags).toBeVisible();
    const align = await tags.evaluate((el) => getComputedStyle(el).alignItems);
    expect(align).toBe('center');
  });

  // 9. In "Dettagli libro" the genre path is the first row of the details
  //    grid, at full width, and wraps between levels, never inside a name.
  test('details genre path is the first full row and wraps only between levels', async ({ page }) => {
    test.skip(!genreBookUrl, 'no book with a genre breadcrumb available');
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(genreBookUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
    const item = page.locator('.book-details-section .meta-item--genre');
    await expect(item).toHaveCount(1);
    const r = await item.evaluate((el) => {
      const grid = el.closest('.details-grid').getBoundingClientRect();
      const box = el.getBoundingClientRect();
      const links = Array.from(el.querySelectorAll('.genre-path a'));
      const firstTop = Math.min(...Array.from(el.closest('.details-grid').querySelectorAll('.meta-item')).map((i) => i.getBoundingClientRect().top));
      return { width: box.width, gridWidth: grid.width, first: Math.abs(box.top - firstTop) < 1, links: links.length, broken: links.filter((a) => a.getClientRects().length > 1).length };
    });
    expect(r.links).toBeGreaterThan(0);
    expect(r.width).toBeGreaterThanOrEqual(r.gridWidth - 1);
    expect(r.broken).toBe(0);
    expect(r.first).toBe(true);
  });
});
