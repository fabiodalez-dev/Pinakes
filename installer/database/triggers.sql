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
    -- The web/CLI application initializes this connection-scoped value from
    -- DateHelper (app.timezone). Direct SQL clients leave it NULL and retain
    -- the safe database-local fallback.
    DECLARE application_today DATE;
    SET application_today = COALESCE(@pinakes_application_date, CURRENT_DATE());

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
              -- Effective interval of NEW: an overdue physical loan remains
              -- open-ended until the copy is actually returned, regardless of
              -- whether the future row or the overdue row was inserted first.
              AND (NEW.stato = 'in_ritardo'
                   OR (NEW.stato = 'in_corso' AND NEW.data_scadenza < application_today)
                   OR p.data_prestito <= NEW.data_scadenza)
              -- application_today is authoritative for app writers; its
              -- declaration keeps CURRENT_DATE() as the direct-SQL fallback.
              AND (p.stato = 'in_ritardo'
                   OR (p.stato = 'in_corso' AND p.data_scadenza < application_today)
                   OR p.data_scadenza >= NEW.data_prestito)
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
    -- See the INSERT trigger: app writers bind DateHelper::today() through a
    -- connection variable; direct SQL writes fall back to the database date.
    DECLARE application_today DATE;
    SET application_today = COALESCE(@pinakes_application_date, CURRENT_DATE());

    -- I7: la copia di un prestito deve appartenere al libro del prestito stesso
    -- (vale anche per righe chiuse: previene il decoupling libro_id/copia_id, #fix/loan-state-bugs BUG2).
    IF NEW.copia_id IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM copie WHERE id = NEW.copia_id AND libro_id = NEW.libro_id) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La copia non appartiene al libro del prestito.';
    END IF;

    -- #157 model A-refined: a copy is "held" by an active loan OR by a
    -- reservation-conversion 'pendente' that already carries a copia_id.
    --
    -- Validate copy usability only when this UPDATE creates a NEW physical
    -- hold (assignment/copy change/lifecycle transition). Re-validating it on
    -- every unrelated UPDATE made notification flags and overdue transitions
    -- impossible when an already-held copy later became damaged/maintenance.
    IF (NEW.copia_id IS NOT NULL
        AND (NEW.attivo = 1 OR NEW.stato = 'pendente')
        AND (
            OLD.copia_id IS NULL
            OR NOT (OLD.copia_id <=> NEW.copia_id)
            OR NOT (OLD.attivo = 1 OR OLD.stato = 'pendente')
        )) THEN
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

    END IF;

    -- Nessuna NUOVA sovrapposizione con altri prestiti della stessa copia.
    --
    -- A real-world overdue return can turn two previously non-overlapping rows
    -- into an unavoidable conflict: the old loan is physically still out while
    -- its successor is already scheduled. That pre-existing conflict must not
    -- freeze both rows forever. In particular it must remain possible to:
    --   * flip in_corso -> in_ritardo;
    --   * demote a stale da_ritirare left by an older release;
    --   * move/shorten that successor without getting loan_update_failed (#366).
    --
    -- Run the overlap gate only when the UPDATE changes the commitment itself,
    -- then reject a conflicting row only if that SAME row did not already
    -- conflict with OLD. Copy changes and newly-active holds still fail closed,
    -- while genuinely corrective edits can escape legacy inconsistent state.
    IF (NEW.copia_id IS NOT NULL
        AND (NEW.attivo = 1 OR NEW.stato = 'pendente')
        AND (
            OLD.copia_id IS NULL
            OR NOT (OLD.copia_id <=> NEW.copia_id)
            OR NOT (OLD.attivo = 1 OR OLD.stato = 'pendente')
            OR NOT (OLD.data_prestito <=> NEW.data_prestito)
            OR NOT (OLD.data_scadenza <=> NEW.data_scadenza)
            -- A state-only transition can turn a finite commitment into an
            -- open-ended physical hold (for example prenotato -> stale
            -- in_corso). Re-run the gate whenever that semantic bit changes.
            OR NOT (
                (OLD.stato = 'in_ritardo'
                 OR (OLD.stato = 'in_corso' AND OLD.data_scadenza < application_today))
                <=>
                (NEW.stato = 'in_ritardo'
                 OR (NEW.stato = 'in_corso' AND NEW.data_scadenza < application_today))
            )
        )) THEN
        IF EXISTS (
            SELECT 1
            FROM prestiti p
            WHERE p.copia_id = NEW.copia_id
              AND p.id <> NEW.id
              AND (NEW.stato = 'in_ritardo'
                   OR (NEW.stato = 'in_corso' AND NEW.data_scadenza < application_today)
                   OR p.data_prestito <= NEW.data_scadenza)
              -- See the INSERT trigger: application_today is authoritative for
              -- app writes and database-local only for uninitialized SQL clients.
              AND (p.stato = 'in_ritardo'
                   OR (p.stato = 'in_corso' AND p.data_scadenza < application_today)
                   OR p.data_scadenza >= NEW.data_prestito)
              AND (
                  (p.attivo = 1 AND p.stato IN ('in_corso','in_ritardo','prenotato','da_ritirare'))
                  OR (p.stato = 'pendente' AND p.copia_id IS NOT NULL)
              )
              AND NOT (
                  OLD.copia_id <=> NEW.copia_id
                  AND (OLD.attivo = 1 OR OLD.stato = 'pendente')
                  AND (OLD.stato = 'in_ritardo'
                       OR (OLD.stato = 'in_corso' AND OLD.data_scadenza < application_today)
                       OR p.data_prestito <= OLD.data_scadenza)
                  AND (p.stato = 'in_ritardo'
                       OR (p.stato = 'in_corso' AND p.data_scadenza < application_today)
                       OR p.data_scadenza >= OLD.data_prestito)
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
