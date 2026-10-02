// Analytic (component-part) records for standalone articles — issue #412.
//
// The unit suite proves the rules; this one proves the librarian can reach
// them. It fills the two collapsed sections through the real admin form, then
// reads the public page as a visitor: the classification with its scheme, the
// language rendered in the reader's language rather than as a stored code, the
// single primary action when a record has both a PDF and an external address,
// the copy button actually putting the citation on the clipboard, and the RIS
// download answering with a file EndNote would accept.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || process.env.APP_URL || 'http://localhost:8081';
const marker = `Analytic412-${Date.now()}`;

test.skip(
  !process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASS || !process.env.E2E_DB_NAME,
  'E2E credentials not configured',
);

function db(sql) {
  const args = ['-u', process.env.E2E_DB_USER, process.env.E2E_DB_NAME, '-N', '-B', '-e', sql];
  if (process.env.E2E_DB_SOCKET) args.unshift('-S', process.env.E2E_DB_SOCKET);
  return execFileSync('mysql', args, { encoding: 'utf8', env: { ...process.env, MYSQL_PWD: process.env.E2E_DB_PASS } }).trim();
}

async function login(page) {
  await page.goto(`${BASE}/admin/plugins`);
  if (await page.locator('input[name=email]').isVisible()) {
    await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL);
    await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASS);
    await page.locator('button[type=submit]').click();
    await page.waitForURL(u => !u.pathname.includes('accedi') && !u.pathname.includes('login'));
  }
}

// Emeroteca ships inactive. Activating it through the real UI is what makes
// onActivate() build the schema, which is the path an installation takes.
async function ensureEmerotecaActive(page) {
  const id = Number(db("SELECT id FROM plugins WHERE name='emeroteca'") || '0');
  expect(id, 'emeroteca must be registered as a bundled plugin').toBeGreaterThan(0);
  const active = () => db(`SELECT is_active FROM plugins WHERE id=${id}`) === '1';
  for (let attempt = 0; attempt < 3 && !active(); attempt++) {
    await page.goto(`${BASE}/admin/plugins`);
    const button = page.locator(`[data-plugin-id="${id}"]`).first().locator('button:has-text("Attiva plugin")');
    if (!await button.isVisible({ timeout: 3000 }).catch(() => false)) continue;
    await button.click();
    const confirm = page.locator('.swal2-confirm:visible');
    if (await confirm.isVisible({ timeout: 3000 }).catch(() => false)) await confirm.click();
    await expect.poll(active, { timeout: 30_000 }).toBe(true).catch(() => {});
  }
  expect(active(), 'emeroteca could not be activated').toBe(true);
}

test.describe.serial('Emeroteca analytic record (#412)', () => {
  let articleId = 0;

  test.afterAll(() => {
    if (articleId > 0) db(`DELETE FROM emeroteca_contributi WHERE id=${articleId}`);
  });

  test('the analytic apparatus is reachable from the real form', async ({ page }) => {
    await login(page);
    await ensureEmerotecaActive(page);

    await page.goto(`${BASE}/admin/periodicals/articles/create`);
    await expect(page.locator('#article-titolo')).toBeVisible();

    // Every analytic field lives behind a <details> the cataloguer opens
    // deliberately: a collection that only wants a citation must not meet a
    // MARC worksheet. Assert they are CLOSED before opening them.
    const advanced = page.locator('details', { hasText: /Descrizione bibliografica avanzata|Advanced bibliographic/ }).first();
    const resource = page.locator('details', { hasText: /Risorsa elettronica|Electronic resource/ }).first();
    expect(await advanced.evaluate(el => el.open), 'the advanced section starts folded away').toBe(false);
    expect(await resource.evaluate(el => el.open), 'and so does the electronic resource').toBe(false);

    // The authors field asks for a semicolon; keywords split on a comma
    // everywhere in the plugin. Without saying so, a cataloguer who read the
    // instruction two fields above types a semicolon here and silently gets one
    // keyword with a semicolon inside it — in the page, the JSON-LD and the RIS.
    await expect(
      page.locator('main, form').getByText(/Separa le parole chiave con una virgola|Separate keywords with a comma/),
      'the keywords field states its separator',
    ).toBeVisible();

    await page.locator('#article-titolo').fill(`${marker} On the trail`);
    await page.locator('#article-sottotitolo').fill('a subtitle that carries half the meaning');
    await page.locator('#article-autori').fill('Petersen, Hans Uwe');
    await page.locator('#article-contenitore_titolo').fill('Arbejderhistorie');
    await page.locator('#article-anno_pubblicazione').fill('1988');
    await page.locator('#article-numero').fill('31');
    await page.locator('#article-pagine').fill('18-38');

    await advanced.locator('summary').click();
    await page.locator('#article-lingua').fill('dan');
    await page.locator('#article-paese').fill('DK');
    await page.locator('#article-classificazione_schema').fill('DK5');
    await page.locator('#article-classificazione').fill('33.129');
    await page.locator('#article-nota_possesso').fill('Copy / offprint only');

    await resource.locator('summary').click();
    await page.locator('#article-risorsa_url').fill('https://arkiv.example/1988-31.pdf');
    await page.locator('#article-risorsa_testo').fill('Download the article as a PDF');
    await page.locator('#article-risorsa_accesso').fill('For internal use only');
    await page.locator('input[name="risorsa_pubblica"]').check();
    await page.locator('input[name="pubblico"]').check();

    await page.locator('button[type=submit]:has-text("Salva")').first().click();

    // Wait for the DETAIL url, not merely for a path containing
    // '/admin/periodicals/articles': the create page satisfies that prefix
    // already, so the wait would resolve before the POST completed and the
    // lookup below could read the table before the INSERT landed. A successful
    // store redirects to /admin/periodicals/articles/{id}, so the id comes from
    // the url the application chose rather than from a LIKE on the title.
    await page.waitForURL(/\/admin\/periodicals\/articles\/\d+(\?|$)/);
    articleId = Number((page.url().match(/\/articles\/(\d+)/) || [])[1] || '0');
    expect(articleId, 'the article was saved and the app redirected to it').toBeGreaterThan(0);

    const savedTitle = db(`SELECT titolo FROM emeroteca_contributi WHERE id=${articleId}`);
    expect(savedTitle, 'and that id really is the row this test just created').toContain(marker);

    const stored = db(`SELECT CONCAT_WS('|', lingua, paese, classificazione_schema, classificazione, nota_possesso, risorsa_pubblica) FROM emeroteca_contributi WHERE id=${articleId}`);
    expect(stored, 'every analytic field reached the database').toBe('dan|DK|DK5|33.129|Copy / offprint only|1');
  });

  test('the public page reads as an analytic record', async ({ page }) => {
    expect(articleId).toBeGreaterThan(0);
    await page.goto(`${BASE}/emeroteca/articolo/${articleId}`);

    // The cookie banner ships a <main> of its own, so the article's is named.
    // The title and subtitle live in the book-style hero, just above the main.
    await expect(page.locator('main[data-articolo-id]')).toHaveAttribute('id', 'emeroteca-articolo');
    await expect(page.locator('h1.resource-title')).toContainText(marker);
    await expect(page.locator('.book-hero.resource-hero')).toContainText('a subtitle that carries half the meaning');
    // The scheme travels with the notation: 33.129 alone means nothing to a
    // reader who does not already know which list it came from.
    await expect(page.locator('main[data-articolo-id]')).toContainText('DK5: 33.129');
    await expect(page.locator('main[data-articolo-id]')).toContainText('Copy / offprint only');

    // The stored code is `dan`; the page must show a language NAME, and it
    // must not show the raw code. That is the whole reason the column holds a
    // code in a per-user multilingual application.
    const languageCell = page.locator('#emeroteca-articolo .meta-value', { hasText: /^(danese|Danish|Dänisch|danois|dansk)$/i });
    await expect(languageCell.first(), 'the ISO code is rendered as a name').toBeVisible();

    await expect(page.locator('main[data-articolo-id]')).toContainText(/Cita questo articolo|Cite this article/);
    await expect(page.locator('main[data-articolo-id]')).toContainText('Petersen, H. U. (1988).');
    await expect(page.locator('[data-citation-copy]')).toHaveCount(2);
  });

  test('exactly one primary action, and it is never a link a browser cannot follow', async ({ page }) => {
    expect(articleId).toBeGreaterThan(0);
    await page.goto(`${BASE}/emeroteca/articolo/${articleId}`);

    // No PDF on this record, so the external address is the primary action.
    await expect(page.locator('a.btn-primary')).toHaveCount(1);
    await expect(page.locator('a.btn-primary')).toHaveAttribute('href', 'https://arkiv.example/1988-31.pdf');
    await expect(page.locator('a.btn-primary')).toHaveAttribute('rel', /noopener/);
    await expect(page.locator('main[data-articolo-id]')).toContainText('For internal use only');

    // A local path is a reference the library can read and a browser cannot.
    db(`UPDATE emeroteca_contributi SET risorsa_url='\\\\\\\\archivio\\\\scans\\\\1988-31.pdf' WHERE id=${articleId}`);
    await page.reload();
    await expect(page.locator('a.btn-primary'), 'an unfollowable path is not promoted to a button').toHaveCount(0);
    await expect(page.locator('main[data-articolo-id] code')).toContainText('archivio');
    const anchors = await page.locator('main[data-articolo-id] a[href*="archivio"]').count();
    expect(anchors, 'and is never wrapped in an anchor').toBe(0);

    // Unpublishing the resource removes it from the page entirely, not merely
    // from view: a hidden address in the HTML is still published.
    db(`UPDATE emeroteca_contributi SET risorsa_url='https://segreto.example/x.pdf', risorsa_pubblica=0 WHERE id=${articleId}`);
    await page.reload();
    expect(await page.content()).not.toContain('segreto.example');

    db(`UPDATE emeroteca_contributi SET risorsa_url='https://arkiv.example/1988-31.pdf', risorsa_pubblica=1 WHERE id=${articleId}`);
  });

  test('the copy button puts the citation on the clipboard', async ({ page, context, browserName }) => {
    test.skip(browserName !== 'chromium', 'clipboard permissions are only grantable in Chromium');
    expect(articleId).toBeGreaterThan(0);
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await page.goto(`${BASE}/emeroteca/articolo/${articleId}`);

    const button = page.locator('[data-citation-copy]').first();
    const expected = (await page.locator('[data-citation-text]').first().textContent() || '').trim();
    await button.click();

    await expect(button).toHaveText(/Copiato|Copied|Kopiert|Copié|Kopieret/);
    const clipboard = await page.evaluate(() => navigator.clipboard.readText());
    expect(clipboard.trim(), 'the clipboard holds the citation as rendered').toBe(expected);
  });

  test('RIS downloads as a file a reference manager accepts', async ({ page }) => {
    expect(articleId).toBeGreaterThan(0);
    const response = await page.request.get(`${BASE}/emeroteca/articolo/${articleId}/citazione.ris`);
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('application/x-research-info-systems');
    expect(response.headers()['content-disposition']).toContain(`articolo-${articleId}.ris`);

    const body = await response.text();
    expect(body.startsWith('TY  - JOUR\r\n'), 'the type line opens the record').toBe(true);
    expect(body.endsWith('ER  - \r\n'), 'the end-of-record marker closes it').toBe(true);
    // Every line CR LF. EndNote on Windows is the strict reader, and a bare LF
    // is the failure that only shows up on somebody else's machine.
    expect(body.split('\n').length - 1).toBe(body.split('\r\n').length - 1);
    expect(body).toContain('AU  - Petersen, Hans Uwe\r\n');
    expect(body).toContain('SP  - 18\r\n');
    expect(body).toContain('EP  - 38\r\n');
    expect(body).toContain('LA  - dan\r\n');

    // Unpublished means unpublished, for the citation as much as for the page.
    db(`UPDATE emeroteca_contributi SET pubblico=0 WHERE id=${articleId}`);
    const refused = await page.request.get(`${BASE}/emeroteca/articolo/${articleId}/citazione.ris`);
    expect(refused.status(), 'an unpublished article does not export a citation publicly').toBe(404);

    // The cataloguer can still take it into Mendeley from the admin side.
    await login(page);
    const admin = await page.request.get(`${BASE}/admin/periodicals/articles/${articleId}/citation.ris`);
    expect(admin.status(), 'while the admin route still answers').toBe(200);
    const adminBody = await admin.text();
    expect(adminBody).toContain('TY  - JOUR\r\n');
    // Both URLs in the file are PUBLIC routes. An unpublished record has no
    // public page and its public PDF route answers 404, so neither may appear:
    // a reference manager follows them unattended and would store a dead link.
    // pdf_pubblico alone does not make a draft's PDF reachable.
    db(`UPDATE emeroteca_contributi SET pdf_path='${'a'.repeat(40)}.pdf', pdf_pubblico=1 WHERE id=${articleId}`);
    const draft = await page.request.get(`${BASE}/admin/periodicals/articles/${articleId}/citation.ris`);
    const draftBody = await draft.text();
    expect(draftBody, 'a draft exports no record URL').not.toContain('UR  - ');
    expect(draftBody, 'and no file link, even with pdf_pubblico set').not.toContain('L1  - ');

    // Publishing it makes both appear — otherwise the two checks above would
    // pass for a formatter that never emits UR or L1 at all.
    db(`UPDATE emeroteca_contributi SET pubblico=1 WHERE id=${articleId}`);
    const live = await page.request.get(`${BASE}/admin/periodicals/articles/${articleId}/citation.ris`);
    const liveBody = await live.text();
    expect(liveBody, 'a published record does export its page').toContain(`UR  - `);
    expect(liveBody, 'and its PDF').toContain(`L1  - `);

    db(`UPDATE emeroteca_contributi SET pdf_path=NULL, pdf_pubblico=0 WHERE id=${articleId}`);
  });
});
