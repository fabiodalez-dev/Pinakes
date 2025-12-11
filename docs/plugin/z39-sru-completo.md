# 🌐 Plugin Z39.50/SRU - Manuale Completo

> Guida completa per usare il plugin Z39.50/SRU di Pinakes come server (esportare il catalogo) e come client (importare da altri server)

---

## 📋 Indice

### PARTE 1: Server SRU
1. [Cos'è il Server SRU](#cose-il-server-sru)
2. [Installazione e Attivazione](#installazione-e-attivazione)
3. [Configurazione Server](#configurazione-server)
4. [Operazioni SRU Disponibili](#operazioni-sru-disponibili)
5. [Formati di Output](#formati-di-output)
6. [Esempi Pratici Server](#esempi-pratici-server)

### PARTE 2: Client SBN
7. [Cos'è il Client SBN](#cose-il-client-sbn)
8. [Come Funziona lo Scraping SBN](#come-funziona-lo-scraping-sbn)
9. [Ricerca per ISBN](#ricerca-per-isbn-sbn)
10. [Ricerca per Titolo/Autore](#ricerca-per-titoloautore-sbn)
11. [Dati Recuperati da SBN](#dati-recuperati-da-sbn)

### PARTE 3: Client SRU Generico
12. [Cos'è il Client SRU](#cose-il-client-sru)
13. [Configurazione Server Esterni](#configurazione-server-esterni)
14. [Ricerca Libri](#ricerca-libri-client)
15. [Parsing Formati](#parsing-formati)

### PARTE 4: Sicurezza e Performance
16. [Rate Limiting](#rate-limiting)
17. [Logging e Monitoring](#logging-e-monitoring)
18. [Performance e Ottimizzazioni](#performance-e-ottimizzazioni)

---

# PARTE 1: SERVER SRU

## 🌐 Cos'è il Server SRU

### Definizione

**SRU (Search/Retrieve via URL)** è un protocollo standard internazionale per esporre cataloghi bibliografici via HTTP/XML. È il successore moderno di Z39.50.

### A Cosa Serve

Il plugin trasforma Pinakes in un **server bibliografico** che può:
- ✅ Esporre il tuo catalogo ad altre biblioteche
- ✅ Permettere ricerche esterne tramite API standard
- ✅ Integrarsi con reti bibliotecarie (SBN, Koha, VuFind, etc.)
- ✅ Fornire dati in formati standard (MARCXML, Dublin Core, MODS)

### Caso d'Uso Pratico

```
La Biblioteca Comunale di Milano usa Pinakes
↓
Attiva il plugin Z39.50/SRU Server
↓
Espone il catalogo all'URL: https://biblio-milano.it/api/sru
↓
La Biblioteca di Roma può cercare nei libri di Milano
↓
Utenti trovano libri in entrambe le biblioteche
↓
Sistema di prestito interbibliotecario
```

---

## ⚙️ Installazione e Attivazione

### Passo 1: Verifica Requisiti

- ✅ PHP 7.4+
- ✅ Pinakes v0.4.0+
- ✅ Accesso come Admin

### Passo 2: Attivazione Plugin

Il plugin **è già incluso** in Pinakes (bundled).

**Percorso**: Dashboard → **Plugin** → Trova "Z39.50/SRU Server" → **"Attiva"**

✅ **La route `/api/sru` viene registrata automaticamente!**

### Passo 3: Verifica Funzionamento

Apri nel browser:
```
https://tuo-dominio.it/api/sru?operation=explain
```

Dovresti vedere un XML con le capacità del server.

---

## 🔧 Configurazione Server

### Accesso Configurazione

**Percorso**: Dashboard → **Plugin** → **Z39.50/SRU Server** → **"Configura"**

### Impostazioni Disponibili

| Impostazione | Valore Default | Descrizione |
|--------------|----------------|-------------|
| **server_enabled** | `true` | Abilita/disabilita il server |
| **server_host** | `localhost` | Nome host del server |
| **server_port** | `80` | Porta del server |
| **server_database** | `catalog` | Nome database (identificativo SRU) |
| **max_records** | `100` | Massimo record per richiesta |
| **default_records** | `10` | Numero record di default |
| **supported_formats** | `marcxml,dc,mods,oai_dc` | Formati supportati |
| **default_format** | `marcxml` | Formato di default |
| **rate_limit_enabled** | `true` | Abilita rate limiting |
| **rate_limit_requests** | `100` | Richieste max per finestra |
| **rate_limit_window** | `3600` | Finestra temporale (secondi) |
| **enable_logging** | `true` | Abilita logging accessi |

### Configurazione Tipica

**Per biblioteca piccola (uso interno):**
```
server_enabled: true
max_records: 50
rate_limit_enabled: false
enable_logging: false
```

**Per biblioteca pubblica (esposto online):**
```
server_enabled: true
max_records: 100
rate_limit_enabled: true
rate_limit_requests: 100
rate_limit_window: 3600
enable_logging: true
```

---

## 📡 Operazioni SRU Disponibili

### 1. Explain

**Descrizione**: Ritorna informazioni sul server e le sue capacità.

**Richiesta**:
```
GET /api/sru?operation=explain
```

**Risposta**: XML con:
- Versione SRU supportata (1.2)
- Indici di ricerca disponibili
- Formati supportati (MARCXML, DC, MODS)
- Configurazione massimi record

**Uso**: Client esterni lo usano per scoprire le capacità del server.

---

### 2. SearchRetrieve

**Descrizione**: Cerca record nel catalogo usando query CQL.

**Richiesta**:
```
GET /api/sru?operation=searchRetrieve&query=dc.title=divina&maximumRecords=10
```

**Parametri**:
| Parametro | Obbligatorio | Descrizione | Default |
|-----------|--------------|-------------|---------|
| `query` | ✅ | Query CQL | - |
| `startRecord` | ❌ | Record iniziale (paginazione) | `1` |
| `maximumRecords` | ❌ | Numero record da ritornare | `10` |
| `recordSchema` | ❌ | Formato output | `marcxml` |
| `sortKeys` | ❌ | Ordinamento risultati | - |

**Formati recordSchema**:
- `marcxml`: MARC 21 XML
- `dc` o `oai_dc`: Dublin Core
- `mods`: MODS (Metadata Object Description Schema)

---

### 3. Scan

**Descrizione**: Scansiona un indice per ottenere termini disponibili.

**Richiesta**:
```
GET /api/sru?operation=scan&scanClause=dc.title&maximumTerms=20
```

**Parametri**:
| Parametro | Obbligatorio | Descrizione | Default |
|-----------|--------------|-------------|---------|
| `scanClause` | ✅ | Indice da scansionare | - |
| `maximumTerms` | ❌ | Numero termini da ritornare | `20` |

**Indici scansionabili**:
- `dc.title`: Titoli libri
- `dc.creator`: Nomi autori
- `dc.subject`: Soggetti/generi
- `bath.isbn`: ISBN registrati

**Uso**: Utile per autocomplete e browse alfabetico.

---

## 🎨 Formati di Output

### MARCXML (Machine-Readable Cataloging XML)

**Standard internazionale per la catalogazione bibliografica.**

**Campi principali**:
```xml
<record xmlns="http://www.loc.gov/MARC21/slim">
  <leader>00000nam a2200000 a 4500</leader>

  <!-- Titolo (245) -->
  <datafield tag="245" ind1="1" ind2="0">
    <subfield code="a">La Divina Commedia</subfield>
    <subfield code="b">Inferno, Purgatorio, Paradiso</subfield>
  </datafield>

  <!-- Autore principale (100) -->
  <datafield tag="100" ind1="1" ind2=" ">
    <subfield code="a">Alighieri, Dante</subfield>
  </datafield>

  <!-- Editore (260) -->
  <datafield tag="260" ind1=" " ind2=" ">
    <subfield code="b">Mondadori</subfield>
    <subfield code="c">2020</subfield>
  </datafield>

  <!-- ISBN (020) -->
  <datafield tag="020" ind1=" " ind2=" ">
    <subfield code="a">9788804666592</subfield>
  </datafield>

  <!-- Holdings - Copia #1 (852) -->
  <datafield tag="852" ind1=" " ind2=" ">
    <subfield code="b">Scaffale A</subfield>
    <subfield code="c">Mensola 3</subfield>
    <subfield code="j">INV-2024-001</subfield>
    <subfield code="z">Status: disponibile</subfield>
  </datafield>

  <!-- Holdings - Copia #2 (852) -->
  <datafield tag="852" ind1=" " ind2=" ">
    <subfield code="b">Scaffale A</subfield>
    <subfield code="c">Mensola 3</subfield>
    <subfield code="j">INV-2024-002</subfield>
    <subfield code="z">Status: prestato</subfield>
  </datafield>

  <!-- Summary Holdings (866) -->
  <datafield tag="866" ind1=" " ind2=" ">
    <subfield code="a">Total copies: 2, Available: 1</subfield>
  </datafield>

  <!-- Dewey Classification (082) -->
  <datafield tag="082" ind1="0" ind2="4">
    <subfield code="a">851.1</subfield>
  </datafield>
</record>
```

**Campi Holdings (v1.1.0+)**:
- **Campo 852**: Informazioni singola copia (scaffale, inventario, stato)
- **Campo 866**: Riepilogo copie totali/disponibili

---

### Dublin Core (Metadata Semplice)

**Standard universale per metadati.**

```xml
<oai_dc:dc xmlns:dc="http://purl.org/dc/elements/1.1/">
  <dc:title>La Divina Commedia</dc:title>
  <dc:creator>Dante Alighieri</dc:creator>
  <dc:publisher>Mondadori</dc:publisher>
  <dc:date>2020</dc:date>
  <dc:identifier>ISBN:9788804666592</dc:identifier>
  <dc:description>Opera letteraria in versi...</dc:description>
  <dc:language>Italiano</dc:language>

  <!-- Disponibilità copie -->
  <dc:rights>Available for loan (1 of 2 copies available)</dc:rights>

  <!-- Inventario copie -->
  <dc:identifier>Copy:INV-2024-001 [disponibile]</dc:identifier>
  <dc:identifier>Copy:INV-2024-002 [prestato]</dc:identifier>

  <!-- Collocazione fisica -->
  <dc:coverage>Shelf: Scaffale A, Level: 3</dc:coverage>
</oai_dc:dc>
```

---

### MODS (Standard Ricco)

**Metadata Object Description Schema.**

```xml
<mods xmlns="http://www.loc.gov/mods/v3">
  <titleInfo>
    <title>La Divina Commedia</title>
  </titleInfo>
  <name type="personal">
    <namePart>Dante Alighieri</namePart>
    <role>
      <roleTerm>author</roleTerm>
    </role>
  </name>
  <originInfo>
    <publisher>Mondadori</publisher>
    <dateIssued>2020</dateIssued>
  </originInfo>
  <identifier type="isbn">9788804666592</identifier>

  <!-- Location copia #1 -->
  <location>
    <physicalLocation>Shelf: Scaffale A, Level: 3</physicalLocation>
    <shelfLocator>INV-2024-001</shelfLocator>
    <holdingSimple>
      <copyInformation>
        <note>Status: disponibile</note>
      </copyInformation>
    </holdingSimple>
  </location>

  <!-- Summary holdings -->
  <note type="holdings">Total copies: 2, Available: 1</note>
</mods>
```

---

## 💻 Esempi Pratici Server

### Esempio 1: Cercare per Titolo

```bash
curl "https://biblio.it/api/sru?operation=searchRetrieve&query=dc.title=divina&maximumRecords=5"
```

**Risultato**: Massimo 5 libri con "divina" nel titolo, formato MARCXML.

---

### Esempio 2: Cercare per Autore (Dublin Core)

```bash
curl "https://biblio.it/api/sru?operation=searchRetrieve&query=dc.creator=dante&recordSchema=dc"
```

**Risultato**: Libri di autore "Dante", formato Dublin Core.

---

### Esempio 3: Cercare per ISBN

```bash
curl "https://biblio.it/api/sru?operation=searchRetrieve&query=bath.isbn=9788804666592"
```

**Risultato**: Libro con ISBN specificato.

---

### Esempio 4: Cercare Solo Libri Disponibili

```bash
curl "https://biblio.it/api/sru?operation=searchRetrieve&query=dc.title=fantasy%20AND%20library.available=true"
```

**Risultato**: Libri fantasy con almeno una copia disponibile.

**Indice `library.available`** (v1.1.0+):
- `library.available=true`: Solo libri disponibili
- `library.available=false`: Solo libri non disponibili
- `library.available=prestato`: Solo copie in prestito
- `library.available=disponibile`: Solo copie libere

---

### Esempio 5: Cercare per Scaffale

```bash
curl "https://biblio.it/api/sru?operation=searchRetrieve&query=library.shelf=A"
```

**Risultato**: Tutti i libri sullo scaffale A.

---

### Esempio 6: Ordinamento Risultati

```bash
curl "https://biblio.it/api/sru?operation=searchRetrieve&query=dc.creator=dante&sortKeys=dc.title,,1"
```

**Parametro sortKeys**: `index,,direction`
- Direction: `1` = ASC, `0` = DESC

**Esempi sortKeys**:
- `dc.title,,1`: Titolo A→Z
- `dc.title,,0`: Titolo Z→A
- `dc.date,,0`: Anno più recente prima
- `bath.isbn,,1`: ISBN crescente

---

### Esempio 7: Paginazione

```bash
# Prima pagina (record 1-10)
curl "https://biblio.it/api/sru?operation=searchRetrieve&query=cql.anywhere=fantasy&maximumRecords=10&startRecord=1"

# Seconda pagina (record 11-20)
curl "https://biblio.it/api/sru?operation=searchRetrieve&query=cql.anywhere=fantasy&maximumRecords=10&startRecord=11"
```

---

### Esempio 8: Query Complesse

```bash
curl "https://biblio.it/api/sru?operation=searchRetrieve&query=(dc.title=divina%20OR%20dc.title=commedia)%20AND%20library.available=true"
```

**Operatori CQL supportati**:
- `AND`: Entrambe le condizioni
- `OR`: Almeno una condizione
- `NOT`: Negazione
- Parentesi `()` per precedenza

---

# PARTE 2: CLIENT SBN

## 🇮🇹 Cos'è il Client SBN

### Definizione

**SBN (Servizio Bibliotecario Nazionale)** è il catalogo nazionale italiano che aggrega milioni di record bibliografici dalle biblioteche italiane.

Il **Client SBN** di Pinakes permette di:
- ✅ Cercare libri nel catalogo SBN
- ✅ Recuperare metadati bibliografici completi
- ✅ Importare automaticamente durante scraping ISBN
- ✅ Arricchire dati esistenti (Dewey, pagine, collana)

### URL Base

```
https://opac.sbn.it/opacmobilegw
```

**API Usata**: JSON Mobile Gateway (non ufficialmente documentata ma stabile)

---

## 🔍 Come Funziona lo Scraping SBN

### Flusso Automatico

Quando inserisci un libro tramite ISBN, Pinakes:

```
1. Ricevi ISBN dall'utente
   ↓
2. Chiama ScrapeController::byIsbn()
   ↓
3. Hook: scrape.fetch.custom
   ↓
4. Plugin Z39 usa SbnClient::searchByIsbn()
   ↓
5. Request a opac.sbn.it/opacmobilegw/search.json?isbn=XXX
   ↓
6. Parse JSON response
   ↓
7. Se trovato: fetch full record
   Request a opac.sbn.it/opacmobilegw/full.json?bid=IT\ICCU\...
   ↓
8. Parse dati completi
   ↓
9. Normalizza testo (rimuove caratteri MARC-8 di controllo)
   ↓
10. Return dati puliti a form libro
```

### Timeout e Retry

- **Connect timeout**: 5 secondi
- **Request timeout**: 15 secondi
- **Max retry**: Nessuno (singolo tentativo per performance)

---

## 📖 Ricerca per ISBN (SBN)

### Codice

```php
$sbnClient = new SbnClient(timeout: 15, enabled: true);
$book = $sbnClient->searchByIsbn('9788804666592');
```

### Request HTTP

```
GET https://opac.sbn.it/opacmobilegw/search.json?isbn=9788804666592&rows=1
```

### Response JSON (Brief Record)

```json
{
  "numFound": 1,
  "briefRecords": [
    {
      "codiceIdentificativo": "IT\\ICCU\\RMB\\0769708",
      "titolo": "La Divina Commedia / Dante Alighieri",
      "autorePrincipale": "Alighieri, Dante <1265-1321>",
      "pubblicazione": "Milano : Mondadori, 2020",
      "isbn": "978-88-04-66659-2",
      "copertina": "https://covers.openlibrary.org/..."
    }
  ]
}
```

### Fetch Full Record

```
GET https://opac.sbn.it/opacmobilegw/full.json?bid=IT\ICCU\RMB\0769708
```

### Response JSON (Full Record)

```json
{
  "codiceIdentificativo": "IT\\ICCU\\RMB\\0769708",
  "titolo": "La Divina Commedia / Dante Alighieri",
  "autorePrincipale": "Alighieri, Dante <1265-1321>",
  "nomi": [
    "[Autore] Alighieri, Dante <1265-1321>"
  ],
  "pubblicazione": "Milano : Mondadori, 2020",
  "descrizioneFisica": "XXIII, 612 p. ; 21 cm.",
  "collezione": "Oscar classici ; 234",
  "numeri": [
    "[ISBN] 978-88-04-66659-2"
  ],
  "classificazioneDewey": "851.1 (22.) POESIA ITALIANA. ORIGINI-1375",
  "linguaPubblicazione": "italiano"
}
```

---

## 📝 Ricerca per Titolo/Autore (SBN)

### Ricerca per Titolo

```php
$sbnClient = new SbnClient();
$books = $sbnClient->searchByTitle('Divina Commedia', maxResults: 10);
```

**Request**:
```
GET https://opac.sbn.it/opacmobilegw/search.json?any=Divina+Commedia&rows=10
```

**Nota**: SBN usa il campo `any` per ricerche generiche, non `titolo` (che restituisce errore di validazione).

---

### Ricerca per Autore

```php
$books = $sbnClient->searchByAuthor('Dante Alighieri', maxResults: 10);
```

**Request**:
```
GET https://opac.sbn.it/opacmobilegw/search.json?autore=Dante+Alighieri&rows=10
```

---

## 📦 Dati Recuperati da SBN

### Campi Sempre Presenti

| Campo | Descrizione | Esempio |
|-------|-------------|---------|
| `title` | Titolo pulito (senza autore) | "La Divina Commedia" |
| `authors` | Array autori normalizzati | ["Dante Alighieri"] |
| `source` | Fonte dati | "sbn" |
| `_sbn_bid` | BID univoco SBN | "IT\\ICCU\\RMB\\0769708" |

### Campi Opzionali

| Campo | Descrizione | Disponibilità |
|-------|-------------|---------------|
| `isbn13` | ISBN-13 | Se presente |
| `isbn10` | ISBN-10 | Se presente |
| `publisher` | Casa editrice | Sempre (da "pubblicazione") |
| `year` | Anno pubblicazione | Sempre (estratto da "pubblicazione") |
| `place` | Luogo pubblicazione | Se presente |
| `pages` | Numero pagine | Se in "descrizioneFisica" |
| `series` / `collana` | Nome collana | Se presente |
| `language` / `lingua` | Lingua ISO code | Se presente |
| `classificazione_dewey` | Codice Dewey | Se presente |
| `_dewey_name_sbn` | Nome classe Dewey | Se presente |
| `numero_inventario` | Prefillato con BID | `SBN-IT\ICCU\RMB\0769708` |

### Pulizia Dati

**Problema**: I dati SBN contengono caratteri di controllo MARC-8:
- `\x88`, `\x98`: NSB (Non-Sorting Begin)
- `\x89`, `\x9C`: NSE (Non-Sorting End)

**Soluzione**: Il client pulisce automaticamente:
```php
// Rimuove caratteri MARC-8
$text = preg_replace('/[\x{0080}-\x{009F}]/u', '', $text);

// Normalizza whitespace
$text = trim(preg_replace('/\s+/u', ' ', $text));
```

---

## 🎯 Normalizzazione Autori

**Problema**: SBN ritorna autori in formato "Cognome, Nome <date>":
```
"Alighieri, Dante <1265-1321>"
```

**Soluzione**: Normalizzazione automatica in "Nome Cognome":
```php
// Input: "Alighieri, Dante <1265-1321>"
// Output: "Dante Alighieri"

1. Rimuove date: "Alighieri, Dante"
2. Split su virgola: ["Alighieri", "Dante"]
3. Inverte: "Dante Alighieri"
```

**Vantaggio**: Coerenza con altri scraper (Google Books, Open Library usano "Nome Cognome").

---

## 📚 Estrazione Dewey

**Formato SBN**:
```
"classificazioneDewey": "851.1 (22.) POESIA ITALIANA. ORIGINI-1375"
```

**Parsing**:
```php
// Regex: (\d{3}(?:\.\d+)?)\s*(?:\([^)]+\)\s*)?(.+)?
// Gruppo 1: Codice (851.1)
// Gruppo 2: Nome (POESIA ITALIANA. ORIGINI-1375)

$deweyData = [
    'code' => '851.1',
    'name' => 'Poesia Italiana. Origini-1375'  // Normalizzato Title Case
];
```

**Campi popolati**:
- `classificazione_dewey`: `"851.1"`
- `_dewey_name_sbn`: `"Poesia Italiana. Origini-1375"` (usato per auto-populate JSON Dewey)

---

# PARTE 3: CLIENT SRU GENERICO

## 🌍 Cos'è il Client SRU

Il **Client SRU Generico** permette di connettersi a **qualsiasi server SRU esterno** (non solo SBN) per importare dati bibliografici.

### Server Supportati

- **Library of Congress** (USA)
- **British Library** (UK)
- **Deutsche Nationalbibliothek** (Germania)
- **Bibliothèque nationale de France** (Francia)
- **Altre biblioteche che espongono SRU**

---

## ⚙️ Configurazione Server Esterni

### Struttura Configurazione

```php
$servers = [
    [
        'name' => 'Library of Congress',
        'url' => 'https://lx2.loc.gov:210/LCDB',
        'enabled' => true,
        'version' => '1.1',
        'syntax' => 'marcxml',  // o 'dc', 'mods'
        'indexes' => [
            'isbn' => 'bath.isbn',
            'title' => 'dc.title',
            'author' => 'dc.creator'
        ]
    ],
    [
        'name' => 'British Library',
        'url' => 'http://bl.uk/sru',
        'enabled' => true,
        'version' => '1.2',
        'syntax' => 'dc'
    ]
];

$client = new SruClient($servers);
```

---

## 🔍 Ricerca Libri (Client)

### Ricerca per ISBN

```php
$client = new SruClient($servers);
$book = $client->searchByIsbn('9788804666592');
```

**Flusso**:
1. Prova primo server (Library of Congress)
2. Se non trova → prova secondo server (British Library)
3. Se non trova → prova terzo server...
4. Ritorna primo risultato trovato

**Request automatica**:
```
GET https://lx2.loc.gov:210/LCDB?operation=searchRetrieve&version=1.1&query=bath.isbn=9788804666592&recordSchema=marcxml&maximumRecords=1
```

---

## 📄 Parsing Formati

### Parsing MARCXML

Il client estrae automaticamente:

| Campo MARC | Tag | Subfield | Campo Pinakes |
|------------|-----|----------|---------------|
| **Titolo** | 245 | $a | `title` |
| **Sottotitolo** | 245 | $b | `subtitle` |
| **Autore** | 100 | $a | `authors[0]` |
| **Autori secondari** | 700 | $a | `authors[1..]` |
| **Editore** | 260/264 | $b | `publisher` |
| **Anno** | 260/264 | $c | `year`, `pubDate` |
| **ISBN** | 020 | $a | `isbn13`, `isbn10` |
| **Pagine** | 300 | $a | `pages` |
| **Lingua** | 041 | $a | `language` |
| **Descrizione** | 520 | $a | `description` |
| **Dewey** | 082 | $a | `classificazione_dewey` |

---

### Parsing Dublin Core

| Elemento DC | Campo Pinakes |
|-------------|---------------|
| `dc:title` | `title` |
| `dc:creator` | `authors[]` |
| `dc:publisher` | `publisher` |
| `dc:date` | `year`, `pubDate` |
| `dc:identifier` (ISBN) | `isbn13`, `isbn10` |
| `dc:description` | `description` |
| `dc:language` | `language` |
| `dc:subject` | `keywords` |

---

## 🔄 Retry e Timeout

### Configurazione

```php
$client = new SruClient($servers);
$client->setOptions([
    'timeout' => 10,        // Timeout richiesta (secondi)
    'max_retries' => 2,     // Numero retry per server
    'verify_ssl' => true    // Verifica certificati SSL
]);
```

### Logica Retry

```
Tentativo 1: Immediate
   ↓ [Fail]
Attesa 100ms
   ↓
Tentativo 2: After 100ms
   ↓ [Fail]
Attesa 200ms
   ↓
Tentativo 3: After 200ms (finale)
```

**Exponential backoff**: `100ms * 2^(attempt-1)`

**Non ritenta su errori 4xx** (es: 404, 400) perché sono errori client.

---

# PARTE 4: SICUREZZA E PERFORMANCE

## 🛡️ Rate Limiting

### Scopo

Previene:
- ❌ Ban IP da API esterne (Google Books, SBN)
- ❌ Attacchi DoS al server SRU
- ❌ Abuso risorse server

### Implementazione Server SRU

**Configurazione**:
```php
rate_limit_enabled: true
rate_limit_requests: 100    // Max 100 richieste
rate_limit_window: 3600     // In 1 ora (3600 secondi)
```

**Storage**: `/storage/rate_limits/[ip_address].json`

**File formato**:
```json
{
  "calls": [1702889123, 1702889456, ...],  // Timestamp chiamate
  "last_cleanup": 1702889600
}
```

**Logica**:
```php
1. Carica file rate limit per IP richiedente
2. Rimuovi chiamate più vecchie di 60 secondi
3. Conta chiamate rimanenti
4. Se count >= max_requests:
     → Ritorna errore 429 "Rate limit exceeded"
5. Altrimenti:
     → Registra chiamata corrente
     → Processa richiesta
```

### Rate Limiting Client (SBN)

**Integrato nel ScrapeController** per evitare ban:
```php
// Configurazione
private function checkRateLimit(string $apiName, int $maxCallsPerMinute = 10): bool

// Chiamata
if (!$this->checkRateLimit('google_books', 10)) {
    // Skip Google Books, troppo chiamate
}
```

---

## 📊 Logging e Monitoring

### Tabella z39_access_logs

**Campi tracciati**:
```sql
CREATE TABLE z39_access_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip_address VARCHAR(45),
  operation VARCHAR(50),       -- explain, searchRetrieve, scan
  query TEXT,                  -- Query CQL eseguita
  record_schema VARCHAR(50),   -- marcxml, dc, mods
  records_returned INT,        -- Numero record ritornati
  response_time_ms INT,        -- Tempo risposta (millisecondi)
  error_message TEXT,          -- Errore se presente
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Query Monitoring Utili

**Richieste ultime 24h**:
```sql
SELECT COUNT(*) as total_requests
FROM z39_access_logs
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR);
```

**Operazioni più usate**:
```sql
SELECT operation, COUNT(*) as count
FROM z39_access_logs
GROUP BY operation
ORDER BY count DESC;
```

**Performance media**:
```sql
SELECT AVG(response_time_ms) as avg_ms
FROM z39_access_logs
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR);
```

**Top IP richiedenti**:
```sql
SELECT ip_address, COUNT(*) as requests
FROM z39_access_logs
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY ip_address
ORDER BY requests DESC
LIMIT 10;
```

---

## ⚡ Performance e Ottimizzazioni

### Indici Database Raccomandati

Per performance ottimali con cataloghi grandi (>10.000 libri):

```sql
-- Ricerche testuali
CREATE INDEX idx_libri_titolo ON libri(titolo);
CREATE INDEX idx_libri_isbn10 ON libri(isbn10);
CREATE INDEX idx_libri_isbn13 ON libri(isbn13);
CREATE INDEX idx_autori_nome ON autori(nome);

-- Disponibilità copie
CREATE INDEX idx_copie_libro_id ON copie(libro_id);
CREATE INDEX idx_copie_stato ON copie(stato);
CREATE INDEX idx_copie_libro_stato ON copie(libro_id, stato);

-- Collocazione
CREATE INDEX idx_scaffali_codice ON scaffali(codice);
CREATE INDEX idx_libri_collocazione ON libri(collocazione);
```

### Ottimizzazioni Query

**Ricerche disponibilità** (v1.1.0+):
```sql
-- Usa subquery EXISTS (più veloce di COUNT)
SELECT l.* FROM libri l
WHERE EXISTS (
  SELECT 1 FROM copie c
  WHERE c.libro_id = l.id
  AND c.stato = 'disponibile'
  LIMIT 1
);
```

**Ordinamento autori**:
```sql
-- Usa GROUP_CONCAT aggregata
SELECT l.*, GROUP_CONCAT(a.nome ORDER BY la.ruolo='principale' DESC) AS autore
FROM libri l
LEFT JOIN libri_autori la ON l.id = la.libro_id
LEFT JOIN autori a ON la.autore_id = a.id
GROUP BY l.id;
```

### Limiti Performance

| Operazione | Limite | Note |
|------------|--------|------|
| **Max record per richiesta** | 100 | Configurabile |
| **Offset molto alti** | Degrada | OFFSET 10000+ è lento |
| **Ricerche cql.anywhere** | Lente | Scansiona molti campi |
| **Query con molti OR** | Lente | Preferire AND quando possibile |

---

## 🎓 Best Practices

### ✅ DO

1. **Abilita rate limiting** in produzione
2. **Abilita logging** per troubleshooting
3. **Usa MARCXML** per massima compatibilità
4. **Testa con `operation=explain`** prima di query complesse
5. **Usa indici database** per cataloghi >1000 libri
6. **Monitora performance** con query z39_access_logs

### ❌ DON'T

1. **Non esporre senza rate limiting** → rischio DoS
2. **Non usare offset >1000** → lentissimo
3. **Non fare query `cql.anywhere` troppo spesso** → scansione completa
4. **Non disabilitare SSL verification** in produzione
5. **Non ignorare errori 429** → rischio ban IP

---

## 🔗 Riferimenti

### Standard Internazionali
- [SRU 1.2 Specification](https://www.loc.gov/standards/sru/)
- [CQL Specification](https://www.loc.gov/standards/sru/cql/)
- [MARC 21](https://www.loc.gov/marc/)
- [Dublin Core](https://www.dublincore.org/)
- [MODS](https://www.loc.gov/standards/mods/)

### Pinakes
- [Scraping Libri →](../libri/scraping.md)
- [Gestione Libri →](../libri/README.md)
- [Developer: Plugin System →](../developer/plugin-system.md)

---

**Ultima modifica**: Dicembre 2025
**Versione Plugin**: v1.1.0
**Versione Pinakes**: v0.4.1
