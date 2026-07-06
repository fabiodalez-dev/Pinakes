<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Plugins\BookClub\Modules\AffinityModule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Affinity module (plan §7.16): the members-only affinity + suggestions
 * page and the privacy opt-in toggle.
 *
 * PRIVACY: affinity scores are computed and shown ONLY between members who
 * opted in (bookclub_affinity_optin). A member who never opted in — or who
 * opted out — never appears in anyone's list; opting out also purges every
 * stored pair involving the user immediately.
 *
 * Every handler re-checks per-club module enablement (routes are global).
 */
class AffinityController extends BaseController
{
    private AffinityModule $module;
    private AffinityRepo $affinity;

    public function __construct(\mysqli $db, Repo $repo, AffinityModule $module)
    {
        parent::__construct($db, $repo);
        $this->module = $module;
        $this->affinity = new AffinityRepo($db);
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

    private function affinityPath(string $slug): string
    {
        return '/book-club/' . $slug . '/affinity';
    }

    // ------------------------------------------------------------------
    // GET /book-club/{slug}/affinity  (active members + managers)
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

        // Lazy recompute with the same once-per-day guard as the
        // maintenance tick.
        if ($this->affinity->isStale($clubId)) {
            $this->affinity->recomputeClub($clubId);
        }

        $optedIn = $isMember && $userId !== null && $this->affinity->isOptedIn($clubId, $userId);

        $states = $this->repo->workflowStates($club);
        $finishedKeys = AffinityRepo::finishedStateKeys($states);
        $topGenres = $this->affinity->topFinishedGenres($clubId, $finishedKeys, 3);
        $genreIds = array_map(static fn(array $g): int => (int) $g['id'], $topGenres);

        return $this->renderPublic($response, 'public/affinity', [
            'club' => $club,
            'isMember' => $isMember,
            'canManage' => $canManage,
            'optedIn' => $optedIn,
            'optedInCount' => $this->affinity->optedInCount($clubId),
            'myAffinities' => $optedIn ? $this->affinity->affinitiesFor($clubId, $userId) : [],
            'topGenres' => $topGenres,
            'suggestedBooks' => $this->affinity->suggestedBooks($clubId, $genreIds, 10),
            'similarAuthors' => $this->affinity->similarAuthors($clubId, $finishedKeys, 5),
        ], __('Affinità e suggerimenti') . ' — ' . (string) $club['name']);
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/affinity/optin  (active members)
    // ------------------------------------------------------------------

    public function toggleOptIn(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->canView($club)) {
            return $this->notFound($response);
        }
        if (!$this->isActiveMember($club)) {
            $this->flash('error', __('Solo i membri attivi del club possono gestire la condivisione delle affinità.'));
            return $this->redirect($response, $this->affinityPath($slug));
        }
        $clubId = (int) $club['id'];
        $userId = (int) $this->userId();

        if ($this->affinity->isOptedIn($clubId, $userId)) {
            // optOut() also purges every stored pair involving the user.
            if ($this->affinity->optOut($clubId, $userId)) {
                $this->flash('success', __('Hai disattivato la condivisione delle affinità.'));
            } else {
                $this->flash('error', __('Impossibile aggiornare la condivisione delle affinità. Riprova.'));
            }
        } else {
            if ($this->affinity->optIn($clubId, $userId)) {
                // Recompute right away (guard bypassed on purpose) so the
                // new member sees their scores without waiting a day.
                $this->affinity->recomputeClub($clubId);
                $this->flash('success', __('Hai attivato la condivisione delle affinità.'));
            } else {
                $this->flash('error', __('Impossibile aggiornare la condivisione delle affinità. Riprova.'));
            }
        }
        return $this->redirect($response, $this->affinityPath($slug));
    }
}
