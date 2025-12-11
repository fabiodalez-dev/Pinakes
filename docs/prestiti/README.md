# 📚 Sistema Prestiti - Guida Completa

Benvenuto nella sezione dedicata al sistema di gestione prestiti di Pinakes, il cuore operativo della tua biblioteca.

## Panoramica

Il sistema prestiti di Pinakes gestisce l'intero ciclo di vita dei prestiti librari: dalla richiesta iniziale dell'utente, all'approvazione, al prestito effettivo, fino alla restituzione. Include anche un sistema avanzato di prenotazioni con coda FIFO, calendario per visualizzare la disponibilità, e notifiche email automatiche per ogni fase.

## 📖 Guide Disponibili

### [→ Sistema Prestiti](./sistema-prestiti.md)
Guida completa al funzionamento del sistema di gestione prestiti.

**Cosa imparerai:**
- Come funziona il workflow completo dei prestiti
- Gli stati di un prestito (in attesa, approvato, in corso, restituito, ritardo)
- Conferma ritiro prenotazioni (two-step loan workflow dalla v0.4.0)
- Come creare, modificare e gestire i prestiti
- Sistema di rinnovi (manuali e automatici)
- Gestione sanzioni per ritardi
- Modalità Catalogo (catalogue mode)

### [→ Prenotazioni e Code](./prenotazioni.md)
Come funziona il sistema di prenotazioni con gestione automatica della coda.

**Cosa imparerai:**
- Sistema di prenotazione libri non disponibili
- Coda FIFO (First In First Out)
- Notifiche automatiche quando un libro diventa disponibile
- Come gestire e annullare prenotazioni
- Priorità e scadenze delle prenotazioni

### [→ Calendario Disponibilità](./calendario.md)
Guida al calendario per visualizzare e gestire la disponibilità dei libri.

**Cosa imparerai:**
- Come visualizzare le date di disponibilità
- Selezione date per prestiti
- Colori e indicatori di disponibilità
- Gestione date di scadenza

## 🎯 Quick Start

**Vuoi creare subito un prestito?**

1. Vai a **Dashboard → Prestiti → + Nuovo Prestito**
2. **Seleziona l'utente** (ricerca per nome o email)
3. **Cerca e seleziona il libro** da prestare
4. **Scegli le date** con il calendario:
   - Data inizio prestito (default: oggi)
   - Data scadenza (es: +30 giorni)
5. Aggiungi **note** se necessario
6. Clicca **"Salva"**
7. ✅ L'utente riceve una **email di conferma automatica**

**Tempo totale:** ~2 minuti per prestito

## 💡 Concetti Chiave

### Workflow del Prestito

Il prestito segue un percorso preciso dalla richiesta alla restituzione:

```
┌─────────────────────────────────────────────────────────────┐
│  1. RICHIESTA                                               │
│     L'utente richiede un libro dal catalogo pubblico       │
│     ↓                                                       │
│  2. APPROVAZIONE                                            │
│     L'admin approva o rifiuta la richiesta                 │
│     ↓                                                       │
│  3. CONFERMA RITIRO (v0.4.0+)                              │
│     L'utente conferma di aver ritirato fisicamente il libro│
│     ↓                                                       │
│  4. PRESTITO ATTIVO                                         │
│     Il libro è in prestito, l'utente lo legge             │
│     ↓                                                       │
│  5. RESTITUZIONE                                            │
│     L'utente restituisce il libro entro la scadenza        │
└─────────────────────────────────────────────────────────────┘
```

### Stati del Prestito

| Stato | Descrizione | Azione Successiva |
|-------|-------------|-------------------|
| **In attesa di approvazione** | Richiesta pendente dall'utente | Admin approva/rifiuta |
| **Approvato** | Admin ha approvato, libro pronto per ritiro | Utente conferma ritiro |
| **In corso** | Libro prestato e in possesso dell'utente | Attendere restituzione |
| **Restituito** | Libro restituito in tempo | Completato |
| **Restituito in ritardo** | Libro restituito dopo la scadenza | Completato (possibile sanzione) |
| **In ritardo** | Scadenza superata, libro non ancora restituito | Sollecito utente |
| **Perso** | Libro dichiarato smarrito | Procedura rimborso |

### Prenotazioni con Coda FIFO

Quando un libro è in prestito, altri utenti possono **prenotarlo**. Le prenotazioni vengono gestite in **coda FIFO** (First In First Out):

```
Libro "Il nome della rosa" - IN PRESTITO
└─ Coda prenotazioni:
   1. Mario Rossi (prenotato 15/11/2025)
   2. Giulia Verdi (prenotato 18/11/2025)
   3. Luca Bianchi (prenotato 20/11/2025)

Quando il libro viene restituito:
→ Mario Rossi riceve email "Il tuo libro è disponibile!"
→ Ha 7 giorni per ritirarlo
→ Se non ritira, passa a Giulia Verdi
```

### Calendario Disponibilità

Il calendario mostra con **colori diversi** la disponibilità:

- 🟢 **Verde**: Date disponibili
- 🟡 **Giallo**: Date con disponibilità limitata
- 🔴 **Rosso**: Date non disponibili (libro già prestato)
- ⚫ **Grigio**: Date passate (non selezionabili)

### Rinnovi

Gli utenti possono **rinnovare** un prestito prima della scadenza se:
- Nessun altro utente ha prenotato il libro
- Il prestito non è già stato rinnovato (limite: 1-2 rinnovi)
- L'utente non ha altri prestiti in ritardo

**Rinnovo automatico**: Se configurato, i prestiti vengono rinnovati automaticamente 3 giorni prima della scadenza.

### Email Automatiche

Il sistema invia automaticamente email per:
- ✅ **Conferma richiesta**: "La tua richiesta è stata ricevuta"
- ✅ **Approvazione prestito**: "Il tuo prestito è stato approvato, puoi ritirare il libro"
- ✅ **Conferma ritiro**: "Grazie per aver ritirato il libro"
- ✅ **Promemoria scadenza**: 3 giorni prima della scadenza
- ✅ **Sollecito ritardo**: Se il libro non è restituito
- ✅ **Prenotazione disponibile**: "Il libro che hai prenotato è disponibile"
- ✅ **Conferma restituzione**: "Grazie per aver restituito il libro"

### Sanzioni per Ritardi

Se configurate dall'admin, le sanzioni possono essere:
- **Blocco account**: L'utente non può fare nuovi prestiti fino alla restituzione
- **Multa giornaliera**: Importo fisso per ogni giorno di ritardo
- **Sospensione temporanea**: Blocco prestiti per X giorni dopo la restituzione

### Modalità Catalogo (Catalogue Mode)

Attivabile da **Impostazioni → Generali → Modalità Catalogo**:
- ✅ Il catalogo pubblico è visibile
- ❌ I prestiti e prenotazioni sono disabilitati
- ❌ I pulsanti "Richiedi Prestito" sono nascosti

**Quando usarla**: Biblioteche che vogliono mostrare solo il catalogo senza gestire prestiti.

## 📊 Statistiche e Numeri

Con il sistema prestiti di Pinakes puoi gestire:
- ✅ Prestiti illimitati
- ✅ Prenotazioni con coda automatica
- ✅ Rinnovi multipli per prestito
- ✅ Calendario intelligente con disponibilità
- ✅ Email automatiche per ogni fase
- ✅ Sanzioni personalizzabili
- ✅ Storico completo per utente e libro
- ✅ Export CSV per report

## 🔗 Collegamenti Utili

- [→ Gestione Utenti](../utenti.md) - Per gestire i lettori
- [→ Gestione Libri](../libri/README.md) - Per gestire il catalogo
- [→ Frontend Prenotazioni](../frontend/prenotazioni.md) - Come gli utenti vedono i loro prestiti
- [→ Impostazioni Email](../settings.md#email) - Per configurare le notifiche

## ❓ Domande Frequenti

**D: Posso creare un prestito manualmente per un utente?**
R: Sì, vai a Prestiti → Nuovo Prestito. Utile per prestiti al banco.

**D: Cosa succede se un utente non restituisce un libro?**
R: Dopo la scadenza, lo stato diventa "In ritardo". Riceve email di sollecito. Puoi applicare sanzioni se configurate.

**D: Come funzionano le prenotazioni se ho 3 copie dello stesso libro?**
R: Il sistema gestisce la disponibilità totale. Se 2 copie sono prestate e 1 disponibile, il primo in coda può ritirare subito.

**D: Posso limitare il numero di prestiti contemporanei per utente?**
R: Sì, da Impostazioni → Prestiti → "Numero massimo prestiti per utente".

**D: Gli utenti vedono tutto lo storico dei loro prestiti?**
R: Sì, nella pagina /prenotazioni possono vedere prestiti in corso, prenotazioni attive e storico completo.

**D: Posso disabilitare i prestiti e usare solo il catalogo?**
R: Sì, attiva la "Modalità Catalogo" dalle impostazioni.

## 🎓 Per Saperne di Più

Questa documentazione è pensata per bibliotecari e amministratori. Se sei uno sviluppatore e vuoi dettagli tecnici sull'implementazione, consulta la [documentazione per developer](../developer/README.md).

## 📝 Note sulla Versione

Questa documentazione è aggiornata per **Pinakes v0.4.1**.

**Novità v0.4.0**:
- Two-step loan workflow (conferma ritiro)
- Stati prestito migliorati (In attesa di approvazione, Prenotato)
- Link diretti ai libri dalle pagine prestito
- Preselezione utente quando crei prestito dalla pagina utente

**Novità v0.4.1**:
- Calendario migliorato con colori per disponibilità
- Link diretti cliccabili su titolo libro nelle pagine prestito
- Formattazione date italiana (GG-MM-AAAA)

Per tutte le modifiche, consulta il [CHANGELOG.md](../../CHANGELOG.md) nella root del progetto.

---

**Prossimo passo:** [Scopri come funziona il sistema prestiti →](./sistema-prestiti.md)
