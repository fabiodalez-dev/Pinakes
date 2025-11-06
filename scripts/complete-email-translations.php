<?php
/**
 * Complete English translations for remaining 4 email templates
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$db = new mysqli(
    $_ENV['DB_HOST'] ?? 'localhost',
    $_ENV['DB_USER'] ?? 'root',
    $_ENV['DB_PASS'] ?? '',
    $_ENV['DB_NAME'] ?? 'biblioteca'
);

if ($db->connect_error) {
    die("❌ Connection failed: " . $db->connect_error . "\n");
}

$db->set_charset('utf8mb4');

echo "🚀 Completing English translations for 4 remaining templates...\n\n";

// Complete translations
$translations = [
    'admin_new_registration' => [
        'subject' => '👤 New User Registration Request',
        'body_replacements' => [
            'Nuova richiesta di registrazione' => 'New Registration Request',
            'Un nuovo utente ha richiesto l\'accesso al sistema biblioteca:' => 'A new user has requested access to the library system:',
            'Dettagli utente:' => 'User Details:',
            'Nome' => 'Name',
            'Email' => 'Email',
            'Codice tessera' => 'Card Code',
            'Data registrazione' => 'Registration Date',
            'Telefono' => 'Phone',
            'Indirizzo' => 'Address',
            'Data di nascita' => 'Date of Birth',
            'Codice fiscale' => 'Tax Code',
            '⚠️ Azione richiesta' => '⚠️ Action Required',
            'Accedi al pannello amministrativo per approvare o rifiutare la richiesta.' => 'Access the admin panel to approve or reject the request.',
            'Gestisci Utenti' => 'Manage Users',
            'Questa email è stata inviata automaticamente dal sistema.' => 'This email was sent automatically by the system.'
        ]
    ],
    'loan_overdue_admin' => [
        'subject' => 'Loan #{prestito_id} Overdue',
        'body_replacements' => [
            'Prestito in ritardo' => 'Overdue Loan',
            'Il prestito' => 'Loan',
            'è entrato nello stato' => 'has entered status',
            'in ritardo' => 'overdue',
            'Libro' => 'Book',
            'Utente' => 'User',
            'Data prestito' => 'Loan Date',
            'Data scadenza' => 'Due Date',
            'Giorni di ritardo' => 'Days Overdue',
            '⚠️ Azione suggerita' => '⚠️ Suggested Action',
            'Contatta l\'utente per organizzare la restituzione del libro.' => 'Contact the user to arrange the return of the book.',
            'Verifica lo stato dei prestiti' => 'Check loan status',
            'Gestisci Prestiti' => 'Manage Loans',
            'Questa email è stata inviata automaticamente dal sistema.' => 'This email was sent automatically by the system.'
        ]
    ],
    'loan_approved' => [
        'subject' => '✅ Your Loan Request Has Been Approved!',
        'body_replacements' => [
            'La tua richiesta di prestito è stata approvata!' => 'Your Loan Request Has Been Approved!',
            'Ciao' => 'Hello',
            'Siamo lieti di informarti che la tua richiesta di prestito è stata' => 'We are pleased to inform you that your loan request has been',
            'approvata' => 'approved',
            'Dettagli del prestito:' => 'Loan Details:',
            'Libro' => 'Book',
            'Data inizio prestito' => 'Loan Start Date',
            'Data scadenza' => 'Due Date',
            'Durata prestito' => 'Loan Duration',
            'giorni' => 'days',
            '✅ Prossimi passi' => '✅ Next Steps',
            'Il libro è ora disponibile per il ritiro presso la biblioteca.' => 'The book is now available for pickup at the library.',
            'Ricordati di restituire il libro entro la data di scadenza per evitare penali.' => 'Remember to return the book by the due date to avoid penalties.',
            'Accedi al tuo profilo' => 'Access your profile',
            'Il Mio Profilo' => 'My Profile',
            'Contatta la biblioteca' => 'Contact the library',
            'Grazie per aver utilizzato il nostro servizio!' => 'Thank you for using our service!'
        ]
    ],
    'admin_new_review' => [
        'subject' => '⭐ New Review to Approve',
        'body_replacements' => [
            'Nuova recensione da approvare' => 'New Review to Approve',
            'È stata ricevuta una nuova recensione per il libro:' => 'A new review has been received for the book:',
            'Libro' => 'Book',
            'Valutazione' => 'Rating',
            'stelle su 5' => 'stars out of 5',
            'Recensione' => 'Review',
            'Recensito da' => 'Reviewed by',
            'Data recensione' => 'Review Date',
            '⚠️ Azione richiesta' => '⚠️ Action Required',
            'Accedi al pannello amministrativo per approvare o rifiutare la recensione.' => 'Access the admin panel to approve or reject the review.',
            'Gestisci Recensioni' => 'Manage Reviews',
            'Questa email è stata inviata automaticamente dal sistema.' => 'This email was sent automatically by the system.'
        ]
    ]
];

$updated = 0;

foreach ($translations as $name => $translation) {
    // Get Italian template
    $stmt = $db->prepare("SELECT body FROM email_templates WHERE name = ? AND locale = 'it_IT'");
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $subject = $translation['subject'];
        $body = $row['body'];

        // Apply translations
        foreach ($translation['body_replacements'] as $it => $en) {
            $body = str_replace($it, $en, $body);
        }

        // Update English template
        $updateStmt = $db->prepare("UPDATE email_templates SET subject = ?, body = ? WHERE name = ? AND locale = 'en_US'");
        $updateStmt->bind_param('sss', $subject, $body, $name);

        if ($updateStmt->execute()) {
            echo "✓ Updated: {$name} (en_US)\n";
            $updated++;
        } else {
            echo "❌ Failed: {$name} - " . $updateStmt->error . "\n";
        }

        $updateStmt->close();
    }

    $stmt->close();
}

echo "\n✅ Completed! Updated {$updated} templates.\n";

// Re-export to data.sql
echo "\n📋 Re-exporting to installer/database/data.sql...\n";

$dataFile = __DIR__ . '/../installer/database/data.sql';
$content = file_get_contents($dataFile);

// Remove old email_templates section
$pattern = '/-- Email Templates.*?(?=-- |$)/s';
$content = preg_replace($pattern, '', $content);

// Get all templates
$result = $db->query("SELECT * FROM email_templates ORDER BY name, locale");
$templates = [];
while ($row = $result->fetch_assoc()) {
    $templates[] = $row;
}

// Generate INSERT statements
$inserts = [];
foreach ($templates as $tpl) {
    $id = (int)$tpl['id'];
    $name = $db->real_escape_string($tpl['name']);
    $locale = $db->real_escape_string($tpl['locale']);
    $subject = $db->real_escape_string($tpl['subject']);
    $body = $db->real_escape_string($tpl['body']);
    $description = $tpl['description'] ? "'" . $db->real_escape_string($tpl['description']) . "'" : 'NULL';
    $active = (int)$tpl['active'];

    $inserts[] = "INSERT INTO `email_templates` VALUES ($id,'$name','$locale','$subject','$body',$description,$active,NULL,NULL);";
}

// Add section to data.sql
$newSection = "\n-- Email Templates (Multi-language)\n";
$newSection .= implode("\n", $inserts);
$newSection .= "\n\n";

// Append at the end
$content .= $newSection;

file_put_contents($dataFile, $content);

echo "✓ Exported " . count($templates) . " templates to data.sql\n";
echo "\n✅ All translations complete!\n";

$db->close();
