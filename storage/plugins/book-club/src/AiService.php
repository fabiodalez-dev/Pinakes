<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Support\SecureLogger;
use mysqli;

/**
 * AI module (plan §7.17): settings access + Anthropic-compatible HTTP client
 * + persistence for the generated outputs (bookclub_ai_outputs).
 *
 * The API key lives in plugin_settings, AES-encrypted at rest: EVERY read and
 * write goes through App\Support\PluginManager (getSetting/setSetting) —
 * never raw SQL on plugin_settings. The key is never logged and never leaves
 * the server other than in the x-api-key request header; the UI only ever
 * sees the ****last4 mask.
 */
class AiService
{
    public const SETTING_API_KEY = 'ai_api_key';
    public const SETTING_MODEL = 'ai_model';
    public const SETTING_ENDPOINT = 'ai_endpoint';
    public const SETTING_ENABLED = 'ai_enabled';

    public const DEFAULT_MODEL = 'claude-sonnet-5';
    public const DEFAULT_ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /** Hard cost cap: max generations per club per rolling 24h window. */
    public const MAX_OUTPUTS_PER_DAY = 20;

    private mysqli $db;
    private ?\App\Support\PluginManager $pluginManager = null;
    private ?int $pluginId = null;
    private bool $pluginIdResolved = false;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------------
    // Plugin settings (via PluginManager — values are encrypted at rest)
    // ------------------------------------------------------------------

    private function pluginManager(): \App\Support\PluginManager
    {
        if ($this->pluginManager === null) {
            $this->pluginManager = new \App\Support\PluginManager($this->db, new \App\Support\HookManager($this->db));
        }
        return $this->pluginManager;
    }

    /** Resolve the book-club row id in the core `plugins` table (cached). */
    public function pluginId(): ?int
    {
        if ($this->pluginIdResolved) {
            return $this->pluginId;
        }
        $this->pluginIdResolved = true;
        $stmt = $this->db->prepare("SELECT id FROM plugins WHERE name = 'book-club' LIMIT 1");
        if ($stmt === false) {
            SecureLogger::error('[BookClub:ai] plugin id lookup prepare failed: ' . $this->db->error);
            return null;
        }
        $stmt->execute();
        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();
        $this->pluginId = isset($row['id']) ? (int) $row['id'] : null;
        return $this->pluginId;
    }

    private function setting(string $key, string $default = ''): string
    {
        $pluginId = $this->pluginId();
        if ($pluginId === null) {
            return $default;
        }
        try {
            $value = $this->pluginManager()->getSetting($pluginId, $key, $default);
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub:ai] getSetting failed for ' . $key . ': ' . $e->getMessage());
            return $default;
        }
        return is_string($value) ? $value : $default;
    }

    public function saveSetting(string $key, string $value): bool
    {
        $pluginId = $this->pluginId();
        if ($pluginId === null) {
            return false;
        }
        try {
            return $this->pluginManager()->setSetting($pluginId, $key, $value);
        } catch (\Throwable $e) {
            // Never include the value: it may be the API key.
            SecureLogger::error('[BookClub:ai] setSetting failed for ' . $key . ': ' . $e->getMessage());
            return false;
        }
    }

    public function apiKey(): string
    {
        return trim($this->setting(self::SETTING_API_KEY));
    }

    public function model(): string
    {
        $model = trim($this->setting(self::SETTING_MODEL));
        return $model !== '' ? $model : self::DEFAULT_MODEL;
    }

    public function endpoint(): string
    {
        $endpoint = trim($this->setting(self::SETTING_ENDPOINT));
        return $endpoint !== '' ? $endpoint : self::DEFAULT_ENDPOINT;
    }

    public function isEnabled(): bool
    {
        return $this->setting(self::SETTING_ENABLED, '0') === '1';
    }

    /** Globally usable: admin switched it on AND an API key is stored. */
    public function isConfigured(): bool
    {
        return $this->isEnabled() && $this->apiKey() !== '';
    }

    /** Display mask — the key itself must never reach any output. */
    public function maskedKey(): string
    {
        $key = $this->apiKey();
        if ($key === '') {
            return '';
        }
        if (strlen($key) <= 4) {
            return '****';
        }
        return '****' . substr($key, -4);
    }

    // ------------------------------------------------------------------
    // Generation (Anthropic Messages API compatible)
    // ------------------------------------------------------------------

    /**
     * One-shot completion: POST {model, max_tokens, system, messages} to the
     * configured endpoint and return content[0].text. On ANY failure
     * (transport, HTTP status, unparsable body) log and return null — the
     * caller shows a flash. NO retries by design (cost safety).
     */
    public function generate(string $system, string $user): ?string
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '' || !function_exists('curl_init')) {
            SecureLogger::warning('[BookClub:ai] generate() called without API key or cURL extension');
            return null;
        }

        $payload = json_encode([
            'model' => $this->model(),
            'max_tokens' => 1024,
            'system' => $system,
            'messages' => [
                ['role' => 'user', 'content' => $user],
            ],
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            SecureLogger::error('[BookClub:ai] request payload JSON encoding failed');
            return null;
        }

        try {
            $ch = curl_init($this->endpoint());
            if ($ch === false) {
                SecureLogger::error('[BookClub:ai] curl_init failed');
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
                // SSRF/redirect/TLS hardening: HTTPS-only, no redirects,
                // certificate verification always on.
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'anthropic-version: 2023-06-01',
                    'x-api-key: ' . $apiKey,
                ],
            ]);
            $raw = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($raw === false || $raw === '') {
                SecureLogger::error('[BookClub:ai] HTTP request failed: ' . ($curlError !== '' ? $curlError : 'empty response'));
                return null;
            }
            if ($httpCode < 200 || $httpCode >= 300) {
                // Body may contain provider error details but never the key.
                SecureLogger::error('[BookClub:ai] endpoint returned HTTP ' . $httpCode . ': ' . mb_substr((string) $raw, 0, 500));
                return null;
            }
            $data = json_decode((string) $raw, true);
            $text = is_array($data) ? ($data['content'][0]['text'] ?? null) : null;
            if (!is_string($text) || trim($text) === '') {
                SecureLogger::error('[BookClub:ai] unexpected response shape (no content[0].text)');
                return null;
            }
            return trim($text);
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub:ai] generate() exception: ' . $e->getMessage());
            return null;
        }
    }

    // ------------------------------------------------------------------
    // bookclub_ai_outputs persistence
    // ------------------------------------------------------------------

    public function saveOutput(int $clubId, string $kind, int $entityId, string $content, string $model, ?int $createdBy): ?int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO bookclub_ai_outputs (club_id, kind, entity_id, content, model, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            SecureLogger::error('[BookClub:ai] saveOutput prepare failed: ' . $this->db->error);
            return null;
        }
        $stmt->bind_param('isissi', $clubId, $kind, $entityId, $content, $model, $createdBy);
        $ok = $stmt->execute();
        if (!$ok) {
            SecureLogger::error('[BookClub:ai] saveOutput execute failed: ' . $stmt->error);
        }
        $stmt->close();
        return $ok ? (int) $this->db->insert_id : null;
    }

    /**
     * Generation history for the module page, newest first, with the creator
     * name and the human title of the source entity (book or meeting).
     *
     * @return list<array<string, mixed>>
     */
    public function listOutputs(int $clubId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare(
            "SELECT o.id, o.kind, o.entity_id, o.content, o.model, o.created_at,
                    u.nome AS creator_nome, u.cognome AS creator_cognome,
                    l.titolo AS book_title, mt.title AS meeting_title
               FROM bookclub_ai_outputs o
               LEFT JOIN utenti u ON u.id = o.created_by
               LEFT JOIN bookclub_books cb ON o.kind = 'questions' AND cb.id = o.entity_id
               LEFT JOIN libri l ON l.id = cb.libro_id
               LEFT JOIN bookclub_meetings mt ON o.kind = 'minutes' AND mt.id = o.entity_id
              WHERE o.club_id = ?
              ORDER BY o.created_at DESC, o.id DESC
              LIMIT ?"
        );
        if ($stmt === false) {
            SecureLogger::error('[BookClub:ai] listOutputs prepare failed: ' . $this->db->error);
            return [];
        }
        $stmt->bind_param('ii', $clubId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /** Rows generated for the club in the last $hours hours (cost cap). */
    public function countRecentOutputs(int $clubId, int $hours = 24): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS n FROM bookclub_ai_outputs
              WHERE club_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)'
        );
        if ($stmt === false) {
            SecureLogger::error('[BookClub:ai] countRecentOutputs prepare failed: ' . $this->db->error);
            return 0;
        }
        $stmt->bind_param('ii', $clubId, $hours);
        $stmt->execute();
        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();
        return (int) ($row['n'] ?? 0);
    }

    /** Whether the hard daily cap allows one more generation for the club. */
    public function underDailyCap(int $clubId): bool
    {
        return $this->countRecentOutputs($clubId, 24) < self::MAX_OUTPUTS_PER_DAY;
    }

    // ------------------------------------------------------------------
    // Prompt sources
    // ------------------------------------------------------------------

    /**
     * Human name of the language the model must answer in, derived from the
     * session/app locale ($_SESSION['locale'], default it_IT): it_* →
     * italiano, en_* → inglese, de_* → tedesco, fr_* → francese, anything
     * else falls back to italiano. Returned through __() so the name reads
     * naturally inside the (translated) system prompt.
     */
    public function promptLanguageName(): string
    {
        $locale = $_SESSION['locale'] ?? 'it_IT';
        $prefix = strtolower(substr(is_string($locale) ? $locale : 'it_IT', 0, 2));
        switch ($prefix) {
            case 'en':
                return __('inglese');
            case 'de':
                return __('tedesco');
            case 'fr':
                return __('francese');
            default:
                return __('italiano');
        }
    }

    /**
     * Plain-text description of a catalog book for the questions prompt
     * (descrizione stores TinyMCE HTML → strip tags, collapse whitespace).
     */
    public function bookDescription(int $libroId): string
    {
        $stmt = $this->db->prepare('SELECT descrizione FROM libri WHERE id = ? AND deleted_at IS NULL');
        if ($stmt === false) {
            SecureLogger::error('[BookClub:ai] bookDescription prepare failed: ' . $this->db->error);
            return '';
        }
        $stmt->bind_param('i', $libroId);
        $stmt->execute();
        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();
        $plain = trim(html_entity_decode(strip_tags((string) ($row['descrizione'] ?? '')), ENT_QUOTES, 'UTF-8'));
        $plain = (string) preg_replace('/\s+/u', ' ', $plain);
        return mb_substr($plain, 0, 2000);
    }
}
