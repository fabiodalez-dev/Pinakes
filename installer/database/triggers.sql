-- Database Triggers - Sistema Biblioteca
-- Generated: 2025-10-06 17:18:57

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- Trigger: trg_check_active_prestito_before_insert
-- Verifica che una copia fisica non sia già in prestito
DROP TRIGGER IF EXISTS `trg_check_active_prestito_before_insert`;
DELIMITER $$
CREATE TRIGGER `trg_check_active_prestito_before_insert`
BEFORE INSERT ON `prestiti`
FOR EACH ROW
BEGIN
    IF (NEW.attivo = 1) THEN
        IF EXISTS (
            SELECT 1 FROM prestiti
            WHERE copia_id = NEW.copia_id AND attivo = 1
        ) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Esiste già un prestito attivo per questa copia.';
        END IF;
    END IF;
END$$
DELIMITER ;

-- Trigger: trg_check_active_prestito_before_update
-- Verifica che una copia fisica non sia già in prestito durante aggiornamento
DROP TRIGGER IF EXISTS `trg_check_active_prestito_before_update`;
DELIMITER $$
CREATE TRIGGER `trg_check_active_prestito_before_update`
BEFORE UPDATE ON `prestiti`
FOR EACH ROW
BEGIN
    IF (NEW.attivo = 1) THEN
        IF EXISTS (
            SELECT 1 FROM prestiti
            WHERE copia_id = NEW.copia_id AND attivo = 1 AND id <> NEW.id
        ) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Esiste già un prestito attivo per questa copia.';
        END IF;
    END IF;
END$$
DELIMITER ;

-- Trigger: trg_utenti_scadenza_tessera
-- Automaticamente gestisce la scadenza tessera in base al tipo di utente
DROP TRIGGER IF EXISTS `trg_utenti_scadenza_tessera`;
DELIMITER $$
CREATE TRIGGER `trg_utenti_scadenza_tessera`
BEFORE UPDATE ON `utenti`
FOR EACH ROW
BEGIN
    -- Se l'utente cambia da admin/staff a standard/premium, assegna scadenza tessera (1 anno)
    IF (OLD.tipo_utente IN ('admin', 'staff') AND NEW.tipo_utente IN ('standard', 'premium')) THEN
        IF NEW.data_scadenza_tessera IS NULL THEN
            SET NEW.data_scadenza_tessera = DATE_ADD(NOW(), INTERVAL 1 YEAR);
        END IF;
    END IF;

    -- Se l'utente cambia da standard/premium a admin/staff, rimuovi scadenza tessera
    IF (OLD.tipo_utente IN ('standard', 'premium') AND NEW.tipo_utente IN ('admin', 'staff')) THEN
        SET NEW.data_scadenza_tessera = NULL;
    END IF;
END$$
DELIMITER ;

SET foreign_key_checks = 1;
-- Triggers export updated: 2025-10-09
