# Sistema di Aggiornamento Pinakes - Guida Completa

---

## ⛔️ REGOLA FONDAMENTALE - LEGGERE PRIMA DI CREARE RELEASE ⛔️

### **I FILE IN `.gitignore` DEVONO ESSERE ESCLUSI ANCHE IN `.rsync-filter`!**

**Il file `.rsync-filter` NON legge automaticamente `.gitignore`!**

Quando aggiungi un file a `.gitignore`, **DEVI aggiungerlo ANCHE a `.rsync-filter`**, altrimenti verrà incluso nel pacchetto di release.

### Regole sui file Markdown (.md)

**NESSUN FILE `.md` NELLA ROOT DEVE ANDARE NELLA RELEASE, TRANNE:**
- `README.md` - Documentazione utente
- `LICENSE.md` - Licenza

**Tutti gli altri `.md` in root sono esclusi da `.rsync-filter` tramite:**
```bash
+ README.md      # Include esplicito
+ LICENSE.md     # Include esplicito
- CHANGELOG.md   # Escludi esplicitamente prima di *.md
- *.md           # Escludi tutti gli altri .md
```

### Regole sui file di test/debug

**I file di test e debug (`test_*.php`, `debug_*.php`, `fix-*.php`, ecc.) sono già esclusi da `.rsync-filter`.**

Non serve aggiungerli manualmente - il pattern `- test_*.php` e simili li escludono già.

**IMPORTANTE**: Se un file è in `.gitignore` come pattern (es. `test_*.php`), verifica che lo stesso pattern sia presente in `.rsync-filter`.

#### Checklist OBBLIGATORIA:

```bash
# Prima di OGNI release, verifica:
# 1. Ogni file/pattern in .gitignore che non deve essere distribuito
#    DEVE avere una corrispondente regola in .rsync-filter

# 2. Verifica che il pacchetto NON contenga file indesiderati:
./bin/build-release.sh
unzip -l releases/pinakes-vX.Y.Z.zip | grep -E "CHANGELOG|todo\.md|updater\.md|test_|debug_"
# ↑ NON deve trovare nulla!
```

#### File/pattern che DEVONO essere in ENTRAMBI i file:

| File/Pattern | `.gitignore` | `.rsync-filter` |
|--------------|--------------|-----------------|
| `CHANGELOG.md` | ✅ | ✅ |
| `todo.md` | ✅ | ✅ |
| `updater.md` | ✅ | ✅ |
| `*.md` (tranne README/LICENSE) | ✅ | ✅ `- *.md` |
| `test_*.php` | ✅ | ✅ |
| `debug_*.php` | ✅ | ✅ |
| `fix-*.php` | ✅ | ✅ |
| `.env` | ✅ | ✅ |

**⚠️ RICORDA: `.gitignore` e `.rsync-filter` sono DUE SISTEMI COMPLETAMENTE SEPARATI!**

---

Questa guida descrive in dettaglio il sistema di aggiornamento di Pinakes: come funziona, come creare nuove release, come gestire le migrazioni del database e tutti i file coinvolti.

---

## Indice

1. [Panoramica del Sistema](#panoramica-del-sistema)
2. [Struttura dei File](#struttura-dei-file)
3. [Come Creare una Nuova Release](#come-creare-una-nuova-release)
4. [File version.json](#file-versionjson)
5. [Migrazioni del Database](#migrazioni-del-database)
6. [File di Seed (Dati Iniziali)](#file-di-seed-dati-iniziali)
7. [Schema del Database](#schema-del-database)
8. [GitHub Actions (release.yml)](#github-actions-releaseyml)
9. [Script di Build (build-release.sh)](#script-di-build-build-releasesh)
10. [File .distignore](#file-distignore)
11. [Come Funziona l'Updater](#come-funziona-lupdater)
12. [Processo di Aggiornamento](#processo-di-aggiornamento)
13. [Rollback e Recovery](#rollback-e-recovery)
14. [Checklist per Nuova Versione](#checklist-per-nuova-versione)
15. [Troubleshooting](#troubleshooting)

---

## Panoramica del Sistema

Il sistema di aggiornamento di Pinakes permette agli utenti di aggiornare l'applicazione direttamente dal pannello di amministrazione, scaricando e installando automaticamente le nuove versioni da GitHub Releases.

### Componenti Principali

| Componente | Percorso | Descrizione |
|------------|----------|-------------|
| Updater Class | `app/Support/Updater.php` | Logica core per controllo, download e installazione aggiornamenti |
| Controller | `app/Controllers/UpdaterController.php` | Endpoint API per l'interfaccia admin |
| View | `app/Views/admin/update.php` | Interfaccia utente per gli aggiornamenti |
| Versione | `version.json` | File che contiene la versione corrente |
| Schema DB | `installer/database/schema.sql` | Schema completo del database |
| Migrazioni | `installer/database/migrations/` | Script SQL per aggiornamenti incrementali |
| Seed Data | `installer/database/data_*.sql` | Dati iniziali per ogni lingua |
| Build Script | `bin/build-release.sh` | Script per creare pacchetti di release |
| **Filter Rules** | `.rsync-filter` | **⭐ Regole include/exclude per rsync (PRIMARIO)** |
| Filter Legacy | `.distignore` | File da escludere (legacy, non supporta negazioni) |
| CI/CD | `.github/workflows/release.yml` | GitHub Action per build automatico |

---

## Struttura dei File

```
biblioteca/
├── version.json                    # Versione corrente (es. "0.4.0")
├── bin/
│   └── build-release.sh            # Script di build release
├── .rsync-filter                   # ⭐ PRIMARIO: Regole filtro rsync (include/exclude)
├── .distignore                     # Legacy: File da escludere (NON usare negazioni!)
├── .github/
│   └── workflows/
│       └── release.yml             # GitHub Action per release automatica
├── installer/
│   └── database/
│       ├── schema.sql              # Schema DB completo (per fresh install)
│       ├── data_it_IT.sql          # Dati seed italiano
│       ├── data_en_US.sql          # Dati seed inglese
│       └── migrations/
│           ├── migrate_0.3.0.sql   # Migrazione per v0.3.0
│           └── migrate_0.4.0.sql   # Migrazione per v0.4.0
├── storage/
│   └── plugins/                    # Plugin bundled (DEVONO essere nel pacchetto!)
│       ├── open-library/
│       ├── z39-server/
│       ├── api-book-scraper/
│       ├── digital-library/
│       └── dewey-editor/
├── app/
│   └── Support/
│       └── Updater.php             # Classe principale updater
└── releases/                       # Output delle build (locale, non in git)
    ├── pinakes-v0.4.0.zip
    ├── pinakes-v0.4.0.zip.sha256
    └── RELEASE_NOTES-v0.4.0.md
```

---

## Come Creare una Nuova Release

### Processo Completo Step-by-Step

#### 1. Aggiorna version.json

```json
{
  "name": "Pinakes",
  "version": "0.5.0",
  "description": "Library Management System - Sistema di Gestione Bibliotecaria"
}
```

**IMPORTANTE**: La versione in `version.json` DEVE corrispondere al tag Git.

#### 2. Crea il File di Migrazione

Se ci sono modifiche al database, crea un nuovo file di migrazione:

```bash
# Crea il file di migrazione
touch installer/database/migrations/migrate_0.5.0.sql
```

Esempio di contenuto migrazione:

```sql
-- Migration script for Pinakes 0.5.0
-- Description: [Descrizione delle modifiche]
-- Date: YYYY-MM-DD
-- Compatibility: MySQL 5.7+ and MariaDB 10.0+
-- Note: ALTER TABLE statements may produce "Duplicate column" warnings on re-run - this is safe

-- ============================================================
-- 1. NUOVA FUNZIONALITÀ
-- ============================================================

-- Aggiungi nuova colonna
ALTER TABLE `utenti` ADD COLUMN `nuova_colonna` VARCHAR(255) DEFAULT NULL AFTER `email`;

-- Crea nuova tabella
CREATE TABLE IF NOT EXISTS `nuova_tabella` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `nome` VARCHAR(255) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. BACKFILL (se necessario)
-- ============================================================

UPDATE `utenti` SET `nuova_colonna` = 'valore_default' WHERE `nuova_colonna` IS NULL;

-- End of migration
```

#### 3. Aggiorna schema.sql

**IMPORTANTE**: Ogni modifica nel file di migrazione DEVE essere riflessa in `schema.sql` per le fresh install.

```bash
# Apri schema.sql e aggiungi le nuove tabelle/colonne
# nella posizione corretta (ordine alfabetico delle tabelle)
```

#### 4. Aggiorna i File di Seed (se necessario)

Se aggiungi nuovi dati predefiniti:

**`installer/database/data_it_IT.sql`** (Italiano - Default):
```sql
-- Nuovi dati per system_settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `description`)
VALUES ('nuova_impostazione', 'valore_italiano', 'string', 'Descrizione impostazione')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `updated_at` = NOW();
```

**`installer/database/data_en_US.sql`** (Inglese):
```sql
-- New settings data
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `description`)
VALUES ('nuova_impostazione', 'english_value', 'string', 'Setting description')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `updated_at` = NOW();
```

#### 5. Aggiorna le Traduzioni

Se aggiungi nuove stringhe UI:

**`locale/en_US.json`**:
```json
{
  "Nuova stringa italiana": "New English string",
  "Altra stringa": "Another string"
}
```

#### 6. Build Locale (Opzionale - per testing)

```bash
# Rendi eseguibile lo script
chmod +x bin/build-release.sh

# Esegui la build
./bin/build-release.sh

# Output in releases/
ls -la releases/
# pinakes-v0.5.0.zip
# pinakes-v0.5.0.zip.sha256
# RELEASE_NOTES-v0.5.0.md
```

#### 7. Commit e Tag

```bash
# Commit delle modifiche
git add version.json installer/database/ locale/
git commit -m "chore: prepare release v0.5.0"

# Crea il tag
git tag v0.5.0

# Push del commit e del tag
git push origin main
git push origin v0.5.0
```

#### 8. Release Automatica

Una volta pushato il tag, GitHub Actions si attiva automaticamente:

1. Checkout del codice
2. Setup PHP 8.1 e Node.js 18
3. `composer install --no-dev --optimize-autoloader`
4. `npm ci && npm run build` (in frontend/)
5. Verifica che version.json corrisponda al tag
6. Esegue `bin/build-release.sh`
7. Crea la GitHub Release con i file allegati

---

## File version.json

Questo file è la **fonte di verità** per la versione dell'applicazione.

### Formato

```json
{
  "name": "Pinakes",
  "version": "X.Y.Z",
  "description": "Library Management System - Sistema di Gestione Bibliotecaria"
}
```

### Regole di Versioning (SemVer)

- **X (Major)**: Modifiche incompatibili con versioni precedenti
- **Y (Minor)**: Nuove funzionalità retrocompatibili
- **Z (Patch)**: Bug fix retrocompatibili

### Esempi

| Tipo di Modifica | Versione Precedente | Nuova Versione |
|------------------|---------------------|----------------|
| Bug fix | 0.4.0 | 0.4.1 |
| Nuova feature | 0.4.1 | 0.5.0 |
| Breaking change | 0.5.0 | 1.0.0 |

---

## Migrazioni del Database

Le migrazioni permettono di aggiornare il database esistente senza perdere dati.

### Posizione

```
installer/database/migrations/
├── migrate_0.3.0.sql
├── migrate_0.4.0.sql
└── migrate_0.5.0.sql  # Nuova migrazione
```

### Convenzione di Naming

```
migrate_X.Y.Z.sql
```

Dove `X.Y.Z` è la versione TARGET dell'applicazione.

### Struttura Consigliata

```sql
-- Migration script for Pinakes X.Y.Z
-- Description: [Breve descrizione delle modifiche]
-- Date: YYYY-MM-DD
-- Compatibility: MySQL 5.7+ and MariaDB 10.0+
-- Note: ALTER TABLE statements may produce "Duplicate column" warnings on re-run - this is safe

-- ============================================================
-- 1. SEZIONE 1 - Descrizione
-- ============================================================

[Statement SQL]

-- ============================================================
-- 2. SEZIONE 2 - Descrizione
-- ============================================================

[Statement SQL]

-- End of migration
```

### Regole per le Migrazioni

1. **Idempotenza**: Le migrazioni DEVONO essere ri-eseguibili senza errori
   - Usa `CREATE TABLE IF NOT EXISTS`
   - L'Updater ignora errori 1060 (Duplicate column), 1061 (Duplicate key), 1050 (Table exists)

2. **No Statement Complessi**: Evita:
   - Stored procedures
   - Triggers con `;` nel corpo
   - Commenti `/* */` multi-linea
   - Stringhe contenenti `;`

3. **Commenti**: Solo commenti `--` a inizio riga

4. **Foreign Keys**: Considera l'ordine di creazione tabelle

5. **Backfill**: Se aggiungi colonne NOT NULL, fornisci valori di default o backfill

### Come l'Updater Esegue le Migrazioni

```php
// In Updater.php - runMigrations()
foreach ($files as $file) {
    // Estrae versione dal filename (migrate_0.4.0.sql -> 0.4.0)
    if (preg_match('/migrate_(.+)\.sql$/', $filename, $matches)) {
        $migrationVersion = $matches[1];

        // Esegue solo se: fromVersion < migrationVersion <= toVersion
        if (version_compare($migrationVersion, $fromVersion, '>') &&
            version_compare($migrationVersion, $toVersion, '<=')) {
            // Esegue la migrazione
        }
    }
}
```

### Tabella migrations

L'Updater tiene traccia delle migrazioni eseguite:

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

---

## File di Seed (Dati Iniziali)

I file di seed contengono i dati iniziali per l'installazione.

### File

| File | Descrizione |
|------|-------------|
| `data_it_IT.sql` | Dati italiani (lingua predefinita) |
| `data_en_US.sql` | Dati inglesi |

### Tabelle con Seed Data

1. **generi** - Generi letterari
2. **system_settings** - Impostazioni di sistema
3. **email_templates** - Template email
4. **ruoli** - Ruoli utente (admin, bibliotecario, utente)
5. **stati_libri** - Stati dei libri
6. **stati_prestiti** - Stati dei prestiti

### Come Aggiungere Nuovi Seed Data

#### Per Impostazioni (system_settings)

**data_it_IT.sql:**
```sql
-- Impostazione singola
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `description`)
VALUES ('chiave_impostazione', 'valore', 'string', 'Descrizione italiana');

-- Oppure con ON DUPLICATE KEY UPDATE per sicurezza
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `description`)
VALUES ('chiave_impostazione', 'valore', 'string', 'Descrizione italiana')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `updated_at` = NOW();
```

**data_en_US.sql:**
```sql
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `description`)
VALUES ('chiave_impostazione', 'value', 'string', 'English description')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `updated_at` = NOW();
```

#### Per Template Email (email_templates)

**data_it_IT.sql:**
```sql
INSERT INTO `email_templates` (`template_key`, `locale`, `subject`, `body`, `placeholders`)
VALUES (
    'nuovo_template',
    'it_IT',
    'Oggetto Email',
    '<p>Ciao {{nome}},</p><p>Contenuto email...</p>',
    'nome,cognome,link'
);
```

**data_en_US.sql:**
```sql
INSERT INTO `email_templates` (`template_key`, `locale`, `subject`, `body`, `placeholders`)
VALUES (
    'nuovo_template',
    'en_US',
    'Email Subject',
    '<p>Hello {{first_name}},</p><p>Email content...</p>',
    'first_name,last_name,link'
);
```

### Differenze IT vs EN

| Aspetto | Italiano | Inglese |
|---------|----------|---------|
| Placeholder variabili | `{{nome}}`, `{{cognome}}` | `{{first_name}}`, `{{last_name}}` |
| Descrizioni | In italiano | In inglese |
| Formato date | DD/MM/YYYY | MM/DD/YYYY (in stringhe) |

---

## Schema del Database

### Posizione

```
installer/database/schema.sql
```

### Scopo

Questo file contiene lo schema **COMPLETO** del database per le fresh install. DEVE essere sempre sincronizzato con le migrazioni.

### Quando Aggiornarlo

**SEMPRE** quando:
- Aggiungi una nuova tabella (nella migrazione)
- Aggiungi una nuova colonna (nella migrazione)
- Aggiungi un nuovo indice (nella migrazione)
- Modifichi un tipo di dato

### Come Aggiornarlo

1. Applica la migrazione al tuo database locale
2. Esporta lo schema aggiornato:

```bash
# Esporta solo lo schema (no dati)
mysqldump --no-data --skip-triggers database_name > installer/database/schema.sql

# Oppure esporta tabella specifica
mysqldump --no-data --skip-triggers database_name nuova_tabella >> installer/database/schema.sql
```

3. **OPPURE** modifica manualmente `schema.sql` aggiungendo la nuova tabella nella posizione corretta

### Ordine delle Tabelle

Le tabelle sono in ordine di creazione/dipendenza:
1. Tabelle senza foreign keys
2. Tabelle con foreign keys (dopo le tabelle da cui dipendono)

### Esempio di Nuova Tabella

```sql
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nuova_tabella` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `utente_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_nome` (`nome`),
  KEY `fk_utente` (`utente_id`),
  CONSTRAINT `fk_nuova_tabella_utente` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
```

---

## GitHub Actions (release.yml)

### Posizione

```
.github/workflows/release.yml
```

### Trigger

La GitHub Action si attiva quando viene pushato un tag nel formato `v*.*.*`:

```yaml
on:
  push:
    tags:
      - 'v*.*.*'
```

### Fasi della Pipeline

```yaml
jobs:
  build-release:
    runs-on: ubuntu-latest
    steps:
      # 1. Checkout codice
      - uses: actions/checkout@v4

      # 2. Estrai versione dal tag
      - run: echo "VERSION=${GITHUB_REF#refs/tags/v}" >> $GITHUB_OUTPUT

      # 3. Setup PHP 8.1
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
          extensions: mysqli, pdo, mbstring, json, openssl, curl
          tools: composer

      # 4. Setup Node.js 18
      - uses: actions/setup-node@v4
        with:
          node-version: '18'

      # 5. Install Composer dependencies (PRODUCTION)
      - run: composer install --no-dev --optimize-autoloader

      # 6. Install NPM e build frontend
      - run: npm ci && npm run build
        working-directory: frontend

      # 7. Verifica version.json
      - run: |
          VERSION_JSON=$(jq -r '.version' version.json)
          if [ "$VERSION_JSON" != "$VERSION" ]; then
            echo "❌ Version mismatch"
            exit 1
          fi

      # 8. Crea pacchetto release
      - run: ./bin/build-release.sh

      # 9. Crea GitHub Release
      - uses: softprops/action-gh-release@v2
        with:
          files: |
            releases/pinakes-v*.zip
            releases/pinakes-v*.zip.sha256
            releases/RELEASE_NOTES-v*.md
```

### File Generati

| File | Descrizione |
|------|-------------|
| `pinakes-vX.Y.Z.zip` | Pacchetto completo pronto per l'installazione |
| `pinakes-vX.Y.Z.zip.sha256` | Checksum per verifica integrità |
| `RELEASE_NOTES-vX.Y.Z.md` | Note di release |

---

## Script di Build (build-release.sh)

### Posizione

```
bin/build-release.sh
```

### Utilizzo

```bash
# Build standard
./bin/build-release.sh

# Skip build frontend (usa asset esistenti)
./bin/build-release.sh --skip-build

# Directory output personalizzata
./bin/build-release.sh --output /path/to/output
```

### Cosa Fa

1. **Verifica requisiti**: jq, rsync, zip, shasum
2. **Legge versione**: da `version.json`
3. **Build frontend**: `npm install && npm run build`
4. **Copia file**: usando rsync con esclusioni da `.distignore`
5. **Verifica integrità**: controlla file critici
6. **Crea ZIP**: pacchetto compresso
7. **Genera checksum**: SHA256
8. **Crea release notes**: file markdown

### Requisiti

- **jq**: parsing JSON
- **rsync**: copia file
- **zip**: creazione archivio
- **shasum/sha256sum**: checksum

Installazione:

```bash
# macOS
brew install jq rsync

# Ubuntu/Debian
sudo apt-get install jq rsync zip
```

---

## File di Filtro per la Build

### File Supportati

| File | Formato | Priorità |
|------|---------|----------|
| `.rsync-filter` | rsync filter rules | **Primario** (raccomandato) |
| `.distignore` | gitignore-like | Legacy fallback |

### IMPORTANTE: Usare .rsync-filter (NON .distignore)

**⚠️ ATTENZIONE**: Il file `.distignore` usa sintassi gitignore con negazioni (`!path`) che **NON FUNZIONANO** con `rsync --exclude-from`. Questo ha causato problemi nella v0.4.0 dove i plugin bundled venivano esclusi.

### Formato .rsync-filter

Il file `.rsync-filter` usa la sintassi nativa di rsync:

```bash
# REGOLA CRITICA: Gli INCLUDE devono venire PRIMA degli EXCLUDE
# Il primo match vince

# Include espliciti (+ = include)
+ .env.example
+ README.md
+ storage/plugins/open-library/
+ storage/plugins/open-library/**

# Exclude (- = exclude)
- .git/
- .env
- node_modules/
- frontend/
- *.log
```

### Differenze tra .distignore e .rsync-filter

| Aspetto | .distignore | .rsync-filter |
|---------|-------------|---------------|
| Negazioni `!path` | ❌ NON funziona con rsync | N/A - usa `+ path` |
| Include | Non supportato | `+ pattern` |
| Exclude | Pattern diretto | `- pattern` |
| Ordine | Non importante | **CRITICO** - primo match vince |
| Plugin bundled | Fallisce | ✅ Funziona correttamente |

### Esempio: Includere Plugin Bundled

```bash
# .rsync-filter - CORRETTO
# Include PRIMA di exclude
+ storage/plugins/
+ storage/plugins/.gitkeep
+ storage/plugins/open-library/
+ storage/plugins/open-library/**
+ storage/plugins/z39-server/
+ storage/plugins/z39-server/**
# ... altri plugin

# Poi exclude il resto dei plugin (quelli non bundled)
- storage/plugins/*

# .distignore - NON FUNZIONA!
storage/plugins/*
!storage/plugins/open-library/  # ❌ Ignorato da rsync!
```

### IMPORTANTE: Cosa è INCLUSO nel Pacchetto

Il pacchetto di release **INCLUDE** tutto il necessario per funzionare E personalizzare:

| Componente | Incluso | Motivo |
|------------|---------|--------|
| `vendor/` | ✅ SÌ | Dipendenze PHP production-ready |
| `frontend/` | ✅ SÌ | Sorgenti JS/CSS per personalizzazione |
| `frontend/node_modules/` | ✅ SÌ | Dipendenze NPM per rebuild frontend |
| `public/assets/` | ✅ SÌ | Asset compilati pronti all'uso |

**Perché includere frontend e node_modules?**

1. Gli utenti possono **personalizzare** CSS/JS senza dover configurare npm da zero
2. Basta modificare i sorgenti in `frontend/` e eseguire `npm run build`
3. Senza node_modules, gli utenti dovrebbero eseguire `npm install` che può fallire su hosting condivisi
4. Il pacchetto è **autosufficiente** - funziona subito, personalizzabile se necessario

**Dimensione tipica del pacchetto**: ~100-150 MB (la maggior parte è node_modules)

### Plugin Bundled

I seguenti plugin sono inclusi nella distribuzione standard:

| Plugin | Percorso | Descrizione |
|--------|----------|-------------|
| open-library | `storage/plugins/open-library/` | Scraping ISBN da Open Library |
| z39-server | `storage/plugins/z39-server/` | Server SRU 1.2 |
| api-book-scraper | `storage/plugins/api-book-scraper/` | Scraping ISBN generico |
| digital-library | `storage/plugins/digital-library/` | Gestione ebook/audiobook |
| dewey-editor | `storage/plugins/dewey-editor/` | Editor classificazione Dewey |

**CRITICO**: Se un plugin bundled manca dal pacchetto, l'Updater lo eliminerà durante l'aggiornamento!

---

## Come Funziona l'Updater

### Classe Principale

```php
// app/Support/Updater.php
class Updater
{
    // Percorsi da preservare durante l'aggiornamento
    private array $preservePaths = [
        '.env',
        'storage/uploads',
        'storage/plugins',
        'storage/backups',
        'storage/cache',
        'storage/logs',
        'public/uploads',
        'public/.htaccess',
        'public/robots.txt',
        'public/favicon.ico',
        'public/sitemap.xml',
        'CLAUDE.md',
    ];

    // Percorsi da saltare completamente
    private array $skipPaths = [
        '.git',
        'node_modules',
    ];
}
```

### Metodi Principali

| Metodo | Descrizione |
|--------|-------------|
| `getCurrentVersion()` | Legge versione da `version.json` |
| `checkForUpdates()` | Controlla GitHub API per nuove release |
| `downloadUpdate($version)` | Scarica e estrae il pacchetto |
| `createBackup()` | Crea backup del database |
| `installUpdate($path, $version)` | Installa l'aggiornamento |
| `runMigrations($from, $to)` | Esegue le migrazioni SQL |
| `performUpdate($version)` | Processo completo di aggiornamento |

### API GitHub

L'Updater usa le GitHub API pubbliche:

```php
// Latest release
$url = "https://api.github.com/repos/{owner}/{repo}/releases/latest";

// Release by tag
$url = "https://api.github.com/repos/{owner}/{repo}/releases/tags/v{version}";

// All releases
$url = "https://api.github.com/repos/{owner}/{repo}/releases?per_page=10";
```

---

## Processo di Aggiornamento

### Flusso Completo

```
1. Utente accede a Admin > Aggiornamenti
                    ↓
2. checkForUpdates() → GitHub API
                    ↓
3. Se disponibile, utente clicca "Aggiorna"
                    ↓
4. enableMaintenanceMode()
                    ↓
5. createBackup() → storage/backups/update_YYYYMMDD_HHMMSS/
                    ↓
6. downloadUpdate() → /tmp/pinakes_update_xxxxx/
                    ↓
7. backupAppFiles() → /tmp/pinakes_app_backup_xxxxx/
                    ↓
8. copyDirectory() → Copia file preservando percorsi protetti
                    ↓
9. cleanupOrphanFiles() → Rimuove file non più presenti
                    ↓
10. runMigrations() → Esegue migrazioni SQL
                    ↓
11. fixPermissions() → Corregge permessi file
                    ↓
12. disableMaintenanceMode()
                    ↓
13. cleanup() → Pulizia file temporanei
                    ↓
14. opcache_reset() → Invalida cache PHP
```

### Maintenance Mode

Durante l'aggiornamento, viene creato:

```
storage/.maintenance
```

Contenuto:
```json
{
    "time": 1733234567,
    "message": "Aggiornamento in corso. Riprova tra qualche minuto."
}
```

### Tabella update_logs

```sql
CREATE TABLE `update_logs` (
    `id` int NOT NULL AUTO_INCREMENT,
    `from_version` varchar(20) NOT NULL,
    `to_version` varchar(20) NOT NULL,
    `status` enum('started','completed','failed') NOT NULL,
    `backup_path` varchar(500) DEFAULT NULL,
    `error_message` text,
    `executed_by` int DEFAULT NULL,
    `started_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `completed_at` datetime DEFAULT NULL,
    PRIMARY KEY (`id`)
);
```

---

## Rollback e Recovery

### Rollback Automatico

Se l'installazione fallisce, l'Updater tenta automaticamente il rollback:

```php
try {
    $this->installUpdate($sourcePath, $targetVersion);
} catch (Exception $e) {
    // Ripristina da backup
    if ($appBackupPath !== null) {
        $this->restoreAppFiles($appBackupPath);
    }
}
```

### Rollback Manuale

#### 1. Ripristina File Applicazione

```bash
# I backup sono in storage/backups/update_YYYYMMDD_HHMMSS/
cd /path/to/pinakes

# Esempio: ripristina da backup specifico
# (Nota: il backup contiene solo il database, non i file app)
```

#### 2. Ripristina Database

```bash
# Trova il backup del database
ls -la storage/backups/

# Ripristina
mysql -u user -p database_name < storage/backups/update_YYYYMMDD_HHMMSS/database.sql
```

#### 3. Reinstalla Versione Precedente

```bash
# Scarica versione precedente da GitHub
wget https://github.com/fabiodalez-dev/Pinakes/releases/download/vX.Y.Z/pinakes-vX.Y.Z.zip

# Estrai (preservando .env e storage/)
unzip pinakes-vX.Y.Z.zip
rsync -av --exclude='.env' --exclude='storage/' pinakes-vX.Y.Z/ ./

# Aggiorna version.json manualmente se necessario
```

---

## Checklist per Nuova Versione

### Prima del Release

- [ ] **version.json** aggiornato con nuova versione
- [ ] **Migrazione SQL** creata (se modifiche DB)
- [ ] **schema.sql** sincronizzato con migrazione
- [ ] **data_it_IT.sql** aggiornato (se nuovi seed)
- [ ] **data_en_US.sql** aggiornato (se nuovi seed)
- [ ] **locale/en_US.json** aggiornato (se nuove stringhe)
- [ ] **CHANGELOG.md** aggiornato (opzionale)
- [ ] Test locale completato
- [ ] Test migrazione completato (da versione precedente)
- [ ] Build locale verificata: `./bin/build-release.sh`

### Creazione Release

- [ ] Commit di tutte le modifiche
- [ ] Tag creato: `git tag vX.Y.Z`
- [ ] Push commit: `git push origin main`
- [ ] Push tag: `git push origin vX.Y.Z`
- [ ] Verifica GitHub Action completata
- [ ] Verifica release su GitHub Releases
- [ ] Test download e installazione pacchetto

### Post Release

- [ ] Aggiorna documentazione se necessario
- [ ] Annuncia la release (se major/minor)
- [ ] Monitora issue per problemi

---

## Troubleshooting

### Errori Comuni

#### "Version mismatch: version.json != tag"

**Causa**: La versione in `version.json` non corrisponde al tag Git.

**Soluzione**:
```bash
# Aggiorna version.json
# Commit
git commit -am "fix: update version.json to X.Y.Z"
# Elimina tag locale e remoto
git tag -d vX.Y.Z
git push origin :refs/tags/vX.Y.Z
# Ricrea tag
git tag vX.Y.Z
git push origin vX.Y.Z
```

#### "Migration failed: Duplicate column"

**Causa**: Normale, la migrazione è già stata eseguita.

**Soluzione**: Nessuna azione necessaria. L'Updater ignora questo errore.

#### "Download fallito"

**Causa**: Problema di rete o GitHub non raggiungibile.

**Soluzione**: Riprova più tardi o scarica manualmente da GitHub Releases.

#### "Impossibile creare directory di backup"

**Causa**: Permessi insufficienti su `storage/backups/`.

**Soluzione**:
```bash
chmod 755 storage/backups/
chown www-data:www-data storage/backups/
```

#### Pacchetto ZIP non valido

**Causa**: Download incompleto o corrotto.

**Soluzione**:
```bash
# Verifica checksum
shasum -a 256 -c pinakes-vX.Y.Z.zip.sha256
# Se fallisce, scarica di nuovo
```

#### "json.parse unexpected character at line 1 column 1"

**Causa**: Il server PHP è crashato o ha restituito HTML invece di JSON (errore PHP, timeout, memory limit).

**Soluzione**:
1. Se sei ancora loggato come admin, vai su Aggiornamenti e clicca "Disattiva modalità manutenzione"
2. Oppure elimina manualmente il file: `rm storage/.maintenance`
3. Controlla i log PHP: `tail -100 storage/logs/app.log`
4. Se il database è corrotto, ripristina da backup

**Vedi sezione "Lezioni Apprese" punto 8 per dettagli completi.**

#### Sito bloccato in "Manutenzione in corso"

**Causa**: L'aggiornamento è fallito durante l'esecuzione e il file `.maintenance` non è stato rimosso.

**Soluzione**:
```bash
# Il file viene rimosso automaticamente dopo 30 minuti (safety net)
# Per sbloccare subito:
rm storage/.maintenance

# Oppure da admin (se riesci ad accedere):
# /admin/updates poi clicca "Disattiva modalità manutenzione"
```

### Errori GitHub Actions

#### "Some specified paths were not resolved, unable to cache dependencies"

**Causa**: `frontend/package-lock.json` non è tracciato in git.

**Soluzione**:
```bash
# Rimuovi package-lock.json da .gitignore
# Aggiungi il file a git
git add frontend/package-lock.json
git commit -m "fix: track package-lock.json for reproducible builds"

# Elimina e ricrea il tag
git tag -d vX.Y.Z
git push origin :refs/tags/vX.Y.Z
git push origin main
git tag vX.Y.Z
git push origin vX.Y.Z
```

**Prevenzione**: `package-lock.json` DEVE essere sempre tracciato in git per garantire build riproducibili con `npm ci`.

#### "Module not found: Can't resolve '@package/name'"

**Causa**: Una dipendenza usata in `vendor.js` o altri file non è presente in `package.json`.

**Soluzione**:
```bash
# 1. Identifica il pacchetto mancante dal messaggio di errore
# 2. Installalo e salvalo in package.json
cd frontend && npm install @package/name --save

# 3. Verifica la build locale PRIMA di pushare
rm -rf node_modules && npm ci && npm run build

# 4. Commit e ricrea il tag
git add frontend/package.json frontend/package-lock.json
git commit -m "fix: add missing dependency @package/name"
# ... elimina e ricrea tag
```

**Prevenzione**: Prima di ogni release, esegui una build pulita localmente:
```bash
cd frontend && rm -rf node_modules && npm ci && npm run build
```

#### "Resource not accessible by integration" (403)

**Causa**: Il `GITHUB_TOKEN` non ha i permessi per creare release.

**Soluzione 1 - Configura permessi repository**:
1. Vai su Repository → Settings → Actions → General
2. Sotto "Workflow permissions" seleziona **"Read and write permissions"**
3. Spunta "Allow GitHub Actions to create and approve pull requests"
4. Salva e ri-esegui il workflow

**Soluzione 2 - Crea release manualmente**:
```bash
# Build locale del pacchetto
./bin/build-release.sh

# Crea la release con gh CLI
gh release create vX.Y.Z \
  releases/pinakes-vX.Y.Z.zip \
  releases/pinakes-vX.Y.Z.zip.sha256 \
  releases/RELEASE_NOTES-vX.Y.Z.md \
  --title "Pinakes vX.Y.Z" \
  --notes "Release notes here..."
```

### Checklist Pre-Release (evita errori CI)

Prima di creare un tag, verifica **SEMPRE**:

```bash
# 1. package-lock.json tracciato
git ls-files frontend/package-lock.json
# Deve mostrare il file, non vuoto

# 2. Build frontend pulita
cd frontend && rm -rf node_modules && npm ci && npm run build
# Deve completare senza errori

# 3. Composer dependencies
composer install --no-dev --optimize-autoloader
# Deve completare senza errori

# 4. Build release completa
./bin/build-release.sh
# Deve generare i file in releases/

# 5. Verifica version.json
cat version.json
# Deve corrispondere al tag che stai creando
```

### Debug

#### Abilita logging dettagliato

```php
// In Updater.php, tutti gli errori sono loggati con error_log()
// Controlla storage/logs/app.log o il log PHP del server
```

#### Verifica manuale migrazioni

```sql
-- Controlla migrazioni eseguite
SELECT * FROM migrations ORDER BY executed_at DESC;

-- Controlla log aggiornamenti
SELECT * FROM update_logs ORDER BY started_at DESC;
```

---

## Flow Consigliato per Creare Release

### Perché Build Locale invece di GitHub Actions?

GitHub Actions può fallire per diversi motivi (permessi, dipendenze mancanti, timeout). **Si consiglia di fare SEMPRE la build in locale** e caricare manualmente la release:

1. **Più controllo**: Puoi verificare il contenuto del pacchetto prima di pubblicare
2. **Nessun problema di permessi**: Non serve configurare GITHUB_TOKEN
3. **Debug immediato**: Se qualcosa fallisce, vedi subito l'errore
4. **Più veloce**: Non devi aspettare la CI/CD

### Flow Completo Raccomandato

```bash
# 1. PREPARAZIONE
# Assicurati di essere su main aggiornato
git checkout main && git pull origin main

# 2. AGGIORNA VERSION.JSON
# Modifica version.json con la nuova versione
code version.json  # o il tuo editor preferito

# 3. CREA/AGGIORNA MIGRAZIONE (se necessario)
# Se ci sono modifiche al database
touch installer/database/migrations/migrate_X.Y.Z.sql

# 4. SINCRONIZZA SCHEMA.SQL
# Le modifiche della migrazione DEVONO essere anche in schema.sql

# 5. BUILD FRONTEND PULITA
cd frontend
rm -rf node_modules
npm ci
npm run build
cd ..

# 6. VERIFICA COMPOSER
composer install --no-dev --optimize-autoloader

# 7. BUILD RELEASE LOCALE
./bin/build-release.sh

# 8. VERIFICA CONTENUTO PACCHETTO
unzip -l releases/pinakes-vX.Y.Z.zip | head -50
# Controlla che NON ci siano: .git/, .gemini/, .qoder/, .env, file .zip sparsi

# 9. COMMIT E TAG
git add .
git commit -m "chore: prepare release vX.Y.Z"
git tag vX.Y.Z
git push origin main
git push origin vX.Y.Z

# 10. CREA RELEASE SU GITHUB
gh release create vX.Y.Z \
  releases/pinakes-vX.Y.Z.zip \
  releases/pinakes-vX.Y.Z.zip.sha256 \
  releases/RELEASE_NOTES-vX.Y.Z.md \
  --title "Pinakes vX.Y.Z" \
  --notes "## What's New in vX.Y.Z

[Release notes qui...]"

# 11. VERIFICA
# Apri https://github.com/fabiodalez-dev/Pinakes/releases
# Verifica che i file siano stati caricati correttamente
```

### Se Devi Ricreare una Release

```bash
# 1. Elimina release e tag
gh release delete vX.Y.Z --yes
git tag -d vX.Y.Z
git push origin :refs/tags/vX.Y.Z

# 2. Fai le correzioni necessarie
# ...

# 3. (Opzionale) Squasha i commit di fix
git reset --soft HEAD~N  # N = numero di commit da squashare
git commit -m "fix: release X.Y.Z corrections"
git push --force origin main

# 4. Ricostruisci il pacchetto
rm -rf releases/pinakes-vX.Y.Z*
./bin/build-release.sh

# 5. Ricrea tag e release
git tag vX.Y.Z
git push origin vX.Y.Z
gh release create vX.Y.Z ...
```

---

## Lezioni Apprese (v0.4.0)

### Problemi Incontrati e Soluzioni

#### 1. ⚠️ CRITICO: Plugin Bundled Esclusi dal Pacchetto

**Problema**: I plugin bundled (open-library, z39-server, ecc.) venivano esclusi dal pacchetto, causando la loro eliminazione durante l'aggiornamento.

**Causa**: `.distignore` usava sintassi `!path` per negare le esclusioni, ma rsync `--exclude-from` **NON supporta** questa sintassi.

```gitignore
# .distignore - NON FUNZIONA!
storage/plugins/*
!storage/plugins/open-library/  # ❌ Ignorato completamente da rsync!
```

**Soluzione**: Creato `.rsync-filter` con sintassi nativa rsync:
```bash
# .rsync-filter - CORRETTO
# Gli include DEVONO venire PRIMA degli exclude
+ storage/plugins/open-library/
+ storage/plugins/open-library/**
+ storage/plugins/z39-server/
+ storage/plugins/z39-server/**
# ... altri plugin
- storage/plugins/*  # Esclude il resto
```

**Prevenzione**: Lo script `build-release.sh` ora verifica automaticamente che i plugin bundled siano presenti nel pacchetto.

#### 2. package-lock.json Non Tracciato

**Problema**: GitHub Actions falliva con `npm ci` perché `package-lock.json` non era in git.

**Causa**: Il file era in `.gitignore`.

**Soluzione**: Rimuovere `package-lock.json` da `.gitignore` e tracciarlo:
```bash
git add frontend/package-lock.json
git commit -m "fix: track package-lock.json for reproducible CI builds"
```

**Nota**: `npm ci` richiede `package-lock.json` per build riproducibili. `npm install` lo genera ma non garantisce versioni identiche.

#### 3. Dipendenza NPM Mancante

**Problema**: La build frontend falliva con "Module not found: Can't resolve '@fortawesome/fontawesome-free'".

**Causa**: Il pacchetto era usato in `vendor.js` ma non era in `package.json`.

**Soluzione**: Aggiungere la dipendenza:
```bash
cd frontend && npm install @fortawesome/fontawesome-free --save
```

**Prevenzione**: Prima di ogni release, eseguire una build pulita:
```bash
cd frontend && rm -rf node_modules && npm ci && npm run build
```

#### 4. File Indesiderati nel Pacchetto

**Problema**: Il pacchetto conteneva `.gemini/`, `.qoder/`, `.claude/`, file `.zip` sparsi.

**Causa**: Cartelle IDE/AI e file temporanei non erano esclusi.

**Soluzione**: Aggiunti a `.rsync-filter`:
```bash
- .gemini/
- .qoder/
- .claude/
- .vscode/
- .idea/
- .cursor/
- *.zip
```

**Prevenzione**: Lo script `build-release.sh` ora verifica che questi file NON siano nel pacchetto.

#### 5. Migrazione con AFTER Clause

**Problema**: `ALTER TABLE ... ADD COLUMN ... AFTER column_name` fallisce se la colonna di riferimento non esiste.

**Causa**: Database di produzione potrebbe avere schema diverso.

**Soluzione**: NON usare `AFTER` nelle migrazioni:
```sql
-- SBAGLIATO
ALTER TABLE `utenti` ADD COLUMN `privacy_accettata` TINYINT(1) AFTER `email_verificata`;

-- CORRETTO
ALTER TABLE `utenti` ADD COLUMN `privacy_accettata` TINYINT(1) NOT NULL DEFAULT 0;
```

L'ordine delle colonne è solo estetico e non influenza la funzionalità.

#### 6. Doppi Spazi dopo Strip Caratteri MARC-8

**Problema**: Dopo aver rimosso i caratteri di controllo MARC-8 (NSB/NSE) dai titoli SRU, rimanevano doppi spazi.

**Causa**: Il carattere di controllo era preceduto da uno spazio.

**Soluzione**: Normalizza sempre gli spazi dopo lo strip:
```php
// Strip MARC-8 control characters
$title = preg_replace('/[\x88\x89\x98\x9C]/', '', $title);
// Normalize whitespace
$title = trim(preg_replace('/\s+/', ' ', $title));
```

#### 7. GitHub Actions Permessi (403)

**Problema**: `softprops/action-gh-release` fallisce con "Resource not accessible by integration".

**Causa**: GITHUB_TOKEN non ha permessi di scrittura.

**Soluzioni**:
1. Repository Settings → Actions → General → "Read and write permissions"
2. Oppure (consigliato): **Fai la build in locale** e usa `gh release create`

#### 8. Errore "json.parse unexpected character" Durante Update

**Problema**: L'utente riceve errore JavaScript `json.parse unexpected character at line 1 column 1` durante l'aggiornamento, il sito resta in manutenzione, e l'auto-revert non scatta.

**Causa**: Il server PHP ha restituito una risposta HTML invece di JSON. Questo accade quando:
- PHP va in fatal error (memory limit, timeout, errore di sintassi)
- Il web server interrompe la richiesta (proxy timeout)
- Un'eccezione non gestita genera una pagina di errore HTML

**Perché il maintenance mode resta attivo**: Se PHP crasha, il `finally` block in `performUpdate()` non viene eseguito, quindi `cleanup()` (che disabilita maintenance) non viene chiamato.

**Perché l'auto-revert non scatta**: Il rollback in `installUpdate()` è nel blocco `catch`. Se PHP termina inaspettatamente (non un'eccezione PHP), il catch non viene eseguito.

**Soluzioni implementate (v0.5.0+)**:
1. Il JavaScript ora verifica `Content-Type: application/json` prima di parsare
2. Mostra un messaggio chiaro se il server restituisce HTML
3. Aggiunto pulsante "Disattiva modalità manutenzione" nell'errore
4. Aggiunto endpoint `/admin/updates/maintenance/clear` per recovery manuale

**Recovery manuale** (se il problema persiste):
```bash
# 1. Rimuovi il file di manutenzione
rm storage/.maintenance

# 2. Controlla i log PHP per capire cosa è andato storto
tail -100 storage/logs/app.log

# 3. Se il sito non funziona, ripristina da backup:
mysql -u user -p database < storage/backups/update_YYYYMMDD/database.sql
```

**Prevenzione**:
- Aumenta `memory_limit` in php.ini (consigliato: 256M+)
- Aumenta `max_execution_time` (consigliato: 300+)
- Se usi un proxy/CDN, aumenta il timeout delle richieste

---

## Lezioni Apprese (v0.4.1)

### Problemi Incontrati e Soluzioni

#### 0. ⛔️ CRITICO: .gitignore e .rsync-filter sono SEPARATI!

**Problema**: File come `CHANGELOG.md` vengono inclusi nel pacchetto release nonostante siano in `.gitignore`.

**Causa**: `.rsync-filter` NON legge `.gitignore`! Sono due sistemi completamente separati:
- `.gitignore` → usato da Git per il version control
- `.rsync-filter` → usato da rsync per creare il pacchetto di distribuzione

**Soluzione**: Ogni file che deve essere escluso dalla distribuzione deve essere aggiunto **ESPLICITAMENTE** a `.rsync-filter`:

```bash
# .rsync-filter
- CHANGELOG.md
- todo.md
- updater.md
- *.md  # Esclude tutti gli altri .md (dopo gli include espliciti)
```

**Prevenzione**: Prima di ogni release, verificare SEMPRE:
```bash
./bin/build-release.sh
unzip -l releases/pinakes-vX.Y.Z.zip | grep -E "CHANGELOG|todo\.md|updater\.md"
# NON deve trovare nulla!
```

#### 1. ⚠️ CRITICO: .DS_Store nei Plugin Bundled

**Problema**: I file `.DS_Store` (file nascosti macOS) venivano inclusi nel pacchetto dentro le cartelle dei plugin.

**Causa**: Il pattern `+ storage/plugins/PLUGIN/**` includeva TUTTO, compresi i file `.DS_Store`.

**Soluzione**: Aggiungere l'esclusione `.DS_Store` PRIMA dei pattern wildcard:
```bash
# .rsync-filter - ORDINE CRITICO!
+ storage/plugins/
+ storage/plugins/.gitkeep
- storage/plugins/**/.DS_Store    # ← DEVE venire PRIMA dei pattern **
+ storage/plugins/open-library/
+ storage/plugins/open-library/**
# ... altri plugin
```

**Regola rsync**: In rsync i filtri vengono valutati in ordine. Il primo match vince. Se il pattern `**` viene prima dell'esclusione `.DS_Store`, i file verranno inclusi.

#### 2. 🚫 scraping-pro NON È UN PLUGIN BUNDLED

**IMPORTANTE**: Il plugin `scraping-pro` è un plugin **PREMIUM venduto separatamente**.

**NON DEVE MAI essere incluso in `.rsync-filter`!**

```bash
# .rsync-filter - Plugin bundled (GRATUITI)
+ storage/plugins/open-library/
+ storage/plugins/open-library/**
+ storage/plugins/z39-server/
+ storage/plugins/z39-server/**
+ storage/plugins/api-book-scraper/
+ storage/plugins/api-book-scraper/**
+ storage/plugins/digital-library/
+ storage/plugins/digital-library/**
+ storage/plugins/dewey-editor/
+ storage/plugins/dewey-editor/**
# NOTE: scraping-pro is NOT bundled (premium plugin, sold separately)
```

**Lista plugin bundled (gratuiti)**:
1. `open-library` - Ricerca libri su Open Library
2. `z39-server` - Ricerca SBN via SRU/Z39.50
3. `api-book-scraper` - Ricerca libri via API
4. `digital-library` - Supporto ebook/audiobook
5. `dewey-editor` - Editor classificazione Dewey

**Plugin premium (NON bundled)**:
- `scraping-pro` - Web scraping avanzato (venduto separatamente)

---

## Best Practices per Migrazioni

### DO (Fare)

```sql
-- Usa CREATE TABLE IF NOT EXISTS
CREATE TABLE IF NOT EXISTS `nuova_tabella` (...);

-- Usa valori DEFAULT per nuove colonne NOT NULL
ALTER TABLE `utenti` ADD COLUMN `flag` TINYINT(1) NOT NULL DEFAULT 0;

-- Backfill con WHERE per evitare UPDATE inutili
UPDATE `utenti` SET `flag` = 1 WHERE `stato` = 'attivo' AND `flag` = 0;

-- Commenti solo con -- a inizio riga
-- Questo è un commento corretto
```

### DON'T (Non Fare)

```sql
-- NON usare AFTER (compatibilità)
ALTER TABLE `utenti` ADD COLUMN `x` INT AFTER `y`;  -- NO!

-- NON usare IF NOT EXISTS su ALTER (non supportato MySQL)
ALTER TABLE `utenti` ADD COLUMN IF NOT EXISTS `x` INT;  -- NO! (solo MariaDB)

-- NON usare stored procedures/triggers con ; nel corpo
-- NON usare commenti /* */ multi-linea

-- NON assumere l'esistenza di colonne specifiche
-- Il database di produzione potrebbe essere diverso
```

### Errori Ignorati dall'Updater

L'Updater ignora automaticamente questi errori MySQL (idempotenza):
- **1060**: Duplicate column name (colonna già esiste)
- **1061**: Duplicate key name (indice già esiste)
- **1050**: Table already exists

Questo permette di ri-eseguire le migrazioni senza errori.

---

## Checklist Completa Pre-Release

### 1. Codice

- [ ] Tutte le modifiche committate
- [ ] Branch main aggiornato: `git pull origin main`
- [ ] Nessun file di debug/test: `git status` pulito

### 2. Versioning

- [ ] `version.json` aggiornato
- [ ] Versione corrisponde al tag che creerai

### 3. Database (se modifiche)

- [ ] File migrazione creato: `migrate_X.Y.Z.sql`
- [ ] Migrazione idempotente (CREATE IF NOT EXISTS, **NO AFTER**)
- [ ] `schema.sql` sincronizzato con migrazione
- [ ] Seed data aggiornati se necessario

### 4. Frontend

- [ ] `package-lock.json` tracciato in git:
  ```bash
  git ls-files frontend/package-lock.json  # Deve mostrare il file
  ```
- [ ] Build pulita funziona:
  ```bash
  cd frontend && rm -rf node_modules && npm ci && npm run build
  ```
- [ ] Nessun errore "Module not found" (tutte le dipendenze in package.json)

### 5. Backend

- [ ] Composer funziona:
  ```bash
  composer install --no-dev --optimize-autoloader
  ```
- [ ] PHP syntax check: `php -l app/...`

### 6. File di Filtro

- [ ] `.rsync-filter` presente e aggiornato
- [ ] Plugin bundled inclusi con `+ storage/plugins/NOME/**`
- [ ] File IDE/AI esclusi: `.gemini/`, `.qoder/`, `.claude/`, ecc.

### 7. Pacchetto

- [ ] Build release locale:
  ```bash
  ./bin/build-release.sh
  ```
- [ ] Lo script verifica automaticamente:
  - ✅ Nessun file proibito (.git, .env, .gemini, ecc.)
  - ✅ File richiesti presenti (vendor/autoload.php, ecc.)
  - ✅ Plugin bundled presenti

- [ ] Verifica manuale contenuto (opzionale):
  ```bash
  # Verifica che NON ci siano file proibiti
  unzip -l releases/pinakes-vX.Y.Z.zip | grep -E "\.git/|\.env$|\.gemini|\.qoder|\.claude|node_modules"
  # ↑ NON deve trovare nulla!

  # Verifica che CI SIANO i plugin bundled
  unzip -l releases/pinakes-vX.Y.Z.zip | grep "storage/plugins/open-library"
  # ↑ DEVE trovare i file del plugin
  ```
- [ ] Dimensione ragionevole (~300MB con vendor)

### 8. Test (idealmente)

- [ ] Fresh install su ambiente pulito
- [ ] Upgrade da versione precedente
- [ ] Migrazione eseguita correttamente
- [ ] Plugin bundled funzionanti dopo upgrade

### 9. Pubblicazione

- [ ] Tag creato e pushato
- [ ] Release creata su GitHub (o con `gh release create`)
- [ ] File allegati correttamente
- [ ] Release notes complete

---

## Note Importanti

### MAI Eliminare File di Migrazione

I file di migrazione **NON DEVONO MAI** essere eliminati, anche per versioni molto vecchie. Servono per utenti che saltano più versioni.

### Vendor nel Pacchetto

Il pacchetto di release **INCLUDE** la cartella `vendor/` production-ready. Gli utenti non devono eseguire `composer install`.

### Test Prima del Release

**SEMPRE** testare:
1. Fresh install con il pacchetto
2. Aggiornamento dalla versione precedente
3. Aggiornamento saltando una versione (es. 0.3.0 → 0.5.0)

---

*Documento aggiornato il 2025-12-10. Versione corrente: 0.4.1*

*Ultima revisione: Aggiunta regola fondamentale su .gitignore vs .rsync-filter, lezione appresa 0 (v0.4.1).*
