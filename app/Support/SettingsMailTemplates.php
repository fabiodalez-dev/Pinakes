<?php
declare(strict_types=1);

namespace App\Support;

final class SettingsMailTemplates
{
    /**
     * Templates managed via the backend settings UI.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'user_registration_pending' => [
                'label' => 'Registrazione ricevuta',
                'description' => "Inviata all'utente al termine della registrazione per confermare la ricezione e l'attesa di approvazione.",
                'subject' => 'Registrazione ricevuta - In attesa di approvazione',
                'placeholders' => ['nome', 'cognome', 'email', 'codice_tessera', 'data_registrazione'],
                'body' => <<<'HTML'
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
    Riceverai una email di conferma una volta che l'account sarà stato attivato.</p>
</div>
<p>Grazie per aver scelto il nostro sistema biblioteca!</p>
HTML,
            ],
            'user_account_approved' => [
                'label' => 'Account attivato',
                'description' => "Inviata all'utente quando un amministratore approva l'account.",
                'subject' => 'Account approvato - Benvenuto in biblioteca!',
                'placeholders' => ['nome', 'cognome', 'email', 'codice_tessera', 'login_url'],
                'body' => <<<'HTML'
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
HTML,
            ],
            'loan_request_notification' => [
                'label' => 'Richiesta prestito',
                'description' => "Notifica agli amministratori quando viene inoltrata una nuova richiesta di prestito.",
                'subject' => '📚 Nuova richiesta di prestito',
                'placeholders' => ['libro_titolo', 'utente_nome', 'utente_email', 'data_inizio', 'data_fine', 'data_richiesta', 'approve_url'],
                'body' => <<<'HTML'
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
HTML,
            ],
            'wishlist_book_available' => [
                'label' => 'Wishlist disponibile',
                'description' => "Inviata agli utenti quando un libro nella wishlist torna disponibile. Il libro viene automaticamente rimosso dalla wishlist dopo l'invio della notifica per evitare duplicati.",
                'subject' => '📖 Libro della tua wishlist ora disponibile!',
                'placeholders' => ['utente_nome', 'libro_titolo', 'libro_autore', 'libro_isbn', 'data_disponibilita', 'book_url', 'wishlist_url'],
                'body' => <<<'HTML'
<h2>Buone notizie! 📚</h2>
<p>Ciao {{utente_nome}},</p>
<p>Il libro che hai aggiunto alla tua wishlist è ora disponibile per il prestito:</p>
<ul>
    <li><strong>Titolo:</strong> {{libro_titolo}}</li>
    <li><strong>Autore:</strong> {{libro_autore}}</li>
    <li><strong>ISBN:</strong> {{libro_isbn}}</li>
    <li><strong>Data disponibilità:</strong> {{data_disponibilita}}</li>
</ul>
<div style="background: #ecfdf5; border-radius: 8px; padding: 16px; margin: 20px 0;">
    <p style="margin: 0 0 8px 0;">📍 Il libro è ora disponibile per il prestito immediato.</p>
    <p style="margin: 0;">⏰ Ti consigliamo di prenotarlo subito prima che vada esaurito!</p>
</div>
<p style="text-align: center;">
    <a href="{{book_url}}" style="background-color: #10b981; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">📚 Prenota ora</a>
    <a href="{{wishlist_url}}" style="background-color: #6b7280; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">❤️ Gestisci wishlist</a>
</p>
<p><em>📝 Questo libro è stato automaticamente rimosso dalla tua wishlist per evitare notifiche duplicate.</em></p>
HTML,
            ],
            'loan_expiring_warning' => [
                'label' => 'Promemoria scadenza',
                'description' => "Promemoria agli utenti tre giorni prima della scadenza del prestito.",
                'subject' => '⚠️ Il tuo prestito sta per scadere',
                'placeholders' => ['utente_nome', 'libro_titolo', 'data_scadenza', 'giorni_rimasti'],
                'body' => <<<'HTML'
<h2>Promemoria scadenza prestito</h2>
<p>Ciao {{utente_nome}},</p>
<p>Ti ricordiamo che il tuo prestito sta per scadere:</p>
<ul>
    <li>Libro: {{libro_titolo}}</li>
    <li>Data scadenza: {{data_scadenza}}</li>
    <li>Giorni rimasti: {{giorni_rimasti}}</li>
</ul>
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
    <p><strong>⏰ Azione richiesta</strong></p>
    <p>Restituisci il libro entro la data di scadenza o contattaci per un eventuale rinnovo.</p>
</div>
<p>Grazie per la collaborazione!</p>
HTML,
            ],
            'loan_overdue_notification' => [
                'label' => 'Prestito scaduto',
                'description' => "Notifica agli utenti quando il prestito è scaduto e deve essere restituito.",
                'subject' => '🚨 Prestito scaduto - Azione richiesta',
                'placeholders' => ['utente_nome', 'libro_titolo', 'data_scadenza', 'giorni_ritardo'],
                'body' => <<<'HTML'
<h2>Prestito scaduto</h2>
<p>Ciao {{utente_nome}},</p>
<p>Il tuo prestito è scaduto e deve essere restituito immediatamente:</p>
<ul>
    <li>Libro: {{libro_titolo}}</li>
    <li>Data scadenza: {{data_scadenza}}</li>
    <li>Giorni di ritardo: {{giorni_ritardo}}</li>
</ul>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>❗️ Attenzione</strong></p>
    <p>Il mancato rientro del libro potrebbe comportare la sospensione del tuo account e penali.</p>
</div>
<p>Ti chiediamo di restituire il libro il prima possibile.</p>
HTML,
            ],
            'loan_overdue_admin' => [
                'label' => 'Alert prestito in ritardo (Admin)',
                'description' => "Avvisa gli amministratori quando un prestito entra in ritardo.",
                'subject' => 'Prestito #{prestito_id} in ritardo',
                'placeholders' => ['prestito_id','libro_titolo','utente_nome','utente_email','data_prestito','data_scadenza'],
                'body' => <<<'HTML'
<h2>Prestito in ritardo</h2>
<p>Il prestito <strong>#{{prestito_id}}</strong> è entrato nello stato <strong>in ritardo</strong>.</p>
<ul>
  <li><strong>Libro:</strong> {{libro_titolo}}</li>
  <li><strong>Utente:</strong> {{utente_nome}} ({{utente_email}})</li>
  <li><strong>Data prestito:</strong> {{data_prestito}}</li>
  <li><strong>Data scadenza:</strong> {{data_scadenza}}</li>
</ul>
<p>Intervieni per contattare l'utente e sollecitare la restituzione.</p>
HTML,
            ],
            'loan_approved' => [
                'label' => 'Prestito approvato',
                'description' => "Inviata all'utente quando un amministratore approva una richiesta di prestito.",
                'subject' => '✅ La tua richiesta di prestito è stata approvata!',
                'placeholders' => ['utente_nome', 'libro_titolo', 'data_inizio', 'data_fine', 'giorni_prestito', 'pickup_instructions'],
                'body' => <<<'HTML'
<h2>La tua richiesta di prestito è stata approvata!</h2>
<p>Ciao {{utente_nome}},</p>
<p>Siamo lieti di informarti che la tua richiesta di prestito è stata <strong>approvata</strong>!</p>
<p><strong>Dettagli del prestito:</strong></p>
<ul>
    <li>Libro: {{libro_titolo}}</li>
    <li>Data inizio prestito: {{data_inizio}}</li>
    <li>Data scadenza: {{data_fine}}</li>
    <li>Durata: {{giorni_prestito}} giorni</li>
</ul>
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981;">
    <p><strong>📦 Ritiro del libro</strong></p>
    <p>{{pickup_instructions}}</p>
</div>
<p><strong>Importante:</strong> Ricorda di restituire il libro entro la data di scadenza. Riceverai un promemoria alcuni giorni prima della scadenza.</p>
<p>Buona lettura!</p>
HTML,
            ],
            'admin_new_review' => [
                'label' => 'Nuova recensione (Admin)',
                'description' => "Inviata agli amministratori quando viene ricevuta una nuova recensione da approvare.",
                'subject' => '⭐ Nuova recensione da approvare',
                'placeholders' => ['libro_titolo', 'utente_nome', 'utente_email', 'stelle', 'titolo_recensione', 'descrizione_recensione', 'data_recensione', 'link_approvazione'],
                'body' => <<<'HTML'
<h2>Nuova recensione da approvare</h2>
<p>È stata ricevuta una nuova recensione per il libro:</p>
<p><strong>Libro:</strong> {{libro_titolo}}</p>
<div style="background-color: #fff7ed; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #f59e0b;">
    <p><strong>Valutazione:</strong> {{stelle}} stelle ⭐</p>
    <p><strong>Utente:</strong> {{utente_nome}} ({{utente_email}})</p>
    <p><strong>Data recensione:</strong> {{data_recensione}}</p>
    <p><strong>Titolo:</strong> {{titolo_recensione}}</p>
    <p><strong>Descrizione:</strong></p>
    <p style="white-space: pre-line;">{{descrizione_recensione}}</p>
</div>
<p style="text-align: center;">
    <a href="{{link_approvazione}}" style="background-color: #10b981; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">Gestisci Recensione</a>
</p>
<p><em>Per approvare o rifiutare questa recensione, accedi al pannello admin.</em></p>
HTML,
            ],
        ];
    }

    public static function get(string $template): ?array
    {
        $all = self::all();
        return $all[$template] ?? null;
    }

    /**
     * @return string[]
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
