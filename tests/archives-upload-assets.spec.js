// @ts-check
/**
 * Archives plugin — upload cover image + PDF + audio via admin UI.
 *
 * Picks 3 existing archival_units from the E2E_SEED_* batch and
 * uploads one of each asset type through the real Playwright form
 * submission. Verifies:
 *   - HTTP 303 redirect back to the detail page
 *   - DB columns populated (cover_image_path / document_path / mime)
 *   - File physically present on disk under public/uploads/archives/
 *
 * Uploads are intentionally NOT cleaned up — the files stay attached
 * to the seeded units so they can be inspected on /archivio/{id}.
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

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

const PROJECT_ROOT = path.resolve(__dirname, '..');
const PUBLIC_DIR = path.join(PROJECT_ROOT, 'public');

const TEST_COVER = '/tmp/archive-test-cover.jpg';
const TEST_PDF = '/tmp/archive-test.pdf';
const TEST_AUDIO = '/tmp/archive-test-audio.mp3';

test.describe.serial('Archives — upload cover / PDF / audio end-to-end', () => {
    /** @type {import('@playwright/test').BrowserContext} */
    let context;
    /** @type {import('@playwright/test').Page} */
    let page;

    // Three distinct E2E_SEED units: one gets a PDF + cover, one gets
    // an audio + cover, one gets just a cover. Picked from the persistent
    // seed batch so the uploads survive across reruns for manual
    // inspection on /archivio/{slug}-{id}.
    /** @type {{pdf: number, audio: number, coverOnly: number}} */
    const ids = { pdf: 0, audio: 0, coverOnly: 0 };

    test.beforeAll(async ({ browser }) => {
        // Fixture-file sanity check — a dev that skipped the
        // ffmpeg/pdf generation step will otherwise see a confusing
        // 'input file not found' from Playwright's setInputFiles.
        for (const fp of [TEST_COVER, TEST_PDF, TEST_AUDIO]) {
            expect(fs.existsSync(fp), `fixture missing: ${fp}`).toBe(true);
        }

        const pickSql = (levelFilter) =>
            `SELECT id FROM archival_units
              WHERE reference_code LIKE 'E2E_SEED_%'
                AND deleted_at IS NULL
                AND cover_image_path IS NULL
                AND document_path IS NULL
                ${levelFilter}
              ORDER BY id ASC LIMIT 1`;
        const pdfId = Number(dbQuery(pickSql("AND level IN ('fonds','series','file')")));
        const audioId = Number(dbQuery(pickSql("AND id > " + pdfId)));
        const coverOnlyId = Number(dbQuery(pickSql("AND id > " + audioId)));
        expect(pdfId, 'no seeded row available for PDF test').toBeGreaterThan(0);
        expect(audioId, 'no seeded row available for audio test').toBeGreaterThan(0);
        expect(coverOnlyId, 'no seeded row available for cover-only test').toBeGreaterThan(0);
        ids.pdf = pdfId;
        ids.audio = audioId;
        ids.coverOnly = coverOnlyId;

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
        // NO cleanup — seeded fixtures + uploaded files stay in place
        // so the user can open /archivio/{slug}-{id} and inspect the
        // rendered cover / download button / audio player.
        await context?.close();
    });

    // Helper: submit an upload form and assert the row got populated.
    async function submitUpload(id, formSelector, inputName, filePath) {
        await page.goto(`${BASE}/admin/archives/${id}`);
        await page.waitForLoadState('domcontentloaded');
        await page.setInputFiles(`${formSelector} input[name="${inputName}"]`, filePath);
        await Promise.all([
            page.waitForURL(new RegExp(`/admin/archives/${id}`), { timeout: 15000 }),
            page.click(`${formSelector} button[type="submit"]`),
        ]);
    }

    test('1. Upload cover image (JPEG) — row #1 (PDF candidate)', async () => {
        await submitUpload(ids.pdf, 'form[action*="/upload-cover"]', 'cover', TEST_COVER);
        const row = dbQuery(`SELECT cover_image_path FROM archival_units WHERE id = ${ids.pdf}`);
        expect(row).toMatch(/^\/uploads\/archives\/covers\/\d+-[a-f0-9]{8}\.jpg$/);
        expect(fs.existsSync(path.join(PUBLIC_DIR, row)), 'cover file on disk').toBe(true);
    });

    test('2. Upload document (PDF) — row #1', async () => {
        await submitUpload(ids.pdf, 'form[action*="/upload-document"]', 'document', TEST_PDF);
        const row = dbQuery(
            `SELECT CONCAT_WS('|', document_path, document_mime, document_filename)
               FROM archival_units WHERE id = ${ids.pdf}`
        );
        const [docPath, mime, filename] = row.split('|');
        expect(docPath).toMatch(/^\/uploads\/archives\/documents\/\d+-[a-f0-9]{8}\.pdf$/);
        expect(mime).toBe('application/pdf');
        expect(filename).toBe('archive-test.pdf');
        expect(fs.existsSync(path.join(PUBLIC_DIR, docPath)), 'PDF file on disk').toBe(true);
        // Sanity: file content starts with %PDF-
        const head = fs.readFileSync(path.join(PUBLIC_DIR, docPath)).slice(0, 5).toString();
        expect(head).toBe('%PDF-');
    });

    test('3. Upload cover image (JPEG) — row #2 (audio candidate)', async () => {
        await submitUpload(ids.audio, 'form[action*="/upload-cover"]', 'cover', TEST_COVER);
        const row = dbQuery(`SELECT cover_image_path FROM archival_units WHERE id = ${ids.audio}`);
        expect(row).toMatch(/^\/uploads\/archives\/covers\/\d+-[a-f0-9]{8}\.jpg$/);
    });

    test('4. Upload audio (MP3) — row #2, should trigger player in frontend', async () => {
        await submitUpload(ids.audio, 'form[action*="/upload-document"]', 'document', TEST_AUDIO);
        const row = dbQuery(
            `SELECT CONCAT_WS('|', document_path, document_mime, document_filename)
               FROM archival_units WHERE id = ${ids.audio}`
        );
        const [docPath, mime, filename] = row.split('|');
        expect(docPath).toMatch(/^\/uploads\/archives\/documents\/\d+-[a-f0-9]{8}\.mp3$/);
        expect(mime).toBe('audio/mpeg');
        expect(filename).toBe('archive-test-audio.mp3');
        expect(fs.existsSync(path.join(PUBLIC_DIR, docPath)), 'MP3 file on disk').toBe(true);
    });

    test('5. Upload cover only — row #3', async () => {
        await submitUpload(ids.coverOnly, 'form[action*="/upload-cover"]', 'cover', TEST_COVER);
        const row = dbQuery(`SELECT cover_image_path FROM archival_units WHERE id = ${ids.coverOnly}`);
        expect(row).toMatch(/^\/uploads\/archives\/covers\/\d+-[a-f0-9]{8}\.jpg$/);
    });

    test('6. Public frontend: PDF row renders download button', async () => {
        // Use the short /archivio/{id} form — the plugin 301-redirects
        // to the canonical /archivio/{slug}-{id} URL, which
        // page.request.get follows by default.
        const resp = await page.request.get(`${BASE}/archivio/${ids.pdf}`);
        const body = await resp.text();
        expect(body).toContain('Scarica documento');
        expect(body).toContain('archive-test.pdf');
    });

    test('7. Public frontend: audio row renders <audio> + green-audio-player', async () => {
        const resp = await page.request.get(`${BASE}/archivio/${ids.audio}`);
        const body = await resp.text();
        expect(body).toContain('green-audio-player');
        expect(body).toMatch(/<audio[^>]+\.mp3/);
    });

    test('8. Public frontend: cover images are served by nginx', async () => {
        const coverPath = dbQuery(
            `SELECT cover_image_path FROM archival_units WHERE id = ${ids.coverOnly}`
        );
        expect(coverPath).toMatch(/^\/uploads\/archives\/covers\//);
        const resp = await page.request.get(`${BASE}${coverPath}`);
        expect(resp.status()).toBe(200);
        expect(resp.headers()['content-type']).toContain('image/jpeg');
    });

    // ─── Negative: mime whitelist rejection ──────────────────────────────
    test('9. NEGATIVE: uploading a PHP file with .jpg extension is rejected', async () => {
        // Build a file that fails the finfo allow-list: the extension
        // claims image/jpeg but the real MIME (sniffed via libmagic) is
        // text/x-php or text/html. Server must reject + not touch the
        // DB / filesystem for this row.
        const fakeJpgPath = '/tmp/archive-test-evil.jpg';
        fs.writeFileSync(fakeJpgPath, '<?php phpinfo(); ?>\n');
        try {
            const before = dbQuery(
                `SELECT IFNULL(cover_image_path,'') FROM archival_units WHERE id = ${ids.coverOnly}`
            );
            await page.goto(`${BASE}/admin/archives/${ids.coverOnly}`);
            await page.waitForLoadState('domcontentloaded');
            await page.setInputFiles(
                'form[action*="/upload-cover"] input[name="cover"]',
                fakeJpgPath
            );
            await Promise.all([
                page.waitForURL(new RegExp(`/admin/archives/${ids.coverOnly}`), { timeout: 15000 }),
                page.click('form[action*="/upload-cover"] button[type="submit"]'),
            ]);
            const after = dbQuery(
                `SELECT IFNULL(cover_image_path,'') FROM archival_units WHERE id = ${ids.coverOnly}`
            );
            expect(
                after,
                'server accepted a PHP payload with a .jpg extension — MIME whitelist is not enforced'
            ).toBe(before);
        } finally {
            fs.unlinkSync(fakeJpgPath);
        }
    });

    test('10. NEGATIVE: uploading an .exe as document is rejected', async () => {
        const fakeExePath = '/tmp/archive-test-fake.exe';
        // Fake PE header — finfo detects it as application/x-dosexec,
        // which is NOT in the document mime whitelist.
        fs.writeFileSync(fakeExePath, 'MZ\x90\x00\x03\x00\x00\x00\x04\x00');
        try {
            const before = dbQuery(
                `SELECT IFNULL(document_path,'') FROM archival_units WHERE id = ${ids.coverOnly}`
            );
            await page.goto(`${BASE}/admin/archives/${ids.coverOnly}`);
            await page.waitForLoadState('domcontentloaded');
            await page.setInputFiles(
                'form[action*="/upload-document"] input[name="document"]',
                fakeExePath
            );
            await Promise.all([
                page.waitForURL(new RegExp(`/admin/archives/${ids.coverOnly}`), { timeout: 15000 }),
                page.click('form[action*="/upload-document"] button[type="submit"]'),
            ]);
            const after = dbQuery(
                `SELECT IFNULL(document_path,'') FROM archival_units WHERE id = ${ids.coverOnly}`
            );
            expect(after, 'server accepted an .exe as document').toBe(before);
        } finally {
            fs.unlinkSync(fakeExePath);
        }
    });
});
