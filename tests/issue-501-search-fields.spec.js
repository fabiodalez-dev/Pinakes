// @ts-check
/** Each advanced backend text box and every book-list suggestion bar. */
const { test, expect } = require('@playwright/test');
// e2e-fixtures throws while loading when the E2E environment is missing, so
// it is required only when the suite is going to run; the skip reports why.
const E2E_READY = Boolean(process.env.E2E_BASE_URL && process.env.E2E_DB_USER && process.env.E2E_DB_NAME
  && (process.env.E2E_DB_HOST || process.env.E2E_DB_SOCKET));
test.skip(!E2E_READY, 'E2E environment not configured (use /tmp/run-e2e.sh)');
const { createTempAdminUser, deleteTempAdminUser } = E2E_READY ? require('./helpers/e2e-fixtures') : {};
const { makeToken, seed, clean, login, typeAndWait, expectTitles } = require('./helpers/exact-search-501');
const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const enc = encodeURIComponent;

test.describe.serial('#501 individual backend search fields', () => {
  let f, admin, context, page;
  test.beforeAll(async ({ browser }) => {
    f = seed(makeToken(), true);
    admin = createTempAdminUser('it_IT');
    context = await browser.newContext(); page = await context.newPage();
    await login(page, BASE, admin.email, admin.password);
  });
  test.afterAll(async () => { await context?.close(); clean(f); deleteTempAdminUser(admin?.id); });

  for (const [route, field, advanced, api, kind] of [
    ['authors', 'search_pseudonimo', true, '/api/autori', 'author'],
    ['authors', 'search_nazionalita', false, '/api/autori', 'author'],
    ['authors', 'search_sito', true, '/api/autori', 'author'],
    ['publishers', 'search_sito', true, '/api/editori', 'publisher'],
    ['publishers', 'search_via', true, '/api/editori', 'publisher'],
    ['publishers', 'search_cap', true, '/api/editori', 'publisher'],
  ]) {
    for (const quoted of [false, true]) {
      test(`${route} #${field}: ${quoted ? 'quoted' : 'tolerant'}`, async () => {
        await page.goto(`${BASE}/admin/${route}`);
        if (advanced) await page.locator('#toggle-advanced').click();
        const value = `${f.token} haffner`;
        await typeAndWait(page, `#${field}`, quoted ? `"${value}"` : value, api);
        const plain = kind === 'author' ? f.authorPlain : f.publisherPlain;
        const umlaut = kind === 'author' ? f.authorUmlaut : f.publisherUmlaut;
        await expectTitles(page.locator('main'), quoted ? [plain] : [plain, umlaut], quoted ? [umlaut] : []);
      });
    }
  }
  for (const [field, list, api, plain, umlaut] of [
    ['filter_autore', 'filter_autore_suggest', '/api/search/autori', 'authorPlain', 'authorUmlaut'],
    ['filter_editore', 'filter_editore_suggest', '/api/search/editori', 'publisherPlain', 'publisherUmlaut'],
  ]) {
    for (const quoted of [false,true]) {
      test(`book list #${field}: ${quoted ? 'quoted' : 'tolerant'}`, async () => {
        await page.goto(`${BASE}/admin/books`);
        await typeAndWait(page, `#${field}`, `${f.token} ${quoted ? '"häffner"' : 'haffner'}`, api);
        await expectTitles(page.locator(`#${list}`), quoted ? [f[umlaut]] : [f[plain],f[umlaut]], quoted ? [f[plain]] : []);
      });
    }
  }
  for (const [field,list,api] of [
    ['filter_genere','filter_genere_suggest','/api/search/generi'],
    ['filter_posizione','filter_posizione_suggest','/api/search/collocazione'],
  ]) {
    for (const quoted of [false,true]) {
      test(`book list #${field}: ${quoted ? 'quoted' : 'tolerant'}`,async () => {
        await page.goto(`${BASE}/admin/books`);
        if(field==='filter_posizione') await page.locator('#toggle-advanced').click();
        const value=`${f.token} haffner`;
        await typeAndWait(page,`#${field}`,quoted ? `"${value}"` : value,api);
        const region=page.locator(`#${list}`);
        if(field==='filter_genere') {
          await expectTitles(region,quoted ? [f.authorPlain] : [f.authorPlain,f.authorUmlaut],quoted ? [f.authorUmlaut] : []);
        } else {
          await expect(region).toContainText(f.token+'0');
          if(quoted) await expect(region).not.toContainText(f.token+'1');
          else await expect(region).toContainText(f.token+'1');
        }
      });
    }
  }
  test('ISBN box accepts quotes and treats % literally', async () => {
    await page.goto(`${BASE}/admin/books`);
    await typeAndWait(page,'#search_isbn',`"${f.token.toLowerCase()}1"`,'/api/libri');
    await expectTitles(page.locator('main'),[f.titleUmlaut],[f.titlePlain]);
    await typeAndWait(page,'#search_isbn','%','/api/libri');
    await expectTitles(page.locator('main'),[],[f.titlePlain,f.titleUmlaut]);
  });
  for (const format of ['csv','librarything']) {
    test(`${format} export accepts mixed quoted and unquoted terms`, async () => {
      const q = `${f.token} "häffner"`;
      const response = await page.request.get(`${BASE}/admin/books/export/${format}?search=${enc(q)}`);
      expect(response.status()).toBe(200);
      const body=await response.text(); expect(body).toContain(f.titleUmlaut); expect(body).not.toContain(f.titlePlain);
    });
  }
  test('user list and CSV accept the full displayed borrower name',async () => {
    const q=`"${f.userUmlaut}"`;
    await page.goto(`${BASE}/admin/users`);
    await typeAndWait(page,'#search_text',q,'/api/utenti');
    await expect(page.locator('main')).toContainText('Häffner');
    await expect(page.locator('main tr').filter({hasText:f.token}).filter({hasText:'Haffner'})).toHaveCount(0);
    const response=await page.request.get(`${BASE}/admin/users/export/csv?search_text=${enc(q)}`);
    expect(response.status()).toBe(200);
    const body=await response.text();expect(body).toContain(f.token.toLowerCase()+'.1@example.test');expect(body).not.toContain(f.token.toLowerCase()+'.0@example.test');
  });
  test('mobile backend global search uses the same quoted terms',async () => {
    await page.setViewportSize({width:390,height:844});
    await page.goto(`${BASE}/admin/dashboard`);
    await page.locator('#mobile-search-button').click();
    await typeAndWait(page,'#mobile-global-search',`${f.token} "häffner"`,'/api/search/unified');
    await expectTitles(page.locator('#mobile-search-results'),[f.authorUmlaut],[f.authorPlain]);
  });
  test('homepage hero search submits the quoted query to the catalogue',async () => {
    await page.setViewportSize({width:1280,height:900});await page.goto(BASE);
    const field=page.locator('.hero-search-input');
    await field.fill(`${f.token} "häffner"`);
    await Promise.all([page.waitForURL(/catalogo/),field.press('Enter')]);
    await expectTitles(page.locator('main'),[f.titleUmlaut],[f.titlePlain]);
  });
});
