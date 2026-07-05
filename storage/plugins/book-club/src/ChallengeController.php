<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Plugins\BookClub\Modules\ChallengesModule;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Challenges module (plan §7.16 — Reading Challenge): current-year challenge
 * list with progress bars, creation (personal for members, club-wide for
 * managers) and deletion (own personal challenges, or anything for managers).
 *
 * Progress is recomputed lazily on every page view (and by the maintenance
 * tick) from the Reading module's tracker — see ChallengeRepo for the
 * formulas. Every handler re-checks per-club module enablement (routes are
 * registered globally).
 */
class ChallengeController extends BaseController
{
    private ChallengesModule $module;
    private ChallengeRepo $challenges;

    public function __construct(\mysqli $db, Repo $repo, ChallengesModule $module)
    {
        parent::__construct($db, $repo);
        $this->module = $module;
        $this->challenges = new ChallengeRepo($db);
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

    private function challengesPath(string $slug): string
    {
        return '/book-club/' . $slug . '/challenges';
    }

    // ------------------------------------------------------------------
    // GET /book-club/{slug}/challenges  (active members + managers)
    // ------------------------------------------------------------------

    public function show(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->canView($club)) {
            return $this->notFound($response);
        }
        $canManage = $this->canManage($club);
        $isMember = $this->isActiveMember($club);
        if (!$isMember && !$canManage) {
            return $this->notFound($response);
        }
        $clubId = (int) $club['id'];
        $userId = $this->userId();
        $year = (int) date('Y');

        // Lazy recompute so the page never shows stale numbers, even when
        // the maintenance cron has not run yet today.
        try {
            $this->challenges->recomputeClub($clubId, $year);
        } catch (\Throwable $e) {
            SecureLogger::warning('[BookClub:challenges] lazy recompute failed for club ' . $clubId . ': ' . $e->getMessage());
        }

        $clubChallenges = [];
        $personalChallenges = [];
        foreach ($this->challenges->challengesForYear($clubId, $year) as $challenge) {
            if ($challenge['user_id'] === null) {
                $clubChallenges[] = $challenge;
            } else {
                $personalChallenges[] = $challenge;
            }
        }

        return $this->renderPublic($response, 'public/challenges', [
            'club' => $club,
            'year' => $year,
            'clubChallenges' => $clubChallenges,
            'personalChallenges' => $personalChallenges,
            'mine' => $userId !== null ? $this->challenges->userProgressMap($clubId, $year, $userId) : [],
            'userId' => $userId,
            'isMember' => $isMember,
            'canManage' => $canManage,
            'memberCount' => $this->repo->countActiveMembers($clubId),
            'readingReady' => $this->challenges->readingSchemaReady(),
        ], __('Reading Challenge') . ' — ' . (string) $club['name']);
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/challenges  (create)
    // ------------------------------------------------------------------

    public function create(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->canView($club)) {
            return $this->notFound($response);
        }
        $canManage = $this->canManage($club);
        if (!$this->isActiveMember($club) && !$canManage) {
            $this->flash('error', __('Solo i membri attivi del club possono creare una sfida.'));
            return $this->redirect($response, $this->challengesPath($slug));
        }
        $body = $request->getParsedBody();

        $isClubWide = self::str($body, 'scope', 20) === 'club';
        if ($isClubWide && !$canManage) {
            $this->flash('error', __('Solo i gestori del club possono creare sfide di club.'));
            return $this->redirect($response, $this->challengesPath($slug));
        }

        $title = self::str($body, 'title', 190);
        if ($title === '') {
            $this->flash('error', __('Il titolo della sfida è obbligatorio.'));
            return $this->redirect($response, $this->challengesPath($slug));
        }
        $metric = self::str($body, 'metric', 20);
        if (!in_array($metric, ChallengeRepo::METRICS, true)) {
            $this->flash('error', __('Metrica non valida.'));
            return $this->redirect($response, $this->challengesPath($slug));
        }
        $target = self::intOrNull($body, 'target');
        if ($target === null || $target < 1 || $target > 1000000) {
            $this->flash('error', __('L\'obiettivo deve essere un numero positivo.'));
            return $this->redirect($response, $this->challengesPath($slug));
        }

        $userId = (int) $this->userId();
        $year = (int) date('Y');
        $challengeId = $this->challenges->createChallenge(
            (int) $club['id'],
            $isClubWide ? null : $userId,
            $title,
            $metric,
            $target,
            $year,
            $userId
        );
        if ($challengeId === null) {
            $this->flash('error', __('Impossibile creare la sfida. Riprova.'));
            return $this->redirect($response, $this->challengesPath($slug));
        }

        // Immediate first snapshot so the new bar is never empty.
        try {
            $challenge = $this->challenges->challengeById($challengeId);
            if ($challenge !== null) {
                $this->challenges->recomputeChallenge($challenge);
            }
        } catch (\Throwable $e) {
            SecureLogger::warning('[BookClub:challenges] initial recompute failed for challenge ' . $challengeId . ': ' . $e->getMessage());
        }

        $this->flash('success', __('Sfida creata.'));
        return $this->redirect($response, $this->challengesPath($slug));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/challenges/{challengeId}/delete
    // ------------------------------------------------------------------

    public function delete(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $challengeId): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->canView($club)) {
            return $this->notFound($response);
        }
        $challenge = $this->challenges->challengeById($challengeId);
        if ($challenge === null || (int) $challenge['club_id'] !== (int) $club['id']) {
            return $this->notFound($response);
        }
        $userId = $this->userId();
        $ownPersonal = $challenge['user_id'] !== null
            && $userId !== null
            && (int) $challenge['user_id'] === $userId;
        if (!$this->canManage($club) && !$ownPersonal) {
            $this->flash('error', __('Non puoi eliminare questa sfida.'));
            return $this->redirect($response, $this->challengesPath($slug));
        }
        if ($this->challenges->deleteChallenge($challengeId)) {
            $this->flash('success', __('Sfida eliminata.'));
        } else {
            $this->flash('error', __('Impossibile eliminare la sfida.'));
        }
        return $this->redirect($response, $this->challengesPath($slug));
    }
}
