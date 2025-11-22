<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;

class UserRepository
{
    public function __construct(private mysqli $db) {}

    public function listBasic(int $limit = 100): array
    {
        $rows = [];
        $sql = "SELECT id, nome, cognome, email, tipo_utente FROM utenti ORDER BY cognome, nome LIMIT ?";
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

