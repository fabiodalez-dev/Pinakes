<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;

class TaxonomyRepository
{
    public function __construct(private mysqli $db) {}

    public function genres(): array
    {
        return $this->all('SELECT id, nome FROM generi ORDER BY nome');
    }
    public function subgenres(): array
    {
        // Dopo la migrazione, sottogeneri sono gestiti nella tabella `generi` come figli con parent_id non nullo
        return $this->all('SELECT id, nome FROM generi WHERE parent_id IS NOT NULL ORDER BY nome');
    }
    private function all(string $sql): array
    {
        $rows=[]; $res=$this->db->query($sql); if($res){ while($r=$res->fetch_assoc()){ $rows[]=$r; } }
        return $rows;
    }
}
