<?php
declare(strict_types=1);

namespace App\Support\Marc;

/**
 * The cover a MARC record points at (856 $u), saved as a local file the way
 * the book form saves one: through CoverController::downloadFromUrl(), which
 * enforces HTTPS, public DNS on every redirect hop, size and image type.
 *
 * A URL into this installation's own covers folder (a record exported by
 * this catalogue, or served by its own SRU server) is copied from disk: the
 * SSRF guard would rightly refuse to fetch it over the network when the site
 * runs on a private address, and the file is already here.
 */
final class MarcCoverFetcher
{
    private const COVERS_PATH = '/uploads/copertine/';

    /**
     * @return string the stored cover path (/uploads/copertine/...)
     * @throws \RuntimeException when the image cannot be fetched or is not one
     */
    public function fetch(string $url): string
    {
        $local = $this->localCoverFile($url);
        if ($local !== null) {
            return $this->copyLocal($local);
        }
        $saved = (new \App\Controllers\CoverController())->downloadFromUrl($url);
        return (string) $saved['file_url'];
    }

    /** The file in this installation's covers folder a URL names, or null. */
    private function localCoverFile(string $url): ?string
    {
        $parts = parse_url($url);
        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || !isset($parts['host'])) {
            return null;
        }
        $base = parse_url(function_exists('absoluteUrl') ? \absoluteUrl('/') : '');
        if (!is_array($base) || !isset($base['host'])
            || strcasecmp((string) $base['host'], (string) $parts['host']) !== 0
            || self::effectivePort($base) !== self::effectivePort($parts)
        ) {
            return null;
        }
        $basePath = rtrim((string) ($base['path'] ?? ''), '/');
        if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
            $path = substr($path, strlen($basePath));
        }
        if (!str_starts_with($path, self::COVERS_PATH)) {
            return null;
        }
        $name = basename(rawurldecode($path));
        if (preg_match('/^[A-Za-z0-9._-]+\.(jpe?g|png|webp|gif)$/i', $name) !== 1) {
            return null;
        }
        $file = self::coversDir() . '/' . $name;
        return is_file($file) ? $file : null;
    }

    /**
     * The port a parse_url() result points at: the explicit one, else the
     * scheme's default, so https://host and https://host:443 are the same.
     *
     * @param array<string, mixed> $parts
     */
    public static function effectivePort(array $parts): int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }
        return match (strtolower((string) ($parts['scheme'] ?? ''))) {
            'https' => 443,
            'http' => 80,
            default => 0,
        };
    }

    private function copyLocal(string $file): string
    {
        $info = @getimagesize($file);
        if ($info === false) {
            throw new \RuntimeException(__('File non valido o corrotto.'));
        }
        // The stored name follows the bytes, not the source name, so a server
        // that types files by extension serves the right Content-Type.
        $extension = match ($info[2]) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_GIF => 'gif',
            default => throw new \RuntimeException(__('File non valido o corrotto.')),
        };
        $name = uniqid('copertina_', true) . '.' . $extension;
        if (!@copy($file, self::coversDir() . '/' . $name)) {
            throw new \RuntimeException(__('Errore nel salvataggio dell\'immagine.'));
        }
        @chmod(self::coversDir() . '/' . $name, 0644);
        return self::COVERS_PATH . $name;
    }

    private static function coversDir(): string
    {
        return dirname(__DIR__, 3) . '/public/uploads/copertine';
    }
}
