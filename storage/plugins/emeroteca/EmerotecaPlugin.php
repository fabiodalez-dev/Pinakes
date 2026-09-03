<?php

declare(strict_types=1);

use App\Support\HookManager;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Emeroteca plugin — periodicals management for Pinakes.
 *
 * Skeleton (v1.0.0). Introduces four tables:
 *   - emeroteca_testate   : periodical titles (rivista/giornale/magazine/…)
 *   - emeroteca_annate    : yearly volumes of a title (bound or loose)
 *   - emeroteca_fascicoli : single issues with holding status + kardex
 *   - emeroteca_articoli  : article-level indexing (spoglio) with FULLTEXT
 *
 * Lifecycle mirrors the Archives plugin (storage/plugins/archives):
 * ensureSchema() is idempotent (CREATE TABLE IF NOT EXISTS) and runs from
 * both onActivate() and onInstall(); activation registers runtime hooks
 * via direct plugin_hooks rows (registerHookInDb) — never doAction()/
 * applyFilters() from onActivate, which would trigger loadHooks() before
 * the guard and duplicate routes.
 *
 * The class lives in the global namespace because PluginManager::
 * getPluginClassName('emeroteca') resolves to 'EmerotecaPlugin' and the
 * main_file is loaded via require, with no PSR-4 scope for plugins.
 * (Archives uses a wrapper.php proxy for the same reason; a single
 * global-namespace class — the DigitalLibraryPlugin pattern — needs no
 * wrapper.)
 */
class EmerotecaPlugin
{
    private mysqli $db;
    private HookManager $hookManager;
    private ?int $pluginId = null;

    /**
     * Periodicity values, per spec (frequenza di pubblicazione).
     */
    public const PERIODICITA = [
        'quotidiano'   => 'Quotidiano',
        'settimanale'  => 'Settimanale',
        'quindicinale' => 'Quindicinale',
        'mensile'      => 'Mensile',
        'bimestrale'   => 'Bimestrale',
        'trimestrale'  => 'Trimestrale',
        'semestrale'   => 'Semestrale',
        'annuale'      => 'Annuale',
        'irregolare'   => 'Irregolare',
    ];

    /** Publication types for a testata. */
    public const TIPI_TESTATA = [
        'rivista'    => 'Rivista',
        'giornale'   => 'Giornale',
        'magazine'   => 'Magazine',
        'bollettino' => 'Bollettino',
        'fanzine'    => 'Fanzine',
    ];

    /** Collection status of a testata. */
    public const STATI_RACCOLTA = [
        'attiva'   => 'Attiva',
        'chiusa'   => 'Chiusa',
        'dismessa' => 'Dismessa',
    ];

    /** Holding status of a single fascicolo. */
    public const STATI_FASCICOLO = [
        'posseduto'   => 'Posseduto',
        'mancante'    => 'Mancante',
        'danneggiato' => 'Danneggiato',
        'in_restauro' => 'In restauro',
        'smarrito'    => 'Smarrito',
        'atteso'      => 'Atteso',
    ];

    /** Article types for the spoglio. */
    public const TIPI_ARTICOLO = [
        'articolo'   => 'Articolo',
        'editoriale' => 'Editoriale',
        'recensione' => 'Recensione',
        'intervista' => 'Intervista',
        'dossier'    => 'Dossier',
        'rubrica'    => 'Rubrica',
    ];

    /**
     * PluginManager::runPluginMethod() instantiates every plugin with
     * ($this->db, $this->hookManager) — the plugin must match this
     * signature even before the hooks are wired.
     */
    public function __construct(mysqli $db, HookManager $hookManager)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;
    }

    public function setPluginId(int $pluginId): void
    {
        $this->pluginId = $pluginId;
    }

    /**
     * Expose the injected HookManager (DI-wiring accessor, mirrors
     * ArchivesPlugin::getHookManager — keeps static analysis happy).
     */
    public function getHookManager(): HookManager
    {
        return $this->hookManager;
    }

    // ── Lifecycle ─────────────────────────────────────────────────────

    /**
     * Called by PluginManager when the plugin is activated via the admin UI.
     * Creates the emeroteca schema if missing, then registers the plugin's
     * runtime hooks. Idempotent: the DDLs use CREATE TABLE IF NOT EXISTS
     * and each hook insert is preceded by a targeted DELETE.
     *
     * Throws on partial-schema failure so PluginManager does not mark the
     * plugin active with missing tables.
     */
    public function onActivate(): void
    {
        $result = $this->ensureSchema();
        if (!empty($result['failed'])) {
            throw new \RuntimeException(
                '[Emeroteca] Schema activation failed for: ' . implode(', ', $result['failed'])
                . '. See app.log for the mysqli error emitted during each CREATE TABLE.'
            );
        }
        $this->db->begin_transaction();
        try {
            $this->registerHookInDb('app.routes.register', 'registerRoutes',        10);
            $this->registerHookInDb('admin.menu.render',   'renderAdminMenuEntry',  10);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function onInstall(): void
    {
        $result = $this->ensureSchema();
        if (!empty($result['failed'])) {
            throw new \RuntimeException(
                '[Emeroteca] Schema install failed for: ' . implode(', ', $result['failed'])
            );
        }
    }

    /**
     * Called when deactivated. Keeps the tables in place — dropping them
     * would delete periodical holdings, which are more valuable than a
     * clean uninstall. Hooks are removed so routes stop responding.
     */
    public function onDeactivate(): void
    {
        $this->deleteHooksFromDb();
    }

    public function onUninstall(): void
    {
        // Tables are intentionally preserved (same policy as Archives).
        SecureLogger::debug('[Emeroteca] Plugin uninstalled');
    }

    // ── Hook registration (plugin_hooks rows, never doAction) ─────────

    /**
     * Register a hook for this plugin in the `plugin_hooks` table.
     * Pattern borrowed from ArchivesPlugin/DeweyEditorPlugin.
     */
    private function registerHookInDb(string $hookName, string $method, int $priority): void
    {
        if ($this->pluginId === null) {
            SecureLogger::warning('[Emeroteca] pluginId not set; cannot register hook ' . $hookName);
            return;
        }
        // Clear existing entries for this (plugin, hook, method) to avoid
        // duplicates on re-activation.
        $del = $this->db->prepare(
            'DELETE FROM plugin_hooks WHERE plugin_id = ? AND hook_name = ? AND callback_method = ?'
        );
        if ($del !== false) {
            $del->bind_param('iss', $this->pluginId, $hookName, $method);
            $del->execute();
            $del->close();
        }
        $stmt = $this->db->prepare(
            'INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW())'
        );
        if ($stmt === false) {
            $err = $this->db->error;
            SecureLogger::error('[Emeroteca] prepare() failed: ' . $err);
            throw new \RuntimeException('[Emeroteca] prepare() failed for hook ' . $hookName . ': ' . $err);
        }
        $callbackClass = 'EmerotecaPlugin';
        $stmt->bind_param('isssi', $this->pluginId, $hookName, $callbackClass, $method, $priority);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            SecureLogger::error('[Emeroteca] hook insert failed: ' . $err);
            throw new \RuntimeException('[Emeroteca] hook insert failed for ' . $hookName . ': ' . $err);
        }
        $stmt->close();
    }

    /**
     * Remove every hook registration this plugin owns. Called from
     * onDeactivate() so routes stop being invoked once inactive.
     */
    private function deleteHooksFromDb(): void
    {
        if ($this->pluginId === null) {
            return;
        }
        $stmt = $this->db->prepare('DELETE FROM plugin_hooks WHERE plugin_id = ?');
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] deleteHooksFromDb prepare failed: ' . $this->db->error);
            return;
        }
        $stmt->bind_param('i', $this->pluginId);
        $stmt->execute();
        $stmt->close();
    }

    // ── Schema ────────────────────────────────────────────────────────

    /**
     * Tables this plugin's ensureSchema() always creates. Declared so
     * PluginManager's boot-time self-heal re-runs ensureSchema when any
     * is missing on an already-active plugin (partial/aborted upgrade).
     *
     * @return list<string>
     */
    public function expectedTables(): array
    {
        return array_keys(self::schemaSteps());
    }

    /**
     * One sentinel column per table, declared to PluginManager's
     * boot-time self-heal (expectedColumnsMissing). Cheap: one
     * information_schema probe per entry per boot. Future additive
     * column migrations must be appended here (ncip-server pattern).
     *
     * @return list<array{table:string, column:string}>
     */
    public function expectedColumns(): array
    {
        return [
            ['table' => 'emeroteca_testate',   'column' => 'stato_raccolta'],
            ['table' => 'emeroteca_annate',    'column' => 'rilegata'],
            ['table' => 'emeroteca_fascicoli', 'column' => 'stato'],
            ['table' => 'emeroteca_articoli',  'column' => 'keywords'],
        ];
    }

    /**
     * Foreign keys declared to PluginManager::bundledSchemaIncomplete()
     * so a stale-class admin-UI upgrade self-heals on the next boot
     * (ncip-server pattern). Internal FKs are always declared; the FKs
     * towards the optional core tables (editori, generi) are declared
     * ONLY when the referenced table exists — on installs where it is
     * missing the schema legitimately degrades without those FKs, and
     * declaring them would churn onActivate on every boot.
     *
     * @return list<array{table:string, column:string, ref_table:string}>
     */
    public function expectedForeignKeys(): array
    {
        $out = [
            ['table' => 'emeroteca_testate',   'column' => 'testata_precedente_id', 'ref_table' => 'emeroteca_testate'],
            ['table' => 'emeroteca_annate',    'column' => 'testata_id',            'ref_table' => 'emeroteca_testate'],
            ['table' => 'emeroteca_fascicoli', 'column' => 'annata_id',             'ref_table' => 'emeroteca_annate'],
            ['table' => 'emeroteca_articoli',  'column' => 'fascicolo_id',          'ref_table' => 'emeroteca_fascicoli'],
        ];
        foreach (self::coreForeignKeyDefs() as $fk) {
            try {
                if ($this->coreTableExists($fk['ref_table'])) {
                    $out[] = [
                        'table'     => $fk['table'],
                        'column'    => $fk['column'],
                        'ref_table' => $fk['ref_table'],
                    ];
                }
            } catch (\Throwable $e) {
                // "Cannot probe" must not imply "missing": skip silently.
            }
        }
        return $out;
    }

    /** @return array<string,string> table => CREATE DDL, in dependency order. */
    private static function schemaSteps(): array
    {
        return [
            'emeroteca_testate'   => self::ddlTestate(),
            'emeroteca_annate'    => self::ddlAnnate(),
            'emeroteca_fascicoli' => self::ddlFascicoli(),
            'emeroteca_articoli'  => self::ddlArticoli(),
        ];
    }

    /**
     * Execute the DDL for the four emeroteca tables, then add the FKs
     * towards the optional core tables (editori, generi) when those
     * exist. CREATE TABLE failures are logged and reported via the
     * returned 'failed' list without throwing — onActivate()/onInstall()
     * inspect it and abort with a RuntimeException.
     *
     * @return array{created: list<string>, failed: list<string>}
     */
    public function ensureSchema(): array
    {
        $steps = self::schemaSteps();
        $created = [];
        $failed = [];

        foreach ($steps as $table => $ddl) {
            try {
                $result = $this->db->query($ddl);
                if ($result === false) {
                    $failed[] = $table;
                    SecureLogger::warning(
                        '[Emeroteca] CREATE TABLE failed for ' . $table . ': ' . $this->db->error
                    );
                } else {
                    $created[] = $table;
                }
            } catch (\Throwable $e) {
                $failed[] = $table;
                SecureLogger::error(
                    '[Emeroteca] Exception during CREATE TABLE ' . $table . ': ' . $e->getMessage()
                );
            }
        }

        // FKs towards core tables (editori, generi) are added after the
        // CREATE so an install where those tables are missing degrades
        // to a schema without the FK instead of failing activation.
        if (!in_array('emeroteca_testate', $failed, true) && !$this->ensureCoreForeignKeys()) {
            $failed[] = 'emeroteca_testate';
        }

        return ['created' => $created, 'failed' => $failed];
    }

    /**
     * Single source of truth for the FKs towards optional core tables,
     * shared by ensureCoreForeignKeys() (which adds them) and
     * expectedForeignKeys() (self-heal probe). ncip-server pattern.
     *
     * @return list<array{table:string, column:string, ref_table:string, ref_col:string, name:string}>
     */
    private static function coreForeignKeyDefs(): array
    {
        return [
            ['table' => 'emeroteca_testate', 'column' => 'editore_id', 'ref_table' => 'editori', 'ref_col' => 'id', 'name' => 'fk_emeroteca_testata_editore'],
            ['table' => 'emeroteca_testate', 'column' => 'genere_id',  'ref_table' => 'generi',  'ref_col' => 'id', 'name' => 'fk_emeroteca_testata_genere'],
        ];
    }

    /** True when the given core table exists in the current schema. */
    private function coreTableExists(string $table): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        if ($stmt === false) {
            throw new \RuntimeException('[Emeroteca] table probe prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('s', $table);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('[Emeroteca] table probe failed for ' . $table . ': ' . $err);
        }
        $res = $stmt->get_result();
        $exists = $res instanceof \mysqli_result
            && ((int) ($res->fetch_assoc()['c'] ?? 0)) > 0;
        $stmt->close();
        return $exists;
    }

    /**
     * Add the emeroteca_testate FKs towards editori/generi when the core
     * table exists and the FK is missing. Where the core table is absent
     * (partial/headless installs) the constraint is skipped with a
     * warning — the column stays a plain nullable INT. Detects each FK
     * by column + referenced table via KEY_COLUMN_USAGE, nulls out
     * orphan rows first, then ALTERs it in with ON DELETE SET NULL.
     * Idempotent, safe to re-run from onActivate/onInstall.
     * All table/column names below are static literals — no user input.
     *
     * @return bool True when every applicable FK is present (or was
     *              added / legitimately skipped); false on probe or
     *              ALTER failure so the caller reports a partial schema.
     */
    private function ensureCoreForeignKeys(): bool
    {
        $ok = true;
        foreach (self::coreForeignKeyDefs() as $fk) {
            try {
                if (!$this->coreTableExists($fk['ref_table'])) {
                    SecureLogger::warning(
                        '[Emeroteca] Core table ' . $fk['ref_table']
                        . ' missing; skipping FK ' . $fk['name'] . ' (degraded schema, column stays plain)'
                    );
                    continue;
                }
            } catch (\Throwable $e) {
                SecureLogger::error('[Emeroteca] ' . $e->getMessage());
                $ok = false;
                continue;
            }

            $stmt = $this->db->prepare(
                "SELECT COUNT(*) AS c FROM information_schema.KEY_COLUMN_USAGE
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'emeroteca_testate'
                    AND COLUMN_NAME = ?
                    AND REFERENCED_TABLE_NAME = ?"
            );
            if ($stmt === false) {
                SecureLogger::error('[Emeroteca] FK probe prepare failed: ' . $this->db->error);
                $ok = false;
                continue;
            }
            $stmt->bind_param('ss', $fk['column'], $fk['ref_table']);
            if (!$stmt->execute()) {
                SecureLogger::error('[Emeroteca] FK probe failed for ' . $fk['column'] . ': ' . $stmt->error);
                $stmt->close();
                $ok = false;
                continue;
            }
            $res = $stmt->get_result();
            $exists = $res instanceof \mysqli_result
                && ((int) ($res->fetch_assoc()['c'] ?? 0)) > 0;
            $stmt->close();
            if ($exists) {
                continue;
            }

            // Null out orphan references that would fail the FK on ADD.
            if ($this->db->query(
                "UPDATE emeroteca_testate t
                 LEFT JOIN {$fk['ref_table']} r ON t.{$fk['column']} = r.{$fk['ref_col']}
                 SET t.{$fk['column']} = NULL
                 WHERE t.{$fk['column']} IS NOT NULL AND r.{$fk['ref_col']} IS NULL"
            ) === false) {
                SecureLogger::error('[Emeroteca] Orphan cleanup for ' . $fk['column'] . ' failed: ' . $this->db->error);
                $ok = false;
                continue;
            }

            $alter = "ALTER TABLE emeroteca_testate
                      ADD CONSTRAINT {$fk['name']}
                      FOREIGN KEY ({$fk['column']}) REFERENCES {$fk['ref_table']} ({$fk['ref_col']}) ON DELETE SET NULL";
            if ($this->db->query($alter) === false) {
                SecureLogger::error('[Emeroteca] Adding FK ' . $fk['name'] . ' failed: ' . $this->db->error);
                $ok = false;
            }
        }
        return $ok;
    }

    // ── DDL ───────────────────────────────────────────────────────────

    /**
     * DDL for `emeroteca_testate` — the periodical title (testata).
     *
     * editore_id / genere_id reference core tables that may be missing on
     * partial installs: the FK is NOT declared here but added afterwards
     * by ensureCoreForeignKeys() only when the core table exists. The
     * self-referencing testata_precedente_id FK (title history: "continua
     * da") is safe in the CREATE because the table references itself.
     */
    public static function ddlTestate(): string
    {
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS emeroteca_testate (
            id                    INT          NOT NULL AUTO_INCREMENT,
            titolo                VARCHAR(255) NOT NULL,
            sottotitolo           VARCHAR(255) NULL,
            issn                  VARCHAR(9)   NULL,
            editore_id            INT          NULL,
            luogo_pubblicazione   VARCHAR(255) NULL,
            lingua                VARCHAR(10)  NULL,
            periodicita           ENUM('quotidiano','settimanale','quindicinale','mensile','bimestrale','trimestrale','semestrale','annuale','irregolare') NULL,
            tipo                  ENUM('rivista','giornale','magazine','bollettino','fanzine') NOT NULL DEFAULT 'rivista',
            anno_inizio           SMALLINT     NULL,
            anno_fine             SMALLINT     NULL,
            testata_precedente_id INT          NULL,
            genere_id             INT          NULL,
            logo_url              VARCHAR(500) NULL,
            descrizione           TEXT         NULL,
            note                  TEXT         NULL,
            stato_raccolta        ENUM('attiva','chiusa','dismessa') NOT NULL DEFAULT 'attiva',
            created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_emeroteca_titolo (titolo),
            KEY idx_emeroteca_editore (editore_id),
            KEY idx_emeroteca_genere (genere_id),
            KEY idx_emeroteca_testata_prec (testata_precedente_id),
            CONSTRAINT fk_emeroteca_testata_prec
                FOREIGN KEY (testata_precedente_id) REFERENCES emeroteca_testate(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;
    }

    /**
     * DDL for `emeroteca_annate` — one row per (title, year, volume).
     * UNIQUE(testata_id, anno, volume): NULL volumes compare distinct in
     * MySQL, so titles with a single unnamed volume per year should store
     * volume = '' rather than NULL if strict uniqueness is required.
     */
    public static function ddlAnnate(): string
    {
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS emeroteca_annate (
            id            INT          NOT NULL AUTO_INCREMENT,
            testata_id    INT          NOT NULL,
            anno          SMALLINT     NOT NULL,
            volume        VARCHAR(50)  NULL,
            rilegata      TINYINT(1)   NOT NULL DEFAULT 0,
            copertina_url VARCHAR(500) NULL,
            note          TEXT         NULL,
            created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_emeroteca_annata (testata_id, anno, volume),
            CONSTRAINT fk_emeroteca_annata_testata
                FOREIGN KEY (testata_id) REFERENCES emeroteca_testate(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;
    }

    /**
     * DDL for `emeroteca_fascicoli` — single issues. collocazione_id is a
     * plain nullable INT (no FK): shelf/position wiring arrives with the
     * controllers and the core collocazione tables are not guaranteed here.
     */
    public static function ddlFascicoli(): string
    {
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS emeroteca_fascicoli (
            id                 INT          NOT NULL AUTO_INCREMENT,
            annata_id          INT          NOT NULL,
            numero             VARCHAR(50)  NOT NULL,
            numero_progressivo VARCHAR(50)  NULL,
            titolo_fascicolo   VARCHAR(255) NULL,
            data_copertina     VARCHAR(100) NULL,
            data_pubblicazione DATE         NULL,
            pagine             SMALLINT     NULL,
            copertina_url      VARCHAR(500) NULL,
            numero_inventario  VARCHAR(100) NULL,
            collocazione_id    INT          NULL,
            stato              ENUM('posseduto','mancante','danneggiato','in_restauro','smarrito','atteso') NOT NULL DEFAULT 'posseduto',
            supplementi        VARCHAR(500) NULL,
            note               TEXT         NULL,
            created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_emeroteca_fascicolo_annata (annata_id),
            KEY idx_emeroteca_fascicolo_stato (stato),
            CONSTRAINT fk_emeroteca_fascicolo_annata
                FOREIGN KEY (annata_id) REFERENCES emeroteca_annate(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;
    }

    /**
     * DDL for `emeroteca_articoli` — article-level indexing (spoglio)
     * with an InnoDB FULLTEXT index over titolo + autori + keywords.
     */
    public static function ddlArticoli(): string
    {
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS emeroteca_articoli (
            id            INT          NOT NULL AUTO_INCREMENT,
            fascicolo_id  INT          NOT NULL,
            titolo        VARCHAR(500) NOT NULL,
            autori        VARCHAR(500) NULL,
            pagina_inizio SMALLINT     NULL,
            pagina_fine   SMALLINT     NULL,
            tipo          ENUM('articolo','editoriale','recensione','intervista','dossier','rubrica') NOT NULL DEFAULT 'articolo',
            keywords      VARCHAR(500) NULL,
            created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_emeroteca_articolo_fascicolo (fascicolo_id),
            FULLTEXT KEY ft_emeroteca_articoli (titolo, autori, keywords),
            CONSTRAINT fk_emeroteca_articolo_fascicolo
                FOREIGN KEY (fascicolo_id) REFERENCES emeroteca_fascicoli(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;
    }

    // ── Routes ────────────────────────────────────────────────────────

    /**
     * Hook callback for `app.routes.register`. Registers every admin and
     * public route of the plugin. The controllers live in
     * src/Controllers/ and are referenced BY CLASS NAME through
     * dispatch(): they are loaded lazily at request time, so the routes
     * can be registered before the controller files ship.
     *
     * Admin routes are English literals (decision: issue #145) —
     * /admin/periodicals; the public section uses the technical literal
     * /emeroteca (non-localized endpoint, same class as /calendar).
     *
     * @param \Slim\App<\Psr\Container\ContainerInterface|null> $app
     */
    public function registerRoutes($app): void
    {
        $plugin = $this;
        $adminMiddleware = new \App\Middleware\AdminAuthMiddleware();
        $csrfMiddleware  = new \App\Middleware\CsrfMiddleware();

        $admin  = 'App\\Plugins\\Emeroteca\\Controllers\\PeriodicalAdminController';
        $issues = 'App\\Plugins\\Emeroteca\\Controllers\\IssueAdminController';
        $public = 'App\\Plugins\\Emeroteca\\Controllers\\PublicController';

        // ── Admin — testate (periodical titles) ──────────────────────

        // GET /admin/periodicals — list of testate
        $app->get('/admin/periodicals', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin, $admin): ResponseInterface {
            return $plugin->dispatch($admin, 'index', $request, $response);
        })->add($adminMiddleware);

        // GET /admin/periodicals/create — blank create form
        $app->get('/admin/periodicals/create', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin, $admin): ResponseInterface {
            return $plugin->dispatch($admin, 'createForm', $request, $response);
        })->add($adminMiddleware);

        // POST /admin/periodicals/create — validate + INSERT + redirect
        $app->post('/admin/periodicals/create', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin, $admin): ResponseInterface {
            return $plugin->dispatch($admin, 'createSubmit', $request, $response);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // GET /admin/periodicals/edit/{id} — edit form pre-populated
        $app->get('/admin/periodicals/edit/{id:[0-9]+}', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin, $admin): ResponseInterface {
            return $plugin->dispatch($admin, 'editForm', $request, $response, $args);
        })->add($adminMiddleware);

        // POST /admin/periodicals/edit/{id} — validate + UPDATE
        $app->post('/admin/periodicals/edit/{id:[0-9]+}', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin, $admin): ResponseInterface {
            return $plugin->dispatch($admin, 'editSubmit', $request, $response, $args);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // POST /admin/periodicals/delete/{id} — delete testata
        $app->post('/admin/periodicals/delete/{id:[0-9]+}', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin, $admin): ResponseInterface {
            return $plugin->dispatch($admin, 'delete', $request, $response, $args);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // ── Admin — annate + fascicoli of one testata ────────────────

        // GET /admin/periodicals/{id}/issues — manage annate + fascicoli
        $app->get('/admin/periodicals/{id:[0-9]+}/issues', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin, $issues): ResponseInterface {
            return $plugin->dispatch($issues, 'manage', $request, $response, $args);
        })->add($adminMiddleware);

        // POST /admin/periodicals/{id}/issues — create annata / fascicolo
        $app->post('/admin/periodicals/{id:[0-9]+}/issues', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin, $issues): ResponseInterface {
            return $plugin->dispatch($issues, 'manageSubmit', $request, $response, $args);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // POST /admin/periodicals/{id}/issues/bulk — serial issue creation
        $app->post('/admin/periodicals/{id:[0-9]+}/issues/bulk', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin, $issues): ResponseInterface {
            return $plugin->dispatch($issues, 'bulkCreate', $request, $response, $args);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // POST /admin/periodicals/{id}/kardex/generate — expected issues
        $app->post('/admin/periodicals/{id:[0-9]+}/kardex/generate', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin, $issues): ResponseInterface {
            return $plugin->dispatch($issues, 'kardexGenerate', $request, $response, $args);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // GET /admin/periodicals/issue/{id} — fascicolo detail (data + cover + spoglio)
        $app->get('/admin/periodicals/issue/{id:[0-9]+}', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin, $issues): ResponseInterface {
            return $plugin->dispatch($issues, 'show', $request, $response, $args);
        })->add($adminMiddleware);

        // POST /admin/periodicals/issue/{id} — update fascicolo
        $app->post('/admin/periodicals/issue/{id:[0-9]+}', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin, $issues): ResponseInterface {
            return $plugin->dispatch($issues, 'update', $request, $response, $args);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // POST /admin/periodicals/issue/{id}/delete — delete fascicolo
        $app->post('/admin/periodicals/issue/{id:[0-9]+}/delete', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin, $issues): ResponseInterface {
            return $plugin->dispatch($issues, 'delete', $request, $response, $args);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // ── Public frontend — read-only /emeroteca section ───────────
        // No auth: periodicals are public cultural material. Literal
        // technical path (non-localized), like /calendar/*.ics.

        // GET /emeroteca — index of testate
        $app->get('/emeroteca', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin, $public): ResponseInterface {
            return $plugin->dispatch($public, 'index', $request, $response);
        });

        // GET /emeroteca/fascicolo/{id} — fascicolo detail
        // Registered before /emeroteca/{id}; the [0-9]+ constraint on the
        // latter keeps 'fascicolo' from matching it anyway.
        $app->get('/emeroteca/fascicolo/{id:[0-9]+}', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin, $public): ResponseInterface {
            return $plugin->dispatch($public, 'showFascicolo', $request, $response, $args);
        });

        // GET /emeroteca/{id} — testata detail
        $app->get('/emeroteca/{id:[0-9]+}', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin, $public): ResponseInterface {
            return $plugin->dispatch($public, 'showTestata', $request, $response, $args);
        });
    }

    /**
     * Lazy controller dispatcher. Controllers are referenced by class
     * name in registerRoutes(); the file src/Controllers/<ShortName>.php
     * is require_once'd at request time (there is no PSR-4 autoloader
     * scope for plugin classes — same reason the Archives plugin
     * require_once's RicJsonLdBuilder.php). Until a controller ships,
     * its routes answer 503 instead of fataling.
     *
     * Controller contract: __construct(mysqli $db, HookManager $hookManager)
     * and action methods (ServerRequestInterface, ResponseInterface, array $args).
     *
     * @param array<string,string> $args
     */
    public function dispatch(
        string $class,
        string $method,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = []
    ): ResponseInterface {
        if (!class_exists($class)) {
            $pos = strrpos($class, '\\');
            $short = $pos === false ? $class : substr($class, $pos + 1);
            if (preg_match('/^[A-Za-z0-9_]+$/', $short) === 1) {
                $file = __DIR__ . '/src/Controllers/' . $short . '.php';
                if (is_file($file)) {
                    require_once $file;
                }
            }
        }
        if (!class_exists($class) || !method_exists($class, $method)) {
            SecureLogger::error('[Emeroteca] Controller not available: ' . $class . '::' . $method);
            $message = function_exists('__')
                ? __('Funzione non ancora disponibile')
                : 'Funzione non ancora disponibile';
            $response->getBody()->write($message);
            return $response
                ->withStatus(503)
                ->withHeader('Content-Type', 'text/plain; charset=UTF-8');
        }
        $controller = new $class($this->db, $this->hookManager);
        return $controller->{$method}($request, $response, $args);
    }

    // ── Shared helpers (used by controllers + views) ──────────────────

    /**
     * Kardex: expected number of issues per year for each known
     * periodicita. 'irregolare' is intentionally absent — no expected
     * issues can be generated for it.
     *
     * @return array<string,int>
     */
    public static function kardexIssuesPerYear(): array
    {
        return [
            'quotidiano'   => 365,
            'settimanale'  => 52,
            'quindicinale' => 24,
            'mensile'      => 12,
            'bimestrale'   => 6,
            'trimestrale'  => 4,
            'semestrale'   => 2,
            'annuale'      => 1,
        ];
    }

    /**
     * Shared "consistenza" string for a testata: range of years with at
     * least one owned issue plus the count of gaps (fascicoli marked
     * 'mancante'). Used by both the admin list and the issues page so
     * the two never disagree. Examples: "1990–2005 · lacune: 3",
     * "1998", "—" (no holdings yet).
     */
    public static function consistenzaTestata(mysqli $db, int $testataId): string
    {
        $stmt = $db->prepare(
            "SELECT
                MIN(CASE WHEN f.stato = 'posseduto' THEN a.anno END) AS anno_min,
                MAX(CASE WHEN f.stato = 'posseduto' THEN a.anno END) AS anno_max,
                COALESCE(SUM(f.stato = 'mancante'), 0)               AS lacune
             FROM emeroteca_annate a
             LEFT JOIN emeroteca_fascicoli f ON f.annata_id = a.id
             WHERE a.testata_id = ?"
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] consistenza prepare failed: ' . $db->error);
            return '—';
        }
        $stmt->bind_param('i', $testataId);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] consistenza query failed: ' . $stmt->error);
            $stmt->close();
            return '—';
        }
        $res = $stmt->get_result();
        $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!is_array($row)) {
            return '—';
        }
        $min    = $row['anno_min'] !== null ? (int) $row['anno_min'] : null;
        $max    = $row['anno_max'] !== null ? (int) $row['anno_max'] : null;
        $lacune = (int) $row['lacune'];

        if ($min === null) {
            $out = '—';
        } elseif ($max === null || $max === $min) {
            $out = (string) $min;
        } else {
            $out = $min . '–' . $max;
        }
        if ($lacune > 0) {
            $label = function_exists('__') ? __('lacune') : 'lacune';
            $out .= ' · ' . $label . ': ' . $lacune;
        }
        return $out;
    }

    // ── Admin menu ────────────────────────────────────────────────────

    /**
     * Hook callback for `admin.menu.render`. Echoes a sidebar nav entry
     * matching the Tailwind pattern used by the core menu items in
     * `app/Views/layout.php` (classes copied verbatim from
     * ArchivesPlugin::renderAdminMenuEntry). Action-style hook — output
     * goes to the response buffer directly, no return value needed.
     */
    public function renderAdminMenuEntry(): void
    {
        // Guard the base path via url() just like every other sidebar item.
        $href = htmlspecialchars(url('/admin/periodicals'), ENT_QUOTES, 'UTF-8');
        $title = function_exists('__') ? __('Emeroteca') : 'Emeroteca';
        $subtitle = function_exists('__') ? __('Riviste e periodici') : 'Riviste e periodici';
        echo <<<HTML

          <a class="nav-link group flex items-center px-4 py-3 rounded-lg transition-all duration-200 hover:bg-gray-100 text-gray-700 hover:text-gray-900"
            href="$href">
            <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-100 group-hover:bg-gray-200 transition-all duration-200">
              <i class="fas fa-newspaper text-gray-600"></i>
            </div>
            <div class="ml-3">
              <div class="font-medium">$title</div>
              <div class="text-xs text-gray-500">$subtitle</div>
            </div>
          </a>

        HTML;
    }
}
