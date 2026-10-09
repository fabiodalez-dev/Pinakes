// @ts-check
/**
 * E2E — OpenURL Z39.88 Resolver + COinS plugin tests (v0.7.2)
 *
 * Covers:
 *  1. Plugin registered in plugins table
 *  2. GET /openurl?rft.btitle=... → 302 redirect to external resolver
 *  3. GET /openurl with no params → 302 redirect (graceful fallback)
 *  4. GET /openurl?rft_val_fmt=...&rft.btitle=&rft.au= → valid redirect
 *  5. GET /api/coins/book/{id} → 200 with JSON containing coins_title and coins_html
 *  6. COinS title contains ctx_ver=Z39.88-2004
 *  7. COinS title contains rft_val_fmt=info:ofi/fmt:kev:mtx:book
 *  8. COinS HTML contains <span class="Z3988"
 *  9. GET /api/coins/book/9999999 → 404
 * 10. COinS is injected on book detail page (script tag present in <head>)
 * 11-16. Journal articles (#412): a journal request is resolved against the
 *        Emeroteca's standalone articles instead of being refused, by DOI and
 *        by exact title, under both spellings PHP can produce for rft.atitle;
 *        an article carries its own mtx:journal COinS.
 * 18. rfr_id never follows a forged Host header (COinS are cached public);
 *     no rft.language in the mtx:book KEV.
 * 19. OpenURL 0.1 legacy keys: aulast/aufirst/auinit, id=isbn:…, id=doi:….
 *
 * Run: /tmp/run-e2e.sh tests/openurl-resolver.spec.js --config=tests/playwright.config.js --workers=1
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE      = process.env.E2E_BASE_URL  || 'http://localhost:8081';
const DB_USER   = process.env.E2E_DB_USER   || '';
const DB_PASS   = process.env.E2E_DB_PASS   || '';
const DB_NAME   = process.env.E2E_DB_NAME   || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

function mysqlArgs(sql, batch = false) {
    const args = [];
    if (DB_SOCKET) args.push('-S', DB_SOCKET);
    args.push('-u', DB_USER, DB_NAME);
    if (batch) args.push('-N', '-B');
    if (sql !== '') args.push('-e', sql);
    return args;
}
const MYSQL_ENV = () => ({ ...process.env, MYSQL_PWD: DB_PASS });
function dbQuery(sql) {
    return execFileSync('mysql', mysqlArgs(sql, true), {
        encoding: 'utf-8', timeout: 10000, env: MYSQL_ENV(),
    }).trim();
}

async function loginAsAdmin(page) {
    await page.goto(`${BASE}/admin/plugins`);
    if (await page.locator('input[name=email]').isVisible()) {
        await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || '');
        await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASS || '');
        await page.locator('button[type=submit]').click();
        await page.waitForURL(u => !u.pathname.includes('accedi') && !u.pathname.includes('login'));
    }
}

// The article tests need the Emeroteca switched on, not just its table: a
// spec that activated and then deactivated it leaves the table behind with
// the plugin off, and the resolver then rightly sends every article request
// off-site. So this suite sets the state it needs, through the real UI so
// onActivate() builds the schema, and puts it back afterwards. "Attiva
// plugin", not "Attiva": "Disattiva" contains it.
async function setEmerotecaActive(page, wanted) {
    const id = Number(dbQuery("SELECT id FROM plugins WHERE name='emeroteca'") || '0');
    if (id === 0) return false;
    const active = () => dbQuery(`SELECT is_active FROM plugins WHERE id=${id}`) === '1';
    const label = wanted ? 'Attiva plugin' : 'Disattiva';
    for (let attempt = 0; attempt < 3 && active() !== wanted; attempt++) {
        await page.goto(`${BASE}/admin/plugins`);
        const button = page.locator(`[data-plugin-id="${id}"]`).first().locator(`button:has-text("${label}")`);
        if (!await button.isVisible({ timeout: 3000 }).catch(() => false)) continue;
        await button.click();
        const confirm = page.locator('.swal2-confirm:visible');
        if (await confirm.isVisible({ timeout: 3000 }).catch(() => false)) await confirm.click();
        await expect.poll(() => active() === wanted, { timeout: 30_000 }).toBe(true).catch(() => {});
    }
    return active() === wanted;
}

test.skip(
    !DB_USER || !DB_NAME,
    'Missing E2E env (DB_*)'
);

test.describe.serial('OpenURL Z39.88 Resolver + COinS plugin — v0.7.2 (10 tests)', () => {
    /** @type {number} */
    let testBookId = 0;

    /** Whether this suite switched the Emeroteca on, and so must switch it off. */
    let activatedEmeroteca = false;

    test.beforeAll(async ({ browser }) => {
        // Use a book known to exist in the DB (fallback to query).
        const result = dbQuery(
            "SELECT id FROM libri WHERE deleted_at IS NULL ORDER BY id LIMIT 1"
        );
        testBookId = parseInt(result) || 0;

        const emerotecaWasActive = dbQuery("SELECT COALESCE(MAX(is_active),0) FROM plugins WHERE name='emeroteca'") === '1';
        if (!emerotecaWasActive && process.env.E2E_ADMIN_EMAIL) {
            const page = await browser.newPage();
            try {
                await loginAsAdmin(page);
                activatedEmeroteca = await setEmerotecaActive(page, true);
            } finally {
                await page.close();
            }
        }

        const hasArticles = dbQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emeroteca_contributi'"
        ) === '1' && dbQuery("SELECT COALESCE(MAX(is_active),0) FROM plugins WHERE name='emeroteca'") === '1';
        if (hasArticles) {
            dbQuery(
                `INSERT INTO emeroteca_contributi (reference_key, titolo, autori, contenitore_titolo, numero, pagine, anno_pubblicazione, lingua, doi, pubblico)
                 VALUES ('openurl412-${Date.now()}', '${articleTitle}', 'Petersen, Hans Uwe', 'Arbejderhistorie', '31', '18-38', 1988, 'dan', '${articleDoi}', 1)`
            );
            articleId = parseInt(dbQuery(`SELECT id FROM emeroteca_contributi WHERE titolo='${articleTitle}'`)) || 0;
        }
    });

    test.afterAll(async ({ browser }) => {
        if (articleId > 0) dbQuery(`DELETE FROM emeroteca_contributi WHERE id=${articleId}`);
        if (activatedEmeroteca) {
            const page = await browser.newPage();
            try {
                await loginAsAdmin(page);
                expect(await setEmerotecaActive(page, false), 'the Emeroteca this suite activated is deactivated again').toBe(true);
            } finally {
                await page.close();
            }
        }
    });

    /**
     * A published standalone article to resolve against. Emeroteca may be
     * inactive or absent — it is an optional plugin — in which case these
     * cases skip rather than fail: the resolver's contract is that it never
     * breaks when the article table is not there.
     */
    let articleId = 0;
    const articleTitle = `OpenUrl412 ${Date.now()}`;
    const articleDoi = `10.5555/openurl412.${Date.now()}`;

    // ── Test 1: Plugin registration ──────────────────────────────────────────

    test('1. openurl-resolver plugin registered in plugins table', async () => {
        const name = dbQuery("SELECT name FROM plugins WHERE name = 'openurl-resolver'");
        expect(name).toBe('openurl-resolver');
    });

    // ── Tests 2-4: /openurl resolver ─────────────────────────────────────────

    test('2. GET /openurl?rft.btitle=... → 302 redirect to external resolver', async ({ request }) => {
        const res = await request.get(
            `${BASE}/openurl?rft_val_fmt=info%3Aofi%2Ffmt%3Akev%3Amtx%3Abook&rft.btitle=Umberto+Eco&rft.au=Eco`,
            { maxRedirects: 0 }
        );
        expect(res.status()).toBe(302);
        const location = res.headers()['location'] ?? '';
        expect(location).toBeTruthy();
        // Should redirect to worldcat, google books, or local libro page
        expect(location.length).toBeGreaterThan(5);
    });

    test('3. GET /openurl with no params → 302 graceful fallback', async ({ request }) => {
        const res = await request.get(`${BASE}/openurl`, { maxRedirects: 0 });
        expect(res.status()).toBe(302);
        const location = res.headers()['location'] ?? '';
        expect(location).toBeTruthy();
    });

    test('4. GET /openurl with ISBN → 302 redirect', async ({ request }) => {
        // Use any ISBN (even non-existent triggers external fallback gracefully)
        const res = await request.get(
            `${BASE}/openurl?rft.isbn=9780141182605`,
            { maxRedirects: 0 }
        );
        expect(res.status()).toBe(302);
    });

    // ── Tests 5-8: /api/coins/book/{id} ──────────────────────────────────────

    test('5. GET /api/coins/book/{id} → 200 JSON with coins_title and coins_html', async ({ request }) => {
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/api/coins/book/${testBookId}`);
        expect(res.status()).toBe(200);
        const ct = res.headers()['content-type'] ?? '';
        expect(ct).toContain('application/json');
        const json = await res.json();
        expect(json).toHaveProperty('coins_title');
        expect(json).toHaveProperty('coins_html');
        expect(json).toHaveProperty('book_id', testBookId);
    });

    test('6. COinS title contains ctx_ver=Z39.88-2004', async ({ request }) => {
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/api/coins/book/${testBookId}`);
        const json = await res.json();
        expect(json.coins_title).toContain('ctx_ver=Z39.88-2004');
    });

    test('7. COinS title contains rft_val_fmt=info:ofi/fmt:kev:mtx:book', async ({ request }) => {
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/api/coins/book/${testBookId}`);
        const json = await res.json();
        // The value is URL-encoded in the title string
        expect(json.coins_title).toContain('rft_val_fmt=');
        expect(json.coins_title).toContain('book');
    });

    test('8. COinS HTML contains <span class="Z3988"', async ({ request }) => {
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/api/coins/book/${testBookId}`);
        const json = await res.json();
        expect(json.coins_html).toContain('class="Z3988"');
        expect(json.coins_html).toContain('<span');
    });

    // ── Test 9: 404 handling ──────────────────────────────────────────────────

    test('9. GET /api/coins/book/9999999 → 404', async ({ request }) => {
        const res = await request.get(`${BASE}/api/coins/book/9999999`);
        expect(res.status()).toBe(404);
        const json = await res.json();
        expect(json).toHaveProperty('error', true);
    });

    // ── Test 10: COinS injection script ──────────────────────────────────────

    test('10. COinS injection script tag is present in book detail page <head>', async ({ page }) => {
        test.skip(testBookId === 0, 'No book in DB');
        await page.goto(`${BASE}/libro/${testBookId}`, { waitUntil: 'domcontentloaded' });

        // The injected script builds its endpoint from the kind of record the
        // page turns out to be, so the base path is the literal to look for —
        // it used to be '/api/coins/book/' and stopped being a literal when
        // the script learned about articles.
        const headContent = await page.evaluate(() => document.head.innerHTML);
        expect(headContent).toContain('/api/coins/');
        expect(headContent).toContain('data-libro-id');
        expect(headContent, 'the same script also recognises an article page').toContain('data-articolo-id');

        // And it must actually resolve to the book endpoint on a book page.
        await expect.poll(
            async () => page.evaluate(() => document.querySelectorAll('span.Z3988').length),
            { timeout: 10_000 },
        ).toBeGreaterThan(0);
        const kev = await page.evaluate(() => (document.querySelector('span.Z3988') || { title: '' }).title);
        expect(decodeURIComponent(kev)).toContain('info:ofi/fmt:kev:mtx:book');
    });

    // ── Tests 11-16: journal articles (#412) ─────────────────────────────────

    test('11. a journal request is no longer refused outright', async ({ request }) => {
        const r = await request.get(`${BASE}/openurl?url_ver=Z39.88-2004&rft_val_fmt=info:ofi/fmt:kev:mtx:journal&rft.atitle=Nothing+Here+At+All`, { maxRedirects: 0 });
        // It used to answer 400 "books only". The question a researcher asks a
        // link resolver — do you have this article — was the one it refused.
        expect(r.status()).toBe(302);
        const location = r.headers()['location'];
        expect(location).toContain('worldcat');
        // `toContain('worldcat')` alone is true of the bare search URL with no
        // query at all, so it passes whether or not the title survived. Assert
        // the title reached the query: an off-site handover that forgets what
        // the researcher asked for is a dead end dressed up as a redirect.
        expect(decodeURIComponent(location)).toContain('Nothing Here At All');
    });

    test('12. an exact title resolves to the local article', async ({ request }) => {
        test.skip(articleId === 0, 'Emeroteca standalone articles are not available');
        const r = await request.get(`${BASE}/openurl?rft_val_fmt=info:ofi/fmt:kev:mtx:journal&rft.atitle=${encodeURIComponent(articleTitle)}`, { maxRedirects: 0 });
        expect(r.status()).toBe(302);
        expect(r.headers()['location']).toContain(`/emeroteca/articolo/${articleId}`);
    });

    test('13. and so does the spelling PHP actually produces', async ({ request }) => {
        test.skip(articleId === 0, 'Emeroteca standalone articles are not available');
        // PHP turns the dot in rft.atitle into an underscore while parsing the
        // query string, so the dotted key a reader of the specification would
        // send never exists in $_GET. Both spellings have to work, and this is
        // the case that fails if only the documented one is read.
        const r = await request.get(`${BASE}/openurl?rft_val_fmt=info:ofi/fmt:kev:mtx:journal&rft_atitle=${encodeURIComponent(articleTitle)}`, { maxRedirects: 0 });
        expect(r.status()).toBe(302);
        expect(r.headers()['location']).toContain(`/emeroteca/articolo/${articleId}`);
    });

    test('14. a DOI resolves without a title', async ({ request }) => {
        test.skip(articleId === 0, 'Emeroteca standalone articles are not available');
        const r = await request.get(`${BASE}/openurl?rft_val_fmt=info:ofi/fmt:kev:mtx:journal&rft_id=${encodeURIComponent('info:doi/' + articleDoi)}`, { maxRedirects: 0 });
        expect(r.status()).toBe(302);
        expect(r.headers()['location']).toContain(`/emeroteca/articolo/${articleId}`);
    });

    test('15. a near-miss title goes to the publisher, not to the wrong paper', async ({ request }) => {
        test.skip(articleId === 0, 'Emeroteca standalone articles are not available');
        // Matching is exact on purpose. Sending a reader to a different paper
        // with a similar name is worse than sending them off-site.
        const fragment = articleTitle.slice(0, 8);
        const r = await request.get(`${BASE}/openurl?rft_val_fmt=info:ofi/fmt:kev:mtx:journal&rft_atitle=${encodeURIComponent(fragment)}`, { maxRedirects: 0 });
        expect(r.status()).toBe(302);
        const location = r.headers()['location'];
        expect(location).toContain('worldcat');
        expect(location).not.toContain('/emeroteca/articolo/');
        // And it hands the fragment over rather than dropping it.
        expect(decodeURIComponent(location)).toContain(fragment);
    });

    test('16. an article carries its own mtx:journal COinS', async ({ request }) => {
        test.skip(articleId === 0, 'Emeroteca standalone articles are not available');
        const r = await request.get(`${BASE}/api/coins/article/${articleId}`);
        expect(r.status()).toBe(200);
        const body = await r.json();
        const kev = decodeURIComponent(String(body.coins_title).replace(/\+/g, ' '));
        expect(kev).toContain('info:ofi/fmt:kev:mtx:journal');
        expect(kev).toContain('rft.genre=article');
        expect(kev).toContain('rft.jtitle=Arbejderhistorie');
        expect(kev).toContain('rft.spage=18');
        expect(kev).toContain('rft.epage=38');
        // rft.language is not a key of the mtx:journal KEV matrix.
        expect(kev).not.toContain('rft.language');
        expect(String(body.coins_html)).toContain('<span class="Z3988"');

        const missing = await request.get(`${BASE}/api/coins/article/9999999`);
        expect(missing.status()).toBe(404);
    });

    test('17. journal and book-item requests keep to their own kind of record', async ({ request }) => {
        test.skip(articleId === 0, 'Emeroteca standalone articles are not available');
        // A chapter of an anthology sharing nothing but its title with the
        // request must not answer a journal request, and a journal article must
        // not answer a book-item request.
        const chapterTitle = `OpenUrlChapter412 ${Date.now()}`;
        dbQuery(`INSERT INTO emeroteca_contributi (reference_key, titolo, contenitore_titolo, contenitore_tipo, isbn, pubblico)
                 VALUES ('openurl412c-${Date.now()}', '${chapterTitle}', 'Exil in Dänemark', 'antologia', '9780306406157', 1)`);
        const chapterId = parseInt(dbQuery(`SELECT id FROM emeroteca_contributi WHERE titolo='${chapterTitle}'`)) || 0;
        try {
            const asJournal = await request.get(`${BASE}/openurl?rft_val_fmt=info:ofi/fmt:kev:mtx:journal&rft.atitle=${encodeURIComponent(chapterTitle)}`, { maxRedirects: 0 });
            expect(asJournal.status()).toBe(302);
            expect(asJournal.headers()['location'], 'a journal request does not land on a chapter').not.toContain(`/emeroteca/articolo/${chapterId}`);

            const asChapter = await request.get(`${BASE}/openurl?rft_val_fmt=info:ofi/fmt:kev:mtx:book&rft.genre=bookitem&rft.atitle=${encodeURIComponent(chapterTitle)}&rft.isbn=9780306406157`, { maxRedirects: 0 });
            expect(asChapter.headers()['location'], 'the book-item request finds the chapter').toContain(`/emeroteca/articolo/${chapterId}`);

            const otherVolume = await request.get(`${BASE}/openurl?rft_val_fmt=info:ofi/fmt:kev:mtx:book&rft.genre=bookitem&rft.atitle=${encodeURIComponent(chapterTitle)}&rft.isbn=9788842935780`, { maxRedirects: 0 });
            expect(otherVolume.headers()['location'], 'a chapter of another volume does not answer').not.toContain(`/emeroteca/articolo/${chapterId}`);

            const journalAsChapter = await request.get(`${BASE}/openurl?rft_val_fmt=info:ofi/fmt:kev:mtx:book&rft.genre=bookitem&rft.atitle=${encodeURIComponent(articleTitle)}`, { maxRedirects: 0 });
            expect(journalAsChapter.headers()['location'], 'a book-item request does not land on a journal article').not.toContain(`/emeroteca/articolo/${articleId}`);
        } finally {
            if (chapterId > 0) dbQuery(`DELETE FROM emeroteca_contributi WHERE id=${chapterId}`);
        }
    });

    test('18. rfr_id comes from the canonical host, never from a forged Host header', async ({ request }) => {
        test.skip(testBookId === 0, 'No book in DB');
        const r = await request.get(`${BASE}/api/coins/book/${testBookId}`, {
            headers: { Host: 'evil.example.test' },
        });
        expect(r.status()).toBe(200);
        const kev = decodeURIComponent(String((await r.json()).coins_title));
        expect(kev).not.toContain('evil.example.test');
        const canonical = new URL(BASE).hostname;
        expect(kev).toContain(`rfr_id=info:sid/${canonical}:pinakes`);
        // rft.language is not a key of the mtx:book KEV matrix either.
        expect(kev).not.toContain('rft.language');
    });

    test('19. OpenURL 0.1 legacy keys are honoured', async ({ request }) => {
        // aulast / aufirst / auinit reach the external search.
        const legacy = await request.get(`${BASE}/openurl?genre=book&title=Legacy+Title+${Date.now()}&aulast=Eco&aufirst=Umberto&auinit=U`, { maxRedirects: 0 });
        expect(legacy.status()).toBe(302);
        const location = decodeURIComponent(legacy.headers()['location'] ?? '');
        expect(location).toContain('worldcat');
        expect(location).toContain('Eco');
        expect(location).toContain('Umberto');

        // id=isbn:… resolves to the local book.
        const prefix = `97888${String(Date.now()).slice(-7)}`;
        const digits = prefix.split('').map(Number);
        const sum = digits.reduce((acc, d, i) => acc + d * (i % 2 === 0 ? 1 : 3), 0);
        const isbn = prefix + String((10 - (sum % 10)) % 10);
        const title = `OpenUrlLegacy ${Date.now()}`;
        dbQuery(`INSERT INTO libri (titolo, isbn13, copie_totali, copie_disponibili, created_at) VALUES ('${title}', '${isbn}', 1, 1, NOW())`);
        const bookId = parseInt(dbQuery(`SELECT id FROM libri WHERE isbn13='${isbn}' AND deleted_at IS NULL LIMIT 1`)) || 0;
        try {
            expect(bookId).toBeGreaterThan(0);
            const byId = await request.get(`${BASE}/openurl?genre=book&id=${encodeURIComponent('isbn:' + isbn)}`, { maxRedirects: 0 });
            expect(byId.status()).toBe(302);
            const to = byId.headers()['location'] ?? '';
            expect(to).not.toContain('google');
            expect(to).toMatch(new RegExp(`/${bookId}$`));
        } finally {
            if (bookId > 0) dbQuery(`UPDATE libri SET deleted_at=NOW(), isbn10=NULL, isbn13=NULL, ean=NULL WHERE id=${bookId}`);
        }

        // id=doi:… resolves to the local article, without rft_val_fmt.
        if (articleId > 0) {
            const byDoi = await request.get(`${BASE}/openurl?genre=article&id=${encodeURIComponent('doi:' + articleDoi)}`, { maxRedirects: 0 });
            expect(byDoi.status()).toBe(302);
            expect(byDoi.headers()['location']).toContain(`/emeroteca/articolo/${articleId}`);
        }
    });
});
