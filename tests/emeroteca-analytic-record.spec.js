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

// Open a Choices.js single select, search, and pick the first match. The
// option label is "Name (code)", so the same search finds by name or by code.
async function pickCode(page, field, query, expected) {
  const box = page.locator(`#article-${field}`).locator('xpath=ancestor::div[contains(concat(" ",normalize-space(@class)," ")," choices ")][1]');
  await box.click();
  const search = box.locator('input.choices__input--cloned');
  await search.fill(query);
  await page.waitForTimeout(300);
  const suggestion = box.locator('.choices__list--dropdown .choices__item--choice').first();
  await expect(suggestion).toHaveText(expected);
  await suggestion.click();
  await expect(box.locator('.choices__list--single .choices__item')).toHaveText(expected);
}

test.describe.serial('Emeroteca analytic record (#412)', () => {
  let articleId = 0;

  test.afterAll(() => {
    if (articleId > 0) {
      // The author was added through the picker and became a registry entry.
      const authorIds = db(`SELECT autore_id FROM emeroteca_contributi_autori WHERE contributo_id=${articleId} AND autore_id IS NOT NULL`).split('\n').filter(Boolean);
      db(`DELETE FROM emeroteca_contributi WHERE id=${articleId}`);
      for (const id of authorIds) db(`DELETE FROM autori WHERE id=${Number(id)} AND NOT EXISTS (SELECT 1 FROM libri_autori WHERE autore_id=${Number(id)}) AND NOT EXISTS (SELECT 1 FROM emeroteca_contributi_autori WHERE autore_id=${Number(id)})`);
    }
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
    // Picked as on the book form: type the name, Enter adds it (#412).
    const authorInput = page.locator('#article-author-editor .choices__input--cloned');
    await authorInput.click();
    await authorInput.pressSequentially('Petersen, Hans Uwe');
    await authorInput.press('Enter');
    await expect(page.locator('#article-credits input[name$="[nome_credito]"]').last()).toHaveValue('Petersen, Hans Uwe');
    await page.locator('#article-contenitore_titolo').fill('Arbejderhistorie');
    await page.locator('#article-anno_pubblicazione').fill('1988');
    await page.locator('#article-numero').fill('31');
    await page.locator('#article-pagine').fill('18-38');

    await advanced.locator(':scope > summary').click();
    // Language and country are picked from searchable lists of names, and
    // the code is what gets stored (#412): nobody has to know "dan" by heart.
    await pickCode(page, 'lingua', 'danese', /^Danese \(dan\)$/);
    await pickCode(page, 'paese', 'DK', /^Danimarca \(DK\)$/);
    await page.locator('#article-classificazione_schema').selectOption('DK5');
    await expect(page.locator('#article-scheme-other'), 'the free scheme name appears only for "Other"').toBeHidden();
    await expect(page.locator('#article-class-dewey'), 'the Dewey picker appears only for DDC').toBeHidden();
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

  test('Dewey notation comes from the book form picker; other schemes are named', async ({ page }) => {
    expect(articleId).toBeGreaterThan(0);
    await login(page);
    await page.goto(`${BASE}/admin/periodicals/articles/${articleId}`);
    const advanced = page.locator('details', { hasText: /Descrizione bibliografica avanzata|Advanced bibliographic/ }).first();
    await advanced.locator(':scope > summary').click();
    // A stored code comes back selected, shown by name.
    await expect(page.locator('#article-lingua').locator('xpath=ancestor::div[contains(concat(" ",normalize-space(@class)," ")," choices ")][1]').locator('.choices__list--single .choices__item')).toHaveText(/^Danese \(dan\)$/);

    await page.locator('#article-classificazione_schema').selectOption('DDC');
    await expect(page.locator('#article-class-dewey')).toBeVisible();
    await expect(page.locator('#article-classificazione'), 'the text box steps aside').toBeHidden();
    // 33.129 is a DK5 notation, not a Dewey code: it is not carried over.
    await expect(page.locator('#dewey_chip_container')).toBeHidden();
    // The Dewey box searches by subject as well as by code (#412).
    await page.locator('#dewey_manual_input').pressSequentially('mammif');
    await expect(page.locator('#dewey_suggest li').filter({ hasText: /^599 — / })).toBeVisible();
    await page.locator('#dewey_suggest li').filter({ hasText: /^599 — / }).click();
    await expect(page.locator('#dewey_chip_code')).toContainText('599');
    await expect(page.locator('#classificazione_dewey')).toHaveValue('599');
    // Picking a shallower class drops the menus of the old path (500 > 590 > 599).
    await page.locator('#dewey_manual_input').pressSequentially('100');
    await page.locator('#dewey_suggest li').filter({ hasText: /^100 — / }).click();
    await expect(page.locator('#classificazione_dewey')).toHaveValue('100');
    await expect(page.locator('#dewey_levels_container select'), 'only the main classes are left').toHaveCount(1);
    await expect(page.locator('#dewey_levels_container select').first()).toHaveValue('100');
    // And any code can still be typed and added, listed or not.
    await page.locator('#dewey_manual_input').fill('305.8');
    await page.locator('#dewey_add_btn').click();
    await expect(page.locator('#dewey_chip_code')).toContainText('305.8');
    await page.locator('button[type=submit]:has-text("Salva")').first().click();
    await page.waitForURL(/\/admin\/periodicals\/articles\/\d+(\?|$)/);
    expect(db(`SELECT CONCAT_WS('|', classificazione_schema, classificazione) FROM emeroteca_contributi WHERE id=${articleId}`)).toBe('DDC|305.8');

    // Reopened, the Dewey picker shows the stored code.
    await page.goto(`${BASE}/admin/periodicals/articles/${articleId}`);
    await expect(page.locator('#dewey_chip_code')).toContainText('305.8');

    // A scheme outside the list is named, and stored under that name.
    await advanced.locator(':scope > summary').click();
    await page.locator('#article-classificazione_schema').selectOption('__altro');
    await expect(page.locator('#article-scheme-other')).toBeVisible();
    await page.locator('#article-classificazione_schema_altro').fill('SAB');
    await page.locator('#article-classificazione').fill('Kbb');
    await page.locator('button[type=submit]:has-text("Salva")').first().click();
    await page.waitForURL(/\/admin\/periodicals\/articles\/\d+(\?|$)/);
    expect(db(`SELECT CONCAT_WS('|', classificazione_schema, classificazione) FROM emeroteca_contributi WHERE id=${articleId}`)).toBe('SAB|Kbb');
    await page.goto(`${BASE}/admin/periodicals/articles/${articleId}`);
    await expect(page.locator('#article-classificazione_schema')).toHaveValue('__altro');
    await expect(page.locator('#article-classificazione_schema_altro')).toHaveValue('SAB');

    // A Dewey code deeper than the list comes back in the picker as it is.
    db(`UPDATE emeroteca_contributi SET classificazione_schema='DDC', classificazione='823.91409' WHERE id=${articleId}`);
    await page.goto(`${BASE}/admin/periodicals/articles/${articleId}`);
    await expect(page.locator('#dewey_chip_code')).toContainText('823.91409');
    await expect(page.locator('#classificazione_dewey')).toHaveValue('823.91409');
    // A stored notation the picker cannot show stays in the text box, not behind an empty picker.
    db(`UPDATE emeroteca_contributi SET classificazione_schema='DDC', classificazione='823.914 BRO' WHERE id=${articleId}`);
    await page.goto(`${BASE}/admin/periodicals/articles/${articleId}`);
    await advanced.locator(':scope > summary').click();
    await expect(page.locator('#article-classificazione')).toBeVisible();
    await expect(page.locator('#article-classificazione')).toHaveValue('823.914 BRO');
    await expect(page.locator('#article-class-dewey')).toBeHidden();

    // Back to the values the public-page tests below read.
    db(`UPDATE emeroteca_contributi SET classificazione_schema='DK5', classificazione='33.129' WHERE id=${articleId}`);
  });

  test('the public page reads as an analytic record', async ({ page }) => {
    expect(articleId).toBeGreaterThan(0);
    await page.goto(`${BASE}/emeroteca/articolo/${articleId}`);

    // The cookie banner ships a <main> of its own, so the article's is named.
    await expect(page.locator('main[data-articolo-id] h1')).toContainText(marker);
    await expect(page.locator('main[data-articolo-id]')).toContainText('a subtitle that carries half the meaning');
    // The scheme travels with the notation: 33.129 alone means nothing to a
    // reader who does not already know which list it came from.
    await expect(page.locator('main[data-articolo-id]')).toContainText('DK5: 33.129');
    await expect(page.locator('main[data-articolo-id]')).toContainText('Copy / offprint only');

    // The stored code is `dan`; the page must show a language NAME, and it
    // must not show the raw code. That is the whole reason the column holds a
    // code in a per-user multilingual application.
    const languageCell = page.locator('dd', { hasText: /^(danese|Danish|Dänisch|danois|dansk)$/i });
    await expect(languageCell.first(), 'the ISO code is rendered as a name').toBeVisible();

    await expect(page.locator('main[data-articolo-id]')).toContainText(/Cita questo articolo|Cite this article/);
    await expect(page.locator('main[data-articolo-id]')).toContainText('Petersen, H. U. (1988).');
    // One "Cite" button; the dialog holds every style (#412).
    await expect(page.locator('#cite-open')).toBeVisible();
    await expect(page.locator('#cite-list [data-cite-copy]')).toHaveCount(4);
  });

  test('exactly one primary action, and it is never a link a browser cannot follow', async ({ page }) => {
    expect(articleId).toBeGreaterThan(0);
    await page.goto(`${BASE}/emeroteca/articolo/${articleId}`);

    // No PDF on this record, so the external address is the primary action.
    await expect(page.locator('.btn-primary')).toHaveCount(1);
    await expect(page.locator('.btn-primary')).toHaveAttribute('href', 'https://arkiv.example/1988-31.pdf');
    await expect(page.locator('.btn-primary')).toHaveAttribute('rel', /noopener/);
    await expect(page.locator('main[data-articolo-id]')).toContainText('For internal use only');

    // A local path is a reference the library can read and a browser cannot.
    db(`UPDATE emeroteca_contributi SET risorsa_url='\\\\\\\\archivio\\\\scans\\\\1988-31.pdf' WHERE id=${articleId}`);
    await page.reload();
    await expect(page.locator('.btn-primary'), 'an unfollowable path is not promoted to a button').toHaveCount(0);
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

    await page.locator('#cite-open').click();
    await expect(page.locator('#cite-dialog')).toBeVisible();
    const button = page.locator('#cite-list [data-cite-copy]').first();
    const expected = (await button.getAttribute('data-cite-text') || '').trim();
    await button.click();

    await expect(button).toHaveText(/Copiato|Copied|Kopiert|Copié|Kopieret/);
    const clipboard = await page.evaluate(() => navigator.clipboard.readText());
    expect(clipboard.trim(), 'the clipboard holds the citation as rendered').toBe(expected);
  });

  test('the Cite dialog lists every style, filters to one and closes', async ({ page }) => {
    expect(articleId).toBeGreaterThan(0);
    await page.goto(`${BASE}/emeroteca/articolo/${articleId}`);
    await page.locator('#cite-open').click();
    const dialog = page.locator('#cite-dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-cite-style]')).toHaveCount(4);
    // Titles the style italicises are italic in the HTML a word processor receives.
    await expect(dialog.locator('[data-cite-style="apa"] [data-cite-html] i').first()).toHaveText('Arbejderhistorie');
    await dialog.locator('#cite-style').selectOption('mla');
    await expect(dialog.locator('[data-cite-style]:visible')).toHaveCount(1);
    await expect(dialog.locator('[data-cite-style="mla"]')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(page.locator('#cite-open'), 'focus returns to the button that opened it').toBeFocused();
  });

  test('a book page offers the same Cite dialog', async ({ page }) => {
    const bookPath = db(`SELECT id FROM libri WHERE deleted_at IS NULL LIMIT 1`);
    test.skip(!bookPath, 'no book in the catalogue');
    await page.goto(`${BASE}/catalogo`);
    await page.locator('a[href*="/libro"], .book-card a').first().click();
    await page.locator('#cite-open').click();
    await expect(page.locator('#cite-dialog [data-cite-style]')).toHaveCount(4);
    const apa = await page.locator('#cite-dialog [data-cite-style="apa"] [data-cite-copy]').getAttribute('data-cite-text');
    expect(apa, 'a book citation is never empty').toBeTruthy();
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
