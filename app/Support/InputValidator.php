<?php
declare(strict_types=1);

namespace App\Support;

class InputValidator
{
    /**
     * Filter input array to only allowed keys (mass assignment protection)
     */
    public static function filterMassAssignment(array $input, array $allowedKeys): array
    {
        return array_intersect_key($input, array_flip($allowedKeys));
    }

    /**
     * Validate and sanitize input data
     */
    public static function validateInput(array $data, array $rules): array
    {
        $errors = [];
        $sanitized = [];

        foreach ($rules as $field => $rule) {
            if (!isset($data[$field])) {
                if ($rule['required'] ?? false) {
                    $errors[] = "Il campo '$field' è richiesto";
                }
                continue;
            }

            $value = $data[$field];
            $sanitized[$field] = self::sanitizeValue($value, $rule);
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode(', ', $errors));
        }

        return $sanitized;
    }

    private static function sanitizeValue($value, array $rule)
    {
        $type = $rule['type'] ?? 'string';
        
        switch ($type) {
            case 'string':
                return is_string($value) ? trim($value) : (string)$value;
            case 'int':
                return filter_var($value, FILTER_VALIDATE_INT) ?: 0;
            case 'float':
                return filter_var($value, FILTER_VALIDATE_FLOAT) ?: 0.0;
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) ?: '';
            case 'url':
                return filter_var($value, FILTER_VALIDATE_URL) ?: '';
            case 'date':
                return date('Y-m-d', strtotime($value)) ?: null;
            default:
                return is_string($value) ? trim($value) : $value;
        }
    }
}