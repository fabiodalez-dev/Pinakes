<?php

declare(strict_types=1);

namespace Z39Server\Exceptions;

class SRUQueryException extends \Exception
{
    public function __construct(
        public readonly int $diagnosticCode,
        string $message,
        int $httpStatus = 400,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }
}
