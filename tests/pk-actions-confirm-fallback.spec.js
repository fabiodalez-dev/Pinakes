// @ts-check
// data-pk-confirm without SweetAlert: vendor.bundle.js (which defines
// window.Swal) is deferred, so an early click falls back to window.confirm().
// The confirmed action must still run, and a submit that fails constraint
// validation must not leave a stale "already confirmed" flag behind.
//
// Self-contained: the page is served by page.route() from a fake origin and
// loads the real public/assets/js/pk-actions.js, so no app server is needed.
const path = require('path');
const { test, expect } = require('@playwright/test');

const ORIGIN = 'http://pk-actions.test';
const SCRIPT = path.join(__dirname, '..', 'public', 'assets', 'js', 'pk-actions.js');

const PAGE = `<!doctype html><html><head><meta charset="utf-8"></head><body>
<form id="f" method="post" action="${ORIGIN}/done" data-pk-confirm="Sure?">
  <input type="text" name="title" id="title">
  <button type="submit" id="go" name="op" value="save">Save</button>
</form>
<form id="g" method="post" action="${ORIGIN}/done">
  <button type="submit" id="del" name="op" value="delete" data-pk-confirm="Delete?">Delete</button>
</form>
</body></html>`;

async function open(page) {
  const posted = [];
  await page.route(`${ORIGIN}/**`, async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/done') {
      posted.push(route.request().postData() || '');
      await route.fulfill({ status: 200, contentType: 'text/html', body: '<p id="done">done</p>' });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'text/html', body: PAGE });
  });
  await page.goto(`${ORIGIN}/`);
  await page.addScriptTag({ path: SCRIPT });
  expect(await page.evaluate(() => typeof window.Swal)).toBe('undefined');
  return posted;
}

test('form-level confirm submits after OK when SweetAlert is missing', async ({ page }) => {
  const posted = await open(page);
  const dialogs = [];
  page.on('dialog', (dialog) => { dialogs.push(dialog.message()); dialog.accept(); });

  await page.click('#go');
  await expect(page.locator('#done')).toBeVisible();
  expect(dialogs).toEqual(['Sure?']);
  expect(posted).toHaveLength(1);
  expect(posted[0]).toContain('op=save');
});

test('form-level confirm stays put after Cancel', async ({ page }) => {
  const posted = await open(page);
  page.on('dialog', (dialog) => dialog.dismiss());

  await page.click('#go');
  await page.waitForTimeout(300);
  expect(posted).toHaveLength(0);
  await expect(page.locator('#f')).toBeVisible();
});

test('button-level confirm clicks through after OK when SweetAlert is missing', async ({ page }) => {
  const posted = await open(page);
  page.on('dialog', (dialog) => dialog.accept());

  await page.click('#del');
  await expect(page.locator('#done')).toBeVisible();
  expect(posted).toHaveLength(1);
  expect(posted[0]).toContain('op=delete');
});

test('a submit blocked by validation does not skip the next confirmation', async ({ page }) => {
  const posted = await open(page);
  const asked = [];
  page.on('console', (msg) => { if (msg.text() === 'pk-confirm-asked') asked.push(1); });
  // The form turns invalid while the dialog is open (here: the field becomes
  // required and is empty), so the re-submit after OK fires no 'submit'.
  await page.evaluate(() => {
    window.__asked = 0;
    window.confirm = () => {
      window.__asked++;
      console.log('pk-confirm-asked');
      if (window.__asked === 1) document.getElementById('title')?.setAttribute('required', '');
      return true;
    };
  });

  await page.click('#go');
  await expect.poll(() => page.evaluate(() => window.__asked)).toBe(1);
  await page.waitForTimeout(300);
  expect(posted).toHaveLength(0);
  expect(await page.evaluate(() => document.getElementById('f')?.dataset.pkConfirmed)).toBeUndefined();

  // Fill the field: the next submit must ask again, then go through.
  await page.fill('#title', 'x');
  await page.click('#go');
  await expect(page.locator('#done')).toBeVisible();
  expect(posted).toHaveLength(1);
  expect(asked).toHaveLength(2);
});
