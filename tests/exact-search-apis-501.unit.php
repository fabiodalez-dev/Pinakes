<?php
declare(strict_types=1);

/**
 * Issue #501, part 6 of 9: the search parameters of the mobile API (staff
 * loans, reservations and borrower picker; catalogue author/publisher
 * filters) and of the public REST API (author filter). Each one runs its real
 * query against seeded rows: two books, authors, publishers and borrowers
 * that differ only by an umlaut, plus a pending loan and a reservation each.
 */

require __DIR__ . '/helpers/exact-search-501.php';
require_once dirname(__DIR__) . '/storage/plugins/mobile-api/MobileApiPlugin.php';

use App\Plugins\MobileApi\Controllers\CatalogController;
use App\Plugins\MobileApi\Support\StaffLoanReader;

$ok = [Exact501::class, 'check'];
$same = [Exact501::class, 'same'];
$db = Exact501::db();

$t = ucfirst(Exact501::token());
$ids = ['autori' => [], 'editori' => [], 'libri' => [], 'utenti' => [], 'prestiti' => [], 'prenotazioni' => []];
Exact501::onCleanup(static function () use ($db, &$ids): void {
    foreach (['prenotazioni', 'prestiti'] as $table) {
        if ($ids[$table] !== []) {
            $db->query("DELETE FROM {$table} WHERE id IN (" . implode(',', $ids[$table]) . ')');
        }
    }
    if ($ids['libri'] !== []) {
        $db->query('DELETE FROM libri_autori WHERE libro_id IN (' . implode(',', $ids['libri']) . ')');
    }
    foreach (['libri', 'autori', 'editori', 'utenti'] as $table) {
        if ($ids[$table] !== []) {
            $db->query("DELETE FROM {$table} WHERE id IN (" . implode(',', $ids[$table]) . ')');
        }
    }
});

foreach (['plain' => ['Haffner', 'Brucke'], 'umlaut' => ['Häffner', 'Brücke']] as $key => [$surname, $word]) {
    $author = Exact501::exec($db, 'INSERT INTO autori (nome) VALUES (?)', ["{$t} {$surname}"]);
    $publisher = Exact501::exec($db, 'INSERT INTO editori (nome) VALUES (?)', ["Verlag {$t} {$surname}"]);
    $book = Exact501::exec($db, 'INSERT INTO libri (titolo, editore_id, copie_totali, copie_disponibili) VALUES (?, ?, 1, 1)', ["{$t} {$word}", $publisher]);
    Exact501::exec($db, "INSERT INTO libri_autori (libro_id, autore_id, ruolo) VALUES (?, ?, 'principale')", [$book, $author]);
    $user = Exact501::exec(
        $db,
        'INSERT INTO utenti (codice_tessera, nome, cognome, email, password) VALUES (?, ?, ?, ?, ?)',
        [substr($t, 0, 10) . $key[0], $t, $surname, strtolower($t) . ".{$key}@example.test", password_hash('x', PASSWORD_DEFAULT)]
    );
    $ids['autori'][] = $author;
    $ids['editori'][] = $publisher;
    $ids['libri'][] = $book;
    $ids['utenti'][] = $user;
    $ids['prestiti'][] = Exact501::exec(
        $db,
        "INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, attivo) VALUES (?, ?, CURDATE(), CURDATE() + INTERVAL 30 DAY, 'pendente', 0)",
        [$book, $user]
    );
    $ids['prenotazioni'][] = Exact501::exec($db, 'INSERT INTO prenotazioni (libro_id, utente_id) VALUES (?, ?)', [$book, $user]);
}
\App\Support\SearchIndexBuilder::rebuildMany($db, $ids['libri']);
[$bookPlain, $bookUmlaut] = $ids['libri'];
[$userPlain, $userUmlaut] = $ids['utenti'];
[$loanPlain, $loanUmlaut] = $ids['prestiti'];
[$resPlain, $resUmlaut] = $ids['prenotazioni'];

$ofItems = static fn(array $items, array $mine): array => array_values(array_intersect(
    array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $items),
    $mine
));

// ── Mobile API: staff borrower picker ──────────────────────────────────────
$reader = new StaffLoanReader($db);
$users = static fn(string $q): array => $ofItems($reader->users($q, 50), $ids['utenti']);
$same([$userPlain, $userUmlaut], $users("{$t} haffner"), 'staff borrower picker: unquoted finds both spellings');
$same([$userPlain], $users("\"{$t} haffner\""), 'staff borrower picker: quoted Haffner excludes Häffner');
$same([$userUmlaut], $users("\"{$t} Häffner\""), 'staff borrower picker: quoted Häffner excludes Haffner');
$same([$userUmlaut], $users('"' . mb_strtoupper("{$t} häffner") . '"'), 'staff borrower picker: case ignored inside quotes');
$same([$userUmlaut], $users("\"{$t}\" \"häffner\""), 'staff borrower picker: two quoted groups form one phrase');

// ── Mobile API: staff loans and reservations ───────────────────────────────
$loans = static fn(string $q): array => $ofItems($reader->loans('pending', ['q' => $q], 0, 100)['items'], $ids['prestiti']);
$same([$loanPlain, $loanUmlaut], $loans("{$t} brucke"), 'staff loans: unquoted title finds both spellings');
$same([$loanUmlaut], $loans("\"{$t} brücke\""), 'staff loans: quoted Brücke excludes Brucke');
$same([$loanPlain], $loans("\"{$t} haffner\""), 'staff loans: quoted borrower name excludes the umlaut spelling');
$same([$loanPlain, $loanUmlaut], $loans("{$t} haffner"), 'staff loans: unquoted borrower name finds both');

$reservations = static fn(string $q): array => $ofItems($reader->reservations('all', ['q' => $q], 0, 100)['items'], $ids['prenotazioni']);
$same([$resPlain, $resUmlaut], $reservations("{$t} haffner"), 'staff reservations: unquoted finds both spellings');
$same([$resUmlaut], $reservations("\"{$t} Häffner\""), 'staff reservations: quoted borrower Häffner excludes Haffner');
$same([$resPlain], $reservations("\"{$t} brucke\""), 'staff reservations: quoted title Brucke excludes Brücke');

// ── Public REST API: author filter ─────────────────────────────────────────
$public = new \App\Controllers\PublicApiController();
$findBooks = new \ReflectionMethod($public, 'findBooks');
$publicIds = static fn(string $author): array => $ofItems($findBooks->invoke($public, $db, null, null, null, $author), $ids['libri']);
$same([$bookPlain, $bookUmlaut], $publicIds("{$t} haffner"), 'public API: unquoted author finds both spellings');
$same([$bookPlain], $publicIds("\"{$t} haffner\""), 'public API: quoted Haffner excludes Häffner');
$same([$bookUmlaut], $publicIds("\"{$t} Häffner\""), 'public API: quoted Häffner excludes Haffner');
$same([$bookUmlaut], $publicIds('"' . mb_strtoupper("{$t} häffner") . '"'), 'public API: case ignored inside quotes');

// ── Mobile API: catalogue filters ──────────────────────────────────────────
$catalog = new CatalogController($db, true);
$buildFilters = new \ReflectionMethod($catalog, 'buildFilters');
$catalogIds = static function (array $params) use ($db, $catalog, $buildFilters, $ids): array {
    [$conditions, $bind, $types] = $buildFilters->invoke($catalog, $params);
    $conditions[] = 'l.id IN (' . implode(',', $ids['libri']) . ')';
    $stmt = $db->prepare('SELECT l.id FROM libri l LEFT JOIN editori e ON e.id = l.editore_id WHERE l.deleted_at IS NULL AND ' . implode(' AND ', $conditions) . ' ORDER BY l.id');
    if ($types !== '') {
        $stmt->bind_param($types, ...$bind);
    }
    $stmt->execute();
    return array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
};
$same([$bookPlain, $bookUmlaut], $catalogIds(['author' => "{$t} haffner"]), 'mobile catalogue: unquoted author finds both spellings');
$same([$bookPlain], $catalogIds(['author' => "\"{$t} haffner\""]), 'mobile catalogue: quoted author Haffner excludes Häffner');
$same([$bookUmlaut], $catalogIds(['author' => "\"{$t} Häffner\""]), 'mobile catalogue: quoted author Häffner excludes Haffner');
$same([$bookPlain, $bookUmlaut], $catalogIds(['publisher' => "Verlag {$t} haffner"]), 'mobile catalogue: unquoted publisher finds both spellings');
$same([$bookPlain], $catalogIds(['publisher' => "\"Verlag {$t} haffner\""]), 'mobile catalogue: quoted publisher Haffner excludes Häffner');
$same([$bookUmlaut], $catalogIds(['publisher' => "\"Verlag {$t} Häffner\""]), 'mobile catalogue: quoted publisher Häffner excludes Haffner');
$same([$bookUmlaut], $catalogIds(['author' => (string) $ids['autori'][1]]), 'mobile catalogue: a numeric author id is still an id, not a name');
$same([$bookUmlaut], $catalogIds(['q' => "{$t} \"brücke\""]), 'mobile catalogue: a quoted word in q follows the book search rule');
$same([$bookPlain, $bookUmlaut], $catalogIds(['publisher' => '"']), 'mobile catalogue: a lone quote adds no publisher filter');

Exact501::finish(25);
