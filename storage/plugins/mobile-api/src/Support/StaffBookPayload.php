<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

/**
 * Translates the English JSON contract of POST/PUT /staff/books into the POST
 * keys the admin book form sends to LibriController::store()/update().
 *
 * Pure (no database): the controller loads the current record for an update
 * and passes it in, which keeps the mapping unit-testable. Two rules matter:
 *
 *  - Validation happens here, before delegation, so the app receives a clean
 *    422 naming the offending field instead of a flash message or a 500 from a
 *    column overflow.
 *  - update() resets every whitelisted column that is absent from the post and
 *    rebuilds the author and publisher links from the posted arrays. A PUT is
 *    therefore always sent as the COMPLETE current record with the JSON patch
 *    applied on top: an absent JSON key keeps its stored value, an explicit
 *    null clears it.
 */
final class StaffBookPayload
{
    /**
     * Scalar JSON key => [form key, max length (0 = unbounded text)].
     *
     * @var array<string, array{0:string, 1:int}>
     */
    public const TEXT_FIELDS = [
        'title'            => ['titolo', 255],
        'subtitle'         => ['sottotitolo', 255],
        'issn'             => ['issn', 20],
        'pub_date'         => ['data_pubblicazione', 50],
        'edition'          => ['edizione', 100],
        'place'            => ['luogo_pubblicazione', 255],
        'language'         => ['lingua', 255],
        'format'           => ['formato', 50],
        'description'      => ['descrizione', 0],
        'keywords'         => ['parole_chiave', 0],
        'notes'            => ['note_varie', 0],
        'series'           => ['collana', 100],
        'series_number'    => ['numero_serie', 50],
        'dewey'            => ['classificazione_dewey', 50],
        'acquisition_type' => ['tipo_acquisizione', 50],
        'inventory_base'   => ['numero_inventario', 50],
    ];

    /** Contributor JSON roles => core form prefix. */
    public const CONTRIBUTOR_ROLES = [
        'translator'  => 'traduttori',
        'illustrator' => 'illustratori',
        'editor'      => 'curatori',
        'colorist'    => 'coloristi',
    ];

    /** Contributor form prefix => libri_autori.ruolo. */
    public const PREFIX_TO_ROLE = [
        'traduttori'   => 'traduttore',
        'illustratori' => 'illustratore',
        'curatori'     => 'curatore',
        'coloristi'    => 'colorista',
    ];

    /** Columns the update() whitelist resets when absent (beyond TEXT_FIELDS). */
    private const PRESERVED_COLUMNS = [
        'isbn10', 'isbn13', 'ean', 'anno_pubblicazione', 'numero_pagine', 'prezzo',
        'data_acquisizione', 'peso', 'dimensioni', 'file_url', 'audio_url', 'copertina_url',
        'scaffale_id', 'mensola_id', 'posizione_progressiva', 'collocazione',
        'genere_id', 'sottogenere_id', 'editore_id', 'tipo_media',
    ];

    /** LibraryThing columns update() resets when that plugin is installed. */
    private const LIBRARYTHING_COLUMNS = [
        'review', 'rating', 'comment', 'private_comment', 'physical_description', 'dewey_wording',
        'lccn', 'lc_classification', 'other_call_number', 'entry_date', 'date_started', 'date_read',
        'bcid', 'barcode', 'oclc', 'work_id', 'original_languages', 'source', 'from_where',
        'lending_patron', 'lending_status', 'lending_start', 'lending_end', 'value', 'condition_lt',
    ];

    public const MAX_COPIES = 100;

    /**
     * RDA override JSON key => RdaTypes kind (Pinakes 0.9.0, module rda_types).
     * The JSON key is also the form key CataloguingExtras::save() reads.
     */
    public const RDA_FIELDS = [
        'rda_content_type' => \App\Support\RdaTypes::KIND_CONTENT,
        'rda_media_type'   => \App\Support\RdaTypes::KIND_MEDIA,
        'rda_carrier_type' => \App\Support\RdaTypes::KIND_CARRIER,
    ];

    private function __construct()
    {
    }

    /**
     * Validate the JSON body. Returns null when valid, otherwise
     * ['field' => json key, 'message' => safe message].
     *
     * @param array<string, mixed> $json
     * @return array{field:string, message:string}|null
     */
    public static function validate(array $json, bool $isCreate): ?array
    {
        if ($isCreate || array_key_exists('title', $json)) {
            $title = $json['title'] ?? null;
            if (!is_string($title) || trim($title) === '') {
                return self::fail('title', __('Il titolo del libro è obbligatorio.'));
            }
        }

        foreach (self::TEXT_FIELDS as $key => [, $max]) {
            if (!array_key_exists($key, $json) || $json[$key] === null) {
                continue;
            }
            $value = $json[$key];
            if (!is_scalar($value) || is_bool($value)) {
                return self::fail($key, __('Valore non valido.'));
            }
            if ($max > 0 && mb_strlen(trim((string) $value)) > $max) {
                return self::fail($key, sprintf(__('Valore troppo lungo (massimo %d caratteri).'), $max));
            }
        }

        foreach (['isbn10', 'isbn13', 'ean'] as $key) {
            if (!array_key_exists($key, $json) || $json[$key] === null || $json[$key] === '') {
                continue;
            }
            if (!is_scalar($json[$key]) || is_bool($json[$key])) {
                return self::fail($key, __('Valore non valido.'));
            }
            $code = self::cleanCode((string) $json[$key]);
            $valid = match ($key) {
                'isbn10' => \App\Support\IsbnFormatter::isValidIsbn10($code),
                'isbn13' => \App\Support\IsbnFormatter::isValidIsbn13($code),
                default  => preg_match('/^\d{8,14}$/', $code) === 1,
            };
            if (!$valid) {
                return self::fail($key, __('Identificativo non valido.'));
            }
        }

        foreach (['year' => [-9999, 9999], 'pages' => [1, 1000000]] as $key => [$min, $max]) {
            if (!array_key_exists($key, $json) || $json[$key] === null || $json[$key] === '') {
                continue;
            }
            $int = self::intOrNull($json[$key]);
            if ($int === null || $int < $min || $int > $max) {
                return self::fail($key, __('Valore non valido.'));
            }
        }

        if (array_key_exists('price', $json) && $json['price'] !== null && $json['price'] !== '') {
            if (!is_numeric($json['price']) || (float) $json['price'] < 0 || (float) $json['price'] >= 100000000) {
                return self::fail('price', __('Valore non valido.'));
            }
        }

        if (array_key_exists('acquisition_date', $json) && $json['acquisition_date'] !== null && $json['acquisition_date'] !== '') {
            $date = is_string($json['acquisition_date']) ? $json['acquisition_date'] : '';
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                return self::fail('acquisition_date', __('Data non valida (formato AAAA-MM-GG).'));
            }
        }

        if (array_key_exists('media_type', $json) && $json['media_type'] !== null) {
            if (!is_string($json['media_type'])
                || !array_key_exists($json['media_type'], \App\Support\MediaLabels::allTypes())) {
                return self::fail('media_type', __('Valore non valido.'));
            }
        }

        if ($isCreate && array_key_exists('copies', $json) && $json['copies'] !== null) {
            $copies = self::intOrNull($json['copies']);
            if ($copies === null || $copies < 1 || $copies > self::MAX_COPIES) {
                return self::fail('copies', __('Numero di copie non valido.'));
            }
        }

        foreach (['genre_id', 'subgenre_id', 'shelf_id', 'level_id'] as $key) {
            if (!array_key_exists($key, $json) || $json[$key] === null) {
                continue;
            }
            $id = self::intOrNull($json[$key]);
            if ($id === null || $id < 0) {
                return self::fail($key, __('Valore non valido.'));
            }
        }
        if (array_key_exists('position', $json) && $json['position'] !== null) {
            $position = self::intOrNull($json['position']);
            if ($position === null || $position < 0 || $position > 100000) {
                return self::fail('position', __('Valore non valido.'));
            }
        }

        foreach (['author_ids', 'publisher_ids'] as $key) {
            if (array_key_exists($key, $json) && self::idList($json[$key]) === null) {
                return self::fail($key, __('Valore non valido.'));
            }
        }
        foreach (['authors_new', 'publishers_new'] as $key) {
            if (array_key_exists($key, $json) && self::nameList($json[$key]) === null) {
                return self::fail($key, __('Valore non valido.'));
            }
        }
        foreach (['genre_new', 'subgenre_new'] as $key) {
            if (array_key_exists($key, $json) && $json[$key] !== null
                && (!is_string($json[$key]) || mb_strlen(trim($json[$key])) > 100)) {
                return self::fail($key, __('Valore non valido.'));
            }
        }

        if (array_key_exists('contributors', $json)) {
            if (!is_array($json['contributors'])) {
                return self::fail('contributors', __('Valore non valido.'));
            }
            foreach (array_keys(self::CONTRIBUTOR_ROLES) as $role) {
                foreach (['_ids' => true, 's_new' => false] as $suffix => $isIds) {
                    $key = $role . $suffix;
                    if (!array_key_exists($key, $json['contributors'])) {
                        continue;
                    }
                    $ok = $isIds ? self::idList($json['contributors'][$key]) !== null
                        : self::nameList($json['contributors'][$key]) !== null;
                    if (!$ok) {
                        return self::fail('contributors.' . $key, __('Valore non valido.'));
                    }
                }
            }
        }

        if (array_key_exists('cover_url', $json) && $json['cover_url'] !== null && $json['cover_url'] !== '') {
            $url = is_string($json['cover_url']) ? trim($json['cover_url']) : '';
            $isHttp = preg_match('#^https?://#i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false;
            $isLocal = str_starts_with($url, '/uploads/copertine/') && !str_contains($url, '..');
            if ((!$isHttp && !$isLocal) || strlen($url) > 2000) {
                return self::fail('cover_url', __('URL non valido.'));
            }
        }

        if (array_key_exists('scraped', $json) && $json['scraped'] !== null) {
            if (!is_array($json['scraped'])) {
                return self::fail('scraped', __('Valore non valido.'));
            }
            $bio = $json['scraped']['author_bio'] ?? null;
            if ($bio !== null && !is_string($bio)) {
                return self::fail('scraped.author_bio', __('Valore non valido.'));
            }
        }

        return null;
    }

    /**
     * Validate the 0.9.0 cataloguing keys (supplier, RDA overrides). A key of
     * a module that is switched off is not validated: like the web form,
     * the save ignores it. null or '' means "none" / "derived".
     *
     * @param array<string, mixed> $json
     * @return array{field:string, message:string}|null
     */
    public static function validateCataloguing(array $json, bool $suppliersOn, bool $rdaOn): ?array
    {
        if ($suppliersOn && array_key_exists('supplier_id', $json) && $json['supplier_id'] !== null && $json['supplier_id'] !== '') {
            $id = self::intOrNull($json['supplier_id']);
            if ($id === null || $id <= 0) {
                return self::fail('supplier_id', __('Valore non valido.'));
            }
        }
        if ($rdaOn) {
            foreach (self::RDA_FIELDS as $key => $kind) {
                if (!array_key_exists($key, $json) || $json[$key] === null || $json[$key] === '') {
                    continue;
                }
                if (\App\Support\RdaTypes::normalize($kind, $json[$key]) === null) {
                    return self::fail($key, __('Valore non valido.'));
                }
            }
        }

        return null;
    }

    /**
     * Add the cataloguing keys the client sent to a create/update post. An
     * absent key is not posted, so CataloguingExtras::save() keeps the
     * stored value; null or '' posts the empty choice, which clears it.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $json validated body
     */
    public static function applyCataloguing(array &$post, array $json, bool $suppliersOn, bool $rdaOn): void
    {
        if ($suppliersOn && array_key_exists('supplier_id', $json)) {
            $id = self::intOrNull($json['supplier_id']);
            $post['fornitore_id'] = $id !== null && $id > 0 ? (string) $id : '';
        }
        if ($rdaOn) {
            foreach (self::RDA_FIELDS as $key => $kind) {
                if (array_key_exists($key, $json)) {
                    $post[$key] = (string) (\App\Support\RdaTypes::normalize($kind, $json[$key]) ?? '');
                }
            }
        }
    }

    /**
     * Form post for LibriController::store().
     *
     * @param array<string, mixed> $json validated body
     * @return array<string, mixed>
     */
    public static function toCreatePost(array $json): array
    {
        $post = [
            'titolo'       => '',
            'copie_totali' => '1',
        ];
        self::applyScalars($post, $json);

        $copies = self::intOrNull($json['copies'] ?? null);
        $post['copie_totali'] = (string) ($copies !== null && $copies >= 1 ? min($copies, self::MAX_COPIES) : 1);

        self::applyRelations($post, $json, null);

        return $post;
    }

    /**
     * Form post for LibriController::update(): the complete current record
     * with the JSON patch on top.
     *
     * @param array<string, mixed> $json validated body
     * @param array{
     *   row: array<string, mixed>,
     *   creator_ids: list<int>,
     *   contributor_ids: array<string, list<int>>,
     *   publisher_ids: list<int>,
     *   other_series: string
     * } $current
     * @return array<string, mixed>
     */
    public static function toUpdatePost(array $json, array $current): array
    {
        $row = $current['row'];
        $post = [];

        foreach (self::TEXT_FIELDS as [$formKey]) {
            $post[$formKey] = self::stringOf($row[$formKey] ?? null);
        }
        foreach (self::PRESERVED_COLUMNS as $column) {
            $post[$column] = self::stringOf($row[$column] ?? null);
        }
        foreach (self::LIBRARYTHING_COLUMNS as $column) {
            // A NULL stays absent: update() defaults these keys to null, while a
            // posted '' would be written back as an empty string.
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $post[$column] = (string) $row[$column];
            }
        }
        // Secondary series memberships are rebuilt from this field on every
        // save; without it a PUT would silently drop them.
        $post['altre_collane'] = $current['other_series'];

        $post['autori_ids'] = array_map('strval', $current['creator_ids']);
        $post['editori_ids'] = array_map('strval', $current['publisher_ids']);

        self::applyScalars($post, $json);
        self::applyRelations($post, $json, $current);

        return $post;
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $json
     */
    private static function applyScalars(array &$post, array $json): void
    {
        foreach (self::TEXT_FIELDS as $key => [$formKey]) {
            if (array_key_exists($key, $json)) {
                $post[$formKey] = $json[$key] === null ? '' : trim((string) $json[$key]);
            }
        }
        foreach (['isbn10', 'isbn13', 'ean'] as $key) {
            if (array_key_exists($key, $json)) {
                $post[$key] = $json[$key] === null ? '' : self::cleanCode((string) $json[$key]);
            }
        }
        $numeric = [
            'year'             => 'anno_pubblicazione',
            'pages'            => 'numero_pagine',
            'price'            => 'prezzo',
            'acquisition_date' => 'data_acquisizione',
        ];
        foreach ($numeric as $key => $formKey) {
            if (array_key_exists($key, $json)) {
                $post[$formKey] = $json[$key] === null ? '' : trim((string) $json[$key]);
            }
        }
        if (array_key_exists('media_type', $json) && is_string($json['media_type'])) {
            $post['tipo_media'] = $json['media_type'];
        }
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $json
     * @param array<string, mixed>|null $current null on create
     */
    private static function applyRelations(array &$post, array $json, ?array $current): void
    {
        // Authors (principal / co-author). Each key is independent: an explicit
        // `author_ids` replaces the current links, an absent one keeps them, so
        // `{"authors_new": ["X"]}` alone adds X to the existing authors.
        if (array_key_exists('author_ids', $json)) {
            $post['autori_ids'] = array_map('strval', self::idList($json['author_ids']) ?? []);
        } elseif ($current === null) {
            $post['autori_ids'] = [];
        }
        if (array_key_exists('authors_new', $json)) {
            $post['autori_new'] = self::nameList($json['authors_new']) ?? [];
        }

        // Contributors: only sent when the client sent them. The core picker
        // flag makes the posted role lists authoritative for ALL four roles,
        // so a role the client did not mention is re-posted with its current
        // links instead of being cleared.
        if (array_key_exists('contributors', $json) && is_array($json['contributors'])) {
            $contributors = $json['contributors'];
            $post['contributors_entity_picker'] = '1';
            foreach (self::CONTRIBUTOR_ROLES as $role => $prefix) {
                $hasIds = array_key_exists($role . '_ids', $contributors);
                $hasNew = array_key_exists($role . 's_new', $contributors);
                if ($hasIds || $hasNew) {
                    $post[$prefix . '_ids'] = array_map('strval', self::idList($contributors[$role . '_ids'] ?? []) ?? []);
                    $post[$prefix . '_new'] = self::nameList($contributors[$role . 's_new'] ?? []) ?? [];
                } else {
                    $existing = $current['contributor_ids'][$prefix] ?? [];
                    $post[$prefix . '_ids'] = array_map('strval', is_array($existing) ? $existing : []);
                }
            }
        }

        // Publishers follow the same rule as authors: `publisher_ids` replaces,
        // an absent key keeps the current links, `publishers_new` only adds.
        if (array_key_exists('publisher_ids', $json)) {
            $publisherIds = self::idList($json['publisher_ids']) ?? [];
            $post['editori_ids'] = array_map('strval', $publisherIds);
            // The legacy single-publisher field would otherwise resurrect the
            // previous primary publisher when the client clears the list.
            $post['editore_id'] = $publisherIds[0] ?? '';
        }
        if (array_key_exists('publishers_new', $json)) {
            $post['editori_new'] = self::nameList($json['publishers_new']) ?? [];
        }

        if (array_key_exists('genre_id', $json)) {
            $newGenre = (string) (self::intOrNull($json['genre_id']) ?? '');
            if (!array_key_exists('subgenre_id', $json) && $newGenre !== (string) ($post['genere_id'] ?? '')) {
                // A different parent invalidates the old child.
                $post['sottogenere_id'] = '';
            }
            $post['genere_id'] = $newGenre;
        }
        if (array_key_exists('subgenre_id', $json)) {
            $post['sottogenere_id'] = (string) (self::intOrNull($json['subgenre_id']) ?? '');
        }
        if (isset($json['genre_new']) && is_string($json['genre_new']) && trim($json['genre_new']) !== '') {
            $post['genere_id'] = '';
            $post['genere_search'] = trim($json['genre_new']);
        }
        if (isset($json['subgenre_new']) && is_string($json['subgenre_new']) && trim($json['subgenre_new']) !== '') {
            $post['sottogenere_id'] = '';
            $post['sottogenere_search'] = trim($json['subgenre_new']);
        }

        if (array_key_exists('shelf_id', $json) || array_key_exists('level_id', $json) || array_key_exists('position', $json)) {
            $shelf = array_key_exists('shelf_id', $json) ? self::intOrNull($json['shelf_id']) : self::intOrNull($post['scaffale_id'] ?? null);
            $level = array_key_exists('level_id', $json) ? self::intOrNull($json['level_id']) : self::intOrNull($post['mensola_id'] ?? null);
            $position = array_key_exists('position', $json) ? self::intOrNull($json['position']) : null;
            // Re-sending the location the book already has, without a position,
            // must not move it: core treats an empty position as "next free
            // slot" and excludes the book itself, so it would jump to the end.
            $sameLevel = $current !== null
                && $shelf !== null && $level !== null
                && $shelf === self::intOrNull($post['scaffale_id'] ?? null)
                && $level === self::intOrNull($post['mensola_id'] ?? null);
            $storedPosition = self::intOrNull($post['posizione_progressiva'] ?? null);
            $post['scaffale_id'] = (string) ($shelf ?? '');
            $post['mensola_id'] = (string) ($level ?? '');
            if (!array_key_exists('position', $json) && $sameLevel && $storedPosition !== null && $storedPosition > 0) {
                $post['posizione_progressiva'] = (string) $storedPosition;
            } else {
                // 0 / absent = "next free position on that level" (computed by core).
                $post['posizione_progressiva'] = $position !== null && $position > 0 ? (string) $position : '';
            }
            $post['collocazione'] = '';
        }

        if (array_key_exists('cover_url', $json)) {
            $cover = is_string($json['cover_url']) ? trim($json['cover_url']) : '';
            $post['copertina_url'] = $cover;
            if (preg_match('#^https?://#i', $cover) === 1) {
                // Mirrors the web form: the scraped URL travels in both keys so
                // core localises it once and never twice.
                $post['scraped_cover_url'] = $cover;
            }
        }
        if (($json['remove_cover'] ?? false) === true) {
            $post['remove_cover'] = '1';
            $post['copertina_url'] = '';
        }

        $bio = $json['scraped']['author_bio'] ?? null;
        if (is_string($bio) && trim($bio) !== '') {
            $post['scraped_author_bio'] = trim($bio);
        }
    }

    public static function cleanCode(string $raw): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/', '', trim($raw)));
    }

    public static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    /**
     * @return list<int>|null null when the value is not a list of positive ids
     */
    public static function idList(mixed $value): ?array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        $ids = [];
        foreach ($value as $item) {
            $id = self::intOrNull($item);
            if ($id === null || $id <= 0) {
                return null;
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>|null null when the value is not a list of names
     */
    public static function nameList(mixed $value): ?array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        $names = [];
        foreach ($value as $item) {
            if (!is_string($item) || mb_strlen(trim($item)) > 255) {
                return null;
            }
            if (trim($item) !== '') {
                $names[] = trim($item);
            }
        }

        return $names;
    }

    private static function stringOf(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    /**
     * @return array{field:string, message:string}
     */
    private static function fail(string $field, string $message): array
    {
        return ['field' => $field, 'message' => $message];
    }
}
