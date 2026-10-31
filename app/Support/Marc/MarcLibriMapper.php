<?php
declare(strict_types=1);

namespace App\Support\Marc;

/**
 * A MARC flavour read into Pinakes book data. Every implementation returns
 * the shape of {@see MarcBookData::empty()}: every key present, strings
 * trimmed, lists of names in record order.
 */
interface MarcLibriMapper
{
    /** @return array<string, string|list<string>> */
    public function map(MarcRecord $record): array;
}
