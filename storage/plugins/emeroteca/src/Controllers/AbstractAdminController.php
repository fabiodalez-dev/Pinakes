<?php

declare(strict_types=1);

namespace App\Plugins\Emeroteca\Controllers;

use App\Support\HookManager;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;

/**
 * Shared base for the Emeroteca admin controllers.
 *
 * There is no PSR-4 autoloader scope for plugin classes: each concrete
 * controller file require_once's this file at the top (mobile-api /
 * archives RicJsonLdBuilder pattern), and EmerotecaPlugin::dispatch()
 * lazy-requires the concrete controller by short class name.
 *
 * Contract (see EmerotecaPlugin::dispatch): constructor receives
 * (mysqli, HookManager); action methods receive (request, response, args).
 */
abstract class AbstractAdminController
{
    protected \mysqli $db;
    protected HookManager $hookManager;

    public function __construct(\mysqli $db, HookManager $hookManager)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;
    }

    /**
     * Render a plugin view wrapped in the core admin layout (sidebar,
     * header). Same two-pass pattern as ArchivesPlugin::renderView().
     *
     * @param array<string, mixed> $data
     */
    protected function renderView(ResponseInterface $response, string $view, array $data): ResponseInterface
    {
        $viewFile = __DIR__ . '/../Views/' . $view . '.php';
        if (!is_file($viewFile)) {
            SecureLogger::error('[Emeroteca] view not found: ' . $viewFile);
            $response->getBody()->write('Emeroteca view missing: ' . htmlspecialchars($view, ENT_QUOTES, 'UTF-8'));
            return $response->withStatus(500);
        }

        // Extract view data into local variables expected by the view.
        extract($data, EXTR_SKIP);

        ob_start();
        require $viewFile;
        $content = (string) ob_get_clean();

        // Reuse the core admin layout for consistent chrome.
        ob_start();
        require __DIR__ . '/../../../../../app/Views/layout.php';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /** 303 redirect to an in-app path (url() adds the base path). */
    protected function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response->withHeader('Location', url($path))->withStatus(303);
    }

    /** Flash shown by app/Views/layout.php on the next render. */
    protected function flashSuccess(string $message): void
    {
        $_SESSION['success_message'] = $message;
    }

    /** Flash shown by app/Views/layout.php on the next render. */
    protected function flashError(string $message): void
    {
        $_SESSION['error_message'] = $message;
    }

    /**
     * True when the given core table exists in the current schema.
     * The plugin tolerates degraded installs where editori/generi are
     * missing (see EmerotecaPlugin::ensureCoreForeignKeys); the admin
     * UI degrades the same way (no JOIN, empty select).
     */
    protected function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] table probe prepare failed: ' . $this->db->error);
            return false;
        }
        $stmt->bind_param('s', $table);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] table probe failed: ' . $stmt->error);
            $stmt->close();
            return false;
        }
        $res = $stmt->get_result();
        $exists = $res instanceof \mysqli_result
            && ((int) ($res->fetch_assoc()['c'] ?? 0)) > 0;
        $stmt->close();
        return $exists;
    }

    /**
     * Fetch one testata row (with the publisher name when the editori
     * table exists), or null when the id is unknown.
     *
     * @return array<string, mixed>|null
     */
    protected function fetchTestata(int $id): ?array
    {
        $hasEditori = $this->tableExists('editori');
        $sql = $hasEditori
            ? 'SELECT t.*, e.nome AS editore_nome
                 FROM emeroteca_testate t
                 LEFT JOIN editori e ON t.editore_id = e.id
                WHERE t.id = ?'
            : 'SELECT t.*, NULL AS editore_nome FROM emeroteca_testate t WHERE t.id = ?';
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] fetchTestata prepare failed: ' . $this->db->error);
            return null;
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] fetchTestata failed: ' . $stmt->error);
            $stmt->close();
            return null;
        }
        $res = $stmt->get_result();
        $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /**
     * id => nome of every publisher, for the form select. Empty when
     * the core table is missing (degraded schema).
     *
     * @return array<int, string>
     */
    protected function fetchEditori(): array
    {
        if (!$this->tableExists('editori')) {
            return [];
        }
        $out = [];
        $res = $this->db->query('SELECT id, nome FROM editori ORDER BY nome');
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $out[(int) $row['id']] = (string) $row['nome'];
            }
            $res->free();
        }
        return $out;
    }

    /**
     * id => nome of top-level genres (parent_id IS NULL), for the form
     * select. Empty when the core table is missing.
     *
     * @return array<int, string>
     */
    protected function fetchGeneriTopLevel(): array
    {
        if (!$this->tableExists('generi')) {
            return [];
        }
        $out = [];
        $res = $this->db->query('SELECT id, nome FROM generi WHERE parent_id IS NULL ORDER BY nome');
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $out[(int) $row['id']] = (string) $row['nome'];
            }
            $res->free();
        }
        return $out;
    }

    /**
     * id => titolo of every testata except $excludeId (for the
     * "testata precedente" select — a title cannot continue itself).
     *
     * @return array<int, string>
     */
    protected function fetchTestateExcept(?int $excludeId): array
    {
        $out = [];
        if ($excludeId !== null) {
            $stmt = $this->db->prepare('SELECT id, titolo FROM emeroteca_testate WHERE id <> ? ORDER BY titolo');
            if ($stmt === false) {
                SecureLogger::error('[Emeroteca] fetchTestateExcept prepare failed: ' . $this->db->error);
                return [];
            }
            $stmt->bind_param('i', $excludeId);
            if (!$stmt->execute()) {
                SecureLogger::error('[Emeroteca] fetchTestateExcept failed: ' . $stmt->error);
                $stmt->close();
                return [];
            }
            $res = $stmt->get_result();
            if ($res instanceof \mysqli_result) {
                while ($row = $res->fetch_assoc()) {
                    $out[(int) $row['id']] = (string) $row['titolo'];
                }
            }
            $stmt->close();
            return $out;
        }
        $res = $this->db->query('SELECT id, titolo FROM emeroteca_testate ORDER BY titolo');
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $out[(int) $row['id']] = (string) $row['titolo'];
            }
            $res->free();
        }
        return $out;
    }
}
