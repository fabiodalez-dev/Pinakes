<?php
declare(strict_types=1);

/** Real database contracts; unique fixtures, intercepted mail and complete cleanup. */
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/storage/plugins/mobile-api/wrapper.php';
require dirname(__DIR__) . '/storage/plugins/desiderata/wrapper.php';
require dirname(__DIR__) . '/storage/plugins/archives/wrapper.php';
require dirname(__DIR__) . '/storage/plugins/emeroteca/src/Modules/MobileModule.php';
require dirname(__DIR__) . '/storage/plugins/emeroteca/src/Services/ContributionService.php';

$env = Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__) . '/.env'));
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? 'localhost'), getenv('E2E_DB_USER') ?: $env['DB_USER'],
    getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? $env['DB_PASSWORD'] ?? ''), getenv('E2E_DB_NAME') ?: $env['DB_NAME'],
    (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
$db->set_charset('utf8mb4');
$hooks = new App\Support\HookManager($db);
$plugin = new DesiderataPlugin($db, $hooks);
$plugin->setEmailService(new class($db) extends App\Support\EmailService {
    public function sendEmail(string $to, string $subject, string $body, string $toName = '', ?string $locale = null, array $attachments = []): bool { return true; }
});
$plugin->ensureSchema();
(new App\Plugins\Archives\ArchivesPlugin($db, $hooks))->ensureSchema();
$controller = new App\Plugins\MobileApi\Controllers\CollectionsController($db, $hooks);
$prefix = 'MOBILECOL_' . bin2hex(random_bytes(6));
$bookIds = []; $archiveIds = []; $articleIds = []; $genreIds = []; $authorIds = []; $userId = 0; $passed = 0; $failure = null;
$pluginStates = $db->query("SELECT id, is_active FROM plugins WHERE name IN ('archives', 'desiderata')")->fetch_all(MYSQLI_ASSOC);
$check = static function (bool $ok, string $label) use (&$passed): void {
    if (!$ok) { throw new RuntimeException($label); }
    $passed++; echo "PASS $label\n";
};
$request = static function (string $method, array $body = [], array $query = [], array $user = []): Psr\Http\Message\ServerRequestInterface {
    return (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest($method, 'http://localhost/api/v1/')
        ->withParsedBody($body)->withQueryParams($query)->withAttribute(App\Plugins\MobileApi\Support\AppAuthMiddleware::ATTR_USER, $user);
};
$payload = static fn($response): array => json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
$uuid = static fn(): string => sprintf('%s-%s-4%s-8%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1), substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));
try {
    $db->query("UPDATE plugins SET is_active = 1 WHERE name IN ('archives', 'desiderata')");
    $repo = new App\Models\BookRepository($db);
    for ($i = 0; $i < 3; $i++) { $bookIds[] = $repo->createBasic(['titolo' => "$prefix book $i", 'is_desiderata' => 1, 'copie_totali' => 0]); }
    $normal = $repo->createBasic(['titolo' => "$prefix normal", 'copie_totali' => 0]); $bookIds[] = $normal;
    $list = $controller->handle('desiderata', $request('GET', query: ['q' => $prefix, 'limit' => '2']), new Slim\Psr7\Response(), 'wanted');
    $data = $payload($list);
    $check(count($data['data']) === 2 && $data['meta']['next_cursor'] === '2', 'wanted records page independently of catalogue holdings');
    $page2 = $payload($controller->handle('desiderata', $request('GET', query: ['q' => $prefix, 'limit' => '2', 'cursor' => '2']), new Slim\Psr7\Response(), 'wanted'));
    $check(count($page2['data']) === 1 && $page2['meta']['next_cursor'] === null, 'wanted pagination reaches the final record');
    $check(!in_array($normal, array_column([...$data['data'], ...$page2['data']], 'id'), true), 'ordinary zero-copy books are not desiderata');
    $detail = $payload($controller->handle('desiderata', $request('GET'), new Slim\Psr7\Response(), 'wanted_detail', $bookIds[0]));
    $check($detail['data']['wanted'] && !isset($detail['data']['availability']), 'wanted detail does not invent lending availability');
    $check($controller->handle('desiderata', $request('GET'), new Slim\Psr7\Response(), 'wanted_detail', $normal)->getStatusCode() === 404, 'ordinary book cannot be opened as a library request');
    $db->query('UPDATE libri SET deleted_at = NOW() WHERE id = ' . $bookIds[2]);
    $check($controller->handle('desiderata', $request('GET'), new Slim\Psr7\Response(), 'wanted_detail', $bookIds[2])->getStatusCode() === 404, 'deleted wanted book is hidden');

    $email = "$prefix@example.invalid"; $password = password_hash('fixture-password-only', PASSWORD_DEFAULT);
    $user = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, email_verificata) VALUES (?, ?, 'Fixture', ?, ?, 'attivo', 1)");
    $card = substr($prefix, -20); $user->bind_param('ssss', $card, $prefix, $email, $password); $user->execute(); $userId = (int) $db->insert_id; $user->close();
    $identity = ['id' => $userId, 'nome' => $prefix, 'cognome' => 'Fixture', 'email' => $email, 'tipo_utente' => 'standard'];
    $offer = ['submission_id' => $uuid(), 'book_id' => $bookIds[0], 'title' => "$prefix submitted", 'consent' => true, 'notes' => 'Good condition'];
    $check($plugin->mobileOffer($request('POST', $offer), new Slim\Psr7\Response())->getStatusCode() === 401, 'anonymous native proposal is refused');
    $check($plugin->mobileOffer($request('POST', array_replace($offer, ['consent' => false]), user: $identity), new Slim\Psr7\Response())->getStatusCode() === 422, 'native proposal requires consent');
    $response = $plugin->mobileOffer($request('POST', $offer + ['donor_email' => 'forged@example.invalid'], user: $identity), new Slim\Psr7\Response());
    $id = $payload($response)['data']['id'];
    $check($response->getStatusCode() === 201, 'native matched proposal is accepted');
    $status = $controller->offerStatus($request('GET', user: $identity), new Slim\Psr7\Response(), $offer['submission_id']);
    $check($status->getStatusCode() === 200 && $payload($status)['data']['id'] === $id, 'own committed proposal is recoverable after a timeout');
    $check($controller->offerStatus($request('GET', user: ['id' => $userId + 1]), new Slim\Psr7\Response(), $offer['submission_id'])->getStatusCode() === 404, 'another account cannot discover proposal identity');
    $check($controller->offerStatus($request('GET'), new Slim\Psr7\Response(), $offer['submission_id'])->getStatusCode() === 401, 'anonymous proposal lookup is refused');
    $check($controller->offerStatus($request('GET', user: $identity), new Slim\Psr7\Response(), 'invalid')->getStatusCode() === 422, 'proposal lookup validates the UUID');

    $stored = $db->query('SELECT * FROM desiderata_offers WHERE id = ' . (int) $id)->fetch_assoc();
    $check($stored['donor_email'] === $email && $stored['title'] === "$prefix book 0", 'verified account and requested title override forged contact/title fields');
    $check((int) $db->query('SELECT COUNT(*) FROM copie WHERE libro_id = ' . $bookIds[0])->fetch_row()[0] === 0, 'proposal creates no inventory copies');
    $again = $plugin->mobileOffer($request('POST', $offer, user: $identity), new Slim\Psr7\Response());
    $check($again->getStatusCode() === 200 && $payload($again)['data']['id'] === $id, 'retry returns the original proposal');
    $check($plugin->mobileOffer($request('POST', array_replace($offer, ['notes' => 'Changed']), user: $identity), new Slim\Psr7\Response())->getStatusCode() === 409, 'UUID reuse with different input is rejected');
    $check($plugin->mobileOffer($request('POST', array_replace($offer, ['submission_id' => $uuid()]), user: $identity), new Slim\Psr7\Response())->getStatusCode() === 429, 'account throttle also applies across devices');
    $db->query('UPDATE desiderata_offers SET created_at = DATE_SUB(NOW(), INTERVAL 61 SECOND) WHERE id = ' . (int) $id);
    $check($plugin->mobileOffer($request('POST', array_replace($offer, ['submission_id' => $uuid(), 'book_id' => $normal]), user: $identity), new Slim\Psr7\Response())->getStatusCode() === 409, 'donation cannot target ordinary inventory');
    $free = array_replace($offer, ['submission_id' => $uuid(), 'book_id' => null]);
    $check($plugin->mobileOffer($request('POST', $free, user: $identity), new Slim\Psr7\Response())->getStatusCode() === 201, 'free proposal is supported without creating a book');

    $changedIdentity = array_replace($identity, ['nome' => 'Renamed fixture']);
    $check($plugin->mobileOffer($request('POST', $offer, user: $changedIdentity), new Slim\Psr7\Response())->getStatusCode() === 200, 'account profile changes do not conflict with an identical donation retry');
    $db->query('UPDATE utenti SET email_verificata = 0 WHERE id = ' . $userId);
    $check($plugin->mobileOffer($request('POST', $offer, user: $identity), new Slim\Psr7\Response())->getStatusCode() === 401, 'unverified account cannot submit or replay a donation');
    $db->query('UPDATE utenti SET email_verificata = 1 WHERE id = ' . $userId);
    $catalog = new App\Plugins\MobileApi\Controllers\CatalogController($db);
    $attachments = json_encode([
        ['url' => '/digital/book.pdf', 'label' => 'PDF edition', 'kind' => 'ebook'],
        ['url' => '/digital/book.epub', 'label' => 'EPUB edition', 'kind' => 'ebook'],
        ['url' => '/digital/review.pdf', 'label' => 'Review', 'kind' => 'supplement'],
        ['url' => '/digital/track1.mp3', 'label' => 'Track 1', 'kind' => 'audio'],
        ['url' => '/digital/track2.mp3', 'label' => 'Track 2', 'kind' => 'audio'],
    ], JSON_THROW_ON_ERROR);
    $stmt = $db->prepare("UPDATE libri SET digital_attachments = ?, file_url = '/digital/book.pdf', audio_url = '/digital/track1.mp3', edizione = '2', luogo_pubblicazione = 'Copenhagen' WHERE id = ?");
    $stmt->bind_param('si', $attachments, $normal); $stmt->execute(); $stmt->close();
    $book = $payload($catalog->bookDetail($request('GET'), new Slim\Psr7\Response(), $normal));
    $check(count($book['data']['digital_attachments'] ?? []) === 5, 'mobile book exposes all ebook editions, related documents and audiobooks');
    $check($book['data']['edition'] === '2' && $book['data']['publication_place'] === 'Copenhagen', 'mobile book retains edition and publication place');
    $check(array_column($book['data']['citations'], 'key') === array_keys(App\Support\CitationStyles::STYLES), 'mobile book exposes every shared citation style including Oxford');
    $name = "$prefix shared author";
    $stmt = $db->prepare('INSERT INTO autori (nome) VALUES (?)'); $stmt->bind_param('s', $name); $stmt->execute(); $authorId = (int) $db->insert_id; $authorIds[] = $authorId; $stmt->close();
    $db->query("INSERT INTO libri_autori (libro_id, autore_id, ruolo) VALUES ($normal, $authorId, 'principale')");
    $bookBeforeRename = $catalog->bookDetail($request('GET'), new Slim\Psr7\Response(), $normal);
    $stmt = $db->prepare('UPDATE autori SET nome = ? WHERE id = ?'); $renamed = "$prefix renamed"; $stmt->bind_param('si', $renamed, $authorId); $stmt->execute(); $stmt->close();
    $bookAfterRename = $catalog->bookDetail($request('GET')->withHeader('If-None-Match', $bookBeforeRename->getHeaderLine('ETag')), new Slim\Psr7\Response(), $normal);
    $check($bookAfterRename->getStatusCode() === 200 && $payload($bookAfterRename)['data']['authors'][0]['name'] === $renamed, 'shared author rename invalidates native detail cache');
    $family = null;
    for ($depth = 0; $depth < 12; $depth++) {
        $name = "$prefix genre $depth"; $stmt = $db->prepare('INSERT INTO generi (nome, parent_id) VALUES (?, ?)'); $stmt->bind_param('si', $name, $family); $stmt->execute(); $family = (int) $db->insert_id; $genreIds[] = $family; $stmt->close();
    }
    $db->query("UPDATE libri SET genere_id = $family WHERE id = $normal");
    $deep = $payload($catalog->search($request('GET', query: ['genre' => (string) $genreIds[0], 'author_id' => (string) $authorId]), new Slim\Psr7\Response()));
    $check(array_column($deep['data'], 'id') === [$normal], 'genre filtering reaches the twelfth level and combines with author identity');
    $deepBook = $payload($catalog->bookDetail($request('GET'), new Slim\Psr7\Response(), $normal));
    $check(count($deepBook['data']['genre_path']) === 12, 'native genre trail retains every ancestor');

    $reference = "$prefix article"; $articleTitle = "$prefix anthology chapter";
    $stmt = $db->prepare("INSERT INTO emeroteca_contributi (reference_key, titolo, contenitore_tipo, contenitore_titolo, contenitore_curatori, contenitore_editore, contenitore_luogo, anno_pubblicazione, lingua, paese, classificazione_schema, classificazione, nota_possesso, genere_id, pubblico, pdf_path, pdf_pubblico, risorsa_url, risorsa_pubblica, keywords) VALUES (?, ?, 'antologia', 'Collected essays', 'Editor, A.', 'Test publisher', 'Copenhagen', 1988, 'dan', 'dk', 'dk5', '33.129', 'Copy only', ?, 1, 'private/article.pdf', 0, 'https://private.example/article', 0, 'Labour; History')");
    $stmt->bind_param('ssi', $reference, $articleTitle, $family); $stmt->execute(); $articleId = (int) $db->insert_id; $articleIds[] = $articleId; $stmt->close();
    $stmt = $db->prepare("INSERT INTO emeroteca_contributi_autori (contributo_id, ordine_credito, autore_id, nome_credito, ruolo) VALUES (?, 0, ?, ?, 'principale')");
    $stmt->bind_param('iis', $articleId, $authorId, $renamed); $stmt->execute(); $stmt->close();
    $module = new App\Plugins\Emeroteca\Modules\MobileModule($db);
    $article = $payload($module->articles($request('GET'), new Slim\Psr7\Response(), $articleId));
    $check($article['data']['contenitore_curatori'] === 'Editor, A.' && $article['data']['contenitore_luogo'] === 'Copenhagen' && $article['data']['lingua'] === 'dan' && $article['data']['classificazione'] === '33.129', 'native analytic record retains anthology, language and classification metadata');
    $check(count($article['data']['citations']) === count(App\Support\CitationStyles::STYLES) && count($article['data']['genre_path']) === 12, 'article citations and full genre trail mirror the book catalogue');
    $check($article['data']['author_credits'][0]['id'] === $authorId, 'articles use the same author identity as books');
    $check($article['data']['pdf_url'] === null && !array_key_exists('risorsa_url', $article['data']) && !isset($article['data']['pdf_path']) && $article['data']['manage_url'] === null, 'private article files and staff management links remain absent for readers');
    $staffArticle = $payload($module->articles($request('GET', user: ['tipo_utente' => 'admin']), new Slim\Psr7\Response(), $articleId));
    $check(str_contains($staffArticle['data']['manage_url'], '/admin/periodicals/articles/' . $articleId), 'staff can reach the existing protected article management page');
    $matched = $payload($module->articles($request('GET', query: ['q' => $prefix, 'author_id' => (string) $authorId, 'genre' => (string) $genreIds[0], 'language' => 'dan']), new Slim\Psr7\Response()));
    $check(array_column($matched['data'], 'id') === [$articleId], 'combined native article filters match shared authors and deeply nested genres');
    $localizedLanguage = $payload($module->articles($request('GET', query: ['q' => $prefix, 'language' => 'Dansk']), new Slim\Psr7\Response()));
    $check(array_column($localizedLanguage['data'], 'id') === [$articleId], 'real Danish book-language facets also find analytic records stored as dan');
    $noMatch = $payload($module->articles($request('GET', query: ['q' => $prefix, 'language' => 'deu']), new Slim\Psr7\Response()));
    $check($noMatch['data'] === [], 'article language facet does not silently return unfiltered records');
    $db->query('UPDATE emeroteca_contributi SET pubblico = 0 WHERE id = ' . $articleId);
    $check($module->articles($request('GET'), new Slim\Psr7\Response(), $articleId)->getStatusCode() === 404, 'unpublished article cannot be opened through the native API');

    foreach ([['fonds', null, -50, 20], ['series', 0, 10, 30], ['item', 1, 1930, 1940]] as $n => [$level, $parentIndex, $start, $end]) {
        $parent = $parentIndex === null ? null : $archiveIds[$parentIndex]; $ref = "$prefix-$n"; $title = "$prefix archive $n";
        $stmt = $db->prepare('INSERT INTO archival_units (parent_id, reference_code, constructed_title, level, date_start, date_end) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssii', $parent, $ref, $title, $level, $start, $end); $stmt->execute(); $archiveIds[] = (int) $db->insert_id; $stmt->close();
    }
    $archiveList = $payload($controller->handle('archives', $request('GET', query: ['q' => $prefix]), new Slim\Psr7\Response(), 'archives'));
    $check(count($archiveList['data']) === 3, 'archive text search spans the hierarchy');
    $filtered = $payload($controller->handle('archives', $request('GET', query: ['q' => $prefix, 'date_from' => '-10', 'date_to' => '0']), new Slim\Psr7\Response(), 'archives'));
    $check(count($filtered['data']) === 1 && $filtered['data'][0]['id'] === $archiveIds[0], 'archive period filter uses overlapping spans including BCE');
    $children = $payload($controller->handle('archives', $request('GET', query: ['parent_id' => (string) $archiveIds[1]]), new Slim\Psr7\Response(), 'archives'));
    $check(count($children['data']) === 1 && $children['data'][0]['id'] === $archiveIds[2], 'archive children can be browsed independently');
    $stmt = $db->prepare("INSERT INTO archival_unit_files (unit_id, file_path, file_mime, original_filename) VALUES (?, ?, ?, ?)");
    foreach ([['/public/archive.pdf', 'application/pdf', 'Document.pdf'], ['/public/archive.jpg', 'image/jpeg', 'Photo.jpg'], ['/public/archive.wav', 'audio/wav', 'Audio.wav'], ['javascript:alert(1)', 'application/pdf', 'Unsafe.pdf']] as [$path, $mime, $label]) {
        $stmt->bind_param('isss', $archiveIds[2], $path, $mime, $label); $stmt->execute();
    }
    $stmt->close();
    $archive = $payload($controller->handle('archives', $request('GET'), new Slim\Psr7\Response(), 'archive', $archiveIds[2]));
    $check(count($archive['data']['documents']) === 3 && array_column($archive['data']['documents'], 'mime') === ['application/pdf', 'image/jpeg', 'audio/wav'], 'archive exposes every public document type while rejecting executable URLs');
    $check(array_reduce($archive['data']['documents'], static fn(bool $ok, array $d): bool => $ok && preg_match('#/archives/' . $archiveIds[2] . '/documents/[1-9][0-9]*$#', (string) $d['url']) === 1, true), 'archive documents link the published-only document route, not the upload path');
    $hasPublished = (int) $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'archival_units' AND COLUMN_NAME = 'published'")->fetch_row()[0] === 1;
    if ($hasPublished) {
        $db->query('UPDATE archival_units SET published = 0 WHERE id = ' . $archiveIds[2]);
        $check($controller->handle('archives', $request('GET'), new Slim\Psr7\Response(), 'archive', $archiveIds[2])->getStatusCode() === 404, 'an unpublished archival record cannot be opened');
        $db->query('UPDATE archival_units SET published = 1 WHERE id = ' . $archiveIds[2]);
    }
    $check(array_column($archive['data']['ancestors'], 'id') === array_slice($archiveIds, 0, 2), 'archive ancestors remain ordered from the root');
    $check(!isset($archive['data']['physical_location']) && !isset($archive['data']['document_path']), 'archive detail exposes public projection instead of raw storage');
    $check($controller->handle('archives', $request('GET', query: ['date_from' => '2000', 'date_to' => '1900']), new Slim\Psr7\Response(), 'archives')->getStatusCode() === 422, 'inverted archive dates are rejected');
    $db->query('UPDATE archival_units SET deleted_at = NOW() WHERE id = ' . $archiveIds[2]);
    $check($controller->handle('archives', $request('GET'), new Slim\Psr7\Response(), 'archive', $archiveIds[2])->getStatusCode() === 404, 'deleted archival record cannot be opened');
    $db->query('UPDATE libri SET search_index = titolo WHERE id = ' . $normal);
    App\Support\Hooks::init($hooks);
    $archivePlugin = new App\Plugins\Archives\ArchivesPlugin($db, $hooks);
    $hooks->addHook('frontend.catalog.archive_results', [$archivePlugin, 'getPublicArchiveResults']);
    $frontend = new App\Controllers\FrontendController();
    $mixed = $payload($frontend->catalogAPI($request('GET', query: ['q' => $prefix]), new Slim\Psr7\Response(), $db));
    if (!str_contains($mixed['html'], "$prefix normal") || !str_contains($mixed['archive_html'], "$prefix archive 0")) { fwrite(STDERR, json_encode(['book_found' => str_contains($mixed['html'], "$prefix normal"), 'archive_found' => str_contains($mixed['archive_html'], "$prefix archive 0"), 'archive_length' => strlen($mixed['archive_html'])]) . "\n"); }
    $check(str_contains($mixed['html'], "$prefix normal") && str_contains($mixed['archive_html'], "$prefix archive 0"), 'browser catalogue exposes archive matches alongside books after AJAX filtering');
    $cleared = $payload($frontend->catalogAPI($request('GET'), new Slim\Psr7\Response(), $db));
    $check($cleared['archive_html'] === '', 'clearing the browser query removes previous archive snippets');

    $handler = new class implements Psr\Http\Server\RequestHandlerInterface {
        public function handle(Psr\Http\Message\ServerRequestInterface $request): Psr\Http\Message\ResponseInterface { throw new RuntimeException('Unauthenticated collection handler was reached'); }
    };
    $middleware = new App\Plugins\MobileApi\Support\AppAuthMiddleware($db, true);
    $check($middleware->process($request('GET', user: $identity), $handler)->getStatusCode() === 401, 'spoofed identity without a bearer token never reaches collection handlers');
    $blocked = new App\Plugins\MobileApi\Support\AppAuthMiddleware($db, false);
    $check($blocked->process($request('GET'), $handler)->getStatusCode() === 403, 'disabled app access also gates the new collections');
    $db->query("UPDATE plugins SET is_active = 0 WHERE name = 'desiderata'");
    $check($controller->handle('desiderata', $request('GET'), new Slim\Psr7\Response(), 'wanted')->getStatusCode() === 404, 'disabled desiderata expose no records');
} catch (Throwable $e) { $failure = $e; }
finally {
    $cleanup = static function (string $sql) use ($db, &$failure): void {
        try { $db->query($sql); } catch (Throwable $e) { $failure ??= $e; fwrite(STDERR, 'Cleanup failed: ' . $e->getMessage() . "\n"); }
    };
    if ($userId) { $cleanup('DELETE FROM desiderata_offers WHERE mobile_user_id = ' . $userId); $cleanup('DELETE FROM utenti WHERE id = ' . $userId); }
    foreach ($articleIds as $id) { $cleanup('DELETE FROM emeroteca_contributi WHERE id = ' . $id); }
    if ($bookIds) { $list = implode(',', $bookIds); $cleanup("DELETE FROM log_modifiche WHERE tabella = 'libri' AND record_id IN ($list)"); $cleanup("DELETE FROM libri WHERE id IN ($list)"); }
    foreach ($authorIds as $id) { $cleanup('DELETE FROM autori WHERE id = ' . $id); }
    foreach (array_reverse($genreIds) as $id) { $cleanup('DELETE FROM generi WHERE id = ' . $id); }
    foreach (array_reverse($archiveIds) as $id) { $cleanup('DELETE FROM archival_units WHERE id = ' . $id); }
    foreach ($pluginStates as $state) { $cleanup('UPDATE plugins SET is_active = ' . (int) $state['is_active'] . ' WHERE id = ' . (int) $state['id']); }
    try {
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix) . '%';
        $stmt = $db->prepare("DELETE FROM admin_notifications WHERE title LIKE ? ESCAPE '!' OR message LIKE ? ESCAPE '!'");
        $stmt->bind_param('ss', $like, $like); $stmt->execute(); $stmt->close();
        App\Support\ContentCache::booksChanged();
    } catch (Throwable $e) { $failure ??= $e; }
}

if ($failure) { fwrite(STDERR, 'FAIL ' . $failure->getMessage() . "\n"); exit(1); }
echo "$passed collection/mobile donation checks passed\n";
