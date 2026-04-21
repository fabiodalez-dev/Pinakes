// @ts-check
/**
 * Archives plugin — 50-row seed spec (persistent).
 *
 * Mirrors tests/archives-50-elements-crud.spec.js but WITHOUT any
 * cleanup: creates 50 archival_units covering the full option matrix
 * (all 4 levels × 15 specific_materials × 4 color modes × varied
 * dates/extent/photographer/publisher/etc) and leaves them in the
 * database so they can be inspected on
 * http://localhost:8888/admin/archives.
 *
 * Use this when you want to see the plugin populated with realistic
 * test data; use the CRUD spec (archives-50-elements-crud.spec.js)
 * when you want to validate the full create→read→update→delete
 * lifecycle under test conditions.
 *
 * Rows are tagged with `E2E_SEED_<timestamp>_NNN` — re-running the
 * spec adds a fresh batch alongside any previous seed batches.
 *
 * Usage:
 *   /tmp/run-e2e.sh tests/archives-seed-50.spec.js \
 *     --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

function mysqlArgs(sql, batch = false) {
    const args = ['-u', DB_USER];
    if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
    if (DB_SOCKET) args.push('-S', DB_SOCKET);
    args.push(DB_NAME);
    if (batch) args.push('-N', '-B');
    if (sql !== '') args.push('-e', sql);
    return args;
}
function dbQuery(sql) {
    return execFileSync('mysql', mysqlArgs(sql, true), { encoding: 'utf-8', timeout: 10000 }).trim();
}

test.skip(
    !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
    'Missing E2E env (ADMIN_EMAIL/PASS, DB_*)'
);

const LEVELS = ['fonds', 'series', 'file', 'item'];
const MATERIALS = [
    'text', 'photograph', 'poster', 'postcard', 'drawing',
    'audio', 'video', 'other',
    'map', 'picture', 'object', 'film', 'microform', 'electronic', 'mixed',
];
const COLOR_MODES = ['', 'bw', 'color', 'mixed'];
const LANGUAGES = ['ita', 'eng', 'dan', 'deu', 'fra'];

// Each seed run gets its own timestamp-suffixed TAG; previous runs
// are preserved so the index grows cumulatively.
const TAG_PREFIX = 'E2E_SEED';
/** @type {string} populated in beforeAll */
let TAG = TAG_PREFIX;

function buildFixture(i) {
    const level = LEVELS[i % LEVELS.length];
    const material = MATERIALS[i % MATERIALS.length];
    const color = COLOR_MODES[i % COLOR_MODES.length];
    const lang = LANGUAGES[i % LANGUAGES.length];
    const refCode = `${TAG}_${String(i + 1).padStart(3, '0')}`;
    const year = 1850 + (i * 7 % 170);
    return {
        refCode,
        level,
        material,
        color,
        lang,
        formalTitle: `Formal title ${i + 1} (${material})`,
        constructedTitle: `Seed archivio #${i + 1} — ${material} ${level}`,
        dateStart: year,
        dateEnd: year + (i % 20),
        extent: `${i + 1} box${i === 0 ? '' : 'es'}, ${(i + 1) * 3} folders`,
        scope: `Scope and content for seed fixture #${i + 1}. Material: ${material}. Level: ${level}.`,
        dimensions: material === 'photograph' ? '24×18 cm' : (material === 'poster' ? '70×100 cm' : ''),
        photographer: ['photograph', 'drawing', 'picture'].includes(material)
            ? `Photographer ${String.fromCharCode(65 + (i % 26))}. Testfield`
            : '',
        publisher: ['poster', 'postcard', 'electronic'].includes(material)
            ? `Publisher House ${i + 1}`
            : '',
        collection: `Seed Collection ${(i % 5) + 1}`,
        localClass: `SC-${(i % 5) + 1}.${i + 1}`,
    };
}

test.describe.serial('Archives — seed 50 persistent rows', () => {
    /** @type {import('@playwright/test').BrowserContext} */
    let context;
    /** @type {import('@playwright/test').Page} */
    let page;

    test.beforeAll(async ({ browser }) => {
        TAG = `${TAG_PREFIX}_${Date.now()}`;
        context = await browser.newContext();
        page = await context.newPage();
        await page.goto(`${BASE}/login`);
        await page.fill('input[name="email"]', ADMIN_EMAIL);
        await page.fill('input[name="password"]', ADMIN_PASS);
        await Promise.all([
            page.waitForURL(/\/admin\//, { timeout: 15000 }),
            page.click('button[type="submit"]'),
        ]);
    });

    test.afterAll(async () => {
        // Only close the browser context. Rows are intentionally kept.
        await context?.close();
    });

    for (let i = 0; i < 50; i++) {
        const titleLevel = LEVELS[i % LEVELS.length];
        const titleMaterial = MATERIALS[i % MATERIALS.length];
        const titleColor = COLOR_MODES[i % COLOR_MODES.length] || 'no-color';
        test(`S${String(i + 1).padStart(2, '0')}. SEED row #${i + 1} [${titleLevel}/${titleMaterial}/${titleColor}]`, async () => {
            const fx = buildFixture(i);
            await page.goto(`${BASE}/admin/archives/new`);
            await page.waitForLoadState('domcontentloaded');

            await page.fill('input[name="reference_code"]', fx.refCode);
            await page.selectOption('select[name="level"]', fx.level);
            await page.fill('input[name="formal_title"]', fx.formalTitle);
            await page.fill('input[name="constructed_title"]', fx.constructedTitle);
            await page.fill('input[name="date_start"]', String(fx.dateStart));
            await page.fill('input[name="date_end"]', String(fx.dateEnd));
            await page.fill('input[name="extent"]', fx.extent);
            await page.fill('textarea[name="scope_content"]', fx.scope);
            await page.fill('input[name="language_codes"]', fx.lang);

            await page.evaluate(() => {
                document.querySelectorAll('details').forEach(d => {
                    const sum = d.querySelector('summary');
                    if (sum && /Materiale specifico/.test(sum.textContent || '')) {
                        d.open = true;
                    }
                });
            });
            await page.selectOption('select[name="specific_material"]', fx.material);
            await page.selectOption('select[name="color_mode"]', fx.color);
            if (fx.dimensions) await page.fill('input[name="dimensions"]', fx.dimensions);
            if (fx.photographer) await page.fill('input[name="photographer"]', fx.photographer);
            if (fx.publisher) await page.fill('input[name="publisher"]', fx.publisher);
            await page.fill('input[name="collection_name"]', fx.collection);
            await page.fill('input[name="local_classification"]', fx.localClass);

            await Promise.all([
                page.waitForURL(/\/admin\/archives$/, { timeout: 10000 }),
                page.click('button[type="submit"]'),
            ]);

            const idStr = dbQuery(
                `SELECT id FROM archival_units WHERE reference_code = '${fx.refCode}' AND deleted_at IS NULL`
            );
            expect(idStr).toMatch(/^\d+$/);
        });
    }

    test('Final. All 50 seeded rows are persisted', async () => {
        const count = dbQuery(
            `SELECT COUNT(*) FROM archival_units WHERE reference_code LIKE '${TAG}_%' AND deleted_at IS NULL`
        );
        expect(count).toBe('50');
        // eslint-disable-next-line no-console
        console.log(`\n  ✓ 50 archival_units seeded with TAG=${TAG}`);
        console.log(`  → visit: ${BASE}/admin/archives\n`);
    });
});
