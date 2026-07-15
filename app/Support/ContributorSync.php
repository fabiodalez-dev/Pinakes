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

    /** @var list<string> */
    private const IMPORT_SOURCES = ['csv', 'librarything', 'legacy-backfill'];

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
     * Add entity links for non-empty legacy values without replacing anything.
     * Used by compatibility callers that do not declare ownership of a role.
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

        self::persistLinks($db, $bookId, $resolved);

        return $created;
    }

    /**
     * Replace only contributor links owned by one importer.
     *
     * Manual links are deliberately never claimed: when an association already
     * exists without an import-source row, it remains outside the importer's
     * stale set. Links created by the legacy backfill are transferred to the
     * first real importer that authoritatively rewrites that role.
     *
     * The caller's supplied role keys are authoritative even when their value is
     * empty. Roles not supplied remain untouched.
     *
     * @param array<string,mixed> $values keys are libri_autori role values
     * @return int number of author entities created
     */
    public static function syncImportedLegacyValues(
        mysqli $db,
        int $bookId,
        array $values,
        string $source
    ): int {
        if ($bookId <= 0) {
            return 0;
        }
        if (!in_array($source, self::IMPORT_SOURCES, true)) {
            throw new \InvalidArgumentException('Unsupported contributor import source');
        }

        $providedRoles = [];
        $rawByRole = [];
        $created = 0;
        foreach ($values as $role => $raw) {
            if (!in_array($role, self::ROLES, true)) {
                continue;
            }
            $providedRoles[$role] = true;
            if (!is_scalar($raw) || trim((string)$raw) === '') {
                continue;
            }
            $rawByRole[$role] = trim((string)$raw);
        }
        if ($providedRoles === []) {
            return 0;
        }

        // Portable active-transaction probe; see BookRepository's equivalent.
        // It requires neither MySQL 8's @@in_transaction nor PROCESS access.
        $insideTransaction = false;
        $probe = 'pinakes_import_probe';
        try {
            if ($db->query("SAVEPOINT {$probe}")
                && $db->query("ROLLBACK TO SAVEPOINT {$probe}")
            ) {
                $insideTransaction = true;
                $db->query("RELEASE SAVEPOINT {$probe}");
            }
        } catch (\mysqli_sql_exception) {
            $insideTransaction = false;
        }
        $savepoint = 'pinakes_import_contributors';
        if ($insideTransaction) {
            if (!$db->query("SAVEPOINT {$savepoint}")) {
                throw new \RuntimeException('Unable to create contributor import savepoint');
            }
        } elseif (!$db->begin_transaction()) {
            throw new \RuntimeException('Unable to begin contributor import transaction');
        }

        try {
            // Resolve and create entities only after the transaction/savepoint
            // exists, so a later provenance or link failure cannot leave
            // orphan author records behind.
            $desired = [];
            foreach ($rawByRole as $role => $raw) {
                $result = self::resolveNameIds($db, $raw);
                $created += $result['created'];
                foreach ($result['ids'] as $authorId) {
                    $desired[$authorId . ':' . $role] = [$authorId, $role];
                }
            }

            // A post-migration importer owns the corresponding legacy-backfill
            // links. Copy then delete avoids duplicate-key failures when this
            // importer already owns the same association.
            if ($source !== 'legacy-backfill') {
                $adopt = $db->prepare(
                    "INSERT INTO libri_autori_import_sources (libro_id, autore_id, ruolo, source)
                     SELECT libro_id, autore_id, ruolo, ?
                       FROM libri_autori_import_sources
                      WHERE libro_id = ? AND ruolo = ? AND source = 'legacy-backfill'
                     ON DUPLICATE KEY UPDATE source = VALUES(source)"
                );
                $dropLegacy = $db->prepare(
                    "DELETE FROM libri_autori_import_sources
                      WHERE libro_id = ? AND ruolo = ? AND source = 'legacy-backfill'"
                );
                if ($adopt === false || $dropLegacy === false) {
                    if ($adopt instanceof \mysqli_stmt) {
                        $adopt->close();
                    }
                    if ($dropLegacy instanceof \mysqli_stmt) {
                        $dropLegacy->close();
                    }
                    throw new \RuntimeException('Unable to prepare legacy contributor ownership transfer');
                }
                foreach (array_keys($providedRoles) as $role) {
                    $adopt->bind_param('sis', $source, $bookId, $role);
                    if (!$adopt->execute()) {
                        throw new \RuntimeException('Unable to adopt legacy contributor source: ' . $adopt->error);
                    }
                    $dropLegacy->bind_param('is', $bookId, $role);
                    if (!$dropLegacy->execute()) {
                        throw new \RuntimeException('Unable to release legacy contributor source: ' . $dropLegacy->error);
                    }
                }
                $adopt->close();
                $dropLegacy->close();
            }

            $owned = $db->prepare(
                'SELECT autore_id, ruolo FROM libri_autori_import_sources '
                . 'WHERE libro_id = ? AND source = ? FOR UPDATE'
            );
            if ($owned === false) {
                throw new \RuntimeException('Unable to prepare imported contributor read: ' . $db->error);
            }
            $owned->bind_param('is', $bookId, $source);
            if (!$owned->execute()) {
                throw new \RuntimeException('Unable to read imported contributors: ' . $owned->error);
            }
            $ownedResult = $owned->get_result();
            if (!($ownedResult instanceof \mysqli_result)) {
                throw new \RuntimeException('Imported contributor result unavailable');
            }
            $currentOwned = $ownedResult->fetch_all(MYSQLI_ASSOC);
            $owned->close();

            $exists = $db->prepare(
                'SELECT 1 FROM libri_autori WHERE libro_id = ? AND autore_id = ? AND ruolo = ? LIMIT 1'
            );
            $hasOwner = $db->prepare(
                'SELECT 1 FROM libri_autori_import_sources '
                . 'WHERE libro_id = ? AND autore_id = ? AND ruolo = ? LIMIT 1'
            );
            $track = $db->prepare(
                'INSERT INTO libri_autori_import_sources (libro_id, autore_id, ruolo, source) '
                . 'VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE source = VALUES(source)'
            );
            if ($exists === false || $hasOwner === false || $track === false) {
                foreach ([$exists, $hasOwner, $track] as $statement) {
                    if ($statement instanceof \mysqli_stmt) {
                        $statement->close();
                    }
                }
                throw new \RuntimeException('Unable to prepare imported contributor provenance');
            }

            foreach ($desired as [$authorId, $role]) {
                $exists->bind_param('iis', $bookId, $authorId, $role);
                if (!$exists->execute()) {
                    throw new \RuntimeException('Unable to inspect contributor association: ' . $exists->error);
                }
                $associationExisted = $exists->get_result()->fetch_row() !== null;

                $hasOwner->bind_param('iis', $bookId, $authorId, $role);
                if (!$hasOwner->execute()) {
                    throw new \RuntimeException('Unable to inspect contributor ownership: ' . $hasOwner->error);
                }
                $alreadyImported = $hasOwner->get_result()->fetch_row() !== null;

                self::persistLinks($db, $bookId, [[$authorId, $role]]);

                // A pre-existing untracked association is manual and must never
                // become deletable by a later re-import.
                if (!$associationExisted || $alreadyImported) {
                    $track->bind_param('iiss', $bookId, $authorId, $role, $source);
                    if (!$track->execute()) {
                        throw new \RuntimeException('Unable to track imported contributor: ' . $track->error);
                    }
                }
            }
            $exists->close();
            $hasOwner->close();
            $track->close();

            $deleteOwner = $db->prepare(
                'DELETE FROM libri_autori_import_sources '
                . 'WHERE libro_id = ? AND autore_id = ? AND ruolo = ? AND source = ?'
            );
            $otherOwner = $db->prepare(
                'SELECT 1 FROM libri_autori_import_sources '
                . 'WHERE libro_id = ? AND autore_id = ? AND ruolo = ? LIMIT 1'
            );
            $deleteLink = $db->prepare(
                'DELETE FROM libri_autori WHERE libro_id = ? AND autore_id = ? AND ruolo = ?'
            );
            if ($deleteOwner === false || $otherOwner === false || $deleteLink === false) {
                foreach ([$deleteOwner, $otherOwner, $deleteLink] as $statement) {
                    if ($statement instanceof \mysqli_stmt) {
                        $statement->close();
                    }
                }
                throw new \RuntimeException('Unable to prepare imported contributor pruning');
            }
            foreach ($currentOwned as $row) {
                $authorId = (int)($row['autore_id'] ?? 0);
                $role = (string)($row['ruolo'] ?? '');
                if ($authorId <= 0 || !isset($providedRoles[$role]) || isset($desired[$authorId . ':' . $role])) {
                    continue;
                }
                $deleteOwner->bind_param('iiss', $bookId, $authorId, $role, $source);
                if (!$deleteOwner->execute()) {
                    throw new \RuntimeException('Unable to delete stale contributor provenance: ' . $deleteOwner->error);
                }
                $otherOwner->bind_param('iis', $bookId, $authorId, $role);
                if (!$otherOwner->execute()) {
                    throw new \RuntimeException('Unable to inspect remaining contributor provenance: ' . $otherOwner->error);
                }
                if ($otherOwner->get_result()->fetch_row() === null) {
                    $deleteLink->bind_param('iis', $bookId, $authorId, $role);
                    if (!$deleteLink->execute()) {
                        throw new \RuntimeException('Unable to delete stale imported contributor: ' . $deleteLink->error);
                    }
                }
            }
            $deleteOwner->close();
            $otherOwner->close();
            $deleteLink->close();

            if ($insideTransaction) {
                if (!$db->query("RELEASE SAVEPOINT {$savepoint}")) {
                    throw new \RuntimeException('Unable to release contributor import savepoint');
                }
            } elseif (!$db->commit()) {
                throw new \RuntimeException('Unable to commit contributor import transaction');
            }
        } catch (\Throwable $e) {
            if ($insideTransaction) {
                $db->query("ROLLBACK TO SAVEPOINT {$savepoint}");
                $db->query("RELEASE SAVEPOINT {$savepoint}");
            } else {
                $db->rollback();
            }
            SecureLogger::error('Imported contributor sync failed', [
                'book_id' => $bookId,
                'source' => $source,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $created;
    }

    /**
     * @param list<array{0:int,1:string}> $links
     */
    private static function persistLinks(mysqli $db, int $bookId, array $links): void
    {
        if ($links === []) {
            return;
        }
        $insert = $db->prepare(
            'INSERT INTO libri_autori (libro_id, autore_id, ruolo) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE ruolo = VALUES(ruolo)'
        );
        $verify = $db->prepare(
            'SELECT 1 FROM libri_autori WHERE libro_id = ? AND autore_id = ? AND ruolo = ? LIMIT 1'
        );
        if ($insert === false || $verify === false) {
            if ($insert instanceof \mysqli_stmt) {
                $insert->close();
            }
            if ($verify instanceof \mysqli_stmt) {
                $verify->close();
            }
            throw new \RuntimeException('Unable to prepare contributor link persistence: ' . $db->error);
        }
        foreach ($links as [$authorId, $role]) {
            $insert->bind_param('iis', $bookId, $authorId, $role);
            if (!$insert->execute()) {
                throw new \RuntimeException('Unable to persist contributor link: ' . $insert->error);
            }
            $verify->bind_param('iis', $bookId, $authorId, $role);
            if (!$verify->execute() || $verify->get_result()->fetch_row() === null) {
                throw new \RuntimeException('Contributor link was not persisted');
            }
        }
        $insert->close();
        $verify->close();
    }
}
