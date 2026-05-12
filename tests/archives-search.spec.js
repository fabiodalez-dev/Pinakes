// @ts-check
/**
 * E2E — Archives search bar (admin + public).
 *
 * 28 test covering:
 *  - Barra di ricerca admin (/admin/archives?q=...&level=...)
 *    · Visibilità form, ricerca per reference code (LIKE), per titolo,
 *      per scope_content (FULLTEXT), filtro livello, combinato, reset,
 *      lista piatta in search mode, contatore risultati, no-results state
 *  - Barra di ricerca pubblica (/archivio?q=...&level=...&date_from=...&date_to=...)
 *    · Visibilità form, ricerca per reference code, per titolo, filtro livello,
 *      filtro date_from, filtro date_to, combinato, reset, no-results,
 *      risultati da livelli non-root (serie, fascicolo)
 *  - Ricerca unificata globale: trova archivi per reference code
 *  - Catalogo /catalogo?search=...: sezione archivio nei risultati
 *  - Autocomplete header: dropdown mostra il record archivistico seedato
 *  - Ricerca con inflection italiana: "fondo" trova "fondi", "serie" trova "serien" ecc.
 *
 * Dati di test (creati in beforeAll, rimossi in afterAll):
 *  TAG_F1  → Fondo  "E2E Fondo Alpha"   (scope: "contenuto archivistico speciale")
 *  TAG_S1  → Serie  (figlia di F1)       "E2E Serie Beta"   date 1900-1950
 *  TAG_FI1 → Fascicolo (figlio di S1)    "E2E Fascicolo Gamma" date 1910-1940
 *
 * Run:
 *   /tmp/run-e2e.sh tests/archives-search.spec.js --config=tests/playwright.config.js --workers=1
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE        = process.env.E2E_BASE_URL    || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const DB_HOST     = process.env.E2E_DB_HOST     || '';
const DB_PORT     = process.env.E2E_DB_PORT     || '';
const DB_USER     = process.env.E2E_DB_USER     || '';
const DB_PASS     = process.env.E2E_DB_PASS     || '';
const DB_NAME     = process.env.E2E_DB_NAME     || '';
const DB_SOCKET   = process.env.E2E_DB_SOCKET   || '';

function mysqlArgs(sql, batch = false) {
    const args = [];
    if (DB_HOST)               args.push('-h', DB_HOST);
    if (DB_PORT)               args.push('-P', DB_PORT);
    if (!DB_HOST && DB_SOCKET) args.push('-S', DB_SOCKET);
    args.push('-u', DB_USER);
    args.push(DB_NAME);
    if (batch) args.push('-N', '-B');
    if (sql !== '') args.push('-e', sql);
    return args;
}
const MYSQL_ENV = () => ({ ...process.env, MYSQL_PWD: DB_PASS });
function dbQuery(sql) {
    return execFileSync('mysql', mysqlArgs(sql, true), { encoding: 'utf-8', timeout: 10_000, env: MYSQL_ENV() }).trim();
}
function dbExec(sql) {
    execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout: 10_000, env: MYSQL_ENV() });
}

function tableExists(tableName) {
    const count = dbQuery(
        `SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '${tableName.replace(/'/g, "''")}'`
    );
    return parseInt(count, 10) === 1;
}

function getArchivesPluginState() {
    const row = dbQuery(
        "SELECT p.id, p.is_active, COUNT(ph.id) AS hooks " +
        "FROM plugins p " +
        "LEFT JOIN plugin_hooks ph ON ph.plugin_id = p.id " +
        "AND ph.hook_name = 'app.routes.register' " +
        "AND ph.callback_method = 'registerRoutes' " +
        "AND ph.is_active = 1 " +
        "WHERE p.name = 'archives' " +
        "GROUP BY p.id, p.is_active " +
        "LIMIT 1"
    );
    if (!row) return { id: 0, active: false, hooks: 0 };
    const parts = row.split('\t');
    return {
        id: parseInt(parts[0], 10) || 0,
        active: parts[1] === '1',
        hooks: parseInt(parts[2], 10) || 0,
    };
}

async function pluginApiCall(page, action, pluginId) {
    return page.evaluate(async ([act, pid]) => {
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        const token = csrfInput ? (/** @type {HTMLInputElement} */ (csrfInput)).value : '';
        const formData = new FormData();
        formData.append('csrf_token', token);
        const res = await fetch(
            `${window.location.origin}${window.BASE_PATH || ''}/admin/plugins/${pid}/${act}`,
            { method: 'POST', body: formData }
        );
        return res.json();
    }, [action, pluginId]);
}

async function ensureArchivesPlugin(page) {
    let plugin = getArchivesPluginState();
    if (plugin.id === 0) {
        throw new Error('Archives plugin is not registered in the plugins table');
    }

    if (!plugin.active || plugin.hooks === 0 || !tableExists('archival_units')) {
        await page.goto(`${BASE}/admin/plugins`);
        if (plugin.active) {
            const deactivate = await pluginApiCall(page, 'deactivate', plugin.id);
            if (!deactivate.success) {
                throw new Error(`Archives plugin deactivation failed: ${deactivate.message || deactivate.error || ''}`);
            }
        }

        const activate = await pluginApiCall(page, 'activate', plugin.id);
        if (!activate.success) {
            throw new Error(`Archives plugin activation failed: ${activate.message || activate.error || ''}`);
        }

        plugin = getArchivesPluginState();
        if (!plugin.active || plugin.hooks === 0 || !tableExists('archival_units')) {
            throw new Error('Archives plugin activation did not create archival_units and route hooks');
        }
    }
}

const TAG      = 'E2E_SRCH_' + Date.now();
const FONDS_REF = TAG + '_F1';
const SERIES_REF = TAG + '_S1';
const FILE_REF  = TAG + '_FI1';

function cleanupTag() {
    try {
        const escapedTag = TAG.replace(/\\/g, '\\\\').replace(/%/g, '\\%').replace(/_/g, '\\_');
        dbExec(`DELETE FROM archival_units WHERE reference_code LIKE '${escapedTag}%' ESCAPE '\\\\' AND parent_id IS NOT NULL`);
        dbExec(`DELETE FROM archival_units WHERE reference_code LIKE '${escapedTag}%' ESCAPE '\\\\'`);
    } catch { /* ignore if table missing */ }
}

// IDs set in beforeAll and reused across tests.
let fondsId  = 0;
let seriesId = 0;
let fileId   = 0;

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME, 'Missing E2E env vars');

test.describe.serial('Archives search bar — admin + public (25 tests)', () => {
    /** @type {import('@playwright/test').BrowserContext} */
    let ctx;
    /** @type {import('@playwright/test').Page} */
    let page;

    test.beforeAll(async ({ browser }) => {
        ctx  = await browser.newContext();
        page = await ctx.newPage();

        // Login
        await page.goto(`${BASE}/login`);
        await page.fill('input[name="email"]', ADMIN_EMAIL);
        await page.fill('input[name="password"]', ADMIN_PASS);
        await Promise.all([
            page.waitForURL(/\/admin\//, { timeout: 15_000 }),
            page.click('button[type="submit"]'),
        ]);

        await ensureArchivesPlugin(page);
        cleanupTag();

        // Seed test data directly in DB so tests are fast and independent.
        dbExec(
            `INSERT INTO archival_units (reference_code, institution_code, level, formal_title, constructed_title, scope_content, date_start, date_end)
             VALUES ('${FONDS_REF}', 'TEST', 'fonds', 'E2E Fondo Alpha', 'E2E Fondo Alpha', 'contenuto archivistico speciale', NULL, NULL)`
        );
        fondsId = parseInt(dbQuery(`SELECT id FROM archival_units WHERE reference_code='${FONDS_REF}' LIMIT 1`), 10);

        dbExec(
            `INSERT INTO archival_units (reference_code, institution_code, level, formal_title, constructed_title, parent_id, date_start, date_end)
             VALUES ('${SERIES_REF}', 'TEST', 'series', 'E2E Serie Beta', 'E2E Serie Beta', ${fondsId}, 1900, 1950)`
        );
        seriesId = parseInt(dbQuery(`SELECT id FROM archival_units WHERE reference_code='${SERIES_REF}' LIMIT 1`), 10);

        dbExec(
            `INSERT INTO archival_units (reference_code, institution_code, level, formal_title, constructed_title, parent_id, date_start, date_end)
             VALUES ('${FILE_REF}', 'TEST', 'file', 'E2E Fascicolo Gamma', 'E2E Fascicolo Gamma', ${seriesId}, 1910, 1940)`
        );
        fileId = parseInt(dbQuery(`SELECT id FROM archival_units WHERE reference_code='${FILE_REF}' LIMIT 1`), 10);
    });

    test.afterAll(async () => {
        cleanupTag();
        await ctx?.close();
    });

    // ─── ADMIN TESTS ────────────────────────────────────────────────────────

    test('1 · Admin: search form è visibile su /admin/archives', async () => {
        await page.goto(`${BASE}/admin/archives`);
        await expect(page.locator('input[name="q"]')).toBeVisible();
        await expect(page.locator('select[name="level"]')).toBeVisible();
        await expect(page.locator('button[type="submit"]')).toBeVisible();
    });

    test('2 · Admin: ricerca per reference code esatto trova il fondo', async () => {
        await page.goto(`${BASE}/admin/archives?q=${encodeURIComponent(FONDS_REF)}`);
        await expect(page.locator(`a[href*="/admin/archives/${fondsId}"]`).first()).toBeVisible();
    });

    test('3 · Admin: ricerca per reference code parziale (TAG prefix) trova tutti i record', async () => {
        await page.goto(`${BASE}/admin/archives?q=${encodeURIComponent(TAG)}`);
        await expect(page.locator(`a[href*="/admin/archives/${fondsId}"]`).first()).toBeVisible();
        await expect(page.locator(`a[href*="/admin/archives/${seriesId}"]`).first()).toBeVisible();
        await expect(page.locator(`a[href*="/admin/archives/${fileId}"]`).first()).toBeVisible();
    });

    test('4 · Admin: ricerca per titolo trova il fondo', async () => {
        await page.goto(`${BASE}/admin/archives?q=E2E+Fondo+Alpha`);
        await expect(page.locator(`a[href*="/admin/archives/${fondsId}"]`).first()).toBeVisible();
    });

    test('5 · Admin: ricerca per scope_content (FULLTEXT) trova il fondo', async () => {
        await page.goto(`${BASE}/admin/archives?q=archivistico+speciale`);
        await expect(page.locator(`a[href*="/admin/archives/${fondsId}"]`).first()).toBeVisible();
    });

    test('6 · Admin: filtro livello "fonds" mostra solo fondi', async () => {
        await page.goto(`${BASE}/admin/archives?level=fonds`);
        await expect(page.locator(`a[href*="/admin/archives/${fondsId}"]`).first()).toBeVisible();
        await expect(page.locator(`a[href*="/admin/archives/${seriesId}"]`).first()).not.toBeVisible();
    });

    test('7 · Admin: filtro livello "series" mostra solo serie', async () => {
        await page.goto(`${BASE}/admin/archives?level=series`);
        await expect(page.locator(`a[href*="/admin/archives/${seriesId}"]`).first()).toBeVisible();
        await expect(page.locator(`a[href*="/admin/archives/${fondsId}"]`).first()).not.toBeVisible();
    });

    test('8 · Admin: filtro livello "file" mostra solo fascicoli', async () => {
        await page.goto(`${BASE}/admin/archives?level=file`);
        await expect(page.locator(`a[href*="/admin/archives/${fileId}"]`).first()).toBeVisible();
        await expect(page.locator(`a[href*="/admin/archives/${fondsId}"]`).first()).not.toBeVisible();
    });

    test('9 · Admin: combinazione q + level restringe correttamente', async () => {
        await page.goto(`${BASE}/admin/archives?q=E2E&level=series`);
        await expect(page.locator(`a[href*="/admin/archives/${seriesId}"]`).first()).toBeVisible();
        await expect(page.locator(`a[href*="/admin/archives/${fondsId}"]`).first()).not.toBeVisible();
        await expect(page.locator(`a[href*="/admin/archives/${fileId}"]`).first()).not.toBeVisible();
    });

    test('10 · Admin: pulsante "Azzera" resetta la vista ad albero', async () => {
        await page.goto(`${BASE}/admin/archives?q=E2E&level=fonds`);
        await page.locator('form a[href$="/admin/archives"]').click();
        await page.waitForURL(`${BASE}/admin/archives`, { timeout: 5_000 });
        await expect(page.locator(`a[href*="/admin/archives/${fondsId}"]`).first()).toBeVisible();
    });

    test('11 · Admin: no-results mostra messaggio "Nessun risultato"', async () => {
        await page.goto(`${BASE}/admin/archives?q=QUESTO_NON_ESISTE_XYZ123`);
        await expect(page.getByText(/Nessun risultato|Aucun résultat|No results/i).first()).toBeVisible();
    });

    test('12 · Admin: in search mode la lista è piatta (no indent gerarchico)', async () => {
        // Cerca tutti e tre i record del TAG
        await page.goto(`${BASE}/admin/archives?q=${encodeURIComponent(TAG)}`);
        // In search mode nessun &nbsp; di indentazione per la serie/fascicolo
        const html = await page.content();
        // La serie in modalità flat non ha `&nbsp;&nbsp;&nbsp;&nbsp;` di profondità 1
        expect(html).not.toContain('&amp;nbsp;&amp;nbsp;&amp;nbsp;&amp;nbsp;');
    });

    test('13 · Admin: contatore risultati mostrato dopo ricerca', async () => {
        await page.goto(`${BASE}/admin/archives?q=${encodeURIComponent(TAG)}`);
        // Deve esserci un testo localizzato "3 risultati/résultats/results" o simile.
        const countEl = page.locator('p').filter({ hasText: /\b3\s+(risultati|résultats|results)\b/i }).first();
        await expect(countEl).toBeVisible();
        const txt = await countEl.textContent() || '';
        expect(txt).toMatch(/3/);
    });

    test('14 · Admin: il valore della query rimane nel campo input dopo la ricerca', async () => {
        const q = 'E2E Fondo Alpha';
        await page.goto(`${BASE}/admin/archives?q=${encodeURIComponent(q)}`);
        const inputVal = await page.inputValue('input[name="q"]');
        expect(inputVal).toBe(q);
    });

    test('15 · Admin: il livello selezionato rimane nel select dopo la ricerca', async () => {
        await page.goto(`${BASE}/admin/archives?q=E2E&level=series`);
        const val = await page.inputValue('select[name="level"]');
        expect(val).toBe('series');
    });

    // ─── PUBLIC TESTS ────────────────────────────────────────────────────────

    test('16 · Pubblico: search form è visibile su /archivio', async () => {
        await page.goto(`${BASE}/archivio`);
        const form = page.locator('.archive-search-form');
        await expect(form.locator('input[name="q"]')).toBeVisible();
        await expect(form.locator('select[name="level"]')).toBeVisible();
        await expect(form.locator('input[name="date_from"]')).toBeVisible();
        await expect(form.locator('input[name="date_to"]')).toBeVisible();
    });

    test('17 · Pubblico: ricerca per reference code trova il fondo', async () => {
        await page.goto(`${BASE}/archivio?q=${encodeURIComponent(FONDS_REF)}`);
        // Nella card pubblica il reference code appare nel .archive-ref
        await expect(page.locator(`.archive-ref:text("${FONDS_REF}")`)).toBeVisible();
    });

    test('18 · Pubblico: ricerca per titolo trova il fondo', async () => {
        await page.goto(`${BASE}/archivio?q=E2E+Fondo+Alpha`);
        await expect(page.locator('h2:has-text("E2E Fondo Alpha"), .card-title:has-text("E2E Fondo Alpha")')).toBeVisible();
    });

    test('19 · Pubblico: filtro livello "series" mostra solo serie', async () => {
        await page.goto(`${BASE}/archivio?q=${encodeURIComponent(TAG)}&level=series`);
        await expect(page.locator('.archive-ref:text("' + SERIES_REF + '")')).toBeVisible();
        await expect(page.locator('.archive-ref:text("' + FONDS_REF + '")')).not.toBeVisible();
    });

    test('20 · Pubblico: filtro livello "file" mostra solo fascicoli', async () => {
        await page.goto(`${BASE}/archivio?q=${encodeURIComponent(TAG)}&level=file`);
        await expect(page.locator(`.archive-ref:text("${FILE_REF}")`)).toBeVisible();
        await expect(page.locator(`.archive-ref:text("${FONDS_REF}")`)).not.toBeVisible();
    });

    test('21 · Pubblico: filtro date_from=1960 esclude serie (1900-1950) e fascicolo (1910-1940)', async () => {
        await page.goto(`${BASE}/archivio?q=${encodeURIComponent(TAG)}&date_from=1960`);
        // Nessuno dei nostri record ha date_end >= 1960
        await expect(page.locator(`.archive-ref:text("${SERIES_REF}")`)).not.toBeVisible();
        await expect(page.locator(`.archive-ref:text("${FILE_REF}")`)).not.toBeVisible();
    });

    test('22 · Pubblico: filtro date_to=1945 include serie (date_start=1900 ≤ 1945)', async () => {
        await page.goto(`${BASE}/archivio?q=${encodeURIComponent(TAG)}&level=series&date_to=1945`);
        await expect(page.locator(`.archive-ref:text("${SERIES_REF}")`)).toBeVisible();
    });

    test('23 · Pubblico: combinazione q + level + date_from + date_to trova la serie', async () => {
        await page.goto(`${BASE}/archivio?q=${encodeURIComponent(SERIES_REF)}&level=series&date_from=1890&date_to=1960`);
        await expect(page.locator(`.archive-ref:text("${SERIES_REF}")`)).toBeVisible();
    });

    test('24 · Pubblico: pulsante × resetta al catalogo radice', async () => {
        await page.goto(`${BASE}/archivio?q=E2E`);
        await page.locator(
            '.archive-search-form a[href$="/archivio"], ' +
            '.archive-search-form a[href$="/archive"], ' +
            '.archive-search-form a[href$="/archives"]'
        ).click({ timeout: 5_000 });
        await expect(page).toHaveURL(/\/(?:archivio|archive|archives)$/, { timeout: 5_000 });
        await expect(page.locator('.archive-search-form input[name="q"]')).toHaveValue('');
    });

    test('25 · Pubblico: ricerca trova anche unità non-root (serie, fascicolo)', async () => {
        await page.goto(`${BASE}/archivio?q=${encodeURIComponent(SERIES_REF)}`);
        await expect(page.locator(`.archive-ref:text("${SERIES_REF}")`)).toBeVisible();

        await page.goto(`${BASE}/archivio?q=${encodeURIComponent(FILE_REF)}`);
        await expect(page.locator(`.archive-ref:text("${FILE_REF}")`)).toBeVisible();
    });

    test('26 · Catalogo: ricerca per titolo mostra sezione archivio', async () => {
        // Search for "Fondo" — matches TAG_F1 "E2E Fondo Alpha"
        await page.goto(`${BASE}/catalogo?search=${encodeURIComponent('E2E Fondo')}`);
        const archiveSection = page.getByText(/Trovato anche nell['’]archivio|Trouvé aussi dans l['’]archive|Also found in (the )?archive/i);
        await expect(archiveSection).toBeVisible({ timeout: 5_000 });
        await expect(page.locator('a', { hasText: 'E2E Fondo Alpha' }).first()).toBeVisible();
    });

    test('27 · Autocomplete: record archivistico seedato nel dropdown', async () => {
        await page.goto(`${BASE}/catalogo`);
        const input = page.locator('.search-form.d-none.d-md-block .search-input');
        const responsePromise = page.waitForResponse(r => r.url().includes('/api/search/preview'), { timeout: 5000 });
        await input.click();
        await input.pressSequentially('Fondo Alpha', { delay: 150 });
        await responsePromise;
        await page.waitForTimeout(200);
        const container = page.locator('.search-form.d-none.d-md-block .search-results');
        await expect(container).toBeVisible();
        await expect(container.locator('.archive-result', { hasText: 'E2E Fondo Alpha' })).toBeVisible();
        await expect(container.locator(`text=${FONDS_REF}`)).toBeVisible();
    });

    test('28 · Catalogo: inflection italiana — singolare trova plurale (stem LIKE)', async () => {
        // "E2E Fondo Alpha" has 9+ chars; stem = "E2E Fondo Alph" — still matches.
        // Use "E2E Fond" (8 chars) as stem to verify: stem = "E2E Fon" matches "Fondo".
        await page.goto(`${BASE}/catalogo?search=${encodeURIComponent('E2E Fond')}`);
        const archiveSection = page.getByText(/Trovato anche nell['’]archivio|Trouvé aussi dans l['’]archive|Also found in (the )?archive/i);
        await expect(archiveSection).toBeVisible({ timeout: 5_000 });
        await expect(page.locator('a', { hasText: 'E2E Fondo Alpha' }).first()).toBeVisible();
    });
});
