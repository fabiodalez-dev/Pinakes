# Sistema Prestiti - Guida Completa

Pinakes gestisce i prestiti bibliotecari con supporto **multi-copia**, **prenotazioni future** e **coda d'attesa** automatica.

---

## Parte 1: Guida Utente

### Come Funziona il Sistema

Il flusso completo di un prestito:

| Fase | Cosa Succede | Chi Agisce | Stato |
|------|--------------|------------|-------|
| **1. Richiesta** | Utente chiede un libro | Utente | `pendente` |
| **2. Approvazione** | Admin approva e assegna copia | Admin | `prenotato` o `in_corso` |
| **3. Ritiro** | Utente ritira fisicamente il libro | Admin conferma | `in_corso` |
| **4. Utilizzo** | Utente legge il libro | - | `in_corso` |
| **5. Scadenza** | Sistema invia promemoria | Automatico | `in_corso` → `in_ritardo` |
| **6. Restituzione** | Libro torna in biblioteca | Admin | `restituito` |

### Per gli Utenti

#### Richiedere un Prestito
1. Trova il libro nel catalogo
2. Clicca **"Richiedi Prestito"**
3. Seleziona le date (se disponibile il calendario)
4. Conferma la richiesta
5. Attendi l'approvazione dell'admin (riceverai email)

#### Prenotare un Libro Occupato
1. Se il libro non e disponibile, clicca **"Prenota"**
2. Entrerai in coda d'attesa (vedrai la tua posizione: "#2 in coda")
3. Quando il libro viene restituito, riceverai una notifica
4. Avrai un tempo limite per ritirare (configurabile dall'admin)

#### Gestire i Tuoi Prestiti
Da `/profile/prestiti` puoi:
- Vedere i prestiti attivi con date e scadenze
- Controllare lo storico completo
- Richiedere un rinnovo (se abilitato)

### Per gli Amministratori

#### Approvare Richieste
1. Vai a **Dashboard → Prestiti**
2. Vedi le richieste con stato `pendente`
3. Clicca **"Approva"** per confermare
4. Il sistema assegna automaticamente una copia disponibile
5. L'utente riceve email di conferma

#### Gestire Restituzioni
1. L'utente porta il libro
2. Trova il prestito nella lista (o cerca per utente/libro)
3. Clicca **"Registra Restituzione"**
4. Il libro torna disponibile automaticamente

#### Creare Prestiti Manuali
Da `/admin/prestiti/crea`:
1. Seleziona utente e libro
2. Scegli le date di inizio e fine
3. Il sistema verifica la disponibilita
4. Se ok, crea il prestito direttamente in stato `in_corso` o `prenotato`

---

## Parte 2: Stati del Prestito

### Diagramma Stati

```
                    ┌─────────────┐
                    │   pendente  │ ← Richiesta utente
                    └──────┬──────┘
                           │ Admin approva
              ┌────────────┴────────────┐
              ▼                         ▼
    ┌─────────────────┐      ┌─────────────────┐
    │   prenotato     │      │    in_corso     │
    │ (data futura)   │      │ (data = oggi)   │
    └────────┬────────┘      └────────┬────────┘
             │ Data arriva            │
             └──────────┬─────────────┘
                        │
                        ▼
              ┌─────────────────┐
              │    in_corso     │
              └────────┬────────┘
                       │ Scadenza passata
                       ▼
              ┌─────────────────┐
              │   in_ritardo    │
              └────────┬────────┘
                       │ Restituzione
        ┌──────────────┼──────────────┐
        ▼              ▼              ▼
┌───────────┐  ┌───────────┐  ┌───────────┐
│restituito │  │   perso   │  │danneggiato│
└───────────┘  └───────────┘  └───────────┘
```

### Dettaglio Stati

| Stato | Descrizione | Flag `attivo` | Ha `copia_id`? |
|-------|-------------|---------------|----------------|
| `pendente` | Richiesta in attesa approvazione | 0 | No |
| `prenotato` | Approvato per data futura | 1 | Si |
| `in_corso` | Libro in mano all'utente | 1 | Si |
| `in_ritardo` | Scaduto, non restituito | 1 | Si |
| `restituito` | Completato con successo | 0 | Si (storico) |
| `perso` | Libro dichiarato perso | 0 | Si (storico) |
| `danneggiato` | Restituito danneggiato | 0 | Si (storico) |

### Badge Colori (UI)

| Colore | Stato | Significato |
|--------|-------|-------------|
| Arancione | `pendente` | In attesa approvazione |
| Viola | `prenotato` | Approvato, data futura |
| Blu | `in_corso` | Attivo, tutto ok |
| Giallo/Rosso | `in_ritardo` | Scaduto! |
| Verde | `restituito` | Completato |
| Rosso scuro | `perso`/`danneggiato` | Problema |

---

## Parte 3: Sistema Notifiche Email

### Template Disponibili

| Template | Destinatario | Quando |
|----------|--------------|--------|
| `loan_request_notification` | Admin | Nuova richiesta prestito |
| `loan_approved` | Utente | Prestito approvato |
| `loan_rejected` | Utente | Prestito rifiutato |
| `loan_expiring_warning` | Utente | X giorni prima scadenza |
| `loan_overdue_notification` | Utente | Prestito scaduto |
| `loan_overdue_admin` | Admin | Alert prestito in ritardo |
| `reservation_book_available` | Utente | Libro prenotato disponibile |
| `wishlist_book_available` | Utente | Libro wishlist disponibile |

### Logica Invio

**Promemoria scadenza** (`loan_expiring_warning`):
- Inviato X giorni prima della scadenza (configurabile, default: 3)
- Solo per prestiti `in_corso` (libro effettivamente ritirato)
- NON inviato per `pendente` o `prenotato`

**Notifica ritardo** (`loan_overdue_notification`):
- Inviato quando `data_scadenza < oggi`
- Solo per prestiti `in_corso` o `in_ritardo`
- Flag `overdue_notification_sent` previene duplicati

**Disponibilita libro** (`reservation_book_available`):
- Inviato quando l'utente e primo in coda e il libro torna disponibile
- Flag `notifica_inviata` impostato solo se email inviata con successo (permette retry)

### Configurazione

Da **Impostazioni → Avanzate**:
- `days_before_expiry_warning`: giorni prima della scadenza per il promemoria
- Template email personalizzabili da **Impostazioni → Email Templates**

---

## Parte 4: Coda d'Attesa (Prenotazioni)

### Come Funziona

1. Utente prenota un libro non disponibile
2. Entra nella tabella `prenotazioni` con `queue_position`
3. Quando il libro viene restituito:
   - `ReservationManager::processBookAvailability()` trova il primo in coda
   - Crea un prestito `pendente` con `origine = 'prenotazione'`
   - Invia email `reservation_book_available`
4. Admin approva → prestito diventa `in_corso`

### Priorita

La coda e FIFO (First In, First Out):
- `queue_position = 1` e il primo a ricevere il libro
- Quando una prenotazione viene completata, le posizioni si aggiornano

### Scadenza Prenotazioni

- Campo `data_scadenza_prenotazione` definisce per quanto la prenotazione e valida
- Prenotazioni scadute vengono automaticamente annullate
- L'utente deve riprenotare se la sua scadenza passa

---

## Parte 5: Multi-Copia

### Logica Disponibilita

Il sistema supporta piu copie fisiche per ogni libro:

```
Libro: "Il Nome della Rosa"
├── Copia #1 (disponibile)
├── Copia #2 (prestata fino al 15/12)
└── Copia #3 (in manutenzione)

Copie utilizzabili: 2 (esclusa #3)
Copie occupate oggi: 1 (#2)
Slot disponibili: 1
```

### Verifica Disponibilita

`ReservationManager::isBookAvailableForImmediateLoan($bookId, $startDate, $endDate)`:

1. Conta copie con stato NOT IN (`perso`, `danneggiato`, `manutenzione`)
2. Conta prestiti attivi che si sovrappongono al periodo richiesto
3. Conta prenotazioni attive che si sovrappongono
4. Disponibile se: `(prestiti + prenotazioni) < copie_utilizzabili`

### Assegnazione Copia

All'approvazione, il sistema:
1. Cerca una copia senza prestiti sovrapposti
2. Blocca la copia con `SELECT ... FOR UPDATE`
3. Ricontrolla l'overlap (prevenzione race condition)
4. Assegna la `copia_id` al prestito

---

## Parte 6: Documentazione Tecnica

### Schema Database

```sql
-- Tabella prestiti
CREATE TABLE prestiti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    libro_id INT NOT NULL,
    utente_id INT NOT NULL,
    copia_id INT NULL,  -- Assegnata all'approvazione
    data_prestito DATE NOT NULL,
    data_scadenza DATE NOT NULL,
    data_restituzione DATE NULL,
    stato ENUM('pendente','prenotato','in_corso','in_ritardo','restituito','perso','danneggiato'),
    origine ENUM('manuale','richiesta','prenotazione') DEFAULT 'richiesta',
    attivo TINYINT(1) DEFAULT 0,
    renewals INT DEFAULT 0,
    processed_by INT NULL,
    warning_sent TINYINT(1) DEFAULT 0,
    overdue_notification_sent TINYINT(1) DEFAULT 0,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (libro_id) REFERENCES libri(id),
    FOREIGN KEY (utente_id) REFERENCES utenti(id),
    FOREIGN KEY (copia_id) REFERENCES copie(id),
    FOREIGN KEY (processed_by) REFERENCES utenti(id),

    INDEX idx_stato_attivo (stato, attivo),
    INDEX idx_libro_stato (libro_id, stato),
    INDEX idx_scadenza (data_scadenza)
);

-- Tabella prenotazioni (coda d'attesa)
CREATE TABLE prenotazioni (
    id INT AUTO_INCREMENT PRIMARY KEY,
    libro_id INT NOT NULL,
    utente_id INT NOT NULL,
    data_inizio_richiesta DATE,
    data_fine_richiesta DATE,
    data_scadenza_prenotazione DATETIME,
    queue_position INT DEFAULT 1,
    stato ENUM('attiva','completata','annullata') DEFAULT 'attiva',
    notifica_inviata TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (libro_id) REFERENCES libri(id),
    FOREIGN KEY (utente_id) REFERENCES utenti(id),

    INDEX idx_libro_stato (libro_id, stato),
    INDEX idx_queue (libro_id, stato, queue_position)
);
```

### Controller Principali

```php
// PrestitiController - Gestione admin
class PrestitiController {
    public function index()      // Lista prestiti
    public function create()     // Form nuovo prestito
    public function store()      // Salva nuovo prestito
    public function show($id)    // Dettagli prestito
    public function close($id)   // Registra restituzione
    public function exportCsv()  // Export CSV
}

// LoanApprovalController - Approvazioni
class LoanApprovalController {
    public function pending()      // Lista richieste pendenti
    public function approveLoan()  // Approva richiesta
    public function rejectLoan()   // Rifiuta richiesta
}

// ReservationManager - Logica prenotazioni
class ReservationManager {
    public function processBookAvailability($bookId)
    public function isBookAvailableForImmediateLoan($bookId, $startDate, $endDate)
    public function cancelExpiredReservations()
}
```

### API Endpoints

```
GET  /api/prestiti              → Lista prestiti (DataTables)
GET  /api/libro/{id}/availability → Disponibilita per calendario
POST /api/prestiti/{id}/close   → Chiudi prestito
POST /api/prestiti/{id}/renew   → Rinnova prestito
```

### Cron Job

```bash
# Esegui notifiche automatiche ogni ora
0 * * * * php /path/to/cron/automatic-notifications.php

# Oppure aggiungi a crontab per esecuzione alle 8-20
0 8-20 * * * php /path/to/cron/automatic-notifications.php
```

Il cron esegue:
- Avvisi scadenza prestiti
- Notifiche prestiti in ritardo
- Notifiche wishlist disponibili
- (alle 6:00) Attivazione prestiti schedulati e aggiornamento stati

### Manutenzione su Admin Login

Se il cron non e configurato, `MaintenanceService` esegue le stesse operazioni quando un admin fa login, con cooldown di 60 minuti per evitare duplicati.

---

## Parte 7: Configurazione

### Impostazioni Disponibili

Da **Impostazioni → Biblioteca**:
- Durata prestito di default (giorni)
- Numero massimo rinnovi
- Giorni prima avviso scadenza

Da **Impostazioni → Email**:
- Template email personalizzabili
- Variabili disponibili per ogni template

### Trigger Database

I trigger in `triggers.sql` proteggono l'integrita:

1. **Prima dell'INSERT**: verifica che la copia non sia persa/danneggiata/manutenzione
2. **Prima dell'INSERT**: verifica che non ci siano overlap di date sulla stessa copia
3. **Prima dell'UPDATE**: stesse verifiche per modifiche

---

*Ultimo aggiornamento: 29 Novembre 2025*
*Versione: 2.0.0*
