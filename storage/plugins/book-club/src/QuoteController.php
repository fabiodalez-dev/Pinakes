<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Plugins\BookClub\Modules\QuotesModule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Quotes module (plan §7.8): per-club quotes with page + personal note and
 * private/club/public visibility, personal annotations per club book
 * (private or shared with the club), and the member's own-data export in
 * Markdown/CSV.
 *
 * Every handler re-checks per-club module enablement (routes are global).
 * The page and both exports are for active members and managers only; a
 * member may edit the visibility of / delete only their OWN quotes and
 * notes (managers may additionally delete quotes/club-shared notes as a
 * moderation measure).
 */
class QuoteController extends BaseController
{
    private QuotesModule $module;
    private QuoteRepo $quotes;

    public function __construct(\mysqli $db, Repo $repo, QuotesModule $module)
    {
        parent::__construct($db, $repo);
        $this->module = $module;
        $this->quotes = new QuoteRepo($db);
    }

    /**
     * Resolve a club by slug enforcing module enablement (→ null = 404).
     *
     * @return array<string, mixed>|null
     */
    private function resolve(string $slug): ?array
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->module->enabledFor($club)) {
            return null;
        }
        return $club;
    }

    /** @param array<string, mixed> $club */
    private function mayUse(array $club): bool
    {
        return $this->canView($club) && ($this->isActiveMember($club) || $this->canManage($club));
    }

    private function quotesPath(string $slug, string $tab = ''): string
    {
        return '/book-club/' . $slug . '/quotes' . ($tab !== '' ? '?tab=' . $tab : '');
    }

    /**
     * Club books usable in the forms: pending-moderation proposals stay
     * manager-only, like everywhere else in the plugin.
     *
     * @param array<string, mixed> $club
     * @return list<array<string, mixed>>
     */
    private function selectableBooks(array $club): array
    {
        $books = $this->repo->clubBooks((int) $club['id']);
        if ($this->canManage($club)) {
            return $books;
        }
        return array_values(array_filter(
            $books,
            static fn(array $b): bool => (string) $b['state'] !== BookClubPlugin::STATE_PENDING
        ));
    }

    // ------------------------------------------------------------------
    // GET /book-club/{slug}/quotes  (active members + managers)
    // ------------------------------------------------------------------

    public function show(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->mayUse($club)) {
            return $this->notFound($response);
        }
        $clubId = (int) $club['id'];
        $userId = (int) $this->userId();

        $query = $request->getQueryParams();
        $tab = ($query['tab'] ?? '') === 'notes' ? 'notes' : 'quotes';

        return $this->renderPublic($response, 'public/quotes', [
            'club' => $club,
            'tab' => $tab,
            'userId' => $userId,
            'canManage' => $this->canManage($club),
            'books' => $this->selectableBooks($club),
            'quotes' => $this->quotes->clubQuotes($clubId, $userId),
            'myNotes' => $this->quotes->myNotes($clubId, $userId),
            'clubNotes' => $this->quotes->clubNotesOfOthers($clubId, $userId),
        ], __('Citazioni e annotazioni') . ' — ' . (string) $club['name']);
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/quotes  — add a quote
    // ------------------------------------------------------------------

    public function addQuote(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->mayUse($club)) {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();

        $quote = self::str($body, 'quote', 5000);
        if ($quote === '') {
            $this->flash('error', __('Il testo della citazione è obbligatorio.'));
            return $this->redirect($response, $this->quotesPath($slug));
        }

        $libroId = self::intOrNull($body, 'libro_id');
        if ($libroId === null || !$this->repo->bookAlreadyInClub((int) $club['id'], $libroId)) {
            $this->flash('error', __('Seleziona un libro del club.'));
            return $this->redirect($response, $this->quotesPath($slug));
        }

        $page = self::intOrNull($body, 'page');
        if ($page !== null && $page < 0) {
            $page = null;
        }
        $note = self::str($body, 'note', 2000);
        $visibility = self::str($body, 'visibility', 10);
        if (!in_array($visibility, QuoteRepo::QUOTE_VISIBILITIES, true)) {
            $visibility = 'club';
        }

        if ($this->quotes->addQuote((int) $this->userId(), $libroId, (int) $club['id'], $quote, $page, $note, $visibility) !== null) {
            $this->flash('success', __('Citazione aggiunta.'));
        } else {
            $this->flash('error', __('Impossibile salvare la citazione. Riprova.'));
        }
        return $this->redirect($response, $this->quotesPath($slug));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/quotes/{quoteId}/visibility  (owner)
    // ------------------------------------------------------------------

    public function updateQuoteVisibility(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $quoteId): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->mayUse($club)) {
            return $this->notFound($response);
        }
        $quote = $this->quotes->quoteById($quoteId);
        if ($quote === null || (int) ($quote['club_id'] ?? 0) !== (int) $club['id']) {
            return $this->notFound($response);
        }
        if ((int) $quote['user_id'] !== (int) $this->userId()) {
            return $this->notFound($response);
        }
        $visibility = self::str($request->getParsedBody(), 'visibility', 10);
        if (!in_array($visibility, QuoteRepo::QUOTE_VISIBILITIES, true)) {
            $this->flash('error', __('Visibilità non valida.'));
            return $this->redirect($response, $this->quotesPath($slug));
        }
        if ($this->quotes->setQuoteVisibility($quoteId, $visibility)) {
            $this->flash('success', __('Visibilità della citazione aggiornata.'));
        } else {
            $this->flash('error', __('Impossibile aggiornare la citazione.'));
        }
        return $this->redirect($response, $this->quotesPath($slug));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/quotes/{quoteId}/delete  (owner or manager)
    // ------------------------------------------------------------------

    public function deleteQuote(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $quoteId): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->mayUse($club)) {
            return $this->notFound($response);
        }
        $quote = $this->quotes->quoteById($quoteId);
        if ($quote === null || (int) ($quote['club_id'] ?? 0) !== (int) $club['id']) {
            return $this->notFound($response);
        }
        if ((int) $quote['user_id'] !== (int) $this->userId() && !$this->canManage($club)) {
            return $this->notFound($response);
        }
        if ($this->quotes->deleteQuote($quoteId)) {
            $this->flash('success', __('Citazione eliminata.'));
        } else {
            $this->flash('error', __('Impossibile eliminare la citazione.'));
        }
        return $this->redirect($response, $this->quotesPath($slug));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/quotes/notes  — add an annotation
    // ------------------------------------------------------------------

    public function addNote(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->mayUse($club)) {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();

        $text = self::str($body, 'body', 20000);
        if ($text === '') {
            $this->flash('error', __('Il testo dell\'annotazione è obbligatorio.'));
            return $this->redirect($response, $this->quotesPath($slug, 'notes'));
        }
        $clubBookId = self::intOrNull($body, 'club_book_id');
        $clubBook = $clubBookId !== null ? $this->repo->clubBook($clubBookId) : null;
        if ($clubBook === null || (int) $clubBook['club_id'] !== (int) $club['id']) {
            $this->flash('error', __('Seleziona un libro del club.'));
            return $this->redirect($response, $this->quotesPath($slug, 'notes'));
        }
        $visibility = self::str($body, 'visibility', 10);
        if (!in_array($visibility, QuoteRepo::NOTE_VISIBILITIES, true)) {
            $visibility = 'private';
        }

        if ($this->quotes->addNote((int) $this->userId(), (int) $clubBook['id'], $text, $visibility) !== null) {
            $this->flash('success', __('Annotazione aggiunta.'));
        } else {
            $this->flash('error', __('Impossibile salvare l\'annotazione. Riprova.'));
        }
        return $this->redirect($response, $this->quotesPath($slug, 'notes'));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/quotes/notes/{noteId}/update  (owner)
    // ------------------------------------------------------------------

    public function updateNote(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $noteId): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->mayUse($club)) {
            return $this->notFound($response);
        }
        $note = $this->quotes->noteById($noteId);
        if ($note === null || (int) $note['club_id'] !== (int) $club['id']) {
            return $this->notFound($response);
        }
        if ((int) $note['user_id'] !== (int) $this->userId()) {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();
        $text = self::str($body, 'body', 20000);
        if ($text === '') {
            $text = (string) $note['body'];
        }
        $visibility = self::str($body, 'visibility', 10);
        if (!in_array($visibility, QuoteRepo::NOTE_VISIBILITIES, true)) {
            $visibility = (string) $note['visibility'];
        }
        if ($this->quotes->updateNote($noteId, $text, $visibility)) {
            $this->flash('success', __('Annotazione aggiornata.'));
        } else {
            $this->flash('error', __('Impossibile aggiornare l\'annotazione.'));
        }
        return $this->redirect($response, $this->quotesPath($slug, 'notes'));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/quotes/notes/{noteId}/delete  (owner; managers
    // may remove club-shared notes)
    // ------------------------------------------------------------------

    public function deleteNote(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $noteId): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->mayUse($club)) {
            return $this->notFound($response);
        }
        $note = $this->quotes->noteById($noteId);
        if ($note === null || (int) $note['club_id'] !== (int) $club['id']) {
            return $this->notFound($response);
        }
        $isOwner = (int) $note['user_id'] === (int) $this->userId();
        $moderatorDelete = !$isOwner && (string) $note['visibility'] === 'club' && $this->canManage($club);
        if (!$isOwner && !$moderatorDelete) {
            return $this->notFound($response);
        }
        if ($this->quotes->deleteNote($noteId)) {
            $this->flash('success', __('Annotazione eliminata.'));
        } else {
            $this->flash('error', __('Impossibile eliminare l\'annotazione.'));
        }
        return $this->redirect($response, $this->quotesPath($slug, 'notes'));
    }

    // ------------------------------------------------------------------
    // Export of the member's OWN data
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $club
     * @return array{quotes: list<array<string, mixed>>, notes: list<array<string, mixed>>}
     */
    private function myData(array $club): array
    {
        $clubId = (int) $club['id'];
        $userId = (int) $this->userId();
        return [
            'quotes' => $this->quotes->myQuotes($clubId, $userId),
            'notes' => $this->quotes->myNotes($clubId, $userId),
        ];
    }

    private function visibilityLabel(string $visibility): string
    {
        return match ($visibility) {
            'private' => __('Privata'),
            'public' => __('Pubblica'),
            default => __('Solo club'),
        };
    }

    // GET /book-club/{slug}/quotes/export.md
    public function exportMarkdown(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->mayUse($club)) {
            return $this->notFound($response);
        }
        $data = $this->myData($club);

        $lines = [];
        $lines[] = '# ' . __('Le mie citazioni e annotazioni') . ' — ' . (string) $club['name'];
        $lines[] = '';
        $lines[] = '_' . sprintf(__('Esportato il %s'), date('d/m/Y H:i')) . '_';
        $lines[] = '';
        $lines[] = '## ' . __('Citazioni');
        $lines[] = '';
        if ($data['quotes'] === []) {
            $lines[] = '_' . __('Nessuna citazione.') . '_';
            $lines[] = '';
        }
        foreach ($data['quotes'] as $quote) {
            $title = (string) $quote['titolo'];
            $authors = (string) ($quote['autori'] ?? '');
            $lines[] = '### ' . $title . ($authors !== '' ? ' — ' . $authors : '');
            $lines[] = '';
            foreach (preg_split('/\R/', (string) $quote['quote']) ?: [] as $qLine) {
                $lines[] = '> ' . $qLine;
            }
            $meta = [];
            if ($quote['page'] !== null) {
                $meta[] = sprintf(__('pag. %d'), (int) $quote['page']);
            }
            $meta[] = $this->visibilityLabel((string) $quote['visibility']);
            $meta[] = (string) $quote['created_at'];
            $lines[] = '>';
            $lines[] = '> — ' . implode(' · ', $meta);
            if ((string) ($quote['note'] ?? '') !== '') {
                $lines[] = '';
                $lines[] = '**' . __('Nota') . ':** ' . (string) $quote['note'];
            }
            $lines[] = '';
        }
        $lines[] = '## ' . __('Annotazioni');
        $lines[] = '';
        if ($data['notes'] === []) {
            $lines[] = '_' . __('Nessuna annotazione.') . '_';
            $lines[] = '';
        }
        foreach ($data['notes'] as $note) {
            $lines[] = '### ' . (string) $note['titolo'];
            $lines[] = '';
            $lines[] = (string) $note['body'];
            $lines[] = '';
            $lines[] = '_' . $this->visibilityLabel((string) $note['visibility']) . ' · ' . (string) $note['created_at'] . '_';
            $lines[] = '';
        }

        $response->getBody()->write(implode("\n", $lines));
        return $response
            ->withHeader('Content-Type', 'text/markdown; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="book-club-' . $slug . '-quotes-' . date('Ymd') . '.md"');
    }

    // GET /book-club/{slug}/quotes/export.csv
    public function exportCsv(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->mayUse($club)) {
            return $this->notFound($response);
        }
        $data = $this->myData($club);

        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return $this->notFound($response);
        }
        // UTF-8 BOM so Excel opens accented titles correctly.
        fwrite($fh, "\xEF\xBB\xBF");

        fputcsv($fh, ['# ' . __('Citazioni')]);
        fputcsv($fh, [__('Libro'), __('Autori'), __('Citazione'), __('Pagina'), __('Nota'), __('Visibilità'), __('Creata il')]);
        foreach ($data['quotes'] as $quote) {
            fputcsv($fh, [
                (string) $quote['titolo'],
                (string) ($quote['autori'] ?? ''),
                (string) $quote['quote'],
                $quote['page'] !== null ? (int) $quote['page'] : '',
                (string) ($quote['note'] ?? ''),
                (string) $quote['visibility'],
                (string) $quote['created_at'],
            ]);
        }
        fwrite($fh, "\n");
        fputcsv($fh, ['# ' . __('Annotazioni')]);
        fputcsv($fh, [__('Libro'), __('Testo'), __('Visibilità'), __('Creata il'), __('Aggiornata il')]);
        foreach ($data['notes'] as $note) {
            fputcsv($fh, [
                (string) $note['titolo'],
                (string) $note['body'],
                (string) $note['visibility'],
                (string) $note['created_at'],
                (string) ($note['updated_at'] ?? ''),
            ]);
        }

        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="book-club-' . $slug . '-quotes-' . date('Ymd') . '.csv"');
    }
}
