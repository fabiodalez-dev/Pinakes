-- Migration 0.7.53
-- Performance: composite indexes for the "newest first" sorts used by the
-- public home page (latest books, per-genre carousels) and the catalog
-- default sort. Without them MySQL filesorts the whole libri table on
-- every home/catalog render.
-- Duplicate-index errors (1061) are ignored by the updater, so this
-- migration is idempotent.

ALTER TABLE `libri` ADD INDEX `idx_libri_deleted_created` (`deleted_at`, `created_at`);

ALTER TABLE `libri` ADD INDEX `idx_libri_genere_deleted_created` (`genere_id`, `deleted_at`, `created_at`);
