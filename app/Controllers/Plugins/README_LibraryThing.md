# Plugin LibraryThing per Pinakes

## Descrizione

Plugin opzionale per importare ed esportare libri in formato LibraryThing.com (TSV - Tab Separated Values).

Questo plugin **non modifica** il sistema di import/export CSV esistente, ma lo estende aggiungendo supporto per il formato specifico di LibraryThing.

## Caratteristiche

### Import da LibraryThing
- ✅ **Formato TSV** (Tab-separated values) - standard di LibraryThing
- ✅ **Mappatura automatica** delle colonne LibraryThing ai campi Pinakes
- ✅ **Supporto multi-autore** (Primary Author + Secondary Author)
- ✅ **Parsing intelligente** di ISBN (gestisce sia ISBN10 che ISBN13 dalla colonna ISBNs)
- ✅ **Integrazione con scraping** - arricchisce i dati mancanti (copertine, descrizioni, etc.)
- ✅ **Gestione duplicati** - aggiorna automaticamente i libri esistenti per ISBN
- ✅ **Creazione automatica** di autori ed editori mancanti
- ✅ **Progress tracking** con feedback in tempo reale
- ✅ **Validazione dati** con report errori dettagliati

### Export per LibraryThing
- ✅ **Formato TSV** compatibile con LibraryThing
- ✅ **Tutti i campi** supportati da LibraryThing (55+ colonne)
- ✅ **Conversione automatica** dei formati (es. "cartaceo" → "Libro cartaceo")
- ✅ **Mappatura lingue** (italiano → Italian, inglese → English, etc.)
- ✅ **Filtri** - esporta solo i libri desiderati (per autore, editore, genere, etc.)
- ✅ **Performance ottimizzate** - gestisce migliaia di libri senza problemi di memoria

## Campi Supportati

### Import LibraryThing → Pinakes

| Campo LibraryThing | Campo Pinakes | Note |
| --- | --- | --- |
| Book Id | id | ID univoco |
| Title | titolo | **Obbligatorio** |
| Sort Character | sottotitolo | Sottotitolo/carattere ordinamento |
| Primary Author | autori | Primo autore |
| Secondary Author | autori | Secondo autore (unito con `\|`) |
| Publication | editore | Estratto da "Publisher, Year" |
| Date | anno_pubblicazione | Anno di pubblicazione |
| ISBNs | isbn13, isbn10 | Parse automatico ISBN13 e ISBN10 |
| Barcode | ean | Codice a barre EAN |
| Languages | lingua | Convertito in italiano (Italian → italiano) |
| Page Count | numero_pagine | Numero di pagine |
| Summary | descrizione | Descrizione del libro |
| Media | formato | Convertito (Libro cartaceo → cartaceo) |
| Subjects | genere | Prima categoria/soggetto |
| Tags | parole_chiave | Keywords/tags |
| Collections | collana | Collezioni/collane |
| Dewey Decimal | classificazione_dewey | Classificazione Dewey |
| List Price / Purchase Price | prezzo | Prezzo |
| Copies | copie_totali | Numero di copie |

### Export Pinakes → LibraryThing

Tutti i campi standard di LibraryThing sono supportati (55 colonne), inclusi:
- Metadati base (Title, Author, ISBN, etc.)
- Informazioni fisiche (Weight, Height, Dimensions)
- Gestione prestiti (Lending Patron, Lending Status)
- Date (Acquired, Date Started, Date Read)
- Prezzi (List Price, Purchase Price, Value)
- Classificazioni (Dewey, LC Classification, LCCN)
- E molti altri...

## Come Usare

### Import da LibraryThing

1. **Esporta da LibraryThing**:
   - Vai su LibraryThing.com → La tua biblioteca
   - Clicca su "More" → "Export"
   - Seleziona formato "**Tab-delimited text**"
   - Scarica il file

2. **Importa in Pinakes**:
   - Vai su Libri → Import → "LibraryThing TSV"
   - Carica il file .tsv scaricato
   - (Opzionale) Abilita scraping per arricchire i dati
   - Clicca "Importa Libri"

3. **Risultati**:
   - Vedrai un report con: libri importati, aggiornati, autori creati, editori creati
   - Eventuali errori verranno mostrati in dettaglio

### Export per LibraryThing

1. **Esporta da Pinakes**:
   - Vai su Libri
   - Clicca su "Export" → "LibraryThing TSV"
   - (Opzionale) Applica filtri prima dell'export
   - Il file .tsv verrà scaricato

2. **Importa in LibraryThing**:
   - Vai su LibraryThing.com → La tua biblioteca
   - Clicca su "More" → "Import"
   - Carica il file .tsv esportato da Pinakes

## Scraping Integration

Il plugin supporta lo **scraping automatico** per arricchire i dati mancanti:

- **Cosa viene arricchito**: Copertine, descrizioni, prezzi, numero pagine, Dewey, anno, lingua, keywords
- **Priorità**: I dati dal CSV LibraryThing hanno sempre la priorità (non vengono sovrascritti)
- **Limiti**: Massimo 50 libri con scraping, timeout 5 minuti
- **Rate limiting**: 3 secondi tra ogni richiesta
- **Retry logic**: 5 tentativi con exponential backoff

## Architettura

Il plugin è completamente **separato** dal sistema di import/export esistente:

```text
app/Controllers/Plugins/
  └── LibraryThingController.php    # Controller principale del plugin

app/Views/plugins/
  └── librarything_import.php        # UI di import

app/Routes/web.php                    # Route del plugin (separate)
```

### Route Plugin

- `GET /admin/libri/import/librarything` - Pagina di import
- `POST /admin/libri/import/librarything/process` - Processo di import
- `GET /admin/libri/import/librarything/progress` - Progress tracking (AJAX)
- `GET /admin/libri/export/librarything` - Export TSV

## Sicurezza

- ✅ **CSRF Protection** - Token CSRF su tutti i form
- ✅ **Authentication** - Solo admin possono importare/esportare
- ✅ **Rate Limiting** - 10 req/min per import, 30 req/min per operazioni bulk
- ✅ **File Validation** - Solo .tsv, .csv, .txt accettati
- ✅ **DoS Prevention** - Max 10,000 righe per import
- ✅ **SQL Injection** - Prepared statements ovunque
- ✅ **XSS Protection** - Sanitizzazione output
- ✅ **Bounds Checking** - Max 100 copie per libro

## Performance

- **Streaming** - I file vengono processati in streaming senza caricarli completamente in memoria
- **Memory Limit** - Stream buffer di 5MB per grandi export
- **Garbage Collection** - Ogni 1000 righe per prevenire memory leak
- **Database Transactions** - Ogni libro è in una transazione separata (rollback automatico in caso di errore)

## Compatibilità

### Sistema Esistente
- ✅ **Non invasivo** - Non modifica il CSV import esistente
- ✅ **Retrocompatibile** - Il sistema esistente continua a funzionare normalmente
- ✅ **Codice separato** - Plugin in namespace dedicato `App\Controllers\Plugins`

### Formati Supportati
- ✅ TSV (Tab-separated values) - Standard LibraryThing
- ✅ CSV con TAB delimiter
- ✅ File .tsv, .csv, .txt

### Database
- ✅ Utilizza le stesse tabelle esistenti (libri, autori, editori, generi)
- ✅ Riutilizza i repository esistenti (BookRepository, AuthorRepository, etc.)
- ✅ Integrazione con DataIntegrity per ricalcolo disponibilità

## Limitazioni

1. **Autori multipli**: Solo i primi 2 autori (Primary + Secondary) vengono importati
2. **Generi**: Solo il primo soggetto/categoria viene mappato al genere
3. **Scraping**: Limitato a 50 libri e 5 minuti per prevenire timeout
4. **Campi LibraryThing non mappati**: Review, Rating, Comment, OCLC, Work ID, etc. (non rilevanti per Pinakes)

## Troubleshooting

### Import non funziona
- Verifica che il file sia in formato TSV (Tab-delimited) non CSV
- Controlla che ci siano le colonne richieste: `Book Id`, `Title`, `ISBNs`
- Verifica i permessi del file (deve essere leggibile)
- Controlla i log di errore nella risposta dell'import

### Caratteri strani (encoding)
- LibraryThing esporta in UTF-8 con BOM - il plugin lo gestisce automaticamente
- Se ci sono problemi, verifica che il file sia UTF-8

### Timeout durante import
- Disabilita lo scraping se importi molti libri
- Dividi il file in parti più piccole (max 10,000 righe)
- Aumenta il timeout di PHP se necessario (attualmente 5 minuti)

## Sviluppo Futuro

Possibili miglioramenti:
- [ ] Supporto per MARC21 XML (standard bibliografico)
- [ ] Import di tutti gli autori (non solo 2)
- [ ] Mapping generi/categorie personalizzabile
- [ ] Export selettivo di campi (scegli quali colonne esportare)
- [ ] Progress bar più dettagliato con ETA
- [ ] Log import scaricabile

## Contribuire

Per segnalare bug o suggerire miglioramenti, aprire una issue su GitHub.

## Licenza

Stesso della licenza di Pinakes.

---

**Versione**: 1.0.0
**Autore**: Pinakes Development Team
**Data**: 2026-01-30
