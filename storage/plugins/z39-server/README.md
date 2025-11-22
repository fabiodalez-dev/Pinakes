# Z39.50/SRU Server Plugin

Plugin per Pinakes che implementa un server SRU (Search/Retrieve via URL) completo per esporre il catalogo bibliografico tramite protocollo standard.

## Descrizione

Questo plugin permette di esporre il catalogo della tua biblioteca tramite il protocollo **SRU (Search/Retrieve via URL)**, il successore moderno e basato su HTTP del protocollo Z39.50. Questo consente l'integrazione con altre biblioteche, sistemi ILS (Integrated Library Systems) e reti bibliotecarie che supportano questi standard internazionali.

> ℹ️ **Nota**: il plugin parla esclusivamente SRU 1.2 (HTTP + XML). I client Z39.50 “puri” dovranno usare un gateway SRU↔Z39.50 per collegarsi.

## Caratteristiche

- **Server SRU completo** - Implementazione completa del protocollo SRU 1.2
- **Formati multipli** - Supporto per MARCXML, Dublin Core, MODS e OAI Dublin Core
- **Operazioni standard** - explain, searchRetrieve, scan
- **Ricerca CQL** - Parser con supporto per parentesi, AND/OR/NOT, operatori `all`/`any`/`exact` e relazioni numeriche
- **Informazioni Holdings** - Recupero automatico di tutte le copie con disponibilità, numero inventario, stato e localizzazione
- **Ricerca Avanzata** - Filtri per disponibilità copie, localizzazione fisica e numero inventario
- **Ordinamento** - Supporto sortKeys per ordinare risultati per titolo, autore, data o ISBN
- **Sicurezza OWASP** - Rate limiting, input validation, SQL injection prevention
- **Logging completo** - Tracciamento di tutte le richieste e performance
- **Paginazione** - Gestione efficiente di grandi risultati
- **CORS abilitato** - Permette l'accesso da client esterni
- **Configurabile** - Pannello di amministrazione per tutte le impostazioni

## Protocolli Supportati

### SRU (Search/Retrieve via URL)
SRU è il protocollo standard per la ricerca e il recupero di record bibliografici tramite HTTP. È il successore moderno di Z39.50 ed è ampiamente supportato da:
- Sistemi bibliotecari (ILS)
- Cataloghi collettivi
- Reti di biblioteche
- Motori di ricerca bibliografici

### Formati di Output

#### MARCXML
Formato XML per MARC 21, lo standard internazionale per la catalogazione bibliografica.

Include informazioni complete sulle copie nei campi MARC:
- **Campo 852** - Holdings location per ogni copia (scaffale, mensola, numero inventario, stato)
- **Campo 866** - Summary holdings con conteggio totale/disponibili

```xml
<record xmlns="http://www.loc.gov/MARC21/slim">
  <leader>00000nam a2200000 a 4500</leader>
  <datafield tag="245" ind1="1" ind2="0">
    <subfield code="a">Titolo del libro</subfield>
  </datafield>
  <!-- Holdings information for each copy -->
  <datafield tag="852" ind1=" " ind2=" ">
    <subfield code="b">Scaffale A</subfield>
    <subfield code="c">Shelf 3</subfield>
    <subfield code="j">INV-12345</subfield>
    <subfield code="z">Status: Available</subfield>
  </datafield>
  <!-- Summary holdings -->
  <datafield tag="866" ind1=" " ind2=" ">
    <subfield code="a">Total copies: 3, Available: 2</subfield>
  </datafield>
</record>
```

#### Dublin Core
Metadata standard semplice e universale.

Include informazioni disponibilità e copie:
- **dc:rights** - Disponibilità con conteggio copie
- **dc:identifier** - Numero inventario per ogni copia con stato
- **dc:coverage** - Informazioni localizzazione fisica

```xml
<oai_dc:dc xmlns:dc="http://purl.org/dc/elements/1.1/">
  <dc:title>Titolo del libro</dc:title>
  <dc:creator>Autore</dc:creator>
  <dc:rights>Available for loan (2 of 3 copies available)</dc:rights>
  <dc:identifier>Copy:INV-12345 [disponibile]</dc:identifier>
  <dc:identifier>Copy:INV-12346 [prestato]</dc:identifier>
  <dc:coverage>Shelf: Scaffale A, Level: 3</dc:coverage>
</oai_dc:dc>
```

#### MODS
Metadata Object Description Schema, standard ricco per descrizioni bibliografiche.

Include elementi holdings standard MODS:
- **location** - Informazioni localizzazione per ogni copia
- **physicalLocation** - Scaffale e mensola
- **shelfLocator** - Numero inventario
- **holdingSimple** - Stato e note copie

```xml
<mods xmlns="http://www.loc.gov/mods/v3">
  <titleInfo>
    <title>Titolo del libro</title>
  </titleInfo>
  <location>
    <physicalLocation>Shelf: Scaffale A, Level: 3</physicalLocation>
    <shelfLocator>INV-12345</shelfLocator>
    <holdingSimple>
      <copyInformation>
        <note>Status: Available</note>
      </copyInformation>
    </holdingSimple>
  </location>
  <note type="holdings">Total copies: 3, Available: 2</note>
</mods>
```

## Operazioni SRU

### 1. Explain
Ritorna informazioni sul server e le sue capacità.

**Richiesta:**
```
GET /api/sru?operation=explain
```

**Risposta:** Documento XML con:
- Informazioni sul server
- Indici di ricerca disponibili
- Formati supportati
- Configurazione massimi record

### 2. SearchRetrieve
Cerca record nel catalogo usando query CQL.

**Richiesta:**
```
GET /api/sru?operation=searchRetrieve&query=dc.title=shakespeare&maximumRecords=10&recordSchema=marcxml&sortKeys=dc.title,,1
```

**Parametri:**
- `query` - Query CQL (obbligatorio)
- `startRecord` - Record iniziale (default: 1)
- `maximumRecords` - Massimo numero di record (default: 10, max: 100)
- `recordSchema` - Formato output (marcxml, dc, mods, oai_dc)
- `sortKeys` - Chiave di ordinamento (formato: index,,direction dove 1=ASC, 0=DESC)

**Query CQL Supportate:**

Indici standard:
- `dc.title` - Ricerca per titolo
- `dc.creator` - Ricerca per autore
- `dc.subject` - Ricerca per soggetto
- `dc.publisher` - Ricerca per editore
- `dc.date` - Ricerca per anno (supporta operatori >, <, >=, <=)
- `dc.identifier` - Ricerca negli identificatori (ISBN, EAN)
- `bath.isbn` - Ricerca per ISBN (ISBN-10 o ISBN-13)
- `cql.anywhere` - Ricerca in tutti i campi

Indici specifici biblioteca (nuovi):
- `library.available` - Filtra per disponibilità copie (true/false o stato specifico)
- `library.location` - Ricerca per localizzazione (collocazione, scaffale)
- `library.shelf` - Ricerca per scaffale specifico
- `library.inventory` - Ricerca per numero inventario copia

**Chiavi di ordinamento (sortKeys):**
- `dc.title,,1` - Ordina per titolo ascendente
- `dc.title,,0` - Ordina per titolo discendente
- `dc.creator,,1` - Ordina per autore
- `dc.date,,0` - Ordina per data (più recenti prima)
- `bath.isbn,,1` - Ordina per ISBN

**Esempi di query:**
```
dc.title=shakespeare
dc.creator=dante
bath.isbn=9788804666592
dc.identifier=9788804666592
cql.anywhere=fantasy
dc.title=harry AND dc.creator=rowling
dc.date>=2020
library.available=true
library.available=disponibile
library.shelf=A
library.inventory=INV-12345
(dc.title=divina OR dc.title=commedia) AND library.available=true
```

### 3. Scan
Scansiona un indice per ottenere termini disponibili.

**Richiesta:**
```
GET /api/sru?operation=scan&scanClause=dc.title&maximumTerms=20
```

**Indici contenenti termini sfogliabili:**
- `dc.title` / `cql.anywhere` – restituisce titoli di opere
- `dc.creator` – restituisce i nomi presenti nell’anagrafica autori
- `dc.subject` – restituisce i soggetti (generi) configurati
- `bath.isbn` – restituisce gli ISBN registrati

Il server esegue una ricerca “prefix” (es. `dc.creator=ros` → tutti i cognomi che iniziano con “Ros...”) e ritorna `<term>` con `value`, `numberOfRecords` e `position`, come previsto dalle specifiche SRU.

## Installazione

1. **Carica il plugin:**
   - Crea un file ZIP con tutti i file del plugin
   - Vai su Admin → Plugin
   - Clicca "Carica Plugin"
   - Seleziona il file ZIP
   - Clicca "Installa"

2. **Attiva il plugin:**
   - Trova "Z39.50/SRU Server" nella lista plugin
   - Clicca "Attiva"
   - **La route `/api/sru` viene registrata automaticamente!**

3. **Configura le impostazioni:**
   - Vai su Admin → Z39.50/SRU Server
   - Configura le opzioni secondo le tue necessità
   - Salva le impostazioni

## Configurazione

### Impostazioni Disponibili

| Impostazione | Descrizione | Default |
|--------------|-------------|---------|
| `server_enabled` | Abilita/disabilita il server | true |
| `server_host` | Nome host del server | localhost |
| `server_port` | Porta del server | 80 |
| `server_database` | Nome database (identificativo) | catalog |
| `max_records` | Massimo record per richiesta | 100 |
| `default_records` | Record di default | 10 |
| `supported_formats` | Formati supportati (separati da virgola) | marcxml,dc,mods,oai_dc |
| `default_format` | Formato di default | marcxml |
| `rate_limit_enabled` | Abilita rate limiting | true |
| `rate_limit_requests` | Richieste massime per finestra | 100 |
| `rate_limit_window` | Finestra temporale (secondi) | 3600 |
| `enable_logging` | Abilita logging accessi | true |

## Utilizzo

### Endpoint Principale
```
GET https://tuo-dominio.it/api/sru
```

### Esempi di Utilizzo

#### 1. Ottenere informazioni sul server
```bash
curl "https://tuo-dominio.it/api/sru?operation=explain"
```

#### 2. Cercare libri per titolo
```bash
curl "https://tuo-dominio.it/api/sru?operation=searchRetrieve&query=dc.title=divina+commedia&maximumRecords=5"
```

#### 3. Cercare libri per autore in formato Dublin Core
```bash
curl "https://tuo-dominio.it/api/sru?operation=searchRetrieve&query=dc.creator=dante&recordSchema=dc"
```

#### 4. Cercare per ISBN in formato MARCXML
```bash
curl "https://tuo-dominio.it/api/sru?operation=searchRetrieve&query=bath.isbn=9788804666592&recordSchema=marcxml"
```

#### 5. Ricerca paginata
```bash
# Prima pagina (record 1-10)
curl "https://tuo-dominio.it/api/sru?operation=searchRetrieve&query=cql.anywhere=fantasy&maximumRecords=10&startRecord=1"

# Seconda pagina (record 11-20)
curl "https://tuo-dominio.it/api/sru?operation=searchRetrieve&query=cql.anywhere=fantasy&maximumRecords=10&startRecord=11"
```

#### 6. Ricerca per disponibilità (NUOVO)
```bash
# Solo libri con copie disponibili
curl "https://tuo-dominio.it/api/sru?operation=searchRetrieve&query=library.available=true"

# Libri con copie in prestito
curl "https://tuo-dominio.it/api/sru?operation=searchRetrieve&query=library.available=prestato"
```

#### 7. Ricerca per localizzazione (NUOVO)
```bash
# Libri sullo scaffale A
curl "https://tuo-dominio.it/api/sru?operation=searchRetrieve&query=library.shelf=A"

# Ricerca per numero inventario specifico
curl "https://tuo-dominio.it/api/sru?operation=searchRetrieve&query=library.inventory=INV-12345"
```

#### 8. Ordinamento risultati (NUOVO)
```bash
# Ordina per titolo ascendente
curl "https://tuo-dominio.it/api/sru?operation=searchRetrieve&query=dc.creator=dante&sortKeys=dc.title,,1"

# Ordina per data discendente (più recenti prima)
curl "https://tuo-dominio.it/api/sru?operation=searchRetrieve&query=cql.anywhere=storia&sortKeys=dc.date,,0"
```

#### 9. Query complesse con disponibilità
```bash
# Titolo specifico + solo disponibili + ordinamento
curl "https://tuo-dominio.it/api/sru?operation=searchRetrieve&query=dc.title=divina%20AND%20library.available=true&sortKeys=dc.title,,1"

# Autore + disponibilità + formato Dublin Core
curl "https://tuo-dominio.it/api/sru?operation=searchRetrieve&query=dc.creator=dante%20AND%20library.available=true&recordSchema=dc"
```

## Integrazione con Altri Sistemi

### Client Z39.50/SRU
Molti sistemi bibliotecari possono connettersi al tuo server SRU:

- **VuFind** - Discovery interface per biblioteche
- **Koha** - Sistema bibliotecario open source
- **Evergreen** - ILS open source
- **WorldCat** - Catalogo bibliografico mondiale
- **Altri sistemi ILS** che supportano SRU/Z39.50

### Configurazione Client Tipica
```
URL: https://tuo-dominio.it/api/sru
Protocollo: SRU 1.2
Database: catalog
Formato preferito: MARCXML o Dublin Core
```

## Sicurezza

Il plugin implementa le seguenti misure di sicurezza secondo le best practice OWASP:

### 1. Rate Limiting
Protegge contro attacchi DoS limitando il numero di richieste per IP.

### 2. Input Validation
Tutti gli input sono sanitizzati e validati prima dell'elaborazione.

### 3. SQL Injection Prevention
Tutte le query usano prepared statements per prevenire SQL injection.

### 4. XSS Prevention
Tutti gli output XML usano proper escaping per prevenire XSS.

### 5. Error Handling
Messaggi di errore generici per evitare information disclosure.

### 6. Logging
Tutte le richieste sono loggate per audit e troubleshooting.

## Logging e Monitoring

### Tabelle di Log

#### z39_access_logs
Traccia tutte le richieste SRU:
- IP client
- Operazione richiesta
- Query eseguita
- Formato richiesto
- Numero record ritornati
- Tempo di risposta
- Errori

#### z39_rate_limits
Traccia i limiti di rate per IP:
- Indirizzo IP
- Numero richieste
- Finestra temporale

### Query di Monitoring

```sql
-- Richieste nelle ultime 24 ore
SELECT COUNT(*) as total_requests
FROM z39_access_logs
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR);

-- Richieste per operazione
SELECT operation, COUNT(*) as count
FROM z39_access_logs
GROUP BY operation;

-- Performance media
SELECT AVG(response_time_ms) as avg_response_time_ms
FROM z39_access_logs
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR);

-- Top IP per richieste
SELECT ip_address, COUNT(*) as requests
FROM z39_access_logs
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY ip_address
ORDER BY requests DESC
LIMIT 10;
```

## Performance e Ottimizzazione

### Indici Database Raccomandati

Per garantire performance ottimali, specialmente con cataloghi di grandi dimensioni, si consiglia di creare i seguenti indici:

```sql
-- Indici per ricerche testuali
CREATE INDEX idx_libri_titolo ON libri(titolo);
CREATE INDEX idx_libri_isbn10 ON libri(isbn10);
CREATE INDEX idx_libri_isbn13 ON libri(isbn13);
CREATE INDEX idx_libri_ean ON libri(ean);
CREATE INDEX idx_autori_nome ON autori(nome);
CREATE INDEX idx_editori_nome ON editori(nome);
CREATE INDEX idx_generi_nome ON generi(nome);

-- Indici per copie e disponibilità  
CREATE INDEX idx_copie_libro_id ON copie(libro_id);
CREATE INDEX idx_copie_stato ON copie(stato);
CREATE INDEX idx_copie_numero_inventario ON copie(numero_inventario);

-- Indici per localizzazione
CREATE INDEX idx_scaffali_nome ON scaffali(nome);
CREATE INDEX idx_scaffali_codice ON scaffali(codice);
CREATE INDEX idx_libri_collocazione ON libri(collocazione);

-- Indici composti per query complesse
CREATE INDEX idx_copie_libro_stato ON copie(libro_id, stato);
CREATE INDEX idx_libri_autori ON libri_autori(libro_id, autore_id);
```

### Note sulle Performance

- **Ricerche per disponibilità**: Utilizzano subquery EXISTS ottimizzate, generalmente veloci anche su grandi dataset
- **Ordinamento per autore**: Usa colonne aggregate (GROUP_CONCAT), può essere più lento su dataset molto grandi
- **Full-text search**: Per ricerche testuali molto performanti su grandi cataloghi, valutare l'uso di indici FULLTEXT MySQL
- **Paginazione**: Il parametro `startRecord` usa OFFSET, che può degradare su offset molto alti (migliaia di record)
- **Caching**: Considerare l'implementazione di cache per query frequenti

### Limiti e Considerazioni

- Massimo 100 record per richiesta (configurabile)
- Rate limiting default: 100 richieste/ora per IP
- Le query con molte condizioni OR possono essere più lente
- Le ricerche `cql.anywhere` scansionano molti campi e possono essere più lente

## Troubleshooting

### Il server non risponde
1. Verifica che il plugin sia attivato
2. Controlla che `server_enabled` sia impostato a `true`
3. Verifica i log di Apache/Nginx per errori

### Errore "Rate limit exceeded"
1. Aumenta `rate_limit_requests` nelle impostazioni
2. Oppure aumenta `rate_limit_window` per una finestra più lunga
3. Oppure disabilita temporaneamente il rate limiting (`rate_limit_enabled=false`)

### Record non formattati correttamente
1. Verifica che i dati del catalogo siano completi
2. Prova formati diversi (MARCXML, DC, MODS)
3. Controlla i log del plugin per errori

### Query CQL non funzionanti
1. Verifica la sintassi CQL (vedi esempi sopra)
2. Usa indici supportati (dc.title, dc.creator, etc.)
3. Escape caratteri speciali se necessario

## Supporto e Contributi

Per bug report, feature request o contributi:
1. Apri una issue nel repository
2. Descrivi il problema dettagliatamente
3. Include esempi di richieste/risposte se pertinente

## Standard e Riferimenti

- [SRU 1.2 Specification](https://www.loc.gov/standards/sru/)
- [CQL Specification](https://www.loc.gov/standards/sru/cql/)
- [MARC 21](https://www.loc.gov/marc/)
- [Dublin Core](https://www.dublincore.org/)
- [MODS](https://www.loc.gov/standards/mods/)
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)

## Licenza

Questo plugin è rilasciato sotto la stessa licenza di Pinakes.

## Changelog

### Version 1.1.0 (2025)
- **NUOVO**: Informazioni complete sulle copie in tutti i formati di output
  - Campo MARC 852 (Holdings Location) per ogni copia
  - Campo MARC 866 (Summary Holdings) con conteggi
  - dc:identifier e dc:rights in Dublin Core con info copie
  - Elementi location e holdingSimple in MODS
- **NUOVO**: Ricerca per disponibilità (indice `library.available`)
  - Filtra libri con copie disponibili (`library.available=true`)
  - Ricerca per stato specifico (`library.available=prestato`)
- **NUOVO**: Ricerca per localizzazione
  - `library.location` - Localizzazione generale
  - `library.shelf` - Scaffale specifico
  - `library.inventory` - Numero inventario
- **NUOVO**: Supporto sortKeys SRU 1.2
  - Ordinamento per titolo, autore, data, ISBN
  - Direzione ascendente/discendente
- **NUOVO**: Indice `dc.identifier` per ricerca unificata ISBN/EAN
- **MIGLIORATO**: Query builder con JOIN completi (copie, posizioni, scaffali, mensole)
- **MIGLIORATO**: Subquery EXISTS per ricerche di disponibilità (performance ottimali)

### Version 1.0.0 (2025)
- Rilascio iniziale
- Supporto SRU 1.2 completo
- Formati: MARCXML, Dublin Core, MODS, OAI-DC
- Rate limiting e sicurezza OWASP
- Logging completo
- Pannello di amministrazione

---

**Autore:** Biblioteca
**Versione:** 1.1.0
**Requisiti:** PHP 7.4+, Pinakes 1.0.0+
