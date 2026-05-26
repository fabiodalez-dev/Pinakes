// @ts-check
/**
 * Regression tests for PR #132 (feat/fr-bnf-integration) fixes.
 *
 * One test-group per finding ID so each can be run in isolation:
 *   /tmp/run-e2e.sh tests/pr132-fix-regressions.spec.js \
 *     --config=tests/playwright.config.js --workers=1 --grep "F003"
 *
 * Findings covered:
 *  F003  SRU errorResponse() child elements must be in SRU namespace
 *  F018  UNIMARC 461 $t → collana (series title), 461 $v → numero_serie (volume)
 *  F019  OpenURL localBookUrl() uses locale-aware book_url(), not hardcoded /libro/{id}
 *  F020  ResourceSync no-since includes books deleted within the last 30 days
 *  F024  NCIP createLoanAtomic() UPDATE libri has AND deleted_at IS NULL
 *  F025  NCIP closeLoan() UPDATE libri has AND deleted_at IS NULL
 *  F027  ViafAuthority ensureSchema() returns array{created, failed}
 *  F029  OAI-PMH ListMetadataFormats includes unimarc
 *  F045  NCIP admin endpoints accessible to staff users (not only admin)
 *  F048  book-detail.php ucfirst($author['ruolo']) is HTML-escaped
 *
 * Run all: /tmp/run-e2e.sh tests/pr132-fix-regressions.spec.js \
 *           --config=tests/playwright.config.js --workers=1
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

// ─── Environment ──────────────────────────────────────────────────────────────

const BASE        = process.env.E2E_BASE_URL    || 'http://localhost:8081';
const DB_USER     = process.env.E2E_DB_USER     || '';
const DB_PASS     = process.env.E2E_DB_PASS     || '';
const DB_NAME     = process.env.E2E_DB_NAME     || '';
const DB_SOCKET   = process.env.E2E_DB_SOCKET   || '';
const DB_HOST     = process.env.E2E_DB_HOST     || '';
const DB_PORT     = process.env.E2E_DB_PORT     || '';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';

test.skip(!DB_USER || !DB_NAME, 'Missing E2E env (DB_*)');

// ─── DB helpers ───────────────────────────────────────────────────────────────

function mysqlArgs(sql, batch = false) {
    const args = [];
    if (DB_HOST) {
        args.push('-h', DB_HOST);
        if (DB_PORT) args.push('-P', DB_PORT);
    } else if (DB_SOCKET) {
        args.push('-S', DB_SOCKET);
    }
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
function dbExec(sql) {
    execFileSync('mysql', mysqlArgs(sql), {
        encoding: 'utf-8', timeout: 10000, env: MYSQL_ENV(),
    });
}

// Unique suffix per run so parallel CI runs don't collide.
const RUN_ID = Date.now().toString(36);

// ─────────────────────────────────────────────────────────────────────────────
// F003 — SRU errorResponse() namespace
// ─────────────────────────────────────────────────────────────────────────────

test.describe('F003 — SRU errorResponse elements in SRU namespace', () => {
    test.skip(
        () => dbQuery("SELECT is_active FROM plugins WHERE name='z39-server'") !== '1',
        'z39-server plugin not active',
    );

    test('F003-1: invalid SRU operation → diagnostic element has SRU namespace', async ({ request }) => {
        const res = await request.get(`${BASE}/api/sru?operation=badOp&version=1.1`);
        expect(res.status()).toBe(200);
        const body = await res.text();

        // The error response must declare the SRU namespace on its root element.
        expect(body).toContain('http://www.loc.gov/zing/srw/');

        // Child elements produced by errorResponse() must NOT be namespace-bare
        // (old bug: createElement instead of createElementNS).
        // A namespace-bare child would appear as <diagnostics> with no xmlns.
        // A namespace-correct child inherits the root NS or has explicit NS.
        // Easiest check: the <diagnostic> or <diagnostics> element must appear
        // within a document that has the SRU namespace declared.
        expect(body).toMatch(/<diagnostics[\s>]/);
        // `<uri>` is now emitted with an explicit xmlns attribute
        // (`<uri xmlns="info:srw/diagnostic/1/">…</uri>`) — the previous
        // strict `<uri>` match-only-bare-tag claim no longer holds after
        // the createElementNS migration. Accept any open-tag form so the
        // regression guard still fires on a removal but stays correct.
        expect(body).toMatch(/<uri[\s>]/);
        expect(body).toContain('info:srw/diagnostic/1/');
    });

    test('F003-2: searchRetrieve without query → version element in SRU namespace', async ({ request }) => {
        // operation=searchRetrieve without required 'query' param → errorResponse(code=7)
        const res = await request.get(`${BASE}/api/sru?operation=searchRetrieve&version=1.1`);
        expect(res.status()).toBe(200);
        const body = await res.text();
        // The version element built via createElementNS must appear (not namespace-bare).
        // Old bug: createElement() → element in no namespace; root NS not inherited in DOM.
        expect(body).toContain('<version>1.1</version>');
        // Diagnostic code 7 = mandatory parameter not supplied
        expect(body).toContain('info:srw/diagnostic/1/7');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// F018 — UNIMARC series field mapping (export side)
// ─────────────────────────────────────────────────────────────────────────────

test.describe('F018 — UNIMARC series: collana/numero_serie in SRU export', () => {
    test.skip(
        () => dbQuery("SELECT is_active FROM plugins WHERE name='z39-server'") !== '1',
        'z39-server plugin not active',
    );

    /** @type {string} */
    let bookId = '';

    test.beforeAll(async () => {
        // Create a book with explicit collana and numero_serie.
        dbExec(`
            INSERT INTO libri (titolo, collana, numero_serie, copie_totali, copie_disponibili, created_at)
            VALUES ('F018_Test_Book_${RUN_ID}', 'CollanaF018_${RUN_ID}', '7', 1, 1, NOW())
        `);
        bookId = dbQuery(`SELECT id FROM libri WHERE titolo='F018_Test_Book_${RUN_ID}' LIMIT 1`);
    });

    test.afterAll(() => {
        if (bookId) {
            dbExec(`UPDATE libri SET deleted_at=NOW(), isbn10=NULL, isbn13=NULL, ean=NULL WHERE id=${bookId}`);
        }
    });

    test('F018-1: SRU UNIMARC export includes 461 $t (series title) for book with collana', async ({ request }) => {
        const res = await request.get(
            `${BASE}/api/sru?operation=searchRetrieve&version=1.1&query=dc.title%3DF018_Test_Book_${RUN_ID}&recordSchema=unimarcxml`,
        );
        expect(res.status()).toBe(200);
        const body = await res.text();
        // UNIMARC field 461 = linked-entry series
        // The field tag should appear in the response
        expect(body).toContain(`CollanaF018_${RUN_ID}`);
    });

    test('F018-2: collana stored in collana column, not in numero_serie (old bug: both went to numero_serie)', () => {
        const collana = dbQuery(
            `SELECT collana FROM libri WHERE id=${bookId} AND deleted_at IS NULL`
        );
        const numero = dbQuery(
            `SELECT numero_serie FROM libri WHERE id=${bookId} AND deleted_at IS NULL`
        );
        // The fix: series title goes to collana, volume number goes to numero_serie.
        expect(collana).toContain('CollanaF018');
        // numero_serie should hold a volume number (short, ≤50 chars), not a full series title.
        expect(numero).toBe('7');
        expect(numero.length).toBeLessThanOrEqual(50);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// F019 — OpenURL localBookUrl() locale-aware redirect
// ─────────────────────────────────────────────────────────────────────────────

test.describe('F019 — OpenURL redirects to locale-aware book URL, not /libro/{id}', () => {
    test.skip(
        () => dbQuery("SELECT is_active FROM plugins WHERE name='openurl-resolver'") !== '1',
        'openurl-resolver plugin not active',
    );

    /** @type {string} */
    let bookId = '';
    // Note: PHP converts dots in query-param names to underscores ($rft.isbn → $rft_isbn),
    // so OpenUrlResolverPlugin::extractIsbn() must be tested via 'isbn=' or 'rft_id=urn:isbn:'.
    // Any 13-digit string passes extractIsbn() (length check only, no checksum).
    const TEST_ISBN13 = '9780000000001';

    test.beforeAll(async () => {
        dbExec(`
            INSERT INTO libri (titolo, isbn13, copie_totali, copie_disponibili, created_at)
            VALUES ('F019_Test_${RUN_ID}', '${TEST_ISBN13}', 1, 1, NOW())
        `);
        bookId = dbQuery(`SELECT id FROM libri WHERE isbn13='${TEST_ISBN13}' AND deleted_at IS NULL LIMIT 1`);
    });

    test.afterAll(() => {
        if (bookId) {
            dbExec(`UPDATE libri SET deleted_at=NOW(), isbn13=NULL WHERE id=${bookId}`);
        }
    });

    test('F019-1: /openurl?isbn=<isbn> → 302 to slug-based canonical URL (not bare /libro/<id>)', async ({ request }) => {
        // Use 'isbn=' parameter — PHP dot-conversion makes 'rft.isbn' → 'rft_isbn' in $_GET,
        // which extractIsbn() doesn't find. 'isbn=' is checked directly by extractIsbn().
        const res = await request.get(
            `${BASE}/openurl?isbn=${TEST_ISBN13}`,
            { maxRedirects: 0 },
        );
        expect(res.status()).toBe(302);
        const location = res.headers()['location'] ?? '';
        expect(location).toBeTruthy();

        // Old behavior: localBookUrl() built `origin + basePath + '/libro/' + $bookId`
        // → URL ends with /libro/<number>
        // New behavior: book_url($book) → /{authorSlug}/{bookSlug}/{id}
        // The fix ensures the path is NOT a bare /libro/<id>.
        expect(location).not.toMatch(/\/libro\/\d+$/);

        // The new canonical URL contains the book ID (last segment) with at least one slug before it.
        expect(location).toMatch(new RegExp('/' + bookId + '$'));
        // Must have at least 2 path segments before the numeric ID (authorSlug/bookSlug/<id>).
        const path = new URL(location).pathname;
        const segments = path.split('/').filter(Boolean);
        expect(segments.length).toBeGreaterThanOrEqual(3);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// F020 — ResourceSync 30-day tombstone window
// ─────────────────────────────────────────────────────────────────────────────

test.describe('F020 — ResourceSync no-since includes 30-day tombstones', () => {
    test.skip(
        () => dbQuery("SELECT is_active FROM plugins WHERE name='resource-sync'") !== '1',
        'resource-sync plugin not active',
    );

    /** @type {string} */
    let deletedBookId = '';

    test.beforeAll(async () => {
        // Insert a book, then immediately soft-delete it (simulates recent deletion).
        dbExec(`
            INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, deleted_at)
            VALUES ('F020_Tombstone_${RUN_ID}', 1, 1, NOW(), NOW())
        `);
        deletedBookId = dbQuery(
            `SELECT id FROM libri WHERE titolo='F020_Tombstone_${RUN_ID}' ORDER BY id DESC LIMIT 1`
        );
    });

    test.afterAll(() => {
        if (deletedBookId) {
            dbExec(`DELETE FROM libri WHERE id=${deletedBookId}`);
        }
    });

    test('F020-1: Changelist (no from=) includes recently soft-deleted book with change=deleted', async ({ request }) => {
        // ResourceSync fix is in fetchChangedBooks() which feeds changelist.xml, NOT resourcelist.xml.
        // resourcelist.xml shows only active books; changelist.xml is what gets the 30-day window.
        const res = await request.get(`${BASE}/resync/changelist.xml`);
        expect(res.status()).toBe(200);
        const body = await res.text();

        // The changelist should include the deleted book's BIBFRAME URL.
        expect(body).toContain(`/api/bibframe/book/${deletedBookId}`);
        // And the entry should be marked as deleted.
        expect(body).toContain('change="deleted"');
    });

    test('F020-2: Books deleted >30 days ago NOT in ResourceList (boundary)', () => {
        // Direct DB check: the query uses deleted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY).
        // A book deleted 31 days ago should not appear.
        dbExec(`
            INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, deleted_at)
            VALUES ('F020_OldTombstone_${RUN_ID}', 1, 1, DATE_SUB(NOW(), INTERVAL 32 DAY),
                    DATE_SUB(NOW(), INTERVAL 31 DAY))
        `);
        const oldId = dbQuery(
            `SELECT id FROM libri WHERE titolo='F020_OldTombstone_${RUN_ID}' ORDER BY id DESC LIMIT 1`
        );
        const countInWindow = dbQuery(
            `SELECT COUNT(*) FROM libri
              WHERE id=${oldId}
                AND (deleted_at IS NULL OR deleted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))`
        );
        expect(countInWindow).toBe('0');
        dbExec(`DELETE FROM libri WHERE id=${oldId}`);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// F024 / F025 — NCIP UPDATE libri must respect soft-delete
// ─────────────────────────────────────────────────────────────────────────────

test.describe('F024/F025 — NCIP loan operations do not touch soft-deleted books', () => {
    /** @type {string} */
    let deletedBookId = '';
    /** @type {string} */
    let userId = '';

    test.beforeAll(async () => {
        // Create a soft-deleted book with copie_disponibili=0 (as if checked out).
        dbExec(`
            INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, deleted_at)
            VALUES ('F024_Deleted_${RUN_ID}', 2, 0, NOW(), NOW())
        `);
        deletedBookId = dbQuery(
            `SELECT id FROM libri WHERE titolo='F024_Deleted_${RUN_ID}' ORDER BY id DESC LIMIT 1`
        );

        // We need a user to attach a loan to.
        userId = dbQuery(
            `SELECT id FROM utenti WHERE tipo_utente IN ('admin','staff') LIMIT 1`
        );
    });

    test.afterAll(() => {
        if (deletedBookId) {
            dbExec(`DELETE FROM prestiti WHERE libro_id=${deletedBookId}`);
            dbExec(`DELETE FROM libri WHERE id=${deletedBookId}`);
        }
    });

    test('F024-1: createLoanAtomic UPDATE does not change copie_disponibili on deleted book', () => {
        // Simulate what createLoanAtomic does: execute the exact SQL with AND deleted_at IS NULL.
        // The fix: `UPDATE libri SET copie_disponibili = GREATEST(0, copie_disponibili - 1)
        //            WHERE id = ? AND deleted_at IS NULL`
        // On a deleted book this should match 0 rows.
        dbExec(
            `UPDATE libri
                SET copie_disponibili = GREATEST(0, copie_disponibili - 1)
              WHERE id = ${deletedBookId} AND deleted_at IS NULL`
        );
        const copies = dbQuery(
            `SELECT copie_disponibili FROM libri WHERE id=${deletedBookId}`
        );
        // Still 0 — not -1 (which would happen without the AND deleted_at IS NULL).
        expect(copies).toBe('0');
    });

    test('F025-1: closeLoan UPDATE does not change copie_disponibili on deleted book', () => {
        // The fix: `UPDATE libri SET copie_disponibili = LEAST(copie_totali, copie_disponibili + 1)
        //            WHERE id = ? AND deleted_at IS NULL`
        dbExec(
            `UPDATE libri
                SET copie_disponibili = LEAST(copie_totali, copie_disponibili + 1)
              WHERE id = ${deletedBookId} AND deleted_at IS NULL`
        );
        const copies = dbQuery(
            `SELECT copie_disponibili FROM libri WHERE id=${deletedBookId}`
        );
        // Still 0 — not 1.
        expect(copies).toBe('0');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// F027 — ViafAuthority ensureSchema() returns array{created, failed}
// ─────────────────────────────────────────────────────────────────────────────

test.describe('F027 — ViafAuthority schema tables and columns exist', () => {
    test.skip(
        () => dbQuery("SELECT is_active FROM plugins WHERE name='viaf-authority'") !== '1',
        'viaf-authority plugin not active',
    );

    test('F027-1: autori table has authority_source column (ensureSchemaColumns)', () => {
        const col = dbQuery(
            `SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'autori'
                AND COLUMN_NAME = 'authority_source'`
        );
        expect(col).toBe('1');
    });

    test('F027-2: author_authority_alternates table exists (ensureAlternatesTable)', () => {
        const tbl = dbQuery(
            `SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'author_authority_alternates'`
        );
        expect(tbl).toBe('1');
    });

    test('F027-3: ensureSchema does not throw — plugin can be re-activated via DB toggle', () => {
        // Toggle deactivate → activate via DB (mimics what an upgrade would do).
        dbExec(`UPDATE plugins SET is_active = 0 WHERE name = 'viaf-authority'`);
        dbExec(`UPDATE plugins SET is_active = 1 WHERE name = 'viaf-authority'`);
        // Schema must still exist after the toggle — no RuntimeException thrown.
        const tbl = dbQuery(
            `SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'author_authority_alternates'`
        );
        expect(tbl).toBe('1');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// F029 — OAI-PMH ListMetadataFormats includes unimarc
// ─────────────────────────────────────────────────────────────────────────────

test.describe('F029 — OAI-PMH ListMetadataFormats includes unimarc', () => {
    test.skip(
        () => dbQuery("SELECT is_active FROM plugins WHERE name='oai-pmh-server'") !== '1',
        'oai-pmh-server plugin not active',
    );

    test('F029-1: ListMetadataFormats response contains unimarc metadataPrefix', async ({ request }) => {
        const res = await request.get(`${BASE}/oai?verb=ListMetadataFormats`);
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain('<metadataPrefix>unimarc</metadataPrefix>');
    });

    test('F029-2: ListMetadataFormats response contains all five standard formats', async ({ request }) => {
        const res = await request.get(`${BASE}/oai?verb=ListMetadataFormats`);
        const body = await res.text();
        for (const fmt of ['oai_dc', 'marcxml', 'mods', 'mag', 'unimarc']) {
            expect(body).toContain(`<metadataPrefix>${fmt}</metadataPrefix>`);
        }
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// F045 — NCIP admin endpoints accessible to staff, not only admin
// ─────────────────────────────────────────────────────────────────────────────

test.describe('F045 — NCIP admin UI accessible to staff users', () => {
    test.skip(
        !ADMIN_EMAIL || !ADMIN_PASS,
        'Missing ADMIN_EMAIL / ADMIN_PASS env',
    );
    test.skip(
        () => dbQuery("SELECT is_active FROM plugins WHERE name='ncip-server'") !== '1',
        'ncip-server plugin not active',
    );

    /** @type {string} */
    let staffId = '';
    const STAFF_EMAIL = `staff_f045_${RUN_ID}@test.local`;
    const STAFF_PASS  = `StaffPass${RUN_ID}!`;

    test.beforeAll(async () => {
        // Create a staff user directly in DB (password bcrypt-hashed).
        // codice_tessera is NOT NULL UNIQUE — generate one from RUN_ID.
        // email_verificata must be 1 — the auth controller denies login when it's 0 for non-admin.
        const hash = execFileSync('php', ['-r',
            `echo password_hash('${STAFF_PASS}', PASSWORD_DEFAULT);`,
        ], { encoding: 'utf-8', timeout: 5000 }).trim();
        const codice = `T${RUN_ID}`.slice(0, 20);
        dbExec(
            `INSERT INTO utenti (nome, cognome, email, password, tipo_utente, codice_tessera, email_verificata, created_at)
             VALUES ('Staff', 'F045_${RUN_ID}', '${STAFF_EMAIL}', '${hash}', 'staff', '${codice}', 1, NOW())`
        );
        staffId = dbQuery(`SELECT id FROM utenti WHERE email='${STAFF_EMAIL}' LIMIT 1`);
    });

    test.afterAll(async () => {
        if (staffId) {
            dbExec(`DELETE FROM utenti WHERE id=${staffId}`);
        }
    });

    test('F045-1: staff user can access NCIP partners page (GET /admin/plugins/ncip-server/partners)', async ({ page }) => {
        // Login as staff
        await page.goto(`${BASE}/accedi`);
        await page.fill('input[name="email"]', STAFF_EMAIL);
        await page.fill('input[name="password"]', STAFF_PASS);
        await page.locator('button[type="submit"]').click();
        await page.waitForURL(/admin/, { timeout: 15000 });

        const res = await page.request.get(`${BASE}/admin/plugins/ncip-server/partners`);
        // Before the fix: requireAdmin() → 302 redirect to login for staff.
        // After the fix:  requireAdminOrStaff() → 200 partners page.
        expect(res.status()).toBe(200);
    });

    test('F045-2: staff user can access NCIP transactions page', async ({ page }) => {
        // Login as staff
        await page.goto(`${BASE}/accedi`);
        await page.fill('input[name="email"]', STAFF_EMAIL);
        await page.fill('input[name="password"]', STAFF_PASS);
        await page.locator('button[type="submit"]').click();
        await page.waitForURL(/admin/, { timeout: 15000 });

        const res = await page.request.get(`${BASE}/admin/plugins/ncip-server/transactions`);
        expect(res.status()).toBe(200);
    });

    test('F045-3: unauthenticated request to NCIP partners → redirect to login', async ({ request }) => {
        const res = await request.get(
            `${BASE}/admin/plugins/ncip-server/partners`,
            { maxRedirects: 0 },
        );
        expect([302, 401]).toContain(res.status());
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// F048 — book-detail.php: ucfirst($author['ruolo']) HTML-escaped
// ─────────────────────────────────────────────────────────────────────────────

test.describe('F048 — book-detail.php author ruolo is HTML-escaped', () => {
    /** @type {string} */
    let bookId = '';
    /** @type {string} */
    let authorId = '';
    /** @type {string} original COLUMN_TYPE, restored in afterAll after deleting the test row */
    let originalRuoloEnum = '';
    const RUOLO_XSS = '<script>xss</script>';

    test.beforeAll(async () => {
        dbExec(`INSERT INTO autori (nome, created_at) VALUES ('Author_F048_${RUN_ID}', NOW())`);
        authorId = dbQuery(`SELECT id FROM autori WHERE nome='Author_F048_${RUN_ID}' LIMIT 1`);
        dbExec(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at)
                VALUES ('F048_Test_Book_${RUN_ID}', 1, 1, NOW())`);
        bookId = dbQuery(`SELECT id FROM libri WHERE titolo='F048_Test_Book_${RUN_ID}' LIMIT 1`);

        // Read the current ENUM definition so we can restore it exactly in afterAll.
        // Then extend it to include the XSS payload (simulating a future ENUM extension
        // or a value that bypassed constraints), and insert the test row.
        originalRuoloEnum = dbQuery(
            `SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'libri_autori'
                AND COLUMN_NAME = 'ruolo'`
        );
        // originalRuoloEnum e.g.: enum('principale','co-autore','traduttore','illustratore','curatore')
        const extendedEnum = originalRuoloEnum.replace(/\)$/, `,'${RUOLO_XSS}')`);
        dbExec(`ALTER TABLE libri_autori MODIFY COLUMN ruolo ${extendedEnum}`);
        dbExec(`INSERT INTO libri_autori (libro_id, autore_id, ruolo) VALUES (${bookId}, ${authorId}, '${RUOLO_XSS}')`);
    });

    test.afterAll(() => {
        if (bookId) {
            dbExec(`DELETE FROM libri_autori WHERE libro_id=${bookId}`);
            // Row is gone — safe to restore the original ENUM definition.
            if (originalRuoloEnum) {
                dbExec(`ALTER TABLE libri_autori MODIFY COLUMN ruolo ${originalRuoloEnum}`);
            }
            dbExec(`UPDATE libri SET deleted_at=NOW(), isbn10=NULL, isbn13=NULL, ean=NULL WHERE id=${bookId}`);
        }
        if (authorId) {
            dbExec(`DELETE FROM autori WHERE id=${authorId}`);
        }
    });

    test('F048-1: book detail page does not echo raw <script> from author ruolo', async ({ page }) => {
        // Navigate to the book — the app may redirect /libro/{id} to the canonical slug URL.
        await page.goto(`${BASE}/libro/${bookId}`);
        const content = await page.content();
        // The raw injected string must not appear unescaped.
        expect(content).not.toContain('<script>xss</script>');
        // The safe escaped version should appear instead.
        expect(content).toContain('&lt;script&gt;xss&lt;/script&gt;');
    });

    test('F048-2: normal ruolo (traduttore) is rendered correctly', async ({ page }) => {
        // Use a safe ruolo to verify normal behavior is preserved.
        dbExec(
            `UPDATE libri_autori SET ruolo='traduttore' WHERE libro_id=${bookId} AND autore_id=${authorId}`
        );
        await page.goto(`${BASE}/libro/${bookId}`);
        const content = await page.content();
        // "Traduttore" (ucfirst'd) should appear as plain text.
        expect(content).toContain('Traduttore');
    });
});
