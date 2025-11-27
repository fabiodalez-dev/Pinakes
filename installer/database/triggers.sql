-- Database Triggers - Pinakes
-- Generated: 2025-10-06 17:18:57

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

DROP TRIGGER IF EXISTS `trg_check_active_prestito_before_insert`;
DELIMITER $$
CREATE TRIGGER `trg_check_active_prestito_before_insert`
BEFORE INSERT ON `prestiti`
FOR EACH ROW
BEGIN
    IF (NEW.attivo = 1 AND NEW.copia_id IS NOT NULL) THEN
        -- 1) La copia deve essere utilizzabile
        IF NOT EXISTS (
            SELECT 1
            FROM copie c
            WHERE c.id = NEW.copia_id
              AND c.stato = 'disponibile'
        ) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'La copia non è disponibile per il prestito.';
        END IF;

        -- 2) Nessuna sovrapposizione di date con prestiti attivi della stessa copia
        IF EXISTS (
            SELECT 1
            FROM prestiti p
            WHERE p.copia_id = NEW.copia_id
              AND p.attivo = 1
              AND p.stato IN ('in_corso','in_ritardo','prenotato','pendente')
              AND p.data_prestito <= NEW.data_scadenza
              AND p.data_scadenza >= NEW.data_prestito
        ) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Esiste già un prestito attivo e sovrapposto per questa copia.';
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
    IF (NEW.attivo = 1 AND NEW.copia_id IS NOT NULL) THEN
        -- 1) La copia deve essere utilizzabile
        IF NOT EXISTS (
            SELECT 1
            FROM copie c
            WHERE c.id = NEW.copia_id
              AND c.stato = 'disponibile'
        ) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'La copia non è disponibile per il prestito.';
        END IF;

        -- 2) Nessuna sovrapposizione di date con altri prestiti attivi della stessa copia
        IF EXISTS (
            SELECT 1
            FROM prestiti p
            WHERE p.copia_id = NEW.copia_id
              AND p.attivo = 1
              AND p.id <> NEW.id
              AND p.stato IN ('in_corso','in_ritardo','prenotato','pendente')
              AND p.data_prestito <= NEW.data_scadenza
              AND p.data_scadenza >= NEW.data_prestito
        ) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Esiste già un prestito attivo e sovrapposto per questa copia.';
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
