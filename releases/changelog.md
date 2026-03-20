# Changelog

Cronologia delle versioni di Pinakes con le principali novità, fix e PR associate.

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
