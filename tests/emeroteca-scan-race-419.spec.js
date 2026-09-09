const { test, expect } = require('@playwright/test');
const path = require('path');

// Real browser, production script, deliberately reordered HTTP responses.
async function scanner(page) {
  await page.route('http://scanner.test/', route => route.fulfill({
    contentType: 'text/html',
    body: `<input id="emt-scan-code"><button id="emt-scan-lookup">Lookup</button>
      <div id="emt-scan-result" hidden></div><a id="emt-scan-open" hidden></a>
      <form id="emt-scan-receive" hidden><input name="fascicolo_id"><button>Receive</button></form>
      <script>window.emerotecaScan = {lookupUrl: '/lookup', testataId: 1};</script>`,
  }));
  const pending = [];
  await page.route('http://scanner.test/lookup?**', route => { pending.push(route); });
  await page.goto('http://scanner.test/');
  await page.addScriptTag({ path: path.join(__dirname, '../storage/plugins/emeroteca/assets/js/emeroteca-scan.js') });
  const scan = async code => {
    const before = pending.length;
    await page.locator('#emt-scan-code').fill(code);
    await page.locator('#emt-scan-code').press('Enter');
    await expect.poll(() => pending.length).toBe(before + 1);
    return pending[before];
  };
  return { scan, id: page.locator('[name=fascicolo_id]'), form: page.locator('#emt-scan-receive') };
}
const match = id => ({ found: true, match: 'issue', action: 'receive', message: `Issue ${id}`,
  issue: { id, url: `/admin/periodicals/issue/${id}` } });

test('a late response cannot replace the latest scanned issue', async ({ page }) => {
  const { scan, id, form } = await scanner(page);
  const first = await scan('9770000000001');
  const second = await scan('9770000000002');
  await second.fulfill({ json: match(2) });
  await expect(id).toHaveValue('2');
  await first.fulfill({ json: match(1) });
  await expect(page.locator('#emt-scan-result')).toHaveText('Issue 2');
  await expect(id).toHaveValue('2');
  await expect(form).toBeVisible();
});

test('editing or clearing the input invalidates the selected issue and pending lookup', async ({ page }) => {
  const { scan, id, form } = await scanner(page);
  const first = await scan('9770000000001');
  await first.fulfill({ json: match(1) });
  await expect(form).toBeVisible();
  await page.locator('#emt-scan-code').fill('9770000000002');
  await expect(id).toHaveValue('');
  await expect(form).toBeHidden();
  const second = await scan('9770000000002');
  await page.locator('#emt-scan-code').fill('');
  await second.fulfill({ json: match(2) });
  await expect(id).toHaveValue('');
  await expect(form).toBeHidden();
  await expect(page.locator('#emt-scan-open')).toBeHidden();
});

test('a stale error cannot hide a newer successful lookup', async ({ page }) => {
  const { scan, id, form } = await scanner(page);
  const first = await scan('9770000000001');
  const second = await scan('9770000000002');
  await second.fulfill({ json: match(2) });
  await expect(id).toHaveValue('2');
  await first.fulfill({ status: 500, body: 'failed' });
  await expect(page.locator('#emt-scan-result')).toHaveText('Issue 2');
  await expect(form).toBeVisible();
  const third = await scan('9770000000003');
  await third.fulfill({ json: { found: false, message: 'Not found' } });
  await expect(page.locator('#emt-scan-result')).toHaveText('Not found');
  await expect(id).toHaveValue('');
  await expect(form).toBeHidden();
});
