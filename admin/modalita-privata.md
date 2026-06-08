# Modalità Privata

La **Modalità Privata** è un interruttore di amministrazione che, quando attivo, **restringe l'intero sito pubblico ai soli utenti autenticati**. È **disattivata di default**: appena installato, Pinakes serve il catalogo a chiunque.

Quando la attivi, ogni pagina pubblica (home, catalogo, schede libro) richiede il login, mentre l'area amministrativa e l'esperienza degli utenti già autenticati restano invariate.

> **In sintesi**: con la Modalità Privata attiva, il sito diventa visibile solo a chi ha effettuato l'accesso. Senza login, l'utente viene reindirizzato alla pagina di accesso.

---

## Come si attiva

1. Vai in **Impostazioni → Avanzate**
2. Individua l'opzione **Modalità privata**
3. Attiva il toggle
4. Clicca **Salva impostazioni**

| Dettaglio | Valore |
|-----------|--------|
| **Percorso** | Amministrazione → Impostazioni → Avanzate |
| **Setting** | `private_mode` |
| **Categoria** | `advanced` |
| **Valore attivo** | `1` |
| **Default** | Disattivata (`0`) |

L'effetto è immediato dopo il salvataggio: non è necessario riavviare il server né svuotare la cache.

---

## Comportamento quando è ATTIVA

### Pagine pubbliche

Tutte le pagine pubbliche (home, catalogo, schede dei libri, pagine CMS, eventi) **reindirizzano gli utenti non autenticati alla pagina di login**. Dopo l'accesso, l'utente può navigare il sito normalmente.

### Richieste API

Una richiesta **API non autenticata** riceve una risposta **`401` in formato JSON**, invece di un redirect HTML. Questo permette ai client (app, script, integrazioni) di gestire l'errore correttamente.

### Amministratori e utenti autenticati

Un **amministratore** (o un qualsiasi utente che ha effettuato il login) **può navigare il sito pubblico senza alcuna restrizione**. La Modalità Privata non aggiunge limitazioni a chi è già autenticato.

### Contenuti caricati

| Tipo di upload | Esempio | Accessibile senza login? |
|----------------|---------|--------------------------|
| **Privati** | Documenti/allegati marcati come privati | ❌ No — non vengono serviti agli utenti non autenticati |
| **Pubblici** | Copertine dei libri, logo/branding | ✅ Sì — restano accessibili anche senza login |

> **Importante**: gli upload pubblici (copertine, logo, elementi di branding) **non vengono "login-wallati"**. Questo evita che immagini essenziali — come la copertina mostrata nella pagina di login o il logo dell'istituto — risultino spezzate per i visitatori non ancora autenticati.

### Rotte protette da API key

Le rotte sotto `/api/public/*` sono protette dal loro **ApiKeyMiddleware** dedicato. La Modalità Privata **non le pre-empta con un 401 di sessione**: continuano a rispondere secondo il proprio meccanismo di controllo.

| Situazione | Risposta della rotta `/api/public/*` |
|------------|--------------------------------------|
| Chiave API mancante | `401` (gestito dall'ApiKeyMiddleware) |
| API pubblica disabilitata | `403` (gestito dall'ApiKeyMiddleware) |

In altre parole, l'autenticazione tramite chiave API e quella tramite sessione restano due gate indipendenti: la Modalità Privata governa l'accesso basato su sessione, l'ApiKeyMiddleware governa l'accesso tramite chiave.

---

## Comportamento quando è DISATTIVA

Con la Modalità Privata disattivata (impostazione predefinita), il **sito torna pubblico per tutti**: chiunque può consultare home, catalogo e schede dei libri senza effettuare l'accesso. È la configurazione tipica di una biblioteca aperta al pubblico.

---

## Casi d'uso

| Scenario | Perché usare la Modalità Privata |
|----------|----------------------------------|
| **Biblioteca privata o aziendale** | Il catalogo deve essere consultabile solo dai dipendenti o dai membri dell'organizzazione |
| **Fase di allestimento del catalogo** | Durante l'inserimento iniziale dei libri, il sito resta nascosto al pubblico finché non è pronto |
| **Accesso riservato ai soci** | Solo gli iscritti/soci autenticati possono vedere il catalogo e i contenuti |

> **Suggerimento**: la Modalità Privata si combina bene con la registrazione utenti soggetta ad approvazione manuale (vedi **Impostazioni → Registrazione**), così controlli sia *chi* può accedere sia *quando* il sito diventa visibile.

---

## Domande Frequenti (FAQ)

### 1. La Modalità Privata blocca anche l'area di amministrazione?

No. L'area admin ha già le proprie protezioni di autenticazione. La Modalità Privata agisce sul **sito pubblico** (catalogo, schede, home), reindirizzando al login chi non è autenticato.

### 2. Le copertine dei libri spariscono quando attivo la Modalità Privata?

No. Le copertine e gli elementi di branding (logo) sono upload **pubblici** e restano accessibili anche ai visitatori non autenticati. Vengono serviti normalmente, ad esempio nella pagina di login.

### 3. Cosa ricevono le integrazioni esterne via API?

Dipende dal tipo di rotta:
- Una **richiesta API non autenticata** generica riceve `401` JSON.
- Le rotte **`/api/public/*`** continuano a essere gestite dal loro ApiKeyMiddleware: rispondono `401` se manca la chiave o `403` se l'API pubblica è disabilitata. La Modalità Privata non le scavalca.

### 4. Qual è la differenza tra Modalità Privata e Modalità Catalogo?

Sono indipendenti e rispondono a esigenze diverse:

| Modalità | Effetto |
|----------|---------|
| **Modalità Privata** | Nasconde *l'intero sito* ai non autenticati (richiede login per vedere qualsiasi cosa) |
| **Modalità Catalogo** | Il sito resta pubblico, ma disabilita prestiti e prenotazioni (solo consultazione) |

Puoi attivarle insieme: ad esempio un catalogo riservato ai soci e di sola consultazione.

### 5. Devo riavviare qualcosa dopo aver attivato la Modalità Privata?

No. La modifica ha effetto immediato dopo il salvataggio. Verifica il risultato aprendo il sito in una finestra anonima (Ctrl+Shift+N): dovresti essere reindirizzato alla pagina di login.
