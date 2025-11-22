# Open Library Plugin - Guida all'Installazione

## 📦 Installazione tramite Plugin Manager

### 1. Preparazione

Crea un file ZIP con tutti i file del plugin:
```bash
cd storage/plugins
zip -r open-library.zip open-library/
```

### 2. Installazione

1. Accedi al pannello di amministrazione
2. Vai su **Admin → Plugin**
3. Clicca **"Carica Plugin"**
4. Seleziona il file `open-library.zip`
5. Clicca **"Installa"**

Il sistema automaticamente:
- Estrarrà i file nella directory corretta
- Registrerà il plugin nel database
- Configurerà le impostazioni di default

### 3. Attivazione

1. Nella lista dei plugin, trova **"Open Library Scraper"**
2. Clicca sul pulsante **"Attiva"**

**Le route vengono registrate automaticamente!**
- `/api/open-library/test` - Endpoint di test del plugin

## ✅ Verifica Installazione

### Test Endpoint Plugin

Testa il plugin direttamente con l'endpoint di test:

```bash
# Test con ISBN di default (La Divina Commedia)
curl 'http://localhost/api/open-library/test'

# Test con ISBN specifico (Fantastic Mr. Fox)
curl 'http://localhost/api/open-library/test?isbn=9780140328721'
```

**Risposta di successo:**
```json
{
  "success": true,
  "plugin": "Open Library",
  "isbn": "9780140328721",
  "data": {
    "title": "Fantastic Mr. Fox",
    "author": "Roald Dahl",
    "publisher": "Puffin Books",
    "year": "1988",
    ...
  },
  "message": "Book data successfully retrieved from Open Library"
}
```

### Test Scraping Integrato

Il plugin si integra automaticamente con l'endpoint di scraping esistente:

```bash
curl 'http://localhost/api/scrape/isbn?isbn=9780140328721'
```

Se il plugin è attivo, vedrai nei log:
```
[OpenLibrary] Fetching ISBN: 9780140328721
[OpenLibrary] Edition found: /books/OL7353617M
```

### Test via Browser

1. Vai su **Admin → Libri → Nuovo Libro**
2. Clicca su "Importa da ISBN"
3. Inserisci un ISBN (es. `9780140328721`)
4. Il plugin recupererà automaticamente i dati

## 🔧 Configurazione

### Google Books API (Opzionale)

Il plugin supporta Google Books come fallback:

1. Vai su **Admin → Plugin → Open Library → Impostazioni**
2. Inserisci la tua Google Books API Key
3. Salva le impostazioni

**Come ottenere la API Key:**
1. Vai su https://console.cloud.google.com/
2. Crea un nuovo progetto
3. Abilita "Books API"
4. Crea credenziali → API Key
5. Copia la chiave nel campo

### Priorità del Plugin

Il plugin ha **priorità 5** (alta) nelle fonti di scraping:
- Priorità più bassa = eseguito prima
- Open Library (5) → viene eseguito prima di altre fonti (10, 20, etc.)

## 📊 Monitoraggio

### Verifica Hook Registrati

Controlla che gli hook siano attivi:

```sql
SELECT hook_name, callback_method, priority, is_active
FROM plugin_hooks ph
JOIN plugins p ON ph.plugin_id = p.id
WHERE p.name = 'open-library';
```

Dovresti vedere:
```
scrape.sources           addOpenLibrarySource         5    1
scrape.fetch.custom      fetchFromOpenLibrary         5    1
scrape.data.modify       enrichWithOpenLibraryData   10    1
app.routes.register      registerRoutes              10    1
```

### Log di Sistema

Controlla i log per verificare il funzionamento:

```bash
# Log PHP
tail -f /var/log/php/error.log | grep -i "openlibrary"

# Log del plugin (nel database)
SELECT level, message, context, created_at
FROM plugin_logs
WHERE plugin_id = (SELECT id FROM plugins WHERE name = 'open-library')
ORDER BY created_at DESC
LIMIT 20;
```

## 🔄 Aggiornamento

Per aggiornare il plugin:

1. **Disattiva** il plugin dall'interfaccia
2. Sostituisci i file nella directory `storage/plugins/open-library/`
3. **Attiva** nuovamente il plugin

Il sistema aggiornerà automaticamente gli hook nel database.

## ❌ Disinstallazione

Per rimuovere completamente il plugin:

1. Vai su **Admin → Plugin**
2. Trova "Open Library Scraper"
3. Clicca **"Disattiva"** (se attivo)
4. Clicca **"Disinstalla"**

Questo rimuoverà:
- File del plugin
- Hook registrati
- Impostazioni
- Log del plugin

## 🐛 Troubleshooting

### Plugin non appare nella lista

**Causa**: Il file `plugin.json` potrebbe non essere valido

**Soluzione**:
```bash
# Verifica sintassi JSON
cat storage/plugins/open-library/plugin.json | python -m json.tool
```

### Route non funziona

**Causa**: Il plugin potrebbe non essere attivo

**Soluzione**:
```sql
-- Verifica che sia attivo
SELECT name, is_active FROM plugins WHERE name = 'open-library';

-- Attiva manualmente se necessario
UPDATE plugins SET is_active = 1 WHERE name = 'open-library';
```

### Nessun dato recuperato

**Causa**: cURL potrebbe essere disabilitato

**Soluzione**:
```bash
# Verifica cURL
php -m | grep curl

# Test connessione Open Library
curl -I https://openlibrary.org
```

### Errori di permessi

**Causa**: File non leggibili dal web server

**Soluzione**:
```bash
# Imposta permessi corretti
chmod 755 storage/plugins/open-library
chmod 644 storage/plugins/open-library/*.php
chmod 644 storage/plugins/open-library/*.json
```

## 📈 Copertura ISBN

### Per Lingua

- **Inglese**: 90%+ (ottima)
- **Italiano**: 60%+ (buona)
- **Altre lingue europee**: 50%+ (discreta)
- **Lingue orientali**: 30%+ (limitata)

### Per Tipo

- **Bestseller internazionali**: 95%+ (ottima)
- **Classici**: 90%+ (ottima)
- **Libri accademici**: 70%+ (buona)
- **Edizioni recenti**: 60%+ (discreta)
- **Pubblicazioni locali**: 30%+ (limitata)

## 🔗 API Endpoints

### Plugin Test Endpoint

```
GET /api/open-library/test?isbn={isbn}
```

**Parametri:**
- `isbn` (opzionale): ISBN da testare (default: 9788804666592)

**Risposta:**
```json
{
  "success": boolean,
  "plugin": "Open Library",
  "isbn": "string",
  "data": {...} | null,
  "message": "string"
}
```

### Integrated Scraping Endpoint

```
GET /api/scrape/isbn?isbn={isbn}
```

**Parametri:**
- `isbn` (obbligatorio): ISBN del libro

**Risposta:**
```json
{
  "title": "string",
  "author": "string",
  "publisher": "string",
  ...
  "source": "https://openlibrary.org/isbn/..."
}
```

## 📚 Esempi ISBN per Test

ISBN verificati su Open Library:

- `9780140328721` - Fantastic Mr. Fox (Roald Dahl)
- `9780451526538` - 1984 (George Orwell)
- `9780743273565` - The Great Gatsby (F. Scott Fitzgerald)
- `9788804666592` - La Divina Commedia (Dante Alighieri)
- `9788804671664` - Il nome della rosa (Umberto Eco)

## 🆘 Supporto

Per problemi o domande:

1. **Controlla i log**: Vai su Admin → Plugin → Open Library → Log
2. **Testa l'endpoint**: `curl http://localhost/api/open-library/test`
3. **Verifica l'API**: `curl https://openlibrary.org/isbn/9780140328721.json`
4. **Consulta la documentazione**: `storage/plugins/open-library/README.md`

## 📝 Note Tecniche

### Sistema di Hook

Il plugin usa il nuovo sistema di hook automatici:
- Gli hook vengono registrati nel database durante l'attivazione
- Le route vengono create automaticamente tramite `app.routes.register`
- Nessuna modifica manuale ai file core è necessaria

### Architettura

```
OpenLibraryPlugin
├── Hook Handlers
│   ├── addOpenLibrarySource()      # Registra fonte scraping
│   ├── fetchFromOpenLibrary()      # Logica fetch principale
│   ├── enrichWithOpenLibraryData() # Arricchimento dati
│   └── registerRoutes()            # Registrazione route
├── API Clients
│   ├── fetchFromOpenLibraryApi()   # Client Open Library
│   └── fetchFromGoogleBooks()      # Client Google Books fallback
└── Lifecycle Hooks
    ├── onInstall()                 # Setup iniziale
    ├── onActivate()                # Registrazione hook
    └── onDeactivate()              # Cleanup
```

---

**Versione Plugin**: 1.0.0
**Versione API Open Library**: v1
**Sistema di Hook**: v2 (automatic route registration)
**Ultima modifica**: 2025-01-16
