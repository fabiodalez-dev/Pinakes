# Plugin System

Pinakes supports plugins to extend functionality.

## Bundled Plugins

Bundled plugins ship with Pinakes and are updated automatically by the
auto-update mechanism. Complete list as of v0.7.7 (16 plugins):

### Metadata scraping & enrichment

| Plugin | Version | Default | Description |
|--------|---------|---------|-------------|
| Open Library | 1.0.1 | Active | Book metadata scraping (ISBN/EAN) via Open Library + Google Books fallback |
| API Book Scraper | 1.1.1 | Active | Scraping via API key authentication (Alma, ExLibris, custom services) |
| Discogs Music Scraper | 1.1.0 | **Inactive** | Discogs + MusicBrainz + Deezer music metadata (CD, vinyl, cassette, Cat# "CDP 7912682") |
| Deezer Music Search | 1.0.0 | **Inactive** | HD cover art + tracklist enrichment from Deezer |
| MusicBrainz | 1.0.0 | **Inactive** | MusicBrainz + Cover Art Archive metadata (barcode fallback) |
| GoodLib — External Sources | 1.0.0 | **Inactive** | Clickable badges to search Anna's Archive, Z-Library, Project Gutenberg |
| VIAF Authority Control | 1.1.0 | **Inactive** | Link authors to VIAF/ISNI with confidence scoring and W3C Reconciliation API |

### Interoperability protocols

| Plugin | Version | Default | Description |
|--------|---------|---------|-------------|
| Z39 Server | 1.2.3 | Active | SRU 1.2 server + Z39.50/SRU client for Italian SBN, French **BNF** (UNIMARC, v0.7.6+), and any standard catalogue with Dewey extraction |
| OAI-PMH Server | 1.0.0 | **Inactive** | OAI-PMH 2.0 data provider for books + archives. Formats: `oai_dc`, `marc21`, `mods`, `mag` (2.0.1), `unimarc`. Harvest by Internet Culturale (ICCU), Europeana, DPLA |
| NCIP 2.0 Server | 1.0.0 | **Inactive** | NISO Circulation Interchange Protocol — self-service kiosks, partner ILS, library networks |
| BIBFRAME 2.0 Linked Data | 1.0.0 | **Inactive** | Exposes book catalogue as BIBFRAME 2.0 JSON-LD / Turtle (Library of Congress transition from MARC) |
| OpenURL Resolver | 1.0.0 | **Inactive** | Z39.88-2004 resolver + COinS embedded. Compatible with Zotero, Mendeley, EndNote |
| ResourceSync | 1.0.0 | **Inactive** | ANSI/NISO Z39.99-2014 protocol for bulk catalogue sync with harvester partners |

### Cataloging & specialised collections

| Plugin | Version | Default | Description |
|--------|---------|---------|-------------|
| Dewey Editor | 1.0.1 | Active | Dewey classification editor — add/modify/import/export JSON |
| Digital Library | 1.3.0 | Active | eBook (PDF/ePub) + audiobook management with Green Audio Player and inline PDF viewer |
| Archives (ISAD(G) / ISAAR(CPF) / RiC-O) | 1.3.0 | **Inactive** | Archival material: ISAD(G), ISAAR(CPF), MARCXML / EAD3 / METS / UNIMARC / Dublin Core export, IIIF Presentation 3.0 (v0.7.6+), **RiC-O JSON-LD** (v0.7.7+), AtoM area labels, ARK identifiers — see [Archives](/en/guide/archives.md) |

Plugins marked **Inactive** ship disabled because they depend on external
network services, an additional DB schema (like `archives`), or expose
public endpoints the administrator must consciously opt into. Enable them
under **Administration → Plugins** when you need them.

## Premium Plugin

| Plugin | Version | Distribution |
|--------|---------|--------------|
| Scraping Pro | 1.4.2 | Separate package `scraping-pro-vX.Y.Z.zip` (not bundled in the free ZIP) |

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
