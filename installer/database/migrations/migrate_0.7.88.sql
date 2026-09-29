-- Shared GND identity for book and analytic-record creators. Idempotent upgrade.
SET @gnd_column_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='autori' AND COLUMN_NAME='gnd_id');
SET @gnd_column_sql = IF(@gnd_column_exists=0,
 'ALTER TABLE autori ADD COLUMN gnd_id VARCHAR(32) NULL', 'SELECT 1');
PREPARE gnd_column_stmt FROM @gnd_column_sql;
EXECUTE gnd_column_stmt;
DEALLOCATE PREPARE gnd_column_stmt;
SET @gnd_index_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='autori' AND INDEX_NAME='uq_autori_gnd');
SET @gnd_index_sql = IF(@gnd_index_exists=0,
 'ALTER TABLE autori ADD UNIQUE KEY uq_autori_gnd(gnd_id)', 'SELECT 1');
PREPARE gnd_index_stmt FROM @gnd_index_sql;
EXECUTE gnd_index_stmt;
DEALLOCATE PREPARE gnd_index_stmt;
