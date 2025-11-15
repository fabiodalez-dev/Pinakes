<?php
/**
 * Translate scheda_libro.php (book detail page)
 * Comprehensive translation of ALL Italian strings
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// ALL translations for book detail page
$newTranslations = [
    // Breadcrumb
    'Home' => 'Home',
    'Libri' => 'Books',

    // Action buttons
    'Stampa etichetta' => 'Print label',
    'Visualizza' => 'View',
    'Modifica' => 'Edit',
    'Elimina' => 'Delete',
    'Restituzione' => 'Return',

    // Quick info sidebar
    'Editore:' => 'Publisher:',
    'Non specificato' => 'Not specified',
    'Autori:' => 'Authors:',
    'Genere:' => 'Genre:',

    // Active loan card
    'Prestito attivo' => 'Active loan',
    'Utente' => 'User',
    'Sconosciuto' => 'Unknown',
    'Dal' => 'From',
    'Scadenza' => 'Due date',
    'Rinnovi effettuati' => 'Renewals made',
    'Gestito da' => 'Managed by',
    'Note' => 'Notes',
    'Rinnova prestito (+14 giorni)' => 'Renew loan (+14 days)',
    'Non rinnovabile: prestito in ritardo' => 'Not renewable: loan overdue',
    'Limite massimo rinnovi raggiunto' => 'Maximum renewal limit reached',
    'Registra restituzione' => 'Register return',

    // Details section
    'Dettagli Libro' => 'Book Details',
    'Anno pubblicazione' => 'Publication year',
    'Data di pubblicazione' => 'Publication date',
    'Collana' => 'Series',
    'Numero serie' => 'Series number',
    'Pagine' => 'Pages',
    'Dimensioni' => 'Dimensions',
    'Formato' => 'Format',
    'Peso' => 'Weight',
    'Prezzo' => 'Price',
    'Data acquisizione' => 'Acquisition date',
    'Tipo acquisizione' => 'Acquisition type',
    'Classificazione Dewey' => 'Dewey Classification',
    'Collocazione' => 'Location',
    'Numero inventario' => 'Inventory number',
    'Parole chiave' => 'Keywords',
    'Stato' => 'Status',
    'File' => 'File',
    'Apri' => 'Open',
    'Audio' => 'Audio',
    'Edizione' => 'Edition',
    'Mensola' => 'Rack',

    // Description section
    'Descrizione' => 'Description',

    // Copies section
    'Copie Fisiche' => 'Physical Copies',
    'Inventario' => 'Inventory',
    'ID Prestito' => 'Loan ID',
    'Azioni' => 'Actions',
    'In prestito' => 'On loan',

    // Loan history section
    'Storico Prestiti' => 'Loan History',
    'prestiti totali' => 'total loans',
    'Data Prestito' => 'Loan Date',
    'Restituzione' => 'Return',
    'Rinnovi' => 'Renewals',
    'Operatore' => 'Operator',
    'In corso' => 'In progress',

    // JavaScript alerts
    'Sei sicuro?' => 'Are you sure?',
    'Questa azione non può essere annullata' => 'This action cannot be undone',
    'Eliminare il libro?' => 'Delete book?',
    'Rinnova prestito?' => 'Renew loan?',
    'La scadenza verrà estesa di 14 giorni' => 'The due date will be extended by 14 days',
    'Rinnova' => 'Renew',
    'Annulla' => 'Cancel',
    'Rinnovare il prestito? La scadenza verrà estesa di 14 giorni.' => 'Renew loan? The due date will be extended by 14 days.',

    // Return modal
    'Registra restituzione prestito' => 'Register loan return',
    'Esito restituzione' => 'Return outcome',
    'Restituito' => 'Returned',
    'Mantieni in ritardo' => 'Keep overdue',
    'Danneggiato' => 'Damaged',
    'Perso' => 'Lost',
    'Aggiungi eventuali note...' => 'Add any notes...',
    'Conferma restituzione' => 'Confirm return',

    // Edit copy modal
    'Modifica Stato Copia' => 'Edit Copy Status',
    'Stato della copia' => 'Copy status',
    'Disponibile' => 'Available',
    'Prestato (usa il sistema Prestiti)' => 'On loan (use Loans system)',
    'In manutenzione' => 'Under maintenance',
    'Nota:' => 'Note:',
    'Per prestare una copia, usa la sezione Prestiti. Imposta "Disponibile" per chiudere un prestito attivo.' => 'To loan a copy, use the Loans section. Set "Available" to close an active loan.',
    'Salva Modifiche' => 'Save Changes',
    'Conferma modifica' => 'Confirm change',
    'Vuoi aggiornare lo stato di questa copia?' => 'Do you want to update the status of this copy?',
    'Sì, aggiorna' => 'Yes, update',
    'Elimina copia' => 'Delete copy',
    'Sei sicuro di voler eliminare la copia' => 'Are you sure you want to delete copy',
    'Questa azione non può essere annullata.' => 'This action cannot be undone.',
    'Sì, elimina' => 'Yes, delete',

    // Copy status options (lowercase for CSS classes)
    'disponibile' => 'available',
    'prestato' => 'on loan',
    'manutenzione' => 'maintenance',
    'perso' => 'lost',
    'danneggiato' => 'damaged',

    // Loan status
    'restituito' => 'returned',
    'in_corso' => 'in progress',
    'in_ritardo' => 'overdue',

    // Position labels
    'Pos' => 'Pos',

    // Misc labels
    'copi' => 'copi', // part of "copie/copia"
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now wrap Italian strings in scheda_libro.php
$file = __DIR__ . '/../app/Views/libri/scheda_libro.php';
$content = file_get_contents($file);
$original = $content;

$replacements = [
    // Breadcrumb
    '          <i class="fas fa-home mr-1"></i>Home' => '          <i class="fas fa-home mr-1"></i><?= __("Home") ?>',
    '          <i class="fas fa-book mr-1"></i>Libri' => '          <i class="fas fa-book mr-1"></i><?= __("Libri") ?>',

    // Action buttons
    '            Stampa etichetta' => '            <?= __("Stampa etichetta") ?>',
    '            Visualizza' => '            <?= __("Visualizza") ?>',
    '            Modifica' => '            <?= __("Modifica") ?>',
    '            Restituzione' => '            <?= __("Restituzione") ?>',
    '              Elimina' => '              <?= __("Elimina") ?>',

    // Quick info sidebar
    '            <span class="font-medium">Editore:</span>' => '            <span class="font-medium"><?= __("Editore:") ?></span>',
    '            <?php echo App\Support\HtmlHelper::e($libro[\'editore_nome\'] ?? \'Non specificato\'); ?>' => '            <?php echo App\Support\HtmlHelper::e($libro[\'editore_nome\'] ?? __(\'Non specificato\')); ?>',
    '            <span class="font-medium">Autori:</span>' => '            <span class="font-medium"><?= __("Autori:") ?></span>',
    '                <span class="text-gray-400">Non specificato</span>' => '                <span class="text-gray-400"><?= __("Non specificato") ?></span>',
    '            <span class="font-medium">Genere:</span>' => '            <span class="font-medium"><?= __("Genere:") ?></span>',
    '              <?php echo $path !== \'\' ? $path : \'Non specificato\'; ?>' => '              <?php echo $path !== \'\' ? $path : __(\'Non specificato\'); ?>',
    '            <span class="font-medium">ISBN:</span>' => '            <span class="font-medium"><?= __("ISBN") ?>:</span>',
    '            <?php echo App\Support\HtmlHelper::e(($libro[\'isbn13\'] ?? \'\') ?: ($libro[\'isbn10\'] ?? \'Non specificato\')); ?>' => '            <?php echo App\Support\HtmlHelper::e(($libro[\'isbn13\'] ?? \'\') ?: ($libro[\'isbn10\'] ?? __(\'Non specificato\'))); ?>',

    // Active loan card
    '            Prestito attivo' => '            <?= __("Prestito attivo") ?>',
    '            <span class="font-medium">Utente</span>' => '            <span class="font-medium"><?= __("Utente") ?></span>',
    '              <?php echo App\Support\HtmlHelper::e($activeLoan[\'utente_nome\'] ?? \'Sconosciuto\'); ?>' => '              <?php echo App\Support\HtmlHelper::e($activeLoan[\'utente_nome\'] ?? __(\'Sconosciuto\')); ?>',
    '            <span class="font-medium">Dal</span>' => '            <span class="font-medium"><?= __("Dal") ?></span>',
    '            <span class="font-medium">Scadenza</span>' => '            <span class="font-medium"><?= __("Scadenza") ?></span>',
    '            <span class="font-medium">Rinnovi effettuati</span>' => '            <span class="font-medium"><?= __("Rinnovi effettuati") ?></span>',
    '            <span class="font-medium">Gestito da</span>' => '            <span class="font-medium"><?= __("Gestito da") ?></span>',
    '                <i class="fas fa-redo-alt"></i> Rinnova prestito (+14 giorni)' => '                <i class="fas fa-redo-alt"></i> <?= __("Rinnova prestito (+14 giorni)") ?>',
    '              Non rinnovabile: prestito in ritardo' => '              <?= __("Non rinnovabile: prestito in ritardo") ?>',
    '              Limite massimo rinnovi raggiunto (<?php echo $maxRenewals; ?>)' => '              <?= __("Limite massimo rinnovi raggiunto") ?> (<?php echo $maxRenewals; ?>)',
    '              <i class="fas fa-undo mr-2"></i>Registra restituzione' => '              <i class="fas fa-undo mr-2"></i><?= __("Registra restituzione") ?>',

    // Details section
    '            Dettagli Libro' => '            <?= __("Dettagli Libro") ?>',
    '              <dt class="text-xs uppercase text-gray-500">Edizione</dt>' => '              <dt class="text-xs uppercase text-gray-500"><?= __("Edizione") ?></dt>',
    '              <dt class="text-xs uppercase text-gray-500">Anno pubblicazione</dt>' => '              <dt class="text-xs uppercase text-gray-500"><?= __("Anno pubblicazione") ?></dt>',
    '              <dt class="text-xs uppercase text-gray-500">Data di pubblicazione</dt>' => '              <dt class="text-xs uppercase text-gray-500"><?= __("Data di pubblicazione") ?></dt>',
    '              <dt class="text-xs uppercase text-gray-500">Collana</dt>' => '              <dt class="text-xs uppercase text-gray-500"><?= __("Collana") ?></dt>',
    '              <dt class="text-xs uppercase text-gray-500">Numero serie</dt>' => '              <dt class="text-xs uppercase text-gray-500"><?= __("Numero serie") ?></dt>',
    '              <dt class="text-xs uppercase text-gray-500">Pagine</dt>' => '              <dt class="text-xs uppercase text-gray-500"><?= __("Pagine") ?></dt>',
    '              <dt class="text-xs uppercase text-gray-500">Dimensioni</dt>' => '              <dt class="text-xs uppercase text-gray-500"><?= __("Dimensioni") ?></dt>',
    '              <dt class="text-xs uppercase text-gray-500">Formato</dt>' => '              <dt class="text-xs uppercase text-gray-500"><?= __("Formato") ?></dt>',
    '              <dt class="text-xs uppercase text-gray-500">Peso</dt>' => '              <dt class="text-xs uppercase text-gray-500"><?= __("Peso") ?></dt>',
    '              <dt class="text-xs uppercase text-gray-500">Prezzo</dt>' => '              <dt class="text-xs uppercase text-gray-500"><?= __("Prezzo") ?></dt>',
    '              <dt class="text-xs uppercase text-gray-500">Data acquisizione</dt>' => '              <dt class="text-xs uppercase text-gray-500"><?= __("Data acquisizione") ?></dt>',
    '              <dt class="text-xs uppercase text-gray-500">Tipo acquisizione</dt>' => '              <dt class="text-xs uppercase text-gray-500"><?= __("Tipo acquisizione") ?></dt>',
    '              <dt class="text-xs uppercase text-gray-500">Classificazione Dewey</dt>' => '              <dt class="text-xs uppercase text-gray-500"><?= __("Classificazione Dewey") ?></dt>',
    '              <dt class="text-xs uppercase text-gray-500">Collocazione</dt>' => '              <dt class="text-xs uppercase text-gray-500"><?= __("Collocazione") ?></dt>',
    '              <dd class="text-gray-900 font-medium"><?php echo $posLabel !== \'\' ? App\Support\HtmlHelper::e($posLabel) : \'Non specificato\'; ?></dd>' => '              <dd class="text-gray-900 font-medium"><?php echo $posLabel !== \'\' ? App\Support\HtmlHelper::e($posLabel) : __(\'Non specificato\'); ?></dd>',
    '              <dt class="text-xs uppercase text-gray-500">Numero inventario</dt>' => '              <dt class="text-xs uppercase text-gray-500"><?= __("Numero inventario") ?></dt>',
    '              <dt class="text-xs uppercase text-gray-500">Parole chiave</dt>' => '              <dt class="text-xs uppercase text-gray-500"><?= __("Parole chiave") ?></dt>',
    '              <dt class="text-xs uppercase text-gray-500">File</dt>' => '              <dt class="text-xs uppercase text-gray-500"><?= __("File") ?></dt>',
    '              <dd><a class="text-gray-700 hover:text-gray-900 hover:underline" href="<?php echo htmlspecialchars($libro[\'file_url\'], ENT_QUOTES, \'UTF-8\'); ?>" target="_blank" rel="noopener">Apri</a></dd>' => '              <dd><a class="text-gray-700 hover:text-gray-900 hover:underline" href="<?php echo htmlspecialchars($libro[\'file_url\'], ENT_QUOTES, \'UTF-8\'); ?>" target="_blank" rel="noopener"><?= __("Apri") ?></a></dd>',
    '              <dt class="text-xs uppercase text-gray-500">Audio</dt>' => '              <dt class="text-xs uppercase text-gray-500"><?= __("Audio") ?></dt>',
    '              <dd><a class="text-gray-700 hover:text-gray-900 hover:underline" href="<?php echo htmlspecialchars($libro[\'audio_url\'], ENT_QUOTES, \'UTF-8\'); ?>" target="_blank" rel="noopener">Apri</a></dd>' => '              <dd><a class="text-gray-700 hover:text-gray-900 hover:underline" href="<?php echo htmlspecialchars($libro[\'audio_url\'], ENT_QUOTES, \'UTF-8\'); ?>" target="_blank" rel="noopener"><?= __("Apri") ?></a></dd>',
    '                <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $cls; ?>"><?php echo App\Support\HtmlHelper::e($libro[\'stato\'] ?? \'Non specificato\'); ?></span>' => '                <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $cls; ?>"><?php echo App\Support\HtmlHelper::e($libro[\'stato\'] ?? __(\'Non specificato\')); ?></span>',

    // Description section
    '            Descrizione' => '            <?= __("Descrizione") ?>',

    // Copies section
    '          Copie Fisiche' => '          <?= __("Copie Fisiche") ?>',
    '          <span class="ml-2 text-sm font-normal text-gray-500">(<?php echo count($copie); ?> copi<?php echo count($copie) > 1 ? \'e\' : \'a\'; ?>)</span>' => '          <span class="ml-2 text-sm font-normal text-gray-500">(<?php echo count($copie); ?> <?= count($copie) > 1 ? __("copie") : __("copia") ?>)</span>',

    // Loan history section
    '          Storico Prestiti' => '          <?= __("Storico Prestiti") ?>',
    '          <span class="ml-2 text-sm font-normal text-gray-500">(<?php echo count($loanHistory); ?> prestiti totali)</span>' => '          <span class="ml-2 text-sm font-normal text-gray-500">(<?php echo count($loanHistory); ?> <?= __("prestiti totali") ?>)</span>',
    '                    <span class="text-gray-400 italic">In corso</span>' => '                    <span class="text-gray-400 italic"><?= __("In corso") ?></span>',

    // JavaScript functions with translations
    '        Swal.fire({title: __(\'Sei sicuro?\'), text: __(\'Questa azione non può essere annullata\'), icon:\'warning\', showCancelButton:true, confirmButtonText: __(\'Elimina\'), confirmButtonColor:\'#d33\'}).then(r=>{ if(r.isConfirmed) e.target.submit(); });' => '        Swal.fire({title: __(\'Sei sicuro?\'), text: __(\'Questa azione non può essere annullata\'), icon:\'warning\', showCancelButton:true, confirmButtonText: __(\'Elimina\'), cancelButtonText: __(\'Annulla\'), confirmButtonColor:\'#d33\'}).then(r=>{ if(r.isConfirmed) e.target.submit(); });',
    '      return confirm(__(\'Eliminare il libro?\'));' => '      return confirm(__(\'Eliminare il libro?\'));',

    // Return modal
    '            Registra restituzione prestito #<?php echo (int)$activeLoan[\'id\']; ?>' => '            <?= __("Registra restituzione prestito") ?> #<?php echo (int)$activeLoan[\'id\']; ?>',
    '            <label for="modal-stato" class="form-label">Esito restituzione</label>' => '            <label for="modal-stato" class="form-label"><?= __("Esito restituzione") ?></label>',
    '              <option value="restituito" selected>Restituito</option>' => '              <option value="restituito" selected><?= __("Restituito") ?></option>',
    '              <option value="in_ritardo">Mantieni in ritardo</option>' => '              <option value="in_ritardo"><?= __("Mantieni in ritardo") ?></option>',
    '              <option value="danneggiato">Danneggiato</option>' => '              <option value="danneggiato"><?= __("Danneggiato") ?></option>',
    '              <option value="perso">Perso</option>' => '              <option value="perso"><?= __("Perso") ?></option>',
    '              <i class="fas fa-check mr-2"></i>Conferma restituzione' => '              <i class="fas fa-check mr-2"></i><?= __("Conferma restituzione") ?>',

    // Edit copy modal
    '          Modifica Stato Copia' => '          <?= __("Modifica Stato Copia") ?>',
    '          <label for="edit-copy-stato" class="form-label">Stato della copia</label>' => '          <label for="edit-copy-stato" class="form-label"><?= __("Stato della copia") ?></label>',
    '            <option value="prestato" disabled>Prestato (usa il sistema Prestiti)</option>' => '            <option value="prestato" disabled><?= __("Prestato (usa il sistema Prestiti)") ?></option>',
    '            <option value="manutenzione">In manutenzione</option>' => '            <option value="manutenzione"><?= __("In manutenzione") ?></option>',
    '          <label for="edit-copy-note" class="form-label">Note (opzionale)</label>' => '          <label for="edit-copy-note" class="form-label"><?= __("Note") ?> (<?= __("opzionale") ?>)</label>',
    '            <i class="fas fa-save mr-2"></i>Salva Modifiche' => '            <i class="fas fa-save mr-2"></i><?= __("Salva Modifiche") ?>',
    '        prestatoOption.textContent = \'Prestato (imposta "Disponibile" per chiudere il prestito)\';' => '        prestatoOption.textContent = __(\'Prestato (imposta "Disponibile" per chiudere il prestito)\');',
    '        prestatoOption.textContent = \'Prestato (usa il sistema Prestiti)\';' => '        prestatoOption.textContent = __(\'Prestato (usa il sistema Prestiti)\');',
    '          title: __(\'Conferma modifica\'),' => '          title: __(\'Conferma modifica\'),',
    '          text: __(\'Vuoi aggiornare lo stato di questa copia?\'),' => '          text: __(\'Vuoi aggiornare lo stato di questa copia?\'),',
    '          confirmButtonText: __(\'Sì, aggiorna\'),' => '          confirmButtonText: __(\'Sì, aggiorna\'),',
    '        if (confirm(__(\'Vuoi aggiornare lo stato di questa copia?\'))) {' => '        if (confirm(__(\'Vuoi aggiornare lo stato di questa copia?\'))) {',
    '          title: __(\'Elimina copia\'),' => '          title: __(\'Elimina copia\'),',
    '          html: `Sei sicuro di voler eliminare la copia <strong>${numeroInventario}</strong>?<br><span class="text-sm text-gray-600">Questa azione non può essere annullata.</span>`,' => '          html: `${__(\'Sei sicuro di voler eliminare la copia\')} <strong>${numeroInventario}</strong>?<br><span class="text-sm text-gray-600">${__(\'Questa azione non può essere annullata.\')}</span>`,',
    '          confirmButtonText: __(\'Sì, elimina\'),' => '          confirmButtonText: __(\'Sì, elimina\'),',
    '        if (confirm(`Sei sicuro di voler eliminare la copia ${numeroInventario}? Questa azione non può essere annullata.`)) {' => '        if (confirm(`${__(\'Sei sicuro di voler eliminare la copia\')} ${numeroInventario}? ${__(\'Questa azione non può essere annullata.\')}`)) {',

    // Position label
    '                if ($msLvl !== \'\') { $parts[] = \'Mensola \'.$msLvl; }' => '                if ($msLvl !== \'\') { $parts[] = __(\'Mensola\').\' \'.$msLvl; }',
    '                if ($posProgressiva > 0) { $parts[] = \'Pos \'.str_pad((string)$posProgressiva, 2, \'0\', STR_PAD_LEFT); }' => '                if ($posProgressiva > 0) { $parts[] = __(\'Pos\').\' \'.str_pad((string)$posProgressiva, 2, \'0\', STR_PAD_LEFT); }',
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
    echo "\n✅ scheda_libro.php - wrapped " . count($replacements) . " strings\n";
} else {
    echo "\nℹ️  scheda_libro.php - no changes needed\n";
}

echo "\n✅ Translation complete!\n";
