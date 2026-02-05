<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Import History Controller
 *
 * Manages import history viewing and error report downloads
 */
class ImportHistoryController
{
    /**
     * Show import history page
     */
    public function index(Request $request, Response $response, \mysqli $db): Response
    {
        // Get user ID from session
        $userId = $_SESSION['user_id'] ?? null;

        // Build query
        $query = "
            SELECT
                id,
                import_id,
                import_type,
                file_name,
                user_id,
                total_rows,
                imported,
                updated,
                failed,
                authors_created,
                publishers_created,
                scraped,
                started_at,
                completed_at,
                status
            FROM import_logs
            WHERE 1=1
        ";

        $params = [];
        $types = '';

        // Filter by user if not admin
        if ($userId && !$this->isAdmin()) {
            $query .= " AND user_id = ?";
            $params[] = $userId;
            $types .= 'i';
        }

        $query .= " ORDER BY started_at DESC LIMIT 100";

        $stmt = $db->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $imports = [];
        while ($row = $result->fetch_assoc()) {
            $imports[] = $row;
        }

        // Render view
        ob_start();
        include __DIR__ . '/../Views/admin/imports_history.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Download error report as CSV
     */
    public function downloadErrors(Request $request, Response $response, \mysqli $db): Response
    {
        $importId = $request->getQueryParams()['import_id'] ?? null;

        if (!$importId) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => __('ID import mancante')
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(400);
        }

        // Fetch import log
        $stmt = $db->prepare("
            SELECT file_name, errors_json, import_type, started_at, user_id
            FROM import_logs
            WHERE import_id = ?
        ");
        $stmt->bind_param('s', $importId);
        $stmt->execute();
        $result = $stmt->get_result();

        if (!($row = $result->fetch_assoc())) {
            $response->getBody()->write('Import non trovato');
            return $response->withStatus(404);
        }

        // Check permissions (users can only download their own imports, unless admin)
        if (!$this->isAdmin() && $row['user_id'] !== ($_SESSION['user_id'] ?? null)) {
            $response->getBody()->write('Non autorizzato');
            return $response->withStatus(403);
        }

        $errors = json_decode($row['errors_json'] ?? '[]', true) ?: [];

        // Generate CSV
        $csv = "\xEF\xBB\xBF"; // UTF-8 BOM for Excel compatibility
        $csv .= "Riga,Titolo,Tipo Errore,Messaggio\n";

        foreach ($errors as $error) {
            $csv .= sprintf(
                '"%d","%s","%s","%s"' . "\n",
                $error['line'] ?? 0,
                str_replace('"', '""', $error['title'] ?? ''),
                str_replace('"', '""', $error['type'] ?? 'unknown'),
                str_replace('"', '""', $error['message'] ?? '')
            );
        }

        // If no errors, add a message
        if (empty($errors)) {
            $csv .= "0,\"\",\"info\",\"Nessun errore registrato per questo import\"\n";
        }

        $fileName = sprintf(
            'import_errors_%s_%s.csv',
            $row['import_type'],
            date('Y-m-d_His', strtotime($row['started_at']))
        );

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"')
            ->withHeader('Cache-Control', 'no-cache, must-revalidate')
            ->withHeader('Expires', '0');
    }

    /**
     * Delete old import logs (admin only)
     * Useful for cleanup and GDPR compliance
     */
    public function deleteOldLogs(Request $request, Response $response, \mysqli $db): Response
    {
        if (!$this->isAdmin()) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => __('Non autorizzato')
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(403);
        }

        $data = $request->getParsedBody();
        $daysOld = (int)($data['days'] ?? 90);

        // Safety: minimum 7 days
        if ($daysOld < 7) {
            $daysOld = 7;
        }

        $stmt = $db->prepare("
            DELETE FROM import_logs
            WHERE started_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->bind_param('i', $daysOld);
        $stmt->execute();
        $deleted = $stmt->affected_rows;

        $response->getBody()->write(json_encode([
            'success' => true,
            'deleted' => $deleted,
            'message' => sprintf(__('%d import logs eliminati (più vecchi di %d giorni)'), $deleted, $daysOld)
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Check if current user is admin
     */
    private function isAdmin(): bool
    {
        return ($_SESSION['ruolo'] ?? '') === 'admin';
    }
}
