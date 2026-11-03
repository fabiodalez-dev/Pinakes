<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Support;

use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Plugins\MobileApi\Support\DelegationOutcome;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Support\Features;
use App\Support\Permissions;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared pieces of the library settings areas (Mobile API 1.10.0): the web's
 * access rules, the error envelopes and the value conversions between the
 * API (snake_case English, money in cents) and the admin forms.
 */
final class SettingsKit
{
    private function __construct()
    {
    }

    /** Library policy: administrators (or a custom role with settings.manage), as on the web. */
    public static function mayConfigure(): bool
    {
        return Permissions::mayConfigure();
    }

    /**
     * FeatureGateMiddleware in configuration mode: the route passes when the
     * module is on, or when the caller may configure it (so an administrator
     * can set a module up before switching it on). Null = pass.
     */
    public static function configurationGate(string $feature, ResponseInterface $response): ?ResponseInterface
    {
        if (Features::enabled($feature) || self::mayConfigure()) {
            return null;
        }

        return ResponseEnvelope::error($response, 'feature_disabled', __('Funzione non disponibile su questa istanza.'), 404);
    }

    /**
     * The configuration gate followed by the controller's own mayConfigure()
     * guard (patron types, rules matrix, closures calendar): module off and
     * not an administrator = 404 feature_disabled, module on = 403 not_permitted.
     */
    public static function adminArea(string $feature, ResponseInterface $response): ?ResponseInterface
    {
        if ($gate = self::configurationGate($feature, $response)) {
            return $gate;
        }

        return self::mayConfigure() ? null : self::forbidden($response);
    }

    /** @param array<string, string> $fields */
    public static function forbidden(ResponseInterface $response, array $fields = []): ResponseInterface
    {
        return ResponseEnvelope::error(
            $response,
            'not_permitted',
            __('Operazione riservata agli amministratori'),
            403,
            $fields === [] ? [] : ['fields' => $fields]
        );
    }

    /** @param array<string, string> $fields field => message */
    public static function invalid(ResponseInterface $response, array $fields, ?string $message = null): ResponseInterface
    {
        return ResponseEnvelope::error(
            $response,
            'validation',
            $message ?? (string) (reset($fields) ?: __('Valore non valido.')),
            422,
            ['fields' => (object) $fields]
        );
    }

    public static function notFound(ResponseInterface $response, string $message): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'not_found', $message, 404);
    }

    public static function fail(ResponseInterface $response, string $what, \Throwable $e): ResponseInterface
    {
        SecureLogger::error('[MobileApi] staff ' . $what . ' failed: ' . $e->getMessage());

        return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
    }

    /** The authenticated operator's id (AppAuthMiddleware). */
    public static function userId(ServerRequestInterface $request): int
    {
        $user = $request->getAttribute(AppAuthMiddleware::ATTR_USER);

        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }

    /** The error flash of a delegated web action, or null when it reported none. */
    public static function errorOf(DelegationOutcome $outcome): ?string
    {
        return $outcome->flash('error_message');
    }

    // ─── Value conversions ─────────────────────────────────────────────────

    /** A DECIMAL string ("2.50", "", null) as cents; null when empty or malformed. */
    public static function cents(mixed $decimal): ?int
    {
        if ($decimal === null || !is_scalar($decimal) || trim((string) $decimal) === '') {
            return null;
        }

        return \App\Services\FineParameters::toCents((string) $decimal);
    }

    /** A resolved float amount (PolicyResolver) as cents. */
    public static function floatCents(?float $amount): ?int
    {
        return \App\Services\FineParameters::moneyToCents($amount);
    }

    /** Cents as the decimal string the admin forms accept; '' for null. */
    public static function decimal(?int $cents): string
    {
        return $cents === null ? '' : \App\Services\FineParameters::centsToDecimal($cents);
    }

    /** A stored nullable int column as int|null. */
    public static function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /** A nullable int as the form value ('' = empty, i.e. inherit). */
    public static function formInt(?int $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    // ─── Input checks (JSON types; ranges belong to the callers) ───────────

    /**
     * Checks an optional nullable integer of $json against [min, max].
     * Returns the error message, or null when absent/valid.
     *
     * @param array<string, mixed> $json
     */
    public static function checkInt(array $json, string $key, int $min, int $max, bool $nullable = true): ?string
    {
        if (!array_key_exists($key, $json)) {
            return null;
        }
        $value = $json[$key];
        if ($value === null) {
            return $nullable ? null : __('Valore non valido.');
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            return __('Valore non valido.');
        }

        return null;
    }

    /** @param array<string, mixed> $json */
    public static function checkBool(array $json, string $key): ?string
    {
        return array_key_exists($key, $json) && !is_bool($json[$key]) ? __('Valore non valido.') : null;
    }

    /**
     * An optional nullable amount in cents, 0..FineParameters::MAX_SETTING_CENTS;
     * $label names the field in the web's "maximum" message.
     *
     * @param array<string, mixed> $json
     */
    public static function checkCents(array $json, string $key, string $label): ?string
    {
        if (!array_key_exists($key, $json) || $json[$key] === null) {
            return null;
        }
        $value = $json[$key];
        if (!is_int($value) || $value < 0) {
            return __('Valore non valido.');
        }
        if ($value > \App\Services\FineParameters::MAX_SETTING_CENTS) {
            return sprintf(
                __('Importo non valido per "%1$s": il massimo consentito è %2$s.'),
                $label,
                \App\Services\FineParameters::format(\App\Services\FineParameters::MAX_SETTING_CENTS)
            );
        }

        return null;
    }

    /** @param array<string, mixed> $json */
    public static function checkString(array $json, string $key, int $max, bool $nullable = true): ?string
    {
        if (!array_key_exists($key, $json)) {
            return null;
        }
        $value = $json[$key];
        if ($value === null) {
            return $nullable ? null : __('Valore non valido.');
        }
        if (!is_string($value) || mb_strlen($value) > $max) {
            return __('Valore non valido.');
        }

        return null;
    }
}
