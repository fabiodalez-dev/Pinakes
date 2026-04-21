<?php
/**
 * Public index — list of root-level archival_units.
 *
 * Uses the same Bootstrap 5 base classes as /catalogo and /events so
 * the archive browser feels native inside the public frontend shell.
 *
 * @var list<array<string, mixed>> $rows
 * @var int                        $total
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$levelLabel = [
    'fonds'  => __('Fondo'),
    'series' => __('Serie'),
    'file'   => __('Fascicolo'),
    'item'   => __('Unità'),
];
// Bootstrap badge colour — uses text-bg-* utilities (BS5.2+).
$levelBadgeClass = [
    'fonds'  => 'text-bg-primary',
    'series' => 'text-bg-info',
    'file'   => 'text-bg-success',
    'item'   => 'text-bg-secondary',
];
$archiveBase = \App\Support\RouteTranslator::route('archives') ?: '/archive';
?>

<style>
    .archive-hero {
        padding: 3rem 0 2rem;
    }
    .archive-hero h1 {
        font-weight: 800;
        letter-spacing: -0.02em;
        color: var(--text-primary);
    }
    .archive-hero p {
        color: var(--text-secondary);
        font-size: 1.05rem;
        max-width: 780px;
    }
    .archive-card {
        transition: box-shadow .2s ease, transform .2s ease;
        border: 1px solid var(--border-color);
        background: var(--bg-primary);
        height: 100%;
    }
    .archive-card:hover {
        box-shadow: 0 8px 24px rgba(0,0,0,.08);
        transform: translateY(-2px);
    }
    .archive-card .card-title a {
        color: var(--text-primary);
        text-decoration: none;
    }
    .archive-card .card-title a:hover {
        color: var(--color-primary);
    }
    .archive-card .ref {
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: .78rem;
        color: var(--text-secondary);
    }
</style>

<main class="container py-4">
    <section class="archive-hero">
        <h1 class="mb-2"><?= __("Archivio") ?></h1>
        <p class="mb-0">
            <?= __("Consulta i fondi archivistici e le collezioni documentarie. Ogni unità è descritta secondo lo standard ISAD(G) — navigazione gerarchica per fondo, serie, fascicolo, unità.") ?>
        </p>
    </section>

    <?php if (empty($rows)): ?>
        <div class="alert alert-warning" role="alert">
            <strong><?= __("Nessun fondo pubblicato.") ?></strong>
            <?= __("L'archivio non contiene ancora unità di primo livello.") ?>
        </div>
    <?php else: ?>
        <p class="text-muted small mb-3">
            <?= sprintf(__("%d unità archivistiche di primo livello."), $total) ?>
        </p>
        <div class="row g-3">
            <?php foreach ($rows as $row):
                $level = (string) $row['level'];
                $badge = $levelBadgeClass[$level] ?? 'text-bg-secondary';
                $detailUrl = $e(url($archiveBase . '/' . (int) $row['id']));
                $dateRange = '';
                if (!empty($row['date_start'])) {
                    $dateRange = (string) $row['date_start'];
                    if (!empty($row['date_end']) && $row['date_end'] !== $row['date_start']) {
                        $dateRange .= '–' . (string) $row['date_end'];
                    }
                }
            ?>
                <div class="col-12 col-md-6 col-lg-4">
                    <article class="card archive-card rounded-3">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge <?= $e($badge) ?>"><?= $e($levelLabel[$level] ?? $level) ?></span>
                                <span class="ref"><?= $e((string) $row['reference_code']) ?></span>
                            </div>
                            <h2 class="card-title h6 mb-1">
                                <a href="<?= $detailUrl ?>"><?= $e((string) $row['constructed_title']) ?></a>
                            </h2>
                            <?php if ($dateRange !== ''): ?>
                                <p class="text-muted small mb-2"><?= $e($dateRange) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($row['scope_content'])): ?>
                                <p class="card-text small text-body-secondary mb-2">
                                    <?= $e(mb_substr((string) $row['scope_content'], 0, 180)) ?><?= mb_strlen((string) $row['scope_content']) > 180 ? '…' : '' ?>
                                </p>
                            <?php endif; ?>
                            <?php if (!empty($row['extent'])): ?>
                                <p class="small fst-italic text-muted mb-0"><?= $e((string) $row['extent']) ?></p>
                            <?php endif; ?>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
