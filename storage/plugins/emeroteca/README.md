# Emeroteca 1.7

Emeroteca supports two workflows. **Simple** starts with standalone articles. **Complete** also exposes the existing mastheads, volume years, issues, Kardex and subscriptions. The same records remain available in either mode. Change the shared setting under **Plugins → Emeroteca → Settings**, or on the Periodicals/Articles page. Only administrators may change the installation-wide setting.

The initial workflow is the administrator's choice, made from the Periodicals page or the plugin settings: nothing sets it for a new collection, and the plugin behaves as Complete until it is chosen, so nothing is hidden meanwhile. Upgrades keep Complete for an installation that already has a collection, and never overwrite a choice already saved. Deactivation keeps the tables and documents.

## Catalogue one article

Choose **Add article**, enter its title and any known citation information, and save. No masthead, year or issue is created. Publication dates can remain textual (for example “June 2019”); volume, issue and page spans accept textual values such as “4–5” and “iv–x”. ISSN checksums and DOI syntax are validated. Only the title is required.

Articles are private initially. Publishing the article and allowing public access to its PDF are separate choices. Uploads accept PDF files up to 25 MB and are stored outside the public directory, under `storage/uploads/plugins/emeroteca/contributi`, so existing full backups include them. Downloads pass through the visibility check on every request and are not cacheable. Private notes and shelf marks are never returned by public pages or the mobile API.

The existing **Issue article indexes** remain available from the Articles list. They are edited on their issue, while standalone articles have independent IDs and permanent detail URLs. No conversion or duplication occurs when switching views.

## The analytic record

A standalone article is, in library terms, an **analytic** (component-part) record: it describes a piece *of* something the library may not hold at all. Everything needed to say so properly is on the article form, and **all of it is optional** — a collection that only wants a citation fills in the title and stops there.

Under **Advanced bibliographic description** the form carries the subtitle, the language and country of publication, a classification, and a holdings note. The classification is stored as a **scheme plus a value** — `DK5` and `33.129`, or `DDC` and `853.92`, or `UDC`, `LCC`, `RVK`. In the MARCXML export a DDC notation goes to 082, UDC to 080 and LCC to 050, the fields MARC 21 reserves for them; every other scheme (DK5, RVK, …) goes to 084 `$a` with the scheme in `$2`. No scheme is privileged: a notation without the name of the list it comes from cannot be read by anyone who does not already know which list was meant.

Language and country are stored as **ISO codes** (639 for the language, 3166-1 alpha-2 for the country), not as names, and the page renders them in the reader's own language: `dan` reads as “Danish” to one visitor and “danese” to another. A language *name* typed into the box would be that name for everybody. Two-letter and three-letter language codes are both accepted and stored as entered; the MARCXML export converts them to the MARC language code (`de`/`deu` → `ger`, `fr`/`fra` → `fre`, `da` → `dan`).

Under **Electronic resource** the form carries the danMARC2/MARC 21 **856** triple: the address, the link text (`$y`) and the access conditions (`$z`), with its own visibility switch. An `http`/`https` address becomes a link; **anything else** — a UNC share, a `file:` URI, an identifier in a document management system — is kept as written and shown as text, never as something a browser is invited to follow. A record may have both an uploaded PDF and an external address: the PDF is always the primary action, because it is the copy the library actually holds, and the external address becomes secondary. There is never more than one primary action on the page.

## Citing an article

The public page renders the citation in **APA 7**, **Harvard**, **MLA**, **Chicago** and **Oxford (Umeå)** through the shared Cite dialog, each with a copy button, and offers the record as **RIS** for EndNote, Mendeley and Zotero at `/emeroteca/articolo/{id}/citazione.ris`. The same file is available in the admin form for an article that is not published, at `/admin/periodicals/articles/{id}/citation.ris`.

The citation is assembled from the record rather than retyped, so a corrected volume number corrects the bibliography too. A name written inverted (`Petersen, Hans Uwe`) is reduced to initials; a name with no comma is treated as corporate and used verbatim, because guessing which word of `Marc J. Schweissinger` is the surname is wrong often enough, and invisibly enough, not to guess. With no author the title takes the author slot, as the selected style prescribes; with no year the citation says `n.d.` rather than inventing one, though a year written only in a free-text date (`June 2019`) is still found.

RIS lines end CR LF and no value may contain a line break — an abstract pasted out of a PDF is collapsed to one line, because in RIS a break starts a new tag and would truncate the record at its first paragraph.

With the **OpenURL Z39.88** plugin active, the article page also carries COinS metadata, so Zotero and Mendeley can import the record straight from the page, and `/openurl` resolves an incoming journal request to a local article by DOI or exact title before falling back to an external resolver.

## Finding an article, and moving between articles

Published standalone articles share the main catalogue grid with books, including its total, sorting and pagination. The same results are returned by the live-search API. Article cards link to their own records and are labelled as articles, never as unavailable books. Loan availability, book genre, publisher and book media-type filters exclude articles because those facets describe book holdings. Mastheads and issue-owned index entries retain their separate discovery links.

Author links on article pages and lists open the shared catalogue author filter. It searches both the book author registry and complete semicolon-separated article credits, accepting `Surname, Given name` and `Given name Surname`. Existing author-ID filters and author pages also include matching published articles. This is name-based retrieval, not an authority merge: homonyms are not silently merged or new author records created. Publication and keyword links still narrow the article search. Separate co-authors with a **semicolon**, for example `Schweissinger, Marc J.; Bianchi, Anna`; the comma stays inside a person's name.

The OpenURL resolver accepts both the main title and the complete `title : subtitle` exported by COinS. Host title, ISSN, volume and issue disambiguate title matches; an ambiguous match falls back to the external resolver. Disabled Emeroteca content is excluded from both the shared catalogue and the resolver.

Each article can carry its own image, uploaded on the article form (JPG, PNG or WebP, up to 5 MB) and stored under `public/uploads/emeroteca` like the issue and masthead images. An article without one shows the cover of the issue it was placed in, then the masthead's logo, and only when there is neither the placeholder the catalogue uses for a book without a cover. Replacing or removing the image deletes the previous file once no other record refers to it, and never before the new row has been saved.

## Public pages

The public section is built from the same pieces as the catalogue and the book page (`app/Views/frontend/partials`, `public/assets/catalog-pages.css`, `public/assets/book-detail.css`), so it follows the active layout like the rest of the site.

- `/emeroteca` — the mastheads, with filters for type, publisher, subject and initial letter, 20 per page, then the latest articles (or, while searching, the articles matching the search).
- `/emeroteca/{id}` — a masthead: its years in the sidebar, the selected year's issues, and its articles, searchable and 20 per page.
- `/emeroteca/fascicolo/{id}` — an issue: the published articles placed in it, in page order, then its printed table of contents; previous and next issue of the same year.
- `/emeroteca/articolo/{id}` — an article: breadcrumb Home › Emeroteca › masthead › issue, where it was published, previous and next article of the same issue, details, citations, and related articles of the same masthead and the same linked author.
- `/emeroteca/articoli` — the article search, with `?testata=`, `?fascicolo=`, `?autore=`, `?pubblicazione=` and `?keyword=`.

Filtered or searched listings are served `noindex,follow`; later pages of a listing canonicalise to themselves.

## Create the publication later

Select standalone articles, open **Associate selected articles with a publication**, then select a publication or enter the name of a new one. Review the preview and confirm. You can also start from an existing masthead's edit/issue page using **Add existing articles**.

Associating articles does not declare ownership of any issue. An optional existing issue must belong to the selected publication. A bulk association is atomic; a changed or deleted selection fails instead of overwriting another operator's work. Reassigning articles already associated with another publication requires an explicit checkbox in the preview.

Removing a publication or issue preserves standalone citations and PDFs. Removing an issue leaves its masthead association intact. Masthead merges repoint article associations. To detach an article, select “No publication” and explicitly confirm reassignment. Article indexes owned by an issue retain their previous cascade-deletion policy.

## CSV

Use **Import articles** in Emeroteca or the link from the book import page. Download the empty template first. Files must be UTF-8, comma-separated, at most 5 MB and 500 records. The preview lists validation errors, updates and possible duplicates; committing imports only valid rows and reports each result.

`reference_key` is the stable import identity. The export supplies it for every article. A known key updates that record; a missing key creates a new identity. Omitted columns preserve existing values, while empty cells clear the corresponding value. Title cannot be empty. Updating from an expired revision is rejected, including if another operator edits a record after preview.

The supported columns are:

```text
record_type,reference_key,titolo,sottotitolo,autori,tipo_contributo,contenitore_tipo,contenitore_titolo,issn,data_pubblicazione_testo,anno_pubblicazione,volume,numero,pagine,doi,supporto,keywords,abstract,lingua,paese,classificazione_schema,classificazione,nota_possesso,risorsa_url,risorsa_testo,risorsa_accesso,risorsa_pubblica,collocazione,note_private,pubblico,contenitore_curatori,contenitore_editore,contenitore_luogo,isbn
```

A filled row, for reference:

```text
record_type,titolo,autori,contenitore_titolo,anno_pubblicazione,volume,numero,pagine
journal_article,Intertextuality in Daniel Kehlmann's Novel Tyll,"Schweissinger, Marc J.",International Journal of Language and Literature,2019,7,1,138-148
```

`journal_article`, `newspaper_article` and `book_chapter` also set the publication type, so `contenitore_tipo` can be left out.

A `book_chapter` row is a chapter of an anthology (`contenitore_tipo` `antologia`): `contenitore_titolo` holds the title of the volume, and four columns describe that volume — `contenitore_curatori` (its editors, separated by a semicolon like `autori`), `contenitore_editore` (publisher), `contenitore_luogo` (place of publication) and `isbn` (the volume's ISBN-10 or ISBN-13). These four are kept only for chapters: on any other publication type they are cleared on save. Columns you omit keep whatever the record already holds; an empty cell clears it.

`autori` (alias `authors`) is free text and is stored exactly as supplied. Several authors are separated by a semicolon; the comma belongs to the name, which is why the row above quotes `"Schweissinger, Marc J."` as one author.

Accepted aliases include `title`, `authors`, `container_title`, `journal_title`, `date`, `year`, `issue`, and `pages`. Header case and separators do not matter. `record_type` (alias `media_type`) accepts `article`, `articolo`, `journal_article`, `newspaper_article`, or `book_chapter`; it leads the template and the export because it is also what lets the book importer refuse a file of articles — omit it and that guard has nothing to read. The last three also identify the publication type. Unknown types or columns are reported instead of discarded. Article records sent to the book importer are explicitly rejected with directions to Emeroteca.

Exports exceeding 500 rows or 5 MB download as a ZIP of numbered CSV files. Extract and import each CSV separately; every part includes its header and fits the same import limits. Duplicate citations and DOI values are checked within the batch and again at commit, with imports serialized per database. Citation matching includes the publication year and textual date, so recurring columns in different issues remain distinct; a matching DOI still identifies a duplicate. Exported data cells escape spreadsheet formula prefixes and leading apostrophes with an additional apostrophe, which the article importer removes on reimport to preserve the original text.

The export is a machine round-trip format: spreadsheet applications should import all columns as text. PDFs are uploaded separately; import never downloads arbitrary remote URLs. Associations to local mastheads can be applied in bulk after import. KBART/ACNP continue to describe actual serial holdings and do not count standalone articles as held issues.

## Mobile API

When both Emeroteca and Mobile API are active:

- `GET /api/v1/periodicals/health` advertises `capabilities.standalone_articles`.
- `GET /api/v1/periodicals/articles` lists public standalone articles with `q`, `testata_id`, `limit` (maximum 50) and `cursor` filters. Use `meta.next_cursor` for the next page.
- `GET /api/v1/periodicals/articles/{id}` returns a public article or 404. Private records are never exposed.

The analytic fields travel with the record: `sottotitolo`, `lingua`, `paese`, `classificazione_schema`, `classificazione` and `nota_possesso`. `has_public_resource` says whether the article carries a published electronic resource; when it does, `risorsa_url`, `risorsa_testo` and `risorsa_accesso` accompany it. When it does not, **those three keys are absent rather than null** — a null address in a payload still tells a client that one exists. Shelf marks and private notes remain out of the payload, as before.

`cover_url` supplies the absolute URL of the article image, or null when it has none. The routes use the existing bearer authentication, quota and response envelope. Lists and details support ETag/304. `kind` is `autonomo`; `has_public_pdf` indicates a public document. `pdf_url` supplies its absolute public streaming URL (including the installation subdirectory), or null when unavailable. Clients must not construct storage paths. Existing issue API responses are unchanged. Android must implement the new endpoints to display standalone articles; its previous issue browser continues to work.

## Upgrade and verification

Version 1.7 adds ten columns to `emeroteca_contributi` — `sottotitolo`, `lingua`, `paese`, `classificazione_schema`, `classificazione`, `nota_possesso`, `risorsa_url`, `risorsa_testo`, `risorsa_accesso` and `risorsa_pubblica` — through the same idempotent additive-column repair as every column before them: no migration file is involved, and an installation upgraded from 1.6 gets them on the first boot after the update. Nine are nullable and the tenth, the resource visibility flag, arrives as hidden, so every article catalogued before the upgrade keeps working exactly as it did and shows none of the new apparatus until someone fills it in.

The new fragments are appended after `updated_at` and carry no `AFTER` clause. That is not a style choice: the same fragments are interpolated into `CREATE TABLE` by `ContributionService::ddl()`, where `AFTER` is a syntax error, and an `ALTER` without it appends — so a fresh install and an upgraded one end with one column order. `tests/emeroteca-412.unit.php` asserts both.

Version 1.6 added one nullable column, `emeroteca_contributi.copertina_url`, the same way. Articles catalogued before that upgrade keep working with no image.

Pinakes 0.7.84 includes `migrate_0.7.84.sql`, which preserves the workflow for existing plugin registrations. The plugin owns the table creation and repair in `ensureSchema()`, called at installation/activation and by the bundled-plugin schema recovery mechanism. The migration does not alter `libri.tipo_media` or existing holdings.

Tests: `tests/emeroteca-412.unit.php` (real services, disposable MySQL tables), `tests/migration-0.7.84.unit.php` (migration-gate entry point), `tests/emeroteca-412.spec.js` (browser workflow), and `tests/emeroteca-412-upgrade.spec.js` (dedicated disposable fresh/upgrade instance). Existing Emeroteca admin, integration, export, interoperability and full application regression suites remain applicable.

Choosing **Publication only** for an already associated article removes its issue link while retaining the masthead. Because a bulk selection can hold up to 500 articles, the preview asks to confirm that removal explicitly, the same way it asks before reassigning articles that already belong to another masthead; without the confirmation nothing is written. Repeating the same complete destination is idempotent.

### Article workflow and MARCXML (#412)

The book management page offers **Add article** beside **New book** (desktop and mobile) when Emeroteca is active. The existing article form captures authors, title/subtitle, pagination and host metadata without requiring an owned journal. Existing articles can be associated with a local masthead from the Articles list.

Article pages and the admin edit form now offer a MARCXML download. It exports a monographic component (`Leader/07=a`, as defined by the Library of Congress for an individual article), 100/700 (the credit in citation form, `Surname, Forename`), 245, 300 and 773 `$t/$x/$g`; an associated masthead adds `$w=periodical:{id}`, matching the 001 of its record in the local SRU export. A chapter in an anthology (publication type *Anthology*, 1.10.0) has a book as its host: 773 carries the volume's imprint in `$d` (`Place : Publisher, Year`) and its ISBN in `$z` instead of an ISSN in `$x`; its citations use the chapter forms of APA 7 and Harvard, RIS exports it as `CHAP` with the editors as `A2`, and its COinS is an OpenURL book item. Variable fields are written in ascending tag order. 008 carries the date entered on file, a known year (or "no dates") and the MARC language; 041 repeats the language. The country is exported as its ISO 3166 code in 044 `$c`, while 008/15-17 remains unspecified, because ISO 3166 country codes are not MARC country codes. It also carries classification (see above), keywords, abstract and DOI. The holdings note is exported in 852 `$z`; the shelf mark (852 `$c`) appears only in the admin export. The catalogue page is an 856 with second indicator 2 (related resource, `$y Catalogue record`); the electronic resource is an 856 with second indicator 0 only when it is published **and** is an `http`/`https` address — a share, a `file:` URI or an archive identifier is never exported. Private notes and uploaded file paths are excluded; public downloads require publication and are not cached. This is a per-record export, not an extension of the OAI/SRU article harvesting sets. Linked authors open the shared author archive by identity. Legacy credits retain name-based discovery, with no automatic authority merges or fabricated GND identifiers.

### Shared authors (v1.8.0)

The article form's shared-author section searches the same registry used for books and supports explicit creation on save. It displays names and dates, with internal IDs hidden. Homonyms remain distinct people; existing free-text credits require explicit selection before they share an authority record.

Name and pseudonym changes and author merges are reflected in linked articles, search suggestions and CSV exports. The shared author archive includes books and articles; unlinked credits retain name-based search. Deleting a person preserves their latest name as a text credit. Failed saves or deletions roll back the links as well as the record.

Core migration 0.7.88 adds GND to the shared author registry; the plugin's idempotent schema upgrade adds the article-author relation. Article MARCXML exports the manually confirmed GND in 100/700 $0 and VIAF/ISNI URIs of confirmed matches. Different GNDs block an accidental author merge. CSV carries names; reimporting an unchanged export preserves existing identity links, while replacing the author credit clears stale associations.
