// @ts-check
//
// E2E for the Book Club feedback from HansUwe52 (discussion #138):
//   ④ edit an existing meeting (was create/RSVP only)
//   ③ an "Edit meeting" button on the "Next meeting" card
//   ⑤ edit a member from the admin club page
//   ① propose a book that is NOT in the catalogue (external proposal)
//
// A Pinakes admin always passes the book-club capability checks
// (can(): "Pinakes admin/staff always pass"), so the admin can manage any
// club's meetings/members without being seeded as a member. Setup activates the
// bundled plugin through the real admin UI and creates a club, then each test
// drives the real UI.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const DB_USER   = process.env.E2E_DB_USER   || '';
const DB_PASS   = process.env.E2E_DB_PASS   || '';
const DB_HOST   = process.env.E2E_DB_HOST   || '';
const DB_PORT   = process.env.E2E_DB_PORT   || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME   = process.env.E2E_DB_NAME   || '';

const RUN = Date.now().toString(36);
const CLUB_NAME = `Uwe Club ${RUN}`;
const EXT_TITLE = `External Book ${RUN}`;
const EXT_TITLE_2 = `External Book Alt ${RUN}`;
const EXT_AUTHOR_1 = `Jane External ${RUN}`;
const EXT_AUTHOR_2 = `Janet External ${RUN}`;
const EXT_PUBLISHER = `External Press ${RUN}`;

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_PASS || !DB_NAME || (!DB_HOST && !DB_SOCKET), 'E2E credentials not configured');

function mysqlArgs(sql = '', batch = false) {
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

function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql, true), {
    encoding: 'utf-8',
    timeout: 10000,
    env: { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}
function sqlStr(s) { return "'" + String(s).replace(/'/g, "''") + "'"; }

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/accedi`);
  const emailField = page.locator('input[name="email"]');
  if (await emailField.isVisible({ timeout: 3000 }).catch(() => false)) {
    await emailField.fill(ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(u => !u.toString().includes('/accedi'), { timeout: 15000 });
  }
}

/** Future datetime as the datetime-local input wants it (local wall clock). */
function futureLocal(daysAhead, hour) {
  const d = new Date(Date.now() + daysAhead * 86400000);
  const p = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(hour)}:00`;
}

test.describe.serial('Book Club — Uwe feedback', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  let slug = '';

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);

    // Activate the bundled book-club plugin. Visiting /admin/plugins auto-
    // registers it; then activate via the real endpoint (runs onActivate →
    // ensureSchema, so the tables exist on a fresh CI install too).
    await page.goto(`${BASE}/admin/plugins`);
    await page.waitForLoadState('domcontentloaded');
    const bcId = Number(dbQuery(`SELECT id FROM plugins WHERE name='book-club' LIMIT 1`) || '0');
    expect(bcId, 'book-club must be registered as a bundled plugin').toBeGreaterThan(0);
    if (dbQuery(`SELECT is_active FROM plugins WHERE id=${bcId}`) !== '1') {
      const csrf = await page.locator('input[name="csrf_token"]').first().inputValue();
      const resp = await page.request.post(`${BASE}/admin/plugins/${bcId}/activate`, { form: { csrf_token: csrf } });
      expect(resp.ok(), 'plugin activation request should succeed').toBeTruthy();
      await expect.poll(() => dbQuery(`SELECT is_active FROM plugins WHERE id=${bcId}`), { timeout: 8000 }).toBe('1');
    }

    // Create a club through the real admin form; the creator becomes its owner.
    await page.goto(`${BASE}/admin/book-club/new`);
    await page.waitForLoadState('domcontentloaded');
    await page.fill('input[name="name"]', CLUB_NAME);
    await page.locator('button[type="submit"], button:has-text("Salva"), button:has-text("Crea")').first().click();
    await page.waitForLoadState('networkidle');

    slug = dbQuery(`SELECT slug FROM bookclub_clubs WHERE name=${sqlStr(CLUB_NAME)} AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`);
    expect(slug, 'club must have been created with a slug').not.toBe('');
  });

  test.afterAll(async () => {
    // FK-safe cleanup of everything this spec created.
    const clubId = dbQuery(`SELECT id FROM bookclub_clubs WHERE name=${sqlStr(CLUB_NAME)} ORDER BY id DESC LIMIT 1`);
    if (clubId) {
      dbQuery(`DELETE FROM bookclub_meetings WHERE club_id=${Number(clubId)}`);
      dbQuery(`DELETE FROM bookclub_members WHERE club_id=${Number(clubId)}`);
      dbQuery(`DELETE FROM bookclub_books WHERE club_id=${Number(clubId)}`);
      dbQuery(`DELETE FROM bookclub_external_books WHERE club_id=${Number(clubId)}`);
      dbQuery(`DELETE FROM bookclub_clubs WHERE id=${Number(clubId)}`);
    }
    // The acquisition test creates a real libri row — remove it too.
    dbQuery(`DELETE FROM libri WHERE titolo IN (${sqlStr(EXT_TITLE)}, ${sqlStr(EXT_TITLE_2)})`);
    dbQuery(`DELETE FROM autori WHERE nome IN (${sqlStr(EXT_AUTHOR_1)}, ${sqlStr(EXT_AUTHOR_2)})`);
    dbQuery(`DELETE FROM editori WHERE nome=${sqlStr(EXT_PUBLISHER)}`);
    await context.close();
  });

  test('④+③ create a meeting, then edit it, and the Next-meeting card links to the edit form', async () => {
    await page.goto(`${BASE}/book-club/${slug}`);
    await page.waitForLoadState('domcontentloaded');

    // Create a meeting via the "Pianifica un incontro" form.
    const planDetails = page.locator('details:has-text("Pianifica un incontro")');
    await planDetails.locator('summary').click();
    await planDetails.locator('input[name="title"]').fill('Original meeting title');
    await planDetails.locator('input[name="starts_at"]').fill(futureLocal(7, 18));
    await planDetails.locator('button:has-text("Crea incontro")').click();
    await page.waitForLoadState('networkidle');

    // A meeting row now exists with an inline edit form.
    const meetingId = Number(dbQuery(
      `SELECT id FROM bookclub_meetings WHERE title='Original meeting title' ORDER BY id DESC LIMIT 1`
    ) || '0');
    expect(meetingId, 'meeting must have been created').toBeGreaterThan(0);

    // Edit it: open the "Modifica incontro" details on that meeting row and change the title.
    const row = page.locator(`#bc-meeting-${meetingId}`);
    await expect(row).toBeVisible();
    const editDetails = row.locator('details.bc-meeting-edit');
    await editDetails.locator('summary').click();
    await editDetails.locator('input[name="title"]').fill('Edited meeting title');
    await editDetails.locator('input[name="location"]').fill('Main hall');
    await editDetails.locator('button:has-text("Salva modifiche")').click();
    await page.waitForLoadState('networkidle');

    // The edit persisted (this is the whole point of ④).
    const row2 = dbQuery(`SELECT title, location FROM bookclub_meetings WHERE id=${meetingId}`);
    expect(row2).toContain('Edited meeting title');
    expect(row2).toContain('Main hall');

    // ③ The "Next meeting" (Prossimo incontro) card carries an edit link to the row.
    await page.goto(`${BASE}/book-club/${slug}`);
    await page.waitForLoadState('domcontentloaded');
    const nextCard = page.locator('section:has-text("Prossimo incontro")');
    const editLink = nextCard.locator(`a[href*="#bc-meeting-${meetingId}"]`);
    await expect(editLink).toBeVisible();
  });

  test('⑤ edit a member: role and status change from the admin club page', async () => {
    const clubId = Number(dbQuery(`SELECT id FROM bookclub_clubs WHERE name=${sqlStr(CLUB_NAME)} ORDER BY id DESC LIMIT 1`) || '0');
    const memberEmail = 'e2e-bcfixes@example.test';
    const memberUserId = Number(dbQuery(`SELECT id FROM utenti WHERE email=${sqlStr(memberEmail)} LIMIT 1`) || '0');
    expect(memberUserId, 'the member test user must exist').toBeGreaterThan(0);

    await page.goto(`${BASE}/admin/book-club/${clubId}`);
    await page.waitForLoadState('domcontentloaded');

    // Add the member through the admin form.
    await page.locator('form[action*="/members/add"] input[name="email"]').fill(memberEmail);
    await page.locator('form[action*="/members/add"] button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    const memberId = Number(dbQuery(
      `SELECT id FROM bookclub_members WHERE club_id=${clubId} AND user_id=${memberUserId} ORDER BY id DESC LIMIT 1`
    ) || '0');
    expect(memberId, 'member must have been added').toBeGreaterThan(0);

    // Change the role via the inline dropdown (auto-submits on change → full
    // page POST + redirect). Wait for that navigation, then poll the DB.
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
      page.locator('tr', { hasText: memberEmail }).locator('select[name="role"]').selectOption('moderator'),
    ]);
    await expect.poll(() => dbQuery(
      `SELECT r.slug FROM bookclub_members m JOIN bookclub_roles r ON r.id=m.role_id WHERE m.id=${memberId}`
    ), { timeout: 8000 }).toBe('moderator');

    // Change the status via the inline dropdown.
    await page.goto(`${BASE}/admin/book-club/${clubId}`);
    await page.waitForLoadState('domcontentloaded');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
      page.locator('tr', { hasText: memberEmail }).locator('select[name="status"]').selectOption('suspended'),
    ]);
    await expect.poll(() => dbQuery(`SELECT status FROM bookclub_members WHERE id=${memberId}`), { timeout: 8000 }).toBe('suspended');
  });

  test('① propose books NOT in the catalogue, vote them as external options, then acquire one', async () => {
    const clubId = Number(dbQuery(`SELECT id FROM bookclub_clubs WHERE name=${sqlStr(CLUB_NAME)} ORDER BY id DESC LIMIT 1`) || '0');
    expect(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo=${sqlStr(EXT_TITLE)}`)).toBe('0');
    expect(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo=${sqlStr(EXT_TITLE_2)}`)).toBe('0');

    async function proposeExternal(title, author, publisher = '') {
      await page.goto(`${BASE}/book-club/${slug}`);
      await page.waitForLoadState('domcontentloaded');
      const ext = page.locator('details.bc-external-propose');
      await ext.locator('summary').click();
      await ext.locator('input[name="ext_titolo"]').fill(title);
      await ext.locator('input[name="ext_autori"]').fill(author);
      if (publisher !== '') {
        await ext.locator('input[name="ext_editore"]').fill(publisher);
      }
      await ext.locator('button:has-text("Proponi libro esterno")').click();
      await page.waitForLoadState('networkidle');

      const extBookId = Number(dbQuery(
        `SELECT id FROM bookclub_external_books WHERE club_id=${clubId} AND titolo=${sqlStr(title)} ORDER BY id DESC LIMIT 1`
      ) || '0');
      expect(extBookId, `external book row must exist for ${title}`).toBeGreaterThan(0);
      const clubBook = dbQuery(
        `SELECT id, IFNULL(libro_id,'NULL'), external_book_id FROM bookclub_books WHERE club_id=${clubId} AND external_book_id=${extBookId}`
      );
      const clubBookId = Number(clubBook.split('\t')[0]);
      expect(clubBookId).toBeGreaterThan(0);
      expect(clubBook).toContain('NULL'); // libro_id is NULL — it is NOT a catalogue book
      expect(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo=${sqlStr(title)}`),
        'an external proposal must NOT create a libri row').toBe('0');
      return { extBookId, clubBookId };
    }

    // They land in the plugin tables, NOT in libri (the whole point of ①).
    const first = await proposeExternal(EXT_TITLE, `${EXT_AUTHOR_1}; ${EXT_AUTHOR_2}`, EXT_PUBLISHER);
    const second = await proposeExternal(EXT_TITLE_2, 'John External');

    // It renders with the "Proposta esterna" badge.
    await expect(page.locator('.bc-badge', { hasText: 'esterna' }).first()).toBeVisible();

    // External proposals are valid poll options before acquisition: voting is a
    // club decision, not a catalog write. Opening/viewing the poll must not
    // create `libri` rows either.
    const pollTitle = `External poll ${RUN}`;
    const pollDetails = page.locator('details:has-text("Apri una nuova votazione")');
    await pollDetails.locator('summary').click();
    await pollDetails.locator('input[name="title"]').fill(pollTitle);
    await page.locator(`#bc-poll-opt-${first.clubBookId}`).check();
    await page.locator(`#bc-poll-opt-${second.clubBookId}`).check();
    await pollDetails.locator('button:has-text("Apri votazione")').click();
    await page.waitForURL(/\/book-club\/[^/]+\/polls\/\d+/, { timeout: 15000 });
    await expect(page.locator('body')).toContainText(EXT_TITLE);
    await expect(page.locator('body')).toContainText(EXT_TITLE_2);
    await expect(page.locator('.bc-badge', { hasText: 'esterna' }).first()).toBeVisible();
    expect(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo IN (${sqlStr(EXT_TITLE)}, ${sqlStr(EXT_TITLE_2)})`),
      'opening a poll with external books must not create catalog rows').toBe('0');

    // Acquire it into the catalogue (manager action) — the one moment it enters libri.
    await page.goto(`${BASE}/book-club/${slug}`);
    await page.waitForLoadState('domcontentloaded');
    await page.locator(`form[action*="/books/${first.clubBookId}/acquire"] button`).click();
    await page.waitForLoadState('networkidle');

    const libroId = Number(dbQuery(`SELECT id FROM libri WHERE titolo=${sqlStr(EXT_TITLE)} ORDER BY id DESC LIMIT 1`) || '0');
    expect(libroId, 'acquisition must create the catalogue row').toBeGreaterThan(0);
    const after = dbQuery(`SELECT IFNULL(libro_id,'NULL'), IFNULL(external_book_id,'NULL') FROM bookclub_books WHERE id=${first.clubBookId}`);
    expect(after).toContain(String(libroId)); // repointed to the new catalogue row
    expect(after).toContain('NULL');           // external_book_id cleared
    expect(dbQuery(`SELECT acquired_libro_id FROM bookclub_external_books WHERE id=${first.extBookId}`)).toBe(String(libroId));
    const acquiredAuthors = dbQuery(
      `SELECT GROUP_CONCAT(a.nome ORDER BY la.ordine_credito SEPARATOR '|') ` +
      `FROM libri_autori la JOIN autori a ON a.id=la.autore_id WHERE la.libro_id=${libroId}`
    );
    expect(acquiredAuthors).toBe(`${EXT_AUTHOR_1}|${EXT_AUTHOR_2}`);
    expect(dbQuery(
      `SELECT e.nome FROM editori e JOIN libri l ON l.editore_id=e.id WHERE l.id=${libroId}`
    )).toBe(EXT_PUBLISHER);
    expect(dbQuery(
      `SELECT e.nome FROM libri_editori le JOIN editori e ON e.id=le.editore_id WHERE le.libro_id=${libroId} AND le.ordine=0`
    )).toBe(EXT_PUBLISHER);
    const searchIndex = dbQuery(`SELECT COALESCE(search_index,'') FROM libri WHERE id=${libroId}`);
    expect(searchIndex).toContain(EXT_TITLE);
    expect(searchIndex).toContain(EXT_AUTHOR_1);
    expect(searchIndex).toContain(EXT_PUBLISHER);
    expect(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo=${sqlStr(EXT_TITLE_2)}`),
      'the other external poll option is still not a catalog book').toBe('0');
  });
});
