# Open Library Plugin

Plugin per l'integrazione delle API di Open Library (openlibrary.org) nel sistema di scraping della Biblioteca.

## Caratteristiche

- **Scraping completo via API**: Utilizza le API ufficiali di Open Library invece dello scraping HTML
- **Copertine ad alta qualità**: Accesso diretto alle copertine in alta risoluzione
- **Dati arricchiti**: Include informazioni su opere, edizioni e autori
- **Multilingua**: Supporta libri in tutte le lingue disponibili su Open Library
- **Alta priorità**: Configurato con priorità 5 (alta) per essere preferito rispetto ad altre fonti

## API Utilizzate

Il plugin integra le seguenti API di Open Library:

1. **ISBN API** - `/isbn/{isbn}.json` - Dati sull'edizione
2. **Works API** - `/works/{id}.json` - Informazioni sull'opera
3. **Authors API** - `/authors/{id}.json` - Dettagli autori
4. **Covers API** - `https://covers.openlibrary.org/b/isbn/{isbn}-L.jpg` - Copertine

## Dati Forniti

Il plugin estrae e mappa i seguenti dati:

- **Titolo e sottotitolo**
- **Autori** (con nomi completi)
- **Editore e data di pubblicazione**
- **ISBN/EAN**
- **Numero di pagine**
- **Formato** (brossura, rilegato, eBook, ecc.)
- **Descrizione** (preferibilmente dall'opera)
- **Copertina** (URL ad alta risoluzione)
- **Serie** (se disponibile)
- **Soggetti/argomenti**
- **Tipologia** (narrativa/saggistica)
- **Lingua**

## Hook Utilizzati

Il plugin si integra con i seguenti hook dello scraping:

### `scrape.sources` (Priorità: 5)
Aggiunge Open Library come fonte di scraping con alta priorità.

```php
$sources['openlibrary'] = [
    'name' => 'Open Library',
    'enabled' => true,
    'priority' => 5,
    'fields' => ['title', 'authors', 'publisher', 'description', 'image', ...]
];
```

### `scrape.fetch.custom` (Priorità: 5)
Implementa la logica personalizzata per recuperare i dati tramite API.

```php
public function fetchFromOpenLibrary($current, array $sources, string $isbn): ?array
{
    $editionData = $this->fetchEditionByISBN($isbn);
    $workData = $this->fetchWork($workKey);
    $authorData = $this->fetchAuthor($authorKey);
    // ... mapping dei dati
}
```

### `scrape.data.modify` (Priorità: 10)
Arricchisce i dati esistenti con informazioni aggiuntive (es. copertine mancanti).

## Installazione

### 1. Attivazione Automatica

Aggiungi il plugin al file di bootstrap:

```php
// public/index.php o bootstrap.php
$openLibraryPlugin = new \App\Plugins\OpenLibrary\OpenLibraryPlugin();
$openLibraryPlugin->activate();
```

### 2. Verifica

Testa lo scraping di un ISBN esistente su Open Library:

```bash
curl 'http://localhost/admin/scrape?isbn=9780140328721'
```

Dovresti vedere nella risposta:
```json
{
  "title": "Fantastic Mr. Fox",
  "source": "https://openlibrary.org/isbn/9780140328721",
  "_openlibrary_edition_key": "/books/OL7353617M",
  ...
}
```

## Configurazione

### Disabilitare Open Library

Se vuoi disabilitare il plugin temporaneamente:

```php
Hooks::add('scrape.sources', function($sources) {
    $sources['openlibrary']['enabled'] = false;
    return $sources;
}, 99); // Priorità alta per sovrascrivere
```

### Cambiare Priorità

Per dare priorità ad altre fonti:

```php
Hooks::add('scrape.sources', function($sources) {
    $sources['openlibrary']['priority'] = 50; // Priorità bassa
    return $sources;
}, 99);
```

### Timeout Personalizzato

Modifica la costante `TIMEOUT` nel file `OpenLibraryPlugin.php`:

```php
private const TIMEOUT = 30; // 30 secondi invece di 15
```

## Limiti e Considerazioni

### Rate Limiting
Open Library non ha rate limiting ufficiale documentato per le API di lettura, ma è buona pratica:
- Non fare più di 1 richiesta al secondo
- Implementare caching locale dei risultati
- Usare un User-Agent identificabile

### Copertura
- **Ottima** per libri in inglese e bestseller internazionali
- **Buona** per classici e libri accademici
- **Variabile** per libri recenti in lingue diverse dall'inglese
- **Limitata** per pubblicazioni locali o di nicchia

### Copertine
- Le copertine sono disponibili in formato JPG in 3 dimensioni (S/M/L)
- Non tutti i libri hanno copertine disponibili
- Il plugin verifica la presenza effettiva dell'immagine prima di restituire l'URL

## Debug

Per debuggare il plugin, abilita i log di errore:

```php
// Nella funzione fetchFromOpenLibrary
error_log('OpenLibrary Plugin: Fetching ISBN ' . $isbn);
error_log('OpenLibrary Response: ' . print_r($editionData, true));
```

Oppure usa l'hook `scrape.error`:

```php
Hooks::add('scrape.error', function($errorData) {
    error_log('Scraping Error: ' . json_encode($errorData));
});
```

## Esempi di Utilizzo

### Esempio 1: Scraping semplice

```bash
# Scrape "The Great Gatsby"
curl 'http://localhost/admin/scrape?isbn=9780743273565'
```

### Esempio 2: Integrazione con altri plugin

Il plugin si integra automaticamente con altri plugin. Se un altro plugin ha già gestito la richiesta, OpenLibraryPlugin non interviene.

```php
// AmazonPlugin con priorità 3 (più alta)
// OpenLibraryPlugin con priorità 5
// Se Amazon trova i dati, OpenLibrary non li sovrascrive
```

### Esempio 3: Arricchimento dati

Anche se un'altra fonte fornisce i dati base, OpenLibrary può arricchire con copertine:

```php
// LibreriaUniversitaria trova il libro ma senza copertina
// OpenLibrary aggiunge la copertina tramite scrape.data.modify
```

## Estensioni Future

Possibili miglioramenti del plugin:

1. **Cache locale**: Salvare i risultati delle API per ridurre le richieste
2. **Search API**: Cercare libri anche senza ISBN tramite titolo/autore
3. **Batch requests**: Supporto per richieste multiple
4. **Traduzioni**: Preferire edizioni in italiano quando disponibili
5. **Ratings**: Integrare le valutazioni da Open Library

## Link Utili

- [Open Library API Docs](https://openlibrary.org/developers/api)
- [Open Library Covers API](https://openlibrary.org/dev/docs/api/covers)
- [Open Library Books API](https://openlibrary.org/dev/docs/api/books)
- [Open Library Data Dumps](https://openlibrary.org/developers/dumps)

## Licenza

Questo plugin è parte del progetto Biblioteca ed è rilasciato sotto la stessa licenza del progetto principale.

I dati di Open Library sono disponibili sotto licenza CC BY-SA.
