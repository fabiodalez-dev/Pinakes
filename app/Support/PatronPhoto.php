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

        // Read the reference to replace before writing anything: a failure
        // here must not leave a personal photo on disk that nothing points to.
        try {
            $previous = self::storedFor($db, $userId);
        } catch (\Throwable $e) {
            SecureLogger::error('[PatronPhoto] cannot read the current photo', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return 'db';
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

        try {
            $stmt = $db->prepare('UPDATE utenti SET foto_profilo_url = ? WHERE id = ?');
            if ($stmt === false) {
                throw new \RuntimeException($db->error);
            }
            try {
                $stmt->bind_param('si', $name, $userId);
                $stmt->execute();
                // The name is new (random part), so a matched row always
                // changes: 0 means the account is gone and the file is orphan.
                if ($stmt->affected_rows !== 1) {
                    throw new \RuntimeException('No account to attach the photo to');
                }
            } finally {
                $stmt->close();
            }
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

    /**
     * Remove the user's photo: the file, then the reference. True when none is
     * left. When the file cannot be removed the reference stays, so the photo
     * is still known (and deletable) instead of lingering on disk unreferenced.
     */
    public static function delete(mysqli $db, int $userId): bool
    {
        try {
            $previous = self::storedFor($db, $userId);
        } catch (\Throwable $e) {
            SecureLogger::error('[PatronPhoto] cannot read the photo to delete', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
        if ($previous !== null && !self::deleteFile($previous)) {
            SecureLogger::error('[PatronPhoto] cannot delete the photo file', ['user_id' => $userId]);
            return false;
        }
        try {
            $stmt = $db->prepare('UPDATE utenti SET foto_profilo_url = NULL WHERE id = ?');
            if ($stmt === false) {
                throw new \RuntimeException($db->error);
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            // The file is already gone: a reference to a missing file shows
            // no photo (absolutePath() checks the file), and the next delete
            // clears it.
            SecureLogger::error('[PatronPhoto] cannot clear the photo', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
        return true;
    }

    /**
     * Delete the file behind a stored value (also used when the account
     * itself is deleted). True when no file is left: removed, or none there.
     */
    public static function deleteFile(?string $stored): bool
    {
        $path = self::absolutePath($stored);
        if ($path === null) {
            return true;
        }
        return @unlink($path) || !is_file($path);
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
        $transform = ['flip' => null, 'angle' => 0];
        if ($info[2] === IMAGETYPE_JPEG) {
            // Without the exif extension the tag is read from the APP1 block
            // directly, so the rotation never depends on an optional module.
            $orientation = null;
            if (function_exists('exif_read_data')) {
                $exif = @exif_read_data($sourcePath);
                $orientation = is_array($exif) && isset($exif['Orientation']) ? (int) $exif['Orientation'] : null;
            }
            $transform = self::orientationTransform($orientation ?? self::jpegOrientation($bytes));
        }
        $scale = min(1.0, self::MAX_EDGE / max($width, $height));
        $newW = max(1, (int) round($width * $scale));
        $newH = max(1, (int) round($height * $scale));
        $dst = imagecreatetruecolor($newW, $newH);
        // A transparent PNG becomes white, not black.
        imagefill($dst, 0, 0, (int) imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
        unset($src); // free the full-size image before the rotation
        if ($transform['flip'] !== null) {
            imageflip($dst, $transform['flip']);
        }
        if ($transform['angle'] !== 0) {
            // A quarter turn keeps the long edge within MAX_EDGE.
            $rotated = imagerotate($dst, $transform['angle'], 0);
            if ($rotated !== false) {
                $dst = $rotated;
            }
        }
        ob_start();
        imagejpeg($dst, null, 85);
        $out = (string) ob_get_clean();
        return $out !== '' ? $out : null;
    }

    /**
     * How to turn the pixels of an EXIF Orientation upright: an optional
     * mirror (IMG_FLIP_*), applied first, then a GD angle (counter-clockwise).
     * 1 or unknown: nothing. 2/4 mirror; 3/6/8 rotate; 5 (transpose) and 7
     * (transverse) mirror horizontally, then rotate.
     *
     * @return array{flip: ?int, angle: int}
     */
    public static function orientationTransform(?int $orientation): array
    {
        return match ($orientation) {
            2 => ['flip' => IMG_FLIP_HORIZONTAL, 'angle' => 0],
            3 => ['flip' => null, 'angle' => 180],
            4 => ['flip' => IMG_FLIP_VERTICAL, 'angle' => 0],
            5 => ['flip' => IMG_FLIP_HORIZONTAL, 'angle' => 90],
            6 => ['flip' => null, 'angle' => -90],
            7 => ['flip' => IMG_FLIP_HORIZONTAL, 'angle' => -90],
            8 => ['flip' => null, 'angle' => 90],
            default => ['flip' => null, 'angle' => 0],
        };
    }

    /**
     * The EXIF Orientation of a JPEG read from its APP1 segment, without the
     * exif extension; null when there is none (or the block is malformed).
     */
    public static function jpegOrientation(string $jpeg): ?int
    {
        $length = strlen($jpeg);
        if ($length < 4 || substr($jpeg, 0, 2) !== "\xFF\xD8") {
            return null;
        }
        $pos = 2;
        while ($pos + 4 <= $length && $jpeg[$pos] === "\xFF") {
            $marker = ord($jpeg[$pos + 1]);
            $size = (int) unpack('n', substr($jpeg, $pos + 2, 2))[1];
            if ($marker === 0xDA || $size < 2) {
                return null; // image data starts: no APP1 before it
            }
            if ($marker === 0xE1 && substr($jpeg, $pos + 4, 6) === "Exif\x00\x00") {
                return self::tiffOrientation(substr($jpeg, $pos + 10, $size - 8));
            }
            $pos += 2 + $size;
        }
        return null;
    }

    /** Orientation tag (0x0112) of IFD0 in a TIFF block. */
    private static function tiffOrientation(string $tiff): ?int
    {
        $order = substr($tiff, 0, 2);
        if ($order !== 'II' && $order !== 'MM' || strlen($tiff) < 8) {
            return null;
        }
        $short = $order === 'II' ? 'v' : 'n';
        $long = $order === 'II' ? 'V' : 'N';
        $ifd = (int) unpack($long, substr($tiff, 4, 4))[1];
        if ($ifd < 8 || $ifd + 2 > strlen($tiff)) {
            return null;
        }
        $entries = (int) unpack($short, substr($tiff, $ifd, 2))[1];
        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($entry + 12 > strlen($tiff)) {
                return null;
            }
            if ((int) unpack($short, substr($tiff, $entry, 2))[1] === 0x0112) {
                $value = (int) unpack($short, substr($tiff, $entry + 8, 2))[1];
                return $value >= 1 && $value <= 8 ? $value : null;
            }
        }
        return null;
    }
}
