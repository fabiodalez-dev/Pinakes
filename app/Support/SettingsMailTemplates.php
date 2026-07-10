<?php
declare(strict_types=1);

namespace App\Support;

final class SettingsMailTemplates
{
    /**
     * Templates managed via the backend settings UI.
     *
     * Italian is the in-code base. For a non-Italian installation the subject
     * and body of each template are replaced with the translation shipped in
     * app/Support/mail_templates/<locale>.php. Passing $locale = null resolves
     * the installation locale, so every caller (seeding, admin UI, send-time
     * fallback) becomes locale-aware automatically.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(?string $locale = null): array
    {
        $templates = [
            'user_registration_pending' => [
                'label' => __('Registrazione ricevuta'),
                'description' => __("Inviata all'utente al termine della registrazione per confermare la ricezione e l'attesa di approvazione."),
                'subject' => 'Registrazione ricevuta - In attesa di approvazione',
                'placeholders' => ['nome', 'cognome', 'email', 'codice_tessera', 'data_registrazione', 'sezione_verifica'],
                'body' => <<<'HTML'
<h2>Benvenuto {{nome}} {{cognome}}!</h2>
<p>La tua richiesta di registrazione è stata ricevuta con successo.</p>
<p><strong>Dettagli account:</strong></p>
<ul>
    <li>Email: {{email}}</li>
    <li>Codice tessera: {{codice_tessera}}</li>
    <li>Data registrazione: {{data_registrazione}}</li>
</ul>
{{sezione_verifica}}
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
    <p><strong>⏳ Account in attesa di approvazione</strong></p>
    <p>Il tuo account è in attesa di approvazione da parte di un amministratore.
    Riceverai una email di conferma una volta che l'account sarà stato attivato.</p>
</div>
<p>Grazie per aver scelto Pinakes!</p>
HTML,
            ],
            'user_registration_verification' => [
                'label' => __('Registrazione ricevuta - verifica email'),
                'description' => __("Inviata quando l'account si attiverà automaticamente dopo la verifica dell'email."),
                'subject' => 'Registrazione ricevuta - Verifica la tua email',
                'placeholders' => ['nome', 'cognome', 'email', 'codice_tessera', 'data_registrazione', 'sezione_verifica'],
                'body' => <<<'HTML'
<h2>Benvenuto {{nome}} {{cognome}}!</h2>
<p>La tua registrazione è stata ricevuta con successo.</p>
<p><strong>Dettagli account:</strong></p>
<ul>
    <li>Email: {{email}}</li>
    <li>Codice tessera: {{codice_tessera}}</li>
    <li>Data registrazione: {{data_registrazione}}</li>
</ul>
{{sezione_verifica}}
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0;">
    <p><strong>Conferma il tuo indirizzo email</strong></p>
    <p>Dopo la verifica il tuo account sarà attivo e potrai accedere subito.</p>
</div>
<p>Grazie per aver scelto Pinakes!</p>
HTML,
            ],
            'user_account_approved' => [
                'label' => __('Account attivato'),
                'description' => __("Inviata all'utente quando un amministratore approva l'account."),
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
                'label' => __('Richiesta prestito'),
                'description' => __("Notifica agli amministratori quando viene inoltrata una nuova richiesta di prestito."),
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
                'label' => __('Wishlist disponibile'),
                'description' => __("Inviata agli utenti quando un libro nella wishlist torna disponibile. Il libro rimane nella wishlist ma non riceverà altre notifiche per lo stesso libro."),
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
<p><em>📝 Puoi rimuovere questo libro dalla tua wishlist quando vuoi.</em></p>
HTML,
            ],
            'loan_expiring_warning' => [
                'label' => __('Promemoria scadenza'),
                'description' => __("Promemoria agli utenti tre giorni prima della scadenza del prestito."),
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
                'label' => __('Prestito scaduto'),
                'description' => __("Notifica agli utenti quando il prestito è scaduto e deve essere restituito."),
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
                'label' => __('Alert prestito in ritardo (Admin)'),
                'description' => __("Avvisa gli amministratori quando un prestito entra in ritardo."),
                'subject' => 'Prestito #{{prestito_id}} in ritardo',
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
                'label' => __('Prestito approvato'),
                'description' => __("Inviata all'utente quando un amministratore approva una richiesta di prestito."),
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
            'loan_rejected' => [
                'label' => __('Prestito rifiutato'),
                'description' => __("Inviata all'utente quando un amministratore rifiuta una richiesta di prestito."),
                'subject' => '❌ La tua richiesta di prestito non è stata approvata',
                'placeholders' => ['utente_nome', 'libro_titolo', 'motivo_rifiuto'],
                'body' => <<<'HTML'
<h2>La tua richiesta di prestito non è stata approvata</h2>
<p>Ciao {{utente_nome}},</p>
<p>Ci dispiace informarti che la tua richiesta di prestito per il libro <strong>"{{libro_titolo}}"</strong> non è stata approvata.</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Motivo:</strong></p>
    <p>{{motivo_rifiuto}}</p>
</div>
<p>Se hai domande o desideri maggiori informazioni, non esitare a contattarci.</p>
<p>Cordiali saluti,<br>Il team della biblioteca</p>
HTML,
            ],
            'admin_new_review' => [
                'label' => __('Nuova recensione (Admin)'),
                'description' => __("Inviata agli amministratori quando viene ricevuta una nuova recensione da approvare."),
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
            'reservation_book_available' => [
                'label' => __('Libro prenotato disponibile'),
                'description' => __("Inviata quando un libro prenotato diventa disponibile e viene convertito in prestito pendente."),
                'subject' => '📚 Libro prenotato pronto per il ritiro!',
                'placeholders' => ['utente_nome', 'libro_titolo', 'libro_autore', 'libro_isbn', 'data_inizio', 'data_fine', 'book_url', 'profile_url'],
                'body' => <<<'HTML'
<h2>Il tuo libro è pronto per il ritiro!</h2>
<p>Ciao {{utente_nome}},</p>
<p>Siamo lieti di informarti che il libro che avevi prenotato è ora disponibile e pronto per il ritiro:</p>
<div style="background-color: #f0f9ff; padding: 20px; border-radius: 10px; border-left: 4px solid #3b82f6; margin: 20px 0;">
    <h3 style="color: #1e40af; margin: 0 0 10px 0;">{{libro_titolo}}</h3>
    <p style="margin: 5px 0;"><strong>Autore:</strong> {{libro_autore}}</p>
    <p style="margin: 5px 0;"><strong>ISBN:</strong> {{libro_isbn}}</p>
    <p style="margin: 5px 0;"><strong>Periodo prestito:</strong> {{data_inizio}} - {{data_fine}}</p>
</div>
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981;">
    <p><strong>📦 Prossimi passi</strong></p>
    <p>Recati in biblioteca per ritirare il libro. Porta con te un documento di identità.</p>
</div>
<p style="text-align: center;">
    <a href="{{book_url}}" style="background-color: #3b82f6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">📖 Vedi Libro</a>
    <a href="{{profile_url}}" style="background-color: #6b7280; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">👤 I miei Prestiti</a>
</p>
<p><em>La prenotazione è stata convertita in un prestito in attesa di conferma del ritiro.</em></p>
HTML,
            ],
            'loan_pickup_ready' => [
                'label' => __('Pronto per il ritiro'),
                'description' => __("Inviata quando un prestito è stato approvato e il libro è pronto per il ritiro."),
                'subject' => '📦 Libro pronto per il ritiro!',
                'placeholders' => ['utente_nome', 'libro_titolo', 'data_inizio', 'data_fine', 'giorni_prestito', 'scadenza_ritiro', 'pickup_instructions'],
                'body' => <<<'HTML'
<h2>Il tuo libro è pronto per il ritiro!</h2>
<p>Ciao {{utente_nome}},</p>
<p>Siamo lieti di informarti che la tua richiesta di prestito è stata <strong>approvata</strong> e il libro è pronto per il ritiro!</p>
<div style="background-color: #f0f9ff; padding: 20px; border-radius: 10px; border-left: 4px solid #3b82f6; margin: 20px 0;">
    <h3 style="color: #1e40af; margin: 0 0 10px 0;">{{libro_titolo}}</h3>
    <p style="margin: 5px 0;"><strong>Periodo prestito:</strong> {{data_inizio}} - {{data_fine}}</p>
    <p style="margin: 5px 0;"><strong>Durata:</strong> {{giorni_prestito}} giorni</p>
</div>
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #f59e0b;">
    <p><strong>⏰ Scadenza ritiro: {{scadenza_ritiro}}</strong></p>
    <p>Ritira il libro entro questa data, altrimenti il prestito verrà annullato automaticamente.</p>
</div>
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981;">
    <p><strong>📦 Come ritirare</strong></p>
    <p>{{pickup_instructions}}</p>
</div>
<p>Buona lettura!</p>
HTML,
            ],
            'loan_pickup_expired' => [
                'label' => __('Ritiro scaduto'),
                'description' => __("Inviata quando il tempo per ritirare un libro è scaduto e il prestito è stato annullato."),
                'subject' => '⏰ Tempo per il ritiro scaduto',
                'placeholders' => ['utente_nome', 'libro_titolo', 'scadenza_ritiro'],
                'body' => <<<'HTML'
<h2>Tempo per il ritiro scaduto</h2>
<p>Ciao {{utente_nome}},</p>
<p>Purtroppo non hai ritirato il libro entro il tempo previsto.</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Libro:</strong> {{libro_titolo}}</p>
    <p><strong>Scadenza ritiro:</strong> {{scadenza_ritiro}}</p>
</div>
<p>Il prestito è stato automaticamente annullato e il libro è stato reso disponibile per altri utenti.</p>
<p>Se desideri ancora questo libro, ti invitiamo a effettuare una nuova richiesta di prestito.</p>
<p>Cordiali saluti,<br>Il team della biblioteca</p>
HTML,
            ],
            'loan_pickup_cancelled' => [
                'label' => __('Ritiro annullato'),
                'description' => __("Inviata quando un ritiro viene annullato dall'amministratore."),
                'subject' => '❌ Ritiro annullato',
                'placeholders' => ['utente_nome', 'libro_titolo', 'motivo'],
                'body' => <<<'HTML'
<h2>Ritiro annullato</h2>
<p>Ciao {{utente_nome}},</p>
<p>Ti informiamo che il ritiro del seguente libro è stato annullato:</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Libro:</strong> {{libro_titolo}}</p>
    <p><strong>Motivo:</strong> {{motivo}}</p>
</div>
<p>Il libro è stato reso disponibile per altri utenti. Se desideri ancora questo libro, ti invitiamo a effettuare una nuova richiesta di prestito.</p>
<p>Cordiali saluti,<br>Il team della biblioteca</p>
HTML,
            ],
            'loan_returned' => [
                'label' => __('Restituzione confermata'),
                'description' => __("Inviata all'utente quando un prestito viene registrato come restituito."),
                'subject' => '✅ Restituzione confermata',
                'placeholders' => ['utente_nome', 'libro_titolo', 'data_restituzione'],
                'body' => <<<'HTML'
<h2>Restituzione confermata</h2>
<p>Ciao {{utente_nome}},</p>
<p>Confermiamo la restituzione del seguente libro. Grazie!</p>
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981;">
    <p><strong>Libro:</strong> {{libro_titolo}}</p>
    <p><strong>Data restituzione:</strong> {{data_restituzione}}</p>
</div>
<p>Speriamo che la lettura ti sia piaciuta. A presto in biblioteca!</p>
<p>Cordiali saluti,<br>Il team della biblioteca</p>
HTML,
            ],
            'reservation_expired' => [
                'label' => __('Prenotazione scaduta'),
                'description' => __("Inviata all'utente quando una prenotazione in coda scade automaticamente senza essere stata convertita in prestito."),
                'subject' => '⌛ Prenotazione scaduta',
                'placeholders' => ['utente_nome', 'libro_titolo', 'data_scadenza'],
                'body' => <<<'HTML'
<h2>Prenotazione scaduta</h2>
<p>Ciao {{utente_nome}},</p>
<p>La tua prenotazione per il seguente libro è scaduta ed è stata chiusa automaticamente:</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Libro:</strong> {{libro_titolo}}</p>
    <p><strong>Scaduta il:</strong> {{data_scadenza}}</p>
</div>
<p>Se sei ancora interessato, puoi effettuare una nuova prenotazione in qualsiasi momento.</p>
<p>Cordiali saluti,<br>Il team della biblioteca</p>
HTML,
            ],
            'reservation_cancelled' => [
                'label' => __('Prenotazione annullata'),
                'description' => __("Inviata all'utente quando una prenotazione viene annullata dall'amministrazione."),
                'subject' => '❌ Prenotazione annullata',
                'placeholders' => ['utente_nome', 'libro_titolo', 'motivo'],
                'body' => <<<'HTML'
<h2>Prenotazione annullata</h2>
<p>Ciao {{utente_nome}},</p>
<p>Ti informiamo che la tua prenotazione per il seguente libro è stata annullata:</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Libro:</strong> {{libro_titolo}}</p>
    <p><strong>Motivo:</strong> {{motivo}}</p>
</div>
<p>Se desideri ancora questo libro, puoi effettuare una nuova prenotazione in qualsiasi momento.</p>
<p>Cordiali saluti,<br>Il team della biblioteca</p>
HTML,
            ],
            'copy_unavailable_user' => [
                'label' => __('Copia non più disponibile'),
                'description' => __("Inviata all'utente quando la copia riservata per la sua prenotazione diventa indisponibile (persa o danneggiata)."),
                'subject' => 'ℹ️ Aggiornamento sulla tua prenotazione',
                'placeholders' => ['utente_nome', 'libro_titolo', 'motivo'],
                'body' => <<<'HTML'
<h2>Aggiornamento sulla tua prenotazione</h2>
<p>Ciao {{utente_nome}},</p>
<p>Ti informiamo che la copia riservata per la tua prenotazione del seguente libro non è più disponibile:</p>
<div style="background-color: #fffbeb; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #f59e0b;">
    <p><strong>Libro:</strong> {{libro_titolo}}</p>
    <p><strong>Motivo:</strong> {{motivo}}</p>
</div>
<p>Stiamo cercando di assegnarti un'altra copia appena possibile. Se non saranno disponibili altre copie, la tua prenotazione resterà in coda e ti avviseremo non appena il libro tornerà disponibile.</p>
<p>Ci scusiamo per il disagio.</p>
<p>Cordiali saluti,<br>Il team della biblioteca</p>
HTML,
            ],
            'user_password_setup' => [
                'label' => __('Imposta password'),
                'description' => __("Inviata ai nuovi utenti per impostare la password del loro account."),
                'subject' => '🔐 Imposta la tua password',
                'placeholders' => ['nome', 'cognome', 'app_name', 'reset_url'],
                'body' => <<<'HTML'
<h2>Imposta la tua password</h2>
<p>Ciao {{nome}} {{cognome}},</p>
<p>Il tuo account su <strong>{{app_name}}</strong> è stato creato. Per iniziare ad utilizzare il sistema, devi impostare la tua password.</p>
<div style="background-color: #f0f9ff; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #3b82f6;">
    <p><strong>🔑 Configura il tuo account</strong></p>
    <p>Clicca sul pulsante qui sotto per impostare la tua password:</p>
</div>
<p style="text-align: center; margin: 30px 0;">
    <a href="{{reset_url}}" style="background-color: #10b981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-size: 16px; display: inline-block;">🔐 Imposta Password</a>
</p>
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
    <p><strong>⏰ Importante</strong></p>
    <p>Il link è valido per 24 ore. Se scade, contatta un amministratore per riceverne uno nuovo.</p>
</div>
<p>Se non hai richiesto questa email, puoi ignorarla.</p>
HTML,
            ],
            'admin_invitation' => [
                'label' => __('Invito amministratore'),
                'description' => __("Inviata quando un utente viene invitato come amministratore."),
                'subject' => '🎉 Invito come Amministratore',
                'placeholders' => ['nome', 'cognome', 'app_name', 'reset_url', 'dashboard_url'],
                'body' => <<<'HTML'
<h2>Benvenuto nel team!</h2>
<p>Ciao {{nome}} {{cognome}},</p>
<p>Sei stato invitato come amministratore su <strong>{{app_name}}</strong>.</p>
<div style="background-color: #f0f9ff; padding: 20px; border-radius: 10px; border-left: 4px solid #3b82f6; margin: 20px 0;">
    <h3 style="color: #1e40af; margin: 0 0 10px 0;">Le tue credenziali</h3>
    <p>Come amministratore, avrai accesso a:</p>
    <ul>
        <li>Gestione catalogo libri</li>
        <li>Gestione utenti e prestiti</li>
        <li>Impostazioni del sistema</li>
        <li>Report e statistiche</li>
    </ul>
</div>
<p><strong>Per iniziare:</strong></p>
<ol>
    <li>Imposta la tua password cliccando il pulsante qui sotto</li>
    <li>Accedi al pannello di amministrazione</li>
</ol>
<p style="text-align: center; margin: 30px 0;">
    <a href="{{reset_url}}" style="background-color: #10b981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-size: 16px; display: inline-block; margin: 10px;">🔐 Imposta Password</a>
    <a href="{{dashboard_url}}" style="background-color: #3b82f6; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-size: 16px; display: inline-block; margin: 10px;">📊 Dashboard Admin</a>
</p>
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
    <p><strong>⏰ Importante</strong></p>
    <p>Il link per impostare la password è valido per 24 ore.</p>
</div>
<p>Benvenuto nel team!</p>
HTML,
            ],
            'admin_new_registration' => [
                'label' => __('Nuova registrazione (admin)'),
                'description' => __("Notifica agli amministratori quando un nuovo utente si registra e attende approvazione."),
                'subject' => '👤 Nuova richiesta di registrazione',
                'placeholders' => ['nome', 'cognome', 'email', 'codice_tessera', 'data_registrazione', 'admin_users_url'],
                'body' => <<<'HTML'
<h2>Nuova richiesta di registrazione</h2>
<p>Un nuovo utente ha richiesto l'accesso a Pinakes:</p>
<p><strong>Dettagli utente:</strong></p>
<ul>
    <li>Nome: {{nome}} {{cognome}}</li>
    <li>Email: {{email}}</li>
    <li>Codice tessera: {{codice_tessera}}</li>
    <li>Data richiesta: {{data_registrazione}}</li>
</ul>
<p><a href="{{admin_users_url}}" style="background-color: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Gestisci Utenti</a></p>
HTML,
            ],
        ];

        return self::applyLocaleOverrides($templates, $locale);
    }

    /**
     * Replace subject/body with the shipped translation for $locale. Italian
     * (or an unknown/missing locale) returns the base templates unchanged.
     *
     * @param array<string, array<string, mixed>> $templates
     * @return array<string, array<string, mixed>>
     */
    private static function applyLocaleOverrides(array $templates, ?string $locale): array
    {
        $overrides = self::localeOverrides($locale);
        foreach ($overrides as $name => $trans) {
            if (!isset($templates[$name])) {
                continue;
            }
            if (isset($trans['subject']) && $trans['subject'] !== '') {
                $templates[$name]['subject'] = (string) $trans['subject'];
            }
            if (isset($trans['body']) && $trans['body'] !== '') {
                $templates[$name]['body'] = (string) $trans['body'];
            }
        }
        return $templates;
    }

    /**
     * Load the per-locale subject/body overrides. Italian is the in-code base
     * and has no override file.
     *
     * @return array<string, array{subject?: string, body?: string}>
     */
    private static function localeOverrides(?string $locale): array
    {
        if ($locale === null || $locale === '') {
            $locale = \App\Support\I18n::getInstallationLocale();
        }
        $locale = str_replace('-', '_', (string) $locale);
        if ($locale === '' || str_starts_with($locale, 'it')) {
            return [];
        }
        // Map short and full codes to the locale files we ship.
        $map = ['en' => 'en_US', 'de' => 'de_DE', 'fr' => 'fr_FR', 'en_US' => 'en_US', 'de_DE' => 'de_DE', 'fr_FR' => 'fr_FR'];
        $key = $map[$locale] ?? ($map[substr($locale, 0, 2)] ?? null);
        if ($key === null) {
            return [];
        }
        $file = __DIR__ . '/mail_templates/' . $key . '.php';
        if (!is_file($file)) {
            return [];
        }
        $data = require $file;
        return is_array($data) ? $data : [];
    }

    public static function get(string $template, ?string $locale = null): ?array
    {
        $all = self::all($locale);
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
