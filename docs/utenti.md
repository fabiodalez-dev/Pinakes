# Guida alla Gestione Utenti

## Introduzione

Questa guida spiega come funziona la gestione degli utenti in Pinakes, sia dal punto di vista dell'**utente che si registra**, sia da quello dell'**amministratore che gestisce gli account**.

---

## ‍‍ Tipi di Utenti (Ruoli)

Nel sistema esistono **quattro** ruoli (`tipo_utente`):

| **Ruolo** (`tipo_utente`) | **Cosa può fare** | **Chi è di solito** |
|-----------|-------------------|---------------------|
| **Standard** (`standard`) | - Cercare libri<br>- Richiedere prestiti<br>- Gestire il proprio profilo<br>- Salvare preferiti (wishlist) | Studenti, cittadini, membri della biblioteca |
| **Premium** (`premium`) | - Come Standard (variante di tessera/livello utente) | Membri con privilegi estesi |
| **Staff** (`staff`) | - Gestione utenti, libri, prestiti e collocazione<br>- Può modificare **solo se stesso** tra gli account staff/admin (protezione IDOR)<br>- **Non** può eliminare utenti | Operatori di front-office |
| **Amministratore** (`admin`) | - **Tutto** quello che fa lo staff<br>- Approvare/attivare nuovi utenti<br>- Modificare ruolo di chiunque<br>- Eliminare utenti<br>- Modificare le impostazioni | Bibliotecari, responsabili della biblioteca |

> Gli account `admin`/`staff` ricevono un **codice tessera** speciale (`ADMIN-XXXXXX`) generato automaticamente e senza scadenza; gli utenti `standard`/`premium` ricevono una tessera `T…` con eventuale data di scadenza. Quando un admin/staff viene declassato a standard/premium, il sistema assegna automaticamente una scadenza tessera a +1 anno.

---

## Processo di Registrazione per un Nuovo Utente

Un nuovo utente deve seguire 4 semplici passi per ottenere un account attivo.

### Passo 1: Compilazione del Modulo di Registrazione
1.  **Vai alla pagina di registrazione** del sito.
2.  **Compila i campi richiesti**:
    -   Nome e Cognome
    -   Indirizzo Email (deve essere valido!)
    -   Password (scegline una sicura)
3.  **Accetta i termini di servizio**.
4.  **Clicca su "Crea Account"**.

### Passo 2: Verifica dell'Email
1.  Dopo la registrazione, il sistema invia un'**email automatica** all'indirizzo fornito.
2.  **Apri l'email** e **clicca sul link di verifica**.
3.  Questo conferma che l'indirizzo email è reale e appartiene a te.

> **Importante**: Se non ricevi l'email entro 5 minuti, controlla la cartella **Spam** o Posta Indesiderata.

### Passo 3: Attesa dell'Approvazione dell'Amministratore
1.  Una volta verificata l'email, il tuo account resta nello stato **`sospeso`** ("In attesa di approvazione").
2.  **Non puoi ancora accedere** al sistema.
3.  Un **amministratore** deve rivedere la tua richiesta e approvarla manualmente. Questo garantisce che solo persone autorizzate accedano alla biblioteca.

> **Nota tecnica**: lo stato di "attesa approvazione" coincide con lo stato account `sospeso`. La lista utenti dell'admin elenca proprio gli account `sospeso` come "in attesa". La richiesta di approvazione admin è governata dal setting `registration.require_admin_approval` (configurabile nel tab Email/Registrazione delle Impostazioni).

### Passo 4: Account Approvato e Accesso
1.  Quando l'amministratore approva il tuo account, riceverai un'**email di benvenuto**.
2.  Questa email conterrà:
    -   La conferma che il tuo account è attivo.
    -   Il tuo **codice tessera** univoco (se previsto dal sistema).
3.  Ora puoi **accedere al sistema** con la tua email e password.

---

## ‍ Gestione Utenti per l'Amministratore

### Dove Trovare la Gestione Utenti
1.  **Accedi** come Amministratore (o Staff).
2.  Vai su **Dashboard → Utenti** (rotta `/admin/utenti`).

Vedrai una tabella con l'elenco di tutti gli utenti registrati. Le rotte admin sono percorsi fissi (decisione #145/#161):

| Azione | Metodo | Rotta |
|---|---|---|
| Lista utenti | `GET` | `/admin/utenti` |
| Form creazione | `GET` | `/admin/utenti/crea` |
| Salva nuovo utente | `POST` | `/admin/utenti/store` |
| Form modifica | `GET` | `/admin/utenti/modifica/{id}` |
| Salva modifica | `POST` | `/admin/utenti/update/{id}` |
| Dettagli utente | `GET` | `/admin/utenti/dettagli/{id}` |
| Elimina utente | `POST` | `/admin/utenti/delete/{id}` |
| Approva + email attivazione | `POST` | `/admin/utenti/{id}/approve-and-send-activation` |
| Attiva direttamente | `POST` | `/admin/utenti/{id}/activate-directly` |
| Export CSV | `GET` | `/admin/utenti/export/csv` |

> Tutte le rotte sono protette da `AdminAuthMiddleware`; quelle in scrittura aggiungono `CsrfMiddleware`; le rotte di lettura hanno rate limit (es. 30 richieste/minuto, 15/min per l'export CSV).

### Azioni Principali dell'Amministratore

#### 1. Approvare Nuovi Utenti

-   Nella lista utenti, vedrai i nuovi account con lo stato **`sospeso`** ("In attesa di approvazione").
-   Sono disponibili **due modalità** di approvazione:
    -   **Approva e invia email di attivazione** (`approve-and-send-activation`): imposta lo stato a `attivo`, genera un token di verifica email valido **7 giorni** e invia all'utente un'email con link di attivazione. L'utente completa la verifica cliccando il link.
    -   **Attiva direttamente** (`activate-directly`): imposta lo stato a `attivo`, marca `email_verificata = 1`, rimuove i token e invia l'email di benvenuto. L'utente può accedere subito senza ulteriori passaggi.
-   In entrambi i casi l'account deve trovarsi in stato `sospeso`; su account già attivi le azioni restituiscono un errore (`not_suspended`).

#### 2. Visualizzare i Dettagli di un Utente
-   Clicca sul nome di un utente per vedere la sua **scheda completa**:
    -   Dati anagrafici.
    -   Storico dei prestiti.
    -   Prestiti attualmente in corso.
    -   Eventuali ritardi.

#### 3. Modificare un Utente
-   Dalla lista o dalla scheda utente, clicca sull'icona  **"Modifica"**.
-   Puoi cambiare:
    -   Nome e cognome.
    -   Email (con cautela, l'utente dovrà usare la nuova email per accedere).
    -   Ruolo (es. da Utente Standard ad Amministratore).
    -   Stato dell'account.

#### 4. Gestire lo Stato di un Account
Puoi cambiare lo stato di un account in qualsiasi momento:

Lo stato account (`stato`) ammette **solo tre valori**:

| **Stato** (`stato`) | **Significato** | **Quando usarlo** |
|-----------|-----------------|-------------------|
| **Attivo** (`attivo`) | L'utente può accedere e richiedere prestiti. Impostando `attivo`, `email_verificata` viene forzato a 1. | Stato normale / account approvato. |
| **Sospeso** (`sospeso`) | L'utente non può accedere. È anche lo stato dei nuovi iscritti **in attesa di approvazione**. | Nuovi iscritti, violazioni, ritardi ripetuti. |
| **Scaduto** (`scaduto`) | Tessera/account scaduto. | Quando la tessera è scaduta. |

> Non esistono gli stati "bloccato" o "in attesa" come valori separati: l'attesa di approvazione è rappresentata da `sospeso`.

#### 5. Eliminare un Utente
-   Clicca sull'icona  **"Elimina"** (richiede conferma).
-   **Solo gli Amministratori** possono eliminare utenti: lo Staff riceve `403`.

Comportamento effettivo dell'eliminazione:
-   Se l'utente ha **qualsiasi storico prestiti** (anche restituiti), **non** viene eliminato dal database. Viene invece marcato come `sospeso` e nelle note (`note_utente`) viene aggiunto un marcatore `[ELIMINATO IL …]`. Questo preserva l'integrità dello storico prestiti.
-   Solo se l'utente **non ha alcun prestito** associato viene eseguita la `DELETE` definitiva del record.

> Le azioni di creazione, modifica ruolo ed eliminazione vengono registrate nell'audit log (`SecureLogger::info`). Se l'utente corrente cambia il proprio ruolo, la sessione e il token CSRF vengono rigenerati per prevenire session fixation.

---

## Domande Frequenti

**D: Perché la mia registrazione deve essere approvata?**
R: Per motivi di sicurezza e per garantire che solo i membri della comunità (studenti, cittadini, ecc.) possano utilizzare le risorse della biblioteca.

**D: Quanto tempo ci vuole per l'approvazione?**
R: Di solito da poche ore a 1-2 giorni lavorativi, a seconda della disponibilità dell'amministratore.

**D: Cosa faccio se ho dimenticato la password?**
R: Nella pagina di login, clicca su "Password dimenticata?". Inserisci la tua email e riceverai un link per reimpostarla.

**D: Posso cambiare la mia email o la mia password?**
R: Sì. Una volta effettuato l'accesso, vai sul tuo **Profilo** per cambiare la password. Per cambiare l'email, potrebbe essere necessario contattare un amministratore.

**D: Cosa succede se un utente viene eliminato?**
R: Se l'utente ha uno storico prestiti (anche solo prestiti già restituiti), **non** viene cancellato: viene marcato come `sospeso` con una nota `[ELIMINATO IL …]`, così lo storico resta intatto. Solo gli utenti senza alcun prestito vengono rimossi definitivamente dal database. Inoltre, solo gli Amministratori possono eseguire l'eliminazione (lo Staff no).

---
*Ultimo aggiornamento: 4 Giugno 2026*
*Versione guida: 1.1.0*
