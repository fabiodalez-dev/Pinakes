# Guida Sistema di Aggiornamento Pinakes

Questa guida definisce il processo completo per creare release GitHub compatibili con l'auto-updater di Pinakes e descrive in dettaglio come funziona il sistema di aggiornamento.

**Versione documento:** 0.4.0
**Ultimo aggiornamento:** Dicembre 2025

---

## Indice

1. [Panoramica del Sistema](#panoramica-del-sistema)
2. [Come Creare una Release](#come-creare-una-release)
3. [Come Funziona l'Aggiornamento](#come-funziona-laggiornamento)
4. [Database Migrations](#database-migrations)
5. [Gestione File e Directory](#gestione-file-e-directory)
6. [Sistema di Backup](#sistema-di-backup)
7. [Maintenance Mode](#maintenance-mode)
8. [Gestione Errori e Rollback](#gestione-errori-e-rollback)
9. [Checklist Pre-Release](#checklist-pre-release)
10. [Cosa Può Rompere l'Aggiornamento](#cosa-può-rompere-laggiornamento)
11. [Troubleshooting](#troubleshooting)

---

## Panoramica del Sistema

### Componenti Principali

| Componente | File | Responsabilità |
|------------|------|----------------|
| **Updater** | `app/Support/Updater.php` | Core logic: download, backup, install, migrate |
| **UpdateController** | `app/Controllers/UpdateController.php` | API endpoints e UI controller |
| **Updates View** | `app/Views/admin/updates.php` | Interfaccia utente admin |
| **Maintenance Handler** | `public/index.php` (linee 78-137) | Gestione maintenance mode |
| **Migrations** | `installer/database/migrations/` | Script SQL di migrazione |

### API Endpoints

| Endpoint | Metodo | Descrizione |
|----------|--------|-------------|
| `/admin/updates` | GET | Pagina gestione aggiornamenti |
| `/admin/updates/check` | GET | Verifica disponibilità update |
| `/admin/updates/perform` | POST | Esegue l'aggiornamento |
| `/admin/updates/backup` | POST | Crea backup manuale |
| `/admin/updates/backups` | GET | Lista backup disponibili |
| `/admin/updates/backup/delete` | POST | Elimina un backup |
| `/admin/updates/backup/download` | GET | Scarica file backup |

---

## Come Creare una Release

### Step 1: Aggiornare version.json

```json
{
  "name": "Pinakes",
  "version": "X.Y.Z",
  "description": "Library Management System - Sistema di Gestione Bibliotecaria"
}
```

**Regole di Versioning (Semantic Versioning):**
- **MAJOR** (X.0.0): Breaking changes, riscritture importanti
- **MINOR** (0.X.0): Nuove funzionalità, backward-compatible
- **PATCH** (0.0.X): Bug fixes, miglioramenti minori

### Step 2: Creare Migration SQL (se necessario)

Se ci sono modifiche al database, creare il file:

```
installer/database/migrations/migrate_X.Y.Z.sql
```

**Formato del file migration:**

```sql
-- Migration script for Pinakes X.Y.Z
-- Description: Breve descrizione delle modifiche
-- Date: YYYY-MM-DD
-- Compatibility: MySQL 5.7+ and MariaDB 10.0+

-- ============================================================
-- 1. SEZIONE DESCRITTIVA
-- Descrizione di cosa fa questa sezione
-- ============================================================

-- Aggiungere colonne (senza IF NOT EXISTS - MySQL non supporta per ALTER)
ALTER TABLE nome_tabella ADD COLUMN nuova_colonna VARCHAR(255) DEFAULT NULL;

-- Creare tabelle (usare IF NOT EXISTS)
CREATE TABLE IF NOT EXISTS nuova_tabella (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campo VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aggiungere indici
CREATE INDEX idx_nome ON tabella(colonna);

-- End of migration
```

### Step 3: Sincronizzare schema.sql

**IMPORTANTE:** Aggiornare `installer/database/schema.sql` con le stesse modifiche strutturali per nuove installazioni. Lo schema deve SEMPRE contenere la struttura completa e aggiornata del database.

### Step 4: Aggiornare EXPECTED_TABLES (se nuove tabelle)

In `installer/classes/Installer.php`, aggiungere nuove tabelle:

```php
private const EXPECTED_TABLES = [
    // ... tabelle esistenti ...
    'nuova_tabella',
];
```

### Step 5: Traduzioni

Aggiungere nuove stringhe a `locale/en_US.json`:

```json
{
    "Nuova stringa italiana": "New English string"
}
```

### Step 6: Build Frontend Assets

```bash
# Assicurarsi di compilare gli asset prima della release
cd frontend && npm run build && cd ..
```

### Step 7: Commit e Tag

```bash
# Commit tutte le modifiche
git add -A
git commit -m "Release vX.Y.Z: descrizione"

# Creare tag
git tag vX.Y.Z

# Push
git push origin main --tags
```

### Step 8: Creare GitHub Release

#### Metodo 1: Usando GitHub CLI

```bash
gh release create vX.Y.Z \
  --title "Pinakes vX.Y.Z" \
  --notes "$(cat <<'EOF'
## What's New in vX.Y.Z

### Features
- Feature 1
- Feature 2

### Bug Fixes
- Fixed issue with...

### Database Changes
This version includes database migrations. The updater will automatically:
- Add `new_column` to `table_name`
- Create new `table_name` table

### Breaking Changes
None / List any breaking changes

### Upgrade Notes
- Backup your database before updating (done automatically)
- Clear browser cache after update if needed
EOF
)"

# Per pre-release (versioni beta/alpha)
gh release create vX.Y.Z-beta.1 \
  --title "Pinakes vX.Y.Z Beta 1" \
  --prerelease \
  --notes "Beta release for testing"
```

#### IMPORTANTE: Asset ZIP

**NON allegare asset ZIP manualmente!**

L'updater usa automaticamente `zipball_url` fornito da GitHub API, che include tutto il codice sorgente del repository.

Se alleghi un ZIP manuale con nome `pinakes*.zip`:
- L'updater lo userà INVECE del source code automatico
- Questo può causare problemi se il ZIP non contiene tutti i file necessari

---

## Come Funziona l'Aggiornamento

### Flusso Completo

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        ADMIN PANEL: /admin/updates                       │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 0: Verifica Requisiti                                              │
│ - PHP 8.1+                                                              │
│ - ZipArchive extension                                                  │
│ - Directory scrivibili (root, storage, backups)                         │
│ - Spazio disco minimo 100MB                                             │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 1: Attiva Maintenance Mode                                         │
│ File: storage/.maintenance                                              │
│ - Blocca accesso utenti (HTTP 503)                                      │
│ - Auto-expire dopo 30 minuti (safety net)                               │
│ - Permette accesso a /admin/updates, /assets/, /favicon.ico             │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 2: Crea Backup Database                                            │
│ Destinazione: storage/backups/update_YYYY-MM-DD_HHMMSS/database.sql     │
│ - Dump completo di tutte le tabelle                                     │
│ - Include struttura e dati                                              │
│ - Logged in tabella update_logs                                         │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 3: Download Package da GitHub                                      │
│ API: https://api.github.com/repos/fabiodalez-dev/Pinakes/releases/tags/ │
│ - Cerca asset pinakes*.zip (se presente)                                │
│ - Altrimenti usa zipball_url (source code automatico)                   │
│ - Timeout download: 300 secondi                                         │
│ - Salva in: /tmp/pinakes_update_XXXX/update.zip                         │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 4: Estrai Package                                                  │
│ - Verifica ZIP valido                                                   │
│ - Estrai in /tmp/pinakes_update_XXXX/extracted/                         │
│ - GitHub aggiunge prefix (es. Pinakes-main/), gestito automaticamente   │
│ - Verifica presenza file richiesti: version.json, app/, public/,        │
│   installer/                                                            │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 5: Backup File Applicazione (per Rollback)                         │
│ Destinazione: /tmp/pinakes_app_backup_TIMESTAMP/                        │
│ Directory backuppate: app/, config/, locale/, public/assets/,           │
│                       installer/, version.json                          │
│ - Permette rollback atomico in caso di errore                           │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 6: Copia File                                                      │
│ - Copia ricorsiva da source a root                                      │
│ - Rispetta preservePaths (non sovrascrive)                              │
│ - Salta skipPaths (.git, node_modules)                                  │
│ - Protezione path traversal (rifiuta .., null bytes, symlinks)          │
│ - vendor/ incluso nel package (nessun Composer richiesto)               │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 7: Pulizia File Orfani                                             │
│ Directory controllate: app/, config/, locale/, installer/               │
│ - Rimuove file presenti nella vecchia versione ma non nella nuova       │
│ - Rispetta preservePaths                                                │
│ - Previene accumulo di classi/file obsoleti                             │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 8: Esegui Database Migrations                                      │
│ Cerca: installer/database/migrations/migrate_*.sql                      │
│ - Solo migrations con versione > current E <= target                    │
│ - Verifica se già eseguita (tabella migrations)                         │
│ - Rimuove commenti full-line (righe che iniziano con --)                │
│ - Esegue statement per statement (split su ;)                           │
│ - Registra in tabella migrations                                        │
│ - Ignora errori idempotenti: 1060, 1061, 1050                           │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 9: Fix Permessi File                                               │
│ - storage/*, public/uploads: 755 dirs, 644 files                        │
│ - .env: 600 (non world-readable)                                        │
│ - app/, config/, vendor/: read-only (755/644)                           │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 10: Cleanup e Finalizzazione                                       │
│ - Elimina file temporanei (/tmp/pinakes_update_*)                       │
│ - Elimina backup app se successo (non serve rollback)                   │
│ - Disattiva maintenance mode                                            │
│ - Reset OpCache (opcache_reset())                                       │
│ - Log completamento in update_logs                                      │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
                            ┌───────────────┐
                            │   SUCCESSO    │
                            └───────────────┘
```

---

## Database Migrations

### Tabella migrations

```sql
CREATE TABLE `migrations` (
    `id` int NOT NULL AUTO_INCREMENT,
    `version` varchar(20) NOT NULL,
    `filename` varchar(255) NOT NULL,
    `batch` int NOT NULL DEFAULT '1',
    `executed_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_version` (`version`)
);
```

### Tabella update_logs

```sql
CREATE TABLE `update_logs` (
    `id` int NOT NULL AUTO_INCREMENT,
    `from_version` varchar(20) NOT NULL,
    `to_version` varchar(20) NOT NULL,
    `status` enum('started','completed','failed','rolled_back'),
    `backup_path` varchar(500),
    `error_message` text,
    `started_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `completed_at` datetime,
    `executed_by` int,
    PRIMARY KEY (`id`)
);
```

### Logica di Esecuzione

```php
// Solo migrations con versione:
// - Maggiore della versione corrente
// - Minore o uguale alla versione target
if (version_compare($migrationVersion, $fromVersion, '>') &&
    version_compare($migrationVersion, $toVersion, '<=')) {
    // Esegui migration
}
```

### Errori SQL Ignorati (Idempotenza)

L'updater ignora automaticamente questi errori MySQL:

| Codice | Descrizione | Motivo |
|--------|-------------|--------|
| 1060 | Duplicate column name | Colonna già aggiunta in run precedente |
| 1061 | Duplicate key name | Indice già esiste |
| 1050 | Table already exists | Tabella già creata (usare IF NOT EXISTS) |

**NOTA:** L'errore 1068 (Multiple primary key) **NON è ignorato** perché indica un errore nella migration.

### Esempio Pratico

Aggiornamento da `0.2.0` a `0.4.0`:

```
Migrations disponibili:
- migrate_0.2.0.sql  ❌ Non eseguita (versione <= current)
- migrate_0.3.0.sql  ✅ Eseguita (0.3.0 > 0.2.0 AND <= 0.4.0)
- migrate_0.4.0.sql  ✅ Eseguita (0.4.0 > 0.2.0 AND <= 0.4.0)
- migrate_0.5.0.sql  ❌ Non eseguita (versione > target)
```

### Seeding Data nelle Migrations

Per inserire dati di default, usare la migration SQL:

```sql
-- ============================================================
-- SEEDING: Dati iniziali per nuovi utenti
-- ============================================================

-- Metodo 1: Con protezione duplicati (consigliato)
INSERT INTO settings (chiave, valore)
VALUES ('new_setting', 'default')
ON DUPLICATE KEY UPDATE valore = valore;

-- Metodo 2: Aggiornare record esistenti (es. backfill)
UPDATE utenti
SET privacy_accettata = 1,
    data_accettazione_privacy = data_registrazione
WHERE privacy_accettata = 0
  AND stato = 'attivo';
```

### Gestione Salti di Versione (Version Jump)

Quando un utente salta più versioni (es. da `0.2.0` a `0.5.0`):

**Migrations SQL: TUTTE le intermedie vengono eseguite**

```
Utente su 0.2.0 → aggiorna a 0.5.0

Le migrations nel package v0.5.0:
├── migrate_0.2.0.sql  ❌ Saltata (versione <= current)
├── migrate_0.3.0.sql  ✅ Eseguita in ordine
├── migrate_0.4.0.sql  ✅ Eseguita in ordine
└── migrate_0.5.0.sql  ✅ Eseguita in ordine
```

**CRITICO:** Ogni release DEVE contenere TUTTI i file migration storici. MAI eliminare i vecchi file `migrate_*.sql` dal repository!

---

## Gestione File e Directory

### preservePaths (Non Sovrascritti)

Questi file/directory NON vengono mai sovrascritti durante l'update:

```php
private array $preservePaths = [
    '.env',                  // Configurazione ambiente
    'storage/uploads',       // File caricati dagli utenti
    'storage/plugins',       // Plugin installati
    'storage/backups',       // Backup database
    'storage/cache',         // Cache applicazione
    'storage/logs',          // Log applicazione
    'public/uploads',        // Upload pubblici
    'public/.htaccess',      // Config Apache custom
    'public/robots.txt',     // SEO
    'public/favicon.ico',    // Favicon custom
    'public/sitemap.xml',    // Sitemap
    'CLAUDE.md',             // Documentazione sviluppo
];
```

### skipPaths (Mai Copiati)

Questi path vengono completamente ignorati nel package:

```php
private array $skipPaths = [
    '.git',           // Repository git
    'node_modules',   // Dipendenze frontend development
];
```

**NOTA:** `vendor/` è tracciato in git e incluso nel package GitHub per semplificare l'installazione (nessun Composer richiesto).

### Cosa DEVE Essere nel Repository

```
✅ INCLUSO (tracciato da Git):
├── app/                    # Codice PHP applicazione
├── config/                 # Configurazione
├── data/                   # Dati statici (Dewey, etc.)
├── frontend/               # Sorgenti frontend
├── installer/              # Installer e migrations
│   └── database/
│       └── migrations/     # CRITICO: tutti i file migrate_*.sql
├── locale/                 # Traduzioni
├── public/                 # Web root
│   └── assets/             # CSS/JS compilati
├── storage/                # Solo struttura directory (con .gitkeep)
├── vendor/                 # INCLUSO: dipendenze PHP (no Composer richiesto)
├── version.json            # CRITICO: versione corrente
├── composer.json           # Metadati Composer
└── composer.lock           # Lock dipendenze

❌ NON INCLUSO (in .gitignore):
├── .git/
├── .env                    # Credenziali locali
├── node_modules/
└── storage/backups/*       # Backup database
```

**NOTA:** `vendor/` è tracciato in git per semplificare l'installazione agli utenti che non hanno Composer. Gli unici file esclusi sono CHANGELOG.md e documentazione interna delle dipendenze.

---

## Sistema di Backup

### Backup Database

**Location:** `storage/backups/update_YYYY-MM-DD_HHMMSS/database.sql`

**Contenuto:**
- Struttura completa di tutte le tabelle (DROP + CREATE)
- Tutti i dati (INSERT statements)
- Header con versione e timestamp
- SET FOREIGN_KEY_CHECKS=0/1 per import sicuro

### Backup Applicazione (Rollback)

**Location:** `/tmp/pinakes_app_backup_TIMESTAMP/`

**Directory backuppate:**
- `app/`
- `config/`
- `locale/`
- `public/assets/`
- `installer/`
- `version.json`

**Nota:** Questo backup è temporaneo e viene eliminato dopo update riuscito.

---

## Maintenance Mode

### Attivazione

File: `storage/.maintenance`

```json
{
    "time": 1701792000,
    "message": "Aggiornamento in corso. Riprova tra qualche minuto."
}
```

### Comportamento

1. **Utenti normali:** HTTP 503 con pagina di manutenzione
2. **Admin su /admin/updates:** Accesso consentito
3. **Asset statici:** Accesso consentito
4. **Auto-expire:** Dopo 30 minuti il file viene ignorato (safety net)

---

## Gestione Errori e Rollback

### Errori Gestiti

| Fase | Errore | Azione |
|------|--------|--------|
| Download | Connessione fallita | Abort, no rollback necessario |
| Download | ZIP invalido | Abort, cleanup temp |
| Estrazione | Package incompleto | Abort, cleanup temp |
| Installazione | Copy fallita | Rollback automatico file |
| Migration | SQL error (non ignorabile) | Rollback automatico file |

### Rollback Automatico

Se l'errore avviene durante l'installazione (Step 6-8):

1. Ripristina file da backup temporaneo (`/tmp/pinakes_app_backup_*`)
2. Log errore in `update_logs`
3. Cleanup file temporanei
4. Disattiva maintenance mode
5. Reset OpCache

**IMPORTANTE:** Il database NON viene ripristinato automaticamente. In caso di migration fallita l'admin deve:
1. Ripristinare manualmente da `storage/backups/update_*/database.sql`
2. Correggere il file migration
3. Ritentare l'update

---

## Checklist Pre-Release

### Obbligatorio

- [ ] `version.json` aggiornato con nuova versione
- [ ] Migration SQL creata (se modifiche DB)
- [ ] `schema.sql` sincronizzato con migration (struttura identica)
- [ ] `EXPECTED_TABLES` aggiornato (se nuove tabelle)
- [ ] Traduzioni aggiunte a `locale/en_US.json`
- [ ] `public/assets/` compilato con `npm run build`
- [ ] **TUTTI i file migration storici presenti** (mai eliminare!)
- [ ] Test migration su database di prova
- [ ] Test PHP syntax: `find app -name "*.php" -exec php -l {} \;`

### Consigliato

- [ ] Changelog dettagliato nelle release notes
- [ ] Breaking changes documentati
- [ ] Test salto di versione (es. 0.2.0 → 0.4.0)

---

## Cosa Può Rompere l'Aggiornamento

### 1. Errori nelle Migrations SQL

#### Commenti inline (NON supportati)

```sql
-- SBAGLIATO: commento inline viene troncato
ALTER TABLE users ADD name VARCHAR(100); -- questo è un nome
```

```sql
-- CORRETTO: solo commenti full-line
-- Aggiunge colonna nome
ALTER TABLE users ADD name VARCHAR(100);
```

#### Stringhe con punto e virgola

```sql
-- SBAGLIATO: lo split su ; rompe la query
INSERT INTO settings VALUES ('msg', 'Ciao; come stai?');
```

**Workaround:** Evitare stringhe con `;` nelle migrations, o usare UPDATE separato.

#### Stored procedures, triggers, DELIMITER

```sql
-- NON SUPPORTATO: l'updater non gestisce DELIMITER
DELIMITER //
CREATE PROCEDURE foo()
BEGIN
    ...
END //
DELIMITER ;
```

**Soluzione:** Non usare stored procedures nelle migrations. Crearle manualmente o con script separato.

#### Commenti multi-linea con ; dentro

```sql
-- NON SUPPORTATO: il ; nel commento rompe lo split
/* Questa è una descrizione;
   con punto e virgola dentro */
```

**Soluzione:** Usare solo commenti full-line con `--`.

### 2. Errori di Schema

#### Multiple primary key (errore 1068 - NON ignorato)

```sql
-- SBAGLIATO: se la tabella ha già una PRIMARY KEY
ALTER TABLE foo ADD PRIMARY KEY (id);
```

#### Colonna già esistente

```sql
-- MySQL non supporta ADD COLUMN IF NOT EXISTS
-- Errore 1060 viene IGNORATO (safe)
ALTER TABLE foo ADD COLUMN bar INT;
```

### 3. File Mancanti nel Package

- **version.json mancante:** L'updater rifiuta il package
- **app/ mancante:** L'updater rifiuta il package
- **Migration storiche eliminate:** Gli utenti che saltano versioni avranno errori

### 4. Problemi di Permessi

- Directory root non scrivibile
- `storage/backups/` non scrivibile
- Spazio disco insufficiente (<100MB)

### 5. Dipendenze PHP Mancanti

Situazione improbabile dato che `vendor/` è tracciato in git. Se per qualche motivo manca:
- L'applicazione mostrerà errore "Dipendenze PHP Mancanti"
- Ripristinare da backup o riscaricare la release

---

## Troubleshooting

### Update Bloccato in Maintenance Mode

```bash
# Rimuovi manualmente il file di maintenance
rm storage/.maintenance
```

### Migration Fallita

1. Controlla `update_logs` per errore specifico
2. Ripristina database da `storage/backups/update_*/database.sql`:

```bash
mysql -u user -p database < storage/backups/update_XXXX/database.sql
```

3. Correggi file migration
4. Riprova update

### OpCache Non Aggiornata

```php
// Esegui manualmente (es. da shell PHP)
opcache_reset();
```

O riavvia PHP-FPM:

```bash
sudo systemctl restart php-fpm
```

### Permessi File Errati

```bash
# Fix permessi dopo update manuale
chmod -R 755 storage/
chmod 600 .env
```

### Package Mancante vendor/

Situazione rara dato che `vendor/` è tracciato in git. Se manca, scaricare nuovamente la release da GitHub o ripristinare da backup.

### Verifica Release GitHub

```bash
# Verifica che l'API GitHub restituisca la release
curl -s https://api.github.com/repos/fabiodalez-dev/Pinakes/releases/latest | jq '.tag_name, .zipball_url'

# Output atteso:
# "vX.Y.Z"
# "https://api.github.com/repos/fabiodalez-dev/Pinakes/zipball/vX.Y.Z"
```

---

## Note per Sviluppatori

### Timeout

`set_time_limit(0)` viene chiamato all'inizio di `performUpdate()` per evitare timeout su:
- Download di package grandi
- Copia di molti file
- Migrations complesse

### OpCache

`opcache_reset()` viene chiamato in `cleanup()` per:
- Forzare ricompilazione PHP
- Evitare "Class not found" con file vecchi in cache

### Sicurezza

- Path traversal protection (rifiuta `..`, null bytes)
- Symlink ignorati
- CSRF validation su tutte le POST
- Admin-only access su tutti gli endpoint
- Backup name validation (no directory traversal)

### Test Automatici

GitHub Actions CI testa automaticamente le migrations su:
- MySQL 8.0 con stato simulato pre-update
- Verifica che tutte le tabelle vengano create
- Verifica che le colonne GDPR esistano dopo migration

File: `.github/workflows/test-migrations.yml`

---

## Riferimenti

- **Updater.php:** `app/Support/Updater.php`
- **UpdateController:** `app/Controllers/UpdateController.php`
- **UI View:** `app/Views/admin/updates.php`
- **Maintenance Handler:** `public/index.php` (linee 78-137)
- **Migrations:** `installer/database/migrations/`
- **Schema:** `installer/database/schema.sql`
- **CI Tests:** `.github/workflows/test-migrations.yml`
