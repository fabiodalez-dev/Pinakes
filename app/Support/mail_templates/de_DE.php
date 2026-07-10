<?php

// Auto-generated email-template translations for de_DE.
// Base (Italian) lives in app/Support/SettingsMailTemplates.php; this file
// overrides subject+body per template. Placeholders/HTML/emoji preserved.

return [
    'admin_invitation' => [
        'subject' => '🎉 Einladung als Administrator',
        'body' => '<h2>Willkommen im Team!</h2>
<p>Hallo {{nome}} {{cognome}},</p>
<p>Du wurdest als Administrator für <strong>{{app_name}}</strong> eingeladen.</p>
<div style="background-color: #f0f9ff; padding: 20px; border-radius: 10px; border-left: 4px solid #3b82f6; margin: 20px 0;">
    <h3 style="color: #1e40af; margin: 0 0 10px 0;">Deine Zugangsdaten</h3>
    <p>Als Administrator erhältst du Zugriff auf:</p>
    <ul>
        <li>Verwaltung des Buchkatalogs</li>
        <li>Verwaltung von Nutzern und Ausleihen</li>
        <li>Systemeinstellungen</li>
        <li>Berichte und Statistiken</li>
    </ul>
</div>
<p><strong>Um loszulegen:</strong></p>
<ol>
    <li>Lege dein Passwort fest, indem du auf die Schaltfläche unten klickst</li>
    <li>Melde dich im Administrationsbereich an</li>
</ol>
<p style="text-align: center; margin: 30px 0;">
    <a href="{{reset_url}}" style="background-color: #10b981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-size: 16px; display: inline-block; margin: 10px;">🔐 Passwort festlegen</a>
    <a href="{{dashboard_url}}" style="background-color: #3b82f6; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-size: 16px; display: inline-block; margin: 10px;">📊 Admin-Dashboard</a>
</p>
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
    <p><strong>⏰ Wichtig</strong></p>
    <p>Der Link zum Festlegen des Passworts ist 24 Stunden lang gültig.</p>
</div>
<p>Willkommen im Team!</p>',
    ],
    'admin_new_registration' => [
        'subject' => '👤 Neue Registrierungsanfrage',
        'body' => '<h2>Neue Registrierungsanfrage</h2>
<p>Ein neuer Benutzer hat Zugang zu Pinakes angefragt:</p>
<p><strong>Benutzerdetails:</strong></p>
<ul>
    <li>Name: {{nome}} {{cognome}}</li>
    <li>E-Mail: {{email}}</li>
    <li>Ausweisnummer: {{codice_tessera}}</li>
    <li>Anfragedatum: {{data_registrazione}}</li>
</ul>
<p><a href="{{admin_users_url}}" style="background-color: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Benutzer verwalten</a></p>',
    ],
    'admin_new_review' => [
        'subject' => '⭐ Neue Rezension zur Freigabe',
        'body' => '<h2>Neue Rezension zur Freigabe</h2>
<p>Für folgendes Buch ist eine neue Rezension eingegangen:</p>
<p><strong>Buch:</strong> {{libro_titolo}}</p>
<div style="background-color: #fff7ed; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #f59e0b;">
    <p><strong>Bewertung:</strong> {{stelle}} Sterne ⭐</p>
    <p><strong>Nutzer:</strong> {{utente_nome}} ({{utente_email}})</p>
    <p><strong>Datum der Rezension:</strong> {{data_recensione}}</p>
    <p><strong>Titel:</strong> {{titolo_recensione}}</p>
    <p><strong>Beschreibung:</strong></p>
    <p style="white-space: pre-line;">{{descrizione_recensione}}</p>
</div>
<p style="text-align: center;">
    <a href="{{link_approvazione}}" style="background-color: #10b981; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">Rezension verwalten</a>
</p>
<p><em>Um diese Rezension freizugeben oder abzulehnen, melden Sie sich im Admin-Bereich an.</em></p>',
    ],
    'copy_unavailable_user' => [
        'subject' => 'ℹ️ Update zu Ihrer Vormerkung',
        'body' => '<h2>Update zu Ihrer Vormerkung</h2>
<p>Hallo {{utente_nome}},</p>
<p>wir möchten Sie darüber informieren, dass das für Ihre Vormerkung reservierte Exemplar des folgenden Buchs nicht mehr verfügbar ist:</p>
<div style="background-color: #fffbeb; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #f59e0b;">
    <p><strong>Buch:</strong> {{libro_titolo}}</p>
    <p><strong>Grund:</strong> {{motivo}}</p>
</div>
<p>Wir sind bemüht, Ihnen so schnell wie möglich ein anderes Exemplar zuzuweisen. Sollten keine weiteren Exemplare verfügbar sein, bleibt Ihre Vormerkung in der Warteschlange bestehen, und wir benachrichtigen Sie, sobald das Buch wieder verfügbar ist.</p>
<p>Wir entschuldigen uns für die Unannehmlichkeiten.</p>
<p>Mit freundlichen Grüßen,<br>Ihr Bibliotheksteam</p>',
    ],
    'loan_approved' => [
        'subject' => '✅ Deine Ausleihanfrage wurde genehmigt!',
        'body' => '<h2>Deine Ausleihanfrage wurde genehmigt!</h2>
<p>Hallo {{utente_nome}},</p>
<p>wir freuen uns, dir mitzuteilen, dass deine Ausleihanfrage <strong>genehmigt</strong> wurde!</p>
<p><strong>Details zur Ausleihe:</strong></p>
<ul>
    <li>Buch: {{libro_titolo}}</li>
    <li>Beginn der Ausleihe: {{data_inizio}}</li>
    <li>Rückgabedatum: {{data_fine}}</li>
    <li>Dauer: {{giorni_prestito}} Tage</li>
</ul>
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981;">
    <p><strong>📦 Abholung des Buches</strong></p>
    <p>{{pickup_instructions}}</p>
</div>
<p><strong>Wichtig:</strong> Bitte denke daran, das Buch bis zum Rückgabedatum zurückzugeben. Du erhältst einige Tage vor Ablauf eine Erinnerung.</p>
<p>Viel Freude beim Lesen!</p>',
    ],
    'loan_expiring_warning' => [
        'subject' => '⚠️ Deine Ausleihe läuft bald ab',
        'body' => '<h2>Erinnerung: Ausleihfrist läuft ab</h2>
<p>Hallo {{utente_nome}},</p>
<p>wir möchten dich daran erinnern, dass deine Ausleihe bald abläuft:</p>
<ul>
    <li>Buch: {{libro_titolo}}</li>
    <li>Fälligkeitsdatum: {{data_scadenza}}</li>
    <li>Verbleibende Tage: {{giorni_rimasti}}</li>
</ul>
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
    <p><strong>⏰ Handlung erforderlich</strong></p>
    <p>Bitte gib das Buch bis zum Fälligkeitsdatum zurück oder kontaktiere uns für eine mögliche Verlängerung.</p>
</div>
<p>Vielen Dank für deine Mithilfe!</p>',
    ],
    'loan_overdue_admin' => [
        'subject' => 'Ausleihe #{{prestito_id}} überfällig',
        'body' => '<h2>Ausleihe überfällig</h2>
<p>Die Ausleihe <strong>#{{prestito_id}}</strong> hat den Status <strong>überfällig</strong> erreicht.</p>
<ul>
  <li><strong>Buch:</strong> {{libro_titolo}}</li>
  <li><strong>Nutzer:</strong> {{utente_nome}} ({{utente_email}})</li>
  <li><strong>Ausleihdatum:</strong> {{data_prestito}}</li>
  <li><strong>Fälligkeitsdatum:</strong> {{data_scadenza}}</li>
</ul>
<p>Bitte nehmen Sie Kontakt mit dem Nutzer auf und fordern Sie zur Rückgabe auf.</p>',
    ],
    'loan_overdue_notification' => [
        'subject' => '🚨 Ausleihe überfällig - Handlung erforderlich',
        'body' => '<h2>Ausleihe überfällig</h2>
<p>Hallo {{utente_nome}},</p>
<p>Deine Ausleihe ist überfällig und muss umgehend zurückgegeben werden:</p>
<ul>
    <li>Buch: {{libro_titolo}}</li>
    <li>Fälligkeitsdatum: {{data_scadenza}}</li>
    <li>Tage im Verzug: {{giorni_ritardo}}</li>
</ul>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>❗️ Achtung</strong></p>
    <p>Wird das Buch nicht zurückgegeben, kann dies zur Sperrung deines Kontos und zu Gebühren führen.</p>
</div>
<p>Bitte gib das Buch so schnell wie möglich zurück.</p>',
    ],
    'loan_pickup_cancelled' => [
        'subject' => '❌ Abholung storniert',
        'body' => '<h2>Abholung storniert</h2>
<p>Hallo {{utente_nome}},</p>
<p>wir möchten dich darüber informieren, dass die Abholung des folgenden Buchs storniert wurde:</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Buch:</strong> {{libro_titolo}}</p>
    <p><strong>Grund:</strong> {{motivo}}</p>
</div>
<p>Das Buch wurde wieder für andere Nutzer verfügbar gemacht. Falls du dieses Buch weiterhin ausleihen möchtest, kannst du gerne eine neue Ausleihanfrage stellen.</p>
<p>Mit freundlichen Grüßen,<br>Das Bibliotheksteam</p>',
    ],
    'loan_pickup_expired' => [
        'subject' => '⏰ Abholfrist abgelaufen',
        'body' => '<h2>Abholfrist abgelaufen</h2>
<p>Hallo {{utente_nome}},</p>
<p>Leider hast du das Buch nicht innerhalb der vorgesehenen Frist abgeholt.</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Buch:</strong> {{libro_titolo}}</p>
    <p><strong>Abholfrist:</strong> {{scadenza_ritiro}}</p>
</div>
<p>Die Ausleihe wurde automatisch storniert und das Buch steht nun wieder für andere Nutzer zur Verfügung.</p>
<p>Falls du dieses Buch weiterhin ausleihen möchtest, kannst du gerne eine neue Ausleihanfrage stellen.</p>
<p>Mit freundlichen Grüßen,<br>Dein Bibliotheksteam</p>',
    ],
    'loan_pickup_ready' => [
        'subject' => '📦 Ihr Buch ist abholbereit!',
        'body' => '<h2>Dein Buch ist abholbereit!</h2>
<p>Hallo {{utente_nome}},</p>
<p>Wir freuen uns, dir mitteilen zu können, dass deine Ausleihanfrage <strong>genehmigt</strong> wurde und das Buch nun zur Abholung bereitliegt!</p>
<div style="background-color: #f0f9ff; padding: 20px; border-radius: 10px; border-left: 4px solid #3b82f6; margin: 20px 0;">
    <h3 style="color: #1e40af; margin: 0 0 10px 0;">{{libro_titolo}}</h3>
    <p style="margin: 5px 0;"><strong>Ausleihzeitraum:</strong> {{data_inizio}} - {{data_fine}}</p>
    <p style="margin: 5px 0;"><strong>Dauer:</strong> {{giorni_prestito}} Tage</p>
</div>
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #f59e0b;">
    <p><strong>⏰ Abholfrist: {{scadenza_ritiro}}</strong></p>
    <p>Bitte hole das Buch bis zu diesem Datum ab, andernfalls wird die Ausleihe automatisch storniert.</p>
</div>
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981;">
    <p><strong>📦 So funktioniert die Abholung</strong></p>
    <p>{{pickup_instructions}}</p>
</div>
<p>Viel Freude beim Lesen!</p>',
    ],
    'loan_rejected' => [
        'subject' => '❌ Deine Ausleihanfrage wurde nicht genehmigt',
        'body' => '<h2>Deine Ausleihanfrage wurde nicht genehmigt</h2>
<p>Hallo {{utente_nome}},</p>
<p>Wir müssen dir leider mitteilen, dass deine Ausleihanfrage für das Buch <strong>"{{libro_titolo}}"</strong> nicht genehmigt wurde.</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Grund:</strong></p>
    <p>{{motivo_rifiuto}}</p>
</div>
<p>Bei Fragen oder wenn du weitere Informationen wünschst, zögere nicht, uns zu kontaktieren.</p>
<p>Mit freundlichen Grüßen,<br>Dein Bibliotheksteam</p>',
    ],
    'loan_request_notification' => [
        'subject' => '📚 Neue Ausleihanfrage',
        'body' => '<h2>Neue Ausleihanfrage</h2>
<p>Es ist eine neue Ausleihanfrage eingegangen:</p>
<p><strong>Details:</strong></p>
<ul>
    <li>Buch: {{libro_titolo}}</li>
    <li>Benutzer: {{utente_nome}} ({{utente_email}})</li>
    <li>Gewünschtes Startdatum: {{data_inizio}}</li>
    <li>Gewünschtes Enddatum: {{data_fine}}</li>
    <li>Datum der Anfrage: {{data_richiesta}}</li>
</ul>
<p><a href="{{approve_url}}" style="background-color: #10b981; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Anfrage verwalten</a></p>',
    ],
    'loan_returned' => [
        'subject' => '✅ Rückgabe bestätigt',
        'body' => '<h2>Rückgabe bestätigt</h2>
<p>Hallo {{utente_nome}},</p>
<p>wir bestätigen die Rückgabe des folgenden Buches. Vielen Dank!</p>
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981;">
    <p><strong>Buch:</strong> {{libro_titolo}}</p>
    <p><strong>Rückgabedatum:</strong> {{data_restituzione}}</p>
</div>
<p>Wir hoffen, die Lektüre hat dir gefallen. Bis bald in der Bibliothek!</p>
<p>Mit freundlichen Grüßen,<br>Dein Bibliotheksteam</p>',
    ],
    'reservation_book_available' => [
        'subject' => '📚 Vorgemerktes Buch bereit zur Abholung!',
        'body' => '<h2>Dein Buch ist bereit zur Abholung!</h2>
<p>Hallo {{utente_nome}},</p>
<p>wir freuen uns, dir mitteilen zu können, dass das von dir vorgemerkte Buch jetzt verfügbar und bereit zur Abholung ist:</p>
<div style="background-color: #f0f9ff; padding: 20px; border-radius: 10px; border-left: 4px solid #3b82f6; margin: 20px 0;">
    <h3 style="color: #1e40af; margin: 0 0 10px 0;">{{libro_titolo}}</h3>
    <p style="margin: 5px 0;"><strong>Autor:</strong> {{libro_autore}}</p>
    <p style="margin: 5px 0;"><strong>ISBN:</strong> {{libro_isbn}}</p>
    <p style="margin: 5px 0;"><strong>Ausleihzeitraum:</strong> {{data_inizio}} - {{data_fine}}</p>
</div>
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981;">
    <p><strong>📦 Nächste Schritte</strong></p>
    <p>Komm bitte in die Bibliothek, um das Buch abzuholen. Bring einen Ausweis mit.</p>
</div>
<p style="text-align: center;">
    <a href="{{book_url}}" style="background-color: #3b82f6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">📖 Buch ansehen</a>
    <a href="{{profile_url}}" style="background-color: #6b7280; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">👤 Meine Ausleihen</a>
</p>
<p><em>Die Vormerkung wurde in eine Ausleihe umgewandelt, die auf die Bestätigung der Abholung wartet.</em></p>',
    ],
    'reservation_cancelled' => [
        'subject' => '❌ Reservierung storniert',
        'body' => '<h2>Reservierung storniert</h2>
<p>Hallo {{utente_nome}},</p>
<p>wir möchten dich darüber informieren, dass deine Reservierung für folgendes Buch storniert wurde:</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Buch:</strong> {{libro_titolo}}</p>
    <p><strong>Grund:</strong> {{motivo}}</p>
</div>
<p>Wenn du dieses Buch weiterhin möchtest, kannst du jederzeit eine neue Reservierung vornehmen.</p>
<p>Mit freundlichen Grüßen,<br>Dein Bibliotheksteam</p>',
    ],
    'reservation_expired' => [
        'subject' => '⌛ Reservierung abgelaufen',
        'body' => '<h2>Reservierung abgelaufen</h2>
<p>Hallo {{utente_nome}},</p>
<p>Deine Reservierung für das folgende Buch ist abgelaufen und wurde automatisch beendet:</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Buch:</strong> {{libro_titolo}}</p>
    <p><strong>Abgelaufen am:</strong> {{data_scadenza}}</p>
</div>
<p>Falls du noch Interesse hast, kannst du jederzeit eine neue Reservierung vornehmen.</p>
<p>Mit freundlichen Grüßen,<br>Dein Bibliotheksteam</p>',
    ],
    'user_account_approved' => [
        'subject' => 'Konto genehmigt - Willkommen in der Bibliothek!',
        'body' => '<h2>Dein Konto wurde genehmigt!</h2>
<p>Hallo {{nome}} {{cognome}},</p>
<p>wir freuen uns, dir mitteilen zu können, dass dein Konto von einem Administrator genehmigt wurde.</p>
<p>Du kannst dich jetzt im System anmelden und mit dem Vormerken von Büchern beginnen!</p>
<p><strong>Details zu deinem Konto:</strong></p>
<ul>
    <li>E-Mail: {{email}}</li>
    <li>Ausweisnummer: {{codice_tessera}}</li>
</ul>
<p><a href="{{login_url}}" style="background-color: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Jetzt anmelden</a></p>
<p>Willkommen in unserer digitalen Bibliothek!</p>',
    ],
    'user_password_setup' => [
        'subject' => '🔐 Richte dein Passwort ein',
        'body' => '<h2>Richte dein Passwort ein</h2>
<p>Hallo {{nome}} {{cognome}},</p>
<p>Dein Konto bei <strong>{{app_name}}</strong> wurde erstellt. Um das System nutzen zu können, musst du zunächst dein Passwort einrichten.</p>
<div style="background-color: #f0f9ff; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #3b82f6;">
    <p><strong>🔑 Richte dein Konto ein</strong></p>
    <p>Klicke auf die Schaltfläche unten, um dein Passwort festzulegen:</p>
</div>
<p style="text-align: center; margin: 30px 0;">
    <a href="{{reset_url}}" style="background-color: #10b981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-size: 16px; display: inline-block;">🔐 Passwort festlegen</a>
</p>
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
    <p><strong>⏰ Wichtig</strong></p>
    <p>Der Link ist 24 Stunden lang gültig. Sollte er abgelaufen sein, wende dich bitte an einen Administrator, um einen neuen zu erhalten.</p>
</div>
<p>Falls du diese E-Mail nicht angefordert hast, kannst du sie einfach ignorieren.</p>',
    ],
    'user_registration_pending' => [
        'subject' => 'Registrierung eingegangen – Warten auf Freigabe',
        'body' => '<h2>Willkommen {{nome}} {{cognome}}!</h2>
<p>Ihre Registrierungsanfrage wurde erfolgreich empfangen.</p>
<p><strong>Kontodetails:</strong></p>
<ul>
    <li>E-Mail: {{email}}</li>
    <li>Ausweisnummer: {{codice_tessera}}</li>
    <li>Registrierungsdatum: {{data_registrazione}}</li>
</ul>
{{sezione_verifica}}
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
    <p><strong>⏳ Konto wartet auf Freigabe</strong></p>
    <p>Ihr Konto wartet derzeit auf die Freigabe durch einen Administrator.
    Sobald es aktiviert wurde, erhalten Sie eine Bestätigungs-E-Mail.</p>
</div>
<p>Vielen Dank, dass Sie sich für Pinakes entschieden haben!</p>',
    ],
    'wishlist_book_available' => [
        'subject' => '📖 Buch auf deiner Wunschliste jetzt verfügbar!',
        'body' => '<h2>Gute Neuigkeiten! 📚</h2>
<p>Hallo {{utente_nome}},</p>
<p>Das Buch, das du zu deiner Wunschliste hinzugefügt hast, ist jetzt zur Ausleihe verfügbar:</p>
<ul>
    <li><strong>Titel:</strong> {{libro_titolo}}</li>
    <li><strong>Autor:</strong> {{libro_autore}}</li>
    <li><strong>ISBN:</strong> {{libro_isbn}}</li>
    <li><strong>Verfügbar ab:</strong> {{data_disponibilita}}</li>
</ul>
<div style="background: #ecfdf5; border-radius: 8px; padding: 16px; margin: 20px 0;">
    <p style="margin: 0 0 8px 0;">📍 Das Buch ist jetzt sofort ausleihbar.</p>
    <p style="margin: 0;">⏰ Wir empfehlen, es gleich zu reservieren, bevor es vergriffen ist!</p>
</div>
<p style="text-align: center;">
    <a href="{{book_url}}" style="background-color: #10b981; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">📚 Jetzt reservieren</a>
    <a href="{{wishlist_url}}" style="background-color: #6b7280; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">❤️ Wunschliste verwalten</a>
</p>
<p><em>📝 Du kannst dieses Buch jederzeit von deiner Wunschliste entfernen.</em></p>',
    ],
];
