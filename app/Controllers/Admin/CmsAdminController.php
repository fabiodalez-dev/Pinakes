<?php

namespace App\Controllers\Admin;

use App\Support\CmsHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CmsAdminController
{
    private \mysqli $db;

    public function __construct()
    {
        // SECURITY & COMPATIBILITY FIX: Use centralized configuration
        $settings = require __DIR__ . '/../../../config/settings.php';
        $cfg = $settings['db'];

        $this->db = new \mysqli(
            $cfg['hostname'],
            $cfg['username'],
            $cfg['password'],
            $cfg['database'],
            $cfg['port'],
            $cfg['socket'] ?? null
        );

        if ($this->db->connect_error) {
            // SECURITY FIX: Log detailed error, show generic message
            error_log("Database connection failed: " . $this->db->connect_error);
            throw new \Exception("Errore di connessione al database. Contatta l'amministratore.");
        }

        $this->db->set_charset($cfg['charset']);
    }

    public function editPage(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? CmsHelper::getSlug('about');

        // Get current locale from session
        $currentLocale = \App\Support\I18n::getLocale();

        // Check if slug needs to be redirected to correct locale version
        $correctSlug = CmsHelper::getRedirectSlug($slug, $currentLocale);
        if ($correctSlug !== null) {
            // Redirect 301 to correct localized admin slug
            // Keep the query (?saved=1, ?error=…): it carries the save outcome.
            $query = $request->getUri()->getQuery();
            return $response
                ->withHeader('Location', url('/admin/cms/' . $correctSlug) . ($query !== '' ? '?' . $query : ''))
                ->withStatus(301);
        }

        // The page under this slug, or under another slug of the same page
        // (an it_IT install seeded with 'about-us'): never a duplicate.
        $page = $this->findPageRow($slug, $currentLocale);

        if (!$page) {
            // Check if this is a known CMS page that should exist
            $pageId = CmsHelper::getPageIdFromSlug($slug);
            if ($pageId !== null) {
                // Auto-create the missing page
                $defaultTitle = ucfirst(str_replace('-', ' ', $slug));
                $escapedTitle = htmlspecialchars($defaultTitle, ENT_QUOTES, 'UTF-8');
                $defaultContent = '<p>' . __('Contenuto della pagina') . ' "' . $escapedTitle . '"</p>';

                $createStmt = $this->db->prepare("
                    INSERT INTO cms_pages (slug, locale, title, content, meta_description, is_active)
                    VALUES (?, ?, ?, ?, '', 1)
                ");
                $createStmt->bind_param('ssss', $slug, $currentLocale, $defaultTitle, $defaultContent);
                if (!$createStmt->execute()) {
                    // Log error but continue - we'll handle the 404 below if page still doesn't exist
                    error_log("CmsAdminController: Failed to auto-create page '{$slug}' for locale '{$currentLocale}': " . $this->db->error);
                }
                $createStmt->close();

                // Reload the page
                $stmt = $this->db->prepare("
                    SELECT id, slug, locale, title, content, image, meta_description, is_active
                    FROM cms_pages
                    WHERE slug = ? AND locale = ?
                ");
                $stmt->bind_param('ss', $slug, $currentLocale);
                $stmt->execute();
                $result = $stmt->get_result();
                $page = $result->fetch_assoc();
                $stmt->close();
            }

            if (!$page) {
                $response->getBody()->write(__('Pagina non trovata.'));
                return $response->withStatus(404);
            }
        }

        // An image uploaded by an older version may sit outside the web root.
        \App\Support\CmsImageStorage::ensurePublic($page['image'] ?? null);

        // Passa i dati alla view
        $pageData = $page;
        $title = sprintf(__('Modifica %s'), $page['title']);

        ob_start();
        include __DIR__ . '/../../Views/admin/cms-edit.php';
        $content = ob_get_clean();

        ob_start();
        include __DIR__ . '/../../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function updatePage(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? 'about-us';
        $data = $request->getParsedBody();

        // Get current locale from session
        $currentLocale = \App\Support\I18n::getLocale();

        $title = $data['title'] ?? '';
        $content = $data['content'] ?? '';
        $image = $data['image'] ?? '';
        $metaDescription = $data['meta_description'] ?? '';
        $isActive = isset($data['is_active']) ? 1 : 0;

        // The row the editor showed: the same lookup as editPage(), so a page
        // stored under another slug of the same page is the one updated.
        $page = $this->findPageRow($slug, $currentLocale);
        if ($page === null) {
            return $response
                ->withHeader('Location', url('/admin/cms/' . $slug . '?error=db'))
                ->withStatus(302);
        }
        $pageRowId = (int) $page['id'];

        $stmt = $this->db->prepare("
            UPDATE cms_pages
            SET title = ?, content = ?, image = ?, meta_description = ?, is_active = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('ssssii', $title, $content, $image, $metaDescription, $isActive, $pageRowId);

        $ok = $stmt->execute();
        $stmt->close();
        // Back to the editor under the locale's own slug (no extra 301 hop).
        return $response
            ->withHeader('Location', url('/admin/cms/' . CmsHelper::getLocalizedSlug($slug, $currentLocale) . ($ok ? '?saved=1' : '?error=db')))
            ->withStatus(302);
    }

    /**
     * The cms_pages row for a slug in a locale: the exact slug first, then any
     * other slug of the same page (CmsHelper's map), as the public page does.
     *
     * @return array<string, mixed>|null
     */
    private function findPageRow(string $slug, string $locale): ?array
    {
        $pageId = CmsHelper::getPageIdFromSlug($slug);
        $variants = $pageId !== null ? CmsHelper::getSlugsForPage($pageId) : [];
        $slugs = array_values(array_unique(array_merge([$slug], $variants)));
        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $stmt = $this->db->prepare("
            SELECT id, slug, locale, title, content, image, meta_description, is_active
            FROM cms_pages
            WHERE slug IN ($placeholders) AND locale = ?
            ORDER BY slug = ? DESC, id ASC
            LIMIT 1
        ");
        $params = array_merge($slugs, [$locale, $slug]);
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result instanceof \mysqli_result ? $result->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /**
     * The largest CMS image accepted: 10MB, or what PHP takes when it takes
     * less (upload_max_filesize, post_max_size). The editor gets the same
     * limit, so it refuses a file the server would drop instead of failing.
     */
    public static function uploadLimit(): int
    {
        $limit = 10 * 1024 * 1024;
        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $bytes = self::iniBytes((string) ini_get($key));
            if ($bytes > 0) {
                $limit = min($limit, $bytes);
            }
        }
        return $limit;
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^(\d+)\s*([kmg]?)/i', $value, $m)) {
            return 0;
        }
        $n = (int) $m[1];
        return match (strtolower($m[2])) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    }

    private static function formatBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.') . ' MB'
            : (int) ceil($bytes / 1024) . ' KB';
    }

    public function uploadImage(Request $request, Response $response): Response
    {
        $uploadedFiles = $request->getUploadedFiles();

        if (!isset($uploadedFiles['file'])) {
            $payload = json_encode(['error' => __('Nessun file caricato.')]);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $uploadedFile = $uploadedFiles['file'];

        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            $tooBig = in_array($uploadedFile->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            $payload = json_encode(['error' => $tooBig
                ? sprintf(__('File troppo grande. Dimensione massima %s.'), self::formatBytes(self::uploadLimit()))
                : __('Errore durante il caricamento del file.')]);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // SECURITY: Validate file extension
        $filename = $uploadedFile->getClientFilename();
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (!in_array($extension, $allowedExtensions)) {
            $payload = json_encode(['error' => __('Estensione del file non valida.')]);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // SECURITY: Validate file size (10MB, or less when PHP accepts less)
        if ($uploadedFile->getSize() > self::uploadLimit()) {
            $payload = json_encode(['error' => sprintf(__('File troppo grande. Dimensione massima %s.'), self::formatBytes(self::uploadLimit()))]);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // SECURITY: Validate MIME type with magic number check
        $tmpPath = $uploadedFile->getStream()->getMetadata('uri');
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpPath);

        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mimeType, $allowedMimes)) {
            $payload = json_encode(['error' => __('Tipo di file non valido. Carica un\'immagine reale.')] );
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // The image is shown on a public page, so it is stored where its URL
        // (/uploads/cms/<file>) is served from: public/uploads/cms. It used to
        // be written to storage/uploads/cms, outside the web root, while the
        // same /uploads/cms URL was saved: every CMS image answered 404.
        $uploadPath = \App\Support\CmsImageStorage::publicDir();
        if (!is_dir($uploadPath) && !@mkdir($uploadPath, 0755, true) && !is_dir($uploadPath)) {
            \App\Support\SecureLogger::error('[CMS] upload directory cannot be created: ' . $uploadPath);
            $payload = json_encode(['error' => __('Errore di configurazione del server.')] );
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
        $baseDir = realpath($uploadPath);
        if ($baseDir === false) {
            $payload = json_encode(['error' => __('Errore di configurazione del server.')] );
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        // SECURITY: Generate cryptographically secure random filename
        try {
            $randomSuffix = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            error_log("CRITICAL: random_bytes() failed - system entropy exhausted");
            $payload = json_encode(['error' => __('Errore del server. Riprova più tardi.')] );
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        $newFilename = 'cms_' . $randomSuffix . '.' . $extension;
        // Sanitize filename to prevent null byte injection
        $newFilename = str_replace("\\0", '', $newFilename);
        $targetPath = $uploadPath . '/' . basename($newFilename);

        // SECURITY: Verify final path is within allowed directory
        $realUploadPath = realpath(dirname($targetPath));
        if ($realUploadPath === false || strpos($realUploadPath, $baseDir) !== 0) {
            error_log("Path traversal attempt detected");
            $payload = json_encode(['error' => __('Percorso del file non valido.')] );
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $uploadedFile->moveTo($targetPath);
            // SECURITY: Set secure file permissions
            @chmod($targetPath, 0644);
        } catch (\Throwable $e) {
            error_log("Image upload error: " . $e->getMessage());
            $payload = json_encode(['error' => __('Caricamento non riuscito. Riprova.')]);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        $url = '/uploads/cms/' . $newFilename;

        $payload = json_encode(['url' => $url, 'filename' => $newFilename]);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function __destruct()
    {
        $this->db->close();
    }
}
