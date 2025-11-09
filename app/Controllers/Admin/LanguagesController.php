<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Language;
use App\Support\CsrfHelper;
use App\Support\HtmlHelper;
use App\Support\I18n;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin Languages Controller
 *
 * Manages multilingual support via admin UI:
 * - View all languages with translation stats
 * - Add/edit/delete languages
 * - Set default language
 * - Enable/disable languages
 * - Upload JSON translation files
 */
class LanguagesController
{
    /**
     * Display list of all languages
     *
     * GET /admin/languages
     */
    public function index(Request $request, Response $response, \mysqli $db, array $args): Response
    {
        $languageModel = new Language($db);
        $languages = $languageModel->getAll();

        // Render view content
        ob_start();
        require __DIR__ . '/../../Views/admin/languages/index.php';
        $content = ob_get_clean();

        // Render with admin layout
        ob_start();
        require __DIR__ . '/../../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Show form to create new language
     *
     * GET /admin/languages/create
     */
    public function create(Request $request, Response $response, \mysqli $db, array $args): Response
    {
        // Render view content
        ob_start();
        require __DIR__ . '/../../Views/admin/languages/create.php';
        $content = ob_get_clean();

        // Render with admin layout
        ob_start();
        require __DIR__ . '/../../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Store new language in database
     *
     * POST /admin/languages
     */
    public function store(Request $request, Response $response, \mysqli $db, array $args): Response
    {
        // CSRF validation
        if ($error = CsrfHelper::validateRequest($request, $response, '/admin/languages/create')) {
            return $error;
        }

        $data = $request->getParsedBody() ?? [];
        $data['code'] = I18n::normalizeLocaleCode((string)($data['code'] ?? ''));
        unset($data['translation_file']);
        $languageModel = new Language($db);

        // Validate required fields
        $errors = [];

        if (empty($data['code']) || !I18n::isValidLocaleCode($data['code'])) {
            $errors[] = __("Il codice lingua è obbligatorio (es. it_IT, en_US)");
        }

        if (empty($data['name'])) {
            $errors[] = __("Il nome inglese è obbligatorio (es. Italian, English)");
        }

        if (empty($data['native_name'])) {
            $errors[] = __("Il nome nativo è obbligatorio (es. Italiano, English)");
        }

        // Handle translation file upload
        $translationFile = null;
        if (isset($_FILES['translation_json']) && $_FILES['translation_json']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = $this->processTranslationUpload($_FILES['translation_json'], $data['code']);

            if (!$uploadResult['success']) {
                $errors[] = $uploadResult['error'];
            } else {
                $data['translation_file'] = $uploadResult['translation_file'];
                $data['total_keys'] = $uploadResult['total_keys'];
                $data['translated_keys'] = $uploadResult['translated_keys'];
            }
        }

        if (!empty($errors)) {
            $_SESSION['flash_error'] = implode('<br>', $errors);
            return $response
                ->withHeader('Location', '/admin/languages/create')
                ->withStatus(302);
        }

        try {
            // Set default/active flags
            $data['is_default'] = isset($data['is_default']) ? 1 : 0;
            $data['is_active'] = isset($data['is_active']) ? 1 : 0;

            $languageModel->create($data);

            $_SESSION['flash_success'] = __("Lingua creata con successo");
            return $response
                ->withHeader('Location', '/admin/languages')
                ->withStatus(302);
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = __("Errore nella creazione:") . " " . $e->getMessage();
            return $response
                ->withHeader('Location', '/admin/languages/create')
                ->withStatus(302);
        }
    }

    /**
     * Show form to edit existing language
     *
     * GET /admin/languages/{code}/edit
     */
    public function edit(Request $request, Response $response, \mysqli $db, array $args): Response
    {
        $code = $this->normalizeRouteLocale($args['code'] ?? null);
        if ($code === null) {
            $_SESSION['flash_error'] = __("Codice lingua non valido");
            return $response
                ->withHeader('Location', '/admin/languages')
                ->withStatus(302);
        }
        $languageModel = new Language($db);
        $language = $languageModel->getByCode($code);

        if (!$language) {
            $_SESSION['flash_error'] = __("Lingua non trovata");
            return $response
                ->withHeader('Location', '/admin/languages')
                ->withStatus(302);
        }

        // Render view content
        ob_start();
        require __DIR__ . '/../../Views/admin/languages/edit.php';
        $content = ob_get_clean();

        // Render with admin layout
        ob_start();
        require __DIR__ . '/../../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Update existing language
     *
     * POST /admin/languages/{code}
     */
    public function update(Request $request, Response $response, \mysqli $db, array $args): Response
    {
        // CSRF validation
        if ($error = CsrfHelper::validateRequest($request, $response, '/admin/languages')) {
            return $error;
        }

        $code = $this->normalizeRouteLocale($args['code'] ?? null);
        if ($code === null) {
            $_SESSION['flash_error'] = __("Codice lingua non valido");
            return $response
                ->withHeader('Location', '/admin/languages')
                ->withStatus(302);
        }

        $data = $request->getParsedBody() ?? [];
        unset($data['translation_file']);
        $languageModel = new Language($db);

        $language = $languageModel->getByCode($code);
        if (!$language) {
            $_SESSION['flash_error'] = __("Lingua non trovata");
            return $response
                ->withHeader('Location', '/admin/languages')
                ->withStatus(302);
        }

        // Validate required fields
        $errors = [];

        if (empty($data['name'])) {
            $errors[] = __("Il nome inglese è obbligatorio");
        }

        if (empty($data['native_name'])) {
            $errors[] = __("Il nome nativo è obbligatorio");
        }

        // Handle translation file upload
        if (isset($_FILES['translation_json']) && $_FILES['translation_json']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = $this->processTranslationUpload($_FILES['translation_json'], $code, true);

            if (!$uploadResult['success']) {
                $errors[] = $uploadResult['error'];
            } else {
                $data['translation_file'] = $uploadResult['translation_file'];
                $data['total_keys'] = $uploadResult['total_keys'];
                $data['translated_keys'] = $uploadResult['translated_keys'];
            }
        }

        if (!empty($errors)) {
            $_SESSION['flash_error'] = implode('<br>', $errors);
            return $response
                ->withHeader('Location', '/admin/languages/' . urlencode($code) . '/edit')
                ->withStatus(302);
        }

        try {
            // Set default/active flags
            $data['is_default'] = isset($data['is_default']) ? 1 : 0;
            $data['is_active'] = isset($data['is_active']) ? 1 : 0;

            $languageModel->update($code, $data);

            $_SESSION['flash_success'] = __("Lingua aggiornata con successo");
            return $response
                ->withHeader('Location', '/admin/languages')
                ->withStatus(302);
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = __("Errore nell'aggiornamento:") . " " . $e->getMessage();
            return $response
                ->withHeader('Location', '/admin/languages/' . urlencode($code) . '/edit')
                ->withStatus(302);
        }
    }

    /**
     * Delete language
     *
     * POST /admin/languages/{code}/delete
     */
    public function delete(Request $request, Response $response, \mysqli $db, array $args): Response
    {
        // CSRF validation
        if ($error = CsrfHelper::validateRequest($request, $response, '/admin/languages')) {
            return $error;
        }

        $code = $this->normalizeRouteLocale($args['code'] ?? null);
        if ($code === null) {
            $_SESSION['flash_error'] = __("Codice lingua non valido");
            return $response
                ->withHeader('Location', '/admin/languages')
                ->withStatus(302);
        }
        $languageModel = new Language($db);

        try {
            $languageModel->delete($code);

            // Optionally delete translation file
            $translationFile = $this->getLocaleFilePath($code);
            if (file_exists($translationFile)) {
                // Backup before deleting
                copy($translationFile, $translationFile . '.deleted.' . time());
                unlink($translationFile);
            }

            $_SESSION['flash_success'] = __("Lingua eliminata con successo");
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = __("Errore nell'eliminazione:") . " " . $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/admin/languages')
            ->withStatus(302);
    }

    /**
     * Toggle active status of language
     *
     * POST /admin/languages/{code}/toggle-active
     */
    public function toggleActive(Request $request, Response $response, \mysqli $db, array $args): Response
    {
        // CSRF validation
        if ($error = CsrfHelper::validateRequest($request, $response, '/admin/languages')) {
            return $error;
        }

        $code = $this->normalizeRouteLocale($args['code'] ?? null);
        if ($code === null) {
            $_SESSION['flash_error'] = __("Codice lingua non valido");
            return $response
                ->withHeader('Location', '/admin/languages')
                ->withStatus(302);
        }
        $languageModel = new Language($db);

        try {
            $newStatus = $languageModel->toggleActive($code);

            $statusText = $newStatus ? __("attivata") : __("disattivata");
            $_SESSION['flash_success'] = __("Lingua") . " " . $statusText . " " . __("con successo");
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = __("Errore nell'operazione:") . " " . $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/admin/languages')
            ->withStatus(302);
    }

    /**
     * Set language as default
     *
     * POST /admin/languages/{code}/set-default
     */
    public function setDefault(Request $request, Response $response, \mysqli $db, array $args): Response
    {
        // CSRF validation
        if ($error = CsrfHelper::validateRequest($request, $response, '/admin/languages')) {
            return $error;
        }

        // CRITICAL: Prevent changing default language after installation
        // The app is single-language - language is fixed at installation time
        // Changing default language would break routes and require .env update
        $installedFile = __DIR__ . '/../../../.installed';
        if (file_exists($installedFile)) {
            $_SESSION['flash_error'] = __("Impossibile cambiare la lingua predefinita dopo l'installazione.") . " " .
                __("La lingua dell'applicazione è fissa e determinata al momento dell'installazione.") . " " .
                __("Cambiare la lingua di default richiederebbe una reinstallazione completa dell'applicazione.");
            return $response
                ->withHeader('Location', '/admin/languages')
                ->withStatus(302);
        }

        $code = $this->normalizeRouteLocale($args['code'] ?? null);
        if ($code === null) {
            $_SESSION['flash_error'] = __("Codice lingua non valido");
            return $response
                ->withHeader('Location', '/admin/languages')
                ->withStatus(302);
        }
        $languageModel = new Language($db);

        try {
            $languageModel->setDefault($code);

            $_SESSION['flash_success'] = __("Lingua predefinita impostata con successo");
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = __("Errore nell'operazione:") . " " . $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/admin/languages')
            ->withStatus(302);
    }

    /**
     * Recalculate translation statistics for all languages
     *
     * POST /admin/languages/refresh-stats
     */
    public function refreshStats(Request $request, Response $response, \mysqli $db, array $args): Response
    {
        // CSRF validation
        if ($error = CsrfHelper::validateRequest($request, $response, '/admin/languages')) {
            return $error;
        }

        $languageModel = new Language($db);
        $languages = $languageModel->getAll();

        $updated = 0;
        $errors = [];

        foreach ($languages as $lang) {
            $code = I18n::normalizeLocaleCode((string)$lang['code']);
            if (!I18n::isValidLocaleCode($code)) {
                continue;
            }

            $translationFile = $this->getLocaleFilePath($code);

            if (file_exists($translationFile)) {
                $jsonContent = file_get_contents($translationFile);
                $decoded = json_decode($jsonContent, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $sanitized = $this->sanitizeTranslations($decoded);
                    $totalKeys = count($sanitized);
                    $translatedKeys = count(array_filter($sanitized, fn($v) => !empty($v)));

                    try {
                        $languageModel->updateStats($code, $totalKeys, $translatedKeys);
                        $updated++;
                    } catch (\Exception $e) {
                        $errors[] = $lang['code'] . ': ' . $e->getMessage();
                    }
                }
            }
        }

        if (empty($errors)) {
            $_SESSION['flash_success'] = __("Statistiche aggiornate per") . " $updated " . __("lingue");
        } else {
            $_SESSION['flash_warning'] = __("Statistiche aggiornate per") . " $updated " . __("lingue. Errori:") . " " . implode(', ', $errors);
        }

        return $response
            ->withHeader('Location', '/admin/languages')
            ->withStatus(302);
    }

    /**
     * Download JSON translation file for a language
     *
     * GET /admin/languages/{code}/download
     */
    public function download(Request $request, Response $response, \mysqli $db, array $args): Response
    {
        $code = $this->normalizeRouteLocale($args['code'] ?? null);
        if ($code === null) {
            $_SESSION['flash_error'] = __("Codice lingua non valido");
            return $response
                ->withHeader('Location', '/admin/languages')
                ->withStatus(302);
        }
        $languageModel = new Language($db);
        $language = $languageModel->getByCode($code);

        if (!$language) {
            $_SESSION['flash_error'] = __("Lingua non trovata");
            return $response
                ->withHeader('Location', '/admin/languages')
                ->withStatus(302);
        }

        // Get translation file path
        $translationFile = $this->getLocaleFilePath($code);

        if (!file_exists($translationFile)) {
            $_SESSION['flash_error'] = __("File di traduzione non trovato");
            return $response
                ->withHeader('Location', '/admin/languages')
                ->withStatus(302);
        }

        // Read JSON content
        $jsonContent = file_get_contents($translationFile);

        // Validate JSON
        $decoded = json_decode($jsonContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $_SESSION['flash_error'] = __("File JSON non valido");
            return $response
                ->withHeader('Location', '/admin/languages')
                ->withStatus(302);
        }

        // Pretty print JSON for download
        $prettyJson = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Set headers for download
        $filename = $code . '.json';
        $response = $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string)strlen($prettyJson))
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0');

        $response->getBody()->write($prettyJson);
        return $response;
    }

    private function normalizeRouteLocale($code): ?string
    {
        if (!is_string($code) || $code === '') {
            return null;
        }

        $normalized = I18n::normalizeLocaleCode($code);
        return I18n::isValidLocaleCode($normalized) ? $normalized : null;
    }

    private function getLocaleFilePath(string $code): string
    {
        $baseDir = realpath(__DIR__ . '/../../../locale');

        if ($baseDir === false) {
            $baseDir = __DIR__ . '/../../../locale';
            if (!is_dir($baseDir)) {
                mkdir($baseDir, 0755, true);
            }
        }

        return rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $code . '.json';
    }

    private function sanitizeTranslations(array $translations): array
    {
        $sanitized = [];

        foreach ($translations as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $scalarValue = is_scalar($value) ? (string)$value : '';
            $scalarValue = trim($scalarValue);
            $scalarValue = strip_tags($scalarValue);
            $sanitized[$key] = $scalarValue;
        }

        return $sanitized;
    }

    private function processTranslationUpload(array $uploadedFile, string $code, bool $backupExisting = false): array
    {
        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => __("Errore nel caricamento del file JSON")];
        }

        $extension = strtolower(pathinfo($uploadedFile['name'] ?? '', PATHINFO_EXTENSION));
        $mimeType = strtolower($uploadedFile['type'] ?? '');

        if ($mimeType !== 'application/json' && $extension !== 'json') {
            return ['success' => false, 'error' => __("Il file deve essere un JSON valido")];
        }

        if (empty($uploadedFile['tmp_name']) || !is_uploaded_file($uploadedFile['tmp_name'])) {
            return ['success' => false, 'error' => __("Upload non valido")];
        }

        $jsonContent = file_get_contents($uploadedFile['tmp_name']);
        $decoded = json_decode($jsonContent, true);

        if (!is_array($decoded)) {
            return ['success' => false, 'error' => __("Il file JSON non è valido:") . ' ' . json_last_error_msg()];
        }

        $sanitized = $this->sanitizeTranslations($decoded);
        $targetPath = $this->getLocaleFilePath($code);

        if ($backupExisting && file_exists($targetPath)) {
            copy($targetPath, $targetPath . '.backup.' . time());
        }

        $jsonToSave = json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($targetPath, $jsonToSave, LOCK_EX) === false) {
            return ['success' => false, 'error' => __("Errore nel salvataggio del file di traduzione")];
        }

        $totalKeys = count($sanitized);
        $translatedKeys = count(array_filter($sanitized, fn($value) => $value !== ''));

        return [
            'success' => true,
            'translation_file' => 'locale/' . $code . '.json',
            'total_keys' => $totalKeys,
            'translated_keys' => $translatedKeys,
        ];
    }

    /**
     * Show form to edit route translations for a language
     *
     * GET /admin/languages/{code}/edit-routes
     */
    public function editRoutes(Request $request, Response $response, \mysqli $db, array $args): Response
    {
        $code = $this->normalizeRouteLocale($args['code'] ?? null);
        if ($code === null) {
            $_SESSION['flash_error'] = __("Codice lingua non valido");
            return $response
                ->withHeader('Location', '/admin/languages')
                ->withStatus(302);
        }

        $languageModel = new Language($db);
        $language = $languageModel->getByCode($code);

        if (!$language) {
            $_SESSION['flash_error'] = __("Lingua non trovata");
            return $response
                ->withHeader('Location', '/admin/languages')
                ->withStatus(302);
        }

        // Load current routes for this language
        $routesFilePath = $this->getRoutesFilePath($code);
        $routes = [];

        if (file_exists($routesFilePath)) {
            $jsonContent = file_get_contents($routesFilePath);
            $decoded = json_decode($jsonContent, true);
            if (is_array($decoded)) {
                $routes = $decoded;
            }
        }

        // Get available route keys from RouteTranslator
        $availableKeys = \App\Support\RouteTranslator::getAvailableKeys();

        // Merge with fallback routes to ensure all keys are present
        foreach ($availableKeys as $key) {
            if (!isset($routes[$key])) {
                $routes[$key] = \App\Support\RouteTranslator::route($key);
            }
        }

        // Sort by key
        ksort($routes);

        // Render view content
        ob_start();
        require __DIR__ . '/../../Views/admin/languages/edit-routes.php';
        $content = ob_get_clean();

        // Render with admin layout
        ob_start();
        require __DIR__ . '/../../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Update route translations for a language
     *
     * POST /admin/languages/{code}/update-routes
     */
    public function updateRoutes(Request $request, Response $response, \mysqli $db, array $args): Response
    {
        // CSRF validation
        if ($error = CsrfHelper::validateRequest($request, $response, '/admin/languages')) {
            return $error;
        }

        $code = $this->normalizeRouteLocale($args['code'] ?? null);
        if ($code === null) {
            $_SESSION['flash_error'] = __("Codice lingua non valido");
            return $response
                ->withHeader('Location', '/admin/languages')
                ->withStatus(302);
        }

        $languageModel = new Language($db);
        $language = $languageModel->getByCode($code);

        if (!$language) {
            $_SESSION['flash_error'] = __("Lingua non trovata");
            return $response
                ->withHeader('Location', '/admin/languages')
                ->withStatus(302);
        }

        $data = $request->getParsedBody() ?? [];
        $routes = $data['routes'] ?? [];

        // Validate routes
        $errors = [];
        $validatedRoutes = [];

        foreach ($routes as $key => $value) {
            // Ensure value is a string and starts with /
            if (!is_string($value) || trim($value) === '') {
                $errors[] = __("La route") . " '$key' " . __("non può essere vuota");
                continue;
            }

            $trimmedValue = trim($value);

            if (!str_starts_with($trimmedValue, '/')) {
                $errors[] = __("La route") . " '$key' " . __("deve iniziare con") . " '/'";
                continue;
            }

            // No spaces allowed
            if (preg_match('/\s/', $trimmedValue)) {
                $errors[] = __("La route") . " '$key' " . __("non può contenere spazi");
                continue;
            }

            $validatedRoutes[$key] = $trimmedValue;
        }

        if (!empty($errors)) {
            $_SESSION['flash_error'] = implode('<br>', $errors);
            return $response
                ->withHeader('Location', '/admin/languages/' . urlencode($code) . '/edit-routes')
                ->withStatus(302);
        }

        try {
            // Backup existing routes file
            $routesFilePath = $this->getRoutesFilePath($code);
            if (file_exists($routesFilePath)) {
                copy($routesFilePath, $routesFilePath . '.backup.' . time());
            }

            // Save routes to JSON file
            $jsonToSave = json_encode($validatedRoutes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (file_put_contents($routesFilePath, $jsonToSave, LOCK_EX) === false) {
                throw new \Exception(__("Errore nel salvataggio del file route"));
            }

            // Clear RouteTranslator cache
            \App\Support\RouteTranslator::clearCache();

            $_SESSION['flash_success'] = __("Route aggiornate con successo");
            return $response
                ->withHeader('Location', '/admin/languages/' . urlencode($code) . '/edit-routes')
                ->withStatus(302);
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = __("Errore nell'aggiornamento:") . " " . $e->getMessage();
            return $response
                ->withHeader('Location', '/admin/languages/' . urlencode($code) . '/edit-routes')
                ->withStatus(302);
        }
    }

    /**
     * Get file path for routes JSON
     *
     * @param string $code Locale code
     * @return string File path
     */
    private function getRoutesFilePath(string $code): string
    {
        $baseDir = realpath(__DIR__ . '/../../../locale');

        if ($baseDir === false) {
            $baseDir = __DIR__ . '/../../../locale';
            if (!is_dir($baseDir)) {
                mkdir($baseDir, 0755, true);
            }
        }

        return rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'routes_' . $code . '.json';
    }
}
