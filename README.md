# Pinakes 📚

> **Sistema di Gestione Bibliotecaria Moderno e Gratuito**

Pinakes è un sistema completo di gestione bibliotecaria progettato per biblioteche scolastiche, parrocchiali, associative e private. Offre catalogazione intelligente, gestione prestiti, notifiche automatiche e molto altro, tutto in un'interfaccia moderna e intuitiva.

[![Version](https://img.shields.io/badge/version-0.1.1-blue.svg)](https://github.com/fabiodalez-dev/pinakes)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-777BB4.svg)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-%3E%3D5.7-4479A1.svg)](https://www.mysql.com/)
[![License](https://img.shields.io/badge/license-ISC-green.svg)](LICENSE)

---

## 📜 Storia del Nome

> **Πίνακες** (Pinakes) - "Le Tavole"

Il nome **Pinakes** deriva dal greco antico **πίνακες** (*pinakes*), che significa "tavole" o "tavolette". Onora **Callimaco di Cirene** (305-240 a.C.) che intorno al **245 a.C.** creò per la **Biblioteca di Alessandria** il primo catalogo bibliotecario sistematico della storia: i ***Πίνακες τῶν ἐν πάσῃ παιδείᾳ διαλαμψάντων καὶ ὧν συνέγραψαν*** ("Tavole di coloro che si sono distinti in ogni campo del sapere e delle loro opere").

Quest'opera monumentale, composta da 120 rotoli, organizzava sistematicamente oltre 120.000 rotoli di papiro presenti nella biblioteca. Sebbene i Pinakes originali siano andati perduti, il loro metodo di catalogazione ha influenzato profondamente la biblioteconomia moderna.

Questo progetto prosegue quella tradizione millenaria di 2,268 anni portando gli stessi principi di organizzazione e accessibilità del sapere nell'era digitale.

---

## ✨ Caratteristiche Principali

### 📖 Gestione Catalogo
- **Catalogazione libri completa** con ISBN, autori, editori, generi, tag
- **Importazione automatica dati** da servizi ISBN esterni (Google Books API, Open Library)
- **Gestione copie fisiche multiple** con sistema scaffali/mensole/posizioni
  - Tracciamento posizione fisica di ogni copia (es. "Scaffale A - Mensola 3 - Posizione 12")
  - Numerazione copie automatica progressiva
  - Stati copia: disponibile, prestato, in manutenzione, smarrito, danneggiato
  - Note per copia (condizioni, annotazioni)
  - QR code generabile per etichettatura fisica
  - Inventario completo con export Excel/CSV
  - Storico movimenti per ogni copia
  - Ricerca rapida per codice copia
- **Classificazione Dewey Decimal** integrata (1369 classificazioni)
- **Ricerca avanzata** con filtri multipli (titolo, autore, anno, genere, editore, ISBN)
- **Sistema tag** per categorizzazione flessibile e dinamica
- **Upload copertine** con gestione intelligente URL e fallback automatico
- **Pagine archivio** dedicate per autori ed editori
  - Vista completa opere per autore (con biografia e foto)
  - Catalogo completo per casa editrice
  - Statistiche: totale opere, copie disponibili, prestiti attivi
  - URL SEO-friendly (es. `/autore/gabriel-garcia-marquez`)
  - Ordinamento personalizzabile (alfabetico, cronologico, popolarità)

### 🔄 Sistema Prestiti e Prenotazioni
- **Gestione prestiti completa** con date inizio/fine/scadenza
- **Approvazione richieste** da pannello admin con workflow
- **Sistema prenotazioni avanzato**
  - Code FIFO (First In First Out) gestite automaticamente
  - Notifiche email istantanee quando libro disponibile
  - Prenotazioni multiple per utente (limite configurabile)
  - Scadenza automatica prenotazioni (default 7 giorni)
  - Promemoria scadenza prenotazione (48h prima)
  - Annullamento prenotazioni da utente
  - Statistiche prenotazioni in dashboard admin
- **Wishlist personale utenti**
  - Lista desideri personalizzata illimitata
  - Notifiche email automatiche quando libro in wishlist diventa disponibile
  - Aggiunta/rimozione wishlist con un click
  - Contatore wishlist in pagina libro
  - Vista dedicata "I Miei Desideri" in profilo utente
  - Priorità gestita dall'ordine di aggiunta
- **Scadenze automatiche** e promemoria email (3 giorni prima)
- **Notifiche prestiti scaduti** automatiche con giorni di ritardo
- **Cronologia prestiti** completa per utente con filtri
- **Stati prestito** (pendente, attivo, scaduto, restituito, rifiutato)
- **Rinnovo prestiti** se libro non prenotato da altri

### 👥 Gestione Multiutente
- **Registrazione utenti** con verifica email
- **Sistema ruoli avanzato**
  - **Admin**: Accesso completo
  - **Staff**: Gestione prestiti e catalogo
  - **Premium**: Prestiti estesi
  - **Standard**: Utente base
- **Approvazione account** da admin
  - Workflow a 2 step (approvazione + verifica email)
  - Attivazione diretta senza email
- **Codice tessera** auto-generato univoco (formato: T + 10 hex)
- **Profilo utente** personalizzabile
  - Dati anagrafici completi
  - Storico prestiti
  - Wishlist personale
  - Prenotazioni attive
  - Impostazioni privacy
- **Gestione scadenze tessere** (5 anni default)
- **Dashboard utente** con panoramica attività
- **Preferenze notifiche** granulari

### 🔔 Sistema Notifiche
- **Notifiche admin** dashboard centralizzata
- **Preferenze notifica** personalizzabili per utente
- **Email automatiche** per scadenze prestiti
- **Notifiche wishlist** quando libro disponibile
- **Template email** professionali e personalizzabili UTF-8

### ⭐ Funzionalità Social e Recensioni
- **Sistema recensioni completo**
  - Rating a stelle (1-5) con half-star support
  - Testo recensione (min 50 caratteri, max 2000 caratteri)
  - Validazione contenuti con filtro parole inappropriate
  - Moderazione admin/staff con dashboard dedicata
  - Approvazione/rifiuto recensioni con notifiche utente
  - Motivo rifiuto richiesto (feedback costruttivo)
  - Display pubblico recensioni solo se approvate
  - Calcolo rating medio automatico con peso per numero recensioni
  - Una recensione per utente per libro (modificabile)
  - Ordinamento recensioni: più recenti, più utili, rating alto/basso
  - "Questa recensione è utile?" - sistema voting
  - Segnalazione recensioni inappropriate da utenti
  - Export recensioni CSV per analisi
- **Condivisione social** multi-piattaforma
  - Facebook (Open Graph ottimizzato)
  - Twitter/X (Twitter Cards)
  - WhatsApp (mobile-friendly)
  - LinkedIn (professional sharing)
  - Telegram
  - Email (mailto con pre-compilato)
- **Copia link diretto** al libro con feedback visivo
- **Feedback utenti** raccolto e gestito via dashboard
- **Messaggi contatti** via form con:
  - reCAPTCHA v3 anti-spam
  - Rate limiting per IP
  - Validazione honeypot
  - Notifiche admin email immediate

### 🎨 CMS e Personalizzazione
- **Pagine CMS** editabili (Chi Siamo, Privacy, Termini)
- **Homepage features** personalizzabili
- **Editor WYSIWYG** TinyMCE integrato
- **Sistema settings** centralizzato
- **Logo personalizzabile** e branding

### 🧩 Sistema Plugin Estensibile
- **Architettura plugin completa** per estendere funzionalità senza modificare il core
- **Sistema hook flessibile** con Filter e Action hooks
- **Installazione tramite ZIP** con upload drag & drop via interfaccia admin
- **Gestione completa plugin**: attivazione, disattivazione, disinstallazione
- **16+ hook predefiniti** per libri, autori, editori, login, catalogo
- **Storage dedicato** per dati plugin (database + filesystem)
- **API completa**: PluginManager, HookManager, Settings, Data, Logs
- **Sicurezza integrata**: CSRF protection, validazione, isolamento
- **Documentazione completa** con esempi pratici in `docs/PLUGIN_SYSTEM.md`
- **Hook disponibili**:
  - **Login**: form render, custom fields, validation, success/failed events
  - **Libri**: extend data, save before/after, custom fields backend/frontend
  - **Autori**: extend data, save before/after hooks
  - **Editori**: extend data customization
- **Plugin di esempio** incluso (Book Rating Plugin)

### 📊 Pannello Admin
- **Dashboard completa** con statistiche
- **Gestione libri, utenti, prestiti** centralizzata
- **Gestione plugin** con statistiche e controlli
- **Notifiche admin** in tempo reale
- **Log attività** e cronologia modifiche
- **Export database** e backup automatici
- **Gestione email templates** da interfaccia

### 🌐 Interfaccia Utente
- **Design responsive** Bootstrap 5 + TailwindCSS
- **Interfaccia moderna** con SweetAlert2
- **Date picker** localizzato italiano (Flatpickr)
- **Tabelle interattive** DataTables con sorting/search
- **Upload file** drag & drop (Uppy self-hosted)
- **Scelta multipla** avanzata (Choices.js)
- **Dark theme** gray-900 coerente

### 🔐 Sicurezza
- **Protezione CSRF** su tutte le form
- **Password bcrypt** hash sicuro
- **Sessioni sicure** con configurazione hardened
- **HTTPS enforcement** opzionale
- **Validazione input** server-side completa
- **SQL injection protection** prepared statements

---

## 🎯 Requisiti Sistema

### Server
- **PHP**: 8.1 o superiore
- **Database**: MySQL 5.7+ o MariaDB 10.3+
- **Web Server**: Apache 2.4+ o Nginx con mod_rewrite
- **RAM**: 512MB minimo (1GB raccomandato)
- **Spazio Disco**: 500MB minimo

### Estensioni PHP Richieste
- `mysqli` - Database connector
- `pdo` - Database abstraction layer
- `mbstring` - Multibyte string support
- `json` - JSON encoding/decoding
- `openssl` - Secure communications
- `curl` - HTTP requests
- `gd` o `imagick` - Image manipulation (opzionale)

### Configurazione PHP Raccomandata
```ini
memory_limit = 256M
upload_max_filesize = 10M
post_max_size = 10M
max_execution_time = 60
```

---

## 🚀 Installazione

### 1. Clone Repository
```bash
git clone https://github.com/fabiodalez-dev/pinakes.git
cd pinakes
```

### 2. Setup Permissions
**IMPORTANTE:** Git non preserva i permessi delle directory. Dopo il clone, esegui:

```bash
# Metodo 1: Script automatico (raccomandato)
./bin/setup-permissions.sh

# Metodo 2: Manuale
chmod 777 uploads backups storage storage/logs storage/tmp public/uploads
chmod +x bin/*.sh
```

**Lo script crea automaticamente:**
- Directory mancanti (se non esistono)
- Permessi 777 per directory scrivibili dal web server
- File `.gitkeep` per preservare struttura directory in Git

**Verifica permessi:**
```bash
ls -la uploads backups storage
# Output atteso: drwxrwxrwx (777) per tutte
```

### 3. Install Dependencies
```bash
# Backend dependencies (Composer)
composer install --no-dev --optimize-autoloader

# Frontend dependencies (NPM)
cd frontend
npm install
npm run build
cd ..
```

### 4. Configurazione Database
Crea un database MySQL e un utente dedicato:
```sql
CREATE DATABASE pinakes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pinakes_user'@'localhost' IDENTIFIED BY 'strong_password';
GRANT ALL PRIVILEGES ON pinakes.* TO 'pinakes_user'@'localhost';
FLUSH PRIVILEGES;
```

### 5. Configurazione Ambiente
```bash
cp .env.example .env
# Modifica .env con i tuoi parametri
```

### 6. Installer Web
1. Apri il browser su `http://tuodominio.com`
2. Verrai reindirizzato automaticamente all'installer
3. Segui i 7 step guidati:
   - Verifica requisiti
   - Configurazione database
   - Creazione tabelle
   - Import dati iniziali
   - Creazione admin
   - Configurazione applicazione
   - Completamento

### 7. Primo Accesso
- URL: `http://tuodominio.com/admin`
- Username: quello creato durante installer
- Password: quella impostata durante installer

---

## ⚙️ Configurazione Post-Installazione

### Email SMTP
1. Accedi come admin
2. Vai in **Settings → Email Configuration**
3. Configura SMTP o usa `mail()` PHP
4. Testa invio email

### Email Templates
1. Vai in **Settings → Email Templates**
2. Personalizza templates predefiniti
3. Variabili disponibili: `{{nome}}`, `{{email}}`, `{{codice_tessera}}`, ecc.

### Backup Automatici
Configura cronjob per backup periodici:
```bash
# Ogni giorno alle 2:00 AM
0 2 * * * php /path/to/pinakes/scripts/maintenance.php
```

### Sitemap e SEO
La sitemap viene generata automaticamente e include:
- Pagine statiche (homepage, catalogo, contatti)
- Tutti i libri con lastmod
- Pagine archivio autori
- Pagine archivio editori
- Priorità e frequenze di aggiornamento ottimizzate

Accesso: `http://tuodominio.com/sitemap.xml`

Rigenera manualmente se necessario:
```bash
php scripts/generate-sitemap.php
```

### Sistema Plugin
Il sistema di plugin permette di estendere Pinakes senza modificare il codice core:

**Installazione Plugin:**
1. Accedi come admin
2. Vai in **Admin → Plugin**
3. Carica file ZIP del plugin
4. Attiva il plugin dalla lista

**Sviluppo Plugin:**
Per creare un plugin personalizzato, consulta la documentazione completa:
- `docs/PLUGIN_SYSTEM.md` - Guida completa con struttura, API, esempi
- `docs/PLUGIN_HOOKS.md` - Riferimento di tutti gli hook disponibili
- `docs/examples/example-plugin/` - Plugin di esempio funzionante

**Hook Disponibili:**
Il sistema include 16+ hook predefiniti per:
- Estendere campi libri (backend e frontend)
- Aggiungere funzionalità al login (reCAPTCHA, 2FA, OAuth)
- Modificare dati autori ed editori
- Interagire con API esterne
- Elaborare immagini
- Estendere filtri catalogo

**Aggiornamento Installazioni Esistenti:**
Se hai già un'installazione Pinakes precedente al plugin system:
```bash
# Esegui la migration
mysql -u username -p database_name < data/migrations/create_plugins_table.sql
```

Vedi `docs/PLUGIN_SYSTEM.md` sezione "Aggiornamento Installazioni Esistenti" per dettagli completi.

---

## 🌐 API RESTful

Pinakes include un'API RESTful completa per integrazioni esterne e applicazioni di terze parti:

### Autenticazione
- **Metodo**: API Key based (header `X-API-Key`)
- **Gestione chiavi**: Dashboard admin → API Settings
- **Scadenza**: Configurabile (30/60/90 giorni o mai)
- **Rate limiting**: 100 richieste/minuto per chiave (configurabile)

### Endpoints Disponibili

| Endpoint | Metodo | Descrizione | Parametri |
|----------|--------|-------------|-----------|
| `/api/v1/books` | GET | Lista libri paginata | `page`, `limit`, `search`, `author`, `genre`, `year` |
| `/api/v1/books/{id}` | GET | Dettagli libro specifico | - |
| `/api/v1/books/{id}/copies` | GET | Copie fisiche disponibili | - |
| `/api/v1/authors` | GET | Lista autori | `page`, `limit`, `search` |
| `/api/v1/authors/{id}` | GET | Dettagli autore + opere | - |
| `/api/v1/publishers` | GET | Lista editori | `page`, `limit`, `search` |
| `/api/v1/publishers/{id}` | GET | Dettagli editore + catalogo | - |
| `/api/v1/genres` | GET | Catalogo generi | - |
| `/api/v1/dewey` | GET | Classificazione Dewey completa | `search`, `class` |
| `/api/v1/search` | GET | Ricerca globale | `q`, `type`, `limit` |
| `/api/v1/availability/{isbn}` | GET | Disponibilità rapida via ISBN | - |

### Formato Risposte
```json
{
  "status": "success",
  "data": {
    "id": 123,
    "title": "Don Chisciotte della Mancia",
    "author": "Miguel de Cervantes",
    "isbn": "9788817123456",
    "year": 1605,
    "available_copies": 2
  },
  "meta": {
    "timestamp": "2025-11-04T10:30:00Z",
    "version": "v1"
  }
}
```

### Caratteristiche API
- **Formato**: JSON con encoding UTF-8
- **Versioning**: URI-based (`/api/v1/`)
- **Paginazione**: Link Header (RFC 5988)
- **CORS**: Configurabile per domini autorizzati
- **HTTPS**: Enforced in produzione
- **Cache**: ETag e Last-Modified headers
- **Errori**: Codici HTTP standard + messaggi descrittivi
- **Documentazione**: Swagger/OpenAPI 3.0 compatibile
- **Throttling**: 429 Too Many Requests con Retry-After

### Esempi Utilizzo

**Ricerca libro per titolo:**
```bash
curl -H "X-API-Key: your_key_here" \
  "https://tuodominio.com/api/v1/books?search=cervantes&limit=10"
```

**Dettagli autore:**
```bash
curl -H "X-API-Key: your_key_here" \
  "https://tuodominio.com/api/v1/authors/42"
```

**Disponibilità rapida via ISBN:**
```bash
curl -H "X-API-Key: your_key_here" \
  "https://tuodominio.com/api/v1/availability/9788817123456"
```

### Sicurezza API
- ✅ API Key rotation automatica
- ✅ IP whitelisting opzionale
- ✅ Rate limiting con sliding window
- ✅ Request logging completo
- ✅ Input validation rigorosa
- ✅ SQL injection protection
- ✅ XSS prevention su output

---

## 🔍 SEO e Ottimizzazione

Pinakes è ottimizzato per i motori di ricerca con tecniche SEO moderne:

### Sitemap XML Auto-generata
- **URL**: `https://tuodominio.com/sitemap.xml`
- **Aggiornamento**: Automatico ad ogni modifica
- **Contenuto**:
  - Tutti i libri con `<lastmod>` (data ultima modifica)
  - Pagine archivio autori (priorità 0.8)
  - Pagine archivio editori (priorità 0.7)
  - Pagine statiche (homepage, catalogo, contatti)
  - Generi e classificazioni Dewey
- **Compressione**: gzip-ready
- **Validazione**: XML Schema conforme
- **Submit**: Google Search Console + Bing Webmaster Tools

**Rigenera manualmente:**
```bash
php scripts/generate-sitemap.php
```

### Schema.org Structured Data
Markup JSON-LD su tutte le pagine rilevanti:

**Pagina Libro:**
```json
{
  "@context": "https://schema.org",
  "@type": "Book",
  "name": "Don Chisciotte della Mancia",
  "author": {
    "@type": "Person",
    "name": "Miguel de Cervantes"
  },
  "isbn": "9788817123456",
  "datePublished": "1605",
  "publisher": {
    "@type": "Organization",
    "name": "Rizzoli"
  },
  "aggregateRating": {
    "@type": "AggregateRating",
    "ratingValue": "4.8",
    "reviewCount": "42"
  }
}
```

**Pagina Autore:**
```json
{
  "@context": "https://schema.org",
  "@type": "Person",
  "name": "Gabriel García Márquez",
  "birthDate": "1927-03-06",
  "nationality": "Colombiano",
  "award": "Premio Nobel per la Letteratura (1982)"
}
```

**BreadcrumbList:**
```json
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    {"@type": "ListItem", "position": 1, "name": "Home", "item": "/"},
    {"@type": "ListItem", "position": 2, "name": "Catalogo", "item": "/catalogo"},
    {"@type": "ListItem", "position": 3, "name": "Don Chisciotte", "item": "/libro/123"}
  ]
}
```

### Meta Tags Dinamici
**Open Graph (Facebook, LinkedIn):**
```html
<meta property="og:title" content="Don Chisciotte della Mancia - Miguel de Cervantes">
<meta property="og:description" content="Romanzo capolavoro della letteratura spagnola...">
<meta property="og:image" content="https://tuodominio.com/uploads/covers/cervantes.jpg">
<meta property="og:type" content="book">
<meta property="book:isbn" content="9788817123456">
<meta property="book:author" content="Miguel de Cervantes">
```

**Twitter Cards:**
```html
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Don Chisciotte della Mancia">
<meta name="twitter:description" content="...">
<meta name="twitter:image" content="...">
```

**Canonical URLs:**
```html
<link rel="canonical" href="https://tuodominio.com/libro/don-chisciotte-123">
```

### URL SEO-Friendly
- ✅ **Semantici**: `/autore/gabriel-garcia-marquez` (non `/author.php?id=42`)
- ✅ **Lowercase**: Tutti minuscoli
- ✅ **Hyphen-separated**: Trattini al posto di underscore
- ✅ **No stop words**: Articoli rimossi
- ✅ **Transliteration**: Caratteri speciali normalizzati
- ✅ **301 redirects**: URL vecchi reindirizzati permanentemente

**Esempi:**
- `/libro/il-nome-della-rosa-umberto-eco`
- `/autore/jose-saramago`
- `/editore/einaudi`
- `/genere/narrativa-classica`
- `/catalogo?anno=2024&genere=giallo`

### Performance Optimization
- **Asset Minification**: Webpack minify JS/CSS (produzione)
- **Image Optimization**:
  - Lazy loading con Intersection Observer
  - Responsive images `<picture>` + `srcset`
  - WebP con fallback JPEG/PNG
  - Dimensioni ottimizzate (max 1920x1080)
- **HTTP/2 Push**: Header Link per asset critici
- **CDN-ready**: Asset URL configurabili per CDN
- **Gzip Compression**: Abilitata di default
- **Browser Caching**: Cache-Control headers ottimizzati (1 anno per asset)
- **Critical CSS**: Above-the-fold CSS inline
- **Defer JavaScript**: Script non critici posticipati

### Core Web Vitals
- **LCP** (Largest Contentful Paint): < 2.5s
- **FID** (First Input Delay): < 100ms
- **CLS** (Cumulative Layout Shift): < 0.1
- **TTFB** (Time to First Byte): < 600ms

### Robots.txt
```
User-agent: *
Allow: /
Disallow: /admin/
Disallow: /api/
Disallow: /installer/
Disallow: /storage/logs/

Sitemap: https://tuodominio.com/sitemap.xml
```

### Google Analytics / Tag Manager
```html
<!-- Configurabile da admin panel -->
<script async src="https://www.googletagmanager.com/gtag/js?id=GA_MEASUREMENT_ID"></script>
```

### Indicizzazione Avanzata
- ✅ **hreflang tags**: Supporto multi-lingua (se necessario)
- ✅ **Alternate links**: Mobile-specific URLs
- ✅ **Pagination**: `rel="next"` e `rel="prev"`
- ✅ **Noindex headers**: Pagine duplicate/sensibili
- ✅ **Rich snippets**: Rating, disponibilità, prezzo (se applicabile)

---

## 🛠️ Stack Tecnologico

### Backend
- **Framework**: Slim 4.13 (PHP microframework)
- **Database**: MySQL con PDO/MySQLi
- **Template Engine**: PHP nativo
- **Email**: PHPMailer 6.10
- **PDF**: TCPDF 6.10
- **Routing**: FastRoute (via Slim)
- **Dependency Injection**: PSR-11 Container

### Frontend
- **CSS Frameworks**:
  - Bootstrap 5.3.8 (UI components)
  - TailwindCSS 3.4.18 (utility-first)
- **JavaScript**:
  - jQuery 3.7.1
  - DataTables 2.3.4 (tabelle admin)
  - SweetAlert2 11.26.3 (alert moderni)
  - Flatpickr 4.6.13 (date picker self-hosted)
  - Uppy 4.5.3 (file upload self-hosted)
  - Sortable.js 1.15.6 (drag & drop)
  - Choices.js 11.1.0 (select avanzati)
  - TinyMCE 7.x (WYSIWYG editor self-hosted)
- **Build Tool**: Webpack 5.x
- **Icons**: Font Awesome 6.7.2 (self-hosted)
- **Font**: Inter (self-hosted per privacy)

### Sicurezza
- **CSRF Protection**: Token-based
- **Password Hashing**: bcrypt (cost 12)
- **Session Management**: Secure, HTTPOnly, SameSite
- **Input Validation**: Server-side con whitelist
- **SQL Protection**: Prepared statements
- **XSS Protection**: htmlspecialchars() su output
- **Content Security Policy**: Configurabile

### Database
- 35 tabelle normalizzate (30 core + 5 plugin system)
- Indici ottimizzati per performance
- Foreign keys per integrità referenziale
- Triggers per consistency automatica
- Stored procedures per operazioni complesse
- **Tabelle Plugin System**:
  - `plugins` - Registry e metadata plugin
  - `plugin_hooks` - Registrazione hook
  - `plugin_settings` - Configurazioni plugin
  - `plugin_data` - Storage dati plugin
  - `plugin_logs` - Log attività plugin

---

## 💻 Sviluppo

### Compilazione Frontend
```bash
cd frontend

# Development (watch mode)
npm run start

# Production build
npm run build
```

### Struttura Progetto
```
pinakes/
├── app/
│   ├── Controllers/     # Business logic
│   ├── Models/          # Database models
│   ├── Repositories/    # Data access layer
│   ├── Routes/          # Route definitions
│   ├── Support/         # Helper classes (HookManager, PluginManager)
│   └── Views/           # Templates PHP
├── config/              # Configurazioni
├── data/
│   └── migrations/      # Database migrations (plugin tables)
├── docs/                # Documentazione
│   ├── PLUGIN_SYSTEM.md      # Guida completa plugin system
│   ├── PLUGIN_HOOKS.md       # Riferimento hook disponibili
│   └── examples/             # Plugin di esempio
│       └── example-plugin/   # Book Rating Plugin
├── frontend/            # Asset frontend
│   ├── css/
│   ├── js/
│   └── webpack.config.js
├── installer/           # Sistema installazione
│   └── database/
│       └── schema.sql   # Include tabelle plugin
├── public/              # Document root
│   ├── assets/          # Build webpack output
│   └── uploads/         # File utente
├── scripts/             # Maintenance scripts
├── storage/             # Logs e cache
│   └── plugins/         # Directory plugin installati
├── vendor/              # Composer dependencies
├── .env.example         # Environment template
├── composer.json        # PHP dependencies
├── package.json         # NPM dependencies
└── version.json         # App version
```

### Convenzioni Codice
- **PSR-12** coding standard per PHP
- **Camel Case** per metodi
- **Pascal Case** per classi
- **Snake Case** per database
- **Kebab Case** per URL/file CSS
- **4 spazi** indentazione
- **UTF-8** encoding
- **LF** line endings

---

## 🤝 Contribuire

Contributi, issues e feature requests sono benvenuti!

### Come Contribuire
1. Fork del progetto
2. Crea feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit modifiche (`git commit -m 'Add AmazingFeature'`)
4. Push su branch (`git push origin feature/AmazingFeature`)
5. Apri Pull Request

### Standard Commit
- **feat**: Nuova funzionalità
- **fix**: Bug fix
- **docs**: Documentazione
- **style**: Formattazione
- **refactor**: Refactoring
- **test**: Test
- **chore**: Manutenzione

---

## 📄 Licenza

Questo progetto è licenziato sotto **ISC License** - vedi [LICENSE](LICENSE) per dettagli.

---

## 🙏 Crediti

### Sviluppato da
- Fabio D'Alessandro

### Librerie e Framework Utilizzati
- [Slim Framework](https://www.slimframework.com/) - PHP microframework
- [Bootstrap](https://getbootstrap.com/) - CSS framework
- [TailwindCSS](https://tailwindcss.com/) - Utility-first CSS
- [jQuery](https://jquery.com/) - JavaScript library
- [DataTables](https://datatables.net/) - Table plugin
- [SweetAlert2](https://sweetalert2.github.io/) - Beautiful alerts
- [Flatpickr](https://flatpickr.js.org/) - Date picker
- [Uppy](https://uppy.io/) - File uploader
- [TinyMCE](https://www.tiny.cloud/) - WYSIWYG editor
- [PHPMailer](https://github.com/PHPMailer/PHPMailer) - Email library
- [TCPDF](https://tcpdf.org/) - PDF generation
- [Font Awesome](https://fontawesome.com/) - Icon library
- [Inter Font](https://rsms.me/inter/) - Typeface

### Ispirazione
Il nome "Pinakes" (Πίνακες) onora **Callimaco di Cirene** (305-240 a.C.) e il suo monumentale catalogo della Biblioteca di Alessandria (245 a.C.) - **il primo sistema di catalogazione bibliotecaria della storia**. Questo progetto prosegue quella tradizione millenaria di organizzazione e accessibilità del sapere. Per la storia completa, vedi la sezione "Storia del Nome" all'inizio di questo README.

---

**⭐ Se questo progetto ti è utile, lascia una stella su GitHub!**

---

<p align="center">
  Made with ❤️ and ☕ by Fabio D'Alessandro<br>
  Powered by Pinakes v0.1.1
</p>
