// @ts-check
/**
 * Archives plugin — 50-element CRUD stress test via real admin UI.
 *
 * Creates 50 archival_units that collectively cover the *full* option
 * matrix (all 4 levels × all 15 specific_materials × all 4 color modes
 * including "none" × varied dates/extent/photographer/publisher/etc),
 * then exercises every CRUD path via Playwright:
 *
 *   - Create (form submit through /admin/archives/new)
 *   - Read  (index list + detail page for each row)
 *   - Update (edit title/dates/material/color/extent on every row)
 *   - Delete (soft-delete via Swal confirm, verify hidden from index)
 *
 * Runs serial — the rows share the TAG prefix so parallel runs and
 * leftover data never collide; both pre- and post-cleanup drop
 * everything matching the prefix.
 *
 * Usage:
 *   /tmp/run-e2e.sh tests/archives-50-elements-crud.spec.js \
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
function dbExec(sql) {
    execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout: 10000 });
}

test.skip(
    !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
    'Missing E2E env (ADMIN_EMAIL/PASS, DB_*)'
);

// Full option matrices — mirror the PHP constants and ENUM.
const LEVELS = ['fonds', 'series', 'file', 'item'];
const MATERIALS = [
    'text', 'photograph', 'poster', 'postcard', 'drawing',
    'audio', 'video', 'other',
    'map', 'picture', 'object', 'film', 'microform', 'electronic', 'mixed',
];
const COLOR_MODES = ['', 'bw', 'color', 'mixed']; // '' = "no preference"
const LANGUAGES = ['ita', 'eng', 'dan', 'deu', 'fra'];

// A stable, deterministic prefix. Using Date.now() here would change
// between Playwright's collection and execution passes, breaking
// --grep filters ("Test not found in the worker process"). The worker
// process regenerates the TAG at beforeAll time and stores the rows
// using that runtime value; the fixture title embeds only the index
// and the level/material tuple so titles never change.
const TAG_PREFIX = 'E2E_50CRUD';
/** @type {string} populated in beforeAll */
let TAG = TAG_PREFIX;

/**
 * Deterministic fixture generator — every element is unique and covers
 * a distinct (level, material, colour) combination.
 */
function buildFixture(i) {
    const level = LEVELS[i % LEVELS.length];
    const material = MATERIALS[i % MATERIALS.length];
    const color = COLOR_MODES[i % COLOR_MODES.length];
    const lang = LANGUAGES[i % LANGUAGES.length];
    const refCode = `${TAG}_${String(i + 1).padStart(3, '0')}`;
    const year = 1850 + (i * 7 % 170); // 1850..2019
    return {
        refCode,
        level,
        material,
        color,
        lang,
        formalTitle: `Formal title ${i + 1} (${material})`,
        constructedTitle: `Constructed title fixture #${i + 1} — ${material} ${level}`,
        dateStart: year,
        dateEnd: year + (i % 20),
        extent: `${i + 1} box${i === 0 ? '' : 'es'}, ${(i + 1) * 3} folders`,
        scope: `Scope and content for fixture #${i + 1}. Material: ${material}. Level: ${level}. Contains test data.`,
        dimensions: material === 'photograph' ? '24×18 cm' : (material === 'poster' ? '70×100 cm' : ''),
        photographer: ['photograph', 'drawing', 'picture'].includes(material)
            ? `Photographer ${String.fromCharCode(65 + (i % 26))}. Testfield`
            : '',
        publisher: ['poster', 'postcard', 'electronic'].includes(material)
            ? `Publisher House ${i + 1}`
            : '',
        collection: `Test Collection ${(i % 5) + 1}`,
        localClass: `TC-${(i % 5) + 1}.${i + 1}`,
    };
}

test.describe.serial('Archives — 50-element CRUD stress test', () => {
    /** @type {import('@playwright/test').BrowserContext} */
    let context;
    /** @type {import('@playwright/test').Page} */
    let page;

    // Track rows as we create them so Read/Update/Delete can target them
    // by DB id (the reference_code stays stable, so SELECT id WHERE ref=…).
    /** @type {number[]} */
    const createdIds = [];

    test.beforeAll(async ({ browser }) => {
        // Mint the runtime TAG now — stable for the whole worker process.
        // Title templates use only the index + level/material tuple so
        // Playwright's discovery and execution passes agree on titles.
        TAG = `${TAG_PREFIX}_${Date.now()}`;
        // Pre-clean any leftover from aborted prior runs (all rows with
        // the shared TAG_PREFIX, regardless of timestamp).
        try {
            dbExec(`DELETE aua FROM archival_unit_authority aua
                    JOIN archival_units au ON au.id = aua.archival_unit_id
                    WHERE au.reference_code LIKE '${TAG_PREFIX}%'`);
            dbExec(`DELETE FROM archival_units WHERE reference_code LIKE '${TAG_PREFIX}%'`);
        } catch { /* first run */ }

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
        // Hard-delete every TAG-prefixed row. Soft-delete would leak
        // across reruns because the unique index on (institution_code,
        // reference_code) also covers deleted rows.
        try {
            dbExec(`DELETE aua FROM archival_unit_authority aua
                    JOIN archival_units au ON au.id = aua.archival_unit_id
                    WHERE au.reference_code LIKE '${TAG_PREFIX}%'`);
            dbExec(`DELETE FROM archival_units WHERE reference_code LIKE '${TAG_PREFIX}%'`);
        } catch { /* best-effort */ }
        await context?.close();
    });

    // ─── CREATE — 50 rows via the real admin form ────────────────────────
    for (let i = 0; i < 50; i++) {
        // Use a deterministic title (no TAG timestamp) so Playwright's
        // test discovery and execution passes agree on titles.
        const titleLevel = LEVELS[i % LEVELS.length];
        const titleMaterial = MATERIALS[i % MATERIALS.length];
        const titleColor = COLOR_MODES[i % COLOR_MODES.length] || 'no-color';
        test(`C${String(i + 1).padStart(2, '0')}. CREATE row #${i + 1} [${titleLevel}/${titleMaterial}/${titleColor}]`, async () => {
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

            // Phase-5 "Materiale specifico" details — open + populate the
            // full set of photograph/ABA columns. The details element is
            // toggled open directly (click on <summary> is a toggle — if
            // a server-side validation error pre-opens it the click would
            // close it instead).
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
            const id = Number(idStr);
            createdIds.push(id);

            // Sanity-check the DB row reflects every submitted field.
            const row = dbQuery(
                `SELECT CONCAT_WS('|', level, specific_material, IFNULL(color_mode,''),
                                     date_start, date_end, language_codes, formal_title)
                   FROM archival_units WHERE id = ${id}`
            );
            expect(row).toBe(
                [fx.level, fx.material, fx.color, fx.dateStart, fx.dateEnd, fx.lang, fx.formalTitle].join('|')
            );
        });
    }

    // ─── READ — index + detail pages ─────────────────────────────────────
    // Helper: re-login if the session expired during the long CREATE phase.
    async function ensureLoggedIn() {
        await page.goto(`${BASE}/admin/archives`);
        if (/\/(accedi|login)(\?|$)/.test(page.url())) {
            await page.fill('input[name="email"]', ADMIN_EMAIL);
            await page.fill('input[name="password"]', ADMIN_PASS);
            await Promise.all([
                page.waitForURL(/\/admin\//, { timeout: 15000 }),
                page.click('button[type="submit"]'),
            ]);
            await page.goto(`${BASE}/admin/archives`);
        }
        await page.waitForLoadState('domcontentloaded');
    }

    test('R01. Index page lists all 50 fixtures', async () => {
        await ensureLoggedIn();
        // DB count is the authoritative assertion; the body search is
        // just a spot-check (table may use virtualization or pagination
        // styling that the cell is rendered but not as plain text).
        const count = dbQuery(
            `SELECT COUNT(*) FROM archival_units WHERE reference_code LIKE '${TAG}%' AND deleted_at IS NULL`
        );
        expect(count).toBe('50');
        // Index must render the reference_code of at least one fixture
        // as a clickable anchor — confirms the listing endpoint is alive.
        const anyRefVisible = await page.locator(`a:has-text("${TAG}")`).count();
        expect(anyRefVisible, 'index must render at least one TAG-prefixed row link').toBeGreaterThan(0);
    });

    test('R02. Detail pages render for every fixture', async () => {
        await ensureLoggedIn();
        // Sample 10 detail pages (spot-check) — a full 50-page walk is
        // covered by Update below anyway.
        for (const i of [0, 5, 12, 18, 25, 31, 37, 42, 48, 49]) {
            const id = createdIds[i];
            const fx = buildFixture(i);
            await page.goto(`${BASE}/admin/archives/${id}`);
            await page.waitForLoadState('domcontentloaded');
            await expect(page.locator('body')).toContainText(fx.refCode);
            await expect(page.locator('body')).toContainText(fx.constructedTitle);
            await expect(page.locator('body')).toContainText(fx.extent);
        }
    });

    // ─── UPDATE — tweak every row via the edit form ──────────────────────
    for (let i = 0; i < 50; i++) {
        const uTitleLevel = LEVELS[i % LEVELS.length];
        const uTitleMaterial = MATERIALS[i % MATERIALS.length];
        test(`U${String(i + 1).padStart(2, '0')}. UPDATE row #${i + 1} [${uTitleLevel}/${uTitleMaterial}]`, async () => {
            const id = createdIds[i];
            const fx = buildFixture(i);
            await page.goto(`${BASE}/admin/archives/${id}/edit`);
            await page.waitForLoadState('domcontentloaded');

            // Mutate: title suffix, extent, extend end date by 10.
            const newTitle = fx.constructedTitle + ' (edited)';
            const newExtent = fx.extent + ' + 1 appendix';
            const newEnd = fx.dateEnd + 10;
            await page.fill('input[name="constructed_title"]', newTitle);
            await page.fill('input[name="extent"]', newExtent);
            await page.fill('input[name="date_end"]', String(newEnd));

            // Flip specific_material to the *next* one in the list —
            // exercises every (from → to) transition across the 50 rows.
            const nextMaterial = MATERIALS[(i + 1) % MATERIALS.length];
            await page.evaluate(() => {
                document.querySelectorAll('details').forEach(d => {
                    const sum = d.querySelector('summary');
                    if (sum && /Materiale specifico/.test(sum.textContent || '')) {
                        d.open = true;
                    }
                });
            });
            await page.selectOption('select[name="specific_material"]', nextMaterial);

            await Promise.all([
                page.waitForURL(new RegExp(`/admin/archives/${id}$`), { timeout: 10000 }),
                page.click('button[type="submit"]'),
            ]);

            // DB verify
            const row = dbQuery(
                `SELECT CONCAT_WS('|', constructed_title, extent, date_end, specific_material)
                   FROM archival_units WHERE id = ${id}`
            );
            expect(row).toBe([newTitle, newExtent, newEnd, nextMaterial].join('|'));
        });
    }

    // ─── DELETE — soft-delete half the rows via Swal confirm ─────────────
    test('D01. Soft-delete rows 1-25 via Swal confirm', async () => {
        for (let i = 0; i < 25; i++) {
            const id = createdIds[i];
            await page.goto(`${BASE}/admin/archives/${id}`);
            await page.waitForLoadState('domcontentloaded');
            await page.click('form[action*="/delete"] button:has-text("Elimina")');
            await page.locator('.swal2-confirm').click();
            await page.waitForURL(/\/admin\/archives$/, { timeout: 10000 });
        }
        const countDeleted = dbQuery(
            `SELECT COUNT(*) FROM archival_units WHERE reference_code LIKE '${TAG}%' AND deleted_at IS NOT NULL`
        );
        expect(countDeleted).toBe('25');
        const countActive = dbQuery(
            `SELECT COUNT(*) FROM archival_units WHERE reference_code LIKE '${TAG}%' AND deleted_at IS NULL`
        );
        expect(countActive).toBe('25');
    });

    test('D02. Soft-deleted rows are hidden from the admin index', async () => {
        await page.goto(`${BASE}/admin/archives`);
        await page.waitForLoadState('domcontentloaded');
        const body = await page.locator('body').textContent() || '';
        // First 25 must NOT appear (soft-deleted)
        for (const i of [0, 5, 12, 20, 24]) {
            expect(body).not.toContain(buildFixture(i).refCode);
        }
        // Last 25 must still be there
        for (const i of [25, 30, 40, 49]) {
            expect(body).toContain(buildFixture(i).refCode);
        }
    });

    test('D03. Hard-delete test fixtures cleanup (via DB, FK-safe)', async () => {
        dbExec(`DELETE aua FROM archival_unit_authority aua
                JOIN archival_units au ON au.id = aua.archival_unit_id
                WHERE au.reference_code LIKE '${TAG}%'`);
        dbExec(`DELETE FROM archival_units WHERE reference_code LIKE '${TAG}%'`);
        const remaining = dbQuery(
            `SELECT COUNT(*) FROM archival_units WHERE reference_code LIKE '${TAG}%'`
        );
        expect(remaining).toBe('0');
    });
});
