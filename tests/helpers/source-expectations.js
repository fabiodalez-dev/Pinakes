const fs = require('fs');
const path = require('path');

const DEFAULT_ROOT = path.resolve(__dirname, '..', '..');

/**
 * Return every table declared by a CREATE TABLE statement.
 *
 * MySQL accepts both quoted and unquoted identifiers; schema.sql intentionally
 * contains both forms. Refuse partial parses so a new SQL spelling cannot turn
 * into a silently stale expectation list.
 *
 * @param {string} sql
 * @returns {string[]}
 */
function parseCreateTableNames(sql) {
    const statementCount = (sql.match(/^\s*CREATE\s+TABLE\b/gim) ?? []).length;
    const names = [...sql.matchAll(
        /^\s*CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+(?:`([^`]+)`|([A-Za-z_][A-Za-z0-9_$]*))/gim
    )].map(match => match[1] || match[2]);

    if (statementCount === 0) {
        throw new Error('No CREATE TABLE statements found');
    }
    if (names.length !== statementCount) {
        throw new Error(`Parsed ${names.length} table names from ${statementCount} CREATE TABLE statements`);
    }

    const unique = [...new Set(names)];
    if (unique.length !== names.length) {
        const duplicates = names.filter((name, index) => names.indexOf(name) !== index);
        throw new Error(`Duplicate CREATE TABLE declarations: ${[...new Set(duplicates)].join(', ')}`);
    }

    return unique.sort();
}

/** @returns {string[]|null} */
function readCoreTablesFromSchema(root = DEFAULT_ROOT) {
    let sql;
    try {
        sql = fs.readFileSync(path.join(root, 'installer', 'database', 'schema.sql'), 'utf-8');
    } catch {
        return null;
    }
    return parseCreateTableNames(sql);
}

/**
 * Parse the entries of App\Support\BundledPlugins::LIST.
 * Only array-entry lines are accepted, so quoted text in comments cannot be
 * mistaken for a plugin name.
 *
 * @returns {string[]}
 */
function parseBundledPluginNames(source) {
    const block = source.match(/const\s+LIST\s*=\s*\[([\s\S]*?)\]\s*;/);
    if (!block) throw new Error('BundledPlugins::LIST not found');

    // Validate EVERY meaningful line inside the block: a plugin entry that
    // doesn't match the quoted-string shape must fail loudly, never be silently
    // skipped — a dropped entry would make the derived list wrong while the
    // tests that consume it still pass.
    const plugins = [];
    for (const raw of block[1].split('\n')) {
        const line = raw.trim();
        if (line === '' || line.startsWith('//')) continue; // blank / comment-only
        const match = line.match(/^'([^']+)'\s*,?\s*(?:\/\/.*)?$/);
        if (!match) throw new Error(`BundledPlugins::LIST contains an unparseable entry: ${line}`);
        plugins.push(match[1]);
    }
    if (plugins.length === 0) throw new Error('BundledPlugins::LIST is empty');
    if (new Set(plugins).size !== plugins.length) {
        throw new Error('BundledPlugins::LIST contains duplicate entries');
    }
    return plugins.sort();
}

/** @returns {string[]|null} */
function readBundledPlugins(root = DEFAULT_ROOT) {
    let source;
    try {
        source = fs.readFileSync(path.join(root, 'app', 'Support', 'BundledPlugins.php'), 'utf-8');
    } catch {
        return null;
    }
    return parseBundledPluginNames(source);
}

module.exports = {
    parseCreateTableNames,
    parseBundledPluginNames,
    readCoreTablesFromSchema,
    readBundledPlugins,
};
