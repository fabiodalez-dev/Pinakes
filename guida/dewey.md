# Classificazione Dewey

Guida all'utilizzo della Classificazione Decimale Dewey in Pinakes.

## Cos'è la Classificazione Dewey

La Classificazione Decimale Dewey (DDC) è un sistema bibliotecario per organizzare le pubblicazioni per argomento. Utilizza numeri da 000 a 999, con decimali per maggiore specificità.

## Classi Principali

| Codice | Classe |
|--------|--------|
| 000 | Informatica, informazione, opere generali |
| 100 | Filosofia e psicologia |
| 200 | Religione |
| 300 | Scienze sociali |
| 400 | Linguaggio |
| 500 | Scienza |
| 600 | Tecnologia |
| 700 | Arti e ricreazione |
| 800 | Letteratura |
| 900 | Storia e geografia |

## Database Incluso

Pinakes include **1.287 categorie Dewey** precaricate:
- Traduzione italiana ufficiale
- Traduzione inglese disponibile
- Gerarchia completa fino a 7 livelli
- Fonte: OCLC DDC standards

## Utilizzo

### Selezione nel Form Libro

Due modalità:

**1. Inserimento Diretto**
- Digita il codice nel campo (es. `823.91`)
- Il sistema cerca automaticamente il nome
- Se esatto, mostra codice + nome
- Se non trovato, usa il nome della categoria padre

**2. Navigazione Gerarchica**
- Clicca "Naviga per categorie"
- Seleziona la classe principale
- Naviga nelle sottoclassi
- Seleziona il codice desiderato

### Formato Codici

Codici validi:
- Tre cifre: `823`
- Con decimali: `823.91`, `599.9374`
- Massimo 4 decimali dopo il punto

Codici non validi:
- Meno di tre cifre: `82`
- Più di 4 decimali: `823.91234`
- Caratteri non numerici: `823a`

## Ricerca per Dewey

### Nel Catalogo

1. Usa il filtro "Classificazione"
2. Digita il codice o parte di esso
3. I libri con quel codice o sottoclassi appaiono

### Navigazione

La pagina catalogo permette:
- Browsing per classe Dewey
- Visualizzazione gerarchica
- Conteggio libri per categoria

## Modifica Database Dewey

### Editor Integrato

Il plugin **Dewey Editor** permette di:
- Aggiungere nuovi codici
- Modificare nomi esistenti
- Eliminare codici inutilizzati
- Import/Export JSON

### Accesso

1. Installa il plugin "Dewey Classification Editor"
2. Vai in **Amministrazione → Dewey Editor**
3. Modifica le categorie necessarie

### File di Origine

I dati sono in `data/dewey/`:
- `dewey_completo_it.json` - Italiano
- `dewey_completo_en.json` - Inglese

## Localizzazione

Il sistema carica automaticamente la lingua corretta:
- Italiano: `dewey_completo_it.json`
- Inglese: `dewey_completo_en.json`

I nomi cambiano in base alla lingua dell'interfaccia.

---

## Domande Frequenti (FAQ)

### 1. È obbligatorio assegnare un codice Dewey a ogni libro?

No, il campo Dewey è opzionale. Tuttavia è consigliato perché:
- Facilita la ricerca per argomento
- Permette il browsing per categoria
- Aiuta a organizzare fisicamente la biblioteca
- È uno standard internazionale riconosciuto

**Per piccole biblioteche** che non usano Dewey, puoi semplicemente lasciare il campo vuoto.

---

### 2. Non trovo il codice Dewey esatto per il mio libro, cosa faccio?

Hai due opzioni:

**Opzione 1 - Usa un codice più generale**:
- Se cerchi `823.91` e non esiste, usa `823.9` o `823`
- Il sistema mostrerà il nome della categoria padre

**Opzione 2 - Inserisci codice personalizzato**:
- Puoi digitare qualsiasi codice valido (es. `823.91234`)
- Il sistema lo accetta anche se non è nel database
- Verrà mostrato con la categoria padre più vicina

---

### 3. Come funziona la navigazione gerarchica Dewey?

La navigazione "per categorie" ti guida passo dopo passo:

1. Clicca **"Naviga per categorie"** nel form libro
2. Seleziona la classe principale (es. "800 - Letteratura")
3. Il sistema carica le sottoclassi
4. Continua a scendere finché trovi il codice giusto
5. Clicca per selezionare

**Breadcrumb**: In alto vedi il percorso completo (es. "Home > 800 > 823 > 823.91").

---

### 4. Posso modificare o aggiungere nuovi codici Dewey?

Sì, con il plugin **Dewey Classification Editor**:

1. Installa il plugin da **Amministrazione → Plugin**
2. Vai in **Amministrazione → Dewey Editor**
3. Puoi:
   - Aggiungere nuovi codici
   - Modificare nomi esistenti
   - Eliminare codici inutilizzati
   - Importare/Esportare JSON

**Senza plugin**: modifica manualmente i file JSON in `data/dewey/`.

---

### 5. Come cambio la lingua delle categorie Dewey?

La lingua segue automaticamente le impostazioni dell'interfaccia:

- **Italiano** → `dewey_completo_it.json`
- **Inglese** → `dewey_completo_en.json`

**Per cambiare lingua**:
1. Modifica la lingua utente nel profilo
2. Oppure cambia la lingua predefinita in Impostazioni
3. I nomi Dewey si aggiornano automaticamente

---

### 6. Come faccio a trovare libri di una specifica categoria Dewey?

**Nel catalogo pubblico**:
1. Usa il filtro "Classificazione"
2. Digita il codice (es. `800` per tutta la letteratura)
3. Appariranno tutti i libri con quel codice o sottoclassi

**Per operatori**:
- La ricerca cerca anche nelle sottoclassi
- Es. cercando `800` trovi anche `823`, `823.91`, ecc.

---

### 7. Qual è la differenza tra codice a 3 cifre e con decimali?

Il sistema Dewey usa decimali per aumentare la specificità:

| Codice | Significato | Specificità |
|--------|-------------|-------------|
| `800` | Letteratura | Generale |
| `823` | Narrativa inglese | Più specifico |
| `823.91` | Romanzi inglesi moderni | Molto specifico |
| `823.912` | Romanzi inglesi 1910-1945 | Ultra-specifico |

**Regola pratica**: più decimali = più preciso. Usa il livello di dettaglio adatto alla tua biblioteca.

---

### 8. I codici Dewey sono già tradotti in italiano?

Sì, Pinakes include **1.287 categorie** completamente tradotte:

- **Fonte**: Standard OCLC DDC (Dewey Decimal Classification)
- **Italiano**: Traduzione ufficiale completa
- **Inglese**: Disponibile per biblioteche internazionali

Entrambe le versioni sono sincronizzate e contengono gli stessi codici.

---

### 9. Come posso stampare la posizione Dewey sulle etichette?

Le etichette libri includono automaticamente il codice Dewey se presente:

1. Vai nella scheda libro
2. Clicca **"Stampa Etichetta"**
3. Seleziona il formato
4. L'etichetta includerà:
   - Codice Dewey
   - Posizione/Scaffale (se impostato)
   - Codice a barre

---

### 10. Ho importato libri senza Dewey, come li assegno in massa?

Attualmente l'assegnazione Dewey va fatta libro per libro. Per grandi quantità:

**Procedura consigliata**:
1. Usa il filtro "Senza classificazione" nel catalogo
2. Ordina per genere o editore
3. Apri ogni libro e assegna il codice
4. Usa la navigazione gerarchica per velocizzare

**Suggerimento**: raggruppa libri simili e usa lo stesso codice per tutti.
