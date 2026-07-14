<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\AuthorRepository;
use mysqli;

/**
 * Shared conversion of contributor names into libri_autori role links.
 *
 * The admin form, CSV import, LibraryThing import and the one-time legacy
 * backfill must all resolve names in exactly the same way. Keeping that logic
 * here prevents new imports from recreating the free-text/entity drift that the
 * 0.7.36 migration repairs.
 */
final class ContributorSync
{
    /** @var list<string> */
    private const ROLES = ['traduttore', 'illustratore', 'curatore', 'colorista'];

    /**
     * Split a legacy contributor value into individual names.
     *
     * @return list<string>
     */
    public static function splitNames(string $raw): array
    {
        // Semicolon/pipe/ampersand/conjunctions are unambiguous list
        // separators. A comma is not: SBN and UNIMARC commonly expose one
        // canonical personal name as "Surname, Forename". Split a lone comma
        // only when both sides already look like complete multi-word names
        // ("Mario Rossi, Luigi Bianchi"); otherwise preserve the whole value
        // and let AuthorNormalizer turn the inverted form into canonical order.
        $chunks = preg_split('/\s*(?:;|\||&|\se\s|\sand\s)\s*/ui', $raw) ?: [];
        $parts = [];
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            $commaParts = array_map('trim', explode(',', $chunk));
            if (count(array_filter($commaParts, static fn(string $part): bool => $part !== '')) === 0) {
                continue;
            }
            if (count($commaParts) === 2
                && self::wordCount($commaParts[0]) >= 2
                && self::wordCount($commaParts[1]) >= 2
            ) {
                array_push($parts, ...$commaParts);
            } elseif (count($commaParts) > 2
                && count(array_filter($commaParts, static fn(string $part): bool => self::wordCount($part) >= 2)) === count($commaParts)
            ) {
                array_push($parts, ...$commaParts);
            } else {
                $parts[] = $chunk;
            }
        }

        $names = [];
        foreach ($parts as $part) {
            $name = trim(HtmlHelper::decode($part));
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    private static function wordCount(string $value): int
    {
        $words = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($words) ? count($words) : 0;
    }

    /**
     * Find or create all author entities represented by a legacy value.
     *
     * @return array{ids:list<int>,created:int}
     */
    public static function resolveNameIds(mysqli $db, string $raw): array
    {
        $authors = new AuthorRepository($db);
        $ids = [];
        $created = 0;

        foreach (self::splitNames($raw) as $name) {
            $authorId = $authors->findByCanonicalName($name);
            if ($authorId === null) {
                $authorId = $authors->create(['nome' => $name]);
                $created++;
            }
            if ($authorId > 0) {
                $ids[$authorId] = $authorId;
            }
        }

        return ['ids' => array_values($ids), 'created' => $created];
    }

    /**
     * Add entity links for the non-empty legacy role values supplied by an
     * ingestion path. Existing links and principal authors are preserved.
     *
     * @param array<string,mixed> $values keys are libri_autori role values
     * @return int number of author entities created
     */
    public static function linkLegacyValues(mysqli $db, int $bookId, array $values): int
    {
        if ($bookId <= 0) {
            return 0;
        }

        $resolved = [];
        $created = 0;
        foreach ($values as $role => $raw) {
            if (!in_array($role, self::ROLES, true) || !is_scalar($raw)) {
                continue;
            }
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            $result = self::resolveNameIds($db, $raw);
            $created += $result['created'];
            foreach ($result['ids'] as $authorId) {
                $resolved[] = [$authorId, $role];
            }
        }

        if ($resolved === []) {
            return $created;
        }

        $insert = $db->prepare(
            'INSERT IGNORE INTO libri_autori (libro_id, autore_id, ruolo) VALUES (?, ?, ?)'
        );
        if ($insert === false) {
            throw new \RuntimeException('Unable to prepare contributor link insert: ' . $db->error);
        }
        foreach ($resolved as [$authorId, $role]) {
            $insert->bind_param('iis', $bookId, $authorId, $role);
            $insert->execute();
        }
        $insert->close();

        return $created;
    }
}
