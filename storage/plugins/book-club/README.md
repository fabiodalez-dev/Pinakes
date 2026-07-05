# Book Club — plugin Pinakes

Motore di **lettura collaborativa** per Pinakes (Discussion [#138](https://github.com/fabiodalez-dev/Pinakes/discussions/138)).
Implementa le **Fasi 1-4** del piano di progettazione [`docs/BOOK_CLUB_PLUGIN_PLAN.md`](../../../docs/BOOK_CLUB_PLUGIN_PLAN.md):
il nucleo (Fase 1) più **16 moduli attivabili per club** (auto-discovery da `src/Modules/*Module.php`,
checkbox nel form admin del club).

## Moduli

Attivi di default: **reading** (lettura condivisa: sezioni, tracker, progressi del club),
**discussions** (thread con spoiler protetti dal progresso di lettura, reazioni, menzioni, moderazione),
**library** (disponibilità copie, prestiti dei membri, lista d'attesa + ponte recensioni core),
**voting2** (stelle, classifica Borda, eliminazione a round, voto ponderato, quorum, spareggi),
**stats** (statistiche membro/club, rollup giornaliero, export JSON/CSV),
**governance** (ruoli personalizzati con matrice di 12 permessi + automazioni per club).

Opzionali (opt-in per club): **seasons** (stagioni con archivio storico),
**gamification** (XP, livelli, badge, classifica), **surveys** (questionari con builder),
**quotes** (citazioni e annotazioni con export), **challenges** (obiettivi annuali),
**affinity** (affinità tra lettori opt-in + suggerimenti dal catalogo),
**api** (REST read-only `/api/book-club/v1/*` con chiavi API core e OpenAPI 3.1),
**sprints** (sessioni di lettura cronometrate), **buddy** (abbinamento lettori),
**ai** (domande di discussione e riassunti verbali; chiave API cifrata in `plugin_settings`, cap 20 generazioni/24h).

## Funzionalità del nucleo (Fase 1)

- **Club illimitati** con nome, descrizione, regolamento, colore, privacy
  (`public` / `private` / `invite` / `hidden`) e limite membri opzionale.
- **Membri e ruoli di sistema** (Fondatore, Moderatore, Membro, Ospite),
  richieste di adesione con approvazione, **inviti via email** con token
  (scadenza 14 giorni), sospensioni/ban.
- **Workflow del libro configurabile per club**: stati ordinati con etichetta,
  colore e flag (`voting`, `current`, `archived`), editor in area admin, log
  delle transizioni in `bookclub_book_state_log`. Default:
  *Proposto → In votazione → Scelto → In lettura → Discussione aperta → Concluso → Archivio*.
- **Proposte dal catalogo** Pinakes (autocomplete su `libri`), con motivazione,
  moderazione opzionale e limite di proposte aperte per membro.
- **Votazioni**: voto singolo o **preferenza multipla (N voti a testa)** — il
  caso d'uso originale della #138 —, voto pubblico o segreto, scadenza con
  **chiusura automatica** (cron o lazy alla visualizzazione), spareggio
  deterministico a favore della proposta più antica, transizione automatica
  del vincitore nel workflow e ritorno dei perdenti allo stato iniziale.
- **Incontri** in presenza/online/ibridi con ordine del giorno, verbale, posti
  limitati, **RSVP** (sì/forse/no) e **promemoria email 24h prima**.
- **Feed iCal per club** (`/book-club/{slug}/calendar.ics`), sottoscrivibile da
  Google Calendar / Apple Calendar / Outlook; per i club non pubblici il feed è
  protetto dal token `ics_token`.
- **Dashboard personale** `/my/book-clubs`: lettura corrente, prossimo
  incontro, votazioni aperte per ogni club.
- **i18n completa** (it, en, de, fr).

## Architettura

```
plugin.json            manifest (main_file: wrapper.php, optional: true)
wrapper.php            proxy globale BookClubPlugin → App\Plugins\BookClub\BookClubPlugin
BookClubPlugin.php     lifecycle, ensureSchema() (12 tabelle bookclub_*), hook, rotte
src/Repo.php           accesso dati (mysqli prepared statements)
src/BaseController.php render two-pass (view → layout core), permessi, flash
src/AdminController.php    /admin/book-club/* (AdminAuthMiddleware + CSRF)
src/PublicController.php   directory, pagina club, membership, inviti, proposte, dashboard
src/PollController.php     votazioni: creazione, voto, chiusura/spoglio
src/MeetingController.php  incontri, RSVP, feed iCal, promemoria
views/admin/*, views/public/*
```

Hook core consumati: `app.routes.register`, `admin.menu.render`,
`maintenance.after_run` (fired da `MaintenanceService::runAll()` — cron di
sistema o fallback al login admin).

Hook esposti: `bookclub.club.created`, `bookclub.member.joined`,
`bookclub.member.left`, `bookclub.book.proposed`,
`bookclub.book.state_changed`, `bookclub.poll.opened`, `bookclub.poll.closed`,
`bookclub.meeting.created`, `bookclub.meeting.reminded`.

## Note operative

- Le tabelle `bookclub_*` **non vengono eliminate** né alla disattivazione né
  alla disinstallazione: lo storico delle letture sopravvive a una
  reinstallazione.
- Il plugin è bundled ma **`optional: true`**: si installa disattivato e va
  attivato da *Admin → Plugin*.
- I ruoli e il workflow di default vengono seedati in `ensureSchema()`
  (idempotente, chiamato sia da `onInstall()` sia da `onActivate()`).
- Le fasi successive del piano (lettura condivisa con spoiler, discussioni,
  statistiche, gamification, questionari, API REST, integrazione
  prestiti/prenotazioni) sono descritte in `docs/BOOK_CLUB_PLUGIN_PLAN.md`.
