// @ts-check
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..', '..');

// Require explicit E2E_* env vars only — never fall back to .env or APP_URL
// These fixtures create/delete data and must not target the real app environment
const BASE_URL = process.env.E2E_BASE_URL;
if (!BASE_URL) {
  throw new Error('E2E_BASE_URL must be set (use /tmp/run-e2e.sh)');
}
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS ?? '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
if (!DB_USER || !DB_NAME || (!DB_HOST && !DB_SOCKET)) {
  throw new Error('Missing E2E_DB_* configuration (use /tmp/run-e2e.sh)');
}

function loadDotEnv(filePath) {
  if (!fs.existsSync(filePath)) {
    return {};
  }

  const env = {};
  for (const rawLine of fs.readFileSync(filePath, 'utf8').split(/\r?\n/)) {
    const line = rawLine.trim();
    if (!line || line.startsWith('#')) {
      continue;
    }
    const eqIndex = line.indexOf('=');
    if (eqIndex === -1) {
      continue;
    }
    const key = line.slice(0, eqIndex).trim();
    let value = line.slice(eqIndex + 1).trim();
    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }
    env[key] = value;
  }
  return env;
}

function mysqlArgs(sql) {
  const args = ['-N', '-B', '-e', sql];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER);
  if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
  args.push(DB_NAME);
  return args;
}

function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql), {
    encoding: 'utf8',
    timeout: 10000,
    cwd: ROOT,
  }).trim();
}

function dbExec(sql) {
  execFileSync('mysql', mysqlArgs(sql), {
    encoding: 'utf8',
    timeout: 10000,
    cwd: ROOT,
  });
}

function escapeSql(value) {
  return String(value)
    .replace(/\\/g, '\\\\')
    .replace(/'/g, "\\'");
}

function phpHash(password) {
  return execFileSync('php', [
    '-r',
    `echo password_hash(${JSON.stringify(password)}, PASSWORD_DEFAULT);`,
  ], {
    encoding: 'utf8',
    timeout: 5000,
    cwd: ROOT,
  }).trim();
}

function getPluginIdByName(name) {
  const result = dbQuery(
    `SELECT id FROM plugins WHERE name = '${escapeSql(name)}' LIMIT 1`
  );
  const id = Number(result);
  if (!Number.isInteger(id) || id <= 0) {
    throw new Error(`Plugin not found: ${name}`);
  }
  return id;
}

function snapshotPluginSettings(pluginId) {
  const output = dbQuery(`
    SELECT JSON_OBJECT(
      'setting_key', setting_key,
      'setting_value', setting_value,
      'autoload', autoload
    )
    FROM plugin_settings
    WHERE plugin_id = ${pluginId}
    ORDER BY setting_key
  `);

  if (!output) {
    return [];
  }

  return output
    .split('\n')
    .filter(Boolean)
    .map((line) => JSON.parse(line));
}

function restorePluginSettings(pluginId, rows) {
  const id = Number(pluginId);
  const statements = [`DELETE FROM plugin_settings WHERE plugin_id = ${id}`];
  for (const row of rows) {
    statements.push(`
      INSERT INTO plugin_settings (plugin_id, setting_key, setting_value, autoload, created_at)
      VALUES (
        ${id},
        '${escapeSql(row.setting_key)}',
        ${row.setting_value === null ? 'NULL' : "'" + escapeSql(row.setting_value) + "'"},
        ${Number(row.autoload) ? 1 : 0},
        NOW()
      )
    `);
  }
  // Atomic: all statements in a single mysql call
  dbExec(`START TRANSACTION; ${statements.join('; ')}; COMMIT`);
}

function createTempAdminUser(locale = 'it_IT') {
  const runId = `codex_${Date.now()}_${Math.random().toString(16).slice(2, 8)}`;
  const email = `${runId}@test.local`;
  const password = 'Test1234!Aa';
  const hash = phpHash(password);
  const card = `CDX${String(Date.now()).slice(-10)}`;

  dbExec(`
    INSERT INTO utenti (
      codice_tessera, nome, cognome, email, password,
      privacy_accettata, data_accettazione_privacy,
      tipo_utente, stato, email_verificata, locale
    ) VALUES (
      '${escapeSql(card)}',
      'Codex',
      'Tester',
      '${escapeSql(email)}',
      '${escapeSql(hash)}',
      1,
      NOW(),
      'admin',
      'attivo',
      1,
      '${escapeSql(locale)}'
    )
  `);

  const id = Number(dbQuery(
    `SELECT id FROM utenti WHERE email = '${escapeSql(email)}' LIMIT 1`
  ));
  if (!Number.isInteger(id) || id <= 0) {
    throw new Error(`createTempAdminUser: failed to resolve user id for ${email}`);
  }

  return { id, email, password, locale };
}

function createTempBook(titlePrefix = 'GoodLib Test') {
  const title = `${titlePrefix} ${Date.now()}`;
  dbExec(`
    INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at)
    VALUES ('${escapeSql(title)}', 1, 1, NOW(), NOW())
  `);
  const id = Number(dbQuery(
    `SELECT id FROM libri WHERE titolo = '${escapeSql(title)}' ORDER BY id DESC LIMIT 1`
  ));
  if (!Number.isInteger(id) || id <= 0) {
    throw new Error(`createTempBook: failed to resolve book id for "${title}"`);
  }
  return { id, title };
}

function deleteTempAdminUser(userId) {
  if (!userId) return;
  const id = Number(userId);
  // Delete non-cascading dependents first
  dbExec(`DELETE FROM prenotazioni WHERE utente_id = ${id}`);
  dbExec(`DELETE FROM prestiti WHERE utente_id = ${id}`);
  dbExec(`DELETE FROM user_sessions WHERE utente_id = ${id}`);
  dbExec(`DELETE FROM utenti WHERE id = ${id}`);
}

function deleteTempBook(bookId) {
  if (!bookId) return;
  const id = Number(bookId);
  // Delete non-cascading dependents first
  dbExec(`DELETE FROM prenotazioni WHERE libro_id = ${id}`);
  dbExec(`DELETE FROM prestiti WHERE libro_id = ${id}`);
  dbExec(`DELETE FROM copie WHERE libro_id = ${id}`);
  dbExec(`DELETE FROM libri WHERE id = ${id}`);
}

module.exports = {
  BASE_URL,
  createTempAdminUser,
  createTempBook,
  dbExec,
  deleteTempAdminUser,
  deleteTempBook,
  getPluginIdByName,
  restorePluginSettings,
  snapshotPluginSettings,
};
