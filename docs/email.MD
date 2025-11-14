# 📧 Guida Completa: Email e Notifiche

## 🎯 Introduzione

Il sistema invia **automaticamente email** per tenere aggiornati sia gli utenti che gli amministratori. Questa guida spiega in dettaglio come **configurare il sistema di invio** e come **personalizzare il contenuto** di ogni email.

---

## ⚙️ Come Funziona il Sistema di Notifiche

Il processo è semplice e automatico, e segue 3 passi:

1.  **Evento Scatenante**: Un'azione nel sistema (es. un utente richiede un libro).
2.  **Scelta del Template**: Il sistema seleziona il template email corretto (es. "Nuova Richiesta di Prestito per Admin").
3.  **Invio Tramite Server**: Il sistema popola il template con i dati reali (nome utente, titolo libro) e lo invia usando il server di posta che hai configurato.

---

## 📋 Tipi di Email Automatiche

Il sistema gestisce diverse notifiche per automatizzare la comunicazione.

| # | Email | Chi la riceve | Quando viene inviata |
|---|---|---|---|
| 1️⃣ | **Registrazione Utente** | Nuovo utente | Subito dopo la registrazione. |
| 2️⃣ | **Approvazione Account** | Utente approvato | Quando un admin approva il suo account. |
| 3️⃣ | **Avviso Scadenza Prestito** | Utente con prestito | 3 giorni prima della data di scadenza. |
| 4️⃣ | **Prestito Scaduto** | Utente con ritardo | Il giorno dopo la data di scadenza. |
| 5️⃣ | **Libro Desiderato Disponibile**| Utente in wishlist | Quando un libro che desiderava torna disponibile. |
| 6️⃣ | **Prenotazione Pronta** | Utente che ha prenotato | Quando il libro prenotato è pronto per il ritiro. |
| 7️⃣ | **Admin: Nuova Registrazione** | Amministratore | Quando un nuovo utente completa la registrazione. |
| 8️⃣ | **Admin: Richiesta Prestito** | Amministratore | Quando un utente richiede un nuovo prestito. |

---

## 🔧 Configurazione del Server di Invio (Tab "Email")

Questa è la sezione **più importante** per far funzionare le email. La trovi in `Dashboard → Impostazioni → Tab "Email"`.

### Driver di Invio: Scegli il "Postino"
Il "Driver" è il metodo che il sistema usa per spedire le email.

-   **SMTP (⭐️ Consigliato)**: Il metodo più affidabile. Usa un account email esistente (come Gmail o Outlook) per inviare. È la scelta migliore per garantire che le email non finiscano in spam.
-   **PHP mail()**: Un metodo di base che usa la funzione di posta del server. È semplice ma **meno affidabile** e spesso le email vengono contrassegnate come spam. Usalo solo per test o se non hai alternative.

### Campi di Configurazione SMTP

Se scegli SMTP, devi compilare i seguenti campi.

| Campo | Descrizione | Esempio |
|---|---|---|
| **Nome Mittente** | Il nome che appare come mittente. | `Biblioteca ITIS Marconi` |
| **Email Mittente** | L'indirizzo email da cui partiranno le email. | `biblioteca.marconi@gmail.com` |
| **Host SMTP** | L'indirizzo del server di posta. | `smtp.gmail.com` |
| **Porta SMTP** | La porta di comunicazione (di solito `587` o `465`). | `587` |
| **Crittografia** | Il protocollo di sicurezza. | `TLS` (più comune) o `SSL` |
| **Username SMTP** | Il tuo indirizzo email completo. | `biblioteca.marconi@gmail.com` |
| **Password SMTP** | La password del tuo account email. | `una-password-speciale` |

### ⚙️ Dati per i Provider più Comuni

#### **Gmail (Metodo Consigliato)**
- **Host**: `smtp.gmail.com`
- **Porta**: `587`
- **Crittografia**: `TLS`
- **Username**: Il tuo indirizzo Gmail (`tuo-nome@gmail.com`)
- **Password**: **NON la tua password normale!** Devi creare una "Password per le app".

**Come creare una Password per le app su Gmail:**
1.  Vai alla pagina di sicurezza del tuo account Google: [myaccount.google.com/security](https://myaccount.google.com/security).
2.  Assicurati che la **"Verifica in 2 passaggi"** sia attiva. Se non lo è, attivala.
3.  Cerca la sezione **"Password per le app"** e cliccaci sopra.
4.  Genera una nuova password:
    -   Seleziona app: "Posta"
    -   Seleziona dispositivo: "Computer Windows" (o un altro a scelta)
5.  Google ti mostrerà una **password di 16 caratteri**. Copia questa password e incollala nel campo "Password SMTP" nelle impostazioni della biblioteca.

#### **Outlook / Hotmail**
- **Host**: `smtp-mail.outlook.com`
- **Porta**: `587`
- **Crittografia**: `TLS`
- **Username**: Il tuo indirizzo Outlook/Hotmail.
- **Password**: La password del tuo account.

#### **Aruba PEC**
- **Host**: `smtps.pec.aruba.it`
- **Porta**: `465`
- **Crittografia**: `SSL`
- **Username**: Il tuo indirizzo PEC.
- **Password**: La password del tuo account PEC.

### ✅ Testare la Configurazione
Dopo aver compilato e salvato i campi, clicca sul bottone **"Test Connessione"**.
-   **Se ricevi un'email di prova**: ✅ La configurazione è corretta!
-   **Se vedi un errore**: ❌ Controlla attentamente tutti i dati inseriti (host, porta, username e soprattutto la password).

---

## 🎨 Personalizzare i Messaggi (Tab "Template")

Qui puoi modificare il testo di ogni email automatica. Vai su `Dashboard → Impostazioni → Tab "Template"`.

### L'Editor Visuale
Per ogni template, hai a disposizione un editor simile a Word che ti permette di:
-   Modificare il testo e la formattazione (grassetto, corsivo).
-   Cambiare l'**Oggetto** dell'email.
-   Inserire link e immagini.

### 🔑 Segnaposto (Placeholders)
I segnaposti sono "variabili" che il sistema sostituisce automaticamente con i dati corretti. Sono racchiusi tra doppie parentesi graffe, es. `{{user_name}}`.

**Esempio Pratico**:
-   **Testo nel template**: `Ciao {{user_name}}, il libro "{{book_title}}" scade il {{due_date}}.`
-   **Email ricevuta dall'utente**: `Ciao Mario Rossi, il libro "Il Signore degli Anelli" scade il 25/10/2025.`

### Elenco dei Segnaposto Disponibili

| Segnaposto | Descrizione | Esempio |
|---|---|---|
| `{{app_name}}` | Nome della biblioteca | `Biblioteca Comunale` |
| `{{app_email}}` | Email pubblica della biblioteca | `info@biblioteca.it` |
| `{{app_phone}}` | Telefono della biblioteca | `06-12345678` |
| `{{library_address}}`| Indirizzo della biblioteca | `Via Roma 1, Milano` |
| `{{user_name}}` | Nome completo dell'utente | `Mario Rossi` |
| `{{user_email}}` | Email dell'utente | `mario.rossi@email.it` |
| `{{book_title}}` | Titolo del libro | `Il Nome della Rosa` |
| `{{author_name}}` | Autore principale del libro | `Umberto Eco` |
| `{{loan_start_date}}`| Data di inizio del prestito | `10/10/2025` |
| `{{due_date}}` | Data di scadenza del prestito | `25/10/2025` |
| `{{loan_details_url}}`| Link alla pagina dei prestiti dell'utente | `http://.../prestiti` |
| `{{admin_approval_url}}`| Link per l'approvazione (per admin) | `http://.../admin/utenti` |

### Come Modificare un Template
1.  Scegli l'email che vuoi modificare dalla lista.
2.  **Modifica l'Oggetto**: Rendilo più personale o informativo.
3.  **Modifica il Corpo**: Cambia il testo usando l'editor. Assicurati di non cancellare i segnaposto che ti servono!
4.  **Salva** cliccando "Salva Template". Le modifiche saranno attive da subito.

---

## 🔍 Troubleshooting: Risoluzione dei Problemi

### "Le email non arrivano!"
1.  **Testa la connessione**: Vai su `Impostazioni → Email` e clicca "Test Connessione".
2.  **Controlla le credenziali**: La causa più comune è una password errata. Se usi Gmail, assicurati di aver creato e usato una "Password per le app".
3.  **Verifica Host e Porta**: Controlla che corrispondano a quelli del tuo provider.
4.  **Controlla la cartella Spam**: A volte le email finiscono lì, soprattutto se usi il driver `PHP mail()`.

### "Errore: Authentication Failed"
Questo errore significa che il server di posta ha rifiutato le tue credenziali.
- **Soluzione 1**: La password è sbagliata. Ricontrollala o rigenerala.
- **Soluzione 2 (Gmail)**: Stai usando la password normale invece di una "Password per le app". Segui la guida per crearne una.

### "Errore: Connection Timed Out"
Il sistema non riesce a contattare il server di posta.
- **Soluzione 1**: L'Host SMTP è sbagliato.
- **Soluzione 2**: La Porta SMTP è bloccata dal tuo hosting. Contatta il supporto del tuo provider di hosting.

### "Le modifiche ai template non si vedono!"
- **Soluzione 1**: Assicurati di aver cliccato "Salva Template" dopo aver modificato.
- **Soluzione 2**: Svuota la cache del sistema andando su `Impostazioni → Avanzate → Svuota Cache`.

---
*Ultimo aggiornamento: 14 Novembre 2025*
*Versione guida: 4.0.0 - Dettagliata*
