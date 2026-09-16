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
        if ($this->db->query('UPDATE libri SET is_desiderata = 0 WHERE is_desiderata = 1') === false) {
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
    public function onActivate(): void
    {
        // Checked before the DDL: an ALTER commits implicitly, so failing after
        // it would leave the schema changed by an activation that never happened.
        if ($this->pluginId === null) { throw new RuntimeException('Missing plugin ID'); }
        $this->ensureSchema();
        $this->db->begin_transaction();
        try {
            $this->onDeactivate();
            foreach ([
                'app.routes.register' => 'registerRoutes', 'admin.menu.render' => 'menu',
                'book.form.before_copies' => 'bookField', 'book.form.save' => 'prepareBook',
                'frontend.home.sections' => 'home',
            ] as $hook => $method) {
                $class = self::class;
                $stmt = $this->db->prepare('INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active) VALUES (?, ?, ?, ?, 10, 1)');
                $stmt->bind_param('isss', $this->pluginId, $hook, $class, $method);
                $stmt->execute();
                $stmt->close();
            }
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
        $this->invalidate();
    }
    public function onDeactivate(): void
    {
        $stmt = $this->db->prepare('DELETE FROM plugin_hooks WHERE plugin_id = ?');
        $stmt->bind_param('i', $this->pluginId);
        $stmt->execute();
        ContentCache::deferBooksChanged();
    }
    private function invalidate(): void
    {
        ContentCache::booksChanged();
    }
    public function menu(): void
    {
        echo '<a class="nav-link group flex items-center px-4 py-3 rounded-lg text-gray-700 hover:bg-gray-100" href="' . self::e(url('/admin/desiderata')) . '"><i class="fas fa-hand-holding-heart mr-3" aria-hidden="true"></i>' . self::e(__('Desiderata e donazioni')) . '</a>';
    }
    public static function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
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
        if ($wanted && $id === null) { $fields['copie_totali'] = $fields['copie_disponibili'] = 0; }
        return $fields;
    }
    public function registerRoutes($app): void
    {
        $plugin = $this;
        $csrf = new \App\Middleware\CsrfMiddleware();
        $admin = new \App\Middleware\AdminAuthMiddleware($this->db);
        $app->get('/desiderata', fn(Request $q, Response $r) => $plugin->page($r));
        $app->get('/desiderata/search', fn(Request $q, Response $r) => $plugin->search($q, $r))->add(new \App\Middleware\RateLimitMiddleware(90, 60, 'desiderata-search'));
        $app->post('/desiderata/offers', fn(Request $q, Response $r) => $plugin->offer($q, $r))->add($csrf)->add(new \App\Middleware\RateLimitMiddleware(5, 900, 'desiderata-offer'));
        $app->get('/admin/desiderata/books', fn(Request $q, Response $r) => $plugin->catalogueSearch($q, $r))->add($admin);
        $app->get('/admin/desiderata', fn(Request $q, Response $r) => $plugin->admin($q, $r))->add($admin);
        $app->post('/admin/desiderata/offers/{id:[0-9]+}', fn(Request $q, Response $r, array $a) => $plugin->manage($q, $r, (int)$a['id']))->add($csrf)->add($admin);
    }
    public function wanted(string $term = '', int $limit = 12, int $offset = 0): array
    {
        $offset = max(0, $offset);
        $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
        $stmt = $this->db->prepare("SELECT l.id, l.titolo, l.isbn13, l.isbn10, e.nome AS editore,
            (SELECT GROUP_CONCAT(a.nome SEPARATOR ', ') FROM libri_autori la JOIN autori a ON a.id=la.autore_id WHERE la.libro_id=l.id) AS autore
            FROM libri l LEFT JOIN editori e ON e.id=l.editore_id
            WHERE l.deleted_at IS NULL AND l.is_desiderata=1
            AND NOT EXISTS (SELECT 1 FROM copie c WHERE c.libro_id=l.id)
            AND (l.titolo LIKE ? ESCAPE '!' OR l.isbn13 LIKE ? ESCAPE '!' OR l.isbn10 LIKE ? ESCAPE '!'
            OR EXISTS (SELECT 1 FROM libri_autori la JOIN autori a ON a.id=la.autore_id WHERE la.libro_id=l.id AND a.nome LIKE ? ESCAPE '!'))
            ORDER BY l.id DESC LIMIT ? OFFSET ?");
        $stmt->bind_param('ssssii', $term, $term, $term, $term, $limit, $offset); $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    public function home(): void { $books = $this->wanted(); require __DIR__ . '/views/public.php'; }
    public function page(Response $r, array $data = [], int $status = 200): Response
    {
        $books = $this->wanted();
        return $this->render($r, 'public', $data + ['books' => $books, 'standalone' => true], false)->withStatus($status);
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
            $stmt = $this->db->prepare("SELECT id, titolo, isbn13, isbn10 FROM libri WHERE deleted_at IS NULL AND (titolo LIKE ? ESCAPE '!' OR isbn13 LIKE ? ESCAPE '!' OR isbn10 LIKE ? ESCAPE '!') ORDER BY titolo, id LIMIT 30");
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
    public function offer(Request $q, Response $r): Response
    {
        $input = (array)$q->getParsedBody();
        try {
            if (!empty($input['website'])) { throw new InvalidArgumentException(__('Proposta non valida.')); }
            if (time() - (int)($_SESSION['desiderata_last_offer'] ?? 0) < 60) { return $this->page($r, ['error' => __('Attendi un minuto prima di inviare un’altra proposta.'), 'values' => $input], 429); }
            $v = self::validateOffer($input);
            $rawId = $input['book_id'] ?? '';
            if (!is_string($rawId) || ($rawId !== '' && !ctype_digit($rawId))) { throw new InvalidArgumentException(__('Seleziona un libro valido.')); }
            $bookId = $rawId === '' ? null : (int)$rawId;
            if ($bookId !== null) {
                $stmt = $this->db->prepare('SELECT titolo FROM libri l WHERE id=? AND deleted_at IS NULL AND is_desiderata=1 AND NOT EXISTS (SELECT 1 FROM copie c WHERE c.libro_id=l.id)');
                $stmt->bind_param('i', $bookId); $stmt->execute();
                $book = $stmt->get_result()->fetch_assoc();
                if (!$book) { throw new InvalidArgumentException(__('Questo libro non è più richiesto. Puoi proporlo come altra donazione.')); }
                $v['title'] = $book['titolo'];
            }
            $stmt = $this->db->prepare('INSERT INTO desiderata_offers (book_id, donor_name, donor_email, title, author, publisher, isbn, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('isssssss', $bookId, $v['donor_name'], $v['donor_email'], $v['title'], $v['author'], $v['publisher'], $v['isbn'], $v['notes']); $stmt->execute();
            $_SESSION['desiderata_last_offer'] = time();
            $_SESSION['desiderata_success'] = true;
            return $r->withHeader('Location', url('/desiderata') . '#donation-form')->withStatus(303);
        } catch (InvalidArgumentException $e) { return $this->page($r, ['error' => $e->getMessage(), 'values' => $input], 422); }
    }
    public function admin(Request $q, Response $r, string $error = ''): Response
    {
        $page = max(1, (int)($q->getQueryParams()['page'] ?? 1));
        $offset = min($page - 1, 100000) * 30;
        $offers = $this->db->query('SELECT * FROM desiderata_offers ORDER BY id DESC LIMIT 31 OFFSET ' . $offset)->fetch_all(MYSQLI_ASSOC);
        $more = count($offers) > 30; $offers = array_slice($offers, 0, 30);
        // The requested-books list is paged too: capped at a fixed number, the
        // oldest requests were reachable from nowhere in the admin.
        $books = $this->wanted('', 31, $offset);
        $moreBooks = count($books) > 30; $books = array_slice($books, 0, 30);
        return $this->render($r, 'admin', compact('offers', 'page', 'more', 'error', 'books', 'moreBooks'), true);
    }
    public function manage(Request $q, Response $r, int $id): Response
    {
        $input = (array)$q->getParsedBody();
        $action = $input['action'] ?? '';
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
            return $r->withHeader('Location', url('/admin/desiderata'))->withStatus(303);
        }
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
                $stmt = $this->db->prepare('UPDATE libri SET is_desiderata=0 WHERE id=?'); $stmt->bind_param('i', $bookId); $stmt->execute();
                if (!(new \App\Support\DataIntegrity($this->db))->recalculateBookAvailability($bookId, true, true)) { throw new RuntimeException('Availability update failed'); }
                $stmt = $this->db->prepare("UPDATE desiderata_offers SET status='received', received_book_id=?, copy_id=?, received_at=NOW() WHERE id=?");
                $stmt->bind_param('iii', $bookId, $copyId, $id); $stmt->execute();
            } else {
                $stmt = $this->db->prepare('UPDATE desiderata_offers SET status=? WHERE id=?'); $stmt->bind_param('si', $action, $id); $stmt->execute();
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            if (!$e instanceof InvalidArgumentException) { \App\Support\SecureLogger::error('[Desiderata] Receipt failed: ' . $e->getMessage()); }
            return $this->admin($q, $r, $e instanceof InvalidArgumentException ? $e->getMessage() : __('Operazione non riuscita. Nessuna copia è stata registrata.'))->withStatus(422);
        }
        $this->invalidate();
        return $r->withHeader('Location', url('/admin/desiderata'))->withStatus(303);
    }
}
