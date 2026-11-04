<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Controllers;

use App\Controllers\CatalogueCardController;
use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * ISBD catalogue cards (Pinakes 0.9.0) as PDF downloads. The PDF is built by
 * CatalogueCardController, the action behind the admin book page and the
 * book list's bulk print; the app gets it as an attachment.
 */
final class StaffCatalogueCardsController
{
    public function __construct(private mysqli $db)
    {
    }

    public function single(ServerRequestInterface $request, ResponseInterface $response, int $bookId): ResponseInterface
    {
        try {
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new CatalogueCardController())->single(
                Delegation::request('GET', '/admin/books/' . $bookId . '/catalogue-card'),
                new SlimResponse(),
                $db,
                $bookId
            ));
            if ($outcome->isRedirect()) {
                return $this->redirectError($response, $outcome->flash('error_message'), __('Libro non trovato.'));
            }

            return $this->pdf($response, $outcome->status, $outcome->body, 'scheda_' . $bookId . '.pdf');
        } catch (\Throwable $e) {
            return $this->fail($response, 'catalogue card', $e);
        }
    }

    public function bulk(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $raw = $request->getQueryParams()['ids'] ?? null;
        $ids = is_string($raw) ? CatalogueCardController::parseIds($raw) : [];
        if ($ids === []) {
            $message = __('Seleziona almeno un libro per stampare le schede catalografiche.');

            return ResponseEnvelope::error($response, 'validation', $message, 422, ['fields' => ['ids' => $message], 'field' => 'ids']);
        }
        try {
            $db = $this->db;
            $query = ['ids' => implode(',', $ids)];
            $outcome = Delegation::run(static fn (): ResponseInterface => (new CatalogueCardController())->bulk(
                Delegation::request('GET', '/admin/books/catalogue-cards', [], $query),
                new SlimResponse(),
                $db
            ));
            if ($outcome->isRedirect()) {
                // None of the ids is a (non-deleted) book.
                return $this->redirectError($response, $outcome->flash('error_message'), __('Libro non trovato.'));
            }

            return $this->pdf($response, $outcome->status, $outcome->body, 'schede_catalografiche.pdf');
        } catch (\Throwable $e) {
            return $this->fail($response, 'catalogue cards', $e);
        }
    }

    /**
     * The web answers a refusal with a redirect and a flash message: a missing
     * book (404) or a PDF that could not be built (500).
     */
    private function redirectError(ResponseInterface $response, ?string $flash, string $notFound): ResponseInterface
    {
        if ($flash === __('Impossibile generare le schede catalografiche.')) {
            SecureLogger::error('[MobileApi] staff catalogue card PDF not produced');

            return ResponseEnvelope::error($response, 'internal_error', $flash, 500);
        }

        return ResponseEnvelope::error($response, 'not_found', $flash ?? $notFound, 404);
    }

    private function pdf(ResponseInterface $response, int $status, string $body, string $filename): ResponseInterface
    {
        if ($status !== 200 || !str_starts_with($body, '%PDF-')) {
            throw new \RuntimeException('catalogue card PDF not produced (HTTP ' . $status . ')');
        }
        $response->getBody()->write($body);

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) strlen($body))
            ->withHeader('Cache-Control', 'no-store');
    }

    private function fail(ResponseInterface $response, string $what, \Throwable $e): ResponseInterface
    {
        SecureLogger::error('[MobileApi] staff ' . $what . ' failed: ' . $e->getMessage());

        return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
    }
}
