# Guida Completa: La Scheda Libro

## Introduzione

La **scheda libro** è la pagina di dettaglio di ogni libro nel tuo sistema. Qui puoi:
- **Visualizzare tutte le informazioni** del libro
- **Modificare i dati** quando serve
- **Stampare l'etichetta** per il dorso
- **Visualizzare i prestiti** attivi
- **Gestire lo stato** del libro
- **Accedere alle azioni rapide**

È il **punto centrale** dove ritrovare tutto quello che riguarda un singolo libro.

---

## Come Accedere alla Scheda Libro

### Metodo 1: Dalla Lista Libri (Più veloce)
1. **Vai su**: Dashboard → **Libri**
2. **Cerca** il libro che ti interessa
3. **Clicca sul titolo** (in blu/link) nella lista
4. **Si apre**: La scheda completa del libro

### Metodo 2: Dalla Ricerca Globale
1. **Usa la barra di ricerca** in alto
2. **Digita**: Titolo o autore
3. **Risultati**: Appariranno automaticamente
4. **Clicca**: Sul titolo per aprire la scheda

### Metodo 3: Dalla Modifica
1. Sei in modifica di un libro
2. **Salva** le modifiche
3. **Vedrai**: Link "Visualizza scheda"
4. **Clicca**: Per tornare alla scheda

---

## Sezioni della Scheda Libro

### 1⃣ Intestazione (In Alto)

**Contiene**:
-  **Titolo del libro** (grande e visibile)
-  **Autore/i** (sottotitolo)
- 🆔 **ID Libro** (es. #56 - numero univoco)
- ⭐ **Valutazione** (se attivata)

**Bottoni Principali**:
-  **Modifica** - Va alla form di modifica
-  **Stampa Etichetta** - Genera il PDF per stampare
- ⋮ **Menu Azioni** - Altre operazioni

---

### 2⃣ Anteprima Copertina (Sinistra)

**Visualizza**:
-  **Immagine della copertina** ad alta risoluzione
-  **Dimensioni della copertina** salvate
-  **Link per aggiornare** la copertina

**Informazioni Rapide**:
-  Disponibilità attuale
-  Collocazione (es. A.2.15)
-  Link diretto al libro

---

### 3⃣ Informazioni Base (Sezione Principale)

#### Titolo e Autori
```
Titolo: "Il nome della rosa"
Sottotitolo: "Un'indagine nel cuore del Medioevo"
Autore/i:
  - Umberto Eco (autore principale)
  - [Altri autori se presenti]
```

#### Dati Editoriali
| **Campo** | **Cosa contiene** | **Esempio** |
|-----------|-------------------|------------|
| **Editore** | Casa editrice | "Bompiani" |
| **Data Pubblicazione** | Quando è uscito | "26 agosto 2025" |
| **Pagine** | Numero totale | "320" |
| **Formato** | Tipo rilegatura | "Brossura" |
| **Lingua** | Lingua del testo | "Italiano" |

#### Codici Identificativi
| **Campo** | **Cosa contiene** | **Formato** |
|-----------|-------------------|------------|
| **ISBN-10** | 10 caratteri | `8842935786` |
| **ISBN-13** | 13 cifre | `9788842935780` |
| **EAN** | Codice a barre | `9788842935780` |

---

### 4⃣ Classificazione (Sezione Centrale)

#### Classificazione Dewey
```
Classe: 800 - Letteratura
Divisione: 850 - Letteratura Italiana
Sezione: 853 - Narrativa Italiana
Codice: 853
```

#### Genere Letterario
```
Radice: Prosa
Genere: Narrativa
Sottogenere: Storico
```

#### Categorie
```
Categoria: Letteratura
Categoria Genitore: Narrativa Italiana
```

---

### 5⃣ Posizione Fisica (Sezione Importante)

**Layout della Biblioteca**:
```
Scaffale: [A] Scaffale Principale
Mensola: Livello 2
Posizione: 15
━━━━━━━━━━━━━━━━━━━
COLLOCAZIONE: A.2.15
```

**Cosa Significano**:
- **Scaffale A**: Lo scaffale vero e proprio (A, B, C, ecc.)
- **Mensola 2**: Il secondo ripiano da cima a fondo
- **Posizione 15**: Il 15° libro da sinistra a destra
- **Collocazione A.2.15**: Indirizzo completo fisico del libro

>  **Per approfondire**: Leggi la [Guida Collocazione](/docs/collocazione.MD) per capire come è organizzata la biblioteca.

---

### 6⃣ Gestione Biblioteca (Sezione Amministrativa)

#### Inventario
| **Campo** | **Cosa contiene** | **Esempio** |
|-----------|-------------------|------------|
| **Numero Inventario** | Codice interno | "INV-2024-001" |
| **Copie Totali** | Quante copie possiedi | "3" |
| **Copie Disponibili** | Quante si possono prestare | "1" |
| **Collana** | Nome collana se applicabile | "I Meridiani" |
| **Numero Serie** | Posizione nella collana | "15" |

#### Stato Attuale
```
Stato: Disponibile (verde )
Disponibilità: 1 di 3 copie
Ultimo aggiornamento: 19/10/2025
```

**Stati possibili**:
-  **Disponibile** - Pronto per prestito
-  **Prestato** - In prestito attivo
-  **Non Disponibile** - Temporaneamente non accessibile
-  **Danneggiato** - Necessita riparazione
-  **Perso** - Da ricercare/sostituire
-  **Riservato** - Riservato da qualcuno
-  **In Riparazione** - Essendo aggiustato

---

### 7⃣ Dettagli Fisici (Sezione Dimensioni)

```
Peso: 0.450 kg (450 grammi)
Dimensioni: 21x14 cm (larghezza x altezza)
Formato: Brossura
```

---

### 8⃣ Acquisizione (Sezione Storico)

#### Quando è Entrato in Biblioteca
| **Campo** | **Cosa contiene** | **Esempio** |
|-----------|-------------------|------------|
| **Data Acquisizione** | Quando lo hanno comprato/ricevuto | "15/10/2024" |
| **Tipo Acquisizione** | Come lo hanno ottenuto | "Acquisto", "Donazione" |
| **Prezzo** | Prezzo di copertina/pagato | "19.90 €" |

---

### 9⃣ Descrizione e Parole Chiave

#### Descrizione (Sezione Trama)
```
"Un'indagine nel monastero benedettino del XIV secolo
attraverso una serie di misteriosi omicidi in una biblioteca
talmente complessa che nessuno conosce realmente la sua
struttura. Una straordinaria lezione di semiotica medievale."
```

#### Parole Chiave (Tag per Ricerca)
```
Tags: fantasy, medioevo, mistero, Umberto Eco, semiotica
```

#### Note Varie (Campo Libero)
```
"Prima edizione, copertina lievemente consumata.
Libro in ottime condizioni di lettura.
Donazione dalla biblioteca comunale di Milano."
```

---

### Sezione Prestiti (Se Attiva)

**Se il libro è in prestito**:
```
╔════════════════════════════════════╗
║ PRESTITO ATTIVO                    ║
╠════════════════════════════════════╣
║ Chi: Mario Rossi                   ║
║ Data Inizio: 15/10/2025            ║
║ Data Prevista Restituzione: 29/10  ║
║ Stato: In corso                    ║
╚════════════════════════════════════╝
```

**Azioni disponibili**:
-  Visualizza dettagli prestito
-  Conferma restituzione (quando torna)
-  Invia promemoria

---

## Bottoni e Azioni Disponibili

### Bottone  Modifica
**Cosa fa**: Apre il form di modifica del libro

**Quando usarlo**:
- Correggere errori
- Aggiornare informazioni
- Cambiare posizione fisica
- Aggiungere dati mancanti

**Link**: `/admin/libri/update/56` (es. con ID 56)

>  **Per approfondire**: Leggi la [Guida Modifica Libro](/docs/modifica_libro.MD) per tutte le operazioni possibili.

---

### Bottone  Stampa Etichetta
**Cosa fa**: Genera un PDF con l'etichetta adesiva

**Formato**:
- 25×38mm (standard, consigliato)
- 50×25mm (per libri spessi)
- 70×36mm (speciale)

**Contenuto dell'etichetta**:
```
┌─────────────────────┐
│  Biblioteca         │  ← Nome della tua biblioteca
│  [Nome]             │
├─────────────────────┤
│                     │
│  Il Nome della      │  ← Titolo (abbreviato)
│  Rosa               │
│                     │
│  Umberto Eco        │  ← Autore principale
│                     │
│  ▐▌▐▌▐▌▐▌▐▌        │  ← Codice a barre (ISBN)
│                     │
│      A.2.15        │  ← Collocazione
└─────────────────────┘
```

**Come usarla**:
1. **Clicca** "Stampa Etichetta"
2. **Si apre** un PDF
3. **Stampa** su carta adesiva 25×38mm
4. **Taglia e applica** sul dorso del libro

>  **Per approfondire**: Leggi la [Guida Stampa Etichette](/docs/stampa_etichette.MD) per istruzioni complete.

---

### Bottone ⋮ Menu Azioni Rapide

**Azioni disponibili**:
-  **Duplica** - Crea una copia del libro con nuovi dati
-  **Stampa** - Stampa la scheda completa
-  **Esporta** - Esporta i dati in formato vario
-  **Elimina** - Rimuove il libro (con conferma)

---

## Workflow Completo da Scheda Libro

### Scenario 1: Vuoi Modificare Il Libro
```
1. Apri la scheda
2. Clicca  "Modifica"
3. Cambai quello che serve
4. Clicca "Salva Modifiche"
5. Torni automaticamente alla scheda aggiornata
```

### Scenario 2: Vuoi Stampare L'etichetta
```
1. Apri la scheda
2. Clicca  "Stampa Etichetta"
3. Si apre il PDF
4. Stampa su carta adesiva 25x38mm
5. Applica sul dorso del libro
```

### Scenario 3: Il Libro è In Prestito
```
1. Apri la scheda
2. Vedrai la sezione PRESTITO ATTIVO
3. Quando il libro ritorna:
   - Clicca  "Conferma Restituzione"
   - Lo stato cambia a "Disponibile"
4. Puoi ora modificarlo o spostarlo
```

### Scenario 4: Vuoi Aggiungere Altre Copie
```
1. Apri la scheda
2. Clicca ⋮ "Duplica"
3. Si apre la form di modifica con i dati copiati
4. Cambia il numero inventario
5. Assegna una nuova posizione fisica
6. Salva
```

---

## Configurare i Formati Etichette

### Dove Configurare
1. **Vai su**: Dashboard → **Impostazioni**
2. **Sezione**: "Etichette" o "Label Settings"
3. **Opzioni disponibili**:
   -  **Formato**: 25×38mm, 50×25mm, 70×36mm
   -  **Tipo carta**: Adesiva, carta normale
   -  **Font e dimensioni**: (Sconsigliato cambiare)
   -  **Margini**: Automatico o personalizzato

### Passo-Passo

#### Passo 1: Accedi alle Impostazioni
```
Dashboard →  Impostazioni →  Etichette
```

#### Passo 2: Scegli Formato
```
Seleziona uno:
□ 25×38mm (standard - consigliato per tutto)
□ 50×25mm (per libri spessi/grandi)
□ 70×36mm (per etichette speciali)
```

#### Passo 3: Configura Carta
```
Tipo carta:
□ Adesiva (normale, consigliata)
□ Adesiva resistente (per ambienti umidi)
□ Normale (per prova su carta bianca)
```

#### Passo 4: Salva Configurazione
```
Clicca:  "Salva Impostazioni"
Vedrai:  "Configurazione salvata!"
```

#### Passo 5: Stampa le Etichette
```
Vai alla scheda libro →  "Stampa Etichetta"
Le nuove etichette userannno il formato configurato
```

---

## Consigli Pratici

### Best Practices
- **Controlla sempre** la collocazione prima di stampare
- **Usa sempre 25×38mm** come standard (è universale)
- **Stampa una prova** su carta normale prima sull'adesiva
- **Applica subito** dopo stampare (adesivo fresco)
- **Allinea dritto** - il lettore lo apprezzerà

### Errori da Evitare
-  Non stampare senza aver configurato il formato
-  Non usare etichette pieghe o rovinate
-  Non applicare su dorsi sporchi/polverosi
-  Non ignorare lo stato "Prestato" (è importante!)

---

## Informazioni sulla Posizione Fisica

### Come Leggere la Collocazione
```
A.2.15
│ │ └─ Posizione 15° (dal lato sinistro)
│ └─── Mensola 2 (secondo ripiano)
└───── Scaffale A (scaffale principale)
```

### Come Trovarla Fisicamente
1. **Vai allo scaffale**: Cerca il cartello "A"
2. **Conta i ripiani**: Scendi fino al Livello 2
3. **Leggi da sinistra**: Conta fino al 15° libro
4. **Dovresti trovarlo**: Il libro con etichetta A.2.15

---

## 🆘 Se Qualcosa Non Va

### "La scheda non carica"
1. Ricarica la pagina (F5)
2. Controlla la connessione internet
3. Prova su un browser diverso
4. Contatta l'amministratore

### "Non posso modificare il libro"
- Il libro potrebbe essere in prestito
- Aspetta la restituzione
- O contatta l'amministratore

### "L'etichetta non stampa"
1. Controlla che il formato sia configurato
2. Verifica che il PDF si apra
3. Prova con stampante diversa
4. Vedi la [Guida Stampa Etichette](/docs/stampa_etichette.MD)

### "Non vedo il libro nella lista"
1. Usa la ricerca con il titolo
2. Verifica che il libro esista ancora (non eliminato)
3. Prova a ricaricare la pagina
4. Contatta l'amministratore

---

## Supporto

### Hai Bisogno di Aiuto?

1. **Consulta questa guida**
   - Usa Ctrl+F per cercare
   - Scorri le sezioni principali

2. **Chiedi a un collega**
   - Ha già usato il sistema
   - Può mostrati praticamente

3. **Contatta l'amministratore**
   - Email o numero telefono
   - Descrivi esattamente il problema
   - Fai uno screenshot se possibile

---

## Guide Correlate

- [Guida Inserimento Libri](/docs/inserimento_libri.MD) - Come aggiungere un nuovo libro
- [Guida Modifica Libro](/docs/modifica_libro.MD) - Come modificare i dati
- [Guida Stampa Etichette](/docs/stampa_etichette.MD) - Come stampare e applicare
- [Guida Collocazione](/docs/collocazione.MD) - Come organizzare gli scaffali
- [Guida Prestiti](/docs/prestiti.MD) - Come gestire i prestiti

---

## Riepilogo Veloce

### Da Scheda Libro Puoi:
**Vedere** tutte le informazioni
**Modificare** i dati (clicca )
**Stampare etichetta** (clicca )
**Visualizzare prestiti** attivi
**Controllare stato** (disponibile/prestato)
**Copiare il libro** (per altre copie)
**Trovare la posizione** fisica

---

** Documento aggiornato: 19 Ottobre 2025**
** Versione: 1.0.0 - Guida Scheda Libro**
** Per utenti finali della biblioteca**
