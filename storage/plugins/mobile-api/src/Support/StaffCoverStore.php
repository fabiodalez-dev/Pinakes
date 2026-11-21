<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

/**
 * Cover replacement for POST /staff/books/{id}/cover.
 *
 * LibriController's upload path is private and bound to `$_FILES['copertina']`,
 * so the safe path is re-implemented here with the same limits as the web
 * form: at most 2 MB, JPEG/PNG only, at most 20 megapixels, and the image is
 * always RE-ENCODED with GD so no polyglot payload smuggled behind valid image
 * headers ever reaches the public uploads directory. Remote URLs go through
 * CoverController::downloadFromUrl(), which owns the HTTPS-only, public-IP
 * (SSRF) and size checks.
 *
 * Files land in public/uploads/copertine (the same directory core uses) and
 * the previous local file is removed only when it is under that directory, is
 * not the shared placeholder, and no other title still points at it.
 */
final class StaffCoverStore
{
    public const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;
    private const MAX_PIXELS = 20_000_000;
    private const MAX_EDGE = 2000;
    private const URL_PREFIX = '/uploads/copertine/';

    public function __construct(private \mysqli $db, private ?string $coversDir = null)
    {
    }

    public function coversDir(): string
    {
        return $this->coversDir ?? dirname(__DIR__, 5) . '/public/uploads/copertine';
    }

    /**
     * Store raw image bytes (an upload) as the book's cover.
     *
     * @return string the new relative cover path (/uploads/copertine/…)
     * @throws \InvalidArgumentException when the bytes are not an acceptable image
     */
    public function storeBytes(int $bookId, string $bytes): string
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            throw new \InvalidArgumentException(__('Immagine non valida: JPEG o PNG, massimo 2 MB.'));
        }

        return $this->encodeAndAttach($bookId, $bytes);
    }

    private function encodeAndAttach(int $bookId, string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw new \InvalidArgumentException(__('Immagine non valida: JPEG o PNG, massimo 2 MB.'));
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width <= 0 || $height <= 0 || $width * $height > self::MAX_PIXELS) {
            throw new \InvalidArgumentException(__('Immagine troppo grande da processare.'));
        }
        $extension = match ($info['mime']) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            default => null,
        };
        if ($extension === null) {
            throw new \InvalidArgumentException(__('Tipo di file non supportato. Solo JPEG e PNG sono consentiti.'));
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new \InvalidArgumentException(__('Immagine non valida: JPEG o PNG, massimo 2 MB.'));
        }
        if ($width > self::MAX_EDGE || $height > self::MAX_EDGE) {
            $ratio = min(self::MAX_EDGE / $width, self::MAX_EDGE / $height);
            $resized = imagecreatetruecolor(max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)));
            if ($resized === false) {
                imagedestroy($image);
                throw new \RuntimeException('cover resize failed');
            }
            if ($extension === 'png') {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
            }
            imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $width, $height);
            imagedestroy($image);
            $image = $resized;
        } elseif ($extension === 'png') {
            imagesavealpha($image, true);
        }

        $dir = $this->ensureDir();
        $filename = sprintf('libro_%d_%s.%s', $bookId, bin2hex(random_bytes(8)), $extension);
        $path = $dir . '/' . $filename;
        $saved = $extension === 'png' ? imagepng($image, $path, 9) : imagejpeg($image, $path, 85);
        imagedestroy($image);
        if (!$saved) {
            @unlink($path);
            throw new \RuntimeException('cover write failed');
        }
        @chmod($path, 0644);

        return $this->attach($bookId, self::URL_PREFIX . $filename);
    }

    /**
     * Use an image that already lives in this instance's cover directory
     * (e.g. the URL a scraper preview returned). The file is re-encoded into
     * a new one so the book never shares — and later deletes — another
     * title's file or the placeholder.
     */
    public function storeLocal(int $bookId, string $relativePath): string
    {
        $file = $this->resolveLocal($relativePath);
        if ($file === null) {
            throw new \InvalidArgumentException(__('URL non valido.'));
        }
        $bytes = file_get_contents($file);
        // Local files were already accepted once by core (5 MB cap there).
        if ($bytes === false || $bytes === '' || strlen($bytes) > 5 * 1024 * 1024) {
            throw new \InvalidArgumentException(__('URL non valido.'));
        }

        return $this->encodeAndAttach($bookId, $bytes);
    }

    /**
     * Download a remote HTTPS cover through core's SSRF-guarded fetcher.
     *
     * @throws \RuntimeException when the download fails (mapped to 502)
     */
    public function storeRemote(int $bookId, string $url): string
    {
        $saved = (new \App\Controllers\CoverController())->downloadFromUrl($url);

        return $this->attach($bookId, (string) $saved['file_url']);
    }

    /**
     * Map an absolute URL of THIS instance to its relative cover path, or
     * return the input unchanged.
     */
    public static function localPathFromUrl(string $url): string
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            return $url;
        }
        $base = rtrim(absoluteUrl('/'), '/');
        if ($base !== '' && str_starts_with($url, $base . self::URL_PREFIX)) {
            return substr($url, strlen($base));
        }

        return $url;
    }

    private function resolveLocal(string $relativePath): ?string
    {
        if (!str_starts_with($relativePath, self::URL_PREFIX) || str_contains($relativePath, '..')) {
            return null;
        }
        $base = realpath($this->coversDir());
        $file = realpath($this->coversDir() . '/' . substr($relativePath, strlen(self::URL_PREFIX)));
        if ($base === false || $file === false || !is_file($file)
            || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $file;
    }

    private function ensureDir(): string
    {
        $dir = $this->coversDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('cover directory unavailable');
        }
        $real = realpath($dir);
        if ($real === false) {
            throw new \RuntimeException('cover directory unavailable');
        }

        return $real;
    }

    /**
     * Point the book at the new file and retire the previous local one.
     */
    private function attach(int $bookId, string $newPath): string
    {
        $previous = '';
        $stmt = $this->db->prepare('SELECT copertina_url FROM libri WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result !== false ? $result->fetch_assoc() : null;
            $stmt->close();
            $previous = is_array($row) ? (string) ($row['copertina_url'] ?? '') : '';
        }

        $update = $this->db->prepare('UPDATE libri SET copertina_url = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL');
        if ($update === false) {
            $this->discard($newPath);
            throw new \RuntimeException('cover update prepare failed: ' . $this->db->error);
        }
        $update->bind_param('si', $newPath, $bookId);
        $ok = $update->execute();
        $affected = $update->affected_rows;
        $update->close();
        if (!$ok || $affected < 1) {
            $this->discard($newPath);
            throw new \RuntimeException('cover update failed');
        }

        if ($previous !== '' && $previous !== $newPath) {
            $this->retire($previous, $bookId);
        }

        return $newPath;
    }

    private function retire(string $relativePath, int $bookId): void
    {
        if (basename($relativePath) === 'placeholder.jpg') {
            return;
        }
        $file = $this->resolveLocal($relativePath);
        if ($file === null || basename($file) === 'placeholder.jpg') {
            return;
        }
        // The shared-reference check must look at the file that is about to
        // be deleted, not at the stored spelling: '/uploads/copertine/./x.jpg'
        // resolves to the same file as another title's '/uploads/copertine/x.jpg'.
        $canonical = self::canonicalCoverPath($file, (string) realpath($this->coversDir()));
        if ($canonical === null) {
            return;
        }
        // Any stored value ending in the same file name counts as a use
        // (absolute URLs, odd spellings): keeping a file is the safe direction.
        $suffix = '%/' . addcslashes(basename($file), '\\%_');
        // Another title (live or soft-deleted, which may be restored) still uses it.
        // CI-SOFT-DELETE-EXEMPT: a soft-deleted title can be restored, so its cover must survive too.
        $stmt = $this->db->prepare('SELECT 1 FROM libri WHERE (copertina_url = ? OR copertina_url LIKE ?) AND id <> ? LIMIT 1');
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('ssi', $canonical, $suffix, $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $shared = $result !== false && $result->fetch_row() !== null;
        $stmt->close();
        if (!$shared) {
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- path confined to the covers directory by resolveLocal()'s realpath containment check
            @unlink($file);
        }
    }

    /**
     * The canonical stored path (/uploads/copertine/<relative>) of a file
     * resolved under $base, or null when it is not under it.
     */
    public static function canonicalCoverPath(string $resolvedFile, string $base): ?string
    {
        if ($base === '' || !str_starts_with($resolvedFile, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($resolvedFile, strlen($base) + 1));

        return self::URL_PREFIX . $relative;
    }

    private function discard(string $relativePath): void
    {
        $file = $this->resolveLocal($relativePath);
        if ($file !== null) {
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- path confined to the covers directory by resolveLocal()
            @unlink($file);
        }
    }
}
