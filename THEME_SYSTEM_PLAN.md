# 🎨 PIANO SISTEMA TEMI PINAKES

**Data creazione**: 2025-11-18
**Branch**: `claude/add-theme-support-012NeU2gpaXU9dikUDcpRSNn`

---

## 📊 ANALISI COLORI E BOTTONI ESISTENTI

### **Colori Hardcoded da Sostituire**

| Colore | Codice Hex | Utilizzo | Variabile CSS |
|--------|-----------|----------|---------------|
| **Magenta Primario** | `#d70161` | Link, badge principale, accenti, feature icons | `var(--primary-color)` |
| **Magenta Bottoni** | `#d70262` | Bottoni CTA nelle card | `var(--button-color)` |
| **Magenta Hover** | `#b70154` | Hover bottoni CTA | `var(--button-hover)` |
| **Nero/Grigio** | `#111827` | Bottoni azioni principali, testi scuri | `var(--secondary-color)` |
| **Nero Hover** | `#000000` | Hover bottoni primary | `var(--secondary-hover)` |
| **Grigio Carousel** | `#c0c0c0` | Bottoni navigazione carousel | `var(--secondary-color)` con opacity |

### **Colori da NON Modificare (Fissi)**

| Colore | Codice Hex | Utilizzo | Motivo |
|--------|-----------|----------|--------|
| **Arancione** | `#f97316` | Badge "Coautore" | Distinguere ruoli |
| **Viola** | `#8b5cf6` | Badge "Traduttore" | Distinguere ruoli |
| **Verde** | `#10b981` | Status "Disponibile" | Semantico |
| **Rosso** | `#ef4444` | Status "In prestito", danger | Semantico |
| **Grigio Neutro** | `#f8fafc` | Background neutri | UI base |

---

## 🎯 MAPPATURA DETTAGLIATA BOTTONI

### **1. BOTTONI CTA (Call-to-Action) - Dentro Card**

**Contesto**: Card libri in catalogo, home, grids
**File**: `catalog-grid.php:58`, `home-books-grid.php:58`
**HTML**: `<a class="btn-cta btn-cta-sm">Dettagli</a>`
**CSS Attuale**:
```css
/* layout.php righe 416-474 */
.btn-cta {
    border: 1.5px solid #d70262;     /* ← HARDCODED */
    background: #d70262;             /* ← HARDCODED */
    color: #ffffff;
}
.btn-cta:hover {
    background: #b70154;             /* ← HARDCODED */
    border-color: #b70154;
}
```

**CSS Target**:
```css
.btn-cta {
    border: 1.5px solid var(--button-color);
    background: var(--button-color);
    color: var(--button-text-color);  /* ← IMPORTANTE */
}
.btn-cta:hover {
    background: var(--button-hover);
    border-color: var(--button-hover);
    color: var(--button-text-color);  /* ← IMPORTANTE */
}
```

**Outline Variant**:
```css
.btn-cta-outline {
    background: transparent;
    color: var(--button-color);       /* ← Testo colore bottone */
    border: 1.5px solid var(--button-color);
}
.btn-cta-outline:hover {
    background: var(--button-color);
    color: var(--button-text-color);  /* ← Testo bianco */
}
```

---

### **2. BOTTONI PRIMARY - Azioni Principali (Fuori Card)**

**Contesto**: Azioni principali scheda libro, modals
**File**: `book-detail.php:1444`
**HTML**: `<button class="btn btn-primary btn-lg">Richiedi Prestito</button>`
**CSS Attuale**:
```css
/* book-detail.php righe 676-699 */
.action-buttons .btn-primary {
    background: #111827;              /* ← HARDCODED */
    border-color: #111827;
    color: #ffffff;
}
.action-buttons .btn-primary:hover {
    background: #000000;              /* ← HARDCODED */
    border-color: #000000;
}
```

**CSS Target**:
```css
.btn-primary,
.action-buttons .btn-primary {
    background: var(--secondary-color);
    border-color: var(--secondary-color);
    color: #ffffff;                   /* ← Sempre bianco */
}
.btn-primary:hover,
.action-buttons .btn-primary:hover {
    background: var(--secondary-hover);
    border-color: var(--secondary-hover);
    color: #ffffff;
}
```

**Outline Variant**:
```css
.btn-outline-primary,
.action-buttons .btn-outline-primary {
    color: var(--secondary-color);    /* ← Testo secondary */
    border-color: var(--secondary-color);
    background: transparent;
}
.btn-outline-primary:hover,
.action-buttons .btn-outline-primary:hover {
    background: var(--secondary-color);
    border-color: var(--secondary-color);
    color: #ffffff;                   /* ← Testo bianco */
}
```

---

### **3. HEADER BUTTONS**

**Contesto**: Bottoni nel header frontend
**File**: `layout.php:1189-1206`
**HTML**: `<a class="btn btn-primary-header">`, `<a class="btn btn-outline-header">`
**CSS Attuale**:
```css
/* layout.php righe 389-411 */
.btn-primary-header {
    background: var(--primary-color);  /* ← GIÀ DINAMICO */
    border: 1px solid var(--primary-color);
}
.btn-primary-header:hover {
    background: var(--secondary-color); /* ← Cambia a secondary */
}

.btn-outline-header {
    background: transparent;
    border: 1px solid var(--border-color);
}
.btn-outline-header:hover {
    background: rgba(0,0,0,0.02);
}
```

**Note**: Questi sono già parzialmente dinamici, verificare coherenza.

---

### **4. BADGE RUOLO AUTORE**

**Contesto**: Badge ruolo nella scheda libro
**File**: `book-detail.php:548-573`
**CSS Attuale**:
```css
.role-principale {
    background: #d70161;              /* ← HARDCODED */
    color: #fff;
    border-color: #d70161;
}
.role-coautore {
    background: #f97316;              /* ← FISSO - non toccare */
    color: #fff;
}
.role-traduttore {
    background: #8b5cf6;              /* ← FISSO - non toccare */
    color: #fff;
}
```

**CSS Target**:
```css
.role-principale {
    background: var(--primary-color); /* ← DINAMICO */
    color: #fff;
    border-color: var(--primary-color);
}
/* Altri ruoli invariati */
```

---

### **5. SWEETALERT2 MODALS**

**Contesto**: Bottoni conferma nei popup
**File**: `book-detail.php:739-750`
**CSS Attuale**:
```css
.swal2-popup .swal2-confirm {
    background: #111827 !important;   /* ← HARDCODED */
    border: 1px solid #111827 !important;
    color: #ffffff !important;
}
.swal2-popup .swal2-confirm:hover {
    background: #000000 !important;   /* ← HARDCODED */
    border-color: #000000 !important;
}
```

**CSS Target**:
```css
.swal2-popup .swal2-confirm {
    background: var(--secondary-color) !important;
    border: 1px solid var(--secondary-color) !important;
    color: #ffffff !important;
}
.swal2-popup .swal2-confirm:hover {
    background: var(--secondary-hover) !important;
    border-color: var(--secondary-hover) !important;
}
```

---

### **6. CAROUSEL NAVIGATION**

**Contesto**: Bottoni prev/next carousel homepage
**File**: `home.php:494-527`
**CSS Attuale**:
```css
.carousel-nav-btn {
    background: #c0c0c0;              /* ← HARDCODED grigio */
    color: white;
}
.carousel-nav-btn:hover:not(:disabled) {
    background: #a0a0a0;
    opacity: 1;
}
```

**CSS Target**:
```css
.carousel-nav-btn {
    background: var(--secondary-color);
    color: white;
    opacity: 0.7;
}
.carousel-nav-btn:hover:not(:disabled) {
    background: var(--secondary-hover);
    opacity: 1;
}
```

---

### **7. EVENT BUTTONS**

**Contesto**: Bottoni eventi homepage
**File**: `home.php:693-809`
**CSS Attuale**:
```css
.home-events__all-link {
    border: 1px solid #111827;        /* ← HARDCODED */
    color: #111827;
}
.home-events__all-link:hover {
    background: #111827;
    color: #ffffff;
}

.event-card__button {
    border: 1px solid #111827;        /* ← HARDCODED */
    color: #111827;
}
.event-card__button:hover {
    background: #111827;
    color: #ffffff;
}
```

**CSS Target**:
```css
.home-events__all-link {
    border: 1px solid var(--secondary-color);
    color: var(--secondary-color);
}
.home-events__all-link:hover {
    background: var(--secondary-color);
    color: #ffffff;
}

.event-card__button {
    border: 1px solid var(--secondary-color);
    color: var(--secondary-color);
}
.event-card__button:hover {
    background: var(--secondary-color);
    color: #ffffff;
}
```

---

### **8. HERO SEARCH BUTTON**

**Contesto**: Bottone ricerca nella hero section
**File**: `home.php:290-307`
**CSS Attuale**:
```css
.hero-search-button {
    background: var(--primary-color);  /* ← GIÀ DINAMICO */
    color: white;
}
.hero-search-button:hover {
    background: var(--secondary-color);
}
```

**Note**: Già dinamico, OK.

---

### **9. FEATURE ICONS**

**Contesto**: Icone features homepage
**File**: `home.php:154-166`
**CSS Attuale**:
```css
.feature-icon {
    background: #d70161;              /* ← HARDCODED */
    color: white;
}
```

**CSS Target**:
```css
.feature-icon {
    background: var(--primary-color);
    color: white;
}
```

---

### **10. FILTER BUTTONS**

**Contesto**: Bottoni filtri catalogo
**File**: `catalog.php:1312, 1336, 1369`
**HTML**:
- `<button class="clear-all-btn">`
- `<button class="clear-filters-top-btn">`
- `<button class="btn-cta btn-cta-sm">` (empty state)

**Note**: I bottoni filtro usano già `.btn-cta`, quindi saranno coperti automaticamente.

---

## 🎨 CSS CUSTOM PROPERTIES - STRUTTURA COMPLETA

### **Variabili Root da Generare Dinamicamente**

```css
:root {
    /* ========================================
       COLORI TEMA CONFIGURABILI
       ======================================== */

    /* Colore primario - Link, accenti, badge principale */
    --primary-color: #d70161;          /* ← Da DB tema */
    --primary-hover: #b70154;          /* ← Generato: darken(primary, 10%) */
    --primary-focus: #a00149;          /* ← Generato: darken(primary, 15%) */

    /* Colore secondario - Bottoni azioni principali, testi scuri */
    --secondary-color: #111827;        /* ← Da DB tema */
    --secondary-hover: #000000;        /* ← Generato: darken(secondary, 10%) */

    /* Colore bottoni CTA - Bottoni nelle card */
    --button-color: #d70262;           /* ← Da DB tema */
    --button-text-color: #ffffff;      /* ← Da DB tema */
    --button-hover: #b70154;           /* ← Generato: darken(button, 10%) */

    /* ========================================
       COLORI SISTEMA (NON MODIFICABILI)
       ======================================== */

    --accent-color: #f1f5f9;
    --text-color: #0f172a;
    --text-light: #64748b;
    --text-muted: #9ca3af;
    --white: #ffffff;
    --light-bg: #f8fafc;
    --border-color: #e2e8f0;
    --danger-color: #ef4444;
    --success-color: #10b981;
    --warning-color: #f59e0b;

    /* Badge ruoli NON modificabili */
    --role-coautore: #f97316;
    --role-traduttore: #8b5cf6;
}
```

### **Generazione Dinamica in PHP**

```php
<?php
// In app/Views/frontend/layout.php (e layout.php admin)

$themeManager = $container->get('ThemeManager');
$colorizer = $container->get('ThemeColorizer');
$activeTheme = $themeManager->getActiveTheme();

if ($activeTheme) {
    $settings = json_decode($activeTheme['settings'], true);
    $colors = $settings['colors'] ?? [];
} else {
    $colors = [];
}

// Colori con fallback ai valori attuali
$primaryColor = $colors['primary'] ?? '#d70161';
$secondaryColor = $colors['secondary'] ?? '#111827';
$buttonColor = $colors['button'] ?? '#d70262';
$buttonTextColor = $colors['button_text'] ?? '#ffffff';

// Generazione varianti automatiche
$primaryHover = $colorizer->darken($primaryColor, 10);
$primaryFocus = $colorizer->darken($primaryColor, 15);
$secondaryHover = $colorizer->darken($secondaryColor, 10);
$buttonHover = $colorizer->darken($buttonColor, 10);
?>

<style>
:root {
    /* Colori tema configurabili */
    --primary-color: <?= htmlspecialchars($primaryColor) ?>;
    --primary-hover: <?= htmlspecialchars($primaryHover) ?>;
    --primary-focus: <?= htmlspecialchars($primaryFocus) ?>;

    --secondary-color: <?= htmlspecialchars($secondaryColor) ?>;
    --secondary-hover: <?= htmlspecialchars($secondaryHover) ?>;

    --button-color: <?= htmlspecialchars($buttonColor) ?>;
    --button-text-color: <?= htmlspecialchars($buttonTextColor) ?>;
    --button-hover: <?= htmlspecialchars($buttonHover) ?>;

    /* Altri colori invariati */
    --accent-color: #f1f5f9;
    /* ... */
}
</style>
```

---

## 🗄️ DATABASE SCHEMA

### **Tabella `themes`**

```sql
CREATE TABLE themes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'Nome visualizzato del tema',
    slug VARCHAR(100) NOT NULL UNIQUE COMMENT 'Identificatore univoco',
    version VARCHAR(50) DEFAULT '1.0.0',
    author VARCHAR(255) DEFAULT 'Admin',
    description TEXT COMMENT 'Descrizione del tema',
    active TINYINT(1) DEFAULT 0 COMMENT '1 = tema attivo, 0 = disattivato',
    settings JSON COMMENT 'Impostazioni tema (colori, opzioni)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_active (active),
    INDEX idx_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Gestione temi applicazione';
```

### **Struttura JSON `settings` - COMPLETA**

```json
{
  "colors": {
    "primary": "#d70161",
    "secondary": "#111827",
    "button": "#d70262",
    "button_text": "#ffffff"
  },
  "typography": {
    "font_family": "system-ui, sans-serif",
    "font_size_base": "16px"
  },
  "logo": {
    "url": "",
    "width": "auto",
    "height": "50px"
  },
  "advanced": {
    "custom_css": "",
    "custom_js": ""
  }
}
```

### **Inserimento Tema Default**

```sql
INSERT INTO themes (name, slug, version, author, description, active, settings)
VALUES (
    'Pinakes Classic',
    'default',
    '1.0.0',
    'Pinakes Team',
    'Tema predefinito dell\'applicazione Pinakes con i colori originali',
    1,
    JSON_OBJECT(
        'colors', JSON_OBJECT(
            'primary', '#d70161',
            'secondary', '#111827',
            'button', '#d70262',
            'button_text', '#ffffff'
        ),
        'options', JSON_OBJECT(
            'custom_css', '',
            'custom_js', ''
        )
    )
);
```

---

## 📁 STRUTTURA FILE

### **File da Creare**

```
app/
├── Support/
│   ├── ThemeManager.php          ← Gestione temi (attivazione, lettura)
│   └── ThemeColorizer.php        ← Generazione varianti colori, contrast checker
├── Controllers/
│   └── ThemeController.php       ← Controller admin temi
└── Views/
    └── admin/
        ├── themes.php            ← Lista temi installati
        └── theme-customize.php   ← Personalizzazione colori tema

installer/
└── database/
    └── schema.sql                ← Aggiungere CREATE TABLE themes + INSERT default
```

### **File da Modificare**

```
app/
├── Views/
│   ├── frontend/
│   │   ├── layout.php            ← Iniettare CSS variables dinamiche
│   │   ├── home.php              ← Sostituire hardcoded colors
│   │   └── book-detail.php       ← Sostituire hardcoded colors
│   └── layout.php                ← Iniettare CSS variables + menu temi
├── Routes/
│   └── web.php                   ← Aggiungere routes /admin/themes
config/
└── container.php                 ← Registrare ThemeManager e ThemeColorizer
```

---

## 🔧 IMPLEMENTAZIONE CLASSI

### **ThemeManager.php**

```php
namespace App\Support;

class ThemeManager
{
    private $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Ottiene il tema attivo
     * @return array|null
     */
    public function getActiveTheme(): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM themes WHERE active = 1 LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        $theme = $result->fetch_assoc();
        $stmt->close();

        return $theme ?: null;
    }

    /**
     * Ottiene tutti i temi installati
     * @return array
     */
    public function getAllThemes(): array
    {
        $result = $this->db->query("SELECT * FROM themes ORDER BY active DESC, name ASC");
        $themes = [];

        while ($row = $result->fetch_assoc()) {
            $themes[] = $row;
        }

        return $themes;
    }

    /**
     * Attiva un tema (disattiva gli altri)
     * @param int $themeId
     * @return bool
     */
    public function activateTheme(int $themeId): bool
    {
        $this->db->begin_transaction();

        try {
            // Disattiva tutti i temi
            $this->db->query("UPDATE themes SET active = 0");

            // Attiva il tema selezionato
            $stmt = $this->db->prepare("UPDATE themes SET active = 1 WHERE id = ?");
            $stmt->bind_param('i', $themeId);
            $stmt->execute();
            $stmt->close();

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollback();
            error_log("Error activating theme: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Aggiorna i colori di un tema
     * @param int $themeId
     * @param array $colors ['primary' => '#xxx', 'secondary' => '#xxx', ...]
     * @return bool
     */
    public function updateThemeColors(int $themeId, array $colors): bool
    {
        // Ottieni settings attuali
        $stmt = $this->db->prepare("SELECT settings FROM themes WHERE id = ?");
        $stmt->bind_param('i', $themeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $theme = $result->fetch_assoc();
        $stmt->close();

        if (!$theme) {
            return false;
        }

        $settings = json_decode($theme['settings'], true) ?? [];
        $settings['colors'] = $colors;

        $settingsJson = json_encode($settings, JSON_UNESCAPED_UNICODE);

        $stmt = $this->db->prepare("UPDATE themes SET settings = ? WHERE id = ?");
        $stmt->bind_param('si', $settingsJson, $themeId);
        $success = $stmt->execute();
        $stmt->close();

        return $success;
    }

    /**
     * Ripristina i colori default di un tema
     * @param int $themeId
     * @return bool
     */
    public function resetThemeColors(int $themeId): bool
    {
        $defaultColors = [
            'primary' => '#d70161',
            'secondary' => '#111827',
            'button' => '#d70262',
            'button_text' => '#ffffff'
        ];

        return $this->updateThemeColors($themeId, $defaultColors);
    }
}
```

### **ThemeColorizer.php**

```php
namespace App\Support;

class ThemeColorizer
{
    /**
     * Scurisce un colore HEX del % specificato
     * @param string $hex Colore esadecimale (es. '#d70161')
     * @param int $percent Percentuale di scurimento (0-100)
     * @return string Colore scurito in formato #rrggbb
     */
    public function darken(string $hex, int $percent): string
    {
        $hex = ltrim($hex, '#');

        // Converti in RGB
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        $rgb = array_map('hexdec', str_split($hex, 2));

        // Scurisci ogni componente
        foreach ($rgb as &$value) {
            $value = max(0, min(255, $value - ($value * $percent / 100)));
        }

        // Ritorna in formato HEX
        return '#' . implode('', array_map(function($v) {
            return str_pad(dechex((int)round($v)), 2, '0', STR_PAD_LEFT);
        }, $rgb));
    }

    /**
     * Calcola il rapporto di contrasto WCAG tra due colori
     * @param string $foreground Colore primo piano
     * @param string $background Colore sfondo
     * @return float Rapporto di contrasto (1-21)
     */
    public function getContrastRatio(string $foreground, string $background): float
    {
        $l1 = $this->getLuminance($foreground);
        $l2 = $this->getLuminance($background);

        $lighter = max($l1, $l2);
        $darker = min($l1, $l2);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Verifica se il contrasto rispetta WCAG AA (4.5:1 per testo normale)
     * @param string $foreground
     * @param string $background
     * @return bool
     */
    public function isAccessibleAA(string $foreground, string $background): bool
    {
        return $this->getContrastRatio($foreground, $background) >= 4.5;
    }

    /**
     * Verifica se il contrasto rispetta WCAG AAA (7:1 per testo normale)
     * @param string $foreground
     * @param string $background
     * @return bool
     */
    public function isAccessibleAAA(string $foreground, string $background): bool
    {
        return $this->getContrastRatio($foreground, $background) >= 7.0;
    }

    /**
     * Calcola la luminanza relativa di un colore (formula WCAG)
     * @param string $hex
     * @return float Luminanza (0-1)
     */
    private function getLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        $rgb = array_map('hexdec', str_split($hex, 2));

        // Normalizza e applica formula WCAG
        $rgb = array_map(function($val) {
            $val = $val / 255;
            return $val <= 0.03928
                ? $val / 12.92
                : pow(($val + 0.055) / 1.055, 2.4);
        }, $rgb);

        // Luminanza relativa
        return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
    }

    /**
     * Valida un colore esadecimale
     * @param string $hex
     * @return bool
     */
    public function isValidHex(string $hex): bool
    {
        return (bool) preg_match('/^#?([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $hex);
    }

    /**
     * Normalizza un colore esadecimale (#rrggbb)
     * @param string $hex
     * @return string
     */
    public function normalizeHex(string $hex): string
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#' . strtolower($hex);
    }
}
```

---

## 🎯 TODO IMPLEMENTAZIONE

### **FASE 1: Database e Classi Base**
- [ ] Aggiungere tabella `themes` a `installer/database/schema.sql`
- [ ] Inserire tema default nel database
- [ ] Creare `app/Support/ThemeManager.php`
- [ ] Creare `app/Support/ThemeColorizer.php`
- [ ] Registrare nel DI container (`config/container.php`)

### **FASE 2: Sostituzione Colori Hardcoded**
- [ ] `app/Views/frontend/layout.php` - Generare CSS variables dinamiche
- [ ] `app/Views/frontend/layout.php` - Sostituire `.btn-cta` hardcoded
- [ ] `app/Views/frontend/layout.php` - Sostituire `.btn-primary` hardcoded
- [ ] `app/Views/frontend/home.php` - Sostituire `.feature-icon` hardcoded
- [ ] `app/Views/frontend/home.php` - Sostituire `.carousel-nav-btn` hardcoded
- [ ] `app/Views/frontend/home.php` - Sostituire event buttons hardcoded
- [ ] `app/Views/frontend/book-detail.php` - Sostituire `.role-principale` hardcoded
- [ ] `app/Views/frontend/book-detail.php` - Sostituire `.action-buttons` hardcoded
- [ ] `app/Views/frontend/book-detail.php` - Sostituire SweetAlert2 hardcoded
- [ ] `app/Views/layout.php` (admin) - Generare CSS variables dinamiche

### **FASE 3: Area Admin Temi**
- [ ] Creare `app/Controllers/ThemeController.php`
- [ ] Creare `app/Views/admin/themes.php` (lista temi)
- [ ] Creare `app/Views/admin/theme-customize.php` (customizer colori)
- [ ] Aggiungere routes in `app/Routes/web.php`
- [ ] Aggiungere voce menu "Temi" in `app/Views/layout.php`

### **FASE 4: Testing e Validazione**
- [x] Testare cambio colori e preview live
- [x] Verificare contrasto WCAG
- [x] Testare fallback se tema non configurato
- [ ] Verificare compatibilità con tutti i browser

### **INSTALLER UPDATES (IMPORTANTE)**

**Database Schema Aggiornato:**
- ✅ Tabella `themes` aggiunta a `installer/database/schema.sql`
- ✅ Record default "Pinakes Classic" inserito automaticamente
- ✅ `EXPECTED_TABLES` in `installer/classes/Installer.php` aggiornato: 39 → 40 tabelle

**Per Installazioni Esistenti:**
Se l'applicazione è già installata, eseguire questa query SQL manualmente:

```sql
-- Crea tabella themes
CREATE TABLE `themes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Theme display name',
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Unique theme identifier',
  `version` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '1.0.0' COMMENT 'Theme version',
  `author` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT 'Admin' COMMENT 'Theme author',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT 'Theme description',
  `active` tinyint(1) DEFAULT '0' COMMENT '1 = active theme, 0 = inactive',
  `settings` json DEFAULT NULL COMMENT 'Theme settings (colors, typography, logo, advanced)',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_active` (`active`),
  KEY `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Theme management system';

-- Inserisci tema default
INSERT INTO `themes` (`name`, `slug`, `version`, `author`, `description`, `active`, `settings`) VALUES
('Pinakes Classic', 'default', '1.0.0', 'Pinakes Team', 'Tema predefinito dell\'applicazione Pinakes con i colori originali', 1, '{\"colors\": {\"primary\": \"#d70161\", \"secondary\": \"#111827\", \"button\": \"#d70262\", \"button_text\": \"#ffffff\"}, \"typography\": {\"font_family\": \"system-ui, sans-serif\", \"font_size_base\": \"16px\"}, \"logo\": {\"url\": \"\", \"width\": \"auto\", \"height\": \"50px\"}, \"advanced\": {\"custom_css\": \"\", \"custom_js\": \"\"}}');
```

---

## 🎨 DESIGN AREA ADMIN TEMI

### **Stile Coerente con Admin Esistente**

Basato su `plugins.php` e `settings.php`, l'area temi deve usare:

**Card System**:
```html
<div class="bg-white rounded-xl shadow-sm border border-gray-200">
  <div class="p-6 border-b border-gray-200">
    <h2 class="text-lg font-semibold text-gray-900">Titolo</h2>
  </div>
  <div class="p-6">
    <!-- Content -->
  </div>
</div>
```

**Bottoni Primari**:
```html
<button class="inline-flex items-center px-6 py-3 bg-black text-white rounded-xl hover:bg-gray-800 transition-all duration-200 shadow-md hover:shadow-lg">
  <i class="fas fa-icon mr-2"></i>
  Testo
</button>
```

**Stats Cards** (3 colonne):
```html
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <div class="flex items-center justify-between">
      <div>
        <p class="text-sm font-medium text-gray-600">Label</p>
        <p class="text-3xl font-bold text-gray-900 mt-2">Valore</p>
      </div>
      <div class="w-12 h-12 bg-gray-100 rounded-xl flex items-center justify-center">
        <i class="fas fa-icon text-gray-600 text-xl"></i>
      </div>
    </div>
  </div>
</div>
```

**Form Inputs**:
```html
<div>
  <label class="block text-sm font-medium text-gray-700 mb-2">Label</label>
  <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
</div>
```

**Color Picker Custom**:
```html
<div class="flex gap-3 items-center">
  <input type="color" class="h-12 w-20 rounded-lg cursor-pointer border-2 border-gray-300">
  <input type="text" readonly class="flex-1 px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
</div>
```

---

## 📋 IMPOSTAZIONI TEMA COMPLETE

### **Sezioni Customizer**

#### **1. Colori (Tab Principale)**
- Colore Primario (primary)
- Colore Secondario (secondary)
- Colore Bottoni CTA (button)
- Colore Testo Bottoni (button_text)
- **Auto-calcolo**: Se `button_text` è vuoto, calcolare automaticamente bianco/nero in base al contrasto

#### **2. Tipografia (Opzionale - Futura implementazione)**
- Font Family (select tra Google Fonts)
- Font Size Base

#### **3. Logo (Opzionale)**
- Upload logo personalizzato
- Dimensioni logo (width/height)
- Posizione logo

#### **4. Advanced (Per utenti avanzati)**
- Custom CSS (textarea)
- Custom JS (textarea)
- Note sicurezza: sanitizzare input

### **Validazione Colori**

**Regole**:
1. Tutti i colori devono essere HEX validi (#rrggbb o #rgb)
2. Contrasto bottoni MINIMO 3:1 (WCAG AA Large Text)
3. Contrasto consigliato 4.5:1 (WCAG AA Normal Text)
4. Mostrare warning se contrasto < 4.5:1
5. Bloccare salvataggio se contrasto < 3:1

**Auto-detect Text Color**:
```php
public function getOptimalTextColor(string $backgroundColor): string
{
    $luminance = $this->getLuminance($backgroundColor);

    // Se lo sfondo è chiaro, usa testo scuro
    // Se lo sfondo è scuro, usa testo chiaro
    return $luminance > 0.5 ? '#000000' : '#ffffff';
}
```

---

## 📝 NOTE IMPORTANTI

1. **Testi Bottoni - Auto-Contrasto**:
   - Se `button_text_color` non è specificato, calcolare automaticamente tramite `getOptimalTextColor()`
   - Bottoni CTA: usano `var(--button-text-color)` configurabile o auto-calcolato
   - Bottoni outline: il testo cambia da `var(--button-color)` a `var(--button-text-color)` on hover
   - Verificare sempre contrasto WCAG AA (4.5:1)

2. **Fallback**: Se il sistema temi fallisce, usare sempre i colori attuali hardcoded come fallback

3. **Non Unificare Tutto**: Mantenere la distinzione logica tra:
   - Bottoni CTA (nelle card) → `--button-color`
   - Bottoni azioni principali → `--secondary-color`
   - Link e accenti → `--primary-color`

4. **Colori Semantici**: Non rendere configurabili i colori con significato semantico (verde=disponibile, rosso=errore)

5. **Admin Design**: Usare lo stesso stile di `plugins.php` per coerenza visiva:
   - Card con rounded-xl e border gray
   - Bottoni neri (bg-black) per azioni primarie
   - Icone FontAwesome con colori contestuali
   - Grid responsive con Tailwind

---

**Ultimo aggiornamento**: 2025-11-18
