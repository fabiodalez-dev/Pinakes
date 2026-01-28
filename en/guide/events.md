# Event Management

Complete guide to managing library events in Pinakes.

## Overview

The events module allows you to:
- Create and publish library events
- Manage images and multimedia content
- Configure complete SEO for each event
- Enable/disable the events section globally

## Access

Event management is found in:
- **Admin → CMS → Events**

## Enabling the Events Section

The events section can be enabled or disabled globally:

1. Go to **CMS → Events**
2. Use the **Events Section** toggle
3. When disabled:
   - The "Events" menu doesn't appear in the frontend
   - Event pages return 404
   - Existing events are preserved

Setting: `events_page_enabled` in `system_settings` (category: `cms`)

## Creating an Event

### Access

1. Go to **CMS → Events**
2. Click **New Event**

### Event Fields

| Field | Description | Required |
|-------|-------------|----------|
| **Title** | Event name | Yes |
| **Content** | HTML description (TinyMCE) | No |
| **Event date** | Date (format: YYYY-MM-DD) | Yes |
| **Event time** | Time (format: HH:MM) | No |
| **Image** | Featured image | No |
| **Active** | Visible in frontend | No (default: no) |

### Validations

- **Date**: must be in `YYYY-MM-DD` format
- **Time**: must be in `HH:MM` or `HH:MM:SS` format
- **Image**: JPG, PNG, WebP - max 5MB

### Automatic Slug

The system automatically generates an SEO-friendly slug:
- Title converted to lowercase
- Accents removed (UTF-8 → ASCII)
- Special characters removed
- Spaces converted to hyphens
- Uniqueness guaranteed (adds `-1`, `-2` if necessary)

Example: "New Book Presentation" → `new-book-presentation`

## Event Image

### Image Upload

Supported formats:
- JPG/JPEG
- PNG
- WebP

Size limit: **5 MB**

### File Path

Images are saved in:
```
public/uploads/events/event_YYYYMMDD_HHMMSS_[random].ext
```

The name includes:
- Prefix `event_`
- Upload date and time
- 8 random characters (security)
- Original extension

### Remove Image

1. Edit the event
2. Select "Remove image"
3. Save

## Event SEO

Each event has dedicated SEO fields to optimize search engine visibility.

### Basic Meta Tags

| Field | Description | Recommended length |
|-------|-------------|-------------------|
| `seo_title` | Title tag | 50-60 characters |
| `seo_description` | Meta description | 150-160 characters |
| `seo_keywords` | Meta keywords | Comma-separated keywords |

### Open Graph (Facebook/LinkedIn)

| Field | Description |
|-------|-------------|
| `og_title` | Social title |
| `og_description` | Social description |
| `og_image` | Social image URL |
| `og_type` | Content type (default: `article`) |
| `og_url` | Canonical URL |

### Twitter Card

| Field | Description |
|-------|-------------|
| `twitter_card` | Card type (default: `summary_large_image`) |
| `twitter_title` | Twitter title |
| `twitter_description` | Twitter description |
| `twitter_image` | Twitter image URL |

## Event List

### Pagination

The admin event list shows:
- 10 events per page
- Sorting: event date DESC, creation date DESC

### Displayed Information

For each event:
- Title
- Slug
- Event date and time
- Image (thumbnail)
- Status (active/draft)
- Creation date

## Editing an Event

1. Go to **CMS → Events**
2. Click on the event to edit
3. Modify the fields
4. Save

### Slug Update

When you modify the title:
- The slug is automatically regenerated
- If the slug already exists, a numeric suffix is added
- The event ID is excluded from the uniqueness check

## Deleting an Event

1. Click the **Delete** icon on the event
2. Confirm deletion

> **Warning**: Deletion is permanent. The associated image remains in the filesystem.

## Database Table

```sql
CREATE TABLE `events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `content` text,
  `event_date` date NOT NULL,
  `event_time` time DEFAULT NULL,
  `featured_image` varchar(500) DEFAULT NULL,
  `seo_title` varchar(255) DEFAULT NULL,
  `seo_description` text,
  `seo_keywords` varchar(500) DEFAULT NULL,
  `og_image` varchar(500) DEFAULT NULL,
  `og_title` varchar(255) DEFAULT NULL,
  `og_description` text,
  `og_type` varchar(50) DEFAULT 'article',
  `og_url` varchar(500) DEFAULT NULL,
  `twitter_card` varchar(50) DEFAULT 'summary_large_image',
  `twitter_title` varchar(255) DEFAULT NULL,
  `twitter_description` text,
  `twitter_image` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '0',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
);
```

## Security

### Input Sanitization

- **Text fields**: `strip_tags()` removes all HTML tags
- **TinyMCE content**: `HtmlHelper::sanitizeHtml()` with allowed tag whitelist
- **File upload**: MIME type, extension, and size validation

### Path Traversal Protection

- `realpath()` verification of upload path
- Check that final path is within `public/uploads`
- Null bytes removal from filename

### CSRF

All operations (create, update, delete, toggle) require a valid CSRF token.

## Frontend URL

Events are accessible in the frontend at:
```
/events                    # Event list
/event/[slug]              # Single event
```

The exact URL depends on the localized route configuration.

## Best Practices

### Images

- Use optimized dimensions (1200x630px for social)
- Prefer WebP format for smaller size
- Compress images before upload

### SEO

- Always fill in title and description
- Use Open Graph for better social sharing
- The slug is generated from the title, choose it carefully

### Content

- Use the TinyMCE editor for rich formatting
- Avoid inline styles - use predefined classes
- Verify the preview before publishing

---

## Frequently Asked Questions (FAQ)

### 1. How do I enable the events section on the site?

1. Go to **CMS → Events**
2. Activate the **Events Section** toggle
3. The "Events" menu automatically appears in the frontend

If disabled, all event pages return 404.

---

### 2. What's the difference between "active" and "draft" events?

| Status | Visible in frontend | Editable |
|--------|---------------------|----------|
| **Active** | Yes | Yes |
| **Draft** (not active) | No | Yes |

Draft events are useful for preparing content in advance.

---

### 3. How do I create a recurring event (e.g., every week)?

Currently Pinakes doesn't support automatic recurring events. You must:
1. Create each occurrence separately
2. Or use a single event with description "Every Tuesday at 5 PM"

---

### 4. What dimensions should the event image be?

**Recommended dimensions**:
- **For site**: 1200 x 630 pixels (optimal social format)
- **Format**: WebP (lighter) or JPG
- **Maximum size**: 5 MB (compress before upload)

Larger images are accepted but slow down loading.

---

### 5. How do I optimize an event's SEO?

Fill in all SEO fields:
1. **SEO Title**: 50-60 characters, include keywords
2. **Description**: 150-160 characters, engaging description
3. **Open Graph**: for optimal social sharing
4. **Image**: 1200x630px for social preview

---

### 6. Can I embed videos in the event?

Yes, using the TinyMCE editor:
1. Click **Insert/Edit media**
2. Paste YouTube or Vimeo URL
3. The video is embedded in the content

---

### 7. How do I delete a past event?

1. Go to **CMS → Events**
2. Find the event in the list
3. Click **Delete**
4. Confirm

**Note**: the associated image remains on the server.

---

### 8. Do events appear in the dashboard calendar?

No, the dashboard calendar only shows loans and reservations.

Library events appear:
- On the public page `/events`
- In the ICS feed if configured

---

### 9. How do I change an event's URL slug?

The slug is automatically generated from the title. To change it:
1. Modify the event's **title**
2. The slug is regenerated
3. Save

If you want a specific slug, modify the title accordingly.

---

### 10. Can I schedule automatic event publication?

No, publication requires manual activation. For future events:
1. Create the event with a future date
2. Set as "Active"
3. It will be visible immediately but with a future date

Users will see that the event is scheduled for that date.
