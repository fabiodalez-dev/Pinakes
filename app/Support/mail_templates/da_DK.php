<?php

// Auto-generated email-template translations for da_DK.
// Base (Italian) lives in app/Support/SettingsMailTemplates.php; this file
// overrides subject+body per template. Placeholders/HTML/emoji preserved.
// Kept in sync with installer/database/data_da_DK.sql (same Danish texts).

return [
    'admin_invitation' => [
        'subject' => '🎉 Invitation som Administrator',
        'body' => '<h2>Velkommen til teamet!</h2>
<p>Hej {{nome}} {{cognome}},</p>
<p>Du er blevet inviteret som administrator på <strong>{{app_name}}</strong>.</p>
<div style="background-color: #f0f9ff; padding: 20px; border-radius: 10px; border-left: 4px solid #3b82f6; margin: 20px 0;">
    <h3 style="color: #1e40af; margin: 0 0 10px 0;">Dine adgangsrettigheder</h3>
    <p>Som administrator vil du have adgang til:</p>
    <ul>
        <li>Administration af bogkatalog</li>
        <li>Administration af brugere og lån</li>
        <li>Systemindstillinger</li>
        <li>Rapporter og statistik</li>
    </ul>
</div>
<p><strong>Sådan kommer du i gang:</strong></p>
<ol>
    <li>Opret din adgangskode ved at klikke på knappen nedenfor</li>
    <li>Log ind på administrationspanelet</li>
</ol>
<p style="text-align: center; margin: 30px 0;">
    <a href="{{reset_url}}" style="background-color: #10b981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-size: 16px; display: inline-block; margin: 10px;">🔐 Opret Adgangskode</a>
    <a href="{{dashboard_url}}" style="background-color: #3b82f6; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-size: 16px; display: inline-block; margin: 10px;">📊 Admin Dashboard</a>
</p>
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
    <p><strong>⏰ Vigtigt</strong></p>
    <p>Linket til oprettelse af adgangskode er gyldigt i 24 timer.</p>
</div>
<p>Velkommen til teamet!</p>',
    ],
    'admin_new_registration' => [
        'subject' => '👤 Ny registreringsanmodning',
        'body' => '
            <h2>Ny registreringsanmodning</h2>
            <p>En ny bruger har anmodet om adgang til Pinakes:</p>
            <p><strong>Brugeroplysninger:</strong></p>
            <ul>
                <li>Navn: {{nome}} {{cognome}}</li>
                <li>Email: {{email}}</li>
                <li>Medlemskort: {{codice_tessera}}</li>
                <li>Anmodningsdato: {{data_registrazione}}</li>
            </ul>
            <p><a href="{{admin_users_url}}" style="background-color: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Administrer Brugere</a></p>
        ',
    ],
    'admin_new_review' => [
        'subject' => '⭐ Ny anmeldelse afventer godkendelse',
        'body' => '<h2>Ny anmeldelse afventer godkendelse</h2>
<p>Der er modtaget en ny anmeldelse af bogen:</p>
<p><strong>Bog:</strong> {{libro_titolo}}</p>
<div style="background-color: #fff7ed; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #f59e0b;">
    <p><strong>Bedømmelse:</strong> {{stelle}} stjerner ⭐</p>
    <p><strong>Bruger:</strong> {{utente_nome}} ({{utente_email}})</p>
    <p><strong>Anmeldelsesdato:</strong> {{data_recensione}}</p>
    <p><strong>Titel:</strong> {{titolo_recensione}}</p>
    <p><strong>Beskrivelse:</strong></p>
    <p style="white-space: pre-line;">{{descrizione_recensione}}</p>
</div>
<p style="text-align: center;">
    <a href="{{link_approvazione}}" style="background-color: #10b981; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">Administrer Anmeldelse</a>
</p>
<p><em>For at godkende eller afvise denne anmeldelse skal du logge ind i administrationspanelet.</em></p>',
    ],
    'copy_unavailable_user' => [
        'subject' => 'ℹ️ Opdatering om din reservation',
        'body' => '<h2>Opdatering om din reservation</h2>
<p>Hej {{utente_nome}},</p>
<p>Vi informerer dig om, at det eksemplar, der var reserveret til din reservation af følgende bog, ikke længere er tilgængeligt:</p>
<div style="background-color: #fffbeb; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #f59e0b;">
    <p><strong>Bog:</strong> {{libro_titolo}}</p>
    <p><strong>Årsag:</strong> {{motivo}}</p>
</div>
<p>Vi forsøger at tildele dig et andet eksemplar hurtigst muligt. Hvis der ikke bliver flere eksemplarer tilgængelige, forbliver din reservation i køen, og vi giver dig besked, så snart bogen igen er tilgængelig.</p>
<p>Vi beklager ulejligheden.</p>
<p>Med venlig hilsen,<br>Bibliotekets team</p>',
    ],
    'loan_approved' => [
        'subject' => '✅ Din udlånsanmodning er blevet godkendt!',
        'body' => '<h2>Din udlånsanmodning er blevet godkendt!</h2>
<p>Hej {{utente_nome}},</p>
<p>Vi er glade for at kunne fortælle dig, at din udlånsanmodning er blevet <strong>godkendt</strong>!</p>
<p><strong>Lånedetaljer:</strong></p>
<ul>
    <li>Bog: {{libro_titolo}}</li>
    <li>Startdato for lån: {{data_inizio}}</li>
    <li>Udløbsdato: {{data_fine}}</li>
    <li>Varighed: {{giorni_prestito}} dage</li>
</ul>
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981;">
    <p><strong>📦 Afhentning af bogen</strong></p>
    <p>{{pickup_instructions}}</p>
</div>
<p><strong>Vigtigt:</strong> Husk at aflevere bogen inden udløbsdatoen. Du vil modtage en påmindelse et par dage før udløbet.</p>
<p>God fornøjelse med læsningen!</p>',
    ],
    'loan_expiring_warning' => [
        'subject' => '⚠️ Dit lån udløber snart',
        'body' => '
            <h2>Påmindelse om udløb af lån</h2>
            <p>Hej {{utente_nome}},</p>
            <p>Vi minder dig om, at dit lån snart udløber:</p>
            <p><strong>Lånedetaljer:</strong></p>
            <ul>
                <li>Bog: {{libro_titolo}}</li>
                <li>Udløbsdato: {{data_scadenza}}</li>
                <li>Dage tilbage: {{giorni_rimasti}}</li>
            </ul>
            <div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
                <p><strong>⏰ Handling påkrævet</strong></p>
                <p>For at undgå gebyrer skal du aflevere bogen inden udløbsdatoen eller kontakte biblioteket for en eventuel fornyelse.</p>
            </div>
            <p>Tak for samarbejdet!</p>
        ',
    ],
    'loan_overdue_admin' => [
        'subject' => 'Lån #{{prestito_id}} er forsinket',
        'body' => '<h2>Forsinket lån</h2>
<p>Lånet <strong>#{{prestito_id}}</strong> er nu i status <strong>forsinket</strong>.</p>
<ul>
  <li><strong>Bog:</strong> {{libro_titolo}}</li>
  <li><strong>Bruger:</strong> {{utente_nome}} ({{utente_email}})</li>
  <li><strong>Lånedato:</strong> {{data_prestito}}</li>
  <li><strong>Udløbsdato:</strong> {{data_scadenza}}</li>
</ul>
<p>Grib ind for at kontakte brugeren og rykke for aflevering.</p>',
    ],
    'loan_overdue_notification' => [
        'subject' => '🚨 Lån udløbet - Handling påkrævet',
        'body' => '
            <h2>Lån udløbet</h2>
            <p>Hej {{utente_nome}},</p>
            <p>Dit lån er udløbet og skal afleveres omgående:</p>
            <p><strong>Lånedetaljer:</strong></p>
            <ul>
                <li>Bog: {{libro_titolo}}</li>
                <li>Udløbsdato: {{data_scadenza}}</li>
                <li>Dage forsinket: {{giorni_ritardo}}</li>
            </ul>
            <div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
                <p><strong>🚨 Akut handling påkrævet</strong></p>
                <p>Bogen skal afleveres omgående. Forsinket aflevering kan medføre sanktioner og suspendering af tjenesten.</p>
            </div>
            <p>Kontakt biblioteket omgående for at løse situationen.</p>
        ',
    ],
    'loan_pickup_cancelled' => [
        'subject' => '❌ Afhentning annulleret',
        'body' => '<h2>Afhentning annulleret</h2>
<p>Hej {{utente_nome}},</p>
<p>Vi informerer dig om, at afhentningen af følgende bog er blevet annulleret:</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Bog:</strong> {{libro_titolo}}</p>
    <p><strong>Årsag:</strong> {{motivo}}</p>
</div>
<p>Bogen er blevet gjort tilgængelig for andre brugere. Hvis du stadig ønsker denne bog, opfordrer vi dig til at indsende en ny låneanmodning.</p>
<p>Med venlig hilsen,<br>Bibliotekets team</p>',
    ],
    'loan_pickup_expired' => [
        'subject' => '⏰ Afhentningsfristen er udløbet',
        'body' => '<h2>Afhentningsfristen er udløbet</h2>
<p>Hej {{utente_nome}},</p>
<p>Desværre har du ikke afhentet bogen inden for den fastsatte frist.</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Bog:</strong> {{libro_titolo}}</p>
    <p><strong>Afhentningsfrist:</strong> {{scadenza_ritiro}}</p>
</div>
<p>Lånet er automatisk blevet annulleret, og bogen er blevet gjort tilgængelig for andre brugere.</p>
<p>Hvis du stadig ønsker denne bog, opfordrer vi dig til at indsende en ny låneanmodning.</p>
<p>Med venlig hilsen,<br>Bibliotekets team</p>',
    ],
    'loan_pickup_ready' => [
        'subject' => '📦 Bog klar til afhentning!',
        'body' => '<h2>Bog klar til afhentning!</h2>
<p>Hej {{utente_nome}},</p>
<p>Vi er glade for at kunne informere dig om, at din bog er klar til afhentning!</p>
<div style="background-color: #f0f9ff; padding: 20px; border-radius: 10px; border-left: 4px solid #3b82f6; margin: 20px 0;">
    <h3 style="color: #1e40af; margin: 0 0 10px 0;">{{libro_titolo}}</h3>
    <p style="margin: 5px 0;"><strong>Lånperiode:</strong> {{data_inizio}} - {{data_fine}}</p>
    <p style="margin: 5px 0;"><strong>Afhentningsfrist:</strong> {{scadenza_ritiro}}</p>
</div>
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981;">
    <p><strong>📦 Sådan afhenter du</strong></p>
    <p>{{pickup_instructions}}</p>
</div>
<p>God fornøjelse med læsningen!</p>',
    ],
    'loan_rejected' => [
        'subject' => '❌ Din udlånsanmodning er ikke blevet godkendt',
        'body' => '<h2>Din udlånsanmodning er ikke blevet godkendt</h2>
<p>Hej {{utente_nome}},</p>
<p>Vi må desværre meddele dig, at din udlånsanmodning for bogen <strong>"{{libro_titolo}}"</strong> ikke er blevet godkendt.</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Årsag:</strong></p>
    <p>{{motivo_rifiuto}}</p>
</div>
<p>Hvis du har spørgsmål eller ønsker yderligere oplysninger, er du velkommen til at kontakte os.</p>
<p>Med venlig hilsen,<br>Biblioteksteamet</p>',
    ],
    'loan_request_notification' => [
        'subject' => '📚 Ny udlånsanmodning',
        'body' => '
            <h2>Ny udlånsanmodning</h2>
            <p>Der er modtaget en ny udlånsanmodning:</p>
            <p><strong>Detaljer:</strong></p>
            <ul>
                <li>Bog: {{libro_titolo}}</li>
                <li>Bruger: {{utente_nome}} ({{utente_email}})</li>
                <li>Ønsket startdato: {{data_inizio}}</li>
                <li>Ønsket slutdato: {{data_fine}}</li>
                <li>Anmodningsdato: {{data_richiesta}}</li>
            </ul>
            <p><a href="{{approve_url}}" style="background-color: #10b981; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Administrer Anmodning</a></p>
        ',
    ],
    'loan_returned' => [
        'subject' => '✅ Aflevering bekræftet',
        'body' => '<h2>Aflevering bekræftet</h2>
<p>Hej {{utente_nome}},</p>
<p>Vi bekræfter afleveringen af følgende bog. Tak!</p>
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981;">
    <p><strong>Bog:</strong> {{libro_titolo}}</p>
    <p><strong>Afleveringsdato:</strong> {{data_restituzione}}</p>
</div>
<p>Vi håber, du nød læsningen. Vi ses snart på biblioteket!</p>
<p>Med venlig hilsen,<br>Bibliotekets team</p>',
    ],
    'reservation_book_available' => [
        'subject' => '📚 Reserveret bog klar til afhentning!',
        'body' => '<h2>Din bog er klar til afhentning!</h2>
<p>Hej {{utente_nome}},</p>
<p>Vi er glade for at kunne informere dig om, at bogen, du havde reserveret, nu er tilgængelig og klar til afhentning:</p>
<div style="background-color: #f0f9ff; padding: 20px; border-radius: 10px; border-left: 4px solid #3b82f6; margin: 20px 0;">
    <h3 style="color: #1e40af; margin: 0 0 10px 0;">{{libro_titolo}}</h3>
    <p style="margin: 5px 0;"><strong>Forfatter:</strong> {{libro_autore}}</p>
    <p style="margin: 5px 0;"><strong>ISBN:</strong> {{libro_isbn}}</p>
    <p style="margin: 5px 0;"><strong>Lånperiode:</strong> {{data_inizio}} - {{data_fine}}</p>
</div>
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981;">
    <p><strong>📦 Næste skridt</strong></p>
    <p>Kom forbi biblioteket for at hente bogen. Husk at medbringe et gyldigt legitimationsdokument.</p>
</div>
<p style="text-align: center;">
    <a href="{{book_url}}" style="background-color: #3b82f6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">📖 Se Bog</a>
    <a href="{{profile_url}}" style="background-color: #6b7280; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">👤 Mine Lån</a>
</p>
<p><em>Reservationen er blevet konverteret til et lån, der afventer bekræftelse af afhentning.</em></p>',
    ],
    'reservation_cancelled' => [
        'subject' => '❌ Reservation annulleret',
        'body' => '<h2>Reservation annulleret</h2>
<p>Hej {{utente_nome}},</p>
<p>Vi informerer dig om, at din reservation af følgende bog er blevet annulleret:</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Bog:</strong> {{libro_titolo}}</p>
    <p><strong>Årsag:</strong> {{motivo}}</p>
</div>
<p>Hvis du stadig ønsker denne bog, kan du foretage en ny reservation når som helst.</p>
<p>Med venlig hilsen,<br>Bibliotekets team</p>',
    ],
    'reservation_expired' => [
        'subject' => '⌛ Reservationen er udløbet',
        'body' => '<h2>Reservationen er udløbet</h2>
<p>Hej {{utente_nome}},</p>
<p>Din reservation af følgende bog er udløbet og er blevet lukket automatisk:</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Bog:</strong> {{libro_titolo}}</p>
    <p><strong>Udløbet den:</strong> {{data_scadenza}}</p>
</div>
<p>Hvis du stadig er interesseret, kan du foretage en ny reservation når som helst.</p>
<p>Med venlig hilsen,<br>Bibliotekets team</p>',
    ],
    'user_account_approved' => [
        'subject' => 'Konto godkendt - Velkommen til biblioteket!',
        'body' => '
            <h2>Din konto er blevet godkendt!</h2>
            <p>Hej {{nome}} {{cognome}},</p>
            <p>Vi er glade for at kunne fortælle dig, at din konto er blevet godkendt af en administrator.</p>
            <p>Du kan nu logge ind på systemet og begynde at booke bøger!</p>
            <p><strong>Detaljer om din konto:</strong></p>
            <ul>
                <li>Email: {{email}}</li>
                <li>Medlemskort: {{codice_tessera}}</li>
            </ul>
            <p><a href="{{login_url}}" style="background-color: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Log ind nu</a></p>
            <p>Velkommen til vores digitale bibliotek!</p>
        ',
    ],
    'user_password_setup' => [
        'subject' => '🔐 Opret din adgangskode',
        'body' => '<h2>Opret din adgangskode</h2>
<p>Hej {{nome}} {{cognome}},</p>
<p>Din konto på <strong>{{app_name}}</strong> er blevet oprettet. For at begynde at bruge systemet skal du oprette din adgangskode.</p>
<div style="background-color: #f0f9ff; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #3b82f6;">
    <p><strong>🔑 Konfigurér din konto</strong></p>
    <p>Klik på knappen nedenfor for at oprette din adgangskode:</p>
</div>
<p style="text-align: center; margin: 30px 0;">
    <a href="{{reset_url}}" style="background-color: #10b981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-size: 16px; display: inline-block;">🔐 Opret Adgangskode</a>
</p>
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
    <p><strong>⏰ Vigtigt</strong></p>
    <p>Linket er gyldigt i 24 timer. Hvis det udløber, bedes du kontakte en administrator for at få et nyt.</p>
</div>
<p>Hvis du ikke har anmodet om denne e-mail, kan du se bort fra den.</p>',
    ],
    'user_registration_pending' => [
        'subject' => 'Registrering modtaget - Afventer godkendelse',
        'body' => '
            <h2>Velkommen {{nome}} {{cognome}}!</h2>
            <p>Din registreringsanmodning er blevet modtaget.</p>
            <p><strong>Kontodetaljer:</strong></p>
            <ul>
                <li>Email: {{email}}</li>
                <li>Medlemskort: {{codice_tessera}}</li>
                <li>Registreringsdato: {{data_registrazione}}</li>
            </ul>
            {{sezione_verifica}}
            <div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
                <p><strong>⏳ Konto afventer godkendelse</strong></p>
                <p>Din konto afventer godkendelse af en administrator.
                Du vil modtage en bekræftelsesmail, når kontoen er blevet aktiveret.</p>
            </div>
            <p>Tak, fordi du valgte Pinakes!</p>
        ',
    ],
    'user_registration_verification' => [
        'subject' => 'Registrering modtaget - Bekræft din e-mail',
        'body' => '<h2>Velkommen {{nome}} {{cognome}}!</h2>
<p>Din registrering er modtaget.</p>
<p><strong>Kontooplysninger:</strong></p>
<ul>
    <li>E-mail: {{email}}</li>
    <li>Kortnummer: {{codice_tessera}}</li>
    <li>Registreringsdato: {{data_registrazione}}</li>
</ul>
{{sezione_verifica}}
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0;">
    <p><strong>Bekræft din e-mailadresse</strong></p>
    <p>Efter bekræftelsen bliver din konto aktiv, og du kan logge ind med det samme.</p>
</div>
<p>Tak, fordi du valgte Pinakes!</p>',
    ],
    'wishlist_book_available' => [
        'subject' => '📖 Bog fra din ønskeliste er nu tilgængelig!',
        'body' => '
            <h2>Gode nyheder!</h2>
            <p>Hej {{utente_nome}},</p>
            <p>Bogen, du tilføjede til din ønskeliste, er nu tilgængelig til udlån:</p>
            <div style="background-color: #f0f9ff; padding: 20px; border-radius: 10px; border-left: 4px solid #3b82f6; margin: 20px 0;">
                <h3 style="color: #1e40af; margin: 0 0 10px 0;">{{libro_titolo}}</h3>
                <p style="margin: 5px 0;"><strong>Forfatter:</strong> {{libro_autore}}</p>
                <p style="margin: 5px 0;"><strong>ISBN:</strong> {{libro_isbn}}</p>
                <p style="margin: 5px 0;"><strong>Tilgængelig fra:</strong> {{data_disponibilita}}</p>
            </div>
            <div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0;">
                <p><strong>✨ Reservér med det samme!</strong></p>
                <p>Bogen er nu tilgængelig til reservation. Skynd dig, før en anden reserverer den!</p>
            </div>
            <p style="text-align: center;">
                <a href="{{book_url}}" style="background-color: #10b981; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">📚 Reservér Nu</a>
                <a href="{{wishlist_url}}" style="background-color: #6b7280; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">❤️ Administrér Ønskeliste</a>
            </p>
            <p><em>Bogen bliver på din ønskeliste; du vil ikke modtage flere notifikationer om denne titel.</em></p>
        ',
    ],
];
