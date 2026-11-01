<?php
declare(strict_types=1);

namespace App\Support\Marc;

/**
 * Helpers the catalogue record readers share to file a name under the
 * authors or under its contributor role, and to read a page count out of an
 * extent statement. The book data they fill is the shape the book form reads
 * (title, authors, editor, translator, ...).
 */
class ContributorSorter
{
    /**
     * The page count of an extent statement: the number before "p.", "pages",
     * "S.", "sider" ("1 vol. (308 p.)" is 308, not 1). Without such a word,
     * the first number when $anyNumber; danMARC2 passes false, because its
     * 300 *a also holds an audiobook's minutes.
     */
    public static function pagesOf(string $extent, bool $anyNumber = true): string
    {
        if (preg_match('/(\d+)\s*(?:p|pp|pages?|pagine|pag|s|sider|sidor|seiten|sivua|str|stron)(?![\p{L}])/iu', $extent, $m) === 1) {
            return $m[1];
        }
        if ($anyNumber && preg_match('/(\d+)/', $extent, $m) === 1) {
            return $m[1];
        }
        return '';
    }

    /**
     * File a name under the authors or under its contributor role. A role the
     * form has no field for ("other": the writer of a preface, the publisher)
     * is left out.
     *
     * @param array<string, mixed> $book
     * @param array<string, list<string>> $contributors
     */
    public static function sortContributor(array &$book, array &$contributors, string $name, string $role): void
    {
        if ($role === 'author') {
            if (!in_array($name, $book['authors'], true)) {
                $book['authors'][] = $name;
            }
        } elseif (isset($contributors[$role]) && !in_array($name, $contributors[$role], true)) {
            $contributors[$role][] = $name;
        }
    }

    /**
     * Editors as a list, translator, illustrator and colorist as the first
     * name: the shape the book form reads.
     *
     * @param array<string, mixed> $book
     * @param array{editor: list<string>, translator: list<string>, illustrator: list<string>, colorist: list<string>} $contributors
     */
    public static function applyContributors(array &$book, array $contributors): void
    {
        if ($contributors['editor'] !== []) {
            $book['editor'] = $contributors['editor'];
        }
        foreach (['translator', 'illustrator', 'colorist'] as $role) {
            if ($contributors[$role] !== []) {
                $book[$role] = $contributors[$role][0];
            }
        }
    }
}
