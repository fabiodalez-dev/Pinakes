<?php
/**
 * Dewey Classification Validator
 *
 * Validates Dewey JSON structure, code uniqueness, and format.
 */

declare(strict_types=1);

class DeweyValidator
{
    private array $errors = [];
    private array $seenCodes = [];

    // Standard Dewey codes (000-999) that must exist and cannot be deleted
    private const REQUIRED_MAIN_CLASSES = ['000', '100', '200', '300', '400', '500', '600', '700', '800', '900'];

    // Regex patterns
    private const PATTERN_INTEGER_CODE = '/^[0-9]{3}$/';
    private const PATTERN_DECIMAL_CODE = '/^[0-9]{3}\.[0-9]{1,4}$/';
    private const PATTERN_ANY_CODE = '/^[0-9]{3}(\.[0-9]{1,4})?$/';

    /**
     * Validate Dewey JSON data structure
     *
     * @param array $data The Dewey data array
     * @return array List of validation errors (empty if valid)
     */
    public function validate(array $data): array
    {
        $this->errors = [];
        $this->seenCodes = [];

        // Must be an array of main classes
        if (!is_array($data) || empty($data)) {
            $this->errors[] = __('I dati devono essere un array non vuoto.');
            return $this->errors;
        }

        // Validate structure recursively
        $this->validateNodes($data, null);

        // Check all main classes exist
        $this->checkRequiredClasses($data);

        return $this->errors;
    }

    /**
     * Validate a single node
     *
     * @param array $node The node to validate
     * @param string|null $parentCode Parent code for hierarchy validation
     * @param int $depth Current depth for level validation
     */
    private function validateNode(array $node, ?string $parentCode, int $depth = 1): void
    {
        // Required fields
        if (!isset($node['code']) || !is_string($node['code'])) {
            $this->errors[] = sprintf(__('Nodo mancante di codice a profondità %d.'), $depth);
            return;
        }

        $code = $node['code'];

        if (!isset($node['name']) || !is_string($node['name']) || strlen(trim($node['name'])) < 2) {
            $this->errors[] = sprintf(__('Il codice %s ha un nome non valido (minimo 2 caratteri).'), $code);
        }

        if (!isset($node['level']) || !is_int($node['level']) || $node['level'] < 1 || $node['level'] > 7) {
            $this->errors[] = sprintf(__('Il codice %s ha un livello non valido (deve essere 1-7).'), $code);
        }

        // Code format validation
        if (!preg_match(self::PATTERN_ANY_CODE, $code)) {
            $this->errors[] = sprintf(__('Il codice %s ha un formato non valido.'), $code);
        }

        // Uniqueness check
        if (isset($this->seenCodes[$code])) {
            $this->errors[] = sprintf(__('Il codice %s è duplicato.'), $code);
        } else {
            $this->seenCodes[$code] = true;
        }

        // Hierarchy validation (child must start with parent code)
        if ($parentCode !== null) {
            if (!$this->isValidChild($parentCode, $code)) {
                $this->errors[] = sprintf(__('Il codice %s non è un figlio valido di %s.'), $code, $parentCode);
            }
        }

        // Validate children
        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                if (is_array($child)) {
                    $this->validateNode($child, $code, $depth + 1);
                }
            }
        }
    }

    /**
     * Validate all nodes in array
     */
    private function validateNodes(array $nodes, ?string $parentCode): void
    {
        foreach ($nodes as $node) {
            if (is_array($node)) {
                $this->validateNode($node, $parentCode);
            }
        }
    }

    /**
     * Check if child code is valid for given parent
     */
    private function isValidChild(string $parentCode, string $childCode): bool
    {
        // Integer parent (e.g., 599) -> child can be 599.X
        if (preg_match(self::PATTERN_INTEGER_CODE, $parentCode)) {
            // Child must start with parent or be a decimal of parent
            if (strpos($childCode, $parentCode) === 0) {
                return true;
            }
            // Also allow same prefix for divisions (e.g., 500 -> 510, 520, etc.)
            $parentPrefix = substr($parentCode, 0, 1);
            $childPrefix = substr($childCode, 0, 1);
            if ($parentPrefix === $childPrefix && strlen($childCode) === 3) {
                return true;
            }
        }

        // Decimal parent (e.g., 599.9) -> child can be 599.9X (e.g., 599.91)
        if (preg_match(self::PATTERN_DECIMAL_CODE, $parentCode)) {
            return strpos($childCode, $parentCode) === 0 && strlen($childCode) > strlen($parentCode);
        }

        return false;
    }

    /**
     * Check that all required main classes exist
     */
    private function checkRequiredClasses(array $data): void
    {
        $mainClasses = [];
        foreach ($data as $node) {
            if (isset($node['code']) && isset($node['level']) && $node['level'] === 1) {
                $mainClasses[] = $node['code'];
            }
        }

        foreach (self::REQUIRED_MAIN_CLASSES as $required) {
            if (!in_array($required, $mainClasses)) {
                $this->errors[] = sprintf(__('Classe principale mancante: %s.'), $required);
            }
        }
    }

    /**
     * Validate a single code format
     *
     * @param string $code The code to validate
     * @return bool True if valid
     */
    public function isValidCode(string $code): bool
    {
        return (bool) preg_match(self::PATTERN_ANY_CODE, $code);
    }

    /**
     * Check if a code is a standard integer code (000-999)
     *
     * @param string $code The code to check
     * @return bool True if integer code
     */
    public function isIntegerCode(string $code): bool
    {
        return (bool) preg_match(self::PATTERN_INTEGER_CODE, $code);
    }

    /**
     * Check if a code is a decimal code (e.g., 599.9)
     *
     * @param string $code The code to check
     * @return bool True if decimal code
     */
    public function isDecimalCode(string $code): bool
    {
        return (bool) preg_match(self::PATTERN_DECIMAL_CODE, $code);
    }

    /**
     * Calculate the parent code for a given code
     *
     * @param string $code The code
     * @return string|null The parent code or null if main class
     */
    public function getParentCode(string $code): ?string
    {
        // Main class (X00) has no parent at level 1
        if (preg_match('/^[0-9]00$/', $code)) {
            return null;
        }

        // Division (X10-X90) -> parent is X00
        if (preg_match('/^([0-9])[0-9]0$/', $code, $matches)) {
            return $matches[1] . '00';
        }

        // Section (X01-X99) -> parent is X10/X20/.../X90 or X00
        if (preg_match(self::PATTERN_INTEGER_CODE, $code)) {
            $prefix = substr($code, 0, 2);
            return $prefix . '0';
        }

        // Decimal code -> remove last digit after decimal
        if (preg_match(self::PATTERN_DECIMAL_CODE, $code)) {
            // 599.93 -> 599.9
            // 599.9 -> 599
            $parts = explode('.', $code);
            $decimal = $parts[1];

            if (strlen($decimal) > 1) {
                return $parts[0] . '.' . substr($decimal, 0, -1);
            } else {
                return $parts[0];
            }
        }

        return null;
    }

    /**
     * Check if a code can be deleted
     * Only decimal codes (level 4+) can be deleted
     *
     * @param string $code The code to check
     * @return bool True if deletable
     */
    public function canDelete(string $code): bool
    {
        // Only decimal codes can be deleted
        return $this->isDecimalCode($code);
    }
}
