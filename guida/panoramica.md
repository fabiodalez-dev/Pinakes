# Panoramica

Pinakes è un sistema completo per la gestione bibliotecaria (ILS - Integrated Library System).

## Funzionalità Principali

### Catalogo
- Inserimento libri con ricerca ISBN automatica
- Metadati da Google Books, Open Library, SBN Italia
- Classificazione Dewey integrata (1.287 categorie)
- Gestione autori, editori, generi
- Upload copertine

### Prestiti
- Workflow completo: richiesta → approvazione → ritiro → restituzione
- Gestione code e prenotazioni
- Notifiche email automatiche
- Rinnovi con limiti configurabili

### Utenti
- Registrazione self-service o manuale
- 4 ruoli: standard, premium, staff, admin
- Cronologia prestiti personale
- Verifica email e tessera biblioteca

### Risorse Digitali
- Campo `file_url` per documenti allegati
- Campo `audio_url` per audiolibri
- Link esterni a risorse digitali

### Eventi
- Calendario attività biblioteca
- Pagine evento con SEO dedicato
- Immagini e descrizioni

## Interfaccia

### Catalogo Pubblico
Accessibile senza login:
- Ricerca libri
- Visualizzazione disponibilità
- Dettaglio libro

### Area Utente
Dopo il login:
- Richiesta prestiti
- Prenotazioni
- Storico personale
- Modifica profilo

### Pannello Operatore
Per il personale biblioteca:
- Approvazione prestiti
- Gestione ritiri/restituzioni
- Inserimento libri
- Gestione utenti

### Pannello Admin
Per gli amministratori:
- Configurazione sistema
- Gestione plugin
- Backup
- Statistiche

## Navigazione

Usa il menu laterale per accedere alle sezioni:
- **Catalogo**: ricerca e gestione libri
- **Prestiti**: gestione prestiti attivi
- **Utenti**: anagrafica utenti
- **Impostazioni**: configurazione sistema

---

## Domande Frequenti (FAQ)

### 1. Pinakes è gratuito?

Sì, Pinakes è **completamente gratuito e open source**:

- Licenza: MIT (libertà di uso, modifica, distribuzione)
- Nessun costo di licenza
- Nessuna funzionalità a pagamento
- Codice sorgente disponibile su GitHub

Puoi usarlo per biblioteche pubbliche, scolastiche, private o aziendali senza limitazioni.

---

### 2. Quali tipi di biblioteca possono usare Pinakes?

Pinakes è adatto a:

| Tipo biblioteca | Funzionalità principali |
|-----------------|------------------------|
| **Scolastica** | Catalogo, prestiti studenti, tessere |
| **Pubblica** | Self-service, prenotazioni, eventi |
| **Aziendale** | Catalogo interno, risorse digitali |
| **Privata** | Gestione collezione personale |
| **Associazione** | Prestiti soci, multiutente |

Scalabile da poche decine a migliaia di libri.

---

### 3. Serve conoscere la programmazione per usare Pinakes?

**Per l'uso quotidiano**: No, l'interfaccia è pensata per bibliotecari senza competenze tecniche.

**Per l'installazione**: Servono competenze base di hosting (caricare file, creare database) oppure un tecnico che faccia il setup iniziale.

**Per personalizzazioni avanzate**: Sì, modifiche al codice richiedono conoscenze PHP.

---

### 4. Posso usare Pinakes solo come catalogo senza prestiti?

Sì, esiste la **modalità catalogo**:

1. Vai in **Impostazioni → Avanzate**
2. Attiva **"Solo catalogo"**
3. Tutte le funzioni prestito vengono nascoste

Utile per:
- Cataloghi consultabili online
- Biblioteche senza circolazione
- Archivi storici

---

### 5. Quanti libri può gestire Pinakes?

Non c'è un limite tecnico imposto. Performance testate:

| Catalogo | Performance |
|----------|-------------|
| < 1.000 libri | Eccellente |
| 1.000 - 10.000 | Ottima |
| 10.000 - 50.000 | Buona (consigliato server dedicato) |
| > 50.000 | Richiede ottimizzazione server |

Il fattore limitante è l'hosting, non il software.

---

### 6. Pinakes funziona su smartphone?

Sì, l'interfaccia è **completamente responsive**:

- **Utenti**: ricerca catalogo, richieste prestiti, profilo
- **Staff**: approvazioni, gestione prestiti, inserimento libri
- **Admin**: tutte le funzioni (consigliato desktop per configurazioni complesse)

Funziona su qualsiasi browser moderno (Chrome, Firefox, Safari, Edge).

---

### 7. Posso importare libri da un altro gestionale?

Sì, tramite **import CSV**:

1. Esporta dal vecchio sistema in formato CSV
2. Vai in **Catalogo → Import**
3. Mappa le colonne ai campi Pinakes
4. Importa

**Campi supportati**: ISBN, titolo, autori, editore, anno, genere, Dewey, descrizione.

---

### 8. Come funziona la ricerca ISBN automatica?

Quando inserisci un ISBN:

1. Pinakes cerca su **Google Books**
2. Se non trova, prova **Open Library**
3. Se non trova, prova **SBN Italia** (catalogo italiano)
4. Compila automaticamente: titolo, autore, editore, anno, descrizione, copertina

Funziona per libri con ISBN valido (10 o 13 cifre).

---

### 9. Posso avere più sedi/biblioteche con un'unica installazione?

Attualmente Pinakes gestisce **una biblioteca per installazione**. Per più sedi:

**Opzione 1 - Installazioni separate**:
- Una installazione per sede
- Database separati
- URL diversi

**Opzione 2 - Usa le posizioni/scaffali**:
- Campo "Posizione" per indicare la sede
- Unico catalogo condiviso
- Filtra per posizione nella ricerca

---

### 10. Quali lingue sono supportate?

Pinakes supporta:

- **Italiano** (lingua principale)
- **Inglese** (traduzione completa)

L'interfaccia cambia lingua automaticamente in base alle preferenze utente o alle impostazioni di sistema.

**Aggiungere nuove lingue**: Crea un nuovo file in `locale/` seguendo il formato JSON esistente.
