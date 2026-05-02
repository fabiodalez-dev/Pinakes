# Bulk ISBN Enrichment

Pinakes includes an automatic enrichment tool to add covers, descriptions
and metadata to items that are missing them — without editing each record
individually.

> Introduced in v0.5.5 — [PR #100](https://github.com/fabiodalez-dev/Pinakes/pull/100).

## How it works

Bulk enrichment finds all catalog items with one or more empty fields
(cover, description, publisher, year…) and automatically queries the
active scraping plugins to retrieve the missing information, in this
order: Open Library → Google Books → Discogs → MusicBrainz → Deezer →
Scraping Pro (if installed).

**Core principle:** enrichment is non-destructive. Only **NULL or empty
fields** are populated; existing data is never overwritten.

## Manual run

1. Go to **Administration → ISBN Enrichment** (sidebar).
2. Enable the toggle if it is off.
3. Click **Start enrichment**.
4. The system processes 20 items per click; a progress bar shows
   real-time status.
5. Repeat until the "items remaining" counter reaches zero.

> **Note** — Rate limits are enforced automatically to respect external
> APIs. No manual waiting between clicks is needed.

## Automation via cron

For continuous background enrichment, configure a cron job on the server:

```bash
# Example: every hour, Monday–Friday
0 * * * 1-5 php /var/www/pinakes/scripts/bulk-enrich-cron.php >> /var/log/pinakes-enrich.log 2>&1
```

`scripts/bulk-enrich-cron.php` uses an exclusive lock (`flock`) that
prevents concurrent runs. If a job is already running, the new process
exits immediately without errors.

## Plugins involved

Enrichment uses all active scraping plugins in this chain:

| Plugin | Resource type | Data retrieved |
|--------|---------------|----------------|
| Open Library | Books | Cover, description, author, publisher, year |
| Google Books (fallback) | Books | Cover, description |
| Discogs | Discs, CDs, vinyl | Cover, tracklist, label, year |
| MusicBrainz | Discs (fallback) | Music metadata, Cover Art Archive |
| Deezer | Audio | HD cover, tracklist |
| Scraping Pro | Books (premium) | Extended data, additional sources |

Enable or disable plugins from **Administration → Plugins** to control
which sources are used.

## Status and logs

- The enrichment page shows how many items still need processing.
- Per-item errors are written to `storage/logs/app.log` with the title
  and ISBN of the affected item.
- An item is considered "fully enriched" when both cover and description
  are present; supplementary fields (publisher, year, tracklist) are
  filled in when available from the source.

## Frequently asked questions

### Does enrichment overwrite my data?

No. The process checks the current value of each field before updating
it: if it already contains a value (even a single space) the field is
left unchanged. Only `NULL` fields are populated.

### Can I enrich only items missing a cover?

Yes. The internal filter is `cover_image_path IS NULL OR cover_image_path
= ''`. Items that already have a cover are skipped even if other fields
are empty — for those fields the non-destructive rule still applies.

### How many external requests does it make?

Approximately 1 request per service every 1–2.5 seconds, depending on
the provider. For 100 items to enrich with 3 active plugins, expect
roughly 5–10 minutes total. With the cron job this happens in the
background with no impact on the interface.

### What happens if a plugin cannot find an item?

The system moves to the next plugin in the chain. If no plugin produces
data, the item remains unchanged (no error visible to the user; the
detail is in the log).
