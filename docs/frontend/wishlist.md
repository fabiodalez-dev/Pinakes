# ❤️ Wishlist - I Miei Preferiti

> **Accedi qui**: http://localhost:8000/wishlist (devi essere loggato)

La **wishlist** (lista dei preferiti) è il tuo **personale elenco** di libri che ti interessano e vuoi tenere d'occhio. Quando aggiungi un libro ai preferiti, riceverai notifiche quando torna disponibile!

---

## 🎯 A Cosa Serve la Wishlist?

```
✅ Salvare libri che ti interessano
✅ Tracciare il loro stato di disponibilità
✅ Ricevere notifiche quando tornano disponibili
✅ Accedere velocemente ai tuoi libri preferiti
✅ Gestire la tua lista (aggiungere/rimuovere)
```

---

## 📖 Come Funziona

### **Aggiungere un Libro ai Preferiti**

**Dove puoi farlo**:
1. **Dalla scheda libro** (pagina dettagli)
2. **Dal catalogo** (sulla card del libro)

**Procedimento**:
```
Accedi a una pagina libro
     ↓
Clicca il bottone ❤️ "Aggiungi ai Preferiti"
     ↓
Il bottone diventa ROSSO (confermato!)
     ↓
Il libro è nella tua wishlist
```

**Devi essere loggato**: Se non sei loggato, vedrai "Accedi per aggiungere ai Preferiti" → Clicca → Login.

### **Rimuovere un Libro dai Preferiti**

**Opzione 1**: Dalla pagina libro
```
Vai alla scheda libro
     ↓
Il bottone ❤️ è ROSSO (significa già nei preferiti)
     ↓
Clicca di nuovo
     ↓
Il bottone diventa GRIGIO (rimosso!)
```

**Opzione 2**: Dalla pagina wishlist
```
Vai a /wishlist (route locale-aware)
     ↓
Trovi il libro nella lista
     ↓
Clicca il bottone 🗑️ (trash)
     ↓
Dialog SweetAlert "Rimuovere dalla wishlist?" → "Sì, rimuovi"
     ↓
POST /api/user/wishlist/toggle (con csrf_token + libro_id)
     ↓
La card viene rimossa in-place (la pagina si ricarica solo
quando la wishlist resta vuota)
```

> **Tecnico**: aggiunta e rimozione passano dallo stesso endpoint
> `POST /api/user/wishlist/toggle` (`UserWishlistController::toggle`), che fa
> toggle atomico (DELETE; se non esisteva, INSERT IGNORE) e risponde
> `{"favorite": true|false}`. Lo stato per un singolo libro si interroga con
> `GET /api/user/wishlist/status?libro_id=...`.

---

## 📋 La Pagina Wishlist (/wishlist)

### **Layout Principale**

```
┌───────────────────────────────────────────────────────┐
│              HERO SECTION                             │
│             "I tuoi preferiti"                        │
│  Una panoramica dei libri che hai salvato             │
└───────────────────────────────────────────────────────┘

┌───────────────────────────────────────────────────────┐
│            RIEPILOGO WISHLIST CARD                    │
│  ┌─────────────────────┐  ┌────────────────────────┐  │
│  │  "Gestisci i tuoi"  │  │  ❤️ X preferiti       │  │
│  │  "titoli preferiti" │  │  ⚡ X disponibili ora  │  │
│  └─────────────────────┘  └────────────────────────┘  │
│                          [Esplora Catalogo] [Prenotazioni]
└───────────────────────────────────────────────────────┘

┌───────────────────────────────────────────────────────┐
│           FILTRO DI RICERCA                           │
│  Ricerca rapida: [________________]  [Pulisci filtro] │
│  Hint: Cerca per titolo o stato (es. "disponibile")  │
└───────────────────────────────────────────────────────┘

┌───────────────────────────────────────────────────────┐
│                GRIGLIA LIBRI                          │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐ │
│  │ Copertina    │  │ Copertina    │  │ Copertina    │ │
│  │              │  │              │  │              │ │
│  │ Titolo       │  │ Titolo       │  │ Titolo       │ │
│  │ 🟢 Disp. ora │  │ ⏰ In attesa  │  │ 🟢 Disp. ora │ │
│  │ Copie: 3     │  │ Copie: 0     │  │ Copie: 1     │ │
│  │              │  │              │  │              │ │
│  │ [Dettagli]   │  │ [Dettagli]   │  │ [Dettagli]   │ │
│  │ [🗑️]        │  │ [🗑️]        │  │ [🗑️]        │ │
│  └──────────────┘  └──────────────┘  └──────────────┘ │
└───────────────────────────────────────────────────────┘
```

---

## 📊 Riepilogo Statistiche

**In cima alla wishlist (card riepilogo) vedi 2 badge**:

| Badge | Significato | Esempio |
|-------|-------------|---------|
| **❤️ X preferiti** | Quanti libri totali nella wishlist (`#wishlist-total-count`) | "❤️ 12 preferiti" |
| **⚡ X disponibili ora** | Quanti hanno una copia fisica realmente disponibile (`#wishlist-available-count`) | "⚡ 5 disponibili ora" |

> **Nota**: esiste anche un contatore "in attesa" (`#wishlist-pending-count`,
> = totali − disponibili) aggiornato via JavaScript, ma **non** è renderizzato
> come badge visibile nella barra riepilogo standard.

**Disponibilità reale**: il conteggio "disponibili ora" usa
`NotificationService::hasActualAvailableCopy()`, che considera copie fisiche,
prenotazioni e prestiti in corso — non il semplice `copie_disponibili`.

**Aggiornamento**: i numeri si aggiornano via JS quando rimuovi un libro o filtri.

---

## 🔍 Filtro di Ricerca

### **Come Usarlo**

**Campo ricerca**:
```
Ricerca rapida: [_________________]
                 Scrivi qui

Suggerimenti: Titolo o stato
Esempio: "harry" → Mostra solo "Harry Potter..."
Esempio: "disponibile" → Mostra solo libri disponibili ORA
Esempio: "attesa" → Mostra solo libri in attesa
```

### **Come Funziona**

```
Digiti "harry"
     ↓
Sistema filtra in TEMPO REALE
     ↓
Mostra solo libri con "harry" nel titolo
     ↓
Se nessuno corrisponde → "Nessun titolo corrisponde al filtro corrente"
```

### **Pulisci Filtro**

```
Clicca [Pulisci filtro]
     ↓
Il campo ricerca si svuota
     ↓
Vedi di nuovo TUTTI i tuoi preferiti
```

---

## 📖 Card di un Libro in Wishlist

**Ogni libro è una "card"** con:

```
┌────────────────────────────────────┐
│        COPERTINA                   │  ← Clicca = vai a dettagli
│     (240px alta)                   │
│                                    │
├────────────────────────────────────┤
│                                    │
│ 🟢 Disponibile ora                 │  ← Badge colore (green/orange)
│ (o ⏰ In attesa)                   │
│                                    │
│ Titolo del Libro Bellissimo        │  ← Titolo in grassetto
│                                    │
│ Copie disponibili: 3               │  ← Numero copie libere
│                                    │
│ [Dettagli]        [🗑️ Rimuovi]    │  ← Bottoni azione
└────────────────────────────────────┘
```

### **Badge di Stato**

| Badge | Colore | Significato |
|-------|--------|-------------|
| **🟢 Disponibile ora** | Verde | `has_actual_copy = true` (copia fisica libera) |
| **⏰ In attesa** | Arancione | Nessuna copia attualmente disponibile |

**Data di prossima disponibilità**: per i libri "In attesa", se il sistema
riesce a stimarla, la card mostra **"Disponibile dal: gg/mm/aaaa"**
(`NotificationService::getNextAvailabilityDate()`). Altrimenti mostra
"Nessuna copia attualmente disponibile".

---

## 🔘 Bottoni sulla Card

### **1. Dettagli**

**Clicca** → Vai alla pagina completa del libro
- Vedi descrizione
- Vedi tutti i dettagli (ISBN, pagine, ecc.)
- Puoi fare una richiesta di prestito
- Puoi rimuovere dai preferiti

### **2. 🗑️ Rimuovi**

**Clicca** → Dialog SweetAlert di conferma
```
"Rimuovere dalla wishlist?"
"Sei sicuro di voler rimuovere questo libro dalla tua wishlist?"
     ↓
[Sì, rimuovi]   [Annulla]
     ↓ (se sì)
POST /api/user/wishlist/toggle → la card sparisce in-place
(reload solo se la wishlist diventa vuota)
```

---

## 👁️ Visualizzazione Mobile

**Su smartphone**:
- Griglia: 1 colonna (1 libro alla volta)
- Card: Più compatta ma leggibile
- Badge: Stack verticale (uno sopra l'altro)
- Bottoni: Stack verticale

**Su tablet**:
- Griglia: 2 colonne

**Su desktop**:
- Griglia: 3 colonne
- Card: Dimensioni normali

---

## 📍 Stati della Wishlist

### **Wishlist Vuota**

Se non hai ancora aggiunto nessun libro:
```
┌──────────────────────────────────┐
│     💔 La tua wishlist è vuota   │
│                                  │
│  Aggiungi i libri che ti         │
│  interessano dalla scheda di     │
│  dettaglio per ricevere un       │
│  promemoria quando tornano       │
│  disponibili.                    │
│                                  │
│ [Cerca titoli]  [Torna a Home]   │
└──────────────────────────────────┘
```

**Cosa puoi fare**:
1. Clicca "Cerca titoli" → Vai a catalogo
2. Clicca "Torna a Home" → Home page

### **Wishlist con Libri**

Vedi la griglia normale con tutti i tuoi preferiti.

### **Nessun Risultato di Ricerca**

Se filtri per qualcosa che non esiste:
```
⚠️ Nessun titolo corrisponde al filtro corrente.
```

**Soluzione**: Ripulisci il filtro e riprova.

---

## 🔗 Navigazione dalla Wishlist

**Da questa pagina puoi andare a**:

| Clicca su | Vai a |
|-----------|-------|
| **Copertina/Dettagli** | Scheda completa del libro |
| **Esplora Catalogo** | Catalogo con filtri |
| **Prenotazioni** | Pagina prenotazioni |
| **Logo** | Home page |

---

## 💡 Casi di Uso Tipici

### **Scenario 1: Voglio tracciare un libro che non è disponibile**

```
1. Vado al libro su catalogo
2. Clicco ❤️ "Aggiungi ai Preferiti"
3. Il libro entra nella wishlist
4. Ricevo email/notifica quando torna disponibile
5. Vado a wishlist e clicco "Dettagli"
6. Faccio la richiesta di prestito
```

### **Scenario 2: Voglio vedere quali libri della mia wishlist sono DISPONIBILI ORA**

```
1. Vado a /wishlist
2. Vedo subito il badge "⚡ X disponibili ora"
3. Filtro: digito "disponibile"
4. Vedo solo i libri pronti per il prestito
5. Scelgo quale prendere in prestito
```

### **Scenario 3: Ho finito di leggere un libro e lo tolgo dai preferiti**

```
1. Vado a /wishlist
2. Trovo il libro
3. Clicco 🗑️ (trash)
4. Conferma la rimozione
5. Scomparso dalla wishlist!
```

---

## ❓ Domande Frequenti

### **D: Posso aggiungere un libro dalla wishlist?**

❌ No, la wishlist è **solo per visualizzare libri che hai già salvato**. Per aggiungerne uno nuovo, vai al catalogo o cerca il libro.

### **D: La wishlist si sincronizza su tutti i miei dispositivi?**

✅ **Sì!** Se sei loggato con lo stesso account (su computer, tablet, telefono), vedi la stessa wishlist.

### **D: Ricevo una notifica quando un libro torna disponibile?**

✅ **Dipende dalle impostazioni della biblioteca**. Potrebbe arrivare via:
- Email
- Notifica sul sito
- SMS (se configurato)

### **D: Quanti libri posso aggiungere alla wishlist?**

✅ **Illimitati!** Non c'è limite massimo.

### **D: Che differenza c'è tra wishlist e prenotazioni?**

| Wishlist | Prenotazioni |
|----------|--------------|
| **Salva** libri per dopo | **Prenota** un libro specifico |
| **Osserva** la disponibilità | **Richiede** il prestito |
| **Notifiche** quando disponibile | **Coda d'attesa** se in prestito |
| Semplice lista | Azione concreta |

[→ Leggi di più su Prenotazioni](./prenotazioni.md)

### **D: La ricerca nella wishlist è case-sensitive?**

❌ No! "Harry" = "harry" = "HARRY" - tutto uguale.

### **D: Se rimuovo un libro dalla wishlist, perdo la mia prenotazione?**

❌ No, sono cose separate:
- **Wishlist** = lista che guardi
- **Prenotazione** = richiesta di prestito attiva

Se hai una prenotazione attiva, rimane attiva anche se togli il libro dai preferiti.

### **D: Posso stampare la mia wishlist?**

✅ Sì! Usa CTRL+P (Windows) o CMD+P (Mac) per stampare la pagina.

### **D: La wishlist scompare se faccio logout?**

❌ No! I tuoi preferiti rimangono salvati nel database. Quando riaccedi, li vedi di nuovo.

### **D: Quante colonne nella griglia su mobile?**

📱 **1 colonna** (pieno schermo). Se vuoi più spazio, gira il telefono in modalità landscape → 2 colonne.

---

## 🎬 Workflow Completo Tipico

```
1. Sono nel catalogo e trovo un libro interessante
   ↓
2. Vado alla scheda completa del libro
   ↓
3. Vedo che è IN PRESTITO (non disponibile)
   ↓
4. Clicco ❤️ "Aggiungi ai Preferiti"
   ↓
5. Il bottone diventa ROSSO ✓
   ↓
6. Vado a /wishlist
   ↓
7. Trovo il mio libro con badge "⏰ In attesa"
   ↓
8. Aspetto l'email di notifica
   ↓
9. Ricevo email: "Il libro è torna disponibile!"
   ↓
10. Torno a wishlist
    ↓
11. Filtro "disponibile" e lo vedo con badge "🟢 Disponibile ora"
    ↓
12. Clicco "Dettagli"
    ↓
13. Clicco "Richiedi Prestito" e completo le date
    ↓
14. Fatto! Ho richiesto il prestito
```

---

## 🎨 Colori e Icone

| Elemento | Colore | Significato |
|----------|--------|------------|
| **❤️ Rosso** | Nel bottone | Libro nei preferiti |
| **❤️ Grigio** | Nel bottone | Libro NON nei preferiti |
| **🟢 Verde** | Badge | Disponibile ora |
| **⏰ Arancione** | Badge | In attesa |
| **⚡ Blu** | Statistica | Disponibili |

---

## 📚 Prossimi Passi

- ➡️ **Vuoi fare una richiesta di prestito?** [Vai a Prenotazioni](./prenotazioni.md)
- ➡️ **Vuoi cercare altri libri?** [Vai a Catalogo](./catalogo.md)
- ➡️ **Vuoi tornare ai tuoi libri in prestito?** Profilo → Prestiti

---

## 🔐 Note di Sicurezza

✅ La wishlist è **personale e privata** - solo TU puoi vederla (quando sei loggato)
✅ Nessun altro ha accesso alla tua wishlist
✅ I dati sono protetti con crittografia
✅ Puoi fidarti 100%

---

*Ultima revisione: Giugno 2026 (allineato a UserWishlistController)*
*Tempo lettura: 8 minuti*
*Tempo per aggiungere un libro: 2 secondi*
