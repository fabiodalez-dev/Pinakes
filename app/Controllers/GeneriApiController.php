<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GeneriApiController
{
    public function search(Request $request, Response $response, \mysqli $db): Response
    {
        $query = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $limit = min(50, (int) ($request->getQueryParams()['limit'] ?? 20));

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
                    'id' => (int) $row['id'],
                    'label' => $label,
                    'nome' => $row['nome'],
                    'parent_id' => $row['parent_id'] ? (int) $row['parent_id'] : null,
                    'parent_nome' => $row['parent_nome'],
                    'tipo' => $row['tipo']
                ];
            }
        }

        $response->getBody()->write(json_encode($results, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function create(Request $request, Response $response, \mysqli $db): Response
    {
        // CSRF validated by CsrfMiddleware

        $data = $request->getParsedBody();
        $nome = trim((string) ($data['nome'] ?? ''));
        $parent_id = !empty($data['parent_id']) ? (int) $data['parent_id'] : null;

        if (empty($nome)) {
            $response->getBody()->write(json_encode(['error' => __('Il nome del genere è obbligatorio.')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Verifica se già esistente
        $stmt = $db->prepare("SELECT id FROM generi WHERE nome = ? AND parent_id <=> ?");
        $stmt->bind_param('si', $nome, $parent_id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();

        if ($existing) {
            $response->getBody()->write(json_encode([
                'id' => (int) $existing['id'],
                'nome' => $nome,
                'exists' => true
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        }

        // Inserisci nuovo genere
        $stmt = $db->prepare("INSERT INTO generi (nome, parent_id, created_at) VALUES (?, ?, NOW())");
        $decodedNome = \App\Support\HtmlHelper::decode($nome);
        $stmt->bind_param('si', $decodedNome, $parent_id);

        if ($stmt->execute()) {
            $id = $db->insert_id;
            $response->getBody()->write(json_encode([
                'id' => (int) $id,
                'nome' => $nome,
                'parent_id' => $parent_id,
                'created' => true
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode(['error' => __('Errore nella creazione del genere.')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    public function update(Request $request, Response $response, \mysqli $db, int $id): Response
    {
        // CSRF validated by CsrfMiddleware
        $data = $request->getParsedBody();
        if (empty($data)) {
            $data = json_decode((string)$request->getBody(), true) ?? [];
        }

        $nome = trim((string)($data['nome'] ?? ''));
        if (empty($nome)) {
            $response->getBody()->write(json_encode(['error' => __('Il nome del genere è obbligatorio.')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $repo = new \App\Models\GenereRepository($db);
        $genere = $repo->getById($id);
        if (!$genere) {
            $response->getBody()->write(json_encode(['error' => __('Genere non trovato.')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        try {
            $updateData = ['nome' => $nome];
            if (isset($data['parent_id'])) {
                $newParent = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;
                if ($newParent === $id) {
                    $response->getBody()->write(json_encode(['error' => __('Un genere non può essere genitore di sé stesso.')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
                    return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
                }
                // Cycle detection: walk ancestor chain to prevent A→B→A
                if ($newParent !== null) {
                    $ancestorId = $newParent;
                    $depth = 100;
                    $aStmt = $db->prepare('SELECT parent_id FROM generi WHERE id = ?');
                    if (!$aStmt) {
                        \App\Support\SecureLogger::error('GeneriApiController::update prepare() failed');
                        $response->getBody()->write(json_encode(['error' => __('Errore interno.')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
                        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
                    }
                    while ($ancestorId > 0 && $depth-- > 0) {
                        if ($ancestorId === $id) {
                            $aStmt->close();
                            $response->getBody()->write(json_encode(['error' => __('Impossibile: si creerebbe un ciclo.')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
                            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
                        }
                        $aStmt->bind_param('i', $ancestorId);
                        $aStmt->execute();
                        $aRow = $aStmt->get_result()->fetch_assoc();
                        $ancestorId = $aRow ? (int)($aRow['parent_id'] ?? 0) : 0;
                    }
                    $aStmt->close();
                }
                $updateData['parent_id'] = $newParent;
            }

            if (!$repo->update($id, $updateData)) {
                throw new \RuntimeException('update() returned false');
            }
            $response->getBody()->write(json_encode([
                'id' => $id,
                'nome' => $nome,
                'updated' => true
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            if ($e instanceof \InvalidArgumentException) {
                $response->getBody()->write(json_encode(['error' => $e->getMessage()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            \App\Support\SecureLogger::error('GeneriApiController::update error', ['id' => $id, 'message' => $e->getMessage()]);
            $response->getBody()->write(json_encode(['error' => __('Errore interno durante l\'aggiornamento.')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function listGeneri(Request $request, Response $response, \mysqli $db): Response
    {
        $limit = min(100, (int) ($request->getQueryParams()['limit'] ?? 50));
        $onlyParents = (bool) ($request->getQueryParams()['only_parents'] ?? false);

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
                'id' => (int) $row['id'],
                'nome' => $row['nome'],
                'parent_id' => $row['parent_id'] ? (int) $row['parent_id'] : null,
                'parent_nome' => $row['parent_nome'],
                'tipo' => $row['tipo'],
                'children_count' => (int) $row['children_count'],
                'label' => $row['nome'] . ($row['parent_nome'] ? " ({$row['parent_nome']})" : '')
            ];
        }

        $response->getBody()->write(json_encode($results, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getSottogeneri(Request $request, Response $response, \mysqli $db): Response
    {
        $parent_id = (int) ($request->getQueryParams()['parent_id'] ?? 0);

        if ($parent_id <= 0) {
            $response->getBody()->write(json_encode([], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
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
                'id' => (int) $row['id'],
                'nome' => $row['nome'],
                'label' => $row['nome']
            ];
        }

        $response->getBody()->write(json_encode($results, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }
}