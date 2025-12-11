# 📍 Sistema di Collocazione - Guida Completa

## Cos'è la Collocazione?

La **collocazione** è semplicemente l'**indirizzo fisico** di ogni libro nella tua biblioteca. Proprio come un indirizzo di casa, ti dice **dove trovare esattamente un libro** tra gli scaffali.

### Esempio Pratico
```
Collocazione: A.2.15
├─ A = Scaffale A
├─ 2 = Mensola (livello) 2
└─ 15 = Posizione 15
```

## Come Funziona il Sistema

### 🏗️ 1. Creare gli Scaffali
Gli **scaffali** sono i grandi contenitori principali. Li chiami con **una lettera semplice**:
- **A** per narrativa
- **B** per saggistica
- **C** per gialli
- Oppure **qualsiasi codice** ti piace (A, B, C... oppure 1, 2, 3)

### 📚 2. Aggiungere le Mensole
Ogni scaffale ha **livelli** chiamati **mensole**:
- Mensola 1 = primo ripiano
- Mensola 2 = secondo ripiano
- Mensola 3 = terzo ripiano
- E così via...

### 📍 3. Le Posizioni Automatiche
**Non devi creare le posizioni manualmente!** Il sistema le genera automaticamente quando:
- Aggiungi un nuovo libro
- La posizione diventa: **Scaffale + Mensola + numero progressivo**

## Interfaccia Utente - Passo per Passo

### 📋 Pagina Principale
Quando vai su `/admin/collocazione`, trovi una pagina divisa in **4 sezioni**:

#### 1. **Spiegazione** (a sinistra)
- **Cos'è la collocazione**: Definizione semplice
- **Esempio pratico**: Come leggere A.2.15
- **Come funziona**: I 3 passaggi in ordine
- **Suggerimenti**: Consigli pratici

#### 2. **Scaffali** (in alto a destra)
Qui **crei e gestisci gli scaffali**:
- ✅ **Aggiungi scaffale**: Inserisci codice (es. "A") e nome (es. "Narrativa")
- ✅ **Riordina**: Trascina con il mouse per cambiare ordine
- ✅ **Elimina**: Bottone cestino (solo se vuoto)
- ✅ **Statistica**: Conta quanti scaffali hai

#### 3. **Mensole** (sotto gli scaffali)
Qui **aggiungi i livelli**:
- ✅ **Seleziona scaffale**: Dal menu a tendina
- ✅ **Numero livello**: Metti 1, 2, 3 ecc.
- ✅ **Riordina**: Trascina come per gli scaffali
- ✅ **Vincoli**: Ogni scaffale+livello deve essere unico

#### 4. **Libri per Collocazione** (in basso)
La **lista completa** dei libri con la loro posizione:
- ✅ **Filtra**: Per scaffale o mensola specifica
- ✅ **Cerca**: Trova libri nella posizione che vuoi
- ✅ **Esporta**: CSV con tutti i libri e le loro posizioni
- ✅ **Modifica**: Link diretto per cambiare posizione

## Flusso Operativo Completo

### 🎯 Per Iniziare (Prima Volta)

1. **Crea gli scaffali**:
   - Vai in "Scaffali"
   - Clicca "Aggiungi"
   - Metti codice: "A" e nome: "Narrativa"
   - Ripeti per "B" = "Saggistica", "C" = "Gialli"

2. **Crea le mensole**:
   - Vai in "Mensole"
   - Seleziona "Scaffale A"
   - Metti livello "1"
   - Clicca "Aggiungi"
   - Ripeti per livello 2, 3 ecc.

3. **Assegna ai libri**:
   - Quando crei un libro, la posizione si genera automaticamente
   - Oppure modifica manualmente in "Libri per Collocazione"

### 📖 Per Trovare un Libro

1. **Guarda la collocazione**: A.2.15
2. **Vai allo scaffale A**
3. **Cerca la mensola 2**
4. **Conta fino alla posizione 15**

### 🔍 Per Controllare la Disponibilità

1. **Filtra per scaffale**: Vedi tutti i libri in "A"
2. **Filtra per mensola**: Vedi tutti i libri in "A.2"
3. **Esporta CSV**: Hai l'elenco completo per inventario

## Gestione Quotidiana

### ✅ Operazioni Facili
- **Aggiungere scaffali**: Sempre possibile
- **Cambiare ordine**: Trascina con mouse
- **Modificare nomi**: Non possibile (serve eliminare e ricreare)
- **Eliminare**: Solo se vuoti (nessun libro collegato)

### ⚠️ Attenzione
- **Codici unici**: Non puoi avere due scaffali con codice "A"
- **Scaffale+Mensola unici**: Non puoi avere "A.1" due volte
- **Posizioni automatiche**: Non modificabili manualmente

## Esempi Pratici di Utilizzo

### 📚 Esempio 1: Biblioteca Piccola
```
Scaffali: A, B
Mensole: A.1, A.2, B.1, B.2
Libri: A.1.1, A.1.2, A.2.1, B.1.1...
```

### 📚 Esempio 2: Biblioteca Grande
```
Scaffali: NAR, SAG, GIU, INF
Mensole: NAR.1, NAR.2, NAR.3, SAG.1, SAG.2...
Libri: NAR.1.1, NAR.1.2, SAG.1.1...
```

### 📚 Esempio 3: Con Categorie
```
Scaffali: FICTION, NON-FICTION, CHILDREN
Mensole: FICTION.1, FICTION.2, FICTION.3
Libri: FICTION.1.1, FICTION.1.2...
```

## Domande Frequenti

### ❓ **Che differenza c'è tra scaffale e mensola?**
- **Scaffale**: Il mobile grande (es. "A")
- **Mensola**: Il ripiano dentro il mobile (es. "1", "2", "3")

### ❓ **Le posizioni le devo mettere a mano?**
**No!** Le posizioni si generano automaticamente quando aggiungi un libro.

### ❓ **Posso usare numeri invece di lettere?**
**Sì!** Puoi usare: 1, 2, 3 oppure A1, B2, C3 - tutto ciò che ti è comodo.

### ❓ **Cosa succede se elimino uno scaffale?**
- **Se ha libri**: Non puoi eliminarlo
- **Se è vuoto**: Si elimina e i numeri si riassegnano automaticamente

### ❓ **Posso cambiare l'ordine?**
**Sì!** Trascina con il mouse e l'ordine si aggiorna automaticamente.

## Consigli Pratici

### 💡 **Per Cominciare**
1. **Inizia semplice**: A, B, C
2. **Non esagerare**: 3-4 mensole per scaffale bastano
3. **Sii consistente**: Usa lo stesso sistema per tutto

### 💡 **Per Organizzare**
- **Per genere**: A=Narrativa, B=Saggistica, C=Gialli
- **Per autore**: A=Autori A-L, B=Autori M-Z
- **Per età**: A=Adulti, B=Ragazzi, C=Bambini

### 💡 **Per Inventario**
- **Esporta CSV**: Hai l'elenco completo
- **Controlla vuoti**: Vedi quali posizioni sono libere
- **Aggiorna facile**: I cambiamenti si vedono subito

## In Sintesi

Il sistema di collocazione è **semplicissimo da usare**:

1. **Crea gli scaffali** → 2. **Crea le mensole** → 3. **I libri si posizionano da soli!**

**Non serve nessuna conoscenza tecnica** - è progettato per essere intuitivo come organizzare una libreria in casa tua.