<?php
declare(strict_types=1);

/** Real controller queries for every backend list filter and suggestion source. */
require __DIR__ . '/helpers/exact-search-501.php';
require_once dirname(__DIR__) . '/storage/plugins/mobile-api/MobileApiPlugin.php';
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use App\Controllers\SearchController;

$db = Exact501::db();
$_SESSION['user'] = ['tipo_utente' => 'admin'];
$t = Exact501::token();
$ids = array_fill_keys(['autori','editori','libri','utenti','generi','scaffali','mensole','posizioni'], []);
Exact501::onCleanup(static function () use ($db, &$ids): void {
    foreach (['posizioni','mensole','scaffali','libri','autori','editori','utenti','generi'] as $table) {
        if ($ids[$table]) { $db->query("DELETE FROM `$table` WHERE id IN (" . implode(',', $ids[$table]) . ')'); }
    }
});
foreach (['Haffner', 'Häffner'] as $i => $surname) {
    $name = "$t $surname";
    $ids['autori'][] = Exact501::exec($db, 'INSERT INTO autori (nome,pseudonimo,biografia,sito_web,`nazionalità`) VALUES (?,?,?,?,?)', [$name,$name,$name,$name,$name]);
    $ids['editori'][] = Exact501::exec($db, 'INSERT INTO editori (nome,sito_web,indirizzo) VALUES (?,?,?)', [$name,$name,$name]);
    $ids['libri'][] = Exact501::exec($db, 'INSERT INTO libri (titolo,collana,isbn13) VALUES (?,?,?)', [$name,$name,$t . $i]);
    $ids['utenti'][] = Exact501::exec($db, 'INSERT INTO utenti (nome,cognome,email,password,codice_tessera) VALUES (?,?,?,?,?)', [$t,$surname,"$t.$i@example.test",'x',strtoupper($t).$i]);
    $genre = $ids['generi'][] = Exact501::exec($db, 'INSERT INTO generi (nome) VALUES (?)', [$name]);
    $shelf = $ids['scaffali'][] = Exact501::exec($db, 'INSERT INTO scaffali (nome,codice,lettera) VALUES (?,?,?)', [$name,$t.$i,'Z']);
    $level = $ids['mensole'][] = Exact501::exec($db, 'INSERT INTO mensole (scaffale_id,numero_livello) VALUES (?,1)', [$shelf]);
    $ids['posizioni'][] = Exact501::exec($db, 'INSERT INTO posizioni (scaffale_id,mensola_id,genere_id) VALUES (?,?,?)', [$shelf,$level,$genre]);
}
App\Support\SearchIndexBuilder::rebuildMany($db, $ids['libri']);
$request = static fn(array $params) => (new ServerRequestFactory())->createServerRequest('GET', '/')->withQueryParams($params);
$read = static function (object $controller, string $method, array $params, bool $mobile = false) use ($request, $db): array {
    $response = $mobile ? $controller->$method($request($params), new Response()) : $controller->$method($request($params), new Response(), $db);
    Exact501::same(200, $response->getStatusCode(), get_class($controller) . "::$method returns 200");
    return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
};
$myIds = static function (array $rows, string $table) use (&$ids): array {
    $found = array_values(array_intersect(array_map('intval', array_column($rows, 'id')), $ids[$table]));
    sort($found); return $found;
};
// Both modes for each individual column: a failure identifies the exact box.
foreach ([
    [new App\Controllers\AutoriApiController(), 'autori', ['search_text','search_nome','search_pseudonimo','search_sito','search_nazionalita']],
    [new App\Controllers\EditoriApiController(), 'editori', ['search_text','search_sito','search_via','search_cap']],
    [new App\Controllers\LibriApiController(), 'libri', ['search_text','collana']],
] as [$controller,$table,$fields]) {
    foreach ($fields as $field) {
        foreach (["$t haffner" => $ids[$table], "\"$t häffner\"" => [$ids[$table][1]]] as $value => $expected) {
            $data = $read($controller, 'list', [$field => $value, 'length' => 100]);
            Exact501::same($expected, $myIds($data['data'], $table), "$table $field: $value");
            Exact501::same(count($expected), $data['recordsFiltered'], "$table $field: filtered count agrees with page");
        }
    }
}
$books = new App\Controllers\LibriApiController();
foreach ([$t => $ids['libri'], '"'.$t.'1"' => [$ids['libri'][1]], '%' => []] as $value => $expected) {
    Exact501::same($expected, $myIds($read($books,'list',['search_isbn'=>$value])['data'],'libri'), "ISBN filter: $value");
}
$users = new App\Controllers\UtentiApiController();
foreach (["$t haffner" => $ids['utenti'], '"'.$t.' Häffner"' => [$ids['utenti'][1]]] as $value => $expected) {
    Exact501::same($expected, $myIds($read($users,'list',['search_text'=>$value])['data'],'utenti'), "user list accepts full names: $value");
}
$search = new SearchController();
foreach (['authors'=>'autori','publishers'=>'editori','users'=>'utenti','genres'=>'generi','locations'=>'posizioni'] as $method=>$table) {
    foreach (["$t haffner" => $ids[$table], '"'.$t.' Häffner"' => [$ids[$table][1]]] as $value=>$expected) {
        Exact501::same($expected, $myIds($read($search,$method,['q'=>$value]),$table), "backend $method picker: $value");
    }
    Exact501::same($read($search,$method,['q'=>'']), $read($search,$method,['q'=>'"']), "$method picker: a lone quote behaves like empty input");
}
$genres = new App\Controllers\GeneriApiController();
foreach (["$t haffner" => $ids['generi'], '"'.$t.' Häffner"' => [$ids['generi'][1]]] as $value=>$expected) {
    Exact501::same($expected, $myIds($read($genres,'search',['q'=>$value]),'generi'), "book-form genre picker: $value");
}
// Pullfrog (PR #502): a box mixing words and quotes binds one value per word;
// the genre picker used to pass a fixed 'ssi' and threw ArgumentCountError.
foreach ([$t.' "häffner"' => [$ids['generi'][1]], '"häffner" '.$t => [$ids['generi'][1]]] as $value=>$expected) {
    Exact501::same($expected, $myIds($read($genres,'search',['q'=>$value]),'generi'), "book-form genre picker, mixed words: $value");
}
$series = new App\Controllers\CollaneController();
Exact501::same(["$t Haffner","$t Häffner"], $read($series,'searchApi',['q'=>"$t haffner"]), 'series suggestions preserve the two accented names');
Exact501::same(["$t Häffner"], $read($series,'searchApi',['q'=>'"'.$t.' Häffner"']), 'series suggestions honour quotes');
Exact501::same([], $read($series,'searchApi',['q'=>'""']), 'empty quoted series does not enumerate the collection');
// CodeRabbit (PR #502): the series picker took a mixed value as one tolerant
// phrase, so `T "häffner"` also suggested Haffner and `"häffner" T` nothing.
Exact501::same(["$t Häffner"], $read($series,'searchApi',['q'=>$t.' "häffner"']), 'series suggestions: a quoted word after a plain one stays exact');
Exact501::same(["$t Häffner"], $read($series,'searchApi',['q'=>'"häffner" '.$t]), 'series suggestions: the words may come in any order');
// CodeRabbit (PR #502): one suggestion per spelling ignoring case, and the
// list sorted with the tolerant collation, not byte order (Ä after Z).
$ids['libri'][] = Exact501::exec($db, 'INSERT INTO libri (titolo,collana) VALUES (?,?)', ["$t extra 1", "$t HAFFNER"]);
$ids['libri'][] = Exact501::exec($db, 'INSERT INTO libri (titolo,collana) VALUES (?,?)', ["$t extra 2", "$t Ärger"]);
$suggested = $read($series,'searchApi',['q'=>"$t haffner"]);
Exact501::same(2, count($suggested), 'series suggestions merge names that differ only in case');
Exact501::same(["$t haffner", "$t häffner"], array_values(array_unique(array_map('mb_strtolower', $suggested))), 'series suggestions keep the umlaut spelling apart');
Exact501::same("$t Ärger", $read($series,'searchApi',['q'=>$t])[0] ?? null, 'series suggestions sort Ä with A, not after Z');
$staff = new App\Plugins\MobileApi\Controllers\StaffEntitiesController($db);
foreach (['searchAuthors'=>'autori','searchPublishers'=>'editori'] as $method=>$table) {
    foreach (["$t haffner"=>$ids[$table],$t.' "häffner"'=>[$ids[$table][1]]] as $value=>$expected) {
        Exact501::same($expected, $myIds($read($staff,$method,['q'=>$value],true)['data'],$table), "mobile backend $method: $value");
    }
}
// Controlled Dewey data, restored afterwards; no edits to the real taxonomy.
$cache = new ReflectionProperty(App\Controllers\DeweyApiController::class, 'deweyDataCache');
$saved = $cache->getValue();
$cache->setValue(null, [App\Support\I18n::getLocale()=>[['code'=>'501','name'=>'Società','level'=>1,'children'=>[]],['code'=>'502','name'=>'Societa','level'=>1,'children'=>[]]]]);
Exact501::onCleanup(static fn()=> $cache->setValue(null,$saved));
$dewey = new App\Controllers\DeweyApiController();
foreach (['societa'=>['501','502'],'"società"'=>['501'],'"societa"'=>['502']] as $value=>$expected) {
    // autocomplete takes no database argument.
    $response = $dewey->autocomplete($request(['q'=>$value]), new Response());
    Exact501::same($expected,array_column(json_decode((string)$response->getBody(),true),'code'),"Dewey picker: $value");
}
Exact501::finish(145);
