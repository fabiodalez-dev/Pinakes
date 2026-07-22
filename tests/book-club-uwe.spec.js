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
const AUTO_ISBN = `978${String(Date.now()).slice(-10)}`;
const MEMBER_EMAIL = `e2e-bc-uwe-${RUN}@example.test`;

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

    // This spec must be hermetic on a fresh CI database. The previous version
    // assumed another, unrelated mobile-API spec had already created a fixed
    // user, so the gating workflow failed before exercising member editing or
    // external-book acquisition.
    dbQuery(`INSERT INTO utenti
      (codice_tessera, nome, cognome, email, password, stato, email_verificata, tipo_utente, privacy_accettata, created_at, updated_at)
      VALUES (${sqlStr(`BCUWE${RUN}`)}, 'E2E', 'Book Club Member', ${sqlStr(MEMBER_EMAIL)}, 'not-used', 'attivo', 1, 'standard', 1, NOW(), NOW())`);
    expect(Number(dbQuery(`SELECT id FROM utenti WHERE email=${sqlStr(MEMBER_EMAIL)} LIMIT 1`) || '0'),
      'member test user must be created by this spec').toBeGreaterThan(0);
  });

  test.afterAll(async () => {
    // FK-safe cleanup of everything this spec created.
    const clubId = dbQuery(`SELECT id FROM bookclub_clubs WHERE name=${sqlStr(CLUB_NAME)} ORDER BY id DESC LIMIT 1`);
    if (clubId) {
      dbQuery(`DELETE FROM bookclub_meetings WHERE club_id=${Number(clubId)}`);
      dbQuery(`DELETE FROM bookclub_members WHERE club_id=${Number(clubId)}`);
      dbQuery(`DELETE FROM bookclub_books WHERE club_id=${Number(clubId)}`);
      dbQuery(`DELETE FROM bookclub_external_books WHERE club_id=${Number(clubId)}`);
      dbQuery(`DELETE FROM admin_notifications WHERE related_id=${Number(clubId)} AND (title LIKE 'Nuova proposta%' OR title LIKE 'Richiesta di adesione%' OR title LIKE 'Nuovo incontro%')`);
      dbQuery(`DELETE FROM bookclub_clubs WHERE id=${Number(clubId)}`);
    }
    // The acquisition test creates a real libri row + its physical copy — remove both.
    dbQuery(`DELETE FROM copie WHERE libro_id IN (SELECT id FROM libri WHERE titolo IN (${sqlStr(EXT_TITLE)}, ${sqlStr(EXT_TITLE_2)}))`);
    dbQuery(`DELETE FROM libri WHERE titolo IN (${sqlStr(EXT_TITLE)}, ${sqlStr(EXT_TITLE_2)})`);
    dbQuery(`DELETE FROM autori WHERE nome IN (${sqlStr(EXT_AUTHOR_1)}, ${sqlStr(EXT_AUTHOR_2)})`);
    dbQuery(`DELETE FROM editori WHERE nome=${sqlStr(EXT_PUBLISHER)}`);
    dbQuery(`DELETE FROM utenti WHERE email=${sqlStr(MEMBER_EMAIL)}`);
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
    const memberEmail = MEMBER_EMAIL;
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

    async function proposeExternal(title, author, publisher = '', isbn = '') {
      await page.goto(`${BASE}/book-club/${slug}`);
      await page.waitForLoadState('domcontentloaded');
      const ext = page.locator('details.bc-external-propose');
      await ext.locator('summary').click();
      await ext.locator('input[name="ext_titolo"]').fill(title);
      await ext.locator('input[name="ext_autori"]').fill(author);
      if (publisher !== '') {
        await ext.locator('input[name="ext_editore"]').fill(publisher);
      }
      if (isbn !== '') {
        await ext.locator('input[name="ext_isbn"]').fill(isbn);
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
    const second = await proposeExternal(EXT_TITLE_2, 'John External', '', AUTO_ISBN);

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

    // Reproduce Uwe's failure exactly: the manager later records the purchase
    // through the normal catalogue side, then revisits the club. The GET must
    // link the proposal by exact ISBN without requiring the Acquire button and
    // without duplicating the catalogue row.
    dbQuery(`INSERT INTO libri (titolo, isbn13) VALUES (${sqlStr(EXT_TITLE_2)}, ${sqlStr(AUTO_ISBN)})`);
    const manuallyAddedLibroId = Number(dbQuery(`SELECT id FROM libri WHERE isbn13=${sqlStr(AUTO_ISBN)} LIMIT 1`) || '0');
    expect(manuallyAddedLibroId).toBeGreaterThan(0);
    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${manuallyAddedLibroId}, ${sqlStr(`LIB-${manuallyAddedLibroId}`)}, 'disponibile')`);
    await page.goto(`${BASE}/book-club/${slug}`);
    await page.waitForLoadState('networkidle');
    await expect.poll(() => dbQuery(
      `SELECT CONCAT(IFNULL(libro_id,'NULL'),'|',IFNULL(external_book_id,'NULL')) FROM bookclub_books WHERE id=${second.clubBookId}`
    )).toBe(`${manuallyAddedLibroId}|NULL`);
    expect(dbQuery(`SELECT acquired_libro_id FROM bookclub_external_books WHERE id=${second.extBookId}`)).toBe(String(manuallyAddedLibroId));
    expect(dbQuery(`SELECT COUNT(*) FROM libri WHERE isbn13=${sqlStr(AUTO_ISBN)}`),
      'automatic reconciliation must not duplicate the manually added catalogue row').toBe('1');

    // Acquire it into the catalogue (manager action) — the one moment it enters libri.
    await page.locator(`form[action*="/books/${first.clubBookId}/acquire"] button`).click();
    await page.waitForLoadState('networkidle');

    const libroId = Number(dbQuery(`SELECT id FROM libri WHERE titolo=${sqlStr(EXT_TITLE)} ORDER BY id DESC LIMIT 1`) || '0');
    expect(libroId, 'acquisition must create the catalogue row').toBeGreaterThan(0);
    const after = dbQuery(`SELECT IFNULL(libro_id,'NULL'), IFNULL(external_book_id,'NULL') FROM bookclub_books WHERE id=${first.clubBookId}`);
    expect(after).toContain(String(libroId)); // repointed to the new catalogue row
    expect(after).toContain('NULL');           // external_book_id cleared
    expect(dbQuery(`SELECT acquired_libro_id FROM bookclub_external_books WHERE id=${first.extBookId}`)).toBe(String(libroId));
    // The acquired book must have a real physical copy: libri.copie_totali
    // defaults to 1, so without a matching `copie` row it would claim an
    // available copy it cannot lend.
    expect(dbQuery(`SELECT COUNT(*) FROM copie WHERE libro_id=${libroId}`),
      'acquired book must have exactly one physical copy').toBe('1');
    expect(dbQuery(`SELECT stato FROM copie WHERE libro_id=${libroId}`)).toBe('disponibile');
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
      'the automatically reconciled poll option has exactly one catalogue row').toBe('1');

    // The main club page must also lazily close expired polls (cron is not a
    // correctness dependency) and expose them in its explicit history.
    const pollId = Number(dbQuery(`SELECT id FROM bookclub_polls WHERE club_id=${clubId} AND title=${sqlStr(pollTitle)} LIMIT 1`) || '0');
    expect(pollId).toBeGreaterThan(0);
    dbQuery(`UPDATE bookclub_polls SET closes_at=DATE_SUB(NOW(), INTERVAL 1 MINUTE), status='open' WHERE id=${pollId}`);
    await page.goto(`${BASE}/book-club/${slug}`);
    await page.waitForLoadState('networkidle');
    expect(dbQuery(`SELECT status FROM bookclub_polls WHERE id=${pollId}`)).toBe('closed');
    const closedHistory = page.locator('details:has-text("Votazioni chiuse")');
    await closedHistory.locator('summary').click();
    await expect(closedHistory.locator(`a[href$="/polls/${pollId}"]`)).toBeVisible();
  });

  test('⑥ Uwe #138 follow-up: heading, proposed-by, remove, PDF, and next-meeting card fields', async () => {
    const clubId = Number(dbQuery(`SELECT id FROM bookclub_clubs WHERE name=${sqlStr(CLUB_NAME)} ORDER BY id DESC LIMIT 1`) || '0');
    const memberUserId = Number(dbQuery(`SELECT id FROM utenti WHERE email=${sqlStr(MEMBER_EMAIL)} LIMIT 1`) || '0');
    expect(clubId).toBeGreaterThan(0);
    expect(memberUserId).toBeGreaterThan(0);
    // The member must be ACTIVE to be a valid "proposed_by" target (⑤ left it suspended).
    dbQuery(`UPDATE bookclub_members SET status='active' WHERE club_id=${clubId} AND user_id=${memberUserId}`);
    await page.goto(`${BASE}/book-club/${slug}`);
    await page.waitForLoadState('domcontentloaded');

    // item 2 — clearer, action-oriented heading on the external-propose section.
    const ext = page.locator('details.bc-external-propose');
    await expect(ext.locator('summary')).toContainText('Proponi un libro non ancora in catalogo');

    // item 3 — manager-only "Proposto da" dropdown attributes the proposal to a member.
    await ext.locator('summary').click();
    const proposedBy = ext.locator('select[name="proposed_by"]');
    await expect(proposedBy).toBeVisible();
    await ext.locator('input[name="ext_titolo"]').fill('Uwe FollowUp Book');
    await proposedBy.selectOption(String(memberUserId));
    await ext.locator('button:has-text("Proponi libro esterno")').click();
    await page.waitForLoadState('networkidle');
    const cbId = Number(dbQuery(
      `SELECT id FROM bookclub_books WHERE club_id=${clubId} AND external_book_id IN ` +
      `(SELECT id FROM bookclub_external_books WHERE titolo='Uwe FollowUp Book') ORDER BY id DESC LIMIT 1`
    ) || '0');
    expect(cbId, 'external proposal must exist').toBeGreaterThan(0);
    expect(dbQuery(`SELECT proposed_by FROM bookclub_books WHERE id=${cbId}`),
      'proposed_by must be the chosen member, not the manager').toBe(String(memberUserId));

    // A new proposal must reach the admins via the Pinakes notification bell.
    expect(Number(dbQuery(
      `SELECT COUNT(*) FROM admin_notifications WHERE related_id=${clubId} AND type='general' AND title LIKE 'Nuova proposta%'`
    ) || '0'), 'a bell notification must be created for the new proposal').toBeGreaterThan(0);
    expect(dbQuery(
      `SELECT message FROM admin_notifications WHERE related_id=${clubId} AND type='general' AND title LIKE 'Nuova proposta%' ORDER BY id DESC LIMIT 1`
    ), 'the notification names the selected proposer, not the acting manager').toContain('E2E Book Club Member');

    // item 5 — PDF export of the club's reading list.
    const pdf = await page.request.get(`${BASE}/book-club/${slug}/books.pdf`);
    expect(pdf.status()).toBe(200);
    expect(pdf.headers()['content-type']).toContain('application/pdf');
    expect((await pdf.body()).length).toBeGreaterThan(500);

    // item 4 — remove the book from the club (and clean up its orphan external row).
    await page.goto(`${BASE}/book-club/${slug}`);
    await page.waitForLoadState('domcontentloaded');
    const removeForm = page.locator(`form[action$="/books/${cbId}/remove"]`);
    await expect(removeForm).toBeVisible();
    await removeForm.locator('button[type="submit"]').click();
    const removePopup = page.locator('.swal2-popup');
    await expect(removePopup).toBeVisible();
    await expect(removePopup).toContainText('Rimuovere questo libro');
    await removePopup.locator('.swal2-cancel').click();
    expect(dbQuery(`SELECT COUNT(*) FROM bookclub_books WHERE id=${cbId}`),
      'cancelling the SweetAlert must preserve the book').toBe('1');

    await removeForm.locator('button[type="submit"]').click();
    await expect(removePopup).toBeVisible();
    await removePopup.locator('.swal2-confirm').click();
    await page.waitForLoadState('networkidle');
    expect(dbQuery(`SELECT COUNT(*) FROM bookclub_books WHERE id=${cbId}`), 'book must be removed').toBe('0');
    expect(dbQuery(`SELECT COUNT(*) FROM bookclub_external_books WHERE titolo='Uwe FollowUp Book'`),
      'orphan external metadata must be cleaned').toBe('0');

    // item 1 — the Next-meeting card shows agenda + an online link, not just title/time.
    await page.goto(`${BASE}/book-club/${slug}`);
    await page.waitForLoadState('domcontentloaded');
    const plan = page.locator('details:has-text("Pianifica un incontro")');
    await plan.locator('summary').click();
    await plan.locator('input[name="title"]').fill('Sooner online meeting');
    await plan.locator('input[name="starts_at"]').fill(futureLocal(3, 18));
    await plan.locator('select[name="kind"]').selectOption('online').catch(() => {});
    await plan.locator('input[name="video_url"]').fill('https://meet.example.com/room');
    await plan.locator('textarea[name="agenda"]').fill('Agenda: discuss chapter 1');
    await plan.locator('button:has-text("Crea incontro")').click();
    await page.waitForLoadState('networkidle');

    await page.goto(`${BASE}/book-club/${slug}`);
    await page.waitForLoadState('domcontentloaded');
    const card = page.locator('section:has-text("Prossimo incontro")');
    await expect(card).toContainText('Agenda: discuss chapter 1');
    await expect(card.locator('a:has-text("Partecipa online")')).toBeVisible();
  });
});
