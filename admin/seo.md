# SEO e Visibilità

Pinakes include funzionalità SEO integrate per migliorare la visibilità nei motori di ricerca.

## Sitemap XML

### Generazione Automatica

Il sistema genera automaticamente una sitemap XML che include:

| Tipo | Query Limit | Priorità | Frequenza |
|------|-------------|----------|-----------|
| Homepage | - | 1.0 | daily |
| Catalogo | - | 0.9 | daily |
| Libri | 2000 | 0.8 | weekly |
| Autori | 500 | 0.6 | monthly |
| Editori | - | 0.5 | monthly |
| Generi | Solo con libri | 0.5 | monthly |
| Pagine CMS | Solo attive | 0.6 | monthly |
| Pagine statiche | - | 0.4-0.7 | monthly |

### URL Sitemap

```
https://tuodominio.it/sitemap.xml
```

### Generazione Dinamica

La sitemap viene generata dinamicamente ad ogni richiesta:

```php
// SeoController::sitemap()
$generator = new SitemapGenerator($db, $baseUrl);
return $generator->generate();
```

Il generatore:
1. Carica le lingue attive dal database
2. Genera URL per ogni entità in ogni locale
3. Deduplica gli URL
4. Restituisce XML conforme al protocollo Sitemaps.org

### Multi-Lingua

Per ogni locale attivo, il sistema genera URL con prefisso lingua:
- Locale default (it_IT): `/libro/123`
- Altri locali (en_US): `/en/libro/123`

### Statistiche

Il generatore tiene traccia delle entità incluse:

```php
$stats = $generator->getStats();
// ['total' => 1234, 'books' => 500, 'authors' => 200, ...]
```

## Robots.txt

### Generazione Automatica

Il file robots.txt è generato dinamicamente:

```
User-agent: *
Disallow: /admin/
Disallow: /login
Disallow: /register

Sitemap: https://tuodominio.it/sitemap.xml
```

### URL

```
https://tuodominio.it/robots.txt
```

### Contenuto

| Direttiva | Descrizione |
|-----------|-------------|
| `Disallow: /admin/` | Blocca indicizzazione area admin |
| `Disallow: /login` | Blocca pagina login |
| `Disallow: /register` | Blocca pagina registrazione |
| `Sitemap:` | Indica posizione sitemap |

## Meta Tag

### Homepage (CMS Hero)

I meta tag homepage sono configurabili nel CMS:

```
seo_title          # Title tag
seo_description    # Meta description
seo_keywords       # Meta keywords
```

Vedi [CMS](cms.md) per la configurazione completa.

### Pagine Libro

I meta sono generati automaticamente da:
- **Titolo**: titolo del libro + autore
- **Descrizione**: primi 160 caratteri della descrizione libro

### Pagine CMS

Ogni pagina CMS ha il campo `meta_description` configurabile.

## Open Graph

### Tag Generati

Per le pagine libro:

```html
<meta property="og:title" content="Titolo Libro">
<meta property="og:description" content="Descrizione libro">
<meta property="og:image" content="URL copertina">
<meta property="og:image:width" content="larghezza">
<meta property="og:image:height" content="altezza">
<meta property="og:image:type" content="image/jpeg">
<meta property="og:url" content="URL canonico">
<meta property="og:type" content="book">
<meta property="og:site_name" content="Nome Biblioteca">
```

### Homepage (CMS)

Il CMS Hero supporta tutti i campi Open Graph:

| Campo | Descrizione |
|-------|-------------|
| `og_title` | Titolo OG personalizzato |
| `og_description` | Descrizione OG |
| `og_type` | Tipo (default: website) |
| `og_url` | URL canonico |
| `og_image` | Immagine condivisione |

## Twitter Card

### Tag Generati

```html
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Titolo">
<meta name="twitter:description" content="Descrizione">
<meta name="twitter:image" content="URL immagine">
<meta name="twitter:site" content="@handle">
<meta name="twitter:creator" content="@handle">
```

### Configurazione CMS

Il CMS Hero supporta:

| Campo | Descrizione |
|-------|-------------|
| `twitter_card` | Tipo card (summary_large_image) |
| `twitter_title` | Titolo Twitter |
| `twitter_description` | Descrizione Twitter |
| `twitter_image` | Immagine Twitter |

## Schema.org (JSON-LD)

### Book Schema

Per ogni pagina libro:

```json
{
  "@context": "https://schema.org",
  "@type": "Book",
  "name": "Titolo Libro",
  "author": {
    "@type": "Person",
    "name": "Nome Autore"
  },
  "publisher": {
    "@type": "Organization",
    "name": "Nome Editore"
  },
  "isbn": "9788858155530",
  "numberOfPages": 256,
  "inLanguage": "it",
  "genre": "Narrativa",
  "description": "Descrizione libro...",
  "image": "URL copertina",
  "offers": {
    "@type": "Offer",
    "availability": "https://schema.org/InStock",
    "itemCondition": "https://schema.org/UsedCondition",
    "seller": {
      "@type": "Library",
      "name": "Nome Biblioteca"
    }
  }
}
```

### AggregateRating

Se il libro ha recensioni approvate:

```json
{
  "aggregateRating": {
    "@type": "AggregateRating",
    "ratingValue": "4.5",
    "reviewCount": "12",
    "bestRating": "5",
    "worstRating": "1"
  }
}
```

### BreadcrumbList Schema

Per la navigazione:

```json
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    {
      "@type": "ListItem",
      "position": 1,
      "name": "Home",
      "item": "https://biblioteca.it"
    },
    {
      "@type": "ListItem",
      "position": 2,
      "name": "Catalogo",
      "item": "https://biblioteca.it/catalogo"
    },
    {
      "@type": "ListItem",
      "position": 3,
      "name": "Titolo Libro"
    }
  ]
}
```

### Library Organization

Schema per la biblioteca:

```json
{
  "@context": "https://schema.org",
  "@type": "Library",
  "name": "Nome Biblioteca",
  "url": "https://biblioteca.it",
  "description": "Biblioteca digitale con catalogo completo"
}
```

## URL Canonico

### Configurazione

La variabile ambiente `APP_CANONICAL_URL` definisce l'URL base:

```env
APP_CANONICAL_URL=https://biblioteca.esempio.it
```

### Rilevamento Automatico

Se non configurato, l'URL viene rilevato da:
1. Header `X-Forwarded-Proto` (per proxy)
2. Variabile `HTTPS`
3. Variabile `REQUEST_SCHEME`
4. Porta server (443 = HTTPS)

### Header Host

Il sistema gestisce:
- `X-Forwarded-Host` (per proxy)
- `HTTP_HOST`
- `SERVER_NAME`

## Implementazione

### File Coinvolti

| File | Funzione |
|------|----------|
| `SeoController.php` | Endpoint sitemap e robots |
| `SitemapGenerator.php` | Generazione XML |
| `Views/frontend/layout.php` | Meta OG e Twitter |
| `Views/frontend/book-detail.php` | Schema.org libro |

### Route

```php
$app->get('/sitemap.xml', [SeoController::class, 'sitemap']);
$app->get('/robots.txt', [SeoController::class, 'robots']);
```

## Risoluzione Problemi

### Sitemap non accessibile

Verifica:
1. Route `/sitemap.xml` configurata in `.htaccess`
2. Connessione database funzionante
3. Libreria `thepixeldeveloper/sitemap` installata

### URL errati nella sitemap

Verifica:
1. `APP_CANONICAL_URL` in `.env`
2. Configurazione proxy (X-Forwarded-*)
3. Locale default nella tabella `languages`

### Meta tag non aggiornati

1. Cancella cache browser
2. Usa strumenti debug social (Facebook Debugger, Twitter Card Validator)
3. Verifica contenuto CMS homepage

### Schema.org non riconosciuto

Testa con:
1. [Google Rich Results Test](https://search.google.com/test/rich-results)
2. Verifica JSON-LD sintatticamente valido
3. Controlla campi obbligatori (name, author per Book)

---

## Domande Frequenti (FAQ)

### 1. Come faccio a far indicizzare la mia biblioteca su Google?

Per far indicizzare Pinakes su Google:

**1. Verifica sitemap:**
- Accedi a `https://tuodominio.it/sitemap.xml`
- Deve mostrare un XML con tutti gli URL

**2. Invia a Google Search Console:**
1. Vai su [search.google.com/search-console](https://search.google.com/search-console)
2. Aggiungi la tua proprietà (dominio)
3. Verifica proprietà (DNS o file HTML)
4. In "Sitemap", inserisci `/sitemap.xml`
5. Clicca "Invia"

**3. Attendi l'indicizzazione:**
- Google esplora il sito in giorni/settimane
- Monitora la copertura in Search Console

---

### 2. Perché la sitemap non mostra tutti i miei libri?

La sitemap ha dei limiti per prestazioni:

| Tipo | Limite |
|------|--------|
| Libri | 2.000 |
| Autori | 500 |

**Se hai più libri:**
- I più recenti hanno priorità
- Considera sitemap index (feature futura)

**Verifica conteggio:**
```php
$stats = $generator->getStats();
// ['total' => 1234, 'books' => 500, ...]
```

---

### 3. Come configuro l'URL canonico correttamente?

L'URL canonico è fondamentale per SEO:

**In `.env`:**
```env
APP_CANONICAL_URL=https://biblioteca.esempio.it
```

**Senza trailing slash**, senza path.

**Dietro proxy/CDN:**
Configura gli header:
- `X-Forwarded-Proto: https`
- `X-Forwarded-Host: biblioteca.esempio.it`

**Verifica:**
Visualizza sorgente pagina e cerca `<link rel="canonical"`.

---

### 4. Come posso testare i meta Open Graph?

**Strumenti ufficiali:**

| Piattaforma | Strumento |
|-------------|-----------|
| Facebook | [Facebook Sharing Debugger](https://developers.facebook.com/tools/debug/) |
| Twitter | [Twitter Card Validator](https://cards-dev.twitter.com/validator) |
| LinkedIn | [LinkedIn Post Inspector](https://www.linkedin.com/post-inspector/) |

**Procedura:**
1. Inserisci URL della pagina libro
2. Verifica titolo, descrizione, immagine
3. Se vecchi dati, clicca "Scrape Again" / "Fetch new"

---

### 5. Perché le immagini non appaiono nelle condivisioni social?

**Cause comuni:**

| Problema | Soluzione |
|----------|-----------|
| Immagine troppo piccola | Minimo 1200x630 px per OG |
| HTTPS mancante | Le piattaforme richiedono HTTPS |
| URL immagine errato | Deve essere URL assoluto completo |
| Immagine non accessibile | Verifica che non sia protetta da login |

**Verifica nel codice:**
```html
<meta property="og:image" content="https://biblioteca.it/uploads/cover.jpg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
```

---

### 6. Come funziona la sitemap multilingua?

Pinakes genera URL per ogni locale attivo:

**Esempio con IT (default) + EN:**
```xml
<url>
  <loc>https://biblioteca.it/libro/123</loc>
</url>
<url>
  <loc>https://biblioteca.it/en/libro/123</loc>
</url>
```

**Configurazione:**
- Le lingue attive sono nella tabella `languages`
- Il locale default non ha prefisso URL
- Gli altri locali hanno prefisso (`/en/`, `/fr/`)

---

### 7. Come verifico che lo Schema.org sia corretto?

**1. Google Rich Results Test:**
- [search.google.com/test/rich-results](https://search.google.com/test/rich-results)
- Inserisci URL pagina libro
- Verifica che rilevi "Book" schema

**2. Schema Markup Validator:**
- [validator.schema.org](https://validator.schema.org)
- Incolla il JSON-LD
- Verifica errori sintattici

**3. Ispeziona manualmente:**
- Apri sorgente pagina (Ctrl+U)
- Cerca `<script type="application/ld+json">`
- Verifica struttura JSON

---

### 8. Le recensioni influenzano il SEO?

Sì! Se il libro ha recensioni approvate, Pinakes genera **AggregateRating**:

```json
{
  "@type": "Book",
  "aggregateRating": {
    "@type": "AggregateRating",
    "ratingValue": "4.5",
    "reviewCount": "12"
  }
}
```

**Benefici SEO:**
- Stelle visibili nei risultati Google (rich snippets)
- Maggiore click-through rate
- Segnale di contenuto di qualità

**Requisiti Google:**
- Almeno 1 recensione
- Rating valido (1-5)

---

### 9. Come personalizzo i meta tag dell'homepage?

I meta dell'homepage si configurano nel CMS:

1. Vai in **Admin → CMS → Homepage**
2. Sezione **Hero**
3. Compila i campi SEO:

| Campo | Uso | Limite |
|-------|-----|--------|
| `seo_title` | `<title>` | 60 caratteri |
| `seo_description` | `<meta description>` | 160 caratteri |
| `og_title` | Titolo Facebook/LinkedIn | 60 caratteri |
| `twitter_title` | Titolo Twitter | 70 caratteri |

4. Salva

---

### 10. Devo configurare manualmente robots.txt?

No, Pinakes genera `robots.txt` automaticamente:

**URL:** `https://tuodominio.it/robots.txt`

**Contenuto generato:**
```
User-agent: *
Disallow: /admin/
Disallow: /login
Disallow: /register
Sitemap: https://tuodominio.it/sitemap.xml
```

**Personalizzazione:**
Attualmente il contenuto è fisso. Per modifiche, crea un file `robots.txt` fisico nella cartella `public/` che sovrascriverà la route dinamica.
