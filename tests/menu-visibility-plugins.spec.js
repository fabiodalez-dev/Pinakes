// @ts-check
/**
 * Emeroteca and Archive: the admin can take their entry out of the public
 * menu, like the Events page. Switched off from the plugin's admin page, the
 * entry leaves the desktop menu, the mobile menu and the account pages' menu;
 * the section's pages stay reachable (catalogue and search link to them).
 * Switched back on, the entry returns.
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';

const SECTIONS = [
  { name: 'Emeroteca', admin: '/admin/periodicals', page: '/emeroteca', href: /\/emeroteca$/ },
  { name: 'Archivio', admin: '/admin/archives', page: '/archivio', href: /\/(archivio|archive)$/ },
];

async function login(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.click('button[type="submit"]');
  await page.waitForURL(url => url.pathname.startsWith('/admin'), { timeout: 30000 });
}

async function setInMenu(page, adminPath, on) {
  await page.goto(BASE + adminPath);
  const box = page.locator('#menuVisibilityForm input[name="in_menu"]');
  if ((await box.isChecked()) !== on) {
    await Promise.all([page.waitForURL(url => url.pathname === adminPath), box.dispatchEvent('click')]);
  }
  await expect(page.locator('#menuVisibilityForm input[name="in_menu"]')).toBeChecked({ checked: on });
}

/** Links to the section in the public header (desktop and mobile menus). */
async function menuLinks(page, path, href) {
  await page.goto(BASE + path, { waitUntil: 'domcontentloaded' });
  return page.locator('header a, .mobile-menu a, .mobile-nav a, nav a').evaluateAll(
    (links, source) => links.filter(a => new RegExp(source).test(new URL(a.href).pathname)).length,
    href.source,
  );
}

test.describe('Plugin sections in the public menu', () => {
  test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'admin credentials not set');

  for (const section of SECTIONS) {
    test(`${section.name}: the admin switch hides and restores the menu entry`, async ({ browser }) => {
      const admin = await browser.newPage();
      await login(admin);
      await admin.goto(BASE + section.admin);
      test.skip(await admin.locator('#menuVisibilityForm').count() === 0, `${section.name} plugin not active`);

      // Remember the admin's own setting so the test leaves it as it found it.
      const initiallyInMenu = await admin.locator('#menuVisibilityForm input[name="in_menu"]').isChecked();

      const visitor = await browser.newPage();
      try {
        // Where the section is listed at all (an archive with no published
        // unit has no entry), the switch must take it out and put it back.
        await setInMenu(admin, section.admin, true);
        const listed = await menuLinks(visitor, '/catalogo', section.href);
        test.skip(listed === 0, `${section.name} has nothing to list`);
        const accountListed = await menuLinks(admin, '/utente/bacheca', section.href);

        await setInMenu(admin, section.admin, false);
        expect(await menuLinks(visitor, '/catalogo', section.href), 'public menus').toBe(0);
        expect(await menuLinks(visitor, '/', section.href), 'home menus').toBe(0);
        expect(await menuLinks(admin, '/utente/bacheca', section.href), 'account menus').toBe(0);
        // Only the menu entry goes: the section itself is still served.
        const res = await visitor.goto(BASE + section.page);
        expect(res && res.status()).toBe(200);

        await setInMenu(admin, section.admin, true);
        expect(await menuLinks(visitor, '/catalogo', section.href)).toBe(listed);
        expect(await menuLinks(admin, '/utente/bacheca', section.href)).toBe(accountListed);
      } finally {
        await setInMenu(admin, section.admin, initiallyInMenu);
      }
    });
  }

  test('Staff do not get the switch: the public menu is an admin setting', async ({ browser }) => {
    test.skip(!process.env.E2E_DB_USER || !process.env.E2E_DB_NAME, 'database credentials not set');
    const db = (sql) => {
      const args = ['--default-character-set=utf8mb4', '-N', '-B', '-e', sql];
      if (process.env.E2E_DB_HOST) args.push('-h', process.env.E2E_DB_HOST, ...(process.env.E2E_DB_PORT ? ['-P', process.env.E2E_DB_PORT] : []));
      else if (process.env.E2E_DB_SOCKET) args.push('-S', process.env.E2E_DB_SOCKET);
      args.push('-u', process.env.E2E_DB_USER, process.env.E2E_DB_NAME);
      return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 15000, env: { ...process.env, MYSQL_PWD: process.env.E2E_DB_PASS || '' } }).trim();
    };
    const stamp = Date.now();
    const email = `menu-staff-${stamp}@example.invalid`;
    const password = `Menu-staff-${stamp}`;
    const hash = execFileSync('php', ['-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', password], { encoding: 'utf-8' }).trim();
    db(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata) VALUES ('MS${String(stamp).slice(-8)}', 'Menu', 'Staff', '${email}', '${hash}', 'staff', 'attivo', 1)`);
    const page = await (await browser.newContext()).newPage();
    try {
      await page.goto(`${BASE}/accedi`);
      await page.fill('input[name="email"]', email);
      await page.fill('input[name="password"]', password);
      await page.click('button[type="submit"]');
      await page.waitForURL(url => !url.pathname.includes('accedi'), { timeout: 30000 });
      for (const section of SECTIONS) {
        const res = await page.goto(BASE + section.admin);
        expect(res?.status(), `${section.admin} opens for staff`).toBe(200);
        await expect(page.locator('#menuVisibilityForm'), `${section.name}: no switch for staff`).toHaveCount(0);
      }
    } finally {
      await page.context().close();
      db(`DELETE FROM utenti WHERE email = '${email}'`);
    }
  });
});
