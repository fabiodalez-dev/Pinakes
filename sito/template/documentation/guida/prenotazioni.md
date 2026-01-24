# Sistema Prenotazioni

Il sistema prenotazioni gestisce la coda d'attesa per libri non immediatamente disponibili.

## Panoramica

Il sistema permette di:
- Richiedere un libro specificando le date desiderate
- Verificare la disponibilità futura
- Gestire automaticamente la coda FIFO
- Notificare gli utenti quando il libro diventa disponibile

## Due Tipologie di Richiesta

Il sistema distingue tra due origini per le richieste di prestito:

| Origine | Descrizione |
|---------|-------------|
| `richiesta` | Richiesta manuale dell'utente tramite catalogo |
| `prenotazione` | Conversione automatica da coda prenotazioni |

## Flusso di Prenotazione

### 1. Richiesta Utente

Quando un utente richiede un libro:

1. Seleziona le date desiderate (inizio e fine)
2. Il sistema verifica la disponibilità
3. Se disponibile: crea un prestito `pendente` con `origine='richiesta'`
4. L'operatore approva/rifiuta

### 2. Verifica Disponibilità

Il sistema calcola la disponibilità considerando:

```php
// Stati che bloccano gli slot
'in_corso', 'in_ritardo', 'da_ritirare', 'prenotato'
```

**Nota importante**: Lo stato `pendente` NON blocca slot - è solo una richiesta non confermata.

### 3. Calcolo Copie Disponibili

Per ogni giorno richiesto:

```php
disponibili = copie_totali - in_prestito - prenotate
```

Dove:
- **copie_totali**: Copie prestabili (esclude `perso`, `danneggiato`, `manutenzione`)
- **in_prestito**: Prestiti attivi nel periodo
- **prenotate**: Prenotazioni già approvate nel periodo

## Coda Prenotazioni (tabella `prenotazioni`)

### Struttura Coda

La tabella `prenotazioni` gestisce la coda d'attesa:

| Campo | Descrizione |
|-------|-------------|
| `libro_id` | Libro richiesto |
| `utente_id` | Utente in coda |
| `stato` | `attiva` / `completata` / `annullata` |
| `queue_position` | Posizione in coda |
| `data_inizio_richiesta` | Data inizio desiderata |
| `data_fine_richiesta` | Data fine desiderata |

### Ordine FIFO

La coda segue l'ordine First In, First Out:
- Ordinamento per `queue_position ASC`
- Chi richiede prima ha priorità
- La posizione viene calcolata in tempo reale

## Riassegnazione Automatica

Il servizio `ReservationReassignmentService` gestisce automaticamente le riassegnazioni.

### Quando una Copia Diventa Disponibile

Metodo: `reassignOnNewCopy()`

1. Cerca prenotazioni "bloccate" (senza copia o con copia non disponibile)
2. Ordina per `created_at ASC` (FIFO)
3. Assegna la nuova copia alla prima prenotazione in coda
4. Imposta la copia a stato `prenotato`
5. Notifica l'utente

```php
// Cerca prenotazioni in attesa
SELECT p.id FROM prestiti p
LEFT JOIN copie c ON p.copia_id = c.id
WHERE p.libro_id = ?
AND p.stato = 'prenotato'
AND (p.copia_id IS NULL OR c.stato != 'disponibile')
ORDER BY p.created_at ASC
LIMIT 1
```

### Quando una Copia Viene Persa/Danneggiata

Metodo: `reassignOnCopyLost()`

1. Trova la prenotazione assegnata a quella copia
2. Cerca un'altra copia disponibile
3. Se trovata: riassegna
4. Se non trovata: mette la prenotazione "in attesa" (`copia_id = NULL`)
5. Notifica l'utente del cambio stato

### Quando un Libro Viene Restituito

Metodo: `reassignOnReturn()`

1. Identifica il libro dalla copia restituita
2. Chiama `reassignOnNewCopy()` per assegnare a chi è in coda

## Calcolo Disponibilità

### API Endpoint

```
GET /api/libri/{id}/availability
```

### Risposta

```json
{
  "success": true,
  "availability": {
    "total_copies": 3,
    "unavailable_dates": ["2024-01-15", "2024-01-16"],
    "earliest_available": "2024-01-17",
    "days": [
      {
        "date": "2024-01-15",
        "available": 0,
        "loaned": 2,
        "reserved": 1,
        "state": "borrowed"
      }
    ]
  }
}
```

### Stati Giornalieri

| Stato | Significato |
|-------|-------------|
| `free` | Almeno una copia disponibile |
| `borrowed` | Tutte le copie in prestito |
| `reserved` | Tutte le copie prenotate |

## Creazione Richiesta Prestito

### Endpoint

```
POST /api/libri/{id}/prenotazioni
```

### Parametri

```json
{
  "start_date": "2024-02-01",
  "end_date": "2024-02-28"
}
```

### Validazioni

1. Utente autenticato
2. Date disponibili (nessun conflitto)
3. Libro esistente e non cancellato
4. Utente non ha già un prestito attivo/pendente per quel libro

### Controllo Duplicati

```php
// Verifica prestiti esistenti
SELECT id FROM prestiti
WHERE libro_id = ? AND utente_id = ?
AND (
  (attivo = 0 AND stato = 'pendente')
  OR (attivo = 1 AND stato IN ('prenotato', 'da_ritirare', 'in_corso', 'in_ritardo'))
)
```

## Notifiche

### Tipi di Notifica

| Evento | Notifica |
|--------|----------|
| Copia disponibile | Email + in-app all'utente |
| Copia non più disponibile | Notifica admin |
| Richiesta creata | Notifica agli operatori |

### Notifiche Differite

Quando le operazioni avvengono dentro una transazione, le notifiche vengono:
1. Accumulate in `deferredNotifications`
2. Inviate dopo il commit con `flushDeferredNotifications()`

Questo evita di inviare notifiche per operazioni poi annullate.

## Concorrenza

### Protezione Race Condition

Il sistema usa lock espliciti per evitare assegnazioni duplicate:

```php
// Lock del libro prima di creare la richiesta
SELECT id FROM libri WHERE id = ? FOR UPDATE

// Lock della copia prima di assegnarla
SELECT id, stato FROM copie WHERE id = ? FOR UPDATE
```

### Retry con Esclusione

Se una copia risulta non più disponibile dopo il lock:
1. Viene aggiunta alla lista `excludeCopiaIds`
2. Si cerca un'altra copia
3. Massimo 5 tentativi

## Configurazione

### Durata Prestito Default

Se l'utente non specifica una data fine, il sistema aggiunge 1 mese dalla data inizio.

### Finestra di Calcolo

La disponibilità viene calcolata per 730 giorni (2 anni) dal giorno corrente.

## Sicurezza

### Controllo Accesso

- Solo utenti autenticati possono creare richieste
- Token CSRF richiesto per tutte le operazioni
- Validazione ID libro e utente

### Logging

Tutte le operazioni vengono loggate con `SecureLogger`:
- Riassegnazioni
- Errori di assegnazione
- Notifiche inviate/fallite

## Risoluzione Problemi

### Prenotazione non assegnata

Verifica:
1. Esistono copie disponibili per il libro
2. Le copie non sono tutte in stato `perso/danneggiato/manutenzione`
3. Il cron MaintenanceService è attivo

### Notifica non ricevuta

Controlla:
1. Email utente valida
2. Configurazione SMTP
3. Log in `storage/logs/app.log`

### Conflitto date

Se l'errore indica conflitto:
1. Le date richieste si sovrappongono con prestiti esistenti
2. Tutte le copie sono occupate nel periodo
3. Prova date diverse o attendi disponibilità

---

## Domande Frequenti (FAQ)

### 1. Qual è la differenza tra "richiesta" e "prenotazione"?

| Tipo | Origine | Quando si usa |
|------|---------|---------------|
| **Richiesta** | L'utente richiede un libro disponibile | Libro ha copie libere nelle date scelte |
| **Prenotazione** | L'utente si mette in coda | Tutte le copie sono occupate |

Entrambe passano per approvazione operatore.

---

### 2. Come funziona la coda FIFO?

La coda segue l'ordine cronologico di inserimento (First In, First Out):
- Chi richiede prima ha priorità
- Quando una copia diventa disponibile, viene assegnata al primo in coda
- La posizione in coda è calcolata in tempo reale

---

### 3. Un utente può prenotare più libri contemporaneamente?

Sì, ma con limiti configurabili:
- Limite prenotazioni attive per utente (es. max 5)
- Non può avere due prenotazioni per lo stesso libro
- I limiti sono configurabili in **Impostazioni → Prestiti**

---

### 4. Cosa succede se l'utente non ritira dopo l'approvazione?

Il sistema gestisce automaticamente i mancati ritiri:
1. Il prestito passa a stato "da_ritirare"
2. Dopo N giorni (configurabile), scade automaticamente
3. La copia torna disponibile
4. L'utente successivo in coda viene notificato

---

### 5. Posso modificare la posizione in coda di un utente?

No, la coda segue rigorosamente l'ordine FIFO per garantire equità. Tuttavia:
- Un admin può **annullare** una prenotazione
- Un admin può **creare un prestito diretto** bypassando la coda (non consigliato)

---

### 6. Come vedo tutte le prenotazioni in coda per un libro?

1. Vai alla **scheda del libro**
2. Sezione **Prenotazioni**
3. Vedi lista ordinata per posizione in coda

Oppure dalla **Dashboard** nella sezione "Prenotazioni Attive".

---

### 7. Cosa succede se tutte le copie vengono perse/danneggiate?

Le prenotazioni restano in coda ma non possono essere evase:
- Stato: "in attesa di copia"
- Quando aggiungi una nuova copia, la prima prenotazione viene riassegnata
- Gli utenti possono annullare la prenotazione se lo desiderano

---

### 8. Come calcolo la prossima data disponibile per un libro?

L'API calcola automaticamente:
```
GET /api/libri/{id}/availability
```

Risposta include `earliest_available` che indica la prima data libera considerando tutti i prestiti attivi.

---

### 9. Posso permettere prenotazioni per date specifiche?

Sì, l'utente può specificare le date desiderate:
- **Data inizio**: quando vuole iniziare il prestito
- **Data fine**: quando prevede di restituire

Il sistema verifica se esistono copie disponibili nel periodo richiesto.

---

### 10. Le notifiche email partono automaticamente?

Sì, il sistema invia notifiche automatiche per:
- **Copia disponibile**: quando un libro prenotato si libera
- **Prenotazione confermata**: quando l'operatore approva
- **Scadenza ritiro**: reminder prima che scada il tempo di ritiro

Configurabile in **Impostazioni → Email → Template**.
