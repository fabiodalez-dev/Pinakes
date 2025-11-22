# 📚 Guida Completa: Come Aggiungere un Nuovo Libro

## 🎯 Introduzione per l'Utente Finale

Questa guida ti spiega **passo dopo passo** come aggiungere nuovi libri al sistema della tua biblioteca. **Non serve alcuna conoscenza tecnica** - è tutto progettato per essere **semplice come compilare un modulo**.

Il sistema è pensato per **semplificare al massimo** il lavoro di catalogazione, riducendo da 10-15 minuti di inserimento manuale a **soli 2 minuti** grazie all'importazione automatica dei dati.

---

## 🚀 Due Modi per Aggiungere Libri

| **Metodo** | **Quando usarlo** | **Tempo richiesto** | **Difficoltà** |
|------------|-------------------|-------------------|----------------|
| **📱 Automatico da ISBN/EAN** | **SEMPRE** quando hai ISBN o EAN | **2 minuti** | 🟢 Facilissimo |
| **📝 Manuale** | Solo se non hai ISBN/EAN | **10-15 minuti** | 🟡 Semplice |

> 💡 **Consiglio**: Usa sempre il metodo automatico quando possibile. Il sistema trova **tutte le informazioni** da solo!

### 🔍 Cos'è l'ISBN e l'EAN?

**ISBN (International Standard Book Number)** e **EAN (European Article Number)** sono codici univoci che identificano i libri:

- **ISBN-10**: Codice di 10 caratteri (9 cifre + 1 cifra o lettera X)
  Esempio: `8842935786`

- **ISBN-13**: Codice di 13 cifre (standard moderno)
  Esempio: `9788842935780`

- **EAN**: Codice a barre di 13 cifre (stesso formato ISBN-13)
  Esempio: `9788842935780`

**Dove trovarli?**
- Sul retro del libro, vicino al codice a barre
- Sulla prima pagina interna (colophon)
- Nella quarta di copertina

> 💡 **Importante**: Il nostro sistema accetta **sia ISBN che EAN** per l'importazione automatica!

---

## 📱 Metodo 1: Inserimento Automatico (Consigliato!)

### Passo 1: Preparazione
- **Trova l'ISBN o EAN** sul retro del libro
- **Esempio ISBN-13**: `978-88-429-3578-0` (con o senza trattini)
- **Esempio ISBN-10**: `8842935786` (può finire anche con la lettera X)
- **Esempio EAN**: `9788842935780`

> 💡 **Suggerimento**: Non importa se copi il codice con i trattini (`978-88-429-3578-0`) o senza (`9788842935780`), il sistema li riconosce entrambi!

### Passo 2: Accesso al Form
1. **Vai su**: Dashboard → **Libri**
2. **Clicca**: **"+ Nuovo Libro"** (bottone verde in alto a destra)
3. **Vedrai**: Una pagina con due sezioni principali:
   - **Sezione superiore**: "Importa da ISBN" (con icona codice a barre)
   - **Sezione inferiore**: Form completo per inserimento manuale

### Passo 3: Importazione Automatica
1. **Trova la sezione**: "Importa da ISBN" o "Importa da EAN"
2. **Digita o incolla**: Il codice completo (es. `9788842935780`)
3. **Clicca**: Bottone blu **"Importa Dati"** (icona download)
4. **Attendi**: 3-5 secondi mentre il sistema cerca online

> ⏱️ **Cosa succede durante l'attesa?**
> Il sistema sta contattando fonti online autorizzate per recuperare tutte le informazioni del libro. Vedi la sezione "Come funziona lo Scraping" più sotto per i dettagli.

### Passo 4: Risultato dell'Importazione

Il sistema compila **automaticamente** questi campi:

#### ✅ Informazioni Bibliografiche
- **Titolo completo** - con maiuscole corrette
- **Sottotitolo** - se presente nell'edizione
- **Autore/i** - con nome e cognome completi
  - Se l'autore non esiste, viene creato automaticamente
  - Supporta autori multipli
- **Editore** - nome casa editrice
  - Se l'editore non esiste, viene segnalato come "Da creare"

#### ✅ Codici Identificativi
- **ISBN-10** - formato pulito (senza trattini)
- **ISBN-13** - formato pulito
- **EAN** - se disponibile

#### ✅ Dettagli Pubblicazione
- **Data di pubblicazione** - in formato italiano leggibile
  - Es: "26 agosto 2025" oppure "2025"
- **Numero pagine** - conteggio totale
- **Lingua** - lingua del testo

#### ✅ Dettagli Fisici
- **Formato** - tipo di rilegatura
  - Es: "Brossura", "Copertina rigida"
- **Dimensioni** - larghezza x altezza in cm
- **Peso** - in kg (convertito automaticamente da grammi)

#### ✅ Contenuti e Prezzi
- **Descrizione** - trama o sinossi del libro
- **Prezzo** - prezzo di copertina in euro
- **Collana/Serie** - se il libro fa parte di una collana
- **Numero serie** - posizione nella collana

#### ✅ Risorse Multimediali
- **Copertina** - immagine ad alta risoluzione
  - Scaricata automaticamente dai server ufficiali
  - Salvata nella tua libreria

#### ✅ Classificazione
- **Genere** - categoria letteraria
  - Il sistema fa un mapping intelligente per associarlo alle tue categorie

### Passo 5: Aggiustamenti Finali

Dopo l'importazione automatica, **devi aggiungere manualmente** solo queste informazioni specifiche della tua biblioteca:

#### 📍 Posizione Fisica (Obbligatoria)
- **Scaffale**: Seleziona da menu (es. "A - Scaffale Principale")
- **Mensola**: Seleziona il ripiano (es. "Livello 2")
- **Posizione progressiva**: Numero del libro sulla mensola
  - Puoi inserirlo manualmente (es. "15")
  - Oppure cliccare "Genera automaticamente" per trovare il primo posto libero

> 📚 **Vuoi capire meglio come funziona la collocazione?**
> Consulta la [pagina Collocazione](/docs/collocazione.MD) che spiega in dettaglio il sistema di organizzazione fisica degli scaffali, come funziona la numerazione progressiva e come ottimizzare lo spazio.

#### 🔢 Gestione Inventario (Opzionale)
- **Numero inventario**: Codice univoco per tracciabilità
  - Es: "INV-2024-001" oppure semplicemente "001"
- **Copie totali**: Quante copie possiedi (default: 1)
- **Copie disponibili**: Quante sono disponibili per il prestito (default: 1)

#### 🏷️ Classificazione Dewey (Opzionale ma Consigliata)
- Vedi sezione dedicata più sotto per capire come usarla

#### 📝 Informazioni Aggiuntive (Opzionali)
- **Data acquisizione**: Quando è entrato in biblioteca
- **Tipo acquisizione**: Come l'hai ottenuto
  - "Acquisto", "Donazione", "Prestito permanente", "Deposito"
- **Note varie**: Qualsiasi annotazione particolare
  - Es: "Dedica dell'autore", "Prima edizione", "Condizioni non perfette"

### Passo 6: Verifica e Salvataggio
1. **Scorri il form** e verifica che tutte le informazioni siano corrette
2. **Controlla particolarmente**:
   - Titolo e autore siano giusti
   - La posizione fisica sia assegnata
   - La copertina sia quella corretta
3. **Clicca**: Bottone verde **"Salva Libro"** in fondo alla pagina
4. **Attendi la conferma**: Vedrai un messaggio di successo con:
   - ID del libro appena creato
   - Link alla scheda completa
   - Opzione per stampare l'etichetta

### 🎯 Risultato Finale
Dopo il salvataggio:
- Il libro è **immediatamente disponibile** per ricerca e prestito
- Puoi **stampare l'etichetta** con QR code
- Il libro appare nella **lista completa** del catalogo
- Le **statistiche** della biblioteca si aggiornano automaticamente

---

## 🤖 Come Funziona lo Scraping Automatico

### 📡 Cos'è lo Scraping?

Lo **scraping** (o "web scraping") è una tecnica informatica che permette al sistema di:
1. **Connettersi** a siti web specializzati in libri
2. **Cercare** il libro usando ISBN o EAN
3. **Estrarre** automaticamente tutte le informazioni pubbliche
4. **Importare** i dati nel nostro database

> 💡 **In parole semplici**: Il sistema "legge" le pagine web come faresti tu, ma in modo automatico e istantaneo!

### 🌐 Da Dove Vengono i Dati?

Il sistema interroga **fonti ufficiali e affidabili** in questo ordine di priorità:

#### 1️⃣ Libreria Universitaria (Fonte Primaria)
- **URL**: img.libreriauniversitaria.it
- **Dati forniti**:
  - Informazioni bibliografiche complete
  - Copertine ad alta risoluzione
  - Prezzi aggiornati
  - Descrizioni dettagliate
- **Affidabilità**: ⭐⭐⭐⭐⭐ (Molto alta)
- **Lingua**: Italiano

#### 2️⃣ Open Library (Fonte Secondaria)
- **URL**: covers.openlibrary.org
- **Dati forniti**:
  - Catalogo internazionale di libri
  - Copertine alternative
  - Edizioni multiple
- **Affidabilità**: ⭐⭐⭐⭐ (Alta)
- **Lingua**: Internazionale

#### 3️⃣ Google Books (Fonte di Backup)
- **URL**: books.google.com
- **Dati forniti**:
  - Database mondiale
  - Anteprime copertine
  - Metadati editoriali
- **Affidabilità**: ⭐⭐⭐⭐ (Alta)
- **Lingua**: Internazionale

#### 4️⃣ Amazon (Fonte Aggiuntiva Copertine)
- **URL**: images.amazon.com
- **Dati forniti**:
  - Copertine ad alta definizione
  - Immagini alternative
- **Affidabilità**: ⭐⭐⭐ (Media)
- **Lingua**: Internazionale

### ⚙️ Processo di Importazione Passo-Passo

Quando clicchi "Importa Dati", ecco cosa succede nei 3-5 secondi di attesa:

1. **Validazione** (0.5 secondi)
   - Il sistema controlla che l'ISBN/EAN sia formattato correttamente
   - Rimuove automaticamente spazi, trattini e caratteri non necessari
   - Verifica che non sia già presente nel database

2. **Connessione Prima Fonte** (1-2 secondi)
   - Si connette a Libreria Universitaria
   - Cerca il libro nel loro catalogo
   - Se trovato, scarica tutti i dati disponibili

3. **Integrazione Fonti Secondarie** (1-2 secondi)
   - Se mancano informazioni (es. copertina), interroga le altre fonti
   - Unisce i dati da diverse fonti per avere il massimo completamento
   - Sceglie la migliore versione per ogni campo

4. **Pulizia e Formattazione** (0.5 secondi)
   - Pulisce i dati da caratteri speciali o formattazioni errate
   - Converte date in formato italiano leggibile
   - Standardizza i prezzi in euro
   - Ottimizza le immagini delle copertine

5. **Popolamento Form** (0.5 secondi)
   - Compila automaticamente tutti i campi del form
   - Mostra un'anteprima della copertina
   - Evidenzia eventuali campi mancanti che richiedono la tua attenzione

### ✅ Cosa Viene Importato Automaticamente

| **Campo** | **Sempre** | **Spesso** | **A Volte** | **Raramente** |
|-----------|-----------|-----------|-------------|--------------|
| Titolo | ✅ | | | |
| Autore/i | ✅ | | | |
| ISBN-13 | ✅ | | | |
| Editore | | ✅ | | |
| Copertina | | ✅ | | |
| Pagine | | ✅ | | |
| Prezzo | | ✅ | | |
| Descrizione | | ✅ | | |
| Data pubblicazione | | ✅ | | |
| ISBN-10 | | | ✅ | |
| Formato | | | ✅ | |
| Peso | | | ✅ | |
| Dimensioni | | | ✅ | |
| Collana | | | ✅ | |
| Lingua | | | | ✅ |

### ⚠️ Gestione degli Errori

#### "ISBN non trovato"
**Causa**: Il libro non è presente in nessuna delle fonti online
**Soluzione**:
- Verifica che l'ISBN sia corretto (controlla sul libro)
- Prova con o senza trattini
- Prova con ISBN-10 se hai inserito ISBN-13 (o viceversa)
- Usa l'inserimento manuale

#### "Dati parziali recuperati"
**Causa**: Alcune fonti non hanno tutte le informazioni
**Soluzione**:
- Il sistema mostra quello che ha trovato
- Completa manualmente i campi mancanti
- Puoi sempre modificare dopo il salvataggio

#### "Timeout connessione"
**Causa**: Le fonti online sono temporaneamente lente
**Soluzione**:
- Il sistema riprova automaticamente una volta
- Se persiste, attendi qualche minuto e riprova
- Usa l'inserimento manuale se hai fretta

#### "Copertina non disponibile"
**Causa**: Nessuna fonte ha l'immagine della copertina
**Soluzione**:
- Il sistema usa un'immagine placeholder
- Puoi caricare la copertina manualmente dopo
- Puoi fotografare la copertina e caricarla

### 🔒 Sicurezza e Privacy

**Il sistema è sicuro?**
- ✅ Tutte le connessioni usano protocollo HTTPS crittografato
- ✅ Non vengono salvate credenziali o dati sensibili
- ✅ I dati vengono validati prima dell'inserimento nel database
- ✅ Nessun dato della tua biblioteca viene inviato alle fonti esterne
- ✅ Le copertine vengono salvate sul tuo server, non linkate esternamente

**Conformità GDPR**
- ✅ Non vengono raccolti dati personali durante lo scraping
- ✅ Solo dati pubblici relativi ai libri
- ✅ Nessun tracciamento degli utenti della biblioteca

---

## 📚 Classificazione Dewey (Dewey Decimal Classification)

### 🔍 Cos'è la Classificazione Dewey?

La **Classificazione Decimale Dewey (DDC)** è il sistema di organizzazione biblioteconomica più usato al mondo, inventato da Melvil Dewey nel 1876.

**Scopo**: Organizzare i libri per **argomento** in modo logico e universale, facilitando la ricerca e il browsing.

### 🎯 Perché Usarla?

1. **Standard Mondiale**: Usata da milioni di biblioteche
2. **Organizzazione Logica**: I libri dello stesso argomento stanno vicini
3. **Espandibile**: Puoi aggiungere nuovi argomenti mantenendo la struttura
4. **Ricerca Facilitata**: Gli utenti trovano libri correlati facilmente
5. **Professionalità**: La tua biblioteca segue standard professionali

### 📊 Come Funziona la Struttura

La classificazione Dewey usa **tre livelli** di dettaglio crescente:

#### Livello 1: Classi Principali (10 Categorie)
Ogni area della conoscenza ha un codice da **000 a 900**:

| Codice | Categoria | Esempi |
|--------|-----------|--------|
| **000** | Informatica, Informazione e Opere Generali | Enciclopedie, Computer |
| **100** | Filosofia e Psicologia | Etica, Logica, Psicologia |
| **200** | Religione | Bibbia, Teologia, Mitologia |
| **300** | Scienze Sociali | Sociologia, Politica, Economia, Diritto |
| **400** | Lingue | Dizionari, Grammatica, Linguistica |
| **500** | Scienze Pure | Matematica, Fisica, Chimica, Biologia |
| **600** | Tecnologia e Scienze Applicate | Medicina, Ingegneria, Agricoltura |
| **700** | Arti e Ricreazione | Pittura, Musica, Sport |
| **800** | Letteratura | Poesia, Teatro, Romanzi |
| **900** | Storia e Geografia | Biografie, Storia, Viaggi |

#### Livello 2: Divisioni (100 Sottocategorie per Classe)
Ogni classe si divide in **10 divisioni** da 0 a 9:

**Esempio per la classe 300 (Scienze Sociali)**:
| Codice | Divisione |
|--------|-----------|
| **310** | Statistica |
| **320** | Scienza Politica |
| **330** | Economia |
| **340** | Diritto |
| **350** | Amministrazione Pubblica |
| **360** | Problemi e Servizi Sociali |
| **370** | Educazione |
| **380** | Commercio, Comunicazioni, Trasporti |
| **390** | Costumi, Etichetta, Folclore |

#### Livello 3: Sezioni (1000 Categorie Specifiche)
Ogni divisione si suddivide ulteriormente in **10 sezioni**:

**Esempio per 320 (Scienza Politica)**:
| Codice | Sezione |
|--------|---------|
| **320.1** | Stato e sue strutture |
| **320.5** | Ideologie politiche |
| **321** | Sistemi di governo |
| **322** | Relazione stato-gruppi organizzati |
| **323** | Diritti civili e politici |
| **324** | Processi politici |
| **325** | Migrazione internazionale |
| **326** | Schiavitù e emancipazione |
| **327** | Relazioni internazionali |
| **328** | Processo legislativo |

### 🎨 Come Usare Dewey nel Sistema

Quando inserisci un libro, puoi assegnare la classificazione Dewey seguendo questi passi:

#### Passo 1: Identifica l'Argomento Principale
Chiediti: **Di cosa parla principalmente questo libro?**

**Esempi**:
- "Il Capitale" di Karl Marx → Economia
- "1984" di George Orwell → Letteratura/Romanzo
- "Breve storia del tempo" di Stephen Hawking → Fisica/Astronomia

#### Passo 2: Seleziona la Classe (Livello 1)
Nel form, apri il menu a tendina "Classe Dewey" e scegli la categoria generale:
- Economia → **300 - Scienze Sociali**
- Letteratura → **800 - Letteratura**
- Fisica → **500 - Scienze Pure**

#### Passo 3: Seleziona la Divisione (Livello 2)
Dopo aver scelto la classe, si attiva il secondo menu per la divisione:
- 300 - Scienze Sociali → **330 - Economia**
- 800 - Letteratura → **820 - Letteratura Inglese** oppure **850 - Letteratura Italiana**
- 500 - Scienze Pure → **520 - Astronomia**

#### Passo 4: Seleziona la Sezione (Livello 3) - Opzionale
Per massima precisione, scegli la sezione specifica:
- 330 - Economia → **335 - Socialismo ed economie correlate**
- 850 - Letteratura Italiana → **853 - Narrativa italiana**

#### Passo 5: Verifica il Codice Finale
Il sistema mostra il percorso completo che hai selezionato:
- **Esempio 1**: `300 > 330 > 335` = "Scienze Sociali > Economia > Socialismo"
- **Esempio 2**: `850 > 853` = "Letteratura Italiana > Narrativa"
- **Esempio 3**: `520` = "Astronomia"

### 📖 Esempi Pratici di Classificazione

| **Libro** | **Autore** | **Classe** | **Divisione** | **Sezione** | **Codice Finale** |
|-----------|-----------|-----------|--------------|------------|------------------|
| Il Signore degli Anelli | J.R.R. Tolkien | 800 - Letteratura | 820 - Lett. Inglese | 823 - Narrativa | **823** |
| Sapiens | Yuval Noah Harari | 900 - Storia | 930 - Storia Antica | 930 - Generale | **930** |
| Il Capitale | Karl Marx | 300 - Sc. Sociali | 330 - Economia | 335 - Socialismo | **335** |
| Cosmos | Carl Sagan | 500 - Scienze | 520 - Astronomia | 520 - Generale | **520** |
| Il Principe | Machiavelli | 300 - Sc. Sociali | 320 - Politica | 320.1 - Stato | **320.1** |

### 💡 Consigli per una Buona Classificazione

#### ✅ Best Practices
- **Pensa all'argomento principale**, non secondario
- **Usa il livello di dettaglio giusto**: non serve sempre arrivare al terzo livello
- **Mantieni coerenza**: libri simili devono avere classificazioni simili
- **Consulta libri già classificati** della stessa categoria come riferimento

#### ❌ Errori Comuni da Evitare
- Non classificare un romanzo storico come Storia (900) → È Letteratura (800)
- Non classificare una biografia come Letteratura → È Storia (900)
- Non mescolare Saggistica e Narrativa nella stessa sezione

### 🔗 Integrazione con la Collocazione Fisica

**Dewey è diverso dalla posizione fisica!**

- **Dewey**: Classifica il **contenuto** del libro (argomento)
- **Collocazione**: Indica **dove si trova fisicamente** il libro (scaffale/mensola)

**Possono coincidere** se organizzi gli scaffali per argomento Dewey:
- Scaffale A → 300-399 (Scienze Sociali)
- Scaffale B → 800-899 (Letteratura)
- ecc.

> 📚 Per maggiori dettagli sull'organizzazione fisica, vedi la [pagina Collocazione](/docs/collocazione.MD).

### 🆘 Quando Dewey è Opzionale

**Puoi saltare Dewey se:**
- Gestisci una biblioteca piccola (< 500 libri)
- Organizzi per genere letterario semplice (Giallo, Fantasy, ecc.)
- È una biblioteca scolastica per bambini (meglio categorie semplici)

**Dewey è consigliata se:**
- Vuoi una biblioteca professionale
- Hai più di 1000 libri
- Hai diverse categorie di saggistica
- Vuoi facilitare la ricerca per argomento

---

## 📝 Metodo 2: Inserimento Manuale

### 📌 Quando usarlo
- **Libri antichi** senza ISBN (pubblicati prima del 1970)
- **Pubblicazioni locali** o autoedizioni senza codice
- **Donazioni** senza informazioni complete
- **Manoscritti** o documenti unici
- **Quaderni** o pubblicazioni non commerciali
- Quando **l'importazione automatica fallisce** ripetutamente

### 🎯 Approccio Consigliato

Prima di iniziare l'inserimento manuale, **raccogli tutte le informazioni possibili** dal libro stesso:
1. Guarda la **copertina** (titolo, autore)
2. Apri la **prima pagina** (colophon con editore, data, ISBN)
3. Controlla il **retro** (quarta di copertina con descrizione)
4. Conta le **pagine** (guarda l'ultimo numero)

---

### 📋 Guida Dettagliata ai Campi

#### 🏷️ **Informazioni Base (Obbligatorie)**

##### **Titolo**
- **Cosa inserire**: Nome completo del libro come appare sulla copertina
- **Formato**: Scrivi normalmente, non tutto MAIUSCOLO
- **Lunghezza**: Minimo 2 caratteri, massimo 200
- **Esempi**:
  - ✅ Corretto: "La Divina Commedia"
  - ✅ Corretto: "Il nome della rosa"
  - ❌ Sbagliato: "LA DIVINA COMMEDIA" (tutto maiuscolo)
  - ❌ Sbagliato: "divina commedia" (minuscolo iniziale)

> 💡 **Suggerimento**: Il sistema capitalizza automaticamente la prima lettera, quindi non preoccuparti troppo.

##### **Sottotitolo**
- **Cosa inserire**: Seconda parte del titolo se presente
- **Quando usarlo**: Solo se c'è davvero un sottotitolo ufficiale
- **Lunghezza**: Massimo 500 caratteri
- **Esempi**:
  - "1984" → Nessun sottotitolo
  - "Sapiens" → Sottotitolo: "Da animali a dèi: Breve storia dell'umanità"

##### **Autore/i**
- **Cosa inserire**: Nome e cognome dell'autore (o autori multipli)
- **Come funziona**: Menu a tendina con ricerca
  - Inizia a digitare il nome
  - Se esiste, selezionalo dalla lista
  - Se non esiste, premi Invio per crearlo automaticamente
- **Autori Multipli**:
  - Puoi aggiungere più autori
  - Vengono visualizzati come badge blu
  - L'ordine conta (primo autore = principale)
- **Esempi**:
  - Autore singolo: "Umberto Eco"
  - Autori multipli: "Isaac Asimov", "Robert Silverberg"
  - Autore collettivo: "AA.VV." (Autori Vari)

---

#### 🔢 **Codici Identificativi (Facoltativi ma Utili)**

##### **ISBN-10**
- **Cosa inserire**: Codice di 10 caratteri (9 cifre + 1 cifra o X)
- **Formato**: Scrivi solo numeri (il sistema rimuove trattini e spazi)
- **Esempi validi**:
  - `8842935786`
  - `884293578X` (con X finale)
- **Dove trovarlo**: Prima pagina interna (colophon)

##### **ISBN-13**
- **Cosa inserire**: Codice di 13 cifre
- **Formato**: Solo numeri, niente trattini
- **Esempio**: `9788842935780`
- **Dove trovarlo**: Retro del libro, vicino al codice a barre

##### **EAN**
- **Cosa inserire**: Codice a barre di 13 cifre (stesso formato ISBN-13)
- **Quando compilarlo**: Se diverso dall'ISBN-13
- **Esempio**: `9788842935780`

> 💡 **Nota**: Spesso ISBN-13 ed EAN sono identici, non serve compilare entrambi.

---

#### 🏢 **Informazioni Editoriali (Facoltative)**

##### **Editore**
- **Cosa inserire**: Nome della casa editrice
- **Come funziona**: Autocomplete intelligente
  - Inizia a digitare (es. "Mond...")
  - Seleziona da lista se esiste ("Mondadori")
  - Oppure scrivi nome completo per crearlo nuovo
- **Esempi comuni**:
  - "Mondadori", "Einaudi", "Feltrinelli", "Rizzoli"

##### **Data di Pubblicazione**
- **Cosa inserire**: Anno o data completa in formato italiano libero
- **Flessibilità**: Il campo accetta vari formati
- **Esempi validi**:
  - Solo anno: "2025"
  - Data completa: "26 agosto 2025"
  - Con edizione: "2ª edizione 2024"
  - Approssimata: "Circa 1950"

##### **Lingua**
- **Cosa inserire**: Lingua principale del testo
- **Esempi**: "Italiano", "Inglese", "Francese", "Spagnolo", "Latino"
- **Note**: Se bilingue, scrivi entrambe separate da virgola: "Italiano, Inglese"

---

#### 📐 **Dettagli Fisici (Facoltativi)**

##### **Numero Pagine**
- **Cosa inserire**: Numero totale di pagine
- **Come trovarlo**: Guarda l'ultimo numero di pagina
- **Formato**: Solo numero intero
- **Esempio**: `320` (non "320 pagine")

##### **Formato**
- **Cosa inserire**: Tipo di rilegatura/copertina
- **Esempi comuni**:
  - "Brossura" (copertina morbida)
  - "Copertina rigida" (cartonato)
  - "Tascabile" (formato piccolo economico)
  - "Rilegato" (con copertina dura)

##### **Peso**
- **Cosa inserire**: Peso in chilogrammi
- **Formato**: Numero con decimali (punto, non virgola)
- **Esempi**:
  - Libro leggero: `0.250` (250 grammi)
  - Libro normale: `0.450` (450 grammi)
  - Libro pesante: `1.200` (1,2 kg)

##### **Dimensioni**
- **Cosa inserire**: Larghezza x Altezza in centimetri
- **Formato libero**: Scrivi come preferisci
- **Esempi**:
  - Formato standard: "21x14 cm"
  - Con spessore: "21x14x3 cm"
  - Solo altezza: "24 cm"

---

#### 📚 **Gestione Biblioteca (Importante)**

##### **Numero Inventario**
- **Cosa inserire**: Codice univoco per tracciabilità interna
- **Formato suggerito**: "INV-ANNO-NUMERO"
- **Esempi**:
  - "INV-2025-001" (primo libro del 2025)
  - "001" (numerazione semplice)
  - "A-001" (per scaffale A)
- **Unicità**: Il sistema controlla che non sia duplicato

##### **Copie Totali**
- **Cosa inserire**: Quante copie dello stesso libro possiedi
- **Default**: 1 (una sola copia)
- **Esempio**: Se hai 3 copie de "Il Signore degli Anelli", scrivi `3`

##### **Copie Disponibili**
- **Cosa inserire**: Quante copie sono attualmente disponibili per prestito
- **Default**: Stesso numero di copie totali
- **Logica**: Deve essere ≤ Copie Totali
- **Esempio**: Se hai 3 copie totali e 2 sono in prestito, copie disponibili = `1`

##### **Stato**
- **Cosa selezionare**: Condizione attuale del libro
- **Opzioni**:
  - **Disponibile**: Pronto per essere prestato (default)
  - **Prestato**: Attualmente in prestito
  - **Non Disponibile**: Temporaneamente non accessibile
  - **Danneggiato**: Necessita riparazione
  - **Perso**: Da ricercare o sostituire

---

#### 📍 **Posizione Fisica (Obbligatoria)**

Questa è l'informazione **più importante** perché indica **dove si trova fisicamente** il libro.

##### **Scaffale**
- **Cosa selezionare**: Lo scaffale dove metterai il libro
- **Menu a tendina**: Mostra tutti gli scaffali configurati
- **Formato visualizzato**: "[A] Scaffale Principale"
- **Esempio**: Seleziona "A - Narrativa Italiana"

##### **Mensola**
- **Cosa selezionare**: Il ripiano dello scaffale
- **Menu dinamico**: Si popola dopo aver scelto lo scaffale
- **Formato visualizzato**: "Livello 1", "Livello 2", ecc.
- **Convenzione**:
  - Livello 1 = ripiano più alto
  - Livello 5 = ripiano più basso
- **Esempio**: "Livello 3" (ripiano centrale)

##### **Posizione Progressiva**
- **Cosa inserire**: Numero del libro sulla mensola (da sinistra a destra)
- **Opzione 1 - Manuale**: Scrivi il numero (es. `15` = quindicesimo libro)
- **Opzione 2 - Automatica**: Clicca "Genera automaticamente"
  - Il sistema trova il primo numero libero
  - Evita conflitti e duplicati

##### **Collocazione Finale**
- **Campo automatico**: Si genera automaticamente
- **Formato**: `SCAFFALE.MENSOLA.POSIZIONE`
- **Esempi**:
  - "A.2.15" = Scaffale A, Mensola 2, 15° libro
  - "B.1.03" = Scaffale B, Mensola 1, 3° libro

> 📚 **Vuoi approfondire?**
> Leggi la [Guida Collocazione](/docs/collocazione.MD) per capire come organizzare al meglio gli scaffali, come gestire la numerazione progressiva e come ottimizzare lo spazio fisico.

---

#### 💰 **Dettagli Acquisizione (Facoltativi)**

##### **Data Acquisizione**
- **Cosa inserire**: Quando il libro è entrato in biblioteca
- **Formato**: Data con calendario (seleziona da popup)
- **Default**: Data odierna
- **Uso**: Utile per statistiche e inventari annuali

##### **Tipo Acquisizione**
- **Cosa inserire**: Come hai ottenuto il libro
- **Esempi comuni**:
  - "Acquisto" (comprato dalla biblioteca)
  - "Donazione" (ricevuto in dono)
  - "Prestito permanente" (prestato da altra istituzione)
  - "Deposito" (depositato temporaneamente)

##### **Prezzo (€)**
- **Cosa inserire**: Prezzo di copertina o di acquisto in euro
- **Formato**: Numero con massimo 2 decimali
- **Esempi**: `19.90`, `12.50`, `25.00`
- **Uso**: Utile per budget e valore assicurativo

---

#### 🏷️ **Categorie e Classificazione (Facoltative)**

##### **Genere Letterario**
- **Come funziona**: Selezione gerarchica a tre livelli
  1. **Radice**: Categoria principale (es. "Prosa")
  2. **Genere**: Sottocategoria (es. "Narrativa")
  3. **Sottogenere**: Specificazione (es. "Fantasy")
- **Dipendenze**: Devi selezionare in ordine (prima radice, poi genere, poi sottogenere)
- **Esempi di percorsi**:
  - Prosa → Narrativa → Fantasy
  - Prosa → Saggistica → Storia
  - Poesia → Lirica → Contemporanea

##### **Collana**
- **Cosa inserire**: Nome della collana se il libro ne fa parte
- **Esempi**: "I Meridiani", "Oscar Mondadori", "Einaudi Tascabili"
- **Opzionale**: Lascia vuoto se non applicabile

##### **Numero Serie**
- **Cosa inserire**: Posizione del libro nella collana/serie
- **Formato**: Numero intero
- **Esempio**: Se è il 3° libro della collana, scrivi `3`

---

#### 🖼️ **Copertina (Facoltativa ma Consigliata)**

##### **Opzione 1: Carica da Computer**
- Clicca "Sfoglia" o "Scegli file"
- Seleziona l'immagine salvata sul tuo PC
- **Requisiti**:
  - Formato: JPG, PNG, JPEG
  - Dimensione massima: 5 MB
  - Risoluzione consigliata: 400×600 pixel

##### **Opzione 2: Drag & Drop**
- Trascina l'immagine nell'area dedicata
- Vedi l'anteprima immediata prima del caricamento

##### **Opzione 3: Fotografia**
- Scatta una foto della copertina con il telefono
- Trasferisci al computer
- Carica come Opzione 1

##### **Placeholder**
- Se non carichi nulla, il sistema usa un'immagine segnaposto generica
- Puoi sempre aggiungerla in seguito modificando il libro

---

#### 📝 **Note e Descrizioni (Facoltative)**

##### **Descrizione**
- **Cosa inserire**: Trama, sinossi o descrizione del contenuto
- **Lunghezza**: Massimo 2000 caratteri (circa 300 parole)
- **Supporta**: Formattazione base Markdown
- **Esempio**:
  > "Un'epica avventura ambientata nella Terra di Mezzo, dove un gruppo eterogeneo di eroi deve distruggere un anello magico per salvare il mondo dal male assoluto."

##### **Parole Chiave**
- **Cosa inserire**: Tag separati da virgola per facilitare la ricerca
- **Formato**: "keyword1, keyword2, keyword3"
- **Esempi**:
  - "fantasy, avventura, magia, epica"
  - "filosofia, politica, società, anarchismo"

##### **Note Varie**
- **Cosa inserire**: Qualsiasi informazione aggiuntiva rilevante
- **Esempi di note utili**:
  - "Edizione con dedica autografa dell'autore"
  - "Prima edizione, copertina originale"
  - "Alcune pagine sottolineate a matita"
  - "Rilegatura restaurata nel 2020"
  - "Non prestare - solo consultazione in sede"

---

## 🎯 Workflow Completo per Inserimento Manuale

Ecco una **checklist passo-passo** per guidarti nell'inserimento manuale:

### ✅ Checklist Prima di Iniziare
- [ ] Ho il libro fisicamente davanti a me?
- [ ] Ho raccolto tutte le informazioni dal libro (copertina, colophon, retro)?
- [ ] Ho deciso in quale scaffale/mensola metterlo?
- [ ] Ho una foto della copertina o posso scansionarla?

### ✅ Checklist Durante l'Inserimento
- [ ] **Titolo** inserito correttamente (non MAIUSCOLO)
- [ ] **Autore/i** selezionati o creati
- [ ] **ISBN** inserito se disponibile (controlla formato)
- [ ] **Editore** selezionato o creato
- [ ] **Data pubblicazione** inserita
- [ ] **Scaffale** e **Mensola** selezionati
- [ ] **Posizione progressiva** assegnata (manuale o automatica)
- [ ] **Classificazione Dewey** assegnata (se applicabile)
- [ ] **Copertina** caricata (se disponibile)
- [ ] **Descrizione** scritta (facoltativa ma utile)

### ✅ Checklist Prima di Salvare
- [ ] Tutti i campi obbligatori sono compilati?
- [ ] L'ortografia del titolo è corretta?
- [ ] La posizione fisica è quella giusta?
- [ ] Ho verificato che non ci siano duplicati (stesso ISBN)?

### ✅ Checklist Dopo il Salvataggio
- [ ] Libro salvato con successo (messaggio conferma)?
- [ ] Stampo l'etichetta?
- [ ] Applico l'etichetta sul libro?
- [ ] Posiziono il libro nello scaffale corretto?

---

## 🎯 Consigli Pratici per l'Inserimento

### ✅ **Best Practices da Seguire Sempre**

#### 📖 Per il Titolo
- **Sempre**: Copia il titolo esattamente come appare sulla copertina
- **Sempre**: Controlla l'ortografia (errori comuni: accenti, apostrofi)
- **Mai**: Scrivere tutto in MAIUSCOLO o tutto in minuscolo
- **Mai**: Abbreviare titoli lunghi (scrivi per intero)
- **Suggerimento**: Se incerto, cerca il libro online per verificare il titolo ufficiale

#### 👤 Per gli Autori
- **Sempre**: Scrivi nome e cognome completi (es. "Umberto Eco", non "U. Eco")
- **Sempre**: Cerca prima se l'autore esiste già nel database
- **Mai**: Usare abbreviazioni o iniziali se hai il nome completo
- **Attenzione**: Controlla la grafia corretta (es. "García Márquez" con accenti)

#### 🔢 Per ISBN/EAN
- **Sempre**: Verifica che le cifre siano corrette (copia-incolla aiuta)
- **Sempre**: Prova l'importazione automatica prima dell'inserimento manuale
- **Prova**: Sia con che senza trattini se l'importazione fallisce
- **Verifica**: Che non esista già un libro con quell'ISBN

#### 📍 Per la Posizione Fisica
- **Sempre**: Assegna una posizione prima di salvare (campo obbligatorio!)
- **Sempre**: Usa "Genera automaticamente" se non sei sicuro della posizione
- **Mai**: Lasciare vuoti scaffale e mensola
- **Suggerimento**: Raggruppa libri dello stesso genere nello stesso scaffale

#### 🖼️ Per la Copertina
- **Sempre**: Carica un'immagine di buona qualità (almeno 300×400 pixel)
- **Preferisci**: File JPG per foto, PNG per grafica
- **Verifica**: Che l'immagine non sia troppo grande (max 5 MB)
- **Alternativa**: Puoi sempre aggiungerla dopo con "Modifica libro"

### ❌ **Errori Comuni da Evitare**

#### Errori di Formattazione
- ❌ Titolo tutto in maiuscolo: "IL NOME DELLA ROSA"
  - ✅ Corretto: "Il nome della rosa"
- ❌ Autore con iniziali: "U. Eco"
  - ✅ Corretto: "Umberto Eco"
- ❌ ISBN con spazi o trattini sbagliati: "978 88429 35780"
  - ✅ Corretto: "9788842935780" o "978-88-429-3578-0"

#### Errori di Classificazione
- ❌ Romanzo storico classificato come Storia (900)
  - ✅ Corretto: Classificato come Letteratura (800)
- ❌ Biografia classificata come Letteratura
  - ✅ Corretto: Classificata come Storia (920 - Biografie)
- ❌ Libro di cucina classificato come Scienza
  - ✅ Corretto: Classificato come Tecnologia (640 - Economia domestica)

#### Errori di Posizione
- ❌ Dimenticare di assegnare scaffale e mensola
  - ✅ Compila sempre prima di salvare
- ❌ Sovrascrivere una posizione già occupata
  - ✅ Usa "Genera automaticamente"
- ❌ Mettere libri di argomenti diversi mescolati
  - ✅ Raggruppa per genere o Dewey

#### Errori di Completezza
- ❌ Salvare senza autore
  - ✅ Ogni libro deve avere almeno un autore (anche "Anonimo" o "AA.VV.")
- ❌ Caricare copertine sfocate o troppo piccole
  - ✅ Minimo 300×400 pixel per buona leggibilità
- ❌ Non compilare la descrizione
  - ✅ Anche solo 2 righe aiutano gli utenti a capire il contenuto

### 💡 **Trucchi e Scorciatoie**

#### ⚡ Velocizza l'Inserimento
1. **Usa sempre l'importazione automatica** quando possibile
2. **Prepara in anticipo** le foto delle copertine in una cartella
3. **Assegna posizioni in batch** per libri dello stesso scaffale
4. **Crea autori ed editori** comuni all'inizio, poi riusali

#### 🎯 Ottimizza la Qualità
1. **Verifica online** titoli e autori se incerto
2. **Copia-incolla ISBN** invece di digitare manualmente
3. **Usa la fotocamera** del telefono per copertine di alta qualità
4. **Scrivi descrizioni concise** ma informative (2-3 frasi)

#### 🔄 Gestione Efficiente
1. **Inserisci libri a gruppi** per genere o scaffale
2. **Stampa etichette in batch** dopo aver inserito più libri
3. **Verifica periodicamente** duplicati o errori di ortografia
4. **Aggiorna regolarmente** le posizioni quando riorganizzi

---

## 📊 Dopo il Salvataggio: Cosa Succede

### ✅ Conferma di Successo

Quando salvi con successo, vedrai:

1. **Messaggio popup verde** "Libro aggiunto con successo!"
2. **ID univoco** assegnato automaticamente (es. "Libro #1234")
3. **Link alla scheda** completa del libro appena creato
4. **Opzioni rapide**:
   - 🖨️ Stampa etichetta
   - ✏️ Modifica libro
   - 📚 Aggiungi un'altra copia
   - 🔙 Torna alla lista libri

### 🎫 Stampa dell'Etichetta

Dopo aver salvato, puoi stampare l'etichetta fisica:

#### Cosa contiene l'etichetta?
- **QR Code**: Per scansione rapida
- **Titolo abbreviato**: Prime parole del titolo
- **Collocazione**: Es. "A.2.15"
- **Codice Dewey**: Se assegnato

#### Formati disponibili:
- **25×38 mm**: Standard per etichette adesive piccole
- **50×30 mm**: Formato medio per dorsi spessi
- **A4**: Foglio intero per stampanti normali

#### Come applicare l'etichetta:
1. **Stampa** su carta adesiva
2. **Taglia** lungo le linee tratteggiate
3. **Applica** sul dorso del libro in basso
4. **Allinea** bene per facilitare la lettura

### 🔄 Aggiornamenti Automatici

Quando aggiungi un libro, il sistema aggiorna automaticamente:
- ✅ **Contatore totale libri** nella dashboard
- ✅ **Statistiche per genere** (se assegnato)
- ✅ **Mappa scaffali** con occupazione
- ✅ **Lista autori** con nuovo conteggio libri
- ✅ **Ricerca full-text** (il libro è immediatamente ricercabile)
- ✅ **Cache** del catalogo pubblico

---

## ❓ Problemi Comuni e Soluzioni Dettagliate

### 🔴 "Importazione ISBN non trova niente"

**Possibili Cause**:
- ISBN errato o con typo
- Libro troppo vecchio (pre-1970)
- Edizione locale non catalogata online
- Connessione internet assente

**Soluzioni Step-by-Step**:
1. **Verifica l'ISBN sul libro**: Controlla cifra per cifra
2. **Prova varianti**:
   - Con trattini: `978-88-429-3578-0`
   - Senza trattini: `9788842935780`
   - ISBN-10 invece di ISBN-13
3. **Cerca online manualmente**: Google "ISBN 9788842935780" per verificare
4. **Usa inserimento manuale**: Se proprio non lo trova

### 🔴 "ISBN già esistente nel database"

**Possibili Cause**:
- Il libro è già stato inserito in precedenza
- Hai copie multiple e hai già inserito la prima
- Edizioni diverse con stesso ISBN (raro ma possibile)

**Soluzioni Step-by-Step**:
1. **Cerca il libro**: Usa la barra di ricerca con l'ISBN
2. **Verifica se è lo stesso**:
   - Stesso titolo e autore? → È un duplicato
   - Edizione diversa? → Potrebbe essere legittimo
3. **Se è un duplicato**: Clicca "Modifica" e aggiorna le copie
4. **Se è un'altra copia fisica**: Aumenta il numero "Copie totali"
5. **Se è un'edizione diversa**: Modifica leggermente il titolo (es. aggiungi "2ª ed.")

### 🔴 "Non riesco a caricare la copertina"

**Possibili Cause**:
- File troppo grande (> 5 MB)
- Formato non supportato (es. GIF, BMP, TIFF)
- Permessi insufficienti sulla cartella upload

**Soluzioni Step-by-Step**:
1. **Controlla dimensione**:
   - Windows: Tasto destro → Proprietà
   - Mac: Cmd+I
   - Se > 5MB: ridimensiona con un editor
2. **Converti formato**:
   - Usa tool online come "Convert to JPG"
   - Oppure apri in Paint/Anteprima e salva come JPG
3. **Riduci risoluzione**: 400×600 pixel è sufficiente
4. **Prova drag & drop**: A volte funziona meglio dell'upload classico

### 🔴 "Posizione già occupata"

**Possibili Cause**:
- Hai inserito manualmente un numero già assegnato
- Database non sincronizzato (rarissimo)

**Soluzioni**:
1. **Usa "Genera automaticamente"**: Il sistema trova il primo posto libero
2. **Scegli manualmente** un numero diverso (prova +1, +2, etc.)
3. **Verifica lo scaffale**: Magari c'è confusione con scaffali diversi

### 🔴 "Autore non si crea automaticamente"

**Possibili Cause**:
- Nome troppo corto (< 2 caratteri)
- Caratteri speciali non supportati
- Bug temporaneo

**Soluzioni**:
1. **Scrivi nome completo**: "Eco" → "Umberto Eco"
2. **Rimuovi caratteri strani**: Emoji, simboli rari
3. **Aggiorna la pagina** e riprova
4. **Contatta l'amministratore**: Se persiste

---

## 🎓 Esempi Pratici Completi

### 📘 Esempio 1: Romanzo Moderno con ISBN

**Scenario**: Hai appena comprato "Il nome della rosa" di Umberto Eco

#### Passo-per-passo:
1. **Trova ISBN** sul retro: `9788845297572`
2. **Accedi**: Dashboard → Libri → "+ Nuovo Libro"
3. **Importa**: Digita ISBN nella sezione "Importa da ISBN" → Clicca "Importa Dati"
4. **Attendi**: 3-5 secondi, il sistema compila tutto automaticamente
5. **Verifica dati importati**:
   - ✅ Titolo: "Il nome della rosa"
   - ✅ Autore: "Umberto Eco"
   - ✅ Editore: "Bompiani"
   - ✅ Copertina: Caricata automaticamente
6. **Completa**:
   - Scaffale: "B - Narrativa Italiana"
   - Mensola: "Livello 3"
   - Posizione: "Genera automaticamente" → Assegna "12"
   - Dewey (opzionale): 850 - Letteratura Italiana
7. **Salva** → Successo!
8. **Stampa etichetta** → Applica sul dorso
9. **Colloca libro** nella posizione B.3.12

**Tempo totale**: 2 minuti

---

### 📕 Esempio 2: Libro Antico Senza ISBN

**Scenario**: Donazione di "Racconti popolari" del 1955, nessun ISBN

#### Passo-per-passo:
1. **Raccogli info dal libro**:
   - Titolo: "Racconti popolari della Lombardia"
   - Autore: "AA.VV." (Autori Vari)
   - Editore: "Editrice Lombarda"
   - Data: "1955"
   - Pagine: ~280 (conta l'ultima)
2. **Accedi**: Dashboard → Libri → "+ Nuovo Libro"
3. **Compila manualmente**:
   - **Titolo**: "Racconti popolari della Lombardia"
   - **Autore**: Scrivi "AA.VV." → Invio (lo crea automaticamente)
   - **Editore**: Scrivi "Editrice Lombarda" → Invio
   - **Data pubblicazione**: "1955"
   - **Pagine**: 280
   - **Lingua**: "Italiano"
4. **Fotografia copertina**:
   - Scatta foto con telefono
   - Trasferisci al PC
   - Carica l'immagine
5. **Classificazione**:
   - Genere: Prosa → Narrativa → Racconti
   - Dewey: 800 > 850 > 853 (Narrativa italiana)
6. **Posizione**:
   - Scaffale: "E - Libri Storici"
   - Mensola: "Livello 2"
   - Posizione: 7
   - Collocazione: E.2.7
7. **Note varie**: "Edizione del 1955, copertina originale, buone condizioni"
8. **Salva** → Successo!

**Tempo totale**: 8-10 minuti

---

### 📗 Esempio 3: Saggistica Scientifica con EAN

**Scenario**: "Breve storia del tempo" di Stephen Hawking

#### Passo-per-passo:
1. **Trova EAN** (codice a barre): `9788817056304`
2. **Importa**: Inserisci nella sezione "Importa da ISBN o EAN"
3. **Dati recuperati**:
   - ✅ Titolo: "Dal big bang ai buchi neri: Breve storia del tempo"
   - ✅ Sottotitolo: Recuperato automaticamente
   - ✅ Autore: "Stephen Hawking"
   - ✅ Editore: "Rizzoli"
   - ✅ Copertina: Scaricata
4. **Dewey specifico**:
   - Classe: 500 - Scienze
   - Divisione: 520 - Astronomia
   - Sezione: 523.1 - Cosmologia
   - Codice finale: 523.1
5. **Posizione logica**:
   - Scaffale: "C - Saggistica Scientifica"
   - Mensola: "Livello 1" (Astronomia)
   - Posizione: Genera automaticamente → 5
6. **Tag keywords**: "cosmologia, universo, fisica, buchi neri, tempo"
7. **Salva** → Libro pronto!

**Tempo totale**: 3 minuti

---

### 📙 Esempio 4: Gestione Copie Multiple

**Scenario**: Hai 3 copie de "Il Signore degli Anelli"

#### Strategia A: Tre Libri Separati (❌ Non consigliato)
- Problema: Triplica il database
- Problema: Difficile tenere traccia delle copie

#### Strategia B: Un Libro con Copie Multiple (✅ Consigliato)
1. **Inserisci normalmente** la prima copia
2. **Nel campo "Copie totali"**: Scrivi `3`
3. **Nel campo "Copie disponibili"**: Scrivi `3` (all'inizio tutte disponibili)
4. **Sistema**: Gestisce automaticamente i prestiti
   - Quando prestiti la prima: Copie disponibili → 2
   - Quando prestiti la seconda: Copie disponibili → 1
   - Quando tutte prestate: Copie disponibili → 0 (Stato: "Non disponibile")

---

## 📞 Supporto e Documentazione Aggiuntiva

### 📚 Guide Correlate
- [Guida Scheda Libro](/docs/scheda_libro.MD) - Come visualizzare i dettagli del libro
- [Guida Modifica Libro](/docs/modifica_libro.MD) - Come modificare i dati dopo l'inserimento
- [Guida Stampa Etichette](/docs/stampa_etichette.MD) - Come stampare l'etichetta
- [Guida Collocazione](/docs/collocazione.MD) - Come organizzare scaffali e mensole
- [Guida Prestiti](/docs/prestiti.MD) - Come gestire i prestiti dei libri

### 💬 Se Hai Bisogno di Aiuto

#### 1. Consulta questa guida
- Usa la ricerca (Ctrl+F) per trovare la tua domanda
- Controlla la sezione "Problemi Comuni"

#### 2. Verifica i campi obbligatori
- Titolo e Autore sono sempre richiesti
- Scaffale e Mensola per la posizione fisica

#### 3. Chiedi a un collega
- Qualcuno che usa già il sistema
- Può mostrarti praticamente il processo

#### 4. Contatta l'amministratore
- Email o numero di telefono dell'amministratore di sistema
- Descrivi il problema con screenshot se possibile

### 🐛 Segnalazione Bug o Problemi Tecnici

Se riscontri problemi tecnici:
1. **Annota esattamente** cosa stavi facendo
2. **Fai uno screenshot** dell'errore
3. **Prova a riprodurre** il problema
4. **Segnala all'amministratore** con i dettagli

---

## 🎯 Riepilogo Veloce

### ⚡ Inserimento Rapido (2 minuti)
1. Trova ISBN/EAN sul libro
2. Dashboard → Libri → + Nuovo
3. Importa da ISBN → Attendi
4. Assegna Scaffale/Mensola/Posizione
5. Salva → Stampa etichetta → Fatto!

### 📝 Inserimento Completo Manuale (10 minuti)
1. Raccogli tutte le info dal libro
2. Compila: Titolo, Autore, Editore, Data
3. Aggiungi: ISBN, Pagine, Formato, Lingua
4. Carica copertina (foto o scan)
5. Assegna Dewey (facoltativo)
6. Assegna Posizione fisica
7. Scrivi Descrizione
8. Salva → Stampa etichetta → Colloca libro

---

**📖 Documento aggiornato: 19 Ottobre 2025**
**📌 Versione: 3.0.0 - Guida Completa Estesa**
**✍️ Per utenti finali della biblioteca**