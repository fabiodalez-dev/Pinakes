<?php
declare(strict_types=1);

namespace App\Support;

final class ConfigStore
{
    /**
     * Cross-request cache key for the raw system_settings rows (APCu/file via
     * QueryCache). Values are cached exactly as stored in the DB — the SMTP
     * password stays encrypted at rest and is only decrypted per request.
     */
    private const SETTINGS_CACHE_KEY = 'config_settings_raw';
    private const SETTINGS_CACHE_TTL = 60;

    private static ?array $runtimeCache = null;
    private static ?array $dbSettingsCache = null;
    private static ?\mysqli $sharedConnection = null;

    /**
     * True once a connection attempt has failed this request. Without it, a
     * DB outage makes every ConfigStore call (e.g. an admin save writing N
     * keys) retry the connect and pay the full timeout N times.
     */
    private static bool $connectionFailed = false;

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
                // App-wide timezone: DateHelper::today()/now() compute the loan
                // clock from this. Was a phantom key (read but defined nowhere,
                // always resolving to the hardcoded fallback) — now a real
                // default, seeded per-locale by the installer and editable from
                // the loans settings tab.
                'timezone' => 'Europe/Rome',
                'social_facebook' => '',
                'social_twitter' => '',
                'social_instagram' => '',
                'social_linkedin' => '',
                'social_bluesky' => '',
                'social_telegram' => '',
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
                // Per-field requirement toggles (issue #255): defaults preserve
                // the historical all-required registration form.
                'require_cognome' => true,
                'require_telefono' => true,
                'require_indirizzo' => true,
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
                'session_lifetime' => 180, // minutes of inactivity before the session expires (default 3h)
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
            'sharing' => [
                'enabled_providers' => 'facebook,x,whatsapp,email',
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
        self::$dbSettingsCache = null;

        // Parse path (e.g., "app.name" => category="app", key="name")
        // Handle nested paths: "mail.smtp.host" => category="mail", key="smtp.host"
        $keys = explode('.', $path);
        if (count($keys) < 2) {
            throw new \InvalidArgumentException("Config path must be in format 'category.key'");
        }

        $category = $keys[0];
        $key = implode('.', array_slice($keys, 1)); // Join remaining segments

        try {
            // Reuse the per-request shared connection: admin saves call set()
            // many times in a row and used to open a fresh MySQL connection for
            // every single key.
            $mysqli = self::getConnection();
            if ($mysqli === null) {
                return;
            }

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
        } catch (\Throwable $e) {
            // Silently ignore DB issues
        }

        // Invalidate the cross-request settings cache so the new value is
        // visible to the next request immediately.
        QueryCache::delete(self::SETTINGS_CACHE_KEY);
    }

    public static function clearCache(): void
    {
        self::$runtimeCache = null;
        self::$dbSettingsCache = null;
        QueryCache::delete(self::SETTINGS_CACHE_KEY);
    }

    /**
     * Lazily open (once per request) the standalone connection used by
     * ConfigStore. Bootstrap code reads settings before the DI container is
     * built, so ConfigStore cannot rely on the container's 'db' service.
     */
    private static function getConnection(): ?\mysqli
    {
        if (self::$sharedConnection instanceof \mysqli) {
            return self::$sharedConnection;
        }

        if (self::$connectionFailed) {
            return null;
        }

        $settingsPath = __DIR__ . '/../../config/settings.php';
        if (!is_file($settingsPath)) {
            return null;
        }

        $config = require $settingsPath;
        $dbCfg = $config['db'] ?? null;
        if (!is_array($dbCfg)) {
            return null;
        }

        $host = $dbCfg['hostname'] ?? 'localhost';
        $user = $dbCfg['username'] ?? '';
        $pass = $dbCfg['password'] ?? '';
        $name = $dbCfg['database'] ?? '';
        $port = (int) ($dbCfg['port'] ?? 3306);
        $charset = $dbCfg['charset'] ?? 'utf8mb4';
        $socket = $dbCfg['socket'] ?? null;

        if ($name === '' || $user === '') {
            return null;
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $mysqli = new \mysqli($host, $user, $pass, $name, $port, $socket);
            $mysqli->set_charset($charset);
        } catch (\Throwable $e) {
            // Remember the failure for the rest of the request so later calls
            // don't re-pay the connect timeout, then let callers handle it.
            self::$connectionFailed = true;
            throw $e;
        }
        self::$sharedConnection = $mysqli;

        return self::$sharedConnection;
    }

    /**
     * Check if catalogue-only mode is enabled
     *
     * When enabled, loans, reservations and wishlist features are disabled
     * throughout the application.
     *
     * @return bool True if catalogue mode is active
     */
    public static function isCatalogueMode(): bool
    {
        return (bool) self::get('system.catalogue_mode', false);
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
        if (self::$dbSettingsCache !== null) {
            return self::$dbSettingsCache;
        }

        self::$dbSettingsCache = [];

        try {
            $raw = self::loadRawSettings();

            // Map to ConfigStore structure
            if (!empty($raw['app'])) {
                self::$dbSettingsCache['app'] = [];
                if (isset($raw['app']['name']) && $raw['app']['name'] !== '') {
                    self::$dbSettingsCache['app']['name'] = (string) $raw['app']['name'];
                }
                // Handle logo: if logo_path exists in DB, use it; if not, explicitly set to empty
                if (isset($raw['app']['logo_path'])) {
                    self::$dbSettingsCache['app']['logo'] = !empty($raw['app']['logo_path']) ? (string) $raw['app']['logo_path'] : '';
                } else {
                    // Logo was deleted from DB - explicitly clear cached value
                    self::$dbSettingsCache['app']['logo'] = '';
                }
                // Load footer_description
                if (isset($raw['app']['footer_description'])) {
                    self::$dbSettingsCache['app']['footer_description'] = (string) $raw['app']['footer_description'];
                }
                // Load locale
                if (isset($raw['app']['locale'])) {
                    self::$dbSettingsCache['app']['locale'] = (string) $raw['app']['locale'];
                }
                // Load timezone (loan clock — DateHelper reads app.timezone).
                // This mapping is what makes the setting REAL: without it the
                // installer seed and the loans-tab save wrote a row that was
                // never read back, and get('app.timezone') always returned the
                // hardcoded default (caught by the adversarial review).
                if (isset($raw['app']['timezone']) && $raw['app']['timezone'] !== '') {
                    self::$dbSettingsCache['app']['timezone'] = (string) $raw['app']['timezone'];
                }
                // Load social links
                $socialKeys = ['social_facebook', 'social_twitter', 'social_instagram', 'social_linkedin', 'social_bluesky', 'social_telegram'];
                foreach ($socialKeys as $socialKey) {
                    if (isset($raw['app'][$socialKey])) {
                        self::$dbSettingsCache['app'][$socialKey] = (string) $raw['app'][$socialKey];
                    }
                }
            }

            if (!empty($raw['email'])) {
                self::$dbSettingsCache['mail'] = [];
                if (!empty($raw['email']['driver_mode'])) {
                    self::$dbSettingsCache['mail']['driver'] = (string) $raw['email']['driver_mode'];
                } elseif (!empty($raw['email']['type'])) {
                    self::$dbSettingsCache['mail']['driver'] = (string) $raw['email']['type'];
                }
                if (isset($raw['email']['from_email'])) {
                    self::$dbSettingsCache['mail']['from_email'] = (string) $raw['email']['from_email'];
                }
                if (isset($raw['email']['from_name'])) {
                    self::$dbSettingsCache['mail']['from_name'] = (string) $raw['email']['from_name'];
                }
                self::$dbSettingsCache['mail']['smtp'] = [];
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
                            self::$dbSettingsCache['mail']['smtp'][$dst] = (int) $value;
                        } elseif ($dst === 'password') {
                            if ($value === '') {
                                self::$dbSettingsCache['mail']['smtp'][$dst] = '';
                                continue;
                            }
                            $decrypted = SettingsEncryption::decrypt((string) $value);
                            if ($decrypted !== null) {
                                self::$dbSettingsCache['mail']['smtp'][$dst] = $decrypted;
                            } else {
                                SecureLogger::error('ConfigStore: smtp_password decryption failed');
                                // Explicitly set null to override the empty-string default
                                self::$dbSettingsCache['mail']['smtp'][$dst] = null;
                                continue;
                            }
                        } else {
                            self::$dbSettingsCache['mail']['smtp'][$dst] = (string) $value;
                        }
                    }
                }
            }

            if (!empty($raw['registration'])) {
                self::$dbSettingsCache['registration'] = [];
                // Boolean registration flags: approval + the per-field
                // requirement toggles (issue #255).
                $registrationFlags = ['require_admin_approval', 'require_cognome', 'require_telefono', 'require_indirizzo'];
                foreach ($registrationFlags as $flagKey) {
                    if (!isset($raw['registration'][$flagKey])) {
                        continue;
                    }
                    $value = filter_var($raw['registration'][$flagKey], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($value === null) {
                        $value = in_array((string) $raw['registration'][$flagKey], ['1', 'true', 'yes'], true);
                    }
                    self::$dbSettingsCache['registration'][$flagKey] = $value;
                }
            }

            if (!empty($raw['cookie_banner'])) {
                self::$dbSettingsCache['cookie_banner'] = [];
                foreach ($raw['cookie_banner'] as $key => $value) {
                    // Handle boolean flags
                    if ($key === 'show_analytics' || $key === 'show_marketing') {
                        $boolValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if ($boolValue === null) {
                            $boolValue = in_array((string) $value, ['1', 'true', 'yes'], true);
                        }
                        self::$dbSettingsCache['cookie_banner'][$key] = $boolValue;
                    } else {
                        self::$dbSettingsCache['cookie_banner'][$key] = (string) $value;
                    }
                }
            }

            if (!empty($raw['contacts'])) {
                self::$dbSettingsCache['contacts'] = [];
                foreach ($raw['contacts'] as $key => $value) {
                    self::$dbSettingsCache['contacts'][$key] = (string) $value;
                }
            }

            if (!empty($raw['privacy'])) {
                self::$dbSettingsCache['privacy'] = [];
                foreach ($raw['privacy'] as $key => $value) {
                    // Handle boolean flag for cookie_banner_enabled
                    if ($key === 'cookie_banner_enabled') {
                        $boolValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if ($boolValue === null) {
                            $boolValue = in_array((string) $value, ['1', 'true', 'yes'], true);
                        }
                        self::$dbSettingsCache['privacy'][$key] = $boolValue;
                    } else {
                        self::$dbSettingsCache['privacy'][$key] = (string) $value;
                    }
                }
            }

            if (!empty($raw['label'])) {
                self::$dbSettingsCache['label'] = [];
                foreach ($raw['label'] as $key => $value) {
                    // Handle numeric values for width and height
                    if ($key === 'width' || $key === 'height') {
                        self::$dbSettingsCache['label'][$key] = (int) $value;
                    } else {
                        self::$dbSettingsCache['label'][$key] = (string) $value;
                    }
                }
            }

            if (!empty($raw['advanced'])) {
                self::$dbSettingsCache['advanced'] = [];
                foreach ($raw['advanced'] as $key => $value) {
                    // Handle numeric value for days_before_expiry_warning
                    if ($key === 'days_before_expiry_warning' || $key === 'sitemap_last_generated_total' || $key === 'session_lifetime') {
                        self::$dbSettingsCache['advanced'][$key] = (int) $value;
                    } elseif ($key === 'api_enabled') {
                        // Handle boolean flag for api_enabled
                        $boolValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if ($boolValue === null) {
                            $boolValue = in_array((string) $value, ['1', 'true', 'yes'], true);
                        }
                        self::$dbSettingsCache['advanced'][$key] = $boolValue;
                    } else {
                        self::$dbSettingsCache['advanced'][$key] = (string) $value;
                    }
                }
            }

            if (!empty($raw['api'])) {
                self::$dbSettingsCache['api'] = [];
                foreach ($raw['api'] as $key => $value) {
                    // Handle boolean flag for enabled
                    if ($key === 'enabled') {
                        $boolValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if ($boolValue === null) {
                            $boolValue = in_array((string) $value, ['1', 'true', 'yes'], true);
                        }
                        self::$dbSettingsCache['api'][$key] = $boolValue;
                    } else {
                        self::$dbSettingsCache['api'][$key] = (string) $value;
                    }
                }
            }

            if (!empty($raw['seo'])) {
                self::$dbSettingsCache['seo'] = [];
                foreach ($raw['seo'] as $key => $value) {
                    self::$dbSettingsCache['seo'][$key] = (string) $value;
                }
            }

            if (!empty($raw['cms'])) {
                self::$dbSettingsCache['cms'] = [];
                foreach ($raw['cms'] as $key => $value) {
                    // Keep as string '1' or '0' to match controller/view usage
                    self::$dbSettingsCache['cms'][$key] = (string) $value;
                }
            }

            if (!empty($raw['sharing'])) {
                self::$dbSettingsCache['sharing'] = [];
                foreach ($raw['sharing'] as $key => $value) {
                    self::$dbSettingsCache['sharing'][$key] = (string) $value;
                }
            }

            if (!empty($raw['system'])) {
                self::$dbSettingsCache['system'] = [];
                foreach ($raw['system'] as $key => $value) {
                    // Handle boolean flags
                    if ($key === 'catalogue_mode') {
                        $boolValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if ($boolValue === null) {
                            $boolValue = in_array((string) $value, ['1', 'true', 'yes'], true);
                        }
                        self::$dbSettingsCache['system'][$key] = $boolValue;
                    } else {
                        self::$dbSettingsCache['system'][$key] = (string) $value;
                    }
                }
            }

        } catch (\Throwable $e) {
            // Silently ignore DB issues and fallback to stored defaults
            self::$dbSettingsCache = [];
        }

        return self::$dbSettingsCache;
    }

    /**
     * Fetch the raw system_settings rows, going to the database only on cache
     * miss. The short TTL (60s) is a safety net: every write path (set(),
     * clearCache(), SettingsRepository::set()) invalidates the key explicitly,
     * so admin changes are visible immediately while ordinary requests skip
     * both the extra MySQL connection and the full-table read entirely.
     *
     * @return array<string, array<string, string|null>>
     */
    private static function loadRawSettings(): array
    {
        $cached = QueryCache::get(self::SETTINGS_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $mysqli = self::getConnection();
        if ($mysqli === null) {
            return [];
        }

        // No SHOW TABLES pre-check: if the table is missing (mid-install) the
        // query throws and the caller's try/catch falls back to defaults.
        $result = $mysqli->query("SELECT category, setting_key, setting_value FROM system_settings");
        $raw = [];
        while ($row = $result->fetch_assoc()) {
            $category = (string) $row['category'];
            $key = (string) $row['setting_key'];
            if (!isset($raw[$category])) {
                $raw[$category] = [];
            }
            $raw[$category][$key] = $row['setting_value'];
        }
        $result->free();

        QueryCache::set(self::SETTINGS_CACHE_KEY, $raw, self::SETTINGS_CACHE_TTL);

        return $raw;
    }
}
