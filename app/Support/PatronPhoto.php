<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;

/**
 * The patron photo: one JPEG per reader, kept OUTSIDE the web root.
 *
 * The photo identifies a person, so it is personal data and is never served by
 * the web server: the file lives in storage/patron_photos/ (denied by
 * storage/.htaccess) and reaches a browser only through the admin route
 * /admin/users/{id}/photo, behind the same permission that edits the reader.
 * The card PDF reads the file from disk.
 *
 * Whatever arrives (a webcam frame from the canvas or a picked file) is decoded
 * and re-encoded with GD: the stored file is always a plain JPEG at most
 * MAX_EDGE pixels on its longer side, with no EXIF block — so neither the
 * camera's metadata nor a polyglot payload survives the upload.
 *
 * `utenti.foto_profilo_url` holds the storage-relative name
 * (`patron_photos/<id>-<random>.jpg`); a random part in the name means a new
 * photo never reuses a cached URL of the old one.
 */
final class PatronPhoto
{
    public const DIR = 'patron_photos';
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_EDGE = 600;
    /** Largest decoded image accepted (16 megapixels, about 64 MB in GD). */
    public const MAX_PIXELS = 16000000;
    private const NAME_PATTERN = '/^patron_photos\/(\d+)-[a-f0-9]{16}\.jpg$/D';

    /** Absolute directory holding the photos (created on first use). */
    public static function directory(): string
    {
        return dirname(__DIR__, 2) . '/storage/' . self::DIR;
    }

    /**
     * Absolute path of a stored photo, or null when the stored value is not
     * one this class wrote (or the file is gone). The pattern check is what
     * keeps a tampered column from pointing the reader at another file.
     */
    public static function absolutePath(?string $stored): ?string
    {
        $stored = (string) $stored;
        if ($stored === '' || preg_match(self::NAME_PATTERN, $stored) !== 1) {
            return null;
        }
        $path = dirname(__DIR__, 2) . '/storage/' . $stored;
        return is_file($path) ? $path : null;
    }

    /** Stored value for a user, or null when none. */
    public static function storedFor(mysqli $db, int $userId): ?string
    {
        $stmt = $db->prepare('SELECT foto_profilo_url FROM utenti WHERE id = ? LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $value = is_array($row) ? ($row['foto_profilo_url'] ?? null) : null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Decode $sourcePath, re-encode it as a bounded JPEG and make it the
     * user's photo. Returns null on success or an error code:
     * 'too_large', 'invalid_image', 'storage', 'db'.
     */
    public static function store(mysqli $db, int $userId, string $sourcePath): ?string
    {
        if ($userId <= 0 || !is_file($sourcePath)) {
            return 'invalid_image';
        }
        $size = filesize($sourcePath);
        if ($size === false || $size === 0) {
            return 'invalid_image';
        }
        if ($size > self::MAX_BYTES) {
            return 'too_large';
        }
        $jpeg = self::normalise($sourcePath);
        if ($jpeg === null) {
            return 'invalid_image';
        }

        $dir = self::directory();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            SecureLogger::error('[PatronPhoto] cannot create the photo directory');
            return 'storage';
        }
        $name = self::DIR . '/' . $userId . '-' . bin2hex(random_bytes(8)) . '.jpg';
        $target = dirname(__DIR__, 2) . '/storage/' . $name;
        if (@file_put_contents($target, $jpeg, LOCK_EX) === false) {
            SecureLogger::error('[PatronPhoto] cannot write the photo file', ['user_id' => $userId]);
            return 'storage';
        }
        @chmod($target, 0640);

        $previous = self::storedFor($db, $userId);
        try {
            $stmt = $db->prepare('UPDATE utenti SET foto_profilo_url = ? WHERE id = ?');
            if ($stmt === false) {
                throw new \RuntimeException($db->error);
            }
            $stmt->bind_param('si', $name, $userId);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            @unlink($target);
            SecureLogger::error('[PatronPhoto] cannot record the photo', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return 'db';
        }
        if ($previous !== null && $previous !== $name) {
            self::deleteFile($previous);
        }
        return null;
    }

    /** Remove the user's photo: the file and the reference. True when none is left. */
    public static function delete(mysqli $db, int $userId): bool
    {
        $previous = self::storedFor($db, $userId);
        try {
            $stmt = $db->prepare('UPDATE utenti SET foto_profilo_url = NULL WHERE id = ?');
            if ($stmt === false) {
                throw new \RuntimeException($db->error);
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            SecureLogger::error('[PatronPhoto] cannot clear the photo', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
        if ($previous !== null) {
            self::deleteFile($previous);
        }
        return true;
    }

    /** Delete the file behind a stored value (used when the account itself is deleted). */
    public static function deleteFile(?string $stored): void
    {
        $path = self::absolutePath($stored);
        if ($path !== null) {
            @unlink($path);
        }
    }

    /** Decode any GD-readable JPEG/PNG/WebP and re-encode it as a bounded JPEG. */
    private static function normalise(string $sourcePath): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $info = @getimagesize($sourcePath);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return null;
        }
        [$width, $height] = [(int) $info[0], (int) $info[1]];
        // The pixel count bounds what GD has to allocate (~4 bytes a pixel):
        // a tiny flat PNG can declare 8000 x 8000 and exhaust memory_limit.
        if ($width < 16 || $height < 16 || $width > 8000 || $height > 8000 || $width * $height > self::MAX_PIXELS) {
            return null;
        }
        $bytes = @file_get_contents($sourcePath);
        if ($bytes === false) {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }
        // Phone photos carry their rotation in EXIF Orientation, which the
        // re-encode drops: turn the pixels so the card shows them upright.
        // Read now, applied after the downscale, so GD never holds two
        // full-resolution copies at once.
        $angle = 0;
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($sourcePath);
            $angle = match (is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1) {
                3 => 180,
                6 => -90,
                8 => 90,
                default => 0,
            };
        }
        $scale = min(1.0, self::MAX_EDGE / max($width, $height));
        $newW = max(1, (int) round($width * $scale));
        $newH = max(1, (int) round($height * $scale));
        $dst = imagecreatetruecolor($newW, $newH);
        // A transparent PNG becomes white, not black.
        imagefill($dst, 0, 0, (int) imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
        unset($src); // free the full-size image before the rotation
        if ($angle !== 0) {
            // A quarter turn keeps the long edge within MAX_EDGE.
            $rotated = imagerotate($dst, $angle, 0);
            if ($rotated !== false) {
                $dst = $rotated;
            }
        }
        ob_start();
        imagejpeg($dst, null, 85);
        $out = (string) ob_get_clean();
        return $out !== '' ? $out : null;
    }
}
