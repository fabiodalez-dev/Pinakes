# Sistema CMS

Il CMS (Content Management System) permette di gestire i contenuti dell'homepage e le pagine statiche.

## Panoramica

Il sistema CMS gestisce due tipi di contenuti:

| Tipo | Tabella | Descrizione |
|------|---------|-------------|
| **Homepage** | `home_content` | Sezioni editabili della homepage |
| **Pagine statiche** | `cms_pages` | Pagine informative (Chi siamo, Contatti, etc.) |

## Accesso

- **Admin → CMS → Homepage**
- **Admin → CMS → [nome pagina]**

## Homepage Editor

### Sezioni Disponibili

L'homepage è composta da sezioni modulari, ciascuna con una `section_key`:

| Section Key | Descrizione | Campi Principali |
|-------------|-------------|------------------|
| `hero` | Banner principale | title, subtitle, button_text, button_link, background_image, SEO completo |
| `features_title` | Titolo sezione funzionalità | title, subtitle |
| `feature_1` - `feature_4` | 4 card funzionalità | title, subtitle, content (icona FontAwesome) |
| `latest_books_title` | Titolo ultimi arrivi | title, subtitle |
| `genre_carousel` | Carosello generi | title, subtitle |
| `text_content` | Blocco testo libero | title, content (HTML TinyMCE) |
| `cta` | Call to Action | title, subtitle, button_text, button_link |
| `events` | Sezione eventi | title, subtitle |

### Campi Hero

La sezione `hero` supporta tutti i campi SEO:

```
Campi Base:
├── title              # Titolo principale
├── subtitle           # Sottotitolo
├── button_text        # Testo pulsante
├── button_link        # URL pulsante
└── background_image   # Immagine sfondo

Campi SEO Base:
├── seo_title          # Title tag personalizzato
├── seo_description    # Meta description
├── seo_keywords       # Keywords (separati da virgola)
└── og_image           # Immagine Open Graph

Open Graph:
├── og_title           # Titolo OG
├── og_description     # Descrizione OG
├── og_type            # Tipo (default: website)
└── og_url             # URL canonico OG

Twitter Card:
├── twitter_card       # Tipo card (default: summary_large_image)
├── twitter_title      # Titolo Twitter
├── twitter_description # Descrizione Twitter
└── twitter_image      # Immagine Twitter
```

### Icone FontAwesome

Per le sezioni `feature_1` - `feature_4`, il campo `content` contiene la classe FontAwesome:

```
fas fa-book        # Icona libro
fas fa-users       # Icona utenti
fas fa-star        # Icona stella (default)
fas fa-calendar    # Icona calendario
```

**Validazione**: Solo pattern `fa[sbrldt]? fa-[nome]` sono accettati.

### Editor HTML (TinyMCE)

La sezione `text_content` utilizza TinyMCE per contenuti rich-text:
- HTML sanitizzato con whitelist (`HtmlHelper::sanitizeHtml()`)
- Tag permessi: `p, a, strong, em, ul, ol, li, h1-h6, br, img`
- Attributi: `href, src, alt, class`

## Pagine Statiche

### Pagine Predefinite

Il sistema supporta pagine con slug localizzati:

| Slug IT | Slug EN | Descrizione |
|---------|---------|-------------|
| `chi-siamo` | `about-us` | Chi siamo |
| `contatti` | `contact` | Contatti |
| `orari` | `hours` | Orari apertura |
| `regolamento` | `regulations` | Regolamento biblioteca |
| `cookie-policy` | `cookie-policy` | Cookie policy |
| `privacy-policy` | `privacy-policy` | Privacy policy |

### Campi Pagina

| Campo | Descrizione | Obbligatorio |
|-------|-------------|--------------|
| `slug` | URL della pagina | Sì |
| `locale` | Lingua (it_IT, en_US) | Sì |
| `title` | Titolo pagina | Sì |
| `content` | Contenuto HTML | No |
| `image` | Immagine principale | No |
| `meta_description` | SEO description | No |
| `is_active` | Visibile pubblicamente | Sì |

### Auto-Creazione Pagine

Se una pagina nota non esiste per il locale corrente, viene creata automaticamente con contenuto placeholder.

### Redirect Localizzati

Il sistema gestisce redirect 301 automatici per URL localizzati:
- `/about-us` con locale IT → redirect 301 a `/chi-siamo`
- `/chi-siamo` con locale EN → redirect 301 a `/about-us`

## Operazioni

### Modificare Homepage

1. Vai in **Admin → CMS → Homepage**
2. Modifica i campi delle sezioni desiderate
3. Clicca **Salva modifiche**

### Caricare Immagine Hero

1. Sezione **Hero**
2. Clicca **Scegli file** per l'immagine di sfondo
3. Formati supportati: JPG, PNG, WebP
4. Dimensione massima: 5 MB
5. Salva

**Sicurezza upload**:
- Validazione estensione
- Verifica MIME type con magic number
- Nome file random (`hero_bg_[random].ext`)
- Permessi file: 0644

### Riordinare Sezioni

Il sistema supporta drag-and-drop per riordinare le sezioni:

```
POST /admin/cms/home/reorder
Content-Type: application/json

{
  "order": [
    {"id": 1, "display_order": 0},
    {"id": 2, "display_order": 1},
    {"id": 3, "display_order": 2}
  ]
}
```

### Attivare/Disattivare Sezione

Toggle visibilità via AJAX:

```
POST /admin/cms/home/toggle-visibility
Content-Type: application/json

{
  "section_id": 5,
  "is_active": 0
}
```

### Modificare Pagina Statica

1. Vai in **Admin → CMS → [nome pagina]**
2. Modifica titolo, contenuto, SEO
3. Clicca **Salva**

### Upload Immagine in Pagina

L'editor TinyMCE supporta upload immagini:

```
POST /admin/cms/upload-image
Content-Type: multipart/form-data

file: [image file]
```

Risposta:
```json
{
  "url": "/uploads/cms/cms_abc123def456.jpg",
  "filename": "cms_abc123def456.jpg"
}
```

**Limiti**:
- Formati: JPG, PNG, GIF, WebP
- Dimensione massima: 10 MB
- Storage: `storage/uploads/cms/`

## Tabelle Database

### home_content

```sql
CREATE TABLE `home_content` (
  `id` int NOT NULL AUTO_INCREMENT,
  `section_key` varchar(50) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `subtitle` text,
  `content` text,
  `button_text` varchar(100) DEFAULT NULL,
  `button_link` varchar(255) DEFAULT NULL,
  `background_image` varchar(500) DEFAULT NULL,
  `seo_title` varchar(255) DEFAULT NULL,
  `seo_description` text,
  `seo_keywords` varchar(500) DEFAULT NULL,
  `og_image` varchar(500) DEFAULT NULL,
  `og_title` varchar(255) DEFAULT NULL,
  `og_description` text,
  `og_type` varchar(50) DEFAULT 'website',
  `og_url` varchar(500) DEFAULT NULL,
  `twitter_card` varchar(50) DEFAULT 'summary_large_image',
  `twitter_title` varchar(255) DEFAULT NULL,
  `twitter_description` text,
  `twitter_image` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `display_order` int DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `section_key` (`section_key`)
);
```

### cms_pages

```sql
CREATE TABLE `cms_pages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `slug` varchar(100) NOT NULL,
  `locale` varchar(10) NOT NULL DEFAULT 'en_US',
  `title` varchar(255) NOT NULL,
  `content` text,
  `image` varchar(500) DEFAULT NULL,
  `meta_description` text,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_cms_slug_locale` (`slug`, `locale`)
);
```

## Pattern UPSERT

Il controller utilizza il pattern UPSERT per salvare le sezioni homepage:

```sql
INSERT INTO home_content (section_key, title, subtitle, ...)
VALUES ('hero', ?, ?, ...)
ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    subtitle = VALUES(subtitle),
    ...
```

Questo garantisce:
- Creazione automatica se la sezione non esiste
- Aggiornamento se esiste già
- Nessun errore di duplicato

## Sicurezza

### Validazione Input

| Campo | Sanitizzazione |
|-------|----------------|
| Testo semplice | `strip_tags()` |
| HTML ricco | `HtmlHelper::sanitizeHtml()` whitelist |
| URL | Regex per URL relativi o assoluti validi |
| Icone FontAwesome | Regex `fa[sbrldt]? fa-[nome]` |

### Upload Immagini

1. Validazione estensione (whitelist)
2. Validazione size (max 5MB hero, 10MB pagine)
3. Verifica MIME con `finfo_file()` magic number
4. Nome file random con `random_bytes()`
5. Verifica path traversal
6. Permessi file sicuri (0644)

### CSRF

Tutte le operazioni POST richiedono token CSRF valido.

### Controllo Accesso

Solo utenti `admin` possono accedere al CMS.

## Risoluzione Problemi

### Sezione non visibile

Verifica:
1. `is_active = 1` nella sezione
2. La sezione ha contenuto (title o subtitle)
3. Il display_order è corretto

### Immagine non caricata

Possibili cause:
1. File troppo grande (max 5MB hero, 10MB pagine)
2. Formato non supportato
3. Permessi directory `storage/uploads/cms/`

### Pagina 404

Verifica:
1. `is_active = 1` per la pagina
2. Slug corretto per il locale corrente
3. Locale sessione vs locale pagina

### Contenuto HTML corrotto

Il sanitizer HTML rimuove tag non permessi. Usa solo:
- `p, a, strong, em, ul, ol, li`
- `h1, h2, h3, h4, h5, h6`
- `br, img`

---

## Domande Frequenti (FAQ)

### 1. Come nascondo una sezione dell'homepage senza eliminarla?

Puoi disattivare temporaneamente qualsiasi sezione:

**Da interfaccia:**
1. Vai in **Admin → CMS → Homepage**
2. Trova la sezione da nascondere
3. Clicca il toggle "Attivo" per disattivarla

**Via API (per sviluppatori):**
```
POST /admin/cms/home/toggle-visibility
{"section_id": 5, "is_active": 0}
```

La sezione rimane nel database con tutti i contenuti, semplicemente non viene renderizzata.

---

### 2. Quali icone FontAwesome posso usare nelle feature?

Le sezioni `feature_1` - `feature_4` accettano classi FontAwesome nel campo `content`.

**Formato:** `fa[tipo] fa-[nome]`

**Esempi validi:**
```
fas fa-book       # Libro (solid)
far fa-calendar   # Calendario (regular)
fab fa-github     # GitHub (brand)
fas fa-users      # Utenti
fas fa-star       # Stella
```

**Trova icone:** [fontawesome.com/icons](https://fontawesome.com/icons)

**Validazione:** Solo pattern `fa[sbrldt]? fa-[a-z-]+` sono accettati per sicurezza.

---

### 3. Come carico un'immagine di sfondo per l'hero?

1. Vai in **Admin → CMS → Homepage**
2. Sezione **Hero**
3. Clicca **Scegli file** accanto a "Immagine di sfondo"
4. Seleziona l'immagine (JPG, PNG, WebP)
5. Salva

**Specifiche consigliate:**
- Dimensioni: 1920x1080 px minimo
- Formato: JPG per foto, PNG per grafiche
- Dimensione max: 5 MB
- L'immagine viene salvata con nome random in `storage/uploads/cms/`

---

### 4. Come funziona il riordinamento delle sezioni homepage?

Le sezioni hanno un ordine di visualizzazione (`display_order`):

**Da interfaccia:**
1. Vai in **Admin → CMS → Homepage**
2. Trascina le sezioni nell'ordine desiderato (drag-and-drop)
3. L'ordine si salva automaticamente

**Manualmente (database):**
```sql
UPDATE home_content SET display_order = 1 WHERE section_key = 'hero';
UPDATE home_content SET display_order = 2 WHERE section_key = 'features_title';
```

---

### 5. Come creo una nuova pagina statica (es. "Regolamento")?

1. Vai in **Admin → CMS → Nuova Pagina**
2. Compila:
   - **Slug**: `regolamento` (URL: `/regolamento`)
   - **Titolo**: "Regolamento della Biblioteca"
   - **Contenuto**: testo con editor WYSIWYG
   - **Meta description**: per SEO
3. Attiva la pagina
4. Salva

**Slug localizzati:** Per inglese, crea una pagina con locale `en_US` e slug `regulations`. Il sistema gestirà i redirect automaticamente.

---

### 6. Perché alcuni tag HTML vengono rimossi dal contenuto?

Per sicurezza, il CMS usa una **whitelist HTML** che rimuove tag potenzialmente pericolosi.

**Tag permessi:**
- Testo: `p, a, strong, em, br`
- Liste: `ul, ol, li`
- Titoli: `h1, h2, h3, h4, h5, h6`
- Media: `img`

**Tag rimossi:**
- `script, iframe, object, embed` (rischio XSS)
- `style` (usa CSS inline)
- `form, input` (conflitto con form pagina)

**Attributi permessi:**
- `href`, `src`, `alt`, `class`

---

### 7. Come configuro i campi SEO dell'homepage?

La sezione **Hero** include tutti i campi SEO:

1. Vai in **Admin → CMS → Homepage → Hero**
2. Scorri fino a "Impostazioni SEO"
3. Compila:

| Campo | Uso |
|-------|-----|
| `seo_title` | Title tag (max 60 caratteri) |
| `seo_description` | Meta description (max 160 caratteri) |
| `seo_keywords` | Keywords separate da virgola |
| `og_image` | Immagine per condivisione social |

4. Salva

Questi valori vengono usati nel `<head>` della homepage.

---

### 8. Come gestisco le pagine in più lingue?

Ogni pagina statica è associata a un **locale**:

**Creare versione inglese:**
1. Vai in **Admin → CMS → Nuova Pagina**
2. Imposta **Locale**: `en_US`
3. Imposta **Slug** in inglese (es. `about-us`)
4. Scrivi il contenuto in inglese

**Redirect automatici:**
- Utente con locale IT visita `/about-us` → redirect 301 a `/chi-siamo`
- Utente con locale EN visita `/chi-siamo` → redirect 301 a `/about-us`

---

### 9. Posso inserire video YouTube nelle pagine?

L'editor non supporta direttamente iframe per sicurezza. Alternative:

**Opzione 1 - Link al video:**
```html
<a href="https://www.youtube.com/watch?v=ID">Guarda il video</a>
```

**Opzione 2 - Immagine con link:**
```html
<a href="https://www.youtube.com/watch?v=ID">
  <img src="https://img.youtube.com/vi/ID/maxresdefault.jpg" alt="Video">
</a>
```

**Opzione 3 - Plugin custom:**
Crea un plugin che registra un hook per permettere iframe da domini fidati.

---

### 10. Come faccio backup del contenuto CMS prima di modifiche importanti?

**Metodo 1 - Export database:**
```bash
mysqldump -u user -p database home_content cms_pages > cms_backup.sql
```

**Metodo 2 - Backup integrato:**
- Usa il sistema backup di Pinakes (**Admin → Backup**)
- Il backup include tutte le tabelle CMS

**Metodo 3 - Screenshot:**
Prima di modifiche importanti, fai screenshot delle pagine correnti.

**Ripristino:**
```bash
mysql -u user -p database < cms_backup.sql
```
