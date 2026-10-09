<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Where the CMS page images live, and the repair of the ones stored in the
 * wrong place by every version up to 0.8.0.
 *
 * The image uploaded for a CMS page (Chi siamo, Privacy, …) is served at
 * /uploads/cms/<file>, i.e. from public/uploads/cms. Older versions wrote the
 * file to storage/uploads/cms, outside the web root, while still storing the
 * /uploads/cms/<file> URL: the image answered 404 everywhere, in the admin
 * preview and on the public page. ensurePublic() copies such a file to the
 * place its URL points to, the first time the page is edited or viewed.
 */
final class CmsImageStorage
{
    public const URL_PREFIX = '/uploads/cms/';

    public static function publicDir(): string
    {
        return dirname(__DIR__, 2) . '/public/uploads/cms';
    }

    private static function legacyDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/uploads/cms';
    }

    /**
     * Make sure the file behind a stored /uploads/cms/<file> URL is served:
     * when it exists only in the legacy storage folder, copy it into the
     * public one. Anything else (empty, external URL, other folders) is left
     * alone. Never throws: an image is never worth a broken page.
     */
    public static function ensurePublic(?string $url): void
    {
        if ($url === null || !str_starts_with($url, self::URL_PREFIX)) {
            return;
        }
        $name = basename($url);
        // Only the names the uploader generates: no traversal, no surprises.
        if (!preg_match('/^cms_[a-f0-9]{32}\.(?:jpe?g|png|gif|webp)$/D', $name)) {
            return;
        }
        $target = self::publicDir() . '/' . $name;
        $source = self::legacyDir() . '/' . $name;
        try {
            if (is_file($target) || !is_file($source)) {
                return;
            }
            if (!is_dir(self::publicDir()) && !@mkdir(self::publicDir(), 0755, true) && !is_dir(self::publicDir())) {
                return;
            }
            if (@copy($source, $target)) {
                @chmod($target, 0644);
            }
        } catch (\Throwable $e) {
            SecureLogger::warning('[CMS] could not move a legacy page image to public/uploads/cms: ' . $e->getMessage());
        }
    }
}
