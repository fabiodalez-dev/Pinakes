<?php
declare(strict_types=1);
/**
 * Issue #426: a book whose only copy is under maintenance published "0 / 0".
 *
 * Real MySQL, disposable tables: libri.copie_totali counts copies IN
 * CIRCULATION (lending capacity), so what the catalogue publishes has to come
 * from the copies themselves. These checks pin both halves — the number a
 * reader sees, and the reason it differs from the available count.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\CopyHoldings;

final class Sandbox426Db extends mysqli
{
    public string $prefix = '';
    /** @var list<string> */
    public array $tables = ['copie'];
    public function mapped(string $sql): string
    {
        foreach ($this->tables as $name) {
            $sql = preg_replace('/\b' . preg_quote($name, '/') . '\b/', $this->prefix . $name, $sql);
        }
        return $sql;
    }
    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        return parent::query($this->mapped($query), $result_mode);
    }
    public function prepare(string $query): mysqli_stmt|false
    {
        return parent::prepare($this->mapped($query));
    }
}

$root = dirname(__DIR__);
$env = Dotenv\Dotenv::parse(file_get_contents($root . '/.env'));
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new Sandbox426Db(
    $env['DB_HOST'] ?? 'localhost',
    getenv('E2E_DB_USER') ?: $env['DB_USER'],
    getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? $env['DB_PASSWORD']),
    getenv('E2E_DB_NAME') ?: $env['DB_NAME'],
    (int) ($env['DB_PORT'] ?? 3306),
    getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null)
);
$db->prefix = 'zz426_' . bin2hex(random_bytes(3)) . '_';
$db->set_charset('utf8mb4');
if (!function_exists('__')) {
    function __(string $text, mixed ...$args): string
    {
        return $args ? vsprintf($text, $args) : $text;
    }
}
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $passed++ : $failed++;
};
try {
    $db->query("CREATE TABLE copie (id INT AUTO_INCREMENT PRIMARY KEY, libro_id INT NOT NULL,
        stato ENUM('disponibile','prestato','prenotato','manutenzione','in_restauro','perso','danneggiato','in_trasferimento') NOT NULL DEFAULT 'disponibile',
        KEY idx_libro (libro_id)) ENGINE=InnoDB");
    $add = static function (int $book, string $state, int $times = 1) use ($db): void {
        for ($i = 0; $i < $times; $i++) {
            $db->query("INSERT INTO copie (libro_id, stato) VALUES ($book, '$state')");
        }
    };

    // The reported case: one copy, under maintenance.
    $add(1, 'manutenzione');
    $holdings = CopyHoldings::forBook($db, 1);
    $check($holdings !== null && $holdings['owned'] === 1 && $holdings['out'] === 1, 'a single copy under maintenance is still owned');
    $check(CopyHoldings::publishedTotal($holdings, 0) === 1, 'the published total is 1, not the 0 copies in circulation');
    $check(str_contains(CopyHoldings::outOfCirculationNote($holdings), 'manutenzione'), 'the note says the copy is under maintenance');

    // A mixed shelf: the denominator counts everything owned, the note only what cannot circulate.
    $add(2, 'disponibile', 2);
    $add(2, 'prestato');
    $add(2, 'perso');
    $add(2, 'in_restauro', 2);
    $holdings = CopyHoldings::forBook($db, 2);
    $check($holdings['owned'] === 6 && $holdings['out'] === 3, 'owned counts every copy, out only the non-circulating ones');
    $note = CopyHoldings::outOfCirculationNote($holdings);
    $check(str_contains($note, 'Perso: 1') && str_contains($note, 'In restauro: 2'), 'the note reports each reason with its count: ' . $note);
    $check(!str_contains($note, 'Prestato') && !str_contains($note, 'Disponibile'), 'copies on loan are in circulation and stay out of the note');

    // Nothing out of circulation: no note at all, so nothing changes for a normal book.
    $add(3, 'disponibile');
    $holdings = CopyHoldings::forBook($db, 3);
    $check(CopyHoldings::publishedTotal($holdings, 1) === 1 && CopyHoldings::outOfCirculationNote($holdings) === '', 'a fully circulating book publishes the same number as before, with no note');

    // Legacy book with no per-copy rows: keep using the stored count, never claim zero.
    $check(CopyHoldings::forBook($db, 999) === null, 'a book without copy rows reports no holdings');
    $check(CopyHoldings::publishedTotal(null, 4) === 4, 'a legacy book keeps publishing libri.copie_totali');
    $check(CopyHoldings::publishedTotal(null, -1) === 0, 'a negative stored count never reaches the page');
    $check(CopyHoldings::outOfCirculationNote(null) === '', 'no holdings, no note');

    // One query for many books: the catalogue hydration asks in batch.
    $batch = CopyHoldings::forBooks($db, [1, 2, 3, 999]);
    $check(count($batch) === 3 && $batch[2]['owned'] === 6, 'the batch lookup returns every book that has copies, and only those');
    $check(CopyHoldings::forBooks($db, []) === [], 'an empty batch asks the database nothing');
} finally {
    $db->query('DROP TABLE IF EXISTS copie');
}
echo "Passed: $passed Failed: $failed\n";
exit($failed ? 1 : 0);
