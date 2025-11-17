# 📦 Sistema Biblioteca - Installer

Installer automatico per **Sistema Biblioteca** - Sistema di gestione bibliotecaria completo.

## 🚀 Come Usare l'Installer

### 1. Requisiti di Sistema

Prima di iniziare, verifica di avere:

- **PHP 8.1+**
- **MySQL 5.7+ / MariaDB 10.3+**
- **Estensioni PHP richieste**:
  - PDO
  - PDO MySQL
  - MySQLi
  - Mbstring
  - JSON
  - GD
  - Fileinfo

### 2. Avviare l'Installer

```bash
# Avvia il server PHP con router.php
php -S localhost:8000 router.php

# Apri il browser e vai a:
# http://localhost:8000
```

L'applicazione rileverà automaticamente che non è installata e ti reindirizzerà all'installer.

---

## 📋 Passi dell'Installazione

### Step 1: Verifica Requisiti
- ✅ Controlla versione PHP
- ✅ Verifica estensioni PHP
- ✅ Controlla permessi directory

### Step 2: Configurazione Database
- 📝 Inserisci credenziali database
- 🧪 Test connessione database
- 🔍 Auto-detect socket MySQL (se disponibile)
- 💾 Creazione file `.env`

### Step 3: Installazione Database
- 📊 Import schema (38 tabelle)
- ⚙️ Import trigger (2 trigger)
- 📦 Import dati essenziali:
  - 1369 classificazioni Dewey
  - 308 generi letterari
  - 7 template email
  - Contenuti CMS placeholder
- ✅ Verifica installazione

### Step 4: Creazione Admin
- 👤 Creazione primo utente amministratore
- 🔑 Generazione automatica codice tessera
- 🔐 Password hashing sicuro (bcrypt)

### Step 5: Impostazioni Applicazione
- 🏷️ Nome applicazione
- 🖼️ Logo (opzionale)
- 💾 Salvataggio in `system_settings`

### Step 6: Configurazione Email
- 📧 Scelta driver email (mail/phpmailer/smtp)
- ⚙️ Configurazione SMTP (se selezionato)
- 📨 Email mittente e nome visualizzato

### Step 7: Completamento
- 🎉 Riepilogo installazione
- 🔒 Creazione lock file (`.installed`)
- 🗑️ Opzione eliminazione installer

---

## 🔐 File `.env` Generato

L'installer crea automaticamente un file `.env` **configurato per produzione**:

```env
# Database
DB_HOST=localhost
DB_USER=your_user
DB_PASS=your_password
DB_NAME=biblioteca
DB_PORT=3306
DB_SOCKET=

# Environment - PRODUZIONE
APP_ENV=production
APP_DEBUG=false
DISPLAY_ERRORS=false
SESSION_LIFETIME=3600
```

### 📝 Nota per Development

Se stai installando per **sviluppo locale**, modifica `.env` dopo l'installazione:

```env
APP_ENV=development
APP_DEBUG=true
DISPLAY_ERRORS=true
```

Vedi `DEVELOPMENT.md` per dettagli.

---

## 🗄️ Database

### Schema Creato

L'installer crea **38 tabelle**:

**Core**:
- `users` - Utenti sistema
- `user_roles` - Ruoli utenti
- `notifications` - Sistema notifiche

**Biblioteca**:
- `libri` - Catalogo libri
- `autori` - Autori
- `editori` - Case editrici
- `generi` - Generi letterari
- `classificazione` - Classificazione Dewey
- `collocazione_*` - Scaffali/mensole/posizioni
- `prestiti` - Gestione prestiti
- `prenotazioni` - Gestione prenotazioni
- `wishlist` - Liste desideri utenti

**CMS**:
- `cms_pages` - Pagine statiche
- `home_content` - Contenuti homepage
- `email_templates` - Template email
- `system_settings` - Impostazioni sistema

### Dati Precaricati

- ✅ **1369 classificazioni Dewey** complete
- ✅ **308 generi letterari**
- ✅ **7 template email** (conferma registrazione, reset password, etc.)
- ✅ **Contenuti CMS** placeholder (homepage, chi siamo, etc.)

---

## 🔒 Sicurezza

### Protezioni Installate

L'installer implementa:

- **Lock File System**: File `.installed` previene re-installazione
- **Password Hashing**: bcrypt per password admin
- **CSRF Token**: Integrato in tutte le form
- **Prepared Statements**: Tutte le query DB
- **Input Validation**: Tutti i campi form validati
- **File Upload Security**: Validazione tipo/dimensione/estensione
- **Session Security**: httpOnly, secure, samesite=Strict
- **Security Headers**: CSP, XSS, Frame Options, etc.

### File Sensibili

Il file `.env` contiene credenziali sensibili:

```bash
# IMPORTANTE: Mai committare .env!
echo ".env" >> .gitignore
```

---

## 🗑️ Eliminazione Installer

**IMPORTANTE**: Per sicurezza, elimina la cartella `installer/` dopo l'installazione.

Puoi farlo:

1. **Tramite interfaccia** - Step 7 dell'installer
2. **Manualmente**:
   ```bash
   rm -rf installer/
   ```

Il lock file `.installed` previene esecuzioni accidentali anche se dimentichi di eliminare l'installer.

---

## 🔧 Troubleshooting

### Problema: 404 su /installer/

**Soluzione**: Usa `router.php`

```bash
# ❌ NON funziona
php -S localhost:8000 -t public/

# ✅ Funziona
php -S localhost:8000 router.php
```

### Problema: CSS non caricato

**Causa**: Server non usa router.php

**Soluzione**: Vedi sopra

### Problema: Errore import trigger

**Causa**: I trigger usano DELIMITER (MySQL client-specific)

**Soluzione**: L'installer ora gestisce automaticamente DELIMITER

### Problema: Permessi directory negati

**Soluzione**:
```bash
chmod 755 .
chmod 777 uploads storage backups
```

### Problema: Database già esistente

Se vuoi reinstallare:

```bash
# Opzione 1: Elimina lock file
rm .installed
rm .env

# Opzione 2: Usa parametro force
# http://localhost:8000/installer/?force=1
```

---

## 📚 File Creati dall'Installer

```
biblioteca/
├── .env                    # ✅ Configurazione ambiente
├── .installed              # ✅ Lock file (nella root)
├── .htaccess              # ✅ Apache rewrite rules
├── installer/
├── uploads/               # ✅ Directory upload (777)
├── storage/               # ✅ Directory storage (777)
└── backups/               # ✅ Directory backup (777)
```

---

## 🎯 Requisiti Produzione

Prima del deploy in produzione, verifica:

- [x] ✅ HTTPS configurato sul server
- [x] ✅ `.env` con APP_ENV=production (fatto dall'installer)
- [x] ✅ APP_DEBUG=false (fatto dall'installer)
- [x] ✅ Permessi corretti (755/777)
- [x] ✅ Backup database configurato
- [x] ✅ Installer eliminato
- [x] ✅ `.env` non committato in Git

Vedi `PRODUCTION-READINESS.md` per checklist completa.

---

## 📞 Supporto

Per problemi con l'installer:

1. Controlla `TEST-INSTALLER.md` per testing checklist
2. Verifica `START-SERVER.md` per comandi server
3. Leggi log in `storage/logs/`

---

**Versione Installer**: 1.0
**Data**: 2025-10-06
