<?php

declare(strict_types=1);

namespace Z39Server;

/**
 * Nuovo Soggettario BNCF subject-heading client — issue #133 (REICAT/SBN).
 *
 * Queries the Biblioteca Nazionale Centrale di Firenze controlled vocabulary
 * (https://thes.bncf.firenze.sbn.it/) and returns matching subject headings
 * with their stable BNCF identifier and concept URI, so the cataloguer can
 * pick a controlled term instead of a free-text subject.
 *
 * The thesaurus has no JSON API; the public search form returns an HTML result
 * list whose term anchors follow the stable pattern
 *   <a href="termine.php?id=NNNN">Label</a>
 * which {@see parseResults()} extracts. HTTP is best-effort: any failure yields
 * an empty list, and the UI falls back to free-text subjects (which REICAT
 * permits when no controlled term applies).
 */
class SoggettarioClient
{
    private const BASE = 'https://thes.bncf.firenze.sbn.it/';
    private const SEARCH = 'ricerca.php';

    /** @var array<string,string> tiporicerca modes accepted by the form */
    private const MODES = [
        'cominciaper'   => 'cominciaper',  // begins with (default, best for autocomplete)
        'parolaesatta'  => 'parolaesatta', // exact term
        'partedi'       => 'partedi',      // contains
    ];

    public function __construct(private int $timeout = 12, private bool $enabled = true)
    {
    }

    /**
     * Search the Nuovo Soggettario for subject headings matching $query.
     *
     * @return list<array{termine:string,bncf_id:string,uri:string}>
     */
    public function search(string $query, string $mode = 'cominciaper', int $limit = 20): array
    {
        $query = trim($query);
        if (!$this->enabled || mb_strlen($query) < 2) {
            return [];
        }
        $mode = self::MODES[$mode] ?? 'cominciaper';

        $url = self::BASE . self::SEARCH
            . '?terminericerca=' . urlencode($query)
            . '&tiporicerca=' . $mode
            . '&lettera=';

        $html = $this->fetch($url);
        if ($html === null) {
            return [];
        }

        $results = $this->parseResults($html);
        return $limit > 0 ? array_slice($results, 0, $limit) : $results;
    }

    /**
     * Extract subject headings from a BNCF result-list page.
     *
     * Pure (no I/O) so it can be unit-tested against a saved fixture.
     *
     * @return list<array{termine:string,bncf_id:string,uri:string}>
     */
    public function parseResults(string $html): array
    {
        $out = [];
        $seen = [];
        if (!preg_match_all(
            '#<a\s+href="termine\.php\?id=(\d+)"[^>]*>(.*?)</a>#is',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            return [];
        }

        foreach ($matches as $m) {
            $id = $m[1];
            $label = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($label === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = [
                'termine' => $label,
                'bncf_id' => $id,
                'uri'     => self::BASE . 'termine.php?id=' . $id,
            ];
        }
        return $out;
    }

    private function fetch(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'Pinakes Library System/1.0',
            CURLOPT_HTTPHEADER => ['Accept: text/html', 'Accept-Language: it-IT,it;q=0.9'],
        ]);
        $response = $this->execute($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '' || $httpCode !== 200 || !is_string($response) || $response === '') {
            \App\Support\SecureLogger::debug('[Soggettario] fetch failed', [
                'http_code' => $httpCode,
                'error' => $error,
            ]);
            return null;
        }
        return $response;
    }

    /**
     * Perform the cURL transfer. Isolated so the transport can be substituted.
     *
     * @param \CurlHandle $ch
     * @return string|false
     */
    private function execute($ch)
    {
        return curl_exec($ch);
    }
}
