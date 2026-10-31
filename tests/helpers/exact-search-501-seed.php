<?php
declare(strict_types=1);

/**
 * Seed / clean the issue #501 E2E fixtures: authors, publishers, books,
 * borrowers, a pending loan and a reservation per spelling, all carrying a
 * per-run token. Prints the created ids as JSON.
 *
 *   php tests/helpers/exact-search-501-seed.php seed <Token>
 *   php tests/helpers/exact-search-501-seed.php clean '<json from seed>'
 *
 * Connection: the E2E_DB_* variables set by the E2E runner.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('E2E_DB_HOST') ?: '';
$db = $host !== ''
    ? new mysqli($host, (string) getenv('E2E_DB_USER'), (string) getenv('E2E_DB_PASS'), (string) getenv('E2E_DB_NAME'), (int) (getenv('E2E_DB_PORT') ?: 3306))
    : new mysqli(null, (string) getenv('E2E_DB_USER'), (string) getenv('E2E_DB_PASS'), (string) getenv('E2E_DB_NAME'), 0, (string) getenv('E2E_DB_SOCKET'));
$db->set_charset('utf8mb4');

/** @param list<string|int> $params */
function seed501(mysqli $db, string $sql, array $params): int
{
    $stmt = $db->prepare($sql);
    $stmt->bind_param(str_repeat('s', count($params)), ...array_map('strval', $params));
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
}

$mode = $argv[1] ?? '';
if ($mode === 'clean') {
    $ids = json_decode((string) ($argv[2] ?? '{}'), true) ?: [];
    $list = static fn(string $k): string => implode(',', array_map('intval', $ids[$k] ?? [0]));
    foreach ([
        'DELETE FROM prenotazioni WHERE id IN (' . $list('reservations') . ')',
        'DELETE FROM prestiti WHERE id IN (' . $list('loans') . ')',
        'DELETE FROM libri_autori WHERE libro_id IN (' . $list('books') . ')',
        'DELETE FROM libri WHERE id IN (' . $list('books') . ')',
        'DELETE FROM autori WHERE id IN (' . $list('authors') . ')',
        'DELETE FROM editori WHERE id IN (' . $list('publishers') . ')',
        'DELETE FROM utenti WHERE id IN (' . $list('users') . ')',
        'DELETE FROM posizioni WHERE id IN (' . $list('positions') . ')',
        'DELETE FROM mensole WHERE id IN (' . $list('levels') . ')',
        'DELETE FROM scaffali WHERE id IN (' . $list('shelves') . ')',
        'DELETE FROM generi WHERE id IN (' . $list('genres') . ')',
    ] as $sql) {
        $db->query($sql);
    }
    echo "{}\n";
    exit(0);
}
if (!in_array($mode, ['seed', 'seed-fields'], true) || ($argv[2] ?? '') === '') {
    fwrite(STDERR, "usage: seed <Token> | clean <json>\n");
    exit(2);
}

$t = $argv[2];
$out = ['authors' => [], 'publishers' => [], 'books' => [], 'users' => [], 'loans' => [], 'reservations' => []];
$fixtures = [
    ['author' => "{$t} Haffner", 'publisher' => "Verlag {$t} Haffner", 'title' => "{$t} Brückenbuch", 'surname' => 'Haffner'],
    ['author' => "{$t} Häffner", 'publisher' => "Verlag {$t} Häffner", 'title' => "{$t} Gedichtband", 'surname' => 'Häffner'],
    ['author' => "{$t} HAFFNER", 'publisher' => "Verlag {$t} 50% Rabatt", 'title' => "{$t} Großbuch", 'surname' => null],
];
foreach ($fixtures as $i => $f) {
    $author = seed501($db, 'INSERT INTO autori (nome) VALUES (?)', [$f['author']]);
    $publisher = seed501($db, 'INSERT INTO editori (nome) VALUES (?)', [$f['publisher']]);
    $book = seed501($db, 'INSERT INTO libri (titolo, editore_id, copie_totali, copie_disponibili) VALUES (?, ?, 1, 1)', [$f['title'], $publisher]);
    seed501($db, "INSERT INTO libri_autori (libro_id, autore_id, ruolo) VALUES (?, ?, 'principale')", [$book, $author]);
    $out['authors'][] = $author;
    $out['publishers'][] = $publisher;
    $out['books'][] = $book;
    if ($f['surname'] === null) {
        continue;
    }
    $user = seed501(
        $db,
        "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata, privacy_accettata)
         VALUES (?, ?, ?, ?, ?, 'standard', 'attivo', 1, 1)",
        [substr(strtoupper($t), 0, 14) . $i, $t, $f['surname'], strtolower($t) . ".{$i}@example.test", password_hash('x', PASSWORD_DEFAULT)]
    );
    $out['users'][] = $user;
    $out['loans'][] = seed501(
        $db,
        "INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, attivo)
         VALUES (?, ?, CURDATE(), CURDATE() + INTERVAL 30 DAY, 'pendente', 0)",
        [$book, $user]
    );
    $out['reservations'][] = seed501($db, 'INSERT INTO prenotazioni (libro_id, utente_id) VALUES (?, ?)', [$book, $user]);
}
if ($mode === 'seed-fields') {
    foreach (['Haffner', 'Häffner', 'HAFFNER'] as $i => $surname) {
        $value = "$t $surname";
        seed501($db, 'UPDATE autori SET pseudonimo=?, biografia=?, sito_web=?, `nazionalità`=? WHERE id=?', [$value,$value,$value,$value,$out['authors'][$i]]);
        seed501($db, 'UPDATE editori SET sito_web=?, indirizzo=? WHERE id=?', [$value,$value,$out['publishers'][$i]]);
        seed501($db, 'UPDATE libri SET collana=?, isbn13=? WHERE id=?', [$value,strtolower($t).$i,$out['books'][$i]]);
        $genre = $out['genres'][] = seed501($db, 'INSERT INTO generi (nome) VALUES (?)', [$value]);
        $shelf = $out['shelves'][] = seed501($db, 'INSERT INTO scaffali (nome,codice,lettera) VALUES (?,?,?)', [$value,$t.$i,'Z']);
        $level = $out['levels'][] = seed501($db, 'INSERT INTO mensole (scaffale_id,numero_livello) VALUES (?,1)', [$shelf]);
        $out['positions'][] = seed501($db, 'INSERT INTO posizioni (scaffale_id,mensola_id,genere_id) VALUES (?,?,?)', [$shelf,$level,$genre]);
    }
}
App\Support\SearchIndexBuilder::rebuildMany($db, $out['books']);
echo json_encode($out), "\n";
