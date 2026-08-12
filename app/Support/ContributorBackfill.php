<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\SettingsRepository;
use mysqli;

/**
 * One-time backfill of the legacy free-text contributor columns
 * (libri.illustratore / traduttore / curatore) into first-class author entities
 * via libri_autori.ruolo (issue #237).
 *
 * Migration runners invoke this conversion immediately after the schema SQL;
 * MaintenanceService also invokes it as a recovery path for an interrupted or
 * legacy upgrade.
 *
 * Idempotent on two levels: a system_settings marker makes the whole pass a no-op
 * after the first success, and every insert is INSERT IGNORE against the
 * (libro_id, autore_id, ruolo) primary key. Never throws — a failure just leaves
 * the marker unset so the next pass retries; the free-text columns are retained
 * either way.
 */
final class ContributorBackfill
{
    // @include-soft-deleted: this one-time data migration intentionally covers
    // restorable rows as well as currently visible books.
    private const MARKER_CATEGORY = 'migrations';
    private const MARKER_KEY = 'contributors_backfilled';

    /** free-text column on `libri` => target libri_autori.ruolo value */
    private const ROLE_COLUMNS = [
        'illustratore' => 'illustratore',
        'traduttore'   => 'traduttore',
        'curatore'     => 'curatore',
    ];

    public static function run(mysqli $db): bool
    {
        $lockAcquired = false;
        try {
            // The updater and maintenance recovery can run concurrently. The
            // marker alone is not a lock: both processes could resolve the same
            // new name before either writes it (autori.nome is not unique).
            $lockResult = $db->query(
                "SELECT GET_LOCK(CONCAT('pinakes-contributor-backfill:', DATABASE()), 30)"
            );
            $lockAcquired = $lockResult instanceof \mysqli_result
                && (int) ($lockResult->fetch_row()[0] ?? 0) === 1;
            if (!$lockAcquired) {
                throw new \RuntimeException('Unable to acquire contributor backfill lock');
            }

            $settings = new SettingsRepository($db);
            if ($settings->get(self::MARKER_CATEGORY, self::MARKER_KEY, '0') === '1') {
                return true; // already done
            }

            $booksToReindex = self::booksWithPseudonyms($db);
            foreach (self::ROLE_COLUMNS as $column => $ruolo) {
                if (!self::hasColumn($db, 'libri', $column)) {
                    continue; // install predates this free-text column — nothing to migrate
                }
                foreach (self::backfillColumn($db, $column, $ruolo) as $bookId) {
                    $booksToReindex[$bookId] = $bookId;
                }
            }

            // The denormalized FULLTEXT cache predates pseudonym search. Rebuild
            // only affected books so existing pseudonyms become searchable on
            // the first post-upgrade pass as well.
            SearchIndexBuilder::rebuildMany($db, array_values($booksToReindex));
            $settings->set(self::MARKER_CATEGORY, self::MARKER_KEY, '1');
            return true;
        } catch (\Throwable $e) {
            // Best-effort: leave the marker unset so a later pass retries.
            SecureLogger::warning('ContributorBackfill failed: ' . $e->getMessage());
            return false;
        } finally {
            if ($lockAcquired) {
                $db->query("SELECT RELEASE_LOCK(CONCAT('pinakes-contributor-backfill:', DATABASE()))");
            }
        }
    }

    /** @return array<int, int> */
    private static function backfillColumn(mysqli $db, string $column, string $ruolo): array
    {
        $bookIds = [];
        // CI-SOFT-DELETE-EXEMPT: this migration deliberately includes deleted
        // books; they may later be restored and must not permanently miss their
        // contributor entities. The pseudonym rebuild below follows the same rule.
        $sql = "SELECT id, `{$column}` AS raw FROM libri
                WHERE `{$column}` IS NOT NULL AND TRIM(`{$column}`) <> ''";
        $res = $db->query($sql);
        if (!($res instanceof \mysqli_result)) {
            return $bookIds;
        }

        while ($row = $res->fetch_assoc()) {
            $bookId = (int) $row['id'];
            ContributorSync::syncImportedLegacyValues(
                $db,
                $bookId,
                [$ruolo => (string) $row['raw']],
                'legacy-backfill'
            );
            $bookIds[$bookId] = $bookId;
        }
        return $bookIds;
    }

    /** @return array<int, int> */
    private static function booksWithPseudonyms(mysqli $db): array
    {
        $bookIds = [];
        // CI-SOFT-DELETE-EXEMPT: backfill indexes deleted books too because they may later be restored.
        $res = $db->query(
            "SELECT DISTINCT la.libro_id
               FROM libri_autori la
               JOIN autori a ON a.id = la.autore_id
               JOIN libri l ON l.id = la.libro_id
              WHERE TRIM(COALESCE(a.pseudonimo, '')) <> ''"
        );
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_row()) {
                $bookIds[(int) $row[0]] = (int) $row[0];
            }
        }
        return $bookIds;
    }

    /**
     * Split a free-text contributor value into individual names. Delegates to
     * {@see ContributorSync::splitNames()}, which splits on the unambiguous list
     * separators (semicolon and pipe). Ampersands/conjunctions and commas are
     * deliberately preserved because a list cannot be distinguished reliably
     * from an inverted "Surname, Forename" SBN/UNIMARC personal name.
     *
     * @return list<string>
     */
    public static function splitNames(string $raw): array
    {
        return ContributorSync::splitNames($raw);
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
