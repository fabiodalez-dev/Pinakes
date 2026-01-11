<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;

class CollocationRepository
{
    public function __construct(private mysqli $db) {}

    public function getScaffali(): array
    {
        $rows = [];
        $res = $this->db->query("SELECT id, codice, nome, COALESCE(ordine,0) AS ordine FROM scaffali ORDER BY ordine ASC, codice ASC");
        if ($res) {
            while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        }
        return $rows;
    }

    public function getMensole(): array
    {
        $rows = [];
        $sql = "SELECT m.id, m.scaffale_id, m.numero_livello,
                s.codice AS scaffale_codice, s.nome AS scaffale_nome
                FROM mensole m JOIN scaffali s ON m.scaffale_id = s.id
                ORDER BY m.ordine ASC, m.scaffale_id, m.numero_livello ASC";
        $res = $this->db->query($sql);
        if ($res) {
            while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        }
        return $rows;
    }

    public function getPosizioni(): array
    {
        $rows = [];
        $sql = "SELECT p.id, p.scaffale_id, p.mensola_id,
                s.codice AS scaffale_codice, s.nome AS scaffale_nome,
                m.numero_livello
                FROM posizioni p
                JOIN scaffali s ON p.scaffale_id = s.id
                JOIN mensole m ON p.mensola_id = m.id
                ORDER BY p.ordine ASC, p.scaffale_id, p.mensola_id";
        $res = $this->db->query($sql);
        if ($res) {
            while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        }
        return $rows;
    }

    public function getScaffaleLetter(int $id): ?string
    {
        $stmt = $this->db->prepare("SELECT COALESCE(codice, lettera) AS codice FROM scaffali WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($codice);
        if ($stmt->fetch()) { return $codice; }
        return null;
    }

    public function getMensolaLevel(int $mensolaId): ?int
    {
        $stmt = $this->db->prepare('SELECT numero_livello FROM mensole WHERE id=? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $mensolaId);
        $stmt->execute();
        $stmt->bind_result($level);
        if ($stmt->fetch()) {
            return $level !== null ? (int)$level : null;
        }
        return null;
    }

    public function buildCollocazioneString(?int $scaffaleId, ?int $mensolaId, ?int $posizioneProgressiva): string
    {
        if (!$scaffaleId || !$mensolaId || !$posizioneProgressiva) {
            return '';
        }

        $scaffaleCode = $this->getScaffaleLetter($scaffaleId);
        if ($scaffaleCode === null || $scaffaleCode === '') {
            return '';
        }

        $level = $this->getMensolaLevel($mensolaId);
        if ($level === null || $level <= 0) {
            return '';
        }

        return sprintf('%s-%d-%02d', strtoupper(trim($scaffaleCode)), $level, $posizioneProgressiva);
    }

    public function computeNextProgressiva(int $scaffaleId, int $mensolaId, ?int $excludeBookId = null): int
    {
        $sql = 'SELECT MAX(posizione_progressiva) AS max_pos FROM libri WHERE scaffale_id = ? AND mensola_id = ? AND deleted_at IS NULL';
        $types = 'ii';
        $params = [$scaffaleId, $mensolaId];

        if ($excludeBookId !== null && $excludeBookId > 0) {
            $sql .= ' AND id <> ?';
            $types .= 'i';
            $params[] = $excludeBookId;
        }

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return 1;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $max = $row && isset($row['max_pos']) ? (int)$row['max_pos'] : 0;
        return $max + 1;
    }

    public function isProgressivaOccupied(int $scaffaleId, int $mensolaId, int $progressiva, ?int $excludeBookId = null): bool
    {
        $sql = 'SELECT id FROM libri WHERE scaffale_id = ? AND mensola_id = ? AND posizione_progressiva = ? AND deleted_at IS NULL';
        $types = 'iii';
        $params = [$scaffaleId, $mensolaId, $progressiva];

        if ($excludeBookId !== null && $excludeBookId > 0) {
            $sql .= ' AND id <> ?';
            $types .= 'i';
            $params[] = $excludeBookId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        return !empty($row);
    }

    public function createScaffale(array $data): int
    {
        $codice = strtoupper(trim((string)($data['codice'] ?? '')));
        $nome = trim((string)($data['nome'] ?? ''));
        $ordine = (int)($data['ordine'] ?? 0);
        // Extract first letter from codice for lettera field
        $lettera = mb_substr($codice, 0, 1);

        // Check duplicate by codice
        $chk = $this->db->prepare("SELECT id FROM scaffali WHERE codice=? LIMIT 1");
        $chk->bind_param('s', $codice);
        $chk->execute();
        $exists = $chk->get_result()->fetch_assoc();
        if ($exists) {
            throw new \RuntimeException('Scaffale con questo codice già esistente');
        }

        // Check duplicate by lettera
        $chk = $this->db->prepare("SELECT id FROM scaffali WHERE lettera=? LIMIT 1");
        $chk->bind_param('s', $lettera);
        $chk->execute();
        $exists = $chk->get_result()->fetch_assoc();
        if ($exists) {
            throw new \RuntimeException('Scaffale con questa lettera già esistente');
        }

        $stmt = $this->db->prepare("INSERT INTO scaffali (codice, nome, lettera, ordine) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('sssi', $codice, $nome, $lettera, $ordine);
        $stmt->execute();
        return (int)$this->db->insert_id;
    }

    public function createMensola(array $data): int
    {
        $scaffale_id = (int)($data['scaffale_id'] ?? 0);
        $numero_livello = (int)($data['numero_livello'] ?? 1);
        $ordine = (int)($data['ordine'] ?? 0);
        $descrizione = (int)($data['descrizione'] ?? 0);
        $stmt = $this->db->prepare("INSERT INTO mensole (scaffale_id, numero_livello, ordine, descrizione) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('iiii', $scaffale_id, $numero_livello, $ordine, $descrizione);
        $stmt->execute();
        return (int)$this->db->insert_id;
    }

    public function createPosizioni(int $scaffale_id, int $mensola_id, int $n): void
    {
        $n = max(0, $n);
        if ($n === 0) return;
        $stmt = $this->db->prepare("INSERT INTO posizioni (scaffale_id, mensola_id, ordine) VALUES (?, ?, ?)");
        for ($i=1; $i<=$n; $i++) {
            $ordine = $i;
            $stmt->bind_param('iii', $scaffale_id, $mensola_id, $ordine);
            $stmt->execute();
        }
    }

    private function getRootByGenere(int $genereId): ?array
    {
        if ($genereId <= 0) return null;
        $stmt = $this->db->prepare("SELECT parent2.id AS radice_id, parent2.nome AS radice_nome
            FROM generi g
            LEFT JOIN generi parent ON g.parent_id = parent.id
            LEFT JOIN generi parent2 ON parent.parent_id = parent2.id
            WHERE g.id = ?");
        $stmt->bind_param('i', $genereId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        if ($res && $res['radice_id']) return ['id'=>(int)$res['radice_id'], 'nome'=>$res['radice_nome']];
        // If g is mid-level (its parent is root), return that root
        $stmt2 = $this->db->prepare("SELECT parent.id AS radice_id, parent.nome AS radice_nome
            FROM generi g
            LEFT JOIN generi parent ON g.parent_id = parent.id
            WHERE g.id = ?");
        $stmt2->bind_param('i', $genereId);
        $stmt2->execute();
        $r = $stmt2->get_result()->fetch_assoc();
        return $r && $r['radice_id'] ? ['id'=>(int)$r['radice_id'], 'nome'=>$r['radice_nome']] : null;
    }

    public function suggestByGenre(int $genereId, int $sottogenereId = 0): array
    {
        $result = [
            'scaffale_id' => null, 'mensola_id' => null, 'posizione_id' => null,
            'collocazione' => '', 'reason' => ''
        ];

        // 1) Prefer scaffale più usato per questo (sotto)genere
        $bestScaffale = null; $reason = '';
        $stmt = null;
        if ($sottogenereId > 0) {
            $stmt = $this->db->prepare("SELECT l.scaffale_id, COUNT(*) c FROM libri l WHERE l.sottogenere_id = ? AND l.scaffale_id IS NOT NULL AND l.deleted_at IS NULL GROUP BY l.scaffale_id ORDER BY c DESC LIMIT 1");
            $stmt->bind_param('i', $sottogenereId);
        } elseif ($genereId > 0) {
            $stmt = $this->db->prepare("SELECT l.scaffale_id, COUNT(*) c FROM libri l WHERE l.genere_id = ? AND l.scaffale_id IS NOT NULL AND l.deleted_at IS NULL GROUP BY l.scaffale_id ORDER BY c DESC LIMIT 1");
            $stmt->bind_param('i', $genereId);
        }
        if ($stmt) { $stmt->execute(); $r = $stmt->get_result()->fetch_assoc(); if ($r) { $bestScaffale = (int)$r['scaffale_id']; $reason = 'scaffale più usato per il genere'; } }

        // 2) Se non trovato, mappa radice -> range scaffali (A–I, J–R, S–Z)
        if (!$bestScaffale && $genereId > 0) {
            $root = $this->getRootByGenere($genereId);
            $preferredRange = [range('A','I'), range('J','R'), range('S','Z')];
            $roots = ['Prosa','Poesia','Teatro'];
            $prefLetters = [];
            if ($root && in_array($root['nome'], $roots, true)) {
                $idx = array_search($root['nome'], $roots, true);
                $prefLetters = $preferredRange[$idx];
            }
            $scaffali = $this->getScaffali();
            foreach ($scaffali as $s) {
                if (empty($prefLetters) || in_array(strtoupper($s['lettera']), $prefLetters, true)) {
                    $bestScaffale = (int)$s['id'];
                    $reason = 'mappatura radice-genere';
                    break;
                }
            }
        }

        // 3) Fallback: primo scaffale per ordine
        if (!$bestScaffale) {
            $scaffali = $this->getScaffali();
            if (!empty($scaffali)) { $bestScaffale = (int)$scaffali[0]['id']; $reason = 'fallback primo scaffale'; }
        }

        if (!$bestScaffale) return $result; // Nessuno scaffale in DB
        $result['scaffale_id'] = $bestScaffale;

        // Trova mensola meno occupata nello scaffale
        $mensolaId = null; $stmt = $this->db->prepare("SELECT m.id, COALESCE(cnt.cnt,0) AS c
            FROM mensole m
            LEFT JOIN (
                SELECT p.mensola_id, COUNT(*) cnt
                FROM posizioni p
                JOIN libri l ON l.posizione_id = p.id AND l.deleted_at IS NULL
                WHERE p.scaffale_id = ?
                GROUP BY p.mensola_id
            ) cnt ON cnt.mensola_id = m.id
            WHERE m.scaffale_id = ?
            ORDER BY c ASC, m.numero_livello ASC, m.id ASC LIMIT 1");
        $stmt->bind_param('ii', $bestScaffale, $bestScaffale);
        $stmt->execute(); $r = $stmt->get_result()->fetch_assoc();
        if ($r) { $mensolaId = (int)$r['id']; }
        $result['mensola_id'] = $mensolaId;

        // Trova prima posizione libera
        $posId = null;
        if ($mensolaId) {
            $stmt = $this->db->prepare("SELECT p.id
                FROM posizioni p
                LEFT JOIN libri l ON l.posizione_id = p.id AND l.deleted_at IS NULL
                WHERE p.scaffale_id = ? AND p.mensola_id = ? AND l.id IS NULL
                ORDER BY p.ordine ASC, p.id ASC LIMIT 1");
            $stmt->bind_param('ii', $bestScaffale, $mensolaId);
            $stmt->execute(); $r = $stmt->get_result()->fetch_assoc();
            if ($r) { $posId = (int)$r['id']; }
        }
        $result['posizione_id'] = $posId;

        // Crea stringa collocazione se possibile
        $letter = $this->getScaffaleLetter($bestScaffale) ?? '';
        $level = null;
        if ($mensolaId) {
            $st = $this->db->prepare("SELECT numero_livello FROM mensole WHERE id=? LIMIT 1");
            $st->bind_param('i', $mensolaId); $st->execute(); $st->bind_result($level); $st->fetch();
        }
        $posOrder = null;
        if ($posId) {
            $st2 = $this->db->prepare("SELECT ordine FROM posizioni WHERE id=? LIMIT 1");
            $st2->bind_param('i', $posId); $st2->execute(); $st2->bind_result($posOrder); $st2->fetch();
        }
        if ($letter) {
            $parts = [$letter];
            if ($level) $parts[] = (string)$level;
            if ($posOrder) $parts[] = str_pad((string)$posOrder, 2, '0', STR_PAD_LEFT);
            $result['collocazione'] = implode('-', $parts);
        }
        $result['reason'] = $reason;
        return $result;
    }

    public function updateOrder(string $table, array $ids): void
    {
        $allowed = ['scaffali','mensole','posizioni'];
        if (!in_array($table, $allowed, true)) return;
        $order = 0;
        $stmt = $this->db->prepare("UPDATE `$table` SET ordine=? WHERE id=?");
        foreach ($ids as $id) {
            $order++;
            $iid = (int)$id;
            $stmt->bind_param('ii', $order, $iid);
            $stmt->execute();
        }
    }
}
