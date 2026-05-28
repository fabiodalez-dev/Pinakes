<?php

declare(strict_types=1);

namespace Z39Server;

use Plugins\Z39Server\Classes\SbnClient;

/**
 * SBN authority assist for author names — issue #133 (REICAT/SBN).
 *
 * The public SBN mobile gateway does not expose authority (name) records
 * directly, but the bibliographic records carry the SBN-controlled author
 * form in `autorePrincipale` ("Surname, Forename <dates>") — which is exactly
 * the REICAT 18.0 *forma autorizzata* with the REICAT 7.0 date qualifier for
 * homonyms. This client harvests those forms for a queried name, ranks them by
 * frequency, and extracts the date qualifier, so a cataloguer can pick the
 * authorized form for an author record.
 *
 * It is honest about its limits: a true Codice di Controllo Nazionale (the SBN
 * *authority* record ID) is not retrievable here, so {@see lookupByName()}
 * returns the authorized form + qualifier rather than inventing a CCN.
 */
class SbnAuthorityClient
{
    public function __construct(private SbnClient $sbn)
    {
    }

    /**
     * Look up candidate authorized forms for an author name.
     *
     * @return list<array{authorized_form:string,qualifier_dates:?string,raw:string,count:int,sample_bid:?string}>
     */
    public function lookupByName(string $name, int $maxCandidates = 5): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }

        $json = $this->sbn->searchRaw('any', $name, 25);
        $records = is_array($json) ? ($json['briefRecords'] ?? null) : null;
        if (!is_array($records) || $records === []) {
            return [];
        }

        /** @var array<string,array{form:string,dates:?string,raw:string,count:int,bid:?string}> $agg */
        $agg = [];
        foreach ($records as $rec) {
            if (!is_array($rec)) {
                continue;
            }
            $raw = trim((string) ($rec['autorePrincipale'] ?? ''));
            if ($raw === '') {
                continue;
            }
            [$form, $dates] = $this->splitForm($raw);
            $key = mb_strtolower($form, 'UTF-8');
            if (!isset($agg[$key])) {
                $agg[$key] = [
                    'form'  => $form,
                    'dates' => $dates,
                    'raw'   => $raw,
                    'count' => 0,
                    'bid'   => is_string($rec['codiceIdentificativo'] ?? null) ? $rec['codiceIdentificativo'] : null,
                ];
            }
            $agg[$key]['count']++;
            // Prefer a raw form that actually carries the date qualifier.
            if ($dates !== null && $agg[$key]['dates'] === null) {
                $agg[$key]['dates'] = $dates;
                $agg[$key]['raw'] = $raw;
            }
        }

        if ($agg === []) {
            return [];
        }

        // Rank by frequency (descending).
        usort($agg, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
        $agg = array_slice($agg, 0, max(1, $maxCandidates));

        // Enrich the top candidate with dates from a full record when the brief
        // records lacked the qualifier (one extra request, capped).
        if ($agg[0]['dates'] === null && $agg[0]['bid'] !== null) {
            $dates = $this->fetchDatesFromFull($agg[0]['bid'], $agg[0]['form']);
            if ($dates !== null) {
                $agg[0]['dates'] = $dates;
            }
        }

        return array_map(static function (array $c): array {
            return [
                'authorized_form' => $c['form'],
                'qualifier_dates' => $c['dates'],
                'raw'             => $c['raw'],
                'count'           => $c['count'],
                'sample_bid'      => $c['bid'],
            ];
        }, $agg);
    }

    /**
     * Split "Surname, Forename <1818-1883>" into [form, dates].
     *
     * @return array{0:string,1:?string}
     */
    private function splitForm(string $raw): array
    {
        $dates = null;
        if (preg_match('/^(.*?)\s*<([^>]+)>\s*$/u', $raw, $m)) {
            $raw   = trim($m[1]);
            $dates = trim($m[2]);
        }
        return [trim($raw), $dates];
    }

    /**
     * Fetch one full record and try to read the date qualifier for $form from
     * `autorePrincipale` or the `nomi` array.
     */
    private function fetchDatesFromFull(string $bid, string $form): ?string
    {
        $full = $this->sbn->getFullRecord($bid);
        if (!is_array($full)) {
            return null;
        }

        $candidates = [];
        if (isset($full['autorePrincipale']) && is_string($full['autorePrincipale'])) {
            $candidates[] = $full['autorePrincipale'];
        }
        if (isset($full['nomi']) && is_array($full['nomi'])) {
            foreach ($full['nomi'] as $n) {
                if (is_string($n)) {
                    $candidates[] = preg_replace('/^\[[^\]]+\]\s*/', '', $n) ?? $n;
                }
            }
        }

        $formLower = mb_strtolower($form, 'UTF-8');
        foreach ($candidates as $cand) {
            [$cForm, $cDates] = $this->splitForm(trim($cand));
            if ($cDates !== null && mb_strtolower($cForm, 'UTF-8') === $formLower) {
                return $cDates;
            }
        }
        return null;
    }
}
