<?php
declare(strict_types=1);
/** Real DB regression checks. Only uniquely named test records are removed. */
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/storage/plugins/desiderata/wrapper.php';

final class DesiderataTestDb extends mysqli
{
    public bool $failReceipt = false;
    public bool $failCopy = false;
    public function prepare(string $query): mysqli_stmt|false
    {
        if ($this->failCopy && preg_match('/INSERT\s+INTO\s+copie/i', $query)) {
            throw new RuntimeException('Injected physical copy failure');
        }
        if ($this->failReceipt && str_contains($query, "SET status='received'")) {
            throw new RuntimeException('Injected receipt failure after copy creation');
        }
        return parent::prepare($query);
    }
}
$env = Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__) . '/.env'));
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new DesiderataTestDb($env['DB_HOST'] ?? 'localhost', getenv('E2E_DB_USER') ?: $env['DB_USER'], getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? $env['DB_PASSWORD'] ?? ''), getenv('E2E_DB_NAME') ?: $env['DB_NAME'], (int)($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
$db->set_charset('utf8mb4');
$plugin = new DesiderataPlugin($db, new App\Support\HookManager($db));
// Capture mail in the parent and receipt workers: tests must not mail real operators.
$plugin->setEmailService(new class($db) extends App\Support\EmailService {
    public function sendEmail(string $to, string $subject, string $body, string $toName = '', ?string $locale = null, array $attachments = []): bool
    {
        return true;
    }
});
// Two independent processes exercise the actual row-lock/idempotency contract.
if (($argv[1] ?? '') === '--receive-worker') {
    echo "READY\n"; fflush(STDOUT); fgets(STDIN);
    $_SESSION = [];
    $rq = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/admin/desiderata')->withParsedBody(['action'=>'received']);
    $r = $plugin->manage($rq, new Slim\Psr7\Response(), (int)$argv[2]);
    echo $r->getStatusCode() . "\n";
    exit;
}
$plugin->ensureSchema();
$prefix = 'DWTEST_' . bin2hex(random_bytes(6));
$ids = []; $offerIds = []; $passed = 0;
$check = static function (bool $ok, string $message) use (&$passed): void {
    if (!$ok) throw new RuntimeException($message);
    echo 'PASS ' . $message . "\n"; $passed++;
};
$scalar = static fn(string $sql) => $db->query($sql)->fetch_row()[0];
$repo = new App\Models\BookRepository($db);
$create = static function (string $suffix, bool $wanted) use ($db, $plugin, $repo, $prefix, &$ids): int {
    $fields = $plugin->prepareBook(['titolo' => $prefix . $suffix, 'copie_totali' => 9], ['desiderata_form' => '1', 'is_desiderata' => $wanted ? '1' : '0'], null);
    if (!$wanted) $fields['copie_totali'] = 0;
    $id = $repo->createBasic($fields); $ids[] = $id; return $id;
};
$request = static fn(array $body) => (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/desiderata/offers')->withParsedBody($body);
$offerMethod = new ReflectionMethod($plugin, 'offer');
$manageMethod = new ReflectionMethod($plugin, 'manage');
$payload = ['donor_name'=>'Test donor', 'donor_email'=>'test@example.invalid', 'title'=>$prefix, 'consent'=>'1'];

// The id of the offer just created has to be read back from the table, never
// from mysqli::$insert_id. offer() notifies the operators right after the
// INSERT, and that notification is written on THIS connection, so insert_id
// afterwards names the admin_notifications row instead. Believing it would hand
// manage() the wrong id — and the same value feeds the cleanup DELETE at the end
// of this file, so once a notification id collided with a real offer id the
// suite would delete a stranger's proposal. Scoped to this run's unique prefix
// so nothing another process inserts can be picked up.
// Matched against the prefix rather than the exact title: an offer bound to a
// book stores that book's title ("<prefix>_wanted"), a free-form one stores what
// the donor typed ("<prefix>"). Both start with this run's prefix.
$lastOfferId = static function () use ($db, $prefix): int {
    $like = $prefix . '%';
    $stmt = $db->prepare('SELECT MAX(id) FROM desiderata_offers WHERE title LIKE ?');
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $id = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();
    if ($id === 0) { throw new RuntimeException('No offer found for prefix ' . $prefix); }
    return $id;
};
// The run's own failure, held rather than rethrown so the cleanup below gets to
// run and the exit code is this suite's own instead of an uncaught-error stack
// trace — the shape the sibling desiderata suites already use.
$fatalError = null;
// Every cleanup statement that threw, with the statement that threw it. Under
// MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT a failed query() is an exception,
// and this file had no catch at all: an exception raised inside the finally was
// the run's ENTIRE result, abandoning every later delete and erasing whatever
// the run was about to report. Failures are collected here instead and reported
// after the primary result rather than instead of it.
$cleanupErrors = [];
try {
    $id = $create('_wanted', true); $normal = $create('_zero', false);
    $check((int)$scalar("SELECT copie_totali FROM libri WHERE id=$id") === 0, 'checkbox overrides forged initial copies on the server');
    $check((int)$scalar("SELECT COUNT(*) FROM copie WHERE libro_id=$id") === 0, 'desiderata has no physical copies');
    $check((int)$scalar("SELECT is_desiderata FROM libri WHERE id=$id") === 1, 'explicit flag persisted with the bibliographic record');
    $found = array_column($plugin->wanted($prefix), 'id');
    $check(in_array($id, $found) && !in_array($normal, $found), 'requests exclude ordinary zero-copy books');
    $visibility = App\Support\BookVisibility::catalogue($db);
    $check((int)$scalar("SELECT COUNT(*) FROM libri WHERE id=$id AND $visibility") === 0, 'catalogue excludes requested books');
    $check((int)$scalar("SELECT COUNT(*) FROM libri WHERE id=$normal AND $visibility") === 1, 'catalogue retains ordinary unavailable books');
    foreach ([['donor_email'=>'invalid'], ['title'=>[]], ['consent'=>''], ['notes'=>str_repeat('a',2001)]] as $invalid) {
        try { DesiderataPlugin::validateOffer(array_replace($payload,$invalid)); throw new RuntimeException('Invalid input accepted'); }
        catch (InvalidArgumentException) { $check(true,'reject malformed/invalid donation fields'); }
    }
    $_SESSION = [];
    $response = $offerMethod->invoke($plugin, $request($payload + ['book_id'=>(string)$id]), new Slim\Psr7\Response());
    $check($response->getStatusCode()===303, 'matched donation is accepted');
    $offerId = $lastOfferId(); $offerIds[]=$offerId;
    $check((int)$scalar("SELECT COUNT(*) FROM copie WHERE libro_id=$id")===0, 'proposal never creates copies');
    $response=$manageMethod->invoke($plugin,$request(['action'=>'accepted']),new Slim\Psr7\Response(),$offerId);
    $check($response->getStatusCode()===303 && (int)$scalar("SELECT COUNT(*) FROM copie WHERE libro_id=$id")===0, 'acceptance waits for actual delivery');
    $db->failReceipt=true;
    ob_start();
    $response=$manageMethod->invoke($plugin,$request(['action'=>'received']),new Slim\Psr7\Response(),$offerId);
    ob_end_clean();
    $db->failReceipt=false;
    $check($response->getStatusCode()===422 && (int)$scalar("SELECT COUNT(*) FROM copie WHERE libro_id=$id")===0 && (int)$scalar("SELECT is_desiderata FROM libri WHERE id=$id")===1, 'failed receipt rolls back copy, flag and status together');
    $response=$manageMethod->invoke($plugin,$request(['action'=>'received']),new Slim\Psr7\Response(),$offerId);
    $check($response->getStatusCode()===303 && (int)$scalar("SELECT COUNT(*) FROM copie WHERE libro_id=$id")===1, 'receipt creates exactly one copy');
    $check((int)$scalar("SELECT is_desiderata FROM libri WHERE id=$id")===0 && (int)$scalar("SELECT copie_disponibili FROM libri WHERE id=$id")===1, 'receipt converts the same book to available holdings');
    ob_start(); $response=$manageMethod->invoke($plugin,$request(['action'=>'received']),new Slim\Psr7\Response(),$offerId); ob_end_clean();
    $check($response->getStatusCode()===422 && (int)$scalar("SELECT COUNT(*) FROM copie WHERE libro_id=$id")===1, 'replayed receipt cannot duplicate a copy');
    $ordinary=$create('_ordinary_receipt',true);
    (new App\Models\CopyRepository($db))->createWithAllocatedInventoryCode($ordinary,$prefix);
    $check((new App\Support\DataIntegrity($db))->recalculateBookAvailability($ordinary), 'normal copy management reconciles successfully');
    $check((int)$scalar("SELECT is_desiderata FROM libri WHERE id=$ordinary")===0, 'ordinary copy creation also automatically fulfils desiderata');
    $fields=$plugin->prepareBook([],['desiderata_form'=>'1','is_desiderata'=>'1'],$ordinary);
    $check($fields['is_desiderata']===0,'existing physical holdings cannot become a request');
    $db->query("DELETE FROM copie WHERE libro_id=$ordinary");
    (new App\Support\DataIntegrity($db))->recalculateBookAvailability($ordinary);
    $check((int)$scalar("SELECT is_desiderata FROM libri WHERE id=$ordinary")===0,'removing a copy never silently recreates a request');
    $_SESSION=[];
    $before=(int)$scalar('SELECT COUNT(*) FROM libri');
    $response=$offerMethod->invoke($plugin,$request($payload+['book_id'=>'']),new Slim\Psr7\Response()); $offerIds[]=$lastOfferId();
    $check($response->getStatusCode()===303 && (int)$scalar('SELECT COUNT(*) FROM libri')===$before,'unsolicited donation stays separate from bibliographic records');
    $freeOffer = $offerIds[count($offerIds)-1];
    ob_start(); $response=$manageMethod->invoke($plugin,$request(['action'=>'received']),new Slim\Psr7\Response(),$freeOffer); ob_end_clean();
    $check($response->getStatusCode()===422, 'free offer cannot be received without a bibliographic record');
    $response=$manageMethod->invoke($plugin,$request(['action'=>'received','received_book_id'=>(string)$normal]),new Slim\Psr7\Response(),$freeOffer);
    $check($response->getStatusCode()===303 && (int)$scalar("SELECT COUNT(*) FROM copie WHERE libro_id=$normal")===1, 'free offer is received into the explicitly selected record');
    $check((int)$scalar("SELECT received_book_id FROM desiderata_offers WHERE id=$freeOffer")===$normal, 'receipt preserves the link to the acquired book');

    // Validation boundaries (characters rather than bytes), trimming and hostile types.
    $limits = ['donor_name'=>150, 'title'=>255, 'author'=>255, 'publisher'=>255, 'isbn'=>20, 'notes'=>2000];
    foreach ($limits as $key=>$limit) {
        $boundary = array_replace($payload, [$key=>str_repeat('è',$limit)]);
        $check(mb_strlen(DesiderataPlugin::validateOffer($boundary)[$key])===$limit, "$key accepts its Unicode boundary");
        foreach ([str_repeat('è',$limit+1), ['unexpected'], new stdClass()] as $bad) {
            try { DesiderataPlugin::validateOffer(array_replace($payload,[$key=>$bad])); throw new RuntimeException("Invalid $key accepted"); }
            catch (InvalidArgumentException) { $check(true,"$key rejects oversized or non-string input"); }
        }
    }
    foreach (['donor_name','title','donor_email'] as $key) {
        try { DesiderataPlugin::validateOffer(array_replace($payload,[$key=>'   '])); throw new RuntimeException('Whitespace accepted'); }
        catch (InvalidArgumentException) { $check(true,"$key rejects whitespace-only input"); }
    }
    $check(DesiderataPlugin::validateOffer(array_replace($payload,['title'=>'  Titolo  ']))['title']==='Titolo','text is trimmed');
    $check($plugin->prepareBook(['copie_totali'=>4],[],null)===['copie_totali'=>4], 'non-form imports are unchanged');
    $check($plugin->prepareBook(['copie_totali'=>4],['desiderata_form'=>'1'],null)['copie_totali']===4,'unchecked request leaves initial copies intact');
    $check($plugin->prepareBook([],['desiderata_form'=>'1','is_desiderata'=>'1'],$id)['is_desiderata']===0,'physical copies prohibit reflagging');

    $open = $create('_still_open',true);
    $deleted = $create('_deleted',true); $db->query("UPDATE libri SET deleted_at=NOW() WHERE id=$deleted");
    $stale = $create('_stale',true);
    (new App\Models\CopyRepository($db))->createWithAllocatedInventoryCode($stale,$prefix.'stale');
    $wantedIds = array_column($plugin->wanted($prefix,100),'id');
    $check(in_array($open,$wantedIds) && !in_array($deleted,$wantedIds) && !in_array($stale,$wantedIds),'search excludes deleted and already-held requests even before reconciliation');
    $queryRequest = static fn(mixed $term) => (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET','/desiderata/search')->withQueryParams(['q'=>$term]);
    foreach (['', 'a', 'ab', '  ab  ', str_repeat('a',121), ['bad']] as $term) {
        $r=$plugin->search($queryRequest($term),new Slim\Psr7\Response());
        $check($r->getStatusCode()===200 && (string)$r->getBody()==='[]','short, oversized or malformed search is empty');
    }
    $r=$plugin->search($queryRequest($prefix),new Slim\Psr7\Response());
    $rows=json_decode((string)$r->getBody(),true,512,JSON_THROW_ON_ERROR);
    $check(in_array($open,array_column($rows,'id')),'AJAX returns matching requests');
    $check(!str_contains((string)$r->getBody(),'donor_email') && $r->getHeaderLine('Cache-Control')==='no-store','AJAX does not disclose donors or cache mutable results');
    $special=$create('_literal%_!',true);
    $check(array_column($plugin->wanted($prefix.'_literal%_!'),'id')===[$special],'LIKE metacharacters are literal');
    $check($plugin->wanted("' OR 1=1 --")===[],'SQL-looking search remains data');
    for($i=0;$i<31;$i++) $create('_bounded_'.$i,true);
    $r=$plugin->search($queryRequest($prefix.'_bounded'),new Slim\Psr7\Response());
    $check(count(json_decode((string)$r->getBody(),true))===30,'AJAX results are bounded at thirty');
    $check(count($plugin->wanted($prefix.'_bounded'))===12,'homepage results are bounded at twelve');

    // Every rejected proposal must leave the database unchanged and retain safe form values.
    foreach ([(string)$normal,(string)$deleted,(string)$stale,'0','-1','1.5','9999999999999999999999999999',['bad']] as $badId) {
        $_SESSION=[]; $before=(int)$scalar('SELECT COUNT(*) FROM desiderata_offers');
        $r=$plugin->offer($request($payload+['book_id'=>$badId]),new Slim\Psr7\Response());
        $check($r->getStatusCode()===422 && (int)$scalar('SELECT COUNT(*) FROM desiderata_offers')===$before,'invalid, deleted, held and non-request book IDs cannot receive matched offers');
    }
    // A proposal aimed at a book that stopped being wanted is told it can still
    // be offered as a separate donation. That promise is only true if the form
    // comes back with the title editable — which it does by dropping book_id,
    // since $lockTitle in offer-form.php is computed from it SERVER-side. The
    // only control that released the lock used to be a JavaScript button, so
    // until this the message was true for visitors running scripts and a dead
    // end for everyone else. Asserted on the rendered HTML rather than on the
    // input array, because the readonly attribute is the thing the donor meets.
    $_SESSION=[];
    $r=$plugin->offer($request($payload+['book_id'=>(string)$normal,'notes'=>'Copia in ottimo stato']),new Slim\Psr7\Response());
    $body=(string)$r->getBody();
    $check($r->getStatusCode()===422,'a proposal for a book that is no longer wanted is refused');
    $check(!str_contains($body,' readonly'),'and the title comes back editable, so the donor can act on what the message offers');
    $check(str_contains($body,'name="book_id" id="donation-book-id" value=""'),'the stale book binding is dropped');
    $check(str_contains($body,'Test donor') && str_contains($body,'test@example.invalid') && str_contains($body,'Copia in ottimo stato'),
        'while everything else the donor typed survives the refusal');

    $_SESSION=[];
    $r=$plugin->offer($request($payload+['website'=>'https://spam.invalid']),new Slim\Psr7\Response());
    $check($r->getStatusCode()===422,'honeypot rejects automated submissions');
    $_SESSION=['desiderata_last_offer'=>time()];
    $r=$plugin->offer($request($payload+['book_id'=>'']),new Slim\Psr7\Response());
    $check($r->getStatusCode()===429,'immediate duplicate submission is throttled');
    $_SESSION=[];
    $r=$plugin->offer($request(array_replace($payload,['title'=>'<script>alert(1)</script>','donor_email'=>'invalid'])),new Slim\Psr7\Response());
    $html=(string)$r->getBody();
    $check($r->getStatusCode()===422 && !str_contains($html,'value="<script>') && str_contains($html,'&lt;script&gt;'),'validation errors retain input with HTML escaping');

    $makeOffer = static function(int $book) use($plugin,$request,$payload,$db,$lastOfferId,&$offerIds): int {
        $_SESSION=[];
        $r=$plugin->offer($request($payload+['book_id'=>(string)$book]),new Slim\Psr7\Response());
        if($r->getStatusCode()!==303) throw new RuntimeException('Fixture offer rejected');
        $offer=$lastOfferId(); $offerIds[]=$offer; return $offer;
    };
    $rejected=$makeOffer($open);
    $r=$plugin->manage($request(['action'=>'rejected']),new Slim\Psr7\Response(),$rejected);
    $check($r->getStatusCode()===303 && (int)$scalar("SELECT COUNT(*) FROM copie WHERE libro_id=$open")===0,'rejecting an offer creates no copies');
    foreach (['received','accepted','rejected','unknown',['bad']] as $action) {
        $r=$plugin->manage($request(['action'=>$action]),new Slim\Psr7\Response(),$rejected);
        $check($r->getStatusCode()===422,'closed proposals and invalid actions cannot mutate state');
    }
    $pending=$makeOffer($open);
    $db->failCopy=true;
    $r=$plugin->manage($request(['action'=>'received']),new Slim\Psr7\Response(),$pending); $db->failCopy=false;
    $check($r->getStatusCode()===422 && $scalar("SELECT status FROM desiderata_offers WHERE id=$pending")==='pending' && (int)$scalar("SELECT COUNT(*) FROM copie WHERE libro_id=$open")===0,'physical insert failure rolls back the receipt');
    $db->query("UPDATE libri SET deleted_at=NOW() WHERE id=$open");
    $r=$plugin->manage($request(['action'=>'received']),new Slim\Psr7\Response(),$pending);
    $check($r->getStatusCode()===422 && (int)$scalar("SELECT COUNT(*) FROM copie WHERE libro_id=$open")===0,'book deleted after the offer cannot be acquired');
    $db->query("UPDATE libri SET deleted_at=NULL WHERE id=$open");
    $r=$plugin->manage($request(['action'=>'received']),new Slim\Psr7\Response(),$pending);
    $check($r->getStatusCode()===303,'restored book can subsequently be received');
    $check(!in_array($open,array_column($plugin->wanted($prefix,100),'id')),'received request disappears from public search');
    $check((int)$scalar("SELECT COUNT(*) FROM libri WHERE id=$open AND $visibility")===1,'received book re-enters the ordinary catalogue');


    $raceBook=$create('_concurrent',true); $raceOffer=$makeOffer($raceBook);
    $workers=[];
    try {
        for($i=0;$i<2;$i++) {
            $process=proc_open([PHP_BINARY,__FILE__,'--receive-worker',(string)$raceOffer],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process)) throw new RuntimeException('Cannot start concurrency worker');
            $workers[]=[$process,$pipes];
            if(trim((string)fgets($pipes[1]))!=='READY') throw new RuntimeException('Worker did not start');
        }
        foreach($workers as [$process,$pipes]) { fwrite($pipes[0],"go\n"); fclose($pipes[0]); }
        $statuses=[];
        foreach($workers as [$process,$pipes]) {
            $statuses[]=(int)trim(stream_get_contents($pipes[1]));
            $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            if(proc_close($process)!==0) throw new RuntimeException('Concurrent worker failed: '.$errors);
        }
        $workers=[]; sort($statuses);
        $check($statuses===[303,422],'simultaneous receipts allow exactly one success');
        $check((int)$scalar("SELECT COUNT(*) FROM copie WHERE libro_id=$raceBook")===1,'simultaneous receipts produce exactly one physical copy');
    } finally {
        foreach($workers as [$process,$pipes]) {
            foreach($pipes as $pipe) if(is_resource($pipe)) fclose($pipe);
            if(is_resource($process)) { proc_terminate($process); proc_close($process); }
        }
    }

    // Each admin list owns its cursor. A single shared 'page' made the
    // proposals pager silently advance the requested-books list as well, so
    // clicking Successiva under one list carried unread items of the other out
    // of sight. Asserted on the RENDERED HTML because the defect is a
    // controller/view contract: renaming one without the other makes both navs
    // inert instead of wrong.
    $adminRequest = static fn(array $query) => (new Slim\Psr7\Factory\ServerRequestFactory())
        ->createServerRequest('GET', '/admin/desiderata')->withQueryParams($query);
    $_SESSION = ['user' => ['id' => 1, 'tipo_utente' => 'admin']];
    $paged = $create('_paging_marker', true);
    $html = (string) $plugin->admin($adminRequest(['offers_page' => '2']), new Slim\Psr7\Response())->getBody();
    $check(str_contains($html, $prefix . '_paging_marker'), 'paging the proposals list leaves the requested books in place');
    $check(str_contains($html, 'offers_page=1') || str_contains($html, 'offers_page'), 'the proposals pager emits its own scoped parameter');
    $check(!str_contains($html, '?page=') && !str_contains($html, '&page='), 'the ambiguous shared page parameter is gone');
    $check(str_contains($html, 'id="requested-books"') && str_contains($html, 'id="donation-offers"'), 'each list has an anchor to return to');
    $html = (string) $plugin->admin($adminRequest(['books_page' => '2']), new Slim\Psr7\Response())->getBody();
    $check(!str_contains($html, $prefix . '_paging_marker'), 'paging the requested books actually moves that list');
    $html = (string) $plugin->admin($adminRequest(['books_page' => '500']), new Slim\Psr7\Response())->getBody();
    $check(str_contains($html, 'Nessuna altra richiesta oltre questa pagina.'), 'an over-run page says so instead of claiming there are no requests at all');
    $_SESSION = [];

    // CSV round trip ACROSS installations, which is where a request is lost.
    // Re-importing an export into the SAME catalogue proves nothing: the row
    // still carries its id, so the importer takes the update path, which never
    // touches copies and stays green with the defect present. The destructive
    // case is a catalogue that does not hold the book — no id, insert path —
    // because there the exported copie_totali of 0 is falsy to !empty(), is read
    // back as 1, survives the "< 1 becomes 1" clamp and makes the importer
    // fabricate a physical copy with an allocated inventory code. A title the
    // library merely wants arrives as an owned, lendable holding, and the next
    // availability recalculation clears the flag for good.
    $roundTrip = $create('_roundtrip', true);
    $exported = (string) (new App\Controllers\LibriController())->exportCsv(
        (new Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('GET', '/admin/libri/export')
            ->withQueryParams(['ids' => (string) $roundTrip]),
        new Slim\Psr7\Response(),
        $db
    )->getBody();
    $reader = App\Support\Csv::readerFromString($exported, ';');
    $exportHeaders = array_values((array) $reader->nth(0));
    $exportRow = array_values((array) $reader->nth(1));
    $cell = static fn(string $column) => (string) ($exportRow[array_search($column, $exportHeaders, true)] ?? '');
    $check(in_array('is_desiderata', $exportHeaders, true), 'the standard export carries the request flag where the column exists');
    $check($cell('is_desiderata') === '1' && $cell('titolo') === $prefix . '_roundtrip', 'the exported row marks the wanted title as a request');
    $check($cell('copie_totali') === '0', 'the exported request declares zero copies');

    // Spreadsheet editors drop the id column routinely, and a second
    // installation has no row under it either: both land on the insert path.
    $idColumn = array_search('id', $exportHeaders, true);
    unset($exportHeaders[$idColumn], $exportRow[$idColumn]);
    $exportHeaders = array_values($exportHeaders);
    $exportRow = array_values($exportRow);

    $importer = new App\Controllers\CsvImportController();
    $invokeImporter = static fn(string $method, mixed ...$args) => (new ReflectionMethod($importer, $method))->invoke($importer, ...$args);
    $row = [];
    foreach ($invokeImporter('mapColumnHeaders', $exportHeaders) as $index => $canonical) {
        if (array_key_exists($canonical, $row) && trim((string) $row[$canonical]) !== '') continue;
        $row[$canonical] = $exportRow[$index] ?? '';
    }
    $parsed = $invokeImporter('parseCsvRow', $row);
    $check($parsed['is_desiderata'] === true, 'the importer recognises the exported flag column');
    $check($parsed['copie_totali'] === 1, 'the exported zero copy count is falsy and comes back as one, so the flag alone must suppress copies');
    $existing = $invokeImporter('findExistingBook', $db, $parsed);
    $check($existing === null, 'without the id column the row reaches a catalogue that does not hold it, so the insert path runs');
    $db->begin_transaction();
    try {
        $result = $invokeImporter('upsertBook', $db, $parsed, null, null, [], $existing);
        $db->commit();
    } catch (\Throwable $e) { $db->rollback(); throw $e; }
    $migrated = (int) $result['id']; $ids[] = $migrated;
    $check($result['action'] === 'created', 'the round trip creates a new record rather than updating the source');
    $check((int) $scalar("SELECT COUNT(*) FROM copie WHERE libro_id=$migrated") === 0, 'importing a request fabricates no physical copy');
    $check((int) $scalar("SELECT is_desiderata FROM libri WHERE id=$migrated") === 1, 'the request survives the migration into another catalogue');
    $check((int) $scalar("SELECT copie_totali FROM libri WHERE id=$migrated") === 0 && (int) $scalar("SELECT copie_disponibili FROM libri WHERE id=$migrated") === 0, 'the migrated request still owns nothing');
    $check((int) $scalar("SELECT COUNT(*) FROM libri WHERE id=$migrated AND $visibility") === 0, 'the migrated request stays out of the ordinary catalogue');
    $check(in_array($migrated, array_column($plugin->wanted($prefix . '_roundtrip', 100), 'id'), true), 'the migrated request appears among the requested books');

    // Exercise the real registered HTTP routes and middleware, not just methods.
    $app=Slim\Factory\AppFactory::create(); $app->addBodyParsingMiddleware(); $plugin->registerRoutes($app);
    $oldBypass=$_ENV['PINAKES_E2E_BYPASS_RATE_LIMIT'] ?? null;
    $_ENV['PINAKES_E2E_BYPASS_RATE_LIMIT']='1';
    try {
        // Two shapes, and they are answered differently ON PURPOSE.
        //
        // A post with NO token and NO session is the sessionless first contact:
        // the donation form also lives on '/' and on a book page, which are
        // served without a session because they are edge-cacheable, so with
        // scripting off nothing can have minted a token yet. That is answered by
        // handing the form back with a real token, not by a bare "Sessione
        // Scaduta" — and it must still write nothing.
        $before=(int)$scalar('SELECT COUNT(*) FROM desiderata_offers');
        $_SESSION=[];
        $r=$app->handle($request($payload));
        $check($r->getStatusCode()===200 && str_contains((string)$r->getBody(),'id="desiderata-offer"'),'a sessionless post is answered with the form again, carrying a token');
        $check((int)$scalar('SELECT COUNT(*) FROM desiderata_offers')===$before,'and it accepts nothing: no proposal is recorded');

        // A WRONG token, on a session that holds a different one, is an ordinary
        // CSRF failure and must stay refused. This is the half that proves the
        // branch above did not soften the guard.
        $_SESSION=['csrf_token'=>'a-real-session-token','csrf_token_time'=>time()];
        $r=$app->handle($request($payload+['csrf_token'=>'deadbeef-0000-0000']));
        $check($r->getStatusCode()===403,'a wrong CSRF token is still rejected by middleware');
        $check((int)$scalar('SELECT COUNT(*) FROM desiderata_offers')===$before,'and it too writes nothing');
        $_SESSION=[];
        $factory=new Slim\Psr7\Factory\ServerRequestFactory();
        foreach(['/admin/desiderata','/admin/desiderata/books?q=abc'] as $path) {
            $r=$app->handle($factory->createServerRequest('GET',$path));
            $check($r->getStatusCode()===302,'anonymous visitors cannot access administrative routes');
        }
        $r=$app->handle($factory->createServerRequest('POST','/admin/desiderata/offers/'.$raceOffer)->withParsedBody(['action'=>'received']));
        $check($r->getStatusCode()===302,'anonymous visitors cannot receive donations');
        $_SESSION=['user'=>['id'=>0,'tipo_utente'=>'standard']];
        $r=$app->handle($factory->createServerRequest('POST','/admin/desiderata/offers/'.$raceOffer)->withParsedBody(['action'=>'received']));
        $check($r->getStatusCode()===302 && $r->getHeaderLine('Location')===\App\Support\RouteTranslator::route('profile') && (int)$scalar("SELECT COUNT(*) FROM copie WHERE libro_id=$raceBook")===1,'ordinary readers cannot receive donations');
        $_SESSION=[];
        $r=$app->handle($factory->createServerRequest('GET','/desiderata/search')->withQueryParams(['q'=>$prefix]));
        $check($r->getStatusCode()===200 && is_array(json_decode((string)$r->getBody(),true)),'registered AJAX route is callable in Slim');
        $r=$app->handle($factory->createServerRequest('GET','/desiderata'));
        $check($r->getStatusCode()===200 && str_contains((string)$r->getBody(),'id="desiderata-offer"'),'registered public page renders through the application layout');
    } finally {
        if($oldBypass===null) unset($_ENV['PINAKES_E2E_BYPASS_RATE_LIMIT']); else $_ENV['PINAKES_E2E_BYPASS_RATE_LIMIT']=$oldBypass;
    }

} catch (\Throwable $thrown) {
    $fatalError = $thrown;
} finally {
    $db->failReceipt=false; $db->failCopy=false;
    // One statement, one attempt, never a lost successor: a row this suite
    // cannot delete must not stop it deleting the rest. \Throwable, not
    // mysqli_sql_exception: a failed prepare() returns false and the
    // ->bind_param() on it is a TypeError, which a mysqli-only catch misses.
    $guard = static function (string $what, callable $statement) use (&$cleanupErrors): void {
        try {
            $statement();
        } catch (\Throwable $error) {
            $cleanupErrors[] = $what . "\n      " . $error->getMessage();
        }
    };
    $sweepSql = static function (string $sql) use ($db, $guard): void {
        $guard($sql, static function () use ($db, $sql): void { $db->query($sql); });
    };
    // Swept by prefix, not only by the ids this run recorded: a run in which the
    // code accepts something the suite expected it to refuse writes proposals
    // nobody tracked, and an id-only cleanup leaves them sitting in the
    // operator's real backlog. The notifications go too — offer() has notified
    // the operators since the bell was wired, so every offer here left a row
    // behind in admin_notifications that nothing removed.
    if($offerIds) $sweepSql('DELETE FROM desiderata_offers WHERE id IN ('.implode(',',$offerIds).')');
    // ESCAPE is not decoration: the prefix contains an underscore, which LIKE
    // treats as "any single character" — unescaped, DWTEST_abc also matches
    // DWTESTXabc, and this statement deletes rows.
    $esc = str_replace(['!','%','_'], ['!!','!%','!_'], $prefix);
    $guard('DELETE FROM desiderata_offers WHERE title LIKE <prefix>', static function () use ($db, $esc): void {
        $sweep = $db->prepare("DELETE FROM desiderata_offers WHERE title LIKE ? ESCAPE '!'");
        $starts = $esc.'%'; $sweep->bind_param('s',$starts); $sweep->execute(); $sweep->close();
    });
    // The notification title reads "Nuova proposta di donazione: <titolo>", so
    // the prefix sits in the middle rather than at the start.
    $guard('DELETE FROM admin_notifications WHERE title/message LIKE <prefix>', static function () use ($db, $esc): void {
        $notes = $db->prepare("DELETE FROM admin_notifications WHERE title LIKE ? ESCAPE '!' OR message LIKE ? ESCAPE '!'");
        $anywhere = '%'.$esc.'%'; $notes->bind_param('ss',$anywhere,$anywhere); $notes->execute(); $notes->close();
    });
    if($ids) {
        $list = implode(',',$ids);
        $sweepSql('DELETE FROM copie WHERE libro_id IN ('.$list.')');
        // createBasic() writes an audit row per fixture book. Deleting the book
        // and not this left an orphan behind on every single run — 45 of them
        // by the time it was noticed — each naming a DWTEST_ title that no
        // longer exists. The sibling suites already delete it; this one didn't.
        $sweepSql("DELETE FROM log_modifiche WHERE tabella = 'libri' AND record_id IN (".$list.')');
        $sweepSql('DELETE FROM libri WHERE id IN ('.$list.')');
    }
    $guard('invalidating the catalogue cache', static function (): void { App\Support\ContentCache::booksChanged(); });
}

// Reported BEFORE the primary result so the primary result is what the reader
// is left looking at — a teardown problem is a real problem, but it is never
// the explanation of the run.
if ($cleanupErrors !== []) {
    fwrite(STDERR, "\nCLEANUP FAILED — fixture rows may still be in the database:\n");
    foreach ($cleanupErrors as $message) {
        fwrite(STDERR, "    {$message}\n");
    }
}

if ($fatalError !== null) {
    fwrite(STDERR, "\nFAIL: {$fatalError->getMessage()}\n");
    exit(1);
}

echo "$passed checks passed\n";
if ($cleanupErrors !== []) {
    // On its own this still has to fail the run: a cleanup that quietly gives
    // up is how the demo data in this database would get polluted unnoticed.
    fwrite(STDERR, "FAIL: the cleanup reported above did not complete.\n");
    exit(1);
}
exit(0);
