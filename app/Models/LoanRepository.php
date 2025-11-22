<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;
use App\Controllers\ReservationManager;
use App\Support\DataIntegrity;

class LoanRepository
{
    public function __construct(private mysqli $db) {}

    public function listRecent(int $limit = 100): array
    {
        $rows = [];
        $sql = "SELECT p.id, l.titolo AS libro_titolo, CONCAT(u.nome,' ',u.cognome) AS utente_nome,
                       u.email as utente_email, p.data_prestito, p.data_scadenza, p.data_restituzione, p.stato, p.attivo
                FROM prestiti p
                LEFT JOIN libri l ON p.libro_id = l.id
                LEFT JOIN utenti u ON p.utente_id = u.id
                ORDER BY p.id DESC
                LIMIT ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function getById(int $id): ?array
    {
        $sql = "SELECT p.*, l.titolo AS libro, CONCAT(u.nome,' ',u.cognome) AS utente
                FROM prestiti p
                LEFT JOIN libri l ON p.libro_id = l.id
                LEFT JOIN utenti u ON p.utente_id = u.id
                WHERE p.id=? LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        return $res->fetch_assoc() ?: null;
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_restituzione, processed_by, attivo)
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $data_prestito = $data['data_prestito'] ?? date('Y-m-d');
        $data_restituzione = $data['data_restituzione'] ?? null;
        $processed_by = $data['processed_by'] ?? null;
        $attivo = (int)($data['attivo'] ?? 1);
        $stmt->bind_param('iissii', $data['libro_id'], $data['utente_id'], $data_prestito, $data_restituzione, $processed_by, $attivo);
        $stmt->execute();
        return (int)$this->db->insert_id;
    }

    public function update(int $id, array $data): bool
    {
        $sql = "UPDATE prestiti SET libro_id=?, utente_id=?, data_prestito=?, data_scadenza=?, data_restituzione=?, processed_by=?, attivo=? WHERE id=?";
        $stmt = $this->db->prepare($sql);
        $data_prestito = $data['data_prestito'] ?? date('Y-m-d');
        $data_scadenza = $data['data_scadenza'] ?? date('Y-m-d', strtotime('+14 days'));
        $data_restituzione = $data['data_restituzione'] ?? null;
        $processed_by = $data['processed_by'] ?? null;
        $attivo = (int)($data['attivo'] ?? 1);
        $stmt->bind_param('iisssiii', $data['libro_id'], $data['utente_id'], $data_prestito, $data_scadenza, $data_restituzione, $processed_by, $attivo, $id);
        return $stmt->execute();
    }

    public function getActiveLoanByBook(int $bookId): ?array
    {
        $sql = "SELECT p.*, 
                       CONCAT(u.nome, ' ', u.cognome) AS utente_nome,
                       u.email AS utente_email,
                       u.id AS utente_id,
                       CONCAT(staff.nome, ' ', staff.cognome) AS processed_by_name
                FROM prestiti p
                LEFT JOIN utenti u ON p.utente_id = u.id
                LEFT JOIN utenti staff ON p.processed_by = staff.id
                WHERE p.libro_id = ?
                  AND p.attivo = 1
                  AND p.stato IN ('in_corso','in_ritardo')
                ORDER BY p.data_prestito DESC
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $res = $stmt->get_result();
        $loan = $res->fetch_assoc();
        $stmt->close();

        return $loan ?: null;
    }

    public function close(int $id): bool
    {
        $this->db->begin_transaction();

        $bookId = null;

        try {
            // Recupera il libro associato e blocca la riga del prestito
            $stmt = $this->db->prepare('SELECT libro_id FROM prestiti WHERE id=? FOR UPDATE');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $bookId = (int)$row['libro_id'];
            } else {
                $stmt->close();
                $this->db->rollback();
                return false;
            }
            $stmt->close();

            // Chiude il prestito
            $today = gmdate('Y-m-d');
            $stmt = $this->db->prepare('UPDATE prestiti SET attivo=0, data_restituzione=?, stato="restituito" WHERE id=?');
            $stmt->bind_param('si', $today, $id);
            if (!$stmt->execute()) {
                throw new \RuntimeException('Impossibile aggiornare lo stato del prestito.');
            }
            $stmt->close();

            // Determina se il libro ha altri prestiti attivi
            $activeCount = 0;
            $countStmt = $this->db->prepare("SELECT COUNT(*) AS c FROM prestiti WHERE libro_id=? AND attivo=1 AND stato IN ('in_corso','in_ritardo')");
            $countStmt->bind_param('i', $bookId);
            $countStmt->execute();
            $activeCount = (int)($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
            $countStmt->close();

            $newStatus = $activeCount > 0 ? 'prestato' : 'disponibile';
            $updateBookStmt = $this->db->prepare('UPDATE libri SET stato = ? WHERE id = ?');
            $updateBookStmt->bind_param('si', $newStatus, $bookId);
            $updateBookStmt->execute();
            $updateBookStmt->close();

            $this->db->commit();

        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        if ($bookId !== null) {
            try {
                $integrity = new DataIntegrity($this->db);
                $integrity->recalculateBookAvailability($bookId);
                $integrity->validateAndUpdateLoan($id);
            } catch (\Throwable $e) {
                error_log('DataIntegrity warning (close loan): ' . $e->getMessage());
            }

            $reservationManager = new ReservationManager($this->db);
            $reservationManager->processBookAvailability($bookId);
        }

        return true;
    }
}
