#!/usr/bin/env php
<?php
/**
 * Translation Script: Profile, Auth, Error Pages, and Archive
 *
 * Wraps all hardcoded Italian strings with __() function for i18n support
 *
 * Files covered:
 * - app/Views/profile/reservations.php
 * - app/Views/user_dashboard/prenotazioni.php
 * - app/Views/auth/forgot-password.php
 * - app/Views/auth/forgot_password.php
 * - app/Views/auth/reset-password.php
 * - app/Views/auth/reset_password.php
 * - app/Views/auth/register_success.php
 * - app/Views/auth/login.php
 * - app/Views/auth/register.php
 * - app/Views/errors/404.php
 * - app/Views/errors/500.php
 * - app/Views/frontend/archive.php (remaining strings)
 */

$rootDir = dirname(__DIR__);
$localeFile = $rootDir . '/locale/en_US.json';

// Translation map: Italian => English
$translations = [
    // Profile/Reservations common strings
    'Attenzione:' => 'Warning:',
    '%d prestito in ritardo' => '%d overdue loan',
    '%d prestiti in ritardo' => '%d overdue loans',
    'Hai libri che dovevano essere restituiti. Restituiscili al più presto per evitare sanzioni.' => 'You have books that should have been returned. Return them as soon as possible to avoid penalties.',

    'Richieste in Sospeso' => 'Pending Requests',
    'Richieste in sospeso' => 'Pending requests',
    '%d richiesta in sospeso' => '%d pending request',
    '%d richieste in sospeso' => '%d pending requests',
    'In attesa di approvazione' => 'Awaiting approval',
    'Dal' => 'From',
    'al' => 'to',
    'Dal %s al %s' => 'From %s to %s',
    'Richiesto il' => 'Requested on',
    'Richiesto il %s' => 'Requested on %s',

    'Prestiti in Corso' => 'Active Loans',
    'Prestiti attivi' => 'Active loans',
    '%d prestito attivo' => '%d active loan',
    '%d prestiti attivi' => '%d active loans',
    'Nessun prestito in corso' => 'No active loans',
    'Nessun prestito attivo' => 'No active loans',
    'Non hai libri in prestito al momento' => 'You have no books on loan at the moment',
    'In ritardo' => 'Overdue',
    'Scadenza' => 'Due date',
    'Già recensito' => 'Already reviewed',
    'Lascia una recensione' => 'Leave a review',

    'Prenotazioni Attive' => 'Active Reservations',
    'Prenotazioni attive' => 'Active reservations',
    '%d prenotazione attiva' => '%d active reservation',
    '%d prenotazioni attive' => '%d active reservations',
    'Nessuna prenotazione attiva' => 'No active reservations',
    'Nessuna prenotazione' => 'No reservations',
    'Non hai prenotazioni attive al momento' => 'You have no active reservations at the moment',
    'Posizione:' => 'Position:',
    'Posizione: %d' => 'Position: %d',
    'Non specificata' => 'Not specified',
    'Annullare questa prenotazione?' => 'Cancel this reservation?',
    'Annulla prenotazione' => 'Cancel reservation',

    'Storico Prestiti' => 'Loan History',
    'Prestiti passati' => 'Past loans',
    '%d prestito passato' => '%d past loan',
    '%d prestiti passati' => '%d past loans',
    'Nessuno storico' => 'No history',
    'Nessun prestito passato' => 'No past loans',
    'Non hai prestiti passati' => 'You have no past loans',
    'Restituito' => 'Returned',
    'Restituito in ritardo' => 'Returned late',
    'Perso' => 'Lost',
    'Danneggiato' => 'Damaged',
    'Prestato' => 'On loan',
    'In corso' => 'In progress',

    'Le Mie Recensioni' => 'My Reviews',
    'Le tue recensioni' => 'Your reviews',
    '%d recensione' => '%d review',
    '%d recensioni' => '%d reviews',
    'Nessuna recensione' => 'No reviews',
    'Non hai ancora lasciato recensioni' => 'You have not left any reviews yet',
    'In attesa di approvazione' => 'Pending approval',
    'Approvata' => 'Approved',
    'Rifiutata' => 'Rejected',

    // Review modal
    'Valutazione *' => 'Rating *',
    'Seleziona' => 'Select',
    'Eccellente' => 'Excellent',
    'Molto buono' => 'Very good',
    'Buono' => 'Good',
    'Mediocre' => 'Fair',
    'Scarso' => 'Poor',
    'Titolo (opzionale)' => 'Title (optional)',
    'Es. Un libro fantastico!' => 'e.g. An amazing book!',
    'Es. Un libro straordinario!' => 'e.g. An outstanding book!',
    'Recensione (opzionale)' => 'Review (optional)',
    'Cosa ne pensi di questo libro?' => 'What do you think of this book?',
    'Condividi la tua opinione su questo libro...' => 'Share your opinion about this book...',
    'Annulla' => 'Cancel',
    'Invia recensione' => 'Submit review',
    'Chiudi' => 'Close',
    'Seleziona la valutazione' => 'Select rating',

    // JavaScript strings
    'Attenzione' => 'Warning',
    'Seleziona una valutazione prima di inviare la recensione.' => 'Please select a rating before submitting the review.',
    'OK' => 'OK',
    'Successo!' => 'Success!',
    'Recensione inviata con successo!' => 'Review submitted successfully!',
    'Errore' => 'Error',
    'Impossibile inviare la recensione' => 'Unable to submit review',
    'Impossibile inviare la recensione.' => 'Unable to submit the review.',
    'Errore di connessione' => 'Connection error',
    'Impossibile comunicare con il server. Riprova più tardi.' => 'Unable to communicate with the server. Please try again later.',
    'Errore del server' => 'Server error',
    'Risposta non valida. Controlla la console per dettagli.' => 'Invalid response. Check the console for details.',
    'Sarà pubblicata dopo l\'approvazione di un amministratore.' => 'It will be published after administrator approval.',

    // Auth: Forgot Password
    'Recupera Password' => 'Recover Password',
    'Inserisci la tua email per ricevere un link di reset' => 'Enter your email to receive a reset link',
    'Email di recupero inviata con successo!' => 'Recovery email sent successfully!',
    'Email associata al tuo account' => 'Email associated with your account',
    'mario.rossi@email.it' => 'john.doe@email.com',
    'Riceverai un link di reset via email. Il link sarà valido per 24 ore.' => 'You will receive a reset link via email. The link will be valid for 24 hours.',
    'Invia link di reset' => 'Send reset link',
    'Ricordi la password?' => 'Remember your password?',
    'Accedi' => 'Sign in',
    'Password dimenticata' => 'Forgot password',
    'Inserisci la tua email per ricevere il link di reset.' => 'Enter your email to receive the reset link.',
    'Torna al login' => 'Back to login',
    'Invia link' => 'Send link',

    // Auth: Reset Password
    'Resetta Password' => 'Reset Password',
    'Inserisci la tua nuova password' => 'Enter your new password',
    'Nuova Password' => 'New Password',
    'Nuova password' => 'New password',
    'Conferma Password' => 'Confirm Password',
    'Conferma password' => 'Confirm password',
    '••••••••' => '••••••••',
    'Minimo 8 caratteri, con lettere maiuscole, minuscole e numeri' => 'Minimum 8 characters, with uppercase, lowercase and numbers',
    'Password resettata con successo!' => 'Password reset successfully!',
    'Ora puoi accedere con la tua nuova password.' => 'You can now sign in with your new password.',
    'Reimposta password' => 'Reset password',
    'Scegli una nuova password per il tuo account.' => 'Choose a new password for your account.',
    'Salva' => 'Save',

    // Auth: Register Success
    'Registrazione Completata' => 'Registration Completed',
    'Registrazione completata' => 'Registration completed',
    'Conferma la tua email' => 'Confirm your email',
    'Ti abbiamo inviato un\'email con il link per confermare l\'indirizzo.' => 'We have sent you an email with the link to confirm your address.',
    'Dopo la conferma, un amministratore approverà la tua iscrizione.' => 'After confirmation, an administrator will approve your registration.',
    'Vai al login' => 'Go to login',

    // Auth: Login
    'Accesso' => 'Login',
    'Accedi al tuo account' => 'Sign in to your account',
    'Email' => 'Email',
    'Password' => 'Password',
    'Ricordami' => 'Remember me',
    'Password dimenticata?' => 'Forgot password?',
    'Accesso in corso...' => 'Signing in...',
    'Non hai un account?' => 'Don\'t have an account?',
    'Registrati' => 'Sign up',
    'Email o password non corretti. Verifica le credenziali e riprova' => 'Email or password incorrect. Check your credentials and try again',
    'La tua sessione è scaduta. Per motivi di sicurezza, effettua nuovamente l\'accesso' => 'Your session has expired. For security reasons, please sign in again',
    'Errore di sicurezza. Aggiorna la pagina e riprova' => 'Security error. Refresh the page and try again',
    'Il tuo account è stato sospeso. Contatta l\'amministratore per maggiori informazioni' => 'Your account has been suspended. Contact the administrator for more information',
    'Il tuo account è in attesa di approvazione. Riceverai un\'email quando sarà attivato' => 'Your account is pending approval. You will receive an email when it is activated',
    'Email non verificata. Controlla la tua casella di posta e clicca sul link di verifica' => 'Email not verified. Check your inbox and click the verification link',
    'Compila tutti i campi richiesti' => 'Fill in all required fields',
    'Sessione scaduta, ti preghiamo di rifare il login' => 'Session expired, please sign in again',
    'Si è verificato un errore durante l\'accesso. Riprova' => 'An error occurred during sign in. Please try again',
    'Logout effettuato con successo' => 'Logout successful',
    'Registrazione completata! Effettua l\'accesso' => 'Registration completed! Please sign in',

    // Auth: Register
    'Registrazione' => 'Registration',
    'Crea un nuovo account' => 'Create a new account',
    'Nome' => 'First name',
    'Mario' => 'John',
    'Cognome' => 'Last name',
    'Rossi' => 'Doe',
    'Email *' => 'Email *',
    'Telefono *' => 'Phone *',
    '+39 123 456 7890' => '+1 123 456 7890',
    'Indirizzo completo *' => 'Full address *',
    'Via, numero civico, città, CAP' => 'Street, number, city, ZIP code',
    'Data di nascita' => 'Date of birth',
    'Sesso' => 'Gender',
    '-- Seleziona --' => '-- Select --',
    'Maschio' => 'Male',
    'Femmina' => 'Female',
    'Altro' => 'Other',
    'Codice Fiscale' => 'Tax Code',
    'es. RSSMRA80A01H501U' => 'e.g. RSSMRA80A01H501U',
    'Opzionale' => 'Optional',
    'Accetto la' => 'I accept the',
    'Privacy Policy' => 'Privacy Policy',
    'La tua sessione è scaduta. Per motivi di sicurezza, ricarica la pagina e riprova' => 'Your session has expired. For security reasons, refresh the page and try again',
    'Errore durante la registrazione' => 'Registration error',
    'Errore di sicurezza, riprova' => 'Security error, try again',
    'Email già registrata' => 'Email already registered',
    'Account creato con successo! Verifica la tua email.' => 'Account created successfully! Check your email.',
    'Account creato! In attesa di approvazione da parte dell\'amministratore.' => 'Account created! Awaiting administrator approval.',
    'Le password non coincidono!' => 'Passwords do not match!',
    'La password deve essere lunga almeno 8 caratteri!' => 'Password must be at least 8 characters long!',
    'Hai già un account?' => 'Already have an account?',
    'Accedi' => 'Sign in',

    // Error 404
    'Pagina Non Trovata' => 'Page Not Found',
    'La pagina che stai cercando non esiste o è stata spostata.' => 'The page you are looking for does not exist or has been moved.',
    'Torna Indietro' => 'Go Back',
    'Vai alla Home' => 'Go to Home',
    'Catalogo' => 'Catalog',
    'Preferiti' => 'Favorites',
    'Prenotazioni' => 'Reservations',

    // Error 500
    'Errore del Server' => 'Server Error',
    'Ops, qualcosa è andato storto' => 'Oops, something went wrong',
    'Si è verificato un errore imprevisto sul server.' => 'An unexpected error occurred on the server.',
    'Il nostro team è stato notificato e sta lavorando per risolvere il problema.' => 'Our team has been notified and is working to resolve the issue.',
    'Prova a ricaricare la pagina o torna alla home. Se il problema persiste, riprova tra qualche minuto.' => 'Try reloading the page or go back to the home. If the problem persists, try again in a few minutes.',
    'Ricarica Pagina' => 'Reload Page',
    'Contatti' => 'Contact',
    'Supporto' => 'Support',

    // Archive (remaining strings)
    'Opere' => 'Works',
    'Pubblicazioni' => 'Publications',
    'Libri' => 'Books',
    'Disponibile' => 'Available',
    'Vedi dettagli' => 'View details',
    'Esplora Catalogo' => 'Explore Catalog',

    // Common placeholders
    '$1' => '$1', // Placeholder - keep as is
];

// Load existing translations
if (!file_exists($localeFile)) {
    die("Error: locale file not found at $localeFile\n");
}

$localeData = json_decode(file_get_contents($localeFile), true);
if (!is_array($localeData)) {
    die("Error: invalid JSON in locale file\n");
}

// Merge new translations
$updated = false;
foreach ($translations as $italian => $english) {
    if (!isset($localeData[$italian])) {
        $localeData[$italian] = $english;
        $updated = true;
        echo "  + Added: $italian => $english\n";
    }
}

// Save updated locale file (pretty print with proper escaping)
if ($updated) {
    $json = json_encode($localeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    file_put_contents($localeFile, $json . "\n");
    echo "\n✅ Updated locale/en_US.json with " . count(array_filter($translations, function($k) use ($localeData) {
        return !isset($localeData[$k]);
    }, ARRAY_FILTER_USE_KEY)) . " new translations\n\n";
} else {
    echo "\n✅ All translations already exist in locale/en_US.json\n\n";
}

// File modifications
$files = [
    'app/Views/profile/reservations.php' => [
        // Lines already translated - verify only
    ],
    'app/Views/user_dashboard/prenotazioni.php' => [
        // Attenzione: ... prestito(i) in ritardo
        ["<h3>Attenzione: <?= \$overdueCount; ?> prestito<?= \$overdueCount !== 1 ? 'i' : ''; ?> in ritardo</h3>",
         "<h3><?= __n('Attenzione: %d prestito in ritardo', 'Attenzione: %d prestiti in ritardo', \$overdueCount, \$overdueCount) ?></h3>"],

        ["<p>Hai libri che dovevano essere restituiti. Restituiscili al più presto per evitare sanzioni.</p>",
         "<p><?= __('Hai libri che dovevano essere restituiti. Restituiscili al più presto per evitare sanzioni.') ?></p>"],

        // In ritardo/Scadenza
        ["<span><?= \$isOverdue ? 'In ritardo' : 'Scadenza'; ?>: <?= \$scadenza ? date('d/m/Y', strtotime(\$scadenza)) : 'N/D'; ?></span>",
         "<span><?= \$isOverdue ? __('In ritardo') : __('Scadenza'); ?>: <?= \$scadenza ? date('d/m/Y', strtotime(\$scadenza)) : __('N/D'); ?></span>"],

        // Dal ...
        ["<span>Dal <?= date('d/m/Y', strtotime(\$startDate)); ?></span>",
         "<span><?= __('Dal') ?> <?= date('d/m/Y', strtotime(\$startDate)); ?></span>"],

        // Buttons
        ["<span><?= \$hasReview ? 'Già recensito' : 'Lascia una recensione'; ?></span>",
         "<span><?= \$hasReview ? __('Già recensito') : __('Lascia una recensione'); ?></span>"],

        // Status labels
        ["'restituito' => 'Restituito',",
         "'restituito' => __('Restituito'),"],
        ["'in_ritardo' => 'Restituito in ritardo',",
         "'in_ritardo' => __('Restituito in ritardo'),"],
        ["'perso' => 'Perso',",
         "'perso' => __('Perso'),"],
        ["'danneggiato' => 'Danneggiato',",
         "'danneggiato' => __('Danneggiato'),"],
        ["'prestato' => 'Prestato',",
         "'prestato' => __('Prestato'),"],
        ["'in_corso' => 'In corso',",
         "'in_corso' => __('In corso'),"],

        // Review status labels
        ["'pendente' => 'In attesa di approvazione',",
         "'pendente' => __('In attesa di approvazione'),"],
        ["'approvata' => 'Approvata',",
         "'approvata' => __('Approvata'),"],
        ["'rifiutata' => 'Rifiutata',",
         "'rifiutata' => __('Rifiutata'),"],

        // Modal
        ["Lascia una recensione",
         "<?= __('Lascia una recensione') ?>"],
        ["Chiudi",
         "<?= __('Chiudi') ?>"],
        ["Valutazione *",
         "<?= __('Valutazione *') ?>"],
        ["★★★★★ - Eccellente",
         "★★★★★ - <?= __('Eccellente') ?>"],
        ["★★★★☆ - Molto buono",
         "★★★★☆ - <?= __('Molto buono') ?>"],
        ["★★★☆☆ - Buono",
         "★★★☆☆ - <?= __('Buono') ?>"],
        ["★★☆☆☆ - Mediocre",
         "★★☆☆☆ - <?= __('Mediocre') ?>"],
        ["★☆☆☆☆ - Scarso",
         "★☆☆☆☆ - <?= __('Scarso') ?>"],
        ["Titolo (opzionale)",
         "<?= __('Titolo (opzionale)') ?>"],
        ["Recensione (opzionale)",
         "<?= __('Recensione (opzionale)') ?>"],
        ["Invia recensione",
         "<?= __('Invia recensione') ?>"],
        ["Seleziona la valutazione",
         "<?= __('Seleziona la valutazione') ?>"],
    ],
    'app/Views/auth/forgot-password.php' => [
        ["<h1 class=\"text-3xl font-bold text-gray-900 dark:text-white mb-2\">Recupera Password</h1>",
         "<h1 class=\"text-3xl font-bold text-gray-900 dark:text-white mb-2\"><?= __('Recupera Password') ?></h1>"],
        ["<p class=\"text-gray-600 dark:text-gray-400\">Inserisci la tua email per ricevere un link di reset</p>",
         "<p class=\"text-gray-600 dark:text-gray-400\"><?= __('Inserisci la tua email per ricevere un link di reset') ?></p>"],
        ["Email di recupero inviata con successo!",
         "<?= __('Email di recupero inviata con successo!') ?>"],
        ["Controlla la tua casella di posta e clicca sul link per resettare la password. Il link sarà valido per 2 ore.",
         "<?= __('Controlla la tua casella di posta e clicca sul link per resettare la password. Il link sarà valido per 2 ore.') ?>"],
        ["Email associata al tuo account",
         "<?= __('Email associata al tuo account') ?>"],
        ["Riceverai un link di reset via email. Il link sarà valido per 24 ore.",
         "<?= __('Riceverai un link di reset via email. Il link sarà valido per 24 ore.') ?>"],
        ["Invia link di reset",
         "<?= __('Invia link di reset') ?>"],
        ["Ricordi la password?",
         "<?= __('Ricordi la password?') ?>"],
    ],
    'app/Views/auth/forgot_password.php' => [
        ["Password dimenticata",
         "<?= __('Password dimenticata') ?>"],
        ["Inserisci la tua email per ricevere il link di reset.",
         "<?= __('Inserisci la tua email per ricevere il link di reset.') ?>"],
        ["Torna al login",
         "<?= __('Torna al login') ?>"],
        ["Invia link",
         "<?= __('Invia link') ?>"],
    ],
    'app/Views/auth/reset-password.php' => [
        ["<h1 class=\"text-3xl font-bold text-gray-900 dark:text-white mb-2\">Resetta Password</h1>",
         "<h1 class=\"text-3xl font-bold text-gray-900 dark:text-white mb-2\"><?= __('Resetta Password') ?></h1>"],
        ["<p class=\"text-gray-600 dark:text-gray-400\">Inserisci la tua nuova password</p>",
         "<p class=\"text-gray-600 dark:text-gray-400\"><?= __('Inserisci la tua nuova password') ?></p>"],
        ["Nuova Password",
         "<?= __('Nuova Password') ?>"],
        ["Conferma Password",
         "<?= __('Conferma Password') ?>"],
        ["Minimo 8 caratteri, con lettere maiuscole, minuscole e numeri",
         "<?= __('Minimo 8 caratteri, con lettere maiuscole, minuscole e numeri') ?>"],
        ["Password resettata con successo!",
         "<?= __('Password resettata con successo!') ?>"],
        ["Ora puoi accedere con la tua nuova password.",
         "<?= __('Ora puoi accedere con la tua nuova password.') ?>"],
        ["Resetta Password",
         "<?= __('Resetta Password') ?>"],
    ],
    'app/Views/auth/reset_password.php' => [
        ["Reimposta password",
         "<?= __('Reimposta password') ?>"],
        ["Scegli una nuova password per il tuo account.",
         "<?= __('Scegli una nuova password per il tuo account.') ?>"],
        ["Nuova password",
         "<?= __('Nuova password') ?>"],
        ["Conferma password",
         "<?= __('Conferma password') ?>"],
        ["Salva",
         "<?= __('Salva') ?>"],
    ],
    'app/Views/auth/register_success.php' => [
        ["<h1 class=\"text-3xl font-bold text-gray-900 dark:text-white mb-2\">Biblioteca</h1>",
         "<h1 class=\"text-3xl font-bold text-gray-900 dark:text-white mb-2\"><?= htmlspecialchars(\$appName ?? 'Biblioteca', ENT_QUOTES, 'UTF-8') ?></h1>"],
        ["<p class=\"text-gray-600 dark:text-gray-400\">Registrazione completata</p>",
         "<p class=\"text-gray-600 dark:text-gray-400\"><?= __('Registrazione completata') ?></p>"],
        ["<h2 class=\"text-2xl font-bold text-gray-900 dark:text-white mb-4\">Conferma la tua email</h2>",
         "<h2 class=\"text-2xl font-bold text-gray-900 dark:text-white mb-4\"><?= __('Conferma la tua email') ?></h2>"],
        ["Ti abbiamo inviato un'email con il link per confermare l'indirizzo.",
         "<?= __('Ti abbiamo inviato un\\'email con il link per confermare l\\'indirizzo.') ?>"],
        ["Dopo la conferma, un amministratore approverà la tua iscrizione.",
         "<?= __('Dopo la conferma, un amministratore approverà la tua iscrizione.') ?>"],
        ["Vai al login",
         "<?= __('Vai al login') ?>"],
    ],
    'app/Views/auth/login.php' => [
        ["<p class=\"text-gray-600\">Accedi al tuo account</p>",
         "<p class=\"text-gray-600\"><?= __('Accedi al tuo account') ?></p>"],
        ["Email o password non corretti. Verifica le credenziali e riprova",
         "<?= __('Email o password non corretti. Verifica le credenziali e riprova') ?>"],
        ["La tua sessione è scaduta. Per motivi di sicurezza, effettua nuovamente l'accesso",
         "<?= __('La tua sessione è scaduta. Per motivi di sicurezza, effettua nuovamente l\\'accesso') ?>"],
        ["Errore di sicurezza. Aggiorna la pagina e riprova",
         "<?= __('Errore di sicurezza. Aggiorna la pagina e riprova') ?>"],
        ["Il tuo account è stato sospeso. Contatta l'amministratore per maggiori informazioni",
         "<?= __('Il tuo account è stato sospeso. Contatta l\\'amministratore per maggiori informazioni') ?>"],
        ["Il tuo account è in attesa di approvazione. Riceverai un'email quando sarà attivato",
         "<?= __('Il tuo account è in attesa di approvazione. Riceverai un\\'email quando sarà attivato') ?>"],
        ["Email non verificata. Controlla la tua casella di posta e clicca sul link di verifica",
         "<?= __('Email non verificata. Controlla la tua casella di posta e clicca sul link di verifica') ?>"],
        ["Compila tutti i campi richiesti",
         "<?= __('Compila tutti i campi richiesti') ?>"],
        ["Sessione scaduta, ti preghiamo di rifare il login",
         "<?= __('Sessione scaduta, ti preghiamo di rifare il login') ?>"],
        ["Si è verificato un errore durante l'accesso. Riprova",
         "<?= __('Si è verificato un errore durante l\\'accesso. Riprova') ?>"],
        ["Logout effettuato con successo",
         "<?= __('Logout effettuato con successo') ?>"],
        ["Registrazione completata! Effettua l'accesso",
         "<?= __('Registrazione completata! Effettua l\\'accesso') ?>"],
        ["Email",
         "<?= __('Email') ?>"],
        ["Password",
         "<?= __('Password') ?>"],
        ["Ricordami",
         "<?= __('Ricordami') ?>"],
        ["Password dimenticata?",
         "<?= __('Password dimenticata?') ?>"],
        ["Accedi",
         "<?= __('Accedi') ?>"],
        ["<i class=\"fas fa-spinner fa-spin mr-2\"></i>Accesso in corso...",
         "<i class=\"fas fa-spinner fa-spin mr-2\"></i><?= __('Accesso in corso...') ?>"],
        ["Non hai un account?",
         "<?= __('Non hai un account?') ?>"],
        ["Registrati",
         "<?= __('Registrati') ?>"],
    ],
    'app/Views/auth/register.php' => [
        ["<p class=\"text-gray-600 dark:text-gray-400\">Crea un nuovo account</p>",
         "<p class=\"text-gray-600 dark:text-gray-400\"><?= __('Crea un nuovo account') ?></p>"],
        ["La tua sessione è scaduta. Per motivi di sicurezza, ricarica la pagina e riprova",
         "<?= __('La tua sessione è scaduta. Per motivi di sicurezza, ricarica la pagina e riprova') ?>"],
        ["Errore durante la registrazione",
         "<?= __('Errore durante la registrazione') ?>"],
        ["Errore di sicurezza, riprova",
         "<?= __('Errore di sicurezza, riprova') ?>"],
        ["Email già registrata",
         "<?= __('Email già registrata') ?>"],
        ["Account creato con successo! Verifica la tua email.",
         "<?= __('Account creato con successo! Verifica la tua email.') ?>"],
        ["Account creato! In attesa di approvazione da parte dell'amministratore.",
         "<?= __('Account creato! In attesa di approvazione da parte dell\\'amministratore.') ?>"],
        ["Nome",
         "<?= __('Nome') ?>"],
        ["Cognome",
         "<?= __('Cognome') ?>"],
        ["Email *",
         "<?= __('Email *') ?>"],
        ["Telefono *",
         "<?= __('Telefono *') ?>"],
        ["Indirizzo completo *",
         "<?= __('Indirizzo completo *') ?>"],
        ["Data di nascita",
         "<?= __('Data di nascita') ?>"],
        ["Sesso",
         "<?= __('Sesso') ?>"],
        ["Maschio",
         "<?= __('Maschio') ?>"],
        ["Femmina",
         "<?= __('Femmina') ?>"],
        ["Altro",
         "<?= __('Altro') ?>"],
        ["Codice Fiscale",
         "<?= __('Codice Fiscale') ?>"],
        ["Accetto la",
         "<?= __('Accetto la') ?>"],
        ["Hai già un account?",
         "<?= __('Hai già un account?') ?>"],
        ["alert(__('Le password non coincidono!'));",
         "alert(__('Le password non coincidono!'));"],
        ["alert(__('La password deve essere lunga almeno 8 caratteri!'));",
         "alert(__('La password deve essere lunga almeno 8 caratteri!'));"],
    ],
    'app/Views/errors/404.php' => [
        ["<h1 class=\"error-404-title\">Pagina Non Trovata</h1>",
         "<h1 class=\"error-404-title\"><?= __('Pagina Non Trovata') ?></h1>"],
        ["La pagina che stai cercando non esiste o è stata spostata.",
         "<?= __('La pagina che stai cercando non esiste o è stata spostata.') ?>"],
        ["Torna Indietro",
         "<?= __('Torna Indietro') ?>"],
        ["Vai alla Home",
         "<?= __('Vai alla Home') ?>"],
        ["<span>Catalogo</span>",
         "<span><?= __('Catalogo') ?></span>"],
        ["<span>Preferiti</span>",
         "<span><?= __('Preferiti') ?></span>"],
        ["<span>Prenotazioni</span>",
         "<span><?= __('Prenotazioni') ?></span>"],
    ],
    'app/Views/errors/500.php' => [
        ["<h1 class=\"error-500-title\">Ops, qualcosa è andato storto</h1>",
         "<h1 class=\"error-500-title\"><?= __('Ops, qualcosa è andato storto') ?></h1>"],
        ["Si è verificato un errore imprevisto sul server.",
         "<?= __('Si è verificato un errore imprevisto sul server.') ?>"],
        ["Il nostro team è stato notificato e sta lavorando per risolvere il problema.",
         "<?= __('Il nostro team è stato notificato e sta lavorando per risolvere il problema.') ?>"],
        ["Prova a ricaricare la pagina o torna alla home. Se il problema persiste, riprova tra qualche minuto.",
         "<?= __('Prova a ricaricare la pagina o torna alla home. Se il problema persiste, riprova tra qualche minuto.') ?>"],
        ["Ricarica Pagina",
         "<?= __('Ricarica Pagina') ?>"],
        ["<span>Catalogo</span>",
         "<span><?= __('Catalogo') ?></span>"],
        ["<span>Contatti</span>",
         "<span><?= __('Contatti') ?></span>"],
        ["<span>Supporto</span>",
         "<span><?= __('Supporto') ?></span>"],
    ],
    'app/Views/frontend/archive.php' => [
        ["                    Opere",
         "                    <?= __('Opere') ?>"],
        ["                    Pubblicazioni",
         "                    <?= __('Pubblicazioni') ?>"],
        ["                    Libri",
         "                    <?= __('Libri') ?>"],
        ["                                <?= (\$book['copie_disponibili'] > 0) ? 'Disponibile' : 'Prestato' ?>",
         "                                <?= (\$book['copie_disponibili'] > 0) ? __('Disponibile') : __('Prestato') ?>"],
        ["                                    <span>Vedi dettagli</span>",
         "                                    <span><?= __('Vedi dettagli') ?></span>"],
        ["                    <span>Esplora Catalogo</span>",
         "                    <span><?= __('Esplora Catalogo') ?></span>"],
    ],
];

echo "Applying translations to files...\n\n";

foreach ($files as $file => $replacements) {
    $filePath = $rootDir . '/' . $file;

    if (!file_exists($filePath)) {
        echo "⚠ SKIP: $file (not found)\n";
        continue;
    }

    $content = file_get_contents($filePath);
    $originalContent = $content;

    foreach ($replacements as [$search, $replace]) {
        $content = str_replace($search, $replace, $content);
    }

    if ($content !== $originalContent) {
        file_put_contents($filePath, $content);
        echo "  ✓ Updated: $file\n";
    } else {
        echo "  • No changes: $file (already translated)\n";
    }
}

echo "\n";
echo "═══════════════════════════════════════════════════════\n";
echo "  TRANSLATION COMPLETE\n";
echo "═══════════════════════════════════════════════════════\n";
echo "Files processed: " . count($files) . "\n";
echo "Translations added: " . count($translations) . "\n";
echo "\nNext steps:\n";
echo "1. Review modified files for correctness\n";
echo "2. Test pages in browser (Italian + English)\n";
echo "3. Run: grep -r \"__('\" app/Views/ | wc -l\n";
echo "4. Commit when verified\n";
echo "═══════════════════════════════════════════════════════\n";
