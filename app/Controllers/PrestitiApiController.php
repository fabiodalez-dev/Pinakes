<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use App\Support\Log as AppLog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PrestitiApiController
{
    public function list(Request $request, Response $response, mysqli $db): Response
    {
        $q = $request->getQueryParams();
        $draw = (int)($q['draw'] ?? 0);
        $start  = max(0, (int)($q['start'] ?? 0));
        $length = max(1, min(500, (int)($q['length'] ?? 10)));
        $utente_id = (int)($q['utente_id'] ?? 0);
        $libro_id = (int)($q['libro_id'] ?? 0);
        $attivo = trim((string)($q['attivo'] ?? ''));
        $from = trim((string)($q['from'] ?? ''));
        $to   = trim((string)($q['to'] ?? ''));
        $statoSpecifico = trim((string)($q['stato_specifico'] ?? ''));
        $search_value = trim((string)($q['search_value'] ?? ''));

        $base = "FROM prestiti p
                 LEFT JOIN libri l ON p.libro_id=l.id AND l.deleted_at IS NULL
                 LEFT JOIN utenti u ON p.utente_id=u.id
                 LEFT JOIN utenti staff ON p.processed_by=staff.id";

        // Build parameterized query to prevent SQL injection
        $params = [];
        $param_types = '';
        $where_prepared = " WHERE 1=1 ";

        if ($utente_id) {
            $where_prepared .= ' AND p.utente_id = ? ';
            $params[] = $utente_id;
            $param_types .= 'i';
        }
        if ($libro_id) {
            $where_prepared .= ' AND p.libro_id = ? ';
            $params[] = $libro_id;
            $param_types .= 'i';
        }
        if ($attivo !== '') {
            $where_prepared .= " AND p.attivo = ? ";
            $params[] = (int)$attivo;
            $param_types .= 'i';
        }
        if ($from !== '') {
            $where_prepared .= " AND p.data_prestito >= ? ";
            $params[] = $from;
            $param_types .= 's';
        }
        if ($to !== '') {
            $where_prepared .= " AND p.data_prestito <= ? ";
            $params[] = $to;
            $param_types .= 's';
        }
        if ($statoSpecifico !== '') {
            $where_prepared .= " AND p.stato = ? ";
            $params[] = $statoSpecifico;
            $param_types .= 's';
        }
        if ($search_value !== '') {
            $where_prepared .= " AND (l.titolo LIKE ?
                          OR CONCAT(u.nome, ' ', u.cognome) LIKE ?
                          OR CONCAT(staff.nome, ' ', staff.cognome) LIKE ?) ";
            $search_param = "%$search_value%";
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $param_types .= 'sss';
        }

        // Count total records with prepared statement
        $total_sql = 'SELECT COUNT(*) AS c FROM prestiti';
        $total_stmt = $db->prepare($total_sql);
        if (!$total_stmt) {
            AppLog::error('prestiti.total.prepare_failed', ['error' => $db->error]);
            $response->getBody()->write(json_encode(['error' => __('Errore interno del database. Riprova più tardi.')], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        
        $total_stmt->execute();
        $total_res = $total_stmt->get_result();
        $total = (int)($total_res->fetch_assoc()['c'] ?? 0);

        // Use prepared statement for filtered count to prevent SQL injection
        $count_sql = "SELECT COUNT(*) AS c $base $where_prepared";
        $count_stmt = $db->prepare($count_sql);
        if (!$count_stmt) {
            AppLog::error('prestiti.count.prepare_failed', ['error' => $db->error]);
            $response->getBody()->write(json_encode(['error' => __('Errore interno del database. Riprova più tardi.')], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        
        if (!empty($params)) {
            $count_stmt->bind_param($param_types, ...$params);
        }
        $count_stmt->execute();
        $filteredRes = $count_stmt->get_result();
        $filtered = (int)($filteredRes->fetch_assoc()['c'] ?? 0);

        // Handle DataTables ordering
        $orderColumn = 'p.id';
        $orderDir = 'DESC';

        // Map column index to database column
        // 0: libro, 1: utente, 2: data_prestito, 3: stato, 4: actions
        $columnMap = [
            0 => 'l.titolo',       // Libro
            1 => 'utente',         // Utente (computed column)
            2 => 'p.data_prestito',// Data Prestito
            3 => 'p.stato',        // Stato
            4 => 'p.id'            // Actions (fallback to id)
        ];

        // Parse order parameter from DataTables
        if (isset($q['order'][0]['column']) && isset($q['order'][0]['dir'])) {
            $colIdx = (int) $q['order'][0]['column'];
            $dir = strtoupper($q['order'][0]['dir']) === 'DESC' ? 'DESC' : 'ASC';

            if (isset($columnMap[$colIdx])) {
                $orderColumn = $columnMap[$colIdx];
                $orderDir = $dir;
            }
        }

        // Add LIMIT parameters
        $params[] = $start;
        $params[] = $length;
        $param_types .= 'ii';

        $sql_prepared = "SELECT p.id, l.titolo AS libro, CONCAT(u.nome,' ',u.cognome) AS utente,
                       p.data_prestito, p.data_restituzione, p.attivo, p.stato,
                       CONCAT(staff.nome,' ',staff.cognome) AS processed_by
                $base $where_prepared
                ORDER BY $orderColumn $orderDir LIMIT ?, ?";

        $stmt = $db->prepare($sql_prepared);
        if (!$stmt) {
            AppLog::error('prestiti.list.prepare_failed', ['error' => $db->error]);
            $response->getBody()->write(json_encode(['error' => __('Errore interno del database. Riprova più tardi.')], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        $stmt->bind_param($param_types, ...$params);

        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $actions = '<a class="text-blue-600" href="'.htmlspecialchars(url('/admin/prestiti/dettagli/'.(int)$r['id']), ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars(__('Dettagli'), ENT_QUOTES, 'UTF-8').'</a>';
            $actions .= ' | <a class="text-orange-600" href="'.htmlspecialchars(url('/admin/prestiti/modifica/'.(int)$r['id']), ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars(__('Modifica'), ENT_QUOTES, 'UTF-8').'</a>';
            if ((int)$r['attivo'] === 1) {
                $actions .= ' | <a class="text-green-600" href="'.htmlspecialchars(url('/admin/prestiti/restituito/'.(int)$r['id']), ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars(__('Restituito'), ENT_QUOTES, 'UTF-8').'</a>';
            }
            $rows[] = [
                'id' => (int)$r['id'],
                'libro' => $r['libro'] ?? '',
                'utente' => $r['utente'] ?? '',
                'data_prestito' => $r['data_prestito'] ?? '',
                'data_restituzione' => $r['data_restituzione'] ?? '',
                'processed_by' => $r['processed_by'] ?? '',
                'attivo' => (int)$r['attivo'],
                'stato' => $r['stato'] ?? ((int)$r['attivo']===1 ? 'in_corso' : 'restituito'),
                'actions' => $actions,
            ];
        }

        $payload = [
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $rows
        ];
        AppLog::debug('prestiti.list.result', ['total'=>$total, 'filtered'=>$filtered, 'rows'=>count($rows)]);
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
