<?php
declare(strict_types=1);
/** Exercise the real catalogue controller in a rolled-back transaction. */
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/storage/plugins/emeroteca/src/Services/ContributionService.php';
use App\Controllers\FrontendController;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
final class ArticleControllerDb extends mysqli
{
    public string $prefix;
    public int $failArticleCountAt = 0;
    public bool $throwOnFailure = false;
    private function mapped(string $sql): string
    {
        return (string)preg_replace('/\b(emeroteca_contributi|emeroteca_testate)\b/', $this->prefix . '$1', $sql);
    }
    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    { return parent::query($this->mapped($query), $result_mode); }
    public function prepare(string $query): mysqli_stmt|false
    {
        if (str_starts_with($query, 'SELECT COUNT(*) n FROM emeroteca_contributi') && $this->failArticleCountAt > 0 && --$this->failArticleCountAt === 0) {
            if ($this->throwOnFailure) { throw new mysqli_sql_exception('Injected article failure'); }
            return false;
        }
        return parent::prepare($this->mapped($query));
    }
}
$env = Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__) . '/.env'));
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new ArticleControllerDb($env['DB_HOST'] ?? 'localhost', getenv('E2E_DB_USER') ?: $env['DB_USER'],
    getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? $env['DB_PASSWORD'] ?? ''), getenv('E2E_DB_NAME') ?: $env['DB_NAME'],
    (int)($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
$db->prefix = 'zzmixedctrl_' . bin2hex(random_bytes(4)) . '_';
$db->set_charset('utf8mb4');
$columns = \App\Plugins\Emeroteca\Services\ContributionService::COLUMN_DEFINITIONS;
$definitions = implode(',',array_map(static fn($name,$definition) => "$name $definition",array_keys($columns),$columns));
$controller = new FrontendController();
$call = static function(array $params) use ($controller,$db): array {
    $request = (new ServerRequestFactory())->createServerRequest('GET','/api/catalogo')->withQueryParams($params);
    return json_decode((string)$controller->catalogAPI($request,new Response(),$db)->getBody(),true,512,JSON_THROW_ON_ERROR);
};
$checks = 0;
$check = static function(bool $ok,string $label) use (&$checks): void {
    if (!$ok) { throw new RuntimeException($label); }
    $checks++; echo "OK $label\n";
};
try {
    $db->query("CREATE TABLE emeroteca_contributi ($definitions) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE emeroteca_testate (id INT PRIMARY KEY, logo_url VARCHAR(500)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->begin_transaction();
    // The catalogue/plugin state is restored by rollback, even on failure.
    $db->query("UPDATE plugins SET is_active=1 WHERE name='emeroteca'");
    $suffix = bin2hex(random_bytes(5));
    $name = "Hans Uwe Probe$suffix";
    $credit = "Probe$suffix, Hans Uwe";
    $db->query("INSERT INTO autori(nome) VALUES ('$name')"); $authorId = (int)$db->insert_id;
    $db->query("INSERT INTO libri(titolo,copie_totali,copie_disponibili) VALUES ('Mixed book $suffix',1,1)"); $bookId=(int)$db->insert_id;
    $db->query("INSERT INTO libri_autori(libro_id,autore_id,ruolo) VALUES ($bookId,$authorId,'principale')");
    $db->query("INSERT INTO emeroteca_contributi(reference_key,titolo,autori,pubblico) VALUES ('$suffix','Mixed article $suffix','$credit',1)"); $articleId=(int)$db->insert_id;
    foreach ([['autore'=>$credit],['autore'=>$name],['autore_id'=>$authorId]] as $filter) {
        $data=$call($filter);
        $check($data['pagination']['total_books']===2, 'same author finds book and article: '.json_encode($filter));
        $check($data['pagination']['total_articles']===1, 'mixed result reports one article');
        $check($data['filter_options']['availability_stats']['total']===2, 'all facet counts both corpora');
        $check(str_contains($data['html'],"/emeroteca/articolo/$articleId") && str_contains($data['html'],"Mixed book $suffix"), 'both real record links are rendered');
    }
    $db->query("UPDATE libri SET anno_pubblicazione=2020 WHERE id=$bookId");
    $db->query("UPDATE emeroteca_contributi SET anno_pubblicazione=1988 WHERE id=$articleId");
    $request=(new ServerRequestFactory())->createServerRequest('GET','/autore/'.$authorId);
    $archive=(string)$controller->authorArchiveById($request,new Response(),$db,$authorId)->getBody();
    $check(str_contains($archive, 'Mixed book '.$suffix) && str_contains($archive, '/emeroteca/articolo/'.$articleId), 'author archive renders both corpora');
    $check(strpos($archive, 'Mixed book '.$suffix) < strpos($archive, 'Mixed article '.$suffix), 'author archive preserves descending publication year');
    $nameArchive=(string)$controller->authorArchive($request,new Response(),$db,$name)->getBody();
    $check(str_contains($nameArchive, 'Mixed book '.$suffix) && str_contains($nameArchive, '/emeroteca/articolo/'.$articleId), 'legacy name-based author archive includes articles too');
    foreach ([false, true] as $throw) {
        $db->throwOnFailure = $throw;
        $db->failArticleCountAt = 1;
        $data = $call(['autore'=>$credit]);
        $check($data['pagination']['total_books'] === 1 && str_contains($data['html'], "Mixed book $suffix"), 'article page failure preserves book API');
        $db->failArticleCountAt = 1;
        $catalogRequest = (new ServerRequestFactory())->createServerRequest('GET','/catalogo')->withQueryParams(['autore'=>$credit]);
        $catalog = (string)$controller->catalog($catalogRequest,new Response(),$db)->getBody();
        $check(str_contains($catalog, "Mixed book $suffix") && !str_contains($catalog, '/emeroteca/articolo/'.$articleId), 'article failure preserves HTML catalogue');
        $db->failArticleCountAt = 2;
        $data = $call(['autore'=>$credit]);
        $check($data['pagination']['total_books'] === 2 && $data['filter_options']['availability_stats']['total'] === 1, 'facet failure preserves mixed page and book counts');
        $db->failArticleCountAt = 1;
        $fallback = (string)$controller->authorArchiveById($request,new Response(),$db,$authorId)->getBody();
        $check(str_contains($fallback, "Mixed book $suffix") && !str_contains($fallback, '/emeroteca/articolo/'.$articleId), 'article failure preserves author archive');
    }
    $data=$call(['autore'=>$credit,'disponibilita'=>'disponibile']);
    $check($data['filter_options']['availability_stats']['total']===2,'all facet retains articles when availability is selected');
    $check($data['pagination']['total_books']===1 && !str_contains($data['html'],"/emeroteca/articolo/$articleId"),'loan availability excludes noncirculating article');
    $db->query("UPDATE emeroteca_contributi SET pubblico=0 WHERE id=$articleId");
    $data=$call(['autore'=>$credit]);
    $check($data['pagination']['total_books']===1 && !str_contains($data['html'],"/emeroteca/articolo/$articleId"),'revoking publication immediately removes the article');
    $db->query("UPDATE emeroteca_contributi SET pubblico=1 WHERE id=$articleId");
    $db->query("UPDATE plugins SET is_active=0 WHERE name='emeroteca'");
    $data=$call(['autore'=>$credit]);
    $check($data['pagination']['total_books']===1,'deactivating plugin removes article from controller results');
    echo "SUCCESS $checks controller checks\n";
} finally {
    $db->rollback();
    $db->query('DROP TABLE IF EXISTS emeroteca_contributi');
    $db->query('DROP TABLE IF EXISTS emeroteca_testate');
    $db->close();
}
