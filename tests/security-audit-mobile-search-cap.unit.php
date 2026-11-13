<?php
declare(strict_types=1);

/**
 * Security audit 2026-11 — mobile catalogue search relevance budget.
 *
 * GET /api/v1/catalog/search (any reader token) passed an unbounded `q` to
 * SearchIndexBuilder::buildRelevanceOrder() without a word cap, so the ORDER BY
 * carried one ISBN/title/author/publisher/keywords/description score block per
 * word, evaluated on every candidate row. The controller now cuts `q` to 256
 * characters and ranks on at most 12 words (the builder also de-duplicates and
 * caps at ExactSearch::MAX_FILTER_TERMS for every caller).
 *
 * DB-free: a mysqli subclass records the SQL handed to prepare() and stops
 * there; the builder's schema probes are pre-seeded through reflection.
 *
 * Run:  php tests/security-audit-mobile-search-cap.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
foreach (['Support/ResponseEnvelope.php', 'Support/Input.php', 'Support/CursorCodec.php', 'Controllers/CatalogController.php'] as $file) {
    require_once $root . '/storage/plugins/mobile-api/src/' . $file;
}

use App\Plugins\MobileApi\Controllers\CatalogController;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$setStatic = static function (string $class, string $prop, mixed $value): void {
    $p = new ReflectionProperty($class, $prop);
    $p->setAccessible(true);
    $p->setValue(null, $value);
};
$setStatic(\App\Support\SearchIndexBuilder::class, 'columnExists', true);
$setStatic(\App\Support\SearchIndexBuilder::class, 'hasDescrizionePlain', false);
$setStatic(\App\Support\SchemaInfo::class, 'tableCache', ['libri_editori' => true]);

/** Records the values bound to a statement, then stops before execution. */
final class SecAuditCapturingStmt extends mysqli_stmt
{
    public function __construct(private SecAuditCapturingDb $owner)
    {
    }

    public function bind_param(string $types, mixed &...$vars): bool
    {
        foreach ($vars as $v) {
            $this->owner->bound[] = $v;
        }
        return true;
    }

    public function execute(?array $params = null): bool
    {
        foreach ($params ?? [] as $v) {
            $this->owner->bound[] = $v;
        }
        throw new RuntimeException('secaudit: SQL and parameters captured, stop before execution');
    }
}

final class SecAuditCapturingDb extends mysqli
{
    /** @var list<string> */
    public array $sql = [];
    /** @var list<mixed> */
    public array $bound = [];

    public function __construct()
    {
    }

    public function prepare(string $query): mysqli_stmt|false
    {
        $this->sql[] = $query;
        return new SecAuditCapturingStmt($this);
    }

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        $this->sql[] = $query;
        return false;
    }
}

/** Score blocks in the search statement (one "THEN 120" ISBN case per ranked word). */
$searchBlocks = static function (string $q, array $extra = []): int {
    $db = new SecAuditCapturingDb();
    $request = (new Slim\Psr7\Factory\ServerRequestFactory())
        ->createServerRequest('GET', '/api/v1/catalog/search')
        ->withQueryParams(['q' => $q] + $extra);
    (new CatalogController($db))->search($request, new Slim\Psr7\Response());
    $max = 0;
    foreach ($db->sql as $sql) {
        $max = max($max, substr_count($sql, 'THEN 120'));
    }
    return $max;
};

echo "A. Word cap on the relevance ORDER BY\n";
$manyWords = implode(' ', array_map(static fn (int $i): string => 'w' . $i, range(1, 400)));
$n = $searchBlocks($manyWords);
$check($n > 0 && $n <= 12, "400 distinct words are ranked on at most 12 (got {$n})");
$n = $searchBlocks(trim(str_repeat('a ', 3000)));
$check($n === 1, "'a a a ...' is ranked as one word (got {$n})");
$n = $searchBlocks('eco rosa');
$check($n === 2, "a normal two-word query still ranks both words (got {$n})");
$n = $searchBlocks($manyWords, ['sort' => 'title']);
$check($n === 0, 'an explicit non-relevance sort builds no score blocks');

echo "B. Query length cap\n";
$source = (string) file_get_contents($root . '/storage/plugins/mobile-api/src/Controllers/CatalogController.php');
$check(str_contains($source, 'private const MAX_QUERY_LENGTH = 256;')
    && str_contains($source, 'mb_substr($query, 0, self::MAX_QUERY_LENGTH'), 'q is cut to 256 characters');
$check(str_contains($source, "buildRelevanceOrder(\$this->db, \$query, 'l.', self::RELEVANCE_WORD_LIMIT)"),
    'buildRelevanceOrder is called with the controller word limit');

echo "C. The filter sees the capped q too\n";
/** Every value bound to the search statement(s), flattened to one string. */
$boundFor = static function (string $q, array $extra = []): string {
    $db = new SecAuditCapturingDb();
    $request = (new Slim\Psr7\Factory\ServerRequestFactory())
        ->createServerRequest('GET', '/api/v1/catalog/search')
        ->withQueryParams(['q' => $q] + $extra);
    (new CatalogController($db))->search($request, new Slim\Psr7\Response());
    return implode("\n", array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $db->bound));
};
// 'eco' + 253 spaces fills the 256-character cap, so 'rosa' lies beyond it. With
// a non-relevance sort nothing is ranked: only the WHERE filter binds q.
$padded = 'eco' . str_repeat(' ', 253) . 'rosa';
$control = $boundFor('eco rosa', ['sort' => 'title']);
$check(str_contains($control, 'rosa') && str_contains($control, 'eco'), 'control: both words of a short query reach the filter parameters');
$capped = $boundFor($padded, ['sort' => 'title']);
$check(str_contains($capped, 'eco') && !str_contains($capped, 'rosa'),
    'a word beyond the 256-character cap is not bound to the filter (title sort)');
$cappedRel = $boundFor($padded);
$check(str_contains($cappedRel, 'eco') && !str_contains($cappedRel, 'rosa'),
    'nor to the filter or the ranking (relevance sort)');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
