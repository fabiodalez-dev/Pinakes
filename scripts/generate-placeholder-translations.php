<?php
/**
 * Generate English translations for placeholder texts
 */

declare(strict_types=1);

// Italian → English translations for placeholder texts
// Technical examples (URLs, emails, codes) are kept as-is
$translations = [
    // Search placeholders
    'Cerca libri, autori, editori, utenti...' => 'Search books, authors, publishers, users...',
    'Cerca libri, autori...' => 'Search books, authors...',
    'Cerca libri...' => 'Search books...',
    'Cerca autore...' => 'Search author...',
    'Cerca autori esistenti o aggiungine di nuovi...' => 'Search existing authors or add new ones...',
    'Cerca editore esistente o inserisci nuovo...' => 'Search existing publisher or insert new...',
    'Cerca editore...' => 'Search publisher...',
    'Cerca genere...' => 'Search genre...',
    'Cerca icona... (es. user, home, book)' => 'Search icon... (e.g. user, home, book)',
    'Cerca per nome, cognome, telefono, email o tessera' => 'Search by name, surname, phone, email or card',
    'Cerca per nome...' => 'Search by name...',
    'Cerca per pseudonimo...' => 'Search by pseudonym...',
    'Cerca per titolo o sottotitolo' => 'Search by title or subtitle',
    'Cerca per titolo o stato (es. disponibile)' => 'Search by title or status (e.g. available)',
    'Cerca posizione...' => 'Search location...',
    'Cerca rapido...' => 'Quick search...',

    // Form fields - Book
    'Titolo libro' => 'Book title',
    'Sottotitolo del libro (opzionale)' => 'Book subtitle (optional)',
    'Descrizione del libro...' => 'Book description...',
    'Titolo, sottotitolo, descrizione...' => 'Title, subtitle, description...',
    'Titolo...' => 'Title...',
    'Link al file digitale (se disponibile)' => 'Link to digital file (if available)',
    'Link all\'audiolibro (se disponibile)' => 'Link to audiobook (if available)',
    'Eventuali annotazioni sullo stato del libro...' => 'Any notes on book condition...',

    // Form fields - Author
    'Nome e cognome dell\'autore' => 'Author\'s full name',
    'Nome d\'arte o pseudonimo' => 'Stage name or pseudonym',

    // Form fields - Publisher
    'Nome della casa editrice' => 'Publisher name',
    'Nome e cognome del referente' => 'Contact person full name',

    // Form fields - General
    'Nome Cognome' => 'First Last Name',
    'Nome, cognome, email...' => 'Name, surname, email...',
    'Data inizio' => 'Start date',
    'Descrizione breve' => 'Short description',
    'Note aggiuntive o osservazioni particolari...' => 'Additional notes or special observations...',
    'Aggiungi eventuali note sul prestito' => 'Add any loan notes',
    'Aggiungi eventuali note...' => 'Add any notes...',

    // Examples
    'es. La morale anarchica' => 'e.g. The Anarchist Morality',
    'es. Prima edizione' => 'e.g. First edition',
    'es. Italiano, Inglese' => 'e.g. Italian, English',
    'es. 26 agosto 2025' => 'e.g. August 26, 2025',
    'es. romanzo, fantasy, avventura (separare con virgole)' => 'e.g. novel, fantasy, adventure (separate with commas)',
    'es. Acquisto, Donazione, Prestito' => 'e.g. Purchase, Donation, Loan',
    'es. Copertina rigida, Brossura' => 'e.g. Hardcover, Paperback',
    'es. Fantasy contemporaneo' => 'e.g. Contemporary Fantasy',
    'es. Noir mediterraneo' => 'e.g. Mediterranean Noir',
    'es. Urban fantasy' => 'e.g. Urban fantasy',
    'es. I Classici' => 'e.g. The Classics',
    'es. Integrazione Sito Web' => 'e.g. Website Integration',
    'es. Biblioteca Civica' => 'e.g. Public Library',
    'Es. La Tua Biblioteca Digitale' => 'E.g. Your Digital Library',
    'Es. Un libro fantastico!' => 'E.g. An amazing book!',
    'Es. Un libro straordinario!' => 'E.g. An extraordinary book!',
    'Es. Italiana, Americana, Francese...' => 'E.g. Italian, American, French...',
    'Es. Italiana, Americana...' => 'E.g. Italian, American...',

    // UI Text
    'Condividi la tua opinione su questo libro...' => 'Share your opinion about this book...',
    'Cosa ne pensi di questo libro?' => 'What do you think about this book?',
    'La tua biblioteca digitale...' => 'Your digital library...',
    'Lascia vuoto per 1 mese' => 'Leave empty for 1 month',
    'Lascia vuoto per nascondere il titolo' => 'Leave empty to hide the title',
    'Descrivi l\'utilizzo di questa API key...' => 'Describe the use of this API key...',

    // URLs and Paths
    '/catalogo' => '/catalog',
    'Contattaci' => 'Contact Us',
    'Privacy Policy' => 'Privacy Policy',
    'URL sito web...' => 'Website URL...',

    // Settings
    'Scaffale Narrativa' => 'Fiction Shelf',
    'Auto' => 'Auto',
    'A' => 'A',
    'IT' => 'IT',

    // Technical placeholders - kept as-is (examples, not translated)
    'ISBN10 o ISBN13' => 'ISBN10 or ISBN13',
];

// Generate locale/en_US.json
$outputFile = __DIR__ . '/../locale/en_US.json';
$formatted = json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

file_put_contents($outputFile, $formatted);

echo "✅ Generated English translations\n";
echo "   File: locale/en_US.json\n";
echo "   Translations: " . count($translations) . "\n\n";

echo "📝 Translations added:\n";
foreach (array_slice($translations, 0, 10) as $it => $en) {
    echo "   • \"{$it}\" → \"{$en}\"\n";
}
echo "   ... and " . (count($translations) - 10) . " more\n";
