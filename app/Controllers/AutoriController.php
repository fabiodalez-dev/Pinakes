<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AutoriController
{
    public function index(Request $request, Response $response, mysqli $db): Response
    {
        $repo = new \App\Models\AuthorRepository($db);
        $autori = $repo->listBasic(100);

        ob_start();
        $data = ['autori' => $autori];
        // extract($data);
        require __DIR__ . '/../Views/autori/index.php';
        $content = ob_get_clean();

        // Layout base
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function show(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $authorRepo = new \App\Models\AuthorRepository($db);
        $bookRepo = new \App\Models\BookRepository($db);

        $autore = $authorRepo->getById($id);
        if (!$autore) {
            return $response->withStatus(404);
        }

        $libri = $authorRepo->getBooksByAuthorId($id);

        ob_start();
        $data = ['autore' => $autore, 'libri' => $libri];
        // extract($data);
        require __DIR__ . '/../Views/autori/scheda_autore.php';
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
        require __DIR__ . '/../Views/autori/crea_autore.php';
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
        $repo = new \App\Models\AuthorRepository($db);

        // SECURITY: Sanitize biografia (strip HTML to prevent XSS)
        $biografia = trim(strip_tags($data['biografia'] ?? ''));

        // SECURITY: Validate and sanitize sito_web as URL
        $sitoWeb = trim($data['sito_web'] ?? '');
        if ($sitoWeb !== '' && !filter_var($sitoWeb, FILTER_VALIDATE_URL)) {
            // If not a valid URL, prepend https:// and revalidate
            $sitoWeb = 'https://' . ltrim($sitoWeb, '/');
            if (!filter_var($sitoWeb, FILTER_VALIDATE_URL)) {
                $sitoWeb = ''; // Invalid URL, clear it
            }
        }

        $repo->create([
            'nome' => trim($data['nome'] ?? ''),
            'pseudonimo' => trim($data['pseudonimo'] ?? ''),
            'data_nascita' => $data['data_nascita'] ?? null,
            'data_morte' => $data['data_morte'] ?? null,
            'nazionalita' => trim($data['nazionalita'] ?? ''),
            'biografia' => $biografia,
            'sito_web' => $sitoWeb,
        ]);
        return $response->withHeader('Location', '/admin/autori')->withStatus(302);
    }

    public function editForm(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $repo = new \App\Models\AuthorRepository($db);
        $autore = $repo->getById($id);
        if (!$autore) {
            return $response->withStatus(404);
        }
        ob_start();
        $data = ['autore' => $autore];
        // extract(['autore'=>$autore]); 
        require __DIR__ . '/../Views/autori/modifica_autore.php';
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
        $repo = new \App\Models\AuthorRepository($db);

        // SECURITY: Sanitize biografia (strip HTML to prevent XSS)
        $biografia = trim(strip_tags($data['biografia'] ?? ''));

        // SECURITY: Validate and sanitize sito_web as URL
        $sitoWeb = trim($data['sito_web'] ?? '');
        if ($sitoWeb !== '' && !filter_var($sitoWeb, FILTER_VALIDATE_URL)) {
            // If not a valid URL, prepend https:// and revalidate
            $sitoWeb = 'https://' . ltrim($sitoWeb, '/');
            if (!filter_var($sitoWeb, FILTER_VALIDATE_URL)) {
                $sitoWeb = ''; // Invalid URL, clear it
            }
        }

        $repo->update($id, [
            'nome' => trim($data['nome'] ?? ''),
            'pseudonimo' => trim($data['pseudonimo'] ?? ''),
            'data_nascita' => $data['data_nascita'] ?? null,
            'data_morte' => $data['data_morte'] ?? null,
            'nazionalita' => trim($data['nazionalita'] ?? ''),
            'biografia' => $biografia,
            'sito_web' => $sitoWeb,
        ]);
        return $response->withHeader('Location', '/admin/autori')->withStatus(302);
    }
    public function delete(Request $request, Response $response, mysqli $db, int $id): Response
    {
        // CSRF validated by CsrfMiddleware
        $repo = new \App\Models\AuthorRepository($db);
        if ($repo->countBooks($id) > 0) {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            $_SESSION['error_message'] = __('Impossibile eliminare l\'autore: sono presenti libri associati.');
            $referer = $request->getHeaderLine('Referer');
            $target = (is_string($referer) && str_contains($referer, '/admin/autori')) ? $referer : '/admin/autori';
            return $response->withHeader('Location', $target)->withStatus(302);
        }

        $repo->delete($id);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['success_message'] = __('Autore eliminato con successo.');
        return $response->withHeader('Location', '/admin/autori')->withStatus(302);
    }
}
