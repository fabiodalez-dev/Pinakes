<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\SettingsRepository;
use App\Support\ConfigStore;
use App\Support\ContentSanitizer;
use App\Support\Csrf;
use App\Support\CsrfHelper;
use App\Support\SettingsMailTemplates;
use App\Support\SitemapGenerator;
use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SettingsController
{
    public function index(Request $request, Response $response, mysqli $db): Response
    {
        $repository = new SettingsRepository($db);
        $repository->ensureTables();
        $repository->ensureEmailTemplates($this->templateDefaults());

        $appSettings = $this->resolveAppSettings($repository);
        $emailSettings = $this->resolveEmailSettings($repository);
        $templates = $this->resolveEmailTemplates($repository);
        $contactSettings = $this->resolveContactSettings($repository);
        $privacySettings = $this->resolvePrivacySettings($repository);
        $labelSettings = $this->resolveLabelSettings($repository);
        $advancedSettings = $this->resolveAdvancedSettings($repository);
        $contactMessages = $this->loadContactMessages($db);
        $cookieBannerTexts = $this->resolveCookieBannerTexts($repository);

        $queryParams = $request->getQueryParams();
        $activeTab = $queryParams['tab'] ?? 'general';

        ob_start();
        $data = compact(
            'appSettings',
            'emailSettings',
            'templates',
            'contactSettings',
            'privacySettings',
            'labelSettings',
            'advancedSettings',
            'contactMessages',
            'activeTab',
            'db',
            'cookieBannerTexts'
        );
        require __DIR__ . '/../Views/settings/index.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function updateGeneral(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array)$request->getParsedBody();
        if (!Csrf::validate($data['csrf_token'] ?? null)) {
            $_SESSION['error_message'] = __('Token CSRF non valido. Riprova.');
            return $this->redirect($response, '/admin/settings?tab=general');
        }

        $repository = new SettingsRepository($db);
        $repository->ensureTables();

        $appName = trim((string)($data['app_name'] ?? ''));
        if ($appName === '') {
            $appName = __('Biblioteca');
        }
        $repository->set('app', 'name', $appName);
        ConfigStore::set('app.name', $appName);

        $logoPath = $repository->get('app', 'logo_path', ConfigStore::get('app.logo', ''));
        $removeLogo = (string)($data['remove_logo'] ?? '') === '1';
        if ($removeLogo) {
            if ($logoPath !== '') {
                $absoluteLogoPath = dirname(__DIR__, 2) . '/public' . $logoPath;
                if (is_file($absoluteLogoPath)) {
                    @unlink($absoluteLogoPath);
                }
            }
            $repository->delete('app', 'logo_path');
            ConfigStore::set('app.logo', '');
            $logoPath = '';
        }

        if (isset($_FILES['app_logo']) && is_array($_FILES['app_logo']) && ($_FILES['app_logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $uploadResult = $this->handleLogoUpload($_FILES['app_logo']);
            if ($uploadResult['success']) {
                $logoPath = $uploadResult['path'];
                $repository->set('app', 'logo_path', $logoPath);
                ConfigStore::set('app.logo', $logoPath);
            } else {
                $_SESSION['error_message'] = $uploadResult['message'];
                return $this->redirect($response, '/admin/settings?tab=general');
            }
        } elseif (isset($_FILES['app_logo']) && is_array($_FILES['app_logo']) && ($_FILES['app_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $_SESSION['error_message'] = __('Caricamento del logo non riuscito. Verifica le dimensioni e il formato del file.');
            return $this->redirect($response, '/admin/settings?tab=general');
        } elseif (!$removeLogo && $logoPath !== '') {
            // Ensure ConfigStore reflects DB stored logo when no upload occurs AND logo was not removed
            ConfigStore::set('app.logo', $logoPath);
        }

        // Save footer description
        $footerDescription = trim((string)($data['footer_description'] ?? ''));
        if ($footerDescription !== '') {
            $repository->set('app', 'footer_description', $footerDescription);
            ConfigStore::set('app.footer_description', $footerDescription);
        }

        // Save social media links
        $socialLinks = [
            'social_facebook' => trim((string)($data['social_facebook'] ?? '')),
            'social_twitter' => trim((string)($data['social_twitter'] ?? '')),
            'social_instagram' => trim((string)($data['social_instagram'] ?? '')),
            'social_linkedin' => trim((string)($data['social_linkedin'] ?? '')),
            'social_bluesky' => trim((string)($data['social_bluesky'] ?? '')),
        ];

        foreach ($socialLinks as $key => $value) {
            $repository->set('app', $key, $value);
            ConfigStore::set("app.{$key}", $value);
        }

        $_SESSION['success_message'] = __('Impostazioni generali aggiornate correttamente.');
        return $this->redirect($response, '/admin/settings?tab=general');
    }

    public function updateEmailSettings(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array)$request->getParsedBody();
        if (!Csrf::validate($data['csrf_token'] ?? null)) {
            $_SESSION['error_message'] = __('Token CSRF non valido. Riprova.');
            return $this->redirect($response, '/admin/settings?tab=email');
        }

        $repository = new SettingsRepository($db);
        $repository->ensureTables();

        $driver = (string)($data['mail_driver'] ?? 'mail');
        $allowedDrivers = ['mail', 'smtp', 'phpmailer'];
        if (!in_array($driver, $allowedDrivers, true)) {
            $driver = 'mail';
        }

        $fromEmail = trim((string)($data['from_email'] ?? ''));
        $fromName = trim((string)($data['from_name'] ?? ''));
        $smtpHost = trim((string)($data['smtp_host'] ?? ''));
        $smtpPort = (string)max(1, (int)($data['smtp_port'] ?? 587));
        $smtpUser = trim((string)($data['smtp_username'] ?? ''));
        $smtpPass = (string)($data['smtp_password'] ?? '');
        $encryption = (string)($data['smtp_encryption'] ?? 'tls');
        $allowedEncryption = ['tls', 'ssl', 'none'];
        if (!in_array($encryption, $allowedEncryption, true)) {
            $encryption = 'tls';
        }

        $repository->set('email', 'driver_mode', $driver);
        $repository->set('email', 'type', $driver === 'phpmailer' ? 'mail' : $driver);
        $repository->set('email', 'from_email', $fromEmail);
        $repository->set('email', 'from_name', $fromName);
        $repository->set('email', 'smtp_host', $smtpHost);
        $repository->set('email', 'smtp_port', $smtpPort);
        $repository->set('email', 'smtp_username', $smtpUser);
        $repository->set('email', 'smtp_password', $smtpPass);
        $repository->set('email', 'smtp_security', $encryption);

        ConfigStore::set('mail.driver', $driver);
        ConfigStore::set('mail.from_email', $fromEmail);
        ConfigStore::set('mail.from_name', $fromName);
        ConfigStore::set('mail.smtp.host', $smtpHost);
        ConfigStore::set('mail.smtp.port', (int)$smtpPort);
        ConfigStore::set('mail.smtp.username', $smtpUser);
        ConfigStore::set('mail.smtp.password', $smtpPass);
        ConfigStore::set('mail.smtp.encryption', $encryption);

        $_SESSION['success_message'] = __('Impostazioni email aggiornate correttamente.');
        return $this->redirect($response, '/admin/settings?tab=email');
    }

    public function updateEmailTemplate(Request $request, Response $response, mysqli $db, string $template): Response
    {
        $definition = SettingsMailTemplates::get($template);
        if ($definition === null) {
            return $response->withStatus(404);
        }

        $data = (array)$request->getParsedBody();
        if (!Csrf::validate($data['csrf_token'] ?? null)) {
            $_SESSION['error_message'] = __('Token CSRF non valido. Riprova.');
            return $this->redirect($response, '/admin/settings?tab=templates');
        }

        $subject = trim((string)($data['subject'] ?? $definition['subject']));
        if ($subject === '') {
            $subject = $definition['subject'];
        }
        $body = (string)($data['body'] ?? $definition['body']);

        $repository = new SettingsRepository($db);
        $repository->ensureTables();
        $repository->saveEmailTemplate($template, $subject, $body, $definition['description'] ?? null, true);

        $_SESSION['success_message'] = 'Template email "' . $definition['label'] . '" aggiornato correttamente.';
        return $this->redirect($response, '/admin/settings?tab=templates&template=' . urlencode($template));
    }

    private function resolveAppSettings(SettingsRepository $repository): array
    {
        $name = $repository->get('app', 'name', ConfigStore::get('app.name', __('Biblioteca')));
        $logo = $repository->get('app', 'logo_path', ConfigStore::get('app.logo', ''));

        if ($logo === null) {
            $logo = '';
        }

        return [
            'name' => $name,
            'logo' => $logo,
            'footer_description' => $repository->get('app', 'footer_description', ConfigStore::get('app.footer_description', __('La tua biblioteca digitale per scoprire, esplorare e gestire la tua collezione di libri preferiti.'))),
            'social_facebook' => $repository->get('app', 'social_facebook', ConfigStore::get('app.social_facebook', '')),
            'social_twitter' => $repository->get('app', 'social_twitter', ConfigStore::get('app.social_twitter', '')),
            'social_instagram' => $repository->get('app', 'social_instagram', ConfigStore::get('app.social_instagram', '')),
            'social_linkedin' => $repository->get('app', 'social_linkedin', ConfigStore::get('app.social_linkedin', '')),
            'social_bluesky' => $repository->get('app', 'social_bluesky', ConfigStore::get('app.social_bluesky', '')),
        ];
    }

    private function resolveEmailSettings(SettingsRepository $repository): array
    {
        $stored = $repository->getCategory('email');
        $defaults = [
            'type' => ConfigStore::get('mail.driver', 'mail'),
            'from_email' => ConfigStore::get('mail.from_email', ''),
            'from_name' => ConfigStore::get('mail.from_name', __('Biblioteca')),
            'smtp_host' => ConfigStore::get('mail.smtp.host', ''),
            'smtp_port' => (string)ConfigStore::get('mail.smtp.port', 587),
            'smtp_username' => ConfigStore::get('mail.smtp.username', ''),
            'smtp_password' => ConfigStore::get('mail.smtp.password', ''),
            'smtp_security' => ConfigStore::get('mail.smtp.encryption', 'tls'),
        ];
        $settings = array_merge($defaults, $stored);
        $driver = $stored['driver_mode'] ?? $settings['type'] ?? $defaults['type'];
        $settings['type'] = $driver;

        return $settings;
    }

    private function resolveEmailTemplates(SettingsRepository $repository): array
    {
        $definitions = SettingsMailTemplates::all();
        $records = $repository->getEmailTemplates(array_keys($definitions));

        $templates = [];
        foreach ($definitions as $name => $meta) {
            $stored = $records[$name] ?? null;
            $templates[$name] = [
                'name' => $name,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'subject' => $stored['subject'] ?? $meta['subject'],
                'body' => $stored['body'] ?? $meta['body'],
                'placeholders' => $meta['placeholders'] ?? [],
            ];
        }

        return $templates;
    }

    /**
     * @return array<string, array{subject:string, body:string, description?:string}>
     */
    private function templateDefaults(): array
    {
        $defaults = [];
        foreach (SettingsMailTemplates::all() as $name => $meta) {
            $defaults[$name] = [
                'subject' => $meta['subject'],
                'body' => $meta['body'],
                'description' => $meta['description'] ?? null,
            ];
        }
        return $defaults;
    }

    /**
     * @param array{name:string, type:string, tmp_name:string, error:int, size:int} $file
     * @return array{success:bool, path?:string, message?:string}
     */
    private function handleLogoUpload(array $file): array
    {
        $tmpPath = $file['tmp_name'] ?? '';
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return ['success' => false, 'message' => 'Upload logo non valido.'];
        }

        $size = (int)($file['size'] ?? 0);
        if ($size > 2 * 1024 * 1024) {
            return ['success' => false, 'message' => 'Il logo supera il limite massimo di 2MB.'];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath) ?: '';
        $allowed = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
        ];

        if (!isset($allowed[$mime])) {
            return ['success' => false, 'message' => 'Formato logo non supportato. Usa PNG, JPG, WEBP o SVG.'];
        }

        $extension = $allowed[$mime];
        try {
            $randomSuffix = bin2hex(random_bytes(3));
        } catch (\Throwable $e) {
            $randomSuffix = substr(hash('sha1', (string)mt_rand()), 0, 6);
        }
        $filename = 'logo_' . date('Ymd_His') . '_' . $randomSuffix . '.' . $extension;
        $targetDir = dirname(__DIR__, 2) . '/public/uploads/settings';
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0775, true);
        }
        $targetPath = $targetDir . '/' . $filename;
        if (!move_uploaded_file($tmpPath, $targetPath)) {
            return ['success' => false, 'message' => 'Impossibile salvare il logo caricato.'];
        }

        // Ensure files are world-readable
        @chmod($targetPath, 0644);

        $publicPath = '/uploads/settings/' . $filename;
        return ['success' => true, 'path' => $publicPath];
    }

    private function resolveContactSettings(SettingsRepository $repository): array
    {
        $config = ConfigStore::get('contacts', []);
        return [
            'page_title' => $repository->get('contacts', 'page_title', $config['page_title'] ?? 'Contattaci'),
            'page_content' => $repository->get('contacts', 'page_content', $config['page_content'] ?? ''),
            'contact_email' => $repository->get('contacts', 'contact_email', $config['contact_email'] ?? ''),
            'contact_phone' => $repository->get('contacts', 'contact_phone', $config['contact_phone'] ?? ''),
            'google_maps_embed' => $repository->get('contacts', 'google_maps_embed', $config['google_maps_embed'] ?? ''),
            'privacy_text' => $repository->get('contacts', 'privacy_text', $config['privacy_text'] ?? 'Accetto il trattamento dei dati personali secondo la privacy policy'),
            'recaptcha_site_key' => $repository->get('contacts', 'recaptcha_site_key', $config['recaptcha_site_key'] ?? ''),
            'recaptcha_secret_key' => $repository->get('contacts', 'recaptcha_secret_key', $config['recaptcha_secret_key'] ?? ''),
            'notification_email' => $repository->get('contacts', 'notification_email', $config['notification_email'] ?? ''),
        ];
    }

    public function updateContactSettings(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array)$request->getParsedBody();
        if (!Csrf::validate($data['csrf_token'] ?? null)) {
            $_SESSION['error_message'] = __('Token CSRF non valido. Riprova.');
            return $this->redirect($response, '/admin/settings?tab=contacts');
        }

        $repository = new SettingsRepository($db);
        $repository->ensureTables();

        // Salva impostazioni contatti
        $settings = [
            'page_title' => trim((string)($data['page_title'] ?? 'Contattaci')),
            'page_content' => trim((string)($data['page_content'] ?? '')),
            'contact_email' => trim((string)($data['contact_email'] ?? '')),
            'contact_phone' => trim((string)($data['contact_phone'] ?? '')),
            'google_maps_embed' => trim((string)($data['google_maps_embed'] ?? '')),
            'privacy_text' => trim((string)($data['privacy_text'] ?? '')),
            'recaptcha_site_key' => trim((string)($data['recaptcha_site_key'] ?? '')),
            'recaptcha_secret_key' => trim((string)($data['recaptcha_secret_key'] ?? '')),
            'notification_email' => trim((string)($data['notification_email'] ?? '')),
        ];

        // Validate and sanitize Maps embed code (Google Maps or OpenStreetMap)
        if (!empty($settings['google_maps_embed'])) {
            $embedCode = trim($settings['google_maps_embed']);

            // Extract the URL from iframe if present
            $mapUrl = '';
            if (preg_match('/<iframe[^>]+src=["\']([^"\']+)["\']/', $embedCode, $matches)) {
                $mapUrl = $matches[1];
            } else {
                $mapUrl = $embedCode;
            }

            // Parse the URL
            $parsedUrl = parse_url($mapUrl);
            if ($parsedUrl === false || $parsedUrl['scheme'] !== 'https') {
                $_SESSION['error_message'] = 'URL non valido. Deve essere un URL HTTPS valido.';
                return $this->redirect($response, '/admin/settings?tab=contacts');
            }

            $isValidMap = false;
            $mapProvider = '';

            // Validate Google Maps
            if ($parsedUrl['host'] === 'www.google.com' &&
                isset($parsedUrl['path']) &&
                strpos($parsedUrl['path'], '/maps/embed') === 0) {
                $isValidMap = true;
                $mapProvider = 'google';
            }

            // Validate OpenStreetMap
            if ($parsedUrl['host'] === 'www.openstreetmap.org' &&
                isset($parsedUrl['path']) &&
                strpos($parsedUrl['path'], '/export/embed.html') === 0) {
                $isValidMap = true;
                $mapProvider = 'openstreetmap';
            }

            if (!$isValidMap) {
                $_SESSION['error_message'] = 'URL non valido. Deve essere un URL di Google Maps (https://www.google.com/maps/embed?...) o OpenStreetMap (https://www.openstreetmap.org/export/embed.html?...).';
                return $this->redirect($response, '/admin/settings?tab=contacts');
            }

            // Rebuild a safe iframe with only allowed attributes
            $safeIframe = sprintf(
                '<iframe src="%s" width="100%%" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade" data-map-provider="%s"></iframe>',
                htmlspecialchars($mapUrl, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($mapProvider, ENT_QUOTES, 'UTF-8')
            );

            // Store the sanitized iframe
            $settings['google_maps_embed'] = $safeIframe;
        }

        foreach ($settings as $key => $value) {
            $repository->set('contacts', $key, $value);
            ConfigStore::set("contacts.$key", $value);
        }

        $_SESSION['success_message'] = __('Impostazioni contatti aggiornate correttamente.');
        return $this->redirect($response, '/admin/settings?tab=contacts');
    }

    private function resolvePrivacySettings(SettingsRepository $repository): array
    {
        $config = ConfigStore::get('privacy', []);

        $enabledValue = $repository->get('privacy', 'cookie_banner_enabled', null);
        if ($enabledValue === null) {
            $cookieBannerEnabled = $config['cookie_banner_enabled'] ?? true;
        } else {
            $cookieBannerEnabled = filter_var($enabledValue, FILTER_VALIDATE_BOOLEAN);
        }

        // Get cookie banner category visibility flags
        $showAnalyticsValue = $repository->get('cookie_banner', 'show_analytics', null);
        if ($showAnalyticsValue === null) {
            $showAnalytics = (bool)ConfigStore::get('cookie_banner.show_analytics', true);
        } else {
            $showAnalytics = filter_var($showAnalyticsValue, FILTER_VALIDATE_BOOLEAN);
        }

        $showMarketingValue = $repository->get('cookie_banner', 'show_marketing', null);
        if ($showMarketingValue === null) {
            $showMarketing = (bool)ConfigStore::get('cookie_banner.show_marketing', true);
        } else {
            $showMarketing = filter_var($showMarketingValue, FILTER_VALIDATE_BOOLEAN);
        }

        return [
            'page_title' => $repository->get('privacy', 'page_title', $config['page_title'] ?? 'Privacy Policy'),
            'page_content' => $repository->get('privacy', 'page_content', $config['page_content'] ?? ''),
            'cookie_policy_content' => $repository->get('privacy', 'cookie_policy_content', $config['cookie_policy_content'] ?? ''),
            'cookie_banner_enabled' => $cookieBannerEnabled,
            'cookie_banner_language' => $repository->get('privacy', 'cookie_banner_language', $config['cookie_banner_language'] ?? 'it'),
            'cookie_banner_country' => $repository->get('privacy', 'cookie_banner_country', $config['cookie_banner_country'] ?? 'it'),
            'cookie_statement_link' => $repository->get('privacy', 'cookie_statement_link', $config['cookie_statement_link'] ?? ''),
            'cookie_technologies_link' => $repository->get('privacy', 'cookie_technologies_link', $config['cookie_technologies_link'] ?? ''),
            'show_analytics' => $showAnalytics,
            'show_marketing' => $showMarketing,
        ];
    }

    public function updatePrivacySettings(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array)$request->getParsedBody();
        if (!Csrf::validate($data['csrf_token'] ?? null)) {
            $_SESSION['error_message'] = 'Token CSRF non valido. Riprova.';
            return $this->redirect($response, '/admin/settings?tab=privacy');
        }

        $repository = new SettingsRepository($db);
        $repository->ensureTables();

        $settings = [
            'page_title' => trim((string)($data['page_title'] ?? 'Privacy Policy')),
            'page_content' => trim((string)($data['page_content'] ?? '')),
            'cookie_policy_content' => trim((string)($data['cookie_policy_content'] ?? '')),
            'cookie_banner_enabled' => isset($data['cookie_banner_enabled']) && $data['cookie_banner_enabled'] === '1',
            'cookie_banner_language' => strtolower(trim((string)($data['cookie_banner_language'] ?? 'it'))),
            'cookie_banner_country' => strtoupper(trim((string)($data['cookie_banner_country'] ?? 'IT'))),
            'cookie_statement_link' => trim((string)($data['cookie_statement_link'] ?? '')),
            'cookie_technologies_link' => trim((string)($data['cookie_technologies_link'] ?? '')),
        ];

        foreach ($settings as $key => $value) {
            // Convert boolean to string for repository
            $dbValue = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
            $repository->set('privacy', $key, $dbValue);
            ConfigStore::set("privacy.$key", $value);
        }

        // Save cookie banner category visibility flags
        $showAnalytics = isset($data['show_analytics']) && $data['show_analytics'] === '1';
        $showMarketing = isset($data['show_marketing']) && $data['show_marketing'] === '1';

        $repository->set('cookie_banner', 'show_analytics', $showAnalytics ? '1' : '0');
        $repository->set('cookie_banner', 'show_marketing', $showMarketing ? '1' : '0');
        ConfigStore::set('cookie_banner.show_analytics', $showAnalytics);
        ConfigStore::set('cookie_banner.show_marketing', $showMarketing);

        $_SESSION['success_message'] = __('Impostazioni privacy aggiornate correttamente.');
        return $this->redirect($response, '/admin/settings?tab=privacy');
    }

    private function resolveAdvancedSettings(SettingsRepository $repository): array
    {
        $config = ConfigStore::get('advanced', []);
        return [
            'custom_js_essential' => ContentSanitizer::normalizeExternalAssets(
                $repository->get('advanced', 'custom_js_essential', $config['custom_js_essential'] ?? '')
            ),
            'custom_js_analytics' => ContentSanitizer::normalizeExternalAssets(
                $repository->get('advanced', 'custom_js_analytics', $config['custom_js_analytics'] ?? '')
            ),
            'custom_js_marketing' => ContentSanitizer::normalizeExternalAssets(
                $repository->get('advanced', 'custom_js_marketing', $config['custom_js_marketing'] ?? '')
            ),
            'custom_header_css' => ContentSanitizer::normalizeExternalAssets(
                $repository->get('advanced', 'custom_header_css', $config['custom_header_css'] ?? '')
            ),
            'days_before_expiry_warning' => (int)$repository->get('advanced', 'days_before_expiry_warning', (string)($config['days_before_expiry_warning'] ?? 3)),
            'force_https' => $repository->get('advanced', 'force_https', $config['force_https'] ?? '0'),
            'enable_hsts' => $repository->get('advanced', 'enable_hsts', $config['enable_hsts'] ?? '0'),
            'sitemap_last_generated_at' => $repository->get('advanced', 'sitemap_last_generated_at', $config['sitemap_last_generated_at'] ?? ''),
            'sitemap_last_generated_total' => (int)$repository->get('advanced', 'sitemap_last_generated_total', (string)($config['sitemap_last_generated_total'] ?? 0)),
            'api_enabled' => $repository->get('api', 'enabled', '0'),
        ];
    }

    public function updateAdvancedSettings(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array)$request->getParsedBody();
        if (!Csrf::validate($data['csrf_token'] ?? null)) {
            $_SESSION['error_message'] = 'Token CSRF non valido. Riprova.';
            return $this->redirect($response, '/admin/settings?tab=advanced');
        }

        $repository = new SettingsRepository($db);
        $repository->ensureTables();

        $daysBeforeWarning = max(1, min(30, (int)($data['days_before_expiry_warning'] ?? 3)));

        $settings = [
            'custom_js_essential' => ContentSanitizer::normalizeExternalAssets(trim((string)($data['custom_js_essential'] ?? ''))),
            'custom_js_analytics' => ContentSanitizer::normalizeExternalAssets(trim((string)($data['custom_js_analytics'] ?? ''))),
            'custom_js_marketing' => ContentSanitizer::normalizeExternalAssets(trim((string)($data['custom_js_marketing'] ?? ''))),
            'custom_header_css' => ContentSanitizer::normalizeExternalAssets(trim((string)($data['custom_header_css'] ?? ''))),
            'days_before_expiry_warning' => (string)$daysBeforeWarning,
            'force_https' => isset($data['force_https']) && $data['force_https'] === '1' ? '1' : '0',
            'enable_hsts' => isset($data['enable_hsts']) && $data['enable_hsts'] === '1' ? '1' : '0',
        ];

        foreach ($settings as $key => $value) {
            $repository->set('advanced', $key, $value);
            if ($key === 'days_before_expiry_warning') {
                ConfigStore::set("advanced.$key", (int)$value);
            } elseif ($key === 'force_https' || $key === 'enable_hsts') {
                ConfigStore::set("advanced.$key", $value === '1');
            } else {
                ConfigStore::set("advanced.$key", $value);
            }
        }

        // Auto-attivazione toggle Privacy: se c'è codice analytics/marketing, attiva i rispettivi toggle
        $hasAnalytics = !empty(trim($settings['custom_js_analytics']));
        $hasMarketing = !empty(trim($settings['custom_js_marketing']));

        if ($hasAnalytics) {
            $repository->set('cookie_banner', 'show_analytics', '1');
            ConfigStore::set('cookie_banner.show_analytics', true);
        }

        if ($hasMarketing) {
            $repository->set('cookie_banner', 'show_marketing', '1');
            ConfigStore::set('cookie_banner.show_marketing', true);
        }

        $_SESSION['success_message'] = __('Impostazioni avanzate aggiornate correttamente.');
        return $this->redirect($response, '/admin/settings?tab=advanced');
    }

    public function regenerateSitemap(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array)$request->getParsedBody();
        if (!Csrf::validate($data['csrf_token'] ?? null)) {
            $_SESSION['error_message'] = 'Token CSRF non valido. Riprova.';
            return $this->redirect($response, '/admin/settings?tab=advanced');
        }

        $repository = new SettingsRepository($db);
        $repository->ensureTables();

        try {
            $baseUrl = SeoController::resolveBaseUrl($request);
            $generator = new SitemapGenerator($db, $baseUrl);
            $targetPath = dirname(__DIR__, 2) . '/public/sitemap.xml';
            $generator->saveTo($targetPath);
            $stats = $generator->getStats();

            $generatedAt = gmdate('c');
            $total = (int)($stats['total'] ?? 0);

            $repository->set('advanced', 'sitemap_last_generated_at', $generatedAt);
            $repository->set('advanced', 'sitemap_last_generated_total', (string)$total);
            ConfigStore::set('advanced.sitemap_last_generated_at', $generatedAt);
            ConfigStore::set('advanced.sitemap_last_generated_total', $total);

            $_SESSION['success_message'] = sprintf(__('Sitemap rigenerata con successo (%d URL).'), $total);
        } catch (\Throwable $exception) {
            $_SESSION['error_message'] = sprintf(__('Impossibile rigenerare la sitemap: %s'), $exception->getMessage());
        }

        return $this->redirect($response, '/admin/settings?tab=advanced');
    }

    private function loadContactMessages(mysqli $db): array
    {
        $messages = [];
        $result = $db->query("
            SELECT id, nome, cognome, email, telefono, indirizzo, messaggio,
                   privacy_accepted, ip_address, is_read, is_archived, created_at, read_at
            FROM contact_messages
            ORDER BY is_read ASC, created_at DESC
        ");

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $messages[] = $row;
            }
            $result->free();
        }

        return $messages;
    }

    private function resolveLabelSettings(SettingsRepository $repository): array
    {
        $config = ConfigStore::get('label', []);
        return [
            'width' => (int)($repository->get('label', 'width', (string)($config['width'] ?? 25))),
            'height' => (int)($repository->get('label', 'height', (string)($config['height'] ?? 38))),
            'format_name' => (string)($repository->get('label', 'format_name', $config['format_name'] ?? '25x38mm (Standard)')),
        ];
    }

    public function updateLabels(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array)$request->getParsedBody();
        if (!Csrf::validate($data['csrf_token'] ?? null)) {
            $_SESSION['error_message'] = 'Token CSRF non valido. Riprova.';
            return $this->redirect($response, '/admin/settings?tab=labels');
        }

        $repository = new SettingsRepository($db);
        $repository->ensureTables();

        $labelFormat = trim((string)($data['label_format'] ?? '25x38'));

        // Parse the format (e.g., "25x38" or "50x25")
        if (preg_match('/^(\d+)x(\d+)$/', $labelFormat, $matches)) {
            $width = (int)$matches[1];
            $height = (int)$matches[2];

            // Validate reasonable dimensions (between 10mm and 100mm)
            if ($width >= 10 && $width <= 100 && $height >= 10 && $height <= 100) {
                $formatName = "{$width}×{$height}mm";

                $repository->set('label', 'width', (string)$width);
                $repository->set('label', 'height', (string)$height);
                $repository->set('label', 'format_name', $formatName);

                ConfigStore::set('label.width', $width);
                ConfigStore::set('label.height', $height);
                ConfigStore::set('label.format_name', $formatName);

                $_SESSION['success_message'] = 'Formato etichette aggiornato correttamente.';
            } else {
                $_SESSION['error_message'] = 'Dimensioni etichetta non valide. Devono essere comprese tra 10mm e 100mm.';
            }
        } else {
            $_SESSION['error_message'] = 'Formato etichetta non valido.';
        }

        return $this->redirect($response, '/admin/settings?tab=labels');
    }

    public function toggleApi(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array)$request->getParsedBody();
        if (!Csrf::validate($data['csrf_token'] ?? null)) {
            $_SESSION['error_message'] = 'Token CSRF non valido. Riprova.';
            return $this->redirect($response, '/admin/settings?tab=advanced');
        }

        $repository = new SettingsRepository($db);
        $repository->ensureTables();

        $enabled = isset($data['api_enabled']) && $data['api_enabled'] === '1' ? '1' : '0';
        $repository->set('api', 'enabled', $enabled);
        ConfigStore::set('api.enabled', $enabled);

        $_SESSION['success_message'] = $enabled === '1'
            ? __('API abilitata con successo.')
            : __('API disabilitata con successo.');
        return $this->redirect($response, '/admin/settings?tab=advanced');
    }

    public function createApiKey(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array)$request->getParsedBody();
        if (!Csrf::validate($data['csrf_token'] ?? null)) {
            $_SESSION['error_message'] = __('Token CSRF non valido. Riprova.');
            return $this->redirect($response, '/admin/settings?tab=advanced');
        }

        $name = trim((string)($data['name'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));

        if ($name === '') {
            $_SESSION['error_message'] = __('Il nome dell\'API key è obbligatorio.');
            return $this->redirect($response, '/admin/settings?tab=advanced');
        }

        try {
            $apiKeyRepo = new \App\Models\ApiKeyRepository($db);
            $apiKeyRepo->ensureTable();
            $newKey = $apiKeyRepo->create($name, $description !== '' ? $description : null);

            $_SESSION['success_message'] = __('API key creata con successo.');
            $_SESSION['new_api_key'] = $newKey['api_key']; // Store for one-time display
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = sprintf(__('Errore nella creazione dell\'API key: %s'), $e->getMessage());
        }

        return $this->redirect($response, '/admin/settings?tab=advanced');
    }

    public function toggleApiKey(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $data = (array)$request->getParsedBody();
        if (!Csrf::validate($data['csrf_token'] ?? null)) {
            $_SESSION['error_message'] = __('Token CSRF non valido. Riprova.');
            return $this->redirect($response, '/admin/settings?tab=advanced');
        }

        try {
            $apiKeyRepo = new \App\Models\ApiKeyRepository($db);
            $apiKeyRepo->ensureTable();
            $apiKeyRepo->toggleActive($id);

            $_SESSION['success_message'] = __('Stato API key aggiornato con successo.');
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = sprintf(__('Errore nell\'aggiornamento dello stato dell\'API key: %s'), $e->getMessage());
        }

        return $this->redirect($response, '/admin/settings?tab=advanced');
    }

    public function deleteApiKey(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $data = (array)$request->getParsedBody();
        if (!Csrf::validate($data['csrf_token'] ?? null)) {
            $_SESSION['error_message'] = __('Token CSRF non valido. Riprova.');
            return $this->redirect($response, '/admin/settings?tab=advanced');
        }

        try {
            $apiKeyRepo = new \App\Models\ApiKeyRepository($db);
            $apiKeyRepo->ensureTable();
            $apiKeyRepo->delete($id);

            $_SESSION['success_message'] = __('API key eliminata con successo.');
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = sprintf(__('Errore nell\'eliminazione dell\'API key: %s'), $e->getMessage());
        }

        return $this->redirect($response, '/admin/settings?tab=advanced');
    }

    public function updateCookieBannerTexts(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array)$request->getParsedBody();
        if (!Csrf::validate($data['csrf_token'] ?? null)) {
            $_SESSION['error_message'] = 'Token CSRF non valido. Riprova.';
            return $this->redirect($response, '/admin/settings');
        }

        $repository = new SettingsRepository($db);
        $repository->ensureTables();

        $fieldMap = $this->getCookieBannerTextFieldMap();
        $cookieBannerTexts = [];

        foreach ($fieldMap as $key => $inputName) {
            $cookieBannerTexts[$key] = trim((string)($data[$inputName] ?? ''));
        }

        foreach ($cookieBannerTexts as $key => $value) {
            if ($value === '') {
                continue;
            }

            $repository->set('cookie_banner', $key, $value);
            ConfigStore::set("cookie_banner.$key", $value);
        }

        $_SESSION['success_message'] = 'Testi cookie banner aggiornati correttamente.';
        return $this->redirect($response, '/admin/settings?tab=privacy#privacy');
    }

    private function resolveCookieBannerTexts(SettingsRepository $repository): array
    {
        $defaults = $this->getCookieBannerDefaultMap();
        $config = ConfigStore::get('cookie_banner', []);
        $texts = [];

        foreach ($defaults as $key => $fallback) {
            $fallbackValue = $config[$key] ?? $fallback;
            $texts[$key] = $repository->get('cookie_banner', $key, $fallbackValue);
        }

        return $texts;
    }

    private function getCookieBannerDefaultMap(): array
    {
        return [
            'banner_description' => '<p>Utilizziamo i cookie per migliorare la tua esperienza. Continuando a visitare questo sito, accetti il nostro uso dei cookie.</p>',
            'accept_all_text' => 'Accetta tutti',
            'reject_non_essential_text' => 'Rifiuta non essenziali',
            'preferences_button_text' => 'Preferenze',
            'save_selected_text' => 'Accetta selezionati',
            'preferences_title' => 'Personalizza le tue preferenze sui cookie',
            'preferences_description' => '<p>Rispettiamo il tuo diritto alla privacy. Puoi scegliere di non consentire alcuni tipi di cookie. Le tue preferenze si applicheranno all\'intero sito web.</p>',
            'cookie_essential_name' => 'Cookie Essenziali',
            'cookie_essential_description' => 'Questi cookie sono necessari per il funzionamento del sito e non possono essere disabilitati.',
            'cookie_analytics_name' => 'Cookie Analitici',
            'cookie_analytics_description' => 'Questi cookie ci aiutano a capire come i visitatori interagiscono con il sito web.',
            'cookie_marketing_name' => 'Cookie di Marketing',
            'cookie_marketing_description' => 'Questi cookie vengono utilizzati per fornire annunci personalizzati.',
        ];
    }

    private function getCookieBannerTextFieldMap(): array
    {
        return [
            'banner_description' => 'cookie_banner_description',
            'accept_all_text' => 'cookie_accept_all_text',
            'reject_non_essential_text' => 'cookie_reject_non_essential_text',
            'preferences_button_text' => 'cookie_preferences_button_text',
            'save_selected_text' => 'cookie_save_selected_text',
            'preferences_title' => 'cookie_preferences_title',
            'preferences_description' => 'cookie_preferences_description',
            'cookie_essential_name' => 'cookie_essential_name',
            'cookie_essential_description' => 'cookie_essential_description',
            'cookie_analytics_name' => 'cookie_analytics_name',
            'cookie_analytics_description' => 'cookie_analytics_description',
            'cookie_marketing_name' => 'cookie_marketing_name',
            'cookie_marketing_description' => 'cookie_marketing_description',
        ];
    }

    private function redirect(Response $response, string $location): Response
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }
}
