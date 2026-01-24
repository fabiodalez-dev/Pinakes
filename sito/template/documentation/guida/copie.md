# Gestione Copie

Ogni libro nel catalogo può avere più copie fisiche. Questa guida spiega come gestirle.

## Panoramica

Il sistema copie permette di:
- Registrare più copie fisiche per ogni libro
- Tracciare lo stato di ogni copia
- Gestire inventario con numeri univoci
- Collegare copie ai prestiti

## Stati della Copia

Le copie possono trovarsi in uno di 8 stati:

| Stato | Descrizione | Prestabile |
|-------|-------------|------------|
| `disponibile` | Copia disponibile per il prestito | Sì |
| `prestato` | Attualmente in prestito | No (automatico) |
| `prenotato` | Riservata per un prestito approvato | No (automatico) |
| `manutenzione` | In manutenzione ordinaria | No |
| `in_restauro` | In restauro/riparazione | No |
| `perso` | Copia smarrita | No |
| `danneggiato` | Copia danneggiata non utilizzabile | No |
| `in_trasferimento` | In trasferimento tra sedi | No |

### Stati Automatici

Due stati sono gestiti automaticamente dal sistema e **non possono essere impostati manualmente**:

- **`prestato`**: Impostato quando la copia viene consegnata in prestito
- **`prenotato`**: Impostato quando un prestito viene approvato

Tentare di impostare manualmente questi stati genera un errore.

### Stati Prestabili

Solo le copie in stato `disponibile` possono essere assegnate a nuovi prestiti.

## Aggiungere una Copia

### Accesso

1. Vai alla scheda del libro
2. Sezione **Copie**
3. Clicca **Aggiungi copia**

### Campi Copia

| Campo | Descrizione | Obbligatorio |
|-------|-------------|--------------|
| **Numero inventario** | Codice identificativo univoco | Sì |
| **Stato** | Stato iniziale della copia | Sì (default: disponibile) |
| **Note** | Note aggiuntive | No |
| **Posizione** | Collocazione fisica (scaffale/mensola) | No |

### Numero Inventario

Il numero inventario deve essere **univoco** in tutto il sistema. Formato consigliato:
- `INV-2024-001`
- `LIB-A-0042`
- Codice a barre

## Modificare una Copia

### Cambio Stato

1. Vai alla scheda del libro
2. Sezione **Copie**
3. Clicca sulla copia da modificare
4. Seleziona nuovo stato
5. Salva

### Stati Modificabili Manualmente

Puoi cambiare lo stato a:
- `disponibile`
- `manutenzione`
- `in_restauro`
- `perso`
- `danneggiato`
- `in_trasferimento`

### Cambio Inventario

Il numero inventario può essere modificato solo se non crea duplicati.

## Eliminare una Copia

### Requisiti

Una copia può essere eliminata **solo** se si trova in uno di questi stati:
- `perso`
- `danneggiato`
- `manutenzione`

### Procedura

1. Vai alla scheda del libro
2. Sezione **Copie**
3. Clicca **Elimina** sulla copia
4. Conferma l'eliminazione

### Copie Non Eliminabili

Se la copia è in stato diverso da quelli elencati, riceverai un errore. Questo previene l'eliminazione accidentale di copie in uso.

## Validazioni

### Controllo Stato

```php
// CopyController - stati validi
$validStates = [
    'disponibile',
    'prestato',
    'prenotato',
    'manutenzione',
    'in_restauro',
    'perso',
    'danneggiato',
    'in_trasferimento'
];

// Stati non impostabili manualmente
$autoStates = ['prestato', 'prenotato'];
```

### Controllo Eliminazione

```php
// Solo questi stati permettono eliminazione
$deletableStates = ['perso', 'danneggiato', 'manutenzione'];
```

## Disponibilità Libro

La disponibilità di un libro dipende dalle sue copie:

```php
// Calcolo copie disponibili
SELECT COUNT(*) FROM copie
WHERE libro_id = ?
  AND stato = 'disponibile'
```

Il campo `copie_disponibili` nella tabella `libri` viene ricalcolato automaticamente quando:
- Si aggiunge una copia
- Si modifica lo stato di una copia
- Si elimina una copia
- Si approva/rifiuta un prestito
- Si restituisce un libro

## Tabella Database

```sql
CREATE TABLE `copie` (
  `id` int NOT NULL AUTO_INCREMENT,
  `libro_id` int NOT NULL,
  `numero_inventario` varchar(100) NOT NULL,
  `stato` enum('disponibile','prestato','prenotato','manutenzione','in_restauro','perso','danneggiato','in_trasferimento') DEFAULT 'disponibile',
  `note` text,
  `posizione_id` int DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero_inventario` (`numero_inventario`),
  KEY `libro_id` (`libro_id`),
  KEY `posizione_id` (`posizione_id`),
  CONSTRAINT `copie_libro_fk` FOREIGN KEY (`libro_id`) REFERENCES `libri` (`id`) ON DELETE CASCADE,
  CONSTRAINT `copie_posizione_fk` FOREIGN KEY (`posizione_id`) REFERENCES `posizioni` (`id`) ON DELETE SET NULL
);
```

## Relazione con Prestiti

### Assegnazione Copia

Quando un prestito viene approvato:
1. Il sistema cerca una copia `disponibile`
2. Imposta la copia a `prenotato`
3. Collega la copia al prestito (`copia_id`)

### Restituzione

Quando un libro viene restituito:
1. La copia torna a stato `disponibile`
2. Il sistema verifica la coda prenotazioni
3. Se c'è una prenotazione, la copia viene riassegnata

## Sicurezza

### Controllo Accesso

Solo utenti con ruolo `admin` o `staff` possono:
- Aggiungere copie
- Modificare stato copie
- Eliminare copie

### CSRF

Tutte le operazioni richiedono token CSRF valido.

### Validazione Input

- Numero inventario: max 100 caratteri, caratteri alfanumerici e trattini
- Stato: deve essere uno degli 8 stati validi
- Note: sanitizzate con `strip_tags()`

## Risoluzione Problemi

### Non riesco a eliminare una copia

Verifica:
1. Lo stato della copia (deve essere `perso`, `danneggiato` o `manutenzione`)
2. Se è `disponibile`, cambia prima lo stato

### Stato "prestato" non selezionabile

Comportamento corretto: lo stato `prestato` viene impostato automaticamente dal sistema prestiti. Non può essere impostato manualmente.

### Numero inventario duplicato

Il numero inventario deve essere univoco. Scegli un altro codice.

### Copia non assegnabile a prestito

Verifica:
1. Lo stato della copia (deve essere `disponibile`)
2. Se è in `manutenzione` o altro stato, cambialo prima

---

## Stampa Etichette

Pinakes genera etichette PDF per il dorso dei libri, complete di codice a barre EAN/ISBN.

### Generare un'Etichetta

1. Vai alla scheda del libro
2. Clicca **Stampa etichetta** (icona codice a barre)
3. Si apre il PDF in una nuova scheda
4. Stampa su carta adesiva

### Contenuto Etichetta

Ogni etichetta include:
- **Nome biblioteca** (dall'impostazione APP_NAME)
- **Titolo** (troncato se necessario)
- **Autore/i**
- **Codice a barre EAN/ISBN** (se disponibile)
- **Numero EAN** in chiaro
- **Classificazione Dewey**
- **Collocazione** (scaffale/mensola)

### Formati Disponibili

Configura il formato in **Impostazioni → Etichette**:

| Formato | Dimensioni | Uso tipico |
|---------|------------|------------|
| **25×38mm** | Verticale | Standard dorso libri (più comune) |
| **50×25mm** | Orizzontale | Dorso libri orizzontale |
| **70×36mm** | Grande | Etichette interne (Herma 4630, Avery 3490) |
| **25×40mm** | Verticale | Standard Tirrenia catalogazione |
| **34×48mm** | Quadrato | Formato Tirrenia quadrato |
| **52×30mm** | Orizzontale | Biblioteche scolastiche (compatibile A4) |

### Layout Automatico

Il sistema adatta automaticamente il layout:
- **Formati verticali** (altezza > larghezza): Layout portrait ottimizzato per dorsi stretti
- **Formati orizzontali**: Layout landscape con più spazio per testo

### Configurazione

1. Vai in **Impostazioni**
2. Sezione **Configurazione Etichette Libri**
3. Seleziona il formato desiderato
4. Clicca **Salva impostazioni etichette**

### Carta Consigliata

Usa carta adesiva specifica per il formato scelto:
- Herma, Avery, Tirrenia producono fogli A4 con etichette prefustellate
- Verifica la compatibilità con la tua stampante (laser/inkjet)

### Endpoint API

Per integrazioni:
```
GET /api/libri/{id}/etichetta-pdf
```

Restituisce il PDF dell'etichetta in formato configurato.

---

## Domande Frequenti (FAQ)

### 1. Quante copie posso aggiungere per ogni libro?

Non c'è un limite tecnico. Puoi aggiungere tutte le copie fisiche che possiedi:
- Ogni copia richiede un **numero inventario univoco**
- Le copie vengono tracciate indipendentemente
- La disponibilità del libro riflette il numero di copie disponibili

---

### 2. Cosa succede se elimino l'ultima copia di un libro?

Il libro resta nel catalogo ma diventa "non disponibile":
- **Disponibilità**: 0 copie
- **Prestiti**: non possibili fino all'aggiunta di nuove copie
- **Prenotazioni**: restano in coda ma non possono essere evase

---

### 3. Posso cambiare il numero inventario di una copia?

Sì, purché il nuovo numero non sia già usato da un'altra copia:
1. Vai alla scheda del libro
2. Modifica la copia
3. Cambia il numero inventario
4. Salva

Il sistema verifica l'unicità e blocca duplicati.

---

### 4. Quando uso lo stato "manutenzione" vs "in_restauro"?

| Stato | Uso tipico | Durata |
|-------|------------|--------|
| `manutenzione` | Controlli periodici, pulizia, inventario | Breve |
| `in_restauro` | Riparazione, rilegatura, restauro conservativo | Lunga |

Entrambi rendono la copia non prestabile.

---

### 5. Come gestisco una copia smarrita?

1. Cambia lo stato a **"perso"**
2. Aggiungi una nota con data smarrimento e circostanze
3. Se la copia riappare, cambia stato a **"disponibile"**
4. Se vuoi rimuoverla definitivamente, usa **Elimina**

---

### 6. Perché non posso impostare manualmente lo stato "prestato"?

Lo stato `prestato` è **automatico**: viene impostato dal sistema quando la copia viene effettivamente consegnata in prestito.

**Questo previene**:
- Incongruenze tra stato copia e prestito
- Copie "prestato" senza un prestito associato
- Errori manuali nell'inventario

---

### 7. Come trovo tutte le copie in manutenzione?

Due metodi:

**Dal catalogo**:
- Filtra per stato copia "manutenzione"

**Da database** (per tecnici):
```sql
SELECT c.*, l.titolo FROM copie c
JOIN libri l ON c.libro_id = l.id
WHERE c.stato = 'manutenzione';
```

---

### 8. Posso associare una posizione fisica diversa a ogni copia?

Sì, ogni copia ha un campo **posizione** indipendente:
- Copia 1: Scaffale A, Mensola 2
- Copia 2: Scaffale B, Mensola 1

Utile per biblioteche con più sedi o sezioni.

---

### 9. Cosa significa "in_trasferimento"?

Lo stato `in_trasferimento` indica che la copia si sta spostando:
- Tra scaffali diversi
- Tra sedi diverse
- Verso/da deposito esterno

La copia non è prestabile finché non torna "disponibile".

---

### 10. Come gestisco copie di libri donati o in prestito da altre biblioteche?

Aggiungi la copia normalmente con un numero inventario che la identifichi:
- Es. `PRESTITO-2024-001` per copie temporanee
- Aggiungi note con origine e data restituzione prevista
- Usa stato "in_trasferimento" quando la restituisci
