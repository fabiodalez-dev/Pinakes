<?php

declare(strict_types=1);

namespace App\Plugins\FrbrLrm;

use App\Support\HookManager;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * FRBR / IFLA LRM plugin — Work (opere) & Expression (espressioni) layer.
 *
 * Issue #134. Adds an optional bibliographic abstraction on top of the
 * Manifestation-level `libri` table so that multiple editions of the same
 * intellectual work can be grouped under a single Opera, and translations /
 * revisions / adaptations can be modelled as Expressions.
 *
 * Naming note — `opere` vs `volumi.opera_id`:
 *   The pre-existing `volumi` table has a column `opera_id` that is a FK to
 *   `libri.id` (the "parent book" of a multi-volume set). That is a DIFFERENT
 *   concept from the FRBR Work introduced here. To avoid collisions:
 *     - the new Work entity lives in its own table `opere` (PK `opere.id`);
 *     - the new FK on `libri` is `libri.opera_id` → `opere.id`;
 *     - `volumi.opera_id` is untouched and keeps pointing at `libri.id`.
 *   They never share a row or a constraint, so there is no technical clash —
 *   only a name coincidence that this comment exists to disambiguate.
 */
class FrbrLrmPlugin
{
    private mysqli $db;
    private ?int $pluginId = null;

    /**
     * PluginManager always instantiates plugins as `new $class($db, $hookManager)`,
     * so the constructor must accept the HookManager to satisfy that contract.
     * This plugin registers its hooks declaratively via registerHookInDb() during
     * onActivate() and does not need to hold a HookManager reference at runtime —
     * notably it must NOT call $hookManager->doAction() during activation, which
     * would trigger HookManager::loadHooks() before PluginManager has called
     * setPluginsLoadedRuntime(), double-loading every plugin's route hooks
     * (DB class-based + runtime callback) and crashing route registration with
     * "Cannot register two routes". The param is intentionally not stored.
     */
    public function __construct(mysqli $db, HookManager $hookManager) // @phpstan-ignore constructor.unusedParameter
    {
        $this->db = $db;
    }

    public function setPluginId(int $pluginId): void
    {
        $this->pluginId = $pluginId;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Lifecycle
    // ─────────────────────────────────────────────────────────────────────

    public function onActivate(): void
    {
        $result = $this->ensureSchema();
        if (!empty($result['failed'])) {
            throw new \RuntimeException(
                '[FrbrLrm] Schema activation failed for: ' . implode(', ', $result['failed'])
                . '. See app.log for the mysqli error emitted during each CREATE TABLE.'
            );
        }

        $this->db->begin_transaction();
        try {
            $this->registerHookInDb('app.routes.register', 'registerRoutes', 20);
            $this->registerHookInDb('admin.menu.render', 'renderAdminMenuEntry', 20);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Slim route registration (hook `app.routes.register`).
     * Admin CRUD for opere + the public /opera/{slug} page.
     *
     * @param \Slim\App<\Psr\Container\ContainerInterface|null> $app
     */
    public function registerRoutes($app): void
    {
        $plugin = $this;
        $adminMiddleware = new \App\Middleware\AdminAuthMiddleware();
        $csrfMiddleware  = new \App\Middleware\CsrfMiddleware();

        // ── Admin: opere list ──
        $app->get('/admin/opere', function (ServerRequestInterface $req, ResponseInterface $res) use ($plugin): ResponseInterface {
            return $plugin->adminIndexAction($req, $res);
        })->add($adminMiddleware);

        // ── Admin: new opera form ──
        $app->get('/admin/opere/new', function (ServerRequestInterface $req, ResponseInterface $res) use ($plugin): ResponseInterface {
            return $plugin->adminNewAction($req, $res);
        })->add($adminMiddleware);

        // ── Admin: create opera ──
        $app->post('/admin/opere/new', function (ServerRequestInterface $req, ResponseInterface $res) use ($plugin): ResponseInterface {
            return $plugin->adminStoreAction($req, $res);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // ── Admin: opera detail ──
        $app->get('/admin/opere/{id:[0-9]+}', function (ServerRequestInterface $req, ResponseInterface $res, array $args) use ($plugin): ResponseInterface {
            return $plugin->adminShowAction($req, $res, (int) $args['id']);
        })->add($adminMiddleware);

        // ── Admin: edit opera form ──
        $app->get('/admin/opere/{id:[0-9]+}/edit', function (ServerRequestInterface $req, ResponseInterface $res, array $args) use ($plugin): ResponseInterface {
            return $plugin->adminEditAction($req, $res, (int) $args['id']);
        })->add($adminMiddleware);

        // ── Admin: update opera ──
        $app->post('/admin/opere/{id:[0-9]+}/edit', function (ServerRequestInterface $req, ResponseInterface $res, array $args) use ($plugin): ResponseInterface {
            return $plugin->adminUpdateAction($req, $res, (int) $args['id']);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // ── Admin: delete opera (soft-delete) ──
        $app->post('/admin/opere/{id:[0-9]+}/delete', function (ServerRequestInterface $req, ResponseInterface $res, array $args) use ($plugin): ResponseInterface {
            return $plugin->adminDeleteAction($req, $res, (int) $args['id']);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // ── Admin: attach a book to an opera (from the book edit form) ──
        $app->post('/admin/books/{id:[0-9]+}/attach-opera', function (ServerRequestInterface $req, ResponseInterface $res, array $args) use ($plugin): ResponseInterface {
            return $plugin->attachBookToOperaAction($req, $res, (int) $args['id']);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // ── Admin: opera autocomplete (for attach UI) ──
        $app->get('/api/opere/search', function (ServerRequestInterface $req, ResponseInterface $res) use ($plugin): ResponseInterface {
            return $plugin->apiSearchOpereAction($req, $res);
        })->add($adminMiddleware);

        // ── Public: opera page with all editions ──
        $app->get('/opera/{slug}', function (ServerRequestInterface $req, ResponseInterface $res, array $args) use ($plugin): ResponseInterface {
            return $plugin->publicOperaAction($req, $res, (string) $args['slug']);
        });
    }

    public function onInstall(): void
    {
        // ensureSchema() is idempotent and safe to call again here so that an
        // install path that skips onActivate() (e.g. upgrade re-seeding) still
        // gets the tables. Matches the ABSOLUTE plugin-schema rule in CLAUDE.md.
        $this->ensureSchema();
    }

    public function onDeactivate(): void
    {
        // Per #134: deactivation leaves the tables intact (data is precious)
        // and only disables the UI. The hooks are removed by PluginManager.
    }

    // ─────────────────────────────────────────────────────────────────────
    // Schema
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Create the FRBR/LRM tables and add the nullable FKs on `libri`.
     * Idempotent: CREATE TABLE IF NOT EXISTS + INFORMATION_SCHEMA guards.
     *
     * @return array{created: array<int,string>, failed: array<int,string>}
     */
    /**
     * Tables this plugin's ensureSchema() always creates. Declared so
     * PluginManager's boot-time self-heal re-runs ensureSchema when any is
     * missing on an already-active plugin (a partial/aborted upgrade). One
     * cheap read-only probe; DDL only runs when a table is actually absent.
     *
     * @return list<string>
     */
    public function expectedTables(): array
    {
        return ['opere', 'espressioni', 'libri_autori_ruoli'];
    }

    public function ensureSchema(): array
    {
        $steps = [
            'opere'              => self::ddlOpere(),
            'espressioni'        => self::ddlEspressioni(),
            'libri_autori_ruoli' => self::ddlLibriAutoriRuoli(),
        ];

        $created = [];
        $failed = [];

        foreach ($steps as $table => $ddl) {
            try {
                if ($this->db->query($ddl) === false) {
                    $failed[] = $table;
                    SecureLogger::warning('[FrbrLrm] CREATE TABLE failed for ' . $table . ': ' . $this->db->error);
                } else {
                    $created[] = $table;
                }
            } catch (\Throwable $e) {
                $failed[] = $table;
                SecureLogger::error('[FrbrLrm] Exception during CREATE TABLE ' . $table . ': ' . $e->getMessage());
            }
        }

        // Add nullable FKs on libri only if they don't already exist.
        $this->addLibriColumnIfMissing('opera_id', 'INT NULL', 'fk_libri_opera', 'opere');
        $this->addLibriColumnIfMissing('espressione_id', 'INT NULL', 'fk_libri_espressione', 'espressioni');

        return ['created' => $created, 'failed' => $failed];
    }

    /**
     * Idempotently add a nullable FK column to `libri`. Uses INFORMATION_SCHEMA
     * to check both the column and the constraint so re-activation is safe.
     */
    private function addLibriColumnIfMissing(string $column, string $type, string $fkName, string $refTable): void
    {
        $colExists = $this->scalar(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = ?",
            's',
            $column
        );
        if ($colExists === 0) {
            if ($this->db->query("ALTER TABLE `libri` ADD COLUMN `{$column}` {$type}") === false) {
                SecureLogger::warning("[FrbrLrm] ALTER libri ADD {$column} failed: " . $this->db->error);
                return;
            }
            $this->db->query("ALTER TABLE `libri` ADD INDEX `idx_libri_{$column}` (`{$column}`)");
        }

        $fkExists = $this->scalar(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri'
                AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            's',
            $fkName
        );
        if ($fkExists === 0) {
            // ON DELETE SET NULL: deleting an Opera/Expression must NOT cascade
            // into deleting the books — they just detach back to Manifestation-only.
            $sql = "ALTER TABLE `libri` ADD CONSTRAINT `{$fkName}` FOREIGN KEY (`{$column}`)"
                 . " REFERENCES `{$refTable}` (`id`) ON DELETE SET NULL";
            if ($this->db->query($sql) === false) {
                SecureLogger::warning("[FrbrLrm] ADD CONSTRAINT {$fkName} failed: " . $this->db->error);
            }
        }
    }

    private static function ddlOpere(): string
    {
        return <<<SQL
            CREATE TABLE IF NOT EXISTS `opere` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `titolo_uniforme` VARCHAR(255) NOT NULL COMMENT 'LRM nomen / RDA preferred title of the work',
              `titolo_originale` VARCHAR(255) DEFAULT NULL,
              `autore_principale_id` INT DEFAULT NULL COMMENT 'FK autori — LRM creator of the work',
              `data_creazione_da` SMALLINT DEFAULT NULL COMMENT 'Year the work was created (from)',
              `data_creazione_a` SMALLINT DEFAULT NULL COMMENT 'Year the work was created (to)',
              `lingua_originale` VARCHAR(50) DEFAULT NULL,
              `viaf_work_id` VARCHAR(50) DEFAULT NULL,
              `sbn_work_bid` VARCHAR(20) DEFAULT NULL COMMENT 'Populated by the reicat-sbn plugin (#133) when present',
              `wikidata_id` VARCHAR(32) DEFAULT NULL,
              `slug` VARCHAR(255) DEFAULT NULL,
              `note` TEXT DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `deleted_at` DATETIME DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_opere_slug` (`slug`),
              KEY `idx_opere_autore` (`autore_principale_id`),
              KEY `idx_opere_titolo` (`titolo_uniforme`),
              KEY `idx_opere_deleted` (`deleted_at`),
              KEY `idx_opere_viaf` (`viaf_work_id`),
              KEY `idx_opere_sbn` (`sbn_work_bid`),
              CONSTRAINT `fk_opere_autore` FOREIGN KEY (`autore_principale_id`)
                REFERENCES `autori` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL;
    }

    private static function ddlEspressioni(): string
    {
        return <<<SQL
            CREATE TABLE IF NOT EXISTS `espressioni` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `opera_id` INT NOT NULL,
              `lingua` VARCHAR(50) DEFAULT NULL,
              `tipo_espressione` ENUM('testo','traduzione','revisione','adattamento','edizione_critica','audio','altro')
                NOT NULL DEFAULT 'testo',
              `traduttore_autore_id` INT DEFAULT NULL,
              `curatore_autore_id` INT DEFAULT NULL,
              `revisore_autore_id` INT DEFAULT NULL,
              `titolo_espressione` VARCHAR(255) DEFAULT NULL,
              `anno_espressione` SMALLINT DEFAULT NULL,
              `note` TEXT DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `deleted_at` DATETIME DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_espressioni_opera` (`opera_id`),
              KEY `idx_espressioni_tipo` (`tipo_espressione`),
              KEY `idx_espressioni_deleted` (`deleted_at`),
              CONSTRAINT `fk_espressioni_opera` FOREIGN KEY (`opera_id`)
                REFERENCES `opere` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_espressioni_traduttore` FOREIGN KEY (`traduttore_autore_id`)
                REFERENCES `autori` (`id`) ON DELETE SET NULL,
              CONSTRAINT `fk_espressioni_curatore` FOREIGN KEY (`curatore_autore_id`)
                REFERENCES `autori` (`id`) ON DELETE SET NULL,
              CONSTRAINT `fk_espressioni_revisore` FOREIGN KEY (`revisore_autore_id`)
                REFERENCES `autori` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL;
    }

    private static function ddlLibriAutoriRuoli(): string
    {
        return <<<SQL
            CREATE TABLE IF NOT EXISTS `libri_autori_ruoli` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `libro_id` INT NOT NULL,
              `autore_id` INT NOT NULL,
              `relator_code` VARCHAR(3) NOT NULL COMMENT 'MARC21 relator code: aut, edt, trl, ill, ctb...',
              `relator_label` VARCHAR(100) DEFAULT NULL,
              `ordine` SMALLINT NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_libro_autore_relator` (`libro_id`,`autore_id`,`relator_code`),
              KEY `idx_lar_autore` (`autore_id`),
              KEY `idx_lar_relator` (`relator_code`),
              CONSTRAINT `fk_lar_libro` FOREIGN KEY (`libro_id`)
                REFERENCES `libri` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_lar_autore` FOREIGN KEY (`autore_id`)
                REFERENCES `autori` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Admin menu (hook `admin.menu.render`)
    // ─────────────────────────────────────────────────────────────────────

    public function renderAdminMenuEntry(): void
    {
        $href = htmlspecialchars(url('/admin/opere'), ENT_QUOTES, 'UTF-8');
        $title = function_exists('__') ? __('Opere') : 'Opere';
        $subtitle = function_exists('__') ? __('Modello FRBR/LRM (Work)') : 'Modello FRBR/LRM (Work)';
        echo <<<HTML

          <a class="nav-link group flex items-center px-4 py-3 rounded-lg transition-all duration-200 hover:bg-gray-100 text-gray-700 hover:text-gray-900"
            href="$href">
            <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-100 group-hover:bg-gray-200 transition-all duration-200">
              <i class="fas fa-sitemap text-gray-600"></i>
            </div>
            <div class="ml-3">
              <div class="font-medium">$title</div>
              <div class="text-xs text-gray-500">$subtitle</div>
            </div>
          </a>

        HTML;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Admin actions — opere CRUD
    // ─────────────────────────────────────────────────────────────────────

    public function adminIndexAction(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $repo = new OpereRepository($this->db);
        $opere = $repo->list(200);
        return $this->renderView($response, 'admin/opere/index', [
            'opere' => $opere,
            'pageTitle' => __('Opere (FRBR/LRM)'),
        ]);
    }

    public function adminNewAction(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderView($response, 'admin/opere/form', [
            'opera' => null,
            'autori' => $this->autoriForSelect(),
            'pageTitle' => __('Nuova Opera'),
        ]);
    }

    public function adminStoreAction(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        if (trim((string) ($data['titolo_uniforme'] ?? '')) === '') {
            return $this->redirect($response, '/admin/opere/new');
        }
        $repo = new OpereRepository($this->db);
        $id = $repo->create($data);
        return $this->redirect($response, '/admin/opere/' . $id);
    }

    public function adminShowAction(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $repo = new OpereRepository($this->db);
        $opera = $repo->getById($id);
        if ($opera === null) {
            $response->getBody()->write(htmlspecialchars(__('Opera non trovata'), ENT_QUOTES, 'UTF-8'));
            return $response->withStatus(404);
        }
        return $this->renderView($response, 'admin/opere/show', [
            'opera' => $opera,
            'edizioni' => $repo->editionsForOpera($id),
            'espressioni' => (new EspressioniRepository($this->db))->listForOpera($id),
            'pageTitle' => $opera['titolo_uniforme'],
        ]);
    }

    public function adminEditAction(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $repo = new OpereRepository($this->db);
        $opera = $repo->getById($id);
        if ($opera === null) {
            return $this->redirect($response, '/admin/opere');
        }
        return $this->renderView($response, 'admin/opere/form', [
            'opera' => $opera,
            'autori' => $this->autoriForSelect(),
            'pageTitle' => __('Modifica Opera'),
        ]);
    }

    public function adminUpdateAction(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        (new OpereRepository($this->db))->update($id, $data);
        return $this->redirect($response, '/admin/opere/' . $id);
    }

    public function adminDeleteAction(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        (new OpereRepository($this->db))->softDelete($id);
        return $this->redirect($response, '/admin/opere');
    }

    /** Attach a book to an opera (POST from the book edit form). */
    public function attachBookToOperaAction(ServerRequestInterface $request, ResponseInterface $response, int $libroId): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $operaId = !empty($data['opera_id']) ? (int) $data['opera_id'] : null;
        $stmt = $this->db->prepare('UPDATE libri SET opera_id = ? WHERE id = ? AND deleted_at IS NULL');
        $stmt->bind_param('ii', $operaId, $libroId);
        $stmt->execute();
        $stmt->close();
        return $this->redirect($response, '/admin/books/' . $libroId);
    }

    /** Opera autocomplete JSON for the attach UI. */
    public function apiSearchOpereAction(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $results = $q === '' ? [] : (new OpereRepository($this->db))->search($q, 10);
        $response->getBody()->write((string) json_encode($results, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Public action — /opera/{slug}
    // ─────────────────────────────────────────────────────────────────────

    public function publicOperaAction(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $repo = new OpereRepository($this->db);
        $opera = $repo->getBySlug($slug);
        if ($opera === null) {
            $response->getBody()->write(htmlspecialchars(__('Opera non trovata'), ENT_QUOTES, 'UTF-8'));
            return $response->withStatus(404);
        }
        $edizioni = $repo->editionsForOpera((int) $opera['id']);

        $viewFile = __DIR__ . '/views/frontend/opera.php';
        ob_start();
        $content = '';
        if (is_file($viewFile)) {
            extract(['opera' => $opera, 'edizioni' => $edizioni], EXTR_SKIP);
            require $viewFile;
            $content = (string) ob_get_clean();
        } else {
            ob_end_clean();
        }
        // Wrap in the frontend layout for consistent public chrome.
        $layout = __DIR__ . '/../../../app/Views/frontend/layout.php';
        if (is_file($layout)) {
            ob_start();
            require $layout;
            $html = (string) ob_get_clean();
            $response->getBody()->write($html);
        } else {
            $response->getBody()->write($content);
        }
        return $response->withHeader('Content-Type', 'text/html');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Render + helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     */
    private function renderView(ResponseInterface $response, string $view, array $data): ResponseInterface
    {
        $viewFile = __DIR__ . '/views/' . $view . '.php';
        if (!is_file($viewFile)) {
            SecureLogger::error('[FrbrLrm] view not found: ' . $viewFile);
            $response->getBody()->write('FrbrLrm view missing: ' . htmlspecialchars($view, ENT_QUOTES, 'UTF-8'));
            return $response->withStatus(500);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $viewFile;
        $content = (string) ob_get_clean();
        ob_start();
        require __DIR__ . '/../../../app/Views/layout.php';
        $html = (string) ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Issue a 302 to an INTERNAL path only. Defense-in-depth against open
     * redirects (CWE-601): every caller passes a literal/integer-built path,
     * but we still reject anything that isn't a single-slash absolute path so
     * a future caller can't accidentally forward user input to `//evil.com`.
     */
    private function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            $path = '/admin/opere';
        }
        return $response->withHeader('Location', url($path))->withStatus(302);
    }

    /** @return array<int, array{id:int, nome:string}> */
    private function autoriForSelect(): array
    {
        $out = [];
        $display = \App\Support\AuthorName::displaySql('a');
        $res = $this->db->query("SELECT a.id, {$display} AS nome FROM autori a ORDER BY nome ASC LIMIT 1000");
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $out[] = ['id' => (int) $row['id'], 'nome' => (string) $row['nome']];
            }
            $res->free();
        }
        return $out;
    }

    /**
     * Run a single-scalar query with one bound param and return it as int.
     */
    private function scalar(string $sql, string $types, string $value): int
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param($types, $value);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_row() : null;
        $stmt->close();
        return $row ? (int) $row[0] : 0;
    }

    private function registerHookInDb(string $hookName, string $method, int $priority): void
    {
        if ($this->pluginId === null) {
            SecureLogger::warning('[FrbrLrm] pluginId not set; cannot register hook ' . $hookName);
            return;
        }
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
            throw new \RuntimeException('[FrbrLrm] prepare() failed for hook ' . $hookName . ': ' . $this->db->error);
        }
        // PluginManager instantiates the global proxy class (no namespace).
        $callbackClass = 'FrbrLrmPlugin';
        $stmt->bind_param('isssi', $this->pluginId, $hookName, $callbackClass, $method, $priority);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('[FrbrLrm] hook insert failed for ' . $hookName . ': ' . $err);
        }
        $stmt->close();
    }
}
