<?php
/**
 * Public detail — one archival_unit with children + authorities + breadcrumb.
 *
 * @var array<string, mixed>                                 $row
 * @var list<array<string, mixed>>                           $children
 * @var list<array<string, mixed>>                           $authorities
 * @var list<array{id: int, title: string}>                  $breadcrumb
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$levelLabel = [
    'fonds'  => __('Fondo'),
    'series' => __('Serie'),
    'file'   => __('Fascicolo'),
    'item'   => __('Unità'),
];
$levelBadge = [
    'fonds'  => 'bg-purple-100 text-purple-800',
    'series' => 'bg-blue-100 text-blue-800',
    'file'   => 'bg-green-100 text-green-800',
    'item'   => 'bg-gray-100 text-gray-800',
];
$typeLabel = [
    'person'    => __('Persona'),
    'corporate' => __('Ente'),
    'family'    => __('Famiglia'),
];
$materialLabels = [
    'text'       => __('Testo / manoscritto (bf)'),
    'photograph' => __('Fotografia (hf)'),
    'poster'     => __('Poster (hp)'),
    'postcard'   => __('Cartolina (hm)'),
    'drawing'    => __('Disegno / opera grafica (hd)'),
    'audio'      => __('Registrazione audio (lm)'),
    'video'      => __('Video (vm)'),
    'other'      => __('Altro'),
    'map'        => __('Mappa / cartografia (hk)'),
    'picture'    => __('Immagine / stampa / dipinto (hb)'),
    'object'     => __('Oggetto tridimensionale / realia (ho)'),
    'film'       => __('Pellicola cinematografica (lf)'),
    'microform'  => __('Microforma (bm)'),
    'electronic' => __('Risorsa elettronica / nato-digitale (le)'),
    'mixed'      => __('Materiale misto (zz)'),
];
$roleLabel = [
    'creator'    => __('Creatore'),
    'subject'    => __('Soggetto'),
    'recipient'  => __('Destinatario'),
    'custodian'  => __('Conservatore'),
    'associated' => __('Associato'),
];

$archiveBase = \App\Support\RouteTranslator::route('archives');
$level = (string) $row['level'];
$dateRange = '';
if (!empty($row['date_start'])) {
    $dateRange = (string) $row['date_start'];
    if (!empty($row['date_end']) && $row['date_end'] !== $row['date_start']) {
        $dateRange .= '–' . (string) $row['date_end'];
    }
}
?>
<article class="max-w-4xl mx-auto px-4 sm:px-6 py-10">
    <nav class="text-sm text-gray-500 mb-4 flex flex-wrap gap-1">
        <a href="<?= $e(url($archiveBase)) ?>" class="hover:text-blue-600 hover:underline"><?= __("Archivio") ?></a>
        <?php foreach ($breadcrumb as $crumb): ?>
            <span>&raquo;</span>
            <a href="<?= $e(url($archiveBase . '/' . (int) $crumb['id'])) ?>" class="hover:text-blue-600 hover:underline">
                <?= $e($crumb['title']) ?>
            </a>
        <?php endforeach; ?>
        <span>&raquo;</span>
        <span class="text-gray-700"><?= $e((string) $row['constructed_title']) ?></span>
    </nav>

    <header class="mb-6">
        <div class="flex items-center gap-3 mb-2">
            <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded <?= $e($levelBadge[$level] ?? 'bg-gray-100 text-gray-800') ?>">
                <?= $e($levelLabel[$level] ?? $level) ?>
            </span>
            <span class="text-xs text-gray-400 font-mono"><?= $e((string) $row['reference_code']) ?></span>
        </div>
        <h1 class="text-3xl font-bold text-gray-900"><?= $e((string) $row['constructed_title']) ?></h1>
        <?php if (!empty($row['formal_title']) && $row['formal_title'] !== $row['constructed_title']): ?>
            <p class="text-gray-600 italic mt-1"><?= $e((string) $row['formal_title']) ?></p>
        <?php endif; ?>
        <?php if ($dateRange !== ''): ?>
            <p class="text-sm text-gray-500 mt-2"><?= $e($dateRange) ?></p>
        <?php endif; ?>
    </header>

    <!-- Identity + content -->
    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden mb-6">
        <dl class="divide-y divide-gray-200">
            <?php if (!empty($row['extent'])): ?>
                <div class="px-6 py-4 grid grid-cols-1 md:grid-cols-3 gap-2">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Estensione e supporto") ?></dt>
                    <dd class="md:col-span-2 text-sm text-gray-900"><?= $e((string) $row['extent']) ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($row['scope_content'])): ?>
                <div class="px-6 py-4 grid grid-cols-1 md:grid-cols-3 gap-2">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Ambito e contenuto") ?></dt>
                    <dd class="md:col-span-2 text-sm text-gray-900 whitespace-pre-line"><?= $e((string) $row['scope_content']) ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($row['specific_material']) && $row['specific_material'] !== 'text'): ?>
                <div class="px-6 py-4 grid grid-cols-1 md:grid-cols-3 gap-2">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Tipo di materiale") ?></dt>
                    <dd class="md:col-span-2 text-sm text-gray-900">
                        <?= $e($materialLabels[(string) $row['specific_material']] ?? (string) $row['specific_material']) ?>
                    </dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($row['photographer'])): ?>
                <div class="px-6 py-4 grid grid-cols-1 md:grid-cols-3 gap-2">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Fotografo / autore primario") ?></dt>
                    <dd class="md:col-span-2 text-sm text-gray-900"><?= $e((string) $row['photographer']) ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($row['language_codes'])): ?>
                <div class="px-6 py-4 grid grid-cols-1 md:grid-cols-3 gap-2">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Lingua") ?></dt>
                    <dd class="md:col-span-2 text-sm text-gray-900 font-mono"><?= $e((string) $row['language_codes']) ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($row['archival_history'])): ?>
                <div class="px-6 py-4 grid grid-cols-1 md:grid-cols-3 gap-2">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Storia archivistica") ?></dt>
                    <dd class="md:col-span-2 text-sm text-gray-900 whitespace-pre-line"><?= $e((string) $row['archival_history']) ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($row['access_conditions'])): ?>
                <div class="px-6 py-4 grid grid-cols-1 md:grid-cols-3 gap-2">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Condizioni di accesso") ?></dt>
                    <dd class="md:col-span-2 text-sm text-gray-900"><?= $e((string) $row['access_conditions']) ?></dd>
                </div>
            <?php endif; ?>
        </dl>
    </div>

    <!-- Authorities -->
    <?php if (!empty($authorities)): ?>
        <div class="bg-white shadow-sm rounded-lg border border-gray-200 mb-6">
            <div class="px-6 py-3 bg-gray-50 border-b">
                <h2 class="text-sm font-semibold text-gray-700"><?= __("Soggetti produttori e associati") ?></h2>
            </div>
            <ul class="divide-y divide-gray-200">
                <?php foreach ($authorities as $auth): ?>
                    <li class="px-6 py-3 flex items-center justify-between text-sm">
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-gray-400"><?= $e($typeLabel[(string) $auth['type']] ?? (string) $auth['type']) ?></span>
                            <span class="font-medium text-gray-900"><?= $e((string) $auth['authorised_form']) ?></span>
                            <?php if (!empty($auth['dates_of_existence'])): ?>
                                <span class="text-xs text-gray-500 italic">(<?= $e((string) $auth['dates_of_existence']) ?>)</span>
                            <?php endif; ?>
                        </div>
                        <span class="text-xs text-gray-500 uppercase tracking-wider">
                            <?= $e($roleLabel[(string) $auth['role']] ?? (string) $auth['role']) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Children -->
    <?php if (!empty($children)): ?>
        <div class="bg-white shadow-sm rounded-lg border border-gray-200 mb-6">
            <div class="px-6 py-3 bg-gray-50 border-b">
                <h2 class="text-sm font-semibold text-gray-700">
                    <?= sprintf(__("Unità discendenti (%d)"), count($children)) ?>
                </h2>
            </div>
            <ul class="divide-y divide-gray-200">
                <?php foreach ($children as $child):
                    $cLevel = (string) $child['level'];
                    $cBadge = $levelBadge[$cLevel] ?? 'bg-gray-100 text-gray-800';
                    $cDate = '';
                    if (!empty($child['date_start'])) {
                        $cDate = (string) $child['date_start'];
                        if (!empty($child['date_end']) && $child['date_end'] !== $child['date_start']) {
                            $cDate .= '–' . (string) $child['date_end'];
                        }
                    }
                ?>
                    <li class="px-6 py-3 flex items-center gap-3 text-sm">
                        <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded <?= $e($cBadge) ?>">
                            <?= $e($levelLabel[$cLevel] ?? $cLevel) ?>
                        </span>
                        <a href="<?= $e(url($archiveBase . '/' . (int) $child['id'])) ?>"
                           class="text-blue-600 hover:underline flex-1">
                            <?= $e((string) $child['constructed_title']) ?>
                        </a>
                        <span class="text-xs text-gray-400 font-mono"><?= $e((string) $child['reference_code']) ?></span>
                        <?php if ($cDate !== ''): ?>
                            <span class="text-xs text-gray-500"><?= $e($cDate) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</article>
