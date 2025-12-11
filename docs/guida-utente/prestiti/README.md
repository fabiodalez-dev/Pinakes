# Sistema di Prestiti, Calendario e Prenotazioni

Questa guida illustra il funzionamento del sistema di prestiti di Pinakes, pensato per essere semplice e intuitivo sia per gli utenti che per i bibliotecari.

## Indice
- [Come Funziona il Prestito](#come-funziona-il-prestito)
- [Il Calendario dei Prestiti](#il-calendario-dei-prestiti)
- [La Coda di Prenotazione](#la-coda-di-prenotazione)
- [Interfaccia Utente (UI/UX)](#interfaccia-utente-uiux)

---

## Come Funziona il Prestito

Il processo di prestito è progettato per essere il più automatizzato possibile.

1.  **Richiesta da Parte dell'Utente**: L'utente naviga nel catalogo online e, una volta trovato un libro di suo interesse, clicca sul pulsante "Richiedi in Prestito".
2.  **Approvazione da Parte dell'Amministratore**: L'amministratore riceve una notifica della richiesta. Dalla sua dashboard, può approvare o rifiutare la richiesta con un solo click.
3.  **Notifica all'Utente**: Una volta approvato il prestito, il sistema invia automaticamente un'email di conferma all'utente.
4.  **Tracciamento della Scadenza**: La data di scadenza del prestito viene registrata e monitorata dal sistema.
5.  **Promemoria Automatici**: Il sistema invia promemoria via email all'utente qualche giorno prima della scadenza.
6.  **Gestione dei Ritardi**: Se un libro non viene restituito in tempo, il sistema invia notifiche di ritardo.
7.  **Restituzione**: Quando il libro viene restituito, l'amministratore lo segna come "Restituito" nel sistema, rendendolo di nuovo disponibile per altri utenti.

È anche possibile configurare sanzioni per i ritardi e permettere il rinnovo dei prestiti.

---

## Il Calendario dei Prestiti

Il calendario è uno strumento visuale a disposizione degli amministratori per avere una panoramica chiara di tutti i prestiti.

-   **Vista Mensile**: Mostra tutti i libri in prestito e le relative scadenze.
-   **Codifica a Colori**:
    -   **Verde**: Prestiti in corso, non ancora vicini alla scadenza.
    -   **Giallo**: Prestiti in scadenza a breve.
    -   **Rosso**: Prestiti scaduti e non ancora restituiti.
-   **Dettagli Rapidi**: Passando il mouse sopra un evento nel calendario, è possibile visualizzare i dettagli del prestito, come il nome dell'utente e il titolo del libro.

---

## La Coda di Prenotazione

Se un libro è attualmente in prestito, gli utenti possono mettersi in coda per prenotarlo.

1.  **Aggiunta alla Coda**: Dalla pagina del libro, l'utente può cliccare su "Prenota" o "Aggiungiti alla Coda".
2.  **Posizione in Coda**: L'utente viene informato della sua posizione nella coda di attesa.
3.  **Notifica di Disponibilità**: Non appena il libro viene restituito, il primo utente nella coda riceve una notifica via email che lo informa che il libro è ora disponibile per il prestito.
4.  **Tempo per il Ritiro**: L'utente ha un periodo di tempo limitato (configurabile dall'amministratore) per ritirare il libro. Se non lo fa, il libro viene offerto all'utente successivo nella coda.

---

## Interfaccia Utente (UI/UX)

L'interfaccia è stata progettata per rendere il processo di prestito e prenotazione il più semplice possibile.

-   **Stato del Libro**: La pagina di dettaglio di ogni libro mostra chiaramente il suo stato: "Disponibile", "In Prestito", o "Disponibile su Prenotazione".
-   **Pulsanti Contestuali**:
    -   Se il libro è disponibile, l'utente vedrà il pulsante "Richiedi in Prestito".
    -   Se il libro è in prestito, il pulsante diventerà "Prenota" o "Aggiungiti alla Coda".
-   **Area Personale**: Ogni utente ha un'area personale dove può visualizzare:
    -   I prestiti attualmente in corso e le loro scadenze.
    -   Lo storico dei prestiti passati.
    -   Le prenotazioni attive e la propria posizione in coda.
