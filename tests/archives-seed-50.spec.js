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

    // ─── Authority records ───────────────────────────────────────────────
    // Create 15 authority records (5 person + 5 corporate + 5 family) via
    // the admin UI and link the first ~20 archival units to one or more
    // authorities with varied roles — this mirrors how a real archive is
    // described (fonds have creator/custodian/subject authorities).
    const AUTHORITY_FIXTURES = [
        { type: 'person',    name: 'Thorvald Stauning',          dates: '1873-1942',       history: 'Politico danese, primo ministro 1924-1926 e 1929-1942.' },
        { type: 'person',    name: 'Luigi Longo',                dates: '1900-1980',       history: 'Politico italiano, segretario del PCI 1964-1972.' },
        { type: 'person',    name: 'Antonio Gramsci',            dates: '1891-1937',       history: 'Filosofo, scrittore e politico italiano.' },
        { type: 'person',    name: 'Anna Kuliscioff',            dates: '1855-1925',       history: 'Attivista socialista russo-italiana, pioniera del femminismo.' },
        { type: 'person',    name: 'Camillo Berneri',            dates: '1897-1937',       history: 'Filosofo anarchico italiano.' },
        { type: 'corporate', name: 'Partito Socialista Italiano', dates: '1892-1994',       history: 'Storico partito politico italiano della sinistra riformista.' },
        { type: 'corporate', name: 'Confederazione Generale del Lavoro', dates: '1906-1927', history: 'Prima grande confederazione sindacale italiana.' },
        { type: 'corporate', name: 'Società Umanitaria',         dates: '1893-oggi',       history: 'Istituzione milanese di utilità sociale e formazione.' },
        { type: 'corporate', name: 'Federazione Giovanile Socialista', dates: '1907-1943', history: 'Organizzazione giovanile del PSI.' },
        { type: 'corporate', name: 'Arbejderbevægelsens Bibliotek og Arkiv', dates: '1909-oggi', history: 'Biblioteca e archivio del movimento operaio danese.' },
        { type: 'family',    name: 'Famiglia Turati-Kuliscioff', dates: '1880-1925',       history: 'Sodalizio politico e affettivo tra Filippo Turati e Anna Kuliscioff.' },
        { type: 'family',    name: 'Famiglia Nenni',             dates: '1891-1980',       history: 'Nucleo familiare di Pietro Nenni, leader socialista.' },
        { type: 'family',    name: 'Famiglia Treves',            dates: '1869-1943',       history: 'Famiglia Treves, editori e politici riformisti.' },
        { type: 'family',    name: 'Famiglia Modigliani',        dates: '1872-1947',       history: 'Famiglia dei fratelli Modigliani, politici e intellettuali.' },
        { type: 'family',    name: 'Famiglia Rosselli',          dates: '1899-1937',       history: 'Famiglia di Carlo e Nello Rosselli, fondatori di Giustizia e Libertà.' },
    ];
    const AUTHORITY_ROLES = ['creator', 'subject', 'recipient', 'custodian', 'associated'];
    /** @type {number[]} */
    const authorityIds = [];

    AUTHORITY_FIXTURES.forEach((auth, i) => {
        test(`A${String(i + 1).padStart(2, '0')}. SEED authority #${i + 1} [${auth.type}]`, async () => {
            await page.goto(`${BASE}/admin/archives/authorities/new`);
            await page.waitForLoadState('domcontentloaded');
            // Tag the name so pre-clean (E2E_SEED_*) matches. Adding the
            // TAG prefix would flood the label; we add a subtle suffix.
            const taggedName = `${auth.name} — ${TAG}`;
            await page.selectOption('select[name="type"]', auth.type);
            await page.fill('input[name="authorised_form"]', taggedName);
            await page.fill('input[name="dates_of_existence"]', auth.dates);
            await page.fill('textarea[name="history"]', auth.history);
            await Promise.all([
                page.waitForURL(/\/admin\/archives\/authorities$/, { timeout: 10000 }),
                page.click('button[type="submit"]'),
            ]);
            const idStr = dbQuery(
                `SELECT id FROM authority_records WHERE authorised_form = '${taggedName.replace(/'/g, "''")}' AND deleted_at IS NULL`
            );
            expect(idStr).toMatch(/^\d+$/);
            authorityIds.push(Number(idStr));
        });
    });

    // ─── Link authorities ↔ archival_units (M:N with varied roles) ───────
    test('L01. Link ~20 archival_units to authorities with varied roles', async () => {
        // Direct DB inserts — the M:N table has no domain logic beyond
        // the enum on role, so a bulk INSERT is equivalent to the UI
        // loop and lets this test finish in <1s instead of 20×click.
        const rowIds = dbQuery(
            `SELECT id FROM archival_units WHERE reference_code LIKE '${TAG}_%' AND deleted_at IS NULL ORDER BY id ASC LIMIT 20`
        ).split('\n').filter(Boolean).map(Number);
        expect(rowIds.length).toBe(20);
        expect(authorityIds.length).toBe(15);
        const values = [];
        rowIds.forEach((unitId, idx) => {
            // Primary authority (creator)
            const primary = authorityIds[idx % authorityIds.length];
            values.push(`(${unitId}, ${primary}, 'creator')`);
            // Secondary authority (varied role) on every other row
            if (idx % 2 === 0) {
                const secondary = authorityIds[(idx + 3) % authorityIds.length];
                const role = AUTHORITY_ROLES[(idx + 1) % AUTHORITY_ROLES.length];
                if (secondary !== primary) {
                    values.push(`(${unitId}, ${secondary}, '${role}')`);
                }
            }
        });
        dbQuery(
            `INSERT IGNORE INTO archival_unit_authority (archival_unit_id, authority_id, role) VALUES ${values.join(', ')}`
        );
        const linkCount = dbQuery(
            `SELECT COUNT(*) FROM archival_unit_authority aua
               JOIN archival_units au ON au.id = aua.archival_unit_id
              WHERE au.reference_code LIKE '${TAG}_%'`
        );
        expect(Number(linkCount)).toBeGreaterThanOrEqual(20);
    });

    test('Final. All 50 units + 15 authorities persisted', async () => {
        const units = dbQuery(
            `SELECT COUNT(*) FROM archival_units WHERE reference_code LIKE '${TAG}_%' AND deleted_at IS NULL`
        );
        const authorities = dbQuery(
            `SELECT COUNT(*) FROM authority_records WHERE authorised_form LIKE '%— ${TAG}' AND deleted_at IS NULL`
        );
        expect(units).toBe('50');
        expect(authorities).toBe('15');
        // eslint-disable-next-line no-console
        console.log(`\n  ✓ seeded: 50 archival_units + 15 authority_records (TAG=${TAG})`);
        console.log(`  → visit: ${BASE}/admin/archives`);
        console.log(`  → visit: ${BASE}/admin/archives/authorities\n`);
    });
});
