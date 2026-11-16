<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Permissions;
use App\Support\SessionRoleRevalidator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Slim\Psr7\Stream;

/**
 * Serves PRIVATE uploaded files (digital-library content, archive documents,
 * generic storage) through PHP so the global middleware stack — notably
 * PrivateModeMiddleware — governs access to them.
 *
 * Background: public/.htaccess serves most of `/uploads/` directly from the
 * web server (so it never reaches PHP). That blanket rule also exposed the
 * private subtrees below, letting anyone with the URL fetch ebooks/audio and
 * archive documents even while "private mode" was on (issue #160). Those
 * prefixes are now routed here instead and pass through PrivateModeMiddleware
 * first; in private mode a logged-out request is redirected/401'd before this
 * controller ever runs.
 *
 * Security:
 *   - the requested path is realpath-resolved and MUST stay inside one of the
 *     allowed private roots (defence against `..` traversal and symlinks);
 *   - dotfiles and empty segments are rejected.
 *
 * Streaming honours single HTTP Range requests so audio/video seeking keeps
 * working, and HEAD, ETag/Last-Modified revalidation and If-Range for players.
 */
class ProtectedUploadController
{
    /**
     * Private upload roots served without a per-file check, relative to public/uploads/.
     * Digital Library files are NOT here: they are reached only through the digital/
     * branch, which asks DigitalAccess (issue #480).
     */
    private const ALLOWED_ROOTS = ['archives/documents', 'storage'];

    /** Read chunk size for bounded (closed-range) responses. */
    private const CHUNK = 262144; // 256 KiB

    public function __construct(private readonly ?\mysqli $db = null)
    {
    }

    /**
     * Serve a private upload, honouring single-byte-range requests.
     *
     * Digital Library files (`/uploads/digital/<name>`) live in storage/digital/, outside
     * the web root, with public/uploads/digital/ as the fallback for files not moved yet.
     * Each request asks DigitalAccess who may open the file: an anonymous reader of a
     * signed-in-only file goes to the login page, anyone else refused gets a 403. The app
     * fetches with a signed link (pk_exp/pk_uid/pk_sig) instead of a session.
     *
     * The high cyclomatic/NPath count flagged by PHPMD is the inherent
     * byte-range state machine (full / open-ended / closed / suffix /
     * unsatisfiable) layered on the containment checks; splitting it would
     * scatter that logic without making it clearer, so the complexity is
     * accepted intentionally. (No @SuppressWarnings tag: PHPStan — the linter
     * actually wired into CI — rejects the dotted PHPMD identifier in phpDoc.)
     */
    public function serve(Request $request, Response $response, array $args): Response
    {
        $rel = (string) ($args['path'] ?? '');

        // Reject obviously hostile inputs early (traversal, NUL, dotfiles, empty or
        // dot segments): one path, one spelling, so "./digital/x" cannot take the
        // branch that skips the Digital Library check.
        if ($rel === '' || str_contains($rel, "\0") || str_contains($rel, '..')) {
            return new SlimResponse(404);
        }
        foreach (explode('/', $rel) as $segment) {
            if ($segment === '' || str_starts_with($segment, '.')) {
                return new SlimResponse(404);
            }
        }

        $digitalName = null;
        if (str_starts_with($rel, 'digital/')) {
            $digitalName = substr($rel, strlen('digital/'));
            // The same rule DigitalAccess matches records with: names from older versions
            // (spaces, accented letters, a subfolder of the old folder) keep working.
            if (!\App\Support\DigitalAccess::isValidName($digitalName)) {
                return new SlimResponse(404);
            }
            $candidate = $this->digitalFile($digitalName);
        } else {
            $candidate = $this->privateUpload($rel);
        }
        if ($candidate === null) {
            return new SlimResponse(404);
        }
        if (str_starts_with($rel, 'archives/documents/') && !$this->archiveDocumentVisible('/uploads/' . $rel)) {
            return new SlimResponse(404);
        }

        if ($digitalName !== null) {
            $refused = $this->authorize($request, $digitalName);
            if ($refused !== null) {
                return $refused;
            }
        }
        // The decision is made: release the session lock before streaming, or every
        // other page the reader opens waits for the whole audiobook to finish.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $size = filesize($candidate);
        $mtime = filemtime($candidate);
        if ($size === false) {
            return new SlimResponse(404);
        }
        $etag = '"' . dechex($size) . '-' . dechex((int) $mtime) . '"';
        $lastModified = gmdate('D, d M Y H:i:s', (int) $mtime) . ' GMT';

        $download = ($request->getQueryParams()['download'] ?? '') === '1';
        // A type outside the known list (an .html or .svg put in the folder by hand) is
        // never rendered on this origin: it leaves as an opaque download.
        $type = $this->contentType($candidate);
        $download = $download || $type === null;
        $headers = [
            'Content-Type' => $type ?? 'application/octet-stream',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'Vary' => 'Cookie',
            'ETag' => $etag,
            'Last-Modified' => $lastModified,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => ($download ? 'attachment' : 'inline') . "; filename*=UTF-8''" . rawurlencode(basename($candidate)),
        ];

        $ifNoneMatch = trim($request->getHeaderLine('If-None-Match'));
        if ($ifNoneMatch !== '' ? in_array($etag, array_map('trim', explode(',', $ifNoneMatch)), true) || $ifNoneMatch === '*'
            : $request->getHeaderLine('If-Modified-Since') === $lastModified) {
            $res = new SlimResponse(304);
            foreach (['ETag', 'Last-Modified', 'Cache-Control', 'Vary'] as $name) {
                $res = $res->withHeader($name, $headers[$name]);
            }
            return $res;
        }

        $start = 0;
        $end = $size - 1;
        $status = 200;

        // Single byte range only. A multi-range header (e.g. "bytes=0-9,20-29")
        // does not match this pattern and intentionally falls through to a full
        // 200 response — RFC 7233 permits ignoring a Range we don't satisfy.
        // An If-Range naming another version of the file also gets the full file.
        $range = $request->getHeaderLine('Range');
        $ifRange = trim($request->getHeaderLine('If-Range'));
        $rangeValid = $ifRange === '' || $ifRange === $etag || $ifRange === $lastModified;
        // "bytes=-" and a range whose last byte precedes its first ("bytes=5-2") are not
        // valid ranges at all (RFC 9110 14.1.1): ignored, the whole file is sent.
        if ($range !== '' && $rangeValid && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)
            && ($m[1] !== '' || $m[2] !== '') && ($m[1] === '' || $m[2] === '' || (int) $m[2] >= (int) $m[1])) {
            $reqStart = $m[1] === '' ? null : (int) $m[1];
            $reqEnd = $m[2] === '' ? null : (int) $m[2];

            if ($reqStart === null && $reqEnd !== null) {
                // Suffix range: the last N bytes.
                $start = $reqEnd >= $size ? 0 : $size - $reqEnd;
            } else {
                $start = $reqStart ?? 0;
                if ($reqEnd !== null) {
                    $end = min($reqEnd, $size - 1);
                }
            }

            if ($start > $end || $start >= $size || $start < 0) {
                return (new SlimResponse(416))->withHeader('Content-Range', "bytes */{$size}");
            }

            $status = 206;
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        $length = $end - $start + 1;
        $headers['Content-Length'] = (string) $length;

        // The bytes must leave as they are on disk: compressed, Content-Length and
        // Content-Range would no longer match what the client receives.
        if (function_exists('ini_set')) {
            @ini_set('zlib.output_compression', '0');
        }

        $sendfile = $digitalName !== null && $status === 200 ? $this->sendfileHeader($candidate, $digitalName) : null;
        if ($request->getMethod() === 'HEAD' || $sendfile !== null) {
            $res = new SlimResponse($status);
            if ($sendfile !== null) {
                $res = $res->withHeader($sendfile[0], $sendfile[1]);
            }
        } else {
            $handle = fopen($candidate, 'rb');
            if ($handle === false) {
                return new SlimResponse(404);
            }
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
            $res = new SlimResponse($status, null, $this->buildBody($handle, $start, $end, $size));
        }
        foreach ($headers as $name => $value) {
            $res = $res->withHeader($name, $value);
        }
        return $res;
    }

    /** A Digital Library file: storage/digital/ first, then the folder it lived in before. */
    private function digitalFile(string $name): ?string
    {
        foreach ([\App\Support\DigitalStorage::targetDir(), \App\Support\DigitalStorage::legacyDir()] as $dir) {
            $base = realpath($dir);
            if ($base === false) {
                continue;
            }
            $candidate = realpath($base . '/' . $name);
            if ($candidate !== false && is_file($candidate) && str_starts_with($candidate, $base . DIRECTORY_SEPARATOR)) {
                return $candidate;
            }
        }
        return null;
    }

    /** Another private upload (archive documents, generic storage) under public/uploads/. */
    private function privateUpload(string $rel): ?string
    {
        $uploadsRoot = realpath(dirname(__DIR__, 2) . '/public/uploads');
        if ($uploadsRoot === false) {
            return null;
        }
        $candidate = realpath($uploadsRoot . '/' . $rel);
        if ($candidate === false || !is_file($candidate)) {
            return null;
        }
        // Wherever the spelling led, a file that resolves into a Digital Library folder
        // (a symlink, a case-insensitive disk) is served only by the checked branch.
        foreach ([\App\Support\DigitalStorage::targetDir(), \App\Support\DigitalStorage::legacyDir()] as $digitalDir) {
            $digitalBase = realpath($digitalDir);
            if ($digitalBase !== false && str_starts_with(strtolower($candidate), strtolower($digitalBase . DIRECTORY_SEPARATOR))) {
                return null;
            }
        }
        // Containment: the resolved file must live under an allowed private root.
        foreach (self::ALLOWED_ROOTS as $root) {
            $base = realpath($uploadsRoot . '/' . $root);
            if ($base !== false && str_starts_with($candidate, $base . DIRECTORY_SEPARATOR)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Whether an archive document may be served by its raw path.
     *
     * The public surfaces link /archives/{id}/documents/{fileId}, which checks
     * that the unit is published and not deleted. This raw path used to skip
     * that, so unpublishing or deleting a unit did not revoke a link someone
     * already held. A file now opens here only while it belongs to a live,
     * published unit (archival_unit_files, or the legacy document_path column).
     * Operators who can open the archive back office keep access to its raw
     * links, including those for unpublished units.
     *
     * Installs without the archives tables keep the old behaviour; a failing
     * lookup refuses.
     */
    private function archiveDocumentVisible(string $storedPath): bool
    {
        if ($this->db === null) {
            return false;
        }
        try {
            $viewer = SessionRoleRevalidator::resolve($this->db);
            if ($viewer['status'] === SessionRoleRevalidator::OK
                && $viewer['stato'] === 'attivo'
                && Permissions::allowsPath('/admin/archives')) {
                return true;
            }
            $schema = $this->archiveSchema();
            if (!$schema['units']) {
                return true;
            }
            $live = 'u.deleted_at IS NULL' . ($schema['published'] ? ' AND u.published = 1' : '');
            $parts = [];
            $params = [];
            if ($schema['legacy']) {
                $parts[] = 'SELECT 1 FROM archival_units u WHERE u.document_path = ? AND ' . $live;
                $params[] = $storedPath;
            }
            if ($schema['files']) {
                $parts[] = 'SELECT 1 FROM archival_unit_files f'
                    . ' JOIN archival_units u ON u.id = f.unit_id'
                    . ' WHERE f.file_path = ? AND ' . $live;
                $params[] = $storedPath;
            }
            if ($parts === []) {
                return false;
            }
            $stmt = $this->db->prepare(implode(' UNION ALL ', $parts) . ' LIMIT 1');
            if ($stmt === false) {
                return false;
            }
            $stmt->bind_param(str_repeat('s', count($params)), ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            $found = $res instanceof \mysqli_result && $res->fetch_row() !== null;
            $stmt->close();
            return $found;
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::warning('[ProtectedUpload] archive document lookup failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /** @return array{units: bool, legacy: bool, files: bool, published: bool} */
    private function archiveSchema(): array
    {
        $db = $this->db;
        if ($db === null) {
            return ['units' => false, 'legacy' => false, 'files' => false, 'published' => false];
        }
        $res = $db->query(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND ((TABLE_NAME = 'archival_units' AND COLUMN_NAME IN ('id', 'document_path', 'published'))
                  OR (TABLE_NAME = 'archival_unit_files' AND COLUMN_NAME = 'file_path'))"
        );
        $out = ['units' => false, 'legacy' => false, 'files' => false, 'published' => false];
        if (!$res instanceof \mysqli_result) {
            throw new \RuntimeException('information_schema lookup failed');
        }
        while ($row = $res->fetch_row()) {
            if ($row[0] === 'archival_units' && $row[1] === 'id') {
                $out['units'] = true;
            } elseif ($row[0] === 'archival_units' && $row[1] === 'document_path') {
                $out['legacy'] = true;
            } elseif ($row[0] === 'archival_units' && $row[1] === 'published') {
                $out['published'] = true;
            } elseif ($row[0] === 'archival_unit_files') {
                $out['files'] = true;
            }
        }
        $res->free();
        return $out;
    }

    /** Null when the viewer may open the file; otherwise the response that refuses it. */
    private function authorize(Request $request, string $name): ?Response
    {
        if ($this->db === null) {
            return new SlimResponse(403);
        }
        $query = $request->getQueryParams();
        $user = $_SESSION['user'] ?? null;
        $viewer = null;
        $deadLink = false;
        if (isset($query['pk_sig'])) {
            $userId = \App\Support\DigitalAccess::verifySignature($name, $query, $this->db);
            $viewer = $userId !== null ? \App\Support\DigitalAccess::signedViewer($userId, $this->db) : null;
            $deadLink = $viewer === null;
        }
        if ($viewer === null && is_array($user)) {
            $viewer = \App\Support\Permissions::viewer((int) ($user['id'] ?? 0), $this->db);
        }
        // An expired, revoked or forged link with nobody signed in opens nothing, public
        // files included: PrivateModeMiddleware lets any pk_sig request reach this route,
        // so treating a dead link as an anonymous visitor would serve public files past
        // private mode. A reader signed in on this browser is still judged as themselves.
        if ($deadLink && $viewer === null) {
            return (new SlimResponse(403))->withHeader('Cache-Control', 'no-store');
        }

        $access = \App\Support\DigitalAccess::authorizeFile($name, $viewer, $this->db);
        if ($access === \App\Support\DigitalAccess::GRANTED) {
            return null;
        }
        if ($access === \App\Support\DigitalAccess::LOGIN_REQUIRED && $viewer === null) {
            if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
                return new SlimResponse(401);
            }
            $login = \App\Support\RouteTranslator::route('login') . '?return_url=' . rawurlencode(url(\App\Support\DigitalAccess::urlPathOf($name)));
            return (new SlimResponse(302))->withHeader('Location', url($login))->withHeader('Cache-Control', 'no-store');
        }
        return (new SlimResponse(403))->withHeader('Cache-Control', 'no-store');
    }

    /**
     * DIGITAL_LIBRARY_SENDFILE (apache|nginx) hands the whole-file transfer to the web
     * server once PHP has said yes. Off by default: whether the server honours the header
     * cannot be detected from PHP, and an ignored header sends an empty file.
     *
     * Only for a file already in storage/digital/: that is the one folder the documented
     * server setup exposes (nginx `alias`, mod_xsendfile's XSendFilePath), so a file still
     * waiting in the old public folder streams through PHP instead of a 404. LiteSpeed is
     * not offered: X-LiteSpeed-Location takes a URI under the document root, never a path
     * outside it.
     *
     * @return array{0:string,1:string}|null
     */
    private function sendfileHeader(string $path, string $name): ?array
    {
        $mode = strtolower(trim((string) ($_ENV['DIGITAL_LIBRARY_SENDFILE'] ?? getenv('DIGITAL_LIBRARY_SENDFILE') ?: '')));
        if ($mode !== 'apache' && $mode !== 'nginx') {
            return null;
        }
        $storage = realpath(\App\Support\DigitalStorage::targetDir());
        if ($storage === false || !str_starts_with($path, $storage . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $mode === 'apache'
            ? ['X-Sendfile', $path]
            : ['X-Accel-Redirect', '/_pinakes_digital/' . implode('/', array_map('rawurlencode', explode('/', $name)))];
    }

    /**
     * Whole-file and open-ended ranges stream straight from the file handle.
     * A closed range is copied (bounded) into a php://temp stream so exactly
     * the requested bytes are emitted (php://temp spills to disk past 2 MiB).
     *
     * @param resource $handle
     */
    private function buildBody($handle, int $start, int $end, int $size): Stream
    {
        // Open-ended (or full file): the body is the handle from $start to EOF. Slim's
        // emitter rewinds the body before sending it, so the stream itself must start
        // at $start: an fseek() alone sent the file's first bytes as bytes $start-$end.
        if ($end === $size - 1) {
            return $start > 0 ? new \App\Support\OffsetFileStream($handle, $start) : new Stream($handle);
        }

        // Closed range: emit exactly [$start, $end].
        $temp = fopen('php://temp', 'w+b');
        if ($temp === false) {
            // Degrade gracefully to streaming from $start (Content-Length bounds it).
            return $start > 0 ? new \App\Support\OffsetFileStream($handle, $start) : new Stream($handle);
        }
        fseek($handle, $start);
        $remaining = $end - $start + 1;
        while ($remaining > 0 && !feof($handle)) {
            $buf = fread($handle, (int) min(self::CHUNK, $remaining));
            if ($buf === false || $buf === '') {
                break;
            }
            fwrite($temp, $buf);
            $remaining -= strlen($buf);
        }
        fclose($handle);
        rewind($temp);
        return new Stream($temp);
    }

    /** The type of a known extension; null for any other (sent as an opaque download). */
    private function contentType(string $path): ?string
    {
        $map = [
            'pdf' => 'application/pdf',
            'epub' => 'application/epub+zip',
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mobi' => 'application/x-mobipocket-ebook',
            'txt' => 'text/plain; charset=utf-8',
            'zip' => 'application/zip',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'tiff' => 'image/tiff',
            'tif' => 'image/tiff',
        ];
        return $map[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
    }
}
