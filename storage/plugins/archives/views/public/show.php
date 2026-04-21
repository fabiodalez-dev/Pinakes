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
$levelBadgeClass = [
    'fonds'  => 'text-bg-primary',
    'series' => 'text-bg-info',
    'file'   => 'text-bg-success',
    'item'   => 'text-bg-secondary',
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

$archiveBase = \App\Support\RouteTranslator::route('archives') ?: '/archive';
$level = (string) $row['level'];
$dateRange = '';
if (!empty($row['date_start'])) {
    $dateRange = (string) $row['date_start'];
    if (!empty($row['date_end']) && $row['date_end'] !== $row['date_start']) {
        $dateRange .= '–' . (string) $row['date_end'];
    }
}
?>

<style>
    .archive-detail {
        padding: 2rem 0 3rem;
    }
    .archive-detail h1 {
        font-weight: 800;
        letter-spacing: -0.02em;
        color: var(--text-primary);
    }
    .archive-detail .ref {
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        color: var(--text-secondary);
    }
    .archive-detail .card {
        border: 1px solid var(--border-color);
        background: var(--bg-primary);
    }
    .archive-detail dl.isad dt {
        color: var(--text-secondary);
        font-size: .85rem;
        font-weight: 500;
    }
    .archive-detail dl.isad dd {
        color: var(--text-primary);
        margin-bottom: .75rem;
    }
    .archive-detail .breadcrumb a {
        color: var(--text-secondary);
        text-decoration: none;
    }
    .archive-detail .breadcrumb a:hover {
        color: var(--color-primary);
    }
</style>

<main class="container archive-detail">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item">
                <a href="<?= $e(url($archiveBase)) ?>"><?= __("Archivio") ?></a>
            </li>
            <?php foreach ($breadcrumb as $crumb): ?>
                <li class="breadcrumb-item">
                    <a href="<?= $e(url($archiveBase . '/' . (int) $crumb['id'])) ?>">
                        <?= $e($crumb['title']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
            <li class="breadcrumb-item active" aria-current="page">
                <?= $e((string) $row['constructed_title']) ?>
            </li>
        </ol>
    </nav>

    <header class="mb-4">
        <div class="d-flex align-items-center gap-2 mb-2">
            <span class="badge <?= $e($levelBadgeClass[$level] ?? 'text-bg-secondary') ?>">
                <?= $e($levelLabel[$level] ?? $level) ?>
            </span>
            <span class="ref small"><?= $e((string) $row['reference_code']) ?></span>
        </div>
        <h1 class="mb-1"><?= $e((string) $row['constructed_title']) ?></h1>
        <?php if (!empty($row['formal_title']) && $row['formal_title'] !== $row['constructed_title']): ?>
            <p class="text-body-secondary fst-italic mb-1"><?= $e((string) $row['formal_title']) ?></p>
        <?php endif; ?>
        <?php if ($dateRange !== ''): ?>
            <p class="text-muted small"><?= $e($dateRange) ?></p>
        <?php endif; ?>
    </header>

    <div class="row g-4">
        <div class="col-lg-8">
            <!-- Identity + content -->
            <div class="card rounded-3 mb-3">
                <div class="card-body">
                    <dl class="isad mb-0">
                        <?php if (!empty($row['extent'])): ?>
                            <dt><?= __("Estensione e supporto") ?></dt>
                            <dd><?= $e((string) $row['extent']) ?></dd>
                        <?php endif; ?>
                        <?php if (!empty($row['scope_content'])): ?>
                            <dt><?= __("Ambito e contenuto") ?></dt>
                            <dd class="text-pre-wrap"><?= $e((string) $row['scope_content']) ?></dd>
                        <?php endif; ?>
                        <?php if (!empty($row['specific_material']) && $row['specific_material'] !== 'text'): ?>
                            <dt><?= __("Tipo di materiale") ?></dt>
                            <dd><?= $e($materialLabels[(string) $row['specific_material']] ?? (string) $row['specific_material']) ?></dd>
                        <?php endif; ?>
                        <?php if (!empty($row['photographer'])): ?>
                            <dt><?= __("Fotografo / autore primario") ?></dt>
                            <dd><?= $e((string) $row['photographer']) ?></dd>
                        <?php endif; ?>
                        <?php if (!empty($row['language_codes'])): ?>
                            <dt><?= __("Lingua") ?></dt>
                            <dd class="ref small"><?= $e((string) $row['language_codes']) ?></dd>
                        <?php endif; ?>
                        <?php if (!empty($row['archival_history'])): ?>
                            <dt><?= __("Storia archivistica") ?></dt>
                            <dd class="text-pre-wrap"><?= $e((string) $row['archival_history']) ?></dd>
                        <?php endif; ?>
                        <?php if (!empty($row['access_conditions'])): ?>
                            <dt><?= __("Condizioni di accesso") ?></dt>
                            <dd><?= $e((string) $row['access_conditions']) ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>

            <!-- Children -->
            <?php if (!empty($children)): ?>
                <div class="card rounded-3">
                    <div class="card-header bg-body-tertiary">
                        <h2 class="h6 mb-0">
                            <?= sprintf(__("Unità discendenti (%d)"), count($children)) ?>
                        </h2>
                    </div>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($children as $child):
                            $cLevel = (string) $child['level'];
                            $cBadge = $levelBadgeClass[$cLevel] ?? 'text-bg-secondary';
                            $cDate = '';
                            if (!empty($child['date_start'])) {
                                $cDate = (string) $child['date_start'];
                                if (!empty($child['date_end']) && $child['date_end'] !== $child['date_start']) {
                                    $cDate .= '–' . (string) $child['date_end'];
                                }
                            }
                        ?>
                            <li class="list-group-item d-flex align-items-center gap-2">
                                <span class="badge <?= $e($cBadge) ?>">
                                    <?= $e($levelLabel[$cLevel] ?? $cLevel) ?>
                                </span>
                                <a class="flex-fill" href="<?= $e(url($archiveBase . '/' . (int) $child['id'])) ?>">
                                    <?= $e((string) $child['constructed_title']) ?>
                                </a>
                                <span class="ref small d-none d-md-inline"><?= $e((string) $child['reference_code']) ?></span>
                                <?php if ($cDate !== ''): ?>
                                    <span class="text-muted small"><?= $e($cDate) ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-4">
            <!-- Authorities -->
            <?php if (!empty($authorities)): ?>
                <div class="card rounded-3">
                    <div class="card-header bg-body-tertiary">
                        <h2 class="h6 mb-0"><?= __("Soggetti produttori e associati") ?></h2>
                    </div>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($authorities as $auth): ?>
                            <li class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div>
                                        <div class="fw-semibold">
                                            <?= $e((string) $auth['authorised_form']) ?>
                                        </div>
                                        <div class="small text-muted">
                                            <?= $e($typeLabel[(string) $auth['type']] ?? (string) $auth['type']) ?>
                                            <?php if (!empty($auth['dates_of_existence'])): ?>
                                                · <?= $e((string) $auth['dates_of_existence']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="badge text-bg-light">
                                        <?= $e($roleLabel[(string) $auth['role']] ?? (string) $auth['role']) ?>
                                    </span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<style>
    .text-pre-wrap { white-space: pre-line; }
</style>
