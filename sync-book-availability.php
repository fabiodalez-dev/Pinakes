#!/usr/bin/env php
<?php
/**
 * Script per sincronizzare campo 'stato' con 'copie_disponibili'
 *
 * Questo script corregge la discrepanza tra il campo 'stato' (usato dal backend)
 * e 'copie_disponibili' (usato dal frontend) per tutti i libri nel database.
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Support\DataIntegrity;

// Carica configurazione database da .env
$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    die("Errore: File .env non trovato\n");
}

// Leggi manualmente il file .env
$env = [];
$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    // Salta commenti
    if (strpos(trim($line), '#') === 0) {
        continue;
    }
    // Parse KEY=VALUE
    if (strpos($line, '=') !== false) {
        list($key, $value) = explode('=', $line, 2);
        $env[trim($key)] = trim($value);
    }
}

// Connessione al database
$db = new mysqli(
    $env['DB_HOST'] ?? 'localhost',
    $env['DB_USER'] ?? 'root',
    $env['DB_PASS'] ?? '',
    $env['DB_NAME'] ?? 'biblioteca'
);

if ($db->connect_error) {
    die("Errore connessione database: " . $db->connect_error . "\n");
}

$db->set_charset('utf8mb4');

echo "=== Sincronizzazione disponibilità libri ===\n\n";

// Mostra situazione attuale
echo "1. Analisi situazione attuale...\n";
$result = $db->query("
    SELECT
        COUNT(*) as totale,
        SUM(CASE WHEN stato = 'disponibile' AND copie_disponibili = 0 THEN 1 ELSE 0 END) as discrepanza_disponibile,
        SUM(CASE WHEN stato = 'prestato' AND copie_disponibili > 0 THEN 1 ELSE 0 END) as discrepanza_prestato
    FROM libri
");

if ($row = $result->fetch_assoc()) {
    echo "   Totale libri: " . $row['totale'] . "\n";
    echo "   Libri con stato 'disponibile' ma copie_disponibili = 0: " . $row['discrepanza_disponibile'] . "\n";
    echo "   Libri con stato 'prestato' ma copie_disponibili > 0: " . $row['discrepanza_prestato'] . "\n";

    if ($row['discrepanza_disponibile'] == 0 && $row['discrepanza_prestato'] == 0) {
        echo "\n✓ Nessuna discrepanza trovata! I dati sono già sincronizzati.\n";
        $db->close();
        exit(0);
    }
}

echo "\n2. Esecuzione sincronizzazione...\n";

// Usa DataIntegrity per sincronizzare
$integrity = new DataIntegrity($db);
$result = $integrity->recalculateAllBookAvailability();

if (!empty($result['errors'])) {
    echo "\n✗ Errori durante la sincronizzazione:\n";
    foreach ($result['errors'] as $error) {
        echo "  - $error\n";
    }
    $db->close();
    exit(1);
}

echo "   Libri aggiornati: " . $result['updated'] . "\n";

// Verifica risultato
echo "\n3. Verifica risultato...\n";
$result = $db->query("
    SELECT
        SUM(CASE WHEN stato = 'disponibile' AND copie_disponibili = 0 THEN 1 ELSE 0 END) as discrepanza_disponibile,
        SUM(CASE WHEN stato = 'prestato' AND copie_disponibili > 0 THEN 1 ELSE 0 END) as discrepanza_prestato
    FROM libri
");

if ($row = $result->fetch_assoc()) {
    if ($row['discrepanza_disponibile'] == 0 && $row['discrepanza_prestato'] == 0) {
        echo "   ✓ Sincronizzazione completata con successo!\n";
        echo "   ✓ Tutti i libri hanno ora stato e copie_disponibili coerenti.\n";
    } else {
        echo "   ⚠ Ancora presenti discrepanze:\n";
        echo "     - stato 'disponibile' ma copie = 0: " . $row['discrepanza_disponibile'] . "\n";
        echo "     - stato 'prestato' ma copie > 0: " . $row['discrepanza_prestato'] . "\n";
    }
}

// Mostra statistiche finali
echo "\n4. Statistiche finali:\n";
$result = $db->query("
    SELECT
        COUNT(*) as totale,
        SUM(CASE WHEN stato = 'disponibile' THEN 1 ELSE 0 END) as disponibili,
        SUM(CASE WHEN stato = 'prestato' THEN 1 ELSE 0 END) as prestati,
        SUM(copie_disponibili) as copie_disponibili_totali,
        SUM(copie_totali) as copie_totali
    FROM libri
");

if ($row = $result->fetch_assoc()) {
    echo "   Libri totali: " . $row['totale'] . "\n";
    echo "   Libri disponibili: " . $row['disponibili'] . "\n";
    echo "   Libri prestati: " . $row['prestati'] . "\n";
    echo "   Copie disponibili: " . $row['copie_disponibili_totali'] . " / " . $row['copie_totali'] . "\n";
}

$db->close();
echo "\n✓ Script completato.\n";
