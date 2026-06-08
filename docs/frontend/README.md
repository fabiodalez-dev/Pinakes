# 🌐 Documentazione Frontend

> **Guida per gli utenti finali**: Come navigare le pagine pubbliche della biblioteca online.

Questa cartella contiene la documentazione **dal punto di vista dell'utente finale** delle pagine pubbliche di Pinakes.

---

## 📖 Le 7 Pagine Principali

### 1. 🔑 [**Login - Accedi**](./login.md) ✨
**URL**: http://localhost:8000/login

La pagina dove accedi al tuo account con:
- Form di login semplice (email + password)
- Casella "Ricordami" (token persistente, non "Mostra password")
- Recupero password (`/forgot-password` → `/reset-password`)
- Messaggi di errore via `?error=` (invalid_credentials, account_pending, ecc.)
- Redirect post-login: admin/staff → /admin/dashboard, altri → /user/dashboard
- Sicurezza: CSRF (sessione + cookie `csrf_login`), rate limit 5/5min

**Tempo lettura**: 10 minuti | **Tempo azione**: 30 secondi

---

### 2. 📝 [**Registrazione - Crea Account**](./register.md) ✨
**URL**: http://localhost:8000/register

La pagina dove crei un nuovo account con:
- Campi obbligatori: nome, cognome, email, **telefono**, **indirizzo**, password, conferma password, privacy
- Campi opzionali: data di nascita, sesso (M/F/Altro), codice fiscale
- Requisiti password: 8-72 caratteri, maiuscola + minuscola + numero (simbolo NON richiesto)
- Email verification (token valido 24h) → `/register/success`
- Admin approval: account creato `stato='sospeso'`, attivato manualmente
- Consenso privacy GDPR registrato (timestamp + `consent_log`)

**Tempo lettura**: 12 minuti | **Tempo azione**: 3-5 minuti

---

### 3. 🏠 [**Home Page**](./home.md)
**URL**: http://localhost:8000/

La pagina iniziale della biblioteca con:
- Hero section con ricerca
- Sezione "Perché Scegliere"
- Ultimi libri aggiunti
- Categorie principali
- Call to action finale

**Tempo lettura**: 8 minuti | **Tempo configurazione**: 5 minuti

---

### 4. 📚 [**Catalogo Completo**](./catalogo.md)
**URL**: http://localhost:8000/catalogo

La pagina di ricerca e filtri avanzati con:
- Barra di ricerca istantanea
- Filtri per categoria, genere, editore, disponibilità, anno
- Griglia di libri con paginazione
- Ordinamento flessibile
- Risultati dinamici

**Tempo lettura**: 10 minuti | **Tempo utilizzo**: dipende da te

---

### 5. 📖 [**Scheda Libro**](./scheda_libro.md)
**URL**: http://localhost:8000/{author-slug}/{book-slug}/{ID}

La pagina dettagli di un libro singolo con:
- Hero section con copertina
- Informazioni complete del libro
- Bottone per richiesta di prestito
- Aggiungi ai preferiti
- Descrizione e dettagli tecnici
- Libri correlati
- Condivisione su social

**Tempo lettura**: 10 minuti | **Tempo azione**: 1-2 minuti per prestito

---

### 6. ❤️ [**Wishlist - I Miei Preferiti**](./wishlist.md)
**URL**: http://localhost:8000/wishlist (richiede login)

La tua lista personale di libri salvati con:
- Visualizzazione di tutti i tuoi preferiti
- Statistiche (totali, disponibili, in attesa)
- Filtro di ricerca rapida
- Stato di disponibilità per ogni libro
- Possibilità di rimuovere libri
- Notifiche quando tornano disponibili

**Tempo lettura**: 8 minuti | **Tempo azione**: 2 secondi per aggiungere

---

### 7. 📅 [**Prenotazioni - Gestione Prestiti**](./prenotazioni.md)
**URL**: http://localhost:8000/prenotazioni (richiede login)

Il tuo centro di controllo per i prestiti con 3 sezioni:
- **Prestiti in corso**: Libri che hai ADESSO
- **Prenotazioni attive**: Libri che hai richiesto/prenotato
- **Storico prestiti**: Tutti i tuoi prestiti passati

**Contenuti**:
- Badge di scadenza (normale/ritardo)
- Posizione in coda per prenotazioni
- Annulla prenotazioni
- Alert per prestiti in ritardo

**Tempo lettura**: 12 minuti | **Tempo azione**: 30 secondi per annullare

---

## 🗺️ Mappa di Navigazione

```
Login (/login — route locale-aware)
├─ Accedi → admin/staff: /admin/dashboard · altri: /user/dashboard
├─ Password dimenticata? → /forgot-password → /reset-password?token=
├─ Nuovo utente? → /register
└─ Rate limit: 5 tentativi / 5 min

Register (/register — route locale-aware)
├─ Compila form → Nome, Cognome, Email, Telefono, Indirizzo, Password (+ opzionali)
├─ Accetta Privacy Policy → Spunta casella (obbligatoria)
├─ Clicca "Crea Account" → /register/success + email di verifica (token 24h)
├─ Verifica email → /verify-email?token= → /login?verified=1
├─ Attendi admin approval (stato sospeso → attivo)
└─ Accedi → /login (ora puoi loggare!)

Home (/)
├─ Hero Search → /catalogo?q=ricerca
├─ Quick Links → /catalogo
├─ Latest Books → Clicca Libro → /{author-slug}/{book-slug}/{id}
├─ Genre Carousel → Clicca Libro → /{author-slug}/{book-slug}/{id}
├─ Events (se cms.events_page_enabled) → /events/{slug}
└─ CTA Button → /catalogo

Catalogo (/catalogo)
├─ Filtri → Aggiorna risultati dinamicamente
├─ Ricerca → Istantanea mentre digiti
├─ Libri Grid → Clicca Libro → /{author-slug}/{book-slug}/{id}
├─ Paginazione → Altre pagine risultati
└─ Sidebar → Ogni filtro clickabile

Scheda Libro (/{author-slug}/{book-slug}/{id})
├─ Richiedi Prestito → Popup calendario → /prenotazioni
├─ Aggiungi Preferiti ❤️ → /wishlist (se loggato)
├─ Clicca Autore → /autore/Nome-Autore
├─ Clicca Editore → /editore/Nome-Editore
├─ Clicca Genere/Categoria → /catalogo?genere=X
├─ Libri Correlati → Clicca → Va al libro
└─ Condividi → Social / Copy Link

Wishlist (/wishlist - Login richiesto, route locale-aware)
├─ Filtro ricerca → Filtra per titolo/stato (client-side)
├─ Clicca Libro → /{author-slug}/{book-slug}/{id}
├─ Rimuovi dai preferiti 🗑️ → POST /api/user/wishlist/toggle
├─ "Esplora Catalogo" → /catalogo
└─ "Prenotazioni" → /prenotazioni

Prenotazioni (/prenotazioni - Login richiesto)
├─ Prestiti in Corso → Vedi scadenze
│  ├─ Clicca Libro → /{author-slug}/{book-slug}/{id}
│  └─ Vedi Badge scadenza (verde/rosso)
├─ Prenotazioni Attive → Vedi posizione coda
│  ├─ Clicca Libro → /{author-slug}/{book-slug}/{id}
│  ├─ Annulla Prenotazione → Rimuovi dalla coda
│  └─ Vedi Posizione in coda (#1, #2, ecc.)
└─ Storico Prestiti → Vedi prestiti passati
   ├─ Clicca Libro → /{author-slug}/{book-slug}/{id}
   └─ Vedi stato (Restituito, In ritardo, Perso)
```

---

## 👥 Chi Dovrebbe Leggere Cosa?

### **Utente Finale Nuovo**
1. Leggi [**Home**](./home.md) (5 min)
2. Leggi [**Catalogo**](./catalogo.md) (10 min)
3. Leggi [**Scheda Libro**](./scheda_libro.md) (10 min)
4. **Pronto!** Puoi usare il sistema 🚀

### **Utente che Cerca un Libro Specifico**
→ Vai diretto a [**Catalogo**](./catalogo.md) e usa la ricerca o i filtri

### **Utente che Vuole Fare un Prestito**
→ Trova il libro in [**Catalogo**](./catalogo.md) → Vai a [**Scheda Libro**](./scheda_libro.md) → Clicca "Richiedi Prestito"

### **Admin che Personalizza la Home**
→ Leggi la sezione "Come Personalizzare" in [**Home**](./home.md)

---

## 🔍 Ricerca Veloce

| Domanda | Risposta | Link |
|---------|----------|------|
| Come faccio a cercare un libro? | Usa la barra di ricerca | [Catalogo](./catalogo.md#-barra-di-ricerca-in-alto) |
| Come faccio un prestito? | Vai a scheda libro e clicca bottone | [Scheda Libro](./scheda_libro.md#-bottoni-azione-action-buttons) |
| Come filtro per genere? | Catalogo → Filtri a sinistra | [Catalogo](./catalogo.md#-generi-e-sottogeneri) |
| Qual è il codice del libro? | Nella scheda, "Collocazione" | [Scheda Libro](./scheda_libro.md#-sidebar-colonna-destra) |
| Come aggiungo ai preferiti? | Clicca ❤️ sulla scheda | [Scheda Libro](./scheda_libro.md#-bottoni-azione-action-buttons) |
| Posso cercare per ISBN? | Sì, copia ISBN nella ricerca | [Catalogo](./catalogo.md#-barra-di-ricerca-in-alto) |
| Come cambio ordine risultati? | Catalogo → Dropdown "Ordinamento" | [Catalogo](./catalogo.md#-ordinamento) |

---

## 📱 Guide per Dispositivo

### **Mobile (Smartphone)**
- ✅ Tutte le pagine sono completamente responsive
- ✅ Testo leggibile senza zoom
- ✅ Bottoni facili da toccare
- ✅ Performanti anche su connessioni lente

**Suggerimento**: Se i filtri occupano troppo spazio, usa la ricerca per trovare libri.

### **Tablet**
- ✅ Griglia 2-3 colonne (non solo 1)
- ✅ Filtri sempre visibili a sinistra
- ✅ Ottimale per sfogliare

### **Desktop**
- ✅ 4 colonne nella griglia
- ✅ Filtri sidebar comodissimi
- ✅ Esperienza massima

---

## 🎨 Personalizzazione della Home

**Se sei l'admin** e vuoi cambiare testi, immagini, colori sulla home:

→ Leggi [**Home → "Come Personalizzare"**](./home.md#%EF%B8%8F-come-personalizzare-la-home)

Procedimento semplice: **Settings → CMS → Modifica e Salva** ✅

---

## 🔐 Aspetti di Sicurezza

Tutte le pagine frontend:
- ✅ **HTTPS Ready** - Crittografia abilitata
- ✅ **Prepared Statements** - Protezione da SQL injection
- ✅ **XSS Prevention** - Escaping di input
- ✅ **CSRF Tokens** - Per le azioni (prestito, preferiti)
- ✅ **Session-based Auth** - Login sicuro

**Non condividere mai**:
- ❌ La tua email di account
- ❌ La tua password
- ✅ Puoi condividere i link dei libri - sono pubblici!

---

## 📊 Statistiche e Performance

**Velocità media di caricamento**:
- Home: ~2-3 secondi
- Catalogo: ~1-2 secondi
- Scheda Libro: ~1-2 secondi
- Ricerca istantanea: <100ms (dopo debounce)

**Numero risultati**:
- Catalogo: 12 libri per pagina
- Paginazione: Dinamica fino a 100+ pagine

**Browser supportati**:
- ✅ Chrome/Chromium 90+
- ✅ Firefox 88+
- ✅ Safari 14+
- ✅ Edge 90+

---

## ❓ FAQ Generale

### **D: Le pagine sono pubbliche? Chiunque le può vedere?**

✅ **Sì. Home, Catalogo, Scheda Libro, Eventi e Login/Registrazione sono pubblici.**
Richiedono login (gestito da `AuthMiddleware` con ruoli `admin/staff/standard/premium`) solo:
- richiesta prestito (`POST /user/loan`) e prenotazione (`POST /user/reserve`)
- wishlist (`/wishlist`, `/api/user/wishlist/*`)
- gestione prenotazioni (`/prenotazioni`) e profilo

> Non esiste una "modalità privata" che blocchi l'intero catalogo dietro login:
> il gating è per singola azione/pagina riservata, non globale.

### **D: Perché la ricerca a volte è lenta?**

⏳ Probabilmente sta caricando molti risultati. Aspetta 1-2 secondi o raffinain ricerca con filtri.

### **D: Come accedo al mio profilo prestiti?**

🔑 Devi **fare login** (bottone in alto a destra) → Profilo → Prestiti. Questa è un'altra pagina non coperta da questa documentazione.

### **D: Posso usare le pagine senza JavaScript?**

❌ No, il sito usa JavaScript per caricamenti dinamici. JavaScript deve essere abilitato.

### **D: Posso scaricare il catalogo completo?**

❌ Non direttamente dal frontend. Contatta l'admin per esportare dati.

### **D: Funziona offline?**

❌ No, il sito richiede una connessione internet attiva.

---

## 🚀 Workflow Completo da Principiante

```
1. Accedi a http://localhost:8000/ (HOME)
   ↓ Vedi home page con hero

2. Leggi la guida [Home](./home.md)
   ↓ Impari cosa contiene

3. Clicca "Sfoglia Catalogo" o usa ricerca hero
   ↓ Vai a CATALOGO

4. Leggi la guida [Catalogo](./catalogo.md)
   ↓ Impari i filtri e la ricerca

5. Cerca qualcosa (es. "fantasy")
   ↓ Vedrai risultati

6. Clicca un libro che ti piace
   ↓ Vai a SCHEDA LIBRO

7. Leggi la guida [Scheda Libro](./scheda_libro.md)
   ↓ Impari come fare un prestito

8. Se vuoi il libro, clicca "Richiedi Prestito"
   ↓ Scegli le date
   ↓ Invia richiesta
   ↓ Ricevi email di conferma

✅ FATTO! Sei un esperto di Pinakes!
```

---

## 📚 Documentazione Correlata

- 📖 [Guida Principale (README)](../README.md) - Indice completo di TUTTE le guide
- ⚙️ [Impostazioni](../settings.md) - Come personalizzare il sistema
- 📧 [Email e Notifiche](../email.md) - Come configurare le email
- 🔧 [Installazione](../installation.MD) - Setup iniziale
- 📊 [API Reference](../api.MD) - Per sviluppatori

---

## 🎯 Checklist Utente

- [ ] Ho letto [Home](./home.md)
- [ ] Ho letto [Catalogo](./catalogo.md)
- [ ] Ho letto [Scheda Libro](./scheda_libro.md)
- [ ] So come cercare libri
- [ ] So come filtrare
- [ ] So come fare un prestito
- [ ] So come aggiungere ai preferiti
- [ ] Sono un esperto! 🎉

---

## 💬 Feedback

Se trovi problemi o hai suggerimenti su queste pagine:
1. **Controlla la FAQ** in ogni guida
2. **Chiedi all'admin** di Pinakes
3. **Segnala bug** al team di sviluppo

---

## 📋 Versione e Changelog

**Versione Frontend Docs**: 1.1.0
**Data**: Giugno 2026 (revisione cluster login/register/home/wishlist allineata al codice)
**Stato**: ✅ Aggiornato a AuthController / RegistrationController / UserWishlistController / FrontendController

**Cosa contiene**:
- ✅ 3 guide complete (Home, Catalogo, Scheda Libro)
- ✅ Layout per mobile e desktop
- ✅ Workflow completo dal principiante
- ✅ FAQ per ogni pagina
- ✅ Link interni per navigazione

---

## 🎉 Benvenuto!

Questa documentazione frontend copre **TUTTE le pagine pubbliche** della biblioteca. Sei pronto a esplorare il catalogo e fare il tuo primo prestito? 🚀

**Inizia da qui**: [👉 Vai alla Home](./home.md)

---

*Documentazione Frontend - Pinakes*
*Ultimo aggiornamento: Giugno 2026*
*Per tutti gli utenti - User-friendly first! 💙*
