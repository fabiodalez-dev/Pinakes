<?php
declare(strict_types=1);

namespace App\Support;

final class ConfigStore
{
    private static ?array $runtimeCache = null;

    public static function all(): array
    {
        if (self::$runtimeCache !== null) {
            return self::$runtimeCache;
        }

        $defaults = [
            'app' => [
                'name' => 'Pinakes',
                'logo' => '',
                'footer_description' => 'Il tuo sistema Pinakes per catalogare, gestire e condividere la tua collezione libraria.',
                'locale' => 'it_IT',
                'social_facebook' => '',
                'social_twitter' => '',
                'social_instagram' => '',
                'social_linkedin' => '',
                'social_bluesky' => '',
            ],
            'mail' => [
                'driver' => 'mail', // mail|smtp|phpmailer
                'from_email' => 'no-reply@localhost',
                'from_name' => 'Pinakes',
                'smtp' => [
                    'host' => 'localhost',
                    'port' => 587,
                    'username' => '',
                    'password' => '',
                    'encryption' => 'tls', // tls|ssl|none
                ],
            ],
            'registration' => [
                'require_admin_approval' => true,
            ],
            'contacts' => [
                'page_title' => 'Contattaci',
                'page_content' => '<p>Contattaci per qualsiasi informazione.</p>',
                'contact_email' => '',
                'contact_phone' => '',
                'google_maps_embed' => '',
                'privacy_text' => 'I tuoi dati sono protetti secondo la nostra privacy policy.',
                'recaptcha_site_key' => '',
                'recaptcha_secret_key' => '',
                'notification_email' => '', // Email dove arrivano i messaggi
            ],
            'privacy' => [
                'page_title' => 'Privacy Policy',
                'page_content' => '<p>La tua privacy è importante per noi.</p>',
                'cookie_banner_enabled' => true,
                'cookie_banner_language' => 'it',
                'cookie_banner_country' => 'it',
                'cookie_statement_link' => '',
                'cookie_technologies_link' => '',
            ],
            'cookie_banner' => [
                // Banner texts
                'banner_description' => '<p>Utilizziamo i cookie per migliorare la tua esperienza. Continuando a visitare questo sito, accetti il nostro uso dei cookie.</p>',
                'accept_all_text' => 'Accetta tutti',
                'reject_non_essential_text' => 'Rifiuta non essenziali',
                'preferences_button_text' => 'Preferenze',
                'save_selected_text' => 'Accetta selezionati',

                // Preferences modal texts
                'preferences_title' => 'Personalizza le tue preferenze sui cookie',
                'preferences_description' => '<p>Rispettiamo il tuo diritto alla privacy. Puoi scegliere di non consentire alcuni tipi di cookie. Le tue preferenze si applicheranno all\'intero sito web.</p>',

                // Cookie type: Essential (always visible, required)
                'cookie_essential_name' => 'Cookie Essenziali',
                'cookie_essential_description' => 'Questi cookie sono necessari per il funzionamento del sito e non possono essere disabilitati.',

                // Cookie type: Analytics (optional, can be hidden)
                'show_analytics' => true,
                'cookie_analytics_name' => 'Cookie Analitici',
                'cookie_analytics_description' => 'Questi cookie ci aiutano a capire come i visitatori interagiscono con il sito web.',

                // Cookie type: Marketing (optional, can be hidden)
                'show_marketing' => true,
                'cookie_marketing_name' => 'Cookie di Marketing',
                'cookie_marketing_description' => 'Questi cookie vengono utilizzati per fornire annunci personalizzati.',
            ],
            'advanced' => [
                'custom_js_essential' => '',
                'custom_js_analytics' => '',
                'custom_js_marketing' => '',
                'custom_header_css' => '',
                'days_before_expiry_warning' => 3,
                'sitemap_last_generated_at' => '',
                'sitemap_last_generated_total' => 0,
            ],
            'label' => [
                'width' => 25,
                'height' => 38,
                'format_name' => '25x38mm (Standard)',
            ],
            'cms' => [
                'events_page_enabled' => '1', // Default to enabled
            ],
        ];

        $localizedDefaults = self::getLocaleDefaultTexts();
        if (!empty($localizedDefaults)) {
            $defaults = self::mergeRecursiveDistinct($defaults, $localizedDefaults);
        }

        // Load everything from database ONLY (no more JSON file)
        $dbOverrides = self::loadDatabaseSettings();
        if (!empty($dbOverrides)) {
            $defaults = self::mergeRecursiveDistinct($defaults, $dbOverrides);
        }

        self::$runtimeCache = $defaults;
        return self::$runtimeCache;
    }

    public static function get(string $path, $default = null)
    {
        $data = self::all();
        $keys = explode('.', $path);
        $cur = $data;
        foreach ($keys as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur))
                return $default;
            $cur = $cur[$k];
        }
        return $cur;
    }

    public static function set(string $path, $value): void
    {
        self::$runtimeCache = null;

        // Parse path (e.g., "app.name" => category="app", key="name")
        // Handle nested paths: "mail.smtp.host" => category="mail", key="smtp.host"
        $keys = explode('.', $path);
        if (count($keys) < 2) {
            throw new \InvalidArgumentException("Config path must be in format 'category.key'");
        }

        $category = $keys[0];
        $key = implode('.', array_slice($keys, 1)); // Join remaining segments

        // Save to database ONLY (no more JSON file)
        $settingsPath = __DIR__ . '/../../config/settings.php';
        if (!is_file($settingsPath)) {
            return; // No database config available
        }

        $config = require $settingsPath;
        $dbCfg = $config['db'] ?? null;
        if (!is_array($dbCfg)) {
            return;
        }

        $host = $dbCfg['hostname'] ?? 'localhost';
        $user = $dbCfg['username'] ?? '';
        $pass = $dbCfg['password'] ?? '';
        $name = $dbCfg['database'] ?? '';
        $port = (int) ($dbCfg['port'] ?? 3306);
        $charset = $dbCfg['charset'] ?? 'utf8mb4';

        if ($name === '' || $user === '') {
            return;
        }

        try {
            $mysqli = new \mysqli($host, $user, $pass, $name, $port);
            $mysqli->set_charset($charset);

            // Map ConfigStore paths to database schema
            $dbCategory = $category;
            $dbKey = $key;

            // Map 'mail' category to 'email' in database
            if ($category === 'mail') {
                $dbCategory = 'email';

                // Map mail keys to database schema
                if ($key === 'driver') {
                    $dbKey = 'driver_mode';
                } elseif ($key === 'smtp.encryption') {
                    // Special case: encryption => smtp_security
                    $dbKey = 'smtp_security';
                } elseif (strpos($key, 'smtp.') === 0) {
                    // mail.smtp.host => smtp_host, mail.smtp.port => smtp_port, etc.
                    $dbKey = str_replace('.', '_', $key);
                } elseif ($key === 'from_email' || $key === 'from_name') {
                    $dbKey = $key; // Keep as-is
                }
            }

            // Map app.logo to logo_path
            if ($category === 'app' && $key === 'logo') {
                $dbKey = 'logo_path';
            }

            $stmt = $mysqli->prepare("
                INSERT INTO system_settings (category, setting_key, setting_value, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
            ");
            $valueStr = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            $stmt->bind_param('ssss', $dbCategory, $dbKey, $valueStr, $valueStr);
            $stmt->execute();
            $stmt->close();
            $mysqli->close();
        } catch (\Throwable $e) {
            // Silently ignore DB issues
        }
    }

    public static function clearCache(): void
    {
        self::$runtimeCache = null;
    }

    private static function mergeRecursiveDistinct(array $base, array $replacements): array
    {
        foreach ($replacements as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::mergeRecursiveDistinct($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private static function getLocaleDefaultTexts(): array
    {
        $texts = self::loadLocaleDefaultsFile();
        if (empty($texts)) {
            return [];
        }

        $fallback = $texts['it_IT'] ?? [];
        $locale = self::determineInstallationLocale();

        if ($locale === 'it_IT' || !isset($texts[$locale])) {
            return $fallback;
        }

        return self::mergeRecursiveDistinct($fallback, $texts[$locale]);
    }

    private static function loadLocaleDefaultsFile(): array
    {
        static $localized = null;
        if ($localized !== null) {
            return $localized;
        }

        $path = __DIR__ . '/../../config/default_texts.php';
        if (is_file($path)) {
            $localized = require $path;
        } else {
            $localized = [];
        }

        return $localized;
    }

    private static function determineInstallationLocale(): string
    {
        $dbLocale = self::extractLocaleFromDatabase();
        if ($dbLocale !== null) {
            return $dbLocale;
        }

        $envLocale = getenv('APP_LOCALE') ?: 'it_IT';
        $normalized = self::normalizeLocale($envLocale);
        if (!preg_match('/^[a-z]{2}_[A-Z]{2}$/', $normalized)) {
            return 'it_IT';
        }
        return $normalized;
    }

    private static function normalizeLocale(string $locale): string
    {
        $locale = trim(str_replace('-', '_', $locale));
        if (preg_match('/^([a-zA-Z]{2})_([a-zA-Z]{2})$/', $locale, $matches)) {
            return strtolower($matches[1]) . '_' . strtoupper($matches[2]);
        }
        return $locale;
    }

    private static function extractLocaleFromDatabase(): ?string
    {
        $dbSettings = self::loadDatabaseSettings();
        if (isset($dbSettings['app']['locale'])) {
            $locale = (string) $dbSettings['app']['locale'];
            $normalized = self::normalizeLocale($locale);
            if (preg_match('/^[a-z]{2}_[A-Z]{2}$/', $normalized)) {
                return $normalized;
            }
        }

        return null;
    }

    private static function loadDatabaseSettings(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = [];

        $settingsPath = __DIR__ . '/../../config/settings.php';
        if (!is_file($settingsPath)) {
            return $cache;
        }

        $config = require $settingsPath;
        $dbCfg = $config['db'] ?? null;
        if (!is_array($dbCfg)) {
            return $cache;
        }

        $host = $dbCfg['hostname'] ?? 'localhost';
        $user = $dbCfg['username'] ?? '';
        $pass = $dbCfg['password'] ?? '';
        $name = $dbCfg['database'] ?? '';
        $port = (int) ($dbCfg['port'] ?? 3306);
        $charset = $dbCfg['charset'] ?? 'utf8mb4';

        if ($name === '' || $user === '') {
            return $cache;
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $mysqli = new \mysqli($host, $user, $pass, $name, $port);
            $mysqli->set_charset($charset);

            // Ensure table exists
            $tables = $mysqli->query("SHOW TABLES LIKE 'system_settings'");
            if (!$tables || $tables->num_rows === 0) {
                if ($tables instanceof \mysqli_result) {
                    $tables->free();
                }
                $mysqli->close();
                return $cache;
            }
            $tables->free();

            $result = $mysqli->query("SELECT category, setting_key, setting_value FROM system_settings");
            $raw = [];
            while ($row = $result->fetch_assoc()) {
                $category = (string) $row['category'];
                $key = (string) $row['setting_key'];
                $value = $row['setting_value'];
                if (!isset($raw[$category])) {
                    $raw[$category] = [];
                }
                $raw[$category][$key] = $value;
            }
            $result->free();
            $mysqli->close();

            // Map to ConfigStore structure
            if (!empty($raw['app'])) {
                $cache['app'] = [];
                if (isset($raw['app']['name']) && $raw['app']['name'] !== '') {
                    $cache['app']['name'] = (string) $raw['app']['name'];
                }
                // Handle logo: if logo_path exists in DB, use it; if not, explicitly set to empty
                if (isset($raw['app']['logo_path'])) {
                    $cache['app']['logo'] = !empty($raw['app']['logo_path']) ? (string) $raw['app']['logo_path'] : '';
                } else {
                    // Logo was deleted from DB - explicitly clear cached value
                    $cache['app']['logo'] = '';
                }
            }

            if (!empty($raw['email'])) {
                $cache['mail'] = [];
                if (!empty($raw['email']['driver_mode'])) {
                    $cache['mail']['driver'] = (string) $raw['email']['driver_mode'];
                } elseif (!empty($raw['email']['type'])) {
                    $cache['mail']['driver'] = (string) $raw['email']['type'];
                }
                if (isset($raw['email']['from_email'])) {
                    $cache['mail']['from_email'] = (string) $raw['email']['from_email'];
                }
                if (isset($raw['email']['from_name'])) {
                    $cache['mail']['from_name'] = (string) $raw['email']['from_name'];
                }
                $cache['mail']['smtp'] = $cache['mail']['smtp'] ?? [];
                $smtpMap = [
                    'smtp_host' => 'host',
                    'smtp_port' => 'port',
                    'smtp_username' => 'username',
                    'smtp_password' => 'password',
                    'smtp_security' => 'encryption',
                ];
                foreach ($smtpMap as $src => $dst) {
                    if (isset($raw['email'][$src])) {
                        $value = $raw['email'][$src];
                        if ($dst === 'port') {
                            $cache['mail']['smtp'][$dst] = (int) $value;
                        } else {
                            $cache['mail']['smtp'][$dst] = (string) $value;
                        }
                    }
                }
            }

            if (!empty($raw['registration'])) {
                $cache['registration'] = [];
                if (isset($raw['registration']['require_admin_approval'])) {
                    $value = filter_var($raw['registration']['require_admin_approval'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($value === null) {
                        $value = in_array((string) $raw['registration']['require_admin_approval'], ['1', 'true', 'yes'], true);
                    }
                    $cache['registration']['require_admin_approval'] = $value;
                }
            }

            if (!empty($raw['cookie_banner'])) {
                $cache['cookie_banner'] = [];
                foreach ($raw['cookie_banner'] as $key => $value) {
                    // Handle boolean flags
                    if ($key === 'show_analytics' || $key === 'show_marketing') {
                        $boolValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if ($boolValue === null) {
                            $boolValue = in_array((string) $value, ['1', 'true', 'yes'], true);
                        }
                        $cache['cookie_banner'][$key] = $boolValue;
                    } else {
                        $cache['cookie_banner'][$key] = (string) $value;
                    }
                }
            }

            if (!empty($raw['contacts'])) {
                $cache['contacts'] = [];
                foreach ($raw['contacts'] as $key => $value) {
                    $cache['contacts'][$key] = (string) $value;
                }
            }

            if (!empty($raw['privacy'])) {
                $cache['privacy'] = [];
                foreach ($raw['privacy'] as $key => $value) {
                    // Handle boolean flag for cookie_banner_enabled
                    if ($key === 'cookie_banner_enabled') {
                        $boolValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if ($boolValue === null) {
                            $boolValue = in_array((string) $value, ['1', 'true', 'yes'], true);
                        }
                        $cache['privacy'][$key] = $boolValue;
                    } else {
                        $cache['privacy'][$key] = (string) $value;
                    }
                }
            }

            if (!empty($raw['label'])) {
                $cache['label'] = [];
                foreach ($raw['label'] as $key => $value) {
                    // Handle numeric values for width and height
                    if ($key === 'width' || $key === 'height') {
                        $cache['label'][$key] = (int) $value;
                    } else {
                        $cache['label'][$key] = (string) $value;
                    }
                }
            }

            if (!empty($raw['advanced'])) {
                $cache['advanced'] = [];
                foreach ($raw['advanced'] as $key => $value) {
                    // Handle numeric value for days_before_expiry_warning
                    if ($key === 'days_before_expiry_warning' || $key === 'sitemap_last_generated_total') {
                        $cache['advanced'][$key] = (int) $value;
                    } elseif ($key === 'api_enabled') {
                        // Handle boolean flag for api_enabled
                        $boolValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if ($boolValue === null) {
                            $boolValue = in_array((string) $value, ['1', 'true', 'yes'], true);
                        }
                        $cache['advanced'][$key] = $boolValue;
                    } else {
                        $cache['advanced'][$key] = (string) $value;
                    }
                }
            }

            if (!empty($raw['api'])) {
                $cache['api'] = [];
                foreach ($raw['api'] as $key => $value) {
                    // Handle boolean flag for enabled
                    if ($key === 'enabled') {
                        $boolValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if ($boolValue === null) {
                            $boolValue = in_array((string) $value, ['1', 'true', 'yes'], true);
                        }
                        $cache['api'][$key] = $boolValue;
                    } else {
                        $cache['api'][$key] = (string) $value;
                    }
                }
            }

            if (!empty($raw['cms'])) {
                $cache['cms'] = [];
                foreach ($raw['cms'] as $key => $value) {
                    // Keep as string '1' or '0' to match controller/view usage
                    $cache['cms'][$key] = (string) $value;
                }
            }

        } catch (\Throwable $e) {
            // Silently ignore DB issues and fallback to stored defaults
            $cache = [];
        }

        return $cache;
    }
}
