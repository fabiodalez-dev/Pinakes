<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DeweyController
{
    public function categories(Request $request, Response $response, mysqli $db): Response
    {
        $rows = [];
        $res = $db->query("SELECT id, codice, nome FROM classificazione WHERE livello=1 ORDER BY codice");
        if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
        $response->getBody()->write(json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function divisions(Request $request, Response $response, mysqli $db): Response
    {
        $params = $request->getQueryParams();
        $cat = (int)($params['category_id'] ?? 0);
        $rows = [];
        $stmt = $db->prepare("SELECT id, codice, nome FROM classificazione WHERE parent_id=? AND livello=2 ORDER BY codice");
        $stmt->bind_param('i', $cat);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $response->getBody()->write(json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function specifics(Request $request, Response $response, mysqli $db): Response
    {
        $params = $request->getQueryParams();
        $div = (int)($params['division_id'] ?? 0);
        $rows = [];
        $stmt = $db->prepare("SELECT id, codice, nome FROM classificazione WHERE parent_id=? AND livello=3 ORDER BY codice");
        $stmt->bind_param('i', $div);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $response->getBody()->write(json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function reseed(Request $request, Response $response, mysqli $db): Response
    {
        $root = dirname(__DIR__, 2);

        // Validate and secure file paths to prevent path traversal
        $jsonPath = $root . '/data/dewey/levels.json';
        $jsonRealPath = realpath($jsonPath);
        $rootRealPath = realpath($root);

        // Ensure paths are within application directory
        if (!$jsonRealPath || !str_starts_with($jsonRealPath, $rootRealPath)) {
            $response->getBody()->write(json_encode(['ok'=>false,'error'=>'Invalid file path'], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        $data = [];
        if (is_file($jsonRealPath)) {
            $data = json_decode((string)file_get_contents($jsonRealPath), true) ?: [];
        }

        // Fallback: parse dewey.MD directly if JSON missing or empty
        if (empty($data['level1'])) {
            $mdPath = $root . '/dewey.MD';
            $mdRealPath = realpath($mdPath);

            // Validate markdown file path
            if (!$mdRealPath || !str_starts_with($mdRealPath, $rootRealPath)) {
                $response->getBody()->write(json_encode(['ok'=>false,'error'=>'Invalid markdown file path'], JSON_UNESCAPED_UNICODE));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }

            if (!is_file($mdRealPath)) {
                $response->getBody()->write(json_encode(['ok'=>false,'error'=>'levels.json empty and dewey.MD missing'], JSON_UNESCAPED_UNICODE));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }
            $html = (string)file_get_contents($mdRealPath);
            $dom = new \DOMDocument();
            \libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
            \libxml_clear_errors();
            $xp = new \DOMXPath($dom);
            // L1 from the UL after the H2 that mentions "Primo livello"
            $level1 = [];
            foreach ($xp->query("//h2[contains(., 'Primo livello')]") as $h2) {
                $node = $h2->parentNode; $ul = null; $n = $node->nextSibling; 
                while ($n && !($n instanceof \DOMElement)) { $n = $n->nextSibling; }
                while ($n) { if (strtolower($n->tagName)==='ul') { $ul=$n; break; } $n=$n->nextSibling; while ($n && !($n instanceof \DOMElement)) { $n=$n->nextSibling; } }
                if ($ul) {
                    foreach ($ul->getElementsByTagName('li') as $li) {
                        $text = trim($li->textContent);
                        if (preg_match('/^(\d{3})-\d{3}\s+(.+)$/u', $text, $m)) {
                            $c = $m[1];
                            $range = $c . '-' . str_pad((string)((int)$c + 99), 3, '0', STR_PAD_LEFT);
                            $title = $range . ' ' . trim($m[2]);
                            $level1[] = ['code'=>$c,'title'=>$title];
                        }
                    }
                }
            }
            // L2 & L3 from all li
            $codes = [];
            foreach ($xp->query('//li') as $li) {
                $t = trim($li->textContent);
                if (preg_match('/^(\d{3})\s+(.+)$/u', $t, $m)) { $codes[$m[1]] = trim($m[2]); }
            }
            ksort($codes, SORT_NUMERIC);
            $level2 = []; $level3 = [];
            foreach ($codes as $code => $name) {
                if (((int)$code) % 100 === 0) continue; // skip L1
                if (((int)$code) % 10 === 0) {
                    $parent = sprintf('%03d', ((int)$code / 100) * 100);
                    $level2[] = ['code'=>$code,'title'=>$name,'parent'=>$parent];
                } else {
                    $parentDiv = sprintf('%03d', ((int)$code / 10) * 10);
                    $level3[] = ['code'=>$code,'title'=>$name,'parent'=>$parentDiv];
                }
            }
            $data = ['level1'=>$level1,'level2'=>$level2,'level3'=>$level3];
        }
        $db->query("SET FOREIGN_KEY_CHECKS=0");
        $db->query("CREATE TABLE IF NOT EXISTS classificazione (
          id INT AUTO_INCREMENT PRIMARY KEY,
          codice VARCHAR(16) NOT NULL,
          nome VARCHAR(255) NOT NULL,
          livello TINYINT NOT NULL,
          parent_id INT NULL,
          created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          KEY idx_codice (codice), KEY idx_livello (livello), KEY idx_parent (parent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->query("TRUNCATE TABLE classificazione");
        $db->query("SET FOREIGN_KEY_CHECKS=1");

        // Insert L1
        $ins1 = $db->prepare("INSERT INTO classificazione (codice, nome, livello, parent_id) VALUES (?,?,1,NULL)");
        foreach ($data['level1'] ?? [] as $row) {
            $code = (string)($row['code'] ?? $row['codice'] ?? '');
            $name = (string)($row['title'] ?? $row['nome'] ?? '');
            if ($code === '' || $name === '') continue;
            $ins1->bind_param('ss', $code, $name);
            $ins1->execute();
        }
        // Map L1
        $mapL1 = [];
        if ($res = $db->query("SELECT id,codice FROM classificazione WHERE livello=1")) {
            while ($r = $res->fetch_assoc()) { $mapL1[$r['codice']] = (int)$r['id']; }
        }
        // Insert L2
        $ins2 = $db->prepare("INSERT INTO classificazione (codice, nome, livello, parent_id) VALUES (?,?,2,?)");
        foreach ($data['level2'] ?? [] as $row) {
            $code = (string)($row['code'] ?? $row['codice'] ?? '');
            $name = (string)($row['title'] ?? $row['nome'] ?? '');
            $parent = (string)($row['parent'] ?? '');
            $pid = (int)($mapL1[$parent] ?? 0);
            if ($code === '' || $name === '' || $pid<=0) continue;
            $ins2->bind_param('ssi', $code, $name, $pid);
            $ins2->execute();
        }
        // Map L2
        $mapL2 = [];
        if ($res2 = $db->query("SELECT id,codice FROM classificazione WHERE livello=2")) {
            while ($r = $res2->fetch_assoc()) { $mapL2[$r['codice']] = (int)$r['id']; }
        }
        // Insert L3
        $ins3 = $db->prepare("INSERT INTO classificazione (codice, nome, livello, parent_id) VALUES (?,?,3,?)");
        foreach ($data['level3'] ?? [] as $row) {
            $code = (string)($row['code'] ?? $row['codice'] ?? '');
            $name = (string)($row['title'] ?? $row['nome'] ?? '');
            $parent = (string)($row['parent'] ?? '');
            $pid = (int)($mapL2[$parent] ?? 0);
            if ($code === '' || $name === '' || $pid<=0) continue;
            $ins3->bind_param('ssi', $code, $name, $pid);
            $ins3->execute();
        }
        // Counts
        $c1 = (int)($db->query("SELECT COUNT(*) AS c FROM classificazione WHERE livello=1")->fetch_assoc()['c'] ?? 0);
        $c2 = (int)($db->query("SELECT COUNT(*) AS c FROM classificazione WHERE livello=2")->fetch_assoc()['c'] ?? 0);
        $c3 = (int)($db->query("SELECT COUNT(*) AS c FROM classificazione WHERE livello=3")->fetch_assoc()['c'] ?? 0);
        $response->getBody()->write(json_encode(['ok'=>true,'counts'=>['l1'=>$c1,'l2'=>$c2,'l3'=>$c3]], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function counts(Request $request, Response $response, mysqli $db): Response
    {
        $c1 = (int)($db->query("SELECT COUNT(*) AS c FROM classificazione WHERE livello=1")->fetch_assoc()['c'] ?? 0);
        $c2 = (int)($db->query("SELECT COUNT(*) AS c FROM classificazione WHERE livello=2")->fetch_assoc()['c'] ?? 0);
        $c3 = (int)($db->query("SELECT COUNT(*) AS c FROM classificazione WHERE livello=3")->fetch_assoc()['c'] ?? 0);
        $total = $c1 + $c2 + $c3;
        $response->getBody()->write(json_encode(['l1'=>$c1,'l2'=>$c2,'l3'=>$c3,'total'=>$total], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function adminPage(Request $request, Response $response, mysqli $db): Response
    {
        ob_start();
        require __DIR__ . '/../Views/admin/dewey.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }
}
