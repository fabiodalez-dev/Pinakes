# 📅 Prenotazioni - Richiesta e Gestione Prestiti

> **Accedi qui**: `/prenotazioni` (route i18n `reservations`, varia per locale — es.
> `/reservations` in inglese; devi essere loggato)

La pagina **prenotazioni** è il **tuo centro di controllo** per gestire:
- 📚 Libri che hai in prestito ADESSO
- ⏳ Libri che hai prenotato/richiesto
- 📜 Storico di tutti i tuoi passati prestiti

---

## 🔄 Flusso di un Prestito

Quando richiedi un libro, il prestito passa attraverso diversi stati:

```text
┌─────────────┐     Admin        ┌─────────────┐     Data          ┌─────────────┐
│  PENDENTE   │ ──approva───────>│  PRENOTATO  │ ──raggiunta──────>│ DA RITIRARE │
│  (attesa)   │                  │ (confermato)│                   │ (pronto!)   │
└─────────────┘                  └─────────────┘                   └─────────────┘
                                                                          │
                                                                   Ritiri il libro
                                                                          │
                                                                          ▼
                                                                   ┌─────────────┐
                                                                   │  IN CORSO   │
                                                                   │ (in mano)   │
                                                                   └─────────────┘
                                                                          │
                                                              ┌───────────┴───────────┐
                                                              │                       │
                                                              ▼                       ▼
                                                       Restituito             Non restituito
                                                       in tempo                    │
                                                              │                    ▼
                                                              │            ┌─────────────┐
                                                              │            │ IN RITARDO  │
                                                              │            │  (scaduto!) │
                                                              │            └─────────────┘
                                                              │                    │
                                                              ▼                    ▼
                                                       ┌─────────────────────────────┐
                                                       │       STORICO PRESTITI       │
                                                       │ (restituito/perso/etc)       │
                                                       └─────────────────────────────────┘
```

### Stati del Prestito

| Stato | Descrizione | Cosa fare |
|-------|-------------|-----------|
| **Pendente** | Richiesta inviata, in attesa approvazione admin | Aspetta conferma via email |
| **Prenotato** | Approvato! Programmato per una data futura | Aspetta la data di inizio |
| **Da Ritirare** | Il libro è PRONTO! Vai a ritirarlo | Ritira entro X giorni |
| **In Corso** | Il libro è in tuo possesso | Restituisci entro la scadenza |
| **In Ritardo** | La scadenza è passata! | Restituisci SUBITO |

### ⚠️ Importante: Da Ritirare

Quando il tuo prestito diventa **"Da Ritirare"**:
- Riceverai una **email di notifica** (`loan_pickup_ready`)
- Hai un **tempo limite** per ritirare il libro (impostazione admin
  `pickup_expiry_days`, default 3 giorni)
- Se non ritiri entro il `pickup_deadline`, la manutenzione automatica porta il
  prestito a **scaduto** e libera la copia (email `loan_pickup_expired`)

---

## 📝 Come si richiede un prestito (dalla scheda libro)

La richiesta non parte da questa pagina ma dalla **scheda del libro** (bottone
"Prenota"). Si apre un popup **SweetAlert** con un calendario **Flatpickr**:

1. Il calendario carica la disponibilità reale da `/api/libro/{id}/availability`.
2. Ogni giorno è colorato per stato:
   - 🟢 **Verde** (`free`) — almeno una copia disponibile, selezionabile
   - 🔴 **Rosso** (`borrowed`) — tutte le copie in prestito, disabilitato
   - 🟠 **Arancione** (`reserved`) — tutte le copie già prenotate in coda, disabilitato
3. Viene suggerita automaticamente la prima data libera (`earliest_available`).
4. Scegli data inizio e (opzionale) data fine. Se lasci la fine vuota, viene usata
   la durata configurata (`loan_duration_days`, default 30 giorni).
5. Invii: `POST /api/libro/{id}/reservation`. La richiesta nasce **pendente** e
   viene valutata da un amministratore.

> Una **richiesta pendente "nuda"** (senza copia assegnata) **non** sottrae copie
> alla disponibilità di altri utenti: lo fa solo quando l'admin la approva e le
> assegna una copia (modello di occupazione #157).

---

## 🎯 3 Sezioni Principali

```
┌─────────────────────────────────────────────────────────┐
│  ⚠️ ALERT (se hai prestiti in ritardo)                 │
│  "Attenzione: 2 prestiti in ritardo"                   │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│  📚 PRESTITI IN CORSO (3 attivi)                        │
│  ├─ Libro 1 - Scadenza: 25 Oct 2025 (verde se ok)     │
│  ├─ Libro 2 - Scadenza: 20 Oct 2025 (ROSSO se ritardo)│
│  └─ Libro 3 - Dal 19 Oct 2025                          │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│  📖 PRENOTAZIONI ATTIVE (2 attive)                      │
│  ├─ Libro A - Posizione coda: #1 (Scadenza: 30 Nov)   │
│  └─ Libro B - Posizione coda: #3 (Scadenza: 15 Nov)   │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│  📜 STORICO PRESTITI (passati)                          │
│  ├─ Libro X - Restituito: 15 Oct 2025                  │
│  ├─ Libro Y - Restituito in ritardo: 10 Oct 2025      │
│  └─ Libro Z - Perso: 5 Oct 2025                        │
└─────────────────────────────────────────────────────────┘
```

---

## 🚨 Alert Prestiti in Ritardo

**Se hai prestiti scaduti**, vedi un alert rosso in cima:

```
┌──────────────────────────────────────┐
│ ⚠️ Attenzione: 2 prestiti in ritardo │
│                                      │
│ Hai libri che dovevano essere        │
│ restituiti. Restituiscili al più     │
│ presto per evitare sanzioni.         │
└──────────────────────────────────────┘
```

**Cosa significa**: La data di scadenza è PASSATA.

**Cosa fare**: Restituisci il libro al più presto in biblioteca!

---

## 📚 Sezione 1: Prestiti in Corso

### **Cosa Contiene**

Tutti i libri che **HAI IN QUESTO MOMENTO** in prestito.

**Indicatore**:
```
"Prestiti in corso" | X prestito/i attivo/i
```

Esempio: "Prestiti in corso | 3 prestiti attivi"

### **Layout di un Prestito in Corso**

```
┌─────────────────────────────────────────────────┐
│  ┌──────┐                                       │
│  │ Cop. │  Titolo del Libro                     │
│  │  96  │                                       │
│  │px    │  [⏰ Scadenza: 25 Oct 2025] (badge)  │
│  │  X   │  [📅 Dal 19 Oct 2025] (badge)        │
│  │ 128  │                                       │
│  │px    │                                       │
│  │      │                                       │
│  └──────┘                                       │
└─────────────────────────────────────────────────┘
```

### **Badge di Scadenza**

| Badge | Colore | Significa | Urgenza |
|-------|--------|-----------|---------|
| **⏰ Scadenza: 25 Oct 2025** | 🟢 Verde | In tempo | OK |
| **⏰ Scadenza: 15 Oct 2025** | 🟢 Verde | Presto ma OK | Normale |
| **⏰ In ritardo: 10 Oct** | 🔴 Rosso | SCADUTO | URGENTE! |

**Rosso = PERICOLO**: Restituisci subito o rischi multa!

### **Se Non Hai Prestiti Attivi**

```
┌──────────────────────────────────────────┐
│      📖 Nessun prestito attivo            │
│                                           │
│   Non hai libri in prestito al momento   │
└──────────────────────────────────────────┘
```

**Cosa puoi fare**:
- Vai al catalogo e fai una nuova richiesta
- Controlla il tuo wishlist
- Scorri verso il basso per vedere le prenotazioni

---

## 📖 Sezione 2: Prenotazioni e Richieste Attive

### **Tipi di Richieste**

In questa sezione puoi vedere diversi tipi di richieste:

| Tipo | Badge | Descrizione |
|------|-------|-------------|
| **Richiesta Pendente** | ⏳ In Attesa | Hai fatto una richiesta, l'admin deve approvarla |
| **Prestito Prenotato** | 📋 Prenotato | Approvato! Aspetta la data di inizio |
| **Da Ritirare** | 📦 Pronto | Il libro è pronto! Vai a ritirarlo in biblioteca |
| **In Coda** | 📊 Posizione #N | Sei in coda per un libro non disponibile |

### **Cosa Significa Ogni Stato**

**⏳ Richiesta Pendente**:
- Hai chiesto un libro ma l'admin non ha ancora risposto
- Riceverai una email quando viene approvata o rifiutata

**📋 Prestito Prenotato**:
- La tua richiesta è stata APPROVATA!
- Il prestito inizierà alla data indicata
- Riceverai una notifica quando sarà pronto

**📦 Da Ritirare**:
- Il libro ti sta ASPETTANDO in biblioteca!
- Hai un tempo limite per ritirarlo (di solito 3-5 giorni)
- Se non lo ritiri, il prestito potrebbe essere annullato

**📊 In Coda**:
- Il libro è prestato a qualcun altro
- Quando viene restituito, tocca a te

### **Indicatore**

```
"Prenotazioni attive" | X prenotazione/i attiva/e
```

Esempio: "Prenotazioni attive | 2 prenotazioni attive"

### **Layout di una Prenotazione**

```
┌─────────────────────────────────────────────────────┐
│  ┌──────┐                                           │
│  │ Cop. │  Titolo del Libro                         │
│  │  96  │                                           │
│  │px    │  [📊 Posizione: #1 in coda] (blu badge)  │
│  │  X   │  [📅 Scadenza: 30 Nov 2025] (badge)      │
│  │ 128  │                                           │
│  │px    │  [🗑️ Annulla prenotazione]               │
│  │      │                                           │
│  └──────┘                                           │
└─────────────────────────────────────────────────────┘
```

### **Capire la Posizione in Coda**

**Posizione: #1**
```
Tu sei PRIMO!
Quando qualcun altro restituisce il libro,
il tuo prestito sarà approvato
```

**Posizione: #3**
```
Ci sono 2 persone davanti a te
Dovrai aspettare finché ognuno
restituisce il libro
```

**Quanto aspetto?**
```
Di solito 2-4 settimane per posizione,
dipende dai tempi di restituzione
```

### **Annullare una Prenotazione**

**Bottone**: 🗑️ "Annulla prenotazione"

```
Clicca [Annulla prenotazione]
     ↓
Ti chiede conferma: "Annullare questa prenotazione?"
     ↓
[Sì, annulla]  [No, tieni]
     ↓
Se annulli: Sei tolto dalla coda
            La prenotazione scompare
```

**Quando potrebbe servire**:
- Hai cambiato idea
- Non ti serve più il libro
- Hai trovato il libro altrove

### **Se Non Hai Prenotazioni**

```
┌──────────────────────────────────────────┐
│   📅 Nessuna prenotazione                 │
│                                           │
│  Non hai prenotazioni attive al momento  │
└──────────────────────────────────────────┘
```

**Cosa fare**:
- Vai al catalogo
- Trova un libro interessante
- Fai una richiesta di prestito

---

## 📜 Sezione 3: Storico Prestiti (Passati)

### **Cosa Contiene**

Tutti i libri che **HAI RESTITUITO O PERSO** in passato.

**Indicatore**:
```
"Storico prestiti" | X prestito/i passato/i
```

Esempio: "Storico prestiti | 15 prestiti passati"

### **Layout di un Prestito Passato**

```
┌─────────────────────────────────────────────────┐
│  ┌──────┐                                       │
│  │ Cop. │  Titolo del Libro                     │
│  │  96  │                                       │
│  │px    │  [✅ Restituito] (badge grigio)      │
│  │  X   │  [📅 15 Oct 2025] (data restituzione) │
│  │ 128  │                                       │
│  │px    │                                       │
│  │      │                                       │
│  └──────┘                                       │
└─────────────────────────────────────────────────┘
```

### **Stati Possibili di un Prestito Passato**

| Stato | Badge | Significato |
|-------|-------|------------|
| **Restituito** | ✅ Grigio | Restituito in tempo |
| **Restituito in ritardo** | ⚠️ Giallo | Restituito ma dopo scadenza |
| **Perso** | ❌ Rosso | Marcato come perso dall'admin |
| **Danneggiato** | 🔧 Rosso | Libro restituito ma rotto |
| **Annullato** | ⛔ Grigio | Richiesta annullata dall'admin o dall'utente |
| **Scaduto** | ⏰ Arancione | Non ritirato in tempo (pickup scaduto) |

> Nota: una richiesta **rifiutata** dall'admin non compare nello storico — la sua
> riga viene eliminata; ricevi comunque l'email di rifiuto.

### **Se Non Hai Storico**

```
┌──────────────────────────────────────────┐
│    📜 Nessuno storico                     │
│                                           │
│   Non hai prestiti passati                │
│   (Sei novo utente!)                      │
└──────────────────────────────────────────┘
```

---

## 🔍 Interazioni Comuni

### **Clicca sulla Copertina**

Vai alla **scheda completa del libro** dove puoi:
- Leggere la descrizione
- Vedere i dettagli (ISBN, pagine, ecc.)
- Fare una nuova richiesta (se il prestito è finito)

### **Clicca su Titolo**

Stesso di sopra - vai alla scheda libro.

---

## 📱 Layout Mobile

**Su smartphone**:
- Griglia: Adattata (1 card alla volta o 2 in riga)
- Copertina: 96×128px (leggibile)
- Badge: Stack verticale
- Bottoni: Full width (prendono tutta la larghezza)

**Su tablet**:
- Griglia: 2 card in riga
- Più spazioso

**Su desktop**:
- Griglia: Standard come da figlio
- Più layout ottimale

---

## 🔗 Navigazione da Prenotazioni

**Da questa pagina puoi andare a**:

| Clicca su | Vai a |
|-----------|-------|
| **Copertina/Titolo** | Scheda completa del libro |
| **Logo** | Home page |
| **Wishlist** | I tuoi libri salvati |
| **Catalogo** | Ricerca nuovi libri |

---

## 💡 Workflow Tipici

### **Scenario 1: Ho un prestito in corso che sta per scadere**

```
1. Vado a /prenotazioni
2. Vedo "Prestiti in corso" con badge 🟢 verde
3. La scadenza è prossima (es. 3 giorni)
4. Opzioni:
   a) Restituisci il libro in tempo
   b) Fai nuova richiesta dopo restituzione
```

### **Scenario 2: Un mio prestito è SCADUTO**

```
1. Vado a /prenotazioni
2. Vedo ALERT ROSSO in cima
3. Il badge della scadenza è 🔴 ROSSO
4. "In ritardo: 20 Oct 2025" (oggi è 25 Oct)
5. ⚠️ AZIONE URGENTE: Restituisci subito!
6. Rischi multa!
```

### **Scenario 3: Ho una prenotazione e sono #1 in coda**

```
1. Vado a /prenotazioni
2. Vedo "Prenotazioni attive"
3. Il badge dice "📊 Posizione: #1"
4. Aspetto: Quando restituiscono il libro,
            riceverò email di conferma
            e il prestito sarà pronto
5. Clicco "Dettagli" per leggere descrizione
   mentre aspetto
```

### **Scenario 4: Voglio annullare una prenotazione**

```
1. Vado a /prenotazioni
2. Trovo la prenotazione che non voglio più
3. Clicco [Annulla prenotazione]
4. Conferma: "Annullare?"
5. Fatto! Sono tolto dalla coda
   (Se #1, il libro andrà al prossimo)
```

---

## 🎯 Cosa Puoi Fare da Qui

✅ **Vedere i tuoi prestiti attivi** - Quando scadono
✅ **Vedere le tue prenotazioni** - Posizione in coda
✅ **Annullare prenotazioni** - Se non le vuoi più
✅ **Vedere lo storico** - Tutti i tuoi prestiti passati
✅ **Navigare ai dettagli** - Clicca i libri

❌ **Non puoi**:
- Estendere un prestito (contatta admin)
- Cambiare la data della prenotazione
- Saltare la coda
- Modificare uno storico

---

## ❓ Domande Frequenti

### **D: Quanto dura un prestito?**

✅ **Di solito 30 giorni**, ma dipende dal regolamento della tua biblioteca. Vedi la scadenza nella pagina.

### **D: Posso estendere un prestito che sta per scadere?**

⚠️ **Dipende dalla biblioteca**. Opzioni:
- Alcune permettono di estendere online (vedrai un bottone)
- Altre ti fanno contattare l'admin
- Altre no affatto

Leggi il regolamento della tua biblioteca per saperlo.

### **D: Quanto tempo aspetto se sono #3 in coda?**

⏳ Varia, ma di solito:
- Posizione #1 = 1-2 settimane
- Posizione #2 = 2-3 settimane
- Posizione #3 = 3-4 settimane

Dipende da quanto velocemente restituiscono il libro prima di te.

### **D: Se annullo una prenotazione, posso riprennotare dopo?**

✅ **Sì!** Puoi:
1. Vai al catalogo
2. Trova il libro
3. Fai una nuova richiesta
4. Sei di nuovo in coda

### **D: Ricevo notifiche quando è il mio turno?**

✅ **Sì!** Quando è il tuo turno, riceverai:
- Email di notifica
- Possibilmente SMS (se configurato)
- Notifica sul sito

### **D: Cosa significa "In ritardo"?**

🔴 Significa che la **scadenza è passata** e non hai restituito il libro.

**Azione urgente**: Vai in biblioteca e restituisci il libro.

**Conseguenze**:
- Possibile multa
- Sospensione account (se troppi ritardi)

### **D: Se perdo un libro, cosa succede?**

❌ Se un libro è segnato come "Perso" nel tuo storico:
- Probabilmente dovrai pagare il valore del libro
- Il tuo account potrebbe essere sospeso
- Contatta la biblioteca per risolvere

### **D: Posso vedere chi è prima di me in coda?**

❌ No, vedi solo la TUA posizione in coda, non l'intera coda.

### **D: Se non ritiro un libro quando è il mio turno, che succede?**

⏰ Dipende:
- La biblioteca ti mantiene il libro per alcuni giorni (es. 3 giorni)
- Se non lo ritiri entro i giorni limite, passa al prossimo in coda
- Perdi la priorità

Contatta la biblioteca per chiarimenti.

### **D: Posso stamparla, la lista dei miei prestiti?**

✅ Sì! CTRL+P (Windows) o CMD+P (Mac) per stampare la pagina.

### **D: Se cambio gli orari, posso vedere i miei prestiti?**

✅ **Sì!** I tuoi prestiti sono legati al tuo account. Accedi da qualunque dispositivo e li vedi.

---

## 📊 Codici Stato Completi

**Prestiti in corso**:
- 🟢 Verde: Normale, scadenza non raggiunta
- 🔴 Rosso: IN RITARDO, scadenza passata

**Prenotazioni attive**:
- 📊 Posizione in coda: Dove sei nella fila
- 📅 Scadenza prenotazione: Quando scade la prenotazione

**Storico**:
- ✅ Restituito: OK, in tempo
- ⚠️ Restituito in ritardo: Restituito ma dopo scadenza
- ❌ Perso: Marcato come perso dall'admin
- 🔧 Danneggiato: Restituito rovinato
- ⛔ Annullato: Richiesta annullata (admin o utente)
- ⏰ Scaduto: Non ritirato in tempo (pickup scaduto)

(Le richieste rifiutate non vengono conservate nello storico.)

---

## 🚨 Cosa Fare se...

### **...Ho un Prestito in Ritardo**

```
1. Vai in biblioteca IL PRIMA POSSIBILE
2. Restituisci il libro
3. Spiega il ritardo
4. Paga multa se richiesta
5. Chiedi scusa
```

### **...La Mia Prenotazione è Scaduta**

```
1. Vai a /prenotazioni
2. Vedi che la scadenza della prenotazione è passata
3. Contatta la biblioteca
4. Spiega il motivo
5. Richiedi di rinnovare la prenotazione se possibile
```

### **...Non Riesco ad Annullare**

```
1. Prova con browser diverso
2. Pulisci cache del browser
3. Ricarica la pagina (F5)
4. Se ancora non va, contatta admin
```

---

## 📚 Prossimi Passi

- ➡️ **Vuoi cercare nuovi libri?** [Vai a Catalogo](./catalogo.md)
- ➡️ **Vuoi gestire i tuoi preferiti?** [Vai a Wishlist](./wishlist.md)
- ➡️ **Vuoi tornare alla scheda di un libro?** Clicca titolo da qui
- ➡️ **Hai problemi?** Contatta la biblioteca

---

## 🎁 Pro Tips

💡 **Suggerimenti d'oro**:

1. **Controlla regolarmente**: Una volta alla settimana, controlla /prenotazioni per non dimenticare le scadenze

2. **Imposta reminder**: Se una scadenza è importante, salvalo nel calendario del tuo telefono

3. **Fai una lista**: Uno screenshot della wishlist per ricordare i tuoi libri preferiti

4. **Prenotazione strategica**: Se un libro è #3 in coda, continua a cercare altri libri mentre aspetti

5. **Comunica**: Se rischi il ritardo, contatta la biblioteca per estendere il prestito

---

---

## 🔗 Endpoint coinvolti (riferimento)

| Azione | Endpoint |
|--------|----------|
| Pagina prenotazioni | `GET /prenotazioni` (i18n `reservations`) |
| Disponibilità calendario | `GET /api/libro/{id}/availability` |
| Invia richiesta prestito | `POST /api/libro/{id}/reservation` |
| Annulla prenotazione in coda | `POST /reservation/cancel` |
| Cambia data prenotazione | `POST /reservation/change-date` |
| Badge conteggio prenotazioni | `GET /api/user/reservations/count` |

*Ultima revisione: Giugno 2026 (branch `review/loan-reservation-system`)*
