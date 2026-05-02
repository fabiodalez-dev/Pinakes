# Archivi (ISAD(G) / ISAAR(CPF))

Il plugin **Archives** gestisce materiale archivistico e fotografico
accanto al catalogo bibliografico. Segue gli standard internazionali
dell'International Council on Archives:

- **[ISAD(G)](https://www.ica.org/en/isadg-general-international-standard-archival-description-second-edition)** — *General International Standard Archival Description*, per la descrizione gerarchica dei documenti;
- **[ISAAR(CPF)](https://www.ica.org/en/isaar-cpf-international-standard-archival-authority-record-corporate-bodies-persons-and-families-2nd)** — *International Standard Archival Authority Record for Corporate bodies, Persons and Families*, per i record di autorità.

Il plugin è **inattivo per default** (richiede opt-in). Attivalo da
**Amministrazione → Plugin** — la prima attivazione crea le quattro tabelle
(`archival_units`, `authority_records`, `archival_unit_authority`,
`autori_authority_link`).

> Introdotto in v0.5.9 — traccia issue [#103](https://github.com/fabiodalez-dev/Pinakes/issues/103).

## Modello dati

Tre tabelle con relazione M:N e self-reference:

```
archival_units (alberatura ISAD(G), 4 livelli)
  ├── id, parent_id                                      ← gerarchia
  ├── level: fonds | series | file | item                ← ISAD(G) 3.1.4
  ├── reference_code, title, creator, extent             ← 3.1.1–3.1.5
  ├── date_range_start, date_range_end
  ├── scope_content, admin_biog_history, custodial_history
  ├── access_conditions, reproduction_conditions
  ├── language_of_material, physical_characteristics
  ├── specific_material (ENUM — vedi sezione "Foto")     ← Phase 5
  ├── cover_image_path, document_path, document_mime     ← upload allegati
  └── deleted_at                                         ← soft delete

authority_records (ISAAR(CPF))
  ├── id
  ├── type: person | corporate_body | family             ← ISAAR 5.1.2
  ├── authorised_form_of_name, parallel_forms            ← 5.1.3/5.1.5
  ├── dates_of_existence, history, places, legal_status  ← 5.2.x
  ├── functions_occupations, mandates, internal_structure
  ├── general_context, maintenance_notes
  └── deleted_at

archival_unit_authority (link M:N)
  ├── archival_unit_id → archival_units.id
  ├── authority_id     → authority_records.id
  └── role: creator | subject | custodian | recipient | associated

autori_authority_link (riconciliazione con il catalogo bibliografico)
  ├── autori_id    → autori.id
  └── authority_id → authority_records.id
```

## Flusso operativo base

### 1. Creare un fondo

**Amministrazione → Archivi → Nuovo record**:

1. Scegli livello `fonds` (livello più alto).
2. Compila Reference Code (es. `IT-FMC-01`), Title (es. "Fondo Famiglia
   Mocenigo, carte amministrative"), Creator (es. "Famiglia Mocenigo,
   Venezia, sec. XV–XVIII"), Extent (es. "12 buste, 480 fascicoli").
3. Date Range (se conosciuto).
4. Salva.

### 2. Aggiungere serie, fascicoli, unità

Dalla pagina di dettaglio di un fondo, clicca **Aggiungi figlio**. Il
child eredita il Reference Code come prefisso (es. `IT-FMC-01/001`
per la prima serie). Puoi nidificare fino a 4 livelli: `fonds` →
`series` → `file` → `item`.

### 3. Collegare record di autorità

Nella scheda di un'unità archivistica c'è la sezione **Authority
records**: type-ahead (JS live-search) per trovare un record
esistente, o "Crea nuovo" per aprire il form ISAAR inline. Scegli il
ruolo (`creator`, `subject`, `custodian`, `recipient`) e salva.

Gli stessi authority records vengono letti anche dal catalogo
bibliografico (`libri.autori`) — è l'authority file unificato per tutto
Pinakes, non per modulo separato.

### 4. Upload cover e documento digitale

Se l'unità ha materiale digitalizzato:

- **Cover**: JPEG/PNG/WebP, mostrata nella card grid.
- **Document**: PDF/ePub/MP3/MP4/MKV, reso disponibile al pubblico su
  `/archivio/<slug>`. MIME detection via `finfo_file` (no trust su
  `$_FILES['type']`), con unlink guard che vincola il path alla cartella
  `public/uploads/archives/` (no path traversal).

## Cross-entity search

La barra di ricerca unificata (header admin + endpoint `/api/search`)
interroga contemporaneamente `libri`, `archival_units` e
`authority_records` e rende ogni hit con il suo label di provenienza
("Libro", "Archivio · Serie", "Autorità · Persona"). Utile per trovare
rapidamente un nome che compare sia come autore di un libro sia come
creator di un fondo.

## Riconciliazione autori bibliografici

La pagina di dettaglio di un **record di autorità** espone una sezione
**Autori bibliografici collegati**. Qui puoi collegare (o scollegare)
un `autori` del catalogo bibliografico allo stesso soggetto ISAAR:

```
POST /admin/archives/authorities/{id}/autori/link
POST /admin/archives/authorities/{id}/autori/{autori_id}/unlink
```

Il collegamento viene salvato nella tabella `autori_authority_link`. Ciò
crea un bridge di identità: dichiari esplicitamente che "l'autore
bibliografico *Mario Rossi* è la stessa persona dell'autorità ISAAR
*Rossi, Mario, 1943–2021*". Questa riconciliazione — analoga alla
funzione VIAF nelle biblioteche nazionali — consente ricerche
cross-entità e sarà usata da esportatori futuri (linked data, RDF) per
produrre identificatori univoci stabili.

> **Nota**: il collegamento è molti-a-molti: un autore bibliografico
> può essere riconciliato con più authority records (p. es. autore sia
> come persona sia come corporazione), e un'authority può avere più
> autori collegati (p. es. pseudonimi differenti).

## Catalogo pubblico

Quando il plugin è attivo, espone automaticamente un **frontend
pubblico** accessibile senza autenticazione, all'URL locale-aware:

| Locale | URL |
|--------|-----|
| Italiano | `/archivio/` |
| Inglese | `/archive/` |
| Tedesco | `/archiv/` (se configurato) |

La pagina indice elenca i **fondi di primo livello** in ordine per
reference code. Ogni fondo ha una pagina di dettaglio raggiungibile
tramite URL SEO-friendly:

```
/archivio/{slug}-{id}     ← forma canonica (indicizzata dai motori)
/archivio/{id}            ← legacy, 301 redirect → forma canonica
```

Il redirect 301 garantisce che cambios al titolo (che aggiornano lo
slug) non frammentino il page rank.

Dalla pagina di dettaglio pubblica è possibile:
- Navigare la gerarchia fondo/serie/fascicolo/unità.
- Scaricare il documento digitale allegato (PDF, audio, video).
- Vedere il Dublin Core dell'unità tramite `<link rel="alternate">` nell'`<head>`.

## Photographic items (Phase 5)

La colonna `specific_material` classifica materiale fotografico e
audiovisivo secondo i codici MARC21 008-33 / ABA billedmarc:

| Codice | Materiale |
|:---:|---|
| `hb` | Fotografia |
| `hp` | Fotografia, stampa positiva |
| `hm` | Fotografia, stampa al collodio |
| `hd` | Diapositiva |
| `hk` | Cartolina |
| `bf` | Disegno |
| `hf` | Dipinto |
| `lm` | Mappa |
| `lf` | Pianta topografica |
| `vm` | Videocassetta / film |
| `bm` | Registrazione sonora |
| `le` | Oggetto tridimensionale |
| `zz` | Non applicabile |

La dropdown è multiselect: un fondo fotografico può mescolare positivi,
diapositive, negativi.

## MARCXML Import/Export (Phase 4)

### Export

Dalla toolbar del fondo: **Esporta → MARCXML**. Pinakes genera un
documento MARC21 Slim conforme allo [XSD ufficiale](https://www.loc.gov/standards/marcxml/schema/MARC21slim.xsd),
validato server-side prima del download. Il mapping ISAD → MARC segue
il crosswalk del formato ABA (Arbejderbevægelsens Bibliotek og Arkiv,
Copenhagen).

### Import

**Archivi → Importa → MARCXML**. Pinakes valida il file contro lo
stesso XSD, poi esegue un import transazionale che crea la struttura
gerarchica a partire dai campi `773`/`787` (host/related).

### Round-trip

Il test di identità verifica: `export → import → re-export` ⇒ output
byte-identico. Questo garantisce che non si perda informazione
attraversando il formato di interscambio.

## Endpoint SRU (Phase 6)

Il plugin espone un endpoint SRU 1.2 anche per i record archivistici:

```
https://tuo-dominio.tld/api/sru/archives?operation=searchRetrieve&version=1.2&query=dc.creator=%22Mocenigo%22
```

Interoperabile con OPAC esterni, gateway Z39.50 e federated search.
Record restituiti in MARCXML, Dublin Core o MODS a scelta
(`recordSchema=marcxml|dc|mods`).

## Ricerca unificata

> Disponibile in versione futura (PR #120 in revisione).

La barra di ricerca admin (header + endpoint `/api/search/unified`)
include le **unità archivistiche** tra i risultati, insieme a libri,
autori e editori. Ogni hit mostra il tipo di origine ("Archivio · Fondo",
"Archivio · Serie", ecc.) per distinguere il contesto a colpo d'occhio.

Usa la barra di ricerca per trovare rapidamente:
- Un fondo per codice di riferimento o titolo.
- Un'unità archivistica per data, creator o luogo.
- Un record di autorità per nome.

## Interoperabilità: Dublin Core, EAD3, OAI-PMH

> Disponibile in versione futura (PR #127 in revisione).

Il plugin Archives implementa tre protocolli standard di interoperabilità
archivistica, che permettono ad altri sistemi (OPAC, portali culturali,
aggregatori) di raccogliere e scambiare dati con Pinakes.

### Dublin Core XML

Ogni unità archivistica può essere esportata in formato Dublin Core:

```
GET /archives/{id}/dc.xml
```

L'output è un documento XML conforme al namespace Dublin Core (`dc:`).
Un link `<link rel="alternate" type="application/xml">` è inserito
nell'`<head>` di ogni pagina di dettaglio unità per supportare la
scoperta automatica.

**Esempio risposta:**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<oai_dc:dc xmlns:oai_dc="http://www.openarchives.org/OAI/2.0/oai_dc/"
           xmlns:dc="http://purl.org/dc/elements/1.1/">
  <dc:title>Fondo Famiglia Mocenigo</dc:title>
  <dc:creator>Famiglia Mocenigo</dc:creator>
  <dc:date>1450/1720</dc:date>
  <dc:type>Collection</dc:type>
  <dc:identifier>IT-FMC-01</dc:identifier>
</oai_dc:dc>
```

### EAD3 Bulk Export

Esporta più unità archivistiche in un unico documento EAD3:

```
GET /admin/archives/export.ead3?ids=1,2,3
```

Il parametro `ids` è una lista separata da virgole degli ID delle unità
da esportare. Il documento EAD3 risultante include la struttura
gerarchica completa di ciascuna unità e può essere importato in sistemi
archivistici compatibili (ArchivesSpace, AtoM, Archivematica).

**Dall'interfaccia:** seleziona le unità nella lista archivi e usa il
pulsante **Esporta → EAD3** nella toolbar.

### OAI-PMH 2.0

Pinakes espone un endpoint OAI-PMH 2.0 completo per il harvesting
automatico dei record archivistici:

```
GET /archives/oai
POST /archives/oai
```

#### Verbs supportati

| Verb | Descrizione |
|------|-------------|
| `Identify` | Informazioni sul repository |
| `ListMetadataFormats` | Formati disponibili: `oai_dc`, `ead3` |
| `ListRecords` | Tutti i record (con paginazione) |
| `GetRecord` | Singolo record per identificatore |
| `ListIdentifiers` | Solo header (più leggero di ListRecords) |
| `ListSets` | Set disponibili (livelli ISAD(G)) |

#### Set ISAD(G)

I set corrispondono ai livelli gerarchici:

| Set | Livello ISAD(G) |
|-----|-----------------|
| `fonds` | Fondo |
| `series` | Serie |
| `file` | Fascicolo |
| `item` | Unità documentaria |

#### Formati metadati

| Prefisso | Schema |
|----------|--------|
| `oai_dc` | Dublin Core semplice |
| `ead3` | EAD3 (Encoded Archival Description 3) |

#### Paginazione (ResumptionToken)

Per dataset grandi, OAI-PMH usa un `resumptionToken` per restituire
i record in pagine. Il token è codificato in base64url JSON e contiene
la posizione del cursore e i parametri della query originale.

**Esempio di interrogazione completa:**

```
# Identificazione repository
GET /archives/oai?verb=Identify

# Lista tutti i record in Dublin Core
GET /archives/oai?verb=ListRecords&metadataPrefix=oai_dc

# Lista solo i fondi
GET /archives/oai?verb=ListRecords&metadataPrefix=oai_dc&set=fonds

# Recupera record successivo (con token)
GET /archives/oai?verb=ListRecords&resumptionToken=<token>

# Record singolo
GET /archives/oai?verb=GetRecord&metadataPrefix=oai_dc&identifier=oai:pinakes:archives:42
```

## Attivazione e schema

```
Amministrazione → Plugin → Archives (ISAD(G) / ISAAR(CPF)) → Attiva
```

La prima attivazione esegue `ensureSchema()`: crea le 4 tabelle via
migration idempotente. Disattivare il plugin NON elimina i dati — basta
riattivarlo per riavere tutto.

## Limiti noti

- Nessun editor visuale per la tree-view gerarchica (solo form +
  reference_code strutturato). Roadmap Phase 8.
- L'import MARCXML non materializza relazioni incrociate tra
  `archival_units` di fondi diversi — vanno rifatte a mano.
- La ricerca unified indicizza on-query (no FULLTEXT precomputato);
  su dataset >100k record il ranking potrebbe rallentare. Roadmap Phase 9.
