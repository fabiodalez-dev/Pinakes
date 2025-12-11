# Personalizzazione del Sito: Temi e Contenuti

Questa guida spiega come gli amministratori possono personalizzare l'aspetto e i contenuti del sito pubblico della biblioteca.

## Indice
- [Gestione dei Temi](#gestione-dei-temi)
  - [Attivazione di un Tema](#attivazione-di-un-tema)
  - [Personalizzazione dei Colori](#personalizzazione-dei-colori)
  - [CSS e JS Personalizzati](#css-e-js-personalizzati)
- [Gestione della Home Page (CMS)](#gestione-della-home-page-cms)
  - [Modifica dei Contenuti delle Sezioni](#modifica-dei-contenuti-delle-sezioni)
  - [Riordinare le Sezioni](#riordinare-le-sezioni)
  - [Attivare e Disattivare le Sezioni](#attivare-e-disattivare-le-sezioni)
- [Creazione di Pagine Personalizzate](#creazione-di-pagine-personalizzate)
- [Gestione degli Eventi](#gestione-degli-eventi)

---

## Gestione dei Temi

Pinakes offre un sistema di temi flessibile per controllare l'aspetto grafico del sito.

### Attivazione di un Tema
1.  Vai su **Amministrazione > Temi**.
2.  Vedrai un elenco dei temi disponibili.
3.  Clicca sul pulsante **"Attiva"** sotto il tema che desideri utilizzare. Il sito pubblico verrà immediatamente aggiornato con il nuovo aspetto.

### Personalizzazione dei Colori
Ogni tema può essere personalizzato.
1.  Clicca su **"Personalizza"** sotto il tema attivo.
2.  Si aprirà una pagina dove potrai modificare i colori principali del sito, come:
    -   Colore primario (per pulsanti, link e accenti)
    -   Colore del testo
    -   Colore di sfondo
3.  Il sistema include un **controllo del contrasto** che ti avviserà se i colori scelti per testo e sfondo non sono sufficientemente leggibili, aiutandoti a mantenere il sito accessibile.
4.  Salva le modifiche per applicarle. Puoi anche ripristinare i colori predefiniti del tema in qualsiasi momento.

### CSS e JS Personalizzati
Nella pagina di personalizzazione, troverai anche delle aree di testo per inserire **CSS e JavaScript personalizzati**. Questo permette agli utenti più esperti di aggiungere stili o funzionalità specifiche senza dover modificare i file del tema.

---

## Gestione della Home Page (CMS)

La home page è interamente gestibile da **Amministrazione > CMS > Modifica Home Page**.

### Modifica dei Contenuti delle Sezioni
La pagina è divisa in pannelli che corrispondono alle varie sezioni della home (Hero, Features, Ultimi Arrivi, etc.). Per ogni sezione, puoi:
-   Modificare **titoli, sottotitoli e testi**.
-   Cambiare l'**immagine di sfondo** (nella sezione Hero).
-   Modificare il **testo e il link dei pulsanti**.
-   Riempire i **campi SEO** (titolo, descrizione, parole chiave) per ottimizzare la visibilità della pagina sui motori di ricerca.

### Riordinare le Sezioni
Puoi cambiare l'ordine in cui le sezioni appaiono sulla home page semplicemente **trascinandole** su o giù nella pagina di modifica del CMS.

### Attivare e Disattivare le Sezioni
Ogni sezione ha un interruttore **"Attiva/Disattiva"**. Se non vuoi mostrare una certa sezione (ad esempio, non hai eventi in programma), puoi disattivarla temporaneamente senza perderne il contenuto.

---

## Creazione di Pagine Personalizzate

Puoi creare pagine statiche aggiuntive (come "Chi Siamo", "Regolamento", "Contatti") in modo molto semplice.
1.  Vai su **Amministrazione > CMS > Pagine**.
2.  Crea una nuova pagina.
3.  Utilizza l'editor di testo per inserire il contenuto, formattare il testo e aggiungere immagini.
4.  Assegna un **URL (slug)** alla pagina (es. `chi-siamo`).
5.  Una volta salvata, la pagina sarà accessibile all'indirizzo `tuosito.it/chi-siamo`. Puoi poi aggiungere manualmente il link a questa pagina nel menu di navigazione del sito.

---

## Gestione degli Eventi

Se la funzionalità eventi è attiva, puoi gestire gli eventi da **Amministrazione > Eventi**.
-   **Crea un evento**: Inserisci titolo, descrizione, data, ora e un'immagine in evidenza.
-   **Visibilità**: Gli eventi attivi e futuri appariranno automaticamente nella sezione "Eventi" della home page e nella pagina archivio di tutti gli eventi, ordinati per data.
