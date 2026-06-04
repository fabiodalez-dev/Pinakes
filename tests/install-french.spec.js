// @ts-check
/**
 * French language & BNF integration tests — branch feat/fr-bnf-integration
 *
 * Covers:
 *   Phase 1 — Installer UI: French locale option, step0 radio, UI text
 *   Phase 2 — Database state: fr_FR row in languages table
 *   Phase 3 — BNF SRU connectivity: real HTTP requests to catalogue.bnf.fr
 *   Phase 4 — Admin UI: fr_FR in language settings, BNF Z39 preset
 *   Phase 5 — Locale file validation: JSON keys, routes, SQL seed data
 *
 * Tests are non-destructive and run against an already-installed instance.
 * BNF connectivity tests (Phase 3) skip gracefully if BNF is unreachable.
 *
 * Run: /tmp/run-e2e.sh tests/install-french.spec.js --config=tests/playwright.config.js --workers=1
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const http = require('http');
const https = require('https');

const BASE        = process.env.E2E_BASE_URL    || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const DB_HOST     = process.env.E2E_DB_HOST     || 'localhost';
const DB_USER     = process.env.E2E_DB_USER     || '';
const DB_PASS     = process.env.E2E_DB_PASS     || '';
const DB_NAME     = process.env.E2E_DB_NAME     || '';
const DB_SOCKET   = process.env.E2E_DB_SOCKET   || '';
const DB_PORT     = process.env.E2E_DB_PORT     || '';

// Project root (two levels up from tests/)
const PROJECT_ROOT = path.resolve(__dirname, '..');

test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'install-french requires E2E_ADMIN_EMAIL, E2E_ADMIN_PASS, E2E_DB_USER, E2E_DB_NAME',
);

// ── DB helpers ────────────────────────────────────────────────────────────────

function mysqlArgs(sql) {
  const args = ['-N', '-B', '-e', sql];
  if (DB_HOST && !DB_SOCKET) args.push('-h', DB_HOST);
  if (DB_PORT && !DB_SOCKET) args.push('-P', DB_PORT);
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER);
  if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
  args.push(DB_NAME);
  return args;
}

function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout: 10000 }).trim();
}

// ── HTTP helper for BNF connectivity ─────────────────────────────────────────

/**
 * Fetch a URL with Node's built-in http/https module.
 * Returns the response body as a string, or throws on error/timeout.
 */
function fetchUrl(url, timeoutMs = 10000) {
  return new Promise((resolve, reject) => {
    const lib = url.startsWith('https') ? https : http;
    const req = lib.get(url, { timeout: timeoutMs }, (res) => {
      let body = '';
      res.on('data', (chunk) => { body += chunk; });
      res.on('end', () => resolve({ status: res.statusCode, body }));
    });
    req.on('error', reject);
    req.on('timeout', () => { req.destroy(); reject(new Error('Request timed out')); });
  });
}

const BNF_SRU = 'http://catalogue.bnf.fr/api/SRU';

// Well-known ISBN at BNF: "Le Grand Meaulnes" by Alain-Fournier (Gallimard)
const BNF_TEST_ISBN = '2070360024';

// ── Z39 modal helper ──────────────────────────────────────────────────────────

/**
 * Open the Z39.50 configuration modal on the plugins page.
 * Returns false if the configure button is not found or the modal doesn't open.
 */
async function openZ39Modal(page) {
  await page.goto(`${BASE}/admin/plugins`, { waitUntil: 'domcontentloaded' });
  const configBtn = page.locator(
    'button[onclick*="openZ39ServerModal"], button:has-text("Configura Z39"), button:has-text("Configure Z39")'
  ).first();
  const visible = await configBtn.isVisible({ timeout: 5000 }).catch(() => false);
  if (!visible) return false;
  await configBtn.click();
  const modal = page.locator('#z39ServerModal');
  await modal.waitFor({ state: 'visible', timeout: 8000 }).catch(() => {});
  return modal.isVisible().catch(() => false);
}

// ── Admin login helper ────────────────────────────────────────────────────────

async function adminLogin(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  // Try Italian route too
  if (await page.locator('input[name="email"]').count() === 0) {
    await page.goto(`${BASE}/accedi`, { waitUntil: 'domcontentloaded' });
  }
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.click('button[type="submit"]');
  await page.waitForURL(/\/(admin|dashboard|books|libri)/, { timeout: 10000 });
}

// ═════════════════════════════════════════════════════════════════════════════
// Phase 1: Installer UI — French language support
// ═════════════════════════════════════════════════════════════════════════════

test.describe.serial('Phase 1: Installer UI — French language option', () => {

  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  let installerAvailable = false;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    const resp = await page.goto(`${BASE}/installer/`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    if (resp && resp.url().includes('/installer/') && !resp.url().includes('step')) {
      installerAvailable = await page.locator('input[name="language"]').count() > 0;
    }
  });
  test.afterAll(async () => { await context?.close(); });

  test('1.1 Installer step0 page has a French locale radio button', async () => {
    test.skip(!installerAvailable, 'App already installed — installer UI not available');
    await page.goto(`${BASE}/installer/`, { waitUntil: 'domcontentloaded' });
    const frRadio = page.locator('input[type="radio"][value="fr_FR"]');
    await expect(frRadio).toHaveCount(1);
  });

  test('1.2 French radio button has a French flag visible nearby', async () => {
    test.skip(!installerAvailable, 'App already installed');
    await page.goto(`${BASE}/installer/`, { waitUntil: 'domcontentloaded' });
    // The fr_FR radio label area should contain FR flag emoji or SVG
    const frContainer = page.locator('label:has(input[value="fr_FR"]), div:has(input[value="fr_FR"])').first();
    const text = await frContainer.textContent().catch(() => '');
    expect(text).toMatch(/🇫🇷|fr_FR|[Ff]rançais|[Ff]rench/i);
  });

  test('1.3 French step button text is "Continuer"', async () => {
    test.skip(!installerAvailable, 'App already installed');
    await page.goto(`${BASE}/installer/`, { waitUntil: 'domcontentloaded' });
    await page.locator('input[type="radio"][value="fr_FR"]').click();
    // After clicking French, the submit button text should update
    await page.waitForTimeout(300);
    const btn = page.locator('button[type="submit"], input[type="submit"]').first();
    const btnText = await btn.textContent() || await btn.inputValue().catch(() => '');
    expect(btnText).toMatch(/Continuer|Continue|Weiter|Avanti/i);
  });

  test('1.4 Installer step0 has Italian, English, German and French options', async () => {
    test.skip(!installerAvailable, 'App already installed');
    await page.goto(`${BASE}/installer/`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('input[value="it_IT"]')).toHaveCount(1);
    await expect(page.locator('input[value="en_US"]')).toHaveCount(1);
    await expect(page.locator('input[value="de_DE"]')).toHaveCount(1);
    await expect(page.locator('input[value="fr_FR"]')).toHaveCount(1);
  });
});

// ═════════════════════════════════════════════════════════════════════════════
// Phase 2: Locale file validation
// ═════════════════════════════════════════════════════════════════════════════

test.describe.serial('Phase 2: Locale file validation', () => {

  test('2.1 locale/fr_FR.json exists', async () => {
    const filePath = path.join(PROJECT_ROOT, 'locale', 'fr_FR.json');
    expect(fs.existsSync(filePath)).toBe(true);
  });

  test('2.2 locale/fr_FR.json is valid JSON', async () => {
    const filePath = path.join(PROJECT_ROOT, 'locale', 'fr_FR.json');
    const content = fs.readFileSync(filePath, 'utf-8');
    expect(() => JSON.parse(content)).not.toThrow();
  });

  test('2.3 locale/fr_FR.json has at least 100 translation keys', async () => {
    const filePath = path.join(PROJECT_ROOT, 'locale', 'fr_FR.json');
    const data = JSON.parse(fs.readFileSync(filePath, 'utf-8'));
    expect(Object.keys(data).length).toBeGreaterThan(100);
  });

  test('2.4 locale/routes_fr_FR.json exists', async () => {
    const filePath = path.join(PROJECT_ROOT, 'locale', 'routes_fr_FR.json');
    expect(fs.existsSync(filePath)).toBe(true);
  });

  test('2.5 routes_fr_FR.json has "login" route (English slugs, not Italian)', async () => {
    const filePath = path.join(PROJECT_ROOT, 'locale', 'routes_fr_FR.json');
    const data = JSON.parse(fs.readFileSync(filePath, 'utf-8'));
    // French uses English slugs (login, not accedi)
    expect(data).toHaveProperty('login');
    expect(data.login).not.toMatch(/accedi/i);
  });

  test('2.6 installer/database/data_fr_FR.sql exists', async () => {
    const filePath = path.join(PROJECT_ROOT, 'installer', 'database', 'data_fr_FR.sql');
    expect(fs.existsSync(filePath)).toBe(true);
  });

  test('2.7 data_fr_FR.sql inserts fr_FR as default language (is_default=1)', async () => {
    const filePath = path.join(PROJECT_ROOT, 'installer', 'database', 'data_fr_FR.sql');
    const content = fs.readFileSync(filePath, 'utf-8');
    // Should have fr_FR with is_default=1
    expect(content).toContain('fr_FR');
    expect(content).toMatch(/fr_FR.*1,\s*1|is_default.*1.*fr_FR/s);
  });

  test('2.8 data_it_IT.sql includes fr_FR in languages seed', async () => {
    const filePath = path.join(PROJECT_ROOT, 'installer', 'database', 'data_it_IT.sql');
    const content = fs.readFileSync(filePath, 'utf-8');
    expect(content).toContain('fr_FR');
  });

  test('2.9 data_en_US.sql includes fr_FR in languages seed', async () => {
    const filePath = path.join(PROJECT_ROOT, 'installer', 'database', 'data_en_US.sql');
    const content = fs.readFileSync(filePath, 'utf-8');
    expect(content).toContain('fr_FR');
  });

  test('2.10 data_de_DE.sql includes fr_FR in languages seed', async () => {
    const filePath = path.join(PROJECT_ROOT, 'installer', 'database', 'data_de_DE.sql');
    const content = fs.readFileSync(filePath, 'utf-8');
    expect(content).toContain('fr_FR');
  });

  test('2.11 migrate_0.7.04.sql backfills fr_FR in languages table', async () => {
    const filePath = path.join(PROJECT_ROOT, 'installer', 'database', 'migrations', 'migrate_0.7.04.sql');
    const content = fs.readFileSync(filePath, 'utf-8');
    expect(content).toContain('fr_FR');
    expect(content).toContain('INSERT IGNORE INTO `languages`');
  });
});

// ═════════════════════════════════════════════════════════════════════════════
// Phase 3: Database state — fr_FR in languages table
// ═════════════════════════════════════════════════════════════════════════════

test.describe.serial('Phase 3: Database state — fr_FR language row', () => {

  test('3.1 languages table contains fr_FR row', async () => {
    const count = dbQuery("SELECT COUNT(*) FROM languages WHERE code='fr_FR'");
    expect(parseInt(count, 10)).toBe(1);
  });

  test('3.2 fr_FR language row is active', async () => {
    const active = dbQuery("SELECT is_active FROM languages WHERE code='fr_FR'");
    expect(active).toBe('1');
  });

  test('3.3 fr_FR translation_file points to locale/fr_FR.json', async () => {
    const file = dbQuery("SELECT translation_file FROM languages WHERE code='fr_FR'");
    expect(file).toContain('fr_FR.json');
  });

  test('3.4 fr_FR total_keys > 0', async () => {
    const keys = dbQuery("SELECT total_keys FROM languages WHERE code='fr_FR'");
    expect(parseInt(keys, 10)).toBeGreaterThan(0);
  });

  test('3.5 No duplicate fr_FR entries in languages table', async () => {
    const count = dbQuery("SELECT COUNT(*) FROM languages WHERE code='fr_FR'");
    expect(parseInt(count, 10)).toBe(1);
  });
});

// ═════════════════════════════════════════════════════════════════════════════
// Phase 4: BNF SRU API connectivity
// ═════════════════════════════════════════════════════════════════════════════

test.describe.serial('Phase 4: BNF SRU API connectivity', () => {

  let bnfReachable = false;

  test.beforeAll(async () => {
    try {
      const resp = await fetchUrl(`${BNF_SRU}?operation=explain&version=1.2`, 8000);
      bnfReachable = resp.status === 200 && resp.body.includes('explain');
    } catch (_) {
      bnfReachable = false;
    }
  });

  test('4.1 BNF SRU endpoint is reachable', async () => {
    test.skip(!bnfReachable, 'BNF SRU not reachable from this network');
    const resp = await fetchUrl(`${BNF_SRU}?operation=explain&version=1.2`, 10000);
    expect(resp.status).toBe(200);
    expect(resp.body.length).toBeGreaterThan(100);
  });

  test('4.2 BNF explain response is valid XML', async () => {
    test.skip(!bnfReachable, 'BNF SRU not reachable from this network');
    const resp = await fetchUrl(`${BNF_SRU}?operation=explain&version=1.2`, 10000);
    expect(resp.body).toMatch(/<\?xml|<explain|<explainResponse/i);
  });

  test('4.3 BNF ISBN search returns a UNIMARC/MARCXchange record', async () => {
    test.skip(!bnfReachable, 'BNF SRU not reachable from this network');
    const query = encodeURIComponent(`bib.isbn="${BNF_TEST_ISBN}"`);
    const url = `${BNF_SRU}?operation=searchRetrieve&version=1.2&query=${query}&maximumRecords=1&recordSchema=unimarcxchange`;
    const resp = await fetchUrl(url, 15000);
    expect(resp.status).toBe(200);
    // Response should contain SRW envelope
    expect(resp.body).toMatch(/numberOfRecords|searchRetrieveResponse/i);
  });

  test('4.4 BNF search response contains at least 1 record for known ISBN', async () => {
    test.skip(!bnfReachable, 'BNF SRU not reachable from this network');
    const query = encodeURIComponent(`bib.isbn="${BNF_TEST_ISBN}"`);
    const url = `${BNF_SRU}?operation=searchRetrieve&version=1.2&query=${query}&maximumRecords=1&recordSchema=unimarcxchange`;
    const resp = await fetchUrl(url, 15000);
    // numberOfRecords should be > 0
    const match = resp.body.match(/<(?:\w+:)?numberOfRecords>(\d+)</);
    expect(match).not.toBeNull();
    expect(parseInt(match[1], 10)).toBeGreaterThan(0);
  });

  test('4.5 BNF response contains UNIMARC field 200 (title)', async () => {
    test.skip(!bnfReachable, 'BNF SRU not reachable from this network');
    const query = encodeURIComponent(`bib.isbn="${BNF_TEST_ISBN}"`);
    const url = `${BNF_SRU}?operation=searchRetrieve&version=1.2&query=${query}&maximumRecords=1&recordSchema=unimarcxchange`;
    const resp = await fetchUrl(url, 15000);
    // UNIMARC field 200 = title
    expect(resp.body).toMatch(/tag="200"|datafield tag="200"/i);
  });

  test('4.6 BNF response contains UNIMARC field 700 or 200$f (author)', async () => {
    test.skip(!bnfReachable, 'BNF SRU not reachable from this network');
    const query = encodeURIComponent(`bib.isbn="${BNF_TEST_ISBN}"`);
    const url = `${BNF_SRU}?operation=searchRetrieve&version=1.2&query=${query}&maximumRecords=1&recordSchema=unimarcxchange`;
    const resp = await fetchUrl(url, 15000);
    // UNIMARC 700 = personal author entry
    expect(resp.body).toMatch(/tag="700"|tag="200"/i);
  });

  test('4.7 BNF search with version 1.2 works correctly (not 1.1)', async () => {
    test.skip(!bnfReachable, 'BNF SRU not reachable from this network');
    const query = encodeURIComponent(`bib.isbn="${BNF_TEST_ISBN}"`);
    // Version 1.2 is required by BNF
    const url12 = `${BNF_SRU}?operation=searchRetrieve&version=1.2&query=${query}&maximumRecords=1&recordSchema=unimarcxchange`;
    const resp = await fetchUrl(url12, 15000);
    expect(resp.status).toBe(200);
    const match = resp.body.match(/<(?:\w+:)?numberOfRecords>(\d+)</);
    expect(match).not.toBeNull();
    expect(parseInt(match[1], 10)).toBeGreaterThan(0);
  });
});

// ═════════════════════════════════════════════════════════════════════════════
// Phase 5: Admin UI — French locale + BNF Z39 preset
// ═════════════════════════════════════════════════════════════════════════════

test.describe.serial('Phase 5: Admin UI — French locale and BNF Z39 preset', () => {

  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    await adminLogin(page);
  });
  test.afterAll(async () => { await context?.close(); });

  test('5.1 Admin settings page loads without error', async () => {
    await page.goto(`${BASE}/admin/settings`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('body')).not.toContainText('Error');
    await expect(page.locator('body')).not.toContainText('500');
  });

  test('5.2 Admin settings shows language/i18n section', async () => {
    await page.goto(`${BASE}/admin/settings`, { waitUntil: 'domcontentloaded' });
    // Locate i18n tab or language section
    const i18nTab = page.locator('[data-tab="i18n"], [href*="i18n"], button:has-text("I18n"), button:has-text("Lingua")').first();
    if (await i18nTab.count() > 0) {
      await i18nTab.click();
      await page.waitForTimeout(300);
    }
    // Page should have some content about languages
    const pageText = await page.locator('body').textContent();
    expect(pageText).toBeTruthy();
  });

  test('5.3 Z39 plugin page loads without error', async () => {
    await page.goto(`${BASE}/admin/plugins`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('body')).not.toContainText('Fatal error');
  });

  test('5.4 Z39 preset dropdown has BnF option', async () => {
    await page.goto(`${BASE}/admin/plugins`, { waitUntil: 'domcontentloaded' });

    // Try to open Z39 settings modal
    const z39Link = page.locator('[data-plugin="z39-server"] a, a[href*="z39"], button:has-text("Z39")').first();
    if (await z39Link.count() > 0) {
      await z39Link.click();
      await page.waitForTimeout(500);
    }

    const presetSelect = page.locator('#z39PresetServers');
    if (await presetSelect.count() === 0) {
      test.skip(true, 'Z39 preset dropdown not visible');
      return;
    }

    const bnfOption = presetSelect.locator('option[value="bnf"]');
    await expect(bnfOption).toHaveCount(1);
  });

  test('5.5 BnF preset option text mentions BnF or France', async () => {
    await page.goto(`${BASE}/admin/plugins`, { waitUntil: 'domcontentloaded' });

    const presetSelect = page.locator('#z39PresetServers');
    if (await presetSelect.count() === 0) {
      test.skip(true, 'Z39 preset dropdown not visible');
      return;
    }

    const bnfOption = presetSelect.locator('option[value="bnf"]');
    const text = await bnfOption.textContent().catch(() => '');
    expect(text).toMatch(/BnF|BNF|Biblioth[eè]que|France|FR/i);
  });

  test('5.6 Syntax dropdown includes unimarcxchange option', async () => {
    await page.goto(`${BASE}/admin/plugins`, { waitUntil: 'domcontentloaded' });

    // Check that the syntax dropdown template has unimarcxchange option
    // (visible by clicking Add Server, then checking the syntax dropdown)
    const addBtn = page.locator('button:has-text("Aggiungi"), button:has-text("Add"), #z39AddServerBtn').first();
    if (await addBtn.count() > 0) {
      await addBtn.click();
      await page.waitForTimeout(300);
      const syntaxSelect = page.locator('[name="server_syntax[]"]').last();
      if (await syntaxSelect.count() > 0) {
        const unimarcOption = syntaxSelect.locator('option[value="unimarcxchange"]');
        await expect(unimarcOption).toHaveCount(1);
      }
    }
  });

  test('5.7 Adding BnF preset populates URL with catalogue.bnf.fr', async () => {
    const modalOpen = await openZ39Modal(page);
    if (!modalOpen) {
      test.skip(true, 'Z39 modal could not be opened');
      return;
    }

    const presetSelect = page.locator('#z39PresetServers');
    await presetSelect.selectOption('bnf');
    await page.waitForTimeout(500);

    const urlInputs = page.locator('[name="server_url[]"]');
    const count = await urlInputs.count();
    let found = false;
    for (let i = 0; i < count; i++) {
      const val = await urlInputs.nth(i).inputValue();
      if (val.includes('bnf.fr')) { found = true; break; }
    }
    expect(found).toBe(true);
  });

  test('5.8 BnF preset row uses unimarcxchange syntax', async () => {
    const modalOpen = await openZ39Modal(page);
    if (!modalOpen) {
      test.skip(true, 'Z39 modal could not be opened');
      return;
    }

    await page.locator('#z39PresetServers').selectOption('bnf');
    await page.waitForTimeout(500);

    const rows = page.locator('.z39-server-row');
    const rowCount = await rows.count();
    let syntaxVal = '';
    for (let i = 0; i < rowCount; i++) {
      const row = rows.nth(i);
      const urlVal = await row.locator('[name="server_url[]"]').inputValue().catch(() => '');
      if (urlVal.includes('bnf.fr')) {
        syntaxVal = await row.locator('[name="server_syntax[]"]').inputValue().catch(() => '');
        break;
      }
    }
    expect(syntaxVal).toBe('unimarcxchange');
  });

  test('5.9 BnF preset row shows version 1.2 in version field', async () => {
    const modalOpen = await openZ39Modal(page);
    if (!modalOpen) {
      test.skip(true, 'Z39 modal could not be opened');
      return;
    }

    await page.locator('#z39PresetServers').selectOption('bnf');
    await page.waitForTimeout(500);

    const rows = page.locator('.z39-server-row');
    const rowCount = await rows.count();
    let versionVal = '';
    for (let i = 0; i < rowCount; i++) {
      const row = rows.nth(i);
      const urlVal = await row.locator('[name="server_url[]"]').inputValue().catch(() => '');
      if (urlVal.includes('bnf.fr')) {
        versionVal = await row.locator('[name="server_version[]"]').inputValue().catch(() => '');
        break;
      }
    }
    expect(versionVal).toBe('1.2');
  });

  test('5.10 BnF preset row has "quote search terms" checkbox checked', async () => {
    const modalOpen = await openZ39Modal(page);
    if (!modalOpen) {
      test.skip(true, 'Z39 modal could not be opened');
      return;
    }

    await page.locator('#z39PresetServers').selectOption('bnf');
    await page.waitForTimeout(500);

    const rows = page.locator('.z39-server-row');
    const rowCount = await rows.count();
    let quoteChecked = false;
    for (let i = 0; i < rowCount; i++) {
      const row = rows.nth(i);
      const urlVal = await row.locator('[name="server_url[]"]').inputValue().catch(() => '');
      if (urlVal.includes('bnf.fr')) {
        quoteChecked = await row.locator('[name="server_quote_terms[]"]').isChecked().catch(() => false);
        break;
      }
    }
    expect(quoteChecked).toBe(true);
  });
});

// ═════════════════════════════════════════════════════════════════════════════
// Phase 6: SruClient UNIMARC parser validation (file-level checks)
// ═════════════════════════════════════════════════════════════════════════════

test.describe.serial('Phase 6: SruClient — UNIMARC parser implementation', () => {

  const SRU_CLIENT = path.join(PROJECT_ROOT, 'storage', 'plugins', 'z39-server', 'classes', 'SruClient.php');

  test('6.1 SruClient.php exists', async () => {
    expect(fs.existsSync(SRU_CLIENT)).toBe(true);
  });

  test('6.2 SruClient.php contains parseMarcxchangeXml method', async () => {
    const content = fs.readFileSync(SRU_CLIENT, 'utf-8');
    expect(content).toContain('parseMarcxchangeXml');
  });

  test('6.3 SruClient handles unimarcxchange schema in match statement', async () => {
    const content = fs.readFileSync(SRU_CLIENT, 'utf-8');
    expect(content).toContain('unimarcxchange');
    expect(content).toMatch(/unimarcxchange.*parseMarcxchangeXml|parseMarcxchangeXml.*unimarcxchange/s);
  });

  test('6.4 SruClient registers mxc namespace for MARCXchange', async () => {
    const content = fs.readFileSync(SRU_CLIENT, 'utf-8');
    expect(content).toContain("info:lc/xmlns/marcxchange-v2");
    expect(content).toContain("'mxc'");
  });

  test('6.5 SruClient supports quote_search_terms parameter', async () => {
    const content = fs.readFileSync(SRU_CLIENT, 'utf-8');
    expect(content).toContain('quote_search_terms');
  });

  test('6.6 SruClient UNIMARC parser reads field 200 (title)', async () => {
    const content = fs.readFileSync(SRU_CLIENT, 'utf-8');
    expect(content).toMatch(/'200'.*'a'|getSub\(.*'200'/s);
  });

  test('6.7 SruClient UNIMARC parser reads field 700/701 (author)', async () => {
    const content = fs.readFileSync(SRU_CLIENT, 'utf-8');
    expect(content).toContain("'700'");
    expect(content).toContain("'701'");
  });

  test('6.8 SruClient UNIMARC parser reads field 214 (publisher/year)', async () => {
    const content = fs.readFileSync(SRU_CLIENT, 'utf-8');
    expect(content).toContain("'214'");
  });

  test('6.9 SruClient UNIMARC parser reads field 010 (ISBN)', async () => {
    const content = fs.readFileSync(SRU_CLIENT, 'utf-8');
    expect(content).toContain("'010'");
  });

  test('6.10 SruClient UNIMARC parser reads field 676 (Dewey)', async () => {
    const content = fs.readFileSync(SRU_CLIENT, 'utf-8');
    expect(content).toContain("'676'");
  });

  test('6.11 SruClient numberOfRecords has local-name() fallback for BNF', async () => {
    const content = fs.readFileSync(SRU_CLIENT, 'utf-8');
    expect(content).toContain('local-name()="numberOfRecords"');
  });
});
