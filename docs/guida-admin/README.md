# 🛠️ Guida Amministratore - Pannello di Controllo

Benvenuto nella guida completa per amministratori di Pinakes. Qui troverai tutte le informazioni per gestire la tua biblioteca dal pannello admin.

## Panoramica

Il pannello amministratore di Pinakes offre controllo completo su tutti gli aspetti della biblioteca: libri, utenti, prestiti, impostazioni, plugin e molto altro.

## 📖 Guide Disponibili

### [→ Dashboard Admin](./dashboard.md)
Panoramica del pannello di controllo admin.

**Cosa imparerai:**
- Layout e navigazione del pannello admin
- Statistiche principali (libri, prestiti, utenti)
- Widget rapidi e azioni frequenti
- Notifiche sistema
- Aggiornamenti disponibili

### [→ Gestione Libri](./libri.md)
Operazioni admin sui libri.

**Cosa imparerai:**
- Tabella libri con filtri avanzati
- Ricerca rapida
- Operazioni batch (export, eliminazione)
- Ordinamento colonne
- Modifica rapida

### [→ Gestione Autori ed Editori](./autori-editori.md)
Come gestire autori ed editori.

**Cosa imparerai:**
- Aggiungere autori con biografia
- Unire autori duplicati
- Gestire editori
- Vedere libri per autore/editore

### [→ Gestione Utenti](./utenti.md)
Amministrazione utenti della biblioteca.

**Cosa imparerai:**
- Creare utenti manualmente
- Approvare registrazioni
- Ruoli e permessi (admin, librarian, user)
- Bloccare/sbloccare utenti
- Vedere storico prestiti utente
- Export dati utenti

### [→ Gestione Prestiti](./prestiti-admin.md)
Pannello admin prestiti.

**Cosa imparerai:**
- Vista tabellare tutti i prestiti
- Filtri per stato (in corso, scaduti, restituiti)
- Approvare richieste pendenti
- Confermare ritiro
- Segnare come restituito
- Gestire ritardi e solleciti
- Statistiche prestiti

### [→ Impostazioni](./impostazioni.md)
Configurazione completa del sistema.

**Cosa imparerai:**
- Identità biblioteca (nome, logo)
- Email SMTP
- Prestiti (durata, rinnovi, sanzioni)
- CMS (homepage, pagine custom)
- Privacy e cookie
- Template email
- Etichette
- Modalità Catalogo

### [→ Sistema di Aggiornamento](./aggiornamenti.md)
Come aggiornare Pinakes automaticamente.

**Cosa imparerai:**
- Verificare aggiornamenti disponibili
- Installare aggiornamenti automaticamente
- Backup automatico pre-aggiornamento
- Rollback in caso di errore
- Log aggiornamenti

### [→ Manutenzione](./manutenzione.md)
Strumenti di manutenzione del sistema.

**Cosa imparerai:**
- Controllo integrità database
- Ottimizzazione database
- Pulizia cache
- Verifica permessi file
- Log di sistema
- Report errori

### [→ Notifiche](./notifiche.md)
Sistema di notifiche admin.

**Cosa imparerai:**
- Tipi di notifiche
- Gestire notifiche
- Badge contatore
- Archivio notifiche

## 🎯 Quick Start per Nuovi Admin

**Prima configurazione (10 minuti):**

1. **Dashboard → Impostazioni → Identità**
   - Nome biblioteca
   - Carica logo

2. **Impostazioni → Email**
   - Configura SMTP (Gmail o altro)
   - Testa connessione

3. **Collocazione**
   - Crea scaffali (A, B, C...)
   - Aggiungi mensole

4. **Libri → + Nuovo Libro**
   - Inserisci i primi libri con ISBN

5. **Utenti → + Nuovo Utente**
   - Crea account di prova

✅ Biblioteca pronta all'uso!

## 💡 Concetti Chiave Admin

### Ruoli Utente

| Ruolo | Permessi | Quando Usarlo |
|-------|----------|---------------|
| **Admin** | Tutti i permessi | Direttore biblioteca |
| **Librarian** | Gestione libri, prestiti, utenti | Bibliotecari |
| **Assistant** | Solo lettura + stampa etichette | Assistenti |
| **User** | Solo catalogo pubblico + prestiti propri | Lettori |

### Sidebar Admin

La sidebar contiene:
- 📊 Dashboard
- 📚 Libri (aggiungi, modifica, tabella)
- 👥 Autori
- 🏢 Editori
- 📕 Prestiti
- 📦 Prenotazioni
- 👤 Utenti
- 📍 Collocazione
- 🔌 Plugin
- ⚙️ Impostazioni
- 🛠️ Manutenzione
- 🔔 Notifiche

### DataTables

Le tabelle admin usano DataTables per:
- ✅ Ricerca istantanea
- ✅ Ordinamento colonne
- ✅ Paginazione
- ✅ Export CSV/PDF
- ✅ Filtri avanzati

## 📊 Statistiche Dashboard

Nel dashboard vedi:
- **Libri totali**: Numero libri catalogati
- **Disponibili**: Libri prestabili ora
- **In prestito**: Libri attualmente fuori
- **In ritardo**: Prestiti scaduti
- **Prenotazioni attive**: Utenti in coda
- **Utenti registrati**: Totale utenti
- **Nuovi utenti (30gg)**: Registrazioni recenti

### Grafici

- 📈 Prestiti ultimi 30 giorni
- 📊 Libri per categoria
- 👥 Utenti attivi vs inattivi
- 📕 Top 10 libri più prestati

## 🔔 Sistema Notifiche

Ricevi notifiche per:
- ✅ Nuova richiesta prestito
- ✅ Nuova registrazione utente
- ✅ Prestito in scadenza domani
- ✅ Prestito scaduto
- ✅ Aggiornamento disponibile
- ✅ Errori di sistema

Badge rosso mostra numero notifiche non lette.

## 🛡️ Sicurezza Admin

### Protezione CSRF

Tutte le operazioni admin sono protette da token CSRF:
- Token generato per ogni sessione
- Validato su ogni POST/PUT/DELETE
- Scade dopo 30 minuti inattività

### Audit Log

Il sistema registra tutte le operazioni admin:
- Chi ha fatto cosa e quando
- Modifiche libri
- Approvazioni prestiti
- Modifiche impostazioni

## 🔗 Collegamenti Utili

- [→ Gestione Libri](../libri/README.md)
- [→ Sistema Prestiti](../prestiti/README.md)
- [→ Plugin](../plugin/README.md)
- [→ Developer: Admin Customization](../developer/admin.md)

## ❓ Domande Frequenti

**D: Come aggiungo un altro admin?**
R: Utenti → Modifica utente → Cambia ruolo in "Admin".

**D: Posso recuperare un libro eliminato per errore?**
R: No, l'eliminazione è permanente. Usa backup database per recupero.

**D: Come vedo chi ha preso in prestito un libro?**
R: Scheda Libro → Sezione "Prestiti Attivi" o "Storico Prestiti".

**D: Posso personalizzare la dashboard?**
R: Parzialmente. Puoi nascondere widget, ma non riorganizzarli (richiede developer).

**D: Il pannello admin è responsive?**
R: Sì, funziona su tablet. Su smartphone è utilizzabile ma ottimizzato per desktop.

---

**Ultimo aggiornamento:** Dicembre 2025
**Compatibile con:** Pinakes v0.4.1+
