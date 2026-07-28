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

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'E2E credentials not configured');

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
      await page.waitForLoadState('networkidle');

      // Wait until the target editor exists and is initialised. For the
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
