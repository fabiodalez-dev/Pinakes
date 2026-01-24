# Sicurezza

Pinakes implementa diverse misure di sicurezza per proteggere l'applicazione e i dati degli utenti.

## Protezione CSRF

Il sistema protegge automaticamente tutte le operazioni che modificano dati (POST, PUT, DELETE, PATCH) tramite token CSRF.

### Come Funziona

1. Ogni sessione riceve un token univoco generato con `random_bytes(32)`
2. Il token ha una validità di **2 ore** (con variazione casuale ±10 minuti)
3. Il token deve essere incluso in ogni richiesta che modifica dati
4. La validazione usa `hash_equals` per prevenire attacchi timing

### Inclusione nei Form

Ogni form deve includere il campo hidden con il token:

```html
<input type="hidden" name="csrf_token" value="<?= Csrf::ensureToken() ?>">
```

### Inclusione nelle Richieste AJAX

Per le chiamate JavaScript, includere l'header `X-CSRF-Token`:

```javascript
fetch('/api/endpoint', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
    },
    body: JSON.stringify(data)
});
```

### Gestione Errori CSRF

Quando il token non è valido:

| Tipo Richiesta | Risposta |
|----------------|----------|
| **AJAX** | JSON con codice errore `CSRF_INVALID` o `SESSION_EXPIRED` |
| **Form tradizionale** | Pagina HTML "Sessione scaduta" con link al login |

### Rigenerazione Token

Il token viene rigenerato automaticamente:
- Dopo ogni login
- Dopo ogni logout
- Quando scade (dopo ~2 ore)

## Cookie Consent (GDPR)

Pinakes include un banner per la gestione del consenso ai cookie conforme al GDPR.

### Categorie di Cookie

| Categoria | Tipo | Descrizione |
|-----------|------|-------------|
| **Essenziali** | Obbligatori | Sessione, CSRF, preferenze. Non richiedono consenso. |
| **Analytics** | Opzionali | Statistiche anonime di utilizzo |
| **Marketing** | Opzionali | Tracciamento per pubblicità mirata |

### Configurazione Banner

Vai in **Impostazioni → Privacy** per configurare:

| Impostazione | Descrizione |
|--------------|-------------|
| **Titolo** | Testo dell'intestazione del banner |
| **Messaggio** | Testo descrittivo mostrato agli utenti |
| **Testo pulsante accetta** | Label del pulsante "Accetta tutti" |
| **Testo pulsante rifiuta** | Label del pulsante "Solo essenziali" |
| **Testo pulsante impostazioni** | Label per aprire le preferenze |
| **Link Privacy Policy** | URL della pagina privacy policy |

### Aspetto

Il banner appare come modale centrata o barra in basso (a seconda del tema) e persiste finché l'utente non fa una scelta.

## Privacy Policy

### Editor Privacy Policy

1. Vai in **Impostazioni → Privacy**
2. Sezione **Privacy Policy**
3. Modifica il testo con l'editor WYSIWYG
4. Salva

La privacy policy viene mostrata all'URL `/privacy-policy`.

### Contenuto Consigliato

La policy dovrebbe includere:
- Titolare del trattamento
- Tipi di dati raccolti
- Finalità del trattamento
- Base giuridica
- Periodo di conservazione
- Diritti degli interessati
- Contatti DPO (se applicabile)

## Log di Sicurezza

Pinakes registra eventi di sicurezza rilevanti per audit e debug.

### Accesso ai Log

1. Vai in **Impostazioni → Log Sicurezza**
2. Visualizzi gli ultimi 100 eventi (dal più recente)

### Tipi di Eventi

| Evento | Descrizione |
|--------|-------------|
| `csrf_failed` | Token CSRF non valido o mancante |
| `invalid_credentials` | Tentativo di login con credenziali errate |
| `success` | Login riuscito |
| `account_suspended` | Tentativo di accesso con account sospeso |
| `password_reset` | Richiesta reset password |
| `logout` | Disconnessione utente |

### Formato Log

Ogni riga nel log contiene:
- **Timestamp** in formato ISO
- **Tipo evento** tra parentesi quadre
- **Dettagli JSON** con IP, user_id, email, motivo

Esempio:
```
2025-01-24T10:30:45 [SECURITY:invalid_credentials] {"ip":"192.168.1.100","email":"test@example.com","reason":"password_mismatch"}
```

### File di Log

- **Posizione**: `storage/security.log`
- **Rotazione**: Manuale o tramite cron job esterno

## Secure Logger

L'applicazione usa un sistema di logging che sanitizza automaticamente i dati sensibili.

### Dati Redatti Automaticamente

Le seguenti chiavi vengono oscurate nei log:
- `password`, `passwd`, `pwd`
- `token`, `api_key`, `apikey`
- `secret`, `key`
- `auth`, `authorization`
- `credit_card`, `card_number`
- `cvv`, `ssn`

### File di Log Applicazione

- **Posizione**: `storage/logs/app.log`
- **Formato**: JSON lines per facile parsing

Esempio:
```json
{"timestamp":"2025-01-24 10:30:45","level":"INFO","message":"User logged in","context":{"user_id":42,"ip":"192.168.1.1"}}
```

## Best Practice per Amministratori

### Monitoraggio

- Controlla regolarmente i log di sicurezza
- Investiga eventi `csrf_failed` ripetuti (possibile attacco)
- Monitora `invalid_credentials` per rilevare brute force

### Manutenzione

- Mantieni l'applicazione aggiornata
- Usa HTTPS in produzione
- Configura backup regolari del database
- Limita gli accessi admin al minimo necessario

### Credenziali

- Usa password complesse per tutti gli account admin
- Non condividere le credenziali tra più persone
- Cambia le password periodicamente
- Disabilita gli account non più in uso

## Risoluzione Problemi

### "Sessione scaduta" frequenti

1. Verifica che il server abbia l'ora sincronizzata (NTP)
2. Controlla la configurazione PHP `session.gc_maxlifetime`
3. Assicurati che i cookie vengano inviati correttamente

### Token CSRF sempre invalido

1. Verifica che le sessioni PHP funzionino (`session_start()`)
2. Controlla che la cartella sessioni sia scrivibile
3. Assicurati che il form includa il campo `csrf_token`

### Cookie banner non appare

1. Verifica che il banner sia abilitato nelle impostazioni
2. Controlla la console browser per errori JavaScript
3. Svuota la cache del browser (potrebbe avere già un consenso salvato)

---

## Domande Frequenti (FAQ)

### 1. Perché ricevo spesso l'errore "Sessione scaduta"?

Il token CSRF ha una validità di **2 ore** (con variazione casuale ±10 minuti). Le cause più comuni:

| Causa | Soluzione |
|-------|-----------|
| Inattività prolungata | Normale, effettua nuovamente il login |
| Ora server non sincronizzata | Configura NTP sul server |
| Sessioni PHP non funzionanti | Verifica `session.save_path` sia scrivibile |
| Più tab aperte | Una tab può invalidare il token delle altre |

**Configurazione consigliata php.ini:**
```ini
session.gc_maxlifetime = 7200
session.cookie_lifetime = 0
```

---

### 2. Come verifico se qualcuno sta tentando un attacco brute force?

Controlla i log di sicurezza in **Impostazioni → Log Sicurezza**:

**Segnali di allarme:**
- Molti eventi `invalid_credentials` dallo stesso IP
- Tentativi su più account in rapida successione
- Orari insoliti (notte, weekend)

**Esempio log brute force:**
```
[SECURITY:invalid_credentials] {"ip":"1.2.3.4","email":"admin@...","reason":"password_mismatch"}
[SECURITY:invalid_credentials] {"ip":"1.2.3.4","email":"admin@...","reason":"password_mismatch"}
[SECURITY:invalid_credentials] {"ip":"1.2.3.4","email":"user@...","reason":"password_mismatch"}
```

**Azioni consigliate:**
- Blocca l'IP a livello firewall
- Considera l'uso di fail2ban
- Attiva rate limiting sul web server

---

### 3. Come configuro correttamente il cookie banner per il GDPR?

1. Vai in **Impostazioni → Privacy**
2. Configura tutti i campi:

| Campo | Esempio |
|-------|---------|
| Titolo | "Utilizziamo i cookie" |
| Messaggio | "Questo sito usa cookie per migliorare l'esperienza..." |
| Pulsante accetta | "Accetta tutti" |
| Pulsante rifiuta | "Solo essenziali" |
| Link privacy | "/privacy-policy" |

3. Salva e verifica in una finestra anonima (Ctrl+Shift+N)

**IMPORTANTE:** I cookie essenziali (sessione, CSRF) non richiedono consenso e funzionano sempre.

---

### 4. Cosa sono i cookie "essenziali" e perché non richiedono consenso?

I cookie essenziali sono **necessari** per il funzionamento base del sito:

| Cookie | Scopo | Durata |
|--------|-------|--------|
| `PHPSESSID` | Sessione utente | Fine sessione |
| `csrf_token` | Protezione sicurezza | 2 ore |
| `cookie_consent` | Ricorda la scelta | 1 anno |
| `locale` | Lingua preferita | 1 anno |

**Base giuridica:** Art. 6(1)(f) GDPR - legittimo interesse del titolare per funzionalità essenziali.

Non richiedono consenso perché senza di essi il sito non potrebbe funzionare.

---

### 5. Come scrivo una privacy policy conforme al GDPR?

La privacy policy deve contenere almeno:

**Sezioni obbligatorie:**
1. **Titolare del trattamento** - Nome, indirizzo, contatti
2. **Dati raccolti** - Email, nome, storico prestiti, IP
3. **Finalità** - Gestione prestiti, comunicazioni, statistiche
4. **Base giuridica** - Contratto, consenso, legittimo interesse
5. **Conservazione** - Quanto tempo conservi i dati
6. **Diritti** - Accesso, rettifica, cancellazione, portabilità
7. **Contatti** - Come esercitare i diritti

**Modifica in:** **Impostazioni → Privacy → Privacy Policy**

---

### 6. Dove trovo i log di sicurezza e quanto vengono conservati?

**Posizione file:**
- Log sicurezza: `storage/security.log`
- Log applicazione: `storage/logs/app.log`

**Conservazione:** I log crescono indefinitamente. Configura una rotazione:

```bash
# Cron job per rotazione settimanale (Linux)
0 0 * * 0 mv /path/to/storage/security.log /path/to/storage/security.log.old
```

**Visualizzazione da admin:**
1. Vai in **Impostazioni → Log Sicurezza**
2. Vengono mostrati gli ultimi 100 eventi

---

### 7. Quali dati sensibili vengono automaticamente oscurati nei log?

Il Secure Logger redige automaticamente queste chiavi:

| Chiave | Valore nei log |
|--------|----------------|
| `password`, `passwd`, `pwd` | `[REDACTED]` |
| `token`, `api_key`, `apikey` | `[REDACTED]` |
| `secret`, `key` | `[REDACTED]` |
| `authorization`, `auth` | `[REDACTED]` |
| `credit_card`, `card_number` | `[REDACTED]` |
| `cvv`, `ssn` | `[REDACTED]` |

**Esempio:**
```php
SecureLogger::info('Login', ['email' => 'user@test.it', 'password' => 'secret123']);
// Log risultante: {"email":"user@test.it","password":"[REDACTED]"}
```

---

### 8. Come proteggo l'area admin da accessi non autorizzati?

**Misure già implementate:**
- Token CSRF su tutte le operazioni
- Sessioni con scadenza
- Log di tutti i tentativi di accesso
- Hash sicuro delle password (password_hash)

**Misure aggiuntive consigliate:**

1. **Limita accesso per IP** (in `.htaccess`):
```apache
<Location /admin>
    Require ip 192.168.1.0/24
</Location>
```

2. **HTTPS obbligatorio**:
```apache
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

3. **Password robuste**: Minimo 12 caratteri, mix maiuscole/minuscole/numeri/simboli

---

### 9. Come gestisco un account compromesso?

Se sospetti che un account sia stato compromesso:

**Azioni immediate:**
1. Vai in **Utenti → [utente] → Modifica**
2. Cambia la password immediatamente
3. Seleziona **Forza logout** per invalidare tutte le sessioni
4. Se admin, degrada temporaneamente a ruolo inferiore

**Investigazione:**
1. Controlla **Log Sicurezza** per attività sospette
2. Verifica operazioni recenti (prestiti, modifiche catalogo)
3. Controlla IP degli ultimi accessi

**Prevenzione futura:**
- Attiva notifiche email per login
- Considera autenticazione a due fattori (tramite plugin)

---

### 10. Pinakes supporta l'autenticazione a due fattori (2FA)?

**Nativamente:** No, Pinakes non include 2FA di default.

**Tramite plugin:** È possibile sviluppare un plugin che aggiunga 2FA usando gli hook disponibili:

```php
// Esempio hook per 2FA
$hooks->register('auth.login.after', function($user) {
    if ($user->has_2fa_enabled) {
        // Richiedi codice TOTP
        redirect('/2fa/verify');
    }
});
```

**Alternative:**
- Protezione a livello web server (Apache/Nginx basic auth come secondo fattore)
- VPN per accesso admin
- Restrizione per IP come descritto nella FAQ 8
