<?php
/**
 * Archives — Attività (Phase 5 / v0.8.0) — detail view.
 *
 * @var array<string, mixed>|null       $row
 * @var list<array<string, mixed>>|null $linkedUnits
 * @var list<array<string, mixed>>|null $relations
 * @var string|null                     $agent_label
 * @var string|null                     $parent_label
 */
declare(strict_types=1);

$e           = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$row         = $row         ?? [];
$linkedUnits = $linkedUnits ?? [];
$relations   = $relations   ?? [];
$id          = (int) ($row['id'] ?? 0);
$title       = (string) ($row['title'] ?? '');
$type        = (string) ($row['activity_type'] ?? '');
// PHPDoc declares $agent_label/$parent_label as string|null — isset+!=='' is
// the only test we need; is_string() is redundant under the declared type.
$agentLabel  = (isset($agent_label)  && $agent_label  !== '') ? $agent_label  : null;
$parentLabel = (isset($parent_label) && $parent_label !== '') ? $parent_label : null;

/**
 * Safely serialise a PHP value as a JS literal for use INSIDE an HTML
 * attribute delimited with double quotes (e.g. `onclick="..."`).
 * Matches the helper used in storage/plugins/archives/views/show.php.
 */
$jsAttr = static fn (mixed $x): string =>
    htmlspecialchars(
        (string) json_encode($x, JSON_UNESCAPED_UNICODE),
        ENT_QUOTES,
        'UTF-8'
    );

// Dynamic confirm message: include linked-unit count when relevant so the
// user sees the side-effect of deletion (orphaned RiC relations) BEFORE
// confirming. Italian source string; en_US / fr_FR / de_DE land in JSON.
$archivesDeleteActivityId = 'archivesDeleteActivity_' . $id;
$archivesDeleteMsg        = empty($linkedUnits)
    ? __('Eliminare questa attività?')
    : sprintf(
        __('Eliminare questa attività? È collegata a %d unità archivistiche — le relazioni saranno orfane.'),
        count($linkedUnits)
    );

$typeLabel = [
    'function'    => __('Funzione'),
    'activity'    => __('Attività'),
    'transaction' => __('Transazione'),
    'task'        => __('Compito'),
    'mandate'     => __('Mandato'),
];
?>
<div class="p-6 max-w-4xl mx-auto">
    <div class="mb-6">
        <nav class="text-sm text-gray-500 mb-2">
            <a href="<?= $e(url('/admin/archives')) ?>" class="hover:underline"><?= __('Archivi') ?></a>
            &nbsp;&raquo;&nbsp;
            <a href="<?= $e(url('/admin/archives/activities')) ?>" class="hover:underline"><?= __('Attività') ?></a>
            &nbsp;&raquo;&nbsp; #<?= $id ?>
        </nav>
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900"><?= $e($title) ?></h1>
                <p class="text-sm text-gray-600 mt-1">
                    <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded bg-gray-100 text-gray-700">
                        <?= $e($typeLabel[$type] ?? $type) ?>
                    </span>
                    <?php if (!empty($row['is_ongoing'])): ?>
                        <span class="inline-block ml-2 px-2 py-0.5 text-xs font-semibold rounded bg-green-100 text-green-800">
                            <?= __('in corso') ?>
                        </span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="space-x-2">
                <a href="<?= $e(url('/admin/archives/activities/' . $id . '/edit')) ?>"
                   class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded text-sm font-semibold shadow-sm">
                    <?= __('Modifica') ?>
                </a>
                <form id="<?= $e($archivesDeleteActivityId) ?>"
                      method="POST" action="<?= $e(url('/admin/archives/activities/' . $id . '/delete')) ?>"
                      class="inline">
                    <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
                    <button type="button"
                            onclick="archivesSwalConfirm(<?= $jsAttr($archivesDeleteActivityId) ?>, <?= $jsAttr($archivesDeleteMsg) ?>, <?= $jsAttr(__('Elimina')) ?>)"
                            class="bg-red-50 hover:bg-red-100 text-red-700 px-4 py-2 rounded text-sm font-semibold">
                        <?= __('Elimina') ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="bg-white shadow rounded-lg p-6 space-y-4">
        <?php if (!empty($row['description'])): ?>
            <div>
                <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2"><?= __('Descrizione') ?></h2>
                <div class="text-sm text-gray-800"><?= nl2br($e((string) $row['description'])) ?></div>
            </div>
        <?php endif; ?>

        <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <?php if (!empty($row['date_start']) || !empty($row['date_end'])): ?>
                <dt class="font-semibold text-gray-700"><?= __('Intervallo temporale') ?></dt>
                <dd class="text-gray-800">
                    <?= $e((string) ($row['date_start'] ?? '')) ?>
                    <?php if (!empty($row['date_end'])): ?>–<?= $e((string) $row['date_end']) ?><?php endif; ?>
                </dd>
            <?php endif; ?>
            <?php if (!empty($row['source_ref'])): ?>
                <dt class="font-semibold text-gray-700"><?= __('Riferimento normativo') ?></dt>
                <dd class="text-gray-800"><?= $e((string) $row['source_ref']) ?></dd>
            <?php endif; ?>
            <?php if (!empty($row['agent_id'])): ?>
                <dt class="font-semibold text-gray-700"><?= __('Agente esecutore') ?></dt>
                <dd>
                    <a class="text-blue-600 hover:underline"
                       href="<?= $e(url('/admin/archives/authorities/' . (int) $row['agent_id'])) ?>">
                        <?= $agentLabel !== null ? $e($agentLabel) : '#' . (int) $row['agent_id'] ?>
                    </a>
                </dd>
            <?php endif; ?>
            <?php if (!empty($row['parent_id'])): ?>
                <dt class="font-semibold text-gray-700"><?= __('Attività padre') ?></dt>
                <dd>
                    <a class="text-blue-600 hover:underline"
                       href="<?= $e(url('/admin/archives/activities/' . (int) $row['parent_id'])) ?>">
                        <?= $parentLabel !== null ? $e($parentLabel) : '#' . (int) $row['parent_id'] ?>
                    </a>
                </dd>
            <?php endif; ?>
        </dl>
    </div>

    <div class="bg-white shadow rounded-lg p-6 mt-4">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
            <?= __('Unità archivistiche collegate') ?>
        </h2>
        <?php if (empty($linkedUnits)): ?>
            <p class="text-sm text-gray-500"><?= __('Nessuna unità archivistica collegata.') ?></p>
        <?php else: ?>
            <ul class="space-y-2 text-sm">
                <?php foreach ($linkedUnits as $link):
                    $uid    = (int) ($link['unit_id'] ?? 0);
                    $pred   = (string) ($link['ric_predicate'] ?? '');
                    $label  = (string) ($link['constructed_title'] ?? $link['formal_title'] ?? ('#' . $uid));
                ?>
                    <li>
                        <code class="text-xs text-gray-500 bg-gray-50 px-1 py-0.5 rounded"><?= $e($pred) ?></code>
                        →
                        <a class="text-blue-600 hover:underline"
                           href="<?= $e(url('/admin/archives/' . $uid)) ?>"><?= $e($label) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="bg-white shadow rounded-lg p-6 mt-4">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3"><?= __('Relazioni RiC-CM') ?></h2>
        <?php if (empty($relations)): ?>
            <p class="text-sm text-gray-500"><?= __('Nessuna relazione collegata.') ?></p>
        <?php else: ?>
            <ul class="space-y-2 text-sm">
                <?php foreach ($relations as $rel):
                    $relId = (int) ($rel['id'] ?? 0);
                    $pred  = (string) ($rel['ric_predicate'] ?? '');
                    $tType = (string) ($rel['target_type'] ?? '');
                    $tId   = (int) ($rel['target_id'] ?? 0);
                ?>
                    <li class="flex items-center gap-2">
                        <code class="text-xs text-gray-500 bg-gray-50 px-1 py-0.5 rounded"><?= $e($pred) ?></code>
                        <span><?= $e($tType) ?> #<?= $tId ?></span>
                        <form method="POST" action="<?= $e(url('/admin/archives/relations/' . $relId . '/detach')) ?>" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
                            <button type="submit" onclick="return confirm('<?= $e(__('Scollegare questa relazione?')) ?>')" class="text-red-600 text-xs hover:underline">
                                <?= __('scollega') ?>
                            </button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="POST" action="<?= $e(url('/admin/archives/relations/attach')) ?>" class="mt-4 grid grid-cols-1 md:grid-cols-4 gap-2 text-sm">
            <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
            <input type="hidden" name="source_type" value="archive_activity">
            <input type="hidden" name="source_id" value="<?= $id ?>">
            <select name="target_type" required class="rounded-md border-gray-300">
                <option value=""><?= __('Tipo entità') ?></option>
                <option value="archival_unit"><?= __('Unità archivistica') ?></option>
                <option value="authority_record"><?= __('Agente') ?></option>
                <option value="archive_activity"><?= __('Attività') ?></option>
                <option value="archive_place"><?= __('Luogo') ?></option>
            </select>
            <input type="number" name="target_id" required placeholder="<?= $e(__('ID target')) ?>" class="rounded-md border-gray-300">
            <input type="text" name="ric_predicate" required placeholder="ric:isOrWasRelatedTo" class="rounded-md border-gray-300">
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded text-sm font-semibold"><?= __('Collega') ?></button>
        </form>
    </div>

    <p class="mt-4 text-xs text-gray-500">
        <?= __('Linked Data:') ?>
        <a class="text-blue-600 hover:underline"
           href="<?= $e(url('/archives/activities/' . $id . '/ric.json')) ?>">
            /archives/activities/<?= $id ?>/ric.json
        </a>
    </p>
</div>

<?php /* SweetAlert2 confirm helper — matches the pattern used in
          storage/plugins/archives/views/show.php. Idempotency guard so
          multiple views on the same page can load it without redefining. */ ?>
<script>
if (typeof window.archivesSwalConfirm !== 'function') {
    window.archivesSwalConfirm = function (formId, message, confirmLabel) {
        var form = document.getElementById(formId);
        if (!form) return;
        if (typeof Swal === 'undefined' || !Swal.fire) {
            if (window.confirm(message)) form.submit();
            return;
        }
        Swal.fire({
            title: message,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: confirmLabel || <?= json_encode(__('Conferma'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            cancelButtonText: <?= json_encode(__('Annulla'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            confirmButtonColor: '#dc2626',
            focusCancel: true,
            reverseButtons: true
        }).then(function (r) {
            if (r && r.isConfirmed) form.submit();
        });
    };
}
</script>
