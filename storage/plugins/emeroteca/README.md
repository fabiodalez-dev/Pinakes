# Emeroteca 1.5

Emeroteca supports two workflows. **Simple** starts with standalone articles. **Complete** also exposes the existing mastheads, volume years, issues, Kardex and subscriptions. The same records remain available in either mode. Change the shared setting under **Plugins → Emeroteca → Settings**, or on the Periodicals/Articles page. Only administrators may change the installation-wide setting.

New collections start in Simple mode. Upgrades preserve Complete mode unless a choice has already been saved. Deactivation keeps the tables and documents.

## Catalogue one article

Choose **Add article**, enter its title and any known citation information, and save. No masthead, year or issue is created. Publication dates can remain textual (for example “June 2019”); volume, issue and page spans accept textual values such as “4–5” and “iv–x”. ISSN checksums and DOI syntax are validated. Only the title is required.

Articles are private initially. Publishing the article and allowing public access to its PDF are separate choices. Uploads accept PDF files up to 25 MB and are stored outside the public directory, under `storage/uploads/plugins/emeroteca/contributi`, so existing full backups include them. Downloads pass through the visibility check on every request and are not cacheable. Private notes and shelf marks are never returned by public pages or the mobile API.

The existing **Issue article indexes** remain available from the Articles list. They are edited on their issue, while standalone articles have independent IDs and permanent detail URLs. No conversion or duplication occurs when switching views.

## Create the publication later

Select standalone articles, open **Associate selected articles with a publication**, then select a publication or enter the name of a new one. Review the preview and confirm. You can also start from an existing masthead's edit/issue page using **Add existing articles**.

Associating articles does not declare ownership of any issue. An optional existing issue must belong to the selected publication. A bulk association is atomic; a changed or deleted selection fails instead of overwriting another operator's work. Reassigning articles already associated with another publication requires an explicit checkbox in the preview.

Removing a publication or issue preserves standalone citations and PDFs. Removing an issue leaves its masthead association intact. Masthead merges repoint article associations. To detach an article, select “No publication” and explicitly confirm reassignment. Article indexes owned by an issue retain their previous cascade-deletion policy.

## CSV

Use **Import articles** in Emeroteca or the link from the book import page. Download the empty template first. Files must be UTF-8, comma-separated, at most 5 MB and 500 records. The preview lists validation errors, updates and possible duplicates; committing imports only valid rows and reports each result.

`reference_key` is the stable import identity. The export supplies it for every article. A known key updates that record; a missing key creates a new identity. Omitted columns preserve existing values, while empty cells clear the corresponding value. Title cannot be empty. Updating from an expired revision is rejected, including if another operator edits a record after preview.

The supported columns are:

```text
record_type,reference_key,titolo,autori,tipo_contributo,contenitore_tipo,contenitore_titolo,issn,data_pubblicazione_testo,anno_pubblicazione,volume,numero,pagine,doi,supporto,keywords,abstract,collocazione,note_private,pubblico
```

A filled row, for reference:

```text
record_type,titolo,autori,contenitore_titolo,anno_pubblicazione,volume,numero,pagine
journal_article,Intertextuality in Daniel Kehlmann's Novel Tyll,"Schweissinger, Marc J.",International Journal of Language and Literature,2019,7,1,138-148
```

`journal_article` and `newspaper_article` also set the publication type, so `contenitore_tipo` can be left out. Columns you omit keep whatever the record already holds; an empty cell clears it.

Accepted aliases include `title`, `authors`, `container_title`, `journal_title`, `date`, `year`, `issue`, and `pages`. Header case and separators do not matter. `record_type` (alias `media_type`) accepts `article`, `articolo`, `journal_article`, or `newspaper_article`; it leads the template and the export because it is also what lets the book importer refuse a file of articles — omit it and that guard has nothing to read. The last two also identify the publication type. Unknown types or columns are reported instead of discarded. Article records sent to the book importer are explicitly rejected with directions to Emeroteca.

The export is a machine round-trip format: spreadsheet applications should import all columns as text. PDFs are uploaded separately; import never downloads arbitrary remote URLs. Associations to local mastheads can be applied in bulk after import. KBART/ACNP continue to describe actual serial holdings and do not count standalone articles as held issues.

## Mobile API

When both Emeroteca and Mobile API are active:

- `GET /api/v1/periodicals/health` advertises `capabilities.standalone_articles`.
- `GET /api/v1/periodicals/articles` lists public standalone articles with `q`, `testata_id`, `limit` (maximum 50) and `cursor` filters. Use `meta.next_cursor` for the next page.
- `GET /api/v1/periodicals/articles/{id}` returns a public article or 404. Private records are never exposed.

The routes use the existing bearer authentication, quota and response envelope. Lists and details support ETag/304. `kind` is `autonomo`; `has_public_pdf` indicates a public document at `/emeroteca/articolo/{id}/pdf`. Existing issue API responses are unchanged. Android must implement the new endpoints to display standalone articles; its previous issue browser continues to work.

## Upgrade and verification

Pinakes 0.7.84 includes `migrate_0.7.84.sql`, which preserves the workflow for existing plugin registrations. The plugin owns the table creation and repair in `ensureSchema()`, called at installation/activation and by the bundled-plugin schema recovery mechanism. The migration does not alter `libri.tipo_media` or existing holdings.

Tests: `tests/emeroteca-412.unit.php` (real services, disposable MySQL tables), `tests/migration-0.7.84.unit.php` (migration-gate entry point), `tests/emeroteca-412.spec.js` (browser workflow), and `tests/emeroteca-412-upgrade.spec.js` (dedicated disposable fresh/upgrade instance). Existing Emeroteca admin, integration, export, interoperability and full application regression suites remain applicable.
