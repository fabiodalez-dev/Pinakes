<?php
declare(strict_types=1);

namespace App\Support;

/**
 * The only way to build a link that will be sent by email.
 *
 * A link in an email carries whatever the path carries — a password-setup
 * token, a recovery token — to a reader who will click it without looking. The
 * host in that link therefore cannot come from the request: on a catch-all
 * virtual host, or behind a proxy that forwards unknown names, `Host` is
 * supplied by whoever made the request, and `absoluteUrl()` accepts it whenever
 * APP_TRUSTED_HOSTS is unset. That is how a recovery mail can be made to point
 * at somebody else's domain.
 *
 * The rule was written once, in PasswordController::forgot(), and then not
 * applied in the two other places that mail the same kind of link: the Mobile
 * API's recovery endpoint and the invitation emails. Both used absoluteUrl().
 * Having it in one place is the point of this class — a second copy of a
 * security decision is a second chance to get it wrong.
 *
 * Fails closed: null means "no host the operator vouched for", and the caller
 * must then decline to send rather than send something unverifiable.
 */
final class TrustedLink
{
    /**
     * An absolute URL for $path, or null when no trustworthy host is configured.
     *
     * $path is expected to start with '/' and to already carry its query
     * string, escaped by the caller.
     */
    public static function build(string $path): ?string
    {
        $envUrl = getenv('APP_CANONICAL_URL') ?: ($_ENV['APP_CANONICAL_URL'] ?? '');
        if (is_string($envUrl) && $envUrl !== '') {
            // The canonical URL keeps the operator's scheme and any base path,
            // which a bare host would lose on a sub-directory install.
            return rtrim($envUrl, '/') . $path;
        }

        $trustedHost = HtmlHelper::configuredTrustedHost();
        if ($trustedHost !== null) {
            // The base path belongs here too. A trusted host is only a host, and
            // an installation living in a sub-directory — which this project
            // supports and tests — would otherwise be sent a link one directory
            // too high: a valid token pointing at a 404.
            return 'https://' . $trustedHost . HtmlHelper::getBasePath() . $path;
        }

        return null;
    }
}
