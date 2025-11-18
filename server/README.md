# API Book Scraper Server

**Version:** 2.0.0

Standalone PHP server for scraping book metadata from Italian bookstores (Libreria Universitaria, Feltrinelli).

## 🚀 Features

- **Standalone**: No framework dependencies (no Slim, Laravel, etc.)
- **Modular Architecture**: Clean separation of concerns (MVC-inspired)
- **Easy to Extend**: Add new scrapers in 2 simple steps (see [ADDING_SCRAPERS.md](ADDING_SCRAPERS.md))
- **Multiple API Keys**: SQLite-based key management
- **File-based Rate Limiting**: High limits with simple file storage
- **Statistics Tracking**: Monitor usage and performance
- **Apache Compatible**: Works on shared hosting
- **Simple Admin Interface**: Web-based key management
- **Compatible**: Works with existing api-book-scraper plugin without modifications

## 🏗️ Architecture

**New in v2.0:** Refactored with clean architecture!

```
src/
├── Router.php              # HTTP routing
├── Response.php            # JSON response handler
├── Config.php              # Configuration manager
├── Middleware/             # Authentication, rate limiting
├── Controllers/            # HTTP request handlers
├── Services/               # Business logic
├── Scraping/               # ⭐ Scraping layer (separate!)
│   ├── ScraperManager.php  # Manages scraper execution
│   ├── ScraperRegistry.php # Scraper registration
│   └── Scrapers/           # Individual scrapers
├── Database.php            # SQLite database
└── RateLimit.php           # File-based rate limiter

config/
└── scrapers.php            # ⭐ Scraper configuration
```

**Benefits:**
- ✅ Add scrapers without modifying core code
- ✅ Configurable scraper priority and enable/disable
- ✅ Testable components
- ✅ Maintainable codebase

## 📋 Requirements

- PHP 8.0+
- Apache with mod_rewrite
- SQLite3 extension
- cURL extension
- DOM extension

## 📦 Installation

### 1. Upload Files

Upload the entire `/server` directory to your hosting:

```bash
/server
├── public/          # Document root (point Apache here)
├── src/             # Application logic
├── data/            # SQLite database and rate limits
├── logs/            # Log files
├── .env.example     # Configuration template
└── admin.php        # Admin interface
```

### 2. Configure Apache

Point your domain/subdomain document root to `/server/public/`.

**Example Apache vhost:**

```apache
<VirtualHost *:80>
    ServerName api.yourdomain.com
    DocumentRoot /path/to/server/public

    <Directory /path/to/server/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 3. Create Configuration

```bash
cd /path/to/server
cp .env.example .env
```

Edit `.env` and configure:

```env
# Generate admin password hash:
# php -r "echo password_hash('your_secure_password', PASSWORD_DEFAULT);"

ADMIN_PASSWORD_HASH=your_generated_hash_here
```

### 4. Set Permissions

```bash
chmod 755 public/
chmod 755 data/
chmod 755 logs/
chmod 644 .env
```

### 5. Initialize Database

The database will be created automatically on first request. To manually initialize:

```bash
php -r "require 'src/Database.php'; Database::getInstance();"
```

## 🔑 Creating API Keys

### Via Admin Interface

1. Navigate to `https://yourdomain.com/admin.php` (or `http://yourdomain.com:port/admin.php` for local)
2. Login with credentials from `.env`
3. Use "Create New API Key" form
4. Copy the generated API key (shown only once!)

### Via Command Line

```php
<?php
require 'src/Database.php';

// Load .env
$env = parse_ini_file('.env');
foreach ($env as $key => $value) {
    $_ENV[$key] = $value;
}

$apiKey = Database::createApiKey('Pinakes Production', 'Main production key');
echo "API Key: {$apiKey}\n";
```

## 📖 API Usage

### Authentication

Provide API key via:

**1. Authorization Header (Recommended)**
```bash
curl -H "Authorization: Bearer YOUR_API_KEY" \
     https://api.yourdomain.com/api/books/9788804710707
```

**2. X-API-Key Header**
```bash
curl -H "X-API-Key: YOUR_API_KEY" \
     https://api.yourdomain.com/api/books/9788804710707
```

**3. Query Parameter**
```bash
curl "https://api.yourdomain.com/api/books/9788804710707?api_key=YOUR_API_KEY"
```

### Endpoints

#### Health Check (No Auth)
```
GET /health
```

**Response:**
```json
{
  "status": "ok",
  "timestamp": "2025-11-18T10:30:00+00:00",
  "version": "1.0.0"
}
```

#### Get Book Metadata
```
GET /api/books/{isbn}
```

**Response (Success):**
```json
{
  "success": true,
  "data": {
    "isbn": "9788804710707",
    "title": "Il nome della rosa",
    "author": "Umberto Eco",
    "publisher": "Bompiani",
    "year": 2016,
    "pages": 503,
    "language": "Italiano",
    "description": "...",
    "cover_url": "https://...",
    "price": "12.00",
    "scraper": "LibreriaUniversitaria",
    "scraped_at": "2025-11-18 10:30:00"
  },
  "meta": {
    "response_time_ms": 1234,
    "remaining_requests": 998
  }
}
```

**Response (Not Found):**
```json
{
  "success": false,
  "error": "Book not found or could not be scraped.",
  "timestamp": "2025-11-18T10:30:00+00:00"
}
```

#### Get Statistics
```
GET /api/stats?limit=100&offset=0
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "api_key": "abc123...",
      "isbn": "9788804710707",
      "success": 1,
      "scraper_used": "LibreriaUniversitaria",
      "response_time_ms": 1234,
      "created_at": "2025-11-18 10:30:00"
    }
  ],
  "meta": {
    "limit": 100,
    "offset": 0,
    "count": 1
  }
}
```

## ⚙️ Configuration

### Rate Limiting

Default: **1000 requests per hour per API key**

Edit `.env`:
```env
RATE_LIMIT_ENABLED=true
RATE_LIMIT_REQUESTS_PER_HOUR=1000
```

### Scraper Settings

```env
SCRAPER_TIMEOUT=10
SCRAPER_USER_AGENT="Mozilla/5.0 (compatible; PinakesBot/1.0)"
```

### CORS

```env
CORS_ENABLED=true
CORS_ALLOWED_ORIGINS=*
```

For production, restrict origins:
```env
CORS_ALLOWED_ORIGINS=https://your-pinakes-domain.com
```

## 🔒 Security

- ✅ API key authentication
- ✅ Rate limiting (file-based)
- ✅ Input validation (ISBN format)
- ✅ SQL injection protection (prepared statements)
- ✅ XSS protection (no user content rendering)
- ✅ Directory listing disabled
- ✅ Sensitive files protected (.env, logs)

### Security Checklist

- [ ] Change default admin password
- [ ] Generate secure admin password hash
- [ ] Set restrictive file permissions (644 for files, 755 for dirs)
- [ ] Enable HTTPS (Let's Encrypt recommended)
- [ ] Restrict CORS origins in production
- [ ] Monitor logs regularly
- [ ] Keep PHP updated

## 📊 Monitoring

### Logs

**Access Log:** `logs/access.log`
```
[2025-11-18 10:30:00] Book lookup {"isbn":"9788804710707","api_key_id":1}
```

**Error Log:** `logs/error.log`
```
[2025-11-18 10:30:00] Scraper error {"scraper":"Feltrinelli","isbn":"...","error":"..."}
```

### Statistics

Access via admin interface or API endpoint `/api/stats`.

## 🔌 Plugin Integration

### Configure Plugin

In Pinakes admin panel:

1. Go to **Admin → Plugins → API Book Scraper**
2. Configure settings:
   - **API URL**: `https://api.yourdomain.com/api/books`
   - **API Key**: Your generated API key
   - **Timeout**: `10` (seconds)

The plugin will automatically use the server without modifications.

### Example Plugin Request

The plugin sends:
```
GET https://api.yourdomain.com/api/books/9788804710707
Authorization: Bearer YOUR_API_KEY
```

Server responds with normalized book data.

## 🛠️ Troubleshooting

### "API key required" Error

**Cause**: Missing or invalid authentication

**Solution**: Ensure API key is provided via Authorization header, X-API-Key header, or query parameter.

### "Rate limit exceeded" Error

**Cause**: Too many requests in 1 hour

**Solution**: Wait for rate limit window to reset, or increase limit in `.env`.

### "Book not found" Error

**Cause**: Book doesn't exist or scrapers failed

**Solution**: Check ISBN validity, verify bookstore websites are accessible.

### Database Errors

**Cause**: Database file not writable

**Solution**:
```bash
chmod 755 data/
chmod 644 data/api_keys.db  # if exists
```

### Apache 500 Error

**Cause**: PHP errors or missing extensions

**Solution**:
1. Check `logs/error.log`
2. Verify PHP version: `php -v` (must be 8.0+)
3. Check extensions: `php -m | grep -E "pdo|sqlite|curl|dom"`

## 🚀 Deployment Checklist

- [ ] Upload all files to hosting
- [ ] Point Apache document root to `/server/public/`
- [ ] Copy `.env.example` to `.env`
- [ ] Generate secure admin password hash
- [ ] Set file permissions (755 dirs, 644 files)
- [ ] Test health endpoint: `curl https://yourdomain.com/health`
- [ ] Create first API key via admin interface
- [ ] Test book lookup with API key
- [ ] Configure plugin with API URL and key
- [ ] Enable HTTPS (Let's Encrypt)
- [ ] Monitor logs for first 24 hours

## 📝 Changelog

### Version 1.0.0 (2025-11-18)

- ✨ Initial release
- 🔑 Multiple API key support (SQLite)
- 🚦 File-based rate limiting
- 📊 Statistics tracking
- 🎨 Simple admin interface
- 🔍 LibreriaUniversitaria scraper
- 🔍 Feltrinelli scraper
- 📖 Complete API documentation

## 🔧 Adding New Scrapers

**New in v2.0!** Adding scrapers is now super easy:

### Step 1: Create Scraper Class

Create `src/Scraping/Scrapers/YourScraper.php`:

```php
<?php
class YourScraper extends AbstractScraper
{
    public function getName(): string
    {
        return 'Your Scraper Name';
    }

    public function scrape(string $isbn): ?array
    {
        // Scraping logic here
        return $this->normalizeBookData($data);
    }
}
```

### Step 2: Register in Config

Add to `config/scrapers.php`:

```php
[
    'name' => 'your-scraper',
    'class' => YourScraper::class,
    'priority' => 7,
    'enabled' => true,
]
```

### Done!

No core code modifications needed. See [ADDING_SCRAPERS.md](ADDING_SCRAPERS.md) for detailed guide.

## 📄 License

GPL-3.0-only - Same as Pinakes project

---

## 📚 Documentation

- [Installation Guide](INSTALL.md) - Detailed installation instructions
- [Adding Scrapers](ADDING_SCRAPERS.md) - How to add new book scrapers
- [Compatibility Test](COMPATIBILITY_TEST.md) - Plugin compatibility verification
- [Refactoring Plan](REFACTORING_PLAN.md) - Architecture design document

**Need Help?** Check logs in `/server/logs/` or open an issue on GitHub.
