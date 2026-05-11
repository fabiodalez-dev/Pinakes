// @ts-check
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

const INTEROP_PLUGINS = [
  'bibframe-linked-data',
  'ncip-server',
  'oai-pmh-server',
  'openurl-resolver',
  'resource-sync',
  'viaf-authority',
];

function mysqlArgs(sql, batch = false) {
  const args = [];
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME);
  if (batch) args.push('-N', '-B');
  args.push('-e', sql);
  return args;
}

const MYSQL_ENV = () => ({ ...process.env, MYSQL_PWD: DB_PASS });

function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql, true), {
    encoding: 'utf-8',
    timeout: 10000,
    env: MYSQL_ENV(),
  }).trim();
}

function dbExec(sql) {
  execFileSync('mysql', mysqlArgs(sql), {
    encoding: 'utf-8',
    timeout: 10000,
    env: MYSQL_ENV(),
  });
}

test.skip(!DB_USER || !DB_NAME, 'Missing E2E env (DB_*)');

test('interop preflight: bundled linked-data plugins are active before interop suites', () => {
  const quoted = INTEROP_PLUGINS.map((name) => `'${name}'`).join(',');
  dbExec(`UPDATE plugins SET is_active = 1 WHERE name IN (${quoted})`);

  const rows = dbQuery(
    `SELECT CONCAT(name, '=', is_active)
       FROM plugins
      WHERE name IN (${quoted})
      ORDER BY name`
  ).split('\n').filter(Boolean);

  expect(rows).toHaveLength(INTEROP_PLUGINS.length);
  for (const row of rows) {
    expect(row).toMatch(/=1$/);
  }
});
