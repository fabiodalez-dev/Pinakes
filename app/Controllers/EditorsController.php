<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EditorsController
{
    public function index(Request $request, Response $response, mysqli $db): Response
    {
        $repo = new \App\Models\PublisherRepository($db);
        $editori = $repo->listBasic();
        ob_start();
        $data = ['editori' => $editori];
        // extract(['editori'=>$editori]); 
        require __DIR__ . '/../Views/editori/index.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    public function show(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $publisherRepo = new \App\Models\PublisherRepository($db);
        $authorRepo = new \App\Models\AuthorRepository($db);

        $editore = $publisherRepo->getById($id);
        if (!$editore) {
            return $response->withStatus(404);
        }

        $libri = $publisherRepo->getBooksByPublisherId($id);
        $autori = $publisherRepo->getAuthorsByPublisherId($id);

        ob_start();
        $data = ['editore' => $editore, 'libri' => $libri, 'autori' => $autori];
        // extract($data);
        require __DIR__ . '/../Views/editori/scheda_editore.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function createForm(Request $request, Response $response): Response
    {
        ob_start();
        require __DIR__ . '/../Views/editori/crea_editore.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    public function store(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array) $request->getParsedBody();
        // CSRF validated by CsrfMiddleware
        $repo = new \App\Models\PublisherRepository($db);
        $id = $repo->create([
            'nome' => trim($data['nome'] ?? ''),
            'sito_web' => trim($data['sito_web'] ?? ''),
        ]);
        return $response->withHeader('Location', '/admin/editori')->withStatus(302);
    }

    public function editForm(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $repo = new \App\Models\PublisherRepository($db);
        $editore = $repo->getById($id);
        if (!$editore) {
            return $response->withStatus(404);
        }
        ob_start();
        $data = ['editore' => $editore];
        // extract(['editore'=>$editore]); 
        require __DIR__ . '/../Views/editori/modifica_editore.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    public function update(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $data = (array) $request->getParsedBody();
        // CSRF validated by CsrfMiddleware
        $repo = new \App\Models\PublisherRepository($db);
        $repo->update($id, [
            'nome' => trim($data['nome'] ?? ''),
            'sito_web' => trim($data['sito_web'] ?? ''),
        ]);
        return $response->withHeader('Location', '/admin/editori')->withStatus(302);
    }
    public function delete(Request $request, Response $response, mysqli $db, int $id): Response
    {
        // CSRF validated by CsrfMiddleware
        $repo = new \App\Models\PublisherRepository($db);
        if ($repo->countBooks($id) > 0) {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            $_SESSION['error_message'] = __('Impossibile eliminare l\'editore: sono presenti libri associati.');
            $referer = $request->getHeaderLine('Referer');
            $target = str_contains($referer, '/admin/editori') ? $referer : '/admin/editori';
            return $response->withHeader('Location', $target)->withStatus(302);
        }

        $repo->delete($id);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['success_message'] = __('Editore eliminato con successo.');
        return $response->withHeader('Location', '/admin/editori')->withStatus(302);
    }
}
