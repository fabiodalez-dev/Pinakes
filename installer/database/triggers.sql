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
    -- I7: la copia di un prestito deve appartenere al libro del prestito stesso
    -- (vale anche per righe chiuse: previene il decoupling libro_id/copia_id, #fix/loan-state-bugs BUG2).
    IF NEW.copia_id IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM copie WHERE id = NEW.copia_id AND libro_id = NEW.libro_id) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La copia non appartiene al libro del prestito.';
    END IF;

    -- #157 model A-refined: a copy is "held" by an active loan OR by a
    -- reservation-conversion 'pendente' that already carries a copia_id.
    IF (NEW.copia_id IS NOT NULL AND (NEW.attivo = 1 OR NEW.stato = 'pendente')) THEN
        -- 1) La copia deve essere utilizzabile (non persa, danneggiata, in manutenzione, restauro o trasferimento)
        -- Consente: disponibile (nuovi prestiti), prenotato (prestiti futuri non sovrapposti), prestato (prestiti futuri)
        IF NOT EXISTS (
            SELECT 1
            FROM copie c
            WHERE c.id = NEW.copia_id
              AND c.stato NOT IN ('perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento')
        ) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'La copia non è disponibile per il prestito.';
        END IF;

        -- 2) Nessuna sovrapposizione di date con prestiti attivi della stessa copia
        IF EXISTS (
            SELECT 1
            FROM prestiti p
            WHERE p.copia_id = NEW.copia_id
              AND p.data_prestito <= NEW.data_scadenza
              AND (p.stato = 'in_ritardo' OR p.data_scadenza >= NEW.data_prestito)
              AND (
                  (p.attivo = 1 AND p.stato IN ('in_corso','in_ritardo','prenotato','da_ritirare'))
                  OR (p.stato = 'pendente' AND p.copia_id IS NOT NULL)
              )
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
    -- I7: la copia di un prestito deve appartenere al libro del prestito stesso
    -- (vale anche per righe chiuse: previene il decoupling libro_id/copia_id, #fix/loan-state-bugs BUG2).
    IF NEW.copia_id IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM copie WHERE id = NEW.copia_id AND libro_id = NEW.libro_id) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La copia non appartiene al libro del prestito.';
    END IF;

    -- Solo se si sta assegnando/cambiando una copia a un prestito attivo
    -- #157 model A-refined: a copy is "held" by an active loan OR by a
    -- reservation-conversion 'pendente' that already carries a copia_id.
    IF (NEW.copia_id IS NOT NULL AND (NEW.attivo = 1 OR NEW.stato = 'pendente')) THEN
        -- 1) La copia deve essere utilizzabile (non persa, danneggiata, in manutenzione, restauro o trasferimento)
        -- Nota: durante un update la copia può essere già in stato prestato/prenotato per QUESTO prestito
        IF NOT EXISTS (
            SELECT 1
            FROM copie c
            WHERE c.id = NEW.copia_id
              AND c.stato NOT IN ('perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento')
        ) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'La copia non è disponibile per il prestito.';
        END IF;

        -- 2) Nessuna sovrapposizione di date con ALTRI prestiti attivi della stessa copia
        -- Esclude il prestito corrente (p.id <> NEW.id) per consentire gli update
        IF EXISTS (
            SELECT 1
            FROM prestiti p
            WHERE p.copia_id = NEW.copia_id
              AND p.id <> NEW.id
              AND p.data_prestito <= NEW.data_scadenza
              AND (p.stato = 'in_ritardo' OR p.data_scadenza >= NEW.data_prestito)
              AND (
                  (p.attivo = 1 AND p.stato IN ('in_corso','in_ritardo','prenotato','da_ritirare'))
                  OR (p.stato = 'pendente' AND p.copia_id IS NOT NULL)
              )
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
-- Triggers updated: 2025-11-29
-- Fixed: stato check changed from 'disponibile' to NOT IN ('perso','danneggiato','manutenzione','in_restauro','in_trasferimento')
-- This allows creating loans for copies that are 'prenotato' (for non-overlapping future dates)
