<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Plugins\BookClub\Modules\LendingModule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Lending module (Prestito tra membri, plan §7.17): members offer their
 * PERSONAL copies of the club's books, other members request them, the
 * lender hands the copy over and either side marks it returned.
 *
 * Every handler re-checks per-club module enablement (routes are global)
 * and requires either an ACTIVE membership or manage capability (admins/staff,
 * who need no membership row — see mayUse()). The state machine
 * lives in LendingRepo as conditional UPDATEs, so two concurrent requests
 * for the same copy cannot both succeed ("first wins").
 */
class LendingController extends BaseController
{
    private LendingModule $module;
    private LendingRepo $lending;

    public function __construct(\mysqli $db, Repo $repo, LendingModule $module)
    {
        parent::__construct($db, $repo);
        $this->module = $module;
        $this->lending = new LendingRepo($db);
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

    private function lendingPath(string $slug): string
    {
        return '/book-club/' . $slug . '/lending';
    }

    /**
     * Loan row scoped to the club, or null (→ 404).
     *
     * @param array<string, mixed> $club
     * @return array<string, mixed>|null
     */
    private function clubLoan(array $club, int $loanId): ?array
    {
        $loan = $this->lending->loanById($loanId);
        if ($loan === null || (int) $loan['club_id'] !== (int) $club['id']) {
            return null;
        }
        return $loan;
    }

    /**
     * Club books offerable in the form: pending-moderation proposals stay
     * manager-only, like everywhere else in the plugin.
     *
     * @param array<string, mixed> $club
     * @return list<array<string, mixed>>
     */
    private function offerableBooks(array $club): array
    {
        $books = $this->repo->clubBooks((int) $club['id']);
        return array_values(array_filter(
            $books,
            static fn(array $b): bool => (string) $b['state'] !== BookClubPlugin::STATE_PENDING
        ));
    }

    // ------------------------------------------------------------------
    // GET /book-club/{slug}/lending  (active members + managers)
    // ------------------------------------------------------------------

    public function show(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->mayUse($club)) {
            return $this->notFound($response);
        }
        $clubId = (int) $club['id'];
        $userId = (int) $this->userId();

        return $this->renderPublic($response, 'public/lending', [
            'club' => $club,
            'userId' => $userId,
            'books' => $this->offerableBooks($club),
            'openOffers' => $this->lending->openOffers($clubId),
            'myOffers' => $this->lending->myOffers($clubId, $userId),
            'myBorrowings' => $this->lending->myBorrowings($clubId, $userId),
        ], __('Prestito tra membri') . ' — ' . (string) $club['name']);
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/lending/offer — offer a personal copy
    // ------------------------------------------------------------------

    public function offer(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->mayUse($club)) {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();
        $userId = (int) $this->userId();

        $clubBookId = self::intOrNull($body, 'club_book_id');
        $clubBook = $clubBookId !== null ? $this->repo->clubBook($clubBookId) : null;
        if (
            $clubBook === null
            || (int) $clubBook['club_id'] !== (int) $club['id']
            || (string) $clubBook['state'] === BookClubPlugin::STATE_PENDING
        ) {
            $this->flash('error', __('Seleziona un libro del club.'));
            return $this->redirect($response, $this->lendingPath($slug));
        }

        if ($this->lending->hasOpenOffer((int) $clubBook['id'], $userId)) {
            $this->flash('error', __('Hai già un\'offerta aperta per questo libro.'));
            return $this->redirect($response, $this->lendingPath($slug));
        }

        $notes = self::str($body, 'notes', 500);
        if ($this->lending->createOffer((int) $club['id'], (int) $clubBook['id'], $userId, $notes) !== null) {
            $this->flash('success', __('Copia offerta al club.'));
        } else {
            $this->flash('error', __('Impossibile completare l\'operazione. Riprova.'));
        }
        return $this->redirect($response, $this->lendingPath($slug));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/lending/{loanId}/request — borrower asks
    // ------------------------------------------------------------------

    public function request(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $loanId): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->mayUse($club)) {
            return $this->notFound($response);
        }
        $loan = $this->clubLoan($club, $loanId);
        if ($loan === null) {
            return $this->notFound($response);
        }
        $userId = (int) $this->userId();
        if ((int) $loan['lender_id'] === $userId) {
            $this->flash('error', __('Non puoi richiedere una copia che offri tu.'));
            return $this->redirect($response, $this->lendingPath($slug));
        }
        // Conditional UPDATE: only succeeds while the row is still 'offered'
        // and unclaimed — the FIRST requester wins.
        if ($this->lending->requestLoan($loanId, $userId)) {
            $this->flash('success', __('Richiesta inviata al proprietario della copia.'));
        } else {
            $this->flash('error', __('Questa copia non è più disponibile.'));
        }
        return $this->redirect($response, $this->lendingPath($slug));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/lending/{loanId}/decline — lender declines
    // ------------------------------------------------------------------

    public function decline(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $loanId): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->mayUse($club)) {
            return $this->notFound($response);
        }
        $loan = $this->clubLoan($club, $loanId);
        if ($loan === null || (int) $loan['lender_id'] !== (int) $this->userId()) {
            return $this->notFound($response);
        }
        if ($this->lending->declineRequest($loanId)) {
            $this->flash('success', __('Richiesta rifiutata: la copia è di nuovo disponibile.'));
        } else {
            $this->flash('error', __('Impossibile completare l\'operazione. Riprova.'));
        }
        return $this->redirect($response, $this->lendingPath($slug));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/lending/{loanId}/handover — lender confirms
    // ------------------------------------------------------------------

    public function handOver(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $loanId): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->mayUse($club)) {
            return $this->notFound($response);
        }
        $loan = $this->clubLoan($club, $loanId);
        if ($loan === null || (int) $loan['lender_id'] !== (int) $this->userId()) {
            return $this->notFound($response);
        }
        $dueOn = self::dateOrNull(self::str($request->getParsedBody(), 'due_on', 10));
        if ($this->lending->handOver($loanId, $dueOn)) {
            $this->flash('success', __('Copia consegnata: buona lettura!'));
        } else {
            $this->flash('error', __('Impossibile completare l\'operazione. Riprova.'));
        }
        return $this->redirect($response, $this->lendingPath($slug));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/lending/{loanId}/return — lender OR borrower
    // ------------------------------------------------------------------

    public function markReturned(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $loanId): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->mayUse($club)) {
            return $this->notFound($response);
        }
        $loan = $this->clubLoan($club, $loanId);
        $userId = (int) $this->userId();
        if (
            $loan === null
            || ((int) $loan['lender_id'] !== $userId && (int) ($loan['borrower_id'] ?? 0) !== $userId)
        ) {
            return $this->notFound($response);
        }
        if ($this->lending->markReturned($loanId)) {
            $this->flash('success', __('Copia segnata come restituita.'));
        } else {
            $this->flash('error', __('Impossibile completare l\'operazione. Riprova.'));
        }
        return $this->redirect($response, $this->lendingPath($slug));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/lending/{loanId}/cancel — lender withdraws
    // ------------------------------------------------------------------

    public function cancel(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $loanId): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->mayUse($club)) {
            return $this->notFound($response);
        }
        $loan = $this->clubLoan($club, $loanId);
        if ($loan === null || (int) $loan['lender_id'] !== (int) $this->userId()) {
            return $this->notFound($response);
        }
        if ($this->lending->cancel($loanId)) {
            $this->flash('success', __('Offerta annullata.'));
        } else {
            $this->flash('error', __('Impossibile completare l\'operazione. Riprova.'));
        }
        return $this->redirect($response, $this->lendingPath($slug));
    }

    // ------------------------------------------------------------------
    // Input helpers
    // ------------------------------------------------------------------

    /** Normalise a date input ("2026-07-20") to SQL DATE, or null. */
    private static function dateOrNull(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $raw));
        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }
}
