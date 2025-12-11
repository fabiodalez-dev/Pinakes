# Guida Completa a Prestiti e Prenotazioni

Questa guida illustra in dettaglio come funzionano il sistema di prestiti, le prenotazioni e il calendario in Pinakes.

## Indice
- [Il Ciclo di Vita di un Prestito](#il-ciclo-di-vita-di-un-prestito)
  - [1. Richiesta da Parte dell'Utente](#1-richiesta-da-parte-dellutente)
  - [2. Approvazione dell'Amministratore](#2-approvazione-dellamministratore)
  - [3. Periodo di Prestito](#3-periodo-di-prestito)
  - [4. Restituzione](#4-restituzione)
  - [5. Rinnovi](#5-rinnovi)
- [Prenotazioni e Coda di Attesa](#prenotazioni-e-coda-di-attesa)
  - [Come Prenotare un Libro Occupato](#come-prenotare-un-libro-occupato)
  - [Funzionamento della Coda](#funzionamento-della-coda)
- [Il Calendario Prestiti (per Amministratori)](#il-calendario-prestiti-per-amministratori)
- [Gestione dal Profilo Utente](#gestione-dal-profilo-utente)

---

## Il Ciclo di Vita di un Prestito

Il sistema gestisce l'intero processo di prestito in modo semi-automatico, richiedendo un intervento minimo da parte dell'amministratore.

### 1. Richiesta da Parte dell'Utente
Un utente registrato e approvato può navigare nel catalogo pubblico. Se un libro è disponibile, vedrà il pulsante **"Richiedi in prestito"**. Cliccandolo, l'utente può specificare un intervallo di date desiderato per il prestito. Questa azione crea una richiesta di prestito con stato **"Pendente"**.

### 2. Approvazione dell'Amministratore
Gli amministratori vedono una notifica per ogni nuova richiesta di prestito nella loro dashboard, nella sezione "Approvazione Prestiti". Qui possono:
- **Approvare la richiesta**: Il sistema verifica che una copia fisica del libro sia effettivamente disponibile per le date richieste. Se sì, assegna una copia specifica al prestito.
  - Se la data di inizio è oggi, il prestito diventa **"In corso"** e lo stato della copia diventa **"Prestato"**.
  - Se la data di inizio è futura, il prestito diventa **"Prenotato"** e lo stato della copia resta **"Disponibile"** fino al giorno di inizio del prestito.
- **Rifiutare la richiesta**: L'amministratore può specificare una motivazione per il rifiuto.

In entrambi i casi, l'utente riceve una notifica via email.

### 3. Periodo di Prestito
Una volta che il prestito è "In corso", il sistema ne monitora la scadenza.
- **Promemoria**: Pochi giorni prima della scadenza, il sistema invia automaticamente un'email di promemoria all'utente.
- **Ritardi**: Se la data di scadenza viene superata e il libro non è stato restituito, lo stato del prestito diventa **"In ritardo"** e vengono inviate notifiche di sollecito.

### 4. Restituzione
Quando l'utente restituisce il libro, l'amministratore deve registrarlo nel sistema:
1.  Trova il prestito attivo nella lista.
2.  Clicca su **"Registra Restituzione"**.
3.  Specifica lo stato finale della copia (es. "Restituito", "Danneggiato", "Perso").
4.  Il prestito viene chiuso e lo stato della copia viene aggiornato (es. torna "Disponibile").

Se una copia torna disponibile, il sistema notifica automaticamente il prossimo utente in coda di prenotazione (se presente).

### 5. Rinnovi
Un utente può richiedere di estendere la durata di un prestito. L'amministratore può approvare il rinnovo (solitamente per un periodo di 14 giorni), a condizione che:
- Il libro non sia in ritardo.
- Non ci siano altre prenotazioni in attesa per quel libro.
- Non sia stato superato il numero massimo di rinnovi consentiti.

---

## Prenotazioni e Coda di Attesa

### Come Prenotare un Libro Occupato
Se tutte le copie di un libro sono attualmente in prestito, l'utente vedrà il pulsante **"Prenota"** o **"Aggiungiti alla Coda"**. Cliccando, l'utente viene inserito in una coda di attesa virtuale.

### Funzionamento della Coda
- **Ordine Cronologico**: La coda è gestita in base all'ordine di arrivo delle richieste.
- **Notifica Automatica**: Non appena una copia del libro viene restituita e diventa "Disponibile", il primo utente nella coda riceve un'email che lo informa della disponibilità.
- **Periodo di Ritiro**: L'utente ha un tempo limitato (configurabile dall'amministratore) per recarsi in biblioteca e ritirare il libro. In questo periodo, il libro è virtualmente "riservato" per lui.
- **Scorrimento della Coda**: Se l'utente non ritira il libro entro il tempo stabilito, perde il suo turno e la notifica viene inviata all'utente successivo nella coda.

---

## Il Calendario Prestiti (per Amministratori)

Il pannello di amministrazione include un calendario visuale che offre una panoramica di tutti i prestiti e le prenotazioni.
- **Vista Mensile/Settimanale**: Mostra tutti gli "eventi" (prestiti e prenotazioni) in corso.
- **Codifica a Colori**:
  - **Blu/Verde**: Prestiti regolari.
  - **Giallo**: Prestiti in scadenza.
  - **Rosso**: Prestiti in ritardo.
  - **Grigio/Arancione**: Prenotazioni future.
- **Dettagli Rapidi**: Cliccando su un evento, l'amministratore può visualizzare i dettagli del prestito/prenotazione e accedere rapidamente alle azioni correlate.

Questo strumento è essenziale per capire la disponibilità futura dei volumi e gestire il flusso di lavoro della biblioteca.

---

## Gestione dal Profilo Utente

Ogni utente, dalla propria area personale, ha il pieno controllo sui propri prestiti e prenotazioni. Può:
- Visualizzare l'elenco dei **prestiti in corso** con le relative date di scadenza.
- Controllare lo **storico dei prestiti** passati.
- Vedere le sue **prenotazioni attive** e la sua posizione in coda per ciascun libro.
- **Annullare** una prenotazione se non è più interessato al libro.
