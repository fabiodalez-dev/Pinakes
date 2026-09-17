// @ts-check
/**
 * The browser half of the desiderata test plan: T1, T5, T7, T8, T9, T13, T14,
 * T15, T20. The PHP half lives in tests/desiderata-extended.integration.php,
 * tests/desiderata-recaptcha.unit.php and tests/desiderata-core-hooks.unit.php.
 *
 * Run: /tmp/run-e2e.sh tests/desiderata-extended.spec.js --config=tests/playwright.config.js --workers=1
 *
 * Three rules this file is built around:
 *
 * 1. It never skips. A suite that exits 0 without running is the failure mode
 *    this project has actually been bitten by, so there is no readiness flag:
 *    a missing precondition throws out of beforeAll (or, for credentials,
 *    out of module load under CI_STRICT_TESTS) and every test goes red.
 * 2. Deactivation goes through /admin/plugins, never through SQL. Flipping
 *    plugins.is_active leaves the plugin_hooks rows in place, so a "plugin off"
 *    test would pass while the hooks kept firing — see the comment at the top
 *    of tests/helpers/plugin-activation.js.
 * 3. The dev installation is left exactly as it was found. T1, T5, T8 and T15
 *    all disturb it; afterAll puts it back through the same admin endpoints an
 *    operator would use and then ASSERTS the restoration, so a half-finished
 *    cleanup fails the run instead of being inherited by the next one.
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { ensurePluginActive, dbQuery } = require('./helpers/plugin-activation');

const root = path.resolve(__dirname, '..');
const envFile = path.join(__dirname, '.env.test');
const settings = Object.fromEntries(
  (fs.existsSync(envFile) ? fs.readFileSync(envFile, 'utf8') : '')
    .split(/\r?\n/)
    .filter(line => line.includes('=') && !line.startsWith('#'))
    .map(line => { const i = line.indexOf('='); return [line.slice(0, i), line.slice(i + 1).trim().replace(/^["']|["']$/g, '')]; }),
);
const BASE_URL = process.env.E2E_BASE_URL || process.env.APP_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || settings.E2E_ADMIN_EMAIL;
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || settings.E2E_ADMIN_PASS;
const STRICT = process.env.CI_STRICT_TESTS === '1';

const tag = crypto.randomBytes(6).toString('hex');
const fixture = (action, ...rest) => JSON.parse(execFileSync(
  'php',
  [path.join(__dirname, 'helpers/desiderata-fixture.php'), action, tag, ...rest],
  { cwd: root, encoding: 'utf8' },
));

// No test.skip(): under the strict gate a missing credential must stop the run
// with its own message, not hide nine tests behind a green tick.
if (!ADMIN_EMAIL || !ADMIN_PASS) {
  if (STRICT) {
    throw new Error('desiderata-extended.spec.js needs E2E_ADMIN_EMAIL/E2E_ADMIN_PASS (use /tmp/run-e2e.sh)');
  }
  test.skip(true, 'Requires an installed app and E2E_ADMIN_EMAIL/E2E_ADMIN_PASS');
}

test.describe.configure({ mode: 'serial' });

/** @type {any} */ let seeded;
/** @type {any} */ let baseline;
/** @type {number} */ let pluginId = 0;

async function login(page) {
  await page.goto('/accedi');
  await page.getByRole('textbox', { name: 'Email', exact: true }).fill(ADMIN_EMAIL);
  await page.getByRole('textbox', { name: 'Password', exact: true }).fill(ADMIN_PASS);
  await page.getByRole('button', { name: 'Accedi', exact: true }).click();
  await page.waitForURL(/\/admin/);
}

/** An admin context of its own, closed whatever happens. */
async function asAdmin(browser, fn) {
  const context = await browser.newContext();
  try {
    const page = await context.newPage();
    await login(page);
    return await fn(page);
  } finally {
    await context.close();
  }
}

/**
 * A JSON POST from inside the logged-in page, with a token fetched the way the
 * CMS page's own scripts fetch it. Used for the two AJAX endpoints that back
 * the sortable list, so the test drives exactly what an operator's drag does.
 */
async function postJson(page, url, body) {
  const result = await page.evaluate(async ({ url, body }) => {
    const token = await (await fetch('/csrf-token', { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json();
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token.token },
      body: JSON.stringify(body),
    });
    let parsed = {};
    try { parsed = await response.json(); } catch { /* asserted by the caller */ }
    return { status: response.status, body: parsed };
  }, { url, body });
  expect(result.status, `${url} HTTP status`).toBe(200);
  expect(result.body.success, `${url} refused: ${result.body.message || '(no message)'}`).toBe(true);
  return result.body;
}

/** Deactivation through the real admin endpoint — the only kind that counts. */
async function deactivatePlugin(page, id) {
  await page.goto('/admin/plugins');
  await page.waitForLoadState('domcontentloaded');
  const result = await postJson(page, `/admin/plugins/${id}/deactivate`, {});
  expect(dbQuery(`SELECT is_active FROM plugins WHERE id=${id}`), 'plugin must be inactive after deactivation').toBe('0');
  const hooks = dbQuery(`SELECT COUNT(*) FROM plugin_hooks WHERE plugin_id=${id}`);
  // The point of going through the endpoint: the hook rows have to be gone, or
  // every "plugin off" assertion below would be testing the plugin switched on.
  expect(Number(hooks), 'deactivation must remove the plugin_hooks rows').toBe(0);
  return result;
}

/** Fill the public donation form. `title` is skipped when it is bound and read-only. */
async function fillDonation(page, { name, email, title }) {
  await page.locator('#donation-donor_name').fill(name);
  await page.locator('#donation-donor_email').fill(email);
  if (title !== undefined) await page.locator('#donation-title').fill(title);
  await page.locator('#desiderata-offer input[name="consent"]').check();
}

test.beforeAll(async ({ browser }) => {
  await asAdmin(browser, async page => {
    pluginId = await ensurePluginActive(page, 'desiderata', { smokePath: '/desiderata' });
  });
  seeded = fixture('seed-extended');
  baseline = fixture('state-extended');
  expect(Number(baseline.plugin.is_active), 'the plugin must be active before the run').toBe(1);
  expect(baseline.home, 'the plugin must own a home_content row before the run').toBeTruthy();
  expect(Object.keys(seeded.books), 'seeded books').toHaveLength(6);
});

test.afterAll(async ({ browser }) => {
  if (!seeded) return;
  await asAdmin(browser, async page => {
    const id = await ensurePluginActive(page, 'desiderata', { smokePath: '/desiderata' });
    const current = fixture('state-extended');
    if (current.home) {
      // Through the endpoints, not through SQL: the same write path also bumps
      // the home cache, so the restored order is what the next visitor sees.
      await postJson(page, '/admin/cms/home/reorder', { order: [{ id: Number(current.home.id), display_order: Number(baseline.home.display_order) }] });
      await postJson(page, '/admin/cms/home/toggle-visibility', { section_id: Number(current.home.id), is_active: Number(baseline.home.is_active) });
    }
    expect(id, 'the plugin id must not change').toBe(pluginId);
  });
  const cleanup = fixture('cleanup-extended');
  // Not decoration: a section restored here means submitting the CMS home form
  // rewrote a section this suite never edited, which is a finding.
  expect(cleanup.restored, 'the CMS save must not have changed any other home section').toEqual([]);

  const final = fixture('state-extended');
  expect(final.books, 'every seeded book must be gone').toEqual([]);
  expect(final.offers, 'every seeded proposal must be gone').toEqual([]);
  expect(final.notifications, 'no admin_notifications debris may be left behind').toEqual([]);
  expect(final.homeTexts, 'home_texts must be back to what it was').toBe(baseline.homeTexts);
  expect(Number(final.plugin.is_active), 'the plugin must be left active').toBe(1);
  expect(final.plugin.version, 'the plugin version must be left as found').toBe(baseline.plugin.version);
  expect(final.hooks, 'the plugin must be left with its full hook set').toBe(baseline.hooks);
  expect(Number(final.home.is_active), 'the home row must be left visible').toBe(Number(baseline.home.is_active));
  expect(Number(final.home.display_order), 'the home row must be left at its position').toBe(Number(baseline.home.display_order));
  expect(final.homeContent, 'every home_content row must be left as found').toEqual(baseline.homeContent);
});

test('T1 — home section obeys display_order and visibility set from /admin/cms/home, immediately', async ({ page, browser }) => {
  const sectionId = Number(baseline.home.id);
  const sectionOrder = () => page.evaluate(() => Array.from(document.querySelectorAll('.hero-section, .desiderata-section'))
    .map(element => (element.classList.contains('desiderata-section') ? 'desiderata' : 'hero')));

  await asAdmin(browser, async admin => {
    // The list an operator drags, and the label the plugin's filter puts on it.
    await admin.goto('/admin/cms/home');
    const row = admin.locator('li.section-item[data-section-key="desiderata"]');
    await expect(row, 'the plugin row must appear in the sortable list').toHaveCount(1);
    await expect(row).toContainText('Desiderata e donazioni');

    await page.goto('/');
    expect(await sectionOrder(), 'the section starts after the hero').toEqual(['hero', 'desiderata']);

    // Order: in front of the hero, which sits at -2.
    await postJson(admin, '/admin/cms/home/reorder', { order: [{ id: sectionId, display_order: -10 }] });
    await page.goto('/');
    // Immediately — no waiting out the 300s home_page_data_v1 TTL. A section
    // that only moves after the cache expires is the exact bug this catches.
    expect(await sectionOrder(), 'the reordered section must render before the hero, at once').toEqual(['desiderata', 'hero']);

    // Visibility off.
    await postJson(admin, '/admin/cms/home/toggle-visibility', { section_id: sectionId, is_active: 0 });
    await page.goto('/');
    await expect(page.locator('.desiderata-section'), 'a hidden section must not render').toHaveCount(0);
    await expect(page.locator('.hero-section'), 'the rest of the homepage must be unaffected').toHaveCount(1);

    // And on again.
    await postJson(admin, '/admin/cms/home/toggle-visibility', { section_id: sectionId, is_active: 1 });
    await page.goto('/');
    await expect(page.locator('.desiderata-section')).toHaveCount(1);

    // Back where the operator had it, verified through the page and not only
    // through the database, so the restoration is proven by what a visitor sees.
    await postJson(admin, '/admin/cms/home/reorder', { order: [{ id: sectionId, display_order: Number(baseline.home.display_order) }] });
    await page.goto('/');
    expect(await sectionOrder(), 'the section must be back after the hero').toEqual(['hero', 'desiderata']);
  });

  const state = fixture('state-extended');
  expect(Number(state.home.display_order)).toBe(Number(baseline.home.display_order));
  expect(Number(state.home.is_active)).toBe(1);
});

test('T5 — CMS editor round trip in the browser', async ({ page, browser }) => {
  const italian = `Libri che cerchiamo ${tag} IT`;
  const english = `Books we are looking for ${tag} EN`;
  const field = locale => `input[name="desiderata[texts][${locale}][title]"]`;

  await asAdmin(browser, async admin => {
    await admin.goto('/admin/cms/home');
    // Only one accordion is open (the admin's own language); the others must be
    // opened before anything can be typed into them.
    const open = async locale => {
      const content = admin.locator(`#desiderata-${locale}-content`);
      await expect(content, `the ${locale} accordion must exist in the card`).toHaveCount(1);
      if (!(await content.isVisible())) {
        await admin.locator(`button[onclick="toggleAccordion('desiderata-${locale}')"]`).click();
        await expect(content).toBeVisible();
      }
    };
    await open('it_IT');
    await admin.locator(field('it_IT')).fill(italian);
    await open('en_US');
    await admin.locator(field('en_US')).fill(english);

    await admin.getByRole('button', { name: 'Salva modifiche Homepage' }).click();
    await admin.waitForLoadState('domcontentloaded');
    await expect(admin.getByText('Contenuti homepage aggiornati con successo!'), 'the page must report the save').toBeVisible();

    await admin.goto('/admin/cms/home');
    await expect(admin.locator(field('it_IT')), 'the Italian override must survive the reload').toHaveValue(italian);
    await expect(admin.locator(field('en_US')), 'the English override must survive the reload').toHaveValue(english);
  });

  // What a visitor gets: the installation locale's override, on the homepage.
  const expected = seeded.installLocale === 'en_US' ? english : italian;
  await page.goto('/');
  await expect(page.locator('#desiderata-heading'), `the ${seeded.installLocale} override must reach the homepage`).toHaveText(expected);

  const state = fixture('state-extended');
  const stored = JSON.parse(state.homeTexts || '{}');
  expect(stored.it_IT.title, 'the Italian text must be stored under its own locale').toBe(italian);
  expect(stored.en_US.title, 'the English text must be stored under its own locale').toBe(english);
});

test('T7 — results show covers on /desiderata and after an AJAX search', async ({ page }) => {
  const withCover = seeded.books.cover;
  const withoutCover = seeded.books.nocover;
  const coverOf = async titolo => {
    const row = page.locator('#desiderata-results li').filter({ hasText: titolo });
    await expect(row, `one row for ${titolo}`).toHaveCount(1);
    return row.locator('img.dw-cover').getAttribute('src');
  };

  await page.goto('/desiderata');
  // Server-rendered rows.
  expect(await coverOf(withCover.titolo), 'the seeded cover, server-side').toBe(seeded.coverPath);
  expect(await coverOf(withoutCover.titolo), 'the placeholder, server-side').toBe(seeded.placeholder);

  // The same rows, rebuilt by the script from /desiderata/search.
  await page.getByRole('searchbox', { name: 'Cerca tra i desiderata', exact: true }).fill(seeded.prefix);
  await expect(page.locator('#desiderata-search-status')).toContainText('Libri trovati:');
  await expect(page.locator('#desiderata-results li'), 'the five seeded requests').toHaveCount(5);
  expect(await coverOf(withCover.titolo), 'the seeded cover, client-side').toBe(seeded.coverPath);
  expect(await coverOf(withoutCover.titolo), 'the placeholder, client-side').toBe(seeded.placeholder);
  // The two renderers must not drift: same URL from the server and the script.
  const serverAndClientAgree = await page.locator('#desiderata-results li img.dw-cover').evaluateAll(images => images.every(image => image.getAttribute('src')));
  expect(serverAndClientAgree, 'every rebuilt row must carry a cover URL').toBe(true);
});

test('T8 — dashboard panels and sidebar count exist only while the plugin is active', async ({ page }) => {
  await login(page);
  const panel = page.locator('#desiderata-dashboard');
  const sidebar = page.locator('a[href$="/admin/desiderata"]').first();

  await page.goto('/admin/dashboard');
  await expect(panel, 'the panels must be there while the plugin is on').toHaveCount(1);
  await expect(panel).toContainText(seeded.books.donate.titolo);
  await expect(sidebar, 'the sidebar entry must be there while the plugin is on').toHaveCount(1);
  const pending = fixture('state-extended').pendingTotal;
  await expect(sidebar, 'the sidebar pill must count the pending proposals').toContainText(String(pending));

  await deactivatePlugin(page, pluginId);

  const dashboard = await page.request.get('/admin/dashboard');
  // 200 and not 500: a hook handler that cannot cope with being switched off
  // takes the whole page down, which is the failure this pins.
  expect(dashboard.status(), 'the dashboard must still answer with the plugin off').toBe(200);
  await page.goto('/admin/dashboard');
  await expect(page.locator('#desiderata-dashboard'), 'no panels with the plugin off').toHaveCount(0);
  await expect(page.locator('a[href$="/admin/desiderata"]'), 'no sidebar entry with the plugin off').toHaveCount(0);
  expect((await page.request.get('/admin/desiderata')).status(), 'the admin route must be gone').toBe(404);

  await ensurePluginActive(page, 'desiderata', { smokePath: '/desiderata' });
  await page.goto('/admin/dashboard');
  await expect(page.locator('#desiderata-dashboard'), 'the panels must come back').toHaveCount(1);
});

test('T9 — mark as donated from the dashboard', async ({ page }) => {
  const book = seeded.books.donate;
  page.on('dialog', dialog => dialog.accept());
  await login(page);
  await page.goto('/admin/dashboard');

  const receiptForm = page.locator(`#desiderata-dashboard form[action$="/admin/desiderata/books/${book.id}/received"]`);
  await expect(receiptForm, 'the wanted book must offer the receipt button').toHaveCount(1);
  await receiptForm.getByRole('button', { name: 'Libro donato: registra la copia' }).click();
  await page.waitForURL(/\/admin\/dashboard/);

  const state = fixture('state-extended');
  const received = state.books.find(row => Number(row.id) === book.id);
  expect(Number(received.physical), 'exactly one physical copy').toBe(1);
  expect(Number(received.is_desiderata), 'the request flag must be cleared').toBe(0);
  const audit = state.offers.filter(offer => Number(offer.book_id) === book.id);
  expect(audit, 'one audit row for the receipt').toHaveLength(1);
  expect(audit[0].status).toBe('received');
  expect(Number(audit[0].copy_id), 'the audit row must name the copy it created').toBeGreaterThan(0);
  expect(audit[0].donor_email, 'nobody left a name at the desk').toBe('');
  const bell = state.notifications.filter(row => String(row.link).includes(`/admin/books/${book.id}`));
  expect(bell, 'the operators must be told, once').toHaveLength(1);
  expect(bell[0].type).toBe('general');

  // Replay with a token that is perfectly valid: the refusal has to come from
  // the row's own state, not from CSRF.
  const token = (await (await page.request.get('/csrf-token')).json()).token;
  const replay = await page.request.post(`/admin/desiderata/books/${book.id}/received`, {
    form: { csrf_token: token, return_to: 'dashboard' },
  });
  expect(replay.status(), 'a replayed receipt must be refused').toBe(422);
  const after = fixture('state-extended');
  expect(Number(after.books.find(row => Number(row.id) === book.id).physical), 'and must not create a second copy').toBe(1);

  await page.goto('/admin/dashboard');
  await expect(page.locator('#desiderata-dashboard').filter({ hasText: book.titolo }), 'the card must be gone').toHaveCount(0);
  const publicSearch = await (await page.request.get(`/desiderata/search?q=${encodeURIComponent(seeded.prefix)}`)).json();
  expect(publicSearch.map(row => row.titolo), 'and the book must leave the public request list').not.toContain(book.titolo);
});

test('T13 — wanted badge in catalogue search, search preview and operator quick-search; hidden from browse and anonymous quick-search', async ({ page, browser }) => {
  const wanted = seeded.books.main;
  const held = seeded.books.held;
  const injected = seeded.books.xss;

  // 1. The catalogue grid, when the visitor asked for a title by name.
  await page.goto(`/catalogo?search=${encodeURIComponent(seeded.prefix)}`);
  const wantedCard = page.locator('.book-card').filter({ hasText: wanted.titolo });
  await expect(wantedCard, 'the wanted book must be findable by search').toHaveCount(1);
  await expect(wantedCard.locator('.dw-wanted-badge'), 'and must be badged').toHaveCount(1);
  const heldCard = page.locator('.book-card').filter({ hasText: held.titolo });
  await expect(heldCard, 'the held book is found too').toHaveCount(1);
  await expect(heldCard.locator('.dw-wanted-badge'), 'and must NOT be badged').toHaveCount(0);

  // 2. Browse is a different question: nothing wanted may pad the grid. The
  // held book proves these rows really do reach page one (sort = newest).
  await page.goto('/catalogo');
  await expect(page.locator('.book-card').filter({ hasText: held.titolo }), 'browse shows the newly held book').toHaveCount(1);
  for (const key of ['main', 'cover', 'nocover', 'xss']) {
    await expect(page.locator('.book-card').filter({ hasText: seeded.books[key].titolo }), `browse must not show ${key}`).toHaveCount(0);
  }

  // 3. The public preview endpoint.
  const preview = await (await page.request.get(`/api/search/preview?q=${encodeURIComponent(seeded.prefix)}`)).json();
  const previewOf = titolo => preview.find(row => row.type === 'book' && row.title === titolo);
  expect(previewOf(wanted.titolo), 'the preview must carry the wanted flag').toMatchObject({ wanted: true });
  expect(previewOf(held.titolo), 'and must not set it on a held book').toMatchObject({ wanted: false });

  // 4. The preview renderer builds its HTML by string concatenation, so a title
  // with markup in it is where that goes wrong. It must arrive as text.
  await page.goto('/');
  // The header form, not the hero one and not its mobile twin: all three carry
  // the class, and only this one is the preview the operator brief names.
  await page.locator('form.search-form input.search-input').first().fill(seeded.prefix);
  const dropdown = page.locator('.search-results.is-visible');
  await expect(dropdown).toBeVisible();
  await expect(dropdown.locator('.search-book-wanted').first(), 'the preview must show the wanted label').toContainText('Cercato dalla biblioteca');
  await expect(dropdown.getByText(injected.titolo, { exact: true }), 'the markup in the title must be text').toHaveCount(1);
  await expect(dropdown.locator('b'), 'and must never become an element').toHaveCount(0);

  // 5. The operator quick-search says it; the anonymous one does not even
  // return the row (SearchController's operator gate is untouched).
  const anonymous = await (await page.request.get(`/api/search/unified?q=${encodeURIComponent(seeded.prefix)}`)).json();
  expect(anonymous.map(row => row.label), 'an anonymous quick-search must not reach a request').not.toContain(wanted.titolo);

  await asAdmin(browser, async admin => {
    const operator = await (await admin.request.get(`/api/search/unified?q=${encodeURIComponent(seeded.prefix)}`)).json();
    const row = operator.find(item => item.type === 'book' && item.label === wanted.titolo);
    expect(row, 'an operator must find the request').toBeTruthy();
    expect(row.wanted, 'flagged as wanted').toBeTruthy();
    await admin.goto('/admin/dashboard');
    await admin.locator('#global-search').fill(seeded.prefix);
    const results = admin.locator('#global-search-results');
    await expect(results.getByText('Cercato dalla biblioteca').first(), 'the quick-search must label it').toBeVisible();
  });
});

test('T14 — wanted book page: reachable, badged, no loan actions, pre-bound donation form that returns to the book', async ({ page, browser }) => {
  const book = seeded.books.main;

  // An open redirect first, in a session of its own: the form lets one visitor
  // send a single proposal a minute, and this test sends two.
  const evilContext = await browser.newContext();
  try {
    const evil = await evilContext.newPage();
    await evil.goto(book.path);
    await evil.locator('#desiderata-offer input[name="return_to"]').evaluate(input => { input.value = '//evil.example/x'; });
    await fillDonation(evil, { name: 'Donatore T14 evil', email: seeded.email });
    const refused = evil.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/desiderata/offers'));
    await evil.getByRole('button', { name: 'Invia la proposta', exact: true }).click();
    const location = (await refused).headers()['location'];
    expect(location, 'an off-site return_to must be ignored, not followed').toBe('/desiderata#donation-form');
    await expect.poll(() => new URL(evil.url()).host, { message: 'and the browser must stay on this host' }).toBe(new URL(BASE_URL).host);
  } finally {
    await evilContext.close();
  }

  const response = await page.goto(book.path);
  expect(response.status(), 'a wanted book must have a reachable page').toBe(200);
  await expect(page.locator('.availability-badge'), 'badged as wanted').toContainText('Cercato dalla biblioteca');
  await expect(page.locator('#book-action-buttons'), 'a book the library does not own cannot be borrowed').toHaveCount(0);
  const form = page.locator('[data-desiderata-form]');
  await expect(form, 'the donation form must be on the book page').toHaveCount(1);
  await expect(page.locator('#donation-book-id'), 'bound to this book').toHaveValue(String(book.id));
  await expect(page.locator('#donation-title'), 'with the title it already knows').toHaveValue(book.titolo);
  expect(await page.locator('#donation-title').getAttribute('readonly'), 'and locked').not.toBeNull();
  await expect(page.locator('#desiderata-offer input[name="return_to"]'), 'coming back here afterwards').toHaveValue(book.path);

  await fillDonation(page, { name: 'Donatore T14', email: seeded.email });
  const accepted = page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/desiderata/offers'));
  await page.getByRole('button', { name: 'Invia la proposta', exact: true }).click();
  const sent = await accepted;
  expect(sent.status(), 'the proposal must be accepted').toBe(303);
  expect(sent.headers()['location'], 'and send the donor back to this book, at the form').toBe(`${book.path}#donation-form`);
  // Polled rather than read once: the fragment lands a beat after the
  // navigation commits, and reading page.url() at that instant misses it.
  await expect.poll(() => page.url(), { message: 'the browser must follow it' }).toBe(`${BASE_URL}${book.path}#donation-form`);
  // The plan also expected the "Grazie!" confirmation here, and it is NOT
  // asserted on purpose: today the book page cannot show it. `views/public.php`
  // reads and clears $_SESSION['desiderata_success'] before it includes the
  // form partial; `views/book-detail.php` (and DesiderataPlugin::bookDetail())
  // never do, so the donor lands back on the book with no acknowledgement at
  // all and the flag stays in the session until their next visit to
  // /desiderata, where a thank-you appears for something they sent elsewhere.
  // Measured, both halves. Asserting either the silence or the stale banner
  // would freeze the defect into the suite, so this test proves the parts that
  // will hold before and after the one-line fix, and the gap is reported.

  const state = fixture('state-extended');
  const mine = state.offers.filter(offer => offer.donor_name === 'Donatore T14');
  expect(mine, 'the proposal must be recorded once').toHaveLength(1);
  expect(Number(mine[0].book_id), 'against the book it was sent from').toBe(book.id);
  expect(mine[0].status).toBe('pending');
});

test('T15 — plugin deactivated: the public web behaves as before the feature existed', async ({ page, browser }) => {
  const book = seeded.books.main;
  const held = seeded.books.held;

  await asAdmin(browser, async admin => {
    await deactivatePlugin(admin, pluginId);
    await admin.goto('/admin/cms/home');
    await expect(admin.locator('li.section-item[data-section-key="desiderata"]'), 'the CMS row belongs to the plugin').toHaveCount(0);
  });

  expect((await page.request.get(book.path)).status(), 'the request has no public page').toBe(404);
  expect((await page.request.get('/desiderata')).status(), 'nor a request list').toBe(404);

  await page.goto(`/catalogo?search=${encodeURIComponent(seeded.prefix)}`);
  await expect(page.locator('.book-card').filter({ hasText: book.titolo }), 'catalogue search must not reach it').toHaveCount(0);
  await expect(page.locator('.book-card').filter({ hasText: held.titolo }), 'while the held book still answers').toHaveCount(1);

  const preview = await (await page.request.get(`/api/search/preview?q=${encodeURIComponent(seeded.prefix)}`)).json();
  expect(preview.map(row => row.title), 'the preview must not reach it either').not.toContain(book.titolo);
  expect(preview.map(row => row.title), 'and must still answer for the held book').toContain(held.titolo);

  // Not in the plan, and not covered by the 33-check visibility suite either:
  // this endpoint goes through fetchLiveAvailability(), which now uses the
  // widened predicate. With the plugin off it must refuse the id outright
  // instead of publishing a copy count for a book nobody owns.
  const edge = await (await page.request.get(`/api/edge/availability?ids=${book.id},${held.id}`)).json();
  expect(edge.success).toBe(true);
  expect(Object.keys(edge.books), 'the request must not have an availability row').not.toContain(String(book.id));
  expect(Object.keys(edge.books), 'the held book must still have one').toContain(String(held.id));

  await page.goto('/');
  await expect(page.locator('.desiderata-section'), 'and the homepage section is gone with the row').toHaveCount(0);

  await asAdmin(browser, async admin => {
    await ensurePluginActive(admin, 'desiderata', { smokePath: '/desiderata' });
  });
  expect((await page.request.get('/desiderata')).status(), 'and comes back on re-activation').toBe(200);
});

test('T20 — browser wiring: the real form posts the token grecaptcha produced', async ({ page, browser }) => {
  const configured = fixture('recaptcha', 'e2e-site', '');
  expect(configured.recaptcha_site_key, 'the fixture must have configured a site key').toBe('e2e-site');
  expect(configured.recaptcha_secret_key, 'with no secret, so the server skips verification').toBe('');

  /** @type {string[]} */ const loaderRequests = [];
  await page.route('https://www.google.com/recaptcha/api.js*', async route => {
    loaderRequests.push(route.request().url());
    await route.fulfill({
      status: 200,
      contentType: 'application/javascript',
      body: 'window.grecaptcha={ready:cb=>cb(),execute:()=>Promise.resolve("e2e-token")};',
    });
  });

  await page.goto('/desiderata');
  expect(loaderRequests, 'the loader must be requested with the configured key').toHaveLength(1);
  expect(loaderRequests[0]).toContain('render=e2e-site');

  await fillDonation(page, { name: 'Donatore T20', email: seeded.email, title: `Offerta reCAPTCHA ${tag}` });
  const posted = page.waitForRequest(request => request.method() === 'POST' && request.url().includes('/desiderata/offers'));
  await page.getByRole('button', { name: 'Invia la proposta', exact: true }).click();
  const request = await posted;
  // The token the stub produced has to be in the body the browser really sent —
  // not in a variable, not in a field outside the form, not after the CSRF hop.
  expect(request.postData(), 'the submitted body must carry the token').toContain('recaptcha_token=e2e-token');
  await expect(page.getByRole('status').filter({ hasText: 'Grazie!' })).toBeVisible();

  // No key configured: nothing may be loaded from Google at all.
  const cleared = fixture('recaptcha', '', '');
  expect(cleared.recaptcha_site_key).toBe('');
  const context = await browser.newContext();
  try {
    const quiet = await context.newPage();
    /** @type {string[]} */ const silent = [];
    await quiet.route('https://www.google.com/recaptcha/**', async route => { silent.push(route.request().url()); await route.abort(); });
    await quiet.goto('/desiderata');
    await expect(quiet.locator('#desiderata-offer'), 'the form is still there').toHaveCount(1);
    await expect(quiet.locator('script[src*="recaptcha"]'), 'with no loader tag').toHaveCount(0);
    expect(silent, 'and no request to Google').toEqual([]);
  } finally {
    await context.close();
  }
});
