# Piano di progettazione — Plugin Book Club per Pinakes

> Documento di design per la [Discussion #138](https://github.com/fabiodalez-dev/Pinakes/discussions/138).
> Obiettivo: un plugin di gestione della lettura collaborativa — non "un calendario con qualche commento" — che scali da un piccolo circolo di lettura a una grande rete bibliotecaria, attivando solo i moduli necessari.

---

## Indice

1. [Contesto e obiettivi](#1-contesto-e-obiettivi)
2. [Principi di progettazione](#2-principi-di-progettazione)
3. [Architettura tecnica](#3-architettura-tecnica)
4. [Sistema di moduli attivabili](#4-sistema-di-moduli-attivabili)
5. [Modello dati](#5-modello-dati)
6. [Ruoli e permessi](#6-ruoli-e-permessi)
7. [Moduli funzionali](#7-moduli-funzionali)
8. [Configurabilità a tre livelli](#8-configurabilità-a-tre-livelli)
9. [Integrazione con il core Pinakes](#9-integrazione-con-il-core-pinakes)
10. [Nuovi hook esposti dal plugin](#10-nuovi-hook-esposti-dal-plugin)
11. [API REST](#11-api-rest)
12. [Sicurezza, privacy e GDPR](#12-sicurezza-privacy-e-gdpr)
13. [Internazionalizzazione](#13-internazionalizzazione)
14. [Roadmap di implementazione](#14-roadmap-di-implementazione)
15. [Rischi e punti aperti](#15-rischi-e-punti-aperti)

---

## 1. Contesto e obiettivi

### I tre casi d'uso di riferimento

| Caso d'uso | Esigenze tipiche |
|---|---|
| **Piccola biblioteca comunale / circolo di lettura** | 1 club, proposte, votazione semplice, calendario incontri, archivio dei libri letti |
| **Grande rete bibliotecaria** | Decine di club con workflow diversi, ruoli granulari, statistiche aggregate, integrazione prestiti/prenotazioni, automazioni |
| **Associazione o libreria indipendente** | Club su invito, discussioni ricche, gamification, recensioni moderate, stagioni |

### Il caso d'uso originale (#138)

Il club descritto da HansUwe52 (club tedesco di narrativa) richiede come minimo:

- incontri mensili (con pausa estiva) → **calendario con frequenza configurabile**;
- votazione dei libri due volte l'anno, **3 voti a testa** → modalità di voto "preferenza multipla" con numero di voti configurabile;
- registro dei libri già letti e delle proposte passate → **archivio storico** e stato "Archiviato" nel workflow;
- supporto a cataloghi non anglosassoni → risolto nativamente: il club pesca dal **catalogo Pinakes** (`libri`), non da un database esterno.

Questo caso è interamente coperto dall'**MVP (Fase 1)** della roadmap in §14.

---

## 2. Principi di progettazione

1. **Modularità reale.** Il plugin è una piattaforma: ogni modulo (votazioni, discussioni, gamification, …) è attivabile a livello di installazione *e* di singolo club. Un piccolo circolo usa proposte + voto + calendario; una rete bibliotecaria attiva tutto.
2. **Riuso del core, zero duplicazioni.** I libri sono i record di `libri`; le recensioni usano `recensioni`; le email passano da `EmailService` + `email_templates`; le notifiche in-app da `NotificationService`; l'ICS da `IcsGenerator`. Il plugin aggiunge solo ciò che non esiste.
3. **Convenzioni dei plugin Pinakes.** Stessa struttura di `archives` (CRUD admin + pagine pubbliche) e `mobile-api` (REST + cron): `plugin.json` + `wrapper.php` globale + classi con namespace, `ensureSchema()` idempotente chiamato da `onInstall()` **e** `onActivate()`, hook registrati in DB in `onActivate()` e rimossi in `onDeactivate()`.
4. **Configurazione > codice.** Workflow, modalità di voto, ruoli e regole sono dati (JSON in tabella), non costanti nel codice. L'admin modifica il comportamento senza toccare PHP.
5. **Progressive disclosure.** L'interfaccia di default mostra il minimo (il "wizard" di creazione club propone 3 preset: *Circolo semplice*, *Biblioteca*, *Community avanzata*); le opzioni avanzate sono opt-in.
6. **Ogni club è un tenant.** Tutte le entità hanno `club_id`; permessi, temi, workflow e impostazioni sono per-club. Un utente appartiene a N club con dashboard separate.

---

## 3. Architettura tecnica

### Struttura del plugin

```
storage/plugins/book-club/
├── plugin.json                  # manifest (main_file: wrapper.php)
├── wrapper.php                  # classe globale BookClubPlugin (proxy __call)
├── BookClubPlugin.php           # bootstrap: lifecycle, ensureSchema, hook, rotte
├── src/
│   ├── Modules/                 # un modulo = una classe autonoma registrabile
│   │   ├── ModuleInterface.php  # slug(), defaultEnabled(), routes(), schema(), hooks()
│   │   ├── ClubsModule.php      # (core, sempre attivo)
│   │   ├── ProposalsModule.php
│   │   ├── VotingModule.php
│   │   ├── WorkflowModule.php   # (core, sempre attivo)
│   │   ├── MeetingsModule.php
│   │   ├── ReadingModule.php    # lettura condivisa + tracker + spoiler
│   │   ├── DiscussionsModule.php
│   │   ├── QuotesModule.php     # citazioni + annotazioni
│   │   ├── ReviewsBridgeModule.php
│   │   ├── LibraryBridgeModule.php  # copie/prestiti/prenotazioni
│   │   ├── StatsModule.php
│   │   ├── GamificationModule.php
│   │   ├── SurveysModule.php
│   │   ├── ChallengesModule.php
│   │   ├── SeasonsModule.php
│   │   ├── AutomationsModule.php
│   │   └── ApiModule.php
│   ├── Controllers/             # Admin*, Public*, Api* controller (Slim)
│   ├── Repositories/            # accesso dati mysqli (stile app/Models/)
│   ├── Services/                # WorkflowEngine, VoteTallier, SpoilerGate,
│   │                            # AffinityCalculator, BadgeAwarder, ReminderService
│   └── Support/                 # PermissionChecker, ClubContext, Csv/Pdf export
├── views/
│   ├── admin/                   # gestione club, workflow builder, moderazione
│   ├── public/                  # elenco club, pagina club, discussioni, dashboard
│   └── partials/
├── assets/
│   ├── css/bookclub.css
│   └── js/bookclub.js           # voto drag&drop, spoiler toggle, survey builder
└── locale/                      # stringhe aggiuntive (merge nei locale/*.json)
```

- Lo slug `book-club` va aggiunto a `App\Support\BundledPlugins::LIST` se distribuito come bundled; con `"metadata": {"optional": true}` nel manifest si installa **disattivato** di default.
- La classe globale `BookClubPlugin` (in `wrapper.php`) fa da proxy verso `App\Plugins\BookClub\BookClubPlugin`, come `mobile-api`. Costruttore `($db, $hookManager)`, supporto a `setPluginId(int)`.

### Ciclo di vita

| Metodo | Azioni |
|---|---|
| `onInstall()` | `ensureSchema()` + seed di workflow di default, ruoli di default, template email |
| `onActivate()` | `ensureSchema()` (regola "Plugin Schema Rule") + `registerHookInDb()` per gli hook core |
| `onDeactivate()` | rimozione righe da `plugin_hooks` |
| `onUninstall()` | opzionale: `DROP` delle tabelle `bookclub_*` **solo previa conferma esplicita** dell'admin (impostazione "elimina dati alla disinstallazione", default OFF) |

### Hook core consumati

| Hook | Uso |
|---|---|
| `app.routes.register` | registra tutte le rotte `/admin/book-club/*`, `/book-club/*`, `/api/book-club/v1/*` |
| `admin.menu.render` | voce di menu "Book Club" nella sidebar admin (markup Tailwind come `ArchivesPlugin::renderAdminMenuEntry`) |
| `assets.head` / `assets.footer` | CSS/JS del plugin solo sulle pagine book club |
| `book.frontend.details` | box "Club di lettura" nella scheda libro: club che lo stanno leggendo/hanno letto, pulsante "Proponi al tuo club" |
| `login.success` | refresh contatore notifiche club in sessione |
| `search.unified.sources` | (opzionale) i club pubblici compaiono nella ricerca unificata |

### Rotte (registrate via `app.routes.register`)

```
# Admin (AdminAuthMiddleware + CsrfMiddleware sui POST)
/admin/book-club                      → dashboard globale (tutti i club)
/admin/book-club/clubs[/new|/{id}]    → CRUD club + impostazioni/moduli per club
/admin/book-club/workflows[/{id}]     → workflow builder
/admin/book-club/roles                → ruoli personalizzati e permessi
/admin/book-club/moderation           → coda proposte/commenti/recensioni da moderare
/admin/book-club/stats                → statistiche aggregate
/admin/book-club/settings             → impostazioni globali + attivazione moduli

# Frontend utente (AuthMiddleware per le azioni; pagine pubbliche visibili per club "pubblici")
/book-club                            → elenco club (rispetta privacy)
/book-club/{slug}                     → home del club
/book-club/{slug}/books[/{bookId}]    → libreria del club + scheda libro nel club
/book-club/{slug}/proposals           → proposte (+ nuova proposta)
/book-club/{slug}/polls[/{pollId}]    → votazioni
/book-club/{slug}/meetings[/{id}]     → incontri + RSVP
/book-club/{slug}/reading/{bookId}    → lettura condivisa (sezioni, progressi)
/book-club/{slug}/discussions/{threadId} → thread
/book-club/{slug}/members             → membri e ruoli
/book-club/{slug}/settings            → impostazioni club (solo admin/moderatori club)
/my/book-clubs                        → dashboard personale multi-club
/book-club/{slug}/calendar.ics        → feed iCal del club (token privato in query string)

# AJAX interno (sessione)
/api/book-club/...                    → voto, RSVP, progress, reazioni, spoiler reveal
```

Le rotte pubbliche frontend possono essere localizzate via `RouteTranslator` (`locale/routes_*.json`) come le rotte eventi.

---

## 4. Sistema di moduli attivabili

Ogni modulo implementa `ModuleInterface`:

```php
interface ModuleInterface {
    public function slug(): string;              // es. 'voting'
    public function defaultEnabled(): bool;
    public function dependencies(): array;       // es. voting → proposals
    public function ensureSchema(mysqli $db): void;
    public function registerRoutes(\Slim\App $app): void;
    public function registerRuntimeHooks(HookManager $hm): void;
}
```

- **Attivazione a livello installazione**: `plugin_settings` chiave `modules.enabled` (JSON array). I moduli disattivati non registrano rotte né schema aggiuntivo.
- **Attivazione a livello club**: colonna `bookclub_clubs.enabled_modules` (JSON). L'UI del club nasconde le tab dei moduli spenti; i controller verificano il flag (403 se disattivo).
- **Dipendenze**: `voting` richiede `proposals`; `challenges` richiede `reading`; il pannello impostazioni le risolve automaticamente.
- I preset del wizard di creazione club sono semplicemente set predefiniti di `enabled_modules` + impostazioni.

Moduli **core non disattivabili**: `clubs`, `workflow`, `members`.

---

## 5. Modello dati

Tutte le tabelle con prefisso `bookclub_`, `utf8mb4_unicode_ci`, `CREATE TABLE IF NOT EXISTS` in `ensureSchema()`. FK verso il core: `utenti(id)`, `libri(id)` (attenzione al soft delete: filtrare sempre `libri.deleted_at IS NULL`), `recensioni(id)`, `events(id)`.

### Nucleo club

```sql
bookclub_clubs
  id, slug UNIQUE, name, description TEXT, logo_path, banner_path,
  color CHAR(7), privacy ENUM('public','private','invite','hidden'),
  max_members INT NULL, enabled_modules JSON, settings JSON,
  workflow_id → bookclub_workflows, created_by → utenti,
  is_active TINYINT, created_at, updated_at, deleted_at

bookclub_roles                 -- ruoli per club, granulari
  id, club_id NULL,            -- NULL = ruolo template globale
  name, slug, permissions JSON, -- es. {"proposals.approve":true,"polls.create":true,...}
  is_system TINYINT             -- owner/moderator/member/guest seedati, non eliminabili

bookclub_members
  id, club_id → bookclub_clubs, user_id → utenti, role_id → bookclub_roles,
  status ENUM('pending','active','suspended','left','banned'),
  joined_at, invited_by NULL, notes,
  UNIQUE (club_id, user_id)

bookclub_invitations
  id, club_id, email, token CHAR(64) UNIQUE, role_id, invited_by,
  expires_at, accepted_at NULL

bookclub_seasons
  id, club_id, name,            -- es. "2026 Primavera"
  starts_on DATE, ends_on DATE, books_target INT NULL, is_current TINYINT
```

### Workflow e libri del club

```sql
bookclub_workflows
  id, club_id NULL,             -- NULL = template globale riusabile
  name, states JSON             -- lista ordinata: [{key,label,color,flags:{votable,readable,discussable,reservable,archived}}]
                                -- default seed: proposed → voting → selected → reservable
                                --   → reading → discussion → finished → archived

bookclub_books                  -- un libro *nel contesto di un club*
  id, club_id, libro_id → libri, season_id NULL → bookclub_seasons,
  state VARCHAR(50),            -- key dello stato corrente del workflow
  proposed_by NULL → utenti, motivation TEXT, tags JSON, est_reading_hours INT NULL,
  reading_starts DATE NULL, reading_ends DATE NULL,
  position INT,                 -- ordinamento in stati tipo "in lettura" multipli
  created_at, updated_at,
  UNIQUE (club_id, libro_id, season_id)

bookclub_book_state_log         -- storico transizioni (audit + statistiche)
  id, club_book_id → bookclub_books, from_state, to_state, changed_by, changed_at
```

### Proposte e votazioni

```sql
-- le proposte SONO bookclub_books in stato 'proposed' (motivation, tags già lì);
-- allegati immagine delle proposte:
bookclub_attachments
  id, club_id, entity_type ENUM('proposal','post','meeting','survey'),
  entity_id, file_path, mime, uploaded_by, created_at

bookclub_polls
  id, club_id, season_id NULL, title, description,
  mode ENUM('simple','stars','ranking','multi','elimination','weighted'),
  anonymity ENUM('secret','public'),
  votes_per_member INT DEFAULT 1,      -- il "3 voti a testa" della #138
  quorum_pct TINYINT NULL, opens_at, closes_at,
  tiebreak ENUM('random','runoff','oldest_proposal','admin'),
  status ENUM('draft','open','closed','resolved'),
  winner_club_book_id NULL, created_by

bookclub_poll_options
  id, poll_id, club_book_id → bookclub_books, UNIQUE (poll_id, club_book_id)

bookclub_votes
  id, poll_id, option_id, user_id, value DECIMAL(5,2),  -- 1 per simple/multi, stelle, rank, peso
  round INT DEFAULT 1,                                   -- per eliminazione progressiva
  created_at, UNIQUE (poll_id, option_id, user_id, round)
```

### Incontri e calendario

```sql
bookclub_meetings
  id, club_id, club_book_id NULL, title, agenda TEXT, minutes TEXT,
  starts_at DATETIME, ends_at DATETIME NULL,
  kind ENUM('in_person','online','hybrid'),
  location VARCHAR(255) NULL, video_url VARCHAR(500) NULL,
  seats INT NULL, rsvp_deadline DATETIME NULL,
  event_id NULL → events,        -- pubblicazione opzionale nel calendario eventi core
  status ENUM('scheduled','done','cancelled'), created_by

bookclub_meeting_rsvps
  id, meeting_id, user_id, response ENUM('yes','no','maybe'), attended TINYINT NULL,
  UNIQUE (meeting_id, user_id)
```

### Lettura condivisa, spoiler, discussioni

```sql
bookclub_sections               -- suddivisione del libro (capitoli/parti/pagine/custom)
  id, club_book_id, title, sort INT,
  unit ENUM('chapter','part','pages','custom'),
  range_from INT NULL, range_to INT NULL,   -- pagine, se unit='pages'
  discuss_from DATE NULL                    -- data da cui la sezione è discutibile

bookclub_progress               -- reading tracker
  id, club_book_id, user_id, section_id NULL,
  percent TINYINT, page INT NULL, finished_at NULL, updated_at,
  UNIQUE (club_book_id, user_id)

bookclub_threads
  id, club_id, club_book_id NULL, section_id NULL,
  kind ENUM('general','chapter','character','free','announcement'),
  title, created_by, is_locked, is_pinned, created_at

bookclub_posts
  id, thread_id, parent_id NULL,            -- reply annidate (1 livello)
  user_id, body MEDIUMTEXT,                 -- markdown limitato, sanificato
  spoiler ENUM('none','mild','full'),
  spoiler_section_id NULL → bookclub_sections,  -- "nascosto finché non arrivi qui"
  created_at, edited_at, deleted_at

bookclub_reactions
  id, post_id, user_id, emoji VARCHAR(16), UNIQUE (post_id, user_id, emoji)

bookclub_mentions
  id, post_id, mentioned_user_id, notified_at NULL
```

### Citazioni, annotazioni, recensioni, questionari

```sql
bookclub_quotes
  id, user_id, libro_id → libri, club_id NULL,
  quote TEXT, page INT NULL, note TEXT NULL,
  visibility ENUM('private','club','public'), created_at

bookclub_notes                  -- annotazioni personali (esportabili)
  id, user_id, club_book_id, body MEDIUMTEXT,
  visibility ENUM('private','club'), created_at, updated_at

-- Recensioni: NESSUNA tabella nuova → si riusa `recensioni`
bookclub_review_meta            -- estensioni book-club alla recensione core
  id, recensione_id → recensioni, club_id,
  has_spoiler TINYINT, strengths TEXT, weaknesses TEXT

bookclub_surveys
  id, club_id, club_book_id NULL, title,
  schema JSON,                  -- output del builder drag&drop: [{type,label,options,required}]
  status ENUM('draft','open','closed'), opens_at, closes_at, anonymous TINYINT

bookclub_survey_answers
  id, survey_id, user_id NULL,  -- NULL se anonimo
  answers JSON, created_at, UNIQUE (survey_id, user_id)
```

### Gamification, challenge, statistiche

```sql
bookclub_badges
  id, slug UNIQUE, name, description, icon, rule JSON   -- es. {"metric":"books_finished","gte":10}

bookclub_user_badges
  id, user_id, badge_id, club_id NULL, awarded_at, UNIQUE (user_id, badge_id, club_id)

bookclub_xp_log
  id, user_id, club_id, action VARCHAR(50), points INT, created_at
  -- il livello è derivato: level = f(SUM(points)); classifiche = GROUP BY

bookclub_challenges
  id, club_id NULL, user_id NULL,           -- club-wide o personale
  title, metric ENUM('books','pages','countries','authors'),
  target INT, year YEAR, created_at

bookclub_challenge_progress     -- snapshot ricalcolato dal cron (le fonti restano normative)
  id, challenge_id, user_id, current INT, updated_at, UNIQUE (challenge_id, user_id)

bookclub_stats_daily            -- rollup per dashboard veloci su installazioni grandi
  id, club_id, date, metric VARCHAR(50), value INT, UNIQUE (club_id, date, metric)
```

### Automazioni e log

```sql
bookclub_automations
  id, club_id, trigger VARCHAR(50),   -- poll.open, poll.closing_soon, reading.deadline,
                                       -- section.unlocked, meeting.reminder, member.birthday
  channel ENUM('email','inapp','both'), template_name VARCHAR(100),
  offset_minutes INT DEFAULT 0, is_active TINYINT

bookclub_notification_log            -- idempotenza dei promemoria (no doppio invio)
  id, automation_id, entity_id, user_id, sent_at,
  UNIQUE (automation_id, entity_id, user_id)
```

> **Nota dimensionamento**: gli indici critici sono `(club_id, state)` su `bookclub_books`, `(thread_id, created_at)` su `bookclub_posts`, `(club_book_id, user_id)` su `bookclub_progress`. Per la rete bibliotecaria (decine di club, migliaia di post) le dashboard leggono da `bookclub_stats_daily`, non da query live.

---

## 6. Ruoli e permessi

Due piani distinti, che non vanno confusi:

1. **Ruoli Pinakes** (`utenti.tipo_utente`): `admin`/`staff` gestiscono il plugin dall'area admin (creano club, workflow template, moderano tutto). Gli utenti `standard`/`premium` partecipano dal frontend.
2. **Ruoli di club** (`bookclub_roles`): per ogni club, ruoli con permessi granulari JSON. Seed di sistema: **Owner**, **Moderator**, **Member**, **Guest** (sola lettura). L'owner può creare ruoli personalizzati ("Bibliotecario", "Organizzatore eventi", "Curatore Fantasy", "Tesoriere") spuntando permessi da una matrice:

```
club.manage_settings   members.invite      members.moderate
proposals.create       proposals.approve   proposals.limit_override
polls.create           polls.close         workflow.transition
meetings.create        meetings.minutes    threads.create
posts.moderate         reviews.moderate    surveys.create
stats.view             exports.run
```

`PermissionChecker::can(int $userId, int $clubId, string $perm): bool` centralizza i controlli (cache per request). Gli admin Pinakes bypassano sempre.

**Privacy dei club**: `public` (visibile e apribile a tutti), `private` (visibile, adesione con approvazione), `invite` (solo con token di invito), `hidden` (invisibile nelle liste; accesso solo da link diretto per membri).

---

## 7. Moduli funzionali

### 7.1 Gestione club (core)

- CRUD club con nome, descrizione, logo/banner (upload in `storage/uploads/book-club/`, riuso pipeline immagini core), colore tema (CSS custom property sulla pagina club), privacy, limite membri.
- Wizard di creazione a 3 preset (§2.5).
- Gestione membri: invito via email (token, riuso `EmailService::sendTemplate`), richieste di adesione con approvazione, sospensione/ban, trasferimento ownership.
- Regole personalizzate del club: campo testo ricco mostrato in home club e all'adesione (con checkbox "accetto le regole" opzionale).

### 7.2 Workflow del libro (core)

- Ogni club referenzia un **workflow**: lista ordinata di stati definita in JSON, ciascuno con `key`, etichetta, colore e **flag comportamentali** che accendono le feature nelle altre parti del plugin:
  - `votable` → il libro può entrare nelle votazioni;
  - `readable` → tracker e sezioni attivi;
  - `discussable` → thread apribili;
  - `reservable` → mostra il pannello disponibilità/prenotazione (modulo LibraryBridge);
  - `archived` → finisce nell'archivio storico.
- **Workflow builder** admin: riordino drag&drop, aggiunta/rinomina stati, assegnazione flag. Le transizioni consentite sono "stato adiacente" di default, con override "transizioni libere" per club.
- Il seed di default riproduce l'esempio della discussione: *Proposto → In votazione → Scelto → Prenotabile → In lettura → Discussione aperta → Concluso → Archivio*.
- Ogni transizione fa `do_action('bookclub.book.state_changed', ...)` (v. §10) e viene loggata in `bookclub_book_state_log`.

### 7.3 Proposte

- Un membro propone un libro **cercandolo nel catalogo Pinakes** (autocomplete su `libri`, FULLTEXT `ft_libri_search`); se il libro non esiste in catalogo, opzione configurabile: (a) proposta "esterna" con titolo/autore liberi che l'admin può poi importare in catalogo, oppure (b) solo catalogo (default per biblioteche).
- Campi: motivazione, tag, genere (da `generi`), durata stimata, immagini allegate.
- Impostazioni per club: approvazione automatica / moderata; max proposte per membro per stagione; proposte duplicate bloccate (UNIQUE su `club_id, libro_id, season_id`).

### 7.4 Votazioni

`VoteTallier` implementa le modalità come strategie intercambiabili sulla stessa tabella `bookclub_votes`:

| Modalità | Comportamento |
|---|---|
| `simple` | 1 voto a testa |
| `multi` | N voti a testa (`votes_per_member`) — il caso della #138 |
| `stars` | 1–5 stelle per opzione |
| `ranking` | ordinamento completo (conteggio Borda) |
| `elimination` | round successivi, l'ultima opzione esce (campo `round`) |
| `weighted` | peso del voto dal ruolo (es. Owner ×2) |

- Segreto/pubblico (`anonymity`); nel voto segreto l'UI non mostra mai chi ha votato cosa, e l'export ometta gli user_id.
- Scadenza (`closes_at`, chiusa dal cron), quorum percentuale sui membri attivi, spareggio automatico configurabile (`tiebreak`).
- Alla chiusura: il vincitore transita automaticamente allo stato successivo del workflow (es. "Scelto"), gli altri tornano a "Proposto" o vanno in archivio proposte (configurabile).

### 7.5 Calendario e incontri

- Incontri con luogo fisico e/o link videoconferenza, posti disponibili, RSVP con deadline, lista d'attesa se pieno, ordine del giorno e verbale.
- Date del libro: inizio/fine lettura (`bookclub_books`), scadenze per sezione (`bookclub_sections.discuss_from`).
- **Feed iCal per club** (`/book-club/{slug}/calendar.ics`, riuso `IcsGenerator`): funziona nativamente con Google Calendar, Apple Calendar e Outlook per sottoscrizione URL — nessuna integrazione OAuth necessaria in Fase 1. Il feed è protetto da token personale in query string per i club non pubblici.
- Pubblicazione opzionale dell'incontro nel calendario eventi core (`events`, `event_id`), utile alle biblioteche che promuovono gli incontri sul sito.

### 7.6 Lettura condivisa e tracker

- Il libro si divide in sezioni (capitoli / parti / range di pagine / custom), ciascuna con data di sblocco discussione.
- Ogni membro aggiorna il proprio progresso (percentuale o pagina) → progress bar personale e aggregata del club ("il 60% del club ha finito la Parte 2").
- Storico letture per utente e per club (fonte per statistiche e challenge).

### 7.7 Discussioni e gestione spoiler

- Thread per libro: generale, per sezione/capitolo, per personaggio, libero, annunci (bloccabili/fissabili).
- Post con markdown limitato (sanificato server-side), citazioni di altri post, immagini allegate, reazioni emoji, @mention (con notifica in-app via `NotificationService::createNotification`).
- **SpoilerGate**: ogni post dichiara `none` / `mild` / `full` + sezione di riferimento. Rendering:
  - se il lettore ha `bookclub_progress` ≥ sezione del post → visibile;
  - altrimenti collassato con blur + avviso "Spoiler fino a: Capitolo 7" e click-to-reveal esplicito.
  - `mild` mostra la prima riga, `full` nasconde tutto.
- Moderazione: soft delete dei post, lock dei thread, segnalazioni alla coda admin.

### 7.8 Citazioni e annotazioni

- Citazioni con pagina e nota personale; visibilità `private` / `club` / `public` (le pubbliche possono comparire nella scheda libro core via hook `book.frontend.details`).
- Annotazioni personali per libro-nel-club, private o condivise; **export** in Markdown/CSV/PDF (riuso delle utility export in `Support/`).

### 7.9 Recensioni (bridge sul core)

- Nessun sistema parallelo: si scrive in `recensioni` (vincolo UNIQUE utente+libro già presente, moderazione `pendente/approvata/rifiutata` già presente, notifica admin già presente con `notifyNewReview`).
- `bookclub_review_meta` aggiunge flag spoiler, punti forti/deboli e il collegamento al club.
- Impostazione per club: le recensioni dei membri sono visibili nel club subito oppure solo dopo l'approvazione core.

### 7.10 Integrazione biblioteca (LibraryBridge) — *la funzione chiave per le biblioteche*

Nel pannello del libro in stato `reservable`/`readable`, per ogni membro:

- **copie disponibili** (`libri.copie_disponibili`, dettaglio per sede da `copie`);
- **chi del club ha il libro in prestito** ora (`prestiti` con `stato IN ('in_corso','in_ritardo')`, mostrando solo membri del club, nel rispetto della privacy: opzione per anonimizzare);
- **lista d'attesa** (`prenotazioni.queue_position`);
- pulsante **"Prenota la tua copia"** che riusa il flusso prenotazioni esistente;
- avviso automatico all'admin del club se le copie sono insufficienti rispetto ai membri che hanno fatto RSVP ("8 partecipanti, 2 copie: valuta l'acquisto o l'allungamento dei tempi di lettura").

Modulo disattivabile per i casi d'uso senza gestione prestiti (associazioni, librerie).

### 7.11 Statistiche

- **Per utente**: libri conclusi, pagine lette, velocità media (pagine/giorno dal tracker), generi e autori più letti, tasso di partecipazione (RSVP onorati, voti espressi, post scritti).
- **Per club**: libri conclusi, incontri effettuati, media voti/recensioni, membri attivi (attività ultimi 30 giorni), trend.
- Rollup giornaliero nel cron → `bookclub_stats_daily`; dashboard admin con grafici (riuso Chart.js già presente negli asset admin, o SVG server-side).

### 7.12 Gamification (modulo opzionale, OFF nei preset "Biblioteca" e "Circolo semplice")

- Badge data-driven (`rule` JSON valutata da `BadgeAwarder` nel cron o su evento): *Primo libro*, *10 libri*, *50 libri*, *Recensionista*, *Votante*, *Organizzatore*…
- XP per azioni (post, voto, libro concluso, verbale scritto), livelli derivati, classifiche per club e stagione.
- Tutto per-club: nessun punteggio globale forzato tra club diversi.

### 7.13 Questionari

- Builder drag&drop (JS vanilla, coerente con gli asset esistenti) che produce `schema` JSON: testo breve, testo lungo, scelta singola/multipla, scala 1–5, sì/no.
- Questionari per libro ("Personaggio preferito?", "Finale convincente?") o per club; anonimi o nominali; risultati aggregati con grafici, export CSV.

### 7.14 Dashboard

- **Utente** (`/my/book-clubs`): per ogni club — libro corrente con progress bar, prossima riunione con RSVP inline, votazioni aperte, ultime discussioni, notifiche. Dashboard separate per club, con switcher.
- **Admin** (`/admin/book-club`): salute di tutti i club (membri attivi, libri in corso, code di moderazione), scorciatoie.

### 7.15 Automazioni

- `bookclub_automations` per club: promemoria incontro (offset configurabile), apertura/chiusura votazioni, scadenza lettura, sblocco nuova sezione, compleanno membri (da `utenti`, se il dato esiste ed è consentito).
- Motore: il plugin registra l'azione **`bookclub.cron.tick`** e chiede (patch minima al core, come già fatto per `mobile_api.dispatch_push`) che `MaintenanceService::runAll()` la invochi. Fallback già esistente: `runIfNeeded()` al login admin fa avanzare le automazioni anche senza crontab.
- Canali: email (`EmailService` + nuovi template seedati in `email_templates`, localizzati) e in-app (`NotificationService`). Idempotenza via `bookclub_notification_log`. Rispetto di `preferenze_notifica_utenti`.

### 7.16 Funzioni avanzate (Fase 3)

- **Reading Challenge**: obiettivi annuali (12 libri, 5000 pagine, 10 nazioni, 20 autori) personali o di club, con progress ricalcolato dal cron.
- **Affinità tra lettori**: `AffinityCalculator` con similarità coseno su vettori (voti alle votazioni, stelle recensioni, generi letti) → "Affinità con Marco: 92%". Solo tra membri dello stesso club, opt-in privacy.
- **Suggerimenti automatici**: dai gusti aggregati del club → autori simili e libri del catalogo mai letti dal club (query su `libri`/`generi`/`autori` + storico `bookclub_books`). Niente dipendenze esterne: è un ranking sul catalogo locale.
- **Stagioni**: raggruppamento di libri/votazioni/incontri, archivio storico navigabile per stagione ("2026 Primavera").

### 7.17 Estensioni future (fuori roadmap, abilitate dall'architettura a moduli)

Prestito condiviso tra membri; Reading Sprint cronometrati; Buddy Reading; scaffale virtuale tematico; quiz post-lettura; generatore IA di domande di discussione e verbali riassunti (opt-in, chiave API in `plugin_settings` che è già cifrato AES-256-GCM at rest); export completo cronologia club (PDF/CSV/JSON).

Ognuna di queste è un nuovo `Module` autonomo: il core del plugin non va toccato.

---

## 8. Configurabilità a tre livelli

| Livello | Dove | Esempi |
|---|---|---|
| **Installazione** | `plugin_settings` (pannello `/admin/book-club/settings`, pattern self-rendering come mobile-api) | moduli abilitati globalmente, chi può creare club (solo admin / staff / tutti), limiti globali (max club, max upload), chiave API per moduli IA |
| **Club** | `bookclub_clubs.settings` + `enabled_modules` | workflow, modalità voto di default, frequenza incontri, max membri, moderazione proposte/recensioni, libri contemporanei, regole |
| **Utente** | preferenze in `plugin_data` per user o colonne dedicate | notifiche per club, visibilità progressi/affinità, digest email |

---

## 9. Integrazione con il core Pinakes

| Funzione core | Come viene riusata |
|---|---|
| Catalogo `libri` (+ `autori`, `generi`, `copie`, soft delete) | fonte unica dei dati bibliografici; nessuna anagrafica libro duplicata |
| `recensioni` + moderazione admin | recensioni del club (bridge §7.9) |
| `events` + `IcsGenerator` | pubblicazione incontri sul sito + feed iCal |
| `prestiti`, `prenotazioni` | pannello disponibilità/prenotazione (§7.10) |
| `EmailService` + `email_templates` | inviti, promemoria, digest — template modificabili dall'admin, localizzati |
| `NotificationService` / `admin_notifications` | notifiche in-app (mention, votazioni, code di moderazione) |
| `MaintenanceService` + `cron/full-maintenance.php` | tick automazioni (`bookclub.cron.tick`) |
| `AuthMiddleware` / `AdminAuthMiddleware` / `CsrfMiddleware` / `RateLimitMiddleware` | protezione rotte (rate limit su post/voti per anti-abuso) |
| `I18n` / `__()` / `RouteTranslator` | tutte le stringhe e le rotte pubbliche |
| `plugin_settings` (cifrato) / `plugin_data` / `plugin_logs` | configurazione, preferenze, audit |

**Patch minime al core richieste** (da proporre come PR separate, retrocompatibili):

1. `MaintenanceService::runAll()`: aggiungere `doAction('maintenance.after_run')` generico (o direttamente `bookclub.cron.tick`) accanto a `mobile_api.dispatch_push` — 1 riga.
2. (Opzionale, Fase 2) hook `user.profile.tabs` nel profilo utente frontend per la tab "I miei club". In alternativa il plugin usa una pagina propria `/my/book-clubs` senza toccare il core.

---

## 10. Nuovi hook esposti dal plugin

Il plugin è a sua volta estensibile (coerente con la filosofia di `COMPLETE_HOOKS_SYSTEM.md`):

```
bookclub.club.created / updated / deleted
bookclub.member.joined / left / role_changed
bookclub.book.proposed
bookclub.book.state_changed        (club_book, from, to)
bookclub.poll.opened / closed      (poll, results)
bookclub.meeting.created / reminded
bookclub.post.created              (filtro: bookclub.post.render per il body)
bookclub.badge.awarded
bookclub.cron.tick
bookclub.dashboard.widgets         (filtro: widget aggiuntivi nella dashboard utente)
```

Le estensioni future (§7.17) si agganciano qui senza modificare il plugin base.

---

## 11. API REST

Modulo `api` opzionale, modellato su mobile-api:

- prefisso `/api/book-club/v1/*`, autenticazione con gli stessi bearer token di mobile-api se attivo (riuso `mobile_app_tokens`/`AppAuthMiddleware`), altrimenti `api_keys` core con `ApiKeyMiddleware`;
- risorse: `clubs`, `clubs/{id}/books`, `polls` (+ voto), `meetings` (+ RSVP), `progress`, `reviews`, `stats`;
- schema OpenAPI 3.1 pubblicato su `/api/book-club/v1/openapi.json` (pattern mobile-api);
- rate limit con `RateLimitMiddleware`.

Consente app mobile, widget esterni sul sito della biblioteca, integrazioni di rete.

---

## 12. Sicurezza, privacy e GDPR

- **CSRF** su tutti i POST (`CsrfMiddleware`), **prepared statements** ovunque (convenzione mysqli del progetto), output escape con `htmlspecialchars` nelle view PHP, sanificazione del markdown dei post (whitelist tag).
- **Upload**: validazione MIME reale + estensioni whitelist, dimensione max da impostazione, storage fuori da percorsi eseguibili.
- **Privacy by default**: progressi di lettura, statistiche personali e affinità visibili solo secondo le preferenze utente; voto segreto realmente non riconducibile nelle esportazioni; club `hidden` esclusi da sitemap/ricerca.
- **GDPR**: i dati book-club rientrano nell'export/cancellazione utente — il plugin registra un handler sull'eliminazione utente (le FK `ON DELETE CASCADE` verso `utenti` coprono la cancellazione; i post possono essere anonimizzati invece che cancellati, a scelta dell'admin, per non distruggere le discussioni).
- **Minori**: campo opzionale per club "riservato a maggiorenni" se la biblioteca gestisce utenti giovani.

---

## 13. Internazionalizzazione

- Tutte le stringhe UI in `__()` / `__n()`; chiavi aggiunte a `locale/it_IT.json`, `en_US.json`, `de_DE.json`, `fr_FR.json` (il caso d'uso #138 è tedesco: de_DE completo fin dall'MVP).
- Rotte pubbliche registrate per locale via `RouteTranslator` (`locale/routes_*.json`).
- Template email per locale in `email_templates` (name + locale), seedati in `onInstall()`.
- Nomi di stati del workflow e ruoli custom sono dati inseriti dall'admin → non passano da `__()` (sono già nella lingua della biblioteca).

---

## 14. Roadmap di implementazione

### Fase 1 — MVP «il circolo di lettura» (copre interamente la #138)

> Moduli: clubs, members/roles (solo ruoli di sistema), workflow (builder base), proposals, voting (`simple` + `multi`), meetings + RSVP + iCal, dashboard utente, archivio.

1. Scaffolding plugin (manifest, wrapper, `ensureSchema`, hook `app.routes.register` + `admin.menu.render`, voce sidebar).
2. Tabelle nucleo (clubs, roles, members, invitations, workflows, books, state_log, polls, options, votes, meetings, rsvps).
3. CRUD club admin + wizard preset; pagine pubbliche club; inviti email.
4. Workflow engine + seed default; proposte da catalogo; votazione simple/multi con scadenza e chiusura automatica; transizione automatica del vincitore.
5. Incontri con RSVP e feed iCal; promemoria incontro via cron tick.
6. i18n (it/en/de/fr) + template email seedati.

**Definizione di fatto per l'MVP**: il club della #138 può — creare il club, registrare i membri, raccogliere proposte, aprire a giugno/dicembre una votazione con 3 voti a testa, calendarizzare gli incontri mensili con pausa estiva, e consultare l'archivio dei libri letti.

### Fase 2 — «la biblioteca»

> Moduli: LibraryBridge (disponibilità/prestiti/prenotazioni), ReviewsBridge, reading tracker + sezioni + spoiler, discussioni complete (reazioni, mention, moderazione), ruoli personalizzati con matrice permessi, statistiche + rollup, automazioni configurabili, stagioni, voto `stars`/`ranking`/segreto/quorum/spareggio.

### Fase 3 — «la community»

> Moduli: gamification (badge/XP/classifiche), questionari con builder, citazioni & annotazioni con export, challenge, affinità lettori, suggerimenti, API REST, voto `elimination`/`weighted`.

### Fase 4 — estensioni

> Sprint di lettura, buddy reading, quiz, moduli IA (domande di discussione, verbali automatici), export cronologia completa, prestito tra membri.

Ogni fase è rilasciabile e utile da sola; i preset del wizard espongono solo i moduli della fase installata.

---

## 15. Rischi e punti aperti

| Rischio / decisione | Mitigazione / proposta |
|---|---|
| **Scope creep**: la spec è enorme | La roadmap a fasi è vincolante; l'MVP chiude la #138. Ogni modulo extra è dietro feature flag |
| **Prestazioni con decine di club** | Rollup `bookclub_stats_daily`, indici dedicati, paginazione ovunque, niente COUNT live nelle dashboard |
| **Sync calendari**: OAuth Google/Outlook è oneroso | Fase 1 usa **feed iCal in sottoscrizione** (funziona con Google/Apple/Outlook senza OAuth). Push bidirezionale solo se emergerà domanda reale |
| **Compleanni membri**: `utenti` potrebbe non avere la data di nascita per tutte le installazioni | L'automazione si attiva solo se il dato esiste; in alternativa campo profilo del plugin, opt-in |
| **Libri fuori catalogo** (associazioni senza biblioteca) | Proposte "esterne" opzionali (§7.3) con promozione a record `libri` da parte dell'admin |
| **Moderazione UGC** (post, immagini) | Code di moderazione, segnalazioni, rate limit, soft delete; impostazione "pre-moderazione post" per club |
| **`PluginController::updateSettings` ha branch hardcoded per plugin** | Il plugin usa il pattern *self-rendering settings page* (come mobile-api) e gestisce i propri POST |
| **Hook cron nel core** | Serve la micro-PR su `MaintenanceService::runAll()`; nel frattempo il fallback `runIfNeeded()` al login admin mantiene vive le automazioni |
| **Voto segreto vs. audit** | Si logga *che* un membro ha votato (per quorum), mai *cosa*; export anonimizzati |

---

*Documento redatto a partire dall'analisi del codice: `app/Support/PluginManager.php`, `app/Support/HookManager.php`, `app/Routes/web.php`, `storage/plugins/archives/`, `storage/plugins/mobile-api/`, `docs/examples/example-plugin/`, `installer/database/schema.sql`, `app/Support/{NotificationService,EmailService,MaintenanceService,IcsGenerator}.php`.*
