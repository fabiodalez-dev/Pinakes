<?php
/**
 * Scraper Registry
 * Manages registration and retrieval of book scrapers
 */
class ScraperRegistry
{
    private array $scrapers = [];

    /**
     * Register a scraper
     */
    public function register(string $name, string $class, int $priority = 0, bool $enabled = true): void
    {
        if (!class_exists($class)) {
            throw new RuntimeException("Scraper class not found: {$class}");
        }

        if (!is_subclass_of($class, 'AbstractScraper')) {
            throw new RuntimeException("Scraper must extend AbstractScraper: {$class}");
        }

        $this->scrapers[$name] = [
            'name' => $name,
            'class' => $class,
            'priority' => $priority,
            'enabled' => $enabled,
            'instance' => null // Lazy instantiation
        ];
    }

    /**
     * Get a specific scraper by name
     */
    public function get(string $name): ?AbstractScraper
    {
        if (!isset($this->scrapers[$name])) {
            return null;
        }

        $scraper = &$this->scrapers[$name];

        // Lazy instantiation
        if ($scraper['instance'] === null && $scraper['enabled']) {
            $scraper['instance'] = new $scraper['class']();
        }

        return $scraper['instance'];
    }

    /**
     * Get all registered scrapers (enabled and disabled)
     */
    public function getAll(): array
    {
        return $this->scrapers;
    }

    /**
     * Get all enabled scrapers ordered by priority (highest first)
     */
    public function getEnabledScrapers(): array
    {
        $enabled = array_filter($this->scrapers, fn($s) => $s['enabled']);

        // Sort by priority (descending)
        uasort($enabled, fn($a, $b) => $b['priority'] <=> $a['priority']);

        $instances = [];
        foreach ($enabled as $name => $scraper) {
            $instance = $this->get($name);
            if ($instance) {
                $instances[] = $instance;
            }
        }

        return $instances;
    }

    /**
     * Enable a scraper
     */
    public function enable(string $name): bool
    {
        if (!isset($this->scrapers[$name])) {
            return false;
        }

        $this->scrapers[$name]['enabled'] = true;
        return true;
    }

    /**
     * Disable a scraper
     */
    public function disable(string $name): bool
    {
        if (!isset($this->scrapers[$name])) {
            return false;
        }

        $this->scrapers[$name]['enabled'] = false;
        $this->scrapers[$name]['instance'] = null; // Clear instance
        return true;
    }

    /**
     * Check if scraper is registered
     */
    public function has(string $name): bool
    {
        return isset($this->scrapers[$name]);
    }

    /**
     * Check if scraper is enabled
     */
    public function isEnabled(string $name): bool
    {
        return isset($this->scrapers[$name]) && $this->scrapers[$name]['enabled'];
    }

    /**
     * Get scraper names
     */
    public function getNames(): array
    {
        return array_keys($this->scrapers);
    }

    /**
     * Get enabled scraper names
     */
    public function getEnabledNames(): array
    {
        return array_keys(array_filter($this->scrapers, fn($s) => $s['enabled']));
    }

    /**
     * Count total scrapers
     */
    public function count(): int
    {
        return count($this->scrapers);
    }

    /**
     * Count enabled scrapers
     */
    public function countEnabled(): int
    {
        return count(array_filter($this->scrapers, fn($s) => $s['enabled']));
    }
}
