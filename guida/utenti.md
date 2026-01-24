# Gestione Utenti

Guida alla gestione degli utenti e dei ruoli.

## Ruoli

Il sistema supporta 4 ruoli con permessi progressivi:

| Ruolo | Campo DB | Descrizione | Permessi |
|-------|----------|-------------|----------|
| **Standard** | `standard` | Lettore base | Cerca libri, richiede prestiti, gestisce profilo |
| **Premium** | `premium` | Lettore privilegiato | Come standard + eventuali limiti aumentati |
| **Staff** | `staff` | Personale biblioteca | + Approva prestiti, inserisce libri, gestisce utenti |
| **Admin** | `admin` | Amministratore | + Configurazione, plugin, backup, statistiche |

## Registrazione Utenti

### Self-Service

1. L'utente accede alla pagina di registrazione
2. Compila i dati richiesti
3. Riceve email di verifica
4. Clicca il link per attivare l'account

### Registrazione Manuale

Operatori e admin possono creare utenti:

1. Vai in **Utenti → Nuovo utente**
2. Compila i dati
3. Scegli se:
   - Inviare email con credenziali
   - Impostare password manualmente
4. Assegna il ruolo appropriato
5. Salva

## Gestione Profili

### Modifica Utente

1. Vai in **Utenti**
2. Cerca l'utente
3. Clicca sul nome
4. Modifica i campi necessari
5. Salva

### Campi Disponibili

| Campo | Descrizione |
|-------|-------------|
| Nome | Nome e cognome |
| Email | Indirizzo email (login) |
| Telefono | Contatto telefonico |
| Ruolo | Livello di accesso |
| Note | Note interne (non visibili all'utente) |
| Attivo | Abilita/disabilita accesso |

## Verifica Email

### Processo

1. L'utente riceve email con link
2. Clicca il link entro 24 ore
3. L'account viene attivato

### Rinvio Verifica

Se l'email non arriva:
1. L'utente clicca "Rinvia email di verifica"
2. Oppure l'operatore può verificare manualmente

### Verifica Manuale

1. Apri il profilo utente
2. Clicca **Verifica manualmente**
3. L'account viene attivato immediatamente

## Blocco Utenti

### Quando Bloccare

- Prestiti non restituiti ripetutamente
- Violazione regolamento biblioteca
- Richiesta dell'utente

### Come Bloccare

1. Apri il profilo utente
2. Disattiva l'opzione "Attivo"
3. Opzionalmente aggiungi nota con motivazione
4. Salva

L'utente bloccato:
- Non può effettuare login
- Mantiene lo storico prestiti
- Può essere riattivato in qualsiasi momento

## Storico Utente

Per ogni utente puoi visualizzare:
- Prestiti attivi
- Prestiti passati
- Prenotazioni in coda

### Accesso allo Storico

1. Apri il profilo utente
2. Seleziona la tab "Storico"
3. Filtra per data o stato

## Import Utenti

Per importazioni massive:

1. Vai in **Utenti → Import**
2. Carica file CSV con colonne:
   - `nome`, `cognome`, `email`, `telefono`
3. Mappa le colonne
4. Scegli se inviare email di benvenuto
5. Conferma l'import

---

## Domande Frequenti (FAQ)

### 1. Qual è la differenza tra i ruoli Standard e Premium?

Entrambi sono ruoli per lettori, ma **Premium** può avere limiti aumentati:
- Più prestiti contemporanei
- Durata prestito più lunga
- Priorità nelle prenotazioni (configurabile)

**Quando usare Premium**:
- Membri con abbonamento a pagamento
- Tesserati fedeli
- Studenti/ricercatori con esigenze particolari

Le differenze esatte dipendono dalla configurazione in **Impostazioni → Prestiti**.

---

### 2. Un utente non riceve l'email di verifica, cosa faccio?

Possibili cause e soluzioni:

1. **Controlla la cartella spam** dell'utente
2. **Rinvia l'email**: Dalla lista utenti, clicca "Rinvia verifica"
3. **Verifica manuale**: Apri il profilo utente → "Verifica manualmente"
4. **Controlla configurazione email**: Tab Email nelle impostazioni

Se nessuna email parte, controlla i log: `storage/logs/app.log`

---

### 3. Come resetto la password di un utente?

Due opzioni:

**Self-service (consigliato)**:
- L'utente usa "Password dimenticata" nella pagina login
- Riceve email con link per reimpostare

**Manuale (operatore)**:
1. Apri il profilo utente
2. Clicca "Reimposta password"
3. Inserisci la nuova password
4. Opzionale: invia notifica all'utente

---

### 4. Come elimino un utente dal sistema?

Per motivi di integrità dati, gli utenti con storico prestiti non possono essere eliminati completamente:

1. **Disattivazione** (consigliata): Disattiva l'utente → mantiene storico
2. **Anonimizzazione**: Modifica i dati personali con placeholder (es. "Utente Rimosso")
3. **Eliminazione** (se senza prestiti): Apri profilo → Elimina

**GDPR**: Per richieste di cancellazione, usa l'anonimizzazione che preserva le statistiche.

---

### 5. Posso importare utenti da un altro gestionale?

Sì, tramite import CSV:

1. Esporta dal vecchio sistema in formato CSV
2. Assicurati che contenga almeno: `email`, `nome`, `cognome`
3. Vai in **Utenti → Import**
4. Mappa le colonne ai campi Pinakes
5. Scegli se inviare email di benvenuto

**Formati supportati**:
- Separatore: virgola, punto e virgola o tab
- Encoding: UTF-8 (consigliato)

---

### 6. Come assegno permessi staff a un utente esistente?

1. Vai in **Utenti** e cerca l'utente
2. Apri il profilo
3. Nel campo **Ruolo**, seleziona "Staff"
4. Salva le modifiche

L'utente avrà immediatamente accesso alle funzioni staff (gestione prestiti, catalogo, ecc.) al prossimo login.

---

### 7. Un utente vuole cambiare la sua email, come procedo?

L'email è l'identificativo di login, quindi:

1. Apri il profilo utente
2. Modifica il campo **Email** con il nuovo indirizzo
3. Salva
4. Opzionale: rinvia l'email di verifica

**Nota**: L'utente dovrà usare la nuova email per accedere.

---

### 8. Come vedo rapidamente tutti gli utenti con prestiti in ritardo?

Due metodi:

**Dalla dashboard**:
- La sezione "Prestiti in ritardo" elenca anche gli utenti coinvolti

**Dalla lista utenti**:
1. Vai in **Utenti**
2. Usa il filtro "Con prestiti in ritardo"
3. Visualizzi solo utenti con almeno un ritardo attivo

---

### 9. Posso creare ruoli personalizzati oltre ai 4 predefiniti?

No, il sistema usa 4 ruoli fissi (Standard, Premium, Staff, Admin). Tuttavia:

- I **limiti** per Standard/Premium sono configurabili
- Puoi usare le **note** per categorizzare ulteriormente (es. "Studente", "Anziano")
- Per esigenze complesse, valuta un plugin personalizzato

---

### 10. Come gestisco tessere biblioteca fisiche?

Pinakes supporta numeri tessera per collegare utenti digitali a tessere fisiche:

1. Modifica il profilo utente
2. Inserisci il **Numero Tessera** (campo personalizzato)
3. Usa la ricerca per trovare utenti per numero tessera

**Suggerimento**: Usa un lettore barcode per scansionare le tessere e cercare rapidamente gli utenti al bancone.
