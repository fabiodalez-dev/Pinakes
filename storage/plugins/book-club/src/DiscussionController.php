<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Discussions with spoiler management (plan §7.7): thread list + single
 * thread with one reply level, spoiler-gated posts (mild/full, optionally
 * tied to a reading section), emoji reactions, @mentions and manager
 * moderation (soft delete, lock, pin).
 */
class DiscussionController extends BaseController
{
    private DiscussionRepo $discussions;
    private Modules\ModuleInterface $module;

    public function __construct(\mysqli $db, Repo $repo, DiscussionRepo $discussions, Modules\ModuleInterface $module)
    {
        parent::__construct($db, $repo);
        $this->discussions = $discussions;
        $this->module = $module;
    }

    /**
     * Resolve the club and enforce module enablement + page visibility.
     *
     * @return array<string, mixed>|null
     */
    private function clubOrNull(string $slug): ?array
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null) {
            return null;
        }
        if (!($this->module instanceof Modules\AbstractModule) || !$this->module->enabledFor($club)) {
            return null;
        }
        if (!$this->canView($club)) {
            return null;
        }
        return $club;
    }

    // ------------------------------------------------------------------
    // Thread list
    // ------------------------------------------------------------------

    public function index(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->clubOrNull($slug);
        if ($club === null) {
            return $this->notFound($response);
        }
        $books = array_values(array_filter(
            $this->repo->clubBooks((int) $club['id']),
            static fn(array $b): bool => $b['state'] !== BookClubPlugin::STATE_PENDING
        ));
        return $this->renderPublic($response, 'public/discussions', [
            'club' => $club,
            'threads' => $this->discussions->listThreads((int) $club['id'], 200),
            'books' => $books,
            'sections' => $this->discussions->clubSections((int) $club['id']),
            'isMember' => $this->isActiveMember($club),
            'canManage' => $this->canManage($club),
        ], $club['name'] . ' — ' . __('Discussioni'));
    }

    /**
     * Open a new thread (active members). Threads of kind 'announcement'
     * additionally require the granular `posts.moderate` permission
     * (owner/moderator and Pinakes admin/staff always pass, custom club
     * roles per their JSON).
     */
    public function createThread(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->clubOrNull($slug);
        if ($club === null) {
            return $this->notFound($response);
        }
        if (!$this->isActiveMember($club) && !$this->canManage($club)) {
            $this->flash('error', __('Solo i membri attivi del club possono aprire discussioni.'));
            return $this->redirect($response, '/book-club/' . $slug . '/discussions');
        }

        $body = $request->getParsedBody();
        $title = self::str($body, 'title', 190);
        if ($title === '') {
            $this->flash('error', __('Il titolo della discussione è obbligatorio.'));
            return $this->redirect($response, '/book-club/' . $slug . '/discussions');
        }

        $kind = self::str($body, 'kind', 20);
        if (!in_array($kind, DiscussionRepo::KINDS, true)) {
            $kind = 'free';
        }
        if ($kind === 'announcement' && !$this->can($club, 'posts.moderate')) {
            $this->flash('error', __('Solo i moderatori possono pubblicare annunci.'));
            return $this->redirect($response, '/book-club/' . $slug . '/discussions');
        }

        $clubBookId = self::intOrNull($body, 'club_book_id');
        if ($clubBookId !== null) {
            $book = $this->repo->clubBook($clubBookId);
            if ($book === null || (int) $book['club_id'] !== (int) $club['id'] || $book['state'] === BookClubPlugin::STATE_PENDING) {
                $clubBookId = null;
            }
        }

        $sectionId = self::intOrNull($body, 'section_id');
        if ($sectionId !== null && !$this->discussions->sectionBelongsToClub($sectionId, (int) $club['id'])) {
            $sectionId = null;
        }

        $threadId = $this->discussions->createThread(
            (int) $club['id'],
            $clubBookId,
            $sectionId,
            $kind,
            $title,
            (int) $this->userId()
        );
        if ($threadId === null) {
            $this->flash('error', __('Discussione non creata, riprova.'));
            return $this->redirect($response, '/book-club/' . $slug . '/discussions');
        }
        $this->flash('success', __('Discussione aperta.'));
        return $this->redirect($response, '/book-club/' . $slug . '/discussions/' . $threadId);
    }

    // ------------------------------------------------------------------
    // Single thread
    // ------------------------------------------------------------------

    public function showThread(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $threadId): ResponseInterface
    {
        $club = $this->clubOrNull($slug);
        if ($club === null) {
            return $this->notFound($response);
        }
        $thread = $this->discussions->thread($threadId);
        if ($thread === null || (int) $thread['club_id'] !== (int) $club['id']) {
            return $this->notFound($response);
        }

        $userId = $this->userId();
        $posts = $this->discussions->posts($threadId);

        // Titles of the sections referenced by the thread and by spoilers.
        $sectionIds = [];
        if (!empty($thread['section_id'])) {
            $sectionIds[] = (int) $thread['section_id'];
        }
        foreach ($posts as $post) {
            if (!empty($post['spoiler_section_id'])) {
                $sectionIds[] = (int) $post['spoiler_section_id'];
            }
        }
        $sectionTitles = $this->discussions->sectionTitles($sectionIds);

        // SpoilerGate: a spoiler post stays collapsed unless the viewer has
        // passed its section (reading module) or wrote it themselves.
        $hiddenPosts = [];
        foreach ($posts as $post) {
            if ($post['spoiler'] === 'none' || $post['deleted_at'] !== null) {
                continue;
            }
            if ($userId !== null && (int) $post['user_id'] === $userId) {
                continue;
            }
            $sectionId = !empty($post['spoiler_section_id']) ? (int) $post['spoiler_section_id'] : null;
            if (!$this->userPassedSection($userId, $sectionId)) {
                $hiddenPosts[(int) $post['id']] = true;
            }
        }

        return $this->renderPublic($response, 'public/thread', [
            'club' => $club,
            'thread' => $thread,
            'posts' => $posts,
            'reactions' => $this->discussions->reactionsForThread($threadId, $userId),
            'mentionNames' => $this->discussions->mentionNamesForThread($threadId),
            'sectionTitles' => $sectionTitles,
            'hiddenPosts' => $hiddenPosts,
            'sections' => $this->discussions->clubSections((int) $club['id']),
            'emojis' => DiscussionRepo::EMOJIS,
            'isMember' => $this->isActiveMember($club),
            'canManage' => $this->canManage($club),
            'userId' => $userId,
        ], (string) $thread['title']);
    }

    /**
     * Reading-module bridge: true only when the optional ReadingRepo exists
     * and confirms the viewer passed the section. Any error = not passed.
     */
    private function userPassedSection(?int $userId, ?int $sectionId): bool
    {
        if ($userId === null || $sectionId === null) {
            return false;
        }
        $class = 'App\\Plugins\\BookClub\\ReadingRepo';
        if (!class_exists($class, false)) {
            return false;
        }
        try {
            // Static API: ReadingRepo::userPassedSection(mysqli $db, int $userId, int $sectionId)
            return (bool) $class::userPassedSection($this->db, $userId, $sectionId);
        } catch (\Throwable $e) {
            SecureLogger::warning('[BookClub:discussions] spoiler gate check failed: ' . $e->getMessage());
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Posts
    // ------------------------------------------------------------------

    public function createPost(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $threadId): ResponseInterface
    {
        $club = $this->clubOrNull($slug);
        if ($club === null) {
            return $this->notFound($response);
        }
        $thread = $this->discussions->thread($threadId);
        if ($thread === null || (int) $thread['club_id'] !== (int) $club['id']) {
            return $this->notFound($response);
        }
        $canManage = $this->canManage($club);
        if (!$this->isActiveMember($club) && !$canManage) {
            $this->flash('error', __('Solo i membri attivi del club possono scrivere messaggi.'));
            return $this->redirect($response, '/book-club/' . $slug . '/discussions/' . $threadId);
        }
        if ((int) $thread['is_locked'] === 1 && !$canManage) {
            $this->flash('warning', __('Questa discussione è bloccata.'));
            return $this->redirect($response, '/book-club/' . $slug . '/discussions/' . $threadId);
        }

        $body = $request->getParsedBody();
        $text = self::str($body, 'body', 20000);
        if ($text === '') {
            $this->flash('error', __('Il messaggio non può essere vuoto.'));
            return $this->redirect($response, '/book-club/' . $slug . '/discussions/' . $threadId);
        }

        $spoiler = self::str($body, 'spoiler', 10);
        if (!in_array($spoiler, ['none', 'mild', 'full'], true)) {
            $spoiler = 'none';
        }
        $spoilerSectionId = null;
        if ($spoiler !== 'none') {
            $spoilerSectionId = self::intOrNull($body, 'spoiler_section_id');
            if ($spoilerSectionId !== null && !$this->discussions->sectionBelongsToClub($spoilerSectionId, (int) $club['id'])) {
                $spoilerSectionId = null;
            }
        }

        // One reply level: the parent must be a top-level post of this thread.
        $parentId = self::intOrNull($body, 'parent_id');
        if ($parentId !== null) {
            $parent = $this->discussions->post($parentId);
            if ($parent === null || (int) $parent['thread_id'] !== $threadId || $parent['parent_id'] !== null) {
                $parentId = null;
            }
        }

        $postId = $this->discussions->createPost($threadId, $parentId, (int) $this->userId(), $text, $spoiler, $spoilerSectionId);
        if ($postId === null) {
            $this->flash('error', __('Messaggio non pubblicato, riprova.'));
            return $this->redirect($response, '/book-club/' . $slug . '/discussions/' . $threadId);
        }
        $this->storeMentions($postId, $text, (int) $club['id']);
        $this->flash('success', __('Messaggio pubblicato.'));
        return $this->redirect($response, '/book-club/' . $slug . '/discussions/' . $threadId . '#post-' . $postId);
    }

    /**
     * Scan the body for @tokens and record a mention for every active
     * member whose first or last name matches (case-insensitively).
     */
    private function storeMentions(int $postId, string $body, int $clubId): void
    {
        if (preg_match_all('/@([\p{L}\p{N}_\'\-]{2,60})/u', $body, $matches) === false || $matches[1] === []) {
            return;
        }
        $tokens = [];
        foreach ($matches[1] as $token) {
            $tokens[mb_strtolower($token)] = true;
        }
        $seen = [];
        foreach ($this->discussions->activeMembers($clubId) as $member) {
            $memberId = (int) $member['id'];
            if (isset($seen[$memberId])) {
                continue;
            }
            $nome = mb_strtolower(trim((string) ($member['nome'] ?? '')));
            $cognome = mb_strtolower(trim((string) ($member['cognome'] ?? '')));
            if (($nome !== '' && isset($tokens[$nome])) || ($cognome !== '' && isset($tokens[$cognome]))) {
                $seen[$memberId] = true;
                $this->discussions->addMention($postId, $memberId);
            }
        }
    }

    // ------------------------------------------------------------------
    // Reactions
    // ------------------------------------------------------------------

    public function react(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $postId): ResponseInterface
    {
        $club = $this->clubOrNull($slug);
        if ($club === null) {
            return $this->notFound($response);
        }
        $post = $this->discussions->post($postId);
        if ($post === null || (int) $post['thread_club_id'] !== (int) $club['id']) {
            return $this->notFound($response);
        }
        $threadId = (int) $post['thread_id'];
        if (!$this->isActiveMember($club) && !$this->canManage($club)) {
            $this->flash('error', __('Solo i membri attivi del club possono reagire ai messaggi.'));
            return $this->redirect($response, '/book-club/' . $slug . '/discussions/' . $threadId);
        }
        if ($post['deleted_at'] !== null) {
            return $this->redirect($response, '/book-club/' . $slug . '/discussions/' . $threadId);
        }
        $body = $request->getParsedBody();
        $emoji = self::str($body, 'emoji', 16);
        if (!in_array($emoji, DiscussionRepo::EMOJIS, true)) {
            $this->flash('error', __('Reazione non valida.'));
            return $this->redirect($response, '/book-club/' . $slug . '/discussions/' . $threadId);
        }
        $this->discussions->toggleReaction($postId, (int) $this->userId(), $emoji);
        return $this->redirect($response, '/book-club/' . $slug . '/discussions/' . $threadId . '#post-' . $postId);
    }

    // ------------------------------------------------------------------
    // Moderation (posts.moderate)
    // ------------------------------------------------------------------

    /**
     * Soft-delete a post.
     *
     * Permission: `posts.moderate` (granular matrix — owner/moderator and
     * Pinakes admin/staff always pass, custom club roles per their JSON).
     */
    public function deletePost(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $postId): ResponseInterface
    {
        $club = $this->clubOrNull($slug);
        if ($club === null || !$this->can($club, 'posts.moderate')) {
            return $this->notFound($response);
        }
        $post = $this->discussions->post($postId);
        if ($post === null || (int) $post['thread_club_id'] !== (int) $club['id']) {
            return $this->notFound($response);
        }
        $this->discussions->softDeletePost($postId);
        $this->flash('success', __('Messaggio rimosso.'));
        return $this->redirect($response, '/book-club/' . $slug . '/discussions/' . (int) $post['thread_id']);
    }

    /**
     * Lock/unlock a thread.
     *
     * Permission: `posts.moderate` (granular matrix — owner/moderator and
     * Pinakes admin/staff always pass, custom club roles per their JSON).
     */
    public function toggleLock(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $threadId): ResponseInterface
    {
        $club = $this->clubOrNull($slug);
        if ($club === null || !$this->can($club, 'posts.moderate')) {
            return $this->notFound($response);
        }
        $thread = $this->discussions->thread($threadId);
        if ($thread === null || (int) $thread['club_id'] !== (int) $club['id']) {
            return $this->notFound($response);
        }
        $this->discussions->toggleLock($threadId);
        $this->flash('success', (int) $thread['is_locked'] === 1 ? __('Discussione sbloccata.') : __('Discussione bloccata.'));
        return $this->redirect($response, '/book-club/' . $slug . '/discussions/' . $threadId);
    }

    /**
     * Pin/unpin a thread.
     *
     * Permission: `posts.moderate` (granular matrix — owner/moderator and
     * Pinakes admin/staff always pass, custom club roles per their JSON).
     */
    public function togglePin(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $threadId): ResponseInterface
    {
        $club = $this->clubOrNull($slug);
        if ($club === null || !$this->can($club, 'posts.moderate')) {
            return $this->notFound($response);
        }
        $thread = $this->discussions->thread($threadId);
        if ($thread === null || (int) $thread['club_id'] !== (int) $club['id']) {
            return $this->notFound($response);
        }
        $this->discussions->togglePin($threadId);
        $this->flash('success', (int) $thread['is_pinned'] === 1 ? __('Discussione non più in evidenza.') : __('Discussione fissata in alto.'));
        return $this->redirect($response, '/book-club/' . $slug . '/discussions/' . $threadId);
    }
}
