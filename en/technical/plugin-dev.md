# Plugin Development

Developer guide to creating plugins for Pinakes.

## Base Structure

```
storage/plugins/my-plugin/
├── MyPluginPlugin.php     # Main class (required)
├── plugin.json            # Metadata (required)
├── classes/               # Additional classes
│   └── MyService.php
├── views/                 # PHP templates
│   └── settings.php
├── assets/                # Static resources
│   ├── style.css
│   └── script.js
└── migrations/            # SQL for installation
    └── install.sql
```

## plugin.json

```json
{
  "name": "my-plugin",
  "display_name": "My Plugin",
  "description": "Description of the functionality",
  "version": "1.0.0",
  "author": "Author Name",
  "author_url": "https://example.com",
  "plugin_url": "https://github.com/author/plugin",
  "main_file": "MyPluginPlugin.php",
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

### Required Fields

| Field | Type | Description |
|-------|------|-------------|
| `name` | string | Unique identifier (lowercase, hyphens) |
| `display_name` | string | Display name |
| `version` | string | Semver version (X.Y.Z) |
| `main_file` | string | Main class file |
| `requires_php` | string | Minimum PHP version |
| `requires_app` | string | Minimum Pinakes version |

### Optional Fields

| Field | Type | Description |
|-------|------|-------------|
| `description` | string | Extended description |
| `author` | string | Author name |
| `settings` | boolean | Has settings page |
| `max_app_version` | string | Maximum Pinakes version |

## Main Class

```php
<?php
namespace Plugins\MyPlugin;

use App\Support\PluginBase;
use App\Support\HookManager;

class MyPluginPlugin extends PluginBase
{
    /**
     * Executed on plugin activation
     */
    public function activate(): void
    {
        // Create tables, initialize data
        $this->runMigration('install.sql');
    }

    /**
     * Executed on deactivation
     */
    public function deactivate(): void
    {
        // Resource cleanup (optional)
    }

    /**
     * Executed on every load
     */
    public function boot(): void
    {
        // Register hooks
        $this->registerHooks();
    }

    /**
     * Render settings page
     */
    public function renderSettings(): string
    {
        return $this->render('settings.php', [
            'settings' => $this->getSettings()
        ]);
    }

    /**
     * Save settings
     */
    public function saveSettings(array $data): bool
    {
        return $this->updateSettings($data);
    }

    private function registerHooks(): void
    {
        HookManager::addAction('book.save.after', function($book) {
            // Logic after book save
        }, 10);
    }
}
```

## Hook System

### Registration

```php
use App\Support\HookManager;

// Action (executes code)
HookManager::addAction('hook.name', callable $callback, int $priority = 10);

// Filter (modifies data)
HookManager::addFilter('filter.name', callable $callback, int $priority = 10);
```

### Available Hooks

#### Application

| Hook | Type | Arguments |
|------|------|-----------|
| `app.init` | action | - |
| `app.routes.register` | action | `$app` (Slim) |
| `app.shutdown` | action | - |

#### Assets

| Hook | Type | Description |
|------|------|-------------|
| `assets.head` | filter | Add CSS/meta in `<head>` |
| `assets.footer` | filter | Add JS before `</body>` |

#### Books

| Hook | Type | Arguments |
|------|------|-----------|
| `book.save.before` | action | `$data` (array) |
| `book.save.after` | action | `$book` (object) |
| `book.delete.before` | action | `$bookId` |
| `book.scrape.isbn` | filter | `$isbn` |

#### Loans

| Hook | Type | Arguments |
|------|------|-----------|
| `loan.create.before` | action | `$data` |
| `loan.approve.after` | action | `$loan` |
| `loan.return.after` | action | `$loan` |

### Hook Example

```php
// Add custom CSS
HookManager::addFilter('assets.head', function($html) {
    $css = '<link rel="stylesheet" href="/plugins/my-plugin/style.css">';
    return $html . $css;
});

// Modify book data before save
HookManager::addFilter('book.save.before', function($data) {
    $data['custom_field'] = 'value';
    return $data;
});
```

## Custom Routes

```php
public function boot(): void
{
    HookManager::addAction('app.routes.register', function($app) {
        $app->get('/my-plugin/page', [$this, 'handlePage']);
        $app->post('/my-plugin/api', [$this, 'handleApi']);
    });
}

public function handlePage($request, $response)
{
    $html = $this->render('page.php');
    $response->getBody()->write($html);
    return $response;
}
```

## Database

### Migrations

Create files in `migrations/`:

```sql
-- migrations/install.sql
CREATE TABLE IF NOT EXISTS plugin_my_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Execute from code:

```php
$this->runMigration('install.sql');
```

### Queries

```php
// Database access
$db = $this->getDatabase();

$stmt = $db->prepare("SELECT * FROM plugin_my_data WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
```

## Settings

### Read/Write

```php
// Read single setting
$value = $this->getSetting('key', 'default');

// Read all
$settings = $this->getSettings();

// Write
$this->updateSettings(['key' => 'value']);
```

### Settings Page

```php
// views/settings.php
<form method="post">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

    <label>
        Option:
        <input type="text" name="option"
               value="<?= htmlspecialchars($settings['option'] ?? '') ?>">
    </label>

    <button type="submit"><?= __('Save') ?></button>
</form>
```

## Best Practices

1. **Namespace**: Use `Plugins\PluginName` to avoid conflicts
2. **Translations**: Use `__()` for all UI strings
3. **Security**: Always validate input, escape output
4. **Cleanup**: Implement `deactivate()` for cleanup
5. **Compatibility**: Test on declared PHP/Pinakes versions
6. **Documentation**: Include README.md in the plugin

## Testing

```bash
# Verify PHP syntax
php -l storage/plugins/my-plugin/MyPluginPlugin.php

# Log during development
SecureLogger::debug('My plugin', ['data' => $value]);

# View logs
tail -f storage/logs/app.log | grep "My plugin"
```

---

## Frequently Asked Questions (FAQ)

### 1. What is the minimum structure to create a working plugin?

A minimal plugin requires only 2 files:

**plugin.json:**
```json
{
  "name": "my-plugin",
  "display_name": "My Plugin",
  "version": "1.0.0",
  "main_file": "MyPluginPlugin.php",
  "requires_php": "8.0",
  "requires_app": "0.4.0"
}
```

**MyPluginPlugin.php:**
```php
<?php
namespace Plugins\MyPlugin;

use App\Support\PluginBase;

class MyPluginPlugin extends PluginBase
{
    public function activate(): void {}
    public function deactivate(): void {}
}
```

Save in `storage/plugins/my-plugin/` and the plugin will appear in the list.

---

### 2. How do I register a new route from my plugin?

Use the `app.routes.register` hook:

```php
public function boot(): void
{
    \App\Support\HookManager::addAction('app.routes.register', function($app) {
        // GET route
        $app->get('/my-plugin/page', [$this, 'showPage']);

        // POST route
        $app->post('/my-plugin/save', [$this, 'saveForm']);

        // Route with parameter
        $app->get('/my-plugin/book/{id}', [$this, 'bookDetail']);
    });
}

public function showPage($request, $response)
{
    $html = $this->render('page.php');
    $response->getBody()->write($html);
    return $response;
}
```

Plugin routes have full access to the Slim `$app` object.

---

### 3. How do I access the database from my plugin?

Use the `getDatabase()` method from the base class:

```php
public function searchBooks(string $title): array
{
    $db = $this->getDatabase();

    $stmt = $db->prepare("SELECT * FROM libri WHERE titolo LIKE ?");
    $search = "%{$title}%";
    $stmt->bind_param('s', $search);
    $stmt->execute();

    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
```

**Important:**
- Always use prepared statements
- Handle exceptions
- Don't modify core tables unnecessarily

---

### 4. How do I create database tables for my plugin?

Create a SQL file in `migrations/`:

**migrations/install.sql:**
```sql
CREATE TABLE IF NOT EXISTS plugin_mydata (
    id INT AUTO_INCREMENT PRIMARY KEY,
    libro_id INT NOT NULL,
    custom_field VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (libro_id) REFERENCES libri(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Execute in activate():**
```php
public function activate(): void
{
    $this->runMigration('install.sql');
}
```

---

### 5. How do I manage plugin settings?

The `PluginBase` class provides methods for settings:

```php
// Read single setting
$value = $this->getSetting('api_key', 'default');

// Read all
$settings = $this->getSettings();

// Save settings
$this->updateSettings([
    'api_key' => 'abc123',
    'active' => true
]);
```

**Settings page:**
1. In `plugin.json`, set `"settings": true`
2. Implement `renderSettings()` and `saveSettings()`

Settings are saved in `plugin_settings`.

---

### 6. What hooks are available for modifying book behavior?

| Hook | Type | When |
|------|------|------|
| `book.save.before` | filter | Before save (can modify $data) |
| `book.save.after` | action | After save (receives $book) |
| `book.delete.before` | action | Before deletion |
| `book.scrape.isbn` | filter | During ISBN scraping |

**Example - Add custom field:**
```php
HookManager::addFilter('book.save.before', function($data) {
    $data['custom_field'] = $this->calculateValue($data);
    return $data;
}, 10);
```

---

### 7. How do I add CSS and JavaScript from my plugin?

Use the `assets.head` and `assets.footer` hooks:

```php
public function boot(): void
{
    // CSS in <head>
    HookManager::addFilter('assets.head', function($html) {
        $css = '<link rel="stylesheet" href="/plugins/my-plugin/assets/style.css">';
        return $html . $css;
    });

    // JS before </body>
    HookManager::addFilter('assets.footer', function($html) {
        $js = '<script src="/plugins/my-plugin/assets/script.js"></script>';
        return $html . $js;
    });
}
```

Save files in `storage/plugins/my-plugin/assets/`.

---

### 8. How do I make my plugin compatible with multiple Pinakes versions?

In `plugin.json` specify the limits:

```json
{
  "requires_app": "0.4.0",
  "max_app_version": "1.0.0"
}
```

**In code, verify features:**
```php
public function boot(): void
{
    // Check if a function/class exists
    if (class_exists('\App\Support\NewFeature')) {
        // Use the new feature
    } else {
        // Fallback for older versions
    }
}
```

**Best practice:**
- Test on all declared versions
- Document compatibility in README

---

### 9. How do I debug my plugin?

**1. Logging:**
```php
use App\Support\SecureLogger;

SecureLogger::debug('MyPlugin: debug', ['data' => $value]);
SecureLogger::info('MyPlugin: info', ['status' => 'ok']);
SecureLogger::error('MyPlugin: error', ['error' => $e->getMessage()]);
```

**2. View logs:**
```bash
tail -f storage/logs/app.log | grep "MyPlugin"
```

**3. Verify syntax:**
```bash
php -l storage/plugins/my-plugin/MyPluginPlugin.php
```

**4. PHP debug:**
In `.env` set `APP_DEBUG=true` to see detailed errors.

---

### 10. How do I distribute my plugin to other users?

**1. Prepare the structure:**
```
my-plugin/
├── MyPluginPlugin.php
├── plugin.json
├── README.md          # Documentation
├── LICENSE            # License
├── classes/
├── views/
├── assets/
└── migrations/
```

**2. Create ZIP archive:**
```bash
cd storage/plugins
zip -r my-plugin-v1.0.0.zip my-plugin/
```

**3. Distribution:**
- Publish on GitHub with release
- Upload to Pinakes plugin repository (if available)
- Share the ZIP file directly

**4. User installation:**
- **Admin → Plugins → Upload plugin** → select ZIP
