<?php
/**
 * Archives — create/edit form view.
 *
 * @var list<string> $levels
 * @var list<string> $specific_materials
 * @var list<string> $color_modes
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 * @var string|null $mode   'create' (default) or 'edit'
 * @var int|null $id        required when $mode === 'edit'
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$val = static fn(string $k): string => $e((string) ($values[$k] ?? ''));
$err = static fn(string $k): ?string => $errors[$k] ?? null;

$mode   = ($mode ?? 'create') === 'edit' ? 'edit' : 'create';
$editId = $mode === 'edit' ? (int) ($id ?? 0) : null;
$formAction = $mode === 'edit'
    ? url('/admin/archives/' . (int) $editId . '/edit')
    : url('/admin/archives/new');
$pageTitle = $mode === 'edit' ? __('Modifica record archivistico') : __('Nuovo record archivistico');
$submitLabel = $mode === 'edit' ? __('Salva modifiche') : __('Crea record');

$levelLabels = [
    'fonds'  => __('Fondo (archivio completo di un creatore)'),
    'series' => __('Serie (raggruppamento per funzione/forma)'),
    'file'   => __('Fascicolo (case file, volume)'),
    'item'   => __('Unità (lettera, nota, memo)'),
];
?>
<div class="p-6 max-w-4xl mx-auto">
    <div class="mb-6">
        <nav class="text-sm text-gray-500 mb-2">
            <a href="<?= $e(url('/admin/archives')) ?>" class="hover:underline"><?= __('Archivi') ?></a>
            &nbsp;&raquo;&nbsp; <?= $mode === 'edit' ? __('Modifica record') . ' #' . $e((string) $editId) : __('Nuovo record') ?>
        </nav>
        <h1 class="text-2xl font-bold text-gray-900"><?= $e($pageTitle) ?></h1>
        <p class="text-sm text-gray-600 mt-1">
            <?= __("Compila i campi ISAD(G) 3.1 (area di identificazione). Campi aggiuntivi (3.2-3.7) saranno disponibili nella vista di modifica dopo la creazione.") ?>
        </p>
    </div>

    <?php if (!empty($errors['_global'])): ?>
        <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-4 rounded">
            <p class="text-sm text-red-800"><strong><?= __("Errore:") ?></strong> <?= $e($errors['_global']) ?></p>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= $e($formAction) ?>" class="bg-white shadow rounded-lg p-6 space-y-5">
        <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">

        <!-- ── ISAD(G) Area 1 — Identity Statement ─────────────────────── -->
        <div class="border-l-4 border-indigo-400 pl-4">
            <h2 class="text-sm font-semibold text-indigo-700 uppercase tracking-wide mb-3">
                <?= __("Area di identificazione") ?>
                <span class="text-xs font-normal text-gray-500 normal-case ml-1">(ISAD(G) 3.1 — Identity Statement)</span>
            </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- reference_code -->
            <div>
                <label for="reference_code" class="form-label">
                    <?= __("Reference Code") ?> <span class="text-red-500">*</span>
                    <span class="text-xs text-gray-500 font-normal">(ISAD(G) 3.1.1)</span>
                </label>
                <input type="text" name="reference_code" id="reference_code"
                       value="<?= $val('reference_code') ?>" maxlength="64" required
                       class="form-input <?= $err('reference_code') ? 'border-red-500' : '' ?>">
                <?php if ($err('reference_code')): ?>
                    <p class="mt-1 text-xs text-red-600"><?= $e($err('reference_code')) ?></p>
                <?php endif; ?>
            </div>

            <!-- institution_code -->
            <div>
                <label for="institution_code" class="form-label">
                    <?= __("Codice istituzione") ?>
                </label>
                <input type="text" name="institution_code" id="institution_code"
                       value="<?= $val('institution_code') !== '' ? $val('institution_code') : ($mode === 'create' ? 'PINAKES' : '') ?>"
                       maxlength="16"
                       class="form-input">
            </div>
        </div>

        <!-- level -->
        <div>
            <label for="level" class="form-label">
                <?= __("Livello di descrizione") ?> <span class="text-red-500">*</span>
                <span class="text-xs text-gray-500 font-normal">(ISAD(G) 3.1.4)</span>
            </label>
            <select name="level" id="level" required
                    class="form-input <?= $err('level') ? 'border-red-500' : '' ?>">
                <option value="">— <?= __("Seleziona un livello") ?> —</option>
                <?php foreach ($levels as $lvl): ?>
                    <option value="<?= $e($lvl) ?>" <?= ($values['level'] ?? '') === $lvl ? 'selected' : '' ?>>
                        <?= $e($levelLabels[$lvl] ?? $lvl) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($err('level')): ?>
                <p class="mt-1 text-xs text-red-600"><?= $e($err('level')) ?></p>
            <?php endif; ?>
        </div>

        <!-- formal_title -->
        <div>
            <label for="formal_title" class="form-label">
                <?= __("Titolo formale") ?>
                <span class="text-xs text-gray-500 font-normal">(ISAD(G) 3.1.2 — MARC 241*a, se presente sul materiale)</span>
            </label>
            <input type="text" name="formal_title" id="formal_title"
                   value="<?= $val('formal_title') ?>" maxlength="500"
                   class="form-input">
        </div>

        <!-- constructed_title -->
        <div>
            <label for="constructed_title" class="form-label">
                <?= __("Titolo attribuito") ?> <span class="text-red-500">*</span>
                <span class="text-xs text-gray-500 font-normal">(ISAD(G) 3.1.2 — MARC 245*a, titolo dato dall'archivista)</span>
            </label>
            <input type="text" name="constructed_title" id="constructed_title"
                   value="<?= $val('constructed_title') ?>" maxlength="500" required
                   class="form-input <?= $err('constructed_title') ? 'border-red-500' : '' ?>">
            <?php if ($err('constructed_title')): ?>
                <p class="mt-1 text-xs text-red-600"><?= $e($err('constructed_title')) ?></p>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- date_start -->
            <div>
                <label for="date_start" class="form-label">
                    <?= __("Anno iniziale") ?>
                    <span class="text-xs text-gray-500 font-normal">(ISAD(G) 3.1.3)</span>
                </label>
                <input type="number" name="date_start" id="date_start"
                       value="<?= $val('date_start') ?>" min="-32768" max="32767"
                       class="form-input <?= $err('date_start') ? 'border-red-500' : '' ?>">
                <?php if ($err('date_start')): ?>
                    <p class="mt-1 text-xs text-red-600"><?= $e($err('date_start')) ?></p>
                <?php endif; ?>
            </div>

            <!-- date_end -->
            <div>
                <label for="date_end" class="form-label">
                    <?= __("Anno finale") ?>
                </label>
                <input type="number" name="date_end" id="date_end"
                       value="<?= $val('date_end') ?>" min="-32768" max="32767"
                       class="form-input <?= $err('date_end') ? 'border-red-500' : '' ?>">
                <?php if ($err('date_end')): ?>
                    <p class="mt-1 text-xs text-red-600"><?= $e($err('date_end')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- extent -->
        <div>
            <label for="extent" class="form-label">
                <?= __("Estensione e supporto") ?>
                <span class="text-xs text-gray-500 font-normal">(ISAD(G) 3.1.5 — es. "1357 scatole, 613 volumi")</span>
            </label>
            <input type="text" name="extent" id="extent"
                   value="<?= $val('extent') ?>" maxlength="500"
                   class="form-input">
        </div>

        <!-- ark_identifier -->
        <div>
            <label for="ark_identifier" class="form-label">
                <?= __("Identificatore ARK") ?>
                <span class="text-xs text-gray-500 font-normal">(es. ark:/12148/btv1b84…)</span>
            </label>
            <input type="text" name="ark_identifier" id="ark_identifier"
                   value="<?= $val('ark_identifier') ?>" maxlength="255"
                   placeholder="ark:/NAAN/name"
                   class="form-input font-mono text-sm">
            <p class="mt-1 text-xs text-gray-500">
                <?= __("Identificatore persistente ARK assegnato dall'istituzione. Usato come URI canonico in EAD3, Dublin Core e manifest IIIF.") ?>
            </p>
        </div>
        </div><!-- end Area 1 -->

        <!-- ── ISAD(G) Area 3 — Content and Structure ──────────────────── -->
        <div class="border-l-4 border-green-400 pl-4">
            <h2 class="text-sm font-semibold text-green-700 uppercase tracking-wide mb-3">
                <?= __("Contenuto e struttura") ?>
                <span class="text-xs font-normal text-gray-500 normal-case ml-1">(ISAD(G) 3.3 — Content &amp; Structure)</span>
            </h2>

        <!-- scope_content -->
        <div>
            <label for="scope_content" class="form-label">
                <?= __("Ambito e contenuto") ?>
                <span class="text-xs text-gray-500 font-normal">(ISAD(G) 3.3.1)</span>
            </label>
            <textarea name="scope_content" id="scope_content" rows="4"
                      class="form-input"><?= $val('scope_content') ?></textarea>
        </div>
        </div><!-- end Area 3 -->

        <!-- ── ISAD(G) Area 4 — Conditions of Access and Use ───────────── -->
        <div class="border-l-4 border-yellow-400 pl-4">
            <h2 class="text-sm font-semibold text-yellow-700 uppercase tracking-wide mb-3">
                <?= __("Condizioni di accesso e uso") ?>
                <span class="text-xs font-normal text-gray-500 normal-case ml-1">(ISAD(G) 3.4 — Conditions of Access &amp; Use)</span>
            </h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- language_codes -->
            <div>
                <label for="language_codes" class="form-label">
                    <?= __("Codice lingua") ?>
                    <span class="text-xs text-gray-500 font-normal">(ISAD(G) 3.4.3 — ISO 639-2, es. "ita", "eng", "dan")</span>
                </label>
                <input type="text" name="language_codes" id="language_codes"
                       value="<?= $val('language_codes') !== '' ? $val('language_codes') : ($mode === 'create' ? 'ita' : '') ?>"
                       maxlength="64"
                       class="form-input">
            </div>

            <!-- parent_id -->
            <div>
                <label for="parent_id" class="form-label">
                    <?= __("ID unità padre (gerarchia)") ?>
                </label>
                <input type="number" name="parent_id" id="parent_id"
                       value="<?= $val('parent_id') ?>" min="1"
                       class="form-input <?= $err('parent_id') ? 'border-red-500' : '' ?>">
                <p class="mt-1 text-xs text-gray-500">
                    Lasciare vuoto per un record top-level (fondo). Per serie/fascicoli/unità: l'ID dell'unità padre.
                </p>
                <?php if ($err('parent_id')): ?>
                    <p class="mt-1 text-xs text-red-600"><?= $e($err('parent_id')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- rights_statement_url -->
        <div class="mt-4">
            <label for="rights_statement_url" class="form-label">
                <?= __("Dichiarazione dei diritti (URL)") ?>
                <span class="text-xs text-gray-500 font-normal">(ISAD(G) 3.4.2 — es. <a href="https://rightsstatements.org" target="_blank" class="underline">rightsstatements.org</a>)</span>
            </label>
            <input type="url" name="rights_statement_url" id="rights_statement_url"
                   value="<?= $val('rights_statement_url') ?>" maxlength="500"
                   placeholder="https://rightsstatements.org/vocab/InC/1.0/"
                   class="form-input font-mono text-sm">
            <p class="mt-1 text-xs text-gray-500">
                <?= __("URI standard (RightsStatements.org o Creative Commons) incluso nel manifest IIIF come campo 'rights'.") ?>
            </p>
        </div>
        </div><!-- end Area 4 -->

        <!-- Phase 5 — photographic / material-type fields (all optional) -->
        <?php
        // Keep the "Materiale specifico" section open not only when a
        // non-default specific_material is selected, but also when any
        // of the correlated photo/material fields has a value — so a
        // re-render after a validation error doesn't hide data that the
        // user already typed.
        $materialExtras = ['dimensions', 'color_mode', 'photographer', 'publisher', 'collection_name', 'local_classification'];
        $materialOpen = !empty($values['specific_material']) && $values['specific_material'] !== 'text';
        foreach ($materialExtras as $mk) {
            if (!empty($values[$mk])) {
                $materialOpen = true;
                break;
            }
        }
        ?>
        <details class="border rounded-md bg-gray-50" <?= $materialOpen ? 'open' : '' ?>>
            <summary class="px-4 py-3 cursor-pointer text-sm font-medium text-gray-700">
                <?= __("Materiale specifico (foto, poster, cartoline…)") ?>
            </summary>
            <div class="p-4 space-y-4 border-t bg-white">
                <?php
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
                $colorLabels = [
                    'bw'    => __('Bianco e nero'),
                    'color' => __('Colore'),
                    'mixed' => __('Misto'),
                ];
                $specsList = $specific_materials;
                $colorsList = $color_modes;
                ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="specific_material" class="form-label">
                            <?= __("Tipo di materiale") ?>
                            <span class="text-xs text-gray-500 font-normal">(ABA billedmarc 009*g)</span>
                        </label>
                        <select name="specific_material" id="specific_material"
                                class="form-input">
                            <?php foreach ($specsList as $s): ?>
                                <option value="<?= $e($s) ?>" <?= (($values['specific_material'] ?? 'text') === $s) ? 'selected' : '' ?>>
                                    <?= $e($materialLabels[$s] ?? $s) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="color_mode" class="form-label">
                            <?= __("Modalità colore") ?>
                            <span class="text-xs text-gray-500 font-normal">(MARC 300*b)</span>
                        </label>
                        <select name="color_mode" id="color_mode"
                                class="form-input">
                            <option value="">—</option>
                            <?php foreach ($colorsList as $c): ?>
                                <option value="<?= $e($c) ?>" <?= (($values['color_mode'] ?? '') === $c) ? 'selected' : '' ?>>
                                    <?= $e($colorLabels[$c] ?? $c) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label for="dimensions" class="form-label">
                        <?= __("Dimensioni") ?>
                        <span class="text-xs text-gray-500 font-normal">(MARC 300*c — <?= __("es. \"15×10 cm\" o \"35mm\"") ?>)</span>
                    </label>
                    <input type="text" name="dimensions" id="dimensions"
                           value="<?= $val('dimensions') ?>" maxlength="100"
                           class="form-input">
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="photographer" class="form-label">
                            <?= __("Fotografo / autore primario") ?>
                            <span class="text-xs text-gray-500 font-normal">(MARC 245*e)</span>
                        </label>
                        <input type="text" name="photographer" id="photographer"
                               value="<?= $val('photographer') ?>" maxlength="255"
                               class="form-input">
                    </div>
                    <div>
                        <label for="publisher" class="form-label">
                            <?= __("Editore") ?>
                            <span class="text-xs text-gray-500 font-normal">(MARC 245*f)</span>
                        </label>
                        <input type="text" name="publisher" id="publisher"
                               value="<?= $val('publisher') ?>" maxlength="255"
                               class="form-input">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="collection_name" class="form-label">
                            <?= __("Collezione") ?>
                            <span class="text-xs text-gray-500 font-normal">(MARC 096*c)</span>
                        </label>
                        <input type="text" name="collection_name" id="collection_name"
                               value="<?= $val('collection_name') ?>" maxlength="255"
                               class="form-input">
                    </div>
                    <div>
                        <label for="local_classification" class="form-label">
                            <?= __("Classificazione locale") ?>
                            <span class="text-xs text-gray-500 font-normal">(MARC 088*a — <?= __("es. DK5") ?>)</span>
                        </label>
                        <input type="text" name="local_classification" id="local_classification"
                               value="<?= $val('local_classification') ?>" maxlength="64"
                               class="form-input">
                    </div>
                </div>

            </div>
        </details>

        <!-- version_note — outside accordion, always visible -->
        <div class="border-t pt-4 mt-2">
            <label for="version_note" class="form-label">
                <?= __("Nota di versione") ?>
                <span class="text-xs text-gray-500 font-normal">(<?= __("descrive cosa è cambiato in questa revisione del record") ?>)</span>
            </label>
            <input type="text" name="version_note" id="version_note"
                   value="<?= $val('version_note') ?>" maxlength="500"
                   placeholder="<?= $val('version_note') !== '' ? '' : __('es. Aggiornati i dati di estensione dopo inventario 2024') ?>"
                   class="form-input text-sm">
        </div>

        <!-- IIIF digital object URL — outside accordion, always visible -->
        <div class="border-t pt-4 mt-2">
            <label for="iiif_manifest_url" class="form-label">
                <?= __("URL manifest IIIF (server esterno)") ?>
                <span class="text-xs text-gray-500 font-normal">(IIIF Presentation API 3.0 — <?= __("lascia vuoto se non disponibile") ?>)</span>
            </label>
            <input type="url" name="iiif_manifest_url" id="iiif_manifest_url"
                   value="<?= $val('iiif_manifest_url') ?>" maxlength="2000"
                   placeholder="https://iiif.example.org/manifests/archive-1/manifest.json"
                   class="form-input font-mono text-sm">
            <p class="mt-1 text-xs text-gray-500">
                <?= __("Se l'istituzione ha un server IIIF (Cantaloupe, IIPImage, Loris), incolla qui l'URL del manifest. Pinakes genera comunque un manifest base da /archives/{id}/manifest.json.") ?>
            </p>
        </div>

        <div class="flex items-center justify-end space-x-3 pt-4 border-t">
            <a href="<?= $e(url('/admin/archives')) ?>"
               class="btn-secondary">
                <?= __("Annulla") ?>
            </a>
            <button type="submit"
                    class="btn-primary">
                <?= $e($submitLabel) ?>
            </button>
        </div>
    </form>
</div>
