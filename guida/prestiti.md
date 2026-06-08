# Guida Completa ai Prestiti

> **Il cuore della biblioteca!** Questa guida documenta ogni aspetto del sistema prestiti: stati, transizioni, email, prenotazioni, disponibilità e molto altro.

---

## Quick Start: Il Tuo Primo Prestito

### Scenario: Un utente vuole un libro

1. **Dashboard** → **Nuovo Prestito** (o `/prestiti/crea`)
2. **Cerca l'utente**: Digita nome, cognome, email o numero tessera
3. **Cerca il libro**: Digita titolo, ISBN o EAN
4. **Seleziona le date**: Il calendario mostra la disponibilità
5. **Clicca "Crea Prestito"**
6.  Fatto! Il prestito è in attesa di approvazione

### Opzione "Consegna Immediata"

Se l'utente è davanti a te con il libro in mano:
-  Spunta **"Consegna Immediata"**
- Il prestito salta l'approvazione e va direttamente a "In Corso"

---

## Il Ciclo di Vita Completo del Prestito

### Diagramma di Flusso Principale

```
┌─────────────┐    ┌──────────────┐    ┌─────────────┐    ┌────────────┐
│  PENDENTE   │ →  │  PRENOTATO   │ →  │ DA_RITIRARE │ →  │  IN_CORSO  │
│  Richiesta  │    │  Approvato   │    │   Pronto    │    │   Attivo   │
└─────────────┘    │  (futuro)    │    │  (ritira!)  │    └─────┬──────┘
                   └──────────────┘    └─────────────┘          │
                                                                │
                   ┌────────────────────────────────────────────┘
                   │
                   ▼
┌─────────────┐    ┌──────────────┐    ┌─────────────┐
│ IN_RITARDO  │ →  │  RESTITUITO  │    │PERSO/DANN.  │
│  Scaduto!   │    │  Completato  │    │  Problemi   │
└─────────────┘    └──────────────┘    └─────────────┘
```

### Percorsi Alternativi

```
PENDENTE ──(rifiuta)──→ RIFIUTATO
PENDENTE ──(annulla)──→ ANNULLATO
PRENOTATO ──(scade)───→ SCADUTO
DA_RITIRARE ─(scade)──→ SCADUTO
```

---

## I 10 Stati del Prestito

### Stati Attivi (il libro è impegnato)

| Stato | Significato | Flag `attivo` | Stato Copia |
|-------|-------------|:-------------:|-------------|
| **pendente** | Richiesta in attesa di approvazione | 0 | Nessuna |
| **prenotato** | Approvato, data futura | 1 | `prenotato` |
| **da_ritirare** | Pronto, utente deve ritirare | 1 | `prenotato` |
| **in_corso** | Utente ha il libro | 1 | `prestato` |
| **in_ritardo** | Scaduto, non restituito | 1 | `prestato` |

### Stati Finali (prestito concluso)

| Stato | Significato | Flag `attivo` | Stato Copia |
|-------|-------------|:-------------:|-------------|
| **restituito** | Completato con successo | 0 | `disponibile` |
| **perso** | Libro smarrito | 0 | `perso` |
| **danneggiato** | Libro danneggiato | 0 | `danneggiato` |
| **annullato** | Cancellato manualmente | 0 | `disponibile` |
| **scaduto** | Tempo scaduto (pickup/prenotazione) | 0 | `disponibile` |
| **rifiutato** | Richiesta non approvata | 0 | Nessuna |

---

## Transizioni di Stato Dettagliate

### 1. Creazione Richiesta

```
[Utente richiede libro] → PENDENTE
```

**Cosa succede:**
- Viene creato un record nella tabella `prestiti`
- `attivo = 0` (non ancora approvato)
- Nessuna copia ancora assegnata
- Admin riceve notifica (se configurato)

---

### 2. Approvazione

```
PENDENTE → PRENOTATO (se data futura)
PENDENTE → DA_RITIRARE (se data oggi o passata)
```

**Cosa succede:**
- Admin clicca "Approva" in Dashboard
- Sistema assegna una copia disponibile (`copia_id`)
- La copia passa a stato `prenotato`
- `attivo = 1`
- **Email inviata**: `loan_approved` all'utente

**Se la data di inizio è futura:**
- Stato = `prenotato`
- Il prestito attende automaticamente

**Se la data di inizio è oggi o passata:**
- Stato = `da_ritirare`
- Viene impostato `pickup_deadline` (default: +3 giorni)
- **Email inviata**: `loan_pickup_ready` all'utente

---

### 3. Attivazione Automatica (Prenotato → Da Ritirare)

```
PRENOTATO → DA_RITIRARE
```

**Trigger**: MaintenanceService (cron alle 6:00 o login admin)

**Condizione**: `data_prestito <= oggi` AND `stato = 'prenotato'`

**Cosa succede:**
- Stato cambia a `da_ritirare`
- `pickup_deadline` = oggi + giorni configurati (default 3)
- **Email inviata**: `loan_pickup_ready` all'utente

---

### 4. Conferma Ritiro

```
DA_RITIRARE → IN_CORSO
```

**Trigger**: Admin clicca "Conferma Ritiro"

**Cosa succede:**
- L'utente ha fisicamente preso il libro
- La copia passa da `prenotato` a `prestato`
- `pickup_deadline` viene azzerato
- Disponibilità libro ricalcolata

---

### 5. Scadenza Prestito (Automatica)

```
IN_CORSO → IN_RITARDO
```

**Trigger**: MaintenanceService (automatico)

**Condizione**: `data_scadenza < oggi` AND `stato = 'in_corso'`

**Cosa succede:**
- Stato cambia automaticamente a `in_ritardo`
- **Email inviata**: `loan_overdue_notification` all'utente
- **Email inviata**: `loan_overdue_admin` agli admin

---

### 6. Restituzione

```
IN_CORSO → RESTITUITO
IN_RITARDO → RESTITUITO (o PERSO/DANNEGGIATO)
```

**Trigger**: Admin clicca "Restituisci"

**Opzioni di restituzione:**

| Opzione | Quando usarla | Stato Copia |
|---------|---------------|-------------|
| **Restituito** | Libro tornato in buone condizioni | `disponibile` |
| **Perso** | Libro smarrito | `perso` |
| **Danneggiato** | Libro rovinato | `danneggiato` |

**Cosa succede:**
- `attivo = 0`
- `data_restituzione` = oggi
- Copia aggiornata
- Se c'erano prenotazioni in coda → vengono notificati
- Se libro era in wishlist → utenti notificati

---

### 7. Scadenza Pickup

```
DA_RITIRARE → SCADUTO
```

**Trigger**: MaintenanceService (automatico)

**Condizione**: `pickup_deadline < oggi` AND `stato = 'da_ritirare'`

**Cosa succede:**
- Utente non ha ritirato in tempo
- Copia torna `disponibile`
- **Email inviata**: `loan_pickup_expired` all'utente
- Se c'è coda, la copia viene riassegnata

---

### 8. Scadenza Prenotazione

```
PRENOTATO → SCADUTO
```

**Trigger**: MaintenanceService (automatico)

**Condizione**: `data_scadenza < oggi` AND `stato = 'prenotato'`

**Cosa succede:**
- La prenotazione futura non è più valida
- Copia torna `disponibile`
- Nota aggiunta al prestito: `[System] Scaduta il {data}`

---

### 9. Rifiuto

```
PENDENTE → RIFIUTATO
```

**Trigger**: Admin clicca "Rifiuta" con motivo

**Cosa succede:**
- `attivo = 0`
- `motivo_rifiuto` salvato
- **Email inviata**: `loan_rejected` all'utente con il motivo

---

### 10. Annullamento

```
PENDENTE/PRENOTATO/DA_RITIRARE → ANNULLATO
```

**Trigger**: Admin clicca "Annulla"

**Cosa succede:**
- `attivo = 0`
- Se c'era una copia assegnata → torna `disponibile`
- **Email inviata**: `loan_pickup_cancelled` (se era da_ritirare)

---

## Sistema Email Automatiche

### Tabella Completa Notifiche

| Email | Quando | Destinatario | Template |
|-------|--------|--------------|----------|
| **Prestito Approvato** | Admin approva | Utente | `loan_approved` |
| **Prestito Rifiutato** | Admin rifiuta | Utente | `loan_rejected` |
| **Pronto per Ritiro** | Stato → da_ritirare | Utente | `loan_pickup_ready` |
| **Ritiro Scaduto** | Pickup deadline passa | Utente | `loan_pickup_expired` |
| **Ritiro Annullato** | Admin annulla | Utente | `loan_pickup_cancelled` |
| **Promemoria Scadenza** | 3 giorni prima scadenza | Utente | `loan_expiring_warning` |
| **Prestito Scaduto** | Stato → in_ritardo | Utente | `loan_overdue_notification` |
| **Avviso Admin Ritardo** | Stato → in_ritardo | Admin | `loan_overdue_admin` |
| **Libro Disponibile** | Wishlist soddisfatta | Utente | `wishlist_available` |

### Variabili Disponibili nei Template

```
{{utente_nome}}        - Nome dell'utente
{{utente_email}}       - Email dell'utente
{{libro_titolo}}       - Titolo del libro
{{libro_autore}}       - Autore/i del libro
{{data_inizio}}        - Data inizio prestito
{{data_fine}}          - Data scadenza prestito
{{scadenza_ritiro}}    - Deadline per ritirare
{{giorni_prestito}}    - Durata totale in giorni
{{giorni_rimasti}}     - Giorni rimasti prima della scadenza
{{giorni_ritardo}}     - Giorni di ritardo
{{motivo_rifiuto}}     - Motivo del rifiuto
{{pickup_instructions}} - Istruzioni per il ritiro
```

### Tempistiche Notifiche Automatiche

| Notifica | Quando viene inviata |
|----------|---------------------|
| Promemoria scadenza | X giorni prima (configurabile, default 3) |
| Avviso ritardo | Primo giorno di ritardo |
| Sollecito ritardo | Ogni giorno di ritardo (se configurato) |

---

## ⏰ Sistema Pickup Deadline

### Come Funziona

Quando un prestito diventa "Da Ritirare":

```
pickup_deadline = oggi + pickup_expiry_days (default: 3 giorni)
```

### Timeline Esempio

```
Giorno 1: Prestito approvato → stato = da_ritirare
          pickup_deadline = Giorno 4

Giorno 2: Utente può ritirare 
Giorno 3: Utente può ritirare 
Giorno 4: Ultimo giorno! 

Giorno 5: MaintenanceService esegue
          → stato = scaduto
          → copia liberata
          → email "pickup expired" inviata
```

### Impostazioni Configurabili dei Prestiti

> **Novità (#157)** — I parametri chiave del ciclo prestiti sono ora **configurabili dall'interfaccia**, senza toccare il codice. Si trovano in **Impostazioni → Prestiti** (categoria `loans`).

Queste impostazioni governano la durata dei prestiti, i limiti per utente, i rinnovi e i tempi di ritiro. Vengono lette in ogni punto del ciclo di vita del prestito: richiesta utente, creazione admin, approvazione prenotazione e manutenzione automatica.

---

### Tabella Riepilogativa

| Impostazione | Default | Range / Valori | Dove agisce |
|--------------|:-------:|----------------|-------------|
| `loan_duration_days` | **30** | 1 – 365 giorni | Durata predefinita del prestito |
| `max_active_loans_per_user` | **0** | 0 = nessun limite | Tetto prestiti attivi per utente |
| `max_renewals` | **3** | numero intero | Rinnovi massimi per prestito |
| `pickup_expiry_days` | **3** | giorni | Tempo per ritirare un prestito approvato |

---

### `loan_duration_days` — Durata predefinita del prestito

**Default: 30 giorni · Range: 1 – 365**

Definisce la durata standard di un prestito in giorni. Viene usata **quando la data di scadenza non è specificata** esplicitamente, in particolare:

- nelle **richieste utente** dal catalogo;
- nella **creazione di un prestito da parte dell'admin**;
- nell'**approvazione di una prenotazione**.

In tutti questi casi, se non viene fornita una data di fine, il sistema calcola:

```
data_scadenza = data_prestito + loan_duration_days
```

> **Esempio**: con `loan_duration_days = 30`, un prestito che parte il 1° marzo scade il 31 marzo se l'operatore non imposta manualmente una data diversa.

---

### `max_active_loans_per_user` — Limite prestiti attivi per utente

**Default: 0 (nessun limite)**

Stabilisce il **numero massimo di prestiti attivi** che un singolo utente può avere contemporaneamente. Il valore `0` disattiva il limite.

Il controllo viene applicato in **due momenti**:

1. alla **creazione** di un prestito;
2. all'**approvazione** di una prenotazione.

#### Stati che contano come "attivo"

Concorrono al conteggio del limite i prestiti nei seguenti stati:

| Stato | Conta nel limite? |
|-------|:-----------------:|
| **prenotato** | ✅ |
| **da_ritirare** | ✅ |
| **in_corso** | ✅ |
| **in_ritardo** | ✅ |

> **Nota**: gli stati finali (`restituito`, `scaduto`, `annullato`, `rifiutato`, `perso`, `danneggiato`) **non** contano nel limite. Una richiesta `pendente` non ancora approvata non occupa uno "slot" del limite finché non viene approvata.

Quando un utente raggiunge il tetto, ulteriori richieste/approvazioni vengono bloccate finché non restituisce un libro.

---

### `max_renewals` — Rinnovi massimi consentiti

**Default: 3**

Numero massimo di volte che un prestito può essere rinnovato. Ad ogni rinnovo il contatore `renewals` del prestito viene incrementato; quando raggiunge `max_renewals`, ulteriori rinnovi vengono rifiutati.

> **Robustezza**: se il valore configurato non è un numero valido, il sistema ricade automaticamente sul **default 3**, evitando comportamenti imprevedibili.

I rinnovi restano soggetti anche alle altre regole del [sistema rinnovi](prestiti.md#sistema-rinnovi) (stato `in_corso`, assenza di conflitti con prenotazioni in coda).

---

### `pickup_expiry_days` — Giorni per il ritiro

**Default: 3 giorni**

Quando un prestito viene approvato e passa allo stato **`da_ritirare`**, l'utente ha un numero limitato di giorni per ritirare fisicamente il libro. Trascorso questo termine, il prestito **scade automaticamente** (stato `scaduto`) e la copia torna disponibile.

```
pickup_deadline = data_approvazione + pickup_expiry_days
```

> **Timezone**: la scadenza del ritiro è calcolata nel **fuso orario applicativo**, così la "mezzanotte" del termine coincide con quella della biblioteca e non con l'UTC del server.

Vedi anche il [Sistema Pickup Deadline](prestiti.md#-sistema-pickup-deadline) nella guida prestiti per la timeline completa.

---

## Modello di Occupazione Unificato (#157)

> **Concetto chiave per gli operatori** — Capire *quando una copia è considerata occupata* è essenziale per leggere correttamente la disponibilità mostrata nel catalogo, nella dashboard e nel calendario.

A partire da #157 il sistema usa un **unico modello di occupazione** coerente in tutta l'applicazione.

### Quando una copia è OCCUPATA

Una copia risulta occupata se si verifica **almeno una** di queste condizioni:

1. ha un **prestito attivo** in uno degli stati:
   - `in_corso`
   - `in_ritardo`
   - `da_ritirare`
   - `prenotato`
2. **oppure** è associata a una **richiesta pendente con copia già assegnata**.

### Quando una copia NON è occupata

Una **richiesta pendente "nuda"** — cioè una richiesta in stato `pendente` **senza una copia assegnata** — **non occupa alcuna copia**.

```
Richiesta PENDENTE senza copia  →  NON occupa
        │
        ▼  (admin approva e assegna una copia)
Copia assegnata                 →  da quel momento OCCUPA
```

> **Perché è importante**: l'occupazione scatta solo quando l'admin **approva la richiesta e le assegna una copia**. Questo evita che richieste in attesa di valutazione "blocchino" copie che potrebbero servire ad altri utenti.

### Multi-copia

Ogni copia di un libro è **tracciata in modo indipendente**. Un libro con 3 copie può quindi avere, nello stesso momento:

- 1 copia in `in_corso`,
- 1 copia `da_ritirare`,
- 1 copia ancora `disponibile`.

La disponibilità mostrata è sempre il risultato del conteggio copia per copia, non un valore aggregato approssimato.

---

## Automatismi ed Email Collegate

Le impostazioni qui sopra sono integrate con la **manutenzione automatica** (eseguita dal `MaintenanceService` tramite cron o al login admin) e con le notifiche email.

### Manutenzione automatica

| Automatismo | Cosa fa |
|-------------|---------|
| **Scadenza ritiro** | Un prestito `da_ritirare` non ritirato entro `pickup_expiry_days` passa a `scaduto` e libera la copia |
| **Riassegnazione copia restituita** | Quando un libro viene restituito, la copia liberata viene **riassegnata alla prossima prenotazione in coda** (ordine FIFO) |

### Email differite (dopo il commit)

Le notifiche email legate a questi automatismi vengono **accodate e inviate solo dopo il commit** della transazione del database.

> **Perché differite**: se un'operazione viene annullata (rollback), le email accodate **non partono**. Questo evita di notificare l'utente per un'azione che in realtà non è andata a buon fine (es. "libro pronto al ritiro" per un'approvazione poi fallita).

Per l'elenco completo dei template email e dei loro trigger, vedi la sezione [Sistema Email Automatiche](prestiti.md#sistema-email-automatiche) della guida prestiti.

---

## Regole di Business

### Un Utente, Un Libro

Un utente non può avere più prestiti attivi per lo stesso libro:

```sql
-- Controllo duplicati
SELECT COUNT(*) FROM prestiti
WHERE libro_id = ? AND utente_id = ?
AND (attivo = 1 OR (attivo = 0 AND stato = 'pendente'))
```

### Sanzioni

Il campo `sanzione` (DECIMAL) può essere valorizzato per:
- Ritardi nella restituzione
- Libri persi
- Libri danneggiati

### Origine Prestito

Il campo `origine` traccia come è nato il prestito:
- `richiesta` - Richiesta diretta dell'utente
- `prenotazione` - Conversione da prenotazione
- `diretto` - Creato direttamente dall'admin

### Concorrenza

Tutte le operazioni critiche usano:
- Lock a livello di riga (`FOR UPDATE`)
- Transazioni atomiche
- Ordine lock consistente: prestiti → libri → copie

---

## Best Practices per Admin

### Routine Quotidiana

1. **Mattina**: Controlla sezione "In Ritardo" 
2. **Durante il giorno**: Approva richieste pendenti 
3. **Sera**: Verifica "Da Ritirare" non scaduti 

### Gestione Ritardi

1. Sistema invia email automatiche
2. Dopo X giorni, contatta telefonicamente
3. Documenta tutto nel campo `note`
4. Se perso, registra con stato `perso` e sanzione

### Gestione Prenotazioni

1. Le prenotazioni hanno priorità FIFO
2. Non saltare la coda manualmente
3. Se necessario annullare, avvisa l'utente

---

## ‍ Sezione Sviluppatori

### Controller e Routes

| Metodo | URL | Controller | Azione |
|--------|-----|------------|--------|
| `GET` | `/prestiti/crea` | `PrestitoController::create` | Form creazione |
| `POST` | `/prestiti` | `PrestitoController::store` | Salva prestito |
| `POST` | `/prestiti/{id}/renew` | `PrestitoController::renew` | Rinnova |
| `GET` | `/admin/loans/pending` | `AdminLoanController::pending` | Gestione |
| `POST` | `/admin/loans/{id}/approve` | `LoanApprovalController::approve` | Approva |
| `POST` | `/admin/loans/{id}/reject` | `LoanApprovalController::reject` | Rifiuta |
| `POST` | `/admin/loans/{id}/pickup` | `LoanApprovalController::confirmPickup` | Conferma ritiro |
| `POST` | `/admin/loans/{id}/cancel-pickup` | `LoanApprovalController::cancelPickup` | Annulla |
| `POST` | `/prestiti/{id}/return` | `PrestitoController::processReturn` | Restituisci |

### API Endpoints

```javascript
// Cerca utenti (autocomplete)
GET /api/utenti/search?q={query}
→ [{id, nome, cognome, email, telefono, numero_tessera}]

// Cerca libri (autocomplete)
GET /api/libri/search?q={query}
→ [{id, titolo, isbn, ean, autori}]

// Disponibilità libro
GET /api/libro/{id}/availability
→ {available_copies, total_copies, dates: [{date, status}]}

// Verifica conflitti rinnovo
GET /api/prestiti/{id}/can-renew
→ {can_renew: bool, reason?: string}
```

### Database - Tabella `prestiti`

```sql
CREATE TABLE prestiti (
    id INT PRIMARY KEY AUTO_INCREMENT,
    utente_id INT NOT NULL,
    libro_id INT NOT NULL,
    copia_id INT,

    -- Stati e flag
    stato ENUM('pendente','prenotato','da_ritirare','in_corso',
               'in_ritardo','restituito','perso','danneggiato',
               'annullato','scaduto','rifiutato'),
    attivo TINYINT(1) DEFAULT 0,

    -- Date
    data_richiesta DATETIME,
    data_prestito DATE,           -- Inizio prestito
    data_scadenza DATE,           -- Fine prevista
    data_restituzione DATETIME,   -- Fine effettiva
    pickup_deadline DATE,         -- Deadline ritiro

    -- Tracciamento
    renewals INT DEFAULT 0,
    origine ENUM('richiesta','prenotazione','diretto'),

    -- Note e sanzioni
    note TEXT,
    motivo_rifiuto TEXT,
    sanzione DECIMAL(10,2),

    -- Flag notifiche
    warning_sent TINYINT(1) DEFAULT 0,
    overdue_notification_sent TINYINT(1) DEFAULT 0,

    FOREIGN KEY (utente_id) REFERENCES utenti(id),
    FOREIGN KEY (libro_id) REFERENCES libri(id),
    FOREIGN KEY (copia_id) REFERENCES copie(id)
);
```

### Database - Tabella `prenotazioni`

```sql
CREATE TABLE prenotazioni (
    id INT PRIMARY KEY AUTO_INCREMENT,
    utente_id INT NOT NULL,
    libro_id INT NOT NULL,
    prestito_id INT,              -- Link al prestito quando convertita

    stato ENUM('attiva','completata','annullata'),
    queue_position INT,

    data_inizio_richiesta DATE,
    data_fine_richiesta DATE,
    data_scadenza_prenotazione DATE,

    created_at DATETIME,
    updated_at DATETIME,

    FOREIGN KEY (utente_id) REFERENCES utenti(id),
    FOREIGN KEY (libro_id) REFERENCES libri(id),
    FOREIGN KEY (prestito_id) REFERENCES prestiti(id)
);
```

### Stati Copia (tabella `copie`)

```sql
stato ENUM(
    'disponibile',     -- Pronta per il prestito
    'prestato',        -- Fisicamente con l'utente
    'prenotato',       -- Riservata (in attesa ritiro)
    'manutenzione',    -- In riparazione
    'in_restauro',     -- In restauro
    'perso',           -- Smarrita
    'danneggiato',     -- Rovinata
    'in_trasferimento' -- In trasferimento
)
```

### Servizi Correlati

| Servizio | Responsabilità |
|----------|----------------|
| `MaintenanceService` | Transizioni automatiche, scadenze |
| `NotificationService` | Invio email |
| `DataIntegrity` | Ricalcolo disponibilità |
| `ReservationManager` | Gestione code prenotazioni |
| `IcsGenerator` | Generazione calendario ICS |

### Hooks Disponibili

```php
// Approvazione
Hooks::do('before_loan_approve', $loan);
Hooks::do('after_loan_approve', $loan);

// Ritiro
Hooks::do('before_loan_pickup', $loan);
Hooks::do('after_loan_pickup', $loan);

// Restituzione
Hooks::do('before_loan_return', $loan);
Hooks::do('after_loan_return', $loan);

// Rinnovo
Hooks::do('before_loan_renew', $loan);
Hooks::do('after_loan_renew', $loan);

// Scadenze automatiche
Hooks::do('after_loan_expired', $loan);
Hooks::do('after_pickup_expired', $loan);
```

### Esempio: Verifica Disponibilità

```php
// Verifica se un libro è disponibile per un periodo
$startDate = '2024-02-01';
$endDate = '2024-02-28';
$bookId = 123;

// Conta copie prestabili
$totalCopies = Copia::where('libro_id', $bookId)
    ->whereNotIn('stato', ['perso', 'danneggiato', 'manutenzione'])
    ->count();

// Conta prestiti sovrapposti
$overlappingLoans = Prestito::where('libro_id', $bookId)
    ->where('attivo', 1)
    ->where(function($q) use ($startDate, $endDate) {
        $q->whereBetween('data_prestito', [$startDate, $endDate])
          ->orWhereBetween('data_scadenza', [$startDate, $endDate])
          ->orWhere(function($q2) use ($startDate, $endDate) {
              $q2->where('data_prestito', '<=', $startDate)
                 ->where('data_scadenza', '>=', $endDate);
          });
    })
    ->count();

// Conta prenotazioni sovrapposte
$overlappingReservations = Prenotazione::where('libro_id', $bookId)
    ->where('stato', 'attiva')
    // ... logica simile
    ->count();

$availableCopies = $totalCopies - $overlappingLoans - $overlappingReservations;
$isAvailable = $availableCopies > 0;
```

### Esempio: Processo Restituzione

```php
public function processReturn(int $loanId, string $status, ?float $sanction = null): void
{
    $loan = Prestito::findOrFail($loanId);

    DB::transaction(function() use ($loan, $status, $sanction) {
        // Aggiorna prestito
        $loan->stato = $status;
        $loan->attivo = 0;
        $loan->data_restituzione = now();

        if ($sanction) {
            $loan->sanzione = $sanction;
        }

        $loan->save();

        // Aggiorna copia
        $copy = $loan->copia;
        $copy->stato = match($status) {
            'restituito' => 'disponibile',
            'perso' => 'perso',
            'danneggiato' => 'danneggiato',
            default => 'disponibile'
        };
        $copy->save();

        // Ricalcola disponibilità
        DataIntegrity::recalculateBookAvailability($loan->libro_id);

        // Processa coda prenotazioni
        ReservationManager::processQueue($loan->libro_id);

        // Notifica wishlist
        if ($copy->stato === 'disponibile') {
            NotificationService::notifyWishlist($loan->libro_id);
        }
    });

    Hooks::do('after_loan_return', $loan);
}
```

---

## Checklist Operativa

### Nuovo Prestito
- [ ] Utente identificato
- [ ] Libro disponibile verificato
- [ ] Date selezionate
- [ ] Conflitti verificati
- [ ] Prestito creato

### Approvazione
- [ ] Richiesta valutata
- [ ] Copia assegnata
- [ ] Email inviata all'utente
- [ ] Pickup deadline impostato (se immediato)

### Ritiro
- [ ] Utente identificato
- [ ] Libro consegnato fisicamente
- [ ] Conferma registrata nel sistema
- [ ] Ricevuta/promemoria consegnato

### Restituzione
- [ ] Libro ricevuto
- [ ] Condizioni verificate
- [ ] Stato corretto selezionato
- [ ] Sanzione applicata (se necessario)
- [ ] Prossimo in coda notificato (se presente)

