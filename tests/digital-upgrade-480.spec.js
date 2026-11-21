// @ts-check
// Issue #480 — Digital Library files uploaded on an older release, then the upgrade.
//
// Run twice by scripts/reinstall-test.sh (Test B), against the same sandbox install:
//   E2E_UPGRADE_PHASE=before  on the old release (files go to public/uploads/digital up to
//                             0.8.5, to storage/digital from 0.8.6-rc.1, and anyone can
//                             download them), the admin uploads through the book form
//                             like a person would;
//   E2E_UPGRADE_PHASE=after   on the upgraded install: the same files, byte for byte, now
//                             in storage/digital, still served at the same addresses, and
//                             the per-file visibility of 0.8.6 enforced on them.
// The old book form comes in two shapes: since 0.7.89 a list of attachments (two PDFs and
// an audiobook here), before it one eBook and one audiobook field. Each file has a role:
// "open" stays public, "readers" is limited to signed-in readers, "staff" to staff.
// The state between the two runs (book id, addresses, checksums) is a JSON file.
// The database is only used to prepare the book and a reader, never to upload or check.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ROOT = process.env.E2E_INSTALL_ROOT || path.join(__dirname, '..');
const PHASE = process.env.E2E_UPGRADE_PHASE || '';
const STATE = process.env.E2E_UPGRADE_STATE || path.join(ROOT, 'storage/tmp/digital-upgrade-480.json');
const STORAGE = path.join(ROOT, 'storage/digital');
const LEGACY = path.join(ROOT, 'public/uploads/digital');

/** version_compare() for X.Y.Z[-rc.N]: a prerelease sorts before its final. */
function compareVersions(a, b) {
  const split = v => { const [core, pre = ''] = String(v).split('-', 2); return [core.split('.').map(Number), pre]; };
  const [ca, pa] = split(a), [cb, pb] = split(b);
  for (let i = 0; i < Math.max(ca.length, cb.length); i++) {
    const d = (ca[i] || 0) - (cb[i] || 0);
    if (d !== 0) return d < 0 ? -1 : 1;
  }
  if (pa === pb) return 0;
  if (pa === '') return 1;
  if (pb === '') return -1;
  return pa < pb ? -1 : 1;
}
// Where the installed release writes a new upload: out of the web root since #480.
function uploadFolder() {
  const version = JSON.parse(fs.readFileSync(path.join(ROOT, 'version.json'), 'utf8')).version;
  return compareVersions(version, '0.8.6-rc.1') >= 0
    ? { dir: STORAGE, label: 'storage/digital', version }
    : { dir: LEGACY, label: 'public/uploads/digital', version };
}
// Without a phase this is the regular browser run, where the spec has nothing to do; a
// reason with "required" there would read as broken infrastructure to the skip audit.
test.skip(!PHASE, 'Runs only around a real upgrade (phase given by E2E_UPGRADE_PHASE)');
test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_DB_NAME, 'E2E credentials required');

// GET exactly the path given: Playwright's request.get parses the URL (WHATWG), which
// drops a "/./" segment before sending, so that variant would repeat the plain request.
function getPathAsIs(url) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'digital-upgrade-480-'));
  const bodyPath = path.join(dir, 'response');
  try {
    const status = execFileSync('curl', ['--silent', '--show-error', '--path-as-is', '--max-redirs', '0', '--max-time', '15',
      '--output', bodyPath, '--write-out', '%{http_code}', url], { encoding: 'utf8', timeout: 20000 }).trim();
    return { status: Number(status), body: fs.existsSync(bodyPath) ? fs.readFileSync(bodyPath) : Buffer.alloc(0) };
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
}

function db(sql) {
  const args = ['-u', process.env.E2E_DB_USER, process.env.E2E_DB_NAME, '-N', '-B', '-e', sql];
  if (process.env.E2E_DB_SOCKET) args.unshift('-S', process.env.E2E_DB_SOCKET);
  return execFileSync('mysql', args, { encoding: 'utf8', env: { ...process.env, MYSQL_PWD: process.env.E2E_DB_PASS } }).trim();
}
const sha = buffer => crypto.createHash('sha256').update(buffer).digest('hex');

async function loginWith(page, email, password) {
  await page.locator('input[name=email]').fill(email);
  await page.locator('input[name=password]').fill(password);
  await page.locator('button[type=submit]').click();
  await page.waitForURL(u => !/accedi|login/.test(u.pathname));
}
async function loginAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  if (await page.locator('input[name=email]').isVisible()) {
    await loginWith(page, process.env.E2E_ADMIN_EMAIL, process.env.E2E_ADMIN_PASS);
  }
}
/** The book edit page: /admin/books/edit/N since 0.7.18-ish, /admin/libri/modifica/N before. */
async function openEditForm(page, bookId) {
  for (const route of [`/admin/books/edit/${bookId}`, `/admin/libri/modifica/${bookId}`]) {
    const response = await page.goto(`${BASE}${route}`);
    if (response && response.status() === 200 && await page.locator('#bookForm').count()) return;
  }
  throw new Error(`no edit form for book ${bookId}`);
}
async function saveBook(page) {
  await page.locator('#bookForm button[type=submit]').click();
  const confirmation = page.locator('.swal2-confirm');
  if (await confirmation.isVisible({ timeout: 3000 }).catch(() => false)) await confirmation.click();
  await page.waitForURL(u => !/\/admin\/(books\/(edit|create)|libri\/(modifica|crea))/.test(u.pathname));
}
/** The Uppy result alert after each upload: dismissed like a person would. */
async function dismissAlert(page) {
  const ok = page.locator('.swal2-confirm');
  if (await ok.isVisible({ timeout: 3000 }).catch(() => false)) await ok.click();
}
/** Upload through one of the plugin's uploaders (ebook or audio), as the admin would. */
async function upload(page, kind, files) {
  await page.locator(`#upload-${kind}-btn`).click();
  await page.locator(`#${kind}-uploader input[type=file]`).setInputFiles(files);
}

const pdf = fs.readFileSync(path.join(__dirname, 'fixtures/archive-test.pdf'));
// The audio uploader of the old releases takes MP3, M4A or OGG: an ID3 header and eight
// silent MPEG-1 Layer III frames, which the server's content check reads as audio/mpeg.
const mp3 = Buffer.concat([Buffer.from('ID3\x03\x00\x00\x00\x00\x00\x00', 'latin1'),
  ...Array.from({ length: 8 }, () => Buffer.concat([Buffer.from([0xff, 0xfb, 0x90, 0x64]), Buffer.alloc(413)]))]);
const entry = (url, buffer) => ({ url, sha: sha(buffer), size: buffer.length });

/** The file at url answers 200 with these bytes, and a Range request 206 with its start. */
async function expectServed(request, file) {
  const response = await request.get(`${BASE}${file.url}`, { maxRedirects: 0 });
  expect(response.status(), file.url).toBe(200);
  expect(sha(await response.body()), `${file.url} intact`).toBe(file.sha);
  const range = await request.get(`${BASE}${file.url}`, { maxRedirects: 0, headers: { Range: 'bytes=0-99' } });
  expect(range.status(), `${file.url} with Range`).toBe(206);
  expect(range.headers()['content-encoding'] || '', `${file.url} not compressed`).not.toContain('gzip');
  expect((await range.body()).length).toBe(Math.min(100, file.size));
}

test.describe.serial(`Digital Library across the upgrade (#480) — ${PHASE}`, () => {
  test.skip(PHASE !== 'before', 'before-upgrade phase only');

  /** @type {{bookId:number, mode:string, files:Record<string,{url:string,sha:string,size:number}>}} */
  const state = { bookId: 0, mode: '', files: {} };

  test('the digital-library plugin is active on the old release, with its hooks', async ({ page }) => {
    await loginAdmin(page);
    const id = db("SELECT id FROM plugins WHERE name='digital-library'");
    expect(id, 'digital-library registered').not.toBe('');
    /** Click the plugin's own button, confirm, and check the JSON answer of the action. */
    const toggle = async action => {
      await page.goto(`${BASE}/admin/plugins`);
      const answered = page.waitForResponse(r => r.url().includes(`/admin/plugins/${id}/${action}`));
      await page.locator(`button[data-pk-action="plugins-${action}"][data-plugin-id="${id}"]`).click();
      await page.locator('.swal2-confirm:visible').click();
      const response = await answered;
      const body = await response.json().catch(() => ({}));
      expect(response.status(), JSON.stringify(body)).toBe(200);
      expect(body.success, JSON.stringify(body)).toBe(true);
      await page.locator('.swal2-confirm:visible').click();
    };
    const hooks = () => Number(db(`SELECT COUNT(*) FROM plugin_hooks WHERE plugin_id=${id} AND hook_name='book.form.digital_fields'`));
    if (db(`SELECT is_active FROM plugins WHERE id=${id}`) !== '1') await toggle('activate');
    // Some 0.7.x installers left the plugin active with only its routes hook: the book form
    // then has no uploader. Switching it off and on again, as an admin would, registers them.
    if (hooks() === 0) {
      await toggle('deactivate');
      await toggle('activate');
    }
    expect(db(`SELECT is_active FROM plugins WHERE id=${id}`)).toBe('1');
    expect(hooks(), 'book.form.digital_fields registered').toBeGreaterThan(0);
  });

  test('the admin uploads the digital files through the old book form', async ({ page }) => {
    const title = `Upgrade480-${Date.now()}`;
    db(`INSERT INTO libri (titolo, created_at, updated_at) VALUES ('${title}', NOW(), NOW())`);
    state.bookId = Number(db(`SELECT id FROM libri WHERE titolo='${title}' AND deleted_at IS NULL`));
    await loginAdmin(page);
    await openEditForm(page, state.bookId);
    // The plugin's uploader is drawn by its book.form.digital_fields hook: if it is missing,
    // say what the database holds for the plugin instead of waiting on a button.
    await expect(page.locator('#upload-ebook-btn'), db(
      "SELECT CONCAT_WS(' ', p.id, p.is_active, p.version, p.path, ph.hook_name, ph.callback_method, ph.is_active) FROM plugins p LEFT JOIN plugin_hooks ph ON ph.plugin_id = p.id WHERE p.name = 'digital-library'"
    )).toBeVisible({ timeout: 10000 });
    state.mode = await page.locator('#add-digital-attachment').count() ? 'attachments' : 'fields';

    if (state.mode === 'attachments') {
      await upload(page, 'ebook', [
        { name: 'upgrade-480-open.pdf', mimeType: 'application/pdf', buffer: pdf },
        { name: 'upgrade-480-readers.pdf', mimeType: 'application/pdf', buffer: pdf },
      ]);
      const rows = page.locator('[data-attachment-row]');
      await expect(rows).toHaveCount(2, { timeout: 20000 });
      await dismissAlert(page);
      await upload(page, 'audio', { name: 'upgrade-480-staff.mp3', mimeType: 'audio/mpeg', buffer: mp3 });
      await expect(rows).toHaveCount(3, { timeout: 20000 });
      await dismissAlert(page);
      for (const [i, [role, label, buffer]] of [['open', 'Public edition', pdf], ['readers', 'Readers edition', pdf], ['staff', 'Audiobook', mp3]].entries()) {
        await rows.nth(i).locator('input[name$="[label]"]').fill(label);
        state.files[role] = entry(await rows.nth(i).locator('input[name$="[url]"]').inputValue(), buffer);
      }
    } else {
      // One eBook and one audiobook field: the eBook goes to readers, the audiobook to staff.
      await upload(page, 'ebook', { name: 'upgrade-480-readers.pdf', mimeType: 'application/pdf', buffer: pdf });
      await expect(page.locator('#file_url').first()).toHaveValue(/\/uploads\/digital\/.+\.pdf$/, { timeout: 20000 });
      await dismissAlert(page);
      await upload(page, 'audio', { name: 'upgrade-480-staff.mp3', mimeType: 'audio/mpeg', buffer: mp3 });
      await expect(page.locator('#audio_url').first()).toHaveValue(/\/uploads\/digital\/.+\.mp3$/, { timeout: 20000 });
      await dismissAlert(page);
      state.files.readers = entry(await page.locator('#file_url').first().inputValue(), pdf);
      state.files.staff = entry(await page.locator('#audio_url').first().inputValue(), mp3);
    }
    await saveBook(page);

    // The old release keeps them where it writes uploads (the public folder up to 0.8.5,
    // storage/digital since 0.8.6-rc.1), as uploaded, and lists them on the book.
    const folder = uploadFolder();
    for (const { url, sha: digest } of Object.values(state.files)) {
      expect(url).toMatch(/^\/uploads\/digital\/[A-Za-z0-9._-]+\.(pdf|mp3)$/);
      const file = path.join(folder.dir, path.basename(url));
      expect(fs.existsSync(file), `${url} in ${folder.label} on ${folder.version}`).toBe(true);
      expect(sha(fs.readFileSync(file))).toBe(digest);
      if (folder.dir === STORAGE) {
        expect(fs.existsSync(path.join(LEGACY, path.basename(url))), `${url} not also in public/uploads/digital on ${folder.version}`).toBe(false);
      }
    }
    const listed = db(`SELECT CONCAT_WS('|', file_url, audio_url) FROM libri WHERE id=${state.bookId}`)
      + (state.mode === 'attachments' ? db(`SELECT digital_attachments FROM libri WHERE id=${state.bookId}`) : '');
    for (const { url } of Object.values(state.files)) expect(listed, `${url} saved on the book`).toContain(url);
    fs.mkdirSync(path.dirname(STATE), { recursive: true });
    fs.writeFileSync(STATE, JSON.stringify(state, null, 2));
  });

  test('on the old release an anonymous visitor downloads every file, Range requests included', async ({ request }) => {
    for (const file of Object.values(state.files)) await expectServed(request, file);
  });
});

test.describe.serial('Digital Library across the upgrade (#480) — after', () => {
  test.skip(PHASE !== 'after', 'after-upgrade phase only');

  const RUN = Date.now();
  const password = `Pk480u-${RUN}-x`;
  const reader = { email: `upgrade-reader-${RUN}@example.test`, id: 0 };
  /** @type {{bookId:number, mode:string, files:Record<string,{url:string,sha:string,size:number}>}} */
  let state;
  const name = role => path.basename(state.files[role].url);

  test.beforeAll(() => {
    state = JSON.parse(fs.readFileSync(STATE, 'utf8'));
    const hash = execFileSync('php', ['-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', password], { encoding: 'utf8' });
    db(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata, privacy_accettata) VALUES ('U480${RUN % 1000000}', 'Upgrade', 'Reader', '${reader.email}', '${hash}', 'attivo', 'standard', 1, 1)`);
    reader.id = Number(db(`SELECT id FROM utenti WHERE email='${reader.email}'`));
  });

  test('the uploaded files moved to storage/digital, byte for byte, at the same addresses', async ({ page }) => {
    // The first page under the new code finishes whatever the upgrade left to move.
    await page.goto(`${BASE}/libro/${state.bookId}`);
    for (const [role, { url, sha: digest }] of Object.entries(state.files)) {
      expect(fs.existsSync(path.join(STORAGE, name(role))), `${role} file in storage/digital`).toBe(true);
      expect(sha(fs.readFileSync(path.join(STORAGE, name(role))))).toBe(digest);
      expect(fs.existsSync(path.join(LEGACY, name(role))), `${role} file no longer in public/uploads/digital`).toBe(false);
      const listed = db(`SELECT CONCAT_WS('|', file_url, audio_url, digital_attachments) FROM libri WHERE id=${state.bookId}`);
      expect(listed, `${url} still the address saved on the book`).toContain(url);
    }
  });

  test('with the default left as it was, anonymous visitors still open every file', async ({ page, request }) => {
    await page.goto(`${BASE}/libro/${state.bookId}`);
    const list = page.locator('.digital-attachments-list');
    await expect(list.locator('.digital-attachment--locked')).toHaveCount(0);
    for (const role of Object.keys(state.files)) {
      await expect(page.locator(`.digital-attachments-list [href*="${name(role)}"], .digital-attachments-list [src*="${name(role)}"]`).first()).toBeAttached();
    }
    for (const file of Object.values(state.files)) await expectServed(request, file);
  });

  test('the admin limits one file to signed-in readers and one to staff on the upgraded form', async ({ page }) => {
    await loginAdmin(page);
    await openEditForm(page, state.bookId);
    const rows = page.locator('[data-attachment-row]');
    await expect(rows).toHaveCount(Object.keys(state.files).length);
    for (const [role, visibility] of [['readers', 'users'], ['staff', 'staff']]) {
      const row = rows.filter({ has: page.locator(`input[name$="[url]"][value$="${name(role)}"]`) });
      await expect(row).toHaveCount(1);
      await row.locator('select[name$="[visibility]"]').selectOption(visibility);
    }
    await saveBook(page);
    const saved = JSON.parse(db(`SELECT digital_attachments FROM libri WHERE id=${state.bookId}`));
    const visibilityOf = role => saved.find(a => a.url === state.files[role].url)?.visibility;
    expect(visibilityOf('readers')).toBe('users');
    expect(visibilityOf('staff')).toBe('staff');
    if (state.files.open) expect(visibilityOf('open')).toBe('inherit');
  });

  test('an anonymous visitor is sent to sign in for the readers file and refused the staff one', async ({ page, request }) => {
    const get = (url, headers = {}) => request.get(`${BASE}${url}`, { maxRedirects: 0, headers });
    if (state.files.open) await expectServed(request, state.files.open);
    const toLogin = await get(state.files.readers.url);
    expect(toLogin.status()).toBe(302);
    expect(toLogin.headers()['location']).toContain('return_url=');
    expect((await get(state.files.staff.url)).status()).toBe(403);
    // Another case of the folder name is no way around the check, nor is a dot segment.
    for (const role of ['readers', 'staff']) {
      const { url, sha: digest } = state.files[role];
      for (const variant of [url.replace('/digital/', '/Digital/'), url.replace('/uploads/', '/uploads/./'), url.replace('/uploads/', '/UPLOADS/')]) {
        const response = getPathAsIs(`${BASE}${variant}`);
        const served = response.status === 200 && sha(response.body) === digest;
        expect(served, `${variant} must not serve the file`).toBe(false);
      }
    }
    await page.goto(`${BASE}/libro/${state.bookId}`);
    await expect(page.locator('.digital-attachments-list .digital-attachment--locked')).toHaveCount(1);
    for (const role of ['readers', 'staff']) {
      await expect(page.locator(`[href*="${name(role)}"], [src*="${name(role)}"]`)).toHaveCount(0);
    }
  });

  test('a signed-in reader opens the readers file but not the staff one; the admin opens both', async ({ page, browser }) => {
    const { readers, staff } = state.files;
    await page.goto(`${BASE}/libro/${state.bookId}`);
    const bookPath = new URL(page.url()).pathname;
    await page.locator('.digital-attachment--locked .digital-attachment__login').first().click();
    await loginWith(page, reader.email, password);
    await page.waitForURL(u => u.pathname === bookPath);
    const asReader = await page.request.get(`${BASE}${readers.url}`, { maxRedirects: 0 });
    expect(asReader.status()).toBe(200);
    expect(sha(await asReader.body())).toBe(readers.sha);
    expect((await page.request.get(`${BASE}${staff.url}`, { maxRedirects: 0 })).status()).toBe(403);

    const admin = await browser.newPage();
    await loginAdmin(admin);
    const asStaff = await admin.request.get(`${BASE}${staff.url}`, { maxRedirects: 0 });
    expect(asStaff.status()).toBe(200);
    expect(sha(await asStaff.body())).toBe(staff.sha);
    await admin.close();
  });

  test.afterAll(() => {
    if (reader.id) db(`DELETE FROM utenti WHERE id=${reader.id}`);
  });
});
