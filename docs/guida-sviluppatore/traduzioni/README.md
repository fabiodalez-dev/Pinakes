# Guida al Sistema di Traduzione (i18n)

Questa guida spiega come funziona il sistema di internazionalizzazione (i18n) in Pinakes e come aggiungere o modificare le traduzioni.

## Indice
- [Struttura dei File di Traduzione](#struttura-dei-file-di-traduzione)
- [Come Utilizzare le Traduzioni nel Codice](#come-utilizzare-le-traduzioni-nel-codice)
- [Aggiungere Nuove Stringhe di Testo](#aggiungere-nuove-stringhe-di-testo)
- [Aggiungere il Supporto per una Nuova Lingua](#aggiungere-il-supporto-per-una-nuova-lingua)

---

## Struttura dei File di Traduzione

Tutti i file di lingua si trovano nella directory `/locale`. Il sistema utilizza file JSON per memorizzare le traduzioni.

-   `/locale/{codice_lingua}.json`: Contiene le traduzioni delle stringhe di testo utilizzate nell'interfaccia.
    -   Esempio: `it_IT.json`, `en_US.json`
-   `/locale/routes_{codice_lingua}.json`: Contiene le traduzioni per gli URL (rotte).
    -   Esempio: `routes_it_IT.json`, `routes_en_US.json`

### Formato dei File

I file JSON sono composti da coppie chiave-valore.

**Esempio di `en_US.json`**:
```json
{
  "dashboard.title": "Dashboard",
  "books.add_new": "Add New Book"
}
```

La **chiave** (`dashboard.title`) è un identificatore unico per una stringa di testo. Il **valore** (`Dashboard`) è la traduzione effettiva.

---

## Come Utilizzare le Traduzioni nel Codice

Per visualizzare un testo tradotto, si utilizza la funzione helper `i18n()`.

**Esempio in una vista PHP**:
```php
<h1><?= i18n('dashboard.title') ?></h1>
```

La funzione `i18n()` determina automaticamente la lingua selezionata dall'utente, carica il file di traduzione corrispondente e restituisce il valore associato alla chiave fornita.

### Variabili nelle Traduzioni

Se una stringa di testo contiene dati dinamici, puoi usare la funzione `sprintf` in combinazione con `i18n()`:

**JSON**:
```json
{
  "books.welcome_user": "Welcome, %s!"
}
```

**PHP**:
```php
echo sprintf(i18n('books.welcome_user'), $nomeUtente);
```

---

## Aggiungere Nuove Stringhe di Testo

1.  **Definisci una Chiave Univoca**: Segui la convenzione `sezione.nome_stringa` per mantenere l'ordine (es. `books.search_placeholder`).
2.  **Aggiungi la Chiave a Tutti i File di Lingua**: Inserisci la nuova chiave in ogni file `{codice_lingua}.json` con la traduzione appropriata.
3.  **Utilizza la Chiave nel Codice**: Chiama `i18n('tua.nuova.chiave')` nel punto in cui vuoi visualizzare il testo.

---

## Aggiungere il Supporto per una Nuova Lingua

1.  **Crea i Nuovi File**:
    -   Copia un file di traduzione esistente (es. `en_US.json`) e rinominalo con il nuovo codice lingua (es. `fr_FR.json`).
    -   Fai lo stesso per il file delle rotte (`routes_fr_FR.json`).
2.  **Traduci i Contenuti**: Apri i nuovi file e traduci tutti i valori nella nuova lingua.
3.  **Registra la Lingua**: Aggiungi la nuova lingua all'elenco delle lingue supportate, che si trova nel file di configurazione principale dell'applicazione. Questo renderà la nuova lingua selezionabile dall'interfaccia utente.
