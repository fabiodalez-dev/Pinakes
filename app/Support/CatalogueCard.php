<?php
declare(strict_types=1);

namespace App\Support;

use TCPDF;

/**
 * Catalogue cards: the 125 × 75 mm index card of a card catalogue, written in
 * ISBD style.
 *
 *   call number              (top left: shelf mark or Dewey class)
 *   Main entry heading       (principal author, "Surname, Forename"; the
 *                             title when there is no author)
 *       Title : subtitle / statement of responsibility. – Edition.
 *       – Place : Publisher, Year. – Extent ; dimensions. – (Series ; n.)
 *       ISBN …
 *       1. Subject. 2. Subject. I. Title.
 *
 * lines() is pure (it only reads the book row, as returned by
 * BookRepository::getById()), so the ISBD assembly is unit-tested without a PDF.
 */
final class CatalogueCard
{
    public const WIDTH_MM = 125.0;
    public const HEIGHT_MM = 75.0;
    /** ISBD area separator. */
    private const AREA = '. – ';

    /**
     * @param array<string, mixed> $book row from BookRepository::getById()
     * @return array{call:string, heading:string, body:string, isbn:string, tracings:string, inventory:string}
     */
    public static function lines(array $book): array
    {
        $authors = self::names($book['autori'] ?? []);
        $title = self::clean($book['titolo'] ?? '');
        $subtitle = self::clean($book['sottotitolo'] ?? '');

        $heading = '';
        $principal = is_array($book['autori'] ?? null) ? ($book['autori'][0] ?? null) : null;
        if (is_array($principal)) {
            $heading = AuthorName::citation($principal);
        }

        // Area 1: title and statement of responsibility
        $area1 = $title;
        if ($subtitle !== '') {
            $area1 .= ' : ' . $subtitle;
        }
        $responsibility = [];
        if ($authors !== []) {
            $responsibility[] = implode(', ', $authors);
        }
        $roles = [
            'traduttori' => __('traduzione di'),
            'illustratori' => __('illustrazioni di'),
            'curatori' => __('a cura di'),
        ];
        foreach ($roles as $key => $phrase) {
            $names = self::names($book[$key] ?? []);
            if ($names !== []) {
                $responsibility[] = $phrase . ' ' . implode(', ', $names);
            }
        }
        if ($responsibility !== []) {
            $area1 .= ' / ' . implode(' ; ', $responsibility);
        }
        $areas = [$area1];

        // Area 2: edition
        $edition = self::clean($book['edizione'] ?? '');
        if ($edition !== '') {
            $areas[] = $edition;
        }

        // Area 4: publication
        $place = self::clean($book['luogo_pubblicazione'] ?? '');
        $publishers = [];
        foreach ((array) ($book['editori'] ?? []) as $pub) {
            $name = is_array($pub) ? self::clean($pub['nome'] ?? '') : '';
            if ($name !== '') {
                $publishers[] = $name;
            }
        }
        if ($publishers === [] && self::clean($book['editore_nome'] ?? '') !== '') {
            $publishers[] = self::clean($book['editore_nome']);
        }
        $year = self::clean($book['anno_pubblicazione'] ?? '');
        $publication = '';
        if ($place !== '') {
            $publication = $place;
        }
        if ($publishers !== []) {
            $publication .= ($publication !== '' ? ' : ' : '') . implode(' : ', $publishers);
        }
        if ($year !== '') {
            $publication .= ($publication !== '' ? ', ' : '') . $year;
        }
        if ($publication !== '') {
            $areas[] = $publication;
        }

        // Area 5: physical description
        $pages = (int) ($book['numero_pagine'] ?? 0);
        $physical = $pages > 0 ? $pages . ' p.' : '';
        $dimensions = self::clean($book['dimensioni'] ?? '');
        if ($dimensions !== '') {
            $physical .= ($physical !== '' ? ' ; ' : '') . $dimensions;
        }
        if ($physical !== '') {
            $areas[] = $physical;
        }

        // Area 6: series
        $series = self::clean($book['collana'] ?? '');
        if ($series !== '') {
            $number = self::clean($book['numero_serie'] ?? '');
            $areas[] = '(' . $series . ($number !== '' ? ' ; ' . $number : '') . ')';
        }

        // ". – " between areas; an area already ending with a full stop
        // (an abbreviation such as "ed.") does not get a second one.
        $body = '';
        foreach ($areas as $i => $area) {
            if ($i > 0) {
                $body .= str_ends_with($body, '.') ? ' – ' : self::AREA;
            }
            $body .= $area;
        }
        $body = rtrim($body, '.') . '.';

        $isbn = self::clean($book['isbn13'] ?? '') ?: self::clean($book['isbn10'] ?? '');
        $isbnLine = $isbn !== '' ? 'ISBN ' . $isbn : '';
        $ean = self::clean($book['ean'] ?? '');
        if ($ean !== '' && $ean !== $isbn) {
            $isbnLine .= ($isbnLine !== '' ? self::AREA : '') . 'EAN ' . $ean;
        }

        // Tracings: subjects (genre, sub-genre, keywords) then the title added entry
        $subjects = [];
        foreach (['genere_nome', 'sottogenere_nome'] as $key) {
            $value = self::clean($book[$key] ?? '');
            if ($value !== '') {
                $subjects[mb_strtolower($value)] ??= $value;
            }
        }
        foreach (explode(',', (string) ($book['parole_chiave'] ?? '')) as $keyword) {
            $keyword = self::clean($keyword);
            if ($keyword !== '') {
                $subjects[mb_strtolower($keyword)] ??= $keyword;
            }
        }
        $tracings = [];
        $n = 1;
        foreach ($subjects as $subject) {
            $tracings[] = $n++ . '. ' . rtrim($subject, '.') . '.';
        }
        if ($heading !== '' && $title !== '') {
            $tracings[] = 'I. ' . __('Titolo') . '.';
        }

        $call = self::clean($book['collocazione'] ?? '');
        $dewey = self::clean($book['classificazione_dewey'] ?? '');
        if ($call === '') {
            $call = $dewey;
        } elseif ($dewey !== '') {
            $call .= "\n" . $dewey;
        }

        return [
            'call' => $call,
            'heading' => $heading !== '' ? $heading : $title,
            'body' => $body,
            'isbn' => $isbnLine,
            'tracings' => implode(' ', $tracings),
            'inventory' => self::clean($book['numero_inventario'] ?? ''),
        ];
    }

    /**
     * One card per page, in the order given.
     *
     * @param list<array<string, mixed>> $books rows from BookRepository::getById()
     */
    public static function render(array $books, string $libraryName = ''): string
    {
        $pdf = new TCPDF('L', 'mm', [self::WIDTH_MM, self::HEIGHT_MM], true, 'UTF-8', false);
        $pdf->SetCreator($libraryName !== '' ? $libraryName : 'Pinakes');
        $pdf->SetAuthor($libraryName !== '' ? $libraryName : 'Pinakes');
        $pdf->SetTitle(__('Schede catalografiche'));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(8, 7, 8);
        $pdf->SetAutoPageBreak(true, 6);

        foreach ($books as $book) {
            $card = self::lines($book);
            $pdf->AddPage();

            // Call number, top left
            $pdf->SetFont('dejavusans', 'B', 8);
            $pdf->MultiCell(30, 0, $card['call'], 0, 'L', false, 0, 8, 7);

            // Main entry heading
            $pdf->SetFont('dejavusans', 'B', 9);
            $pdf->MultiCell(0, 0, $card['heading'], 0, 'L', false, 1, 40, 7);
            $pdf->Ln(1.5);

            // Body, indented as on a traditional card
            $pdf->SetFont('dejavusans', '', 8);
            $pdf->SetX(44);
            $pdf->MultiCell(0, 0, $card['body'], 0, 'L', false, 1, 44);
            if ($card['isbn'] !== '') {
                $pdf->Ln(1);
                $pdf->MultiCell(0, 0, $card['isbn'], 0, 'L', false, 1, 44);
            }
            if ($card['tracings'] !== '') {
                $pdf->Ln(2);
                $pdf->MultiCell(0, 0, $card['tracings'], 0, 'L', false, 1, 44);
            }
            if ($card['inventory'] !== '') {
                $pdf->SetFont('dejavusans', '', 7);
                $pdf->MultiCell(40, 0, __('Inv.') . ' ' . $card['inventory'], 0, 'L', false, 1, 8, self::HEIGHT_MM - 12);
            }
        }

        return $pdf->Output('', 'S');
    }

    /**
     * @param mixed $authors list of author rows (nome, pseudonimo)
     * @return list<string>
     */
    private static function names(mixed $authors): array
    {
        $out = [];
        foreach ((array) $authors as $author) {
            if (is_array($author)) {
                $name = AuthorName::display($author);
                if ($name !== '') {
                    $out[] = $name;
                }
            }
        }
        return $out;
    }

    private static function clean(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        return trim(preg_replace('/\s+/u', ' ', HtmlHelper::decode((string) $value)) ?? '');
    }
}
