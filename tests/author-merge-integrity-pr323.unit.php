<?php
declare(strict_types=1);

/**
 * Behavioural regression for AuthorRepository::mergeAuthors().
 *
 * Exercises colliding and non-colliding relational rows so future tables can
 * reuse the same fixture pattern: repoint with UPDATE IGNORE, delete only the
 * duplicate leftovers, then rebuild the denormalized search document.
 *
 * Run: php tests/author-merge-integrity-pr323.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$pass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$name = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$host = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$port = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));

try {
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $user, $pass, $name, 0, $socket)
        : new mysqli($host, $user, $pass, $name, $port);
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — author merge integrity is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "  OK  {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL  {$label}\n";
};

$prefix = 'pr323_merge_' . bin2hex(random_bytes(5));
$primaryName = $prefix . '_primary';
$duplicateName = $prefix . '_duplicate';
$bookTitle = $prefix . '_book';
$authorityForm = $prefix . '_authority';
$ids = ['book' => 0, 'primary' => 0, 'duplicate' => 0, 'authority' => 0];

$tableExists = static function (string $table) use ($db): bool {
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
};

$hasArchivesLinks = $tableExists('authority_records') && $tableExists('autori_authority_link');

$cleanup = static function () use ($db, $bookTitle, $primaryName, $duplicateName, $authorityForm): void {
    $stmt = $db->prepare('DELETE FROM libri WHERE titolo = ?');
    $stmt->bind_param('s', $bookTitle);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare('DELETE FROM autori WHERE nome IN (?, ?)');
    $stmt->bind_param('ss', $primaryName, $duplicateName);
    $stmt->execute();
    $stmt->close();

    try {
        $stmt = $db->prepare('DELETE FROM authority_records WHERE authorised_form = ?');
        if ($stmt !== false) {
            $stmt->bind_param('s', $authorityForm);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable) {
        // Archives is optional on a base-schema test installation.
    }
};

try {
    $cleanup();

    $stmt = $db->prepare('INSERT INTO autori (nome) VALUES (?), (?)');
    $stmt->bind_param('ss', $primaryName, $duplicateName);
    $stmt->execute();
    $stmt->close();

    // Resolve by the unique random fixture names: auto_increment_increment is
    // configurable, so assuming the second id is first+1 would be brittle.
    $stmt = $db->prepare('SELECT id, nome FROM autori WHERE nome IN (?, ?)');
    $stmt->bind_param('ss', $primaryName, $duplicateName);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $author) {
        if ($author['nome'] === $primaryName) {
            $ids['primary'] = (int) $author['id'];
        } elseif ($author['nome'] === $duplicateName) {
            $ids['duplicate'] = (int) $author['id'];
        }
    }
    $stmt->close();
    if ($ids['primary'] <= 0 || $ids['duplicate'] <= 0) {
        throw new RuntimeException('fixture author ids could not be resolved');
    }

    $stmt = $db->prepare('INSERT INTO libri (titolo, search_index) VALUES (?, ?)');
    $initialIndex = $bookTitle . ' ' . $duplicateName;
    $stmt->bind_param('ss', $bookTitle, $initialIndex);
    $stmt->execute();
    $ids['book'] = (int) $db->insert_id;
    $stmt->close();

    $stmt = $db->prepare("INSERT INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito) VALUES (?, ?, 'principale', 0), (?, ?, 'principale', 1), (?, ?, 'traduttore', 2)");
    $stmt->bind_param('iiiiii', $ids['book'], $ids['primary'], $ids['book'], $ids['duplicate'], $ids['book'], $ids['duplicate']);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("INSERT INTO author_authority_alternates (autore_id, source, authority_id, label) VALUES (?, 'viaf', ?, ?), (?, 'sbn', ?, ?)");
    $viafId = $prefix . '_viaf';
    $viafLabel = 'VIAF alternate';
    $sbnId = $prefix . '_sbn';
    $sbnLabel = 'SBN alternate';
    $stmt->bind_param('ississ', $ids['duplicate'], $viafId, $viafLabel, $ids['duplicate'], $sbnId, $sbnLabel);
    $stmt->execute();
    $stmt->close();

    // One tuple collides with an existing primary row; one must be repointed.
    $stmt = $db->prepare("INSERT INTO libri_autori_import_sources (libro_id, autore_id, ruolo, source) VALUES (?, ?, 'principale', 'csv'), (?, ?, 'principale', 'csv'), (?, ?, 'traduttore', 'librarything')");
    $stmt->bind_param('iiiiii', $ids['book'], $ids['primary'], $ids['book'], $ids['duplicate'], $ids['book'], $ids['duplicate']);
    $stmt->execute();
    $stmt->close();

    if ($hasArchivesLinks) {
        $stmt = $db->prepare("INSERT INTO authority_records (type, ric_type, authorised_form) VALUES ('person', 'Person', ?)");
        $stmt->bind_param('s', $authorityForm);
        $stmt->execute();
        $ids['authority'] = (int) $db->insert_id;
        $stmt->close();

        // Collision proves the UPDATE IGNORE + cleanup branch leaves one link.
        $stmt = $db->prepare('INSERT INTO autori_authority_link (autori_id, authority_id) VALUES (?, ?), (?, ?)');
        $stmt->bind_param('iiii', $ids['primary'], $ids['authority'], $ids['duplicate'], $ids['authority']);
        $stmt->execute();
        $stmt->close();
    }

    $repo = new \App\Models\AuthorRepository($db);
    $merged = $repo->mergeAuthors([$ids['duplicate'], $ids['primary'], $ids['duplicate']], $ids['primary']);
    $check($merged === $ids['primary'], 'merge accepts unordered/de-duplicated ids and keeps the requested primary');

    $stmt = $db->prepare('SELECT COUNT(*) FROM autori WHERE id = ?');
    $stmt->bind_param('i', $ids['duplicate']);
    $stmt->execute();
    $check((int) $stmt->get_result()->fetch_column() === 0, 'duplicate author row is removed');
    $stmt->close();

    $stmt = $db->prepare('SELECT ruolo FROM libri_autori WHERE libro_id = ? AND autore_id = ? ORDER BY ruolo');
    $stmt->bind_param('ii', $ids['book'], $ids['primary']);
    $stmt->execute();
    $roles = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'ruolo');
    $stmt->close();
    $check($roles === ['principale', 'traduttore'], 'book links are repointed and colliding roles are de-duplicated');

    $stmt = $db->prepare('SELECT source, authority_id FROM author_authority_alternates WHERE autore_id = ? ORDER BY source');
    $stmt->bind_param('i', $ids['primary']);
    $stmt->execute();
    $alternates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $alternateSources = array_column($alternates, 'source');
    sort($alternateSources);
    $check(count($alternates) === 2 && $alternateSources === ['sbn', 'viaf'], 'all authority alternates survive on the primary author');

    $stmt = $db->prepare('SELECT ruolo, source FROM libri_autori_import_sources WHERE libro_id = ? AND autore_id = ? ORDER BY ruolo, source');
    $stmt->bind_param('ii', $ids['book'], $ids['primary']);
    $stmt->execute();
    $sources = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $check($sources === [
        ['ruolo' => 'principale', 'source' => 'csv'],
        ['ruolo' => 'traduttore', 'source' => 'librarything'],
    ], 'import provenance is repointed without duplicate composite keys');

    if ($hasArchivesLinks) {
        $stmt = $db->prepare('SELECT autori_id FROM autori_authority_link WHERE authority_id = ?');
        $stmt->bind_param('i', $ids['authority']);
        $stmt->execute();
        $archiveLinks = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'autori_id'));
        $stmt->close();
        $check($archiveLinks === [$ids['primary']], 'optional archive authority identity is repointed and de-duplicated');
    } else {
        $authorSource = (string) file_get_contents($root . '/app/Models/AuthorRepository.php');
        $check(str_contains($authorSource, "TABLE_NAME = 'autori_authority_link'")
            && str_contains($authorSource, 'UPDATE IGNORE autori_authority_link SET autori_id = ?'),
            'optional archive link branch remains guarded when the plugin tables are absent');
    }

    $stmt = $db->prepare('SELECT search_index FROM libri WHERE id = ?');
    $stmt->bind_param('i', $ids['book']);
    $stmt->execute();
    $searchIndex = (string) $stmt->get_result()->fetch_column();
    $stmt->close();
    $check(str_contains($searchIndex, $primaryName) && !str_contains($searchIndex, $duplicateName), 'merged book search_index is rebuilt from surviving identities');

    $before = (int) $db->query("SELECT COUNT(*) FROM autori WHERE nome LIKE '" . $db->real_escape_string($prefix . '%') . "'")->fetch_row()[0];
    $check($repo->mergeAuthors([$ids['primary'], $ids['primary']], $ids['primary']) === null, 'a one-author merge is rejected after id normalization');
    $after = (int) $db->query("SELECT COUNT(*) FROM autori WHERE nome LIKE '" . $db->real_escape_string($prefix . '%') . "'")->fetch_row()[0];
    $check($before === $after, 'rejected merge leaves the surviving author untouched');
} catch (Throwable $e) {
    $failed++;
    fwrite(STDERR, 'FAIL  unexpected exception: ' . $e->getMessage() . "\n");
} finally {
    try {
        $cleanup();
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, 'FAIL  cleanup: ' . $e->getMessage() . "\n");
    }
    $db->close();
}

echo "\n================================\n";
echo "Passed: {$passed}   Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
