// The CMS as an administrator uses it: the index, the homepage sections, the
// content pages and the events.
//
// The bug this suite starts from: switching a homepage section off and pressing
// Save turned it straight back on. The section's visibility lives on the page
// twice — the toggle in the ordering list, which writes immediately, and the
// "Visibile" checkbox inside the section's own card, which is written on
// submit — and the two did not talk to each other, so the form re-sent the
// value the page had been loaded with.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || process.env.APP_URL || 'http://localhost:8081';
const marker = `ZZCMS${Date.now()}`;

function db(sql) {
  const args = ['-u', process.env.E2E_DB_USER, process.env.E2E_DB_NAME, '-N', '-B', '-e', sql];
  if (process.env.E2E_DB_SOCKET) args.unshift('-S', process.env.E2E_DB_SOCKET);
  return execFileSync('mysql', args, { encoding: 'utf8', env: { ...process.env, MYSQL_PWD: process.env.E2E_DB_PASS } }).trim();
}

async function login(page) {
  await page.goto(BASE + '/admin/dashboard');
  if (await page.locator('input[name=email]').isVisible()) {
    await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL);
    await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASS);
    await page.locator('button[type=submit]').click();
    await page.waitForURL(u => !u.pathname.includes('accedi') && !u.pathname.includes('login'));
  }
}

const listToggle = (key) => `[data-section-key="${key}"] input[type=checkbox]`;
const sectionActive = (key) => db(`SELECT is_active FROM home_content WHERE section_key='${key}'`);

// Everything this suite changes is put back, so it can run on a working
// installation without leaving the homepage rearranged.
let originalState = {};

test.describe.serial('CMS admin', () => {
  test.beforeAll(() => {
    if (!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_DB_USER) throw new Error('Run with /tmp/run-e2e.sh');
    const rows = db('SELECT section_key, is_active, display_order FROM home_content').split('\n').filter(Boolean);
    originalState = Object.fromEntries(rows.map(r => {
      const [key, active, order] = r.split('\t');
      return [key, { active, order }];
    }));
  });

  test.afterAll(() => {
    try {
      for (const [key, v] of Object.entries(originalState)) {
        db(`UPDATE home_content SET is_active=${Number(v.active)}, display_order=${Number(v.order)} WHERE section_key='${key}'`);
      }
      db(`DELETE FROM events WHERE title LIKE '${marker}%'`);
    } catch (e) { console.error('Scoped cleanup failed:', e.message); }
  });

  test('the CMS index lists everything that can be edited', async ({ page }) => {
    await login(page);
    const response = await page.goto(BASE + '/admin/cms');
    // Used to be a 404: the three entry points existed only as buttons inside
    // the settings page, so the address they all shorten to led nowhere.
    expect(response.status()).toBe(200);

    const links = await page.locator('a[href*="/admin/cms/"]').evaluateAll(as => as.map(a => a.getAttribute('href')));
    expect(links.some(h => h.endsWith('/admin/cms/home'))).toBe(true);
    expect(links.some(h => h.endsWith('/admin/cms/events'))).toBe(true);

    // Every page in the database is reachable from here, including the ones no
    // menu links to.
    const slugs = db("SELECT slug FROM cms_pages WHERE locale='it_IT'").split('\n').filter(Boolean);
    expect(slugs.length).toBeGreaterThan(0);
    for (const slug of slugs) {
      expect(links.some(h => h.endsWith('/admin/cms/' + slug)), `${slug} is linked`).toBe(true);
    }
  });

  test('a section switched off in the list stays off when the page is saved', async ({ page }) => {
    await login(page);
    await page.goto(BASE + '/admin/cms/home');
    await page.locator(listToggle('features_title')).check();
    await page.waitForTimeout(800);
    await page.reload();

    // Switch it off in the ordering list…
    await page.locator(listToggle('features_title')).uncheck();
    await page.waitForTimeout(800);
    expect(sectionActive('features_title'), 'the toggle writes immediately').toBe('0');
    // …the card's own checkbox must follow, or the form will re-send "on".
    await expect(page.locator('#features_visible')).not.toBeChecked();

    await page.locator('button[type=submit]').first().click();
    await page.waitForLoadState('networkidle');
    expect(sectionActive('features_title'), 'saving must not resurrect the section').toBe('0');
    await expect(page.locator('#features_visible')).not.toBeChecked();
  });

  test('and disappears from the public homepage', async ({ page, browser }) => {
    await login(page);
    const anonymous = await browser.newContext();
    const reader = await anonymous.newPage();

    await reader.goto(BASE + '/');
    await expect(reader.locator('[data-section="features_title"]')).toHaveCount(0);

    // Back on: the section returns, so the switch works in both directions.
    await page.goto(BASE + '/admin/cms/home');
    await page.locator('#features_visible').check();
    await expect(page.locator(listToggle('features_title'))).toBeChecked();
    await page.locator('button[type=submit]').first().click();
    await page.waitForLoadState('networkidle');
    expect(sectionActive('features_title')).toBe('1');

    await reader.goto(BASE + '/');
    await expect(reader.locator('[data-section="features_title"]')).toHaveCount(1);
    await anonymous.close();
  });

  test('a feature card switched off leaves no placeholder behind', async ({ page, browser }) => {
    await login(page);
    await page.goto(BASE + '/admin/cms/home');
    for (const key of ['feature_1', 'feature_2', 'feature_3', 'feature_4']) {
      const toggle = page.locator(listToggle(key));
      if (!(await toggle.isChecked())) {
        await toggle.check();
        await page.waitForTimeout(400);
      }
    }
    await page.locator('#features_visible').check();
    await page.locator('button[type=submit]').first().click();
    await page.waitForLoadState('networkidle');

    const anonymous = await browser.newContext();
    const reader = await anonymous.newPage();
    await reader.goto(BASE + '/?cb=' + Date.now());
    const before = await reader.locator('[data-section="features_title"] .feature-card').count();
    expect(before).toBe(4);

    // Switching a card off is what an administrator does when they do not want
    // it. The section used to draw it anyway, from the template's own defaults:
    // a live library published four cards reading "Feature 1" to "Feature 4",
    // a star icon each and no text.
    await page.goto(BASE + '/admin/cms/home');
    await page.locator(listToggle('feature_1')).uncheck();
    await page.waitForTimeout(900);

    await reader.goto(BASE + '/?cb=' + Date.now());
    await expect(reader.locator('[data-section="features_title"] .feature-card')).toHaveCount(3);
    await expect(reader.locator('[data-section="features_title"]')).not.toContainText('Feature 1');

    // With every card off, the empty grid is not drawn at all.
    await page.goto(BASE + '/admin/cms/home');
    for (const key of ['feature_2', 'feature_3', 'feature_4']) {
      await page.locator(listToggle(key)).uncheck();
      await page.waitForTimeout(400);
    }
    await reader.goto(BASE + '/?cb=' + Date.now());
    await expect(reader.locator('[data-section="features_title"]')).toHaveCount(1);
    await expect(reader.locator('[data-section="features_title"] .feature-grid')).toHaveCount(0);

    // Put the cards back.
    await page.goto(BASE + '/admin/cms/home');
    for (const key of ['feature_1', 'feature_2', 'feature_3', 'feature_4']) {
      await page.locator(listToggle(key)).check();
      await page.waitForTimeout(400);
    }
    await reader.goto(BASE + '/?cb=' + Date.now());
    await expect(reader.locator('[data-section="features_title"] .feature-card')).toHaveCount(4);
    await anonymous.close();
  });

  test('an invalid field saves nothing and says so', async ({ page }) => {
    await login(page);
    await page.goto(BASE + '/admin/cms/home');
    const before = sectionActive('features_title');

    await page.locator('input[name="hero[button_link]"]').fill('non un url valido');
    await page.locator('#features_visible').uncheck();
    await page.locator('button[type=submit]').first().click();
    await page.waitForLoadState('networkidle');

    // Every section is written only when there are no errors, so one bad field
    // discards the whole submission. The message has to admit that much.
    const body = await page.locator('body').innerText();
    expect(body).toContain('Nessuna modifica è stata salvata');
    expect(body).toContain('link del pulsante non è valido');
    expect(body).not.toContain('<br>');
    expect(sectionActive('features_title'), 'nothing was written').toBe(before);
  });

  test('section order can be rearranged and survives a reload', async ({ page }) => {
    await login(page);
    await page.goto(BASE + '/admin/cms/home');
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const id = db("SELECT id FROM home_content WHERE section_key='cta'");
    const currentOrder = Number(db("SELECT display_order FROM home_content WHERE section_key='cta'"));

    const resp = await page.request.post(BASE + '/admin/cms/home/reorder', {
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      data: { order: [{ id: Number(id), display_order: currentOrder + 1 }] },
    });
    expect(resp.status()).toBeLessThan(400);
    expect(Number(db("SELECT display_order FROM home_content WHERE section_key='cta'"))).toBe(currentOrder + 1);
  });

  test('a content page saves, and its title lines up with its text', async ({ page, browser }) => {
    await login(page);
    await page.goto(BASE + '/admin/cms/chi-siamo');
    const titleInput = page.locator('input[name=title]').first();
    const original = await titleInput.inputValue();
    await titleInput.fill(`${original} ${marker}`);
    await page.locator('button[type=submit]').first().click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('input[name=title]').first()).toHaveValue(new RegExp(marker));

    // The heading used to sit in the page container while the text sat in a
    // narrower column, so on a left-aligned theme it started about a hundred
    // pixels further left than its own first line.
    const anonymous = await browser.newContext();
    const reader = await anonymous.newPage();
    await reader.setViewportSize({ width: 1280, height: 900 });
    await reader.goto(BASE + '/chi-siamo');
    const heading = await reader.locator('h1.cms-title').boundingBox();
    const content = await reader.locator('.cms-content').first().boundingBox();
    const centred = await reader.locator('.cms-header').first().evaluate(el => getComputedStyle(el).textAlign === 'center');
    if (centred) {
      const headingCentre = heading.x + heading.width / 2;
      const contentCentre = content.x + content.width / 2;
      expect(Math.abs(headingCentre - contentCentre)).toBeLessThan(2);
    } else {
      expect(Math.abs(heading.x - content.x)).toBeLessThan(2);
    }
    await anonymous.close();

    // Put the title back.
    await page.goto(BASE + '/admin/cms/chi-siamo');
    await page.locator('input[name=title]').first().fill(original);
    await page.locator('button[type=submit]').first().click();
    await page.waitForLoadState('networkidle');
  });

  test('events can be created, edited and deleted', async ({ page }) => {
    await login(page);
    await page.goto(BASE + '/admin/cms/events/create');
    await page.locator('input[name=title]').fill(`${marker} Evento`);
    // The date field is driven by Flatpickr, so its own input is hidden: set it
    // the way the main suite does rather than typing into a picker.
    await page.locator('input[name=event_date]').evaluate((el, d) => {
      el.value = d;
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }, '2027-03-15');
    await page.locator('button[type=submit]').first().click();
    await page.waitForLoadState('networkidle');
    const id = db(`SELECT id FROM events WHERE title='${marker} Evento'`);
    expect(id, 'the event was created').not.toBe('');

    await page.goto(BASE + `/admin/cms/events/edit/${id}`);
    await page.locator('input[name=title]').fill(`${marker} Evento modificato`);
    await page.locator('button[type=submit]').first().click();
    await page.waitForLoadState('networkidle');
    expect(db(`SELECT title FROM events WHERE id=${id}`)).toBe(`${marker} Evento modificato`);

    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const del = await page.request.post(BASE + `/admin/cms/events/delete/${id}`, {
      form: { csrf_token: csrf },
    });
    expect(del.status()).toBeLessThan(400);
    expect(db(`SELECT COUNT(*) FROM events WHERE id=${id}`)).toBe('0');
  });
});
