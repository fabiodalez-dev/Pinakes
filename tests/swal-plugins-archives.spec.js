// @ts-check
/**
 * E2E coverage for every SweetAlert in the "plugins-archives" cluster.
 *
 * Each test proves BOTH that the SweetAlert is shown AND that confirming it
 * produces the documented outcome (soft-delete / hard-detach in the DB).
 *
 * Views covered:
 *   - storage/plugins/archives/views/show.php                (unit delete, authority detach)
 *   - storage/plugins/archives/views/authorities/show.php    (authority delete, autore unlink)
 *   - storage/plugins/archives/views/activities/show.php     (activity delete, relation detach)
 *   - storage/plugins/archives/views/places/show.php         (place delete, relation detach)
 *
 * All SweetAlerts are fired by the shared inline helper
 *   window.archivesSwalConfirm(formId, message, confirmLabel)
 * which opens a `icon: 'warning'` confirm dialog and submits the hidden
 * <form> on confirm. Admin routes are English literals (/admin/archives/...).
 *
 * Run with:
 *   /tmp/run-e2e.sh tests/swal-plugins-archives.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

// ── Helper block — copied VERBATIM from tests/series-cycles.spec.js so env
//    wiring matches the rest of the suite. ───────────────────────────────────
const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS ?? '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

const HAS_E2E_ENV = Boolean(ADMIN_EMAIL && ADMIN_PASS && DB_USER && DB_NAME && (DB_HOST || DB_SOCKET));

test.skip(
  !HAS_E2E_ENV,
  'Missing E2E env vars for SweetAlert plugins-archives tests'
);

function mysqlArgs(sql = '', batch = false) {
  const args = [];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER);
  if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
  args.push(DB_NAME);
  if (batch) args.push('-N', '-B');
  if (sql !== '') args.push('-e', sql);
  return args;
}

function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql, true), { encoding: 'utf-8', timeout: 10000 }).trim();
}

function dbExec(sql, timeout = 10000) {
  execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout });
}

function escapeSql(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const emailField = page.locator('input[name="email"]');
  if (await emailField.isVisible({ timeout: 3000 }).catch(() => false)) {
    await emailField.fill(ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
      page.waitForURL(/\/admin\//, { timeout: 15000 }),
      page.click('button[type="submit"]'),
    ]);
  }
}

// ── Unique per-run suffix, matching the template's TAG style. ────────────────
const TAG = 'E2E_SWAL_ARCH_' + Date.now();

/** Insert a fresh archival_unit fixture, return its id. */
function makeUnit(ref, title) {
  dbExec(`
    INSERT INTO archival_units (reference_code, institution_code, level, constructed_title, created_at, updated_at)
    VALUES ('${escapeSql(ref)}', 'PINAKES', 'fonds', '${escapeSql(title)}', NOW(), NOW())
  `);
  return Number(dbQuery(`SELECT id FROM archival_units WHERE reference_code = '${escapeSql(ref)}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`));
}

/** Insert a fresh authority_record fixture, return its id. */
function makeAuthority(name) {
  dbExec(`
    INSERT INTO authority_records (type, ric_type, authorised_form, created_at, updated_at)
    VALUES ('person', 'Person', '${escapeSql(name)}', NOW(), NOW())
  `);
  return Number(dbQuery(`SELECT id FROM authority_records WHERE authorised_form = '${escapeSql(name)}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`));
}

/** Insert a fresh archive_activity fixture, return its id. */
function makeActivity(title) {
  dbExec(`
    INSERT INTO archive_activities (title, activity_type, created_at, updated_at)
    VALUES ('${escapeSql(title)}', 'activity', NOW(), NOW())
  `);
  return Number(dbQuery(`SELECT id FROM archive_activities WHERE title = '${escapeSql(title)}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`));
}

/** Insert a fresh archive_place fixture, return its id. */
function makePlace(name) {
  dbExec(`
    INSERT INTO archive_places (name, place_type, created_at, updated_at)
    VALUES ('${escapeSql(name)}', 'locality', NOW(), NOW())
  `);
  return Number(dbQuery(`SELECT id FROM archive_places WHERE name = '${escapeSql(name)}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`));
}

/** Insert a core autori fixture, return its id. */
function makeAutore(name) {
  dbExec(`INSERT INTO autori (nome, created_at, updated_at) VALUES ('${escapeSql(name)}', NOW(), NOW())`);
  return Number(dbQuery(`SELECT id FROM autori WHERE nome = '${escapeSql(name)}' ORDER BY id DESC LIMIT 1`));
}

/** Insert a polymorphic relation, return its id. */
function makeRelation(srcType, srcId, tgtType, tgtId, predicate) {
  dbExec(`
    INSERT INTO archive_relations (source_type, source_id, target_type, target_id, ric_predicate, created_at)
    VALUES ('${escapeSql(srcType)}', ${srcId}, '${escapeSql(tgtType)}', ${tgtId}, '${escapeSql(predicate)}', NOW())
  `);
  return Number(dbQuery(`
    SELECT id FROM archive_relations
     WHERE source_type='${escapeSql(srcType)}' AND source_id=${srcId}
       AND target_type='${escapeSql(tgtType)}' AND target_id=${tgtId}
       AND ric_predicate='${escapeSql(predicate)}'
     ORDER BY id DESC LIMIT 1`));
}

/** FK-safe cleanup of every fixture this spec created. */
function cleanupRows() {
  // Link / relation rows first (children).
  dbExec(`DELETE FROM archive_relations WHERE ric_predicate LIKE '${escapeSql(TAG)}%'`);
  dbExec(`DELETE FROM archival_unit_authority WHERE archival_unit_id IN (SELECT id FROM archival_units WHERE reference_code LIKE '${escapeSql(TAG)}%')`);
  dbExec(`DELETE FROM autori_authority_link WHERE authority_id IN (SELECT id FROM authority_records WHERE authorised_form LIKE '${escapeSql(TAG)}%')`);
  dbExec(`DELETE FROM archive_unit_activities WHERE activity_id IN (SELECT id FROM archive_activities WHERE title LIKE '${escapeSql(TAG)}%')`);
  // Base entities.
  dbExec(`DELETE FROM autori WHERE nome LIKE '${escapeSql(TAG)}%'`);
  dbExec(`DELETE FROM archive_relations WHERE source_type='archival_unit' AND source_id IN (SELECT id FROM archival_units WHERE reference_code LIKE '${escapeSql(TAG)}%')`);
  dbExec(`DELETE FROM archival_units WHERE reference_code LIKE '${escapeSql(TAG)}%'`);
  dbExec(`DELETE FROM authority_records WHERE authorised_form LIKE '${escapeSql(TAG)}%'`);
  dbExec(`DELETE FROM archive_activities WHERE title LIKE '${escapeSql(TAG)}%'`);
  dbExec(`DELETE FROM archive_places WHERE name LIKE '${escapeSql(TAG)}%'`);
}

test.describe.serial('SweetAlert — plugins-archives cluster', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);

    // Ensure the archives plugin is active so its routes/tables exist.
    // (Pattern from tests/archives-crud.spec.js.)
    await page.goto(`${BASE}/admin/plugins`);
    await page.waitForLoadState('domcontentloaded');
    const isActive = dbQuery("SELECT is_active FROM plugins WHERE name = 'archives'");
    if (isActive !== '1') {
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

    try { cleanupRows(); } catch { /* tables may not exist until activation */ }
  });

  // Skip the whole suite gracefully if activation didn't create the schema.
  test.beforeAll(() => {
    const exists = dbQuery(
      "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'archival_units'"
    );
    test.skip(exists !== '1', 'archives plugin schema not present (plugin route/activation unavailable)');
  });

  test.afterAll(async () => {
    try { cleanupRows(); } catch { /* best-effort */ }
    await context?.close();
  });

  // ── show.php:103 — confirm-destructive: soft-delete the archival unit ──────
  test('show.php:103 — delete archival unit (soft-delete) shows Swal + sets deleted_at', async () => {
    const id = makeUnit(`${TAG}_U1`, `${TAG} Unit to delete`);
    await page.goto(`${BASE}/admin/archives/${id}`);
    await page.waitForSelector(`#archivesDeleteUnit_${id}`, { timeout: 8000 });

    await page.locator(`#archivesDeleteUnit_${id} button.btn-danger`).click();
    const popup = page.locator('.swal2-popup');
    await expect(popup).toBeVisible();
    await expect(popup).toContainText('Eliminare questo record');

    await Promise.all([
      page.waitForURL(/\/admin\/archives$/, { timeout: 12000 }),
      page.locator('.swal2-confirm').click(),
    ]);

    // Soft-deleted: still in DB but deleted_at is set, so the active view is empty.
    expect(dbQuery(`SELECT COUNT(*) FROM archival_units WHERE id = ${id} AND deleted_at IS NULL`)).toBe('0');
    expect(dbQuery(`SELECT COUNT(*) FROM archival_units WHERE id = ${id} AND deleted_at IS NOT NULL`)).toBe('1');
  });

  // ── show.php:431 — confirm-destructive: detach a linked authority from unit ─
  test('show.php:431 — detach authority from unit shows Swal + removes link row', async () => {
    const unitId = makeUnit(`${TAG}_U2`, `${TAG} Unit with authority`);
    const authId = makeAuthority(`${TAG} Linked authority`);
    dbExec(`INSERT INTO archival_unit_authority (archival_unit_id, authority_id, role) VALUES (${unitId}, ${authId}, 'creator')`);

    await page.goto(`${BASE}/admin/archives/${unitId}`);
    await page.waitForSelector(`#archivesDetachAuth_${unitId}_${authId}`, { timeout: 8000 });

    await page.locator(`#archivesDetachAuth_${unitId}_${authId} button`).click();
    const popup = page.locator('.swal2-popup');
    await expect(popup).toBeVisible();
    await expect(popup).toContainText('Rimuovere questo collegamento');

    await Promise.all([
      page.waitForURL(new RegExp(`/admin/archives/${unitId}$`), { timeout: 12000 }),
      page.locator('.swal2-confirm').click(),
    ]);

    // Hard DELETE from the M:N link table; both endpoints survive.
    expect(dbQuery(`SELECT COUNT(*) FROM archival_unit_authority WHERE archival_unit_id = ${unitId} AND authority_id = ${authId}`)).toBe('0');
    expect(dbQuery(`SELECT COUNT(*) FROM archival_units WHERE id = ${unitId} AND deleted_at IS NULL`)).toBe('1');
    expect(dbQuery(`SELECT COUNT(*) FROM authority_records WHERE id = ${authId} AND deleted_at IS NULL`)).toBe('1');
  });

  // ── authorities/show.php:82 — confirm-destructive: soft-delete authority ────
  test('authorities/show.php:82 — delete authority (soft-delete) shows Swal + sets deleted_at', async () => {
    const authId = makeAuthority(`${TAG} Authority to delete`);
    await page.goto(`${BASE}/admin/archives/authorities/${authId}`);
    await page.waitForSelector(`#archivesDeleteAuth_${authId}`, { timeout: 8000 });

    await page.locator(`#archivesDeleteAuth_${authId} button.btn-danger`).click();
    const popup = page.locator('.swal2-popup');
    await expect(popup).toBeVisible();
    await expect(popup).toContainText('Eliminare questo authority record');

    await Promise.all([
      page.waitForURL(/\/admin\/archives\/authorities$/, { timeout: 12000 }),
      page.locator('.swal2-confirm').click(),
    ]);

    expect(dbQuery(`SELECT COUNT(*) FROM authority_records WHERE id = ${authId} AND deleted_at IS NULL`)).toBe('0');
    expect(dbQuery(`SELECT COUNT(*) FROM authority_records WHERE id = ${authId} AND deleted_at IS NOT NULL`)).toBe('1');
  });

  // ── authorities/show.php:178 — confirm-destructive: unlink a library autore ─
  test('authorities/show.php:178 — unlink library author shows Swal + removes link row', async () => {
    const authId = makeAuthority(`${TAG} Authority with autore`);
    const autoreId = makeAutore(`${TAG} Linked autore`);
    dbExec(`INSERT INTO autori_authority_link (autori_id, authority_id) VALUES (${autoreId}, ${authId})`);

    await page.goto(`${BASE}/admin/archives/authorities/${authId}`);
    await page.waitForSelector(`#archivesUnlinkAutore_${authId}_${autoreId}`, { timeout: 8000 });

    await page.locator(`#archivesUnlinkAutore_${authId}_${autoreId} button`).click();
    const popup = page.locator('.swal2-popup');
    await expect(popup).toBeVisible();
    await expect(popup).toContainText('Rimuovere questo collegamento');

    await Promise.all([
      page.waitForURL(new RegExp(`/admin/archives/authorities/${authId}$`), { timeout: 12000 }),
      page.locator('.swal2-confirm').click(),
    ]);

    // Hard DELETE from the link table; both endpoints survive.
    expect(dbQuery(`SELECT COUNT(*) FROM autori_authority_link WHERE authority_id = ${authId} AND autori_id = ${autoreId}`)).toBe('0');
    expect(dbQuery(`SELECT COUNT(*) FROM authority_records WHERE id = ${authId} AND deleted_at IS NULL`)).toBe('1');
    expect(dbQuery(`SELECT COUNT(*) FROM autori WHERE id = ${autoreId}`)).toBe('1');
  });

  // ── activities/show.php:103 — confirm-destructive: soft-delete activity ─────
  test('activities/show.php:103 — delete activity (soft-delete) shows Swal + sets deleted_at', async () => {
    const actId = makeActivity(`${TAG} Activity to delete`);
    await page.goto(`${BASE}/admin/archives/activities/${actId}`);
    await page.waitForSelector(`#archivesDeleteActivity_${actId}`, { timeout: 8000 });

    await page.locator(`#archivesDeleteActivity_${actId} button`).click();
    const popup = page.locator('.swal2-popup');
    await expect(popup).toBeVisible();
    await expect(popup).toContainText('Eliminare questa attività');

    await Promise.all([
      page.waitForURL(/\/admin\/archives\/activities$/, { timeout: 12000 }),
      page.locator('.swal2-confirm').click(),
    ]);

    expect(dbQuery(`SELECT COUNT(*) FROM archive_activities WHERE id = ${actId} AND deleted_at IS NULL`)).toBe('0');
    expect(dbQuery(`SELECT COUNT(*) FROM archive_activities WHERE id = ${actId} AND deleted_at IS NOT NULL`)).toBe('1');
  });

  // ── activities/show.php:197 — confirm-destructive: detach relation from activity
  test('activities/show.php:197 — detach relation from activity shows Swal + deletes relation', async () => {
    const actId = makeActivity(`${TAG} Activity with relation`);
    const placeId = makePlace(`${TAG} Place target of activity`);
    const relId = makeRelation('archive_activity', actId, 'archive_place', placeId, `${TAG}_predA`);

    await page.goto(`${BASE}/admin/archives/activities/${actId}`);
    await page.waitForSelector(`#archivesDetachRel_${relId}`, { timeout: 8000 });

    await page.locator(`#archivesDetachRel_${relId} button`).click();
    const popup = page.locator('.swal2-popup');
    await expect(popup).toBeVisible();
    await expect(popup).toContainText('Scollegare questa relazione');

    // _return_to redirects back to the activity detail page.
    await Promise.all([
      page.waitForURL(new RegExp(`/admin/archives/activities/${actId}$`), { timeout: 12000 }),
      page.locator('.swal2-confirm').click(),
    ]);

    // Relation hard-deleted; the activity itself survives.
    expect(dbQuery(`SELECT COUNT(*) FROM archive_relations WHERE id = ${relId}`)).toBe('0');
    expect(dbQuery(`SELECT COUNT(*) FROM archive_activities WHERE id = ${actId} AND deleted_at IS NULL`)).toBe('1');
  });

  // ── places/show.php:96 — confirm-destructive: delete place w/ dynamic warning
  test('places/show.php:96 — delete place (soft-delete) shows dynamic Swal warning + sets deleted_at', async () => {
    const placeId = makePlace(`${TAG} Place to delete`);
    // Give it a relation so the message includes the dynamic relation count.
    const otherPlace = makePlace(`${TAG} Place related`);
    makeRelation('archive_place', placeId, 'archive_place', otherPlace, `${TAG}_predP`);

    await page.goto(`${BASE}/admin/archives/places/${placeId}`);
    await page.waitForSelector(`#archivesDeletePlace_${placeId}`, { timeout: 8000 });

    await page.locator(`#archivesDeletePlace_${placeId} button`).click();
    const popup = page.locator('.swal2-popup');
    await expect(popup).toBeVisible();
    // Dynamic blast-radius message: mentions the related relation count.
    await expect(popup).toContainText('relazione');

    await Promise.all([
      page.waitForURL(/\/admin\/archives\/places$/, { timeout: 12000 }),
      page.locator('.swal2-confirm').click(),
    ]);

    expect(dbQuery(`SELECT COUNT(*) FROM archive_places WHERE id = ${placeId} AND deleted_at IS NULL`)).toBe('0');
    expect(dbQuery(`SELECT COUNT(*) FROM archive_places WHERE id = ${placeId} AND deleted_at IS NOT NULL`)).toBe('1');
  });

  // ── places/show.php:169 — confirm-destructive: detach relation from place ───
  test('places/show.php:169 — detach relation from place shows Swal + deletes relation', async () => {
    const placeId = makePlace(`${TAG} Place with detachable relation`);
    const authId = makeAuthority(`${TAG} Authority target of place`);
    const relId = makeRelation('archive_place', placeId, 'authority_record', authId, `${TAG}_predPR`);

    await page.goto(`${BASE}/admin/archives/places/${placeId}`);
    await page.waitForSelector(`#archivesDetachRel_${relId}`, { timeout: 8000 });

    await page.locator(`#archivesDetachRel_${relId} button`).click();
    const popup = page.locator('.swal2-popup');
    await expect(popup).toBeVisible();
    await expect(popup).toContainText('Scollegare questa relazione');

    // _return_to redirects back to the place detail page.
    await Promise.all([
      page.waitForURL(new RegExp(`/admin/archives/places/${placeId}$`), { timeout: 12000 }),
      page.locator('.swal2-confirm').click(),
    ]);

    // Relation hard-deleted; the place itself survives.
    expect(dbQuery(`SELECT COUNT(*) FROM archive_relations WHERE id = ${relId}`)).toBe('0');
    expect(dbQuery(`SELECT COUNT(*) FROM archive_places WHERE id = ${placeId} AND deleted_at IS NULL`)).toBe('1');
  });
});
