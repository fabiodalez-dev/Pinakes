# 📚 Catalogo Libri - Ricerca e Filtri Avanzati

> **Accedi qui**: http://localhost:8000/catalogo

La pagina del catalogo è il **cuore della ricerca**: puoi trovare qualunque libro con una ricerca sofisticata e filtri potenti. È come Google ma solo per i tuoi libri!

---

## 🎯 Layout Principale

La pagina è divisa in **3 sezioni**:

```
┌─────────────────────────────────────────────────────────────────┐
│                    HEADER CON TITOLO                           │
│                  "Catalogo Libri" + Breadcrumb                 │
└─────────────────────────────────────────────────────────────────┘

┌──────────────────┐  ┌─────────────────────────────────────────┐
│                  │  │  RISULTATI E GRIGLIA LIBRI             │
│  FILTRI PANEL    │  │                                         │
│  (25% larghezza) │  │  - Indicatore risultati                │
│                  │  │  - Ordinamento                         │
│  • Ricerca       │  │  - Grid responsiva con card libri      │
│  • Categorie     │  │  - Paginazione                         │
│  • Generi        │  │                                         │
│  • Editori       │  │  ┌─ Libro 1   ┌─ Libro 2   ┌─ Libro 3  │
│  • Disponibilità │  │  │             │             │          │
│  • Anno          │  │  └─────────────└─────────────└─────────  │
│  • Pagine        │  │                                         │
│                  │  │  ┌─ Libro 4   ┌─ Libro 5   ┌─ Libro 6  │
│  Pulisci Filtri  │  │  │             │             │          │
│                  │  │  └─────────────└─────────────└─────────  │
└──────────────────┘  │                                         │
                      │  << Precedente | 1 | 2 | 3 | Successiva >>
                      └─────────────────────────────────────────┘
```

---

## 🔍 Barra di Ricerca (In Alto)

**Posizione**: Nel campo "Ricerca" del pannello filtri a sinistra.

**Cosa cerca**:
- 📖 Titolo del libro
- ✍️ Nome dell'autore
- 🏢 Nome dell'editore
- 📛 ISBN / EAN (se conosci il codice)
- 📝 Qualunque parola nel titolo

**Come funziona**:
```
Digita "dante" → Sistema cerca AUTOMATICAMENTE
Risultati istantanei mentre scrivi (300ms di ritardo)

Mostra:
- Tutti i libri con "dante" nel titolo
- Tutti i libri di autore "Dante Alighieri"
- Tutti gli editori che contengono "dante"
```

**Esempio di ricerche**:
| Digita | Risultati |
|--------|-----------|
| "harry potter" | Tutti i libri della serie Harry Potter |
| "Rowling" | Tutti i libri di J.K. Rowling |
| "Mondadori" | Tutti i libri pubblicati da Mondadori |
| "978-88" | Libri con ISBN che inizia con 978-88 |

---

## 🏷️ Filtri Disponibili

### 1. **Categorie**

**Cosa sono**: Le **categorie principali** della biblioteca (es. Narrativa, Saggistica, Bambini, ecc.)

**Come usarli**:
1. Trova la categoria che vuoi nella lista
2. Clicca su di essa
3. La categoria diventa **blu/evidenziata**
4. I risultati si aggiornano automaticamente

**Badge**: Accanto a ogni categoria vedi un numero (es. "156") = quanti libri in quella categoria

**Filtrare per categoria**:
- Clicca 1 categoria = mostra SOLO libri di quella categoria
- Clicca di nuovo = deseleziona

**Nota**: La lista si aggiorna dinamicamente - vedrai SOLO categorie che hanno libri con gli altri filtri attivi.

---

### 2. **Generi e Sottogeneri**

**Cosa sono**: Generi letterari (Giallo, Fantasy, Romanzo, Poesia, ecc.)

**Come usarli**:
1. Scorri la lista "Generi"
2. Se il genere ha sottogeneri, sono **indentati** (spostatia destra)

**Esempio di struttura**:
```
Generi
├─ Fantasy (156)
│  ├─ Fantasy Epico (89)
│  ├─ Urban Fantasy (45)
│  └─ Dark Fantasy (22)
├─ Giallo (234)
│  ├─ Noir (112)
│  ├─ Thriller (98)
│  └─ Mistero (24)
├─ Romanzo (567)
│  ├─ Sentimentale (234)
│  ├─ Storico (189)
│  └─ Contemporaneo (144)
```

**Come funziona**:
- Clicca il genere principale = mostra TUTTI i sottogeneri
- Clicca un sottogenere = mostra SOLO libri di quel sottogenere
- Il numero accanto = quanti libri disponibili

---

### 3. **Editori**

**Cosa sono**: Le case editrici dei libri.

**Come usarli**:
1. Scorri la lista "Editori"
2. Clicca su un editore
3. Vedrai solo i libri di quell'editore

**Nota**: La lista ha **scroll interno** se molti editori (non occupa tutto lo schermo).

**Badge**: Numero di libri per editore.

---

### 4. **Disponibilità**

**3 Opzioni**:

| Opzione | Mostra | Quando usare |
|---------|--------|-------------|
| **Tutti i libri** | Disponibili + Prestati | Quando vuoi vedere tutto |
| **Disponibili** | Solo libri pronti ora | Quando vuoi prendere in prestito subito |
| **In prestito** | Solo libri attualmente prestati | Per mettere in wishlist |

**Icone visuali**:
- 🟢 Verde = Disponibili
- 🔴 Rosso = In prestito

**Come funziona**:
```
Clicca "Disponibili"
→ Mostra solo libri che puoi prendere ADESSO
→ I numeri: "234 disponibili, 156 in prestito"
```

---

### 5. **Anno di Pubblicazione (Range)**

**Cosa è**: Un **cursore doppio** per filtrare per anno.

**Come funziona**:
```
Range disponibile: 1900 - 2025 (anno attuale)

┌─────────────────────────────────────────────────┐
│ 1900 ●──────────────────────● 2025              │
│     trascina i cursori                           │
└─────────────────────────────────────────────────┘

Anno Min: [1950] -- Anno Max: [2023]
```

**Step by step**:
1. Trascina il cursore **sinistro** = imposta anno MINIMO
2. Trascina il cursore **destro** = imposta anno MASSIMO
3. I risultati si aggiornano in TEMPO REALE

**Esempio**:
```
Voglio i libri pubblicati tra 1950 e 1975

1. Imposta Min: 1950
2. Imposta Max: 1975
3. Premi reset per tornare a valori di default
```

**Reset**: Clicca il bottone "↻" per tornare ai valori di default (1900-2025).

---

## 📊 Risultati e Ordinamento

### **Indicatore Risultati** (In alto a destra)

```
234 libri trovati      ↔  [Più recenti ▼]
```

- **Numero a sinistra**: Quanti libri corrispondono ai tuoi filtri
- **Cambio automatico**: Se cambi i filtri, il numero si aggiorna

### **Ordinamento**

**Opzioni disponibili**:

| Ordinamento | Risultato |
|-------------|-----------|
| **Più recenti** | Libri appena aggiunti per primi (DEFAULT) |
| **Più vecchi** | Libri inseriti da più tempo per primi |
| **Titolo A-Z** | Alfabetico ascendente (A → Z) |
| **Titolo Z-A** | Alfabetico discendente (Z → A) |
| **Autore A-Z** | Per cognome autore (A → Z) |
| **Autore Z-A** | Per cognome autore (Z → A) |

**Come usare**:
1. Clicca il dropdown "Più recenti"
2. Scegli un ordinamento
3. La griglia si riordina immediatamente

---

## 📖 Griglia Libri

### **Una Card per Libro** (Layout Grid)

```
┌────────────────────────────────┐
│  📷 Copertina                  │  ← Immagine del libro
│       (3/4 ratio)              │
│                                │
│   [● Disponibile]              │  ← Badge di disponibilità
└────────────────────────────────┘
│ Titolo del Libro               │
│ Autore della Novella           │  ← Nome dell'autore
│ 📖 2024 | 324 pagine           │  ← Metadati
│                                │
│  [Dettagli] [Aggiungi Fav.]    │  ← Azioni
└────────────────────────────────┘
```

**Componenti di una card**:

| Elemento | Cosa è |
|----------|--------|
| **Copertina** | Clicca per andare ai dettagli del libro |
| **Titolo** | Clickable - vai ai dettagli |
| **Autore** | Nome dell'autore principale |
| **Metadati** | Anno, pagine, editore (se visibili) |
| **Badge** | 🟢 "Disponibile" o 🔴 "In prestito" |
| **Bottoni** | "Dettagli" (vai a scheda) e "❤️ Preferiti" (se loggato) |

---

## 📍 Paginazione

**In fondo alla pagina**:

```
← Precedente  | 1 | 2 | 3 | ... | 10 |  Successiva →
```

**Come funziona**:
- **12 libri per pagina**
- Clicca il numero pagina = vai a quella pagina
- **← Precedente** = pagina precedente (disabilitato se sei in pagina 1)
- **Successiva →** = pagina successiva (disabilitato se sei in ultima pagina)
- **... (puntini)** = saltano le pagine intermedie se sono molte

**Esempio**:
```
234 libri totali ÷ 12 per pagina = ~20 pagine
Clicca pagina 3 → Mostra libri 25-36
```

---

## 🏷️ Filtri Attivi (Visible Filter Indicators)

**In alto nei risultati**:

```
Filtri attivi:
[Genere: Fantasy ✕]  [Editore: Mondadori ✕]  [Anno: 1990-2020 ✕]
```

**Cosa fa**:
- **Mostra tutti i filtri attivi** in una barra
- Clicca la **✕** per rimuovere un filtro
- "**Pulisci tutti i filtri**" = reset completo

---

## 🎯 Ricerche Avanzate (Esempi Pratici)

### **Scenario 1: Cerco libri di Fantasy degli ultimi 5 anni**

```
1. Clicca "Disponibili" (se vuoi solo quelli liberi)
2. Clicca su "Fantasy" (o un sottogenere)
3. Imposta "Anno Min": 2020
4. Imposta "Anno Max": 2025
5. Risultato: Fantasy recenti disponibili
```

### **Scenario 2: Voglio TUTTI i libri di Giallo di Mondadori**

```
1. Cerca "Giallo" (categoria o genere)
2. Clicca "Mondadori" in Editori
3. Clicca "Disponibili" per vederli solo se liberi
4. Ordina per "Titolo A-Z"
5. Sfoglia le pagine
```

### **Scenario 3: Mi interessa la Narrativa Contemporanea Italiana**

```
1. Clicca "Narrativa" (categoria)
2. Filtra genere "Romanzo" → "Contemporaneo"
3. Cerca una parola chiave se necessario
4. Seleziona gli ultimi anni (es. 2015-2025)
5. Ordina per data (Più recenti)
```

### **Scenario 4: Sto cercando un libro specifico per ISBN**

```
1. Nella ricerca scrivi l'ISBN completo (es: 978-88-17-14656-7)
2. Premere Invio
3. Se esiste, comparirà tra i risultati
```

---

## 📱 Layout Mobile

**Su smartphone**:
- 📍 Filtri slider si aprono/chiudono (tap su "Filtri")
- 📖 Griglia: 1-2 colonne (non 4)
- 🔍 Barra di ricerca sempre visibile in alto
- ✨ Scrolling fluido con caricamento progressivo

**Su tablet**:
- 📍 Filtri sempre visibili a sinistra (30% larghezza)
- 📖 Griglia: 2-3 colonne
- Tutto il resto come desktop

---

## ⚡ Comportamenti Dinamici

### **Filtri Intelligenti**

I filtri si aggiornano intelligentemente:
```
Se filtri per "Fantasy" + selezioni "2024-2025"
→ I numeri delle altre categorie cambiano
→ Mostra solo quante ne hanno per quel periodo
```

### **Ricerca Istantanea**

```
Digiti "harry"
  ↓ Aspetta 300ms (debounce)
  ↓ Ricerca automatica
  ↓ Risultati aggiornati senza bottone
```

### **Persistenza URL**

```
Se navighi con filtri attivi:
/catalogo?genere=Fantasy&editore=Mondadori&anno_min=2020

Reload pagina → I filtri restano (sono nell'URL)
```

---

## ❌ Cosa Succede se Non Trovi Nulla

### **"Nessun libro trovato"**

Possibili cause:
1. **Filtri troppo restrittivi** → Rimuovi alcuni filtri
2. **Ricerca con errori di ortografia** → Riprova
3. **Nessun libro in quella combinazione** → È normale

**Soluzioni**:
```
1. Clicca "Pulisci tutti i filtri"
   → Torna a vista completa
   → Poi riapplica filtri uno alla volta

2. Usa ricerca invece di filtri
   → Più flessibile

3. Contatta admin se libro mancante
   → Potrebbe non essere stato inserito
```

---

## 🔗 Link Catalogo

**Da altre pagine**:
- **Home** → "Sfoglia Catalogo" → `/catalogo`
- **Home** → Ricerca hero → `/catalogo?q=query`
- **Scheda Libro** → "Libri simili" → `/catalogo?genere=...`
- **Profilo** → "Cronologia ricerche" → `/catalogo?...`

---

## 📊 Informazioni Libro in Catalogo

Ogni card mostra:
- 📷 Copertina
- 📚 Titolo
- ✍️ Autore principale
- 📅 Anno (se disponibile)
- 🔢 Pagine (se disponibile)
- 🟢/🔴 Disponibilità

**Per più info**: Clicca il libro → [Vai a Scheda Libro](./scheda_libro.md)

---

## ❓ Domande Frequenti

### **D: Posso cercare per prezzo?**

❌ No, il catalogo non filtra per prezzo. Filtra solo per: categoria, genere, editore, anno, disponibilità.

### **D: La ricerca è case-sensitive?**

❌ No! "dante" = "DANTE" = "Dante" - tutto uguale.

### **D: Quanti risultati per pagina?**

✅ **12 libri per pagina** (fisso).

### **D: Come salvo una ricerca?**

✅ Copia l'URL della pagina (con i filtri nell'indirizzo) e salvala nei segnalibri del browser.

### **D: Posso combinare più generi?**

❌ No, ma puoi selezionare un genere principale e poi un sottogenere.

### **D: Perché la ricerca non trova il libro che cerco?**

Possibili cause:
- Il libro non è stato inserito nel catalogo (contatta admin)
- Cerchi con un'ortografia diversa (prova varianti)
- Il titolo/autore è leggermente diverso nel database

### **D: I risultati si aggiornano mentre scrivo?**

✅ **Sì! Istantaneamente** (con 300ms di ritardo per non appesantire). Non devi premere Invio.

---

## 🚀 Workflow Tipico

```
1. Accedi a /catalogo
   ↓
2. Vedi la griglia con TUTTI i libri (ordinati per data)
   ↓
3. OPZIONE A: Usa filtri a sinistra
   ✓ Clicca categoria/genere/editore
   ✓ Vedrai solo quelli filtrati
   ✓ I numeri si aggiornano
   ↓

4. OPZIONE B: Usa la ricerca
   ✓ Digita titolo/autore/editore
   ✓ Risultati istantanei
   ✓ Raffinato con filtri se necessario
   ↓

5. Clicca un libro
   ↓
6. Vai alla scheda del libro [→ Leggi guida](./scheda_libro.md)
```

---

## 🎨 Colori e Icone

| Elemento | Colore | Significato |
|----------|--------|------------|
| 🟢 Disponibile | Verde | Puoi prendere in prestito |
| 🔴 In prestito | Rosso | Attualmente prestato |
| Titolo link | Blu | Cliccabile → dettagli |
| Filtro attivo | Blu/Highlightato | Questo filtro è activo |
| Scroll filtri | Grigio | Ci sono più opzioni |

---

## 📚 Prossimi Passi

- ➡️ **Trovi un libro che ti interessa?** [Vai a Scheda Libro](./scheda_libro.md)
- ➡️ **Vuoi tronare alla home?** [Vai a Home](./home.md)
- ➡️ **Problema con ricerca?** [Controlla API](../api.md#get-apicatalogo)

---

*Ultima lettura: 19 Ottobre 2025*
*Tempo lettura: 10 minuti*
*Tempo configurazione filtri: 2 minuti*
