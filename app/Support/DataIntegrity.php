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
            $this->db->query("
                UPDATE copie c
                LEFT JOIN prestiti p ON c.id = p.copia_id AND p.attivo = 1 AND p.stato IN ('in_corso', 'in_ritardo')
                SET c.stato = CASE
                    WHEN p.id IS NOT NULL THEN 'prestato'
                    ELSE 'disponibile'
                END
                WHERE c.stato IN ('disponibile', 'prestato')
            ");

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
        $result = $this->db->query("SELECT id, titolo, copie_totali, copie_disponibili FROM libri WHERE copie_disponibili < 0");
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $issues[] = [
                    'type' => 'negative_copies',
                    'message' => "Libro '{$row['titolo']}' (ID: {$row['id']}) ha copie disponibili negative: {$row['copie_disponibili']}"
                ];
            }
        }

        // 2. Verifica libri con più copie disponibili che totali
        $result = $this->db->query("SELECT id, titolo, copie_totali, copie_disponibili FROM libri WHERE copie_disponibili > copie_totali");
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $issues[] = [
                    'type' => 'excess_copies',
                    'message' => "Libro '{$row['titolo']}' (ID: {$row['id']}) ha più copie disponibili ({$row['copie_disponibili']}) che totali ({$row['copie_totali']})"
                ];
            }
        }

        // 3. Verifica prestiti orfani (senza libro o utente)
        $result = $this->db->query("
            SELECT p.id, p.libro_id, p.utente_id
            FROM prestiti p
            LEFT JOIN libri l ON p.libro_id = l.id
            LEFT JOIN utenti u ON p.utente_id = u.id
            WHERE l.id IS NULL OR u.id IS NULL
        ");
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $issues[] = [
                    'type' => 'orphan_loan',
                    'message' => "Prestito ID {$row['id']} riferisce libro/utente inesistente (libro: {$row['libro_id']}, utente: {$row['utente_id']})"
                ];
            }
        }

        // 4. Verifica prestiti attivi senza data scadenza
        $result = $this->db->query("
            SELECT id, libro_id, utente_id, stato
            FROM prestiti
            WHERE stato IN ('in_corso', 'in_ritardo')
            AND (data_scadenza IS NULL OR DATE(data_scadenza) IS NULL OR data_scadenza < '1900-01-01')
        ");
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $issues[] = [
                    'type' => 'missing_due_date',
                    'message' => "Prestito ID {$row['id']} attivo senza data scadenza"
                ];
            }
        }

        // 5. Verifica incoerenze stato libro vs copie disponibili
        $result = $this->db->query("
            SELECT id, titolo, stato, copie_disponibili
            FROM libri
            WHERE (stato = 'disponibile' AND copie_disponibili = 0)
               OR (stato = 'prestato' AND copie_disponibili > 0)
        ");
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $issues[] = [
                    'type' => 'status_mismatch',
                    'message' => "Libro '{$row['titolo']}' (ID: {$row['id']}) ha stato '{$row['stato']}' ma copie disponibili: {$row['copie_disponibili']}"
                ];
            }
        }

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
        $stats = $this->db->query("
            SELECT
                (SELECT COUNT(*) FROM libri) as total_books,
                (SELECT COUNT(*) FROM prestiti) as total_loans,
                (SELECT COUNT(*) FROM prestiti WHERE stato IN ('in_corso', 'in_ritardo')) as active_loans,
                (SELECT COUNT(*) FROM prestiti WHERE stato = 'in_ritardo') as overdue_loans,
                (SELECT COUNT(*) FROM libri WHERE copie_disponibili > 0) as books_available,
                (SELECT COUNT(*) FROM libri WHERE copie_disponibili = 0) as books_unavailable
        ")->fetch_assoc();

        if ($stats) {
            $report['statistics'] = array_map('intval', $stats);
        }

        return $report;
    }
}