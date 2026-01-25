# Sistema di Plugin per Pinakes

Benvenuto alla documentazione completa del sistema di plugin di Pinakes. Questo sistema permette di estendere le funzionalità dell'applicazione senza modificare il codice core.

## Indice

1. [Introduzione](#introduzione)
2. [Struttura di un Plugin](#struttura-di-un-plugin)
3. [File plugin.json](#file-pluginjson)
4. [Classe Principale del Plugin](#classe-principale-del-plugin)
5. [Sistema di Hook](#sistema-di-hook)
6. [Hook Disponibili](#hook-disponibili)
7. [API del Plugin](#api-del-plugin)
8. [Esempi Pratici](#esempi-pratici)
9. [Installazione e Gestione](#installazione-e-gestione)
10. [Sicurezza](#sicurezza)
11. [Aggiornamento Installazioni Esistenti](#aggiornamento-installazioni-esistenti)

---

## Introduzione

Il sistema di plugin di Pinakes permette di:

- Estendere i campi dei libri, autori ed editori
- Aggiungere funzionalità al login
- Estendere i filtri del catalogo
- Aggiungere fonti di scraping personalizzate
- Elaborare immagini
- Interagire con API esterne
- Molto altro...

### Caratteristiche Principali

**Sistema di Hook flessibile** - Filter e Action hooks per estendere ogni parte dell'applicazione
**Installazione tramite ZIP** - Upload semplice tramite interfaccia admin
**Gestione completa** - Attivazione, disattivazione, disinstallazione
**Storage dedicato** - Database e filesystem per dati plugin
**Logging integrato** - Sistema di log per debug e monitoraggio
**Sicurezza** - Validazione, CSRF protection, isolamento

---

## Struttura di un Plugin

Un plugin deve avere la seguente struttura di directory:

```
my-plugin/
├── plugin.json          # Metadati del plugin (obbligatorio)
├── MyPlugin.php         # Classe principale (obbligatorio)
├── classes/             # Classi aggiuntive (opzionale)
│   ├── BookHandler.php
│   └── ApiClient.php
├── views/               # Template/View (opzionale)
│   ├── settings.php
│   └── widget.php
├── assets/              # CSS/JS/Immagini (opzionale)
│   ├── style.css
│   └── script.js
└── README.md            # Documentazione (opzionale)
```

### File Obbligatori

1. **plugin.json** - Contiene i metadati del plugin
2. **File principale PHP** - Contiene la classe principale del plugin

---

## File plugin.json

Il file `plugin.json` contiene tutte le informazioni sul plugin:

```json
{
  "name": "my-plugin",
  "display_name": "Il Mio Plugin",
  "description": "Una breve descrizione del plugin",
  "version": "1.0.0",
  "author": "Nome Autore",
  "author_url": "https://example.com",
  "plugin_url": "https://github.com/username/my-plugin",
  "main_file": "MyPlugin.php",
  "requires_php": "8.0",
  "requires_app": "1.0.0",
  "metadata": {
    "license": "MIT",
    "tags": ["books", "metadata", "api"],
    "custom_field": "valore personalizzato"
  }
}
```

### Campi Obbligatori

- **name** (string): Identificatore univoco del plugin (solo lettere minuscole, numeri e trattini)
- **display_name** (string): Nome visualizzato nell'interfaccia
- **version** (string): Versione del plugin (formato semver: x.y.z)
- **main_file** (string): Nome del file PHP principale

### Campi Opzionali

- **description** (string): Descrizione del plugin
- **author** (string): Nome dell'autore
- **author_url** (string): URL del sito dell'autore
- **plugin_url** (string): URL del repository/sito del plugin
- **requires_php** (string): Versione minima di PHP richiesta
- **requires_app** (string): Versione minima dell'applicazione richiesta
- **metadata** (object): Dati personalizzati aggiuntivi

---

## Classe Principale del Plugin

La classe principale deve seguire queste convenzioni:

- Nome: `{PluginName}Plugin` (es: `MyPluginPlugin`, `BookEnhancerPlugin`)
- Namespace: Opzionale, ma consigliato
- Deve implementare i metodi del ciclo di vita

### Esempio Base

```php
<?php
declare(strict_types=1);

use App\Support\HookManager;
use App\Support\Hooks;
use mysqli;

class MyPluginPlugin
{
    private mysqli $db;
    private HookManager $hookManager;
    private int $pluginId;

    /**
     * Costruttore - riceve database e hook manager
     */
    public function __construct(mysqli $db, HookManager $hookManager)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;

        // Ottieni ID del plugin dal database
        $result = $db->query("SELECT id FROM plugins WHERE name = 'my-plugin' LIMIT 1");
        if ($result && $row = $result->fetch_assoc()) {
            $this->pluginId = (int)$row['id'];
        }
    }

    /**
     * Eseguito durante l'installazione del plugin
     */
    public function onInstall(): void
    {
        // Crea tabelle personalizzate se necessario
        $this->db->query("
            CREATE TABLE IF NOT EXISTS my_plugin_data (
                id INT AUTO_INCREMENT PRIMARY KEY,
                libro_id INT NOT NULL,
                custom_field VARCHAR(255),
                FOREIGN KEY (libro_id) REFERENCES libri(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Salva impostazioni iniziali
        $this->setSetting('enabled', 'true');
        $this->setSetting('api_key', '');
    }

    /**
     * Eseguito quando il plugin viene attivato
     */
    public function onActivate(): void
    {
        // Registra gli hook
        $this->registerHooks();

        // Esegui operazioni di attivazione
        $this->log('info', 'Plugin attivato');
    }

    /**
     * Eseguito quando il plugin viene disattivato
     */
    public function onDeactivate(): void
    {
        // Pulisci cache o risorse temporanee
        $this->log('info', 'Plugin disattivato');
    }

    /**
     * Eseguito durante la disinstallazione
     */
    public function onUninstall(): void
    {
        // Elimina tabelle personalizzate
        $this->db->query("DROP TABLE IF EXISTS my_plugin_data");

        // Elimina file/directory se necessario
        // Nota: le impostazioni nel database vengono eliminate automaticamente
    }

    /**
     * Registra gli hook del plugin
     */
    private function registerHooks(): void
    {
        // Registra hook nel database
        $this->db->query("
            INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority)
            VALUES
                ({$this->pluginId}, 'book.data.get', 'MyPluginBookHandler', 'enrichBookData', 10),
                ({$this->pluginId}, 'book.save.after', 'MyPluginBookHandler', 'saveCustomFields', 10)
            ON DUPLICATE KEY UPDATE priority = VALUES(priority)
        ");
    }

    /**
     * Helper per salvare impostazioni
     */
    private function setSetting(string $key, string $value): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO plugin_settings (plugin_id, setting_key, setting_value)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->bind_param('iss', $this->pluginId, $key, $value);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Helper per logging
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $contextJson = json_encode($context);
        $stmt = $this->db->prepare("
            INSERT INTO plugin_logs (plugin_id, level, message, context)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param('isss', $this->pluginId, $level, $message, $contextJson);
        $stmt->execute();
        $stmt->close();
    }
}
```

---

## Sistema di Hook

Il sistema di hook permette di estendere l'applicazione in punti specifici.

### Tipi di Hook

#### 1. Filter Hooks
Modificano e restituiscono un valore.

```php
// Registrazione
Hooks::add('book.data.get', function($bookData, $bookId) {
    // Modifica i dati
    $bookData['custom_field'] = 'valore';
    return $bookData; // IMPORTANTE: restituire il valore modificato
}, 10); // priorità

// Utilizzo nel core
$bookData = Hooks::apply('book.data.get', $bookData, [$bookId]);
```

#### 2. Action Hooks
Eseguono codice senza restituire un valore.

```php
// Registrazione
Hooks::add('book.save.after', function($bookId, $bookData) {
    // Esegui azioni
    error_log("Libro $bookId salvato");
}, 10);

// Utilizzo nel core
Hooks::do('book.save.after', [$bookId, $bookData]);
```

### Priorità

La priorità determina l'ordine di esecuzione:
- Valori più bassi = esecuzione prima
- Default: 10
- Range consigliato: 1-100

```php
Hooks::add('hook.name', $callback, 5);  // Eseguito per primo
Hooks::add('hook.name', $callback, 10); // Eseguito secondo (default)
Hooks::add('hook.name', $callback, 20); // Eseguito terzo
```

### Registrazione Hook

#### Metodo 1: Nel Database (consigliato per plugin distribuiti)

```php
$this->db->query("
    INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority)
    VALUES ({$this->pluginId}, 'book.data.get', 'MyPlugin\\BookHandler', 'enrichData', 10)
");
```

#### Metodo 2: Runtime (utile per sviluppo/test)

```php
Hooks::add('book.data.get', [$this, 'enrichData'], 10);
// oppure
Hooks::add('book.data.get', function($data) { return $data; }, 10);
```

---

## Hook Disponibili

Consulta il file [PLUGIN_HOOKS.md](./PLUGIN_HOOKS.md) per l'elenco completo degli hook disponibili con esempi e parametri.

### Hook Principali

| Hook | Tipo | Descrizione |
|------|------|-------------|
| `book.data.get` | Filter | Modifica dati libro recuperati |
| `book.save.after` | Action | Dopo il salvataggio libro |
| `author.data.get` | Filter | Modifica dati autore |
| `publisher.data.get` | Filter | Modifica dati editore |
| `login.validate` | Filter | Validazione custom login |
| `catalog.query.modify` | Filter | Modifica query ricerca |
| `scrape.sources` | Filter | Aggiunge fonti scraping |
| `image.process` | Filter | Elabora immagini |

---

## API del Plugin

### PluginManager

Accessibile tramite container: `$app->getContainer()->get('pluginManager')`

```php
// Ottieni plugin
$plugin = $pluginManager->getPlugin($pluginId);
$plugin = $pluginManager->getPluginByName('my-plugin');
$plugins = $pluginManager->getAllPlugins();
$activePlugins = $pluginManager->getActivePlugins();

// Gestione
$result = $pluginManager->activatePlugin($pluginId);
$result = $pluginManager->deactivatePlugin($pluginId);
$result = $pluginManager->uninstallPlugin($pluginId);

// Settings
$value = $pluginManager->getSetting($pluginId, 'api_key', 'default');
$pluginManager->setSetting($pluginId, 'api_key', 'new_value');

// Data storage
$data = $pluginManager->getData($pluginId, 'cache_data');
$pluginManager->setData($pluginId, 'cache_data', $value, 'json');

// Logging
$pluginManager->log($pluginId, 'info', 'Messaggio', ['context' => 'data']);
```

### HookManager

Accessibile tramite `Hooks` helper class:

```php
// Applicare filter
$value = Hooks::apply('hook.name', $value, [$arg1, $arg2]);

// Eseguire action
Hooks::do('hook.name', [$arg1, $arg2]);

// Verificare hook
if (Hooks::has('hook.name')) {
    // hook esiste
}

// Aggiungere hook runtime
Hooks::add('hook.name', $callback, 10);
```

### Database

Accesso al database tramite `$this->db`:

```php
// Query preparata
$stmt = $this->db->prepare("SELECT * FROM libri WHERE id = ?");
$stmt->bind_param('i', $bookId);
$stmt->execute();
$result = $stmt->get_result();
$book = $result->fetch_assoc();
$stmt->close();

// Query diretta (solo per query sicure, senza input utente)
$result = $this->db->query("SELECT COUNT(*) as total FROM libri");
```

---

## Esempi Pratici

### Esempio 1: Estendere Campi Libro

```php
<?php
// File: MyPlugin/classes/BookHandler.php

namespace MyPlugin;

class BookHandler
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Aggiunge campi personalizzati ai dati del libro
     */
    public function enrichBookData(array $bookData, int $bookId): array
    {
        // Recupera dati personalizzati
        $stmt = $this->db->prepare("
            SELECT rating, review_count FROM my_plugin_book_ratings
            WHERE libro_id = ?
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $bookData['external_rating'] = $row['rating'];
            $bookData['external_review_count'] = $row['review_count'];
        }

        $stmt->close();
        return $bookData;
    }

    /**
     * Salva campi personalizzati dopo il salvataggio del libro
     */
    public function saveCustomFields(int $bookId, array $bookData): void
    {
        if (isset($_POST['external_rating'])) {
            $rating = floatval($_POST['external_rating']);

            $stmt = $this->db->prepare("
                INSERT INTO my_plugin_book_ratings (libro_id, rating, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE rating = VALUES(rating), updated_at = NOW()
            ");
            $stmt->bind_param('id', $bookId, $rating);
            $stmt->execute();
            $stmt->close();
        }
    }
}
```

### Esempio 2: Aggiungere Fonte di Scraping

```php
<?php
// File: MyPlugin/classes/ScrapingHandler.php

namespace MyPlugin;

class ScrapingHandler
{
    /**
     * Aggiunge una fonte di scraping personalizzata
     */
    public function addScrapingSource(array $sources): array
    {
        $sources['my_source'] = [
            'name' => 'My Custom Source',
            'url_pattern' => 'https://example.com/api/book?isbn={isbn}',
            'parser' => [$this, 'parseResponse']
        ];

        return $sources;
    }

    /**
     * Parsing della risposta API
     */
    public function parseResponse(string $response, string $source): array
    {
        $data = json_decode($response, true);

        return [
            'title' => $data['title'] ?? '',
            'authors' => $data['authors'] ?? [],
            'publisher' => $data['publisher'] ?? '',
            'year' => $data['publication_year'] ?? '',
            'description' => $data['description'] ?? '',
            'cover_url' => $data['cover'] ?? ''
        ];
    }
}
```

### Esempio 3: Estendere il Login

```php
<?php
// File: MyPlugin/classes/LoginHandler.php

namespace MyPlugin;

class LoginHandler
{
    /**
     * Aggiunge campo reCAPTCHA al form login
     */
    public function addRecaptchaField(): void
    {
        $siteKey = $this->getSetting('recaptcha_site_key');

        echo '<div class="g-recaptcha" data-sitekey="' . htmlspecialchars($siteKey) . '"></div>';
        echo '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
    }

    /**
     * Valida reCAPTCHA durante il login
     */
    public function validateRecaptcha(bool $isValid, array $credentials): bool
    {
        if (!$isValid) {
            return false; // Già fallito, non proseguire
        }

        $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';
        $secretKey = $this->getSetting('recaptcha_secret_key');

        $response = file_get_contents(
            'https://www.google.com/recaptcha/api/siteverify?secret=' .
            $secretKey . '&response=' . $recaptchaResponse
        );

        $data = json_decode($response);

        return $data->success === true;
    }
}
```

### Esempio 4: Elaborazione Immagini

```php
<?php
// File: MyPlugin/classes/ImageHandler.php

namespace MyPlugin;

class ImageHandler
{
    /**
     * Applica watermark alle immagini caricate
     */
    public function applyWatermark(string $imagePath, array $options): string
    {
        // Carica immagine
        $image = imagecreatefromjpeg($imagePath);

        // Carica watermark
        $watermarkPath = __DIR__ . '/../assets/watermark.png';
        $watermark = imagecreatefrompng($watermarkPath);

        // Dimensioni
        $imageWidth = imagesx($image);
        $imageHeight = imagesy($image);
        $watermarkWidth = imagesx($watermark);
        $watermarkHeight = imagesy($watermark);

        // Posiziona watermark in basso a destra
        $destX = $imageWidth - $watermarkWidth - 10;
        $destY = $imageHeight - $watermarkHeight - 10;

        // Applica watermark con trasparenza
        imagecopy($image, $watermark, $destX, $destY, 0, 0, $watermarkWidth, $watermarkHeight);

        // Salva immagine modificata
        imagejpeg($image, $imagePath, 90);

        // Libera memoria
        imagedestroy($image);
        imagedestroy($watermark);

        return $imagePath;
    }

    /**
     * Genera miniature aggiuntive
     */
    public function generateThumbnails(string $imagePath): void
    {
        $sizes = [
            'small' => [150, 200],
            'medium' => [300, 400],
            'large' => [600, 800]
        ];

        foreach ($sizes as $name => $dimensions) {
            $this->createThumbnail($imagePath, $name, $dimensions[0], $dimensions[1]);
        }
    }

    private function createThumbnail(string $source, string $name, int $width, int $height): void
    {
        // Implementazione resize...
    }
}
```

---

## Installazione e Gestione

### Per gli Utenti

#### Installazione

1. Accedi al pannello admin di Pinakes
2. Vai su **Plugin** nel menu laterale
3. Clicca su **Carica Plugin**
4. Seleziona il file ZIP del plugin
5. Clicca su **Installa Plugin**

#### Attivazione

1. Trova il plugin nella lista
2. Clicca su **Attiva**

#### Disattivazione

1. Trova il plugin nella lista
2. Clicca su **Disattiva**

#### Disinstallazione

1. Disattiva il plugin se è attivo
2. Clicca su **Disinstalla**
3. Conferma l'operazione

** Attenzione:** La disinstallazione elimina tutti i dati del plugin!

### Per gli Sviluppatori

#### Creazione Pacchetto ZIP

Il file ZIP deve contenere tutti i file del plugin con `plugin.json` nella root:

```bash
zip -r my-plugin.zip my-plugin/
```

Struttura ZIP corretta:
```
my-plugin.zip
└── my-plugin/
    ├── plugin.json
    ├── MyPlugin.php
    └── ...
```

** Struttura ERRATA:**
```
my-plugin.zip
├── plugin.json    # NON nella root del ZIP
└── MyPlugin.php
```

#### Testing Locale

1. Crea directory plugin:
```bash
mkdir -p storage/plugins/my-plugin
```

2. Copia file del plugin:
```bash
cp -r /path/to/my-plugin/* storage/plugins/my-plugin/
```

3. Registra plugin nel database:
```sql
INSERT INTO plugins (name, display_name, version, path, main_file, installed_at)
VALUES ('my-plugin', 'My Plugin', '1.0.0', 'my-plugin', 'MyPlugin.php', NOW());
```

4. Attiva tramite interfaccia admin

---

## Sicurezza

### Best Practices

#### 1. Validazione Input

```php
//  CORRETTO
$bookId = filter_input(INPUT_POST, 'book_id', FILTER_VALIDATE_INT);
if ($bookId === false || $bookId === null) {
    throw new \InvalidArgumentException('ID libro non valido');
}

//  ERRATO
$bookId = $_POST['book_id']; // Mai usare input diretto
```

#### 2. Query Preparate

```php
//  CORRETTO
$stmt = $this->db->prepare("SELECT * FROM libri WHERE id = ?");
$stmt->bind_param('i', $bookId);
$stmt->execute();

//  ERRATO
$this->db->query("SELECT * FROM libri WHERE id = $bookId"); // SQL Injection!
```

#### 3. Output Escaping

```php
//  CORRETTO
echo htmlspecialchars($userInput, ENT_QUOTES, 'UTF-8');

//  ERRATO
echo $userInput; // XSS vulnerability!
```

#### 4. Verifica Permessi

```php
//  CORRETTO
if (!isset($_SESSION['user']) || $_SESSION['user']['tipo_utente'] !== 'admin') {
    throw new \Exception('Accesso negato');
}

// Procedi con operazione privilegiata
```

#### 5. CSRF Protection

```php
// Nel form
echo '<input type="hidden" name="csrf_token" value="' . \App\Support\Csrf::ensureToken() . '">';

// Nella gestione POST
if (!\App\Support\Csrf::validateToken($_POST['csrf_token'] ?? '')) {
    throw new \Exception('Token CSRF non valido');
}
```

#### 6. File Upload

```php
// Valida tipo file
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

if (!in_array($extension, $allowedExtensions)) {
    throw new \Exception('Tipo file non permesso');
}

// Valida dimensione
$maxSize = 5 * 1024 * 1024; // 5MB
if ($fileSize > $maxSize) {
    throw new \Exception('File troppo grande');
}

// Usa nome file sicuro
$safeFilename = uniqid('upload_', true) . '.' . $extension;
```

#### 7. API Esterne

```php
// Usa timeout
$context = stream_context_create([
    'http' => [
        'timeout' => 10 // secondi
    ]
]);

$response = file_get_contents('https://api.example.com', false, $context);

// Valida risposta
if ($response === false) {
    throw new \Exception('Errore chiamata API');
}
```

### Limitazioni

I plugin **NON POSSONO**:
- Modificare file core dell'applicazione
- Accedere a directory fuori da `storage/plugins/{plugin-name}/`
- Eseguire comandi di sistema (exec, shell_exec, etc.)
- Modificare configurazioni PHP globali
- Disabilitare sicurezza (es. disabilitare CSRF)

---

## Aggiornamento Installazioni Esistenti

Se hai un'installazione esistente di Pinakes e vuoi aggiungere il supporto ai plugin, segui questi passaggi:

### Metodo 1: Esecuzione Migration SQL (Consigliato)

1. **Backup del Database**
```bash
mysqldump -u username -p database_name > backup_$(date +%Y%m%d).sql
```

2. **Esegui la Migration**

Accedi al database e esegui il file:
```bash
mysql -u username -p database_name < data/migrations/create_plugins_table.sql
```

Oppure tramite phpMyAdmin:
- Vai su **Import**
- Seleziona il file `data/migrations/create_plugins_table.sql`
- Clicca su **Esegui**

3. **Verifica**

Controlla che le tabelle siano state create:
```sql
SHOW TABLES LIKE 'plugin%';
```

Dovresti vedere:
- `plugins`
- `plugin_hooks`
- `plugin_settings`
- `plugin_data`
- `plugin_logs`

### Metodo 2: Aggiornamento Manuale

Se preferisci aggiornare manualmente, esegui queste query SQL:

```sql
-- Tabella plugins
CREATE TABLE IF NOT EXISTS `plugins` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL COMMENT 'Plugin unique identifier',
  `display_name` VARCHAR(255) NOT NULL COMMENT 'Human-readable plugin name',
  `description` TEXT NULL COMMENT 'Plugin description',
  `version` VARCHAR(50) NOT NULL COMMENT 'Plugin version',
  `author` VARCHAR(255) NULL COMMENT 'Plugin author name',
  `author_url` VARCHAR(255) NULL COMMENT 'Author website URL',
  `plugin_url` VARCHAR(255) NULL COMMENT 'Plugin website/repository URL',
  `is_active` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether plugin is activated',
  `path` VARCHAR(255) NOT NULL COMMENT 'Plugin directory path',
  `main_file` VARCHAR(255) NOT NULL COMMENT 'Main plugin file',
  `requires_php` VARCHAR(50) NULL COMMENT 'Minimum PHP version required',
  `requires_app` VARCHAR(50) NULL COMMENT 'Minimum app version required',
  `metadata` JSON NULL COMMENT 'Additional plugin metadata',
  `installed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Installation timestamp',
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
  `activated_at` DATETIME NULL COMMENT 'Last activation timestamp',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_plugin_name` (`name`),
  KEY `idx_active` (`is_active`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plugin registry and metadata';

-- Tabella plugin_hooks
CREATE TABLE IF NOT EXISTS `plugin_hooks` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `plugin_id` INT(11) UNSIGNED NOT NULL COMMENT 'Reference to plugin',
  `hook_name` VARCHAR(255) NOT NULL COMMENT 'Hook identifier',
  `callback_class` VARCHAR(255) NOT NULL COMMENT 'PHP class to call',
  `callback_method` VARCHAR(255) NOT NULL COMMENT 'Method name in class',
  `priority` INT(11) NOT NULL DEFAULT 10 COMMENT 'Execution priority (lower = earlier)',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Whether hook is active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hook_name` (`hook_name`, `priority`),
  KEY `idx_plugin_id` (`plugin_id`),
  KEY `idx_active` (`is_active`),
  CONSTRAINT `fk_plugin_hooks_plugin` FOREIGN KEY (`plugin_id`) REFERENCES `plugins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plugin hooks registry';

-- Tabella plugin_settings
CREATE TABLE IF NOT EXISTS `plugin_settings` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `plugin_id` INT(11) UNSIGNED NOT NULL COMMENT 'Reference to plugin',
  `setting_key` VARCHAR(255) NOT NULL COMMENT 'Setting key',
  `setting_value` LONGTEXT NULL COMMENT 'Setting value (can store JSON)',
  `autoload` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Whether to autoload this setting',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_plugin_setting` (`plugin_id`, `setting_key`),
  KEY `idx_plugin_id` (`plugin_id`),
  KEY `idx_autoload` (`autoload`),
  CONSTRAINT `fk_plugin_settings_plugin` FOREIGN KEY (`plugin_id`) REFERENCES `plugins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plugin-specific settings and configuration';

-- Tabella plugin_data
CREATE TABLE IF NOT EXISTS `plugin_data` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `plugin_id` INT(11) UNSIGNED NOT NULL COMMENT 'Reference to plugin',
  `data_key` VARCHAR(255) NOT NULL COMMENT 'Data key',
  `data_value` LONGTEXT NULL COMMENT 'Data value (can store JSON)',
  `data_type` VARCHAR(50) NOT NULL DEFAULT 'string' COMMENT 'Data type hint',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plugin_key` (`plugin_id`, `data_key`),
  KEY `idx_plugin_id` (`plugin_id`),
  CONSTRAINT `fk_plugin_data_plugin` FOREIGN KEY (`plugin_id`) REFERENCES `plugins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Generic plugin data storage';

-- Tabella plugin_logs
CREATE TABLE IF NOT EXISTS `plugin_logs` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `plugin_id` INT(11) UNSIGNED NULL COMMENT 'Reference to plugin (NULL for system logs)',
  `level` VARCHAR(20) NOT NULL DEFAULT 'info' COMMENT 'Log level',
  `message` TEXT NOT NULL COMMENT 'Log message',
  `context` JSON NULL COMMENT 'Additional context data',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plugin_id` (`plugin_id`),
  KEY `idx_level` (`level`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_plugin_logs_plugin` FOREIGN KEY (`plugin_id`) REFERENCES `plugins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plugin activity and error logs';
```

### 4. Verifica File

Assicurati che questi file siano presenti:

- `app/Support/HookManager.php`
- `app/Support/PluginManager.php`
- `app/Support/Hooks.php`
- `app/Controllers/PluginController.php`
- `app/Views/admin/plugins.php`
- Routes aggiunte in `app/Routes/web.php`
- Voci container in `config/container.php`
- Inizializzazione in `public/index.php`

### 5. Verifica Permessi Directory

```bash
mkdir -p storage/plugins
chmod 755 storage/plugins

mkdir -p uploads/plugins
chmod 755 uploads/plugins
```

### 6. Test

1. Accedi come admin
2. Verifica che il menu "Plugin" sia visibile nella sidebar
3. Clicca su "Plugin" - dovresti vedere la pagina di gestione plugin
4. Prova a caricare un plugin di test

---

## Risoluzione Problemi

### Il menu Plugin non appare

**Soluzione:**
- Verifica di essere loggato come admin (non staff)
- Controlla che il file `app/Views/layout.php` contenga il link ai plugin
- Svuota la cache del browser

### Errore "Table 'plugins' doesn't exist"

**Soluzione:**
- Esegui la migration SQL
- Verifica che la connessione al database funzioni
- Controlla i permessi dell'utente MySQL

### Plugin non si installa

**Possibili cause:**
- File ZIP malformato (plugin.json non nella root)
- Permessi directory `storage/plugins` insufficienti
- PHP version non compatibile
- Campi obbligatori mancanti in plugin.json

### Hook non viene eseguito

**Verifiche:**
- Plugin attivato?
- Hook registrato correttamente nel database?
- Priorità corretta?
- Nome hook corretto?
- Classe e metodo callback esistono?

### Errore durante attivazione

**Soluzioni:**
- Controlla i log in `plugin_logs`
- Verifica sintassi PHP nel file principale
- Controlla che tutte le dipendenze siano disponibili
- Assicurati che `onActivate()` non sollevi eccezioni

---

## Supporto e Contributi

### Ottenere Aiuto

- Consulta la documentazione completa in `docs/`
- Controlla gli esempi in `docs/examples/`
- Rivedi i log in tabella `plugin_logs`

### Segnalare Bug

Quando segnali un bug, includi:
- Versione Pinakes
- Versione PHP
- Descrizione del problema
- Passi per riprodurre
- Log degli errori

---

## Licenza

Il sistema di plugin di Pinakes è distribuito con la stessa licenza dell'applicazione principale.

---

**Documentazione aggiornata:** 2025-01-05
**Versione sistema plugin:** 1.0.0
**Compatibile con Pinakes:** 1.0.0+

