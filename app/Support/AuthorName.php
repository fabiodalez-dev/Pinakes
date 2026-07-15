<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Single source of truth for how an author's name is shown *on a book*.
 *
 * When an author has a pseudonym, readers expect the pseudonym — but the real
 * name still disambiguates it — so the display form is "Pseudonimo (Nome vero)"
 * (issue #237). When there is no pseudonym, it is just the real name.
 *
 * Two entry points keep PHP-side rendering (Choices.js chips, initial values)
 * and SQL-side rendering (GROUP_CONCAT on list/detail queries) in lockstep.
 */
final class AuthorName
{
    /**
     * SQL display expression for the conventional `a` table alias.
     *
     * This is a class constant so SQL held in another class constant (for
     * example the Book Club base selects) can still reuse the canonical name
     * contract. {@see displaySql()} rewrites only the validated alias prefix.
     */
    public const DISPLAY_SQL_A = "CASE WHEN TRIM(COALESCE(`a`.`pseudonimo`, '')) <> ''"
        . " AND TRIM(COALESCE(`a`.`nome`, '')) <> ''"
        . " AND TRIM(COALESCE(`a`.`pseudonimo`, '')) <> TRIM(COALESCE(`a`.`nome`, ''))"
        . " THEN CONCAT(TRIM(COALESCE(`a`.`pseudonimo`, '')), ' (', TRIM(COALESCE(`a`.`nome`, '')), ')')"
        . " WHEN TRIM(COALESCE(`a`.`nome`, '')) <> '' THEN TRIM(COALESCE(`a`.`nome`, ''))"
        . " ELSE TRIM(COALESCE(`a`.`pseudonimo`, '')) END";

    /**
     * PHP-side display name from an author row (keys: nome, pseudonimo).
     *
     * @param array<string,mixed> $author
     */
    public static function display(array $author): string
    {
        $nome = trim((string)($author['nome'] ?? ''));
        $pseudonimo = trim((string)($author['pseudonimo'] ?? ''));

        if ($pseudonimo !== '' && $pseudonimo !== $nome) {
            return $nome !== '' ? $pseudonimo . ' (' . $nome . ')' : $pseudonimo;
        }
        return $nome !== '' ? $nome : $pseudonimo;
    }

    /**
     * SQL expression producing the same display name, for use inside SELECT /
     * GROUP_CONCAT. `$alias` is the table alias of `autori` in the query (the
     * columns `nome`/`pseudonimo` are referenced through it). The alias is
     * validated to a bare identifier so it can never carry injection.
     */
    public static function displaySql(string $alias = 'a'): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
            $alias = 'a';
        }
        // Match display(): trim both fields and tolerate old rows containing
        // NULL/whitespace-only values. Keeping PHP and SQL output identical is
        // important because the same author is rendered by both query-backed
        // lists and PHP-backed detail views.
        return $alias === 'a'
            ? self::DISPLAY_SQL_A
            : str_replace('`a`.', "`{$alias}`.", self::DISPLAY_SQL_A);
    }
}
