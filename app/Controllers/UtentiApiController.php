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
        $start  = max(0, (int)($q['start'] ?? 0));
        $length = max(1, min(500, (int)($q['length'] ?? 10)));
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
        $total_stmt->close();

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
        // 0: nome/cognome, 1: email, 2: telefono, 3: tipo_utente, 4: stato
        $columnMap = [
            0 => 'u.cognome',      // Nome (sorts by cognome first)
            1 => 'u.email',        // Email
            2 => 'u.telefono',     // Telefono
            3 => 'u.tipo_utente',  // Ruolo
            4 => 'u.stato',        // Stato
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
        if (!$stmt) {
            AppLog::error('utenti.list.prepare_failed', ['error' => $db->error]);
            $response->getBody()->write(json_encode(['error' => __('Errore interno del database. Riprova più tardi.')], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
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

    /**
     * Server-side PDF export of the (filtered) users list. Applies the SAME filters as
     * list() — search_text / role_filter / status_filter / created_from — so the PDF is
     * "what you see", then streams a TCPDF built by UsersPdfGenerator (Unicode-safe).
     */
    public function exportPdf(Request $request, Response $response, mysqli $db): Response
    {
        $q = $request->getQueryParams();

        $where = ' WHERE 1=1 ';
        $params = [];
        $types = '';

        $search_value = trim((string)($q['search_text'] ?? ($q['search_value'] ?? '')));
        if ($search_value !== '') {
            $searchParam = "%$search_value%";
            $where .= ' AND (u.nome LIKE ? OR u.cognome LIKE ? OR u.email LIKE ? OR u.telefono LIKE ? OR u.codice_tessera LIKE ?)';
            $params = array_fill(0, 5, $searchParam);
            $types = 'sssss';
        }
        $role = trim((string)($q['role_filter'] ?? ''));
        if ($role !== '') {
            $where .= ' AND u.tipo_utente = ? ';
            $params[] = $role;
            $types .= 's';
        }
        $status = trim((string)($q['status_filter'] ?? ''));
        if ($status !== '') {
            $where .= ' AND u.stato = ? ';
            $params[] = $status;
            $types .= 's';
        }
        $createdFrom = trim((string)($q['created_from'] ?? ''));
        if ($createdFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $createdFrom)) {
            $where .= ' AND DATE(u.created_at) >= ? ';
            $params[] = $createdFrom;
            $types .= 's';
        }

        // Unfiltered total for the header summary ("N filtered out of M total").
        $total = 0;
        if ($totRes = $db->query('SELECT COUNT(*) AS c FROM utenti')) {
            $total = (int)($totRes->fetch_assoc()['c'] ?? 0);
        }

        // All matching rows, capped so a very large library can't build a runaway PDF.
        $sql = "SELECT u.nome, u.cognome, u.email, u.telefono, u.tipo_utente, u.stato, u.codice_tessera
                FROM utenti u $where
                ORDER BY u.cognome ASC, u.nome ASC
                LIMIT 5000";
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            AppLog::error('utenti.export.prepare_failed', ['error' => $db->error]);
            return $response->withStatus(500);
        }
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            // Localize the enum labels the same way the on-screen columns present them.
            $r['tipo_utente'] = __((string)($r['tipo_utente'] ?? ''));
            $r['stato'] = __((string)($r['stato'] ?? ''));
            $rows[] = $r;
        }
        $stmt->close();

        $pdf = (new \App\Support\UsersPdfGenerator())->generate($rows, $total);
        $filename = 'utenti_' . date('Ymd') . '.pdf';
        $response->getBody()->write($pdf);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
}
