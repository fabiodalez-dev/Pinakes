<?php
declare(strict_types=1);
namespace App\Support;

/** Keeps requests out of the public holdings catalogue, even after plugin deactivation. */
final class BookVisibility
{
    /**
     * The PUBLIC-catalogue predicate. It belongs on reads that answer to a
     * visitor or a member — the front end, the public and mobile APIs, the
     * interop protocols, sitemaps, feeds, patron dashboards and favourites.
     *
     * It must NOT be used on operator surfaces. An admin has to be able to
     * find, list, count and edit a wanted title; /admin/books, the DataTables
     * totals, the admin dashboard card and the header quick-stat therefore all
     * stay unfiltered, and filtering only some of them makes two numbers on the
     * same screen disagree with nothing in the UI to account for it.
     *
     * Where one method serves both audiences, take a default-permissive boolean
     * and pass true from the public entry point only — see
     * OpereRepository::editionsForOpera() and IcsGenerator::generate().
     *
     * Returns the literal 1=1 when the column is absent, so every caller can
     * concatenate it unconditionally.
     */
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
