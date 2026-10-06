<?php

declare(strict_types=1);

namespace App\Plugins\Emeroteca\Controllers;

use App\Support\HookManager;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public read-only frontend of the Emeroteca plugin.
 *
 * Routes (registered by EmerotecaPlugin::registerRoutes):
 *   GET /emeroteca                 → index()          — testate grid
 *   GET /emeroteca/{id}            → showTestata()    — one periodical + year timeline
 *   GET /emeroteca/fascicolo/{id}  → showFascicolo()  — one issue + spoglio (TOC)
 *
 * Rendering follows the Archives plugin two-pass pattern
 * (ArchivesPlugin::renderPublic): the inner view is buffered into
 * $content, then app/Views/frontend/layout.php wraps it in the public
 * shell shared with /catalogo, /autore, etc.
 *
 * Loaded lazily by EmerotecaPlugin::dispatch() via require_once — there
 * is no PSR-4 autoloader scope for plugin classes.
 */
class PublicController
{
    private \mysqli $db;
    private HookManager $hookManager;

    /** @var array<string, bool> Per-request cache for table-existence probes. */
    private array $tableCache = [];

    public function __construct(\mysqli $db, HookManager $hookManager)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;
    }

    /**
     * Expose the injected HookManager (DI-wiring accessor, mirrors
     * EmerotecaPlugin::getHookManager — keeps static analysis happy).
     */
    public function getHookManager(): HookManager
    {
        return $this->hookManager;
    }

    // ── Actions ───────────────────────────────────────────────────────

    /** Page size of every public emeroteca listing (mastheads and articles). */
    public const PER_PAGE = 20;

    /**
     * GET /emeroteca — the mastheads, as the catalogue lists books: a facet
     * sidebar (search, type, publisher, subject, initial letter), a paginated
     * grid of cards, and underneath the latest articles (or, while searching,
     * the articles answering the same term).
     *
     * Facet counts follow the catalogue's rule: each facet is counted with
     * every OTHER active filter applied, so a number always says how many
     * mastheads clicking it would leave on screen.
     *
     * Legacy ?vista=editore|argomento links (the old grouped views) still
     * resolve: they now land on the same list, and stay noindex like before.
     *
     * @param array<string, string> $args
     */
    public function index(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = []
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $str = static fn(string $key): string => is_string($params[$key] ?? null) ? mb_substr(trim($params[$key]), 0, 200) : '';
        $q = $str('q');
        $vista = $str('vista');
        $tipo = $str('tipo');
        if (!array_key_exists($tipo, \EmerotecaPlugin::TIPI_TESTATA)) {
            $tipo = '';
        }
        $hasEditori = $this->tableExists('editori');
        $hasGeneri  = $this->tableExists('generi');
        $editore = $hasEditori ? max(0, (int) $str('editore')) : 0;
        $genere = $hasGeneri ? max(0, (int) $str('genere')) : 0;
        $lettera = mb_strtoupper($str('lettera'));
        if ($lettera !== '#' && preg_match('/^[A-Z]$/', $lettera) !== 1) {
            $lettera = '';
        }
        $requestedPage = max(1, (int) $str('page'));

        $withArticles = $this->tableExists('emeroteca_articoli')
            && $this->tableExists('emeroteca_fascicoli')
            && $this->tableExists('emeroteca_annate');

        /**
         * WHERE + binds for the active filters, leaving out $skip — the facet
         * being counted. The free term is never skipped: a facet counts within
         * the search, exactly as on /catalogo.
         *
         * @return array{0: string, 1: string, 2: list<int|string>}
         */
        $filterSql = function (string $skip = '') use ($q, $tipo, $editore, $genere, $lettera, $withArticles): array {
            $where = [];
            $types = '';
            $binds = [];
            if ($q !== '') {
                // One owner for "does this masthead answer that term": the
                // catalogue hint counts with the same fragment, so the number
                // it prints next to this link is the number this link opens.
                $where[] = \EmerotecaPlugin::testataSearchWhere($withArticles);
                $pattern = '%' . $this->escapeLike($q) . '%';
                $termBinds = $withArticles
                    ? [$pattern, $pattern, $pattern, $q, $pattern, $pattern, $pattern]
                    : [$pattern, $pattern, $pattern];
                $types .= str_repeat('s', count($termBinds));
                array_push($binds, ...$termBinds);
            }
            if ($tipo !== '' && $skip !== 'tipo') {
                $where[] = 't.tipo = ?';
                $types .= 's';
                $binds[] = $tipo;
            }
            if ($editore > 0 && $skip !== 'editore') {
                $where[] = 't.editore_id = ?';
                $types .= 'i';
                $binds[] = $editore;
            }
            if ($genere > 0 && $skip !== 'genere') {
                $where[] = 't.genere_id = ?';
                $types .= 'i';
                $binds[] = $genere;
            }
            if ($lettera !== '' && $skip !== 'lettera') {
                // The count's own bucket expression: under an accent-insensitive
                // collation UPPER(LEFT(...)) = 'E' would also take "Époque", which
                // the count files under '#', so one title would sit in two letters.
                $where[] = "(CASE WHEN t.titolo REGEXP '^[A-Za-z]' THEN UPPER(LEFT(t.titolo, 1)) ELSE '#' END) = ?";
                $types .= 's';
                $binds[] = $lettera;
            }
            return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $types, $binds];
        };

        // Type facet: real holdings only, in the plugin's vocabulary order.
        [$w, $t, $b] = $filterSql('tipo');
        $typeCounts = [];
        foreach ($this->fetchAll("SELECT t.tipo, COUNT(*) AS n FROM emeroteca_testate t{$w} GROUP BY t.tipo", $t, $b) as $row) {
            $typeCounts[(string) $row['tipo']] = (int) $row['n'];
        }
        $typeFacet = [];
        foreach (\EmerotecaPlugin::TIPI_TESTATA as $key => $label) {
            if (($typeCounts[$key] ?? 0) > 0) {
                $typeFacet[] = ['value' => $key, 'label' => __($label), 'n' => $typeCounts[$key]];
            }
        }

        $editoreFacet = [];
        if ($hasEditori) {
            [$w, $t, $b] = $filterSql('editore');
            foreach ($this->fetchAll(
                "SELECT ed.id, ed.nome, COUNT(*) AS n FROM emeroteca_testate t JOIN editori ed ON ed.id = t.editore_id{$w}
                  GROUP BY ed.id, ed.nome ORDER BY n DESC, ed.nome LIMIT 30",
                $t,
                $b
            ) as $row) {
                $editoreFacet[] = ['value' => (int) $row['id'], 'label' => (string) $row['nome'], 'n' => (int) $row['n']];
            }
        }

        $genereFacet = [];
        if ($hasGeneri) {
            [$w, $t, $b] = $filterSql('genere');
            foreach ($this->fetchAll(
                "SELECT g.id, g.nome, COUNT(*) AS n FROM emeroteca_testate t JOIN generi g ON g.id = t.genere_id{$w}
                  GROUP BY g.id, g.nome ORDER BY n DESC, g.nome LIMIT 30",
                $t,
                $b
            ) as $row) {
                $genereFacet[] = ['value' => (int) $row['id'], 'label' => (string) $row['nome'], 'n' => (int) $row['n']];
            }
        }

        [$w, $t, $b] = $filterSql('lettera');
        $letterCounts = [];
        foreach ($this->fetchAll(
            "SELECT CASE WHEN t.titolo REGEXP '^[A-Za-z]' THEN UPPER(LEFT(t.titolo, 1)) ELSE '#' END AS l, COUNT(*) AS n
               FROM emeroteca_testate t{$w} GROUP BY l ORDER BY l",
            $t,
            $b
        ) as $row) {
            $letterCounts[(string) $row['l']] = (int) $row['n'];
        }

        // The listing itself.
        [$w, $t, $b] = $filterSql();
        $total = (int) ($this->fetchOne("SELECT COUNT(*) AS n FROM emeroteca_testate t{$w}", $t, $b)['n'] ?? 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, $requestedPage);
        $offset = ($page - 1) * self::PER_PAGE;

        $editoreSel = $hasEditori ? 'ed.nome' : 'NULL';
        $genereSel  = $hasGeneri  ? 'g.nome'  : 'NULL';
        $editoreJoin = $hasEditori ? 'LEFT JOIN editori ed ON ed.id = t.editore_id' : '';
        $genereJoin  = $hasGeneri  ? 'LEFT JOIN generi g ON g.id = t.genere_id'     : '';
        $rows = $this->fetchAll(
            "SELECT t.id, t.titolo, t.sottotitolo, t.issn, t.tipo, t.periodicita,
                    t.anno_inizio, t.anno_fine, t.logo_url, t.stato_raccolta,
                    {$editoreSel} AS editore_nome,
                    {$genereSel} AS genere_nome,
                    ann.anno_min, ann.anno_max, ann.num_annate
               FROM emeroteca_testate t
               {$editoreJoin}
               {$genereJoin}
               LEFT JOIN (
                     SELECT testata_id, MIN(anno) AS anno_min, MAX(anno) AS anno_max,
                            COUNT(*) AS num_annate
                       FROM emeroteca_annate
                      GROUP BY testata_id
               ) ann ON ann.testata_id = t.id
               {$w}
              ORDER BY t.titolo ASC, t.id ASC
              LIMIT " . self::PER_PAGE . " OFFSET {$offset}",
            $t,
            $b
        );

        // Labels for the active-filter chips.
        $editoreLabel = '';
        if ($editore > 0) {
            $editoreLabel = (string) ($this->fetchOne('SELECT nome FROM editori WHERE id = ?', 'i', [$editore])['nome'] ?? ('#' . $editore));
        }
        $genereLabel = '';
        if ($genere > 0) {
            $genereLabel = (string) ($this->fetchOne('SELECT nome FROM generi WHERE id = ?', 'i', [$genere])['nome'] ?? ('#' . $genere));
        }

        $narrowed = $q !== '' || $tipo !== '' || $editore > 0 || $genere > 0 || $lettera !== '';
        $canonical = $this->baseUrl() . '/emeroteca' . ($page > 1 ? '?page=' . $page : '');

        return $this->renderPublic($response, 'index.php', [
            // While searching, the articles answering the same term; otherwise
            // the latest ones — the way into the collection's contents.
            'articleResults' => $this->articleResults($q, 0, 1, [], 8),
            'rows'  => $rows,
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
            'q'     => $q,
            'tipo'  => $tipo,
            'editore' => $editore,
            'genere'  => $genere,
            'lettera' => $lettera,
            'editoreLabel' => $editoreLabel,
            'genereLabel'  => $genereLabel,
            'typeFacet'    => $typeFacet,
            'editoreFacet' => $editoreFacet,
            'genereFacet'  => $genereFacet,
            'letterCounts' => $letterCounts,
            'tipoLabels' => \EmerotecaPlugin::TIPI_TESTATA,
            'seoTitle' => __('Emeroteca'),
            'seoDescription' => __('Consulta le testate di riviste, giornali e periodici conservate in emeroteca.'),
            'seoCanonical' => $canonical,
            // Same rule as the article search and the core catalogue: a
            // narrowed view of one corpus must not ask to be indexed (links are
            // still followed); a further page of the bare list is not a
            // duplicate of page 1 and canonicalises to itself.
            'seoRobots' => ($narrowed || ($vista !== '' && $vista !== 'az')) ? 'noindex,follow' : 'index,follow',
        ]);
    }

    /**
     * GET /emeroteca/{id} — testata detail: header (logo, ISSN, editore,
     * periodicità, anni, "già"/"poi" title chain, descrizione), year
     * timeline and, for the selected year (?anno=, default most recent),
     * the covers grid of its fascicoli.
     *
     * @param array<string, string> $args
     */
    public function showTestata(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = []
    ): ResponseInterface {
        $id = (int) ($args['id'] ?? 0);
        $testata = $this->findTestata($id);
        if ($testata === null) {
            return $this->renderNotFound($response);
        }

        // Title chain: "già" (continues from) / "poi" (continued by).
        $precedente = null;
        if (!empty($testata['testata_precedente_id'])) {
            $precedente = $this->fetchOne(
                'SELECT id, titolo FROM emeroteca_testate WHERE id = ?',
                'i',
                [(int) $testata['testata_precedente_id']]
            );
        }
        $successiva = $this->fetchOne(
            'SELECT id, titolo FROM emeroteca_testate WHERE testata_precedente_id = ? ORDER BY id ASC LIMIT 1',
            'i',
            [$id]
        );

        // Year timeline with per-year issue counts. Withdrawn issues
        // ('scartato') are excluded from the JOIN: they left the collection,
        // so counting them would inflate the public holdings figure — same
        // rule the public grid and the sitemap listener apply.
        $years = $this->fetchAll(
            'SELECT a.anno,
                    COUNT(f.id) AS num_fascicoli,
                    SUM(CASE WHEN f.stato = \'posseduto\' THEN 1 ELSE 0 END) AS num_posseduti
               FROM emeroteca_annate a
               LEFT JOIN emeroteca_fascicoli f ON f.annata_id = a.id AND f.stato <> \'scartato\'
              WHERE a.testata_id = ?
              GROUP BY a.anno
              ORDER BY a.anno ASC',
            'i',
            [$id]
        );

        // Selected year: ?anno= when it exists in the timeline, else the
        // most recent year on record.
        $params = $request->getQueryParams();
        $rawAnno = $params['anno'] ?? '';
        $availableYears = array_map(static fn(array $y): int => (int) $y['anno'], $years);
        $selectedYear = null;
        if (is_string($rawAnno) && ctype_digit($rawAnno) && in_array((int) $rawAnno, $availableYears, true)) {
            $selectedYear = (int) $rawAnno;
        } elseif ($availableYears !== []) {
            $selectedYear = max($availableYears);
        }

        // Fascicoli of the selected year (all volumes of that year).
        $fascicoli = [];
        if ($selectedYear !== null) {
            $fascicoli = $this->fetchAll(
                'SELECT f.id, f.numero, f.titolo_fascicolo, f.data_copertina,
                        f.data_pubblicazione, f.copertina_url, f.stato,
                        a.volume, a.anno
                   FROM emeroteca_fascicoli f
                   JOIN emeroteca_annate a ON a.id = f.annata_id
                  WHERE a.testata_id = ? AND a.anno = ? AND f.stato <> \'scartato\'
                  ORDER BY (f.data_pubblicazione IS NULL), f.data_pubblicazione ASC, f.id ASC',
                'ii',
                [$id, $selectedYear]
            );
        }

        $title = (string) $testata['titolo'];
        $description = trim((string) ($testata['descrizione'] ?? ''));
        $seoDescription = $description !== ''
            ? mb_substr($description, 0, 160)
            : $title . ' — ' . __('Emeroteca');

        // The masthead's own articles, searchable and paginated like the
        // article search — this page is where a reader browsing a periodical
        // expects to find what was published in it.
        $rawQ = $params['q'] ?? '';
        $q = is_string($rawQ) ? mb_substr(trim($rawQ), 0, 200) : '';
        $articles = $this->articleResults($q, $id, max(1, (int) ($params['page'] ?? 1)));
        $articlePage = max(1, (int) $articles['page']);
        $canonical = $this->baseUrl() . '/emeroteca/' . $id . ($articlePage > 1 ? '?page=' . $articlePage : '');

        return $this->renderPublic($response, 'testata.php', [
            'articleResults' => $articles,
            'q'            => $q,
            'rawAnno'      => is_string($rawAnno) ? $rawAnno : '',
            'testata'      => $testata,
            'precedente'   => $precedente,
            'successiva'   => $successiva,
            'years'        => $years,
            'selectedYear' => $selectedYear,
            'fascicoli'    => $fascicoli,
            'tipoLabels'         => \EmerotecaPlugin::TIPI_TESTATA,
            'periodicitaLabels'  => \EmerotecaPlugin::PERIODICITA,
            'statoFascicoloLabels' => \EmerotecaPlugin::STATI_FASCICOLO,
            'seoTitle' => $title . ' — ' . __('Emeroteca'),
            'seoDescription' => $seoDescription,
            'seoCanonical' => $canonical,
            // A search inside the masthead's articles is a narrowed view of the
            // same page; a further page of its articles is not a duplicate.
            'seoRobots' => $q !== '' ? 'noindex,follow' : 'index,follow',
        ]);
    }

    /**
     * GET /emeroteca/fascicolo/{id} — issue detail: big cover (or
     * placeholder), data sheet (numero, data, pagine, supplementi,
     * collocazione), spoglio (article TOC) and prev/next navigation
     * within the annata.
     *
     * @param array<string, string> $args
     */
    public function showFascicolo(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = []
    ): ResponseInterface {
        $id = (int) ($args['id'] ?? 0);
        $fascicolo = $this->fetchOne(
            'SELECT f.*, a.anno, a.volume, a.rilegata, a.testata_id,
                    t.titolo AS testata_titolo, t.sottotitolo AS testata_sottotitolo,
                    t.issn AS testata_issn, t.logo_url AS testata_logo_url
               FROM emeroteca_fascicoli f
               JOIN emeroteca_annate a ON a.id = f.annata_id
               JOIN emeroteca_testate t ON t.id = a.testata_id
              WHERE f.id = ?',
            'i',
            [$id]
        );
        if ($fascicolo === null) {
            return $this->renderNotFound($response);
        }

        // Spoglio: article-level TOC, ordered by starting page.
        $articoli = $this->fetchAll(
            'SELECT id, titolo, autori, pagina_inizio, pagina_fine, tipo
               FROM emeroteca_articoli
              WHERE fascicolo_id = ?
              ORDER BY (pagina_inizio IS NULL), pagina_inizio ASC, id ASC',
            'i',
            [$id]
        );

        // Collocazione — the core shelving model is scaffali (bookcases)
        // → mensole (shelf levels); emeroteca_fascicoli.collocazione_id
        // holds a mensole.id. Both tables are core but not guaranteed on
        // partial installs (same reason the DDL ships no FK), so the
        // lookup is probe-guarded and the LEFT JOIN towards scaffali is
        // defensive: a missing bookcase still shows the shelf level.
        $collocazione = null;
        if (!empty($fascicolo['collocazione_id']) && $this->tableExists('mensole')) {
            $scaffaliJoin = $this->tableExists('scaffali')
                ? 'LEFT JOIN scaffali s ON s.id = m.scaffale_id'
                : '';
            $scaffaleSel = $this->tableExists('scaffali')
                ? 's.codice AS scaffale_codice, s.nome AS scaffale_nome'
                : 'NULL AS scaffale_codice, NULL AS scaffale_nome';
            $collocazione = $this->fetchOne(
                "SELECT m.numero_livello, m.descrizione AS mensola_descrizione, {$scaffaleSel}
                   FROM mensole m
                   {$scaffaliJoin}
                  WHERE m.id = ?",
                'i',
                [(int) $fascicolo['collocazione_id']]
            );
        }

        // Prev/next inside the annata: same ordering as the covers grid.
        $siblings = $this->fetchAll(
            'SELECT id, numero, titolo_fascicolo
               FROM emeroteca_fascicoli
              WHERE annata_id = ? AND stato <> \'scartato\'
              ORDER BY (data_pubblicazione IS NULL), data_pubblicazione ASC, id ASC',
            'i',
            [(int) $fascicolo['annata_id']]
        );
        $prev = null;
        $next = null;
        foreach ($siblings as $i => $sib) {
            if ((int) $sib['id'] === $id) {
                $prev = $i > 0 ? $siblings[$i - 1] : null;
                $next = $i < count($siblings) - 1 ? $siblings[$i + 1] : null;
                break;
            }
        }

        // The catalogued articles placed in this issue (emeroteca_contributi.
        // fascicolo_id), in reading order. These are full records with their
        // own page; the spoglio above is the issue's bare table of contents.
        // Both are shown: neither is guaranteed to cover the other.
        $contributi = $this->tableExists('emeroteca_contributi')
            ? $this->contributions()->issueContents($id)
            : [];

        $issueLabel = sprintf(__('n. %s (%s)'), (string) $fascicolo['numero'], (string) $fascicolo['anno']);
        $title = (string) $fascicolo['testata_titolo'] . ' — ' . $issueLabel;

        return $this->renderPublic($response, 'fascicolo.php', [
            'fascicolo'    => $fascicolo,
            'articoli'     => $articoli,
            'contributi'   => $contributi,
            'collocazione' => $collocazione,
            'prev'         => $prev,
            'next'         => $next,
            'statoFascicoloLabels' => \EmerotecaPlugin::STATI_FASCICOLO,
            'tipoArticoloLabels'   => \EmerotecaPlugin::TIPI_ARTICOLO,
            'seoTitle' => $title . ' — ' . __('Emeroteca'),
            'seoDescription' => $title,
            'seoCanonical' => $this->baseUrl() . '/emeroteca/fascicolo/' . $id,
            // Withdrawn: reachable for a bookmarked link, but kept out of the
            // index — it is in no listing and in no sitemap, so indexing it
            // would advertise a holding the library no longer has.
            'seoRobots' => ((string) ($fascicolo['stato'] ?? '') === 'scartato')
                ? 'noindex,follow'
                : 'index,follow',
        ]);
    }

    // ── Data helpers ──────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function findTestata(int $id): ?array
    {
        $hasEditori = $this->tableExists('editori');
        $hasGeneri  = $this->tableExists('generi');
        $editoreSel = $hasEditori ? 'ed.nome' : 'NULL';
        $genereSel  = $hasGeneri  ? 'g.nome'  : 'NULL';
        $editoreJoin = $hasEditori ? 'LEFT JOIN editori ed ON ed.id = t.editore_id' : '';
        $genereJoin  = $hasGeneri  ? 'LEFT JOIN generi g ON g.id = t.genere_id'     : '';

        return $this->fetchOne(
            "SELECT t.*, {$editoreSel} AS editore_nome, {$genereSel} AS genere_nome
               FROM emeroteca_testate t
               {$editoreJoin}
               {$genereJoin}
              WHERE t.id = ?",
            'i',
            [$id]
        );
    }

    /**
     * Prepared-statement fetch-all with the same defensive guards as the
     * Archives plugin (prepare() may return false; never fatals).
     *
     * @param list<int|string> $params
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, string $types = '', array $params = []): array
    {
        $rows = [];
        try {
            $stmt = $this->db->prepare($sql);
            if ($stmt === false) {
                SecureLogger::error('[Emeroteca] public prepare() failed: ' . $this->db->error);
                return [];
            }
            if ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result instanceof \mysqli_result) {
                    while ($r = $result->fetch_assoc()) {
                        $rows[] = $r;
                    }
                    $result->free();
                }
            } else {
                SecureLogger::error('[Emeroteca] public query failed: ' . $stmt->error);
            }
            $stmt->close();
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] public query exception: ' . $e->getMessage());
        }
        return $rows;
    }

    /**
     * @param list<int|string> $params
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, string $types = '', array $params = []): ?array
    {
        $rows = $this->fetchAll($sql, $types, $params);
        return $rows[0] ?? null;
    }

    /** True when the given core table exists (per-request cached probe). */
    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableCache)) {
            return $this->tableCache[$table];
        }
        $exists = false;
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) AS c FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            if ($stmt !== false) {
                $stmt->bind_param('s', $table);
                if ($stmt->execute()) {
                    $res = $stmt->get_result();
                    $exists = $res instanceof \mysqli_result
                        && ((int) ($res->fetch_assoc()['c'] ?? 0)) > 0;
                }
                $stmt->close();
            }
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] table probe failed for ' . $table . ': ' . $e->getMessage());
        }
        return $this->tableCache[$table] = $exists;
    }

    /** Escape LIKE metacharacters in user input (backslash-escaped). */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** Site base URL with no trailing slash, for building canonical links. */
    private function baseUrl(): string
    {
        return rtrim(\App\Support\HtmlHelper::getBaseUrl(), '/');
    }

    // ── Rendering ─────────────────────────────────────────────────────

    /**
     * Public article results, or an empty page when the table is not there.
     *
     * emeroteca_contributi is optional from the public side's point of view:
     * an install whose schema step failed must still serve its mastheads and
     * issues. The sitemap hook and the catalogue hint already degrade this way.
     *
     * @param array<string, string> $filters see ContributionService::FILTER_FIELDS
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, pages: int}
     */
    private function articleResults(string $term, int $testata, int $page = 1, array $filters = [], int $perPage = self::PER_PAGE, int $fascicolo = 0): array
    {
        if (!$this->tableExists('emeroteca_contributi')) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
        }
        return $this->contributions()->search($term, $testata, true, $page, $filters, $fascicolo, $perPage);
    }

    /**
     * The filter values carried by the query string, trimmed and capped.
     *
     * @param array<string, mixed> $query
     * @return array<string, string> only the keys that carry a value
     */
    private function articleFilters(array $query): array
    {
        // Plugin classes have no autoloader scope: reading a constant off the
        // service is enough to need its file, and this method runs BEFORE
        // contributions() does its own lazy require.
        require_once __DIR__ . '/../Services/ContributionService.php';
        $filters = [];
        foreach (\App\Plugins\Emeroteca\Services\ContributionService::FILTER_FIELDS as $key) {
            $value = $query[$key] ?? null;
            $value = is_string($value) ? trim($value) : '';
            if ($value !== '') {
                $filters[$key] = mb_substr($value, 0, 200);
            }
        }
        return $filters;
    }

    /**
     * Sidebar facets of the public article search: the mastheads and the
     * container publications that hold published articles, each with its count.
     *
     * The counts describe the whole public corpus, not the current result set:
     * a facet is a way into the collection, and its number says how much is
     * behind it. Bounded, so a large kardex cannot turn the sidebar into the
     * page.
     *
     * @return array{testate: list<array{id:int,titolo:string,n:int}>, pubblicazioni: list<array{nome:string,n:int}>}
     */
    private function articleFacets(): array
    {
        $facets = ['testate' => [], 'pubblicazioni' => []];
        if (!$this->tableExists('emeroteca_contributi')) {
            return $facets;
        }
        if ($this->tableExists('emeroteca_testate')) {
            foreach ($this->fetchAll(
                "SELECT t.id, t.titolo, COUNT(*) AS n
                   FROM emeroteca_contributi c
                   JOIN emeroteca_testate t ON t.id = c.testata_id
                  WHERE c.pubblico = 1
                  GROUP BY t.id, t.titolo
                  ORDER BY t.titolo
                  LIMIT 200"
            ) as $row) {
                $facets['testate'][] = ['id' => (int) $row['id'], 'titolo' => (string) $row['titolo'], 'n' => (int) $row['n']];
            }
        }
        foreach ($this->fetchAll(
            "SELECT contenitore_titolo AS nome, COUNT(*) AS n
               FROM emeroteca_contributi
              WHERE pubblico = 1 AND contenitore_titolo IS NOT NULL AND contenitore_titolo <> ''
              GROUP BY contenitore_titolo
              ORDER BY n DESC, contenitore_titolo
              LIMIT 50"
        ) as $row) {
            $facets['pubblicazioni'][] = ['nome' => (string) $row['nome'], 'n' => (int) $row['n']];
        }
        return $facets;
    }

    /** Build a ContributionService bound to this controller's DB connection, loading its class file. */
    private function contributions(): \App\Plugins\Emeroteca\Services\ContributionService
    {
        require_once __DIR__ . '/../Services/ContributionService.php';
        return new \App\Plugins\Emeroteca\Services\ContributionService($this->db);
    }

    /**
     * Public articles search/listing page, optionally narrowed by author,
     * publication or keyword — the destinations the links on an article page
     * point at.
     *
     * A narrowed listing is the same corpus seen through a filter, so it is
     * served noindex/follow: one canonical /emeroteca/articoli, not one
     * indexable page per author the library happens to hold. "Narrowed" means
     * EVERY parameter that cuts the corpus — the free term and the masthead
     * as much as the three named filters — and not just the subset that
     * happens to live in ContributionService::FILTER_FIELDS.
     *
     * Pagination is the exception: page 2 is not a duplicate of page 1, so it
     * canonicalises to itself, exactly as the core catalogue and the author /
     * publisher archives do (app/Views/frontend/catalog.php,
     * app/Views/frontend/archive.php).
     */
    public function articles(ServerRequestInterface $request, ResponseInterface $response, array $args=[]): ResponseInterface
    {
        $q=$request->getQueryParams();
        $term=is_string($q['q']??null)?$q['q']:'';
        $filters=$this->articleFilters($q);
        $testata=(int)($q['testata']??0);
        // "Search in this issue" from an issue page lands here, narrowed to it.
        $fascicolo=max(0,(int)($q['fascicolo']??0));
        $fascicoloLabel='';
        if ($fascicolo>0) {
            $issue=$this->fetchOne(
                'SELECT f.numero, a.anno, t.titolo FROM emeroteca_fascicoli f JOIN emeroteca_annate a ON a.id=f.annata_id JOIN emeroteca_testate t ON t.id=a.testata_id WHERE f.id=?',
                'i',
                [$fascicolo]
            );
            $fascicoloLabel=$issue!==null
                ? (string)$issue['titolo'].', '.sprintf(__('n. %s (%s)'),(string)$issue['numero'],(string)$issue['anno'])
                : '#'.$fascicolo;
        }
        $results=$this->articleResults($term,$testata,max(1,(int)($q['page']??1)),$filters,self::PER_PAGE,$fascicolo);
        // The page the listing actually settled on: a request past the last
        // page is clamped, and the canonical must name the page it served.
        $page=max(1,(int)$results['page']);
        $narrowed=$filters!==[]||$term!==''||$testata>0||$fascicolo>0;
        return $this->renderPublic($response,'articles.php',$results+[
            'term'=>$term,
            'testata'=>$testata,
            'fascicolo'=>$fascicolo,
            'fascicoloLabel'=>$fascicoloLabel,
            'filters'=>$filters,
            'facets'=>$this->articleFacets(),
            'seoTitle'=>__('Articoli'),
            'seoCanonical'=>$this->baseUrl().'/emeroteca/articoli'.($page>1?'?page='.$page:''),
            'seoRobots'=>$narrowed?'noindex,follow':'index,follow',
        ]);
    }

    /**
     * Public single-article page. 404s when emeroteca_contributi doesn't exist, the article
     * is missing, or it isn't published (get() is called with the public-only flag).
     */
    public function article(ServerRequestInterface $request, ResponseInterface $response, array $args=[]): ResponseInterface
    {
        $row=$this->tableExists('emeroteca_contributi') ? $this->contributions()->get((int)($args['id']??0),true) : null;
        if (!$row) { return $this->renderNotFound($response)->withHeader('Cache-Control','private, no-store'); }
        $service=$this->contributions();
        $id=(int)$row['id'];
        // Where the article sits, read both ways: its neighbours in the issue
        // (so the issue can be read article by article) and what else the
        // masthead and its linked author published.
        $neighbours=$service->neighboursInIssue($row);
        $firstAuthor=0;
        foreach (\App\Plugins\Emeroteca\Services\ContributionService::authorLinks($row) as $credit) {
            if ($credit['id']!==null) { $firstAuthor=(int)$credit['id']; $firstAuthorName=$credit['name']; break; }
        }
        // Staff open the record from here (#455). The page is never cached
        // (private, no-store below), so the button cannot reach a visitor.
        $canEdit = in_array($_SESSION['user']['tipo_utente'] ?? '', ['admin', 'staff'], true);
        try {
            $genreTrail = $service->genreTrail((int)($row['genere_id'] ?? 0));
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] article genre: '.$e->getMessage());
            $genreTrail = [];
        }
        return $this->renderPublic($response,'article.php',[
            'article'=>$row,
            'neighbours'=>$neighbours,
            'relatedTestata'=>$service->relatedInTestata((int)($row['testata_id']??0),$id,4),
            'relatedAuthor'=>$firstAuthor>0 ? $service->relatedByAuthor($firstAuthor,$id,4) : [],
            'relatedAuthorName'=>$firstAuthorName??'',
            // The author's works whatever their format (#453): the books
            // beside the articles, as the author's own page lists them.
            'relatedAuthorBooks'=>$firstAuthor>0 ? $this->booksByAuthor($firstAuthor,4) : [],
            'relatedAuthorId'=>$firstAuthor,
            'canEdit'=>$canEdit,
            'genreTrail'=>$genreTrail,
            'seoTitle'=>$row['titolo'],
            'seoCanonical'=>$this->baseUrl().'/emeroteca/articolo/'.$id,
        ])->withHeader('Cache-Control','private, no-store');
    }

    /**
     * The catalogue's books credited to an author, newest first, in the row
     * shape app/Views/frontend/catalog-grid.php draws: the same query as the
     * public author page, with the catalogue's visibility rule.
     *
     * @return list<array<string,mixed>>
     */
    private function booksByAuthor(int $authorId, int $limit): array
    {
        try {
            $sql = "SELECT DISTINCT l.*,
                       (SELECT " . \App\Support\AuthorName::displaySql('a2') . " FROM libri_autori la2 JOIN autori a2 ON la2.autore_id = a2.id
                        WHERE la2.libro_id = l.id AND la2.ruolo = 'principale' LIMIT 1) AS autore,
                       (SELECT a2.nome FROM libri_autori la2 JOIN autori a2 ON la2.autore_id = a2.id
                        WHERE la2.libro_id = l.id AND la2.ruolo = 'principale' LIMIT 1) AS autore_principale_nome,
                       e.nome AS editore,
                       g.nome AS genere
                FROM libri l
                JOIN libri_autori la ON l.id = la.libro_id
                LEFT JOIN editori e ON l.editore_id = e.id
                LEFT JOIN generi g ON l.genere_id = g.id
                WHERE la.autore_id = ? AND l.deleted_at IS NULL AND " . \App\Support\BookVisibility::catalogue($this->db, 'l') . "
                ORDER BY l.anno_pubblicazione DESC, l.titolo ASC
                LIMIT ?";
            $stmt = $this->db->prepare($sql);
            if ($stmt === false) {
                return [];
            }
            $stmt->bind_param('ii', $authorId, $limit);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return $rows;
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] author books: '.$e->getMessage());
            return [];
        }
    }

    /**
     * Render a public view wrapped in the site's frontend layout —
     * verbatim port of ArchivesPlugin::renderPublic (two-pass render:
     * inner view → app/Views/frontend/layout.php, which consumes
     * $content + the seo* variables and wraps them in the public shell
     * shared with /catalogo, /autore, etc.).
     *
     * @param array<string, mixed> $data
     */
    private function renderPublic(
        ResponseInterface $response,
        string $viewFile,
        array $data,
        int $status = 200
    ): ResponseInterface {
        $viewPath = __DIR__ . '/../Views/public/' . $viewFile;
        if (!is_file($viewPath)) {
            SecureLogger::error('[Emeroteca] public view missing: ' . $viewFile);
            $response->getBody()->write('Emeroteca view not found');
            return $response->withStatus(500)->withHeader('Content-Type', 'text/plain; charset=UTF-8');
        }

        ob_start();
        extract($data, EXTR_SKIP);
        include $viewPath;
        $content = (string) ob_get_clean();

        $title = (string) ($data['seoTitle'] ?? __('Emeroteca'));
        $seoTitle = $title;
        $seoDescription = (string) ($data['seoDescription'] ?? __('Emeroteca'));
        $seoCanonical = (string) ($data['seoCanonical'] ?? ($this->baseUrl() . '/emeroteca'));
        // A withdrawn issue stays reachable — the URL may be bookmarked or
        // linked, and the page explains that the library no longer holds it —
        // but it must not enter the index: it is absent from every listing and
        // from the sitemap, so leaving it indexable would advertise a holding
        // that does not exist. Links are still followed toward the masthead.
        $seoRobots = (string) ($data['seoRobots'] ?? 'index,follow');

        // The current route proves the plugin is active. Pass the same flag
        // consumed by the shared frontend layout so its navigation does not
        // depend on a DI container that plugin-rendered pages do not expose.
        $emerotecaAvailable = true;

        $layoutPath = __DIR__ . '/../../../../../app/Views/frontend/layout.php';
        if (!is_file($layoutPath)) {
            $response->getBody()->write($content);
            return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
        $db = $this->db;
        ob_start();
        include $layoutPath;
        $html = (string) ob_get_clean();
        $response->getBody()->write($html);
        return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /** 404 page rendered inside the public layout. */
    private function renderNotFound(ResponseInterface $response): ResponseInterface
    {
        // The site's own 404 (app/Views/errors/404.php, which wraps itself in
        // the frontend layout), told what was missing here and offering the
        // emeroteca's ways back — one "not found" for the whole public site.
        $errorPage = __DIR__ . '/../../../../../app/Views/errors/404.php';
        if (!is_file($errorPage)) {
            $response->getBody()->write(__('Contenuto non trovato'));
            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=UTF-8');
        }
        $errorTitle = __('Contenuto non trovato');
        $errorDescription = __('La testata, il fascicolo o l\'articolo che cerchi non è disponibile in emeroteca.');
        $errorLinks = [
            ['href' => url('/emeroteca'), 'icon' => 'fa-newspaper', 'label' => __('Emeroteca')],
            ['href' => url('/emeroteca/articoli'), 'icon' => 'fa-file-lines', 'label' => __('Articoli')],
            ['href' => route_path('catalog'), 'icon' => 'fa-book', 'label' => __('Catalogo')],
        ];
        $seoRobots = 'noindex,follow';
        // The layout's <title> reads $seoTitle, not the $pageTitle 404.php sets.
        $seoTitle = $errorTitle . ' — ' . __('Emeroteca');
        // Same layout inputs renderPublic() supplies.
        $emerotecaAvailable = true;
        $db = $this->db;
        ob_start();
        include $errorPage;
        $html = (string) ob_get_clean();
        $response->getBody()->write($html);
        return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
