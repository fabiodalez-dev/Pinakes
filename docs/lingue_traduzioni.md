# 🌍 Guida a Lingue e Traduzioni

## 🎯 Introduzione

Pinakes è progettato per essere **multilingua**, il che significa che può essere facilmente tradotto in diverse lingue. Questa guida spiega come funziona il sistema di internazionalizzazione (spesso abbreviato in **i18n**).

---

## 📍 Dove si Trovano le Traduzioni

Tutti i file relativi alle lingue si trovano nella cartella `locale/`.

```
locale/
├── en_US.json         # File principale per l'Inglese
├── it_IT.json         # File principale per l'Italiano
├── routes_en_US.json  # Traduzioni per gli URL in Inglese
└── routes_it_IT.json  # Traduzioni per gli URL in Italiano
```

### File Principali (`it_IT.json`, `en_US.json`)

Questi file contengono la maggior parte delle traduzioni. Sono file di tipo **JSON**, che è un formato semplice per associare una "chiave" a un "valore".

**Esempio di `it_IT.json`**:
```json
{
  "dashboard.title": "Pannello di Controllo",
  "books.title": "Libri",
  "books.add_new": "Aggiungi Nuovo Libro",
  "common.save": "Salva",
  "common.delete": "Elimina"
}
```

**Esempio di `en_US.json` corrispondente**:
```json
{
  "dashboard.title": "Dashboard",
  "books.title": "Books",
  "books.add_new": "Add New Book",
  "common.save": "Save",
  "common.delete": "Delete"
}
```

- **Chiave**: `dashboard.title` (identificatore univoco della traduzione)
- **Valore**: `"Pannello di Controllo"` (il testo che viene mostrato all'utente)

Quando l'utente seleziona l'italiano, il sistema userà `it_IT.json`. Se seleziona l'inglese, userà `en_US.json`.

### File delle Rotte (`routes_it_IT.json`, `routes_en_US.json`)

Questi file speciali servono per tradurre gli **URL** (gli indirizzi delle pagine).

**Esempio di `routes_it_IT.json`**:
```json
{
  "books": "libri",
  "loans": "prestiti",
  "settings": "impostazioni"
}
```

**Esempio di `routes_en_US.json`**:
```json
{
  "books": "books",
  "loans": "loans",
  "settings": "settings"
}
```
Questo permette di avere URL localizzati, come:
- `http://tuosito.it/admin/libri` (in italiano)
- `http://tuosito.it/admin/books` (in inglese)

---

## 🔧 Come Funziona nel Codice

Per mostrare un testo tradotto, usiamo una funzione speciale chiamata `i18n()`.

**Esempio nel codice PHP (in una vista)**:
```php
<h1><?= i18n('dashboard.title') ?></h1>

<a href="/admin/books/create" class="button">
  <?= i18n('books.add_new') ?>
</a>
```

**Cosa succede**:
1. La funzione `i18n('dashboard.title')` viene chiamata.
2. Il sistema controlla la lingua attualmente selezionata dall'utente (es. `it_IT`).
3. Apre il file `locale/it_IT.json`.
4. Cerca la chiave `dashboard.title`.
5. Trova il valore `"Pannello di Controllo"` e lo mostra nella pagina.

Se l'utente cambiasse lingua in inglese, la stessa funzione `i18n('dashboard.title')` cercherebbe nel file `en_US.json` e mostrerebbe `"Dashboard"`.

---

## ➕ Come Aggiungere una Nuova Traduzione

Se vuoi aggiungere un nuovo testo traducibile, segui questi 3 semplici passi:

### Passo 1: Scegli una Chiave
Pensa a una chiave **descrittiva e univoca**. La convenzione è `sezione.nome_del_testo`.

**Esempio**: Vuoi tradurre il testo "Cerca per autore".
- **Sezione**: `books` (perché riguarda i libri)
- **Nome**: `search_by_author`
- **Chiave finale**: `books.search_by_author`

### Passo 2: Aggiungi la Chiave ai File JSON
Apri **entrambi** i file `it_IT.json` e `en_US.json` e aggiungi la nuova chiave con la rispettiva traduzione.

**In `it_IT.json`**:
```json
{
  ...
  "books.search_by_author": "Cerca per autore",
  ...
}
```

**In `en_US.json`**:
```json
{
  ...
  "books.search_by_author": "Search by author",
  ...
}
```
> ⚠️ **Importante**: Ricorda di aggiungere una virgola `,` dopo la riga precedente se non è l'ultima del file!

### Passo 3: Usa la Nuova Chiave nel Codice
Ora, nel file della vista dove vuoi mostrare il testo, usa la funzione `i18n()` con la nuova chiave.

**Esempio**:
```php
<label><?= i18n('books.search_by_author') ?></label>
<input type="text" name="author_search">
```

✅ **Fatto!** Il testo ora è traducibile e cambierà automaticamente in base alla lingua dell'utente.

---

## 🌐 Gestire le Lingue

### Come Cambiare Lingua
L'utente può cambiare lingua tramite un selettore presente nell'interfaccia (di solito nell'header o nel footer). Il sistema memorizza la scelta e la applica a tutte le pagine.

### Aggiungere una Nuova Lingua (es. Francese)
Per aggiungere il supporto a una nuova lingua, per esempio il francese (`fr_FR`):

1. **Crea i file di traduzione**:
   - Copia `it_IT.json` e rinominalo in `fr_FR.json`.
   - Copia `routes_it_IT.json` e rinominalo in `routes_fr_FR.json`.

2. **Traduci i valori**:
   - Apri `fr_FR.json` e traduci tutti i valori in francese.
     ```json
     {
       "dashboard.title": "Tableau de Bord",
       "books.title": "Livres"
     }
     ```
   - Apri `routes_fr_FR.json` e traduci gli URL.
     ```json
     {
       "books": "livres",
       "loans": "emprunts"
     }
     ```

3. **Registra la nuova lingua**:
   - Aggiungi la nuova lingua nell'elenco delle lingue supportate (di solito in un file di configurazione come `config/settings.php`).

4. **Testa**: Seleziona la nuova lingua dall'interfaccia e verifica che tutte le traduzioni vengano caricate correttamente.

---

## ❓ Domande Frequenti

**D: Cosa succede se una chiave di traduzione non viene trovata?**
R: Se la funzione `i18n()` non trova una chiave nel file JSON, per evitare errori mostrerà la chiave stessa. Ad esempio, `i18n('common.missing_key')` mostrerebbe `common.missing_key`.

**D: Posso usare variabili nelle traduzioni?**
R: Sì, il sistema supporta i segnaposto.
**Esempio JSON**: `"welcome_message": "Benvenuto, %s!"`
**Esempio PHP**: `sprintf(i18n('welcome_message'), $userName)`

**D: Devo tradurre ogni singola parola?**
R: No, si traducono "stringhe" o frasi intere. Questo rende il contesto più chiaro e la traduzione più naturale. Ad esempio, invece di tradurre "Cerca" e "per" separatamente, si traduce l'intera frase "Cerca per autore".

**D: Perché la convenzione `sezione.nome`?**
R: Aiuta a mantenere i file JSON organizzati e a evitare conflitti tra chiavi con lo stesso nome ma usate in contesti diversi (es. `books.title` e `authors.title`).

---
*Ultimo aggiornamento: 14 Novembre 2025*
*Versione guida: 1.0.0*
