# Guida allo Sviluppo di Plugin per Pinakes

Questa guida è destinata agli sviluppatori che desiderano estendere le funzionalità di Pinakes attraverso il sistema di plugin.

## Indice
- [Introduzione al Sistema di Plugin](#introduzione-al-sistema-di-plugin)
- [Struttura di un Plugin](#struttura-di-un-plugin)
- [Il Sistema di Hook](#il-sistema-di-hook)
  - [Tipi di Hook: Action e Filter](#tipi-di-hook-action-e-filter)
  - [Registrare un Hook](#registrare-un-hook)
  - [Priorità di Esecuzione](#priorità-di-esecuzione)
- [Elenco degli Hook Disponibili](#elenco-degli-hook-disponibili)
  - [Hook per i Libri](#hook-per-i-libri)
  - [Hook per l'Autenticazione](#hook-per-l-autenticazione)
  - [Hook per lo Scraping](#hook-per-lo-scraping)
  - [Altri Hook](#altri-hook)
- [Best Practices](#best-practices)

---

## Introduzione al Sistema di Plugin

Il sistema di plugin di Pinakes ti permette di aggiungere nuove funzionalità o modificare quelle esistenti senza alterare il codice sorgente del core. Questo rende i tuoi aggiornamenti più facili da mantenere e meno soggetti a conflitti con le future versioni di Pinakes.

## Struttura di un Plugin

Un plugin è una directory contenente i file necessari al suo funzionamento. La struttura di base è la seguente:

```
/nome-plugin
|-- plugin.json
|-- wrapper.php
|-- NomePlugin.php
|-- /views
|-- /assets
```

-   `plugin.json`: Un file di metadati che descrive il plugin (nome, versione, autore, ecc.).
-   `wrapper.php`: Il file principale che viene eseguito per caricare il plugin.
-   `NomePlugin.php`: La classe principale del plugin, dove viene implementata la logica.
-   `/views`: Directory per i file HTML/PHP delle viste.
-   `/assets`: Directory per i file CSS, JavaScript e immagini.

---

## Il Sistema di Hook

Gli "hook" (ganci) sono punti specifici nel codice di Pinakes in cui un plugin può "agganciarsi" per eseguire il proprio codice.

### Tipi di Hook: Action e Filter

-   **Action**: Esegue una funzione in un punto specifico. Non restituisce alcun valore. Utile per, ad esempio, inviare una notifica dopo che un libro è stato salvato.
-   **Filter**: Modifica un dato prima che venga utilizzato o salvato. Riceve un valore, lo modifica e lo restituisce. Utile per, ad esempio, aggiungere un campo personalizzato ai dati di un libro.

### Registrare un Hook

Per registrare un hook, si utilizza la funzione `Hooks::add()`:

```php
Hooks::add('nome_hook', 'tua_funzione_callback', priorita, numero_argomenti);
```

-   `nome_hook`: Il nome dell'hook a cui ti stai agganciando.
-   `tua_funzione_callback`: La funzione che verrà eseguita.
-   `priorita` (opzionale): Un numero intero che determina l'ordine di esecuzione (un numero più basso viene eseguito prima). Il valore predefinito è 10.
-   `numero_argomenti` (opzionale): Il numero di argomenti che la tua funzione accetta.

### Priorità di Esecuzione

Se più plugin si agganciano allo stesso hook, la priorità determina quale viene eseguito per primo. L'ordine di esecuzione va dal numero di priorità più basso al più alto.

---

## Elenco degli Hook Disponibili

Di seguito è riportato un elenco parziale ma significativo degli hook disponibili.

### Hook per i Libri

-   `book.data.get` (Filter): Modifica i dati di un libro quando vengono letti dal database.
-   `book.save.before` (Action): Eseguito prima di salvare un libro.
-   `book.save.after` (Action): Eseguito dopo aver salvato un libro.
-   `book.form.fields` (Action): Aggiunge campi personalizzati al form di modifica del libro.

**Esempio**:
```php
Hooks::add('book.form.fields', function($bookData, $bookId) {
    echo '<label>Campo Personalizzato</label><input type="text" name="custom_field">';
});
```

### Hook per l'Autenticazione

-   `login.form.fields` (Action): Aggiunge campi al form di login (es. per un reCAPTCHA).
-   `login.validate` (Filter): Aggiunge una logica di validazione personalizzata al login.
-   `login.success` (Action): Eseguito dopo un login riuscito.
-   `login.failed` (Action): Eseguito dopo un tentativo di login fallito.

### Hook per lo Scraping

-   `scrape.sources` (Filter): Aggiunge nuove fonti per lo scraping dei dati dei libri.
-   `scrape.parse` (Filter): Permette di implementare una logica di parsing personalizzata per i dati grezzi ottenuti dallo scraping.
-   `scrape.data.modify` (Filter): Modifica i dati ottenuti dallo scraping prima che vengano restituiti.

### Altri Hook

Ci sono molti altri hook disponibili per estendere quasi ogni aspetto di Pinakes, inclusa la gestione degli autori, degli editori, dei prestiti e altro ancora.

---

## Best Practices

-   **Sii Specifico**: Dai ai tuoi plugin un prefisso unico per evitare conflitti con altri plugin.
-   **Controlla le Prestazioni**: Un codice inefficiente in un hook può rallentare l'intera applicazione.
-   **Gestisci gli Errori**: Implementa una gestione robusta degli errori per evitare di bloccare il flusso normale dell'applicazione.
-   **Documenta il Tuo Codice**: Commenta il tuo codice e fornisci un file `README.md` con il tuo plugin.
