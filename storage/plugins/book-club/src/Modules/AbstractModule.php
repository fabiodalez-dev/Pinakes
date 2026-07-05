<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

use App\Plugins\BookClub\Repo;
use App\Support\SecureLogger;
use mysqli;

/**
 * No-op base implementation plus the shared plumbing every module needs:
 * DDL runner, idempotent ALTER helpers, partial rendering and the per-club
 * enablement check.
 */
abstract class AbstractModule implements ModuleInterface
{
    protected mysqli $db;
    protected Repo $repo;

    public function __construct(mysqli $db, Repo $repo)
    {
        $this->db = $db;
        $this->repo = $repo;
    }

    public function description(): string
    {
        return '';
    }

    public function defaultEnabled(): bool
    {
        return true;
    }

    public function ensureSchema(): array
    {
        return ['created' => [], 'failed' => []];
    }

    public function registerRoutes($app): void
    {
    }

    public function renderClubPanel(array $ctx): string
    {
        return '';
    }

    public function renderClubSidebar(array $ctx): string
    {
        return '';
    }

    public function onMaintenanceTick(): void
    {
    }

    /** Whether this module is enabled for $club (route handlers MUST check). */
    public function enabledFor(array $club): bool
    {
        return Registry::clubEnabled($club, $this);
    }

    // ------------------------------------------------------------------
    // Schema helpers
    // ------------------------------------------------------------------

    /**
     * Run a map of table → CREATE TABLE IF NOT EXISTS statements, logging
     * failures the same way the plugin core does.
     *
     * @param array<string, string> $steps
     * @return array{created: list<string>, failed: list<string>}
     */
    protected function runDdl(array $steps): array
    {
        $created = [];
        $failed = [];
        foreach ($steps as $table => $ddl) {
            try {
                if ($this->db->query($ddl) === false) {
                    $failed[] = $table;
                    SecureLogger::warning('[BookClub:' . $this->slug() . '] CREATE TABLE failed for ' . $table . ': ' . $this->db->error);
                } else {
                    $created[] = $table;
                }
            } catch (\Throwable $e) {
                $failed[] = $table;
                SecureLogger::error('[BookClub:' . $this->slug() . '] Exception during CREATE TABLE ' . $table . ': ' . $e->getMessage());
            }
        }
        return ['created' => $created, 'failed' => $failed];
    }

    protected function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();
        return (int) ($row['n'] ?? 0) > 0;
    }

    protected function columnExists(string $table, string $column): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();
        return (int) ($row['n'] ?? 0) > 0;
    }

    /** Idempotent ALTER TABLE … ADD COLUMN. $definition is trusted plugin code. */
    protected function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if ($this->columnExists($table, $column)) {
            return;
        }
        if ($this->db->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}") === false) {
            SecureLogger::warning('[BookClub:' . $this->slug() . "] ADD COLUMN {$table}.{$column} failed: " . $this->db->error);
        }
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    /**
     * Render a view partial from views/ to a string (module panels).
     *
     * @param array<string, mixed> $data
     */
    protected function renderPartial(string $view, array $data): string
    {
        $viewFile = __DIR__ . '/../../views/' . $view . '.php';
        if (!is_file($viewFile)) {
            SecureLogger::error('[BookClub:' . $this->slug() . '] partial not found: ' . $viewFile);
            return '';
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $viewFile;
        return (string) ob_get_clean();
    }
}
