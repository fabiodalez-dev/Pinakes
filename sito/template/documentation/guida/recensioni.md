# Sistema Recensioni

Gli utenti possono lasciare recensioni e valutazioni sui libri che hanno preso in prestito.

## Panoramica

Il sistema recensioni permette di:
- Valutare libri con stelle (1-5)
- Scrivere recensioni testuali con titolo
- Moderazione admin/staff prima della pubblicazione

## Requisiti per Recensire

Un utente può lasciare una recensione solo se:

1. **Ha effettuato un prestito** del libro con stato `restituito` o `in_corso`
2. **Non ha già recensito** quel libro

Il sistema verifica automaticamente questi requisiti prima di mostrare il form di recensione.

### Verifica Eligibilità

```sql
SELECT COUNT(*) FROM prestiti
WHERE utente_id = ?
  AND libro_id = ?
  AND stato IN ('restituito', 'in_corso')
```

Se l'utente non ha mai preso in prestito il libro, non può recensirlo.

## Lasciare una Recensione

### Per gli Utenti

1. Vai alla scheda del libro
2. Sezione **Recensioni** (se hai i requisiti)
3. Compila il form:
   - **Stelle**: valutazione 1-5 (obbligatorio)
   - **Titolo**: max 255 caratteri (opzionale)
   - **Descrizione**: max 2000 caratteri (opzionale)
4. Clicca **Pubblica**

### Validazioni

| Campo | Regola |
|-------|--------|
| `stelle` | Obbligatorio, intero 1-5 |
| `titolo` | Opzionale, max 255 caratteri |
| `descrizione` | Opzionale, max 2000 caratteri |

### Stato Iniziale

Tutte le recensioni vengono create con stato `pendente` e richiedono approvazione da parte di admin o staff.

## Stati della Recensione

| Stato | Descrizione | Visibile |
|-------|-------------|----------|
| `pendente` | In attesa di moderazione | No |
| `approvata` | Approvata e pubblicata | Sì |
| `rifiutata` | Rifiutata dal moderatore | No |

## Modifica e Eliminazione

### Modifica

L'utente può modificare la propria recensione:
1. Trova la tua recensione nella scheda libro
2. Clicca **Modifica**
3. Aggiorna stelle/titolo/descrizione
4. Salva

> **Nota**: La recensione modificata mantiene lo stato attuale.

### Eliminazione

L'utente può eliminare la propria recensione:
1. Clicca **Elimina** sulla recensione
2. Conferma l'eliminazione

## Moderazione Admin

### Accesso

Solo utenti con ruolo `admin` o `staff` possono accedere alla moderazione:

- **Admin → Recensioni**

### Lista Recensioni

La lista mostra:
- Libro (titolo)
- Utente (nome)
- Stelle
- Titolo recensione
- Data creazione
- Stato

### Azioni Disponibili

| Azione | Effetto |
|--------|---------|
| **Approva** | Stato → `approvata`, visibile pubblicamente |
| **Rifiuta** | Stato → `rifiutata`, non visibile |
| **Elimina** | Cancella definitivamente |

### Approvazione

Quando una recensione viene approvata:
- `stato` → `approvata`
- `approved_by` → ID dell'admin/staff
- `approved_at` → timestamp approvazione

### Rifiuto

Quando una recensione viene rifiutata:
- `stato` → `rifiutata`
- La recensione non è visibile ma resta nel database

## Valutazioni

### Sistema a Stelle

| Stelle | Valore |
|--------|--------|
| ⭐ | 1 |
| ⭐⭐ | 2 |
| ⭐⭐⭐ | 3 |
| ⭐⭐⭐⭐ | 4 |
| ⭐⭐⭐⭐⭐ | 5 |

### Media Valutazioni

La scheda libro mostra:
- Valutazione media (es. 4.2/5)
- Numero totale recensioni approvate
- Distribuzione stelle

### Calcolo Statistiche

```php
// RecensioniRepository::getReviewStats()
SELECT
    COUNT(*) as total,
    AVG(stelle) as average,
    SUM(CASE WHEN stelle = 1 THEN 1 ELSE 0 END) as stars_1,
    SUM(CASE WHEN stelle = 2 THEN 1 ELSE 0 END) as stars_2,
    ...
FROM recensioni
WHERE libro_id = ? AND stato = 'approvata'
```

## Tabella Database

```sql
CREATE TABLE `recensioni` (
  `id` int NOT NULL AUTO_INCREMENT,
  `libro_id` int NOT NULL,
  `utente_id` int NOT NULL,
  `stelle` tinyint NOT NULL,
  `titolo` varchar(255) DEFAULT NULL,
  `descrizione` text,
  `stato` enum('pendente','approvata','rifiutata') DEFAULT 'pendente',
  `approved_by` int DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `libro_id` (`libro_id`),
  KEY `utente_id` (`utente_id`),
  CONSTRAINT `recensioni_libro_fk` FOREIGN KEY (`libro_id`) REFERENCES `libri` (`id`) ON DELETE CASCADE,
  CONSTRAINT `recensioni_utente_fk` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`) ON DELETE CASCADE
);
```

## Visualizzazione Frontend

### Scheda Libro

Le recensioni approvate mostrano:
- Nome utente
- Data recensione
- Valutazione stelle
- Titolo (se presente)
- Descrizione (se presente)

### Ordinamento

Le recensioni sono ordinate per data di creazione (più recenti prima).

## Sicurezza

### Protezioni

- **Autenticazione**: solo utenti loggati possono recensire
- **Autorizzazione**: solo l'autore può modificare/eliminare la propria recensione
- **CSRF**: token richiesto per tutte le operazioni
- **Sanitizzazione**: `strip_tags()` su titolo e descrizione
- **Moderazione**: tutte le recensioni passano per approvazione

### Controllo Accesso Admin

```php
// RecensioniAdminController - solo admin/staff
if (!in_array($user['ruolo'], ['admin', 'staff'])) {
    return redirect with 403;
}
```

## Risoluzione Problemi

### Non riesco a recensire

Verifica:
1. Sei loggato
2. Hai preso in prestito il libro (stato `restituito` o `in_corso`)
3. Non hai già recensito questo libro

### Recensione non visibile

La recensione potrebbe essere:
- In stato `pendente` (attesa moderazione)
- In stato `rifiutata`

Contatta la biblioteca per informazioni.

### Errore durante l'invio

Controlla:
1. Valutazione stelle selezionata (1-5)
2. Titolo non supera 255 caratteri
3. Descrizione non supera 2000 caratteri

---

## Domande Frequenti (FAQ)

### 1. Devo aver letto il libro per recensirlo?

Non necessariamente "letto", ma devi aver **effettuato un prestito**:
- Prestito restituito: puoi recensire
- Prestito in corso: puoi recensire
- Mai preso in prestito: non puoi recensire

Questo garantisce che le recensioni siano di utenti reali.

---

### 2. Perché la mia recensione non appare?

Le recensioni passano per **moderazione**:
- Stato `pendente`: in attesa di approvazione
- Stato `rifiutata`: non pubblicabile

Contatta la biblioteca per informazioni sullo stato.

---

### 3. Posso modificare una recensione già approvata?

Sì:
1. Vai alla scheda del libro
2. Trova la tua recensione
3. Clicca **Modifica**
4. Aggiorna il contenuto
5. Salva

La recensione modificata mantiene lo stato "approvata" (non richiede nuova approvazione).

---

### 4. Un utente può lasciare più recensioni per lo stesso libro?

No, ogni utente può lasciare **una sola recensione per libro**. Se vuoi aggiornarla, usa la funzione Modifica.

---

### 5. Come funziona la media delle stelle?

La media viene calcolata solo sulle **recensioni approvate**:
- Recensioni pendenti: non contano
- Recensioni rifiutate: non contano

Formula: somma stelle / numero recensioni approvate.

---

### 6. Chi può approvare o rifiutare recensioni?

Solo utenti con ruolo **admin** o **staff**:
1. Vai in **Admin → Recensioni**
2. Vedi le recensioni pendenti
3. Clicca **Approva** o **Rifiuta**

---

### 7. Le recensioni rifiutate vengono cancellate?

No, restano nel database ma non sono visibili:
- L'utente non le vede pubblicate
- L'admin può recuperarle se necessario
- Per eliminarle definitivamente, usa **Elimina**

---

### 8. Posso disabilitare completamente le recensioni?

Non esiste un toggle globale, ma puoi:
- Non approvare mai le recensioni (restano pendenti)
- Rimuovere il form recensione modificando il template (richiede PHP)

---

### 9. Le recensioni influenzano la ricerca?

No, la ricerca si basa su titolo, autore, ISBN, descrizione. Le recensioni:
- Non sono indicizzate nella ricerca
- Non influenzano l'ordinamento default
- Sono visibili solo nella scheda libro

---

### 10. Come gestisco recensioni offensive o spam?

1. Vai in **Admin → Recensioni**
2. Trova la recensione problematica
3. Clicca **Rifiuta** (mantiene in database) o **Elimina** (rimuove)

Per recidivi, considera di bloccare l'utente.
