# Guida Completa alla Gestione dei Libri

Questa guida copre in dettaglio ogni aspetto della gestione dei libri in Pinakes, dall'inserimento alla stampa delle etichette.

## Indice
- [Aggiungere un Nuovo Libro](#aggiungere-un-nuovo-libro)
  - [Metodo 1: Automatico via ISBN (Consigliato)](#metodo-1-automatico-via-isbn-consigliato)
  - [Metodo 2: Inserimento Manuale](#metodo-2-inserimento-manuale)
- [Il Form di Inserimento/Modifica in Dettaglio](#il-form-di-inserimentomodifica-in-dettaglio)
  - [Informazioni Principali](#informazioni-principali)
  - [Dettagli Pubblicazione](#dettagli-pubblicazione)
  - [Classificazione](#classificazione)
  - [Collocazione Fisica](#collocazione-fisica)
  - [Dati Amministrativi e Copie](#dati-amministrativi-e-copie)
  - [Descrizione e Media](#descrizione-e-media)
- [Modificare un Libro Esistente](#modificare-un-libro-esistente)
- [La Scheda Libro](#la-scheda-libro)
- [Gestione delle Copie Multiple](#gestione-delle-copie-multiple)
- [Stampa delle Etichette](#stampa-delle-etichette)
- [Azioni Bulk (di Massa)](#azioni-bulk-di-massa)

---

## Aggiungere un Nuovo Libro

### Metodo 1: Automatico via ISBN (Consigliato)

Questo è il metodo più rapido e preciso per aggiungere un libro.

1.  **Vai alla Sezione Libri**: Dal menu di amministrazione, clicca su "Libri".
2.  **Clicca su "Aggiungi Libro"**: Troverai il pulsante in alto a destra.
3.  **Inserisci l'ISBN**: Nel campo "Scraping tramite ISBN", inserisci il codice a 10 o 13 cifre del libro (solitamente si trova sul retro, vicino al codice a barre) e clicca "Recupera Dati".
4.  **Attendi il Caricamento**: Il sistema interrogherà diverse fonti online (come Open Library e altri database) per recuperare automaticamente tutte le informazioni del libro.
5.  **Verifica e Completa**: In pochi secondi, i campi del form verranno pre-compilati. Il tuo compito è:
    -   Verificare che i dati recuperati (titolo, autore, etc.) siano corretti.
    -   Compilare i campi specifici della tua biblioteca, come la **Collocazione** e il **Numero di Inventario**.
    -   Impostare il numero di **Copie Totali**.
6.  **Salva**: Clicca su "Salva" in fondo alla pagina.

### Metodo 2: Inserimento Manuale

Usa questo metodo per libri antichi, senza ISBN, o se la ricerca automatica non ha dato risultati.

1.  **Apri il Form**: Segui i primi due passaggi del metodo automatico.
2.  **Ignora lo Scraping**: Lascia vuoto il campo ISBN e compila manualmente tutti i campi del form.
3.  **Carica la Copertina**: Puoi caricare un'immagine della copertina dal tuo computer.
4.  **Salva**: Una volta compilati tutti i dati necessari, clicca su "Salva".

---

## Il Form di Inserimento/Modifica in Dettaglio

Il form è diviso in sezioni logiche per facilitare la compilazione.

### Informazioni Principali
-   **Titolo** e **Sottotitolo**: I titoli principali del libro.
-   **Autori**: Puoi selezionare uno o più autori esistenti dalla lista. Se un autore non è presente, puoi crearlo al volo semplicemente digitandone il nome e premendo Invio.
-   **ISBN-13, ISBN-10, EAN**: I codici identificativi del libro. È importante inserire almeno uno di questi per una corretta catalogazione.

### Dettagli Pubblicazione
-   **Editore**: Simile agli autori, puoi selezionare un editore esistente o crearne uno nuovo.
-   **Anno di Pubblicazione**: L'anno della prima pubblicazione dell'opera.
-   **Data di Pubblicazione**: La data esatta di questa specifica edizione.
-   **Lingua**, **Edizione**, **Traduttore**.

### Classificazione
-   **Genere** e **Sottogenere**: Seleziona le categorie letterarie. Il sistema è gerarchico: scegliendo un genere, la lista dei sottogeneri si aggiornerà di conseguenza.
-   **Classificazione Dewey**: Invece di inserire un codice numerico, puoi navigare un menu a cascata che ti guida dalla categoria generale a quella più specifica, rendendo la classificazione semplice anche per chi non conosce a fondo il sistema Dewey.
-   **Parole Chiave**: Aggiungi tag per migliorare la ricercabilità del libro nel catalogo.

### Collocazione Fisica
-   **Scaffale** e **Mensola**: Seleziona dove verrà posizionato fisicamente il libro.
-   **Posizione Progressiva**: Puoi lasciare questo campo vuoto: il sistema calcolerà automaticamente la prima posizione libera su quella mensola. Se invece vuoi forzare una posizione specifica, puoi inserirla qui.
-   **Collocazione (Testo Libero)**: Usa questo campo solo se la tua biblioteca non usa il sistema a scaffali e mensole e preferisci inserire un codice di collocazione manuale (es. "N.INF.12").

### Dati Amministrativi e Copie
-   **Numero Inventario**: Il codice univoco assegnato dalla biblioteca a quel libro. Se hai più copie, puoi usare un numero base (es. "INV-2024-100") e il sistema aggiungerà un suffisso per ogni copia (es. "-C1", "-C2").
-   **Copie Totali**: Il numero di copie fisiche di questo libro che possiedi. Il sistema creerà automaticamente un record per ogni copia.
-   **Data e Tipo di Acquisizione**: Traccia come e quando il libro è entrato a far parte della collezione (Acquisto, Donazione, etc.).
-   **Prezzo**.

### Descrizione e Media
-   **Descrizione**: La trama o il riassunto del libro.
-   **Note Varie**: Un campo libero per qualsiasi informazione aggiuntiva.
-   **Copertina**: Puoi caricare un'immagine o, se lo scraping ha fornito un URL, vedrai un'anteprima. Il sistema scaricherà automaticamente l'immagine e la salverà localmente per maggiore velocità e affidabilità.
-   **File URL / Audio URL**: Se stai catalogando un ebook o un audiolibro, puoi inserire qui i link diretti ai file.

---

## Modificare un Libro Esistente

Per modificare un libro, cercalo nella tabella principale della sezione "Libri" e clicca sull'icona a forma di matita. Verrai portato allo stesso form di inserimento, ma pre-compilato con i dati attuali del libro. Apporta le modifiche e clicca su "Salva".

---

## La Scheda Libro

Cliccando sul titolo di un libro nella tabella, accederai alla sua **Scheda Libro**. Questa pagina riassume tutte le informazioni del volume e offre una panoramica completa, inclusi:
-   **Dettagli principali** e copertina.
-   **Stato attuale** (Disponibile, In prestito, etc.).
-   **Elenco di tutte le copie fisiche**, con il loro stato e numero di inventario.
-   **Cronologia dei prestiti** per quel libro.
-   **Prenotazioni attive**.
-   Pulsanti per azioni rapide come **Modifica** e **Stampa Etichetta**.

---

## Gestione delle Copie Multiple

Pinakes gestisce i libri come **opere uniche** e le **copie fisiche** come istanze di quell'opera.

-   Quando imposti "Copie Totali" a `3`, il sistema crea un record per il libro (l'opera) e tre record separati per le copie, ognuno con il proprio stato (`disponibile`, `in prestito`, etc.) e numero di inventario.
-   Questo ti permette di tracciare individualmente ogni copia fisica, sapere chi ha in prestito una specifica copia e gestire i danni o le perdite in modo granulare.
-   Puoi aggiungere o rimuovere copie modificando il campo "Copie Totali" nella pagina di modifica del libro. **Attenzione**: puoi ridurre il numero di copie solo se ci sono abbastanza copie disponibili (non in prestito o danneggiate).

---

## Stampa delle Etichette

Dalla **Scheda Libro**, puoi stampare un'etichetta adesiva professionale.
1.  Clicca su **"Stampa Etichetta"**.
2.  Verrà generato un PDF nel formato che hai pre-configurato nelle Impostazioni.
3.  Il PDF conterrà:
    -   Nome della biblioteca
    -   Titolo e Autore (abbreviati se necessario)
    -   La **collocazione** esatta (es. `A.1.15`)
    -   Un **codice a barre** basato sull'ISBN/EAN.

---

## Azioni Bulk (di Massa)

Dalla tabella principale dei libri, puoi selezionare più libri usando le checkbox e applicare azioni di massa, come:
-   **Cambiare lo stato** a più libri contemporaneamente.
-   **Eliminare** più libri in una sola operazione (solo se non hanno prestiti attivi).
