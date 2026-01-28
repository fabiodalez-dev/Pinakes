# CMS System

The CMS (Content Management System) allows you to manage homepage content and static pages.

## Overview

The CMS system manages two types of content:

| Type | Table | Description |
|------|-------|-------------|
| **Homepage** | `home_content` | Editable homepage sections |
| **Static pages** | `cms_pages` | Informational pages (About Us, Contact, etc.) |

## Access

- **Admin → CMS → Homepage**
- **Admin → CMS → [page name]**

## Homepage Editor

### Available Sections

The homepage is composed of modular sections, each with a `section_key`:

| Section Key | Description | Main Fields |
|-------------|-------------|-------------|
| `hero` | Main banner | title, subtitle, button_text, button_link, background_image, full SEO |
| `features_title` | Features section title | title, subtitle |
| `feature_1` - `feature_4` | 4 feature cards | title, subtitle, content (FontAwesome icon) |
| `latest_books_title` | New arrivals title | title, subtitle |
| `genre_carousel` | Genre carousel | title, subtitle |
| `text_content` | Free text block | title, content (HTML TinyMCE) |
| `cta` | Call to Action | title, subtitle, button_text, button_link |
| `events` | Events section | title, subtitle |

### Hero Fields

The `hero` section supports all SEO fields:

```
Base Fields:
├── title              # Main title
├── subtitle           # Subtitle
├── button_text        # Button text
├── button_link        # Button URL
└── background_image   # Background image

Base SEO Fields:
├── seo_title          # Custom title tag
├── seo_description    # Meta description
├── seo_keywords       # Keywords (comma-separated)
└── og_image           # Open Graph image

Open Graph:
├── og_title           # OG title
├── og_description     # OG description
├── og_type            # Type (default: website)
└── og_url             # Canonical OG URL

Twitter Card:
├── twitter_card       # Card type (default: summary_large_image)
├── twitter_title      # Twitter title
├── twitter_description # Twitter description
└── twitter_image      # Twitter image
```

### FontAwesome Icons

For sections `feature_1` - `feature_4`, the `content` field contains the FontAwesome class:

```
fas fa-book        # Book icon
fas fa-users       # Users icon
fas fa-star        # Star icon (default)
fas fa-calendar    # Calendar icon
```

**Validation**: Only patterns `fa[sbrldt]? fa-[name]` are accepted.

### HTML Editor (TinyMCE)

The `text_content` section uses TinyMCE for rich-text content:
- HTML sanitized with whitelist (`HtmlHelper::sanitizeHtml()`)
- Allowed tags: `p, a, strong, em, ul, ol, li, h1-h6, br, img`
- Attributes: `href, src, alt, class`

## Static Pages

### Predefined Pages

The system supports pages with localized slugs:

| Slug IT | Slug EN | Description |
|---------|---------|-------------|
| `chi-siamo` | `about-us` | About us |
| `contatti` | `contact` | Contact |
| `orari` | `hours` | Opening hours |
| `regolamento` | `regulations` | Library regulations |
| `cookie-policy` | `cookie-policy` | Cookie policy |
| `privacy-policy` | `privacy-policy` | Privacy policy |

### Page Fields

| Field | Description | Required |
|-------|-------------|----------|
| `slug` | Page URL | Yes |
| `locale` | Language (it_IT, en_US) | Yes |
| `title` | Page title | Yes |
| `content` | HTML content | No |
| `image` | Main image | No |
| `meta_description` | SEO description | No |
| `is_active` | Publicly visible | Yes |

### Auto-Creation of Pages

If a known page doesn't exist for the current locale, it's automatically created with placeholder content.

### Localized Redirects

The system handles automatic 301 redirects for localized URLs:
- `/about-us` with IT locale → redirect 301 to `/chi-siamo`
- `/chi-siamo` with EN locale → redirect 301 to `/about-us`

## Operations

### Modify Homepage

1. Go to **Admin → CMS → Homepage**
2. Edit the fields of desired sections
3. Click **Save changes**

### Upload Hero Image

1. **Hero** section
2. Click **Choose file** for background image
3. Supported formats: JPG, PNG, WebP
4. Maximum size: 5 MB
5. Save

**Upload security**:
- Extension validation
- MIME type verification with magic number
- Random filename (`hero_bg_[random].ext`)
- File permissions: 0644

### Reorder Sections

The system supports drag-and-drop to reorder sections:

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

### Enable/Disable Section

Toggle visibility via AJAX:

```
POST /admin/cms/home/toggle-visibility
Content-Type: application/json

{
  "section_id": 5,
  "is_active": 0
}
```

### Edit Static Page

1. Go to **Admin → CMS → [page name]**
2. Edit title, content, SEO
3. Click **Save**

### Upload Image in Page

The TinyMCE editor supports image uploads:

```
POST /admin/cms/upload-image
Content-Type: multipart/form-data

file: [image file]
```

Response:
```json
{
  "url": "/uploads/cms/cms_abc123def456.jpg",
  "filename": "cms_abc123def456.jpg"
}
```

**Limits**:
- Formats: JPG, PNG, GIF, WebP
- Maximum size: 10 MB
- Storage: `storage/uploads/cms/`

## Database Tables

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

## UPSERT Pattern

The controller uses the UPSERT pattern to save homepage sections:

```sql
INSERT INTO home_content (section_key, title, subtitle, ...)
VALUES ('hero', ?, ?, ...)
ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    subtitle = VALUES(subtitle),
    ...
```

This guarantees:
- Automatic creation if section doesn't exist
- Update if it already exists
- No duplicate errors

## Security

### Input Validation

| Field | Sanitization |
|-------|--------------|
| Plain text | `strip_tags()` |
| Rich HTML | `HtmlHelper::sanitizeHtml()` whitelist |
| URL | Regex for valid relative or absolute URLs |
| FontAwesome icons | Regex `fa[sbrldt]? fa-[name]` |

### Image Uploads

1. Extension validation (whitelist)
2. Size validation (max 5MB hero, 10MB pages)
3. MIME verification with `finfo_file()` magic number
4. Random filename with `random_bytes()`
5. Path traversal verification
6. Secure file permissions (0644)

### CSRF

All POST operations require a valid CSRF token.

### Access Control

Only `admin` users can access the CMS.

## Troubleshooting

### Section not visible

Verify:
1. `is_active = 1` for the section
2. The section has content (title or subtitle)
3. The display_order is correct

### Image not uploaded

Possible causes:
1. File too large (max 5MB hero, 10MB pages)
2. Unsupported format
3. Directory permissions `storage/uploads/cms/`

### Page 404

Verify:
1. `is_active = 1` for the page
2. Correct slug for current locale
3. Session locale vs page locale

### Corrupted HTML content

The HTML sanitizer removes disallowed tags. Use only:
- `p, a, strong, em, ul, ol, li`
- `h1, h2, h3, h4, h5, h6`
- `br, img`

---

## Frequently Asked Questions (FAQ)

### 1. How do I hide a homepage section without deleting it?

You can temporarily disable any section:

**From interface:**
1. Go to **Admin → CMS → Homepage**
2. Find the section to hide
3. Click the "Active" toggle to disable it

**Via API (for developers):**
```
POST /admin/cms/home/toggle-visibility
{"section_id": 5, "is_active": 0}
```

The section remains in the database with all content, it simply isn't rendered.

---

### 2. Which FontAwesome icons can I use in features?

Sections `feature_1` - `feature_4` accept FontAwesome classes in the `content` field.

**Format:** `fa[type] fa-[name]`

**Valid examples:**
```
fas fa-book       # Book (solid)
far fa-calendar   # Calendar (regular)
fab fa-github     # GitHub (brand)
fas fa-users      # Users
fas fa-star       # Star
```

**Find icons:** [fontawesome.com/icons](https://fontawesome.com/icons)

**Validation:** Only patterns `fa[sbrldt]? fa-[a-z-]+` are accepted for security.

---

### 3. How do I upload a background image for the hero?

1. Go to **Admin → CMS → Homepage**
2. **Hero** section
3. Click **Choose file** next to "Background image"
4. Select the image (JPG, PNG, WebP)
5. Save

**Recommended specifications:**
- Dimensions: 1920x1080 px minimum
- Format: JPG for photos, PNG for graphics
- Max size: 5 MB
- The image is saved with random name in `storage/uploads/cms/`

---

### 4. How does homepage section reordering work?

Sections have a display order (`display_order`):

**From interface:**
1. Go to **Admin → CMS → Homepage**
2. Drag sections to desired order (drag-and-drop)
3. Order saves automatically

**Manually (database):**
```sql
UPDATE home_content SET display_order = 1 WHERE section_key = 'hero';
UPDATE home_content SET display_order = 2 WHERE section_key = 'features_title';
```

---

### 5. How do I create a new static page (e.g., "Regulations")?

1. Go to **Admin → CMS → New Page**
2. Fill in:
   - **Slug**: `regulations` (URL: `/regulations`)
   - **Title**: "Library Regulations"
   - **Content**: text with WYSIWYG editor
   - **Meta description**: for SEO
3. Activate the page
4. Save

**Localized slugs:** For Italian, create a page with locale `it_IT` and slug `regolamento`. The system will handle redirects automatically.

---

### 6. Why are some HTML tags removed from content?

For security, the CMS uses an **HTML whitelist** that removes potentially dangerous tags.

**Allowed tags:**
- Text: `p, a, strong, em, br`
- Lists: `ul, ol, li`
- Headings: `h1, h2, h3, h4, h5, h6`
- Media: `img`

**Removed tags:**
- `script, iframe, object, embed` (XSS risk)
- `style` (use inline CSS)
- `form, input` (conflict with page forms)

**Allowed attributes:**
- `href`, `src`, `alt`, `class`

---

### 7. How do I configure SEO fields for the homepage?

The **Hero** section includes all SEO fields:

1. Go to **Admin → CMS → Homepage → Hero**
2. Scroll to "SEO Settings"
3. Fill in:

| Field | Use |
|-------|-----|
| `seo_title` | Title tag (max 60 characters) |
| `seo_description` | Meta description (max 160 characters) |
| `seo_keywords` | Comma-separated keywords |
| `og_image` | Image for social sharing |

4. Save

These values are used in the homepage `<head>`.

---

### 8. How do I manage pages in multiple languages?

Each static page is associated with a **locale**:

**Create English version:**
1. Go to **Admin → CMS → New Page**
2. Set **Locale**: `en_US`
3. Set **Slug** in English (e.g., `about-us`)
4. Write content in English

**Automatic redirects:**
- User with IT locale visits `/about-us` → redirect 301 to `/chi-siamo`
- User with EN locale visits `/chi-siamo` → redirect 301 to `/about-us`

---

### 9. Can I embed YouTube videos in pages?

The editor doesn't directly support iframes for security. Alternatives:

**Option 1 - Link to video:**
```html
<a href="https://www.youtube.com/watch?v=ID">Watch the video</a>
```

**Option 2 - Image with link:**
```html
<a href="https://www.youtube.com/watch?v=ID">
  <img src="https://img.youtube.com/vi/ID/maxresdefault.jpg" alt="Video">
</a>
```

**Option 3 - Custom plugin:**
Create a plugin that registers a hook to allow iframes from trusted domains.

---

### 10. How do I backup CMS content before major changes?

**Method 1 - Database export:**
```bash
mysqldump -u user -p database home_content cms_pages > cms_backup.sql
```

**Method 2 - Integrated backup:**
- Use Pinakes backup system (**Admin → Backup**)
- The backup includes all CMS tables

**Method 3 - Screenshot:**
Before major changes, take screenshots of current pages.

**Restore:**
```bash
mysql -u user -p database < cms_backup.sql
```
