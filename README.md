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
- **Importazione automatica dati** da servizi ISBN esterni
- **Gestione copie fisiche multiple** con sistema scaffali/mensole/posizioni
  - Tracciamento posizione fisica di ogni copia
  - Numerazione copie automatica
  - Stati: disponibile, prestato, in manutenzione
- **Classificazione Dewey Decimal** integrata (1369 classificazioni)
- **Ricerca avanzata** con filtri multipli (titolo, autore, anno, genere)
- **Sistema tag** per categorizzazione flessibile
- **Upload copertine** con gestione intelligente URL
- **Pagine archivio** dedicate per autori ed editori
  - Vista completa opere per autore
  - Catalogo per casa editrice
  - Statistiche e contatori

### 🔄 Sistema Prestiti e Prenotazioni
- **Gestione prestiti completa** con date inizio/fine/scadenza
- **Approvazione richieste** da pannello admin con workflow
- **Sistema prenotazioni avanzato**
  - Code gestite automaticamente
  - Notifiche disponibilità libro
  - Prenotazioni multiple per utente
  - Scadenza automatica prenotazioni
- **Wishlist personale utenti**
  - Lista desideri personalizzata
  - Notifiche email quando libro disponibile
  - Gestione priorità wishlist
- **Scadenze automatiche** e promemoria email (3 giorni prima)
- **Notifiche prestiti scaduti** automatiche
- **Cronologia prestiti** completa per utente
- **Stati prestito** (pendente, attivo, scaduto, restituito, rifiutato)

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
  - Rating a stelle (1-5)
  - Testo recensione con validazione
  - Moderazione admin/staff
  - Approvazione/rifiuto recensioni
  - Filtro contenuti inappropriati
  - Display pubblico recensioni approvate
- **Condivisione social** (Facebook, Twitter, WhatsApp, LinkedIn)
- **Copia link diretto** al libro
- **Feedback utenti** raccolto e gestito
- **Messaggi contatti** via form con anti-spam

### 🎨 CMS e Personalizzazione
- **Pagine CMS** editabili (Chi Siamo, Privacy, Termini)
- **Homepage features** personalizzabili
- **Editor WYSIWYG** TinyMCE integrato
- **Sistema settings** centralizzato
- **Logo personalizzabile** e branding

### 📊 Pannello Admin
- **Dashboard completa** con statistiche
- **Gestione libri, utenti, prestiti** centralizzata
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

### 2. Install Dependencies
```bash
# Backend dependencies (Composer)
composer install --no-dev --optimize-autoloader

# Frontend dependencies (NPM)
cd frontend
npm install
npm run build
cd ..
```

### 3. Configurazione Database
Crea un database MySQL e un utente dedicato:
```sql
CREATE DATABASE pinakes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pinakes_user'@'localhost' IDENTIFIED BY 'strong_password';
GRANT ALL PRIVILEGES ON pinakes.* TO 'pinakes_user'@'localhost';
FLUSH PRIVILEGES;
```

### 4. Configurazione Ambiente
```bash
cp .env.example .env
# Modifica .env con i tuoi parametri
```

### 5. Installer Web
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

### 6. Primo Accesso
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

---

## 🌐 API RESTful

Pinakes include un'API RESTful completa per integrazioni esterne:

- **Autenticazione**: API Key based
- **Endpoints disponibili**:
  - `/api/books` - Ricerca e dettagli libri
  - `/api/authors` - Informazioni autori
  - `/api/publishers` - Informazioni editori
  - `/api/genres` - Catalogo generi
  - `/api/dewey` - Classificazione Dewey
- **Formato**: JSON con encoding UTF-8
- **Rate limiting**: Protezione anti-abuse
- **Documentazione**: Swagger/OpenAPI compatibile
- **CORS**: Configurabile per domini autorizzati

**Esempio utilizzo**:
```bash
curl -H "X-API-Key: your_key_here" \
  https://tuodominio.com/api/books?search=cervantes
```

---

## 🔍 SEO e Ottimizzazione

Pinakes è ottimizzato per i motori di ricerca:

- **Sitemap XML** auto-generata
  - Libri con lastmod
  - Autori con priorità
  - Editori con priorità
  - Pagine statiche
  - Aggiornamento automatico
- **Schema.org markup**
  - Book structured data
  - Person (autori)
  - Organization (editori)
  - BreadcrumbList
- **Meta tags dinamici**
  - Open Graph per social sharing
  - Twitter Cards
  - Canonical URLs
- **URL semantici** (SEO-friendly)
- **Performance optimization**
  - Asset minification
  - Image lazy loading
  - CDN-ready
- **Robots.txt** configurabile
- **Google Analytics** ready

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
- 30 tabelle normalizzate
- Indici ottimizzati per performance
- Foreign keys per integrità referenziale
- Triggers per consistency automatica
- Stored procedures per operazioni complesse

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
│   ├── Support/         # Helper classes
│   └── Views/           # Templates PHP
├── config/              # Configurazioni
├── frontend/            # Asset frontend
│   ├── css/
│   ├── js/
│   └── webpack.config.js
├── installer/           # Sistema installazione
├── public/              # Document root
│   ├── assets/          # Build webpack output
│   └── uploads/         # File utente
├── scripts/             # Maintenance scripts
├── storage/             # Logs e cache
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
