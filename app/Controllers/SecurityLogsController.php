<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SecurityLogsController
{
    public function index(Request $request, Response $response): Response
    {
        $logFile = __DIR__ . '/../../storage/security.log';
        $logs = [];
        $totalLines = 0;

        if (file_exists($logFile)) {
            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $totalLines = count($lines);

            // Get last 100 lines (most recent)
            $recentLines = array_slice($lines, -100);
            $recentLines = array_reverse($recentLines);

            foreach ($recentLines as $line) {
                $parsed = $this->parseLogLine($line);
                if ($parsed) {
                    $logs[] = $parsed;
                }
            }
        }

        ob_start();
        $data = [
            'logs' => $logs,
            'total_lines' => $totalLines
        ];
        require __DIR__ . '/../Views/admin/security_logs.php';
        $content = ob_get_clean();

        // Layout base
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    private function parseLogLine(string $line): ?array
    {
        // Format: 2025-01-30T12:34:56+00:00 [SECURITY:login.csrf_failed] {"email":"user@example.com",...}
        if (!preg_match('/^(\S+)\s+\[SECURITY:([^\]]+)\]\s+(.+)$/', $line, $matches)) {
            return null;
        }

        $timestamp = $matches[1];
        $type = $matches[2];
        $jsonData = $matches[3];

        $data = json_decode($jsonData, true);
        if (!is_array($data)) {
            return null;
        }

        // Extract common fields
        $email = $data['email'] ?? null;
        $ip = $data['ip'] ?? null;

        // Parse type (e.g., "login.csrf_failed" -> "csrf_failed")
        $typeParts = explode('.', $type);
        $typeShort = end($typeParts);

        return [
            'timestamp' => $timestamp,
            'type' => $typeShort,
            'email' => $email,
            'ip' => $ip,
            'data' => $data
        ];
    }
}
