<?php
declare(strict_types=1);
/**
 * 0.9.0 book-form extras and supplier writes against a real connection:
 * only the empty choice clears the supplier or an RDA override (a malformed
 * id, a deleted supplier or an unknown code keep the stored value), an
 * import batch reports a supplier it could not assign, and a supplier
 * update tells a deleted row from a save that changed nothing.
 *
 * Runs on session TEMPORARY tables named libri / fornitori, which shadow
 * the real ones for this connection only: no catalogue data is touched.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
if (!function_exists('__')) { function __(string $s, mixed ...$a): string { return $a ? sprintf($s, ...$a) : $s; } }

use App\Models\SupplierRepository;
use App\Support\CataloguingExtras;

$env = Dotenv\Dotenv::parse((string) file_get_contents(dirname(__DIR__) . '/.env'));
$db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
$db->set_charset('utf8mb4');

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    $checks++; echo "OK {$label}\n";
};

$db->query('CREATE TEMPORARY TABLE fornitori (id INT NOT NULL PRIMARY KEY, nome VARCHAR(255) NOT NULL, referente VARCHAR(150) NULL, email VARCHAR(255) NULL, telefono VARCHAR(30) NULL, sito_web VARCHAR(255) NULL, indirizzo TEXT NULL, partita_iva VARCHAR(20) NULL, note TEXT NULL) ENGINE=InnoDB');
$db->query('CREATE TEMPORARY TABLE libri (id INT NOT NULL PRIMARY KEY, fornitore_id INT NULL, rda_content_type VARCHAR(10) NULL, rda_media_type VARCHAR(10) NULL, rda_carrier_type VARCHAR(10) NULL, deleted_at DATETIME NULL) ENGINE=InnoDB');
// INFORMATION_SCHEMA does not list temporary tables: tell the column probe they exist.
$cache = new ReflectionProperty(CataloguingExtras::class, 'columnCache');
$cache->setValue(null, ['fornitore_id' => true, 'rda_content_type' => true, 'rda_media_type' => true, 'rda_carrier_type' => true]);
// Both modules are off on an upgraded install until switched on (Settings →
// Modules); the writes below are the ones of a library that uses them.
\App\Support\Features::override('suppliers', true);
\App\Support\Features::override('rda_types', true);

$db->query("INSERT INTO fornitori (id, nome) VALUES (5, 'Libreria Rossi'), (6, 'Distribuzione Bianchi')");
$db->query("INSERT INTO libri (id, fornitore_id, rda_carrier_type) VALUES (1, 5, 'cr')");
$book = static function () use ($db): array {
    $row = $db->query('SELECT fornitore_id, rda_carrier_type FROM libri WHERE id = 1')->fetch_assoc();
    return ['fornitore_id' => $row['fornitore_id'] === null ? null : (int) $row['fornitore_id'], 'rda_carrier_type' => $row['rda_carrier_type']];
};

CataloguingExtras::save($db, 1, ['fornitore_id' => 'abc', 'rda_carrier_type' => 'zzz']);
$check($book() === ['fornitore_id' => 5, 'rda_carrier_type' => 'cr'], 'a malformed supplier id and an unknown RDA code keep the stored values');
CataloguingExtras::save($db, 1, ['fornitore_id' => '99']);
$check($book()['fornitore_id'] === 5, 'a supplier that does not exist (deleted meanwhile) keeps the stored one');
CataloguingExtras::save($db, 1, ['fornitore_id' => '6', 'rda_carrier_type' => ' NC ']);
$check($book() === ['fornitore_id' => 6, 'rda_carrier_type' => 'nc'], 'a valid supplier and RDA code are written');
CataloguingExtras::save($db, 1, ['fornitore_id' => '', 'rda_carrier_type' => '']);
$check($book() === ['fornitore_id' => null, 'rda_carrier_type' => null], 'the empty choice ("no supplier", "Automatic") clears both');
CataloguingExtras::save($db, 1, []);
$check($book() === ['fornitore_id' => null, 'rda_carrier_type' => null], 'a request without the keys writes nothing');

$check(CataloguingExtras::assignSupplier($db, 1, 6) === true && $book()['fornitore_id'] === 6, 'an import batch supplier is assigned and reported as such');
$check(CataloguingExtras::assignSupplier($db, 1, 99) === false && $book()['fornitore_id'] === 6, 'a batch supplier deleted meanwhile is reported as not assigned and clears nothing');

// A module switched off never writes its field: the stored value survives.
\App\Support\Features::override('suppliers', false);
\App\Support\Features::override('rda_types', false);
CataloguingExtras::save($db, 1, ['fornitore_id' => '5', 'rda_carrier_type' => 'nc']);
$check($book() === ['fornitore_id' => 6, 'rda_carrier_type' => null], 'with both modules off a save keeps the stored supplier and RDA values');
$check(CataloguingExtras::assignSupplier($db, 1, 5) === false && $book()['fornitore_id'] === 6, 'with suppliers off an import batch assigns nothing and says so');
\App\Support\Features::override('suppliers', true);
\App\Support\Features::override('rda_types', true);

$repo = new SupplierRepository($db);
$data = SupplierRepository::normalize(['nome' => 'Libreria Rossi 2', 'sito_web' => 'rossi.it']);
$check($repo->update(5, $data) === true && $repo->getById(5)['nome'] === 'Libreria Rossi 2', 'a supplier update is saved');
$check($repo->update(5, $data) === true, 'saving the same values again is still a success');
$check($repo->update(42, $data) === false, 'updating a supplier that no longer exists is not a success');

echo "ALL {$checks} PASS\n";
