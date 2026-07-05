<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /admin/book-club — global club management (AdminAuthMiddleware-guarded).
 */
class AdminController extends BaseController
{
    private const PRIVACIES = ['public', 'private', 'invite', 'hidden'];

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $clubs = $this->repo->listAllClubs();
        return $this->renderAdmin($response, 'admin/index', ['clubs' => $clubs]);
    }

    public function form(ServerRequestInterface $request, ResponseInterface $response, ?int $id): ResponseInterface
    {
        $club = null;
        if ($id !== null) {
            $club = $this->repo->clubById($id);
            if ($club === null) {
                return $this->notFound($response);
            }
        }
        return $this->renderAdmin($response, 'admin/form', ['club' => $club]);
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response, ?int $id): ResponseInterface
    {
        $body = $request->getParsedBody();
        $name = self::str($body, 'name', 190);
        if ($name === '') {
            $this->flash('error', __('Il nome del club è obbligatorio.'));
            return $this->redirect($response, $id === null ? '/admin/book-club/new' : '/admin/book-club/' . $id . '/edit');
        }
        $privacy = self::str($body, 'privacy', 20);
        if (!in_array($privacy, self::PRIVACIES, true)) {
            $privacy = 'public';
        }
        $color = self::str($body, 'color', 7);
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#4f46e5';
        }
        $data = [
            'name' => $name,
            'description' => self::str($body, 'description', 10000),
            'rules' => self::str($body, 'rules', 10000),
            'color' => $color,
            'privacy' => $privacy,
            'max_members' => self::intOrNull($body, 'max_members'),
            'is_active' => isset($body['is_active']) ? 1 : 0,
            'settings' => [
                'moderate_proposals' => isset($body['moderate_proposals']),
                'max_proposals_per_member' => self::intOrNull($body, 'max_proposals_per_member'),
            ],
        ];

        if ($id === null) {
            $clubId = $this->repo->createClub($data, (int) $this->userId());
            if ($clubId === null) {
                $this->flash('error', __('Creazione del club non riuscita.'));
                return $this->redirect($response, '/admin/book-club/new');
            }
            // The creator becomes the club owner so a staff-created club is
            // immediately manageable from the frontend too.
            $ownerRole = $this->repo->roleIdBySlug('owner');
            if ($ownerRole !== null) {
                $this->repo->upsertMember($clubId, (int) $this->userId(), $ownerRole, 'active');
            }
            if (function_exists('do_action')) {
                do_action('bookclub.club.created', $clubId);
            }
            $this->flash('success', __('Club creato.'));
            return $this->redirect($response, '/admin/book-club/' . $clubId);
        }

        $club = $this->repo->clubById($id);
        if ($club === null) {
            return $this->notFound($response);
        }
        $data['is_active'] = isset($body['is_active']) ? 1 : 0;
        $this->repo->updateClub($id, $data);
        $this->flash('success', __('Club aggiornato.'));
        return $this->redirect($response, '/admin/book-club/' . $id);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $club = $this->repo->clubById($id);
        if ($club === null) {
            return $this->notFound($response);
        }
        $this->repo->softDeleteClub($id);
        $this->flash('success', __('Club eliminato.'));
        return $this->redirect($response, '/admin/book-club');
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $club = $this->repo->clubById($id);
        if ($club === null) {
            return $this->notFound($response);
        }
        return $this->renderAdmin($response, 'admin/show', [
            'club' => $club,
            'states' => $this->repo->workflowStates($club),
            'members' => $this->repo->listMembers($id),
            'roles' => $this->repo->systemRoles(),
            'books' => $this->repo->clubBooks($id),
            'polls' => $this->repo->clubPolls($id),
            'meetings' => $this->repo->clubMeetings($id),
        ]);
    }

    /**
     * Save the workflow editor. The form posts parallel arrays
     * state_key[] / state_label[] / state_color[] plus per-row checkbox
     * arrays keyed by index (flag_current[i], flag_voting[i], …).
     */
    public function saveWorkflow(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $club = $this->repo->clubById($id);
        if ($club === null) {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();
        $keys = is_array($body['state_key'] ?? null) ? $body['state_key'] : [];
        $labels = is_array($body['state_label'] ?? null) ? $body['state_label'] : [];
        $colors = is_array($body['state_color'] ?? null) ? $body['state_color'] : [];

        $states = [];
        $seen = [];
        foreach ($keys as $i => $rawKey) {
            $label = trim((string) ($labels[$i] ?? ''));
            $key = strtolower(trim((string) $rawKey));
            if ($key === '' && $label !== '') {
                $key = slugify_text($label);
            }
            $key = preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';
            if ($key === '' || $label === '' || isset($seen[$key]) || $key === BookClubPlugin::STATE_PENDING) {
                continue;
            }
            $seen[$key] = true;
            $color = trim((string) ($colors[$i] ?? ''));
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $color = '#6b7280';
            }
            $flags = [];
            foreach (['voting', 'current', 'archived'] as $flag) {
                if (!empty($body['flag_' . $flag][$i])) {
                    $flags[$flag] = true;
                }
            }
            $states[] = ['key' => $key, 'label' => mb_substr($label, 0, 100), 'color' => $color, 'flags' => $flags];
        }

        if (count($states) < 2) {
            $this->flash('error', __('Il workflow deve avere almeno due stati.'));
            return $this->redirect($response, '/admin/book-club/' . $id);
        }

        $this->repo->saveWorkflowStates($club, $states);
        $this->flash('success', __('Workflow aggiornato.'));
        return $this->redirect($response, '/admin/book-club/' . $id);
    }

    /**
     * Add a member by email of an existing Pinakes user.
     */
    public function addMember(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $club = $this->repo->clubById($id);
        if ($club === null) {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();
        $email = self::str($body, 'email', 190);
        $roleSlug = self::str($body, 'role', 60);
        if (!in_array($roleSlug, BookClubPlugin::SYSTEM_ROLES, true)) {
            $roleSlug = 'member';
        }
        $user = $email !== '' ? $this->repo->findUserByEmail($email) : null;
        if ($user === null) {
            $this->flash('error', __('Nessun utente registrato con questa email.'));
            return $this->redirect($response, '/admin/book-club/' . $id);
        }
        $roleId = $this->repo->roleIdBySlug($roleSlug);
        if ($roleId === null) {
            $this->flash('error', __('Ruolo non valido.'));
            return $this->redirect($response, '/admin/book-club/' . $id);
        }
        $this->repo->upsertMember($id, (int) $user['id'], $roleId, 'active', $this->userId());
        $this->flash('success', __('Membro aggiunto al club.'));
        return $this->redirect($response, '/admin/book-club/' . $id);
    }

    /**
     * Change a member's role or status (single form, action discriminator).
     */
    public function updateMember(ServerRequestInterface $request, ResponseInterface $response, int $id, int $memberId): ResponseInterface
    {
        $club = $this->repo->clubById($id);
        $member = $this->repo->memberById($memberId);
        if ($club === null || $member === null || (int) $member['club_id'] !== $id) {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();
        $status = self::str($body, 'status', 20);
        $roleSlug = self::str($body, 'role', 60);

        if (in_array($status, ['pending', 'active', 'suspended', 'left', 'banned'], true)) {
            $this->repo->setMemberStatus($memberId, $status);
        }
        if (in_array($roleSlug, BookClubPlugin::SYSTEM_ROLES, true)) {
            $roleId = $this->repo->roleIdBySlug($roleSlug);
            if ($roleId !== null) {
                $this->repo->setMemberRole($memberId, $roleId);
            }
        }
        $this->flash('success', __('Membro aggiornato.'));
        return $this->redirect($response, '/admin/book-club/' . $id);
    }
}
