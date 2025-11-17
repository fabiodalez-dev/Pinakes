# Esempi di Utilizzo - Z39.50/SRU Server

## Esempi di Richieste SRU

### 1. Explain - Ottenere Informazioni sul Server

**Richiesta:**
```bash
curl "http://localhost/api/sru?operation=explain"
```

**Risposta (esempio):**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<explainResponse xmlns="http://www.loc.gov/zing/srw/">
  <version>1.2</version>
  <record>
    <recordSchema>http://explain.z3950.org/dtd/2.1/</recordSchema>
    <recordPacking>xml</recordPacking>
    <recordData>
      <explain>
        <serverInfo protocol="SRU" version="1.2">
          <host>localhost</host>
          <port>80</port>
          <database>catalog</database>
        </serverInfo>
        <databaseInfo>
          <title>Library Catalog - Pinakes</title>
          <description>SRU interface to library catalog</description>
        </databaseInfo>
        <indexInfo>
          <index>
            <title>Title</title>
            <map><name>dc.title</name></map>
          </index>
          <index>
            <title>Author</title>
            <map><name>dc.creator</name></map>
          </index>
          ...
        </indexInfo>
      </explain>
    </recordData>
  </record>
</explainResponse>
```

### 2. SearchRetrieve - Ricerca Base

**Ricerca per titolo:**
```bash
curl "http://localhost/api/sru?operation=searchRetrieve&query=dc.title=divina+commedia"
```

**Ricerca per autore:**
```bash
curl "http://localhost/api/sru?operation=searchRetrieve&query=dc.creator=dante"
```

**Ricerca per ISBN:**
```bash
curl "http://localhost/api/sru?operation=searchRetrieve&query=bath.isbn=9788804666592"
```

**Ricerca in tutti i campi:**
```bash
curl "http://localhost/api/sru?operation=searchRetrieve&query=cql.anywhere=fantasy"
```

### 3. SearchRetrieve con Formati Diversi

**MARCXML (default):**
```bash
curl "http://localhost/api/sru?operation=searchRetrieve&query=dc.title=harry+potter&recordSchema=marcxml&maximumRecords=5"
```

**Dublin Core:**
```bash
curl "http://localhost/api/sru?operation=searchRetrieve&query=dc.title=harry+potter&recordSchema=dc&maximumRecords=5"
```

**MODS:**
```bash
curl "http://localhost/api/sru?operation=searchRetrieve&query=dc.title=harry+potter&recordSchema=mods&maximumRecords=5"
```

**OAI Dublin Core:**
```bash
curl "http://localhost/api/sru?operation=searchRetrieve&query=dc.title=harry+potter&recordSchema=oai_dc&maximumRecords=5"
```

### 4. Paginazione

**Prima pagina (record 1-10):**
```bash
curl "http://localhost/api/sru?operation=searchRetrieve&query=cql.anywhere=romanzo&startRecord=1&maximumRecords=10"
```

**Seconda pagina (record 11-20):**
```bash
curl "http://localhost/api/sru?operation=searchRetrieve&query=cql.anywhere=romanzo&startRecord=11&maximumRecords=10"
```

**Terza pagina (record 21-30):**
```bash
curl "http://localhost/api/sru?operation=searchRetrieve&query=cql.anywhere=romanzo&startRecord=21&maximumRecords=10"
```

### 5. Query CQL Avanzate

**Query AND (tutti i termini devono essere presenti):**
```bash
curl "http://localhost/api/sru?operation=searchRetrieve&query=dc.title=harry+AND+dc.creator=rowling"
```

**Query OR (almeno un termine deve essere presente):**
```bash
curl "http://localhost/api/sru?operation=searchRetrieve&query=dc.creator=dante+OR+dc.creator=petrarca"
```

**Ricerca per anno:**
```bash
curl "http://localhost/api/sru?operation=searchRetrieve&query=dc.date=2020"
```

**Ricerca per editore:**
```bash
curl "http://localhost/api/sru?operation=searchRetrieve&query=dc.publisher=mondadori"
```

## Esempi di Integrazione

### Python con requests

```python
import requests
import xml.etree.ElementTree as ET

def search_books(title, max_records=10):
    """Cerca libri per titolo usando SRU"""
    url = "http://localhost/api/sru"
    params = {
        "operation": "searchRetrieve",
        "query": f"dc.title={title}",
        "maximumRecords": max_records,
        "recordSchema": "dc"
    }

    response = requests.get(url, params=params)

    if response.status_code == 200:
        root = ET.fromstring(response.content)
        # Parse XML response
        return root
    else:
        print(f"Error: {response.status_code}")
        return None

# Esempio di utilizzo
books = search_books("dante", max_records=5)
```

### JavaScript/Node.js con axios

```javascript
const axios = require('axios');
const xml2js = require('xml2js');

async function searchBooks(title, maxRecords = 10) {
    try {
        const response = await axios.get('http://localhost/api/sru', {
            params: {
                operation: 'searchRetrieve',
                query: `dc.title=${title}`,
                maximumRecords: maxRecords,
                recordSchema: 'dc'
            }
        });

        // Parse XML response
        const parser = new xml2js.Parser();
        const result = await parser.parseStringPromise(response.data);

        return result;
    } catch (error) {
        console.error('Error:', error.message);
        return null;
    }
}

// Esempio di utilizzo
searchBooks('harry potter', 5).then(books => {
    console.log(JSON.stringify(books, null, 2));
});
```

### PHP con cURL

```php
<?php

function searchBooks($title, $maxRecords = 10) {
    $url = 'http://localhost/api/sru';
    $params = http_build_query([
        'operation' => 'searchRetrieve',
        'query' => "dc.title=$title",
        'maximumRecords' => $maxRecords,
        'recordSchema' => 'dc'
    ]);

    $ch = curl_init($url . '?' . $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/xml'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $xml = simplexml_load_string($response);
        return $xml;
    } else {
        return null;
    }
}

// Esempio di utilizzo
$books = searchBooks('dante', 5);
if ($books) {
    print_r($books);
}
?>
```

### Java con HttpClient

```java
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;

public class SRUClient {

    public static String searchBooks(String title, int maxRecords) throws Exception {
        String url = String.format(
            "http://localhost/api/sru?operation=searchRetrieve&query=dc.title=%s&maximumRecords=%d&recordSchema=dc",
            title, maxRecords
        );

        HttpClient client = HttpClient.newHttpClient();
        HttpRequest request = HttpRequest.newBuilder()
            .uri(URI.create(url))
            .GET()
            .build();

        HttpResponse<String> response = client.send(request,
            HttpResponse.BodyHandlers.ofString());

        if (response.statusCode() == 200) {
            return response.body();
        } else {
            throw new Exception("Error: " + response.statusCode());
        }
    }

    public static void main(String[] args) {
        try {
            String xml = searchBooks("harry potter", 5);
            System.out.println(xml);
        } catch (Exception e) {
            e.printStackTrace();
        }
    }
}
```

## Esempi di Risposta

### MARCXML Response

```xml
<?xml version="1.0" encoding="UTF-8"?>
<searchRetrieveResponse xmlns="http://www.loc.gov/zing/srw/">
  <version>1.2</version>
  <numberOfRecords>42</numberOfRecords>
  <record>
    <recordSchema>marcxml</recordSchema>
    <recordPacking>xml</recordPacking>
    <recordPosition>1</recordPosition>
    <recordData>
      <record xmlns="http://www.loc.gov/MARC21/slim">
        <leader>00000nam a2200000 a 4500</leader>
        <controlfield tag="001">123</controlfield>
        <controlfield tag="008">250116s2020    xx            000 0 ita d</controlfield>
        <datafield tag="020" ind1=" " ind2=" ">
          <subfield code="a">9788804666592</subfield>
        </datafield>
        <datafield tag="100" ind1="1" ind2=" ">
          <subfield code="a">Dante Alighieri</subfield>
        </datafield>
        <datafield tag="245" ind1="1" ind2="0">
          <subfield code="a">La Divina Commedia</subfield>
        </datafield>
        <datafield tag="260" ind1=" " ind2=" ">
          <subfield code="b">Mondadori</subfield>
          <subfield code="c">2020</subfield>
        </datafield>
        <datafield tag="300" ind1=" " ind2=" ">
          <subfield code="a">400 p.</subfield>
        </datafield>
      </record>
    </recordData>
  </record>
  ...
</searchRetrieveResponse>
```

### Dublin Core Response

```xml
<?xml version="1.0" encoding="UTF-8"?>
<searchRetrieveResponse xmlns="http://www.loc.gov/zing/srw/">
  <version>1.2</version>
  <numberOfRecords>42</numberOfRecords>
  <record>
    <recordSchema>dc</recordSchema>
    <recordPacking>xml</recordPacking>
    <recordPosition>1</recordPosition>
    <recordData>
      <oai_dc:dc xmlns:dc="http://purl.org/dc/elements/1.1/"
                 xmlns:oai_dc="http://www.openarchives.org/OAI/2.0/oai_dc/">
        <dc:title>La Divina Commedia</dc:title>
        <dc:creator>Dante Alighieri</dc:creator>
        <dc:publisher>Mondadori</dc:publisher>
        <dc:date>2020</dc:date>
        <dc:type>Text</dc:type>
        <dc:format>cartaceo</dc:format>
        <dc:identifier>ISBN:9788804666592</dc:identifier>
        <dc:language>italiano</dc:language>
      </oai_dc:dc>
    </recordData>
  </record>
  ...
</searchRetrieveResponse>
```

## Test con Postman

1. Apri Postman
2. Crea una nuova richiesta GET
3. Inserisci l'URL: `http://localhost/api/sru`
4. Aggiungi i parametri nella sezione "Params":
   - `operation`: `searchRetrieve`
   - `query`: `dc.title=dante`
   - `maximumRecords`: `5`
   - `recordSchema`: `dc`
5. Clicca "Send"

## Test con Browser

Puoi testare direttamente dal browser visitando:

```
http://localhost/api/sru?operation=explain
```

Il browser mostrerà l'XML formattato.

## Monitoraggio con SQL

**Vedere ultime richieste:**
```sql
SELECT
    ip_address,
    operation,
    query,
    format,
    num_records,
    response_time_ms,
    created_at
FROM z39_access_logs
ORDER BY created_at DESC
LIMIT 20;
```

**Statistiche per oggi:**
```sql
SELECT
    operation,
    COUNT(*) as count,
    AVG(response_time_ms) as avg_time_ms,
    MAX(response_time_ms) as max_time_ms
FROM z39_access_logs
WHERE DATE(created_at) = CURDATE()
GROUP BY operation;
```

**Top 10 query più frequenti:**
```sql
SELECT
    query,
    COUNT(*) as count
FROM z39_access_logs
WHERE query IS NOT NULL
GROUP BY query
ORDER BY count DESC
LIMIT 10;
```

---

Per altri esempi e casi d'uso, consulta il file `README.md`.
