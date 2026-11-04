<?php
declare(strict_types=1);

namespace Plugins\Z39Server\Classes;

/**
 * The part a name played in a book, read from a catalogue record: the logic
 * lives in core ({@see \App\Support\Marc\RelatorRoles}), shared with the
 * MARC bulk importer; this name stays for the plugin's callers.
 */
final class RelatorRoles extends \App\Support\Marc\RelatorRoles
{
}
