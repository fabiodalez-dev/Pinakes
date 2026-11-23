// @ts-check
const { test, expect } = require('@playwright/test');
const BASE = process.env.E2E_BASE_URL || process.env.APP_URL || 'http://localhost:8081';

async function login(page) {
  await page.goto(`${BASE}/accedi`);
  await page.locator('input[name="email"]').fill(process.env.E2E_ADMIN_EMAIL || '');
  await page.locator('input[name="password"]').fill(process.env.E2E_ADMIN_PASS || '');
  await page.locator('button[type=submit]').click();
  await page.waitForURL(url => !url.pathname.includes('accedi'));
}

// Visit the actual staff destinations, including installed plugins and hidden
// settings subnavigation. No writes, fixture changes or guessed plugin routes.
async function destinations(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  return page.locator('#sidebar nav a[href]').evaluateAll(links =>
    [...new Set(links.map(link => link.href).filter(href => {
      const url = new URL(href);
      return url.origin === location.origin && url.pathname.includes('/admin/') && !url.pathname.endsWith('/dashboard');
    }))]
  );
}

for (const viewport of [{ width: 1512, height: 844 }, { width: 390, height: 844 }]) {
  test(`staff navigation has one readable header and fits ${viewport.width}px`, async ({ page }) => {
    test.setTimeout(240_000);
    await page.setViewportSize(viewport);
    await login(page);
    const urls = await destinations(page);
    expect(urls.length).toBeGreaterThan(15);
    for (const url of urls) {
      await test.step(new URL(url).pathname + new URL(url).search, async () => {
        const response = await page.goto(url);
        expect(response?.ok(), url).toBeTruthy();
        await expect(page.locator('main [data-workspace-header]')).toHaveCount(1);
        await expect(page.locator('main .pk-workspace-breadcrumb')).toHaveCount(1);
        await expect(page.locator('main .pk-workspace-breadcrumb [aria-current="page"]')).toHaveCount(1);
        await expect(page.locator('main .pk-workspace-breadcrumb ol > li:first-child a')).toHaveAttribute('href', /\/admin\/dashboard$/);
        const geometry = await page.evaluate(() => {
          const title = document.querySelector('main h1');
          const page = document.querySelector('.pk-workspace-page');
          const back = document.querySelector('[data-workspace-back] a');
          return {
            titleLeft: title.getBoundingClientRect().left,
            contentLeft: page.getBoundingClientRect().left,
            backHeight: back.getBoundingClientRect().height,
            overflow: document.documentElement.scrollWidth > innerWidth + 1,
          };
        });
        expect(geometry.overflow, url).toBe(false);
        expect(geometry.titleLeft - geometry.contentLeft).toBeGreaterThanOrEqual(15);
        expect(geometry.backHeight).toBeGreaterThanOrEqual(44);
      });
    }
  });
}

test('binary controls, language actions and article tabs stay usable', async ({ page }) => {
  await page.setViewportSize({ width: 1512, height: 844 });
  await login(page);
  const urls = await destinations(page);
  await page.goto(`${BASE}/admin/books/bulk-enrich`);
  const toggle = page.getByRole('switch');
  await expect(toggle).toBeVisible();
  const switchGeometry = await toggle.evaluate(element => {
    const box = element.getBoundingClientRect();
    const knob = element.querySelector('span').getBoundingClientRect();
    return { height: box.height, width: box.width, contained: knob.left >= box.left && knob.right <= box.right && knob.top >= box.top && knob.bottom <= box.bottom };
  });
  expect(switchGeometry.height).toBeGreaterThanOrEqual(44);
  expect(switchGeometry.contained).toBe(true);

  await page.goto(`${BASE}/admin/loans`);
  const scanGeometry = await page.locator('[data-copy-scan]').evaluate(element => {
    const icon = element.querySelector('i').getBoundingClientRect();
    const label = element.querySelector('span').getBoundingClientRect();
    return Math.abs(icon.top + icon.height / 2 - label.top - label.height / 2);
  });
  expect(scanGeometry).toBeLessThan(3);

  await page.goto(`${BASE}/admin/publishers`);
  await expect(page.locator('.dt-info')).toBeVisible();
  const footerGap = await page.evaluate(() => document.querySelector('.dt-info').getBoundingClientRect().top - document.querySelector('#editori-table').getBoundingClientRect().bottom);
  expect(footerGap).toBeGreaterThanOrEqual(24);
  // The list has a pager above and one below the table: they share one state,
  // so page on with the top one and expect both to show page 2.
  const publisherPagers = page.locator('.dt-container').filter({ has: page.locator('#editori-table') }).locator('.dt-paging');
  const nextPage = publisherPagers.first().locator('.dt-paging-button.next:not(.disabled)');
  if (await nextPage.count()) {
    await nextPage.click();
    await expect(publisherPagers.locator('.dt-paging-button[aria-current="page"]')).toHaveText(['2', '2']);
  }

  await page.goto(`${BASE}/admin/languages`);
  const controls = await page.locator('.pk-language-actions :is(a,button)').evaluateAll(elements => elements.map(element => {
    const box = element.getBoundingClientRect();
    return { width: box.width, height: box.height, padding: getComputedStyle(element).padding, name: element.getAttribute('aria-label') };
  }));
  expect(controls.length).toBeGreaterThan(0);
  for (const control of controls) {
    expect(control.width).toBeGreaterThanOrEqual(44);
    expect(control.height).toBeGreaterThanOrEqual(44);
    expect(control.name).toBeTruthy();
    expect(control.padding).toBe('0px');
  }
  await expect(page.locator('.pk-language-table progress').first()).toBeVisible();

  const articles = urls.find(url => new URL(url).pathname.endsWith('/periodicals/articles'));
  if (articles) {
    await page.goto(articles);
    const tabs = page.locator('main .settings-tab');
    await expect(tabs).toHaveCount(2);
    const target = await tabs.last().getAttribute('href');
    await tabs.last().click();
    await expect(page).toHaveURL(new URL(target, BASE).href);
    await expect(page.locator('main .settings-tab[aria-current="page"]')).toHaveCount(1);
    const boxes = await page.locator('main .settings-tab').evaluateAll(elements => elements.map(element => element.getBoundingClientRect().height));
    expect(boxes.every(height => height >= 44)).toBe(true);
  }
});


test('action styling preserves hidden and responsive layout behaviour', async ({ page }) => {
  await login(page);
  for (const width of [390, 767, 768, 1512]) {
    await page.setViewportSize({ width, height: 844 });
    await page.goto(`${BASE}/admin/loans`);
    const desktop = page.locator('main .hidden.md\\:inline-flex.btn-primary');
    const mobile = page.locator('main .md\\:hidden .btn-primary');
    if (width < 768) {
      await expect(desktop).toBeHidden();
      await expect(mobile).toBeVisible();
    } else {
      await expect(desktop).toBeVisible();
      await expect(mobile).toBeHidden();
    }
  }

  await page.goto(`${BASE}/admin/genres`);
  const detail = page.locator('main a[href*="/admin/genres/"]:not([href$="/create"])').first();
  // The installer supplies genres; follow the real detail route from its table.
  await detail.click();
  const edit = page.locator('#btn-edit-genre');
  await expect(edit).toBeVisible();
  await edit.click();
  await expect(edit).toBeHidden();
  await expect(page.locator('#edit-genre-form')).toBeVisible();
  await page.locator('#btn-cancel-edit').click();
  await expect(edit).toBeVisible();
  await expect(page.locator('#edit-genre-form')).toBeHidden();

  await page.goto(`${BASE}/admin/books/import`);
  const accordion = page.locator('button.justify-between').first();
  await expect(accordion).toBeVisible();
  expect(await accordion.evaluate(element => getComputedStyle(element).justifyContent)).toBe('space-between');
});


test('API key eye remains centred on hover and has an accessible name', async ({ page }) => {
  await login(page);
  await page.goto(`${BASE}/admin/plugins`);
  const configure = page.locator('button[data-pk-action="plugins-open-api-scraper"]');
  await expect(configure).toBeVisible();
  await configure.click();
  const eye = page.locator('button[data-pk-action="plugins-toggle-api-key"]');
  await expect(eye).toHaveAttribute('aria-label', /.+/);
  const offset = async () => eye.evaluate(element => {
    const button = element.getBoundingClientRect();
    const input = document.querySelector('#apiKeyInput').getBoundingClientRect();
    return Math.abs(button.top + button.height / 2 - input.top - input.height / 2);
  });
  expect(await offset()).toBeLessThan(1);
  await eye.hover();
  await expect.poll(offset).toBeLessThan(1);
  // Cancel rather than submit; this test does not read or change the key.
  await page.locator('button[data-pk-action="plugins-close-api-scraper"]', { hasText: /Annulla|Cancel/ }).click();
});
