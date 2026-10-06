<?php
declare(strict_types=1);

namespace App\Support;

/**
 * The genre tree as the catalogue filters it: a genre stands for itself and
 * for every genre below it, at any depth. Books and articles ask the same
 * question here, so a genre filter lists both by the same rule.
 */
final class GenreTree
{
    /**
     * The genre and every genre below it. Walked level by level rather than
     * with WITH RECURSIVE, which MySQL 5.7 does not have. Each genre enters
     * the set once, so a parent_id cycle cannot loop: the walk ends when a
     * level brings nothing new.
     *
     * @return non-empty-list<int>
     */
    public static function withDescendants(\mysqli $db, int $genreId): array
    {
        $family = [$genreId => true];
        $level = [$genreId];
        while ($level !== []) {
            $marks = implode(',', array_fill(0, count($level), '?'));
            $stmt = $db->prepare("SELECT id FROM generi WHERE parent_id IN ($marks)");
            if ($stmt === false) {
                throw new \RuntimeException('Genre tree prepare failed');
            }
            try {
                $stmt->bind_param(str_repeat('i', count($level)), ...$level);
                if (!$stmt->execute()) {
                    throw new \RuntimeException('Genre tree query failed');
                }
                $result = $stmt->get_result();
                $children = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
            } finally {
                $stmt->close();
            }
            $level = [];
            foreach ($children as $child) {
                $childId = (int) $child['id'];
                if (!isset($family[$childId])) {
                    $family[$childId] = true;
                    $level[] = $childId;
                }
            }
        }
        return array_keys($family);
    }
}
