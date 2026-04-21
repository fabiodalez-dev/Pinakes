<?php
/**
 * Archives — unified cross-entity search (phase 3).
 *
 * @var string $q
 * @var array{archival_units: list<array<string, mixed>>, authority_records: list<array<string, mixed>>, linked_autori: list<array<string, mixed>>} $results
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$levelBadge = [
    'fonds'  => 'bg-purple-100 text-purple-800',
    'series' => 'bg-blue-100 text-blue-800',
    'file'   => 'bg-green-100 text-green-800',
    'item'   => 'bg-gray-100 text-gray-800',
];
$typeBadge = [
    'person'    => 'bg-indigo-100 text-indigo-800',
    'corporate' => 'bg-amber-100 text-amber-800',
    'family'    => 'bg-pink-100 text-pink-800',
];

$totalHits = count($results['archival_units']) + count($results['authority_records']) + count($results['linked_autori']);
?>
<div class="p-6 max-w-6xl mx-auto">
    <div class="mb-6">
        <nav class="text-sm text-gray-500 mb-1">
            <a href="<?= $e(url('/admin/archives')) ?>" class="hover:underline"><?= __("Archivi") ?></a>
            &nbsp;&raquo;&nbsp; <?= __("Ricerca unificata") ?>
        </nav>
        <h1 class="text-2xl font-bold text-gray-900"><?= __("Ricerca unificata") ?></h1>
        <p class="text-sm text-gray-600 mt-1">
            <?= __("Ricerca cross-entity su archivi, authority records e autori di libreria riconciliati.") ?>
        </p>
    </div>

    <form method="GET" action="<?= $e(url('/admin/archives/search')) ?>" class="bg-white shadow rounded-lg p-4 mb-6 flex items-center gap-2">
        <input type="search" name="q" value="<?= $e($q) ?>"
               placeholder="<?= $e(__("Titolo, nome, descrizione… (minimo 2 caratteri)")) ?>"
               autofocus minlength="2"
               class="flex-1 rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500">
        <button type="submit"
                class="btn-primary">
            <?= __("Cerca") ?>
        </button>
    </form>

    <?php if ($q === ''): ?>
        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
            <p class="text-sm text-yellow-800">
                <?= __("Inserisci almeno 2 caratteri per avviare la ricerca.") ?>
            </p>
        </div>
    <?php elseif ($totalHits === 0): ?>
        <div class="bg-gray-50 border border-gray-200 p-6 rounded text-center">
            <p class="text-sm text-gray-700">
                <?= __("Nessun risultato per") ?> <strong><?= $e($q) ?></strong>.
            </p>
            <p class="text-xs text-gray-500 mt-1">
                <?= __("Suggerimento: la ricerca usa MATCH…AGAINST (full-text MySQL). Parole di 3 caratteri o meno possono essere ignorate dal min-word-length di MySQL.") ?>
            </p>
        </div>
    <?php else: ?>
        <p class="text-sm text-gray-600 mb-4">
            <?= $totalHits ?> <?= __("risultati per") ?> <strong><?= $e($q) ?></strong>.
        </p>

        <?php if (!empty($results['archival_units'])): ?>
            <section class="mb-8">
                <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider mb-2">
                    <?= __("Unità archivistiche") ?>
                    <span class="text-xs text-gray-400">(<?= count($results['archival_units']) ?>)</span>
                </h2>
                <ul class="bg-white shadow rounded-lg divide-y divide-gray-200">
                    <?php foreach ($results['archival_units'] as $row): ?>
                        <?php
                        $lvl = (string) $row['level'];
                        $badge = $levelBadge[$lvl] ?? 'bg-gray-100 text-gray-800';
                        $rid = (int) $row['id'];
                        $dateRange = '';
                        if (!empty($row['date_start'])) {
                            $dateRange = (string) $row['date_start'];
                            if (!empty($row['date_end']) && $row['date_end'] !== $row['date_start']) {
                                $dateRange .= '–' . (string) $row['date_end'];
                            }
                        }
                        ?>
                        <li class="px-6 py-3">
                            <div class="flex items-center gap-3">
                                <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded <?= $badge ?>"><?= $e($lvl) ?></span>
                                <a href="<?= $e(url('/admin/archives/' . $rid)) ?>" class="text-blue-600 hover:underline font-medium">
                                    <?= $e((string) $row['constructed_title']) ?>
                                </a>
                                <span class="text-xs text-gray-400 font-mono">(<?= $e((string) $row['reference_code']) ?>)</span>
                                <?php if ($dateRange !== ''): ?>
                                    <span class="text-xs text-gray-500"><?= $e($dateRange) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($row['extent'])): ?>
                                <p class="text-xs text-gray-500 mt-1 ml-1"><?= $e((string) $row['extent']) ?></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if (!empty($results['authority_records'])): ?>
            <section class="mb-8">
                <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider mb-2">
                    <?= __("Authority records") ?>
                    <span class="text-xs text-gray-400">(<?= count($results['authority_records']) ?>)</span>
                </h2>
                <ul class="bg-white shadow rounded-lg divide-y divide-gray-200">
                    <?php foreach ($results['authority_records'] as $row): ?>
                        <?php
                        $type = (string) $row['type'];
                        $badge = $typeBadge[$type] ?? 'bg-gray-100 text-gray-800';
                        $rid = (int) $row['id'];
                        ?>
                        <li class="px-6 py-3">
                            <div class="flex items-center gap-3">
                                <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded <?= $badge ?>"><?= $e($type) ?></span>
                                <a href="<?= $e(url('/admin/archives/authorities/' . $rid)) ?>" class="text-blue-600 hover:underline font-medium">
                                    <?= $e((string) $row['authorised_form']) ?>
                                </a>
                                <?php if (!empty($row['dates_of_existence'])): ?>
                                    <span class="text-xs text-gray-500 italic">(<?= $e((string) $row['dates_of_existence']) ?>)</span>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if (!empty($results['linked_autori'])): ?>
            <section>
                <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider mb-2">
                    <?= __("Autori di libreria riconciliati") ?>
                    <span class="text-xs text-gray-400">(<?= count($results['linked_autori']) ?>)</span>
                </h2>
                <ul class="bg-white shadow rounded-lg divide-y divide-gray-200">
                    <?php foreach ($results['linked_autori'] as $row): ?>
                        <?php
                        $aid = (int) $row['id'];
                        $authId = (int) ($row['authority_id'] ?? 0);
                        ?>
                        <li class="px-6 py-3">
                            <div class="flex items-center gap-3">
                                <a href="<?= $e(url('/admin/autori/' . $aid)) ?>" class="text-blue-600 hover:underline font-medium">
                                    <?= $e((string) $row['nome']) ?>
                                </a>
                                <span class="text-xs text-gray-500">
                                    <?= (int) ($row['book_count'] ?? 0) ?> <?= __("libri") ?>
                                </span>
                                <?php if ($authId > 0): ?>
                                    <span class="text-xs text-gray-400">↔</span>
                                    <a href="<?= $e(url('/admin/archives/authorities/' . $authId)) ?>"
                                       class="text-xs text-indigo-600 hover:underline">
                                        <?= $e((string) ($row['authorised_form'] ?? '')) ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>
