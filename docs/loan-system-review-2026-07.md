# Revisione completa del sistema prestiti/prenotazioni — luglio 2026

Seconda revisione approfondita del sistema di prenotazione e prestito (disponibilità,
calendario, email, gestione per-copia, stati del libro), successiva alla revisione
documentata in `docs/loan-system-review.md` (21 finding, tutti implementati).
Questa passata copre: flussi utente (richiesta/prenotazione/calendario), flussi
admin (approvazione/ritiro/restituzione/rinnovo/copie), motore di occupazione
(`CapacityService`, `DataIntegrity`, trigger DB), coda prenotazioni e riassegnazioni
(`ReservationManager`, `ReservationReassignmentService`), sottosistema
email/notifiche/cron (`NotificationService`, `EmailService`, `MaintenanceService`,
`IcsGenerator`, `cron/*`) e le superfici API (web, endpoint legacy, plugin mobile-api).

**Metodo**: lettura diretta dei file core + tre passate parallele indipendenti per
sottosistema, con verifica incrociata sul sorgente di ogni finding ad alta severità
prima dell'inclusione. I finding già corretti dalla revisione precedente non sono
ripetuti.

## Sintesi esecutiva

L'architettura è solida e coerente: il modello di occupazione #157 (prestito attivo
o `pendente` con copia = copia occupata; prenotazione `attiva` = unità di capacità
soft) è applicato in modo uniforme da `CapacityService`, dal calendario, dai gate di
creazione/approvazione e dai trigger DB; i lock `libri`-first, i re-check post-lock e
le notifiche differite post-commit coprono le race principali. IDOR/CSRF a posto su
tutti gli endpoint verificati.

Restano però **5 problemi ad alta severità**: un endpoint admin che chiude qualunque
prestito senza guardia di stato (falsificando storici e flag di ritardo); l'assenza di
validazione date su `createReservation` (date passate, range invertiti e un DoS da
range illimitato); tre template email introdotti con GAP-1/2/3 **mai seminati** né
presenti nei default hardcoded (email di restituzione/scadenza/copia-indisponibile
silenziosamente mai inviate su gran parte delle installazioni); il cron che non
inizializza I18n (email in italiano o del tutto fallite su installazioni non-italiane);
e il JS di prenotazione che hardcoda i path API italiani mentre le route sono
registrate per locale attivo (installazioni senza it_IT: calendario e prenotazione
rotti).

---

## Finding — ALTA severità

### H1 — `LoanRepository::close()` chiude qualunque prestito senza guardia di stato
`app/Models/LoanRepository.php:164-188`, esposto da `POST /prestiti/close/{id}`
(`app/Routes/web.php:1675` → `PrestitiController::close`, staff).

A differenza di `processReturn` (che richiede `attivo=1 AND stato IN
('in_corso','in_ritardo')`), la SELECT non filtra lo stato e l'UPDATE è
`SET attivo=0, data_restituzione=?, stato='restituito', restituito_in_ritardo=?
WHERE id=?` senza `AND attivo=1`.

*Scenario*: POST su un prestito già restituito in orario il 2026-06-01 (scadenza
06-05) eseguito il 2026-07-01 → `data_restituzione` sovrascritta a oggi e
`restituito_in_ritardo` ricalcolato contro oggi → il prestito risulta falsamente in
ritardo (inquina statistiche e vincoli recensioni). Chiudere un `prenotato`/
`da_ritirare`/`pendente` lo registra come `restituito` (mai consegnato) invece di
`annullato`/`scaduto`, saltando il percorso di riassegnazione di `cancelPickup`.

*Fix*: replicare la guardia di `processReturn` (`attivo=1 AND stato IN
('in_corso','in_ritardo')` nella SELECT `FOR UPDATE` e `AND attivo=1` nell'UPDATE,
con `affected_rows` check), oppure rimuovere la route se ridondante rispetto a
`processReturn`.

### H2 — `createReservation`: nessuna validazione di date passate, range invertiti, durata illimitata (DoS)
`app/Controllers/ReservationsController.php:255-304` e `getDateRange`
(`:478-490`). Route: `POST /api/libro/{id}/reservation` (autenticata + CSRF).

A differenza di `UserActionsController::reserve()` (che ha il guard `past_date`),
qui mancano: `start_date >= oggi`, `end_date >= start_date`, cap sulla durata. I
trigger DB non aiutano: proteggono solo righe con `copia_id`, e la riga inserita è
un `pendente` nudo.

*Scenari*:
- `{start_date:"2020-01-01"}` → richiesta nel passato accettata; all'approvazione
  `data_prestito <= oggi` → `da_ritirare` con `data_scadenza` già superata →
  prestito istantaneamente in ritardo.
- `{start_date:"2026-07-01", end_date:"2026-06-01"}` → `getDateRange()` ritorna `[]`,
  zero controlli di conflitto, riga con `data_scadenza < data_prestito` (intervallo
  vuoto che nessun predicato di overlap intercetta).
- `{end_date:"9999-12-31"}` → array di ~2,9M date + scansione per-giorno di
  `calculateAvailability` → esaurimento memoria/timeout su ogni richiesta
  autenticata (la route non è rate-limited). Anche un range "solo" decennale che
  passa i controlli crea all'approvazione un blocco copia di 10 anni.

*Fix*: validare `start >= DateHelper::today()`, `end >= start`, e clampare la durata
a un massimo configurabile (es. `loans.max_loan_duration_days`, default 60-90).

### H3 — Template email `loan_returned`, `reservation_expired`, `copy_unavailable_user` irrisolvibili nella maggior parte delle configurazioni
`app/Support/EmailService.php` (catena di fallback `getEmailTemplate` :232-273,
default hardcoded :278+ — i tre nomi sono assenti), seed installer
`installer/database/data_*.sql` (18 template seminati — i tre assenti),
`app/Models/SettingsRepository.php:174-181` (INSERT senza colonna `locale` →
default `it_IT`).

I tre template introdotti dai fix GAP-1/2/3 esistono solo in
`SettingsMailTemplates.php` e raggiungono il DB **solo** quando un admin apre
`/admin/settings` (`ensureEmailTemplates`), sempre con `locale='it_IT'`.

*Scenari*: installazione it_IT fresca, nessun admin ha ancora aperto Impostazioni →
restituzione → `sendTemplate('loan_returned')` → miss DB → default hardcoded assente
→ eccezione "Template not found" catturata → email persa con una sola riga di log.
Su installazioni **en_US/de_DE/fr_FR** il lookup `(name,'en_US')` fallisce e il ramo
di fallback è saltato (`if ($locale !== 'it_IT' && $locale !== 'en_US')`): questi
tre tipi di email non sono **mai** consegnabili, nemmeno dopo il seeding it_IT.

*Fix*: aggiungere i tre template ai seed dei 4 locale + migrazione per gli
installati; aggiungerli ai default hardcoded di `EmailService::getDefaultTemplate()`;
estendere il fallback a it_IT come ultima spiaggia per ogni locale.

### H4 — Il cron non inizializza I18n: email cron in italiano o mai inviate su installazioni non-italiane
`cron/automatic-notifications.php`, `cron/full-maintenance.php` (nessuna chiamata a
`I18n::loadFromDatabase()`/bootstrap; verificato via grep), `app/Support/I18n.php`
(default statico `it_IT`), `public/index.php` (init solo web).

In CLI `I18n::getLocale()` resta `it_IT`. Su installazione en_US: i template con
default hardcoded partono in **italiano**; i template senza default hardcoded
(`loan_pickup_ready`, `loan_pickup_expired`, `loan_approved`, …) **falliscono del
tutto** quando l'evento scatta dal cron — es. `MaintenanceService::
activateScheduledLoans` → `sendPickupReadyNotification`: l'utente non viene mai
avvisato che il libro è pronto se l'attivazione avviene via cron (funziona invece se
avviene via manutenzione da login admin, dove I18n è inizializzato). Anche tutte le
stringhe `__()` interne alle email cron escono in italiano.

*Fix*: nei due entry point cron, bootstrappare I18n con il locale di installazione
letto dal DB prima di istanziare i servizi.

### H5 — Il JS di prenotazione hardcoda i path API italiani; le route esistono solo per i locale attivi
`app/Views/frontend/book-detail.php:2572,2755,2796` (fetch di
`/api/libro/{id}/availability` e POST `/api/libro/{id}/reservation`) contro la
registrazione per-locale in `app/Routes/web.php:2386-2410`
(`RouteTranslator::getRouteForLocale('api_book', $locale)`: it=`/api/libro`,
en=`/api/book`, de=`/api/buch`, fr=`/api/livre`), con `$supportedLocales` derivato
dalle lingue **attive**.

*Scenario*: installazione solo inglese/tedesca (it_IT disattivato da
Admin → Lingue) → la fetch disponibilità va in 404 silenzioso (il `catch` logga
soltanto: il calendario si disegna **senza alcuna data disabilitata**) e la POST di
prenotazione va in 404 → funzione di richiesta prestito completamente rotta, con un
calendario che per giunta mostra tutto come prenotabile.

*Fix*: esporre al JS il path localizzato (variabile iniettata dalla view via
`RouteTranslator`) oppure registrare i path API per tutti i locale supportati
indipendentemente dall'attivazione.

---

## Finding — MEDIA severità

### M1 — Restituzione con esito perso/danneggiato/manutenzione non riassegna i prestiti futuri sulla stessa copia
`app/Controllers/PrestitiController.php:785-825` (`processReturn`).
`reassignOnCopyLost()` esiste esattamente per questo caso ma è chiamato solo da
`CopyController::updateCopy` (`CopyController.php:245`); nel ramo di restituzione
con esito fuori-circolazione non viene invocato. *Scenario*: copia A con prestito
attivo L1 e prestito futuro schedulato L2 (`prenotato`, non sovrapposto — lecito);
L1 rientra come `perso` → copia A `perso`, L2 resta `attivo=1` agganciato a una
copia persa; `confirmPickup` fallirà (fail-closed) e nessun check di consistenza lo
segnala (`DataIntegrity` scansiona solo copie `prenotato`/`prestato`). Riparazione
solo manuale o al fortuito `reassignOnNewCopy` da un altro rientro.
*Fix*: nel ramo `$copia_stato !== 'disponibile'` chiamare
`reassignOnCopyLost((int)$copia_id)` (con `setExternalTransaction(true)` + flush
differito), come già fa `CopyController`.

### M2 — Inversione dell'ordine canonico dei lock nei percorsi di restituzione/ritiro
`PrestitiController::processReturn` (`:743`), `LoanApprovalController::returnLoan`
(`:985`), `confirmPickup` (`:713`), `cancelPickup` (`:852`),
`LoanApprovalController::cancelReservation` (`:1140`): tutti bloccano prima la riga
`prestiti`/`prenotazioni` e acquisiscono la riga `libri` solo dopo (dentro
`processBookAvailability` o l'UPDATE di `recalculateBookAvailability`). I percorsi
di creazione/approvazione/rinnovo e `LoanRepository::close` usano l'ordine canonico
opposto (P3: `libri` → `prestiti`). *Scenario*: approvazione e restituzione
concorrenti sullo stesso libro (overlap sulla stessa copia) → deadlock InnoDB → una
delle due transazioni abortita con errore generico 500. Nessuna corruzione, ma
fallimenti spurii sotto carico. *Fix*: nei percorsi di restituzione/ritiro,
determinare `libro_id` con lettura non bloccante e lockare `libri` prima del
prestito (pattern già scritto in `LoanRepository::close`).

### M3 — `sendOverdueLoanNotifications`: claim/revert senza guardia di stato può corrompere un prestito restituito
`app/Support/NotificationService.php:482` (claim `SET overdue_notification_sent=1,
stato='in_ritardo' WHERE id=? AND (flag IS NULL OR flag=0)`) e `:515` (revert
`SET flag=0, stato='in_corso' WHERE id=?`), senza `AND attivo=1 AND stato IN
('in_corso','in_ritardo')`. *Scenario*: il loop di invio (retry SMTP con sleep) può
durare minuti dopo la SELECT; un admin restituisce nel frattempo un prestito in
lista → il claim riporta la riga `restituito` a `stato='in_ritardo'` con `attivo=0`
(combinazione invalida) e l'utente riceve un sollecito per un libro già reso;
simmetricamente il revert forza `in_corso` su qualunque stato corrente. *Fix*:
aggiungere la guardia di stato a claim e revert (il pattern guarded-UPDATE è già
usato ovunque in `MaintenanceService`).

### M4 — Email `reservation_book_available` persa definitivamente se l'invio fallisce
`app/Controllers/ReservationManager.php:523-532`: `notifica_inviata` è impostato
solo a successo e il log promette "will retry on next run", ma la prenotazione è già
`completata` prima dell'invio e **nessun codice legge mai** `completata AND
notifica_inviata=0` (il flag è scritto qui e mai letto altrove). Stessa semantica
one-shot in `ReservationReassignmentService::notifyUserCopyAvailable`. *Scenario*:
hiccup SMTP al momento della promozione → l'utente non saprà mai che il libro è
pronto; il `pendente` derivato invecchia in silenzio. *Fix*: sweep di recupero in
`MaintenanceService` (o nel cron notifiche) sulle prenotazioni
`completata`+`notifica_inviata=0` recenti, riusando il pattern claim-then-send.

### M5 — Rinnovo: `warning_sent` non azzerato, durata hardcoded, conflitto stessa-copia mascherato
`app/Controllers/PrestitiController.php:1104-1177`. (a) L'UPDATE di rinnovo non
azzera `warning_sent`/`overdue_notification_sent`: un prestito rinnovato dopo il
promemoria non riceverà mai il promemoria per la nuova scadenza (prossima email:
direttamente il sollecito di ritardo). (b) Durata rinnovo hardcoded `+14 days`
(`:1034,1098`) ignorando `loans.loan_duration_days`. (c) Il check di capacità è a
livello libro e non verifica la **copia propria** del prestito: con ≥2 copie,
estendere L1 sulla copia A sopra un prestito schedulato L2 sulla stessa copia A passa
il gate e viene salvato solo dal trigger DB → catch-all → `error=renewal_failed`
generico invece di `extension_conflicts` (e senza trigger, doppio prestito sulla
stessa copia). *Fix*: azzerare i flag nell'UPDATE; leggere la durata da settings;
aggiungere un overlap-check sulla copia del prestito prima dell'UPDATE.

### M6 — Modifica prestito admin: `utente_id` sostituibile senza ricontrolli, date non validate, disponibilità non ricalcolata
`app/Controllers/PrestitiController.php:605-659` + `LoanRepository::update`
(`:86-96`). `utente_id` arriva da hidden field ed è riscritto senza dup-check,
senza `max_active_loans_per_user`, senza verifica esistenza/stato utente; nessuna
validazione `data_scadenza > data_prestito`; dopo lo spostamento date non viene
chiamato `recalculateBookAvailability` (spostare la partenza di un `prenotato`
attraverso "oggi" lascia stati copia/`copie_disponibili` stantii fino al prossimo
evento). *Fix*: rivalidare come in `store()`; ricalcolare la disponibilità dopo
l'UPDATE; validare il range.

### M7 — Nessun percorso verifica idoneità utente: tessera scaduta / stato `sospeso` mai controllati a prestito
Nessun entry point (web `createReservation`, `UserActionsController::loan/reserve`,
`PrestitiController::store`, `approveLoan`, mobile-api) consulta
`utenti.data_scadenza_tessera` né `utenti.stato`; `stato` è verificato solo al
login (`AuthController.php:106`). *Scenari*: utente con tessera scaduta ieri o
sospeso dall'admin a sessione aperta continua a richiedere prestiti; l'admin può
creare prestiti per utenti `sospeso` (la "cancellazione" utente marca `sospeso`).
Inoltre in `store()` il check di esistenza utente gira solo dentro
`if ($maxLoans > 0)`: con il default `max_active_loans_per_user=0` un `utente_id`
inesistente arriva all'INSERT → eccezione FK non mappata → 500. *Fix*: check di
idoneità (stato + tessera) centralizzato nei gate di creazione/approvazione;
spostare il check di esistenza fuori dal ramo `maxLoans`.

### M8 — Editor template email locale-blind: le modifiche admin sono ignorate su installazioni non-italiane
`app/Models/SettingsRepository.php:174-181`: l'INSERT/UPDATE non specifica
`locale` (default colonna `it_IT`) e le letture dell'editor ignorano il locale,
mentre l'invio legge `(name, locale_installazione)`. *Scenario*: installazione
en_US → admin modifica "loan_approved" → viene creata/aggiornata la riga
`(loan_approved, it_IT)` mentre l'invio continua a usare la riga seed `en_US`:
la modifica appare salvata nell'UI ma non ha alcun effetto sulle email. *Fix*:
propagare il locale di installazione in lettura/scrittura dell'editor.

### M9 — Regime timezone misto in tutto il sottosistema date
Quattro modi diversi di calcolare "oggi": `DateHelper::today()` (app-TZ, l'unico
documentato come canonico), `date('Y-m-d')` (TZ processo, spesso UTC — usato in
`LoanApprovalController:37,241,709,848,1008`, `UserActionsController:309,429,565`,
`NotificationService:736,789`, `ReservationManager:553`, mobile-api),
`gmdate()` (UTC esplicito — `LoanRepository::close:180`,
`RegistrationController:113`) e `CURDATE()`/`NOW()` SQL (TZ sessione DB — i cron
forzano `SET SESSION time_zone='+00:00'` mentre il web non imposta nulla:
`MaintenanceService:243,277` vs `:339,443`; `ReservationManager:647`).
*Scenari a cavallo della mezzanotte (00:00–02:00 Rome con PHP/DB in UTC)*:
`data_restituzione` registrata al giorno precedente; flag ritardo non impostato per
rientri al giorno dopo la scadenza; nello stesso `runAll()` le scadenze prenotazione
usano "oggi Rome" e i ritardi "oggi UTC" (un prestito scaduto ieri-Rome è expired
se `prenotato` ma non ancora `in_ritardo` se `in_corso`); pickup confermabile un
giorno oltre la deadline. *Fix*: sostituire ogni calcolo di "oggi" nel dominio
prestiti con `DateHelper::today()`; allineare la TZ di sessione DB all'app-TZ (o
convertire le comparazioni SQL su parametri PHP).

### M10 — `deleteCopy` su copia con storico prestiti → eccezione FK non gestita (HTTP 500)
`app/Controllers/CopyController.php:278-336`: il pre-check blocca solo copie tenute
da prestiti attivi/pendente-con-copia; i prestiti chiusi referenziano ancora
`copia_id` e il FK `fk_prestiti_copia ... ON DELETE RESTRICT` fa esplodere la DELETE
(mysqli strict) senza catch. *Fix*: pre-check sullo storico (`EXISTS prestiti WHERE
copia_id=?`) con messaggio dedicato, o catch dell'eccezione FK.

### M11 — Cancellazioni/scadenze prenotazione silenziose verso l'utente
(a) `ReservationManager::cancelExpiredReservations` (`:635-689`) annulla le
prenotazioni-coda scadute senza alcuna email/notifica (il template
`reservation_expired` — la cui descrizione cita esattamente questo evento — è
collegato solo alla scadenza dei prestiti `prenotato`). (b) Nessuna email
all'utente quando una prenotazione è annullata dall'admin
(`LoanApprovalController::cancelReservation`). *Fix*: inviare
`reservation_expired` dal percorso coda e una notifica di annullamento dal percorso
admin.

### M12 — Pagina archivio autore: formula di disponibilità non canonica
`app/Controllers/FrontendController.php:2000-2010`: `copie_totali - COUNT(prestiti
in stati attivi)` senza overlap di date (un prestito schedulato tra mesi conta come
occupante oggi), senza `attivo=1`, senza ramo `pendente`+copia, senza esclusione
copie non prestabili, ignorando il campo `libri.copie_disponibili` usato da
catalogo/scheda. *Scenario*: stessa copia mostrata "non disponibile" in pagina
autore e "disponibile" in catalogo (o viceversa con copia in manutenzione).
Solo display, ma incoerenza visibile. *Fix*: usare `l.copie_disponibili` come le
altre pagine.

### M13 — L'utente web non può annullare le proprie richieste/prestiti schedulati
`UserActionsController::cancelLoan` (`:116-219`) è implementato correttamente
(lock, rilascio copia, riassegnazione, promozione coda) ma nessuna route web lo
espone e nessuna view lo invoca; è raggiungibile **solo** dal plugin mobile-api
(`ActionsController::cancelReservation` → kind 'loan'). *Scenario*: utente web
sbaglia le date della richiesta → unico rimedio è il rifiuto admin; una volta
approvata, la copia resta impegnata. *Fix*: esporre route + controllo UI in "I miei
prestiti" per stati `pendente`/`prenotato`.

---

## Finding — BASSA severità

- **L1 — `pickup_deadline` non limitata a `data_scadenza`**
  (`MaintenanceService:263-277`, `LoanApprovalController:322-330`): un prestito
  finestra 1–2 luglio attivato l'1 luglio ha deadline 4 luglio; il 3–4 luglio
  `confirmPickup` (che controlla solo la deadline) può avviare un `in_corso` già
  scaduto; nel frattempo la copia resta bloccata oltre la finestra. Fix:
  `pickup_deadline = MIN(oggi+N, data_scadenza)` e/o check `data_scadenza >= oggi`
  in `confirmPickup`.
- **L2 — Promozione coda incompleta in due punti**: `processReturn`
  (`PrestitiController:822-824`) promuove una sola prenotazione invece del loop
  "finché converte" usato da tutti gli altri release-path;
  `MaintenanceService::processScheduledReservations` (`:363-400`) converte al
  massimo una prenotazione per libro per run (3 copie libere + 3 prenotazioni
  eleggibili = servite in 3 giorni). Fix: allineare al pattern loop.
- **L3 — Cosmetici email**: variabili HTML-escaped anche nel Subject
  (`EmailService:170,498` → `L&#039;isola…` nell'oggetto); subject di
  `loan_overdue_admin` con placeholder a graffa singola `{prestito_id}` mai
  sostituito (`SettingsMailTemplates:148` + seed); il default hardcoded
  `wishlist_book_available` afferma che il libro "è stato automaticamente rimosso
  dalla tua wishlist" (falso — resta, viene solo marcato `notified`); stringhe
  in-app non tradotte in `sendLoanExpirationWarnings` (`NotificationService:426`).
- **L4 — ICS**: nessun line-folding RFC 5545 a 75 ottetti su SUMMARY/DESCRIPTION
  (parser rigorosi rifiutano titoli lunghi); `LAST-MODIFIED` interpretato nella TZ
  del processo (drift di offset). Il resto del generatore è corretto (DTEND
  esclusivo +1 giorno per all-day, escaping, UID univoci, METHOD:PUBLISH).
- **L5 — `store()` admin non tagga `origine='diretto'`**
  (`PrestitiController:449-451`): i prestiti diretti risultano `richiesta`
  (l'enum `diretto` non è mai scritto da nessuno). Inoltre il match del messaggio
  trigger a `:576` è stantio (il testo reale è "…e sovrapposto per questa copia.")
  → un SIGNAL del trigger in `store()` diventa 500 invece del redirect dedicato.
- **L6 — Endpoint legacy `/api/libri/{id}/disponibilita` ancora pubblico**
  (`web.php:2074`, AVAIL-003 applicato solo nella logica): la matematica ora delega
  al calcolo canonico, ma l'endpoint resta senza `AuthMiddleware` ed espone
  `occupied_ranges` (date dei prestiti) a client anonimi, mentre il gemello
  `/api/books/{id}/availability` è autenticato.
- **L7 — Integrità coda in casi limite**: il reorder in
  `UserActionsController::cancelReservation` (`:261-279`) è senza `FOR UPDATE` né
  lock libro (il gemello admin li ha) → gap/posizioni stantie con cancel+reserve
  concorrenti; `ReservationManager::processBookAvailability:232` passa
  `(int)$nextReservation['queue_position']` a `updateQueuePositions` — con
  `queue_position NULL` legacy diventa 0 e decrementa tutte le posizioni attive.
- **L8 — Residuo F5 della revisione precedente**: il cooldown di
  `MaintenanceService::runIfNeeded` è ancora basato su `$_SESSION`
  (`MaintenanceService:46-59`) — due admin in sessioni diverse eseguono entrambi
  `runAll()`. (F4 risulta invece indirizzato dai guard su stato copia.)
- **L9 — `prestiti.sanzione` mai valorizzata**: scritta solo come `0.00`
  all'inserimento; nessun percorso applica sanzioni per ritardo/perso/danneggiato.
  Se le sanzioni sono previste dal prodotto, il campo è oggi decorativo.

---

## Mappa eventi → notifiche (gap di ciclo di vita)

| Evento | Email utente | Stato |
|---|---|---|
| Richiesta inviata | — | **GAP** (solo notifica admin) |
| Richiesta approvata (futura/immediata) | `loan_approved` / `loan_pickup_ready` | OK (ma H4 via cron) |
| Richiesta rifiutata | `loan_rejected` | OK |
| Ritiro confermato (prestito parte) | — | **GAP** |
| Promemoria ritiro pre-deadline | — | **GAP** |
| Ritiro scaduto / annullato | `loan_pickup_expired` / `loan_pickup_cancelled` | OK (H4) |
| Promemoria scadenza | `loan_expiring_warning` | OK ma non ripetuto dopo rinnovo (M5) |
| Ritardo | `loan_overdue_notification` (+admin) | OK una tantum (M3, L3) |
| Restituzione | `loan_returned` | **ROTTO (H3)** |
| Prenotazione convertita (libro pronto) | `reservation_book_available` | invio one-shot, perdita definitiva su errore (M4) |
| Copia prenotata divenuta indisponibile | `copy_unavailable_user` | **ROTTO (H3)** |
| Prenotazione-prestito scaduta | `reservation_expired` | **ROTTO (H3)** |
| Prenotazione-coda scaduta | — | **GAP (M11)** |
| Prenotazione annullata (admin/utente) | — | **GAP (M11)** |
| Wishlist: libro di nuovo disponibile | `wishlist_book_available` | OK |

---

## Verificato e risultato corretto

- **Modello di occupazione #157** coerente in tutti i punti di applicazione:
  `CapacityService` (sweep-line per-giorno con coalesce canonico R_END, half-open
  corretto sugli adiacenti), calendario (`calculateAvailability`), gate di
  creazione/approvazione/rinnovo, `DataIntegrity::recalculateBookAvailability`
  (copie non prestabili escluse da `copie_totali`, `pendente`+copia contato una
  sola volta post-BUG9), trigger DB INSERT/UPDATE (copy-book coupling + overlap).
- **Concorrenza**: lock `libri`-first + `FOR UPDATE` su utente per il cap prestiti,
  re-check post-lock, dup-check richiesta/prenotazione su entrambi i lati,
  claim-then-send con revert per warning/overdue/wishlist, flock distinti nei due
  cron, notifiche differite flushate solo post-commit con eccezioni rilanciate in
  transazione esterna.
- **Sicurezza**: tutti gli endpoint admin dietro `AdminAuthMiddleware`+CSRF; tutte
  le mutazioni utente scoping su `utente_id` (niente IDOR, verificato anche sul
  plugin mobile-api che delega ai controller core); variabili template email
  passate da `htmlspecialchars`; il calendario client è più restrittivo del server
  (mismatch solo in direzione innocua).
- **Restituzioni**: flag `restituito_in_ritardo` corretto su entrambi i percorsi
  principali; riassegnazione Layer-1 + promozione Layer-2 dentro la transazione su
  ogni release-path; wishlist notify post-commit solo con disponibilità reale.

## Priorità di intervento suggerite

1. **Subito**: H1 (guardia di stato su `close`), H2 (validazione date), H3+H4
   (seed template + bootstrap I18n nel cron — insieme ripristinano l'intero
   comparto email), H5 (path API localizzati nel JS).
2. **Seconda passata**: M1, M3, M4, M5 (integrità stato/notifiche), M7 (idoneità
   utente), M8 (editor template), M9 (unificare "oggi" su `DateHelper::today()`).
3. **Pulizia**: M2/M6/M10–M13 e i LOW, molti dei quali one-liner.

---

## Stato implementazione (2026-07-02, branch `fix/loan-system-review-2026-07`)

Tutti i finding H1–H5, M1–M13 e L1–L8 sono stati implementati e verificati
(workflow multi-agente: 8 pacchetti su file disgiunti + traduzioni + 3 revisori
avversariali sul diff + 2 round di fix; `php -l` pulito su tutti i file, JSON
locale validati). Commit:

| Commit | Contenuto |
|---|---|
| `edb10aa` | Implementazione completa dei 27 fix (32 file, +1646/−256) |
| `32d308b` | Correzioni dai revisori: escaping SQL della migrazione conforme al runner `Updater::splitSqlStatements`, bump `version.json` → 0.7.26, mapping `not_eligible` nel mobile-api, lock-order P3 anche in `UserActionsController::cancelLoan`, escaping del subject al sink HTML (`wrapInBaseTemplate`), claim-then-send nel retry M4, pulsante annullamento anche lato admin |
| (successivo) | Guard `is_scalar` sul banner esiti della view prenotazioni (ultimo finding low della verifica di chiusura) + questa sezione |

Elementi introdotti:
- `app/Support/LoanEligibility.php` — punto unico di idoneità utente
  (stato `sospeso`/`scaduto`, tessera scaduta) usato da richiesta, prenotazione,
  creazione admin e approvazione (M7).
- `installer/database/migrations/migrate_0.7.26.sql` — seed idempotente dei
  template `loan_returned`, `reservation_expired`, `copy_unavailable_user` e
  del nuovo `reservation_cancelled` per i 4 locale, setting
  `loans.max_loan_duration_days` (default 90), fix subject `loan_overdue_admin`.
- Nuove notifiche: annullamento prenotazione admin (`reservation_cancelled`),
  scadenza prenotazioni-coda (`reservation_expired` sul percorso coda), retry
  post-fallimento di `reservation_book_available`
  (`ReservationManager::retryUnsentReservationNotifications`, chiamato da
  maintenance e cron).
- Route web + UI per l'annullamento delle proprie richieste (`pendente`) e dei
  prestiti schedulati (`prenotato`) da parte dell'utente (M13).
- `DateHelper::now()` e unificazione di ogni "oggi/adesso" del dominio prestiti
  sul timezone applicativo, cron inclusi (M9); rimosso il `SET SESSION
  time_zone='+00:00'` dai cron.

**Escluso di proposito** — L9 (sanzioni mai applicate): decidere la policy
tariffaria è una scelta di prodotto, non un difetto; il campo `sanzione` resta
scritto a 0.00. I gap di notifica "richiesta ricevuta", "ritiro confermato" e
"promemoria pre-deadline ritiro" (tabella sopra) restano aperti come possibili
migliorie di prodotto, non regressioni.

**Nota di release**: `version.json` è stato portato a 0.7.26 affinché l'updater
esegua la nuova migrazione; se la numerazione di release è gestita altrove,
allineare il numero prima del tag.
