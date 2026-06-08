# 📝 Registrazione - Crea il Tuo Account

> **Vai qui**: http://localhost:8000/register

La pagina **registrazione** è dove **crei un nuovo account** per accedere alla biblioteca online.

---

## 🎯 Chi Dovrebbe Registrarsi?

```
✅ Non hai ancora un account
✅ Vuoi gestire i tuoi prestiti online
✅ Vuoi salvare libri nei preferiti
✅ Vuoi fare richieste di prestito
✅ Vuoi prenotare libri
```

---

## 📖 Come Registrarsi

### **La Pagina di Registrazione**

```
┌────────────────────────────────────────┐
│       BIBLIOTECA (Logo)                │
│     "Crea un nuovo account"            │
├────────────────────────────────────────┤
│                                        │
│  ⚠️ MESSAGGI (errori o successi)      │
│                                        │
│  Nome:               Cognome:          │
│  [Mario________]     [Rossi________]   │
│                                        │
│  Email:                                │
│  [mario.rossi@email.it_______]        │
│                                        │
│  Telefono:  (obbligatorio)             │
│  [+39 333 1234567____________]         │
│                                        │
│  Indirizzo:  (obbligatorio, textarea)  │
│  [Via Roma 1, Milano_________]         │
│                                        │
│  Data di nascita:    Sesso:            │
│  [gg/mm/aaaa]        [M / F / Altro ▾] │  ← opzionali
│                                        │
│  Codice fiscale:  (opzionale)          │
│  [RSSMRA__________________]            │
│                                        │
│  Password:                             │
│  [****________________________]        │
│                                        │
│  Conferma Password:                    │
│  [****________________________]        │
│                                        │
│  ☐ Accetto la Privacy Policy          │
│                                        │
│  [📝 Crea Account]                    │
│                                        │
│  Hai già un account? [Accedi]          │
│                                        │
└────────────────────────────────────────┘
```

> **Campi obbligatori** (validati lato server da `RegistrationController::register`):
> nome, cognome, email, **telefono**, **indirizzo**, password, conferma password,
> accettazione privacy. **Campi opzionali**: data di nascita, sesso (`M`/`F`/`Altro`),
> codice fiscale. Non esiste alcuna casella "Mostra password".

---

## 🔐 Step by Step - Come Registrarsi

### **1. Nome**

```
Campo: Nome
Esempio: Mario
⚠️ IMPORTANTE:
  - Minimo 2 caratteri
  - Massimo 50 caratteri
  - Niente numeri o simboli strambi
  - Tuo nome di battesimo
```

### **2. Cognome**

```
Campo: Cognome
Esempio: Rossi
⚠️ IMPORTANTE:
  - Minimo 2 caratteri
  - Massimo 50 caratteri
  - Niente numeri o simboli strambi
  - Tuo cognome
```

### **3. Email**

```
Campo: Email
Esempio: mario.rossi@email.it
⚠️ IMPORTANTE:
  - Deve essere formato valido: nome@dominio.it
  - Deve essere EMAIL REALE (riceverai conferma qui!)
  - Controlla bene per evitare typo
  - Non puoi cambiarla dopo (facilmente)
  - Se usi email sbagliata, non riceverai email di verifica!
```

**Quale email usare?**
- 📧 Gmail, Outlook, Libero, ecc.
- 🎓 Email scolastica (se disponibile)
- 💼 Email di lavoro
- ⚠️ NON email fasulle o temporanee

### **4. Telefono** (obbligatorio)

```
Campo: Telefono
Esempio: +39 333 1234567
⚠️ Campo richiesto: senza telefono la registrazione fallisce
   con error=missing_fields
```

### **5. Indirizzo** (obbligatorio)

```
Campo: Indirizzo (textarea)
Esempio: Via Roma 1, 20100 Milano
⚠️ Campo richiesto: senza indirizzo la registrazione fallisce
```

### **6. Campi opzionali**

```
- Data di nascita (gg/mm/aaaa)
- Sesso (select): M / F / Altro
- Codice fiscale
Questi campi NON sono obbligatori: vengono salvati solo se valorizzati.
```

### **7. Password**

```
Campo: Password
Inserisci una password robusta

Requisiti REALI (validati da RegistrationController::register):
✅ Minimo 8 caratteri
✅ Massimo 72 caratteri (limite bcrypt)
✅ Almeno una MAIUSCOLA (A-Z)
✅ Almeno una minuscola (a-z)
✅ Almeno un NUMERO (0-9)
ℹ️ Il SIMBOLO NON è obbligatorio (consigliato ma non richiesto)

Esempio di password VALIDA:
✅ Biblioteca2025
✅ Lib@ria2025!
✅ BiBMario2025

Esempio di password NON valida:
❌ password   (no maiuscole, no numeri)
❌ 12345678   (no lettere)
❌ Lib2       (meno di 8 caratteri)
❌ Biblioteca (no numeri)
```

**Suggerimenti**:
- Usa una frase che ricordi: "Amo i libri di Dumas! 2025" → "AldiD!2025"
- Combina MAIUSCOLE, minuscole, numeri, simboli
- Non usare Nome + Cognome
- Non usare dati pubblici (data nascita, numeri fissi)

### **8. Conferma Password**

```
Campo: Conferma Password (name="password_confirm")
Digita di nuovo la STESSA password

Perché?
→ Per assicurarsi che l'hai scritta senza errori
→ Se non corrisponde a "Password", error=missing_fields
```

### **9. Privacy Policy** (Obbligatorio)

```
☐ Accetto la Privacy Policy (name="privacy_acceptance")

Devi SPUNTARE questa casella per registrarti.
Senza spunta → error=privacy_required.

L'accettazione viene registrata ai fini GDPR:
- timestamp in `data_accettazione_privacy`
- versione policy salvata (`privacy_policy_version`)
- log nella tabella `consent_log` (IP + user-agent, se la tabella esiste)
```

### **10. Clicca [📝 Crea Account]**

```
Se tutto è OK:
  ↓
Account creato con stato 'sospeso' ed email_verificata=0
  ↓
Vengono inviate: email di benvenuto/attesa all'utente +
notifica agli admin (email e notifica in-app)
  ↓
Redirect alla pagina di conferma /register/success
("Controlla la tua email")

Se ci sono errori:
  ↓
Redirect a /register?error=<codice> con messaggio rosso
  ↓
Rileggi le istruzioni più sotto
```

> **Codici `?error=` reali**: `privacy_required`, `missing_fields`,
> `name_too_long`, `email_too_long`, `password_too_long`, `password_too_short`,
> `password_needs_upper_lower_number`, `email_exists`, `db`, `csrf`,
> `session_expired`.
> **Rate limit**: 3 registrazioni / ora (`RateLimitMiddleware(3, 3600, 'register')`).

---

## ✅ Dopo la Registrazione - Cosa Succede?

### **Step 1: Email di Verifica**

```
Apri la tua casella di posta
    ↓
Cerca email da: noreply@biblioteca.it
Oggetto: "Verifica il tuo indirizzo email"
    ↓
Se non la vedi in 5 minuti:
  - Controlla SPAM/JUNK
  - Attendi ancora un po'
  - Ricarica il browser
```

### **Step 2: Clicca il Link di Verifica**

```
L'email contiene un link verso:
/verify-email?token=<token di 48 caratteri esadecimali>

Il token è valido 24 ore (data_token_verifica).

Clicca il link (entro 24 ore!)
    ↓
RegistrationController::verifyEmail imposta email_verificata=1
e azzera token_verifica_email / data_token_verifica
    ↓
Redirect a /login?verified=1
"Email verificata con successo! Account in attesa di approvazione."
    ↓
Ora devi attendere approvazione admin

Token scaduto/invalido → /login?error=token_expired (o invalid_token)
```

### **Step 3: Attendi Approvazione Admin**

```
Una volta verificata la email, l'account resta con stato 'sospeso':
"In attesa di approvazione"

Cosa significa?
→ L'account viene creato con stato='sospeso' e tipo_utente='standard'
→ Un amministratore deve portare lo stato ad 'attivo'
→ Finché lo stato non è 'attivo', il login restituisce
  error=account_pending (o account_suspended)
→ Di solito approva entro 1-2 giorni

Come faccio a sapere quando è approvato?
→ Riceverai un'altra email:
   "Il tuo account è stato approvato!"
```

### **Step 4: Accedi!**

```
Una volta approvato, puoi:
    ↓
Vai a http://localhost:8000/login
    ↓
Inserisci email e password
    ↓
Clicca [🔐 Accedi]
    ↓
✅ Sei dentro! Accesso consentito!
```

---

## ⚠️ Messaggi di Errore e Soluzioni

### **"Email già registrata"**

**Significa**: Qualcuno ha già creato un account con questa email.

**Soluzione**:
```
1. Sei stato TU?
   → Clicca [Hai già un account? Accedi]
   → Usa [Password dimenticata?] se non ricordi password

2. Non sei stato tu (account rubato)?
   → Clicca [Hai già un account? Accedi]
   → Usa [Password dimenticata?]
   → Cambia password
   → Contatta admin se sospetto
```

### **"Compila tutti i campi richiesti"**

**Significa**: Hai saltato uno o più campi.

**Soluzione**:
```
Controlla (tutti obbligatori):
☐ Nome
☐ Cognome
☐ Email (formato valido: xxx@yyy.zz)
☐ Telefono
☐ Indirizzo
☐ Password (8+ caratteri, maiuscola, minuscola, numero)
☐ Conferma Password (identica a Password)
☐ Casella "Accetto la Privacy Policy" (error=privacy_required se manca)

Riempili TUTTI e riprova
```

### **"Password non corrisponde"** (rientra in `error=missing_fields`)

**Significa**: "Password" e "Conferma Password" sono diverse. Lato server
questo caso produce lo stesso codice `missing_fields` (la condizione include
`$password !== $password2`).

**Soluzione**:
```
Controlla:
1. Password è: Lib@ria2025!
2. Conferma Password deve essere IDENTICA: Lib@ria2025!

Se sono diverse:
→ Controlla lettera per lettera (il form non ha "Mostra password")
→ Se non coincidono, correggi

Pro Tip: Copia/incolla da Password a Conferma Password
(Evita errori di digitazione!)
```

### **"Errore di sicurezza"**

**Significa**: Qualcosa non va con i token di sicurezza.

**Soluzione**:
```
1. Ricarica la pagina (F5 / CMD+R)
2. Compila di nuovo il form
3. Riprova
4. Se persiste:
   - Svuota cache del browser
   - Prova browser diverso
   - Contatta admin
```

### **"Errore durante la registrazione"**

**Significa**: Qualcosa è andato storto nel database.

**Soluzione**:
```
1. Prova di nuovo
2. Se succede di nuovo:
   - Usa email leggermente diversa (aggiungi numero)
     Es: mario.rossi2@email.it
   - Usa password diversa
   - Prova più tardi
3. Se problema persiste → Contatta admin
```

---

## 🔐 Requisiti della Password

### **DEVE Contenere**

✅ **Minimo 8 caratteri**
```
Troppe corte: abc1234 (7 caratteri = NO)
OK: Biblioteca2025 (14 caratteri = SÌ)
```

✅ **Almeno una MAIUSCOLA (A-Z)**
```
Senza maiuscole: biblioteca2025! = NO
Con maiuscole: Biblioteca2025! = SÌ
```

✅ **Almeno una minuscola (a-z)**
```
Senza minuscole: BIBLIOTECA2025! = NO
Con minuscole: Biblioteca2025! = SÌ
```

✅ **Almeno un NUMERO (0-9)**
```
Senza numeri: Biblioteca = NO
Con numeri: Biblioteca2025 = SÌ
```

ℹ️ **Simbolo NON obbligatorio**
```
La regex di validazione richiede solo maiuscola + minuscola + numero.
Un simbolo è consigliato ma NON è imposto dal sistema.
Lunghezza massima: 72 caratteri (limite bcrypt).
```

### **Esempi di Password Valide**

✅ Biblioteca2025
✅ Lib@ria2025! (simbolo opzionale)
✅ BiBMario2025
✅ MyL0v3Books

### **Esempi di Password Non Valide**

❌ password (no maiuscole, no numeri)
❌ 12345678 (solo numeri, no lettere)
❌ Mario (no numeri, meno di 8 caratteri)
❌ Lib2 (meno di 8 caratteri)
❌ BibliotecaRossi (no numeri)

---

## 📱 Registrazione su Mobile

**Schermo ridotto**: Il form si adatta automaticamente

```
Mobile (Smartphone):
- Campo per campo verticale (facile da scorrere)
- Tastiera predittiva per nome/cognome
- Tastiera email per campo email
- Tastiera normale per password
- Bottone: Occupa tutta la larghezza
- Layout ottimizzato per touch

Consiglio: Usa password manager sul telefono
per generare password forte automaticamente!
```

---

## 🔒 Sicurezza della Registrazione

### **Come Protegge i Tuoi Dati?**

✅ **HTTPS**: Connessione crittografata
✅ **Password**: Salvata con hash bcrypt (`password_hash`)
✅ **Email Verification**: token 24h, prova che email è tua
✅ **CSRF Token**: Protezione da attacchi
✅ **Rate Limit**: 3 registrazioni / ora per IP
✅ **Admin Approval**: stato 'sospeso' → 'attivo' manuale prima dell'accesso
✅ **GDPR**: consenso privacy con timestamp, versione e log in `consent_log`

### **Consigli di Sicurezza**

✅ **DO**:
- ✅ Usa password FORTE (come spiegato sopra)
- ✅ Usa EMAIL REALE (dove ricevi mail)
- ✅ Usa NOME e COGNOME VERI (per libreria)
- ✅ Leggi TERMINI DI SERVIZIO prima di spuntare
- ✅ Controlla bene prima di inviare

❌ **DON'T**:
- ❌ Usare password troppo semplice
- ❌ Usare email fasulle o di altri
- ❌ Usare nome e cognome finti
- ❌ Condividere il tuo account
- ❌ Cliccare termini senza leggere

---

## 💡 Casi di Uso Tipici

### **Scenario 1: Mi sono appena trasferito, voglio nuovo account**

```
1. Vai a /register
2. Compila con TUOI dati
3. Usa NUOVA email (se disponibile)
4. Crea password forte
5. Spunta termini
6. Clicca [Crea Account]
7. Verifica email
8. Attendi approvazione
9. ✅ Pronto!
```

### **Scenario 2: Non ricordo se ho già un account**

```
1. Vai a /login
2. Prova a "Accedere" con email
3. Se errore "Email o password non corretti":
   → Usa [Password dimenticata?]
4. Se sblocchi → Avevi già account
5. Se non sblocchi → Probabilmente no, registrati
```

### **Scenario 3: Ho un vecchio account ma voglio nuovo**

```
1. Contatta admin
2. Chiedi di disabilitare vecchio account
3. Poi registrati con NEW email
4. OPPURE:
   - Usa stessa email e [Password dimenticata?]
   - Resetta password
```

### **Scenario 4: Mi sono sbagliato email durante registrazione**

```
1. ❌ Non puoi cambiarla facilmente
2. Aspetta approvazione admin
3. Contatta admin
4. Spiega errore
5. Chiedi di cambiare email nel database
6. Oppure cancella account e registrati di nuovo
```

---

## ❓ Domande Frequenti

### **D: Quanto tempo per approvazione?**

⏳ Di solito:
- Entro 24 ore (più comune)
- Entro 48 ore (se busy)
- Fino a 1 settimana (raro, vacanze)

Se passa molto → Contatta admin

### **D: Posso usare due email per due account?**

✅ **Sì!** Puoi avere multiple account:
- Account A: mario.rossi@gmail.com
- Account B: mario.rossi@outlook.com
- Entrambi registrati a TUO nome

Ma perché? Raramente necessario...

### **D: Se mi pentisco, posso cancellare account?**

✅ **Sì!** Puoi chiedere:
- Vai nel profilo (dopo login)
- Clicca "Impostazioni"
- Opzione "Elimina Account"
- Oppure contatta admin

⚠️ ATTENZIONE:
- Cancelli tutto (prestiti, preferiti, storico)
- Non è recuperabile
- Attendi 30 giorni prima di riregistrarti

### **D: Mi serve una password davvero così complessa?**

✅ **Sì!** Per sicurezza:
- Protegge il tuo account
- Evita che hacker indovinino
- Protegge i tuoi dati personali

Usa frase da ricordare → Converti in password:
"Amo i libri di Dumas!" → "AiLdD2025!"

### **D: Perché devo verificare email?**

✅ **3 motivi**:
1. **Prova** che email è vera (non fasulli)
2. **Comunicazione**: Ti raggiungiamo via email
3. **Sicurezza**: Se qualcuno usa email altrui, real owner la verifica

### **D: Se non ricevo email di verifica, cosa faccio?**

```
1. Controlla spam/junk (controlla bene!)
2. Attendi 5 minuti (email lenta)
3. Ricarica browser
4. Controlla bene email inserita:
   - Minuscole/maiuscole?
   - Spazi?
   - Typo (@gmail.com non @gmial.com)?
5. Se ancora niente → Contatta admin
   (Potrebbero rinviare email)
```

### **D: Dopo quanta inattività cancellate account?**

⚠️ Dipende dalla biblioteca:
- Alcuni: Niente (rimane per sempre)
- Alcuni: 2 anni di inattività
- Alcuni: 5 anni

Controlla le regole della TUA biblioteca!

### **D: Posso cambiare email dopo registrazione?**

⚠️ Di solito NO (facilmente):
- Alcune biblioteche permettono nel profilo
- Altre no, devi contattare admin
- Verifica nel TUO profilo

Ecco perché: **Controlla BENE email prima di registrarti!**

### **D: Se mi blocco fuori dall'account che faccio?**

```
1. Clicca [Hai già un account? Accedi]
2. Usa [Password dimenticata?]
3. Resetta password
4. Accedi normalmente
5. Fatto!
```

### **D: La mia email è condivisa con famiglia, problema?**

⚠️ **ATTENZIONE**:
- Account dovrebbe essere PERSONALE solo
- Se lo usa qualcun altro:
  - Fa prestiti a tuo nome
  - Accede ai tuoi preferiti
  - Vede tuoi dati

**Consiglio**: Usa email personale, non condivisa!

---

## 📚 Prossimi Passi

Dopo la registrazione:

- ➡️ **Hai completato verifica email?** [Accedi](./login.md)
- ➡️ **Stai aspettando approvazione?** Attendi email
- ➡️ **Approvato e dentro?** [Vai al Catalogo](./catalogo.md)
- ➡️ **Leggi le guide** [HOME](./home.md)

---

## 🎁 Pro Tips

💡 **Suggerimenti d'oro**:

1. **Password manager**: Usa 1Password, Bitwarden, ecc. per generare password forte
2. **Email unica**: Crea email dedicata solo per biblioteca (se possibile)
3. **Scrivi password**: In un luogo SICURO (quaderno chiuso, password manager)
4. **Non condividere**: Account deve essere TUO solo
5. **Leggi termini**: 2 minuti per capire le regole

---

*Ultima revisione: Giugno 2026 (allineato a RegistrationController)*
*Tempo lettura: 12 minuti*
*Tempo per registrarsi: 3-5 minuti*
