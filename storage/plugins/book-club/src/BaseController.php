<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Support\NotificationService;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;

/**
 * Shared plumbing for the Book Club controllers: two-pass view rendering
 * (inner view → core admin/frontend layout, same pattern as Archives),
 * flash messages, JSON responses and the club permission checks.
 */
abstract class BaseController
{
    protected mysqli $db;
    protected Repo $repo;

    public function __construct(mysqli $db, Repo $repo)
    {
        $this->db = $db;
        $this->repo = $repo;
    }

    // ------------------------------------------------------------------
    // Session user & permissions
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    protected function sessionUser(): ?array
    {
        $user = $_SESSION['user'] ?? null;
        return is_array($user) ? $user : null;
    }

    protected function userId(): ?int
    {
        $user = $this->sessionUser();
        return isset($user['id']) ? (int) $user['id'] : null;
    }

    /** Pinakes staff/admin bypass every club-level check. */
    protected function isPinakesAdmin(): bool
    {
        $user = $this->sessionUser();
        return in_array($user['tipo_utente'] ?? '', ['admin', 'staff'], true);
    }

    /**
     * Membership row of the current user in $club, or null.
     *
     * @param array<string, mixed> $club
     * @return array<string, mixed>|null
     */
    protected function membership(array $club): ?array
    {
        $userId = $this->userId();
        if ($userId === null) {
            return null;
        }
        return $this->repo->memberRow((int) $club['id'], $userId);
    }

    /** @param array<string, mixed> $club */
    protected function isActiveMember(array $club): bool
    {
        $m = $this->membership($club);
        return $m !== null && $m['status'] === 'active';
    }

    /** Display label for the current user (full name, else email, else fallback). */
    protected function currentUserLabel(): string
    {
        $u = $this->sessionUser();
        $name = trim((string) ($u['nome'] ?? '') . ' ' . (string) ($u['cognome'] ?? ''));
        return $name !== '' ? $name : (string) ($u['email'] ?? __('Un utente'));
    }

    /**
     * Notify the Pinakes admins about a club event that needs attention (a join
     * request, a new proposal, a new meeting). Delegates to the shared
     * NotificationService::notifyAdmins() so the in-app admin bell AND the admin
     * email go through exactly the same pipeline as every other Pinakes admin
     * notification — no hand-rolled recipient/mail plumbing here. Best-effort.
     *
     * @param array<string, mixed> $club
     * @param string $type one of NotificationService's allowed types
     */
    protected function notifyAdminsForClub(array $club, string $type, string $title, string $message, string $slug): void
    {
        try {
            (new NotificationService($this->db))->notifyAdmins(
                $type,
                $title,
                $message,
                absoluteUrl('/book-club/' . $slug),
                (int) $club['id']
            );
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub] admin notification failed: ' . $e->getMessage());
        }
    }

    /**
     * Club managers: Pinakes admin/staff, or active members whose system
     * role is owner/moderator.
     *
     * @param array<string, mixed> $club
     */
    protected function canManage(array $club): bool
    {
        if ($this->isPinakesAdmin()) {
            return true;
        }
        $m = $this->membership($club);
        return $m !== null
            && $m['status'] === 'active'
            && in_array($m['role_slug'] ?? '', ['owner', 'moderator'], true);
    }

    /**
     * Granular permission check backed by the governance matrix
     * (Permissions::can): Pinakes admin/staff always pass, system roles map
     * to their fixed grants (owner/moderator → all), custom club roles use
     * their permissions JSON. Falls back to canManage() if the Permissions
     * class is unavailable (defensive: it ships with the plugin core).
     *
     * @param array<string, mixed> $club
     */
    protected function can(array $club, string $perm): bool
    {
        if (class_exists(Permissions::class)) {
            return Permissions::can($this->db, $club, $this->userId(), $perm);
        }
        return $this->canManage($club);
    }

    /**
     * Whether the current visitor may see the club page at all. Hidden and
     * invite-only clubs are members-only; public/private club pages are
     * browsable by anyone (join is what privacy gates).
     *
     * @param array<string, mixed> $club
     */
    protected function canView(array $club): bool
    {
        if ((int) $club['is_active'] !== 1 && !$this->isPinakesAdmin()) {
            return false;
        }
        if (in_array($club['privacy'], ['public', 'private'], true)) {
            return true;
        }
        if ($this->isPinakesAdmin()) {
            return true;
        }
        $m = $this->membership($club);
        return $m !== null && in_array($m['status'], ['active', 'pending'], true);
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    /**
     * Render an admin view wrapped by app/Views/layout.php.
     *
     * @param array<string, mixed> $data
     */
    protected function renderAdmin(ResponseInterface $response, string $view, array $data): ResponseInterface
    {
        return $this->renderWithLayout($response, $view, $data, __DIR__ . '/../../../../app/Views/layout.php');
    }

    /**
     * Render a public view wrapped by app/Views/frontend/layout.php.
     *
     * @param array<string, mixed> $data
     */
    protected function renderPublic(ResponseInterface $response, string $view, array $data, string $title = ''): ResponseInterface
    {
        $data['title'] = $title !== '' ? $title : __('Book Club');
        $data['seoTitle'] = $data['title'];
        return $this->renderWithLayout($response, $view, $data, __DIR__ . '/../../../../app/Views/frontend/layout.php');
    }

    /** @param array<string, mixed> $data */
    private function renderWithLayout(ResponseInterface $response, string $view, array $data, string $layoutPath): ResponseInterface
    {
        $viewFile = __DIR__ . '/../views/' . $view . '.php';
        if (!is_file($viewFile)) {
            SecureLogger::error('[BookClub] view not found: ' . $viewFile);
            $response->getBody()->write('Book Club view missing: ' . htmlspecialchars($view, ENT_QUOTES, 'UTF-8'));
            return $response->withStatus(500);
        }

        // Make the flash available to the inner view, then consume it.
        $data['flash'] = $_SESSION['bookclub_flash'] ?? null;
        unset($_SESSION['bookclub_flash']);

        extract($data, EXTR_SKIP);
        $title = $data['title'] ?? null;
        $seoTitle = $data['seoTitle'] ?? null;

        ob_start();
        require $viewFile;
        $content = (string) ob_get_clean();

        if (!is_file($layoutPath)) {
            $response->getBody()->write($content);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
        ob_start();
        require $layoutPath;
        $html = (string) ob_get_clean();
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    protected function flash(string $type, string $message): void
    {
        $_SESSION['bookclub_flash'] = ['type' => $type, 'message' => $message];
    }

    protected function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response->withStatus(302)->withHeader('Location', url($path));
    }

    /** @param array<string, mixed> $payload */
    protected function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    protected function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write('<h1>404</h1>');
        return $response->withStatus(404);
    }

    // ------------------------------------------------------------------
    // Input helpers
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed>|object|null $body
     */
    protected static function str(mixed $body, string $key, int $maxLen = 5000): string
    {
        $value = is_array($body) ? ($body[$key] ?? '') : '';
        if (!is_string($value)) {
            return '';
        }
        return mb_substr(trim($value), 0, $maxLen);
    }

    /**
     * @param array<string, mixed>|object|null $body
     */
    protected static function intOrNull(mixed $body, string $key): ?int
    {
        $value = is_array($body) ? ($body[$key] ?? null) : null;
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (int) $value;
    }

    /**
     * Normalise a datetime-local input ("2026-07-05T18:30") to SQL DATETIME,
     * or null when empty/invalid.
     */
    protected static function dateTimeOrNull(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }
}
