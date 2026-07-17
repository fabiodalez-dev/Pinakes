// @ts-check
/**
 * Social links — end-to-end + static hardening contract (26 checks).
 *
 * The security fix lives at the OUTPUT boundary: social URLs pass through
 * HtmlHelper::sanitizePublicHttpUrl() when the footer loads them, so only a
 * clean http(s) URL renders as a link; a javascript:/data:/no-scheme/
 * credentialed/control-char value collapses to no link at all. Because a
 * ConfigStore cache sits between the DB and the render, the E2E group drives
 * the REAL admin settings form (which clears that cache) rather than writing
 * SQL directly — otherwise the page would serve a stale value.
 *
 *   1-14  E2E: set each social through Settings → General, verify the footer.
 *   15-26 Static invariants over the touched files (sanitize usage, escaping,
 *         the 5 sync points, the reworded Updater message, the README links).
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
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const ROOT = path.resolve(__dirname, '..');

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'E2E credentials not configured (set E2E_ADMIN_EMAIL, E2E_ADMIN_PASS, E2E_DB_*)');

function dbQuery(sql) {
  const args = [];
  if (DB_HOST) { args.push('-h', DB_HOST); if (DB_PORT) args.push('-P', DB_PORT); }
  else if (DB_SOCKET) { args.push('-S', DB_SOCKET); }
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, {
    encoding: 'utf-8', timeout: 10000,
    env: { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}

const SOCIALS = [
  { key: 'facebook', icon: 'fa-facebook' },
  { key: 'twitter', icon: 'fa-twitter' },
  { key: 'instagram', icon: 'fa-instagram' },
  { key: 'linkedin', icon: 'fa-linkedin' },
  { key: 'bluesky', icon: 'fa-bluesky' },
  { key: 'telegram', icon: 'fa-telegram' },
];

const read = (rel) => fs.readFileSync(path.join(ROOT, rel), 'utf-8');

test.describe.serial('Social links — E2E + hardening contract (26 checks)', () => {
  /** @type {import('@playwright/test').Page} */
  let admin;
  /** @type {Map<string, string>} setting key -> original HEX(setting_value) */
  let originalSocials;
  let originalSnapshot;

  /** Save one or more social URLs through the real General settings form. */
  async function saveSocials(values) {
    await admin.goto(`${BASE}/admin/settings?tab=general`);
    for (const s of SOCIALS) {
      const input = admin.locator(`input[name="social_${s.key}"]`);
      // Force the value even for javascript:/data: schemes that type="url"
      // would drop, so the SERVER contract (not the browser) is under test.
      const v = values[s.key] ?? '';
      await input.evaluate((el, val) => { el.value = val; }, v);
    }
    await Promise.all([
      admin.waitForLoadState('domcontentloaded'),
      admin.locator('form[action$="/admin/settings/general"] button[type="submit"]').first().click(),
    ]);
  }

  /** Return the footer href for a social icon on the public homepage, or null. */
  async function footerHref(page, icon) {
    await page.goto(`${BASE}/`);
    const link = page.locator(`footer a:has(i.${icon}), a:has(i.${icon})`).first();
    if (await link.count() === 0) return null;
    return await link.getAttribute('href');
  }

  test.beforeAll(async ({ browser }) => {
    originalSocials = new Map();
    const keys = SOCIALS.map((s) => `'social_${s.key}'`).join(',');
    originalSnapshot = dbQuery(
      `SELECT setting_key, HEX(setting_value) FROM system_settings WHERE category='app' AND setting_key IN (${keys}) ORDER BY setting_key`
    );
    for (const row of originalSnapshot ? originalSnapshot.split('\n') : []) {
      const [key, hex = ''] = row.split('\t');
      originalSocials.set(key, hex);
    }

    const ctx = await browser.newContext();
    admin = await ctx.newPage();
    await admin.goto(`${BASE}/login`);
    await admin.fill('input[name="email"]', ADMIN_EMAIL);
    await admin.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
      admin.waitForURL(/\/admin\//, { timeout: 15000 }),
      admin.click('button[type="submit"]'),
    ]);
  });

  test.afterAll(async () => {
    // Restore the exact pre-suite state. Drive the real form first so its
    // ConfigStore invalidation path runs, then correct row presence/value in
    // SQL (the form necessarily creates empty rows for originally absent keys).
    const originalValues = {};
    for (const s of SOCIALS) {
      const hex = originalSocials.get(`social_${s.key}`);
      originalValues[s.key] = hex === undefined ? '' : Buffer.from(hex, 'hex').toString('utf8');
    }
    try { await saveSocials(originalValues); } catch { /* SQL restore below is authoritative */ }
    const keys = SOCIALS.map((s) => `'social_${s.key}'`).join(',');
    dbQuery(`DELETE FROM system_settings WHERE category='app' AND setting_key IN (${keys})`);
    for (const [key, hex] of originalSocials) {
      dbQuery(
        `INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('app', '${key}', UNHEX('${hex}')) ` +
        'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'
      );
    }
    const restoredSnapshot = dbQuery(
      `SELECT setting_key, HEX(setting_value) FROM system_settings WHERE category='app' AND setting_key IN (${keys}) ORDER BY setting_key`
    );
    expect(restoredSnapshot).toBe(originalSnapshot);
    await admin?.context()?.close();
  });

  // ── 1-5: each valid https URL renders as the footer link ───────────────────
  for (let i = 0; i < SOCIALS.length; i++) {
    const s = SOCIALS[i];
    test(`${i + 1}. valid https ${s.key} renders in the footer`, async ({ page }) => {
      const url = `https://example.com/${s.key}-zz`;
      await saveSocials({ [s.key]: url });
      expect(await footerHref(page, s.icon)).toBe(url);
    });
  }

  // ── 6: all five at once ─────────────────────────────────────────────────────
  test('6. all six socials set at once each render their link', async ({ page }) => {
    const values = Object.fromEntries(SOCIALS.map((s) => [s.key, `https://example.com/all-${s.key}`]));
    await saveSocials(values);
    await page.goto(`${BASE}/`);
    for (const s of SOCIALS) {
      await expect(page.locator(`a:has(i.${s.icon})`).first()).toHaveAttribute('href', `https://example.com/all-${s.key}`);
    }
  });

  // ── 7-9, 14: malicious/invalid schemes never render a link ──────────────────
  const badValues = [
    ['7. a javascript: URL renders no link', 'javascript:alert(document.cookie)'],
    ['8. a data: URL renders no link', 'data:text/html,<script>alert(1)</script>'],
    ['9. a scheme-less value renders no link', 'example.com/no-scheme'],
    ['14. a control-character URL renders no link', 'https://exa\tmple.com/x'],
  ];
  for (const [title, bad] of badValues) {
    test(String(title), async ({ page }) => {
      await saveSocials({ facebook: bad });
      expect(await footerHref(page, 'fa-facebook')).toBeNull();
      const html = await (await page.goto(`${BASE}/`))?.text() ?? '';
      expect(html).not.toContain('javascript:alert');
      expect(html).not.toContain('data:text/html');
    });
  }

  // ── 10: credentials in the URL are rejected ─────────────────────────────────
  test('10. a URL with embedded credentials renders no link', async ({ page }) => {
    await saveSocials({ facebook: 'https://user:pass@example.com/x' });
    expect(await footerHref(page, 'fa-facebook')).toBeNull();
  });

  // ── 11: clearing a social removes its link ──────────────────────────────────
  test('11. clearing a social removes the footer link', async ({ page }) => {
    await saveSocials({ facebook: 'https://example.com/keep' });
    expect(await footerHref(page, 'fa-facebook')).toBe('https://example.com/keep');
    await saveSocials({ facebook: '' });
    expect(await footerHref(page, 'fa-facebook')).toBeNull();
  });

  // ── 12: the href is HTML-escaped (attribute can't be broken out of) ─────────
  test('12. a URL with special chars is escaped in the footer href attribute', async ({ page }) => {
    const url = 'https://example.com/x?a=1&b=2';
    await saveSocials({ facebook: url });
    await page.goto(`${BASE}/`);
    // getAttribute returns the DECODED value; the footer link's raw HTML must
    // carry &amp; (scoped to the anchor — the JSON-LD sameAs legitimately
    // json_encodes the same URL with a raw & inside its JSON string).
    expect(await footerHref(page, 'fa-facebook')).toBe(url);
    const anchorHtml = await page.locator('a:has(i.fa-facebook)').first().evaluate((el) => el.outerHTML);
    expect(anchorHtml).toContain('a=1&amp;b=2');
    expect(anchorHtml).not.toContain('a=1&b=2');
  });

  // ── 13: the logged-in (user_layout) footer sanitizes too ────────────────────
  test('13. the logged-in footer also drops a javascript: social link', async () => {
    await saveSocials({ facebook: 'javascript:alert(1)' });
    // The admin session renders user_layout on account pages.
    await admin.goto(`${BASE}/`);
    const html = await admin.content();
    expect(html).not.toContain('javascript:alert');
  });

  // ── 15-26: static invariants over the touched files ─────────────────────────
  test('15. frontend/layout.php sanitizes all six socials at load', async () => {
    const src = read('app/Views/frontend/layout.php');
    for (const s of SOCIALS) {
      expect(src).toContain(`$social${cap(s.key)} = HtmlHelper::sanitizePublicHttpUrl((string) ConfigStore::get('app.social_${s.key}'`);
    }
  });

  test('16. frontend/layout.php has no HtmlHelper::e on a social href', async () => {
    const src = read('app/Views/frontend/layout.php');
    expect(src).not.toMatch(/HtmlHelper::e\(\$social/);
  });

  test('17. frontend/layout.php escapes social hrefs with htmlspecialchars', async () => {
    const src = read('app/Views/frontend/layout.php');
    const count = (src.match(/href="<\?= htmlspecialchars\(\$social\w+, ENT_QUOTES, 'UTF-8'\) \?>"/g) || []).length;
    expect(count).toBe(6);
  });

  test('18. user_layout.php sanitizes all six socials at load', async () => {
    const src = read('app/Views/user_layout.php');
    for (const s of SOCIALS) {
      expect(src).toContain(`sanitizePublicHttpUrl((string) ConfigStore::get('app.social_${s.key}'`);
    }
  });

  test('19. user_layout.php escapes social hrefs and drops HtmlHelper::e', async () => {
    const src = read('app/Views/user_layout.php');
    expect(src).not.toMatch(/HtmlHelper::e\(\$social/);
    expect((src.match(/htmlspecialchars\(\$social\w+, ENT_QUOTES, 'UTF-8'\)/g) || []).length).toBe(6);
  });

  test('20. SettingsController saves all six socials', async () => {
    const src = read('app/Controllers/SettingsController.php');
    for (const s of SOCIALS) expect(src).toContain(`'social_${s.key}' => trim(`);
  });

  test('21. ConfigStore declares all five social defaults', async () => {
    const src = read('app/Support/ConfigStore.php');
    for (const s of SOCIALS) expect(src).toContain(`'social_${s.key}' => ''`);
  });

  test('22. ConfigStore whitelists all six socials in the DB→runtime mapping', async () => {
    const src = read('app/Support/ConfigStore.php');
    const m = src.match(/\$socialKeys\s*=\s*\[([^\]]+)\]/);
    expect(m).not.toBeNull();
    for (const s of SOCIALS) expect(m[1]).toContain(`'social_${s.key}'`);
  });

  test('23. FrontendController sanitizes and adds all six socials to Schema.org sameAs', async () => {
    const src = read('app/Controllers/FrontendController.php');
    for (const s of SOCIALS) {
      const variable = `$social${cap(s.key)}`;
      expect(src).toContain(`${variable} = \\App\\Support\\HtmlHelper::sanitizePublicHttpUrl((string) \\App\\Support\\ConfigStore::get('app.social_${s.key}'`);
      expect(src).toContain(`if (${variable}) $sameAs[] = ${variable};`);
    }
  });

  test('24. the reworded Docker updater message exists in all four locales', async () => {
    // The i18n KEY is the full Italian source string in every locale; match on
    // its distinctive opening rather than the whole sentence.
    const marker = "I file dell'applicazione non sono scrivibili dall'utente del web server e Pinakes gira dentro un container.";
    for (const loc of ['it_IT', 'en_US', 'de_DE', 'fr_FR']) {
      const d = JSON.parse(read(`locale/${loc}.json`));
      const key = Object.keys(d).find((k) => k.includes(marker));
      expect(key, `locale ${loc} is missing the reworded Docker message`).toBeTruthy();
      expect(String(d[key]).length).toBeGreaterThan(20);
    }
  });

  test('25. the old presumptuous Docker message is gone from all four locales', async () => {
    const old = "Sembra che tu stia eseguendo l'immagine Docker";
    for (const loc of ['it_IT', 'en_US', 'de_DE', 'fr_FR']) {
      const d = JSON.parse(read(`locale/${loc}.json`));
      expect(Object.keys(d).some((k) => k.includes(old))).toBe(false);
    }
  });

  test('26. README links the official Docker image (repo + Docker Hub)', async () => {
    const readme = read('README.md');
    expect(readme).toContain('fabiodalez/pinakes');
    expect(readme).toContain('hub.docker.com/r/fabiodalez/pinakes');
    expect(readme).toContain('github.com/fabiodalez-dev/pinakes-docker');
  });
});

function cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); }
