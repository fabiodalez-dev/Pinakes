<?php
declare(strict_types=1);

namespace App\Repositories;

use mysqli;
use Exception;

class RecensioniRepository
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Verifica se un utente può recensire un libro
     * (deve aver completato almeno un prestito del libro)
     */
    public function canUserReview(int $userId, int $libroId): bool
    {
        try {
            // Verifica se l'utente ha già recensito questo libro
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM recensioni
                WHERE utente_id = ? AND libro_id = ?
            ");
            $stmt->bind_param('ii', $userId, $libroId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            if ($row['count'] > 0) {
                return false; // Già recensito
            }

            // Verifica se l'utente ha completato almeno un prestito del libro
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM prestiti
                WHERE utente_id = ?
                  AND libro_id = ?
                  AND stato IN ('restituito', 'in_corso')
            ");
            $stmt->bind_param('ii', $userId, $libroId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            return $row['count'] > 0;

        } catch (Exception $e) {
            error_log("Error checking if user can review: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Crea una nuova recensione
     */
    public function createReview(array $data): ?int
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO recensioni (libro_id, utente_id, stelle, titolo, descrizione, stato, data_recensione)
                VALUES (?, ?, ?, ?, ?, 'pendente', NOW())
            ");

            $stmt->bind_param(
                'iiiss',
                $data['libro_id'],
                $data['utente_id'],
                $data['stelle'],
                $data['titolo'],
                $data['descrizione']
            );

            if ($stmt->execute()) {
                $reviewId = $stmt->insert_id;
                $stmt->close();
                return $reviewId;
            }

            error_log('RecensioniRepository::createReview execute error: ' . $stmt->error);
            $stmt->close();
            return null;

        } catch (Exception $e) {
            error_log("Error creating review: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Ottiene le recensioni in attesa di approvazione
     */
    public function getPendingReviews(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT r.*,
                       l.titolo as libro_titolo,
                       l.copertina_url as libro_copertina,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome,
                       u.email as utente_email
                FROM recensioni r
                JOIN libri l ON r.libro_id = l.id
                JOIN utenti u ON r.utente_id = u.id
                WHERE r.stato = 'pendente'
                ORDER BY r.created_at DESC
            ");

            $stmt->execute();
            $result = $stmt->get_result();
            $reviews = [];

            while ($row = $result->fetch_assoc()) {
                $reviews[] = $row;
            }

            $stmt->close();
            return $reviews;

        } catch (Exception $e) {
            error_log("Error getting pending reviews: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Ottiene tutte le recensioni (con filtro opzionale per stato)
     */
    public function getAllReviews(?string $stato = null): array
    {
        try {
            $sql = "
                SELECT r.*,
                       l.titolo as libro_titolo,
                       l.copertina_url as libro_copertina,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome,
                       u.email as utente_email,
                       CONCAT(a.nome, ' ', a.cognome) as approved_by_name
                FROM recensioni r
                JOIN libri l ON r.libro_id = l.id
                JOIN utenti u ON r.utente_id = u.id
                LEFT JOIN utenti a ON r.approved_by = a.id
            ";

            if ($stato) {
                $sql .= " WHERE r.stato = ?";
            }

            $sql .= " ORDER BY r.created_at DESC";

            if ($stato) {
                $stmt = $this->db->prepare($sql);
                $stmt->bind_param('s', $stato);
            } else {
                $stmt = $this->db->prepare($sql);
            }

            $stmt->execute();
            $result = $stmt->get_result();
            $reviews = [];

            while ($row = $result->fetch_assoc()) {
                $reviews[] = $row;
            }

            $stmt->close();
            return $reviews;

        } catch (Exception $e) {
            error_log("Error getting all reviews: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Ottiene le recensioni approvate per un libro specifico
     */
    public function getApprovedReviewsForBook(int $libroId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT r.*,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome,
                       u.email as utente_email
                FROM recensioni r
                JOIN utenti u ON r.utente_id = u.id
                WHERE r.libro_id = ?
                  AND r.stato = 'approvata'
                ORDER BY r.approved_at DESC, r.created_at DESC
            ");

            $stmt->bind_param('i', $libroId);
            $stmt->execute();
            $result = $stmt->get_result();
            $reviews = [];

            while ($row = $result->fetch_assoc()) {
                $reviews[] = $row;
            }

            $stmt->close();
            return $reviews;

        } catch (Exception $e) {
            error_log("Error getting approved reviews for book: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Ottiene statistiche recensioni per un libro
     */
    public function getReviewStats(int $libroId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(*) as total_reviews,
                    AVG(stelle) as average_rating,
                    SUM(CASE WHEN stelle = 1 THEN 1 ELSE 0 END) as one_star,
                    SUM(CASE WHEN stelle = 2 THEN 1 ELSE 0 END) as two_star,
                    SUM(CASE WHEN stelle = 3 THEN 1 ELSE 0 END) as three_star,
                    SUM(CASE WHEN stelle = 4 THEN 1 ELSE 0 END) as four_star,
                    SUM(CASE WHEN stelle = 5 THEN 1 ELSE 0 END) as five_star
                FROM recensioni
                WHERE libro_id = ? AND stato = 'approvata'
            ");

            $stmt->bind_param('i', $libroId);
            $stmt->execute();
            $result = $stmt->get_result();
            $stats = $result->fetch_assoc();
            $stmt->close();

            return [
                'total_reviews' => (int)($stats['total_reviews'] ?? 0),
                'average_rating' => round((float)($stats['average_rating'] ?? 0), 2),
                'one_star' => (int)($stats['one_star'] ?? 0),
                'two_star' => (int)($stats['two_star'] ?? 0),
                'three_star' => (int)($stats['three_star'] ?? 0),
                'four_star' => (int)($stats['four_star'] ?? 0),
                'five_star' => (int)($stats['five_star'] ?? 0),
            ];

        } catch (Exception $e) {
            error_log("Error getting review stats: " . $e->getMessage());
            return [
                'total_reviews' => 0,
                'average_rating' => 0,
                'one_star' => 0,
                'two_star' => 0,
                'three_star' => 0,
                'four_star' => 0,
                'five_star' => 0,
            ];
        }
    }

    /**
     * Approva una recensione
     */
    public function approveReview(int $reviewId, int $adminId): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE recensioni
                SET stato = 'approvata',
                    approved_by = ?,
                    approved_at = NOW()
                WHERE id = ?
            ");

            $stmt->bind_param('ii', $adminId, $reviewId);
            $result = $stmt->execute();
            $stmt->close();

            return $result;

        } catch (Exception $e) {
            error_log("Error approving review: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Rifiuta una recensione
     */
    public function rejectReview(int $reviewId, int $adminId): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE recensioni
                SET stato = 'rifiutata',
                    approved_by = ?,
                    approved_at = NOW()
                WHERE id = ?
            ");

            $stmt->bind_param('ii', $adminId, $reviewId);
            $result = $stmt->execute();
            $stmt->close();

            return $result;

        } catch (Exception $e) {
            error_log("Error rejecting review: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Elimina una recensione
     */
    public function deleteReview(int $reviewId): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM recensioni WHERE id = ?");
            $stmt->bind_param('i', $reviewId);
            $result = $stmt->execute();
            $stmt->close();

            return $result;

        } catch (Exception $e) {
            error_log("Error deleting review: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Ottiene una recensione specifica
     */
    public function getReview(int $reviewId): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT r.*,
                       l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome
                FROM recensioni r
                JOIN libri l ON r.libro_id = l.id
                JOIN utenti u ON r.utente_id = u.id
                WHERE r.id = ?
            ");

            $stmt->bind_param('i', $reviewId);
            $stmt->execute();
            $result = $stmt->get_result();
            $review = $result->fetch_assoc();
            $stmt->close();

            return $review ?: null;

        } catch (Exception $e) {
            error_log("Error getting review: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Conta recensioni in attesa
     */
    public function countPendingReviews(): int
    {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM recensioni WHERE stato = 'pendente'");
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            return (int)($row['count'] ?? 0);

        } catch (Exception $e) {
            error_log("Error counting pending reviews: " . $e->getMessage());
            return 0;
        }
    }
}
