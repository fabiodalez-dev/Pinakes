# Guida Completa: Implementazione Server API per Book Scraper

Questa guida fornisce tutte le informazioni necessarie per implementare un server API che risponde alle chiamate del plugin **API Book Scraper** di Pinakes.

---

## Indice

1. [Panoramica](#panoramica)
2. [Specifiche API](#specifiche-api)
3. [Autenticazione](#autenticazione)
4. [Formato Richiesta](#formato-richiesta)
5. [Formato Risposta](#formato-risposta)
6. [Codici di Stato HTTP](#codici-di-stato-http)
7. [Esempi di Implementazione](#esempi-di-implementazione)
   - [PHP (Laravel)](#esempio-php-laravel)
   - [Node.js (Express)](#esempio-nodejs-express)
   - [Python (FastAPI)](#esempio-python-fastapi)
   - [Go (Fiber)](#esempio-go-fiber)
8. [Database Schema](#database-schema)
9. [Fonti Dati Esterne](#fonti-dati-esterne)
10. [Best Practices](#best-practices)
11. [Security](#security)
12. [Testing](#testing)
13. [Deployment](#deployment)

---

## Panoramica

Il plugin **API Book Scraper** è un client HTTP che interroga un server API personalizzato per recuperare i dati bibliografici di un libro tramite ISBN/EAN.

### Caratteristiche Principali

- ✅ Autenticazione tramite API Key (header `X-API-Key`)
- ✅ Supporto ISBN-10 e ISBN-13
- ✅ Risposta in formato JSON
- ✅ Timeout configurabile (5-60 secondi)
- ✅ Priorità alta (3) - interrogato prima di Open Library

### Flusso di Comunicazione

```
┌─────────────┐     HTTP GET      ┌─────────────┐     Query DB     ┌──────────┐
│   Pinakes   │ ───────────────>  │ Your API    │ ──────────────>  │ Database │
│   Plugin    │  X-API-Key: xxx   │   Server    │                  │          │
└─────────────┘                   └─────────────┘                  └──────────┘
       ↑                                  │                              │
       │          JSON Response           │         Book Data            │
       └──────────────────────────────────┴──────────────────────────────┘
```

---

## Specifiche API

### Endpoint

**Base URL**: Configurabile (es. `https://api.yourdomain.com`)

**Risorsa**: `/books/{isbn}` oppure `/books/search?isbn={isbn}`

**Metodo HTTP**: `GET`

**Content-Type**: `application/json`

### Parametri

| Parametro | Tipo   | Obbligatorio | Posizione      | Descrizione              |
|-----------|--------|--------------|----------------|--------------------------|
| `isbn`    | string | Sì           | Path o Query   | ISBN-10 o ISBN-13        |

---

## Autenticazione

### Header Richiesto

```http
X-API-Key: your-secret-api-key-here
```

### Generazione API Key

Consigliato formato:
- Prefisso identificativo: `sk_live_` (production) o `sk_test_` (development)
- Lunghezza minima: 32 caratteri
- Caratteri: alfanumerici + simboli
- Esempio: `sk_live_9f8e7d6c5b4a3210fedcba9876543210`

### Validazione Lato Server

```php
// PHP Example
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;

if (!$apiKey || !validateApiKey($apiKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}
```

---

## Formato Richiesta

### Esempio GET Request

```http
GET /books/9788804668619 HTTP/1.1
Host: api.yourdomain.com
X-API-Key: sk_live_9f8e7d6c5b4a3210fedcba9876543210
Accept: application/json
User-Agent: Pinakes-API-Scraper/1.0
```

### Alternative URL Patterns

Il plugin supporta diversi pattern:

1. **Path Parameter con placeholder**: `https://api.yourdomain.com/books/{isbn}`
   - Il plugin sostituisce `{isbn}` con il valore effettivo

2. **Query Parameter**: `https://api.yourdomain.com/books/search`
   - Il plugin aggiunge automaticamente `?isbn={isbn}`

---

## Formato Risposta

### Struttura JSON Completa

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
    "isbn10": "8845292614",
    "ean": "9788845292613",
    "pages": 503,
    "language": "it",
    "description": "In un'abbazia benedettina nel 1327...",
    "cover_url": "https://cdn.example.com/covers/9788845292613.jpg",
    "series": "Narratori della Fenice",
    "series_number": "1",
    "format": "Brossura",
    "price": "14.00",
    "currency": "EUR",
    "weight": 450,
    "dimensions": "21 x 13 x 3 cm",
    "genres": ["Romanzo storico", "Giallo"],
    "subjects": ["Medioevo", "Monasteri", "Investigazione"],
    "edition": "Prima edizione",
    "binding": "Paperback"
  },
  "metadata": {
    "source": "internal_db",
    "cached": false,
    "timestamp": "2025-01-15T10:30:00Z"
  }
}
```

### Campi Mapping

| Campo API          | Campo Pinakes         | Tipo    | Obbligatorio | Note                              |
|--------------------|-----------------------|---------|--------------|-----------------------------------|
| `title`            | `titolo`              | string  | ✅           | Titolo principale                 |
| `subtitle`         | `sottotitolo`         | string  | ❌           | Sottotitolo                       |
| `authors`          | `autori`              | array   | ✅           | Lista autori (stringhe o oggetti) |
| `publisher`        | `editore`             | string  | ❌           | Casa editrice                     |
| `publish_date`     | `data_pubblicazione`  | string  | ❌           | Formato: YYYY-MM-DD               |
| `isbn13`           | `isbn13`              | string  | ❌           | ISBN a 13 cifre                   |
| `isbn10`           | `isbn10`              | string  | ❌           | ISBN a 10 cifre                   |
| `ean`              | `ean`                 | string  | ❌           | Codice EAN                        |
| `pages`            | `numero_pagine`       | integer | ❌           | Numero pagine                     |
| `language`         | `lingua`              | string  | ❌           | Codice ISO 639-1 (it, en, fr...)  |
| `description`      | `descrizione`         | string  | ❌           | Trama/descrizione                 |
| `cover_url`        | `copertina_url`       | string  | ❌           | URL immagine copertina            |
| `series`           | `collana`             | string  | ❌           | Nome collana/serie                |
| `format`           | `formato`             | string  | ❌           | Brossura, Cartonato, eBook...     |
| `price`            | `prezzo`              | string  | ❌           | Prezzo di copertina               |
| `weight`           | `peso`                | integer | ❌           | Peso in grammi                    |
| `dimensions`       | `dimensioni`          | string  | ❌           | Dimensioni fisiche                |
| `genres`           | `generi`              | array   | ❌           | Generi letterari                  |
| `subjects`         | `argomenti`           | array   | ❌           | Argomenti/temi                    |

### Formato Autori

Gli autori possono essere forniti in 3 formati:

**1. Array di stringhe (semplice)**
```json
"authors": ["Umberto Eco", "Giovanni Boccaccio"]
```

**2. Array di oggetti (dettagliato)**
```json
"authors": [
  {
    "name": "Umberto Eco",
    "role": "author",
    "bio": "Scrittore e filosofo italiano..."
  }
]
```

**3. Stringa singola**
```json
"author": "Umberto Eco"
```

### Risposta Errore

```json
{
  "success": false,
  "error": {
    "code": "NOT_FOUND",
    "message": "Nessun libro trovato con ISBN 9788804668619"
  }
}
```

---

## Codici di Stato HTTP

| Codice | Significato           | Descrizione                                    |
|--------|-----------------------|------------------------------------------------|
| 200    | OK                    | Libro trovato, dati restituiti                 |
| 400    | Bad Request           | ISBN formato non valido                        |
| 401    | Unauthorized          | API key mancante o non valida                  |
| 404    | Not Found             | ISBN non trovato nel database                  |
| 429    | Too Many Requests     | Rate limit superato                            |
| 500    | Internal Server Error | Errore interno del server                      |
| 503    | Service Unavailable   | Servizio temporaneamente non disponibile       |

---

## Esempi di Implementazione

### Esempio PHP (Laravel)

#### 1. Route Definition

```php
// routes/api.php
Route::middleware('api.key')->get('/books/{isbn}', [BookController::class, 'getByIsbn']);
```

#### 2. Middleware Autenticazione

```php
// app/Http/Middleware/ApiKeyAuth.php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiKeyAuth
{
    public function handle(Request $request, Closure $next)
    {
        $apiKey = $request->header('X-API-Key');

        if (!$apiKey || !$this->validateApiKey($apiKey)) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Invalid API key']
            ], 401);
        }

        return $next($request);
    }

    private function validateApiKey(string $key): bool
    {
        return \App\Models\ApiKey::where('key', $key)
            ->where('is_active', true)
            ->exists();
    }
}
```

#### 3. Controller

```php
// app/Http/Controllers/Api/BookController.php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use Illuminate\Http\JsonResponse;

class BookController extends Controller
{
    public function getByIsbn(string $isbn): JsonResponse
    {
        // Normalizza ISBN (rimuovi trattini/spazi)
        $cleanIsbn = preg_replace('/[^0-9X]/i', '', $isbn);

        // Cerca nel database
        $book = Book::where('isbn13', $cleanIsbn)
            ->orWhere('isbn10', $cleanIsbn)
            ->orWhere('ean', $cleanIsbn)
            ->with(['authors', 'publisher', 'genres'])
            ->first();

        if (!$book) {
            // Tentativo di recupero da API esterna (Google Books, Open Library...)
            $book = $this->fetchFromExternalApi($cleanIsbn);

            if (!$book) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'NOT_FOUND',
                        'message' => "No book found with ISBN: $isbn"
                    ]
                ], 404);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatBookData($book),
            'metadata' => [
                'source' => 'database',
                'cached' => true,
                'timestamp' => now()->toIso8601String()
            ]
        ]);
    }

    private function formatBookData(Book $book): array
    {
        return [
            'title' => $book->title,
            'subtitle' => $book->subtitle,
            'authors' => $book->authors->pluck('name')->toArray(),
            'publisher' => $book->publisher->name ?? null,
            'publish_date' => $book->publish_date?->format('Y-m-d'),
            'isbn13' => $book->isbn13,
            'isbn10' => $book->isbn10,
            'ean' => $book->ean,
            'pages' => $book->pages,
            'language' => $book->language,
            'description' => $book->description,
            'cover_url' => $book->cover_url,
            'series' => $book->series,
            'format' => $book->format,
            'price' => $book->price,
            'weight' => $book->weight,
            'dimensions' => $book->dimensions,
            'genres' => $book->genres->pluck('name')->toArray(),
        ];
    }

    private function fetchFromExternalApi(string $isbn): ?Book
    {
        // Implementa logica per Google Books API, Open Library, ecc.
        // ...
        return null;
    }
}
```

#### 4. Model

```php
// app/Models/Book.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Book extends Model
{
    protected $fillable = [
        'title', 'subtitle', 'isbn13', 'isbn10', 'ean',
        'pages', 'language', 'description', 'cover_url',
        'series', 'format', 'price', 'weight', 'dimensions',
        'publish_date', 'publisher_id'
    ];

    protected $casts = [
        'publish_date' => 'date',
        'pages' => 'integer',
        'weight' => 'integer',
    ];

    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class);
    }
}
```

---

### Esempio Node.js (Express)

```javascript
// server.js
const express = require('express');
const app = express();

// Middleware autenticazione
const authenticateApiKey = (req, res, next) => {
  const apiKey = req.headers['x-api-key'];

  if (!apiKey || !isValidApiKey(apiKey)) {
    return res.status(401).json({
      success: false,
      error: { code: 'UNAUTHORIZED', message: 'Invalid API key' }
    });
  }

  next();
};

// Route
app.get('/books/:isbn', authenticateApiKey, async (req, res) => {
  const { isbn } = req.params;
  const cleanIsbn = isbn.replace(/[^0-9X]/gi, '');

  try {
    // Query database
    const book = await db.query(
      'SELECT * FROM books WHERE isbn13 = $1 OR isbn10 = $1 OR ean = $1',
      [cleanIsbn]
    );

    if (book.rows.length === 0) {
      return res.status(404).json({
        success: false,
        error: { code: 'NOT_FOUND', message: `No book found with ISBN: ${isbn}` }
      });
    }

    const bookData = book.rows[0];

    // Fetch related data
    const authors = await db.query(
      'SELECT a.name FROM authors a JOIN book_authors ba ON a.id = ba.author_id WHERE ba.book_id = $1',
      [bookData.id]
    );

    res.json({
      success: true,
      data: {
        title: bookData.title,
        subtitle: bookData.subtitle,
        authors: authors.rows.map(a => a.name),
        publisher: bookData.publisher,
        publish_date: bookData.publish_date,
        isbn13: bookData.isbn13,
        isbn10: bookData.isbn10,
        ean: bookData.ean,
        pages: bookData.pages,
        language: bookData.language,
        description: bookData.description,
        cover_url: bookData.cover_url,
        series: bookData.series,
        format: bookData.format,
        price: bookData.price
      },
      metadata: {
        source: 'database',
        timestamp: new Date().toISOString()
      }
    });

  } catch (error) {
    console.error('Database error:', error);
    res.status(500).json({
      success: false,
      error: { code: 'SERVER_ERROR', message: 'Internal server error' }
    });
  }
});

function isValidApiKey(key) {
  // Implementa validazione API key (query database, cache...)
  return key === process.env.VALID_API_KEY;
}

app.listen(3000, () => console.log('API Server running on port 3000'));
```

---

### Esempio Python (FastAPI)

```python
# main.py
from fastapi import FastAPI, Header, HTTPException, Depends
from pydantic import BaseModel
from typing import List, Optional
import databases
import re

app = FastAPI()

# Database setup
DATABASE_URL = "postgresql://user:password@localhost/books"
database = databases.Database(DATABASE_URL)

# Models
class BookResponse(BaseModel):
    success: bool
    data: dict
    metadata: dict

class ErrorResponse(BaseModel):
    success: bool
    error: dict

# Dependency: API Key validation
async def validate_api_key(x_api_key: str = Header(...)):
    # Query database per validare API key
    query = "SELECT id FROM api_keys WHERE key = :key AND is_active = true"
    result = await database.fetch_one(query, values={"key": x_api_key})

    if not result:
        raise HTTPException(
            status_code=401,
            detail={"success": False, "error": {"code": "UNAUTHORIZED", "message": "Invalid API key"}}
        )

    return True

# Endpoint
@app.get("/books/{isbn}", response_model=BookResponse, responses={404: {"model": ErrorResponse}})
async def get_book_by_isbn(isbn: str, authorized: bool = Depends(validate_api_key)):
    # Normalizza ISBN
    clean_isbn = re.sub(r'[^0-9X]', '', isbn.upper())

    # Query database
    query = """
        SELECT b.*, p.name as publisher_name
        FROM books b
        LEFT JOIN publishers p ON b.publisher_id = p.id
        WHERE b.isbn13 = :isbn OR b.isbn10 = :isbn OR b.ean = :isbn
    """

    book = await database.fetch_one(query, values={"isbn": clean_isbn})

    if not book:
        raise HTTPException(
            status_code=404,
            detail={"success": False, "error": {"code": "NOT_FOUND", "message": f"No book found with ISBN: {isbn}"}}
        )

    # Fetch authors
    authors_query = """
        SELECT a.name
        FROM authors a
        JOIN book_authors ba ON a.id = ba.author_id
        WHERE ba.book_id = :book_id
    """
    authors = await database.fetch_all(authors_query, values={"book_id": book['id']})

    return {
        "success": True,
        "data": {
            "title": book['title'],
            "subtitle": book['subtitle'],
            "authors": [author['name'] for author in authors],
            "publisher": book['publisher_name'],
            "publish_date": str(book['publish_date']) if book['publish_date'] else None,
            "isbn13": book['isbn13'],
            "isbn10": book['isbn10'],
            "ean": book['ean'],
            "pages": book['pages'],
            "language": book['language'],
            "description": book['description'],
            "cover_url": book['cover_url'],
            "series": book['series'],
            "format": book['format'],
            "price": str(book['price']) if book['price'] else None
        },
        "metadata": {
            "source": "database",
            "timestamp": datetime.utcnow().isoformat() + "Z"
        }
    }

@app.on_event("startup")
async def startup():
    await database.connect()

@app.on_event("shutdown")
async def shutdown():
    await database.disconnect()
```

---

### Esempio Go (Fiber)

```go
// main.go
package main

import (
    "database/sql"
    "github.com/gofiber/fiber/v2"
    _ "github.com/lib/pq"
    "regexp"
    "time"
)

type Book struct {
    ID          int       `json:"-"`
    Title       string    `json:"title"`
    Subtitle    *string   `json:"subtitle,omitempty"`
    ISBN13      string    `json:"isbn13"`
    ISBN10      *string   `json:"isbn10,omitempty"`
    EAN         string    `json:"ean"`
    Pages       *int      `json:"pages,omitempty"`
    Language    string    `json:"language"`
    Description *string   `json:"description,omitempty"`
    CoverURL    *string   `json:"cover_url,omitempty"`
    PublishDate *string   `json:"publish_date,omitempty"`
    Publisher   *string   `json:"publisher,omitempty"`
}

type BookResponse struct {
    Success  bool                   `json:"success"`
    Data     map[string]interface{} `json:"data"`
    Metadata map[string]interface{} `json:"metadata"`
}

// Middleware per autenticazione API key
func apiKeyAuth(db *sql.DB) fiber.Handler {
    return func(c *fiber.Ctx) error {
        apiKey := c.Get("X-API-Key")

        if apiKey == "" {
            return c.Status(401).JSON(fiber.Map{
                "success": false,
                "error": fiber.Map{
                    "code":    "UNAUTHORIZED",
                    "message": "API key required",
                },
            })
        }

        // Valida API key
        var exists bool
        err := db.QueryRow("SELECT EXISTS(SELECT 1 FROM api_keys WHERE key = $1 AND is_active = true)", apiKey).Scan(&exists)

        if err != nil || !exists {
            return c.Status(401).JSON(fiber.Map{
                "success": false,
                "error": fiber.Map{
                    "code":    "UNAUTHORIZED",
                    "message": "Invalid API key",
                },
            })
        }

        return c.Next()
    }
}

func main() {
    // Database connection
    db, err := sql.Open("postgres", "postgresql://user:password@localhost/books?sslmode=disable")
    if err != nil {
        panic(err)
    }
    defer db.Close()

    app := fiber.New()

    // Routes
    app.Get("/books/:isbn", apiKeyAuth(db), func(c *fiber.Ctx) error {
        isbn := c.Params("isbn")

        // Normalizza ISBN
        re := regexp.MustCompile(`[^0-9X]`)
        cleanISBN := re.ReplaceAllString(isbn, "")

        // Query database
        var book Book
        var authors []string

        err := db.QueryRow(`
            SELECT b.id, b.title, b.subtitle, b.isbn13, b.isbn10, b.ean,
                   b.pages, b.language, b.description, b.cover_url,
                   TO_CHAR(b.publish_date, 'YYYY-MM-DD') as publish_date,
                   p.name as publisher
            FROM books b
            LEFT JOIN publishers p ON b.publisher_id = p.id
            WHERE b.isbn13 = $1 OR b.isbn10 = $1 OR b.ean = $1
        `, cleanISBN).Scan(
            &book.ID, &book.Title, &book.Subtitle, &book.ISBN13, &book.ISBN10,
            &book.EAN, &book.Pages, &book.Language, &book.Description,
            &book.CoverURL, &book.PublishDate, &book.Publisher,
        )

        if err == sql.ErrNoRows {
            return c.Status(404).JSON(fiber.Map{
                "success": false,
                "error": fiber.Map{
                    "code":    "NOT_FOUND",
                    "message": "No book found with ISBN: " + isbn,
                },
            })
        }

        if err != nil {
            return c.Status(500).JSON(fiber.Map{
                "success": false,
                "error": fiber.Map{
                    "code":    "SERVER_ERROR",
                    "message": "Internal server error",
                },
            })
        }

        // Fetch authors
        rows, _ := db.Query(`
            SELECT a.name
            FROM authors a
            JOIN book_authors ba ON a.id = ba.author_id
            WHERE ba.book_id = $1
        `, book.ID)
        defer rows.Close()

        for rows.Next() {
            var name string
            rows.Scan(&name)
            authors = append(authors, name)
        }

        return c.JSON(BookResponse{
            Success: true,
            Data: map[string]interface{}{
                "title":        book.Title,
                "subtitle":     book.Subtitle,
                "authors":      authors,
                "publisher":    book.Publisher,
                "publish_date": book.PublishDate,
                "isbn13":       book.ISBN13,
                "isbn10":       book.ISBN10,
                "ean":          book.EAN,
                "pages":        book.Pages,
                "language":     book.Language,
                "description":  book.Description,
                "cover_url":    book.CoverURL,
            },
            Metadata: map[string]interface{}{
                "source":    "database",
                "timestamp": time.Now().Format(time.RFC3339),
            },
        })
    })

    app.Listen(":3000")
}
```

---

## Database Schema

### Schema SQL Consigliato

```sql
-- Tabella Books
CREATE TABLE books (
    id SERIAL PRIMARY KEY,
    title VARCHAR(500) NOT NULL,
    subtitle VARCHAR(500),
    isbn13 VARCHAR(13) UNIQUE,
    isbn10 VARCHAR(10),
    ean VARCHAR(13),
    pages INTEGER,
    language VARCHAR(10) DEFAULT 'it',
    description TEXT,
    cover_url VARCHAR(1000),
    series VARCHAR(255),
    format VARCHAR(100),
    price DECIMAL(10, 2),
    currency VARCHAR(3) DEFAULT 'EUR',
    weight INTEGER, -- grammi
    dimensions VARCHAR(100),
    publish_date DATE,
    publisher_id INTEGER REFERENCES publishers(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_books_isbn13 ON books(isbn13);
CREATE INDEX idx_books_isbn10 ON books(isbn10);
CREATE INDEX idx_books_ean ON books(ean);
CREATE INDEX idx_books_title ON books(title);

-- Tabella Authors
CREATE TABLE authors (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    bio TEXT,
    birth_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabella Book-Authors (Many-to-Many)
CREATE TABLE book_authors (
    book_id INTEGER REFERENCES books(id) ON DELETE CASCADE,
    author_id INTEGER REFERENCES authors(id) ON DELETE CASCADE,
    author_order INTEGER DEFAULT 0,
    role VARCHAR(50) DEFAULT 'author', -- author, editor, translator
    PRIMARY KEY (book_id, author_id)
);

-- Tabella Publishers
CREATE TABLE publishers (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    country VARCHAR(2),
    website VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabella Genres
CREATE TABLE genres (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    parent_id INTEGER REFERENCES genres(id)
);

-- Tabella Book-Genres (Many-to-Many)
CREATE TABLE book_genres (
    book_id INTEGER REFERENCES books(id) ON DELETE CASCADE,
    genre_id INTEGER REFERENCES genres(id) ON DELETE CASCADE,
    PRIMARY KEY (book_id, genre_id)
);

-- Tabella API Keys
CREATE TABLE api_keys (
    id SERIAL PRIMARY KEY,
    key VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT true,
    rate_limit INTEGER DEFAULT 1000, -- richieste/ora
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP
);

CREATE INDEX idx_api_keys_key ON api_keys(key) WHERE is_active = true;
```

---

## Fonti Dati Esterne

### Google Books API

```php
function fetchFromGoogleBooks(string $isbn): ?array
{
    $url = "https://www.googleapis.com/books/v1/volumes?q=isbn:$isbn";
    $response = file_get_contents($url);
    $data = json_decode($response, true);

    if ($data['totalItems'] === 0) {
        return null;
    }

    $item = $data['items'][0]['volumeInfo'];

    return [
        'title' => $item['title'] ?? null,
        'subtitle' => $item['subtitle'] ?? null,
        'authors' => $item['authors'] ?? [],
        'publisher' => $item['publisher'] ?? null,
        'publish_date' => $item['publishedDate'] ?? null,
        'pages' => $item['pageCount'] ?? null,
        'language' => $item['language'] ?? null,
        'description' => $item['description'] ?? null,
        'cover_url' => $item['imageLinks']['thumbnail'] ?? null,
        'isbn13' => $isbn,
    ];
}
```

### Open Library API

```javascript
async function fetchFromOpenLibrary(isbn) {
  const response = await fetch(`https://openlibrary.org/isbn/${isbn}.json`);

  if (response.status === 404) {
    return null;
  }

  const data = await response.json();

  return {
    title: data.title,
    subtitle: data.subtitle,
    authors: data.authors?.map(a => a.name) || [],
    publisher: data.publishers?.[0],
    publish_date: data.publish_date,
    pages: data.number_of_pages,
    cover_url: `https://covers.openlibrary.org/b/isbn/${isbn}-L.jpg`,
    isbn13: isbn
  };
}
```

---

## Best Practices

### 1. Caching

Implementa caching per ridurre carico database:

```php
use Illuminate\Support\Facades\Cache;

$book = Cache::remember("book:isbn:$isbn", 3600, function () use ($isbn) {
    return Book::where('isbn13', $isbn)->first();
});
```

### 2. Rate Limiting

```php
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->header('X-API-Key'));
});
```

### 3. Logging

```php
Log::channel('api')->info('Book requested', [
    'isbn' => $isbn,
    'api_key' => substr($apiKey, 0, 10) . '...',
    'ip' => $request->ip()
]);
```

### 4. Compressione Risposta

```javascript
const compression = require('compression');
app.use(compression());
```

### 5. CORS (se necessario)

```javascript
const cors = require('cors');
app.use(cors({
  origin: 'https://your-pinakes-domain.com',
  allowedHeaders: ['X-API-Key', 'Content-Type']
}));
```

---

## Security

### Checklist Sicurezza

- ✅ **HTTPS obbligatorio** (TLS 1.2+)
- ✅ **API key criptate** nel database
- ✅ **Rate limiting** per prevenire abuse
- ✅ **Validazione input** (ISBN format)
- ✅ **SQL injection protection** (prepared statements)
- ✅ **Logs audit** completi
- ✅ **Firewall** (Cloudflare, AWS WAF)
- ✅ **Rotating API keys** ogni 90 giorni
- ✅ **IP whitelist** (opzionale)

### Esempio Rotazione API Keys

```php
// Genera nuova API key
$newKey = 'sk_live_' . bin2hex(random_bytes(24));

// Salva nel database
ApiKey::create([
    'key' => hash('sha256', $newKey),
    'name' => 'Auto-rotated key',
    'expires_at' => now()->addDays(90)
]);

// Notifica utente via email
Mail::to($user)->send(new ApiKeyRotatedMail($newKey));
```

---

## Testing

### Test Endpoint con cURL

```bash
# Test base
curl -X GET "https://api.yourdomain.com/books/9788804668619" \
  -H "X-API-Key: sk_live_your_key_here" \
  -H "Accept: application/json"

# Test con ISBN non esistente (deve restituire 404)
curl -X GET "https://api.yourdomain.com/books/0000000000000" \
  -H "X-API-Key: sk_live_your_key_here"

# Test senza API key (deve restituire 401)
curl -X GET "https://api.yourdomain.com/books/9788804668619"

# Test con API key non valida (deve restituire 401)
curl -X GET "https://api.yourdomain.com/books/9788804668619" \
  -H "X-API-Key: invalid_key"
```

### Unit Tests (PHPUnit - Laravel)

```php
public function test_can_get_book_by_valid_isbn()
{
    $response = $this->withHeaders([
        'X-API-Key' => 'test_api_key'
    ])->get('/api/books/9788804668619');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'title',
                'authors',
                'isbn13'
            ]
        ]);
}

public function test_returns_404_for_non_existent_isbn()
{
    $response = $this->withHeaders([
        'X-API-Key' => 'test_api_key'
    ])->get('/api/books/0000000000000');

    $response->assertStatus(404)
        ->assertJson(['success' => false]);
}
```

---

## Deployment

### Docker Example

```dockerfile
# Dockerfile
FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

COPY . /var/www/html
WORKDIR /var/www/html

CMD ["php-fpm"]
```

```yaml
# docker-compose.yml
version: '3.8'

services:
  api:
    build: .
    ports:
      - "8000:9000"
    environment:
      - DATABASE_URL=postgresql://user:pass@db:5432/books
      - APP_ENV=production
    depends_on:
      - db

  db:
    image: postgres:15
    environment:
      POSTGRES_DB: books
      POSTGRES_USER: user
      POSTGRES_PASSWORD: pass
    volumes:
      - pgdata:/var/lib/postgresql/data

volumes:
  pgdata:
```

### Nginx Configuration

```nginx
server {
    listen 80;
    server_name api.yourdomain.com;

    # Redirect HTTP to HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name api.yourdomain.com;

    ssl_certificate /etc/ssl/certs/api.yourdomain.com.crt;
    ssl_certificate_key /etc/ssl/private/api.yourdomain.com.key;

    location / {
        proxy_pass http://localhost:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # Rate limiting
    limit_req_zone $binary_remote_addr zone=api_limit:10m rate=10r/s;
    limit_req zone=api_limit burst=20 nodelay;
}
```

---

## Monitoring & Maintenance

### Metriche da Monitorare

1. **Latency** - Tempo risposta < 500ms
2. **Error Rate** - < 1% errori 5xx
3. **Availability** - Uptime > 99.9%
4. **Throughput** - Requests/sec
5. **Cache Hit Rate** - > 80%

### Tools Consigliati

- **APM**: New Relic, DataDog, AppSignal
- **Monitoring**: Prometheus + Grafana
- **Logging**: ELK Stack (Elasticsearch, Logstash, Kibana)
- **Uptime**: UptimeRobot, Pingdom

---

## Support & Resources

### API Testing Tools

- **Postman**: https://www.postman.com
- **Insomnia**: https://insomnia.rest
- **HTTPie**: https://httpie.io

### Librerie Utili

- **PHP**: Guzzle (HTTP client), Laravel Passport (OAuth)
- **Node.js**: Axios, Express-rate-limit
- **Python**: httpx, fastapi-limiter
- **Go**: resty, go-rate

---

## Conclusione

Questa guida fornisce tutti gli strumenti necessari per implementare un server API compatibile con il plugin **API Book Scraper** di Pinakes.

Per domande o supporto:
- 📧 Email: support@pinakes.dev
- 📖 Docs: https://docs.pinakes.dev
- 💬 Forum: https://community.pinakes.dev

**Buon sviluppo! 🚀**
