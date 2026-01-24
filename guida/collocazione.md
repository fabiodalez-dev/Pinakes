# Sistema Collocazione

Il sistema collocazione permette di gestire la posizione fisica dei libri nella biblioteca.

## Panoramica

Il sistema organizza i libri in una struttura gerarchica a tre livelli:

```
Scaffale → Mensola → Posizione
    A         2          15
```

**Formato collocazione**: `A.2.15` (Scaffale A, Mensola 2, Posizione 15)

## Struttura Gerarchica

### Scaffali

Lo scaffale è il contenitore principale (armadio, libreria, etc.).

| Campo | Descrizione | Obbligatorio |
|-------|-------------|--------------|
| `codice` | Lettera identificativa (es. A, B, C) | Sì |
| `nome` | Descrizione (es. "Narrativa italiana") | No |
| `ordine` | Posizione nell'elenco | No |

**Regole**:
- Il codice viene convertito in maiuscolo automaticamente
- Il codice deve essere univoco
- Non si può eliminare uno scaffale che contiene mensole

### Mensole

La mensola è un ripiano all'interno dello scaffale.

| Campo | Descrizione | Obbligatorio |
|-------|-------------|--------------|
| `scaffale_id` | Scaffale di appartenenza | Sì |
| `numero_livello` | Numero del ripiano (1, 2, 3...) | Sì |
| `ordine` | Posizione nell'elenco | No |

**Regole**:
- Il numero livello deve essere univoco per scaffale
- Non si può eliminare una mensola che contiene libri

### Posizioni

La posizione indica lo slot specifico sulla mensola.

| Campo | Descrizione |
|-------|-------------|
| `scaffale_id` | Scaffale |
| `mensola_id` | Mensola |
| `posizione_progressiva` | Numero sequenziale (1, 2, 3...) |

## Accesso

La gestione collocazione si trova in:
- **Admin → Collocazione**

## Operazioni

### Creare uno Scaffale

1. Vai in **Admin → Collocazione**
2. Sezione **Scaffali**
3. Inserisci:
   - **Codice**: lettera univoca (es. A, B, C)
   - **Nome**: descrizione opzionale
4. Clicca **Crea Scaffale**

### Creare una Mensola

1. Sezione **Mensole**
2. Seleziona lo scaffale di appartenenza
3. Inserisci:
   - **Numero livello**: 1, 2, 3...
   - **Genera posizioni**: numero di slot da creare (opzionale)
4. Clicca **Crea Mensola**

Se specifichi "Genera posizioni", il sistema crea automaticamente N posizioni sulla mensola.

### Eliminare uno Scaffale

1. Clicca **Elimina** accanto allo scaffale
2. Conferma

**Vincoli**:
- Lo scaffale non deve contenere mensole
- Lo scaffale non deve contenere libri

### Eliminare una Mensola

1. Clicca **Elimina** accanto alla mensola
2. Conferma

**Vincoli**:
- La mensola non deve contenere libri

### Riordinare

Gli elementi possono essere riordinati tramite drag-and-drop:
- Scaffali
- Mensole
- Posizioni

L'ordine viene salvato automaticamente via API.

## Assegnare Collocazione a un Libro

### Durante l'Inserimento

Nel form del libro:
1. Seleziona **Scaffale**
2. Seleziona **Mensola**
3. Il sistema suggerisce automaticamente la **prossima posizione** disponibile

### Suggerimento per Genere

Il sistema può suggerire la collocazione in base al genere del libro:

```
GET /api/collocazione/suggest?genere_id=5&sottogenere_id=12
```

Questo aiuta a raggruppare libri dello stesso genere nello stesso scaffale.

### Calcolo Prossima Posizione

```
GET /api/collocazione/next-position?scaffale_id=1&mensola_id=3
```

Risposta:
```json
{
  "next_position": 15,
  "collocazione": "A.2.15",
  "mensola_level": 2,
  "scaffale_code": "A"
}
```

## Visualizzare Libri per Collocazione

### Lista Libri

```
GET /api/collocazione/libri?scaffale_id=1&mensola_id=3
```

Mostra tutti i libri posizionati nello scaffale/mensola specificato.

### Export CSV

```
GET /admin/collocazione/export?scaffale_id=1&mensola_id=3
```

Esporta un CSV con:
- Collocazione
- Titolo
- Autori
- Editore
- ISBN
- Anno

Il CSV usa il separatore `;` e include BOM UTF-8 per compatibilità Excel.

## Tabelle Database

### scaffali

```sql
CREATE TABLE `scaffali` (
  `id` int NOT NULL AUTO_INCREMENT,
  `codice` varchar(10) NOT NULL,
  `nome` varchar(100) DEFAULT NULL,
  `ordine` int DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codice` (`codice`)
);
```

### mensole

```sql
CREATE TABLE `mensole` (
  `id` int NOT NULL AUTO_INCREMENT,
  `scaffale_id` int NOT NULL,
  `numero_livello` int NOT NULL,
  `ordine` int DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scaffale_livello` (`scaffale_id`, `numero_livello`),
  CONSTRAINT `mensole_scaffale_fk` FOREIGN KEY (`scaffale_id`) REFERENCES `scaffali` (`id`) ON DELETE CASCADE
);
```

### libri (campi collocazione)

```sql
-- Campi nella tabella libri
scaffale_id int DEFAULT NULL,
mensola_id int DEFAULT NULL,
posizione_progressiva int DEFAULT NULL
```

## Sicurezza

### Controllo Accesso

Solo utenti `admin` e `staff` possono gestire la collocazione.

### CSRF

Tutte le operazioni richiedono token CSRF valido.

### Validazione

- Codice scaffale: obbligatorio, convertito in maiuscolo
- Numero livello mensola: deve essere univoco per scaffale
- Posizioni: generate automaticamente

## Risoluzione Problemi

### Impossibile eliminare scaffale

Verifica:
1. Non ci sono mensole nello scaffale
2. Non ci sono libri assegnati allo scaffale

### Codice scaffale duplicato

Il codice deve essere univoco. Scegli un'altra lettera.

### Posizione già occupata

Il sistema calcola automaticamente la prossima posizione libera. Se appare occupata, il libro esistente in quella posizione deve essere spostato.

### Collocazione non visualizzata

Verifica che il libro abbia tutti e tre i campi compilati:
- scaffale_id
- mensola_id
- posizione_progressiva

---

## Domande Frequenti (FAQ)

### 1. È obbligatorio assegnare una collocazione ai libri?

No, la collocazione è opzionale. Tuttavia è utile per:
- Trovare fisicamente i libri
- Organizzare la biblioteca per argomento
- Stampare etichette con posizione
- Esportare inventari per scaffale

---

### 2. Come organizzo gli scaffali in modo logico?

**Approccio consigliato**:
| Scaffale | Contenuto |
|----------|-----------|
| A | Narrativa italiana |
| B | Narrativa straniera |
| C | Saggistica |
| D | Scienze |
| E | Bambini/Ragazzi |

Usa il campo **Nome** per descrivere il contenuto di ogni scaffale.

---

### 3. Posso rinominare uno scaffale esistente?

Sì, puoi modificare:
- **Nome**: liberamente
- **Codice**: solo se non crea duplicati

I libri associati mantengono la collocazione.

---

### 4. Come funziona il suggerimento automatico per genere?

Il sistema può suggerire dove collocare un libro in base al genere:
1. Configura la mappatura genere → scaffale
2. Quando inserisci un libro con genere X
3. Il sistema suggerisce lo scaffale associato

Configurazione in **Impostazioni → Collocazione → Mappatura Generi**.

---

### 5. Posso spostare un libro in un altro scaffale?

Sì:
1. Vai alla scheda del libro
2. Modifica la sezione **Collocazione**
3. Seleziona nuovo Scaffale/Mensola/Posizione
4. Salva

La posizione precedente diventa libera per altri libri.

---

### 6. Come stampo etichette con la collocazione?

1. Vai alla scheda del libro
2. Clicca **Stampa Etichetta**
3. Scegli il formato
4. L'etichetta include la collocazione (es. A.2.15)

Formati etichetta configurabili in **Impostazioni → Etichette**.

---

### 7. Come esporto l'elenco libri per scaffale?

1. Vai in **Admin → Collocazione**
2. Seleziona lo scaffale (opzionalmente mensola)
3. Clicca **Esporta CSV**
4. Il file include: collocazione, titolo, autore, ISBN

---

### 8. Cosa succede se elimino uno scaffale con libri?

**Non puoi**: il sistema blocca l'eliminazione se ci sono libri o mensole associate.

**Procedura corretta**:
1. Sposta tutti i libri su altro scaffale
2. Elimina le mensole vuote
3. Elimina lo scaffale

---

### 9. Come gestisco più sedi/biblioteche?

Usa gli scaffali per rappresentare le sedi:
- Scaffale A = Sede Centro
- Scaffale B = Sede Nord
- Scaffale C = Sede Sud

Oppure usa un prefisso: `A-CENTRO`, `A-NORD`.

---

### 10. Posso riordinare gli scaffali nel menu?

Sì, usando il drag-and-drop:
1. Vai in **Admin → Collocazione**
2. Trascina gli scaffali nell'ordine desiderato
3. L'ordine viene salvato automaticamente

Lo stesso vale per mensole e posizioni.
