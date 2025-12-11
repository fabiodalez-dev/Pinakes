# Guida Dettagliata alle Impostazioni

La sezione **Amministrazione > Impostazioni** è il pannello di controllo centrale per configurare ogni aspetto di Pinakes. È organizzata in diverse schede (tab).

## Indice
- [Generale](#generale)
- [Email](#email)
- [Template Email](#template-email)
- [Contatti](#contatti)
- [Privacy](#privacy)
- [Etichette](#etichette)
- [Avanzate](#avanzate)

---

## Generale

In questa scheda definisci l'identità della tua biblioteca.

-   **Nome Applicazione**: Il nome della tua biblioteca, che apparirà come titolo del sito e in tutte le comunicazioni.
-   **Logo Applicazione**: Carica il tuo logo in formato PNG, JPG, WEBP o SVG (massimo 2MB). Verrà mostrato nell'intestazione del sito. Puoi anche rimuovere il logo esistente.
-   **Descrizione nel Footer**: Un breve testo che apparirà nel footer (piè di pagina) del sito.
-   **Link Social Media**: Inserisci gli URL completi dei tuoi profili social (Facebook, Twitter, Instagram, etc.). Le icone appariranno automaticamente nel footer.

## Email

Questa sezione è **fondamentale** per permettere al sistema di inviare email automatiche (notifiche, promemoria, etc.).

-   **Driver Email**:
    -   **Mail**: Utilizza la funzione `mail()` di PHP. Semplice ma poco affidabile, spesso finisce in spam.
    -   **SMTP**: **(Consigliato)** Permette di usare un server di posta esterno (come Gmail, Outlook, o il tuo provider di hosting) per un invio professionale e affidabile.
-   **Indirizzo Mittente** e **Nome Mittente**: L'indirizzo e il nome che gli utenti vedranno come mittente delle email.
-   **Configurazione SMTP**:
    -   **Host, Porta, Username, Password**: Le credenziali fornite dal tuo servizio di posta.
    -   **Sicurezza SMTP**: Solitamente `TLS` o `SSL`.

Dopo aver compilato i dati, usa il pulsante **"Invia Email di Test"** per verificare che la configurazione sia corretta.

## Template Email

Pinakes invia diverse email automatiche. In questa scheda, puoi personalizzare il contenuto di ognuna.

-   **Seleziona un Template**: Scegli dall'elenco l'email che vuoi modificare (es. "Richiesta di prestito approvata").
-   **Modifica Oggetto e Corpo**: Puoi riscrivere completamente il testo.
-   **Usa i Segnaposto (Placeholder)**: In ogni template, hai a disposizione una lista di "segnaposto" (es. `{{user_name}}`, `{{book_title}}`). Questi verranno sostituiti automaticamente dal sistema con i valori reali al momento dell'invio, permettendoti di creare email personalizzate.

## Contatti

Qui puoi configurare la pagina "Contatti" del sito pubblico.

-   **Titolo e Contenuto della Pagina**: Testi che appariranno sopra il modulo di contatto.
-   **Email e Telefono**: I contatti principali della biblioteca.
-   **Embed Google Maps**: Incolla il codice `<iframe>` fornito da Google Maps per mostrare una mappa interattiva.
-   **reCAPTCHA**: Se hai un account Google reCAPTCHA, inserisci qui la Site Key e la Secret Key per proteggere il modulo dallo spam.
-   **Email per Notifiche**: L'indirizzo a cui verranno inviati i messaggi inviati dagli utenti tramite il modulo.

## Privacy

Gestisci le impostazioni relative alla privacy e ai cookie.

-   **Contenuto Pagine**: Modifica il testo della "Privacy Policy" e della "Cookie Policy".
-   **Banner Cookie**:
    -   **Abilita/Disabilita**: Attiva o disattiva la comparsa del banner dei cookie per i nuovi visitatori.
    -   **Testi del Banner**: Puoi personalizzare ogni testo mostrato nel banner e nel pannello delle preferenze.
    -   **Categorie di Cookie**: Puoi scegliere di mostrare le opzioni per i cookie "Analitici" e di "Marketing". Se inserisci del codice negli appositi script nella scheda "Avanzate", questi toggle verranno attivati automaticamente.

## Etichette

Configura il formato delle etichette da stampare per i libri.

-   **Formato Etichetta**: Seleziona una delle dimensioni standard (es. `25x38mm`). Il sistema adatterà automaticamente il layout di stampa.

## Avanzate

Questa sezione contiene impostazioni per utenti esperti.

-   **Script Personalizzati**:
    -   **JS Essenziali**: Codice JavaScript che verrà caricato sempre.
    -   **JS Analitici/Marketing**: Codice che verrà caricato solo se l'utente fornisce il consenso tramite il banner dei cookie.
    -   **CSS Personalizzato**: Regole CSS per modificare ulteriormente l'aspetto del sito.
-   **Impostazioni Prestiti**:
    -   **Giorni di preavviso scadenza**: Quanti giorni prima della scadenza inviare l'email di promemoria.
-   **Sicurezza**:
    -   **Forza HTTPS**: Reindirizza tutto il traffico da HTTP a HTTPS.
    -   **Abilita HSTS**: Aggiunge un header di sicurezza che istruisce i browser a comunicare con il sito solo tramite HTTPS.
-   **Modalità Catalogo**: Se attivata, disabilita tutte le funzioni di prestito e richiesta. Il sito funzionerà solo come un catalogo online da consultare, senza interazioni per gli utenti.
-   **Sitemap**:
    -   **Rigenera Sitemap**: Clicca questo pulsante per creare/aggiornare il file `sitemap.xml`, utile per l'indicizzazione sui motori di ricerca.
-   **API Pubbliche**:
    -   Abilita o disabilita l'accesso alle API pubbliche.
    -   **Gestione API Keys**: Crea, attiva/disattiva o elimina chiavi di accesso per servizi esterni. Quando crei una nuova chiave, **copiala e salvala in un posto sicuro**, perché non verrà mostrata di nuovo.
