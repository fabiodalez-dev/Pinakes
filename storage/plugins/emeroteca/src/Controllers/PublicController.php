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

    /**
     * GET /emeroteca — index of testate with three switchable views
     * (?vista=az|editore|argomento) and a simple search box (?q= over
     * titolo / sottotitolo / ISSN).
     *
     * @param array<string, string> $args
     */
    public function index(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = []
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $rawQ = $params['q'] ?? '';
        $q = is_string($rawQ) ? mb_substr(trim($rawQ), 0, 200) : '';
        $rawVista = $params['vista'] ?? 'az';
        $vista = is_string($rawVista) && in_array($rawVista, ['az', 'editore', 'argomento'], true)
            ? $rawVista
            : 'az';
        $rawTipo = $params['tipo'] ?? '';
        $tipo = is_string($rawTipo) && array_key_exists($rawTipo, \EmerotecaPlugin::TIPI_TESTATA)
            ? $rawTipo
            : '';

        // Populate the public filter from real holdings only. Unknown legacy
        // values are deliberately omitted, while valid types remain ordered
        // according to the plugin vocabulary rather than database collation.
        $typeCounts = [];
        foreach ($this->fetchAll(
            'SELECT tipo, COUNT(*) AS totale FROM emeroteca_testate GROUP BY tipo',
            '',
            []
        ) as $typeRow) {
            $typeKey = (string) ($typeRow['tipo'] ?? '');
            $count = (int) ($typeRow['totale'] ?? 0);
            if ($count > 0 && array_key_exists($typeKey, \EmerotecaPlugin::TIPI_TESTATA)) {
                $typeCounts[$typeKey] = $count;
            }
        }
        $availableTypes = array_intersect_key(\EmerotecaPlugin::TIPI_TESTATA, $typeCounts);
        if ($tipo !== '' && !array_key_exists($tipo, $availableTypes)) {
            $tipo = '';
        }

        $hasEditori = $this->tableExists('editori');
        $hasGeneri  = $this->tableExists('generi');

        $editoreSel = $hasEditori ? 'ed.nome' : 'NULL';
        $genereSel  = $hasGeneri  ? 'g.nome'  : 'NULL';
        $editoreJoin = $hasEditori ? 'LEFT JOIN editori ed ON ed.id = t.editore_id' : '';
        $genereJoin  = $hasGeneri  ? 'LEFT JOIN generi g ON g.id = t.genere_id'     : '';

        $sql = "SELECT t.id, t.titolo, t.sottotitolo, t.issn, t.tipo, t.periodicita,
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
                  ) ann ON ann.testata_id = t.id";

        $where = [];
        $bindTypes = '';
        $bindValues = [];
        if ($q !== '') {
            $where[] = '(t.titolo LIKE ? ESCAPE \'\\\\\'
                         OR t.sottotitolo LIKE ? ESCAPE \'\\\\\'
                         OR t.issn LIKE ? ESCAPE \'\\\\\'
                         OR EXISTS (
                            SELECT 1
                              FROM emeroteca_articoli ar
                              JOIN emeroteca_fascicoli ef ON ef.id = ar.fascicolo_id
                              JOIN emeroteca_annate ea ON ea.id = ef.annata_id
                             WHERE ea.testata_id = t.id
                               AND (
                                    MATCH(ar.titolo, ar.autori, ar.keywords)
                                        AGAINST (? IN NATURAL LANGUAGE MODE)
                                    OR ar.titolo LIKE ? ESCAPE \'\\\\\'
                                    OR ar.autori LIKE ? ESCAPE \'\\\\\'
                                    OR ar.keywords LIKE ? ESCAPE \'\\\\\'
                               )
                         ))';
            $pattern = '%' . $this->escapeLike($q) . '%';
            $bindTypes .= 'sssssss';
            $bindValues = [$pattern, $pattern, $pattern, $q, $pattern, $pattern, $pattern];
        }
        if ($tipo !== '') {
            $where[] = 't.tipo = ?';
            $bindTypes .= 's';
            $bindValues[] = $tipo;
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        // Sorting drives the grouping headers rendered by the view.
        $sql .= match ($vista) {
            'editore'   => " ORDER BY (editore_nome IS NULL), editore_nome ASC, t.titolo ASC",
            'argomento' => " ORDER BY (genere_nome IS NULL), genere_nome ASC, t.titolo ASC",
            default     => " ORDER BY t.titolo ASC",
        };
        $sql .= ' LIMIT 500';

        $rows = $this->fetchAll($sql, $bindTypes, $bindValues);

        return $this->renderPublic($response, 'index.php', [
            'rows'  => $rows,
            'q'     => $q,
            'vista' => $vista,
            'tipo'  => $tipo,
            'availableTypes' => $availableTypes,
            'typeCounts' => $typeCounts,
            'tipoLabels' => \EmerotecaPlugin::TIPI_TESTATA,
            'seoTitle' => __('Emeroteca'),
            'seoDescription' => __('Consulta le testate di riviste, giornali e periodici conservate in emeroteca.'),
            'seoCanonical' => $this->baseUrl() . '/emeroteca',
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

        // Year timeline with per-year issue counts.
        $years = $this->fetchAll(
            'SELECT a.anno,
                    COUNT(f.id) AS num_fascicoli,
                    SUM(CASE WHEN f.stato = \'posseduto\' THEN 1 ELSE 0 END) AS num_posseduti
               FROM emeroteca_annate a
               LEFT JOIN emeroteca_fascicoli f ON f.annata_id = a.id
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
                  WHERE a.testata_id = ? AND a.anno = ?
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

        return $this->renderPublic($response, 'testata.php', [
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
            'seoCanonical' => $this->baseUrl() . '/emeroteca/' . $id,
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
              WHERE annata_id = ?
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

        $issueLabel = sprintf(__('n. %s (%s)'), (string) $fascicolo['numero'], (string) $fascicolo['anno']);
        $title = (string) $fascicolo['testata_titolo'] . ' — ' . $issueLabel;

        return $this->renderPublic($response, 'fascicolo.php', [
            'fascicolo'    => $fascicolo,
            'articoli'     => $articoli,
            'collocazione' => $collocazione,
            'prev'         => $prev,
            'next'         => $next,
            'statoFascicoloLabels' => \EmerotecaPlugin::STATI_FASCICOLO,
            'tipoArticoloLabels'   => \EmerotecaPlugin::TIPI_ARTICOLO,
            'seoTitle' => $title . ' — ' . __('Emeroteca'),
            'seoDescription' => $title,
            'seoCanonical' => $this->baseUrl() . '/emeroteca/fascicolo/' . $id,
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

    private function baseUrl(): string
    {
        return rtrim(\App\Support\HtmlHelper::getBaseUrl(), '/');
    }

    // ── Rendering ─────────────────────────────────────────────────────

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
        return $this->renderPublic($response, 'not-found.php', [
            'seoTitle' => __('Contenuto non trovato') . ' — ' . __('Emeroteca'),
            'seoDescription' => __('Contenuto non trovato'),
            'seoCanonical' => $this->baseUrl() . '/emeroteca',
        ], 404);
    }
}
