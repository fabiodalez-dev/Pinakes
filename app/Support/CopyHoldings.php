<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What the library owns, next to what it can lend today.
 *
 * `libri.copie_totali` counts copies IN CIRCULATION: DataIntegrity recalculates
 * it excluding perso, danneggiato, manutenzione, in_restauro and
 * in_trasferimento, and the loan capacity, the integrity checks and the edit
 * form all read it with that meaning. Sound for lending, wrong to publish: a
 * book whose only copy is under maintenance showed "0 / 0" to readers, which
 * reads as "this library does not have it" rather than "it has it, just not
 * available right now" (issue #426).
 *
 * So the public side asks this class instead. It never changes the column: it
 * counts the `copie` rows and reports the owned total plus what is out of
 * circulation and why, leaving copie_totali to the lending logic that needs it.
 */
final class CopyHoldings
{
    /** Copy states that exist but cannot circulate; the same set DataIntegrity excludes. */
    public const OUT_OF_CIRCULATION = ['perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento'];

    private function __construct()
    {
    }

    /**
     * Owned and out-of-circulation counts per book id.
     *
     * A book with no `copie` rows at all is absent from the result: those are
     * legacy entries whose holdings live only in libri.copie_totali, and the
     * caller keeps using that number rather than claiming zero copies.
     *
     * @param list<int> $bookIds
     * @return array<int, array{owned: int, out: int, states: array<string, int>}>
     */
    public static function forBooks(\mysqli $db, array $bookIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $bookIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $holdings = [];
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("SELECT libro_id, stato, COUNT(*) AS n FROM copie WHERE libro_id IN ($placeholders) GROUP BY libro_id, stato");
            if ($stmt === false) {
                return [];
            }
            $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
            if (!$stmt->execute()) {
                $stmt->close();
                return [];
            }
            $result = $stmt->get_result();
            $rows = $result instanceof \mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();
            foreach ($rows as $row) {
                $id = (int) $row['libro_id'];
                $state = (string) $row['stato'];
                $count = (int) $row['n'];
                $holdings[$id] ??= ['owned' => 0, 'out' => 0, 'states' => []];
                $holdings[$id]['owned'] += $count;
                if (in_array($state, self::OUT_OF_CIRCULATION, true)) {
                    $holdings[$id]['out'] += $count;
                    $holdings[$id]['states'][$state] = ($holdings[$id]['states'][$state] ?? 0) + $count;
                }
            }
        } catch (\Throwable $e) {
            // A public page must render even when this extra count fails; the
            // caller then falls back to copie_totali, the pre-#426 behaviour.
            return [];
        }
        return $holdings;
    }

    /**
     * @return array{owned: int, out: int, states: array<string, int>}|null null when the book has no per-copy rows
     */
    public static function forBook(\mysqli $db, int $bookId): ?array
    {
        return self::forBooks($db, [$bookId])[$bookId] ?? null;
    }

    /**
     * The denominator to publish: owned copies, or the stored count for a
     * legacy book that has no per-copy rows.
     *
     * @param array{owned: int, out: int, states: array<string, int>}|null $holdings
     */
    public static function publishedTotal(?array $holdings, int $storedTotal): int
    {
        return $holdings === null ? max(0, $storedTotal) : $holdings['owned'];
    }

    /**
     * Why the owned copies are not all available, as "Under maintenance: 1",
     * or an empty string when everything is in circulation.
     *
     * Reuses the per-state labels the admin copy list already translates, so a
     * reader is told the reason rather than left with an unexplained zero.
     *
     * @param array{owned: int, out: int, states: array<string, int>}|null $holdings
     */
    public static function outOfCirculationNote(?array $holdings): string
    {
        if ($holdings === null || $holdings['out'] < 1) {
            return '';
        }
        $labels = [
            'manutenzione' => __('In manutenzione'),
            'in_restauro' => __('In restauro'),
            'in_trasferimento' => __('In trasferimento'),
            'perso' => __('Perso'),
            'danneggiato' => __('Danneggiato'),
        ];
        $parts = [];
        foreach ($holdings['states'] as $state => $count) {
            $parts[] = ($labels[$state] ?? $state) . ': ' . (int) $count;
        }
        return implode(' · ', $parts);
    }
}
