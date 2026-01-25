# SEO Implementation Testing Guide

## Overview

This document provides comprehensive testing procedures for the dynamic, multilingual SEO system implemented for the Pinakes homepage.

**Implementation Date:** 2025-11-07
**Version:** 0.1.1
**Status:** Complete

---

## What Was Implemented

### 1. Database Schema Changes

**Table:** `home_content`

**New Columns Added:**
- `seo_title` (VARCHAR 255) - Custom SEO title override
- `seo_description` (TEXT) - Custom meta description
- `seo_keywords` (VARCHAR 500) - SEO keywords (comma-separated)
- `og_image` (VARCHAR 500) - Custom Open Graph image

**Migration Script:** `scripts/add-seo-fields-to-home-content.php`

### 2. CMS Admin Panel

**File:** `app/Views/cms/edit-home.php`

**New Features:**
- SEO section in hero configuration
- Fields for SEO title, description, keywords, and OG image
- Character count guidance (60 for title, 160 for description)
- Inline help text and placeholder examples
- All fields are optional with intelligent fallbacks

### 3. Frontend Controller Logic

**File:** `app/Controllers/FrontendController.php`

**Dynamic SEO Data Sources:**

| SEO Field | Priority Order | Fallback Chain |
|-----------|---------------|----------------|
| Title | Custom SEO title → Hero title → App name | `Pinakes` |
| Description | Custom SEO description → Hero subtitle → Footer description | Generic library text |
| Image | Custom OG image → Hero background → App logo | Default cover |
| Keywords | Custom keywords | `biblioteca digitale, prestito libri...` |

**Schema.org Structured Data:**
- WebSite schema with search action
- Organization schema with logo and social profiles
- JSON-LD format for Google rich snippets

### 4. Layout Enhancements

**File:** `app/Views/frontend/layout.php`

**New SEO Elements:**
- Meta keywords tag (conditional)
- Hreflang tags for Italian/English (x-default)
- Twitter Card meta tags with site/creator handles
- Enhanced Open Graph tags
- Schema.org JSON-LD structured data

### 5. Multilingual Support

**Files Modified:**
- `locale/en_US.json` - Added 9 new SEO-related translations

**Supported Languages:**
- Italian (it_IT) - Default
- English (en_US)

**Hreflang Implementation:**
```html
<link rel="alternate" hreflang="it" href="...?lang=it">
<link rel="alternate" hreflang="en" href="...?lang=en">
<link rel="alternate" hreflang="x-default" href="...">
```

---

## Testing Procedures

### Phase 1: Database Migration Testing

#### Test 1.1: Verify Migration Success

```bash
# Run migration script
php scripts/add-seo-fields-to-home-content.php

# Expected output:
# SEO columns added successfully
# All 4 SEO columns verified
# Default SEO description set for hero section
```

#### Test 1.2: Verify Schema Columns

```sql
-- Connect to database
mysql -u root -p biblioteca

-- Check columns exist
SHOW COLUMNS FROM home_content;

-- Expected columns (among others):
-- seo_title, seo_description, seo_keywords, og_image

-- Check data types
DESCRIBE home_content;

-- Expected types:
-- seo_title: varchar(255)
-- seo_description: text
-- seo_keywords: varchar(500)
-- og_image: varchar(500)
```

** Pass Criteria:**
- All 4 columns exist
- Data types match specification
- Default values are NULL
- No errors during migration

---

### Phase 2: CMS Admin Panel Testing

#### Test 2.1: Access CMS Home Edit Page

**Steps:**
1. Login as admin user
2. Navigate to `/admin/settings?tab=cms`
3. Click "Modifica Homepage" button
4. Verify page loads without errors

** Pass Criteria:**
- Page loads successfully
- No PHP errors in browser console
- SEO section visible in hero card

#### Test 2.2: Test SEO Fields Form

**Test Data:**
```
SEO Title: La Mia Biblioteca - Catalogo Completo Online
SEO Description: Scopri oltre 5.000 libri nella nostra biblioteca digitale. Prestiti gratuiti, catalogo sempre aggiornato, registrazione immediata.
SEO Keywords: biblioteca digitale, prestito libri online, catalogo libri gratis, gestione biblioteca
OG Image: https://example.com/uploads/og-library.jpg
```

**Steps:**
1. Fill in all SEO fields with test data
2. Click "Salva modifiche Homepage"
3. Verify success message appears
4. Reload page and verify data persists

** Pass Criteria:**
- Form submits successfully
- Success message: "Homepage aggiornata con successo"
- SEO data persists after page reload
- No JavaScript errors

#### Test 2.3: Test Empty SEO Fields (Fallback Behavior)

**Steps:**
1. Clear all SEO fields (leave empty)
2. Save changes
3. Visit homepage
4. Inspect page source

** Pass Criteria:**
- Page loads without errors
- Meta tags use fallback values:
  - Title: Hero title or app name
  - Description: Hero subtitle or footer description
  - Image: Hero background or app logo
  - Keywords: Default keywords

---

### Phase 3: Frontend Display Testing

#### Test 3.1: Homepage SEO Meta Tags

**Steps:**
1. Visit homepage: `http://localhost:8000/`
2. Right-click → "View Page Source"
3. Search for SEO meta tags

**Expected HTML Output:**

```html
<!-- Title -->
<title>La Mia Biblioteca - Catalogo Completo Online</title>

<!-- Meta Description -->
<meta name="description" content="Scopri oltre 5.000 libri nella nostra biblioteca digitale...">

<!-- Meta Keywords (if set) -->
<meta name="keywords" content="biblioteca digitale, prestito libri online...">

<!-- Canonical URL -->
<link rel="canonical" href="http://localhost:8000/">

<!-- Hreflang Tags -->
<link rel="alternate" hreflang="it" href="http://localhost:8000/?lang=it">
<link rel="alternate" hreflang="en" href="http://localhost:8000/?lang=en">
<link rel="alternate" hreflang="x-default" href="http://localhost:8000/">
```

** Pass Criteria:**
- All meta tags present
- Custom SEO values displayed correctly
- No HTML encoding issues (è not Ã¨)
- Hreflang tags have correct URLs

#### Test 3.2: Open Graph Tags

**Expected HTML Output:**

```html
<meta property="og:title" content="La Mia Biblioteca - Catalogo Completo Online">
<meta property="og:description" content="Scopri oltre 5.000 libri nella nostra biblioteca digitale...">
<meta property="og:image" content="https://example.com/uploads/og-library.jpg">
<meta property="og:url" content="http://localhost:8000/">
<meta property="og:type" content="book">
<meta property="og:site_name" content="Pinakes">
```

** Pass Criteria:**
- All OG tags present
- Image URL is absolute (includes https://)
- Type is "book" (appropriate for library)
- Site name uses app name from settings

#### Test 3.3: Twitter Card Tags

**Expected HTML Output:**

```html
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="La Mia Biblioteca - Catalogo Completo Online">
<meta name="twitter:description" content="Scopri oltre 5.000 libri...">
<meta name="twitter:image" content="https://example.com/uploads/og-library.jpg">
<meta name="twitter:site" content="@biblioteca_it">
<meta name="twitter:creator" content="@biblioteca_it">
```

** Pass Criteria:**
- Card type is "summary_large_image"
- Twitter handle extracted from social_twitter setting
- All content matches OG tags

#### Test 3.4: Schema.org Structured Data

**Expected JSON-LD:**

```json
[
  {
    "@context": "https://schema.org",
    "@type": "WebSite",
    "name": "Pinakes",
    "url": "http://localhost:8000",
    "description": "Scopri oltre 5.000 libri...",
    "potentialAction": {
      "@type": "SearchAction",
      "target": {
        "@type": "EntryPoint",
        "urlTemplate": "http://localhost:8000/catalogo?q={search_term_string}"
      },
      "query-input": "required name=search_term_string"
    }
  },
  {
    "@context": "https://schema.org",
    "@type": "Organization",
    "name": "Pinakes",
    "url": "http://localhost:8000",
    "logo": "http://localhost:8000/uploads/logo.png",
    "sameAs": [
      "https://facebook.com/biblioteca",
      "https://twitter.com/biblioteca_it",
      "https://instagram.com/biblioteca"
    ]
  }
]
```

** Pass Criteria:**
- JSON is valid (no syntax errors)
- WebSite schema includes search action
- Organization schema includes logo and social profiles
- All URLs are absolute

---

### Phase 4: Validation Tools Testing

#### Test 4.1: Google Rich Results Test

**Tool:** https://search.google.com/test/rich-results

**Steps:**
1. Copy homepage URL or HTML source
2. Paste into Rich Results Test
3. Click "Test URL" or "Test Code"

** Pass Criteria:**
- No errors or warnings
- WebSite schema detected
- Organization schema detected
- Search action recognized

#### Test 4.2: Facebook Sharing Debugger

**Tool:** https://developers.facebook.com/tools/debug/

**Steps:**
1. Enter homepage URL
2. Click "Debug"
3. Verify OG tags are scraped correctly

** Pass Criteria:**
- Title matches custom SEO title
- Description matches custom SEO description
- Image preview shows OG image
- No missing required properties

#### Test 4.3: Twitter Card Validator

**Tool:** https://cards-dev.twitter.com/validator

**Steps:**
1. Enter homepage URL
2. Click "Preview card"

** Pass Criteria:**
- Card type: "summary_large_image"
- Title, description, and image display correctly
- No errors or warnings

#### Test 4.4: Schema.org Validator

**Tool:** https://validator.schema.org/

**Steps:**
1. Copy JSON-LD from page source
2. Paste into validator
3. Check for errors

** Pass Criteria:**
- No errors
- WebSite and Organization schemas valid
- All required properties present

---

### Phase 5: Multilingual Testing

#### Test 5.1: Italian Homepage (Default)

**Steps:**
1. Clear browser cookies
2. Visit: `http://localhost:8000/`
3. Verify Italian is default language

** Pass Criteria:**
- Page displays in Italian
- Hreflang tags present
- Session locale is `it_IT`

#### Test 5.2: English Homepage

**Steps:**
1. Click language switcher or visit: `http://localhost:8000/?lang=en`
2. Verify page switches to English

** Pass Criteria:**
- Page displays in English
- SEO fields translated (if applicable)
- Hreflang tags still present
- Session locale is `en_US`

#### Test 5.3: Hreflang Tag Validation

**Tool:** Manual inspection or hreflang tag validator

**Steps:**
1. View page source
2. Verify hreflang tags are correct

**Expected:**
```html
<link rel="alternate" hreflang="it" href="http://localhost:8000/?lang=it">
<link rel="alternate" hreflang="en" href="http://localhost:8000/?lang=en">
<link rel="alternate" hreflang="x-default" href="http://localhost:8000/">
```

** Pass Criteria:**
- All 3 hreflang tags present (it, en, x-default)
- URLs are absolute
- No duplicate hreflang tags
- x-default points to base URL

---

### Phase 6: Performance Testing

#### Test 6.1: Page Load Time

**Tools:**
- Google PageSpeed Insights
- Chrome DevTools Network tab

**Steps:**
1. Open Chrome DevTools (F12)
2. Go to Network tab
3. Hard refresh homepage (Ctrl+Shift+R)
4. Check total load time

** Pass Criteria:**
- Page loads in < 3 seconds
- No significant performance regression from SEO additions
- All external resources load (if applicable)

#### Test 6.2: Database Query Performance

**Steps:**
1. Enable MySQL slow query log
2. Load homepage 10 times
3. Check for slow queries

```sql
-- Check query performance
EXPLAIN SELECT section_key, title, subtitle, content, button_text, button_link,
               background_image, seo_title, seo_description, seo_keywords, og_image, is_active
FROM home_content
WHERE is_active = 1
ORDER BY display_order ASC;
```

** Pass Criteria:**
- Query executes in < 10ms
- Using appropriate indexes
- No table scans

---

### Phase 7: Edge Cases & Error Handling

#### Test 7.1: Very Long SEO Title (> 255 chars)

**Steps:**
1. Enter 300-character title in CMS
2. Save changes

** Pass Criteria:**
- Input is truncated to 255 chars (maxlength attribute)
- Or: Backend validation prevents save
- No database errors

#### Test 7.2: HTML in SEO Fields

**Test Data:**
```
SEO Title: <script>alert('XSS')</script>Test
SEO Description: <b>Bold text</b> and <a href="#">link</a>
```

**Steps:**
1. Enter HTML/script tags in SEO fields
2. Save changes
3. View page source

** Pass Criteria:**
- HTML is properly escaped in meta tags
- No XSS vulnerability
- Script tags don't execute
- Output: `&lt;script&gt;alert('XSS')&lt;/script&gt;Test`

#### Test 7.3: Empty Database (Fresh Install)

**Steps:**
1. Truncate `home_content` table
2. Visit homepage

** Pass Criteria:**
- Page loads without fatal errors
- Fallback values used for all SEO fields
- Default title: App name
- Default description: Generic library text

#### Test 7.4: Missing App Settings

**Steps:**
1. Delete app.name from settings
2. Visit homepage

** Pass Criteria:**
- Page loads with "Pinakes" as default
- No PHP warnings or notices
- SEO tags still present

---

### Phase 8: Installer Compatibility

#### Test 8.1: Fresh Install with New Schema

**Steps:**
1. Create new test database
2. Run installer from scratch
3. Verify `home_content` table has SEO columns

```sql
USE biblioteca_test;
SHOW CREATE TABLE home_content;
```

** Pass Criteria:**
- Table created successfully
- SEO columns present (seo_title, seo_description, seo_keywords, og_image)
- Default data inserted correctly

#### Test 8.2: Installer Table Count Verification

**File:** `installer/classes/Installer.php` (line ~395)

**Steps:**
1. Check table count validation
2. Verify count matches actual tables

```php
// Current validation
if (count($tables) !== 30) {  // Update this if table count changed
    throw new Exception("Database installation incomplete.");
}
```

** Pass Criteria:**
- Table count validation passes
- No installer errors
- All tables created successfully

---

## Validation Checklist

Use this checklist to verify complete implementation:

### Database
- [ ] Migration script ran successfully
- [ ] 4 new columns added to `home_content`
- [ ] Columns have correct data types
- [ ] No data loss during migration
- [ ] Installer schema.sql updated

### CMS Admin
- [ ] SEO section visible in hero edit
- [ ] All 4 SEO input fields present
- [ ] Form saves data correctly
- [ ] Data persists after save
- [ ] Empty fields use fallbacks

### Frontend
- [ ] Meta title tag displays custom value
- [ ] Meta description tag displays custom value
- [ ] Meta keywords tag displays (if set)
- [ ] Canonical URL is correct
- [ ] Hreflang tags present (it, en, x-default)
- [ ] Open Graph tags complete
- [ ] Twitter Card tags complete
- [ ] Schema.org JSON-LD valid

### Multilingual
- [ ] Italian translation works
- [ ] English translation works
- [ ] Hreflang URLs correct for both languages
- [ ] Language switcher updates SEO

### Validation Tools
- [ ] Google Rich Results Test passes
- [ ] Facebook Sharing Debugger shows correct preview
- [ ] Twitter Card Validator displays card
- [ ] Schema.org Validator shows no errors

### Performance
- [ ] Page load time < 3 seconds
- [ ] Database query < 10ms
- [ ] No N+1 query problems

### Security
- [ ] XSS protection (HTML escaped)
- [ ] SQL injection protection (prepared statements)
- [ ] CSRF token validation
- [ ] Input sanitization working

---

## Common Issues & Solutions

### Issue 1: Hreflang Tags Not Showing

**Symptom:** Hreflang tags missing from page source

**Solution:**
- Check that `$currentUrl` is defined in layout.php
- Verify `$_SERVER['HTTP_HOST']` is available
- Ensure no output buffering conflicts

### Issue 2: Schema.org JSON Invalid

**Symptom:** Google Rich Results Test shows errors

**Solution:**
- Validate JSON with jsonlint.com
- Check for missing required properties
- Ensure all URLs are absolute (include https://)
- Verify JSON_UNESCAPED_SLASHES flag is used

### Issue 3: OG Image Not Displaying

**Symptom:** Facebook shows no preview image

**Solution:**
- Verify image URL is absolute (not relative)
- Check image is publicly accessible (not 404)
- Image dimensions should be 1200x630px (OG recommended)
- Use Facebook Debugger to force re-scrape

### Issue 4: Migration Script Error

**Symptom:** "Column already exists" error

**Solution:**
- Script checks for existing columns before adding
- If error persists, manually verify columns:
  ```sql
  SHOW COLUMNS FROM home_content LIKE 'seo_%';
  ```
- Drop and re-run migration if needed (BACKUP FIRST)

---

## Post-Implementation Monitoring

### Weekly Checks
- Monitor Google Search Console for SEO performance
- Check for crawl errors related to hreflang
- Verify structured data remains valid

### Monthly Reviews
- Review SEO titles/descriptions for effectiveness
- Update keywords based on search analytics
- A/B test different OG images

### Tools to Monitor
- **Google Search Console:** Track impressions, clicks, CTR
- **Google Analytics:** Monitor organic traffic
- **Rich Results Report:** Ensure structured data working

---

## Rollback Procedure

If critical issues arise, follow this rollback procedure:

### Step 1: Revert Database Changes

```sql
-- Remove SEO columns
ALTER TABLE home_content
  DROP COLUMN seo_title,
  DROP COLUMN seo_description,
  DROP COLUMN seo_keywords,
  DROP COLUMN og_image;
```

### Step 2: Revert Code Changes

```bash
# Checkout previous commit (before SEO implementation)
git log --oneline -10  # Find commit hash
git revert <commit-hash>

# Or reset to specific commit (DESTRUCTIVE)
git reset --hard <commit-hash>
```

### Step 3: Clear Cache

```bash
# Clear PHP OPcache (if enabled)
php -r "opcache_reset();"

# Restart web server
sudo systemctl restart apache2
# OR
sudo systemctl restart nginx
```

---

## Success Metrics

The SEO implementation is considered successful if:

1.  All 35+ test cases pass
2.  Google Rich Results Test validates structured data
3.  Facebook/Twitter show correct preview
4.  No performance degradation (< 10ms query time)
5.  No security vulnerabilities (XSS/SQL injection tests pass)
6.  Multilingual hreflang working for both languages
7.  Fresh installs work with new schema

---

## Documentation References

- [Google SEO Starter Guide](https://developers.google.com/search/docs/fundamentals/seo-starter-guide)
- [Open Graph Protocol](https://ogp.me/)
- [Twitter Card Docs](https://developer.twitter.com/en/docs/twitter-for-websites/cards/overview/abouts-cards)
- [Schema.org WebSite](https://schema.org/WebSite)
- [Google Hreflang Guide](https://developers.google.com/search/docs/specialty/international/localized-versions)

---

**Last Updated:** 2025-11-07
**Status:** Ready for Production Testing
