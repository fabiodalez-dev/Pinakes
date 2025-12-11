# 🤖 Scraping Automatico - Recupero Metadati Libri

## Cos'è lo Scraping?

Lo **scraping** (o "web scraping") è una tecnica informatica che permette al sistema di connettersi automaticamente a siti web specializzati in libri, cercare un libro tramite il suo ISBN/EAN e importare tutti i dati pubblicamente disponibili senza doverli digitare manualmente.

**In parole semplici:** Il sistema "legge" le pagine web come faresti tu, ma in modo automatico e istantaneo, recuperando titolo, autore, copertina, descrizione e molto altro in pochi secondi.

## 🌐 Fonti di Scraping

Pinakes interroga diverse fonti online affidabili e ufficiali per recuperare i dati. A partire dalla versione 0.4.1, il sistema supporta:

### 1. Google Books
- **URL:** books.google.com
- **Affidabilità:** ⭐⭐⭐⭐⭐ (Molto alta)
- **Lingua:** Internazionale
- **Dati forniti:**
  - Database mondiale di libri
  - Metadati editoriali completi
  - Copertine ad alta risoluzione
  - Descrizioni e anteprime
  - Classificazioni e categorie

### 2. Open Library
- **URL:** openlibrary.org
- **Affidabilità:** ⭐⭐⭐⭐ (Alta)
- **Lingua:** Internazionale
- **Dati forniti:**
  - Catalogo internazionale collaborativo
  - Edizioni multiple e varianti
  - Copertine alternative
  - Dati bibliografici standard

### 3. Sistema Bibliotecario Nazionale (SBN)
- **URL:** sbn.it
- **Affidabilità:** ⭐⭐⭐⭐⭐ (Molto alta per libri italiani)
- **Lingua:** Italiano
- **Dati forniti:**
  - Catalogo nazionale italiano
  - Metadati bibliografici ufficiali
  - Classificazioni standard
  - Informazioni editoriali italiane

**Nota importante:** Il sistema non utilizza più fonti commerciali specifiche come Libreria Universitaria, Feltrinelli o Ubik. Le fonti sono limitate a database bibliografici aperti e affidabili.

## ⚙️ Come Funziona il Processo di Scraping

Quando clicchi "Importa Dati" dopo aver inserito un ISBN, ecco cosa succede nei 3-5 secondi di attesa:

### 1. Validazione (0.5 secondi)
- Il sistema controlla che l'ISBN/EAN sia formattato correttamente
- Rimuove automaticamente spazi, trattini e caratteri non necessari
- Verifica che non sia già presente nel database
- Converte automaticamente tra ISBN-10 e ISBN-13 quando possibile

### 2. Ricerca Multi-Fonte (2-3 secondi)
- Il sistema interroga le fonti in parallelo per massimizzare la velocità
- Ogni fonte viene contattata con timeout di 5 secondi
- Le risposte vengono raccolte e normalizzate

### 3. Consolidamento Dati (0.5 secondi)
- Il sistema confronta i dati da tutte le fonti
- Sceglie la versione più completa e affidabile per ogni campo
- Se una fonte manca di un dato (es. copertina), prova le altre
- Privilegia fonti più affidabili per dati critici (titolo, autore)

### 4. Arricchimento Automatico (0.5 secondi)
- Se manca la classificazione Dewey, il sistema prova altre fonti
- Le biografie degli autori vengono recuperate quando disponibili
- Le copertine vengono validate e ottimizzate

### 5. Pulizia e Formattazione (0.5 secondi)
- Pulizia dei dati da caratteri speciali (es. caratteri MARC-8)
- Normalizzazione nomi autori (da "Cognome, Nome" a "Nome Cognome")
- Conversione date in formato italiano leggibile
- Standardizzazione prezzi in euro
- Ottimizzazione immagini copertine

### 6. Popolamento Form (0.5 secondi)
- Compila automaticamente tutti i campi del form
- Mostra anteprima della copertina
- Evidenzia eventuali campi mancanti

## ✅ Cosa Viene Importato Automaticamente

| Campo | Google Books | Open Library | SBN | Note |
|-------|-------------|--------------|-----|------|
| **Titolo** | ✅ | ✅ | ✅ | Sempre disponibile |
| **Sottotitolo** | ✅ | ⚠️ | ✅ | Se presente nell'edizione |
| **Autore/i** | ✅ | ✅ | ✅ | Supporta autori multipli |
| **Editore** | ✅ | ✅ | ✅ | Nome casa editrice |
| **ISBN-10** | ✅ | ✅ | ⚠️ | Conversione automatica |
| **ISBN-13** | ✅ | ✅ | ✅ | Standard moderno |
| **Copertina** | ✅ | ✅ | ⚠️ | Alta risoluzione |
| **Descrizione** | ✅ | ⚠️ | ⚠️ | Trama o sinossi |
| **Data pubblicazione** | ✅ | ✅ | ✅ | Formato flessibile |
| **Numero pagine** | ✅ | ✅ | ✅ | Conteggio totale |
| **Prezzo** | ✅ | ❌ | ⚠️ | Prezzo di copertina |
| **Lingua** | ✅ | ✅ | ✅ | Lingua del testo |
| **Formato** | ✅ | ⚠️ | ⚠️ | Tipo di rilegatura |
| **Dimensioni** | ✅ | ❌ | ⚠️ | Larghezza × altezza |
| **Peso** | ✅ | ❌ | ❌ | In kilogrammi |
| **Collana/Serie** | ✅ | ⚠️ | ✅ | Se fa parte di una serie |
| **Genere** | ✅ | ⚠️ | ⚠️ | Categoria letteraria |
| **Classificazione Dewey** | ⚠️ | ❌ | ✅ | Quando disponibile |
| **Biografia autore** | ⚠️ | ⚠️ | ⚠️ | Dalla versione 0.4.1 |

**Legenda:**
- ✅ = Sempre o quasi sempre disponibile
- ⚠️ = A volte disponibile, dipende dal libro
- ❌ = Raramente disponibile

## 🔧 Gestione ISBN

### Conversione Automatica ISBN-10 ↔ ISBN-13

A partire dalla versione 0.4.1, il sistema riconosce automaticamente il formato ISBN e prova entrambe le varianti:

**Esempio:**
```
Inserisci ISBN-10: 8842935786
→ Sistema cerca con: 8842935786
→ Se non trova, converte in ISBN-13: 9788842935780
→ Prova nuovamente la ricerca
→ Salva entrambi i formati quando possibile
```

**Importante:** Gli ISBN che iniziano con 979 non possono essere convertiti in ISBN-10 (è una limitazione dello standard ISBN stesso).

### Cross-Check Duplicati

Il sistema verifica sia il campo ISBN-10 che ISBN-13 per evitare duplicati:
- Prima di inserire, controlla se esiste già un libro con lo stesso ISBN
- Considera equivalenti ISBN-10 e ISBN-13 dello stesso libro
- Avvisa se stai per creare un duplicato

## ⚠️ Gestione degli Errori

### "ISBN non trovato"

**Causa:** Il libro non è presente in nessuna delle fonti online.

**Soluzione:**
1. Verifica che l'ISBN sia corretto (controlla sul libro fisico)
2. Prova con o senza trattini (es. `978-88-429-3578-0` vs `9788842935780`)
3. Prova con ISBN-10 se hai inserito ISBN-13 (o viceversa)
4. Per libri molto vecchi o rari, usa l'inserimento manuale
5. Verifica che il libro sia effettivamente pubblicato

### "Dati parziali recuperati"

**Causa:** Alcune fonti non hanno tutte le informazioni per quel libro.

**Soluzione:**
- Il sistema mostra i dati che è riuscito a trovare
- Completa manualmente i campi mancanti
- Puoi sempre modificare dopo il salvataggio
- Considera di provare nuovamente lo scraping in futuro (i database si aggiornano)

### "Timeout connessione"

**Causa:** Le fonti online sono temporaneamente lente o non rispondono.

**Soluzione:**
1. Il sistema riprova automaticamente una volta
2. Se persiste, attendi qualche minuto e riprova
3. Verifica la connessione internet
4. Come ultima risorsa, usa l'inserimento manuale

### "Copertina non disponibile"

**Causa:** Nessuna fonte ha l'immagine della copertina per quel libro.

**Soluzione:**
- Il sistema usa un'immagine placeholder generica
- Puoi caricare la copertina manualmente dopo il salvataggio
- Puoi fotografare la copertina del libro fisico e caricarla
- Cerca l'immagine online manualmente (Google Immagini) e caricala

### "Caratteri strani nel titolo"

**Causa:** Alcuni metadati contengono caratteri di controllo MARC-8 (standard bibliografico).

**Soluzione:**
- Il sistema pulisce automaticamente questi caratteri (dalla versione 0.4.0)
- Se vedi ancora caratteri strani, segnalalo (è un bug)
- Puoi correggere manualmente dopo il salvataggio

## 🔒 Sicurezza e Privacy

### Il Sistema è Sicuro?

✅ **Tutte le connessioni usano protocollo HTTPS** crittografato
✅ **Non vengono salvate credenziali** o dati sensibili
✅ **I dati vengono validati** prima dell'inserimento nel database
✅ **Nessun dato della tua biblioteca** viene inviato alle fonti esterne
✅ **Le copertine vengono salvate** sul tuo server, non linkate esternamente
✅ **Rate limiting** per evitare abusi delle API esterne

### Conformità GDPR

✅ **Non vengono raccolti dati personali** durante lo scraping
✅ **Solo dati pubblici** relativi ai libri vengono recuperati
✅ **Nessun tracciamento** degli utenti della biblioteca
✅ **Log anonimi** per debug (senza informazioni personali)

### Rispetto dei Termini di Servizio

Il sistema rispetta i termini di servizio di tutte le fonti:
- **Google Books:** Utilizza l'API ufficiale
- **Open Library:** Utilizza l'API pubblica
- **SBN:** Segue le linee guida per l'accesso ai dati

## 💡 Best Practices

### Quando Usare lo Scraping

**✅ USA** lo scraping quando:
- Il libro ha un ISBN/EAN
- È un libro pubblicato dopo il 1970
- È un'edizione commerciale standard
- Hai connessione internet

**❌ NON USARE** lo scraping quando:
- Il libro non ha ISBN (libri antichi, autoedizioni)
- È una pubblicazione locale o artigianale
- È un manoscritto o documento unico
- Non hai connessione internet

### Come Ottimizzare i Risultati

1. **Usa sempre l'ISBN più recente** dell'edizione che possiedi
2. **Verifica i dati importati** prima di salvare (possono esserci errori)
3. **Completa i campi mancanti** manualmente quando necessario
4. **Salva** e poi usa "Modifica" per aggiustamenti successivi

### Arricchimento Post-Scraping

Dopo aver importato i dati via scraping, puoi migliorarli aggiungendo:
- Note varie specifiche della tua biblioteca
- Numero inventario per tracciabilità interna
- Stato fisico del libro (se danneggiato)
- Data di acquisizione e tipo (acquisto, donazione)
- Collocazione fisica (scaffale, mensola, posizione)

## 🆕 Novità dalla Versione 0.4.1

La versione 0.4.1 ha introdotto miglioramenti significativi allo scraping:

### Miglioramenti Gestione ISBN
- ✅ Riconoscimento automatico formato ISBN-10 o ISBN-13
- ✅ Conversione automatica tra i due formati
- ✅ Ricerca automatica con entrambi i formati
- ✅ Cross-check per evitare duplicati

### Nuova Fonte: SBN
- ✅ Supporto per il Sistema Bibliotecario Nazionale italiano
- ✅ Metadati di qualità superiore per libri italiani
- ✅ Classificazioni Dewey più accurate

### Arricchimento Automatico
- ✅ Recupero automatico classificazione Dewey da fonti alternative
- ✅ Biografie autori salvate automaticamente quando disponibili
- ✅ Validazione e ottimizzazione copertine

### Pulizia Dati
- ✅ Rimozione automatica caratteri MARC-8
- ✅ Normalizzazione nomi autori
- ✅ Rimozione duplicati autori durante import

## 🔗 Collegamenti Utili

- [→ Inserimento Libri](./inserimento.md) - Guida completa all'inserimento
- [→ Modifica Libri](./modifica.md) - Come aggiornare i dati dopo lo scraping
- [→ API Book Scraper Plugin](../plugin/api-book-scraper.md) - Plugin per fonti aggiuntive
- [→ Developer: Scraping API](../developer/api-scraping.md) - Documentazione tecnica

## ❓ Domande Frequenti

**D: Quanto tempo ci vuole per lo scraping?**
R: Di solito 3-5 secondi. In caso di connessione lenta o fonti sovraccariche, può arrivare a 10-15 secondi.

**D: Posso scegliere quale fonte usare?**
R: No, il sistema interroga automaticamente tutte le fonti e sceglie i dati migliori. Dalla versione 0.4.0 puoi però vedere da quale fonte provengono i dati.

**D: Lo scraping consuma dati?**
R: Sì, ma molto poco. Ogni ricerca consuma circa 50-200 KB di dati (principalmente per scaricare la copertina).

**D: Posso fare scraping offline?**
R: No, lo scraping richiede connessione internet attiva.

**D: I dati importati sono sempre corretti?**
R: Generalmente sì, ma è sempre bene verificare. Le fonti sono affidabili ma possono contenere errori o dati incompleti.

**D: Posso aggiungere nuove fonti di scraping?**
R: Sì, tramite il sistema di plugin. Consulta la documentazione developer per creare plugin di scraping personalizzati.

---

**Ultimo aggiornamento:** Dicembre 2025
**Versione documentazione:** 1.0.0
**Compatibile con:** Pinakes v0.4.1+
