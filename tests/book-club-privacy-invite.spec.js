// @ts-check
// Book Club privacy and invitations, as a visitor and a member see them:
//  - a PRIVATE club shows its card to anyone, but its activity (reading list,
//    discussions, polls, meetings) only to active members, managers and the
//    library's admins;
//  - following an invitation link only shows a confirm page: the visitor
//    becomes a member when they press "Accetta l'invito" (a CSRF-protected
//    POST), never on the GET a mail scanner or a stray click performs.
//
// Run: /tmp/run-e2e.sh tests/book-club-privacy-invite.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const crypto = require('crypto');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME, 'E2E admin and database credentials required');

function db(sql) {
  const args = ['--default-character-set=utf8mb4', '-N', '-B', '-e', sql];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_PORT) args.push('-P', DB_PORT);
  if (!DB_HOST && DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 15000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}
const q = (v) => "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";

const RUN = Date.now().toString(36);
const PRIVATE_SLUG = `e2e-private-${RUN}`;
const INVITE_SLUG = `e2e-invite-${RUN}`;
const TOKEN = crypto.randomBytes(32).toString('hex');

async function login(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForFunction(() => !location.pathname.includes('accedi') && !location.pathname.includes('login'), null, { timeout: 15000 });
}

test.describe.serial('Book Club: private clubs and invitation links', () => {
  let adminId = 0;

  test.beforeAll(() => {
    const active = db("SELECT is_active FROM plugins WHERE name = 'book-club' LIMIT 1");
    test.skip(active !== '1', 'book-club plugin not active');
    adminId = parseInt(db(`SELECT id FROM utenti WHERE email = ${q(ADMIN_EMAIL)} LIMIT 1`), 10);
    const hex32 = () => crypto.randomBytes(16).toString('hex');
    db(`INSERT INTO bookclub_clubs (slug, name, description, privacy, ics_token, created_by, is_active)
        VALUES (${q(PRIVATE_SLUG)}, ${q('E2E Private ' + RUN)}, ${q('Club privato di prova')}, 'private', ${q(hex32())}, NULL, 1),
               (${q(INVITE_SLUG)}, ${q('E2E Invite ' + RUN)}, ${q('Club su invito di prova')}, 'invite', ${q(hex32())}, NULL, 1)`);
    const inviteClub = db(`SELECT id FROM bookclub_clubs WHERE slug = ${q(INVITE_SLUG)}`);
    db(`INSERT INTO bookclub_invitations (club_id, email, token, role_id, invited_by, expires_at)
        VALUES (${inviteClub}, ${q(ADMIN_EMAIL)}, ${q(TOKEN)}, NULL, NULL, DATE_ADD(NOW(), INTERVAL 1 DAY))`);
  });

  test.afterAll(() => {
    try { db(`DELETE FROM bookclub_clubs WHERE slug IN (${q(PRIVATE_SLUG)}, ${q(INVITE_SLUG)})`); } catch { /* best effort */ }
  });

  test('a visitor sees a private club\'s card, not its activity', async ({ page }) => {
    const res = await page.goto(`${BASE}/book-club/${PRIVATE_SLUG}`);
    expect(res && res.status()).toBe(200);
    await expect(page.getByRole('heading', { name: `E2E Private ${RUN}` }).first()).toBeVisible();
    await expect(page.getByText('Club privato', { exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'I libri del club' })).toHaveCount(0);

    const discussions = await page.request.get(`${BASE}/book-club/${PRIVATE_SLUG}/discussions`, { maxRedirects: 0 });
    expect(discussions.status(), 'the discussions of a private club are members-only').toBe(404);
  });

  test('the library admin still sees the private club\'s activity', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/book-club/${PRIVATE_SLUG}`);
    await expect(page.getByRole('heading', { name: 'I libri del club' })).toBeVisible();
    await expect(page.getByText('Club privato', { exact: true })).toHaveCount(0);
  });

  test('opening an invitation link asks for confirmation and joins only on the POST', async ({ page }) => {
    await login(page);
    const inviteClub = db(`SELECT id FROM bookclub_clubs WHERE slug = ${q(INVITE_SLUG)}`);
    const membership = () => db(`SELECT status FROM bookclub_members WHERE club_id = ${inviteClub} AND user_id = ${adminId}`);

    await page.goto(`${BASE}/book-club/invite/${TOKEN}`);
    await expect(page.getByRole('button', { name: "Accetta l'invito" })).toBeVisible();
    expect(membership(), 'the GET alone must not join the club').toBe('');
    expect(db(`SELECT accepted_at IS NULL FROM bookclub_invitations WHERE token = ${q(TOKEN)}`)).toBe('1');

    await Promise.all([
      // Any localized club base (/club-di-lettura, /book-club, …) is fine.
      page.waitForURL((url) => /\/(?:book-club|club-di-lettura|lesekreis|club-de-lecture|laeseklub)\/[^/]+$/.test(url.pathname) && url.pathname.endsWith(`/${INVITE_SLUG}`), { timeout: 15000 }),
      page.getByRole('button', { name: "Accetta l'invito" }).click(),
    ]);
    expect(membership(), 'pressing the button makes the user an active member').toBe('active');
    expect(db(`SELECT accepted_at IS NOT NULL FROM bookclub_invitations WHERE token = ${q(TOKEN)}`)).toBe('1');
  });
});
