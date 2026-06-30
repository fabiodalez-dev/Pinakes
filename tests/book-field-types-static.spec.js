// @ts-check

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');

function read(relPath) {
  return fs.readFileSync(path.join(ROOT, relPath), 'utf-8');
}

test.describe('book field-type consistency', () => {
  test('free-text acquisition and derived availability stay coherent across schema, repository and form', () => {
    const schema = read('installer/database/schema.sql');
    const migration = read('installer/database/migrations/migrate_0.7.25.sql');
    const repository = read('app/Models/BookRepository.php');
    const form = read('app/Views/libri/partials/book_form.php');

    expect(schema).toContain('`tipo_acquisizione` varchar(50)');
    expect(migration).toContain('MODIFY COLUMN `tipo_acquisizione` VARCHAR(50)');
    expect(repository).toContain('private function sanitizeAcquisitionType(mixed $value): string');
    expect(repository).toContain('private function normalizeEnumValue(mixed $value, string $column, string $default): string');

    expect(schema).toContain("'non_disponibile'");
    expect(migration).toContain("'non_disponibile'");
    expect(form).toContain('value="non_disponibile"');
    expect(form).toContain('$statoCorrente === \'non_disponibile\'');
  });
});
