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

    /**
     * Create a new LoanPdfGenerator with the given database connection.
     *
     * Stores the provided mysqli instance for repository access during PDF generation.
     */
    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Generate a PDF receipt for the specified loan.
     *
     * @param int $loanId The loan identifier.
     * @return string The PDF document as a binary string.
     * @throws \Exception If no loan exists for the given ID.
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
     * Configure document metadata and basic PDF settings for the receipt.
     *
     * Sets creator/author/title/subject, disables the default header and footer, and applies 15mm page margins with automatic page breaks using a 15mm bottom margin.
     *
     * @param TCPDF $pdf PDF instance to configure.
     * @param string $appName Application name used for creator/author metadata.
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
     * Resolve the absolute filesystem path to the library logo.
     *
     * @return string|null The absolute path to the logo file if it exists, or `null` if no logo is available.
     */
    private function resolveLogoPath(): ?string
    {
        $publicDir = realpath(__DIR__ . '/../../public');
        $logoRelative = Branding::fullLogo(); // e.g., '/assets/brand/logo.png'

        if ($logoRelative && $publicDir) {
            $logoAbsolute = $publicDir . $logoRelative;
            if (file_exists($logoAbsolute)) {
                return $logoAbsolute;
            }
        }

        return null;
    }

    /**
     * Render the document header including an optional centered logo, the library name, a document title, and a separator line.
     *
     * If a logo path is provided, the image is centered and scaled to fit within a 60×25mm area before placing subsequent header content.
     *
     * @param TCPDF $pdf The TCPDF instance used for rendering.
     * @param string $appName The library/application name to display prominently.
     * @param string|null $logoPath Absolute path to the logo image file, or `null` to omit the logo.
     */
    private function renderHeader(TCPDF $pdf, string $appName, ?string $logoPath): void
    {
        // Logo (scaled to fit max 60x25mm)
        if ($logoPath) {
            list($width, $height) = getimagesize($logoPath);
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
     * Render the loan, book, user and optional notes sections into the provided PDF.
     *
     * Sections rendered:
     * - Informazioni Prestito: `id`, `data_prestito`, `data_scadenza`, localized `stato`.
     * - Dettagli Libro: `libro`, `autori`, `isbn13` (falls back to `isbn10`).
     * - Dati Utente: `utente`, `utente_email`.
     * - Note: rendered when `note` is non-empty.
     *
     * @param \TCPDF $pdf The TCPDF document to render into.
     * @param array $loan Associative array with loan data. Expected keys:
     *                    - 'id'
     *                    - 'data_prestito'
     *                    - 'data_scadenza'
     *                    - 'stato'
     *                    - 'libro' (optional)
     *                    - 'autori' (optional)
     *                    - 'isbn13' (optional)
     *                    - 'isbn10' (optional)
     *                    - 'utente' (optional)
     *                    - 'utente_email' (optional)
     *                    - 'note' (optional)
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
        $this->renderSection($pdf, __('Dati Utente'), [
            __('Nome:') => $loan['utente'] ?? __('Non disponibile'),
            __('Email:') => $loan['utente_email'] ?? '',
        ]);

        $pdf->Ln(3);

        // Section 4: Notes (if present)
        if (!empty($loan['note'])) {
            $this->renderSection($pdf, __('Note'), [
                '' => $loan['note']
            ]);
        }
    }

    /**
     * Render a titled section containing label/value rows.
     *
     * Renders a section header with a gray background followed by each field as
     * a label/value pair. When a field's label is the empty string, the value is
     * rendered as a single full-width block (used for free-form notes). Otherwise
     * labels occupy a fixed 60mm column on the left and values flow/wrap in the
     * remaining space.
     *
     * @param TCPDF $pdf The TCPDF instance to draw into.
     * @param string $title The section title shown in the gray header.
     * @param array<string,mixed> $fields Associative array of `label => value` pairs;
     *                                   use an empty string as the label to render
     *                                   a full-width value block.
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
     * Render the document footer containing a separator line and a centered generation timestamp.
     *
     * The timestamp displays the generation date and time in the format `dd/mm/YYYY` and `HH:MM`.
     *
     * @param TCPDF $pdf The PDF document to render the footer into.
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
        $generatedAt = __('Documento generato il %s alle %s', date('d/m/Y'), date('H:i'));
        $pdf->Cell(0, 5, $generatedAt, 0, 1, 'C');
    }

    /**
     * Return the localized label for a loan status key.
     *
     * @param string $status Internal loan status key (e.g. 'pendente', 'in_corso', 'restituito').
     * @return string The localized status label; returns the original `$status` if no localization is available.
     */
    private function translateStatus(string $status): string
    {
        return match ($status) {
            'pendente' => __('Pendente'),
            'prenotato' => __('Prenotato'),
            'da_ritirare' => __('Da Ritirare'),
            'in_corso' => __('In Corso'),
            'in_ritardo' => __('In Ritardo'),
            'restituito' => __('Restituito'),
            'perso' => __('Perso'),
            'danneggiato' => __('Danneggiato'),
            'annullato' => __('Annullato'),
            'scaduto' => __('Scaduto'),
            default => $status
        };
    }
}