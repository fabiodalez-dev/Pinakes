# Changelog - Pinakes

Storico delle modifiche e delle nuove funzionalità di Pinakes.

---

## [0.4.1] - 10 Dicembre 2025

### Ricerca e Importazione Libri

**Miglioramento gestione ISBN**
- Il sistema ora riconosce automaticamente se un codice è ISBN-10 o ISBN-13
- Conversione automatica tra i due formati quando possibile (nota: gli ISBN che iniziano con 979 non possono essere convertiti in ISBN-10)
- Quando cerchi un libro con un formato ISBN, il sistema prova automaticamente anche l'altro formato per trovare più informazioni
- I campi ISBN-10 e ISBN-13 nel modulo libro vengono ora compilati correttamente quando importi dati

**Nuova fonte di ricerca: Ubik Libri**
- Aggiunta una nuova fonte italiana per la ricerca di informazioni sui libri
- Include automaticamente le biografie degli autori quando disponibili
- La biografia dell'autore viene salvata nel suo profilo (solo se non ne ha già una)

**Arricchimento automatico**
- Quando cerchi un libro e una fonte non ha la classificazione Dewey, il sistema prova automaticamente altre fonti per trovarla
- Miglior recupero delle copertine da fonti esterne con validazione automatica

### Gestione Prestiti

**Link diretti ai libri**
- Nella pagina di dettaglio prestito ora puoi cliccare sul titolo del libro per andare direttamente alla sua scheda
- Anche nella pagina di modifica prestito il titolo del libro è cliccabile
- Più facile navigare tra prestiti e catalogo

**Preselezione utente**
- Quando crei un nuovo prestito dalla pagina di un utente, l'utente viene automaticamente preselezionato
- Non devi più cercarlo manualmente nel menu a tendina

**Calendario migliorato**
- Il calendario per selezionare le date di scadenza ora mostra colori diversi in base alla disponibilità del libro
- Più facile scegliere date appropriate per il prestito

### Catalogo Pubblico

**Filtri più puliti**
- I filtri per genere nel catalogo nascondono automaticamente le categorie che non contengono libri
- Interfaccia più ordinata senza opzioni inutili

**Formattazione date migliorata**
- Tutte le date vengono ora mostrate nel formato italiano (GG-MM-AAAA) in modo coerente
- Sia nel pannello amministrativo che nel catalogo pubblico

### Pannello Amministrativo

**Gestione recensioni migliorata**
- Aggiunta la possibilità di eliminare le recensioni con conferma
- I pulsanti vengono disabilitati durante l'elaborazione per evitare doppi clic
- Messaggi di errore più chiari

**Ordinamento tabelle**
- L'elenco libri nel pannello admin ora supporta l'ordinamento cliccando sulle colonne
- Puoi ordinare per titolo, genere, collocazione e anno

### Correzioni

- **Pagina "Chi siamo"**: Risolto un problema che causava un ciclo infinito di reindirizzamento
- **Registrazione eventi**: Rimossa una duplicazione nelle rotte che poteva causare errori
- **Banner cookie**: Migliorata l'affidabilità del caricamento con sistema di riprova automatico
- **Sicurezza**: Migliorata la gestione dei token di sicurezza per le richieste
- **Profilo utente**: Risolto un problema che impediva l'aggiornamento del profilo in alcuni casi
- **Gestione utenti**: Corretta la validazione del campo "sesso" che causava errori di salvataggio

### Dati Dewey

- Aggiunte classificazioni mancanti per la letteratura araba (892.7, 892.73)
- Aggiunte classificazioni per i partiti politici (324.2, 324.21)
- Normalizzata la punteggiatura in tutte le voci

---

## [0.4.0] - 8 Dicembre 2025

### Conformità GDPR

**Tracciamento consenso privacy**
- Durante la registrazione, gli utenti devono ora accettare l'informativa sulla privacy
- Il sistema registra la data e la versione dell'informativa accettata
- Gli amministratori possono vedere lo stato del consenso per ogni utente
- Gli utenti esistenti vengono automaticamente segnati come "consenso accettato" con la data di registrazione

**Registro dei consensi**
- Nuova tabella di log per tracciare tutti i consensi (richiesta GDPR Articolo 7)
- Possibilità di tracciare richieste di esportazione o cancellazione dati

### Sessioni "Ricordami"

**Login persistente**
- Nuova opzione "Ricordami" durante il login
- Rimani connesso per 30 giorni anche se chiudi il browser
- I token sono memorizzati in modo sicuro con crittografia

**Gestione sessioni nel profilo**
- Puoi vedere tutti i dispositivi dove sei connesso
- Puoi disconnettere singoli dispositivi o tutti contemporaneamente
- Vedi quando e da dove ti sei collegato l'ultima volta

### Sistema di Aggiornamento

**Miglioramenti all'updater automatico**
- Il sistema ora crea automaticamente un backup completo prima di ogni aggiornamento
- Se qualcosa va storto, viene ripristinato automaticamente il backup
- Blocco automatico per impedire aggiornamenti simultanei
- Log dettagliato di tutte le operazioni di aggiornamento

**Controllo integrità database**
- Nuova sezione "Integrità" nel pannello manutenzione
- Mostra se mancano tabelle di sistema
- Pulsante per creare automaticamente le tabelle mancanti

### Manutenzione Automatica

**Nuovi task automatici**
- Pulizia automatica delle sessioni "Ricordami" scadute
- Ricalcolo disponibilità libri (ogni giorno)
- Controllo e creazione indici database (ogni settimana)
- Sistema di blocco per evitare esecuzioni simultanee del cron

### Importazione Libri

**Selezione fonti alternative**
- Quando cerchi un ISBN, ora vedi da quale fonte provengono i dati
- Puoi scegliere tra più fonti se disponibili
- Applica singoli campi da fonti diverse (es. copertina da una fonte, descrizione da un'altra)

**Pulizia dati migliorata**
- Rimossi automaticamente caratteri di controllo illeggibili dai titoli
- I nomi degli autori vengono normalizzati (es. "Rossi, Mario" diventa "Mario Rossi")
- Rimozione automatica di autori duplicati durante l'importazione

### Interfaccia Prestiti

**Restituzione più chiara**
- Il modulo di restituzione ora ha opzioni più chiare:
  - "Restituito regolarmente" (in tempo)
  - "Restituito in ritardo"
- Lo stato predefinito è "Restituito" invece di "In corso"

**Nuovi stati prestito**
- Aggiunto stato "In attesa di approvazione" per le richieste pendenti
- Aggiunto stato "Prenotato" per distinguere dalle prenotazioni in coda
- Badge colorati per ogni stato nel pannello admin

### Correzioni

- **Titoli libri**: Rimossi caratteri speciali illeggibili che apparivano in alcuni titoli importati
- **Notifiche email**: Migliorata l'affidabilità con sistema di riprova automatico
- **Indici database**: Aggiunti tutti gli indici mancanti per prestazioni migliori
- **Generi in inglese**: Ripristinate le traduzioni inglesi dei generi letterari
- **Template email**: Allineate le impostazioni tra versione italiana e inglese
- **Sidebar admin**: Corretta la visualizzazione del titolo nel menu laterale

### Sicurezza

- Migliorata la gestione degli errori in tutto il sistema di autenticazione
- Controlli più robusti per prevenire problemi di sicurezza nelle sessioni
- Escape completo degli attributi HTML per prevenire vulnerabilità XSS

---

## [0.3.0] - 5 Dicembre 2025

### Classificazione Dewey

**Nuovo sistema basato su JSON**
- La classificazione Dewey non usa più una tabella database
- I dati sono ora memorizzati in file JSON facilmente modificabili
- Supporto completo per italiano e inglese (1.276 voci per lingua)

**Plugin Dewey Editor**
- Nuovo editor visuale ad albero per gestire le classificazioni
- Possibilità di aggiungere, modificare o eliminare categorie
- Import/export dei dati per backup o condivisione tra installazioni

### Sistema di Aggiornamento Automatico

**Updater integrato**
- Nuovo pannello Admin → Aggiornamenti per verificare e installare nuove versioni
- Backup automatico del database prima di ogni aggiornamento
- Rollback automatico in caso di errore
- Supporto per aggiornamenti incrementali (salta versioni)

### Gestione Autori e Editori

**Normalizzazione nomi**
- I nomi degli autori vengono automaticamente normalizzati durante l'importazione
- Formato "Cognome, Nome" convertito in "Nome Cognome"

**Funzione di unione**
- Possibilità di unire autori o editori duplicati
- Tutti i libri vengono automaticamente riassegnati

### Breaking Changes

**Modifica schema database**: La colonna `classificazione_dowey` è stata rinominata in `classificazione_dewey` (correzione typo).

Per installazioni esistenti, esegui la migrazione PRIMA di aggiornare:

```bash
mysql -u UTENTE -p DATABASE < installer/database/migrations/migrate_0.3.0.sql
```

### Rimosso

- Tabella `classificazione` - I dati Dewey sono ora in file JSON
- `DeweyController.php` - Sostituito da `DeweyApiController.php`
- Colonne chiave esterna dalle tabelle `scaffali` e `mensole`

---

## Come Aggiornare

### Da versione 0.4.0 a 0.4.1
Usa semplicemente il pannello **Admin → Aggiornamenti**. L'aggiornamento è automatico e non richiede modifiche al database.

### Da versione 0.3.x a 0.4.x
1. **Fai un backup del database** (molto importante!)
2. Esegui la migrazione:
   ```bash
   mysql -u UTENTE -p DATABASE < installer/database/migrations/migrate_0.4.0.sql
   ```
3. Sostituisci i file dell'applicazione
4. Visita **Admin → Manutenzione → Integrità** per verificare che tutte le tabelle siano presenti

### Da versione 0.2.x o precedenti
1. **Fai un backup del database**
2. Esegui le migrazioni in ordine:
   ```bash
   mysql -u UTENTE -p DATABASE < installer/database/migrations/migrate_0.3.0.sql
   mysql -u UTENTE -p DATABASE < installer/database/migrations/migrate_0.4.0.sql
   ```
3. Sostituisci i file dell'applicazione

---

## Note Tecniche

- **Versione PHP richiesta**: 8.1 o superiore
- **Database**: MySQL 5.7+ o MariaDB 10.3+
- **La versione 0.4.1 non include modifiche al database**

---

Per segnalare problemi o richiedere nuove funzionalità:
- GitHub Issues: https://github.com/fabiodalez-dev/pinakes/issues
- Email: pinakes@fabiodalez.it
