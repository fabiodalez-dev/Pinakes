# FAQ Operazioni Bibliotecario

Risposte rapide alle operazioni quotidiane più comuni per i bibliotecari.

## Gestione Catalogo

### Come aggiungo un nuovo libro al catalogo?

**Metodo rapido (con ISBN):**
1. Vai in **Catalogo → Nuovo Libro**
2. Inserisci l'**ISBN** nel campo dedicato
3. Clicca **Cerca** - i dati vengono recuperati automaticamente
4. Verifica titolo, autore, editore
5. Seleziona il **genere** e la **classificazione Dewey**
6. Clicca **Salva**

**Metodo manuale (senza ISBN):**
1. Vai in **Catalogo → Nuovo Libro**
2. Compila tutti i campi manualmente
3. Carica la copertina (opzionale)
4. Salva

**Provider ISBN disponibili:**
- Google Books (default)
- Open Library
- SBN Italia (richiede plugin Z39.50)

---

### Come modifico i dati di un libro esistente?

1. Cerca il libro nel catalogo
2. Clicca sul titolo per aprire la scheda
3. Clicca **Modifica** (icona matita)
4. Modifica i campi desiderati
5. Clicca **Salva modifiche**

**Campi modificabili:**
- Titolo, sottotitolo
- Autore/i (puoi aggiungerne multipli)
- Editore, anno, pagine
- Descrizione, genere, Dewey
- Copertina

---

### Come elimino un libro dal catalogo?

⚠️ **Attenzione:** L'eliminazione è permanente!

1. Apri la scheda del libro
2. Clicca **Elimina** (icona cestino)
3. Conferma l'operazione

**Prerequisiti:**
- Il libro non deve avere prestiti attivi
- Le copie associate vengono eliminate
- Le prenotazioni vengono annullate

**Alternativa consigliata:** Invece di eliminare, imposta tutte le copie come "Ritirato" per mantenere lo storico.

---

### Come gestisco le copie fisiche di un libro?

Un libro può avere più copie fisiche:

**Aggiungere una copia:**
1. Apri la scheda del libro
2. Sezione **Copie** → clicca **Aggiungi Copia**
3. Inserisci:
   - Numero inventario (opzionale, generato automaticamente)
   - Collocazione (scaffale/mensola)
   - Stato (Buono, Discreto, ecc.)
4. Salva

**Modificare una copia:**
1. Nella sezione Copie, clicca sulla copia
2. Modifica i campi
3. Salva

**Stati disponibili:**
| Stato | Significato |
|-------|-------------|
| Disponibile | Pronta per il prestito |
| In prestito | Attualmente prestata |
| In riparazione | Non disponibile temporaneamente |
| Riservata | Prenotata da un utente |
| Ritirata | Non più in circolazione |
| Smarrita | Persa, da non conteggiare |

---

### Come assegno la classificazione Dewey a un libro?

**Metodo 1 - Inserimento diretto:**
1. Nel campo Dewey, digita il codice (es. `823.914`)
2. Clicca **Aggiungi**
3. Il sistema mostra il nome della categoria se riconosciuto

**Metodo 2 - Navigazione gerarchica:**
1. Clicca su **Oppure naviga per categorie**
2. Seleziona la classe principale (es. `800 Letteratura`)
3. Seleziona la divisione (es. `820 Letteratura inglese`)
4. Continua fino al livello desiderato
5. Clicca **Seleziona**

**Codici comuni:**
| Codice | Categoria |
|--------|-----------|
| 800 | Letteratura |
| 823 | Narrativa inglese |
| 853 | Narrativa italiana |
| 500 | Scienze |
| 900 | Storia e geografia |

---

## Gestione Prestiti

### Come registro un nuovo prestito?

**Dalla scheda libro:**
1. Apri la scheda del libro
2. Clicca **Presta** su una copia disponibile
3. Cerca l'utente per nome o tessera
4. Seleziona l'utente
5. Conferma il prestito

**Dalla pagina Prestiti:**
1. Vai in **Prestiti → Nuovo Prestito**
2. Cerca l'utente
3. Cerca il libro
4. Seleziona la copia
5. Conferma

**Consegna immediata:**
Se l'impostazione è attiva, il prestito passa direttamente a "In corso" senza approvazione.

---

### Come registro la restituzione di un libro?

1. Vai in **Prestiti → Gestione Prestiti**
2. Trova il prestito (cerca per utente o libro)
3. Clicca **Restituisci** (icona check)
4. Conferma la restituzione

**Oppure dalla scheda utente:**
1. Apri la scheda dell'utente
2. Sezione **Prestiti Attivi**
3. Clicca **Restituisci** sul prestito

**Cosa succede:**
- La copia torna disponibile
- Lo storico prestiti viene aggiornato
- Se c'è una prenotazione, l'utente in coda viene notificato

---

### Come gestisco un prestito in ritardo?

1. Vai in **Prestiti → Gestione Prestiti**
2. Filtra per **Stato: In ritardo**
3. Per ogni prestito in ritardo:

**Opzioni:**
- **Invia sollecito**: Clicca l'icona email per inviare un promemoria
- **Estendi**: Concedi giorni aggiuntivi
- **Registra restituzione**: Se il libro è stato restituito

**Email automatiche:**
Il sistema può inviare solleciti automatici se configurato (vedi Impostazioni → Email).

---

### Come approvo o rifiuto una richiesta di prestito?

Se la consegna immediata è disattivata:

1. Vai in **Prestiti → Richieste in Attesa**
2. Per ogni richiesta:
   - **Approva**: Il prestito diventa attivo
   - **Rifiuta**: Il prestito viene annullato (inserisci motivazione)

**Notifiche:**
L'utente riceve un'email sia in caso di approvazione che di rifiuto.

---

### Come rinnovo un prestito?

1. Vai in **Prestiti → Gestione Prestiti**
2. Trova il prestito attivo
3. Clicca **Rinnova** (icona freccia circolare)

**Condizioni:**
- Il libro non deve avere prenotazioni in coda
- Non deve aver superato il numero massimo di rinnovi
- Non deve essere in ritardo

**Impostazione rinnovi:**
In **Impostazioni → Prestiti** puoi configurare:
- Numero massimo rinnovi consentiti
- Durata estensione (giorni)

---

## Gestione Utenti

### Come registro un nuovo utente?

1. Vai in **Utenti → Nuovo Utente**
2. Compila i campi:
   - Nome e cognome
   - Email (obbligatoria)
   - Password (generata o manuale)
   - Ruolo (Standard, Premium, Staff, Admin)
3. Clicca **Crea Utente**

**Approvazione:**
Se abilitata, i nuovi utenti registrati autonomamente richiedono approvazione prima di poter usare il sistema.

---

### Come cerco un utente?

**Dalla barra di ricerca:**
1. Vai in **Utenti**
2. Usa la barra di ricerca per:
   - Nome o cognome
   - Email
   - Numero tessera

**Filtri avanzati:**
- Per ruolo (Admin, Staff, Premium, Standard)
- Per stato (Attivo, Sospeso, In attesa)
- Per data registrazione

---

### Come sospendo un utente?

1. Vai in **Utenti → [utente] → Modifica**
2. Cambia lo stato da "Attivo" a "Sospeso"
3. Inserisci una motivazione (opzionale)
4. Salva

**Effetti:**
- L'utente non può effettuare prestiti
- Non può fare nuove prenotazioni
- I prestiti attivi rimangono tali (vanno gestiti separatamente)

---

### Come stampo la tessera biblioteca?

1. Apri la scheda dell'utente
2. Clicca **Stampa Tessera** (o icona tessera)
3. Si apre l'anteprima di stampa
4. Stampa

**Contenuto tessera:**
- Nome e cognome
- Numero tessera
- Codice a barre (scansionabile)
- Data scadenza (se configurata)

---

## Gestione Prenotazioni

### Come gestisco la coda delle prenotazioni?

1. Vai in **Prenotazioni**
2. Visualizzi tutte le prenotazioni ordinate per data

**Quando un libro viene restituito:**
1. Il sistema notifica automaticamente il primo in coda
2. L'utente ha N giorni per ritirare (configurabile)
3. Se non ritira, passa al successivo

**Annullare una prenotazione:**
1. Clicca sulla prenotazione
2. Clicca **Annulla**
3. Inserisci motivazione

---

### Come verifico se un libro ha prenotazioni?

1. Apri la scheda del libro
2. Sezione **Prenotazioni**: mostra la coda

**Oppure:**
1. Vai in **Prenotazioni**
2. Filtra per libro

**Indicatori:**
- Badge con numero prenotazioni nella lista libri
- Icona prenotazione nella scheda libro

---

## Operazioni Quotidiane

### Come faccio il check-in mattutino?

**Checklist giornaliera consigliata:**

1. **Controlla prestiti in scadenza oggi:**
   - Prestiti → Filtro: "Scade oggi"
   - Invia promemoria se necessario

2. **Verifica restituzioni in ritardo:**
   - Prestiti → Filtro: "In ritardo"
   - Invia solleciti

3. **Gestisci prenotazioni pronte:**
   - Prenotazioni → "Pronte per ritiro"
   - Contatta utenti che non hanno ancora ritirato

4. **Approva richieste pendenti:**
   - Prestiti → Richieste in attesa
   - Utenti → In attesa di approvazione

---

### Come esporto i dati per report?

**Export Prestiti:**
1. Vai in **Prestiti → Gestione Prestiti**
2. Applica i filtri desiderati
3. Clicca **Esporta CSV**

**Export Utenti:**
1. Vai in **Utenti**
2. Filtra se necessario
3. Clicca **Esporta CSV**

**Export Catalogo:**
1. Vai in **Catalogo**
2. Clicca **Esporta**
3. Scegli formato (CSV)

---

### Come cerco velocemente un libro per un utente?

**Dalla homepage catalogo:**
1. Usa la barra di ricerca globale
2. Cerca per titolo, autore, ISBN
3. I risultati mostrano disponibilità in tempo reale

**Ricerca avanzata:**
1. Catalogo → Ricerca Avanzata
2. Combina filtri:
   - Genere
   - Autore
   - Editore
   - Anno pubblicazione
   - Disponibilità

---

### Come gestisco un libro smarrito o danneggiato?

**Libro smarrito:**
1. Apri la scheda del libro → Copie
2. Trova la copia interessata
3. Cambia stato da "In prestito" a "Smarrita"
4. (Opzionale) Registra nel prestito la nota "Smarrito"
5. Chiudi il prestito come "Restituito con note"

**Libro danneggiato:**
1. Cambia stato copia a "In riparazione" o "Ritirata"
2. Se riparabile, torna a "Disponibile" dopo la riparazione
3. Se irreparabile, lascia "Ritirata"

---

### Come consulto lo storico di un utente?

1. Apri la scheda dell'utente
2. Sezioni disponibili:
   - **Prestiti Attivi**: prestiti correnti
   - **Storico Prestiti**: tutti i prestiti passati
   - **Prenotazioni**: prenotazioni attive e passate
   - **Wishlist**: libri desiderati

**Filtri storico:**
- Per periodo (ultimo mese, anno, sempre)
- Per stato (completati, in ritardo, annullati)

---

## Risoluzione Problemi Comuni

### L'utente dice di aver restituito ma il sistema mostra ancora il prestito

1. Verifica fisicamente che il libro sia sullo scaffale
2. Se presente:
   - Vai in Prestiti → trova il prestito
   - Clicca **Restituisci**
   - Aggiungi nota: "Restituzione registrata manualmente"
3. Se non presente, il libro potrebbe essere smarrito

---

### Non riesco a prestare un libro "disponibile"

**Cause possibili:**

| Problema | Soluzione |
|----------|-----------|
| Utente sospeso | Riattiva l'utente |
| Utente ha troppi prestiti | Verifica limite in Impostazioni |
| Libro riservato | Controlla coda prenotazioni |
| Copia in stato errato | Correggi lo stato della copia |

---

### L'ISBN non trova risultati

1. Verifica che l'ISBN sia corretto (10 o 13 cifre)
2. Prova senza trattini
3. Prova un provider diverso (Open Library, SBN)
4. Se il libro è molto vecchio o raro, inserisci manualmente

---

### Come annullo un prestito registrato per errore?

1. Vai in **Prestiti → Gestione Prestiti**
2. Trova il prestito
3. Clicca **Annulla** (se disponibile)
4. Oppure: **Restituisci** immediatamente

**Nota:** Lo storico manterrà traccia dell'operazione per trasparenza.
