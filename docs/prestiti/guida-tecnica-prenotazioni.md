# 🔧 Sistema Prenotazioni - Guida Tecnica

> Documentazione tecnica dettagliata sul funzionamento interno del sistema di prenotazioni con coda FIFO, race conditions, overlap checking e conversione automatica a prestiti

---

## 📋 Indice

1. [Architettura Sistema](#architettura-sistema)
2. [Coda FIFO - Funzionamento](#coda-fifo-funzionamento)
3. [Processo Automatico Disponibilità](#processo-automatico-disponibilita)
4. [Overlap Checking Date](#overlap-checking-date)
5. [Conversione Prenotazione → Prestito](#conversione-prenotazione--prestito)
6. [Race Conditions e Locking](#race-conditions-e-locking)
7. [Gestione Scadenze](#gestione-scadenze)
8. [Copie Multiple](#copie-multiple)
9. [Sistema Notifiche](#sistema-notifiche)

---

## 🏗️ Architettura Sistema

### Componenti Principali

| Componente | File | Responsabilità |
|------------|------|----------------|
| **ReservationManager** | `app/Controllers/ReservationManager.php` | Logica core prenotazioni |
| **ReservationsController** | `app/Controllers/ReservationsController.php` | Gestione richieste HTTP |
| **DataIntegrity** | `app/Support/DataIntegrity.php` | Ricalcolo disponibilità |
| **NotificationService** | `app/Support/NotificationService.php` | Invio email |
| **Cron Job** | `app/cron/maintenance.php` | Processa prenotazioni scadute |

### Tabella Database

```sql
CREATE TABLE prenotazioni (
    id INT AUTO_INCREMENT PRIMARY KEY,
    libro_id INT NOT NULL,
    utente_id INT NOT NULL,
    data_inizio_richiesta DATE,           -- Quando vuole il libro
    data_fine_richiesta DATE,             -- Fino a quando lo terrebbe
    data_scadenza_prenotazione DATETIME,  -- Timeout prenotazione
    stato ENUM('attiva', 'completata', 'annullata'),
    queue_position INT,                   -- Posizione in coda (1 = primo)
    notifica_inviata TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_libro_stato (libro_id, stato),
    INDEX idx_utente_stato (utente_id, stato),
    INDEX idx_queue (libro_id, queue_position)
);
```

---

## 📊 Coda FIFO - Funzionamento

### Principio FIFO

**FIFO = First In First Out**: Chi prenota PRIMA ha la PRIORITÀ.

### Gestione `queue_position`

**Assegnazione Nuova Prenotazione**:
```sql
-- 1. Trova posizione corrente massima
SELECT MAX(queue_position) as max_pos
FROM prenotazioni
WHERE libro_id = ? AND stato = 'attiva';

-- 2. Assegna posizione successiva
$newPosition = ($maxPos ?? 0) + 1;

-- 3. Crea prenotazione
INSERT INTO prenotazioni (libro_id, utente_id, queue_position, ...)
VALUES (?, ?, $newPosition, ...);
```

**Riordino dopo Completamento**:
```sql
-- Decrementa tutte le posizioni > 1
UPDATE prenotazioni
SET queue_position = queue_position - 1
WHERE libro_id = ? AND stato = 'attiva' AND queue_position > 1;
```

### Esempio Pratico

```
Libro: "1984" (ID: 42)

┌──────────────────────────────────────────────┐
│ STATO INIZIALE                               │
└──────────────────────────────────────────────┘
Prenotazioni:
#1 → Mario (ID: 10, created: 01/12)
#2 → Luigi (ID: 20, created: 05/12)
#3 → Sara  (ID: 30, created: 10/12)

┌──────────────────────────────────────────────┐
│ Mario completa (ritira il libro)             │
└──────────────────────────────────────────────┘
UPDATE prenotazioni SET stato = 'completata' WHERE id = 10;
UPDATE prenotazioni
SET queue_position = queue_position - 1
WHERE libro_id = 42 AND stato = 'attiva' AND queue_position > 1;

Risultato:
#1 → Luigi (era #2, promosso)
#2 → Sara  (era #3, promossa)

┌──────────────────────────────────────────────┐
│ Nuovo utente Anna prenota                    │
└──────────────────────────────────────────────┘
SELECT MAX(queue_position) → 2
INSERT ... queue_position = 3

Risultato:
#1 → Luigi
#2 → Sara
#3 → Anna
```

---

## 🤖 Processo Automatico Disponibilità

### Trigger

Il processamento automatico avviene quando:
1. **Libro restituito** (stato prestito → `restituito`)
2. **Cron job giornaliero** (verifica disponibilità periodica)
3. **Prestito annullato** dall'admin

### Flusso `processBookAvailability()`

```php
public function processBookAvailability(int $bookId): bool
{
    $today = date('Y-m-d');

    // 1. SELEZIONA PROSSIMA PRENOTAZIONE DATE-ELIGIBLE
    // Solo prenotazioni con data_inizio_richiesta <= oggi
    SELECT r.*, u.email, u.nome, u.cognome
    FROM prenotazioni r
    JOIN utenti u ON r.utente_id = u.id
    WHERE r.libro_id = ? AND r.stato = 'attiva'
    AND r.data_inizio_richiesta <= ?  // ← KEY: solo se data è arrivata
    ORDER BY r.queue_position ASC     // ← FIFO
    LIMIT 1;

    if (!$nextReservation) {
        return false;  // Nessuno in coda con date valide
    }

    // 2. VERIFICA DATE RANGE DISPONIBILE
    if (!$this->isDateRangeAvailable($bookId, $startDate, $endDate)) {
        return false;  // Conflitto con altri prestiti
    }

    // 3. CREA PRESTITO DA PRENOTAZIONE
    $loanId = $this->createLoanFromReservation($nextReservation);

    if ($loanId === false) {
        return false;  // Race condition o nessuna copia disponibile
    }

    // 4. MARCA PRENOTAZIONE COMPLETATA
    UPDATE prenotazioni SET stato = 'completata' WHERE id = ?;

    // 5. AGGIORNA CODE POSITIONS
    $this->updateQueuePositions($bookId);

    // 6. NOTIFICA UTENTE
    $this->sendReservationNotification($nextReservation);

    return true;
}
```

### Condizione Date-Eligible

**Importante**: Il sistema processa SOLO prenotazioni con `data_inizio_richiesta <= oggi`.

**Perché?**
- Giulia prenota "1984" dal 15/01/2026 al 15/02/2026
- Oggi è 10/01/2026
- "1984" diventa disponibile oggi
- Sistema NON converte a prestito perché Giulia vuole il libro DAL 15/01
- Il libro resta disponibile per altri utenti nel frattempo

**Scenario Completo**:
```
Libro "1984" restituito il 10/01/2026

Coda:
#1 → Giulia (dal 15/01 al 15/02) ❌ Non ancora pronta
#2 → Marco  (dal 10/01 al 10/02) ✅ Pronto subito

Risultato: Marco salta Giulia? NO!
Sistema processa in ordine FIFO:
- Giulia NON viene processata (data futura)
- Il libro resta disponibile
- Il 15/01/2026 il cron riprocessa e converte Giulia
```

---

## 📅 Overlap Checking Date

### Algoritmo `isDateRangeAvailable()`

Verifica se almeno una copia è disponibile per un periodo specifico.

```php
private function isDateRangeAvailable(int $bookId, string $startDate, string $endDate): bool
{
    // 1. CONTA COPIE PRESTABILI
    SELECT COUNT(*) as total FROM copie
    WHERE libro_id = ?
    AND stato NOT IN ('perso', 'danneggiato', 'manutenzione');
    // Esempio: 3 copie totali

    if ($totalCopies === 0) {
        return false;  // Nessuna copia disponibile
    }

    // 2. CONTA PRESTITI SOVRAPPOSTI
    // OVERLAP LOGIC: existing_start <= our_end AND existing_end >= our_start
    SELECT COUNT(*) as conflicts
    FROM prestiti
    WHERE libro_id = ?
    AND attivo = 1
    AND stato IN ('in_corso', 'in_ritardo', 'prenotato', 'pendente')
    AND data_prestito <= ?    // Data fine nostro range
    AND data_scadenza >= ?;   // Data inizio nostro range
    // Esempio: 2 prestiti sovrapposti

    // 3. VERIFICA SLOT DISPONIBILI
    return $loanConflicts < $totalCopies;
    // 2 < 3 → TRUE (c'è una copia libera)
}
```

### Esempi Overlap

**Caso 1: Overlap Completo**
```
Prestito esistente: 01/12 → 31/12
Richiesta:          15/12 → 20/12

Check: 01/12 <= 20/12 AND 31/12 >= 15/12
       TRUE   ✅       AND TRUE   ✅
       → OVERLAP! ❌
```

**Caso 2: Overlap Parziale Inizio**
```
Prestito esistente: 15/12 → 31/12
Richiesta:          10/12 → 20/12

Check: 15/12 <= 20/12 AND 31/12 >= 10/12
       TRUE   ✅       AND TRUE   ✅
       → OVERLAP! ❌
```

**Caso 3: Overlap Parziale Fine**
```
Prestito esistente: 01/12 → 15/12
Richiesta:          10/12 → 20/12

Check: 01/12 <= 20/12 AND 15/12 >= 10/12
       TRUE   ✅       AND TRUE   ✅
       → OVERLAP! ❌
```

**Caso 4: Nessun Overlap**
```
Prestito esistente: 01/12 → 10/12
Richiesta:          15/12 → 20/12

Check: 01/12 <= 20/12 AND 10/12 >= 15/12
       TRUE   ✅       AND FALSE  ❌
       → NESSUN OVERLAP ✅
```

### Nota Importante: Solo Prestiti

**La funzione conta SOLO prestiti, NON altre prenotazioni!**

```php
// ✅ Conta questi
SELECT COUNT(*) FROM prestiti WHERE ...

// ❌ NON conta questi
SELECT COUNT(*) FROM prenotazioni WHERE ...
```

**Perché?**
- Le prenotazioni sono in CODA FIFO
- Quando processiamo prenotazione #1, le altre prenotazioni (#2, #3...) ASPETTANO
- Non devono bloccarsi a vicenda
- Solo i prestiti ATTIVI bloccano le date

---

## 🔄 Conversione Prenotazione → Prestito

### Flusso `createLoanFromReservation()`

```php
private function createLoanFromReservation(array $reservation): int|false
{
    $bookId = $reservation['libro_id'];
    $startDate = $reservation['data_inizio_richiesta'];
    $endDate = $reservation['data_fine_richiesta'];

    // STEP 1: INIZIA TRANSAZIONE
    $db->begin_transaction();

    try {
        // STEP 2: TROVA COPIA DISPONIBILE (senza overlap)
        SELECT c.id FROM copie c
        WHERE c.libro_id = ?
        AND c.stato IN ('disponibile', 'prenotato')
        AND NOT EXISTS (
            SELECT 1 FROM prestiti p
            WHERE p.copia_id = c.id
            AND p.attivo = 1
            AND p.stato IN ('in_corso', 'prenotato', 'in_ritardo', 'pendente')
            AND p.data_prestito <= ?
            AND p.data_scadenza >= ?
        )
        LIMIT 1;

        if (!$copyId) {
            // Nessuna copia libera → ROLLBACK
            $db->rollback();
            return false;
        }

        // STEP 3: BLOCCA LA COPIA (LOCK)
        SELECT id FROM copie WHERE id = ? FOR UPDATE;

        // STEP 4: RICONTROLLA OVERLAP (race condition check)
        SELECT 1 FROM prestiti
        WHERE copia_id = ? AND attivo = 1
        AND stato IN ('in_corso','prenotato','in_ritardo','pendente')
        AND data_prestito <= ? AND data_scadenza >= ?
        LIMIT 1;

        if ($overlap) {
            // Qualcun altro ha preso la copia → ROLLBACK
            $db->rollback();
            return false;
        }

        // STEP 5: CREA PRESTITO
        INSERT INTO prestiti (
            libro_id, utente_id, copia_id,
            data_prestito, data_scadenza,
            stato, origine, attivo
        ) VALUES (?, ?, ?, ?, ?, 'pendente', 'prenotazione', 1);

        $loanId = $db->insert_id;

        if ($loanId <= 0) {
            $db->rollback();
            return false;
        }

        // STEP 6: AGGIORNA DISPONIBILITÀ LIBRO
        $integrity->recalculateBookAvailability($bookId);

        // STEP 7: COMMIT
        $db->commit();
        return $loanId;

    } catch (Exception $e) {
        $db->rollback();
        return false;
    }
}
```

### Stato Prestito Creato

**Campo `stato`**: `pendente`
**Campo `origine`**: `prenotazione`

**Perché pendente?**
- Admin deve **approvare** il prestito
- Utente deve **confermare ritiro fisico**
- Two-step workflow (v0.4.0+)

**Lo stato copia?**
- Resta `disponibile` fino alla conferma ritiro
- Quando admin conferma ritiro → `stato = 'prestato'`

---

## 🔒 Race Conditions e Locking

### Problema: Doppia Conversione Stessa Copia

**Scenario**:
```
Libro "1984" ha 1 copia disponibile
Due utenti in coda:
  #1 → Mario
  #2 → Luigi

Cron job esegue 2 istanze contemporaneamente:
  Processo A: processa Mario
  Processo B: processa Luigi

RISCHIO: Entrambi ottengono la stessa copia!
```

### Soluzione: Locking Ottimistico

#### Fase 1: SELECT...FOR UPDATE

```sql
-- Processo A blocca la copia
SELECT id FROM copie WHERE id = 5 FOR UPDATE;

-- Processo B prova a bloccare → ATTENDE
SELECT id FROM copie WHERE id = 5 FOR UPDATE;  -- ⏳ In attesa...
```

**Cosa succede**:
- `FOR UPDATE` acquisisce un **lock esclusivo** sulla riga
- Altri processi che fanno SELECT...FOR UPDATE sulla stessa riga **attendono**
- Il lock viene rilasciato solo al **COMMIT** o **ROLLBACK**

#### Fase 2: Ricontrollo Overlap

```php
// Dopo aver bloccato, ricontrolla se qualcuno ha creato un prestito
SELECT 1 FROM prestiti
WHERE copia_id = ? AND attivo = 1
AND stato IN ('in_corso','prenotato','in_ritardo','pendente')
AND data_prestito <= ? AND data_scadenza >= ?
LIMIT 1;

if ($overlap) {
    // Race condition rilevata!
    $db->rollback();
    return false;
}
```

**Perché due check?**
1. **Primo check** (prima del lock): Performance, evita transazioni inutili
2. **Secondo check** (dopo il lock): Sicurezza, verifica definitiva

### Timeline Race Condition

```
T=0  Processo A: BEGIN TRANSACTION
T=0  Processo B: BEGIN TRANSACTION

T=1  Processo A: SELECT copia disponibile → Copia #5 OK
T=1  Processo B: SELECT copia disponibile → Copia #5 OK

T=2  Processo A: SELECT ... FOR UPDATE (LOCK copia #5)
T=2  Processo B: SELECT ... FOR UPDATE ⏳ ATTENDE...

T=3  Processo A: Ricontrolla overlap → Nessun overlap
T=3  Processo A: INSERT prestito per copia #5
T=3  Processo A: COMMIT (rilascia lock)

T=4  Processo B: ✅ Lock acquisito!
T=4  Processo B: Ricontrolla overlap → TROVATO OVERLAP! (prestito di A)
T=4  Processo B: ROLLBACK (non crea prestito)
T=4  Processo B: return false

Risultato: Solo Processo A ha creato il prestito
          Processo B ha fallito gracefully
```

---

## ⏰ Gestione Scadenze

### Scadenza Prenotazione (`data_scadenza_prenotazione`)

**Quando si imposta**:
- Quando la prenotazione diventa "disponibile per ritiro"
- Tipicamente: +7 giorni dalla disponibilità

**Cosa succede alla scadenza**:
```php
public function cancelExpiredReservations(): void
{
    // 1. TROVA PRENOTAZIONI SCADUTE
    SELECT DISTINCT libro_id
    FROM prenotazioni
    WHERE stato = 'attiva'
    AND data_scadenza_prenotazione IS NOT NULL
    AND data_scadenza_prenotazione < NOW();

    // 2. ANNULLA PRENOTAZIONI
    UPDATE prenotazioni
    SET stato = 'annullata'
    WHERE stato = 'attiva'
    AND data_scadenza_prenotazione IS NOT NULL
    AND data_scadenza_prenotazione < NOW();

    // 3. RIORDINA CODE
    foreach ($affectedBooks as $bookId) {
        $this->reorderQueuePositions($bookId);
    }
}
```

**Chiamata**: Cron job giornaliero (`maintenance.php`)

### Riordino Queue Positions

```php
private function reorderQueuePositions(int $bookId): void
{
    // Usa variabile MySQL per aggiornare in singola query
    $db->query("SET @pos := 0");

    UPDATE prenotazioni
    SET queue_position = (@pos := @pos + 1)
    WHERE libro_id = ? AND stato = 'attiva'
    ORDER BY queue_position ASC;
}
```

**Vantaggio**: Evita N+1 query quando ci sono molte prenotazioni.

**Esempio**:
```
Prima:
#1 → Mario
#2 → Luigi (SCADUTO, annullato)
#3 → Sara
#5 → Anna  (gap per prenotazione annullata manualmente)

Dopo reorderQueuePositions():
#1 → Mario
#2 → Sara  (era #3)
#3 → Anna  (era #5)
```

---

## 📚 Copie Multiple

### Gestione Intelligente

**Scenario**:
```
Libro "1984" - 3 copie totali

Copia #1: disponibile
Copia #2: prestato (scad. 20/12)
Copia #3: prestato (scad. 25/12)

Prenotazioni:
#1 → Mario (dal 15/12 al 30/12)
#2 → Luigi (dal 22/12 al 30/12)
```

**Processamento 20/12 (Copia #2 restituita)**:
```php
processBookAvailability(libro_id: 42);

// 1. Seleziona Mario (#1 in coda)
// 2. isDateRangeAvailable(15/12, 30/12)?
//    - Copie totali: 3
//    - Prestiti sovrapposti:
//      * Copia #3: 25/12 (overlaps con 15-30/12) → COUNT = 1
//    - 1 < 3 → TRUE ✅

// 3. createLoanFromReservation()
//    - Trova copia disponibile senza overlap 15-30/12
//    - Copia #1: nessun prestito → OK!
//    - Copia #2: appena restituita → OK!
//    - Sceglie Copia #1
//    - Crea prestito per Mario

// 4. Mario completato, coda:
//    #1 → Luigi (promosso)
```

**Processamento 25/12 (Copia #3 restituita)**:
```php
processBookAvailability(libro_id: 42);

// 1. Seleziona Luigi (#1 in coda)
// 2. isDateRangeAvailable(22/12, 30/12)?
//    - Copie totali: 3
//    - Prestiti sovrapposti:
//      * Mario: 15/12-30/12 (overlaps) → COUNT = 1
//    - 1 < 3 → TRUE ✅

// 3. Crea prestito per Luigi
//    - Trova copia libera
//    - Copia #2: libera
//    - Copia #3: libera
//    - Sceglie Copia #2
```

**Risultato finale**:
```
Copia #1: Mario (15-30/12)
Copia #2: Luigi (22-30/12)
Copia #3: disponibile
```

### Fallback Copie

Se la tabella `copie` è vuota (non gestita), il sistema usa fallback:

```php
// Cerca in tabella copie
SELECT COUNT(*) FROM copie WHERE libro_id = ?;

if ($totalCopies === 0) {
    // Fallback: usa campo libri.copie_totali
    SELECT GREATEST(IFNULL(copie_totali, 1), 1) AS copie_totali
    FROM libri WHERE id = ?;

    $totalCopies = $fallbackRow['copie_totali'];
}
```

**Garanzia**: Minimo 1 copia (anche se `copie_totali` è NULL).

---

## 📧 Sistema Notifiche

### Email "Libro Disponibile"

**Trigger**: Quando `createLoanFromReservation()` ha successo.

```php
private function sendReservationNotification(array $reservation): bool
{
    // 1. RECUPERA DATI LIBRO
    SELECT l.titolo, COALESCE(l.isbn13, l.isbn10, '') as isbn,
           GROUP_CONCAT(a.nome) AS autore
    FROM libri l
    LEFT JOIN libri_autori la ON l.id = la.libro_id
    LEFT JOIN autori a ON la.autore_id = a.id
    WHERE l.id = ?
    GROUP BY l.id;

    // 2. PREPARA VARIABILI EMAIL
    $variables = [
        'utente_nome' => $reservation['nome'],
        'libro_titolo' => $book['titolo'],
        'libro_autore' => $book['autore'] ?: 'Autore non specificato',
        'libro_isbn' => $book['isbn'] ?: 'N/A',
        'data_inizio' => format_date($reservation['data_inizio_richiesta']),
        'data_fine' => format_date($reservation['data_fine_richiesta']),
        'book_url' => base_url() . book_url($book),
        'profile_url' => base_url() . '/profile/prestiti'
    ];

    // 3. INVIA EMAIL
    $success = $notificationService->sendReservationBookAvailable(
        $reservation['email'],
        $variables
    );

    // 4. MARCA COME INVIATA (solo se successo)
    if ($success) {
        UPDATE prenotazioni SET notifica_inviata = 1 WHERE id = ?;
    }

    return $success;
}
```

### Template Email

**Nome**: `reservation_book_available.html`

**Variabili disponibili**:
- `{utente_nome}`: Nome utente
- `{libro_titolo}`: Titolo libro
- `{libro_autore}`: Autore/i
- `{libro_isbn}`: Codice ISBN
- `{data_inizio}`: Data inizio prestito richiesto
- `{data_fine}`: Data fine prestito richiesto
- `{book_url}`: Link diretto al libro
- `{profile_url}`: Link profilo utente

**Esempio contenuto**:
```html
<p>Ciao {utente_nome},</p>

<p>Il libro che hai prenotato è ora disponibile!</p>

<h3>{libro_titolo}</h3>
<p>Autore: {libro_autore}<br>
   ISBN: {libro_isbn}</p>

<p>Puoi ritirarlo in biblioteca dal {data_inizio} al {data_fine}.</p>

<p><a href="{book_url}">Vedi dettagli libro</a></p>
<p><a href="{profile_url}">Vai ai tuoi prestiti</a></p>

<p>Hai 7 giorni per ritirare il libro, altrimenti passerà al prossimo in coda.</p>
```

### Retry Logica

**Se l'email fallisce**:
```php
if (!$success) {
    error_log("Email send failed for reservation ID {$reservation['id']}, will retry on next run");
    // NON marca notifica_inviata = 1
}
```

**Prossima esecuzione cron**:
- Riprocessa le prenotazioni completate ma non notificate
- Riprova invio email
- Previene perdita notifiche per problemi SMTP temporanei

---

## 🔍 Query Diagnostiche

### Verifica Stato Code

```sql
-- Code attive per libro
SELECT
    r.id,
    r.queue_position,
    u.nome,
    u.cognome,
    r.data_inizio_richiesta,
    r.created_at
FROM prenotazioni r
JOIN utenti u ON r.utente_id = u.id
WHERE r.libro_id = 42 AND r.stato = 'attiva'
ORDER BY r.queue_position ASC;
```

### Prenotazioni Scadute

```sql
-- Trova prenotazioni scadute non ancora processate
SELECT
    p.*,
    u.email
FROM prenotazioni p
JOIN utenti u ON p.utente_id = u.id
WHERE p.stato = 'attiva'
AND p.data_scadenza_prenotazione IS NOT NULL
AND p.data_scadenza_prenotazione < NOW();
```

### Prenotazioni Pronte per Conversione

```sql
-- Prenotazioni eligibili per conversione in prestito
SELECT
    p.*,
    l.titolo,
    u.nome,
    u.cognome
FROM prenotazioni p
JOIN libri l ON p.libro_id = l.id
JOIN utenti u ON p.utente_id = u.id
WHERE p.stato = 'attiva'
AND p.data_inizio_richiesta <= CURDATE()
AND p.queue_position = 1  -- Solo primo in coda
ORDER BY p.created_at ASC;
```

### Disponibilità Date Range

```sql
-- Verifica se libro disponibile per date specifiche
-- (replica logica isDateRangeAvailable)

-- Copie totali
SELECT COUNT(*) as total_copies FROM copie
WHERE libro_id = 42
AND stato NOT IN ('perso', 'danneggiato', 'manutenzione');

-- Prestiti sovrapposti
SELECT COUNT(*) as loan_conflicts
FROM prestiti
WHERE libro_id = 42 AND attivo = 1
AND stato IN ('in_corso', 'in_ritardo', 'prenotato', 'pendente')
AND data_prestito <= '2025-12-31'   -- Nostra data fine
AND data_scadenza >= '2025-12-15';  -- Nostra data inizio

-- Disponibile se: loan_conflicts < total_copies
```

---

## 🎓 Best Practices

### ✅ DO

1. **Esegui cron giornaliero** per processare scadenze
2. **Monitora email fallite** (notifica_inviata = 0)
3. **Verifica integrità code** periodicamente (no gap, no duplicati)
4. **Usa transazioni** per operazioni multiple
5. **Log errori race condition** per monitorare frequenza

### ❌ DON'T

1. **Non modificare queue_position manualmente** → usa reorderQueuePositions()
2. **Non processare prenotazioni fuori transazione** → rischio inconsistenza
3. **Non ignorare return false** da createLoanFromReservation → indica race condition
4. **Non disabilitare controlli overlap** → rischio doppie assegnazioni
5. **Non modificare date_scadenza_prenotazione** senza ricalcolare tutto

---

## 🔗 Riferimenti

- [Sistema Prestiti →](./sistema-prestiti.md)
- [Guida Admin Prestiti →](./guida-admin-prestiti.md)
- [Gestione Copie →](../libri/gestione-copie.md)
- [Developer: ReservationManager.php](../../app/Controllers/ReservationManager.php)

---

**Ultima modifica**: Dicembre 2025
**Versione Pinakes**: v0.4.1
