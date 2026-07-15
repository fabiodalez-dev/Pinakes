<?php
declare(strict_types=1);

require_once __DIR__ . '/../installer/classes/Installer.php';
require_once __DIR__ . '/../vendor/autoload.php';

$testNo = 0;
$check = static function (bool $condition, string $description) use (&$testNo): void {
    $testNo++;
    if (!$condition) {
        fwrite(STDERR, sprintf("[%02d] FAIL: %s\n", $testNo, $description));
        exit(1);
    }
    printf("[%02d] PASS: %s\n", $testNo, $description);
};

$synthetic = <<<'SQL'
CREATE TABLE `quoted_table` (id INT);
CREATE TABLE IF NOT EXISTS unquoted_table (id INT);
SQL;
$check(
    Installer::parseCreateTableNames($synthetic) === ['quoted_table', 'unquoted_table'],
    'parser supports quoted and unquoted CREATE TABLE identifiers'
);

$schema = (string) file_get_contents(__DIR__ . '/../installer/database/schema.sql');
$tables = Installer::parseCreateTableNames($schema);
$statementCount = preg_match_all('/^\s*CREATE\s+TABLE\b/im', $schema);
$check(count($tables) === $statementCount, 'every CREATE TABLE statement yields exactly one table name');
$check(in_array('mobile_app_tokens', $tables, true), 'unquoted Mobile API tables are included');
$check(in_array('libri_autori_import_sources', $tables, true), 'new core tables are included automatically');

$thrown = false;
try {
    Installer::parseCreateTableNames("CREATE TABLE `ok` (id INT);\nCREATE TABLE ??? (id INT);");
} catch (\Throwable $e) {
    $thrown = true;
}
$check($thrown, 'partial parses fail closed instead of weakening verification');

$cli = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/list-source-expectations.php');
$tableCli = shell_exec($cli . ' tables');
$pluginCli = shell_exec($cli . ' plugins');
$check(array_values(array_filter(explode("\n", (string)$tableCli))) === $tables, 'CLI table list uses the same parser as Installer');
$check(array_values(array_filter(explode("\n", (string)$pluginCli))) === \App\Support\BundledPlugins::LIST, 'CLI plugin list uses BundledPlugins::LIST');

echo "ALL {$testNo} PASS\n";
