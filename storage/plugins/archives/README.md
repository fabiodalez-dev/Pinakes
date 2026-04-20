# Archives plugin — ISAD(G) / ISAAR(CPF) support for Pinakes

**Status: PHASE 2 — authority records CRUD + linking** (v0.5.0). Plus everything from phase 1 (schema + archival_units CRUD + sidebar + i18n + E2E). Now also ships: full ISAAR(CPF) authority-records CRUD at `/admin/archives/authorities`, plus an M:N link UI on the archival-unit detail page to attach/detach authorities with a role enum (creator/subject/recipient/custodian/associated). Still missing: linkage to the legacy `libri.autori` column, MARCXML I/O, unified cross-entity search, biographical ISAAR fields beyond the phase-2 minimum.

Tracks issue [#103](https://github.com/fabiodalez-dev/Pinakes/issues/103).

## Why this plugin

Pinakes today models a flat bibliographic record (`libri` — ISBN/EAN/Dewey). Institutions that hold **archival material** (fonds, series, files, items) and **photographic collections** need a different, hierarchical model — standardised internationally as [ISAD(G)](https://www.ica.org/en/isadg-general-international-standard-archival-description-second-edition) for descriptions and [ISAAR(CPF)](https://www.ica.org/en/isaar-cpf-international-standard-archival-authority-record-corporate-bodies-persons-and-families-2nd) for authority records (persons / corporate bodies / families).

The canonical real-world mapping of ISAD(G) onto a MARC-like serialisation comes from the [ABA archive format](https://github.com/fabiodalez-dev/Pinakes/issues/103) (Arbejderbevægelsens Bibliotek og Arkiv, Copenhagen) — designed by Hans Uwe Petersen and Reindex, drawing inspiration from the Swedish ARKIS II. This plugin adopts the same field crosswalk so future MARCXML import/export is a mechanical translation.

## Data model (skeleton)

Three tables (DDL exposed via `ArchivesPlugin::ddl*()` methods and executed by `ensureSchema()` when `onActivate()` fires — review before enabling the plugin):

### `archival_units` (hierarchical)

Self-referencing tree; each row is a record at one of four ISAD(G) levels:

| Level | `level` value | ISAD term | Example |
|---|---|---|---|
| 1 | `fonds` | Fonds / Archive collection | "Dansk Metalarbejderforbund Arkiv" (1884-2003) |
| 2 | `series` | Archive series | "Cirkulærer og skrivelser" (1895-1986) |
| 3 | `file` | Archive file | "Udsendte cirkulærer" |
| 4 | `item` | Archive item | "Cirkulær 1910-07-15, J. Jensen to branches" |

Column → ISAD(G) crosswalk (selected):

| Column | ISAD(G) | ABA MARC |
|---|---|---|
| `reference_code` | 3.1.1 Reference code | `001*a` |
| `institution_code` | 3.1.1 Reference code | `001*b` |
| `formal_title` | 3.1.2 Title (formal) | `241*a` |
| `constructed_title` | 3.1.2 Title (constructed) | `245*a` |
| `date_start` / `date_end` | 3.1.3 Dates of creation | `008*a` / `008*z`, `260*c` |
| `predominant_dates` | 3.1.3 — Predominant dates | `501*a` |
| `date_gaps` | 3.1.3 — Significant gaps | `501*b` |
| `level` | 3.1.4 Level of description | `008*c` |
| `extent` | 3.1.5 Extent and medium | `300*a`, `300*n` |
| `scope_content` | 3.3.1 Scope and content | `504*a` |
| `appraisal` | 3.3.2 Appraisal, destruction | `521*a`, `521*b` |
| `accruals` | 3.3.3 Accruals | `521*d` |
| `arrangement_system` | 3.3.4 System of arrangement | `512*c` |
| `access_conditions` | 3.4.1 Conditions governing access | `513*a`, `518*a` |
| `reproduction_rules` | 3.4.2 Conditions governing reproduction | `518*b` |
| `language_codes` | 3.4.3 Language / scripts | `008*l`, `041*a` |
| `finding_aids` | 3.4.5 Finding aids | `526*a` |
| `originals_location` | 3.5.1 Existence of originals | `529*a` |
| `copies_location` | 3.5.2 Existence of copies | `529*b`, `529*c` |
| `related_units` | 3.5.3 Related units | `525*a` |
| `archival_history` | 3.2.3 Archival history | `520*a` |
| `acquisition_source` | 3.2.4 Immediate source of acquisition | `520*b` |
| `registration_date` | 3.7.3 Date(s) of descriptions | `512*a` |

Soft-delete (`deleted_at`) aligned with Pinakes' `libri` convention.

### `authority_records` (ISAAR(CPF))

Separate from the existing `autori` table because ISAAR covers **persons + corporate bodies + families** and carries a richer element set. Authority records are shared between `libri` and `archival_units` via the link table.

| Column | ISAAR(CPF) |
|---|---|
| `type` | 5.1.1 Type of entity (`person`/`corporate`/`family`) |
| `authorised_form` | 5.1.2 Authorised form of name |
| `parallel_forms` | 5.1.3 Parallel forms |
| `other_forms` | 5.1.5 Other forms |
| `identifiers` | 5.1.6 Identifiers |
| `dates_of_existence` | 5.2.1 Dates of existence |
| `history` | 5.2.2 History |
| `places` | 5.2.3 Places |
| `legal_status` | 5.2.4 Legal status |
| `functions` | 5.2.5 Functions, occupations, activities |
| `mandates` | 5.2.6 Mandates / sources of authority |
| `internal_structure` | 5.2.7 Internal structure / genealogy |
| `general_context` | 5.2.8 General context |

### `archival_unit_authority` (link table)

M:N relation with a `role` enum — `creator` / `subject` / `recipient` / `custodian` / `associated`. Mirrors MARC 100/600/700/710 semantics in the ABA format.

## Roadmap

| Phase | Scope | Est. effort |
|---|---|---|
| **1 — MVP archives CRUD** | Execute the DDL, basic CRUD for `archival_units`, tree-view frontend, 10 core ISAD(G) fields | 2-3 weeks |
| **2 — Authority records** | `authority_records` CRUD, linkage to both archival_units and libri.autori, biographical UI | 1-2 weeks |
| **3 — Unified search** | Cross-entity index (libri + archival_units + authority_records), unified results page | 1 week |
| **4 — MARCXML I/O** | Import (ABA format + generic MARC21-archive), export, optional Z39.50 server | 2 weeks |
| **5 — Photographic items** | Extend archival_units with image-specific fields (from ABA billedmarc); thumbnails, EXIF | 1-2 weeks |

## Planned hooks

Currently data-only (see `ArchivesPlugin::plannedHooks()`):

| Hook | Purpose |
|---|---|
| `search.unified.sources` | Contribute archival_units + authority_records to unified search results |
| `admin.menu.render` | Add "Archivi" entry to the admin sidebar |
| `libri.authority.resolve` | Share authority_records with the existing `libri.autori` column |

## References

- ICA — [ISAD(G) 2nd ed.](https://www.ica.org/en/isadg-general-international-standard-archival-description-second-edition)
- ICA — [ISAAR(CPF) 2nd ed.](https://www.ica.org/en/isaar-cpf-international-standard-archival-authority-record-corporate-bodies-persons-and-families-2nd)
- ABA archive format (Hans Uwe Petersen, 2006) — see PDFs attached to issue #103
- ARKIS II (Swedish National Archives)
- [danMARC2](https://www.kat-format.dk/danMARC2) — Danish library MARC variant
