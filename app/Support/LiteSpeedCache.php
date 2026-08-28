<?php
declare(strict_types=1);

namespace App\Support;

/** Shared LiteSpeed Cache configuration, tags and durable purge queue. */
final class LiteSpeedCache
{
    private const HTACCESS_MARKER = '# === Pinakes LiteSpeed privacy bypass ===';
    private const HTACCESS_END_MARKER = '# === end Pinakes LiteSpeed privacy bypass ===';
    public const MARKER_HEADER = 'X-Pinakes-Edge-Page';
    public const TAG_ALL = 'pinakes';
    public const TAG_HOME = 'pinakes_home';
    public const TAG_CATALOG = 'pinakes_catalog';
    public const TAG_BOOKS = 'pinakes_books';

    /** @var array<string, true> */
    private static array $queuedTags = [];
    private static bool $cliDispatcherRegistered = false;
    private static ?bool $lookupBypassCache = null;

    public static function enabled(): bool
    {
        return !self::blockedByContainer()
            && filter_var(ConfigStore::get('cache.litespeed_enabled', false), FILTER_VALIDATE_BOOLEAN)
            && self::lookupBypassInstalled();
    }

    /** LiteSpeed/LSCache is unsupported by the official Apache Docker image. */
    public static function blockedByContainer(): bool
    {
        return ContainerRuntime::detected();
    }

    public static function ttlFor(string $page): int
    {
        $defaults = ['home' => 300, 'catalog' => 120, 'book' => 300];
        $default = $defaults[$page] ?? 120;
        $ttl = (int) ConfigStore::get('cache.litespeed_' . $page . '_ttl', $default);
        return max(30, min(86400, $ttl));
    }

    public static function serverDetected(): bool
    {
        $software = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));
        return str_contains($software, 'litespeed')
            || isset($_SERVER['LSWS_EDITION']);
    }

    public static function lookupBypassInstalled(?string $path = null): bool
    {
        $usesDefaultPath = $path === null;
        if ($usesDefaultPath && self::$lookupBypassCache !== null) {
            return self::$lookupBypassCache;
        }
        $content = @file_get_contents($path ?? self::htaccessPath());
        $installed = is_string($content) && self::blockHasProtectiveRules($content);
        if ($usesDefaultPath) {
            self::$lookupBypassCache = $installed;
        }
        return $installed;
    }

    /**
     * A marker pair is not proof of protection: a hand-edited (or truncated)
     * file can carry the two comments around an EMPTY block, which would let
     * the admin enable LSCache with none of the fail-closed rules in place —
     * a shared hit could then be served for an authenticated, cookie-bearing
     * or Authorization request. Require the block to actually contain the
     * no-cache guards for non-GET/HEAD methods, Authorization headers and any
     * non-locale cookie before treating the bypass as installed.
     */
    private static function blockHasProtectiveRules(string $content): bool
    {
        $start = strpos($content, self::HTACCESS_MARKER);
        if ($start === false) {
            return false;
        }
        $end = strpos($content, self::HTACCESS_END_MARKER, $start);
        if ($end === false) {
            return false;
        }
        $block = substr($content, $start, $end - $start);

        // Only ACTIVE (uncommented) directive lines count. A block whose
        // no-cache guards are commented out (`# RewriteRule ... no-cache`) must
        // NOT be treated as installed — otherwise the admin could enable LSCache
        // with the protection disabled and a shared hit could be served to an
        // authenticated / cookie-bearing / Authorization request.
        $directives = [];
        foreach (preg_split('/\R/', $block) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $directives[] = $line;
        }

        $methodGuard = false;
        $authorizationGuard = false;
        $cookieGuard = false;
        $localeVary = false;

        foreach ($directives as $index => $directive) {
            if (self::isLocaleVaryRule($directive)) {
                $localeVary = true;
            }
            if (!self::isNoCacheRule($directive)) {
                continue;
            }

            // RewriteCond applies only to the immediately following
            // RewriteRule. Walk back over that rule's contiguous condition
            // group; unrelated tokens elsewhere in the marker cannot satisfy
            // this validator.
            $conditions = [];
            for ($i = $index - 1; $i >= 0; $i--) {
                $condition = self::parseRewriteCondition($directives[$i]);
                if ($condition === null) {
                    break;
                }
                array_unshift($conditions, $condition);
            }

            // Extra conditions would narrow the bypass and could make it miss
            // the request it is meant to protect. Accept only the exact groups
            // installed by ensureLookupBypass(), and reject OR chaining.
            if (count($conditions) === 1) {
                [$subject, $pattern] = $conditions[0];
                $methodGuard = $methodGuard || (
                    strcasecmp($subject, '%{REQUEST_METHOD}') === 0
                    && $pattern === '!^(GET|HEAD)$'
                    && self::hasOnlyFlags($conditions[0][2], ['NC'])
                );
                $authorizationGuard = $authorizationGuard || (
                    strcasecmp($subject, '%{HTTP:Authorization}') === 0
                    && $pattern === '.'
                    && self::hasOnlyFlags($conditions[0][2], [])
                );
            }

            if (count($conditions) === 2) {
                [$nonEmptyCookie, $localeException] = $conditions;
                if (strcasecmp($nonEmptyCookie[0], '%{HTTP:Cookie}') === 0
                    && $nonEmptyCookie[1] === '!^$'
                    && self::hasOnlyFlags($nonEmptyCookie[2], [])
                    && strcasecmp($localeException[0], '%{HTTP:Cookie}') === 0
                    && $localeException[1] === '!^\s*pinakes_locale=[A-Za-z]{2}_[A-Za-z]{2}\s*;?\s*$'
                    && self::hasOnlyFlags($localeException[2], ['NC'])) {
                    $cookieGuard = true;
                }
            }
        }

        return $methodGuard && $authorizationGuard && $cookieGuard && $localeVary;
    }

    /** @return array{string, string, string}|null Parsed subject, pattern and flags. */
    private static function parseRewriteCondition(string $directive): ?array
    {
        $parts = preg_split('/\s+/', $directive, 4);
        if (!is_array($parts) || count($parts) < 3 || strcasecmp($parts[0], 'RewriteCond') !== 0) {
            return null;
        }

        return [$parts[1], $parts[2], $parts[3] ?? ''];
    }

    /** Compare an Apache flags token without depending on order or case. */
    private static function hasOnlyFlags(string $flags, array $expected): bool
    {
        $flags = trim($flags, "[] \t");
        $actual = $flags === '' ? [] : array_map('strtoupper', explode(',', $flags));
        $expected = array_map('strtoupper', $expected);
        sort($actual);
        sort($expected);
        return $actual === $expected;
    }

    /** True only for an active RewriteRule that sets LSCache's no-cache flag. */
    private static function isNoCacheRule(string $directive): bool
    {
        return preg_match('/^RewriteRule\s+\.\*\s+-\s+\[E=Cache-Control:no-cache\]$/i', $directive) === 1;
    }

    /** True only for the request-lookup locale vary rule. */
    private static function isLocaleVaryRule(string $directive): bool
    {
        return preg_match('/^RewriteRule\s+\.\*\s+-\s+\[E=Cache-Vary:pinakes_locale\]$/i', $directive) === 1;
    }

    /**
     * Install the request-time privacy bypass into an existing deployment.
     * Updaters preserve public/.htaccess, so relying on the packaged template
     * alone would make an upgraded site unsafe to enable from the admin UI.
     */
    public static function ensureLookupBypass(?string $path = null): bool
    {
        $usesDefaultPath = $path === null;
        $path ??= self::htaccessPath();
        if (self::lookupBypassInstalled($path)) {
            if ($usesDefaultPath) {
                self::$lookupBypassCache = true;
            }
            return true;
        }

        $content = @file_get_contents($path);
        if (!is_string($content)) {
            // file_get_contents() also fails on a PRESENT but unreadable file.
            // atomicWrite() renames into place, which needs only directory
            // write permission, so seeding from .dist here would clobber the
            // operator's existing (merely unreadable) .htaccess and its custom
            // rules. Only fall back to the template when the target is truly
            // absent.
            if (file_exists($path)) {
                return false;
            }
            $content = @file_get_contents($path . '.dist');
            if (!is_string($content) || !str_contains($content, self::HTACCESS_MARKER)) {
                return false;
            }
            $written = self::atomicWrite($path, $content, 0644);
            if ($usesDefaultPath) {
                self::$lookupBypassCache = $written ? true : null;
            }
            return $written;
        }

        // A partial marker means a prior/manual edit is ambiguous. Fail closed
        // instead of appending a second block around possibly broken rules.
        if (str_contains($content, self::HTACCESS_MARKER) || str_contains($content, self::HTACCESS_END_MARKER)) {
            return false;
        }

        $anchor = '    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]';
        if (substr_count($content, $anchor) !== 1) {
            return false;
        }

        $block = <<<'HTACCESS'


    # === Pinakes LiteSpeed privacy bypass ===
    # Prevent a shared hit before PHP can apply user/session checks. A sole
    # validated locale cookie is safe because responses vary on that cookie.
    <IfModule LiteSpeed>
        # The locale must be part of the lookup key before PHP is reached.
        # X-LiteSpeed-Vary repeats this on cacheable responses, but this rule
        # also protects the first lookup after a worker/cache restart.
        RewriteRule .* - [E=Cache-Vary:pinakes_locale]

        RewriteCond %{REQUEST_METHOD} !^(GET|HEAD)$ [NC]
        RewriteRule .* - [E=Cache-Control:no-cache]

        RewriteCond %{HTTP:Authorization} .
        RewriteRule .* - [E=Cache-Control:no-cache]

        RewriteCond %{HTTP:Cookie} !^$
        RewriteCond %{HTTP:Cookie} !^\s*pinakes_locale=[A-Za-z]{2}_[A-Za-z]{2}\s*;?\s*$ [NC]
        RewriteRule .* - [E=Cache-Control:no-cache]
    </IfModule>
    # === end Pinakes LiteSpeed privacy bypass ===
HTACCESS;
        $updated = str_replace($anchor, $anchor . $block, $content);
        $permissions = @fileperms($path);
        $written = self::atomicWrite($path, $updated, is_int($permissions) ? ($permissions & 0777) : 0644);
        if ($usesDefaultPath) {
            self::$lookupBypassCache = $written ? true : null;
        }
        return $written;
    }

    public static function cliPurgeConfigured(): bool
    {
        return self::purgeSecret() !== '' && self::purgeUrl() !== '';
    }

    public static function authorizesPurgeSecret(string $provided): bool
    {
        $configured = self::purgeSecret();
        return $configured !== '' && $provided !== '' && hash_equals($configured, $provided);
    }

    /** @param string[] $tags */
    public static function queuePurge(array $tags, bool $force = false): void
    {
        if (!$force && !self::enabled()) {
            return;
        }

        $tags = self::sanitizeTags($tags);
        if ($tags === []) {
            return;
        }

        foreach ($tags as $tag) {
            self::$queuedTags[$tag] = true;
        }

        $path = self::queuePath();
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0770, true);
        }
        $handle = @fopen($path, 'c+');
        if ($handle !== false) {
            try {
                if (flock($handle, LOCK_EX)) {
                    $existing = stream_get_contents($handle);
                    $merged = array_merge(
                        preg_split('/\s+/', trim((string) $existing)) ?: [],
                        $tags
                    );
                    $merged = self::sanitizeTags($merged);
                    rewind($handle);
                    ftruncate($handle, 0);
                    if ($merged !== []) {
                        fwrite($handle, implode("\n", $merged) . "\n");
                    }
                    fflush($handle);
                    flock($handle, LOCK_UN);
                }
            } finally {
                fclose($handle);
            }
        }

        $cliDisabled = filter_var(
            getenv('PINAKES_DISABLE_CLI_PURGE') ?: ($_ENV['PINAKES_DISABLE_CLI_PURGE'] ?? false),
            FILTER_VALIDATE_BOOLEAN
        );
        if (PHP_SAPI === 'cli' && !$cliDisabled && !self::$cliDispatcherRegistered) {
            self::$cliDispatcherRegistered = true;
            register_shutdown_function([self::class, 'dispatchCliPurge']);
        }
    }

    /** @return string[] */
    public static function consumeQueuedPurge(): array
    {
        $tags = array_keys(self::$queuedTags);
        self::$queuedTags = [];

        $path = self::queuePath();
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return self::sanitizeTags($tags);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return self::sanitizeTags($tags);
            }
            $queued = stream_get_contents($handle);
            rewind($handle);
            ftruncate($handle, 0);
            fflush($handle);
            flock($handle, LOCK_UN);
            $tags = array_merge($tags, preg_split('/\s+/', trim((string) $queued)) ?: []);
        } finally {
            fclose($handle);
        }

        return self::sanitizeTags($tags);
    }

    /** @param string[] $tags */
    public static function purgeHeader(array $tags): string
    {
        $parts = ['public'];
        foreach (self::sanitizeTags($tags) as $tag) {
            $parts[] = 'tag=' . $tag;
        }
        return implode(',', $parts);
    }

    public static function dispatchCliPurge(): void
    {
        if (!self::cliPurgeConfigured()) {
            return;
        }

        $url = self::purgeUrl();
        $secret = self::purgeSecret();
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            if ($curl === false) {
                return;
            }
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => '',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => [
                    'X-Pinakes-Purge-Secret: ' . $secret,
                    'Content-Length: 0',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            curl_exec($curl);
            /* curl_close(): no-op since PHP 8.0, deprecated 8.5 */
            return;
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "X-Pinakes-Purge-Secret: {$secret}\r\nContent-Length: 0\r\n",
            'content' => '',
            'timeout' => 5,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        @file_get_contents($url, false, $context);
    }

    private static function queuePath(): string
    {
        return dirname(__DIR__, 2) . '/storage/cache/litespeed-purge.queue';
    }

    private static function htaccessPath(): string
    {
        return dirname(__DIR__, 2) . '/public/.htaccess';
    }

    private static function atomicWrite(string $path, string $content, int $permissions): bool
    {
        $temporary = @tempnam(dirname($path), '.pinakes-litespeed-');
        if (!is_string($temporary)) {
            return false;
        }
        try {
            if (@file_put_contents($temporary, $content, LOCK_EX) === false) {
                return false;
            }
            @chmod($temporary, $permissions);
            return @rename($temporary, $path);
        } finally {
            if (is_file($temporary)) {
                // nosemgrep: php.lang.security.unlink-use.unlink-use -- random
                // temp file created in the fixed public directory above.
                @unlink($temporary);
            }
        }
    }

    private static function purgeSecret(): string
    {
        $configured = trim((string) (getenv('LITESPEED_PURGE_SECRET') ?: ($_ENV['LITESPEED_PURGE_SECRET'] ?? '')));
        if ($configured !== '') {
            // Refuse weak operator overrides: this token authenticates a
            // cache-wide purge endpoint and must not be guessable or able to
            // inject bytes into the outbound HTTP header.
            return preg_match('/^[\x21-\x7E]{32,256}$/D', $configured) === 1 ? $configured : '';
        }

        // Same-host installations need no manual secret: create a private,
        // application-local token shared by web and CLI. Operators can still
        // override it through environment for multi-node deployments.
        $path = dirname(__DIR__, 2) . '/storage/cache/litespeed-purge.secret';
        if (is_file($path)) {
            $secret = trim((string) @file_get_contents($path));
            if (preg_match('/^[a-f0-9]{64}$/D', $secret) === 1) {
                return $secret;
            }
        }
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0770, true);
        }
        try {
            $secret = bin2hex(random_bytes(32));
        } catch (\Throwable) {
            return '';
        }
        $handle = @fopen($path, 'x');
        if ($handle !== false) {
            fwrite($handle, $secret . "\n");
            fclose($handle);
            @chmod($path, 0600);
            return $secret;
        }

        $existing = trim((string) @file_get_contents($path));
        return preg_match('/^[a-f0-9]{64}$/D', $existing) === 1 ? $existing : '';
    }

    private static function purgeUrl(): string
    {
        $explicit = trim((string) (getenv('LITESPEED_PURGE_URL') ?: ($_ENV['LITESPEED_PURGE_URL'] ?? '')));
        if ($explicit !== '') {
            return self::isHttpUrl($explicit) ? $explicit : '';
        }

        $canonical = trim((string) (getenv('APP_CANONICAL_URL') ?: ($_ENV['APP_CANONICAL_URL'] ?? '')));
        if (!self::isHttpUrl($canonical)) {
            return '';
        }
        return rtrim($canonical, '/') . '/_pinakes/litespeed-purge';
    }

    private static function isHttpUrl(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme === 'https') {
            return true;
        }
        if ($scheme !== 'http') {
            return false;
        }

        // The purge secret grants cache-wide invalidation. Plain HTTP is safe
        // only when the request never leaves the host; remote endpoints must
        // use TLS so the secret cannot be observed in transit.
        $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }
        return false;
    }

    /** @param mixed[] $tags @return string[] */
    private static function sanitizeTags(array $tags): array
    {
        $safe = [];
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                continue;
            }
            $tag = strtolower(trim($tag));
            if ($tag !== '' && preg_match('/^[a-z0-9_-]{1,64}$/D', $tag) === 1) {
                $safe[$tag] = true;
            }
        }
        return array_keys($safe);
    }
}
