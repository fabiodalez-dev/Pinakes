# 📅 Calendario Disponibilità - Gestione Visuale Prestiti

## Cos'è il Calendario Disponibilità?

Il **Calendario Disponibilità** è uno strumento visuale che mostra quando un libro è disponibile, quando è prestato e quando ci sono prenotazioni attive. Utilizza **FullCalendar**, una libreria JavaScript professionale per visualizzare eventi in modo interattivo.

**Disponibile da:** v0.2.0 (migliorato in v0.4.0)

---

## 🎯 Dove Trovi il Calendario

### 1. Scheda Libro (Pubblico)

**URL:** `/libro/{id-libro}`

**Dove:** Nella scheda dettaglio di ogni libro, c'è una sezione "Disponibilità" con il calendario.

**Cosa mostra:**
- 🟢 **Verde**: Date disponibili (puoi richiedere prestito)
- 🔴 **Rosso**: Date prestito attivo (non disponibile)
- 🟡 **Giallo**: Prenotazioni in coda
- ⚫ **Grigio**: Date passate (non selezionabili)

**Utente può:**
- Vedere quando il libro sarà disponibile
- Selezionare data inizio/fine per richiesta prestito
- Vedere fino a quando è prestato attualmente

### 2. Dashboard Admin Prestiti

**URL:** Dashboard → Prestiti → Calendario

**Cosa mostra:**
- Tutti i prestiti attivi
- Scadenze imminenti (evidenziate)
- Prestiti in ritardo (rosso intenso)
- Vista globale biblioteca

**Admin può:**
- Vedere tutti i prestiti in un colpo d'occhio
- Filtrare per libro/utente/stato
- Click su evento → dettagli prestito
- Drag & drop per modificare date (opzionale)

### 3. Dashboard Utente

**URL:** `/prenotazioni` (per utenti loggati)

**Sezione:** "I Miei Prestiti"

**Cosa mostra:**
- Prestiti attivi dell'utente
- Scadenze prossime
- Storico prestiti

---

## 🎨 Colori e Significato

### Nel Calendario Scheda Libro

| Colore | Significato | Cosa Puoi Fare |
|--------|-------------|----------------|
| 🟢 **Verde chiaro** | Disponibile | Seleziona date per prestito |
| 🟢 **Verde scuro** | Disponibile + ultima copia | Prenota subito |
| 🔴 **Rosso** | Prestato | Vedi data scadenza, prenota |
| 🟡 **Giallo** | Prenotato da altri | Entra in coda |
| ⚫ **Grigio** | Data passata | Non selezionabile |
| 🔵 **Blu** | Tue prenotazioni | Vedi stato |

### Nel Calendario Admin

| Colore | Significato | Azione |
|--------|-------------|--------|
| 🟢 **Verde** | Prestito in corso | Normale |
| 🟡 **Giallo** | Scade tra 3 giorni | Preparati per ritorno |
| 🟠 **Arancione** | Scade domani | Invia promemoria |
| 🔴 **Rosso** | In ritardo | Sollecita utente |
| 🔵 **Blu** | Prenotazione attiva | In attesa approvazione |
| ⚪ **Grigio** | Prestito completato | Storico |

---

## 📊 Viste Disponibili

### Vista Mese (Default)

**Cosa mostra:**
- Calendario mensile completo
- Eventi distribuiti per giorno
- Numeri piccoli per eventi multipli stesso giorno

**Quando usarla:**
- Panoramica generale
- Pianificare settimane/mesi
- Vedere pattern (es: picchi prestiti)

**Come navigare:**
- Frecce < > per mese precedente/successivo
- Click "Oggi" per tornare a mese corrente
- Click su giorno → vedi eventi quel giorno

### Vista Settimana

**Cosa mostra:**
- 7 giorni consecutivi
- Timeline oraria (se eventi hanno orario)
- Più spazio per dettagli eventi

**Quando usarla:**
- Pianificazione dettagliata settimana
- Vedere orari specifici (es: quando l'utente ha detto che ritira)
- Gestione eventi multipli stesso giorno

### Vista Giorno

**Cosa mostra:**
- Singolo giorno
- Timeline completa 00:00-24:00
- Tutti i dettagli visibili

**Quando usarla:**
- Gestione giornata specifica
- Molti eventi concentrati in un giorno
- Verifica orari precisi

### Vista Lista

**Cosa mostra:**
- Lista cronologica eventi
- Ordinata per data
- Tutti i dettagli in tabella

**Quando usarla:**
- Esportare dati
- Cercare evento specifico
- Stampa lista prestiti

---

## 🔧 Funzionalità Interattive

### Click su Evento

**Scheda libro (utente):**
1. Click su data rossa (prestito)
2. Popup mostra:
   - "Prestato fino al: [data]"
   - "Prenotazione disponibile"
   - Bottone "Prenota"
3. Click "Prenota" → entra in coda

**Dashboard admin:**
1. Click su evento prestito
2. Popup mostra:
   - Nome utente
   - Titolo libro
   - Data inizio/scadenza
   - Stato
   - Azioni rapide:
     - Conferma ritiro
     - Segna restituito
     - Rinnova
     - Vedi dettagli completi

### Selezione Date (Scheda Libro)

**Per richiedere prestito:**
1. Click su data verde iniziale (es: 15 gennaio)
2. Drag fino a data finale (es: 29 gennaio)
3. Area selezionata diventa blu
4. Appare summary:
   - "Dal 15/01 al 29/01 (14 giorni)"
   - "Disponibile"
5. Bottone "Richiedi Prestito"
6. Click → form precompilato con date

**Validazioni automatiche:**
- ❌ Non puoi selezionare date passate
- ❌ Non puoi selezionare oltre date già prestate
- ❌ Durata max configurabile (es: max 30 giorni)
- ✅ Sistema avvisa se superi limiti

### Hover su Eventi

**Mouse sopra evento:**
- Tooltip appare con dettagli
- Senza dover cliccare
- Info rapide (utente, libro, giorni mancanti)

### Drag & Drop (Solo Admin)

**Se abilitato in impostazioni:**
1. Click e tieni premuto su evento
2. Trascina a nuova data
3. Rilascia
4. Conferma nel popup
5. ✅ Prestito spostato (email automatica a utente)

**Validazioni:**
- Non puoi spostare prestiti già iniziati nel passato
- Sistema avvisa se nuova data in conflitto con altre prenotazioni
- Richiede conferma per modifiche >7 giorni

---

## 📥 Export Calendario

### Export ICS (Standard)

**Per utenti:**
1. Scheda libro → Calendario
2. Click "Esporta Calendario"
3. Scarica file .ICS
4. Importa in Google Calendar/Outlook/Apple Calendar

**File contiene:**
- Nome evento: "Prestito: [Titolo Libro]"
- Data inizio
- Data scadenza
- Reminder: 2 giorni prima
- Descrizione: Link alla scheda libro

### Feed ICS Sincronizzabile (Admin)

**Dashboard → Prestiti → Calendario → Impostazioni → Feed**

1. Abilita "Feed pubblico calendario prestiti"
2. Copia URL tipo:
   ```
   https://tuabiblioteca.it/prestiti/feed.ics?token=xyz123
   ```
3. Aggiungi questo URL al tuo calendario
4. ✅ Sincronizzazione automatica!

**Aggiornamenti:**
- Ogni ora il calendario si sincronizza
- Nuovi prestiti appaiono automaticamente
- Modifiche/cancellazioni si riflettono

**Privacy:**
- Token unico per admin
- Non condividere URL (contiene token accesso)
- Revocabile da impostazioni

---

## ⚙️ Configurazione Admin

### Impostazioni Calendario

**Dashboard → Impostazioni → Prestiti → Calendario**

**Opzioni disponibili:**

**Vista Predefinita:**
- Mese (default)
- Settimana
- Giorno
- Lista

**Giorni Visualizzati:**
- Solo giorni lavorativi (lun-ven)
- Include sabato
- Include domenica
- Tutti i giorni

**Orari:**
- Mostra orari: Sì/No
- Orario inizio: 08:00
- Orario fine: 20:00
- Intervallo slot: 30 minuti

**Colori Personalizzati:**
- Colore disponibile: Verde (#10B981)
- Colore prestato: Rosso (#EF4444)
- Colore prenotato: Giallo (#F59E0B)
- Colore scaduto: Rosso scuro (#991B1B)

**Interattività:**
- Abilita drag & drop: Sì/No
- Abilita selezione date: Sì/No
- Mostra numero giorni mancanti: Sì/No

**Notifiche:**
- Email promemoria scadenza: 3 giorni prima
- Evidenzia scadenze imminenti: Sì
- Colore evidenziazione: Arancione

---

## 📱 Responsività Mobile

Il calendario è completamente responsive:

**Su Desktop (>1024px):**
- Vista mese completa
- Tutti i dettagli visibili
- Hover effects
- Drag & drop abilitato

**Su Tablet (768-1024px):**
- Vista mese compatta
- Dettagli ridotti
- Tap invece di hover
- Drag & drop limitato

**Su Mobile (<768px):**
- Vista lista default (più leggibile)
- Swipe per navigare mesi
- Eventi collapsabili
- Nessun drag & drop (interfaccia touch)
- Bottoni grandi per tap

---

## 🔍 Filtri e Ricerca

### Dashboard Admin Calendario

**Filtri disponibili:**

**Per Stato:**
- Tutti i prestiti
- Solo attivi
- Solo scaduti
- Solo prenotazioni
- Solo completati

**Per Libro:**
- Ricerca per titolo
- Filtra per categoria
- Filtra per autore
- Filtra per scaffale

**Per Utente:**
- Ricerca per nome
- Filtra per ruolo
- Filtra per classe (se biblioteca scolastica)

**Per Data:**
- Range date custom
- Questo mese
- Prossimi 7/15/30 giorni
- Anno corrente

**Applicazione filtri:**
- Filtri in tempo reale
- Nessun reload pagina
- Salva filtri preferiti
- Reset con un click

---

## 🆕 Novità v0.4.1

### Miglioramenti Calendario
- ✅ Colori più distintivi per disponibilità
- ✅ Tooltip più informativi
- ✅ Performance migliorata (rendering più veloce)
- ✅ Gestione copie multiple visualizzata meglio

### Nuove Funzionalità
- ✅ Export PDF diretto dal calendario
- ✅ Stampa vista calendario
- ✅ Condivisione eventi calendario via link

### Bug Fix
- ✅ Risolto: Eventi duplicati su cambio vista
- ✅ Risolto: Timezone non corretto per utenti non-italiani
- ✅ Risolto: Drag & drop non funzionante su Firefox

---

## ❓ Domande Frequenti

**D: Il calendario mostra anche copie multiple?**
R: Sì, se un libro ha 3 copie e 2 sono prestate, il calendario mostra "1 copia disponibile" in verde chiaro.

**D: Posso nascondere il calendario dalla scheda libro?**
R: Sì, Dashboard → Impostazioni → Frontend → "Mostra calendario disponibilità" → Off.

**D: Gli utenti possono vedere il calendario dei propri prestiti?**
R: Sì, in /prenotazioni c'è un calendario personale con i loro prestiti.

**D: Il calendario supporta fusi orari diversi?**
R: Sì, dalla v0.4.1 il calendario usa il fuso orario del server (configurabile).

**D: Posso stampare il calendario?**
R: Sì, click "Stampa" in alto → sceglie layout stampa ottimizzato.

**D: Come faccio a vedere solo i prestiti in scadenza?**
R: Dashboard Calendario → Filtro "Stato" → Seleziona "In scadenza (prossimi 7 giorni)".

---

## 🔗 Collegamenti Utili

- [→ Sistema Prestiti](./sistema-prestiti.md)
- [→ Prenotazioni](./prenotazioni.md)
- [→ Frontend Scheda Libro](../frontend/scheda_libro.md)
- [→ API Calendario](../developer/api.md#calendario)

---

**Ultimo aggiornamento:** Dicembre 2025
**Versione documentazione:** 1.0.0
**Compatibile con:** Pinakes v0.4.1+
**Libreria utilizzata:** FullCalendar v6.1
