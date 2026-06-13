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

        // Issue #163: author photo (upload or URL) + relevant source/website links.
        $foto = $this->resolveAuthorPhoto($request, $data, '');
        $collegamenti = $this->buildCollegamentiJson($data);

        $repo->create([
            'nome' => trim($data['nome'] ?? ''),
            'pseudonimo' => trim($data['pseudonimo'] ?? ''),
            'data_nascita' => $data['data_nascita'] ?? null,
            'data_morte' => $data['data_morte'] ?? null,
            'nazionalita' => trim($data['nazionalita'] ?? ''),
            'biografia' => $biografia,
            'sito_web' => $sitoWeb,
            'foto' => $foto,
            'collegamenti' => $collegamenti,
        ]);
        return $response->withHeader('Location', url('/admin/authors'))->withStatus(302);
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

        // Issue #163: resolve photo against the existing value, build links JSON.
        $existing = $repo->getById($id);
        $existingFoto = is_array($existing) ? (string) ($existing['foto'] ?? '') : '';
        $foto = $this->resolveAuthorPhoto($request, $data, $existingFoto);
        $collegamenti = $this->buildCollegamentiJson($data);

        $repo->update($id, [
            'nome' => trim($data['nome'] ?? ''),
            'pseudonimo' => trim($data['pseudonimo'] ?? ''),
            'data_nascita' => $data['data_nascita'] ?? null,
            'data_morte' => $data['data_morte'] ?? null,
            'nazionalita' => trim($data['nazionalita'] ?? ''),
            'biografia' => $biografia,
            'sito_web' => $sitoWeb,
            'foto' => $foto,
            'collegamenti' => $collegamenti,
        ]);
        return $response->withHeader('Location', url('/admin/authors'))->withStatus(302);
    }

    /**
     * Resolve the author photo (issue #163). Priority: an uploaded image
     * (saved under public/uploads/autori/), then a pasted URL, then the
     * "remove" flag, otherwise the existing value is kept. Returns the stored
     * value — a `/uploads/...` path, an external `https?://` URL, or ''.
     */
    private function resolveAuthorPhoto(Request $request, array $data, string $existing): string
    {
        if (!empty($data['rimuovi_foto'])) {
            $this->deleteLocalPhoto($existing);
            return '';
        }

        $files = $request->getUploadedFiles();
        $up = $files['foto_file'] ?? null;
        if ($up instanceof \Psr\Http\Message\UploadedFileInterface && $up->getError() === UPLOAD_ERR_OK) {
            $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $mime = (string) $up->getClientMediaType();
            $size = (int) $up->getSize();
            if (isset($allowed[$mime]) && $size > 0 && $size <= 5 * 1024 * 1024) {
                $dir = dirname(__DIR__, 2) . '/public/uploads/autori';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $name = 'autore_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
                try {
                    $up->moveTo($dir . '/' . $name);
                    @chmod($dir . '/' . $name, 0644);
                    $this->deleteLocalPhoto($existing); // replacing a previous local photo
                    return '/uploads/autori/' . $name;
                } catch (\Throwable $e) {
                    \App\Support\SecureLogger::error('Author photo upload failed: ' . $e->getMessage());
                }
            }
        }

        $urlRaw = trim((string) ($data['foto_url'] ?? ''));
        if ($urlRaw !== '') {
            if (!filter_var($urlRaw, FILTER_VALIDATE_URL)) {
                $urlRaw = 'https://' . ltrim($urlRaw, '/');
            }
            if (filter_var($urlRaw, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $urlRaw) === 1) {
                if ($urlRaw !== $existing) {
                    $this->deleteLocalPhoto($existing); // switching from a local file to a URL
                }
                return $urlRaw;
            }
        }

        return $existing; // unchanged
    }

    /** Delete a previously-uploaded local author photo (never an external URL). */
    private function deleteLocalPhoto(string $foto): void
    {
        if ($foto === '' || strpos($foto, '/uploads/autori/') !== 0) {
            return;
        }
        $base = realpath(dirname(__DIR__, 2) . '/public/uploads/autori');
        $real = realpath(dirname(__DIR__, 2) . '/public' . $foto);
        if ($base !== false && $real !== false && strpos($real, $base . DIRECTORY_SEPARATOR) === 0) {
            @unlink($real); // nosemgrep -- constrained to the resolved uploads/autori dir
        }
    }

    /**
     * Build the collegamenti JSON (issue #163) from the repeated form fields
     * collegamenti_etichetta[] + collegamenti_url[]. Drops rows without a valid
     * http(s) URL, caps the list, and returns a JSON string ('' when empty).
     */
    private function buildCollegamentiJson(array $data): string
    {
        $labels = (array) ($data['collegamenti_etichetta'] ?? []);
        $urls   = (array) ($data['collegamenti_url'] ?? []);
        $out = [];
        foreach ($urls as $i => $u) {
            $u = trim((string) $u);
            if ($u === '') {
                continue;
            }
            if (!filter_var($u, FILTER_VALIDATE_URL)) {
                $u = 'https://' . ltrim($u, '/');
            }
            if (!filter_var($u, FILTER_VALIDATE_URL) || preg_match('#^https?://#i', $u) !== 1) {
                continue;
            }
            $label = trim(strip_tags((string) ($labels[$i] ?? '')));
            if (mb_strlen($label) > 120) {
                $label = mb_substr($label, 0, 120);
            }
            $out[] = ['etichetta' => $label, 'url' => $u];
            if (count($out) >= 20) {
                break;
            }
        }
        return $out === [] ? '' : (string) json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
            $target = str_contains($referer, '/admin/authors') ? $referer : '/admin/authors';
            return $response->withHeader('Location', $target)->withStatus(302);
        }

        $repo->delete($id);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['success_message'] = __('Autore eliminato con successo.');
        return $response->withHeader('Location', url('/admin/authors'))->withStatus(302);
    }
}
