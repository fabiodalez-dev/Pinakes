# Changelog

Cronologia delle versioni di Pinakes con le principali novità, fix e PR associate.

---

## v0.5.4 — 2026-03-31 (in corso)
**PR:** [#100](https://github.com/fabiodalez-dev/Pinakes/pull/100) — Discogs music scraper, media types, dynamic labels

### Plugin Discogs, Tipi Media, Etichette Dinamiche

**Plugin Discogs (Multi-Sorgente):**
- Scraping musica da **Discogs API** (barcode + ricerca testuale)
- Fallback automatico su **MusicBrainz + Cover Art Archive**
- Arricchimento cover HD da **Deezer**
- Rate limiting per-API: 1s Discogs (auth) / 2.5s (anon), 1.1s MusicBrainz, 1s Deezer
- Mappatura completa: titolo, artista, etichetta, anno, tracklist, generi, formato, peso, prezzo, crediti
- Validazione barcode EAN-13 e UPC-A (hook `scrape.isbn.validate`)
- Pagina impostazioni per token opzionale Discogs

**Colonna `tipo_media`:**
- Nuova ENUM: `libro`, `disco`, `audiolibro`, `dvd`, `altro`
- Dropdown nel form admin con icone Font Awesome
- Colonna icona nella lista admin con filtro
- Auto-popolamento nella migrazione da valori `formato` esistenti
- Backward compatibility con guard `hasColumn()` ovunque
- Incluso in export CSV e import CSV/TSV/LibraryThing

**Etichette Dinamiche (MediaLabels):**
- Autore→Artista, Editore→Etichetta, Anno Pubblicazione→Anno di Uscita
- Numero Pagine→Tracce, ISBN→Barcode, Descrizione→Tracklist, Collana→Discografia
- Nomi formato leggibili: `cd_audio`→"CD Audio", `vinile`→"Vinile"
- Tracklist formattata come `<ol>` HTML ordinata

**Schema.org per Media:**
- `MusicAlbum` (byArtist, recordLabel, numTracks) per dischi
- `Movie` (director, productionCompany) per DVD
- `Audiobook` (readBy) per audiolibri
- `CreativeWork` generico per tipo "altro"
- `Book` (isbn, numberOfPages, bookEdition) come default

**Sicurezza (hardening curl):**
- `CURLOPT_PROTOCOLS` (HTTP/HTTPS only), `CURLOPT_MAXREDIRS`, `CURLOPT_CONNECTTIMEOUT`
- `CURLOPT_SSL_VERIFYPEER` su tutte le chiamate API
- Cast `releaseId` a int (prevenzione SSRF)
- `curl_error()` check su tutti i 4 siti di chiamata

**Migrazione:** `migrate_0.5.4.sql` — colonna `tipo_media`, indice composito, auto-populate

---

## v0.5.3 — 2026-03-28
**PR:** [#96](https://github.com/fabiodalez-dev/Pinakes/pull/96) — 4 P2 cross-version consistency findings + GoodLib plugin

### Consistency Fix, Plugin GoodLib, Sicurezza

**4 P2 Cross-Version Findings:**
- Fix `LT_COLUMN_MAP` export traduttori con roundtrip (#97)
- Fix locale login page che usava il locale errato
- Schema.org ISSN come `identifier/PropertyValue` (non campo diretto)
- Fix `ProfileController::update()` gestione fallimento `prepare()`

**Plugin GoodLib:**
- Nuovo plugin bundled per ricerca ISBN su Anna's Archive e Z-Library
- Link diretti a risorse esterne dalla pagina dettaglio libro
- Aggiunto a `Updater::BUNDLED_PLUGINS`

**10 Round di CodeRabbit Review:**
- 30+ fix di sicurezza, affidabilità e stabilità
- Guard `hasColumn()` rafforzati
- Miglioramenti i18n coverage

---

## v0.5.2 — 2026-03-22
**PR:** [#93](https://github.com/fabiodalez-dev/Pinakes/pull/93) — Normalize translator/illustrator/curator names

### Normalizzazione Nomi Traduttori/Illustratori/Curatori

- **Normalizzazione nomi** per traduttori, illustratori e curatori con `AuthorNormalizer`
- Stessa logica di normalizzazione usata per gli autori principali (inversione cognome/nome, capitalizzazione)
- Fix CodeRabbit review findings per #93

---

## v0.5.1 — 2026-03-20
**PR:** [#95](https://github.com/fabiodalez-dev/Pinakes/pull/95) — ISSN visibility, series links, multi-volume works (#75)

### ISSN, Gestione Collane, Opere Multi-Volume

**ISSN:**
- Nuovo campo ISSN nel form libro con validazione XXXX-XXXX (server + client)
- Visibile nel dettaglio frontend e nelle risposte API pubblica
- Proprietà `issn` nello Schema.org JSON-LD

**Gestione Collane (Serie):**
- Pagina admin `/admin/collane` — lista, crea, rinomina, unisci, elimina
- Pagina dettaglio collana con editor descrizione e autocomplete per unione
- Assegnazione massiva dalla lista libri
- Le collane vuote restano visibili nella lista

**Opere Multi-Volume:**
- Tabella `volumi` per collegare opere padre a singoli volumi
- UI admin per aggiungere/rimuovere volumi con ricerca
- Badge "Questo libro è il volume X dell'opera Y"
- Prevenzione cicli con ancestor-chain walk
- Creazione opera padre da pagina collana

**Import:**
- Parsing campo Series di LibraryThing (`"Nome ; Numero"` → collana + numero_serie)
- Stesso parsing per risultati scraping ISBN

**Bug Fix e Miglioramenti:**
- Validazione ISSN esplicita (era silenziosamente scartato)
- Transazioni su delete/rename/merge collane
- Guard soft-delete su addVolume e updateOptionals
- Resilienza migrazione parziale (`hasCollaneTable()`)
- Ordinamento volumi non-numerici dopo quelli numerici

**Migrazione:** `migrate_0.5.1.sql` — tabelle `volumi`, `collane`, indice `idx_collana`

---

## v0.5.0 — 2026-03-15
**PR:** [#92](https://github.com/fabiodalez-dev/Pinakes/pull/92) — Hreflang tags, RSS feed, sitemap events | [#91](https://github.com/fabiodalez-dev/Pinakes/pull/91) — Genre filter fix

### SEO & LLM Readiness, Schema.org, Campo Curatore

- **Hreflang alternate tags** su tutte le pagine frontend
- **RSS 2.0 feed** `/feed.xml` con gli ultimi 50 libri
- **Endpoint `/llms.txt`** dinamico (attivabile da admin)
- **Schema.org enrichment** — `sameAs` (OpenLibrary, Google Books, WorldCat), tutti i ruoli autore, `bookEdition`, `Offer` condizionale, `editor` per curatore
- **Campo `curatore`** — colonna DB, form libro, dettaglio admin, Schema.org
- **Fix CSV column shift (#83)** e **admin genre display (#90)**

**Migrazione:** `migrate_0.5.0.sql` — colonna `curatore`, `issn`, impostazioni RSS e llms.txt

---

## v0.4.9.9 — 2026-03-12

### Condivisione Social, Navigazione Generi, PDF Inline

- **7 provider sharing** — Facebook, X, WhatsApp, Telegram, LinkedIn, Reddit, Pinterest + Email, Copia Link, Web Share API
- **Navigazione breadcrumb generi** — link cliccabili nella gerarchia
- **Viewer PDF inline** — `<iframe>` nativo (Digital Library plugin v1.3.0)
- **Ricerca nella descrizione** — nuova colonna `descrizione_plain` per ricerca senza HTML
- **Auto-registrazione hook plugin** al caricamento pagina

**Migrazione:** `migrate_0.4.9.9.sql` — colonna `descrizione_plain`, impostazioni social sharing

---

## v0.4.9.8 — 2026-03-08
**PR:** [#88](https://github.com/fabiodalez-dev/Pinakes/pull/88) — Address 19 codebase review findings

### Normalizzazione Traduttori/Illustratori, Bug Fix

- **Normalizzazione nomi** traduttori, illustratori, curatori con `AuthorNormalizer`
- **19 finding di codebase review** corretti (sicurezza, affidabilità, stabilità test)
- Script di upgrade manuale `manual-upgrade.php`

---

## v0.4.9.7 — 2026-03-03

### Re-release per Plugin Bundled

- Re-release di v0.4.9.6 per garantire che gli aggiornamenti dei plugin bundled si propaghino alle installazioni che hanno aggiornato da versioni pre-0.4.9.6

---

## v0.4.9.6 — 2026-03-03
**PR:** [#82](https://github.com/fabiodalez-dev/Pinakes/pull/82) — Comprehensive codebase review

### Review Completa del Codice

- Validazione schema URL, HTTPS proxy-aware nell'installer
- Limite 72 byte bcrypt, RateLimiter atomico con flock
- Guard su `recalculateBookAvailability` e `RELEASE_LOCK`
- Config charset in `SET NAMES`

---

## v0.4.9.4 — 2026-03-02
**PR:** [#69](https://github.com/fabiodalez-dev/Pinakes/pull/69) — German locale, manual upgrade script

### Audiolibri, Z39.50 Nordic, Scorciatoie Tastiera

- **Player MP3 audiolibri**
- **Sorgenti Z39.50/SRU nordiche** (Danimarca, Norvegia, Svezia)
- **Scorciatoie tastiera globali** (Ctrl+K ricerca, Ctrl+N nuovo libro)
- **Scroll-to-top button**
- **Supporto installer tedesco**

---

## v0.4.9.2 — 2026-02-24
**PR:** [#68](https://github.com/fabiodalez-dev/Pinakes/pull/68) — Genre merge/rearrange, book list filters

### Gestione Generi, Filtri Lista Libri

- **Modifica/unione/riorganizzazione generi**
- **Filtro collana (serie)** nella lista libri admin con autocomplete
- **Supporto locale tedesco** completo
- Fix installer, TinyMCE, upload PDF/ePub

---

## v0.4.9.1 — 2026-02-24
**PR:** [#55](https://github.com/fabiodalez-dev/Pinakes/pull/55) — Full subfolder installation support

### Supporto Sottocartella, Sicurezza

- **Installazione in sottocartella** — funziona con base path custom
- **Homepage sort configurabile**
- **Hardening sicurezza** — 177 file modificati
- **Traduzione route** — sistema `RouteTranslator`

---

## v0.4.8.2 — 2026-02-12
**PR:** [#50](https://github.com/fabiodalez-dev/Pinakes/pull/50) — PDF loan receipts, pending loans fixes

### Illustratore, Espansione Lingua

- **Campo illustratore** nel form e nei metadati
- **Lingua espansa** a VARCHAR(255) con normalizzazione nomi nativi
- **Anno pubblicazione signed** (supporto BCE)
- Fix sessione installer, numeri inventario CSV duplicati

**Migrazione:** `migrate_0.4.8.2.sql`
