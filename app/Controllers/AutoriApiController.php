<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use App\Support\Log as AppLog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AutoriApiController
{
    public function list(Request $request, Response $response, mysqli $db): Response
    {
        $q = $request->getQueryParams();
        $draw = (int)($q['draw'] ?? 0);
        $start = (int)($q['start'] ?? 0);
        $length = (int)($q['length'] ?? 10);

        $search_text = trim((string)($q['search_text'] ?? ''));
        $search_nome = trim((string)($q['search_nome'] ?? ''));
        $search_pseudonimo = trim((string)($q['search_pseudonimo'] ?? ''));
        $search_sito = trim((string)($q['search_sito'] ?? ''));
        $search_naz = trim((string)($q['search_nazionalita'] ?? ''));
        $nascita_from = trim((string)($q['nascita_from'] ?? ''));
        $nascita_to   = trim((string)($q['nascita_to'] ?? ''));
        $morte_from = trim((string)($q['morte_from'] ?? ''));
        $morte_to   = trim((string)($q['morte_to'] ?? ''));

        // Se la tabella non esiste (database appena creato/backup parziale) restituiamo payload vuoto
        $tableCheck = $db->query("SHOW TABLES LIKE 'autori'");
        $hasAutoriTable = $tableCheck && $tableCheck->num_rows > 0;
        if ($tableCheck instanceof \mysqli_result) {
            $tableCheck->free();
        }
        if (!$hasAutoriTable) {
            $payload = [
                'draw' => $draw,
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => []
            ];
            $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $colNaz = $this->resolveColumn($db, 'autori', ['nazionalita', 'nazionalità']);

        // Build parameterized query to prevent SQL injection
        $params = [];
        $param_types = '';

        // Rebuild WHERE clause for prepared statement
        $where_prepared = 'WHERE 1=1 ';
        $params_for_where = [];
        $param_types_for_where = '';

        if ($search_text !== '') {
            $where_prepared .= " AND (a.nome LIKE ? OR a.pseudonimo LIKE ? OR a.biografia LIKE ?) ";
            $params_for_where[] = "%$search_text%";
            $params_for_where[] = "%$search_text%";
            $params_for_where[] = "%$search_text%";
            $param_types_for_where .= 'sss';
        }
        if ($search_nome !== '') {
            $where_prepared .= " AND a.nome LIKE ? ";
            $params_for_where[] = "%$search_nome%";
            $param_types_for_where .= 's';
        }
        if ($search_pseudonimo !== '') {
            $where_prepared .= " AND a.pseudonimo LIKE ? ";
            $params_for_where[] = "%$search_pseudonimo%";
            $param_types_for_where .= 's';
        }
        if ($search_sito !== '') {
            $where_prepared .= " AND a.sito_web LIKE ? ";
            $params_for_where[] = "%$search_sito%";
            $param_types_for_where .= 's';
        }
        if ($search_naz !== '' && $colNaz !== null) {
            $where_prepared .= " AND a.`$colNaz` LIKE ? ";
            $params_for_where[] = "%$search_naz%";
            $param_types_for_where .= 's';
        }
        if ($nascita_from !== '') {
            $where_prepared .= " AND a.data_nascita >= ? ";
            $params_for_where[] = $nascita_from;
            $param_types_for_where .= 's';
        }
        if ($nascita_to !== '') {
            $where_prepared .= " AND a.data_nascita <= ? ";
            $params_for_where[] = $nascita_to;
            $param_types_for_where .= 's';
        }
        if ($morte_from !== '') {
            $where_prepared .= " AND a.data_morte >= ? ";
            $params_for_where[] = $morte_from;
            $param_types_for_where .= 's';
        }
        if ($morte_to !== '') {
            $where_prepared .= " AND a.data_morte <= ? ";
            $params_for_where[] = $morte_to;
            $param_types_for_where .= 's';
        }

        // Use prepared statement for total count
        $total_sql = 'SELECT COUNT(*) AS c FROM autori a';
        $total_stmt = $db->query($total_sql);
        if (!$total_stmt) {
            AppLog::error('autori.total.query_failed', ['error' => $db->error]);
            $response->getBody()->write(json_encode(['error' => __('Errore interno del database. Riprova più tardi.')], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        $totalRow = $total_stmt->fetch_assoc();
        $total = (int)($totalRow['c'] ?? 0);
        $total_stmt->free();

        // Use prepared statement for filtered count to prevent SQL injection
        $count_sql = "SELECT COUNT(*) AS c FROM autori a $where_prepared";
        $count_stmt = $db->prepare($count_sql);
        if (!$count_stmt) {
            AppLog::error('autori.count.prepare_failed', ['error' => $db->error]);
            $response->getBody()->write(json_encode(['error' => __('Errore interno del database. Riprova più tardi.')], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        
        if (!empty($params_for_where)) {
            $count_stmt->bind_param($param_types_for_where, ...$params_for_where);
        }
        $count_stmt->execute();
        $filRes = $count_stmt->get_result();
        $filtered = (int)($filRes->fetch_assoc()['c'] ?? 0);

        // Add WHERE clause parameters for main query
        $params = $params_for_where;
        $param_types = $param_types_for_where;

        // Add LIMIT parameters
        $params[] = $start;
        $params[] = $length;
        $param_types .= 'ii';

        $selectNaz = $colNaz !== null ? "a.`$colNaz` AS nazionalita" : "'' AS nazionalita";
        $sql_prepared = "SELECT a.id, a.nome, a.pseudonimo, a.data_nascita, a.data_morte, a.biografia, a.sito_web,
                       $selectNaz,
                       (SELECT COUNT(*) FROM libri_autori la WHERE la.autore_id = a.id) AS libri_count
                FROM autori a $where_prepared ORDER BY a.nome ASC LIMIT ?, ?";

        $stmt = $db->prepare($sql_prepared);
        if (!$stmt) {
            AppLog::error('autori.list.prepare_failed', ['error' => $db->error]);
            $response->getBody()->write(json_encode(['error' => __('Errore interno del database. Riprova più tardi.')], JSON_UNESCAPED_UNICODE));
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
        AppLog::debug('autori.list.result', ['total'=>$total, 'filtered'=>$filtered, 'rows'=>count($data)]);
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function columnExists(mysqli $db, string $table, string $column): bool
    {
        $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->num_rows > 0;
    }

    private function resolveColumn(mysqli $db, string $table, array $candidates): ?string
    {
        $stmt = $db->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        if (!$stmt) {
            AppLog::warning('autori.resolve_column.prepare_failed', ['table' => $table, 'error' => $db->error]);
            return null;
        }
        $stmt->bind_param('s', $table);
        if (!$stmt->execute()) {
            AppLog::warning('autori.resolve_column.execute_failed', ['table' => $table, 'error' => $stmt->error]);
            $stmt->close();
            return null;
        }
        $stmt->bind_result($columnName);
        while ($stmt->fetch()) {
            foreach ($candidates as $candidate) {
                if ($columnName === $candidate) {
                    $stmt->close();
                    return $columnName;
                }
            }
        }
        $stmt->close();
        return null;
    }
}
