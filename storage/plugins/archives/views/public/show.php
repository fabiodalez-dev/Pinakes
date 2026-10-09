<?php
/**
 * Public detail of one archival_unit, on the book page's surface.
 *
 * Hero from the core partial resource-hero.php (breadcrumb Home › Archivio ›
 * fondo/serie… › title, kicker with the level), then the book page's body:
 * description and details on 2/3, the identifiers card on 1/3
 * (public/assets/book-detail.css). One primary action at most: the download
 * of the first document.
 *
 * @var array<string, mixed>                                 $row
 * @var list<array<string, mixed>>                           $children
 * @var list<array<string, mixed>>                           $authorities
 * @var list<array{id: int, title: string}>                  $breadcrumb
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$corePartials = dirname(__DIR__, 5) . '/app/Views/frontend/partials';

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
$unitUrl = static fn(int $id, string $title): string => url($archiveBase . '/' . slugify_text($title) . '-' . $id);
$level = (string) $row['level'];
$icon = $levelIcon[$level] ?? 'fa-archive';
$dateLabel = static function (array $r): string {
    if (empty($r['date_start'])) {
        return '';
    }
    $label = (string) $r['date_start'];
    if (!empty($r['date_end']) && $r['date_end'] !== $r['date_start']) {
        $label .= '–' . (string) $r['date_end'];
    }
    return $label;
};
$dateRange = $dateLabel($row);
$title = (string) $row['constructed_title'];
$refCode = (string) ($row['reference_code'] ?? '');

// Optional per-document assets.
$coverUrl   = !empty($row['cover_image_path']) ? url((string) $row['cover_image_path']) : '';
/** @var list<array{id:int,file_path:string,file_mime:string,original_filename:string,sort_order:int,file_size?:int|string|null}> $unit_files */
$unit_files = $unit_files ?? [];
// Visitors download through the public document route
// (/archives/{id}/documents/{fileId}), never from /uploads directly: the
// route answers 404 as soon as the unit is unpublished.
$publicDocUrl = static fn(int $fileId): string => url(\App\Plugins\Archives\ArchivesPlugin::publicDocumentPath((int) $row['id'], $fileId));
// Backwards-compat: expose first file as legacy $docUrl for schema.org etc.
$firstFile  = !empty($unit_files) ? $unit_files[0] : null;
$docPath    = $firstFile !== null ? (string) $firstFile['file_path'] : (string) ($row['document_path'] ?? '');
$docMime    = $firstFile !== null ? (string) $firstFile['file_mime'] : (string) ($row['document_mime'] ?? '');
$docName    = $firstFile !== null ? (string) $firstFile['original_filename'] : (string) ($row['document_filename'] ?? '');
$docUrl     = $docPath !== '' ? $publicDocUrl($firstFile !== null ? (int) $firstFile['id'] : 0) : '';
$docIsAudio = $docMime !== '' && str_starts_with($docMime, 'audio/');
$specific   = (string) ($row['specific_material'] ?? '');

/** The reader's word for a file: "PDF", "JPEG", "MP3" — never a MIME type. */
$fileKind = static function (string $mime, string $name): string {
    $known = [
        'application/pdf' => 'PDF', 'image/jpeg' => 'JPEG', 'image/png' => 'PNG', 'image/tiff' => 'TIFF',
        'image/gif' => 'GIF', 'image/webp' => 'WebP', 'audio/mpeg' => 'MP3', 'audio/wav' => 'WAV',
        'audio/x-wav' => 'WAV', 'audio/ogg' => 'OGG', 'audio/flac' => 'FLAC', 'video/mp4' => 'MP4',
        'application/zip' => 'ZIP', 'text/plain' => 'TXT', 'application/xml' => 'XML', 'text/xml' => 'XML',
    ];
    if (isset($known[strtolower($mime)])) {
        return $known[strtolower($mime)];
    }
    $ext = strtoupper((string) pathinfo($name, PATHINFO_EXTENSION));
    if ($ext !== '' && strlen($ext) <= 5) {
        return $ext;
    }
    return $mime !== '' && str_contains($mime, '/') ? strtoupper(substr($mime, strpos($mime, '/') + 1)) : '';
};
$bytesStr = static function (int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $val = (float) $bytes;
    while ($val >= 1024 && $i < count($units) - 1) {
        $val /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) $bytes : number_format($val, 1)) . ' ' . $units[$i];
};

// Every downloadable document in one shape: the multi-file table first, the
// legacy single document_path column as a fallback.
$downloads = [];
$fileSources = $unit_files !== []
    ? $unit_files
    : ($docPath !== '' ? [['id' => 0, 'file_path' => $docPath, 'file_mime' => $docMime, 'original_filename' => $docName]] : []);
foreach ($fileSources as $uf) {
    $ufPath = (string) $uf['file_path'];
    $ufMime = (string) $uf['file_mime'];
    // Sanitize basename fallback to prevent leaking unexpected path
    // characters into the download="" attribute (defence-in-depth).
    $ufBaseFallback = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($ufPath));
    if ($ufBaseFallback === null || $ufBaseFallback === '') {
        $ufBaseFallback = __('file');
    }
    $ufName = (string) $uf['original_filename'] !== '' ? (string) $uf['original_filename'] : $ufBaseFallback;
    // file_size may be missing from the schema: fall back to the file on
    // disk; @filesize() is guarded against missing files.
    $ufSize = '';
    if (isset($uf['file_size']) && (int) $uf['file_size'] > 0) {
        $ufSize = $bytesStr((int) $uf['file_size']);
    } elseif ($ufPath !== '' && !str_contains($ufPath, '..')) {
        $ufBytes = @filesize(dirname(__DIR__, 5) . '/public' . $ufPath);
        if ($ufBytes !== false && $ufBytes > 0) {
            $ufSize = $bytesStr((int) $ufBytes);
        }
    }
    $downloads[] = [
        'url'   => $publicDocUrl((int) $uf['id']),
        'name'  => $ufName,
        'audio' => str_starts_with($ufMime, 'audio/'),
        'facts' => implode(' · ', array_filter([$fileKind($ufMime, $ufName), $ufSize], static fn(string $v): bool => $v !== '')),
    ];
}
$hasAudio = array_filter($downloads, static fn(array $d): bool => $d['audio']) !== [];
/** The one primary action: the first document that is not a recording. */
$primaryDownload = null;
foreach ($downloads as $d) {
    if (!$d['audio']) {
        $primaryDownload = $d;
        break;
    }
}

/** Stored ISO 639 codes ("ita;eng") in the reader's language. */
$languageLabel = static function (string $codes): string {
    $parts = array_values(array_filter(array_map('trim', preg_split('/[;,\s]+/', $codes) ?: []), static fn(string $c): bool => $c !== ''));
    if (class_exists(\Locale::class)) {
        $locale = \App\Support\I18n::getLocale();
        $parts = array_map(static function (string $c) use ($locale): string {
            $label = \Locale::getDisplayLanguage($c, $locale);
            return (!is_string($label) || $label === $c) ? $c : $label;
        }, $parts);
    }
    return implode(', ', $parts);
};
/** The material label without its MARC code: "Fotografia", not "Fotografia (hf)". */
$materialText = static fn(string $key): string => (string) preg_replace('/\s*\([a-z]{2}\)$/', '', $materialLabels[$key] ?? $key);

$catalogPageStyles = true;
$bookDetailStyles = true;
?>
<link rel="stylesheet" href="<?= $e(url('/plugins/archives/assets/css/archives-public.css')) ?>">
<?php if ($hasAudio): ?>
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
// FIX F042: emit absolute URLs in JSON-LD (Schema.org consumers require absolute URIs)
if ($coverUrl !== '') {
    $schema['image'] = absoluteUrl($coverUrl);
}
if ($docUrl !== '') {
    $schema['associatedMedia'] = [
        '@type' => $docIsAudio ? 'AudioObject' : 'MediaObject',
        'contentUrl' => absoluteUrl($docUrl),
        'encodingFormat' => $docMime,
    ];
}
$schema = array_filter($schema, static fn($v) => $v !== null && $v !== '' && $v !== []);
$archiveSchema = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
?>
<script type="application/ld+json"><?= $archiveSchema ?: '{}' ?></script>

<?php
// ── Hero: the same "scheda" as a book ──────────────────────────────────────
$kicker = '<span class="book-media-type"><i class="fas ' . $e($icon) . ' mr-1" aria-hidden="true"></i>' . $e($levelLabel[$level] ?? $level) . '</span>';
if ($specific !== '' && $specific !== 'text') {
    $kicker .= '<span class="book-kicker-separator" aria-hidden="true">·</span><span>' . $e($materialText($specific)) . '</span>';
}
$byline = '';
foreach ($authorities as $auth) {
    if ((string) $auth['role'] === 'creator') {
        $byline .= '<span class="author-item">' . $e((string) $auth['authorised_form']) . '</span>';
    }
}
$facts = array_values(array_filter([$refCode, $dateRange, (string) ($row['extent'] ?? '')], static fn(string $v): bool => $v !== ''));

$resourceCover = $coverUrl;
$resourceCoverKind = 'cover';
$resourceCoverAlt = '';
$resourceKickerHtml = $kicker;
$resourceTitle = $title;
$resourceSubtitle = !empty($row['formal_title']) && $row['formal_title'] !== $row['constructed_title'] ? (string) $row['formal_title'] : '';
$resourceBylineHtml = $byline;
$resourceExtraHtml = $facts !== [] ? '<p class="resource-placement">' . $e(implode(' · ', $facts)) . '</p>' : '';
$breadcrumbItems = [['label' => __('Home'), 'href' => url('/')], ['label' => __('Archivio'), 'href' => url($archiveBase)]];
foreach ($breadcrumb as $crumb) {
    $breadcrumbItems[] = ['label' => (string) $crumb['title'], 'href' => $unitUrl((int) $crumb['id'], (string) $crumb['title'])];
}
$breadcrumbItems[] = ['label' => $title];
include $corePartials . '/resource-hero.php';

$parent = $breadcrumb !== [] ? $breadcrumb[count($breadcrumb) - 1] : null;
?>

<div id="archive-unit" class="container archive-public">
    <div class="flex flex-wrap -mx-3">
        <div class="w-full lg:w-2/3 px-3">
            <?php if (count($downloads) === 1): $d = $downloads[0]; ?>
                <div class="action-buttons resource-action-buttons archive-download">
                    <?php if ($d['audio']): ?>
                        <div class="archive-player">
                            <audio class="green-audio-player" controls preload="metadata" src="<?= $e($d['url']) ?>"></audio>
                        </div>
                    <?php else: ?>
                        <a class="ui-button btn-primary" href="<?= $e($d['url']) ?>" download="<?= $e($d['name']) ?>"><i class="fas fa-download" aria-hidden="true"></i> <?= __('Scarica documento') ?></a>
                    <?php endif; ?>
                    <p class="archive-file-note"><?= $e($d['name']) ?><?php if ($d['facts'] !== ''): ?> · <?= $e($d['facts']) ?><?php endif; ?></p>
                </div>
            <?php elseif ($primaryDownload !== null): ?>
                <div class="action-buttons resource-action-buttons">
                    <a class="ui-button btn-primary" href="<?= $e($primaryDownload['url']) ?>" download="<?= $e($primaryDownload['name']) ?>"><i class="fas fa-download" aria-hidden="true"></i> <?= __('Scarica documento') ?></a>
                </div>
            <?php endif; ?>

            <?php if (!empty($row['scope_content'])): ?>
                <div class="book-description-section">
                    <h2 class="section-title"><i class="fas fa-align-left" aria-hidden="true"></i> <?= __('Ambito e contenuto') ?></h2>
                    <div class="description-content"><p class="whitespace-pre-line"><?= $e((string) $row['scope_content']) ?></p></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($row['archival_history'])): ?>
                <div class="book-description-section">
                    <h2 class="section-title"><i class="fas fa-history" aria-hidden="true"></i> <?= __('Storia archivistica') ?></h2>
                    <div class="description-content"><p class="whitespace-pre-line"><?= $e((string) $row['archival_history']) ?></p></div>
                </div>
            <?php endif; ?>

            <div class="book-details-section">
                <h2 class="section-title"><i class="fas fa-list-ul" aria-hidden="true"></i> <?= __('Descrizione archivistica') ?></h2>
                <div class="details-grid">
                    <div class="details-column">
                        <div class="meta-item"><div class="meta-label"><?= __('Livello') ?></div><div class="meta-value"><?= $e($levelLabel[$level] ?? $level) ?></div></div>
                        <?php if ($dateRange !== ''): ?><div class="meta-item"><div class="meta-label"><?= __('Datazione') ?></div><div class="meta-value"><?= $e($dateRange) ?></div></div><?php endif; ?>
                        <?php if (!empty($row['extent'])): ?><div class="meta-item"><div class="meta-label"><?= __('Estensione e supporto') ?></div><div class="meta-value"><?= $e((string) $row['extent']) ?></div></div><?php endif; ?>
                        <?php if ($specific !== ''): ?><div class="meta-item"><div class="meta-label"><?= __('Tipo di materiale') ?></div><div class="meta-value"><?= $e($materialText($specific)) ?></div></div><?php endif; ?>
                    </div>
                    <div class="details-column">
                        <?php if (!empty($row['photographer'])): ?><div class="meta-item"><div class="meta-label"><?= __('Fotografo / autore primario') ?></div><div class="meta-value"><?= $e((string) $row['photographer']) ?></div></div><?php endif; ?>
                        <?php if (!empty($row['language_codes'])): ?><div class="meta-item"><div class="meta-label"><?= __('Lingua') ?></div><div class="meta-value"><?= $e($languageLabel((string) $row['language_codes'])) ?></div></div><?php endif; ?>
                        <?php if (!empty($row['access_conditions'])): ?><div class="meta-item"><div class="meta-label"><?= __('Condizioni di accesso') ?></div><div class="meta-value"><?= $e((string) $row['access_conditions']) ?></div></div><?php endif; ?>
                        <?php foreach ($authorities as $auth): ?>
                            <div class="meta-item">
                                <div class="meta-label"><?= $e($roleLabel[(string) $auth['role']] ?? (string) $auth['role']) ?></div>
                                <div class="meta-value"><?= $e((string) $auth['authorised_form']) ?><span class="archive-authority-facts"><?= $e($typeLabel[(string) $auth['type']] ?? (string) $auth['type']) ?><?php if (!empty($auth['dates_of_existence'])): ?> · <?= $e((string) $auth['dates_of_existence']) ?><?php endif; ?></span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php if (count($downloads) > 1): ?>
                <section class="listing-section archive-section" aria-labelledby="archive-documents-title">
                    <h2 class="listing-section-title" id="archive-documents-title"><span><?= __('Documenti scaricabili') ?></span></h2>
                    <ul class="resource-toc">
                        <?php foreach ($downloads as $d): ?>
                            <li>
                                <span class="resource-toc-main">
                                    <?php if ($d['audio']): ?>
                                        <span class="resource-toc-title"><?= $e($d['name']) ?></span>
                                        <span class="archive-player"><audio class="green-audio-player" controls preload="metadata" src="<?= $e($d['url']) ?>"></audio></span>
                                    <?php else: ?>
                                        <a class="resource-toc-title" href="<?= $e($d['url']) ?>" download="<?= $e($d['name']) ?>"><i class="fas fa-download mr-2" aria-hidden="true"></i><?= $e($d['name']) ?></a>
                                    <?php endif; ?>
                                </span>
                                <?php if ($d['facts'] !== ''): ?><span class="resource-toc-pages"><?= $e($d['facts']) ?></span><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <?php if (!empty($children)): ?>
                <section class="listing-section archive-section" aria-labelledby="archive-children-title">
                    <h2 class="listing-section-title" id="archive-children-title"><span><?= $e(sprintf(__('Unità discendenti (%d)'), count($children))) ?></span></h2>
                    <ol class="resource-toc">
                        <?php foreach ($children as $child): $cDate = $dateLabel($child); ?>
                            <li>
                                <span class="resource-toc-main">
                                    <span class="resource-toc-kind"><?= $e($levelLabel[(string) $child['level']] ?? (string) $child['level']) ?></span>
                                    <a class="resource-toc-title" href="<?= $e($unitUrl((int) $child['id'], (string) $child['constructed_title'])) ?>"><?= $e((string) $child['constructed_title']) ?></a>
                                    <span class="resource-toc-authors"><?= $e((string) $child['reference_code']) ?></span>
                                </span>
                                <?php if ($cDate !== ''): ?><span class="resource-toc-pages"><?= $e($cDate) ?></span><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
            <?php endif; ?>
        </div>

        <aside class="w-full lg:w-1/3 px-3" aria-label="<?= $e(__('Identificativi')) ?>">
            <div class="card mb-4 resource-info-card">
                <div class="card-header"><h2 class="mb-0 resource-info-title"><i class="fas fa-fingerprint mr-2" aria-hidden="true"></i><?= __('Identificativi') ?></h2></div>
                <div class="card-body">
                    <div class="meta-item"><div class="meta-label"><?= __('Reference Code') ?></div><div class="meta-value"><?= $e($refCode) ?></div></div>
                    <?php if (!empty($row['institution_code'])): ?><div class="meta-item"><div class="meta-label"><?= __('Istituzione') ?></div><div class="meta-value"><?= $e((string) $row['institution_code']) ?></div></div><?php endif; ?>
                    <?php if (!empty($row['local_classification'])): ?><div class="meta-item"><div class="meta-label"><?= __('Classificazione locale') ?></div><div class="meta-value"><?= $e((string) $row['local_classification']) ?></div></div><?php endif; ?>
                    <?php if (!empty($row['collection_name'])): ?><div class="meta-item"><div class="meta-label"><?= __('Collezione') ?></div><div class="meta-value"><?= $e((string) $row['collection_name']) ?></div></div><?php endif; ?>
                    <?php if ($parent !== null): ?><div class="meta-item"><div class="meta-label"><?= __('Fa parte di') ?></div><div class="meta-value"><a href="<?= $e($unitUrl((int) $parent['id'], (string) $parent['title'])) ?>"><?= $e((string) $parent['title']) ?></a></div></div><?php endif; ?>
                    <a class="ui-button btn-outline resource-back" href="<?= $e($parent !== null ? $unitUrl((int) $parent['id'], (string) $parent['title']) : url($archiveBase)) ?>"><i class="fas fa-arrow-left" aria-hidden="true"></i> <?= $e($parent !== null ? sprintf(__('Torna a %s'), (string) $parent['title']) : __("Torna all'archivio")) ?></a>
                </div>
            </div>
        </aside>
    </div>
</div>

<?php if ($hasAudio): ?>
<script src="<?= $e(url('/assets/vendor/green-audio-player/js/green-audio-player.min.js')) ?>"></script>
<script>
    (function() {
        var players = document.querySelectorAll('.green-audio-player');
        if (!players.length || typeof GreenAudioPlayer === 'undefined') return;
        players.forEach(function(p) { new GreenAudioPlayer(p); });
    })();
</script>
<?php endif; ?>
