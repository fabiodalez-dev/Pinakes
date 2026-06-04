// @ts-check
/**
 * Static consistency checks for the final feat/fr-bnf-integration merge pass.
 *
 * These tests intentionally target the small integration/review changes that
 * are easy to lose in a large branch merge: CI install mode, language parity,
 * translated LibraryThing labels, robust Playwright helpers, and migration
 * coverage references.
 */

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');

function read(relPath) {
  return fs.readFileSync(path.join(ROOT, relPath), 'utf-8');
}

function readJson(relPath) {
  return JSON.parse(read(relPath));
}

test.describe('fr-bnf merge consistency', () => {
  test('working tree and branch diff do not contain whitespace errors', () => {
    expect(() => execFileSync('git', ['diff', '--check'], { cwd: ROOT, encoding: 'utf-8' }))
      .not.toThrow();
    expect(() => execFileSync('git', ['diff', '--check', 'origin/main'], { cwd: ROOT, encoding: 'utf-8' }))
      .not.toThrow();
  });

  test('CI workflows use branch-compatible dependency and audit commands', () => {
    const e2e = read('.github/workflows/ci-e2e.yml');
    expect(e2e).toContain('npm install --silent');
    expect(e2e).not.toContain('cache: npm');

    const quality = read('.github/workflows/ci-quality.yml');
    expect(quality).toContain('composer audit --no-dev --abandoned=ignore');
  });

  test('book form preserves browser-validation bypass and translates LibraryThing field labels', () => {
    const form = read('app/Views/libri/partials/book_form.php');
    expect(form).toContain('<form id="bookForm" novalidate');
    expect(form).toContain("HtmlHelper::e(__($ltFields[$fieldName]))");
  });

  test('locale files remain valid and critical French LibraryThing keys are translated', () => {
    for (const code of ['it_IT', 'en_US', 'de_DE', 'fr_FR']) {
      expect(() => readJson(`locale/${code}.json`), `${code} must be valid JSON`).not.toThrow();
    }

    const enKeys = Object.keys(readJson('locale/en_US.json')).sort();
    const deKeys = Object.keys(readJson('locale/de_DE.json')).sort();
    expect(deKeys, 'de_DE keys differ from en_US').toEqual(enKeys);

    const fr = readJson('locale/fr_FR.json');
    expect(fr["Campi estesi per l'integrazione con LibraryThing"])
      .toBe("Champs étendus pour l'intégration avec LibraryThing");
    expect(fr['Recensione e Valutazione']).toBe('Avis et Évaluation');
    expect(fr['Come Nuovo']).toBe('Comme Neuf');
  });

  test('archive plugin metadata is strict JSON and exposes references without trailing object comma', () => {
    const raw = read('storage/plugins/archives/plugin.json');
    const parsed = JSON.parse(raw);
    expect(parsed.name).toBe('archives');
    expect(parsed.metadata.references).toEqual(expect.arrayContaining([
      expect.stringContaining('isadg'),
      expect.stringContaining('isaar-cpf'),
    ]));
    expect(raw).not.toMatch(/,\s*\n\s*}\s*\n\s*}$/);
  });

  test('archive fixture stubs are created atomically and only ignore EEXIST', () => {
    const spec = read('tests/archives-pr-extended.spec.js');
    expect(spec).toContain("fs.writeFileSync(absPath, Buffer.from('stub'), { flag: 'wx' })");
    expect(spec).toContain("if (!err || err.code !== 'EEXIST') throw err");
  });

  test('full E2E login helper only treats admin redirect as successful', () => {
    const spec = read('tests/full-test.spec.js');
    expect(spec).toContain("url.toString().includes('/admin')");
    expect(spec).toContain('not redirected to admin area after 2 attempts');
    const helper = spec.slice(spec.indexOf('async function loginAsAdmin'), spec.indexOf('/** Dismiss a visible SweetAlert popup. */'));
    expect(helper).not.toContain("!url.toString().includes('/accedi')");
  });

  test('interop coverage checks the 0.7.4 NCIP schema fallback migration', () => {
    const spec = read('tests/interop-document-coverage.spec.js');
    expect(spec).toContain("read('installer/database/migrations/migrate_0.7.04.sql')");
    expect(spec).toContain('ncip_partners');
    expect(spec).toContain('ncip_request_id');
    expect(spec).not.toContain("read('installer/database/migrations/migrate_0.7.3.sql')");
  });
});
