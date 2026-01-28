# SRU API

Documentation for the SRU (Search/Retrieve via URL) protocol implemented in Pinakes.

## Overview

Pinakes implements the **SRU 1.2** protocol for interoperability with other library systems. The SRU server exposes the library catalog for remote searches.

## Requirements

Requires the **Z39.50/SRU Integration** plugin (v1.1.0+).

## Endpoint

```
https://your-library.com/api/sru
```

## Supported Operations

### explain

Returns information about the SRU server.

```
GET /api/sru?operation=explain
```

Response: XML with server capabilities, supported indexes, available formats.

### searchRetrieve

Executes a search in the catalog.

```
GET /api/sru?operation=searchRetrieve&query=dc.title=rome&recordSchema=marcxml
```

Parameters:
| Parameter | Description | Required |
|-----------|-------------|----------|
| `query` | CQL query | Yes |
| `recordSchema` | Response format | No (default: dc) |
| `maximumRecords` | Max results | No (default: 10) |
| `startRecord` | Offset | No (default: 1) |

### scan

Scans the catalog indexes.

```
GET /api/sru?operation=scan&scanClause=dc.title
```

## CQL Queries

The supported query language is **CQL** (Contextual Query Language).

### Supported Indexes

| Index | Field |
|-------|-------|
| `dc.title` | Title |
| `dc.creator` | Author |
| `dc.publisher` | Publisher |
| `dc.date` | Publication year |
| `dc.subject` | Genre/Subject |
| `dc.identifier` | ISBN |
| `rec.id` | Record ID |

### Query Examples

```
# Search by title
dc.title=divine comedy

# Search by author
dc.creator=dante

# Search by ISBN
dc.identifier=9788804668237

# Combined search
dc.title=inferno AND dc.creator=dante

# Search with wildcard
dc.title=div*
```

### Operators

| Operator | Meaning |
|----------|---------|
| `=` | Equals/Contains |
| `AND` | Both conditions |
| `OR` | At least one condition |
| `NOT` | Exclusion |

## Response Formats

### Dublin Core (dc)

Simple format, 15 standard elements.

```xml
<srw:record>
  <srw:recordData>
    <oai_dc:dc>
      <dc:title>The Divine Comedy</dc:title>
      <dc:creator>Dante Alighieri</dc:creator>
      <dc:publisher>Mondadori</dc:publisher>
      <dc:date>2021</dc:date>
      <dc:identifier>ISBN:9788804668237</dc:identifier>
    </oai_dc:dc>
  </srw:recordData>
</srw:record>
```

### MARCXML

MARC 21 format in XML, complete for bibliographic exchange.

```
?recordSchema=marcxml
```

### MODS

Metadata Object Description Schema.

```
?recordSchema=mods
```

## Complete Example

Request:
```
GET /api/sru?operation=searchRetrieve
    &query=dc.creator=eco
    &recordSchema=dc
    &maximumRecords=5
    &startRecord=1
```

Response:
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
          <dc:title>The Name of the Rose</dc:title>
          <dc:creator>Umberto Eco</dc:creator>
          <!-- other elements -->
        </oai_dc:dc>
      </srw:recordData>
    </srw:record>
    <!-- other records -->
  </srw:records>
</srw:searchRetrieveResponse>
```

## Configuration

In the plugin panel:

1. Go to **Administration → Plugins**
2. Find "Z39.50/SRU Integration"
3. Click settings icon
4. Configure:
   - Database name
   - Description
   - Contact
   - Enabled formats

## SRU Client

The plugin also includes a **client** to query external servers:

1. Go to **Catalog → Federated Search**
2. Select servers to query
3. Enter the query
4. Results are aggregated

### Preconfigured Servers

- OPAC SBN (Italy)
- Library of Congress (USA)
- British Library (UK)
- Custom configurable

## Copy Cataloging

Import records from external catalogs:

1. Find the book in Federated Search
2. Click **Import**
3. Metadata is copied to your catalog
4. Edit if necessary
5. Save

---

## Frequently Asked Questions (FAQ)

### 1. What is the SRU protocol and what is it for?

**SRU** (Search/Retrieve via URL) is a library standard for exchanging catalog data between different systems.

**Advantages:**
- Interoperability with other libraries
- Federated search across multiple catalogs
- Record import from authoritative sources (SBN, Library of Congress)
- International standard (OASIS/LOC)

**In Pinakes:**
- **SRU Server**: exposes your catalog for external searches
- **SRU Client**: queries external catalogs and imports records

---

### 2. How do I enable the SRU server in my installation?

The SRU server requires the **Z39.50/SRU Integration** plugin:

1. Download the plugin from the Pinakes release page
2. Go to **Administration → Plugins → Upload plugin**
3. Upload the ZIP file
4. Activate the plugin
5. The `/api/sru` endpoint becomes available

**Configuration:**
1. Go to plugin settings
2. Configure database name and description
3. Choose enabled response formats

---

### 3. What response formats does the SRU server support?

| Format | Description | Parameter |
|--------|-------------|-----------|
| **Dublin Core** | 15 standard elements, simple | `recordSchema=dc` |
| **MARCXML** | Complete MARC 21, for bibliographic exchange | `recordSchema=marcxml` |
| **MODS** | Metadata Object Description Schema | `recordSchema=mods` |

**Default:** Dublin Core (dc)

**Example:**
```
/api/sru?operation=searchRetrieve&query=dc.title=rome&recordSchema=marcxml
```

---

### 4. How do I search for a book using CQL queries?

**CQL** (Contextual Query Language) is the query language for SRU.

**Basic syntax:**
```
index=value
```

**Examples:**
```
dc.title=divine comedy          # By title
dc.creator=dante                # By author
dc.identifier=9788804668237     # By ISBN
dc.title=inferno AND dc.creator=dante  # Combined
dc.title=div*                   # With wildcard
```

**Operators:**
- `=` equals/contains
- `AND` both conditions
- `OR` at least one
- `NOT` exclusion

---

### 5. How do I use federated search to import books?

Federated search queries multiple catalogs simultaneously:

1. Go to **Catalog → Federated Search**
2. Select servers to query (SBN, LOC, British Library, etc.)
3. Enter ISBN, title or author
4. Results are aggregated from all servers
5. Click **Import** on desired record
6. Edit metadata if necessary
7. Save to your catalog

**Advantage:** Professional copy cataloging from authoritative sources.

---

### 6. Can I add custom SRU servers?

Yes, in the plugin settings:

1. Go to **Administration → Plugins → Z39.50/SRU → Settings**
2. "External Servers" section
3. Add a new server:
   - Name: "XYZ University Library"
   - URL: `https://opac.xyz.edu/sru`
   - Database: (optional, depends on server)

**Preconfigured servers:**
- OPAC SBN (Italy)
- Library of Congress (USA)
- British Library (UK)

---

### 7. How do I limit SRU search results?

Use the `maximumRecords` and `startRecord` parameters:

```
/api/sru?operation=searchRetrieve
  &query=dc.creator=eco
  &maximumRecords=5
  &startRecord=1
```

**Parameters:**
| Parameter | Default | Description |
|-----------|---------|-------------|
| `maximumRecords` | 10 | Max number of results |
| `startRecord` | 1 | Offset (for pagination) |

**Pagination:**
- First page: `startRecord=1&maximumRecords=10`
- Second page: `startRecord=11&maximumRecords=10`

---

### 8. How do I get information about SRU server capabilities?

Use the `explain` operation:

```
GET /api/sru?operation=explain
```

**XML response includes:**
- Database name and description
- Supported indexes (dc.title, dc.creator, etc.)
- Available response formats
- Query limits
- Contact information

---

### 9. What are the differences between SRU and Z39.50?

| Aspect | Z39.50 | SRU |
|--------|--------|-----|
| **Protocol** | Binary, dedicated port | HTTP/HTTPS |
| **Query format** | PQF (complex) | CQL (simple) |
| **Response** | Binary MARC | XML |
| **Firewall** | Problematic (port 210) | No problem (80/443) |
| **Debug** | Difficult | Easy (URL in browser) |

**In Pinakes:** The plugin implements SRU 1.2, which is the modern successor to Z39.50. There is no native Z39.50 server.

---

### 10. How do I test if my SRU server is working?

**Basic test:**
Open in browser:
```
https://yourlibrary.com/api/sru?operation=explain
```

You should see an XML with server information.

**Search test:**
```
https://yourlibrary.com/api/sru?operation=searchRetrieve&query=dc.title=test
```

**Debug:**
If it doesn't work:
1. Verify the plugin is active
2. Check logs: `storage/logs/app.log`
3. Make sure `/api/sru` isn't blocked by `.htaccess`
