<?php

declare(strict_types=1);

namespace App\Support;

/** An operator tried to record circulation or a ledger write on their own account. */
final class SelfDealingException extends \RuntimeException
{
}
