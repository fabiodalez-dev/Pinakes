<?php
/**
 * Translate prestiti/index.php (loans list page)
 * Comprehensive translation of ALL Italian strings
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// ALL translations for prestiti index page
$newTranslations = [
    // Status badges (in PHP function)
    'In Corso' => 'In Progress',
    'In Ritardo' => 'Overdue',
    'Restituito' => 'Returned',
    'Danneggiato' => 'Damaged',
    'Perso' => 'Lost',

    // Page header
    'Prestiti' => 'Loans',
    'Gestione Prestiti' => 'Loans Management',
    'Visualizza e gestisci tutti i prestiti della biblioteca' => 'View and manage all library loans',
    'Nuovo Prestito' => 'New Loan',

    // Success messages
    'Prestito creato con successo!' => 'Loan created successfully!',
    'Prestito aggiornato con successo!' => 'Loan updated successfully!',

    // Pending requests section
    'Richieste di Prestito in Attesa' => 'Pending Loan Requests',
    'Inizio:' => 'Start:',
    'Fine:' => 'End:',
    'Approva' => 'Approve',
    'Rifiuta' => 'Reject',
    'Richiesto il' => 'Requested on',

    // Filters
    'Filtri di Ricerca' => 'Search Filters',
    'Cerca Utente' => 'Search User',
    'Cerca Libro' => 'Search Book',
    'Data prestito (Da)' => 'Loan date (From)',
    'Data prestito (A)' => 'Loan date (To)',
    'Cancella filtri' => 'Clear filters',
    'Applica Filtri' => 'Apply Filters',

    // Loans list
    'Elenco Prestiti' => 'Loans List',
    'In corso' => 'In progress',
    'In ritardo' => 'Overdue',
    'Nessun prestito trovato.' => 'No loans found.',
    'ID Prestito:' => 'Loan ID:',
    'Prestito:' => 'Loan:',
    'Scadenza:' => 'Due date:',
    'Registra Restituzione' => 'Register Return',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now wrap Italian strings in prestiti/index.php
$file = __DIR__ . '/../app/Views/prestiti/index.php';
$content = file_get_contents($file);
$original = $content;

$replacements = [
    // Status badges function
    "return \"<span class='\$baseClasses bg-blue-100 text-blue-800'><i class='fas fa-clock mr-2'></i>In Corso</span>\";" => "return \"<span class='\$baseClasses bg-blue-100 text-blue-800'><i class='fas fa-clock mr-2'></i>\" . __(\"In Corso\") . \"</span>\";",
    "return \"<span class='\$baseClasses bg-yellow-100 text-yellow-800'><i class='fas fa-exclamation-triangle mr-2'></i>In Ritardo</span>\";" => "return \"<span class='\$baseClasses bg-yellow-100 text-yellow-800'><i class='fas fa-exclamation-triangle mr-2'></i>\" . __(\"In Ritardo\") . \"</span>\";",
    "return \"<span class='\$baseClasses bg-green-100 text-green-800'><i class='fas fa-check-circle mr-2'></i>Restituito</span>\";" => "return \"<span class='\$baseClasses bg-green-100 text-green-800'><i class='fas fa-check-circle mr-2'></i>\" . __(\"Restituito\") . \"</span>\";",
    "return \"<span class='\$baseClasses bg-red-100 text-red-800'><i class='fas fa-times-circle mr-2'></i>\" . ucfirst(\$status) . \"</span>\";" => "return \"<span class='\$baseClasses bg-red-100 text-red-800'><i class='fas fa-times-circle mr-2'></i>\" . ucfirst(__(ucfirst(\$status))) . \"</span>\";",

    // Breadcrumb
    '            <i class="fas fa-home mr-1"></i>Home' => '            <i class="fas fa-home mr-1"></i><?= __("Home") ?>',
    '            <i class="fas fa-handshake mr-1"></i>Prestiti' => '            <i class="fas fa-handshake mr-1"></i><?= __("Prestiti") ?>',

    // Page header
    '            Gestione Prestiti' => '            <?= __("Gestione Prestiti") ?>',
    '          <p class="text-sm text-gray-600 mt-1">Visualizza e gestisci tutti i prestiti della biblioteca</p>' => '          <p class="text-sm text-gray-600 mt-1"><?= __("Visualizza e gestisci tutti i prestiti della biblioteca") ?></p>',
    '            Nuovo Prestito' => '            <?= __("Nuovo Prestito") ?>',

    // Success messages
    '          <span>Prestito creato con successo!</span>' => '          <span><?= __("Prestito creato con successo!") ?></span>',
    '          <span>Prestito aggiornato con successo!</span>' => '          <span><?= __("Prestito aggiornato con successo!") ?></span>',

    // Pending section
    '          Richieste di Prestito in Attesa (<?= count($pending_loans) ?>)' => '          <?= __("Richieste di Prestito in Attesa") ?> (<?= count($pending_loans) ?>)',
    '                    Inizio: <?= date(\'d-m-Y\', strtotime($loan[\'data_prestito\'])) ?>' => '                    <?= __("Inizio:") ?> <?= date(\'d-m-Y\', strtotime($loan[\'data_prestito\'])) ?>',
    '                    Fine: <?= date(\'d-m-Y\', strtotime($loan[\'data_scadenza\'])) ?>' => '                    <?= __("Fine:") ?> <?= date(\'d-m-Y\', strtotime($loan[\'data_scadenza\'])) ?>',
    '                <i class="fas fa-check mr-2"></i>Approva' => '                <i class="fas fa-check mr-2"></i><?= __("Approva") ?>',
    '                <i class="fas fa-times mr-2"></i>Rifiuta' => '                <i class="fas fa-times mr-2"></i><?= __("Rifiuta") ?>',
    '              Richiesto il <?= date(\'d-m-Y H:i\', strtotime($loan[\'created_at\'])) ?>' => '              <?= __("Richiesto il") ?> <?= date(\'d-m-Y H:i\', strtotime($loan[\'created_at\'])) ?>',

    // Filters
    '          Filtri di Ricerca' => '          <?= __("Filtri di Ricerca") ?>',
    '                <label class="form-label">Cerca Utente</label>' => '                <label class="form-label"><?= __("Cerca Utente") ?></label>',
    '                <label class="form-label">Cerca Libro</label>' => '                <label class="form-label"><?= __("Cerca Libro") ?></label>',
    '                <label class="form-label">Data prestito (Da)</label>' => '                <label class="form-label"><?= __("Data prestito (Da)") ?></label>',
    '                <label class="form-label">Data prestito (A)</label>' => '                <label class="form-label"><?= __("Data prestito (A)") ?></label>',
    '                Cancella filtri' => '                <?= __("Cancella filtri") ?>',
    '                Applica Filtri' => '                <?= __("Applica Filtri") ?>',

    // Table
    '            <h2 class="text-lg font-semibold text-gray-800">Elenco Prestiti</h2>' => '            <h2 class="text-lg font-semibold text-gray-800"><?= __("Elenco Prestiti") ?></h2>',
    '              <button data-status="in_corso" class="status-filter-btn btn-secondary px-3 py-1.5">In corso</button>' => '              <button data-status="in_corso" class="status-filter-btn btn-secondary px-3 py-1.5"><?= __("In corso") ?></button>',
    '              <button data-status="in_ritardo" class="status-filter-btn btn-secondary px-3 py-1.5">In ritardo</button>' => '              <button data-status="in_ritardo" class="status-filter-btn btn-secondary px-3 py-1.5"><?= __("In ritardo") ?></button>',
    '              <button data-status="restituito" class="status-filter-btn btn-secondary px-3 py-1.5">Restituito</button>' => '              <button data-status="restituito" class="status-filter-btn btn-secondary px-3 py-1.5"><?= __("Restituito") ?></button>',
    '                                <p>Nessun prestito trovato.</p>' => '                                <p><?= __("Nessun prestito trovato.") ?></p>',
    '                                    <div class="text-gray-500">ID Prestito: <?php echo $prestito[\'id\']; ?></div>' => '                                    <div class="text-gray-500"><?= __("ID Prestito:") ?> <?php echo $prestito[\'id\']; ?></div>',
    '                                        <span class="font-semibold">Prestito:</span> <?php echo date("d/m/Y", strtotime($prestito[\'data_prestito\'])); ?>' => '                                        <span class="font-semibold"><?= __("Prestito:") ?></span> <?php echo date("d/m/Y", strtotime($prestito[\'data_prestito\'])); ?>',
    '                                        <span class="font-semibold">Scadenza:</span> <?php echo date("d/m/Y", strtotime($prestito[\'data_scadenza\'])); ?>' => '                                        <span class="font-semibold"><?= __("Scadenza:") ?></span> <?php echo date("d/m/Y", strtotime($prestito[\'data_scadenza\'])); ?>',
    '                                            <a href="/admin/prestiti/restituito/<?php echo $prestito[\'id\']; ?>" class="p-2 text-blue-600 hover:bg-blue-100 rounded-full transition-colors" title="Registra Restituzione">' => '                                            <a href="/admin/prestiti/restituito/<?php echo $prestito[\'id\']; ?>" class="p-2 text-blue-600 hover:bg-blue-100 rounded-full transition-colors" title="<?= __("Registra Restituzione") ?>">',
];

foreach ($replacements as $search => $replace) {
    $count = 0;
    $content = str_replace($search, $replace, $content, $count);
    if ($count > 0) {
        echo "✓ Replaced: " . substr($search, 0, 60) . "... ($count times)\n";
    }
}

if ($content !== $original) {
    file_put_contents($file, $content);
    echo "\n✅ prestiti/index.php - wrapped " . count($replacements) . " strings\n";
} else {
    echo "\nℹ️  prestiti/index.php - no changes needed\n";
}

echo "\n✅ Translation complete!\n";
