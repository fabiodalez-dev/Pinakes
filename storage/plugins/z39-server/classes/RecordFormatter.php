<?php
/**
 * Record Formatter Factory
 *
 * Creates appropriate formatter for requested record format.
 * Supports: MARCXML, Dublin Core, MODS, OAI Dublin Core
 */

declare(strict_types=1);

namespace Z39Server;

abstract class RecordFormatter
{
    protected \DOMDocument $doc;

    public function __construct(\DOMDocument $doc)
    {
        $this->doc = $doc;
    }

    /**
     * Create formatter for specified format
     *
     * @param string $format Format name (marcxml, dc, mods, oai_dc)
     * @param \DOMDocument $doc DOM document
     * @return RecordFormatter Formatter instance
     * @throws \Exception If format is not supported
     */
    public static function create(string $format, \DOMDocument $doc): RecordFormatter
    {
        switch (strtolower($format)) {
            case 'marcxml':
                return new MARCXMLFormatter($doc);

            case 'dc':
            case 'oai_dc':
                return new DublinCoreFormatter($doc);

            case 'mods':
                return new MODSFormatter($doc);

            case 'unimarcxml':
                return new UNIMARCXMLFormatter($doc);

            default:
                throw new \Exception("Unsupported record format: {$format}");
        }
    }

    /**
     * Format record data as XML element
     *
     * @param array $record Record data from database
     * @return \DOMElement Formatted record element
     */
    abstract public function format(array $record): \DOMElement;

    /**
     * Escape XML text
     *
     * @param string $text Text to escape
     * @return string Escaped text
     */
    protected function escapeXml(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Normalize the role-aware contributor payload supplied by SRU/Z39.
     * Older callers that only provide the historic `autori` string retain a
     * compatible principal/co-author representation.
     *
     * @param array<string,mixed> $record
     * @return list<array{nome:string,ruolo:string}>
     */
    protected function contributorRows(array $record): array
    {
        $allowed = ['principale', 'co-autore', 'traduttore', 'illustratore', 'curatore', 'colorista'];
        $rows = [];
        /** @var array<string,array<string,true>> $namesByRole */
        $namesByRole = [];
        if (isset($record['contributors']) && is_array($record['contributors'])) {
            foreach ($record['contributors'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = trim((string) ($row['nome'] ?? $row['name'] ?? ''));
                $role = (string) ($row['ruolo'] ?? 'co-autore');
                if ($name !== '' && in_array($role, $allowed, true)) {
                    $rows[] = ['nome' => $name, 'ruolo' => $role];
                    $namesByRole[$role][mb_strtolower($name, 'UTF-8')] = true;
                }
            }
        }

        // No entity contributors at all: fall back to the historic '; '-joined
        // author string as intellectual creators.
        if ($rows === []) {
            $authors = array_values(array_filter(array_map(
                'trim',
                explode('; ', (string) ($record['autori'] ?? ''))
            )));
            foreach ($authors as $index => $name) {
                $rows[] = ['nome' => $name, 'ruolo' => $index === 0 ? 'principale' : 'co-autore'];
            }
        }

        // Legacy free-text contributor columns (libri.traduttore/illustratore/
        // curatore/colorista) fill gaps left by entity rows. Merge by normalized
        // name AND role: skipping an entire role would lose a second legacy name
        // when only one of its contributors has already been migrated to entities.
        foreach (['traduttore', 'illustratore', 'curatore', 'colorista'] as $role) {
            $raw = trim((string) ($record[$role] ?? ''));
            if ($raw === '') {
                continue;
            }
            foreach (preg_split('/\s*;\s*/u', $raw) ?: [] as $name) {
                $name = trim($name);
                if ($name === '') {
                    continue;
                }
                $normalizedName = mb_strtolower($name, 'UTF-8');
                if (isset($namesByRole[$role][$normalizedName])) {
                    continue;
                }
                $rows[] = ['nome' => $name, 'ruolo' => $role];
                $namesByRole[$role][$normalizedName] = true;
            }
        }

        return $rows;
    }

    /**
     * Every publisher for a record: the batch-fetched primary + secondary
     * co-publishers (#143) when present, otherwise the single primary
     * `editore` string. Keeps Z39/SRU output at parity with web/OAI-PMH.
     *
     * @param array<string,mixed> $record
     * @return list<string>
     */
    protected function publisherNames(array $record): array
    {
        $names = [];
        if (isset($record['publishers']) && is_array($record['publishers'])) {
            foreach ($record['publishers'] as $name) {
                $name = trim((string) $name);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }
        if ($names === []) {
            $primary = trim((string) ($record['editore'] ?? ''));
            if ($primary !== '') {
                $names[] = $primary;
            }
        }
        return $names;
    }

    protected function isCreatorRole(string $role): bool
    {
        return $role === 'principale' || $role === 'co-autore';
    }

    protected function roleTerm(string $role): string
    {
        return match ($role) {
            'traduttore' => 'translator',
            'illustratore' => 'illustrator',
            'curatore' => 'editor',
            'colorista' => 'colorist',
            default => 'author',
        };
    }

    /**
     * Absolutize a stored cover URL so remote SRU/Z39 partners get a resolvable
     * link.
     *
     * #6: covers imported through the app are localized to SITE-RELATIVE paths
     * (/uploads/copertine/...) by LibriController::downloadExternalCover, so a
     * raw value is not resolvable by a remote partner. The web and Mobile API
     * wrap the value in absoluteUrl(); mirror that here. Values that are already
     * absolute (http/https) are emitted unchanged. Shared by every formatter.
     */
    protected function absoluteCoverUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }
        if (function_exists('absoluteUrl')) {
            return \absoluteUrl('/' . ltrim($url, '/'));
        }
        return $url;
    }

    /**
     * Human-readable copy status. Keys must match the copie.stato enum exactly.
     * Shared by every formatter so the enum→label map lives in one place.
     */
    protected function formatCopyStatus(string $status): string
    {
        $statusMap = [
            'disponibile'       => 'Available',
            'prestato'          => 'On loan',
            'prenotato'         => 'Reserved',
            'manutenzione'      => 'Under maintenance',
            'in_restauro'       => 'Under restoration',
            'perso'             => 'Lost',
            'danneggiato'       => 'Damaged',
            'in_trasferimento'  => 'In transit',
        ];

        return $statusMap[$status] ?? ucfirst($status);
    }
}
