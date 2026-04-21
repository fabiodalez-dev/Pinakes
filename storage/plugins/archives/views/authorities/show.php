<?php
/**
 * Authorities — show (detail) view.
 *
 * @var array<string, mixed> $row
 * @var list<array<string, mixed>> $links             archival_units linked to this authority
 * @var list<array<string, mixed>> $linked_autori     autori rows linked via autori_authority_link
 * @var list<array<string, mixed>> $available_autori  all autori (capped @100) for the attach <select>
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$v = static fn(string $k): string => $e((string) ($row[$k] ?? ''));

$typeBadge = [
    'person'    => 'bg-indigo-100 text-indigo-800',
    'corporate' => 'bg-amber-100 text-amber-800',
    'family'    => 'bg-pink-100 text-pink-800',
];
$levelBadge = [
    'fonds'  => 'bg-purple-100 text-purple-800',
    'series' => 'bg-blue-100 text-blue-800',
    'file'   => 'bg-green-100 text-green-800',
    'item'   => 'bg-gray-100 text-gray-800',
];
$badgeClass = $typeBadge[(string) $row['type']] ?? 'bg-gray-100 text-gray-800';
$id = (int) $row['id'];
?>
<div class="p-6 max-w-4xl mx-auto">
    <nav class="text-sm text-gray-500 mb-2">
        <a href="<?= $e(url('/admin/archives')) ?>" class="hover:underline"><?= __("Archivi") ?></a>
        &nbsp;&raquo;&nbsp;
        <a href="<?= $e(url('/admin/archives/authorities')) ?>" class="hover:underline"><?= __("Authority records") ?></a>
        &nbsp;&raquo;&nbsp;
        <?= $v('authorised_form') ?>
    </nav>

    <div class="flex items-start justify-between mb-6">
        <div>
            <div class="flex items-center gap-3 mb-1">
                <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded <?= $badgeClass ?>">
                    <?= $v('type') ?>
                </span>
            </div>
            <h1 class="text-2xl font-bold text-gray-900"><?= $v('authorised_form') ?></h1>
            <?php if (!empty($row['dates_of_existence'])): ?>
                <p class="text-sm text-gray-600 mt-1 italic"><?= $v('dates_of_existence') ?></p>
            <?php endif; ?>
        </div>
        <div class="flex items-center gap-2">
            <a href="<?= $e(url('/admin/archives/authorities/' . $id . '/edit')) ?>"
               class="px-3 py-1.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                <?= __("Modifica") ?>
            </a>
            <?php
            // SECURITY: confirm() message must be serialised as a JS literal.
            // htmlspecialchars would encode ' as &#039;, the HTML parser then
            // decodes it BEFORE handing to the JS engine, yielding:
            //     confirm('... L'operazione ...');   → SyntaxError
            // The form would silently submit without a confirmation prompt.
            $confirmDeleteAuthority = json_encode(
                __("Eliminare questo authority record? L'operazione è reversibile (soft-delete)."),
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            );
            ?>
            <form method="POST" action="<?= $e(url('/admin/archives/authorities/' . $id . '/delete')) ?>"
                  onsubmit="return confirm(<?= $confirmDeleteAuthority ?>);"
                  class="inline">
                <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
                <button type="submit"
                        class="px-3 py-1.5 text-sm font-medium text-red-700 bg-white border border-red-300 rounded-md hover:bg-red-50">
                    <?= __("Elimina") ?>
                </button>
            </form>
        </div>
    </div>

    <div class="bg-white shadow rounded-lg overflow-hidden mb-6">
        <dl class="divide-y divide-gray-200">
            <?php if (!empty($row['history'])): ?>
                <div class="px-6 py-3 grid grid-cols-3 gap-4">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Storia") ?></dt>
                    <dd class="col-span-2 text-sm text-gray-900 whitespace-pre-wrap"><?= $v('history') ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($row['functions'])): ?>
                <div class="px-6 py-3 grid grid-cols-3 gap-4">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Funzioni") ?></dt>
                    <dd class="col-span-2 text-sm text-gray-900 whitespace-pre-wrap"><?= $v('functions') ?></dd>
                </div>
            <?php endif; ?>
            <div class="px-6 py-3 grid grid-cols-3 gap-4">
                <dt class="text-sm font-medium text-gray-500"><?= __("Creato") ?></dt>
                <dd class="col-span-2 text-xs text-gray-600 font-mono"><?= $v('created_at') ?></dd>
            </div>
        </dl>
    </div>

    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="px-6 py-3 bg-gray-50 border-b">
            <h2 class="text-sm font-semibold text-gray-700"><?= __("Archivi collegati") ?></h2>
        </div>
        <?php if (empty($links)): ?>
            <p class="px-6 py-4 text-sm text-gray-500 italic">
                <?= __("Questo authority record non è ancora collegato a nessun archivio.") ?>
            </p>
        <?php else: ?>
            <ul class="divide-y divide-gray-200">
                <?php foreach ($links as $link): ?>
                    <?php
                    $linkLevel = (string) ($link['level'] ?? '');
                    $linkBadge = $levelBadge[$linkLevel] ?? 'bg-gray-100 text-gray-800';
                    $linkId = (int) ($link['id'] ?? 0);
                    ?>
                    <li class="px-6 py-3 flex items-center justify-between text-sm">
                        <div class="flex items-center gap-3">
                            <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded <?= $linkBadge ?>"><?= $e($linkLevel) ?></span>
                            <a href="<?= $e(url('/admin/archives/' . $linkId)) ?>" class="text-blue-600 hover:underline">
                                <?= $e((string) ($link['constructed_title'] ?? '')) ?>
                            </a>
                            <span class="text-xs text-gray-400 font-mono">(<?= $e((string) ($link['reference_code'] ?? '')) ?>)</span>
                        </div>
                        <span class="text-xs text-gray-500"><?= __("ruolo:") ?> <?= $e((string) $link['role']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- Phase 2b — linked `autori` rows (library-side authors) -->
    <div class="bg-white shadow rounded-lg overflow-hidden mt-6">
        <div class="px-6 py-3 bg-gray-50 border-b flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-700"><?= __("Autori di libreria collegati") ?></h2>
            <a href="<?= $e(url('/admin/autori')) ?>" class="text-xs text-blue-600 hover:underline">
                <?= __("Vai ad Autori") ?>
            </a>
        </div>

        <?php if (empty($linked_autori)): ?>
            <p class="px-6 py-4 text-sm text-gray-500 italic">
                <?= __("Nessun autore di libreria collegato. Associa un autore qui sotto per unificare libri e archivi nella ricerca.") ?>
            </p>
        <?php else: ?>
            <ul class="divide-y divide-gray-200">
                <?php foreach ($linked_autori as $a): ?>
                    <?php $aid = (int) ($a['id'] ?? 0); ?>
                    <li class="px-6 py-3 flex items-center justify-between text-sm">
                        <div class="flex items-center gap-3">
                            <a href="<?= $e(url('/admin/autori/' . $aid)) ?>" class="text-blue-600 hover:underline">
                                <?= $e((string) ($a['nome'] ?? '')) ?>
                            </a>
                            <?php if (!empty($a['data_nascita']) || !empty($a['data_morte'])): ?>
                                <span class="text-xs text-gray-400 italic">
                                    (<?= $e((string) ($a['data_nascita'] ?? '')) ?>–<?= $e((string) ($a['data_morte'] ?? '')) ?>)
                                </span>
                            <?php endif; ?>
                            <span class="text-xs text-gray-500">
                                <?= (int) ($a['book_count'] ?? 0) ?> <?= __("libri") ?>
                            </span>
                        </div>
                        <form method="POST"
                              action="<?= $e(url('/admin/archives/authorities/' . $id . '/autori/' . $aid . '/unlink')) ?>"
                              onsubmit="return confirm(<?= json_encode(__('Rimuovere questo collegamento?'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);"
                              class="inline">
                            <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
                            <button type="submit" class="text-xs text-red-600 hover:underline"><?= __("scollega") ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if (!empty($available_autori)): ?>
            <form method="POST" action="<?= $e(url('/admin/archives/authorities/' . $id . '/autori/link')) ?>"
                  class="px-6 py-4 border-t bg-gray-50 flex items-center gap-2">
                <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
                <select name="autori_id" required
                        class="flex-1 rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">— <?= __("Seleziona un autore di libreria") ?> —</option>
                    <?php foreach ($available_autori as $a): ?>
                        <option value="<?= (int) $a['id'] ?>"><?= $e((string) $a['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit"
                        class="px-3 py-1.5 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700">
                    <?= __("Collega") ?>
                </button>
            </form>
            <p class="px-6 pb-3 text-xs text-gray-500 italic">
                <?= __("Mostrati primi 100 autori. Per liste più lunghe, una ricerca type-ahead arriverà nella prossima fase.") ?>
            </p>
        <?php endif; ?>
    </div>
</div>
