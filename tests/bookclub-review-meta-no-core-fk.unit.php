<?php
declare(strict_types=1);

/**
 * Regression guard for the book-club activation failure reported on a real 0.7.29 install:
 *   "[BookClub] Schema activation failed for: bookclub_review_meta"
 *
 * Cause: bookclub_review_meta carried a hard FOREIGN KEY to the CORE `recensioni` table.
 * A plugin can't assume a core table's presence or exact id type across installs —
 * `recensioni` is only in schema.sql (never backfilled by a migration), so on older
 * instances it can be absent (MySQL 1824) or have `id int unsigned` (MySQL 3780), either of
 * which aborts the CREATE TABLE and the whole plugin activation.
 *
 * This asserts, at the source level (no DB needed), that the fragile FK is gone while the
 * column, its UNIQUE index, and the safe plugin-owned FK to bookclub_clubs remain.
 *
 * Run:  php tests/bookclub-review-meta-no-core-fk.unit.php   Exit 0 on "ALL <n> PASS".
 */

$src = file_get_contents(dirname(__DIR__) . '/storage/plugins/book-club/src/Modules/LibraryModule.php');
if ($src === false) {
    fwrite(STDERR, "FAIL: cannot read LibraryModule.php\n");
    exit(1);
}

// Isolate the bookclub_review_meta DDL string.
if (!preg_match('/CREATE TABLE IF NOT EXISTS bookclub_review_meta(.*?)ENGINE=InnoDB/s', $src, $m)) {
    fwrite(STDERR, "FAIL: bookclub_review_meta DDL not found\n");
    exit(1);
}
$ddl = $m[1];

$n = 0;
function check(bool $cond, string $desc): void
{
    global $n;
    if (!$cond) {
        throw new \RuntimeException("assertion failed: {$desc}");
    }
    $n++;
    printf("[%02d] PASS: %s\n", $n, $desc);
}

set_exception_handler(function (\Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    exit(1);
});

// 1. No hard FK to the core `recensioni` table — the whole point of the fix.
check(strpos($ddl, 'REFERENCES recensioni') === false,
    'bookclub_review_meta has NO foreign key to the core `recensioni` table');
check(strpos($ddl, 'fk_bcrevmeta_review') === false,
    'the fk_bcrevmeta_review constraint is gone');

// 2. The data model is otherwise intact: the column and its uniqueness survive.
check(preg_match('/recensione_id\s+INT\s+NOT NULL/i', $ddl) === 1,
    'recensione_id is still an INT NOT NULL column');
check(strpos($ddl, 'UNIQUE KEY uq_bcrevmeta_review (recensione_id)') !== false,
    'the one-meta-per-review UNIQUE index on recensione_id is kept');

// 3. The FK to the plugin's OWN table (bookclub_clubs) is safe and must stay.
check(strpos($ddl, 'REFERENCES bookclub_clubs (id)') !== false,
    'the FK to the plugin-owned bookclub_clubs is retained');

printf("\nALL %d PASS\n", $n);
exit(0);
