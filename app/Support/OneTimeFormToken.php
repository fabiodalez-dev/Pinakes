<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Session-backed one-time tokens for non-idempotent HTML form submissions.
 *
 * CSRF proves who submitted a form, but intentionally remains reusable for the
 * whole session. These scoped tokens additionally make one POST consumable
 * exactly once while retaining a small set so multiple browser tabs work.
 */
final class OneTimeFormToken
{
    private const SESSION_KEY = '_one_time_form_tokens';
    private const MAX_TOKENS_PER_SCOPE = 12;
    private const TTL_SECONDS = 7200;

    public static function issue(string $scope): string
    {
        self::assertScope($scope);
        $tokens = self::scopeTokens($scope);
        $now = time();
        $tokens = self::prune($tokens, $now);

        $token = bin2hex(random_bytes(32));
        $tokens[$token] = $now;
        arsort($tokens, SORT_NUMERIC);
        $tokens = array_slice($tokens, 0, self::MAX_TOKENS_PER_SCOPE, true);
        self::storeScopeTokens($scope, $tokens);

        return $token;
    }

    public static function consume(string $scope, mixed $submittedToken): bool
    {
        self::assertScope($scope);
        if (!is_string($submittedToken) || !preg_match('/^[a-f0-9]{64}$/D', $submittedToken)) {
            return false;
        }

        $tokens = self::prune(self::scopeTokens($scope), time());
        $matched = null;
        foreach (array_keys($tokens) as $token) {
            if (hash_equals($token, $submittedToken)) {
                $matched = $token;
                break;
            }
        }

        if ($matched === null) {
            self::storeScopeTokens($scope, $tokens);
            return false;
        }

        unset($tokens[$matched]);
        self::storeScopeTokens($scope, $tokens);
        return true;
    }

    /** @return array<string, int> */
    private static function scopeTokens(string $scope): array
    {
        $all = $_SESSION[self::SESSION_KEY] ?? [];
        if (!is_array($all) || !isset($all[$scope]) || !is_array($all[$scope])) {
            return [];
        }

        $tokens = [];
        foreach ($all[$scope] as $token => $issuedAt) {
            if (is_string($token) && is_int($issuedAt)) {
                $tokens[$token] = $issuedAt;
            }
        }
        return $tokens;
    }

    /** @param array<string, int> $tokens */
    private static function storeScopeTokens(string $scope, array $tokens): void
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }
        if ($tokens === []) {
            unset($_SESSION[self::SESSION_KEY][$scope]);
            return;
        }
        $_SESSION[self::SESSION_KEY][$scope] = $tokens;
    }

    /**
     * @param array<string, int> $tokens
     * @return array<string, int>
     */
    private static function prune(array $tokens, int $now): array
    {
        $cutoff = $now - self::TTL_SECONDS;
        return array_filter(
            $tokens,
            static fn (int $issuedAt): bool => $issuedAt >= $cutoff && $issuedAt <= $now + 60
        );
    }

    private static function assertScope(string $scope): void
    {
        if ($scope === '' || strlen($scope) > 80 || !preg_match('/^[a-z0-9._-]+$/D', $scope)) {
            throw new \InvalidArgumentException('Invalid one-time form token scope.');
        }
    }
}
