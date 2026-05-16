-- Phase 4 cleanup — drop reserved-but-unused place_id column from
-- archive_activities. The column was added in 0.7.9 with the comment
-- "reserved for Phase 4 archive_places FK" but Phase 4 (0.7.10) never
-- added the foreign key and no application code reads or writes the
-- column. See review F015 (rev_01KRRE2QJR3QSTGJ4FZEK7MVDW).
ALTER TABLE archive_activities DROP COLUMN place_id;
