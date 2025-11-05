# Plugin Hooks Reference

Questo documento elenca tutti gli hook disponibili nel sistema di plugin di Pinakes.

## Tipi di Hook

Ci sono due tipi di hook:

- **Filter**: Modificano e restituiscono un valore
- **Action**: Eseguono codice senza restituire un valore

## Hook per Libri

### `book.data.get` (Filter)
Modifica i dati del libro quando vengono recuperati dal database.

**Parametri:**
- `$bookData` (array): Dati del libro
- `$bookId` (int): ID del libro

**Esempio:**
```php
public function filterBookData($bookData, $bookId) {
    $bookData['custom_field'] = $this->getCustomField($bookId);
    return $bookData;
}
```

### `book.fields.backend.form` (Action)
Aggiunge campi personalizzati al form di modifica/creazione libro nel backend.

**Parametri:**
- `$bookData` (array|null): Dati del libro (null se creazione)
- `$bookId` (int|null): ID del libro (null se creazione)

### `book.save.before` (Action)
Eseguito prima di salvare un libro.

**Parametri:**
- `$bookData` (array): Dati del libro da salvare
- `$bookId` (int|null): ID del libro (null se creazione)

### `book.save.after` (Action)
Eseguito dopo aver salvato un libro.

**Parametri:**
- `$bookId` (int): ID del libro salvato
- `$bookData` (array): Dati del libro salvati

### `book.delete.before` (Action)
Eseguito prima di eliminare un libro.

**Parametri:**
- `$bookId` (int): ID del libro da eliminare

### `book.frontend.details` (Action)
Aggiunge contenuto nella pagina dettaglio libro nel frontend.

**Parametri:**
- `$bookData` (array): Dati del libro
- `$bookId` (int): ID del libro

### `book.frontend.card` (Filter)
Modifica il contenuto della card libro nel frontend.

**Parametri:**
- `$cardHtml` (string): HTML della card
- `$bookData` (array): Dati del libro

## Hook per Autori

### `author.data.get` (Filter)
Modifica i dati dell'autore quando vengono recuperati.

**Parametri:**
- `$authorData` (array): Dati dell'autore
- `$authorId` (int): ID dell'autore

### `author.fields.backend.form` (Action)
Aggiunge campi personalizzati al form autore nel backend.

**Parametri:**
- `$authorData` (array|null): Dati dell'autore
- `$authorId` (int|null): ID dell'autore

### `author.save.after` (Action)
Eseguito dopo aver salvato un autore.

**Parametri:**
- `$authorId` (int): ID dell'autore
- `$authorData` (array): Dati dell'autore

### `author.frontend.details` (Action)
Aggiunge contenuto nella pagina autore nel frontend.

**Parametri:**
- `$authorData` (array): Dati dell'autore
- `$authorId` (int): ID dell'autore

## Hook per Editori

### `publisher.data.get` (Filter)
Modifica i dati dell'editore quando vengono recuperati.

**Parametri:**
- `$publisherData` (array): Dati dell'editore
- `$publisherId` (int): ID dell'editore

### `publisher.fields.backend.form` (Action)
Aggiunge campi personalizzati al form editore nel backend.

**Parametri:**
- `$publisherData` (array|null): Dati dell'editore
- `$publisherId` (int|null): ID dell'editore

### `publisher.save.after` (Action)
Eseguito dopo aver salvato un editore.

**Parametri:**
- `$publisherId` (int): ID dell'editore
- `$publisherData` (array): Dati dell'editore

### `publisher.frontend.details` (Action)
Aggiunge contenuto nella pagina editore nel frontend.

**Parametri:**
- `$publisherData` (array): Dati dell'editore
- `$publisherId` (int): ID dell'editore

## Hook per Login

### `login.form.before` (Action)
Eseguito prima del form di login nel template.

### `login.form.after` (Action)
Eseguito dopo il form di login nel template.

### `login.validate` (Filter)
Permette validazione personalizzata durante il login.

**Parametri:**
- `$isValid` (bool): Risultato validazione predefinita
- `$credentials` (array): Credenziali fornite

### `login.success` (Action)
Eseguito dopo un login riuscito.

**Parametri:**
- `$userId` (int): ID dell'utente
- `$userData` (array): Dati dell'utente

### `login.failed` (Action)
Eseguito dopo un login fallito.

**Parametri:**
- `$credentials` (array): Credenziali fornite (senza password)

## Hook per Catalogo/Ricerca

### `catalog.filters.additional` (Filter)
Aggiunge filtri personalizzati alla ricerca nel catalogo.

**Parametri:**
- `$filters` (array): Array di filtri esistenti

### `catalog.query.modify` (Filter)
Modifica la query SQL per la ricerca libri.

**Parametri:**
- `$query` (string): Query SQL
- `$params` (array): Parametri della query

### `catalog.results.modify` (Filter)
Modifica i risultati della ricerca prima della visualizzazione.

**Parametri:**
- `$results` (array): Array di risultati

## Hook per Scraping

### `scrape.sources` (Filter)
Aggiunge fonti di scraping personalizzate.

**Parametri:**
- `$sources` (array): Array di fonti disponibili

### `scrape.before` (Action)
Eseguito prima di iniziare lo scraping.

**Parametri:**
- `$source` (string): Fonte di scraping
- `$query` (string): Query di ricerca

### `scrape.parse` (Filter)
Permette parsing personalizzato dei dati scrapati.

**Parametri:**
- `$parsedData` (array): Dati parsati
- `$rawData` (string): Dati grezzi
- `$source` (string): Fonte

### `scrape.after` (Action)
Eseguito dopo lo scraping.

**Parametri:**
- `$result` (array): Risultato dello scraping
- `$source` (string): Fonte

## Hook per Immagini

### `image.upload.before` (Action)
Eseguito prima del caricamento di un'immagine.

**Parametri:**
- `$filename` (string): Nome del file
- `$tmpPath` (string): Percorso temporaneo

### `image.upload.after` (Action)
Eseguito dopo il caricamento di un'immagine.

**Parametri:**
- `$filename` (string): Nome del file salvato
- `$path` (string): Percorso finale

### `image.process` (Filter)
Permette elaborazione personalizzata dell'immagine.

**Parametri:**
- `$imagePath` (string): Percorso dell'immagine
- `$options` (array): Opzioni di elaborazione

### `image.delete.before` (Action)
Eseguito prima di eliminare un'immagine.

**Parametri:**
- `$imagePath` (string): Percorso dell'immagine da eliminare

## Hook Generici

### `app.init` (Action)
Eseguito all'inizializzazione dell'applicazione.

### `app.request.before` (Action)
Eseguito prima di processare ogni richiesta.

**Parametri:**
- `$request` (ServerRequestInterface): Oggetto richiesta

### `app.response.before` (Action)
Eseguito prima di inviare la risposta.

**Parametri:**
- `$response` (ResponseInterface): Oggetto risposta

### `admin.menu.items` (Filter)
Permette di aggiungere voci al menu amministrazione.

**Parametri:**
- `$menuItems` (array): Array di voci di menu

### `frontend.menu.items` (Filter)
Permette di aggiungere voci al menu frontend.

**Parametri:**
- `$menuItems` (array): Array di voci di menu

## Note sull'Uso degli Hook

1. Gli hook **Filter** devono sempre restituire un valore
2. Gli hook **Action** eseguono codice senza restituire nulla
3. La priorità di esecuzione è determinata dal parametro `priority` (valore predefinito: 10, minore = prima)
4. Gli hook sono caricati automaticamente all'attivazione del plugin
5. Gli hook personalizzati possono essere registrati a runtime con `Hooks::add()`

## Esempio di Registrazione Hook

Nel file principale del plugin:

```php
public function onActivate() {
    $this->hookManager->addHook('book.data.get', 'MyPlugin\\BookHandler', 'enrichBookData', 10);
}
```

O registrazione diretta nel codice del plugin:

```php
Hooks::add('book.save.after', function($bookId, $bookData) {
    // Il tuo codice qui
}, 10);
```
