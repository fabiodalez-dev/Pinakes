# Wishlist (Lista Desideri)

La wishlist permette agli utenti di salvare libri di interesse per richiederli in futuro.

## Panoramica

Il sistema wishlist permette di:
- Aggiungere libri alla lista desideri
- Visualizzare la disponibilità di ogni libro
- Vedere la prossima data disponibile per libri in prestito
- Rimuovere libri dalla lista

## Aggiungere alla Wishlist

### Da Scheda Libro

1. Vai alla scheda del libro
2. Clicca il pulsante **Aggiungi alla wishlist** (icona cuore)
3. Il libro viene aggiunto alla tua lista

### Toggle Atomico

Il sistema usa un pattern **toggle atomico** per gestire le race condition:

```php
// UserWishlistController::toggle()
// Prima elimina (se esiste), poi inserisce (se era assente)
DELETE FROM wishlist WHERE utente_id = ? AND libro_id = ?

// Se il libro non era in wishlist, lo aggiunge
INSERT INTO wishlist (utente_id, libro_id) VALUES (?, ?)
```

Questo garantisce che cliccando rapidamente più volte non si creino duplicati.

## Visualizzare la Wishlist

### Accesso

- **Menu utente → La mia wishlist**

### Informazioni Mostrate

Per ogni libro nella wishlist:

| Campo | Descrizione |
|-------|-------------|
| **Titolo** | Titolo del libro |
| **Autore** | Autore/i del libro |
| **Disponibilità** | Disponibile / Non disponibile |
| **Prossima data** | Data in cui sarà disponibile (se in prestito) |

### Calcolo Disponibilità

Il sistema verifica se esistono copie fisiche prestabili:

```php
// Verifica se il libro ha copie disponibili
SELECT COUNT(*) > 0 as has_actual_copy
FROM copie
WHERE libro_id = ?
  AND stato NOT IN ('perso', 'danneggiato', 'in_trasferimento')
```

### Calcolo Prossima Data

Se il libro non è disponibile, mostra quando sarà:

```php
// Trova la data di fine prestito più vicina
SELECT MIN(data_fine) as next_available
FROM prestiti
WHERE libro_id = ?
  AND stato IN ('in_corso', 'in_ritardo')
  AND data_fine IS NOT NULL
```

## Rimuovere dalla Wishlist

### Da Scheda Libro

1. Vai alla scheda del libro
2. Clicca il pulsante **Rimuovi dalla wishlist** (icona cuore pieno)

### Dalla Lista Wishlist

1. Vai alla tua wishlist
2. Clicca **Rimuovi** accanto al libro

## Verifica Stato Wishlist

### Controllo Singolo Libro

L'API verifica se un libro specifico è nella wishlist:

```
GET /api/wishlist/status/{libro_id}
```

Risposta:
```json
{
  "in_wishlist": true
}
```

## Tabella Database

```sql
CREATE TABLE `wishlist` (
  `id` int NOT NULL AUTO_INCREMENT,
  `utente_id` int NOT NULL,
  `libro_id` int NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `utente_libro` (`utente_id`, `libro_id`),
  KEY `libro_id` (`libro_id`),
  CONSTRAINT `wishlist_utente_fk` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wishlist_libro_fk` FOREIGN KEY (`libro_id`) REFERENCES `libri` (`id`) ON DELETE CASCADE
);
```

### Vincolo Unicità

La combinazione `(utente_id, libro_id)` è unica: un utente non può avere lo stesso libro due volte in wishlist.

## Sicurezza

### Protezioni

- **Autenticazione**: solo utenti loggati possono usare la wishlist
- **Autorizzazione**: ogni utente vede solo la propria wishlist
- **CSRF**: token richiesto per operazioni di modifica
- **Validazione**: libro_id verificato prima dell'inserimento

### Controllo Accesso

```php
// Solo utenti autenticati
$user = getLoggedUser();
if (!$user) {
    return redirect('/login');
}

// La wishlist appartiene sempre all'utente corrente
$wishlists = getWishlistByUser($user['id']);
```

## API Endpoints

| Endpoint | Metodo | Descrizione |
|----------|--------|-------------|
| `/wishlist` | GET | Lista wishlist utente |
| `/wishlist/toggle/{libro_id}` | POST | Aggiungi/rimuovi libro |
| `/api/wishlist/status/{libro_id}` | GET | Verifica se libro in wishlist |

## Risoluzione Problemi

### Libro non si aggiunge

Verifica:
1. Sei loggato
2. Il libro esiste nel catalogo
3. Non hai già il libro in wishlist (in questo caso verrà rimosso)

### Disponibilità non mostrata

Controlla:
1. Il libro ha copie registrate nel sistema
2. Le copie non sono tutte in stato `perso` o `danneggiato`

### Data disponibilità non appare

Possibili cause:
1. Tutte le copie sono disponibili (nessun prestito attivo)
2. I prestiti attivi non hanno data_fine impostata

---

## Domande Frequenti (FAQ)

### 1. Quanti libri posso aggiungere alla wishlist?

Non c'è un limite tecnico. Puoi aggiungere tutti i libri che desideri:
- Ogni libro può essere aggiunto una sola volta
- Cliccando di nuovo si rimuove dalla wishlist

---

### 2. La wishlist si cancella dopo un certo tempo?

No, la wishlist è permanente:
- I libri restano finché non li rimuovi manualmente
- Anche se il libro viene eliminato dal catalogo, scompare automaticamente

---

### 3. Posso condividere la mia wishlist con altri?

No, la wishlist è personale e privata:
- Solo tu puoi vedere la tua lista
- Non esiste un link pubblico di condivisione
- Gli operatori non vedono le wishlist degli utenti

---

### 4. Se aggiungo un libro alla wishlist, vengo notificato quando è disponibile?

Attualmente no. La wishlist è una lista di promemoria personale:
- Devi controllare manualmente la disponibilità
- Le notifiche automatiche sono associate solo alle **prenotazioni**

Per essere notificato, usa la funzione **Prenota** invece della wishlist.

---

### 5. Posso richiedere un prestito direttamente dalla wishlist?

Sì:
1. Vai alla tua wishlist
2. Clicca sul titolo del libro
3. Si apre la scheda libro
4. Clicca **Richiedi prestito**

La wishlist ti aiuta a tenere traccia, ma la richiesta va fatta dalla scheda libro.

---

### 6. La wishlist mostra la disponibilità in tempo reale?

Sì:
- **Disponibile**: almeno una copia libera
- **Non disponibile**: tutte le copie in prestito
- **Prossima data**: quando sarà disponibile (se in prestito)

I dati si aggiornano ad ogni caricamento della pagina wishlist.

---

### 7. Cosa succede se un libro in wishlist viene eliminato dal catalogo?

Il libro scompare automaticamente dalla tua wishlist:
- La foreign key con `ON DELETE CASCADE` rimuove il record
- Non ricevi notifica dell'eliminazione

---

### 8. Posso ordinare la wishlist?

Attualmente l'ordine è cronologico (ultimi aggiunti per primi). Non è possibile riordinare manualmente i libri.

---

### 9. La wishlist funziona senza login?

No, la wishlist richiede login:
- Se non sei loggato, il pulsante "Aggiungi alla wishlist" ti reindirizza al login
- Dopo il login, puoi completare l'operazione

---

### 10. Come esporto la mia wishlist?

Non esiste una funzione di export integrata. Puoi:
- Fare uno screenshot della pagina
- Copiare manualmente i titoli
- Chiedere all'admin un export database (per casi speciali)
