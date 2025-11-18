# Adding New Scrapers - Guide

This guide explains how to add new book scrapers to the API Book Scraper server.

## 🎯 Overview

The refactored architecture makes adding scrapers **extremely simple**:

1. Create a new scraper class extending `AbstractScraper`
2. Register it in `config/scrapers.php`
3. **Done!** No core code modifications needed

---

## 📝 Step-by-Step Guide

### Step 1: Create Scraper Class

Create a new file in `src/Scraping/Scrapers/` with your scraper class.

**Example:** `src/Scraping/Scrapers/AmazonItScraper.php`

```php
<?php
/**
 * Amazon.it Book Scraper
 */
class AmazonItScraper extends AbstractScraper
{
    /**
     * Get scraper name
     */
    public function getName(): string
    {
        return 'Amazon.it';
    }

    /**
     * Scrape book data by ISBN
     */
    public function scrape(string $isbn): ?array
    {
        // 1. Build URL
        $url = "https://www.amazon.it/s?k={$isbn}";

        // 2. Fetch HTML
        $html = $this->fetchHtml($url);
        if (!$html) {
            return null;
        }

        // 3. Parse HTML with DOMXPath
        $xpath = $this->getDomXPath($html);
        if (!$xpath) {
            return null;
        }

        // 4. Extract data using XPath queries
        $data = [];

        $data['title'] = $this->extractText(
            $xpath,
            "//h2[contains(@class, 's-title')]//span"
        );

        $data['author'] = $this->extractText(
            $xpath,
            "//span[contains(@class, 'author')]//a"
        );

        $data['publisher'] = $this->extractText(
            $xpath,
            "//div[contains(text(), 'Editore')]/following-sibling::div"
        );

        // Extract year from publication date
        $yearText = $this->extractText(
            $xpath,
            "//div[contains(text(), 'Data di pubblicazione')]/following-sibling::div"
        );
        if ($yearText && preg_match('/(\d{4})/', $yearText, $matches)) {
            $data['year'] = (int)$matches[1];
        }

        // Extract pages
        $pagesText = $this->extractText(
            $xpath,
            "//div[contains(text(), 'Pagine')]/following-sibling::div"
        );
        if ($pagesText && preg_match('/(\d+)/', $pagesText, $matches)) {
            $data['pages'] = (int)$matches[1];
        }

        $data['language'] = $this->extractText(
            $xpath,
            "//div[contains(text(), 'Lingua')]/following-sibling::div"
        );

        $data['description'] = $this->extractText(
            $xpath,
            "//div[@id='productDescription']//p"
        );

        // Cover image
        $coverNodes = $xpath->query("//img[@data-image-latency='s-product-image']");
        if ($coverNodes && $coverNodes->length > 0) {
            $data['cover_url'] = $coverNodes->item(0)->getAttribute('src');
        }

        // Price
        $priceText = $this->extractText(
            $xpath,
            "//span[@class='a-price']//span[@class='a-offscreen']"
        );
        if ($priceText && preg_match('/(\d+[,.]?\d*)/', $priceText, $matches)) {
            $data['price'] = str_replace(',', '.', $matches[1]);
        }

        $data['isbn'] = $isbn;

        // 5. Check if we got at least the title
        if (empty($data['title'])) {
            return null;
        }

        // 6. Normalize and return
        return $this->normalizeBookData($data);
    }
}
```

### Step 2: Register in Configuration

Add your scraper to `config/scrapers.php`:

```php
<?php
return [
    'scrapers' => [
        // ... existing scrapers ...

        [
            'name' => 'amazon-it',
            'class' => AmazonItScraper::class,
            'priority' => 8,    // Higher = tried first
            'enabled' => true,  // Enable/disable
        ],
    ],
];
```

### Step 3: Test Your Scraper

Test your new scraper:

```bash
# Create test API key (via admin interface or database)
# Then test:
curl -H "X-API-Key: YOUR_KEY" \
     http://localhost:8000/api/books/9788804710707
```

---

## 🔧 Configuration Options

| Option | Type | Description | Example |
|--------|------|-------------|---------|
| `name` | string | Unique scraper identifier | `'amazon-it'` |
| `class` | string | Scraper class name | `AmazonItScraper::class` |
| `priority` | int | Execution order (higher = first) | `10` |
| `enabled` | bool | Enable/disable scraper | `true` |

### Priority System

Scrapers are executed in **descending priority order** until one succeeds:

```php
'priority' => 10,  // Tried first
'priority' => 8,   // Tried second
'priority' => 5,   // Tried third
```

**Example:**
```php
LibreriaUniversitaria (priority: 10) → tries first
Amazon.it (priority: 8) → tries if first fails
Feltrinelli (priority: 5) → tries if both fail
```

---

## 🛠️ AbstractScraper Helper Methods

Your scraper extends `AbstractScraper` which provides useful methods:

### HTTP Fetching

```php
// Fetch HTML from URL
$html = $this->fetchHtml($url);
// Returns: string|null
```

**Features:**
- Automatic timeout handling
- User-Agent spoofing
- Follows redirects
- Gzip decompression
- Returns null on failure

### DOM Parsing

```php
// Create DOMXPath from HTML
$xpath = $this->getDomXPath($html);
// Returns: DOMXPath|null
```

### Text Extraction

```php
// Extract text from XPath query
$title = $this->extractText($xpath, "//h1[@class='title']");
// Returns: string|null (trimmed, whitespace normalized)
```

### Text Cleaning

```php
// Clean text (remove extra whitespace)
$clean = $this->cleanText($text);
// "  Multiple   spaces  " → "Multiple spaces"
```

### Data Normalization

```php
// Normalize book data to standard format
$normalized = $this->normalizeBookData($data);
// Adds: isbn, scraper, scraped_at
// Returns: array
```

---

## 📊 Data Structure

Your scraper should return an array with these fields:

### Required Fields

```php
[
    'isbn' => '9788804710707',
    'title' => 'Il nome della rosa',
]
```

### Recommended Fields

```php
[
    'author' => 'Umberto Eco',
    'publisher' => 'Bompiani',
    'year' => 2016,
    'pages' => 503,
    'language' => 'Italiano',
    'description' => '...',
    'cover_url' => 'https://...',
    'price' => '12.00',
]
```

### Optional Fields

```php
[
    'subtitle' => 'A Novel',
    'series' => 'Mystery Series',
    'format' => 'Paperback',
    'weight' => '450g',
    'dimensions' => '20x14x3cm',
]
```

**Note:** `normalizeBookData()` automatically adds:
- `scraper` - Your scraper's name
- `scraped_at` - Timestamp

---

## 💡 XPath Examples

### Common Patterns

```php
// Title in h1
"//h1[@class='book-title']"

// Author link
"//a[@class='author-link']"

// First paragraph in description
"//div[@id='description']//p[1]"

// Image src attribute
$nodes = $xpath->query("//img[@class='cover']");
$url = $nodes->item(0)->getAttribute('src');

// Text following a label
"//span[contains(text(), 'Publisher')]/following-sibling::span"

// Multiple authors
$nodes = $xpath->query("//a[@class='author']");
foreach ($nodes as $node) {
    $authors[] = $this->cleanText($node->textContent);
}
```

### Extract Numbers

```php
// Extract year from date string
$dateText = $this->extractText($xpath, "//span[@class='date']");
if (preg_match('/(\d{4})/', $dateText, $matches)) {
    $data['year'] = (int)$matches[1];
}

// Extract pages
$pagesText = "Pagine: 503 pagine";
if (preg_match('/(\d+)/', $pagesText, $matches)) {
    $data['pages'] = (int)$matches[1];
}

// Extract price
$priceText = "€ 12,50";
if (preg_match('/(\d+[,.]?\d*)/', $priceText, $matches)) {
    $data['price'] = str_replace(',', '.', $matches[1]);
}
```

---

## 🚨 Error Handling

Your scraper should handle errors gracefully:

```php
public function scrape(string $isbn): ?array
{
    // Return null if book not found
    $html = $this->fetchHtml($url);
    if (!$html) {
        return null;  // Not an error - just not found
    }

    // Return null if required data missing
    if (empty($data['title'])) {
        return null;
    }

    // Let exceptions bubble up for unexpected errors
    // ScraperManager will catch and log them
    if (!$someRequirement) {
        throw new Exception('Unexpected condition');
    }

    return $this->normalizeBookData($data);
}
```

**Rules:**
- Return `null` when book is not found (normal case)
- Return `null` when data extraction fails (missing title)
- Throw `Exception` for unexpected errors (will be logged)

---

## 🔍 Testing Your Scraper

### 1. Manual Test

```php
<?php
require 'src/bootstrap.php';

$scraper = new AmazonItScraper();
$result = $scraper->scrape('9788804710707');

if ($result) {
    echo "✓ Success!\n";
    print_r($result);
} else {
    echo "✗ Failed - no data\n";
}
```

### 2. Via API

```bash
# Start server
php -S localhost:8000 -t public/

# Test endpoint
curl -H "X-API-Key: YOUR_KEY" \
     http://localhost:8000/api/books/9788804710707
```

### 3. Check Logs

```bash
# View access log
tail -f logs/access.log

# View error log
tail -f logs/error.log
```

---

## 🎨 Best Practices

### 1. Be Specific with XPath

```php
// ✗ Too generic
"//h1"

// ✓ Specific class
"//h1[@class='product-title']"

// ✓ Multiple fallbacks
"//h1[@class='title'] | //h1[@id='book-title'] | //div[@class='main']//h1"
```

### 2. Handle Missing Data

```php
// ✗ Assumes data exists
$data['pages'] = (int)$pagesText;

// ✓ Checks first
if ($pagesText && preg_match('/(\d+)/', $pagesText, $matches)) {
    $data['pages'] = (int)$matches[1];
}
```

### 3. Normalize URLs

```php
// ✗ Relative URL
$data['cover_url'] = '/images/book.jpg';

// ✓ Absolute URL
if ($coverUrl && !str_starts_with($coverUrl, 'http')) {
    $coverUrl = 'https://www.example.com' . $coverUrl;
}
$data['cover_url'] = $coverUrl;
```

### 4. Return Early

```php
// ✓ Fail fast
if (!$html) {
    return null;
}

if (!$xpath) {
    return null;
}

if (empty($data['title'])) {
    return null;
}

return $this->normalizeBookData($data);
```

---

## 📚 Real-World Example

See the existing scrapers for reference:

- **LibreriaUniversitariaScraper** - Italian bookstore
- **FeltrinelliScraper** - Italian bookstore

Study these to understand:
- XPath patterns for Italian book sites
- How to handle lazy-loaded images
- Price extraction with European formatting
- Multi-language handling

---

## 🤝 Contributing

When contributing a new scraper:

1. ✅ Test with multiple ISBNs (at least 5)
2. ✅ Handle both found and not-found cases
3. ✅ Extract at minimum: title, author, isbn
4. ✅ Normalize URLs (absolute paths)
5. ✅ Clean text (remove extra whitespace)
6. ✅ Set appropriate priority (based on reliability)
7. ✅ Document any site-specific quirks

---

## 🎉 Summary

Adding a scraper is just **2 steps**:

1. Create class in `src/Scraping/Scrapers/`
2. Register in `config/scrapers.php`

No other code changes needed! The architecture handles:
- ✅ Auto-loading your class
- ✅ Instantiation
- ✅ Priority ordering
- ✅ Error handling
- ✅ Logging
- ✅ Fallback to next scraper

Happy scraping! 🔍📚
