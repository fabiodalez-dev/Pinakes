<?php

namespace App\Controllers\Admin;

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
        $slug = $args['slug'] ?? 'about-us';

        // Get current locale from session
        $currentLocale = \App\Support\I18n::getLocale();

        // Recupera la pagina dal database (slug + locale)
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

        if (!$page) {
            $response->getBody()->write(__('Pagina non trovata.'));
            return $response->withStatus(404);
        }

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

        // Aggiorna la pagina (slug + locale)
        $stmt = $this->db->prepare("
            UPDATE cms_pages
            SET title = ?, content = ?, image = ?, meta_description = ?, is_active = ?, updated_at = NOW()
            WHERE slug = ? AND locale = ?
        ");
        $stmt->bind_param('ssssiss', $title, $content, $image, $metaDescription, $isActive, $slug, $currentLocale);

        if ($stmt->execute()) {
            $stmt->close();
            return $response
                ->withHeader('Location', '/admin/cms/' . $slug . '?saved=1')
                ->withStatus(302);
        } else {
            $error = $this->db->error;
            $stmt->close();
            return $response
                ->withHeader('Location', '/admin/cms/' . $slug . '?error=db')
                ->withStatus(302);
        }
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
            $payload = json_encode(['error' => __('Errore durante il caricamento del file.')]);
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

        // SECURITY: Validate file size (max 10MB for CMS images)
        if ($uploadedFile->getSize() > 10 * 1024 * 1024) {
            $payload = json_encode(['error' => __('File troppo grande. Dimensione massima 10MB.')] );
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

        // SECURITY: Store uploads outside web directory to prevent direct access
        $baseDir = realpath(__DIR__ . '/../../../storage/uploads');
        if ($baseDir === false) {
            error_log("Upload base directory not found");
            $payload = json_encode(['error' => __('Errore di configurazione del server.')] );
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        $uploadPath = $baseDir . '/cms';

        // Crea directory se non esiste
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }

        // SECURITY: Generate cryptographically secure random filename
        try {
            $randomSuffix = bin2hex(random_bytes(16));
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
        if ($this->db) {
            $this->db->close();
        }
    }
}
