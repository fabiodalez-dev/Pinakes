<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Where a journal or newspaper article belongs, told from the book form.
 *
 * `tipo_media` lists Book, Record, Audiobook, DVD and Other: a cataloguer with
 * a single article in hand looks there, finds nothing that fits and stops
 * (issue #412). Standalone articles live in the Emeroteca plugin, which is
 * optional and inactive on a fresh install — so the hint cannot come from a
 * hook: hooks only fire when the plugin is already active, which is exactly
 * the case that needs no hint. The core therefore asks the plugins table
 * directly, the way the admin layout and the CSV import page already do.
 *
 * Three states, because pointing at something that is not there is worse than
 * saying nothing: ACTIVE links to the article form, INACTIVE to the plugins
 * page, and ABSENT renders nothing at all — the plugin was uninstalled (its
 * row and directory are both removed) or was never shipped.
 */
final class PeriodicalArticlesHint
{
    /** The plugin is active: the article form is reachable. */
    public const ACTIVE = 'active';
    /** Installed but switched off: the operator can turn it on. */
    public const INACTIVE = 'inactive';
    /** Not installed: say nothing rather than offer a dead end. */
    public const ABSENT = 'absent';

    private const PLUGIN = 'emeroteca';

    private function __construct()
    {
    }

    /**
     * Resolve the state without ever breaking the page that asks.
     *
     * A missing plugins table, a failed statement or a deleted plugin
     * directory all resolve to ABSENT: the book form must render whatever
     * happens to the plugin registry.
     */
    public static function state(?\mysqli $db, ?string $pluginsDir = null): string
    {
        if (!$db instanceof \mysqli) {
            return self::ABSENT;
        }
        try {
            $stmt = $db->prepare('SELECT is_active FROM plugins WHERE name = ? LIMIT 1');
            if ($stmt === false) {
                return self::ABSENT;
            }
            $name = self::PLUGIN;
            $stmt->bind_param('s', $name);
            if (!$stmt->execute()) {
                $stmt->close();
                return self::ABSENT;
            }
            $result = $stmt->get_result();
            $row = $result instanceof \mysqli_result ? $result->fetch_assoc() : null;
            $stmt->close();
            if ($row === null) {
                return self::ABSENT;
            }
            // Uninstalling deletes the row AND the directory, so a row without
            // files means a half-removed install: its admin routes are not
            // registered, and a link to them would 404.
            $dir = rtrim($pluginsDir ?? dirname(__DIR__, 2) . '/storage/plugins', '/') . '/' . self::PLUGIN;
            if (!is_dir($dir)) {
                return self::ABSENT;
            }
            return (int)($row['is_active'] ?? 0) === 1 ? self::ACTIVE : self::INACTIVE;
        } catch (\Throwable $e) {
            return self::ABSENT;
        }
    }
}
