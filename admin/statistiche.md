# Dashboard e Statistiche

La dashboard di Pinakes fornisce una panoramica immediata dello stato della biblioteca con statistiche, avvisi e azioni rapide.

## Accesso alla Dashboard

La dashboard è la pagina predefinita dopo il login per gli amministratori e lo staff.

**Percorso**: `/dashboard` o `/admin`

## Card Statistiche

La parte superiore mostra 6 indicatori chiave:

| Indicatore | Descrizione |
|------------|-------------|
| **Libri** | Totale libri nel catalogo (esclusi i cancellati) |
| **Utenti** | Totale utenti registrati |
| **Prestiti Attivi** | Prestiti in corso o in ritardo |
| **Pronti per il Ritiro** | Prestiti approvati in attesa di consegna |
| **Richieste Pendenti** | Richieste in attesa di approvazione |
| **Autori** | Totale autori in archivio |

### Card Interattive

Le card "Pronti per il Ritiro" e "Richieste Pendenti" diventano cliccabili quando il contatore è > 0:
- Sfondo colorato (arancione o blu)
- Icona pulsante per attirare l'attenzione
- Click porta alla pagina di gestione

## Calendario Prestiti

Un calendario interattivo mostra prestiti e prenotazioni dei prossimi 6 mesi.

### Legenda Colori

| Colore | Significato |
|--------|-------------|
| 🟢 Verde | Prestiti in corso |
| 🔵 Blu | Prestiti programmati (futuri) |
| 🟠 Arancione | Da ritirare |
| 🔴 Rosso | Prestiti scaduti / Fine periodo |
| 🟡 Ambra | Richieste pendenti |
| 🟣 Viola | Prenotazioni |

### Funzionalità Calendario

- **Vista mese/settimana/lista**: toggle in alto a destra
- **Navigazione**: frecce per mese precedente/successivo
- **Click su evento**: mostra dettagli (titolo, utente, date, stato)
- **Responsive**: su mobile passa automaticamente alla vista lista

### Sincronizzazione ICS

Il pulsante **"Sincronizza (ICS)"** permette di:
1. Scaricare il file calendario
2. Importarlo in Google Calendar, Apple Calendar, Outlook
3. Aggiornamenti automatici quando il calendario è sottoscritto

**Copia Link**: copia l'URL del feed ICS negli appunti.

## Sezioni Operative

Le sezioni sono ordinate per urgenza, dalla più critica alla più informativa.

### 1. Pronti per il Ritiro (Arancione)

Mostra i prestiti approvati in attesa che l'utente ritiri il libro.

**Informazioni mostrate**:
- Copertina libro
- Titolo
- Nome utente e email
- Data inizio prestito
- Scadenza ritiro (se impostata)

**Azioni disponibili**:
- ✅ **Conferma Ritiro**: segna il libro come consegnato
- ❌ **Annulla**: annulla il prestito (se scaduto)

### 2. Richieste di Prestito (Blu)

Mostra le richieste in attesa di approvazione.

**Tipi di richiesta** (badge colorato):
- 🟣 **Da prenotazione**: convertita da una prenotazione
- 🟢 **Prestito diretto**: creato da staff/admin
- 🔵 **Richiesta manuale**: inviata dall'utente

**Azioni disponibili**:
- ✅ **Approva**: conferma il prestito
- ❌ **Rifiuta**: nega la richiesta

### 3. Prestiti Programmati (Ciano)

Prestiti approvati con data di inizio futura.

Questi sono già confermati e inizieranno automaticamente alla data stabilita.

### 4. Prestiti Scaduti (Rosso)

Prestiti oltre la data di scadenza che richiedono attenzione.

**Azioni consigliate**:
- Contattare l'utente
- Registrare sollecito
- Valutare estensione o sollecito formale

### 5. Prenotazioni Attive (Viola)

Prenotazioni in attesa che il libro diventi disponibile.

Quando il libro viene restituito, la prenotazione si converte automaticamente in richiesta di prestito.

### 6. Prestiti in Corso (Verde)

Tabella con tutti i prestiti attivi (non scaduti):
- Titolo libro (cliccabile)
- Nome utente
- Data prestito
- Data scadenza
- Stato

### 7. Ultimi Libri Inseriti (Grigio)

Griglia con gli ultimi 4 libri aggiunti al catalogo:
- Copertina
- Titolo
- Autore
- Anno pubblicazione

Click sulla card porta alla scheda libro.

## Stato "Tutto Sotto Controllo"

Se non ci sono:
- Ritiri da confermare
- Richieste pendenti
- Prestiti scaduti

Appare un messaggio verde di conferma che non ci sono azioni urgenti.

## Modalità Catalogo

Se l'installazione è configurata in **modalità catalogo** (solo consultazione, senza prestiti), la dashboard mostra solo:
- Card: Libri, Utenti, Autori
- Sezione: Ultimi Libri Inseriti

Tutte le sezioni relative ai prestiti e prenotazioni sono nascoste.

## Responsive Design

La dashboard si adatta ai dispositivi mobili:
- Card statistiche impilate verticalmente
- Calendario in vista lista
- Sezioni prestiti in colonna singola
- Touch-friendly per le azioni

## Aggiornamento Dati

Le statistiche vengono calcolate ad ogni caricamento della pagina. Per dati aggiornati:
- Ricaricare la pagina (F5 o pull-to-refresh su mobile)
- Dopo ogni azione (approva/rifiuta/conferma) la pagina si aggiorna automaticamente

---

## Domande Frequenti (FAQ)

### 1. La dashboard si aggiorna automaticamente?

No, i dati vengono calcolati al caricamento della pagina. Per dati aggiornati:

- **Desktop**: premi F5 o ricarica la pagina
- **Mobile**: pull-to-refresh (trascina verso il basso)
- **Dopo azioni**: approva/rifiuta/conferma aggiornano automaticamente

**Nota**: Le card statistiche mostrano sempre il conteggio attuale al momento del caricamento.

---

### 2. Come sincronizzo il calendario con Google Calendar?

Usa la funzione **Sincronizza ICS**:

1. Clicca **"Sincronizza (ICS)"** nella sezione calendario
2. Scegli **"Copia Link"** per copiare l'URL del feed
3. In Google Calendar: Impostazioni → Aggiungi calendario → Da URL
4. Incolla il link copiato
5. Google aggiornerà automaticamente gli eventi

**Funziona anche con**: Apple Calendar, Outlook, Thunderbird.

---

### 3. Cosa significano i colori nel calendario?

| Colore | Significato | Azione richiesta |
|--------|-------------|------------------|
| 🟢 Verde | Prestito in corso | Nessuna, tutto ok |
| 🔵 Blu | Prestito futuro (programmato) | Nessuna |
| 🟠 Arancione | Da ritirare | Attendere ritiro utente |
| 🔴 Rosso | Scaduto / Fine periodo | Sollecitare restituzione |
| 🟡 Ambra | Richiesta pendente | Approvare o rifiutare |
| 🟣 Viola | Prenotazione | Attendere disponibilità |

---

### 4. Le card arancioni/blu pulsano, cosa significa?

Le card **"Pronti per il Ritiro"** e **"Richieste Pendenti"** hanno animazione quando il contatore è > 0:

- **Sfondo colorato**: indica azioni urgenti
- **Icona pulsante**: attira l'attenzione visiva
- **Cliccabili**: portano direttamente alla gestione

Questo indica che ci sono operazioni in attesa di intervento.

---

### 5. Come vedo rapidamente i prestiti scaduti?

Due modi:

**Dalla dashboard**:
- La sezione rossa **"Prestiti Scaduti"** elenca tutti i ritardi
- Mostra: libro, utente, email, giorni di ritardo

**Dal menu Prestiti**:
- **Prestiti → Scaduti** (se disponibile come filtro)
- Oppure filtra per stato "Scaduto" nella lista completa

---

### 6. Posso esportare le statistiche?

Attualmente le statistiche della dashboard sono solo visuali. Per export:

**Prestiti**:
- Vai in **Prestiti** → usa **Esporta CSV**
- Include tutti i dati filtrabili

**Utenti**:
- Vai in **Utenti** → usa **Esporta CSV**

**Report personalizzati**: Contatta l'amministratore di sistema o usa query database dirette.

---

### 7. La dashboard è diversa per staff e admin?

Sì, con alcune differenze:

**Staff vede**:
- Tutte le 6 card statistiche
- Tutte le sezioni operative (ritiri, richieste, scaduti)
- Azioni su prestiti

**Admin vede in più**:
- Link rapidi a configurazione
- Eventuali avvisi di sistema (aggiornamenti, backup)

La struttura principale è identica, cambiano i link disponibili nel menu.

---

### 8. In modalità catalogo cosa vedo nella dashboard?

Se la biblioteca è configurata in **modalità solo catalogo**:

**Visibile**:
- Card: Libri, Utenti, Autori
- Sezione: Ultimi Libri Inseriti

**Nascosto**:
- Card: Prestiti Attivi, Pronti per il Ritiro, Richieste Pendenti
- Calendario prestiti
- Tutte le sezioni prestiti

La dashboard diventa una panoramica del catalogo senza funzioni di circolazione.

---

### 9. Come gestisco le richieste pendenti dalla dashboard?

Nella sezione **"Richieste di Prestito"** (blu):

1. Vedi l'elenco delle richieste in attesa
2. Per ogni richiesta hai:
   - **✅ Approva**: conferma il prestito
   - **❌ Rifiuta**: nega la richiesta
3. Cliccando appare eventuale conferma
4. La pagina si aggiorna automaticamente

**Badge colorati** indicano l'origine: da prenotazione, prestito diretto, o richiesta manuale.

---

### 10. Posso cambiare l'ordine delle sezioni nella dashboard?

No, l'ordine è fisso e basato sull'urgenza:

1. **Pronti per il Ritiro** - Richiede azione fisica
2. **Richieste Pendenti** - Richiede decisione
3. **Prestiti Programmati** - Informativi (futuri)
4. **Prestiti Scaduti** - Richiede follow-up
5. **Prenotazioni Attive** - Automatiche
6. **Prestiti in Corso** - Informativo
7. **Ultimi Libri** - Informativo

Questa sequenza garantisce che le azioni urgenti siano sempre in cima.
