# 📚 Funzioni CMS – Documentazione Tecnica

> **Scopo** – Questo documento descrive in dettaglio le funzioni del CMS presenti nella cartella `app/Views/cms` (attualmente `edit-home.php`) e il relativo **controller** `app/Controllers/CmsController.php`.  
> Vengono inoltre illustrate le impostazioni dell’applicazione, le misure di sicurezza (CSRF, sanitizzazione, upload), il meccanismo di **scraping** ISBN, l’**inserimento manuale** dei contenuti e la gestione delle **copie** dei libri.

---

## 📁 Struttura dei file CMS

```
app/
├── Controllers/
│   └── CmsController.php          # Logica di visualizzazione e salvataggio
└── Views/
    └── cms/
        └── edit-home.php          # Form di amministrazione della homepage
```

> **Nota:** Al momento il CMS gestisce solo la homepage (`edit-home.php`). Altre pagine statiche sono gestite da `CmsController::showPage()` che carica il contenuto da `cms_pages`.

---

## 🛠️ Funzioni del `CmsController`

| Metodo | Route (esempio) | Descrizione | Principali operazioni |
|--------|----------------|-------------|----------------------|
| `showPage` | `GET /cms/:slug` | Visualizza una pagina CMS (es. “chi-siamo”). | - Recupera la pagina dal DB con supporto locale.<br>- Sanitizza il contenuto con `ContentSanitizer`.<br>- Renderizza `frontend/cms-page.php`. |
| `editHome` | `GET /admin/cms/home` | Carica tutti i blocchi della homepage per la modifica. | - Legge tutti i record da `home_content`.<br>- Popola l’array `$sections` (chiave `section_key`).<br>- Include `cms/edit-home.php` e il layout generale. |
| `updateHome` | `POST /admin/cms/home` | Salva le modifiche della homepage. | - **CSRF**: verifica token.<br>- **Sanitizzazione** di tutti i campi testuali.<br>- **Validazione URL** per link pulsanti.<br>- **Upload immagine** con controlli di estensione, MIME, dimensione, percorso sicuro e nome random.<br>- **UPSERT** (INSERT … ON DUPLICATE KEY UPDATE) per ogni sezione (`hero`, `features_title`, `feature_1‑4`, `latest_books_title`, `text_content`, `cta`).<br>- Gestione errori e messaggi di successo in `$_SESSION`. |

---

## 🔐 Sicurezza

### CSRF
- **Generazione**: `Csrf::ensureToken()` inserisce un campo hidden `_csrf` nel form.  
- **Validazione**: `Csrf::validate($token)` è chiamata all’inizio di `updateHome`. Se fallisce, la richiesta è rifiutata e l’utente viene reindirizzato con messaggio di errore.

### Sanitizzazione dei dati
```php
$sanitizeText = function($text) {
    $text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $text);
    $text = preg_replace('/\s*on\w+\s*=\s*["\'][^"\']*["\']/i', '', $text);
    $text = preg_replace('/javascript:/i', '', $text);
    return trim($text);
};
```
- Rimuove `<script>`, attributi `on*` e protocolli `javascript:` per prevenire XSS.

### Validazione URL
```php
$validateUrl = function($url) {
    $url = trim($url);
    if (empty($url)) return true;
    if (preg_match('/^\/[^\/]/', $url)) return true; // URL relativo
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
};
```
- Accetta solo URL relativi (es. `/catalogo`) o URL assoluti validi.

### Upload di immagini (Hero background)
1. **Estensioni consentite**: `jpg`, `jpeg`, `png`, `webp`.  
2. **Dimensione massima**: 5 MB.  
3. **MIME type** verificato con `finfo`.  
4. **Percorso sicuro**: `public/uploads/assets/` (creato se non esiste).  
5. **Nome file**: `hero_bg_<random>.ext` generato con `random_bytes(8)`.  
6. **Permessi**: `0644`.  
7. **Controllo di traversal**: `realpath` e verifica che il percorso sia all’interno della directory di upload.

---

## ⚙️ Impostazioni CMS dell’app

| Configurazione | File | Scopo |
|----------------|------|-------|
| **Locale** | `app/Support/I18n.php` | Determina la lingua corrente (`getLocale()`) e carica le traduzioni. |
| **Impostazioni di default** | `config/default_texts.php` | Testi di fallback per pagine CMS (es. “Titolo di default”). |
| **Impostazioni utente** | `config/settings.php` | Configurazioni globali (es. `site_name`, `default_locale`). |
| **CSRF** | `app/Support/Csrf.php` | Generazione e validazione token. |
| **Sanitizzazione contenuti** | `app/Support/ContentSanitizer.php` | Normalizza asset esterni (es. URL di immagini). |
| **SEO per la homepage** | Campi `seo_*`, `og_*`, `twitter_*` nella tabella `home_content`. | Permettono di impostare meta‑tag, Open Graph e Twitter Card per ogni sezione. |
| **Attivazione sezioni** | Campo `is_active` (boolean) in `home_content`. | Consente di nascondere/mostrare singole sezioni senza cancellare i dati. |
| **Ordinamento** | Campo `display_order` (int). | Definisce l’ordine di visualizzazione nella homepage. |

---

## 📦 Scraping automatico (ISBN)

Il **scraping** è gestito dal controller `app/Controllers/ScrapeController.php` (non parte del CMS ma spesso usato nella pagina di inserimento libri).

- **Endpoint**: `GET /api/scrape/isbn?isbn=9788842935780`  
- **Flusso**:
  1. Verifica formato ISBN (10/13).  
  2. Richieste parallele a Google Books, Open Library, ecc.  
  3. Normalizza i dati (titolo, autore, copertina, descrizione, prezzo).  
  4. Restituisce JSON con i campi pronti per l’inserimento.  
- **Gestione errori**: 404 se nessun risultato, 429 per rate‑limit, log in `storage/logs/scrape.log`.

> **Nota**: Il risultato del scraping può essere usato nella sezione “Hero” della homepage (campo `hero[background_image]`) oppure nella pagina di inserimento libri (`docs/inserimento_libri.MD`).

---

## ✍️ Inserimento manuale dei contenuti

### 1. **CMS – Homepage**
- Accedi a **Dashboard → CMS → Modifica Homepage** (`/admin/cms/home`).  
- Compila i campi testuali, carica l’immagine di sfondo, attiva/disattiva le sezioni.  
- Salva → il controller `updateHome` esegue l’**UPSERT** in `home_content`.

### 2. **CMS – Pagine statiche**
- Le pagine statiche sono gestite da `cms_pages` (tabella).  
- Per aggiungere una nuova pagina:
  1. Inserisci un record in `cms_pages` (slug, locale, title, content, meta_description, is_active).  
  2. La rotta `/cms/:slug` la renderizza tramite `showPage`.  
  3. Puoi creare una vista personalizzata in `app/Views/frontend/cms-page.php` o riutilizzare il layout esistente.

### 3. **Inserimento libri (non CMS)**
- Vedi la guida completa in `docs/inserimento_libri.MD`.  
- Puoi inserire manualmente i dati compilando il form oppure usare lo **scraping** per pre‑popolare i campi.

---

## 📚 Gestione delle copie (books)

Il CMS non gestisce direttamente le copie dei libri; questa logica è presente nei controller `LibriController` e `LibriApiController`. Tuttavia, è possibile:

- **Visualizzare** il numero di copie totali e disponibili nella tabella `libri` (`copie_totali`, `copie_disponibili`).  
- **Aggiornare** le copie tramite la pagina di modifica libro (`app/Views/libri/partials/book_form.php`).  
- **Aggiungere** copie in massa usando l’endpoint API `POST /api/libri/{id}/copy`.

> **Riferimento**: per dettagli sulla struttura della tabella `libri` consultare `docs/libri.MD`.

---

## 🛠️ Come aggiungere nuove sezioni al CMS

1. **Database** – Aggiungi un nuovo record in `home_content` con `section_key` univoco (es. `testimonials`).  
2. **Controller** – `editHome()` carica automaticamente tutti i record; la nuova chiave sarà disponibile in `$sections['testimonials']`.  
3. **View** – Inserisci il markup nella pagina `edit-home.php` (es. un nuovo `<div>` con i campi del form).  
4. **Salvataggio** – `updateHome()` gestisce automaticamente l’UPSERT se il nome del campo corrisponde a `testimonials`.  
5. **Ordinamento** – Imposta `display_order` per controllare la posizione nella homepage.

---

## ✅ Checklist di verifica (per gli sviluppatori)

- [ ] **CSRF**: il token è presente nel form e viene validato in `updateHome`.  
- [ ] **Sanitizzazione**: tutti i campi testuali passano attraverso `$sanitizeText`.  
- [ ] **Validazione URL**: i link dei pulsanti sono controllati con `$validateUrl`.  
- [ ] **Upload immagine**: verifica estensione, MIME, dimensione, percorso e permessi.  
- [ ] **UPSERT**: ogni sezione della homepage è salvata con `INSERT … ON DUPLICATE KEY UPDATE`.  
- [ ] **Messaggi**: `$_SESSION['success_message']` o `$_SESSION['error_message']` vengono mostrati correttamente nella view.  
- [ ] **Locale**: la pagina CMS rispetta la lingua corrente (`I18n::getLocale()`).  
- [ ] **SEO**: i campi SEO (title, description, keywords, OG, Twitter) sono salvati e utilizzati nella view.  

---

## 📖 Riferimenti incrociati

- **Home Content Table** – `docs/home_content.MD` (sezione dedicata).  
- **Inserimento libri** – `docs/inserimento_libri.MD`.  
- **Gestione copie** – `docs/libri.MD`.  
- **Scraping ISBN** – `app/Controllers/ScrapeController.php`.  
- **Impostazioni globali** – `config/settings.php`.  
- **CSRF** – `app/Support/Csrf.php`.  
- **Sanitizzazione** – `app/Support/ContentSanitizer.php`.  

---

*Ultimo aggiornamento: 19 Ottobre 2025*  
*Versione documento: 1.0.0* 🎉
