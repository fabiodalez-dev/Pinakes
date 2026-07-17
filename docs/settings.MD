# Impostazioni Pinakes

> **Non serve essere tecnici!** Questa pagina contiene tutte le impostazioni della tua biblioteca, spiegate in modo semplice con esempi pratici.

---

## Quick Start: Setup in 5 Minuti

### Prima cosa (obbligatorio - 3 minuti):

1. **Dashboard** → **Impostazioni** → **Tab "Identità"**
2. Metti il **nome della tua biblioteca**
   - Esempio: "Biblioteca Scuola Marconi"
3. Carica il **logo** (facoltativo ma consigliato)
   - Trascinaci l'immagine del logo
4. **Salva**
5.  Fatto! Vedrai subito il nome e logo nell'interfaccia

### Seconda cosa (consigliato - 2 minuti):

1. **Impostazioni** → **Tab "Email"**
2. Seleziona un provider:
   - **Gmail** (facile, gratuito) ← **CONSIGLIATO**
   - Outlook (se usi Microsoft)
   - SMTP personalizzato (se hai server aziendale)
3. Riempi i dati
4. **Testa connessione**
5.  Fatto! Sistema pronto a mandare email

**Pronto?** Hai finito la configurazione di base. Puoi iniziare a usare il sistema! 

---

## Le Aree (Tab) delle Impostazioni

La pagina Impostazioni (`/admin/settings`) è organizzata in **11 tab**. Il tab attivo è selezionabile con `?tab=…`:

| Tab (`?tab=`) | Contenuto |
|---|---|
| `general` | Identità: nome, logo, descrizione footer, link social (Facebook, Twitter/X, Instagram, LinkedIn, Bluesky, Telegram) |
| `email` | Provider/SMTP + toggle "richiedi approvazione admin" alla registrazione |
| `templates` | Template email (19 tipi) |
| `cms` | Contenuti homepage / pagine + layout immagine eventi |
| `contacts` | Pagina contatti, email/telefono, mappa (Google Maps o OpenStreetMap), reCAPTCHA |
| `privacy` | Privacy policy, cookie banner e relativi testi |
| `messages` | Messaggi ricevuti dal form contatti |
| `labels` | Formato etichette libri |
| `sharing` | Provider di condivisione social abilitati |
| `advanced` | JS/CSS custom, sicurezza HTTPS/HSTS, timeout sessione, preavviso scadenza, sitemap, API pubbliche, llms.txt, modalità catalogo |
| `loans` | Durata prestito, scadenza ritiro, rinnovi, max prestiti attivi per utente |

> Tutte le rotte di salvataggio sono `POST /admin/settings/<area>` (es. `/admin/settings/general`, `/admin/settings/email`, `/admin/settings/labels`, `/admin/settings/loans`, …), protette da `AdminAuthMiddleware` + `CsrfMiddleware`. Le rotte admin sono letterali in inglese (decisione #145/#161).

### 1.  **Identità della Biblioteca** - Chi sei al pubblico

**Dove**: Dashboard → Impostazioni → **Tab "Identità"** (`?tab=general`)

Oltre a nome/logo/descrizione, qui si impostano anche i **link ai social** (Facebook, Twitter/X, Instagram, LinkedIn, Bluesky), mostrati nel footer pubblico. Il logo accetta solo **PNG, JPG o WEBP** (max 2MB; SVG non supportato per motivi di sicurezza).

**Cosa puoi cambiare:**

| Cosa | Dove lo vedono | Esempio |
|---|---|---|
| **Nome biblioteca** | Ovunque nel sistema | "Biblioteca Comunale Roma" |
| **Logo** | Intestazione, email, lettere | Logo della scuola/comune |
| **Descrizione breve** | Homepage pubblica | "La biblioteca del tuo quartiere dal 1980" |

**Come si fa:** Clicca campo → Scrivi o carica → Clicca "Salva" → **Vedi subito il risultato!**

---

### 2.  **Email del Sistema** - Come il sistema manda messaggi

**Dove**: Dashboard → Impostazioni → **Tab "Email"**

**Cosa fa il sistema:**
-  Manda email di benvenuto ai nuovi utenti
-  Notifica quando un prestito è approvato
-  Avvisa 3 giorni prima della scadenza
-  Sollecita se un libro è in ritardo

**Come configurare (scegli uno):**

#### **Opzione 1: Gmail** ( Più facile)
1. **Email mittente**: `tua-biblioteca@gmail.com`
2. **Password**: Password Gmail speciale (vedi sotto)
3. **Host**: `smtp.gmail.com`
4. **Porta**: `587`

**Con Gmail devi:**
1. Vai su https://myaccount.google.com/security
2. Attiva "Verifica in 2 passaggi"
3. Torna a Security → "App passwords"
4. Genera password per "Mail"
5. Copia quella password e mettila qui

#### **Opzione 2: Outlook**
1. **Email mittente**: `tua-email@outlook.com`
2. **Password**: Password Outlook
3. **Host**: `smtp-mail.outlook.com`
4. **Porta**: `587`

#### **Opzione 3: SMTP Personalizzato**
Chiedi i dati al tuo amministratore IT (se lavori in una scuola/ente).

**Dopo aver riempito tutto:**
1. Clicca **"Test Connessione"**
2. Se  **Verde**: Perfetto! Email funzioneranno
3. Se  **Rosso**: Controlla i dati e riprova

> **Dettagli tecnici**: il driver email (campo del form `mail_driver`, salvato come setting `email.driver_mode`) può valere `mail`, `smtp` o `phpmailer`; la cifratura SMTP (campo del form `smtp_encryption`, salvato come setting `email.smtp_security`) può essere `tls`, `ssl` o `none`. La password SMTP è cifrata prima di essere salvata (`SettingsEncryption`). Nello **stesso form** è presente il toggle **"Richiedi approvazione admin"** per le registrazioni (setting `registration.require_admin_approval`).

---

### 3.  **Contenuti del Sito** - Cosa dici pubblicamente

**Dove**: Dashboard → Impostazioni → **Tab "CMS"**

Personalizza 2 pagine pubbliche:

| Pagina | Puoi scrivere | Utile per |
|---|---|---|
| **Homepage** | Titolo, grande immagine, testo | Presentare la biblioteca |
| **Chi Siamo** | Storia, foto, informazioni | Spiegare la missione |

**Come si fa:**
1. Clicca la pagina che vuoi modificare
2. Scrivi il testo (usa l'editor come Word)
3. Aggiungi foto trascinandola
4. Clicca "Salva"
5. Vedi subito sulla homepage pubblica

---

### 4.  **Pagina Contatti** - Come ti trovano

**Dove**: Dashboard → Impostazioni → **Tab "Contatti"**

Riempi questi campi (semplice):

| Campo | Metti qui | Esempio |
|---|---|---|
| **Email pubblica** | Email che vedono gli utenti | info@biblioteca-milano.it |
| **Telefono** | Numero di telefono | 02-2345678 |
| **Indirizzo fisico** | Dove siete | Via Roma 1, 20100 Milano |
| **Mappa Google** | Codice Google Maps (facoltativo) | [Se sai come fare] |

**Questo appare** nella pagina "Contatti" del sito pubblico.

---

### 5.  **Privacy e Cookie** - Questioni legali (semplice!)

**Dove**: Dashboard → Impostazioni → **Tab "Privacy"**

**3 cose da decidere:**

1. **Mostrare banner cookie?**
   - Sì se il sito è pubblico
   - No se è solo interno

2. **Testo privacy policy**
   - Usa quello di default (va bene)
   - O scrivi il tuo (solo se serve davvero)

3. **Lingua**
   - Italiano 
   - O Inglese se serve

**Consiglio**: Lascia i default, funzionano per il 99% dei casi.

---

### 6.  **Template Email** - Personalizza i messaggi automatici

**Dove**: Dashboard → Impostazioni → **Tab "Template"** (`?tab=templates`)

Il sistema gestisce **19 tipi di email** (definiti in `App\Support\SettingsMailTemplates`). Qui puoi personalizzarli. I tipi includono, tra gli altri: `user_registration_pending`, `user_account_approved`, `user_password_setup`, `admin_invitation`, `loan_request_notification`, `loan_approved`, `loan_rejected`, `loan_pickup_ready`, `loan_pickup_expired`, `loan_pickup_cancelled`, `loan_expiring_warning`, `loan_overdue_notification`, `loan_overdue_admin`, `loan_returned`, `reservation_book_available`, `reservation_expired`, `wishlist_book_available`, `copy_unavailable_user`, `admin_new_review`.

Salvataggio: `POST /admin/settings/templates/{template}`.

**Cosa puoi cambiare per ogni email:**
-  Oggetto (il titolo dell'email)
-  Corpo (il messaggio)

**Variabili intelligenti:**
- `{{user_name}}` → diventa il nome vero dell'utente
- `{{book_title}}` → diventa il titolo vero del libro
- `{{due_date}}` → diventa la data di scadenza vera

**Esempio:**
```text
Scrivi: "Ciao {{user_name}}, il libro {{book_title}} scade il {{due_date}}"
Sistema manda: "Ciao Mario, il libro La Divina Commedia scade il 25/10/2025"
```

**Consiglio**: Non toccare se va bene così. Ma se vuoi personalizzare:
1. Apri il template
2. Modifica il testo
3. Clicca "Salva template"
4. Fatto!

---

### 7.  **Etichette Libri** - Come stampare

**Dove**: Dashboard → Impostazioni → **Tab "Etichette"**

Scegli il **formato delle etichette** che stamperai:

| Formato | Dimensioni | Consiglio |
|---|---|---|
| **Standard** | 25×38mm |  Usa sempre questo |
| Medio | 50×25mm | Solo se libri molto grossi |
| Grande | 70×36mm | Raramente |

**Come si usa:**
1. Scegli il formato
2. Clicca "Salva"
3. Quando stampi un'etichetta, usa il formato scelto
4. Compra carta con lo stesso formato su Amazon/cartoleria

---

### 7-bis.  **Prestiti** - Regole dei prestiti

**Dove**: Dashboard → Impostazioni → **Tab "Prestiti"** (`?tab=loans`) — salvataggio `POST /admin/settings/loans`

Imposti le regole globali dei prestiti (categoria setting `loans`):

| Campo (`name`) | Significato | Default | Clamp lato server |
|---|---|---|---|
| `loan_duration_days` | Durata standard del prestito (giorni) | 30 | 1 … 3650 (10 anni) |
| `pickup_expiry_days` | Giorni entro cui ritirare un prestito approvato prima della scadenza ritiro | 3 | 1 … 365 |
| `max_renewals` | Numero massimo di rinnovi (0 = illimitato) | 3 | 0 … 100 |
| `max_active_loans_per_user` | Massimo prestiti attivi per utente (0 = illimitato) | 0 | 0 … 1000 |

> **Importante**: il server **ricalcola e blocca (clamp)** ogni valore entro i limiti indicati a prescindere dagli attributi `min`/`max` del form (che sono solo indicativi). Un POST manipolato non può quindi impostare durate o rinnovi assurdi.

---

### 8.  **Impostazioni Avanzate** - Personalizzazioni e funzionalità extra

**Dove**: Dashboard → Impostazioni → **Tab "Avanzate"** (`?tab=advanced`)

> **Per la maggior parte degli utenti**: Lascia tutto di default, funziona perfettamente!
> Esplora questa sezione solo se hai esigenze specifiche.

#### **JavaScript e CSS Personalizzati**

Puoi aggiungere codice personalizzato senza modificare i file di sistema:

| Sezione | Quando viene eseguito | Utile per |
|---------|----------------------|-----------|
| **JS Essenziale** | Sempre, su ogni pagina | Analytics interni, widget chat |
| **JS Analytics** | Solo se utente accetta cookie analytics | Google Analytics, Matomo |
| **JS Marketing** | Solo se utente accetta cookie marketing | Pixel Facebook, retargeting |
| **CSS Personalizzato** | Sempre | Stili aggiuntivi, personalizzazione colori |

**Come si fa:**
1. Incolla il tuo codice nella sezione appropriata
2. Clicca "Salva"
3. Ricarica il sito per vedere le modifiche

#### **Sicurezza HTTPS/HSTS**

| Impostazione | Cosa fa | Consiglio |
|--------------|---------|-----------|
| **Forza HTTPS** | Reindirizza automaticamente da http:// a https:// |  Attiva se hai certificato SSL |
| **HSTS** | Dice ai browser di usare sempre HTTPS |  Attiva dopo aver testato HTTPS |

**Attenzione**: Attiva HSTS solo se sei sicuro che HTTPS funziona correttamente!

#### **Notifiche Scadenza Prestiti**

**Giorni preavviso scadenza** (`days_before_expiry_warning`): Quanti giorni prima della scadenza mandare l'email di promemoria.
- Default: **3 giorni** (clamp lato server: 1 … 30)
- Esempio: Se un prestito scade il 30, l'email parte il 27

#### **Timeout Sessione**

**Durata sessione** (`session_lifetime`, in minuti): tempo di inattività dopo cui l'utente viene disconnesso.
- Default: **180 minuti** (3 ore)
- Valori ammessi (preset): 30, 60, 120, 180, 360, 720, 1440 minuti — qualunque altro valore viene riportato a 180

#### **llms.txt**

Toggle (`seo.llms_txt_enabled`) per generare il file `llms.txt` che descrive il sito agli assistenti AI.

#### **Modalità Catalogo**

Attiva questa opzione per trasformare il sistema in un **catalogo consultabile**:
-  Gli utenti possono cercare e vedere i libri
-  Funzionalità prestiti nascoste
-  Nessuna registrazione utenti

**Utile per**: Biblioteche che vogliono solo mostrare il proprio catalogo online senza gestire prestiti.

#### **Sitemap XML**

Genera automaticamente una sitemap per i motori di ricerca (Google, Bing):

1. **Attiva la generazione** con il toggle
2. **Copia l'URL della sitemap** mostrato
3. **Invialo a Google Search Console** per indicizzazione più veloce

**Aggiornamento automatico**: Configura un cron job per rigenerare la sitemap:
```bash
# Ogni giorno alle 3:00
0 3 * * * curl -s https://tuosito.it/sitemap/generate?key=TUA_API_KEY
```

#### **API Pubbliche**

Se vuoi permettere ad altre applicazioni di accedere ai dati del catalogo:

1. **Attiva le API pubbliche** con il toggle
2. **Genera una chiave API** cliccando "Nuova Chiave"
3. **Copia la chiave** (mostrata una sola volta!)
4. **Usala nelle tue applicazioni** per accedere agli endpoint API

**Endpoint disponibili:**
- `GET /api/v1/books` - Lista libri
- `GET /api/v1/books/{id}` - Dettagli libro
- `GET /api/v1/search?q=...` - Ricerca catalogo

---

### 9.  **Messaggi di Contatto** - Gestisci le richieste ricevute

**Dove**: Dashboard → Impostazioni → **Tab "Messaggi"**

Qui trovi tutti i messaggi inviati dagli utenti tramite il form contatti del sito pubblico.

**Cosa puoi fare:**

| Azione | Icona | Descrizione |
|--------|-------|-------------|
| **Visualizza** |  | Apri il messaggio completo |
| **Rispondi** | ↩ | Apre il tuo client email con l'indirizzo precompilato |
| **Archivia** |  | Sposta il messaggio nell'archivio |
| **Elimina** |  | Rimuove definitivamente il messaggio |
| **Segna tutti letti** |  | Marca tutti i messaggi come letti |

**Indicatori di stato:**
-  **Nuovo** - Messaggio non ancora letto
-  **Letto** - Già visualizzato
-  **Archiviato** - Spostato nell'archivio

**Consiglio**: Controlla regolarmente questa sezione per non perdere richieste importanti!

---

## Checklist Configurazione Iniziale

### **Obbligatorio (fai subito - 5 min):**
- [ ] Nome biblioteca
- [ ] Logo caricato
- [ ] Email SMTP configurata e testata

### **Consigliato (quando hai tempo - 10 min):**
- [ ] Testo "Chi Siamo" scritto
- [ ] Email e telefono nella pagina contatti
- [ ] Homepage personalizzata

### **Opzionale (solo se vuoi):**
- [ ] Template email personalizzati
- [ ] Mappa Google integrata
- [ ] Descrizione breve della biblioteca
- [ ] Sitemap XML attivata (per SEO)
- [ ] API pubbliche configurate
- [ ] HTTPS forzato e HSTS attivo

---

## Problemi Comuni (Risposte Facili)

### "Non ricevo le email"
**Soluzione:**
1. Vai in Impostazioni → Email
2. Clicca "Test Connessione"
3. Se rosso (errore):
   - Verifica che email sia corretta
   - Verifica che password sia corretta
   - Se usi Gmail, assicurati di aver creato la "password app"
   - Contatta il supporto del provider email

### "Le modifiche alle impostazioni non si vedono"
**Soluzione:**
1. Clicca "Salva"
2. Ricarica la pagina (CTRL+F5 o CMD+SHIFT+R)
3. Se ancora non funziona, pulisci la cache del browser

### "Come faccio a farmi aiutare?"
**Soluzione:**
1. Leggi la guida correlata (link in fondo)
2. Controlla FAQ in questa pagina
3. Chiedi all'amministratore del sistema

---

## 3 Esempi Pratici di Configurazione

### Esempio 1: Biblioteca Scolastica
```
 Nome: "Biblioteca Scuola Media Galilei"
 Email: biblioteca@galilei.edu.it
 Formato etichette: 25×38mm Standard
 Tutto gratis, nessuna sanzione
```

### Esempio 2: Biblioteca Comunale
```
 Nome: "Biblioteca Comunale Borgo Antico"
 Email: info@borgoantico.it
 Formato etichette: 25×38mm Standard
 Prestiti 30 giorni, max 3 rinnovi (nessuna gestione sanzioni/multe nel sistema)
```

### Esempio 3: Biblioteca Privata/Aziendale
```
 Nome: "Biblioteca Dipartimento R&D"
 Email: biblioteca@azienda.it
 Formato etichette: 25×38mm Standard
 Prestiti 7 giorni, no sanzioni
```

---

## Prossimi Passi Dopo la Configurazione

1. **Aggiungi i tuoi scaffali** → [Vai a Collocazione](./collocazione.MD)
2. **Inserisci i primi libri** → [Vai a Inserimento Libri](./inserimento_libri.MD)
3. **Invita gli utenti** → Sezione Users nel menu
4. **Testa i prestiti** → [Vai a Prestiti](./prestiti.MD)

---

** Fatto!** Le impostazioni base sono complete. Il sistema è pronto all'uso! 

---

*Tempo lettura: 10 minuti*
*Tempo configurazione: 15 minuti*
*Ultimo aggiornamento: 24 Gennaio 2026*

---

## PARTE 2: DOCUMENTAZIONE TECNICA PER SVILUPPATORI

> Questa sezione è per sviluppatori e amministratori di sistema. Utenti finali possono ignorarla.

### Architettura del Sistema Settings

#### Struttura Database

```sql
CREATE TABLE system_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category VARCHAR(50) NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_setting (category, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

I template email vivono in una tabella separata `email_templates` (`name` UNIQUE, `subject`, `body` LONGTEXT, `active`). Entrambe le tabelle sono create idempotentemente da `SettingsRepository::ensureTables()` (`CREATE TABLE IF NOT EXISTS`).

#### Categorie Principali

Categorie effettivamente usate dal `SettingsController` (chiave `category`):
`app`, `email`, `registration`, `contacts`, `privacy`, `cookie_banner`, `advanced`, `api`, `seo`, `system`, `label`, `sharing`, `cms`, `loans`.

```sql
-- Esempi reali di chiavi (category, setting_key)
('app', 'name', 'Pinakes'),
('app', 'logo_path', '/uploads/settings/logo_…'),
('email', 'driver_mode', 'smtp'),            -- mail | smtp | phpmailer
('email', 'smtp_host', 'smtp.gmail.com'),
('email', 'smtp_port', '587'),
('email', 'smtp_security', 'tls'),           -- tls | ssl | none
('registration', 'require_admin_approval', '1'),
('loans', 'loan_duration_days', '30'),
('loans', 'pickup_expiry_days', '3'),
('loans', 'max_renewals', '3'),
('loans', 'max_active_loans_per_user', '0'), -- 0 = illimitato
('advanced', 'days_before_expiry_warning', '3'),
('advanced', 'session_lifetime', '180'),     -- minuti
('advanced', 'force_https', '0'),
('advanced', 'enable_hsts', '0'),
('seo', 'llms_txt_enabled', '0'),
('api', 'enabled', '0'),
('system', 'catalogue_mode', '0'),
('label', 'width', '25'), ('label', 'height', '38');
```

> Non esistono chiavi `library.loan_days` / `fine_per_day`: le regole prestito stanno nella categoria `loans` (vedi tab "Prestiti"). Non c'è gestione sanzioni/multe nel codice del SettingsController.

#### SettingsRepository Pattern

```php
class SettingsRepository
{
    public function ensureTables(): void;
    public function get(string $category, string $key, ?string $default = null): ?string;
    public function set(string $category, string $key, ?string $value): void;
    public function getCategory(string $category): array;          // [setting_key => value]
    public function delete(string $category, string $key): void;

    // Template email
    public function ensureEmailTemplates(array $templates): void;
    public function getEmailTemplate(string $name): ?array;
    public function getEmailTemplates(array $names = []): array;
    public function saveEmailTemplate(string $name, string $subject, string $body, ?string $description = null, bool $active = true): void;
}
```

> Le impostazioni "live" sono anche replicate in `App\Support\ConfigStore` (es. `ConfigStore::set('app.name', …)`, `ConfigStore::get('system.catalogue_mode')`). Il `SettingsController` aggiorna sempre **entrambi**: la tabella tramite `SettingsRepository::set()` e la cache di runtime tramite `ConfigStore`.

#### Email Multi-Provider Configuration

```php
// Gmail SMTP — chiavi reali usate da SettingsController::updateEmailSettings()
$settings->set('email', 'driver_mode', 'smtp');
$settings->set('email', 'smtp_host', 'smtp.gmail.com');
$settings->set('email', 'smtp_port', '587');
$settings->set('email', 'smtp_username', 'tua-email@gmail.com');
$settings->set('email', 'smtp_password', 'app-password-generata');
$settings->set('email', 'smtp_security', 'tls');

// Outlook SMTP
$settings->set('email', 'smtp_host', 'smtp-mail.outlook.com');
$settings->set('email', 'smtp_port', '587');
$settings->set('email', 'smtp_security', 'tls');

// PEC Aruba
$settings->set('email', 'smtp_host', 'smtps.pec.aruba.it');
$settings->set('email', 'smtp_port', '465');
$settings->set('email', 'smtp_security', 'ssl');
```

#### Label Dimensions Configuration

```php
$labelFormats = [
    '25x38' => [
        'width' => 25,
        'height' => 38,
        'margin' => 2,
        'font_size_title' => 6,
        'font_size_author' => 4,
        'barcode_height' => 8
    ],
    '50x25' => [
        'width' => 50,
        'height' => 25,
        'margin' => 3,
        'font_size_title' => 8,
        'font_size_author' => 5,
        'barcode_height' => 10
    ],
    '70x36' => [
        'width' => 70,
        'height' => 36,
        'margin' => 4,
        'font_size_title' => 10,
        'font_size_author' => 6,
        'barcode_height' => 12
    ]
];
```

#### Email Template System

Le definizioni di default dei 19 template sono in `App\Support\SettingsMailTemplates` (label, oggetto, corpo, descrizione, placeholders). All'apertura della pagina Impostazioni, `SettingsController::index()` chiama `ensureEmailTemplates()` per popolare i default mancanti; gli override personalizzati sono salvati in `email_templates` da `saveEmailTemplate()`.

```php
// Lettura definizioni di default
SettingsMailTemplates::all();              // array di 19 definizioni
SettingsMailTemplates::get('loan_approved'); // singola definizione (o null)

// Esempio definizione (placeholders come {{user_name}}, {{book_title}}, …)
'loan_approved' => [
    'label'        => '…',
    'subject'      => '…',
    'body'         => '…',
    'description'  => '…',
    'placeholders' => ['user_name', 'book_title', 'due_date'],
];
```

#### Rotte di salvataggio (form admin, no REST JSON)

Le impostazioni **non** sono esposte tramite una REST `/api/settings/...`: sono form HTML `POST` protetti da `AdminAuthMiddleware` + `CsrfMiddleware`:

| Area | Rotta |
|---|---|
| Identità | `POST /admin/settings/general` |
| Email + approvazione registrazione | `POST /admin/settings/email` |
| Template email | `POST /admin/settings/templates/{template}` |
| Contatti | `POST /admin/settings/contacts` |
| Privacy | `POST /admin/settings/privacy` |
| Testi cookie banner | `POST /admin/settings/cookie-banner` |
| Etichette | `POST /admin/settings/labels` |
| Condivisione | `POST /admin/settings/sharing` |
| Eventi (layout immagine) | `POST /admin/settings/events` |
| Avanzate | `POST /admin/settings/advanced` |
| Rigenera sitemap | `POST /admin/settings/advanced/regenerate-sitemap` |
| Prestiti | `POST /admin/settings/loans` |
| Toggle API pubbliche | `POST /admin/settings/api/toggle` |
| Crea API key | `POST /admin/settings/api/keys/create` |
| Toggle/Elimina API key | `POST /admin/settings/api/keys/{id}/toggle` · `/delete` |

#### Strategia di cache

Il runtime usa `App\Support\ConfigStore` come cache in-memory delle impostazioni (popolata da DB + default localizzati). Dopo modifiche che cambiano la modalità catalogo, il controller invoca `ConfigStore::clearCache()`. Non esiste un layer `Cache::remember('settings:…')` con TTL: la persistenza è il DB, la cache di runtime è `ConfigStore`.

#### Security Considerations

- All settings validated before saving
- Email passwords encrypted in database
- SMTP credentials sanitized from API responses
- Template variables escaped for HTML injection prevention
- Rate limiting on test-email endpoint (5 per minute)

#### Public API Configuration

```php
// API key generation and storage
$settings->set('api', 'public_enabled', true);
$settings->set('api', 'keys', json_encode([
    [
        'key' => 'pk_live_...',
        'name' => 'My App',
        'created_at' => '2025-01-24',
        'last_used' => null
    ]
]));

// Rate limiting per API key
$settings->set('api', 'rate_limit', '1000'); // requests per hour
```

#### Sitemap Configuration

```php
// Sitemap generation settings
$settings->set('sitemap', 'enabled', true);
$settings->set('sitemap', 'include_books', true);
$settings->set('sitemap', 'include_authors', true);
$settings->set('sitemap', 'changefreq', 'weekly');
$settings->set('sitemap', 'priority', '0.8');

// Cron regeneration endpoint
// GET /sitemap/generate?key={api_key}
```

#### Custom JavaScript/CSS Injection

```php
// JavaScript categories respect cookie consent
$settings->set('custom', 'js_essential', '// Always executed');
$settings->set('custom', 'js_analytics', '// Only with analytics consent');
$settings->set('custom', 'js_marketing', '// Only with marketing consent');
$settings->set('custom', 'css', '/* Custom styles */');
```

#### HTTPS/HSTS Configuration

```php
// Force HTTPS redirect
$settings->set('security', 'force_https', true);

// HSTS (HTTP Strict Transport Security)
$settings->set('security', 'hsts_enabled', true);
$settings->set('security', 'hsts_max_age', '31536000'); // 1 year in seconds
```

#### Catalogue Mode

```php
// Disabilita i prestiti, mostra solo il catalogo.
// La chiave è 'catalogue_mode' nella categoria 'system' (NON 'library').
$repository->set('system', 'catalogue_mode', '1');
ConfigStore::set('system.catalogue_mode', true);
ConfigStore::clearCache();

// Lettura runtime:
ConfigStore::isCatalogueMode(); // bool

// Quando attiva:
// - Nasconde pulsanti prestito e prenotazioni (dashboard + frontend)
// - Ricerca e dettaglio libro restano accessibili
// - Le funzioni admin restano disponibili
```

#### Contact Messages API

**GET /admin/messages**
- Lists all contact form messages
- Supports filtering by read/archived status

**GET /admin/messages/{id}**
```json
{
  "id": 123,
  "nome": "Mario",
  "cognome": "Rossi",
  "email": "mario@example.com",
  "telefono": "123456789",
  "messaggio": "Vorrei informazioni...",
  "is_read": false,
  "is_archived": false,
  "created_at": "2025-01-24T10:30:00Z"
}
```

**POST /admin/messages/{id}/archive**
- Archives the message

**POST /admin/messages/mark-all-read**
- Marks all messages as read

**DELETE /admin/messages/{id}**
- Permanently deletes the message

---

*Ultimo aggiornamento: 4 Giugno 2026*
*Versione Documentazione: 7.0.0 - Allineata a SettingsController (11 tab)*
