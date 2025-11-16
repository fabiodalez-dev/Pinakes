<?php
require 'vendor/autoload.php';

$envFile = '.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim(str_replace(['"', "'"], '', $value));
    }
}

$pdo = new PDO(
    "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS']
);

echo "Clearing Italian Dewey...\n";
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
$pdo->exec("TRUNCATE TABLE classificazione");

echo "Loading English Dewey...\n";
$sql = file_get_contents(__DIR__ . '/installer/database/data_en_US.sql');
preg_match_all('/INSERT INTO `classificazione` VALUES \((.*?)\);/s', $sql, $matches);

foreach ($matches[0] as $insert) {
    $pdo->exec($insert);
}

$pdo->exec("SET FOREIGN_KEY_CHECKS=1");

$count = $pdo->query("SELECT COUNT(*) FROM classificazione")->fetchColumn();
$sample = $pdo->query("SELECT nome FROM classificazione WHERE id = 1")->fetchColumn();

echo "Done! $count entries. Sample: $sample\n";
