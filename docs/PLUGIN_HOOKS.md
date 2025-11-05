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

### 🟡 `scrape.sources` (Filter)
**Status:** Documentato

Aggiunge fonti di scraping personalizzate.

**Parametri:**
- `$sources` (array): Array di fonti disponibili

**Restituisce:** array - Fonti con nuove aggiunte

**Esempio:**
```php
Hooks::add('scrape.sources', function($sources) {
    $sources['amazon'] = [
        'name' => 'Amazon',
        'url_pattern' => 'https://www.amazon.it/s?k={isbn}',
        'parser' => 'AmazonParser'
    ];
    return $sources;
}, 10);
```

### 🟡 `scrape.before` (Action)
**Status:** Documentato

Eseguito prima di iniziare lo scraping.

**Parametri:**
- `$source` (string): Fonte di scraping
- `$query` (string): Query di ricerca (ISBN, titolo, etc.)

### 🟡 `scrape.parse` (Filter)
**Status:** Documentato

Permette parsing personalizzato dei dati scrapati.

**Parametri:**
- `$parsedData` (array): Dati parsati dal parser predefinito
- `$rawData` (string): Dati grezzi (HTML, JSON, etc.)
- `$source` (string): Fonte

**Restituisce:** array - Dati parsati modificati

### 🟡 `scrape.after` (Action)
**Status:** Documentato

Eseguito dopo lo scraping.

**Parametri:**
- `$result` (array): Risultato dello scraping
- `$source` (string): Fonte

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
**Hook implementati:** 16
**Hook documentati:** 25+
**Totale hook disponibili:** 41+

