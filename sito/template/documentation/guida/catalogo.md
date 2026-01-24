# Gestione Catalogo

Guida completa all'inserimento e gestione dei libri nel catalogo Pinakes.

## Inserimento Nuovo Libro

### Accesso

**Percorso**: Catalogo → Nuovo libro

### Ricerca per ISBN

Il metodo più rapido per inserire un libro:

1. Inserisci l'ISBN nel campo **"Codice ISBN o EAN"**
2. Clicca **Importa Dati** (o premi Invio)
3. Il sistema cerca i metadati su:
   - **Google Books**
   - **Open Library**
   - **SBN Italia** (Servizio Bibliotecario Nazionale)
4. Se trovato, i campi vengono precompilati automaticamente
5. Verifica e modifica i dati se necessario
6. Clicca **Salva Libro**

**Fonti Alternative**: Dopo l'importazione, puoi cliccare "Vedi alternative" per vedere dati da altre fonti e scegliere quali usare.

### Inserimento Manuale

Se l'ISBN non restituisce risultati o per libri senza ISBN:

1. Compila il campo obbligatorio **Titolo**
2. Aggiungi gli **Autori** (seleziona esistenti o crea nuovi)
3. Compila gli altri campi rilevanti
4. Carica la **copertina** (opzionale)
5. Clicca **Salva Libro**

---

## Campi del Form Libro

Il form è organizzato in 7 sezioni. Di seguito ogni campo con la sua descrizione.

### Sezione 1: Informazioni Base

| Campo | Descrizione | Obbligatorio |
|-------|-------------|--------------|
| **Titolo** | Titolo principale del libro | Sì |
| **Sottotitolo** | Sottotitolo o titolo secondario | No |
| **ISBN 10** | Codice ISBN a 10 cifre (formato precedente al 2007) | No |
| **ISBN 13** | Codice ISBN a 13 cifre (formato attuale) | No |
| **Edizione** | Numero o descrizione dell'edizione (es. "Prima edizione", "Edizione riveduta") | No |
| **Data di Pubblicazione** | Data originale di pubblicazione in formato italiano (es. "26 agosto 2025") | No |
| **Anno di Pubblicazione** | Anno numerico per filtri e ordinamento (es. 2025) | No |
| **EAN** | European Article Number (spesso coincide con ISBN-13) | No |
| **Lingua** | Lingua/e del libro (es. "Italiano", "Inglese") | No |
| **Editore** | Casa editrice - cerca tra esistenti o digita per creare nuovo | No |
| **Autori** | Uno o più autori - cerca tra esistenti o digita per creare nuovi | No |
| **Disponibilità** | Stato generale del libro (vedi tabella sotto) | Sì |
| **Descrizione** | Trama, sinossi o descrizione del contenuto | No |

#### Valori Disponibilità

| Valore | Significato |
|--------|-------------|
| `Disponibile` | Libro disponibile per il prestito |
| `Non Disponibile` | Non disponibile temporaneamente |
| `Prestato` | Attualmente in prestito |
| `Riservato` | Riservato per un utente |
| `Danneggiato` | Libro danneggiato |
| `Perso` | Libro smarrito |
| `In Riparazione` | In fase di restauro/riparazione |
| `Fuori Catalogo` | Rimosso dal catalogo attivo |
| `Da Inventariare` | In attesa di catalogazione completa |

**Nota**: Lo stato `Disponibilità` è indipendente dagli stati delle copie fisiche. Il sistema calcola automaticamente `copie_disponibili` in base allo stato delle singole copie nella tabella `copie`.

### Sezione 2: Classificazione Dewey

| Campo | Descrizione | Obbligatorio |
|-------|-------------|--------------|
| **Codice Dewey** | Codice di classificazione Dewey Decimal | No |
| **Radice** | Livello principale del genere (es. Prosa, Poesia, Teatro) | No |
| **Genere** | Genere letterario (dipende dalla radice) | No |
| **Sottogenere** | Sottogenere specifico (dipende dal genere) | No |
| **Parole Chiave** | Tag separati da virgole per facilitare la ricerca | No |

#### Classificazione Dewey

Due modalità di inserimento:

**Inserimento diretto:**
1. Digita il codice Dewey nel campo (es. `599.93`, `004.6782`)
2. Clicca **Aggiungi**
3. Il sistema cerca automaticamente il nome corrispondente
4. Il codice appare come chip blu con nome

**Navigazione categorie:**
1. Espandi "Oppure naviga per categorie"
2. Seleziona la classe principale (0-9)
3. Naviga nelle sottocategorie
4. Clicca sul codice desiderato

**Formato accettato:**
- Da 1 a 3 cifre intere: `000` - `999`
- Fino a 4 decimali: `599.9374`
- Esempi: `823`, `813.54`, `641.5945`

**Nota**: Il sistema Dewey di Pinakes contiene 1.287 voci. Puoi inserire qualsiasi codice valido, anche se non presente nell'elenco predefinito.

### Sezione 3: Dettagli Acquisizione

| Campo | Descrizione | Obbligatorio |
|-------|-------------|--------------|
| **Data Acquisizione** | Data in cui il libro è entrato in biblioteca | No |
| **Tipo Acquisizione** | Modalità di acquisizione (es. Acquisto, Donazione, Prestito interbibliotecario) | No |
| **Prezzo (€)** | Prezzo di acquisto o valore stimato | No |

### Sezione 4: Dettagli Fisici

| Campo | Descrizione | Obbligatorio |
|-------|-------------|--------------|
| **Formato** | Tipo di rilegatura (es. Copertina rigida, Brossura, Tascabile) | No |
| **Numero Pagine** | Conteggio pagine del libro | No |
| **Peso (kg)** | Peso in chilogrammi (es. 0.450) | No |
| **Dimensioni** | Formato fisico (es. 21x14 cm) | No |
| **Copie Totali** | Numero di copie fisiche possedute | Sì (default: 1) |

**Nota Copie Totali**: In modalità modifica, non puoi ridurre il numero di copie al di sotto di quelle attualmente in uso (prestato, perso, danneggiato). Il campo `copie_disponibili` viene calcolato automaticamente dal sistema.

### Sezione 5: Gestione Biblioteca

| Campo | Descrizione | Obbligatorio |
|-------|-------------|--------------|
| **Numero Inventario** | Codice identificativo univoco per l'inventario (es. INV-2024-001) | No |
| **Collana** | Nome della collana editoriale (es. "I Classici", "Oscar Mondadori") | No |
| **Numero Serie** | Numero progressivo nella collana | No |
| **File URL** | Link al file digitale (PDF, ePub) se disponibile | No |
| **Audio URL** | Link all'audiolibro se disponibile | No |
| **Note Varie** | Note aggiuntive o osservazioni particolari | No |

### Sezione 6: Copertina del Libro

Upload della copertina tramite drag-and-drop o selezione file.

**Formati supportati**: JPG, PNG, WebP
**Dimensione massima**: 5 MB
**Risoluzione consigliata**: almeno 300x450 pixel

**Funzionalità:**
- Trascina l'immagine nell'area di upload
- Oppure clicca per selezionare il file
- Anteprima immediata dopo selezione
- Pulsante "Rimuovi" per eliminare la copertina esistente

**Copertina da ISBN**: Se disponibile, la copertina viene scaricata automaticamente durante l'importazione ISBN da Google Books o Open Library.

### Sezione 7: Posizione Fisica nella Biblioteca

| Campo | Descrizione | Obbligatorio |
|-------|-------------|--------------|
| **Scaffale** | Scaffale dove si trova il libro | No |
| **Mensola** | Livello/mensola dello scaffale (dipende dallo scaffale) | No |
| **Posizione progressiva** | Numero d'ordine sulla mensola | No |
| **Collocazione calcolata** | Stringa di collocazione generata automaticamente (sola lettura) | - |

**Collocazione automatica:**
- Il sistema genera la collocazione combinando: `[Codice Scaffale]-[Livello Mensola]-[Posizione]`
- Esempio: `A1-L2-015` = Scaffale A1, Livello 2, Posizione 15
- Clicca **Genera automaticamente** per ottenere la prossima posizione disponibile
- Clicca **Suggerisci collocazione** per una proposta basata sulla classificazione Dewey

**Nota**: La posizione fisica è indipendente dalla classificazione Dewey e indica dove il libro si trova fisicamente sugli scaffali.

---

## Gestione Autori

### Selezione Autore Esistente

1. Inizia a digitare il nome nel campo **Autori**
2. Appare un elenco di autori corrispondenti
3. Clicca sull'autore per selezionarlo
4. Appare come chip con pulsante X per rimuovere

### Creazione Nuovo Autore

1. Digita il nome completo dell'autore
2. Se non esiste, appare l'opzione "Aggiungi [nome] come nuovo autore"
3. Clicca per confermare la creazione
4. L'autore viene creato automaticamente al salvataggio del libro

### Normalizzazione Nomi

Il sistema normalizza automaticamente i formati:
- `ROSSI, Mario` → `Mario Rossi`
- `rossi mario` → `Mario Rossi`

### Autori Multipli

Un libro può avere più autori:
1. Seleziona il primo autore
2. Continua a digitare per aggiungere altri
3. Ogni autore appare come chip separato
4. L'ordine degli autori viene preservato

---

## Gestione Editori

### Selezione Editore Esistente

1. Digita nel campo **Editore**
2. Appare un elenco di editori corrispondenti
3. Clicca per selezionare
4. L'editore appare come chip

### Creazione Nuovo Editore

1. Digita il nome dell'editore
2. Se non esiste, il nome viene usato come nuovo editore
3. L'editore viene creato al salvataggio del libro

### Un Solo Editore

A differenza degli autori, un libro può avere un solo editore. Selezionando un nuovo editore, il precedente viene sostituito.

---

## Gestione Copie

Ogni libro può avere più copie fisiche, ciascuna con stato e posizione propri.

### Aggiungere Copie

1. Apri la scheda del libro
2. Vai alla sezione **Copie**
3. Clicca **Aggiungi copia**
4. Specifica:
   - Numero inventario (obbligatorio, univoco)
   - Stato iniziale
   - Note sulla copia
   - Posizione fisica

### Stati Copia

| Stato | Descrizione | Prestabile |
|-------|-------------|------------|
| `disponibile` | Pronta per il prestito | Sì |
| `prestato` | Attualmente in prestito (automatico) | No |
| `prenotato` | Riservata per un prestito futuro (automatico) | No |
| `manutenzione` | Temporaneamente non disponibile | No |
| `in_restauro` | In fase di restauro/riparazione | No |
| `perso` | Copia smarrita | No |
| `danneggiato` | Copia danneggiata non utilizzabile | No |
| `in_trasferimento` | In trasferimento tra sedi | No |

**Stati automatici**: `prestato` e `prenotato` sono gestiti dal sistema prestiti e non possono essere impostati manualmente.

### Eliminazione Copie

Una copia può essere eliminata solo se in stato:
- `perso`
- `danneggiato`
- `manutenzione`

Questo previene l'eliminazione accidentale di copie in uso.

---

## Copertine

### Upload Manuale

1. Nella scheda libro, vai alla sezione **Copertina**
2. Trascina l'immagine nell'area di upload
3. Oppure clicca per selezionare il file
4. L'anteprima appare immediatamente
5. Salva il libro per confermare

### Copertina da ISBN

Durante l'importazione da ISBN, la copertina viene scaricata automaticamente se disponibile:
- **Google Books**: alta risoluzione
- **Open Library**: varie risoluzioni

### Rimozione Copertina

1. Clicca **Rimuovi** sotto l'anteprima
2. La copertina viene eliminata al salvataggio

---

## Import/Export

### Import da CSV

Per importazioni massive:

1. Vai in **Catalogo → Import**
2. Carica il file CSV
3. Mappa le colonne del CSV ai campi del database
4. Verifica l'anteprima dei primi record
5. Conferma l'importazione

**Campi CSV supportati:**
- titolo, sottotitolo
- isbn10, isbn13, ean
- autore (nome)
- editore (nome)
- anno_pubblicazione
- descrizione
- genere
- classificazione_dewey
- numero_pagine
- formato
- lingua

### Export Catalogo

1. Vai in **Catalogo → Export**
2. Seleziona il formato (CSV, JSON)
3. Seleziona i campi da esportare
4. Clicca **Esporta**
5. Il file viene scaricato

---

## Ricerca e Filtri

### Ricerca Rapida

La barra di ricerca cerca in:
- Titolo
- Sottotitolo
- ISBN (10 e 13)
- EAN
- Autori
- Editore

### Filtri Avanzati

| Filtro | Descrizione |
|--------|-------------|
| **Autore** | Filtra per autore specifico |
| **Editore** | Filtra per casa editrice |
| **Genere** | Filtra per genere letterario |
| **Anno** | Intervallo anni pubblicazione |
| **Disponibilità** | Solo libri disponibili/non disponibili |
| **Scaffale** | Filtra per collocazione fisica |
| **Classificazione Dewey** | Filtra per codice o categoria Dewey |

---

## Risoluzione Problemi

### ISBN non trova risultati

1. Verifica che l'ISBN sia corretto (10 o 13 cifre)
2. Prova senza trattini
3. Il libro potrebbe non essere presente nei database online
4. Procedi con l'inserimento manuale

### Copertina non caricata

Possibili cause:
1. File troppo grande (max 5 MB)
2. Formato non supportato (usa JPG, PNG, WebP)
3. Errore di connessione

### Autore/Editore duplicato

Il sistema cerca di prevenire duplicati, ma se ne trovi:
1. Vai in **Gestione → Autori** o **Gestione → Editori**
2. Cerca l'elemento duplicato
3. Usa la funzione **Unisci** per consolidare

### Dewey non trovato

Se il codice Dewey non è nell'elenco:
1. Puoi comunque inserirlo manualmente
2. Il sistema salva qualsiasi codice valido
3. Verrà visualizzato senza nome descrittivo

---

## Domande Frequenti (FAQ)

### 1. Come inserisco un libro che non ha ISBN?

Molti libri antichi, pubblicazioni locali o autoprodotte non hanno ISBN. Per inserirli:

1. Vai in **Catalogo → Nuovo libro**
2. Lascia vuoto il campo ISBN
3. Compila manualmente il campo **Titolo** (obbligatorio)
4. Aggiungi **Autore** e **Editore** se noti
5. Carica una foto della copertina se disponibile
6. Clicca **Salva Libro**

Il libro sarà comunque ricercabile e gestibile come tutti gli altri.

---

### 2. Posso modificare un libro dopo averlo salvato?

Sì, puoi modificare qualsiasi libro in qualsiasi momento:

1. Cerca il libro nel catalogo
2. Clicca sulla scheda del libro
3. Clicca il pulsante **Modifica** (icona matita)
4. Modifica i campi desiderati
5. Clicca **Salva Libro**

**Nota**: Alcuni campi come le copie totali hanno restrizioni se ci sono prestiti attivi.

---

### 3. Come gestisco più copie dello stesso libro?

Pinakes distingue tra il "libro" (record bibliografico) e le "copie" (esemplari fisici):

1. Apri la scheda del libro
2. Nella sezione **Copie**, clicca **Aggiungi copia**
3. Assegna un **Numero Inventario** univoco a ogni copia
4. Ogni copia può avere stato e posizione diversi

**Esempio**: Se hai 3 copie di "Il nome della rosa", hai 1 libro e 3 copie. Ogni copia può essere prestata indipendentemente.

---

### 4. L'ISBN non trova nulla, cosa faccio?

Se la ricerca ISBN non restituisce risultati:

1. **Verifica l'ISBN**: Controlla che sia corretto (10 o 13 cifre, senza spazi)
2. **Prova l'EAN**: Alcuni libri usano EAN invece di ISBN
3. **Fonti limitate**: Google Books, Open Library e SBN potrebbero non avere tutti i libri
4. **Inserisci manualmente**: Compila i campi a mano usando le informazioni dal libro fisico

**Consiglio**: Per libri italiani non trovati, prova a cercare sul sito OPAC SBN (opac.sbn.it) per copiare i metadati.

---

### 5. Come funziona la classificazione Dewey?

La classificazione Dewey organizza i libri per argomento in 10 classi principali (000-999):

| Classe | Argomento |
|--------|-----------|
| 000 | Informatica e opere generali |
| 100 | Filosofia e psicologia |
| 200 | Religione |
| 300 | Scienze sociali |
| 400 | Linguaggio |
| 500 | Scienze naturali |
| 600 | Tecnologia |
| 700 | Arti e sport |
| 800 | Letteratura |
| 900 | Storia e geografia |

**Due modi per inserire**:
- **Diretto**: Digita il codice (es. `853.914` per narrativa italiana contemporanea)
- **Navigazione**: Usa "Naviga per categorie" per esplorare la gerarchia

---

### 6. Come elimino un libro dal catalogo?

Per eliminare un libro:

1. Apri la scheda del libro
2. Clicca **Elimina** (icona cestino)
3. Conferma l'eliminazione

**Attenzione**: Non puoi eliminare un libro se:
- Ha copie attualmente in prestito
- Ha prenotazioni attive

Prima devi chiudere i prestiti e annullare le prenotazioni.

---

### 7. Posso importare libri da un file Excel?

Sì, tramite la funzione Import CSV:

1. Esporta il file Excel in formato CSV
2. Vai in **Catalogo → Import**
3. Carica il file CSV
4. Mappa le colonne ai campi di Pinakes
5. Verifica l'anteprima e conferma

**Campi richiesti**: Almeno il campo `titolo` deve essere presente.

---

### 8. Come trovo rapidamente un libro specifico?

Pinakes offre diverse opzioni di ricerca:

- **Ricerca rapida**: Digita nella barra di ricerca (cerca in titolo, autore, ISBN, editore)
- **Filtri avanzati**: Usa i filtri per autore, editore, genere, anno, scaffale
- **Codice Dewey**: Filtra per classificazione tematica
- **Scansione ISBN**: Se hai uno scanner, scansiona il codice a barre

**Suggerimento**: La ricerca supporta anche ricerche parziali (es. "ros" trova "Rossi" e "La rosa").

---

### 9. La copertina non viene caricata, perché?

Verifica questi punti:

1. **Dimensione file**: Massimo 5 MB
2. **Formato**: Solo JPG, PNG o WebP
3. **Connessione**: Verifica la connessione internet
4. **Permessi**: La cartella `uploads` deve essere scrivibile

**Soluzione alternativa**: Se il caricamento continua a fallire, riduci la dimensione dell'immagine con un editor prima di caricarla.

---

### 10. Come cambio la posizione fisica di un libro?

Per spostare un libro (o una copia) su uno scaffale diverso:

1. Apri la scheda del libro
2. Vai alla sezione **Posizione Fisica**
3. Seleziona il nuovo **Scaffale** e **Mensola**
4. Modifica la **Posizione progressiva** se necessario
5. Clicca **Salva**

Per spostare una singola copia (se hai più copie), modifica la copia specifica dalla sezione Copie.
