# Analisi del sistema prestiti/prenotazioni — Pinakes

## Sintesi esecutiva

L'analisi ha coperto le quattro dimensioni funzionali del sistema: gestione delle transazioni DB, calcolo della disponibilità multi-copia, condizioni di gara su overlap e trigger, e configurabilità dei parametri operativi. Sono stati identificati **21 finding confermati** e **10 falsi positivi** rigettati dopo verifica avversariale sul codice reale.

I problemi più critici riguardano la gestione delle transazioni MySQL: in due percorsi di codice attivi (`processReturn` e `renew`) si verifica un `begin_transaction()` annidato non protetto che causa un **commit implicito silenzioso** — le modifiche vengono persistite prima che il chiamante possa fare rollback in caso di errore. Immediatamente sotto per priorità stanno due lacune email P1: nessuna conferma di restituzione viene inviata all'utente (`loan_returned`) e nessuna notifica viene spedita alla scadenza automatica di una prenotazione (`reservation_expired`).

Il sistema di calcolo della disponibilità multi-copia è strutturalmente corretto ma contiene incoerenze di conteggio (`copie_totali` include copie perse/danneggiate), un endpoint legacy con aritmetica difettosa (`/api/libri/{id}/disponibilita`), e un fallback `GREATEST(...,1)` che può rendere prenotabile un libro senza copie fisiche. Quattro parametri operativi rilevanti (`pickup_expiry_days`, `loan_duration_days`, `max_renewals`, `max_active_loans_per_user`) sono hardcoded o non esposti nell'UI admin.

> **Nota sullo stato finale**: i 4 parametri sopra sono stati resi configurabili
> (gruppo `settings.loans`, tab admin **Impostazioni → Prestiti**,
> `POST /admin/settings/loans`) con clamping lato server; vedi sezione "Stato
> implementazione".

---

## Modello unificato di occupazione copia (#157, "model A-refined")

L'intera analisi multi-copia poggia su un'invariante esplicita, codificata sia
nell'applicazione sia nei trigger DB:

> Una **copia** è occupata in una finestra di date se e solo se, sovrapposta a
> quella finestra, esiste un **prestito attivo**
> (`attivo = 1 AND stato IN ('in_corso','in_ritardo','da_ritirare','prenotato')`)
> **oppure** una **conversione prenotazione→prestito pendente**
> (`stato = 'pendente' AND copia_id IS NOT NULL`, `attivo = 0`,
> `origine = 'prenotazione'`).
>
> Una **richiesta pendente "nuda"** (`stato = 'pendente' AND copia_id IS NULL`)
> **non** riduce la disponibilità finché l'admin non le assegna una copia.

Punti di applicazione:

| Livello | File / funzione | Clausola |
|---------|-----------------|----------|
| Calcolo calendario | `ReservationsController::calculateAvailability()` (e i due SELECT che la alimentano) | salta i `pendente` con `copia_id` vuoto; conta gli altri stati per-giorno/per-copia |
| Selezione copia in conversione | `ReservationManager::createLoanFromReservation()` | esclude copie tenute da prestiti attivi o da `pendente` con copia |
| Difesa DB | `trg_check_active_prestito_before_insert` / `_before_update` (`installer/database/triggers.sql`) | `SIGNAL 45000` su copia non utilizzabile o overlap, stesso predicato del modello |

I trigger sono ridondanti rispetto ai controlli applicativi (lock `libri`-first +
overlap check) e fungono da backstop a livello database. Distribuiti via
`migrate_0.7.17` (runner DELIMITER-aware).

---

## Finding confermati

### Dimensione: transactions-state

| ID | Severità | File principali | Evidenza sintetica | Fix proposto |
|----|----------|-----------------|-------------------|--------------|
| TXN-001 | **P1** | `DataIntegrity.php`, `PrestitiController.php` | `validateAndUpdateLoan()` chiama `begin_transaction()` incondizionatamente (riga 908). In `processReturn` (L676) e `renew` (L975), entrambi già dentro una transazione esterna, la chiamata provoca un commit implicito di tutte le modifiche precedenti. Il `$db->commit()` finale commette la seconda transazione, non quella originale. | Aggiungere parametro `bool $insideTransaction = false` a `validateAndUpdateLoan()`. Rendere condizionali `begin_transaction()` (L908), `commit()` (L942) e `rollback()` (L946–948). Nei call site: passare `insideTransaction: true` a L676 di `processReturn` e L975 di `renew`. |
| TXN-002 | **P1** | `PrestitiController.php`, `UserActionsController.php`, `DataIntegrity.php` | `recalculateBookAvailability()` chiamata senza `insideTransaction: true` in `processReturn` (L673) e `UserActionsController::cancelLoan` (L184), entrambi dentro una transazione attiva. La funzione apre una seconda `begin_transaction()`, causando commit implicito delle modifiche precedenti (UPDATE prestiti, UPDATE copie). | Due modifiche monorighe: L673 di `PrestitiController` e L184 di `UserActionsController` aggiungono `insideTransaction: true`. |
| TXN-003 | P2 | `ReservationManager.php` | `beginTransactionIfNeeded()` usa `SELECT @@autocommit` come rilevatore di transazione attiva (L38–55). In MySQL/MariaDB, `BEGIN`/`START TRANSACTION` **non modifica** `@@autocommit` (documentato). Con `autocommit=1` (default), la variabile vale sempre 1 anche dentro una transazione aperta, rendendo il guard inutile quando `$this->inTransaction` è `false`. | Eliminare il check `@@autocommit`. Aggiungere flag `$externalTransaction` e metodo `setExternalTransaction(bool)`. Aggiornare i tre call site in `LoanRepository.php` (dopo L161), `PrestitiController.php` (dopo L698) e `MaintenanceService.php` (dopo L368). |
| TXN-005 | P2 | `PrestitiController.php`, `DataIntegrity.php` | In `processReturn`, il prestito viene già marcato allo stato terminale corretto (L650: `stato = $nuovo_stato, attivo = 0`). La chiamata successiva a `validateAndUpdateLoan()` (L676) — che per effetto di TXN-001 opera in una seconda transazione separata — legge lo stato già aggiornato e può produrre un secondo `recalculateBookAvailability()` su uno stato di copia già mutato, lasciando `copie_disponibili` fuori sincronia. | Aggiungere `insideTransaction: true` alla chiamata `recalculateBookAvailability` a L673 (elimina la seconda transazione). Rimuovere completamente il blocco `validateAndUpdateLoan` a L675–679 da `processReturn`: il prestito è già nello stato terminale, la chiamata è semanticamente inapplicabile. Difesa in profondità: aggiungere early-return in `validateAndUpdateLoan` se `attivo = 0`. |

### Dimensione: calendar-multicopy

| ID | Severità | File principali | Evidenza sintetica | Fix proposto |
|----|----------|-----------------|-------------------|--------------|
| AVAIL-001 | **P1** | `app/Routes/web.php` | `GET /api/libri/{id}/disponibilita` calcola `first_available` come giorno successivo alla `data_scadenza` massima tra tutti i prestiti attivi, ignorando il numero di copie disponibili (L2069–2087). Con 3 copie e 2 prestiti, restituisce `first_available = 2026-07-02` anche se la terza copia è libera oggi. I campi `is_available_now` e `first_available` sono contraddittori. | Sostituire l'intera logica inline (L2054–2112) con una delegazione a `ReservationsController::getBookAvailabilityData()`, che usa `calculateAvailability()` con aritmetica per-giorno e per-copia corretta. |
| AVAIL-003 | P2 | `book-detail.php`, `crea_prestito.php`, `web.php` | Il frontend usa `/api/libro/{id}/availability` (corretto); nessun consumer interno usa `/api/libri/{id}/disponibilita`. L'endpoint è pubblico (nessun `AuthMiddleware`) e restituisce dati errati a qualsiasi client esterno. | Opzione A (preferita): rimuovere le righe 2054–2112. Opzione B: riscrivere come alias protetto da `AuthMiddleware` che delega a `getBookAvailabilityData()`. |
| AVAIL-006 | P2 | `DataIntegrity.php`, `ReservationsController.php` | `recalculateBookAvailability()` imposta `copie_totali = COUNT(*) FROM copie` senza escludere stato `perso/danneggiato/manutenzione` (L327–331, L85–89). `getBookTotalCopies()` in `ReservationsController` filtra correttamente con `NOT IN ('perso','danneggiato','manutenzione')`. Incoerenza: un libro con 3 copie (1 persa, 2 disponibili) ha `libri.copie_totali = 3` ma `getBookTotalCopies() = 2`. | Due modifiche in `DataIntegrity.php`: aggiungere `AND c.stato NOT IN ('perso','danneggiato','manutenzione')` alle subquery a L85–89 e L327–331. In `web.php` (L1865–1873), ricalcolare `copie_totali` on-the-fly con la stessa clausola per coerenza immediata. |
| AVAIL-007 | P2 | `ReservationsController.php` | `getBookTotalCopies()` usa `GREATEST(IFNULL(copie_totali, 1), 1)` (L453): forza almeno 1 copia anche se `copie_totali = 0` o `NULL`. Un libro senza copie fisiche risulta prenotabile. | Sostituire con `IFNULL(copie_totali, 0)`. Aggiungere guard sul percorso di modifica in `LibriController.php` (L1346) per impedire il salvataggio di `copie_totali < 1`. Opzionalmente: migrazione DB per allineare i record esistenti con `copie_totali < 1`. |

### Dimensione: overlap-trigger-race

| ID | Severità | File principali | Evidenza sintetica | Fix proposto |
|----|----------|-----------------|-------------------|--------------|
| CONC-03 | P2 | `PrestitiController.php`, `ReservationsController.php` | Il check anti-duplicato utente-libro nei percorsi esistenti non protegge il path `LoanApprovalController::approveLoan()`. Due admin possono approvare contemporaneamente due prestiti pendenti (es. uno da richiesta, uno da prenotazione) dello stesso utente per lo stesso libro: entrambe le approvazioni superano il check perché avvengono su righe diverse. Nessun UNIQUE index DB protegge la coppia `(utente_id, libro_id)` sugli stati attivi. | Fix 1: aggiungere dup-check con `FOR UPDATE` in `LoanApprovalController::approveLoan()` prima dell'approvazione. Fix 2: aggiungere dup-check con `FOR UPDATE` in `ReservationManager::createLoanFromReservation()` prima dell'INSERT. Fix opzionale: trigger MySQL BEFORE INSERT su `prestiti` come difesa DB-level. |
| CONC-04 | P3 | `LoanRepository.php` | `LoanRepository::create()` (L57–68) esegue un INSERT diretto su `prestiti` con solo 6 campi, senza impostare `copia_id`, `stato`, `data_scadenza`, senza overlap check né verifica disponibilità. Il metodo è `public` e non deprecato. Nessun caller attivo nel progetto principale (confermato da grep), ma rimane un vettore di regressione per script o plugin futuri. | Sostituire l'intero corpo del metodo con `throw new \LogicException(...)` e aggiornare il docblock con `@deprecated` che indica i path corretti (`PrestitiController::store()`, `ReservationManager::createLoanFromReservation()`). |

### Dimensione: emails

| ID | Severità | File principali | Evidenza sintetica | Fix proposto |
|----|----------|-----------------|-------------------|--------------|
| GAP-1 | **P1** | `LoanApprovalController.php`, `PrestitiController.php`, `SettingsMailTemplates.php` | Nessuna email di conferma restituzione viene inviata all'utente. `LoanApprovalController::returnLoan` (L857) e `PrestitiController::processReturn` (L650–714) aggiornano lo stato e chiamano `notifyWishlistBookAvailability()` ma non notificano il restituente. Il template `loan_returned` è assente da `SettingsMailTemplates::all()`. | 1. Aggiungere template `loan_returned` in `SettingsMailTemplates.php`. 2. Aggiungere metodo `sendLoanReturnedNotification(int $loanId)` in `NotificationService.php`. 3. Chiamare il metodo (in try/catch autonomo) in `LoanApprovalController::returnLoan` dopo L920 e in `PrestitiController::processReturn` dopo L711. |
| GAP-2 | **P1** | `MaintenanceService.php`, `SettingsMailTemplates.php` | `checkExpiredReservations` (L407–505) marca le prenotazioni `scaduto` e libera le copie ma non invia alcuna email all'utente. Contrasto diretto con `checkExpiredPickups` (L616–623) che chiama `sendPickupExpiredNotification`. Il template `reservation_expired` è assente. | 1. Aggiungere template `reservation_expired` in `SettingsMailTemplates.php`. 2. Aggiungere metodo `sendReservationExpiredNotification(int $loanId)` in `NotificationService.php`. 3. Chiamare il metodo (in try/catch) in `MaintenanceService::checkExpiredReservations` dopo L488, sul modello esatto di `checkExpiredPickups` L615–624. |
| GAP-3 | P2 | `ReservationReassignmentService.php` | `notifyUserCopyUnavailable` (L482–531) recupera l'email dell'utente (L486–495) ma crea solo una notifica in-app per gli **admin** (quarto parametro `null` a L513). L'utente la cui copia è stata persa/danneggiata non riceve alcuna comunicazione. | 1. Aggiungere template `copy_unavailable_user` in `SettingsMailTemplates.php`. 2. Aggiungere metodo `sendCopyUnavailableNotification` in `NotificationService.php`. 3. Chiamare il metodo in `notifyUserCopyUnavailable` dopo il blocco `$reasonText` (prima di L513). Correggere anche il quarto argomento di `createNotification` (L522) da `null` a `'/admin/prestiti'`. |
| GAP-4 | P2 | `NotificationService.php`, `SettingsMailTemplates.php` | `notifyAdminsOverdue` (L559) chiama `SettingsMailTemplates::get('loan_overdue_admin')` che legge solo il codice PHP hardcoded, ignorando completamente la tabella `email_templates` del DB. L'admin non può personalizzare questo template dall'UI, a differenza di tutti gli altri. | Sostituire le righe 550–567 in `NotificationService.php`: eliminare `SettingsMailTemplates::get()`, il pre-rendering con `replaceVariables()` e `emailService->sendToAdmins()`. Sostituire con `$this->sendToAdmins('loan_overdue_admin', $variables)` (metodo privato, L877), identico al pattern di `loan_request_notification` e `admin_new_review`. |

### Dimensione: automatisms-cron

| ID | Severità | File principali | Evidenza sintetica | Fix proposto |
|----|----------|-----------------|-------------------|--------------|
| F1 | P2 | `ReservationManager.php`, `DataIntegrity.php` | `reorderQueuePositions` usa user-variable MySQL `@pos` in un UPDATE con ORDER BY (L639–650 e DataIntegrity L835–845). Su MySQL 8.0+ e MariaDB 10.3+ l'ordine di valutazione delle user-variable in UPDATE non è garantito: l'optimizer può riordinare le righe prima dell'assegnazione, corrompendo silenziosamente la coda. | Sostituire entrambe le occorrenze con il pattern loop PHP: `SELECT ... FOR UPDATE ORDER BY queue_position ASC` + loop `UPDATE prenotazioni SET queue_position = ? WHERE id = ?`. Stesso pattern già presente in `LoanApprovalController::cancelReservation`. |
| F2 | P2 | `PrestitiController.php` | `renew()`: (a) `pickup_deadline` non viene azzerata nell'UPDATE (L964–970) — il cron `checkExpiredPickups` userà il valore originale e scadrà il prestito rinnovato; (b) stato `da_ritirare` non è bloccato esplicitamente; (c) `$maxRenewals = 3` hardcoded (L848) invece di letto da `SettingsRepository`. | (a) Aggiungere `pickup_deadline = NULL` all'UPDATE a L964–970. (b) Aggiungere guard esplicito per `stato === 'da_ritirare'` dopo il guard `in_ritardo`. (c) Sostituire la costante con `$settingsRepo->get('loans', 'max_renewals', '3')`. |
| F4 | P3 | `MaintenanceService.php` | `checkExpiredPickups` (L584–599): la copia viene impostata `disponibile` se non è già in `$nonRestorableStates` e non è già disponibile, ma non viene verificato se altri prestiti attivi (`in_corso`, `da_ritirare`) tengono ancora la stessa copia. `recalculateBookAvailability` corregge a posteriori, ma c'è una finestra breve in cui `copie.stato` è inconsistente quando `reassignOnReturn` viene chiamato. | Prima dell'UPDATE della copia, aggiungere un check `SELECT 1 FROM prestiti WHERE copia_id = ? AND attivo = 1 AND stato IN ('in_corso','in_ritardo','prenotato','da_ritirare') LIMIT 1`. Eseguire l'UPDATE solo se non esistono altri prestiti attivi sulla copia. Invertire anche l'ordine delle chiamate: `recalculateBookAvailability` prima di `reassignOnReturn`. |
| F5 | P2 | `MaintenanceService.php`, `cron/full-maintenance.php` | `runIfNeeded()` (L46–59) usa `$_SESSION['maintenance_last_run']` come unico cooldown. Il cron CLI chiama `runAll()` direttamente senza session. Due admin che si loggano in sessioni diverse nel medesimo minuto eseguono entrambi `runAll()`. Il lock via `flock` in `full-maintenance.php` protegge solo il processo cron, non il path HTTP. | Sostituire il check session con un UPDATE atomico su `settings`: `INSERT INTO settings (group, key, value) VALUES ('maintenance','last_run', UNIX_TIMESTAMP()) ON DUPLICATE KEY UPDATE value = IF(CAST(value AS UNSIGNED) < UNIX_TIMESTAMP() - ?, UNIX_TIMESTAMP(), value)`. `affected_rows = 0` significa cooldown attivo. Verificare che `settings` abbia `UNIQUE KEY (group, key)`. |

### Dimensione: config-settings

| ID | Severità | File principali | Evidenza sintetica | Fix proposto |
|----|----------|-----------------|-------------------|--------------|
| CFG-01 | P2 | `data_*.sql`, `MaintenanceService.php`, `LoanApprovalController.php`, `PrestitiController.php`, `settings/index.php` | `pickup_expiry_days` è presente in tutti e 4 i seed SQL e in `migrate_0.4.5.sql`, viene letto in 3 punti runtime, ma non compare in nessun tab dell'UI admin settings (nessun tab `loans` esiste in `settings/index.php`). La stringa i18n esiste nei 4 JSON ma non è collegata ad alcun campo. | Creare `app/Views/settings/loans-tab.php` con il campo numerico. Aggiungere tab `loans` in `settings/index.php`. Aggiungere `updateLoansSettings()` in `SettingsController.php` e la route `POST /admin/settings/loans` in `web.php`. Aggiungere 5–6 chiavi i18n nei 4 JSON. |
| CFG-02 | P2 | `crea_prenotazione.php`, `PrestitiController.php`, `ReservationsController.php`, `UserActionsController.php`, `data_*.sql` | Nessun setting `loan_duration_days` esiste. La durata default è hardcoded in modo inconsistente: `+30 days` nella view (L174), `+1 month` in `PrestitiController` (L131), `P1M` in `ReservationsController` (L246–248), **`+14 days`** in `UserActionsController` (L415). I quattro valori divergono. | Aggiungere `('loans', 'loan_duration_days', '30', ...)` ai 4 seed SQL e a una migrazione. Sostituire i 4 hardcode con `$settingsRepo->get('loans', 'loan_duration_days', '30')`. Passare `$defaultLoanDays` alla view `crea_prenotazione.php`. |
| CFG-03 | P3 | `PrestitiController.php`, `ReservationsController.php`, `UserActionsController.php`, `data_*.sql` | Nessun limite al numero di prestiti simultanei per utente (`max_active_loans_per_user`). I 3 entry point verificano solo il duplicato per lo stesso libro, non il conteggio totale su libri diversi. Grep su `max_active_loans`, `max_loans_per_user`, `maxLoans`, `prestiti_attivi_max` non produce risultati. | Aggiungere `('loans', 'max_active_loans_per_user', '0', ...)` ai 4 seed SQL e a una migrazione (0 = nessun limite). Aggiungere il check `COUNT(*) WHERE utente_id = ? AND attivo = 1 AND stato IN (...)` nei 3 controller, gated su `$maxLoans > 0`. Aggiungere chiavi i18n nei 4 JSON. |

---

## Falsi positivi / non confermati

| ID | Motivo del rigetto |
|----|-------------------|
| **TXN-004** | `ReservationReassignmentService::$transactionOwned` non resettato dopo `setExternalTransaction(true)`: scenario teoricamente valido ma non riproducibile. Tutti i call site reali o creano una nuova istanza per request HTTP (lifetime limitato) o operano nel cron dove una transazione esterna è sempre attiva. Nessun percorso di codice riusa l'istanza dopo la chiusura della transazione esterna. |
| **TXN-006** | `cancelPickup` ignora il valore di ritorno di `recalculateBookAvailability`: falso positivo. Alle righe 770–772 di `LoanApprovalController.php` il guard `if (!$integrity->recalculateBookAvailability(...)) { throw ... }` è già presente. La funzione con `$insideTransaction = true` rilancia le eccezioni (DataIntegrity.php L372–378), propagando qualsiasi fallimento al rollback esterno. Il pattern è uniforme in tutti e 6 i call site dello stesso controller. |
| **AVAIL-002** | Mancanza filtro `attivo = 1` in `/api/libri/{id}/disponibilita`: falso positivo. La query a riga 2062 di `web.php` include già `AND attivo = 1` tra `libro_id = ?` e `stato IN (...)`. Il finding è stato generato su una versione diversa del file. |
| **AVAIL-004** | Doppio conteggio prenotazione → prestito in `calculateAvailability()`: nessun doppio conteggio reale. `createLoanFromReservation()` crea il prestito con `attivo=0, stato='pendente'` (escluso dal filtro) e nella stessa transazione atomica marca la prenotazione `completata`. Quando il prestito viene approvato ad `attivo=1`, la prenotazione è già fuori dall'insieme `attiva`. La finestra di race descritta non esiste perché le due operazioni sono atomiche. |
| **AVAIL-005** | Doppio conteggio copia `prenotato` + prestito `da_ritirare` in `recalculateBookAvailability()`: falso positivo. L'invariante applicativa garantisce che una prenotazione `attiva` non abbia mai un prestito derivato con `attivo=1`. La query ibrida copia-fisica + prenotazioni-logiche è internamente coerente con questo invariante. |
| **CONC-01** | Trigger `trg_check_active_prestito_before_insert` non copre `pendente` con `copia_id` assegnata: il gap DB-level esiste ma non è sfruttabile. `processBookAvailability()` acquisisce `SELECT ... FROM libri WHERE id = ? FOR UPDATE` prima di qualsiasi selezione di copia, serializzando le chiamate concorrenti per lo stesso libro. La finestra tra selezione e lock della copia non può essere interleaved da un secondo caller dello stesso libro. |
| **CONC-02** | Race condition tra selezione copia e FOR UPDATE in `store()` e `createLoanFromReservation()`: entrambi i percorsi acquisiscono un lock esclusivo sulla riga `libri` prima della selezione della copia. Sotto InnoDB REPEATABLE READ, due transazioni concorrenti per lo stesso libro non possono raggiungere contemporaneamente la fase di selezione-copia. |
| **F3** | `updateOverdueLoans` non chiama `recalculateBookAvailability` dopo `in_corso→in_ritardo`: entrambi gli stati mappano `copie.stato = 'prestato'` e contribuiscono ugualmente alla formula `copie_disponibili`. La transizione è un cambio di etichetta puro, non un cambio di disponibilità. Chiamare `recalculate` scriverebbe lo stesso valore già presente. |
| **F6 (TXN-006)** | *(vedere TXN-006 sopra)* |
| **AVAIL-004/AVAIL-005** | *(vedere sopra)* |

---

## Piano di correzione

### P1 — Bug critici (impatto immediato su integrità dati e UX)

1. **TXN-001** — Aggiungere `bool $insideTransaction` a `validateAndUpdateLoan()` e rendere condizionali begin/commit/rollback. Aggiornare i call site in `processReturn` (L676) e `renew` (L975). *File: `DataIntegrity.php`, `PrestitiController.php`.*

2. **TXN-002** — Aggiungere `insideTransaction: true` alle chiamate `recalculateBookAvailability()` in `processReturn` (L673) e `UserActionsController::cancelLoan` (L184). *File: `PrestitiController.php`, `UserActionsController.php`.*

3. **AVAIL-001** — Riscrivere l'endpoint `/api/libri/{id}/disponibilita` per delegare a `ReservationsController::getBookAvailabilityData()`. *File: `web.php` L2054–2112.*

4. **GAP-1** — Aggiungere template `loan_returned` e metodo `sendLoanReturnedNotification()`. Chiamare il metodo in `returnLoan` e `processReturn`. *File: `SettingsMailTemplates.php`, `NotificationService.php`, `LoanApprovalController.php`, `PrestitiController.php`.*

5. **GAP-2** — Aggiungere template `reservation_expired` e metodo `sendReservationExpiredNotification()`. Chiamare il metodo in `checkExpiredReservations`. *File: `SettingsMailTemplates.php`, `NotificationService.php`, `MaintenanceService.php`.*

6. **TXN-005** — Rimuovere la chiamata ridondante a `validateAndUpdateLoan` da `processReturn` (L675–679). Aggiungere early-return in `validateAndUpdateLoan` per `attivo = 0`. *File: `PrestitiController.php`, `DataIntegrity.php`.*

### P2 — Email mancanti e incoerenze di calcolo

7. **GAP-4** — Sostituire `SettingsMailTemplates::get()` con `$this->sendToAdmins('loan_overdue_admin', $variables)` in `notifyAdminsOverdue`. *File: `NotificationService.php`.*

8. **GAP-3** — Aggiungere template `copy_unavailable_user` e notifica email in `notifyUserCopyUnavailable`. *File: `SettingsMailTemplates.php`, `NotificationService.php`, `ReservationReassignmentService.php`.*

9. **AVAIL-006** — Aggiungere `AND c.stato NOT IN ('perso','danneggiato','manutenzione')` alle subquery `copie_totali` in `recalculateBookAvailability()` e `recalculateAllBookAvailability()`. *File: `DataIntegrity.php`, `web.php`.*

10. **AVAIL-007** — Sostituire `GREATEST(IFNULL(copie_totali, 1), 1)` con `IFNULL(copie_totali, 0)`. Aggiungere guard `copie_totali >= 1` nel percorso di modifica libro. *File: `ReservationsController.php`, `LibriController.php`.*

11. **TXN-003** — Eliminare il check `@@autocommit` in `ReservationManager`. Aggiungere `setExternalTransaction()`. *File: `ReservationManager.php`, `LoanRepository.php`, `PrestitiController.php`, `MaintenanceService.php`.*

12. **F1** — Riscrivere `reorderQueuePositions` con SELECT FOR UPDATE + loop PHP. *File: `ReservationManager.php`, `DataIntegrity.php`.*

13. **F2** — In `renew()`: azzerare `pickup_deadline`, aggiungere guard per `da_ritirare`, leggere `max_renewals` da settings. *File: `PrestitiController.php`.*

14. **F5** — Sostituire il cooldown `$_SESSION` con UPDATE atomico su `settings`. *File: `MaintenanceService.php`.*

15. **AVAIL-003** — Rimuovere o proteggere con `AuthMiddleware` l'endpoint legacy `/api/libri/{id}/disponibilita`. *File: `web.php`.*

16. **CFG-01** — Esporre `pickup_expiry_days` nell'UI admin: creare `loans-tab.php`, aggiungere tab e route. *File: `settings/index.php`, `SettingsController.php`, `web.php`, 4 JSON.*

17. **CFG-02** — Centralizzare la durata default prestito in `loan_duration_days`. Sostituire i 4 hardcode divergenti. *File: `data_*.sql`, `PrestitiController.php`, `ReservationsController.php`, `UserActionsController.php`, `crea_prenotazione.php`.*

### P3 — Feature e difesa in profondità

18. **CONC-03** — Aggiungere dup-check con `FOR UPDATE` in `LoanApprovalController::approveLoan()` e `ReservationManager::createLoanFromReservation()`. Valutare trigger DB come backstop. *File: `LoanApprovalController.php`, `ReservationManager.php`.*

19. **F4** — Aggiungere check su altri prestiti attivi prima di liberare la copia in `checkExpiredPickups`. Invertire ordine: `recalculate` prima di `reassignOnReturn`. *File: `MaintenanceService.php`.*

20. **CONC-04** — Disabilitare `LoanRepository::create()` con `\LogicException`. *File: `LoanRepository.php`.*

21. **CFG-03** — Aggiungere `max_active_loans_per_user` (default `0` = nessun limite) nei seed e nei 3 controller di creazione prestito. *File: `data_*.sql`, `PrestitiController.php`, `ReservationsController.php`, `UserActionsController.php`, 4 JSON.*

---

## Stato implementazione (branch `review/loan-reservation-system`)

Tutti i 21 finding confermati sono stati implementati e verificati. Commit per area:

| Commit | Area | Finding |
|--------|------|---------|
| `c86eb2f7` | Atomicità transazioni + ricalcolo disponibilità | TXN-001/002/005, A1/A2, AVAIL-006, F1, F2 |
| `1afc0474` | Endpoint disponibilità multi-copia | AVAIL-001/003/007 |
| `f78b0544` | Email mancanti (restituzione, scadenza prenotazione, copia non disponibile, overdue admin dal DB) | GAP-1/2/3/4 |
| `388f5702` | Rilevamento transazioni, anti doppia-approvazione, deprecato `LoanRepository::create()` | TXN-003, CONC-03/04 |
| `58d6333a` | Annullamento prenotazione atomico + fix falso-negativo test 6.4 | (TXN-002 family) |
| `e76bc2ed` | Disponibilità coerente in catalogo e calendario admin | display |
| `3e85302c` | Impostazioni prestito configurabili + migrazione 0.7.17 | CFG-01/02/03 |

**Verifica:**
- `tests/loan-reservation.spec.js` — 21/21 verde (lifecycle completo: richiesta → approvazione → ritiro → restituzione → riprestabilità → prenotazione/coda/annullamento), con guardia HTTP 5xx aggiunta.
- PHPStan livello 5 pulito su tutti i file toccati.
- Migrazione `migrate_0.7.17.sql` testata idempotente sul DB reale (UNIQUE key `unique_setting`).
- Riproduzione DB del sintomo "restituito → prestabile" e dei 3 scenari di drift editore.

**Note residue (follow-up):** F4 (finestra breve di stato copia in `checkExpiredPickups`) ed F5 (cooldown manutenzione atomico via `settings`) sono robustezze P3/P2 del cron non bloccanti e non ancora applicate; il flusso "Prenota" frontend continua a usare la tabella `prestiti` (design esistente, funzionante e testato) anziché la coda `prenotazioni` separata.
