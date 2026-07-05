<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Plugins\BookClub\Modules\SeasonsModule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Seasons module (plan §7.16): manager CRUD for reading seasons, invoked
 * from the seasons panel on the public club page. Only one season per club
 * is `is_current`; only seasons with no books can be deleted.
 *
 * Every handler re-checks per-club module enablement (routes are global)
 * and requires club-manager rights.
 */
class SeasonController extends BaseController
{
    private SeasonsModule $module;
    private StatsRepo $stats;

    public function __construct(\mysqli $db, Repo $repo, SeasonsModule $module)
    {
        parent::__construct($db, $repo);
        $this->module = $module;
        $this->stats = new StatsRepo($db);
    }

    /**
     * Resolve club by slug, enforcing module enablement + manager rights.
     *
     * @return array<string, mixed>|null null → 404
     */
    private function resolve(string $slug): ?array
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->module->enabledFor($club) || !$this->canManage($club)) {
            return null;
        }
        return $club;
    }

    /**
     * Season row belonging to $club, or null.
     *
     * @param array<string, mixed> $club
     * @return array<string, mixed>|null
     */
    private function seasonInClub(array $club, int $seasonId): ?array
    {
        $season = $this->stats->seasonById($seasonId);
        if ($season === null || (int) $season['club_id'] !== (int) $club['id']) {
            return null;
        }
        return $season;
    }

    private function clubPath(string $slug): string
    {
        return '/book-club/' . $slug;
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/seasons/new
    // ------------------------------------------------------------------

    public function create(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null) {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();
        $name = self::str($body, 'name', 190);
        if ($name === '') {
            $this->flash('error', __('Il nome della stagione è obbligatorio.'));
            return $this->redirect($response, $this->clubPath($slug));
        }
        $startsOn = self::dateOrNull(self::str($body, 'starts_on', 20));
        $endsOn = self::dateOrNull(self::str($body, 'ends_on', 20));
        $target = self::intOrNull($body, 'books_target');
        if ($target !== null && $target < 1) {
            $target = null;
        }

        $seasonId = $this->stats->createSeason((int) $club['id'], $name, $startsOn, $endsOn, $target);
        if ($seasonId === null) {
            $this->flash('error', __('Impossibile creare la stagione.'));
            return $this->redirect($response, $this->clubPath($slug));
        }
        if (is_array($body) && !empty($body['make_current'])) {
            $this->stats->setCurrentSeason((int) $club['id'], $seasonId);
        }
        $this->flash('success', __('Stagione creata.'));
        return $this->redirect($response, $this->clubPath($slug));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/seasons/{seasonId}/update
    // ------------------------------------------------------------------

    public function update(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $seasonId): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null) {
            return $this->notFound($response);
        }
        $season = $this->seasonInClub($club, $seasonId);
        if ($season === null) {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();
        $name = self::str($body, 'name', 190);
        if ($name === '') {
            $name = (string) $season['name'];
        }
        $startsOn = self::dateOrNull(self::str($body, 'starts_on', 20));
        $endsOn = self::dateOrNull(self::str($body, 'ends_on', 20));
        $target = self::intOrNull($body, 'books_target');
        if ($target !== null && $target < 1) {
            $target = null;
        }

        if ($this->stats->updateSeason($seasonId, $name, $startsOn, $endsOn, $target)) {
            $this->flash('success', __('Stagione aggiornata.'));
        } else {
            $this->flash('error', __('Impossibile aggiornare la stagione.'));
        }
        return $this->redirect($response, $this->clubPath($slug));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/seasons/{seasonId}/current
    // ------------------------------------------------------------------

    public function setCurrent(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $seasonId): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null) {
            return $this->notFound($response);
        }
        if ($this->seasonInClub($club, $seasonId) === null) {
            return $this->notFound($response);
        }
        if ($this->stats->setCurrentSeason((int) $club['id'], $seasonId)) {
            // Pick up the books already in a current-flagged state right away.
            $this->module->syncSeasonAssignments($club);
            $this->flash('success', __('Stagione impostata come corrente.'));
        } else {
            $this->flash('error', __('Impossibile impostare la stagione corrente.'));
        }
        return $this->redirect($response, $this->clubPath($slug));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/seasons/{seasonId}/delete
    // ------------------------------------------------------------------

    public function delete(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $seasonId): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null) {
            return $this->notFound($response);
        }
        if ($this->seasonInClub($club, $seasonId) === null) {
            return $this->notFound($response);
        }
        if ($this->stats->seasonBookCount($seasonId) > 0) {
            $this->flash('error', __('Puoi eliminare solo stagioni senza libri.'));
            return $this->redirect($response, $this->clubPath($slug));
        }
        if ($this->stats->deleteSeason($seasonId)) {
            $this->flash('success', __('Stagione eliminata.'));
        } else {
            $this->flash('error', __('Impossibile eliminare la stagione.'));
        }
        return $this->redirect($response, $this->clubPath($slug));
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
