<?php
declare(strict_types=1);

use App\Support\HookManager;
use App\Support\ContentCache;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Library requests are catalogue records; donations remain proposals until receipt. */
class DesiderataPlugin
{
    private ?int $pluginId = null;
    /** Memoised plugins.id, for the hook instances that never get setPluginId(). */
    private static ?int $resolvedPluginId = null;
    /** One CSS/JS block per request, however many forms the page holds. */
    private static bool $assetsPrinted = false;
    /**
     * Part of the constructor contract PluginManager calls with; this plugin
     * registers its hooks in the database and never dispatches any itself.
     * Same treatment as bibframe-linked-data and openurl-resolver.
     *
     * @phpstan-ignore property.onlyWritten
     */
    private HookManager $hooks;
    public function __construct(private mysqli $db, HookManager $hooks) { $this->hooks = $hooks; }
    public function setPluginId(int $id): void { $this->pluginId = $id; }
    public function expectedTables(): array { return ['desiderata_offers']; }
    public function expectedColumns(): array { return [['table' => 'libri', 'column' => 'is_desiderata']]; }
    public function onInstall(): void { $this->ensureSchema(); }
    /**
     * Bibliographic data and donation history are preserved on purpose, but the
     * flag is cleared: BookVisibility hides a flagged book whenever the column
     * exists, so leaving it set would keep those records out of the catalogue
     * forever, with the checkbox gone and nothing in the admin explaining why.
     */
    public function onUninstall(): void
    {
        // CI-SOFT-DELETE-EXEMPT: archived rows must be cleared too. The flag
        // hides a book for as long as the column exists; leaving it set on a
        // soft-deleted record would make any later restore produce a title that
        // is invisible in the catalogue, with the checkbox gone and nothing in
        // the admin explaining why. Same rule as the two DataIntegrity sweeps.
        if ($this->db->query('UPDATE libri SET is_desiderata = 0' . \App\Support\BookVisibility::catalogueStamp($this->db) . ' WHERE is_desiderata = 1') === false) {
            $this->fail('cannot clear the desiderata flag on uninstall');
        }
        ContentCache::deferBooksChanged();
    }
    /**
     * Idempotent, and loud when a step fails.
     *
     * Returning silently would leave the plugin active with a half-built
     * schema: every homepage render would then hit a failed prepare, the
     * section would vanish with only a line in the error log, and the public
     * page would answer 500. mysqli's strict reporting is not enough to rely
     * on — BackupManager turns it off process-wide while it runs.
     */
    public function ensureSchema(): void
    {
        $columns = $this->db->query("SHOW COLUMNS FROM libri LIKE 'is\\_desiderata'");
        if ($columns === false) {
            $this->fail('cannot inspect libri for is_desiderata');
        }
        if ($columns->num_rows === 0) {
            // Two statements, not one: adding the column is INSTANT (metadata
            // only), while building the index rewrites a secondary index over
            // the whole table. Splitting them means a large catalogue gets the
            // column immediately and only pays for the index afterwards — and
            // if the index fails, the column is already in place and usable.
            if ($this->db->query('ALTER TABLE libri ADD COLUMN is_desiderata TINYINT(1) NOT NULL DEFAULT 0') === false) {
                $this->fail('cannot add libri.is_desiderata');
            }
        }
        // CI-SOFT-DELETE-EXEMPT: schema introspection, not a read of book rows.
        $index = $this->db->query("SHOW INDEX FROM libri WHERE Key_name = 'idx_desiderata'");
        if ($index === false) {
            $this->fail('cannot inspect the indexes of libri');
        }
        if ($index->num_rows === 0 && $this->db->query('ALTER TABLE libri ADD INDEX idx_desiderata (is_desiderata, deleted_at)') === false) {
            $this->fail('cannot add the idx_desiderata index');
        }
        // "Was this row ever in the public catalogue?" — written once, never
        // cleared. The OAI-PMH de-listing arm needs it because is_desiderata
        // alone cannot answer it: a row reaches 1 either by being WITHDRAWN
        // from the catalogue (a real deletion its harvesters must be told
        // about) or by being BORN as a request (which no harvester ever saw),
        // and once the flag is set nothing distinguishes the two — the copies
        // are hard-deleted, so there is no history to consult. Without this the
        // arm published a status=deleted header for wishes that were never
        // published in the first place.
        //
        // The predicate is "was ever is_desiderata = 0", not "ever had copies":
        // the ACTIVE arm filters on is_desiderata alone, so a record with no
        // copies is harvested just the same, and tying the stamp to copies
        // would stop a genuinely withdrawn copy-less record from tombstoning.
        // CI-SOFT-DELETE-EXEMPT: schema introspection, not a read of book rows.
        $catalogued = $this->db->query("SHOW COLUMNS FROM libri LIKE 'catalogued\\_at'");
        if ($catalogued === false) {
            $this->fail('cannot inspect libri for catalogued_at');
        }
        if ($catalogued->num_rows === 0) {
            if ($this->db->query('ALTER TABLE libri ADD COLUMN catalogued_at DATETIME NULL DEFAULT NULL') === false) {
                $this->fail('cannot add libri.catalogued_at');
            }
            // Backfill, once, on the transition — and only in the direction we
            // can actually know. A row sitting at is_desiderata = 0 right now
            // IS in the catalogue, so it is stamped. A row already flagged
            // cannot be judged either way, so it stays NULL and will never
            // tombstone: the existing wish list loses tombstones it should
            // arguably have had, which is the harmless half of the trade, while
            // guessing the other way would keep publishing deletions for
            // records no harvester ever received.
            // NOW(), not the row's own created_at/updated_at: those belong to
            // core's libri, not to this plugin, and reading them here would make
            // the schema step fail on any table that does not carry them. The
            // value is never compared to anything — everCatalogued() asks only
            // IS NOT NULL — so "stamped when the column arrived" is as good as a
            // reconstructed date, and it does not pretend to a precision it
            // cannot have.
            // CI-SOFT-DELETE-EXEMPT: deliberately stamps archived rows too — a
            // restored book was in the catalogue before it was deleted.
            if ($this->db->query('UPDATE libri SET catalogued_at = NOW() WHERE is_desiderata = 0 AND catalogued_at IS NULL') === false) {
                $this->fail('cannot backfill libri.catalogued_at');
            }
        }
        // No hard FK to core tables: installations may use different integer types.
        if ($this->db->query("CREATE TABLE IF NOT EXISTS desiderata_offers (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            book_id INT NULL, received_book_id INT NULL, copy_id INT NULL,
            donor_name VARCHAR(150) NOT NULL, donor_email VARCHAR(254) NOT NULL,
            title VARCHAR(255) NOT NULL, author VARCHAR(255) NOT NULL DEFAULT '',
            publisher VARCHAR(255) NOT NULL DEFAULT '', isbn VARCHAR(20) NOT NULL DEFAULT '',
            notes TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, received_at DATETIME NULL,
            INDEX (status, created_at), INDEX (book_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci") === false) {
            $this->fail('cannot create desiderata_offers');
        }
    }
    /** @return never */
    private function fail(string $what): void
    {
        $message = '[Desiderata] ' . $what . ': ' . $this->db->error;
        \App\Support\SecureLogger::error($message);
        throw new RuntimeException($message);
    }
    /**
     * Every hook this plugin answers, in one place so activation and the
     * upgrade path register exactly the same set.
     *
     * `frontend.home.section` fires inside the ordered loop of the homepage
     * template, so the section obeys the display_order and the visibility the
     * operator sets in /admin/cms/home like any core section; the three
     * `cms.home.*` hooks are what put its card in that editor.
     *
     * `book.save.after` is the other half of `book.form.save`: clearing the
     * checkbox on an existing request is the operator saying the book arrived,
     * and the copies that makes real cannot be created from a field filter.
     * Core fires it after its own transaction has committed, precisely so a
     * handler can open one of its own.
     *
     * `admin.dashboard.sections` is where the two operator panels go — the
     * books still being looked for and the proposals waiting for a verdict —
     * so the feature is visible on the page an operator opens first.
     *
     * `book.visibility.discoverable` is what makes a wanted title findable by
     * name and reachable by link while the plugin is on, and `book.frontend.details`
     * puts the donation form on that page. Both disappear with the hook rows on
     * deactivation, which is the whole guarantee that the feature leaves no
     * trace on the public site when it is off.
     */
    private const HOOKS = [
        'app.routes.register' => 'registerRoutes',
        'admin.menu.render' => 'menu',
        'book.form.before_copies' => 'bookField',
        'book.form.save' => 'prepareBook',
        'book.save.after' => 'bookSaved',
        'frontend.home.section' => 'renderHomeSection',
        'cms.home.section.fields' => 'cmsFields',
        'cms.home.section_name' => 'cmsSectionName',
        'cms.home.save' => 'cmsSave',
        'admin.dashboard.sections' => 'dashboard',
        'book.frontend.details' => 'bookDetail',
        'book.visibility.discoverable' => 'discoverable',
    ];
    /** The home_content row this plugin owns; nothing else may write it. */
    private const HOME_SECTION_KEY = 'desiderata';
    /**
     * The plugin's three public paths, in one place.
     *
     * They were spelled out as literals in seven spots across five files — the
     * route registrations, the redirect fallback in offer(), the pager, the
     * "see them all" link, the noscript link, the search endpoint in the JS
     * assets and the form action — which is six chances for a rename to land
     * half-done, and to land silently, since a stale literal is a valid string
     * that simply 404s at the wrong moment.
     *
     * Deliberately NOT routed through route_path()/RouteTranslator. That
     * mechanism reads a fixed key table in locale/routes_*.json which a plugin
     * cannot extend, so using it would mean adding keys to five shared core
     * files for one optional plugin — and a partial edit there misroutes
     * non-English installations without saying so. Thirteen of the fourteen
     * other bundled plugins with public routes register literals for the same
     * reason. CLAUDE.md rule 4 governs locale-varying core user routes and does
     * not reach this case.
     */
    /**
     * The query marker that tells the landing page a proposal was just
     * sent. See thankYouUrl() for why the session flash cannot carry this
     * on its own.
     */
    public const THANK_YOU_MARKER = 'inviata';
    public const PATH_PUBLIC = '/desiderata';
    public const PATH_SEARCH = self::PATH_PUBLIC . '/search';
    public const PATH_OFFERS = self::PATH_PUBLIC . '/offers';
    /**
     * Idempotent: PluginManager re-runs onActivate() on an already-active
     * bundled plugin whenever plugin.json carries a newer version, which is the
     * only way an existing installation ever receives new hook rows. Routing
     * that path through onDeactivate() — as this method used to — would
     * snapshot and delete the operator's home section on every single upgrade.
     */
    public function onActivate(): void
    {
        // Checked before the DDL: an ALTER commits implicitly, so failing after
        // it would leave the schema changed by an activation that never happened.
        if ($this->pluginId === null) { throw new RuntimeException('Missing plugin ID'); }
        $this->ensureSchema();
        $this->db->begin_transaction();
        try {
            $this->unregisterHooks();
            foreach (self::HOOKS as $hook => $method) {
                $class = self::class;
                $stmt = $this->db->prepare('INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active) VALUES (?, ?, ?, ?, 10, 1)');
                $stmt->bind_param('isss', $this->pluginId, $hook, $class, $method);
                $stmt->execute();
                $stmt->close();
            }
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
        $this->ensureHomeSection();
        $this->invalidate();
    }
    public function onDeactivate(): void
    {
        $this->unregisterHooks();
        $this->removeHomeSection();
        ContentCache::deferBooksChanged();
    }
    /**
     * Drops every hook row of this plugin. Deactivation is what makes the
     * public predicates and the CMS card disappear, so it must run on its own
     * — and re-activation must be able to rebuild the set from scratch.
     */
    private function unregisterHooks(): void
    {
        $pluginId = $this->pluginIdByName();
        if ($pluginId <= 0) { return; }
        $stmt = $this->db->prepare('DELETE FROM plugin_hooks WHERE plugin_id = ?');
        if ($stmt === false) { $this->fail('cannot prepare the hook cleanup'); }
        $stmt->bind_param('i', $pluginId);
        $stmt->execute();
        $stmt->close();
    }
    /**
     * Creates the home_content row the ordered loop renders through, without
     * ever touching one that is already there: the position and the visibility
     * belong to the operator, and an upgrade must not reset them.
     *
     * The row carries no text (decision A: texts are per-locale overrides in
     * plugin_settings); `title` is a constant nothing reads, because the CMS
     * list label comes from the cms.home.section_name filter.
     */
    private function ensureHomeSection(): void
    {
        $existing = $this->db->query("SELECT id FROM home_content WHERE section_key = '" . self::HOME_SECTION_KEY . "' LIMIT 1");
        if ($existing === false) { $this->fail('cannot inspect home_content'); }
        if ($existing->num_rows === 0) {
            $isActive = 1;
            $order = null;
            $snapshot = json_decode((string) $this->readSetting('home_section_state'), true);
            if (is_array($snapshot)) {
                if (isset($snapshot['is_active'])) { $isActive = (int) $snapshot['is_active'] === 0 ? 0 : 1; }
                if (isset($snapshot['display_order']) && is_numeric($snapshot['display_order'])) { $order = (int) $snapshot['display_order']; }
            }
            if ($order === null) {
                $next = $this->db->query('SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order FROM home_content');
                if ($next === false) { $this->fail('cannot read the home section order'); }
                $order = (int) ($next->fetch_assoc()['next_order'] ?? 1);
            }
            $key = self::HOME_SECTION_KEY;
            $title = 'Desiderata';
            $stmt = $this->db->prepare('INSERT INTO home_content (section_key, title, is_active, display_order) VALUES (?, ?, ?, ?)');
            if ($stmt === false) { $this->fail('cannot prepare the home section insert'); }
            $stmt->bind_param('ssii', $key, $title, $isActive, $order);
            if (!$stmt->execute()) { $stmt->close(); $this->fail('cannot create the home section row'); }
            $stmt->close();
        }
        // The homepage dataset is cached under home_page_data_v1: without this
        // the section list stays stale and the feature looks broken.
        ContentCache::homeContentChanged();
    }
    /**
     * Takes the row away with the plugin (rule 8: nothing of ours survives a
     * deactivation) after remembering where the operator had put it, so a
     * re-activation restores the same position and visibility.
     */
    private function removeHomeSection(): void
    {
        $row = $this->db->query("SELECT is_active, display_order FROM home_content WHERE section_key = '" . self::HOME_SECTION_KEY . "' LIMIT 1");
        if ($row !== false && ($state = $row->fetch_assoc()) !== null) {
            try {
                $this->writeSetting('home_section_state', (string) json_encode([
                    'is_active' => (int) ($state['is_active'] ?? 1),
                    'display_order' => (int) ($state['display_order'] ?? 0),
                ]));
            } catch (Throwable $e) {
                // Losing the snapshot costs the operator a re-ordering, while
                // refusing to deactivate would leave the plugin half off.
                \App\Support\SecureLogger::error('[Desiderata] cannot snapshot the home section: ' . $e->getMessage());
            }
            $this->db->query("DELETE FROM home_content WHERE section_key = '" . self::HOME_SECTION_KEY . "'");
        }
        ContentCache::homeContentChanged();
    }
    /**
     * HookManager builds a fresh plugin object for every hook call and never
     * calls setPluginId(), so render-time code cannot read $this->pluginId.
     * Resolving by name (memoised per process) is what keeps the settings
     * helpers usable from inside a hook.
     */
    private function pluginIdByName(): int
    {
        if ($this->pluginId !== null) { return $this->pluginId; }
        if (self::$resolvedPluginId !== null) { return self::$resolvedPluginId; }
        $id = 0;
        $result = $this->db->query("SELECT id FROM plugins WHERE name = 'desiderata' LIMIT 1");
        if ($result !== false && ($row = $result->fetch_assoc()) !== null) { $id = (int) $row['id']; }
        // A zero is never cached: the row exists as soon as the plugin is
        // registered, and memoising "missing" would outlive that.
        if ($id > 0) { self::$resolvedPluginId = $id; }
        return $id;
    }
    private function readSetting(string $key): ?string
    {
        $pluginId = $this->pluginIdByName();
        if ($pluginId <= 0) { return null; }
        $stmt = $this->db->prepare('SELECT setting_value FROM plugin_settings WHERE plugin_id = ? AND setting_key = ? LIMIT 1');
        if ($stmt === false) { return null; }
        $stmt->bind_param('is', $pluginId, $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row === null ? null : (string) ($row['setting_value'] ?? '');
    }
    private function writeSetting(string $key, string $value): void
    {
        $pluginId = $this->pluginIdByName();
        if ($pluginId <= 0) { throw new RuntimeException('[Desiderata] plugin row not found'); }
        $stmt = $this->db->prepare('INSERT INTO plugin_settings (plugin_id, setting_key, setting_value, autoload, created_at) VALUES (?, ?, ?, 0, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()');
        if ($stmt === false) { throw new RuntimeException('[Desiderata] ' . $this->db->error); }
        $stmt->bind_param('iss', $pluginId, $key, $value);
        if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException('[Desiderata] ' . $error); }
        $stmt->close();
    }
    private function invalidate(): void
    {
        ContentCache::booksChanged();
    }
    public function menu(): void
    {
        // The pill is the only place an operator learns there is something to
        // read without opening the page, so it counts proposals nobody has
        // judged yet — not accepted ones, which are already being handled.
        $pending = $this->pendingOfferCount();
        $badge = $pending > 0
            ? '<span class="ml-auto text-xs font-bold px-2 py-0.5 rounded-full bg-blue-500 text-white">' . $pending . '</span>'
            : '';
        echo '<a class="nav-link group flex items-center px-4 py-3 rounded-lg text-gray-700 hover:bg-gray-100" href="' . self::e(url('/admin/desiderata')) . '"><i class="fas fa-hand-holding-heart mr-3" aria-hidden="true"></i>' . self::e(__('Desiderata e donazioni')) . $badge . '</a>';
    }
    /**
     * Proposals waiting for a verdict.
     *
     * Deliberately quiet on failure: this number decorates a menu entry that
     * renders on every admin page, and a missing table (a half-finished
     * activation) must not take the whole back office down for a badge.
     */
    private function pendingOfferCount(): int
    {
        try {
            $result = $this->db->query("SELECT COUNT(*) AS n FROM desiderata_offers WHERE status = 'pending'");
            if ($result === false) { return 0; }
            return (int) ($result->fetch_assoc()['n'] ?? 0);
        } catch (Throwable $e) {
            \App\Support\SecureLogger::error('[Desiderata] pending count failed: ' . $e->getMessage());
            return 0;
        }
    }
    public static function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
    /**
     * The body of an `onclick` confirmation, built the one way that is safe
     * there: json_encode with the HEX flags, never htmlspecialchars of a JS
     * string — the attribute is HTML-decoded before the script is parsed, so an
     * entity-escaped quote would break straight back out into the code. The
     * result still goes through e() when it is printed into the attribute.
     */
    public static function confirmJs(string $text): string
    {
        return 'return confirm(' . json_encode($text, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) . ');';
    }
    public function bookField(?array $book, ?int $id): void
    {
        $hasCopies = false;
        if ($id) {
            $stmt = $this->db->prepare('SELECT id FROM copie WHERE libro_id = ? LIMIT 1');
            $stmt->bind_param('i', $id); $stmt->execute();
            $hasCopies = $stmt->get_result()->num_rows > 0;
        }
        require __DIR__ . '/views/book-field.php';
    }
    /**
     * HookManager swallows anything a filter throws and keeps the unfiltered
     * value, so an exception here would silently publish the record: the flag
     * would stay unset (stored as 0) while the form's disabled copies field
     * sends nothing — an ordinary, publicly visible book with zero copies.
     * The failure path therefore keeps the operator's request instead.
     */
    public function prepareBook(array $fields, array $input, ?int $id): array
    {
        if (!isset($input['desiderata_form'])) { return $fields; }
        $wanted = ($input['is_desiderata'] ?? '') === '1';
        if ($id && $wanted) {
            try {
                $stmt = $this->db->prepare('SELECT id FROM copie WHERE libro_id = ? LIMIT 1');
                if ($stmt === false) { throw new RuntimeException($this->db->error); }
                $stmt->bind_param('i', $id); $stmt->execute();
                $wanted = $stmt->get_result()->num_rows === 0;
                $stmt->close();
            } catch (Throwable $e) {
                // Cannot tell whether copies exist: honour the request rather
                // than publishing a book the library does not own.
                \App\Support\SecureLogger::error('[Desiderata] copy probe failed: ' . $e->getMessage());
                $wanted = true;
            }
        }
        $fields['is_desiderata'] = $wanted ? 1 : 0;
        // Zeroed on BOTH paths: ticking the box after typing a number must
        // never record those copies, and the edit form's own copy field is
        // read-only by core design. A request owns nothing, by definition.
        if ($wanted) { $fields['copie_totali'] = $fields['copie_disponibili'] = 0; }
        if ($id !== null) {
            unset(self::$pendingReceipt[$id]);
            if (!$wanted) {
                // Preserve the stored flag until copies and receipt commit together.
                // Also fail closed if the intent lookup is temporarily unavailable.
                if (($input['is_desiderata'] ?? '') !== '1') { unset($fields['is_desiderata']); }
                $this->rememberReceipt($id, $input);
            }
        }
        return $fields;
    }
    /**
     * Books whose request is being closed by this very save, and how many
     * physical copies the operator asked to register.
     *
     * Static because HookManager builds a fresh plugin object for every hook
     * call: `book.form.save` and `book.save.after` are two of them, and this is
     * the only thing that survives between the two. It has to be captured in
     * the first one — the second hook completes the receipt after metadata has been saved.
     *
     * @var array<int,int>
     */
    private static array $pendingReceipt = [];
    /**
     * How many copies the operator asked for, or one.
     *
     * The upper bound is not defensive decoration: this number drives a loop of
     * real inventory rows, and a typo of six digits in a number field would
     * otherwise be a denial of service against the copy allocator.
     *
     * @param array<string,mixed> $input
     */
    private static function copiesRequested(array $input): int
    {
        $raw = $input['desiderata_copies'] ?? '';
        if (!is_scalar($raw) || !ctype_digit(trim((string) $raw))) { return 1; }
        return max(1, min(50, (int) trim((string) $raw)));
    }
    /**
     * Remembers that this save turns an open request into a holding.
     *
     * Read BEFORE the update to capture the operator's intent independently
     * of subsequent hooks. An ordinary edit of a book that never was a
     * request must not register a receipt — and creating copies for the latter would invent stock nobody
     * owns. The "no copies yet" condition is the other half: a record that
     * already has holdings is not a request being closed.
     *
     * @param array<string,mixed> $input
     */
    private function rememberReceipt(int $id, array $input): void
    {
        if (!isset($input['desiderata_form'])) { return; }
        try {
            $stmt = $this->db->prepare('SELECT is_desiderata FROM libri l WHERE l.id = ? AND l.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM copie c WHERE c.libro_id = l.id)');
            if ($stmt === false) { throw new RuntimeException($this->db->error); }
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row === null || (int) ($row['is_desiderata'] ?? 0) !== 1) { return; }
            self::$pendingReceipt[$id] = self::copiesRequested($input);
        } catch (Throwable $e) {
            $_SESSION['error_message'] = __('Operazione non riuscita. Nessuna copia è stata registrata.');
            \App\Support\SecureLogger::error('[Desiderata] receipt intent probe failed: ' . $e->getMessage());
        }
    }
    /**
     * Complete receipt after the metadata save, retaining the flag on failure.
     * Core has already committed the metadata: handle failures here and report
     * them through the existing flash channel instead of throwing over a saved
     * record. Copies, the flag and the receipt audit share a separate transaction.
     */
    public function bookSaved(mixed $id = null, mixed $fields = null): void
    {
        $bookId = is_numeric($id) ? (int) $id : 0;
        if ($bookId <= 0) { return; }
        $howMany = self::$pendingReceipt[$bookId] ?? 0;
        unset(self::$pendingReceipt[$bookId]);
        if ($howMany <= 0) { return; }
        try {
            $this->registerCopies($bookId, $howMany);
            $this->invalidate();
        } catch (Throwable $e) {
            $_SESSION['error_message'] = $e instanceof InvalidArgumentException
                ? $e->getMessage()
                : __('Operazione non riuscita. Nessuna copia è stata registrata.');
            \App\Support\SecureLogger::error('[Desiderata] receipt on save failed: ' . $e->getMessage());
        }
    }
    /**
     * The arrival of a donated book, written the same way receiveDirect() does
     * it: one transaction, copies through the inventory-code allocator, the
     * flag cleared explicitly and an audit row in desiderata_offers.
     *
     * The audit row is deliberate. Without it the library's record of how a
     * wanted book entered the catalogue would depend on which of the two
     * buttons the operator happened to press, and the received history would
     * have holes nobody could explain later.
     *
     * The flag and the absence of copies are checked under the book lock.
     */
    private function registerCopies(int $bookId, int $howMany): void
    {
        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare('SELECT titolo FROM libri l WHERE l.id = ? AND l.deleted_at IS NULL AND l.is_desiderata = 1 AND NOT EXISTS (SELECT 1 FROM copie c WHERE c.libro_id = l.id) FOR UPDATE');
            if ($stmt === false) { throw new RuntimeException($this->db->error); }
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $book = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$book) { $this->db->rollback(); return; }
            $title = (string) ($book['titolo'] ?? '');
            $this->assertNoOpenOffers($bookId);
            $ids = (new \App\Models\CopyRepository($this->db))
                ->createManyForBookWithIdsAndNote($bookId, 'LIB-' . $bookId, $howMany, 'disponibile', 'Donazione diretta');
            if (count($ids) !== $howMany) { throw new RuntimeException('Copy creation failed'); }
            $copyId = $ids[0];
            $stmt = $this->db->prepare('UPDATE libri SET is_desiderata = 0' . \App\Support\BookVisibility::catalogueStamp($this->db) . ' WHERE id = ? AND deleted_at IS NULL');
            if ($stmt === false) { throw new RuntimeException($this->db->error); }
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $stmt->close();
            if (!(new \App\Support\DataIntegrity($this->db))->recalculateBookAvailability($bookId, true, true)) {
                throw new RuntimeException('Availability update failed');
            }
            $stmt = $this->db->prepare("INSERT INTO desiderata_offers (book_id, received_book_id, copy_id, donor_name, donor_email, title, notes, status, received_at) VALUES (?, ?, ?, '', '', ?, '', 'received', NOW())");
            if ($stmt === false) { throw new RuntimeException($this->db->error); }
            $stmt->bind_param('iiis', $bookId, $bookId, $copyId, $title);
            $stmt->execute();
            $offerId = (int) $this->db->insert_id;
            $stmt->close();
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        $this->notifyReceipt($bookId, $offerId);
    }
    public function registerRoutes($app): void
    {
        $plugin = $this;
        $csrf = new \App\Middleware\CsrfMiddleware();
        $admin = new \App\Middleware\AdminAuthMiddleware($this->db);
        $app->get(self::PATH_PUBLIC, fn(Request $q, Response $r) => $plugin->page($r, [], 200, self::pageNumber($q->getQueryParams(), 'page')));
        $app->get(self::PATH_SEARCH, fn(Request $q, Response $r) => $plugin->search($q, $r))->add(new \App\Middleware\RateLimitMiddleware(90, 60, 'desiderata-search'));
        // Ordering matters: Slim runs the LAST added first, so the rate limiter
        // still throttles a flood, and confirmFirstContact() then sits between it
        // and the CSRF guard — close enough to intercept the sessionless first
        // contact, far enough that it cannot be used to bypass throttling.
        $app->post(self::PATH_OFFERS, fn(Request $q, Response $r) => $plugin->offer($q, $r))
            ->add($csrf)
            ->add(fn(Request $q, $handler) => $plugin->confirmFirstContact($q, $handler))
            ->add(new \App\Middleware\RateLimitMiddleware(5, 900, 'desiderata-offer'));
        $app->get('/admin/desiderata/books', fn(Request $q, Response $r) => $plugin->catalogueSearch($q, $r))->add($admin);
        $app->get('/admin/desiderata', fn(Request $q, Response $r) => $plugin->admin($q, $r))->add($admin);
        $app->post('/admin/desiderata/offers/{id:[0-9]+}', fn(Request $q, Response $r, array $a) => $plugin->manage($q, $r, (int)$a['id']))->add($csrf)->add($admin);
        // Same middleware chain as manage(): this one creates an inventory copy
        // too, it just does it without a proposal behind it.
        $app->post('/admin/desiderata/books/{id:[0-9]+}/received', fn(Request $q, Response $r, array $a) => $plugin->receiveDirect($q, $r, (int)$a['id']))->add($csrf)->add($admin);
    }
    /**
     * True when this installation has the multi-publisher junction table.
     *
     * Memoised per process: schema.sql ships `libri_editori` (issue #143), but
     * BookRepository::find() still probes for it, so installations upgraded
     * from before that change may not have it — and a query naming a missing
     * table fails outright instead of simply matching nothing.
     */
    private function hasPublisherJunction(): bool
    {
        if (self::$publisherJunction === null) {
            // CI-SOFT-DELETE-EXEMPT: schema introspection, not a read of book rows.
            $result = $this->db->query("SHOW TABLES LIKE 'libri\\_editori'");
            self::$publisherJunction = $result !== false && $result->num_rows > 0;
        }
        return self::$publisherJunction;
    }
    /** @see hasPublisherJunction() */
    private static ?bool $publisherJunction = null;
    /**
     * The open requests, optionally filtered by what a visitor typed.
     *
     * The term is matched against the title, both ISBNs, the authors AND the
     * publisher — a reader who remembers "that Einaudi book" must find it. The
     * publisher lives in two places (libri.editore_id, and the libri_editori
     * junction for multi-publisher records), so both are searched whenever the
     * junction exists.
     *
     * `cover` and `url` are resolved here, once, instead of in each of the three
     * renderers: the JSON the search endpoint returns is consumed by JavaScript
     * that has no business knowing the installation's base path, and a row built
     * server-side must be indistinguishable from a row built by the script — the
     * two drift apart the moment either of them computes its own links.
     *
     * `url` is the canonical book URL, so `autore_principale_nome` is selected
     * beside the GROUP_CONCAT of every author: book_path() slugs THAT key first,
     * and feeding it the concatenation would put "natalia-ginzburg-cesare-pavese"
     * where the catalogue puts "natalia-ginzburg".
     *
     * @return list<array<string,mixed>>
     */
    public function wanted(string $term = '', int $limit = 12, int $offset = 0): array
    {
        $offset = max(0, $offset);
        $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
        $junction = $this->hasPublisherJunction();
        $byJunction = $junction
            ? " OR EXISTS (SELECT 1 FROM libri_editori le JOIN editori e2 ON e2.id=le.editore_id WHERE le.libro_id=l.id AND e2.nome LIKE ? ESCAPE '!')"
            : '';
        $stmt = $this->db->prepare("SELECT l.id, l.titolo, l.isbn13, l.isbn10, l.copertina_url, e.nome AS editore,
            (SELECT GROUP_CONCAT(a.nome SEPARATOR ', ') FROM libri_autori la JOIN autori a ON a.id=la.autore_id WHERE la.libro_id=l.id) AS autore,
            (SELECT a.nome FROM libri_autori la JOIN autori a ON a.id=la.autore_id
             WHERE la.libro_id=l.id AND la.ruolo IN ('principale','co-autore')
             ORDER BY CASE la.ruolo WHEN 'principale' THEN 0 ELSE 1 END, la.ordine_credito LIMIT 1) AS autore_principale_nome
            FROM libri l LEFT JOIN editori e ON e.id=l.editore_id
            WHERE l.deleted_at IS NULL AND l.is_desiderata=1
            AND NOT EXISTS (SELECT 1 FROM copie c WHERE c.libro_id=l.id)
            AND (l.titolo LIKE ? ESCAPE '!' OR l.isbn13 LIKE ? ESCAPE '!' OR l.isbn10 LIKE ? ESCAPE '!'
            OR EXISTS (SELECT 1 FROM libri_autori la JOIN autori a ON a.id=la.autore_id WHERE la.libro_id=l.id AND a.nome LIKE ? ESCAPE '!')
            OR e.nome LIKE ? ESCAPE '!'" . $byJunction . ")
            ORDER BY l.id DESC LIMIT ? OFFSET ?");
        if ($stmt === false) { $this->fail('cannot prepare the desiderata search'); }
        // Spelled out per branch on purpose: the type string and the argument
        // list have to agree with the placeholder count of the SQL actually
        // built above, and a mismatch is a runtime error with no compile-time
        // warning of any kind.
        if ($junction) {
            $stmt->bind_param('ssssssii', $term, $term, $term, $term, $term, $term, $limit, $offset);
        } else {
            $stmt->bind_param('sssssii', $term, $term, $term, $term, $term, $limit, $offset);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $index => $row) {
            $cover = is_string($row['copertina_url'] ?? null) ? $row['copertina_url'] : '';
            $rows[$index]['cover'] = url($cover !== '' ? $cover : self::PLACEHOLDER_COVER);
            $rows[$index]['url'] = book_url($row);
        }
        return $rows;
    }
    /** How many requests one public page shows. Matches wanted()'s own default. */
    public const PUBLIC_PAGE_SIZE = 12;
    /** The cover shown for a request nobody has photographed yet. */
    public const PLACEHOLDER_COVER = '/uploads/copertine/placeholder.jpg';
    /**
     * Rendered from inside the ordered loop of the homepage template, so the
     * operator's display_order and visibility apply. The guard matters: the
     * action fires for every row without a core template, plugin-owned or not.
     *
     * No pager here, on purpose: this section is one block of a longer page, so
     * a page link would carry the reader away from everything else the homepage
     * says. The overflow is a link to /desiderata instead, which is the surface
     * that paginates — and the one the <noscript> fallback already points at.
     */
    public function renderHomeSection(string $sectionKey, array $section): void
    {
        if ($sectionKey !== self::HOME_SECTION_KEY) { return; }
        $books = $this->wanted('', self::PUBLIC_PAGE_SIZE);
        $wantedTotal = $this->wantedCount();
        $pager = null;
        $texts = $this->texts(\App\Support\I18n::getLocale());
        $recaptchaSiteKey = self::recaptchaSiteKey();
        require __DIR__ . '/views/public.php';
    }
    /**
     * The standalone /desiderata page, which is where the whole list lives.
     *
     * `$page` comes from the query string, so it is clamped to what actually
     * exists: a hand-typed ?page=9999 shows the last page rather than an empty
     * list with no way back. The controls are ordinary links — the <noscript>
     * fallback promises this page works, and a pager that needs JavaScript
     * would quietly break that promise.
     */
    public function page(Response $r, array $data = [], int $status = 200, int $page = 1): Response
    {
        $wantedTotal = $this->wantedCount();
        $pages = max(1, (int) ceil($wantedTotal / self::PUBLIC_PAGE_SIZE));
        $page = min(max(1, $page), $pages);
        $books = $this->wanted('', self::PUBLIC_PAGE_SIZE, ($page - 1) * self::PUBLIC_PAGE_SIZE);
        $texts = $this->texts(\App\Support\I18n::getLocale());
        return $this->render($r, 'public', $data + [
            'books' => $books,
            'standalone' => true,
            'texts' => $texts,
            'wantedTotal' => $wantedTotal,
            'pager' => ['page' => $page, 'pages' => $pages, 'total' => $wantedTotal],
            'recaptchaSiteKey' => self::recaptchaSiteKey(),
        ], false)->withStatus($status);
    }
    /**
     * The donation form reuses the contact form's reCAPTCHA keys: one pair of
     * keys per installation is what an operator expects, and a second pair in a
     * plugin settings screen would be one more thing to get wrong. Empty here
     * means no reCAPTCHA anywhere, exactly as on the contact form.
     */
    private static function recaptchaSiteKey(): string
    {
        $config = \App\Support\ConfigStore::get('contacts', []);
        $key = is_array($config) ? ($config['recaptcha_site_key'] ?? '') : '';
        return is_string($key) ? $key : '';
    }
    /**
     * The donation form on the public page of a book the library is looking
     * for (core fires `book.frontend.details` with [$book, $id]).
     *
     * Both arguments come straight from a core view with no validation in
     * between, so neither type is guaranteed: a filter/action handler that
     * fatals on an unexpected shape would take down the whole book page, and
     * HookManager would log it as a hook error with the page already half sent.
     *
     * @param array<string,mixed>|mixed $book
     * @param mixed $id
     */
    public function bookDetail(mixed $book = null, mixed $id = null): void
    {
        if (!is_array($book) || empty($book['is_desiderata'])) { return; }
        $bookId = is_numeric($id) ? (int) $id : (int) ($book['id'] ?? 0);
        if ($bookId <= 0) { return; }
        $texts = $this->texts(\App\Support\I18n::getLocale());
        // Exactly what the homepage fills in when a visitor presses "I have it":
        // title, author, publisher and ISBN, with only the title locked. Asking
        // a donor to retype three things the catalogue already holds is how a
        // form gets abandoned.
        $values = $this->donationPrefill($bookId) + [
            'book_id' => (string) $bookId,
            'title' => is_scalar($book['titolo'] ?? null) ? (string) $book['titolo'] : '',
        ];
        // Where a successful proposal sends the donor back: the page they were
        // reading, not the generic desiderata list.
        $returnTo = book_path($book + ['id' => $bookId]);
        $recaptchaSiteKey = self::recaptchaSiteKey();
        require __DIR__ . '/views/book-detail.php';
    }
    /**
     * Author, publisher and ISBN for the form bound to one wanted book.
     *
     * Read here rather than picked out of the book-detail DTO the hook hands
     * over: that array carries `autore_principale` (the FIRST author only) while
     * the homepage row carries every author, so trusting it would give a
     * two-author book one author on its own page and two on the list. The
     * expressions below are wanted()'s, so the two surfaces agree by
     * construction. A failed read simply leaves the fields empty and editable.
     *
     * @return array<string,string>
     */
    private function donationPrefill(int $bookId): array
    {
        $stmt = $this->db->prepare("SELECT l.isbn13, l.isbn10, e.nome AS editore,
            (SELECT GROUP_CONCAT(a.nome SEPARATOR ', ') FROM libri_autori la JOIN autori a ON a.id=la.autore_id WHERE la.libro_id=l.id) AS autore
            FROM libri l LEFT JOIN editori e ON e.id=l.editore_id
            WHERE l.id = ? AND l.deleted_at IS NULL LIMIT 1");
        if ($stmt === false) { return []; }
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) { return []; }
        $isbn13 = is_string($row['isbn13'] ?? null) ? trim($row['isbn13']) : '';
        $isbn10 = is_string($row['isbn10'] ?? null) ? trim($row['isbn10']) : '';
        return [
            'author' => is_string($row['autore'] ?? null) ? $row['autore'] : '',
            'publisher' => is_string($row['editore'] ?? null) ? $row['editore'] : '',
            'isbn' => $isbn13 !== '' ? $isbn13 : $isbn10,
        ];
    }
    /**
     * Widens BookVisibility::discoverable() to everything while the plugin is
     * active, so a visitor who searches for a title the library is looking for
     * finds it (badged by core) and can open its page to offer a copy.
     *
     * Deliberately unconditional and deliberately `'1=1'`: core accepts that
     * one string and discards anything else, so this hook can only ever open
     * search and the detail page — never inject SQL, never narrow a predicate
     * someone else depends on. Browse, feeds, sitemap and the interop
     * protocols do not consult this filter at all.
     *
     * The arguments are whatever core passed; none of them is needed and none
     * is trusted (a filter handler must not fatal on an unexpected shape).
     *
     * @param mixed $predicate the catalogue predicate this widens
     */
    public function discoverable(mixed $predicate = null, mixed $db = null, mixed $alias = null): string
    {
        return '1=1';
    }
    /**
     * Maximum length accepted for each operator-editable text, per locale.
     * The view uses them as `maxlength`, cmsSave() enforces them server-side.
     */
    public const TEXT_FIELDS = ['eyebrow' => 120, 'title' => 160, 'intro' => 600, 'form_title' => 160, 'form_intro' => 600, 'button' => 80];
    /**
     * What the section says when the operator has written nothing: the shipped
     * strings, translated like the rest of the interface. Same precedent as
     * home-sections/hero.php, which falls back to __() for an empty CMS field.
     *
     * @return array<string,string>
     */
    public function textDefaults(): array
    {
        return [
            'eyebrow' => __('Cresciamo insieme'),
            'title' => __('I libri che cerchiamo'),
            'intro' => __('Aiutaci ad arricchire la biblioteca. Puoi offrire un libro richiesto oppure proporre un altro titolo.'),
            'form_title' => __('Proponi una donazione'),
            // What the donor needs and nothing else. The sentence that used to
            // follow ("sending it adds no books or copies to the catalogue")
            // described the plugin's own bookkeeping to somebody who is simply
            // offering a book, and the old key stays in the catalogue so an
            // operator who copied it into an override keeps their text.
            'form_intro' => __('La biblioteca valuterà la proposta e ti contatterà per concordare la consegna.'),
            'button' => __('Invia la proposta'),
        ];
    }
    /** @return array<string,string> */
    public function textFieldLabels(): array
    {
        return [
            'eyebrow' => __('Occhiello'),
            'title' => __('Titolo della sezione'),
            'intro' => __('Introduzione'),
            'form_title' => __('Titolo del modulo'),
            'form_intro' => __('Testo del modulo'),
            'button' => __('Etichetta del pulsante'),
        ];
    }
    /**
     * The texts to render for one locale: the operator's override when there is
     * one, otherwise the shipped string translated INTO THAT LOCALE — not into
     * whatever locale the current request happens to use.
     *
     * @return array<string,string>
     */
    public function texts(string $locale): array
    {
        /** @var array<string,string> $texts */
        $texts = $this->inLocale($locale, fn(): array => $this->textDefaults());
        $overrides = $this->allTexts()[$locale] ?? null;
        if (is_array($overrides)) {
            foreach (array_keys(self::TEXT_FIELDS) as $field) {
                $value = $overrides[$field] ?? null;
                if (is_string($value) && $value !== '') { $texts[$field] = $value; }
            }
        }
        return $texts;
    }
    /**
     * The raw per-locale override map, for the editor.
     *
     * @return array<string,mixed>
     */
    public function allTexts(): array
    {
        $raw = $this->readSetting('home_texts');
        if ($raw === null || $raw === '') { return []; }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    /**
     * Run $fn with the locale switched, and put the previous one back whatever
     * happens — a leaked locale would mistranslate the rest of the request.
     * Mirrors NotificationService::translateInLocale().
     */
    private function inLocale(string $locale, callable $fn): mixed
    {
        $previous = \App\Support\I18n::getLocale();
        if ($locale === '' || $locale === $previous) { return $fn(); }
        \App\Support\I18n::setLocale($locale);
        try {
            return $fn();
        } finally {
            \App\Support\I18n::setLocale($previous);
        }
    }
    /** Names the plugin's row in the CMS sortable list; other rows pass through. */
    public function cmsSectionName(string $label, string $key): string
    {
        return $key === self::HOME_SECTION_KEY ? __('Desiderata e donazioni') : $label;
    }
    /**
     * The editor card, printed inside the CMS form. No row means the plugin is
     * off (onDeactivate() removed it), and then there is nothing to edit.
     */
    public function cmsFields(mixed $sections): void
    {
        if (!is_array($sections)) { return; }
        $section = $sections[self::HOME_SECTION_KEY] ?? null;
        if (!is_array($section)) { return; }
        $locales = \App\Support\I18n::getAvailableLocales();
        $current = \App\Support\I18n::getLocale();
        // The admin's own language first: that is the one being proof-read.
        if (isset($locales[$current])) { $locales = [$current => $locales[$current]] + $locales; }
        $overrides = $this->allTexts();
        $labels = $this->textFieldLabels();
        // Placeholders show the SHIPPED text in that language, so the operator
        // can see what an empty field will produce before typing anything.
        $defaults = [];
        foreach (array_keys($locales) as $locale) {
            /** @var array<string,string> $shipped */
            $shipped = $this->inLocale((string) $locale, fn(): array => $this->textDefaults());
            $defaults[(string) $locale] = $shipped;
        }
        require __DIR__ . '/views/cms-section.php';
    }
    /**
     * Persists the plugin's own fields during the CMS home save.
     *
     * Filter contract: return the (possibly extended) $errors array on EVERY
     * path and let nothing escape — HookManager swallows a throwing filter and
     * keeps the unfiltered value, which would turn a failed save into a page
     * that says "saved". Writing only when the incoming array is empty matches
     * the rule every core block on that page already follows.
     *
     * @param list<string> $errors
     * @return list<string>
     */
    public function cmsSave(array $errors, mixed $data): array
    {
        if ($errors !== []) { return $errors; }
        if (!is_array($data)) { return $errors; }
        $own = $data[self::HOME_SECTION_KEY] ?? null;
        if (!is_array($own)) { return $errors; }
        $labels = $this->textFieldLabels();
        $submitted = is_array($own['texts'] ?? null) ? $own['texts'] : [];
        $texts = [];
        foreach (array_keys(\App\Support\I18n::getAvailableLocales()) as $locale) {
            $locale = (string) $locale;
            $perLocale = is_array($submitted[$locale] ?? null) ? $submitted[$locale] : [];
            foreach (self::TEXT_FIELDS as $field => $max) {
                $raw = $perLocale[$field] ?? '';
                $value = is_scalar($raw) ? trim(strip_tags((string) $raw)) : '';
                if (mb_strlen($value) > $max) {
                    $errors[] = __('Testo troppo lungo per la sezione Desiderata (%s).', $labels[$field] ?? $field);
                    return $errors;
                }
                if ($value !== '') { $texts[$locale][$field] = $value; }
            }
        }
        $isActive = isset($own['is_active']) ? 1 : 0;
        try {
            $this->writeSetting('home_texts', (string) json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            $key = self::HOME_SECTION_KEY;
            $stmt = $this->db->prepare('UPDATE home_content SET is_active = ? WHERE section_key = ?');
            if ($stmt === false) { throw new RuntimeException($this->db->error); }
            $stmt->bind_param('is', $isActive, $key);
            if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
            $stmt->close();
        } catch (Throwable $e) {
            \App\Support\SecureLogger::error('[Desiderata] CMS save failed: ' . $e->getMessage());
            $errors[] = __('Impossibile salvare la sezione Desiderata. Riprova.');
        }
        return $errors;
    }
    /**
     * True the second time it is asked, so the shared style/script block is
     * printed once even when a page carries more than one donation form.
     */
    public static function assetsAlreadyPrinted(): bool
    {
        $already = self::$assetsPrinted;
        self::$assetsPrinted = true;
        return $already;
    }
    private function render(Response $r, string $view, array $data, bool $admin): Response
    {
        extract($data, EXTR_SKIP);
        $title = $seoTitle = __('Desiderata e donazioni');
        $db = $this->db;
        ob_start(); require __DIR__ . '/views/' . $view . '.php'; $content = ob_get_clean();
        ob_start(); require __DIR__ . '/../../../app/Views/' . ($admin ? '' : 'frontend/') . 'layout.php'; $html = ob_get_clean();
        $r->getBody()->write($html);
        return $r->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }
    public function search(Request $q, Response $r): Response
    {
        $raw = $q->getQueryParams()['q'] ?? '';
        $term = is_string($raw) ? trim($raw) : '';
        $rows = mb_strlen($term) >= 3 && mb_strlen($term) <= 120 ? $this->wanted($term, 30) : [];
        $r->getBody()->write(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        return $r->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
    }
    public function catalogueSearch(Request $q, Response $r): Response
    {
        $raw = $q->getQueryParams()['q'] ?? '';
        $term = is_string($raw) ? trim($raw) : '';
        $rows = [];
        if (mb_strlen($term) >= 3 && mb_strlen($term) <= 120) {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
            // is_desiderata travels with the row so the picker can SAY which
            // records are open requests. The WHERE clause deliberately does not
            // filter them out: receiving a free-form donation against an
            // ordinary holding is a supported workflow, and an operator who
            // genuinely received the requested book must be able to pick it.
            // The warning belongs in the interface, not in a silent exclusion.
            $stmt = $this->db->prepare("SELECT id, titolo, isbn13, isbn10, is_desiderata FROM libri WHERE deleted_at IS NULL AND (titolo LIKE ? ESCAPE '!' OR isbn13 LIKE ? ESCAPE '!' OR isbn10 LIKE ? ESCAPE '!') ORDER BY titolo, id LIMIT 30");
            $stmt->bind_param('sss', $like, $like, $like); $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        $r->getBody()->write(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        return $r->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
    }

    public static function validateOffer(array $input): array
    {
        $out = [];
        foreach (['donor_name' => 150, 'donor_email' => 254, 'title' => 255, 'author' => 255, 'publisher' => 255, 'isbn' => 20, 'notes' => 2000] as $key => $max) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || mb_strlen(trim($value)) > $max) { throw new InvalidArgumentException(__('Controlla la lunghezza dei campi.')); }
            $out[$key] = trim($value);
        }
        if ($out['donor_name'] === '' || $out['title'] === '' || !filter_var($out['donor_email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(__('Inserisci nome, email valida e titolo del libro.'));
        }
        if (($input['consent'] ?? '') !== '1') { throw new InvalidArgumentException(__('Conferma il consenso al contatto per la donazione.')); }
        return $out;
    }
    /**
     * Transport for the reCAPTCHA verifier. Null means "the library's own
     * POST", which is what production uses; a test injects a fake here instead
     * of talking to Google.
     */
    private ?\ReCaptcha\RequestMethod $recaptchaTransport = null;
    public function setRecaptchaTransport(?\ReCaptcha\RequestMethod $transport): void { $this->recaptchaTransport = $transport; }
    /**
     * Same contract as the contact form (ContactController::submitForm), for
     * the same reason: an installation with no keys configured must keep
     * working, and one WITH keys must not accept a submission that carries no
     * token — a missing token is the shape an automated post has.
     *
     * @param array<string,mixed> $input
     */
    private function verifyRecaptcha(array $input): void
    {
        $config = \App\Support\ConfigStore::get('contacts', []);
        $secret = is_array($config) ? ($config['recaptcha_secret_key'] ?? '') : '';
        if (!is_string($secret) || $secret === '') { return; }
        $token = $input['recaptcha_token'] ?? '';
        if (!is_string($token) || $token === '') { throw new InvalidArgumentException(__('Verifica reCAPTCHA fallita. Riprova.')); }
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $response = (new \ReCaptcha\ReCaptcha($secret, $this->recaptchaTransport))
            ->setExpectedAction('desiderata_offer')
            ->setScoreThreshold(0.5)
            ->verify($token, is_string($remoteIp) ? $remoteIp : '');
        if (!$response->isSuccess()) { throw new InvalidArgumentException(__('Verifica reCAPTCHA fallita. Riprova.')); }
    }
    /**
     * Where to send the donor after a successful proposal.
     *
     * Anything the request supplies here ends up in a Location header, so the
     * allow-list is the security control: a local absolute path, never a
     * protocol-relative `//evil.example` (which a browser reads as another
     * host), never a backslash variant of it, never a header-splitting CR/LF.
     * Everything else falls back to the desiderata page.
     *
     * A control byte anywhere in the value refuses it outright, and that is the
     * half a pattern alone gets wrong. A browser REMOVES tab, LF and CR from a
     * URL wherever they appear — not merely at the edges — before it parses it
     * (WHATWG URL Standard, "URL parsing"). Matching the raw string therefore
     * judges something the browser will never see: "/\t\evil.example" contains
     * no "/\" pair and passes a pattern looking for one, yet the browser reads
     * it as "/\evil.example", a network-path reference to the host the attacker
     * named. No legitimate return path carries such a byte, so the whole class
     * is refused rather than stripped — stripping would leave two forms of the
     * same value, the one sent and the one rewritten, and every later reader
     * would have to reason about both.
     */
    private static function returnPath(mixed $raw): string
    {
        if (!is_string($raw) || preg_match('/[\x00-\x1F\x7F]/', $raw) === 1) {
            return '';
        }

        return preg_match('#^/(?![/\\\\])[^\r\n]{0,254}$#D', $raw) === 1 ? $raw : '';
    }
    /**
     * First contact from a visitor the site never gave a session to.
     *
     * The donation form also lives on `/` and on a book page, and SessionPolicy
     * serves both without a session — deliberately, because those pages are
     * edge-cacheable and a per-visitor CSRF token baked into a shared cache
     * would be handed to everyone. So the form there renders an empty token and
     * the browser mints one before submitting. With scripting off nothing mints
     * it, the POST arrives with no token and no prior session, and the core CSRF
     * guard answers a bare "Sessione Scaduta" — to a donor who did nothing
     * wrong and whose typed text is gone.
     *
     * This runs BEFORE that guard and only in the one case that could never be a
     * valid protected request in the first place: no token submitted AND no
     * token in the session. It accepts nothing and writes nothing. It starts the
     * session, gives the visitor their own form back with everything they typed
     * and a real token, and asks them to send it once more. Everything else —
     * a token that is wrong, or missing while a session exists — is left to the
     * core guard exactly as before.
     *
     * Safe to reach cross-site: the response only echoes what the caller itself
     * posted, is not readable across origins, and changes no state.
     */
    public function confirmFirstContact(Request $q, \Psr\Http\Server\RequestHandlerInterface $handler): Response
    {
        $input = (array) $q->getParsedBody();
        $submitted = is_string($input['csrf_token'] ?? null) ? trim($input['csrf_token']) : '';
        $inSession = is_string($_SESSION['csrf_token'] ?? null) ? $_SESSION['csrf_token'] : '';
        if ($submitted !== '' || $inSession !== '') {
            return $handler->handle($q);
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        \App\Support\Csrf::ensureToken();

        return $this->page(new \Slim\Psr7\Response(), [
            'error' => __('Per completare l’invio conferma un’ultima volta: il tuo browser non aveva ancora una sessione aperta. Quello che hai scritto è tutto qui.'),
            'values' => $input,
            'returnTo' => self::returnPath($input['return_to'] ?? ''),
        ], 200);
    }
    /**
     * Where a donor lands after a successful proposal, carrying the "thank you"
     * in the URL and not only in the session.
     *
     * The session flash alone is not enough, and the reason is structural rather
     * than accidental. SessionPolicy lists '/' among the sessionless paths: an
     * anonymous visitor reading the homepage is served with no session and no
     * cookie at all, which is the point of that policy. The donation form lives
     * on that page too, so a proposal sent from the homepage mints its session
     * midway through the submission — the /csrf-token fetch does it — and then
     * asks a flash to survive a redirect onto a DIFFERENT page. Nothing
     * guarantees that continuity; the policy explicitly declines to.
     *
     * Submitting from /desiderata never showed the problem because that page IS
     * sessionful and is also where the donor lands, so the flash never has to
     * cross anything. Same banner, same code, different session guarantees.
     *
     * A marker in the redirect removes the dependency entirely. The honest cost:
     * anyone who types the URL sees a thank-you for a donation they did not
     * make. It states nothing about them, reveals nothing, and writes nothing —
     * a fair trade for a confirmation that otherwise vanishes for the visitors
     * least likely to try again.
     */
    private static function thankYouUrl(string $returnTo): string
    {
        $target = url($returnTo !== '' ? $returnTo : self::PATH_PUBLIC);
        // returnPath() admits a query string, so the separator has to be chosen
        // rather than assumed — appending a second '?' would make the marker
        // part of the previous parameter's value and silently do nothing.
        $separator = str_contains($target, '?') ? '&' : '?';

        return $target . $separator . self::THANK_YOU_MARKER . '=1#donation-form';
    }
    /**
     * A reader has offered a book: tell the operators.
     *
     * Called after the INSERT and outside any transaction on purpose — telling
     * the library about a proposal must never be able to undo the proposal.
     *
     * @param array<string,string> $v the validated proposal
     */
    private function notifyOffer(int $offerId, array $v): void
    {
        $title = self::shortTitle($v['title'] ?? '');
        $donor = trim($v['donor_name'] ?? '');
        $this->notifyOperators(
            static fn(): string => sprintf(__('Nuova proposta di donazione: %s'), $title),
            static fn(): string => sprintf(__('%s ha proposto di donare «%s». Valuta la proposta in Desiderata e donazioni.'), $donor, $title),
            absoluteUrl('/admin/desiderata#donation-offers'),
            $offerId
        );
    }
    public function offer(Request $q, Response $r): Response
    {
        $input = (array)$q->getParsedBody();
        $returnTo = self::returnPath($input['return_to'] ?? '');
        $transactionOpen = false;
        // Set at the one throw that means "the book stopped being wanted while
        // you were filling the form in". Flagged at the throw rather than
        // recognised from the message in the catch: the message is translated,
        // so matching on its text would quietly stop working in every locale
        // but Italian.
        $bookNoLongerWanted = false;
        try {
            if (!empty($input['website'])) { throw new InvalidArgumentException(__('Proposta non valida.')); }
            if (time() - (int)($_SESSION['desiderata_last_offer'] ?? 0) < 60) { return $this->page($r, ['error' => __('Attendi un minuto prima di inviare un’altra proposta.'), 'values' => $input, 'returnTo' => $returnTo], 429); }
            $this->verifyRecaptcha($input);
            $v = self::validateOffer($input);
            $rawId = $input['book_id'] ?? '';
            if (!is_string($rawId) || ($rawId !== '' && !ctype_digit($rawId))) { throw new InvalidArgumentException(__('Seleziona un libro valido.')); }
            $bookId = $rawId === '' ? null : (int)$rawId;
            // Serialize proposals and receipts on the same book row. Otherwise a
            // receipt can close the request between validation and insertion.
            $this->db->begin_transaction();
            $transactionOpen = true;
            if ($bookId !== null) {
                $stmt = $this->db->prepare('SELECT titolo FROM libri l WHERE id=? AND deleted_at IS NULL AND is_desiderata=1 AND NOT EXISTS (SELECT 1 FROM copie c WHERE c.libro_id=l.id) FOR UPDATE');
                $stmt->bind_param('i', $bookId); $stmt->execute();
                $book = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$book) { $bookNoLongerWanted = true; throw new InvalidArgumentException(__('Questo libro non è più richiesto. Puoi proporlo come altra donazione.')); }
                $v['title'] = $book['titolo'];
            }
            $stmt = $this->db->prepare('INSERT INTO desiderata_offers (book_id, donor_name, donor_email, title, author, publisher, isbn, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('isssssss', $bookId, $v['donor_name'], $v['donor_email'], $v['title'], $v['author'], $v['publisher'], $v['isbn'], $v['notes']); $stmt->execute();
            $offerId = (int) $this->db->insert_id;
            $stmt->close();
            $this->db->commit();
            $transactionOpen = false;
            $_SESSION['desiderata_last_offer'] = time();
            $_SESSION['desiderata_success'] = true;
            $this->notifyOffer($offerId, $v);
            return $r->withHeader('Location', self::thankYouUrl($returnTo))->withStatus(303);
        } catch (Throwable $e) {
            if ($transactionOpen) { $this->db->rollback(); }
            // Same shape as manage(): a rejected input speaks for itself, while
            // anything else is logged and answered with a generic message. What
            // matters is that BOTH branches re-render the form with 'values' —
            // the error middleware renders a bare 500 with no access to the
            // request body, so an unhandled database failure here would throw
            // away everything the visitor typed into the donation form.
            if (!$e instanceof InvalidArgumentException) {
                \App\Support\SecureLogger::error('[Desiderata] Offer failed: ' . $e->getMessage());
            }
            // The message tells the donor they can still offer this book as a
            // separate donation. Dropping book_id is what makes that true: the
            // form locks the title whenever book_id is set — server-side, via
            // $lockTitle in offer-form.php — and the only control that released
            // it was a button wired in JavaScript. Without this the promise held
            // only for visitors running scripts; the rest were left with a
            // read-only title and, if they took the noscript link out, an empty
            // form. Everything else they typed stays where it is.
            if ($bookNoLongerWanted) {
                unset($input['book_id']);
            }
            return $this->page($r, [
                'error' => $e instanceof InvalidArgumentException
                    ? $e->getMessage()
                    : __('Non è stato possibile registrare la proposta. Riprova tra poco: i dati che hai inserito sono ancora qui.'),
                'values' => $input,
                // The re-rendered form is the /desiderata one, so it cannot
                // show inline on the book page; carrying return_to means a
                // second, successful attempt still lands the donor back there.
                'returnTo' => $returnTo,
            ], 422);
        }
    }
    /**
     * How many undecided proposals each of these books already has.
     *
     * Kept out of wanted() on purpose: that query answers the public search as
     * well, and nothing on the public side may learn how many people have
     * offered a book. One extra grouped read serves both admin surfaces.
     *
     * @param list<array<string,mixed>> $books
     * @return list<array<string,mixed>> the same rows, each with `open_offers`
     */
    private function attachOpenOffers(array $books): array
    {
        foreach ($books as $index => $book) { $books[$index]['open_offers'] = 0; }
        $ids = [];
        foreach ($books as $book) {
            $id = (int) ($book['id'] ?? 0);
            if ($id > 0) { $ids[] = $id; }
        }
        if ($ids === []) { return $books; }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT book_id, COUNT(*) AS n FROM desiderata_offers WHERE status IN ('pending','accepted') AND book_id IN ($placeholders) GROUP BY book_id");
        if ($stmt === false) { return $books; }
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $counts = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $counts[(int) $row['book_id']] = (int) $row['n'];
        }
        $stmt->close();
        foreach ($books as $index => $book) {
            $books[$index]['open_offers'] = $counts[(int) ($book['id'] ?? 0)] ?? 0;
        }
        return $books;
    }
    /**
     * The open requests for the dashboard, each knowing whether somebody has
     * already offered it: a book with a live proposal must send the operator to
     * that proposal, not to the desk-receipt button.
     *
     * @return list<array<string,mixed>>
     */
    public function wantedWithOpenOffers(int $limit = 6): array
    {
        return $this->attachOpenOffers($this->wanted('', $limit));
    }
    /**
     * The two operator panels on /admin/dashboard, printed by core through
     * `admin.dashboard.sections` — which core fires only for admin and staff.
     *
     * The wanted panel renders even when the list is empty: an operator who has
     * never used the feature has to be able to find it from the page they open
     * first. The proposals panel follows the dashboard's own rule instead and
     * appears only when there is something to decide.
     */
    public function dashboard(): void
    {
        try {
            $books = $this->wantedWithOpenOffers(6);
            $offers = $this->db->query("SELECT * FROM desiderata_offers WHERE status = 'pending' ORDER BY id DESC LIMIT 6");
            $offers = $offers === false ? [] : $offers->fetch_all(MYSQLI_ASSOC);
            $pendingTotal = $this->pendingOfferCount();
            $wantedTotal = $this->wantedCount();
        } catch (Throwable $e) {
            // A panel is not worth the dashboard: log and render nothing.
            \App\Support\SecureLogger::error('[Desiderata] dashboard panels failed: ' . $e->getMessage());
            return;
        }
        require __DIR__ . '/views/dashboard.php';
    }
    /**
     * Open requests in total: the count pill above the six on the dashboard,
     * and the public pager's idea of how many pages there are.
     *
     * Same predicate as wanted() with no search term, and nothing else — a
     * count that disagrees with the list it counts produces an empty last page.
     */
    public function wantedCount(): int
    {
        $result = $this->db->query('SELECT COUNT(*) AS n FROM libri l WHERE l.deleted_at IS NULL AND l.is_desiderata = 1 AND NOT EXISTS (SELECT 1 FROM copie c WHERE c.libro_id = l.id)');
        return $result === false ? 0 : (int) ($result->fetch_assoc()['n'] ?? 0);
    }
    private function assertNoOpenOffers(int $bookId): void
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) AS n FROM desiderata_offers WHERE book_id = ? AND status IN ('pending','accepted')");
        if ($stmt === false) { throw new RuntimeException($this->db->error); }
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $open = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();
        if ($open > 0) { throw new InvalidArgumentException(__('Questo libro ha proposte aperte: registra la ricezione dalla proposta.')); }
    }

    /**
     * Someone handed the book over at the desk: register the copy without a
     * proposal behind it.
     *
     * One transaction, and the same idempotency key as manage(): the row is
     * locked FOR UPDATE with `is_desiderata = 1` in the predicate, so a second
     * click (or a replayed form) finds nothing to receive and answers 422
     * instead of creating a second copy. A failure anywhere inside rolls the
     * whole thing back — a copy without the flag cleared, or a cleared flag
     * with no copy, are both worse than refusing.
     *
     * A book somebody has already offered is refused outright: closing that
     * request here would leave the donor's proposal open forever with the book
     * already in the catalogue, so the card links to the proposal instead.
     */
    public function receiveDirect(Request $q, Response $r, int $bookId): Response
    {
        $input = (array) $q->getParsedBody();
        // An allow-list of one token, not a path: this never reaches a header
        // as supplied, it only picks between two URLs this method builds.
        $back = ($input['return_to'] ?? '') === 'dashboard'
            ? url('/admin/dashboard') . '#desiderata-dashboard'
            : url('/admin/desiderata') . '#requested-books';
        $offerId = 0;
        $this->db->begin_transaction();
        try {
            // The NOT EXISTS clause is the same one registerCopies() and offer()
            // lock on, and it is not redundant with is_desiderata = 1: a row can
            // be BOTH flagged and already holding copies. That is precisely the
            // inconsistency DataIntegrity exists to repair, and without this
            // clause a receipt recorded in that window would answer it by
            // creating a SECOND inventory copy rather than refusing.
            $stmt = $this->db->prepare('SELECT titolo FROM libri l WHERE id = ? AND deleted_at IS NULL AND is_desiderata = 1 AND NOT EXISTS (SELECT 1 FROM copie c WHERE c.libro_id = l.id) FOR UPDATE');
            if ($stmt === false) { throw new RuntimeException($this->db->error); }
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $book = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$book) { throw new InvalidArgumentException(__('Il libro non è più una richiesta aperta.')); }
            $title = (string) ($book['titolo'] ?? '');
            $this->assertNoOpenOffers($bookId);
            $copyId = (new \App\Models\CopyRepository($this->db))->createWithAllocatedInventoryCode($bookId, 'LIB-' . $bookId, 'disponibile', 'Donazione diretta');
            if ($copyId <= 0) { throw new RuntimeException('Copy creation failed'); }
            // Self-guarding, per ABSOLUTE RULE 2, and kept even though
            // DataIntegrity clears the flag by itself once a copy exists: the
            // guarantee must not depend on a helper's side effect. Same shape
            // as manage(), for the same reason.
            $stmt = $this->db->prepare('UPDATE libri SET is_desiderata = 0' . \App\Support\BookVisibility::catalogueStamp($this->db) . ' WHERE id = ? AND deleted_at IS NULL');
            if ($stmt === false) { throw new RuntimeException($this->db->error); }
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $stmt->close();
            if (!(new \App\Support\DataIntegrity($this->db))->recalculateBookAvailability($bookId, true, true)) {
                throw new RuntimeException('Availability update failed');
            }
            // The audit row is what keeps the received history complete: the
            // admin list reads donation receipts from this table, and a receipt
            // with no row would simply never have happened. Donor fields stay
            // empty — nobody left a name — and the view says so at render time,
            // in the reader's language (decision J: nothing locale-dependent is
            // ever written to the database).
            $stmt = $this->db->prepare("INSERT INTO desiderata_offers (book_id, received_book_id, copy_id, donor_name, donor_email, title, notes, status, received_at) VALUES (?, ?, ?, '', '', ?, '', 'received', NOW())");
            if ($stmt === false) { throw new RuntimeException($this->db->error); }
            $stmt->bind_param('iiis', $bookId, $bookId, $copyId, $title);
            $stmt->execute();
            $offerId = (int) $this->db->insert_id;
            $stmt->close();
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            if (!$e instanceof InvalidArgumentException) { \App\Support\SecureLogger::error('[Desiderata] Direct receipt failed: ' . $e->getMessage()); }
            return $this->admin($q, $r, $e instanceof InvalidArgumentException ? $e->getMessage() : __('Operazione non riuscita. Nessuna copia è stata registrata.'))->withStatus(422);
        }
        $this->invalidate();
        $this->notifyReceipt($bookId, $offerId);
        return $r->withHeader('Location', $back)->withStatus(303);
    }
    /**
     * A book the library was looking for is now on the shelf: tell the
     * operators. Called after the commit and outside the transaction, because
     * telling them must never be able to undo the donation.
     *
     * The operator who registered the receipt is notified too. A library has
     * several people at the desk and the others are exactly who needs to know
     * that a request just closed; the bell row is one shared row anyway, so
     * excluding the actor from the email only would make the two channels
     * disagree about what happened.
     *
     * The title is read here rather than passed in: the three call sites
     * (manage(), receiveDirect(), registerCopies()) know different things, and
     * the book row is the one thing all three have just written.
     */
    private function notifyReceipt(int $bookId, int $offerId): void
    {
        $title = self::shortTitle($this->bookTitle($bookId));
        // Not a label — an identifier, for the case where the title lookup
        // fails. Nothing to translate, so the notification still reads the same
        // in every recipient's language.
        if ($title === '') { $title = '#' . $bookId; }
        $this->notifyOperators(
            static fn(): string => sprintf(__('Libro ricevuto in donazione: %s'), $title),
            static fn(): string => sprintf(__('«%s» è entrato in catalogo con una copia fisica. La richiesta è chiusa.'), $title),
            absoluteUrl('/admin/books/' . $bookId . '#physical-copies'),
            $offerId
        );
    }
    /** The title of a live book, or '' when it cannot be read. */
    private function bookTitle(int $bookId): string
    {
        try {
            $stmt = $this->db->prepare('SELECT titolo FROM libri WHERE id = ? AND deleted_at IS NULL LIMIT 1');
            if ($stmt === false) { return ''; }
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return trim((string) ($row['titolo'] ?? ''));
        } catch (Throwable $e) {
            \App\Support\SecureLogger::error('[Desiderata] notification title lookup failed: ' . $e->getMessage());
            return '';
        }
    }
    /**
     * A book title is 255 characters wide in this plugin's table and the bell
     * row's title column is 255 too: composed with a prefix, a long title would
     * overflow the INSERT and the notification would be lost with only a line in
     * the log. Shortened once, so the bell, the subject and the body agree.
     */
    private static function shortTitle(string $title): string
    {
        $title = trim($title);
        return mb_strlen($title) > 120 ? rtrim(mb_substr($title, 0, 119)) . '…' : $title;
    }
    /**
     * Where the emails go when a test is watching. Production leaves it null
     * and gets a real EmailService built per notification.
     */
    private ?\App\Support\EmailService $emailService = null;
    public function setEmailService(\App\Support\EmailService $service): void { $this->emailService = $service; }
    /**
     * The two channels Pinakes notifies operators on: the shared admin bell and
     * one email each.
     *
     * This deliberately does NOT call NotificationService::notifyAdmins(). That
     * helper composes the subject and the body ONCE, in whatever locale the
     * request happens to be in, and sends those same strings to everybody — a
     * donation posted by a visitor browsing in French would reach every operator
     * in French. Pinakes renders user-facing email in the RECIPIENT's language
     * (#360), so the ten lines of recipient selection below are duplicated on
     * purpose. Do not "simplify" them back into notifyAdmins().
     *
     * $title and $message are callables, not strings, for the same reason: each
     * one is evaluated once per locale, inside inLocale(), so the __() literals
     * live at the call site where the locale scanner can see them.
     *
     * Best-effort throughout: the offer or the copy is already committed by the
     * time this runs, and a mail server having a bad day must never turn a
     * donation the library actually received into a 500 that discards it.
     *
     * @param callable(): string $title
     * @param callable(): string $message
     */
    private function notifyOperators(callable $title, callable $message, string $link, int $relatedId): void
    {
        try {
            $notifications = new \App\Support\NotificationService($this->db);
            // The bell is one row shared by every operator, so it can only speak
            // one language: the installation's.
            $installation = \App\Support\I18n::getInstallationLocale();
            $notifications->createNotification(
                'general',
                (string) $this->inLocale($installation, $title),
                (string) $this->inLocale($installation, $message),
                $link,
                $relatedId
            );
            $recipients = $this->operatorRecipients();
            if ($recipients === []) { return; }
            // Same circuit-breaker as notifyAdmins(): this runs synchronously
            // inside a visitor's POST, and an unreachable SMTP server would hang
            // that request for one connection timeout per operator. The bell row
            // above is already written either way.
            if (!\App\Support\Mailer::isSmtpReachable()) {
                \App\Support\SecureLogger::warning('[Desiderata] SMTP unreachable: notification e-mail skipped for ' . count($recipients) . ' operator(s)');
                return;
            }
            $mailer = $this->emailService ?? new \App\Support\EmailService($this->db);
            foreach ($recipients as $recipient) {
                $locale = $notifications->resolveRecipientLocale($recipient['email']);
                $body = '<p>' . self::e($this->inLocale($locale, $message)) . '</p>'
                    . '<p><a href="' . self::e($link) . '">' . self::e($link) . '</a></p>';
                $mailer->sendEmail(
                    $recipient['email'],
                    (string) $this->inLocale($locale, $title),
                    $body,
                    $recipient['name'],
                    $locale
                );
            }
        } catch (Throwable $e) {
            \App\Support\SecureLogger::error('[Desiderata] notification failed: ' . $e->getMessage());
        }
    }
    /**
     * Active admins and staff who have an address, de-duplicated by lower-cased
     * e-mail — the same recipient set every other Pinakes admin notification
     * uses (NotificationService::notifyAdmins), minus the locale column, which
     * resolveRecipientLocale() reads and caches per address.
     *
     * @return list<array{email: string, name: string}>
     */
    private function operatorRecipients(): array
    {
        $result = $this->db->query(
            "SELECT email, nome, cognome FROM utenti
              WHERE tipo_utente IN ('admin', 'staff') AND stato = 'attivo'
                AND email IS NOT NULL AND email <> ''"
        );
        if ($result === false) { return []; }
        $byEmail = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '') { continue; }
            $key = mb_strtolower($email);
            if (isset($byEmail[$key])) { continue; }
            $byEmail[$key] = [
                'email' => $email,
                'name' => trim((string) ($row['nome'] ?? '') . ' ' . (string) ($row['cognome'] ?? '')),
            ];
        }
        return array_values($byEmail);
    }
    /**
     * The admin screen holds two independent lists, so they hold two
     * independent cursors: a single shared `page` made the proposals pager
     * advance the requested-books list as well, silently carrying unread
     * proposals past the operator. Scoped parameters follow the precedent
     * already set by activity_page / book_activity_page elsewhere in the admin.
     */
    public static function pageNumber(array $params, string $key): int
    {
        return max(1, (int)($params[$key] ?? 1));
    }
    /** The paging to carry across an action: only what differs from page one. */
    public static function pagingQuery(int $offersPage, int $booksPage): string
    {
        $params = array_filter(
            ['offers_page' => $offersPage, 'books_page' => $booksPage],
            static fn(int $value): bool => $value > 1
        );
        return $params === [] ? '' : '?' . http_build_query($params);
    }
    /**
     * The catalogue records that carry the ISBN a free-form proposal came with.
     *
     * A donor who typed an ISBN has already told the library which book this
     * is; making the operator search for it again by hand is work the data
     * already did. The lookup is a suggestion and nothing more — the picker
     * stays editable and the receipt still validates server-side.
     *
     * One query for the whole page, not one per proposal: the ISBN columns are
     * uniquely indexed, so the equality branches are index reads; the
     * normalised branches exist for installations that stored the hyphens the
     * catalogue import happened to receive.
     *
     * @param list<array<string,mixed>> $offers
     * @return array<int, list<array<string,mixed>>> offer id => candidate books
     */
    private function matchOffersByIsbn(array $offers): array
    {
        $perOffer = [];
        $candidates = [];
        foreach ($offers as $offer) {
            $offerId = (int) ($offer['id'] ?? 0);
            // Only the proposals that still need a record picked: a linked or
            // closed one has nothing to suggest.
            if ($offerId <= 0 || !empty($offer['book_id'])) { continue; }
            if (!in_array($offer['status'] ?? '', ['pending', 'accepted'], true)) { continue; }
            $raw = is_scalar($offer['isbn'] ?? null) ? (string) $offer['isbn'] : '';
            $clean = \App\Support\IsbnFormatter::clean($raw);
            if ($clean === '' || strlen($clean) < 10 || strlen($clean) > 13) { continue; }
            // Both shapes of the same book: a donor may write the ISBN-10 of a
            // record catalogued with its ISBN-13, or the other way round. An
            // unparseable code still gets looked up as typed — a valid EAN-13
            // that is not a valid ISBN is exactly what some records carry.
            $variants = \App\Support\IsbnFormatter::getAllVariants($clean);
            $forOffer = $variants === [] ? [$clean] : array_values($variants);
            $perOffer[$offerId] = $forOffer;
            foreach ($forOffer as $variant) { $candidates[$variant] = true; }
        }
        if ($candidates === []) { return []; }
        $list = array_keys($candidates);
        $placeholders = implode(',', array_fill(0, count($list), '?'));
        $normalised = "REPLACE(REPLACE(REPLACE(UPPER(%s), '-', ''), ' ', ''), '.', '')";
        $stmt = $this->db->prepare(
            'SELECT id, titolo, isbn13, isbn10, is_desiderata FROM libri WHERE deleted_at IS NULL AND ('
            . "isbn13 IN ($placeholders) OR isbn10 IN ($placeholders) OR "
            . sprintf($normalised, 'isbn13') . " IN ($placeholders) OR "
            . sprintf($normalised, 'isbn10') . " IN ($placeholders)"
            . ') ORDER BY titolo, id LIMIT 50'
        );
        if ($stmt === false) { return []; }
        $bind = array_merge($list, $list, $list, $list);
        $stmt->bind_param(str_repeat('s', count($bind)), ...$bind);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $matches = [];
        foreach ($perOffer as $offerId => $wanted) {
            foreach ($rows as $row) {
                $codes = [
                    \App\Support\IsbnFormatter::clean((string) ($row['isbn13'] ?? '')),
                    \App\Support\IsbnFormatter::clean((string) ($row['isbn10'] ?? '')),
                ];
                if (array_intersect($wanted, array_filter($codes)) === []) { continue; }
                $matches[$offerId][] = $row;
                // A shelf of duplicates is a cataloguing problem, not something
                // to render thirty options of.
                if (count($matches[$offerId]) >= 10) { break; }
            }
        }
        return $matches;
    }
    /**
     * $errorOfferId names the proposal the message belongs to, so the view can
     * put it inside that card instead of on a banner thirty cards away. Zero
     * means the failure is not about one proposal and the banner is right.
     */
    public function admin(Request $q, Response $r, string $error = '', int $errorOfferId = 0): Response
    {
        $params = $q->getQueryParams();
        $offersPage = self::pageNumber($params, 'offers_page');
        $booksPage = self::pageNumber($params, 'books_page');
        $offersOffset = min($offersPage - 1, 100000) * 30;
        $booksOffset = min($booksPage - 1, 100000) * 30;
        // Loud, unlike the dashboard panel and the menu pill: those two decorate
        // pages that are about something else and may quietly show nothing, but
        // this list IS the operator's backlog. An empty one here would read as
        // "no proposals to judge" when the truth is "the query failed", so a
        // half-finished activation must say so instead of hiding the queue.
        $offersResult = $this->db->query('SELECT * FROM desiderata_offers ORDER BY id DESC LIMIT 31 OFFSET ' . $offersOffset);
        if ($offersResult === false) { $this->fail('cannot read the donation proposals'); }
        $offers = $offersResult->fetch_all(MYSQLI_ASSOC);
        $more = count($offers) > 30; $offers = array_slice($offers, 0, 30);
        // The requested-books list is paged too: capped at a fixed number, the
        // oldest requests were reachable from nowhere in the admin.
        $books = $this->wanted('', 31, $booksOffset);
        $moreBooks = count($books) > 30; $books = array_slice($books, 0, 30);
        // Each request says whether somebody has already offered it, so the
        // list can point at that proposal instead of offering the desk button
        // that receiveDirect() would refuse anyway.
        $books = $this->attachOpenOffers($books);
        // The donor gave an ISBN: the picker starts from what that ISBN finds
        // instead of an empty search box.
        $isbnMatches = $this->matchOffersByIsbn($offers);
        return $this->render($r, 'admin', compact('offers', 'offersPage', 'booksPage', 'more', 'error', 'errorOfferId', 'books', 'moreBooks', 'isbnMatches'), true);
    }
    public function manage(Request $q, Response $r, int $id): Response
    {
        $input = (array)$q->getParsedBody();
        $action = $input['action'] ?? '';
        // The form action carries the operator's current paging, so an error
        // re-renders where they were and every redirect returns there instead
        // of dumping a backlog of 30+ proposals back to page one.
        $params = $q->getQueryParams();
        $back = url('/admin/desiderata')
            . self::pagingQuery(self::pageNumber($params, 'offers_page'), self::pageNumber($params, 'books_page'))
            . '#donation-offers';
        if (!in_array($action, ['accepted', 'rejected', 'received', 'delete'], true)) { return $this->admin($q, $r, __('Azione non valida.'))->withStatus(422); }
        // Deleting is the erasure path for the donor's name, e-mail and notes:
        // a proposal carries personal data the library asked for, and once it is
        // settled there has to be a way to remove it from the interface, not
        // only from SQL. Allowed in any state, including closed ones.
        if ($action === 'delete') {
            $stmt = $this->db->prepare('DELETE FROM desiderata_offers WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            return $r->withHeader('Location', $back)->withStatus(303);
        }
        // Set inside the transaction, read after the commit: the receipt
        // notification must fire from the same place as the other two receipt
        // paths — after the donation is a fact — and never from inside the
        // transaction it would be reporting on.
        $receivedBookId = 0;
        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM desiderata_offers WHERE id=? FOR UPDATE');
            $stmt->bind_param('i', $id); $stmt->execute(); $offer = $stmt->get_result()->fetch_assoc();
            if (!$offer || in_array($offer['status'], ['received', 'rejected'], true)) { throw new InvalidArgumentException(__('Proposta già chiusa o non trovata.')); }
            if ($action === 'received') {
                $rawBookId = $offer['book_id'] ?: ($input['received_book_id'] ?? '');
                if (!is_scalar($rawBookId) || !ctype_digit((string)$rawBookId) || (int)$rawBookId < 1) {
                    throw new InvalidArgumentException(__('Seleziona la scheda del libro ricevuto.'));
                }
                $bookId = (int)$rawBookId;
                $stmt = $this->db->prepare('SELECT id FROM libri WHERE id=? AND deleted_at IS NULL FOR UPDATE');
                $stmt->bind_param('i', $bookId); $stmt->execute();
                if (!$stmt->get_result()->fetch_assoc()) { throw new InvalidArgumentException(__('Per la ricezione scegli una scheda libro esistente. Puoi prima crearla con zero copie.')); }
                $copyId = (new \App\Models\CopyRepository($this->db))->createWithAllocatedInventoryCode($bookId, 'LIB-' . $bookId, 'disponibile', 'Donazione #' . $id);
                if ($copyId <= 0) { throw new RuntimeException('Copy creation failed'); }
                // Self-guarding, per ABSOLUTE RULE 2. Provably a no-op here —
                // the row was locked FOR UPDATE and checked for deleted_at two
                // statements above, inside this same transaction — but the
                // guard removes the dependency on a caller-side check a later
                // refactor could move away. This is the receipt of a real
                // donation, so unlike the maintenance sweeps it has no business
                // touching an archived record.
                $stmt = $this->db->prepare('UPDATE libri SET is_desiderata=0' . \App\Support\BookVisibility::catalogueStamp($this->db) . ' WHERE id=? AND deleted_at IS NULL'); $stmt->bind_param('i', $bookId); $stmt->execute();
                if (!(new \App\Support\DataIntegrity($this->db))->recalculateBookAvailability($bookId, true, true)) { throw new RuntimeException('Availability update failed'); }
                $stmt = $this->db->prepare("UPDATE desiderata_offers SET status='received', received_book_id=?, copy_id=?, received_at=NOW() WHERE id=?");
                $stmt->bind_param('iii', $bookId, $copyId, $id); $stmt->execute();
                $receivedBookId = $bookId;
            } else {
                $stmt = $this->db->prepare('UPDATE desiderata_offers SET status=? WHERE id=?'); $stmt->bind_param('si', $action, $id); $stmt->execute();
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            if (!$e instanceof InvalidArgumentException) { \App\Support\SecureLogger::error('[Desiderata] Receipt failed: ' . $e->getMessage()); }
            return $this->admin($q, $r, $e instanceof InvalidArgumentException ? $e->getMessage() : __('Operazione non riuscita. Nessuna copia è stata registrata.'), $id)->withStatus(422);
        }
        $this->invalidate();
        if ($receivedBookId > 0) { $this->notifyReceipt($receivedBookId, $id); }
        return $r->withHeader('Location', $back)->withStatus(303);
    }
}
