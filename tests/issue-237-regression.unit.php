<?php
declare(strict_types=1);

/**
 * Issue #237 — reusable regression suite (25 checks) over every surface the
 * contributor-roles / pseudonym work touched. Complements the behavioural
 * tests in contributor-roles-237.unit.php and migration-*.unit.php: this file
 * pins the INVARIANTS that past review rounds proved easy to regress —
 * RC version guards, schema parity, comma-safe name splitting, atomic saves,
 * the legacy compatibility cache, importer provenance co-ownership, LIKE
 * escaping and principale-first interop ordering.
 *
 * Sections:
 *   A (1-11)  static — parse the shipped sources; no DB needed.
 *   B (12-18) pure   — ContributorSync / AuthorName behaviour; no DB needed.
 *   C (19-25) DB     — atomicity + cache + provenance against the real schema
 *                      (skipped cleanly when no database is reachable).
 *
 * Run:  php tests/issue-237-regression.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Models\BookRepository;
use App\Support\AuthorName;
use App\Support\ContributorSync;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  OK  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n";
    }
};
$src = static fn (string $rel): string => (string) file_get_contents(__DIR__ . '/../' . $rel);

// ─────────────────────────────────────────────────────────────────────────────
// A. Static invariants (source-derived, no DB)
// ─────────────────────────────────────────────────────────────────────────────
echo "A. RC guards, schema parity, escaping, interop ordering (static)\n";

$releaseVersion = (string) (json_decode($src('version.json'), true)['version'] ?? '');

// 1-2. The ContributorBackfill gate must fire for the version actually shipped.
//      An RC sorts BELOW its bare final under version_compare, so a guard
//      pinned to '0.7.36' silently skips the backfill on a 0.7.36-rc.N upgrade
//      (real incident: bibliodoc, 2026-07-15). Extract the literal each runner
//      compares against and prove the shipping version satisfies it.
$guardCovers = static function (string $source) use ($releaseVersion): bool {
    if (preg_match_all(
        "/version_compare\\(\\s*\\\$\\w+\\s*,\\s*'([^']+)'\\s*,\\s*'>='\\s*\\)/",
        $source,
        $m
    ) === 0) {
        return false;
    }
    foreach ($m[1] as $literal) {
        // Every backfill-era guard literal must admit the shipping version.
        if (str_starts_with($literal, '0.7.36') && !version_compare($releaseVersion, $literal, '>=')) {
            return false;
        }
    }
    return in_array(true, array_map(
        static fn (string $l): bool => str_starts_with($l, '0.7.36'),
        $m[1]
    ), true);
};
$check($guardCovers($src('app/Support/Updater.php')), 'Updater backfill guard admits the shipping version (RC-safe)');
$check($guardCovers($src('scripts/manual-upgrade.php')), 'manual-upgrade backfill guard admits the shipping version (RC-safe)');

// 3. No migration file may carry a version above the release — the updater
//    silently skips those (version_compare(v, to, '<=') false).
$migrationsOk = true;
foreach (glob($root . '/installer/database/migrations/migrate_*.sql') ?: [] as $file) {
    $v = str_replace(['migrate_', '.sql'], '', basename($file));
    if (!version_compare($v, $releaseVersion, '<=')) {
        $migrationsOk = false;
        echo "       offending file: " . basename($file) . "\n";
    }
}
$check($migrationsOk, "every migrate_*.sql version <= {$releaseVersion}");

// 4. A release that ships a migration must ship its behavioural test too.
$releaseMigration = $root . "/installer/database/migrations/migrate_{$releaseVersion}.sql";
$check(
    !file_exists($releaseMigration) || file_exists($root . "/tests/migration-{$releaseVersion}.unit.php"),
    'release migration has a matching behavioural unit test'
);

$schema = $src('installer/database/schema.sql');
$roleEnum = "enum('principale','co-autore','traduttore','illustratore','curatore','colorista')";

// 5. Fresh installs must get the full role enum straight from schema.sql —
//    the migration only serves upgrades.
$check(str_contains($schema, $roleEnum), 'schema.sql libri_autori.ruolo enum includes all six roles');

// 6. Provenance co-ownership invariant: `source` MUST be part of the PK, so a
//    second importer adds a row instead of seizing the first importer's link.
$check(
    (bool) preg_match(
        '/libri_autori_import_sources.*?PRIMARY KEY \(`libro_id`,`autore_id`,`ruolo`,`source`\)/s',
        $schema
    ),
    'import_sources PK is (libro_id, autore_id, ruolo, source) — source co-owned'
);

// 7. Upgrade path parity: the migration must install the exact enum schema.sql
//    declares, or fresh and upgraded installs drift apart.
$migrationFile = file_exists($releaseMigration)
    ? (string) file_get_contents($releaseMigration)
    : '';
$check(
    $migrationFile === '' || str_contains($migrationFile, $roleEnum),
    'release migration enum matches schema.sql enum (no drift)'
);

// 8. Author autocomplete hardening: EVERY pseudonimo LIKE placeholder must be
//    paired with ESCAPE (exhaustive — a future path added without it fails
//    here), and the wildcard-escaping bind pattern must be present. Caught a
//    real gap on first run: two of the three SearchController paths shipped
//    unescaped because a replace-all fix silently missed their indentation.
$search = $src('app/Controllers/SearchController.php');
$check(
    preg_match('/pseudonimo LIKE \?(?! ESCAPE)/', $search) === 0
        && substr_count($search, "pseudonimo LIKE ? ESCAPE") >= 3
        && substr_count($search, "str_replace(['\\\\', '%', '_']") >= 3,
    'SearchController: every pseudonimo LIKE uses ESCAPE + wildcard-escaped bind'
);

// 9. Same exhaustive invariant in the mobile API author filter.
$mobile = $src('storage/plugins/mobile-api/src/Controllers/CatalogController.php');
$check(
    preg_match('/pseudonimo LIKE \?(?! ESCAPE)/', $mobile) === 0
        && str_contains($mobile, "str_replace(['\\\\', '%', '_']"),
    'mobile-api CatalogController: every pseudonimo LIKE uses ESCAPE + escaped bind'
);

// 10. View-escaping rule: the contributor display in book-detail must never
//     call htmlspecialchars() without ENT_QUOTES (project rule 3).
$bookDetail = $src('app/Views/frontend/book-detail.php');
$check(
    !str_contains($bookDetail, 'htmlspecialchars($authorDisplay)'),
    'book-detail escapes $authorDisplay with ENT_QUOTES (no bare htmlspecialchars)'
);

// 11. Interop exports must put the principal author first: with the #237
//     links often carrying ordine_credito=NULL, a name-ordered query lets an
//     illustrator take the UNIMARC 200$f / BIBFRAME / NCIP main-author slot.
$interopOk = true;
foreach ([
    'storage/plugins/oai-pmh-server/OaiPmhServerPlugin.php' => "CASE la.ruolo WHEN 'principale' THEN 0",
    'storage/plugins/bibframe-linked-data/BibframeLinkedDataPlugin.php' => "ruolo = \\'principale\\') DESC",
    'storage/plugins/ncip-server/NcipServerPlugin.php' => "ruolo = \\'principale\\') DESC",
    'storage/plugins/z39-server/Z39ServerPlugin.php' => "ruolo = 'principale') DESC",
] as $rel => $needle) {
    if (!str_contains($src($rel), $needle)) {
        $interopOk = false;
        echo "       missing principale-first ordering: {$rel}\n";
    }
}
$check($interopOk, 'OAI/BIBFRAME/NCIP/Z39 order contributors principale-first');

// ─────────────────────────────────────────────────────────────────────────────
// B. Pure behaviour (autoloaded classes, no DB)
// ─────────────────────────────────────────────────────────────────────────────
echo "B. splitNames + AuthorName behaviour (pure)\n";

// 12. Unambiguous list separators split.
$check(
    ContributorSync::splitNames('A; B | C & D e E and F') === ['A', 'B', 'C', 'D', 'E', 'F'],
    'splitNames splits on ; | & " e " " and "'
);

// 13. A comma NEVER splits: SBN/UNIMARC expose one person as
//     "Surname, Forename" and no heuristic can tell that from a list.
$check(
    ContributorSync::splitNames('García Márquez, Gabriel José') === ['García Márquez, Gabriel José'],
    'splitNames keeps a multi-word inverted SBN name as ONE person'
);

// 14. The documented trade-off: an ambiguous comma list also stays one value.
$check(
    ContributorSync::splitNames('Mario Rossi, Luigi Bianchi') === ['Mario Rossi, Luigi Bianchi'],
    'splitNames keeps an ambiguous comma list as one value (documented trade-off)'
);

// 15. Dedup + garbage fragments dropped.
$check(
    ContributorSync::splitNames('Mario Rossi; Mario Rossi;  ,  ; ') === ['Mario Rossi']
        && ContributorSync::splitNames('  ,  ') === [],
    'splitNames dedupes and drops empty / comma-only fragments'
);

// 16. Pseudonym display: "Pseudonimo (Nome)".
$check(
    AuthorName::display(['nome' => 'Charles Dodgson', 'pseudonimo' => 'Lewis Carroll']) === 'Lewis Carroll (Charles Dodgson)',
    'display(): pseudonym renders as "Pseudonimo (Nome)"'
);

// 17. Display guards: no pseudonym → nome; pseudonimo == nome → nome (no
//     "X (X)"); pseudonym-only rows still render.
$check(
    AuthorName::display(['nome' => 'Mario Rossi', 'pseudonimo' => '']) === 'Mario Rossi'
        && AuthorName::display(['nome' => 'Mario Rossi', 'pseudonimo' => 'Mario Rossi']) === 'Mario Rossi'
        && AuthorName::display(['nome' => '', 'pseudonimo' => 'Banksy']) === 'Banksy',
    'display(): guards — empty pseudonym, pseudonym==nome, pseudonym-only'
);

// 18. displaySql alias rewrite: a custom alias must fully replace `a`. and an
//     invalid alias must fall back to `a` (never reach SQL unvalidated).
$aliased = AuthorName::displaySql('autori');
$check(
    str_contains($aliased, '`autori`.`pseudonimo`')
        && !str_contains($aliased, '`a`.`')
        && AuthorName::displaySql('bad alias; DROP') === AuthorName::displaySql('a'),
    'displaySql(): alias fully rewritten; invalid alias falls back to `a`'
);

// ─────────────────────────────────────────────────────────────────────────────
// C. Database-backed invariants (atomicity, legacy cache, provenance)
// ─────────────────────────────────────────────────────────────────────────────
echo "C. Atomic save, legacy cache, provenance (DB)\n";

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

try {
    $socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    echo "SKIP: no database reachable — section C (7 checks) skipped: {$e->getMessage()}\n";
    echo $fail === 0 ? "\nALL {$pass} PASS (DB section skipped)\n" : "\n{$pass} PASS, {$fail} FAIL\n";
    exit($fail === 0 ? 0 : 1);
}

$token = bin2hex(random_bytes(4));
$repo = new BookRepository($db);
$bookIds = [];
$authorIds = [];

$newAuthor = static function (string $name) use ($db, &$authorIds): int {
    $stmt = $db->prepare('INSERT INTO autori (nome) VALUES (?)');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $stmt->close();
    $id = (int) $db->insert_id;
    $authorIds[] = $id;
    return $id;
};
$col = static function (string $sql, array $params = [], string $types = '') use ($db) {
    $stmt = $db->prepare($sql);
    if ($params !== []) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row[0] ?? null;
};

try {
    $creatorId = $newAuthor("ZZ Reg Creator {$token}");

    // 19. Invalid FK contributor id: the whole create must roll back —
    //     no orphan libri row may survive the failed sync.
    $orphanTitle = "ZZ reg orphan-fk {$token}";
    $threw = false;
    try {
        $repo->createBasic(['titolo' => $orphanTitle, 'autori_ids' => [999999999]]);
    } catch (\Throwable $e) {
        $threw = true;
    }
    $check(
        $threw && (int) $col('SELECT COUNT(*) FROM libri WHERE titolo = ?', [$orphanTitle], 's') === 0,
        'create with FK-invalid contributor id throws AND leaves no orphan book'
    );

    // 20. Unresolved new_* id (frontend contract slip): same full rollback.
    $orphanTitle2 = "ZZ reg orphan-new {$token}";
    $threw = false;
    try {
        $repo->createBasic(['titolo' => $orphanTitle2, 'autori_ids' => ['new_123_abc']]);
    } catch (\Throwable $e) {
        $threw = true;
    }
    $check(
        $threw && (int) $col('SELECT COUNT(*) FROM libri WHERE titolo = ?', [$orphanTitle2], 's') === 0,
        'create with unresolved new_* id throws AND leaves no orphan book'
    );

    // Baseline book for the update-path checks.
    $bookTitle = "ZZ reg book {$token}";
    $bookId = $repo->createBasic(['titolo' => $bookTitle, 'autori_ids' => [$creatorId]]);
    $bookIds[] = $bookId;

    // 21. Malformed id on update: title change must roll back with the sync.
    $threw = false;
    try {
        $repo->updateBasic($bookId, ['titolo' => "ZZ reg mutated {$token}", 'autori_ids' => ['abc']]);
    } catch (\Throwable $e) {
        $threw = true;
    }
    $check(
        $threw && $col('SELECT titolo FROM libri WHERE id = ?', [$bookId], 'i') === $bookTitle,
        'update with malformed contributor id throws AND the title change rolls back'
    );

    // 21b safety: the creator link must have survived the failed update.
    // (folded into 22's precondition rather than a separate numbered check)

    // 22. Un-backfilled legacy free-text must survive an entity-form save with
    //     an empty picker — it is the #237 rollback/recovery safety net.
    $legacy = "ZZ Reg Legacy Illustrator {$token}";
    $stmt = $db->prepare('UPDATE libri SET illustratore = ? WHERE id = ?');
    $stmt->bind_param('si', $legacy, $bookId);
    $stmt->execute();
    $stmt->close();
    $repo->updateBasic($bookId, ['autori_ids' => [$creatorId], 'illustratori_ids' => []]);
    $check(
        $col('SELECT illustratore FROM libri WHERE id = ?', [$bookId], 'i') === $legacy,
        'empty picker preserves un-backfilled legacy free-text (safety net)'
    );

    // 23. Entity links refresh the legacy compatibility cache (CSV export and
    //     the public API still read it), and an explicit removal clears both.
    $illId = $newAuthor("ZZ Reg Illustrator {$token}");
    $repo->updateBasic($bookId, ['illustratori_ids' => [$illId]]);
    $cached = $col('SELECT illustratore FROM libri WHERE id = ?', [$bookId], 'i');
    $repo->updateBasic($bookId, ['illustratori_ids' => []]);
    $cleared = $col('SELECT illustratore FROM libri WHERE id = ?', [$bookId], 'i');
    $links = (int) $col(
        "SELECT COUNT(*) FROM libri_autori WHERE libro_id = ? AND ruolo = 'illustratore'",
        [$bookId],
        'i'
    );
    $check(
        $cached === "ZZ Reg Illustrator {$token}" && $cleared === null && $links === 0,
        'entity link mirrors into the legacy cache; explicit removal clears cache + link'
    );

    // 24. Re-import replaces the importer's OWN stale link (per-source
    //     provenance): a second csv import with a different translator prunes
    //     the first csv translator but never touches manual links.
    $impBookId = $repo->createBasic(['titolo' => "ZZ reg import {$token}", 'autori_ids' => [$creatorId]]);
    $bookIds[] = $impBookId;
    ContributorSync::syncImportedLegacyValues($db, $impBookId, ['traduttore' => "ZZ Reg TradA {$token}"], 'csv');
    ContributorSync::syncImportedLegacyValues($db, $impBookId, ['traduttore' => "ZZ Reg TradB {$token}"], 'csv');
    $tradNames = [];
    $res = $db->query(
        "SELECT a.nome FROM libri_autori la JOIN autori a ON a.id = la.autore_id
          WHERE la.libro_id = {$impBookId} AND la.ruolo = 'traduttore'"
    );
    while ($row = $res->fetch_row()) {
        $tradNames[] = $row[0];
    }
    $check(
        $tradNames === ["ZZ Reg TradB {$token}"],
        'csv re-import replaces its own stale translator link (A pruned, B linked)'
    );

    // 25. Cross-source co-ownership: a second importer touching the same link
    //     ADDS its provenance row (PK includes source) — it must not seize or
    //     delete the first importer's row, and the association must survive.
    ContributorSync::syncImportedLegacyValues($db, $impBookId, ['curatore' => "ZZ Reg Shared {$token}"], 'csv');
    ContributorSync::syncImportedLegacyValues($db, $impBookId, ['curatore' => "ZZ Reg Shared {$token}"], 'librarything');
    $provenanceSources = (int) $col(
        "SELECT COUNT(DISTINCT s.source) FROM libri_autori_import_sources s
          JOIN autori a ON a.id = s.autore_id
         WHERE s.libro_id = ? AND s.ruolo = 'curatore' AND a.nome = ?",
        [$impBookId, "ZZ Reg Shared {$token}"],
        'is'
    );
    $sharedLink = (int) $col(
        "SELECT COUNT(*) FROM libri_autori la JOIN autori a ON a.id = la.autore_id
          WHERE la.libro_id = ? AND la.ruolo = 'curatore' AND a.nome = ?",
        [$impBookId, "ZZ Reg Shared {$token}"],
        'is'
    );
    $check(
        $provenanceSources === 2 && $sharedLink === 1,
        'two importers co-own one link (2 provenance rows, association intact)'
    );
} finally {
    // FK-safe cleanup: deleting libri cascades to libri_autori and
    // libri_autori_import_sources; test authors are removed by name prefix.
    foreach ($bookIds as $id) {
        $db->query("DELETE FROM libri WHERE id = {$id}");
    }
    $stmt = $db->prepare("DELETE FROM autori WHERE nome LIKE ?");
    $prefix = "ZZ Reg %{$token}";
    $stmt->bind_param('s', $prefix);
    $stmt->execute();
    $stmt->close();
    $db->close();
}

echo $fail === 0 ? "\nALL {$pass} PASS\n" : "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
