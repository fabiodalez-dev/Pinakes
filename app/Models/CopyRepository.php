<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;

class CopyRepository
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Ottiene tutte le copie di un libro
     */
    public function getByBookId(int $bookId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*,
                   p.id as prestito_id,
                   p.utente_id,
                   p.data_prestito,
                   p.data_scadenza,
                   p.stato as prestito_stato,
                   u.nome as utente_nome,
                   u.cognome as utente_cognome,
                   u.email as utente_email
            FROM copie c
            LEFT JOIN prestiti p ON c.id = p.copia_id AND p.attivo = 1 AND p.stato IN ('pendente', 'prenotato', 'in_corso', 'in_ritardo')
            LEFT JOIN utenti u ON p.utente_id = u.id
            WHERE c.libro_id = ?
            ORDER BY c.numero_inventario ASC
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();

        $copie = [];
        while ($row = $result->fetch_assoc()) {
            $copie[] = $row;
        }

        $stmt->close();
        return $copie;
    }

    /**
     * Ottiene una copia specifica per ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT c.*,
                   l.titolo as libro_titolo
            FROM copie c
            JOIN libri l ON c.libro_id = l.id
            WHERE c.id = ?
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $copia = $result->fetch_assoc();
        $stmt->close();

        return $copia ?: null;
    }

    /**
     * Crea una nuova copia
     */
    public function create(int $bookId, string $numeroInventario, string $stato = 'disponibile', ?string $note = null): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO copie (libro_id, numero_inventario, stato, note)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param('isss', $bookId, $numeroInventario, $stato, $note);
        $stmt->execute();
        $insertId = $this->db->insert_id;
        $stmt->close();

        return $insertId;
    }

    /**
     * Aggiorna lo stato di una copia
     */
    public function updateStatus(int $id, string $stato): bool
    {
        $stmt = $this->db->prepare("UPDATE copie SET stato = ? WHERE id = ?");
        $stmt->bind_param('si', $stato, $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Elimina una copia
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM copie WHERE id = ?");
        $stmt->bind_param('i', $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Ottiene le copie disponibili di un libro (non assegnate a prestiti attivi o futuri)
     */
    public function getAvailableByBookId(int $bookId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*
            FROM copie c
            LEFT JOIN prestiti p ON c.id = p.copia_id AND p.attivo = 1 AND p.stato IN ('in_corso', 'in_ritardo', 'prenotato', 'pendente')
            WHERE c.libro_id = ?
            AND c.stato = 'disponibile'
            AND p.id IS NULL
            ORDER BY c.numero_inventario ASC
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();

        $copie = [];
        while ($row = $result->fetch_assoc()) {
            $copie[] = $row;
        }

        $stmt->close();
        return $copie;
    }

    /**
     * Ottiene le copie disponibili per un libro in un periodo specifico
     * Esclude copie con prestiti sovrapposti al periodo richiesto
     * @param int $bookId ID del libro
     * @param string $startDate Data inizio periodo (Y-m-d)
     * @param string $endDate Data fine periodo (Y-m-d)
     * @return array Array di copie disponibili per il periodo
     */
    public function getAvailableByBookIdForDateRange(int $bookId, string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*
            FROM copie c
            WHERE c.libro_id = ?
            AND c.stato NOT IN ('perso', 'danneggiato', 'manutenzione')
            AND NOT EXISTS (
                SELECT 1 FROM prestiti p
                WHERE p.copia_id = c.id
                AND p.attivo = 1
                AND p.stato IN ('in_corso', 'prenotato', 'in_ritardo', 'pendente')
                AND p.data_prestito <= ?
                AND p.data_scadenza >= ?
            )
            ORDER BY c.numero_inventario ASC
        ");
        $stmt->bind_param('iss', $bookId, $endDate, $startDate);
        $stmt->execute();
        $result = $stmt->get_result();

        $copie = [];
        while ($row = $result->fetch_assoc()) {
            $copie[] = $row;
        }

        $stmt->close();
        return $copie;
    }

    /**
     * Conta il numero di copie disponibili per un libro (non assegnate a prestiti attivi o futuri)
     */
    public function countAvailableByBookId(int $bookId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM copie c
            LEFT JOIN prestiti p ON c.id = p.copia_id AND p.attivo = 1 AND p.stato IN ('in_corso', 'in_ritardo', 'prenotato', 'pendente')
            WHERE c.libro_id = ?
            AND c.stato = 'disponibile'
            AND p.id IS NULL
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return (int)($row['count'] ?? 0);
    }

    /**
     * Conta il numero totale di copie per un libro
     */
    public function countByBookId(int $bookId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM copie WHERE libro_id = ?");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return (int)($row['count'] ?? 0);
    }

    /**
     * Genera numero inventario univoco per una nuova copia
     */
    public function generateInventoryNumber(int $bookId, string $baseNumber): string
    {
        // Conta quante copie esistono già per questo libro
        $count = $this->countByBookId($bookId);

        if ($count === 0) {
            return $baseNumber;
        }

        // Genera numero con suffisso incrementale
        return "{$baseNumber}-C" . ($count + 1);
    }

    /**
     * Aggiorna note di una copia
     */
    public function updateNotes(int $id, ?string $note): bool
    {
        $stmt = $this->db->prepare("UPDATE copie SET note = ? WHERE id = ?");
        $stmt->bind_param('si', $note, $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }
}
