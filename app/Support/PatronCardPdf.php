<?php
declare(strict_types=1);

namespace App\Support;

use TCPDF;

/**
 * Printable library cards (TCPDF).
 *
 * A card is CR80 (85.6 × 54 mm, the bank-card size). Two output formats:
 *   - `single`: one card per page, the page IS the card (card printers, or a
 *     PDF the operator sends to a print shop);
 *   - `sheet`:  A4 sheets of business-card stock, 10 per sheet (2 × 5) for the
 *     landscape layouts, 9 (3 × 3) for the portrait one, with a hairline
 *     border as cut guide.
 *
 * When a back text is configured, every front page is followed by its back:
 * in `sheet` format the back sheet mirrors the columns, so a long-edge duplex
 * print puts each back behind its own front.
 *
 * Names are user data in any script, so they use the Unicode `dejavusans`
 * font, like every other PDF of the application. The card code — the value the
 * barcode encodes — is printed in core Helvetica, which writes it to the page
 * as plain text (searchable, and checked by the E2E suite with pdftotext).
 */
final class PatronCardPdf
{
    public const CARD_W = 85.6;
    public const CARD_H = 54.0;
    private const FONT = 'dejavusans';
    private const CODE_FONT = 'helvetica';
    private const A4_W = 210.0;
    private const A4_H = 297.0;

    /** @var array{layout:string, code_type:string, format:string, title:string, back_text:string, show_photo:bool, show_logo:bool, show_expiry:bool} */
    private array $settings;
    private string $title;
    private ?string $logoFile;

    /**
     * @param array{layout:string, code_type:string, format:string, title:string, back_text:string, show_photo:bool, show_logo:bool, show_expiry:bool} $settings
     * @param string $appName  fallback card title when the settings leave it empty
     * @param string|null $logoFile absolute path of a local JPEG/PNG logo, or null
     */
    public function __construct(array $settings, string $appName, ?string $logoFile)
    {
        $this->settings = $settings;
        $this->title = $settings['title'] !== '' ? $settings['title'] : $appName;
        $this->logoFile = ($settings['show_logo'] && $logoFile !== null && self::isUsableImage($logoFile)) ? $logoFile : null;
    }

    /**
     * Card size (mm) for the configured layout, as [width, height].
     *
     * @return array{0: float, 1: float}
     */
    public function cardSize(): array
    {
        return $this->settings['layout'] === 'portrait'
            ? [self::CARD_H, self::CARD_W]
            : [self::CARD_W, self::CARD_H];
    }

    /**
     * Slots of one A4 sheet as [columns, rows, left, top] for the layout.
     *
     * @return array{0:int, 1:int, 2:float, 3:float}
     */
    public function sheetGrid(): array
    {
        [$w, $h] = $this->cardSize();
        $cols = (int) floor(self::A4_W / $w);
        $rows = (int) floor((self::A4_H - 20) / $h);
        $cols = max(1, min($cols, 3));
        $rows = max(1, $rows);
        $left = (self::A4_W - $cols * $w) / 2;
        $top = (self::A4_H - $rows * $h) / 2;
        return [$cols, $rows, $left, $top];
    }

    /**
     * @param list<array{name:string, code:string, expiry:string, photo:?string}> $cards
     * @param string $format 'single' or 'sheet'
     * @return string the PDF bytes
     */
    public function render(array $cards, string $format): string
    {
        [$w, $h] = $this->cardSize();
        $portrait = $h > $w;
        $hasBack = $this->settings['back_text'] !== '';

        if ($format === 'sheet') {
            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        } else {
            $pdf = new TCPDF($portrait ? 'P' : 'L', 'mm', [$w, $h], true, 'UTF-8', false);
        }
        $pdf->SetCreator($this->title);
        $pdf->SetAuthor($this->title);
        $pdf->SetTitle(__('Tessere della biblioteca'));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->setCellPaddings(0, 0, 0, 0);

        if ($format === 'sheet') {
            [$cols, $rows, $left, $top] = $this->sheetGrid();
            $perSheet = $cols * $rows;
            foreach (array_chunk($cards, $perSheet) as $sheet) {
                $pdf->AddPage();
                foreach ($sheet as $i => $card) {
                    $col = $i % $cols;
                    $row = intdiv($i, $cols);
                    $x = $left + $col * $w;
                    $y = $top + $row * $h;
                    $this->cutGuide($pdf, $x, $y, $w, $h);
                    $this->front($pdf, $x, $y, $w, $h, $card);
                }
                if ($hasBack) {
                    $pdf->AddPage();
                    foreach ($sheet as $i => $card) {
                        // Mirror the columns: on a long-edge duplex print the
                        // back of column c lands behind column (cols-1-c).
                        $col = $cols - 1 - ($i % $cols);
                        $row = intdiv($i, $cols);
                        $x = $left + $col * $w;
                        $y = $top + $row * $h;
                        $this->cutGuide($pdf, $x, $y, $w, $h);
                        $this->back($pdf, $x, $y, $w, $h);
                    }
                }
            }
        } else {
            foreach ($cards as $card) {
                $pdf->AddPage();
                $this->front($pdf, 0, 0, $w, $h, $card);
                if ($hasBack) {
                    $pdf->AddPage();
                    $this->back($pdf, 0, 0, $w, $h);
                }
            }
        }

        if ($cards === []) {
            $pdf->AddPage();
        }

        return $pdf->Output('', 'S');
    }

    /** @param array{name:string, code:string, expiry:string, photo:?string} $card */
    private function front(TCPDF $pdf, float $x, float $y, float $w, float $h, array $card): void
    {
        switch ($this->settings['layout']) {
            case 'minimal':
                $this->frontMinimal($pdf, $x, $y, $w, $h, $card);
                break;
            case 'portrait':
                $this->frontPortrait($pdf, $x, $y, $w, $h, $card);
                break;
            default:
                $this->frontClassic($pdf, $x, $y, $w, $h, $card);
        }
    }

    /**
     * Header band with logo and title; photo on the left; name, code and expiry
     * on the right; Code128 across the bottom of the text column, or a QR code
     * at its right.
     *
     * @param array{name:string, code:string, expiry:string, photo:?string} $card
     */
    private function frontClassic(TCPDF $pdf, float $x, float $y, float $w, float $h, array $card): void
    {
        $band = 11.0;
        $this->band($pdf, $x, $y, $w, $band);

        $pad = 3.0;
        $top = $y + $band + 3;
        $textX = $x + $pad;
        if ($this->settings['show_photo']) {
            $this->photo($pdf, $x + $pad, $top, 22, 28, $card);
            $textX = $x + $pad + 22 + 3;
        }
        $textW = $x + $w - $pad - $textX;
        $qr = $this->settings['code_type'] === 'qr';
        $qrSize = 20.0;
        if ($qr) {
            $textW -= $qrSize + 2;
        }

        $this->fitText($pdf, $textX, $top, $textW, 6, $card['name'], 'B', 11);
        $this->codeLine($pdf, $textX, $top + 7, $textW, $card['code'], 9);
        if ($this->settings['show_expiry'] && $card['expiry'] !== '') {
            $this->fitText($pdf, $textX, $top + 12, $textW, 4, __('Scadenza') . ': ' . $card['expiry'], '', 7, [80, 80, 80]);
        }

        if ($qr) {
            $this->qr($pdf, $x + $w - $pad - $qrSize, $top, $qrSize, $card['code']);
        } else {
            $this->barcode($pdf, $textX, $y + $h - $pad - 11, $textW, 11, $card['code']);
        }
    }

    /**
     * No band and no photo: logo and title small at the top, the name large,
     * the code as a wide barcode (or a QR on the right).
     *
     * @param array{name:string, code:string, expiry:string, photo:?string} $card
     */
    private function frontMinimal(TCPDF $pdf, float $x, float $y, float $w, float $h, array $card): void
    {
        $pad = 4.0;
        $titleX = $x + $pad;
        if ($this->logoFile !== null) {
            $logoW = $this->logoWidth(6, $w * 0.35);
            $this->image($pdf, $this->logoFile, $x + $pad, $y + $pad, $logoW, 6, true);
            $titleX = $x + $pad + $logoW + 2;
        }
        $this->fitText($pdf, $titleX, $y + $pad + 0.5, $x + $w - $pad - $titleX, 5, $this->title, 'B', 8, [70, 70, 70]);

        $qr = $this->settings['code_type'] === 'qr';
        $qrSize = 22.0;
        $textW = $w - 2 * $pad - ($qr ? $qrSize + 3 : 0);
        $this->fitText($pdf, $x + $pad, $y + 15, $textW, 8, $card['name'], 'B', 14);
        if ($this->settings['show_expiry'] && $card['expiry'] !== '') {
            $this->fitText($pdf, $x + $pad, $y + 24, $textW, 4, __('Scadenza') . ': ' . $card['expiry'], '', 7, [80, 80, 80]);
        }
        if ($qr) {
            $this->qr($pdf, $x + $w - $pad - $qrSize, $y + 14, $qrSize, $card['code']);
            $this->codeLine($pdf, $x + $pad, $y + 30, $textW, $card['code'], 10);
        } else {
            $this->barcode($pdf, $x + $pad, $y + 30, $w - 2 * $pad, 13, $card['code']);
            $this->codeLine($pdf, $x + $pad, $y + 44, $w - 2 * $pad, $card['code'], 9, 'C');
        }
    }

    /**
     * Vertical card: band on top, photo centred, then name, code and expiry.
     *
     * @param array{name:string, code:string, expiry:string, photo:?string} $card
     */
    private function frontPortrait(TCPDF $pdf, float $x, float $y, float $w, float $h, array $card): void
    {
        $band = 12.0;
        $this->band($pdf, $x, $y, $w, $band);
        $pad = 3.0;
        $cursor = $y + $band + 3;
        if ($this->settings['show_photo']) {
            $this->photo($pdf, $x + ($w - 26) / 2, $cursor, 26, 32, $card);
            $cursor += 34;
        } else {
            $cursor += 4;
        }
        $this->fitText($pdf, $x + $pad, $cursor, $w - 2 * $pad, 6, $card['name'], 'B', 10, [0, 0, 0], 'C');
        $cursor += 7;
        if ($this->settings['code_type'] === 'qr') {
            $this->qr($pdf, $x + ($w - 17) / 2, $cursor, 17, $card['code']);
            $cursor += 18;
        } else {
            $this->barcode($pdf, $x + $pad + 2, $cursor, $w - 2 * $pad - 4, 10, $card['code']);
            $cursor += 11;
        }
        $this->codeLine($pdf, $x + $pad, $cursor, $w - 2 * $pad, $card['code'], 8, 'C');
        if ($this->settings['show_expiry'] && $card['expiry'] !== '') {
            $this->fitText($pdf, $x + $pad, $y + $h - $pad - 4, $w - 2 * $pad, 4, __('Scadenza') . ': ' . $card['expiry'], '', 7, [80, 80, 80], 'C');
        }
    }

    private function back(TCPDF $pdf, float $x, float $y, float $w, float $h): void
    {
        $pad = 4.0;
        $this->fitText($pdf, $x + $pad, $y + $pad, $w - 2 * $pad, 5, $this->title, 'B', 8);
        $areaY = $y + $pad + 6;
        $areaH = $h - 2 * $pad - 6;
        $areaW = $w - 2 * $pad;
        $text = $this->settings['back_text'];
        $size = 7.0;
        $pdf->SetFont(self::FONT, '', $size);
        while ($size > 4.5 && $pdf->getStringHeight($areaW, $text) > $areaH) {
            $size -= 0.25;
            $pdf->SetFont(self::FONT, '', $size);
        }
        $pdf->SetTextColor(40, 40, 40);
        $pdf->MultiCell($areaW, $areaH, $text, 0, 'L', false, 1, $x + $pad, $areaY, true, 0, false, true, $areaH, 'T', true);
        $pdf->SetTextColor(0, 0, 0);
    }

    private function band(TCPDF $pdf, float $x, float $y, float $w, float $hBand): void
    {
        $pdf->SetFillColor(31, 41, 55);
        $pdf->Rect($x, $y, $w, $hBand, 'F');
        $titleX = $x + 3;
        if ($this->logoFile !== null) {
            $logoW = $this->logoWidth($hBand - 3, $w * 0.35);
            $this->image($pdf, $this->logoFile, $x + 3, $y + 1.5, $logoW, $hBand - 3, true);
            $titleX = $x + 3 + $logoW + 2;
        }
        $this->fitText($pdf, $titleX, $y + ($hBand - 5) / 2, $x + $w - 3 - $titleX, 5, $this->title, 'B', 9, [255, 255, 255]);
    }

    /** @param array{name:string, code:string, expiry:string, photo:?string} $card */
    private function photo(TCPDF $pdf, float $x, float $y, float $w, float $h, array $card): void
    {
        $file = $card['photo'];
        if ($file !== null && self::isUsableImage($file)) {
            // Fit the photo in the box, centred, keeping its proportions.
            $this->image($pdf, $file, $x, $y, $w, $h, true);
            $pdf->SetDrawColor(200, 200, 200);
            $pdf->Rect($x, $y, $w, $h, 'D');
            return;
        }
        $pdf->SetFillColor(229, 231, 235);
        $pdf->Rect($x, $y, $w, $h, 'F');
        $initials = '';
        foreach (preg_split('/\s+/u', trim($card['name'])) ?: [] as $part) {
            if ($part !== '' && mb_strlen($initials) < 2) {
                $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            }
        }
        $pdf->SetFont(self::FONT, 'B', 16);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->MultiCell($w, $h, $initials, 0, 'C', false, 1, $x, $y, true, 0, false, true, $h, 'M');
        $pdf->SetTextColor(0, 0, 0);
    }

    private function barcode(TCPDF $pdf, float $x, float $y, float $w, float $h, string $code): void
    {
        if ($code === '' || preg_match('/^[\x20-\x7E]+$/', $code) !== 1) {
            return; // Code128 encodes printable ASCII only; the text line still shows the code.
        }
        $pdf->write1DBarcode($code, 'C128', $x, $y, $w, $h, 0.4, [
            'stretch' => true,
            'fitwidth' => false,
            'border' => false,
            'text' => false,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => false,
        ], 'N');
    }

    private function qr(TCPDF $pdf, float $x, float $y, float $size, string $code): void
    {
        if ($code === '') {
            return;
        }
        $pdf->write2DBarcode($code, 'QRCODE,M', $x, $y, $size, $size, [
            'border' => false,
            'padding' => 0,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => false,
        ], 'N');
    }

    /** The card code as text: Helvetica when it is plain ASCII, the Unicode font otherwise. */
    private function codeLine(TCPDF $pdf, float $x, float $y, float $w, string $code, float $size, string $align = 'L'): void
    {
        $font = preg_match('/^[\x20-\x7E]*$/', $code) === 1 ? self::CODE_FONT : self::FONT;
        $pdf->SetFont($font, 'B', $size);
        while ($size > 5 && $pdf->GetStringWidth($code) > $w) {
            $size -= 0.5;
            $pdf->SetFont($font, 'B', $size);
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, 4, $code, 0, 0, $align, false, '', 0, false, 'T', 'T');
    }

    /** @param array{0:int,1:int,2:int} $color */
    private function fitText(TCPDF $pdf, float $x, float $y, float $w, float $h, string $text, string $style, float $size, array $color = [0, 0, 0], string $align = 'L'): void
    {
        if ($text === '' || $w <= 1) {
            return;
        }
        $pdf->SetFont(self::FONT, $style, $size);
        while ($size > 5 && $pdf->GetStringWidth($text) > $w) {
            $size -= 0.5;
            $pdf->SetFont(self::FONT, $style, $size);
        }
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->SetXY($x, $y);
        // Cell with stretch 0 and no fitting beyond the font loop: a name
        // longer than the minimum font allows is clipped, never overprinted.
        $pdf->Cell($w, $h, $text, 0, 0, $align, false, '', 1, false, 'T', 'T');
        $pdf->SetTextColor(0, 0, 0);
    }

    /** Width (mm) the logo takes at height $h, from its pixel proportions, capped at $max. */
    private function logoWidth(float $h, float $max): float
    {
        $info = $this->logoFile !== null ? @getimagesize($this->logoFile) : false;
        if ($info === false || $info[1] <= 0) {
            return $h;
        }
        return min($max, $h * ($info[0] / $info[1]));
    }

    private function cutGuide(TCPDF $pdf, float $x, float $y, float $w, float $h): void
    {
        $pdf->SetLineWidth(0.1);
        $pdf->SetDrawColor(190, 190, 190);
        $pdf->Rect($x, $y, $w, $h, 'D');
    }

    private function image(TCPDF $pdf, string $file, float $x, float $y, float $w, float $h, bool $fit = false): void
    {
        try {
            $pdf->Image($file, $x, $y, $w, $h, '', '', '', true, 300, '', false, false, 0, $fit ? 'CM' : false);
        } catch (\Throwable $e) {
            SecureLogger::warning('[PatronCardPdf] image skipped: ' . $e->getMessage());
        }
    }

    /** TCPDF stops the request on an unreadable image, so only proven JPEG/PNG files reach it. */
    private static function isUsableImage(string $file): bool
    {
        if (!is_file($file) || !is_readable($file)) {
            return false;
        }
        $info = @getimagesize($file);
        return $info !== false && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true);
    }
}
