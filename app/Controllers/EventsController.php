<?php

namespace App\Controllers;

use App\Support\ContentSanitizer;
use App\Support\HtmlHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EventsController
{
    /**
     * List all events with pagination
     */
    public function index(Request $request, Response $response, \mysqli $db): Response
    {
        // CRITICAL: Set UTF-8 charset to prevent corruption of Greek/Unicode characters
        $db->set_charset('utf8mb4');

        // Check if events section is enabled
        $repository = new \App\Models\SettingsRepository($db);
        $eventsEnabled = $repository->get('cms', 'events_page_enabled', '1') === '1';

        // Pagination
        $page = max(1, (int)($request->getQueryParams()['page'] ?? 1));
        $perPage = 10;
        $offset = ($page - 1) * $perPage;

        // Get total count
        $countResult = $db->query("SELECT COUNT(*) as total FROM events");
        $totalEvents = $countResult->fetch_assoc()['total'];
        $totalPages = (int)ceil($totalEvents / $perPage);

        // Get events for current page
        $stmt = $db->prepare("
            SELECT id, title, slug, event_date, event_time, featured_image, is_active, created_at
            FROM events
            ORDER BY event_date DESC, created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param('ii', $perPage, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $events = [];
        while ($row = $result->fetch_assoc()) {
            $events[] = $row;
        }
        $stmt->close();

        $title = __('Gestione Eventi');

        // Include the specific view first
        ob_start();
        include __DIR__ . '/../Views/events/index.php';
        $content = ob_get_clean();

        // Then include layout which uses $content
        ob_start();
        include __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Toggle events section visibility
     */
    public function toggleVisibility(Request $request, Response $response, \mysqli $db): Response
    {
        // CSRF validated by CsrfMiddleware
        $data = $request->getParsedBody();

        $db->set_charset('utf8mb4');

        $enabled = isset($data['events_enabled']) ? '1' : '0';

        // Update setting
        $repository = new \App\Models\SettingsRepository($db);
        $repository->set('cms', 'events_page_enabled', $enabled);

        if ($enabled === '1') {
            $_SESSION['success_message'] = __('Sezione Eventi abilitata! Il menu e le pagine sono ora visibili nel frontend.');
        } else {
            $_SESSION['success_message'] = __('Sezione Eventi disabilitata. Il menu e le pagine sono ora nascosti nel frontend.');
        }

        return $response->withHeader('Location', '/admin/cms/events')->withStatus(302);
    }

    /**
     * Show create event form
     */
    public function create(Request $request, Response $response, \mysqli $db): Response
    {
        $db->set_charset('utf8mb4');

        $title = __('Crea Nuovo Evento');
        $event = null; // No existing event data

        // Include the form view
        ob_start();
        include __DIR__ . '/../Views/events/form.php';
        $content = ob_get_clean();

        // Then include layout
        ob_start();
        include __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Show edit event form
     */
    public function edit(Request $request, Response $response, \mysqli $db, array $args): Response
    {
        $db->set_charset('utf8mb4');

        $id = (int)($args['id'] ?? 0);

        // Get event data
        $stmt = $db->prepare("
            SELECT id, title, slug, content, event_date, event_time, featured_image,
                   seo_title, seo_description, seo_keywords, og_image,
                   og_title, og_description, og_type, og_url,
                   twitter_card, twitter_title, twitter_description, twitter_image,
                   is_active
            FROM events
            WHERE id = ?
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $event = $result->fetch_assoc();
        $stmt->close();

        if (!$event) {
            $_SESSION['error_message'] = __('Evento non trovato.');
            return $response->withHeader('Location', '/admin/cms/events')->withStatus(302);
        }

        $title = __('Modifica Evento: %s', $event['title']);

        // Include the form view
        ob_start();
        include __DIR__ . '/../Views/events/form.php';
        $content = ob_get_clean();

        // Then include layout
        ob_start();
        include __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Store a new event
     */
    public function store(Request $request, Response $response, \mysqli $db): Response
    {
        // CSRF validated by CsrfMiddleware
        $data = $request->getParsedBody();
        $files = $request->getUploadedFiles();

        $db->set_charset('utf8mb4');

        $errors = [];

        // SECURITY: Sanitize plain text fields (strip all HTML tags)
        $sanitizeText = function ($text): string {
            return trim(strip_tags($text ?? ''));
        };

        // Validate required fields
        $title = $sanitizeText($data['title'] ?? '');
        if (empty($title)) {
            $errors[] = __('Il titolo è obbligatorio.');
        }

        $eventDate = $data['event_date'] ?? '';
        if (empty($eventDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            $errors[] = __('La data dell\'evento è obbligatoria e deve essere nel formato corretto.');
        }

        $eventTime = $data['event_time'] ?? null;
        if (!empty($eventTime) && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $eventTime)) {
            $errors[] = __('L\'ora dell\'evento deve essere nel formato corretto (HH:MM).');
        }

        // Generate slug from title
        $slug = $this->generateSlug($title, $db);

        // SECURITY: Sanitize rich HTML content (TinyMCE) with whitelist
        $content = HtmlHelper::sanitizeHtml($data['content'] ?? '');
        $isActive = isset($data['is_active']) ? 1 : 0;

        // SEO fields
        $seoTitle = $sanitizeText($data['seo_title'] ?? '');
        $seoDescription = $sanitizeText($data['seo_description'] ?? '');
        $seoKeywords = $sanitizeText($data['seo_keywords'] ?? '');
        $ogImage = $sanitizeText($data['og_image'] ?? '');
        $ogTitle = $sanitizeText($data['og_title'] ?? '');
        $ogDescription = $sanitizeText($data['og_description'] ?? '');
        $ogType = $sanitizeText($data['og_type'] ?? 'article');
        $ogUrl = $sanitizeText($data['og_url'] ?? '');
        $twitterCard = $sanitizeText($data['twitter_card'] ?? 'summary_large_image');
        $twitterTitle = $sanitizeText($data['twitter_title'] ?? '');
        $twitterDescription = $sanitizeText($data['twitter_description'] ?? '');
        $twitterImage = $sanitizeText($data['twitter_image'] ?? '');

        // Handle image upload
        $featuredImagePath = null;
        if (isset($files['featured_image']) && $files['featured_image']->getError() === UPLOAD_ERR_OK) {
            $uploadResult = $this->handleImageUpload($files['featured_image']);
            if (!$uploadResult['success']) {
                $errors[] = $uploadResult['message'];
            } else {
                $featuredImagePath = $uploadResult['path'];
            }
        }

        if (!empty($errors)) {
            $_SESSION['error_message'] = implode('<br>', $errors);
            return $response->withHeader('Location', '/admin/cms/events/create')->withStatus(302);
        }

        // Insert event
        $stmt = $db->prepare("
            INSERT INTO events (
                title, slug, content, event_date, event_time, featured_image,
                seo_title, seo_description, seo_keywords, og_image,
                og_title, og_description, og_type, og_url,
                twitter_card, twitter_title, twitter_description, twitter_image,
                is_active
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('ssssssssssssssssssi',
            $title, $slug, $content, $eventDate, $eventTime, $featuredImagePath,
            $seoTitle, $seoDescription, $seoKeywords, $ogImage,
            $ogTitle, $ogDescription, $ogType, $ogUrl,
            $twitterCard, $twitterTitle, $twitterDescription, $twitterImage,
            $isActive
        );

        if ($stmt->execute()) {
            $_SESSION['success_message'] = __('Evento creato con successo!');
        } else {
            $_SESSION['error_message'] = __('Errore durante la creazione dell\'evento.');
        }
        $stmt->close();

        return $response->withHeader('Location', '/admin/cms/events')->withStatus(302);
    }

    /**
     * Update an existing event
     */
    public function update(Request $request, Response $response, \mysqli $db, array $args): Response
    {
        // CSRF validated by CsrfMiddleware
        $data = $request->getParsedBody();
        $files = $request->getUploadedFiles();

        $db->set_charset('utf8mb4');

        $id = (int)($args['id'] ?? 0);

        $errors = [];

        // SECURITY: Sanitize plain text fields (strip all HTML tags)
        $sanitizeText = function ($text): string {
            return trim(strip_tags($text ?? ''));
        };

        // Validate required fields
        $title = $sanitizeText($data['title'] ?? '');
        if (empty($title)) {
            $errors[] = __('Il titolo è obbligatorio.');
        }

        $eventDate = $data['event_date'] ?? '';
        if (empty($eventDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            $errors[] = __('La data dell\'evento è obbligatoria.');
        }

        $eventTime = $data['event_time'] ?? null;
        if (!empty($eventTime) && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $eventTime)) {
            $errors[] = __('L\'ora deve essere nel formato corretto (HH:MM).');
        }

        // Update slug if title changed
        $slug = $this->generateSlug($title, $db, $id);

        // SECURITY: Sanitize rich HTML content (TinyMCE) with whitelist
        $content = HtmlHelper::sanitizeHtml($data['content'] ?? '');
        $isActive = isset($data['is_active']) ? 1 : 0;

        // SEO fields
        $seoTitle = $sanitizeText($data['seo_title'] ?? '');
        $seoDescription = $sanitizeText($data['seo_description'] ?? '');
        $seoKeywords = $sanitizeText($data['seo_keywords'] ?? '');
        $ogImage = $sanitizeText($data['og_image'] ?? '');
        $ogTitle = $sanitizeText($data['og_title'] ?? '');
        $ogDescription = $sanitizeText($data['og_description'] ?? '');
        $ogType = $sanitizeText($data['og_type'] ?? 'article');
        $ogUrl = $sanitizeText($data['og_url'] ?? '');
        $twitterCard = $sanitizeText($data['twitter_card'] ?? 'summary_large_image');
        $twitterTitle = $sanitizeText($data['twitter_title'] ?? '');
        $twitterDescription = $sanitizeText($data['twitter_description'] ?? '');
        $twitterImage = $sanitizeText($data['twitter_image'] ?? '');

        // Handle image upload or removal.
        //
        // Priority order (matters when the user submits BOTH a new file
        // AND ticks "remove_image"): the new upload wins. Treating
        // "remove" as the dominant intent would silently destroy a
        // freshly uploaded file, which is what users hit before this
        // branch.
        $featuredImagePath = null;
        $updateImage = false;

        if (isset($files['featured_image']) && $files['featured_image']->getError() === UPLOAD_ERR_OK) {
            $uploadResult = $this->handleImageUpload($files['featured_image']);
            if (!$uploadResult['success']) {
                $errors[] = $uploadResult['message'];
            } else {
                $updateImage = true;
                $featuredImagePath = $uploadResult['path'];
            }
        } elseif (isset($data['remove_image']) && $data['remove_image'] == '1') {
            $updateImage = true;
            $featuredImagePath = null;
        }

        // Snapshot the previous featured_image before the UPDATE so we can
        // unlink the orphan file in the success branch. The validation-error
        // and UPDATE-fail branches unlink the freshly-uploaded
        // $featuredImagePath instead; this lookup is only consumed by the
        // post-commit success path. Capturing it here keeps the snapshot
        // adjacent to its use site.
        $oldImagePath = null;
        if ($updateImage) {
            $oldStmt = $db->prepare("SELECT featured_image FROM events WHERE id = ?");
            if ($oldStmt !== false) {
                $oldStmt->bind_param('i', $id);
                $oldStmt->execute();
                $oldResult = $oldStmt->get_result();
                $oldRow = $oldResult ? $oldResult->fetch_assoc() : null;
                $oldStmt->close();
                if (is_array($oldRow) && !empty($oldRow['featured_image'])) {
                    $oldImagePath = (string) $oldRow['featured_image'];
                }
            }
        }

        if (!empty($errors)) {
            // Cleanup orphan upload on validation-error short-circuit:
            // handleImageUpload() has already moved the new file to its
            // final path before non-image validation ran. Since we are
            // bailing out without writing the DB row, the file would
            // become an orphan on disk. Unlink it here so the disk
            // state matches the DB state (which still references the
            // old image, if any).
            if ($updateImage && $featuredImagePath !== null) {
                $this->deleteUploadedImageFile($featuredImagePath);
            }
            $_SESSION['error_message'] = implode('<br>', $errors);
            return $response->withHeader('Location', '/admin/cms/events/edit/' . $id)->withStatus(302);
        }

        // Update event
        if ($updateImage) {
            $stmt = $db->prepare("
                UPDATE events SET
                    title = ?, slug = ?, content = ?, event_date = ?, event_time = ?, featured_image = ?,
                    seo_title = ?, seo_description = ?, seo_keywords = ?, og_image = ?,
                    og_title = ?, og_description = ?, og_type = ?, og_url = ?,
                    twitter_card = ?, twitter_title = ?, twitter_description = ?, twitter_image = ?,
                    is_active = ?
                WHERE id = ?
            ");
            $stmt->bind_param('ssssssssssssssssssii',
                $title, $slug, $content, $eventDate, $eventTime, $featuredImagePath,
                $seoTitle, $seoDescription, $seoKeywords, $ogImage,
                $ogTitle, $ogDescription, $ogType, $ogUrl,
                $twitterCard, $twitterTitle, $twitterDescription, $twitterImage,
                $isActive, $id
            );
        } else {
            $stmt = $db->prepare("
                UPDATE events SET
                    title = ?, slug = ?, content = ?, event_date = ?, event_time = ?,
                    seo_title = ?, seo_description = ?, seo_keywords = ?, og_image = ?,
                    og_title = ?, og_description = ?, og_type = ?, og_url = ?,
                    twitter_card = ?, twitter_title = ?, twitter_description = ?, twitter_image = ?,
                    is_active = ?
                WHERE id = ?
            ");
            $stmt->bind_param('sssssssssssssssssii',
                $title, $slug, $content, $eventDate, $eventTime,
                $seoTitle, $seoDescription, $seoKeywords, $ogImage,
                $ogTitle, $ogDescription, $ogType, $ogUrl,
                $twitterCard, $twitterTitle, $twitterDescription, $twitterImage,
                $isActive, $id
            );
        }

        if ($stmt->execute()) {
            $_SESSION['success_message'] = __('Evento aggiornato con successo!');
            // Cleanup orphan file on disk now that the DB row no longer
            // references it. Skipped when the old path matches the new
            // one (defensive — should not happen, but unlinking would
            // delete a still-referenced file).
            if ($updateImage && $oldImagePath !== null && $oldImagePath !== $featuredImagePath) {
                $this->deleteUploadedImageFile($oldImagePath);
            }
        } else {
            $_SESSION['error_message'] = __('Errore durante l\'aggiornamento dell\'evento.');
            // Symmetric cleanup on UPDATE failure: the new file made it
            // to disk via handleImageUpload(), but the DB row still
            // references the OLD path. Unlink the freshly-uploaded
            // file to avoid leaving an unreferenced orphan behind.
            if ($updateImage && $featuredImagePath !== null) {
                $this->deleteUploadedImageFile($featuredImagePath);
            }
        }
        $stmt->close();

        return $response->withHeader('Location', '/admin/cms/events')->withStatus(302);
    }

    /**
     * Best-effort unlink of an orphaned event image under
     * public/uploads/events/. Path-traversal-safe: the supplied
     * relative path is rejected unless it points inside the events
     * upload directory (matches the safety contract of
     * handleImageUpload()).
     *
     * Callsites (keep the four in sync — they implement the
     * "file on disk must never outlive the DB reference" invariant):
     *   1. update() success branch with image replacement — unlinks the
     *      OLD path after the UPDATE commits.
     *   2. update() failure branch — unlinks the NEWLY-uploaded path
     *      because the UPDATE rolled back and the DB still points to
     *      the old file.
     *   3. update() validation-error short-circuit — unlinks the
     *      NEWLY-uploaded path because we redirect before writing the
     *      DB row.
     *   4. delete() success branch — unlinks the path captured BEFORE
     *      the DELETE, now that the row is gone.
     */
    private function deleteUploadedImageFile(?string $relativePath): void
    {
        if ($relativePath === null || $relativePath === '') {
            return;
        }
        // Must look like a value produced by handleImageUpload().
        if (strpos($relativePath, '/uploads/events/') !== 0) {
            return;
        }
        $baseDir = realpath(__DIR__ . '/../../public/uploads/events');
        if ($baseDir === false) {
            return;
        }
        $candidate = __DIR__ . '/../../public' . $relativePath;
        $realCandidate = realpath($candidate);
        if ($realCandidate === false) {
            return;
        }
        // Confirm the resolved path is genuinely inside the events
        // directory — defends against symlink shenanigans.
        if (strpos($realCandidate, $baseDir . DIRECTORY_SEPARATOR) !== 0) {
            return;
        }
        @unlink($realCandidate);
    }

    /**
     * Delete an event (POST only, CSRF validated by middleware)
     */
    public function delete(Request $request, Response $response, \mysqli $db, array $args): Response
    {
        // CSRF validated by CsrfMiddleware (POST request)
        $db->set_charset('utf8mb4');

        $id = (int)($args['id'] ?? 0);

        // Capture the featured_image path BEFORE the DELETE so we can
        // unlink it after the row is gone. Mirrors the update() lifecycle:
        // the file on disk must never outlive the DB reference.
        $oldImagePath = null;
        $lookupStmt = $db->prepare("SELECT featured_image FROM events WHERE id = ?");
        if ($lookupStmt !== false) {
            $lookupStmt->bind_param('i', $id);
            $lookupStmt->execute();
            $lookupResult = $lookupStmt->get_result();
            $lookupRow = $lookupResult ? $lookupResult->fetch_assoc() : null;
            $lookupStmt->close();
            if (is_array($lookupRow) && !empty($lookupRow['featured_image'])) {
                $oldImagePath = (string) $lookupRow['featured_image'];
            }
        }

        // Delete event
        $stmt = $db->prepare("DELETE FROM events WHERE id = ?");
        $stmt->bind_param('i', $id);

        if ($stmt->execute()) {
            $_SESSION['success_message'] = __('Evento eliminato con successo!');
            // Row is gone — unlink the now-orphaned image. Uses the
            // same path-traversal-safe helper as update().
            if ($oldImagePath !== null) {
                $this->deleteUploadedImageFile($oldImagePath);
            }
        } else {
            $_SESSION['error_message'] = __('Errore durante l\'eliminazione dell\'evento.');
        }
        $stmt->close();

        return $response->withHeader('Location', '/admin/cms/events')->withStatus(302);
    }

    /**
     * Handle image upload for events
     */
    private function handleImageUpload($uploadedFile): array
    {
        $filename = $uploadedFile->getClientFilename();
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // SECURITY: Validate file extension
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($extension, $allowedExtensions)) {
            return ['success' => false, 'message' => __('Formato immagine non supportato. Usa JPG, PNG o WebP.')];
        }

        // SECURITY: Validate file size (max 5MB)
        if ($uploadedFile->getSize() > 5 * 1024 * 1024) {
            return ['success' => false, 'message' => __('L\'immagine è troppo grande. Max 5MB.')];
        }

        // SECURITY: Validate MIME type
        $tmpPath = $uploadedFile->getStream()->getMetadata('uri');
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpPath);

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mimeType, $allowedMimes)) {
            return ['success' => false, 'message' => __('Tipo di file non valido.')];
        }

        // SECURITY: Secure path handling
        $baseDir = realpath(__DIR__ . '/../../public/uploads');
        if ($baseDir === false) {
            error_log("Upload base directory not found");
            return ['success' => false, 'message' => __('Errore di configurazione.')];
        }

        $targetDir = $baseDir . '/events';

        // Create directory if it doesn't exist
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        // SECURITY: Generate secure random filename
        try {
            $randomSuffix = bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            error_log("random_bytes() failed");
            return ['success' => false, 'message' => __('Errore di sistema.')];
        }

        $newFilename = 'event_' . date('Ymd_His') . '_' . $randomSuffix . '.' . $extension;
        $newFilename = str_replace("\0", '', $newFilename);
        $uploadPath = $targetDir . '/' . basename($newFilename);

        // SECURITY: Verify final path
        $realUploadPath = realpath(dirname($uploadPath));
        if ($realUploadPath === false || strpos($realUploadPath, $baseDir) !== 0) {
            error_log("Path traversal attempt detected");
            return ['success' => false, 'message' => __('Percorso non valido.')];
        }

        try {
            $uploadedFile->moveTo($uploadPath);
            @chmod($uploadPath, 0644);
            return ['success' => true, 'path' => '/uploads/events/' . $newFilename];
        } catch (\Throwable $e) {
            error_log("Image upload error: " . $e->getMessage());
            return ['success' => false, 'message' => __('Errore durante l\'upload.')];
        }
    }

    /**
     * Generate unique slug from title
     */
    private function generateSlug(string $title, \mysqli $db, ?int $excludeId = null): string
    {
        // Transliterate Unicode → ASCII before lowercasing.
        // Prefer intl Transliterator (handles all Unicode correctly),
        // fall back to iconv (buggy on macOS with some sequences)
        if (class_exists('Transliterator')) {
            $t = \Transliterator::create('Any-Latin; Latin-ASCII');
            $slug = ($t !== null) ? ($t->transliterate($title) ?: $title) : $title;
        } else {
            $slug = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
            if ($slug === false) {
                $slug = $title;
            }
        }
        $slug = strtolower($slug);

        // Remove special characters
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);

        // Replace multiple spaces/hyphens with single hyphen
        $slug = preg_replace('/[\s-]+/', '-', $slug);

        // Trim hyphens from ends
        $slug = trim($slug, '-');

        // Ensure uniqueness
        $originalSlug = $slug;
        $counter = 1;

        while (true) {
            if ($excludeId) {
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM events WHERE slug = ? AND id != ?");
                $stmt->bind_param('si', $slug, $excludeId);
            } else {
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM events WHERE slug = ?");
                $stmt->bind_param('s', $slug);
            }

            $stmt->execute();
            $result = $stmt->get_result();
            $count = $result->fetch_assoc()['count'];
            $stmt->close();

            if ($count == 0) {
                break;
            }

            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
