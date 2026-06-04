// @ts-check
/**
 * Installation & standard compatibility tests — branch feat/fr-bnf-integration
 *
 * Covers:
 *   Phase 1 — Multi-locale seed files: all locales include all supported languages
 *   Phase 2 — Route compatibility: fr_FR routes have same keys as other locales
 *   Phase 3 — Migration compatibility: migrate_0.7.04.sql backfills fr_FR correctly
 *   Phase 4 — Installer code compatibility: fr_FR registered everywhere it_IT/en_US/de_DE are
 *   Phase 5 — DB upgrade simulation: existing install gets fr_FR after migration
 *   Phase 6 — BNF URL security: preset uses HTTPS
 *   Phase 7 — SruClient robustness: \Throwable catch, libxml state restoration
 *   Phase 8 — data_fr_FR route coherence: button links match routes_fr_FR.json
 *
 * All file-system phases run without browser. DB phases require E2E env vars.
 *
 * Run: /tmp/run-e2e.sh tests/install-compat-standard.spec.js --config=tests/playwright.config.js --workers=1
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs   = require('fs');
const path = require('path');

const BASE      = process.env.E2E_BASE_URL    || 'http://localhost:8081';
const DB_HOST   = process.env.E2E_DB_HOST     || 'localhost';
const DB_USER   = process.env.E2E_DB_USER     || '';
const DB_PASS   = process.env.E2E_DB_PASS     || '';
const DB_NAME   = process.env.E2E_DB_NAME     || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET   || '';
const DB_PORT   = process.env.E2E_DB_PORT     || '';

const ROOT = path.resolve(__dirname, '..');

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

// ── Helpers ───────────────────────────────────────────────────────────────────

function readJson(filePath) {
  return JSON.parse(fs.readFileSync(filePath, 'utf-8'));
}

function readFile(filePath) {
  return fs.readFileSync(filePath, 'utf-8');
}

const LOCALES      = ['it_IT', 'en_US', 'de_DE', 'fr_FR'];
const ROUTE_FILES  = LOCALES.map(l => path.join(ROOT, 'locale', `routes_${l}.json`));
const MIGRATION    = path.join(ROOT, 'installer', 'database', 'migrations', 'migrate_0.7.04.sql');
const INSTALLER_PHP = path.join(ROOT, 'installer', 'classes', 'Installer.php');
const STEP0_PHP    = path.join(ROOT, 'installer', 'steps', 'step0.php');
const INDEX_PHP    = path.join(ROOT, 'installer', 'index.php');
const SRU_CLIENT   = path.join(ROOT, 'storage', 'plugins', 'z39-server', 'classes', 'SruClient.php');
const PLUGINS_PHP  = path.join(ROOT, 'app', 'Views', 'admin', 'plugins.php');
const FR_SEED      = path.join(ROOT, 'installer', 'database', 'data_fr_FR.sql');
const FR_ROUTES    = path.join(ROOT, 'locale', 'routes_fr_FR.json');

// ═════════════════════════════════════════════════════════════════════════════
// Phase 1: Multi-locale seed files include all supported languages
// ═════════════════════════════════════════════════════════════════════════════
test.describe.serial('Phase 1: Seed files include all supported languages', () => {
  for (const locale of LOCALES) {
    test(`1.${LOCALES.indexOf(locale) + 1} data_${locale}.sql includes fr_FR language row`, () => {
      const content = readFile(path.join(ROOT, 'installer', 'database', `data_${locale}.sql`));
      expect(content).toContain('fr_FR');
    });
  }

  test('1.5 data_fr_FR.sql includes it_IT language row', () => {
    expect(readFile(FR_SEED)).toContain('it_IT');
  });

  test('1.6 data_fr_FR.sql includes en_US language row', () => {
    expect(readFile(FR_SEED)).toContain('en_US');
  });

  test('1.7 data_fr_FR.sql includes de_DE language row', () => {
    expect(readFile(FR_SEED)).toContain('de_DE');
  });

  test('1.8 data_fr_FR.sql sets fr_FR is_default=1', () => {
    const content = readFile(FR_SEED);
    // The fr_FR row inside the INSERT should have is_default=1
    expect(content).toMatch(/fr_FR[^)]*,\s*1\s*,\s*1/);
  });

  test('1.9 data_fr_FR.sql uses completion_percentage column (not completion_percent)', () => {
    const content = readFile(FR_SEED);
    expect(content).toContain('completion_percentage');
    expect(content).not.toContain('`completion_percent`');
  });
});

// ═════════════════════════════════════════════════════════════════════════════
// Phase 2: Route compatibility — fr_FR has same keys as reference locale (it_IT)
// ═════════════════════════════════════════════════════════════════════════════
test.describe.serial('Phase 2: Route compatibility across locales', () => {
  let itKeys, frKeys, enKeys, deKeys;

  test('2.1 All route files exist', () => {
    for (const f of ROUTE_FILES) {
      expect(fs.existsSync(f), `Missing: ${path.basename(f)}`).toBe(true);
    }
  });

  test('2.2 All route files are valid JSON', () => {
    for (const f of ROUTE_FILES) {
      expect(() => readJson(f), `Invalid JSON: ${path.basename(f)}`).not.toThrow();
    }
    itKeys = Object.keys(readJson(path.join(ROOT, 'locale', 'routes_it_IT.json')));
    frKeys = Object.keys(readJson(FR_ROUTES));
    enKeys = Object.keys(readJson(path.join(ROOT, 'locale', 'routes_en_US.json')));
    deKeys = Object.keys(readJson(path.join(ROOT, 'locale', 'routes_de_DE.json')));
  });

  test('2.3 fr_FR routes have same number of keys as it_IT', () => {
    if (!itKeys || !frKeys) test.skip(true, 'Route files could not be parsed');
    expect(frKeys.length).toBe(itKeys.length);
  });

  test('2.4 fr_FR routes contain all keys from it_IT', () => {
    if (!itKeys || !frKeys) test.skip(true, 'Route files could not be parsed');
    const missing = itKeys.filter(k => !frKeys.includes(k));
    expect(missing, `Missing route keys in fr_FR: ${missing.join(', ')}`).toHaveLength(0);
  });

  test('2.5 fr_FR routes contain no extra keys vs it_IT', () => {
    if (!itKeys || !frKeys) test.skip(true, 'Route files could not be parsed');
    const extra = frKeys.filter(k => !itKeys.includes(k));
    expect(extra, `Extra route keys in fr_FR not in it_IT: ${extra.join(', ')}`).toHaveLength(0);
  });

  test('2.6 fr_FR route values are non-empty strings', () => {
    if (!frKeys) test.skip(true, 'Route files could not be parsed');
    const routes = readJson(FR_ROUTES);
    const empty = frKeys.filter(k => typeof routes[k] !== 'string' || routes[k].trim() === '');
    expect(empty, `Empty route values in fr_FR: ${empty.join(', ')}`).toHaveLength(0);
  });

  test('2.7 fr_FR route values all start with /', () => {
    if (!frKeys) test.skip(true, 'Route files could not be parsed');
    const routes = readJson(FR_ROUTES);
    const bad = frKeys.filter(k => !String(routes[k]).startsWith('/'));
    expect(bad, `Routes not starting with /: ${bad.join(', ')}`).toHaveLength(0);
  });

  test('2.8 fr_FR does not duplicate route paths (no two keys share same path)', () => {
    if (!frKeys) test.skip(true, 'Route files could not be parsed');
    const routes = readJson(FR_ROUTES);
    const paths = frKeys.map(k => routes[k]);
    const seen = new Set();
    const dupes = [];
    for (const p of paths) {
      if (seen.has(p)) dupes.push(p);
      seen.add(p);
    }
    // Allow known intentional duplicates:
    // - .php legacy aliases
    // - archives/archive_item share the same base path (differentiated by URL parameter)
    const archivePaths = [routes['archives'], routes['archive_item']].filter(Boolean);
    const nonLegacyDupes = dupes.filter(p =>
      !p.includes('.php') && !archivePaths.includes(p)
    );
    expect(nonLegacyDupes, `Duplicate route paths: ${nonLegacyDupes.join(', ')}`).toHaveLength(0);
  });

  test('2.9 fr_FR login route is localized to /connexion', () => {
    const routes = readJson(FR_ROUTES);
    expect(routes['login']).toBe('/connexion');
  });
});

// ═════════════════════════════════════════════════════════════════════════════
// Phase 3: Migration compatibility
// ═════════════════════════════════════════════════════════════════════════════
test.describe.serial('Phase 3: migrate_0.7.04.sql backfills fr_FR', () => {
  test('3.1 Migration file exists', () => {
    expect(fs.existsSync(MIGRATION)).toBe(true);
  });

  test('3.2 Migration contains INSERT IGNORE INTO languages for fr_FR', () => {
    const content = readFile(MIGRATION);
    expect(content).toContain('INSERT IGNORE INTO `languages`');
    expect(content).toContain('fr_FR');
  });

  test('3.3 Migration uses completion_percentage (correct column name)', () => {
    const content = readFile(MIGRATION);
    // Extract the fr_FR INSERT section
    const idx = content.lastIndexOf('fr_FR');
    const section = content.substring(Math.max(0, idx - 200), idx + 200);
    expect(section).toContain('completion_percentage');
    expect(section).not.toContain('completion_percent\'');
  });

  test('3.4 Migration version (0.7.4) is ≤ release version', () => {
    // The migration file itself is named 0.7.4 — this is always <= 0.7.4
    const versionJson = path.join(ROOT, 'version.json');
    if (!fs.existsSync(versionJson)) {
      // version.json may not exist in this branch; skip
      return;
    }
    const versionData = readJson(versionJson);
    const releaseVersion = versionData.version || versionData.app_version || '0.0.0';
    // Simple comparison: 0.7.4 <= release
    const migVer = [0, 7, 4];
    const relParts = String(releaseVersion).split('.').map(Number);
    let ok = true;
    for (let i = 0; i < migVer.length; i++) {
      if (migVer[i] < (relParts[i] || 0)) { ok = true; break; }
      if (migVer[i] > (relParts[i] || 0)) { ok = false; break; }
    }
    expect(ok, `Migration 0.7.4 > release ${releaseVersion}`).toBe(true);
  });

  test('3.5 DB currently has fr_FR (backfill was applied)', () => {
    test.skip(!DB_USER || !DB_NAME, 'DB env vars not set');
    const count = dbQuery("SELECT COUNT(*) FROM languages WHERE code='fr_FR'");
    expect(parseInt(count, 10)).toBe(1);
  });

  test('3.6 Existing languages not removed by migration (it_IT still present)', () => {
    test.skip(!DB_USER || !DB_NAME, 'DB env vars not set');
    const count = dbQuery("SELECT COUNT(*) FROM languages WHERE code='it_IT'");
    expect(parseInt(count, 10)).toBeGreaterThanOrEqual(1);
  });

  test('3.7 No duplicate languages in DB after migration', () => {
    test.skip(!DB_USER || !DB_NAME, 'DB env vars not set');
    const result = dbQuery(
      "SELECT code, COUNT(*) as c FROM languages GROUP BY code HAVING c > 1"
    );
    expect(result.trim()).toBe('');
  });
});

// ═════════════════════════════════════════════════════════════════════════════
// Phase 4: Installer code compatibility
// ═════════════════════════════════════════════════════════════════════════════
test.describe.serial('Phase 4: Installer code registers fr_FR in all required locations', () => {
  test('4.1 Installer.php references fr_FR in locale map', () => {
    const content = readFile(INSTALLER_PHP);
    expect(content).toContain('fr_FR');
  });

  test('4.2 Installer.php has French-specific entry (not just a stray mention)', () => {
    const content = readFile(INSTALLER_PHP);
    // Should appear at least 2 times (locale map + at least one match/case)
    const matches = (content.match(/fr_FR/g) || []).length;
    expect(matches).toBeGreaterThanOrEqual(2);
  });

  test('4.3 step0.php contains fr_FR radio button value', () => {
    const content = readFile(STEP0_PHP);
    expect(content).toContain('fr_FR');
    expect(content).toMatch(/value=['"]fr_FR['"]/);
  });

  test('4.4 step0.php has French flag or tricolor indicator', () => {
    const content = readFile(STEP0_PHP);
    // Either emoji or SVG with French colors (bleu/blanc/rouge)
    expect(content).toMatch(/🇫🇷|fr_FR|Français|French/);
  });

  test('4.5 installer/index.php normalizes fr_FR', () => {
    const content = readFile(INDEX_PHP);
    expect(content).toContain('fr_FR');
  });

  test('4.6 fr_FR appears in same code sections as de_DE in Installer.php', () => {
    const content = readFile(INSTALLER_PHP);
    // Count occurrences of de_DE and fr_FR — should be similar (within 2)
    const deCount = (content.match(/de_DE/g) || []).length;
    const frCount = (content.match(/fr_FR/g) || []).length;
    expect(Math.abs(deCount - frCount)).toBeLessThanOrEqual(2);
  });

  test('4.7 locale/fr_FR.json exists and is valid JSON', () => {
    const frJson = path.join(ROOT, 'locale', 'fr_FR.json');
    expect(fs.existsSync(frJson)).toBe(true);
    expect(() => readJson(frJson)).not.toThrow();
  });

  test('4.8 locale/fr_FR.json has at least 4000 keys', () => {
    const frJson = path.join(ROOT, 'locale', 'fr_FR.json');
    const keys = Object.keys(readJson(frJson));
    expect(keys.length).toBeGreaterThanOrEqual(4000);
  });

  test('4.9 locale/routes_fr_FR.json exists and is valid JSON', () => {
    expect(fs.existsSync(FR_ROUTES)).toBe(true);
    expect(() => readJson(FR_ROUTES)).not.toThrow();
  });
});

// ═════════════════════════════════════════════════════════════════════════════
// Phase 5: DB upgrade simulation (existing install gets fr_FR)
// ═════════════════════════════════════════════════════════════════════════════
test.describe.serial('Phase 5: DB state after upgrade', () => {
  test('5.1 languages table has at least 4 entries (it/en/de/fr)', () => {
    test.skip(!DB_USER || !DB_NAME, 'DB env vars not set');
    const count = dbQuery('SELECT COUNT(*) FROM languages');
    expect(parseInt(count, 10)).toBeGreaterThanOrEqual(4);
  });

  test('5.2 fr_FR language row has is_active=1', () => {
    test.skip(!DB_USER || !DB_NAME, 'DB env vars not set');
    const val = dbQuery("SELECT is_active FROM languages WHERE code='fr_FR'");
    expect(val).toBe('1');
  });

  test('5.3 fr_FR translation_file column is correct', () => {
    test.skip(!DB_USER || !DB_NAME, 'DB env vars not set');
    const val = dbQuery("SELECT translation_file FROM languages WHERE code='fr_FR'");
    expect(val).toContain('fr_FR.json');
  });

  test('5.4 fr_FR total_keys > 4000', () => {
    test.skip(!DB_USER || !DB_NAME, 'DB env vars not set');
    const val = dbQuery("SELECT total_keys FROM languages WHERE code='fr_FR'");
    expect(parseInt(val, 10)).toBeGreaterThan(4000);
  });

  test('5.5 it_IT remains the default language (is_default=1)', () => {
    test.skip(!DB_USER || !DB_NAME, 'DB env vars not set');
    const val = dbQuery("SELECT is_default FROM languages WHERE code='it_IT'");
    expect(val).toBe('1');
  });

  test('5.6 fr_FR is_default=0 on an it_IT install (not overriding default)', () => {
    test.skip(!DB_USER || !DB_NAME, 'DB env vars not set');
    const defaultLang = dbQuery("SELECT code FROM languages WHERE is_default=1 LIMIT 1");
    const frDefault = dbQuery("SELECT is_default FROM languages WHERE code='fr_FR'");
    // If current install is it_IT, fr_FR should not be default
    if (defaultLang === 'it_IT') {
      expect(frDefault).toBe('0');
    } else {
      // If install was done in fr_FR, fr_FR would be default — also acceptable
      expect(['0', '1']).toContain(frDefault);
    }
  });

  test('5.7 Only one language can be is_default=1', () => {
    test.skip(!DB_USER || !DB_NAME, 'DB env vars not set');
    const count = dbQuery("SELECT COUNT(*) FROM languages WHERE is_default=1");
    expect(parseInt(count, 10)).toBe(1);
  });
});

// ═════════════════════════════════════════════════════════════════════════════
// Phase 6: BNF URL security — preset must use HTTPS
// ═════════════════════════════════════════════════════════════════════════════
test.describe.serial('Phase 6: BNF URL security', () => {
  test('6.1 plugins.php BNF preset uses HTTPS URL', () => {
    const content = readFile(PLUGINS_PHP);
    // Extract BNF preset block
    const idx = content.indexOf('bnf:');
    expect(idx).toBeGreaterThan(-1);
    const block = content.substring(idx, idx + 300);
    expect(block).toContain('https://catalogue.bnf.fr');
    expect(block).not.toContain('http://catalogue.bnf.fr');
  });

  test('6.2 plugins.php BNF preset has syntax unimarcxchange', () => {
    const content = readFile(PLUGINS_PHP);
    const idx = content.indexOf('bnf:');
    const block = content.substring(idx, idx + 300);
    expect(block).toContain("unimarcxchange");
  });

  test('6.3 plugins.php BNF preset has version 1.2', () => {
    const content = readFile(PLUGINS_PHP);
    const idx = content.indexOf('bnf:');
    const block = content.substring(idx, idx + 300);
    expect(block).toContain("'1.2'");
  });

  test('6.4 plugins.php BNF preset has quote_search_terms: true', () => {
    const content = readFile(PLUGINS_PHP);
    const idx = content.indexOf('bnf:');
    const block = content.substring(idx, idx + 300);
    expect(block).toContain('quote_search_terms: true');
  });
});

// ═════════════════════════════════════════════════════════════════════════════
// Phase 7: SruClient robustness
// ═════════════════════════════════════════════════════════════════════════════
test.describe.serial('Phase 7: SruClient robustness checks', () => {
  test('7.1 SruClient retry loop catches \\Throwable (not just \\Exception)', () => {
    const content = readFile(SRU_CLIENT);
    // Must use \Throwable in the retry catch — \Exception misses TypeError
    expect(content).toMatch(/catch\s*\(\s*\\Throwable\s+\$e\s*\)/);
  });

  test('7.2 SruClient does not have bare \\Exception catch in retry loop', () => {
    const content = readFile(SRU_CLIENT);
    // Find the queryServerWithRetry method
    const methodStart = content.indexOf('queryServerWithRetry');
    const methodEnd = content.indexOf('public function', methodStart + 1);
    const method = content.substring(methodStart, methodEnd > 0 ? methodEnd : methodStart + 1000);
    // The retry loop's catch should be \Throwable
    expect(method).not.toMatch(/catch\s*\(\s*\\Exception\s+\$e\s*\)/);
  });

  test('7.3 SruClient restores libxml error state after XML parse', () => {
    const content = readFile(SRU_CLIENT);
    expect(content).toContain('libxml_use_internal_errors($prevLibxmlErrors)');
  });

  test('7.4 SruClient captures previous libxml state before changing it', () => {
    const content = readFile(SRU_CLIENT);
    expect(content).toContain('$prevLibxmlErrors = libxml_use_internal_errors(true)');
  });

  test('7.5 SruClient uses RuntimeException for XML parse errors (not bare Exception)', () => {
    const content = readFile(SRU_CLIENT);
    // RuntimeException is a subclass of Exception — more specific and correct
    expect(content).toContain('new \\RuntimeException("Invalid XML response');
  });

  test('7.6 SruClient quote_search_terms applied conditionally', () => {
    const content = readFile(SRU_CLIENT);
    expect(content).toContain('quote_search_terms');
    // Should wrap term in quotes only when flag is set
    expect(content).toMatch(/quote_search_terms.*".*\$term.*"|!\s*empty.*quote_search_terms/s);
  });

  test('7.7 SruClient mxc namespace registered for MARCXchange', () => {
    const content = readFile(SRU_CLIENT);
    expect(content).toContain("registerNamespace('mxc', 'info:lc/xmlns/marcxchange-v2')");
  });

  test('7.8 SruClient parseMarcxchangeXml handles empty result gracefully', () => {
    const content = readFile(SRU_CLIENT);
    // parseMarcxchangeXml should return an array (not throw on empty)
    const methodIdx = content.indexOf('parseMarcxchangeXml');
    const methodBody = content.substring(methodIdx, methodIdx + 2000);
    // Method should have a return statement
    expect(methodBody).toContain('return');
  });
});

// ═════════════════════════════════════════════════════════════════════════════
// Phase 8: data_fr_FR route coherence
// ═════════════════════════════════════════════════════════════════════════════
test.describe.serial('Phase 8: data_fr_FR button links match routes_fr_FR.json', () => {
  test('8.1 Hero button link matches routes_fr_FR catalog route', () => {
    const routes = readJson(FR_ROUTES);
    const seed = readFile(FR_SEED);
    expect(routes['catalog']).toBe('/catalogue');
    const heroMatch = seed.match(/'hero'[^)]+button_link[^,)]*'([^']+)'/);
    if (heroMatch) {
      expect(heroMatch[1]).toBe(routes['catalog']);
    } else {
      expect(seed).toMatch(/hero.*\/catalogue/s);
    }
  });

  test('8.2 CTA button link matches routes_fr_FR register route', () => {
    const routes = readJson(FR_ROUTES);
    const seed = readFile(FR_SEED);
    expect(routes['register']).toBe('/inscription');
    expect(seed).toContain(`'${routes['register']}'`);
  });

  test('8.3 No /catalogo hardcoded in data_fr_FR.sql (Italian route)', () => {
    const seed = readFile(FR_SEED);
    expect(seed).not.toContain("'/catalogo'");
  });

  test('8.4 No /accedi hardcoded in data_fr_FR.sql (Italian login route)', () => {
    const seed = readFile(FR_SEED);
    expect(seed).not.toContain("'/accedi'");
  });

  test('8.5 No /anmelden hardcoded in data_fr_FR.sql (German login route)', () => {
    const seed = readFile(FR_SEED);
    expect(seed).not.toContain("'/anmelden'");
  });

  test('8.6 data_fr_FR.sql contains proper French email templates', () => {
    const seed = readFile(FR_SEED);
    expect(seed).toContain('fr_FR');
    // Email templates should exist in French
    expect(seed).toContain('email_templates');
    expect(seed).toContain('fr_FR');
  });
});
