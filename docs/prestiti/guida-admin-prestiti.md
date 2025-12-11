# 🔧 Guida Amministratore: Gestione Tecnica Prestiti

> Documentazione tecnica per bibliotecari e amministratori su come il sistema gestisce prestiti, copie multiple, stati e flussi di approvazione

---

## 📋 Indice

1. [Creazione Prestiti Amministratore](#creazione-prestiti-amministratore)
2. [Stati Prestito e Transizioni](#stati-prestito-e-transizioni)
3. [Gestione Copie Multiple](#gestione-copie-multiple)
4. [Sistema di Approvazione](#sistema-di-approvazione)
5. [Rinnovi Prestiti](#rinnovi-prestiti)
6. [Processo di Restituzione](#processo-di-restituzione)
7. [Gestione Conflitti e Race Conditions](#gestione-conflitti-e-race-conditions)
8. [Integrità Dati](#integrita-dati)

---

## 📝 Creazione Prestiti Amministratore

### Accesso Rapido

**Percorso**: Dashboard → Prestiti → **"+ Nuovo Prestito"**

### Campi Obbligatori

| Campo | Descrizione | Validazione |
|-------|-------------|-------------|
| **Utente** | Seleziona tramite ricerca nome/email | Deve esistere nel sistema |
| **Libro** | Seleziona tramite ricerca titolo/ISBN | Deve avere copie disponibili |
| **Data Prestito** | Data inizio (default: oggi) | Deve essere ≤ oggi per prestiti immediati |
| **Data Scadenza** | Data restituzione prevista | Deve essere > data prestito |

### Campi Opzionali

- **Note**: Annotazioni per uso interno (es: "Prestito prolungato per ricerca", "Libro con dedica dell'autore")

---

## 🎨 Stati Prestito e Transizioni

### Stati Disponibili

| Stato | Codice DB | Descrizione | Attivo |
|-------|-----------|-------------|--------|
| **Pendente** | `pendente` | Richiesta creata, in attesa approvazione | ✅ |
| **Prenotato** | `prenotato` | Prestito futuro pianificato | ✅ |
| **In Corso** | `in_corso` | Libro attualmente in prestito | ✅ |
| **In Ritardo** | `in_ritardo` | Scadenza superata, non restituito | ✅ |
| **Restituito** | `restituito` | Libro restituito entro scadenza | ❌ |
| **Perso** | `perso` | Libro dichiarato smarrito | ❌ |
| **Danneggiato** | `danneggiato` | Libro restituito danneggiato | ❌ |

### Diagramma Transizioni Stati

```
┌─────────────────────────────────────────────────────────────┐
│                    CREAZIONE PRESTITO                        │
└─────────────────────────────────────────────────────────────┘
                           ↓
                    ┌──────────────┐
                    │   PENDENTE   │ ← Richiesta da utente
                    └──────────────┘
                           │
                   [Admin Approva]
                           ↓
        ┌──────────────────┴──────────────────┐
        │                                     │
    [Immediato]                         [Futuro]
        ↓                                     ↓
┌──────────────┐                     ┌──────────────┐
│   IN_CORSO   │                     │  PRENOTATO   │
└──────────────┘                     └──────────────┘
        │                                     │
        │                        [Data inizio arriva]
        │                                     ↓
        │                            ┌──────────────┐
        │                            │   IN_CORSO   │
        │                            └──────────────┘
        │                                     │
        └─────────────┬───────────────────────┘
                      │
          [Scadenza superata]
                      ↓
              ┌──────────────┐
              │  IN_RITARDO  │
              └──────────────┘
                      │
              [Restituito]
                      ↓
        ┌─────────────┴─────────────┐
        │                           │
   [In tempo]                  [Oltre scadenza]
        ↓                           ↓
┌──────────────┐            ┌──────────────┐
│  RESTITUITO  │            │ IN_RITARDO + │
│              │            │  RESTITUITO  │
└──────────────┘            └──────────────┘
```

### Logica Automatica Stati

**Il sistema cambia automaticamente lo stato quando:**

1. **`in_corso` → `in_ritardo`**
   - Trigger: Data odierna > data_scadenza
   - Eseguito da: Cron job giornaliero (DataIntegrity::validateAndUpdateLoan)

2. **`prenotato` → `in_corso`**
   - Trigger: Data odierna ≥ data_prestito
   - Eseguito da: Cron job giornaliero

---

## 📚 Gestione Copie Multiple

### Concetto Base

**Un libro può avere N copie fisiche**. Il sistema gestisce:
- **Copie totali**: Conteggio esemplari posseduti
- **Copie disponibili**: Conteggio esemplari non prestati
- **Copie prestate**: Copie totali - Copie disponibili

### Tracciamento Copie

Ogni copia ha:
- **ID univoco** (`copie.id`)
- **Numero inventario** (codice fisico, es: "INV-2024-001")
- **Stato** (disponibile, prestato, perso, danneggiato, manutenzione)
- **Collocazione** (scaffale, mensola, posizione)

### Assegnazione Copia al Prestito

**Quando crei un prestito, il sistema:**

1. **Conta copie totali prestabili**
   ```sql
   SELECT COUNT(*) FROM copie
   WHERE libro_id = ?
   AND stato NOT IN ('perso', 'danneggiato', 'manutenzione')
   ```

2. **Conta prestiti attivi sovrapposti**
   ```sql
   SELECT COUNT(*) FROM prestiti
   WHERE libro_id = ? AND attivo = 1
   AND stato IN ('in_corso', 'prenotato', 'in_ritardo', 'pendente')
   AND data_prestito <= ? AND data_scadenza >= ?
   ```

3. **Verifica disponibilità**
   ```
   IF prestiti_attivi < copie_totali THEN
       → Copia disponibile
   ELSE
       → Errore: "Nessuna copia disponibile"
   ```

4. **Seleziona copia specifica senza overlap**
   ```sql
   SELECT c.id FROM copie c
   WHERE c.libro_id = ?
   AND c.stato IN ('disponibile', 'prenotato')
   AND NOT EXISTS (
       SELECT 1 FROM prestiti p
       WHERE p.copia_id = c.id
       AND p.attivo = 1
       AND p.stato IN ('in_corso', 'prenotato', 'in_ritardo', 'pendente')
       AND p.data_prestito <= ? AND p.data_scadenza >= ?
   )
   LIMIT 1
   ```

5. **Blocca la copia selezionata**
   ```sql
   SELECT id FROM copie WHERE id = ? FOR UPDATE
   ```

6. **Verifica race condition**
   - Ricontrolla se nel frattempo qualcun altro ha preso quella copia
   - Se sì: ROLLBACK e errore

7. **Assegna copia al prestito**
   ```sql
   INSERT INTO prestiti (libro_id, copia_id, ...)
   ```

8. **Aggiorna stato copia**
   - Prestito immediato: `stato = 'prestato'`
   - Prestito futuro: `stato = 'prenotato'`

### Esempio Pratico

```
Libro: "Il nome della rosa" (ID: 123)
Copie totali: 3

┌────────────────────────────────────────────────┐
│ COPIA #1 - INV-2024-001                        │
│ Stato: prestato                                │
│ Prestito: Mario Rossi (scad. 20/12/2025)      │
└────────────────────────────────────────────────┘

┌────────────────────────────────────────────────┐
│ COPIA #2 - INV-2024-002                        │
│ Stato: disponibile                             │
│ Prestito: nessuno                              │
└────────────────────────────────────────────────┘

┌────────────────────────────────────────────────┐
│ COPIA #3 - INV-2024-003                        │
│ Stato: manutenzione                            │
│ Prestito: non disponibile per prestiti        │
└────────────────────────────────────────────────┘

Copie prestabili: 2 (esclude manutenzione)
Copie in prestito: 1
Copie disponibili: 1

✅ Posso creare un nuovo prestito
   → Verrà assegnata copia #2
```

---

## ✅ Sistema di Approvazione

### Workflow a Due Fasi (v0.4.0+)

**Fase 1: Approvazione Admin**
- Admin valuta la richiesta
- Verifica disponibilità libro
- Controlla storico utente (ritardi precedenti)
- Approva o rifiuta

**Fase 2: Conferma Ritiro**
- Utente arriva fisicamente in biblioteca
- Admin/Totem conferma consegna libro
- Solo ora il prestito diventa "in_corso"

### Vantaggi Two-Step

| Aspetto | Problema Vecchio Sistema | Soluzione Two-Step |
|---------|--------------------------|-------------------|
| **Libri fantasma** | Approvato ma mai ritirato | Stato "approvato" separato |
| **Disponibilità fittizia** | Libro segnato prestato senza ritiro | Copia resta disponibile fino al ritiro |
| **Tracking accurato** | Difficile sapere se ritirato | Stati chiari: approvato/in_corso |

### Gestione Ritiro

**Percorso**: Prestiti → Prestito specifico → **"Conferma Ritiro"**

Il sistema:
1. Aggiorna stato: `approvato` → `in_corso`
2. Aggiorna stato copia: `disponibile` → `prestato`
3. Ricalcola disponibilità libro
4. Invia email conferma ritiro all'utente

---

## 🔄 Rinnovi Prestiti

### Limiti Rinnovi

- **Massimo rinnovi**: 3 per prestito (configurabile)
- **Giorni estensione**: +14 giorni per rinnovo

### Condizioni per Rinnovare

✅ **Prestito rinnovabile se:**
- Stato = `in_corso` (NON `in_ritardo`)
- Numero rinnovi < limite massimo (3)
- Nessuna prenotazione attiva sullo stesso libro

❌ **Prestito NON rinnovabile se:**
- Prestito in ritardo (`data_scadenza` superata)
- Già rinnovato 3 volte
- Qualcuno ha prenotato il libro (coda attiva)
- Prestito non attivo (restituito/perso/danneggiato)

### Processo Rinnovo

```
1. Admin clicca "Rinnova" sul prestito
   ↓
2. Sistema verifica condizioni
   ↓
3. Se OK:
   - Calcola nuova scadenza: data_scadenza + 14 giorni
   - Incrementa contatore: renewals = renewals + 1
   - Invia email: "Prestito rinnovato, nuova scadenza: XX/XX/XXXX"
   ↓
4. Se KO:
   - Mostra errore specifico:
     * "Prestito in ritardo, impossibile rinnovare"
     * "Limite rinnovi raggiunto"
     * "Libro prenotato da altri utenti"
```

### Codice Rinnovo

```php
// Calcola nuova scadenza (+14 giorni dalla scadenza ATTUALE, non da oggi)
$currentDueDate = $loan['data_scadenza'];
$newDueDate = date('Y-m-d', strtotime($currentDueDate . ' +14 days'));
$newRenewalCount = (int)$loan['renewals'] + 1;

// Aggiorna prestito
UPDATE prestiti
SET data_scadenza = '$newDueDate', renewals = $newRenewalCount
WHERE id = $loanId;
```

**⚠️ Nota**: La nuova scadenza parte dalla scadenza ATTUALE, non dalla data odierna. Questo previene di "perdere giorni" rinnovando in anticipo.

---

## 📦 Processo di Restituzione

### Accesso

**Percorso**: Prestiti → Prestito specifico → **"Restituisci"**

### Form Restituzione

**Campi:**
1. **Stato finale** (obbligatorio):
   - `restituito`: Libro restituito regolarmente
   - `in_ritardo`: Restituito oltre scadenza
   - `perso`: Libro dichiarato smarrito
   - `danneggiato`: Libro danneggiato

2. **Note** (opzionale):
   - Condizioni libro
   - Danni riscontrati
   - Altro

### Cosa Succede alla Restituzione

1. **Aggiorna prestito**
   ```sql
   UPDATE prestiti
   SET stato = ?, data_restituzione = CURDATE(), note = ?, attivo = 0
   WHERE id = ?
   ```

2. **Aggiorna stato copia**
   | Stato Prestito | Nuovo Stato Copia |
   |----------------|-------------------|
   | `restituito` | `disponibile` |
   | `in_ritardo` | `disponibile` |
   | `perso` | `perso` |
   | `danneggiato` | `danneggiato` |

3. **Ricalcola disponibilità libro**
   ```php
   $integrity->recalculateBookAvailability($libro_id);
   ```

4. **Processa prenotazioni**
   - Se ci sono utenti in coda
   - Notifica automaticamente il primo della lista
   - Converte prenotazione in prestito pendente

5. **Notifica wishlist**
   - Se ci sono utenti con libro in wishlist
   - Invia notifica "Libro disponibile"

6. **Email conferma**
   - All'utente: "Grazie per aver restituito il libro"

---

## ⚠️ Gestione Conflitti e Race Conditions

### Problema: Doppia Prenotazione Stessa Copia

**Scenario**:
- Libro con 1 copia disponibile
- Due admin cliccano "Nuovo Prestito" contemporaneamente
- Entrambi vedono "1 copia disponibile"
- Rischio: Assegnare la stessa copia a due prestiti

### Soluzione: Locking Ottimistico

```php
// 1. Inizia transazione
$db->begin_transaction();

try {
    // 2. Seleziona copia disponibile
    $copyStmt = $db->prepare("
        SELECT c.id FROM copie c
        WHERE c.libro_id = ?
        AND c.stato IN ('disponibile', 'prenotato')
        AND NOT EXISTS (
            SELECT 1 FROM prestiti p
            WHERE p.copia_id = c.id
            AND p.attivo = 1
            AND p.stato IN ('in_corso', 'prenotato', 'in_ritardo', 'pendente')
            AND p.data_prestito <= ? AND p.data_scadenza >= ?
        )
        LIMIT 1
    ");
    $copyId = ...;

    // 3. BLOCCA la copia selezionata (FOR UPDATE)
    $lockStmt = $db->prepare("SELECT id FROM copie WHERE id = ? FOR UPDATE");
    $lockStmt->bind_param('i', $copyId);
    $lockStmt->execute();

    // 4. RICONTROLLA che nessun altro l'abbia presa nel frattempo
    $overlapStmt = $db->prepare("
        SELECT 1 FROM prestiti
        WHERE copia_id = ? AND attivo = 1
        AND stato IN ('in_corso','prenotato','in_ritardo','pendente')
        AND data_prestito <= ? AND data_scadenza >= ?
        LIMIT 1
    ");
    $overlap = ...;

    if ($overlap) {
        // Race condition! Qualcun altro ha preso la copia
        $db->rollback();
        return error("Copia non più disponibile");
    }

    // 5. OK! Assegna la copia
    INSERT INTO prestiti (copia_id, ...) VALUES (?, ...);

    // 6. Aggiorna stato copia
    UPDATE copie SET stato = 'prestato' WHERE id = ?;

    // 7. Conferma transazione
    $db->commit();

} catch (Exception $e) {
    $db->rollback();
    throw $e;
}
```

### Protezioni Implementate

| Protezione | Tecnica | File |
|------------|---------|------|
| **Locking copia** | `SELECT ... FOR UPDATE` | PrestitiController.php:290 |
| **Ricontrollo overlap** | Query doppia verifica | PrestitiController.php:296 |
| **Transazioni** | `BEGIN ... COMMIT/ROLLBACK` | PrestitiController.php:118 |
| **Conteggi atomici** | Conta prima assegna dopo | PrestitiController.php:149 |

---

## 🔍 Integrità Dati

### Sistema DataIntegrity

Il sistema include controlli automatici di integrità che:

1. **Ricalcolano disponibilità**
   ```php
   // Dopo ogni operazione su prestiti/copie
   $integrity = new DataIntegrity($db);
   $integrity->recalculateBookAvailability($libro_id);
   ```

2. **Validano stati prestiti**
   ```php
   // Verifica coerenza stato prestito vs date
   $integrity->validateAndUpdateLoan($loan_id);
   ```

### Trigger Ricalcolo

Il ricalcolo disponibilità avviene:
- ✅ Dopo creazione prestito
- ✅ Dopo restituzione
- ✅ Dopo aggiunta/rimozione copia
- ✅ Dopo cambio stato copia
- ✅ Cron giornaliero (maintenance)

### Cosa Viene Ricalcolato

```php
// Copie disponibili
$disponibili = $copie_totali - $copie_prestate;

// Stato libro
if ($disponibili > 0) {
    $stato = 'disponibile';
} else {
    $stato = 'non_disponibile';
}

UPDATE libri
SET copie_disponibili = $disponibili, stato = $stato
WHERE id = $libro_id;
```

---

## 📊 Report e Export

### Export CSV Prestiti

**Percorso**: Prestiti → **"Esporta CSV"**

**Filtri disponibili:**
- Per stato (in_corso, restituito, in_ritardo, etc.)
- Tutti i prestiti

**Campi esportati:**
1. ID prestito
2. Titolo libro
3. Nome utente
4. Email utente
5. Data prestito
6. Data scadenza
7. Data restituzione
8. Stato
9. Numero rinnovi
10. Numero inventario copia
11. Admin che ha elaborato
12. Note

**Formato**: UTF-8 con BOM (compatibile Excel)

**Protezione CSV Injection**: Tutti i campi sanitizzati contro formula injection (prefisso `'` se iniziano con `=+-@`)

---

## 🎓 Best Practices per Admin

### ✅ DO

1. **Controlla sempre lo storico utente** prima di approvare
   - Ha prestiti in ritardo?
   - Ha restituito libri danneggiati?

2. **Verifica fisicamente la disponibilità**
   - Il libro è davvero sullo scaffale?
   - La collocazione è corretta?

3. **Usa le note** per tracciare situazioni particolari
   - "Prestito prolungato per tesi"
   - "Libro con dedica autografa"

4. **Conferma ritiro solo quando l'utente è fisicamente presente**
   - Non anticipare la conferma ritiro
   - Usa lo stato "approvato" per tenere traccia

5. **Sollecita i ritardi tempestivamente**
   - Invia promemoria a 3 giorni dalla scadenza
   - Contatta personalmente per ritardi > 7 giorni

### ❌ DON'T

1. **Non creare prestiti "al volo" senza verifiche**
   - Potrebbe causare copie fantasma

2. **Non ignorare i warning del sistema**
   - "Nessuna copia disponibile"
   - "Utente ha prestiti in ritardo"

3. **Non rinnovare prestiti in ritardo**
   - Prima restituzione, poi nuovo prestito

4. **Non modificare manualmente i database**
   - Usa sempre l'interfaccia admin
   - I trigger automatici mantengono la coerenza

---

## 🔗 Riferimenti

- [Sistema Prenotazioni →](./prenotazioni.md)
- [Calendario Disponibilità →](./calendario.md)
- [Gestione Copie →](../libri/gestione-copie.md)
- [Developer: LoanRepository →](../developer/repositories.md)

---

**Ultima modifica**: Dicembre 2025
**Versione Pinakes**: v0.4.1
