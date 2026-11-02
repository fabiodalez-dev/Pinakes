<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Runs a core web controller action in-process and normalises what it did.
 *
 * The admin controllers answer a form POST with a 302 (`?error=<code>` or a
 * success flag in the query string), a JSON body, or a flash message in the
 * PHP session. Every key the action may write to the session is taken out
 * before the call and put back afterwards (the value a concurrent web
 * session held before is restored), so a delegated API call can never leave a
 * flash message, a form draft or a one-time token behind for the next page a
 * browser renders with the same session.
 */
final class Delegation
{
    /**
     * Session keys any delegated circulation / content action may write.
     * A forgotten key here is a flash message on someone's next web page.
     */
    public const SESSION_KEYS = [
        'success_message',
        'error_message',
        'error',
        'success',
        'loan_form_old',
        'csv_import_data',
        'librarything_import',
        'import_errors',
        'import_progress',
        '_one_time_form_tokens',
        // Pinakes 0.9.0 (Mobile API 1.10.0 staff areas)
        'marc_import_data',
        'marc_import_done',
        'user_import',
        'copy_cataloging_results',
        'copy_cataloging_last_import',
        'supplier_form_old',
        'custom_holiday_draft',
        'attendance_sede_id',
    ];

    private function __construct()
    {
    }

    /**
     * @param callable(): ResponseInterface $action
     * @param list<string> $keys session keys to isolate (defaults to SESSION_KEYS)
     */
    public static function run(callable $action, array $keys = self::SESSION_KEYS): DelegationOutcome
    {
        $prior = self::take($keys);
        $captured = [];
        try {
            $result = $action();
            if (isset($_SESSION)) {
                foreach ($keys as $key) {
                    if (array_key_exists($key, $_SESSION)) {
                        $captured[$key] = $_SESSION[$key];
                    }
                }
            }
        } finally {
            self::restore($keys, $prior);
        }

        return DelegationOutcome::fromResponse($result, $captured);
    }

    /**
     * A fresh mock request for a core action: a parsed form body (what the web
     * form posts), optional query parameters, and an optional raw body for the
     * actions that read `php://input` style JSON (e.g. CSV import chunks).
     *
     * @param array<string, mixed> $parsedBody
     * @param array<string, mixed> $query
     */
    public static function request(
        string $method,
        string $path,
        array $parsedBody = [],
        array $query = [],
        ?string $rawJson = null
    ): ServerRequestInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($query !== []) {
            $request = $request->withQueryParams($query);
        }
        if ($rawJson !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream($rawJson));
            if ($parsedBody !== []) {
                $request = $request->withParsedBody($parsedBody);
            }

            return $request;
        }
        if ($method !== 'GET') {
            $request = $request
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withParsedBody($parsedBody);
        }

        return $request;
    }

    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function take(array $keys): array
    {
        $prior = [];
        if (!isset($_SESSION)) {
            return $prior;
        }
        foreach ($keys as $key) {
            if (array_key_exists($key, $_SESSION)) {
                $prior[$key] = $_SESSION[$key];
                unset($_SESSION[$key]);
            }
        }

        return $prior;
    }

    /**
     * @param list<string> $keys
     * @param array<string, mixed> $prior
     */
    private static function restore(array $keys, array $prior): void
    {
        if (!isset($_SESSION)) {
            return;
        }
        foreach ($keys as $key) {
            unset($_SESSION[$key]);
            if (array_key_exists($key, $prior)) {
                $_SESSION[$key] = $prior[$key];
            }
        }
    }
}

/**
 * What a delegated core action answered.
 */
final class DelegationOutcome
{
    /**
     * @param array<string, string> $query  query parameters of the Location header
     * @param array<string, mixed>  $json   decoded JSON body ([] when not JSON)
     * @param array<string, mixed>  $session values the action left in the isolated session keys
     */
    public function __construct(
        public readonly int $status,
        public readonly string $location,
        public readonly array $query,
        public readonly array $json,
        public readonly string $body,
        public readonly array $session,
        public readonly ResponseInterface $response
    ) {
    }

    /**
     * @param array<string, mixed> $session
     */
    public static function fromResponse(ResponseInterface $response, array $session): self
    {
        $location = $response->getHeaderLine('Location');
        $query = [];
        $qs = parse_url($location, PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $parsed);
            foreach ($parsed as $k => $v) {
                if (is_scalar($v)) {
                    $query[(string) $k] = (string) $v;
                }
            }
        }
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        return new self(
            $response->getStatusCode(),
            $location,
            $query,
            is_array($decoded) ? $decoded : [],
            $body,
            $session,
            $response
        );
    }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    /** The `?error=` code of a redirect, if any. */
    public function errorCode(): ?string
    {
        $code = $this->query['error'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    public function queryValue(string $key): ?string
    {
        $value = $this->query[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** A non-empty string the action stored under a session key (flash text). */
    public function flash(string $key): ?string
    {
        $value = $this->session[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** The integer captured by the first group of $pattern in the Location path. */
    public function locationId(string $pattern): ?int
    {
        $path = (string) (parse_url($this->location, PHP_URL_PATH) ?? '');
        if (preg_match($pattern, $path, $m) === 1 && isset($m[1])) {
            return (int) $m[1];
        }

        return null;
    }
}
