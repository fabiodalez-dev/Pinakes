<?php
declare(strict_types=1);

namespace App\Support;

use TCPDF;
use mysqli;
use App\Models\LoanRepository;

/**
 * Helper class for generating PDF receipts for loans
 *
 * Generates professional A4 PDF documents with library branding,
 * loan details, book information, and user data.
 */
class LoanPdfGenerator
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Generate PDF receipt for a loan
     *
     * @param int $loanId Loan ID
     * @return string PDF binary content
     * @throws \Exception If loan not found
     */
    public function generate(int $loanId): string
    {
        // 1. Load loan data with all related information
        $repo = new LoanRepository($this->db);
        $loan = $repo->getById($loanId);

        if (!$loan) {
            throw new \Exception(__('Prestito non trovato'));
        }

        // 2. Get library settings
        $appName = ConfigStore::get('app.name', 'Biblioteca');
        $logoPath = $this->resolveLogoPath();

        // 3. Initialize TCPDF with A4 portrait
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        // 4. Configure document metadata and settings
        $this->configurePdf($pdf, $appName);

        // 5. Add page
        $pdf->AddPage();

        // 6. Render content sections
        $this->renderHeader($pdf, $appName, $logoPath);
        $this->renderLoanDetails($pdf, $loan);
        $this->renderFooter($pdf);

        // 7. Return PDF as binary string
        return $pdf->Output('', 'S');
    }

    /**
     * Configure PDF document metadata and settings
     */
    private function configurePdf(TCPDF $pdf, string $appName): void
    {
        // Document metadata
        $pdf->SetCreator($appName);
        $pdf->SetAuthor($appName);
        $pdf->SetTitle(__('Ricevuta Prestito'));
        $pdf->SetSubject(__('Ricevuta di prestito bibliotecario'));

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set margins (15mm all around)
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
    }

    /**
     * Resolve absolute path to library logo
     *
     * @return string|null Absolute path to logo file, or null if not found
     */
    private function resolveLogoPath(): ?string
    {
        $logoRelative = Branding::fullLogo(); // e.g., '/assets/brand/logo.png'

        // Handle absolute URLs (TCPDF supports them directly)
        if ($logoRelative && preg_match('/^https?:\/\//i', $logoRelative)) {
            return $logoRelative;
        }

        $publicDir = realpath(__DIR__ . '/../../public');

        if ($logoRelative && $publicDir) {
            $logoAbsolute = $publicDir . $logoRelative;
            if (file_exists($logoAbsolute)) {
                return $logoAbsolute;
            }
        }

        return null;
    }

    /**
     * Render PDF header with logo and library name
     */
    private function renderHeader(TCPDF $pdf, string $appName, ?string $logoPath): void
    {
        // Logo (scaled to fit max 60x25mm)
        if ($logoPath) {
            // Only use getimagesize on local files to avoid HTTP blocking
            $isLocal = !preg_match('/^https?:\/\//i', $logoPath);
            $imageInfo = $isLocal ? @getimagesize($logoPath) : false;
            if ($imageInfo && $imageInfo[0] > 0 && $imageInfo[1] > 0) {
                $width = $imageInfo[0];
                $height = $imageInfo[1];
                $aspectRatio = $width / $height;

                // Calculate scaled dimensions
                if ($aspectRatio > 2.4) {
                    // Wide logo
                    $logoWidth = 60;
                    $logoHeight = 60 / $aspectRatio;
                } else {
                    // Square/tall logo
                    $logoHeight = 25;
                    $logoWidth = 25 * $aspectRatio;
                }

                $logoX = (210 - $logoWidth) / 2; // Center on A4 page
                $pdf->Image($logoPath, $logoX, 15, $logoWidth, $logoHeight, '', '', '', false, 300, '', false, false, 0);
                $pdf->SetY(15 + $logoHeight + 5);
            } elseif (!$isLocal) {
                // Remote logo: use default dimensions, let TCPDF fetch the URL
                $logoWidth = 40;
                $logoHeight = 20;
                $logoX = (210 - $logoWidth) / 2;
                $pdf->Image($logoPath, $logoX, 15, $logoWidth, $logoHeight, '', '', '', false, 300, '', false, false, 0);
                $pdf->SetY(15 + $logoHeight + 5);
            } else {
                $pdf->SetY(15);
            }
        } else {
            $pdf->SetY(15);
        }

        // Library name (centered, bold, large)
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 10, $appName, 0, 1, 'C');

        // Document title (centered, regular, medium)
        $pdf->SetFont('helvetica', '', 14);
        $pdf->Cell(0, 8, __('Ricevuta di Prestito'), 0, 1, 'C');

        // Separator line
        $pdf->Ln(3);
        $pdf->SetLineWidth(0.5);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(5);
    }

    /**
     * Render loan details in organized sections
     */
    private function renderLoanDetails(TCPDF $pdf, array $loan): void
    {
        $pdf->SetFont('helvetica', '', 10);

        // Section 1: Loan Information
        $this->renderSection($pdf, __('Informazioni Prestito'), [
            __('ID Prestito:') => $loan['id'],
            __('Data Prestito:') => format_date($loan['data_prestito'], false, '/'),
            __('Data Scadenza:') => format_date($loan['data_scadenza'], false, '/'),
            __('Stato:') => $this->translateStatus($loan['stato']),
        ]);

        $pdf->Ln(3);

        // Section 2: Book Information
        $this->renderSection($pdf, __('Dettagli Libro'), [
            __('Titolo:') => $loan['libro'] ?? __('Non disponibile'),
            __('Autori:') => $loan['autori'] ?? __('Non specificato'),
            __('ISBN:') => $loan['isbn13'] ?? $loan['isbn10'] ?? __('Non disponibile'),
        ]);

        $pdf->Ln(3);

        // Section 3: User Information
        $userFields = [
            __('Nome:') => $loan['utente'] ?? __('Non disponibile'),
        ];
        if (!empty($loan['utente_tessera'])) {
            $userFields[__('Codice Tessera:')] = $loan['utente_tessera'];
        }
        $userFields[__('Email:')] = $loan['utente_email'] ?? '';
        if (!empty($loan['utente_telefono'])) {
            $userFields[__('Telefono:')] = $loan['utente_telefono'];
        }
        if (!empty($loan['utente_indirizzo'])) {
            $userFields[__('Indirizzo:')] = $loan['utente_indirizzo'];
        }
        $this->renderSection($pdf, __('Dati Utente'), $userFields);

        $pdf->Ln(3);

        // Section 4: Notes (if present)
        if (!empty($loan['note'])) {
            $this->renderSection($pdf, __('Note'), [
                '' => $loan['note']
            ]);
        }
    }

    /**
     * Render a section with title and field-value pairs
     */
    private function renderSection(TCPDF $pdf, string $title, array $fields): void
    {
        // Section title (gray background)
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(0, 8, $title, 0, 1, 'L', true);
        $pdf->Ln(2);

        // Fields (label: value pairs)
        $pdf->SetFont('helvetica', '', 10);
        foreach ($fields as $label => $value) {
            if ($label === '') {
                // Special case: no label (e.g., notes)
                $pdf->MultiCell(0, 5, (string)$value, 0, 'L');
            } else {
                // Label in first column (60mm), value in remaining space
                $pdf->Cell(60, 6, $label, 0, 0, 'L');
                $pdf->MultiCell(0, 6, (string)$value, 0, 'L');
            }
        }
    }

    /**
     * Render PDF footer with generation timestamp
     */
    private function renderFooter(TCPDF $pdf): void
    {
        $pdf->Ln(10);

        // Separator line
        $pdf->SetLineWidth(0.3);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(3);

        // Generation timestamp (centered, small, gray)
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(128, 128, 128);
        $tz = ConfigStore::get('app.timezone', 'Europe/Rome');
        try {
            $timezone = new \DateTimeZone($tz);
        } catch (\Exception $e) {
            $timezone = new \DateTimeZone('Europe/Rome');
        }
        $now = new \DateTime('now', $timezone);
        $generatedAt = __('Documento generato il %s alle %s', format_date($now->format('Y-m-d'), false, '/'), $now->format('H:i'));
        $pdf->Cell(0, 5, $generatedAt, 0, 1, 'C');
    }

    /**
     * Translate loan status to localized string
     */
    private function translateStatus(string $status): string
    {
        return translate_loan_status($status);
    }
}
