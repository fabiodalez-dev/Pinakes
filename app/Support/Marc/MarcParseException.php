<?php
declare(strict_types=1);

namespace App\Support\Marc;

/** A single record that cannot be read; the batch it belongs to goes on. */
final class MarcParseException extends \RuntimeException
{
}
