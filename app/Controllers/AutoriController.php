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
        $photo = $this->resolveAuthorPhoto($request, $data, '');
        $collegamenti = $this->buildCollegamentiJson($data);

        try {
            $repo->create([
                'nome' => trim($data['nome'] ?? ''),
                'pseudonimo' => trim($data['pseudonimo'] ?? ''),
                'data_nascita' => $data['data_nascita'] ?? null,
                'data_morte' => $data['data_morte'] ?? null,
                'nazionalita' => trim($data['nazionalita'] ?? ''),
                'biografia' => $biografia,
                'sito_web' => $sitoWeb,
                'foto' => $photo['foto'],
                'collegamenti' => $collegamenti,
            ]);
        } catch (\Throwable $e) {
            // Persistence failed → roll back the just-written upload so no orphan file is left.
            \App\Support\SecureLogger::error('Author create failed: ' . $e->getMessage());
            if ($photo['deleteOnFailure'] !== null) {
                $this->deleteLocalPhoto($photo['deleteOnFailure']);
            }
            $this->flashError(__('Impossibile salvare l\'autore. Riprova.'));
            return $response->withHeader('Location', url('/admin/authors/create'))->withStatus(302);
        }
        // Persistence succeeded → safe to remove any superseded local photo (none on create).
        if ($photo['deleteOnSuccess'] !== null) {
            $this->deleteLocalPhoto($photo['deleteOnSuccess']);
        }
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
        // Guard: the record may have been deleted between form-open and submit —
        // return 404 instead of a masked redirect (which would also orphan uploads).
        $existing = $repo->getById($id);
        if (!$existing) {
            return $response->withStatus(404);
        }
        $existingFoto = (string) ($existing['foto'] ?? '');
        $photo = $this->resolveAuthorPhoto($request, $data, $existingFoto);
        $collegamenti = $this->buildCollegamentiJson($data);

        try {
            $repo->update($id, [
                'nome' => trim($data['nome'] ?? ''),
                'pseudonimo' => trim($data['pseudonimo'] ?? ''),
                'data_nascita' => $data['data_nascita'] ?? null,
                'data_morte' => $data['data_morte'] ?? null,
                'nazionalita' => trim($data['nazionalita'] ?? ''),
                'biografia' => $biografia,
                'sito_web' => $sitoWeb,
                'foto' => $photo['foto'],
                'collegamenti' => $collegamenti,
            ]);
        } catch (\Throwable $e) {
            // Persistence failed → roll back the just-written upload, keep the old photo intact.
            \App\Support\SecureLogger::error('Author update failed: ' . $e->getMessage());
            if ($photo['deleteOnFailure'] !== null) {
                $this->deleteLocalPhoto($photo['deleteOnFailure']);
            }
            $this->flashError(__('Impossibile salvare l\'autore. Riprova.'));
            return $response->withHeader('Location', url('/admin/authors/edit/' . $id))->withStatus(302);
        }
        // Persistence succeeded → now it is safe to drop the superseded local photo.
        if ($photo['deleteOnSuccess'] !== null) {
            $this->deleteLocalPhoto($photo['deleteOnSuccess']);
        }
        return $response->withHeader('Location', url('/admin/authors'))->withStatus(302);
    }

    /**
     * Resolve the author photo (issue #163). Priority: an uploaded image
     * (saved under public/uploads/autori/), then a pasted URL, then the
     * "remove" flag, otherwise the existing value is kept.
     *
     * Side effects are DEFERRED to the caller: this method may WRITE a new local
     * file, but it never DELETES the previous photo — instead it returns which
     * file to drop after a successful DB write (`deleteOnSuccess`) and which file
     * to drop if the DB write fails (`deleteOnFailure`). This keeps the photo and
     * the persisted value consistent even when the DB write throws.
     *
     * @return array{foto: string, deleteOnSuccess: ?string, deleteOnFailure: ?string}
     *         foto = stored value (`/uploads/...` path, external `https?://` URL, or '')
     */
    private function resolveAuthorPhoto(Request $request, array $data, string $existing): array
    {
        if (!empty($data['rimuovi_foto'])) {
            // Drop the old local photo only after the DB row is updated to ''.
            return ['foto' => '', 'deleteOnSuccess' => $existing, 'deleteOnFailure' => null];
        }

        $files = $request->getUploadedFiles();
        $up = $files['foto_file'] ?? null;
        if ($up instanceof \Psr\Http\Message\UploadedFileInterface && $up->getError() === UPLOAD_ERR_OK) {
            $size = (int) $up->getSize();
            if ($size > 0 && $size <= 5 * 1024 * 1024) {
                $bytes = '';
                try {
                    $bytes = (string) $up->getStream();
                } catch (\Throwable $e) {
                    $bytes = '';
                }
                // SECURITY: never trust the client-supplied MIME — validate the
                // real bytes and derive the extension from the detected type.
                $info = ($bytes !== '' && strlen($bytes) <= 5 * 1024 * 1024)
                    ? @getimagesizefromstring($bytes) : false;
                $detected = is_array($info) ? (string) $info['mime'] : '';
                $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
                if (isset($allowed[$detected])) {
                    $dir = dirname(__DIR__, 2) . '/public/uploads/autori';
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0775, true);
                    }
                    $name = 'autore_' . bin2hex(random_bytes(8)) . '.' . $allowed[$detected];
                    $dest = $dir . '/' . $name;
                    // Re-encode through GD to strip any embedded payload (mirrors cover handling).
                    if ($this->reencodeAuthorPhoto($bytes, $detected, $dest)) {
                        @chmod($dest, 0644);
                        $stored = '/uploads/autori/' . $name;
                        return [
                            'foto' => $stored,
                            'deleteOnSuccess' => ($existing !== '' && $existing !== $stored) ? $existing : null,
                            'deleteOnFailure' => $stored, // remove this fresh upload if the DB write fails
                        ];
                    }
                    \App\Support\SecureLogger::error('Author photo re-encode failed (unsupported or corrupt image)');
                }
            }
        }

        $urlRaw = trim((string) ($data['foto_url'] ?? ''));
        if ($urlRaw !== '') {
            if (!filter_var($urlRaw, FILTER_VALIDATE_URL)) {
                $urlRaw = 'https://' . ltrim($urlRaw, '/');
            }
            if (filter_var($urlRaw, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $urlRaw) === 1) {
                // Switching from a local file to a URL: drop the file only after the DB write.
                $deleteOld = ($urlRaw !== $existing) ? $existing : null;
                return ['foto' => $urlRaw, 'deleteOnSuccess' => $deleteOld, 'deleteOnFailure' => null];
            }
        }

        return ['foto' => $existing, 'deleteOnSuccess' => null, 'deleteOnFailure' => null]; // unchanged
    }

    /**
     * Re-encode validated image bytes to $dest via GD, stripping any embedded
     * payload. $mime is the server-detected type (one of png/jpeg/webp/gif).
     * Returns false if GD cannot decode/encode that format (upload is rejected).
     */
    private function reencodeAuthorPhoto(string $bytes, string $mime, string $dest): bool
    {
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return false;
        }
        try {
            switch ($mime) {
                case 'image/png':
                    imagealphablending($img, false);
                    imagesavealpha($img, true);
                    return imagepng($img, $dest, 9);
                case 'image/jpeg':
                    return imagejpeg($img, $dest, 88);
                case 'image/gif':
                    return imagegif($img, $dest);
                case 'image/webp':
                    return function_exists('imagewebp') ? imagewebp($img, $dest) : false;
                default:
                    return false;
            }
        } finally {
            imagedestroy($img);
        }
    }

    /** Flash an error message for the next request (mirrors delete()). */
    private function flashError(string $message): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['error_message'] = $message;
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
