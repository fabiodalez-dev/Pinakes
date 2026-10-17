<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Who may open a digital attachment, and the signed links the mobile app uses.
 *
 * Each attachment in libri.digital_attachments carries a `visibility`:
 *   - inherit: the library's default (Digital Library settings), `public` unless changed;
 *   - public:  anyone, signed in or not;
 *   - users:   any signed-in reader;
 *   - staff:   admin and staff only;
 *   - loan:    admin and staff, and the reader who has that book on loan right now.
 *
 * The decision lives here, in core, so every place that hands out a link (the public
 * book page, the file route, the public API, OAI-PMH and the Mobile API) answers the
 * same way, even with the Digital Library plugin switched off. Any failure while
 * deciding closes the file (staff only) rather than opening it.
 */
final class DigitalAccess
{
    public const CATEGORY = 'digital_library';
    public const DEFAULT_KEY = 'default_visibility';
    public const SHOW_LOCKED_KEY = 'show_locked_entries';
    public const SIGNING_KEY = 'url_signing_secret';

    public const VISIBILITIES = ['inherit', 'public', 'users', 'staff', 'loan'];
    private const CONCRETE = ['public', 'users', 'staff', 'loan'];

    public const GRANTED = 'granted';
    public const LOGIN_REQUIRED = 'login_required';
    public const LOAN_REQUIRED = 'loan_required';
    public const STAFF_ONLY = 'staff_only';

    /** Most to least open: with several records naming one file, the most open answer wins. */
    private const RANK = [self::GRANTED => 0, self::LOGIN_REQUIRED => 1, self::LOAN_REQUIRED => 2, self::STAFF_ONLY => 3];

    /** URL path prefix of the files this class protects. */
    public const URL_PREFIX = '/uploads/digital/';

    /** Signed links for the app last a day: long enough for an audiobook, short enough to expire. */
    public const SIGNED_TTL = 86400;

    /** @var array<int, string> default visibility per connection (one read per request) */
    private static array $defaultCache = [];

    /** The library's default for attachments saved as `inherit`. */
    public static function defaultVisibility(\mysqli $db): string
    {
        $key = spl_object_id($db);
        if (!isset(self::$defaultCache[$key])) {
            $value = 'public';
            try {
                $stored = (new \App\Models\SettingsRepository($db))->get(self::CATEGORY, self::DEFAULT_KEY, 'public');
                if (in_array($stored, self::CONCRETE, true)) {
                    $value = $stored;
                }
            } catch (\Throwable $e) {
                SecureLogger::error('[DigitalAccess] default visibility read failed', ['error' => $e->getMessage()]);
            }
            self::$defaultCache[$key] = $value;
        }
        return self::$defaultCache[$key];
    }

    /** Forget the cached default (after the settings page saved a new one). */
    public static function forgetDefault(): void
    {
        self::$defaultCache = [];
    }

    /** A stored visibility as it applies now: `inherit` (or anything unknown) follows the default. */
    public static function resolve(?string $visibility, \mysqli $db): string
    {
        return in_array($visibility, self::CONCRETE, true) ? (string) $visibility : self::defaultVisibility($db);
    }

    /** @param array<string, mixed>|null $viewer */
    public static function isStaff(?array $viewer): bool
    {
        return $viewer !== null && in_array($viewer['tipo_utente'] ?? '', ['admin', 'staff'], true);
    }

    /**
     * Whether $viewer may open one attachment of book $bookId.
     *
     * @param array<string, mixed> $attachment a row of DigitalAttachments::fromBook()
     * @param array<string, mixed>|null $viewer the signed-in user (id, tipo_utente) or null
     */
    public static function decide(array $attachment, ?array $viewer, int $bookId, \mysqli $db): string
    {
        $visibility = self::resolve(is_string($attachment['visibility'] ?? null) ? $attachment['visibility'] : null, $db);
        if ($visibility === 'public' || self::isStaff($viewer)) {
            return self::GRANTED;
        }
        $userId = (int) ($viewer['id'] ?? 0);
        if ($userId <= 0) {
            return $visibility === 'staff' ? self::STAFF_ONLY : self::LOGIN_REQUIRED;
        }
        return match ($visibility) {
            'users' => self::GRANTED,
            'loan' => self::hasActiveLoan($bookId, $userId, $db) ? self::GRANTED : self::LOAN_REQUIRED,
            default => self::STAFF_ONLY,
        };
    }

    /**
     * The attachments of a record with what $viewer may do with each.
     *
     * @param array<string, mixed> $book
     * @param list<array<string, mixed>> $attachments DigitalAttachments::fromBook($book)
     * @param array<string, mixed>|null $viewer
     * @return list<array<string, mixed>> each row plus `access` and `visibility_resolved`
     */
    public static function forViewer(array $book, array $attachments, ?array $viewer, \mysqli $db): array
    {
        $bookId = (int) ($book['id'] ?? 0);
        $out = [];
        foreach ($attachments as $attachment) {
            $attachment['visibility_resolved'] = self::resolve(is_string($attachment['visibility'] ?? null) ? $attachment['visibility'] : null, $db);
            $attachment['access'] = self::decide($attachment, $viewer, $bookId, $db);
            $out[] = $attachment;
        }
        return $out;
    }

    /** Whether a closed entry should still be listed (with the reason) on the book page. */
    public static function showLockedEntries(\mysqli $db): bool
    {
        try {
            return (new \App\Models\SettingsRepository($db))->get(self::CATEGORY, self::SHOW_LOCKED_KEY, '1') !== '0';
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * The file name (relative to the Digital Library folder) of a protected upload URL of
     * THIS installation, or null when the URL is not one. A URL on another host is never a
     * local file, whatever its path: it is not served here, and must never carry a signature.
     */
    public static function fileNameOf(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }
        if (isset($parts['host']) && !self::isOwnHost((string) $parts['host'])) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '');
        $at = strpos($path, self::URL_PREFIX);
        if ($at === false) {
            return null;
        }
        $name = rawurldecode(substr($path, $at + strlen(self::URL_PREFIX)));
        return self::isValidName($name) ? $name : null;
    }

    /**
     * The one rule for a Digital Library file name, shared by the file route and the
     * record matching so a name one serves is always a name the other can match. Names
     * from older versions (spaces, accented letters, a subfolder of the old folder) stay
     * valid; what keeps a request inside the folder is the segment rule here and the
     * realpath containment in ProtectedUploadController.
     */
    public static function isValidName(string $name): bool
    {
        if ($name === '' || strlen($name) > 255 || !mb_check_encoding($name, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F\\\\]/', $name) === 1) {
            return false;
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment[0] === '.') {
                return false;
            }
        }
        return true;
    }

    /** The URL path of a Digital Library file, each segment encoded. */
    public static function urlPathOf(string $name): string
    {
        return self::URL_PREFIX . implode('/', array_map('rawurlencode', explode('/', $name)));
    }

    /** Whether $host is this installation's (the canonical base URL's) host. */
    private static function isOwnHost(string $host): bool
    {
        $own = (string) (parse_url(HtmlHelper::absoluteUrl('/'), PHP_URL_HOST) ?? '');
        return $own !== '' && strcasecmp($own, $host) === 0;
    }

    /**
     * $url as an anonymous consumer may receive it (public API, OAI-PMH harvesters):
     * a protected file only when anyone may open it, else null. A link to another site is
     * not served by Pinakes, so it follows the record's own attachment: with $bookId given,
     * an off-site link of an attachment closed to anonymous readers is left out too.
     * Without a record (a URL no Digital Library record describes) it is passed as is.
     */
    public static function anonymousUrl(?string $url, \mysqli $db, ?int $bookId = null): ?string
    {
        if ($url === null || trim($url) === '') {
            return $url;
        }
        $name = self::fileNameOf($url);
        if ($name !== null) {
            return self::authorizeFile($name, null, $db) === self::GRANTED ? $url : null;
        }
        if ($bookId === null || $bookId <= 0) {
            return $url;
        }
        try {
            $book = self::loadBook($bookId, $db);
            foreach ($book !== null ? DigitalAttachments::fromBook($book) : [] as $attachment) {
                if (rawurldecode($attachment['url']) === rawurldecode(trim($url))) {
                    return self::decide($attachment, null, $bookId, $db) === self::GRANTED ? $url : null;
                }
            }
            return $url;
        } catch (\Throwable $e) {
            // The record could not be read (the plugin's columns missing, a database error):
            // the library default decides, as for an attachment left on "inherit".
            return self::defaultVisibility($db) === 'public' ? $url : null;
        }
    }

    /**
     * Whether $viewer may download the protected file $fileName.
     *
     * The file is matched to the records that list it (through the Digital Library index
     * when it exists, else by a scan). A file no live record lists is staff only: an
     * upload never saved on a record, or one whose record was deleted, is not public.
     *
     * @param array<string, mixed>|null $viewer
     */
    public static function authorizeFile(string $fileName, ?array $viewer, \mysqli $db): string
    {
        if (self::isStaff($viewer)) {
            return self::GRANTED;
        }
        try {
            $best = self::STAFF_ONLY;
            foreach (self::recordsListing($fileName, $db) as $book) {
                foreach (DigitalAttachments::fromBook($book) as $attachment) {
                    if (self::fileNameOf($attachment['url']) !== $fileName) {
                        continue;
                    }
                    $decision = self::decide($attachment, $viewer, (int) $book['id'], $db);
                    if (self::RANK[$decision] < self::RANK[$best]) {
                        $best = $decision;
                    }
                    if ($best === self::GRANTED) {
                        return $best;
                    }
                }
            }
            return $best;
        } catch (\Throwable $e) {
            SecureLogger::error('[DigitalAccess] authorizeFile failed', ['file' => $fileName, 'error' => $e->getMessage()]);
            return self::STAFF_ONLY;
        }
    }

    /**
     * The live records that list $fileName. The Digital Library index answers in one
     * key lookup while no record has changed since it was verified (libri.updated_at is
     * indexed, so the check is one index read); after any change, or when an indexed
     * record no longer lists the file, the records are scanned again. The index is a
     * cache, never the source of truth: a file attached to a second record is seen.
     *
     * @return list<array<string, mixed>>
     */
    private static function recordsListing(string $fileName, \mysqli $db): array
    {
        $indexed = self::indexedBookIds($fileName, $db);
        if ($indexed !== null && $indexed !== [] && self::indexOutdated($fileName, $db)) {
            self::forgetIndex($fileName, $db);
            $indexed = [];
        }
        if ($indexed !== null && $indexed !== []) {
            $books = [];
            $stale = false;
            foreach ($indexed as $bookId) {
                $book = self::loadBook($bookId, $db);
                if ($book !== null && self::lists($book, $fileName)) {
                    $books[] = $book;
                } else {
                    $stale = true;
                }
            }
            if (!$stale) {
                return $books;
            }
            self::forgetIndex($fileName, $db);
        }

        // Stored URLs spell a name in more than one way (percent-encoded spaces, raw or
        // encoded accented letters): every character outside the plain ASCII set becomes a
        // wildcard, and lists() below keeps only the records that really name this file.
        $pieces = preg_split('#[^A-Za-z0-9._/-]+#', $fileName) ?: [$fileName];
        $like = '%' . addcslashes(self::URL_PREFIX, '%_\\')
            . implode('%', array_map(static fn (string $p): string => addcslashes($p, '%_\\'), $pieces)) . '%';
        $stmt = $db->prepare('SELECT id, file_url, audio_url, digital_attachments FROM libri
            WHERE deleted_at IS NULL AND (file_url LIKE ? OR audio_url LIKE ? OR digital_attachments LIKE ?)');
        if ($stmt === false) {
            throw new \RuntimeException('records scan prepare failed: ' . $db->error);
        }
        $stmt->bind_param('sss', $like, $like, $like);
        $stmt->execute();
        $result = $stmt->get_result();
        $books = [];
        while ($result !== false && ($row = $result->fetch_assoc())) {
            if (self::lists($row, $fileName)) {
                $books[] = $row;
            }
        }
        $stmt->close();
        if ($indexed !== null) {
            self::remember($fileName, array_map(static fn (array $b): int => (int) $b['id'], $books), $db);
        }
        return $books;
    }

    /** @param array<string, mixed> $book */
    private static function lists(array $book, string $fileName): bool
    {
        foreach (DigitalAttachments::fromBook($book) as $attachment) {
            if (self::fileNameOf($attachment['url']) === $fileName) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, mixed>|null */
    private static function loadBook(int $bookId, \mysqli $db): ?array
    {
        $stmt = $db->prepare('SELECT id, file_url, audio_url, digital_attachments FROM libri WHERE id = ? AND deleted_at IS NULL');
        if ($stmt === false) {
            throw new \RuntimeException('record read prepare failed: ' . $db->error);
        }
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @return list<int>|null null when the index table does not exist (plugin never activated) */
    private static function indexedBookIds(string $fileName, \mysqli $db): ?array
    {
        try {
            $stmt = $db->prepare('SELECT libro_id FROM digital_library_files WHERE file_name = ?');
        } catch (\mysqli_sql_exception $e) {
            return null;
        }
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('s', $fileName);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($result !== false && ($row = $result->fetch_assoc())) {
            $ids[] = (int) $row['libro_id'];
        }
        $stmt->close();
        return $ids;
    }

    /** Some record changed since this file's entries were verified (same second included). */
    private static function indexOutdated(string $fileName, \mysqli $db): bool
    {
        // CI-SOFT-DELETE-EXEMPT: a soft-delete bumps updated_at too, and must outdate the index like any other change.
        // verified_at is a Unix time (DigitalLibraryPlugin::ensureIndexTable): UNIX_TIMESTAMP() of a
        // TIMESTAMP column is the same instant whatever the connection's time_zone, which
        // RememberMeService switches to UTC in the middle of some requests.
        $stmt = $db->prepare('SELECT COUNT(*) FROM digital_library_files
            WHERE file_name = ? AND verified_at <= (SELECT UNIX_TIMESTAMP(MAX(updated_at)) FROM libri)');
        if ($stmt === false) {
            return true;
        }
        $stmt->bind_param('s', $fileName);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_row() : null;
        $stmt->close();
        return (int) ($row[0] ?? 1) > 0;
    }

    private static function forgetIndex(string $fileName, \mysqli $db): void
    {
        $stmt = $db->prepare('DELETE FROM digital_library_files WHERE file_name = ?');
        if ($stmt !== false) {
            $stmt->bind_param('s', $fileName);
            $stmt->execute();
            $stmt->close();
        }
    }

    /** @param list<int> $bookIds */
    private static function remember(string $fileName, array $bookIds, \mysqli $db): void
    {
        foreach ($bookIds as $bookId) {
            $stmt = $db->prepare('INSERT IGNORE INTO digital_library_files (file_name, libro_id, verified_at) VALUES (?, ?, UNIX_TIMESTAMP())');
            if ($stmt === false) {
                return;
            }
            $stmt->bind_param('si', $fileName, $bookId);
            $stmt->execute();
            $stmt->close();
        }
    }

    private static function hasActiveLoan(int $bookId, int $userId, \mysqli $db): bool
    {
        if ($bookId <= 0) {
            return false;
        }
        $stmt = $db->prepare("SELECT 1 FROM prestiti WHERE libro_id = ? AND utente_id = ? AND attivo = 1
            AND stato IN ('in_corso', 'in_ritardo') LIMIT 1");
        if ($stmt === false) {
            throw new \RuntimeException('loan check prepare failed: ' . $db->error);
        }
        $stmt->bind_param('ii', $bookId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $found = $result !== false && $result->num_rows > 0;
        $stmt->close();
        return $found;
    }

    // ── Signed links (the app's player and PDF reader fetch without a session) ──

    /** $url with a signature that lets user $userId open it until it expires. */
    public static function signedUrl(string $url, int $userId, \mysqli $db, ?int $now = null): string
    {
        $name = self::fileNameOf($url);
        if ($name === null || $userId <= 0) {
            return $url;
        }
        // Rounded up to the hour: the same link for a whole hour, so the app's media cache
        // and the book detail's ETag stay stable (a link lives between 24 and 25 hours).
        $expires = intdiv(($now ?? time()) + self::SIGNED_TTL + 3599, 3600) * 3600;
        $signature = self::signature($name, $userId, $expires, $db);
        $query = http_build_query(['pk_exp' => $expires, 'pk_uid' => $userId, 'pk_sig' => $signature]);
        return $url . (str_contains($url, '?') ? '&' : '?') . $query;
    }

    /**
     * The user a signed request speaks for, or null when it carries no valid,
     * unexpired signature for $fileName.
     *
     * @param array<string, mixed> $query
     */
    public static function verifySignature(string $fileName, array $query, \mysqli $db, ?int $now = null): ?int
    {
        $expires = is_scalar($query['pk_exp'] ?? null) ? (string) $query['pk_exp'] : '';
        $userId = is_scalar($query['pk_uid'] ?? null) ? (string) $query['pk_uid'] : '';
        $signature = is_scalar($query['pk_sig'] ?? null) ? (string) $query['pk_sig'] : '';
        if (!ctype_digit($expires) || !ctype_digit($userId) || $signature === '' || (int) $userId <= 0) {
            return null;
        }
        if ((int) $expires < ($now ?? time())) {
            return null;
        }
        $expected = self::signature($fileName, (int) $userId, (int) $expires, $db);
        return hash_equals($expected, $signature) ? (int) $userId : null;
    }

    /** A signed request names its user: read the role now, so a suspended reader is refused. */
    public static function signedViewer(int $userId, \mysqli $db): ?array
    {
        $stmt = $db->prepare("SELECT id, tipo_utente FROM utenti WHERE id = ? AND stato = 'attivo' LIMIT 1");
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    private static function signature(string $fileName, int $userId, int $expires, \mysqli $db): string
    {
        return hash_hmac('sha256', $fileName . '|' . $userId . '|' . $expires, self::signingSecret($db));
    }

    private static function signingSecret(\mysqli $db): string
    {
        $repo = new \App\Models\SettingsRepository($db);
        $secret = (string) ($repo->get(self::CATEGORY, self::SIGNING_KEY, '') ?? '');
        if ($secret !== '') {
            return $secret;
        }
        $candidate = bin2hex(random_bytes(32));
        $stmt = $db->prepare('INSERT IGNORE INTO system_settings (category, setting_key, setting_value) VALUES (?, ?, ?)');
        if ($stmt !== false) {
            $category = self::CATEGORY;
            $key = self::SIGNING_KEY;
            $stmt->bind_param('sss', $category, $key, $candidate);
            $stmt->execute();
            $stmt->close();
        }
        $stored = (string) ($repo->get(self::CATEGORY, self::SIGNING_KEY, '') ?? '');
        return $stored !== '' ? $stored : $candidate;
    }
}
