# 📅 Prenotazioni e Code - Guida Completa

> Come funziona il sistema di prenotazioni con coda FIFO (First In First Out)

Il sistema di prenotazioni permette agli utenti di "mettersi in coda" per libri attualmente in prestito. Quando il libro viene restituito, il primo della coda riceve automaticamente una notifica.

---

## 🎯 Cos'è una Prenotazione?

### Scenario Tipico

```
Giulia vuole leggere "1984" di George Orwell

1. Va nel catalogo e cerca "1984"
   ↓
2. Apre la scheda libro
   ↓
3. Vede badge 🔴 "In prestito" (Marco lo ha preso)
   ↓
4. Clicca "Prenota questo libro"
   ↓
5. Seleziona quando vorrebbe leggerlo:
   - Dal: 15/01/2026
   - Al: 15/02/2026 (opzionale)
   ↓
6. Conferma
   ↓
✅ Prenotazione creata!
✅ Email: "Libro prenotato, sei il #1 in coda"
✅ Può vedere la sua posizione in /prenotazioni
```

### Differenza tra Prestito e Prenotazione

| Aspetto | Prestito | Prenotazione |
|---------|----------|--------------|
| **Quando** | Libro disponibile | Libro NON disponibile |
| **Stato libro** | Subito "In prestito" | Resta "In prestito" (di qualcun altro) |
| **Ritiro** | Immediato (appena approvato) | Quando il libro torna disponibile |
| **Coda** | No | Sì, FIFO |
| **Scadenza** | Data restituzione | Data prenotazione (timeout) |

---

## 🔄 Come Funziona la Coda FIFO

### FIFO = First In First Out

**Regola**: Chi prenota PRIMA, ha la PRIORITÀ.

### Esempio Pratico

```
LIBRO: "Il Signore degli Anelli" (1 copia)
STATO: In prestito a Marco (scadenza 20/12/2025)

┌─────────────────────────────────────────────────────┐
│  CODA PRENOTAZIONI:                                 │
│                                                     │
│  #1 → Giulia Verdi     (prenotato 01/12/2025)     │
│  #2 → Luca Bianchi     (prenotato 05/12/2025)     │
│  #3 → Sara Neri        (prenotato 10/12/2025)     │
│  #4 → Paolo Gialli     (prenotato 12/12/2025)     │
└─────────────────────────────────────────────────────┘

Cosa succede quando Marco restituisce il libro?

SCENARIO A: Giulia ritira il libro
─────────────────────────────────────
21/12/2025: Marco restituisce il libro
  ↓
Sistema:
  ✅ Email automatica a Giulia (#1): "Il libro è disponibile!"
  ✅ Giulia ha 7 giorni per ritirarlo
  ✅ Gli altri restano in coda:
     #1 → Luca (era #2, ora promosso)
     #2 → Sara (era #3)
     #3 → Paolo (era #4)

23/12/2025: Giulia arriva in biblioteca
  ↓
Admin approva e conferma ritiro
  ✅ Giulia ha il libro in prestito
  ✅ Coda aggiornata:
     #1 → Luca
     #2 → Sara
     #3 → Paolo

SCENARIO B: Giulia NON ritira il libro (timeout)
─────────────────────────────────────────────────
21/12/2025: Marco restituisce, email a Giulia
  ↓
7 giorni dopo (28/12/2025): Giulia non si è presentata
  ↓
Sistema:
  ❌ Prenotazione di Giulia cancellata automaticamente
  ✅ Email automatica a Luca (#1): "Il libro è disponibile!"
  ✅ Luca ha 7 giorni per ritirarlo
  ✅ Coda aggiornata:
     #1 → Luca (promosso)
     #2 → Sara (promossa)
     #3 → Paolo (promosso)
```

---

## 📊 Stati di una Prenotazione

### 1. ⏳ In coda

**Quando**: Il libro è ancora in prestito a qualcun altro, ci sono persone prima di te.

**Badge**: Giallo con numero posizione

**Esempio**: "Posizione in coda: #3"

**Cosa fare**: Aspettare. Riceverai email quando sarà il tuo turno.

**Visibilità**:
- Frontend (/prenotazioni): Sì, in "Prenotazioni attive"
- Dashboard Admin: Sì, in lista prenotazioni

**Azioni disponibili**:
- ❌ Annulla prenotazione (se cambi idea)

---

### 2. 🟢 Disponibile per ritiro

**Quando**: È il tuo turno! Il libro è disponibile e puoi ritirarlo.

**Badge**: Verde

**Email**: "Il libro che hai prenotato è disponibile! Ritiralo entro 7 giorni"

**Cosa fare**:
1. Vai in biblioteca entro 7 giorni
2. Ritira il libro al banco
3. L'admin conferma il ritiro → diventa prestito attivo

**Timeout**: Se non ritiri entro 7 giorni, passa al prossimo in coda.

**Visibilità**:
- Frontend: Alert verde in /prenotazioni "Il tuo libro è pronto!"
- Dashboard Admin: Badge "Pronto per ritiro"

---

### 3. ✅ Ritirata

**Quando**: Hai ritirato il libro, ora è un prestito attivo.

**Cosa succede**:
- La prenotazione diventa un **prestito**
- Sparisce da "Prenotazioni attive"
- Appare in "Prestiti in corso"

---

### 4. ❌ Scaduta

**Quando**: Non hai ritirato il libro entro il timeout (7 giorni).

**Badge**: Grigio

**Email**: "La tua prenotazione è scaduta. Il libro è passato al prossimo in coda"

**Cosa fare**:
- Niente, la prenotazione è cancellata
- Puoi riprenotare se vuoi (torni in fondo alla coda)

---

### 5. ❌ Annullata

**Quando**: Hai annullato manualmente la prenotazione (o l'admin l'ha cancellata).

**Come annullare**:
```
Frontend (/prenotazioni):
1. Trova la prenotazione in "Prenotazioni attive"
2. Clicca "Annulla Prenotazione"
3. Conferma → Prenotazione cancellata
   ↓
✅ Gli altri in coda salgono di posizione
✅ Email: "Prenotazione annullata"
```

---

## 🎨 Gestione Copie Multiple

### Quando ci sono più copie dello stesso libro

Pinakes gestisce **intelligentemente** le copie multiple:

```
LIBRO: "Harry Potter e la Pietra Filosofale"
COPIE: 3 totali

STATO ATTUALE:
├─ Copia #1: In prestito a Marco (scadenza 20/12)
├─ Copia #2: In prestito a Giulia (scadenza 25/12)
└─ Copia #3: Disponibile ✅

CODA PRENOTAZIONI:
├─ #1 → Luca (prenotato 01/12)
├─ #2 → Sara (prenotato 05/12)
└─ #3 → Paolo (prenotato 10/12)

Cosa vede Luca?
┌─────────────────────────────────────────────────┐
│  Badge sulla scheda libro:                      │
│  🟡 "1 copia disponibile, 2 in prestito"       │
│                                                 │
│  Opzioni:                                       │
│  [Richiedi Prestito]  ← Prende la copia #3    │
│  [Prenota]            ← Entra in coda          │
└─────────────────────────────────────────────────┘

Scenario 1: Luca clicca "Richiedi Prestito"
─────────────────────────────────────────────
✅ Luca ottiene subito la copia #3
✅ Stato:
   - Copia #1: Marco
   - Copia #2: Giulia
   - Copia #3: Luca
✅ Badge diventa: 🔴 "Tutte le copie in prestito"
✅ Sara e Paolo restano in coda:
   #1 → Sara
   #2 → Paolo

Scenario 2: Luca clicca "Prenota"
──────────────────────────────────
✅ Luca entra in coda (diventa #4)
✅ Copia #3 resta disponibile
✅ Il prossimo che cerca il libro la può prendere subito

Quando Marco restituisce (20/12):
──────────────────────────────────
✅ Copia #1 torna disponibile
✅ Email automatica a Luca (#1): "Libro disponibile!"
✅ Luca può ritirare la copia #1
✅ Stato durante il ritiro di Luca:
   - Copia #1: Disponibile (riservata per Luca)
   - Copia #2: Giulia
   - Copia #3: Luca (già in suo possesso)
```

### Logica di Assegnazione

**Regola**: Se ci sono copie disponibili, NON serve prenotare. La prenotazione serve solo quando TUTTE le copie sono prestate.

```
IF copie_disponibili > 0:
    → Pulsante: "Richiedi Prestito" (prestito immediato)
ELSE:
    → Pulsante: "Prenota" (entra in coda)
```

---

## 📧 Notifiche Email Automatiche

### Email di Conferma Prenotazione

**Quando**: Subito dopo aver prenotato

**Contenuto**:
```
Oggetto: Prenotazione confermata - "1984"

Ciao Giulia,

La tua prenotazione per il libro "1984" è stata confermata.

Posizione in coda: #3

Attualmente ci sono 2 persone prima di te. Riceverai una notifica
quando il libro sarà disponibile per il ritiro.

Puoi controllare lo stato su: https://biblioteca.it/prenotazioni

Grazie,
Biblioteca Comunale
```

### Email di Disponibilità

**Quando**: Quando è il tuo turno

**Contenuto**:
```
Oggetto: Il libro "1984" è disponibile!

Ciao Giulia,

Buone notizie! Il libro "1984" che avevi prenotato è ora disponibile.

📅 Hai 7 giorni per ritirarlo (entro il 28/12/2025)

Vieni in biblioteca durante gli orari di apertura e presentati
al banco per ritirare il tuo libro.

⚠️ Se non ritiri il libro entro 7 giorni, la prenotazione
   passerà automaticamente alla persona successiva in coda.

Orari: Lun-Ven 9-18, Sab 9-13

Grazie,
Biblioteca Comunale
```

### Email di Scadenza

**Quando**: Se non ritiri entro il timeout

**Contenuto**:
```
Oggetto: Prenotazione scaduta - "1984"

Ciao Giulia,

La tua prenotazione per "1984" è scaduta perché non è stata
ritirata entro i 7 giorni previsti.

Il libro è stato assegnato alla persona successiva in coda.

Puoi riprenotare il libro se sei ancora interessata.

Grazie,
Biblioteca Comunale
```

---

## ⚙️ Configurazione Sistema Prenotazioni

### Impostazioni Admin

**Dashboard → Impostazioni → Prestiti**

| Impostazione | Default | Descrizione |
|--------------|---------|-------------|
| **Abilita prenotazioni** | ✅ Sì | Permette agli utenti di prenotare |
| **Giorni per ritiro** | 7 giorni | Timeout per ritirare libro disponibile |
| **Max prenotazioni per utente** | 5 | Limite prenotazioni contemporanee |
| **Notifica ritiro** | ✅ Email | Tipo di notifica (email, push, entrambi) |
| **Auto-cancella scadute** | ✅ Sì | Rimuove automaticamente prenotazioni scadute |

### Priorità Personalizzate (Avanzato)

**Configurabile via database** (per sviluppatori):

Puoi dare priorità a:
- Utenti premium/abbonati
- Docenti/personale
- Utenti con meno prestiti in corso

**File**: `src/Models/Reservation.php`

---

## 📊 Visualizzazione Prenotazioni

### Per l'Utente (Frontend /prenotazioni)

```
┌─────────────────────────────────────────────────────┐
│  📖 PRENOTAZIONI ATTIVE                             │
│  ────────────────────────────────────────────────   │
│  ┌───────────────────────────────────────────────┐  │
│  │  📘 "1984"                                    │  │
│  │  George Orwell                                │  │
│  │                                               │  │
│  │  🟡 Posizione in coda: #3                    │  │
│  │  📅 Prenotato il: 10/12/2025                 │  │
│  │                                               │  │
│  │  Stato: 2 persone prima di te                │  │
│  │                                               │  │
│  │  [Dettagli Libro]  [Annulla Prenotazione]    │  │
│  └───────────────────────────────────────────────┘  │
│                                                     │
│  ┌───────────────────────────────────────────────┐  │
│  │  📗 "Il nome della rosa"                      │  │
│  │  Umberto Eco                                  │  │
│  │                                               │  │
│  │  🟢 Pronto per il ritiro!                    │  │
│  │  📅 Disponibile dal: 15/12/2025              │  │
│  │  ⏰ Ritira entro: 22/12/2025                 │  │
│  │                                               │  │
│  │  ⚠️ Hai 7 giorni per ritirare                │  │
│  │                                               │  │
│  │  [Dettagli Libro]  [Vai in Biblioteca]       │  │
│  └───────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────┘
```

### Per l'Admin (Dashboard)

**Dashboard → Prestiti → Tab "Prenotazioni"**

```
┌────────────────────────────────────────────────────────────────┐
│  Filtri: [Tutti ▼] [Libro: ____] [Utente: ____]              │
└────────────────────────────────────────────────────────────────┘

Tabella:
┌───┬────────────────┬───────────────┬──────────┬──────────┬────────┐
│ # │ Utente         │ Libro         │ Data     │ Stato    │ Azioni │
├───┼────────────────┼───────────────┼──────────┼──────────┼────────┤
│ 1 │ Giulia Verdi   │ "1984"        │ 01/12/25 │ 🟢 Pronto│ Notifica│
│   │ giulia@mail.it │ G. Orwell     │          │          │ Annulla│
├───┼────────────────┼───────────────┼──────────┼──────────┼────────┤
│ 2 │ Luca Bianchi   │ "1984"        │ 05/12/25 │ 🟡 #2    │ Annulla│
│   │ luca@mail.it   │ G. Orwell     │          │          │        │
├───┼────────────────┼───────────────┼──────────┼──────────┼────────┤
│ 3 │ Sara Neri      │ "1984"        │ 10/12/25 │ 🟡 #3    │ Annulla│
│   │ sara@mail.it   │ G. Orwell     │          │          │        │
└───┴────────────────┴───────────────┴──────────┴──────────┴────────┘
```

---

## 🎯 Casi d'Uso Pratici

### Caso 1: Libro Molto Richiesto

**Scenario**: "Harry Potter e la Pietra Filosofale" ha 20 prenotazioni attive.

**Gestione**:
1. Considera **acquistare più copie**
2. Riduci durata prestiti (da 30 a 15 giorni)
3. Disabilita rinnovi per questo libro
4. Comunica con gli utenti i tempi di attesa

```
Dashboard → Libri → Harry Potter → Statistiche:
├─ Copie totali: 2
├─ Prenotazioni attive: 20
├─ Tempo medio attesa: 300 giorni (!)
└─ 🎯 Azione consigliata: Acquista 3 copie aggiuntive
```

### Caso 2: Utente Non Ritira Mai

**Scenario**: Giulia prenota libri ma non li ritira mai (timeout continui).

**Gestione Admin**:
1. Dashboard → Utenti → Giulia Verdi
2. Vedi statistiche:
   - Prenotazioni totali: 10
   - Prenotazioni ritirate: 2 (20%)
   - Prenotazioni scadute: 8 (80%)
3. Azioni possibili:
   - ⚠️ Avviso via email
   - 🔒 Limita prenotazioni (max 1 alla volta)
   - 🚫 Blocca prenotazioni (temporaneamente)

### Caso 3: Prenotazione Prioritaria

**Scenario**: Un docente ha bisogno urgentemente di un libro per le lezioni.

**Gestione**:
1. Admin va in Dashboard → Prestiti → Prenotazioni
2. Trova il libro nella lista
3. Clicca "Azioni" → "Sposta in alto"
4. Seleziona l'utente da spostare in #1
5. Gli altri utenti scendono di una posizione
6. Email automatica al docente: "Prenotazione prioritizzata"

**Nota**: Usa con moderazione per equità!

---

## 📈 Statistiche Prenotazioni

### Dashboard Panoramica

**Dashboard → Home**:
- 📅 Prenotazioni attive
- ⏰ Prenotazioni pronte per ritiro
- ⚠️ Prenotazioni in scadenza (ultime 24h)

### Report Dettagliati

**Dashboard → Report → Prenotazioni**:
- Libri più prenotati
- Utenti con più prenotazioni
- Tasso di ritiro (%) = ritirate / totali
- Tempo medio attesa in coda
- Prenotazioni scadute (%)

**Export CSV**: Scarica tutte le prenotazioni per analisi esterne.

---

## ❓ Domande Frequenti

### **D: Posso prenotare un libro disponibile?**

❌ No, se il libro è disponibile devi fare una **richiesta di prestito**, non una prenotazione. Il sistema mostra automaticamente il bottone corretto.

### **D: Quante prenotazioni posso fare contemporaneamente?**

✅ Dipende dalle impostazioni della biblioteca. Default: 5 prenotazioni attive.

### **D: Cosa succede se due persone prenotano nello stesso istante?**

✅ Il sistema usa il **timestamp esatto** (fino ai millisecondi). Chi conferma prima, è prima in coda.

### **D: Posso cedere il mio posto in coda a qualcun altro?**

❌ No, la coda è rigida (FIFO). Solo l'admin può modificare le priorità manualmente in casi eccezionali.

### **D: Ricevo notifiche quando salgo in coda?**

❌ No, ricevi notifica solo quando il libro è disponibile per il ritiro (sei #1 e il libro è tornato).

### **D: Posso vedere chi c'è prima/dopo di me in coda?**

❌ No, per privacy vedi solo la tua posizione (#3) ma non i nomi degli altri.

### **D: Cosa succede se il libro che ho prenotato viene rimosso dal catalogo?**

✅ Ricevi email automatica: "Il libro prenotato non è più disponibile". La prenotazione viene cancellata.

### **D: Posso prenotare un libro che è "In riparazione"?**

✅ Sì, puoi prenotarlo. Quando torna disponibile (stato cambia da "In riparazione" a "Disponibile"), il sistema ti notifica.

---

## 🔗 Collegamenti Utili

- [→ Sistema Prestiti](./sistema-prestiti.md) - Come funzionano i prestiti
- [→ Calendario](./calendario.md) - Visualizzare disponibilità
- [→ Frontend Prenotazioni](../frontend/prenotazioni.md) - Vista utente
- [→ README Prestiti](./README.md) - Torna alla panoramica

---

*Ultima aggiornamento: Dicembre 2025*
*Versione: Pinakes v0.4.1*
