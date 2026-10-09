<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

/**
 * Scalar reads of request values. A query string or a JSON body can carry an
 * array where a string or a number is expected (`?q[]=x`, `{"book_id":[1]}`):
 * a bare (string)/(int) cast turns it into "Array" with a PHP warning, i.e. a
 * 500. These helpers read a non-scalar as empty, so the handler's own
 * validation answers 4xx as it does for a missing value.
 */
final class Input
{
    public static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    public static function int(mixed $value): int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) || is_float($value) || is_bool($value)
            ? (int) $value
            : 0;
    }
}
