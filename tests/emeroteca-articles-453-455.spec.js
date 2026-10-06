// @ts-check
/**
 * #453, #454, #455: articles found, listed and edited the way books are.
 *
 *  - the admin sidebar has an Articles entry (#454);
 *  - the admin quick search finds articles and periodicals, and an article
 *    opens its edit form (#453);
 *  - the author page shows the author's articles as cards with an image,
 *    Details and Edit, like the books (#453);
 *  - the public header search suggests the article (#453);
 *  - the public article page offers staff an Edit button, shows the genre and
 *    lists the author's other works, books included (#453, #455);
 *  - the article form has keywords and genre in the advanced section, and the
 *    genre puts the article in the catalogue filtered by it (#455).
 *
 * Run: /tmp/run-e2e.sh tests/emeroteca-articles-453-455.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || process.env.APP_URL || 'http://localhost:8081';
const RUN = `U453${Date.now().toString(36)}`;
const AUTHOR = `${RUN} Petersen`;
const ARTICLE = `${RUN} Solidaritet med flygtninge`;
const BOOK = `${RUN} Arbejderhistorie bog`;
const MASTHEAD = `${RUN} Arbejderhistorie`;

function db(sql) {
  const args = ['-u', process.env.E2E_DB_USER, process.env.E2E_DB_NAME, '-N', '-B', '-e', sql];
  if (process.env.E2E_DB_SOCKET) args.unshift('-S', process.env.E2E_DB_SOCKET);
  return execFileSync('mysql', args, { encoding: 'utf8', env: { ...process.env, MYSQL_PWD: process.env.E2E_DB_PASS } }).trim();
}

async function login(page) {
  await page.goto(BASE + '/admin/dashboard');
  if (await page.locator('input[name=email]').isVisible()) {
    await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || '');
    await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASS || '');
    await page.locator('button[type=submit]').click();
    await page.waitForURL(u => !u.pathname.includes('accedi') && !u.pathname.includes('login'));
  }
}

// Through the real UI, so onActivate() registers the hooks; checked against the
// database because the activation POST and its dialog race.
async function setEmerotecaActive(page, wanted) {
  const id = Number(db("SELECT id FROM plugins WHERE name='emeroteca'") || '0');
  expect(id, 'emeroteca must be registered as a bundled plugin').toBeGreaterThan(0);
  const active = () => db(`SELECT is_active FROM plugins WHERE id=${id}`) === '1';
  const label = wanted ? 'Attiva plugin' : 'Disattiva';
  for (let attempt = 0; attempt < 3 && active() !== wanted; attempt++) {
    await page.goto(BASE + '/admin/plugins');
    const button = page.locator(`[data-plugin-id="${id}"]`).first().locator(`button:has-text("${label}")`);
    if (!await button.isVisible({ timeout: 3000 }).catch(() => false)) continue;
    await button.click();
    const confirm = page.locator('.swal2-confirm:visible');
    if (await confirm.isVisible({ timeout: 3000 }).catch(() => false)) await confirm.click();
    await expect.poll(() => active() === wanted, { timeout: 30_000 }).toBe(true).catch(() => {});
  }
  expect(active(), `emeroteca could not be ${wanted ? 'activated' : 'deactivated'}`).toBe(wanted);
}

let wasActive = false;
let deepGenres = [];
let authorId = 0;
let bookId = 0;
let articleId = 0;
let mastheadId = 0;
let childGenre = 0;
let parentGenre = 0;

test.describe.serial('Articles like books (#453, #454, #455)', () => {
  /** @type {import('@playwright/test').Page} */
  let admin;

  test.beforeAll(async ({ browser }) => {
    if (!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_DB_USER) throw new Error('Run with /tmp/run-e2e.sh');
    wasActive = db("SELECT COALESCE(MAX(is_active),0) FROM plugins WHERE name='emeroteca'") === '1';
    admin = await browser.newPage();
    await login(admin);
    if (!wasActive) await setEmerotecaActive(admin, true);
    db(`INSERT INTO autori (nome) VALUES ('${AUTHOR}')`);
    authorId = Number(db(`SELECT id FROM autori WHERE nome='${AUTHOR}'`));
    db(`INSERT INTO libri (titolo, anno_pubblicazione) VALUES ('${BOOK}', 1988)`);
    bookId = Number(db(`SELECT id FROM libri WHERE titolo='${BOOK}'`));
    db(`INSERT INTO libri_autori (libro_id, autore_id, ruolo) VALUES (${bookId}, ${authorId}, 'principale')`);
    db(`INSERT INTO emeroteca_testate (titolo, issn) VALUES ('${MASTHEAD}', '0107-8461')`);
    mastheadId = Number(db(`SELECT id FROM emeroteca_testate WHERE titolo='${MASTHEAD}'`));
    db(`INSERT INTO emeroteca_contributi (reference_key, titolo, autori, contenitore_titolo, data_pubblicazione_testo, pagine, keywords, testata_id, pubblico)
        VALUES ('${RUN}-a', '${ARTICLE}', '${AUTHOR}', '${MASTHEAD}', '1988', '18-38', 'fagbevægelsen, nazisme', ${mastheadId}, 1)`);
    articleId = Number(db(`SELECT id FROM emeroteca_contributi WHERE reference_key='${RUN}-a'`));
    db(`INSERT INTO emeroteca_contributi_autori (contributo_id, ordine_credito, autore_id, nome_credito, ruolo) VALUES (${articleId}, 0, ${authorId}, '${AUTHOR}', 'principale')`);
    const genre = db('SELECT id, parent_id FROM generi WHERE parent_id IS NOT NULL ORDER BY id LIMIT 1').split('\t');
    childGenre = Number(genre[0]);
    parentGenre = Number(genre[1]);
    expect(childGenre, 'the installation has a child genre').toBeGreaterThan(0);
  });

  test.afterAll(async () => {
    if (articleId) db(`DELETE FROM emeroteca_contributi_autori WHERE contributo_id=${articleId}; DELETE FROM emeroteca_contributi WHERE id=${articleId}`);
    if (mastheadId) db(`DELETE FROM emeroteca_testate WHERE id=${mastheadId}`);
    if (bookId) db(`DELETE FROM libri_autori WHERE libro_id=${bookId}; DELETE FROM libri WHERE id=${bookId}`);
    if (authorId) db(`DELETE FROM autori WHERE id=${authorId}`);
    for (const id of [...deepGenres].reverse()) db(`DELETE FROM generi WHERE id=${id}`);
    if (!wasActive && admin) await setEmerotecaActive(admin, false);
    await admin?.close();
  });

  test('1 The admin sidebar has an Articles entry that lists the articles (#454)', async () => {
    await admin.goto(BASE + '/admin/dashboard');
    const entry = admin.locator('a.nav-link', { hasText: 'Articoli' }).first();
    await expect(entry).toBeVisible();
    await entry.click();
    await expect(admin).toHaveURL(/\/admin\/periodicals\/articles$/);
    await expect(admin.getByText(ARTICLE).first()).toBeVisible();
    // Only the Articles entry is highlighted, not Periodicals above it, whose
    // address is a prefix of this one.
    await expect(entry).toHaveClass(/bg-rose-50/);
    await expect(admin.locator('a.nav-link[href$="/admin/periodicals"]')).not.toHaveClass(/bg-rose-50/);
    // The title opens the article's page, not its form; Edit is its own icon.
    const row = admin.locator('tr', { hasText: ARTICLE });
    await expect(row.locator(`a[href$="/admin/periodicals/articles/${articleId}/edit"]`)).toHaveCount(1);
    await row.getByRole('link', { name: ARTICLE }).click();
    await expect(admin).toHaveURL(new RegExp(`/admin/periodicals/articles/${articleId}$`));
    await expect(admin.locator(`section[data-article-id="${articleId}"]`)).toBeVisible();
    await expect(admin.locator('#article-titolo')).toHaveCount(0);
  });

  test('2 The admin quick search finds the article and the periodical; the article opens its page (#453)', async () => {
    await admin.goto(BASE + '/admin/dashboard');
    await expect(admin.locator('#global-search')).toHaveAttribute('aria-label', /articoli, periodici/);
    await admin.locator('#global-search').fill(RUN);
    const results = admin.locator('#global-search-results');
    const periodical = results.locator(`a[href$="/admin/periodicals/${mastheadId}/issues"]`);
    const article = results.locator(`a[href$="/admin/periodicals/articles/${articleId}"]`);
    await expect(periodical).toBeVisible({ timeout: 10_000 });
    await expect(periodical.locator('i.fa-newspaper')).toHaveCount(1);
    await expect(article).toBeVisible();
    await expect(article.locator('i.fa-file-alt')).toHaveCount(1);
    // The linked author, in citation form, and where it was published.
    await expect(article).toContainText(`Petersen, ${RUN}`);
    await expect(article).toContainText(MASTHEAD);
    // Articles come before periodicals: the list is cut at 20, and what comes
    // last is what a busy catalogue drops.
    const unified = await (await admin.request.get(`${BASE}/api/search/unified?q=${encodeURIComponent(RUN)}`)).json();
    const articleAt = unified.findIndex(r => r.type === 'article');
    const periodicalAt = unified.findIndex(r => r.type === 'periodical');
    expect(articleAt, 'the article is listed').toBeGreaterThanOrEqual(0);
    expect(periodicalAt, 'after it, the periodical').toBeGreaterThan(articleAt);
    await article.click();
    await expect(admin).toHaveURL(new RegExp(`/admin/periodicals/articles/${articleId}$`));
    await expect(admin.locator('h1', { hasText: ARTICLE })).toBeVisible();
    await expect(admin.locator('#article-titolo')).toHaveCount(0);
  });

  test('3 The author page shows the article as a card with an image, Details and Edit (#453)', async () => {
    await admin.goto(`${BASE}/admin/authors/${authorId}`);
    const card = admin.locator(`article[data-article-id="${articleId}"]`);
    await expect(card).toBeVisible();
    await expect(card.locator('img')).toHaveCount(1);
    await expect(card.locator('h3')).toContainText(ARTICLE);
    await expect(card).toContainText(MASTHEAD);
    // Details opens the article's admin page, as a book's opens the book's.
    await expect(card.locator(`a.btn-primary[href$="/admin/periodicals/articles/${articleId}"]`)).toBeVisible();
    await expect(card.locator(`a.btn-secondary[href$="/admin/periodicals/articles/${articleId}/edit"]`)).toBeVisible();
    // The plain list of links it replaces is gone.
    await expect(admin.locator('ul.space-y-2 a.underline', { hasText: ARTICLE })).toHaveCount(0);
  });

  test('4 The article form has keywords and genre in the advanced section; the genre is saved (#455)', async () => {
    await admin.goto(`${BASE}/admin/periodicals/articles/${articleId}/edit`);
    const advanced = admin.locator('details.article-fold', { hasText: 'Descrizione bibliografica avanzata' });
    // Open by itself: the article already has keywords, now kept in here.
    await expect(advanced).toHaveAttribute('open', '');
    await expect(advanced.locator('#article-keywords')).toHaveValue('fagbevægelsen, nazisme');
    const genre = advanced.locator('#article-genere_id');
    await expect(genre).toBeVisible();
    const label = await genre.locator(`option[value="${childGenre}"]`).textContent();
    expect(label, 'a child genre is listed with its path').toContain('›');
    await genre.selectOption(String(childGenre));
    await admin.locator('form button[type=submit]', { hasText: /Salva/ }).first().click();
    // Saving brings the operator back to the article's page, which shows it.
    await expect(admin).toHaveURL(new RegExp(`/admin/periodicals/articles/${articleId}$`));
    await expect(admin.getByText('Articolo salvato.')).toBeVisible();
    // The genre with its path, root first, as the book page shows it.
    const genreLine = admin.getByTestId('genre-display');
    await expect(genreLine).toContainText(db(`SELECT nome FROM generi WHERE id=${parentGenre}`));
    await expect(genreLine).toContainText(db(`SELECT nome FROM generi WHERE id=${childGenre}`));
    await expect.poll(() => db(`SELECT COALESCE(genere_id,0) FROM emeroteca_contributi WHERE id=${articleId}`)).toBe(String(childGenre));
    expect(db(`SELECT keywords FROM emeroteca_contributi WHERE id=${articleId}`)).toBe('fagbevægelsen, nazisme');
  });

  test('4b The admin article page reads like the book page: the record, then Edit, Delete and the exports (#453, #454)', async () => {
    await admin.goto(`${BASE}/admin/periodicals/articles/${articleId}`);
    const page = admin.locator(`section[data-article-id="${articleId}"]`);
    await expect(page.locator('h1', { hasText: ARTICLE })).toBeVisible();
    await expect(page.locator(`a[href$="/admin/authors/${authorId}"]`)).toContainText(AUTHOR.split(' ')[0]);
    await expect(page.locator(`a[href$="/admin/periodicals/${mastheadId}/issues"]`)).toContainText(MASTHEAD);
    await expect(page.locator('dd', { hasText: 'fagbevægelsen' })).toBeVisible();
    await expect(page.locator('dd', { hasText: '18-38' })).toBeVisible();
    await expect(page.locator(`a[href$="/emeroteca/articolo/${articleId}"]`, { hasText: 'Vedi pagina pubblica' })).toBeVisible();
    await expect(page.locator(`a[href$="/admin/periodicals/articles/${articleId}/citation.ris"]`)).toBeVisible();
    await expect(page.locator(`form[action$="/admin/periodicals/articles/${articleId}/delete"]`)).toHaveCount(1);
    // Nothing on it is an input: the record is changed in the form.
    await expect(page.locator('input:not([type=hidden]), textarea, select')).toHaveCount(0);
    // Edit and Cancel make the round trip without saving anything.
    await page.getByTestId('article-edit').click();
    await expect(admin).toHaveURL(new RegExp(`/admin/periodicals/articles/${articleId}/edit$`));
    await admin.getByRole('link', { name: 'Annulla' }).click();
    await expect(admin).toHaveURL(new RegExp(`/admin/periodicals/articles/${articleId}$`));
  });

  test('5 The public article page offers staff an Edit button, shows the genre and the author\'s other works, books included (#453, #455)', async () => {
    await admin.goto(`${BASE}/emeroteca/articolo/${articleId}`);
    const edit = admin.locator(`a[href$="/admin/periodicals/articles/${articleId}/edit"]`, { hasText: 'Modifica' });
    await expect(edit).toBeVisible();
    await expect(admin.locator(`.meta-item a[href*="genere_id=${childGenre}"]`)).toBeVisible();
    await expect(admin.locator(`.meta-item a[href*="genere_id=${parentGenre}"]`)).toBeVisible();
    const others = admin.locator('.listing-section', { hasText: 'Altre opere di' });
    await expect(others).toBeVisible();
    await expect(others.locator('.book-card', { hasText: BOOK })).toBeVisible();
    await expect(others.locator(`a[href$="/${authorId}"]`, { hasText: 'Tutte' })).toBeVisible();
    await edit.click();
    await expect(admin).toHaveURL(new RegExp(`/admin/periodicals/articles/${articleId}/edit$`));
    await expect(admin.locator('#article-titolo')).toHaveValue(ARTICLE);
  });

  test('6 A visitor sees no Edit button, and the header search suggests the article (#453, #455)', async ({ browser }) => {
    const visitor = await (await browser.newContext()).newPage();
    try {
      await visitor.goto(`${BASE}/emeroteca/articolo/${articleId}`);
      await expect(visitor.locator('h1', { hasText: ARTICLE })).toBeVisible();
      await expect(visitor.locator(`a[href*="/admin/periodicals/articles/"]`)).toHaveCount(0);
      const preview = await visitor.request.get(`${BASE}/api/search/preview?q=${encodeURIComponent(RUN)}`);
      const items = await preview.json();
      const hit = items.find(r => r.type === 'article' && r.label === ARTICLE);
      expect(hit, 'the header search returns the article').toBeTruthy();
      expect(hit.url).toMatch(new RegExp(`/emeroteca/articolo/${articleId}$`));
      expect(items.some(r => r.type === 'periodical'), 'periodicals stay out of the public suggestions').toBe(false);
      await visitor.goto(BASE + '/');
      await visitor.locator('.search-input').first().fill(RUN);
      const dropdown = visitor.locator('.search-section', { hasText: 'Articoli' });
      await expect(dropdown.locator('a.article-result', { hasText: ARTICLE })).toBeVisible({ timeout: 10_000 });
    } finally {
      await visitor.context().close();
    }
  });

  test('8 Books and articles follow one genre rule, at any depth (#455)', async ({ browser }) => {
    // A tree of its own, four levels deep, so the root lists only these two.
    let parent = 'NULL';
    for (const level of [1, 2, 3, 4]) {
      db(`INSERT INTO generi (nome, parent_id) VALUES ('${RUN} Level ${level}', ${parent})`);
      deepGenres.push(Number(db(`SELECT id FROM generi WHERE nome='${RUN} Level ${level}'`)));
      parent = String(deepGenres[deepGenres.length - 1]);
    }
    const [root, , , leaf] = deepGenres;
    db(`UPDATE emeroteca_contributi SET genere_id=${leaf} WHERE id=${articleId}`);
    db(`UPDATE libri SET genere_id=${leaf} WHERE id=${bookId}`);
    const visitor = await (await browser.newContext()).newPage();
    try {
      await visitor.goto(`${BASE}/catalogo?genere_id=${root}`);
      await expect(visitor.locator(`[data-article-id="${articleId}"]`), 'the article').toBeVisible();
      await expect(visitor.locator('.book-card', { hasText: BOOK }), 'and the book').toBeVisible();
      // The sidebar counts follow the same rule: the root's child counts the
      // book three levels below the root, and the third level lists the leaf.
      // A selected genre folds its facet into a pill; "Change" opens it.
      const genres = visitor.locator('#genres-filter');
      const option = (name) => genres.locator('.filter-option', { hasText: name }).locator('.count-badge');
      await genres.locator('.facet-change-link').click();
      await expect(option(`${RUN} Level 2`), 'the root lists its child with the book counted').toHaveText('1');
      await visitor.goto(`${BASE}/catalogo?genere_id=${deepGenres[2]}`);
      await genres.locator('.facet-change-link').click();
      await expect(option(`${RUN} Level 4`), 'the third level lists the leaf').toHaveText('1');
    } finally {
      await visitor.context().close();
      db(`UPDATE libri SET genere_id=NULL WHERE id=${bookId}`);
      db(`UPDATE emeroteca_contributi SET genere_id=${childGenre} WHERE id=${articleId}`);
    }
  });

  test('7 The catalogue filtered by the genre, or by its parent, lists the article (#455)', async ({ browser }) => {
    const visitor = await (await browser.newContext()).newPage();
    try {
      for (const genreId of [childGenre, parentGenre]) {
        await visitor.goto(`${BASE}/catalogo?genere_id=${genreId}&q=${encodeURIComponent(RUN)}`);
        await expect(visitor.locator(`[data-article-id="${articleId}"]`), `genre ${genreId}`).toBeVisible();
      }
    } finally {
      await visitor.context().close();
    }
  });
});
