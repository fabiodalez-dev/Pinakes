<?php

declare(strict_types=1);

namespace App\Plugins\Emeroteca\Controllers;

use App\Support\HookManager;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;

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

    /** @var array<string, bool> */
    private array $tableCache = [];

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
        if (array_key_exists($table, $this->tableCache)) {
            return $this->tableCache[$table];
        }
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] table probe prepare failed: ' . $this->db->error);
            return $this->tableCache[$table] = false;
        }
        $stmt->bind_param('s', $table);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] table probe failed: ' . $stmt->error);
            $stmt->close();
            return $this->tableCache[$table] = false;
        }
        $res = $stmt->get_result();
        $exists = $res instanceof \mysqli_result
            && ((int) ($res->fetch_assoc()['c'] ?? 0)) > 0;
        $stmt->close();
        return $this->tableCache[$table] = $exists;
    }

    /**
     * Remove a plugin-managed image after its database row has gone, but only
     * when no title, volume year or issue still references it. External URLs
     * and paths outside public/uploads/emeroteca are never touched.
     */
    protected function deleteManagedImageIfUnreferenced(string $url): void
    {
        if (!str_starts_with($url, '/uploads/emeroteca/')) {
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT 1 FROM emeroteca_fascicoli WHERE copertina_url = ?
             UNION SELECT 1 FROM emeroteca_annate WHERE copertina_url = ?
             UNION SELECT 1 FROM emeroteca_testate WHERE logo_url = ?
             LIMIT 1'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] image reference check prepare failed: ' . $this->db->error);
            return;
        }
        $stmt->bind_param('sss', $url, $url, $url);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] image reference check failed: ' . $stmt->error);
            $stmt->close();
            return;
        }
        $res = $stmt->get_result();
        $referenced = $res instanceof \mysqli_result && $res->fetch_row() !== null;
        $stmt->close();
        if ($referenced) {
            return;
        }

        $baseDir = realpath(__DIR__ . '/../../../../../public/uploads/emeroteca');
        if ($baseDir === false) {
            return;
        }
        $resolved = realpath($baseDir . DIRECTORY_SEPARATOR . basename($url));
        if ($resolved !== false
            && str_starts_with($resolved, $baseDir . DIRECTORY_SEPARATOR)
            && is_file($resolved)
            && !@unlink($resolved)) {
            SecureLogger::warning('[Emeroteca] unable to remove orphan image: ' . $resolved);
        }
    }

    /**
     * Validate and store an image selected through the app's Uppy widget.
     * Uppy feeds the hidden multipart input, while this server-side check is
     * authoritative (extension, size and MIME magic bytes).
     *
     * @return array{success: bool, message?: string, path?: string}
     */
    protected function storeManagedImage(UploadedFileInterface $uploadedFile, string $prefix): array
    {
        $filename = (string) $uploadedFile->getClientFilename();
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return ['success' => false, 'message' => __('Formato immagine non supportato. Usa JPG, PNG o WebP.')];
        }
        $size = $uploadedFile->getSize();
        if ($size === null || $size <= 0 || $size > 5 * 1024 * 1024) {
            return ['success' => false, 'message' => __('L\'immagine è troppo grande. Max 5MB.')];
        }

        $tmpPath = $uploadedFile->getStream()->getMetadata('uri');
        if (!is_string($tmpPath) || !is_file($tmpPath)) {
            return ['success' => false, 'message' => __('Errore durante l\'upload.')];
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpPath);
        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return ['success' => false, 'message' => __('Tipo di file non valido.')];
        }

        $baseDir = realpath(__DIR__ . '/../../../../../public/uploads');
        if ($baseDir === false) {
            SecureLogger::error('[Emeroteca] public uploads base directory not found');
            return ['success' => false, 'message' => __('Errore di configurazione.')];
        }
        $targetDir = $baseDir . '/emeroteca';
        if ((!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) || !is_writable($targetDir)) {
            SecureLogger::error('[Emeroteca] image uploads target directory is not writable');
            return ['success' => false, 'message' => __('Errore di configurazione.')];
        }

        try {
            $safePrefix = preg_replace('/[^a-z0-9_-]/i', '_', $prefix) ?: 'immagine';
            $newFilename = $safePrefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
            $uploadPath = $targetDir . DIRECTORY_SEPARATOR . basename($newFilename);
            $uploadedFile->moveTo($uploadPath);
            @chmod($uploadPath, 0644);
            return ['success' => true, 'path' => '/uploads/emeroteca/' . $newFilename];
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] image upload error: ' . $e->getMessage());
            return ['success' => false, 'message' => __('Errore durante l\'upload.')];
        }
    }

    /**
     * Validate and store a PDF outside the web root. It can only be read back
     * through EmerotecaPlugin::serveIssuePdf(), which applies admin/public
     * access rules.
     *
     * @return array{success: bool, message?: string, path?: string, original_name?: string, size?: int}
     */
    protected function storeManagedPdf(UploadedFileInterface $uploadedFile): array
    {
        $clientName = basename((string) $uploadedFile->getClientFilename());
        if ($clientName === '' || strtolower(pathinfo($clientName, PATHINFO_EXTENSION)) !== 'pdf') {
            return ['success' => false, 'message' => __('Formato file non supportato.')];
        }
        $size = $uploadedFile->getSize();
        if ($size === null || $size <= 0 || $size > 100 * 1024 * 1024) {
            return ['success' => false, 'message' => __('File troppo grande.')];
        }

        $tmpPath = $uploadedFile->getStream()->getMetadata('uri');
        if (!is_string($tmpPath) || !is_file($tmpPath)) {
            return ['success' => false, 'message' => __('Errore durante l\'upload.')];
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpPath);
        $header = file_get_contents($tmpPath, false, null, 0, 5);
        if ($mimeType !== 'application/pdf' || $header !== '%PDF-') {
            return ['success' => false, 'message' => __('Formato file non supportato.')];
        }

        $targetDir = __DIR__ . '/../../../../uploads/emeroteca';
        if ((!is_dir($targetDir) && !@mkdir($targetDir, 0750, true)) || !is_writable($targetDir)) {
            SecureLogger::error('[Emeroteca] private PDF uploads target directory is not writable');
            return ['success' => false, 'message' => __('Errore di configurazione.')];
        }
        $targetReal = realpath($targetDir);
        if ($targetReal === false) {
            return ['success' => false, 'message' => __('Errore di configurazione.')];
        }

        try {
            $newFilename = 'fascicolo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.pdf';
            $uploadPath = $targetReal . DIRECTORY_SEPARATOR . $newFilename;
            $uploadedFile->moveTo($uploadPath);
            @chmod($uploadPath, 0640);
            return [
                'success' => true,
                'path' => $newFilename,
                'original_name' => mb_substr($clientName, 0, 255),
                'size' => (int) $size,
            ];
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] PDF upload error: ' . $e->getMessage());
            return ['success' => false, 'message' => __('Errore durante l\'upload.')];
        }
    }

    /** Remove a no-longer-referenced plugin PDF, never an arbitrary path. */
    protected function deleteManagedPdfIfUnreferenced(string $filename): void
    {
        if ($filename === '' || basename($filename) !== $filename || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'pdf') {
            return;
        }
        $stmt = $this->db->prepare('SELECT 1 FROM emeroteca_fascicoli WHERE pdf_path = ? LIMIT 1');
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] PDF reference check prepare failed: ' . $this->db->error);
            return;
        }
        $stmt->bind_param('s', $filename);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] PDF reference check failed: ' . $stmt->error);
            $stmt->close();
            return;
        }
        $res = $stmt->get_result();
        $referenced = $res instanceof \mysqli_result && $res->fetch_row() !== null;
        $stmt->close();
        if ($referenced) {
            return;
        }

        $baseDir = realpath(__DIR__ . '/../../../../uploads/emeroteca');
        $resolved = $baseDir === false ? false : realpath($baseDir . DIRECTORY_SEPARATOR . $filename);
        if ($resolved !== false
            && str_starts_with($resolved, $baseDir . DIRECTORY_SEPARATOR)
            && is_file($resolved)
            && !@unlink($resolved)) {
            SecureLogger::warning('[Emeroteca] unable to remove orphan PDF: ' . $resolved);
        }
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
