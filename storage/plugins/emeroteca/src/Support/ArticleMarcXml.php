<?php
declare(strict_types=1);
namespace App\Plugins\Emeroteca\Support;
require_once __DIR__ . '/CitationFormatter.php';

/** MARC 21 analytic record; a host description never implies owned serial holdings. */
final class ArticleMarcXml
{
    /** @param array<string,mixed> $row */
    public static function format(array $row, string $recordUrl = '', string $hostControlNumber = ''): string
    {
        $parts = CitationFormatter::parts($row);
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElementNs(null, 'record', 'http://www.loc.gov/MARC21/slim');
        // LDR/06 language material, /07 monographic component (a single article).
        $xml->writeElement('leader', '00000naa a22000007  4500');
        $xml->startElement('controlfield');
        $xml->writeAttribute('tag', '001');
        $xml->text('article:' . (string)($row['reference_key'] ?? $row['id'] ?? ''));
        $xml->endElement();
        // Unknown positions stay unspecified; ISO country codes are not MARC country codes.
        $fixed = str_repeat('|', 40);
        if (preg_match('/^\d{4}$/D', $parts['year'])) {
            $fixed = substr_replace($fixed, 's'.$parts['year'].'    ', 6, 9);
        }
        if (preg_match('/^[a-z]{3}$/D', $parts['language'])) {
            $fixed = substr_replace($fixed, $parts['language'], 35, 3);
        }
        $xml->startElement('controlfield');
        $xml->writeAttribute('tag', '008');
        $xml->text($fixed);
        $xml->endElement();
        $field = static function(string $tag, array $values, string $ind1 = ' ', string $ind2 = ' ') use ($xml): void {
            $values = array_filter($values, static fn($value) => is_array($value) ? $value !== [] : ($value !== null && trim((string)$value) !== ''));
            if ($values === []) { return; }
            $xml->startElement('datafield');
            $xml->writeAttribute('tag', $tag);
            $xml->writeAttribute('ind1', $ind1);
            $xml->writeAttribute('ind2', $ind2);
            foreach ($values as $code => $value) {
                foreach (is_array($value) ? $value : [$value] as $part) {
                    $xml->startElement('subfield');
                    $xml->writeAttribute('code', (string)$code);
                    $xml->text((string)$part);
                    $xml->endElement();
                }
            }
            $xml->endElement();
        };
        $credits = $row['author_credits'] ?? [];
        if ($credits === []) {
            $credits = array_map(static fn($name) => ['nome_credito'=>$name], $parts['authors']);
        }
        $primary = null;
        foreach ($credits as $i => $credit) {
            if (($credit['ruolo'] ?? '') === 'principale') { $primary = $i; break; }
        }
        $primary ??= $credits === [] ? null : array_key_first($credits);
        foreach ($credits as $i => $credit) {
            $author = (string)$credit['nome_credito'];
            $field($i === $primary ? '100' : '700', ['a'=>$author, '0'=>$credit['identifiers'] ?? []], str_contains($author, ',') ? '1' : '0');
        }
        $field('245', ['a'=>$row['titolo'] ?? '', 'b'=>$row['sottotitolo'] ?? '', 'c'=>$row['autori'] ?? ''], $parts['authors'] === [] ? '0' : '1', '0');
        $field('300', ['a'=>$row['pagine'] ?? '']);
        $enumeration = array_filter([
            $parts['volume'] !== '' ? 'Vol. '.$parts['volume'] : '',
            $parts['issue'] !== '' ? 'No. '.$parts['issue'] : '',
            !empty($row['data_pubblicazione_testo']) ? $row['data_pubblicazione_testo'] : $parts['year'],
            !empty($row['pagine']) ? 'pp. '.$row['pagine'] : '',
        ], static fn($v) => $v !== '');
        $field('773', ['t'=>$parts['container'], 'x'=>$parts['issn'], 'g'=>implode(', ', $enumeration), 'w'=>$hostControlNumber], '0');
        $field('041', ['a'=>$parts['language']], '0');
        $field('084', ['a'=>$row['classificazione'] ?? '', '2'=>strtolower((string)($row['classificazione_schema'] ?? ''))]);
        $field('520', ['a'=>$parts['abstract']]);
        foreach ($parts['keywords'] as $keyword) { $field('653', ['a'=>$keyword]); }
        if ($parts['doi'] !== '') { $field('024', ['a'=>$parts['doi'], '2'=>'doi'], '7'); }
        $field('856', ['u'=>$recordUrl], '4', '0');
        // Public exports never disclose private notes, file paths or access URLs.
        if (!empty($row['risorsa_pubblica'])) {
            $field('856', ['u'=>$row['risorsa_url'] ?? '', 'y'=>$row['risorsa_testo'] ?? '', 'z'=>$row['risorsa_accesso'] ?? ''], '4', '0');
        }
        $xml->endElement();
        $xml->endDocument();
        return $xml->outputMemory();
    }
}
