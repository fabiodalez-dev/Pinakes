<?php
declare(strict_types=1);
namespace App\Support;

/** Keeps requests out of the public holdings catalogue, even after plugin deactivation. */
final class BookVisibility
{
    public static function catalogue(\mysqli $db, string $alias = 'libri'): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias)) {
            throw new \InvalidArgumentException('Invalid SQL alias');
        }
        return self::hasDesiderata($db) ? "$alias.is_desiderata = 0" : '1=1';
    }

    public static function hasDesiderata(\mysqli $db): bool
    {
        static $columns;
        $columns ??= new \WeakMap();
        if (!isset($columns[$db])) {
            $result = $db->query("SHOW COLUMNS FROM libri LIKE 'is_desiderata'");
            $columns[$db] = $result !== false && $result->num_rows > 0;
        }
        return $columns[$db];
    }
}
