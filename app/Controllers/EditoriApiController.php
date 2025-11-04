<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use App\Support\Log as AppLog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EditoriApiController
{
    public function list(Request $request, Response $response, mysqli $db): Response
    {
        $q = $request->getQueryParams();
        $draw = (int)($q['draw'] ?? 0);
        $start = (int)($q['start'] ?? 0);
        $length = (int)($q['length'] ?? 10);

        $search_text = trim((string)($q['search_text'] ?? ''));
        $search_sito = trim((string)($q['search_sito'] ?? ''));
        $created_from = trim((string)($q['created_from'] ?? ''));

        // Prepare WHERE clause and parameters for prepared statements
        $where_prepared = 'WHERE 1=1 ';
        $params = [];
        $param_types = '';
        $params_for_where = [];
        $param_types_for_where = '';

        if ($search_text !== '') {
            $where_prepared .= " AND e.nome LIKE ? ";
            $params_for_where[] = "%$search_text%";
            $param_types_for_where .= 's';
            $params[] = "%$search_text%";
            $param_types .= 's';
        }
        if ($search_sito !== '') {
            $where_prepared .= " AND e.sito_web LIKE ? ";
            $params_for_where[] = "%$search_sito%";
            $param_types_for_where .= 's';
            $params[] = "%$search_sito%";
            $param_types .= 's';
        }
        if ($created_from !== '') {
            $where_prepared .= " AND e.created_at >= ? ";
            $params_for_where[] = $created_from;
            $param_types_for_where .= 's';
            $params[] = $created_from;
            $param_types .= 's';
        }

        // Add LIMIT parameters
        $params[] = $start;
        $params[] = $length;
        $param_types .= 'ii';

        // Count total records with prepared statement
        $total_sql = 'SELECT COUNT(*) AS c FROM editori e';
        $total_stmt = $db->prepare($total_sql);
        if (!$total_stmt) {
            AppLog::error('editori.total.prepare_failed', ['error' => $db->error]);
            $response->getBody()->write(json_encode(['error' => 'Database prepare failed'], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        
        $total_stmt->execute();
        $total_res = $total_stmt->get_result();
        $total = (int)($total_res->fetch_assoc()['c'] ?? 0);

        // Use prepared statement for filtered count to prevent SQL injection
        $count_sql = "SELECT COUNT(*) AS c FROM editori e $where_prepared";
        $count_stmt = $db->prepare($count_sql);
        if (!$count_stmt) {
            AppLog::error('editori.count.prepare_failed', ['error' => $db->error]);
            $response->getBody()->write(json_encode(['error' => 'Database prepare failed'], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        
        if (!empty($params_for_where)) {
            $count_stmt->bind_param($param_types_for_where, ...$params_for_where);
        }
        $count_stmt->execute();
        $filRes = $count_stmt->get_result();
        $filtered = (int)($filRes->fetch_assoc()['c'] ?? 0);

        $sql_prepared = "SELECT e.*, (
                    SELECT COUNT(*) FROM libri l WHERE l.editore_id = e.id
                ) AS libri_count
                FROM editori e
                $where_prepared
                ORDER BY e.nome ASC
                LIMIT ?, ?";

        AppLog::debug('editori.list.query', ['params'=>array_intersect_key($q, ['draw'=>1,'start'=>1,'length'=>1,'search_text'=>1,'search_sito'=>1,'created_from'=>1])]);

        $stmt = $db->prepare($sql_prepared);
        if (!$stmt) {
            AppLog::error('editori.list.prepare_failed', ['error' => $db->error]);
            $response->getBody()->write(json_encode(['error' => 'Database prepare failed'], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        if (!empty($params)) {
            $stmt->bind_param($param_types, ...$params);
        }

        $stmt->execute();
        $res = $stmt->get_result();

        $data = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) { $data[] = $row; }
        }

        $payload = [
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $data
        ];
        AppLog::debug('editori.list.result', ['total'=>$total, 'filtered'=>$filtered, 'rows'=>count($data)]);
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
