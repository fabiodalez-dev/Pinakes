<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\SettingsRepository;
use App\Support\Branding;
use App\Support\ConfigStore;
use App\Support\PatronCardPdf;
use App\Support\PatronCardSettings;
use App\Support\PatronPhoto;
use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Library cards (PDF) and the patron photo.
 *
 * Who may do what follows the reader record it acts on, exactly like
 * UsersController: printing a card or viewing the photo is reading the record
 * (admin and staff read every record, a custom users manager only the ones it
 * may edit); changing or deleting the photo is editing it.
 */
class PatronCardsController
{
    /** Upper bound of cards in one bulk PDF (ten A4 sheets of 50 pages). */
    public const MAX_BULK = 500;

    public function cardPdf(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if (($off = \App\Support\Features::requireOrRedirect('patron_cards', $response)) !== null) {
            return $off;
        }
        if (!\App\Support\Permissions::can('users.manage')) {
            return $response->withStatus(403);
        }
        $rows = $this->loadUsers($db, [$id]);
        if ($rows === []) {
            return $response->withStatus(404);
        }
        if (!$this->canRead($rows[0])) {
            return $response->withStatus(403);
        }
        $code = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $rows[0]['codice_tessera']) ?: (string) $id;
        return $this->pdfResponse($response, $db, $rows, $request->getQueryParams(), 'tessera_' . $code . '.pdf');
    }

    public function bulkPdf(Request $request, Response $response, mysqli $db): Response
    {
        if (($off = \App\Support\Features::requireOrRedirect('patron_cards', $response)) !== null) {
            return $off;
        }
        if (!\App\Support\Permissions::can('users.manage')) {
            return $response->withStatus(403);
        }
        $query = $request->getQueryParams();
        $ids = [];
        foreach (explode(',', is_string($query['ids'] ?? null) ? $query['ids'] : '') as $part) {
            $n = (int) trim($part);
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }
        if ($ids === []) {
            return $response->withStatus(400);
        }
        if (count($ids) > self::MAX_BULK) {
            $ids = array_slice($ids, 0, self::MAX_BULK, true);
        }
        $rows = array_values(array_filter($this->loadUsers($db, array_values($ids)), fn (array $row): bool => $this->canRead($row)));
        if ($rows === []) {
            return $response->withStatus(404);
        }
        return $this->pdfResponse($response, $db, $rows, $query, 'tessere.pdf');
    }

    /** Stream the stored photo (never cached by shared caches). */
    public function photo(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if (($off = \App\Support\Features::requireOrRedirect('patron_cards', $response)) !== null) {
            return $off;
        }
        if (!\App\Support\Permissions::can('users.manage')) {
            return $response->withStatus(403);
        }
        $rows = $this->loadUsers($db, [$id]);
        if ($rows === [] || !$this->canRead($rows[0])) {
            return $response->withStatus($rows === [] ? 404 : 403);
        }
        $path = PatronPhoto::absolutePath($rows[0]['foto_profilo_url'] ?? null);
        if ($path === null) {
            return $response->withStatus(404);
        }
        $bytes = (string) file_get_contents($path);
        $response->getBody()->write($bytes);
        return $response
            ->withHeader('Content-Type', 'image/jpeg')
            ->withHeader('Content-Length', (string) strlen($bytes))
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /** Save a photo sent as multipart field `photo` (webcam frame or picked file). JSON reply. */
    public function uploadPhoto(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if (($off = \App\Support\Features::requireOrRedirect('patron_cards', $response)) !== null) {
            return $off;
        }
        $guard = $this->editGuard($db, $id);
        if ($guard !== null) {
            return $this->json($response, ['success' => false, 'error' => $guard[1]], $guard[0]);
        }
        $files = $request->getUploadedFiles();
        $file = $files['photo'] ?? null;
        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $tooLarge = $file instanceof \Psr\Http\Message\UploadedFileInterface
                && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            return $this->json($response, ['success' => false, 'error' => $tooLarge ? __('La foto supera la dimensione massima consentita.') : __('Nessuna foto ricevuta.')], 400);
        }
        $tmp = $file->getStream()->getMetadata('uri');
        if (!is_string($tmp) || !is_file($tmp)) {
            return $this->json($response, ['success' => false, 'error' => __('Nessuna foto ricevuta.')], 400);
        }
        $error = PatronPhoto::store($db, $id, $tmp);
        if ($error !== null) {
            $message = match ($error) {
                'too_large' => __('La foto supera la dimensione massima consentita.'),
                'invalid_image' => __('Il file non è un\'immagine JPEG, PNG o WebP valida.'),
                default => __('Impossibile salvare la foto. Riprova più tardi.'),
            };
            return $this->json($response, ['success' => false, 'error' => $message], $error === 'storage' || $error === 'db' ? 500 : 422);
        }
        \App\Support\SecureLogger::info('Patron photo updated', ['user_id' => $id, 'by' => $_SESSION['user']['id'] ?? null]);
        return $this->json($response, [
            'success' => true,
            'url' => url('/admin/users/' . $id . '/photo') . '?v=' . substr(sha1((string) PatronPhoto::storedFor($db, $id)), 0, 10),
        ]);
    }

    /** Remove the photo (the reader's right to erasure). JSON for fetch(), redirect for a plain form. */
    public function deletePhoto(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if (($off = \App\Support\Features::requireOrRedirect('patron_cards', $response)) !== null) {
            return $off;
        }
        $wantsJson = str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
        $guard = $this->editGuard($db, $id);
        if ($guard !== null) {
            return $wantsJson
                ? $this->json($response, ['success' => false, 'error' => $guard[1]], $guard[0])
                : $response->withStatus($guard[0]);
        }
        $ok = PatronPhoto::delete($db, $id);
        if ($ok) {
            \App\Support\SecureLogger::info('Patron photo deleted', ['user_id' => $id, 'by' => $_SESSION['user']['id'] ?? null]);
        }
        if ($wantsJson) {
            return $this->json($response, $ok ? ['success' => true] : ['success' => false, 'error' => __('Impossibile eliminare la foto. Riprova più tardi.')], $ok ? 200 : 500);
        }
        $_SESSION[$ok ? 'success_message' : 'error_message'] = $ok ? __('Foto eliminata.') : __('Impossibile eliminare la foto. Riprova più tardi.');
        return $response->withHeader('Location', url('/admin/users/details/' . $id))->withStatus(302);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $query
     */
    private function pdfResponse(Response $response, mysqli $db, array $rows, array $query, string $filename): Response
    {
        $repository = new SettingsRepository($db);
        $settings = PatronCardSettings::resolve($repository);
        $format = is_string($query['format'] ?? null) && in_array($query['format'], PatronCardSettings::FORMATS, true)
            ? $query['format']
            : $settings['format'];

        $cards = [];
        foreach ($rows as $row) {
            $expiry = (string) ($row['data_scadenza_tessera'] ?? '');
            $cards[] = [
                'name' => trim((string) ($row['nome'] ?? '') . ' ' . (string) ($row['cognome'] ?? '')),
                'code' => (string) ($row['codice_tessera'] ?? ''),
                'expiry' => $expiry !== '' && function_exists('format_date') ? format_date($expiry, false, '/') : $expiry,
                'photo' => $settings['show_photo'] ? PatronPhoto::absolutePath($row['foto_profilo_url'] ?? null) : null,
            ];
        }

        $appName = (string) ConfigStore::get('app.name', 'Biblioteca');
        $generator = new PatronCardPdf($settings, $appName, $this->localLogo());
        $pdf = $generator->render($cards, $format);

        $response->getBody()->write($pdf);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /** The configured logo as a local file (remote logos are not fetched while rendering). */
    private function localLogo(): ?string
    {
        $logo = Branding::logo();
        if ($logo === '' || preg_match('#^https?://#i', $logo) === 1) {
            return null;
        }
        $path = (string) (parse_url($logo, PHP_URL_PATH) ?? '');
        $public = realpath(dirname(__DIR__, 2) . '/public');
        if ($path === '' || $public === false) {
            return null;
        }
        $file = realpath($public . '/' . ltrim($path, '/'));
        return $file !== false && str_starts_with($file, $public . DIRECTORY_SEPARATOR) ? $file : null;
    }

    /**
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    private function loadUsers(mysqli $db, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $n): bool => $n > 0));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT id, nome, cognome, codice_tessera, data_scadenza_tessera, foto_profilo_url, tipo_utente, ruolo_id, accesso_digitale
                                FROM utenti WHERE id IN ({$placeholders})
                               ORDER BY cognome, nome, id");
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /** @param array<string,mixed> $row */
    private function canRead(array $row): bool
    {
        return in_array($_SESSION['user']['tipo_utente'] ?? '', ['admin', 'staff'], true)
            || \App\Support\UserRoleAssignment::canEdit($row);
    }

    /** @return array{0:int,1:string}|null status and message when refused */
    private function editGuard(mysqli $db, int $id): ?array
    {
        if (!\App\Support\Permissions::can('users.manage')) {
            return [403, __('Accesso negato')];
        }
        $rows = $this->loadUsers($db, [$id]);
        if ($rows === []) {
            return [404, __('Utente non trovato')];
        }
        if (!\App\Support\UserRoleAssignment::canEdit($rows[0])) {
            return [403, __('Accesso negato')];
        }
        return null;
    }

    /** @param array<string,mixed> $payload */
    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
