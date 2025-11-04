<?php
declare(strict_types=1);

namespace App\Support;

final class ConfigStore
{
    private const FILE = __DIR__ . '/../../storage/settings.json';

    public static function all(): array
    {
        $defaults = [
            'app' => [
                'name' => 'Pinakes',
                'logo' => '',
                'footer_description' => 'Il tuo sistema Pinakes per catalogare, gestire e condividere la tua collezione libraria.',
                'social_facebook' => '',
                'social_twitter' => '',
                'social_instagram' => '',
                'social_linkedin' => '',
                'social_bluesky' => '',
            ],
            'mail' => [
                'driver' => 'mail', // mail|smtp|phpmailer
                'from_email' => 'no-reply@localhost',
                'from_name'  => 'Pinakes',
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
        ];

        if (is_file(self::FILE)) {
            $json = file_get_contents(self::FILE) ?: '{}';
            $stored = json_decode($json, true);
            if (is_array($stored)) {
                $defaults = self::mergeRecursiveDistinct($defaults, $stored);
            }
        }

        $dbOverrides = self::loadDatabaseSettings();
        if (!empty($dbOverrides)) {
            $defaults = self::mergeRecursiveDistinct($defaults, $dbOverrides);
        }

        return $defaults;
    }

    public static function get(string $path, $default = null)
    {
        $data = self::all();
        $keys = explode('.', $path);
        $cur = $data;
        foreach ($keys as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) return $default;
            $cur = $cur[$k];
        }
        return $cur;
    }

    public static function set(string $path, $value): void
    {
        $data = self::all();
        $keys = explode('.', $path);
        $ref =& $data;
        foreach ($keys as $k) {
            if (!isset($ref[$k]) || !is_array($ref[$k])) $ref[$k] = [];
            $ref =& $ref[$k];
        }
        $ref = $value;
        if (!is_dir(dirname(self::FILE))) {
            @mkdir(dirname(self::FILE), 0775, true);
        }
        file_put_contents(self::FILE, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
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
        $port = (int)($dbCfg['port'] ?? 3306);
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
                $category = (string)$row['category'];
                $key = (string)$row['setting_key'];
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
                    $cache['app']['name'] = (string)$raw['app']['name'];
                }
                if (!empty($raw['app']['logo_path'])) {
                    $cache['app']['logo'] = (string)$raw['app']['logo_path'];
                }
            }

            if (!empty($raw['email'])) {
                $cache['mail'] = [];
                if (!empty($raw['email']['driver_mode'])) {
                    $cache['mail']['driver'] = (string)$raw['email']['driver_mode'];
                } elseif (!empty($raw['email']['type'])) {
                    $cache['mail']['driver'] = (string)$raw['email']['type'];
                }
                if (isset($raw['email']['from_email'])) {
                    $cache['mail']['from_email'] = (string)$raw['email']['from_email'];
                }
                if (isset($raw['email']['from_name'])) {
                    $cache['mail']['from_name'] = (string)$raw['email']['from_name'];
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
                            $cache['mail']['smtp'][$dst] = (int)$value;
                        } else {
                            $cache['mail']['smtp'][$dst] = (string)$value;
                        }
                    }
                }
            }

            if (!empty($raw['registration'])) {
                $cache['registration'] = [];
                if (isset($raw['registration']['require_admin_approval'])) {
                    $value = filter_var($raw['registration']['require_admin_approval'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($value === null) {
                        $value = in_array((string)$raw['registration']['require_admin_approval'], ['1', 'true', 'yes'], true);
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
                            $boolValue = in_array((string)$value, ['1', 'true', 'yes'], true);
                        }
                        $cache['cookie_banner'][$key] = $boolValue;
                    } else {
                        $cache['cookie_banner'][$key] = (string)$value;
                    }
                }
            }

            if (!empty($raw['contacts'])) {
                $cache['contacts'] = [];
                foreach ($raw['contacts'] as $key => $value) {
                    $cache['contacts'][$key] = (string)$value;
                }
            }

            if (!empty($raw['privacy'])) {
                $cache['privacy'] = [];
                foreach ($raw['privacy'] as $key => $value) {
                    // Handle boolean flag for cookie_banner_enabled
                    if ($key === 'cookie_banner_enabled') {
                        $boolValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if ($boolValue === null) {
                            $boolValue = in_array((string)$value, ['1', 'true', 'yes'], true);
                        }
                        $cache['privacy'][$key] = $boolValue;
                    } else {
                        $cache['privacy'][$key] = (string)$value;
                    }
                }
            }

            if (!empty($raw['label'])) {
                $cache['label'] = [];
                foreach ($raw['label'] as $key => $value) {
                    // Handle numeric values for width and height
                    if ($key === 'width' || $key === 'height') {
                        $cache['label'][$key] = (int)$value;
                    } else {
                        $cache['label'][$key] = (string)$value;
                    }
                }
            }

            if (!empty($raw['advanced'])) {
                $cache['advanced'] = [];
                foreach ($raw['advanced'] as $key => $value) {
                    // Handle numeric value for days_before_expiry_warning
                    if ($key === 'days_before_expiry_warning' || $key === 'sitemap_last_generated_total') {
                        $cache['advanced'][$key] = (int)$value;
                    } elseif ($key === 'api_enabled') {
                        // Handle boolean flag for api_enabled
                        $boolValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if ($boolValue === null) {
                            $boolValue = in_array((string)$value, ['1', 'true', 'yes'], true);
                        }
                        $cache['advanced'][$key] = $boolValue;
                    } else {
                        $cache['advanced'][$key] = (string)$value;
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
                            $boolValue = in_array((string)$value, ['1', 'true', 'yes'], true);
                        }
                        $cache['api'][$key] = $boolValue;
                    } else {
                        $cache['api'][$key] = (string)$value;
                    }
                }
            }

        } catch (\Throwable $e) {
            // Silently ignore DB issues and fallback to stored defaults
            $cache = [];
        }

        return $cache;
    }
}
