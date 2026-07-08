<?php
declare(strict_types=1);

namespace App\Support;

use TCPDF;

/**
 * Server-side PDF export of the users list (admin → Users → "Export PDF").
 *
 * Replaces a dead client-side jsPDF export: jsPDF was never bundled (the asset 404s),
 * and even if it were, its core Helvetica font can't render non-Latin-1 names. This uses
 * TCPDF with the bundled Unicode 'dejavusans' font — the same choice as the loan receipt
 * and book-label PDFs — so Polish/Turkish/Cyrillic/etc. names render correctly.
 *
 * The caller (UtentiApiController::exportPdf) applies the SAME filters as the on-screen
 * DataTables list, so the PDF is "what you see".
 */
class UsersPdfGenerator
{
    private const FONT = 'dejavusans';

    /**
     * @param list<array<string,mixed>> $users Rows with nome, cognome, email, telefono,
     *                                          tipo_utente, stato, codice_tessera.
     * @param int $totalCount    Total users in the library (unfiltered).
     * @return string PDF binary content.
     */
    public function generate(array $users, int $totalCount): string
    {
        $appName = (string) ConfigStore::get('app.name', 'Biblioteca');

        // Landscape A4: the user table is wide (name/email/role/status/card).
        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator($appName);
        $pdf->SetAuthor($appName);
        $pdf->SetTitle(__('Elenco Utenti'));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage();

        $this->renderHeader($pdf, $appName, count($users), $totalCount);
        $this->renderTable($pdf, $users);
        $this->renderFooter($pdf);

        return $pdf->Output('', 'S');
    }

    private function renderHeader(TCPDF $pdf, string $appName, int $shown, int $total): void
    {
        $pdf->SetFont(self::FONT, 'B', 16);
        $pdf->Cell(0, 9, $appName, 0, 1, 'C');
        $pdf->SetFont(self::FONT, '', 12);
        $pdf->Cell(0, 7, __('Elenco Utenti'), 0, 1, 'C');

        $pdf->SetFont(self::FONT, '', 9);
        $pdf->SetTextColor(90, 90, 90);
        // "shown" == "total" when no filter is active; otherwise it's the filtered subset.
        $summary = ($shown === $total)
            ? sprintf(__('%d utenti'), $total)
            : sprintf(__('%d utenti filtrati su %d totali'), $shown, $total);
        $pdf->Cell(0, 6, $summary, 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(2);
    }

    /**
     * @param list<array<string,mixed>> $users
     */
    private function renderTable(TCPDF $pdf, array $users): void
    {
        // Column widths (mm) sum to the ~273mm printable width of landscape A4 minus margins.
        $cols = [
            ['label' => __('Nome'),    'key' => 'nome',           'w' => 45],
            ['label' => __('Cognome'), 'key' => 'cognome',        'w' => 45],
            ['label' => __('Email'),   'key' => 'email',          'w' => 78],
            ['label' => __('Ruolo'),   'key' => 'tipo_utente',    'w' => 32],
            ['label' => __('Stato'),   'key' => 'stato',          'w' => 30],
            ['label' => __('Tessera'), 'key' => 'codice_tessera', 'w' => 43],
        ];

        // Header row.
        $pdf->SetFont(self::FONT, 'B', 9);
        $pdf->SetFillColor(235, 235, 235);
        foreach ($cols as $c) {
            $pdf->Cell($c['w'], 7, $c['label'], 1, 0, 'L', true);
        }
        $pdf->Ln();

        // Data rows.
        $pdf->SetFont(self::FONT, '', 8);
        $fill = false;
        foreach ($users as $u) {
            $pdf->SetFillColor(249, 249, 249);
            foreach ($cols as $c) {
                $value = (string) ($u[$c['key']] ?? '');
                // Keep each cell on one line — truncate over-long values so the row grid holds.
                $value = $this->fit($value, $c['w']);
                $pdf->Cell($c['w'], 6, $value, 1, 0, 'L', $fill);
            }
            $pdf->Ln();
            $fill = !$fill;
        }

        if ($users === []) {
            $pdf->SetFont(self::FONT, 'I', 9);
            $pdf->Cell(0, 8, __('Nessun utente da esportare.'), 0, 1, 'C');
        }
    }

    /**
     * Trim a value to roughly fit the given column width (mm) at the table font size,
     * appending an ellipsis when cut. TCPDF would otherwise overflow the fixed-width cell.
     */
    private function fit(string $value, float $widthMm): string
    {
        // ~1.9mm per glyph at 8pt DejaVu Sans; conservative so cells never overflow.
        $max = (int) max(4, floor($widthMm / 1.9));
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        return mb_substr($value, 0, $max - 1) . '…';
    }

    private function renderFooter(TCPDF $pdf): void
    {
        $pdf->Ln(4);
        $pdf->SetFont(self::FONT, 'I', 8);
        $pdf->SetTextColor(128, 128, 128);
        $tz = (string) ConfigStore::get('app.timezone', 'Europe/Rome');
        try {
            $timezone = new \DateTimeZone($tz);
        } catch (\Throwable $e) {
            $timezone = new \DateTimeZone('Europe/Rome');
        }
        $now = new \DateTime('now', $timezone);
        $pdf->Cell(0, 5, sprintf(
            __('Documento generato il %s alle %s'),
            format_date($now->format('Y-m-d'), false, '/'),
            $now->format('H:i')
        ), 0, 1, 'C');
    }
}
