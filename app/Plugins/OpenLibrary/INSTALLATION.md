# Open Library Plugin - Guida all'Installazione

## ✅ Stato Installazione

Il plugin Open Library è **già installato e attivo** nel tuo sistema Biblioteca.

## 📁 File del Plugin

```
app/Plugins/OpenLibrary/
├── OpenLibraryPlugin.php    # Classe principale del plugin
├── plugin.json              # Manifest del plugin
├── activate.php             # Script di attivazione rapida
├── test.php                 # Script di test
├── README.md                # Documentazione completa
└── INSTALLATION.md          # Questa guida
```

## 🚀 Verifica Installazione

### 1. Verifica che il plugin sia caricato

Il plugin viene caricato automaticamente in `public/index.php` alle righe 350-355:

```php
// Load plugins
if (file_exists(__DIR__ . '/../app/Plugins/OpenLibrary/activate.php')) {
    require __DIR__ . '/../app/Plugins/OpenLibrary/activate.php';
}
```

### 2. Test via Browser

Accedi all'interfaccia di scraping e inserisci un ISBN:

```
http://localhost/admin/libri/scrape
```

Prova con questi ISBN:
- `9780140328721` - Fantastic Mr. Fox di Roald Dahl
- `9780451526538` - 1984 di George Orwell
- `9788804671664` - Il nome della rosa di Umberto Eco

Se il plugin funziona, nel campo "source" della risposta JSON vedrai:
```json
{
  "source": "https://openlibrary.org/isbn/...",
  "title": "...",
  "author": "...",
  ...
}
```

### 3. Test via Command Line

```bash
# Test API diretta
curl 'http://localhost/admin/scrape?isbn=9780140328721'

# Test script PHP
cd /Users/fabio/Documents/GitHub/biblioteca
php app/Plugins/OpenLibrary/test.php
```

### 4. Verifica Log

Controlla i log di PHP per vedere se il plugin è stato attivato:

```bash
tail -f /var/log/php/error.log | grep OpenLibrary
```

Dovresti vedere:
```
[OpenLibrary] Plugin activated successfully
```

## 🔧 Configurazione

### Priorità del Plugin

Il plugin Open Library ha **priorità 5** (alta). Questo significa che viene eseguito **prima** dello scraping HTML da LibreriaUniversitaria (priorità 10).

L'ordine di esecuzione è:
1. **Open Library** (priorità 5) - API
2. LibreriaUniversitaria (priorità 10) - HTML scraping
3. Feltrinelli Covers (priorità 20) - Solo copertine

### Modificare la Priorità

Se vuoi cambiare la priorità del plugin, modifica `OpenLibraryPlugin.php`:

```php
// Nella funzione addOpenLibrarySource()
$sources['openlibrary'] = [
    // ...
    'priority' => 15, // Cambia qui (valori più alti = priorità più bassa)
];
```

### Disabilitare il Plugin

#### Metodo 1: Commentare il caricamento

In `public/index.php`:

```php
// Commenta queste righe:
// if (file_exists(__DIR__ . '/../app/Plugins/OpenLibrary/activate.php')) {
//     require __DIR__ . '/../app/Plugins/OpenLibrary/activate.php';
// }
```

#### Metodo 2: Disabilitare via Hook

Aggiungi in `public/index.php` dopo il caricamento del plugin:

```php
// Disabilita Open Library
\App\Support\Hooks::add('scrape.sources', function($sources) {
    $sources['openlibrary']['enabled'] = false;
    return $sources;
}, 99);
```

## 📊 Copertura e Limiti

### Copertura ISBN

- **Ottima** (90%+): Libri in inglese, bestseller internazionali
- **Buona** (70%+): Classici, libri accademici
- **Variabile** (40%+): Libri recenti in altre lingue
- **Limitata** (<30%): Pubblicazioni locali, edizioni rare

### Limiti Tecnici

- **Timeout**: 15 secondi per richiesta
- **Rate limiting**: Nessun limite ufficiale documentato
- **Dimensione risposta**: Variabile (1-50KB per libro)
- **Richieste multiple**: 1 richiesta per edizione + 1 per opera + N per autori

### Best Practices

1. **Cache locale**: Salva i risultati nel database per evitare richieste ripetute
2. **Fallback**: Il plugin lascia automaticamente gestire ad altri scraper se non trova dati
3. **Error handling**: Gli errori vengono loggati ma non bloccano il processo di scraping

## 🐛 Troubleshooting

### Il plugin non funziona

1. **Verifica che curl sia abilitato in PHP**:
   ```bash
   php -m | grep curl
   ```

2. **Verifica connessione a Open Library**:
   ```bash
   curl -I https://openlibrary.org
   ```

3. **Controlla i log di errore**:
   ```bash
   tail -50 /var/log/php/error.log | grep -i "openlibrary\|curl"
   ```

### Scraping lento

Se lo scraping è lento, potrebbe essere per:
- **Multiple richieste API**: Il plugin fa 1 richiesta per edizione + 1 per opera + N per autori
- **Timeout lunghi**: 15 secondi per richiesta

**Soluzione**: Riduci il timeout in `OpenLibraryPlugin.php`:

```php
private const TIMEOUT = 10; // Ridotto da 15 a 10 secondi
```

### Nessuna copertina trovata

Le copertine potrebbero non essere disponibili per tutti i libri. Il plugin:
1. Cerca prima tramite Cover ID (dall'edizione)
2. Poi cerca tramite ISBN
3. Verifica che l'immagine esista effettivamente (non sia un placeholder)

Se ancora non funziona, prova manualmente:
```bash
curl -I "https://covers.openlibrary.org/b/isbn/9780140328721-L.jpg"
```

## 📚 Documentazione Aggiuntiva

- **README.md** - Panoramica completa del plugin
- **PLUGIN_HOOKS.md** (docs/) - Documentazione di tutti gli hook con esempi
- **API Open Library** - https://openlibrary.org/developers/api

## 🆘 Supporto

Per problemi o domande:

1. Controlla i log: `tail -f /var/log/php/error.log`
2. Esegui il test: `php app/Plugins/OpenLibrary/test.php`
3. Verifica la connessione: `curl https://openlibrary.org/isbn/9780140328721.json`

---

**Data installazione**: 2025-01-14
**Versione plugin**: 1.0.0
**Versione API Open Library**: v1
