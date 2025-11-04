# 📚 Guida Completa: Sistema Prestiti - Tutto Quello che Devi Sapere

## 📖 PARTE 1: GUIDA PER L'UTENTE FINALE

### 🎯 Cosa sono i Prestiti?

I prestiti sono **libri che le persone prendono in prestito** dalla tua biblioteca. Il sistema gestisce **tutte le fasi**:
- Richiesta → Approvazione → Prestito → Restituzione → Fine

---

## 🚀 Come Funziona il Sistema (Semplice!)

### Flusso Completo in 5 Passaggi

| **Fase** | **Cosa Succede** | **Chi fa cosa** | **Tempo** |
|----------|------------------|-----------------|-----------|
| **1. Richiesta** | Utente chiede un libro | Utente → Sistema | 30 secondi |
| **2. Approvazione** | Admin approva | Admin → Email notifica | 1-2 giorni |
| **3. Prestito** | Libro viene dato | Admin → Sistema aggiorna | Istantaneo |
| **4. Utilizzo** | Utente legge il libro | Utente → Libro | 14-30 giorni |
| **5. Restituzione** | Libro torna in biblioteca | Utente → Sistema aggiorna | 1 minuto |

---

## 📱 Come Usare il Sistema (Per Tutti)

### 👤 Per gli Utenti (Chi Prende i Libri)

#### **Richiedere un Prestito**
1. **Trova** il libro nella lista libri
2. **Clicca** sul libro
3. **Clicca** **"Richiedi Prestito"**
4. **Compila**:
   - Nome e cognome
   - Email
   - Data di restituzione preferita
5. **Invia** la richiesta

#### **Ricevere Notifiche**
- **Email**: "Richiesta ricevuta" ✅
- **Email**: "Prestito approvato" ✅
- **Email**: "Manca 3 giorni alla scadenza" ⚠️
- **Email**: "Libro scaduto" 🚨

#### **Restituire il Libro**
1. **Porta** il libro in biblioteca
2. **Dai** il libro all'amministratore
3. **Conferma** la restituzione
4. **Ricevi** email di ringraziamento

### 👨‍💼 Per gli Amministratori (Chi Gestisce)

#### **Vedere le Richieste**
1. **Vai su**: Dashboard → **Prestiti**
2. **Vedrai** una tabella con tutte le richieste
3. **Colori** per capire subito:
   - 🟢 **Nuove richieste** (da approvare)
   - 🟡 **Prestiti in corso** (tutto ok)
   - 🔴 **In ritardo** (da recuperare)

#### **Approvare una Richiesta**
1. **Trova** la richiesta nella lista
2. **Clicca** su **"Approva"** ✅
3. **Sistema**: Manda email automatica all'utente
4. **Aggiorna**: Stato libro diventa "Prestato"

#### **Gestire Restituzioni**
1. **Ricevi** il libro dall'utente
2. **Clicca** su **"Restituito"** nel sistema
3. **Automatico**: Libro torna "Disponibile"
4. **Email**: Ringraziamento all'utente

---

## 📊 Leggere la Tabella Prestiti

### Colonne della Tabella (Semplice!)

| **Colonna** | **Cosa Mostra** | **Esempio** |
|-------------|------------------|-------------|
| 📖 **Libro** | Titolo + autore | "Il Nome della Rosa - Eco" |
| 👤 **Utente** | Nome + email | "Mario Rossi - mario@email.it" |
| 📅 **Date** | Prestito → Scadenza | "15/10 → 29/10" |
| 🚦 **Stato** | Colore + testo | 🟢 "In Corso" |
| ⚙️ **Azioni** | Cosa puoi fare | "Restituito" / "Dettagli" |

### Colori degli Stati
| **Colore** | **Stato** | **Cosa Significa** |
|------------|-----------|-------------------|
| 🟢 Verde | **In Corso** | Tutto ok, può tenere il libro |
| 🟡 Giallo | **In Ritardo** | Scaduto, va recuperato |
| 🔴 Rosso | **Scaduto** | Moltissimo ritardo |
| ⚪ Grigio | **Restituito** | Completato ✅ |

---

## 📧 Email Automatiche (Spiegate Semplice)

### Email all'Utente
1. **"Richiesta ricevuta"** → Abbiamo ricevuto la tua richiesta
2. **"Prestito approvato"** → Puoi venire a prendere il libro
3. **"Manca 3 giorni"** → Ricordati di restituire
4. **"Scaduto"** → Restituisci subito
5. **"Grazie"** → Grazie per la restituzione!

### Email all'Amministratore
1. **"Nuova richiesta"** → Qualcuno ha chiesto un libro
2. **"Prestito scaduto"** → Ricordati di recuperare il libro

---

## 📅 Scadenze e Tempi

### Standard della Biblioteca
| **Tipo Biblioteca** | **Durata Prestito** | **Rinnovi Possibili** |
|---------------------|---------------------|----------------------|
| **Scuola** | 14 giorni | 2 rinnovi |
| **Comunale** | 30 giorni | 1 rinnovo |
| **Privata** | 7 giorni | Nessun rinnovo |

### Come Cambiare le Scadenze
1. **Vai su**: Dashboard → **Impostazioni**
2. **Clicca**: **"Prestiti"**
3. **Modifica**: Giorni di prestito e numero rinnovi
4. **Salva**: Le modifiche valgono per tutti i futuri prestiti

---

## 🔄 Gestire i Prestiti Scaduti

### Cosa Fare Quando è Scaduto

#### **Per l'Amministratore**
1. **Controlla**: Lista "In Ritardo"
2. **Chiama** l'utente (se necessario)
3. **Invia** email di sollecito
4. **Segna** come recuperato quando arriva

#### **Per l'Utente**
1. **Ricevi** email di avviso
2. **Porta** il libro in biblioteca
3. **Conferma** la restituzione
4. **Nessuna multa** se restituisci (configurabile)

---

## 📈 Statistiche e Report

### Cosa Puoi Vedere
- **Libri più popolari**: Quali vengono presi di più
- **Utenti più attivi**: Chi prende più libri
- **Prestiti attivi**: Quanti libri sono fuori
- **Ritardi**: Quanti libri sono scaduti

### Come Vedere le Statistiche
1. **Vai su**: Dashboard → **Statistiche**
2. **Scegli**: Periodo di tempo
3. **Vedrai**: Grafici e numeri semplici

---

## 📋 Esempi Pratici di Utilizzo

### Esempio 1: Utente Standard
```
Utente: Maria Rossi
Libro: "Orgoglio e Pregiudizio"
Processo:
1. Maria chiede il libro online
2. Admin approva in 1 giorno
3. Maria prende il libro
4. Legge per 14 giorni
5. Restituisce il libro
6. Riceve email di ringraziamento
```

### Esempio 2: Admin Gestisce
```
Admin: Paolo
Giornata tipo:
- 3 nuove richieste da approvare
- 5 prestiti in corso da monitorare
- 1 libro restituito da registrare
- 0 libri in ritardo da recuperare
```

---

## 🎯 Consigli Pratici

### Per gli Utenti
- **Leggi** le email di avviso
- **Porta** il libro qualche giorno prima della scadenza
- **Contatta** se hai problemi
- **Rinnova** solo se veramente necessario

### Per gli Admin
- **Controlla** ogni mattina i prestiti scaduti
- **Approva** le richieste entro 24 ore
- **Archivia** i prestiti completati
- **Comunica** chiaramente le regole

---

## ❓ Domande Frequenti

### "Quanto costa prendere un libro?"
- **Standard**: Gratuito in biblioteche pubbliche
- **Configurabile**: Può avere costi in biblioteche private

### "Posso rinnovare il prestito?"
- **Sì**: Se non ci sono altre richieste
- **Come**: Contatta l'amministratore
- **Quante volte**: Dipende dalle regole della biblioteca

### "Cosa succede se non restituisco?"
- **Email**: Riceverai solleciti automatici
- **Telefono**: Possibile chiamata dell'amministratore
- **Nessuna multa**: Nel sistema base (configurabile)

### "Posso vedere i miei prestiti?"
- **Sì**: Login → Profilo → I miei prestiti
- **Storico**: Tutti i prestiti passati
- **Attivi**: Solo quelli correnti

---

## 📞 Supporto e Aiuto

### Se hai problemi
1. **Controlla** le email di notifica
2. **Leggi** questa guida alla sezione problemi
3. **Contatta** l'amministratore della biblioteca
4. **Verifica** la data di restituzione nel sistema

---

## 🎯 Checklist per Iniziare

### Nuova Biblioteca
- [ ] Impostato durata prestito (14/30 giorni)
- [ ] Configurato email notifiche
- [ ] Testato un prestito fittizio
- [ ] Spiegato le regole agli utenti

### Utente Nuovo
- [ ] Registrato nel sistema
- [ ] Capito come richiedere libri
- [ ] Salvato email biblioteca
- [ ] Capito le regole di restituzione

---

## 🎓 Glossario Semplice

| **Termine** | **Significa** | **Esempio** |
|-------------|---------------|-------------|
| **Prestito** | Dare un libro in uso | "Il libro è in prestito a Mario" |
| **Scadenza** | Data di restituzione | "Restituzione entro 30/10" |
| **Rinnovo** | Estendere il prestito | "Posso tenerlo altri 7 giorni?" |
| **Ritardo** | Oltre la data di scadenza | "Il libro è in ritardo di 3 giorni" |

---

## 📊 Monitoraggio Semplice

### Indicatori Chiave
- **🟢 Tutto ok**: 0 libri in ritardo
- 🟡 **Attenzione**: 1-3 libri scaduti
- 🔴 **Azione**: Più di 3 libri scaduti

### Come Controllare
1. **Guarda** la tabella "Prestiti"
2. **Controlla** i colori degli stati
3. **Leggi** le email di notifica
4. **Agisci** se necessario

---

*Ultimo aggiornamento: 19 Ottobre 2025*  
*Versione guida: 3.0.0 - Semplificata per utenti finali*

---

## 🔧 PARTE 2: DOCUMENTAZIONE TECNICA PER SVILUPPATORI

### Architettura del Sistema Prestiti

#### Database Schema

```sql
CREATE TABLE prestiti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    libro_id INT NOT NULL,
    utente_id INT NOT NULL,
    data_prestito DATE NOT NULL,
    data_scadenza DATE NOT NULL,
    data_restituzione DATE NULL,
    stato ENUM('richiesto','approvato','in_corso','in_ritardo','restituito','annullato') DEFAULT 'richiesto',
    sanzione DECIMAL(10,2) DEFAULT 0.00,
    renewals INT DEFAULT 0,
    processed_by INT NULL,
    note TEXT,
    attivo TINYINT(1) DEFAULT 1,
    active_libro_id INT GENERATED ALWAYS AS (CASE WHEN attivo = 1 THEN libro_id ELSE NULL END) STORED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (libro_id) REFERENCES libri(id) ON DELETE CASCADE,
    FOREIGN KEY (utente_id) REFERENCES utenti(id) ON DELETE CASCADE,
    FOREIGN KEY (processed_by) REFERENCES utenti(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_prestiti_active_libro (active_libro_id),
    INDEX idx_prestiti_libro_stato (libro_id, stato),
    INDEX idx_prestiti_utente_data (utente_id, data_prestito),
    INDEX idx_prestiti_attivo_stato (attivo, stato)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### Business Logic

```php
// PrestitiController.php - Metodi Principali
class PrestitiController
{
    public function index(Request $request, Response $response, mysqli $db): Response
    public function createPrestito(Request $request, Response $response, mysqli $db): Response
    public function approvePrestito(Request $request, Response $response, mysqli $db, int $id): Response
    public function returnPrestito(Request $request, Response $response, mysqli $db, int $id): Response
    public function renewPrestito(Request $request, Response $response, mysqli $db, int $id): Response
}
```

#### API Endpoints

**GET /api/prestiti**
```javascript
// Response format
{
  "draw": 1,
  "recordsTotal": 100,
  "recordsFiltered": 25,
  "data": [
    {
      "id": 123,
      "libro_titolo": "Il Nome della Rosa",
      "utente_nome": "Mario Rossi",
      "utente_email": "mario@example.com",
      "data_prestito": "2024-10-15",
      "data_scadenza": "2024-10-29",
      "stato": "in_corso",
      "giorni_rimanenti": 5,
      "attivo": 1
    }
  ]
}
```

#### Cron Jobs per Automazione

```bash
# /etc/cron.d/biblioteca
# Controllo scadenze ogni giorno alle 8:00
0 8 * * * php /var/www/html/biblioteca/scripts/maintenance.php

# Email notifiche ogni giorno alle 9:00
0 9 * * * php /var/www/html/biblioteca/scripts/email-notifications.php

# Backup settimanale
0 2 * * 0 php /var/www/html/biblioteca/scripts/backup.php
```

#### Query Ottimizzate

```sql
-- Prestiti attivi per utente
SELECT p.*, l.titolo, u.nome 
FROM prestiti p 
JOIN libri l ON p.libro_id = l.id 
JOIN utenti u ON p.utente_id = u.id 
WHERE p.stato IN ('in_corso', 'in_ritardo') 
AND p.attivo = 1;

-- Prestiti in ritardo con dettagli
SELECT p.*, l.titolo, u.nome, u.email,
       DATEDIFF(CURDATE(), p.data_scadenza) as giorni_ritardo
FROM prestiti p
JOIN libri l ON p.libro_id = l.id
JOIN utenti u ON p.utente_id = u.id
WHERE p.stato = 'in_ritardo'
AND p.attivo = 1;
```

#### Sicurezza e Validazione

```php
// Validazione date prestito
$loanDays = (int)$settings->get('library', 'loan_days', 14);
$maxRenewals = (int)$settings->get('library', 'max_renewals', 2);

// Controllo disponibilità libro
if (!$bookRepo->isAvailableForLoan($bookId)) {
    throw new Exception('Libro non disponibile per prestito');
}

// Prevenzione prestiti multipli
if ($loanRepo->hasActiveLoan($userId, $bookId)) {
    throw new Exception('Hai già un prestito attivo per questo libro');
}
```

#### Monitoring e Alert

```php
// Dashboard stats
$stats = [
    'prestiti_attivi' => $loanRepo->countActiveLoans(),
    'prestiti_scaduti' => $loanRepo->countOverdueLoans(),
    'prestiti_oggi' => $loanRepo->countLoansDueToday(),
    'tasso_restituzione' => $loanRepo->getReturnRate()
];
```

---

*Ultimo aggiornamento: 19 Ottobre 2025*  
*Versione documentazione: 3.0.0*