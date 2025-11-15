<?php
/**
 * Scan ALL view files for Italian text that's NOT wrapped in __()
 * This finds hardcoded Italian strings that need translation
 */

declare(strict_types=1);

$viewsDir = __DIR__ . '/../app/Views';

// Recursively find all .php files
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($viewsDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

$italianPatterns = [
    // Common Italian words/phrases in HTML/PHP
    '/>\s*([A-ZÀ-Ù][a-zà-ùòèéìùç\s]+)\s*</',  // Text between tags
    '/placeholder="([^"]+)"/',                  // Placeholders
    '/title="([^"]+)"/',                        // Titles
    '/value="([^"<>]+)"(?![^<]*__\()/',        // Values (not followed by __())
];

$italianWords = [
    'Aggiungi', 'Modifica', 'Elimina', 'Salva', 'Annulla', 'Cerca', 'Filtra',
    'Totale', 'Nome', 'Cognome', 'Email', 'Telefono', 'Indirizzo', 'Città',
    'Data', 'Stato', 'Azioni', 'Dettagli', 'Vedi', 'Modifica', 'Cancella',
    'Conferma', 'Attenzione', 'Errore', 'Successo', 'Avviso', 'Informazione',
    'Sì', 'No', 'Chiudi', 'Apri', 'Invia', 'Carica', 'Scarica', 'Stampa',
    'Esporta', 'Importa', 'Seleziona', 'Tutti', 'Nessuno', 'Alcuni',
    'Titolo', 'Descrizione', 'Contenuto', 'Immagine', 'File', 'Documento',
    'Nuovo', 'Vecchio', 'Recente', 'Archiviato', 'Attivo', 'Inattivo',
    'Libro', 'Libri', 'Autore', 'Autori', 'Editore', 'Editori', 'Genere', 'Generi',
    'Prestito', 'Prestiti', 'Utente', 'Utenti', 'Recensione', 'Recensioni',
    'ISBN', 'Copertina', 'Pagine', 'Anno', 'Lingua', 'Formato', 'Prezzo',
    'Disponibile', 'Non disponibile', 'In prestito', 'Prenotato', 'Scaduto',
    'Collocazione', 'Scaffale', 'Ripiano', 'Sezione', 'Ubicazione',
    'Crea', 'Inserisci', 'Aggiorna', 'Rimuovi', 'Ripristina', 'Duplica',
    'Visualizza', 'Nascondi', 'Mostra', 'Espandi', 'Comprimi',
];

$foundStrings = [];

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $filePath = $file->getPathname();
    $relativePath = str_replace($viewsDir . '/', '', $filePath);
    $content = file_get_contents($filePath);

    // Skip if file has no HTML content
    if (strpos($content, '<') === false) {
        continue;
    }

    // Find Italian text NOT wrapped in __()
    // Pattern: Text between > and < that's not preceded by __("
    preg_match_all('/(?<!__\(")>([^<>{}\n]+)</', $content, $matches);

    foreach ($matches[1] as $text) {
        $text = trim($text);

        // Skip if empty, too short, or contains PHP/HTML
        if (strlen($text) < 3 || strpos($text, '<?') !== false || strpos($text, 'php') !== false) {
            continue;
        }

        // Check if contains Italian words
        foreach ($italianWords as $word) {
            if (stripos($text, $word) !== false) {
                if (!isset($foundStrings[$text])) {
                    $foundStrings[$text] = [];
                }
                $foundStrings[$text][] = $relativePath;
                break;
            }
        }
    }
}

// Sort by frequency
uasort($foundStrings, function($a, $b) {
    return count($b) - count($a);
});

echo "🔍 Italian strings found (NOT wrapped in __()):  \n";
echo "   Total unique strings: " . count($foundStrings) . "\n\n";

if (count($foundStrings) > 0) {
    echo "📝 Top 50 most common strings:\n";
    $count = 0;
    foreach ($foundStrings as $str => $files) {
        if ($count++ >= 50) break;
        echo "   • \"$str\" (found in " . count($files) . " files)\n";
        echo "     Files: " . implode(', ', array_slice($files, 0, 3));
        if (count($files) > 3) {
            echo " ... and " . (count($files) - 3) . " more";
        }
        echo "\n";
    }
}
