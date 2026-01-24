# Centro Impostazioni

Guida completa al Centro Impostazioni di Pinakes, suddiviso in 9 sezioni (tab).

**Percorso**: Amministrazione → Impostazioni

---

## Tab 1: Identità

Configura l'identità visiva dell'applicazione.

### Identità Applicazione

| Campo | Descrizione |
|-------|-------------|
| **Nome applicazione** | Nome visualizzato nell'header e nelle email |
| **Logo** | Immagine logo (PNG, SVG, JPG, WebP - max 2MB) |
| **Rimuovi logo attuale** | Checkbox per eliminare il logo esistente |

**Upload Logo**:
- Drag-and-drop o selezione file
- Formati: PNG, SVG, JPG, WebP
- Dimensione massima: 2 MB
- Consigliato: PNG o SVG con sfondo trasparente

### Footer

| Campo | Descrizione |
|-------|-------------|
| **Descrizione footer** | Testo che appare nel footer del sito pubblico |

### Link Social Media

| Campo | Esempio |
|-------|---------|
| **Facebook** | `https://facebook.com/tuapagina` |
| **Twitter** | `https://twitter.com/tuoprofilo` |
| **Instagram** | `https://instagram.com/tuoprofilo` |
| **LinkedIn** | `https://linkedin.com/company/tuaazienda` |
| **Bluesky** | `https://bsky.app/profile/tuoprofilo` |

**Nota**: Lascia vuoto un campo per nascondere quel social dal footer.

---

## Tab 2: Email

Configura il metodo di invio email dal sistema.

### Metodo di Invio

| Opzione | Descrizione |
|---------|-------------|
| **PHP mail()** | Funzione PHP nativa (semplice, meno affidabile) |
| **PHPMailer** | Libreria PHPMailer con configurazione nel codice |
| **SMTP personalizzato** | Server SMTP esterno configurabile dall'interfaccia |

### Mittente

| Campo | Descrizione | Esempio |
|-------|-------------|---------|
| **Mittente (email)** | Indirizzo email mittente | `noreply@biblioteca.local` |
| **Mittente (nome)** | Nome visualizzato | `Biblioteca Civica` |

### Server SMTP

Disponibile solo con driver "SMTP personalizzato":

| Campo | Descrizione | Esempio |
|-------|-------------|---------|
| **Host** | Server SMTP | `smtp.gmail.com` |
| **Porta** | Porta SMTP | `587` |
| **Username** | Account SMTP | `user@gmail.com` |
| **Password** | Password SMTP | `xxxx` |
| **Crittografia** | TLS / SSL / Nessuna | `TLS` |

### Provider Comuni

#### Gmail
```
Host: smtp.gmail.com
Porta: 587
Crittografia: TLS
```
> Richiede "App Password" se 2FA attivo

#### Outlook/Office 365
```
Host: smtp.office365.com
Porta: 587
Crittografia: TLS
```

---

## Tab 3: Template

Personalizza i template delle email automatiche con l'editor TinyMCE.

### Template Disponibili

| Template | Evento | Destinatario |
|----------|--------|--------------|
| `registration` | Nuova registrazione utente | Nuovo utente |
| `registration_admin` | Notifica nuova registrazione | Amministratori |
| `email_verification` | Verifica indirizzo email | Utente |
| `password_reset` | Richiesta reset password | Utente |
| `user_approved` | Approvazione account | Utente |
| `loan_approved` | Prestito approvato (data futura) | Utente |
| `loan_ready` | Libro pronto al ritiro | Utente |
| `loan_reminder` | Promemoria scadenza | Utente |
| `loan_overdue` | Prestito in ritardo | Utente |
| `pickup_expired` | Ritiro scaduto | Utente |
| `reservation_available` | Prenotazione disponibile | Utente |
| `contact_form` | Messaggio dal form contatti | Amministratori |

### Struttura Template

Ogni template ha:
- **Oggetto**: riga dell'oggetto email
- **Corpo**: contenuto HTML (editor TinyMCE)
- **Segnaposto**: variabili dinamiche mostrate sopra l'editor

### Placeholder Disponibili

#### Universali
| Placeholder | Descrizione |
|-------------|-------------|
| `{{nome_biblioteca}}` | Nome della biblioteca configurato |
| `{{url_biblioteca}}` | URL base dell'applicazione |
| `{{anno}}` | Anno corrente |

#### Utente
| Placeholder | Descrizione |
|-------------|-------------|
| `{{nome}}` | Nome dell'utente |
| `{{cognome}}` | Cognome dell'utente |
| `{{email}}` | Email dell'utente |
| `{{tessera}}` | Numero tessera biblioteca |

#### Prestito
| Placeholder | Descrizione |
|-------------|-------------|
| `{{titolo_libro}}` | Titolo del libro |
| `{{autore}}` | Autore/i del libro |
| `{{data_inizio}}` | Data inizio prestito |
| `{{data_scadenza}}` | Data scadenza prestito |
| `{{giorni_ritardo}}` | Giorni di ritardo (se applicabile) |

#### Prenotazione
| Placeholder | Descrizione |
|-------------|-------------|
| `{{posizione_coda}}` | Posizione nella coda prenotazioni |
| `{{scadenza_ritiro}}` | Data limite per il ritiro |

#### Verifica Email
| Placeholder | Descrizione |
|-------------|-------------|
| `{{sezione_verifica}}` | Blocco HTML con pulsante verifica |
| `{{link_verifica}}` | URL diretto per la verifica |

#### Reset Password
| Placeholder | Descrizione |
|-------------|-------------|
| `{{link_reset}}` | URL per reimpostare password |
| `{{scadenza_link}}` | Validità del link (ore) |

---

## Tab 4: CMS

Gestione contenuti delle pagine statiche.

### Pagine Disponibili

| Pagina | Descrizione | Link Editor |
|--------|-------------|-------------|
| **Homepage** | Hero, features, CTA, immagine sfondo | `/admin/cms/home` |
| **Chi Siamo** | Contenuto pagina Chi Siamo | `/admin/cms/chi-siamo` |
| **Eventi** | Gestione eventi biblioteca | `/admin/cms/events` |

### Homepage Editor

L'editor homepage gestisce le seguenti sezioni:

| Section Key | Descrizione |
|-------------|-------------|
| `hero` | Banner principale con titolo, sottotitolo, pulsante, sfondo, SEO completo |
| `features_title` | Titolo sezione funzionalità |
| `feature_1` - `feature_4` | 4 card funzionalità con icona FontAwesome |
| `latest_books_title` | Titolo sezione ultimi arrivi |
| `genre_carousel` | Carosello generi |
| `text_content` | Blocco testo libero (TinyMCE) |
| `cta` | Call to Action |
| `events` | Sezione eventi |

Per dettagli completi sull'editor CMS, vedi [Sistema CMS](cms.md).

---

## Tab 5: Contatti

Configurazione della pagina Contatti e form di contatto.

### Contenuto Pagina

| Campo | Descrizione |
|-------|-------------|
| **Titolo pagina** | Titolo visualizzato (es. "Contattaci") |
| **Testo introduttivo** | Contenuto HTML introduttivo (TinyMCE) |

### Informazioni di Contatto

| Campo | Descrizione | Visibilità |
|-------|-------------|------------|
| **Email di contatto** | Email pubblica | Pagina contatti |
| **Telefono** | Numero telefono | Pagina contatti |
| **Email per notifiche** | Dove ricevere i messaggi dal form | Solo admin |

### Mappa Interattiva

| Campo | Descrizione |
|-------|-------------|
| **Codice embed completo** | iframe Google Maps o OpenStreetMap |

**Privacy**: Le mappe esterne vengono caricate solo se l'utente accetta i cookie Analytics.

**Come ottenere il codice**:
- Google Maps: `https://www.google.com/maps/embed?pb=...`
- OpenStreetMap: `https://www.openstreetmap.org/export/embed.html?bbox=...`

### Google reCAPTCHA v3

Protezione anti-spam per il form contatti.

| Campo | Descrizione |
|-------|-------------|
| **Site Key** | Chiave pubblica reCAPTCHA |
| **Secret Key** | Chiave privata reCAPTCHA |

Ottieni le chiavi da: [Google reCAPTCHA Admin](https://www.google.com/recaptcha/admin)

### Testo Privacy

| Campo | Descrizione |
|-------|-------------|
| **Testo checkbox** | Testo della checkbox privacy obbligatoria nel form |

---

## Tab 6: Privacy

Gestione Privacy Policy, Cookie Policy e Cookie Banner GDPR.

### Contenuto Privacy Policy

| Campo | Descrizione |
|-------|-------------|
| **Titolo pagina** | Titolo della pagina `/privacy-policy` |
| **Contenuto pagina** | Testo completo della privacy policy (TinyMCE) |

### Pagina Cookie Policy

| Campo | Descrizione |
|-------|-------------|
| **Contenuto Cookie Policy** | Contenuto della pagina `/cookies` linkata dal banner |

### Cookie Banner

| Campo | Descrizione | Default |
|-------|-------------|---------|
| **Abilita Cookie Banner** | Toggle on/off | On |
| **Lingua** | Lingua del banner | Italiano |
| **Paese** | Codice ISO 2 lettere | IT |
| **Link Cookie Statement** | URL pagina cookie policy | - |
| **Link Cookie Technologies** | URL tecnologie cookie | - |

**Lingue Supportate**:
- Italiano (IT), English (EN), Deutsch (DE), Español (ES)
- Français (FR), Nederlands (NL), Polski (PL), Dansk (DA)
- Български (BG), Català (CA), Slovenčina (SK), עברית (HE)

### Categorie Cookie

| Categoria | Descrizione | Toggle |
|-----------|-------------|--------|
| **Cookie Essenziali** | Sempre attivi, necessari per il funzionamento | Fisso |
| **Mostra Cookie Analitici** | Nascondi se non usi Google Analytics | On/Off |
| **Mostra Cookie di Marketing** | Nascondi se non usi cookie pubblicitari | On/Off |

### Testi Banner Cookie

Personalizzazione completa dei testi del banner:

**Banner Iniziale**:
| Campo | Descrizione |
|-------|-------------|
| Descrizione banner | Testo principale del banner |
| Testo "Accetta tutti" | Pulsante accettazione totale |
| Testo "Rifiuta non essenziali" | Pulsante rifiuto |
| Testo "Preferenze" | Pulsante apertura modale |
| Testo "Salva selezionati" | Pulsante salvataggio preferenze |

**Modale Preferenze**:
| Campo | Descrizione |
|-------|-------------|
| Titolo modale | Intestazione del pannello preferenze |
| Descrizione modale | Testo esplicativo |

**Categorie Cookie**:
| Campo | Descrizione |
|-------|-------------|
| Nome cookie essenziali | Label della categoria |
| Nome cookie analitici | Label della categoria |
| Nome cookie marketing | Label della categoria |
| Descrizione cookie essenziali | Spiegazione dettagliata |
| Descrizione cookie analitici | Spiegazione dettagliata |
| Descrizione cookie marketing | Spiegazione dettagliata |

---

## Tab 7: Messaggi

Inbox dei messaggi ricevuti tramite il form contatti.

### Visualizzazione Messaggi

La tabella mostra:
- **Checkbox** per selezione multipla
- **Mittente** (nome, cognome, email) con badge "Nuovo" se non letto
- **Messaggio** (anteprima 60 caratteri)
- **Data** di invio
- **Stato**: Non letto / Letto / Archiviato
- **Azioni**: Visualizza / Elimina

### Azioni Disponibili

| Azione | Descrizione |
|--------|-------------|
| **Visualizza** (icona occhio) | Apre modale con dettagli completi |
| **Elimina** (icona cestino) | Elimina il messaggio (conferma richiesta) |
| **Segna tutti come letti** | Marca tutti i messaggi come letti |

### Dettagli Messaggio

La modale mostra:
- Nome e cognome
- Email (cliccabile per rispondere)
- Telefono (se fornito)
- Indirizzo (se fornito)
- Data e ora
- Messaggio completo

**Azioni nel dettaglio**:
- **Rispondi**: Apre client email con destinatario precompilato
- **Archivia**: Sposta il messaggio in archivio

---

## Tab 8: Etichette

Configurazione del formato delle etichette PDF per i libri.

### Formati Disponibili

| Formato | Dimensioni | Descrizione |
|---------|------------|-------------|
| **25×38mm** | 25×38 mm | Standard dorso libri (più comune) |
| **50×25mm** | 50×25 mm | Formato orizzontale per dorso |
| **70×36mm** | 70×36 mm | Etichette interne grandi (Herma 4630, Avery 3490) |
| **25×40mm** | 25×40 mm | Standard Tirrenia catalogazione |
| **34×48mm** | 34×48 mm | Formato quadrato Tirrenia |
| **52×30mm** | 52×30 mm | Formato biblioteche scolastiche (compatibili A4) |

### Contenuto Etichetta

Le etichette PDF generate includono:
- **Codice a barre** (EAN o ISBN)
- **Codice Dewey** (se presente)
- **Collocazione** (scaffale-mensola-posizione)
- **Titolo** del libro
- **Autore** principale

**Nota**: Il formato selezionato viene applicato a tutte le etichette generate. Assicurati che corrisponda al tipo di carta per etichette utilizzata.

Per stampare etichette, vai su **Catalogo → [Libro] → Stampa etichetta**.

---

## Tab 9: Avanzate

Impostazioni avanzate per sviluppatori e configurazioni speciali.

### JavaScript Personalizzato

Inserisci codice JavaScript custom caricato in base alle preferenze cookie dell'utente.

| Campo | Quando viene caricato |
|-------|----------------------|
| **JS Essenziale** | Sempre (anche senza consenso cookie) |
| **JS Analytics** | Solo se utente accetta cookie Analytics |
| **JS Marketing** | Solo se utente accetta cookie Marketing |

**Casi d'uso**:
- **Essenziale**: Script critici per il funzionamento
- **Analytics**: Google Analytics, Matomo, Plausible
- **Marketing**: Pixel Facebook, Google Ads, remarketing

### CSS Personalizzato

| Campo | Descrizione |
|-------|-------------|
| **CSS Personalizzato** | Stili CSS aggiuntivi caricati su tutte le pagine |

### Sicurezza

| Opzione | Descrizione | Effetto |
|---------|-------------|---------|
| **Forza HTTPS** | Reindirizza tutte le richieste HTTP a HTTPS | Redirect 301 |
| **Abilita HSTS** | Invia header Strict-Transport-Security | Cache browser |

**Attenzione**: Abilita HTTPS solo se hai un certificato SSL valido configurato.

### Notifiche Prestiti

| Campo | Descrizione | Range |
|-------|-------------|-------|
| **Giorni prima della scadenza** | Quando inviare il promemoria | 1-30 giorni |

Default: 3 giorni prima della scadenza.

### Modalità Catalogo

| Opzione | Descrizione |
|---------|-------------|
| **Abilita modalità solo catalogo** | Disabilita prestiti e prenotazioni |

Quando attivo:
- Gli utenti possono solo consultare il catalogo
- Pulsanti "Richiedi prestito" e "Prenota" nascosti
- Dashboard semplificata (solo libri, utenti, autori)

### Sitemap XML

| Opzione | Descrizione |
|---------|-------------|
| **Abilita generazione sitemap** | Genera `/sitemap.xml` automaticamente |

**Configurazione Cron** (consigliato):
```bash
# Rigenera sitemap ogni giorno alle 3:00
0 3 * * * php /path/to/public/index.php cron:sitemap
```

Senza cron, la sitemap viene rigenerata ad ogni richiesta (più lento).

### API Pubblica

| Campo | Descrizione |
|-------|-------------|
| **Abilita API pubblica** | Attiva endpoints API per integrazioni esterne |
| **Chiave API** | Token di autenticazione per le richieste |

**Azioni**:
- **Genera nuova chiave**: Crea un nuovo token (invalida il precedente)
- **Copia chiave**: Copia negli appunti

**Link utili** (quando API attiva):
- Documentazione API: `/api/docs`
- Test connessione: `GET /api/test`

---

## File .env

Alcune configurazioni sono gestite a livello server nel file `.env`:

```ini
# Database
DB_HOST=localhost
DB_NAME=pinakes
DB_USER=root
DB_PASS=password

# Applicazione
APP_URL=https://mia-biblioteca.it
APP_DEBUG=false
APP_ENV=production

# Email (alternativa a configurazione UI)
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USER=user@example.com
MAIL_PASS=secret
MAIL_ENCRYPTION=tls
```

---

## Permessi File

Verifica che queste cartelle siano scrivibili dal webserver:

```
storage/                # Tutto il contenuto
├── backups/
├── cache/
├── logs/
├── plugins/
├── tmp/
└── uploads/

public/
└── uploads/
```

Permessi consigliati: `755` per cartelle, `644` per file.

---

## Risoluzione Problemi

### Email non inviate

1. **Verifica configurazione SMTP** in Tab Email
2. Controlla che i template siano abilitati in Tab Template
3. Verifica i log: `storage/logs/app.log`

### Cookie Banner non appare

1. Verifica che sia abilitato in Tab Privacy
2. Controlla che non ci siano errori JavaScript nella console
3. Il banner non appare se l'utente ha già dato consenso

### Sitemap non aggiornata

1. Verifica che sia abilitata in Tab Avanzate
2. Configura il cron job per aggiornamento automatico
3. Rigenera manualmente: `php public/index.php cron:sitemap`

### API non risponde

1. Verifica che l'API sia abilitata in Tab Avanzate
2. Controlla che la chiave API sia valida
3. Testa con: `curl -H "X-API-Key: TUA_CHIAVE" https://sito/api/test`

---

## Domande Frequenti (FAQ)

### 1. Come cambio il logo della biblioteca?

1. Vai in **Impostazioni → Identità** (primo tab)
2. Nella sezione **Logo**, clicca sull'area di upload
3. Seleziona un'immagine (formati: PNG, JPG, SVG)
4. L'anteprima appare immediatamente
5. Clicca **Salva impostazioni**

**Dimensioni consigliate**: Il logo viene ridimensionato automaticamente. Consigliato almeno 200x60 pixel per qualità ottimale.

---

### 2. Le email non arrivano agli utenti, cosa controllo?

Verifica in questo ordine:

1. **Tab Email**: Controlla la configurazione SMTP
   - Host, porta, utente e password corretti
   - Prova "Invia email di test"

2. **Tab Template**: Verifica che i template siano abilitati (toggle attivo)

3. **Spam**: Controlla la cartella spam dell'utente

4. **Log**: Cerca errori in `storage/logs/app.log`

**Provider consigliati**: Per invii affidabili usa servizi come SendGrid, Mailgun, o Amazon SES invece di SMTP generico.

---

### 3. Come personalizzo il testo delle email?

1. Vai in **Impostazioni → Template**
2. Seleziona il template da modificare (es. "Prestito approvato")
3. Modifica il testo nell'editor
4. Usa i **placeholder** tra doppie graffe: `{{nome}}`, `{{titolo_libro}}`, ecc.
5. Clicca **Salva template**

**Placeholder disponibili**: Ogni template mostra i placeholder utilizzabili. Clicca "Mostra placeholder" per l'elenco completo.

---

### 4. Come attivo il cookie banner GDPR?

1. Vai in **Impostazioni → Privacy**
2. Attiva **Cookie Banner** (toggle)
3. Seleziona la **Lingua** del banner
4. Configura le categorie di cookie:
   - **Essenziali**: sempre attivi
   - **Analytics**: opzionali (Google Analytics, ecc.)
   - **Marketing**: opzionali (pubblicità, ecc.)
5. Inserisci i link a Privacy Policy e Cookie Policy
6. Clicca **Salva**

Il banner apparirà ai nuovi visitatori. Gli utenti che hanno già accettato non lo rivedranno.

---

### 5. Come faccio a forzare HTTPS su tutto il sito?

1. Vai in **Impostazioni → Avanzate**
2. Attiva **Forza HTTPS** (toggle)
3. Opzionale: Attiva **HSTS** per massima sicurezza
4. Salva le impostazioni

**Prerequisito**: Il certificato SSL deve essere già installato sul server. Se non lo è, contatta il tuo hosting.

**Attenzione**: Se attivi HTTPS senza certificato valido, il sito diventerà inaccessibile.

---

### 6. Come aggiungo Google Analytics al sito?

1. Vai in **Impostazioni → Avanzate**
2. Nel campo **JavaScript Personalizzato**, sezione **Analytics**
3. Incolla il codice di tracking di Google Analytics:

```javascript
<!-- Google Analytics -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXXX"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-XXXXXXX');
</script>
```

4. Salva le impostazioni

Il codice nella sezione "Analytics" viene caricato solo se l'utente accetta i cookie Analytics.

---

### 7. Come rigenero la Sitemap?

La sitemap può essere:

**Generata automaticamente:**
1. Configura il cron job sul server
2. Esegue `php public/index.php cron:sitemap` ogni giorno

**Generata manualmente:**
1. Vai in **Impostazioni → Avanzate**
2. Clicca **Rigenera Sitemap**
3. Il file `sitemap.xml` viene aggiornato immediatamente

**Verifica**: Accedi a `https://tuosito.it/sitemap.xml` per vedere il risultato.

---

### 8. Come cambio il formato delle etichette libro?

1. Vai in **Impostazioni → Etichette**
2. Seleziona il formato desiderato:
   - 25×38 mm (piccole)
   - 50×25 mm (rettangolari)
   - 70×36 mm (grandi)
   - Altri formati disponibili
3. Salva le impostazioni

Per stampare le etichette:
1. Apri la scheda del libro
2. Clicca **Stampa etichetta**
3. Viene generato un PDF nel formato selezionato

---

### 9. Come attivo la "Modalità Catalogo" (solo consultazione)?

Se vuoi usare Pinakes solo come catalogo online, senza gestione prestiti:

1. Vai in **Impostazioni → Avanzate**
2. Attiva **Modalità Catalogo** (toggle)
3. Salva le impostazioni

In questa modalità:
- Gli utenti possono cercare e visualizzare i libri
- I pulsanti "Richiedi prestito" sono nascosti
- La dashboard mostra solo statistiche catalogo
- Le sezioni prestiti/prenotazioni sono disabilitate

---

### 10. Come aggiungo i link ai social network?

1. Vai in **Impostazioni → Identità**
2. Scorri alla sezione **Social Media**
3. Inserisci gli URL completi dei profili:
   - Facebook: `https://facebook.com/nomepagina`
   - Instagram: `https://instagram.com/nomeprofilo`
   - Twitter/X: `https://twitter.com/nomeutente`
   - LinkedIn: `https://linkedin.com/company/nome`
   - Bluesky: `https://bsky.app/profile/nome`
4. Salva le impostazioni

I link appariranno nel footer del sito con le rispettive icone.
