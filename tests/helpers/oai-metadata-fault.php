<?php
declare(strict_types=1);

namespace App\Plugins\OaiPmhServer;

/** Test-only failure injection at the metadata writer's text conversion boundary. */
function strip_tags(string $string, array|string|null $allowed_tags = null): string
{
    if (str_starts_with($string, 'zz-oai419-unrenderable:')) {
        throw new \RuntimeException('Injected metadata writer failure');
    }
    return \strip_tags($string, $allowed_tags);
}
