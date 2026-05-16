// @ts-check
/**
 * E2E for Archives RiC-CM Phase 5 — admin UI for activities, places, relations.
 *
 * Coverage:
 *   1. CRUD for archive_activities (/admin/archives/activities)
 *   2. CRUD for archive_places (/admin/archives/places)
 *   3. Polymorphic relations: attach + detach via /admin/archives/relations
 *   4. Autocomplete API /api/archives/entities
 *
 * Pre-requisite: archives plugin must be active (Phase 1 schema +
 * Phase 2/3/4 migrations) — this spec activates it explicitly to be
 * standalone-runnable.
 *
 * Cleanup: hard-deletes E2E-created rows in afterAll().
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

// Mirrors the pattern in tests/archives-search.spec.js: prefer TCP
// (host/port) when available because CI runs MySQL as a service
// container reachable only on 127.0.0.1; fall back to socket for local
// dev. Password goes through MYSQL_PWD env to avoid leaking via argv.
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
    return execFileSync('mysql', mysqlArgs(sql, true),
        { encoding: 'utf-8', timeout: 10_000, env: MYSQL_ENV() }).trim();
}
function dbExec(sql) {
    execFileSync('mysql', mysqlArgs(sql),
        { encoding: 'utf-8', timeout: 10_000, env: MYSQL_ENV() });
}

const TAG = 'E2E_P5_' + Date.now();
const ACT_TITLE = TAG + '_Activity_Test';
const ACT_TITLE_EDITED = TAG + '_Activity_Edited';
const PLACE_NAME = TAG + '_Place_Test';
const ARCHIVAL_REF = TAG + '_AU';

test.skip(
    !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
    'Missing E2E env (ADMIN_EMAIL/PASS, DB_*)'
);

function cleanupPhase5(tag) {
    // FK-safe order: relations → activity links → activities → places → archival_units.
    // Relations may reference any of the four entity types, so wipe by tag
    // through the names/titles of the linked entities first.
    try { dbExec(`DELETE FROM archive_relations WHERE qualifier LIKE '${tag}%' OR source_ref LIKE '${tag}%'`); } catch {}
    try { dbExec(`DELETE FROM archive_unit_activities WHERE activity_id IN (SELECT id FROM archive_activities WHERE title LIKE '${tag}%')`); } catch {}
    try { dbExec(`DELETE FROM archive_activities WHERE title LIKE '${tag}%'`); } catch {}
    try { dbExec(`DELETE FROM archive_places WHERE name LIKE '${tag}%'`); } catch {}
    try { dbExec(`DELETE FROM archival_units WHERE reference_code LIKE '${tag}%' AND parent_id IS NOT NULL`); } catch {}
    try { dbExec(`DELETE FROM archival_units WHERE reference_code LIKE '${tag}%'`); } catch {}
}

test.describe.serial('Archives Phase 5 — admin UI for activities/places/relations', () => {
    /** @type {import('@playwright/test').BrowserContext} */
    let context;
    /** @type {import('@playwright/test').Page} */
    let page;

    /** @type {number|null} */
    let activityId = null;
    /** @type {number|null} */
    let placeId = null;
    /** @type {number|null} */
    let archivalUnitId = null;

    test.beforeAll(async ({ browser }) => {
        try { cleanupPhase5(TAG); } catch { /* tables may not exist yet */ }

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
        try { cleanupPhase5(TAG); } catch { /* best-effort */ }
        await context?.close();
    });

    test('1. Archives plugin is active (sanity)', async () => {
        const isActive = dbQuery(
            "SELECT is_active FROM plugins WHERE name = 'archives'"
        );
        if (isActive !== '1') {
            // Activate it manually rather than skipping the whole suite —
            // the schema for Phase 2/3/4 tables is created via ensureSchema()
            // on plugin activation.
            await page.goto(`${BASE}/admin/plugins`);
            const activateBtn = page.locator('button[onclick^="activatePlugin("]').first();
            if (await activateBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
                await activateBtn.click();
                const swalConfirm = page.locator('.swal2-confirm').first();
                if (await swalConfirm.isVisible({ timeout: 5000 }).catch(() => false)) {
                    await swalConfirm.click();
                }
                await page.waitForLoadState('domcontentloaded');
            }
        }
        const afterActive = dbQuery(
            "SELECT is_active FROM plugins WHERE name = 'archives'"
        );
        expect(afterActive, 'archives plugin must be active').toBe('1');

        // All three Phase 2-4 tables must exist.
        for (const tbl of ['archive_activities', 'archive_places', 'archive_relations']) {
            const exists = dbQuery(
                `SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = '${tbl}'`
            );
            expect(exists, `table ${tbl} must exist`).toBe('1');
        }
    });

    test('2. Activity index renders with breadcrumb + "+ create" CTA (locale-agnostic)', async () => {
        await page.goto(`${BASE}/admin/archives/activities`);
        await page.waitForLoadState('domcontentloaded');
        // Page main heading is present (any locale).
        await expect(page.locator('h1').first()).toBeVisible();
        // CTA link to /new must exist regardless of locale.
        await expect(page.locator('a[href$="/admin/archives/activities/new"]').first()).toBeVisible();
        // Breadcrumb back to /admin/archives must be linked.
        await expect(page.locator('a[href$="/admin/archives"]').first()).toBeVisible();
    });

    test('3. Create activity (type=function) via form POST', async () => {
        // Fetch the empty form to scrape both meta CSRF and the
        // form-embedded _token (some routes accept one, others both).
        await page.goto(`${BASE}/admin/archives/activities/new`);
        await page.waitForLoadState('domcontentloaded');
        const csrf = await page.locator('input[name="csrf_token"]').first().getAttribute('value')
            || await page.locator('meta[name="csrf-token"]').getAttribute('content');
        expect(csrf, 'must scrape CSRF token from /new form').toBeTruthy();

        const res = await page.request.post(`${BASE}/admin/archives/activities/new`, {
            form: {
                csrf_token: csrf || '',
                title: ACT_TITLE,
                activity_type: 'function',
                date_start: '1861',
                date_end: '1946',
                source_ref: 'RD 9 ottobre 1861 n. 250',
            },
        });
        expect([200, 201, 302, 303].includes(res.status()),
            `create must respond 2xx/3xx, got ${res.status()}`).toBe(true);

        const idStr = dbQuery(
            `SELECT id FROM archive_activities WHERE title = '${ACT_TITLE}' AND deleted_at IS NULL LIMIT 1`
        );
        expect(idStr, 'activity row must exist after create').not.toBe('');
        activityId = parseInt(idStr, 10);
        expect(Number.isFinite(activityId) && activityId > 0).toBe(true);

        expect(dbQuery(`SELECT activity_type FROM archive_activities WHERE id = ${activityId}`)).toBe('function');
        expect(dbQuery(`SELECT date_start FROM archive_activities WHERE id = ${activityId}`)).toBe('1861');
    });

    test('4. Edit activity via form POST: title + type persist in DB', async () => {
        expect(activityId, 'precondition: activity created in step 3').not.toBeNull();
        await page.goto(`${BASE}/admin/archives/activities/${activityId}/edit`);
        await page.waitForLoadState('domcontentloaded');
        const csrf = await page.locator('input[name="csrf_token"]').first().getAttribute('value')
            || await page.locator('meta[name="csrf-token"]').getAttribute('content');

        const res = await page.request.post(`${BASE}/admin/archives/activities/${activityId}/edit`, {
            form: {
                csrf_token: csrf || '',
                title: ACT_TITLE_EDITED,
                activity_type: 'activity',
            },
        });
        expect([200, 201, 302, 303].includes(res.status())).toBe(true);

        expect(dbQuery(`SELECT title FROM archive_activities WHERE id = ${activityId}`)).toBe(ACT_TITLE_EDITED);
        expect(dbQuery(`SELECT activity_type FROM archive_activities WHERE id = ${activityId}`)).toBe('activity');
    });

    test('5. Activity show page renders edited title + Linked Data link', async () => {
        expect(activityId).not.toBeNull();
        await page.goto(`${BASE}/admin/archives/activities/${activityId}`);
        await page.waitForLoadState('domcontentloaded');
        // Edited title (tag-prefixed) survives locale changes — it's data,
        // not a translatable string.
        await expect(page.locator('h1', { hasText: ACT_TITLE_EDITED })).toBeVisible();
        // Phase 1 JSON-LD endpoint link must be present on the show page.
        const ldLink = page.locator(`a[href$="/archives/activities/${activityId}/ric.json"]`);
        await expect(ldLink).toBeVisible();
    });

    test('6. Place index renders with breadcrumb + new-place CTA (locale-agnostic)', async () => {
        await page.goto(`${BASE}/admin/archives/places`);
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('h1').first()).toBeVisible();
        await expect(page.locator('a[href$="/admin/archives/places/new"]').first()).toBeVisible();
        await expect(page.locator('a[href$="/admin/archives"]').first()).toBeVisible();
    });

    test('7. Create place (country) with GeoNames identifier via form POST', async () => {
        await page.goto(`${BASE}/admin/archives/places/new`);
        await page.waitForLoadState('domcontentloaded');
        const csrf = await page.locator('input[name="csrf_token"]').first().getAttribute('value')
            || await page.locator('meta[name="csrf-token"]').getAttribute('content');

        const res = await page.request.post(`${BASE}/admin/archives/places/new`, {
            form: {
                csrf_token: csrf || '',
                name: PLACE_NAME,
                place_type: 'country',
                latitude: '41.9028',
                longitude: '12.4964',
                geonames_id: '3175395',
            },
        });
        if (![200, 201, 302, 303].includes(res.status())) {
            const body = await res.text();
            console.error(`place create unexpected status ${res.status()}; first 400 chars of body: ${body.substring(0, 400)}`);
        }
        expect([200, 201, 302, 303].includes(res.status()),
            `place create must respond 2xx/3xx, got ${res.status()}`).toBe(true);

        const idStr = dbQuery(
            `SELECT id FROM archive_places WHERE name = '${PLACE_NAME}' AND deleted_at IS NULL LIMIT 1`
        );
        expect(idStr).not.toBe('');
        placeId = parseInt(idStr, 10);
        expect(dbQuery(`SELECT geonames_id FROM archive_places WHERE id = ${placeId}`)).toBe('3175395');
        expect(dbQuery(`SELECT place_type FROM archive_places WHERE id = ${placeId}`)).toBe('country');
    });

    test('8. Place show page renders name + LOD ric.json link', async () => {
        expect(placeId).not.toBeNull();
        await page.goto(`${BASE}/admin/archives/places/${placeId}`);
        await page.waitForLoadState('domcontentloaded');
        // Place name is data — locale-invariant.
        await expect(page.locator('h1', { hasText: PLACE_NAME })).toBeVisible();
        // GeoNames identifier value (numeric, no translation) must appear
        // in the rendered page.
        await expect(page.getByText('3175395')).toBeVisible();
        const ldLink = page.locator(`a[href$="/archives/places/${placeId}/ric.json"]`);
        await expect(ldLink).toBeVisible();
    });

    test('9. Autocomplete API /api/archives/entities returns activities matching query', async () => {
        const url = `${BASE}/api/archives/entities?type=archive_activity&q=${encodeURIComponent(TAG)}`;
        const res = await page.request.get(url);
        expect(res.status(), `autocomplete must respond 200, got ${res.status()}`).toBe(200);
        const body = await res.json();
        expect(Array.isArray(body) || (body && typeof body === 'object'), 'response must be array or object').toBeTruthy();

        // Body shape may be either a list or {items: [...]} depending on
        // implementation — accept both.
        const items = Array.isArray(body) ? body : (body.items || body.results || []);
        const found = items.some(i =>
            (i.id === activityId)
            || (i.label && String(i.label).includes(TAG))
            || (i.title && String(i.title).includes(TAG))
        );
        expect(found, `autocomplete must surface activity #${activityId} (tag=${TAG})`).toBe(true);
    });

    test('10. Autocomplete API safely handles unknown entity type (4xx OR empty results)', async () => {
        const res = await page.request.get(
            `${BASE}/api/archives/entities?type=not_a_valid_type&q=foo`
        );
        // Two valid implementations:
        //   a) 4xx with error envelope (strict — locks the ENUM at the API)
        //   b) 200 with `{results: []}` (permissive — type whitelist is in
        //      the handler, unknown types silently return no rows)
        // Either way the server MUST NOT leak rows from a default table
        // and MUST NOT return 500.
        expect(res.status()).toBeLessThan(500);
        if (res.status() < 400) {
            const body = await res.json();
            const items = Array.isArray(body) ? body : (body.results || body.items || []);
            expect(items.length, 'unknown type must yield zero rows').toBe(0);
        }
    });

    test('11. Create archival_unit (so we have a valid relation source)', async () => {
        // Use the SQL path — the Archives unit form is exercised by
        // archives-crud.spec.js so we don't re-test it here.
        dbExec(`INSERT INTO archival_units (level, reference_code, formal_title, constructed_title, created_at, updated_at)
                VALUES ('file', '${ARCHIVAL_REF}', '${TAG} test unit', '${TAG} test unit', NOW(), NOW())`);
        const idStr = dbQuery(
            `SELECT id FROM archival_units WHERE reference_code = '${ARCHIVAL_REF}' LIMIT 1`
        );
        archivalUnitId = parseInt(idStr, 10);
        expect(Number.isFinite(archivalUnitId) && archivalUnitId > 0).toBe(true);
    });

    test('12. Attach a polymorphic relation archival_unit → place', async () => {
        expect(archivalUnitId).not.toBeNull();
        expect(placeId).not.toBeNull();

        // CSRF token: fetch any admin page first, scrape the meta tag.
        await page.goto(`${BASE}/admin/archives`);
        await page.waitForLoadState('domcontentloaded');
        const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
        expect(csrf, 'csrf token must be present in meta').toBeTruthy();

        const res = await page.request.post(`${BASE}/admin/archives/relations/attach`, {
            form: {
                csrf_token: csrf || '',
                source_type: 'archival_unit',
                source_id: String(archivalUnitId),
                target_type: 'archive_place',
                target_id: String(placeId),
                ric_predicate: 'ric:isOrWasLocatedAt',
                qualifier: TAG + '_qualifier',
                certainty: 'certain',
            },
        });
        // Either 200 (JSON success) or 302 (form redirect back to detail page)
        // is acceptable depending on how the handler responds.
        expect([200, 201, 302, 303].includes(res.status()),
            `attach must respond 2xx/3xx, got ${res.status()}`).toBe(true);

        const count = dbQuery(
            `SELECT COUNT(*) FROM archive_relations
              WHERE source_type='archival_unit' AND source_id=${archivalUnitId}
                AND target_type='archive_place' AND target_id=${placeId}
                AND ric_predicate='ric:isOrWasLocatedAt'`
        );
        expect(count, 'relation must be persisted').toBe('1');
    });

    test('13. Detach the relation we just attached', async () => {
        const relIdStr = dbQuery(
            `SELECT id FROM archive_relations
              WHERE source_type='archival_unit' AND source_id=${archivalUnitId}
                AND target_type='archive_place' AND target_id=${placeId}
              LIMIT 1`
        );
        expect(relIdStr).not.toBe('');
        const relId = parseInt(relIdStr, 10);

        await page.goto(`${BASE}/admin/archives`);
        await page.waitForLoadState('domcontentloaded');
        const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');

        const res = await page.request.post(`${BASE}/admin/archives/relations/${relId}/detach`, {
            form: { csrf_token: csrf || '' },
        });
        expect([200, 204, 302, 303].includes(res.status()),
            `detach must respond 2xx/3xx, got ${res.status()}`).toBe(true);

        const remaining = dbQuery(`SELECT COUNT(*) FROM archive_relations WHERE id = ${relId}`);
        expect(remaining, 'relation must be deleted after detach').toBe('0');
    });

    test('14. Attach with invalid endpoint type → rejected', async () => {
        await page.goto(`${BASE}/admin/archives`);
        await page.waitForLoadState('domcontentloaded');
        const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');

        const res = await page.request.post(`${BASE}/admin/archives/relations/attach`, {
            form: {
                csrf_token: csrf || '',
                source_type: 'definitely_not_a_valid_enum_member',
                source_id: '1',
                target_type: 'archive_place',
                target_id: String(placeId),
                ric_predicate: 'ric:isOrWasLocatedAt',
            },
        });
        // Server-side ENUM validation should reject. Either 4xx or a 3xx
        // redirect-back-to-form is acceptable, but the row MUST NOT have
        // been inserted.
        const inserted = dbQuery(
            `SELECT COUNT(*) FROM archive_relations
              WHERE source_type LIKE '%definitely_not%' OR target_type LIKE '%definitely_not%'`
        );
        expect(inserted, 'invalid source_type must not be persisted').toBe('0');
    });

    test('15. Delete activity (soft-delete via admin form)', async () => {
        expect(activityId).not.toBeNull();
        await page.goto(`${BASE}/admin/archives`);
        await page.waitForLoadState('domcontentloaded');
        const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');

        const res = await page.request.post(
            `${BASE}/admin/archives/activities/${activityId}/delete`,
            { form: { csrf_token: csrf || '' } }
        );
        expect([200, 204, 302, 303].includes(res.status()),
            `delete must respond 2xx/3xx, got ${res.status()}`).toBe(true);

        // Activity should be either hard-deleted or soft-deleted.
        const visible = dbQuery(
            `SELECT COUNT(*) FROM archive_activities WHERE id = ${activityId} AND deleted_at IS NULL`
        );
        expect(visible, 'activity must be hidden from active queries after delete').toBe('0');
    });
});
