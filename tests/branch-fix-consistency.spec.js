// @ts-check
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

const HARNESS = path.join(__dirname, 'helpers', 'branch-fix-harness.php');
const BASE_URL = process.env.E2E_BASE_URL || process.env.APP_URL || 'http://localhost:8081';

function runScenario(name) {
  let output = '';
  try {
    output = execFileSync('php', [HARNESS, name], {
      cwd: path.resolve(__dirname, '..'),
      encoding: 'utf-8',
      env: {
        ...process.env,
        BASE_URL,
      },
      timeout: 30000,
    }).trim();
  } catch (error) {
    output = String(error.stdout || '').trim() || String(error.stderr || '').trim();
    if (!output) {
      throw error;
    }
  }

  return JSON.parse(output);
}

test.describe('Branch Fix Consistency', () => {
  test('collana rename rolls back when target already exists', () => {
    const result = runScenario('collana-rename-rollback');
    expect(result.ok, JSON.stringify(result)).toBe(true);
    expect(result.bookCollana).toBe(result.source);
    expect(result.sourceExists).toBe(1);
    expect(result.targetExists).toBe(1);
  });

  test('librarything import normalizes and persists translator, series, descrizione_plain and issn', () => {
    const result = runScenario('librarything-parse-and-persist');
    expect(result.ok, JSON.stringify(result)).toBe(true);
    expect(result.parsed.autori).toBe('George Orwell');
    expect(result.parsed.traduttore).toBe('Barbara Pym');
    expect(result.parsed.descrizione_plain).toBe('Alpha & Omega');
    expect(result.parsed.collana).toBe('Branch Saga');
    expect(result.parsed.numero_serie).toBe('7');
    expect(result.parsed.issn).toBe('1234-567X');
    expect(result.created.issn).toBe('1234-567X');
    expect(result.updated.collana).toBe('Branch Saga Updated');
    expect(result.softDeleted.descrizione_plain).toBe('before soft delete');
  });

  test('librarything export keeps translator roundtrip-compatible', () => {
    const result = runScenario('librarything-export-translator-roundtrip');
    expect(result.ok, JSON.stringify(result)).toBe(true);
    expect(result.row.secondaryAuthor).toBe('Barbara Pym');
    expect(result.row.secondaryAuthorRoles).toBe('Translator');
    expect(result.parsed.autori).toBe('George Orwell');
    expect(result.parsed.traduttore).toBe('Barbara Pym');
  });

  test('librarything keeps secondary author roles paired during roundtrip', () => {
    const result = runScenario('librarything-secondary-author-role-pairing');
    expect(result.ok, JSON.stringify(result)).toBe(true);
    expect(result.row.secondaryAuthor).toBe('Jane Austen; Barbara Pym');
    expect(result.row.secondaryAuthorRoles).toBe('; Translator');
    expect(result.parsed.autori).toBe('George Orwell|Jane Austen');
    expect(result.parsed.traduttore).toBe('Barbara Pym');
  });

  test('admin and frontend search use descrizione_plain', () => {
    const result = runScenario('descrizione-plain-search');
    expect(result.ok, JSON.stringify(result)).toBe(true);
    expect(result.apiTitles).toContain(result.title);
    expect(result.catalogFound).toBe(true);
  });

  test('public api and frontend detail expose issn with schema identifier', () => {
    const result = runScenario('public-api-and-frontend-issn');
    expect(result.ok, JSON.stringify(result)).toBe(true);
    expect(result.apiIssn).toBe('1234-567X');
    expect(result.detailHasIssn).toBe(true);
    expect(result.detailHasSchemaIdentifier).toBe(true);
  });

  test('login applies user locale immediately', () => {
    const result = runScenario('auth-login-loads-locale');
    expect(result.ok, JSON.stringify(result)).toBe(true);
    expect(result.location).toBe('/admin/dashboard');
    expect(result.sessionLocale).toBe('en_US');
    expect(result.currentLocale).toBe('en_US');
  });

  test('profile locale update persists and updates session locale', () => {
    const result = runScenario('profile-locale-update');
    expect(result.ok, JSON.stringify(result)).toBe(true);
    expect(result.dbLocale).toBe('it_IT');
    expect(result.sessionLocale).toBe('it_IT');
    expect(result.currentLocale).toBe('it_IT');
  });

  test('profile update without locale keeps the stored locale intact', () => {
    const result = runScenario('profile-locale-omitted-keeps-value');
    expect(result.ok, JSON.stringify(result)).toBe(true);
    expect(result.dbLocale).toBe('en_US');
    expect(result.sessionLocale).toBe('en_US');
    expect(result.currentLocale).toBe('en_US');
  });

  test('remember-me middleware restores user locale on auto-login', () => {
    const result = runScenario('remember-me-loads-locale');
    expect(result.ok, JSON.stringify(result)).toBe(true);
    expect(result.sessionLocale).toBe('de_DE');
    expect(result.currentLocale).toBe('de_DE');
  });
});
