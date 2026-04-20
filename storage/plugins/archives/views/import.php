<?php
/**
 * Archives — MARCXML import view (phase 4).
 *
 * @var array{success: bool, dry_run: bool, parsed: list<array<string, mixed>>, inserted: list<array<string, mixed>>, errors: list<string>}|null $result
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="p-6 max-w-4xl mx-auto">
    <nav class="text-sm text-gray-500 mb-1">
        <a href="<?= $e(url('/admin/archives')) ?>" class="hover:underline"><?= __("Archivi") ?></a>
        &nbsp;&raquo;&nbsp; <?= __("Importa MARCXML") ?>
    </nav>
    <h1 class="text-2xl font-bold text-gray-900 mb-4"><?= __("Importa MARCXML") ?></h1>
    <p class="text-sm text-gray-600 mb-6">
        <?= __("Carica un file MARCXML (formato ABA / MARC21 Slim) per importare unità archivistiche. Gli authority record nel file vengono ignorati in questa fase.") ?>
    </p>

    <?php if ($result !== null): ?>
        <?php if (!empty($result['errors'])): ?>
            <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-4 rounded">
                <p class="text-sm font-semibold text-red-800"><?= __("Errori durante l'importazione") ?></p>
                <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                    <?php foreach ($result['errors'] as $err): ?>
                        <li><?= $e($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($result['dry_run']): ?>
            <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-4 rounded">
                <p class="text-sm text-blue-800">
                    <strong><?= __("Dry-run:") ?></strong>
                    <?= sprintf(
                        /* TRANSLATORS: %d = number of records found */
                        __("trovati %d record. Nessuna riga è stata inserita. Deseleziona 'Dry-run' e ripeti per procedere con l'insert."),
                        count($result['parsed'])
                    ) ?>
                </p>
            </div>
        <?php elseif ($result['success']): ?>
            <div class="bg-green-50 border-l-4 border-green-400 p-4 mb-4 rounded">
                <p class="text-sm text-green-800">
                    <?= sprintf(__("Importati %d record su %d."), count($result['inserted']), count($result['parsed'])) ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if (!empty($result['parsed'])): ?>
            <div class="bg-white shadow rounded-lg overflow-hidden mb-6">
                <div class="px-6 py-3 bg-gray-50 border-b">
                    <h2 class="text-sm font-semibold text-gray-700"><?= __("Record analizzati") ?></h2>
                </div>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase"><?= __("Reference") ?></th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase"><?= __("Livello") ?></th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase"><?= __("Titolo") ?></th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase"><?= __("Date") ?></th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase"><?= __("Stato") ?></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php
                        $insertedRefs = array_column($result['inserted'], 'reference_code');
                        foreach ($result['parsed'] as $rec):
                            $ref = (string) ($rec['reference_code'] ?? '');
                            $dateRange = '';
                            if (!empty($rec['date_start'])) {
                                $dateRange = (string) $rec['date_start'];
                                if (!empty($rec['date_end']) && $rec['date_end'] !== $rec['date_start']) {
                                    $dateRange .= '–' . (string) $rec['date_end'];
                                }
                            }
                            $status = $result['dry_run']
                                ? __("preview")
                                : (in_array($ref, $insertedRefs, true) ? __("inserito") : __("saltato"));
                        ?>
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs text-gray-600"><?= $e($ref) ?></td>
                                <td class="px-4 py-2"><?= $e((string) ($rec['level'] ?? '')) ?></td>
                                <td class="px-4 py-2"><?= $e((string) ($rec['constructed_title'] ?? '')) ?></td>
                                <td class="px-4 py-2 text-xs text-gray-600"><?= $e($dateRange) ?></td>
                                <td class="px-4 py-2 text-xs"><?= $e($status) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <form method="POST" action="<?= $e(url('/admin/archives/import')) ?>" enctype="multipart/form-data"
          class="bg-white shadow rounded-lg p-6 space-y-4">
        <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">

        <div>
            <label for="marcxml" class="block text-sm font-medium text-gray-700 mb-1">
                <?= __("File MARCXML") ?> <span class="text-red-500">*</span>
            </label>
            <input type="file" name="marcxml" id="marcxml" accept=".xml,application/xml,application/marcxml+xml" required
                   class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="dry_run" value="1" checked
                   class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
            <span class="text-gray-700"><?= __("Dry-run (analizza senza inserire)") ?></span>
        </label>

        <p class="text-xs text-gray-500">
            <?= __("Consiglio: esegui prima un dry-run per verificare la mappatura dei campi, poi disabilita il checkbox per l'insert.") ?>
        </p>

        <div class="flex items-center justify-end space-x-3 pt-4 border-t">
            <a href="<?= $e(url('/admin/archives')) ?>"
               class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                <?= __("Annulla") ?>
            </a>
            <button type="submit"
                    class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700">
                <?= __("Analizza / Importa") ?>
            </button>
        </div>
    </form>
</div>
