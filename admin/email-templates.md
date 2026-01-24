# Template Email

Pinakes include un sistema completo di notifiche email personalizzabili.

## Template Disponibili

| Template | Evento | Destinatario |
|----------|--------|--------------|
| `registration` | Nuova registrazione utente | Nuovo utente |
| `registration_admin` | Nuova registrazione | Amministratori |
| `email_verification` | Verifica indirizzo email | Utente |
| `password_reset` | Richiesta reset password | Utente |
| `user_approved` | Approvazione account | Utente |
| `loan_approved` | Prestito approvato | Utente |
| `loan_ready` | Libro pronto al ritiro | Utente |
| `loan_reminder` | Promemoria scadenza | Utente |
| `loan_overdue` | Prestito in ritardo | Utente |
| `pickup_expired` | Ritiro scaduto | Utente |
| `reservation_available` | Prenotazione disponibile | Utente |
| `contact_form` | Messaggio da contatti | Amministratori |

## Personalizzazione Template

### Accedere all'Editor

1. Vai in **Impostazioni → Email**
2. Scorri fino a **Template Email**
3. Clicca su un template per modificarlo

### Struttura Template

Ogni template ha:
- **Oggetto**: riga dell'oggetto email
- **Corpo**: contenuto HTML dell'email
- **Stato**: attivo/disattivato

### Editor WYSIWYG

L'editor TinyMCE permette di:
- Formattare testo (grassetto, corsivo, liste)
- Inserire link
- Modificare colori
- Inserire placeholder dinamici

## Placeholder Disponibili

I placeholder vengono sostituiti con i dati reali al momento dell'invio.

### Placeholder Universali

| Placeholder | Descrizione |
|-------------|-------------|
| `{{nome_biblioteca}}` | Nome della biblioteca configurato |
| `{{url_biblioteca}}` | URL base dell'applicazione |
| `{{anno}}` | Anno corrente |

### Placeholder Utente

| Placeholder | Descrizione |
|-------------|-------------|
| `{{nome}}` | Nome dell'utente |
| `{{cognome}}` | Cognome dell'utente |
| `{{email}}` | Email dell'utente |
| `{{tessera}}` | Numero tessera biblioteca |

### Placeholder Prestito

| Placeholder | Descrizione |
|-------------|-------------|
| `{{titolo_libro}}` | Titolo del libro |
| `{{autore}}` | Autore/i del libro |
| `{{data_inizio}}` | Data inizio prestito |
| `{{data_scadenza}}` | Data scadenza prestito |
| `{{giorni_ritardo}}` | Giorni di ritardo (se applicabile) |

### Placeholder Prenotazione

| Placeholder | Descrizione |
|-------------|-------------|
| `{{posizione_coda}}` | Posizione nella coda prenotazioni |
| `{{scadenza_ritiro}}` | Data limite per il ritiro |

### Placeholder Verifica Email

| Placeholder | Descrizione |
|-------------|-------------|
| `{{sezione_verifica}}` | Blocco HTML con pulsante verifica |
| `{{link_verifica}}` | URL diretto per la verifica |

### Placeholder Reset Password

| Placeholder | Descrizione |
|-------------|-------------|
| `{{link_reset}}` | URL per reimpostare password |
| `{{scadenza_link}}` | Validità del link (ore) |

## Configurazione SMTP

### Impostazioni Base

In **Impostazioni → Email → Configurazione SMTP**:

| Campo | Descrizione | Esempio |
|-------|-------------|---------|
| Host SMTP | Server email | `smtp.gmail.com` |
| Porta | Porta SMTP | `587` |
| Crittografia | TLS/SSL/Nessuna | `TLS` |
| Username | Account email | `biblioteca@example.com` |
| Password | Password/App password | `xxxx` |
| Email mittente | Indirizzo FROM | `biblioteca@example.com` |
| Nome mittente | Nome visualizzato | `Biblioteca Comunale` |

### Provider Comuni

#### Gmail
```
Host: smtp.gmail.com
Porta: 587
Crittografia: TLS
```
> Richiede "App Password" se 2FA attivo

#### Outlook/Office 365
```
Host: smtp.office365.com
Porta: 587
Crittografia: TLS
```

#### Server Locale
```
Host: localhost
Porta: 25
Crittografia: Nessuna
```

## Test Invio Email

### Inviare Email di Test

1. Vai in **Impostazioni → Email**
2. Sezione **Test Email**
3. Inserisci un indirizzo destinatario
4. Clicca **Invia email di test**

### Verifica Risultato

Se l'invio fallisce, controlla:
1. Credenziali SMTP corrette
2. Firewall/blocchi porta
3. Log in `storage/logs/app.log`

## Notifiche In-App

Oltre alle email, il sistema supporta notifiche interne:

### Tipi di Notifica

- **Info**: informazioni generali
- **Success**: operazioni completate
- **Warning**: avvisi
- **Error**: errori

### Visualizzazione

Le notifiche appaiono:
- Nel pannello utente (icona campanella)
- Come badge sul menu
- Nella dashboard admin

### Gestione Notifiche

Gli utenti possono:
- Visualizzare notifiche non lette
- Segnare come lette
- Eliminare notifiche

## Cron per Email Automatiche

Per le email automatiche (reminder scadenza, solleciti), configura un cron job:

```bash
# Ogni giorno alle 8:00
0 8 * * * php /path/to/public/index.php cron:send-reminders
```

### Job Disponibili

| Comando | Funzione |
|---------|----------|
| `cron:send-reminders` | Invia reminder scadenza |
| `cron:send-overdue` | Invia solleciti ritardo |
| `cron:cleanup-expired` | Pulisce prenotazioni scadute |

## Risoluzione Problemi

### Email non inviate

1. **Verifica configurazione SMTP**
   - Test email funziona?
   - Credenziali corrette?

2. **Controlla i log**
   ```bash
   tail -100 storage/logs/app.log | grep -i email
   ```

3. **Verifica template attivo**
   - Il template specifico è abilitato?

### Email in spam

- Configura SPF/DKIM sul dominio
- Usa un indirizzo mittente del dominio
- Evita parole spam nell'oggetto

### Placeholder non sostituiti

- Verifica sintassi: `{{placeholder}}` (doppie graffe)
- Controlla che il placeholder sia supportato per quel template
- Verifica che i dati siano disponibili nel contesto

---

## Domande Frequenti (FAQ)

### 1. Come configuro Gmail per inviare email da Pinakes?

Per usare Gmail come server SMTP:

**Configurazione:**
```
Host: smtp.gmail.com
Porta: 587
Crittografia: TLS
Username: tua-email@gmail.com
Password: [App Password]
```

**Requisiti Gmail:**
1. Abilita la **verifica in due passaggi** sul tuo account Google
2. Genera una **App Password** (non la password normale):
   - Vai su myaccount.google.com → Sicurezza → Password per le app
   - Genera una password per "Posta" su "Altro (nome personalizzato)"
3. Usa questa password di 16 caratteri in Pinakes

**Limite invii:** Gmail consente ~500 email/giorno per account consumer.

---

### 2. Come posso personalizzare il layout grafico delle email?

I template email supportano HTML completo:

1. Vai in **Impostazioni → Email → Template**
2. Seleziona il template da modificare
3. Nell'editor WYSIWYG puoi:
   - Modificare formattazione (grassetto, colori)
   - Inserire immagini (come URL esterni)
   - Aggiungere link

**CSS inline obbligatorio:** Molti client email (Gmail, Outlook) ignorano `<style>`. Usa sempre style inline:
```html
<p style="color: #333; font-size: 16px;">Testo</p>
```

---

### 3. Perché alcune email finiscono in spam?

**Cause comuni e soluzioni:**

| Problema | Soluzione |
|----------|-----------|
| No SPF/DKIM | Configura DNS del dominio |
| Mittente generico | Usa email@tuodominio.it, non gmail |
| Parole spam nell'oggetto | Evita "GRATIS", "URGENTE", maiuscole |
| Troppi link | Limita i link nel corpo |
| Nuovo dominio | Costruisci reputazione gradualmente |

**Record DNS consigliati:**
```
SPF:  v=spf1 include:_spf.google.com ~all
DKIM: Configura tramite provider SMTP
DMARC: v=DMARC1; p=none; rua=mailto:admin@tuodominio.it
```

---

### 4. Come funzionano le email automatiche di reminder scadenza?

Le email di promemoria richiedono un **cron job** configurato:

```bash
# Ogni giorno alle 8:00
0 8 * * * php /path/to/public/index.php cron:send-reminders
```

**Comportamento:**
- Controlla prestiti in scadenza nei prossimi X giorni
- Invia email con template `loan_reminder`
- Registra l'invio per evitare duplicati

**Configurazione:**
- I giorni di anticipo si configurano in **Impostazioni → Prestiti**

---

### 5. Posso disabilitare specifici template email?

Sì, ogni template ha uno stato attivo/disattivo:

1. Vai in **Impostazioni → Email → Template**
2. Trova il template
3. Disattiva il toggle "Attivo"

**Attenzione:**
- `email_verification` - Disabilitando, gli utenti non potranno verificare l'email
- `password_reset` - Disabilitando, nessuno potrà recuperare la password
- `loan_approved` - Gli utenti non sapranno quando ritirare

---

### 6. Quali placeholder posso usare in ogni template?

I placeholder dipendono dal contesto:

| Template | Placeholder Disponibili |
|----------|------------------------|
| `registration` | Universali + Utente |
| `loan_approved` | Universali + Utente + Prestito |
| `reservation_available` | Universali + Utente + Prenotazione |
| `password_reset` | Universali + Utente + Reset |
| `email_verification` | Universali + Utente + Verifica |

**Test placeholder:**
Inserisci `{{placeholder}}` nel corpo e verifica nell'email di test.

---

### 7. Come testo le email senza inviare a utenti reali?

**Metodo 1 - Email di test:**
1. Vai in **Impostazioni → Email → Test Email**
2. Inserisci la TUA email
3. Clicca **Invia email di test**

**Metodo 2 - Mailtrap (sviluppo):**
```
Host: sandbox.smtp.mailtrap.io
Porta: 587
Username: [da Mailtrap]
Password: [da Mailtrap]
```
Tutte le email finiscono in una inbox virtuale.

**Metodo 3 - Log locale:**
In `.env` imposta `MAIL_LOG_ONLY=true` per salvare email su file invece di inviarle.

---

### 8. Come aggiungo un nuovo template email personalizzato?

Attualmente i template sono predefiniti dal sistema. Per template custom:

**Opzione 1 - Modifica template esistente:**
- Usa un template poco usato (es. `contact_form`)
- Personalizza oggetto e corpo

**Opzione 2 - Plugin:**
```php
HookManager::addAction('notification.send', function($type, $data) {
    if ($type === 'custom_event') {
        $mailer->send($data['email'], 'Oggetto', $body);
    }
});
```

---

### 9. Le notifiche in-app sostituiscono le email?

No, sono **complementari**:

| Canale | Uso |
|--------|-----|
| **Email** | Comunicazioni importanti, arrivano anche senza login |
| **In-app** | Promemoria veloci, visibili al prossimo accesso |

**Comportamento:**
- Prestito approvato → Email + Notifica in-app
- Libro disponibile → Email + Notifica in-app
- Scadenza imminente → Solo email (se cron configurato)

Le notifiche in-app si vedono cliccando l'icona campanella nell'header.

---

### 10. Come risolvo errori "Connection timed out" o "Authentication failed"?

**Connection timed out:**
1. Verifica che host e porta siano corretti
2. Controlla che il firewall permetta connessioni in uscita sulla porta SMTP
3. Prova porta alternativa (465 invece di 587, o viceversa)

**Authentication failed:**
1. Verifica username e password
2. Per Gmail: usa App Password, non la password normale
3. Per Office 365: potrebbe richiedere OAuth2 (non supportato, usa SMTP diretto)

**Debug avanzato:**
```bash
# Testa connessione SMTP
telnet smtp.gmail.com 587

# Controlla log
grep -i "email\|smtp" storage/logs/app.log
```
