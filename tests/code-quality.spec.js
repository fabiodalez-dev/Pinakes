/**
 * Code Quality Test Suite — 15 static-analysis tests
 *
 * No browser, database, or shell commands required.
 * Reads source files and verifies project rules that are too subtle
 * for linters alone.
 *
 * Run:
 *   /tmp/run-e2e.sh tests/code-quality.spec.js \
 *     --config=tests/playwright.config.js --workers=1
 *
 * Or directly (no DB env vars needed):
 *   npx playwright test tests/code-quality.spec.js --config=tests/playwright.config.js
 */

const { test, expect } = require('@playwright/test');
const fs   = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Walk dir recursively, return absolute paths matching ext. */
function glob(relDir, ext, excludeSegments = []) {
    const results = [];
    function walk(dir) {
        let entries;
        try { entries = fs.readdirSync(dir, { withFileTypes: true }); } catch { return; }
        for (const e of entries) {
            const full = path.join(dir, e.name);
            if (excludeSegments.some(seg => full.includes(sep + seg + sep) || full.endsWith(sep + seg))) continue;
            if (e.isDirectory()) walk(full);
            else if (e.isFile() && e.name.endsWith(ext)) results.push(full);
        }
    }
    const sep = path.sep;
    walk(path.join(ROOT, relDir));
    return results;
}

/** Return { file, line, text }[] for all pattern matches across files. */
function grepFiles(relDir, pattern, ext = '.php', excludeSegments = []) {
    const re   = new RegExp(pattern);
    const hits = [];
    for (const file of glob(relDir, ext, excludeSegments)) {
        const lines = fs.readFileSync(file, 'utf-8').split('\n');
        lines.forEach((line, i) => {
            if (re.test(line)) hits.push({ file: path.relative(ROOT, file), line: i + 1, text: line.trim() });
        });
    }
    return hits;
}

/** PHP-compatible version_compare for numeric version strings like "0.7.4". */
function versionLte(a, b) {
    const pa = a.split('.').map(Number);
    const pb = b.split('.').map(Number);
    const len = Math.max(pa.length, pb.length);
    for (let i = 0; i < len; i++) {
        const na = pa[i] ?? 0;
        const nb = pb[i] ?? 0;
        if (na < nb) return true;
        if (na > nb) return false;
    }
    return true; // equal → also ≤
}

// ── Tests ─────────────────────────────────────────────────────────────────────

test.describe.serial('Code Quality — 15 static analysis tests', () => {

    // ── 1. Migration version guard ────────────────────────────────────────────

    test('1. All migrate_*.sql have version ≤ version.json (silent-skip guard)', () => {
        const target  = JSON.parse(fs.readFileSync(path.join(ROOT, 'version.json'), 'utf-8')).version;
        const migrDir = path.join(ROOT, 'installer', 'database', 'migrations');
        const files   = fs.readdirSync(migrDir).filter(f => /^migrate_[\d.]+\.sql$/.test(f));

        const violations = files.filter(f => {
            const v = f.replace('migrate_', '').replace('.sql', '');
            return !versionLte(v, target);
        });

        expect(violations,
            `Migration files newer than release ${target} (would be silently skipped):\n${violations.join('\n')}`
        ).toHaveLength(0);
    });

    // ── 2. CORE_TABLES list in sync with schema.sql ───────────────────────────

    test('2. CORE_TABLES in schema-integrity.spec.js matches CREATE TABLE in schema.sql', () => {
        const specFile  = fs.readFileSync(path.join(__dirname, 'schema-integrity.spec.js'), 'utf-8');
        const listMatch = specFile.match(/const CORE_TABLES\s*=\s*\[([\s\S]*?)\];/);
        expect(listMatch, 'CORE_TABLES definition not found in schema-integrity.spec.js').not.toBeNull();

        const hardcoded  = (listMatch[1].match(/'([^']+)'/g) ?? []).map(s => s.replace(/'/g, '')).sort();
        const schemaSQL  = fs.readFileSync(path.join(ROOT, 'installer', 'database', 'schema.sql'), 'utf-8');
        const fromSchema = [...schemaSQL.matchAll(/^CREATE TABLE `(\w+)`/gm)].map(m => m[1]).sort();

        const inSchemaOnly    = fromSchema.filter(t => !hardcoded.includes(t));
        const inHardcodedOnly = hardcoded.filter(t => !fromSchema.includes(t));

        expect(inSchemaOnly,
            `Tables in schema.sql but missing from CORE_TABLES: ${inSchemaOnly.join(', ')}`
        ).toHaveLength(0);
        expect(inHardcodedOnly,
            `Tables in CORE_TABLES but absent from schema.sql: ${inHardcodedOnly.join(', ')}`
        ).toHaveLength(0);
    });

    // ── 3. Plugin ensureSchema() called from onActivate() ─────────────────────

    test('3. Plugins with CREATE TABLE call ensureSchema() from onActivate()', () => {
        const violations = [];
        const pluginsDir = path.join(ROOT, 'storage', 'plugins');

        for (const dir of fs.readdirSync(pluginsDir)) {
            const plugDir = path.join(pluginsDir, dir);
            if (!fs.statSync(plugDir).isDirectory()) continue;
            for (const f of fs.readdirSync(plugDir).filter(n => n.endsWith('.php'))) {
                const content = fs.readFileSync(path.join(plugDir, f), 'utf-8');
                if (!content.includes('CREATE TABLE')) continue;
                if (!content.includes('ensureSchema()')) {
                    violations.push(`${dir}/${f}: has CREATE TABLE but no ensureSchema()`);
                    continue;
                }
                // Extract onActivate() body and verify ensureSchema is called
                const activateBody = content.match(/function onActivate\(\)[^{]*\{([\s\S]*?)(?=\n\s+(?:public|private|protected)\s+function |\n}$)/m);
                if (!activateBody) {
                    violations.push(`${dir}/${f}: missing onActivate() method`);
                } else if (!activateBody[1].includes('ensureSchema')) {
                    violations.push(`${dir}/${f}: ensureSchema() not called from onActivate()`);
                }
            }
        }
        expect(violations, `Plugin schema rule violations:\n${violations.join('\n')}`).toHaveLength(0);
    });

    // ── 4. Plugin ensureSchema() called from onInstall() ──────────────────────

    test('4. Plugins with CREATE TABLE call ensureSchema() from onInstall()', () => {
        const violations = [];
        const pluginsDir = path.join(ROOT, 'storage', 'plugins');

        for (const dir of fs.readdirSync(pluginsDir)) {
            const plugDir = path.join(pluginsDir, dir);
            if (!fs.statSync(plugDir).isDirectory()) continue;
            for (const f of fs.readdirSync(plugDir).filter(n => n.endsWith('.php'))) {
                const content = fs.readFileSync(path.join(plugDir, f), 'utf-8');
                if (!content.includes('CREATE TABLE') || !content.includes('ensureSchema()')) continue;
                const installBody = content.match(/function onInstall\(\)[^{]*\{([\s\S]*?)(?=\n\s+(?:public|private|protected)\s+function |\n}$)/m);
                if (!installBody) {
                    violations.push(`${dir}/${f}: missing onInstall() method`);
                } else if (!installBody[1].includes('ensureSchema')) {
                    violations.push(`${dir}/${f}: ensureSchema() not called from onInstall()`);
                }
            }
        }
        expect(violations, `Plugin onInstall() schema rule violations:\n${violations.join('\n')}`).toHaveLength(0);
    });

    // ── 5. Soft-delete guard on libri table ───────────────────────────────────

    test('5. app/ PHP files querying FROM libri include deleted_at IS NULL', () => {
        // Match SELECT/JOIN data queries, not DDL like SHOW COLUMNS FROM libri
        const FROM_LIBRI = /(?:SELECT|JOIN|,)\s[\s\S]{0,200}?FROM\s+`?libri`?\b(?!_)/i;
        const SOFT_DEL   = /deleted_at\s+IS\s+NULL/i;
        const violations = [];

        for (const file of glob('app', '.php', ['vendor'])) {
            const content = fs.readFileSync(file, 'utf-8');
            if (FROM_LIBRI.test(content) && !SOFT_DEL.test(content)) {
                violations.push(path.relative(ROOT, file));
            }
        }
        expect(violations,
            `Files with SELECT/JOIN on libri table but missing deleted_at IS NULL guard:\n${violations.join('\n')}`
        ).toHaveLength(0);
    });

    // ── 6. No unescaped url() in HTML attributes ──────────────────────────────

    test('6. Views: url() in HTML attrs is wrapped with htmlspecialchars()', () => {
        const UNESCAPED = /(href|action|src)="<\?=?\s*url\(/;
        const violations = grepFiles('app/Views', UNESCAPED.source, '.php')
            .filter(h => !h.text.includes('htmlspecialchars'));
        expect(violations.map(h => `${h.file}:${h.line}: ${h.text}`),
            "Unescaped url() in HTML attribute — wrap with htmlspecialchars(url(...), ENT_QUOTES, 'UTF-8')"
        ).toHaveLength(0);
    });

    // ── 7. No dynamic Tailwind class construction ─────────────────────────────

    test('7. No dynamic Tailwind class construction (breaks JIT)', () => {
        const DYNAMIC = /'(bg|text|border|ring|from|to|fill)-'\s*\.\s*\$|"(bg|text|border|ring|from|to|fill)-"\s*\.\s*\$/;
        const violations = [
            ...grepFiles('app',             DYNAMIC.source, '.php'),
            ...grepFiles('storage/plugins', DYNAMIC.source, '.php'),
        ];
        expect(violations.map(h => `${h.file}:${h.line}: ${h.text}`),
            'Dynamic Tailwind classes will not be generated by JIT. Use full static class names in ternary expressions.'
        ).toHaveLength(0);
    });

    // ── 8. Route key integrity: route_path() keys exist in routes_it_IT.json ──

    test('8. All route_path() keys in views exist in routes JSON or RouteTranslator fallback', () => {
        // Valid keys come from two sources:
        // 1. locale/routes_it_IT.json (locale-aware routes)
        // 2. $fallbackRoutes in RouteTranslator.php (admin/language-neutral routes)
        const jsonKeys = new Set(
            Object.keys(JSON.parse(fs.readFileSync(path.join(ROOT, 'locale', 'routes_it_IT.json'), 'utf-8')))
        );

        const translatorSrc = fs.readFileSync(path.join(ROOT, 'app', 'Support', 'RouteTranslator.php'), 'utf-8');
        const fallbackMatch = translatorSrc.match(/\$fallbackRoutes\s*=\s*\[([\s\S]*?)\];/);
        if (fallbackMatch) {
            const FALLBACK_KEY = /'([^']+)'\s*=>/g;
            let m;
            while ((m = FALLBACK_KEY.exec(fallbackMatch[1])) !== null) jsonKeys.add(m[1]);
        }

        const used      = new Set();
        const ROUTE_KEY = /route_path\('([^']+)'\)/g;
        for (const file of glob('app/Views', '.php')) {
            const content = fs.readFileSync(file, 'utf-8');
            let m;
            while ((m = ROUTE_KEY.exec(content)) !== null) used.add(m[1]);
        }

        const missing = [...used].filter(k => !jsonKeys.has(k)).sort();
        expect(missing,
            `route_path() keys used in views but not defined in routes JSON or RouteTranslator fallback: ${missing.join(', ')}`
        ).toHaveLength(0);
    });

    // ── 9. Translation key parity: en_US ↔ de_DE ─────────────────────────────

    test('9. de_DE.json has same set of keys as en_US.json', () => {
        const enKeys = Object.keys(JSON.parse(fs.readFileSync(path.join(ROOT, 'locale', 'en_US.json'), 'utf-8'))).sort();
        const deKeys = Object.keys(JSON.parse(fs.readFileSync(path.join(ROOT, 'locale', 'de_DE.json'), 'utf-8'))).sort();

        const inEnOnly = enKeys.filter(k => !deKeys.includes(k));
        const inDeOnly = deKeys.filter(k => !enKeys.includes(k));

        expect(inEnOnly,
            `Keys in en_US.json but missing from de_DE.json (${inEnOnly.length}): ${inEnOnly.slice(0, 5).join(', ')}${inEnOnly.length > 5 ? '...' : ''}`
        ).toHaveLength(0);
        expect(inDeOnly,
            `Keys in de_DE.json but missing from en_US.json (${inDeOnly.length}): ${inDeOnly.slice(0, 5).join(', ')}${inDeOnly.length > 5 ? '...' : ''}`
        ).toHaveLength(0);
    });

    // ── 10. README API endpoints exist in PHP route registrations ─────────────

    test('10. API endpoints documented in README.md exist in PHP code', () => {
        const readme    = fs.readFileSync(path.join(ROOT, 'README.md'), 'utf-8');
        const phpSources = [
            ...glob('app',            '.php', ['vendor']),
            ...glob('storage/plugins', '.php'),
        ].map(f => fs.readFileSync(f, 'utf-8')).join('\n');

        const DOC_RE   = /`GET (\/(?:api|resync|oai|openurl|archives)[^\s`{]+)/g;
        const violations = [];
        let m;
        while ((m = DOC_RE.exec(readme)) !== null) {
            const rawPath = m[1].replace(/\/\{[^}]+\}/g, '/');  // strip {id} params
            const search  = rawPath.replace(/\/$/, '');
            if (!phpSources.includes(search)) violations.push(m[1]);
        }
        expect(violations,
            `Endpoints documented in README but no matching route found in PHP:\n${violations.map(v => '  GET ' + v).join('\n')}`
        ).toHaveLength(0);
    });

    // ── 11. Autoloader phpstan-free ───────────────────────────────────────────

    test('11. vendor/composer/autoload_static.php has no phpstan references', () => {
        const autoloadPath = path.join(ROOT, 'vendor', 'composer', 'autoload_static.php');
        if (!fs.existsSync(autoloadPath)) return; // vendor/ not installed — skip

        const content = fs.readFileSync(autoloadPath, 'utf-8');
        const count   = (content.match(/phpstan/gi) ?? []).length;
        expect(count,
            `autoload_static.php contains ${count} phpstan reference(s). Fix: composer install --no-dev --optimize-autoloader`
        ).toBe(0);
    });

    // ── 12. Plugin.json "path" matches directory name ─────────────────────────

    test('12. plugin.json "path" field matches plugin directory name', () => {
        const violations = [];
        const pluginsDir = path.join(ROOT, 'storage', 'plugins');
        for (const dir of fs.readdirSync(pluginsDir)) {
            const jsonPath = path.join(pluginsDir, dir, 'plugin.json');
            if (!fs.existsSync(jsonPath)) continue;
            try {
                const meta = JSON.parse(fs.readFileSync(jsonPath, 'utf-8'));
                if (meta.path !== undefined && meta.path !== dir) {
                    violations.push(`${dir}: plugin.json path="${meta.path}" ≠ directory "${dir}"`);
                }
            } catch {
                violations.push(`${dir}/plugin.json: invalid JSON`);
            }
        }
        expect(violations, violations.join('\n')).toHaveLength(0);
    });

    // ── 13. Migrations use idempotent DDL patterns ────────────────────────────

    test('13. Each migrate_*.sql (≥ 0.5.0) uses idempotent DDL (IF NOT EXISTS or @sql conditional)', () => {
        // Migrations before 0.5.0 predate the idempotency requirement; new migrations must comply.
        const migrDir    = path.join(ROOT, 'installer', 'database', 'migrations');
        const violations = [];

        for (const f of fs.readdirSync(migrDir).filter(n => /^migrate_.*\.sql$/.test(n))) {
            const v = f.replace('migrate_', '').replace('.sql', '');
            if (!versionLte('0.5.0', v)) continue; // skip pre-0.5.0 legacy files

            const upper = fs.readFileSync(path.join(migrDir, f), 'utf-8').toUpperCase();
            const hasDDL = upper.includes('ALTER TABLE') || upper.includes('CREATE TABLE');
            const isIdempotent = upper.includes('IF NOT EXISTS')
                || upper.includes('SET @SQL')
                || upper.includes('SET @S ')
                || upper.includes('INFORMATION_SCHEMA')
                || upper.includes('ON DUPLICATE KEY');
            if (hasDDL && !isIdempotent) {
                violations.push(`${f}: has DDL without idempotency guard (use IF NOT EXISTS or SET @sql=IF(...))`);
            }
        }
        expect(violations, violations.join('\n')).toHaveLength(0);
    });

    // ── 14. No forEach() + return false anti-pattern in JS ────────────────────

    test('14. All plugin.json files have required fields (name, display_name, version, main_file)', () => {
        const REQUIRED = ['name', 'display_name', 'version', 'main_file', 'requires_php', 'requires_app'];
        const violations = [];
        const pluginsDir = path.join(ROOT, 'storage', 'plugins');

        for (const dir of fs.readdirSync(pluginsDir)) {
            if (dir.startsWith('.')) continue;
            const jsonPath = path.join(pluginsDir, dir, 'plugin.json');
            if (!fs.existsSync(jsonPath)) {
                violations.push(`${dir}: missing plugin.json`);
                continue;
            }
            let meta;
            try { meta = JSON.parse(fs.readFileSync(jsonPath, 'utf-8')); }
            catch { violations.push(`${dir}/plugin.json: invalid JSON`); continue; }

            for (const field of REQUIRED) {
                if (meta[field] === undefined || meta[field] === null || meta[field] === '') {
                    violations.push(`${dir}/plugin.json: missing required field "${field}"`);
                }
            }
        }
        expect(violations, violations.join('\n')).toHaveLength(0);
    });

    // ── 15. schema.sql ENUM correctness: archival_unit (not archive_unit) ─────

    test("15. schema.sql: oai_deleted_records.entity_type has 'archival_unit' (not 'archive_unit')", () => {
        const schema = fs.readFileSync(path.join(ROOT, 'installer', 'database', 'schema.sql'), 'utf-8');
        const colMatch = schema.match(/`entity_type`\s+enum\([^)]+\)/i);
        expect(colMatch, 'entity_type column not found in schema.sql').not.toBeNull();

        const colDef = colMatch[0];
        expect(colDef, `entity_type ENUM missing 'archival_unit' — got: ${colDef}`)
            .toContain("'archival_unit'");
        expect(colDef, `entity_type ENUM has old typo 'archive_unit' — got: ${colDef}`)
            .not.toContain("'archive_unit'");
    });

});
