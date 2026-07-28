// @ts-check
// Issue #299 — the WYSIWYG editors double-prefixed URLs. TinyMCE's convert_urls
// (on by default) resolved a placeholder href like {{login_url}} against the
// admin page's base, so it was saved as https://host/admin/{{login_url}} and
// the rendered email link came out double-prefixed. The fix sets
// convert_urls:false on EVERY TinyMCE instance so the editor never rewrites URLs.
//
// This drives a real browser: for each editor, it feeds an href with a
// placeholder through TinyMCE and asserts the round-tripped content keeps the
// placeholder verbatim with NO /admin/ prefix — the behaviour that was broken.
//
// Run: /tmp/run-e2e.sh tests/tinymce-url-prefix-299.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'E2E credentials not configured');

function db(sql) {
  const args = [];
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}
function sqlq(s) { return "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'"; }

async function login(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForFunction(
    () => !location.pathname.includes('accedi') && !location.pathname.includes('login'),
    { timeout: 15000 },
  );
}

// Each editor: the page URL and how to grab its TinyMCE instance. The email
// editor uses a class (multiple instances); the rest use a stable id. Selection
// is a plain if/else inside the page callback — no eval, no new Function.
const CASES = [
  { name: 'email template (settings/index)', url: '/admin/settings?tab=templates', kind: 'class', sel: 'tinymce-editor' },
  { name: 'book description (book_form)',     url: '/admin/books/create',           kind: 'id',    sel: 'descrizione' },
  { name: 'event content (events/form)',      url: '/admin/cms/events/create',      kind: 'id',    sel: 'event_content' },
  { name: 'home text (cms/edit-home)',        url: '/admin/cms/home',               kind: 'id',    sel: 'text_content_body' },
];

test.describe.serial('#299 TinyMCE does not prefix URLs (convert_urls:false)', () => {
  for (const c of CASES) {
    test(c.name, async ({ page }) => {
      await login(page);
      await page.goto(`${BASE}${c.url}`);

      // Wait until the target editor exists and is initialised (this is the
      // real readiness signal — no networkidle, which is flaky on pages that
      // poll or hold connections open). For the
      // class-based email editor, resolve the textarea in the DOM and look the
      // editor up by the id TinyMCE assigns it (avoids relying on tm.editors).
      await page.waitForFunction(
        ({ kind, sel }) => {
          const tm = window.tinymce;
          if (!tm) return false;
          let ed = null;
          if (kind === 'class') {
            const ta = document.querySelector('textarea.' + sel);
            ed = ta && ta.id ? tm.get(ta.id) : null;
          } else {
            ed = tm.get(sel);
          }
          return !!ed && ed.initialized === true;
        },
        { kind: c.kind, sel: c.sel },
        { timeout: 20000 },
      );

      const result = await page.evaluate(({ kind, sel }) => {
        const tm = window.tinymce;
        let ed = null;
        if (kind === 'class') {
          const ta = document.querySelector('textarea.' + sel);
          ed = ta && ta.id ? tm.get(ta.id) : null;
        } else {
          ed = tm.get(sel);
        }
        ed.setContent('<p><a href="{{login_url}}">x</a></p>');
        return { convertUrls: ed.options.get('convert_urls'), content: ed.getContent() };
      }, { kind: c.kind, sel: c.sel });

      // The knob is off…
      expect(result.convertUrls, `${c.name}: convert_urls must be false`).toBe(false);
      // …and behaviourally: the placeholder survives untouched, no /admin/ prefix,
      // no URL-encoded braces (%7B%7B) — both symptoms of a rewrite.
      expect(result.content, `${c.name}: placeholder kept verbatim`).toContain('{{login_url}}');
      expect(result.content, `${c.name}: no /admin/ prefix injected`).not.toMatch(/\/admin\/\{\{|\/admin\/%7B/);
      expect(result.content, `${c.name}: braces not URL-encoded`).not.toContain('%7B%7B');
    });
  }
});

// The DB-repair half of the fix: an install whose template was ALREADY saved
// corrupted must be healed. SettingsController runs healCorruptedTemplateUrls()
// when the settings page is rendered, so opening it repairs the stored value.
test.describe('#299 stored templates are healed on settings open', () => {
  test('a template corrupted with an /admin/ prefix is repaired in the DB', async ({ page }) => {
    test.skip(!DB_USER || !DB_NAME, 'DB not configured');
    const NAME = '__heal299_test__';
    const LOCALE = 'it_IT';
    const corrupted = '<p><a href="http://localhost:8081/admin/{{login_url}}">Accedi</a></p>';
    db(`INSERT INTO email_templates (name, locale, subject, body, active)
        VALUES (${sqlq(NAME)}, ${sqlq(LOCALE)}, 'Heal test', ${sqlq(corrupted)}, 1)
        ON DUPLICATE KEY UPDATE body=VALUES(body), active=1`);
    try {
      // Sanity: the corrupt prefix is really in the DB before we open settings.
      expect(db(`SELECT body FROM email_templates WHERE name=${sqlq(NAME)} AND locale=${sqlq(LOCALE)}`))
        .toContain('/admin/{{login_url}}');

      await login(page);
      // Rendering the settings page runs the server-side heal.
      await page.goto(`${BASE}/admin/settings?tab=templates`);
      await page.waitForLoadState('domcontentloaded');

      const body = db(`SELECT body FROM email_templates WHERE name=${sqlq(NAME)} AND locale=${sqlq(LOCALE)}`);
      expect(body, 'placeholder is preserved').toContain('{{login_url}}');
      expect(body, 'admin prefix stripped from the stored value').not.toContain('/admin/{{login_url}}');
    } finally {
      db(`DELETE FROM email_templates WHERE name=${sqlq(NAME)} AND locale=${sqlq(LOCALE)}`);
    }
  });
});
