# 📖 Scheda Libro - La Pagina di Dettaglio del Libro

> **Esempio URL**: `/errico-malatesta/anarchia-il-nostro-programma/61`
>
> Formato canonico: `/{author-slug}/{book-slug}/{ID}` (rotta SEO). Restano disponibili, con redirect interno, le vecchie varianti `/libro/{id}/{slug}`.

Questa è la **pagina più importante per il lettore**: qui vedi TUTTI i dettagli di un libro e puoi richiedere un prestito o aggiungerlo ai preferiti.

---

## 🎯 Layout Principale

```
┌───────────────────────────────────────────────────────────────────┐
│                      BREADCRUMB NAVIGATION                        │
│              Home > Catalogo > Categoria > Titolo Libro            │
└───────────────────────────────────────────────────────────────────┘

┌───────────────────────────────────────────────────────────────────┐
│                       HERO SECTION                                │
│          (Sfondo con copertina + overlay scuro)                   │
│  ┌──────────┐                                                     │
│  │          │  Titolo del Libro (Enorme)                         │
│  │ Copertina│  Editore - Autori (ruoli)                          │
│  │          │  Genere/Categoria tag                              │
│  │          │  🟢 Disponibile | 🔴 Non Disponibile              │
│  └──────────┘  Breadcrumb                                        │
└───────────────────────────────────────────────────────────────────┘

┌────────────────────────────┐  ┌───────────────────────────────────┐
│                            │  │      CONTENT AREA                 │
│   ACTION BUTTONS           │  │                                   │
│  ┌─────────────────────┐  │  │  📝 DESCRIZIONE SEZIONE           │
│  │ Richiedi Prestito   │  │  │  ├─ Sinossi del libro            │
│  │ ❤️ Aggiungi Prefer. │  │  │  └─ Trama completa              │
│  └─────────────────────┘  │  │                                   │
│                            │  │  📋 DETTAGLI SEZIONE             │
│   SIDEBAR INFO             │  │  ├─ ISBN-13, ISBN-10, EAN       │
│  ├─ Editore                │  │  ├─ Categoria, Genere            │
│  ├─ Stato                  │  │  ├─ Lingua, Prezzo              │
│  ├─ Copie Disponibili      │  │  ├─ Anno, Data pubblicazione    │
│  ├─ Collocazione           │  │  ├─ Pagine, Formato, Peso       │
│  ├─ Data Aggiunto          │  │  └─ Numero inventario           │
│  └─ Condividi             │  │                                   │
│     FB • Twitter • WhatsApp │  │  ⭐ RECENSIONI SEZIONE           │
│     LinkCopy                │  │  (Se disponibili - placeholder)  │
└────────────────────────────┘  │                                   │
                                 │  LIBRI CORRELATI SEZIONE          │
                                 │  ├─ Stesso autore                │
                                 │  ├─ Stesso genere               │
                                 │  └─ Stessa categoria            │
                                 └───────────────────────────────────┘
```

---

## 🎬 Hero Section (In Alto)

### **Background Personalizzato**

L'hero ha uno **sfondo dinamico** che cambia per ogni libro:
- 📷 Immagine della copertina (semitrasparente)
- 🌀 Effetto blur per leggibilità
- 📐 Overlay scuro per contrasto

### **Componenti dell'Hero**

| Elemento | Dettaglio |
|----------|-----------|
| **Copertina del Libro** | Immagine grande a sinistra (responsiva) |
| **Titolo Principale** | Titolo grande, grassetto, bianco |
| **Editore** | "Casa Editrice" con link (clicca = libri dello stesso editore) |
| **Autori** | Lista di autori con ruoli colorati |
| **Genere/Categoria** | Tag clickabili che portano al catalogo filtrato |
| **Disponibilità** | Badge 🟢 "Disponibile" o 🔴 "Non Disponibile" |
| **Breadcrumb** | Navigazione: Home > Catalogo > Categoria > Libro |

### **Autori e Ruoli**

Gli autori hanno **colori diversi per ruolo**:

| Ruolo | Colore | Esempio |
|-------|--------|---------|
| **Principale** | Blu/Gradiente | "Dante Alighieri" |
| **Coautore** | Arancione | "Giuseppe Rossi (Coautore)" |
| **Traduttore** | Viola | "Maria Bianchi (Traduttore)" |

Clicca un autore → Vai alla pagina dell'autore con TUTTI i suoi libri.

---

## 🔘 Bottoni Azione (Action Buttons)

### **1. Richiedi Prestito** (Principale)

**Cosa fa**:
```
Clicca → Si apre un popup di calendario
         ↓
Scegli data di inizio → Scegli data di fine (opzionale)
         ↓
Clicca "Invia Richiesta"
         ↓
Richiesta inviata all'admin per approvazione
         ↓
Ricevi email di conferma quando approvata
```

**Stato del bottone**:
- 🟢 **Verde pieno** = Libro disponibile ("Richiedi Prestito")
- 🔴 **Grigio/Rosso** = Libro in prestito ("Prenota Quando Disponibile")

**Processo Calendario**:
```
┌─────────────────────────────────┐
│  Richiesta Prestito             │
│                                 │
│  Quando vuoi iniziare?          │
│  [📅 gg-mm-yyyy]                │
│                                 │
│  Fino a quando? (opzionale)     │
│  [📅 gg-mm-yyyy]                │
│                                 │
│  ℹ️ Date rosse non disponibili  │
│     (Altre persone le hanno     │
│      già prenotate)             │
│                                 │
│  [Annulla]  [Invia Richiesta]   │
└─────────────────────────────────┘
```

✅ **Se non specifichi fine**: Il sistema usa la durata predefinita configurata dall'admin (`loans.loan_duration_days`, default **30 giorni**).

### **2. Aggiungi ai Preferiti** (Cuore ❤️)

**Cosa fa**:
- Clicca → Libro aggiunto ai tuoi preferiti
- Clicca di nuovo → Rimosso dai preferiti
- Il bottone cambia colore (rosso = aggiunto, grigio = rimosso)

**Necessario**: Devi essere **loggato** per usare questa funzione.

**Se non loggato**:
Vedi "Accedi per aggiungere ai Preferiti" → Clicca → Vai a login.

---

## 📝 Sezione Descrizione

### **Sinossi / Trama del Libro**

**Cosa è**: La descrizione completa del libro.

**Formattazione**:
- A capo mantenuti (vai a riga)
- Testo leggibile senza formattazione complessa
- Se manca: "Nessuna descrizione disponibile"

**Lunghezza**: Può essere molto lunga - scorri per leggere tutto.

**Fonte**: Importata automaticamente durante lo scraping ISBN, oppure inserita manualmente.

---

## 📋 Sezione Dettagli

### **Grid con 2 Colonne** (Desktop) o 1 Colonna (Mobile)

**Colonna Sinistra**:

| Campo | Esempio |
|-------|---------|
| **ISBN-13** | 978-88-17-14656-7 |
| **ISBN-10** | 88-17-14656-5 |
| **EAN** | 9788817146567 |
| **Categoria** | Narrativa |
| **Genere** | Poesia |
| **Lingua** | Italiano |
| **Prezzo** | €15,00 |

**Colonna Destra**:

| Campo | Esempio |
|-------|---------|
| **Anno Pubblicazione** | 2023 |
| **Data Pubblicazione** | 15 marzo 2023 |
| **Numero Pagine** | 324 |
| **Formato** | Brossura |
| **Dimensioni** | 21 x 15 cm |
| **Peso** | 0.45 kg |
| **Inventario** | LIB-00156 |

---

## 👥 Sidebar (Colonna Destra)

### **Card: Informazioni Libro**

```
┌─────────────────────────────┐
│ ℹ️ Informazioni Libro        │
├─────────────────────────────┤
│ Editore:                    │
│ Casa Editrice ABC           │ (link)
│                             │
│ Stato:                      │
│ 🟢 Disponibile              │
│                             │
│ Copie Disponibili:          │
│ 3 di 5 copie                │
│                             │
│ Collocazione:               │
│ A.2.15                      │ (scaffale A, mensola 2, posizione 15)
│                             │
│ Aggiunto il:                │
│ 19 Ottobre 2025             │
└─────────────────────────────┘
```

**Cosa significa "Collocazione A.2.15"**:
- **A** = Scaffale A della biblioteca
- **2** = Mensola 2 di quello scaffale
- **15** = 15ª posizione sulla mensola
- → È come un "indirizzo" del libro in biblioteca

### **Card: Condividi**

```
┌─────────────────────────────┐
│ 🔗 Condividi                 │
├─────────────────────────────┤
│  [f] [tw] [wa] [link]       │
│  Facebook, Twitter, WhatsApp│
│  e copia link               │
└─────────────────────────────┘
```

**Funzioni**:
- 📘 **Facebook** = Condividi su Facebook
- 🐦 **Twitter** = Cita su Twitter
- 💬 **WhatsApp** = Invia su WhatsApp
- 🔗 **Link** = Copia URL della pagina

---

## ⭐ Sezione Recensioni

**Stato attuale**: implementata. La sezione "Recensioni" compare quando il libro ha recensioni approvate.

**Cosa mostra**:
- **Riepilogo**: voto medio (es. `4.3`), stelle, numero totale di recensioni
- **Distribuzione**: barre per il numero di recensioni a 1–5 stelle
- **Elenco recensioni**: per ogni recensione approvata vengono mostrati nome utente, data di approvazione, stelle, titolo e testo

**SEO**: se ci sono recensioni, la pagina include lo schema.org `aggregateRating` (`ratingValue` + `reviewCount`).

---

## 🔗 Sezione Libri Correlati ("Potrebbero Interessarti")

**Cosa è**: 3 libri simili a questo.

**Criteri di selezione** (in ordine di priorità):

1️⃣ **Stesso autore** (più rilevante)
   - Se l'autore ha altri libri, mostri prima questi

2️⃣ **Stesso genere** (secondo)
   - Se lo stesso autore non ha altri libri, mostra dello stesso genere

3️⃣ **Stessa categoria** (terzo)
   - Se nemmeno il genere corrisponde, mostra della stessa categoria

4️⃣ **Ultimi aggiunti** (fallback)
   - Se nessuno dei criteri precedenti, mostra semplicemente i più recenti

**Layout Card Correlato**:
```
┌─────────────────────────────┐
│  [Copertina]                │
│  Titolo del Libro Correlato │
│  Autore Correlato           │
│  [Vedi Dettagli]            │
└─────────────────────────────┘
```

**Clicca**:
- Copertina → Vai ai dettagli del libro correlato
- "Vedi Dettagli" → Stessa azione
- Autore → Vai alla pagina dell'autore

---

## 🌐 URL e Slug

**Formato URL**:
```
/{author-slug}/{book-slug}/{ID}
```

**Esempi**:
```
/errico-malatesta/anarchia-il-nostro-programma/61
/gabriel-garcia-marquez/cent-anni-di-solitudine/42
/dante-alighieri/la-divina-commedia/1
```

**Come funziona lo slug**:
- Il titolo viene convertito in **slug** (lettere minuscole, trattini)
- "Anarchia: il nostro programma!" → "anarchia-il-nostro-programma"
- Se cambi l'ID, la pagina cambia libro
- Se cambi solo lo slug, funziona lo stesso (il sistema usa l'ID)

---

## 📊 Disponibilità e Prestiti

### **Come capire se un libro è disponibile**

| Indicatore | Significa |
|-----------|-----------|
| **🟢 Disponibile** | Almeno 1 copia è libera ORA |
| **🔴 Non Disponibile** | Tutte le copie sono in prestito |
| **"3 di 5 copie"** | 3 copie libere su 5 totali |

### **Cosa puoi fare se è in prestito**

```
Il libro è 🔴 Non Disponibile
          ↓
Clicca "Prenota Quando Disponibile"
          ↓
Scegli data di inizio (quando pensi sia libero)
          ↓
Invia richiesta
          ↓
Admin valida la prenotazione
          ↓
Il libro sarà riservato per te!
```

---

## 👤 Profili Autori

**Clicca un autore** → Vai a una pagina che mostra:
- 📚 TUTTI i libri di quell'autore
- 📝 Biografia (se disponibile)
- 🔗 Link al profilo

**URL** (locale italiano): `/autore/{id}` (es. `/autore/1`) oppure `/autore/{name}` (es. `/autore/Dante-Alighieri`). In locale inglese il prefisso è `/author/...`.

---

## 🏢 Profilo Editore

**Clicca nome editore** → Vai a una pagina che mostra:
- 📚 TUTTI i libri di quell'editore
- 🌐 Link al sito web (se disponibile)
- 📍 Indirizzo (se disponibile)

**URL** (locale italiano): `/editore/{id}` o `/editore/{name}` (es. `/editore/Mondadori`). In locale inglese il prefisso è `/publisher/...`.

---

## 📱 Layout Mobile

**Cambios su smartphone**:

| Elemento | Desktop | Mobile |
|----------|---------|--------|
| **Hero** | Copertina a sinistra | Copertina in alto, centrata |
| **Autori** | In fila orizzontale | In colonna |
| **Bottoni** | Fianco a fianco | Uno sotto l'altro (stack) |
| **Dettagli Grid** | 2 colonne | 1 colonna |
| **Sidebar** | Accanto contenuto | Sotto il contenuto |
| **Font** | Normale | Leggibile senza zoom |

---

## 🔍 SEO e Metadati

La pagina è **SEO-optimizzata**:
```
<title>Anarchia: il nostro programma - Biblioteca</title>
<description>Scopri "Anarchia: il nostro programma"...
<image>Copertina del libro</image>
<author>Autore principale</author>
```

**Perché**: Se condividi su social media, appare un'anteprima bella.

---

## ❓ Domande Frequenti

### **D: Quanto dura un prestito?**

✅ **Di default 30 giorni**, ma dipende dalle impostazioni della biblioteca. Puoi specificare una data di fine diversa al momento della richiesta.

### **D: Come faccio la richiesta di prestito se il libro è già in prestito?**

✅ Il bottone cambia a **"Prenota Quando Disponibile"**. Scegli la data di inizio che preferisci e il sistema ti prenoterà un posto in coda.

### **D: Posso cancellare una richiesta di prestito?**

✅ Una volta inviata, devi andare nel tuo profilo → Prestiti → Trovare la richiesta → Cancellare (se ancora non è stata approvata).

### **D: I preferiti si sincronizzano su altri dispositivi?**

✅ **Sì!** Se sei loggato con lo stesso account, i preferiti si vedono su qualunque dispositivo.

### **D: Posso leggere il libro online?**

❌ No, Pinakes è solo per la **gestione dei prestiti fisici**. Non fornisce letture online.

### **D: Se il libro non ha descrizione?**

ℹ️ Vedrai "Nessuna descrizione disponibile". È normale per libri importati automaticamente. L'admin può aggiungerla manualmente.

### **D: Che differenza c'è tra ISBN-10 e ISBN-13?**

📖 Entrambi identificano il libro:
- **ISBN-10**: Vecchio formato (10 cifre) - usato fino a 2007
- **ISBN-13**: Nuovo formato (13 cifre) - obbligatorio da 2007 in poi
- Sono legati lo stesso libro, basta uno per cercare

### **D: Cosa significa "Collocazione A.2.15"?**

📍 È l'indirizzo fisico del libro nella tua biblioteca:
- **A** = Scaffale A (primo scaffale)
- **2** = Mensola 2 (secondo piano dello scaffale)
- **15** = Posizione 15 (il 15° libro da sinistra)

### **D: Posso scaricare la scheda del libro?**

❌ Non direttamente. Ma puoi:
- Stampa la pagina (CTRL+P / CMD+P)
- Copia il testo
- Condividi via social / email

### **D: Come aggiungo questo libro ai preferiti?**

✅ Devi essere **loggato** → Clicca il bottone ❤️ "Aggiungi ai Preferiti".

---

## 🔗 Link Interni

**Da scheda libro puoi andare a**:

| Clicca su | Vai a |
|-----------|-------|
| **Titolo Autore** | Pagina autore con tutti i suoi libri |
| **Editore** | Pagina editore con tutti i suoi libri |
| **Categoria tag** | Catalogo filtrato per categoria |
| **Genere tag** | Catalogo filtrato per genere |
| **Libro Correlato** | Scheda di quel libro |
| **Breadcrumb Home** | Home page |
| **Breadcrumb Catalogo** | Catalogo completo |
| **Breadcrumb Categoria** | Catalogo filtrato per categoria |

---

## 📚 Prossimi Passi

- ➡️ **Vuoi cercare altri libri?** [Vai a Catalogo](./catalogo.md)
- ➡️ **Vuoi gestire i tuoi prestiti?** Vai al tuo profilo (devi essere loggato)
- ➡️ **Vuoi tornare alla home?** [Vai a Home](./home.md)

---

## 🎬 Workflow Tipico dall'Utente

```
1. Accedi a /errico-malatesta/anarchia-il-nostro-programma/61
   ↓
2. Leggi il titolo, autore, e description
   ↓
3. Vedi se è disponibile (badge 🟢 o 🔴)
   ↓
4. OPZIONE A: Clicca "Richiedi Prestito" o "Prenota"
      ↓ → Scegli date
      ↓ → Invia richiesta
      ↓ → Ricevi email di conferma

   OPZIONE B: Clicca ❤️ "Aggiungi ai Preferiti"
      ↓ → Salvato nella tua lista

   OPZIONE C: Scorri e leggi i dettagli
      ↓ → Vedi ISBN, genere, pagine, ecc.

   OPZIONE D: Scorri verso il basso
      ↓ → Vedi "Potrebbero Interessarti"
      ↓ → Clicca un libro correlato
      ↓ → Vai a quella pagina

5. Clicca icona Condividi
   ↓ → Condividi su social / copia link
```

---

*Ultimo aggiornamento: 4 Giugno 2026*
*Tempo lettura: 10 minuti*
*Tempo per fare un prestito: 1-2 minuti*
