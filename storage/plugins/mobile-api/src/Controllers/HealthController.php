<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Support\ConfigStore;
use App\Support\SecureLogger;
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

            $data = [
                'name'                 => $name,
                'logo'                 => $logo,
                'version'              => $appVersion,
                'api_version'          => self::API_VERSION,
                'features'             => [
                    'catalog'       => true,
                    'loans'         => true,
                    'reservations'  => true,
                    'wishlist'      => true,
                    'messages'      => true,
                    'notifications' => true,
                    'push'          => $vapidPublicKey !== '',
                ],
                'app_access_enabled'   => $appAccessEnabled,
                'registration_enabled' => $registrationEnabled,
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

    private function isHttps(ServerRequestInterface $request): bool
    {
        $forwarded = $request->getHeaderLine('X-Forwarded-Proto');
        if ($forwarded !== '') {
            return strtolower(trim(explode(',', $forwarded)[0])) === 'https';
        }
        if (strtolower($request->getUri()->getScheme()) === 'https') {
            return true;
        }
        $server = $request->getServerParams();
        $https  = strtolower((string) ($server['HTTPS'] ?? ''));

        return $https !== '' && $https !== 'off';
    }
}
