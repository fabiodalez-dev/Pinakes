<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\BookRepository;
use App\Support\CatalogueCard;
use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * ISBD catalogue cards (125 × 75 mm) as PDF, for one book or a selection.
 * Soft-deleted books are never printed: BookRepository::getById() filters
 * `deleted_at IS NULL`.
 */
class CatalogueCardController
{
    /** Upper bound for one bulk print, to keep the PDF build bounded. */
    public const MAX_BULK = 200;

    public function single(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if (($off = \App\Support\Features::requireOrRedirect('catalogue_cards', $response)) !== null) {
            return $off;
        }
        $book = (new BookRepository($db))->getById($id);
        if ($book === null) {
            $_SESSION['error_message'] = __('Libro non trovato.');
            return $response->withHeader('Location', url('/admin/books'))->withStatus(302);
        }
        return $this->pdf($response, [$book], 'scheda_' . $id . '.pdf');
    }

    public function bulk(Request $request, Response $response, mysqli $db): Response
    {
        if (($off = \App\Support\Features::requireOrRedirect('catalogue_cards', $response)) !== null) {
            return $off;
        }
        $ids = self::parseIds((string) ($request->getQueryParams()['ids'] ?? ''));
        $repo = new BookRepository($db);
        $books = [];
        foreach ($ids as $id) {
            $book = $repo->getById($id);
            if ($book !== null) {
                $books[] = $book;
            }
        }
        if ($books === []) {
            $_SESSION['error_message'] = __('Seleziona almeno un libro per stampare le schede catalografiche.');
            return $response->withHeader('Location', url('/admin/books'))->withStatus(302);
        }
        return $this->pdf($response, $books, 'schede_catalografiche.pdf');
    }

    /**
     * "3,1,3,x,2" → [3, 1, 2]: positive ints, first occurrence order, capped.
     *
     * @return list<int>
     */
    public static function parseIds(string $raw): array
    {
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $id = filter_var(trim($part), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false && !in_array($id, $ids, true)) {
                $ids[] = $id;
                if (count($ids) >= self::MAX_BULK) {
                    break;
                }
            }
        }
        return $ids;
    }

    /** @param list<array<string, mixed>> $books */
    private function pdf(Response $response, array $books, string $filename): Response
    {
        $name = (string) \App\Support\ConfigStore::get('app.name', 'Biblioteca');
        try {
            $content = CatalogueCard::render($books, $name);
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('Catalogue card PDF failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
            $_SESSION['error_message'] = __('Impossibile generare le schede catalografiche.');
            return $response->withHeader('Location', url('/admin/books'))->withStatus(302);
        }
        $response->getBody()->write($content);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . $filename . '"');
    }
}
