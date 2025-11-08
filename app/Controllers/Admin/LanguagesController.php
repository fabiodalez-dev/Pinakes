<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Language;
use App\Support\CsrfHelper;
use App\Support\HtmlHelper;
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

        $data = $request->getParsedBody();
        $languageModel = new Language($db);

        // Validate required fields
        $errors = [];

        if (empty($data['code'])) {
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
            $uploadedFile = $_FILES['translation_json'];

            // Validate JSON file
            if ($uploadedFile['type'] !== 'application/json' && pathinfo($uploadedFile['name'], PATHINFO_EXTENSION) !== 'json') {
                $errors[] = __("Il file deve essere un JSON valido");
            } else {
                // Verify JSON is valid
                $jsonContent = file_get_contents($uploadedFile['tmp_name']);
                $decoded = json_decode($jsonContent, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $errors[] = __("Il file JSON non è valido:") . " " . json_last_error_msg();
                } else {
                    // Move uploaded file to locale directory
                    $targetPath = __DIR__ . '/../../../locale/' . $data['code'] . '.json';
                    if (move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
                        $translationFile = 'locale/' . $data['code'] . '.json';

                        // Calculate translation stats
                        $totalKeys = count($decoded);
                        $translatedKeys = count(array_filter($decoded, fn($v) => !empty($v)));

                        $data['translation_file'] = $translationFile;
                        $data['total_keys'] = $totalKeys;
                        $data['translated_keys'] = $translatedKeys;
                    } else {
                        $errors[] = __("Errore nel caricamento del file JSON");
                    }
                }
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
        $code = $args['code'] ?? '';
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

        $code = $args['code'] ?? '';
        $data = $request->getParsedBody();
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
            $uploadedFile = $_FILES['translation_json'];

            // Validate JSON file
            if ($uploadedFile['type'] !== 'application/json' && pathinfo($uploadedFile['name'], PATHINFO_EXTENSION) !== 'json') {
                $errors[] = __("Il file deve essere un JSON valido");
            } else {
                // Verify JSON is valid
                $jsonContent = file_get_contents($uploadedFile['tmp_name']);
                $decoded = json_decode($jsonContent, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $errors[] = __("Il file JSON non è valido:") . " " . json_last_error_msg();
                } else {
                    // Move uploaded file to locale directory
                    $targetPath = __DIR__ . '/../../../locale/' . $code . '.json';

                    // Backup existing file if it exists
                    if (file_exists($targetPath)) {
                        copy($targetPath, $targetPath . '.backup.' . time());
                    }

                    if (move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
                        $data['translation_file'] = 'locale/' . $code . '.json';

                        // Calculate translation stats
                        $totalKeys = count($decoded);
                        $translatedKeys = count(array_filter($decoded, fn($v) => !empty($v)));

                        $data['total_keys'] = $totalKeys;
                        $data['translated_keys'] = $translatedKeys;
                    } else {
                        $errors[] = __("Errore nel caricamento del file JSON");
                    }
                }
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

        $code = $args['code'] ?? '';
        $languageModel = new Language($db);

        try {
            $languageModel->delete($code);

            // Optionally delete translation file
            $translationFile = __DIR__ . '/../../../locale/' . $code . '.json';
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

        $code = $args['code'] ?? '';
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

        $code = $args['code'] ?? '';
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
            $translationFile = __DIR__ . '/../../../' . $lang['translation_file'];

            if (!empty($lang['translation_file']) && file_exists($translationFile)) {
                $jsonContent = file_get_contents($translationFile);
                $decoded = json_decode($jsonContent, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $totalKeys = count($decoded);
                    $translatedKeys = count(array_filter($decoded, fn($v) => !empty($v)));

                    try {
                        $languageModel->updateStats($lang['code'], $totalKeys, $translatedKeys);
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
}
