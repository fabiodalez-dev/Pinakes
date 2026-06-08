# Changelog

Cronologia delle versioni di Pinakes con le principali novità, fix e PR associate.

Per l'ultimo aggiornamento consulta sempre la [pagina releases su GitHub](https://github.com/fabiodalez-dev/Pinakes/releases).

---

## In arrivo — Prestiti configurabili, Modalità privata, rotte admin EN

> Release candidate in preparazione (**0.7.18-rc.1**), non ancora pubblicata come release stabile.

- **Sistema prestiti e prenotazioni** (#157): durata predefinita, numero massimo di prestiti attivi per utente, numero massimo di rinnovi e giorni per il ritiro sono ora **configurabili** da *Impostazioni → Prestiti*. Modello di occupazione delle copie unificato e multi-copia, riassegnazione automatica della copia restituita alla prossima prenotazione in coda, automatismi di manutenzione e notifiche email differite con nuovi template. Vedi [Prestiti](guida/prestiti.md#impostazioni-configurabili-dei-prestiti).
- **Modalità privata** (#158 / #160): nuovo interruttore che restringe l'intero sito pubblico ai soli utenti autenticati, deferendo le rotte API-key al loro gate. Vedi [Modalità Privata](admin/modalita-privata.md).
- **Rotte amministrative in inglese** (#145 / #161): i percorsi `/admin/*` sono ora letterali inglesi (es. `/admin/books`, `/admin/loans`, `/admin/publishers`), con redirect dai vecchi percorsi italiani per retro-compatibilità.

---

## v0.7.16 — 2026-05-31
**PR:** [#148](https://github.com/fabiodalez-dev/Pinakes/pull/148) (multi-editore), [#149](https://github.com/fabiodalez-dev/Pinakes/pull/149) (standard bibliografici), [#151](https://github.com/fabiodalez-dev/Pinakes/pull/151), [#154](https://github.com/fabiodalez-dev/Pinakes/pull/154), [#155](https://github.com/fabiodalez-dev/Pinakes/pull/155), [#156](https://github.com/fabiodalez-dev/Pinakes/pull/156)

Release maggiore che consolida tre filoni: **supporto multi-editore** per le
schede libro, una nuova famiglia di plugin per gli **standard bibliografici**
(FRBR/LRM, RDA Registry, REICAT/SBN) e un refactoring profondo delle dipendenze
(league/csv + Illuminate + Guzzle, sanitizzazione HTML basata su parser).

### Multi-editore (#143)

- **`libri_editori`** — nuova tabella di giunzione M:N: un libro può ora avere
  più editori con ordinamento (`ordine`). Merge, filtri e import/enrichment
  riallineano automaticamente la giunzione, con guard anti-drift che declassano
  gli «impostori» a `ordine 0` prima di promuovere l'editore primario.
- Tutti gli editori vengono emessi in **OAI-PMH** e **BIBFRAME** (#143).

### Standard bibliografici (#149)

- **Plugin `frbr-lrm`** — modello Work–Expression FRBR / IFLA LRM: tabelle
  `opere`, `espressioni`, `libri_autori_ruoli` (ruoli relator MARC21), con FK
  nullable `libri.opera_id` / `libri.espressione_id` (guardate da
  INFORMATION_SCHEMA, `ON DELETE SET NULL`). CRUD admin per le opere e pagina
  pubblica `/opera/{slug}`.
- **RDA Registry** e mapping **REICAT/SBN** per l'interoperabilità con il
  Servizio Bibliotecario Nazionale; prefill del campo import REICAT, ISBN
  «pinnato» sulla query SBN.

### Refactoring dipendenze & sicurezza

- **`league/csv`** centralizza tutto l'import/export CSV/TSV; adozione delle
  collection **Illuminate** e dell'HttpClient **Guzzle**.
- **`symfony/html-sanitizer`** — sanitizzazione HTML basata su parser (non più
  regex), con guard CSV-injection sugli export.
- **Canale release-candidate** opt-in per sviluppatori nell'updater + supporto
  alle prerelease (#155).
- **Durata sessione configurabile** da admin (#142), fix logout spuri
  (SameSite=Lax + rotazione ID non distruttiva), floor **PHP 8.2** imposto nel
  bootstrap prima dell'autoloader, pin del platform composer a PHP 8.2.
- **Hardening SSRF** sui client Z39/SBN (protocolli cURL ristretti a http/https).

### Migration

`migrate_0.7.16.sql` → tabella `libri_editori` + indici, idempotente via guard
`INFORMATION_SCHEMA`.

## v0.7.15-rc.3 — 2026-05-30
**Tipo:** Release Candidate (prerelease) — solo canale developer

Prima prerelease distribuita tramite il nuovo **canale release-candidate**
dell'updater (#155). Anticipa il payload poi stabilizzato in v0.7.16:
multi-editore (#148), standard bibliografici FRBR/LRM + RDA + REICAT/SBN (#149),
refactoring su league/csv + Illuminate + Guzzle (#151) e sanitizzazione HTML
basata su parser (#154). Disponibile solo agli utenti che abilitano
esplicitamente il canale RC via env — gli aggiornamenti di produzione restano
sul canale stabile.

## v0.7.14 — 2026-05-27
**Commit:** `8be24209…` — hotfix di consolidamento

Patch di stabilizzazione immediatamente successiva a v0.7.13: rebuild degli
asset, allineamento dei test e fix minori emersi dall'ultima suite pre-merge.
Nessuna nuova feature utente — bump tecnico per portare a tutti gli utenti gli
ultimi fix di CSS/layout e i test di regressione di #144.

## v0.7.13 — 2026-05-27
**PR:** [#144](https://github.com/fabiodalez-dev/Pinakes/pull/144) — roadmap unificata: **SwalApp** + layout evento + **RiC-CM**
**Issues:** [#136](https://github.com/fabiodalez-dev/Pinakes/issues/136), [#137](https://github.com/fabiodalez-dev/Pinakes/issues/137), [#139](https://github.com/fabiodalez-dev/Pinakes/issues/139), [#140](https://github.com/fabiodalez-dev/Pinakes/issues/140), [#141](https://github.com/fabiodalez-dev/Pinakes/issues/141)

Roadmap che unifica tre filoni in un'unica release.

### Unificazione popup → SweetAlert2 (#140)

- **`SwalApp`** — tutti i popup client-side (conferme, alert, prompt) passano a
  un bus centralizzato basato su **SweetAlert2**, con `confirmText` di default
  «kind-aware» e 49 test riutilizzabili. Niente più `window.confirm` nativi.

### Layout immagine evento configurabile (#137)

- **Layout admin-configurabile** per l'hero image degli eventi: modalità
  contenuta (3:2) e thumb (griglia) ridisegnate, preset che producono immagini
  progressivamente più piccole e allineate a sinistra, con preservazione
  dell'upload quando si spunta anche «rimuovi immagine».

### Archives — RiC-CM e perfezionamenti (#136)

- Estensione del plugin **Archives** verso **Records in Contexts (RiC-CM)**, fix
  IIIF route, ciclo place-ancestor e test endpoint README.

### Copertine, ricerca, dettaglio

- **`covers`** — compressione + cache HTTP, self-heal delle copertine locali
  mancanti, containment via `realpath`, drop del MIME deprecato `font-woff`.
- Allineamento a sinistra dell'anno nel dropdown hero-search; blocchi prose del
  dettaglio libro che riempiono il contenitore.
- Hardening views: rifiuto degli input `$_GET` non scalari prima dell'uso come
  stringa/intero; `htmlspecialchars` + `JSON_HEX_TAG` su 3 punti di echo non
  sicuri.
- **25 test E2E** browser-driven per scraping + form-entry, più ripristino del
  monkey-patch Choices.js `_onEnterKey` (regressione #74).

## v0.7.7 — 2026-05-20
**Commit:** `1aa1831b` — fix locale + regressione autori

Patch correttiva mirata:

- **`i18n`** — il cambio di lingua dell'applicazione propaga ora il locale di
  default a **tutti** gli utenti, evitando UI nel locale sbagliato.
- **Fix regressione #74** — ripristino del monkey-patch `_onEnterKey` di
  Choices.js: premere Invio su un autore digitato-ma-non-esistente conferma di
  nuovo il valore digitato, anche quando un prefisso parziale combacia con un
  autore esistente.

---

## v0.7.6 — 2026-05-13
**PR:** [#132](https://github.com/fabiodalez-dev/Pinakes/pull/132)

### French locale + integrazione BNF

- **Traduzione francese completa** (`fr_FR`) — 4.145 chiavi tradotte
  (100% coverage). Selezionabile dall'installer; le installazioni
  esistenti possono cambiare la locale da Impostazioni → Localizzazione.
- **Z39 Server / BNF (Bibliothèque nationale de France)** — il plugin
  Z39 Server ora include un client SRU verso la BNF con parser UNIMARC
  e mapping completo dei campi (titolo, autori, editore, ISBN, Dewey
  dal campo 676, soggetti). Aggiungi `sru.bnf.fr` come fonte in
  Impostazioni → Z39 Server per importare record francesi.

### Archives — IIIF 3.0 + AtoM alignment + multi-document upload

- **IIIF Presentation 3.0** ([#123](https://github.com/fabiodalez-dev/Pinakes/issues/123)) — `GET /archives/{id}/manifest.json`
  restituisce un manifest IIIF 3.0 standard-compliant per ogni unità
  archivistica. Compatibile con Universal Viewer, Mirador e ogni
  viewer IIIF. Il blocco `seeAlso` punta a Dublin Core, EAD3, METS,
  OAI-PMH record e ARK identifier.
- **AtoM ISAD(G) area labels** ([#121](https://github.com/fabiodalez-dev/Pinakes/issues/121)) — l'UI admin e la pagina pubblica
  usano i nomi canonici delle aree ISAD(G) (`Identity area`,
  `Context area`, `Content and structure area`, `Conditions of access
  and use area`, `Allied materials area`, `Notes area`), così i record
  sono immediatamente riconoscibili a chi viene da AtoM.
- **Multi-document upload** — ogni unità archivistica può ora avere
  più file digitalizzati allegati (PDF / ePub / audio / video), oltre
  alla cover image. Drag-and-drop in admin; ogni file con nome
  originale, MIME type e ordine di visualizzazione.

### Security fixes

- **Open-redirect via Host spoofing** — fix nel resolver OpenURL: ora
  usa `absoluteUrl()` invece di costruire l'URL direttamente da
  `Host:` header (che bypassava `APP_TRUSTED_HOSTS`).
- **CQL injection nel client SRU** — i termini di ricerca contenenti
  `"` o `\` sono ora correttamente escaped prima di essere inseriti
  nelle query CQL verso endpoint SRU esterni (BNF, SUDOC).

### Compatibilità

- **Updater Windows** ([#130](https://github.com/fabiodalez-dev/Pinakes/issues/130)) — separatori path ora normalizzati a forward
  slash prima del lookup version file.
- **Route tedesche** — aggiunta la chiave `bibframe.book` mancante in
  `routes_de_DE.json` (parità con IT/EN/FR).

---

## v0.7.5 — 2026-05-09
**PR:** intermedia tra #131 e #132

### Hardening migrazione locale francese

Migrazione `migrate_0.7.5.sql` usa `ON DUPLICATE KEY UPDATE` invece di
`INSERT IGNORE` per garantire che `fr_FR` sia attivato anche su upgrade
dove la riga lingua esisteva già con `is_active=0`. `Language::setDefault()`
forza `is_active=1` sulla lingua target per evitare lo stato inconsistente
"default invisibile alla resolution chain".

---

## v0.7.4 — 2026-05-04
**PR:** [#129](https://github.com/fabiodalez-dev/Pinakes/pull/129)

### Stack interoperabilità completo + ricerca archivi

Sei nuovi plugin di interoperabilità + ricerca archivi avanzata:

- **OAI-PMH Server** plugin — provider OAI-PMH 2.0 sia per libri sia
  per archivi (Internet Culturale, Europeana, DPLA). Formati:
  `oai_dc`, `marc21`, `mods`, `mag 2.0.1`, `unimarc`.
- **NCIP 2.0 Server** plugin — protocollo NISO per scambio circolazione
  con kiosk self-service e partner ILS. Servizi: `LookupItem`,
  `LookupUser`, `CheckOutItem`, `CheckInItem`, `RenewItem`,
  `RequestItem`, `CancelRequestItem`.
- **BIBFRAME 2.0 Linked Data** plugin — content negotiation JSON-LD /
  Turtle per il catalogo libri.
- **OpenURL Resolver** plugin — Z39.88-2004 resolver + COinS embedded.
- **ResourceSync** plugin — protocollo Z39.99-2014 per sync bulk.
- **VIAF Authority Control** plugin — collegamento autori al VIAF/ISNI
  con confidence scoring e API W3C Reconciliation.

### Ricerca archivi (admin + pubblica)

- **Admin** (`/admin/archives?q=…&level=…`) — query a doppio passaggio:
  LIKE su `reference_code` (codici brevi sotto `ft_min_word_len`), poi
  FULLTEXT su title/scope/history. Filtro per livello, contatore
  risultati, persistenza input.
- **Pubblica** (`/archivio?q=…&level=…&date_from=…&date_to=…`) — stessi
  filtri + date-range overlap. In modalità ricerca tutti i livelli
  sono restituiti (non solo i fondi root).

### Interoperabilità archivi (Dublin Core, EAD3, OAI-PMH 2.0) — PR #127 mergiata

- **Dublin Core XML** — `GET /archives/{id}/dc.xml`
- **EAD3 Bulk Export** — `GET /admin/archives/export.ead3?ids=…`
- **OAI-PMH 2.0** — `GET/POST /archives/oai`

### Fix vari

- **PR #118** — verifica estensione PHP `zip` prima dell'installazione
  (errore con istruzioni `apt install php-zip` se mancante).
- **PR #119** — rimosso rate limit interno su `/import/chunk` e
  `/import/progress` per import CSV/TSV grandi.
- **PR #120** — ricerca unificata admin include unità archivistiche
  insieme a libri/autori/editori con badge di provenienza.

---

## v0.7.0 → v0.7.3 — 2026-05-01 → 2026-05-03

### Archives plugin (PR #103, #105) + VIAF/ISNI authority control

- **Archives plugin v1.0 → v1.2** ([#103](https://github.com/fabiodalez-dev/Pinakes/issues/103)) — modello gerarchico ISAD(G) a
  4 livelli (`fonds` → `series` → `file` → `item`), authority records
  ISAAR(CPF), link M:N con ruoli MARC-aligned, MARCXML import/export
  + XSD validation, SRU 1.2 endpoint, photographic items (ABA
  billedmarc 15 codici), unified search cross-entity libri/archivi/
  authority. Tutti i dettagli in [Archivi](/guida/archivi.md).
- **VIAF/ISNI columns su `autori`** — preparazione per il plugin
  viaf-authority. Colonne `viaf_id`, `viaf_uri`, `isni_id`, `isni_uri`,
  `authority_source`, `authority_confidence`. Tabella
  `author_authority_alternates` per identificatori alternativi.
- **Migration `migrate_0.7.0.sql`** — idempotente con
  INFORMATION_SCHEMA guards; rileva e ricrea `author_authority_alternates`
  da schema legacy con colonna `source_code`.

---

## v0.5.9.6 — 2026-05-02
**PR:** [#114](https://github.com/fabiodalez-dev/Pinakes/pull/114) / [#115](https://github.com/fabiodalez-dev/Pinakes/pull/115)

### Gerarchia Collane: Cicli, Stagioni, Spin-off

Le collane ora supportano un **albero self-referencing** per rappresentare
qualsiasi struttura multi-livello editoriale e audiovisiva.

**Struttura:**
- Ogni collana può avere una **collana padre** e collane figlie illimitate.
- Il campo `tipo` (ENUM: `serie`, `ciclo`, `stagione`, `spin_off`) descrive
  il tipo di relazione.
- Colonna `libri.collana_id` come FK diretta verso la collana foglia più
  specifica.
- Prevenzione cicli via ancestor-chain walk prima di ogni salvataggio.
- `CHECK` constraint sul campo tipo.

**Interfaccia:**
- Dropdown autocomplete per selezione collana padre nel form collana.
- Pagina pubblica collana con albero delle figlie e lista libri ordinata.
- Breadcrumb gerarchica automatica nelle schede libro.

**Operazioni:**
- Unione collane con trasferimento libri.
- Ridenominazione transazionale.
- Eliminazione solo se la collana è vuota e senza figlie.

**Migration:** `migrate_0.5.9.6.sql` — colonne `parent_id` e `tipo` su
`collane`, FK su `libri.collana_id`, CHECK constraint.

---

## v0.5.9.4 — 2026-04-22
**Commit:** `db2331a` — hotfix infrastruttura release

Root-cause fix per la catena 0.5.9 → 0.5.9.3 che aveva rilasciato
quattro pacchetti con solo 5 plugin su 10. Un workflow GitHub Actions
dimenticato (`release.yml`) gareggiava con `scripts/create-release.sh`
e sovrascriveva il ZIP uploadato usando uno script stale
(`bin/build-release.sh` con lista plugin hardcoded a 5 voci).

- **`.github/workflows/release.yml`** rinominato `.disabled` → la pipeline
  di release è ora un'unica strada: `scripts/create-release.sh`.
- **`bin/build-release.sh`** → enumera i plugin dal filesystem
  (`find storage/plugins/*/plugin.json`), skip di `scraping-pro`. Mai
  più drift tra lista hardcoded e plugin effettivi.
- **`scripts/create-release.sh` step 9.5** → verifica post-upload via
  GitHub API (non CDN): SHA + uploader identity + plugin count, polling
  90s. Se l'uploader risulta `github-actions[bot]` quando lo script
  gira in locale, abort immediato (segno di hijack da workflow).
- **`updater.md`** sezione «ABSOLUTE RULE — always verify the uploaded
  ZIP» con 5 lezioni esplicite.

## v0.5.9.3 — 2026-04-22
**Commit:** `204b5c1` — force updater re-run per installazioni bloccate

Bump di versione only — stesso payload di v0.5.9.2. Il ZIP remoto di
v0.5.9.2 era stato troncato (24.7 MB / 5 plugin invece di 26.7 MB / 10).
Ricaricato subito con quello corretto, ma gli utenti che l'avevano già
scaricato rotto avevano `version.json=0.5.9.2` che coincideva con il
`latest` su GitHub → updater non offriva più alcun aggiornamento.

- `post-install-patch.php` target_versions include `0.5.9.2`.
- `pre-update-patch.php` target_versions include `0.5.9.2` (riusa
  l'iterazione filesystem-based sull'Updater vecchio).

## v0.5.9.2 — 2026-04-22
**Commit:** `ab72faf` — self-heal per installazioni 0.5.9 bloccate su archives

Utenti in upgrade da v0.5.8 a v0.5.9 (primo caso: HansUwe52) vedevano
il plugin **Archives** nella lista `/admin/plugins` ma non riuscivano
ad attivarlo: la cartella `storage/plugins/archives/` non era stata
copiata sul disco. Causa: `Updater.php` della v0.5.8 iterava la SUA
`BundledPlugins::LIST` (5 voci, senza archives) anziché quella del ZIP.

- **`Updater::updateBundledPlugins()`** ora legge la lista dal file
  `BundledPlugins.php` del **pacchetto sorgente** (via `resolvePackageBundledPluginList`
  che fa regex-parse sicuro, niente `include`/eval). Fallback a
  `BundledPlugins::LIST` locale se il file del pacchetto è illeggibile.
- **`pre-update-patch.php`** (target: 0.5.4–0.5.9.1) riscrive la stessa
  iterazione nel vecchio Updater già installato, così l'upgrade in
  corso fa la cosa giusta anche prima che arrivi il nuovo Updater.
- **`post-install-patch.php` v1.1.0** → `target_versions` estesa a
  0.5.8/0.5.9/0.5.9.1, aggiunto `INSERT IGNORE` per `archives` nel
  seed DB.

## v0.5.9.1 — 2026-04-22
**Issue:** [#108](https://github.com/fabiodalez-dev/Pinakes/issues/108) — locale non ripristinato dal remember-me

Utenti con `utenti.locale` diverso dal default dell'installazione (es.
un utente `de_DE` su un'installazione `it_IT`) vedevano la UI nel
locale di default dopo l'auto-login. `I18n::setLocale()` filtra per
appartenenza alla tabella `languages`, seedata solo con il locale
scelto in installazione + fallback. Fix nell'installer: seed di tutti
e 3 i locale bundled.

- `installer/database/data_it_IT.sql` e `data_en_US.sql` seedano ora
  `it_IT` + `en_US` + `de_DE` (solo quello scelto ha `is_default=1`).
- `migrate_0.5.9.1.sql` → backfill idempotente per installazioni
  esistenti tramite `INSERT IGNORE` su `languages.code` (UNIQUE KEY).
- **Bonus fix post-v0.5.9 CodeRabbit:** `DiscogsPlugin::isCatalogNumber()`
  classificava ISBN-10 validi terminanti in `X` (`080442957X`) come
  Cat# Discogs, rischiando merge di metadati musicali in schede libro.
  Aggiunto guard `DiscogsPlugin::isIsbn10()` con MOD-11 checksum, +7
  regression asserts in `tests/discogs-catno.unit.php` (44/44 pass).

## v0.5.9 — 2026-04-22
**PR:** [#105](https://github.com/fabiodalez-dev/Pinakes/pull/105) + [#102](https://github.com/fabiodalez-dev/Pinakes/pull/102)
**Issues chiuse:** [#103](https://github.com/fabiodalez-dev/Pinakes/issues/103) (archives), [#101](https://github.com/fabiodalez-dev/Pinakes/issues/101) (Discogs Cat#)

Due feature major: nuovo plugin **Archives** (ISAD(G)/ISAAR(CPF)) + supporto
**Catalog Number** Discogs.

### Plugin Archives (ISAD(G) / ISAAR(CPF))

Gestione di materiale archivistico e fotografico secondo gli standard
internazionali [ISAD(G)](https://www.ica.org/en/isadg-general-international-standard-archival-description-second-edition)
(descrizioni gerarchiche) e [ISAAR(CPF)](https://www.ica.org/en/isaar-cpf-international-standard-archival-authority-record-corporate-bodies-persons-and-families-2nd)
(record di autorità). Phase 1-7:

- **Phase 1** — Schema DB (3 tabelle: `archival_units`, `authority_records`,
  `archival_unit_authority`), CRUD admin `/admin/archives`, sidebar, i18n
  IT/EN/DE, frontend pubblico `/archivio` con SEO slug + JSON-LD
  `ArchiveComponent`, upload cover + documento (PDF/ePub/MP3/video) con
  finfo MIME detection e path-prefix unlink guard.
- **Phase 2-3** — CRUD authority records con linkage M:N a
  `archival_units` e `libri.autori` + unified cross-entity search
  (libri+archives+authority in un unico risultato).
- **Phase 4** — Import/export MARCXML round-trip stable (identity
  test) + validazione contro XSD MARC21 Slim.
- **Phase 5** — Photographic items con ENUM `specific_material` che
  copre i codici ABA billedmarc / MARC21 008-33
  (`hb`/`hp`/`hm`/`hd`/`hk`/`bf`/`hf`/`lm`/`lf`/`vm`/`bm`/`le`/`zz`…).
- **Phase 6** — Endpoint SRU 1.2 per i record archivistici (interop
  bibliotecaria).
- **Phase 7** — Type-ahead JS per attach authority da admin form.
- Plugin **inattivo** per default (`metadata.optional: true`); attivalo
  da Admin → Plugin per creare lo schema.

### Discogs Catalog Number (Cat#)

`DiscogsPlugin::validateBarcode` accetta ora i Catalog Number stampati
su dischi/spine/label (`CDP 7912682`, `SRX-6272`, `DGC-24425-2`)
accanto a EAN-13 e UPC-A. `ScrapeController::byIsbn` preserva l'input
raw attraverso la catena hook `scrape.isbn.validate` → gli identificatori
non-numerici raggiungono il plugin. Chiude il caso "Bonnie Raitt — Nick
Of Time, Capitol CDP 7912682" riportato da HansUwe52.

### Migration

`migrate_0.5.9.sql` → 3 tabelle archives + indici. Idempotente via guard
`INFORMATION_SCHEMA` (pattern v0.4.7+).

## v0.5.8 — 2026-04-17
**Commit:** `8375477` — protect bundled plugins + surface silent failures

Hardening dell'updater: prevenzione orphan-plugin deletion per plugin
bundled mancanti temporaneamente (wait-for-files invece di DELETE),
severity error per fallimenti INSERT auto-register (prima warning
silenzioso, vedi lezione v0.5.4 bind_param swap).

## v0.5.7 — 2026-04-17
**Commit:** `bdaf5f3` + `0ff6129`

- Upload automatico di `pre-update-patch.php` e `post-install-patch.php`
  come asset di release in `create-release.sh` (prima erano manuali).
- Fix updater: `autoRegisterBundledPlugins()` chiamato al termine
  di `installUpdate()` → i plugin nuovi appaiono in `/admin/plugins`
  subito, senza attendere la request successiva.
- `deezer` e `musicbrainz` marcati `optional: true` nel plugin.json
  (prima erano auto-attivati ed emettevano warning di rete senza
  configurazione).

## v0.5.6 — 2026-04-17
**Commit:** `c150e6d` + `8b4e…`

Fix cascade Dewey 404s: aprendo il form admin di un libro con
`classificazione_dewey` più specifico del catalogo JSON (es. `305.42097`
quando il JSON si ferma a `305.4`) venivano scatenati 404 in cascata su
`/api/dewey/children`. Nessuna rottura funzionale, solo rumore nella
console. `DeweyApiController::getChildren` ritorna ora `200 []` per
codici non trovati (leaf semantics); `book_form.php navigateToCode()`
interrompe il loop quando `loadLevel()` ritorna null. Regression test
`tests/dewey-cascade-404.spec.js`.

## v0.5.5 — 2026-04-15
**PR:** [#100](https://github.com/fabiodalez-dev/Pinakes/pull/100)

Aggiunge il workflow di arricchimento massivo ISBN e 4 nuovi plugin
di scraping bundled.

### Bulk ISBN Enrichment

- **Nuova pagina admin** `/admin/libri/bulk-enrich` — arricchimento
  automatico di libri con copertine/descrizioni mancanti via ISBN/EAN.
- **Batch manuale** — 20 libri per click attraverso tutti i plugin di
  scraping attivi (Open Library, Google Books, Discogs, MusicBrainz,
  Deezer, Scraping Pro se installato). Rate limit: 1 request ogni 2
  minuti per rispetto delle API upstream.
- **Cron-driven** — `scripts/bulk-enrich-cron.php` con locking atomico
  `flock(LOCK_EX|LOCK_NB)`, exit non-zero su fatal.
- **No-overwrite** — solo campi NULL/vuoti; mai sovrascrive dati.
- **Empty-string safe** — `NULLIF(TRIM(col), '')` su `isbn13/isbn10/ean`.

### Nuovi plugin bundled

- **Discogs** — metadati musicali (CD, vinili, cassette) via UPC/EAN o
  ricerca testuale. 4 hook (`scrape.isbn.validate`, `scrape.sources`,
  `scrape.fetch.custom`, `scrape.data.modify`).
- **MusicBrainz** — metadati musicali fallback (barcode).
- **Deezer** — cover HD + tracklist per media audio.
- **GoodLib** — scraper a dominio custom (Anna's Archive, Z-Library,
  Project Gutenberg).

### Robustezza upgrade/install

- Fix `public/installer/assets` symlink → directory reale. Il vecchio
  symlink crashava l'upgrade manuale (`copy(): second argument cannot
  be a directory`) su installazioni dove la dir era stata materializzata.
- Release ZIP guard — `create-release.sh` rifiuta simlinks nel ZIP.
- Reinstall regression test — `scripts/reinstall-test.sh` +
  `tests/manual-upgrade-real.spec.js`: UI flow admin reale (upload ZIP
  → "Avvia" → `Updater::performUpdateFromFile`) invece di rsync.

### CodeRabbit hardening (16 fix Major)

`BulkEnrichController::start` logga via `SecureLogger` + 500 generico
(no leak raw exception); `toggle` usa `FILTER_VALIDATE_BOOL`;
`BulkEnrichmentService::setEnabled` ritorna bool; `enrichBook` verifica
`UPDATE execute()`; `ScrapeController::normalizeIsbnFields` distingue
validated-ISBN (via `IsbnFormatter::isValid`) da barcode-only per
evitare skip del backfill nei libri; toggle switch accessibile
(`aria-label` + `aria-labelledby`).

### i18n

168 nuove traduzioni in `en_US.json` + `de_DE.json` — tutte le stringhe
nuove nel branch sono localizzate. `it_IT.json` rimane minimale
(fallback-to-key).

### Migration

Nessuna nuova migration. Le modifiche DB sono già in `migrate_0.5.4.sql`.

---

## v0.5.4 — 2026-03-31 (in corso)
**PR:** [#100](https://github.com/fabiodalez-dev/Pinakes/pull/100) — Discogs music scraper, media types, dynamic labels

### Plugin Discogs, Tipi Media, Etichette Dinamiche

**Plugin Discogs (Multi-Sorgente):**
- Scraping musica da **Discogs API** (barcode + ricerca testuale)
- Fallback automatico su **MusicBrainz + Cover Art Archive**
- Arricchimento cover HD da **Deezer**
- Rate limiting per-API: 1s Discogs (auth) / 2.5s (anon), 1.1s MusicBrainz, 1s Deezer
- Mappatura completa: titolo, artista, etichetta, anno, tracklist, generi, formato, peso, prezzo, crediti
- Validazione barcode EAN-13 e UPC-A (hook `scrape.isbn.validate`)
- Pagina impostazioni per token opzionale Discogs

**Colonna `tipo_media`:**
- Nuova ENUM: `libro`, `disco`, `audiolibro`, `dvd`, `altro`
- Dropdown nel form admin con icone Font Awesome
- Colonna icona nella lista admin con filtro
- Auto-popolamento nella migrazione da valori `formato` esistenti
- Backward compatibility con guard `hasColumn()` ovunque
- Incluso in export CSV e import CSV/TSV/LibraryThing

**Etichette Dinamiche (MediaLabels):**
- Autore→Artista, Editore→Etichetta, Anno Pubblicazione→Anno di Uscita
- Numero Pagine→Tracce, ISBN→Barcode, Descrizione→Tracklist, Collana→Discografia
- Nomi formato leggibili: `cd_audio`→"CD Audio", `vinile`→"Vinile"
- Tracklist formattata come `<ol>` HTML ordinata

**Schema.org per Media:**
- `MusicAlbum` (byArtist, recordLabel, numTracks) per dischi
- `Movie` (director, productionCompany) per DVD
- `Audiobook` (readBy) per audiolibri
- `CreativeWork` generico per tipo "altro"
- `Book` (isbn, numberOfPages, bookEdition) come default

**Sicurezza (hardening curl):**
- `CURLOPT_PROTOCOLS` (HTTP/HTTPS only), `CURLOPT_MAXREDIRS`, `CURLOPT_CONNECTTIMEOUT`
- `CURLOPT_SSL_VERIFYPEER` su tutte le chiamate API
- Cast `releaseId` a int (prevenzione SSRF)
- `curl_error()` check su tutti i 4 siti di chiamata

**Migrazione:** `migrate_0.5.4.sql` — colonna `tipo_media`, indice composito, auto-populate

---

## v0.5.3 — 2026-03-28
**PR:** [#96](https://github.com/fabiodalez-dev/Pinakes/pull/96) — 4 P2 cross-version consistency findings + GoodLib plugin

### Consistency Fix, Plugin GoodLib, Sicurezza

**4 P2 Cross-Version Findings:**
- Fix `LT_COLUMN_MAP` export traduttori con roundtrip (#97)
- Fix locale login page che usava il locale errato
- Schema.org ISSN come `identifier/PropertyValue` (non campo diretto)
- Fix `ProfileController::update()` gestione fallimento `prepare()`

**Plugin GoodLib:**
- Nuovo plugin bundled per ricerca ISBN su Anna's Archive e Z-Library
- Link diretti a risorse esterne dalla pagina dettaglio libro
- Aggiunto a `Updater::BUNDLED_PLUGINS`

**10 Round di CodeRabbit Review:**
- 30+ fix di sicurezza, affidabilità e stabilità
- Guard `hasColumn()` rafforzati
- Miglioramenti i18n coverage

---

## v0.5.2 — 2026-03-22
**PR:** [#93](https://github.com/fabiodalez-dev/Pinakes/pull/93) — Normalize translator/illustrator/curator names

### Normalizzazione Nomi Traduttori/Illustratori/Curatori

- **Normalizzazione nomi** per traduttori, illustratori e curatori con `AuthorNormalizer`
- Stessa logica di normalizzazione usata per gli autori principali (inversione cognome/nome, capitalizzazione)
- Fix CodeRabbit review findings per #93

---

## v0.5.1 — 2026-03-20
**PR:** [#95](https://github.com/fabiodalez-dev/Pinakes/pull/95) — ISSN visibility, series links, multi-volume works (#75)

### ISSN, Gestione Collane, Opere Multi-Volume

**ISSN:**
- Nuovo campo ISSN nel form libro con validazione XXXX-XXXX (server + client)
- Visibile nel dettaglio frontend e nelle risposte API pubblica
- Proprietà `issn` nello Schema.org JSON-LD

**Gestione Collane (Serie):**
- Pagina admin `/admin/collane` — lista, crea, rinomina, unisci, elimina
- Pagina dettaglio collana con editor descrizione e autocomplete per unione
- Assegnazione massiva dalla lista libri
- Le collane vuote restano visibili nella lista

**Opere Multi-Volume:**
- Tabella `volumi` per collegare opere padre a singoli volumi
- UI admin per aggiungere/rimuovere volumi con ricerca
- Badge "Questo libro è il volume X dell'opera Y"
- Prevenzione cicli con ancestor-chain walk
- Creazione opera padre da pagina collana

**Import:**
- Parsing campo Series di LibraryThing (`"Nome ; Numero"` → collana + numero_serie)
- Stesso parsing per risultati scraping ISBN

**Bug Fix e Miglioramenti:**
- Validazione ISSN esplicita (era silenziosamente scartato)
- Transazioni su delete/rename/merge collane
- Guard soft-delete su addVolume e updateOptionals
- Resilienza migrazione parziale (`hasCollaneTable()`)
- Ordinamento volumi non-numerici dopo quelli numerici

**Migrazione:** `migrate_0.5.1.sql` — tabelle `volumi`, `collane`, indice `idx_collana`

---

## v0.5.0 — 2026-03-15
**PR:** [#92](https://github.com/fabiodalez-dev/Pinakes/pull/92) — Hreflang tags, RSS feed, sitemap events | [#91](https://github.com/fabiodalez-dev/Pinakes/pull/91) — Genre filter fix

### SEO & LLM Readiness, Schema.org, Campo Curatore

- **Hreflang alternate tags** su tutte le pagine frontend
- **RSS 2.0 feed** `/feed.xml` con gli ultimi 50 libri
- **Endpoint `/llms.txt`** dinamico (attivabile da admin)
- **Schema.org enrichment** — `sameAs` (OpenLibrary, Google Books, WorldCat), tutti i ruoli autore, `bookEdition`, `Offer` condizionale, `editor` per curatore
- **Campo `curatore`** — colonna DB, form libro, dettaglio admin, Schema.org
- **Fix CSV column shift (#83)** e **admin genre display (#90)**

**Migrazione:** `migrate_0.5.0.sql` — colonna `curatore`, `issn`, impostazioni RSS e llms.txt

---

## v0.4.9.9 — 2026-03-12

### Condivisione Social, Navigazione Generi, PDF Inline

- **7 provider sharing** — Facebook, X, WhatsApp, Telegram, LinkedIn, Reddit, Pinterest + Email, Copia Link, Web Share API
- **Navigazione breadcrumb generi** — link cliccabili nella gerarchia
- **Viewer PDF inline** — `<iframe>` nativo (Digital Library plugin v1.3.0)
- **Ricerca nella descrizione** — nuova colonna `descrizione_plain` per ricerca senza HTML
- **Auto-registrazione hook plugin** al caricamento pagina

**Migrazione:** `migrate_0.4.9.9.sql` — colonna `descrizione_plain`, impostazioni social sharing

---

## v0.4.9.8 — 2026-03-08
**PR:** [#88](https://github.com/fabiodalez-dev/Pinakes/pull/88) — Address 19 codebase review findings

### Normalizzazione Traduttori/Illustratori, Bug Fix

- **Normalizzazione nomi** traduttori, illustratori, curatori con `AuthorNormalizer`
- **19 finding di codebase review** corretti (sicurezza, affidabilità, stabilità test)
- Script di upgrade manuale `manual-upgrade.php`

---

## v0.4.9.7 — 2026-03-03

### Re-release per Plugin Bundled

- Re-release di v0.4.9.6 per garantire che gli aggiornamenti dei plugin bundled si propaghino alle installazioni che hanno aggiornato da versioni pre-0.4.9.6

---

## v0.4.9.6 — 2026-03-03
**PR:** [#82](https://github.com/fabiodalez-dev/Pinakes/pull/82) — Comprehensive codebase review

### Review Completa del Codice

- Validazione schema URL, HTTPS proxy-aware nell'installer
- Limite 72 byte bcrypt, RateLimiter atomico con flock
- Guard su `recalculateBookAvailability` e `RELEASE_LOCK`
- Config charset in `SET NAMES`

---

## v0.4.9.4 — 2026-03-02
**PR:** [#69](https://github.com/fabiodalez-dev/Pinakes/pull/69) — German locale, manual upgrade script

### Audiolibri, Z39.50 Nordic, Scorciatoie Tastiera

- **Player MP3 audiolibri**
- **Sorgenti Z39.50/SRU nordiche** (Danimarca, Norvegia, Svezia)
- **Scorciatoie tastiera globali** (Ctrl+K ricerca, Ctrl+N nuovo libro)
- **Scroll-to-top button**
- **Supporto installer tedesco**

---

## v0.4.9.2 — 2026-02-24
**PR:** [#68](https://github.com/fabiodalez-dev/Pinakes/pull/68) — Genre merge/rearrange, book list filters

### Gestione Generi, Filtri Lista Libri

- **Modifica/unione/riorganizzazione generi**
- **Filtro collana (serie)** nella lista libri admin con autocomplete
- **Supporto locale tedesco** completo
- Fix installer, TinyMCE, upload PDF/ePub

---

## v0.4.9.1 — 2026-02-24
**PR:** [#55](https://github.com/fabiodalez-dev/Pinakes/pull/55) — Full subfolder installation support

### Supporto Sottocartella, Sicurezza

- **Installazione in sottocartella** — funziona con base path custom
- **Homepage sort configurabile**
- **Hardening sicurezza** — 177 file modificati
- **Traduzione route** — sistema `RouteTranslator`

---

## v0.4.8.2 — 2026-02-12
**PR:** [#50](https://github.com/fabiodalez-dev/Pinakes/pull/50) — PDF loan receipts, pending loans fixes

### Illustratore, Espansione Lingua

- **Campo illustratore** nel form e nei metadati
- **Lingua espansa** a VARCHAR(255) con normalizzazione nomi nativi
- **Anno pubblicazione signed** (supporto BCE)
- Fix sessione installer, numeri inventario CSV duplicati

**Migrazione:** `migrate_0.4.8.2.sql`
