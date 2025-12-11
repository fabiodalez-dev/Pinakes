# 📅 Sistema Eventi - Gestione Eventi Biblioteca

## Cos'è il Sistema Eventi?

Il **Sistema Eventi** di Pinakes permette di creare, gestire e pubblicare eventi della biblioteca sul sito pubblico. Eventi come presentazioni di libri, incontri con autori, laboratori di lettura, gruppi di discussione e attività culturali.

**Disponibile dalla:** v0.4.0

---

## 🎯 A Cosa Serve?

Il sistema eventi ti permette di:
- ✅ Pubblicizzare eventi della biblioteca
- ✅ Gestire iscrizioni (opzionale)
- ✅ Mostrare calendario eventi sulla homepage
- ✅ Inviare promemoria automatici via email
- ✅ Tracciare partecipanti
- ✅ Creare eventi ricorrenti

---

## 📋 Creare un Evento

### Passo per Passo

1. **Dashboard → Eventi → + Nuovo Evento**

2. **Informazioni Base:**
   - **Titolo**: Nome evento (es: "Presentazione libro XYZ")
   - **Descrizione**: Dettagli evento (editor WYSIWYG)
   - **Tipo**: Presentazione libro / Incontro autore / Laboratorio / Conferenza / Altro
   - **Immagine**: Locandina evento (opzionale)

3. **Data e Ora:**
   - **Data inizio**: Giorno dell'evento
   - **Ora inizio**: Orario inizio (es: 18:00)
   - **Ora fine**: Orario fine (es: 20:00)
   - **Evento ricorrente**: Toggle se si ripete (es: ogni martedì)

4. **Luogo:**
   - **Dove**: Sala biblioteca / Online / Esterno
   - **Indirizzo**: Se esterno, indirizzo completo
   - **Link streaming**: Se online, link Zoom/Meet

5. **Partecipanti:**
   - **Posti disponibili**: Numero max partecipanti (0 = illimitato)
   - **Richiede iscrizione**: Toggle on/off
   - **Scadenza iscrizione**: Data limite prenotazioni

6. **Pubblicazione:**
   - **Stato**: Bozza / Pubblicato / Annullato
   - **Visibile da**: Data inizio visibilità
   - **Visibile fino**: Data fine visibilità

7. **Salva Evento**

**Tempo:** ~5 minuti per evento

---

## 📚 Tipi di Eventi

### 1. Presentazione Libro

**Esempio:**
```
Titolo: "Il nuovo romanzo di Elena Ferrante"
Tipo: Presentazione libro
Data: 15/01/2026 ore 18:00-20:00
Luogo: Sala eventi biblioteca
Posti: 50
Iscrizione: Richiesta
```

**Cosa includere:**
- Copertina libro
- Breve sinossi
- Info autore
- Moderatore (se presente)
- Link per acquistare libro

### 2. Incontro con Autore

**Esempio:**
```
Titolo: "Incontro con Roberto Saviano"
Tipo: Incontro autore
Data: 20/01/2026 ore 17:00-19:00
Luogo: Auditorium comunale
Posti: 100
Iscrizione: Richiesta (priorità soci)
```

**Cosa includere:**
- Foto autore
- Biografia breve
- Libri pubblicati
- Temi discussione
- Q&A previsto

### 3. Laboratorio

**Esempio:**
```
Titolo: "Laboratorio di scrittura creativa"
Tipo: Laboratorio
Data: Ogni giovedì dal 10/01 al 14/03 ore 15:00-17:00
Luogo: Sala lettura
Posti: 15
Iscrizione: Obbligatoria
```

**Cosa includere:**
- Età target
- Materiale necessario
- Costo (se previsto)
- Numero incontri
- Docente/conduttore

### 4. Gruppo di Lettura

**Esempio:**
```
Titolo: "Club del Libro - Gennaio"
Tipo: Gruppo discussione
Data: 25/01/2026 ore 19:00-21:00
Luogo: Online (Zoom)
Posti: 20
Iscrizione: Consigliata
```

**Cosa includere:**
- Libro del mese
- Link streaming
- Domande guida
- Moderatore

### 5. Conferenza

**Esempio:**
```
Titolo: "La biblioteca digitale del futuro"
Tipo: Conferenza
Data: 30/01/2026 ore 10:00-13:00
Luogo: Aula magna
Posti: 80
Iscrizione: No
```

**Cosa includere:**
- Relatori
- Programma dettagliato
- Materiale scaricabile
- Certificato partecipazione

---

## 🌐 Visualizzazione Pubblica

### Widget Homepage

**Dove appare:**
- Homepage → Sezione "Prossimi Eventi"
- Mostra i prossimi 3 eventi
- Ordinati per data
- Solo eventi "Pubblicati"

**Come attivarlo:**
1. Dashboard → CMS → Homepage
2. Sezione "Organizza Sezioni"
3. Abilita "Eventi Prossimi"
4. Salva

### Pagina Calendario Eventi

**URL:** `/eventi`

**Cosa contiene:**
- Calendario mensile interattivo
- Lista eventi in timeline
- Filtri per tipo evento
- Ricerca eventi passati
- Export ICS (Google Calendar, Outlook)

**Layout:**
- Vista Mese (default)
- Vista Settimana
- Vista Lista

### Scheda Evento Singolo

**URL:** `/eventi/{slug-evento}`

**Cosa mostra:**
- Dettagli completi
- Locandina grande
- Mappa (se luogo fisico)
- Posti disponibili
- Form iscrizione (se richiesta)
- Condivisione social
- Aggiungi a calendario

---

## 📧 Notifiche Email

Il sistema invia automaticamente email per:

### 1. Conferma Iscrizione

**Quando:** Utente si iscrive all'evento

**Contiene:**
- Conferma iscrizione
- Dettagli evento
- Data, ora, luogo
- Link aggiungi a calendario (ICS)
- Eventuale link online

### 2. Promemoria

**Quando:** 2 giorni prima dell'evento (configurabile)

**Contiene:**
- Reminder evento
- Info logistiche
- Contatti organizzatori
- Link per disdire (se previsto)

### 3. Annullamento

**Quando:** Admin annulla l'evento

**Contiene:**
- Notifica annullamento
- Motivazione (se fornita)
- Info eventi simili futuri

### 4. Modifica Evento

**Quando:** Admin modifica data/ora/luogo

**Contiene:**
- Notifica modifica
- Vecchi e nuovi dettagli
- Opzione conferma/disdici

---

## 🎫 Sistema Iscrizioni

### Iscrizione Utente

**Se richiede iscrizione:**

1. Utente va su scheda evento
2. Clicca "Iscriviti"
3. Compila form:
   - Nome, Cognome, Email (precompilati se loggato)
   - Numero partecipanti (opzionale)
   - Note (opzionale)
4. Invia
5. Riceve email conferma
6. ✅ Iscritto!

### Lista Iscritti (Admin)

**Dashboard → Eventi → [Nome Evento] → Iscritti**

**Cosa vedi:**
- Tabella iscritti
- Nome, email, data iscrizione
- Stato: Confermato / In attesa / Cancellato
- Export CSV
- Invia email a tutti

**Operazioni:**
- ✅ Conferma iscrizione manualmente
- ❌ Cancella iscrizione
- 📧 Invia email a singolo iscritto
- 📥 Export lista per registro presenze

### Gestione Liste d'Attesa

**Se evento pieno:**
- Utente può iscriversi a lista d'attesa
- Se qualcuno cancella → primo in lista riceve notifica automatica
- Ha 24 ore per confermare, altrimenti passa al successivo

---

## 🔁 Eventi Ricorrenti

**Per eventi che si ripetono:**

1. Crea evento
2. Abilita "Evento ricorrente"
3. Scegli frequenza:
   - Giornaliera (ogni N giorni)
   - Settimanale (es: ogni martedì)
   - Mensile (es: primo lunedì del mese)
   - Annuale (es: 15 maggio ogni anno)
4. Imposta data fine ricorrenza
5. Salva

**Il sistema crea automaticamente** tutte le occorrenze.

**Esempio:**
```
Evento: "Ora del racconto per bambini"
Ricorrenza: Ogni sabato alle 10:00
Inizio: 10/01/2026
Fine: 30/06/2026
→ Sistema crea ~26 eventi automaticamente
```

**Modifica eventi ricorrenti:**
- Modifica singola occorrenza
- Modifica tutte le occorrenze future
- Elimina singola occorrenza
- Elimina tutte le occorrenze

---

## 📊 Statistiche Eventi

**Dashboard → Eventi → Statistiche**

**Metriche disponibili:**
- 📈 Eventi totali (anno corrente)
- 👥 Totale partecipanti
- 📊 Media partecipanti per evento
- 🏆 Eventi più popolari
- 📅 Eventi per mese (grafico)
- 📍 Eventi per tipo (grafico)
- ⭐ Rating medio eventi (se recensioni abilitate)

**Export report:**
- PDF per presentazioni
- CSV per analisi
- Grafico PNG

---

## 🎨 Personalizzazione

### Colori per Tipo Evento

**Dashboard → Eventi → Impostazioni → Colori**

Assegna colore diverso per ogni tipo:
- Presentazione libro → 🔵 Blu
- Incontro autore → 🟢 Verde
- Laboratorio → 🟡 Giallo
- Conferenza → 🔴 Rosso
- Altro → ⚫ Grigio

I colori appaiono nel calendario per distinguere visivamente.

### Template Email

**Dashboard → Impostazioni → Template Email → Eventi**

Personalizza i template:
- Conferma iscrizione
- Promemoria
- Annullamento
- Modifica

**Variabili disponibili:**
- `{{evento_titolo}}` - Nome evento
- `{{evento_data}}` - Data evento
- `{{evento_ora}}` - Ora inizio
- `{{evento_luogo}}` - Luogo
- `{{utente_nome}}` - Nome iscritto
- `{{posto_numero}}` - Numero posto assegnato

---

## 📱 Integrazione Calendario

### Export ICS

**Utenti possono:**
1. Aprire scheda evento
2. Cliccare "Aggiungi a Calendario"
3. Scaricare file .ICS
4. Importare in:
   - Google Calendar
   - Outlook
   - Apple Calendar
   - Qualsiasi app calendario

**Admin possono:**
- Esportare tutti gli eventi del mese
- Condividere calendario pubblico (URL)
- Sincronizzazione automatica (feed ICS)

### URL Feed Calendario

**Dashboard → Eventi → Impostazioni → Feed Pubblico**

Abilita feed pubblico → Ottieni URL tipo:
```
https://tuabiblioteca.it/eventi/feed.ics
```

Gli utenti possono **sottoscrivere questo URL** nel loro calendario e ricevere aggiornamenti automatici.

---

## 🔔 Promemoria e Notifiche

### Push Notification (Opzionale)

**Se attivate:**
- Browser chiede permesso notifiche
- Utenti iscritti ricevono promemoria 1 giorno prima
- Click notifica → apre scheda evento

### Email Digest Settimanale

**Dashboard → Eventi → Impostazioni → Digest**

Invia email ogni lunedì con:
- Eventi settimana corrente
- Nuovi eventi aggiunti
- Posti ancora disponibili

Gli utenti possono sottoscriversi/cancellarsi.

---

## ❓ Domande Frequenti

**D: Posso creare eventi privati (solo per utenti loggati)?**
R: Sì, nel form evento seleziona "Visibilità: Solo utenti registrati".

**D: Posso limitare iscrizioni a certi ruoli (es: solo soci)?**
R: Sì, in "Requisiti iscrizione" seleziona ruoli ammessi.

**D: Gli eventi passati vengono cancellati?**
R: No, restano in archivio. Puoi filtrare per "Eventi passati" e esportarli.

**D: Posso collegare un libro a un evento?**
R: Sì, in "Libro correlato" cerca e seleziona il libro. Apparirà nella scheda evento con link.

**D: Come faccio a inviare email a tutti gli iscritti?**
R: Dashboard → Eventi → [Evento] → Iscritti → "Invia Email Gruppo".

**D: Posso duplicare un evento?**
R: Sì, click "Duplica" nella lista eventi. Utile per eventi simili.

---

## 🆕 Novità v0.4.0

- ✅ Sistema eventi completamente nuovo
- ✅ Iscrizioni con gestione liste d'attesa
- ✅ Eventi ricorrenti
- ✅ Export ICS
- ✅ Feed calendario pubblico
- ✅ Widget homepage
- ✅ Statistiche eventi

---

## 🔗 Collegamenti Utili

- [→ CMS](./cms.md) - Per personalizzare pagina eventi
- [→ Temi](./README.md) - Per cambiare colori eventi
- [→ Impostazioni Email](../guida-admin/impostazioni.md#email) - Configurare SMTP

---

**Ultimo aggiornamento:** Dicembre 2025
**Versione documentazione:** 1.0.0
**Compatibile con:** Pinakes v0.4.0+
