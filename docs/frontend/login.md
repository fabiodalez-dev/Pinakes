# 🔑 Login - Accedi al Tuo Account

> **Accedi qui**: http://localhost:8000/login

La pagina **login** è dove **accedi al tuo account personale** per:
- 📚 Gestire i tuoi prestiti
- ❤️ Salvare libri nei preferiti
- 📅 Visualizzare le tue prenotazioni
- 👤 Modificare il tuo profilo

---

## 🎯 A Chi Serve il Login?

```
✅ Hai già un account registrato
✅ Vuoi visualizzare i tuoi prestiti
✅ Vuoi aggiungere libri ai preferiti
✅ Vuoi fare una richiesta di prestito
✅ Vuoi gestire le tue prenotazioni
```

---

## 📖 Come Funziona il Login

### **La Pagina di Login**

```
┌────────────────────────────────────────┐
│       BIBLIOTECA (Logo)                │
│     "Accedi al tuo account"            │
├────────────────────────────────────────┤
│                                        │
│  ⚠️ MESSAGGIO DI ERRORE (se presente) │
│  (es. "Email o password non corretti") │
│                                        │
│  Email:                                │
│  [mario.rossi@email.it________________]│
│                                        │
│  Password:                             │
│  [****************************]        │
│  [☑️ Mostra password]                 │
│                                        │
│  [🔐 Accedi]                          │
│                                        │
│  Non hai account? [Registrati]         │
│  Password dimenticata? [Recupera]      │
│                                        │
└────────────────────────────────────────┘
```

---

## 🔐 Come Accedere

### **Step by Step**

**1. Vai a http://localhost:8000/login**

Vedrai il form di login.

**2. Inserisci l'email**

```
Campo: Email
Esempio: mario.rossi@email.it
⚠️ IMPORTANTE: Deve essere l'email con cui ti sei registrato
```

**3. Inserisci la password**

```
Campo: Password
⚠️ IMPORTANTE: I punti rimpiazzano i caratteri (per privacy)
```

**4. (Opzionale) Mostra password**

```
☑️ Mostra password
Clicca se vuoi VEDERE la password mentre digiti
(Utile se hai dubbi di aver scritto tutto bene)
```

**5. Clicca [🔐 Accedi]**

```
Se credenziali corrette:
  ↓
Accedi al tuo account
  ↓
Vieni reindirizzato alla dashboard o pagina che stavi visitando

Se credenziali ERRATE:
  ↓
Vedi messaggio di errore rosso
  ↓
Puoi ritentare
```

---

## ⚠️ Messaggi di Errore e Soluzioni

### **"Email o password non corretti"**

**Significa**: Email e/o password non riconosciute nel database.

**Cosa fare**:
1. ✅ Verifica che l'email sia CORRETTA (niente spazi, maiuscole, typo)
2. ✅ Verifica che la password sia CORRETTA
3. ✅ Assicurati che CAPS LOCK non sia attivo
4. ✅ Se non ricordi la password → [Recupera Password](#-recuperare-la-password)
5. ✅ Se non hai account → [Registrati](./register.md)

**Quando capita**:
- Scrivi l'email sbagliata
- Scrivi la password sbagliata
- Non hai ancora un account registrato

---

### **"Email non verificata"**

**Significa**: Hai un account ma non hai confermato la tua email.

**Cosa fare**:
```
1. Apri il tuo programma di posta (Gmail, Outlook, ecc.)
2. Cerca l'email da: noreply@biblioteca.it
   Oggetto: "Verifica il tuo indirizzo email"
3. Clicca il link blu "Verifica Email"
   (Oppure copia il link e incollalo nel browser)
4. Vedrai: "Email verificata con successo!"
5. Ritorna a login e accedi normalmente
```

**Non trovi l'email?**
- Controlla la cartella SPAM/JUNK
- Attendi 5 minuti (può essere lenta)
- Chiedi all'admin di inviarla di nuovo

---

### **"Il tuo account è in attesa di approvazione"**

**Significa**: Ti sei registrato, ma un amministratore deve ancora approvare il tuo account.

**Cosa fare**:
```
1. Attendi: L'admin approverà l'account entro 1-2 giorni
2. Riceverai un'email quando sarà approvato
3. Poi potrai accedere normalmente
```

**Quanto aspetto?**
- Di solito entro 24 ore
- Dipende dalla velocità dell'admin
- Se passa molto tempo, contatta la biblioteca

---

### **"Il tuo account è stato sospeso"**

**Significa**: Ci sono problemi con il tuo account (es. troppi ritardi, comportamento sospetto).

**Cosa fare**:
```
1. ❌ Non puoi accedere fino a risoluzione
2. Contatta la biblioteca
3. Spiega il problema
4. Chiedi di sbloccare l'account
5. Una volta sbloccato, accedi normalmente
```

**Cause comuni**:
- Troppi prestiti non restituiti
- Ritardi ripetuti
- Comportamento irregolare
- Pagamenti non versati

---

### **"Sessione scaduta"**

**Significa**: Il tuo "biglietto" di login ha scaduto (timeout).

**Cosa fare**:
```
1. Ricarica la pagina (F5 o CMD+R)
2. Accedi di nuovo
3. Fatto!
```

**Perché succede**:
- Non usi l'account per 2-3 ore
- Chiudi il browser senza logout
- Token di sicurezza scade

---

### **"Errore di sicurezza"**

**Significa**: Qualcosa è andato storto (CSRF token mancante, sessione violata, ecc.)

**Cosa fare**:
```
1. Ricarica la pagina (F5 o CMD+R)
2. Prova di nuovo
3. Se persiste:
   - Pulisci cache del browser (CTRL+SHIFT+DEL)
   - Prova con un browser diverso
   - Contatta admin
```

---

## 🔓 Recuperare la Password

**Se dimentichi la password**:

```
1. Vai a http://localhost:8000/login
2. Clicca [Password dimenticata?]
3. Inserisci la tua email
4. Ricevi email con link di recupero
5. Clicca il link (entro 24 ore!)
6. Scegli una nuova password
7. Accedi con la nuova password
```

**La email di recupero include**:
- Link unico per resettare password
- Valido per 24 ore soltanto
- Usa link una sola volta (poi scade)
- Se perdi il link, richiedi un altro

---

## 📱 Login su Mobile

**Schermo ridotto**: Il form si adatta automaticamente

```
Mobile (Smartphone):
- Email: Tastiera predittiva (utile!)
- Password: Tastiera normale ma nascosta
- Bottone: Occupa tutta la larghezza
- Facile da toccare

Dark Mode:
- Se hai dark mode attivo, il form si scurisce
- Tema automatico dal tuo dispositivo
```

---

## 🍪 Cookie e Sessione

### **Cosa Succede Dopo il Login?**

```
Clicchi "Accedi"
    ↓
Il browser riceve un COOKIE di sessione
    ↓
Questo cookie viene salvato sul TUO dispositivo
    ↓
Ogni volta che visiti una pagina:
  - Il browser invia il cookie
  - Il server riconosce chi sei
  - Ti mostra il TUO contenuto personale
```

### **Durata della Sessione**

- **Di default**: 2-3 ore di inattività
- **Poi**: Sessione scade, devi riaccedere
- **Se clicchi Logout**: Sessione termina subito

### **Dispositivi Diversi**

```
Se accedi da:
- Computer desktop
- Telefono
- Tablet

Ogni dispositivo ha la PROPRIA sessione separata.
Non devi logout da tutti i dispositivi.
```

---

## 🔒 Sicurezza del Login

### **Come Protegge il Tuo Account?**

✅ **HTTPS**: Connessione crittografata (lucchetto 🔒)
✅ **Password**: Salvata con hash (non in chiaro)
✅ **CSRF Token**: Protezione da attacchi
✅ **Rate Limiting**: Blocca tentativi ripetuti
✅ **Email Verification**: Conferma che email è tua
✅ **Session Timeout**: Logout automatico dopo inattività

### **Consigli di Sicurezza**

✅ **DO**:
- ✅ Usa password FORTE (maiuscole, numeri, simboli)
- ✅ Logout quando finisci (soprattutto da computer pubblico)
- ✅ Proteggi la tua email (non dirla a nessuno)
- ✅ Usa browser AGGIORNATO
- ✅ Controlla HTTPS nella barra indirizzi

❌ **DON'T**:
- ❌ Salvare password in note pubbliche
- ❌ Condividere il tuo account
- ❌ Usare stesso password per molti siti
- ❌ Accedere da WiFi pubblico NON sicuro
- ❌ Lasciare account aperto su computer pubblico

---

## 💡 Casi di Uso Tipici

### **Scenario 1: Voglio solo cercare libri (senza login)**

```
1. Puoi visitare:
   - Home page (/)
   - Catalogo (/catalogo)
   - Dettagli libro (/libro/{id})

2. ❌ NON puoi:
   - Aggiungere ai preferiti
   - Fare prestiti
   - Vedere prenotazioni

3. Se provi → Ti reindirizza a LOGIN
```

### **Scenario 2: Mi sono dimenticato la password**

```
1. Vai a /login
2. Clicca [Password dimenticata?]
3. Inserisci email
4. Ricevi email di recupero
5. Clicca link nell'email
6. Scegli nuova password
7. Accedi con nuova password
```

### **Scenario 3: Non mi ricordo se ho un account**

```
1. Vai a /login
2. Prova con la tua email
3. Se ti dice "Email o password non corretti":
   → Prova a registrarti (potrebbe essere una email diversa)
4. Se non ti ricordi l'email:
   → Contatta la biblioteca per verificare
```

### **Scenario 4: Mi trovo in un computer pubblico**

```
1. ✅ Accedi normalmente
2. ✅ Fai quello che devi
3. ❌ NON cliccare "Ricorda password"
4. ✅ SEMPRE cliccare LOGOUT quando finisci
5. ✅ Chiudi il browser completamente
```

---

## ❓ Domande Frequenti

### **D: Quanti tentativi di login posso fare?**

✅ Puoi fare più tentativi, ma se troppi ravvicinati:
- Dopo 5-10 tentativi falliti → Blocco temporaneo (5-15 minuti)
- Questo protegge da hacker che provano password random
- Aspetta e riprova con password corretta

### **D: Se perdo il dispositivo, rimane connesso?**

✅ Dipende:
- Se il dispositivo ha i cookie salvati → Rimane connesso
- Se qualcuno lo trova → Può accedere al tuo account!
- **SOLUZIONE**: Contatta admin per sloggarti da remoto

### **D: Posso loggarmi su 2 dispositivi contemporaneamente?**

✅ **Sì!** Puoi:
- Accedere dal computer
- Accedere dallo smartphone
- Accedere dal tablet
- Tutti contemporaneamente (sessioni separate)

### **D: La password è visibile mentre digito?**

❌ No, per default è nascosta (puntini). Ma puoi:
- Cliccare [☑️ Mostra password] per vederla
- Utile se non sei sicuro di quello che digiti

### **D: Se clicco "Password dimenticata" per sbaglio?**

✅ No problem! Semplicemente:
- Ricevi email di recupero
- Non devi fare niente
- Puoi ignorare l'email
- Dopo 24 ore scade

### **D: Uso la stessa email per molti siti, quale email uso?**

✅ Usa la **email con cui ti sei registrato** a Biblioteca:
- Potrebbe essere email personale, scolastica, di lavoro
- Se non ricordi quale → Contatta la biblioteca

### **D: Se cambio la mia email, devo rifare login?**

⚠️ Dipende dalla configurazione:
- Alcune biblioteche permettono cambio email nel profilo
- Altre no
- Se cambi email → Potresti non riuscire più ad accedere
- Contatta admin prima di cambiarla

### **D: Quanto è sicuro il login?**

✅ **Molto sicuro!**:
- HTTPS = crittografia
- Password = salvata con hash
- Email verification = provato che email è tua
- CSRF protection = impossibile attacchi cross-site
- Rate limiting = blocca brute force

### **D: Se qualcuno accede al mio account, cosa faccio?**

🔴 **URGENTE**:
1. Cambia password SUBITO ([Password dimenticata?])
2. Contatta la biblioteca e avvisa
3. Chiedi di controllare i prestiti (potrebbero aver fatto danni)
4. Chiedi di fare audit della sicurezza

### **D: Rimango loggato se chiudo il browser?**

⚠️ Dipende:
- Se chiudi solo la tab → Rimani loggato in altre tab
- Se chiudi TUTTO il browser → Rimani loggato (cookie salvato)
- Solo il LOGOUT manuale ti disconnette

Consiglio: Clicca sempre LOGOUT quando finisci, soprattutto da computer pubblico!

---

## 🚨 Cosa Fare Se...

### **...Non Riesco ad Accedere**

```
1. Controlla email:
   - Minuscole/maiuscole?
   - Spazi all'inizio/fine?
   - Typo?

2. Controlla password:
   - CAPS LOCK attivo?
   - Dito sbagliato sulla tastiera?
   - Cambiata di recente?

3. Se non ricordi password:
   - Clicca [Password dimenticata?]
   - Segui i step

4. Se problemi persistono:
   - Pulisci cache browser
   - Prova browser diverso
   - Contatta admin
```

### **...La Pagina di Login Non Carica**

```
1. Controlla internet (WiFi attiva?)
2. Ricarica pagina (F5 / CMD+R)
3. Controlla URL: http://localhost:8000/login ✓
4. Prova browser diverso
5. Svuota cache del browser
6. Se ancora non va → Contatta admin
```

### **...Mi Esce "Errore di Sicurezza"**

```
1. Ricarica pagina (F5)
2. Accedi di nuovo
3. Se persiste:
   - CTRL+SHIFT+DEL (Windows) o CMD+SHIFT+DEL (Mac)
   - Seleziona "Cookie e cache"
   - Clicca "Svuota"
4. Riprova
5. Se ancora no → Contatta admin
```

---

## 📚 Prossimi Passi

Dopo aver effettuato il login, puoi:

- ➡️ **Visita la tua dashboard** - Profilo, Prestiti, Prenotazioni
- ➡️ **Vai al Catalogo** [Catalogo](./catalogo.md) - Cerca libri
- ➡️ **Gestisci Preferiti** [Wishlist](./wishlist.md) - I tuoi libri preferiti
- ➡️ **Vedi Prestiti** [Prenotazioni](./prenotazioni.md) - Cosa hai in prestito
- ➡️ **Non hai account?** [Registrati](./register.md)

---

## 🎁 Pro Tips

💡 **Suggerimenti d'oro**:

1. **Salva il link**: Aggiungi /login ai preferiti del browser
2. **Ricorda email**: La stessa email per tutte le volte
3. **Password forte**: Usa maiuscole, numeri, simboli (es: Lib@2025!)
4. **Logout pubblico**: Sempre logout da computer pubblico/biblioteca
5. **Cookie**: Non accettare prompt "Accetti cookie" se non sei sicuro

---

*Ultima lettura: 19 Ottobre 2025*
*Tempo lettura: 10 minuti*
*Tempo per accedere: 30 secondi*
