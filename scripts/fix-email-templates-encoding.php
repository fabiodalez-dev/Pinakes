<?php
/**
 * Fix Email Templates Encoding
 *
 * This script re-inserts all email templates with proper UTF-8 encoding
 * to fix charset mismatch issues (Ã¨ → è, ðŸ"§ → 📧, etc.)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Database connection
$db = new mysqli(
    $_ENV['DB_HOST'],
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    $_ENV['DB_NAME']
);

if ($db->connect_error) {
    die("❌ Connection failed: " . $db->connect_error);
}

// Set UTF-8 encoding
$db->set_charset('utf8mb4');
$db->query("SET NAMES utf8mb4");
$db->query("SET CHARACTER SET utf8mb4");

echo "🔧 Fixing Email Templates Encoding...\n\n";

// All email templates with proper UTF-8 characters
$templates = [
    'user_registration_pending' => [
        'subject' => 'Registrazione ricevuta - In attesa di approvazione',
        'body' => '
            <h2>Benvenuto {{nome}} {{cognome}}!</h2>
            <p>La tua richiesta di registrazione è stata ricevuta con successo.</p>
            <p><strong>Dettagli account:</strong></p>
            <ul>
                <li>Email: {{email}}</li>
                <li>Codice tessera: {{codice_tessera}}</li>
                <li>Data registrazione: {{data_registrazione}}</li>
            </ul>
            <div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
                <p><strong>⏳ Account in attesa di approvazione</strong></p>
                <p>Il tuo account è in attesa di approvazione da parte di un amministratore.
                Riceverai una email di conferma una volta che l\'account sarà stato attivato.</p>
            </div>
            <p>Grazie per aver scelto il nostro sistema biblioteca!</p>
        ',
        'description' => 'Email inviata all\'utente dopo la registrazione, quando l\'account è in attesa di approvazione.'
    ],
    'user_account_approved' => [
        'subject' => 'Account approvato - Benvenuto in biblioteca!',
        'body' => '
            <h2>Il tuo account è stato approvato!</h2>
            <p>Ciao {{nome}} {{cognome}},</p>
            <p>Siamo lieti di informarti che il tuo account è stato approvato da un amministratore.</p>
            <p>Ora puoi accedere al sistema e iniziare a prenotare libri!</p>
            <p><strong>Dettagli del tuo account:</strong></p>
            <ul>
                <li>Email: {{email}}</li>
                <li>Codice tessera: {{codice_tessera}}</li>
            </ul>
            <p><a href="{{login_url}}" style="background-color: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Accedi ora</a></p>
            <p>Benvenuto nella nostra biblioteca digitale!</p>
        ',
        'description' => 'Email inviata quando un admin approva direttamente l\'account senza richiedere verifica email.'
    ],
    'user_activation_with_verification' => [
        'subject' => 'Attiva il tuo account - Verifica email',
        'body' => '
            <h2>Il tuo account è stato approvato!</h2>
            <p>Ciao {{nome}} {{cognome}},</p>
            <p>Siamo lieti di informarti che il tuo account è stato approvato da un amministratore.</p>
            <p>📧 <strong>Verifica la tua email</strong></p>
            <p>Per completare l\'attivazione, clicca sul pulsante qui sotto per verificare il tuo indirizzo email:</p>
            <p style="text-align: center; margin: 30px 0;">
                <a href="{{verification_url}}" style="background-color: #10b981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-size: 16px; display: inline-block;">✅ Verifica Email</a>
            </p>
            <p><strong>Dettagli del tuo account:</strong></p>
            <ul>
                <li>Email: {{email}}</li>
                <li>Codice tessera: {{codice_tessera}}</li>
            </ul>
            <div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
                <p><strong>⏰ Importante</strong></p>
                <p>Il link di verifica è valido per 7 giorni. Dopo la verifica potrai accedere al sistema e prenotare libri.</p>
            </div>
            <p>Se non hai richiesto questa registrazione, puoi ignorare questa email.</p>
        ',
        'description' => 'Email inviata quando un admin approva l\'account richiedendo la verifica email.'
    ],
    'loan_request_notification' => [
        'subject' => '📚 Nuova richiesta di prestito',
        'body' => '
            <h2>Nuova richiesta di prestito</h2>
            <p>È stata ricevuta una nuova richiesta di prestito:</p>
            <p><strong>Dettagli:</strong></p>
            <ul>
                <li>Libro: {{libro_titolo}}</li>
                <li>Utente: {{utente_nome}} ({{utente_email}})</li>
                <li>Data richiesta inizio: {{data_inizio}}</li>
                <li>Data richiesta fine: {{data_fine}}</li>
                <li>Data richiesta: {{data_richiesta}}</li>
            </ul>
            <p><a href="{{approve_url}}" style="background-color: #10b981; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Gestisci Richiesta</a></p>
        ',
        'description' => 'Email inviata agli admin quando un utente richiede un prestito.'
    ],
    'loan_expiring_warning' => [
        'subject' => '⚠️ Il tuo prestito sta per scadere',
        'body' => '
            <h2>Promemoria scadenza prestito</h2>
            <p>Ciao {{utente_nome}},</p>
            <p>Ti ricordiamo che il tuo prestito sta per scadere:</p>
            <p><strong>Dettagli prestito:</strong></p>
            <ul>
                <li>Libro: {{libro_titolo}}</li>
                <li>Data scadenza: {{data_scadenza}}</li>
                <li>Giorni rimasti: {{giorni_rimasti}}</li>
            </ul>
            <div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
                <p><strong>⏰ Azione richiesta</strong></p>
                <p>Per evitare penali, restituisci il libro entro la data di scadenza o contatta la biblioteca per un eventuale rinnovo.</p>
            </div>
            <p>Grazie per la collaborazione!</p>
        ',
        'description' => 'Email inviata all\'utente 3 giorni prima della scadenza del prestito.'
    ],
    'loan_overdue_notification' => [
        'subject' => '🚨 Prestito scaduto - Azione richiesta',
        'body' => '
            <h2>Prestito scaduto</h2>
            <p>Ciao {{utente_nome}},</p>
            <p>Il tuo prestito è scaduto e deve essere restituito immediatamente:</p>
            <p><strong>Dettagli prestito:</strong></p>
            <ul>
                <li>Libro: {{libro_titolo}}</li>
                <li>Data scadenza: {{data_scadenza}}</li>
                <li>Giorni di ritardo: {{giorni_ritardo}}</li>
            </ul>
            <div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
                <p><strong>🚨 Azione urgente richiesta</strong></p>
                <p>Il libro deve essere restituito immediatamente. Il ritardo nella restituzione può comportare sanzioni e la sospensione del servizio.</p>
            </div>
            <p>Contatta immediatamente la biblioteca per risolvere la situazione.</p>
        ',
        'description' => 'Email inviata all\'utente quando il prestito è scaduto.'
    ],
    'admin_new_registration' => [
        'subject' => '👤 Nuova richiesta di registrazione',
        'body' => '
            <h2>Nuova richiesta di registrazione</h2>
            <p>Un nuovo utente ha richiesto l\'accesso al sistema biblioteca:</p>
            <p><strong>Dettagli utente:</strong></p>
            <ul>
                <li>Nome: {{nome}} {{cognome}}</li>
                <li>Email: {{email}}</li>
                <li>Codice tessera: {{codice_tessera}}</li>
                <li>Data richiesta: {{data_registrazione}}</li>
            </ul>
            <p><a href="{{admin_users_url}}" style="background-color: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Gestisci Utenti</a></p>
        ',
        'description' => 'Email inviata agli admin quando un nuovo utente si registra.'
    ],
    'wishlist_book_available' => [
        'subject' => '📖 Libro della tua wishlist ora disponibile!',
        'body' => '
            <h2>Buone notizie!</h2>
            <p>Ciao {{utente_nome}},</p>
            <p>Il libro che hai aggiunto alla tua wishlist è ora disponibile per il prestito:</p>
            <div style="background-color: #f0f9ff; padding: 20px; border-radius: 10px; border-left: 4px solid #3b82f6; margin: 20px 0;">
                <h3 style="color: #1e40af; margin: 0 0 10px 0;">{{libro_titolo}}</h3>
                <p style="margin: 5px 0;"><strong>Autore:</strong> {{libro_autore}}</p>
                <p style="margin: 5px 0;"><strong>ISBN:</strong> {{libro_isbn}}</p>
                <p style="margin: 5px 0;"><strong>Disponibile da:</strong> {{data_disponibilita}}</p>
            </div>
            <div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0;">
                <p><strong>✨ Prenota subito!</strong></p>
                <p>Il libro è ora disponibile per la prenotazione. Affrettati prima che qualcun altro lo prenoti!</p>
            </div>
            <p style="text-align: center;">
                <a href="{{book_url}}" style="background-color: #10b981; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">📚 Prenota Ora</a>
                <a href="{{wishlist_url}}" style="background-color: #6b7280; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">❤️ Gestisci Wishlist</a>
            </p>
            <p><em>Questo libro è stato automaticamente rimosso dalla tua wishlist.</em></p>
        ',
        'description' => 'Email inviata quando un libro della wishlist diventa disponibile.'
    ]
];

echo "📋 Templates da inserire: " . count($templates) . "\n\n";

// Delete all existing templates
echo "🗑️  Eliminando template esistenti...\n";
$db->query("DELETE FROM email_templates");
echo "✓ Template esistenti eliminati\n\n";

// Insert templates with UTF-8
$stmt = $db->prepare("
    INSERT INTO email_templates (name, subject, body, description, active)
    VALUES (?, ?, ?, ?, 1)
    ON DUPLICATE KEY UPDATE
        subject = VALUES(subject),
        body = VALUES(body),
        description = VALUES(description),
        updated_at = CURRENT_TIMESTAMP
");

$inserted = 0;
$failed = 0;

foreach ($templates as $name => $data) {
    $stmt->bind_param('ssss', $name, $data['subject'], $data['body'], $data['description']);

    if ($stmt->execute()) {
        echo "✓ Inserito: {$name}\n";
        $inserted++;
    } else {
        echo "✗ Errore: {$name} - " . $stmt->error . "\n";
        $failed++;
    }
}

$stmt->close();
$db->close();

echo "\n";
echo "═══════════════════════════════════════\n";
echo "✅ Completato!\n";
echo "═══════════════════════════════════════\n";
echo "Templates inseriti: {$inserted}\n";
echo "Template falliti: {$failed}\n";
echo "\n";
echo "🎉 Encoding email templates fixato!\n";
echo "   Tutti i caratteri speciali (è, à, emoji, ecc.) \n";
echo "   saranno ora visualizzati correttamente.\n";
