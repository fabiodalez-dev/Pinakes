<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Plugins\BookClub\Modules\ReadingModule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reading module (plan §7.6): shared-reading page per club book — sections
 * with discussion-unlock dates, personal progress tracker, club aggregate —
 * plus manager-only inline section CRUD.
 *
 * Every handler re-checks per-club module enablement (routes are global).
 */
class ReadingController extends BaseController
{
    private ReadingModule $module;
    private ReadingRepo $reading;

    public function __construct(\mysqli $db, Repo $repo, ReadingModule $module)
    {
        parent::__construct($db, $repo);
        $this->module = $module;
        $this->reading = new ReadingRepo($db);
    }

    public const UNITS = ['chapter', 'part', 'pages', 'custom'];

    /**
     * Resolve club + book for a route, enforcing module enablement and
     * book-belongs-to-club. Returns null when any check fails (→ 404).
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    private function resolve(string $slug, int $bookId): ?array
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->module->enabledFor($club)) {
            return null;
        }
        $book = $this->repo->clubBook($bookId);
        if ($book === null || (int) $book['club_id'] !== (int) $club['id']) {
            return null;
        }
        return [$club, $book];
    }

    private function readingPath(string $slug, int $bookId): string
    {
        return '/book-club/' . $slug . '/reading/' . $bookId;
    }

    // ------------------------------------------------------------------
    // GET /book-club/{slug}/reading/{bookId}
    // ------------------------------------------------------------------

    public function show(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $bookId): ResponseInterface
    {
        $resolved = $this->resolve($slug, $bookId);
        if ($resolved === null) {
            return $this->notFound($response);
        }
        [$club, $book] = $resolved;
        if (!$this->canView($club)) {
            return $this->notFound($response);
        }
        // Books awaiting moderation are manager-only everywhere.
        if ($book['state'] === BookClubPlugin::STATE_PENDING && !$this->canManage($club)) {
            return $this->notFound($response);
        }

        $states = $this->repo->workflowStates($club);
        $userId = $this->userId();
        $isMember = $this->isActiveMember($club);

        return $this->renderPublic($response, 'public/reading', [
            'club' => $club,
            'book' => $book,
            'state' => Repo::stateByKey($states, (string) $book['state']),
            'isMember' => $isMember,
            'canManage' => $this->canManage($club),
            'loggedIn' => $userId !== null,
            'sections' => $this->reading->sections($bookId),
            'sectionPassed' => $this->reading->sectionPassedCounts($bookId),
            'myProgress' => $userId !== null ? $this->reading->progressRow($bookId, $userId) : null,
            'aggregate' => $this->reading->aggregate($bookId),
            'memberCount' => $this->repo->countActiveMembers((int) $club['id']),
        ], (string) $book['titolo']);
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/reading/{bookId}/progress
    // ------------------------------------------------------------------

    public function updateProgress(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $bookId): ResponseInterface
    {
        $resolved = $this->resolve($slug, $bookId);
        if ($resolved === null) {
            return $this->notFound($response);
        }
        [$club] = $resolved;
        if (!$this->isActiveMember($club)) {
            $this->flash('error', __('Solo i membri attivi del club possono aggiornare il progresso.'));
            return $this->redirect($response, $this->readingPath($slug, $bookId));
        }
        $userId = (int) $this->userId();
        $body = $request->getParsedBody();

        $percent = self::intOrNull($body, 'percent') ?? 0;
        $percent = max(0, min(100, $percent));

        $sectionId = self::intOrNull($body, 'section_id');
        if ($sectionId !== null) {
            $section = $this->reading->sectionById($sectionId);
            if ($section === null || (int) $section['club_book_id'] !== $bookId) {
                $sectionId = null;
            }
        }

        $finishedFlag = is_array($body) && !empty($body['finished']);
        $existing = $this->reading->progressRow($bookId, $userId);
        $finishedAt = null;
        if ($percent >= 100 || $finishedFlag) {
            $percent = $finishedFlag ? max($percent, 100) : $percent;
            // Set once: keep the original timestamp on later updates.
            $finishedAt = !empty($existing['finished_at'])
                ? (string) $existing['finished_at']
                : date('Y-m-d H:i:s');
        }

        if ($this->reading->upsertProgress($bookId, $userId, $percent, $sectionId, $finishedAt)) {
            $this->flash('success', __('Progresso aggiornato.'));
        } else {
            $this->flash('error', __('Impossibile salvare il progresso. Riprova.'));
        }
        return $this->redirect($response, $this->readingPath($slug, $bookId));
    }

    // ------------------------------------------------------------------
    // Section CRUD (managers)
    // ------------------------------------------------------------------

    public function addSection(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $bookId): ResponseInterface
    {
        $resolved = $this->resolve($slug, $bookId);
        if ($resolved === null) {
            return $this->notFound($response);
        }
        [$club] = $resolved;
        if (!$this->canManage($club)) {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();
        $title = self::str($body, 'title', 190);
        if ($title === '') {
            $this->flash('error', __('Il titolo della sezione è obbligatorio.'));
            return $this->redirect($response, $this->readingPath($slug, $bookId));
        }
        $unit = self::str($body, 'unit', 20);
        if (!in_array($unit, self::UNITS, true)) {
            $unit = 'chapter';
        }
        $rangeFrom = self::intOrNull($body, 'range_from');
        $rangeTo = self::intOrNull($body, 'range_to');
        $discussFrom = self::dateOrNull(self::str($body, 'discuss_from', 20));

        if ($this->reading->addSection($bookId, $title, $unit, $rangeFrom, $rangeTo, $discussFrom) !== null) {
            $this->flash('success', __('Sezione aggiunta.'));
        } else {
            $this->flash('error', __('Impossibile aggiungere la sezione.'));
        }
        return $this->redirect($response, $this->readingPath($slug, $bookId));
    }

    public function updateSection(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $bookId, int $sectionId): ResponseInterface
    {
        $resolved = $this->resolve($slug, $bookId);
        if ($resolved === null) {
            return $this->notFound($response);
        }
        [$club] = $resolved;
        if (!$this->canManage($club)) {
            return $this->notFound($response);
        }
        $section = $this->reading->sectionById($sectionId);
        if ($section === null || (int) $section['club_book_id'] !== $bookId) {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();
        $title = self::str($body, 'title', 190);
        if ($title === '') {
            $title = (string) $section['title'];
        }
        $sort = self::intOrNull($body, 'sort') ?? (int) $section['sort'];
        $discussFrom = self::dateOrNull(self::str($body, 'discuss_from', 20));

        if ($this->reading->updateSection($sectionId, $title, $sort, $discussFrom)) {
            $this->flash('success', __('Sezione aggiornata.'));
        } else {
            $this->flash('error', __('Impossibile aggiornare la sezione.'));
        }
        return $this->redirect($response, $this->readingPath($slug, $bookId));
    }

    public function deleteSection(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $bookId, int $sectionId): ResponseInterface
    {
        $resolved = $this->resolve($slug, $bookId);
        if ($resolved === null) {
            return $this->notFound($response);
        }
        [$club] = $resolved;
        if (!$this->canManage($club)) {
            return $this->notFound($response);
        }
        $section = $this->reading->sectionById($sectionId);
        if ($section === null || (int) $section['club_book_id'] !== $bookId) {
            return $this->notFound($response);
        }
        if ($this->reading->deleteSection($sectionId)) {
            $this->flash('success', __('Sezione eliminata.'));
        } else {
            $this->flash('error', __('Impossibile eliminare la sezione.'));
        }
        return $this->redirect($response, $this->readingPath($slug, $bookId));
    }

    // ------------------------------------------------------------------
    // Input helpers
    // ------------------------------------------------------------------

    /** Normalise a date input ("2026-07-05") to SQL DATE, or null. */
    private static function dateOrNull(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        if ($dt === false || $dt->format('Y-m-d') !== $raw) {
            return null;
        }
        return $raw;
    }
}
