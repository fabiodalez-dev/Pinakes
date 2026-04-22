# Archives (ISAD(G) / ISAAR(CPF))

The **Archives** plugin manages archival and photographic material
alongside the bibliographic catalog. It follows the international
standards published by the International Council on Archives:

- **[ISAD(G)](https://www.ica.org/en/isadg-general-international-standard-archival-description-second-edition)** — *General International Standard Archival Description*, for hierarchical description of archival material;
- **[ISAAR(CPF)](https://www.ica.org/en/isaar-cpf-international-standard-archival-authority-record-corporate-bodies-persons-and-families-2nd)** — *International Standard Archival Authority Record for Corporate bodies, Persons and Families*, for authority records.

The plugin ships **inactive** (opt-in). Enable it from **Administration →
Plugins** — the first activation creates the three tables
(`archival_units`, `authority_records`, `archival_unit_authority`).

> Introduced in v0.5.9 — tracks issue [#103](https://github.com/fabiodalez-dev/Pinakes/issues/103).

## Data model

Three tables with M:N and self-reference:

```
archival_units (ISAD(G) tree, 4 levels)
  ├── id, parent_id                                      ← hierarchy
  ├── level: fonds | series | file | item                ← ISAD(G) 3.1.4
  ├── reference_code, title, creator, extent             ← 3.1.1–3.1.5
  ├── date_range_start, date_range_end
  ├── scope_content, admin_biog_history, custodial_history
  ├── access_conditions, reproduction_conditions
  ├── language_of_material, physical_characteristics
  ├── specific_material (ENUM — see "Photographs")       ← Phase 5
  ├── cover_image_path, document_path, document_mime     ← uploads
  └── deleted_at                                         ← soft delete

authority_records (ISAAR(CPF))
  ├── id
  ├── type: person | corporate_body | family             ← ISAAR 5.1.2
  ├── authorised_form_of_name, parallel_forms            ← 5.1.3/5.1.5
  ├── dates_of_existence, history, places, legal_status  ← 5.2.x
  ├── functions_occupations, mandates, internal_structure
  ├── general_context, maintenance_notes
  └── deleted_at

archival_unit_authority (M:N link)
  ├── archival_unit_id → archival_units.id
  ├── authority_id     → authority_records.id
  └── role: creator | subject | custodian | recipient
```

## Basic workflow

### 1. Create a fonds

**Administration → Archives → New record**:

1. Pick level `fonds` (highest level).
2. Fill Reference Code (e.g. `IT-FMC-01`), Title, Creator, Extent
   (e.g. "12 boxes, 480 files").
3. Date Range if known.
4. Save.

### 2. Add series, files, items

From the fonds detail page, click **Add child**. The child inherits
the Reference Code as prefix (e.g. `IT-FMC-01/001`). You can nest up
to 4 levels: `fonds` → `series` → `file` → `item`.

### 3. Link authority records

The unit detail page has an **Authority records** section: a JS
type-ahead (live-search) finds an existing record, or "Create new"
opens the ISAAR form inline. Pick the role (`creator`, `subject`,
`custodian`, `recipient`) and save.

Those authority records are read by the bibliographic catalog too
(`libri.autori`) — it's a unified authority file across all of
Pinakes, not one per module.

### 4. Upload cover and document

For units with digitised material:

- **Cover**: JPEG/PNG/WebP, shown in the public card grid.
- **Document**: PDF/ePub/MP3/MP4/MKV, exposed publicly at
  `/archivio/<slug>`. MIME detection via `finfo_file` (no trust on
  `$_FILES['type']`) with unlink guard constraining the path to
  `public/uploads/archives/` (no path traversal).

## Cross-entity search

The unified search bar (admin header + `/api/search` endpoint) queries
`libri`, `archival_units` and `authority_records` at the same time and
renders each hit with its provenance label ("Book", "Archive · Series",
"Authority · Person"). Useful for finding a name that shows up both as
book author and as fonds creator.

## Photographic items (Phase 5)

The `specific_material` column classifies photographic and audio-visual
material using MARC21 008-33 / ABA billedmarc codes:

| Code | Material |
|:---:|---|
| `hb` | Photograph |
| `hp` | Photograph, positive print |
| `hm` | Photograph, collodion print |
| `hd` | Slide |
| `hk` | Postcard |
| `bf` | Drawing |
| `hf` | Painting |
| `lm` | Map |
| `lf` | Plan |
| `vm` | Videotape / film |
| `bm` | Sound recording |
| `le` | Three-dimensional object |
| `zz` | Not applicable |

The dropdown is multi-select: a photographic fonds can mix positives,
slides, negatives.

## MARCXML Import/Export (Phase 4)

### Export

From the fonds toolbar: **Export → MARCXML**. Pinakes generates a
MARC21 Slim document compliant with the [official XSD](https://www.loc.gov/standards/marcxml/schema/MARC21slim.xsd),
validated server-side before download. The ISAD → MARC mapping follows
the ABA (Arbejderbevægelsens Bibliotek og Arkiv, Copenhagen) crosswalk.

### Import

**Archives → Import → MARCXML**. Pinakes validates against the same
XSD, then runs a transactional import that creates the hierarchical
structure from the `773`/`787` fields (host/related).

### Round-trip

The identity test verifies: `export → import → re-export` yields
byte-identical output. This guarantees no information loss through the
interchange format.

## SRU endpoint (Phase 6)

The plugin exposes an SRU 1.2 endpoint for archival records too:

```
https://your-domain.tld/api/sru/archives?operation=searchRetrieve&version=1.2&query=dc.creator=%22Mocenigo%22
```

Interoperable with external OPACs, Z39.50 gateways, and federated
search. Records are returned in MARCXML, Dublin Core, or MODS
(`recordSchema=marcxml|dc|mods`).

## Activation and schema

```
Administration → Plugins → Archives (ISAD(G) / ISAAR(CPF)) → Enable
```

First activation runs `ensureSchema()`: creates the 3 tables via
idempotent migration. Disabling the plugin does NOT drop the data — just
re-enable to pick up where you left off.

## Known limitations

- No visual tree editor for the hierarchy yet (only form + structured
  reference_code). Roadmap Phase 8.
- MARCXML import does not materialise cross-fonds relations
  automatically — they must be re-linked manually.
- Unified search indexes on-query (no precomputed FULLTEXT); on
  datasets >100k records ranking may slow down. Roadmap Phase 9.
