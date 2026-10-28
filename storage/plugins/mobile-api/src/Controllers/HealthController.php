<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Plugins\MobileApi\Support\ProxyTrust;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Support\ConfigStore;
use App\Support\RegistrationFields;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public discovery endpoint: GET /api/v1/health.
 *
 * No token required. The native app calls this first (after the user types the
 * instance URL) to render the library identity and to decide whether app access
 * + registration are available and whether the connection is secure.
 *
 * Payload (per spec §Endpoint manifest):
 *   { name, logo, version, api_version, features{...},
 *     app_access_enabled, registration_enabled, private_mode }
 */
final class HealthController
{
    /** Mobile API contract version advertised to clients for forward-compat. */
    public const API_VERSION = 'v1';

    public function __construct(private mysqli $db)
    {
    }

    public function index(
        ServerRequestInterface $request,
        ResponseInterface $response,
        bool $appAccessEnabled = false,
        string $vapidPublicKey = ''
    ): ResponseInterface {
        try {
            $name = (string) ConfigStore::get('app.name', 'Pinakes');

            $logoRaw = (string) ConfigStore::get('app.logo', '');
            $logo    = $logoRaw !== '' ? absoluteUrl($logoRaw) : null;

            $appVersion = $this->appVersion();

            // App access is the dedicated, separate gate (mobile_api.enabled),
            // resolved by the caller via SettingsRepository — ConfigStore does not
            // surface plugin-defined categories, so reading it here would always
            // report the default '0'.

            $privateMode = (string) ConfigStore::get('advanced.private_mode', '0') === '1';

            // Registration discovery flag. There is no single master
            // "registration on/off" switch in core today; we expose a dedicated
            // setting and fall back to "open unless private mode" so the app can
            // hide its sign-up CTA when appropriate. Refined in the auth slice.
            $registrationSetting = ConfigStore::get('registration.enabled', null);
            $registrationEnabled = $registrationSetting !== null
                ? ((string) $registrationSetting === '1')
                : !$privateMode;

            $isHttps = $this->isHttps($request);

            // Catalogue-only mode (system.catalogue_mode): when on, the instance
            // hides loans, reservations and wishlist everywhere. The app must do
            // the same, so we gate those feature flags off and expose the mode.
            $catalogueMode = (bool) ConfigStore::isCatalogueMode();
            $loanApprovalRequired = !(new \App\Models\SettingsRepository($this->db))->autoApproveLoanRequests();

            $data = [
                'name'                 => $name,
                'logo'                 => $logo,
                'version'              => $appVersion,
                'api_version'          => self::API_VERSION,
                'features'             => [
                    'catalog'       => true,
                    'loans'         => !$catalogueMode,
                    'reservations'  => !$catalogueMode,
                    'wishlist'      => !$catalogueMode,
                    'messages'      => true,
                    'notifications' => true,
                    'push'          => $vapidPublicKey !== '',
                    // Book reviews ride the loan history (only borrowers can
                    // review), so they are meaningless in catalogue mode.
                    'reviews'       => !$catalogueMode,
                    'archives'      => $this->pluginActive('archives'),
                    'desiderata'    => $this->pluginActive('desiderata'),
                    // Mobile API 1.6.0. Older servers omit these keys, so the
                    // app hides the matching entries. staff_catalog only says
                    // the routes exist: each call still checks the role.
                    'staff_catalog' => true,
                    'catalog_sync'  => true,
                    'calendar_feed' => !$catalogueMode,
                    // Mobile API 1.9.0: the optional circulation modules of
                    // Settings -> Loans (already false in catalogue mode).
                    // Older servers omit these keys: the app treats them as off.
                    'fines'             => \App\Support\Features::enabled('fines'),
                    'closures_calendar' => \App\Support\Features::enabled('closures_calendar'),
                    'circulation_rules' => \App\Support\Features::enabled('circulation_rules'),
                    // Mobile API 1.7.0 staff areas: true only when the area's
                    // routes are registered (StaffAreas::IMPLEMENTED) AND its
                    // prerequisite holds. Older servers omit these keys.
                ] + \App\Plugins\MobileApi\Support\StaffAreas::flags([
                    'circulation'     => !$catalogueMode,
                    'series'          => $this->hasTable('collane'),
                    'desiderata'      => $this->pluginActive('desiderata'),
                    'archives'        => $this->pluginActive('archives'),
                    'periodicals'     => $this->pluginActive('emeroteca') && is_file(dirname(__DIR__, 3) . '/emeroteca/src/Modules/MobileStaffModule.php'),
                    'bookclub'        => $this->pluginActive('book-club') && is_file(dirname(__DIR__, 3) . '/book-club/src/Modules/MobileStaffBridge.php'),
                    'frbr'            => $this->pluginActive('frbr-lrm'),
                    'digital_library' => $this->pluginActive('digital-library'),
                    // The LibraryThing TSV import/export is core (no plugin to probe).
                ]),
                'catalogue_mode'       => $catalogueMode,
                'loan_approval_required' => $loanApprovalRequired,
                'app_access_enabled'   => $appAccessEnabled,
                'registration_enabled' => $registrationEnabled,
                // Lightweight registration summary (a convenience mirror). The
                // CANONICAL signup form-render contract is GET
                // /auth/registration-fields — both derive from the same
                // App\Support\RegistrationFields source so their values cannot
                // drift; a client rendering the signup form should read the
                // dedicated endpoint (richer per-field shape + core fields).
                'registration'         => [
                    'require_cognome'   => RegistrationFields::isRequired('cognome'),
                    'require_telefono'  => RegistrationFields::isRequired('telefono'),
                    'require_indirizzo' => RegistrationFields::isRequired('indirizzo'),
                    'show_sesso'        => RegistrationFields::isVisible('sesso'),
                    'show_cod_fiscale'  => RegistrationFields::isVisible('cod_fiscale'),
                    'custom_fields'     => RegistrationFields::apiDefinitions($this->db),
                ],
                'private_mode'         => $privateMode,
                // VAPID public key (applicationServerKey) for Web Push / UnifiedPush
                // subscription on the device. Empty if push isn't set up.
                'vapid_public_key'     => $vapidPublicKey !== '' ? $vapidPublicKey : null,
            ];

            $meta = [
                'https'   => $isHttps,
                'warning' => $isHttps ? null : 'insecure_transport',
            ];

            return ResponseEnvelope::success($response, $data, $meta, 200);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] health endpoint failed: ' . $e->getMessage());

            return ResponseEnvelope::error(
                $response,
                'internal_error',
                __('Impossibile recuperare lo stato del servizio.'),
                500
            );
        }
    }

    private function appVersion(): string
    {
        $file = dirname(__DIR__, 5) . '/version.json';
        if (is_file($file)) {
            $raw = file_get_contents($file);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['version']) && is_string($decoded['version'])) {
                    return $decoded['version'];
                }
            }
        }

        return 'unknown';
    }

    private function pluginActive(string $name): bool
    {
        $stmt = $this->db->prepare('SELECT is_active FROM plugins WHERE name = ? LIMIT 1');
        if ($stmt === false) { return false; }
        $stmt->bind_param('s', $name); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        return (int) ($row['is_active'] ?? 0) === 1;
    }

    private function hasTable(string $table): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $result = $stmt->get_result();
        $found = $result !== false && $result->fetch_row() !== null;
        $stmt->close();

        return $found;
    }

    private function isHttps(ServerRequestInterface $request): bool
    {
        // Only honour X-Forwarded-Proto when the request actually comes from a
        // configured trusted proxy (TRUSTED_PROXIES); otherwise a client could
        // spoof the header and have us advertise https=true over cleartext.
        if (ProxyTrust::isTrustedProxy($request)) {
            $forwarded = $request->getHeaderLine('X-Forwarded-Proto');
            if ($forwarded !== '') {
                return strtolower(trim(explode(',', $forwarded)[0])) === 'https';
            }
        }
        if (strtolower($request->getUri()->getScheme()) === 'https') {
            return true;
        }
        $server = $request->getServerParams();
        $https  = strtolower((string) ($server['HTTPS'] ?? ''));

        return $https !== '' && $https !== 'off';
    }
}
