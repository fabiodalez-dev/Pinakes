# 📦 Gestione Copie Multiple

## Introduzione

Molte biblioteche possiedono più copie fisiche dello stesso libro. Pinakes gestisce le copie multiple in modo intelligente: un unico record nel database con un contatore che traccia quante copie fisiche esistono e quante sono disponibili per il prestito.

## 🎯 Concetto Base

**Un libro → Più copie fisiche → Un unico record**

```
Esempio: "Il Signore degli Anelli"
├─ Record database: ID #123
├─ Copie totali: 3
├─ Copie disponibili: 2
└─ Copie in prestito: 1
```

Questo approccio è più efficiente che creare 3 record separati e semplifica la gestione dei prestiti.

## ➕ Come Aggiungere Copie

### Durante l'Inserimento del Libro

Quando inserisci un nuovo libro:

1. Compila tutti i dati del libro normalmente
2. Nel campo **"Copie Totali"**: Inserisci il numero di copie che possiedi
   - Esempio: Se hai 3 copie, scrivi `3`
3. Nel campo **"Copie Disponibili"**: Inserisci lo stesso numero (all'inizio tutte le copie sono disponibili)
4. Salva il libro

### Dopo l'Inserimento (Aggiungere Altre Copie)

Se acquisti altre copie di un libro già catalogato:

1. Vai a **Dashboard → Libri**
2. Cerca e trova il libro
3. Clicca su **"Modifica" (icona matita ✏️)**
4. Trova i campi **"Copie Totali"** e **"Copie Disponibili"**
5. Aumenta entrambi i numeri
   - Esempio: Avevi 2 copie, ne compri 1 nuova → cambia da `2` a `3`
6. Clicca **"Salva Modifiche"**

**Tempo richiesto:** ~30 secondi

## ➖ Come Rimuovere Copie

### Quando Una Copia Viene Persa o Danneggiata

1. Vai a **Dashboard → Libri → Modifica** del libro specifico
2. Trova i campi **"Copie Totali"** e **"Copie Disponibili"**
3. Diminuisci entrambi i numeri
4. **Aggiungi una nota** in "Note Varie" spiegando cosa è successo
   - Esempio: "Copia danneggiata e scartata il 10/12/2025"
5. Salva

⚠️ **Attenzione:** Non puoi rimuovere copie se sono in prestito. Devi prima aspettare la restituzione.

### Rimuovere Tutte le Copie (Eliminare il Libro)

Se non possiedi più nessuna copia del libro:

1. Assicurati che non ci siano **prestiti attivi**
2. Vai a **Dashboard → Libri**
3. Trova il libro
4. Clicca su **"Elimina" (icona cestino 🗑️)**
5. Conferma nel popup

⚠️ **Attenzione:** L'eliminazione è permanente e non può essere annullata.

## 📊 Come Funziona con i Prestiti

Il sistema gestisce automaticamente le copie disponibili quando vengono prestate:

### Esempio Pratico

```
Stato iniziale:
Libro: "Harry Potter e la Pietra Filosofale"
├─ Copie totali: 3
└─ Copie disponibili: 3

Un utente prende in prestito una copia:
├─ Copie totali: 3 (rimane invariato)
└─ Copie disponibili: 2 ← diminuisce automaticamente

Un secondo utente prende in prestito:
├─ Copie totali: 3
└─ Copie disponibili: 1

Un terzo utente prende in prestito:
├─ Copie totali: 3
└─ Copie disponibili: 0 ← libro non disponibile

Primo utente restituisce:
├─ Copie totali: 3
└─ Copie disponibili: 1 ← aumenta automaticamente
```

**Tu non devi fare nulla!** Il sistema aggiorna automaticamente il contatore ogni volta che un prestito viene creato o una copia viene restituita.

## 🔍 Visualizzare lo Stato delle Copie

### Nel Pannello Admin

Nella tabella **Libri**, vedi colonne che mostrano:

| Titolo | Copie Totali | Disponibili | In Prestito |
|--------|--------------|-------------|-------------|
| Il Signore degli Anelli | 3 | 2 | 1 |
| Harry Potter | 5 | 0 | 5 |
| 1984 | 1 | 1 | 0 |

### Nella Scheda Libro

Aprendo la scheda di un libro, vedi:

```
📦 Copie:
├─ Totali: 3
├─ Disponibili: 2 🟢
└─ In Prestito: 1 🔴
```

### Nel Catalogo Pubblico

Gli utenti vedono:

```
Disponibilità: 2 copie disponibili
```

Se **tutte** le copie sono in prestito:

```
Disponibilità: Non disponibile (3 in prestito)
```

## 🏷️ Etichette per Copie Multiple

### Strategia 1: Etichetta Unica per Tutte le Copie

Stampa **una sola etichetta** con la collocazione e applicala a tutte le copie. Tutte le copie avranno la stessa posizione sullo scaffale.

**Pro:**
- ✅ Semplice e veloce
- ✅ Copie intercambiabili
- ✅ Facile da gestire

**Contro:**
- ❌ Non puoi tracciare quale copia specifica è stata prestata
- ❌ Copie devono stare nello stesso scaffale

### Strategia 2: Numerazione Copie

Aggiungi un numero identificativo manualmente su ogni copia.

**Esempio:**
```
Libro: Il Signore degli Anelli (ID #123)

Copia 1: Etichetta "A.2.15-1"
Copia 2: Etichetta "A.2.15-2"
Copia 3: Etichetta "A.2.15-3"
```

**Pro:**
- ✅ Tracciabilità specifica
- ✅ Puoi identificare quale copia è danneggiata

**Contro:**
- ❌ Più complesso
- ❌ Richiede sistema di tracciamento manuale

**Raccomandazione:** Per biblioteche piccole/medie, usa la **Strategia 1** (molto più semplice). La Strategia 2 serve solo per biblioteche grandi con requisiti di tracciabilità stringenti.

## ⚙️ Impostazioni Avanzate

### Copie con Stati Diversi

Pinakes traccia a livello di record, non di singola copia fisica. Se hai bisogno di marcare una copia come danneggiata:

**Opzione 1: Note**
- Aggiungi una nota nel campo "Note Varie"
- Esempio: "Copia #2 danneggiata - copertina strappata"

**Opzione 2: Riduci Copie Disponibili**
- Se la copia danneggiata non può essere prestata
- Riduci "Copie Disponibili" di 1
- Aggiungi nota spiegando perché

**Opzione 3: Stato Generale**
- Cambia lo stato del libro in "Danneggiato"
- Questo impatta tutte le copie (usalo solo se tutte sono danneggiate)

### Copie in Sedi Diverse

Se hai filiali/sedi diverse:

**Soluzione:** Crea **record separati** per ogni sede:
```
Record 1: "Il Signore degli Anelli - Sede A"
├─ Copie totali: 2
└─ Collocazione: A.1.12

Record 2: "Il Signore degli Anelli - Sede B"
├─ Copie totali: 1
└─ Collocazione: B.3.05
```

Aggiungi la sede nel titolo o nelle note per distinguerle.

## 📊 Statistiche e Report

### Libri con Più Copie

Per vedere quali libri hanno copie multiple:

1. Vai a **Dashboard → Libri**
2. Ordina per colonna **"Copie Totali"** (clic sull'intestazione)
3. I libri con più copie appariranno in cima

### Copie Disponibili Basse

Per identificare libri molto richiesti (copie tutte in prestito):

1. Filtra per **"Disponibili" = 0**
2. Vedi quali libri hanno **tutte le copie in prestito**
3. Considera di **acquistare copie aggiuntive** per soddisfare la domanda

### Export per Inventario

Per avere un report di tutte le copie:

1. **Dashboard → Libri → Export CSV**
2. Apri il file in Excel/LibreOffice
3. Filtra/ordina per "Copie Totali" > 1

## ❓ Domande Frequenti

**D: Perché non creare record separati per ogni copia?**
R: Triplicherebbe il database inutilmente. Un unico record con contatore è più efficiente e semplifica la gestione.

**D: Posso tracciare quale copia specifica è stata prestata?**
R: No, Pinakes traccia a livello di libro, non di singola copia fisica. Per questo livello di dettaglio servirebbero sistemi enterprise come Koha o Evergreen.

**D: Cosa succede se tutte le copie sono in prestito?**
R: Il libro appare come "Non disponibile" nel catalogo. Gli utenti possono comunque metterlo in wishlist o prenotarlo.

**D: Posso dare collocazioni diverse a copie diverse?**
R: No direttamente. Soluzione: crea record separati (uno per collocazione) con nota esplicativa.

**D: Le copie in prestito contano per le statistiche?**
R: Sì! Le statistiche mostrano:
- Totale copie (incluse quelle prestate)
- Copie disponibili (escluse quelle prestate)
- Tasso di prestito per libro

**D: Cosa faccio se una copia viene rubata?**
R: Diminuisci "Copie Totali" e "Copie Disponibili" di 1, aggiungi nota "Copia rubata [data]". Se era in prestito, segna il prestito come "Perso".

## 🎯 Best Practices

### ✅ Da Fare

- **Inserisci subito** il numero corretto di copie durante l'inserimento
- **Aggiorna immediatamente** quando acquisti o rimuovi copie
- **Aggiungi note** quando diminuisci le copie (spiega perché)
- **Monitora** i libri con molte copie in prestito (alta richiesta)

### ❌ Da Evitare

- **Non creare record duplicati** per lo stesso libro
- **Non dimenticare** di aggiornare le copie quando ne aggiungi/rimuovi
- **Non rimuovere copie** se sono in prestito (aspetta il rientro)
- **Non confondere** "Copie Totali" con "Copie Disponibili"

## 🔗 Collegamenti Utili

- [→ Inserimento Libri](./inserimento.md) - Come inserire libri con copie multiple
- [→ Modifica Libri](./modifica.md) - Come modificare il numero di copie
- [→ Sistema Prestiti](../prestiti/sistema-prestiti.md) - Come le copie interagiscono con i prestiti
- [→ Inventario](../guida-admin/inventario.md) - Report e statistiche sulle copie

---

**Ultimo aggiornamento:** Dicembre 2025
**Versione documentazione:** 1.0.0
**Compatibile con:** Pinakes v0.4.1+
