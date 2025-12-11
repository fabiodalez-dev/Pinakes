# ⚙️ Guida Completa: Impostazioni Pinakes

> Configurazione completa del sistema: identità, email, template, privacy, cookie, messaggi, etichette e impostazioni avanzate

---

## 📋 Indice

### Impostazioni Base
1. [Identità Applicazione](#identita-applicazione)
2. [Configurazione Email](#configurazione-email)
3. [Template Email](#template-email)
4. [CMS - Gestione Contenuti](#cms-gestione-contenuti)
5. [Pagina Contatti](#pagina-contatti)

### Privacy e Conformità
6. [Privacy e Cookie](#privacy-e-cookie)
7. [Cookie Banner Personalizzato](#cookie-banner-personalizzato)
8. [Messaggi Utenti](#messaggi-utenti)

### Funzionalità Avanzate
9. [Etichette Libri](#etichette-libri)
10. [Impostazioni Avanzate](#impostazioni-avanzate)
11. [Variabili Ambiente (.env)](#variabili-ambiente-env)

---

## 🏢 Identità Applicazione

**Percorso**: Impostazioni → **Tab "Identità"**

### Campi Disponibili

| Campo | Tipo | Descrizione | Dove appare |
|-------|------|-------------|-------------|
| **Nome applicazione** | Testo | Nome della biblioteca | Header, email, title pagine |
| **Logo** | Immagine | Logo istituzionale | Header, email, documenti |
| **Descrizione footer** | Textarea | Breve descrizione | Footer sito pubblico |
| **Link Social Media** | URL | Profili social | Footer sito pubblico |

### Logo

**Formati supportati**:
- PNG (consigliato per trasparenza)
- JPG
- WEBP
- SVG

**Dimensione massima**: 2MB

**Upload**:
```php
// Il sistema salva il logo in:
/public/uploads/settings/logo_YYYYMMDD_HHMMSS_RANDOM.ext

// E lo rende disponibile via URL:
/uploads/settings/logo_YYYYMMDD_HHMMSS_RANDOM.ext
```

**Rimozione logo**:
- Checkbox "Rimuovi logo attuale"
- Elimina file fisico dal server
- Resetta campo database

### Link Social Media

**Piattaforme supportate**:
1. **Facebook**: `https://facebook.com/tuapagina`
2. **Twitter**: `https://twitter.com/tuoprofilo`
3. **Instagram**: `https://instagram.com/tuoprofilo`
4. **LinkedIn**: `https://linkedin.com/company/tuaazienda`
5. **Bluesky**: `https://bsky.app/profile/tuoprofilo`

**Comportamento**:
- Campo vuoto = icona nascosta nel footer
- URL valido = icona cliccabile nel footer

---

## 📧 Configurazione Email

**Percorso**: Impostazioni → **Tab "Email"**

### Metodi di Invio

| Metodo | Quando usarlo | Configurazione richiesta |
|--------|---------------|--------------------------|
| **PHP mail()** | Server con mail() configurato | Solo mittente |
| **PHPMailer** | Compatibilità avanzata | Solo mittente |
| **SMTP personalizzato** | Server esterno (Gmail, Outlook, etc.) | Host, porta, credenziali |

### Configurazione SMTP

**Campi obbligatori**:
```
Host: smtp.gmail.com
Porta: 587
Username: biblioteca@gmail.com
Password: ****************
Crittografia: TLS
```

**Provider comuni**:

#### Gmail
```
Host: smtp.gmail.com
Porta: 587
Encryption: TLS
Nota: Richiede "Password per le app"
```

**Come generare password app Gmail**:
1. Vai su https://myaccount.google.com/security
2. Attiva "Verifica in 2 passaggi"
3. Vai su "Password per le app"
4. Genera password per "Mail"
5. Copia e incolla qui

#### Outlook
```
Host: smtp-mail.outlook.com
Porta: 587
Encryption: TLS
```

#### Aruba PEC
```
Host: smtps.pec.aruba.it
Porta: 465
Encryption: SSL
```

### Mittente Email

**From Email**: `noreply@biblioteca.it`
- Email che appare come mittente
- Deve essere valida per conformità SMTP

**From Name**: `Biblioteca Comunale`
- Nome leggibile del mittente
- Appare come: `Biblioteca Comunale <noreply@biblioteca.it>`

---

## 📨 Template Email

**Percorso**: Impostazioni → **Tab "Template"**

### Template Disponibili

Il sistema include 8 template email automatici:

| Template | Quando viene inviato | Destinatario |
|----------|---------------------|--------------|
| **user_registration** | Nuova registrazione utente | Nuovo utente |
| **loan_approved** | Prestito approvato da admin | Utente richiedente |
| **loan_reminder** | 3 giorni prima scadenza | Utente con prestito |
| **loan_overdue** | Prestito scaduto | Utente in ritardo |
| **loan_returned** | Libro restituito | Utente |
| **reservation_book_available** | Libro prenotato disponibile | Utente in coda |
| **wishlist_book_available** | Libro in wishlist disponibile | Utenti con libro in wishlist |
| **password_reset** | Reset password richiesto | Utente |

### Struttura Template

Ogni template ha:
- **Oggetto** (Subject): Titolo email
- **Corpo** (Body): Contenuto HTML

### Variabili Placeholders

**Sistema di sostituzione automatica**:
```
Scrivi: Ciao {{user_name}}, il libro {{book_title}} scade il {{due_date}}
Sistema invia: Ciao Mario Rossi, il libro La Divina Commedia scade il 25/12/2025
```

**Variabili disponibili** (dipendono dal template):

| Variabile | Descrizione | Esempio |
|-----------|-------------|---------|
| `{{user_name}}` | Nome completo utente | "Mario Rossi" |
| `{{app_name}}` | Nome applicazione | "Biblioteca Comunale" |
| `{{app_email}}` | Email applicazione | "info@biblioteca.it" |
| `{{book_title}}` | Titolo libro | "Il Nome della Rosa" |
| `{{book_author}}` | Autore libro | "Umberto Eco" |
| `{{due_date}}` | Data scadenza | "25/12/2025" |
| `{{loan_date}}` | Data prestito | "10/12/2025" |
| `{{verification_link}}` | Link verifica email | "https://..." |
| `{{reset_link}}` | Link reset password | "https://..." |

### Editor Template

**Funzionalità**:
- Editor WYSIWYG (TinyMCE)
- Formattazione testo (grassetto, corsivo, link)
- Liste e tabelle
- Inserimento immagini
- Codice HTML personalizzato

**Salvataggio**:
```php
// I template vengono salvati in:
database: email_templates
campi: template_name, subject, body, description
```

---

## 📝 CMS - Gestione Contenuti

**Percorso**: Impostazioni → **Tab "CMS"**

### Pagina "Chi Siamo"

**Funzionalità**:
- Editor WYSIWYG completo
- Upload immagini
- Formattazione HTML
- Anteprima real-time

**Accesso editor**:
```
Impostazioni → CMS → Bottone "Modifica"
Redirect a: /admin/cms/about
```

**Contenuto tipico**:
```html
<h2>Chi Siamo</h2>
<p>La Biblioteca Comunale nasce nel 1980...</p>
<img src="/uploads/cms/storia.jpg" alt="Storia">
<h3>La nostra missione</h3>
<ul>
  <li>Promuovere la lettura</li>
  <li>Supportare la ricerca</li>
  <li>Conservare la memoria storica</li>
</ul>
```

---

## 📞 Pagina Contatti

**Percorso**: Impostazioni → **Tab "Contatti"**

### Campi Configurabili

| Campo | Tipo | Descrizione |
|-------|------|-------------|
| **Titolo pagina** | Testo | Header pagina contatti |
| **Contenuto pagina** | HTML | Testo introduttivo |
| **Email contatto** | Email | Email pubblica per contatti |
| **Telefono** | Testo | Numero telefono |
| **Google Maps Embed** | iframe | Mappa interattiva |
| **Testo privacy** | Testo | Checkbox consenso form |
| **Email notifiche** | Email | Dove ricevere messaggi form |

### Google Maps

**Provider supportati**:
1. **Google Maps**: `https://www.google.com/maps/embed?...`
2. **OpenStreetMap**: `https://www.openstreetmap.org/export/embed.html?...`

**Validazione sicurezza**:
```php
// Il sistema verifica:
- URL HTTPS obbligatorio
- Host whitelisted (solo Google Maps o OSM)
- Sanitizzazione iframe
- Attributi sicuri (allowfullscreen, loading="lazy")
```

**Come ottenere embed Google Maps**:
1. Vai su https://google.com/maps
2. Cerca la tua biblioteca
3. Clicca "Condividi" → "Incorpora mappa"
4. Copia il codice iframe
5. Incolla nel campo (il sistema estrae automaticamente l'URL)

### reCAPTCHA (Opzionale)

**Protezione anti-spam**:
```
Site Key: 6Lc...AAAA
Secret Key: 6Lc...BBBB
```

**Quando abilitato**:
- Form contatti mostra challenge reCAPTCHA
- Blocca bot automatici
- Migliora deliverability email

---

## 🔒 Privacy e Cookie

**Percorso**: Impostazioni → **Tab "Privacy"**

### Configurazioni Privacy

| Impostazione | Tipo | Descrizione |
|--------------|------|-------------|
| **Titolo pagina privacy** | Testo | Titolo "Privacy Policy" |
| **Contenuto privacy** | HTML | Testo privacy policy completo |
| **Contenuto cookie policy** | HTML | Testo cookie policy |
| **Cookie banner abilitato** | Checkbox | Mostra/nascondi banner |
| **Lingua banner** | Select | it, en, fr, de, es |
| **Paese banner** | Select | IT, GB, FR, DE, ES |
| **Link cookie statement** | URL | Link privacy esterna |
| **Link tecnologie** | URL | Link documentazione cookie |

### Cookie Banner

**Comportamento**:
```javascript
// Banner appare quando:
- cookie_banner_enabled = true
- Utente NON ha già espresso consenso
- Pagina pubblica (non admin)

// Banner NON appare quando:
- cookie_banner_enabled = false
- Utente ha già cliccato "Accetta" o "Rifiuta"
```

### Categorie Cookie

**3 categorie gestite**:

1. **Essenziali** (sempre attivi)
   - Session PHP
   - CSRF token
   - Autenticazione
   - Cookie consenso stesso

2. **Analitici** (opzionali)
   - Google Analytics
   - Matomo
   - Hotjar
   - Toggle: `show_analytics`

3. **Marketing** (opzionali)
   - Facebook Pixel
   - Google Ads
   - LinkedIn Insight
   - Toggle: `show_marketing`

---

## 🍪 Cookie Banner Personalizzato

**Percorso**: Impostazioni → **Tab "Privacy"** → Sezione "Testi Cookie Banner"

### Testi Personalizzabili

#### Banner Principale

| Campo | Default | Descrizione |
|-------|---------|-------------|
| **Descrizione banner** | "Utilizziamo i cookie..." | Testo principale banner |
| **Bottone "Accetta tutti"** | "Accetta tutti" | Label bottone verde |
| **Bottone "Rifiuta"** | "Rifiuta non essenziali" | Label bottone rosso |
| **Bottone "Preferenze"** | "Preferenze" | Label bottone impostazioni |

#### Modale Preferenze

| Campo | Default | Descrizione |
|-------|---------|-------------|
| **Titolo modale** | "Personalizza le tue preferenze..." | Header modale |
| **Descrizione modale** | "Rispettiamo il tuo diritto..." | Testo modale |
| **Bottone "Salva selezionati"** | "Accetta selezionati" | Conferma scelte |

#### Categorie Cookie

**Cookie Essenziali**:
```
Nome: "Cookie Essenziali"
Descrizione: "Questi cookie sono necessari per il funzionamento..."
Toggle: Sempre ON (non disabilitabile)
```

**Cookie Analitici**:
```
Nome: "Cookie Analitici"
Descrizione: "Questi cookie ci aiutano a capire..."
Toggle: Checkbox utente
Show/Hide: Impostazioni → show_analytics
```

**Cookie Marketing**:
```
Nome: "Cookie di Marketing"
Descrizione: "Questi cookie vengono utilizzati..."
Toggle: Checkbox utente
Show/Hide: Impostazioni → show_marketing
```

### Auto-Attivazione Toggle

**Comportamento automatico**:
```php
// Se inserisci codice JavaScript Analitico (Tab Avanzate):
IF custom_js_analytics NOT EMPTY
THEN show_analytics = TRUE (auto-attivato)

// Se inserisci codice JavaScript Marketing:
IF custom_js_marketing NOT EMPTY
THEN show_marketing = TRUE (auto-attivato)
```

---

## 📬 Messaggi Utenti

**Percorso**: Impostazioni → **Tab "Messaggi"**

### Gestione Messaggi Form Contatti

**Tabella database**: `contact_messages`

**Campi memorizzati**:
```sql
- nome, cognome
- email
- telefono (opzionale)
- indirizzo (opzionale)
- messaggio (testo)
- privacy_accepted (bool)
- ip_address (tracking)
- is_read (bool)
- is_archived (bool)
- created_at, read_at
```

### Funzionalità Tab Messaggi

**Visualizzazione**:
- Lista completa messaggi
- Ordinamento: non letti prima, poi per data
- Badge "Nuovo" per is_read=0

**Azioni**:
- Segna come letto
- Archivia messaggio
- Elimina messaggio

**Privacy**:
- IP address memorizzato per sicurezza
- Consenso privacy obbligatorio
- Dati GDPR compliant

---

## 🏷️ Etichette Libri

**Percorso**: Impostazioni → **Tab "Etichette"**

### Formati Disponibili

| Formato | Dimensioni (mm) | Uso Consigliato |
|---------|----------------|-----------------|
| **25×38** | 25mm × 38mm | Standard (CONSIGLIATO) |
| **50×25** | 50mm × 25mm | Libri grandi |
| **70×36** | 70mm × 36mm | Raccoglitori, box |

### Configurazione Formato

**Validazione**:
```php
// Dimensioni ammesse:
width: min 10mm, max 100mm
height: min 10mm, max 100mm

// Formati personalizzati:
Regex: /^(\d+)x(\d+)$/
Esempio: "30x40" → 30mm × 40mm
```

**Salvataggio**:
```php
ConfigStore::set('label.width', 25);
ConfigStore::set('label.height', 38);
ConfigStore::set('label.format_name', '25×38mm');
```

### Stampa Etichette

**Quando crei/modifichi un libro**:
1. Salva libro
2. Vai a "Stampa Etichetta"
3. Sistema usa formato configurato
4. Genera PDF pronto per stampa

**Carta etichette consigliata**:
- Avery L7636
- Herma 4389
- Formati compatibili su Amazon/ufficio

---

## ⚙️ Impostazioni Avanzate

**Percorso**: Impostazioni → **Tab "Avanzate"**

### JavaScript Personalizzati

**Sistema a 3 categorie**:

#### 1. JavaScript Essenziali

**Quando si caricano**: Sempre, senza consenso cookie

**Uso tipico**:
- Chat support (Crisp, Intercom)
- Accessibility tools
- Script funzionali NON traccianti

**Esempio**:
```javascript
// Chat Crisp
window.$crisp=[];
window.CRISP_WEBSITE_ID="xxxxxxx";
(function(){d=document;s=d.createElement("script");
s.src="https://client.crisp.chat/l.js";
s.async=1;d.getElementsByTagName("head")[0].appendChild(s);})();
```

#### 2. JavaScript Analitici

**Quando si caricano**: Solo se utente accetta cookie Analytics

**Uso tipico**:
- Google Analytics
- Matomo
- Hotjar
- Plausible

**Esempio Google Analytics 4**:
```javascript
// Google Analytics 4
(function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;
i[r]=i[r]||function(){(i[r].q=i[r].q||[]).push(arguments)},
i[r].l=1*new Date();a=s.createElement(o),
m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;
m.parentNode.insertBefore(a,m)
})(window,document,'script',
'https://www.google-analytics.com/analytics.js','ga');
ga('create', 'UA-XXXXX-Y', 'auto');
ga('send', 'pageview');
```

**Auto-attivazione**:
```
IF custom_js_analytics NOT EMPTY
THEN cookie_banner.show_analytics = TRUE
```

#### 3. JavaScript Marketing

**Quando si caricano**: Solo se utente accetta cookie Marketing

**Uso tipico**:
- Facebook Pixel
- Google Ads
- LinkedIn Insight Tag
- Twitter Pixel

**Esempio Facebook Pixel**:
```javascript
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){
n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}
(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init', 'YOUR_PIXEL_ID');
fbq('track', 'PageView');
```

**Auto-attivazione**:
```
IF custom_js_marketing NOT EMPTY
THEN cookie_banner.show_marketing = TRUE
```

### CSS Personalizzato

**Campo**: `custom_header_css`

**Comportamento**:
```html
<!-- Il CSS viene inserito nell'header come: -->
<style>
  /* Il tuo CSS qui */
  body { font-size: 16px; }
</style>
```

**Uso tipico**:
- Override colori tema
- Font personalizzati
- Spacing/padding custom
- Nascondere elementi

**Esempio**:
```css
/* Cambia colore primario */
.btn-primary {
  background-color: #ff6600 !important;
}

/* Font personalizzato */
body {
  font-family: 'Montserrat', sans-serif;
}

/* Nascondi elemento */
.footer-newsletter {
  display: none;
}
```

### Configurazione HTTPS

**Force HTTPS**:
```
Checkbox: "Forza HTTPS"
Comportamento: Redirect automatico HTTP → HTTPS
Requisito: Certificato SSL valido
```

**HSTS (HTTP Strict Transport Security)**:
```
Checkbox: "Abilita HSTS"
Header inviato: Strict-Transport-Security: max-age=31536000
Comportamento: Browser forza HTTPS per 1 anno
⚠️ ATTENZIONE: Non attivare senza SSL valido!
```

### Giorni Prima Avviso Scadenza

**Campo**: `days_before_expiry_warning`

**Range**: 1-30 giorni
**Default**: 3 giorni

**Funzionamento**:
```php
// Cron job giornaliero calcola:
$dueDate = $loan['data_scadenza'];
$today = date('Y-m-d');
$daysUntilDue = days_between($today, $dueDate);

if ($daysUntilDue == $days_before_expiry_warning) {
    sendReminderEmail($loan);
}
```

### Sitemap XML

**Generazione automatica**:
```
Bottone: "Rigenera Sitemap"
Output: /public/sitemap.xml
Include: Homepage, Catalogo, Chi Siamo, Contatti, Privacy
```

**Metadati salvati**:
```
sitemap_last_generated_at: "2025-12-11T10:30:00Z"
sitemap_last_generated_total: 1247  // URL totali
```

### Modalità Catalogo

**Checkbox**: `catalogue_mode`

**Comportamento**:
```
IF catalogue_mode = TRUE:
  - Catalogo pubblico: Visibile ✅
  - Bottone "Richiedi Prestito": Nascosto ❌
  - Prenotazioni: Disabilitate ❌
  - Wishlist: Disabilitata ❌
```

**Uso**:
- Biblioteche che vogliono solo mostrare il catalogo
- Archivi storici senza prestiti
- Fase transitoria pre-attivazione

### API Pubbliche

**Toggle**: `api_enabled`

**Quando abilitato**:
```
Endpoint: /api/public/*
Autenticazione: API Key
Formati: JSON
```

**Gestione API Key**:
- **Crea nuova key**: Nome + Descrizione
- **Formato key**: 64 caratteri esadecimali random
- **Mostra key**: Solo una volta alla creazione (copiala!)
- **Toggle attiva/disattiva**: Senza eliminare
- **Elimina key**: Permanente

**Generazione key**:
```php
// Formato: pinakes_[64_char_hex]
$key = 'pinakes_' . bin2hex(random_bytes(32));
// Esempio: pinakes_a1b2c3d4...
```

---

## 🌍 Variabili Ambiente (.env)

**File**: `.env` (root progetto)
**Esempio**: `.env.example`

### Variabili Database

```bash
DB_HOST=localhost
DB_USER=biblioteca_user
DB_PASS=password_sicura
DB_NAME=biblioteca
DB_PORT=3306
DB_SOCKET=              # Opzionale (per socket Unix)
```

### Variabili Applicazione

```bash
# Ambiente (production / development)
APP_ENV=production

# URL canonico (per email, sitemap, redirect)
# IMPORTANTE: Usato per link nelle email!
APP_CANONICAL_URL=https://biblioteca.example.com

# Debug (true / false) - MUST be false in production
APP_DEBUG=false

# Display errors (true / false) - MUST be false in production
DISPLAY_ERRORS=false
```

**APP_CANONICAL_URL** (Importante!):
```php
// Usato per:
- Link verifica email
- Link reset password
- Link notifiche prestiti
- Sitemap.xml
- Meta tag canonical

// Anche quando email inviate da cron (localhost),
// i link puntano sempre a APP_CANONICAL_URL
```

### Variabili Sicurezza

```bash
# Encryption key per plugin (auto-generato)
PLUGIN_ENCRYPTION_KEY=base64:Kt8x...

# Come generare:
php -r "echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"

# Session lifetime (secondi)
SESSION_LIFETIME=3600  # 1 ora

# Force HTTPS
FORCE_HTTPS=true
```

### Variabili Logging

```bash
# Async logging (performance)
LOG_ASYNC_ENABLED=true

# Buffer size (entries prima di flush)
LOG_BUFFER_SIZE=50

# Buffer timeout (secondi prima di flush forzato)
LOG_BUFFER_TIMEOUT=5
```

**Quando usare async logging**:
- ✅ Production (performance)
- ❌ Development (preferire sync per debug immediato)

### Note Produzione

**Checklist deployment**:
```bash
✅ APP_ENV=production
✅ APP_DEBUG=false
✅ DISPLAY_ERRORS=false
✅ APP_CANONICAL_URL impostato
✅ FORCE_HTTPS=true (con SSL valido)
✅ Password DB robusta (min 16 char, alfanumerica + simboli)
✅ PLUGIN_ENCRYPTION_KEY generata e salvata
✅ Permissions corrette (755 app, 777 uploads/storage)
✅ Cron job configurato
✅ Backup database automatico
✅ Email SMTP configurata via Admin Panel
```

---

## 🔍 Query Diagnostiche

### Visualizza Tutte le Impostazioni

```sql
-- Tutte le settings
SELECT * FROM system_settings
ORDER BY category, setting_key;

-- Settings per categoria
SELECT * FROM system_settings
WHERE category = 'email';
```

### Template Email

```sql
-- Lista template
SELECT template_name, subject, is_custom
FROM email_templates
ORDER BY template_name;

-- Template con placeholder
SELECT template_name, subject, body
FROM email_templates
WHERE template_name = 'loan_approved';
```

### Messaggi Contatti

```sql
-- Messaggi non letti
SELECT * FROM contact_messages
WHERE is_read = 0
ORDER BY created_at DESC;

-- Messaggi ultimi 30 giorni
SELECT nome, cognome, email, messaggio, created_at
FROM contact_messages
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
ORDER BY created_at DESC;
```

---

## 🎓 Best Practices

### ✅ DO

1. **Configura APP_CANONICAL_URL** in produzione (critical per email)
2. **Usa SMTP affidabile** (Gmail, Outlook, provider professionale)
3. **Genera password app** per Gmail (non usare password account)
4. **Abilita HTTPS** in produzione
5. **Personalizza template email** con logo e branding
6. **Testa email** prima di andare live (bottone "Test Email")
7. **Abilita cookie banner** se sito pubblico (GDPR)
8. **Separa JavaScript** per categoria (essenziali/analytics/marketing)
9. **Backup .env** in luogo sicuro (contiene chiavi sensibili)
10. **Monitora messaggi** utenti regolarmente

### ❌ DON'T

1. **NON mettere .env in git** (aggiungi a .gitignore)
2. **NON usare APP_DEBUG=true** in produzione
3. **NON abilitare HSTS** senza SSL valido
4. **NON inserire `<script></script>`** nei campi JavaScript custom
5. **NON usare email non verificata** come mittente SMTP
6. **NON ignorare errori** test email SMTP
7. **NON modificare PLUGIN_ENCRYPTION_KEY** dopo installazione
8. **NON mettere codice tracciante** in JavaScript Essenziali
9. **NON saltare configurazione** cookie banner (GDPR compliance)
10. **NON dimenticare** di configurare cron job (notifiche)

---

## 🔗 Riferimenti

- [Guida Prestiti →](./prestiti/README.md)
- [Guida Template Email →](./email-templates.md)
- [Privacy e GDPR →](./privacy.md)
- [Plugin System →](./plugin/README.md)

---

**Ultima modifica**: Dicembre 2025
**Versione Pinakes**: v0.4.1
**File analizzati**: SettingsController.php, views/settings/*.php, .env.example
