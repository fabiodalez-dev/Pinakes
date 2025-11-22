# Flusso di Scraping - Open Library + Sistema Esistente

## 📊 Panoramica

Il plugin Open Library si integra perfettamente con lo scraping esistente (LibreriaUniversitaria/Feltrinelli) usando il sistema di hook. Non sostituisce lo scraping esistente, ma lo **arricchisce** con una fonte aggiuntiva ad alta priorità.

## 🔄 Flusso Completo (Step by Step)

```
┌─────────────────────────────────────────────────────┐
│  1. RICHIESTA: GET /admin/scrape?isbn=9780451526538 │
└─────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────┐
│  2. VALIDAZIONE ISBN                                │
│     ├─ Formato: ISBN-10 o ISBN-13                  │
│     ├─ Checksum validation                         │
│     └─ Hook: scrape.isbn.validate ⚡                │
└─────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────┐
│  3. CARICAMENTO FONTI DEFAULT                       │
│     ScrapeController.php:37                         │
│     ┌──────────────────────────────────────┐        │
│     │ 'libreriauniversitaria' => [         │        │
│     │   'priority' => 10,                  │        │
│     │   'enabled' => true                  │        │
│     │ ],                                   │        │
│     │ 'feltrinelli_cover' => [             │        │
│     │   'priority' => 20,                  │        │
│     │   'enabled' => true,                 │        │
│     │   'fields' => ['image']              │        │
│     │ ]                                    │        │
│     └──────────────────────────────────────┘        │
└─────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────┐
│  4. HOOK: scrape.sources ⚡                          │
│     ScrapeController.php:40                         │
│     ┌──────────────────────────────────────┐        │
│     │ OpenLibrary Plugin aggiunge:         │        │
│     │ 'openlibrary' => [                   │        │
│     │   'priority' => 5,  ← PIÙ ALTA!     │        │
│     │   'enabled' => true                  │        │
│     │ ]                                    │        │
│     └──────────────────────────────────────┘        │
│                                                     │
│     Fonti finali ordinate per priorità:            │
│     1. openlibrary (5) ← PRIMA                     │
│     2. libreriauniversitaria (10)                  │
│     3. feltrinelli_cover (20)                      │
└─────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────┐
│  5. HOOK: scrape.fetch.custom ⚡                     │
│     ScrapeController.php:43                         │
│     ┌──────────────────────────────────────┐        │
│     │ OpenLibrary Plugin prova a fare      │        │
│     │ lo scraping via API:                 │        │
│     │                                      │        │
│     │ if ($current !== null) {             │        │
│     │   return $current; // già gestito    │        │
│     │ }                                    │        │
│     │                                      │        │
│     │ if (!$sources['openlibrary']) {      │        │
│     │   return null; // lascia ad altri   │        │
│     │ }                                    │        │
│     │                                      │        │
│     │ $data = API call...                 │        │
│     │                                      │        │
│     │ if (!$data) {                        │        │
│     │   return null; // fallback           │        │
│     │ }                                    │        │
│     │                                      │        │
│     │ return [...]; // dati trovati!       │        │
│     └──────────────────────────────────────┘        │
└─────────────────────────────────────────────────────┘
                        ↓
                   ┌─────────┐
                   │ Dati?   │
                   └─────────┘
                   /         \
                 SI           NO
                 ↓            ↓
┌──────────────────────┐  ┌──────────────────────┐
│  6a. PLUGIN GESTITO  │  │  6b. FALLBACK        │
│  ScrapeController:45 │  │  ScrapeController:64 │
│                      │  │                      │
│  ✅ OpenLibrary ha   │  │  ⚠️  OpenLibrary non │
│  trovato i dati!     │  │  ha trovato dati     │
│                      │  │                      │
│  Il sistema usa i    │  │  Procede con lo      │
│  dati del plugin e   │  │  scraping HTML di    │
│  SALTA lo scraping   │  │  LibreriaUniv.       │
│  HTML.               │  │                      │
│                      │  │  ┌────────────────┐  │
│  ┌────────────────┐  │  │  │ 1. Fetch HTML  │  │
│  │ Salta a step 8 │  │  │  │ 2. Parse XPath │  │
│  └────────────────┘  │  │  │ 3. Extract data│  │
│                      │  │  └────────────────┘  │
└──────────────────────┘  └──────────────────────┘
         ↓                         ↓
         └─────────┬───────────────┘
                   ↓
┌─────────────────────────────────────────────────────┐
│  7. HOOK: scrape.data.modify ⚡                      │
│     ScrapeController.php:232                        │
│     ┌──────────────────────────────────────┐        │
│     │ OpenLibrary può arricchire i dati:   │        │
│     │                                      │        │
│     │ if (empty($payload['image'])) {      │        │
│     │   $payload['image'] = getCover();    │        │
│     │ }                                    │        │
│     │                                      │        │
│     │ // Aggiungi rating, metadata, etc.  │        │
│     └──────────────────────────────────────┘        │
└─────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────┐
│  8. HOOK: scrape.response ⚡                         │
│     Ultima modifica prima di restituire             │
└─────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────┐
│  9. RISPOSTA JSON                                   │
│     {                                               │
│       "title": "1984",                              │
│       "author": "George Orwell",                    │
│       "source": "https://openlibrary.org/...",      │
│       "image": "https://covers.openlibrary.org/..." │
│     }                                               │
└─────────────────────────────────────────────────────┘
```

## 🎯 Scenari Reali

### Scenario 1: Open Library Trova il Libro ✅

```
ISBN: 9780451526538 (1984 - George Orwell)

Step 1-4: Preparazione fonti
  → Open Library aggiunto con priorità 5

Step 5: scrape.fetch.custom
  → OpenLibrary chiama API:
    ✅ GET https://openlibrary.org/isbn/9780451526538.json
    ✅ GET https://openlibrary.org/works/OL1168007W.json
    ✅ GET https://openlibrary.org/authors/OL118077A.json
  → Dati trovati! Restituisce array completo

Step 6a: Dati dal plugin usati
  → LibreriaUniversitaria NON viene chiamato
  → Feltrinelli NON viene chiamato
  → Risparmio di tempo e risorse

Step 7: scrape.data.modify (opzionale)
  → Già ha tutti i dati, nessuna modifica

Step 8-9: Risposta
  {
    "title": "Nineteen Eighty-Four",
    "author": "George Orwell",
    "publisher": "Signet Classic",
    "year": 1950,
    "source": "https://openlibrary.org/isbn/9780451526538"
  }

✅ RISULTATO: Scraping API-based veloce e completo
⏱️  TEMPO: ~2-3 secondi (solo API calls)
```

### Scenario 2: Open Library NON Trova il Libro ⚠️

```
ISBN: 9788858135174 (Libro italiano recente)

Step 1-4: Preparazione fonti
  → Open Library aggiunto con priorità 5

Step 5: scrape.fetch.custom
  → OpenLibrary chiama API:
    ❌ GET https://openlibrary.org/isbn/9788858135174.json → 404
  → Dati NON trovati, restituisce NULL

Step 6b: Fallback a LibreriaUniversitaria
  → Scraping HTML da libreriauniversitaria.it
  → Parse con XPath
  → Estrae: titolo, autore, editore, ecc.
  → Dati trovati!

Step 7: scrape.data.modify
  → OpenLibrary prova ad aggiungere copertina:
    ❌ GET https://covers.openlibrary.org/b/isbn/...L.jpg → 404
  → Nessuna modifica

Step 8-9: Risposta
  {
    "title": "...",
    "author": "...",
    "source": "https://www.libreriauniversitaria.it/..."
  }

✅ RISULTATO: Fallback funziona, dati da LibreriaUniv
⏱️  TEMPO: ~5-8 secondi (1 API call fallita + HTML scraping)
```

### Scenario 3: Open Library Trova Parzialmente 🔄

```
ISBN: 9788804671664 (Il nome della rosa - italiano)

Step 1-4: Preparazione fonti
  → Open Library aggiunto con priorità 5

Step 5: scrape.fetch.custom
  → OpenLibrary chiama API:
    ✅ GET https://openlibrary.org/isbn/9788804671664.json
    ✅ Trova edizione MA senza descrizione
    ✅ Trova autore: Umberto Eco
    ❌ Copertina non disponibile
  → Dati PARZIALI, restituisce array

Step 6a: Dati dal plugin usati
  → LibreriaUniversitaria NON viene chiamato
  → MA dati sono incompleti (no descrizione, no cover)

Step 7: scrape.data.modify
  → Altri plugin potrebbero arricchire
  → Oppure copertina viene aggiunta dopo manualmente

Step 8-9: Risposta
  {
    "title": "Il nome della rosa",
    "author": "Umberto Eco",
    "description": "",  ← VUOTO
    "image": "",        ← VUOTO
    "source": "https://openlibrary.org/isbn/9788804671664"
  }

⚠️  RISULTATO: Dati parziali da Open Library
💡 POSSIBILE MIGLIORAMENTO: Fare merge con LibreriaUniv
```

## 🔧 Configurazioni Possibili

### 1. Open Library come Fonte Primaria (Default) ✅

```php
// Già configurato così!
// Priority: openlibrary(5), libreriauniversitaria(10)
// OpenLibrary prova prima, se fallisce → LibreriaUniv
```

### 2. Solo Open Library (Disabilita HTML Scraping)

```php
Hooks::add('scrape.sources', function($sources) {
    // Disabilita LibreriaUniversitaria
    $sources['libreriauniversitaria']['enabled'] = false;
    $sources['feltrinelli_cover']['enabled'] = false;
    return $sources;
}, 99);
```

### 3. Solo LibreriaUniversitaria (Disabilita Open Library)

```php
Hooks::add('scrape.sources', function($sources) {
    // Disabilita Open Library
    $sources['openlibrary']['enabled'] = false;
    return $sources;
}, 99);
```

### 4. LibreriaUniversitaria Prima (Inverti Priorità)

```php
Hooks::add('scrape.sources', function($sources) {
    // Dai priorità più bassa a Open Library
    $sources['openlibrary']['priority'] = 50;
    return $sources;
}, 99);

// Ordine: libreriauniversitaria(10), feltrinelli(20), openlibrary(50)
```

### 5. Merge dei Dati (Migliore di Entrambi) 🚀

```php
Hooks::add('scrape.data.modify', function($payload, $isbn) {
    // Se Open Library ha fornito dati ma mancano descrizione/cover
    if ($payload['source'] === 'https://openlibrary.org/isbn/' . $isbn) {
        if (empty($payload['description']) || empty($payload['image'])) {
            // Fetch da LibreriaUniversitaria per i dati mancanti
            $libunivData = scrapeFromLibreriaUniv($isbn);

            if (empty($payload['description'])) {
                $payload['description'] = $libunivData['description'];
            }
            if (empty($payload['image'])) {
                $payload['image'] = $libunivData['image'];
            }
        }
    }

    return $payload;
}, 15);
```

## 📈 Statistiche & Performance

### Tempo di Risposta Medio

| Scenario | Open Library | LibreriaUniv | Totale |
|----------|--------------|--------------|--------|
| **Solo OL (trovato)** | 2-3s | 0s (skip) | **2-3s** ⚡ |
| **Solo OL (404)** | 1s (404) | 0s (skip) | **1s** ⚡ |
| **OL fallisce → LU** | 1s (404) | 5-8s | **6-9s** |
| **Solo LU (no plugin)** | 0s | 5-8s | **5-8s** |

### Copertura ISBN (stimata)

| Tipo Libro | Open Library | LibreriaUniv | Combinati |
|------------|--------------|--------------|-----------|
| Bestseller internazionali | **95%** | 80% | **98%** |
| Classici | **90%** | 85% | **95%** |
| Accademici | 70% | **90%** | **95%** |
| Recenti italiani | 40% | **95%** | **95%** |
| Edizioni rare | 30% | **60%** | **70%** |

## 🎓 Best Practices

### ✅ Raccomandazioni

1. **Lascia Open Library abilitato** - Ha priorità alta ma fallback automatico
2. **Monitora i log** - Controlla quali fonti vengono usate più spesso
3. **Cache i risultati** - Salva nel DB per evitare richieste ripetute
4. **Considera merge** - Usa configurazione #5 per il meglio di entrambi

### ❌ Errori Comuni

1. ~~Disabilitare completamente LibreriaUniv~~ - Perdi il fallback
2. ~~Aspettarsi sempre risultati da OL~~ - Non ha copertura 100%
3. ~~Non gestire timeout~~ - API può essere lenta
4. ~~Non cachare risultati~~ - Spreco di risorse

## 🔍 Debug & Troubleshooting

### Come Vedere Quale Fonte Ha Fornito i Dati

Guarda il campo `source` nella risposta JSON:

```json
{
  "source": "https://openlibrary.org/isbn/..." // ← Open Library
}
```

oppure

```json
{
  "source": "https://www.libreriauniversitaria.it/..." // ← LibreriaUniv
}
```

### Log di Debug

Aggiungi logging per vedere il flusso:

```php
// In OpenLibraryPlugin::fetchFromOpenLibrary()
error_log("🔍 [OpenLibrary] Trying ISBN: {$isbn}");

// Se trova dati:
error_log("✅ [OpenLibrary] Found data for ISBN: {$isbn}");

// Se non trova:
error_log("❌ [OpenLibrary] No data for ISBN: {$isbn}, falling back");
```

---

**Conclusione**: Il plugin Open Library si integra perfettamente con lo scraping esistente, fornendo una fonte aggiuntiva ad alta priorità con fallback automatico a LibreriaUniversitaria. Il meglio di entrambi i mondi! 🚀
