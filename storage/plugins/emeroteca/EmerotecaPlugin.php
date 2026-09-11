<?php

declare(strict_types=1);

use App\Support\HookManager;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

/**
 * Emeroteca plugin — periodicals management for Pinakes.
 *
 * Introduces six tables:
 *   - emeroteca_testate     : periodical titles (rivista/giornale/magazine/…)
 *   - emeroteca_annate      : yearly volumes of a title (bound or loose)
 *   - emeroteca_fascicoli   : single issues with holding status + kardex
 *   - emeroteca_articoli    : article-level indexing (spoglio) with FULLTEXT
 *   - emeroteca_abbonamenti : subscriptions (fornitore, costo, scadenza)
 *   - emeroteca_contributi  : standalone articles, citation kept on the row
 *                             so one can be catalogued without owning the issue
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

    /**
     * Holding status of a single fascicolo (possesso). Twin of the
     * emeroteca_fascicoli.stato ENUM — keys MUST match the ENUM members
     * in the same order (the 1.4.0 schema test enforces the parity).
     * Physical condition moved to COND_FASCICOLO in 1.4.0: the legacy
     * states 'danneggiato'/'in_restauro' are migrated by
     * ensureStatoCondizioneSplit() to stato='posseduto' + condizione.
     */
    public const STATI_FASCICOLO = [
        'posseduto' => 'Posseduto',
        'mancante'  => 'Mancante',
        'atteso'    => 'Atteso',
        'smarrito'  => 'Smarrito',
        'reclamato' => 'Reclamato',
        'scartato'  => 'Scartato',
    ];

    /**
     * Physical condition of an owned fascicolo (1.4.0). Twin of the
     * emeroteca_fascicoli.condizione ENUM — keep aligned.
     */
    public const COND_FASCICOLO = [
        'buono'       => 'Buono',
        'discreto'    => 'Discreto',
        'danneggiato' => 'Danneggiato',
        'in_restauro' => 'In restauro',
    ];

    /**
     * Acquisition channels (1.4.0). Twin of the ENUM shared by
     * emeroteca_testate.acquisizione_default and
     * emeroteca_fascicoli.acquisizione.
     */
    public const TIPI_ACQUISIZIONE = [
        'abbonamento' => 'Abbonamento',
        'acquisto'    => 'Acquisto',
        'dono'        => 'Dono',
        'scambio'     => 'Scambio',
        'deposito'    => 'Deposito',
    ];

    /**
     * Lending policy of a testata (1.4.0). Twin of the
     * emeroteca_testate.prestabile ENUM.
     */
    public const OPZIONI_PRESTABILE = [
        'escluso'       => 'Escluso',
        'consultazione' => 'Consultazione',
        'prestabile'    => 'Prestabile',
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
    public function hasSettingsPage(): bool { return true; }

    public function getSettingsViewPath(): string { return __DIR__ . "/src/Views/settings.php"; }

    public function contributionService(): \App\Plugins\Emeroteca\Services\ContributionService
    {
        require_once __DIR__ . "/src/Services/ContributionService.php";
        return new \App\Plugins\Emeroteca\Services\ContributionService($this->db);
    }

    public static function ddlContributi(): string
    {
        require_once __DIR__ . "/src/Services/ContributionService.php";
        return \App\Plugins\Emeroteca\Services\ContributionService::ddl();
    }

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
            // Documents the /api/v1/periodicals bridge inside mobile-api's
            // /api/v1/openapi.json, so the add-endpoint => add-manifest-row
            // guard sees the whole surface (book-club pattern). Registered via
            // plugin_hooks ONLY — never doAction/applyFilters from onActivate.
            $this->registerHookInDb('mobile_api.openapi',  'extendMobileOpenApi',   10);
            // Core entity lifecycle listeners (1.4.0). emeroteca_testate
            // references editori/generi and both emeroteca_fascicoli and
            // emeroteca_annate reference mensole, all with ON DELETE SET
            // NULL: without these the plugin's rows silently lose their link
            // when the core merges or deletes the referenced row.
            // Registered ONLY as plugin_hooks rows — calling doAction/
            // applyFilters here would trigger loadHooks() before the
            // activation guard and duplicate the routes (known trap).
            $this->registerHookInDb('publisher.merging',   'onPublisherMerging',    10);
            $this->registerHookInDb('publisher.deleting',  'onPublisherDeleting',   10);
            $this->registerHookInDb('genre.merging',       'onGenreMerging',        10);
            $this->registerHookInDb('shelf.can_delete',    'onShelfCanDelete',      10);
            $this->registerHookInDb('shelf.deleted',       'onShelfDeleted',        10);
            // Public discoverability (1.4.0). Without these two the
            // emeroteca is a section of the site nobody can reach from
            // outside it: its pages are absent from /sitemap.xml, and a
            // catalogue search for a periodical is a dead end because the
            // core only reads libri.search_index. The core exposes the two
            // filters; registering them is what makes them do anything.
            $this->registerHookInDb('sitemap.entries',             'extendSitemapEntries', 10);
            $this->registerHookInDb('search.external_suggestions', 'suggestEmerotecaSearch', 10);
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
     * column migrations must be appended to additiveColumnDefs(), which
     * is enumerated below (ncip-server pattern).
     *
     * The hardcoded list holds ONLY the columns that are not additive
     * migrations (they ship in the fresh-install DDLs and have no entry
     * in additiveColumnDefs); everything else is derived from that map.
     * Both lists are then de-duplicated by table+column, so a column
     * that later becomes additive cannot be probed twice per boot — the
     * pdf_* quartet used to be listed in both places and cost four
     * redundant information_schema round trips on every boot.
     *
     * @return list<array{table:string, column:string}>
     */
    public function expectedColumns(): array
    {
        $sentinels = [
            ['table' => 'emeroteca_testate',   'column' => 'stato_raccolta'],
            ['table' => 'emeroteca_annate',    'column' => 'rilegata'],
            ['table' => 'emeroteca_fascicoli', 'column' => 'stato'],
            ['table' => 'emeroteca_fascicoli', 'column' => 'collocazione_id'],
            ['table' => 'emeroteca_articoli',  'column' => 'keywords'],
            ['table' => 'emeroteca_contributi', 'column' => 'revision'],
        ];
        // 1.4.0 additive columns: every one declared so the boot-time
        // self-heal re-runs ensureSchema when any is missing.
        foreach (self::additiveColumnDefs() as $table => $definitions) {
            foreach (array_keys($definitions) as $column) {
                $sentinels[] = ['table' => $table, 'column' => $column];
            }
        }

        $out = [];
        $seen = [];
        foreach ($sentinels as $sentinel) {
            $key = $sentinel['table'] . '.' . $sentinel['column'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $sentinel;
        }
        return $out;
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
            ['table' => 'emeroteca_contributi', 'column' => 'testata_id', 'ref_table' => 'emeroteca_testate'],
            ['table' => 'emeroteca_contributi', 'column' => 'fascicolo_id', 'ref_table' => 'emeroteca_fascicoli'],
            ['table' => 'emeroteca_testate',     'column' => 'testata_precedente_id', 'ref_table' => 'emeroteca_testate'],
            ['table' => 'emeroteca_annate',      'column' => 'testata_id',            'ref_table' => 'emeroteca_testate'],
            ['table' => 'emeroteca_fascicoli',   'column' => 'annata_id',             'ref_table' => 'emeroteca_annate'],
            ['table' => 'emeroteca_articoli',    'column' => 'fascicolo_id',          'ref_table' => 'emeroteca_fascicoli'],
            ['table' => 'emeroteca_abbonamenti', 'column' => 'testata_id',            'ref_table' => 'emeroteca_testate'],
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
            'emeroteca_testate'     => self::ddlTestate(),
            'emeroteca_annate'      => self::ddlAnnate(),
            'emeroteca_fascicoli'   => self::ddlFascicoli(),
            'emeroteca_articoli'    => self::ddlArticoli(),
            'emeroteca_abbonamenti' => self::ddlAbbonamenti(),
            'emeroteca_contributi' => self::ddlContributi(),
        ];
    }

    /**
     * Execute the DDL for the six emeroteca tables, then add the FKs
     * towards the optional core tables (editori, generi) when those
     * exist. Failures are logged and reported via the returned 'failed'
     * list without throwing — onActivate()/onInstall() inspect it and
     * abort with a RuntimeException that names the table.
     *
     * NOTE on error style: config/container.php arms
     * `mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)`, so in
     * production a failing statement THROWS mysqli_sql_exception and the
     * `=== false` / `!execute()` branches inside the migration helpers
     * never run. Every step is therefore wrapped here as well, so a
     * failure lands in `failed` (naming the table the operator has to
     * look at) instead of escaping as a raw mysqli exception from a
     * boot-time self-heal.
     *
     * @return array{created: list<string>, failed: list<string>}
     */
    public function ensureSchema(): array
    {
        // A collection is its mastheads, not its tables: auto-registration runs
        // onInstall() even while this optional plugin is inactive, which builds
        // every table empty, so "the table exists" said nothing about whether an
        // operator ever catalogued anything. Probed without the cache the table
        // check uses, because the answer changes as soon as a masthead is added.
        $newCollection = !$this->emerotecaTableExists('emeroteca_testate') || !$this->emerotecaHasMastheads();
        $steps = self::schemaSteps();
        $created = [];
        $failed = [];

        /**
         * Run one migration step for $table, recording the table as
         * failed when the step returns false OR throws.
         *
         * @param callable():bool $step
         */
        $runStep = function (string $table, string $label, callable $step) use (&$failed): void {
            if (in_array($table, $failed, true)) {
                return;
            }
            try {
                if (!$step()) {
                    $failed[] = $table;
                }
            } catch (\Throwable $e) {
                SecureLogger::error(
                    '[Emeroteca] ' . $label . ' migration failed on ' . $table . ': ' . $e->getMessage()
                );
                $failed[] = $table;
            }
        };

        /**
         * Same, for the steps that report a list of affected tables.
         *
         * @param callable():list<string> $step
         * @param list<string>            $ownedTables blamed when the step throws
         */
        $runListStep = function (string $label, array $ownedTables, callable $step) use (&$failed): void {
            try {
                $stepFailed = $step();
            } catch (\Throwable $e) {
                SecureLogger::error('[Emeroteca] ' . $label . ' migration threw: ' . $e->getMessage());
                $stepFailed = $ownedTables;
            }
            foreach ($stepFailed as $table) {
                if (!in_array($table, $failed, true)) {
                    $failed[] = $table;
                }
            }
        };

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

        // CREATE TABLE IF NOT EXISTS does not evolve installations created by
        // an older plugin release. Keep additive migrations explicit and
        // idempotent so activation and PluginManager's boot-time self-heal
        // converge to the same schema.
        foreach (self::additiveColumnDefs() as $table => $definitions) {
            $runStep($table, 'additive column', fn(): bool => $this->ensureAdditiveColumns($table, $definitions));
        }

        $runStep('emeroteca_contributi', 'contribution foreign keys', fn(): bool => $this->ensureContributionForeignKeys());

        // 1.4.0: possession/condition split. MUST run after the additive
        // step above (it writes into the new `condizione` column).
        $runStep('emeroteca_fascicoli', 'stato/condizione split', fn(): bool => $this->ensureStatoCondizioneSplit());

        // 1.4.0: rows whose `stato` is outside the ENUM (the empty
        // index-0 slot). Runs AFTER the split — and unconditionally, not
        // only on the narrowing path — because a fresh install already at
        // the final ENUM can grow such rows too (see the method).
        $runStep('emeroteca_fascicoli', 'out-of-set stato', fn(): bool => $this->ensureStatoInSet());

        // 1.4.0: annate.volume NULL → '' + NOT NULL DEFAULT ''.
        $runStep('emeroteca_annate', 'volume NOT NULL', fn(): bool => $this->ensureAnnateVolumeNotNull());

        // FKs towards core tables (editori, generi, mensole) are added after
        // the CREATE (and after the additive columns, which introduce
        // emeroteca_annate.collocazione_id) so an install where those tables
        // are missing degrades to a schema without the FK instead of failing
        // activation.
        //
        // The failure is attributed to the table the FK actually belongs to:
        // the mensole constraints live on emeroteca_fascicoli and
        // emeroteca_annate, and blaming emeroteca_testate for them sent the
        // operator looking for a problem on a table that is perfectly fine.
        $runListStep(
            'core foreign key',
            ['emeroteca_testate', 'emeroteca_annate', 'emeroteca_fascicoli'],
            fn(): array => $this->ensureCoreForeignKeys()
        );
        $runStep('emeroteca_fascicoli', 'issue-number index', fn(): bool => $this->ensureIssueNumberIndex());

        // 1.4.0 plain (non-unique) lookup indexes.
        $runListStep(
            'lookup index',
            ['emeroteca_testate', 'emeroteca_fascicoli'],
            fn(): array => $this->ensureAdditiveIndexes()
        );

        // 1.4.0 data repair: fascicoli that inherited their testata's
        // barcode_base. Runs last — it needs both the barcode columns
        // (additive step) and consistent tables.
        $runStep('emeroteca_fascicoli', 'inherited barcode', fn(): bool => $this->ensureFascicoloBarcodeNotInherited());

        // A collection that already exists keeps the workflow it has been run
        // with, so an upgrade never changes what the operator sees. A NEW
        // collection is deliberately left unstamped: guessing an initial
        // workflow here decides it silently and, because both this and the
        // migration use INSERT IGNORE, whichever runs first wins over the
        // operator's own first choice. Unstamped is what makes the choice
        // theirs — mode() reads 'complete' meanwhile, so nothing is hidden,
        // and both admin pages ask them to pick.
        if ($failed === [] && !$newCollection) {
            try {
                $this->contributionService()->rows("INSERT IGNORE INTO plugin_settings (plugin_id,setting_key,setting_value) SELECT id,'mode','complete' FROM plugins WHERE name='emeroteca'");
            } catch (\Throwable $e) { $failed[] = 'plugin_settings'; }
        }
        return ['created' => $created, 'failed' => $failed];
    }

    private function ensureContributionForeignKeys(): bool
    {
        foreach (['testata_id'=>['fk_contributo_testata','emeroteca_testate'], 'fascicolo_id'=>['fk_contributo_fascicolo','emeroteca_fascicoli']] as $column=>[$name,$table]) {
            $rows=$this->contributionService()->rows("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emeroteca_contributi' AND COLUMN_NAME=? AND REFERENCED_TABLE_NAME=?",[$column,$table]);
            if ($rows===[]) {
                if (!$this->db->query("ALTER TABLE emeroteca_contributi ADD CONSTRAINT $name FOREIGN KEY ($column) REFERENCES $table(id) ON DELETE SET NULL")) { return false; }
            }
        }
        return true;
    }

    /**
     * Single source of truth for every column introduced after the initial
     * 1.0 schema, per table. Shared by ensureAdditiveColumns() (which adds
     * them) and expectedColumns() (boot-time self-heal sentinels). The
     * fresh-install DDLs below already contain all of them: on a fresh
     * install every probe hits and this is a no-op.
     *
     * @return array<string, array<string,string>> table => (column => DDL)
     */
    private static function additiveColumnDefs(): array
    {
        require_once __DIR__ . '/src/Services/ContributionService.php';
        return [
            'emeroteca_contributi' => \App\Plugins\Emeroteca\Services\ContributionService::COLUMN_DEFINITIONS,
            'emeroteca_testate' => [
                // 1.4.0 — serials identifiers + gestione amministrativa
                'e_issn'                  => 'VARCHAR(9) NULL AFTER issn',
                'issn_l'                  => 'VARCHAR(9) NULL AFTER e_issn',
                // EAN-13 with the 977 serials prefix, derived from the ISSN
                'barcode_base'            => 'VARCHAR(13) NULL AFTER issn_l',
                'direttore_responsabile'  => 'VARCHAR(255) NULL AFTER luogo_pubblicazione',
                'registrazione_tribunale' => 'VARCHAR(255) NULL AFTER direttore_responsabile',
                'prezzo_copertina'        => 'DECIMAL(8,2) NULL AFTER registrazione_tribunale',
                'acquisizione_default'    => "ENUM('abbonamento','acquisto','dono','scambio','deposito') NULL AFTER prezzo_copertina",
                'prestabile'              => "ENUM('escluso','consultazione','prestabile') NOT NULL DEFAULT 'consultazione' AFTER acquisizione_default",
            ],
            'emeroteca_annate' => [
                // 1.4.0
                'serie'                  => 'VARCHAR(50) NULL AFTER volume',
                'collocazione_id'        => 'INT NULL AFTER rilegata',
                'consistenza_dichiarata' => 'VARCHAR(255) NULL AFTER collocazione_id',
                // Alignment with the other emeroteca tables (which are
                // NOT NULL DEFAULT CURRENT_TIMESTAMP ... ON UPDATE): here the
                // column arrives late, so NULL marks the never-updated rows.
                'updated_at'             => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
            ],
            'emeroteca_fascicoli' => [
                // 1.1.x
                'pdf_path'           => 'VARCHAR(500) NULL AFTER note',
                'pdf_nome_originale' => 'VARCHAR(255) NULL AFTER pdf_path',
                'pdf_dimensione'     => 'BIGINT UNSIGNED NULL AFTER pdf_nome_originale',
                'pdf_pubblico'       => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER pdf_dimensione',
                // 1.4.0 — EAN-13 977 + add-on (what the scanner reads)
                'barcode'            => 'VARCHAR(18) NULL AFTER numero_inventario',
                'condizione'         => "ENUM('buono','discreto','danneggiato','in_restauro') NULL AFTER stato",
                'acquisizione'       => "ENUM('abbonamento','acquisto','dono','scambio','deposito') NULL AFTER condizione",
                'prezzo'             => 'DECIMAL(8,2) NULL AFTER acquisizione',
                'reclamato_il'       => 'DATE NULL AFTER prezzo',
                'n_reclami'          => 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER reclamato_il',
            ],
        ];
    }

    /**
     * Add the given columns to the given table when missing. Idempotent:
     * each column is probed via information_schema before the ALTER.
     *
     * @param array<string,string> $definitions column => DDL fragment
     */
    private function ensureAdditiveColumns(string $table, array $definitions): bool
    {
        foreach ($definitions as $column => $ddl) {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?'
            );
            if ($stmt === false) {
                SecureLogger::error('[Emeroteca] column migration probe prepare failed: ' . $this->db->error);
                return false;
            }
            $stmt->bind_param('ss', $table, $column);
            if (!$stmt->execute()) {
                SecureLogger::error('[Emeroteca] column migration probe failed for ' . $table . '.' . $column . ': ' . $stmt->error);
                $stmt->close();
                return false;
            }
            $res = $stmt->get_result();
            $exists = $res instanceof \mysqli_result
                && ((int) ($res->fetch_assoc()['c'] ?? 0)) > 0;
            $stmt->close();
            if ($exists) {
                continue;
            }

            // Table/column names and definitions come only from the static
            // additiveColumnDefs() map above — no user input.
            if ($this->db->query("ALTER TABLE {$table} ADD COLUMN {$column} {$ddl}") === false) {
                SecureLogger::error('[Emeroteca] adding column ' . $table . '.' . $column . ' failed: ' . $this->db->error);
                return false;
            }
        }
        return true;
    }

    /**
     * 1.4.0 — split possession (stato) from physical condition
     * (condizione) on emeroteca_fascicoli.
     *
     * ENUM narrowing follows the mandatory order (never lose rows):
     *   1. widen stato to the SUPERSET of legacy + final members, so the
     *      normalization UPDATEs can never hit an invalid target value;
     *   2. normalize: stato='danneggiato'/'in_restauro' become
     *      stato='posseduto' with the condition moved into `condizione`
     *      (COALESCE keeps a condition someone already recorded);
     *   3. restrict stato to the final member list.
     *
     * Idempotent: when COLUMN_TYPE already equals the final ENUM the
     * method returns immediately (fresh installs land here too, since
     * ddlFascicoli() already declares the final ENUM).
     */
    private function ensureStatoCondizioneSplit(): bool
    {
        $finalType = "enum('posseduto','mancante','atteso','smarrito','reclamato','scartato')";
        $current = $this->columnType('emeroteca_fascicoli', 'stato');
        if ($current === null) {
            SecureLogger::error('[Emeroteca] stato/condizione split: emeroteca_fascicoli.stato not found');
            return false;
        }
        if (strtolower($current) === $finalType) {
            return true;
        }

        // 1. widen (append-only relative to the legacy list, so existing
        //    values are untouched).
        if ($this->db->query(
            "ALTER TABLE emeroteca_fascicoli
             MODIFY stato ENUM('posseduto','mancante','danneggiato','in_restauro','smarrito','atteso','reclamato','scartato')
                 NOT NULL DEFAULT 'posseduto'"
        ) === false) {
            SecureLogger::error('[Emeroteca] stato ENUM widening failed: ' . $this->db->error);
            return false;
        }

        // 2. normalize possession/condition.
        foreach (['danneggiato', 'in_restauro'] as $legacy) {
            $stmt = $this->db->prepare(
                "UPDATE emeroteca_fascicoli
                    SET condizione = COALESCE(condizione, ?), stato = 'posseduto'
                  WHERE stato = ?"
            );
            if ($stmt === false) {
                SecureLogger::error('[Emeroteca] stato normalization prepare failed: ' . $this->db->error);
                return false;
            }
            $stmt->bind_param('ss', $legacy, $legacy);
            if (!$stmt->execute()) {
                SecureLogger::error('[Emeroteca] stato normalization failed for ' . $legacy . ': ' . $stmt->error);
                $stmt->close();
                return false;
            }
            $stmt->close();
        }

        // 3. restrict to the final member list.
        if ($this->db->query(
            "ALTER TABLE emeroteca_fascicoli
             MODIFY stato ENUM('posseduto','mancante','atteso','smarrito','reclamato','scartato')
                 NOT NULL DEFAULT 'posseduto'"
        ) === false) {
            SecureLogger::error('[Emeroteca] stato ENUM restriction failed: ' . $this->db->error);
            return false;
        }
        return true;
    }

    /**
     * 1.4.0 — repair fascicoli whose `stato` sits OUTSIDE the ENUM.
     *
     * MySQL gives an ENUM an extra, unnamed index-0 member that reads
     * back as the empty string. It is reached whenever an INSERT/UPDATE
     * writes a value the ENUM does not know while strict mode is off —
     * exactly the configuration of the cPanel/CloudLinux hosts this
     * project runs on in production, where `sql_mode=''` is common.
     *
     * Those rows survive everything ensureStatoCondizioneSplit() does:
     * its normalization UPDATEs only match 'danneggiato'/'in_restauro',
     * and an ENUM→ENUM MODIFY copies index 0 verbatim into the narrowed
     * type. The damage is silent and real — the public badge renders
     * grey with no label, and consistenzaTestata() counts the issue
     * neither as owned (stato='posseduto') nor as a gap
     * (stato='mancante'), so the fascicolo simply vanishes from the
     * holdings statement. The migration is the only place positioned to
     * see and fix them.
     *
     * Normalization rule (documented on purpose, since it guesses):
     *   - 'posseduto' when the row carries any evidence of possession —
     *     an inventory number, a barcode, a shelf location, a stored
     *     PDF, a recorded physical condition, an acquisition channel or
     *     a price. Someone described a copy the library holds.
     *   - 'mancante' otherwise: an empty placeholder row is a hole in
     *     the collection, and claiming it as owned would overstate the
     *     holdings. 'mancante' is also the conservative choice — it is
     *     visible as a gap, so a librarian notices and corrects it.
     *
     * Runs on every ensureSchema() (activation + PluginManager's
     * boot-time self-heal) behind a COUNT probe, so the normal case
     * costs one cheap query and writes nothing.
     */
    private function ensureStatoInSet(): bool
    {
        // `stato = ''` is how the out-of-ENUM index-0 slot compares; it
        // can never match a legitimate member, on either the legacy or
        // the final member list.
        $probe = $this->db->query("SELECT COUNT(*) AS c FROM emeroteca_fascicoli WHERE stato = ''");
        if ($probe === false) {
            SecureLogger::error('[Emeroteca] out-of-set stato probe failed: ' . $this->db->error);
            return false;
        }
        $count = $probe instanceof \mysqli_result ? (int) ($probe->fetch_assoc()['c'] ?? 0) : 0;
        if ($probe instanceof \mysqli_result) {
            $probe->free();
        }
        if ($count === 0) {
            return true;
        }

        if ($this->db->query(
            "UPDATE emeroteca_fascicoli
                SET stato = CASE
                        WHEN (numero_inventario IS NOT NULL AND numero_inventario <> '')
                          OR (barcode IS NOT NULL AND barcode <> '')
                          OR collocazione_id IS NOT NULL
                          OR (pdf_path IS NOT NULL AND pdf_path <> '')
                          OR condizione IS NOT NULL
                          OR acquisizione IS NOT NULL
                          OR prezzo IS NOT NULL
                        THEN 'posseduto'
                        ELSE 'mancante'
                    END
              WHERE stato = ''"
        ) === false) {
            SecureLogger::error('[Emeroteca] out-of-set stato normalization failed: ' . $this->db->error);
            return false;
        }

        SecureLogger::warning(
            '[Emeroteca] stato migration: normalized ' . $count
            . ' fascicolo(i) whose stato was outside the ENUM (empty index-0 slot, written with sql_mode=\'\'); '
            . 'rows with possession data became \'posseduto\', the others \'mancante\''
        );
        return true;
    }

    /**
     * 1.4.0 — clear the barcodes fascicoli INHERITED from their testata.
     *
     * An earlier build of the issue form pre-filled `barcode` with the
     * testata's `barcode_base` and saved it as if it were the issue's
     * own code. `barcode_base` is the EAN-13 of the SERIAL (the 977
     * prefix), shared by every issue of the title: an issue is
     * identified by that base PLUS its add-on. So each polluted row
     * carries a code that is not unique to it, and a scan at the desk
     * resolves to whichever of them the query returns first — the wrong
     * issue, silently. The form no longer copies the base, but the rows
     * already written keep misresolving until they are cleaned.
     *
     * Exactly the rows whose barcode EQUALS the barcode_base of their
     * OWN testata are reset to NULL. A legitimate issue barcode is the
     * base plus its add-on, so it differs from the base and is left
     * alone; a code that happens to equal ANOTHER title's base is not
     * evidence of the copy bug and is not touched either — the JOIN
     * walks fascicolo → annata → testata, so the comparison is always
     * against the issue's own title.
     *
     * Guarded like the other steps: a COUNT probe first, so on a clean
     * install (and on every boot afterwards) this costs one query and
     * writes nothing — the repair effectively runs once.
     */
    private function ensureFascicoloBarcodeNotInherited(): bool
    {
        $matchClause =
            "FROM emeroteca_fascicoli f
               JOIN emeroteca_annate a ON a.id = f.annata_id
               JOIN emeroteca_testate t ON t.id = a.testata_id
              WHERE f.barcode IS NOT NULL
                AND t.barcode_base IS NOT NULL
                AND f.barcode = t.barcode_base";

        $probe = $this->db->query("SELECT COUNT(*) AS c {$matchClause}");
        if ($probe === false) {
            SecureLogger::error('[Emeroteca] inherited-barcode probe failed: ' . $this->db->error);
            return false;
        }
        $count = $probe instanceof \mysqli_result ? (int) ($probe->fetch_assoc()['c'] ?? 0) : 0;
        if ($probe instanceof \mysqli_result) {
            $probe->free();
        }
        if ($count === 0) {
            return true;
        }

        // Multi-table UPDATE with the same JOIN as the probe, so the set
        // of affected rows is exactly the set that was counted.
        if ($this->db->query(
            "UPDATE emeroteca_fascicoli f
               JOIN emeroteca_annate a ON a.id = f.annata_id
               JOIN emeroteca_testate t ON t.id = a.testata_id
                SET f.barcode = NULL
              WHERE f.barcode IS NOT NULL
                AND t.barcode_base IS NOT NULL
                AND f.barcode = t.barcode_base"
        ) === false) {
            SecureLogger::error('[Emeroteca] inherited-barcode cleanup failed: ' . $this->db->error);
            return false;
        }

        SecureLogger::warning(
            '[Emeroteca] barcode migration: cleared ' . $count
            . ' fascicolo barcode(s) that were copies of their testata barcode_base '
            . '(a serial base is not an issue barcode — scanning one resolved to the wrong fascicolo)'
        );
        return true;
    }

    /**
     * 1.4.0 — emeroteca_annate.volume becomes NOT NULL DEFAULT ''.
     *
     * NULL volumes compare distinct inside UNIQUE(testata_id, anno,
     * volume), so before the MODIFY the NULLs must collapse to '' WITHOUT
     * tripping the unique key:
     *   - a NULL row becomes '' only when its (testata_id, anno) group has
     *     no '' row yet and it is the lowest-id NULL row of the group;
     *   - any leftover NULL row (a duplicate the unique key only admitted
     *     via NULL) gets a synthetic label — keeping the annata and its
     *     fascicoli is worth more than a pretty volume name.
     *
     * The synthetic label is NOT written blind: 'v14' is a perfectly
     * plausible volume a librarian may already have typed by hand, so a
     * bare `volume = CONCAT('v', id)` can hit uq_emeroteca_annata
     * (testata_id, anno, volume) with ER_DUP_ENTRY. That failure is
     * terminal: ensureSchema() reports emeroteca_annate failed,
     * onActivate() throws, PluginManager rolls the version back and the
     * next boot repeats the same error forever. So each candidate is
     * probed inside its own (testata_id, anno) group before the UPDATE,
     * with a bounded ladder of fallbacks, and every rename is logged.
     * Idempotent: skipped entirely once IS_NULLABLE = 'NO'.
     */
    private function ensureAnnateVolumeNotNull(): bool
    {
        $stmt = $this->db->prepare(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'emeroteca_annate'
                AND COLUMN_NAME = 'volume'"
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] volume probe prepare failed: ' . $this->db->error);
            return false;
        }
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] volume probe failed: ' . $stmt->error);
            $stmt->close();
            return false;
        }
        $res = $stmt->get_result();
        $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!is_array($row)) {
            SecureLogger::error('[Emeroteca] emeroteca_annate.volume not found');
            return false;
        }
        if (strtoupper((string) ($row['IS_NULLABLE'] ?? '')) === 'NO') {
            return true;
        }

        if ($this->db->query(
            "UPDATE emeroteca_annate a
               LEFT JOIN emeroteca_annate e
                 ON e.testata_id = a.testata_id AND e.anno = a.anno AND e.volume = ''
               LEFT JOIN emeroteca_annate n
                 ON n.testata_id = a.testata_id AND n.anno = a.anno
                AND n.volume IS NULL AND n.id < a.id
                SET a.volume = ''
              WHERE a.volume IS NULL AND e.id IS NULL AND n.id IS NULL"
        ) === false) {
            SecureLogger::error('[Emeroteca] volume NULL normalization failed: ' . $this->db->error);
            return false;
        }
        if (!$this->labelLeftoverNullVolumes()) {
            return false;
        }
        if ($this->db->query(
            "ALTER TABLE emeroteca_annate MODIFY volume VARCHAR(50) NOT NULL DEFAULT ''"
        ) === false) {
            SecureLogger::error('[Emeroteca] volume NOT NULL migration failed: ' . $this->db->error);
            return false;
        }
        return true;
    }

    /**
     * Give every annata whose `volume` is still NULL a synthetic, unique
     * label inside its own (testata_id, anno) group.
     *
     * Called by ensureAnnateVolumeNotNull() once the "lone NULL → ''"
     * pass has run: what remains are duplicates that only existed
     * because NULLs compare distinct in a UNIQUE key.
     *
     * Collision handling — the point of this method. 'v14' is a label a
     * librarian can (and does) type by hand, so the previous blanket
     * `SET volume = CONCAT('v', id)` could hit uq_emeroteca_annata and
     * abort the whole activation permanently. Each row therefore probes
     * a bounded ladder of candidates inside its group and takes the
     * first free one:
     *
     *   v<id>  →  v<id>-2 … v<id>-20  →  v<id>-<random hex> (×5)
     *
     * A candidate is checked with a prepared SELECT and the UPDATE is
     * still allowed to fail (a concurrent writer): on failure the next
     * candidate is tried, so no single collision is fatal.
     *
     * @return bool false only when a row could not be labelled at all,
     *         which the caller reports as a failed emeroteca_annate.
     */
    private function labelLeftoverNullVolumes(): bool
    {
        $leftovers = $this->db->query(
            'SELECT id, testata_id, anno FROM emeroteca_annate WHERE volume IS NULL ORDER BY id'
        );
        if ($leftovers === false) {
            SecureLogger::error('[Emeroteca] leftover NULL volume probe failed: ' . $this->db->error);
            return false;
        }
        /** @var list<array{id:int, testata_id:int, anno:int}> $rows */
        $rows = [];
        if ($leftovers instanceof \mysqli_result) {
            while ($row = $leftovers->fetch_assoc()) {
                $rows[] = [
                    'id'         => (int) $row['id'],
                    'testata_id' => (int) $row['testata_id'],
                    'anno'       => (int) $row['anno'],
                ];
            }
            $leftovers->free();
        }
        if ($rows === []) {
            return true;
        }

        $probe = $this->db->prepare(
            'SELECT 1 FROM emeroteca_annate
              WHERE testata_id = ? AND anno = ? AND volume = ? LIMIT 1'
        );
        $update = $this->db->prepare(
            'UPDATE emeroteca_annate SET volume = ? WHERE id = ? AND volume IS NULL'
        );
        if ($probe === false || $update === false) {
            if ($probe !== false) {
                $probe->close();
            }
            if ($update !== false) {
                $update->close();
            }
            SecureLogger::error('[Emeroteca] leftover NULL volume prepare failed: ' . $this->db->error);
            return false;
        }

        $renamed = [];
        $ok = true;
        try {
            foreach ($rows as $row) {
                $label = null;
                foreach ($this->syntheticVolumeCandidates($row['id']) as $candidate) {
                    // VARCHAR(50): a truncated candidate would silently
                    // collide with another truncated one.
                    if (mb_strlen($candidate) > 50) {
                        continue;
                    }
                    $probe->bind_param('iis', $row['testata_id'], $row['anno'], $candidate);
                    if (!$probe->execute()) {
                        SecureLogger::error('[Emeroteca] volume candidate probe failed: ' . $probe->error);
                        $ok = false;
                        break;
                    }
                    $probeResult = $probe->get_result();
                    $taken = $probeResult instanceof \mysqli_result && $probeResult->fetch_row() !== null;
                    if ($probeResult instanceof \mysqli_result) {
                        $probeResult->free();
                    }
                    if ($taken) {
                        continue;
                    }
                    // The UPDATE is allowed to lose the race. Under
                    // MYSQLI_REPORT_ERROR (armed by config/container.php)
                    // a duplicate key THROWS instead of returning false,
                    // so both shapes have to fall through to the next
                    // candidate — otherwise the exception would escape
                    // and the migration would fail on the very collision
                    // this ladder exists to absorb.
                    try {
                        $update->bind_param('si', $candidate, $row['id']);
                        $written = $update->execute();
                    } catch (\Throwable $updateError) {
                        SecureLogger::warning(
                            '[Emeroteca] volume label "' . $candidate . '" rejected for annata #'
                            . $row['id'] . ': ' . $updateError->getMessage()
                        );
                        continue;
                    }
                    if (!$written) {
                        SecureLogger::warning(
                            '[Emeroteca] volume label "' . $candidate . '" rejected for annata #'
                            . $row['id'] . ': ' . $update->error
                        );
                        continue;
                    }
                    $label = $candidate;
                    break;
                }
                if ($label === null) {
                    if ($ok) {
                        SecureLogger::error(
                            '[Emeroteca] could not find a free synthetic volume label for annata #' . $row['id']
                            . ' (testata ' . $row['testata_id'] . ', anno ' . $row['anno'] . ')'
                        );
                    }
                    $ok = false;
                    continue;
                }
                $renamed[] = '#' . $row['id'] . ' → "' . $label . '"';
            }
        } finally {
            $probe->close();
            $update->close();
        }

        if ($renamed !== []) {
            SecureLogger::warning(
                '[Emeroteca] volume migration: ' . count($renamed)
                . ' annata(e) with a duplicate NULL volume received a synthetic label: '
                . implode(', ', array_slice($renamed, 0, 50))
                . (count($renamed) > 50 ? ' …' : '')
            );
        }
        return $ok;
    }

    /**
     * Candidate synthetic volume labels for one annata, most readable
     * first. Deterministic head ('v<id>', then 'v<id>-2'…'v<id>-20') so
     * a re-run of the migration converges on the same label; random tail
     * as the last resort when a library really uses that whole range.
     *
     * @return list<string>
     */
    private function syntheticVolumeCandidates(int $annataId): array
    {
        $candidates = ['v' . $annataId];
        for ($i = 2; $i <= 20; $i++) {
            $candidates[] = 'v' . $annataId . '-' . $i;
        }
        for ($i = 0; $i < 5; $i++) {
            try {
                $suffix = bin2hex(random_bytes(4));
            } catch (\Throwable $e) {
                $suffix = substr(md5($annataId . '-' . $i . '-' . microtime(true)), 0, 8);
            }
            $candidates[] = 'v' . $annataId . '-' . $suffix;
        }
        return $candidates;
    }

    /**
     * 1.4.0 — plain lookup KEYs added by the upgrade path. Index names
     * match the fresh-install DDLs so both paths converge. Probes by
     * INDEX_NAME via information_schema.STATISTICS.
     *
     * @return list<string> tables whose index migration failed (empty on success)
     */
    private function ensureAdditiveIndexes(): array
    {
        $indexes = [
            ['table' => 'emeroteca_testate',   'name' => 'idx_emeroteca_testata_issn',        'columns' => 'issn'],
            ['table' => 'emeroteca_testate',   'name' => 'idx_emeroteca_testata_barcode',     'columns' => 'barcode_base'],
            ['table' => 'emeroteca_fascicoli', 'name' => 'idx_emeroteca_fascicolo_barcode',   'columns' => 'barcode'],
            ['table' => 'emeroteca_fascicoli', 'name' => 'idx_emeroteca_fascicolo_inventario', 'columns' => 'numero_inventario'],
        ];
        $failed = [];
        foreach ($indexes as $idx) {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) AS c FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND INDEX_NAME = ?'
            );
            if ($stmt === false) {
                SecureLogger::error('[Emeroteca] index probe prepare failed: ' . $this->db->error);
                $failed[] = $idx['table'];
                continue;
            }
            $stmt->bind_param('ss', $idx['table'], $idx['name']);
            if (!$stmt->execute()) {
                SecureLogger::error('[Emeroteca] index probe failed for ' . $idx['name'] . ': ' . $stmt->error);
                $stmt->close();
                $failed[] = $idx['table'];
                continue;
            }
            $res = $stmt->get_result();
            $exists = $res instanceof \mysqli_result
                && ((int) ($res->fetch_assoc()['c'] ?? 0)) > 0;
            $stmt->close();
            if ($exists) {
                continue;
            }
            // Names come only from the static list above — no user input.
            if ($this->db->query(
                "ALTER TABLE {$idx['table']} ADD KEY {$idx['name']} ({$idx['columns']})"
            ) === false) {
                SecureLogger::error('[Emeroteca] adding index ' . $idx['name'] . ' failed: ' . $this->db->error);
                $failed[] = $idx['table'];
            }
        }
        return array_values(array_unique($failed));
    }

    /** COLUMN_TYPE of a column, or null when the column does not exist. */
    private function columnType(string $table, string $column): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] column-type probe prepare failed: ' . $this->db->error);
            return null;
        }
        $stmt->bind_param('ss', $table, $column);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] column-type probe failed for ' . $table . '.' . $column . ': ' . $stmt->error);
            $stmt->close();
            return null;
        }
        $res = $stmt->get_result();
        $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) && isset($row['COLUMN_TYPE']) ? (string) $row['COLUMN_TYPE'] : null;
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
            ['table' => 'emeroteca_fascicoli', 'column' => 'collocazione_id', 'ref_table' => 'mensole', 'ref_col' => 'id', 'name' => 'fk_emeroteca_fascicolo_mensola'],
            // 1.4.0 — shelf location at annata level (bound volumes).
            ['table' => 'emeroteca_annate', 'column' => 'collocazione_id', 'ref_table' => 'mensole', 'ref_col' => 'id', 'name' => 'fk_emeroteca_annata_mensola'],
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
     * Add the FKs towards the optional core tables (editori/generi on
     * emeroteca_testate, mensole on emeroteca_fascicoli and
     * emeroteca_annate) when the core table exists and the FK is
     * missing. Where the core table is absent (partial/headless
     * installs) the constraint is skipped with a warning — the column
     * stays a plain nullable INT. Detects each FK by column + referenced
     * table via KEY_COLUMN_USAGE, nulls out orphan rows first, then
     * ALTERs it in with ON DELETE SET NULL. Idempotent, safe to re-run
     * from onActivate/onInstall.
     * All table/column names below are static literals — no user input.
     *
     * @return list<string> The emeroteca tables whose FK migration
     *         failed (empty on success). Returning the OWNING table —
     *         not a fixed one — is what lets ensureSchema() name the
     *         table the operator actually has to look at: three of the
     *         four constraints do not live on emeroteca_testate.
     */
    private function ensureCoreForeignKeys(): array
    {
        $failed = [];
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
                $failed[] = $fk['table'];
                continue;
            }

            $stmt = $this->db->prepare(
                "SELECT COUNT(*) AS c FROM information_schema.KEY_COLUMN_USAGE
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?
                    AND REFERENCED_TABLE_NAME = ?"
            );
            if ($stmt === false) {
                SecureLogger::error('[Emeroteca] FK probe prepare failed: ' . $this->db->error);
                $failed[] = $fk['table'];
                continue;
            }
            $stmt->bind_param('sss', $fk['table'], $fk['column'], $fk['ref_table']);
            if (!$stmt->execute()) {
                SecureLogger::error('[Emeroteca] FK probe failed for ' . $fk['column'] . ': ' . $stmt->error);
                $stmt->close();
                $failed[] = $fk['table'];
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
                "UPDATE {$fk['table']} t
                 LEFT JOIN {$fk['ref_table']} r ON t.{$fk['column']} = r.{$fk['ref_col']}
                 SET t.{$fk['column']} = NULL
                 WHERE t.{$fk['column']} IS NOT NULL AND r.{$fk['ref_col']} IS NULL"
            ) === false) {
                SecureLogger::error('[Emeroteca] Orphan cleanup for ' . $fk['column'] . ' failed: ' . $this->db->error);
                $failed[] = $fk['table'];
                continue;
            }

            $alter = "ALTER TABLE {$fk['table']}
                      ADD CONSTRAINT {$fk['name']}
                      FOREIGN KEY ({$fk['column']}) REFERENCES {$fk['ref_table']} ({$fk['ref_col']}) ON DELETE SET NULL";
            if ($this->db->query($alter) === false) {
                SecureLogger::error('[Emeroteca] Adding FK ' . $fk['name'] . ' failed: ' . $this->db->error);
                $failed[] = $fk['table'];
            }
        }
        return array_values(array_unique($failed));
    }

    /**
     * An issue number is unique inside one annata. Version 1.0 already
     * had both columns but not this index, so the 1.1 activation adds it
     * explicitly instead of relying on CREATE TABLE IF NOT EXISTS.
     */
    private function ensureIssueNumberIndex(): bool
    {
        $stmt = $this->db->prepare(
            "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'emeroteca_fascicoli'
                AND INDEX_NAME = 'uq_emeroteca_fascicolo_numero'
                AND NON_UNIQUE = 0"
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] issue unique-index probe prepare failed: ' . $this->db->error);
            return false;
        }
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] issue unique-index probe failed: ' . $stmt->error);
            $stmt->close();
            return false;
        }
        $res = $stmt->get_result();
        $columns = $res instanceof \mysqli_result
            ? (string) ($res->fetch_assoc()['columns_list'] ?? '')
            : '';
        $stmt->close();
        if ($columns === 'annata_id,numero') {
            return true;
        }
        if ($columns !== '') {
            SecureLogger::error('[Emeroteca] issue unique index exists with unexpected columns: ' . $columns);
            return false;
        }
        if ($this->db->query(
            'ALTER TABLE emeroteca_fascicoli
             ADD UNIQUE KEY uq_emeroteca_fascicolo_numero (annata_id, numero)'
        ) === false) {
            SecureLogger::error(
                '[Emeroteca] issue unique-index migration failed; resolve duplicate numbers per annata: '
                . $this->db->error
            );
            return false;
        }
        return true;
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
            e_issn                VARCHAR(9)   NULL,
            issn_l                VARCHAR(9)   NULL,
            barcode_base          VARCHAR(13)  NULL,
            editore_id            INT          NULL,
            luogo_pubblicazione   VARCHAR(255) NULL,
            direttore_responsabile  VARCHAR(255) NULL,
            registrazione_tribunale VARCHAR(255) NULL,
            prezzo_copertina      DECIMAL(8,2) NULL,
            acquisizione_default  ENUM('abbonamento','acquisto','dono','scambio','deposito') NULL,
            prestabile            ENUM('escluso','consultazione','prestabile') NOT NULL DEFAULT 'consultazione',
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
            KEY idx_emeroteca_testata_issn (issn),
            KEY idx_emeroteca_testata_barcode (barcode_base),
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
     * UNIQUE(testata_id, anno, volume): since 1.4.0 volume is NOT NULL
     * DEFAULT '' so the unique key is strict (NULL volumes used to
     * compare distinct in MySQL; ensureAnnateVolumeNotNull() migrates
     * legacy NULLs). The optional collocazione FK (mensole) is attached
     * after creation by ensureCoreForeignKeys() when the core table
     * exists. updated_at is intentionally NULLable: the column arrived
     * in 1.4.0 and NULL marks the never-updated legacy rows.
     */
    public static function ddlAnnate(): string
    {
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS emeroteca_annate (
            id                     INT          NOT NULL AUTO_INCREMENT,
            testata_id             INT          NOT NULL,
            anno                   SMALLINT     NOT NULL,
            volume                 VARCHAR(50)  NOT NULL DEFAULT '',
            serie                  VARCHAR(50)  NULL,
            rilegata               TINYINT(1)   NOT NULL DEFAULT 0,
            collocazione_id        INT          NULL,
            consistenza_dichiarata VARCHAR(255) NULL,
            copertina_url          VARCHAR(500) NULL,
            note                   TEXT         NULL,
            created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at             TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_emeroteca_annata (testata_id, anno, volume),
            CONSTRAINT fk_emeroteca_annata_testata
                FOREIGN KEY (testata_id) REFERENCES emeroteca_testate(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;
    }

    /**
     * DDL for `emeroteca_fascicoli` — single issues. The optional
     * collocazione FK is attached after creation when the core mensole
     * table exists.
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
            barcode            VARCHAR(18)  NULL,
            collocazione_id    INT          NULL,
            stato              ENUM('posseduto','mancante','atteso','smarrito','reclamato','scartato') NOT NULL DEFAULT 'posseduto',
            condizione         ENUM('buono','discreto','danneggiato','in_restauro') NULL,
            acquisizione       ENUM('abbonamento','acquisto','dono','scambio','deposito') NULL,
            prezzo             DECIMAL(8,2) NULL,
            reclamato_il       DATE         NULL,
            n_reclami          TINYINT UNSIGNED NOT NULL DEFAULT 0,
            supplementi        VARCHAR(500) NULL,
            note               TEXT         NULL,
            pdf_path           VARCHAR(500) NULL,
            pdf_nome_originale VARCHAR(255) NULL,
            pdf_dimensione     BIGINT UNSIGNED NULL,
            pdf_pubblico       TINYINT(1)   NOT NULL DEFAULT 0,
            created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_emeroteca_fascicolo_numero (annata_id, numero),
            KEY idx_emeroteca_fascicolo_annata (annata_id),
            KEY idx_emeroteca_fascicolo_stato (stato),
            KEY idx_emeroteca_fascicolo_barcode (barcode),
            KEY idx_emeroteca_fascicolo_inventario (numero_inventario),
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

    /**
     * DDL for `emeroteca_abbonamenti` (1.4.0) — subscriptions of a
     * testata: supplier, cost, validity window, auto-renewal. Cascade
     * with the testata: a subscription without its periodical is noise.
     */
    public static function ddlAbbonamenti(): string
    {
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS emeroteca_abbonamenti (
            id                 INT           NOT NULL AUTO_INCREMENT,
            testata_id         INT           NOT NULL,
            fornitore          VARCHAR(255)  NOT NULL,
            costo              DECIMAL(10,2) NULL,
            valuta             VARCHAR(3)    NOT NULL DEFAULT 'EUR',
            data_inizio        DATE          NULL,
            data_scadenza      DATE          NULL,
            rinnovo_automatico TINYINT(1)    NOT NULL DEFAULT 0,
            attivo             TINYINT(1)    NOT NULL DEFAULT 1,
            note               TEXT          NULL,
            created_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_emeroteca_abbonamento_testata (testata_id),
            KEY idx_emeroteca_abbonamento_scadenza (data_scadenza),
            CONSTRAINT fk_emeroteca_abbonamento_testata
                FOREIGN KEY (testata_id) REFERENCES emeroteca_testate(id) ON DELETE CASCADE
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

        // Throttles for the three routes a client can hammer (1.4.0).
        // Layering matches the core admin routes in app/Routes/web.php and
        // book-club's ApiModule: ->add($rate)->add($adminMiddleware), so
        // authentication stays the OUTER middleware and the budget is only
        // spent by requests that already passed it.
        //
        // scan-lookup is a legitimately high-frequency operation — one
        // request per barcode while a librarian receives a pile of issues
        // at the desk — so the ceiling is generous enough never to get in
        // the way of real work while still stopping a runaway scanner or
        // an enumeration sweep over the code space.
        $scanRateMiddleware = new \App\Middleware\RateLimitMiddleware(120, 300, 'emeroteca_scan_lookup');
        // The two exports are the opposite: rare, and heavy — without
        // ?testata they walk the entire emeroteca and buffer the document
        // in memory, so a handful of concurrent requests is a memory
        // problem. One shared action key, so the budget covers the pair.
        $exportRateMiddleware = new \App\Middleware\RateLimitMiddleware(10, 300, 'emeroteca_export');

        $admin  = 'App\\Plugins\\Emeroteca\\Controllers\\PeriodicalAdminController';
        $issues = 'App\\Plugins\\Emeroteca\\Controllers\\IssueAdminController';
        $subs   = 'App\\Plugins\\Emeroteca\\Controllers\\SubscriptionAdminController';
        $export = 'App\\Plugins\\Emeroteca\\Controllers\\ExportAdminController';
        $public = 'App\\Plugins\\Emeroteca\\Controllers\\PublicController';

        $articles = 'App\\Plugins\\Emeroteca\\Controllers\\ContributionController';
        foreach (['' => 'index', '/create' => 'form', '/{id:[0-9]+}' => 'form', '/import' => 'importForm', '/export' => 'export', '/issues' => 'issueOptions', '/{id:[0-9]+}/pdf' => 'pdf'] as $path => $method) {
            $app->get('/admin/periodicals/articles' . $path, function ($rq, $rs, $args) use ($plugin, $articles, $method) {
                return $plugin->dispatch($articles, $method, $rq, $rs, $args);
            })->add($adminMiddleware);
        }
        foreach (['/save' => 'save', '/associate' => 'associate', '/import' => 'importSubmit', '/{id:[0-9]+}/delete' => 'delete', '/mode' => 'mode'] as $path => $method) {
            $app->post('/admin/periodicals/articles' . $path, function ($rq, $rs, $args) use ($plugin, $articles, $method) {
                return $plugin->dispatch($articles, $method, $rq, $rs, $args);
            })->add($csrfMiddleware)->add($adminMiddleware);
        }
        $app->get('/emeroteca/articoli', fn($rq,$rs,$args) => $plugin->dispatch($public, 'articles', $rq,$rs,$args));
        $app->get('/emeroteca/articolo/{id:[0-9]+}', fn($rq,$rs,$args) => $plugin->dispatch($public, 'article', $rq,$rs,$args));
        $app->get('/emeroteca/articolo/{id:[0-9]+}/pdf', fn($rq,$rs,$args) => $plugin->dispatch($articles, 'publicPdf', $rq,$rs,$args));

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

        // ── Admin — union-catalogue exports (1.4.0) ──────────────────
        // Literal paths registered BEFORE the {id} routes; 'export' and
        // 'merge' can never match the [0-9]+ constraint anyway, so the two
        // groups stay disjoint whatever the dispatcher's ordering.

        // GET /admin/periodicals/export/kbart[?testata=ID] — KBART TSV
        $app->get('/admin/periodicals/export/kbart', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin, $export): ResponseInterface {
            return $plugin->dispatch($export, 'kbart', $request, $response);
        })->add($exportRateMiddleware)->add($adminMiddleware);

        // GET /admin/periodicals/export/acnp[?testata=ID] — ACNP CSV
        $app->get('/admin/periodicals/export/acnp', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin, $export): ResponseInterface {
            return $plugin->dispatch($export, 'acnp', $request, $response);
        })->add($exportRateMiddleware)->add($adminMiddleware);

        // GET /admin/periodicals/scan-lookup?code=… — JSON barcode lookup
        // for the Kardex scanner. Read-only by design: a GET never receives
        // an issue, it only points at the existing receive action.
        $app->get('/admin/periodicals/scan-lookup', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin, $export): ResponseInterface {
            return $plugin->dispatch($export, 'scanLookup', $request, $response);
        })->add($scanRateMiddleware)->add($adminMiddleware);

        // ── Admin — merge of duplicate testate (1.4.0) ───────────────

        // GET /admin/periodicals/merge?ids[]=A&ids[]=B — preview
        $app->get('/admin/periodicals/merge', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin, $admin): ResponseInterface {
            return $plugin->dispatch($admin, 'mergeForm', $request, $response);
        })->add($adminMiddleware);

        // POST /admin/periodicals/merge — perform the merge
        $app->post('/admin/periodicals/merge', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin, $admin): ResponseInterface {
            return $plugin->dispatch($admin, 'mergeSubmit', $request, $response);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // ── Admin — abbonamenti of one testata ───────────────────────
        // Nested under the testata: a subscription only exists relative to a
        // title, and both ids travel in the path so the controller can verify
        // ownership on every write.

        // GET /admin/periodicals/{id}/subscriptions — list + create form
        $app->get('/admin/periodicals/{id:[0-9]+}/subscriptions', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin, $subs): ResponseInterface {
            return $plugin->dispatch($subs, 'index', $request, $response, $args);
        })->add($adminMiddleware);

        // POST /admin/periodicals/{id}/subscriptions — create
        $app->post('/admin/periodicals/{id:[0-9]+}/subscriptions', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin, $subs): ResponseInterface {
            return $plugin->dispatch($subs, 'createSubmit', $request, $response, $args);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // GET /admin/periodicals/{id}/subscriptions/{sid}/edit — edit form
        $app->get('/admin/periodicals/{id:[0-9]+}/subscriptions/{sid:[0-9]+}/edit', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin, $subs): ResponseInterface {
            return $plugin->dispatch($subs, 'editForm', $request, $response, $args);
        })->add($adminMiddleware);

        // POST /admin/periodicals/{id}/subscriptions/{sid}/edit — update
        $app->post('/admin/periodicals/{id:[0-9]+}/subscriptions/{sid:[0-9]+}/edit', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin, $subs): ResponseInterface {
            return $plugin->dispatch($subs, 'editSubmit', $request, $response, $args);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // POST /admin/periodicals/{id}/subscriptions/{sid}/delete — delete
        $app->post('/admin/periodicals/{id:[0-9]+}/subscriptions/{sid:[0-9]+}/delete', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin, $subs): ResponseInterface {
            return $plugin->dispatch($subs, 'delete', $request, $response, $args);
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

        // POST /admin/periodicals/{id}/issues/labels — spine labels PDF for
        // the issues ticked on the manage page (1.4.0).
        $app->post('/admin/periodicals/{id:[0-9]+}/issues/labels', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin, $export): ResponseInterface {
            return $plugin->dispatch($export, 'labels', $request, $response, $args);
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

        // PDF scans are stored outside the web root and streamed only after
        // the same admin authorization used by the issue editor.
        $app->get('/admin/periodicals/issue/{id:[0-9]+}/pdf', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->serveIssuePdf($response, $args, false);
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

        // Plugin-local stylesheet, served only while the plugin is active.
        $app->get('/plugins/emeroteca/assets/{type}/{filename}', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->serveAsset($request, $response, $args);
        });

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

        // Public PDF access is opt-in per issue; newly uploaded scans stay
        // private until an administrator explicitly enables this route.
        $app->get('/emeroteca/fascicolo/{id:[0-9]+}/pdf', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->serveIssuePdf($response, $args, true);
        });

        // GET /emeroteca/{id} — testata detail
        $app->get('/emeroteca/{id:[0-9]+}', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin, $public): ResponseInterface {
            return $plugin->dispatch($public, 'showTestata', $request, $response, $args);
        });

        // ── Mobile bridge (/api/v1/periodicals, needs mobile-api) ────
        // The module guards itself: when the mobile-api plugin is not
        // active it mounts nothing. A bridge failure must never take the
        // web routes of the plugin down with it.
        try {
            if ($this->loadMobileModule()) {
                (new \App\Plugins\Emeroteca\Modules\MobileModule($this->db))->registerRoutes($app);
            }
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] mobile bridge registerRoutes failed: ' . $e->getMessage());
        }
    }

    /**
     * Load the mobile bridge module class (no PSR-4 scope for plugin
     * classes — same reason dispatch() require_once's the controllers).
     */
    private function loadMobileModule(): bool
    {
        if (!class_exists(\App\Plugins\Emeroteca\Modules\MobileModule::class)) {
            $file = __DIR__ . '/src/Modules/MobileModule.php';
            if (is_file($file)) {
                require_once $file;
            }
        }

        return class_exists(\App\Plugins\Emeroteca\Modules\MobileModule::class);
    }

    /**
     * Filter target for mobile-api's 'mobile_api.openapi' hook: appends the
     * /api/v1/periodicals bridge paths when the bridge is actually mounted
     * (book-club pattern — the availability re-check lives in the module).
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public function extendMobileOpenApi(array $doc): array
    {
        if (!$this->loadMobileModule()) {
            return $doc;
        }

        return \App\Plugins\Emeroteca\Modules\MobileModule::extendOpenApi($doc, $this->db);
    }

    /**
     * Serve CSS/JS bundled with the plugin, using the same realpath guard and
     * immutable cache policy as Archives and Digital Library.
     *
     * @param array<string,string> $args
     */
    public function serveAsset(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $type = (string) ($args['type'] ?? '');
        $filename = (string) ($args['filename'] ?? '');
        if (!in_array($type, ['css', 'js'], true)
            || preg_match('/^[A-Za-z0-9._-]+$/', $filename) !== 1) {
            return $response->withStatus(404);
        }

        $baseDir = realpath(__DIR__ . '/assets/' . $type);
        if ($baseDir === false) {
            return $response->withStatus(404);
        }
        $filePath = realpath($baseDir . DIRECTORY_SEPARATOR . $filename);
        if ($filePath === false
            || !str_starts_with($filePath, $baseDir . DIRECTORY_SEPARATOR)
            || !is_file($filePath)) {
            return $response->withStatus(404);
        }

        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return $response->withStatus(500);
        }
        $response->getBody()->write($contents);
        return $response
            ->withHeader('Content-Type', $type === 'css'
                ? 'text/css; charset=UTF-8'
                : 'application/javascript; charset=UTF-8')
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
    }

    /**
     * Stream an issue PDF from storage/uploads/emeroteca. Public requests are
     * accepted only when pdf_pubblico is enabled; admin authorization is
     * enforced by route middleware before this method runs.
     *
     * @param array<string,string> $args
     */
    public function serveIssuePdf(
        ResponseInterface $response,
        array $args,
        bool $publicOnly
    ): ResponseInterface {
        $id = (int) ($args['id'] ?? 0);
        $sql = 'SELECT pdf_path, pdf_nome_originale FROM emeroteca_fascicoli WHERE id = ? AND pdf_path IS NOT NULL';
        if ($publicOnly) {
            $sql .= ' AND pdf_pubblico = 1';
        }
        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] PDF lookup prepare failed: ' . $this->db->error);
            return $response->withStatus(500);
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] PDF lookup failed: ' . $stmt->error);
            $stmt->close();
            return $response->withStatus(500);
        }
        $res = $stmt->get_result();
        $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!is_array($row)) {
            return $response->withStatus(404);
        }

        $relative = (string) ($row['pdf_path'] ?? '');
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '..')) {
            return $response->withStatus(404);
        }
        $baseDir = realpath(__DIR__ . '/../../uploads/emeroteca');
        $filePath = $baseDir === false ? false : realpath($baseDir . DIRECTORY_SEPARATOR . basename($relative));
        if ($filePath === false
            || !str_starts_with($filePath, $baseDir . DIRECTORY_SEPARATOR)
            || !is_file($filePath)) {
            return $response->withStatus(404);
        }
        $handle = fopen($filePath, 'rb');
        $size = filesize($filePath);
        if ($handle === false || $size === false) {
            return $response->withStatus(404);
        }

        $original = basename((string) ($row['pdf_nome_originale'] ?? 'fascicolo.pdf'));
        if ($original === '' || strtolower(pathinfo($original, PATHINFO_EXTENSION)) !== 'pdf') {
            $original = 'fascicolo.pdf';
        }
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $original) ?: 'fascicolo.pdf';
        return $response
            ->withBody(new Stream($handle))
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Length', (string) $size)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // Never cacheable, even when pdf_pubblico is on: the flag is a
            // revocable privacy toggle, and a 'public' TTL would keep a
            // revoked (or deleted) PDF servable from shared/edge caches
            // (LiteSpeed edge in production) until expiry.
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader(
                'Content-Disposition',
                'inline; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($original)
            );
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

    // ── Core entity hook listeners (1.4.0) ────────────────────────────
    //
    // emeroteca_testate.editore_id / .genere_id and both
    // emeroteca_fascicoli.collocazione_id and emeroteca_annate.collocazione_id
    // are FKs towards core tables with ON DELETE SET NULL. That keeps the
    // database consistent but loses information: after a publisher merge the
    // surviving publisher is known, and letting the link go NULL instead of
    // following it is data loss the core cannot fix on the plugin's behalf.
    // These listeners are registered as plugin_hooks rows by onActivate() and
    // removed by onDeactivate() — an inactive plugin correctly stops
    // listening.
    //
    // All of them are best-effort: they log and return instead of throwing,
    // because most run INSIDE the core's transaction and an exception here
    // must never roll back a merge the operator asked for.

    /**
     * Listener for `publisher.merging` (action, emitted inside the merge
     * transaction BEFORE the duplicate rows are deleted): repoint every
     * testata of a duplicate publisher onto the surviving primary.
     *
     * @param array<int, mixed>|int $duplicateIds
     */
    public function onPublisherMerging(int $primaryId, $duplicateIds): void
    {
        $this->repointReference(
            'emeroteca_testate',
            'editore_id',
            $primaryId,
            is_array($duplicateIds) ? $duplicateIds : [$duplicateIds],
            'publisher.merging'
        );
    }

    /**
     * Listener for `genre.merging` (action, same contract as
     * publisher.merging): repoint the testate of the merged genres onto the
     * surviving target genre.
     *
     * @param array<int, mixed>|int $sourceIds
     */
    public function onGenreMerging(int $targetId, $sourceIds): void
    {
        $this->repointReference(
            'emeroteca_testate',
            'genere_id',
            $targetId,
            is_array($sourceIds) ? $sourceIds : [$sourceIds],
            'genre.merging'
        );
    }

    /**
     * Listener for `publisher.deleting` (action, emitted BEFORE the row is
     * removed). A delete has no survivor to follow, so the link genuinely
     * goes to NULL — but silently is the problem: this makes the loss
     * explicit in app.log, naming the affected testate, so the librarian can
     * re-attach them to the right publisher afterwards.
     *
     * The UPDATE is not strictly necessary (the FK does it, when present),
     * but on a degraded install where the FK was skipped it is what keeps
     * emeroteca_testate from pointing at a publisher that no longer exists.
     */
    public function onPublisherDeleting(int $publisherId): void
    {
        if ($publisherId <= 0) {
            return;
        }
        try {
            $titles = $this->referencingTitles('editore_id', $publisherId);
            if ($titles === []) {
                return;
            }
            SecureLogger::warning(
                '[Emeroteca] publisher ' . $publisherId . ' is being deleted; '
                . count($titles) . ' testata(e) lose their publisher link: '
                . implode(', ', array_map(static fn(array $t): string => '#' . $t['id'] . ' ' . $t['titolo'], $titles))
            );
            $stmt = $this->db->prepare(
                'UPDATE emeroteca_testate SET editore_id = NULL WHERE editore_id = ?'
            );
            if ($stmt === false) {
                SecureLogger::error('[Emeroteca] publisher.deleting prepare failed: ' . $this->db->error);
                return;
            }
            $stmt->bind_param('i', $publisherId);
            if (!$stmt->execute()) {
                SecureLogger::error('[Emeroteca] publisher.deleting update failed: ' . $stmt->error);
            }
            $stmt->close();
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] publisher.deleting listener error: ' . $e->getMessage());
        }
    }

    /**
     * Listener for the `shelf.can_delete` FILTER: veto the deletion of a
     * mensola that still holds emeroteca material.
     *
     * The core only counts `libri` before deleting a shelf, so a mensola used
     * exclusively by fascicoli or bound annate looks empty to it. Returning
     * false here keeps the location, exactly as the core does for books.
     *
     * Fails OPEN on purpose: if the probe itself breaks we return the value
     * we were given rather than blocking a legitimate delete on a broken
     * query — the core's contract is that only an explicit false vetoes.
     *
     * @param mixed $allowed value carried through the filter chain
     */
    public function onShelfCanDelete($allowed, int $mensolaId): bool
    {
        if ($allowed === false) {
            return false; // an earlier listener already vetoed
        }
        if ($mensolaId <= 0) {
            return true;
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM emeroteca_fascicoli WHERE collocazione_id = ?
                 UNION SELECT 1 FROM emeroteca_annate WHERE collocazione_id = ?
                 LIMIT 1'
            );
            if ($stmt === false) {
                SecureLogger::error('[Emeroteca] shelf.can_delete prepare failed: ' . $this->db->error);
                return true;
            }
            $stmt->bind_param('ii', $mensolaId, $mensolaId);
            if (!$stmt->execute()) {
                SecureLogger::error('[Emeroteca] shelf.can_delete probe failed: ' . $stmt->error);
                $stmt->close();
                return true;
            }
            $res = $stmt->get_result();
            $inUse = $res instanceof \mysqli_result && $res->fetch_row() !== null;
            $stmt->close();
            if ($inUse) {
                SecureLogger::debug(
                    '[Emeroteca] blocking deletion of mensola ' . $mensolaId . ': still referenced by emeroteca holdings'
                );
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] shelf.can_delete listener error: ' . $e->getMessage());
            return true;
        }
    }

    /**
     * Listener for `shelf.deleted` (action, after the row is gone). With the
     * FK in place the columns are already NULL; this only covers the
     * degraded-schema install where ensureCoreForeignKeys() had to skip the
     * mensole constraint, so a deleted shelf cannot leave dangling ids
     * behind.
     */
    public function onShelfDeleted(int $mensolaId): void
    {
        if ($mensolaId <= 0) {
            return;
        }
        foreach (['emeroteca_fascicoli', 'emeroteca_annate'] as $table) {
            try {
                // Table names come from the static list above — no user input.
                $stmt = $this->db->prepare(
                    "UPDATE {$table} SET collocazione_id = NULL WHERE collocazione_id = ?"
                );
                if ($stmt === false) {
                    SecureLogger::error('[Emeroteca] shelf.deleted prepare failed: ' . $this->db->error);
                    continue;
                }
                $stmt->bind_param('i', $mensolaId);
                if (!$stmt->execute()) {
                    SecureLogger::error('[Emeroteca] shelf.deleted cleanup failed on ' . $table . ': ' . $stmt->error);
                }
                $stmt->close();
            } catch (\Throwable $e) {
                SecureLogger::error('[Emeroteca] shelf.deleted listener error: ' . $e->getMessage());
            }
        }
    }

    // ── Public-discoverability listeners (1.4.0) ──────────────────────
    //
    // Both run on PUBLIC, unauthenticated requests of the core (the
    // sitemap generator and the catalogue page), on installs where the
    // emeroteca tables may not exist at all — the plugin ships disabled
    // and a half-finished activation leaves the schema partial. So every
    // method here probes before it queries and NEVER throws: the core
    // catches, but a listener that throws costs the whole sitemap (the
    // core falls back to the unfiltered entries) or the search hint.

    /** Sitemap ceilings, well under the core's own MAX_TOTAL_URLS (50k). */
    private const SITEMAP_MAX_TESTATE   = 5000;
    private const SITEMAP_MAX_FASCICOLI = 20000;
    private const SITEMAP_MAX_CONTRIBUTI = 10000;

    /** @var array<string,bool> per-instance table-existence cache */
    private array $tableProbeCache = [];

    /**
     * Listener for the `sitemap.entries` FILTER (contract in
     * App\Support\SitemapGenerator::applyEntriesFilter): append the
     * plugin's public pages to the site sitemap, which otherwise only
     * knows about core entities and would leave the whole emeroteca
     * invisible to crawlers.
     *
     * Added, in this order:
     *   - /emeroteca                     the section index (weekly, 0.6),
     *                                    lastmod = newest testata change;
     *   - /emeroteca/{id}                one per testata (monthly, 0.5);
     *   - /emeroteca/fascicolo/{id}      the issues the public frontend
     *                                    actually links (yearly, 0.3).
     *
     * Only `stato='posseduto'` issues are advertised: those are the only
     * ones testata.php turns into a link, and a withdrawn ('scartato'),
     * missing or merely expected issue is not a page worth a crawl
     * budget — publishing a 'scartato' issue would also advertise
     * material the library no longer holds.
     *
     * `loc` MUST start with the $baseUrl the generator passes (it
     * already contains the base path); anything else is discarded by the
     * core as an off-site URL.
     *
     * @param mixed $entries the entry list assembled so far
     * @return mixed the same list plus this plugin's entries; the input
     *         is returned untouched when it is not an array, so the
     *         core's own "non-array ⇒ keep core entries" guard fires
     *         instead of this listener silently emptying the sitemap
     */
    public function extendSitemapEntries($entries, string $baseUrl = '', string $defaultLocale = ''): mixed
    {
        if (!is_array($entries)) {
            return $entries;
        }
        try {
            $base = rtrim($baseUrl, '/');
            if ($base === '' || !$this->emerotecaTableExists('emeroteca_testate')) {
                return $entries;
            }

            $testate = $this->fetchRows(
                'SELECT id, updated_at FROM emeroteca_testate ORDER BY id LIMIT ' . self::SITEMAP_MAX_TESTATE
            );

            // Index lastmod: the most recent change to any testata, so
            // the section page ages with its content.
            $indexLastmod = null;
            foreach ($testate as $testata) {
                $stamp = isset($testata['updated_at']) ? (string) $testata['updated_at'] : '';
                if ($stamp !== '' && ($indexLastmod === null || $stamp > $indexLastmod)) {
                    $indexLastmod = $stamp;
                }
            }

            $entries[] = array_filter([
                'loc'        => $base . '/emeroteca',
                'lastmod'    => $indexLastmod,
                'changefreq' => 'weekly',
                'priority'   => '0.6',
            ], static fn($value): bool => $value !== null);

            foreach ($testate as $testata) {
                $id = (int) ($testata['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $entries[] = array_filter([
                    'loc'        => $base . '/emeroteca/' . $id,
                    'lastmod'    => isset($testata['updated_at']) ? (string) $testata['updated_at'] : null,
                    'changefreq' => 'monthly',
                    'priority'   => '0.5',
                ], static fn($value): bool => $value !== null);
            }

            if ($this->emerotecaTableExists('emeroteca_contributi')) {
                foreach ($this->fetchRows('SELECT id, updated_at FROM emeroteca_contributi WHERE pubblico=1 ORDER BY id LIMIT ' . self::SITEMAP_MAX_CONTRIBUTI) as $article) {
                    $entries[] = ['loc'=>$base . '/emeroteca/articolo/' . (int)$article['id'], 'lastmod'=>$article['updated_at'], 'changefreq'=>'monthly', 'priority'=>'0.4'];
                }
            }
            if ($this->emerotecaTableExists('emeroteca_fascicoli')) {
                $fascicoli = $this->fetchRows(
                    "SELECT id, updated_at FROM emeroteca_fascicoli
                      WHERE stato = 'posseduto'
                      ORDER BY id LIMIT " . self::SITEMAP_MAX_FASCICOLI
                );
                foreach ($fascicoli as $fascicolo) {
                    $id = (int) ($fascicolo['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $entries[] = array_filter([
                        'loc'        => $base . '/emeroteca/fascicolo/' . $id,
                        'lastmod'    => isset($fascicolo['updated_at']) ? (string) $fascicolo['updated_at'] : null,
                        'changefreq' => 'yearly',
                        'priority'   => '0.3',
                    ], static fn($value): bool => $value !== null);
                }
            }
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] sitemap.entries listener error: ' . $e->getMessage());
        }
        return $entries;
    }

    /**
     * Listener for the `search.external_suggestions` FILTER (contract in
     * App\Controllers\FrontendController::collectExternalSearchSuggestions):
     * point a catalogue search at the emeroteca when the term matches
     * something there.
     *
     * The catalogue only reads `libri.search_index`, so searching for a
     * periodical title or an indexed article gives "nessun risultato"
     * even though the library holds it — a dead end the visitor has no
     * way out of. This appends ONE link to /emeroteca?q=<term>.
     *
     * The suggestion is emitted ONLY on a real match, as the contract
     * demands ("MUST NOT return a suggestion when it has no match"): two
     * existence probes with LIMIT 1, short-circuiting on the first hit,
     * using LIKE for masthead titles and the public search FULLTEXT index
     * for article titles, restricted to non-withdrawn issues.
     *
     * @param mixed $suggestions the suggestions collected so far
     * @return mixed append-only; a non-array input is passed through
     *         untouched (the core discards it) rather than replaced
     */
    public function suggestEmerotecaSearch($suggestions, string $term = ''): mixed
    {
        if (!is_array($suggestions)) {
            return $suggestions;
        }
        try {
            $needle = trim($term);
            // One- or zero-character terms would drag a full LIKE '%x%'
            // scan over every testata and article for a hint nobody can
            // act on; the cap mirrors PublicController::index().
            if (mb_strlen($needle) < 2) {
                return $suggestions;
            }
            $needle = mb_substr($needle, 0, 200);

            if (!$this->emerotecaMatches($needle)) {
                return $suggestions;
            }

            $path = function_exists('url') ? (string) url('/emeroteca') : '/emeroteca';
            if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
                // The core rejects anything that is not a single-slash
                // same-origin path; do not hand it a URL it will drop.
                $path = '/emeroteca';
            }

            $suggestions[] = [
                'label' => function_exists('__')
                    ? (string) __('Emeroteca (testate e spoglio degli articoli)')
                    : 'Emeroteca (testate e spoglio degli articoli)',
                'url'   => $path . '?q=' . rawurlencode($needle),
            ];
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] search.external_suggestions listener error: ' . $e->getMessage());
        }
        return $suggestions;
    }

    /**
     * True when at least one testata or one indexed article matches the
     * term. Two separate LIMIT 1 probes rather than a UNION so each is
     * guarded by its own table probe: on a degraded install one table
     * can exist without the other.
     */
    private function emerotecaMatches(string $term): bool
    {
        $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';

        $probes = [];
        if ($this->emerotecaTableExists('emeroteca_testate')) {
            $probes[] = ["SELECT 1 FROM emeroteca_testate
                          WHERE titolo LIKE ? ESCAPE '\\\\'
                             OR sottotitolo LIKE ? ESCAPE '\\\\'
                             OR issn LIKE ? ESCAPE '\\\\'
                          LIMIT 1", 'sss', [$pattern, $pattern, $pattern]];
        }
        if ($this->emerotecaTableExists('emeroteca_contributi')) {
            // Same fields as the public article search, public rows only, and a
            // LIMIT 1 existence probe like its neighbours: this runs on every
            // catalogue miss, and it goes through the loop below so a failure
            // is logged instead of breaking the search that asked for a hint.
            $probes[] = ["SELECT 1 FROM emeroteca_contributi
                          WHERE pubblico = 1
                            AND (titolo LIKE ? ESCAPE '\\\\'
                                 OR autori LIKE ? ESCAPE '\\\\'
                                 OR contenitore_titolo LIKE ? ESCAPE '\\\\'
                                 OR keywords LIKE ? ESCAPE '\\\\'
                                 OR issn = ?)
                          LIMIT 1", 'sssss', [$pattern, $pattern, $pattern, $pattern, $term]];
        }
        if ($this->emerotecaTableExists('emeroteca_articoli')) {
            // This hint uses the same token search as the public article search.
            // The FULLTEXT index avoids a full article scan on every catalogue miss.
            $probes[] = ["SELECT 1 FROM emeroteca_articoli ar
                          JOIN emeroteca_fascicoli f ON f.id = ar.fascicolo_id
                          WHERE f.stato <> 'scartato'
                            AND MATCH(ar.titolo, ar.autori, ar.keywords)
                                AGAINST (? IN NATURAL LANGUAGE MODE)
                          LIMIT 1", 's', [$term]];
        }

        foreach ($probes as [$sql, $types, $params]) {
            $stmt = $this->db->prepare($sql);
            if ($stmt === false) {
                SecureLogger::error('[Emeroteca] search suggestion probe prepare failed: ' . $this->db->error);
                continue;
            }
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) {
                SecureLogger::error('[Emeroteca] search suggestion probe failed: ' . $stmt->error);
                $stmt->close();
                continue;
            }
            $res = $stmt->get_result();
            $hit = $res instanceof \mysqli_result && $res->fetch_row() !== null;
            if ($res instanceof \mysqli_result) {
                $res->free();
            }
            $stmt->close();
            if ($hit) {
                return true;
            }
        }
        return false;
    }

    /**
     * Table-existence probe for the listeners above: cached per request
     * and, unlike coreTableExists(), it never throws — these run on
     * public pages where an exception would cost the whole sitemap or
     * the catalogue hint.
     */
    /** True once at least one masthead exists. Never cached: see ensureSchema(). */
    private function emerotecaHasMastheads(): bool
    {
        try {
            $res = $this->db->query('SELECT 1 FROM emeroteca_testate LIMIT 1');
            return $res instanceof \mysqli_result && $res->num_rows > 0;
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] masthead probe failed: ' . $e->getMessage());
            return false;
        }
    }

    private function emerotecaTableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableProbeCache)) {
            return $this->tableProbeCache[$table];
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
            SecureLogger::error('[Emeroteca] listener table probe failed for ' . $table . ': ' . $e->getMessage());
        }
        return $this->tableProbeCache[$table] = $exists;
    }

    /**
     * Run a parameterless SELECT and return its rows. Used only with the
     * static SQL literals above (the LIMITs are class constants), never
     * with user input.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchRows(string $sql): array
    {
        $rows = [];
        $res = $this->db->query($sql);
        if ($res === false) {
            SecureLogger::error('[Emeroteca] listener query failed: ' . $this->db->error);
            return [];
        }
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $res->free();
        }
        return $rows;
    }

    /**
     * Move every row of $table whose $column points at one of $fromIds onto
     * $toId. Shared by the publisher and genre merge listeners.
     *
     * $toId is excluded from the source list: repointing a row onto itself is
     * a no-op, and including it would make the statement depend on argument
     * order.
     *
     * @param array<int, mixed> $fromIds
     */
    private function repointReference(
        string $table,
        string $column,
        int $toId,
        array $fromIds,
        string $hookName
    ): void {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn(mixed $id): int => (int) $id, $fromIds),
            static fn(int $id): bool => $id > 0 && $id !== $toId
        )));
        if ($toId <= 0 || $ids === []) {
            return;
        }
        try {
            // $table/$column are literals from the two call sites above; only
            // the ids are bound, and they are cast to int first.
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare(
                "UPDATE {$table} SET {$column} = ? WHERE {$column} IN ({$placeholders})"
            );
            if ($stmt === false) {
                SecureLogger::error('[Emeroteca] ' . $hookName . ' prepare failed: ' . $this->db->error);
                return;
            }
            $params = [$toId, ...$ids];
            $stmt->bind_param(str_repeat('i', count($params)), ...$params);
            if (!$stmt->execute()) {
                SecureLogger::error('[Emeroteca] ' . $hookName . ' repoint failed: ' . $stmt->error);
                $stmt->close();
                return;
            }
            $moved = $stmt->affected_rows;
            $stmt->close();
            if ($moved > 0) {
                SecureLogger::debug(
                    '[Emeroteca] ' . $hookName . ': repointed ' . $moved . ' row(s) of '
                    . $table . '.' . $column . ' onto ' . $toId
                );
            }
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] ' . $hookName . ' listener error: ' . $e->getMessage());
        }
    }

    /**
     * Testate (id + titolo) referencing a core row through $column, capped so
     * a warning can never turn into a megabyte of log.
     *
     * @return list<array{id:int, titolo:string}>
     */
    private function referencingTitles(string $column, int $refId): array
    {
        // $column is a literal from the single call site above.
        $stmt = $this->db->prepare(
            "SELECT id, titolo FROM emeroteca_testate WHERE {$column} = ? ORDER BY id LIMIT 20"
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] referencing-titles prepare failed: ' . $this->db->error);
            return [];
        }
        $stmt->bind_param('i', $refId);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] referencing-titles query failed: ' . $stmt->error);
            $stmt->close();
            return [];
        }
        $out = [];
        $res = $stmt->get_result();
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $out[] = ['id' => (int) $row['id'], 'titolo' => (string) $row['titolo']];
            }
        }
        $stmt->close();
        return $out;
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
     *
     * Counting is driven by `stato` (possession), NEVER by `condizione`:
     * after the 1.4.0 split a damaged-but-owned issue (stato='posseduto',
     * condizione='danneggiato') counts as owned — the pre-1.4.0 bug where
     * damaged issues silently disappeared from the consistenza fixes
     * itself with the data normalization.
     *
     * 'scartato' (withdrawn on purpose) counts NEITHER as owned NOR as a
     * gap: a deliberate discard is not a hole in the collection, and
     * claiming it as owned would misstate the holdings. Same reasoning
     * for 'smarrito'/'reclamato'/'atteso': only 'mancante' is a lacuna.
     *
     * When an annata carries a curator-written `consistenza_dichiarata`,
     * the declared string(s) are appended (chronological, '; '-joined) —
     * and shown alone when there are no computed holdings at all.
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
        $min        = $row['anno_min'] !== null ? (int) $row['anno_min'] : null;
        $max        = $row['anno_max'] !== null ? (int) $row['anno_max'] : null;
        $lacune     = (int) $row['lacune'];
        $dichiarata = self::declaredHoldings($db, [$testataId])[$testataId] ?? '';

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
        if ($dichiarata !== '') {
            $out = ($out === '—') ? $dichiarata : $out . ' · ' . $dichiarata;
        }
        return $out;
    }

    /**
     * Complete declared holdings, shared by the admin list and public summary.
     * Concatenate in PHP so group_concat_max_len can never truncate the data.
     * @param list<int> $ids
     * @return array<int, string>
     */
    public static function declaredHoldings(mysqli $db, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT testata_id, consistenza_dichiarata FROM emeroteca_annate
              WHERE testata_id IN ({$placeholders})
                AND consistenza_dichiarata IS NOT NULL AND consistenza_dichiarata <> ''
              ORDER BY testata_id, anno, volume, id"
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] declared holdings prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] declared holdings failed: ' . $stmt->error);
            $stmt->close();
            return [];
        }
        $res = $stmt->get_result();
        while ($res instanceof \mysqli_result && ($row = $res->fetch_assoc())) {
            $out[(int) $row['testata_id']][] = (string) $row['consistenza_dichiarata'];
        }
        $stmt->close();
        return array_map(static fn(array $parts): string => trim(implode('; ', $parts)), $out);
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
