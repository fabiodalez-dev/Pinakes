# Gestione Libri - Documentazione Ufficiale

## PARTE 1: GUIDA PER L'UTENTE FINALE

### Benvenuto nella Gestione Libri
Questa pagina ti spiega **tutto quello che puoi fare** con i libri della tua biblioteca. Non serve essere tecnici!

### Cosa Puoi Fare Qui

| **Funzione** | **Serve per...** | **Quando usarla** |
|--------------|-------------------|-------------------|
| ** Lista Libri** | Vedere tutti i libri della biblioteca | Ogni giorno |
| ** Ricerca** | Trovare un libro specifico | Quando ti serve |
| ** Aggiungi Libro** | Inserire nuovi libri | Quando acquisti libri |
| ** Modifica** | Cambiare informazioni | Quando trovi errori |
| ** Etichette** | Stampare codici per gli scaffali | Quando organizzi |
| ** Esporta** | Salvare liste in Excel | Per inventari |
| ** Elimina** | Rimuovere libri non più presenti | Con cautela |
| ** Copie** | Gestire più copie dello stesso titolo | Quando hai più esemplari |

---

### Come Entrare

1. **Vai su**: Dashboard → **Libri**
2. **Oppure digita**: `/admin/libri`
3. **Vedrai**: Una grande tabella con tutti i libri!

> **Nota tecnica**: le rotte admin dei libri sono letterali in inglese sotto `/admin/libri/*` e NON passano dal sistema i18n delle rotte (decisione issue #145).

---

### Come Cercare Libri (Facile!)

#### Metodo 1: Ricerca Rapida
- Scrivi **qualunque cosa** nella barra in alto
- Esempi: `Dante`, `Commedia`, `9788842935780`
- **Funziona per**: titolo, autore, ISBN, editore

#### Metodo 2: Filtri Precisi
Clicca su **"Filtri"** per usare:
- **Disponibilità**: Solo libri liberi
- **Autore**: Tutti i libri di uno scrittore
- **Data**: Libri acquistati nel 2024
- **Genere**: Solo narrativa, saggistica, ecc.
- **Etichetta**: Filtra per scaffale o posizione

---

### Leggere la Tabella (Semplice!)

| **Colonna** | **Cosa Significa** | **Esempio** |
|-------------|-------------------|-------------|
|  | Stato del libro | =Libero, =Prestato |
|  | Copertina | Clicca per vedere grande |
|  | Titolo + Autore | `Divina Commedia - Dante` |
|  | Posizione fisica | `A.2.15` = Scaffale A, Mensola 2, Posizione 15 |
|  | Anno | Quando è stato pubblicato |
|  | Azioni | Cosa puoi fare |

---

### Aggiungere un Nuovo Libro

#### Metodo A: Automatico (Consigliato)
1. Clicca **"+ Nuovo Libro"**
2. Trova l'**ISBN** sul retro del libro (13 cifre)
3. Digita l'ISBN e clicca **"Importa"**
4. **Magia!** Il sistema recupera:
   - Titolo completo
   - Autore/i
   - Copertina
   - Descrizione
   - Prezzo
   - Classificazione Dewey (se disponibile)

#### Metodo B: Manuale
1. Clicca **"+ Nuovo Libro"**
2. Compila i campi:
   - **Titolo** (obbligatorio)
   - **Autore** (puoi crearne di nuovi)
   - **Posizione** (scaffale/mensola)
   - **Numero copie** (default 1)
3. Carica copertina (se vuoi)
4. Salva!

>  **Vuoi i dettagli?** Leggi la [Guida Completa Inserimento Libri](/docs/inserimento_libri.MD) con tutti i campi spiegati, lo scraping automatico e 4 esempi pratici.

#### Inserimento Manuale Avanzato
- **Gestione delle copie**: inserisci il numero di copie totali; il sistema calcola le copie disponibili.
- **Gestione dei codici a barre**: se fornisci un EAN/ISBN‑13, il codice a barre viene generato automaticamente.
- **Campi opzionali**: lingua, formato, peso, dimensioni, prezzo di acquisto, data di acquisizione, categoria, genere, sottogenere, collana, serie, numero di serie.

---

### Modificare un Libro Esistente

1. **Trova** il libro nella tabella
2. **Clicca** l'icona  nella riga
3. **Cambia** solo ciò che serve:
   - Titolo sbagliato? Correggi
   - Autore mancante? Aggiungi
   - Posizione cambiata? Aggiorna
   - Copie aggiunte o rimosse? Aggiorna il contatore
4. **Salva** e fatto!

>  **Nota**: Se il libro è in prestito, alcuni campi (es. stato, copie disponibili) sono bloccati per sicurezza.

>  **Vuoi i dettagli?** Leggi la [Guida Modifica Libro](/docs/modifica_libro.MD) con 8 passi completi, 5 casi reali e tutte le soluzioni ai problemi comuni.

---

### Stampare le Etichette

Le etichette servono per **trovare i libri sugli scaffali**.

#### Procedura
1. Clicca sul **titolo del libro** nella tabella
2. Clicca **"Stampa Etichetta"**
3. **PDF** pronto! Contiene:
   - Nome della biblioteca
   - Titolo abbreviato
   - Autore
   - Posizione (es. `A.2.15`)
   - Codice a barre (EAN/ISBN)

#### Formati Disponibili
- **25×38 mm**: Standard per dorso libri
- **50×25 mm**: Orizzontale
- **70×36 mm**: Grande

>  **Vuoi i dettagli?** Leggi la [Guida Stampa Etichette](/docs/stampa_etichette.MD) con come configurare il formato, dove comprare la carta, istruzioni di stampa e come applicarle.

---

### Vedere i Dettagli di un Libro

1. **Clicca** sul titolo del libro
2. **Vedrai**:
   - Copertina grande
   - Tutte le informazioni (ISBN, editore, data, ecc.)
   - Storico prestiti
   - Bottoni per modificare, stampare etichetta, gestire copie

>  **Vuoi i dettagli?** Leggi la [Guida Scheda Libro](/docs/scheda_libro.MD) che spiega le 10 sezioni della scheda, i bottoni disponibili e come navigare tra modifica, stampa e visualizzazione.

---

### Esportare la Lista

1. **Applica** i filtri (se vuoi)
2. **Clicca** i bottoni in alto:
   - **CSV**: Per Excel
   - **PDF**: Per stampare
   - **Stampa**: Diretta su stampante

---

### Eliminare un Libro

>  **Attenzione**: L'eliminazione è un **soft-delete**: il record resta nel database con la colonna `deleted_at` valorizzata e viene nascosto da tutte le viste (ogni query sui libri filtra `AND deleted_at IS NULL`). Al soft-delete il sistema azzera i codici univoci (`isbn10`, `isbn13`, `ean`) e i campi di collocazione (`scaffale_id`, `mensola_id`, `posizione_progressiva`, `collocazione`) per evitare violazioni di unicità su futuri inserimenti.

1. Trova il libro
2. Clicca
3. **Conferma** nel popup

> **Non puoi eliminare** se:
> - Esiste un prestito attivo (`prestiti.attivo = 1` con stato `in_corso`, `da_ritirare`, `prenotato` o `in_ritardo`)
> - Esiste una prenotazione attiva (`prenotazioni.stato = 'attiva'`)
>
> In questi casi il sistema mostra un messaggio e rimanda alla scheda libro: termina prima i prestiti/prenotazioni.
>
> **Rotta**: `POST /admin/libri/delete/{id}` (protetta da `CsrfMiddleware` + `AdminAuthMiddleware`).

---

### Gestione delle Copie

- **Modello fisico**: alla creazione di un libro il sistema genera N righe fisiche nella tabella `copie` (una per ogni *Copie totali*), tramite `CopyRepository::create()`. Ogni copia ha un `numero_inventario` (es. `LIB-{id}` per la copia singola, oppure `{base}-C1`, `{base}-C2`, … per copie multiple) e uno stato (`disponibile` di default).
- **In creazione**: `copie_disponibili` viene forzato uguale a `copie_totali` (tutte le copie partono disponibili). Il valore è limitato all'intervallo 1–9999.
- **Modifica delle copie**: dalla scheda libro è possibile aggiornare/eliminare le singole copie tramite gli endpoint `POST /admin/libri/copie/{id}/update` e `POST /admin/libri/copie/{id}/delete` (gestiti da `CopyController`, protetti da CSRF + AdminAuth).
- **Disponibilità**: il conteggio delle copie disponibili è ricalcolato dal sistema in base a prestiti e prenotazioni attive (vedi `BookRepository`/`recalculateBookAvailability`).

---

### Sicurezza (CSRF, Validazione, Permessi)

#### CSRF Protection
- **Token CSRF**: ogni form di inserimento/modifica include il token generato dal server.
- **Validazione**: il middleware `CsrfMiddleware` verifica il token sulle rotte di scrittura (`POST /admin/libri/crea`, `POST /admin/libri/update/{id}`, `POST /admin/libri/delete/{id}`, gestione copie, ecc.).

#### Validazione dei Dati (server‑side, in `LibriController`)
- **Titolo obbligatorio**: dopo la normalizzazione (rimozione caratteri di controllo MARC‑8 C1 e collasso degli spazi) il titolo non può essere vuoto → risposta `422`.
- **ISBN/EAN**: spazi e trattini vengono rimossi; valori vuoti convertiti a `NULL` per compatibilità con i vincoli UNIQUE; controllo di lunghezza anti‑ReDoS prima delle regex.
- **ISSN**: normalizzato al formato canonico `XXXX-XXXX` (8 cifre, l'ultima può essere `X`); formato non valido → errore.
- **Copie**: `copie_totali` forzato nell'intervallo 1–9999.
- **Duplicati**: prima dell'inserimento il sistema verifica che non esista già un libro non eliminato con lo stesso ISBN-10/ISBN-13/EAN, usando un advisory lock MySQL (`GET_LOCK`) per rendere atomico il controllo+insert. In caso di duplicato risponde `409` con i dati del libro esistente.

#### Controlli di Accesso
- **Ruoli**: il campo `tipo_utente` distingue gli utenti; le rotte admin dei libri sono protette da `AdminAuthMiddleware`, che ammette solo `admin` e `staff`.
- **Rotte API di lettura libri per autore/editore** usano invece `AuthMiddleware(['admin','staff','standard','premium'])`.

---

### Scraping Automatico (ISBN)

Il controller `ScrapeController` recupera metadati a partire da un ISBN/EAN.

1. **Richiesta**: `GET /api/scrape/isbn?isbn=9788842935780`
   - Protetta da `AdminAuthMiddleware` e da `RateLimitMiddleware` (30 richieste al minuto).
2. **Flusso**:
   - Verifica/normalizza il formato ISBN/EAN
   - Interroga le fonti disponibili e fonde i risultati (`BookDataMerger`)
   - Normalizza i campi (titolo, autore, descrizione, copertina, prezzo, ecc.)
   - Restituisce JSON con i campi pronti per popolare il form
3. **Plugin scraping-pro** (e altri plugin di scraping): aggiungono fonti supplementari tramite l'hook `scrape.fetch.custom`. Il form rileva la disponibilità di una fonte custom con `Hooks::has('scrape.fetch.custom')` e mostra il pannello "fonte" con le alternative dopo l'import.

>  **Vuoi i dettagli?** Leggi la sezione *Scraping* nella [Guida Inserimento Libri](/docs/inserimento_libri.MD#scraping-automatico).

---

### Rotte Libri (admin) e API

La creazione/modifica/eliminazione dei libri avviene tramite i form admin, NON tramite un'API REST CRUD JSON. Le rotte effettive sono:

| Metodo | Endpoint | Descrizione |
|--------|----------|-------------|
| `GET` | `/admin/libri` | Lista libri (vista admin) |
| `GET` | `/admin/libri/{id}` | Scheda libro |
| `GET` | `/admin/libri/crea` | Form di creazione |
| `POST` | `/admin/libri/crea` | Salvataggio nuovo libro |
| `GET` | `/admin/libri/modifica/{id}` | Form di modifica |
| `POST` | `/admin/libri/update/{id}` | Salvataggio modifiche |
| `POST` | `/admin/libri/delete/{id}` | Soft-delete |
| `POST` | `/admin/libri/copie/{id}/update` | Aggiorna una copia fisica |
| `POST` | `/admin/libri/copie/{id}/delete` | Elimina una copia fisica |
| `GET` | `/api/scrape/isbn?isbn=...` | Scraping metadati ISBN/EAN (rate-limited) |

Endpoint JSON di sola lettura / supporto al form:

| Metodo | Endpoint | Descrizione |
|--------|----------|-------------|
| `GET` | `/api/libri` | Lista libri JSON (DataTables, admin) |
| `GET` | `/api/libri/author/{id}` | Libri di un autore |
| `GET` | `/api/libri/publisher/{id}` | Libri di un editore |
| `GET` | `/api/libri/genre/{id}` | Libri per genere (pubblico, caroselli home) |
| `POST` | `/api/libri/bulk-status` | Cambio stato in blocco (admin) |
| `POST` | `/api/libri/bulk-delete` | Soft-delete in blocco (admin) |
| `POST` | `/api/libri/{id}/increase-copies` | Aggiunge copie (admin) |
| `GET` | `/api/autori`, `/api/editori`, `/api/generi`, `/api/generi/sottogeneri` | Liste per autocomplete e cascade generi |
| `GET` | `/api/dewey/categories\|divisions\|specifics\|children\|search\|path` | Classificazione Dewey (cascade + percorso) |

---

### Import/Export Bulk

#### Importazione (CSV/TSV, LibraryThing)
- **UI**: `GET /admin/libri/import` (esempio scaricabile da `/admin/libri/import/example`)
- **Upload**: `POST /admin/libri/import/upload`, elaborazione a chunk via `POST /admin/libri/import/chunk` con progress su `GET /admin/libri/import/progress`
- **LibraryThing**: rotte dedicate sotto `/admin/libri/import/librarything/*` (disponibili con il plugin LibraryThing)

#### Esportazione
- **CSV**: `GET /admin/libri/export/csv` (supporta `?ids=` per esportare solo i libri selezionati — issue #77)
- **LibraryThing**: `GET /admin/libri/export/librarything`

#### Copertine in blocco / arricchimento
- **Sync copertine**: `POST /admin/libri/sync-covers`
- **Bulk enrich** (scraping di massa, max ~20 per richiesta): `GET /admin/libri/bulk-enrich`, `POST /admin/libri/bulk-enrich/start`, `POST /admin/libri/bulk-enrich/toggle`

---

## INDICE COMPLETO DELLE GUIDE

| **Operazione** | **Guida Completa** | **Tempo di Lettura** |
|----------------|-------------------|---------------------|
|  Aggiungere un libro | [Guida Inserimento Libri](/docs/inserimento_libri.MD) | 15 min |
|  Modificare un libro | [Guida Modifica Libro](/docs/modifica_libro.MD) | 10 min |
|  Visualizzare i dettagli | [Guida Scheda Libro](/docs/scheda_libro.MD) | 8 min |
|  Stampare etichette | [Guida Stampa Etichette](/docs/stampa_etichette.MD) | 10 min |
|  Organizzare scaffali | [Guida Collocazione](/docs/collocazione.MD) | 12 min |
|  Gestire prestiti | [Guida Prestiti](/docs/prestiti.MD) | 10 min |
|  Import/Export CSV | [Guida Import/Export](/docs/inserimento_libri.MD#bulk-import-export) | 8 min |
|  Scraping ISBN | [Guida Scraping](/docs/inserimento_libri.MD#scraping-automatico) | 7 min |
|  Sicurezza e CSRF | [Guida Sicurezza](/docs/settings.MD#csrf-protection) | 6 min |

### Argomenti Approfonditi

- **Scraping Automatico**: Come il sistema recupera automaticamente i dati da ISBN/EAN → vedi [Guida Inserimento Libri - Sezione Scraping](/docs/inserimento_libri.MD#scraping-automatico)
- **Classificazione Dewey**: Come classificare i libri per argomento → vedi [Guida Inserimento Libri - Sezione Dewey](/docs/inserimento_libri.MD#dewey-classification)
- **Impostazioni Etichette**: Come configurare formati e tipo di carta → vedi [Guida Stampa Etichette - Sezione Configurazione](/docs/stampa_etichette.MD#configurazione-etichette)
- **CSRF & Permessi**: Dettagli tecnici su token, middleware e ruoli → vedi [Guida Sicurezza](/docs/settings.MD#csrf-protection)

---

## Quick Start per Utente Finale

**Vuoi aggiungere subito un libro?**
1. Vai a **Dashboard → Libri**
2. Clicca **"+ Nuovo Libro"**
3. Digita l'ISBN e clicca **"Importa"** (o compila manualmente)
4. Assegna la posizione fisica
5. Clicca **"Salva"**
6.  Fatto! → Stampa etichetta e applica

>  Se hai dubbi, consulta una delle guide correlate qui sopra!

---

*Ultimo aggiornamento: 4 Giugno 2026*
*Versione documentazione: 4.2.0*
*Versione guide correlate: 3.2.0*
