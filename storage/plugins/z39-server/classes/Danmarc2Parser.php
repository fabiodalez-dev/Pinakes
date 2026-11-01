<?php
declare(strict_types=1);

namespace Plugins\Z39Server\Classes;

/**
 * danMARC2 records (MARCXchange `format="danMARC2"`) read into the book data
 * the form reads. The reader lives in core
 * ({@see \App\Support\Marc\Danmarc2Parser}), shared with the MARC bulk
 * importer; this name stays for the plugin's callers.
 */
final class Danmarc2Parser extends \App\Support\Marc\Danmarc2Parser
{
}
