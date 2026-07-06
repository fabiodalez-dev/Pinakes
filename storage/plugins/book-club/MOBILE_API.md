# Book Club — API per l'app mobile

Contratto degli endpoint esposti dal modulo `mobile` del plugin Book Club,
pensati per l'app Pinakes (Android/iOS). Richiedono il plugin **mobile-api**
attivo: l'autenticazione è lo **stesso bearer token** che l'app ottiene da
`POST /api/v1/auth/login` — nessun login aggiuntivo.

Envelope (identico alle convenzioni mobile-api):

```json
200 → {"success": true,  "data": { ... }}
4xx → {"success": false, "error": {"code": "...", "message": "..."}}
```

Codici errore ricorrenti: `not_found` (404 — club/incontro inesistente, non
visibile al token o con modulo mobile disattivato), `forbidden` (403),
`poll_closed`, `club_full`, `invite_only`, `banned`, `limit_reached`,
`duplicate`, `invalid_book`, `mode_not_supported`, `module_disabled`,
`no_seats`, `empty_ballot`, `too_many`, `invalid_option`,
`invalid_response`, `invalid_credentials`, `internal_error`.
Dai middleware del plugin mobile-api arrivano inoltre:
`app_access_disabled` (403 — accesso app spento: `mobile_api.enabled` ≠ '1';
emesso anche da `POST /api/v1/auth/login|register|forgot-password`) e
`unauthorized` (401 — token mancante, revocato o scaduto).

**Formati data**: i campi DATETIME (`closes_at`, `starts_at`, `ends_at`,
`created_at`, `updated_at`, `last_used_at`) sono **ISO-8601 UTC** con
suffisso `Z` (`2026-07-06T09:30:00Z`); i campi solo-data
(`reading_starts`, `reading_ends`) restano `yyyy-MM-dd`.

## Discovery

```
GET /api/v1/bookclub/health        (nessun token)
→ data: { plugin, enabled: true, app_access_enabled: bool, version,
          requires: ["mobile-api"], endpoints: [...] }
```

L'app la interroga dopo il login (o all'avvio): **2xx → mostra la sezione
Book Club; 404 → plugin non attivo, sezione nascosta.**
`app_access_enabled` riflette il gate `mobile_api.enabled`: con `false`
ogni endpoint autenticato risponde 403 `app_access_disabled`, quindi la
sezione va nascosta senza una seconda chiamata.

## Lettura

```
GET /api/v1/bookclub/clubs
→ data: {
    my_clubs:  [{id, slug, name, color, privacy, member_status, role}],
    directory: [{id, slug, name, description, color, privacy, member_count, max_members}]
  }

GET /api/v1/bookclub/clubs/{slug}
→ data: {
    club: {id, slug, name, description, rules*, color, privacy, member_count, max_members},
    my_membership: {status, role} | null,
    workflow: [{key, label, color, flags}],
    books: [{id, libro_id, title, authors, cover_url, state, state_label,
             state_color, is_current, reading_starts, reading_ends,
             motivation, my_progress: {percent, finished} | null}],
    polls: [{id, title, mode, status, closes_at, votes_per_member,
             voter_count, my_option_ids, options: [{id, club_book_id,
             title, score}], votable_in_app}],
    meetings: [{id, title, starts_at, ends_at, kind, status, location,
                video_url*, agenda, book_title, yes_count, seats, my_rsvp}]
  }
  (*) rules e video_url solo per membri attivi, come sul web.

GET /api/v1/bookclub/me/dashboard
→ data: { clubs: [{club, current_books (con my_progress), next_meeting, open_polls}] }
```

## Azioni

```
POST /api/v1/bookclub/clubs/{slug}/join
→ data: {status: "active" | "pending"}          (privacy public → active,
                                                 private → pending; invite/hidden → 403 invite_only)

POST /api/v1/bookclub/clubs/{slug}/proposals
body {libro_id, motivation?}
→ data: {club_book_id, state, moderated}        (regole identiche al web:
                                                 duplicati 409, limite proposte 429, moderazione)

POST /api/v1/bookclub/clubs/{slug}/polls/{pollId}/vote
body {options: [optionId, ...]}
→ data: {poll_id, options}
Modalità supportate in app: simple (1), multi (≤ votes_per_member),
weighted (pesi del poll applicati server-side). stars/ranking/elimination
→ 422 mode_not_supported: l'app apre la pagina web
/book-club/{slug}/polls/{pollId} in una WebView/Custom Tab.

POST /api/v1/bookclub/clubs/{slug}/meetings/{meetingId}/rsvp
body {response: "yes" | "no" | "maybe"}
→ data: {meeting_id, response}                  (limite posti → 409 no_seats)

POST /api/v1/bookclub/clubs/{slug}/books/{clubBookId}/progress
body {percent: 0-100, finished?: bool}
→ data: {club_book_id, percent, finished}       (richiede modulo reading attivo per il club)
```

## Note per l'app

- Gli ospiti (`role: "guest"`) sono sola-lettura: nascondere i bottoni di
  voto/proposta/RSVP quando `my_membership.role == "guest"`.
- Il voto è sostitutivo: reinviare `options` rimpiazza la scheda precedente
  finché la votazione è aperta (`my_option_ids` dice cosa ho già votato).
- La visibilità per club è governata dal modulo `mobile` (attivabile per
  club dall'admin): un club con il modulo spento risponde 404 come se non
  esistesse.
- Rate limiting/quota: le stesse quote per token del plugin mobile-api
  (TokenQuotaMiddleware) si applicano a tutti gli endpoint autenticati.
