<?php
declare(strict_types=1);

/**
 * Archives plugin wrapper.
 *
 * Skeleton for issue #103 — implements an ISAD(G) / ISAAR(CPF) data model on
 * top of Pinakes, allowing archival material (fonds → series → files → items)
 * to coexist with the bibliographic `libri` table and support unified search
 * across collections.
 *
 * Inspired by the ABA (Arbejderbevægelsens Bibliotek og Arkiv, Copenhagen)
 * archive format mapping ISAD(G)/ISAAR(CPF) onto an extended danMARC2.
 *
 * Current status: SKELETON — schema creation + hook registration only.
 * No CRUD UI, no MARCXML I/O, no unified search integration yet.
 * See storage/plugins/archives/README.md for the roadmap.
 */

require_once __DIR__ . '/ArchivesPlugin.php';

return new \App\Plugins\Archives\ArchivesPlugin();
