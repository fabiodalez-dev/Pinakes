// @ts-check

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');

function read(relPath) {
  return fs.readFileSync(path.join(ROOT, relPath), 'utf-8');
}

test.describe('book field-type consistency', () => {
  test('1) schema, migration and form preserve non_disponibile availability', () => {
    const schema = read('installer/database/schema.sql');
    const migration = read('installer/database/migrations/migrate_0.7.25-rc.1.sql');
    const form = read('app/Views/libri/partials/book_form.php');

    expect(schema).toContain('`tipo_acquisizione` varchar(50)');
    expect(migration).toContain('MODIFY COLUMN `tipo_acquisizione` VARCHAR(50)');
    expect(schema).toContain("'non_disponibile'");
    expect(migration).toContain("'non_disponibile'");
    expect(form).toContain('value="non_disponibile"');
    expect(form).toContain('$statoCorrente === \'non_disponibile\'');
  });

  test('2) BookRepository derives availability on create and never writes it on update', () => {
    const repository = read('app/Models/BookRepository.php');
    const updateBasicStart = repository.indexOf('public function updateBasic');
    const updateOptionalsStart = repository.indexOf('public function updateOptionals');
    expect(updateBasicStart).toBeGreaterThanOrEqual(0);
    expect(updateOptionalsStart).toBeGreaterThan(updateBasicStart);
    const updateBasic = repository.slice(updateBasicStart, updateOptionalsStart);

    expect(repository).toContain('private function sanitizeAcquisitionType(mixed $value): string');
    expect(repository).toContain('private function normalizeEnumValue(mixed $value, string $column, string $default): string');
    expect(repository).toContain('private function stringInput(mixed $value): string');
    expect(repository).toContain('$copie_disponibili = $copie_totali;');
    expect(repository).toContain("$initialState = $copie_totali > 0 ? 'disponibile' : 'non_disponibile';");
    expect(updateBasic).not.toContain("$addSet('stato'");
    expect(updateBasic).not.toContain("$addSet('copie_totali'");
    expect(updateBasic).not.toContain("$addSet('copie_disponibili'");
  });

  test('3) LibriController does not save user-posted derived availability on edit', () => {
    const booksController = read('app/Controllers/LibriController.php');

    expect(booksController).toContain('// Non aggiorniamo disponibilità/stato dall\'utente');
    expect(booksController).toContain("unset($fields['copie_disponibili']);");
    expect(booksController).toContain("unset($fields['stato']);");
    expect(booksController.lastIndexOf("unset($fields['stato']);")).toBeLessThan(
      booksController.lastIndexOf('(new \\App\\Models\\BookRepository($db))->updateOptionals')
    );
  });

  test('4) PrestitiController sends returned-loan notifications for repair returns', () => {
    const loanController = read('app/Controllers/PrestitiController.php');

    expect(loanController).toContain("if ($loan_stato === 'restituito')");
    expect(loanController).not.toContain("if ($nuovo_stato === 'restituito')");
    expect(loanController).toContain("'manutenzione' => 'manutenzione'");
    expect(loanController).toContain("'in_restauro'  => 'in_restauro'");
  });

  test('5) DataIntegrity repair recalculates the derived read model inside its transaction', () => {
    const dataIntegrity = read('app/Support/DataIntegrity.php');
    const repair = dataIntegrity.slice(
      dataIntegrity.indexOf('public function fixDataInconsistencies'),
      dataIntegrity.indexOf('private function createMissingIndexes')
    );

    expect(repair).toContain('$this->db->begin_transaction();');
    expect(repair).toContain('$this->recalculateAllBookAvailability(insideTransaction: true);');
    expect(repair.indexOf('$this->recalculateAllBookAvailability(insideTransaction: true);')).toBeLessThan(
      repair.indexOf('$this->db->commit();')
    );
    expect(repair).not.toContain('UPDATE libri SET stato');
    expect(dataIntegrity).toContain('AND deleted_at IS NULL');
  });

  test('6) translate_book_status localizes derived book states without underscores', () => {
    const helper = read('app/helpers.php');

    expect(helper).toContain('function translate_book_status(string $status): string');
    expect(helper).toContain("'non_disponibile' => __('Non Disponibile')");
    expect(helper).toContain("str_replace('_', ' ', $status)");
  });

  test('7) book detail page uses the centralized book status label in both badges', () => {
    const bookDetail = read('app/Views/libri/scheda_libro.php');

    expect(bookDetail).toContain("'non_disponibile' => 'inline-flex items-center gap-2 rounded-full");
    expect(bookDetail).toContain('translate_book_status($status)');
    expect(bookDetail).toContain("translate_book_status((string)($libro['stato'] ?? ''))");
  });

  test('8) author detail page uses the centralized book status label', () => {
    const authorDetail = read('app/Views/autori/scheda_autore.php');

    expect(authorDetail).toContain("translate_book_status((string)($libro['stato'] ?? ''))");
    expect(authorDetail).not.toContain("__(ucfirst($libro['stato'] ?? ''))");
  });

  test('9) publisher detail page uses the centralized book status label', () => {
    const publisherDetail = read('app/Views/editori/scheda_editore.php');

    expect(publisherDetail).toContain("translate_book_status((string)($libro['stato'] ?? ''))");
    expect(publisherDetail).not.toContain("__(ucfirst($libro['stato'] ?? ''))");
  });

  test('10) books index maps non_disponibile to the unavailable badge metadata', () => {
    const bookIndex = read('app/Views/libri/index.php');

    expect(bookIndex).toContain("stato === 'non_disponibile'");
    expect(bookIndex).toContain("return { cls: 'bg-red-500', icon: 'fa-times-circle' };");
  });

  test('11) en_US locale translates repair-return outcomes', () => {
    const messages = JSON.parse(read('locale/en_US.json'));

    expect(messages['Restituito — copia in manutenzione']).toBe('Returned - copy under maintenance');
    expect(messages['Restituito — copia in restauro']).toBe('Returned - copy in restoration');
  });

  test('12) fr_FR locale translates repair-return outcomes', () => {
    const messages = JSON.parse(read('locale/fr_FR.json'));

    expect(messages['Restituito — copia in manutenzione']).toBe('Rendu - exemplaire en maintenance');
    expect(messages['Restituito — copia in restauro']).toBe('Rendu - exemplaire en restauration');
  });

  test('13) de_DE locale translates repair-return outcomes', () => {
    const messages = JSON.parse(read('locale/de_DE.json'));

    expect(messages['Restituito — copia in manutenzione']).toBe('Zurückgegeben - Exemplar in Wartung');
    expect(messages['Restituito — copia in restauro']).toBe('Zurückgegeben - Exemplar in Restaurierung');
  });

  test('14) it_IT locale includes repair-return outcome keys', () => {
    const messages = JSON.parse(read('locale/it_IT.json'));

    expect(messages['Restituito — copia in manutenzione']).toBe('Restituito — copia in manutenzione');
    expect(messages['Restituito — copia in restauro']).toBe('Restituito — copia in restauro');
  });

  test('15) loan edge-case suite labels match the expanded 68-test coverage', () => {
    const loanEdges = read('tests/loan-edge-cases.unit.php');

    expect(loanEdges).toContain('Exit:  0 only if all 68 pass');
    expect(loanEdges).toContain('* 41-48  Mixed / availability math');
    expect(loanEdges).toContain('* 49-52  Misc invariants');
    expect(loanEdges).toContain('* 53-68  Canonical capacity, schedules, integrity, calendars and reservation queues');
    expect(loanEdges).toContain('printf("[%02d/68] PASS: %s\\n", $TESTNO, $desc);');
  });
});
