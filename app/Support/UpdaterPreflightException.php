<?php
declare(strict_types=1);

namespace App\Support;

/**
 * The updater cannot start because a precondition of the host is missing:
 * storage/tmp or storage/backups not writable, no ZipArchive, no HTTP
 * transport.
 *
 * Its message is written for the operator who has to fix the host, names
 * paths relative to the installation only, and is safe to show to an
 * administrator. Any other exception raised while building the updater is
 * unexpected and is never shown as it is: UpdateController logs it and
 * answers with a generic message.
 */
final class UpdaterPreflightException extends \RuntimeException
{
}
