# Sistema Completo di 60 Hooks per Pinakes

Questo documento descrive tutti i 60 hooks strategici disponibili nel sistema Pinakes, progettati per rendere l'applicazione estremamente estensibile e personalizzabile tramite plugin.

## Indice dei Hooks per Categoria

### 📚 LIBRO (Book) - 15 Hooks
### 👤 UTENTE (User) - 10 Hooks
### 📖 PRESTITO (Loan) - 8 Hooks
### ⭐ RECENSIONE (Review) - 5 Hooks
### 🔖 PRENOTAZIONE (Reservation) - 5 Hooks
### 🔍 RICERCA (Search) - 5 Hooks
### 📥 IMPORT/EXPORT - 4 Hooks
### 🌐 API & SCRAPING - 8 Hooks

---

## 📚 LIBRO (Book) - 15 Hooks

### 1. `book.create.before` (Action)
**Quando**: Prima della creazione di un nuovo libro
**Parametri**: `[$bookData]`
**Caso d'uso**: Validazione dati, normalizzazione campi, generazione automatica codici

### 2. `book.create.after` (Action)
**Quando**: Dopo la creazione di un nuovo libro
**Parametri**: `[$bookId, $bookData]`
**Caso d'uso**: Notifiche, sincronizzazione esterna, aggiornamento cache

### 3. `book.update.before` (Action)
**Quando**: Prima dell'aggiornamento di un libro
**Parametri**: `[$bookId, $newData, $oldData]`
**Caso d'uso**: Validazione modifiche, backup dati precedenti

### 4. `book.update.after` (Action)
**Quando**: Dopo l'aggiornamento di un libro
**Parametri**: `[$bookId, $newData, $oldData]`
**Caso d'uso**: Notifiche modifiche, sincronizzazione

### 5. `book.delete.before` (Action)
**Quando**: Prima dell'eliminazione di un libro
**Parametri**: `[$bookId, $bookData]`
**Caso d'uso**: Controlli dipendenze, backup

### 6. `book.delete.after` (Action)
**Quando**: Dopo l'eliminazione di un libro
**Parametri**: `[$bookId]`
**Caso d'uso**: Pulizia cache, notifiche

### 7. `book.data.get` (Filter)
**Quando**: Quando vengono recuperati i dati di un libro
**Parametri**: `[$bookData, $bookId]`
**Ritorna**: `$bookData` modificato
**Caso d'uso**: Arricchimento dati, privacy filtering

### 8. `book.data.list` (Filter)
**Quando**: Quando viene recuperata una lista di libri
**Parametri**: `[$booksList, $filters, $pagination]`
**Ritorna**: `$booksList` modificata
**Caso d'uso**: Ordinamento personalizzato, filtri aggiuntivi

### 9. `book.cover.upload` (Filter)
**Quando**: Prima del salvataggio di una copertina
**Parametri**: `[$imageData, $bookId]`
**Ritorna**: `$imageData` modificato
**Caso d'uso**: Ridimensionamento, watermark, compressione

### 10. `book.cover.delete` (Action)
**Quando**: Dopo l'eliminazione di una copertina
**Parametri**: `[$bookId, $coverPath]`
**Caso d'uso**: Pulizia CDN, backup

### 11. `book.form.fields` (Action)
**Quando**: Durante il rendering del form libro
**Parametri**: `[$bookData, $bookId]`
**Caso d'uso**: Aggiunta campi personalizzati

### 12. `book.validate.isbn` (Filter)
**Quando**: Validazione ISBN
**Parametri**: `[$isValid, $isbn]`
**Ritorna**: `boolean`
**Caso d'uso**: Validazione personalizzata ISBN

### 13. `book.availability.check` (Filter)
**Quando**: Controllo disponibilità libro
**Parametri**: `[$isAvailable, $bookId]`
**Ritorna**: `boolean`
**Caso d'uso**: Logica disponibilità personalizzata

### 14. `book.duplicate.check` (Filter)
**Quando**: Controllo duplicati
**Parametri**: `[$isDuplicate, $bookData]`
**Ritorna**: `boolean`
**Caso d'uso**: Algoritmi anti-duplicati personalizzati

### 15. `book.metadata.enrich` (Filter)
**Quando**: Arricchimento metadati
**Parametri**: `[$metadata, $bookId]`
**Ritorna**: `$metadata` arricchiti
**Caso d'uso**: Aggiunta dati da fonti esterne

---

## 👤 UTENTE (User) - 10 Hooks

### 16. `user.register.before` (Action)
**Quando**: Prima della registrazione utente
**Parametri**: `[$userData]`
**Caso d'uso**: Validazione email domain, filtri anti-spam

### 17. `user.register.after` (Action)
**Quando**: Dopo la registrazione utente
**Parametri**: `[$userId, $userData]`
**Caso d'uso**: Email benvenuto, notifiche admin

### 18. `user.login.validate` (Filter)
**Quando**: Validazione credenziali login
**Parametri**: `[$isValid, $username, $password]`
**Ritorna**: `boolean`
**Caso d'uso**: 2FA, blocco IP, rate limiting

### 19. `user.login.success` (Action)
**Quando**: Login avvenuto con successo
**Parametri**: `[$userId, $userData]`
**Caso d'uso**: Log accessi, notifiche sicurezza

### 20. `user.login.failed` (Action)
**Quando**: Login fallito
**Parametri**: `[$username, $reason]`
**Caso d'uso**: Log tentativi, blocco IP

### 21. `user.logout` (Action)
**Quando**: Logout utente
**Parametri**: `[$userId]`
**Caso d'uso**: Log attività, pulizia sessioni

### 22. `user.profile.update` (Action)
**Quando**: Aggiornamento profilo
**Parametri**: `[$userId, $newData, $oldData]`
**Caso d'uso**: Validazione modifiche, notifiche

### 23. `user.password.change` (Action)
**Quando**: Cambio password
**Parametri**: `[$userId]`
**Caso d'uso**: Email notifica, log sicurezza

### 24. `user.role.change` (Action)
**Quando**: Cambio ruolo utente
**Parametri**: `[$userId, $newRole, $oldRole]`
**Caso d'uso**: Notifiche permessi, log admin

### 25. `user.delete` (Action)
**Quando**: Eliminazione utente
**Parametri**: `[$userId, $userData]`
**Caso d'uso**: Backup dati, GDPR compliance

---

## 📖 PRESTITO (Loan) - 8 Hooks

### 26. `loan.create.before` (Action)
**Quando**: Prima della creazione prestito
**Parametri**: `[$bookId, $userId, $dueDate]`
**Caso d'uso**: Controlli disponibilità, limiti utente

### 27. `loan.create.after` (Action)
**Quando**: Dopo la creazione prestito
**Parametri**: `[$loanId, $bookId, $userId]`
**Caso d'uso**: Email conferma, notifiche

### 28. `loan.extend.before` (Action)
**Quando**: Prima del rinnovo prestito
**Parametri**: `[$loanId, $newDueDate]`
**Caso d'uso**: Controlli limiti rinnovi

### 29. `loan.extend.after` (Action)
**Quando**: Dopo il rinnovo prestito
**Parametri**: `[$loanId, $oldDueDate, $newDueDate]`
**Caso d'uso**: Email conferma rinnovo

### 30. `loan.return.before` (Action)
**Quando**: Prima della restituzione
**Parametri**: `[$loanId, $bookId, $userId]`
**Caso d'uso**: Controllo stato libro, multe

### 31. `loan.return.after` (Action)
**Quando**: Dopo la restituzione
**Parametri**: `[$loanId, $bookId, $userId, $returnDate]`
**Caso d'uso**: Email conferma, disponibilità prenotazioni

### 32. `loan.overdue.check` (Filter)
**Quando**: Controllo scadenza prestito
**Parametri**: `[$isOverdue, $loanId, $dueDate]`
**Ritorna**: `boolean`
**Caso d'uso**: Logica scadenza personalizzata

### 33. `loan.reminder.send` (Action)
**Quando**: Invio promemoria scadenza
**Parametri**: `[$loanId, $userId, $dueDate, $daysLeft]`
**Caso d'uso**: Canali notifica personalizzati (SMS, push)

---

## ⭐ RECENSIONE (Review) - 5 Hooks

### 34. `review.create.before` (Action)
**Quando**: Prima della creazione recensione
**Parametri**: `[$bookId, $userId, $rating, $content]`
**Caso d'uso**: Moderazione automatica, filtri spam

### 35. `review.create.after` (Action)
**Quando**: Dopo la creazione recensione
**Parametri**: `[$reviewId, $bookId, $userId]`
**Caso d'uso**: Notifiche, aggiornamento rating medio

### 36. `review.moderate` (Filter)
**Quando**: Moderazione recensione
**Parametri**: `[$content, $userId, $bookId]`
**Ritorna**: `$content` moderato
**Caso d'uso**: Filtro parole offensive, sentiment analysis

### 37. `review.delete` (Action)
**Quando**: Eliminazione recensione
**Parametri**: `[$reviewId, $bookId]`
**Caso d'uso**: Ricalcolo rating, notifiche

### 38. `review.rating.calculate` (Filter)
**Quando**: Calcolo rating medio
**Parametri**: `[$averageRating, $bookId, $reviews]`
**Ritorna**: `float`
**Caso d'uso**: Algoritmi rating ponderati

---

## 🔖 PRENOTAZIONE (Reservation) - 5 Hooks

### 39. `reservation.create.before` (Action)
**Quando**: Prima della creazione prenotazione
**Parametri**: `[$bookId, $userId]`
**Caso d'uso**: Controlli disponibilità, limiti

### 40. `reservation.create.after` (Action)
**Quando**: Dopo la creazione prenotazione
**Parametri**: `[$reservationId, $bookId, $userId]`
**Caso d'uso**: Email conferma, coda prenotazioni

### 41. `reservation.cancel` (Action)
**Quando**: Cancellazione prenotazione
**Parametri**: `[$reservationId, $bookId, $userId]`
**Caso d'uso**: Notifica utenti in coda

### 42. `reservation.notify` (Action)
**Quando**: Libro disponibile per prenotazione
**Parametri**: `[$reservationId, $bookId, $userId]`
**Caso d'uso**: Notifiche multi-canale

### 43. `reservation.expire` (Action)
**Quando**: Scadenza prenotazione
**Parametri**: `[$reservationId, $bookId, $userId]`
**Caso d'uso**: Passaggio prossimo in coda

---

## 🔍 RICERCA (Search) - 5 Hooks

### 44. `search.query.before` (Filter)
**Quando**: Prima della ricerca
**Parametri**: `[$query, $filters]`
**Ritorna**: `[$query, $filters]`
**Caso d'uso**: Normalizzazione query, suggerimenti

### 45. `search.results` (Filter)
**Quando**: Risultati ricerca
**Parametri**: `[$results, $query, $filters]`
**Ritorna**: `$results`
**Caso d'uso**: Ranking personalizzato, highlights

### 46. `search.autocomplete` (Filter)
**Quando**: Suggerimenti autocomplete
**Parametri**: `[$suggestions, $query]`
**Ritorna**: `$suggestions`
**Caso d'uso**: ML-based suggestions, personalizzazione

### 47. `search.facets` (Filter)
**Quando**: Generazione facet/filtri
**Parametri**: `[$facets, $query]`
**Ritorna**: `$facets`
**Caso d'uso**: Filtri dinamici personalizzati

### 48. `search.log` (Action)
**Quando**: Log ricerca utente
**Parametri**: `[$query, $userId, $resultsCount]`
**Caso d'uso**: Analytics, raccomandazioni

---

## 📥 IMPORT/EXPORT - 4 Hooks

### 49. `import.validate` (Filter)
**Quando**: Validazione file import
**Parametri**: `[$isValid, $fileData, $format]`
**Ritorna**: `boolean`
**Caso d'uso**: Validazione formati custom

### 50. `import.process.row` (Filter)
**Quando**: Elaborazione riga import
**Parametri**: `[$rowData, $rowNumber]`
**Ritorna**: `$rowData`
**Caso d'uso**: Mapping campi, trasformazioni

### 51. `import.complete` (Action)
**Quando**: Completamento import
**Parametri**: `[$importedCount, $errors, $source]`
**Caso d'uso**: Report, notifiche

### 52. `export.generate` (Filter)
**Quando**: Generazione export
**Parametri**: `[$data, $format, $filters]`
**Ritorna**: `$data`
**Caso d'uso**: Formati export custom

---

## 🌐 API & SCRAPING - 8 Hooks

### 53. `scrape.isbn.validate` (Filter)
**Quando**: Validazione ISBN per scraping
**Parametri**: `[$isValid, $isbn]`
**Ritorna**: `boolean`

### 54. `scrape.sources` (Filter)
**Quando**: Lista sorgenti scraping
**Parametri**: `[$sources, $isbn]`
**Ritorna**: `$sources`

### 55. `scrape.fetch.custom` (Filter)
**Quando**: Fetch dati custom
**Parametri**: `[$data, $sources, $isbn]`
**Ritorna**: `$data`

### 56. `scrape.parse` (Filter)
**Quando**: Parsing dati scraped
**Parametri**: `[$parsedData, $rawData, $source]`
**Ritorna**: `$parsedData`

### 57. `scrape.validate.data` (Filter)
**Quando**: Validazione dati scraped
**Parametri**: `[$isValid, $data, $source]`
**Ritorna**: `boolean`

### 58. `scrape.data.modify` (Filter)
**Quando**: Modifica dati scraped
**Parametri**: `[$data, $isbn, $source]`
**Ritorna**: `$data`

### 59. `scrape.response` (Filter)
**Quando**: Modifica risposta scraping
**Parametri**: `[$response, $isbn, $sources, $metadata]`
**Ritorna**: `$response`

### 60. `scrape.error` (Action)
**Quando**: Errore durante scraping
**Parametri**: `[$error, $isbn, $source]`
**Caso d'uso**: Log errori, fallback

---

## Priorità degli Hooks

- **1-4**: Massima priorità (eseguiti per primi)
- **5-10**: Alta priorità
- **11-20**: Priorità normale (default)
- **21-50**: Bassa priorità
- **51-100**: Minima priorità (eseguiti per ultimi)

## Esempio di Utilizzo

```php
// In un plugin
class MyPlugin {
    public function __construct(mysqli $db, HookManager $hookManager) {
        // Hook con priorità alta (eseguito prima di Open Library)
        Hooks::add('scrape.fetch.custom', [$this, 'fetchFromMyApi'], 3);

        // Hook con priorità normale
        Hooks::add('book.create.after', [$this, 'onBookCreated'], 10);
    }

    public function fetchFromMyApi($data, $sources, $isbn) {
        // Logica custom
        return $enrichedData;
    }
}
```

## Note Importanti

1. **Filter Hooks** devono sempre ritornare un valore
2. **Action Hooks** non ritornano valori
3. La priorità determina l'ordine di esecuzione
4. Hook con stessa priorità vengono eseguiti in ordine di registrazione
5. I plugin possono registrare hooks multipli sullo stesso evento
