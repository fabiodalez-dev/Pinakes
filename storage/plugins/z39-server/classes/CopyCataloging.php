<?php
declare(strict_types=1);

namespace Plugins\Z39Server\Classes;

use App\Support\Marc\MarcBookData;
use App\Support\Marc\MarcBookImporter;

require_once __DIR__ . '/LocalSruClient.php';
require_once __DIR__ . '/SbnClient.php';

/**
 * Interactive copy cataloguing: search other catalogues by title, author,
 * ISBN/ISSN or all fields, look at the records, import the chosen ones.
 *
 * The servers on offer are the SRU servers configured in the plugin, a few
 * national catalogues (SBN through its own API, Library of Congress, BnF,
 * DNB, K10plus) and this installation's own SRU server; another Pinakes is
 * added like any SRU server (its /api/sru, MARCXML). Records are read with the
 * shared MARC library and written by the MARC bulk importer, with its
 * duplicate check (ISBN-13, ISBN-10, EAN) and its cover download (856).
 */
final class CopyCataloging
{
    private const SESSION_KEY = 'copy_cataloging_results';
    private const MAX_RESULTS = 100;

    /** National catalogues offered even when not configured. */
    private const PRESETS = [
        'loc' => [
            'name' => 'Library of Congress',
            'url' => 'http://lx2.loc.gov:210/LCDB',
            'version' => '1.1',
            'syntax' => 'marcxml',
            'indexes' => ['isbn' => 'bath.isbn', 'issn' => 'bath.issn', 'title' => 'dc.title', 'author' => 'dc.creator', 'any' => 'cql.serverChoice'],
        ],
        'bnf' => [
            'name' => 'BnF - Bibliothèque nationale de France',
            'url' => 'https://catalogue.bnf.fr/api/SRU',
            'version' => '1.2',
            'syntax' => 'unimarcxchange',
            'quote_search_terms' => true,
            // bib.fuzzyIsbn matches either form of the ISBN (bib.isbn only
            // the one the record carries: "2070612759" finds nothing while
            // "9782070612758" does).
            'indexes' => ['isbn' => 'bib.fuzzyIsbn', 'issn' => 'bib.issn', 'title' => 'bib.title', 'author' => 'bib.author', 'any' => 'bib.anywhere'],
        ],
        'dnb' => [
            'name' => 'DNB - Deutsche Nationalbibliothek',
            'url' => 'https://services.dnb.de/sru/dnb',
            'version' => '1.1',
            'syntax' => 'MARC21-xml',
            // woe="Kafka Prozess" finds nothing (an exact phrase in a word
            // index); woe all "Kafka Prozess" finds every record with both.
            'indexes' => ['isbn' => 'num', 'issn' => 'num', 'title' => 'tit', 'author' => 'per', 'any' => ['index' => 'woe', 'relation' => 'all']],
        ],
        'k10plus' => [
            'name' => 'K10plus (GBV)',
            'url' => 'http://sru.k10plus.de/opac-de-627',
            'version' => '1.1',
            'syntax' => 'marcxml',
            'indexes' => ['isbn' => 'pica.isb', 'issn' => 'pica.iss', 'title' => 'pica.tit', 'author' => 'pica.per', 'any' => 'pica.all'],
        ],
    ];

    /**
     * @param list<array<string, mixed>> $configured the plugin's `servers` setting
     */
    public function __construct(
        private \mysqli $db,
        private ?int $pluginId,
        private array $configured,
        private bool $sbnEnabled,
        private bool $localServerEnabled,
        private int $timeout = 15,
        /** @var (\Closure(array<string, mixed>): SruClient)|null test seam: the client for one server */
        private ?\Closure $clientFactory = null
    ) {
    }

    /**
     * Every server the page offers, keyed: cfg:N (configured), preset:KEY,
     * sbn, local.
     *
     * @return array<string, array<string, mixed>>
     */
    public function servers(): array
    {
        $out = [];
        $urls = [];
        foreach ($this->configured as $i => $server) {
            if (empty($server['url'])) {
                continue;
            }
            $server['name'] = (string) ($server['name'] ?? $server['url']);
            $server['kind'] = 'configured';
            $out['cfg:' . $i] = $server;
            $urls[] = rtrim(strtolower((string) $server['url']), '/');
        }
        if ($this->sbnEnabled) {
            $out['sbn'] = ['name' => 'SBN - OPAC del Servizio Bibliotecario Nazionale', 'url' => 'https://opac.sbn.it', 'syntax' => 'sbn', 'kind' => 'preset', 'enabled' => true];
        }
        foreach (self::PRESETS as $key => $preset) {
            if (!in_array(rtrim(strtolower($preset['url']), '/'), $urls, true)) {
                $out['preset:' . $key] = $preset + ['kind' => 'preset', 'enabled' => true];
            }
        }
        if ($this->localServerEnabled) {
            $local = function_exists('absoluteUrl') ? \absoluteUrl('/api/sru') : '/api/sru';
            if (!in_array(rtrim(strtolower($local), '/'), $urls, true)) {
                $out['local'] = [
                    'name' => __('Questa installazione (Pinakes)'),
                    'url' => $local,
                    'version' => '1.2',
                    'syntax' => 'marcxml',
                    'kind' => 'local',
                    'enabled' => true,
                    'indexes' => ['isbn' => 'bath.isbn', 'issn' => 'bath.issn', 'title' => 'dc.title', 'author' => 'dc.creator', 'any' => 'cql.anywhere'],
                ];
            }
        }
        return $out;
    }

    /**
     * Search the chosen servers. Every result gets an id; its book data stays
     * in the session until it is imported, so the import works on what the
     * catalogue sent, not on what the browser sends back.
     *
     * @param list<string> $serverKeys
     * @return array{results: list<array<string, mixed>>, servers: list<array<string, mixed>>}
     */
    public function search(array $serverKeys, string $index, string $term, int $max): array
    {
        $servers = $this->servers();
        $max = max(1, min(25, $max));
        $results = [];
        $report = [];
        $stored = [];
        $importer = new MarcBookImporter($this->db, MarcBookImporter::MODE_SKIP, false);
        $csv = new \App\Controllers\CsvImportController();
        foreach (array_values(array_unique($serverKeys)) as $key) {
            if (!isset($servers[$key])) {
                continue;
            }
            $server = $servers[$key];
            if (count($stored) >= self::MAX_RESULTS) {
                // The results are full: the server is listed as not queried,
                // not silently dropped from the report.
                $report[] = ['key' => $key, 'name' => (string) $server['name'], 'total' => 0, 'count' => 0, 'error' => sprintf(__('Limite di %d risultati raggiunto: catalogo non interrogato.'), self::MAX_RESULTS)];
                continue;
            }
            $answer = $key === 'sbn'
                ? $this->searchSbn($index, $term, $max)
                : $this->client($server)->searchServer($server, SruClient::cqlFor($server, $index, $term), $max);
            $report[] = ['key' => $key, 'name' => (string) $server['name'], 'total' => $answer['total'], 'count' => count($answer['records']), 'error' => $answer['error']];
            foreach ($answer['records'] as $n => $record) {
                if (count($stored) >= self::MAX_RESULTS) {
                    break;
                }
                $id = substr(hash('sha256', $key . '|' . $n . '|' . json_encode($record['data'])), 0, 16);
                $data = (array) $record['data'];
                $existing = null;
                try {
                    $existing = $importer->findExisting($csv->normalizeImportRow(MarcBookData::toImportRow($data)));
                } catch (\Throwable) {
                    $existing = null;
                }
                $stored[$id] = ['server' => (string) $server['name'], 'data' => $data];
                $results[] = [
                    'id' => $id,
                    'server' => (string) $server['name'],
                    'flavour' => (string) $record['flavour'],
                    'titolo' => (string) ($data['titolo'] ?? ''),
                    'sottotitolo' => (string) ($data['sottotitolo'] ?? ''),
                    'autori' => implode('; ', (array) ($data['autori'] ?? [])),
                    'editore' => implode('; ', (array) ($data['editori'] ?? [])),
                    'anno' => (string) ($data['anno_pubblicazione'] ?? ''),
                    'isbn' => (string) (($data['isbn13'] ?? '') !== '' ? $data['isbn13'] : ($data['isbn10'] ?? '')),
                    'copertina' => (string) ($data['copertina_url'] ?? ''),
                    'existing_id' => $existing,
                    'detail' => $this->detail($data, $record['marc'] ?? null),
                ];
            }
        }
        $_SESSION[self::SESSION_KEY] = $stored;
        return ['results' => $results, 'servers' => $report];
    }

    /**
     * Import the chosen results of the last search.
     *
     * @param list<string> $ids
     * @return array{imported: int, updated: int, skipped: int, failed: int, covers: int, items: list<array<string, mixed>>, import_id: ?string}
     */
    public function import(array $ids, string $mode, bool $covers): array
    {
        $stored = is_array($_SESSION[self::SESSION_KEY] ?? null) ? $_SESSION[self::SESSION_KEY] : [];
        $importer = new MarcBookImporter($this->db, in_array($mode, MarcBookImporter::MODES, true) ? $mode : MarcBookImporter::MODE_SKIP, $covers);
        $counters = [];
        $items = [];
        $errors = [];
        $serverNames = [];
        $position = 0;
        foreach (array_values(array_unique($ids)) as $id) {
            $position++;
            if (!isset($stored[$id])) {
                $items[] = ['id' => $id, 'action' => 'failed', 'message' => __('Risultato non più disponibile: ripeti la ricerca')];
                $errors[] = ['line' => $position, 'title' => '', 'message' => __('Risultato non più disponibile: ripeti la ricerca'), 'type' => 'validation'];
                continue;
            }
            $serverNames[$stored[$id]['server']] = true;
            $data = (array) $stored[$id]['data'];
            $title = (string) ($data['titolo'] ?? '');
            try {
                $warnings = [];
                $result = $importer->import($data, $counters, $warnings);
                $items[] = ['id' => $id, 'action' => $result['action'], 'book_id' => $result['id'], 'title' => $title, 'warnings' => $warnings];
                if ($result['action'] === 'skipped') {
                    $errors[] = ['line' => $position, 'title' => $title, 'message' => sprintf(__('Già in catalogo (libro #%d): saltato'), (int) $result['id']), 'type' => 'duplicate'];
                }
                foreach ($warnings as $warning) {
                    $errors[] = ['line' => $position, 'title' => $title, 'message' => $warning, 'type' => 'cover'];
                }
            } catch (\Throwable $e) {
                $items[] = ['id' => $id, 'action' => 'failed', 'title' => $title, 'message' => $e->getMessage()];
                $errors[] = ['line' => $position, 'title' => $title, 'message' => $e->getMessage(), 'type' => 'validation'];
            }
        }
        $failed = count(array_filter($items, static fn (array $i): bool => $i['action'] === 'failed'));
        $importId = null;
        try {
            $userId = isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null;
            $logger = new \App\Support\ImportLogger($this->db, 'marc', mb_substr('Copy cataloging: ' . implode(', ', array_keys($serverNames)), 0, 255), $userId);
            $logger->setStats([
                'imported' => (int) ($counters['imported'] ?? 0),
                'updated' => (int) ($counters['updated'] ?? 0),
                'failed' => $failed,
                'authors_created' => (int) ($counters['authors_created'] ?? 0),
                'publishers_created' => (int) ($counters['publishers_created'] ?? 0),
                'scraped' => (int) ($counters['covers'] ?? 0),
            ]);
            foreach ($errors as $error) {
                $logger->addError($error['line'], $error['title'], $error['message'], $error['type']);
            }
            if ($logger->complete(count($ids))) {
                $importId = $logger->getImportId();
            }
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::warning('[Copy cataloging] history not saved', ['error' => $e->getMessage()]);
        }
        return [
            'imported' => (int) ($counters['imported'] ?? 0),
            'updated' => (int) ($counters['updated'] ?? 0),
            'skipped' => (int) ($counters['skipped'] ?? 0),
            'failed' => $failed,
            'covers' => (int) ($counters['covers'] ?? 0),
            'items' => $items,
            'import_id' => $importId,
        ];
    }

    /** @param array<string, mixed> $server */
    private function client(array $server): SruClient
    {
        if ($this->clientFactory !== null) {
            return ($this->clientFactory)($server);
        }
        $client = new LocalSruClient([$server], $this->db, $this->pluginId);
        $client->setOptions(['timeout' => $this->timeout]);
        return $client;
    }

    /**
     * @return array{total: int, records: list<array<string, mixed>>, error: ?string}
     */
    private function searchSbn(string $index, string $term, int $max): array
    {
        try {
            $client = new SbnClient($this->timeout, true);
            if ($index === 'isbn') {
                $book = $client->searchByIsbn((string) preg_replace('/[^0-9Xx]/', '', $term));
                $books = $book !== null ? [$book] : [];
            } elseif ($index === 'author') {
                $books = $client->searchByAuthor($term, $max);
            } else {
                $books = $client->searchByTitle($term, $max);
            }
            $records = [];
            foreach (array_slice($books, 0, $max) as $book) {
                if (is_array($book)) {
                    $records[] = ['flavour' => 'sbn', 'marc' => null, 'data' => MarcBookData::fromScrape($book)];
                }
            }
            return ['total' => count($records), 'records' => $records, 'error' => null];
        } catch (\Throwable $e) {
            return ['total' => 0, 'records' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * What the detail view shows: the MARC fields as the record has them,
     * or the mapped book data for a record that was not MARC.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $marc
     * @return list<array{tag: string, ind: string, value: string}>
     */
    private function detail(array $data, ?array $marc): array
    {
        $lines = [];
        if (is_array($marc)) {
            $lines[] = ['tag' => 'LDR', 'ind' => '', 'value' => (string) ($marc['leader'] ?? '')];
            foreach ((array) ($marc['fields'] ?? []) as $field) {
                if (!is_array($field)) {
                    continue;
                }
                if (array_key_exists('data', $field)) {
                    $lines[] = ['tag' => (string) $field['tag'], 'ind' => '', 'value' => (string) $field['data']];
                    continue;
                }
                $value = '';
                foreach ((array) ($field['subfields'] ?? []) as $sub) {
                    $value .= '$' . (string) ($sub[0] ?? '') . ' ' . (string) ($sub[1] ?? '') . ' ';
                }
                $lines[] = ['tag' => (string) $field['tag'], 'ind' => (string) ($field['ind1'] ?? ' ') . (string) ($field['ind2'] ?? ' '), 'value' => trim($value)];
            }
            return $lines;
        }
        foreach ($data as $key => $value) {
            $text = is_array($value) ? implode('; ', $value) : (string) $value;
            if ($text !== '') {
                $lines[] = ['tag' => (string) $key, 'ind' => '', 'value' => $text];
            }
        }
        return $lines;
    }
}
