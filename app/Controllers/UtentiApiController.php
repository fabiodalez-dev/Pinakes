<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use App\Support\Log as AppLog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UtentiApiController
{
    public function list(Request $request, Response $response, mysqli $db): Response
    {
        $q = $request->getQueryParams();
        $draw = (int)($q['draw'] ?? 0);
        $start = (int)($q['start'] ?? 0);
        $length = (int)($q['length'] ?? 10);
        $search_value = trim((string)($q['search_text'] ?? ($q['search_value'] ?? '')));

        $base = "FROM utenti u";
        $where = " WHERE 1=1 ";
        $params = [];
        $types = '';

        if ($search_value !== '') {
            $searchParam = "%$search_value%";
            $where .= " AND (u.nome LIKE ?
                          OR u.cognome LIKE ?
                          OR u.email LIKE ?
                          OR u.telefono LIKE ?
                          OR u.codice_tessera LIKE ?)";
            $params = array_fill(0, 5, $searchParam);
            $types = 'sssss';
        }

        // Additional filters
        $role = trim((string)($q['role_filter'] ?? ''));
        if ($role !== '') {
            $where .= " AND u.tipo_utente = ? ";
            $params[] = $role;
            $types .= 's';
        }
        $status = trim((string)($q['status_filter'] ?? ''));
        if ($status !== '') {
            $where .= " AND u.stato = ? ";
            $params[] = $status;
            $types .= 's';
        }
        $createdFrom = trim((string)($q['created_from'] ?? ''));
        if ($createdFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $createdFrom)) {
            $where .= " AND DATE(u.created_at) >= ? ";
            $params[] = $createdFrom;
            $types .= 's';
        }

        // Count total records with prepared statement
        $total_sql = 'SELECT COUNT(*) AS c FROM utenti';
        $total_stmt = $db->prepare($total_sql);
        if (!$total_stmt) {
            AppLog::error('utenti.total.prepare_failed', ['error' => $db->error]);
            $response->getBody()->write(json_encode(['error' => __('Errore interno del database. Riprova più tardi.')], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        
        $total_stmt->execute();
        $total_res = $total_stmt->get_result();
        $total = (int)($total_res->fetch_assoc()['c'] ?? 0);

        // Use prepared statement for filtered count
        $count_sql = "SELECT COUNT(*) AS c $base $where";
        $count_stmt = $db->prepare($count_sql);
        if (!$count_stmt) {
            AppLog::error('utenti.count.prepare_failed', ['error' => $db->error]);
            $response->getBody()->write(json_encode(['error' => __('Errore interno del database. Riprova più tardi.')], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        
        if (!empty($params)) {
            $count_stmt->bind_param($types, ...$params);
        }
        $count_stmt->execute();
        $filtered_res = $count_stmt->get_result();
        $filtered = (int)($filtered_res->fetch_assoc()['c'] ?? 0);
        $count_stmt->close();

        // Handle DataTables ordering
        $orderColumn = 'u.cognome';
        $orderDir = 'ASC';
        $orderExtra = ', u.nome ASC'; // Secondary sort by nome

        // Map column index to database column
        // 0: nome/cognome, 1: email, 2: telefono, 3: tipo_utente, 4: stato, 5: actions
        $columnMap = [
            0 => 'u.cognome',      // Nome (sorts by cognome first)
            1 => 'u.email',        // Email
            2 => 'u.telefono',     // Telefono
            3 => 'u.tipo_utente',  // Ruolo
            4 => 'u.stato',        // Stato
            5 => 'u.id'            // Actions (fallback to id)
        ];

        // Parse order parameter from DataTables
        if (isset($q['order'][0]['column']) && isset($q['order'][0]['dir'])) {
            $colIdx = (int) $q['order'][0]['column'];
            $dir = strtoupper($q['order'][0]['dir']) === 'DESC' ? 'DESC' : 'ASC';

            if (isset($columnMap[$colIdx])) {
                $orderColumn = $columnMap[$colIdx];
                $orderDir = $dir;
                // Only add secondary sort for nome column
                $orderExtra = ($colIdx === 0) ? ', u.nome ' . $dir : '';
            }
        }

        $sql = "SELECT u.id, u.nome, u.cognome, u.email, u.telefono, u.tipo_utente, u.stato,
                       u.codice_tessera, u.data_scadenza_tessera, u.created_at
                $base $where
                ORDER BY $orderColumn $orderDir$orderExtra LIMIT ?, ?";

        // Add LIMIT parameters
        $params[] = $start;
        $params[] = $length;
        $types .= 'ii';

        $stmt = $db->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $actions = '<a class="text-blue-600" href="/utenti/dettagli/'.(int)$r['id'].'">'.__('Dettagli').'</a>';
            $actions .= ' | <a class="text-orange-600" href="/utenti/modifica/'.(int)$r['id'].'">'.__('Modifica').'</a>';
            $confirmMessage = __('Eliminare l\'utente?');
            $actions .= ' | <form method="post" action="/utenti/delete/'.(int)$r['id'].'" style="display:inline" onsubmit="return confirm(\'' . addslashes($confirmMessage) . '\')">'
                     . '<input type="hidden" name="csrf_token" value="'.htmlspecialchars(\App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8').'">'
                     . '<button class="text-red-600">'.__('Elimina').'</button></form>';
                     
            $rows[] = [
                'id' => (int)$r['id'],
                'nome' => $r['nome'] ?? '',
                'cognome' => $r['cognome'] ?? '',
                'email' => $r['email'] ?? '',
                'telefono' => $r['telefono'] ?? '',
                'tipo_utente' => $r['tipo_utente'] ?? 'standard',
                'stato' => $r['stato'] ?? 'attivo',
                'codice_tessera' => $r['codice_tessera'] ?? '',
                'data_scadenza_tessera' => $r['data_scadenza_tessera'] ?? '',
                'created_at' => $r['created_at'] ?? '',
                'actions' => $actions,
            ];
        }
        $stmt->close();

        $payload = [
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $rows
        ];
        AppLog::debug('utenti.list.result', ['total'=>$total, 'filtered'=>$filtered, 'rows'=>count($rows)]);
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
