<?php
declare(strict_types=1);

/**
 * A connection-less mysqli for authorization tests (security audit 2026-11).
 *
 * The handlers under test read their target row and then decide. This double
 * answers the first $budget calls with the rows it was given, records every
 * statement, and throws SecurityAuditPastGuard on the first call beyond the
 * budget — the earliest observable sign that a handler went on past its check.
 * A refused request therefore leaves count($db->calls) <= $budget.
 */

final class SecurityAuditPastGuard extends RuntimeException
{
}

final class SecurityAuditFakeResult extends mysqli_result
{
    public function __construct(private ?array $row)
    {
    }

    public function fetch_assoc(): array|null|false
    {
        $row = $this->row;
        $this->row = null;
        return $row;
    }

    public function free(): void
    {
    }
}

final class SecurityAuditFakeStmt extends mysqli_stmt
{
    public function __construct(private ?array $row)
    {
    }

    public function bind_param(string $types, mixed &...$vars): bool
    {
        return true;
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function get_result(): mysqli_result|false
    {
        return new SecurityAuditFakeResult($this->row);
    }

    public function close(): true
    {
        return true;
    }
}

final class SecurityAuditFakeDb extends mysqli
{
    /** @var list<string> */
    public array $calls = [];

    /** @param list<?array> $rows one row per statement allowed within the budget */
    public function __construct(private array $rows = [], private int $budget = 0)
    {
    }

    public function prepare(string $query): mysqli_stmt|false
    {
        $this->record($query);
        return new SecurityAuditFakeStmt(array_shift($this->rows));
    }

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        $this->record($query);
        return true;
    }

    private function record(string $query): void
    {
        $this->calls[] = $query;
        if (count($this->calls) > $this->budget) {
            throw new SecurityAuditPastGuard($query);
        }
    }
}
