<?php
declare(strict_types=1);

use App\Support\DataIntegrity;
use App\Support\SearchIndexBuilder;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

// Safety: this is a DESTRUCTIVE demo seed — it reuses/overwrites 'E2E %' rows
// and unlinks their authors. Never let it run via the web or in production.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("seed-demo-catalog.php is CLI-only.\n");
}
$appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '';
if ($appEnv === 'production' || $appEnv === 'prod') {
    fwrite(STDERR, "Refusing to run the destructive demo seed with APP_ENV={$appEnv}.\n");
    exit(1);
}

$settings = require dirname(__DIR__) . '/config/settings.php';
$dbConfig = $settings['db'];
$db = new mysqli(
    $dbConfig['hostname'],
    $dbConfig['username'],
    $dbConfig['password'],
    $dbConfig['database'],
    (int) $dbConfig['port']
);
$db->set_charset('utf8mb4');

$books = [
    [
        'title' => 'To Kill a Mockingbird',
        'subtitle' => 'A novel',
        'isbn13' => '9780061120084',
        'author' => 'Harper Lee',
        'publisher' => 'Harper Perennial',
        'year' => 2006,
        'pages' => 336,
        'language' => 'Inglese',
        'dewey' => '813.54',
        'cover' => '/assets/demo-covers/to-kill-a-mockingbird.jpg',
        'description' => 'Un classico della narrativa americana su giustizia, coscienza e crescita, raccontato attraverso lo sguardo di Scout Finch.',
    ],
    [
        'title' => 'Il Signore degli Anelli',
        'subtitle' => 'Edizione illustrata da Alan Lee',
        'isbn13' => '9780261103252',
        'author' => 'J. R. R. Tolkien',
        'publisher' => 'Bompiani',
        'year' => 2002,
        'pages' => 1368,
        'language' => 'Italiano',
        'dewey' => '823.912',
        'cover' => '/assets/demo-covers/il-signore-degli-anelli.jpg',
        'description' => 'Il viaggio della Compagnia dell’Anello attraverso la Terra di Mezzo, in un’edizione illustrata da Alan Lee.',
    ],
    [
        'title' => 'Fantastic Mr Fox',
        'subtitle' => null,
        'isbn13' => '9780141365442',
        'author' => 'Roald Dahl',
        'publisher' => 'Puffin Books',
        'year' => 2016,
        'pages' => 96,
        'language' => 'Inglese',
        'dewey' => '823.914',
        'cover' => '/assets/demo-covers/fantastic-mr-fox.jpg',
        'description' => 'Mr Fox sfida tre avidi fattori in una storia brillante, ironica e piena di ritmo.',
    ],
    [
        'title' => 'Gli anni in bianco e nero',
        'subtitle' => null,
        'isbn13' => null,
        'author' => 'Francesca Giannone',
        'publisher' => 'Nord',
        'year' => 2024,
        'pages' => 416,
        'language' => 'Italiano',
        'dewey' => '853.92',
        'cover' => '/assets/demo-covers/gli-anni-in-bianco-e-nero.jpg',
        'description' => 'Una storia familiare italiana fatta di scelte, desideri e trasformazioni, sullo sfondo di un Paese che cambia.',
    ],
    [
        'title' => '10 miti su Israele',
        'subtitle' => null,
        'isbn13' => null,
        'author' => 'Ilan Pappé',
        'publisher' => 'Tamu Edizioni',
        'year' => 2022,
        'pages' => 224,
        'language' => 'Italiano',
        'dewey' => '956.94',
        'cover' => '/assets/demo-covers/dieci-miti-su-israele.jpg',
        'description' => 'Un saggio che riesamina dieci narrazioni ricorrenti sulla storia di Israele e Palestina.',
    ],
];

$findAuthor = $db->prepare('SELECT id FROM autori WHERE nome = ? ORDER BY id LIMIT 1');
$insertAuthor = $db->prepare('INSERT INTO autori (nome) VALUES (?)');
$findPublisher = $db->prepare('SELECT id FROM editori WHERE nome = ? ORDER BY id LIMIT 1');
$insertPublisher = $db->prepare('INSERT INTO editori (nome) VALUES (?)');
$findByIsbn = $db->prepare('SELECT id FROM libri WHERE isbn13 = ? AND deleted_at IS NULL LIMIT 1');
$findDemoByTitle = $db->prepare("SELECT id FROM libri WHERE titolo = ? AND source = 'demo-restyling' AND deleted_at IS NULL LIMIT 1");
$findReusableE2e = $db->prepare("SELECT id FROM libri WHERE titolo LIKE 'E2E %' AND isbn13 IS NULL AND deleted_at IS NULL ORDER BY id LIMIT 1");
$updateBook = $db->prepare(
    "UPDATE libri SET titolo=?, sottotitolo=?, isbn13=?, anno_pubblicazione=?, lingua=?, numero_pagine=?, " .
    "genere_id=?, stato='disponibile', copertina_url=?, descrizione=?, descrizione_plain=?, formato='cartaceo', " .
    "tipo_media='libro', editore_id=?, classificazione_dewey=?, source='demo-restyling', deleted_at=NULL WHERE id=?"
);
$insertBook = $db->prepare(
    "INSERT INTO libri (titolo,sottotitolo,isbn13,anno_pubblicazione,lingua,numero_pagine,genere_id,stato,copertina_url," .
    "descrizione,descrizione_plain,formato,tipo_media,editore_id,classificazione_dewey,source,copie_totali,copie_disponibili) " .
    "VALUES (?,?,?,?,?,?,?,'disponibile',?,?,?,'cartaceo','libro',?,?,'demo-restyling',1,1)"
);
$clearReusedAuthors = $db->prepare('DELETE FROM libri_autori WHERE libro_id = ?');
$linkAuthor = $db->prepare("INSERT INTO libri_autori (libro_id,autore_id,ruolo,ordine_credito) VALUES (?,?,'principale',1) ON DUPLICATE KEY UPDATE ordine_credito=1");
$linkPublisher = $db->prepare('INSERT INTO libri_editori (libro_id,editore_id,ordine) VALUES (?,?,0) ON DUPLICATE KEY UPDATE ordine=0');
$clearOtherPublishers = $db->prepare('DELETE FROM libri_editori WHERE libro_id = ? AND editore_id <> ?');
$countCopies = $db->prepare('SELECT COUNT(*) AS total FROM copie WHERE libro_id = ?');
$insertCopy = $db->prepare("INSERT INTO copie (libro_id,numero_inventario,stato) VALUES (?,?,'disponibile')");

$genreId = (int) ($db->query("SELECT id FROM generi WHERE nome = 'Prosa' ORDER BY id LIMIT 1")->fetch_assoc()['id'] ?? 0);
$seededIds = [];

try {
    $db->begin_transaction();

    foreach ($books as $book) {
        $findAuthor->bind_param('s', $book['author']);
        $findAuthor->execute();
        $authorId = (int) ($findAuthor->get_result()->fetch_assoc()['id'] ?? 0);
        if ($authorId === 0) {
            $insertAuthor->bind_param('s', $book['author']);
            $insertAuthor->execute();
            $authorId = $db->insert_id;
        }

        $findPublisher->bind_param('s', $book['publisher']);
        $findPublisher->execute();
        $publisherId = (int) ($findPublisher->get_result()->fetch_assoc()['id'] ?? 0);
        if ($publisherId === 0) {
            $insertPublisher->bind_param('s', $book['publisher']);
            $insertPublisher->execute();
            $publisherId = $db->insert_id;
        }

        $bookId = 0;
        $reusedE2e = false;
        if ($book['isbn13'] !== null) {
            $findByIsbn->bind_param('s', $book['isbn13']);
            $findByIsbn->execute();
            $bookId = (int) ($findByIsbn->get_result()->fetch_assoc()['id'] ?? 0);
        } else {
            $findDemoByTitle->bind_param('s', $book['title']);
            $findDemoByTitle->execute();
            $bookId = (int) ($findDemoByTitle->get_result()->fetch_assoc()['id'] ?? 0);
        }

        if ($bookId === 0) {
            $findReusableE2e->execute();
            $bookId = (int) ($findReusableE2e->get_result()->fetch_assoc()['id'] ?? 0);
            $reusedE2e = $bookId > 0;
        }

        $plain = $book['description'];
        if ($bookId > 0) {
            $updateBook->bind_param(
                'sssisiisssisi',
                $book['title'], $book['subtitle'], $book['isbn13'], $book['year'], $book['language'],
                $book['pages'], $genreId, $book['cover'], $book['description'], $plain,
                $publisherId, $book['dewey'], $bookId
            );
            $updateBook->execute();
            if ($reusedE2e) {
                $clearReusedAuthors->bind_param('i', $bookId);
                $clearReusedAuthors->execute();
            }
        } else {
            $insertBook->bind_param(
                'sssisiisssis',
                $book['title'], $book['subtitle'], $book['isbn13'], $book['year'], $book['language'],
                $book['pages'], $genreId, $book['cover'], $book['description'], $plain,
                $publisherId, $book['dewey']
            );
            $insertBook->execute();
            $bookId = $db->insert_id;
        }

        $linkAuthor->bind_param('ii', $bookId, $authorId);
        $linkAuthor->execute();
        $clearOtherPublishers->bind_param('ii', $bookId, $publisherId);
        $clearOtherPublishers->execute();
        $linkPublisher->bind_param('ii', $bookId, $publisherId);
        $linkPublisher->execute();

        $countCopies->bind_param('i', $bookId);
        $countCopies->execute();
        $copyCount = (int) $countCopies->get_result()->fetch_assoc()['total'];
        if ($copyCount === 0) {
            $inventory = sprintf('DEMO-%04d-01', $bookId);
            $insertCopy->bind_param('is', $bookId, $inventory);
            $insertCopy->execute();
        }

        $seededIds[] = $bookId;
    }

    $db->commit();
} catch (Throwable $exception) {
    $db->rollback();
    fwrite(STDERR, "Seed annullato: {$exception->getMessage()}\n");
    exit(1);
}

$integrity = new DataIntegrity($db);
foreach ($seededIds as $bookId) {
    if (!$integrity->recalculateBookAvailability($bookId)) {
        fwrite(STDERR, "Impossibile ricalcolare la disponibilità del libro {$bookId}.\n");
        exit(1);
    }
}

// The demo seed updates books and their author/publisher relations directly,
// outside the normal controllers. Rebuild the denormalized index only once,
// after the transaction, so title, subtitle, author and publisher autocomplete
// all reflect the seeded records without one UPDATE per relation.
SearchIndexBuilder::rebuildMany($db, $seededIds);

echo sprintf("Catalogo demo aggiornato: %d libri con copertine locali reali.\n", count($seededIds));
foreach ($seededIds as $bookId) {
    echo "- libro #{$bookId}\n";
}
