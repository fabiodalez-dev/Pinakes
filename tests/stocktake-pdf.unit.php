<?php
declare(strict_types=1);

/**
 * Inventory stocktake PDF report: the generator writes every report row in
 * its own section, under that section's heading, not just a document that
 * starts with %PDF- and carries the session name (review #504).
 *
 * TCPDF stores text as glyph ids of a subset font, so the bytes cannot be
 * searched for a copy code: the generator draws on a TCPDF subclass that
 * records every cell it is given, in order, and still renders the document.
 *
 * Run: php tests/stocktake-pdf.unit.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/helpers.php';

use App\Support\StocktakePdfGenerator;

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL {$label}\n");
        exit(1);
    }
    $checks++;
    echo "OK {$label}\n";
};

final class RecordingPdf extends TCPDF
{
    /**
     * Static: Output() tears the document's own properties down.
     *
     * @var list<string>
     */
    public static array $texts = [];
    /** MultiCell() draws its lines through Cell(): record the call the generator made, once. */
    private static int $depth = 0;

    public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '', $stretch = 0, $ignore_min_height = false, $calign = 'T', $valign = 'M')
    {
        if (self::$depth === 0) {
            self::$texts[] = (string) $txt;
        }
        return parent::Cell($w, $h, $txt, $border, $ln, $align, $fill, $link, $stretch, $ignore_min_height, $calign, $valign);
    }

    public function MultiCell($w, $h, $txt, $border = 0, $align = 'J', $fill = false, $ln = 1, $x = null, $y = null, $reseth = true, $stretch = 0, $ishtml = false, $autopadding = true, $maxh = 0, $valign = 'T', $fitcell = false)
    {
        if (self::$depth === 0) {
            self::$texts[] = (string) $txt;
        }
        self::$depth++;
        try {
            return parent::MultiCell($w, $h, $txt, $border, $align, $fill, $ln, $x, $y, $reseth, $stretch, $ishtml, $autopadding, $maxh, $valign, $fitcell);
        } finally {
            self::$depth--;
        }
    }
}

final class RecordingStocktakePdfGenerator extends StocktakePdfGenerator
{
    protected function createDocument(): TCPDF
    {
        RecordingPdf::$texts = [];
        return new RecordingPdf('P', 'mm', 'A4', true, 'UTF-8', false);
    }
}

$labels = [
    'mancante' => 'Mancante', 'fuori_posto' => 'Fuori posto', 'in_prestito' => 'In prestito',
    'sconosciuta' => 'Codice sconosciuto', 'fuori_ambito' => 'Fuori ambito', 'duplicata' => 'Duplicata', 'trovata' => 'Trovata',
];
$row = static fn (string $category, string $code): array => [
    'categoria' => $category, 'codice' => $code, 'titolo' => 'Titolo ' . $code, 'tipo_media' => 'libro',
    'collocazione' => 'A.1', 'scaffale_atteso' => '[X] Scaffale', 'stato_copia' => 'disponibile', 'operatore' => '', 'quando' => '',
];
$rows = [
    $row('trovata', 'PDF-FOUND-1'),
    $row('mancante', 'PDF-MISSING-1'),
    $row('fuori_posto', 'PDF-MISPLACED-1'),
    $row('in_prestito', 'PDF-LENT-1'),
    $row('sconosciuta', 'PDF-UNKNOWN-1'),
    $row('fuori_ambito', 'PDF-SCOPE-1'),
    $row('duplicata', 'PDF-DUP-1'),
    $row('mancante', 'PDF-MISSING-2'),
];
$session = ['nome' => 'Scaffale X', 'stato' => 'chiusa', 'aperta_il' => '2026-10-01 09:00:00', 'chiusa_il' => '2026-10-02 18:00:00', 'sede_nome' => ''];
$summary = ['attese' => 5, 'trovate' => 1, 'fuori_posto' => 1, 'in_prestito' => 1, 'mancanti' => 2, 'sconosciute' => 1, 'fuori_ambito' => 1, 'duplicate' => 1, 'perse' => 0];

$generator = new RecordingStocktakePdfGenerator();
$bytes = $generator->generate($session, $summary, $rows, [], $labels);
$texts = RecordingPdf::$texts;
$check(str_starts_with($bytes, '%PDF-') && $texts !== [], 'the report renders and its cells are recorded');

// Each code is written after its own section heading and before the next one.
$headingAt = [];
foreach ($labels as $category => $label) {
    $count = count(array_filter($rows, static fn (array $r): bool => $r['categoria'] === $category));
    $position = array_search("{$label} ({$count})", $texts, true);
    $check($position !== false, "the \"{$label}\" section has a heading with its row count");
    $headingAt[$category] = (int) $position;
}
asort($headingAt);
$sectionOf = static function (int $position) use ($headingAt): ?string {
    $section = null;
    foreach ($headingAt as $category => $start) {
        if ($start <= $position) {
            $section = $category;
        }
    }
    return $section;
};
foreach ($rows as $r) {
    $positions = array_keys($texts, $r['codice'], true);
    $check(count($positions) === 1, "{$r['codice']} is written once");
    $check($sectionOf($positions[0]) === $r['categoria'], "{$r['codice']} is in the {$r['categoria']} section");
    $check(in_array('Titolo ' . $r['codice'], $texts, true), "{$r['codice']} carries its title");
}
$check(array_keys($headingAt) === ['mancante', 'fuori_posto', 'in_prestito', 'sconosciuta', 'fuori_ambito', 'duplicata', 'trovata'], 'sections follow the reading order, missing copies first');

// An empty section is left out instead of printing a bare heading.
$generator = new RecordingStocktakePdfGenerator();
$generator->generate($session, $summary, [$row('mancante', 'PDF-ONLY-1')], [], $labels);
$texts = RecordingPdf::$texts;
$check(in_array('Mancante (1)', $texts, true) && in_array('PDF-ONLY-1', $texts, true) && !in_array('Trovata (0)', $texts, true), 'only sections with rows get a heading');

echo "SUCCESS {$checks} stocktake PDF checks\n";
