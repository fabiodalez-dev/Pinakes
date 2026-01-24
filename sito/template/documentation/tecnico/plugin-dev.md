# Sviluppo Plugin

Guida per sviluppatori alla creazione di plugin per Pinakes.

## Struttura Base

```
storage/plugins/mio-plugin/
├── MioPluginPlugin.php    # Classe principale (obbligatoria)
├── plugin.json            # Metadati (obbligatorio)
├── classes/               # Classi aggiuntive
│   └── MyService.php
├── views/                 # Template PHP
│   └── settings.php
├── assets/                # Risorse statiche
│   ├── style.css
│   └── script.js
└── migrations/            # SQL per installazione
    └── install.sql
```

## plugin.json

```json
{
  "name": "mio-plugin",
  "display_name": "Il Mio Plugin",
  "description": "Descrizione della funzionalità",
  "version": "1.0.0",
  "author": "Nome Autore",
  "author_url": "https://esempio.it",
  "plugin_url": "https://github.com/autore/plugin",
  "main_file": "MioPluginPlugin.php",
  "requires_php": "8.0",
  "requires_app": "0.4.0",
  "max_app_version": "1.0.0",
  "settings": true,
  "metadata": {
    "category": "utility",
    "tags": ["utility", "enhancement"],
    "priority": 10,
    "features": [
      "Feature 1",
      "Feature 2"
    ]
  }
}
```

### Campi Obbligatori

| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `name` | string | Identificativo univoco (lowercase, hyphens) |
| `display_name` | string | Nome visualizzato |
| `version` | string | Versione semver (X.Y.Z) |
| `main_file` | string | File classe principale |
| `requires_php` | string | Versione PHP minima |
| `requires_app` | string | Versione Pinakes minima |

### Campi Opzionali

| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `description` | string | Descrizione estesa |
| `author` | string | Nome autore |
| `settings` | boolean | Ha pagina impostazioni |
| `max_app_version` | string | Versione Pinakes massima |

## Classe Principale

```php
<?php
namespace Plugins\MioPlugin;

use App\Support\PluginBase;
use App\Support\HookManager;

class MioPluginPlugin extends PluginBase
{
    /**
     * Eseguito all'attivazione del plugin
     */
    public function activate(): void
    {
        // Crea tabelle, inizializza dati
        $this->runMigration('install.sql');
    }

    /**
     * Eseguito alla disattivazione
     */
    public function deactivate(): void
    {
        // Pulizia risorse (opzionale)
    }

    /**
     * Eseguito a ogni caricamento
     */
    public function boot(): void
    {
        // Registra hook
        $this->registerHooks();
    }

    /**
     * Renderizza pagina impostazioni
     */
    public function renderSettings(): string
    {
        return $this->render('settings.php', [
            'settings' => $this->getSettings()
        ]);
    }

    /**
     * Salva impostazioni
     */
    public function saveSettings(array $data): bool
    {
        return $this->updateSettings($data);
    }

    private function registerHooks(): void
    {
        HookManager::addAction('book.save.after', function($book) {
            // Logica dopo salvataggio libro
        }, 10);
    }
}
```

## Hook System

### Registrazione

```php
use App\Support\HookManager;

// Azione (esegue codice)
HookManager::addAction('hook.name', callable $callback, int $priority = 10);

// Filtro (modifica dati)
HookManager::addFilter('filter.name', callable $callback, int $priority = 10);
```

### Hook Disponibili

#### Applicazione

| Hook | Tipo | Argomenti |
|------|------|-----------|
| `app.init` | action | - |
| `app.routes.register` | action | `$app` (Slim) |
| `app.shutdown` | action | - |

#### Assets

| Hook | Tipo | Descrizione |
|------|------|-------------|
| `assets.head` | filter | Aggiungi CSS/meta in `<head>` |
| `assets.footer` | filter | Aggiungi JS prima di `</body>` |

#### Libri

| Hook | Tipo | Argomenti |
|------|------|-----------|
| `book.save.before` | action | `$data` (array) |
| `book.save.after` | action | `$book` (object) |
| `book.delete.before` | action | `$bookId` |
| `book.scrape.isbn` | filter | `$isbn` |

#### Prestiti

| Hook | Tipo | Argomenti |
|------|------|-----------|
| `loan.create.before` | action | `$data` |
| `loan.approve.after` | action | `$loan` |
| `loan.return.after` | action | `$loan` |

### Esempio Hook

```php
// Aggiungi CSS personalizzato
HookManager::addFilter('assets.head', function($html) {
    $css = '<link rel="stylesheet" href="/plugins/mio-plugin/style.css">';
    return $html . $css;
});

// Modifica dati libro prima del salvataggio
HookManager::addFilter('book.save.before', function($data) {
    $data['custom_field'] = 'valore';
    return $data;
});
```

## Route Personalizzate

```php
public function boot(): void
{
    HookManager::addAction('app.routes.register', function($app) {
        $app->get('/mio-plugin/pagina', [$this, 'handlePage']);
        $app->post('/mio-plugin/api', [$this, 'handleApi']);
    });
}

public function handlePage($request, $response)
{
    $html = $this->render('pagina.php');
    $response->getBody()->write($html);
    return $response;
}
```

## Database

### Migrazioni

Crea file in `migrations/`:

```sql
-- migrations/install.sql
CREATE TABLE IF NOT EXISTS plugin_mio_dati (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    valore TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Esegui da codice:

```php
$this->runMigration('install.sql');
```

### Query

```php
// Accesso al database
$db = $this->getDatabase();

$stmt = $db->prepare("SELECT * FROM plugin_mio_dati WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
```

## Impostazioni

### Lettura/Scrittura

```php
// Leggi singola impostazione
$valore = $this->getSetting('chiave', 'default');

// Leggi tutte
$settings = $this->getSettings();

// Scrivi
$this->updateSettings(['chiave' => 'valore']);
```

### Pagina Impostazioni

```php
// views/settings.php
<form method="post">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

    <label>
        Opzione:
        <input type="text" name="opzione"
               value="<?= htmlspecialchars($settings['opzione'] ?? '') ?>">
    </label>

    <button type="submit"><?= __('Salva') ?></button>
</form>
```

## Best Practice

1. **Namespace**: Usa `Plugins\NomePlugin` per evitare conflitti
2. **Traduzioni**: Usa `__()` per tutte le stringhe UI
3. **Sicurezza**: Valida sempre l'input, escape l'output
4. **Cleanup**: Implementa `deactivate()` per pulizia
5. **Compatibilità**: Testa su versioni PHP/Pinakes dichiarate
6. **Documentazione**: Includi README.md nel plugin

## Test

```bash
# Verifica sintassi PHP
php -l storage/plugins/mio-plugin/MioPluginPlugin.php

# Log durante sviluppo
SecureLogger::debug('Mio plugin', ['data' => $value]);

# Visualizza log
tail -f storage/logs/app.log | grep "Mio plugin"
```

---

## Domande Frequenti (FAQ)

### 1. Qual è la struttura minima per creare un plugin funzionante?

Un plugin minimo richiede solo 2 file:

**plugin.json:**
```json
{
  "name": "mio-plugin",
  "display_name": "Il Mio Plugin",
  "version": "1.0.0",
  "main_file": "MioPluginPlugin.php",
  "requires_php": "8.0",
  "requires_app": "0.4.0"
}
```

**MioPluginPlugin.php:**
```php
<?php
namespace Plugins\MioPlugin;

use App\Support\PluginBase;

class MioPluginPlugin extends PluginBase
{
    public function activate(): void {}
    public function deactivate(): void {}
}
```

Salva in `storage/plugins/mio-plugin/` e il plugin apparirà nella lista.

---

### 2. Come registro una nuova route dal mio plugin?

Usa l'hook `app.routes.register`:

```php
public function boot(): void
{
    \App\Support\HookManager::addAction('app.routes.register', function($app) {
        // Route GET
        $app->get('/mio-plugin/pagina', [$this, 'mostraPagina']);

        // Route POST
        $app->post('/mio-plugin/salva', [$this, 'salvaForm']);

        // Route con parametro
        $app->get('/mio-plugin/libro/{id}', [$this, 'dettaglioLibro']);
    });
}

public function mostraPagina($request, $response)
{
    $html = $this->render('pagina.php');
    $response->getBody()->write($html);
    return $response;
}
```

Le route del plugin hanno accesso completo all'oggetto Slim `$app`.

---

### 3. Come accedo al database dal mio plugin?

Usa il metodo `getDatabase()` della classe base:

```php
public function cercaLibri(string $titolo): array
{
    $db = $this->getDatabase();

    $stmt = $db->prepare("SELECT * FROM libri WHERE titolo LIKE ?");
    $search = "%{$titolo}%";
    $stmt->bind_param('s', $search);
    $stmt->execute();

    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
```

**Importante:**
- Usa sempre prepared statements
- Gestisci le eccezioni
- Non modificare tabelle core senza necessità

---

### 4. Come creo tabelle database per il mio plugin?

Crea un file SQL in `migrations/`:

**migrations/install.sql:**
```sql
CREATE TABLE IF NOT EXISTS plugin_miodati (
    id INT AUTO_INCREMENT PRIMARY KEY,
    libro_id INT NOT NULL,
    campo_custom VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (libro_id) REFERENCES libri(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Esegui in activate():**
```php
public function activate(): void
{
    $this->runMigration('install.sql');
}
```

---

### 5. Come gestisco le impostazioni del plugin?

La classe `PluginBase` fornisce metodi per le impostazioni:

```php
// Leggi singola impostazione
$valore = $this->getSetting('api_key', 'default');

// Leggi tutte
$settings = $this->getSettings();

// Salva impostazioni
$this->updateSettings([
    'api_key' => 'abc123',
    'attivo' => true
]);
```

**Pagina impostazioni:**
1. In `plugin.json`, imposta `"settings": true`
2. Implementa `renderSettings()` e `saveSettings()`

Le impostazioni sono salvate in `plugin_settings`.

---

### 6. Quali hook sono disponibili per modificare il comportamento dei libri?

| Hook | Tipo | Quando |
|------|------|--------|
| `book.save.before` | filter | Prima del salvataggio (puoi modificare $data) |
| `book.save.after` | action | Dopo il salvataggio (ricevi $book) |
| `book.delete.before` | action | Prima della cancellazione |
| `book.scrape.isbn` | filter | Durante scraping ISBN |

**Esempio - Aggiungi campo custom:**
```php
HookManager::addFilter('book.save.before', function($data) {
    $data['campo_custom'] = $this->calcolaValore($data);
    return $data;
}, 10);
```

---

### 7. Come aggiungo CSS e JavaScript dal mio plugin?

Usa gli hook `assets.head` e `assets.footer`:

```php
public function boot(): void
{
    // CSS nel <head>
    HookManager::addFilter('assets.head', function($html) {
        $css = '<link rel="stylesheet" href="/plugins/mio-plugin/assets/style.css">';
        return $html . $css;
    });

    // JS prima di </body>
    HookManager::addFilter('assets.footer', function($html) {
        $js = '<script src="/plugins/mio-plugin/assets/script.js"></script>';
        return $html . $js;
    });
}
```

Salva i file in `storage/plugins/mio-plugin/assets/`.

---

### 8. Come rendo il mio plugin compatibile con più versioni di Pinakes?

Nel `plugin.json` specifica i limiti:

```json
{
  "requires_app": "0.4.0",
  "max_app_version": "1.0.0"
}
```

**Nel codice, verifica le feature:**
```php
public function boot(): void
{
    // Verifica se esiste una funzione/classe
    if (class_exists('\App\Support\NuovaFeature')) {
        // Usa la nuova feature
    } else {
        // Fallback per versioni vecchie
    }
}
```

**Best practice:**
- Testa su tutte le versioni dichiarate
- Documenta la compatibilità nel README

---

### 9. Come faccio debug del mio plugin?

**1. Logging:**
```php
use App\Support\SecureLogger;

SecureLogger::debug('MioPlugin: debug', ['data' => $value]);
SecureLogger::info('MioPlugin: info', ['stato' => 'ok']);
SecureLogger::error('MioPlugin: errore', ['errore' => $e->getMessage()]);
```

**2. Visualizza log:**
```bash
tail -f storage/logs/app.log | grep "MioPlugin"
```

**3. Verifica sintassi:**
```bash
php -l storage/plugins/mio-plugin/MioPluginPlugin.php
```

**4. Debug PHP:**
In `.env` imposta `APP_DEBUG=true` per vedere errori dettagliati.

---

### 10. Come distribuisco il mio plugin ad altri utenti?

**1. Prepara la struttura:**
```
mio-plugin/
├── MioPluginPlugin.php
├── plugin.json
├── README.md          # Documentazione
├── LICENSE            # Licenza
├── classes/
├── views/
├── assets/
└── migrations/
```

**2. Crea archivio ZIP:**
```bash
cd storage/plugins
zip -r mio-plugin-v1.0.0.zip mio-plugin/
```

**3. Distribuzione:**
- Pubblica su GitHub con release
- Carica su repository plugin Pinakes (se disponibile)
- Condividi direttamente il file ZIP

**4. Installazione utente:**
- **Admin → Plugin → Carica plugin** → seleziona ZIP
