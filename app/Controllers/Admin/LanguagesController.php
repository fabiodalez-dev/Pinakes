<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Language;
use App\Models\SettingsRepository;
use App\Support\ConfigStore;
use App\Support\HtmlHelper;
use App\Support\I18n;
use App\Support\SecureLogger;
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
        // Stats derived live from the Italian source (see Language::getAllWithDerivedStats)
        // so total_keys is the same source-derived denominator for every locale and can't drift.
        $languages = $languageModel->getAllWithDerivedStats();

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
        // CSRF validated by CsrfMiddleware

        $data = $request->getParsedBody() ?? [];
        $data['code'] = I18n::normalizeLocaleCode((string) ($data['code'] ?? ''));
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

        // Bail on an invalid locale code BEFORE reaching the upload handler:
        // the code becomes part of the target file path, so an invalid code
        // must never be used to build/write a translation file (path traversal).
        if (empty($data['code']) || !I18n::isValidLocaleCode($data['code'])) {
            $_SESSION['flash_error'] = implode('<br>', $errors);
            return $response
                ->withHeader('Location', '/admin/languages/create')
                ->withStatus(302);
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
        } catch (\Throwable $e) {
            SecureLogger::error('LanguagesController::store error: ' . $e->getMessage());
            $_SESSION['flash_error'] = __("Errore nella creazione della lingua.");
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
        // Derive the completion stats from the Italian source (same as the list
        // view) so the edit page can't show a different denominator (#303 review).
        $language = $languageModel->getByCodeWithDerivedStats($code);

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
        // CSRF validated by CsrfMiddleware

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
        } catch (\Throwable $e) {
            SecureLogger::error('LanguagesController::update error: ' . $e->getMessage());
            $_SESSION['flash_error'] = __("Errore nell'aggiornamento della lingua.");
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
        // CSRF validated by CsrfMiddleware

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

            // Delete both translation files: the shipped base and the admin
            // override, so a deleted language leaves no lingering override that
            // would resurface if the same code is re-added later. The DB record is
            // already gone, so a failed unlink cannot be rolled back — surface it
            // as a warning (never a silent success) instead of claiming the files
            // are cleaned up when a residual file could resurrect stale strings.
            $residual = [];
            foreach ([$this->getLocaleFilePath($code), $this->getOverrideFilePath($code)] as $translationFile) {
                if (file_exists($translationFile)) {
                    copy($translationFile, $translationFile . '.deleted.' . time());
                    if (!unlink($translationFile)) {
                        $residual[] = basename($translationFile);
                    }
                }
            }

            if ($residual !== []) {
                SecureLogger::error('LanguagesController::delete could not remove translation file(s): ' . implode(', ', $residual));
                $_SESSION['flash_warning'] = __("Lingua eliminata, ma un file di traduzione non è stato rimosso: potrebbe riapparire se ricrei la lingua con lo stesso codice.");
            } else {
                $_SESSION['flash_success'] = __("Lingua eliminata con successo");
            }
        } catch (\Throwable $e) {
            SecureLogger::error('LanguagesController::delete error: ' . $e->getMessage());
            $_SESSION['flash_error'] = __("Errore nell'eliminazione della lingua.");
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
        // CSRF validated by CsrfMiddleware

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
        } catch (\Throwable $e) {
            SecureLogger::error('LanguagesController::toggleActive error: ' . $e->getMessage());
            $_SESSION['flash_error'] = __("Errore nell'operazione.");
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
        // CSRF validated by CsrfMiddleware

        $code = $this->normalizeRouteLocale($args['code'] ?? null);
        if ($code === null) {
            $_SESSION['flash_error'] = __("Codice lingua non valido");
            return $response
                ->withHeader('Location', '/admin/languages')
                ->withStatus(302);
        }
        $languageModel = new Language($db);

        try {
            // Capture previous active state so we can inform the user when an
            // inactive language is re-activated by being promoted to default.
            $previous = $languageModel->getByCode($code);
            $wasInactive = ($previous !== null && (int)($previous['is_active'] ?? 0) === 0);

            // Capture the outgoing default BEFORE the flip so the propagation can
            // move only the accounts still on it (preserving per-user choices).
            $previousDefault = null;
            $prevDefaultRes = $db->query("SELECT code FROM `languages` WHERE is_default = 1 LIMIT 1");
            if ($prevDefaultRes instanceof \mysqli_result) {
                $prevDefaultRow = $prevDefaultRes->fetch_assoc();
                $previousDefault = is_array($prevDefaultRow) ? ($prevDefaultRow['code'] ?? null) : null;
                $prevDefaultRes->free();
            }

            $languageModel->setDefault($code);
            $this->synchronizeGlobalLocale($db, $code, $previousDefault);
            $_SESSION['flash_success'] = __("Lingua predefinita impostata con successo");

            if ($wasInactive) {
                $_SESSION['flash_info'] = sprintf(__('Language %s has been activated automatically'), $code);
            }
        } catch (\Throwable $e) {
            SecureLogger::error('LanguagesController::setDefault error: ' . $e->getMessage());
            $_SESSION['flash_error'] = __("Errore nell'operazione.");
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
        // CSRF validated by CsrfMiddleware

        $languageModel = new Language($db);
        $languages = $languageModel->getAll();

        $updated = 0;
        $errors = [];
        $canonical = $this->loadCanonicalTranslations();

        foreach ($languages as $lang) {
            $code = I18n::normalizeLocaleCode((string) $lang['code']);
            if (!I18n::isValidLocaleCode($code)) {
                continue;
            }

            // Count from the EFFECTIVE catalogue (shipped base merged with the
            // admin override) so an override-only language (a custom locale whose
            // first upload lives only under locale/overrides/) is counted too.
            $sanitized = $this->getEffectiveTranslations($code);

            if (!empty($sanitized)) {
                $totalKeys = count($canonical);
                $translatedKeys = count(array_filter(
                    array_intersect_key($sanitized, $canonical),
                    static fn(string $value): bool => trim($value) !== ''
                ));

                try {
                    $languageModel->updateStats($code, $totalKeys, $translatedKeys);
                    $updated++;
                } catch (\Throwable $e) {
                    $errors[] = $lang['code'] . ': ' . $e->getMessage();
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

        // Export the EFFECTIVE translations (shipped base merged with the admin
        // override) so a re-download reflects what the app actually serves and a
        // re-upload never silently drops prior overrides. Accept the language when
        // EITHER the base or the override exists — an override-only custom locale
        // (whose first upload lives only under locale/overrides/) is valid too.
        if (!file_exists($this->getLocaleFilePath($code)) && !file_exists($this->getOverrideFilePath($code))) {
            $_SESSION['flash_error'] = __("File di traduzione non trovato");
            return $response
                ->withHeader('Location', '/admin/languages')
                ->withStatus(302);
        }

        // Every current application key is present; missing translations are
        // empty; custom extra keys remain at the end for backwards compatibility.
        $translations = $this->getEffectiveTranslations($code);
        $complete = [];
        foreach ($this->loadCanonicalTranslations() as $key => $_default) {
            // getEffectiveTranslations() guarantees string values (sanitized).
            $complete[$key] = $translations[$key] ?? '';
        }
        foreach (array_diff_key($translations, $complete) as $key => $value) {
            $complete[$key] = $value;
        }
        $prettyJson = json_encode($complete, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Set headers for download
        $filename = $code . '.json';
        $response = $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) strlen($prettyJson))
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0');

        $response->getBody()->write($prettyJson);
        return $response;
    }

    /** @return array<string, string> */
    private function loadCanonicalTranslations(): array
    {
        $path = $this->getLocaleFilePath('it_IT');
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        return $this->sanitizeTranslations(is_array($decoded) ? $decoded : []);
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

    /**
     * Path of the admin OVERRIDE file for a locale: locale/overrides/{code}.json.
     * This directory is never re-seeded by the Docker entrypoint, so in-app edits
     * of a shipped locale persist across image upgrades (see I18n::loadTranslations,
     * which merges this on top of the base). Creates the subdirectory on demand.
     */
    private function getOverrideFilePath(string $code): string
    {
        $baseDir = realpath(__DIR__ . '/../../../locale');
        if ($baseDir === false) {
            $baseDir = __DIR__ . '/../../../locale';
        }
        $overrideDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'overrides';
        if (!is_dir($overrideDir)) {
            mkdir($overrideDir, 0755, true);
        }
        return $overrideDir . DIRECTORY_SEPARATOR . $code . '.json';
    }

    /**
     * Effective translations for a locale as the app sees them at runtime:
     * shipped base merged with the admin override (override wins). Used for the
     * download/export and for accurate key counts.
     *
     * @return array<string, string>
     */
    private function getEffectiveTranslations(string $code): array
    {
        $result = [];
        $base = $this->getLocaleFilePath($code);
        if (is_file($base)) {
            $decoded = json_decode((string) file_get_contents($base), true);
            if (is_array($decoded)) {
                $result = $decoded;
            }
        }
        $override = $this->getOverrideFilePath($code);
        if (is_file($override)) {
            $decoded = json_decode((string) file_get_contents($override), true);
            if (is_array($decoded)) {
                $result = array_merge($result, $decoded);
            }
        }
        return $this->sanitizeTranslations($result);
    }

    private function sanitizeTranslations(array $translations): array
    {
        $sanitized = [];

        foreach ($translations as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $scalarValue = is_scalar($value) ? (string) $value : '';
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

        // The locale code becomes part of the target file path — reject any
        // code that is not a strict xx_YY locale BEFORE touching the filesystem
        // (prevents arbitrary .json write via "../" traversal).
        if (!I18n::isValidLocaleCode($code)) {
            return ['success' => false, 'error' => __("Codice lingua non valido")];
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
        // Write the edit to the non-reseeded override layer (locale/overrides/)
        // instead of the shipped base, so it survives a Docker image re-seed.
        // getOverrideFilePath() has already created the subdirectory.
        $targetPath = $this->getOverrideFilePath($code);

        // Defense in depth: the resolved file must live directly inside the
        // locale/overrides directory. realpath() can't resolve a not-yet-created
        // file, so validate the DIRNAME against the override base and the basename.
        $overrideDir = realpath(__DIR__ . '/../../../locale/overrides');
        $targetDir = realpath(dirname($targetPath));
        if ($overrideDir === false || $targetDir === false
            || $targetDir !== $overrideDir
            || basename($targetPath) !== $code . '.json') {
            return ['success' => false, 'error' => __("Percorso file non valido")];
        }

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

    private function synchronizeGlobalLocale(\mysqli $db, string $code, ?string $previousDefault = null): void
    {
        $normalized = I18n::normalizeLocaleCode($code);
        if (!I18n::isValidLocaleCode($normalized)) {
            return;
        }

        try {
            $settingsRepo = new SettingsRepository($db);
            $settingsRepo->set('app', 'locale', $normalized);
        } catch (\Throwable $e) {
            SecureLogger::error('LanguagesController: Unable to save locale in system_settings: ' . $e->getMessage());
        }

        try {
            ConfigStore::set('app.locale', $normalized);
        } catch (\Throwable $e) {
            SecureLogger::error('LanguagesController: Unable to save locale in settings store: ' . $e->getMessage());
        }

        $this->updateEnvLocale($normalized);

        I18n::setLocale($normalized);
        $_SESSION['locale'] = $normalized;

        // Move ONLY accounts still sitting on the previous default (i.e. users
        // who never customised their language) to the new default. A deliberate
        // per-user choice made via the language switcher/profile (#238, Option B)
        // must be preserved — the old unscoped `UPDATE utenti SET locale = ?`
        // wiped every account. When the previous default is unknown, touch
        // nothing rather than overwriting everyone.
        $previousNormalized = ($previousDefault !== null && $previousDefault !== '')
            ? I18n::normalizeLocaleCode($previousDefault)
            : null;
        if ($previousNormalized !== null && $previousNormalized !== $normalized) {
            try {
                $stmt = $db->prepare("UPDATE utenti SET locale = ? WHERE locale = ?");
                $stmt->bind_param('ss', $normalized, $previousNormalized);
                $stmt->execute();
                $stmt->close();
            } catch (\Throwable $e) {
                SecureLogger::error('LanguagesController: Unable to move previous-default users to the new locale: ' . $e->getMessage());
            }
        }
    }

    private function updateEnvLocale(string $locale): void
    {
        $envPath = dirname(__DIR__, 3) . '/.env';
        if (!is_file($envPath) || !is_readable($envPath) || !is_writable($envPath)) {
            return;
        }

        $contents = file_get_contents($envPath);
        if ($contents === false) {
            return;
        }

        if (preg_match('/^APP_LOCALE=.*$/m', $contents)) {
            $contents = preg_replace('/^APP_LOCALE=.*$/m', 'APP_LOCALE=' . $locale, $contents);
        } else {
            $contents = rtrim($contents) . PHP_EOL . 'APP_LOCALE=' . $locale . PHP_EOL;
        }

        file_put_contents($envPath, $contents);
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
        // CSRF validated by CsrfMiddleware

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
        } catch (\Throwable $e) {
            SecureLogger::error('LanguagesController::updateRoutes error: ' . $e->getMessage());
            $_SESSION['flash_error'] = __("Errore nell'aggiornamento delle route.");
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
