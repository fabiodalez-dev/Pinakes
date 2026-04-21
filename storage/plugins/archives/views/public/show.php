<?php
/**
 * Public detail — single archival_unit with book-detail-style hero.
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

// Optional per-document assets. These columns are added by the
// 0.5.9 migration's phase-5+ addenda — views degrade gracefully
// when they're absent or empty.
$coverUrl  = !empty($row['cover_image_path']) ? url((string) $row['cover_image_path']) : '';
$docPath   = (string) ($row['document_path'] ?? '');
$docMime   = (string) ($row['document_mime'] ?? '');
$docName   = (string) ($row['document_filename'] ?? '');
$docUrl    = $docPath !== '' ? url($docPath) : '';
$isAudio   = $docMime !== '' && str_starts_with($docMime, 'audio/');
$specific  = (string) ($row['specific_material'] ?? '');
?>
<link rel="stylesheet" href="<?= $e(url('/plugins/archives/assets/css/archives-public.css')) ?>">
<?php if ($isAudio): ?>
    <link rel="stylesheet" href="<?= $e(url('/assets/vendor/green-audio-player/css/green-audio-player.min.css')) ?>">
<?php endif; ?>
<?php
// Schema.org JSON-LD. archival_units map cleanly onto `ArchiveComponent`
// (fonds/series/file) or `ArchiveOrganization`; for individual items we
// fall back to `CreativeWork` + `isPartOf` chain. `Dataset` is reserved
// for bulk/statistical material; `Book` doesn't fit archival description.
$schemaType = match ($level) {
    'fonds'  => 'ArchiveComponent',
    'series' => 'ArchiveComponent',
    'file'   => 'ArchiveComponent',
    'item'   => 'CreativeWork',
    default  => 'CreativeWork',
};
$canonicalSelf = rtrim(\App\Support\HtmlHelper::getBaseUrl(), '/')
    . $archiveBase . '/'
    . slugify_text((string) ($row['constructed_title'] ?? ''))
    . '-' . (int) $row['id'];
$schema = [
    '@context'    => 'https://schema.org',
    '@type'       => $schemaType,
    'name'        => (string) ($row['constructed_title'] ?? ''),
    'alternateName' => (string) ($row['formal_title'] ?? ''),
    'identifier'  => (string) ($row['reference_code'] ?? ''),
    'url'         => $canonicalSelf,
    'description' => (string) ($row['scope_content'] ?? ''),
    'inLanguage'  => (string) ($row['language_codes'] ?? ''),
    'temporalCoverage' => !empty($row['date_start'])
        ? ((string) $row['date_start'] . (!empty($row['date_end']) && $row['date_end'] !== $row['date_start'] ? '/' . (string) $row['date_end'] : ''))
        : null,
    'holdingArchive' => [
        '@type' => 'ArchiveOrganization',
        'identifier' => (string) ($row['institution_code'] ?? ''),
    ],
    'about' => array_map(
        static fn(array $a): array => [
            '@type' => ($a['type'] ?? '') === 'corporate' ? 'Organization' : (($a['type'] ?? '') === 'family' ? 'Organization' : 'Person'),
            'name'  => (string) ($a['authorised_form'] ?? ''),
            'description' => (string) ($a['dates_of_existence'] ?? ''),
        ],
        $authorities
    ),
];
if (!empty($breadcrumb)) {
    $schema['isPartOf'] = array_map(
        static fn(array $c): array => [
            '@type' => 'ArchiveComponent',
            'name'  => $c['title'],
            'url'   => rtrim(\App\Support\HtmlHelper::getBaseUrl(), '/') . $archiveBase . '/' . slugify_text((string) $c['title']) . '-' . (int) $c['id'],
        ],
        $breadcrumb
    );
}
if ($coverUrl !== '') {
    $schema['image'] = $coverUrl;
}
if ($docUrl !== '') {
    $schema['associatedMedia'] = [
        '@type' => $isAudio ? 'AudioObject' : 'MediaObject',
        'contentUrl' => $docUrl,
        'encodingFormat' => $docMime,
    ];
}
$schema = array_filter($schema, static fn($v) => $v !== null && $v !== '' && $v !== []);
$archiveSchema = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
?>
<script type="application/ld+json"><?= $archiveSchema ?: '{}' ?></script>

<section class="archive-hero">
    <div class="container hero-content">
        <div class="row align-items-center">
            <div class="col-lg-4 mb-4 mb-lg-0">
                <?php if ($coverUrl !== ''): ?>
                    <img class="archive-cover-large"
                         src="<?= $e($coverUrl) ?>"
                         alt="<?= $e((string) $row['constructed_title']) ?>">
                <?php else: ?>
                    <div class="icon-box">
                        <i class="fas <?= $e($icon) ?>"></i>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-lg-8">
                <div class="hero-text">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <span class="badge <?= $e($badge) ?> fs-6 px-3 py-2">
                            <i class="fas <?= $e($icon) ?> me-1"></i><?= $e($levelLabel[$level] ?? $level) ?>
                        </span>
                        <span class="ref-pill"><?= $e((string) $row['reference_code']) ?></span>
                        <?php if ($specific !== '' && $specific !== 'text'): ?>
                            <span class="badge text-bg-light border">
                                <?= $e($materialLabels[$specific] ?? $specific) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <h1><?= $e((string) $row['constructed_title']) ?></h1>
                    <?php if (!empty($row['formal_title']) && $row['formal_title'] !== $row['constructed_title']): ?>
                        <p class="meta-line fst-italic"><?= $e((string) $row['formal_title']) ?></p>
                    <?php endif; ?>
                    <?php if ($dateRange !== ''): ?>
                        <p class="meta-line">
                            <i class="far fa-calendar-alt me-2"></i><?= $e($dateRange) ?>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($row['extent'])): ?>
                        <p class="meta-line">
                            <i class="fas fa-box-open me-2"></i><?= $e((string) $row['extent']) ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($docUrl !== ''): ?>
                        <div class="archive-actions" style="justify-content:flex-start;">
                            <?php if ($isAudio): ?>
                                <div class="archive-player-wrap w-100">
                                    <audio class="green-audio-player" controls preload="metadata"
                                           src="<?= $e($docUrl) ?>"></audio>
                                </div>
                            <?php else: ?>
                                <a class="btn btn-primary" href="<?= $e($docUrl) ?>"
                                   <?php if ($docName !== ''): ?>download="<?= $e($docName) ?>"<?php else: ?>download<?php endif; ?>>
                                    <i class="fas fa-download me-2"></i><?= __("Scarica documento") ?>
                                </a>
                            <?php endif; ?>
                            <?php if ($docMime !== ''): ?>
                                <span class="text-muted small align-self-center ref-mono"><?= $e($docMime) ?></span>
                            <?php endif; ?>
                        </div>
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
                                    <a href="<?= $e(url($archiveBase . '/' . slugify_text($crumb['title']) . '-' . (int) $crumb['id'])) ?>">
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
                        <div class="card-header">
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
                                    <a class="flex-fill fw-medium" href="<?= $e(url($archiveBase . '/' . slugify_text((string) $child['constructed_title']) . '-' . (int) $child['id'])) ?>">
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
                        <div class="card-header">
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
                    <div class="card-header">
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

<?php if ($isAudio): ?>
<script src="<?= $e(url('/assets/vendor/green-audio-player/js/green-audio-player.min.js')) ?>"></script>
<script>
    (function() {
        var players = document.querySelectorAll('.green-audio-player');
        if (!players.length || typeof GreenAudioPlayer === 'undefined') return;
        players.forEach(function(p) { new GreenAudioPlayer(p); });
    })();
</script>
<?php endif; ?>
