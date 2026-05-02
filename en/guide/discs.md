# Discs and Music

Pinakes manages not only books but also **music and audio-visual
material**: CDs, vinyl, LPs, cassettes, DVDs, audiobooks, and any other
physical or digital medium. The interface adapts intelligently to the
content type, showing the appropriate labels and fields.

> Introduced in v0.5.5 — [PR #100](https://github.com/fabiodalez-dev/Pinakes/pull/100).

## The "Media Type" field

Every catalog item has a **Media Type** (`tipo_media`) field that
determines how Pinakes interprets and displays the record:

| Value | Description | Icon |
|-------|-------------|------|
| `libro` | Paper book or ebook | Book |
| `disco` | CD, vinyl, LP, cassette | Disc |
| `audiolibro` | Audiobook (MP3, spoken word) | Headphones |
| `dvd` | DVD, Blu-ray, film | Film reel |
| `altro` | Any other medium | Archive |

In the add/edit form, the **Media Type** dropdown is in the
"Classification" section. Once saved, the corresponding icon appears in
the admin list and on the public detail page.

## Dynamic labels

When the media type is `disco`, field labels automatically switch to
music terminology:

| Generic field | Label for discs |
|---------------|-----------------|
| Author | Artist |
| Publisher | Record Label |
| Publication Year | Release Year |
| Number of Pages | Tracks |
| ISBN-13 | Barcode (EAN-13 / UPC-A) |
| Description | Tracklist |
| Series | Discography |

These labels are managed by `app/Support/MediaLabels.php` and apply
consistently across the admin form, detail page, and CSV export.

## Adding a disc manually

1. Go to **Administration → Catalog → New** (or **New book** from the
   sidebar).
2. In the **Classification** section, choose **Media Type** → `disco`.
3. The fields update instantly with music labels.
4. Fill in the main fields:
   - **Artist** — artist or band name.
   - **Album title** — exact title as printed on the disc.
   - **Record Label** — label name (e.g. "EMI", "Capitol").
   - **Release Year** — year of publication.
   - **Barcode** — the EAN-13 or UPC-A code printed on the back.
   - **Format** — select from: CD Audio, Vinyl, LP, Cassette, etc.
5. In the **Content** section, fill in the **Tracklist** (HTML editor;
   TinyMCE renders tracks as an ordered list).
6. Save. The cover can be uploaded manually or retrieved via automatic
   enrichment.

> **Tip** — If you have the barcode, use **ISBN Enrichment** to
> automatically populate artist, title, tracklist, and cover via Discogs,
> MusicBrainz, or Deezer.

## Identifiers for discs

Discs use different identification systems from books:

| Identifier | Description | Example |
|------------|-------------|---------|
| EAN-13 | European barcode, 13 digits | `0724349681422` |
| UPC-A | American barcode, 12 digits | `724349681422` |
| Cat# | Catalog Number on spine/label | `CDP 7912682`, `SRX-6272` |

In the **Barcode** field (formerly ISBN-13) you can enter both EAN and
UPC. The **Cat#** field accepts alphanumeric identifiers used by Discogs
(e.g. `EMI 2C 068-82892`).

> **Note on Cat#** — From v0.5.9 onwards, the Discogs plugin
> automatically recognises alphanumeric Catalog Numbers that are not
> numeric barcodes, avoiding confusion with valid ISBN-10 values (fix for
> the "Bonnie Raitt — Nick Of Time, Capitol CDP 7912682" case).

## Automatic enrichment via plugins

Pinakes ships three bundled plugins for automatic retrieval of music
metadata:

### Discogs plugin

Enable it from **Administration → Plugins → Discogs Music Scraper →
Enable**.

Discogs is the most comprehensive disc database available and supports:
- Search by EAN-13 and UPC-A barcode
- Search by Catalog Number (Cat#)
- Retrieval of: title, artist, label, year, tracklist, genres, format,
  production notes, cover art

**Optional configuration:** enter a Discogs token in the plugin settings
page to get a higher rate limit (1 req/s with token vs 1 req every 2.5 s
without).

### MusicBrainz plugin

MusicBrainz is an open-source music database. Used as a fallback when
Discogs returns no results. It also integrates the **Cover Art Archive**
for album artwork.

Enable it from **Administration → Plugins → MusicBrainz → Enable**.

### Deezer plugin

Deezer is excellent for high-definition covers and up-to-date tracklists.
Enable it from **Administration → Plugins → Deezer Music Search →
Enable**.

It retrieves:
- High-resolution album covers
- Full tracklist with track durations
- Album title and artist name

## Search and filters

In the admin list, use the **Media Type** filter to show only discs. The
filter is accessible from the list toolbar.

In the public search, discs are included in results alongside books. The
detail page shows the media type icon and uses the appropriate labels
(Artist, Label, Tracks).

## Export and import

The `tipo_media` and `barcode` fields are included in CSV/TSV export.
When importing CSV/TSV, the `tipo_media` column accepts the values:
`libro`, `disco`, `audiolibro`, `dvd`, `altro`.

The `barcode` field is automatically mapped to `isbn13` in
`IMPORT_COLUMN_MAP`.

## Schema.org

Public disc pages use the `MusicAlbum` Schema.org type with the
following fields:

```json
{
  "@type": "MusicAlbum",
  "byArtist": "Artist Name",
  "recordLabel": "Label Name",
  "numTracks": 12,
  "name": "Album Title"
}
```

This improves indexing in search engines and readability by voice
assistants and aggregators.
