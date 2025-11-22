# Plugin Hooks Reference - Pinakes

Questo documento elenca tutti gli hook disponibili nel sistema di plugin di Pinakes, con esempi pratici e parametri.

## Legenda

- 🟢 **Implementato** - Hook già integrato nel codice
- 🟡 **Documentato** - Hook pianificato, pronto per implementazione
- **Filter** - Hook che modifica e restituisce un valore
- **Action** - Hook che esegue codice senza restituire un valore

---

## 📖 Hook per Libri

### 🟢 `book.data.get` (Filter)
**Status:** Implementato
**File:** `app/Models/BookRepository.php:119`

Modifica i dati del libro quando vengono recuperati dal database.

**Parametri:**
- `$bookData` (array): Dati del libro dal database
- `$bookId` (int): ID del libro

**Restituisce:** array - Dati del libro modificati

**Esempio:**
```php
Hooks::add('book.data.get', function($bookData, $bookId) {
    // Aggiungi rating esterno
    $bookData['external_rating'] = getExternalRating($bookId);
    $bookData['goodreads_url'] = "https://goodreads.com/book/{$bookId}";
    return $bookData;
}, 10);
```

### 🟢 `book.save.before` (Action)
**Status:** Implementato
**File:** `app/Controllers/LibriController.php:403, 768`

Eseguito prima di salvare un libro (sia create che update).

**Parametri:**
- `$bookData` (array): Dati del libro da salvare
- `$bookId` (int|null): ID del libro (null se creazione)

**Esempio:**
```php
Hooks::add('book.save.before', function($bookData, $bookId) {
    // Validazione custom
    if (empty($bookData['isbn13'])) {
        throw new Exception('ISBN13 required');
    }

    // Log operazione
    error_log("Saving book: " . ($bookId ?? 'new'));
}, 10);
```

### 🟢 `book.save.after` (Action)
**Status:** Implementato
**File:** `app/Controllers/LibriController.php:408, 773`

Eseguito dopo aver salvato un libro (sia create che update).

**Parametri:**
- `$bookId` (int): ID del libro salvato
- `$bookData` (array): Dati del libro salvati

**Esempio:**
```php
Hooks::add('book.save.after', function($bookId, $bookData) {
    // Sync con API esterna
    syncWithGoodreads($bookId, $bookData);

    // Invalida cache
    clearBookCache($bookId);

    // Invia notifica
    notifyAdmins("Nuovo libro aggiunto: {$bookData['titolo']}");
}, 10);
```

### 🟢 `book.form.fields` (Action)
**Status:** Implementato
**File:** `app/Views/libri/partials/book_form.php:499`

Aggiunge campi personalizzati al form libro nel backend.

**Parametri:**
- `$bookData` (array|null): Dati del libro (null se creazione)
- `$bookId` (int|null): ID del libro (null se creazione)

**Esempio:**
```php
Hooks::add('book.form.fields', function($bookData, $bookId) {
    if ($bookId === null) return; // Solo in edit

    $rating = getExternalRating($bookId);
    ?>
    <div class="bg-white rounded-xl shadow-sm border p-6 mt-6">
        <h3 class="font-semibold mb-4">Rating Esterni</h3>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label>Goodreads Rating</label>
                <input type="number" step="0.1" name="goodreads_rating"
                       value="<?= $rating ?>" class="form-input">
            </div>
        </div>
    </div>
    <?php
}, 10);
```

### 🟢 `book.frontend.details` (Action)
**Status:** Implementato
**File:** `app/Views/frontend/book-detail.php:1593`

Aggiunge contenuto personalizzato nella pagina dettaglio libro pubblica.

**Parametri:**
- `$bookData` (array): Dati del libro
- `$bookId` (int): ID del libro

**Esempio:**
```php
Hooks::add('book.frontend.details', function($bookData, $bookId) {
    $rating = $bookData['external_rating'] ?? null;
    if (!$rating) return;
    ?>
    <div class="card mt-4">
        <div class="card-header">
            <h5><i class="fas fa-star"></i> Valutazioni Esterne</h5>
        </div>
        <div class="card-body">
            <div class="d-flex align-items-center">
                <div class="text-warning fs-1 me-3"><?= $rating ?>/5</div>
                <div>
                    <div class="text-muted">Goodreads</div>
                    <div><?= $bookData['external_ratings_count'] ?? 0 ?> valutazioni</div>
                </div>
            </div>
        </div>
    </div>
    <?php
}, 10);
```

### 🟡 `book.delete.before` (Action)
**Status:** Documentato

Eseguito prima di eliminare un libro.

**Parametri:**
- `$bookId` (int): ID del libro da eliminare

**Esempio:**
```php
Hooks::add('book.delete.before', function($bookId) {
    // Cleanup dati esterni
    deleteExternalData($bookId);

    // Log eliminazione
    logBookDeletion($bookId);
}, 10);
```

### 🟡 `book.frontend.card` (Filter)
**Status:** Documentato

Modifica l'HTML della card libro nel catalogo pubblico.

**Parametri:**
- `$cardHtml` (string): HTML della card
- `$bookData` (array): Dati del libro

**Restituisce:** string - HTML modificato

---

## 🔐 Hook per Login & Autenticazione

### 🟢 `login.form.render.before` (Action)
**Status:** Implementato
**File:** `app/Controllers/AuthController.php:23`

Eseguito prima del rendering del form di login.

**Parametri:**
- `$request` (ServerRequestInterface): Oggetto richiesta

**Esempio:**
```php
Hooks::add('login.form.render.before', function($request) {
    // Track page view
    analytics()->trackPageView('login');

    // Set session data
    $_SESSION['login_attempt_time'] = time();
}, 10);
```

### 🟢 `login.form.html` (Filter)
**Status:** Implementato
**File:** `app/Controllers/AuthController.php:33`

Modifica l'HTML del form di login.

**Parametri:**
- `$html` (string): HTML del form
- `$request` (ServerRequestInterface): Oggetto richiesta

**Restituisce:** string - HTML modificato

**Esempio:**
```php
Hooks::add('login.form.html', function($html, $request) {
    // Add banner before form
    $banner = '<div class="alert alert-info">Maintenance window: 2am-4am</div>';
    return str_replace('<form', $banner . '<form', $html);
}, 10);
```

### 🟢 `login.form.fields` (Action)
**Status:** Implementato
**File:** `app/Views/auth/login.php:139`

Aggiunge campi personalizzati al form di login (es. reCAPTCHA, 2FA).

**Parametri:** Nessuno

**Esempio:**
```php
Hooks::add('login.form.fields', function() {
    $siteKey = getSetting('recaptcha_site_key');
    ?>
    <div class="mb-4">
        <div class="g-recaptcha" data-sitekey="<?= $siteKey ?>"></div>
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    </div>
    <?php
}, 10);
```

### 🟢 `login.validate` (Filter)
**Status:** Implementato
**File:** `app/Controllers/AuthController.php:77`

Validazione personalizzata durante il login (reCAPTCHA, 2FA, etc.).

**Parametri:**
- `$isValid` (bool): Risultato validazione predefinita
- `$email` (string): Email fornita
- `$request` (ServerRequestInterface): Oggetto richiesta

**Restituisce:** bool - true se valido, false altrimenti

**Esempio:**
```php
Hooks::add('login.validate', function($isValid, $email, $request) {
    if (!$isValid) return false; // Già fallito

    // Valida reCAPTCHA
    $response = $_POST['g-recaptcha-response'] ?? '';
    $secret = getSetting('recaptcha_secret');

    $verify = file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret={$secret}&response={$response}");
    $data = json_decode($verify);

    return $data->success === true;
}, 10);
```

### 🟢 `login.success` (Action)
**Status:** Implementato
**File:** `app/Controllers/AuthController.php:146`

Eseguito dopo un login riuscito.

**Parametri:**
- `$userId` (int): ID dell'utente
- `$userData` (array): Dati dell'utente dalla sessione
- `$request` (ServerRequestInterface): Oggetto richiesta

**Esempio:**
```php
Hooks::add('login.success', function($userId, $userData, $request) {
    // Analytics
    analytics()->track('login', [
        'user_id' => $userId,
        'user_type' => $userData['tipo_utente']
    ]);

    // Welcome email
    if (isFirstLogin($userId)) {
        sendWelcomeEmail($userData['email']);
    }

    // Update last login
    updateLastLogin($userId);
}, 10);
```

### 🟢 `login.failed` (Action)
**Status:** Implementato
**File:** `app/Controllers/AuthController.php:172`

Eseguito dopo un login fallito.

**Parametri:**
- `$email` (string): Email fornita
- `$request` (ServerRequestInterface): Oggetto richiesta

**Esempio:**
```php
Hooks::add('login.failed', function($email, $request) {
    // Track failed attempts
    incrementFailedAttempts($email);

    // Alert admins after 5 failures
    $failures = getFailedAttempts($email);
    if ($failures >= 5) {
        notifyAdmins("Multiple failed login attempts for: {$email}");
    }

    // Analytics
    analytics()->track('login_failed', ['email' => $email]);
}, 10);
```

---

## 👤 Hook per Autori

### 🟢 `author.data.get` (Filter)
**Status:** Implementato
**File:** `app/Models/AuthorRepository.php:36`

Modifica i dati dell'autore quando vengono recuperati.

**Parametri:**
- `$authorData` (array): Dati dell'autore
- `$authorId` (int): ID dell'autore

**Restituisce:** array - Dati dell'autore modificati

**Esempio:**
```php
Hooks::add('author.data.get', function($authorData, $authorId) {
    // Add social media
    $authorData['twitter'] = getAuthorTwitter($authorId);
    $authorData['instagram'] = getAuthorInstagram($authorId);

    // Add book count
    $authorData['total_books'] = countAuthorBooks($authorId);

    return $authorData;
}, 10);
```

### 🟢 `author.save.before` (Action)
**Status:** Implementato
**File:** `app/Models/AuthorRepository.php:131`

Eseguito prima di salvare un autore.

**Parametri:**
- `$authorData` (array): Dati dell'autore
- `$authorId` (int): ID dell'autore

**Esempio:**
```php
Hooks::add('author.save.before', function($authorData, $authorId) {
    // Validate biography length
    if (strlen($authorData['biografia'] ?? '') > 5000) {
        throw new Exception('Biografia troppo lunga (max 5000 caratteri)');
    }
}, 10);
```

### 🟢 `author.save.after` (Action)
**Status:** Implementato
**File:** `app/Models/AuthorRepository.php:162`

Eseguito dopo aver salvato un autore.

**Parametri:**
- `$authorId` (int): ID dell'autore
- `$authorData` (array): Dati dell'autore

**Esempio:**
```php
Hooks::add('author.save.after', function($authorId, $authorData) {
    // Sync with external database
    syncAuthorWithWorldcat($authorId, $authorData);

    // Clear cache
    clearAuthorCache($authorId);
}, 10);
```

### 🟡 `author.frontend.details` (Action)
**Status:** Documentato

Aggiunge contenuto nella pagina autore nel frontend.

**Parametri:**
- `$authorData` (array): Dati dell'autore
- `$authorId` (int): ID dell'autore

---

## 🏢 Hook per Editori

### 🟢 `publisher.data.get` (Filter)
**Status:** Implementato
**File:** `app/Models/PublisherRepository.php:35`

Modifica i dati dell'editore quando vengono recuperati.

**Parametri:**
- `$publisherData` (array): Dati dell'editore
- `$publisherId` (int): ID dell'editore

**Restituisce:** array - Dati dell'editore modificati

**Esempio:**
```php
Hooks::add('publisher.data.get', function($publisherData, $publisherId) {
    // Add statistics
    $publisherData['total_books'] = countPublisherBooks($publisherId);
    $publisherData['total_authors'] = countPublisherAuthors($publisherId);

    // Add external data
    $publisherData['wikipedia_url'] = getPublisherWikipediaUrl($publisherId);

    return $publisherData;
}, 10);
```

### 🟡 `publisher.save.before` (Action)
**Status:** Documentato

Eseguito prima di salvare un editore.

**Parametri:**
- `$publisherData` (array): Dati dell'editore
- `$publisherId` (int): ID dell'editore

### 🟡 `publisher.save.after` (Action)
**Status:** Documentato

Eseguito dopo aver salvato un editore.

**Parametri:**
- `$publisherId` (int): ID dell'editore
- `$publisherData` (array): Dati dell'editore

### 🟡 `publisher.frontend.details` (Action)
**Status:** Documentato

Aggiunge contenuto nella pagina editore nel frontend.

**Parametri:**
- `$publisherData` (array): Dati dell'editore
- `$publisherId` (int): ID dell'editore

---

## 🔍 Hook per Catalogo e Ricerca

### 🟡 `catalog.filters.render` (Action)
**Status:** Documentato

Aggiunge filtri personalizzati alla ricerca nel catalogo.

**Parametri:**
- `$currentFilters` (array): Filtri attualmente applicati

**Esempio:**
```php
Hooks::add('catalog.filters.render', function($currentFilters) {
    ?>
    <div class="filter-group">
        <label>Rating Minimo</label>
        <select name="min_rating" class="form-select">
            <option value="">Tutti</option>
            <option value="4">4+ stelle</option>
            <option value="3">3+ stelle</option>
        </select>
    </div>
    <?php
}, 10);
```

### 🟡 `catalog.query.modify` (Filter)
**Status:** Documentato

Modifica la query SQL per la ricerca libri.

**Parametri:**
- `$query` (string): Query SQL
- `$params` (array): Parametri della query

**Restituisce:** array - `['query' => string, 'params' => array]`

**Esempio:**
```php
Hooks::add('catalog.query.modify', function($query, $params) {
    $minRating = $_GET['min_rating'] ?? null;
    if ($minRating) {
        $query .= " AND external_rating >= ?";
        $params[] = (float)$minRating;
    }
    return ['query' => $query, 'params' => $params];
}, 10);
```

### 🟡 `catalog.results.modify` (Filter)
**Status:** Documentato

Modifica i risultati della ricerca prima della visualizzazione.

**Parametri:**
- `$results` (array): Array di risultati

**Restituisce:** array - Risultati modificati

---

## 🌐 Hook per Scraping

### 🟢 `scrape.isbn.validate` (Filter)
**Status:** Implementato
**File:** `app/Controllers/ScrapeController.php:27`

Permette validazione ISBN personalizzata (es: API online, database esterno).

**Parametri:**
- `$isValid` (bool): Risultato validazione predefinita
- `$isbn` (string): ISBN da validare
- `$source` (string): Fonte della richiesta (es. 'user_input')

**Restituisce:** bool - true se valido, false altrimenti

**Esempio:**
```php
Hooks::add('scrape.isbn.validate', function($isValid, $isbn, $source) {
    if ($isValid) return true; // Already valid

    // Fallback: validate with external API
    $response = file_get_contents("https://api.isbn-db.com/validate?isbn=$isbn");
    $data = json_decode($response);
    return $data->valid ?? false;
}, 5);
```

---

### 🟢 `scrape.sources` (Filter)
**Status:** Implementato
**File:** `app/Controllers/ScrapeController.php:40`

Aggiunge nuove fonti di scraping personalizzate (Amazon, IBS, Mondadori, API custom).

**Parametri:**
- `$sources` (array): Array di fonti disponibili
- `$isbn` (string): ISBN da scrapare

**Restituisce:** array - Fonti con nuove aggiunte

**Formato fonte:**
```php
[
    'source_key' => [
        'name' => 'Amazon',
        'url_pattern' => 'https://www.amazon.it/s?k={isbn}',
        'enabled' => true,
        'priority' => 15,
        'fields' => ['price', 'description'] // Optional: only these fields
    ]
]
```

**Esempio:**
```php
Hooks::add('scrape.sources', function($sources, $isbn) {
    $sources['amazon'] = [
        'name' => 'Amazon',
        'url_pattern' => 'https://www.amazon.it/s?k={isbn}',
        'enabled' => true,
        'priority' => 15,
        'fields' => ['price', 'description']
    ];
    return $sources;
}, 10);
```

---

### 🟢 `scrape.fetch.custom` (Filter)
**Status:** Implementato
**File:** `app/Controllers/ScrapeController.php:43`

Permette di sostituire completamente la logica di scraping.

**Parametri:**
- `$default` (null): Sempre null (valore predefinito)
- `$sources` (array): Fonti disponibili
- `$isbn` (string): ISBN da scrapare

**Restituisce:** array|null - Dati scrapati oppure null per usare logica predefinita

**Esempio:**
```php
Hooks::add('scrape.fetch.custom', function($default, $sources, $isbn) {
    // Use custom API instead of scraping HTML
    $response = json_decode(file_get_contents(
        "https://api.mycustom.com/books?isbn=$isbn&key=secret"
    ), true);

    return [
        'title' => $response['book_title'],
        'authors' => $response['authors'],
        'publisher' => $response['publisher'],
        'price' => $response['price'],
        'image' => $response['cover_url']
    ];
}, 5);
```

---

### 🟢 `scrape.before` (Action)
**Status:** Implementato
**File:** `app/Controllers/ScrapeController.php:70`

Eseguito prima di scrapare da una fonte (es: setup proxy, logging, cache check).

**Parametri:**
- `$source` (array): Informazioni fonte
- `$url` (string): URL che verrà scrapato
- `$isbn` (string): ISBN da scrapare

**Esempio:**
```php
Hooks::add('scrape.before', function($source, $url, $isbn) {
    // Setup proxy for Amazon
    if ($source['name'] === 'Amazon') {
        putenv('HTTP_PROXY=proxy.example.com:8080');
    }

    // Log scraping attempt
    error_log("Scraping $isbn from {$source['name']}");
}, 5);
```

---

### 🟢 `scrape.http.options` (Filter)
**Status:** Implementato
**File:** `app/Controllers/ScrapeController.php:87`

Customizza opzioni curl per fetch HTTP (headers, timeouts, user agents).

**Parametri:**
- `$curlOptions` (array): Opzioni CURL predefinite
- `$source` (array): Informazioni fonte
- `$url` (string): URL target

**Restituisce:** array - Opzioni CURL modificate

**Esempio:**
```php
Hooks::add('scrape.http.options', function($options, $source, $url) {
    if (strpos($url, 'amazon.it') !== false) {
        // Amazon blocks bots, use realistic headers
        $options[CURLOPT_HTTPHEADER] = [
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: it-IT,it;q=0.9',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
        ];
        $options[CURLOPT_REFERER] = 'https://www.google.com/';
        $options[CURLOPT_TIMEOUT] = 30;
    }
    return $options;
}, 5);
```

---

### 🟢 `scrape.after` (Action)
**Status:** Implementato
**File:** `app/Controllers/ScrapeController.php:107`

Eseguito dopo aver fetchato i dati grezzi (es: cache, cleanup, logging).

**Parametri:**
- `$rawData` (string): Dati grezzi fetchati (HTML, JSON, etc.)
- `$source` (array): Informazioni fonte
- `$isbn` (string): ISBN scrapato

**Esempio:**
```php
Hooks::add('scrape.after', function($rawData, $source, $isbn) {
    // Cache raw data for 7 days
    $cacheKey = "scrape_{$source['name']}_{$isbn}";
    cache()->set($cacheKey, $rawData, 60*60*24*7);

    // Log data size
    error_log("Fetched " . strlen($rawData) . " bytes from {$source['name']}");
}, 15);
```

---

### 🟢 `scrape.parse` (Filter)
**Status:** Implementato
**File:** `app/Controllers/ScrapeController.php:238`

Permette parsing personalizzato o modifica dei dati parsati.

**Parametri:**
- `$parsedData` (array): Dati parsati dal parser predefinito
- `$rawData` (string): Dati grezzi (HTML, JSON, etc.)
- `$source` (array): Informazioni fonte
- `$isbn` (string): ISBN

**Restituisce:** array - Dati parsati modificati

**Esempio:**
```php
Hooks::add('scrape.parse', function($parsed, $raw, $source, $isbn) {
    if ($source['name'] !== 'Amazon') {
        return $parsed;
    }

    // Custom Amazon parser
    $dom = new \DOMDocument();
    @$dom->loadHTML($raw);
    $xpath = new \DOMXPath($dom);

    $parsed['title'] = $xpath->query('//span[@id="productTitle"]')[0]->textContent ?? '';
    $parsed['price'] = preg_match('/€\s*(\d+,\d+)/', $raw, $m) ? str_replace(',', '.', $m[1]) : '';

    return $parsed;
}, 8);
```

---

### 🟢 `scrape.validate.data` (Filter)
**Status:** Implementato
**File:** `app/Controllers/ScrapeController.php:241`

Valida dati scrapati prima di usarli (es: format prezzi, date, ISBN).

**Parametri:**
- `$validation` (array): `['valid' => bool, 'errors' => [], 'data' => array]`
- `$parsedData` (array): Dati parsati
- `$source` (array): Informazioni fonte
- `$isbn` (string): ISBN

**Restituisce:** array - `['valid' => bool, 'errors' => [], 'data' => array]`

**Esempio:**
```php
Hooks::add('scrape.validate.data', function($validation, $data, $source, $isbn) {
    $errors = [];

    // Title required
    if (empty($data['title'])) {
        $errors[] = 'Title is required';
    }

    // Price must be numeric
    if (isset($data['price']) && !is_numeric($data['price'])) {
        $errors[] = 'Price must be numeric';
    }

    // Date format validation
    if (isset($data['pubDate'])) {
        if (!\DateTime::createFromFormat('Y-m-d', $data['pubDate'])) {
            $errors[] = 'Publication date must be Y-m-d format';
        }
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'data' => $data
    ];
}, 10);
```

---

### 🟢 `scrape.validation.failed` (Action)
**Status:** Implementato
**File:** `app/Controllers/ScrapeController.php:248`

Gestisce fallimento validazione dati scrapati.

**Parametri:**
- `$errors` (array): Array di errori validazione
- `$source` (array): Informazioni fonte
- `$isbn` (string): ISBN
- `$parsedData` (array): Dati che hanno fallito la validazione

**Esempio:**
```php
Hooks::add('scrape.validation.failed', function($errors, $source, $isbn, $data) {
    // Log validation errors
    error_log("Validation failed for ISBN $isbn from {$source['name']}: " . implode(', ', $errors));

    // Notify admins if critical source
    if ($source['priority'] < 5) {
        sendAdminNotification("Critical scraping validation failed: {$source['name']}");
    }
}, 10);
```

---

### 🟢 `scrape.data.modify` (Filter)
**Status:** Implementato
**File:** `app/Controllers/ScrapeController.php:259`

Modifica dati scrapati prima di ritornare (es: normalizzazione, enrichment, lookup).

**Parametri:**
- `$payload` (array): Dati da ritornare
- `$isbn` (string): ISBN
- `$source` (array): Informazioni fonte
- `$originalData` (array): Dati originali

**Restituisce:** array - Dati modificati

**Esempio:**
```php
Hooks::add('scrape.data.modify', function($payload, $isbn, $source) {
    // Enrich with Goodreads rating
    $goodreadsRating = fetchGoodreadsRating($payload['title'], $payload['authors'][0] ?? '');

    if ($goodreadsRating) {
        $payload['goodreads_rating'] = $goodreadsRating['rating'];
        $payload['goodreads_count'] = $goodreadsRating['count'];
    }

    // Normalize price format
    $payload['price'] = normalizePriceFormat($payload['price']);

    // Lookup genre
    $genre = lookupGenre($payload['title']);
    if ($genre) {
        $payload['suggested_genre'] = $genre;
    }

    return $payload;
}, 10);
```

---

### 🟢 `scrape.error` (Action)
**Status:** Implementato
**File:** `app/Controllers/ScrapeController.php:76`

Gestisce errori durante scraping (logging, alerting, fallback).

**Parametri:**
- `$error` (array): Informazioni errore
  - `error` (string): Messaggio errore
  - `source` (array): Informazioni fonte
  - `isbn` (string): ISBN
  - `context` (array): Contesto aggiuntivo (code, url, etc.)

**Esempio:**
```php
Hooks::add('scrape.error', function($errorData) {
    // Log error
    error_log("Scrape error for ISBN {$errorData['isbn']} from {$errorData['source']['name']}: {$errorData['error']}");

    // Send to Sentry
    \Sentry\captureException(new \Exception(
        "Scrape failed: {$errorData['error']}",
        0,
        null,
        $errorData
    ));

    // Notify admin if critical
    if ($errorData['source']['priority'] < 5) {
        sendAdminNotification("Critical scraping source failed: {$errorData['source']['name']}");
    }
}, 10);
```

---

### 🟢 `scrape.response` (Filter)
**Status:** Implementato
**File:** `app/Controllers/ScrapeController.php:50, 262`

Modifica il JSON response finale prima di inviarlo al client.

**Parametri:**
- `$payload` (array): Payload JSON
- `$isbn` (string): ISBN
- `$sources` (array): Array di fonti usate
- `$metadata` (array): Metadata (timestamp, duration, etc.)

**Restituisce:** array - Payload modificato

**Esempio:**
```php
Hooks::add('scrape.response', function($payload, $isbn, $sources, $meta) {
    // Add metadata
    $payload['_meta'] = [
        'scrape_timestamp' => $meta['timestamp'],
        'sources_used' => array_map(fn($s) => $s['name'], $sources),
        'plugin_version' => '1.0.0'
    ];

    // Add API version
    $payload['_api_version'] = 'v1';

    return $payload;
}, 20);
```

---

## 🖼️ Hook per Immagini

### 🟡 `image.upload.before` (Action)
**Status:** Documentato

Eseguito prima del caricamento di un'immagine.

**Parametri:**
- `$filename` (string): Nome del file
- `$tmpPath` (string): Percorso temporaneo

### 🟡 `image.upload.after` (Action)
**Status:** Documentato

Eseguito dopo il caricamento di un'immagine.

**Parametri:**
- `$filename` (string): Nome del file salvato
- `$path` (string): Percorso finale

**Esempio:**
```php
Hooks::add('image.upload.after', function($filename, $path) {
    // Create thumbnails
    createThumbnail($path, 150, 200);
    createThumbnail($path, 300, 400);

    // Optimize
    optimizeImage($path);
}, 10);
```

### 🟡 `image.process` (Filter)
**Status:** Documentato

Permette elaborazione personalizzata dell'immagine.

**Parametri:**
- `$imagePath` (string): Percorso dell'immagine
- `$options` (array): Opzioni di elaborazione

**Restituisce:** string - Percorso dell'immagine (può essere modificato)

**Esempio:**
```php
Hooks::add('image.process', function($imagePath, $options) {
    // Add watermark
    addWatermark($imagePath, '/path/to/watermark.png');

    // Convert to WebP
    $webpPath = convertToWebP($imagePath);

    return $webpPath;
}, 10);
```

### 🟡 `image.delete.before` (Action)
**Status:** Documentato

Eseguito prima di eliminare un'immagine.

**Parametri:**
- `$imagePath` (string): Percorso dell'immagine da eliminare

---

## 📚 Hook per Prestiti

### 🟡 `loan.create.before` (Action)
**Status:** Documentato

Eseguito prima di creare un prestito.

**Parametri:**
- `$loanData` (array): Dati del prestito

**Esempio:**
```php
Hooks::add('loan.create.before', function($loanData) {
    // Verify user hasn't exceeded loan limit
    $userLoans = countUserActiveLoans($loanData['utente_id']);
    if ($userLoans >= 5) {
        throw new Exception('Limite prestiti raggiunto (max 5)');
    }
}, 10);
```

### 🟡 `loan.create.after` (Action)
**Status:** Documentato

Eseguito dopo aver creato un prestito.

**Parametri:**
- `$loanId` (int): ID del prestito
- `$loanData` (array): Dati del prestito

**Esempio:**
```php
Hooks::add('loan.create.after', function($loanId, $loanData) {
    // Send confirmation email
    sendLoanConfirmationEmail($loanData['utente_id'], $loanId);

    // Add to calendar
    addToUserCalendar($loanData['utente_id'], $loanData['data_scadenza']);
}, 10);
```

### 🟡 `loan.return.after` (Action)
**Status:** Documentato

Eseguito dopo la restituzione di un prestito.

**Parametri:**
- `$loanId` (int): ID del prestito
- `$loanData` (array): Dati del prestito

---

## 📊 Hook Generici

### 🟡 `app.init` (Action)
**Status:** Documentato

Eseguito all'inizializzazione dell'applicazione.

**Esempio:**
```php
Hooks::add('app.init', function() {
    // Initialize analytics
    analytics()->init();

    // Load external configs
    loadExternalConfig();
}, 10);
```

### 🟡 `app.request.before` (Action)
**Status:** Documentato

Eseguito prima di processare ogni richiesta.

**Parametri:**
- `$request` (ServerRequestInterface): Oggetto richiesta

### 🟡 `app.response.before` (Action)
**Status:** Documentato

Eseguito prima di inviare la risposta.

**Parametri:**
- `$response` (ResponseInterface): Oggetto risposta

### 🟡 `admin.menu.items` (Filter)
**Status:** Documentato

Permette di aggiungere voci al menu amministrazione.

**Parametri:**
- `$menuItems` (array): Array di voci di menu

**Restituisce:** array - Menu items modificato

**Esempio:**
```php
Hooks::add('admin.menu.items', function($menuItems) {
    $menuItems[] = [
        'label' => 'Custom Reports',
        'url' => '/admin/custom-reports',
        'icon' => 'fas fa-chart-line'
    ];
    return $menuItems;
}, 10);
```

### 🟡 `frontend.menu.items` (Filter)
**Status:** Documentato

Permette di aggiungere voci al menu frontend.

**Parametri:**
- `$menuItems` (array): Array di voci di menu

**Restituisce:** array - Menu items modificato

---

## 🔌 Plugin di Esempio

Questa sezione mostra plugin completi che utilizzano gli hook del sistema.

### Plugin: Open Library Scraper

**Percorso:** `app/Plugins/OpenLibrary/`
**Stato:** ✅ Installato
**Priorità:** 5 (alta)

#### Descrizione

Plugin per l'integrazione con le API di Open Library (openlibrary.org). Fornisce scraping completo di metadati libri tramite API REST invece di scraping HTML.

#### Hook Utilizzati

1. **`scrape.sources`** (priorità 5) - Aggiunge Open Library come fonte di scraping
2. **`scrape.fetch.custom`** (priorità 5) - Implementa la logica di fetch via API
3. **`scrape.data.modify`** (priorità 10) - Arricchisce i dati con copertine

#### Codice Completo

```php
<?php
namespace App\Plugins\OpenLibrary;

use App\Support\Hooks;

class OpenLibraryPlugin
{
    private const API_BASE = 'https://openlibrary.org';
    private const COVERS_BASE = 'https://covers.openlibrary.org';

    public function activate(): void
    {
        // Aggiunge Open Library come fonte di scraping
        Hooks::add('scrape.sources', [$this, 'addOpenLibrarySource'], 5);

        // Usa le API per lo scraping
        Hooks::add('scrape.fetch.custom', [$this, 'fetchFromOpenLibrary'], 5);

        // Arricchisce con copertine se mancanti
        Hooks::add('scrape.data.modify', [$this, 'enrichWithOpenLibraryData'], 10);
    }

    public function addOpenLibrarySource(array $sources, string $isbn): array
    {
        $sources['openlibrary'] = [
            'name' => 'Open Library',
            'url_pattern' => self::API_BASE . '/isbn/{isbn}.json',
            'enabled' => true,
            'priority' => 5,
            'fields' => ['title', 'authors', 'publisher', 'description', 'image'],
        ];

        return $sources;
    }

    public function fetchFromOpenLibrary($current, array $sources, string $isbn): ?array
    {
        // Se un altro plugin ha già gestito, non interviene
        if ($current !== null) {
            return $current;
        }

        if (!isset($sources['openlibrary']) || !$sources['openlibrary']['enabled']) {
            return null;
        }

        try {
            // Fetch edition data
            $editionData = $this->makeApiRequest(self::API_BASE . "/isbn/{$isbn}.json");

            if (!$editionData) {
                return null;
            }

            // Fetch work data
            $workData = null;
            if (!empty($editionData['works'][0]['key'])) {
                $workKey = $editionData['works'][0]['key'];
                $workData = $this->makeApiRequest(self::API_BASE . "{$workKey}.json");
            }

            // Fetch authors
            $authorNames = [];
            if (!empty($editionData['authors'])) {
                foreach ($editionData['authors'] as $author) {
                    if (!empty($author['key'])) {
                        $authorData = $this->makeApiRequest(self::API_BASE . "{$author['key']}.json");
                        if ($authorData && !empty($authorData['name'])) {
                            $authorNames[] = $authorData['name'];
                        }
                    }
                }
            }

            // Build response
            return [
                'title' => $editionData['title'] ?? '',
                'subtitle' => $editionData['subtitle'] ?? '',
                'author' => implode(', ', $authorNames),
                'authors' => $authorNames,
                'publisher' => $editionData['publishers'][0] ?? '',
                'isbn' => $isbn,
                'year' => $this->extractYear($editionData),
                'pages' => $editionData['number_of_pages'] ?? null,
                'description' => $this->extractDescription($editionData, $workData),
                'image' => $this->getCoverUrl($isbn, $editionData),
                'source' => self::API_BASE . "/isbn/{$isbn}",
            ];

        } catch (\Exception $e) {
            error_log('OpenLibrary Plugin Error: ' . $e->getMessage());
            return null;
        }
    }

    public function enrichWithOpenLibraryData(array $payload, string $isbn): array
    {
        // Aggiungi copertina se mancante
        if (empty($payload['image'])) {
            $coverUrl = $this->getCoverUrl($isbn, []);
            if ($coverUrl) {
                $payload['image'] = $coverUrl;
            }
        }

        return $payload;
    }

    private function getCoverUrl(string $isbn, array $editionData = []): ?string
    {
        // Try cover ID first
        if (!empty($editionData['covers'][0])) {
            $url = self::COVERS_BASE . "/b/id/{$editionData['covers'][0]}-L.jpg";
            if ($this->checkCoverExists($url)) {
                return $url;
            }
        }

        // Fallback to ISBN
        $url = self::COVERS_BASE . "/b/isbn/{$isbn}-L.jpg";
        return $this->checkCoverExists($url) ? $url : null;
    }

    private function makeApiRequest(string $url): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BibliotecaBot/1.0)',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return null;
        }

        return json_decode($response, true) ?: null;
    }

    private function extractYear(array $data): ?int
    {
        $dateStr = $data['publish_date'] ?? '';
        if (preg_match('/(\d{4})/', $dateStr, $matches)) {
            return (int)$matches[1];
        }
        return null;
    }

    private function extractDescription(array $edition, ?array $work): string
    {
        // Prefer work description (more complete)
        if ($work && !empty($work['description'])) {
            if (is_string($work['description'])) {
                return $work['description'];
            }
            if (is_array($work['description']) && !empty($work['description']['value'])) {
                return $work['description']['value'];
            }
        }

        // Fallback to edition description
        if (!empty($edition['description'])) {
            if (is_string($edition['description'])) {
                return $edition['description'];
            }
            if (is_array($edition['description']) && !empty($edition['description']['value'])) {
                return $edition['description']['value'];
            }
        }

        return '';
    }

    private function checkCoverExists(string $url): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        return $httpCode === 200 && strpos($contentType, 'image/') === 0;
    }
}
```

#### Attivazione

```php
// In public/index.php (già configurato)
if (file_exists(__DIR__ . '/../app/Plugins/OpenLibrary/activate.php')) {
    require __DIR__ . '/../app/Plugins/OpenLibrary/activate.php';
}
```

#### Caratteristiche

- ✅ **API-based scraping** - Usa API REST invece di HTML parsing
- ✅ **Alta priorità** (5) - Preferito rispetto a scraping HTML
- ✅ **Dati arricchiti** - Include opere, edizioni e autori completi
- ✅ **Copertine HD** - Accesso diretto a immagini alta risoluzione
- ✅ **Multilingua** - Supporta tutte le lingue disponibili su Open Library
- ✅ **Fallback intelligente** - Se mancano dati, lascia gestire ad altri plugin
- ✅ **Error handling** - Non blocca lo scraping in caso di errori

#### Test

```bash
# Test manuale via API
curl 'http://localhost/admin/scrape?isbn=9780140328721'

# Output atteso:
{
  "title": "Fantastic Mr. Fox",
  "author": "Roald Dahl",
  "publisher": "Puffin Books",
  "source": "https://openlibrary.org/isbn/9780140328721",
  "image": "https://covers.openlibrary.org/b/id/240727-L.jpg",
  ...
}

# Test automatico
php app/Plugins/OpenLibrary/test.php
```

#### Configurazione

Per disabilitare temporaneamente:

```php
Hooks::add('scrape.sources', function($sources) {
    $sources['openlibrary']['enabled'] = false;
    return $sources;
}, 99); // Priorità alta per sovrascrivere
```

Per modificare la priorità:

```php
Hooks::add('scrape.sources', function($sources) {
    $sources['openlibrary']['priority'] = 50; // Priorità bassa
    return $sources;
}, 99);
```

---

## 📝 Note sull'Uso degli Hook

### Priorità
- Valori più bassi = esecuzione prima
- Default: 10
- Range consigliato: 1-100

### Best Practices

1. **Always Return in Filters**
   ```php
   // ✅ CORRETTO
   Hooks::add('book.data.get', function($data, $id) {
       $data['custom'] = 'value';
       return $data; // IMPORTANTE
   }, 10);

   // ❌ ERRATO
   Hooks::add('book.data.get', function($data, $id) {
       $data['custom'] = 'value';
       // Manca return!
   }, 10);
   ```

2. **Error Handling**
   ```php
   Hooks::add('book.save.after', function($id, $data) {
       try {
           externalApi()->sync($id, $data);
       } catch (Exception $e) {
           error_log("Sync failed: " . $e->getMessage());
           // Non propagare l'errore per non bloccare il salvataggio
       }
   }, 10);
   ```

3. **Performance**
   ```php
   // ✅ Efficiente - cache risultati pesanti
   Hooks::add('book.data.get', function($data, $id) {
       $cacheKey = "external_rating_{$id}";
       $rating = cache()->get($cacheKey);

       if ($rating === null) {
           $rating = expensiveApiCall($id);
           cache()->set($cacheKey, $rating, 3600);
       }

       $data['rating'] = $rating;
       return $data;
   }, 10);
   ```

---

## 🔧 Registrazione Hook

### Metodo 1: Database (Consigliato per plugin distribuiti)
```php
// Nel metodo onActivate() del plugin
public function onActivate() {
    $this->db->query("
        INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority)
        VALUES ({$this->pluginId}, 'book.data.get', 'MyPlugin\\BookHandler', 'enrichData', 10)
    ");
}
```

### Metodo 2: Runtime (Utile per sviluppo/test)
```php
// Nel costruttore o metodo del plugin
Hooks::add('book.save.after', [$this, 'onBookSave'], 10);
// oppure
Hooks::add('book.save.after', function($id, $data) {
    // codice
}, 10);
```

---

**Documentazione aggiornata:** 2025-01-05
**Hook implementati:** 28 (16 precedenti + 12 scraping)
**Hook documentati:** 37+
**Totale hook disponibili:** 53+

