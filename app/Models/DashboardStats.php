<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;

class DashboardStats
{
    public function __construct(private mysqli $db) {}

    public function counts(): array
    {
        $c = ['libri' => 0, 'utenti' => 0, 'prestiti_in_corso' => 0, 'autori' => 0, 'prestiti_pendenti' => 0];
        $c['libri'] = (int)($this->db->query("SELECT COUNT(*) AS c FROM libri")->fetch_assoc()['c'] ?? 0);
        $c['utenti'] = (int)($this->db->query("SELECT COUNT(*) AS c FROM utenti")->fetch_assoc()['c'] ?? 0);
        $c['prestiti_in_corso'] = (int)($this->db->query("SELECT COUNT(*) AS c FROM prestiti WHERE stato IN ('in_corso','in_ritardo') AND attivo = 1")->fetch_assoc()['c'] ?? 0);
        $c['autori'] = (int)($this->db->query("SELECT COUNT(*) AS c FROM autori")->fetch_assoc()['c'] ?? 0);
        $c['prestiti_pendenti'] = (int)($this->db->query("SELECT COUNT(*) AS c FROM prestiti WHERE stato='pendente'")->fetch_assoc()['c'] ?? 0);
        return $c;
    }

    public function lastBooks(int $limit = 4): array
    {
        $rows = [];
        $sql = "SELECT l.*,
                       GROUP_CONCAT(a.nome ORDER BY la.ruolo='principale' DESC, a.nome SEPARATOR ', ') AS autore
                FROM libri l
                LEFT JOIN libri_autori la ON l.id = la.libro_id
                LEFT JOIN autori a ON la.autore_id = a.id
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
                       CONCAT(u.nome, ' ', u.cognome) AS utente_nome, u.email
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
}
