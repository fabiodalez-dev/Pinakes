# Installazione Pinakes - Guida Semplice

> **Buone notizie!** L'installazione è **SEMPLICISSIMA** - Basta seguire 5 passi e il sistema funziona.

---

## Introduzione

**Pinakes** viene fornito con un **installer web automatico** che fa tutto il lavoro per te. Non devi:
-  Scrivere comandi nel terminale
-  Configurare manualmente database
-  Editare file di configurazione
-  Conoscere PHP o MySQL

**Tutto è automatico!** 

---

## Requisiti Minimi

Prima di iniziare, verifica di avere:

| **Requisito** | **Cosa Serve** |
|---|---|
| **Web Server** | Hosting con PHP 8.1+ (il tuo provider la avrà) |
| **Database** | MySQL 8.0+ o MariaDB 10.4+ (incluso in hosting) |
| **Browser** | Chrome, Firefox, Safari, Edge (qualunque moderno) |
| **Spazio** | Almeno 100MB di spazio disco |
| **Connessione** | Internet per scaricare il pacchetto |

**Se hai hosting commerciale (Aruba, SiteGround, Bluehost, ecc.)**:  HAI GIÀ TUTTO!

---

## Installazione in 5 Passi (15 Minuti Totali)

### Passo 1: Scarica il Pacchetto

1. **Scarica** il pacchetto `biblioteca-latest.zip` dal sito del progetto
2. **Estrai** il file ZIP sul tuo computer
3. **Vedrai una cartella**: `biblioteca/`

### Passo 2: Carica il Pacchetto sull'Hosting

**Tramite FTP (il modo più facile):**

1. **Apri il tuo client FTP** (FileZilla, Cyberduck, Transmit, ecc.)
2. **Connettiti** al tuo hosting con le credenziali fornite dal provider
3. **Naviga** nella cartella `public_html` o `www`
4. **Trascina e rilascia** la cartella `biblioteca/` lì
5. **Aspetta** il caricamento (1-5 minuti a seconda della velocità)

**Alternativa - Via cPanel (se il tuo hosting ha cPanel):**

1. **Accedi a cPanel** (il pannello di controllo del hosting)
2. **File Manager** → `public_html`
3. **Upload** → seleziona il file `biblioteca-latest.zip`
4. **Extract** (estrai il file direttamente su cPanel)

### Passo 3: Accedi all'Installer Web

1. **Apri il browser**
2. **Digita**: `http://tuodominio.it/biblioteca/installer/`
   - Sostituisci `tuodominio.it` con il tuo vero dominio
   - Esempio: `http://biblioteca.scuola.it/biblioteca/installer/`
3. **Premi Invio**
4. **Vedrai**: Una pagina con logo e un bottone "Inizia"

### Passo 4: Segui l'Installer Automatico (7 Step)

L'installer ti guiderà automaticamente. **Non devi fare nulla di tecnico!**

**Step 1: Verifica Requisiti**
```text
 Il sistema controlla che il tuo hosting abbia PHP e MySQL
 Se tutto è ok, vai al passo successivo
```

**Step 2: Crea il Database**
```text
 Metti: Nome database (es. "biblioteca_db")
 Metti: Utente database (es. "bib_user")
 Metti: Password (sicura, es. "Abc123!@#")
 Il sistema crea tutto automaticamente
```

**Step 3: Crea l'Amministratore**
```text
 Metti: Nome (es. "Mario")
 Metti: Email (es. mario@scuola.it)
 Metti: Password (per l'admin)
 Il sistema crea l'utente admin
```

**Step 4: Configura il Nome della Biblioteca**
```text
 Metti: Nome biblioteca (es. "Biblioteca Scuola Galilei")
 Metti: Email per notifiche (es. biblioteca@scuola.it)
 Metti: Logo (facoltativo, puoi farlo dopo)
```

**Step 5: Email (Facoltativo per Ora)**
```text
 Puoi lasciar vuoto e configurare dopo
 O inserire Gmail/Outlook se lo conosci
```

**Step 6: Verifica Finale**
```text
 Il sistema controlla che tutto funziona
 Se ok, vai al passo finale
```

**Step 7: Completamento**
```text
 L'installer dice "Installazione completata!"
 Salva le credenziali (password admin)
 Accedi alla dashboard
```

### Passo 5: Accedi e Inizia a Usare

1. **Clicca** il link "Vai al Sistema" fornito dall'installer
2. **Oppure digita**: `http://tuodominio.it/biblioteca/`
3. **Login**:
   - **Email**: Quella che hai inserito nello Step 3
   - **Password**: Quella che hai creato nello Step 3
4. **Vedrai**: La dashboard principale
5. **Fatto!**  Il sistema è pronto

---

## Dashboard Principale

Dopo il login vedrai:

```
Dashboard
├─  Libri (155 totali)
├─  Prestiti Attivi (12)
├─ ⏰ Scadenze Imminenti (5)
├─  Utenti (45)
└─  Impostazioni
```

**Prossimi passi:**

1.  [Vai a "Impostazioni" per personalizzare](../settings.MD)
2.  [Inizia ad aggiungere libri](../inserimento_libri.MD)
3.  Invita utenti alla biblioteca

---

## FAQ Installazione

### "Non riesco ad accedere all'installer"

**Soluzione:**
- Verifica che il percorso sia esatto: `tuodominio.it/biblioteca/installer/`
- Se NON appare niente, ricarica la pagina (CTRL+F5 o CMD+SHIFT+R)
- Se ancora NON funziona, contatta il supporto del tuo hosting

### "Mi chiede credenziali del database, non le conosco"

**Soluzione:**
- Accedi a **cPanel** del tuo hosting
- Cerca **"MySQL Databases"** o **"PHPMyAdmin"**
- Lì troverai i dati del database forniti dal provider
- Oppure: crea un nuovo database/utente tramite cPanel

### "Errore: 'Cannot connect to database'"

**Soluzione:**
-  Controlla che il nome del database sia scritto esattamente giusto
-  Controlla che la password sia corretta
-  Chiedi al tuo hosting se il servizio MySQL è attivo

### "Errore: 'Class PDO not found'"

**Soluzione:**
- Il tuo hosting non ha PHP configurato correttamente
- Contatta il supporto del tuo hosting e chiedi che attivino l'estensione PDO
- (La maggior parte dei provider moderni ce l'ha già)

### "L'installer è lentissimo"

**Soluzione:**
- È normale! La prima volta crea il database (può durare 2-3 minuti)
- Aspetta, non ricarica la pagina
- Se aspetti più di 10 minuti, contatta il supporto del hosting

### "Voglio reinstallare da zero"

**Soluzione:**
1. Accedi a cPanel
2. **MySQL Databases** → Elimina il database
3. **File Manager** → Elimina la cartella `biblioteca/`
4. Ricomincia dal Passo 1

### "Come cambio il dominio dopo l'installazione?"

**Soluzione:**
-  Il sistema lo rileva automaticamente
- Non devi fare nulla!
- Se hai problemi con rewriting URL, contatta il supporto del hosting

---

## Installazione Completata!

**Congratulazioni!** Pinakes è ora online e pronto all'uso.

### Prossimi Passi Consigliati

1. **Personalizza il Sistema** → [Vai a Impostazioni](../settings.MD)
   - Cambia il nome della biblioteca
   - Carica il logo
   - Configura l'email

2. **Aggiungi Scaffali** → [Vai a Collocazione](../collocazione.MD)
   - Crea gli scaffali fisici (A, B, C, ecc.)
   - Crea le mensole (livelli)

3. **Aggiungi i Primi Libri** → [Vai a Inserimento Libri](../inserimento_libri.MD)
   - Inizia con 10-20 libri di prova
   - Usa il metodo automatico con ISBN

4. **Invita Utenti** → Torna in Dashboard
   - Crea account per studenti/docenti
   - Spiega loro come funziona

5. **Configura Email** → [Vai a Email e Notifiche](../email.MD)
   - Configura Gmail o Outlook
   - Testa l'invio di email

6. **Prova un Prestito** → [Vai a Sistema Prestiti](../prestiti.MD)
   - Prova a fare un prestito
   - Verifica che tutto funziona

---

## Supporto

**Se hai problemi:**

1. **Leggi** la sezione FAQ sopra
2. **Controlla** i log nel tuo hosting (cPanel → Errors)
3. **Contatta** il supporto del tuo hosting per problemi di server
4. **Contatta** gli sviluppatori del progetto per problemi del software

---

## Struttura Dopo l'Installazione

```
tuodominio.it/
├─ admin/           ← Dashboard amministratore
│  ├─ libri/       ← Gestione libri
│  ├─ prestiti/    ← Gestione prestiti
│  ├─ utenti/      ← Gestione utenti
│  └─ settings/    ← Impostazioni
│
├─ catalogo/        ← Catalogo pubblico (per gli utenti)
├─ profilo/         ← Profilo utente
└─ docs/            ← Questa documentazione
```

---

**Sei pronto!** Inizia subito a usare Pinakes! 

---

*Ultimo aggiornamento: 19 Ottobre 2025*
*Versione: 2.0.0 - Installazione Semplificata*
*Tempo lettura: 5 minuti | Tempo installazione: 15 minuti*
