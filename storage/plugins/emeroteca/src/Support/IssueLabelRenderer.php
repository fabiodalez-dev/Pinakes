<?php

declare(strict_types=1);

namespace App\Plugins\Emeroteca\Support;

use mysqli;
use mysqli_result;
use RuntimeException;
use TCPDF;
use TCPDFBarcode;

/**
 * Spine/shelf labels for single issues (review #140).
 *
 * Deliberately built on the SAME machinery the core uses for per-copy book
 * labels (LibriController::generateCopyLabelsPDF, copy-tracking #238):
 *
 *   • TCPDF for the document, `write1DBarcode()` for the symbol — no new
 *     dependency is introduced;
 *   • the label size comes from the core `label.width` / `label.height`
 *     settings, with the same 10–100 mm clamp, the same 25×38 mm defaults
 *     and the same configurable `label.padding` inset;
 *   • the content is laid out as the same stack of weighted blocks that
 *     scales to fill the label, so text and barcode grow with the label
 *     instead of floating in a fixed size.
 *
 * The one deliberate difference: issues are printed in batches (a year of a
 * weekly is 52 labels), so instead of one label per page this renderer tiles
 * the labels on an A4 grid, adding pages as the grid fills. Same label
 * geometry, far less paper.
 *
 * Two serializations of the identical layout:
 *   • labelsPdf()  — TCPDF bytes, the core-aligned output meant for printing;
 *   • labelsHtml() — a self-contained printable A4 sheet (`@page size: A4`)
 *     for previews and for installs where a browser print is preferred. The
 *     barcode is an inline SVG produced by TCPDF's own TCPDFBarcode class,
 *     so both outputs encode the symbol with exactly the same library.
 *
 * Pure component: methods take the mysqli handle and return a string. No
 * routes, no headers, no echo. There is no PSR-4 autoloader scope for plugin
 * classes (see IssnHelper): callers `require_once` this file directly.
 *
 * Barcode policy, in order:
 *   1. the issue's own `barcode` (EAN-13 with the 977 prefix, or any local code);
 *   2. otherwise the title's `barcode_base` (the ISSN-derived 977 EAN-13);
 *   3. otherwise no barcode at all — the label is still emitted, carrying the
 *      inventory number and shelfmark, because a label without a symbol is
 *      still a usable label while a missing label is a lost issue.
 */
final class IssueLabelRenderer
{
    /** Printable sheet geometry (mm). */
    private const SHEET_WIDTH = 210.0;
    private const SHEET_HEIGHT = 297.0;
    private const SHEET_MARGIN = 8.0;
    private const CELL_GAP = 2.0;

    /**
     * Batch label PDF: one label per issue, tiled on A4.
     *
     * @param  list<int>|array<int, int> $issueIds
     * @return string Raw PDF bytes ('' when no issue matches).
     * @throws RuntimeException when TCPDF is unavailable.
     */
    public static function labelsPdf(mysqli $db, array $issueIds): string
    {
        $labels = self::fetchLabels($db, $issueIds);
        if ($labels === []) {
            return '';
        }
        if (!class_exists(TCPDF::class)) {
            throw new RuntimeException('TCPDF is not available: cannot render issue labels.');
        }

        $geometry = self::geometry($db);

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator($geometry['appName']);
        $pdf->SetAuthor($geometry['appName']);
        $pdf->SetTitle(self::t('Etichette fascicoli'));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(self::SHEET_MARGIN, self::SHEET_MARGIN, self::SHEET_MARGIN);
        $pdf->SetAutoPageBreak(false, 0);

        $perPage = $geometry['columns'] * $geometry['rows'];
        foreach ($labels as $index => $label) {
            $slot = $index % $perPage;
            if ($slot === 0) {
                $pdf->AddPage();
            }
            $column = $slot % $geometry['columns'];
            $row = intdiv($slot, $geometry['columns']);

            $x = self::SHEET_MARGIN + $column * ($geometry['labelWidth'] + self::CELL_GAP) + $geometry['padding'];
            $y = self::SHEET_MARGIN + $row * ($geometry['labelHeight'] + self::CELL_GAP) + $geometry['padding'];
            $width = max(1.0, $geometry['labelWidth'] - 2 * $geometry['padding']);
            $height = max(1.0, $geometry['labelHeight'] - 2 * $geometry['padding']);

            self::renderScaledLabel($pdf, self::blocks($label, $geometry['appName']), $x, $y, $width, $height);
        }

        return $pdf->Output('', 'S');
    }

    /**
     * Batch label sheet as a self-contained printable HTML document.
     *
     * Every interpolated value is escaped with htmlspecialchars(ENT_QUOTES);
     * the barcode is an inline SVG whose only attributes are numbers.
     *
     * @param list<int>|array<int, int> $issueIds
     */
    public static function labelsHtml(mysqli $db, array $issueIds): string
    {
        $labels = self::fetchLabels($db, $issueIds);
        $geometry = self::geometry($db);
        $title = self::esc(self::t('Etichette fascicoli'));

        $labelWidth = $geometry['labelWidth'];
        $labelHeight = $geometry['labelHeight'];
        $padding = $geometry['padding'];

        $html = "<!DOCTYPE html>\n<html lang=\"" . self::esc(self::htmlLang()) . "\">\n<head>\n"
            . "<meta charset=\"utf-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . '<title>' . $title . "</title>\n"
            . "<style>\n"
            . "@page { size: A4; margin: " . self::num(self::SHEET_MARGIN) . "mm; }\n"
            . "body { margin: 0; padding: " . self::num(self::SHEET_MARGIN) . "mm; font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #111; background: #fff; }\n"
            . ".emeroteca-labels { display: flex; flex-wrap: wrap; gap: " . self::num(self::CELL_GAP) . "mm; align-content: flex-start; }\n"
            . ".emeroteca-label { width: " . self::num($labelWidth) . "mm; height: " . self::num($labelHeight) . "mm;"
            . " padding: " . self::num($padding) . "mm; box-sizing: border-box; border: 0.2mm solid #ccc;"
            . " display: flex; flex-direction: column; align-items: center; justify-content: center;"
            . " gap: 0.4mm; overflow: hidden; text-align: center; page-break-inside: avoid; break-inside: avoid; }\n"
            . ".emeroteca-label .l-app { font-size: 1.7mm; font-weight: 700; letter-spacing: 0.02em; }\n"
            . ".emeroteca-label .l-title { font-size: 2.2mm; font-weight: 700; line-height: 1.1; }\n"
            . ".emeroteca-label .l-issue { font-size: 2mm; }\n"
            . ".emeroteca-label .l-meta { font-size: 1.7mm; color: #333; }\n"
            . ".emeroteca-label .l-code { font-size: 1.7mm; font-weight: 700; }\n"
            . ".emeroteca-label .l-barcode { width: 100%; }\n"
            . ".emeroteca-label .l-barcode svg { width: 100%; height: 6mm; display: block; }\n"
            . ".emeroteca-empty { font-size: 3mm; color: #666; }\n"
            . "@media print { .emeroteca-label { border-color: transparent; } }\n"
            . "</style>\n</head>\n<body>\n";

        if ($labels === []) {
            $html .= '<p class="emeroteca-empty">' . self::esc(self::t('Nessun fascicolo selezionato.')) . "</p>\n";
            return $html . "</body>\n</html>\n";
        }

        $html .= "<div class=\"emeroteca-labels\">\n";
        foreach ($labels as $label) {
            $html .= "<div class=\"emeroteca-label\">\n";
            foreach (self::blocks($label, $geometry['appName']) as $block) {
                if ($block['type'] === 'barcode') {
                    $svg = self::barcodeSvg((string) $block['value'], (string) $block['symbology']);
                    if ($svg !== '') {
                        $html .= '<div class="l-barcode">' . $svg . "</div>\n";
                    }
                    continue;
                }
                $html .= '<div class="' . self::esc((string) $block['css']) . '">'
                    . self::esc((string) $block['text']) . "</div>\n";
            }
            $html .= "</div>\n";
        }
        $html .= "</div>\n</body>\n</html>\n";

        return $html;
    }

    // ── data ──────────────────────────────────────────────────────────

    /**
     * Load the printable fields of the requested issues, in the order the
     * caller asked for them.
     *
     * @param  list<int>|array<int, int> $issueIds
     * @return list<array<string, mixed>>
     */
    private static function fetchLabels(mysqli $db, array $issueIds): array
    {
        $ids = [];
        foreach ($issueIds as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT f.id, f.numero, f.numero_progressivo, f.titolo_fascicolo, f.data_copertina,
                    f.data_pubblicazione, f.numero_inventario, f.barcode,
                    a.anno, a.volume, a.serie,
                    t.titolo AS testata_titolo, t.barcode_base,
                    s.codice AS scaffale_codice, m.numero_livello
               FROM emeroteca_fascicoli f
               JOIN emeroteca_annate a ON a.id = f.annata_id
               JOIN emeroteca_testate t ON t.id = a.testata_id
               LEFT JOIN mensole m ON m.id = f.collocazione_id
               LEFT JOIN scaffali s ON s.id = m.scaffale_id
              WHERE f.id IN ({$placeholders})"
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $res = $stmt->get_result();
        $byId = [];
        if ($res instanceof mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $byId[(int) $row['id']] = $row;
            }
        }
        $stmt->close();

        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }
        return $out;
    }

    /**
     * Ordered content blocks of one label. Shared by both renderers so the
     * PDF and the HTML sheet can never drift apart.
     *
     * The `weight` mirrors the core proportional renderer: the barcode
     * dominates, the title is the prominent line, everything else is lighter.
     *
     * @param  array<string, mixed> $label
     * @return list<array{type:string, text?:string, style?:string, css?:string, weight:float, value?:string, symbology?:string}>
     */
    private static function blocks(array $label, string $appName): array
    {
        $blocks = [];

        $appName = trim($appName);
        if ($appName !== '') {
            $blocks[] = ['type' => 'text', 'text' => $appName, 'style' => 'B', 'css' => 'l-app', 'weight' => 0.8];
        }

        $titolo = self::truncate(trim((string) ($label['testata_titolo'] ?? '')), 60);
        if ($titolo !== '') {
            $blocks[] = ['type' => 'text', 'text' => $titolo, 'style' => 'B', 'css' => 'l-title', 'weight' => 1.5];
        }

        $issue = self::issueDesignation($label);
        if ($issue !== '') {
            $blocks[] = ['type' => 'text', 'text' => $issue, 'style' => '', 'css' => 'l-issue', 'weight' => 1.1];
        }

        $cover = self::coverDate($label);
        if ($cover !== '') {
            $blocks[] = ['type' => 'text', 'text' => $cover, 'style' => 'I', 'css' => 'l-meta', 'weight' => 0.7];
        }

        $barcode = self::barcodePayload($label);
        if ($barcode !== null) {
            $blocks[] = [
                'type' => 'barcode',
                'weight' => 3.0,
                'value' => $barcode['value'],
                'symbology' => $barcode['type'],
            ];
            $blocks[] = [
                'type' => 'text',
                'text' => $barcode['value'],
                'style' => '',
                'css' => 'l-meta',
                'weight' => 0.7,
            ];
        }

        $inventario = trim((string) ($label['numero_inventario'] ?? ''));
        if ($inventario !== '') {
            $blocks[] = ['type' => 'text', 'text' => $inventario, 'style' => 'B', 'css' => 'l-code', 'weight' => 0.9];
        }

        $collocazione = self::collocazione($label);
        if ($collocazione !== '') {
            $blocks[] = ['type' => 'text', 'text' => $collocazione, 'style' => 'B', 'css' => 'l-code', 'weight' => 1.0];
        }

        return $blocks;
    }

    /** "1998 · v. 2 · n. 7" — whichever parts the issue actually has. */
    private static function issueDesignation(array $label): string
    {
        $parts = [];
        $anno = trim((string) ($label['anno'] ?? ''));
        if ($anno !== '') {
            $parts[] = $anno;
        }
        $volume = trim((string) ($label['volume'] ?? ''));
        if ($volume === '') {
            $volume = trim((string) ($label['serie'] ?? ''));
        }
        if ($volume !== '') {
            $parts[] = 'v. ' . $volume;
        }
        $numero = trim((string) ($label['numero'] ?? ''));
        if ($numero !== '') {
            $parts[] = 'n. ' . $numero;
        }
        return implode(' · ', $parts);
    }

    /**
     * Cover date as printed on the issue, falling back to the publication
     * date. `data_copertina` is free text ("Estate 1998") by design.
     */
    private static function coverDate(array $label): string
    {
        $cover = trim((string) ($label['data_copertina'] ?? ''));
        if ($cover !== '') {
            return self::truncate($cover, 40);
        }
        $published = trim((string) ($label['data_pubblicazione'] ?? ''));
        return $published !== '' ? $published : '';
    }

    /** "A.3" — shelf code and shelf level, when the issue is shelved. */
    private static function collocazione(array $label): string
    {
        $scaffale = trim((string) ($label['scaffale_codice'] ?? ''));
        $livello = trim((string) ($label['numero_livello'] ?? ''));
        if ($scaffale === '' || $livello === '') {
            return '';
        }
        return $scaffale . '.' . $livello;
    }

    /**
     * Resolve the symbol to print, mirroring the core's payload preparation:
     * 13 digits is an EAN-13, 8 digits an EAN-8, anything else Code 128.
     *
     * @return array{value:string, type:string}|null
     */
    private static function barcodePayload(array $label): ?array
    {
        $raw = trim((string) ($label['barcode'] ?? ''));
        if ($raw === '') {
            $raw = trim((string) ($label['barcode_base'] ?? ''));
        }
        if ($raw === '') {
            return null;
        }
        // Keep the printable ASCII subset Code 128 can encode; this also
        // strips any control character that could reach the SVG/PDF writer.
        $raw = (string) preg_replace('/[^\x20-\x7E]/', '', $raw);
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // TCPDF REFUSES an EAN with a wrong check digit (barcode_eanupc()
        // returns false and the encoder throws), so a mistyped code must
        // degrade to Code 128 rather than take the whole print job down.
        $digits = (string) preg_replace('/\D/', '', $raw);
        if (preg_match('/^\d{13}$/', $digits) === 1 && self::hasValidGtinCheckDigit($digits)) {
            return ['value' => $digits, 'type' => 'EAN13'];
        }
        if (preg_match('/^\d{8}$/', $digits) === 1 && self::hasValidGtinCheckDigit($digits)) {
            return ['value' => $digits, 'type' => 'EAN8'];
        }

        return ['value' => $raw, 'type' => 'C128'];
    }

    /**
     * GS1 modulo-10 check digit, valid for both EAN-13 and EAN-8: digits are
     * weighted 3/1 from the right, excluding the trailing check digit.
     */
    private static function hasValidGtinCheckDigit(string $digits): bool
    {
        $length = strlen($digits);
        $sum = 0;
        for ($i = 0; $i < $length - 1; $i++) {
            // Rightmost payload digit weighs 3, alternating leftwards.
            $weight = (($length - 2 - $i) % 2 === 0) ? 3 : 1;
            $sum += ((int) $digits[$i]) * $weight;
        }

        return ((10 - ($sum % 10)) % 10) === (int) $digits[$length - 1];
    }

    // ── rendering ─────────────────────────────────────────────────────

    /**
     * Core-equivalent proportional renderer, scoped to one grid cell: the
     * blocks are distributed by weight so the stack fills the cell exactly,
     * text auto-shrinks until the wrapped run fits its block, and the barcode
     * stretches to the cell width.
     *
     * @param list<array{type:string, text?:string, style?:string, css?:string, weight:float, value?:string, symbology?:string}> $blocks
     */
    private static function renderScaledLabel(
        TCPDF $pdf,
        array $blocks,
        float $x,
        float $y,
        float $width,
        float $height
    ): void {
        $count = count($blocks);
        if ($count === 0 || $width <= 0 || $height <= 0) {
            return;
        }
        $family = 'dejavusans';

        $gap = $height * 0.03;
        $usableHeight = max(0.1, $height - $gap * ($count - 1));
        $totalWeight = 0.0;
        foreach ($blocks as $block) {
            $totalWeight += $block['weight'];
        }
        if ($totalWeight <= 0.0) {
            return;
        }

        $cursorY = $y;
        foreach ($blocks as $index => $block) {
            $blockHeight = $usableHeight * ($block['weight'] / $totalWeight);

            if ($block['type'] === 'barcode') {
                $pdf->write1DBarcode(
                    (string) $block['value'],
                    (string) $block['symbology'],
                    $x,
                    $cursorY,
                    $width,
                    $blockHeight,
                    0.4,
                    ['stretch' => true],
                    'N'
                );
            } else {
                $text = (string) ($block['text'] ?? '');
                $fontPt = ($blockHeight / 0.3528) * 0.68;
                $fontPt = max(3.0, min(60.0, $fontPt));
                $pdf->SetFont($family, (string) ($block['style'] ?? ''), $fontPt);
                while ($fontPt > 3.0 && $pdf->getStringHeight($width, $text) > $blockHeight) {
                    $fontPt -= 0.5;
                    $pdf->SetFont($family, (string) ($block['style'] ?? ''), $fontPt);
                }
                $pdf->MultiCell($width, $blockHeight, $text, 0, 'C', false, 0, $x, $cursorY, true, 0, false, true, $blockHeight, 'M');
            }

            $cursorY += $blockHeight;
            if ($index < $count - 1) {
                $cursorY += $gap;
            }
        }
    }

    /**
     * Inline SVG for the HTML sheet, produced by TCPDF's own barcode encoder.
     *
     * TCPDF emits an XML prolog, a DOCTYPE and a <desc> element carrying the
     * raw code; all three are stripped so what is embedded is a bare <svg>
     * containing nothing but <g>/<rect> elements with numeric attributes.
     */
    private static function barcodeSvg(string $value, string $symbology): string
    {
        if (!class_exists(TCPDFBarcode::class)) {
            return '';
        }
        try {
            $barcode = new TCPDFBarcode($value, $symbology);
            $svg = $barcode->getBarcodeSVGcode(2, 30, 'black');
        } catch (\Throwable $e) {
            return '';
        }
        if ($svg === '') {
            return '';
        }
        $svg = (string) preg_replace('/<\?xml.*?\?>\s*/s', '', $svg);
        $svg = (string) preg_replace('/<!DOCTYPE.*?>\s*/s', '', $svg);
        $svg = (string) preg_replace('#<desc>.*?</desc>\s*#s', '', $svg);
        $svg = trim($svg);

        // Defence in depth: embed only when nothing but the expected element
        // vocabulary survived the strip.
        if (preg_match('/^<svg\b[^>]*>.*<\/svg>$/s', $svg) !== 1) {
            return '';
        }
        if (preg_match('/<\s*(script|foreignObject|image|a|use|style)\b/i', $svg) === 1) {
            return '';
        }

        return $svg;
    }

    // ── settings & helpers ────────────────────────────────────────────

    /**
     * Label geometry read from the CORE label settings, with the identical
     * clamps LibriController::resolveLabelSettings() applies, plus the
     * derived A4 grid.
     *
     * @return array{appName:string, labelWidth:float, labelHeight:float, padding:float, columns:int, rows:int}
     */
    private static function geometry(mysqli $db): array
    {
        $appName = 'Biblioteca';
        $labelWidth = 25.0;
        $labelHeight = 38.0;
        $padding = 0.0;

        if (class_exists(\App\Models\SettingsRepository::class)) {
            $settings = new \App\Models\SettingsRepository($db);
            $appName = (string) $settings->get('app', 'name', 'Biblioteca');
            $labelWidth = (float) (int) $settings->get('label', 'width', '25');
            $labelHeight = (float) (int) $settings->get('label', 'height', '38');
            $padding = (float) $settings->get('label', 'padding', '0');
        }

        if ($labelWidth < 10.0 || $labelWidth > 100.0) {
            $labelWidth = 25.0;
        }
        if ($labelHeight < 10.0 || $labelHeight > 100.0) {
            $labelHeight = 38.0;
        }
        $maxPadding = min($labelWidth, $labelHeight) / 3;
        if ($padding < 0.0) {
            $padding = 0.0;
        } elseif ($padding > $maxPadding) {
            $padding = $maxPadding;
        }

        $usableWidth = self::SHEET_WIDTH - 2 * self::SHEET_MARGIN;
        $usableHeight = self::SHEET_HEIGHT - 2 * self::SHEET_MARGIN;
        $columns = (int) floor(($usableWidth + self::CELL_GAP) / ($labelWidth + self::CELL_GAP));
        $rows = (int) floor(($usableHeight + self::CELL_GAP) / ($labelHeight + self::CELL_GAP));

        return [
            'appName' => $appName,
            'labelWidth' => $labelWidth,
            'labelHeight' => $labelHeight,
            'padding' => $padding,
            'columns' => max(1, $columns),
            'rows' => max(1, $rows),
        ];
    }

    private static function truncate(string $value, int $max): string
    {
        if ($value === '' || mb_strlen($value, 'UTF-8') <= $max) {
            return $value;
        }
        return rtrim(mb_substr($value, 0, $max - 1, 'UTF-8')) . '…';
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /** Millimetre value for CSS, always with a dot separator. */
    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }

    /** Translation with the guard used across the plugin (CLI has no __()). */
    private static function t(string $text): string
    {
        return function_exists('__') ? __($text) : $text;
    }

    /**
     * Two-letter language for the printable sheet, derived exactly like
     * app/Views/layout.php does. Falls back to 'it' in CLI contexts where
     * I18n was never bootstrapped.
     */
    private static function htmlLang(): string
    {
        if (!class_exists(\App\Support\I18n::class)) {
            return 'it';
        }
        $locale = substr(\App\Support\I18n::getLocale(), 0, 2);
        return preg_match('/^[a-z]{2}$/i', $locale) === 1 ? strtolower($locale) : 'it';
    }
}
