// @ts-check
/**
 * Issue #255 — configurable registration fields. 29-check E2E suite.
 *
 * Coverage (all through the real browser):
 *   1-4    defaults + SERVER-side enforcement of each built-in requirement
 *          (client validation bypassed with novalidate — the HTML attribute
 *          alone is not a security boundary).
 *   5-7    the dedicated Settings → Registrazione tab: toggles persist, all
 *          six custom field types can be created, and each renders on the
 *          public form with the right control type.
 *   8-15   the full 2^3 toggle matrix: every cognome/telefono/indirizzo
 *          combination reflects exactly in the form's required attributes.
 *   16-17  registrations: full data with every custom type stored; minimal
 *          data with '' surname and NULL phone/address.
 *   18-22  server-side rejection of invalid custom values (email, url
 *          javascript:, non-numeric, missing required, over-long).
 *   23-24  sanitization: a tag-carrying label is neutralised on save; a
 *          script-carrying VALUE is escaped when rendered back (admin detail).
 *   25     an inactive (attivo=0) field disappears from the public form.
 *   26-29 profile/admin-detail regressions and destructive type-change guard.
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'E2E credentials not configured (set E2E_ADMIN_EMAIL, E2E_ADMIN_PASS, E2E_DB_*)');

function dbQuery(sql) {
  // Prefer TCP host/port (CI's MySQL service listens on 127.0.0.1, not the
  // default socket); fall back to the socket (local dev). Mirrors the working
  // connection logic in schema-integrity.spec.js.
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

const TOKEN = Math.random().toString(16).slice(2, 8);
const LBL = (name) => `ZZ255 ${name} ${TOKEN}`;
const TYPES = ['text', 'textarea', 'email', 'url', 'number', 'checkbox'];

/** Ensure the three toggle rows exist, then set them to the given combo. */
function setToggles(cognome, telefono, indirizzo) {
  const combo = { require_cognome: cognome, require_telefono: telefono, require_indirizzo: indirizzo };
  for (const [key, on] of Object.entries(combo)) {
    const val = on ? '1' : '0';
    dbQuery(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('registration', '${key}', '${val}') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
  }
}

function fieldId(label) {
  return dbQuery(`SELECT id FROM registrazione_campi WHERE etichetta='${label.replace(/'/g, "''")}'`);
}

/** Fill the public registration form; missing keys stay empty. */
async function fillRegistration(page, data) {
  await page.goto(`${BASE}/registrati`);
  for (const [name, value] of Object.entries(data.fields || {})) {
    await page.fill(`[name="${name}"]`, value);
  }
  for (const [id, value] of Object.entries(data.custom || {})) {
    const loc = page.locator(`[name="custom_field[${id}]"]`);
    if (value === true) await loc.check();
    else await loc.fill(String(value));
  }
  await page.check('input[name="privacy_acceptance"]');
}

/** Submit bypassing client-side validation (server contract under test). */
async function submitNoValidate(page) {
  await page.evaluate(() => {
    const form = document.querySelector('form:has(input[name="nome"])');
    if (form instanceof HTMLFormElement) form.noValidate = true;
  });
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.click('form:has(input[name="nome"]) button[type="submit"]'),
  ]);
}

function userCount(email) {
  return Number(dbQuery(`SELECT COUNT(*) FROM utenti WHERE email='${email}'`));
}

test.describe.serial('Issue #255 — configurable registration fields (29 checks)', () => {
  /** @type {import('@playwright/test').Page} */
  let admin;

  test.beforeAll(async ({ browser }) => {
    const ctx = await browser.newContext();
    admin = await ctx.newPage();
    await admin.goto(`${BASE}/login`);
    await admin.fill('input[name="email"]', ADMIN_EMAIL);
    await admin.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
      admin.waitForURL(/\/admin\//, { timeout: 15000 }),
      admin.click('button[type="submit"]'),
    ]);
    // Tests 1-4 must exercise the application's ACTUAL defaults (no toggle
    // rows in system_settings → code-side default = required), not a value
    // this suite planted. Deleting also makes the run deterministic on a
    // DB dirtied by earlier manual testing.
    dbQuery("DELETE FROM system_settings WHERE category='registration' AND setting_key IN ('require_cognome','require_telefono','require_indirizzo')");
  });

  test.afterAll(async () => {
    // Restore the pristine default state (absent rows = code default).
    dbQuery("DELETE FROM system_settings WHERE category='registration' AND setting_key IN ('require_cognome','require_telefono','require_indirizzo')");
    // FK-safe order (children first, then parents) — the cascades exist and
    // are asserted by the migration test, but teardown must not depend on
    // the very behaviour the suite verifies.
    dbQuery(`DELETE v FROM utenti_campi_valori v JOIN registrazione_campi c ON c.id = v.campo_id WHERE c.etichetta LIKE '%${TOKEN}%'`);
    dbQuery(`DELETE v FROM utenti_campi_valori v JOIN utenti u ON u.id = v.utente_id WHERE u.email LIKE 'zz-255-%${TOKEN}@example.test'`);
    dbQuery(`DELETE FROM registrazione_campi WHERE etichetta LIKE 'ZZ255 %${TOKEN}%' OR etichetta LIKE '%${TOKEN}%'`);
    dbQuery(`DELETE FROM utenti WHERE email LIKE 'zz-255-%${TOKEN}@example.test'`);
    await admin?.context()?.close();
  });

  // ── 1-4: defaults + server-side enforcement ────────────────────────────────

  test('1. defaults: surname/phone/address carry required on the public form', async ({ page }) => {
    await page.goto(`${BASE}/registrati`);
    for (const f of ['cognome', 'telefono', 'indirizzo']) {
      await expect(page.locator(`[name="${f}"]`)).toHaveAttribute('required', /.*/);
    }
  });

  const serverEnforced = [
    ['2. server rejects a missing surname when required', 'cognome'],
    ['3. server rejects a missing phone when required', 'telefono'],
    ['4. server rejects a missing address when required', 'indirizzo'],
  ];
  for (const [title, omitted] of serverEnforced) {
    test(String(title), async ({ page }) => {
      const email = `zz-255-srv-${omitted}-${TOKEN}@example.test`;
      const fields = {
        nome: 'Srv255', cognome: 'Test', telefono: '123456', indirizzo: 'Via Test 1',
        email, password: 'Password255!ok', password_confirm: 'Password255!ok',
      };
      delete fields[omitted];
      await fillRegistration(page, { fields });
      await submitNoValidate(page);
      expect(page.url()).toContain('error=');
      expect(userCount(email)).toBe(0);
    });
  }

  // ── 5-7: dedicated tab, all six types, correct controls ───────────────────

  test('5. Registrazione tab: toggles persist across save + reload', async () => {
    await admin.goto(`${BASE}/admin/settings?tab=registration`);
    for (const t of ['require_cognome', 'require_telefono', 'require_indirizzo']) {
      await admin.locator(`input[name="${t}"]`).uncheck();
    }
    await Promise.all([
      admin.waitForLoadState('domcontentloaded'),
      admin.click('form[action*="/admin/settings/registration"] button[type="submit"]'),
    ]);
    await admin.goto(`${BASE}/admin/settings?tab=registration`);
    for (const t of ['require_cognome', 'require_telefono', 'require_indirizzo']) {
      await expect(admin.locator(`input[name="${t}"]`)).not.toBeChecked();
    }
  });

  test('6. all six custom field types can be created from the tab', async () => {
    for (const type of TYPES) {
      await admin.goto(`${BASE}/admin/settings?tab=registration`);
      await admin.fill('input[name="new_custom_field_label"]', LBL(type));
      await admin.selectOption('select[name="new_custom_field_type"]', type);
      await Promise.all([
        admin.waitForLoadState('domcontentloaded'),
        admin.click('form[action*="/admin/settings/registration"] button[type="submit"]'),
      ]);
    }
    await admin.goto(`${BASE}/admin/settings?tab=registration`);
    for (const type of TYPES) {
      await expect(admin.locator(`input[value="${LBL(type)}"]`)).toBeVisible();
      expect(Number(fieldId(LBL(type)))).toBeGreaterThan(0);
    }
  });

  test('7. each type renders the right control on the public form', async ({ page }) => {
    await page.goto(`${BASE}/registrati`);
    for (const type of TYPES) {
      const id = fieldId(LBL(type));
      const control = page.locator(`[name="custom_field[${id}]"]`);
      await expect(control).toBeVisible();
      const tag = await control.evaluate((el) => el.tagName.toLowerCase());
      if (type === 'textarea') {
        expect(tag).toBe('textarea');
      } else {
        expect(tag).toBe('input');
        const expectedType = type === 'checkbox' ? 'checkbox' : (type === 'text' ? 'text' : type);
        await expect(control).toHaveAttribute('type', expectedType);
      }
    }
  });

  // ── 8-15: the full 2^3 toggle matrix ───────────────────────────────────────

  const matrix = [];
  for (const c of [true, false]) for (const t of [true, false]) for (const i of [true, false]) matrix.push([c, t, i]);
  matrix.forEach(([c, t, i], idx) => {
    test(`${8 + idx}. toggle combo cognome=${c ? 1 : 0} telefono=${t ? 1 : 0} indirizzo=${i ? 1 : 0} maps to required attrs`, async ({ page }) => {
      setToggles(c, t, i);
      await page.goto(`${BASE}/registrati`);
      const expectations = { cognome: c, telefono: t, indirizzo: i };
      for (const [field, required] of Object.entries(expectations)) {
        const loc = page.locator(`[name="${field}"]`);
        if (required) await expect(loc).toHaveAttribute('required', /.*/);
        else await expect(loc).not.toHaveAttribute('required', /.*/);
      }
    });
  });

  // ── 16-17: registrations storing every type / minimal data ────────────────

  test('16. full registration stores a value for every custom type', async ({ page }) => {
    setToggles(true, true, true);
    const email = `zz-255-full-${TOKEN}@example.test`;
    const custom = {};
    custom[fieldId(LBL('text'))] = '@telegram_handle';
    custom[fieldId(LBL('textarea'))] = 'riga1 riga2';
    custom[fieldId(LBL('email'))] = 'alt@example.test';
    custom[fieldId(LBL('url'))] = 'https://example.test/profilo';
    custom[fieldId(LBL('number'))] = '1987';
    custom[fieldId(LBL('checkbox'))] = true;
    await fillRegistration(page, {
      fields: {
        nome: 'Full255', cognome: 'Campi', telefono: '3331234567', indirizzo: 'Via Piena 9',
        email, password: 'Password255!ok', password_confirm: 'Password255!ok',
      },
      custom,
    });
    await submitNoValidate(page);
    expect(userCount(email)).toBe(1);
    const stored = dbQuery(`SELECT v.campo_id, v.valore FROM utenti_campi_valori v JOIN utenti u ON u.id=v.utente_id WHERE u.email='${email}' ORDER BY v.campo_id`);
    const byId = Object.fromEntries(stored.split('\n').map((r) => r.split('\t')));
    expect(byId[fieldId(LBL('text'))]).toBe('@telegram_handle');
    expect(byId[fieldId(LBL('textarea'))]).toBe('riga1 riga2');
    expect(byId[fieldId(LBL('email'))]).toBe('alt@example.test');
    expect(byId[fieldId(LBL('url'))]).toBe('https://example.test/profilo');
    expect(byId[fieldId(LBL('number'))]).toBe('1987');
    expect(byId[fieldId(LBL('checkbox'))]).toBe('1');
  });

  test('17. minimal registration: surname stored as empty, phone/address NULL', async ({ page }) => {
    setToggles(false, false, false);
    const email = `zz-255-min-${TOKEN}@example.test`;
    await fillRegistration(page, {
      fields: { nome: 'Min255', email, password: 'Password255!ok', password_confirm: 'Password255!ok' },
    });
    await submitNoValidate(page);
    expect(userCount(email)).toBe(1);
    // Bracket the surname so the empty string survives dbQuery's trim().
    const row = dbQuery(`SELECT CONCAT('[', cognome, ']'), IFNULL(telefono,'<null>'), IFNULL(indirizzo,'<null>') FROM utenti WHERE email='${email}'`);
    expect(row).toBe('[]\t<null>\t<null>');
  });

  // ── 18-22: server-side rejection of invalid custom values ─────────────────

  const invalidCustom = [
    ['18. invalid email value rejected server-side', 'email', 'not-an-email'],
    ['19. javascript: URL rejected server-side', 'url', 'javascript:alert(1)'],
    ['20. non-numeric number rejected server-side', 'number', 'abc'],
    ['22. over-long value rejected server-side', 'text', 'x'.repeat(1001)],
  ];
  for (const [title, type, bad] of invalidCustom) {
    test(String(title), async ({ page }) => {
      setToggles(false, false, false);
      const email = `zz-255-bad-${type}-${TOKEN}@example.test`;
      const id = fieldId(LBL(type));
      await fillRegistration(page, {
        fields: { nome: 'Bad255', email, password: 'Password255!ok', password_confirm: 'Password255!ok' },
      });
      // A crafted POST is not bound by the browser's input-type filtering
      // (e.g. type=number silently drops 'abc'): neutralise the control type
      // so the raw value actually reaches the server contract under test.
      await page.evaluate(({ fieldName, value }) => {
        const el = document.querySelector(`[name="${fieldName}"]`);
        if (el instanceof HTMLInputElement) {
          el.type = 'text';
          el.value = value;
        } else if (el instanceof HTMLTextAreaElement) {
          el.value = value;
        }
      }, { fieldName: `custom_field[${id}]`, value: bad });
      await submitNoValidate(page);
      expect(page.url()).toContain('error=');
      expect(userCount(email)).toBe(0);
    });
  }

  test('21. missing required custom field rejected server-side', async ({ page }) => {
    const id = fieldId(LBL('text'));
    dbQuery(`UPDATE registrazione_campi SET obbligatorio=1 WHERE id=${id}`);
    try {
      const email = `zz-255-reqcf-${TOKEN}@example.test`;
      await fillRegistration(page, {
        fields: { nome: 'ReqCf255', email, password: 'Password255!ok', password_confirm: 'Password255!ok' },
      });
      await submitNoValidate(page);
      expect(page.url()).toContain('error=');
      expect(userCount(email)).toBe(0);
    } finally {
      dbQuery(`UPDATE registrazione_campi SET obbligatorio=0 WHERE id=${id}`);
    }
  });

  // ── 23-24: sanitization ────────────────────────────────────────────────────

  test('23. a tag-carrying label is neutralised on save (no raw < in DB or page)', async () => {
    await admin.goto(`${BASE}/admin/settings?tab=registration`);
    await admin.fill('input[name="new_custom_field_label"]', `Tele<script>window.__x=1</script> ${TOKEN}`);
    await admin.selectOption('select[name="new_custom_field_type"]', 'text');
    await Promise.all([
      admin.waitForLoadState('domcontentloaded'),
      admin.click('form[action*="/admin/settings/registration"] button[type="submit"]'),
    ]);
    const raw = dbQuery(`SELECT etichetta FROM registrazione_campi WHERE etichetta LIKE '%${TOKEN}' AND etichetta LIKE 'Tele%'`);
    expect(raw).not.toContain('<');
    expect(raw).not.toContain('script');
  });

  test('24. a script-carrying VALUE is escaped when rendered back (admin user detail)', async ({ page }) => {
    setToggles(false, false, false);
    const email = `zz-255-xss-${TOKEN}@example.test`;
    const id = fieldId(LBL('text'));
    const payload = '<script>window.__pwned=1</script>';
    const custom = {};
    custom[id] = payload;
    await fillRegistration(page, {
      fields: { nome: 'Xss255', email, password: 'Password255!ok', password_confirm: 'Password255!ok' },
      custom,
    });
    await submitNoValidate(page);
    expect(userCount(email)).toBe(1);

    const uid = dbQuery(`SELECT id FROM utenti WHERE email='${email}'`);
    await admin.goto(`${BASE}/admin/users/details/${uid}`);
    // The literal payload must be visible as TEXT (escaped), never executed.
    await expect(admin.locator('dd', { hasText: 'window.__pwned' })).toBeVisible();
    const pwned = await admin.evaluate(() => /** @type {any} */ (window).__pwned);
    expect(pwned).toBeUndefined();
    const scriptInDl = await admin.locator('dl script').count();
    expect(scriptInDl).toBe(0);
  });

  // ── 25: inactive fields disappear ──────────────────────────────────────────

  test('25. an inactive field no longer renders on the public form', async ({ page }) => {
    const id = fieldId(LBL('url'));
    dbQuery(`UPDATE registrazione_campi SET attivo=0 WHERE id=${id}`);
    try {
      await page.goto(`${BASE}/registrati`);
      await expect(page.locator(`[name="custom_field[${id}]"]`)).toHaveCount(0);
    } finally {
      dbQuery(`UPDATE registrazione_campi SET attivo=1 WHERE id=${id}`);
    }
  });

  // ── review-round fixes (adamsreview) ───────────────────────────────────────

  test('26. a format-invalid custom value redirects to the distinct error code (not missing_fields)', async ({ page }) => {
    setToggles(false, false, false);
    const email = `zz-255-fmt-${TOKEN}@example.test`;
    const id = fieldId(LBL('email'));
    await fillRegistration(page, {
      fields: { nome: 'Fmt255', email, password: 'Password255!ok', password_confirm: 'Password255!ok' },
    });
    // Force a bad email value into the (email-typed) custom control via a text
    // control so the browser doesn't drop it, then submit past client validation.
    await page.evaluate((fieldName) => {
      const el = document.querySelector(`[name="${fieldName}"]`);
      if (el instanceof HTMLInputElement) { el.type = 'text'; el.value = 'not-an-email'; }
    }, `custom_field[${id}]`);
    await submitNoValidate(page);
    expect(page.url()).toContain('error=custom_field_invalid');
    expect(userCount(email)).toBe(0);
  });

  test('27. a surname-less member renders with no trailing space in the admin user list', async ({ page }) => {
    setToggles(false, false, false);
    const email = `zz-255-noln-${TOKEN}@example.test`;
    await fillRegistration(page, {
      fields: { nome: 'SoloNome27', email, password: 'Password255!ok', password_confirm: 'Password255!ok' },
    });
    await submitNoValidate(page);
    expect(userCount(email)).toBe(1);
    // Stored surname is '' (optional); the admin list must not show "SoloNome27 ".
    await admin.goto(`${BASE}/admin/users`);
    const cell = admin.locator('td', { hasText: 'SoloNome27' }).first();
    await expect(cell).toBeVisible();
    const text = (await cell.innerText()).trim();
    // innerText of the cell may include the email on the next line; assert the
    // name line itself has no trailing space before a newline or end.
    expect(text).not.toMatch(/SoloNome27 (?:\n|$)/);
    expect(text).toContain('SoloNome27');
  });

  test('28. a newly-required custom field does not block existing users in the profile form', async () => {
    const id = fieldId(LBL('text'));
    dbQuery(`UPDATE registrazione_campi SET obbligatorio=1 WHERE id=${id}`);
    try {
      await admin.goto(`${BASE}/profilo`);
      const control = admin.locator(`[name="custom_field[${id}]"]`);
      await expect(control).toBeVisible();
      await expect(control).not.toHaveAttribute('required', /.*/);
      await expect(admin.locator('input[name="custom_fields_present"]')).toHaveValue('1');
    } finally {
      dbQuery(`UPDATE registrazione_campi SET obbligatorio=0 WHERE id=${id}`);
    }
  });

  test('29. a field type with stored values cannot be changed destructively', async () => {
    const id = fieldId(LBL('text'));
    expect(Number(dbQuery(`SELECT COUNT(*) FROM utenti_campi_valori WHERE campo_id=${id}`))).toBeGreaterThan(0);
    await admin.goto(`${BASE}/admin/settings?tab=registration`);
    await admin.selectOption(`select[name="custom_fields[${id}][tipo]"]`, 'checkbox');
    await Promise.all([
      admin.waitForLoadState('domcontentloaded'),
      admin.click('form[action*="/admin/settings/registration"] button[type="submit"]'),
    ]);
    expect(dbQuery(`SELECT tipo FROM registrazione_campi WHERE id=${id}`)).toBe('text');
    await expect(admin.locator('body')).toContainText(/non puoi cambiare il tipo|cannot change the type|ne pouvez pas modifier le type|Typ eines Feldes/i);
  });
});
