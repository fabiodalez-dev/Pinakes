<?php
declare(strict_types=1);

namespace App\Support;

class AuthorizationHelper
{
    /**
     * Check if user can access a resource based on their role
     */
    public static function canAccessResource(?array $user, string $resourceType, int $resourceId): bool
    {
        if ($user === null) {
            return false;
        }

        // Admin and staff can access everything
        if (in_array($user['tipo_utente'] ?? '', ['admin', 'staff'])) {
            return true;
        }

        // Standard users can only access their own data or public data
        switch ($resourceType) {
            case 'user':
                return (int)$user['id'] === $resourceId;
            case 'book':
            case 'author':
            case 'publisher':
                // These are public resources for read access
                return true;
            default:
                return false;
        }
    }

    /**
     * Validate that user owns the resource or has admin access
     */
    public static function validateOwnership(?array $user, string $table, int $resourceId, \mysqli $db): bool
    {
        if ($user === null) {
            return false;
        }

        // Admin and staff can access everything
        if (in_array($user['tipo_utente'] ?? '', ['admin', 'staff'])) {
            return true;
        }

        $stmt = $db->prepare("SELECT user_id FROM $table WHERE id = ?");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $resourceId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            return (int)$row['user_id'] === (int)$user['id'];
        }

        return false;
    }
}