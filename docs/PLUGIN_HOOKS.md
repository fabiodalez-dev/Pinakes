# Plugin Hooks Reference - Pinakes

Questo documento elenca tutti gli hook disponibili nel sistema di plugin di Pinakes, con esempi pratici e parametri.

## Legenda

-  **Implementato** - Hook già integrato nel codice
-  **Documentato** - Hook pianificato, pronto per implementazione
- **Filter** - Hook che modifica e restituisce un valore
- **Action** - Hook che esegue codice senza restituire un valore

---

## Hook per Libri

### `book.data.get` (Filter)
**Status:** Implementato
**File:** `app/Models/BookRepository.php:218`

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

### `book.save.before` (Action)
**Status:** Implementato
**File:** `app/Controllers/LibriController.php:1304, :1995, 768`

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

### `book.save.after` (Action)
**Status:** Implementato
**File:** `app/Controllers/LibriController.php:1390, :2007, 773`

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

### `book.form.fields` (Action)
**Status:** Implementato
**File:** `app/Views/libri/partials/book_form.php:1043`

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

### `book.frontend.details` (Action)
**Status:** Implementato
**File:** `app/Views/frontend/book-detail.php:2514`

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

### `book.delete.before` (Action)
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

### `book.frontend.card` (Filter)
**Status:** Documentato

Modifica l'HTML della card libro nel catalogo pubblico.

**Parametri:**
- `$cardHtml` (string): HTML della card
- `$bookData` (array): Dati del libro

**Restituisce:** string - HTML modificato

### `book.admin.external_links` (Action)
**Status:** Implementato
**File:** `app/Views/libri/scheda_libro.php:283`

Invocato con l'helper `do_action('book.admin.external_links', $libro)` nella scheda libro del backend (`GET /admin/books/{id}`, `LibriController::show()`), dentro il riquadro dei dati bibliografici, subito dopo le righe ISBN e ISSN. È pensato per link di ricerca verso siti esterni: il plugin bundled `goodlib` lo usa per i badge delle fonti (Anna's Archive, Z-Library, ecc.). Il valore di ritorno non viene letto.

L'handler emette direttamente l'HTML (echo) dentro la view: il core non esegue alcun escape su quell'output, quindi ogni valore del libro stampato va passato da `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` e i termini di ricerca inseriti negli URL vanno codificati (`goodlib` usa `urlencode()` e poi `htmlspecialchars()` sull’intero `href`). La rotta è aperta anche agli utenti `standard` e `premium` (`AuthMiddleware(['admin', 'staff', 'standard', 'premium'])`), quindi l'hook scatta anche per loro: non emettere nulla di riservato allo staff senza controllare `$_SESSION['user']['tipo_utente']`.

**Parametri:**
- `$libro` (array): riga del libro restituita da `BookRepository::getById()`, già passata dal filtro `book.data.get`

**Esempio** (signature di `GoodLibPlugin::renderAdminBadges()`, corpo semplificato):
```php
public function renderAdminBadges(array $libro): void
{
    $isbn = (string) ($libro['isbn13'] ?? '');
    if ($isbn === '') { return; }
    echo '<a href="' . htmlspecialchars('https://example.org/search?q=' . urlencode($isbn), ENT_QUOTES, 'UTF-8') . '">…</a>';
}
```

---

## Hook per Login & Autenticazione

### `login.form.render.before` (Action)
**Status:** Implementato
**File:** `app/Controllers/AuthController.php:24`

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

### `login.form.html` (Filter)
**Status:** Implementato
**File:** `app/Controllers/AuthController.php:34`

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

### `login.form.fields` (Action)
**Status:** Implementato
**File:** `app/Views/auth/login.php:193`

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

### `login.validate` (Filter)
**Status:** Implementato
**File:** `app/Controllers/AuthController.php:107`

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

### `login.success` (Action)
**Status:** Implementato
**File:** `app/Controllers/AuthController.php:173`

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

### `login.failed` (Action)
**Status:** Implementato
**File:** `app/Controllers/AuthController.php:201`

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

## Hook per Autori

### `author.data.get` (Filter)
**Status:** Implementato
**File:** `app/Models/AuthorRepository.php:60`

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

### `author.save.before` (Action)
**Status:** Implementato
**File:** `app/Models/AuthorRepository.php:189`

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

### `author.save.after` (Action)
**Status:** Implementato
**File:** `app/Models/AuthorRepository.php:247`

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

### `author.frontend.details` (Action)
**Status:** Documentato

Aggiunge contenuto nella pagina autore nel frontend.

**Parametri:**
- `$authorData` (array): Dati dell'autore
- `$authorId` (int): ID dell'autore

### `author.form.fields` (Action)
**Status:** Implementato
**File:** `app/Views/autori/modifica_autore.php:169`

Invocato con `\App\Support\Hooks::do('author.form.fields', [$autore ?? null])` **solo** nel modulo di modifica autore (`GET /admin/authors/edit/{id}`, `AutoriController::editForm()`), dentro il `<form id="edit-author-form">`, dopo la sezione "Collegamenti e fonti" e prima dei pulsanti Annulla/Salva. Il modulo di creazione (`crea_autore.php`) non invoca l'hook. Il valore di ritorno non viene letto.

L'handler emette HTML (echo) che finisce dentro il form: ogni campo con un attributo `name` viene inviato con il POST di `/admin/authors/update/{id}` insieme ai campi core, ma `AutoriController::update()` passa ad `AuthorRepository::update()` (e quindi a `author.save.before`) solo un elenco esplicito di campi core: i campi del plugin non vengono salvati né inoltrati. I plugin bundled persistono i propri dati tramite endpoint propri (es. `viaf-authority` usa `/api/viaf/author/{id}/set`) e ricavano il token con `\App\Support\Csrf::ensureToken()`. Il core non esegue l'escape dell'output: i valori dell'autore stampati vanno passati da `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.

**Parametri:**
- `$autore` (array|null): riga dell'autore restituita da `AuthorRepository::getById()` (già filtrata da `author.data.get`); la signature deve accettare anche `null`

**Esempio** (signature usata da `ViafAuthorityPlugin::renderAuthorFields()` e `Z39ServerPlugin::renderReicatAuthorFields()`):
```php
public function renderAuthorFields(?array $autore): void
{
    $authorId = is_array($autore) ? (int) ($autore['id'] ?? 0) : 0;
    $csrf = \App\Support\Csrf::ensureToken();
    include __DIR__ . '/views/author-fields.php';
}
```

---

## Hook per Editori

### `publisher.data.get` (Filter)
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

### `publisher.save.before` (Action)
**Status:** Documentato

Eseguito prima di salvare un editore.

**Parametri:**
- `$publisherData` (array): Dati dell'editore
- `$publisherId` (int): ID dell'editore

### `publisher.save.after` (Action)
**Status:** Documentato

Eseguito dopo aver salvato un editore.

**Parametri:**
- `$publisherId` (int): ID dell'editore
- `$publisherData` (array): Dati dell'editore

### `publisher.frontend.details` (Action)
**Status:** Documentato

Aggiunge contenuto nella pagina editore nel frontend.

**Parametri:**
- `$publisherData` (array): Dati dell'editore
- `$publisherId` (int): ID dell'editore

### `publisher.merging` (Action)
**Status:** Implementato
**File:** `app/Models/PublisherRepository.php:354`

Invocato da `PublisherRepository::mergePublishers()` tramite il wrapper privato `emitHook()` (che chiama `Hooks::do()` dentro un `try/catch` e registra un eventuale errore con `SecureLogger::warning()`). L'azione utente è l'unione di editori: `POST /api/editori/merge` (`MergeHelper::handleMergeRequest(..., 'editori')`, CSRF + `AdminAuthMiddleware`). L'hook scatta **dentro** la transazione aperta con `begin_transaction()`, come primo passo, **prima** che `libri.editore_id` e la tabella `libri_editori` vengano ripuntati sul primario e prima che le righe duplicate di `editori` vengano cancellate. Serve ai plugin che referenziano `editori` con FK `ON DELETE SET NULL` (es. `emeroteca_testate.editore_id`) per ripuntare le proprie righe sul primario invece di perdere il collegamento.

Vincoli per l'handler: le sue scritture fanno parte della transazione del core (vengono annullate se il merge fa rollback); non deve chiamare `begin_transaction()`, `commit()` o `rollback()` sulla connessione condivisa (un `begin_transaction()` annidato esegue un commit implicito); un'eccezione viene intercettata da `HookManager` e **non** annulla il merge, quindi non è un modo per bloccarlo. Non emettere output: la risposta è JSON.

**Parametri:**
- `$primaryId` (int): ID dell'editore che sopravvive
- `$duplicateIds` (array<int>): ID degli editori che verranno cancellati (almeno uno)

**Esempio** (signature di `EmerotecaPlugin::onPublisherMerging()`):
```php
public function onPublisherMerging(int $primaryId, $duplicateIds): void
{
    $ids = is_array($duplicateIds) ? $duplicateIds : [$duplicateIds];
    // UPDATE tabella_plugin SET editore_id = $primaryId WHERE editore_id IN (...$ids)
}
```

### `publisher.deleting` (Action)
**Status:** Implementato
**File:** `app/Models/PublisherRepository.php:257`, `app/Controllers/EditoriApiController.php:331`

Invocato **prima** della cancellazione di un editore, in due punti:

- `PublisherRepository::delete()` tramite `emitHook()`, prima di `UPDATE libri SET editore_id=NULL` e del `DELETE FROM editori`. Azione utente: `POST /admin/publishers/delete/{id}` (`EditorsController::delete()`), che chiama il repository solo se l'editore non ha libri collegati.
- `EditoriApiController::bulkDelete()` (`POST /api/editori/bulk-delete`): dopo la verifica che nessuno degli editori selezionati abbia libri, l'hook viene emesso **una volta per ogni ID** con `Hooks::do('publisher.deleting', [$publisherId])` dentro un `try/catch`, poi viene eseguito un unico `DELETE ... WHERE id IN (...)`.

Nessuno dei due percorsi apre una transazione. L'hook scatta prima della `DELETE` e non viene revocato se questa poi fallisce: l'handler non deve dare per certa la cancellazione. Non ha un sopravvissuto da seguire, quindi serve a registrare o ripulire i riferimenti del plugin. Un'eccezione dell'handler non blocca la cancellazione. Non emettere output (redirect o risposta JSON).

**Parametri:**
- `$publisherId` (int): ID dell'editore che sta per essere eliminato

**Esempio** (signature di `EmerotecaPlugin::onPublisherDeleting()`):
```php
public function onPublisherDeleting(int $publisherId): void
{
    if ($publisherId <= 0) { return; }
    // log dei riferimenti persi + UPDATE tabella_plugin SET editore_id = NULL WHERE editore_id = ?
}
```

---

## Hook per Generi

### `genre.merging` (Action)
**Status:** Implementato
**File:** `app/Models/GenereRepository.php:612`

Invocato da `GenereRepository::merge()` con `\App\Support\Hooks::do('genre.merging', [$targetId, [$sourceId]])`, avvolto in un `try/catch` che registra l'errore con `SecureLogger::warning()`. Azione utente: unione di un genere in un altro da `POST /admin/genres/{id}/merge` (`GeneriController::merge()`, CSRF + `AdminAuthMiddleware`), dove `{id}` è il genere di origine e `target_id` nel body quello di destinazione.

L'hook scatta **dentro** la transazione, dopo che il core ha già spostato i sottogeneri, ripuntato `libri.genere_id`/`libri.sottogenere_id`, `posizioni` e `mensole` sul genere di destinazione, e **prima** del `DELETE FROM generi` del genere di origine. Stessi vincoli di `publisher.merging`: le scritture dell'handler fanno parte della transazione del core, niente `begin_transaction()`/`commit()`/`rollback()`, un'eccezione non annulla il merge, nessun output (la risposta è un redirect).

**Parametri:**
- `$targetId` (int): ID del genere che sopravvive
- `$sourceIds` (array<int>): ID dei generi che verranno cancellati; con l'unico punto di chiamata attuale contiene sempre un solo elemento

**Esempio** (signature di `EmerotecaPlugin::onGenreMerging()`):
```php
public function onGenreMerging(int $targetId, $sourceIds): void
{
    $ids = is_array($sourceIds) ? $sourceIds : [$sourceIds];
    // UPDATE tabella_plugin SET genere_id = $targetId WHERE genere_id IN (...$ids)
}
```

---

## Hook per Collocazione

### `shelf.can_delete` (Filter)
**Status:** Implementato
**File:** `app/Controllers/CollocazioneController.php:176`

Invocato da `CollocazioneController::deleteMensola()` (`POST /admin/placement/shelves/{id}/delete`, CSRF + `AdminAuthMiddleware`) con `\App\Support\Hooks::apply('shelf.can_delete', true, [$id])`, dopo che il core ha verificato che nessun libro non cancellato (`deleted_at IS NULL`) sia collocato sulla mensola e prima della `DELETE FROM mensole`. Nonostante il nome, lo "shelf" è una **mensola** (`mensole.id`), non uno scaffale: l'eliminazione degli scaffali non invoca hook.

Il core confronta il risultato con `!== false`: **solo** il booleano `false` blocca l'eliminazione (l'utente vede "Impossibile eliminare: la mensola è in uso" e la mensola resta); qualsiasi altro valore, compresi `0`, `null` o `'no'`, la consente. Se la dispatch stessa lancia un'eccezione il core registra un warning e consente l'eliminazione; un'eccezione dentro l'handler viene intercettata da `HookManager`, che mantiene il valore ricevuto. Per questo l'handler deve restituire `false` esplicitamente, e deve restituire `false` anche se lo ha ricevuto da un handler precedente, altrimenti annulla il veto. Nessuna transazione aperta.

**Parametri:**
- valore iniziale `true` (mixed): esito accumulato dagli handler precedenti
- `$mensolaId` (int): ID della mensola da eliminare

**Restituisce:** bool - `false` per vietare l'eliminazione, `true` per consentirla

**Esempio** (signature di `EmerotecaPlugin::onShelfCanDelete()`):
```php
public function onShelfCanDelete($allowed, int $mensolaId): bool
{
    if ($allowed === false) { return false; } // veto già espresso
    return !$this->mensolaUsataDalPlugin($mensolaId);
}
```

### `shelf.deleted` (Action)
**Status:** Implementato
**File:** `app/Controllers/CollocazioneController.php:206`

Invocato da `CollocazioneController::deleteMensola()` con `\App\Support\Hooks::do('shelf.deleted', [$id])`, avvolto in un `try/catch`, subito dopo la `DELETE FROM mensole` e prima del redirect a `/admin/placement`. L'hook scatta solo se la `DELETE` ha rimosso davvero la riga: se l'id non corrisponde più a nessuna mensola (pagina vecchia, doppio clic) il controller mostra "Mensola non trovata" e l'hook non viene invocato, così i plugin non ripuliscono dati derivati per un'eliminazione mai avvenuta. Scatta solo se `shelf.can_delete` non ha restituito `false`. Nessuna transazione aperta; un'eccezione non fa fallire la richiesta; nessun output (la risposta è un redirect).

**Parametri:**
- `$mensolaId` (int): ID della mensola eliminata

**Esempio** (signature di `EmerotecaPlugin::onShelfDeleted()`):
```php
public function onShelfDeleted(int $mensolaId): void
{
    // UPDATE tabella_plugin SET collocazione_id = NULL WHERE collocazione_id = ?
}
```

---

## Hook per Catalogo e Ricerca

### `catalog.filters.render` (Action)
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

### `catalog.query.modify` (Filter)
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

### `catalog.results.modify` (Filter)
**Status:** Documentato

Modifica i risultati della ricerca prima della visualizzazione.

**Parametri:**
- `$results` (array): Array di risultati

**Restituisce:** array - Risultati modificati

### `search.external_suggestions` (Filter)
**Status:** Implementato
**File:** `app/Controllers/FrontendController.php:1288`

Invocato da `FrontendController::collectExternalSearchSuggestions()` con `\App\Support\Hooks::apply('search.external_suggestions', [], [$term])` quando il catalogo pubblico (`FrontendController::catalog()`) riceve un termine di ricerca non vuoto (parametro `q` o `search`, dopo `trim()`). Serve a dire al visitatore che il termine esiste anche in un corpus che il catalogo non legge (il catalogo cerca solo in `libri.search_index`): il core mostra i link nel riquadro "Cerca "…" anche in:" (`app/Views/frontend/partials/search-external-suggestions.php`), sia quando il catalogo non trova nulla sia quando trova qualcosa.

Contratto del valore restituito, validato dal core:

- deve essere un array; qualsiasi altro tipo, o un'eccezione nella dispatch, fa sparire il riquadro (la pagina non si rompe);
- ogni voce è `['label' => string, 'url' => string]`; le voci che non sono array, con chiavi mancanti o non stringa, o vuote dopo `trim()` vengono scartate;
- `label` è testo semplice già tradotto nella lingua del visitatore: il core lo passa da `htmlspecialchars()` e lo tronca a 160 caratteri;
- `url` deve essere un percorso relativo della stessa origine che inizia con una sola `/` (regex `{^/(?!/)[\w/\-.~%?&=:;,@!$'()*+\[\]#]*$}`): URL assoluti, `//host`, `javascript:`, `data:`, spazi e caratteri di controllo vengono scartati. Il core lo stampa così com'è nell'`href` (con escape), senza aggiungere il base path: costruirlo con `url('/...')`;
- vengono mostrate al massimo 5 voci.

L'handler deve aggiungere voci all'array ricevuto invece di sostituirlo, e non deve restituire alcuna voce quando il termine non ha corrispondenze nel proprio corpus. Gira su una richiesta pubblica e non autenticata a ogni ricerca: le query vanno tenute leggere (il plugin `emeroteca` usa due sonde `LIMIT 1` e ignora termini più corti di 2 caratteri).

**Parametri:**
- valore iniziale `[]` (array): suggerimenti raccolti dagli handler precedenti
- `$term` (string): termine di ricerca grezzo, dopo `trim()`

**Restituisce:** array - lista di `['label' => string, 'url' => string]`

**Esempio** (signature di `EmerotecaPlugin::suggestEmerotecaSearch()`):
```php
public function suggestEmerotecaSearch($suggestions, string $term = ''): mixed
{
    if (!is_array($suggestions)) { return $suggestions; }
    if (!$this->haCorrispondenze($term)) { return $suggestions; }
    $suggestions[] = [
        'label' => __('Emeroteca (testate e spoglio degli articoli)'),
        'url'   => url('/emeroteca') . '?q=' . rawurlencode($term),
    ];
    return $suggestions;
}
```

---

## Hook per Scraping

### `scrape.isbn.validate` (Filter)
**Status:** Implementato
**File:** `app/Controllers/ScrapeController.php:226`

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

### `scrape.sources` (Filter)
**Status:** Implementato
**File:** `app/Controllers/ScrapeController.php:248`

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

### `scrape.fetch.custom` (Filter)
**Status:** Implementato
**File:** `app/Controllers/ScrapeController.php:262`

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

### `scrape.before` (Action)
**Status:** Implementato
**File:** `storage/plugins/scraping-pro/ScrapingProPlugin.php:115`

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

### `scrape.http.options` (Filter)
**Status:** Implementato
**File:** `storage/plugins/scraping-pro/ScrapingProPlugin.php:368`

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

### `scrape.after` (Action)
**Status:** Implementato
**File:** `storage/plugins/scraping-pro/ScrapingProPlugin.php:124`

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

### `scrape.parse` (Filter)
**Status:** Implementato
**File:** `storage/plugins/scraping-pro/ScrapingProPlugin.php:264`

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

### `scrape.validate.data` (Filter)
**Status:** Implementato
**File:** `storage/plugins/scraping-pro/ScrapingProPlugin.php:266`

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

### `scrape.validation.failed` (Action)
**Status:** Implementato
**File:** `storage/plugins/scraping-pro/ScrapingProPlugin.php:273`

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

### `scrape.data.modify` (Filter)
**Status:** Implementato
**File:** `app/Controllers/ScrapeController.php:292, :361`

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

### `scrape.error` (Action)
**Status:** Implementato
**File:** emesso dai plugin di scraping, non da `ScrapeController` — `storage/plugins/scraping-pro/ScrapingProPlugin.php:128`, `:382`, `:397` e `storage/plugins/api-book-scraper/ApiBookScraperPlugin.php:449`

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

### `scrape.response` (Filter)
**Status:** Implementato
**File:** `app/Controllers/ScrapeController.php:308, :377, 262`

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

## Hook per Immagini

### `image.upload.before` (Action)
**Status:** Documentato

Eseguito prima del caricamento di un'immagine.

**Parametri:**
- `$filename` (string): Nome del file
- `$tmpPath` (string): Percorso temporaneo

### `image.upload.after` (Action)
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

### `image.process` (Filter)
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

### `image.delete.before` (Action)
**Status:** Documentato

Eseguito prima di eliminare un'immagine.

**Parametri:**
- `$imagePath` (string): Percorso dell'immagine da eliminare

---

## Hook per Prestiti

### `loan.create.before` (Action)
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

### `loan.create.after` (Action)
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

### `loan.return.after` (Action)
**Status:** Documentato

Eseguito dopo la restituzione di un prestito.

**Parametri:**
- `$loanId` (int): ID del prestito
- `$loanData` (array): Dati del prestito

---

## Hook per Manutenzione e Cron

Entrambi gli hook di questa sezione sono invocati su un'istanza **nuova** di `HookManager` (`(new HookManager($db))->doAction(...)`), non sull'istanza globale usata da `Hooks::do()`. Quell'istanza carica solo gli hook registrati nella tabella `plugin_hooks` per plugin attivi: un callback aggiunto a runtime con `Hooks::add()` **non** viene eseguito. Registrare quindi l'handler nel database (metodo 1 di "Registrazione Hook"). Nessuno dei due riceve parametri e il valore di ritorno non viene letto; il contesto può essere la CLI del cron, senza richiesta HTTP né sessione, quindi l'handler non deve emettere output né leggere `$_SESSION`.

### `mobile_api.dispatch_push` (Action)
**Status:** Implementato
**File:** `app/Support/MaintenanceService.php:294`, `cron/automatic-notifications.php:196`

Invocato in due punti:

- `MaintenanceService::runAll()`, dopo l'invio dei promemoria email e dei tentativi di reinvio delle notifiche accodate, prima della generazione del calendario ICS;
- `cron/automatic-notifications.php` (cron orario delle notifiche), dopo l'invio delle email.

Serve a consegnare le notifiche push native per gli stessi eventi delle email; il plugin bundled `mobile-api` lo registra con priorità 20. Essendo chiamato sia dal cron orario sia dalla manutenzione completa, l'handler deve essere idempotente (il `PushDispatcher` di `mobile-api` deduplica per chiave evento). In `runAll()` la chiamata è avvolta in un `try/catch` che aggiunge `dispatchPush: <messaggio>` a `$results['errors']`; un'eccezione dentro l'handler viene comunque intercettata da `HookManager` e registrata con `error_log()`.

**Parametri:** nessuno

**Esempio** (signature di `MobileApiPlugin::dispatchPush()`):
```php
public function dispatchPush(): void
{
    try {
        // consegna best-effort; non lanciare mai eccezioni
    } catch (\Throwable $e) {
        SecureLogger::error('[MyPlugin] dispatchPush failed: ' . $e->getMessage());
    }
}
```

### `maintenance.after_run` (Action)
**Status:** Implementato
**File:** `app/Support/MaintenanceService.php:328`

Invocato come **ultimo** passo di `MaintenanceService::runAll()`, dopo tutte le attività di circolazione, le email, `mobile_api.dispatch_push`, la generazione dell'ICS e la pulizia delle sessioni "Ricordami" scadute, prima dell'aggiornamento del marker di cooldown. `runAll()` viene eseguito da:

- `cron/full-maintenance.php` (manutenzione completa da cron);
- il login di un utente `admin` o `staff`, tramite `MaintenanceService::onAdminLogin()` registrato su `login.success` in `public/index.php`, che chiama `runIfNeeded(60)` (al massimo una volta ogni 60 minuti), in modo sincrono dentro la richiesta di login;
- il pulsante di manutenzione dell'amministrazione, `POST /admin/maintenance/perform` (`MaintenanceController`, solo `admin`), che chiama `runIfNeeded(0)`.

`runAll()` acquisisce il lock MySQL `GET_LOCK('pinakes_maintenance_<database>')`: se un'altra connessione lo detiene, il passaggio viene saltato per intero e l'hook non scatta. Non ci sono transazioni aperte al momento della chiamata. Il plugin bundled `book-club` lo usa per chiudere le votazioni scadute, inviare i promemoria degli incontri e riconciliare i libri esterni con il catalogo. Poiché può girare dentro la richiesta di login, l'handler deve restare veloce e intercettare le proprie eccezioni; la chiamata è avvolta in un `try/catch` che aggiunge `maintenanceAfterRun: <messaggio>` a `$results['errors']`.

**Parametri:** nessuno

**Esempio** (signature di `BookClubPlugin::onMaintenanceTick()`):
```php
public function onMaintenanceTick(): void
{
    try {
        // chiusura votazioni scadute, promemoria, ...
    } catch (\Throwable $e) {
        SecureLogger::error('[MyPlugin] maintenance tick failed: ' . $e->getMessage());
    }
}
```

---

## Hook Generici

### `app.init` (Action)
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

### `app.request.before` (Action)
**Status:** Documentato

Eseguito prima di processare ogni richiesta.

**Parametri:**
- `$request` (ServerRequestInterface): Oggetto richiesta

### `app.response.before` (Action)
**Status:** Documentato

Eseguito prima di inviare la risposta.

**Parametri:**
- `$response` (ResponseInterface): Oggetto risposta

### `admin.menu.items` (Filter)
**Status:** Documentato (non ancora invocato — l'hook realmente attivo è `admin.menu.render`, vedi sotto)

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

### `frontend.menu.items` (Filter)
**Status:** Documentato

Permette di aggiungere voci al menu frontend.

**Parametri:**
- `$menuItems` (array): Array di voci di menu

**Restituisce:** array - Menu items modificato

---

## Hook di Integrazione (usati dai plugin bundled)

Questi hook sono **effettivamente invocati dal core** e usati dai plugin distribuiti con Pinakes (archives, oai-pmh-server, frbr-lrm, ecc.).

### `app.routes.register` (Action)

**Status:** Implementato
**File:** `app/Routes/web.php:81`

Eseguito molto presto nel bootstrap del routing per permettere ai plugin di registrare le proprie rotte. Riceve l'istanza dell'app Slim.

**Parametri:**
- `$app` (\Slim\App): Istanza dell'applicazione su cui chiamare `$app->get()/post()/...`

**Esempio:**

```php
Hooks::add('app.routes.register', function($app) {
    $app->get('/oai', [\App\Plugins\OaiPmh\Controller::class, 'handle']);
}, 10);
```

> Nota: questo hook viene chiamato anche durante il bootstrap, prima che il guard runtime degli hook sia attivo. Un plugin **non** deve invocare `doAction()`/`applyFilters()` dentro `onActivate()` per evitare doppia registrazione delle rotte (FastRoute "Cannot register two routes").

### `admin.menu.render` (Action)

**Status:** Implementato
**File:** `app/Views/layout.php:365`

Eseguito nel rendering della sidebar admin: i plugin emettono direttamente l'HTML delle proprie voci di menu (echo). Non riceve né restituisce parametri.

**Esempio:**

```php
Hooks::add('admin.menu.render', function() {
    echo '<a href="' . htmlspecialchars(url('/admin/archives'), ENT_QUOTES, 'UTF-8') . '" class="...">Archivi</a>';
}, 10);
```

> Le rotte admin sono letterali inglesi: usare `url('/admin/...')`, mai `route_path()`.

### `assets.head` (Action)

**Status:** Implementato
**File:** `app/Views/layout.php:69`, `app/Views/frontend/layout.php:304`

Eseguito nel `<head>` sia del layout admin sia di quello frontend. Permette di iniettare `<link>`/`<style>`/`<script>` di plugin. Invocato via helper `do_action('assets.head')`.

### `assets.footer` (Action)

**Status:** Implementato
**File:** `app/Views/layout.php:1765`, `app/Views/frontend/layout.php:2473`

Controparte di `assets.head` in fondo al `<body>`: invocato con l'helper `do_action('assets.footer')`, senza argomenti, sia nel layout admin (dopo gli script inline del layout, prima del partial `scroll-to-top`) sia in quello frontend (dopo `$additional_js`, prima dei partial `cookie-banner` e `scroll-to-top`). Scatta quindi su ogni pagina HTML resa con uno dei due layout. Il valore di ritorno non viene letto. Adatto a `<script>` che devono girare dopo il markup della pagina.

L'handler emette HTML (echo) direttamente nel documento, senza escape da parte del core. Il middleware CSP di `public/index.php` aggiunge il nonce della risposta a ogni `<script>` e `<style>` inline che non ne ha già uno, quindi gli script inline funzionano; gli script esterni restano soggetti a `script-src` (`'self'` e pochi host CDN elencati in `ContentSecurityPolicy::header()`). I dati PHP passati a JavaScript vanno codificati con `json_encode(..., JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP)`, come fa `openurl-resolver` in `assets.head`. Sul frontend, se la cache LiteSpeed opzionale è attiva, home, catalogo e scheda libro possono essere servite dalla cache condivisa: non emettere dati legati all'utente o alla sessione. Nessun plugin bundled registra oggi questo hook.

**Esempio:**

```php
public function injectFooterScript(): void
{
    $config = ['endpoint' => url('/api/mio-plugin')];
    // Script inline: il nonce CSP viene aggiunto dal middleware
    echo '<script>window.mioPlugin = ' . json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) . ';</script>';
}
```

### `search.unified.sources` (Filter)

**Status:** Implementato
**File:** `app/Controllers/SearchController.php:259, :295, 240`

Permette ai plugin di aggiungere risultati alla ricerca unificata (`/api/search/unified`), p.es. risultati archivistici o da authority esterne.

**Parametri:**
- `$results` (array): Risultati correnti
- `$q` (string): Termine di ricerca

**Restituisce:** array - Risultati arricchiti

### `frontend.catalog.archive_results` (Filter)

**Status:** Implementato
**File:** `app/Controllers/FrontendController.php:251`

Inietta risultati di materiale archivistico nel catalogo pubblico. Usato dal plugin `archives`.

**Parametri:**
- valore iniziale `[]` (array): Lista risultati archivistici
- `$searchTerm` (string): Termine di ricerca

**Restituisce:** array - Risultati archivistici da fondere nel catalogo

### `sitemap.entries` (Filter)

**Status:** Implementato
**File:** `app/Support/SitemapGenerator.php:234`

Invocato da `SitemapGenerator::applyEntriesFilter()` con `Hooks::apply('sitemap.entries', array_values($unique), [$this->baseUrl, $this->defaultLocale])`, dopo che il generatore ha raccolto le voci core (pagine statiche, libri, autori, editori, generi) e prima di scrivere l'XML. Il generatore gira su `GET /sitemap.xml` (`SeoController::sitemap()`, richiesta pubblica), dal pulsante di rigenerazione nelle impostazioni avanzate (`POST /admin/settings/advanced/regenerate-sitemap`, `SettingsController::regenerateSitemap()`, che scrive il file statico `public/sitemap.xml`) e da `scripts/generate-sitemap.php`. Il filtro viene chiamato solo se l’istanza globale degli hook è inizializzata e ha almeno un handler (`Hooks::has('sitemap.entries')`). Anche `scripts/generate-sitemap.php` inizializza il sistema degli hook e carica i plugin attivi prima di generare, come fa `public/index.php`: la sitemap prodotta da riga di comando o da cron contiene quindi le stesse voci dei plugin di quella generata dal pannello.

Contratto, validato dal core (un plugin non deve poter rompere la sitemap):

- l'handler riceve l'elenco **completo** delle voci e può aggiungere, modificare o togliere; deve restituire un array. Un valore non array viene scartato con un warning e restano le voci core; un'eccezione nella dispatch fa lo stesso;
- ogni voce è un array associativo con `loc` (obbligatorio; `url` è accettato come alias), `lastmod`, `changefreq`, `priority` (facoltativi);
- `loc` deve essere uguale a `$baseUrl` o iniziare con `$baseUrl . '/'` (`$baseUrl` contiene già il base path, senza slash finale), non può contenere spazi o caratteri di controllo e non può superare 2048 caratteri (`MAX_LOC_LENGTH`): altrimenti la voce viene scartata;
- `changefreq` deve essere uno fra `always|hourly|daily|weekly|monthly|yearly|never` e `priority` un numero fra 0.0 e 1.0: i valori non validi vengono tolti (la voce resta); `lastmod` è una stringa che `DateTimeImmutable` sappia interpretare (es. un DATETIME MySQL);
- le voci vengono reindicizzate per `loc` (per lo stesso URL vince l'ultima) e il tetto di 50.000 URL (`MAX_TOTAL_URLS`) vale anche dopo il filtro.

L'handler gira su una richiesta pubblica e su installazioni dove le tabelle del plugin potrebbero non esistere: deve verificarle prima di interrogarle e non lanciare eccezioni.

**Parametri:**
- `$entries` (array): elenco delle voci raccolte finora
- `$baseUrl` (string): URL base assoluto del sito, già comprensivo del base path
- `$defaultLocale` (string): locale predefinito dell'installazione

**Restituisce:** array - elenco delle voci

**Esempio** (signature di `EmerotecaPlugin::extendSitemapEntries()`):
```php
public function extendSitemapEntries($entries, string $baseUrl = '', string $defaultLocale = ''): mixed
{
    if (!is_array($entries)) { return $entries; }
    $entries[] = ['loc' => rtrim($baseUrl, '/') . '/emeroteca', 'changefreq' => 'weekly', 'priority' => '0.6'];
    return $entries;
}
```

### `search.external_suggestions` (Filter)

**Status:** Implementato
**File:** `app/Controllers/FrontendController.php` (`collectExternalSearchSuggestions()`)

Il catalogo interroga solo `libri.search_index`: un termine che vive nel corpus di un plugin (periodici, archivi) produrrebbe "nessun risultato" anche quando la biblioteca lo possiede. Il filtro chiede ai plugin che cosa trovano sul termine cercato e il risultato viene mostrato nella pagina dei risultati, sia quando il catalogo non ha trovato nulla sia quando ha trovato qualcosa che il plugin completa.

**Parametri:**
- valore iniziale `[]` (array): suggerimenti raccolti finora — il listener **aggiunge**, non sostituisce
- `$term` (string): termine di ricerca grezzo, già trimmato

**Ogni suggerimento:**

| chiave | tipo | obbligatoria | descrizione |
|---|---|---|---|
| `label` | string | sì | testo già tradotto nella lingua del visitatore, max 160 caratteri; il core lo escapa (niente HTML) |
| `url` | string | sì | percorso same-origin che inizia con `/`; URL assoluti, `//host`, `javascript:` e `data:` vengono scartati |
| `items` | array | no | i match veri e propri: `['label' => …, 'url' => …, 'meta' => …]`, con `label`/`url` validati come sopra e `meta` riga di dettaglio in solo testo. Ne vengono mostrati al massimo 5 |
| `total` | int | no | quanti match esistono in tutto nel corpus del plugin; ignorato se minore del numero di `items` |

`items` e `total` sono additivi (dalla 0.7.86): un listener che restituisce solo `label` e `url` continua a funzionare e viene reso come semplice link di sezione.

**Regole:** il listener **non deve** restituire un suggerimento quando non ha riscontri — il core non rende nulla se l'array è vuoto, ed è esattamente questo il senso del suggerimento. Un listener che solleva un'eccezione o restituisce dati malformati non rompe mai la pagina del catalogo: il suggerimento semplicemente non compare. Vengono mostrati al massimo 5 suggerimenti.

**Restituisce:** array - i suggerimenti, validati dal core prima del rendering

Esempio (dal plugin `emeroteca`):

```php
$suggestions[] = [
    'label' => __('Articoli nell’emeroteca (%d)', $total),
    'url'   => url('/emeroteca/articoli') . '?q=' . rawurlencode($term),
    'items' => [
        ['label' => 'Intertextuality in Tyll', 'url' => url('/emeroteca/articolo/12'), 'meta' => 'Schweissinger · IJLL · giugno 2019 · 138-148'],
    ],
    'total' => $total,
];
```

### Hook Digital Library (Action)

**Status:** Implementati
**Plugin:** `digital-library`

Invocati via helper `do_action(...)` nelle view core; il plugin emette direttamente HTML (echo). Ricevono l'array `$book`.

| Hook | File | Scopo |
|------|------|-------|
| `book.detail.digital_buttons` | `frontend/book-detail.php:2029` | Pulsanti download/lettura nella scheda libro |
| `book.detail.digital_player` | `frontend/book-detail.php:2036` | Player audio/PDF inline |
| `book.badge.digital_icons` | `catalog-grid.php`, `home-books-grid.php`, `archive.php`, `book-detail.php` | Badge "digitale" nelle griglie e tra i correlati |
| `book.form.digital_fields` | `libri/partials/book_form.php:651` | Campi upload contenuto digitale nel form libro |

---

## Plugin di Esempio

Questa sezione mostra plugin completi che utilizzano gli hook del sistema.

### Plugin: Open Library Scraper

**Percorso:** `app/Plugins/OpenLibrary/`
**Stato:**  Installato
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

-  **API-based scraping** - Usa API REST invece di HTML parsing
-  **Alta priorità** (5) - Preferito rispetto a scraping HTML
-  **Dati arricchiti** - Include opere, edizioni e autori completi
-  **Copertine HD** - Accesso diretto a immagini alta risoluzione
-  **Multilingua** - Supporta tutte le lingue disponibili su Open Library
-  **Fallback intelligente** - Se mancano dati, lascia gestire ad altri plugin
-  **Error handling** - Non blocca lo scraping in caso di errori

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

## Note sull'Uso degli Hook

### Priorità
- Valori più bassi = esecuzione prima
- Default: 10
- Range consigliato: 1-100

### Best Practices

1. **Always Return in Filters**
   ```php
   //  CORRETTO
   Hooks::add('book.data.get', function($data, $id) {
       $data['custom'] = 'value';
       return $data; // IMPORTANTE
   }, 10);

   //  ERRATO
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
   //  Efficiente - cache risultati pesanti
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

## Registrazione Hook

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

**Documentazione aggiornata:** 2026-09
**Hook di integrazione aggiunti:** `app.routes.register`, `admin.menu.render`, `assets.head`, `assets.footer`, `search.unified.sources`, `frontend.catalog.archive_results`, `sitemap.entries`
**Hook di entità e manutenzione aggiunti:** `book.admin.external_links`, `author.form.fields`, `publisher.merging`, `publisher.deleting`, `genre.merging`, `shelf.can_delete`, `shelf.deleted`, `search.external_suggestions`, `mobile_api.dispatch_push`, `maintenance.after_run`
**Nota:** gli hook con stato "Documentato" (es. `loan.*`, `reservation.*`, `catalog.query.modify`, `book.delete.*`, `admin.menu.items`) sono punti di estensione pianificati, **non** ancora invocati dal core. Gli hook della sezione "Desiderata" qui sotto sono invece invocati dal core. Ogni hook invocato dal core (`app/` e `cron/`, con `Hooks::do()`, `Hooks::apply()`, `do_action()`, `apply_filters()` o direttamente `HookManager::doAction()`/`applyFilters()`) è descritto in questa pagina; tutti usano un nome letterale, nessuno è costruito dinamicamente. Non sono elencati qui gli hook che un plugin bundled emette solo per le proprie estensioni: `bookclub.club.created`, `bookclub.member.joined`, `bookclub.member.left`, `bookclub.book.proposed`, `bookclub.book.state_changed`, `bookclub.poll.opened`, `bookclub.poll.closed`, `bookclub.meeting.created`, `bookclub.meeting.reminded` (plugin `book-club`, vedi il suo README) e `mobile_api.openapi` (filtro del plugin `mobile-api` sul documento OpenAPI). Gli hook `scrape.before`, `scrape.after`, `scrape.parse`, `scrape.http.options`, `scrape.validate.data`, `scrape.validation.failed` e `scrape.error` sono descritti sopra ma oggi vengono emessi dai plugin di scraping (`scraping-pro`, `api-book-scraper`), non dal core. Per verificare se un hook è davvero invocato, cercare il nome in `app/` e `cron/`.


## Desiderata: estensioni del modulo e della homepage

### `book.form.before_copies` (Action)

Riceve `array $bookData, ?int $bookId`. Emette campi prima del controllo delle copie nel modulo di creazione/modifica. Il plugin Desiderata usa questo punto per il checkbox esplicito che blocca le copie iniziali.

### `book.form.save` (Filter)

Riceve `array $fields, array $submittedData, ?int $bookId` e restituisce i campi normalizzati. Eseguito solo dal salvataggio del modulo libri, prima di `book.save.before` e prima della transazione di creazione. Non aprire transazioni in questo filtro. Gli import che non contengono i campi del modulo non cambiano il flag desiderata.

Come gli altri filtri, gli errori sono intercettati dal gestore hook: non usare eccezioni nel filtro come unica barriera di autorizzazione o validazione. La consistenza delle copie resta responsabilità del repository e del ricalcolo del core.

### `frontend.home.sections` (Action)

Nessun parametro. Invocato da `app/Views/frontend/home.php` con `Hooks::do('frontend.home.sections')` dopo il ciclo delle sezioni configurate: ciò che emette finisce sempre in fondo alla homepage, indipendentemente da `display_order` e `is_active`. Per una sezione che deve rispettare l'ordine e l'attivazione impostati nel CMS usare `frontend.home.section` (singolare). Una homepage anonima può non avere una sessione: per form mutanti richiedere il token dall’endpoint `/csrf-token` al momento dell’invio, senza incorporare token di sessione in HTML condivisibile in cache.

### `frontend.home.section` (Action)

Riceve `string $sectionKey, array $section`. Invocato da `app/Views/frontend/home.php` con `Hooks::do('frontend.home.section', [$sectionKey, $section])` **dentro** il ciclo ordinato delle sezioni (`ORDER BY display_order ASC, section_key ASC`), per ogni riga di `home_content` attiva che non ha un template core in `app/Views/frontend/home-sections/{section_key}.php`. `$section` è la riga di `home_content` (`section_key`, `title`, `subtitle`, `content`, `button_text`, `button_link`, `background_image`, campi SEO, `is_active`, `display_order`). Le righe con `is_active` vuoto vengono saltate prima dell'hook. È così che una sezione di plugin rispetta ordine e attivazione come le sezioni core.

L'hook scatta per **ogni** sezione senza template core: l'handler deve controllare `$sectionKey` e uscire subito se la chiave non è la propria. La riga in `home_content` appartiene al plugin, che la crea e la rimuove nel proprio ciclo di vita (attivazione/disattivazione). Le stesse regole su sessione e token CSRF di `frontend.home.sections` valgono qui.

```php
public function renderHomeSection(string $sectionKey, array $section): void
{
    if ($sectionKey !== 'desiderata') { return; }
    require __DIR__ . '/views/public.php';
}
```

### `cms.home.section_name` (Filter)

Riceve `string $label, string $key` e restituisce l'etichetta da mostrare per la sezione nell'elenco ordinabile di `/admin/cms/home`. Invocato da `app/Views/cms/edit-home.php` con `Hooks::apply('cms.home.section_name', $default, [$key])`, dove `$default` è il nome della sezione core oppure, per una chiave sconosciuta, la chiave resa leggibile (`ucfirst(str_replace('_', ' ', $key))`). Il valore restituito viene stampato: se non è una stringa viene scartato e resta `$default`. L'handler deve restituire `$label` invariato per le chiavi che non gli appartengono.

```php
public function cmsSectionName(string $label, string $key): string
{
    return $key === 'desiderata' ? __('Desiderata e donazioni') : $label;
}
```

### `cms.home.section.fields` (Action)

Riceve `array $sections`: tutte le righe di `home_content` indicizzate per `section_key`. Invocato da `app/Views/cms/edit-home.php` con `Hooks::do('cms.home.section.fields', [$sections])` **dentro** il `<form>` dell'editor homepage, subito prima del pulsante di salvataggio. Il plugin emette (echo) la propria card di campi: essendo nel form, i campi vengono inviati insieme al resto e arrivano al filtro `cms.home.save`. Se la riga del plugin non esiste in `$sections` (plugin disattivato), l'handler non deve emettere nulla. Gli attributi `name` dei campi vanno annidati sotto la chiave della sezione (es. `desiderata[texts][it_IT][title]`), perché `cms.home.save` riceve il body completo del POST.

### `cms.home.save` (Filter)

Riceve `array $errors, array|null $data` e restituisce l'array degli errori. Invocato da `CmsController::updateHome()` con `Hooks::apply('cms.home.save', $errors, [$data])`, dove `$data` è `$request->getParsedBody()` (tutto il form di `/admin/cms/home`) ed `$errors` è l'elenco (stringhe) degli errori di validazione delle sezioni core. Contratto del filtro:

- restituire l'array `$errors` ricevuto, eventualmente esteso con messaggi (stringhe in chiaro: la view le esegue l'escape);
- scrivere sul database **solo** se l'array ricevuto è vuoto: è la stessa regola "un campo non valido scarta l'intero invio" che rispettano tutti i blocchi core;
- intercettare le proprie eccezioni: `HookManager::applyFilters()` cattura un `\Throwable` sfuggito all'handler e mantiene il valore non filtrato, quindi un'eccezione non gestita produrrebbe un "salvataggio riuscito" senza che il plugin abbia scritto nulla.

Se il valore restituito non è un array viene ignorato; se lo è, vengono tenute solo le voci stringa. Quando il filtro viene eseguito le sezioni core sono **già** state scritte: se gli errori arrivano solo dagli handler, l'operatore vede "Le sezioni principali sono state salvate, ma una sezione aggiuntiva ha segnalato un problema", non "Nessuna modifica è stata salvata". La cache dei contenuti homepage viene invalidata ogni volta che le sezioni core sono state scritte: sia quando l'elenco finale degli errori è vuoto, sia quando gli errori arrivano solo dagli handler di questo filtro. Non viene invalidata quando la validazione core respinge l'invio, perché in quel caso non è stato salvato nulla.

```php
public function cmsSave(array $errors, mixed $data): array
{
    if ($errors !== []) { return $errors; }
    if (!is_array($data)) { return $errors; }
    $own = $data['desiderata'] ?? null;
    if (!is_array($own)) { return $errors; }
    // ... validare; in caso di problemi: $errors[] = __('...'); return $errors;
    // ... scrivere solo a validazione superata
    return $errors;
}
```

### `admin.dashboard.sections` (Action)

Nessun parametro. Invocato da `app/Views/dashboard/index.php` con `Hooks::do('admin.dashboard.sections')` dopo le urgenze di circolazione e prima degli elenchi informativi (libri recenti), fuori dal blocco condizionato dalla modalità catalogo, quindi anche una biblioteca che non presta riceve i pannelli. Il plugin emette (echo) i propri pannelli. La rotta della dashboard è aperta anche agli utenti `standard` e `premium`, ma l'hook scatta **solo** se `$isAdminOrStaff` è vero: gli utenti non staff non eseguono i callback e non ricevono markup amministrativo. Un pannello non deve compromettere la dashboard: intercettare gli errori, registrarli con `SecureLogger::error()` e non emettere nulla.

### `book.visibility.discoverable` (Filter)

Riceve `string $predicate, \mysqli $db, string $alias` e restituisce un predicato SQL. Invocato da `App\Support\BookVisibility::discoverable()` con `Hooks::apply('book.visibility.discoverable', $default, [$db, $alias])`, dove `$default` è il predicato di catalogo (`BookVisibility::catalogue()`: `<alias>.is_desiderata = 0` se la colonna esiste, altrimenti `1=1`). `discoverable()` decide cosa un visitatore può trovare cercandolo per nome e aprire tramite link (ricerca e scheda libro, in `SearchController` e `FrontendController`); navigazione del catalogo, feed, sitemap, API mobile e protocolli di interoperabilità restano su `catalogue()` e non passano da questo filtro.

**Regola di sicurezza:** il valore restituito viene concatenato direttamente nelle clausole `WHERE`. Per questo l'unico valore accettato per allargare il predicato è **esattamente** la stringa `'1=1'`; qualsiasi altro valore, compresa una stringa dall'aspetto innocuo come `'1=1 OR l.id > 0'`, viene scartato e resta il predicato di catalogo. Un handler può solo allargare a "tutto" oppure lasciare il predicato invariato: non può restringerlo né iniettare SQL. Il plugin Desiderata restituisce `'1=1'` mentre è attivo, così un titolo desiderato compare (con badge) nei risultati di ricerca e sulla propria pagina.

```php
public function discoverable(mixed $predicate = null, mixed $db = null, mixed $alias = null): string
{
    return '1=1';
}
```
