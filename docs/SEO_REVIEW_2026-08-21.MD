# Review SEO completa — Pinakes

**Data:** 2026-08-21 · **Base:** `main` @ `5b35f2e` (v0.7.63) · include la verifica dei finding CodeRabbit sulla PR #368.

> **Nota sulle fonti:** l'audit è condotto esclusivamente sul codice, non sulla documentazione in `docs/`, che risulta non aggiornata. In particolare `docs/SEO_IMPLEMENTATION_TESTING.md` (rev. 2026-06-04, "Status: Complete") descrive l'hreflang path-prefix (`/en/…`) come funzionante e afferma che non esiste un language switcher frontend, mentre nel codice i prefissi non sono routabili (vedi C1) ed esiste l'endpoint `/language/{locale}`. Andrebbe riallineata dopo il fix di C1.

---

## Parte 1 — Verifica dei finding CodeRabbit (PR #368)

La PR #368 (prestiti multipli di copie fisiche, #238) ha ricevuto 7 finding. Verifica effettuata sul head `0f54a81`:

| # | Finding | Severità | Stato thread | Verifica sul codice |
|---|---------|----------|--------------|---------------------|
| 1 | `reassignOnNewCopy()` usciva al primo candidato in conflitto (`LIMIT 1`) invece di valutare la prenotazione FIFO successiva | Major | Risolto (40662cb) | **Corretto davvero.** Il metodo ora pre-filtra tutti i candidati, li blocca in batch FIFO deterministico (`ORDER BY created_at, id`) e itera con `continue` sui conflitti (`ReservationReassignmentService.php:240-261`). |
| 2 | Con l'opzione attiva, una prenotazione già associata a una copia non era più bloccante → doppio prestito stesso utente/libro | Major | Risolto (40662cb) | **Corretto davvero.** `LoanMultiplicityPolicy::hasBlockingLoan()` mantiene bloccanti le prenotazioni promosse (`origine='prenotazione'` in `prenotato`/`da_ritirare`) anche con `copia_id` valorizzato (`LoanMultiplicityPolicy.php:66-68`). |
| 3 | Etichetta toggle fuorviante ("prestiti multipli per copia") | Minor | Risolto (40662cb) | **Corretto davvero.** Ora: "Consenti più prestiti dello stesso titolo solo su copie fisiche distinte" (`loans-tab.php:275`). |
| 4 | Test unit senza il caso "due prenotazioni bloccate, la prima in conflitto" | Trivial | Risolto (40662cb) | **Corretto davvero.** Il test crea target in conflitto + fallback di altro utente, forza il FIFO via `created_at` e asserisce che la copia vada al secondo (`multiple-copy-loans-238.unit.php:919-997`). |
| 5 | Predicato "impegni attivi" duplicato tra policy e servizio di riassegnazione | Major | Risolto (d9436a6 + 027dd2a) | **Corretto davvero.** `OPEN_ACTIVE_STATES_SQL` / `OPEN_COMMITMENT_PREDICATE` sono l'unica fonte; `lockOpenCopyCommitments()` + `candidateConflictsWithOpenCommitments()` sono riusati da `committedCopyIds()` e `reassignOnNewCopy()`. |
| 6 | E2E: dopo ogni submit andrebbe atteso/cliccato `.swal2-confirm` | Minor | **Aperto** | **Non applicabile.** I submit del test (`/admin/loans/create` e il form settings) sono POST classici con redirect server-side (`?created=`/`?error=`); né `crea_prestito.php` né il pannello loans mostrano SweetAlert al submit (l'unico Swal in `settings/index.php:1239` riguarda l'upload del logo). Il finding deriva dalla path-instruction generica di CodeRabbit, non dal comportamento reale. Si può risolvere il thread motivando così. |
| 7 | E2E: `dbQuery()` fa `trim()`, quindi un `setting_value` preesistente vuoto/con spazi non viene ripristinato esattamente in `afterAll` (riga cancellata invece che ripristinata) | Minor | **Aperto** | **Tecnicamente valido ma marginale**: riguarda solo un DB E2E con valore vuoto/whitespace preesistente per quella chiave. Fix rapido possibile: rilevare l'esistenza con `SELECT COUNT(*)` e serializzare con `HEX(setting_value)`. Da applicare sul branch `feature/multiple-copy-loans` se si vuole chiudere il thread. |

**Conclusione parte 1:** i 5 finding sostanziali sono realmente risolti nel codice; dei 2 aperti, uno è un falso positivo (SweetAlert) e uno è un nit valido ma a bassissimo impatto sul cleanup dei test E2E.

---

## Parte 2 — Review SEO dell'applicazione

### Punti di forza (già implementati bene)

- **Infrastruttura di discovery completa**: `sitemap.xml` dinamica con rigenerazione da admin, `robots.txt` dinamico, feed RSS 2.0, `llms.txt` opzionale (`SeoController`, `SitemapGenerator`, `FeedController`).
- **Canonical host enforcement**: redirect 301 verso `APP_CANONICAL_URL` con gestione proxy/porte e protezione host-header injection (`public/index.php:383-460`).
- **JSON-LD ricco sulla scheda libro**: tipo dinamico (Book/MusicAlbum/Movie/Audiobook/CreativeWork), autori con ruoli corretti, `sameAs` VIAF/ISNI/OpenLibrary/WorldCat, `aggregateRating` dalle recensioni, BreadcrumbList, Offer condizionale (`book-detail.php:140-429`).
- **URL parlanti** `/autore/titolo/id` con redirect 301 alla forma canonica (`FrontendController::bookDetail`).
- **404 con status code corretto** e pagina custom nel layout.
- **Open Graph + Twitter Card completi** con `book:*` meta, fallback intelligenti, immagine social configurabile.
- **hreflang con x-default** concettualmente corretto (ma vedi C1).
- **FAIR Signposting** (`Link: rel="type"/"describedby"/"author"`) sulla scheda libro — raro e ottimo per interoperabilità.
- Security headers, HSTS, cache-busting asset, catalogo con prima pagina server-rendered.

### 🔴 Critici

**C1 — hreflang e sitemap generano URL `/en/…`, `/de/…` che rispondono 404.**
`HreflangHelper::getAlternates()` e `SitemapGenerator::getLocalePrefix()` emettono URL con prefisso lingua per ogni locale attivo diverso dal default (es. `https://sito/en/catalog`). Ma nel routing (`app/Routes/web.php`) **nessuna route con prefisso `/en|/de|/fr` è registrata**: le varianti tradotte sono tutte a root (`/catalogo` e `/catalog` coesistono) e la lingua è di sessione (`/language/{locale}`). Risultato su installazioni con ≥2 lingue attive:
- la sitemap contiene N-1/N di URL che restituiscono 404 (ogni entry è moltiplicata per locale);
- tutti gli hreflang alternate puntano a 404 → errori in Search Console, crawl budget sprecato, segnali di qualità negativi.
*Fix:* o si implementa davvero il prefisso locale nel routing (middleware che consuma `/xx` e imposta il locale), o — più semplice — si emettono alternates/entry sitemap solo per URL effettivamente servibili (nel modello attuale: le varianti di path tradotte a root, es. `hreflang="en" → /catalog`, `hreflang="it" → /catalogo`).
File: `app/Support/HreflangHelper.php:93-108`, `app/Support/SitemapGenerator.php:505-515` + tutti i `getLocalePrefix()`.

**C2 — Canonical di default con query string.**
Il fallback del layout è `HtmlHelper::getCurrentUrl()` che include `REQUEST_URI` completo di query (`layout.php:154-155`, `HtmlHelper.php:449-467`). La scheda libro usa esplicitamente `getCurrentUrl()` (`book-detail.php:128`) e il redirect 301 alla struttura canonica **preserva la query** (`FrontendController.php:550-555`). Quindi `…/libro/x/123?utm_source=newsletter` dichiara canonical sé stesso → ogni parametro di tracking crea un duplicato indicizzabile.
*Fix:* sulla scheda libro usare `absoluteUrl($canonicalPath)` (già calcolato); nel layout, fallback canonical = URL senza query string.

**C3 — Archivi autore senza canonical/description/schema + doppia route indicizzabile.**
`authorArchive` (per nome) e `authorArchiveById` non impostano `$seoTitle/$seoDescription/$seoCanonical/$seoSchema` — solo `$title` hardcoded in italiano ("Libri di …"). La stessa pagina esiste a due URL (per nome e per ID) senza canonical che li unifichi → duplicate content su tutto il layer autori; il canonical fallback (C2) con query peggiora. Editore e genere hanno i meta ma hardcoded in italiano (vedi H3).
*Fix:* impostare nel controller canonical (variante per-nome come primaria), meta description e uno schema `CollectionPage`/`Person`; su `authorArchiveById` canonical → URL per nome.
File: `FrontendController.php:1369-1449`, `2078-2160`, `app/Views/frontend/archive.php`.

### 🟠 Alti

**H1 — Sitemap troncata silenziosamente e voci incoerenti con robots.txt.**
- Libri `LIMIT 2000`, autori `LIMIT 500` (`SitemapGenerator.php:295, 330`): oltre quei numeri il catalogo sparisce dalla sitemap senza avviso. Niente sitemap index/paginazione.
- `login` e `register` sono nella sitemap (`getStaticEntries`) ma **Disallow** in robots.txt → segnalazione "Submitted URL blocked by robots.txt".
- Gli editori entrano tutti, anche con 0 libri (i generi invece filtrano `HAVING COUNT>0`) → thin pages in sitemap.
*Fix:* sitemap index con chunk da 10-50k, rimuovere login/register, filtrare editori senza libri.

**H2 — Meta description tagliata byte-per-byte (UTF-8 spezzato).**
`book-detail.php:109-111`: `substr(strip_tags($desc), 0, 140)` non è multibyte — con caratteri accentati può troncare a metà carattere producendo byte invalidi nel `<head>`; inoltre il confronto `strlen($bookDescription) > 140` misura l'HTML originale (con tag), non il testo. *Fix:* `mb_substr` sul testo già strip-tagged + `mb_strlen`.

**H3 — Stringhe SEO hardcoded in italiano su installazioni multilingua.**
- `publisherArchive`: "Libri di X - Catalogo Editore | Biblioteca" (`FrontendController.php:1545-1546`);
- `genreArchive`: idem (`1697-1698`);
- `authorArchive`/`ById`: `$title = "Libri di …"`;
- suffisso title scheda libro `" - " . __("Biblioteca")` e Organization schema `name => __("Biblioteca")` (`book-detail.php:103, 426`): meglio il nome reale della biblioteca (`app.name`), che è anche il brand nelle SERP.
*Fix:* wrappare in `__()` con placeholder e usare `ConfigStore::get('app.name')` come brand.

**H4 — Paginazione del catalogo non crawlabile.**
I link di paginazione sono `<a href="#" onclick="goToPage(n)">` (`catalog.php:2103-2131`): Googlebot non segue gli onclick, quindi le pagine 2+ del catalogo (e i libri linkati solo lì) sono raggiungibili solo via sitemap — che è cappata a 2000 (H1).
*Fix:* href reali `?page=N` (il controller li gestisce già server-side) con enhancement JS sopra.

### 🟡 Medi

**M1 — Nessun `noindex` in tutta l'app.** Le pagine di ricerca interna (`?q=`) sono indicizzabili (mitigato dal canonical al catalogo nudo, che però su risultati vuoti/spam è un segnale debole); le pagine auth hanno `<head>` proprio senza meta robots (mitigato dal Disallow, che però non impedisce l'indicizzazione di URL linkati). Consigliato `noindex,follow` su risultati di ricerca, login/register e pagine profilo.

**M2 — Eventi senza JSON-LD `Event`.** `event-detail.php` ha OG/Twitter completi ma nessun structured data → niente rich results eventi (data, luogo). Aggiungere schema `Event` con `startDate`, `location`, `organizer`.

**M3 — Immagini griglia senza `loading="lazy"`, `width/height`, `srcset`.** `catalog-grid.php:40-43` e `home-books-grid.php`: covers senza dimensioni intrinseche (CLS) né lazy loading (solo i correlati della scheda libro lo hanno). La cover principale della scheda libro (`book-detail.php:1830-1833`) è l'elemento LCP: aggiungere `width/height` e `fetchpriority="high"`.

**M4 — `og:image` può essere vuoto.** Se non c'è social image configurata, il layout stampa `<meta property="og:image" content="">` (`layout.php:172-186`). Meglio ometterla o fallback alla copertina placeholder.

**M5 — robots.txt copre solo la variante di lingua corrente di login/register.** Le route sono registrate in tutte le lingue (`/accedi` E `/login`), ma il Disallow usa `RouteTranslator::route()` col locale della richiesta → le varianti alternative restano crawlabili. Iterare su tutte le locale attive.

**M6 — Incoerenza canonical/hreflang su URL parametrizzati.** Gli hreflang strippano la query, il canonical fallback la include (C2): su pagine paginate/filtrate i due segnali puntano a URL diversi.

**M7 — Breadcrumb JSON-LD solo sulla scheda libro.** Archivi, eventi e CMS non emettono `BreadcrumbList` pur avendo breadcrumb visivi.

### Priorità d'intervento suggerita

1. **C1** (hreflang/sitemap 404) — è l'unico che *danneggia attivamente* il ranking su installazioni multilingua.
2. **C2 + M6** (canonical con query) — one-liner nel layout + scheda libro.
3. **H1** (sitemap: cap, login/register, editori vuoti) — impatto diretto su copertura di scansione.
4. **C3 + H3** (archivi autore: canonical/meta/i18n) — il layer autori è ampio e ora quasi invisibile.
5. **H4** (paginazione crawlabile) — sblocca la scoperta organica oltre la sitemap.
6. H2, M1-M7 in coda.
