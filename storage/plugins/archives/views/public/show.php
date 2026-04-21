<?php
/**
 * Public detail — single archival_unit with hero (book-detail-like layout).
 *
 * Mirrors the visual structure of app/Views/frontend/book-detail.php:
 *   - Full-bleed hero with level badge, title, identifiers, breadcrumb
 *   - Two-column body: main (ISAD fields + children) + side (authorities)
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
$levelIcon = [
    'fonds'  => 'fa-archive',
    'series' => 'fa-folder-open',
    'file'   => 'fa-folder',
    'item'   => 'fa-file-alt',
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
$icon = $levelIcon[$level] ?? 'fa-archive';
$badge = $levelBadgeClass[$level] ?? 'text-bg-secondary';
$dateRange = '';
if (!empty($row['date_start'])) {
    $dateRange = (string) $row['date_start'];
    if (!empty($row['date_end']) && $row['date_end'] !== $row['date_start']) {
        $dateRange .= '–' . (string) $row['date_end'];
    }
}
?>

<style>
    .archive-hero {
        position: relative;
        padding: 5rem 0 7rem;
        background: linear-gradient(135deg, var(--color-primary, #0d6efd) 0%, var(--color-primary-dark, #0a58ca) 100%);
        color: #fff;
        overflow: hidden;
    }
    .archive-hero::before {
        content: '';
        position: absolute;
        inset: 0;
        background: radial-gradient(circle at 20% 20%, rgba(255,255,255,.08), transparent 50%),
                    radial-gradient(circle at 80% 80%, rgba(0,0,0,.15), transparent 50%);
        pointer-events: none;
    }
    .archive-hero .hero-content { position: relative; z-index: 2; }
    .archive-hero .icon-box {
        width: 200px; height: 200px;
        border-radius: 28px;
        background: rgba(255,255,255,.12);
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto;
        box-shadow: 0 16px 40px rgba(0,0,0,.2);
    }
    .archive-hero .icon-box i { font-size: 5rem; color: #fff; opacity: .95; }
    .archive-hero h1 {
        font-weight: 800; letter-spacing: -0.02em; line-height: 1.15;
        font-size: clamp(1.75rem, 3.5vw, 2.5rem);
        margin-bottom: 1rem;
    }
    .archive-hero .ref {
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        background: rgba(255,255,255,.15);
        padding: .25rem .6rem;
        border-radius: .4rem;
        font-size: .85rem;
    }
    .archive-hero .breadcrumb a { color: rgba(255,255,255,.85); text-decoration: none; }
    .archive-hero .breadcrumb a:hover { color: #fff; text-decoration: underline; }
    .archive-hero .breadcrumb .active { color: #fff; }
    .archive-hero .breadcrumb-item + .breadcrumb-item::before { color: rgba(255,255,255,.5); }
    .archive-body { margin-top: -4rem; position: relative; z-index: 10; padding-bottom: 4rem; }
    .archive-body .card { border: 1px solid var(--border-color); background: var(--bg-primary); box-shadow: 0 4px 16px rgba(0,0,0,.06); }
    .archive-body dl.isad dt { color: var(--text-secondary); font-size: .8rem; font-weight: 500; text-transform: uppercase; letter-spacing: .03em; margin-bottom: .25rem; }
    .archive-body dl.isad dd { color: var(--text-primary); margin-bottom: 1.25rem; line-height: 1.6; }
    .archive-body dl.isad dd.pre-wrap { white-space: pre-line; }
    .archive-body .ref-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; color: var(--text-secondary); }
    .archive-body .child-item { transition: background .15s ease; }
    .archive-body .child-item:hover { background: var(--bg-secondary); }
    .archive-body .child-item a { color: var(--text-primary); text-decoration: none; }
    .archive-body .child-item a:hover { color: var(--color-primary); }
    .archive-body .authority-item { padding: .75rem 1rem; }
    .archive-body .authority-item + .authority-item { border-top: 1px solid var(--border-light); }
</style>

<section class="archive-hero">
    <div class="container hero-content">
        <div class="row align-items-center">
            <div class="col-lg-4 text-center mb-4 mb-lg-0">
                <div class="icon-box">
                    <i class="fas <?= $e($icon) ?>"></i>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="badge <?= $e($badge) ?> fs-6 px-3 py-2">
                        <i class="fas <?= $e($icon) ?> me-1"></i><?= $e($levelLabel[$level] ?? $level) ?>
                    </span>
                    <span class="ref"><?= $e((string) $row['reference_code']) ?></span>
                </div>
                <h1><?= $e((string) $row['constructed_title']) ?></h1>
                <?php if (!empty($row['formal_title']) && $row['formal_title'] !== $row['constructed_title']): ?>
                    <p class="mb-2 opacity-90 fst-italic"><?= $e((string) $row['formal_title']) ?></p>
                <?php endif; ?>
                <?php if ($dateRange !== ''): ?>
                    <p class="mb-2 opacity-85">
                        <i class="far fa-calendar-alt me-2"></i><?= $e($dateRange) ?>
                    </p>
                <?php endif; ?>
                <?php if (!empty($row['extent'])): ?>
                    <p class="mb-0 opacity-85">
                        <i class="fas fa-box-open me-2"></i><?= $e((string) $row['extent']) ?>
                    </p>
                <?php endif; ?>

                <nav aria-label="breadcrumb" class="mt-4">
                    <ol class="breadcrumb bg-transparent p-0 mb-0">
                        <li class="breadcrumb-item">
                            <a href="<?= $e(url('/')) ?>"><?= __("Home") ?></a>
                        </li>
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
            </div>
        </div>
    </div>
</section>

<section class="archive-body">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card rounded-3 mb-4">
                    <div class="card-body p-4 p-lg-5">
                        <h2 class="h5 mb-4 text-uppercase text-muted" style="letter-spacing:.05em;">
                            <i class="fas fa-info-circle me-2"></i><?= __("Descrizione archivistica") ?>
                        </h2>
                        <dl class="isad mb-0">
                            <?php if (!empty($row['scope_content'])): ?>
                                <dt><?= __("Ambito e contenuto") ?></dt>
                                <dd class="pre-wrap"><?= $e((string) $row['scope_content']) ?></dd>
                            <?php endif; ?>
                            <?php if (!empty($row['archival_history'])): ?>
                                <dt><?= __("Storia archivistica") ?></dt>
                                <dd class="pre-wrap"><?= $e((string) $row['archival_history']) ?></dd>
                            <?php endif; ?>
                            <div class="row">
                                <?php if (!empty($row['extent'])): ?>
                                    <div class="col-sm-6">
                                        <dt><?= __("Estensione e supporto") ?></dt>
                                        <dd><?= $e((string) $row['extent']) ?></dd>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($row['specific_material']) && $row['specific_material'] !== 'text'): ?>
                                    <div class="col-sm-6">
                                        <dt><?= __("Tipo di materiale") ?></dt>
                                        <dd><?= $e($materialLabels[(string) $row['specific_material']] ?? (string) $row['specific_material']) ?></dd>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($row['photographer'])): ?>
                                    <div class="col-sm-6">
                                        <dt><?= __("Fotografo / autore primario") ?></dt>
                                        <dd><?= $e((string) $row['photographer']) ?></dd>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($row['language_codes'])): ?>
                                    <div class="col-sm-6">
                                        <dt><?= __("Lingua") ?></dt>
                                        <dd class="ref-mono"><?= $e((string) $row['language_codes']) ?></dd>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($row['access_conditions'])): ?>
                                    <div class="col-12">
                                        <dt><?= __("Condizioni di accesso") ?></dt>
                                        <dd><?= $e((string) $row['access_conditions']) ?></dd>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </dl>
                    </div>
                </div>

                <?php if (!empty($children)): ?>
                    <div class="card rounded-3">
                        <div class="card-header bg-body-tertiary">
                            <h2 class="h6 mb-0">
                                <i class="fas fa-sitemap me-2"></i>
                                <?= sprintf(__("Unità discendenti (%d)"), count($children)) ?>
                            </h2>
                        </div>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($children as $child):
                                $cLevel = (string) $child['level'];
                                $cBadge = $levelBadgeClass[$cLevel] ?? 'text-bg-secondary';
                                $cIcon = $levelIcon[$cLevel] ?? 'fa-archive';
                                $cDate = '';
                                if (!empty($child['date_start'])) {
                                    $cDate = (string) $child['date_start'];
                                    if (!empty($child['date_end']) && $child['date_end'] !== $child['date_start']) {
                                        $cDate .= '–' . (string) $child['date_end'];
                                    }
                                }
                            ?>
                                <li class="list-group-item child-item d-flex align-items-center gap-2 py-3">
                                    <span class="badge <?= $e($cBadge) ?>">
                                        <i class="fas <?= $e($cIcon) ?> me-1"></i><?= $e($levelLabel[$cLevel] ?? $cLevel) ?>
                                    </span>
                                    <a class="flex-fill fw-medium" href="<?= $e(url($archiveBase . '/' . (int) $child['id'])) ?>">
                                        <?= $e((string) $child['constructed_title']) ?>
                                    </a>
                                    <span class="ref-mono small d-none d-md-inline"><?= $e((string) $child['reference_code']) ?></span>
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
                <?php if (!empty($authorities)): ?>
                    <div class="card rounded-3 mb-4">
                        <div class="card-header bg-body-tertiary">
                            <h2 class="h6 mb-0">
                                <i class="fas fa-user-friends me-2"></i><?= __("Soggetti produttori e associati") ?>
                            </h2>
                        </div>
                        <div>
                            <?php foreach ($authorities as $auth): ?>
                                <div class="authority-item">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div class="flex-fill">
                                            <div class="fw-semibold"><?= $e((string) $auth['authorised_form']) ?></div>
                                            <div class="small text-muted">
                                                <?= $e($typeLabel[(string) $auth['type']] ?? (string) $auth['type']) ?>
                                                <?php if (!empty($auth['dates_of_existence'])): ?>
                                                    · <?= $e((string) $auth['dates_of_existence']) ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <span class="badge text-bg-light text-uppercase small">
                                            <?= $e($roleLabel[(string) $auth['role']] ?? (string) $auth['role']) ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card rounded-3">
                    <div class="card-header bg-body-tertiary">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-fingerprint me-2"></i><?= __("Identificativi") ?>
                        </h2>
                    </div>
                    <div class="card-body">
                        <dl class="isad mb-0 small">
                            <dt><?= __("Reference Code") ?></dt>
                            <dd class="ref-mono"><?= $e((string) $row['reference_code']) ?></dd>
                            <?php if (!empty($row['institution_code'])): ?>
                                <dt><?= __("Istituzione") ?></dt>
                                <dd class="ref-mono"><?= $e((string) $row['institution_code']) ?></dd>
                            <?php endif; ?>
                            <?php if (!empty($row['local_classification'])): ?>
                                <dt><?= __("Classificazione locale") ?></dt>
                                <dd class="ref-mono"><?= $e((string) $row['local_classification']) ?></dd>
                            <?php endif; ?>
                            <?php if (!empty($row['collection_name'])): ?>
                                <dt><?= __("Collezione") ?></dt>
                                <dd><?= $e((string) $row['collection_name']) ?></dd>
                            <?php endif; ?>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
