<?php
declare(strict_types=1);

namespace App\Support;

/** Capabilities are independent of reader membership (standard/premium). */
final class Permissions
{
    public const ALL = ['backend.access', 'catalog.manage', 'loans.manage', 'digital.manage', 'digital.access', 'users.manage', 'settings.manage', 'inventory.manage'];

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'backend.access' => __('Accesso al backend'),
            'catalog.manage' => __('Catalogazione'),
            'loans.manage' => __('Gestione prestiti'),
            'digital.manage' => __('Gestione allegati digitali'),
            'digital.access' => __('Apertura allegati riservati'),
            'users.manage' => __('Gestione utenti'),
            'settings.manage' => __('Impostazioni'),
            'inventory.manage' => __('Revisione inventariale'),
        ];
    }

    /** Only known capabilities can be persisted or trusted. @return list<string> */
    public static function normalize(mixed $permissions): array
    {
        if (!is_array($permissions) || count($permissions) > count(self::ALL)) {
            throw new \InvalidArgumentException(__('Permessi non validi.'));
        }
        foreach ($permissions as $permission) {
            if (!is_string($permission) || !in_array($permission, self::ALL, true)) {
                throw new \InvalidArgumentException(__('Permessi non validi.'));
            }
        }
        // Managing attachments includes opening the restricted ones: whoever may set a
        // file's visibility, or attach the same file to another record (the most open
        // record wins), can open it anyway. Stored and shown together so the matrix
        // never displays a separation the server could not enforce.
        if (in_array('digital.manage', $permissions, true)) {
            $permissions[] = 'digital.access';
        }
        return array_values(array_intersect(self::ALL, $permissions));
    }

    /** Hydrate only from DB rows, never from submitted/session permission arrays. */
    public static function hydrate(array $user, \mysqli $db): array
    {
        $user['permissions'] = [];
        $user['role_name'] = '';
        if ((int) ($user['ruolo_id'] ?? 0) > 0) {
            $stmt = $db->prepare('SELECT nome, permessi FROM ruoli WHERE id = ?');
            $id = (int) $user['ruolo_id'];
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $role = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (is_array($role)) {
                try {
                    $user['permissions'] = self::normalize(json_decode($role['permessi'], true, 32, JSON_THROW_ON_ERROR));
                    $user['role_name'] = RolePresets::displayName($role['nome']);
                } catch (\JsonException | \InvalidArgumentException) {
                    // A damaged role grants nothing.
                }
            }
        }
        return $user;
    }

    /** Fresh account + role for public pages, downloads and bearer-token consumers. */
    public static function viewer(int $id, \mysqli $db): ?array
    {
        if ($id <= 0) { return null; }
        try {
            $stmt = $db->prepare("SELECT * FROM utenti WHERE id = ? AND stato = 'attivo' LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!is_array($row)) { return null; }
            // Do not copy credentials into request/session metadata.
            $user = array_intersect_key($row, array_flip(['id', 'tipo_utente', 'stato', 'ruolo_id', 'accesso_digitale']));
            return self::hydrate($user, $db);
        } catch (\Throwable $e) {
            SecureLogger::error('[Permissions] account lookup failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /** $user must be a freshly hydrated identity when making an access decision. */
    public static function can(string $permission, ?array $user = null): bool
    {
        $user ??= $_SESSION['user'] ?? [];
        if (!in_array($permission, self::ALL, true) || ($user['stato'] ?? 'attivo') !== 'attivo') { return false; }
        if (($user['tipo_utente'] ?? '') === 'admin') { return true; }
        // Staff keeps its historical privileges. Custom roles live on reader accounts.
        if (($user['tipo_utente'] ?? '') === 'staff') { return true; }
        if ($permission === 'digital.access' && !empty($user['accesso_digitale'])) { return true; }
        return in_array($permission, $user['permissions'] ?? [], true);
    }

    /** Exact segment matching prevents a similarly named URL acquiring another module's rights. */
    public static function permissionForPath(string $path): ?string
    {
        $path = self::localPath($path);
        if ($path === '/admin' || $path === '/admin/dashboard') { return 'backend.access'; }
        // Circulation views nested under catalogue prefixes name borrowers (open loans,
        // reservation queue), so they follow the loan desk, not the catalogue.
        if (preg_match('#^/api/v1/staff/books/\d+/(queue|availability)$#', $path) === 1
            || $path === '/api/v1/staff/copies/by-code' || $path === '/admin/copies/by-code'
            || $path === '/api/v1/staff/circulation/summary') {
            return 'loans.manage';
        }
        if (preg_match('#^/api/v1/staff/books/\d+/digital$#', $path) === 1) { return 'digital.manage'; }
        // Lookups used by the FRBR work picker in the book editor. Keep these exact:
        // cataloguing does not grant every route under a plugin or API prefix.
        if ($path === '/api/opere/search'
            || preg_match('#^/admin/frbr-lrm/opere/\d+/espressioni$#', $path) === 1) {
            return 'catalog.manage';
        }
        // The reader ledger (payment, manual charge, waiver, receipt) is a loan-desk
        // action that happens to live under the reader record's URL: it follows the
        // loan desk, not the users module, so a users-only role reads the ledger but
        // cannot move money.
        if (preg_match('#^/admin/users/\d+/ledger/#', $path) === 1) { return 'loans.manage'; }
        $groups = [
            'catalog.manage' => ['/admin/books', '/admin/authors', '/admin/publishers', '/admin/suppliers', '/admin/genres', '/admin/series', '/admin/placement', '/admin/copies', '/admin/import', '/admin/imports', '/admin/imports-history', '/api/books', '/api/authors', '/api/publishers', '/api/genres', '/api/series', '/api/copies', '/api/dewey', '/api/scrape', '/api/libri', '/api/autori', '/api/editori', '/api/collane', '/api/collocazione', '/api/cover', '/admin/periodicals', '/api/v1/staff/lookups', '/api/v1/staff/isbn', '/api/v1/staff/copies', '/api/v1/staff/books', '/api/v1/staff/authors', '/api/v1/staff/publishers', '/api/v1/staff/imports', '/api/v1/staff/periodicals', '/api/v1/staff/series', '/api/v1/staff/genres', '/api/v1/staff/works', '/api/v1/staff/covers', '/api/v1/staff/enrich', '/api/v1/staff/shelves', '/api/v1/staff/archives', '/api/search/editori', '/api/search/generi', '/api/search/preview', '/api/generi', '/admin/archives', '/admin/opere', '/admin/espressioni'],
            'loans.manage' => ['/admin/loans', '/admin/attendance', '/admin/reservations', '/api/loans', '/api/reservations', '/api/prestiti', '/prestiti', '/api/v1/staff/loans', '/api/v1/staff/reservations'],
            'users.manage' => ['/admin/users', '/api/users', '/api/utenti', '/api/admin/pending-registrations', '/api/admin/pending-registrations-count', '/api/v1/staff/users', '/api/search/utenti'],
            'digital.manage' => ['/admin/plugins/digital-library/upload'],
            // The closures calendar is a settings page: LibraryCalendarController
            // admits Permissions::mayConfigure(). /admin/calendar/rotate-link is
            // admin-only inside its route, whatever the map says.
            'settings.manage' => ['/admin/settings', '/admin/calendar'],
            'inventory.manage' => ['/admin/inventory'],
        ];
        foreach ($groups as $permission => $prefixes) {
            foreach ($prefixes as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix . '/')) { return $permission; }
            }
        }
        return null; // Unknown/plugin/system administration surfaces require a built-in operator.
    }

    public static function allowsPath(string $path, ?array $user = null, string $method = 'GET'): bool
    {
        $user ??= $_SESSION['user'] ?? [];
        $path = self::localPath($path);
        if (!self::can('backend.access', $user)) { return false; }
        if (in_array($user['tipo_utente'] ?? '', ['admin', 'staff'], true)) { return true; }
        // The loan desk reads borrowers and books (pickers, mobile lookups) without managing them.
        if ($method === 'GET' && self::can('loans.manage', $user)
            && (in_array($path, ['/api/utenti', '/api/libri', '/api/search/utenti', '/api/v1/staff/users', '/api/v1/staff/books'], true)
                || preg_match('#^/api/v1/staff/(users|books)/\d+$#', $path) === 1)) { return true; }
        $permission = self::permissionForPath($path);
        return $permission !== null && self::can($permission, $user);
    }

    private static function localPath(string $path): string
    {
        $base = HtmlHelper::getBasePath();
        if ($base !== '' && str_starts_with($path, $base . '/')) { $path = substr($path, strlen($base)); }
        return '/' . trim($path, '/');
    }

    /** For authorising middleware: revalidate even a session with a forged/stale capability list. */
    public static function authorizePath(string $path, ?\mysqli $db = null, string $method = 'GET'): bool
    {
        $verdict = SessionRoleRevalidator::resolve($db);
        return $verdict['status'] === SessionRoleRevalidator::OK && $verdict['stato'] === 'attivo'
            && self::allowsPath($path, null, $method);
    }

    /** Additive discovery metadata for native clients; it never grants permissions. */
    public static function describe(?array $user): array
    {
        if ($user === null) { return ['permissions' => [], 'role_name' => '', 'backend_access' => false, 'digital_access' => false]; }
        return [
            'permissions' => array_values(array_filter(self::ALL, static fn (string $p): bool => self::can($p, $user))),
            'role_name' => $user['role_name'] ?? '',
            'backend_access' => self::can('backend.access', $user),
            'digital_access' => self::can('digital.access', $user),
        ];
    }

    public const SCOPE_MANAGE = 'manage';
    public const SCOPE_BORROWER = 'borrower';

    /**
     * SQL condition (starting with " AND ") limiting the accounts a list or search shows to
     * the signed-in user. Administrators and Staff see everything. Otherwise it depends on
     * what the lookup is for:
     *   - SCOPE_MANAGE (the users table, its totals, pending registrations): a custom users
     *     manager sees only the readers it may edit (UserRoleAssignment::canEdit), so
     *     operators' email and phone never reach it; any other role sees readers only;
     *   - SCOPE_BORROWER (choosing who borrows: the loan form, the reservations filter, the
     *     app's desk): a role that records loans finds every reader, role and grant holders
     *     included, whatever else it holds; without loans.manage the manage scope applies.
     * No administrator or Staff account reaches a custom role either way. Literal SQL.
     */
    public static function accountScopeSql(string $alias = '', string $scope = self::SCOPE_MANAGE): string
    {
        $user = $_SESSION['user'] ?? [];
        if (in_array($user['tipo_utente'] ?? '', ['admin', 'staff'], true)) {
            return '';
        }
        $p = $alias !== '' ? $alias . '.' : '';
        $readers = " AND {$p}tipo_utente IN ('standard', 'premium')";
        if ($scope === self::SCOPE_BORROWER && self::can('loans.manage', $user)) {
            return $readers;
        }
        if (self::can('users.manage', $user)) {
            return $readers . " AND {$p}ruolo_id IS NULL AND {$p}accesso_digitale = 0";
        }
        return $readers;
    }

    /** Short header label of the backend entry: the built-in role, or the custom role's name. */
    public static function backendLabel(?array $user = null): string
    {
        $user ??= $_SESSION['user'] ?? [];
        return match ($user['tipo_utente'] ?? '') {
            'admin' => __('Admin'),
            'staff' => __('Staff'),
            default => trim((string) ($user['role_name'] ?? '')) !== '' ? (string) $user['role_name'] : __('Il tuo spazio di lavoro'),
        };
    }

    /**
     * Settings a custom role with settings.manage may change beyond what Staff can.
     * The advanced tab (custom JS/CSS, caches), mail transport and templates, and the
     * API keys stay administrator-only: each of them can be turned into a takeover
     * of an administrator account, so SettingsController checks those inline.
     */
    public static function mayConfigure(): bool
    {
        $user = $_SESSION['user'] ?? [];
        return ($user['tipo_utente'] ?? '') === 'admin'
            || (!in_array($user['tipo_utente'] ?? '', ['admin', 'staff'], true)
                && self::can('backend.access', $user) && self::can('settings.manage', $user));
    }
}
