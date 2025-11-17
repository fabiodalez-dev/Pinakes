# API Book Scraper Plugin

Plugin per Pinakes che permette di recuperare automaticamente i dati dei libri da un servizio API personalizzato tramite ISBN/EAN.

## 📋 Descrizione

Questo plugin si collega a un servizio web esterno per recuperare automaticamente i dati bibliografici dei libri durante la creazione o modifica. Ha **priorità 3** (più alta di Open Library che ha priorità 5), quindi viene interrogato per primo.

## ✨ Caratteristiche

- ✅ **Alta Priorità**: Priorità 3, interrogato prima di Open Library
- ✅ **Sicurezza**: API key criptata con AES-256-GCM
- ✅ **Personalizzabile**: Endpoint API configurabile
- ✅ **Timeout Configurabile**: Da 5 a 60 secondi
- ✅ **Logging Completo**: Tracciamento completo delle richieste
- ✅ **Fallback Automatico**: Se fallisce, passa alle altre sorgenti
- ✅ **Supporto ISBN-10 e ISBN-13**

## 🚀 Installazione

### 1. Installazione Manuale

1. Copia la cartella `api-book-scraper` in `/storage/plugins/`
2. Vai su **Admin → Plugin** nell'interfaccia web
3. Trova "API Book Scraper" e clicca **Attiva**
4. Clicca **Configura** per impostare endpoint e API key

### 2. Configurazione

Dopo l'attivazione, configura i seguenti parametri:

| Parametro         | Descrizione                              | Obbligatorio |
|-------------------|------------------------------------------|--------------|
| **API Endpoint**  | URL del servizio web                     | ✅           |
| **API Key**       | Chiave di autenticazione                 | ✅           |
| **Timeout**       | Timeout richiesta (5-60 sec)             | ✅           |
| **Abilita Plugin**| Attiva/disattiva il plugin               | ✅           |

#### Esempio Configurazione

```
API Endpoint: https://api.example.com/books/{isbn}
API Key: sk_live_9f8e7d6c5b4a3210fedcba9876543210
Timeout: 10
```

## 📝 Formato API

### Richiesta

```http
GET /books/9788804668619 HTTP/1.1
Host: api.example.com
X-API-Key: sk_live_your_api_key
Accept: application/json
```

### Risposta

```json
{
  "success": true,
  "data": {
    "title": "Il Nome della Rosa",
    "subtitle": "Edizione illustrata",
    "authors": ["Umberto Eco"],
    "publisher": "Bompiani",
    "publish_date": "1980-10-28",
    "isbn13": "9788845292613",
    "ean": "9788845292613",
    "pages": 503,
    "language": "it",
    "description": "In un'abbazia benedettina...",
    "cover_url": "https://cdn.example.com/cover.jpg",
    "series": "Narratori della Fenice",
    "format": "Brossura",
    "price": "14.00"
  }
}
```

### Campi Supportati

Tutti i campi sono opzionali. Il plugin mapperà automaticamente i campi presenti:

- `title` → Titolo libro
- `subtitle` → Sottotitolo
- `authors` → Autori (array o stringa)
- `publisher` → Editore
- `publish_date` → Data pubblicazione (YYYY-MM-DD)
- `isbn13` / `isbn10` / `ean` → Codici identificativi
- `pages` → Numero pagine
- `language` → Lingua (it, en, fr...)
- `description` → Descrizione/trama
- `cover_url` → URL copertina
- `series` → Collana
- `format` → Formato (Brossura, Cartonato...)
- `price` → Prezzo
- `weight` → Peso (grammi)
- `dimensions` → Dimensioni
- `genres` → Generi letterari (array)
- `subjects` → Argomenti/temi (array)

## 🔧 Utilizzo

### Nel Form Libro

1. Vai su **Libri → Aggiungi Nuovo Libro**
2. Inserisci ISBN/EAN nel campo "Importa da ISBN"
3. Clicca **"Importa dati libro"**
4. Il sistema interrogherà prima la tua API personalizzata
5. Se non trova risultati, passerà a Open Library

### Hook Disponibili

Il plugin implementa i seguenti hooks:

```php
// Aggiunge sorgente API personalizzata (priorità 3)
Hooks::add('scrape.sources', [$plugin, 'addApiSource'], 3);

// Fetch dati da API (priorità 3)
Hooks::add('scrape.fetch.custom', [$plugin, 'fetchFromApi'], 3);

// Validazione ISBN (priorità 3)
Hooks::add('scrape.isbn.validate', [$plugin, 'validateIsbn'], 3);
```

## 📚 Implementazione Server

Per implementare un server compatibile con questo plugin, consulta la guida completa:

**📖 [SERVER_IMPLEMENTATION_GUIDE.md](SERVER_IMPLEMENTATION_GUIDE.md)**

La guida include:
- Specifiche API complete
- Esempi in PHP (Laravel), Node.js, Python, Go
- Schema database SQL
- Best practices di sicurezza
- Testing e deployment
- Integrazione con API esterne (Google Books, Open Library)

## 🔒 Sicurezza

- ✅ **API Key Criptata**: Salvata con AES-256-GCM nel database
- ✅ **HTTPS Obbligatorio**: Tutte le comunicazioni via TLS
- ✅ **Validazione Input**: ISBN sanitizzato prima dell'invio
- ✅ **Timeout Protezione**: Previene hanging requests
- ✅ **Error Handling**: Gestione sicura degli errori
- ✅ **Logging Audit**: Tracciamento completo richieste

## 🐛 Troubleshooting

### Il plugin non funziona

1. Verifica che il plugin sia **attivato**
2. Controlla che **API Endpoint** e **API Key** siano configurati
3. Verifica che l'opzione **"Abilita Plugin"** sia selezionata
4. Controlla i log del plugin in **Admin → Plugin → API Book Scraper → Log**

### Errore 401 Unauthorized

- L'API key non è valida
- Verifica la configurazione sul server API

### Errore 404 Not Found

- L'ISBN non è presente nel database del server API
- Il sistema passerà automaticamente a Open Library

### Timeout

- Aumenta il timeout nelle impostazioni (max 60 secondi)
- Verifica che il server API risponda in tempo

### Il plugin viene ignorato

- Controlla la priorità: deve essere < 5 per essere interrogato prima di Open Library
- Verifica nei log se il plugin è stato chiamato

## 📊 Log & Monitoring

### Visualizzazione Log

1. Vai su **Admin → Plugin**
2. Trova "API Book Scraper"
3. Clicca **Log** per vedere le richieste recenti

### Livelli Log

- **INFO**: Richieste successful
- **WARNING**: ISBN non trovato
- **ERROR**: Errori di connessione o parsing

### Esempio Log

```
[2025-01-15 10:30:00] INFO: Dati recuperati per ISBN: 9788804668619
[2025-01-15 10:31:15] ERROR: Errore scraping ISBN 1234567890: HTTP 404
```

## 🔄 Aggiornamenti

### Versione 1.0.0 (Attuale)

- ✅ Release iniziale
- ✅ Supporto ISBN-10/13
- ✅ Autenticazione API key
- ✅ Mapping campi completo
- ✅ Priorità alta (3)
- ✅ Logging completo

## 💡 Esempi Avanzati

### Mapping Campi Custom

Se la tua API usa nomi campi diversi, modifica il metodo `mapApiResponse()` in `ApiBookScraperPlugin.php`:

```php
private function mapApiResponse(array $apiData, string $isbn): ?array
{
    $data = $apiData['data'] ?? $apiData;

    return [
        'title' => $data['book_title'] ?? null,  // Custom field
        'authors' => $data['book_authors'] ?? [], // Custom field
        // ... altri campi
    ];
}
```

### Autori in Formato Oggetto

La API può restituire autori con dettagli:

```json
"authors": [
  {
    "name": "Umberto Eco",
    "role": "author",
    "bio": "Scrittore e filosofo..."
  }
]
```

Il plugin estrae automaticamente il campo `name`.

### Placeholder URL Personalizzato

L'API può usare diversi pattern URL:

```
1. https://api.example.com/books/{isbn}
   → GET /books/9788804668619

2. https://api.example.com/v1/search
   → GET /v1/search?isbn=9788804668619

3. https://api.example.com/lookup/{isbn}/details
   → GET /lookup/9788804668619/details
```

Il plugin supporta tutti questi pattern.

## 🤝 Contributi

Per contribuire al plugin:

1. Fork il repository
2. Crea un branch per la tua feature
3. Commit le modifiche
4. Apri una Pull Request

## 📄 Licenza

Questo plugin è rilasciato sotto licenza MIT.

## 📞 Supporto

- **Email**: support@pinakes.dev
- **Forum**: https://community.pinakes.dev
- **Documentazione**: https://docs.pinakes.dev

---

**Sviluppato con ❤️ per la comunità Pinakes**
