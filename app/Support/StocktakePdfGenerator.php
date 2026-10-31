<?php
declare(strict_types=1);

namespace App\Support;

use TCPDF;

/**
 * Final report of an inventory stocktake session, built with TCPDF and the
 * bundled Unicode 'dejavusans' font like the other PDF exports.
 */
class StocktakePdfGenerator
{
    private const FONT = 'dejavusans';

    /** Report sections in reading order: what needs attention first. */
    private const SECTIONS = ['mancante', 'fuori_posto', 'in_prestito', 'sconosciuta', 'fuori_ambito', 'duplicata', 'trovata'];

    /**
     * @param array<string,mixed> $session
     * @param array<string,int> $summary
     * @param list<array<string,string>> $rows
     * @param list<string> $filterLabels
     * @param array<string,string> $labels
     */
    public function generate(array $session, array $summary, array $rows, array $filterLabels, array $labels): string
    {
        $appName = (string) ConfigStore::get('app.name', 'Biblioteca');
        $title = __('Revisione inventariale');

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator($appName);
        $pdf->SetAuthor($appName);
        $pdf->SetTitle($title . ' - ' . (string) $session['nome']);
        $pdf->SetSubject((string) $session['nome']);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage();

        $pdf->SetFont(self::FONT, 'B', 16);
        $pdf->Cell(0, 9, $appName, 0, 1, 'C');
        $pdf->SetFont(self::FONT, '', 12);
        $pdf->Cell(0, 7, $title . ': ' . (string) $session['nome'], 0, 1, 'C');

        $pdf->SetFont(self::FONT, '', 9);
        $pdf->SetTextColor(90, 90, 90);
        $states = ['aperta' => __('Aperta'), 'chiusa' => __('Chiusa'), 'annullata' => __('Annullata')];
        $meta = [
            __('Stato') . ': ' . ($states[(string) $session['stato']] ?? (string) $session['stato']),
            __('Aperta il') . ': ' . format_date((string) $session['aperta_il'], true, '/'),
        ];
        if (!empty($session['chiusa_il'])) {
            $meta[] = __('Chiusa il') . ': ' . format_date((string) $session['chiusa_il'], true, '/');
        }
        if (!empty($session['sede_nome'])) {
            $meta[] = __('Sede') . ': ' . (string) $session['sede_nome'];
        }
        $pdf->MultiCell(0, 5, implode('  ·  ', $meta), 0, 'C');
        $pdf->MultiCell(0, 5, $filterLabels !== [] ? implode('  ·  ', $filterLabels) : __('Tutto il catalogo'), 0, 'C');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);

        $pdf->SetFont(self::FONT, 'B', 10);
        $pdf->Cell(0, 7, __('Riepilogo'), 0, 1, 'L');
        $pdf->SetFont(self::FONT, '', 9);
        $pairs = [
            [__('Copie attese'), $summary['attese'] ?? 0],
            [$labels['trovata'] ?? 'trovata', $summary['trovate'] ?? 0],
            [$labels['fuori_posto'] ?? 'fuori_posto', $summary['fuori_posto'] ?? 0],
            [$labels['in_prestito'] ?? 'in_prestito', $summary['in_prestito'] ?? 0],
            [$labels['mancante'] ?? 'mancante', $summary['mancanti'] ?? 0],
            [$labels['sconosciuta'] ?? 'sconosciuta', $summary['sconosciute'] ?? 0],
            [$labels['fuori_ambito'] ?? 'fuori_ambito', $summary['fuori_ambito'] ?? 0],
            [$labels['duplicata'] ?? 'duplicata', $summary['duplicate'] ?? 0],
        ];
        if (($session['stato'] ?? '') === 'chiusa') {
            $pairs[] = [__('Segnate come perse'), $summary['perse'] ?? 0];
        }
        $pdf->SetFillColor(245, 245, 245);
        foreach ($pairs as $i => [$label, $value]) {
            $pdf->Cell(60, 6, (string) $label, 1, 0, 'L', $i % 2 === 0);
            $pdf->Cell(25, 6, (string) $value, 1, 1, 'R', $i % 2 === 0);
        }
        $pdf->Ln(4);

        $cols = [
            ['label' => __('Codice copia'), 'key' => 'codice', 'w' => 32],
            ['label' => __('Titolo'), 'key' => 'titolo', 'w' => 70],
            ['label' => __('Collocazione'), 'key' => 'collocazione', 'w' => 26],
            ['label' => __('Scaffale atteso'), 'key' => 'scaffale_atteso', 'w' => 30],
            ['label' => __('Stato copia'), 'key' => 'stato_copia', 'w' => 28],
        ];
        foreach (self::SECTIONS as $section) {
            $sectionRows = array_values(array_filter($rows, static fn (array $row): bool => $row['categoria'] === $section));
            if ($sectionRows === []) {
                continue;
            }
            $pdf->SetFont(self::FONT, 'B', 10);
            $pdf->Cell(0, 7, ($labels[$section] ?? $section) . ' (' . count($sectionRows) . ')', 0, 1, 'L');
            $pdf->SetFont(self::FONT, 'B', 8);
            $pdf->SetFillColor(235, 235, 235);
            foreach ($cols as $c) {
                $pdf->Cell($c['w'], 6, $c['label'], 1, 0, 'L', true);
            }
            $pdf->Ln();
            $pdf->SetFont(self::FONT, '', 8);
            $pdf->SetFillColor(249, 249, 249);
            $fill = false;
            foreach ($sectionRows as $row) {
                // The copy code is what staff look for on the shelf: it wraps
                // instead of being cut, so two long codes never print alike.
                $code = (string) ($row['codice'] ?? '');
                $height = max(1, $pdf->getNumLines($code, $cols[0]['w'])) * 4.5 + 1;
                if ($pdf->GetY() + $height > $pdf->getPageHeight() - $pdf->getBreakMargin()) {
                    $pdf->AddPage();
                }
                foreach ($cols as $c) {
                    $text = $c['key'] === 'codice' ? $code : $this->fit((string) ($row[$c['key']] ?? ''), $c['w']);
                    $pdf->MultiCell($c['w'], $height, $text, 1, 'L', $fill, 0, null, null, true, 0, false, true, $height, 'M');
                }
                $pdf->Ln($height);
                $fill = !$fill;
            }
            $pdf->Ln(3);
        }

        $pdf->Ln(2);
        $pdf->SetFont(self::FONT, 'I', 8);
        $pdf->SetTextColor(128, 128, 128);
        $tz = (string) ConfigStore::get('app.timezone', 'Europe/Rome');
        try {
            $timezone = new \DateTimeZone($tz);
        } catch (\Throwable) {
            $timezone = new \DateTimeZone('Europe/Rome');
        }
        $now = new \DateTime('now', $timezone);
        $pdf->Cell(0, 5, sprintf(__('Documento generato il %s alle %s'), format_date($now->format('Y-m-d'), false, '/'), $now->format('H:i')), 0, 1, 'C');

        return $pdf->Output('', 'S');
    }

    private function fit(string $value, float $widthMm): string
    {
        $max = (int) max(4, floor($widthMm / 1.9));
        return mb_strlen($value) <= $max ? $value : mb_substr($value, 0, $max - 1) . '…';
    }
}
