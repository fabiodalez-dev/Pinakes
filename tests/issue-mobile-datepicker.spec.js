// @ts-check
// Regression: on the public book page, the loan/reservation date picker must use
// Flatpickr's OWN calendar even on mobile (disableMobile:true). Without it,
// flatpickr falls back to the native Android/iOS date picker, which ignores the
// `disable` option — so fully-booked days look selectable and availability is
// never shown. Runs under Pixel 5 (Android Chrome) emulation (mobile user-agent + touch).
const { test, expect, devices } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';

const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'E2E credentials not configured (set E2E_ADMIN_EMAIL, E2E_ADMIN_PASS, E2E_DB_USER, E2E_DB_NAME)',
);

function dbQuery(sql) {
  const args = [];
  if (DB_HOST) {
    args.push('-h', DB_HOST);
    if (DB_PORT) args.push('-P', DB_PORT);
  } else if (DB_SOCKET) {
    args.push('-S', DB_SOCKET);
  }
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, {
    encoding: 'utf-8', timeout: 10000,
    env: { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}

// Emulate a real phone (mobile UA + touch) — this is what makes flatpickr
// consider falling back to the native picker.
test.use({ ...devices['Pixel 5'] });

test.describe('Mobile: book-page loan date picker', () => {
  let bookId = 0;

  test.beforeAll(() => {
    // A book with at least one available copy → the request-loan button shows.
    bookId = parseInt(
      dbQuery(
        "SELECT c.libro_id FROM copie c JOIN libri l ON l.id=c.libro_id " +
        "WHERE c.stato='disponibile' AND l.deleted_at IS NULL LIMIT 1",
      ) || '0',
      10,
    );
  });

  test('uses flatpickr (not the native picker), so availability can be shown', async ({ page }) => {
    test.skip(!bookId, 'No available book to open the loan date picker on');

    // Login (the request-loan dialog is for authenticated users)
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/(\/$|admin|profilo|account)/, { timeout: 15000 }).catch(() => {});

    await page.goto(`${BASE}/libro/${bookId}`);
    const btn = page.locator('#btn-request-loan');
    test.skip((await btn.count()) === 0, 'This book has no request-loan button');

    await btn.click();
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await page.waitForFunction(
      () => {
        const el = document.querySelector('#swal-date-start');
        return el && /** @type {any} */ (el)._flatpickr;
      },
      { timeout: 8000 },
    );

    const info = await page.evaluate(() => {
      const el = /** @type {any} */ (document.querySelector('#swal-date-start'));
      const fp = el && el._flatpickr;
      return {
        hasFlatpickr: !!fp,
        disableMobile: fp ? !!fp.config.disableMobile : null,
        // flatpickr sets isMobile=true only when it actually renders the native
        // input; with disableMobile:true it must stay false even on a phone.
        usesNativePicker: fp ? !!fp.isMobile : null,
        nativeDateInput: !!document.querySelector('.swal2-popup input[type="date"]'),
        customCalendar: !!document.querySelector('.flatpickr-calendar'),
      };
    });

    expect(info.hasFlatpickr).toBe(true);
    expect(info.disableMobile).toBe(true);
    expect(info.usesNativePicker).toBe(false);   // custom calendar, not the OS one
    expect(info.nativeDateInput).toBe(false);     // flatpickr did not inject a native <input type=date>
    expect(info.customCalendar).toBe(true);       // flatpickr's own calendar is in the DOM
  });
});
