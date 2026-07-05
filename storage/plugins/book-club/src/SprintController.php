<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Plugins\BookClub\Modules\SprintsModule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Sprints module (plan §7.17 — Reading Sprint): timed group reading
 * sessions. Members schedule a sprint (optional club book, start datetime,
 * 5–480 minutes), join/leave before the start, the status is derived from
 * the clock (scheduled → running → done, explicit cancel by the creator or
 * a manager) and once the sprint is over every participant logs the pages
 * read for the results board.
 *
 * Every handler re-checks per-club module enablement (routes are global).
 */
class SprintController extends BaseController
{
    public const MIN_DURATION = 5;
    public const MAX_DURATION = 480;
    public const MAX_PAGES = 10000;

    private SprintsModule $module;
    private ExtensionsRepo $ext;

    public function __construct(\mysqli $db, Repo $repo, SprintsModule $module)
    {
        parent::__construct($db, $repo);
        $this->module = $module;
        $this->ext = new ExtensionsRepo($db);
    }

    /**
     * Resolve the club for a route, enforcing module enablement.
     *
     * @return array<string, mixed>|null
     */
    private function resolveClub(string $slug): ?array
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->module->enabledFor($club)) {
            return null;
        }
        return $club;
    }

    private function sprintsPath(string $slug): string
    {
        return '/book-club/' . $slug . '/sprints';
    }

    // ------------------------------------------------------------------
    // GET /book-club/{slug}/sprints
    // ------------------------------------------------------------------

    public function index(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->resolveClub($slug);
        if ($club === null || !$this->canView($club)) {
            return $this->notFound($response);
        }
        $clubId = (int) $club['id'];
        $userId = $this->userId();

        $participantsBySprint = $this->ext->participantsByClubSprint($clubId);
        $now = time();
        $sprints = [];
        foreach ($this->ext->sprintsForClub($clubId) as $sprint) {
            $sprintId = (int) $sprint['id'];
            $participants = $participantsBySprint[$sprintId] ?? [];
            $mine = null;
            foreach ($participants as $p) {
                if ($userId !== null && (int) $p['user_id'] === $userId) {
                    $mine = $p;
                    break;
                }
            }
            $total = 0;
            foreach ($participants as $p) {
                $total += (int) ($p['pages_read'] ?? 0);
            }
            $sprint['effective_status'] = ExtensionsRepo::effectiveStatus($sprint, $now);
            $sprint['participants'] = $participants;
            $sprint['mine'] = $mine;
            $sprint['total_pages'] = $total;
            $sprints[] = $sprint;
        }

        $states = $this->repo->workflowStates($club);

        return $this->renderPublic($response, 'public/sprints', [
            'club' => $club,
            'sprints' => $sprints,
            'currentBooks' => $this->ext->currentBooks($clubId, ExtensionsRepo::currentStateKeys($states)),
            'isMember' => $this->isActiveMember($club),
            'canManage' => $this->canManage($club),
            'loggedIn' => $userId !== null,
            'userId' => $userId,
        ], __('Reading Sprint') . ' — ' . (string) $club['name']);
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/sprints
    // ------------------------------------------------------------------

    public function create(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->resolveClub($slug);
        if ($club === null || !$this->canView($club)) {
            return $this->notFound($response);
        }
        if (!$this->isActiveMember($club) && !$this->canManage($club)) {
            $this->flash('error', __('Solo i membri attivi del club possono creare uno sprint.'));
            return $this->redirect($response, $this->sprintsPath($slug));
        }
        $body = $request->getParsedBody();

        $title = self::str($body, 'title', 190);
        if ($title === '') {
            $this->flash('error', __('Il titolo dello sprint è obbligatorio.'));
            return $this->redirect($response, $this->sprintsPath($slug));
        }

        $startsAt = self::dateTimeOrNull(self::str($body, 'starts_at', 30));
        if ($startsAt === null) {
            $this->flash('error', __('Data e ora di inizio non valide.'));
            return $this->redirect($response, $this->sprintsPath($slug));
        }

        $duration = self::intOrNull($body, 'duration_min');
        if ($duration === null || $duration < self::MIN_DURATION || $duration > self::MAX_DURATION) {
            $this->flash('error', sprintf(__('La durata deve essere compresa tra %d e %d minuti.'), self::MIN_DURATION, self::MAX_DURATION));
            return $this->redirect($response, $this->sprintsPath($slug));
        }

        $clubBookId = self::intOrNull($body, 'club_book_id');
        if ($clubBookId !== null) {
            $book = $this->repo->clubBook($clubBookId);
            if ($book === null || (int) $book['club_id'] !== (int) $club['id']) {
                $clubBookId = null;
            }
        }

        $userId = $this->userId();
        $sprintId = $this->ext->createSprint((int) $club['id'], $clubBookId, $title, $startsAt, $duration, $userId);
        if ($sprintId === null) {
            $this->flash('error', __('Impossibile creare lo sprint. Riprova.'));
            return $this->redirect($response, $this->sprintsPath($slug));
        }
        // The creator takes part by default; they can still leave before the start.
        if ($userId !== null) {
            $this->ext->joinSprint($sprintId, $userId);
        }
        $this->flash('success', __('Sprint creato: sei già tra i partecipanti.'));
        return $this->redirect($response, $this->sprintsPath($slug));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/sprints/{id}/join | /leave
    // ------------------------------------------------------------------

    public function join(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $sprintId): ResponseInterface
    {
        $context = $this->resolveSprint($slug, $sprintId);
        if ($context === null) {
            return $this->notFound($response);
        }
        [$club, $sprint] = $context;
        if (!$this->isActiveMember($club)) {
            $this->flash('error', __('Solo i membri attivi del club possono partecipare agli sprint.'));
            return $this->redirect($response, $this->sprintsPath($slug));
        }
        if (ExtensionsRepo::effectiveStatus($sprint) !== 'scheduled') {
            $this->flash('error', __('Le iscrizioni sono aperte solo prima dell\'inizio dello sprint.'));
            return $this->redirect($response, $this->sprintsPath($slug));
        }
        if ($this->ext->joinSprint($sprintId, (int) $this->userId())) {
            $this->flash('success', __('Iscrizione allo sprint registrata.'));
        } else {
            $this->flash('error', __('Impossibile iscriversi allo sprint. Riprova.'));
        }
        return $this->redirect($response, $this->sprintsPath($slug));
    }

    public function leave(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $sprintId): ResponseInterface
    {
        $context = $this->resolveSprint($slug, $sprintId);
        if ($context === null) {
            return $this->notFound($response);
        }
        [$club, $sprint] = $context;
        $userId = $this->userId();
        if ($userId === null || !$this->isActiveMember($club)) {
            return $this->notFound($response);
        }
        if (ExtensionsRepo::effectiveStatus($sprint) !== 'scheduled') {
            $this->flash('error', __('Puoi ritirarti solo prima dell\'inizio dello sprint.'));
            return $this->redirect($response, $this->sprintsPath($slug));
        }
        if ($this->ext->leaveSprint($sprintId, $userId)) {
            $this->flash('success', __('Ti sei ritirato dallo sprint.'));
        } else {
            $this->flash('error', __('Impossibile ritirarsi dallo sprint. Riprova.'));
        }
        return $this->redirect($response, $this->sprintsPath($slug));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/sprints/{id}/cancel
    // ------------------------------------------------------------------

    public function cancel(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $sprintId): ResponseInterface
    {
        $context = $this->resolveSprint($slug, $sprintId);
        if ($context === null) {
            return $this->notFound($response);
        }
        [$club, $sprint] = $context;
        $userId = $this->userId();
        $isCreator = $userId !== null && (int) ($sprint['created_by'] ?? 0) === $userId;
        if (!$isCreator && !$this->canManage($club)) {
            return $this->notFound($response);
        }
        $status = ExtensionsRepo::effectiveStatus($sprint);
        if ($status === 'done' || $status === 'cancelled') {
            $this->flash('error', __('Uno sprint concluso non può essere annullato.'));
            return $this->redirect($response, $this->sprintsPath($slug));
        }
        if ($this->ext->cancelSprint($sprintId)) {
            $this->flash('success', __('Sprint annullato.'));
        } else {
            $this->flash('error', __('Impossibile annullare lo sprint. Riprova.'));
        }
        return $this->redirect($response, $this->sprintsPath($slug));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/sprints/{id}/pages
    // ------------------------------------------------------------------

    public function logPages(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $sprintId): ResponseInterface
    {
        $context = $this->resolveSprint($slug, $sprintId);
        if ($context === null) {
            return $this->notFound($response);
        }
        [$club, $sprint] = $context;
        $userId = $this->userId();
        if ($userId === null || !$this->isActiveMember($club)) {
            return $this->notFound($response);
        }
        if (ExtensionsRepo::effectiveStatus($sprint) !== 'done') {
            $this->flash('error', __('Le pagine lette si registrano solo a sprint concluso.'));
            return $this->redirect($response, $this->sprintsPath($slug));
        }
        if ($this->ext->participantRow($sprintId, $userId) === null) {
            $this->flash('error', __('Solo chi ha partecipato allo sprint può registrare le pagine lette.'));
            return $this->redirect($response, $this->sprintsPath($slug));
        }
        $body = $request->getParsedBody();
        $pages = self::intOrNull($body, 'pages_read');
        if ($pages === null || $pages < 0 || $pages > self::MAX_PAGES) {
            $this->flash('error', __('Numero di pagine non valido.'));
            return $this->redirect($response, $this->sprintsPath($slug));
        }
        if ($this->ext->logPages($sprintId, $userId, $pages)) {
            $this->flash('success', __('Pagine lette registrate.'));
        } else {
            $this->flash('error', __('Impossibile registrare le pagine lette. Riprova.'));
        }
        return $this->redirect($response, $this->sprintsPath($slug));
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Club + sprint for the POST routes, with sprint-belongs-to-club check.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    private function resolveSprint(string $slug, int $sprintId): ?array
    {
        $club = $this->resolveClub($slug);
        if ($club === null || !$this->canView($club)) {
            return null;
        }
        $sprint = $this->ext->sprintById($sprintId);
        if ($sprint === null || (int) $sprint['club_id'] !== (int) $club['id']) {
            return null;
        }
        return [$club, $sprint];
    }
}
