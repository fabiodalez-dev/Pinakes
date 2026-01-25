# Pinakes - Guida Completa

> **Benvenuto!** Questo è il centro documentale di **Pinakes**, la soluzione completa e user-friendly per gestire la tua biblioteca scolastica, comunale o privata.

---

## Cos'è Pinakes?

**Pinakes** è un'applicazione web moderna progettata per **semplificare completamente** la gestione di una biblioteca:

-  **Nessuna conoscenza tecnica richiesta** - Interfaccia intuitiva come Facebook
-  **Completamente gratuito e open-source**
-  **Funziona in cloud** - Accedi da qualunque dispositivo
-  **Automatizzato al 100%** - Email, scadenze, notifiche
-  **Professionale** - Usato da scuole e comuni italiani

---

## Le Funzionalità Più Belle 

### Inserimento Libri Fulminei (2 minuti!)
```
 Scansiona il codice ISBN/EAN del libro
 Il sistema importa AUTOMATICAMENTE:
    Titolo completo
    Autore/i esatti
    Copertina
    Descrizione
    Data pubblicazione
    Prezzo

 Tu non fai NULLA! Tutto in automatico dai database online.
```
**Tempo risparmiato**: 10 minuti di inserimento manuale → **2 minuti automatico**

### Sistema Email Intelligente
```
Il sistema invia AUTOMATICAMENTE email quando:
 Un utente si registra ("Grazie, in attesa di approvazione")
 L'account viene approvato ("Accettato! Ecco il tuo codice")
 Un prestito sta per scadere ("3 giorni! Non dimenticarti!")
 Un libro è in ritardo ("Restituisci al più presto!")
 Un libro desiderato torna disponibile ("È arrivato!")

 Template personalizzabili con editor come Word
 Link dinamici che aggiungono automaticamente i dati
```

### Sistema di Collocazione Intelligente
```
Organizzi gli scaffali UNA VOLTA SOLA:
- Scaffale A, B, C... (narrativa, saggistica, ecc.)
- Mensole 1, 2, 3... (piani di ogni scaffale)

Poi il sistema assegna AUTOMATICAMENTE:
  A.1.1 → Primo libro sullo scaffale A, mensola 1
  A.1.2 → Secondo libro sullo scaffale A, mensola 1
  A.2.1 → Primo libro sullo scaffale A, mensola 2
  ...

 Le posizioni si generano da sole quando aggiungi libri!
```

### Classificazione Dewey Automatica
```
Il sistema suggerisce AUTOMATICAMENTE la classificazione Dewey:
 000-099: Informatica
 100-199: Filosofia
 200-299: Religione
 300-399: Scienze sociali
... e molte altre!

Tu scegli da una lista, non devi ricordarti i numeri.
```

### Stampa Etichette Professionali
```
In UN CLICK generi PDF perfetti da stampare:
 Con codice a barre scannerizzabile
 Con posizione (A.2.15)
 Con titolo e autore
 In 3 formati diversi (25×38mm, 50×25mm, 70×36mm)

Pronto da attaccare sui libri!
```

### Dashboard Statistiche Completa
```
Visualizza in tempo reale:
 Libri totali e disponibili
 Prestiti attivi
⏰ Scadenze imminenti
 Ritardi
 Utenti registrati
 Storico completo
```

### Prestiti Gestiti Automaticamente
```
Flusso completamente gestito dal sistema:
1⃣ Utente richiede un libro (online)
2⃣ Admin approva con 1 click
3⃣ Email automatica di conferma
4⃣ Scadenza tracciata automaticamente
5⃣ Promemoria 3 giorni prima
6⃣ Avvertimento se in ritardo
7⃣ Restituzione confermata

 Supporta sanzioni al giorno (configurabili)
 Supporta rinnovi prestiti
```

### Gestione Utenti Professionale
```
Admin approva gli utenti:
 Assegnazione automatica codice tessera
 Email di benvenuto personalizzata
 Ruoli (utente, admin, bibliotecario)
 Storico completo prestiti per utente
 Blocco utenti con troppi ritardi
```

### Ricerca Potentissima
```
Cerca per:
 Titolo (anche parziale)
 Autore
 ISBN/EAN
 Editore
 Anno pubblicazione
 Genere
⭐ Disponibilità (libero/prestato)

RISULTATI ISTANTANEI mentre digiti!
```

### Export e Backup
```
Esporta in:
 Excel/CSV
 PDF per stampare
 Backup automatico del database
```

### Interfaccia Moderna e Responsive
```
 Funziona perfettamente su:
   Mobile (smartphone, tablet)
   Computer (Windows, Mac, Linux)
   Qualunque browser moderno

 Design intuitivo "as is"
 Logo personalizzabile
 Colori configurabili
```

---

## Guida Completa alle Funzionalità

### Per Utenti Finali (Non-Tecnici)

| **Argomento** | **Descrizione** | **Tempo lettura** |
|---------------|-----------------|------------------|
|  [**Guida Libri**](./libri.MD) | Come gestire i libri (aggiungere, cercare, modificare) | 8 min |
|  [**Inserimento Libri**](./inserimento_libri.MD) | Guida completa all'inserimento con scraping automatico | 12 min |
|  [**Modifica Libri**](./modifica_libro.MD) | Come cambiare i dati dei libri | 6 min |
|  [**Scheda Libro**](./scheda_libro.MD) | La pagina di dettaglio di ogni libro | 5 min |
|  [**Stampa Etichette**](./stampa_etichette.MD) | Come stampare le etichette adesive | 7 min |
|  [**Sistema Collocazione**](./collocazione.MD) | Come organizzare gli scaffali | 6 min |
|  [**Sistema Prestiti**](./prestiti.MD) | Come funzionano i prestiti | 10 min |
|  [**Email e Notifiche**](./email.MD) | Come configurare il sistema email | 10 min |
|  [**Impostazioni**](./settings.MD) | Configurazione completa del sistema | 12 min |

**Tempo totale per leggere tutto**: ~1 ora (inclusi gli approfondimenti)

### Per Amministratori di Sistema (Tecnici)

| **Argomento** | **Descrizione** | **Per chi** |
|---------------|-----------------|-----------|
|  [**Installazione**](./installation.MD) | Come installare il sistema su un server | IT Admin / DevOps |

---

## Quick Start (Inizia in 5 minuti!)

### Se Sei un Utente Finale Nuovo

1. **Leggi**: [Guida Libri](./libri.MD) (2 min)
2. **Accedi**: Vai su `http://tuabiblioteca.it/admin`
3. **Prova**: Aggiungi 1 libro seguendo [Inserimento Libri](./inserimento_libri.MD)
4. **Stampa**: Una etichetta seguendo [Stampa Etichette](./stampa_etichette.MD)
5. ** Fatto!** Sei un esperto!

### Se Sei un Admin che Configura il Sistema

1. **Leggi**: [Impostazioni](./settings.MD) (5 min)
2. **Configura**: Nome biblioteca, logo, email
3. **Leggi**: [Email e Notifiche](./email.MD) per setup SMTP
4. **Prova**: Invia un'email di test
5. ** Fatto!** Il sistema è pronto per gli utenti!

### Se Sei un IT che Installa il Sistema

1. **Leggi**: [Installazione](./installation.MD) (segui passo-passo)
2. **Configura**: Database, PHP, web server
3. **Esegui**: Installer web guidato
4. ** Fatto!** Sistema online!

---

## Domande Frequenti

### "Quanto costa?"
**GRATIS!** È open-source, no license fees.

### "Devo installare software sul mio computer?"
**No!** Funziona da browser. Accedi da qualunque dispositivo.

### "Come faccio se mi serve aiuto?"
 Leggi la guida correlata. Se ancora non è chiaro, chiedi all'amministratore del sistema.

### "Posso personalizzare i template email?"
**Sì!** [Vai alla sezione Email](./email.MD#personalizzare-le-email) e leggi come fare.

### "Il sistema è sicuro?"
**Sì!** Ha protezione CSRF, prepared statements, validazione input, HTTPS ready.

### "Quanti libri posso aggiungere?"
**Illimitati!** Il database si adatta automaticamente. (Limite tecnico: DB dipende da hosting)

### "Posso esportare i miei dati?"
**Sì!** In Excel, CSV, PDF. Sono tuoi dati, puoi esportarli quando vuoi.

---

## In Sintesi - Cosa Rende Speciale Pinakes?

### Prima (Con metodi tradizionali)
```
 15-20 minuti per aggiungere 1 libro (manuale)
 Excel pieno di dati caotici
 Nessuna comunicazione automatica
 Scarti carta ad ogni prestito
 Errori manuali
 Difficile trovare un libro
```

### Dopo (Con Pinakes)
```
 2 minuti per aggiungere un libro (automatico)
 Dati organizzati e sempre corretti
 Email automatiche per ogni evento
 Etichette stampate professionalmente
 Zero errori - Sistema affidabile
 Ricerca istantanea su 1000+ libri
 Dashboard con tutte le statistiche
 Accesso da qualunque dispositivo
```

---

## Inizia Subito!

Scegli la tua guida in base a quello che devi fare:

| **Voglio...** | **Leggi** | **Tempo** |
|---|---|---|
| Aggiungere libri | [Inserimento Libri](./inserimento_libri.MD) | 12 min |
| Cercare un libro | [Guida Libri](./libri.MD) | 8 min |
| Gestire i prestiti | [Sistema Prestiti](./prestiti.MD) | 10 min |
| Stampare etichette | [Stampa Etichette](./stampa_etichette.MD) | 7 min |
| Configurare il sistema | [Impostazioni](./settings.MD) | 12 min |
| Mandare email | [Email e Notifiche](./email.MD) | 10 min |
| Installare il sistema | [Installazione](./installation.MD) | 30 min |

---

## Supporto

- **Domande su una funzione?** → Cerca la guida correlata qui
- **Non hai trovato la risposta?** → Contatta l'amministratore di Pinakes
- **Problema tecnico?** → Consulta la sezione "Risoluzione Problemi" nella guida correlata

---

## Struttura della Documentazione

```
 Pinakes - Guida Completa (Tu sei qui!)
│
├─  LIBRI (Gestione Catalogo)
│  ├─ Guida Libri (indice principale)
│  ├─ Inserimento Libri (con scraping ISBN/EAN)
│  ├─ Modifica Libri (aggiornamento dati)
│  ├─ Scheda Libro (visualizzazione dettagli)
│  └─ Stampa Etichette (etichette adesive)
│
├─  ORGANIZZAZIONE
│  └─ Sistema Collocazione (scaffali e mensole)
│
├─  PRESTITI
│  └─ Sistema Prestiti (richieste, approvazioni, resi)
│
├─  COMUNICAZIONE
│  └─ Email e Notifiche (automatizzazione messaggi)
│
├─  AMMINISTRAZIONE
│  ├─ Impostazioni (configurazione completa)
│  └─ Installazione (setup iniziale per IT)
```

---

## Versione e Changelog

**Versione Documentazione**: 1.0.0
**Data Aggiornamento**: 19 Ottobre 2025
**Stato**:  Completo e Aggiornato

### Cosa Contiene questa Versione
-  9 guide complete user-friendly
-  Sezione "Funzionalità più belle" con examples
-  Quick start in 5 minuti
-  FAQ comuni
-  Supporto multi-livello (utente, admin, IT)

---

## Benvenuto in Pinakes!

Siamo felici di averti con noi. Che tu sia un bibliotecario, un admin scolastico, o un IT, **questa documentazione ti guiderà passo-passo** per usare il sistema al meglio.

**Domanda per iniziare**: Cosa vuoi fare prima?
-  [Aggiungere libri?](./inserimento_libri.MD)
-  [Gestire prestiti?](./prestiti.MD)
-  [Configurare il sistema?](./settings.MD)

**Buon lavoro!** 

---

*Documento creato il 19 Ottobre 2025*
*Pinakes - Documentazione Ufficiale v1.0.0*
*Lingua: Italiano | User-friendly | Per tutti i livelli*
