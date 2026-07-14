<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\AuthorRepository;
use App\Models\SettingsRepository;
use mysqli;

/**
 * One-time backfill of the legacy free-text contributor columns
 * (libri.illustratore / traduttore / curatore) into first-class author entities
 * via libri_autori.ruolo (issue #237).
 *
 * The updater only runs .sql migrations, and this conversion needs row logic
 * (split multi-name values, find-or-create the author, insert the role row), so
 * it runs here as a guarded self-heal invoked from MaintenanceService::runAll()
 * — the same cron + admin-login trigger the mail-template self-heal uses.
 *
 * Idempotent on two levels: a system_settings marker makes the whole pass a no-op
 * after the first success, and every insert is INSERT IGNORE against the
 * (libro_id, autore_id, ruolo) primary key. Never throws — a failure just leaves
 * the marker unset so the next pass retries; the free-text columns are retained
 * either way.
 */
final class ContributorBackfill
{
    private const MARKER_CATEGORY = 'migrations';
    private const MARKER_KEY = 'contributors_backfilled';

    /** free-text column on `libri` => target libri_autori.ruolo value */
    private const ROLE_COLUMNS = [
        'illustratore' => 'illustratore',
        'traduttore'   => 'traduttore',
        'curatore'     => 'curatore',
    ];

    public static function run(mysqli $db): void
    {
        try {
            $settings = new SettingsRepository($db);
            if ($settings->get(self::MARKER_CATEGORY, self::MARKER_KEY, '0') === '1') {
                return; // already done
            }

            $authors = new AuthorRepository($db);
            foreach (self::ROLE_COLUMNS as $column => $ruolo) {
                if (!self::hasColumn($db, 'libri', $column)) {
                    continue; // install predates this free-text column — nothing to migrate
                }
                self::backfillColumn($db, $authors, $column, $ruolo);
            }

            $settings->set(self::MARKER_CATEGORY, self::MARKER_KEY, '1');
        } catch (\Throwable $e) {
            // Best-effort: leave the marker unset so a later pass retries.
            SecureLogger::warning('ContributorBackfill failed: ' . $e->getMessage());
        }
    }

    private static function backfillColumn(mysqli $db, AuthorRepository $authors, string $column, string $ruolo): void
    {
        $sql = "SELECT id, `{$column}` AS raw FROM libri
                WHERE `{$column}` IS NOT NULL AND TRIM(`{$column}`) <> '' AND deleted_at IS NULL";
        $res = $db->query($sql);
        if (!($res instanceof \mysqli_result)) {
            return;
        }

        $insert = $db->prepare(
            "INSERT IGNORE INTO libri_autori (libro_id, autore_id, ruolo) VALUES (?, ?, ?)"
        );
        if ($insert === false) {
            return;
        }

        while ($row = $res->fetch_assoc()) {
            $bookId = (int) $row['id'];
            foreach (self::splitNames((string) $row['raw']) as $name) {
                $authorId = $authors->findByName($name);
                if ($authorId === null) {
                    $authorId = $authors->create(['nome' => $name]);
                }
                if ($authorId > 0) {
                    $insert->bind_param('iis', $bookId, $authorId, $ruolo);
                    $insert->execute();
                }
            }
        }
        $insert->close();
    }

    /**
     * Split a free-text contributor value into individual names. Handles the
     * common separators seen in imported/scraped data: comma, semicolon,
     * ampersand, and the Italian/English " e " / " and " conjunctions.
     *
     * @return list<string>
     */
    public static function splitNames(string $raw): array
    {
        $parts = preg_split('/\s*(?:,|;|&|\se\s|\sand\s)\s*/ui', $raw) ?: [];
        $names = [];
        foreach ($parts as $part) {
            $name = trim(HtmlHelper::decode($part));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }

    private static function hasColumn(mysqli $db, string $table, string $column): bool
    {
        $stmt = $db->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $exists;
    }
}
