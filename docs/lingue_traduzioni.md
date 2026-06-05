# Guida a Lingue e Traduzioni

## Introduzione

Pinakes è progettato per essere **multilingua**, il che significa che può essere facilmente tradotto in diverse lingue. Questa guida spiega come funziona il sistema di internazionalizzazione (spesso abbreviato in **i18n**).

---

## Dove si Trovano le Traduzioni

Tutti i file relativi alle lingue si trovano nella cartella `locale/`.

```
locale/
├── it_IT.json         # File principale per l'Italiano
├── en_US.json         # File principale per l'Inglese
├── de_DE.json         # File principale per il Tedesco
├── fr_FR.json         # File principale per il Francese
├── routes_it_IT.json  # Traduzioni per gli URL in Italiano
├── routes_en_US.json  # Traduzioni per gli URL in Inglese
├── routes_de_DE.json  # Traduzioni per gli URL in Tedesco
└── routes_fr_FR.json  # Traduzioni per gli URL in Francese
```

### File Principali (`it_IT.json`, `en_US.json`, `de_DE.json`, `fr_FR.json`)

Questi file contengono la maggior parte delle traduzioni. Sono file di tipo **JSON**, che è un formato semplice per associare una "chiave" a un "valore".

A differenza di molti sistemi i18n, **la chiave è la frase italiana stessa**
(vedi "Come si Aggiunge una Traduzione" più sotto), non un identificatore
astratto. Nel file sorgente `it_IT.json` chiave e valore coincidono.

**Esempio di `it_IT.json`** (sorgente, chiave = valore):
```json
{
  "Pannello di Controllo": "Pannello di Controllo",
  "Libri": "Libri",
  "Aggiungi Nuovo Libro": "Aggiungi Nuovo Libro",
  "Salva": "Salva",
  "Elimina": "Elimina"
}
```

**Esempio di `en_US.json` corrispondente** (stessa chiave italiana, valore tradotto):
```json
{
  "Pannello di Controllo": "Dashboard",
  "Libri": "Books",
  "Aggiungi Nuovo Libro": "Add New Book",
  "Salva": "Save",
  "Elimina": "Delete"
}
```

- **Chiave**: `Pannello di Controllo` (la frase italiana sorgente, identica in tutti i file)
- **Valore**: il testo mostrato all'utente nella lingua del file (`"Dashboard"` in `en_US.json`)

A seconda della lingua **scelta in fase di installazione** il sistema usa `it_IT.json` (italiano), `en_US.json` (inglese), `de_DE.json` (tedesco) o `fr_FR.json` (francese). La lingua è fissata all'installazione e non esiste un selettore di lingua nel frontend (vedi sotto).

### File delle Rotte (`routes_it_IT.json`, `routes_en_US.json`)

Questi file speciali servono per tradurre gli **URL** (gli indirizzi delle pagine).

I file delle rotte associano una **chiave di rotta** (es. `catalog`, `book`,
`login`) al percorso localizzato. Le chiavi sono fisse; cambiano solo i valori.

**Esempio di `routes_it_IT.json`** (estratto reale):
```json
{
  "login": "/accedi",
  "catalog": "/catalogo",
  "book": "/libro",
  "author": "/autore",
  "events": "/eventi"
}
```

**Esempio di `routes_en_US.json`**:
```json
{
  "login": "/login",
  "catalog": "/catalog",
  "book": "/book",
  "author": "/author",
  "events": "/events"
}
```

**Esempio di `routes_fr_FR.json`**:
```json
{
  "login": "/connexion",
  "catalog": "/catalogue",
  "book": "/livre",
  "author": "/auteur",
  "events": "/evenements"
}
```

Questo permette di avere URL **utente** (frontend) localizzati, come:
- `http://tuosito.it/catalogo` / `/libro/123` (italiano)
- `http://tuosito.it/catalog` / `/book/123` (inglese)
- `http://tuosito.it/catalogue` / `/livre/123` (francese)

> **Importante (regola del progetto)**: la traduzione delle rotte vale **solo
> per le rotte utente** (login, catalogo, scheda libro, profilo, eventi, ecc.).
> Le **rotte admin** (`/admin/...`) sono letterali in inglese e **non** passano
> dal sistema i18n: vanno generate con `url('/admin/...')`, mai con
> `route_path()`/`RouteTranslator`.

---

## Come Funziona nel Codice

### Traduzione dei testi: `__()`

Per mostrare un testo tradotto si usa la funzione globale **`__()`** (definita in
`app/helpers.php`). A differenza di molti sistemi i18n, **la chiave è il testo
italiano stesso** (l'italiano è la lingua sorgente): non si usano chiavi
astratte tipo `dashboard.title`.

**Esempio nel codice PHP (in una vista)**:
```php
<h1><?= __('Pannello di Controllo') ?></h1>

<a href="<?= htmlspecialchars(url('/admin/libri/nuovo'), ENT_QUOTES, 'UTF-8') ?>" class="button">
  <?= __('Aggiungi Nuovo Libro') ?>
</a>
```

**Cosa succede**:
1. `__('Pannello di Controllo')` viene chiamata.
2. Il sistema legge la lingua corrente da `I18n::getLocale()` (fissata
   all'installazione).
3. Apre il file della lingua, es. `locale/en_US.json`.
4. Cerca la chiave `"Pannello di Controllo"`.
5. Restituisce la traduzione (`"Dashboard"`); se la chiave manca, restituisce la
   stringa italiana originale.

Esiste anche **`__n($singolare, $plurale, $count, ...)`** per le forme plurali.
Entrambe accettano segnaposto `printf` come argomenti aggiuntivi.

> **Regola del progetto (BLOCCANTE)**: ogni nuova stringa rivolta all'utente
> va racchiusa in `__()` con l'italiano come sorgente, e la chiave va aggiunta a
> **tutti e 4** i file di lingua (`it_IT`, `en_US`, `de_DE`, `fr_FR`) nello
> stesso commit.

### Traduzione degli URL: `route_path()` / `RouteTranslator`

Per generare un percorso **utente** localizzato si usa l'helper di vista
`route_path('chiave')`, wrapper di `RouteTranslator::route('chiave')`
(`app/Support/RouteTranslator.php`).

```php
<a href="<?= htmlspecialchars(route_path('catalog'), ENT_QUOTES, 'UTF-8') ?>">
  <?= __('Catalogo') ?>
</a>
```

`RouteTranslator::route()` legge la lingua corrente, carica
`locale/routes_<locale>.json` e restituisce il percorso tradotto; se la chiave
non è nel JSON, ricade su una **mappa di fallback inglese** definita in
`RouteTranslator` (es. `login → /login`, `catalog → /catalog`). Per URL di
entità si concatena l'id/slug: `route_path('author') . '/' . $id`.

`RouteTranslator::getRouteForLocale('chiave', $locale)` serve a registrare in
`web.php` le varianti di rotta per ogni lingua attiva, così percorsi diversi
(es. `/libro/123` e `/book/123`) puntano allo stesso controller.

---

## Come Aggiungere una Nuova Traduzione

Se vuoi aggiungere un nuovo testo traducibile, segui questi 3 semplici passi:

### Passo 1: Scrivi il Testo in Italiano (è la chiave)
La chiave **è** la frase italiana. Scrivi una frase intera e naturale.

**Esempio**: vuoi il testo "Cerca per autore" → la chiave è esattamente
`"Cerca per autore"`.

### Passo 2: Aggiungi la Chiave a TUTTI i File JSON
Apri **tutti e quattro** i file di traduzione (`it_IT.json`, `en_US.json`,
`de_DE.json`, `fr_FR.json`) e aggiungi la stessa chiave con la rispettiva
traduzione, nello **stesso commit**.

**In `it_IT.json`** (sorgente, chiave = valore):
```json
{ "Cerca per autore": "Cerca per autore" }
```

**In `en_US.json`**:
```json
{ "Cerca per autore": "Search by author" }
```

**In `de_DE.json`**:
```json
{ "Cerca per autore": "Nach Autor suchen" }
```

**In `fr_FR.json`**:
```json
{ "Cerca per autore": "Rechercher par auteur" }
```
>  **Importante**: ricorda la virgola `,` tra le voci JSON.

### Passo 3: Usa `__()` nel Codice
Nella vista usa la funzione `__()` con la frase italiana.

**Esempio**:
```php
<label><?= __('Cerca per autore') ?></label>
<input type="text" name="author_search">
```

**Fatto!** Il testo viene tradotto secondo la lingua impostata all'installazione.

---

## Gestire le Lingue

### Lingue Disponibili
Pinakes include di serie **quattro** lingue complete:
- **Italiano** (`it_IT`) — lingua sorgente
- **English** (`en_US`)
- **Deutsch** (`de_DE`)
- **Français** (`fr_FR`)

La lingua viene scelta durante l'installazione e diventa la lingua predefinita
per tutta l'applicazione. **Non esiste un selettore di lingua nel frontend**: la
lingua è fissata a livello di installazione.

> **Nota tecnica**: l'elenco delle lingue è gestito da `App\Support\I18n`. Le
> lingue vengono caricate dal database (`getAvailableLocales()`); la mappa
> statica di fallback in `I18n` elenca `it_IT`, `en_US`, `de_DE`, mentre i file
> `fr_FR.json` / `routes_fr_FR.json` sono presenti su disco e la lingua francese
> è attivabile/registrata a livello DB.

### Aggiungere una Nuova Lingua (es. Spagnolo)
Per aggiungere il supporto a una nuova lingua, per esempio lo spagnolo (`es_ES`):

1. **Crea i file di traduzione**:
   - Copia `it_IT.json` e rinominalo in `es_ES.json`.
   - Copia `routes_it_IT.json` e rinominalo in `routes_es_ES.json`.

2. **Traduci i valori**:
   - Apri `es_ES.json` e traduci tutti i valori (le chiavi restano in italiano).
     ```json
     { "Pannello di Controllo": "Panel de Control", "Libri": "Libros" }
     ```
   - Apri `routes_es_ES.json` e traduci i percorsi (le chiavi di rotta restano
     invariate, es. `catalog`, `book`, `author`).
     ```json
     { "catalog": "/catalogo", "book": "/libro", "author": "/autor" }
     ```

3. **Registra la nuova lingua** nell'elenco delle lingue supportate (gestione
   lingue lato amministrazione / `App\Support\I18n`).

4. **Testa**: imposta la nuova lingua e verifica che tutte le traduzioni e i
   percorsi vengano caricati correttamente.

---

## Domande Frequenti

**D: Cosa succede se una chiave di traduzione non viene trovata?**
R: Se `__()` non trova la chiave nel file della lingua corrente, restituisce la
stringa italiana originale (la chiave stessa). Ad esempio
`__('Testo non tradotto')` mostra `Testo non tradotto`.

**D: Posso usare variabili nelle traduzioni?**
R: Sì, `__()` (e `__n()`) accettano argomenti `printf` aggiuntivi.
**Esempio JSON**: `"Benvenuto, %s!": "Benvenuto, %s!"`
**Esempio PHP**: `__('Benvenuto, %s!', $userName)`

**D: Devo tradurre ogni singola parola?**
R: No, si traducono "stringhe" o frasi intere. Questo rende il contesto più chiaro e la traduzione più naturale. Ad esempio, invece di tradurre "Cerca" e "per" separatamente, si traduce l'intera frase "Cerca per autore".

**D: Devo usare chiavi astratte tipo `sezione.nome`?**
R: No. In Pinakes la chiave di traduzione **è la frase italiana** (lingua
sorgente). Si traducono frasi intere, non parole singole: questo mantiene il
contesto e rende la traduzione più naturale.

---
*Ultimo aggiornamento: 4 Giugno 2026*
*Versione guida: 1.2.0 — Aggiunto supporto francese (fr_FR); corretta la funzione di traduzione (`__()`/`__n()`), il modello a chiave-italiana e il sistema rotte (`route_path`/`RouteTranslator`, rotte admin escluse dall'i18n)*
