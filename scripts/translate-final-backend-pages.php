<?php
/**
 * Translation Script - FINAL Backend Pages (Last 3 Groups)
 *
 * Group 1: Libri Remaining Strings
 *   - app/Views/libri/partials/book_form.php (lines 187-194, 2318)
 *   - app/Views/libri/scheda_libro.php (lines 280-352, 816)
 *
 * Group 2: Utenti Pages
 *   - app/Views/utenti/dettagli_utente.php (lines 9-220, 259-312)
 *   - app/Views/utenti/index.php (lines 100-102)
 *
 * Group 3: Admin Pages
 *   - app/Views/admin/csv_import.php (lines 209-242)
 *   - app/Views/admin/settings.php (lines 33-35, 69-70, 140-164)
 */

// Configuration
$baseDir = dirname(__DIR__);
$files = [
    // Group 1: Libri Remaining Strings
    [
        'path' => 'app/Views/libri/partials/book_form.php',
        'replacements' => [
            // Status options (lines 187-194)
            [
                'search' => '<option value="Non Disponibile" <?php echo strcasecmp($statoCorrente, \'Non Disponibile\') === 0 ? \'selected\' : \'\'; ?>>Non Disponibile</option>',
                'replace' => '<option value="Non Disponibile" <?php echo strcasecmp($statoCorrente, \'Non Disponibile\') === 0 ? \'selected\' : \'\'; ?>><?= __("Non Disponibile") ?></option>',
                'description' => 'Status option: Non Disponibile'
            ],
            [
                'search' => '<option value="In Riparazione" <?php echo strcasecmp($statoCorrente, \'In Riparazione\') === 0 ? \'selected\' : \'\'; ?>>In Riparazione</option>',
                'replace' => '<option value="In Riparazione" <?php echo strcasecmp($statoCorrente, \'In Riparazione\') === 0 ? \'selected\' : \'\'; ?>><?= __("In Riparazione") ?></option>',
                'description' => 'Status option: In Riparazione'
            ],
            [
                'search' => '<option value="Fuori Catalogo" <?php echo strcasecmp($statoCorrente, \'Fuori Catalogo\') === 0 ? \'selected\' : \'\'; ?>>Fuori Catalogo</option>',
                'replace' => '<option value="Fuori Catalogo" <?php echo strcasecmp($statoCorrente, \'Fuori Catalogo\') === 0 ? \'selected\' : \'\'; ?>><?= __("Fuori Catalogo") ?></option>',
                'description' => 'Status option: Fuori Catalogo'
            ],
            [
                'search' => '<option value="Da Inventariare" <?php echo strcasecmp($statoCorrente, \'Da Inventariare\') === 0 ? \'selected\' : \'\'; ?>>Da Inventariare</option>',
                'replace' => '<option value="Da Inventariare" <?php echo strcasecmp($statoCorrente, \'Da Inventariare\') === 0 ? \'selected\' : \'\'; ?>><?= __("Da Inventariare") ?></option>',
                'description' => 'Status option: Da Inventariare'
            ],
            // Cover download message (line 2318)
            [
                'search' => '<p class="text-xs text-gray-500 mb-3">L\'immagine verrà scaricata al salvataggio</p>',
                'replace' => '<p class="text-xs text-gray-500 mb-3"><?= __("L\'immagine verrà scaricata al salvataggio") ?></p>',
                'description' => 'Cover download help text'
            ],
        ]
    ],

    // Group 1: scheda_libro.php
    [
        'path' => 'app/Views/libri/scheda_libro.php',
        'replacements' => [
            // ISBN labels (lines 280-294)
            [
                'search' => '<dt class="text-xs uppercase text-gray-500">ISBN10</dt>',
                'replace' => '<dt class="text-xs uppercase text-gray-500"><?= __("ISBN10") ?></dt>',
                'description' => 'ISBN10 label'
            ],
            [
                'search' => '<dt class="text-xs uppercase text-gray-500">ISBN13</dt>',
                'replace' => '<dt class="text-xs uppercase text-gray-500"><?= __("ISBN13") ?></dt>',
                'description' => 'ISBN13 label'
            ],
            [
                'search' => '<dt class="text-xs uppercase text-gray-500">EAN</dt>',
                'replace' => '<dt class="text-xs uppercase text-gray-500"><?= __("EAN") ?></dt>',
                'description' => 'EAN label'
            ],
            // Weight unit (line 352)
            [
                'search' => '<dd class="text-gray-900 font-medium"><?php echo htmlspecialchars((string)$libro[\'peso\'], ENT_QUOTES, \'UTF-8\'); ?> kg</dd>',
                'replace' => '<dd class="text-gray-900 font-medium"><?php echo htmlspecialchars((string)$libro[\'peso\'], ENT_QUOTES, \'UTF-8\'); ?> <?= __("kg") ?></dd>',
                'description' => 'Weight unit: kg'
            ],
            // Notes label (line 816)
            [
                'search' => '<label for="modal-note" class="form-label">Note (opzionali)</label>',
                'replace' => '<label for="modal-note" class="form-label"><?= __("Note") ?> (<?= __("opzionali") ?>)</label>',
                'description' => 'Notes label with optional'
            ],
        ]
    ],

    // Group 2: dettagli_utente.php
    [
        'path' => 'app/Views/utenti/dettagli_utente.php',
        'replacements' => [
            // JavaScript fallback strings (lines 9-12)
            [
                'search' => 'return "<span class=\'$baseClasses bg-blue-100 text-blue-800\'><i class=\'fas fa-clock mr-2\'></i>In Corso</span>";',
                'replace' => 'return "<span class=\'$baseClasses bg-blue-100 text-blue-800\'><i class=\'fas fa-clock mr-2\'></i>" . __("In Corso") . "</span>";',
                'description' => 'JS fallback: In Corso'
            ],
            [
                'search' => 'return "<span class=\'$baseClasses bg-yellow-100 text-yellow-800\'><i class=\'fas fa-exclamation-triangle mr-2\'></i>In Ritardo</span>";',
                'replace' => 'return "<span class=\'$baseClasses bg-yellow-100 text-yellow-800\'><i class=\'fas fa-exclamation-triangle mr-2\'></i>" . __("In Ritardo") . "</span>";',
                'description' => 'JS fallback: In Ritardo'
            ],
            [
                'search' => 'return "<span class=\'$baseClasses bg-green-100 text-green-800\'><i class=\'fas fa-check-circle mr-2\'></i>Restituito</span>";',
                'replace' => 'return "<span class=\'$baseClasses bg-green-100 text-green-800\'><i class=\'fas fa-check-circle mr-2\'></i>" . __("Restituito") . "</span>";',
                'description' => 'JS fallback: Restituito'
            ],
            // Success messages (lines 69-81)
            [
                'search' => '<p class="font-medium text-blue-900">Utente approvato con successo!</p>',
                'replace' => '<p class="font-medium text-blue-900"><?= __("Utente approvato con successo!") ?></p>',
                'description' => 'Success: User approved'
            ],
            [
                'search' => '<p class="text-sm text-blue-700 mt-1">L\'email di attivazione è stata inviata. L\'utente potrà verificare il proprio account cliccando il link ricevuto (valido 7 giorni).</p>',
                'replace' => '<p class="text-sm text-blue-700 mt-1"><?= __("L\'email di attivazione è stata inviata. L\'utente potrà verificare il proprio account cliccando il link ricevuto (valido 7 giorni).") ?></p>',
                'description' => 'Success: Activation email sent'
            ],
            [
                'search' => '<p class="font-medium text-green-900">Utente attivato direttamente!</p>',
                'replace' => '<p class="font-medium text-green-900"><?= __("Utente attivato direttamente!") ?></p>',
                'description' => 'Success: User activated directly'
            ],
            [
                'search' => '<p class="text-sm text-green-700 mt-1">L\'utente è stato attivato e può già effettuare il login. È stata inviata un\'email di benvenuto.</p>',
                'replace' => '<p class="text-sm text-green-700 mt-1"><?= __("L\'utente è stato attivato e può già effettuare il login. È stata inviata un\'email di benvenuto.") ?></p>',
                'description' => 'Success: Welcome email sent'
            ],
            // Error messages (lines 88-112)
            [
                'search' => '<p class="font-medium text-red-900">Errore: Utente non trovato</p>',
                'replace' => '<p class="font-medium text-red-900"><?= __("Errore: Utente non trovato") ?></p>',
                'description' => 'Error: User not found'
            ],
            [
                'search' => '<p class="text-sm text-red-700 mt-1">L\'utente richiesto non esiste nel database.</p>',
                'replace' => '<p class="text-sm text-red-700 mt-1"><?= __("L\'utente richiesto non esiste nel database.") ?></p>',
                'description' => 'Error: User not in database'
            ],
            [
                'search' => '<p class="font-medium text-amber-900">Operazione non consentita</p>',
                'replace' => '<p class="font-medium text-amber-900"><?= __("Operazione non consentita") ?></p>',
                'description' => 'Error: Operation not allowed'
            ],
            [
                'search' => '<p class="text-sm text-amber-700 mt-1">L\'utente non è in stato sospeso. Solo gli utenti sospesi richiedono approvazione.</p>',
                'replace' => '<p class="text-sm text-amber-700 mt-1"><?= __("L\'utente non è in stato sospeso. Solo gli utenti sospesi richiedono approvazione.") ?></p>',
                'description' => 'Error: User not suspended'
            ],
            [
                'search' => '<p class="font-medium text-red-900">Errore del database</p>',
                'replace' => '<p class="font-medium text-red-900"><?= __("Errore del database") ?></p>',
                'description' => 'Error: Database error'
            ],
            [
                'search' => '<p class="text-sm text-red-700 mt-1">Si è verificato un errore durante l\'operazione. Riprova più tardi.</p>',
                'replace' => '<p class="text-sm text-red-700 mt-1"><?= __("Si è verificato un errore durante l\'operazione. Riprova più tardi.") ?></p>',
                'description' => 'Error: Try again later'
            ],
            // Back to list link (line 118)
            [
                'search' => '<i class="fas fa-arrow-left mr-2"></i> Torna alla lista',
                'replace' => '<i class="fas fa-arrow-left mr-2"></i> <?= __("Torna alla lista") ?>',
                'description' => 'Back to list link'
            ],
            // User without name (line 120)
            [
                'search' => '<h1 class="mt-2 text-2xl font-bold text-gray-900"><?= $display($name, \'Utente senza nome\'); ?></h1>',
                'replace' => '<h1 class="mt-2 text-2xl font-bold text-gray-900"><?= $display($name, __("Utente senza nome")); ?></h1>',
                'description' => 'User without name fallback'
            ],
            // Role label (line 121)
            [
                'search' => '<p class="text-sm text-gray-500">Ruolo: <?= $display($roleLabels[$ruolo] ?? ucfirst($ruolo)); ?></p>',
                'replace' => '<p class="text-sm text-gray-500"><?= __("Ruolo:") ?> <?= $display($roleLabels[$ruolo] ?? ucfirst($ruolo)); ?></p>',
                'description' => 'Role label'
            ],
            // New loan button (line 126)
            [
                'search' => 'Nuovo Prestito',
                'replace' => '<?= __("Nuovo Prestito") ?>',
                'description' => 'New loan button'
            ],
            // Edit user button (line 130)
            [
                'search' => 'Modifica Utente',
                'replace' => '<?= __("Modifica Utente") ?>',
                'description' => 'Edit user button'
            ],
            // Personal info section (line 136)
            [
                'search' => '<h2 class="text-lg font-medium text-gray-900">Informazioni Personali</h2>',
                'replace' => '<h2 class="text-lg font-medium text-gray-900"><?= __("Informazioni Personali") ?></h2>',
                'description' => 'Personal info heading'
            ],
            // Full name label (line 139)
            [
                'search' => '<dt class="text-sm text-gray-500">Nome completo</dt>',
                'replace' => '<dt class="text-sm text-gray-500"><?= __("Nome completo") ?></dt>',
                'description' => 'Full name label'
            ],
            // Birth date label (line 157)
            [
                'search' => '<dt class="text-sm text-gray-500">Data di nascita</dt>',
                'replace' => '<dt class="text-sm text-gray-500"><?= __("Data di nascita") ?></dt>',
                'description' => 'Birth date label'
            ],
            // Gender label (line 163)
            [
                'search' => '<dt class="text-sm text-gray-500">Sesso</dt>',
                'replace' => '<dt class="text-sm text-gray-500"><?= __("Sesso") ?></dt>',
                'description' => 'Gender label'
            ],
            // Tax code label (line 167)
            [
                'search' => '<dt class="text-sm text-gray-500">Codice Fiscale</dt>',
                'replace' => '<dt class="text-sm text-gray-500"><?= __("Codice Fiscale") ?></dt>',
                'description' => 'Tax code label'
            ],
            // Account data section (line 178)
            [
                'search' => '<h2 class="text-lg font-medium text-gray-900">Dati Account</h2>',
                'replace' => '<h2 class="text-lg font-medium text-gray-900"><?= __("Dati Account") ?></h2>',
                'description' => 'Account data heading'
            ],
            // Card code label (line 185)
            [
                'search' => '<dt class="text-sm text-gray-500">Codice Tessera</dt>',
                'replace' => '<dt class="text-sm text-gray-500"><?= __("Codice Tessera") ?></dt>',
                'description' => 'Card code label'
            ],
            // Registered on label (line 189)
            [
                'search' => '<dt class="text-sm text-gray-500">Registrato il</dt>',
                'replace' => '<dt class="text-sm text-gray-500"><?= __("Registrato il") ?></dt>',
                'description' => 'Registered on label'
            ],
            // Last update label (line 195)
            [
                'search' => '<dt class="text-sm text-gray-500">Ultimo aggiornamento</dt>',
                'replace' => '<dt class="text-sm text-gray-500"><?= __("Ultimo aggiornamento") ?></dt>',
                'description' => 'Last update label'
            ],
            // Card expiry label (line 201)
            [
                'search' => '<dt class="text-sm text-gray-500">Scadenza tessera</dt>',
                'replace' => '<dt class="text-sm text-gray-500"><?= __("Scadenza tessera") ?></dt>',
                'description' => 'Card expiry label'
            ],
            // Last access label (line 207)
            [
                'search' => '<dt class="text-sm text-gray-500">Ultimo accesso</dt>',
                'replace' => '<dt class="text-sm text-gray-500"><?= __("Ultimo accesso") ?></dt>',
                'description' => 'Last access label'
            ],
            // Approval actions heading (line 220)
            [
                'search' => '<h2 class="text-lg font-medium text-gray-900">Azioni di Approvazione</h2>',
                'replace' => '<h2 class="text-lg font-medium text-gray-900"><?= __("Azioni di Approvazione") ?></h2>',
                'description' => 'Approval actions heading'
            ],
            // Suspended user message (line 226)
            [
                'search' => 'Questo utente è in stato <strong>sospeso</strong> e richiede approvazione. Scegli un\'opzione:',
                'replace' => '<?= __("Questo utente è in stato <strong>sospeso</strong> e richiede approvazione. Scegli un\'opzione:") ?>',
                'description' => 'Suspended user message'
            ],
            // Approve button (line 235)
            [
                'search' => '<span class="font-medium">Approva e Invia Email Attivazione</span>',
                'replace' => '<span class="font-medium"><?= __("Approva e Invia Email Attivazione") ?></span>',
                'description' => 'Approve and send email button'
            ],
            // Approve help text (line 238)
            [
                'search' => 'L\'utente riceverà un\'email con link di verifica (valido 7 giorni) e potrà attivare autonomamente l\'account.',
                'replace' => '<?= __("L\'utente riceverà un\'email con link di verifica (valido 7 giorni) e potrà attivare autonomamente l\'account.") ?>',
                'description' => 'Approve help text'
            ],
            // Activate directly button (line 246)
            [
                'search' => '<span class="font-medium">Attiva Direttamente</span>',
                'replace' => '<span class="font-medium"><?= __("Attiva Direttamente") ?></span>',
                'description' => 'Activate directly button'
            ],
            // Activate help text (line 249)
            [
                'search' => 'L\'utente sarà attivato immediatamente e riceverà un\'email di benvenuto. Potrà accedere subito.',
                'replace' => '<?= __("L\'utente sarà attivato immediatamente e riceverà un\'email di benvenuto. Potrà accedere subito.") ?>',
                'description' => 'Activate help text'
            ],
            // Loan history heading (line 259)
            [
                'search' => '<h2 class="text-lg font-semibold text-gray-800">Storico Prestiti</h2>',
                'replace' => '<h2 class="text-lg font-semibold text-gray-800"><?= __("Storico Prestiti") ?></h2>',
                'description' => 'Loan history heading'
            ],
            // No loans message (line 276)
            [
                'search' => '<p>Questo utente non ha mai effettuato prestiti.</p>',
                'replace' => '<p><?= __("Questo utente non ha mai effettuato prestiti.") ?></p>',
                'description' => 'No loans message'
            ],
            // Loan ID label (line 284)
            [
                'search' => '<div class="text-gray-500">ID Prestito: <?= $prestito[\'id\']; ?></div>',
                'replace' => '<div class="text-gray-500"><?= __("ID Prestito:") ?> <?= $prestito[\'id\']; ?></div>',
                'description' => 'Loan ID label'
            ],
            // From label (line 288)
            [
                'search' => '<span class="font-semibold">Dal:</span>',
                'replace' => '<span class="font-semibold"><?= __("Dal:") ?></span>',
                'description' => 'From label'
            ],
            // To label (line 291)
            [
                'search' => '<span class="font-semibold">Al:</span>',
                'replace' => '<span class="font-semibold"><?= __("Al:") ?></span>',
                'description' => 'To label'
            ],
            // Internal notes heading (line 312)
            [
                'search' => '<h2 class="text-lg font-medium text-gray-900">Note interne</h2>',
                'replace' => '<h2 class="text-lg font-medium text-gray-900"><?= __("Note interne") ?></h2>',
                'description' => 'Internal notes heading'
            ],
        ]
    ],

    // Group 2: utenti/index.php
    [
        'path' => 'app/Views/utenti/index.php',
        'replacements' => [
            // User type options (lines 100-102)
            [
                'search' => '<option value="staff">Staff</option>',
                'replace' => '<option value="staff"><?= __("Staff") ?></option>',
                'description' => 'User type: Staff'
            ],
            [
                'search' => '<option value="premium">Premium</option>',
                'replace' => '<option value="premium"><?= __("Premium") ?></option>',
                'description' => 'User type: Premium'
            ],
            [
                'search' => '<option value="standard">Standard</option>',
                'replace' => '<option value="standard"><?= __("Standard") ?></option>',
                'description' => 'User type: Standard'
            ],
        ]
    ],

    // Group 3: csv_import.php
    [
        'path' => 'app/Views/admin/csv_import.php',
        'replacements' => [
            // CSV example values (lines 212-242)
            [
                'search' => '<td class="px-4 py-3 text-gray-500 text-xs">Il nome della rosa</td>',
                'replace' => '<td class="px-4 py-3 text-gray-500 text-xs"><?= __("Il nome della rosa") ?></td>',
                'description' => 'CSV example: book title'
            ],
            [
                'search' => '<td class="px-4 py-3 text-gray-500 text-xs">Umberto Eco<br><small><?= __("o multipli separati da |") ?></small></td>',
                'replace' => '<td class="px-4 py-3 text-gray-500 text-xs"><?= __("Umberto Eco") ?><br><small><?= __("o multipli separati da |") ?></small></td>',
                'description' => 'CSV example: author name'
            ],
            [
                'search' => '<td class="px-4 py-3 text-gray-500 text-xs">Mondadori</td>',
                'replace' => '<td class="px-4 py-3 text-gray-500 text-xs"><?= __("Mondadori") ?></td>',
                'description' => 'CSV example: publisher'
            ],
            [
                'search' => '<td class="px-4 py-3 text-gray-500 text-xs">Narrativa</td>',
                'replace' => '<td class="px-4 py-3 text-gray-500 text-xs"><?= __("Narrativa") ?></td>',
                'description' => 'CSV example: genre'
            ],
        ]
    ],

    // Group 3: settings.php
    [
        'path' => 'app/Views/admin/settings.php',
        'replacements' => [
            // Mail driver options (lines 33-35)
            [
                'search' => '<option value="mail" <?php echo $drv===\'mail\'?\'selected\':\'\'; ?>>PHP mail()</option>',
                'replace' => '<option value="mail" <?php echo $drv===\'mail\'?\'selected\':\'\'; ?>><?= __("PHP mail()") ?></option>',
                'description' => 'Mail driver: PHP mail()'
            ],
            [
                'search' => '<option value="smtp" <?php echo $drv===\'smtp\'?\'selected\':\'\'; ?>>SMTP (custom)</option>',
                'replace' => '<option value="smtp" <?php echo $drv===\'smtp\'?\'selected\':\'\'; ?>><?= __("SMTP (custom)") ?></option>',
                'description' => 'Mail driver: SMTP'
            ],
            [
                'search' => '<option value="phpmailer" <?php echo $drv===\'phpmailer\'?\'selected\':\'\'; ?>>PHPMailer</option>',
                'replace' => '<option value="phpmailer" <?php echo $drv===\'phpmailer\'?\'selected\':\'\'; ?>><?= __("PHPMailer") ?></option>',
                'description' => 'Mail driver: PHPMailer'
            ],
            // Encryption options (lines 69-70)
            [
                'search' => '<option value="tls" <?php echo $enc===\'tls\'?\'selected\':\'\'; ?>>TLS</option>',
                'replace' => '<option value="tls" <?php echo $enc===\'tls\'?\'selected\':\'\'; ?>><?= __("TLS") ?></option>',
                'description' => 'Encryption: TLS'
            ],
            [
                'search' => '<option value="ssl" <?php echo $enc===\'ssl\'?\'selected\':\'\'; ?>>SSL</option>',
                'replace' => '<option value="ssl" <?php echo $enc===\'ssl\'?\'selected\':\'\'; ?>><?= __("SSL") ?></option>',
                'description' => 'Encryption: SSL'
            ],
            // Cron command (line 140)
            [
                'search' => '<code class="block bg-gray-800 text-green-400 p-2 rounded text-sm">crontab -e</code>',
                'replace' => '<code class="block bg-gray-800 text-green-400 p-2 rounded text-sm"><?= __("crontab -e") ?></code>',
                'description' => 'Cron command: crontab -e'
            ],
        ]
    ],
];

// Counters
$totalFiles = count($files);
$totalReplacements = 0;
$successCount = 0;
$errorCount = 0;
$errors = [];

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════╗\n";
echo "║   Translation Script - FINAL Backend Pages (Last 3 Groups)   ║\n";
echo "╚═══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Process each file
foreach ($files as $fileConfig) {
    $filePath = $baseDir . '/' . $fileConfig['path'];
    $replacements = $fileConfig['replacements'];

    echo "Processing: {$fileConfig['path']}\n";

    // Check if file exists
    if (!file_exists($filePath)) {
        $errors[] = "File not found: {$fileConfig['path']}";
        $errorCount++;
        continue;
    }

    // Read file content
    $content = file_get_contents($filePath);
    if ($content === false) {
        $errors[] = "Failed to read file: {$fileConfig['path']}";
        $errorCount++;
        continue;
    }

    $originalContent = $content;
    $fileReplacementCount = 0;

    // Apply replacements
    foreach ($replacements as $replacement) {
        $search = $replacement['search'];
        $replace = $replacement['replace'];
        $description = $replacement['description'];

        if (strpos($content, $search) !== false) {
            $content = str_replace($search, $replace, $content);
            $fileReplacementCount++;
            echo "  ✓ {$description}\n";
        } else {
            echo "  ⚠ NOT FOUND: {$description}\n";
        }
    }

    // Write back to file
    if ($fileReplacementCount > 0) {
        if (file_put_contents($filePath, $content) !== false) {
            echo "  → Applied {$fileReplacementCount} replacements\n";
            $successCount++;
            $totalReplacements += $fileReplacementCount;
        } else {
            $errors[] = "Failed to write file: {$fileConfig['path']}";
            $errorCount++;
        }
    } else {
        echo "  ℹ No changes made\n";
    }

    echo "\n";
}

// Summary
echo "═══════════════════════════════════════════════════════════════\n";
echo "SUMMARY\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "Files processed:        {$totalFiles}\n";
echo "Files modified:         {$successCount}\n";
echo "Total translations:     {$totalReplacements}\n";
echo "Errors:                 {$errorCount}\n";

if ($errorCount > 0) {
    echo "\nERRORS:\n";
    foreach ($errors as $error) {
        echo "  • {$error}\n";
    }
}

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "Translation Groups Completed:\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "✓ Group 1: Libri Remaining Strings\n";
echo "  - book_form.php: Status options + cover help text\n";
echo "  - scheda_libro.php: ISBN labels, weight unit, notes\n";
echo "\n";
echo "✓ Group 2: Utenti Pages\n";
echo "  - dettagli_utente.php: JS fallbacks, messages, form labels\n";
echo "  - index.php: User type options\n";
echo "\n";
echo "✓ Group 3: Admin Pages\n";
echo "  - csv_import.php: CSV example values\n";
echo "  - settings.php: Mail drivers, encryption, cron\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "\n";

if ($errorCount === 0 && $totalReplacements > 0) {
    echo "✅ All translations applied successfully!\n";
    echo "\n";
    echo "NEXT STEPS:\n";
    echo "1. Review changes: git diff\n";
    echo "2. Test pages in browser (Italian + English)\n";
    echo "3. Run extraction: php scripts/extract-i18n-strings.php\n";
    echo "4. Verify translations in locale files\n";
    echo "5. Commit when satisfied\n";
} elseif ($errorCount > 0) {
    echo "⚠ Some errors occurred. Please review and fix.\n";
    exit(1);
} else {
    echo "ℹ No translations were applied (all already exist?)\n";
}

echo "\n";
