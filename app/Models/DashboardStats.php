<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;

class DashboardStats
{
    public function __construct(private mysqli $db) {}

    public function counts(): array
    {
        // Singola query invece di 5 query separate
        // Include count for pickup confirmations (origine='prenotazione') and manual requests separately
        // pickup_pronti: loans in 'da_ritirare' state OR 'prenotato' with data_prestito <= today
        $sql = "SELECT
                    (SELECT COUNT(*) FROM libri WHERE deleted_at IS NULL) AS libri,
                    (SELECT COUNT(*) FROM utenti) AS utenti,
                    (SELECT COUNT(*) FROM prestiti WHERE stato IN ('in_corso','in_ritardo') AND attivo = 1) AS prestiti_in_corso,
                    (SELECT COUNT(*) FROM autori) AS autori,
                    (SELECT COUNT(*) FROM prestiti WHERE stato = 'pendente') AS prestiti_pendenti,
                    (SELECT COUNT(*) FROM prestiti WHERE stato = 'pendente' AND origine = 'prenotazione') AS ritiri_da_confermare,
                    (SELECT COUNT(*) FROM prestiti WHERE stato = 'pendente' AND (origine = 'richiesta' OR origine IS NULL)) AS richieste_manuali,
                    (SELECT COUNT(*) FROM prestiti WHERE stato = 'da_ritirare' OR (stato = 'prenotato' AND data_prestito <= CURDATE())) AS pickup_pronti";

        $result = $this->db->query($sql);
        if ($result && $row = $result->fetch_assoc()) {
            return [
                'libri' => (int)($row['libri'] ?? 0),
                'utenti' => (int)($row['utenti'] ?? 0),
                'prestiti_in_corso' => (int)($row['prestiti_in_corso'] ?? 0),
                'autori' => (int)($row['autori'] ?? 0),
                'prestiti_pendenti' => (int)($row['prestiti_pendenti'] ?? 0),
                'ritiri_da_confermare' => (int)($row['ritiri_da_confermare'] ?? 0),
                'richieste_manuali' => (int)($row['richieste_manuali'] ?? 0),
                'pickup_pronti' => (int)($row['pickup_pronti'] ?? 0)
            ];
        }

        return ['libri' => 0, 'utenti' => 0, 'prestiti_in_corso' => 0, 'autori' => 0, 'prestiti_pendenti' => 0, 'ritiri_da_confermare' => 0, 'richieste_manuali' => 0, 'pickup_pronti' => 0];
    }

    public function lastBooks(int $limit = 4): array
    {
        $rows = [];
        $sql = "SELECT l.*,
                       GROUP_CONCAT(a.nome ORDER BY la.ruolo='principale' DESC, a.nome SEPARATOR ', ') AS autore
                FROM libri l
                LEFT JOIN libri_autori la ON l.id = la.libro_id
                LEFT JOIN autori a ON la.autore_id = a.id
                WHERE l.deleted_at IS NULL
                GROUP BY l.id
                ORDER BY l.created_at DESC LIMIT ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function activeLoans(): array
    {
        $rows = [];
        $sql = "SELECT p.*, l.titolo, l.id AS libro_id, CONCAT(u.nome, ' ', u.cognome) AS utente
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id
                JOIN utenti u ON p.utente_id = u.id
                WHERE p.stato IN ('in_corso','in_ritardo') AND p.attivo = 1";
        $res = $this->db->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    public function overdueLoans(): array
    {
        $rows = [];
        $sql = "SELECT p.*, l.titolo, l.id AS libro_id, CONCAT(u.nome, ' ', u.cognome) AS utente
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id
                JOIN utenti u ON p.utente_id = u.id
                WHERE p.stato='in_ritardo' AND p.attivo = 1";
        $res = $this->db->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    public function pendingLoans(int $limit = 4): array
    {
        $rows = [];
        $sql = "SELECT p.id, p.data_prestito AS data_richiesta_inizio, p.data_scadenza AS data_richiesta_fine,
                       p.created_at, l.titolo, l.copertina_url,
                       CONCAT(u.nome, ' ', u.cognome) AS utente_nome, u.email,
                       COALESCE(p.origine, 'richiesta') AS origine
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id
                JOIN utenti u ON p.utente_id = u.id
                WHERE p.stato = 'pendente'
                ORDER BY p.created_at ASC
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

    /**
     * Get loans ready for pickup (da_ritirare or prenotato with date reached)
     */
    public function pickupReadyLoans(int $limit = 6): array
    {
        $rows = [];
        $today = date('Y-m-d');
        $sql = "SELECT p.id, p.libro_id, p.utente_id, p.stato, p.data_prestito, p.data_scadenza,
                       p.pickup_deadline, p.created_at,
                       l.titolo, l.copertina_url,
                       CONCAT(u.nome, ' ', u.cognome) AS utente_nome, u.email
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id
                JOIN utenti u ON p.utente_id = u.id
                WHERE p.stato = 'da_ritirare'
                   OR (p.stato = 'prenotato' AND p.data_prestito <= ?)
                ORDER BY COALESCE(p.pickup_deadline, p.data_prestito) ASC
                LIMIT ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('si', $today, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Get scheduled loans (prenotato with future data_prestito)
     */
    public function scheduledLoans(int $limit = 6): array
    {
        $rows = [];
        $today = date('Y-m-d');
        $sql = "SELECT p.id, p.libro_id, p.utente_id, p.stato, p.data_prestito, p.data_scadenza,
                       p.created_at,
                       l.titolo, l.copertina_url,
                       CONCAT(u.nome, ' ', u.cognome) AS utente_nome, u.email
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id
                JOIN utenti u ON p.utente_id = u.id
                WHERE p.stato = 'prenotato' AND p.data_prestito > ? AND p.attivo = 1
                ORDER BY p.data_prestito ASC
                LIMIT ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('si', $today, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Get active reservations for dashboard display
     */
    public function activeReservations(int $limit = 6): array
    {
        $rows = [];
        $sql = "SELECT r.id, r.libro_id, r.utente_id, r.stato, r.created_at,
                       r.data_scadenza_prenotazione, r.data_inizio_richiesta, r.data_fine_richiesta,
                       l.titolo, l.copertina_url,
                       CONCAT(u.nome, ' ', u.cognome) AS utente_nome, u.email
                FROM prenotazioni r
                JOIN libri l ON r.libro_id = l.id
                JOIN utenti u ON r.utente_id = u.id
                WHERE r.stato = 'attiva'
                ORDER BY COALESCE(r.data_inizio_richiesta, r.data_scadenza_prenotazione) ASC
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

    /**
     * Get all calendar events (loans and reservations) for the next 6 months
     */
    public function calendarEvents(): array
    {
        $events = [];
        $today = date('Y-m-d');
        $sixMonthsLater = date('Y-m-d', strtotime('+6 months'));

        // Fetch active/scheduled loans (in_corso, prenotato, in_ritardo, pendente)
        // Include pendente loans with attivo=0 (waiting for admin approval)
        $loanSql = "SELECT p.id, p.stato, p.data_prestito, p.data_scadenza,
                           l.titolo, CONCAT(u.nome, ' ', u.cognome) AS utente_nome,
                           'prestito' AS tipo
                    FROM prestiti p
                    JOIN libri l ON p.libro_id = l.id
                    JOIN utenti u ON p.utente_id = u.id
                    WHERE (p.attivo = 1 OR p.stato = 'pendente')
                      AND p.stato IN ('in_corso', 'da_ritirare', 'prenotato', 'in_ritardo', 'pendente')
                      AND p.data_scadenza >= ?
                      AND p.data_prestito <= ?
                    ORDER BY p.data_prestito ASC";
        $stmt = $this->db->prepare($loanSql);
        $stmt->bind_param('ss', $today, $sixMonthsLater);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $events[] = [
                'id' => 'loan_' . $row['id'],
                'title' => $row['titolo'],
                'user' => $row['utente_nome'],
                'start' => $row['data_prestito'],
                'end' => $row['data_scadenza'],
                'type' => 'prestito',
                'status' => $row['stato']
            ];
        }
        $stmt->close();

        // Fetch active reservations (include all active, using today as fallback for NULL dates)
        $resSql = "SELECT r.id, r.stato, r.data_scadenza_prenotazione,
                          r.data_inizio_richiesta, r.data_fine_richiesta, r.created_at,
                          l.titolo, CONCAT(u.nome, ' ', u.cognome) AS utente_nome,
                          'prenotazione' AS tipo
                   FROM prenotazioni r
                   JOIN libri l ON r.libro_id = l.id
                   JOIN utenti u ON r.utente_id = u.id
                   WHERE r.stato = 'attiva'
                   ORDER BY COALESCE(r.data_inizio_richiesta, r.data_scadenza_prenotazione, r.created_at) ASC";
        $res = $this->db->query($resSql);
        while ($row = $res->fetch_assoc()) {
            // Use fallback dates: data_inizio_richiesta -> data_scadenza_prenotazione -> today
            $startDate = $row['data_inizio_richiesta'] ?? $row['data_scadenza_prenotazione'] ?? $today;
            $endDate = $row['data_fine_richiesta'] ?? $row['data_scadenza_prenotazione'] ?? $today;
            $events[] = [
                'id' => 'res_' . $row['id'],
                'title' => $row['titolo'],
                'user' => $row['utente_nome'],
                'start' => $startDate,
                'end' => $endDate,
                'type' => 'prenotazione',
                'status' => $row['stato']
            ];
        }

        return $events;
    }
}
