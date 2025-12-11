# Guida alla Catalogazione: Collocazione e Dewey

Questa guida approfondisce gli strumenti a disposizione dell'amministratore per organizzare il catalogo in modo sia fisico (Collocazione) che logico (Classificazione Dewey).

## Indice
- [Collocazione Fisica: Organizzare la Biblioteca](#collocazione-fisica-organizzare-la-biblioteca)
  - [La Logica: Scaffali, Mensole e Posizioni](#la-logica-scaffali-mensole-e-posizioni)
  - [Come Configurare la Struttura](#come-configurare-la-struttura)
  - [Assegnare una Collocazione a un Libro](#assegnare-una-collocazione-a-un-libro)
- [Classificazione Dewey (Sistema a JSON)](#classificazione-dewey-sistema-a-json)
  - [Come Funziona l'Interfaccia](#come-funziona-linterfaccia)
  - [Vantaggi del Sistema Guidato](#vantaggi-del-sistema-guidato)

---

## Collocazione Fisica: Organizzare la Biblioteca

La sezione **Amministrazione > Collocazione** ti permette di replicare la struttura fisica della tua biblioteca nel sistema.

### La Logica: Scaffali, Mensole e Posizioni

Il sistema si basa su una gerarchia a tre livelli:

1.  **Scaffali**: Sono i contenitori principali. Ogni scaffale è identificato da un **Codice** (es. "A", "NARR", "S1") e un **Nome** descrittivo (es. "Narrativa Straniera", "Saggistica", "Scaffale 1").
2.  **Mensole**: Sono i ripiani all'interno di ogni scaffale. Ogni mensola è definita da un **Numero di Livello** (1, 2, 3...).
3.  **Posizione Progressiva**: È il numero che indica la posizione del libro su una mensola, contando da sinistra a destra. **Questa viene calcolata automaticamente dal sistema**.

Il risultato è un codice di collocazione semplice e intuitivo, come `A.1.15`.

### Come Configurare la Struttura

Nella pagina "Collocazione", troverai due pannelli principali:

-   **Gestione Scaffali**:
    -   **Aggiungi**: Crea un nuovo scaffale inserendo Codice e Nome.
    -   **Riordina**: Trascina gli scaffali per cambiare l'ordine di visualizzazione.
    -   **Elimina**: Puoi eliminare uno scaffale solo se non contiene mensole o libri.
-   **Gestione Mensole**:
    -   **Seleziona uno scaffale** dal menu a tendina.
    -   **Aggiungi** le mensole specificando il numero di livello.

### Assegnare una Collocazione a un Libro

Quando aggiungi o modifichi un libro, nella sezione "Collocazione Fisica":
1.  **Scegli lo Scaffale e la Mensola** dai menu a tendina.
2.  **Lascia vuoto il campo "Posizione Progressiva"**: Il sistema troverà automaticamente il primo posto libero su quella mensola e lo assegnerà al libro.
3.  **Forzare una Posizione**: Se necessario, puoi inserire un numero specifico nel campo "Posizione Progressiva". Il sistema verificherà se la posizione è già occupata.

In fondo alla pagina "Collocazione" troverai anche una tabella con l'elenco di tutti i libri già posizionati, con la possibilità di filtrarli per scaffale o mensola e di esportare l'elenco in CSV.

---

## Classificazione Dewey (Sistema a JSON)

Pinakes semplifica l'applicazione della Classificazione Decimale Dewey grazie a un sistema interattivo basato su file JSON.

### Come Funziona l'Interfaccia

Quando modifichi un libro, nel campo "Classificazione Dewey", non devi digitare un codice a memoria. Troverai invece una serie di menu a tendina a cascata:

1.  **Classe Principale**: Seleziona una delle 10 classi principali (es. "800 - Letteratura").
2.  **Divisione**: Il secondo menu si popolerà con le divisioni di quella classe (es. "850 - Letteratura italiana").
3.  **Sezione**: Il terzo menu mostrerà le sezioni della divisione scelta (es. "853 - Narrativa italiana").

Man mano che selezioni, il sistema costruisce il codice Dewey corretto per te.

### Vantaggi del Sistema Guidato

-   **Nessun Errore di Digitazione**: Elimina la possibilità di inserire codici inesistenti.
-   **Facile da Usare**: Non è richiesta una conoscenza mnemonica dell'intero sistema Dewey.
-   **Multilingua**: Le descrizioni delle categorie sono tradotte automaticamente in base alla lingua dell'interfaccia.
-   **Suggerimenti Automatici**: In fase di scraping tramite ISBN, il sistema spesso riesce a suggerire una classificazione Dewey, che potrai poi confermare o perfezionare.
