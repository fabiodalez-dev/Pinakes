<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\GenereRepository;
use App\Support\SecureLogger;

class GeneriController
{
    public function index(Request $request, Response $response, \mysqli $db): Response
    {
        $repo = new GenereRepository($db);
        $generi = $repo->listAll(200);

        // Organize generi hierarchically
        $generiPrincipali = [];
        $sottogeneri = [];

        foreach ($generi as $genere) {
            if ($genere['parent_id'] === null) {
                $generiPrincipali[] = $genere;
            } else {
                if (!isset($sottogeneri[$genere['parent_id']])) {
                    $sottogeneri[$genere['parent_id']] = [];
                }
                $sottogeneri[$genere['parent_id']][] = $genere;
            }
        }

        ob_start();
        $generiPrincipali = $generiPrincipali;
        $sottogeneri = $sottogeneri;
        $totalGeneri = count($generi);
        require __DIR__ . '/../Views/generi/index.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function createForm(Request $request, Response $response, \mysqli $db): Response
    {
        $repo = new GenereRepository($db);
        $generiPrincipali = $repo->listAll();
        $generiParentOptions = array_filter($generiPrincipali, fn($g) => $g['parent_id'] === null);

        ob_start();
        $generiParentOptions = $generiParentOptions;
        require __DIR__ . '/../Views/generi/crea_genere.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function store(Request $request, Response $response, \mysqli $db): Response
    {
        $data = $request->getParsedBody() ?? [];
        // CSRF validated by CsrfMiddleware
        $repo = new GenereRepository($db);

        try {
            $id = $repo->create([
                'nome' => trim((string) ($data['nome'] ?? '')),
                'parent_id' => !empty($data['parent_id']) ? (int) $data['parent_id'] : null
            ]);

            $_SESSION['success_message'] = __('Genere creato con successo!');
            return $response->withHeader('Location', "/admin/generi/{$id}")->withStatus(302);

        } catch (\Throwable $e) {
            SecureLogger::error('GeneriController::store error: ' . $e->getMessage());
            $_SESSION['error_message'] = __('Errore nella creazione del genere.');
            return $response->withHeader('Location', '/admin/generi/crea')->withStatus(302);
        }
    }

    public function update(Request $request, Response $response, \mysqli $db, int $id): Response
    {
        $data = $request->getParsedBody() ?? [];
        // CSRF validated by CsrfMiddleware
        $repo = new GenereRepository($db);

        $genere = $repo->getById($id);
        if (!$genere) {
            return $response->withStatus(404);
        }

        try {
            $updateData = ['nome' => trim((string)($data['nome'] ?? ''))];

            // Allow moving to a different parent (but not if it would create a cycle)
            if (isset($data['parent_id'])) {
                $newParent = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;
                // Prevent setting self as parent
                if ($newParent === $id) {
                    $_SESSION['error_message'] = __('Un genere non può essere genitore di sé stesso.');
                    return $response->withHeader('Location', "/admin/generi/{$id}")->withStatus(302);
                }
                // Cycle detection: walk ancestor chain to prevent A→B→A
                if ($newParent !== null) {
                    $ancestorId = $newParent;
                    $depth = 100;
                    $aStmt = $db->prepare('SELECT parent_id FROM generi WHERE id = ?');
                    if (!$aStmt) {
                        \App\Support\SecureLogger::error('GeneriController::update prepare() failed');
                        $_SESSION['error_message'] = __('Errore interno.');
                        return $response->withHeader('Location', "/admin/generi/{$id}")->withStatus(302);
                    }
                    while ($ancestorId > 0 && $depth-- > 0) {
                        if ($ancestorId === $id) {
                            $aStmt->close();
                            $_SESSION['error_message'] = __('Impossibile: si creerebbe un ciclo.');
                            return $response->withHeader('Location', "/admin/generi/{$id}")->withStatus(302);
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
            $_SESSION['success_message'] = __('Genere aggiornato con successo!');
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('GeneriController::update error', ['id' => $id, 'message' => $e->getMessage()]);
            $_SESSION['error_message'] = __('Errore nell\'aggiornamento del genere.');
        }

        return $response->withHeader('Location', "/admin/generi/{$id}")->withStatus(302);
    }

    public function destroy(Request $request, Response $response, \mysqli $db, int $id): Response
    {
        // CSRF validated by CsrfMiddleware
        $repo = new GenereRepository($db);

        try {
            if (!$repo->delete($id)) {
                throw new \RuntimeException('delete() returned false');
            }
            $_SESSION['success_message'] = __('Genere eliminato con successo!');
            return $response->withHeader('Location', '/admin/generi')->withStatus(302);
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('GeneriController::destroy error', ['id' => $id, 'message' => $e->getMessage()]);
            $_SESSION['error_message'] = __('Errore nell\'eliminazione del genere.');
            return $response->withHeader('Location', "/admin/generi/{$id}")->withStatus(302);
        }
    }

    public function merge(Request $request, Response $response, \mysqli $db, int $id): Response
    {
        $data = $request->getParsedBody() ?? [];
        $repo = new GenereRepository($db);

        $targetId = (int)($data['target_id'] ?? 0);
        if ($targetId === 0) {
            $_SESSION['error_message'] = __('Seleziona un genere di destinazione.');
            return $response->withHeader('Location', "/admin/generi/{$id}")->withStatus(302);
        }

        try {
            $stats = $repo->merge($id, $targetId);
            $parts = [];
            if ($stats['books_updated'] > 0) {
                $parts[] = $stats['books_updated'] . ' ' . __('libri aggiornati');
            }
            if ($stats['children_moved'] > 0) {
                $parts[] = $stats['children_moved'] . ' ' . __('sottogeneri spostati');
            }
            $detail = !empty($parts) ? ' (' . implode(', ', $parts) . ')' : '';
            $_SESSION['success_message'] = __('Generi uniti con successo!') . $detail;
            return $response->withHeader('Location', "/admin/generi/{$targetId}")->withStatus(302);
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('GeneriController::merge error', ['id' => $id, 'target' => $targetId, 'message' => $e->getMessage()]);
            $_SESSION['error_message'] = __('Errore nell\'unione dei generi.');
            return $response->withHeader('Location', "/admin/generi/{$id}")->withStatus(302);
        }
    }

    public function show(Request $request, Response $response, \mysqli $db, int $id): Response
    {
        $repo = new GenereRepository($db);
        $genere = $repo->getById($id);

        if (!$genere) {
            return $response->withStatus(404);
        }

        // Mostra sempre i figli (se presenti), anche per sottogeneri intermedi
        $children = $repo->getChildren($id);
        $allGeneri = $repo->getAllFlat();

        ob_start();
        $genere = $genere;
        $children = $children;
        $allGeneri = $allGeneri;
        require __DIR__ . '/../Views/generi/dettaglio_genere.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }
}