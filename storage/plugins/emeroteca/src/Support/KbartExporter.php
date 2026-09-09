<?php

declare(strict_types=1);

namespace App\Plugins\Emeroteca\Support;

use mysqli;
use mysqli_result;
use mysqli_stmt;

require_once __DIR__ . '/IssnHelper.php';

/**
 * Holdings exports for union catalogues (review #140).
 *
 * Two serializations of the same underlying holdings:
 *
 *  • kbart()  — NISO RP-9-2014 (KBART Phase II) TSV: the de-facto exchange
 *               format for title-level coverage, ingested by knowledge
 *               bases and discovery layers.
 *  • acnp()   — CSV in the style of ACNP (Catalogo Italiano dei Periodici),
 *               one row per title with the holdings declared the way an
 *               Italian union catalogue expects it: "1998: 1-8, 10-12; lac. 9".
 *
 * Pure component: every method takes the mysqli handle and returns a string.
 * No routes, no headers, no echo — the HTTP layer wraps these.
 *
 * There is no PSR-4 autoloader scope for plugin classes (see IssnHelper):
 * callers `require_once` this file directly.
 *
 * ── Encoding decisions ────────────────────────────────────────────────
 * UTF-8 **without BOM**, newline "\n". Both files are machine-ingested by
 * catalogue loaders; a BOM ends up glued to the first header cell
 * ("\xEF\xBB\xBFpublication_title") and breaks strict header matching —
 * which is exactly what KBART validators do. Excel's UTF-8 auto-detection
 * is not worth corrupting the header for.
 *
 * ── Field policy ──────────────────────────────────────────────────────
 * • coverage_depth: 'fulltext' when the title has at least one issue with a
 *   PUBLIC PDF (pdf_path set AND pdf_pubblico = 1) — that is genuine online
 *   full text; otherwise 'print', which states plainly that the holdings are
 *   physical issues on a shelf. 'selected articles' is deliberately not used:
 *   it would describe article-level licensing we do not have.
 * • access_type: 'F' (free) alongside 'fulltext', empty for print-only
 *   holdings — KBART leaves access_type optional and asserting 'P' (paid)
 *   would claim a subscription model that does not exist here.
 * • title_id: "emeroteca:{id}", a stable local identifier. The same scheme is
 *   used for preceding_publication_title_id so intra-file references resolve.
 * • date_first/last_issue_online carry the YEAR of the first/last annata with
 *   at least one owned issue. The "_online" suffix is KBART legacy naming;
 *   union catalogues read these as the coverage bounds regardless of carrier.
 *
 * ── Possession semantics (shared by both exports) ─────────────────────
 * Driven by `stato` only, never by `condizione` — a damaged but owned issue
 * is still owned (same rule as EmerotecaPlugin::consistenzaTestata):
 *   posseduto              → counts as held, enters the ranges
 *   mancante               → counts as a gap, listed after "lac."
 *   atteso, reclamato      → neither: not yet due, claiming them as either
 *                            held or missing would misstate the holdings
 *   scartato               → neither: a deliberate withdrawal is not a hole
 *   smarrito               → neither, for coherence with consistenzaTestata()
 */
final class KbartExporter
{
    /**
     * The 25 KBART Phase II columns, in the canonical order mandated by
     * NISO RP-9-2014. Order and spelling are part of the contract: do not
     * translate, reorder or extend.
     *
     * @var list<string>
     */
    public const KBART_COLUMNS = [
        'publication_title',
        'print_identifier',
        'online_identifier',
        'date_first_issue_online',
        'num_first_vol_online',
        'num_first_issue_online',
        'date_last_issue_online',
        'num_last_vol_online',
        'num_last_issue_online',
        'title_url',
        'first_author',
        'title_id',
        'embargo_info',
        'coverage_depth',
        'notes',
        'publisher_name',
        'publication_type',
        'date_monograph_published_print',
        'date_monograph_published_online',
        'monograph_volume',
        'monograph_edition',
        'first_editor',
        'parent_publication_title_id',
        'preceding_publication_title_id',
        'access_type',
    ];

    /**
     * ACNP-style CSV columns. Technical identifiers, intentionally NOT
     * translated: the file is consumed by an Italian union catalogue loader,
     * not read by an end user.
     *
     * @var list<string>
     */
    public const ACNP_COLUMNS = [
        'titolo',
        'issn',
        'e_issn',
        'editore',
        'luogo_pubblicazione',
        'periodicita',
        'tipo',
        'consistenza',
        'url',
    ];

    /** Separator between per-year holdings statements inside one ACNP cell. */
    private const ACNP_YEAR_SEPARATOR = ' | ';

    /**
     * KBART Phase II TSV export.
     *
     * @param int|null $testataId Restrict to one title; null exports all.
     */
    public static function kbart(mysqli $db, ?int $testataId = null): string
    {
        $out = implode("\t", self::KBART_COLUMNS) . "\n";

        foreach (self::collectHoldings($db, $testataId) as $holding) {
            $testata  = $holding['testata'];
            $coverage = $holding['coverage'];

            $row = [
                'publication_title'              => (string) $testata['titolo'],
                'print_identifier'               => self::formatIssn((string) ($testata['issn'] ?? '')),
                'online_identifier'              => self::formatIssn((string) ($testata['e_issn'] ?? '')),
                'date_first_issue_online'        => $coverage['first_year'],
                'num_first_vol_online'           => $coverage['first_vol'],
                'num_first_issue_online'         => $coverage['first_issue'],
                'date_last_issue_online'         => $coverage['last_year'],
                'num_last_vol_online'            => $coverage['last_vol'],
                'num_last_issue_online'          => $coverage['last_issue'],
                'title_url'                      => self::testataUrl((int) $testata['id']),
                'first_author'                   => '',
                'title_id'                       => self::titleId((int) $testata['id']),
                'embargo_info'                   => '',
                'coverage_depth'                 => $holding['has_public_pdf'] ? 'fulltext' : 'print',
                'notes'                          => $holding['notes'],
                'publisher_name'                 => (string) ($testata['editore_nome'] ?? ''),
                'publication_type'               => 'serial',
                'date_monograph_published_print' => '',
                'date_monograph_published_online' => '',
                'monograph_volume'               => '',
                'monograph_edition'              => '',
                'first_editor'                   => '',
                'parent_publication_title_id'    => '',
                'preceding_publication_title_id' => $testata['testata_precedente_id'] !== null
                    ? self::titleId((int) $testata['testata_precedente_id'])
                    : '',
                'access_type'                    => $holding['has_public_pdf'] ? 'F' : '',
            ];

            $cells = [];
            foreach (self::KBART_COLUMNS as $column) {
                $cells[] = self::oneLine($row[$column]);
            }
            $out .= implode("\t", $cells) . "\n";
        }

        return $out;
    }

    /**
     * ACNP-style CSV export: one row per title, holdings declared per year.
     *
     * @param int|null $testataId Restrict to one title; null exports all.
     */
    public static function acnp(mysqli $db, ?int $testataId = null): string
    {
        $out = implode(',', array_map([self::class, 'csvCell'], self::ACNP_COLUMNS)) . "\n";

        foreach (self::collectHoldings($db, $testataId) as $holding) {
            $testata = $holding['testata'];
            $cells = [
                (string) $testata['titolo'],
                self::formatIssn((string) ($testata['issn'] ?? '')),
                self::formatIssn((string) ($testata['e_issn'] ?? '')),
                (string) ($testata['editore_nome'] ?? ''),
                (string) ($testata['luogo_pubblicazione'] ?? ''),
                (string) ($testata['periodicita'] ?? ''),
                (string) ($testata['tipo'] ?? ''),
                $holding['notes'],
                self::testataUrl((int) $testata['id']),
            ];
            $out .= implode(',', array_map(
                static fn (string $cell): string => self::csvCell(self::oneLine($cell)),
                $cells
            )) . "\n";
        }

        return $out;
    }

    /**
     * Holdings statement for ONE annata, in the biblioteconomic form used by
     * ACNP: "1-8, 10-12; lac. 9".
     *
     * Reusable on its own (the admin annata view and the public page want the
     * same string). Returns '' when the annata has neither holdings nor gaps,
     * and the curator-written `consistenza_dichiarata` verbatim when present —
     * that column is the manually declared legacy statement and always wins
     * over the computed one.
     */
    public static function consistenzaAnnata(mysqli $db, int $annataId): string
    {
        $stmt = $db->prepare('SELECT consistenza_dichiarata FROM emeroteca_annate WHERE id = ?');
        if ($stmt === false) {
            return '';
        }
        $stmt->bind_param('i', $annataId);
        if (!$stmt->execute()) {
            $stmt->close();
            return '';
        }
        $res = $stmt->get_result();
        $row = $res instanceof mysqli_result ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!is_array($row)) {
            return '';
        }
        $dichiarata = $row['consistenza_dichiarata'] !== null ? (string) $row['consistenza_dichiarata'] : null;

        $byAnnata = self::fetchFascicoli($db, [$annataId]);

        return self::consistenzaFromRows($dichiarata, $byAnnata[$annataId] ?? []);
    }

    // ── data collection ───────────────────────────────────────────────

    /**
     * Load every exported title with its computed coverage and notes.
     *
     * One query per level (titles → annate → fascicoli), never per row: the
     * export must not degrade into N+1 on a large emeroteca.
     *
     * @return list<array{
     *     testata: array<string, mixed>,
     *     coverage: array{first_year:string,first_vol:string,first_issue:string,last_year:string,last_vol:string,last_issue:string},
     *     notes: string,
     *     has_public_pdf: bool
     * }>
     */
    private static function collectHoldings(mysqli $db, ?int $testataId): array
    {
        $testate = self::fetchTestate($db, $testataId);
        if ($testate === []) {
            return [];
        }

        $testataIds = array_map(static fn (array $t): int => (int) $t['id'], $testate);
        $annateByTestata = self::fetchAnnate($db, $testataIds);

        $annataIds = [];
        foreach ($annateByTestata as $annate) {
            foreach ($annate as $annata) {
                $annataIds[] = (int) $annata['id'];
            }
        }
        $fascicoliByAnnata = self::fetchFascicoli($db, $annataIds);
        $publicPdfTestate  = self::fetchTestateWithPublicPdf($db, $testataIds);

        $out = [];
        foreach ($testate as $testata) {
            $tid    = (int) $testata['id'];
            $annate = $annateByTestata[$tid] ?? [];

            $notesParts = [];
            $ownedAnnate = [];
            foreach ($annate as $annata) {
                $aid  = (int) $annata['id'];
                $rows = $fascicoliByAnnata[$aid] ?? [];

                $dichiarata = $annata['consistenza_dichiarata'] !== null
                    ? (string) $annata['consistenza_dichiarata']
                    : null;
                $statement = self::consistenzaFromRows($dichiarata, $rows);
                if ($statement !== '') {
                    $notesParts[] = $annata['anno'] . ': ' . $statement;
                }

                $owned = self::ownedNumbers($rows);
                if ($owned !== []) {
                    $ownedAnnate[] = ['annata' => $annata, 'owned' => $owned];
                }
            }

            $out[] = [
                'testata'        => $testata,
                'coverage'       => self::buildCoverage($ownedAnnate),
                'notes'          => implode(self::ACNP_YEAR_SEPARATOR, $notesParts),
                'has_public_pdf' => in_array($tid, $publicPdfTestate, true),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetchTestate(mysqli $db, ?int $testataId): array
    {
        $sql = 'SELECT t.id, t.titolo, t.issn, t.e_issn, t.issn_l, t.luogo_pubblicazione,
                       t.periodicita, t.tipo, t.testata_precedente_id, e.nome AS editore_nome
                  FROM emeroteca_testate t
                  LEFT JOIN editori e ON e.id = t.editore_id';
        if ($testataId !== null) {
            $sql .= ' WHERE t.id = ?';
        }
        $sql .= ' ORDER BY t.titolo ASC, t.id ASC';

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        if ($testataId !== null) {
            $stmt->bind_param('i', $testataId);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        return self::drain($stmt);
    }

    /**
     * @param  list<int> $testataIds
     * @return array<int, list<array<string, mixed>>> keyed by testata_id, chronological
     */
    private static function fetchAnnate(mysqli $db, array $testataIds): array
    {
        if ($testataIds === []) {
            return [];
        }
        $stmt = self::prepareIn(
            $db,
            'SELECT id, testata_id, anno, volume, serie, consistenza_dichiarata
               FROM emeroteca_annate
              WHERE testata_id IN (%s)
              ORDER BY anno ASC, volume ASC, id ASC',
            $testataIds
        );
        if ($stmt === null) {
            return [];
        }

        $out = [];
        foreach (self::drain($stmt) as $row) {
            $out[(int) $row['testata_id']][] = $row;
        }
        return $out;
    }

    /**
     * @param  list<int> $annataIds
     * @return array<int, list<array{numero:string, stato:string}>> keyed by annata_id
     */
    private static function fetchFascicoli(mysqli $db, array $annataIds): array
    {
        if ($annataIds === []) {
            return [];
        }
        $stmt = self::prepareIn(
            $db,
            'SELECT annata_id, numero, stato
               FROM emeroteca_fascicoli
              WHERE annata_id IN (%s)
              ORDER BY id ASC',
            $annataIds
        );
        if ($stmt === null) {
            return [];
        }

        $out = [];
        foreach (self::drain($stmt) as $row) {
            $out[(int) $row['annata_id']][] = [
                'numero' => (string) $row['numero'],
                'stato'  => (string) $row['stato'],
            ];
        }
        return $out;
    }

    /**
     * Titles owning at least one issue whose PDF is stored AND publicly
     * downloadable — the only case in which we may claim full text.
     *
     * @param  list<int> $testataIds
     * @return list<int>
     */
    private static function fetchTestateWithPublicPdf(mysqli $db, array $testataIds): array
    {
        if ($testataIds === []) {
            return [];
        }
        $stmt = self::prepareIn(
            $db,
            'SELECT DISTINCT a.testata_id
               FROM emeroteca_fascicoli f
               JOIN emeroteca_annate a ON a.id = f.annata_id
              WHERE a.testata_id IN (%s)
                AND f.pdf_path IS NOT NULL
                AND f.pdf_path <> \'\'
                AND f.pdf_pubblico = 1',
            $testataIds
        );
        if ($stmt === null) {
            return [];
        }

        $out = [];
        foreach (self::drain($stmt) as $row) {
            $out[] = (int) $row['testata_id'];
        }
        return $out;
    }

    /**
     * Prepare a statement whose only placeholder group is an integer IN list.
     *
     * @param  list<int> $ids
     */
    private static function prepareIn(mysqli $db, string $sqlTemplate, array $ids): ?mysqli_stmt
    {
        $ids = array_values(array_unique($ids));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(sprintf($sqlTemplate, $placeholders));
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        return $stmt;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function drain(mysqli_stmt $stmt): array
    {
        $res = $stmt->get_result();
        $out = [];
        if ($res instanceof mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $out[] = $row;
            }
        }
        $stmt->close();
        return $out;
    }

    // ── holdings computation ──────────────────────────────────────────

    /**
     * Compact a set of issues into a holdings statement.
     *
     * Pure numbers collapse into ranges ("1-8"); anything else (e.g. "13-14",
     * "12bis", "S1") cannot be range-collapsed without inventing issues that
     * may not exist, so it is listed verbatim after the numeric runs. Gaps
     * follow the same treatment behind "lac.".
     *
     * @param list<array{numero:string, stato:string}> $rows
     */
    private static function consistenzaFromRows(?string $dichiarata, array $rows): string
    {
        $declared = trim((string) $dichiarata);
        if ($declared !== '') {
            return self::oneLine($declared);
        }

        $ownedInts = [];
        $ownedOther = [];
        $gapInts = [];
        $gapOther = [];

        foreach ($rows as $row) {
            $numero = trim($row['numero']);
            if ($numero === '') {
                continue;
            }
            $isNumeric = preg_match('/^\d+$/', $numero) === 1;

            switch ($row['stato']) {
                case 'posseduto':
                    if ($isNumeric) {
                        $ownedInts[] = (int) $numero;
                    } else {
                        $ownedOther[] = $numero;
                    }
                    break;
                case 'mancante':
                    if ($isNumeric) {
                        $gapInts[] = (int) $numero;
                    } else {
                        $gapOther[] = $numero;
                    }
                    break;
                default:
                    // atteso / reclamato / scartato / smarrito: see class docblock.
                    break;
            }
        }

        $owned = self::compactList($ownedInts, $ownedOther);
        $gaps  = self::compactList($gapInts, $gapOther);

        if ($owned === '' && $gaps === '') {
            return '';
        }
        if ($gaps === '') {
            return $owned;
        }
        $lacune = 'lac. ' . $gaps;

        return $owned === '' ? $lacune : $owned . '; ' . $lacune;
    }

    /**
     * "1-8, 10-12" from consecutive integer runs, with the non-numeric issue
     * designations appended verbatim in natural order.
     *
     * @param list<int>    $ints
     * @param list<string> $others
     */
    private static function compactList(array $ints, array $others): string
    {
        $ints = array_values(array_unique($ints));
        sort($ints, SORT_NUMERIC);

        $parts = [];
        $count = count($ints);
        for ($i = 0; $i < $count; $i++) {
            $start = $ints[$i];
            $end = $start;
            while ($i + 1 < $count && $ints[$i + 1] === $end + 1) {
                $i++;
                $end = $ints[$i];
            }
            $parts[] = $start === $end ? (string) $start : $start . '-' . $end;
        }

        $others = array_values(array_unique($others));
        usort($others, [self::class, 'compareIssueNumbers']);
        foreach ($others as $other) {
            $parts[] = $other;
        }

        return implode(', ', $parts);
    }

    /**
     * Owned issue designations of one annata, in natural order.
     *
     * @param  list<array{numero:string, stato:string}> $rows
     * @return list<string>
     */
    private static function ownedNumbers(array $rows): array
    {
        $owned = [];
        foreach ($rows as $row) {
            if ($row['stato'] !== 'posseduto') {
                continue;
            }
            $numero = trim($row['numero']);
            if ($numero !== '') {
                $owned[] = $numero;
            }
        }
        $owned = array_values(array_unique($owned));
        usort($owned, [self::class, 'compareIssueNumbers']);
        return $owned;
    }

    /**
     * Natural ordering for issue designations: compare the leading integer
     * when both have one ("2" before "10", "13-14" after "12"), fall back to
     * a case-insensitive string comparison otherwise.
     */
    private static function compareIssueNumbers(string $a, string $b): int
    {
        $hasA = preg_match('/^\d+/', $a, $ma) === 1;
        $hasB = preg_match('/^\d+/', $b, $mb) === 1;

        if ($hasA && $hasB) {
            $na = (int) $ma[0];
            $nb = (int) $mb[0];
            return $na !== $nb ? $na <=> $nb : strcasecmp($a, $b);
        }
        if ($hasA) {
            return -1;
        }
        if ($hasB) {
            return 1;
        }
        return strcasecmp($a, $b);
    }

    /**
     * First/last coverage point across the annate that actually own something.
     *
     * @param list<array{annata: array<string, mixed>, owned: list<string>}> $ownedAnnate
     * @return array{first_year:string,first_vol:string,first_issue:string,last_year:string,last_vol:string,last_issue:string}
     */
    private static function buildCoverage(array $ownedAnnate): array
    {
        if ($ownedAnnate === []) {
            return [
                'first_year' => '', 'first_vol' => '', 'first_issue' => '',
                'last_year'  => '', 'last_vol'  => '', 'last_issue'  => '',
            ];
        }

        $first = $ownedAnnate[0];
        $last  = $ownedAnnate[count($ownedAnnate) - 1];

        return [
            'first_year'  => (string) $first['annata']['anno'],
            'first_vol'   => self::volumeLabel($first['annata']),
            'first_issue' => $first['owned'][0],
            'last_year'   => (string) $last['annata']['anno'],
            'last_vol'    => self::volumeLabel($last['annata']),
            'last_issue'  => $last['owned'][count($last['owned']) - 1],
        ];
    }

    /**
     * Volume designation of an annata; falls back to the series when the
     * volume was never filled in (volume is NOT NULL DEFAULT '' since 1.4.0).
     *
     * @param array<string, mixed> $annata
     */
    private static function volumeLabel(array $annata): string
    {
        $volume = trim((string) ($annata['volume'] ?? ''));
        if ($volume !== '') {
            return $volume;
        }
        return trim((string) ($annata['serie'] ?? ''));
    }

    // ── formatting ────────────────────────────────────────────────────

    /** Canonical hyphenated ISSN, or '' when the column is empty. */
    private static function formatIssn(string $issn): string
    {
        $issn = trim($issn);
        if ($issn === '') {
            return '';
        }
        return IssnHelper::normalize($issn);
    }

    /** Stable local identifier, also used for intra-file title references. */
    private static function titleId(int $testataId): string
    {
        return 'emeroteca:' . $testataId;
    }

    /**
     * Absolute public URL of the title. Uses the core helper when the HTTP
     * stack is bootstrapped; degrades to the relative path in CLI contexts
     * (cron, unit tests) where there is no request to derive a host from.
     */
    private static function testataUrl(int $testataId): string
    {
        $path = '/emeroteca/' . $testataId;
        if (function_exists('absoluteUrl')) {
            return absoluteUrl($path);
        }
        return $path;
    }

    /**
     * Collapse every TAB, CR, LF and other C0 control character to a single
     * space. Mandatory for the TSV (a stray TAB shifts every later column
     * into the wrong field) and applied to the CSV too so a row is always
     * one physical line.
     */
    private static function oneLine(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $value = (string) preg_replace('/\s{2,}/u', ' ', $value);
        return trim($value);
    }

    /** RFC 4180 cell: quote when it contains a comma or a double quote. */
    private static function csvCell(string $value): string
    {
        if (strpbrk($value, ",\"") === false) {
            return $value;
        }
        return '"' . str_replace('"', '""', $value) . '"';
    }
}
