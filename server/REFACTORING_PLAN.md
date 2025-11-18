# Piano di Refactoring - API Book Scraper Server

## 🎯 Obiettivo

Separare la logica del server (routing, autenticazione, rate limiting) dalla logica di scraping per:
- ✅ Semplificare l'aggiunta di nuovi scrapers
- ✅ Migliorare la manutenibilità del codice
- ✅ Rendere il sistema più testabile
- ✅ Permettere configurazione flessibile degli scrapers

---

## 📊 Architettura Attuale vs Proposta

### ❌ Architettura Attuale (Problemi)

```
public/index.php (500+ righe)
├── Routing manuale (if/preg_match)
├── Autenticazione inline
├── Rate limiting inline
├── Logica scraping hardcoded
├── Response formatting inline
└── Gestione errori sparsa

src/Scrapers/
├── AbstractScraper.php
├── LibreriaUniversitariaScraper.php
└── FeltrinelliScraper.php

⚠️ Problemi:
- Tutto in un file (index.php)
- Per aggiungere un scraper bisogna modificare index.php
- Difficile testare componenti singoli
- Nessuna configurazione centralizzata
- Scrapers hardcoded (new LibreriaUniversitariaScraper())
```

### ✅ Architettura Proposta (Separation of Concerns)

```
public/index.php (20-30 righe)
└── Bootstrap + Router invocation

src/
├── Router.php                    # Routing system
├── Response.php                  # JSON response handler
├── Config.php                    # Configuration loader
│
├── Middleware/                   # Middleware layer
│   ├── AuthMiddleware.php       # Authentication
│   └── RateLimitMiddleware.php  # Rate limiting
│
├── Controllers/                  # Controllers (thin layer)
│   ├── ApiController.php        # API endpoints
│   ├── HealthController.php     # Health check
│   └── StatsController.php      # Statistics
│
├── Services/                     # Business logic
│   ├── BookService.php          # Book lookup logic
│   └── StatsService.php         # Statistics logic
│
├── Scraping/                     # Scraping layer (SEPARATO!)
│   ├── ScraperManager.php       # Gestisce scrapers
│   ├── ScraperRegistry.php      # Registry pattern
│   └── Scrapers/
│       ├── AbstractScraper.php
│       ├── LibreriaUniversitariaScraper.php
│       ├── FeltrinelliScraper.php
│       ├── AmazonItScraper.php          # Esempio: facile aggiungere!
│       └── MondadoriStoreScraper.php    # Esempio: facile aggiungere!
│
├── Database.php                  # Già esistente
└── RateLimit.php                # Già esistente (usato da middleware)

config/
└── scrapers.php                  # Configurazione scrapers
```

---

## 🏗️ Nuovi Componenti da Creare

### 1. **Router** (`src/Router.php`)

**Responsabilità:** Gestire il routing delle richieste

```php
class Router
{
    private array $routes = [];

    public function get(string $pattern, callable|array $handler): void
    public function post(string $pattern, callable|array $handler): void
    public function match(string $method, string $uri): ?array
    public function dispatch(string $method, string $uri): void
}
```

**Esempio utilizzo:**
```php
$router = new Router();
$router->get('/health', [HealthController::class, 'index']);
$router->get('/api/books/{isbn}', [ApiController::class, 'getBook']);
$router->get('/api/stats', [StatsController::class, 'index']);
$router->dispatch($_SERVER['REQUEST_METHOD'], $requestUri);
```

---

### 2. **Response Handler** (`src/Response.php`)

**Responsabilità:** Gestire le risposte JSON

```php
class Response
{
    public static function json(array $data, int $status = 200): void
    public static function success(array $data, array $meta = []): void
    public static function error(string $message, int $status = 400): void
}
```

**Esempio utilizzo:**
```php
Response::success(['isbn' => '...', 'title' => '...'], ['response_time_ms' => 1234]);
Response::error('Book not found', 404);
```

---

### 3. **Config** (`src/Config.php`)

**Responsabilità:** Caricare e gestire configurazione

```php
class Config
{
    private static array $config = [];

    public static function load(string $envFile): void
    public static function get(string $key, mixed $default = null): mixed
    public static function has(string $key): bool
}
```

**Esempio utilizzo:**
```php
Config::load(__DIR__ . '/../.env');
$timeout = Config::get('SCRAPER_TIMEOUT', 10);
```

---

### 4. **Middleware Layer**

#### `src/Middleware/AuthMiddleware.php`

**Responsabilità:** Autenticazione API key

```php
class AuthMiddleware
{
    public function handle(): ?array // Ritorna key data o null
    public function getApiKey(): ?string
}
```

#### `src/Middleware/RateLimitMiddleware.php`

**Responsabilità:** Rate limiting

```php
class RateLimitMiddleware
{
    private RateLimit $rateLimit;

    public function handle(string $apiKey): bool
    public function getRemaining(string $apiKey): int
}
```

---

### 5. **ScraperManager** (`src/Scraping/ScraperManager.php`)

**Responsabilità:** Gestire l'esecuzione degli scrapers

```php
class ScraperManager
{
    private ScraperRegistry $registry;

    public function __construct(ScraperRegistry $registry)
    public function scrape(string $isbn): ?array
    public function getEnabledScrapers(): array
}
```

**Logica:**
```php
public function scrape(string $isbn): ?array
{
    $scrapers = $this->registry->getEnabledScrapers();

    foreach ($scrapers as $scraper) {
        try {
            $result = $scraper->scrape($isbn);
            if ($result) {
                return $result;
            }
        } catch (Exception $e) {
            // Log error, continua con prossimo scraper
            continue;
        }
    }

    return null; // Nessuno scraper ha trovato il libro
}
```

---

### 6. **ScraperRegistry** (`src/Scraping/ScraperRegistry.php`)

**Responsabilità:** Registrare e gestire scrapers disponibili

```php
class ScraperRegistry
{
    private array $scrapers = [];

    public function register(string $name, string $class, int $priority = 0, bool $enabled = true): void
    public function get(string $name): ?AbstractScraper
    public function getAll(): array
    public function getEnabledScrapers(): array // Ordinati per priorità
    public function disable(string $name): void
    public function enable(string $name): void
}
```

**Esempio utilizzo:**
```php
$registry = new ScraperRegistry();
$registry->register('libreria-universitaria', LibreriaUniversitariaScraper::class, 10, true);
$registry->register('feltrinelli', FeltrinelliScraper::class, 5, true);
$registry->register('amazon', AmazonItScraper::class, 8, false); // Disabilitato

$scrapers = $registry->getEnabledScrapers();
// Ritorna [LibreriaUniversitariaScraper, AmazonItScraper, FeltrinelliScraper] ordinati per priorità
```

---

### 7. **Services Layer**

#### `src/Services/BookService.php`

**Responsabilità:** Logica di business per book lookup

```php
class BookService
{
    private ScraperManager $scraperManager;
    private Database $db;

    public function findByIsbn(string $isbn, string $apiKey): ?array
    private function validateIsbn(string $isbn): bool
}
```

**Logica:**
```php
public function findByIsbn(string $isbn, string $apiKey): ?array
{
    if (!$this->validateIsbn($isbn)) {
        throw new ValidationException('Invalid ISBN format');
    }

    $startTime = microtime(true);

    $bookData = $this->scraperManager->scrape($isbn);

    $responseTime = (int)((microtime(true) - $startTime) * 1000);

    // Log statistics
    Database::logStats($apiKey, $isbn, $bookData !== null, $bookData['scraper'] ?? null, $responseTime);

    return $bookData;
}
```

#### `src/Services/StatsService.php`

**Responsabilità:** Gestione statistiche

```php
class StatsService
{
    public function getStats(int $limit = 100, int $offset = 0): array
    public function getStatsByApiKey(string $apiKey): array
}
```

---

### 8. **Controllers Layer**

#### `src/Controllers/ApiController.php`

**Responsabilità:** Gestire richieste API

```php
class ApiController
{
    private BookService $bookService;
    private AuthMiddleware $auth;
    private RateLimitMiddleware $rateLimit;

    public function getBook(array $params): void
    {
        // 1. Autentica
        $keyData = $this->auth->handle();
        if (!$keyData) {
            Response::error('Invalid API key', 403);
        }

        // 2. Rate limit
        if (!$this->rateLimit->handle($keyData['api_key'])) {
            Response::error('Rate limit exceeded', 429);
        }

        // 3. Business logic
        $isbn = $params['isbn'];
        $bookData = $this->bookService->findByIsbn($isbn, $keyData['api_key']);

        if (!$bookData) {
            Response::error('Book not found', 404);
        }

        // 4. Response
        Response::success($bookData, [
            'remaining_requests' => $this->rateLimit->getRemaining($keyData['api_key'])
        ]);
    }
}
```

#### `src/Controllers/HealthController.php`

```php
class HealthController
{
    public function index(): void
    {
        Response::json([
            'status' => 'ok',
            'timestamp' => date('c'),
            'version' => '2.0.0'
        ]);
    }
}
```

---

### 9. **Configurazione Scrapers** (`config/scrapers.php`)

**Responsabilità:** Configurare scrapers disponibili

```php
<?php
return [
    'scrapers' => [
        [
            'name' => 'libreria-universitaria',
            'class' => LibreriaUniversitariaScraper::class,
            'priority' => 10,
            'enabled' => true,
            'timeout' => 10,
        ],
        [
            'name' => 'feltrinelli',
            'class' => FeltrinelliScraper::class,
            'priority' => 5,
            'enabled' => true,
            'timeout' => 10,
        ],
        [
            'name' => 'amazon-it',
            'class' => AmazonItScraper::class,
            'priority' => 8,
            'enabled' => false, // Disabilitato di default
            'timeout' => 15,
        ],
    ],
];
```

---

### 10. **Nuovo index.php** (`public/index.php`)

**Responsabilità:** Bootstrap e routing (20-30 righe)

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

// Create router
$router = new Router();

// Register routes
$router->get('/health', [HealthController::class, 'index']);
$router->get('/api', [ApiController::class, 'index']);
$router->get('/api/books/{isbn}', [ApiController::class, 'getBook']);
$router->get('/api/stats', [StatsController::class, 'index']);

// Handle OPTIONS for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Dispatch request
try {
    $router->dispatch($_SERVER['REQUEST_METHOD'], $requestUri);
} catch (Exception $e) {
    Response::error('Internal server error', 500);
}
```

---

### 11. **Bootstrap** (`src/bootstrap.php`)

**Responsabilità:** Inizializzazione applicazione

```php
<?php
// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Autoloader
require __DIR__ . '/autoloader.php';

// Load configuration
Config::load(__DIR__ . '/../.env');

// Initialize scraper registry
$registry = new ScraperRegistry();
$scrapersConfig = require __DIR__ . '/../config/scrapers.php';

foreach ($scrapersConfig['scrapers'] as $scraper) {
    $registry->register(
        $scraper['name'],
        $scraper['class'],
        $scraper['priority'],
        $scraper['enabled']
    );
}

// Make registry globally available
$GLOBALS['scraperRegistry'] = $registry;
```

---

## 📝 Step di Implementazione

### Fase 1: Fondamenta (Core Infrastructure)
1. ✅ Creare `src/Config.php`
2. ✅ Creare `src/Response.php`
3. ✅ Creare `src/Router.php`
4. ✅ Creare `src/bootstrap.php`
5. ✅ Creare `src/autoloader.php`

### Fase 2: Scraping Layer (Separazione!)
6. ✅ Creare `src/Scraping/ScraperRegistry.php`
7. ✅ Creare `src/Scraping/ScraperManager.php`
8. ✅ Spostare scrapers in `src/Scraping/Scrapers/`
9. ✅ Creare `config/scrapers.php`

### Fase 3: Middleware
10. ✅ Creare `src/Middleware/AuthMiddleware.php`
11. ✅ Creare `src/Middleware/RateLimitMiddleware.php`

### Fase 4: Services
12. ✅ Creare `src/Services/BookService.php`
13. ✅ Creare `src/Services/StatsService.php`

### Fase 5: Controllers
14. ✅ Creare `src/Controllers/HealthController.php`
15. ✅ Creare `src/Controllers/ApiController.php`
16. ✅ Creare `src/Controllers/StatsController.php`

### Fase 6: Integration
17. ✅ Refactorare `public/index.php` (nuovo slim version)
18. ✅ Testare tutti gli endpoints
19. ✅ Verificare compatibilità con plugin

### Fase 7: Documentation
20. ✅ Aggiornare `README.md`
21. ✅ Creare `ADDING_SCRAPERS.md` (guida per aggiungere scrapers)
22. ✅ Aggiornare `INSTALL.md`

---

## 🎁 Vantaggi della Nuova Architettura

### 1. Facile Aggiungere Scrapers

**Prima (complesso):**
```php
// Bisognava modificare index.php, linea ~140
$scrapers = [
    new LibreriaUniversitariaScraper(),
    new FeltrinelliScraper(),
    new NuovoScraper(), // ← Aggiunto qui manualmente
];
```

**Dopo (semplicissimo):**
```php
// 1. Creare il file src/Scraping/Scrapers/NuovoScraper.php
class NuovoScraper extends AbstractScraper { ... }

// 2. Registrare in config/scrapers.php
[
    'name' => 'nuovo-scraper',
    'class' => NuovoScraper::class,
    'priority' => 7,
    'enabled' => true,
],

// 3. Fine! Nessuna modifica al codice core
```

### 2. Configurazione Flessibile

```php
// Disabilitare uno scraper
// config/scrapers.php, cambia solo 'enabled' => false

// Cambiare priorità (ordine di esecuzione)
'priority' => 15, // Questo scraper viene provato per primo

// Timeout personalizzato per scraper
'timeout' => 20,
```

### 3. Testabilità

```php
// Test BookService
$mockScraperManager = new MockScraperManager();
$service = new BookService($mockScraperManager);
$result = $service->findByIsbn('9788804710707', 'test-key');
assert($result['title'] === 'Il nome della rosa');

// Test singolo scraper
$scraper = new LibreriaUniversitariaScraper();
$result = $scraper->scrape('9788804710707');
assert($result !== null);
```

### 4. Separation of Concerns

| Componente | Responsabilità | Modifica Richiesta Per |
|------------|----------------|------------------------|
| Router | Routing | Aggiungere endpoint |
| AuthMiddleware | Autenticazione | Cambiare auth method |
| RateLimitMiddleware | Rate limiting | Cambiare rate limits |
| ScraperManager | Gestione scrapers | Mai (configurazione!) |
| ScraperRegistry | Registry scrapers | Mai (configurazione!) |
| BookService | Business logic | Cambiare logica lookup |
| ApiController | HTTP handling | Cambiare formato richiesta |

### 5. Manutenibilità

```
Prima: index.php = 500 righe (tutto insieme)
Dopo:
  - index.php = 30 righe
  - Router.php = 80 righe
  - Response.php = 40 righe
  - AuthMiddleware.php = 50 righe
  - ScraperManager.php = 60 righe
  - BookService.php = 80 righe
  - ApiController.php = 100 righe

Più facile leggere, capire, modificare!
```

---

## 📖 Esempio: Aggiungere Amazon Scraper

### 1. Creare lo Scraper

**File:** `src/Scraping/Scrapers/AmazonItScraper.php`

```php
<?php
class AmazonItScraper extends AbstractScraper
{
    public function getName(): string
    {
        return 'Amazon.it';
    }

    public function scrape(string $isbn): ?array
    {
        $url = "https://www.amazon.it/s?k={$isbn}";
        $html = $this->fetchHtml($url);

        if (!$html) {
            return null;
        }

        $xpath = $this->getDomXPath($html);

        $data = [];
        $data['title'] = $this->extractText($xpath, "//h2[@class='s-title']");
        $data['author'] = $this->extractText($xpath, "//span[@class='author']");
        // ... altri campi

        return $this->normalizeBookData($data);
    }
}
```

### 2. Registrare in Config

**File:** `config/scrapers.php`

```php
[
    'name' => 'amazon-it',
    'class' => AmazonItScraper::class,
    'priority' => 8,
    'enabled' => true,
    'timeout' => 15,
],
```

### 3. Fine!

Non serve modificare:
- ❌ index.php
- ❌ Router.php
- ❌ ApiController.php
- ❌ BookService.php
- ❌ ScraperManager.php

Il nuovo scraper sarà automaticamente utilizzato! 🎉

---

## ✅ Checklist Refactoring

- [ ] Fase 1: Core Infrastructure (5 files)
- [ ] Fase 2: Scraping Layer (4 files)
- [ ] Fase 3: Middleware (2 files)
- [ ] Fase 4: Services (2 files)
- [ ] Fase 5: Controllers (3 files)
- [ ] Fase 6: Integration (refactor index.php)
- [ ] Fase 7: Documentation (3 files)
- [ ] Test compatibilità con plugin
- [ ] Test tutti gli endpoints
- [ ] Update COMPATIBILITY_TEST.md

---

## 🎯 Risultato Finale

**Server modulare, estensibile, manutenibile con separazione pulita tra:**
- 🔀 Routing
- 🔐 Autenticazione
- 🚦 Rate Limiting
- 📊 Business Logic
- 🔍 Scraping (SEPARATO e CONFIGURABILE!)
- 📡 HTTP Response Handling

**Facile aggiungere nuovi scrapers senza toccare il core!**
