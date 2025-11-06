<?php
/**
 * Migration Script: Add i18n support to email templates
 *
 * This script:
 * 1. Adds 'locale' column to email_templates table
 * 2. Updates existing templates to locale='it_IT'
 * 3. Duplicates all templates for locale='en_US' with English translations
 * 4. Exports templates to installer/database/data.sql
 *
 * Usage: php scripts/migrate-email-templates-i18n.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Database connection
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

echo "🚀 Starting email templates i18n migration...\n\n";

// Step 1: Check if locale column already exists
echo "📋 Step 1: Checking table structure...\n";
$result = $db->query("SHOW COLUMNS FROM email_templates LIKE 'locale'");
$localeExists = $result && $result->num_rows > 0;

if ($localeExists) {
    echo "✓ Column 'locale' already exists\n";
} else {
    echo "➕ Adding 'locale' column...\n";

    // Add locale column
    $db->query("ALTER TABLE `email_templates` ADD COLUMN `locale` VARCHAR(10) NOT NULL DEFAULT 'it_IT' AFTER `name`");

    // Drop old unique key
    $db->query("ALTER TABLE `email_templates` DROP INDEX `name`");

    // Add composite unique key
    $db->query("ALTER TABLE `email_templates` ADD UNIQUE KEY `name_locale` (`name`, `locale`)");

    // Update existing templates
    $db->query("UPDATE `email_templates` SET `locale` = 'it_IT' WHERE `locale` = 'it_IT'");

    echo "✓ Column 'locale' added successfully\n";
}

// Step 2: Check if English templates exist
echo "\n📋 Step 2: Checking for English templates...\n";
$result = $db->query("SELECT COUNT(*) as count FROM email_templates WHERE locale = 'en_US'");
$row = $result->fetch_assoc();
$enTemplatesExist = $row['count'] > 0;

if ($enTemplatesExist) {
    echo "✓ English templates already exist ({$row['count']} templates)\n";
} else {
    echo "➕ Creating English templates...\n";

    // Get all Italian templates
    $result = $db->query("SELECT * FROM email_templates WHERE locale = 'it_IT' ORDER BY id");
    $templates = [];
    while ($row = $result->fetch_assoc()) {
        $templates[] = $row;
    }

    echo "  Found " . count($templates) . " Italian templates to translate\n";

    // English translations map
    $translations = [
        'user_registration_pending' => [
            'subject' => 'Registration Received - Pending Approval',
            'body_replacements' => [
                'Benvenuto' => 'Welcome',
                'La tua richiesta di registrazione è stata ricevuta con successo.' => 'Your registration request has been received successfully.',
                'Dettagli account:' => 'Account details:',
                'Email' => 'Email',
                'Codice tessera' => 'Card code',
                'Data registrazione' => 'Registration date',
                '⏳ Account in attesa di approvazione' => '⏳ Account pending approval',
                'Il tuo account è in attesa di approvazione da parte di un amministratore.' => 'Your account is pending approval by an administrator.',
                'Riceverai una email di conferma una volta che l\'account sarà stato attivato.' => 'You will receive a confirmation email once your account has been activated.',
                'Grazie per aver scelto il nostro sistema biblioteca!' => 'Thank you for choosing our library system!'
            ]
        ],
        'user_account_approved' => [
            'subject' => 'Account Approved - Welcome to the Library!',
            'body_replacements' => [
                'Il tuo account è stato approvato!' => 'Your account has been approved!',
                'Ciao' => 'Hello',
                'Siamo lieti di informarti che il tuo account è stato approvato da un amministratore.' => 'We are pleased to inform you that your account has been approved by an administrator.',
                'Ora puoi accedere al sistema e iniziare a prenotare libri!' => 'You can now access the system and start booking books!',
                'Dettagli del tuo account:' => 'Your account details:',
                'Codice tessera' => 'Card code',
                'Accedi ora' => 'Login Now',
                'Benvenuto nella nostra biblioteca digitale!' => 'Welcome to our digital library!'
            ]
        ],
        'user_activation_with_verification' => [
            'subject' => 'Activate Your Account - Verify Email',
            'body_replacements' => [
                'Il tuo account è stato approvato!' => 'Your account has been approved!',
                'Ciao' => 'Hello',
                'Il tuo account è stato approvato da un amministratore.' => 'Your account has been approved by an administrator.',
                'Prima di poter accedere, devi verificare il tuo indirizzo email cliccando sul pulsante qui sotto:' => 'Before you can log in, you must verify your email address by clicking the button below:',
                'Verifica Email' => 'Verify Email',
                'Questo link scadrà tra 7 giorni.' => 'This link will expire in 7 days.',
                'Una volta verificato, potrai accedere al sistema e iniziare a prenotare libri!' => 'Once verified, you can access the system and start booking books!',
                'Dettagli del tuo account:' => 'Your account details:',
                'Codice tessera' => 'Card code',
                'Se non hai richiesto questa verifica, ignora questa email.' => 'If you did not request this verification, ignore this email.'
            ]
        ],
        'loan_request_notification' => [
            'subject' => '📚 New Loan Request',
            'body_replacements' => [
                'Nuova richiesta di prestito' => 'New Loan Request',
                'È stata ricevuta una nuova richiesta di prestito:' => 'A new loan request has been received:',
                'Dettagli:' => 'Details:',
                'Libro' => 'Book',
                'Utente' => 'User',
                'Data richiesta inizio' => 'Requested start date',
                'Data richiesta fine' => 'Requested end date',
                'Data richiesta' => 'Request date',
                'Gestisci Richiesta' => 'Manage Request'
            ]
        ],
        'loan_expiring_warning' => [
            'subject' => '⚠️ Your Loan is About to Expire',
            'body_replacements' => [
                'Promemoria scadenza prestito' => 'Loan Expiration Reminder',
                'Ciao' => 'Hello',
                'Ti ricordiamo che il tuo prestito sta per scadere:' => 'We remind you that your loan is about to expire:',
                'Dettagli prestito:' => 'Loan details:',
                'Libro' => 'Book',
                'Data scadenza' => 'Expiration date',
                'Giorni rimasti' => 'Days remaining',
                '⏰ Azione richiesta' => '⏰ Action Required',
                'Per evitare penali, restituisci il libro entro la data di scadenza o contatta la biblioteca per un eventuale rinnovo.' => 'To avoid penalties, return the book by the due date or contact the library for a possible renewal.',
                'Grazie per la collaborazione!' => 'Thank you for your cooperation!'
            ]
        ],
        'loan_overdue_notification' => [
            'subject' => '🚨 Overdue Loan - Action Required',
            'body_replacements' => [
                'Prestito scaduto' => 'Overdue Loan',
                'Ciao' => 'Hello',
                'Il tuo prestito è scaduto e deve essere restituito immediatamente:' => 'Your loan is overdue and must be returned immediately:',
                'Dettagli prestito:' => 'Loan details:',
                'Libro' => 'Book',
                'Data scadenza' => 'Due date',
                'Giorni di ritardo' => 'Days overdue',
                '🚨 Azione urgente richiesta' => '🚨 Urgent Action Required',
                'Il libro deve essere restituito immediatamente. Il ritardo nella restituzione può comportare sanzioni e la sospensione del servizio.' => 'The book must be returned immediately. Late returns may result in penalties and suspension of service.',
                'Contatta immediatamente la biblioteca per risolvere la situazione.' => 'Contact the library immediately to resolve this situation.'
            ]
        ],
        'wishlist_book_available' => [
            'subject' => '📖 Book from Your Wishlist Now Available!',
            'body_replacements' => [
                'Buone notizie!' => 'Good News!',
                'Ciao' => 'Hello',
                'Il libro che hai aggiunto alla tua wishlist è ora disponibile per il prestito:' => 'The book you added to your wishlist is now available for loan:',
                'Autore' => 'Author',
                'Disponibile da' => 'Available from',
                '✨ Prenota subito!' => '✨ Book Now!',
                'Il libro è ora disponibile per la prenotazione. Affrettati prima che qualcun altro lo prenoti!' => 'The book is now available for booking. Hurry before someone else books it!',
                'Prenota Ora' => 'Book Now',
                'Gestisci Wishlist' => 'Manage Wishlist',
                'Questo libro è stato automaticamente rimosso dalla tua wishlist.' => 'This book has been automatically removed from your wishlist.'
            ]
        ],
        'reservation_book_available' => [
            'subject' => '📚 Your Reserved Book is Now Available!',
            'body_replacements' => [
                'Buone notizie!' => 'Good News!',
                'Ciao' => 'Hello',
                'Il libro che hai prenotato è ora disponibile per il prestito!' => 'The book you reserved is now available for loan!',
                'Autore' => 'Author',
                'Periodo richiesto' => 'Requested period',
                '✅ Cosa fare ora?' => '✅ What to Do Now?',
                'Il libro è stato automaticamente prenotato per te. Puoi:' => 'The book has been automatically reserved for you. You can:',
                'Venire a ritirare il libro' => 'Come to pick up the book',
                'Accedere al tuo account per confermare i dettagli' => 'Access your account to confirm details',
                'Contattare la biblioteca per organizzare il ritiro' => 'Contact the library to arrange pickup',
                'Vedi Dettagli Libro' => 'View Book Details',
                'Il Mio Profilo' => 'My Profile',
                '⏰ Nota importante' => '⏰ Important Note',
                'Hai 3 giorni di tempo per ritirare il libro prima che venga offerto al prossimo in coda.' => 'You have 3 days to pick up the book before it is offered to the next person in line.',
                'Grazie per la pazienza!' => 'Thank you for your patience!'
            ]
        ]
    ];

    // Insert English templates
    $stmt = $db->prepare("INSERT INTO email_templates (name, locale, subject, body, description, active) VALUES (?, 'en_US', ?, ?, ?, ?)");

    foreach ($templates as $template) {
        $name = $template['name'];
        $translation = $translations[$name] ?? null;

        if ($translation) {
            // Translate subject
            $subject = $translation['subject'];

            // Translate body
            $body = $template['body'];
            foreach ($translation['body_replacements'] as $it => $en) {
                $body = str_replace($it, $en, $body);
            }

            $active = (int)$template['active'];
            $stmt->bind_param('ssssi', $name, $subject, $body, $template['description'], $active);
            $stmt->execute();

            echo "  ✓ Created: {$name} (en_US)\n";
        } else {
            echo "  ⚠ No translation for: {$name} (using Italian as placeholder)\n";
            $active = (int)$template['active'];
            $stmt->bind_param('ssssi', $name, $template['subject'], $template['body'], $template['description'], $active);
            $stmt->execute();
        }
    }

    $stmt->close();
    echo "✓ English templates created successfully\n";
}

// Step 3: Export to data.sql
echo "\n📋 Step 3: Exporting templates to installer/database/data.sql...\n";

$dataFile = __DIR__ . '/../installer/database/data.sql';
if (!file_exists($dataFile)) {
    die("❌ File not found: $dataFile\n");
}

// Read current data.sql
$content = file_get_contents($dataFile);

// Find and remove old email_templates section
$pattern = '/INSERT INTO `email_templates`.*?(?=INSERT INTO|$)/s';
$content = preg_replace($pattern, '', $content);

// Get all templates
$result = $db->query("SELECT * FROM email_templates ORDER BY name, locale");
$templates = [];
while ($row = $result->fetch_assoc()) {
    $templates[] = $row;
}

// Generate new INSERT statements
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

// Add new section to data.sql
$newSection = "\n-- Email Templates (Multi-language)\n";
$newSection .= implode("\n", $inserts);
$newSection .= "\n\n";

// Find a good insertion point (before the last INSERT or at the end)
if (preg_match('/\n(INSERT INTO `[^`]+`[^;]+;)\s*$/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
    $insertPos = $matches[0][1];
    $content = substr($content, 0, $insertPos) . $newSection . substr($content, $insertPos);
} else {
    $content .= $newSection;
}

// Write back to file
file_put_contents($dataFile, $content);

echo "✓ Exported " . count($templates) . " templates to data.sql\n";

// Summary
echo "\n📊 Summary:\n";
$result = $db->query("SELECT locale, COUNT(*) as count FROM email_templates GROUP BY locale");
while ($row = $result->fetch_assoc()) {
    echo "  • {$row['locale']}: {$row['count']} templates\n";
}

echo "\n✅ Migration completed successfully!\n";
echo "\n📝 Next steps:\n";
echo "  1. Update EmailService::getEmailTemplate() to accept \$locale parameter\n";
echo "  2. Update all sendTemplate() calls to pass I18n::getLocale()\n";
echo "  3. Test email sending in both languages\n";

$db->close();
