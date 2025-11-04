# 📧 Guida Email e Notifiche - Sistema Biblioteca

## 🎯 Introduzione Semplice

Il sistema invia **automaticamente email** quando accadono cose importanti nella biblioteca:
- 📝 Quando un utente si registra
- ✅ Quando un account viene approvato
- 📚 Quando un prestito sta per scadere
- ⚠️ Quando un prestito è in ritardo
- 🎉 Quando un libro desiderato torna disponibile

**Non devi fare nulla** - tutto accade in automatico! 🤖

---

## 📋 Tipi di Email Inviate Automaticamente

### 1️⃣ Email di Registrazione
**Chi la riceve?** → Nuovo utente che si registra
**Cosa dice?** → "Ciao! Grazie per esserti registrato. Il tuo account è in attesa di approvazione dall'amministratore."

---

### 2️⃣ Email di Approvazione Account
**Chi la riceve?** → Utente appena approvato
**Cosa dice?** → "🎉 Accettato! Ecco il tuo codice tessera e puoi accedere subito"

---

### 3️⃣ Email Avviso Scadenza Prestito
**Chi la riceve?** → Utente con prestito scadente (3 giorni prima)
**Cosa dice?** → "⏰ Attenzione! Il libro scade tra 3 giorni. Puoi rinnovare cliccando qui"

---

### 4️⃣ Email Prestito Scaduto
**Chi la riceve?** → Utente con libro in ritardo
**Cosa dice?** → "🚨 Urgente! Il libro è scaduto. Hai una multa di X€ al giorno"

---

### 5️⃣ Email Libro Desiderato Disponibile
**Chi la riceve?** → Utente che aveva messo il libro nella lista desideri
**Cosa dice?** → "🎉 Buone notizie! Il libro che volevi è disponibile! Hai 24 ore per prenotarlo"

---

### 6️⃣ Email Prenotazione Pronta
**Chi la riceve?** → Utente che ha prenotato un libro
**Cosa dice?** → "📦 Il tuo libro è pronto! Vieni a ritirarlo entro 5 giorni"

---

### 7️⃣ Email per Admin - Nuova Registrazione
**Chi la riceve?** → Amministratore della biblioteca
**Cosa dice?** → "Un nuovo utente si è registrato. Clicca qui per approvare"

---

### 8️⃣ Email per Admin - Richiesta Prestito
**Chi la riceve?** → Amministratore della biblioteca
**Cosa dice?** → "Un utente ha richiesto un libro. Clicca qui per approvare"

---

## 🎨 Personalizzare le Email (Tab "Template" in Impostazioni)

### 📍 Dove trovare questa sezione
**Dashboard** → **Impostazioni** → **Tab "Template"** (terzo tab in alto)

### Cosa puoi fare qui?

In questo tab potrai **personalizzare completamente le email** che il sistema invia automaticamente:

- ✏️ **Oggetto email** (il titolo che vede il destinatario nella inbox)
- 📝 **Corpo email** (il messaggio vero e proprio)
- 🎨 **Formattazione** (grassetto, corsivo, colori, link)
- 🔗 **Link dinamici** (che si aggiornano automaticamente)

### 📧 Le Email Personalizzabili

Il sistema ti offre **8 template email** che puoi modificare:

| **#** | **Email** | **Quando viene inviata** | **Chi la riceve** |
|-------|-----------|-------------------------|-------------------|
| 1️⃣ | Registrazione | Nuovo utente si registra | Nuovo utente |
| 2️⃣ | Approvazione Account | Admin approva l'account | Utente approvato |
| 3️⃣ | Scadenza Prestito (3 giorni) | 3 giorni prima della scadenza | Utente che ha il libro |
| 4️⃣ | Prestito Scaduto | Dopo la data di scadenza | Utente con ritardo |
| 5️⃣ | Libro Desiderato Disponibile | Un libro richiesto torna disponibile | Utente che lo voleva |
| 6️⃣ | Prenotazione Pronta | Il libro prenotato è disponibile | Utente che ha prenotato |
| 7️⃣ | Admin - Nuova Registrazione | Nuovo utente si registra | Amministratore |
| 8️⃣ | Admin - Richiesta Prestito | Utente richiede un libro | Amministratore |

### 🔧 Come Personalizzare un Template

#### **Step 1: Scegliere l'Email**
1. Scorri verso il basso nel tab "Template"
2. Troverai **8 sezioni**, una per ogni tipo di email
3. Seleziona quella che vuoi modificare

#### **Step 2: Modificare l'Oggetto**
```
Campo "Oggetto":
Questo è il titolo che vede il destinatario nella sua inbox.

Esempio PRIMA:
"Registrazione - Biblioteca Civica"

Esempio DOPO:
"Benvenuto! La tua registrazione è stata ricevuta"
```

#### **Step 3: Modificare il Corpo Email**
```
Campo "Corpo email":
Questo è il messaggio vero e proprio.
Usa l'editor visuale (simile a Word) per:
- Scrivere il testo
- Formattare (grassetto, corsivo, sottolineato)
- Aggiungere link
- Modificare colori
```

### 🔑 Segnaposti (Variabili Intelligenti)

Ogni template ha **segnaposti** che il sistema riempie automaticamente.

**Cosa vuol dire?** Il sistema sostituisce questi marcatori con i dati reali:

| **Segnaposto** | **Diventa** | **Esempio** |
|------------|----------|------------|
| `{{user_name}}` | Nome dell'utente | Mario Rossi |
| `{{book_title}}` | Titolo del libro | "La Divina Commedia" |
| `{{due_date}}` | Data di scadenza | 25/10/2025 |
| `{{app_name}}` | Nome della biblioteca | Biblioteca ITIS Marconi |
| `{{library_address}}` | Indirizzo della biblioteca | Via Roma 123, Milano |

**Quando usi questi segnaposti:**
- L'email di registrazione dirà: "Ciao Mario Rossi!" (non "Ciao {{user_name}}")
- L'email di scadenza dirà: "Il libro 'La Divina Commedia' scade il 25/10/2025"
- Ecc.

### 💡 Esempio Pratico di Personalizzazione

**Scenario**: Vuoi che le email dicano "Biblioteca ITIS Marconi" anziché il nome generico.

**Soluzione**:
1. **Apri il Tab "Template"** in Impostazioni
2. **Seleziona il primo template** (Registrazione)
3. **Nel campo "Corpo email"**:
   - Cerca il testo: `{{app_name}}`
   - È già lì! Sistema lo sostituirà automaticamente
4. **Per cambiare il nome della biblioteca**:
   - **Non modifica qui il template**
   - **Vai in Impostazioni → Tab "Identità"**
   - **Cambia il "Nome applicazione"** in "Biblioteca ITIS Marconi"
   - Tutte le email userannno quel nome automaticamente!

### ✨ Best Practices per i Template

#### ✅ **Fai così:**
- ✅ Usa i segnaposti forniti (`{{user_name}}`, `{{book_title}}`, ecc.)
- ✅ Mantieni il tono professionale ma amichevole
- ✅ Includi un link di aiuto (es: "Hai domande? Contattaci")
- ✅ Metti il numero di telefono/email della biblioteca
- ✅ Testa il template prima di salvare (invia una prova a te stesso)

#### ❌ **Evita:**
- ❌ Non rimuovere i segnaposti (le email sarebbero generiche)
- ❌ Non scrivere TUTTO IN MAIUSCOLO (sembra un urlo)
- ❌ Non mettere link strani o sospetti
- ❌ Non fare template troppo lunghi (più di 500 parole)
- ❌ Non cambiare il template ogni giorno (confonde gli utenti)

### 🧪 Testare i Template

**Come vedere come appare davvero l'email:**

1. **Personalizza il template** come vuoi
2. **Clicca "Salva template"** in basso
3. **Vai in Impostazioni → Tab "Email"**
4. **Clicca "Test Email"** (se disponibile)
5. **Seleziona il tipo di email** che vuoi testare
6. **Metti la tua email** nel campo test
7. **Clicca "Invia"**
8. **Controlla la tua inbox** (o spam)

Così vedrai esattamente come gli utenti riceveranno l'email!

### 📋 Esempi di Template Personalizzati

#### **Esempio 1: Email di Registrazione Accogliente**
```
Oggetto:
"🎉 Benvenuto! {{user_name}} - Registrazione ricevuta"

Corpo:
Ciao {{user_name}},

Grazie per esserti registrato in {{app_name}}!

Il tuo account è in fase di revisione. L'amministratore lo
approverà entro 24 ore.

Nel frattempo, puoi sfogliare il nostro catalogo di libri:
[Link al catalogo]

Se hai domande:
📧 {{app_email}}
📞 {{app_phone}}

A presto!
La squadra di {{app_name}}
```

#### **Esempio 2: Email di Scadenza con Tono Amichevole**
```
Oggetto:
"⏰ Ultimi 3 giorni per '{{book_title}}'"

Corpo:
Ciao {{user_name}},

Tra 3 giorni il tuo prestito di "{{book_title}}" scade
(scadenza: {{due_date}}).

Non dimenticarti di restituire il libro! Se non hai finito,
puoi rinnovare il prestito cliccando qui:
[Link a rinnova prestito]

Domande? Contattaci!
```

---

## ⚙️ Configurazione Email (Tab "Email" in Impostazioni)

### 📍 Dove trovare questa sezione
**Dashboard** → **Impostazioni** → **Tab "Email"** (secondo tab in alto)

### Come Funziona?
Il sistema ha bisogno di un "postino" per spedire le email. Questo postino si chiama **server SMTP** (un computer specializzato che invia email).

### Scegliere il Metodo di Invio

Nella sezione "Configurazione invio" troverai **3 opzioni**:

#### **Opzione 1: PHP mail()** (Semplice ma meno affidabile)
```
✅ Pro:
- Configurazione minima
- Non serve account esterno
- Veloce

❌ Contro:
- Spesso finisce nello spam
- Dipende dal server
```
**Quando usarlo?** Solo per test, non consigliato in produzione

---

#### **Opzione 2: PHPMailer** (Intermedio)
```
✅ Pro:
- Affidabilità media
- Buon compromesso

❌ Contro:
- Configurazione nel codice (non in interfaccia)
- Meno flessibile
```
**Quando usarlo?** Se il tuo hosting lo supporta e gestisce automaticamente la configurazione

---

#### **Opzione 3: SMTP Personalizzato** (CONSIGLIATO ⭐)
```
✅ Pro:
- Massima affidabilità
- Configurabile dall'interfaccia
- Email non finiscono nello spam
- Funziona sempre

❌ Contro:
- Serve un account email (Gmail, PEC, server scolastico)
```
**Quando usarlo?** SEMPRE se possibile. È la scelta migliore.

### 📋 I Campi Obbligatori

Qualunque metodo scegli, dovrai compilare questi campi:

| **Campo** | **Cosa inserire** | **Esempio** |
|-----------|-------------------|------------|
| **Mittente (email)** | Email da cui spedire | `biblioteca@scuola.it` |
| **Mittente (nome)** | Nome che vede il destinatario | `Biblioteca ITIS Marconi` |

### 🔧 Configurazione Server SMTP (Se scegli "SMTP Personalizzato")

Dopo aver selezionato **"SMTP personalizzato"**, compariranno questi campi:

| **Campo** | **Dove trovarlo** | **Esempio** |
|-----------|-------------------|------------|
| **Host** | Nome del server mail | `smtp.gmail.com` |
| **Porta** | Numero porta di connessione | `587` (standard) o `465` (SSL) |
| **Username** | Email dell'account | `biblioteca@gmail.com` |
| **Password** | Password account | `abc123def456` (o password app) |
| **Crittografia** | Tipo di sicurezza | `TLS` (consigliato) |

### 📌 Provider Più Comuni - Dati da Usare

#### **Gmail** (Gratuito - CONSIGLIATO)
```
Host: smtp.gmail.com
Porta: 587
Crittografia: TLS
Username: tua-email@gmail.com
Password: [Password App generata in Impostazioni Google]

⚠️ IMPORTANTE: Con Gmail devi creare una "Password app":
1. Vai su https://myaccount.google.com/security
2. Attiva "Verifica in 2 passaggi"
3. Torna a Security e cerca "App passwords"
4. Genera password per "Mail" e "Windows"
5. Copia quella generata e usala qui
```

#### **Server Scolastico** (Dipende da scuola)
```
Chiedi al tuo amministratore IT i dati di connessione:
- Host: [chiedi all'admin]
- Porta: [chiedi all'admin]
- Username: [la tua email scolastica]
- Password: [la tua password scolastica]
- Crittografia: [chiedi all'admin]

Questo è diverso da scuola a scuola, non c'è uno standard.
```

#### **PEC (Raccomandata Digitale)** (Più formale)
```
Host: smtps.pec.aruba.it (Aruba) / smtps.pec.it (altri provider)
Porta: 465
Crittografia: SSL
Username: [la tua email PEC]
Password: [la tua password PEC]

Nota: PEC ha costi di registrazione (~€10-20/anno)
```

#### **Outlook/Hotmail** (Se usi Microsoft)
```
Host: smtp-mail.outlook.com
Porta: 587
Crittografia: TLS
Username: tua-email@outlook.com
Password: [la tua password]
```

### ✅ Testare la Configurazione

**Come verificare che funziona:**

1. **Compila tutti i campi** con i dati del tuo provider
2. **Clicca "Salva impostazioni email"** in basso
3. **Torna nella sezione Email**
4. **Clicca il pulsante verde "Test Connessione"** (se presente)
5. **Attendi il risultato**:
   - ✅ **Verde**: Configurazione OK! Le email partiranno
   - ❌ **Rosso**: Controlla dati (host, porta, credenziali)

**Se il test non passa:**
- Verifica che l'email e password siano corretti
- Verifica il nome host (copialo esattamente dal provider)
- Verifica la porta (587 per TLS, 465 per SSL)
- Se usi Gmail, assicurati di usare una "password app" non la password normale
- Contatta il supporto del provider email

---

## 🧪 Testare le Email

### Test Veloce
1. **Dashboard** → **Impostazioni** → **Email**
2. **Clicca**: "Invia Email di Prova"
3. **Seleziona**: Il tipo di email
4. **Metti**: La tua email personale
5. **Clicca**: "Invia"
6. **Controlla**: La tua posta

### Cosa Controllare?
- ✅ Ricevi l'email in pochi secondi?
- ✅ Il design è quello che volevi?
- ✅ I dati sono corretti (nome, data, ecc.)?
- ✅ I link funzionano?

---

## 🔍 Problema: Email non Ricevute?

### Checklist Rapida

#### 1. Verifica Configurazione SMTP
```
Dashboard → Impostazioni → Email
Controlla che tutti i dati siano compilati
```

#### 2. Verifica Indirizzo Email
```
Email corretta?
Es. mario@example.com (sì)
Es. mario@.com (no - incompleta)
```

#### 3. Controlla lo Spam
```
Controlla anche la cartella SPAM/Posta Indesiderata
A volte le email finiscono lì per sbaglio
```

#### 4. Prova a Inviare Email di Test
```
Dashboard → Impostazioni → Email → "Test Email"
Se il test non funziona, il problema è nella configurazione
```

### Se Ancora Non Funziona?
- ✅ Contatta l'amministratore del sistema
- ✅ Mostragli il messaggio di errore (se c'è)
- ✅ Digli il provider che stai usando (Gmail, PEC, ecc.)

---

## 📊 Statistiche Email (Facoltativo)

### Dove Vederle?
1. **Dashboard** → **Impostazioni** → **Email**
2. **Sezione**: "Statistiche Email"

### Cosa Significano?

| **Metrica** | **Cosa è** | **Cosa Significa** |
|-------------|-----------|-------------------|
| **Inviate oggi** | Numero email spedite | Quante email ha mandato il sistema oggi |
| **In coda** | Email in attesa | Quante email devono ancora essere spedite |
| **Consegnate** | Email ricevute | Quante hanno raggiunto la destinazione |
| **Errori** | Email fallite | Quante non sono riuscite a partire |

> 💡 Se gli errori sono pochi (< 5%) è normale. Se sono molti (> 20%), c'è un problema con la configurazione.

---

## 🎯 Variabili Intelligenti (Per Admin Avanzati)

Le email hanno dei "marcatori" che si riempiono automaticamente:

| **Marcatore** | **Diventa** | **Esempio** |
|---------------|-----------|------------|
| `{user_name}` | Nome utente | Mario Rossi |
| `{book_title}` | Titolo libro | La Divina Commedia |
| `{due_date}` | Data scadenza | 25/10/2025 |
| `{app_name}` | Nome biblioteca | Biblioteca ITIS Marconi |
| `{library_address}` | Indirizzo | Via Roma 123, Milano |

**Puoi usarli** quando personalizzi i template. Il sistema li "compila" automaticamente per ogni email!

---

## 📚 Best Practices (Suggerimenti)

### ✅ Cose da Fare
- ✅ Testa sempre le email prima di usarle in produzione
- ✅ Personalizza il nome della biblioteca (più professionale)
- ✅ Aggiungi il logo della scuola (riconoscibilità)
- ✅ Includi un numero di telefono (gli utenti devono poter contattarti)
- ✅ Controlla regolarmente la posta in uscita (feedback)

### ❌ Cose da Evitare
- ❌ Non cambiare le email ogni giorno (confonde gli utenti)
- ❌ Non usare colori strani o layout complicati
- ❌ Non includere link pericolosi
- ❌ Non inviare troppe email (spam!)

---

## 💡 Consigli per Evitare Spam

### Il Sistema Automaticamente...
- ✅ Non invia email a utenti inesistenti
- ✅ Non invia lo stesso messaggio 100 volte
- ✅ Controlla che gli indirizzi siano validi
- ✅ Usa un provider affidabile (Gmail, PEC, ecc.)

### Tu Devi...
- ✅ Mantenere il design "pulito" e professionale
- ✅ Non scrivere TUTTO IN MAIUSCOLO
- ✅ Non fare link strani o sospetti
- ✅ Non cambiare subject ogni giorno

---

## 🚀 Quick Start - Setup Email (5 minuti)

### Passo 1: Prepara le Credenziali
```
Scegli uno tra:
- Account Gmail (più facile)
- Server scolastico (se disponibile)
- Servizio PEC (più formale)

Raccogli: email, password (o password app), host, port
```

### Passo 2: Accedi alle Impostazioni
```
Dashboard → Impostazioni → Email o Notifiche
```

### Passo 3: Riempi i Campi
```
Provider: [Gmail / PEC / Altro]
Email mittente: [la tua email]
Password: [la tua password]
Host SMTP: [dipende dal provider]
Port: [solitamente 587 o 465]
```

### Passo 4: Testa
```
Clicca: "Testa Connessione"
Attendi: ✅ Connessione riuscita!
```

### Passo 5: Personalizza (Opzionale)
```
Dashboard → Impostazioni → Email → Template
Cambia nome biblioteca, logo, contatti
```

### Passo 6: ✅ Fatto!
```
Le email partono automaticamente ora!
```

---

## 📞 Supporto e Aiuto

### Se Hai Dubbi
1. **Leggi questa guida** - risponde alla maggior parte delle domande
2. **Prova il test email** - il sistema ti dirà se c'è un problema
3. **Controlla lo spam** - a volte finiscono lì per sbaglio
4. **Contatta il supporto** - se non riesci da solo

### Info Utili da Avere Quando Chiedi Aiuto
- Quale provider usi? (Gmail, PEC, server scuola?)
- Qual è il messaggio di errore esatto?
- Quando ha smesso di funzionare? (oggi? una settimana fa?)

---

## 🎉 Risultato Finale

Dopo il setup, il sistema automaticamente:

✅ Invia email di benvenuto ai nuovi utenti
✅ Notifica quando gli account vengono approvati
✅ Avvisa prima della scadenza prestiti
✅ Ricorda i prestiti in ritardo
✅ Segnala libri desiderati disponibili
✅ Comunica con gli amministratori

**Tutto senza che tu debba fare nulla!** 🤖

---

## 📚 Guide Correlate

- [Guida Impostazioni](/docs/settings.MD) - Configurazione generale del sistema (dove si trovano Tab Email e Template)
- [Guida Prestiti](/docs/prestiti.MD) - Gestione prestiti (che triggera automaticamente le email)
- [Guida Libri](/docs/libri.MD) - Gestione del catalogo libri

---

**📖 Documento aggiornato: 19 Ottobre 2025**
**📌 Versione: 3.0.0 - Con Tab Email e Template dettagliati**
**✍️ Linguaggio chiaro e diretto per utenti finali**
