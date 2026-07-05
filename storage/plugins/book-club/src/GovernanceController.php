<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Plugins\BookClub\Modules\GovernanceModule;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Governance module (plan §6 / §7.15):
 *  - /admin/book-club/{id}/roles — custom club roles with the granular
 *    permission matrix (AdminAuthMiddleware-guarded);
 *  - POST /book-club/{slug}/automations — per-club automation settings
 *    (club managers only).
 *
 * Every handler re-checks per-club module enablement (routes are global).
 */
class GovernanceController extends BaseController
{
    private GovernanceModule $module;

    public function __construct(\mysqli $db, Repo $repo, GovernanceModule $module)
    {
        parent::__construct($db, $repo);
        $this->module = $module;
    }

    // ------------------------------------------------------------------
    // GET /admin/book-club/{id}/roles
    // ------------------------------------------------------------------

    public function roles(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $club = $this->repo->clubById($id);
        if ($club === null || !$this->module->enabledFor($club)) {
            return $this->notFound($response);
        }
        return $this->renderAdmin($response, 'admin/roles', [
            'club' => $club,
            'roles' => $this->customRoles($id),
            'permKeys' => Permissions::keys(),
            'permLabels' => Permissions::labels(),
        ]);
    }

    // ------------------------------------------------------------------
    // POST /admin/book-club/{id}/roles (action: create | update | delete)
    // ------------------------------------------------------------------

    public function saveRole(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $club = $this->repo->clubById($id);
        if ($club === null || !$this->module->enabledFor($club)) {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();
        $action = self::str($body, 'action', 10);
        $rolesPath = '/admin/book-club/' . $id . '/roles';

        if ($action === 'delete') {
            $role = $this->customRoleById($id, self::intOrNull($body, 'role_id') ?? 0);
            if ($role === null) {
                return $this->notFound($response);
            }
            if ($this->roleInUse((int) $role['id'])) {
                $this->flash('error', __('Il ruolo è in uso e non può essere eliminato.'));
                return $this->redirect($response, $rolesPath);
            }
            $this->execStmt('DELETE FROM bookclub_roles WHERE id = ? AND club_id = ? AND is_system = 0', 'ii', [(int) $role['id'], $id]);
            $this->flash('success', __('Ruolo eliminato.'));
            return $this->redirect($response, $rolesPath);
        }

        $name = self::str($body, 'name', 190);
        if ($name === '') {
            $this->flash('error', __('Il nome del ruolo è obbligatorio.'));
            return $this->redirect($response, $rolesPath);
        }
        $permsJson = json_encode($this->pickedPermissions($body), JSON_UNESCAPED_UNICODE);
        if ($permsJson === false) {
            $permsJson = '{}';
        }

        if ($action === 'update') {
            $role = $this->customRoleById($id, self::intOrNull($body, 'role_id') ?? 0);
            if ($role === null) {
                return $this->notFound($response);
            }
            $this->execStmt(
                'UPDATE bookclub_roles SET name = ?, permissions = ? WHERE id = ? AND club_id = ? AND is_system = 0',
                'ssii',
                [$name, $permsJson, (int) $role['id'], $id]
            );
            $this->flash('success', __('Ruolo aggiornato.'));
            return $this->redirect($response, $rolesPath);
        }

        // create
        $slug = $this->uniqueRoleSlug($id, $name);
        $ok = $this->execStmt(
            'INSERT INTO bookclub_roles (club_id, slug, name, permissions, is_system) VALUES (?, ?, ?, ?, 0)',
            'isss',
            [$id, $slug, $name, $permsJson]
        );
        $this->flash($ok ? 'success' : 'error', $ok ? __('Ruolo creato.') : __('Creazione del ruolo non riuscita.'));
        return $this->redirect($response, $rolesPath);
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/automations (club managers)
    // ------------------------------------------------------------------

    public function saveAutomations(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->module->enabledFor($club) || !$this->canManage($club)) {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();
        $active = is_array($body['active'] ?? null) ? $body['active'] : [];
        $offsets = is_array($body['offset'] ?? null) ? $body['offset'] : [];
        $channels = is_array($body['channel'] ?? null) ? $body['channel'] : [];

        foreach (GovernanceModule::TRIGGERS as $trigger) {
            $isActive = !empty($active[$trigger]) ? 1 : 0;
            $offset = (int) ($offsets[$trigger] ?? 24);
            $offset = max(GovernanceModule::MIN_OFFSET_HOURS, min(GovernanceModule::MAX_OFFSET_HOURS, $offset));
            $channel = (string) ($channels[$trigger] ?? 'email');
            if (!in_array($channel, GovernanceModule::CHANNELS, true)) {
                $channel = 'email';
            }
            $this->module->upsertAutomation((int) $club['id'], $trigger, $channel, $offset, $isActive);
        }

        $this->flash('success', __('Automazioni aggiornate.'));
        return $this->redirect($response, '/book-club/' . $slug);
    }

    // ------------------------------------------------------------------
    // Data helpers (custom roles live in the core bookclub_roles table)
    // ------------------------------------------------------------------

    /**
     * Permission keys picked in the form's perms[] checkboxes, restricted to
     * the fixed matrix, stored as a perm→true map (same shape as the system
     * role seeds).
     *
     * @param array<string, mixed>|object|null $body
     * @return array<string, bool>
     */
    private function pickedPermissions(mixed $body): array
    {
        $picked = is_array($body) && is_array($body['perms'] ?? null) ? $body['perms'] : [];
        $picked = array_map('strval', $picked);
        $out = [];
        foreach (Permissions::keys() as $key) {
            if (in_array($key, $picked, true)) {
                $out[$key] = true;
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> custom roles of the club with usage counts */
    private function customRoles(int $clubId): array
    {
        $stmt = $this->db->prepare(
            'SELECT r.*, (SELECT COUNT(*) FROM bookclub_members m WHERE m.role_id = r.id) AS member_count
               FROM bookclub_roles r
              WHERE r.club_id = ? AND r.is_system = 0
              ORDER BY r.name ASC'
        );
        if ($stmt === false) {
            SecureLogger::error('[BookClub:governance] customRoles prepare failed: ' . $this->db->error);
            return [];
        }
        $stmt->bind_param('i', $clubId);
        $rows = [];
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        }
        $stmt->close();
        return $rows;
    }

    /** @return array<string, mixed>|null the custom role only when it belongs to the club */
    private function customRoleById(int $clubId, int $roleId): ?array
    {
        if ($roleId <= 0) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM bookclub_roles WHERE id = ? AND club_id = ? AND is_system = 0');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $roleId, $clubId);
        $row = null;
        if ($stmt->execute()) {
            $row = $stmt->get_result()?->fetch_assoc() ?: null;
        }
        $stmt->close();
        return $row;
    }

    /** In use = assigned to members or attached to a pending invitation. */
    private function roleInUse(int $roleId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT (SELECT COUNT(*) FROM bookclub_members WHERE role_id = ?)
                  + (SELECT COUNT(*) FROM bookclub_invitations WHERE role_id = ? AND accepted_at IS NULL AND expires_at > NOW()) AS n'
        );
        if ($stmt === false) {
            return true; // fail safe: refuse deletion when unsure
        }
        $stmt->bind_param('ii', $roleId, $roleId);
        $n = 1;
        if ($stmt->execute()) {
            $row = $stmt->get_result()?->fetch_assoc();
            $n = (int) ($row['n'] ?? 1);
        }
        $stmt->close();
        return $n > 0;
    }

    /**
     * Custom role slugs are prefixed with "custom-" so they can NEVER
     * collide with the system slugs (owner/moderator/…) that canManage()
     * and other role_slug checks rely on.
     */
    private function uniqueRoleSlug(int $clubId, string $name): string
    {
        $base = 'custom-' . slugify_text($name);
        if ($base === 'custom-') {
            $base = 'custom-ruolo';
        }
        $base = mb_substr($base, 0, 55);
        $slug = $base;
        $i = 2;
        while ($this->slugTaken($clubId, $slug)) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    private function slugTaken(int $clubId, string $slug): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM bookclub_roles WHERE club_id = ? AND slug = ?');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('is', $clubId, $slug);
        $taken = false;
        if ($stmt->execute()) {
            $taken = $stmt->get_result()?->fetch_assoc() !== null;
        }
        $stmt->close();
        return $taken;
    }

    /** @param array<int, mixed> $params */
    private function execStmt(string $sql, string $types, array $params): bool
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[BookClub:governance] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return false;
        }
        $stmt->bind_param($types, ...$params);
        $ok = $stmt->execute();
        if (!$ok) {
            SecureLogger::error('[BookClub:governance] execute failed: ' . $stmt->error);
        }
        $stmt->close();
        return $ok;
    }
}
