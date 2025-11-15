<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GeneriApiController
{
    public function search(Request $request, Response $response, \mysqli $db): Response
    {
        $query = trim((string)($request->getQueryParams()['q'] ?? ''));
        $limit = min(50, (int)($request->getQueryParams()['limit'] ?? 20));

        $results = [];
        if (strlen($query) >= 1) {
            $searchTerm = "%{$query}%";
            $stmt = $db->prepare("
                SELECT g.id, g.nome, g.parent_id,
                       p.nome AS parent_nome,
                       CASE WHEN g.parent_id IS NULL THEN 'genere' ELSE 'sottogenere' END AS tipo
                FROM generi g
                LEFT JOIN generi p ON g.parent_id = p.id
                WHERE g.nome LIKE ?
                ORDER BY
                    CASE WHEN g.nome LIKE ? THEN 0 ELSE 1 END,
                    g.parent_id IS NULL DESC,
                    g.nome
                LIMIT ?
            ");
            $exactMatch = "{$query}%";
            $stmt->bind_param('ssi', $searchTerm, $exactMatch, $limit);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $label = $row['nome'];
                if ($row['parent_nome']) {
                    $label .= " ({$row['parent_nome']})";
                }

                $results[] = [
                    'id' => (int)$row['id'],
                    'label' => $label,
                    'nome' => $row['nome'],
                    'parent_id' => $row['parent_id'] ? (int)$row['parent_id'] : null,
                    'parent_nome' => $row['parent_nome'],
                    'tipo' => $row['tipo']
                ];
            }
        }

        $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function create(Request $request, Response $response, \mysqli $db): Response
    {
        $csrfToken = $request->getHeaderLine('X-CSRF-Token');
        if (!\App\Support\Csrf::validate($csrfToken)) {
            $response->getBody()->write(json_encode(['error' => __('Token CSRF non valido')]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $data = $request->getParsedBody();
        $nome = trim((string)($data['nome'] ?? ''));
        $parent_id = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;

        if (empty($nome)) {
            $response->getBody()->write(json_encode(['error' => __('Il nome del genere è obbligatorio.')]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Verifica se già esistente
        $stmt = $db->prepare("SELECT id FROM generi WHERE nome = ? AND parent_id <=> ?");
        $stmt->bind_param('si', $nome, $parent_id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();

        if ($existing) {
            $response->getBody()->write(json_encode([
                'id' => (int)$existing['id'],
                'nome' => $nome,
                'exists' => true
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        // Inserisci nuovo genere
        $stmt = $db->prepare("INSERT INTO generi (nome, parent_id, created_at) VALUES (?, ?, NOW())");
        $decodedNome = \App\Support\HtmlHelper::decode($nome);
        $stmt->bind_param('si', $decodedNome, $parent_id);

        if ($stmt->execute()) {
            $id = $db->insert_id;
            $response->getBody()->write(json_encode([
                'id' => (int)$id,
                'nome' => $nome,
                'parent_id' => $parent_id,
                'created' => true
            ]));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode(['error' => __('Errore nella creazione del genere.')]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    public function listGeneri(Request $request, Response $response, \mysqli $db): Response
    {
        $limit = min(100, (int)($request->getQueryParams()['limit'] ?? 50));
        $onlyParents = (bool)($request->getQueryParams()['only_parents'] ?? false);

        $sql = "
            SELECT g.id, g.nome, g.parent_id,
                   p.nome AS parent_nome,
                   CASE WHEN g.parent_id IS NULL THEN 'genere' ELSE 'sottogenere' END AS tipo,
                   (SELECT COUNT(*) FROM generi child WHERE child.parent_id = g.id) AS children_count
            FROM generi g
            LEFT JOIN generi p ON g.parent_id = p.id
        ";

        if ($onlyParents) {
            $sql .= " WHERE g.parent_id IS NULL";
        }

        $sql .= " ORDER BY g.parent_id IS NULL DESC, g.nome LIMIT ?";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $results = [];
        while ($row = $result->fetch_assoc()) {
            $results[] = [
                'id' => (int)$row['id'],
                'nome' => $row['nome'],
                'parent_id' => $row['parent_id'] ? (int)$row['parent_id'] : null,
                'parent_nome' => $row['parent_nome'],
                'tipo' => $row['tipo'],
                'children_count' => (int)$row['children_count'],
                'label' => $row['nome'] . ($row['parent_nome'] ? " ({$row['parent_nome']})" : '')
            ];
        }

        $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getSottogeneri(Request $request, Response $response, \mysqli $db): Response
    {
        $parent_id = (int)($request->getQueryParams()['parent_id'] ?? 0);

        if ($parent_id <= 0) {
            $response->getBody()->write(json_encode([]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $stmt = $db->prepare("
            SELECT id, nome
            FROM generi
            WHERE parent_id = ?
            ORDER BY nome
        ");
        $stmt->bind_param('i', $parent_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $results = [];
        while ($row = $result->fetch_assoc()) {
            $results[] = [
                'id' => (int)$row['id'],
                'nome' => $row['nome'],
                'label' => $row['nome']
            ];
        }

        $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
?>
