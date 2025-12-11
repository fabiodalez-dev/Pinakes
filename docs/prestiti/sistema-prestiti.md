# 📖 Sistema Prestiti - Guida Dettagliata

> Come funziona il sistema di gestione prestiti di Pinakes dalla A alla Z

Il sistema prestiti di Pinakes è progettato per essere semplice da usare ma completo nelle funzionalità. Questa guida ti accompagna in ogni fase del workflow.

---

## 🎯 Workflow Completo: Dalla Richiesta alla Restituzione

### Scenario Tipico

Mario vuole leggere "Il nome della rosa" di Umberto Eco. Ecco cosa succede:

```
┌────────────────────────────────────────────────────────────────┐
│ FASE 1: RICHIESTA DELL'UTENTE                                  │
└────────────────────────────────────────────────────────────────┘
1. Mario cerca il libro nel catalogo pubblico (http://biblioteca.it/catalogo)
2. Trova "Il nome della rosa" e clicca sulla scheda libro
3. Vede il badge 🟢 "Disponibile"
4. Clicca il bottone "Richiedi Prestito"
5. Si apre un calendario → Mario seleziona:
   - Data inizio: 10/12/2025 (oggi)
   - Data fine: 09/01/2026 (+30 giorni)
6. Clicca "Conferma Richiesta"
   ✅ Stato: "In attesa di approvazione"
   ✅ Mario riceve email: "Richiesta ricevuta, attendi approvazione"

┌────────────────────────────────────────────────────────────────┐
│ FASE 2: APPROVAZIONE DELL'ADMIN                                │
└────────────────────────────────────────────────────────────────┘
7. La bibliotecaria Laura accede a Dashboard → Prestiti
8. Vede la nuova richiesta con badge giallo "In attesa"
9. Clicca sulla richiesta per vedere i dettagli
10. Controlla:
    - L'utente ha altri prestiti in ritardo? NO ✅
    - Il libro è davvero disponibile? SÌ ✅
11. Laura clicca "Approva Prestito"
    ✅ Stato: "Approvato"
    ✅ Mario riceve email: "Prestito approvato! Vieni a ritirare il libro"

┌────────────────────────────────────────────────────────────────┐
│ FASE 3: CONFERMA RITIRO (Two-Step Workflow v0.4.0+)           │
└────────────────────────────────────────────────────────────────┘
12. Mario arriva in biblioteca il 10/12/2025
13. Laura trova il libro sullo scaffale usando la collocazione (A.2.45)
14. Consegna il libro a Mario
15. Laura (o Mario stesso da un totem) clicca "Conferma Ritiro"
    ✅ Stato: "In corso"
    ✅ Il libro viene segnato come "In prestito"
    ✅ Mario riceve email: "Buona lettura! Ricorda di restituirlo entro il 09/01/2026"

┌────────────────────────────────────────────────────────────────┐
│ FASE 4: PRESTITO ATTIVO                                        │
└────────────────────────────────────────────────────────────────┘
16. Mario porta il libro a casa e lo legge
17. Può controllare la scadenza su /prenotazioni
18. Riceve promemoria automatici:
    - 06/01/2026: "Tra 3 giorni devi restituire il libro"
    - 09/01/2026: "Oggi scade il prestito"

┌────────────────────────────────────────────────────────────────┐
│ FASE 5: RESTITUZIONE                                           │
└────────────────────────────────────────────────────────────────┘
19. Mario torna in biblioteca il 08/01/2026 (UN GIORNO PRIMA!)
20. Consegna il libro a Laura
21. Laura:
    - Va a Dashboard → Prestiti → trova il prestito di Mario
    - Clicca "Restituisci"
    - Seleziona "Restituito regolarmente" (perché in tempo)
    - Conferma
22. ✅ Stato: "Restituito"
23. ✅ Il libro torna "Disponibile" nel catalogo
24. ✅ Mario riceve email: "Grazie per aver restituito il libro!"
25. ✅ Se c'erano prenotazioni in coda, il primo utente riceve email automatica
```

**Tempo totale del workflow**: ~30 giorni (durata prestito tipica)

**Tempo gestione admin**: ~5 minuti totali (approvazione + ritiro + restituzione)

---

## 🎨 Stati del Prestito (In Dettaglio)

### 1. 🟡 In attesa di approvazione

**Quando**: L'utente ha fatto richiesta ma l'admin non ha ancora approvato.

**Badge**: Giallo

**Azioni disponibili**:
- ✅ Approva (passa a "Approvato")
- ❌ Rifiuta (cancella il prestito)

**Email inviate**:
- All'utente: "Richiesta ricevuta, attendi approvazione"

**Visibilità**:
- Dashboard Admin: Sì, in evidenza
- Utente Frontend: Sì, in "Prenotazioni attive" con stato "In attesa"

---

### 2. ✅ Approvato

**Quando**: L'admin ha approvato, il libro è pronto per il ritiro fisico.

**Badge**: Verde

**Azioni disponibili**:
- ✅ Conferma Ritiro (passa a "In corso")
- ❌ Annulla (se l'utente non si presenta)

**Email inviate**:
- All'utente: "Prestito approvato! Vieni a ritirare il libro"

**Cosa fare**:
- Preparare il libro fisicamente (trovarlo sullo scaffale)
- Aspettare che l'utente arrivi per ritirarlo
- Quando arriva, cliccare "Conferma Ritiro"

**Timeout**: Se configurato, dopo X giorni senza ritiro, il prestito viene annullato automaticamente.

---

### 3. 🟢 In corso

**Quando**: L'utente ha ritirato fisicamente il libro e lo sta leggendo.

**Badge**: Blu

**Azioni disponibili**:
- ✅ Restituisci (passa a "Restituito" o "Restituito in ritardo")
- 🔄 Rinnova (estende la scadenza)
- 📧 Invia Sollecito (se in ritardo)

**Email inviate**:
- All'utente (all'inizio): "Buona lettura!"
- Promemoria: 3 giorni prima della scadenza
- Promemoria: Il giorno della scadenza

**Cosa monitorare**:
- Data di scadenza
- Se passa la scadenza → diventa automaticamente "In ritardo"

**Visibilità**:
- Dashboard Admin: Tabella "Prestiti in corso"
- Utente Frontend: "Prestiti in corso" con badge scadenza

---

### 4. 🔴 In ritardo

**Quando**: La data di scadenza è passata e il libro non è stato restituito.

**Badge**: Rosso

**Azioni disponibili**:
- ✅ Restituisci → passa a "Restituito in ritardo"
- 📧 Invia Sollecito
- ⚠️ Dichiara Perso

**Email inviate**:
- Sollecito automatico: Ogni 3 giorni
- Sollecito manuale: Quando l'admin clicca "Invia Sollecito"

**Sanzioni** (se configurate):
- Blocco account: L'utente non può fare nuovi prestiti
- Multa: €0.50/giorno di ritardo
- Sospensione: 7 giorni di blocco dopo restituzione

**Alert utente**: Nella pagina /prenotazioni vede alert rosso: "Hai prestiti in ritardo!"

---

### 5. ✅ Restituito

**Quando**: Il libro è stato restituito IN TEMPO (prima o entro la scadenza).

**Badge**: Verde chiaro

**Stato finale**: ✅ Completato

**Email inviate**:
- All'utente: "Grazie per aver restituito il libro"
- Al prossimo in coda (se ci sono prenotazioni): "Il libro che hai prenotato è disponibile"

**Cosa succede**:
- Il libro torna "Disponibile" nel catalogo
- Lo storico viene salvato
- Se ci sono prenotazioni, la prima in coda viene notificata

---

### 6. ⚠️ Restituito in ritardo

**Quando**: Il libro è stato restituito DOPO la scadenza.

**Badge**: Arancione

**Stato finale**: ⚠️ Completato con penalità

**Differenza da "Restituito"**:
- Registra il ritardo nello storico
- Può applicare sanzioni (se configurate)
- Può inviare email diversa

**Email inviate**:
- All'utente: "Libro restituito. Attenzione al ritardo" (opzionale)

**Sanzioni applicate**:
- Se configurate, vengono calcolate automaticamente
- L'admin può modificare l'importo manualmente

---

### 7. ❌ Perso

**Quando**: Il libro è dichiarato smarrito (utente non lo restituisce mai).

**Badge**: Nero/Grigio

**Stato finale**: ❌ Chiuso con perdita

**Azioni fatte**:
- Il libro viene segnato come "Perso" nel database
- L'utente può essere addebitato del valore del libro
- Il libro NON torna disponibile (fino a recupero o sostituzione)

**Quando usarlo**:
- Dopo molti solleciti senza risposta
- L'utente conferma di averlo perso
- Dopo X giorni (es: 90) di ritardo

---

## 🆕 Creazione Prestito Manuale (al Banco)

### Da Dashboard → Prestiti → + Nuovo Prestito

**Quando usarlo**: Quando un utente si presenta fisicamente al banco senza aver fatto richiesta online.

**Procedimento**:

```
1. Clicca "+ Nuovo Prestito"
   ↓
2. Form di creazione:
   ┌─────────────────────────────────────────────────┐
   │ Seleziona Utente: [Mario Rossi ▼]              │
   │ Cerca: [________] (digita nome o email)         │
   │                                                 │
   │ Seleziona Libro: [Il nome della rosa ▼]        │
   │ Cerca: [________] (digita titolo o ISBN)        │
   │                                                 │
   │ 📅 Data Inizio: [10/12/2025] (calendario)      │
   │ 📅 Data Scadenza: [09/01/2026] (calendario)    │
   │                                                 │
   │ 📝 Note: [________________________]             │
   │          (opzionale, es: "Prestito rinnovato") │
   │                                                 │
   │         [Annulla]  [Salva Prestito]            │
   └─────────────────────────────────────────────────┘
   ↓
3. Clicca "Salva Prestito"
   ↓
4. ✅ Prestito creato con stato "In corso" (già approvato)
   ↓
5. L'utente riceve email di conferma
   ↓
6. Consegna fisicamente il libro all'utente
```

**Differenze dalla richiesta online**:
- Non serve approvazione (già approvato)
- Non serve conferma ritiro (già ritirato)
- Parte direttamente come "In corso"

**Preselezione utente** (v0.4.1+):
- Se crei un prestito dalla pagina di un utente (Dashboard → Utenti → Mario Rossi → + Nuovo Prestito)
- L'utente Mario Rossi viene **automaticamente preselezionato**
- Devi solo scegliere il libro e le date

---

## 🔄 Rinnovi: Estendere la Scadenza

### Cos'è un Rinnovo?

Quando un utente vuole **tenere il libro più a lungo**, può richiedere un **rinnovo** = estensione della data di scadenza.

### Requisiti per Rinnovare

✅ Il prestito deve essere "In corso"
✅ Non deve essere già scaduto
✅ Nessun altro utente ha prenotato quel libro
✅ L'utente non ha raggiunto il limite di rinnovi (default: 2 per prestito)
✅ L'utente non ha altri prestiti in ritardo

### Rinnovo Manuale (Admin)

```
1. Vai a Dashboard → Prestiti → trova il prestito
2. Clicca "Dettagli"
3. Clicca "Rinnova Prestito"
4. Scegli la nuova data di scadenza (es: +15 giorni)
5. Conferma
   ↓
✅ Data scadenza aggiornata
✅ Counter rinnovi: 1/2
✅ Email all'utente: "Prestito rinnovato fino al XX/XX/XXXX"
```

### Rinnovo Automatico

**Se abilitato** da Impostazioni → Prestiti → "Rinnovo automatico":

```
3 giorni prima della scadenza:
   ↓
Sistema controlla:
   - Ci sono prenotazioni? NO ✅
   - Utente ha altri ritardi? NO ✅
   - Raggiunto limite rinnovi? NO (0/2) ✅
   ↓
Sistema rinnova automaticamente (+15 giorni)
   ↓
✅ Email all'utente: "Il tuo prestito è stato rinnovato automaticamente"
```

**Pro**: Meno lavoro per admin e utenti
**Contro**: Meno controllo

### Rinnovo dall'Utente (Frontend)

**Da implementare**: Gli utenti potranno richiedere rinnovi da /prenotazioni → bottone "Richiedi Rinnovo"

---

## 📧 Email Automatiche: Cosa Viene Inviato e Quando

### Template Disponibili

| Evento | Template | Destinatario | Quando |
|--------|----------|--------------|--------|
| **Richiesta ricevuta** | `loan_request_received.php` | Utente | Subito dopo richiesta |
| **Prestito approvato** | `loan_approved.php` | Utente | Dopo approvazione admin |
| **Conferma ritiro** | `loan_pickup_confirmed.php` | Utente | Dopo conferma ritiro |
| **Promemoria scadenza** | `loan_due_reminder.php` | Utente | 3 giorni prima |
| **Scadenza oggi** | `loan_due_today.php` | Utente | Il giorno stesso |
| **Sollecito ritardo** | `loan_overdue.php` | Utente | Ogni 3 giorni dopo scadenza |
| **Prestito restituito** | `loan_returned.php` | Utente | Dopo restituzione |
| **Prestito rinnovato** | `loan_renewed.php` | Utente | Dopo rinnovo |
| **Prenotazione disponibile** | `reservation_available.php` | Primo in coda | Quando libro torna disponibile |

### Configurazione Email

**Impostazioni → Email**:
- Server SMTP o PHP mail()
- Indirizzo mittente (es: biblioteca@example.it)
- Nome mittente (es: "Biblioteca Comunale")
- Abilita/Disabilita notifiche

**Personalizzazione template**:
- I file sono in `/app/templates/emails/`
- Modificabili con HTML/CSS
- Variabili disponibili: `{nome_utente}`, `{titolo_libro}`, `{data_scadenza}`, ecc.

---

## ⚠️ Sanzioni per Ritardi

### Tipi di Sanzioni

**1. Blocco Account**

```
Se abilitato: Impostazioni → Prestiti → "Blocca account con ritardi"

Quando scatta:
- L'utente ha ≥1 prestito in ritardo
- Non può fare nuove richieste di prestito
- Vede messaggio: "Account temporaneamente bloccato per ritardi"

Quando si sblocca:
- Quando restituisce TUTTI i libri in ritardo
```

**2. Multa Giornaliera**

```
Se abilitato: Impostazioni → Prestiti → "Multa per ritardi" → €0.50/giorno

Calcolo automatico:
- Data restituzione: 15/01/2026
- Data scadenza: 09/01/2026
- Giorni di ritardo: 6
- Multa: 6 × €0.50 = €3.00

Registrazione:
- Salvata nel prestito
- Visibile all'utente in /prenotazioni
- Esportabile per contabilità
```

**3. Sospensione Temporanea**

```
Se abilitato: Impostazioni → Prestiti → "Giorni sospensione dopo ritardo" → 7

Quando scatta:
- L'utente restituisce un libro in ritardo
- Account bloccato per 7 giorni
- Anche se restituisce tutto, non può fare prestiti per 7 giorni

Quando si sblocca:
- Automaticamente dopo 7 giorni dalla restituzione
```

### Esenzioni

**Admin può esentare**:
- Utenti speciali (es: personale biblioteca)
- Casi particolari (es: malattia documentata)
- Errori di sistema

**Impostazioni → Utenti → Modifica Utente → "Esente da sanzioni"**: ✅

---

## 📅 Calendario e Date

### Selezione Date con Calendario

Quando crei o modifichi un prestito, usi il **calendario interattivo**:

```
┌─────────────────────────────────────┐
│  Dicembre 2025                       │
│  Dom Lun Mar Mer Gio Ven Sab       │
│   1   2   3   4   5   6   7        │
│   8   9  🟢  🟡  🔴  🟢  🟢       │
│  🟢  🟢  🟢  🟢  🟢  🟢  🟢      │
│  🟢  🟢  ⚫  ⚫  ⚫  ⚫  ⚫       │
└─────────────────────────────────────┘

Legenda (v0.4.1+):
🟢 Verde = Disponibile (nessun prestito)
🟡 Giallo = Disponibilità limitata (alcune copie prestate)
🔴 Rosso = Non disponibile (tutte le copie prestate)
⚫ Grigio = Data passata (non selezionabile)
```

### Date Predefinite

**Data Inizio**:
- Default: Oggi
- Modificabile: Sì (puoi scegliere una data futura per prenotazioni)

**Data Scadenza**:
- Default: Oggi + X giorni (configurabile)
- Standard: +30 giorni
- Modificabile: Sì (puoi accorciare o allungare)

**Configurazione**: Impostazioni → Prestiti → "Durata predefinita prestito" → 30 giorni

---

## 🎨 Modalità Catalogo (Catalogue Mode)

### Cos'è?

Una modalità speciale che **nasconde completamente i prestiti** e mostra solo il catalogo pubblico.

### Quando Usarla?

- Biblioteche che vogliono solo mostrare i libri online
- Durante manutenzione del sistema prestiti
- Biblioteche che gestiscono prestiti manualmente (non digitalmente)
- Per collezioni private non prestabili

### Come Attivarla?

**Impostazioni → Generali → "Modalità Catalogo"**: ✅ Attiva

### Cosa Cambia?

**Frontend (Catalogo Pubblico)**:
- ❌ Bottone "Richiedi Prestito" nascosto
- ❌ Pagina /prenotazioni nascosta
- ❌ Badge "Disponibile/Non disponibile" nascosto
- ✅ Catalogo visibile e ricercabile
- ✅ Schede libro complete (ma senza prestiti)

**Backend (Admin)**:
- ✅ Sezione Prestiti sempre accessibile
- ✅ Puoi comunque gestire prestiti interni
- ✅ Statistiche funzionano

**Quando disattivarla**: Quando sei pronto ad accettare richieste online.

---

## 📊 Statistiche e Report

### Dashboard Prestiti

**Dashboard → Home**:
- 📚 Prestiti attivi
- ⏳ Prestiti in attesa
- 🔴 Prestiti in ritardo
- 📅 Prenotazioni in coda

### Report Dettagliati

**Dashboard → Prestiti → Report**:
- Prestiti per periodo (mensile/annuale)
- Libri più prestati
- Utenti più attivi
- Tasso di ritardo (%)
- Multa totale riscossa

### Export CSV

**Da ogni tabella prestiti**:
1. Clicca "Esporta CSV"
2. Seleziona campi da esportare
3. Download file → apri in Excel/LibreOffice

---

## 🔍 Ricerca e Filtri

### Nella Tabella Prestiti

**Filtra per**:
- Stato (Tutti, In corso, In attesa, In ritardo, Restituiti)
- Utente (cerca nome/email)
- Libro (cerca titolo)
- Data (range di date)

**Ordinamento**:
- Data creazione (più recenti)
- Data scadenza (prossimi a scadere)
- Utente (alfabetico)
- Libro (alfabetico)

---

## ❓ Domande Frequenti

### **D: Posso modificare un prestito dopo averlo creato?**

✅ Sì! Vai a Prestiti → Dettagli → Modifica. Puoi cambiare:
- Date (inizio e scadenza)
- Note
- Stato (con cautela)

Non puoi cambiare: Utente o Libro (devi cancellare e ricreare).

### **D: Cosa faccio se un utente perde un libro?**

1. Vai al prestito → Azioni → "Dichiara Perso"
2. Il libro viene segnato come perso
3. Addebita l'utente (manualmente o con plugin)
4. Quando recuperi/sostituisci il libro, aggiorna lo stato manualmente

### **D: Posso prestare un libro già in prestito?**

❌ No, a meno che non ci siano copie multiple disponibili. Se tutte le copie sono prestate, l'utente può solo **prenotare**.

### **D: Le email vengono inviate in coda o subito?**

✅ Subito, ma con sistema di riprova automatico (v0.4.0+). Se l'invio fallisce, riprova ogni ora fino a 24 ore.

### **D: Posso disabilitare alcune email?**

✅ Sì, vai a Impostazioni → Email → Disabilita notifiche specifiche. Puoi disabilitare:
- Promemoria scadenza
- Solleciti
- Conferme

### **D: Il calendario mostra la disponibilità in tempo reale?**

✅ Sì (dalla v0.4.1). I colori si aggiornano in base ai prestiti esistenti.

---

## 📚 Prossimi Passi

- ➡️ **Vuoi capire le prenotazioni?** [Vai a Prenotazioni e Code](./prenotazioni.md)
- ➡️ **Vuoi usare il calendario?** [Vai a Calendario Disponibilità](./calendario.md)
- ➡️ **Torna alla panoramica**: [Vai a README Prestiti](./README.md)

---

*Ultima aggiornamento: Dicembre 2025*
*Versione: Pinakes v0.4.1*
