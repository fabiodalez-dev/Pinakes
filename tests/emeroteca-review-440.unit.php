<?php
// Review fixes on PR #440 that a unit can pin without a browser.
// Run: php tests/emeroteca-review-440.unit.php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/storage/plugins/emeroteca/EmerotecaPlugin.php';
require_once dirname(__DIR__) . '/storage/plugins/emeroteca/src/Services/ContributionService.php';
require_once dirname(__DIR__) . '/storage/plugins/emeroteca/src/Services/ContributionCsv.php';
require_once dirname(__DIR__) . '/storage/plugins/emeroteca/src/Support/CodeLists.php';

$n = 0;
function check(bool $ok, string $label): void
{
    global $n;
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $n++;
    echo "OK {$label}\n";
}
/** @param array<string,mixed> $row */
function renderAuthors(array $row): string
{
    ob_start();
    include dirname(__DIR__) . '/storage/plugins/emeroteca/src/Views/article-authors.php';
    return (string)ob_get_clean();
}
function fieldValue(string $html): string
{
    preg_match('/<input[^>]*id="article-autori"[^>]*value="([^"]*)"/', $html, $m);
    return html_entity_decode($m[1] ?? '', ENT_QUOTES, 'UTF-8');
}

// A form re-shown after a failed save (422) without the picker: the stored
// credits come from the record, the text from what the operator typed.
$stored = ['author_credits' => [['autore_id' => 5, 'nome_credito' => 'Rossi, Mario', 'display_name' => 'Mario Rossi']]];
check(fieldValue(renderAuthors($stored + ['autori' => 'Bianchi, Anna; Verdi, Luca'])) === 'Bianchi, Anna; Verdi, Luca',
    'a failed save shows the authors the operator typed, not the stored ones');
$unchanged = renderAuthors($stored + ['autori' => 'Rossi, Mario']);
check(fieldValue($unchanged) === 'Rossi, Mario' && str_contains($unchanged, '"kind":"linked"'),
    'an unchanged author text keeps the linked author');
check(fieldValue(renderAuthors(['credits' => [['autore_id' => '', 'nome_credito' => 'Neri, Ugo', 'create' => '1']], 'autori' => 'ignored'])) === 'Neri, Ugo',
    'credits posted by the picker still win over the text');

// Without JavaScript the text field is the author input: the label names it.
check(preg_match('/<label for="article-autori"[^>]*>/', renderAuthors(['autori' => 'Rossi, Mario'])) === 1,
    'the Autori label points at the visible text field');

// The plugin's own documentation keeps up with the code it describes.
$plugin = json_decode((string)file_get_contents(dirname(__DIR__) . '/storage/plugins/emeroteca/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
check(str_starts_with((string)$plugin['changelog'], 'v' . $plugin['version'] . ':'), 'the changelog opens with the version plugin.json declares');
$readme = (string)file_get_contents(dirname(__DIR__) . '/storage/plugins/emeroteca/README.md');
preg_match('/The supported columns are:\s*```text\n([^\n]+)\n```/', $readme, $columns);
check(($columns[1] ?? '') === implode(',', \App\Plugins\Emeroteca\Services\ContributionService::CSV_HEADER),
    'the README lists exactly the columns the template and the export carry');
foreach (\App\Plugins\Emeroteca\Services\ContributionCsv::RECORD_TYPES as $type) {
    check(preg_match('/`record_type`[^\n]*`' . preg_quote($type, '/') . '`/', $readme) === 1, "the README documents record_type {$type}");
}

// Language codes: one stored form, one published form.
use App\Plugins\Emeroteca\Support\CodeLists;
check(CodeLists::terminologyCode('it') === 'ita' && CodeLists::terminologyCode('IT') === 'ita', 'a two-letter code is stored as the picker stores it');
check(CodeLists::terminologyCode('ger') === 'deu' && CodeLists::terminologyCode('deu') === 'deu', 'a bibliographic code becomes the terminology one');
check(CodeLists::terminologyCode('non') === 'non', 'a language with no two-letter code is kept');
check(CodeLists::languageTag('ita') === 'it' && CodeLists::languageTag('it') === 'it' && CodeLists::languageTag('deu') === 'de', 'inLanguage is BCP 47: the two-letter code where one exists');
check(CodeLists::languageTag('non') === 'non' && CodeLists::languageTag('') === '', 'and the three-letter one where none does');

echo "SUCCESS {$n} checks\n";
