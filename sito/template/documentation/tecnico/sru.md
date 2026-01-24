# API SRU

Documentazione del protocollo SRU (Search/Retrieve via URL) implementato in Pinakes.

## Panoramica

Pinakes implementa il protocollo **SRU 1.2** per l'interoperabilità con altri sistemi bibliotecari. Il server SRU espone il catalogo della biblioteca per ricerche remote.

## Requisiti

Richiede il plugin **Z39.50/SRU Integration** (v1.1.0+).

## Endpoint

```
https://tua-biblioteca.it/api/sru
```

## Operazioni Supportate

### explain

Restituisce informazioni sul server SRU.

```
GET /api/sru?operation=explain
```

Risposta: XML con capacità del server, indici supportati, formati disponibili.

### searchRetrieve

Esegue una ricerca nel catalogo.

```
GET /api/sru?operation=searchRetrieve&query=dc.title=roma&recordSchema=marcxml
```

Parametri:
| Parametro | Descrizione | Obbligatorio |
|-----------|-------------|--------------|
| `query` | Query CQL | Sì |
| `recordSchema` | Formato risposta | No (default: dc) |
| `maximumRecords` | Max risultati | No (default: 10) |
| `startRecord` | Offset | No (default: 1) |

### scan

Scansiona gli indici del catalogo.

```
GET /api/sru?operation=scan&scanClause=dc.title
```

## Query CQL

Il linguaggio di query supportato è **CQL** (Contextual Query Language).

### Indici Supportati

| Indice | Campo |
|--------|-------|
| `dc.title` | Titolo |
| `dc.creator` | Autore |
| `dc.publisher` | Editore |
| `dc.date` | Anno pubblicazione |
| `dc.subject` | Genere/Soggetto |
| `dc.identifier` | ISBN |
| `rec.id` | ID record |

### Esempi Query

```
# Ricerca per titolo
dc.title=divina commedia

# Ricerca per autore
dc.creator=dante

# Ricerca per ISBN
dc.identifier=9788804668237

# Ricerca combinata
dc.title=inferno AND dc.creator=dante

# Ricerca con wildcard
dc.title=div*
```

### Operatori

| Operatore | Significato |
|-----------|-------------|
| `=` | Uguale/Contiene |
| `AND` | Entrambe le condizioni |
| `OR` | Almeno una condizione |
| `NOT` | Esclusione |

## Formati Risposta

### Dublin Core (dc)

Formato semplice, 15 elementi standard.

```xml
<srw:record>
  <srw:recordData>
    <oai_dc:dc>
      <dc:title>La Divina Commedia</dc:title>
      <dc:creator>Dante Alighieri</dc:creator>
      <dc:publisher>Mondadori</dc:publisher>
      <dc:date>2021</dc:date>
      <dc:identifier>ISBN:9788804668237</dc:identifier>
    </oai_dc:dc>
  </srw:recordData>
</srw:record>
```

### MARCXML

Formato MARC 21 in XML, completo per scambio bibliografico.

```
?recordSchema=marcxml
```

### MODS

Metadata Object Description Schema.

```
?recordSchema=mods
```

## Esempio Completo

Richiesta:
```
GET /api/sru?operation=searchRetrieve
    &query=dc.creator=eco
    &recordSchema=dc
    &maximumRecords=5
    &startRecord=1
```

Risposta:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<srw:searchRetrieveResponse xmlns:srw="http://www.loc.gov/zing/srw/">
  <srw:version>1.2</srw:version>
  <srw:numberOfRecords>12</srw:numberOfRecords>
  <srw:records>
    <srw:record>
      <srw:recordSchema>info:srw/schema/1/dc-v1.1</srw:recordSchema>
      <srw:recordData>
        <oai_dc:dc>
          <dc:title>Il nome della rosa</dc:title>
          <dc:creator>Umberto Eco</dc:creator>
          <!-- altri elementi -->
        </oai_dc:dc>
      </srw:recordData>
    </srw:record>
    <!-- altri record -->
  </srw:records>
</srw:searchRetrieveResponse>
```

## Configurazione

Nel pannello plugin:

1. Vai in **Amministrazione → Plugin**
2. Trova "Z39.50/SRU Integration"
3. Clicca icona impostazioni
4. Configura:
   - Nome database
   - Descrizione
   - Contatto
   - Formati abilitati

## Client SRU

Il plugin include anche un **client** per interrogare server esterni:

1. Vai in **Catalogo → Ricerca Federata**
2. Seleziona i server da interrogare
3. Inserisci la query
4. I risultati vengono aggregati

### Server Preconfigurati

- OPAC SBN (Italia)
- Library of Congress (USA)
- British Library (UK)
- Personali configurabili

## Copy Cataloging

Importa record da cataloghi esterni:

1. Trova il libro nella Ricerca Federata
2. Clicca **Importa**
3. I metadati vengono copiati nel tuo catalogo
4. Modifica se necessario
5. Salva

---

## Domande Frequenti (FAQ)

### 1. Cos'è il protocollo SRU e a cosa serve?

**SRU** (Search/Retrieve via URL) è uno standard bibliotecario per lo scambio di dati catalografici tra sistemi diversi.

**Vantaggi:**
- Interoperabilità con altre biblioteche
- Ricerca federata su più cataloghi
- Import record da fonti autorevoli (SBN, Library of Congress)
- Standard internazionale (OASIS/LOC)

**In Pinakes:**
- **Server SRU**: espone il tuo catalogo per ricerche esterne
- **Client SRU**: interroga cataloghi esterni e importa record

---

### 2. Come abilito il server SRU nella mia installazione?

Il server SRU richiede il plugin **Z39.50/SRU Integration**:

1. Scarica il plugin dalla pagina release di Pinakes
2. Vai in **Amministrazione → Plugin → Carica plugin**
3. Carica il file ZIP
4. Attiva il plugin
5. L'endpoint `/api/sru` diventa disponibile

**Configurazione:**
1. Vai nelle impostazioni del plugin
2. Configura nome database e descrizione
3. Scegli i formati di risposta abilitati

---

### 3. Quali formati di risposta supporta il server SRU?

| Formato | Descrizione | Parametro |
|---------|-------------|-----------|
| **Dublin Core** | 15 elementi standard, semplice | `recordSchema=dc` |
| **MARCXML** | MARC 21 completo, per scambio bibliografico | `recordSchema=marcxml` |
| **MODS** | Metadata Object Description Schema | `recordSchema=mods` |

**Default:** Dublin Core (dc)

**Esempio:**
```
/api/sru?operation=searchRetrieve&query=dc.title=roma&recordSchema=marcxml
```

---

### 4. Come cerco un libro usando query CQL?

**CQL** (Contextual Query Language) è il linguaggio di query per SRU.

**Sintassi base:**
```
indice=valore
```

**Esempi:**
```
dc.title=divina commedia        # Per titolo
dc.creator=dante                # Per autore
dc.identifier=9788804668237     # Per ISBN
dc.title=inferno AND dc.creator=dante  # Combinata
dc.title=div*                   # Con wildcard
```

**Operatori:**
- `=` uguale/contiene
- `AND` entrambe le condizioni
- `OR` almeno una
- `NOT` esclusione

---

### 5. Come uso la ricerca federata per importare libri?

La ricerca federata interroga più cataloghi contemporaneamente:

1. Vai in **Catalogo → Ricerca Federata**
2. Seleziona i server da interrogare (SBN, LOC, British Library, ecc.)
3. Inserisci ISBN, titolo o autore
4. I risultati vengono aggregati da tutti i server
5. Clicca **Importa** sul record desiderato
6. Modifica i metadati se necessario
7. Salva nel tuo catalogo

**Vantaggio:** Copy cataloging professionale da fonti autorevoli.

---

### 6. Posso aggiungere server SRU personalizzati?

Sì, nelle impostazioni del plugin:

1. Vai in **Amministrazione → Plugin → Z39.50/SRU → Impostazioni**
2. Sezione "Server Esterni"
3. Aggiungi un nuovo server:
   - Nome: "Biblioteca Universitaria XYZ"
   - URL: `https://opac.xyz.it/sru`
   - Database: (opzionale, dipende dal server)

**Server preconfigurati:**
- OPAC SBN (Italia)
- Library of Congress (USA)
- British Library (UK)

---

### 7. Come limito i risultati della ricerca SRU?

Usa i parametri `maximumRecords` e `startRecord`:

```
/api/sru?operation=searchRetrieve
  &query=dc.creator=eco
  &maximumRecords=5
  &startRecord=1
```

**Parametri:**
| Parametro | Default | Descrizione |
|-----------|---------|-------------|
| `maximumRecords` | 10 | Numero max risultati |
| `startRecord` | 1 | Offset (per paginazione) |

**Paginazione:**
- Prima pagina: `startRecord=1&maximumRecords=10`
- Seconda pagina: `startRecord=11&maximumRecords=10`

---

### 8. Come ottengo informazioni sulle capacità del server SRU?

Usa l'operazione `explain`:

```
GET /api/sru?operation=explain
```

**Risposta XML include:**
- Nome e descrizione del database
- Indici supportati (dc.title, dc.creator, ecc.)
- Formati di risposta disponibili
- Limiti di query
- Informazioni di contatto

---

### 9. Quali differenze ci sono tra SRU e Z39.50?

| Aspetto | Z39.50 | SRU |
|---------|--------|-----|
| **Protocollo** | Binario, porta dedicata | HTTP/HTTPS |
| **Formato query** | PQF (complesso) | CQL (semplice) |
| **Risposta** | MARC binario | XML |
| **Firewall** | Problematico (porta 210) | Nessun problema (80/443) |
| **Debug** | Difficile | Facile (URL in browser) |

**In Pinakes:** Il plugin implementa SRU 1.2, che è il successore moderno di Z39.50. Non c'è un server Z39.50 nativo.

---

### 10. Come testo se il mio server SRU funziona?

**Test base:**
Apri nel browser:
```
https://tuabiblioteca.it/api/sru?operation=explain
```

Dovresti vedere un XML con le informazioni del server.

**Test ricerca:**
```
https://tuabiblioteca.it/api/sru?operation=searchRetrieve&query=dc.title=test
```

**Debug:**
Se non funziona:
1. Verifica che il plugin sia attivo
2. Controlla i log: `storage/logs/app.log`
3. Assicurati che `/api/sru` non sia bloccato da `.htaccess`
