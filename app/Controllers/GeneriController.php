<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\GenereRepository;
use App\Support\Csrf;
use App\Support\CsrfHelper;

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
        $data = $request->getParsedBody();
        if (!Csrf::validate($data['csrf_token'] ?? null)) {
            $_SESSION['error_message'] = 'Sessione scaduta. Riprova.';
            return $response->withHeader('Location', '/admin/generi/crea')->withStatus(302);
        }
        $repo = new GenereRepository($db);

        try {
            $id = $repo->create([
                'nome' => trim((string)($data['nome'] ?? '')),
                'parent_id' => !empty($data['parent_id']) ? (int)$data['parent_id'] : null
            ]);

            $_SESSION['success_message'] = 'Genere creato con successo!';
            return $response->withHeader('Location', "/admin/generi/{$id}")->withStatus(302);

        } catch (\Exception $e) {
            $_SESSION['error_message'] = 'Errore nella creazione del genere: ' . $e->getMessage();
            return $response->withHeader('Location', '/admin/generi/crea')->withStatus(302);
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

        ob_start();
        $genere = $genere;
        $children = $children;
        require __DIR__ . '/../Views/generi/dettaglio_genere.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }
}
?>
