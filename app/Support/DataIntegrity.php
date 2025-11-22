<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;
use Exception;

class DataIntegrity {
    private mysqli $db;

    public function __construct(mysqli $db) {
        $this->db = $db;
    }

    /**
     * Ricalcola le copie disponibili per tutti i libri
     */
    public function recalculateAllBookAvailability(): array {
        $results = ['updated' => 0, 'errors' => []];

        try {
            $this->db->begin_transaction();

            // Aggiorna stato copie basandosi sui prestiti attivi
            $stmt = $this->db->prepare("
                UPDATE copie c
                LEFT JOIN prestiti p ON c.id = p.copia_id AND p.attivo = 1 AND p.stato IN ('in_corso', 'in_ritardo')
                SET c.stato = CASE
                    WHEN p.id IS NOT NULL THEN 'prestato'
                    ELSE 'disponibile'
                END
                WHERE c.stato IN ('disponibile', 'prestato')
            ");
            $stmt->execute();
            $stmt->close();

            // Ricalcola copie_disponibili e stato per tutti i libri dalla tabella copie
            $stmt = $this->db->prepare("
                UPDATE libri l
                SET copie_disponibili = (
                    SELECT COUNT(*)
                    FROM copie c
                    LEFT JOIN prestiti p ON c.id = p.copia_id AND p.attivo = 1 AND p.stato IN ('in_corso', 'in_ritardo')
                    WHERE c.libro_id = l.id
                    AND c.stato = 'disponibile'
                    AND p.id IS NULL
                ),
                copie_totali = (
                    SELECT COUNT(*)
                    FROM copie c
                    WHERE c.libro_id = l.id
                ),
                stato = CASE
                    WHEN (
                        SELECT COUNT(*)
                        FROM copie c
                        LEFT JOIN prestiti p ON c.id = p.copia_id AND p.attivo = 1 AND p.stato IN ('in_corso', 'in_ritardo')
                        WHERE c.libro_id = l.id
                        AND c.stato = 'disponibile'
                        AND p.id IS NULL
                    ) > 0 THEN 'disponibile'
                    ELSE 'prestato'
                END
            ");
            $stmt->execute();
            $results['updated'] = $this->db->affected_rows;
            $stmt->close();

            $this->db->commit();

        } catch (Exception $e) {
            $this->db->rollback();
            $results['errors'][] = "Errore ricalcolo disponibilità: " . $e->getMessage();
        }

        return $results;
    }

    /**
     * Ricalcola le copie disponibili per un singolo libro
     */
    public function recalculateBookAvailability(int $bookId): bool {
        try {
            // Aggiorna stato copie del libro basandosi sui prestiti attivi
            $stmt = $this->db->prepare("
                UPDATE copie c
                LEFT JOIN prestiti p ON c.id = p.copia_id AND p.attivo = 1 AND p.stato IN ('in_corso', 'in_ritardo')
                SET c.stato = CASE
                    WHEN p.id IS NOT NULL THEN 'prestato'
                    ELSE 'disponibile'
                END
                WHERE c.libro_id = ?
                AND c.stato IN ('disponibile', 'prestato')
            ");
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $stmt->close();

            // Aggiorna copie_disponibili e stato del libro dalla tabella copie
            $stmt = $this->db->prepare("
                UPDATE libri l
                SET copie_disponibili = (
                    SELECT COUNT(*)
                    FROM copie c
                    LEFT JOIN prestiti p ON c.id = p.copia_id AND p.attivo = 1 AND p.stato IN ('in_corso', 'in_ritardo')
                    WHERE c.libro_id = ?
                    AND c.stato = 'disponibile'
                    AND p.id IS NULL
                ),
                copie_totali = (
                    SELECT COUNT(*)
                    FROM copie c
                    WHERE c.libro_id = ?
                ),
                stato = CASE
                    WHEN (
                        SELECT COUNT(*)
                        FROM copie c
                        LEFT JOIN prestiti p ON c.id = p.copia_id AND p.attivo = 1 AND p.stato IN ('in_corso', 'in_ritardo')
                        WHERE c.libro_id = ?
                        AND c.stato = 'disponibile'
                        AND p.id IS NULL
                    ) > 0 THEN 'disponibile'
                    ELSE 'prestato'
                END
                WHERE id = ?
            ");
            $stmt->bind_param('iiii', $bookId, $bookId, $bookId, $bookId);
            $result = $stmt->execute();
            $stmt->close();

            return $result;
        } catch (Exception $e) {
            error_log("Errore ricalcolo disponibilità libro {$bookId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verifica la coerenza dei dati nel database
     */
    public function verifyDataConsistency(): array {
        $issues = [];

        // 1. Verifica libri con copie disponibili negative
        $stmt = $this->db->prepare("SELECT id, titolo, copie_totali, copie_disponibili FROM libri WHERE copie_disponibili < 0");
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $issues[] = [
                    'type' => 'negative_copies',
                    'message' => "Libro '{$row['titolo']}' (ID: {$row['id']}) ha copie disponibili negative: {$row['copie_disponibili']}"
                ];
            }
        }
        $stmt->close();

        // 2. Verifica libri con più copie disponibili che totali
        $stmt = $this->db->prepare("SELECT id, titolo, copie_totali, copie_disponibili FROM libri WHERE copie_disponibili > copie_totali");
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $issues[] = [
                    'type' => 'excess_copies',
                    'message' => "Libro '{$row['titolo']}' (ID: {$row['id']}) ha più copie disponibili ({$row['copie_disponibili']}) che totali ({$row['copie_totali']})"
                ];
            }
        }
        $stmt->close();

        // 3. Verifica prestiti orfani (senza libro o utente)
        $stmt = $this->db->prepare("
            SELECT p.id, p.libro_id, p.utente_id
            FROM prestiti p
            LEFT JOIN libri l ON p.libro_id = l.id
            LEFT JOIN utenti u ON p.utente_id = u.id
            WHERE l.id IS NULL OR u.id IS NULL
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $issues[] = [
                    'type' => 'orphan_loan',
                    'message' => "Prestito ID {$row['id']} riferisce libro/utente inesistente (libro: {$row['libro_id']}, utente: {$row['utente_id']})"
                ];
            }
        }
        $stmt->close();

        // 4. Verifica prestiti attivi senza data scadenza
        $stmt = $this->db->prepare("
            SELECT id, libro_id, utente_id, stato
            FROM prestiti
            WHERE stato IN ('in_corso', 'in_ritardo')
            AND (data_scadenza IS NULL OR DATE(data_scadenza) IS NULL OR data_scadenza < '1900-01-01')
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $issues[] = [
                    'type' => 'missing_due_date',
                    'message' => "Prestito ID {$row['id']} attivo senza data scadenza"
                ];
            }
        }
        $stmt->close();

        // 5. Verifica incoerenze stato libro vs copie disponibili
        $stmt = $this->db->prepare("
            SELECT id, titolo, stato, copie_disponibili
            FROM libri
            WHERE (stato = 'disponibile' AND copie_disponibili = 0)
               OR (stato = 'prestato' AND copie_disponibili > 0)
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $issues[] = [
                    'type' => 'status_mismatch',
                    'message' => "Libro '{$row['titolo']}' (ID: {$row['id']}) ha stato '{$row['stato']}' ma copie disponibili: {$row['copie_disponibili']}"
                ];
            }
        }
        $stmt->close();

        // 6. Verifica prenotazioni che si sovrappongono a prestiti attivi dello stesso libro
        $stmt = $this->db->prepare("
            SELECT pr.id AS prenotazione_id, pr.libro_id, pr.data_inizio_richiesta, pr.data_fine_richiesta, pr.data_scadenza_prenotazione,
                   p.id AS prestito_id, p.data_prestito, p.data_scadenza, p.data_restituzione, p.stato
            FROM prenotazioni pr
            JOIN prestiti p ON pr.libro_id = p.libro_id
            WHERE pr.stato = 'attiva'
              AND p.stato IN ('in_corso','in_ritardo','pendente')
              AND (
                    (pr.data_inizio_richiesta IS NOT NULL AND pr.data_fine_richiesta IS NOT NULL AND pr.data_inizio_richiesta <= p.data_scadenza AND pr.data_fine_richiesta >= p.data_prestito)
                 OR (pr.data_inizio_richiesta IS NOT NULL AND pr.data_fine_richiesta IS NULL AND pr.data_inizio_richiesta <= COALESCE(p.data_scadenza, p.data_restituzione, p.data_prestito))
                 OR (pr.data_inizio_richiesta IS NULL AND pr.data_fine_richiesta IS NOT NULL AND pr.data_fine_richiesta >= p.data_prestito)
                 OR (pr.data_inizio_richiesta IS NULL AND pr.data_fine_richiesta IS NULL)
              )
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $issues[] = [
                    'type' => 'overlap_reservation_loan',
                    'message' => "Prenotazione ID {$row['prenotazione_id']} si sovrappone al prestito ID {$row['prestito_id']} per il libro {$row['libro_id']}"
                ];
            }
        }
        $stmt->close();

        // 7. Verifica prenotazioni che si sovrappongono tra loro per lo stesso libro
        $stmt = $this->db->prepare("
            SELECT r1.id AS pren1, r2.id AS pren2, r1.libro_id, r1.data_inizio_richiesta, r1.data_fine_richiesta, r2.data_inizio_richiesta AS data_inizio_richiesta2, r2.data_fine_richiesta AS data_fine_richiesta2
            FROM prenotazioni r1
            JOIN prenotazioni r2 ON r1.libro_id = r2.libro_id AND r1.id < r2.id
            WHERE r1.stato = 'attiva' AND r2.stato = 'attiva'
              AND (
                  (r1.data_inizio_richiesta IS NOT NULL AND r1.data_fine_richiesta IS NOT NULL AND r2.data_inizio_richiesta IS NOT NULL AND r2.data_fine_richiesta IS NOT NULL
                   AND r1.data_inizio_richiesta <= r2.data_fine_richiesta AND r1.data_fine_richiesta >= r2.data_inizio_richiesta)
              )
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $issues[] = [
                    'type' => 'overlap_reservation_reservation',
                    'message' => "Prenotazioni ID {$row['pren1']} e {$row['pren2']} si sovrappongono per il libro {$row['libro_id']}"
                ];
            }
        }
        $stmt->close();

        return $issues;
    }

    /**
     * Corregge automaticamente le incoerenze riparabili
     */
    public function fixDataInconsistencies(): array {
        $results = ['fixed' => 0, 'errors' => []];

        try {
            $this->db->begin_transaction();

            // 1. Ricalcola tutte le copie disponibili
            $availabilityResult = $this->recalculateAllBookAvailability();
            $results['fixed'] += $availabilityResult['updated'];
            $results['errors'] = array_merge($results['errors'], $availabilityResult['errors']);

            // 2. Correggi stati libri basandosi sulle copie disponibili
            $stmt = $this->db->prepare("
                UPDATE libri SET stato = CASE
                    WHEN copie_disponibili > 0 THEN 'disponibile'
                    WHEN copie_disponibili = 0 THEN 'prestato'
                    ELSE stato
                END
                WHERE stato IN ('disponibile', 'prestato')
            ");
            $stmt->execute();
            $results['fixed'] += $this->db->affected_rows;
            $stmt->close();

            // 3. Aggiorna prestiti in ritardo
            $stmt = $this->db->prepare("
                UPDATE prestiti SET stato = 'in_ritardo'
                WHERE stato = 'in_corso'
                AND data_scadenza < CURDATE()
                AND attivo = 1
            ");
            $stmt->execute();
            $results['fixed'] += $this->db->affected_rows;
            $stmt->close();

            // 4. Annulla prenotazioni attive che si sovrappongono a prestiti attivi dello stesso libro
            $stmt = $this->db->prepare("
                UPDATE prenotazioni pr
                JOIN prestiti p ON pr.libro_id = p.libro_id
                SET pr.stato = 'annullata'
                WHERE pr.stato = 'attiva'
                  AND p.stato IN ('in_corso','in_ritardo','pendente')
                  AND (
                        (pr.data_inizio_richiesta IS NOT NULL AND pr.data_fine_richiesta IS NOT NULL AND pr.data_inizio_richiesta <= p.data_scadenza AND pr.data_fine_richiesta >= p.data_prestito)
                     OR (pr.data_inizio_richiesta IS NOT NULL AND pr.data_fine_richiesta IS NULL AND pr.data_inizio_richiesta <= COALESCE(p.data_scadenza, p.data_restituzione, p.data_prestito))
                     OR (pr.data_inizio_richiesta IS NULL AND pr.data_fine_richiesta IS NOT NULL AND pr.data_fine_richiesta >= p.data_prestito)
                     OR (pr.data_inizio_richiesta IS NULL AND pr.data_fine_richiesta IS NULL)
                  )
            ");
            $stmt->execute();
            $results['fixed'] += $this->db->affected_rows;
            $stmt->close();

            // 5. Annulla prenotazioni attive che si sovrappongono tra loro per lo stesso libro (tiene la più vecchia)
            $stmt = $this->db->prepare("
                UPDATE prenotazioni r2
                JOIN prenotazioni r1 ON r1.libro_id = r2.libro_id AND r1.id < r2.id
                SET r2.stato = 'annullata'
                WHERE r1.stato = 'attiva' AND r2.stato = 'attiva'
                  AND (
                      r1.data_inizio_richiesta IS NOT NULL AND r1.data_fine_richiesta IS NOT NULL AND
                      r2.data_inizio_richiesta IS NOT NULL AND r2.data_fine_richiesta IS NOT NULL AND
                      r1.data_inizio_richiesta <= r2.data_fine_richiesta AND r1.data_fine_richiesta >= r2.data_inizio_richiesta
                  )
            ");
            $stmt->execute();
            $results['fixed'] += $this->db->affected_rows;
            $stmt->close();

            $this->db->commit();

        } catch (Exception $e) {
            $this->db->rollback();
            $results['errors'][] = "Errore correzione dati: " . $e->getMessage();
        }

        return $results;
    }

    /**
     * Verifica ed aggiorna lo stato di un prestito
     */
    public function validateAndUpdateLoan(int $loanId): array {
        $result = ['success' => false, 'message' => '', 'updated_fields' => []];

        try {
            // Recupera dati prestito
            $stmt = $this->db->prepare("
                SELECT p.*, l.copie_totali, l.stato as libro_stato
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id
                WHERE p.id = ?
            ");
            $stmt->bind_param('i', $loanId);
            $stmt->execute();
            $loanResult = $stmt->get_result();

            if ($loanResult->num_rows === 0) {
                $result['message'] = 'Prestito non trovato';
                return $result;
            }

            $loan = $loanResult->fetch_assoc();
            $stmt->close();

            $this->db->begin_transaction();
            $updates = [];

            // Verifica stato in ritardo
            if ($loan['stato'] === 'in_corso' && $loan['data_scadenza'] < date('Y-m-d')) {
                $updates['stato'] = 'in_ritardo';
                $result['updated_fields'][] = 'stato -> in_ritardo';
            }

            // Se ci sono aggiornamenti, applicali
            if (!empty($updates)) {
                $setParts = [];
                $params = [];
                $types = '';

                foreach ($updates as $field => $value) {
                    $setParts[] = "$field = ?";
                    $params[] = $value;
                    $types .= 's';
                }

                $params[] = $loanId;
                $types .= 'i';

                $sql = "UPDATE prestiti SET " . implode(', ', $setParts) . " WHERE id = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $stmt->close();
            }

            // Aggiorna disponibilità libro
            $this->recalculateBookAvailability($loan['libro_id']);

            $this->db->commit();
            $result['success'] = true;
            $result['message'] = 'Prestito validato e aggiornato';

        } catch (Exception $e) {
            $this->db->rollback();
            $result['message'] = 'Errore validazione prestito: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Esegue controlli di integrità completi e genera report
     */
    public function generateIntegrityReport(): array {
        $report = [
            'timestamp' => gmdate('Y-m-d H:i:s'),
            'consistency_issues' => $this->verifyDataConsistency(),
            'statistics' => [
                'total_books' => 0,
                'total_loans' => 0,
                'active_loans' => 0,
                'overdue_loans' => 0,
                'books_available' => 0,
                'books_unavailable' => 0
            ]
        ];

        // Statistiche generali
        $stmt = $this->db->prepare("
            SELECT
                (SELECT COUNT(*) FROM libri) as total_books,
                (SELECT COUNT(*) FROM prestiti) as total_loans,
                (SELECT COUNT(*) FROM prestiti WHERE stato IN ('in_corso', 'in_ritardo')) as active_loans,
                (SELECT COUNT(*) FROM prestiti WHERE stato = 'in_ritardo') as overdue_loans,
                (SELECT COUNT(*) FROM libri WHERE copie_disponibili > 0) as books_available,
                (SELECT COUNT(*) FROM libri WHERE copie_disponibili = 0) as books_unavailable
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        $stats = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($stats) {
            $report['statistics'] = array_map('intval', $stats);
        }

        return $report;
    }
}
