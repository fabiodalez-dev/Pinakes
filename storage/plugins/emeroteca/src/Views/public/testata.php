<?php
/**
 * Emeroteca — public testata detail.
 *
 * Header (logo, titolo, ISSN, editore, periodicità, anni, "già"/"poi"
 * title chain, descrizione), year timeline and covers grid of the
 * fascicoli of the selected year (?anno=, default: most recent).
 *
 * @var array<string, mixed>            $testata
 * @var array<string, mixed>|null       $precedente
 * @var array<string, mixed>|null       $successiva
 * @var list<array<string, mixed>>      $years
 * @var int|null                        $selectedYear
 * @var list<array<string, mixed>>      $fascicoli
 * @var array<string, string>           $tipoLabels
 * @var array<string, string>           $periodicitaLabels
 * @var array<string, string>           $statoFascicoloLabels
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$asset = static function (string $path): string {
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }
    return url($path[0] === '/' ? $path : '/' . $path);
};

$testataId = (int) $testata['id'];
$logo = $asset((string) ($testata['logo_url'] ?? ''));

$annoInizio = $testata['anno_inizio'] !== null ? (int) $testata['anno_inizio'] : null;
$annoFine   = $testata['anno_fine'] !== null ? (int) $testata['anno_fine'] : null;
$anniLabel = '';
if ($annoInizio !== null) {
    $anniLabel = (string) $annoInizio . '–' . ($annoFine !== null ? (string) $annoFine : __('oggi'));
} elseif ($annoFine !== null) {
    $anniLabel = (string) $annoFine;
}

$statoBadgeClass = [
    'posseduto'   => 'bg-emerald-100 text-emerald-800',
    'mancante'    => 'bg-slate-100 text-slate-700',
    'danneggiato' => 'bg-amber-50 text-amber-900',
    'in_restauro' => 'bg-sky-100 text-sky-800',
    'smarrito'    => 'bg-slate-100 text-slate-700',
    'atteso'      => 'bg-gray-100 text-gray-800',
];

// ── Schema.org Periodical (dedicated branch for SEO consumers) ────────
$canonicalSelf = rtrim(\App\Support\HtmlHelper::getBaseUrl(), '/') . '/emeroteca/' . $testataId;
$temporalCoverage = null;
if ($annoInizio !== null) {
    $temporalCoverage = (string) $annoInizio . '/' . ($annoFine !== null ? (string) $annoFine : '..');
}
$schema = [
    '@context'      => 'https://schema.org',
    '@type'         => 'Periodical',
    'name'          => (string) $testata['titolo'],
    'alternateName' => (string) ($testata['sottotitolo'] ?? ''),
    'issn'          => (string) ($testata['issn'] ?? ''),
    'url'           => $canonicalSelf,
    'description'   => (string) ($testata['descrizione'] ?? ''),
    'inLanguage'    => (string) ($testata['lingua'] ?? ''),
    'temporalCoverage' => $temporalCoverage,
];
if (!empty($testata['editore_nome'])) {
    $schema['publisher'] = [
        '@type' => 'Organization',
        'name'  => (string) $testata['editore_nome'],
    ];
}
if ($logo !== '') {
    $schema['image'] = absoluteUrl($logo);
}
$schema = array_filter($schema, static fn($v) => $v !== null && $v !== '');
$emerotecaSchema = json_encode($schema, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<script type="application/ld+json"><?= $emerotecaSchema ?: '{}' ?></script>
<link rel="stylesheet" href="<?= $e(url('/plugins/emeroteca/assets/css/emeroteca.css?v=1.2.4')) ?>">

<main id="emeroteca-testata" class="container emeroteca-public">
    <nav aria-label="breadcrumb" class="mb-3 text-sm text-gray-500">
        <a href="<?= $e(url('/')) ?>"><?= __('Home') ?></a>
        <span aria-hidden="true">/</span>
        <a href="<?= $e(url('/emeroteca')) ?>"><?= __('Emeroteca') ?></a>
        <span aria-hidden="true">/</span>
        <span aria-current="page"><?= $e((string) $testata['titolo']) ?></span>
    </nav>

    <!-- Intestazione testata -->
    <section class="emeroteca-identity mb-4">
                <div>
                    <div class="emeroteca-logo-large">
                        <?php if ($logo !== ''): ?>
                            <img src="<?= $e($logo) ?>" alt="<?= $e((string) $testata['titolo']) ?>">
                        <?php else: ?>
                            <i class="fas fa-newspaper" aria-hidden="true"></i>
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2 mb-2">
                        <span class="status-badge bg-sky-100 text-sky-800">
                            <?= $e($tipoLabels[(string) $testata['tipo']] ?? (string) $testata['tipo']) ?>
                        </span>
                        <?php if (!empty($testata['issn'])): ?>
                            <span class="emeroteca-issn">ISSN <?= $e((string) $testata['issn']) ?></span>
                        <?php endif; ?>
                    </div>
                    <h1 class="text-2xl font-semibold mb-1"><?= $e((string) $testata['titolo']) ?></h1>
                    <?php if (!empty($testata['sottotitolo'])): ?>
                        <p class="text-gray-500 italic mb-2"><?= $e((string) $testata['sottotitolo']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($testata['editore_nome'])): ?>
                        <p class="emeroteca-meta-line">
                            <i class="fas fa-building mr-2" aria-hidden="true"></i><?= __('Editore:') ?>
                            <?= $e((string) $testata['editore_nome']) ?>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($testata['periodicita'])): ?>
                        <p class="emeroteca-meta-line">
                            <i class="far fa-clock mr-2" aria-hidden="true"></i><?= __('Periodicità:') ?>
                            <?= $e($periodicitaLabels[(string) $testata['periodicita']] ?? (string) $testata['periodicita']) ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($anniLabel !== ''): ?>
                        <p class="emeroteca-meta-line">
                            <i class="far fa-calendar-alt mr-2" aria-hidden="true"></i><?= __('Pubblicata:') ?>
                            <?= $e($anniLabel) ?>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($testata['genere_nome'])): ?>
                        <p class="emeroteca-meta-line">
                            <i class="fas fa-tag mr-2" aria-hidden="true"></i><?= __('Argomento:') ?>
                            <?= $e((string) $testata['genere_nome']) ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($precedente !== null): ?>
                        <p class="emeroteca-meta-line">
                            <i class="fas fa-history mr-2" aria-hidden="true"></i><?= __('Già:') ?>
                            <a href="<?= $e(url('/emeroteca/' . (int) $precedente['id'])) ?>" class="underline underline-offset-2">
                                <?= $e((string) $precedente['titolo']) ?>
                            </a>
                        </p>
                    <?php endif; ?>
                    <?php if ($successiva !== null): ?>
                        <p class="emeroteca-meta-line">
                            <i class="fas fa-arrow-right mr-2" aria-hidden="true"></i><?= __('Poi:') ?>
                            <a href="<?= $e(url('/emeroteca/' . (int) $successiva['id'])) ?>" class="underline underline-offset-2">
                                <?= $e((string) $successiva['titolo']) ?>
                            </a>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($testata['descrizione'])): ?>
                        <p class="mt-2 text-gray-500 emeroteca-pre-wrap"><?= $e((string) $testata['descrizione']) ?></p>
                    <?php endif; ?>
                </div>
    </section>

    <?php if (empty($years)): ?>
        <div class="emeroteca-notice" role="alert">
            <strong><?= __('Nessuna annata registrata.') ?></strong>
            <?= __('Questa testata non ha ancora annate o fascicoli catalogati.') ?>
        </div>
    <?php else: ?>
        <!-- Timeline anni -->
        <section class="mb-4">
            <h2 class="text-base font-semibold mb-2"><?= __('Annate') ?></h2>
            <ul class="emeroteca-timeline">
                <?php foreach ($years as $y):
                    $anno = (int) $y['anno'];
                    $isActive = $selectedYear !== null && $anno === $selectedYear;
                ?>
                    <li>
                        <a class="emeroteca-year<?= $isActive ? ' active' : '' ?>"
                           href="<?= $e(url('/emeroteca/' . $testataId . '?anno=' . $anno)) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                            <span><?= $e((string) $anno) ?></span>
                            <small><?= sprintf(__('%d fasc.'), (int) $y['num_fascicoli']) ?></small>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <!-- Griglia fascicoli dell'anno selezionato -->
        <section>
            <h2 class="text-base font-semibold mb-2">
                <?= sprintf(__('Fascicoli %d'), (int) $selectedYear) ?>
            </h2>
            <?php if (empty($fascicoli)): ?>
                <div class="emeroteca-notice" role="alert">
                    <?= __('Nessun fascicolo registrato per questa annata.') ?>
                </div>
            <?php else: ?>
                <div class="emeroteca-issues-grid">
                    <?php foreach ($fascicoli as $f):
                        $stato = (string) $f['stato'];
                        $posseduto = $stato === 'posseduto';
                        $cover = $asset((string) ($f['copertina_url'] ?? ''));
                        $issueUrl = $e(url('/emeroteca/fascicolo/' . (int) $f['id']));
                        $numeroLabel = sprintf(__('n. %s'), (string) $f['numero']);
                        if (!empty($f['data_copertina'])) {
                            $numeroLabel .= ' · ' . (string) $f['data_copertina'];
                        }
                        $badge = $statoBadgeClass[$stato] ?? 'bg-gray-100 text-gray-800';
                    ?>
                        <div>
                            <?php if ($posseduto): ?><a href="<?= $issueUrl ?>" aria-label="<?= $e($numeroLabel) ?>"><?php endif; ?>
                                <div class="emeroteca-cover-box">
                                    <?php if ($cover !== ''): ?>
                                        <img src="<?= $e($cover) ?>" alt="<?= $e($numeroLabel) ?>"
                                             loading="lazy" decoding="async">
                                    <?php elseif ($stato === 'mancante'): ?>
                                        <i class="far fa-circle-xmark" aria-hidden="true"></i>
                                        <span class="emeroteca-cover-missing"><?= __('Mancante') ?></span>
                                    <?php else: ?>
                                        <i class="far fa-newspaper" aria-hidden="true"></i>
                                    <?php endif; ?>
                                    <?php if (!$posseduto): ?>
                                        <span class="emeroteca-cover-badge status-badge <?= $e($badge) ?>">
                                            <?= $e($statoFascicoloLabels[$stato] ?? $stato) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php if ($posseduto): ?></a><?php endif; ?>
                            <p class="emeroteca-issue-caption">
                                <?php if ($posseduto): ?>
                                    <a href="<?= $issueUrl ?>"><?= $e($numeroLabel) ?></a>
                                <?php else: ?>
                                    <?= $e($numeroLabel) ?>
                                <?php endif; ?>
                                <?php if (!empty($f['volume'])): ?>
                                    <span class="text-gray-500"> · <?= $e(sprintf(__('vol. %s'), (string) $f['volume'])) ?></span>
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
