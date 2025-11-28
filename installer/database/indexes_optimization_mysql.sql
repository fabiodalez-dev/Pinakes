-- =====================================================
-- INDICI DI OTTIMIZZAZIONE AGGIUNTIVI - Pinakes
-- Versione compatibile con Oracle MySQL (tutte le versioni)
-- Eseguire dopo l'installazione per migliorare le performance
-- =====================================================
--
-- Questo script usa una stored procedure per verificare se l'indice
-- esiste già prima di crearlo, evitando errori su MySQL.
--
-- Per MariaDB 10.0.2+ usare invece: indexes_optimization.sql
-- =====================================================

DELIMITER //

-- Procedura helper per aggiungere indici in modo sicuro
DROP PROCEDURE IF EXISTS add_index_if_not_exists//
CREATE PROCEDURE add_index_if_not_exists(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_columns VARCHAR(255)
)
BEGIN
    DECLARE index_exists INT DEFAULT 0;

    SELECT COUNT(*) INTO index_exists
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table
      AND INDEX_NAME = p_index;

    IF index_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_columns, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//

DELIMITER ;

-- =====================================================
-- TABELLA: libri
-- =====================================================
CALL add_index_if_not_exists('libri', 'idx_created_at', 'created_at');
CALL add_index_if_not_exists('libri', 'idx_isbn10', 'isbn10');
CALL add_index_if_not_exists('libri', 'idx_genere_scaffale', 'genere_id, scaffale_id');
CALL add_index_if_not_exists('libri', 'idx_sottogenere_scaffale', 'sottogenere_id, scaffale_id');

-- =====================================================
-- TABELLA: libri_autori (CRITICA - JOIN efficienti)
-- =====================================================
CALL add_index_if_not_exists('libri_autori', 'idx_libro_autore', 'libro_id, autore_id');
CALL add_index_if_not_exists('libri_autori', 'idx_autore_libro', 'autore_id, libro_id');
CALL add_index_if_not_exists('libri_autori', 'idx_ordine_credito', 'ordine_credito');
CALL add_index_if_not_exists('libri_autori', 'idx_ruolo', 'ruolo');

-- =====================================================
-- TABELLA: autori
-- =====================================================
CALL add_index_if_not_exists('autori', 'idx_nome', 'nome(100)');

-- =====================================================
-- TABELLA: editori
-- =====================================================
CALL add_index_if_not_exists('editori', 'idx_nome', 'nome(100)');

-- =====================================================
-- TABELLA: prestiti
-- =====================================================
CALL add_index_if_not_exists('prestiti', 'idx_stato_attivo', 'stato, attivo');
CALL add_index_if_not_exists('prestiti', 'idx_data_prestito', 'data_prestito');

-- =====================================================
-- TABELLA: utenti
-- =====================================================
CALL add_index_if_not_exists('utenti', 'idx_nome', 'nome(50)');
CALL add_index_if_not_exists('utenti', 'idx_cognome', 'cognome(50)');
CALL add_index_if_not_exists('utenti', 'idx_nome_cognome', 'nome(50), cognome(50)');
CALL add_index_if_not_exists('utenti', 'idx_ruolo', 'ruolo');

-- =====================================================
-- TABELLA: generi
-- =====================================================
CALL add_index_if_not_exists('generi', 'idx_nome', 'nome(50)');

-- =====================================================
-- TABELLA: posizioni
-- =====================================================
CALL add_index_if_not_exists('posizioni', 'idx_scaffale_mensola', 'scaffale_id, mensola_id');

-- =====================================================
-- TABELLA: copie
-- =====================================================
CALL add_index_if_not_exists('copie', 'idx_numero_inventario', 'numero_inventario');

-- =====================================================
-- TABELLA: prenotazioni
-- =====================================================
CALL add_index_if_not_exists('prenotazioni', 'idx_libro_id', 'libro_id');
CALL add_index_if_not_exists('prenotazioni', 'idx_utente_id', 'utente_id');
CALL add_index_if_not_exists('prenotazioni', 'idx_stato', 'stato');

-- Pulizia: rimuovi la procedura helper
DROP PROCEDURE IF EXISTS add_index_if_not_exists;

-- =====================================================
-- ANALISI E VERIFICA
-- =====================================================
-- Per verificare gli indici creati:
-- SHOW INDEX FROM libri;
-- SHOW INDEX FROM libri_autori;
--
-- Per analizzare le tabelle:
-- ANALYZE TABLE libri, libri_autori, autori, editori, prestiti, utenti;
