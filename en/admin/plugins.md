# Plugin System

Pinakes supports plugins to extend functionality.

## Official Plugins

| Plugin | Version | Description |
|--------|---------|-------------|
| Z39.50/SRU Integration | 1.1.0 | SRU server and client for interoperability |
| API Book Scraper | 1.1.0 | Metadata scraping from external APIs |
| Scraping Pro | 1.4.0 | Advanced web scraping |
| Dewey Editor | 1.0.0 | Dewey classification editor |
| Digital Library | 1.0.0 | Ebook and audiobook management |
| Open Library | 1.0.0 | Open Library API integration |

## Plugin Installation

### From ZIP Archive

1. Go to **Administration → Plugins**
2. Click **Upload plugin**
3. Select the ZIP file
4. Click **Install**

### Manual Installation

1. Extract the ZIP to `storage/plugins/plugin-name/`
2. Verify `plugin.json` is present
3. Go to **Administration → Plugins**
4. The plugin appears in the list
5. Click **Activate**

## Plugin Structure

```
storage/plugins/my-plugin/
├── MyPluginPlugin.php    # Main class
├── plugin.json           # Metadata
├── classes/              # Additional classes
├── views/                # Templates
└── assets/               # CSS, JS, images
```

### plugin.json

```json
{
  "name": "my-plugin",
  "display_name": "My Plugin",
  "description": "Plugin description",
  "version": "1.0.0",
  "author": "Author Name",
  "requires_php": "8.0",
  "requires_app": "0.4.0",
  "main_file": "MyPluginPlugin.php"
}
```

## Plugin Management

### Activation/Deactivation

1. Go to **Administration → Plugins**
2. Find the plugin in the list
3. Click **Activate** or **Deactivate**

### Updating

1. Deactivate the plugin
2. Upload the new version
3. Reactivate the plugin

### Removal

1. Deactivate the plugin
2. Click **Remove**
3. Confirm deletion

Files are deleted from `storage/plugins/`.

## Configuration

Plugins with settings show a gear icon:

1. Click the settings icon
2. Modify parameters
3. Save

Settings are stored in database (`plugin_settings`).

## Hook System

Plugins integrate through hooks:

| Hook | Description |
|------|-------------|
| `app.routes.register` | Register new routes |
| `assets.head` | Add CSS/meta in head |
| `assets.footer` | Add JS in footer |
| `book.scrape.isbn` | ISBN search provider |
| `book.save.after` | After book save |

## Plugin Development

To create a new plugin:

1. Create folder in `storage/plugins/`
2. Create `plugin.json` with metadata
3. Create main class that extends `PluginBase`
4. Implement `activate()` and `deactivate()` methods
5. Register necessary hooks

Example base class:

```php
<?php
namespace Plugins\MyPlugin;

use App\Support\PluginBase;

class MyPluginPlugin extends PluginBase
{
    public function activate(): void
    {
        // Activation code
    }

    public function deactivate(): void
    {
        // Deactivation code
    }
}
```

## Compatibility

Each plugin declares:
- `requires_php`: minimum PHP version
- `requires_app`: minimum Pinakes version
- `max_app_version`: maximum Pinakes version

The system verifies compatibility before activation.

---

## Frequently Asked Questions (FAQ)

### 1. Where do I find official plugins to install?

Official plugins are available in two ways:

**Direct download:**
- Go to [Pinakes GitHub Releases](https://github.com/fabiodalez-dev/Pinakes/releases)
- Download the plugin ZIP archives

**From the Plugins page:**
- **Administration → Plugins → Plugin Catalog** (if available)
- List of plugins compatible with your version

---

### 2. The plugin won't activate, what should I check?

Common causes and solutions:

| Problem | Solution |
|---------|----------|
| PHP too old | Verify PHP meets `requires_php` |
| Pinakes too old | Update Pinakes to required version |
| Pinakes too new | Look for updated plugin version |
| Folder permissions | `chmod 755 storage/plugins/` |
| plugin.json missing | Verify ZIP archive structure |

**Check the logs**: `storage/logs/app.log` for detailed errors.

---

### 3. How do I update a plugin to a new version?

Safe procedure:

1. **Deactivate** the current plugin
2. **Backup** the plugin folder (optional but recommended)
3. **Upload** the new ZIP via interface
4. Or **overwrite** files manually in `storage/plugins/plugin-name/`
5. **Reactivate** the plugin

Settings are stored in database and are preserved.

---

### 4. Can I modify an existing plugin?

Yes, but with caution:

**Simple modifications:**
- Modify PHP files in `storage/plugins/plugin-name/`
- Change CSS/JS in `assets/`
- Modify templates in `views/`

**Caution:**
- Modifications are lost when updating the plugin
- Create a fork or derived plugin for permanent changes
- Don't modify `plugin.json` without understanding the consequences

---

### 5. How do I completely remove a plugin?

**From interface:**
1. **Deactivate** the plugin
2. Click **Remove**
3. Confirm deletion

**Manually:**
```bash
# Delete the folder
rm -rf storage/plugins/plugin-name/

# Remove settings from database (optional)
DELETE FROM plugin_settings WHERE plugin_name = 'plugin-name';
DELETE FROM plugin_hooks WHERE plugin_name = 'plugin-name';
```

---

### 6. Do plugins slow down the system?

It depends on the plugin:

**Lightweight plugins** (no noticeable impact):
- Dewey Editor
- Open Library Integration

**Plugins with impact** (external requests):
- API Book Scraper (HTTP calls)
- Z39.50 Server (if actively used)
- Scraping Pro (web page parsing)

**Suggestion**: Only activate plugins you actually use.

---

### 7. How do I create my own plugin?

Essential steps:

1. **Create folder**: `storage/plugins/my-plugin/`
2. **Create plugin.json** with metadata
3. **Create main class** that extends `PluginBase`
4. **Implement** `activate()` and `deactivate()`
5. **Register hooks** to integrate functionality

See the [plugin development guide](/technical/plugin-dev.md) for details.

---

### 8. What happens to plugin data if I deactivate it?

**Data preserved:**
- Settings in `plugin_settings`
- Registered hooks in `plugin_hooks`
- Any database tables created by the plugin

**Data removed** (only on "Remove"):
- Plugin folder files
- Settings and hooks (optional, depends on plugin)

Deactivating a plugin is safe: you can reactivate it without losing configurations.

---

### 9. How do I verify if a plugin is compatible with my version?

Check the `plugin.json` file:

```json
{
  "requires_php": "8.0",      // Minimum PHP
  "requires_app": "0.4.0",    // Minimum Pinakes
  "max_app_version": "1.0.0"  // Maximum Pinakes (optional)
}
```

**In the Plugins page:**
- Incompatible plugins show a warning
- The "Activate" button is disabled if not compatible

---

### 10. After a Pinakes update, do plugins still work?

It depends:

**Official plugins**: Generally yes, they are tested with new versions. Check release notes for any breaking changes.

**Third-party plugins**: May require updates. Check with the author.

**Best practices after update:**
1. Check the Plugins page for compatibility warnings
2. Test main functionality of each plugin
3. Check logs for errors
4. Update plugins if new versions are available
