-- Migration 0.7.73-rc.1 — issue #374 activity-feed audit retention.
--
-- New audit rows reference the operator who performed an action. The original
-- restrictive foreign key made an otherwise-deletable staff account impossible
-- to remove as soon as it had generated one event. Audit rows must survive that
-- lifecycle, so deleting the account now nulls only the relational reference;
-- new rows also retain the operator display name in their JSON snapshot.
--
-- Idempotent: preserve an already-correct SET NULL key, otherwise replace the
-- existing FK regardless of its installation-specific constraint name.

SET @activity_operator_fk = (
    SELECT kcu.CONSTRAINT_NAME
      FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
     WHERE kcu.CONSTRAINT_SCHEMA = DATABASE()
       AND kcu.TABLE_NAME = 'log_modifiche'
       AND kcu.COLUMN_NAME = 'utente_id'
       AND kcu.REFERENCED_TABLE_NAME = 'utenti'
       AND kcu.REFERENCED_COLUMN_NAME = 'id'
     LIMIT 1
);

SET @activity_operator_delete_rule = (
    SELECT rc.DELETE_RULE
      FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
     WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
       AND rc.TABLE_NAME = 'log_modifiche'
       AND rc.CONSTRAINT_NAME = @activity_operator_fk
     LIMIT 1
);

SET @sql = IF(
    @activity_operator_fk IS NOT NULL AND COALESCE(@activity_operator_delete_rule, '') <> 'SET NULL',
    CONCAT(
        'ALTER TABLE `log_modifiche` DROP FOREIGN KEY `',
        REPLACE(@activity_operator_fk, '`', '``'),
        '`'
    ),
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @activity_operator_fk_exists = (
    SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
      JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
        ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
       AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
       AND rc.TABLE_NAME = kcu.TABLE_NAME
     WHERE kcu.CONSTRAINT_SCHEMA = DATABASE()
       AND kcu.TABLE_NAME = 'log_modifiche'
       AND kcu.COLUMN_NAME = 'utente_id'
       AND kcu.REFERENCED_TABLE_NAME = 'utenti'
       AND kcu.REFERENCED_COLUMN_NAME = 'id'
       AND rc.DELETE_RULE = 'SET NULL'
);

SET @sql = IF(
    @activity_operator_fk_exists = 0,
    'ALTER TABLE `log_modifiche` ADD CONSTRAINT `log_modifiche_ibfk_1` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
