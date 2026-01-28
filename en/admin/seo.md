# SEO and Visibility

Pinakes includes built-in SEO features to improve visibility in search engines.

## XML Sitemap

### Automatic Generation

The system automatically generates an XML sitemap that includes:

| Type | Query Limit | Priority | Frequency |
|------|-------------|----------|-----------|
| Homepage | - | 1.0 | daily |
| Catalog | - | 0.9 | daily |
| Books | 2000 | 0.8 | weekly |
| Authors | 500 | 0.6 | monthly |
| Publishers | - | 0.5 | monthly |
| Genres | Only with books | 0.5 | monthly |
| CMS Pages | Only active | 0.6 | monthly |
| Static pages | - | 0.4-0.7 | monthly |

### Sitemap URL

```
https://yourdomain.com/sitemap.xml
```

### Dynamic Generation

The sitemap is dynamically generated on each request:

```php
// SeoController::sitemap()
$generator = new SitemapGenerator($db, $baseUrl);
return $generator->generate();
```

The generator:
1. Loads active languages from database
2. Generates URLs for each entity in each locale
3. Deduplicates URLs
4. Returns XML conforming to Sitemaps.org protocol

### Multi-Language

For each active locale, the system generates URLs with language prefix:
- Default locale (it_IT): `/libro/123`
- Other locales (en_US): `/en/libro/123`

### Statistics

The generator tracks included entities:

```php
$stats = $generator->getStats();
// ['total' => 1234, 'books' => 500, 'authors' => 200, ...]
```

## Robots.txt

### Automatic Generation

The robots.txt file is dynamically generated:

```
User-agent: *
Disallow: /admin/
Disallow: /login
Disallow: /register

Sitemap: https://yourdomain.com/sitemap.xml
```

### URL

```
https://yourdomain.com/robots.txt
```

### Content

| Directive | Description |
|-----------|-------------|
| `Disallow: /admin/` | Block admin area indexing |
| `Disallow: /login` | Block login page |
| `Disallow: /register` | Block registration page |
| `Sitemap:` | Indicates sitemap location |

## Meta Tags

### Homepage (CMS Hero)

Homepage meta tags are configurable in the CMS:

```
seo_title          # Title tag
seo_description    # Meta description
seo_keywords       # Meta keywords
```

See [CMS](cms.md) for complete configuration.

### Book Pages

Meta tags are automatically generated from:
- **Title**: book title + author
- **Description**: first 160 characters of book description

### CMS Pages

Each CMS page has a configurable `meta_description` field.

## Open Graph

### Generated Tags

For book pages:

```html
<meta property="og:title" content="Book Title">
<meta property="og:description" content="Book description">
<meta property="og:image" content="Cover URL">
<meta property="og:image:width" content="width">
<meta property="og:image:height" content="height">
<meta property="og:image:type" content="image/jpeg">
<meta property="og:url" content="Canonical URL">
<meta property="og:type" content="book">
<meta property="og:site_name" content="Library Name">
```

### Homepage (CMS)

The CMS Hero supports all Open Graph fields:

| Field | Description |
|-------|-------------|
| `og_title` | Custom OG title |
| `og_description` | OG description |
| `og_type` | Type (default: website) |
| `og_url` | Canonical URL |
| `og_image` | Sharing image |

## Twitter Card

### Generated Tags

```html
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Title">
<meta name="twitter:description" content="Description">
<meta name="twitter:image" content="Image URL">
<meta name="twitter:site" content="@handle">
<meta name="twitter:creator" content="@handle">
```

### CMS Configuration

The CMS Hero supports:

| Field | Description |
|-------|-------------|
| `twitter_card` | Card type (summary_large_image) |
| `twitter_title` | Twitter title |
| `twitter_description` | Twitter description |
| `twitter_image` | Twitter image |

## Schema.org (JSON-LD)

### Book Schema

For each book page:

```json
{
  "@context": "https://schema.org",
  "@type": "Book",
  "name": "Book Title",
  "author": {
    "@type": "Person",
    "name": "Author Name"
  },
  "publisher": {
    "@type": "Organization",
    "name": "Publisher Name"
  },
  "isbn": "9788858155530",
  "numberOfPages": 256,
  "inLanguage": "en",
  "genre": "Fiction",
  "description": "Book description...",
  "image": "Cover URL",
  "offers": {
    "@type": "Offer",
    "availability": "https://schema.org/InStock",
    "itemCondition": "https://schema.org/UsedCondition",
    "seller": {
      "@type": "Library",
      "name": "Library Name"
    }
  }
}
```

### AggregateRating

If the book has approved reviews:

```json
{
  "aggregateRating": {
    "@type": "AggregateRating",
    "ratingValue": "4.5",
    "reviewCount": "12",
    "bestRating": "5",
    "worstRating": "1"
  }
}
```

### BreadcrumbList Schema

For navigation:

```json
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    {
      "@type": "ListItem",
      "position": 1,
      "name": "Home",
      "item": "https://library.com"
    },
    {
      "@type": "ListItem",
      "position": 2,
      "name": "Catalog",
      "item": "https://library.com/catalog"
    },
    {
      "@type": "ListItem",
      "position": 3,
      "name": "Book Title"
    }
  ]
}
```

### Library Organization

Schema for the library:

```json
{
  "@context": "https://schema.org",
  "@type": "Library",
  "name": "Library Name",
  "url": "https://library.com",
  "description": "Digital library with complete catalog"
}
```

## Canonical URL

### Configuration

The `APP_CANONICAL_URL` environment variable defines the base URL:

```env
APP_CANONICAL_URL=https://library.example.com
```

### Automatic Detection

If not configured, the URL is detected from:
1. `X-Forwarded-Proto` header (for proxies)
2. `HTTPS` variable
3. `REQUEST_SCHEME` variable
4. Server port (443 = HTTPS)

### Host Header

The system handles:
- `X-Forwarded-Host` (for proxies)
- `HTTP_HOST`
- `SERVER_NAME`

## Implementation

### Files Involved

| File | Function |
|------|----------|
| `SeoController.php` | Sitemap and robots endpoints |
| `SitemapGenerator.php` | XML generation |
| `Views/frontend/layout.php` | OG and Twitter meta |
| `Views/frontend/book-detail.php` | Book Schema.org |

### Routes

```php
$app->get('/sitemap.xml', [SeoController::class, 'sitemap']);
$app->get('/robots.txt', [SeoController::class, 'robots']);
```

## Troubleshooting

### Sitemap not accessible

Verify:
1. `/sitemap.xml` route configured in `.htaccess`
2. Database connection working
3. `thepixeldeveloper/sitemap` library installed

### Incorrect URLs in sitemap

Verify:
1. `APP_CANONICAL_URL` in `.env`
2. Proxy configuration (X-Forwarded-*)
3. Default locale in `languages` table

### Meta tags not updated

1. Clear browser cache
2. Use social debug tools (Facebook Debugger, Twitter Card Validator)
3. Verify CMS homepage content

### Schema.org not recognized

Test with:
1. [Google Rich Results Test](https://search.google.com/test/rich-results)
2. Verify JSON-LD is syntactically valid
3. Check required fields (name, author for Book)

---

## Frequently Asked Questions (FAQ)

### 1. How do I get my library indexed on Google?

To get Pinakes indexed on Google:

**1. Verify sitemap:**
- Access `https://yourdomain.com/sitemap.xml`
- Should display XML with all URLs

**2. Submit to Google Search Console:**
1. Go to [search.google.com/search-console](https://search.google.com/search-console)
2. Add your property (domain)
3. Verify property (DNS or HTML file)
4. In "Sitemaps", enter `/sitemap.xml`
5. Click "Submit"

**3. Wait for indexing:**
- Google crawls the site in days/weeks
- Monitor coverage in Search Console

---

### 2. Why doesn't the sitemap show all my books?

The sitemap has limits for performance:

| Type | Limit |
|------|-------|
| Books | 2,000 |
| Authors | 500 |

**If you have more books:**
- Most recent have priority
- Consider sitemap index (future feature)

**Verify count:**
```php
$stats = $generator->getStats();
// ['total' => 1234, 'books' => 500, ...]
```

---

### 3. How do I configure the canonical URL correctly?

The canonical URL is fundamental for SEO:

**In `.env`:**
```env
APP_CANONICAL_URL=https://library.example.com
```

**Without trailing slash**, without path.

**Behind proxy/CDN:**
Configure headers:
- `X-Forwarded-Proto: https`
- `X-Forwarded-Host: library.example.com`

**Verify:**
View page source and search for `<link rel="canonical"`.

---

### 4. How can I test Open Graph meta tags?

**Official tools:**

| Platform | Tool |
|----------|------|
| Facebook | [Facebook Sharing Debugger](https://developers.facebook.com/tools/debug/) |
| Twitter | [Twitter Card Validator](https://cards-dev.twitter.com/validator) |
| LinkedIn | [LinkedIn Post Inspector](https://www.linkedin.com/post-inspector/) |

**Procedure:**
1. Enter the book page URL
2. Verify title, description, image
3. If old data, click "Scrape Again" / "Fetch new"

---

### 5. Why don't images appear in social shares?

**Common causes:**

| Problem | Solution |
|---------|----------|
| Image too small | Minimum 1200x630 px for OG |
| Missing HTTPS | Platforms require HTTPS |
| Incorrect image URL | Must be complete absolute URL |
| Image not accessible | Verify not protected by login |

**Verify in code:**
```html
<meta property="og:image" content="https://library.com/uploads/cover.jpg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
```

---

### 6. How does the multilingual sitemap work?

Pinakes generates URLs for each active locale:

**Example with IT (default) + EN:**
```xml
<url>
  <loc>https://library.com/libro/123</loc>
</url>
<url>
  <loc>https://library.com/en/libro/123</loc>
</url>
```

**Configuration:**
- Active languages are in the `languages` table
- Default locale has no URL prefix
- Other locales have prefix (`/en/`, `/fr/`)

---

### 7. How do I verify Schema.org is correct?

**1. Google Rich Results Test:**
- [search.google.com/test/rich-results](https://search.google.com/test/rich-results)
- Enter book page URL
- Verify it detects "Book" schema

**2. Schema Markup Validator:**
- [validator.schema.org](https://validator.schema.org)
- Paste the JSON-LD
- Verify syntax errors

**3. Manual inspection:**
- Open page source (Ctrl+U)
- Search for `<script type="application/ld+json">`
- Verify JSON structure

---

### 8. Do reviews affect SEO?

Yes! If the book has approved reviews, Pinakes generates **AggregateRating**:

```json
{
  "@type": "Book",
  "aggregateRating": {
    "@type": "AggregateRating",
    "ratingValue": "4.5",
    "reviewCount": "12"
  }
}
```

**SEO benefits:**
- Stars visible in Google results (rich snippets)
- Higher click-through rate
- Quality content signal

**Google requirements:**
- At least 1 review
- Valid rating (1-5)

---

### 9. How do I customize homepage meta tags?

Homepage meta tags are configured in the CMS:

1. Go to **Admin → CMS → Homepage**
2. **Hero** section
3. Fill in SEO fields:

| Field | Use | Limit |
|-------|-----|-------|
| `seo_title` | `<title>` | 60 characters |
| `seo_description` | `<meta description>` | 160 characters |
| `og_title` | Facebook/LinkedIn title | 60 characters |
| `twitter_title` | Twitter title | 70 characters |

4. Save

---

### 10. Do I need to manually configure robots.txt?

No, Pinakes generates `robots.txt` automatically:

**URL:** `https://yourdomain.com/robots.txt`

**Generated content:**
```
User-agent: *
Disallow: /admin/
Disallow: /login
Disallow: /register
Sitemap: https://yourdomain.com/sitemap.xml
```

**Customization:**
Currently the content is fixed. For modifications, create a physical `robots.txt` file in the `public/` folder that will override the dynamic route.
