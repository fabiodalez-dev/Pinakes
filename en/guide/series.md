# Series, Cycles and Seasons

**Series** in Pinakes are not just flat labels: they support a full
hierarchy to represent cycles, seasons, spin-offs and any multi-level
structure common in publishing, comics, and audio-visual media.

> Series hierarchy introduced in v0.5.9.6 — [PR #114](https://github.com/fabiodalez-dev/Pinakes/pull/114) / [PR #115](https://github.com/fabiodalez-dev/Pinakes/pull/115).

## Hierarchical structure

Series are organised as a self-referencing tree: each series can have a
**parent series** and an unlimited number of **child series**. The
relationship type is specified by the **Type** field:

| Type | When to use | Example |
|------|-------------|---------|
| `serie` | Main sequence of volumes | "The Lord of the Rings" |
| `ciclo` | Narrative arc within a series | "Foundation Cycle" |
| `stagione` | TV season or periodic collection | "Breaking Bad — Season 1" |
| `spin_off` | Derivative work from a main series | "Better Call Saul" from "Breaking Bad" |

A book belongs to a single leaf series (the most specific one); the
hierarchy up to the root is reconstructed automatically via parent-child
links.

## Managing series

### Create a parent series (main series)

1. Go to **Administration → Series → New series**.
2. Fill in the **Name** (e.g. "Harry Potter").
3. Leave **Parent series** empty — this is the root.
4. Choose **Type** → `serie`.
5. Add an optional **Description**.
6. Save.

### Add a cycle or season

1. Go to **Administration → Series → New series**.
2. Fill in the **Name** (e.g. "Harry Potter – The Hogwarts Years").
3. Select the **Parent series** → "Harry Potter" (autocomplete).
4. Choose **Type** → `ciclo`.
5. Save.

### Create a spin-off

Same procedure: create a new series, select the source series as the
parent, choose **Type** → `spin_off`.

## Assigning a book to a series

In the book form (section **Series / Discography**):

1. Start typing the series name in the autocomplete field.
2. Choose the most specific series (e.g. the season or cycle, not the
   main series).
3. Fill in the **Series number** field (e.g. `3` for the third volume).
4. Save.

> **Note** — The hierarchical breadcrumb (series → cycle → season) is
> generated automatically on the public page and in the admin record.
> You do not need to assign all levels manually.

## Public series page

Each series has a public page reachable at `/collana/{id}-{slug}`. The
page shows:

- The series title and description.
- The tree of child series (cycles, seasons, spin-offs).
- The list of books ordered by `numero_serie`.
- A link to the parent series (if present).

## Merge and rename

From the series list (`/admin/collane`) the following actions are
available:

- **Rename** — change the name without losing associated books.
- **Merge** — move all books from one series into another and delete the
  empty one. Useful for normalising duplicates (e.g. "Star Wars" and
  "star wars").
- **Delete** — available only when the series has no books and no child
  series.

## Cycle prevention

The system prevents cycles in the hierarchy (e.g. A → B → C → A). Before
saving a parent-child relationship, an ancestor-chain walk is performed.
If a cycle is detected, the operation is rejected with an explicit error
message.

## Export and import

- The `collana` field is included in CSV/TSV export as the leaf series
  name.
- The `numero_serie` field is included as a separate column.
- In CSV/TSV import, the `collana` value is matched by exact name
  (case-insensitive); if it does not exist it is automatically created as
  a standalone series with no parent.
- Hierarchy is not imported via CSV — use the admin interface to build
  the tree structure.

## Series and discs (Discography)

When a book's media type is `disco`, the **Series** field is relabelled
**Discography**. The functionality is identical: you can structure
complex discographies with cycles and seasons in exactly the same way as
literary series.
