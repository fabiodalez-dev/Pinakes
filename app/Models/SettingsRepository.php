<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;
use mysqli_stmt;
use RuntimeException;

class SettingsRepository
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function ensureTables(): void
    {
        $systemSettingsSql = "
            CREATE TABLE IF NOT EXISTS system_settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                category VARCHAR(50) NOT NULL,
                setting_key VARCHAR(100) NOT NULL,
                setting_value TEXT,
                description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_setting (category, setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        if ($this->db->query($systemSettingsSql) === false) {
            throw new RuntimeException('Unable to ensure system_settings table: ' . $this->db->error);
        }

        // Stessa forma di installer/database/schema.sql: la chiave è (name, locale)
        // perché l'invio risolve i template per locale di installazione (M8).
        $emailTemplatesSql = "
            CREATE TABLE IF NOT EXISTS email_templates (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                locale VARCHAR(10) NOT NULL DEFAULT 'it_IT',
                subject VARCHAR(255) NOT NULL,
                body LONGTEXT NOT NULL,
                description TEXT,
                active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY name_locale (name, locale)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        if ($this->db->query($emailTemplatesSql) === false) {
            throw new RuntimeException('Unable to ensure email_templates table: ' . $this->db->error);
        }
    }

    public function get(string $category, string $key, ?string $default = null): ?string
    {
        $stmt = $this->prepare('SELECT setting_value FROM system_settings WHERE category = ? AND setting_key = ? LIMIT 1');
        $stmt->bind_param('ss', $category, $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $value = $result->fetch_column();
        $stmt->close();

        // fetch_column() returns false when the query matched no row, and null
        // when the stored value is SQL NULL — both mean "unset". Coercing the
        // no-row false to '' silently discarded every caller's $default (e.g.
        // label show_* flags fell back to '' instead of '1', so the label PDF
        // rendered only the barcode). Treat both as absent → return $default.
        if ($value === null || $value === false) {
            return $default;
        }

        return (string)$value;
    }

    /**
     * Days-before-expiry warning horizon (Settings → Advanced). Zero is a valid
     * value meaning "today only"; negative/corrupt values clamp to 0. Shared by the
     * web reminders (NotificationService) and the mobile-api due-soon feed/push so
     * the two can never diverge on the setting key, fallback or clamp.
     */
    public function daysBeforeExpiryWarning(): int
    {
        return max(0, (int)($this->get('advanced', 'days_before_expiry_warning', '3') ?? 3));
    }

    /**
     * Whether user loan requests should skip the manual approval queue.
     * Kept here so the web flow and the Mobile API share the same default and
     * interpretation of the persisted setting.
     */
    public function autoApproveLoanRequests(): bool
    {
        return ($this->get('loans', 'auto_approve_requests', '0') ?? '0') === '1';
    }

    /**
     * Whether staff workflows may register more than one open loan for the same
     * borrower and title when every loan is tied to a distinct physical copy.
     * Missing or malformed values deliberately preserve the strict default.
     */
    public function allowsMultipleLoansSameBook(): bool
    {
        return ($this->get('loans', 'allow_multiple_loans_same_book', '0') ?? '0') === '1';
    }

    /**
     * Configured default loan duration (global rule, no patron context).
     *
     * @deprecated read the rules from \App\Services\CirculationPolicy (forLoan()/defaults()).
     */
    public function loanDurationDays(): int
    {
        return (new \App\Services\CirculationPolicy($this->db, $this))->defaults()->durationDays;
    }

    /**
     * Maximum duration of a reader's request, including an explicit end date.
     *
     * @deprecated read the rules from \App\Services\CirculationPolicy (forLoan()/defaults()).
     */
    public function maxLoanDurationDays(): int
    {
        return (new \App\Services\CirculationPolicy($this->db, $this))->defaults()->maxRequestDays;
    }

    /**
     * Default for reader requests and their calendars; staff loans retain their own duration.
     *
     * @deprecated read the rules from \App\Services\CirculationPolicy (forLoan()/defaults()).
     */
    public function requestLoanDurationDays(): int
    {
        return (new \App\Services\CirculationPolicy($this->db, $this))->defaults()->requestDurationDays();
    }

    /**
     * @return array<string,string>
     */
    public function getCategory(string $category): array
    {
        $stmt = $this->prepare('SELECT setting_key, setting_value FROM system_settings WHERE category = ?');
        $stmt->bind_param('s', $category);
        $stmt->execute();
        $result = $stmt->get_result();

        $settings = [];
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = (string)$row['setting_value'];
        }
        $stmt->close();

        return $settings;
    }

    public function set(string $category, string $key, ?string $value): void
    {
        $stmt = $this->prepare('INSERT INTO system_settings (category, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->bind_param('sss', $category, $key, $value);
        $stmt->execute();
        $stmt->close();

        // ConfigStore caches system_settings across requests; writes that
        // bypass ConfigStore::set() must invalidate it too.
        \App\Support\ConfigStore::clearCache();
    }

    public function delete(string $category, string $key): void
    {
        $stmt = $this->prepare('DELETE FROM system_settings WHERE category = ? AND setting_key = ?');
        $stmt->bind_param('ss', $category, $key);
        $stmt->execute();
        $stmt->close();

        \App\Support\ConfigStore::clearCache();
    }

    /**
     * @param array<string, array{subject:string, body:string, description?:string}> $templates
     */
    public function ensureEmailTemplates(array $templates, ?string $locale = null): void
    {
        $locale = $this->resolveTemplateLocale($locale);

        // On a non-Italian install seeded before per-locale defaults existed, the
        // rows carry the untranslated Italian text. Compare against the Italian
        // base so we can heal those rows to the localized default WITHOUT ever
        // clobbering a template an admin has customized (exact match only).
        $italianBase = ($locale !== 'it_IT') ? \App\Support\SettingsMailTemplates::all('it_IT') : [];

        foreach ($templates as $name => $template) {
            $existing = $this->getEmailTemplate($name, $locale);
            if ($existing !== null) {
                if (isset($italianBase[$name])) {
                    $base = $italianBase[$name];
                    // The Italian text as shipped now, or as an earlier
                    // release shipped it (SettingsMailTemplates::retiredBodies():
                    // loan_returned before its {{multa}} line), so a row left
                    // in Italian by an older install still gets translated.
                    $storedBody = trim(str_replace("\r\n", "\n", (string) $existing['body']));
                    $italianBodies = array_map(
                        static fn (string $body): string => trim(str_replace("\r\n", "\n", $body)),
                        array_merge([(string) $base['body']], \App\Support\SettingsMailTemplates::retiredBodies($name, 'it_IT'))
                    );
                    $stillItalian = trim((string) $existing['subject']) === trim((string) $base['subject'])
                        && in_array($storedBody, $italianBodies, true);
                    $haveTranslation = trim((string) $template['subject']) !== trim((string) $existing['subject'])
                        || trim((string) $template['body']) !== trim((string) $existing['body']);
                    if ($stillItalian && $haveTranslation) {
                        $this->saveEmailTemplate(
                            $name,
                            $template['subject'],
                            $template['body'],
                            $template['description'] ?? null,
                            true,
                            $locale
                        );
                        continue;
                    }
                }
                $this->healRetiredDefault($name, $existing, $template, $locale);
                continue;
            }
            $this->saveEmailTemplate(
                $name,
                $template['subject'],
                $template['body'],
                $template['description'] ?? null,
                true,
                $locale
            );
        }
    }

    /**
     * A stored row that still equals a default this template shipped with
     * BEFORE the current one (SettingsMailTemplates::retiredBodies(): the
     * 0.9.0 loan_returned without its {{multa}} line) gets the current body,
     * so a new placeholder reaches an upgraded install. Any other text is a
     * customisation and is left exactly as it is; the subject, the description
     * and the active flag of the row are kept. Idempotent: once healed the row
     * equals the current default and matches nothing retired.
     *
     * @param array<string, mixed> $existing
     * @param array{subject:string, body:string, description?:string} $template
     */
    private function healRetiredDefault(string $name, array $existing, array $template, string $locale): bool
    {
        $canon = static fn (string $html): string => trim(str_replace("\r\n", "\n", $html));
        $stored = $canon((string) $existing['body']);
        $current = $canon((string) $template['body']);
        if ($stored === '' || $stored === $current) {
            return false;
        }
        foreach (\App\Support\SettingsMailTemplates::retiredBodies($name, $locale) as $retired) {
            if ($stored !== $canon($retired) && $stored !== $canon(\App\Support\EmailLayout::normalizeContent($retired))) {
                continue;
            }
            $this->saveEmailTemplate(
                $name,
                (string) $existing['subject'],
                $template['body'],
                isset($existing['description']) ? (string) $existing['description'] : ($template['description'] ?? null),
                (int) ($existing['active'] ?? 1) === 1,
                $locale
            );
            return true;
        }
        return false;
    }

    /**
     * #299: repair email templates already stored with links double-prefixed by
     * the admin base — the WYSIWYG editor used to resolve placeholder URLs (e.g.
     * href="{{login_url}}") against the admin page, saving
     * href="https://host/admin/{{login_url}}". The editor no longer does this
     * (convert_urls:false), and EmailService heals at render time, but the stored
     * value stays wrong until repaired. Runs on every install (all locales).
     *
     * Portable across MySQL 5.7+/MariaDB (no REGEXP_REPLACE): the LIKE narrows to
     * the few possibly-affected rows, the fix is done in PHP. Idempotent.
     *
     * @return int number of template rows actually repaired
     */
    public function healCorruptedTemplateUrls(): int
    {
        $stmt = $this->prepare("SELECT name, locale, body FROM email_templates WHERE body LIKE '%/admin/{{%'");
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        $healed = 0;
        foreach ($rows as $row) {
            $fixed = \App\Support\EmailService::healPlaceholderUrls((string) $row['body']);
            if ($fixed !== (string) $row['body']) {
                $update = $this->prepare('UPDATE email_templates SET body = ? WHERE name = ? AND locale = ?');
                $update->bind_param('sss', $fixed, $row['name'], $row['locale']);
                $update->execute();
                $update->close();
                $healed++;
            }
        }
        return $healed;
    }

    public function getEmailTemplate(string $name, ?string $locale = null): ?array
    {
        $locale = $this->resolveTemplateLocale($locale);
        $stmt = $this->prepare('SELECT name, locale, subject, body, description, active FROM email_templates WHERE name = ? AND locale = ? LIMIT 1');
        $stmt->bind_param('ss', $name, $locale);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * @param string[] $names
     * @return array<string, array{name:string,locale:string,subject:string,body:string,description:?string,active:int}>
     */
    public function getEmailTemplates(array $names = [], ?string $locale = null): array
    {
        $locale = $this->resolveTemplateLocale($locale);
        $stmt = null;
        if (empty($names)) {
            $sql = 'SELECT name, locale, subject, body, description, active FROM email_templates WHERE locale = ? ORDER BY name';
            $stmt = $this->prepare($sql);
            $stmt->bind_param('s', $locale);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $placeholders = implode(',', array_fill(0, count($names), '?'));
            $sql = 'SELECT name, locale, subject, body, description, active FROM email_templates WHERE name IN (' . $placeholders . ') AND locale = ? ORDER BY name';
            $stmt = $this->prepare($sql);
            $types = str_repeat('s', count($names)) . 's';
            $params = array_merge($names, [$locale]);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
        }

        $templates = [];
        // $stmt->get_result() returns a mysqli_result (mysqli runs in exception
        // mode, so it throws rather than returning false) — free it directly.
        while ($row = $result->fetch_assoc()) {
            $templates[$row['name']] = $row;
        }
        $result->free();
        $stmt->close();

        return $templates;
    }

    public function saveEmailTemplate(string $name, string $subject, string $body, ?string $description = null, bool $active = true, ?string $locale = null): void
    {
        $locale = $this->resolveTemplateLocale($locale);
        $stmt = $this->prepare('INSERT INTO email_templates (name, locale, subject, body, description, active) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), description = VALUES(description), active = VALUES(active)');
        $activeInt = $active ? 1 : 0;
        $stmt->bind_param('sssssi', $name, $locale, $subject, $body, $description, $activeInt);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Normalize the optional template locale. The multilingual editor passes an
     * explicit value; legacy callers still target the installation language.
     */
    private function resolveTemplateLocale(?string $locale): string
    {
        if ($locale !== null && $locale !== '') {
            return $locale;
        }
        return \App\Support\I18n::getInstallationLocale();
    }

    private function prepare(string $sql): mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare SQL: ' . $this->db->error);
        }
        return $stmt;
    }
}
