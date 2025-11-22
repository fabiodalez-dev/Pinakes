# 🔌 API Documentation - Pinakes

> **Technical Documentation for Developers**
> Complete reference for all REST API endpoints available in Pinakes.

---

## 📋 Sommario

- [Overview](#overview)
- [Authentication & Security](#authentication--security)
- [Response Format](#response-format)
- [Libri API](#libri-api)
- [Prestiti API](#prestiti-api)
- [Utenti API](#utenti-api)
- [Autori API](#autori-api)
- [Generi API](#generi-api)
- [Editori API](#editori-api)
- [Dewey API](#dewey-api)
- [Error Handling](#error-handling)
- [Rate Limiting](#rate-limiting)

---

## Overview

### Base URL
```
http://localhost:8000/api
```

### Technology Stack
- **Framework**: Slim 4 (PSR-7/PSR-15)
- **Database**: MySQL 8.0+ with InnoDB
- **Content-Type**: `application/json`
- **Charset**: UTF-8
- **Response Format**: JSON with DataTables compatibility

### Authentication
All API endpoints are **session-based authenticated**. Requests must include valid session cookies.

### Protocol
- **HTTP Methods**: GET, POST (with DataTables integration)
- **Content Encoding**: gzip, deflate supported
- **Timeouts**: 30 seconds per request (configurable)

---

## Authentication & Security

### Session Management
```
Session-based authentication via browser cookies
CSRF protection on all POST requests
User role verification per endpoint
```

### Headers Required
```
Content-Type: application/json
Accept: application/json
```

### CSRF Protection
```
POST requests require CSRF token in:
- Request body: csrf_token parameter
- OR HTTP header: X-CSRF-Token
```

### Input Validation
- All parameters type-cast and validated
- Prepared statements prevent SQL injection
- XSS prevention via HTML escaping
- Rate limiting per IP address

---

## Response Format

### Success Response (DataTables Format)
```json
{
  "draw": 1,
  "recordsTotal": 150,
  "recordsFiltered": 25,
  "data": [
    {
      "id": 123,
      "campo1": "valore1",
      "campo2": "valore2",
      "actions": "<a href=\"...\">Dettagli</a> | <a href=\"...\">Modifica</a>"
    }
  ]
}
```

### Fields
| Field | Type | Description |
|-------|------|-------------|
| `draw` | int | Echo back from request (for DataTables sync) |
| `recordsTotal` | int | Total records in database |
| `recordsFiltered` | int | Records after filtering |
| `data` | array | Array of result objects |

### Error Response
```json
{
  "error": "Detailed error message",
  "code": "ERROR_CODE",
  "timestamp": "2025-10-19T12:34:56Z"
}
```

---

## Libri API

### Endpoint: `GET /api/libri`

**Description**: List all books with advanced filtering and pagination

**Authentication**: Required (session)

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `draw` | int | 1 | DataTables draw counter |
| `start` | int | 0 | Pagination offset |
| `length` | int | 10 | Records per page |
| `search_text` | string | "" | Search by title, subtitle, or author |
| `search_isbn` | string | "" | Search by ISBN-10 or ISBN-13 |
| `categoria_filter` | int | 0 | Filter by category ID |
| `genere_filter` | int | 0 | Filter by genre ID |
| `sottogenere_filter` | int | 0 | Filter by subgenre ID |
| `editore_filter` | int | 0 | Filter by publisher ID |
| `autore_id` | int | 0 | Filter by author ID |
| `stato_filter` | string | "" | Filter by status (disponibile, prestato, etc) |
| `collocazione_id` | int | 0 | Filter by shelf position ID |
| `acq_from` | date | "" | Acquisition date range start (YYYY-MM-DD) |
| `acq_to` | date | "" | Acquisition date range end |
| `pub_from` | date | "" | Publication date start |
| `pub_to` | date | "" | Publication date end |
| `anno_from` | int | "" | Publication year start |
| `anno_to` | int | "" | Publication year end |

**Example Request**:
```bash
curl "http://localhost:8000/api/libri?draw=1&start=0&length=20&search_text=Dante&genere_filter=5"
```

**Example Response**:
```json
{
  "draw": 1,
  "recordsTotal": 1542,
  "recordsFiltered": 12,
  "data": [
    {
      "id": 156,
      "titolo": "La Divina Commedia",
      "autore": "Dante Alighieri",
      "editore": "Mondadori",
      "isbn13": "9788842935780",
      "stato": "disponibile",
      "collocazione": "A.2.15",
      "data_acquisizione": "2024-10-15",
      "anno_pubblicazione": 2023,
      "prezzo": 25.99,
      "copie_totali": 3,
      "copie_disponibili": 2,
      "actions": "<a href=\"/admin/libri/156\">Dettagli</a> | <a href=\"/admin/libri/156/modifica\">Modifica</a>"
    }
  ]
}
```

**Status Codes**:
- `200 OK`: Success
- `400 Bad Request`: Invalid parameters
- `401 Unauthorized`: Not authenticated
- `500 Internal Server Error`: Database error

---

## Prestiti API

### Endpoint: `GET /api/prestiti`

**Description**: List all loans with advanced filtering

**Authentication**: Required (session)

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `draw` | int | 1 | DataTables draw counter |
| `start` | int | 0 | Pagination offset |
| `length` | int | 10 | Records per page |
| `utente_id` | int | 0 | Filter by user ID |
| `libro_id` | int | 0 | Filter by book ID |
| `attivo` | int | -1 | Filter by active status (0=returned, 1=active, -1=all) |
| `from` | date | "" | Loan date range start (YYYY-MM-DD) |
| `to` | date | "" | Loan date range end |
| `stato_specifico` | string | "" | Filter by specific status (richiesto, approvato, in_corso, in_ritardo, restituito) |
| `search_value` | string | "" | Search by book title, user name, or staff name |

**Example Request**:
```bash
curl "http://localhost:8000/api/prestiti?draw=1&start=0&length=10&attivo=1&stato_specifico=in_corso"
```

**Example Response**:
```json
{
  "draw": 1,
  "recordsTotal": 142,
  "recordsFiltered": 8,
  "data": [
    {
      "id": 45,
      "libro": "La Divina Commedia",
      "utente": "Mario Rossi",
      "data_prestito": "2025-10-10",
      "data_restituzione": null,
      "data_scadenza": "2025-10-24",
      "stato": "in_corso",
      "attivo": 1,
      "processed_by": "Admin System",
      "giorni_rimanenti": 5,
      "in_ritardo": false,
      "actions": "<a href=\"/admin/prestiti/45/dettagli\">Dettagli</a> | <a href=\"/admin/prestiti/45/restituito\">Restituito</a>"
    }
  ]
}
```

**Loan States**:
```
richiesto      → Request pending admin approval
approvato      → Request approved, awaiting pickup
in_corso       → Active loan, in user's possession
in_ritardo     → Past due date
restituito     → Returned and closed
annullato      → Cancelled
```

---

## Utenti API

### Endpoint: `GET /api/utenti`

**Description**: List all users with filtering

**Authentication**: Required (session, admin role)

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `draw` | int | 1 | DataTables draw counter |
| `start` | int | 0 | Pagination offset |
| `length` | int | 10 | Records per page |
| `search_text` | string | "" | Search by name, email, phone, or card code |
| `role_filter` | string | "" | Filter by user type (standard, admin, bibliotecario) |
| `status_filter` | string | "" | Filter by status (attivo, sospeso, bloccato) |
| `created_from` | date | "" | Account creation date start (YYYY-MM-DD) |

**Example Request**:
```bash
curl "http://localhost:8000/api/utenti?draw=1&start=0&length=20&role_filter=admin"
```

**Example Response**:
```json
{
  "draw": 1,
  "recordsTotal": 245,
  "recordsFiltered": 5,
  "data": [
    {
      "id": 12,
      "nome": "Mario",
      "cognome": "Rossi",
      "email": "mario.rossi@example.com",
      "telefono": "02-1234567",
      "tipo_utente": "standard",
      "stato": "attivo",
      "codice_tessera": "LIB-2024-00012",
      "data_scadenza_tessera": "2026-10-19",
      "created_at": "2024-10-19T10:30:00Z",
      "prestiti_attivi": 2,
      "prestiti_in_ritardo": 0,
      "actions": "<a href=\"/admin/utenti/12\">Dettagli</a> | <a href=\"/admin/utenti/12/modifica\">Modifica</a>"
    }
  ]
}
```

---

## Autori API

### Endpoint: `GET /api/autori`

**Description**: List all authors with filtering

**Authentication**: Required (session)

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `draw` | int | 1 | DataTables draw counter |
| `start` | int | 0 | Pagination offset |
| `length` | int | 10 | Records per page |
| `search_text` | string | "" | Search by name |
| `search_nazionalita` | string | "" | Filter by nationality |
| `nascita_from` | year | "" | Birth year start |
| `nascita_to` | year | "" | Birth year end |

**Example Response**:
```json
{
  "draw": 1,
  "recordsTotal": 1203,
  "recordsFiltered": 5,
  "data": [
    {
      "id": 156,
      "nome": "Dante",
      "cognome": "Alighieri",
      "nome_completo": "Dante Alighieri",
      "data_nascita": "1265-05-29",
      "data_morte": "1321-09-14",
      "nazionalita": "Italiana",
      "biografia": "Poeta fiorentino del Medioevo...",
      "libri_count": 45,
      "actions": "<a href=\"/admin/autori/156\">Dettagli</a>"
    }
  ]
}
```

---

## Generi API

### Endpoint: `GET /api/generi/search`

**Description**: Search genres/subgenres (autocomplete)

**Authentication**: Required (session)

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `q` | string | "" | Search query (min 1 char) |
| `limit` | int | 20 | Max results (max 50) |

**Example Request**:
```bash
curl "http://localhost:8000/api/generi/search?q=fan&limit=10"
```

**Example Response**:
```json
[
  {
    "id": 15,
    "label": "Fantasy",
    "nome": "Fantasy",
    "parent_id": null,
    "tipo": "genere"
  },
  {
    "id": 42,
    "label": "Urban Fantasy (Fantasy)",
    "nome": "Urban Fantasy",
    "parent_id": 15,
    "parent_nome": "Fantasy",
    "tipo": "sottogenere"
  }
]
```

---

## Editori API

### Endpoint: `GET /api/editori`

**Description**: List all publishers

**Authentication**: Required (session)

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `draw` | int | 1 | DataTables draw counter |
| `start` | int | 0 | Pagination offset |
| `length` | int | 10 | Records per page |
| `search_text` | string | "" | Search by publisher name |
| `search_sito` | string | "" | Search by website |
| `created_from` | date | "" | Creation date start |

**Example Response**:
```json
{
  "draw": 1,
  "recordsTotal": 342,
  "recordsFiltered": 3,
  "data": [
    {
      "id": 1,
      "nome": "Mondadori",
      "sito_web": "https://www.mondadori.it",
      "email": "info@mondadori.it",
      "telefono": "02-74417101",
      "indirizzo": "Via Mondadori 1, Milano",
      "libri_count": 156,
      "created_at": "2024-10-15T09:00:00Z",
      "actions": "<a href=\"/admin/editori/1\">Dettagli</a>"
    }
  ]
}
```

---

## Dewey API

### Endpoint: `GET /api/dewey/categories`

**Description**: Get Dewey Decimal Classification main categories

**Authentication**: Required (session)

**Example Response**:
```json
[
  {
    "codice": "000",
    "nome": "Generalità - Informatica",
    "descrizione": "Informatica, generalità"
  },
  {
    "codice": "100",
    "nome": "Filosofia",
    "descrizione": "Filosofia e discipline correlate"
  },
  {
    "codice": "200",
    "nome": "Religione",
    "descrizione": "Religione, bibbia, teologia"
  }
]
```

### Endpoint: `GET /api/dewey/divisions/{category}`

**Description**: Get divisions within a Dewey category

**Example**:
```bash
curl "http://localhost:8000/api/dewey/divisions/000"
```

---

## Error Handling

### HTTP Status Codes

| Code | Meaning | Example |
|------|---------|---------|
| `200` | OK - Request successful | Data returned |
| `400` | Bad Request - Invalid params | Missing required field |
| `401` | Unauthorized - Not authenticated | Session expired |
| `403` | Forbidden - No permission | Admin-only endpoint |
| `404` | Not Found - Resource doesn't exist | Invalid ID |
| `500` | Server Error | Database connection failed |
| `503` | Service Unavailable | Maintenance mode |

### Error Response Format
```json
{
  "error": "Database prepare failed",
  "code": "DB_PREPARE_ERROR",
  "timestamp": "2025-10-19T12:34:56Z",
  "details": {
    "query": "SELECT ...",
    "error_info": "Syntax error in query"
  }
}
```

### Common Errors

#### Database Connection Failure
```json
{
  "error": "Database prepare failed",
  "code": "DB_ERROR"
}
```
**Solution**: Check MySQL server is running and credentials are valid.

#### Invalid Parameter Type
```json
{
  "error": "Invalid parameter type",
  "code": "INVALID_PARAM",
  "parameter": "start",
  "expected": "integer"
}
```
**Solution**: Ensure parameters match expected types.

#### Missing CSRF Token
```json
{
  "error": "CSRF token missing or invalid",
  "code": "CSRF_ERROR"
}
```
**Solution**: Include valid CSRF token in request body or header.

---

## Rate Limiting

### Limits per User Session
```
- List endpoints (libri, prestiti, utenti): 100 requests/minute
- Search endpoints (generi, autori): 200 requests/minute
- Special endpoints (dewey): 50 requests/minute
```

### Rate Limit Headers
```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 87
X-RateLimit-Reset: 1698156896
```

### 429 Too Many Requests
```json
{
  "error": "Rate limit exceeded",
  "code": "RATE_LIMIT",
  "retry_after": 60
}
```

---

## Query Optimization Tips

### DataTables Parameters
For optimal performance with DataTables:

```javascript
// Send correct parameters
{
  draw: 1,           // Should increment
  start: 0,          // Pagination offset
  length: 25,        // Records per page (recommended: 10-50)
  search_text: "...",// Search term
  // ... other filters
}
```

### Indexing
Ensure database indexes on:
- `libri.titolo`
- `libri.isbn10`, `libri.isbn13`
- `prestiti.utente_id`, `prestiti.libro_id`
- `utenti.email`
- `autori.nome`, `autori.cognome`

### Batch Operations
For bulk operations, make sequential requests:
```bash
# Instead of single large request
for i in {1..10}; do
  curl "http://localhost:8000/api/libri?start=$((i*100))&length=100"
done
```

---

## Security Best Practices

1. **Always use HTTPS** in production
2. **Validate all input** on client and server
3. **Use parameterized queries** (already done in API)
4. **Implement rate limiting** per IP
5. **Add request logging** for audit trail
6. **Use API keys** for third-party integrations
7. **Enable CORS** only for trusted domains
8. **Encrypt sensitive data** in transit and at rest

---

## Integration Examples

### JavaScript (Fetch API)
```javascript
const response = await fetch('/api/libri', {
  method: 'GET',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json'
  },
  credentials: 'include' // Include session cookies
});

const data = await response.json();
console.log(data.data); // Array of books
```

### Python (Requests)
```python
import requests

session = requests.Session()
response = session.get('http://localhost:8000/api/libri', params={
    'draw': 1,
    'start': 0,
    'length': 20,
    'search_text': 'Dante'
})

data = response.json()
print(f"Total: {data['recordsTotal']}")
print(f"Filtered: {data['recordsFiltered']}")
for book in data['data']:
    print(f"- {book['titolo']}")
```

### cURL
```bash
curl -b cookies.txt \
  "http://localhost:8000/api/libri" \
  -H "Accept: application/json" \
  -d "draw=1&start=0&length=10"
```

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0.0 | 2025-10-19 | Initial API documentation |

---

## Support

For API issues or questions:
1. Check this documentation
2. Review application logs in `storage/logs/`
3. Verify database connectivity
4. Check CSRF token validity
5. Contact development team

---

*Last Updated: 19 Ottobre 2025*
*API Version: 1.0.0*
*Documentation Version: 1.0.0*
