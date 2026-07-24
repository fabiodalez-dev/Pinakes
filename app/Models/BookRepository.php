<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;

class BookRepository
{
    public function __construct(private mysqli $db)
    {
    }

    private function logDebug(string $label, array $payload): void
    {
        // SECURITY: Logging disabilitato per prevenire information disclosure
        // Usa AppLog per logging sicuro in development
        if (getenv('APP_ENV') === 'development') {
            \App\Support\Log::debug($label, $payload);
        }
    }

    public function listWithAuthors(int $limit = 100): array
    {
        $rows = [];
        // Ottimizzato: JOIN + GROUP BY invece di subquery nel SELECT
        // Filtro soft delete: esclude libri cancellati
        $sql = "SELECT l.id, l.titolo, e.nome AS editore,
                       GROUP_CONCAT(" . \App\Support\AuthorName::displaySql('a') . " ORDER BY a.nome SEPARATOR ', ') AS autori
                FROM libri l
                LEFT JOIN editori e ON l.editore_id = e.id
                LEFT JOIN libri_autori la ON l.id = la.libro_id AND la.ruolo IN ('principale','co-autore')
                LEFT JOIN autori a ON la.autore_id = a.id
                WHERE l.deleted_at IS NULL
                GROUP BY l.id, l.titolo, e.nome
                ORDER BY l.titolo ASC
                LIMIT ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Fetch a single book with its joined relations: authors, publishers
     * (ordered `editori` list — multi-publisher, issue #143), genre hierarchy
     * and series memberships. Returns null when the book does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        // Check if sottogenere_id column exists
        $hasSottogenere = $this->hasColumn('sottogenere_id');
        $seriesRepo = new SeriesRepository($this->db);
        $hasSeriesHierarchy = $seriesRepo->supportsHierarchy();

        $sql = "SELECT l.*, e.nome AS editore_nome,
                       g.nome AS genere_nome,
                       g.id AS genere_id_resolved,
                       g.parent_id AS genere_parent_id,
                       gp.nome AS radice_nome,
                       gp.id AS radice_id,
                       gpp.nome AS nonno_nome,
                       gpp.id AS nonno_id";

        // Add sottogenere fields conditionally
        if ($hasSottogenere) {
            $sql .= ", sg.nome AS sottogenere_nome";
        } else {
            $sql .= ", NULL AS sottogenere_nome";
        }

        if ($hasSeriesHierarchy) {
            $sql .= ", c.gruppo_serie, c.ciclo AS ciclo_serie, c.ordine_ciclo, c.tipo AS tipo_collana, cp.nome AS serie_padre";
        } else {
            $sql .= ", NULL AS gruppo_serie, NULL AS ciclo_serie, NULL AS ordine_ciclo, NULL AS tipo_collana, NULL AS serie_padre";
        }

        $sql .= ", p.id AS posizione_id_join,
                       m.numero_livello AS mensola_livello,
                       s.codice AS scaffale_codice,
                       s.nome   AS scaffale_nome
                FROM libri l
                LEFT JOIN editori e ON l.editore_id=e.id
                LEFT JOIN generi g ON l.genere_id=g.id
                LEFT JOIN generi gp ON g.parent_id = gp.id
                LEFT JOIN generi gpp ON gp.parent_id = gpp.id";

        // Add sottogenere join conditionally
        if ($hasSottogenere) {
            $sql .= " LEFT JOIN generi sg ON l.sottogenere_id=sg.id";
        }

        $sql .= " LEFT JOIN posizioni p ON l.posizione_id = p.id
                LEFT JOIN mensole m ON p.mensola_id = m.id
                LEFT JOIN scaffali s ON p.scaffale_id = s.id";

        if ($hasSeriesHierarchy) {
            $sql .= " LEFT JOIN collane c ON c.nome = l.collana
                      LEFT JOIN collane cp ON cp.id = c.parent_id";
        }

        $sql .= "
                WHERE l.id=? AND l.deleted_at IS NULL LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        if (!$row)
            return null;

        // Compute descrizione_plain in-memory for pre-migration rows (persisted on next save)
        if ($this->hasColumn('descrizione_plain')
            && $row['descrizione_plain'] === null
            && !empty($row['descrizione'])
        ) {
            $row['descrizione_plain'] = $this->toPlainTextDescription((string) $row['descrizione']);
        }

        // Resolve genre hierarchy for the 3-level cascade (Radice → Genere → Sottogenere)
        // Walk up the tree from genere_id to find the full ancestor chain, then map to cascade levels
        $this->resolveGenreHierarchy($row);

        // Contributors grouped by role (issue #237). 'principale' → autori;
        // illustratore/traduttore/curatore/colorista each get their own list, so
        // the edit form can pre-fill the per-role pickers. pseudonimo is carried so
        // the display can show "Pseudonimo (Nome)".
        // Whitelist ORDER BY per prevenire SQL injection.
        $hasOrdineCredito = $this->hasColumnInTable('libri_autori', 'ordine_credito');
        $orderClause = $hasOrdineCredito
            ? 'ORDER BY la.ordine_credito, a.nome'
            : 'ORDER BY a.nome';
        $stmt2 = $this->db->prepare(
            "SELECT a.id, a.nome, a.pseudonimo, la.ruolo
             FROM libri_autori la JOIN autori a ON la.autore_id=a.id
             WHERE la.libro_id=? $orderClause"
        );
        $stmt2->bind_param('i', $id);
        $stmt2->execute();
        $authorsRes = $stmt2->get_result();
        $roleToKey = [
            'principale'   => 'autori',
            'illustratore' => 'illustratori',
            'traduttore'   => 'traduttori',
            'curatore'     => 'curatori',
            'colorista'    => 'coloristi',
        ];
        foreach ($roleToKey as $key) {
            $row[$key] = [];
        }
        while ($a = $authorsRes->fetch_assoc()) {
            $ruolo = (string)($a['ruolo'] ?? 'principale');
            $key = $roleToKey[$ruolo] ?? 'autori';
            unset($a['ruolo']);
            $row[$key][] = $a;
        }

        // publishers list (issue #143). editore_id / editore_nome are kept as
        // the primary publisher; this is the full ordered set.
        $row['editori'] = [];
        if ($this->hasColumnInTable('libri_editori', 'editore_id')) {
            $hasOrdine = $this->hasColumnInTable('libri_editori', 'ordine');
            $pubOrder = $hasOrdine ? 'ORDER BY le.ordine, e.nome' : 'ORDER BY e.nome';
            $stmtPub = $this->db->prepare("SELECT e.id, e.nome FROM libri_editori le JOIN editori e ON le.editore_id=e.id WHERE le.libro_id=? $pubOrder");
            if ($stmtPub) {
                $stmtPub->bind_param('i', $id);
                $stmtPub->execute();
                $pubRes = $stmtPub->get_result();
                while ($p = $pubRes->fetch_assoc()) {
                    $row['editori'][] = $p;
                }
                $stmtPub->close();
            }
        }
        // Fallback for installs before the libri_editori backfill: surface the
        // single primary publisher as a one-element list so views can always
        // iterate $row['editori'].
        if ($row['editori'] === [] && !empty($row['editore_id']) && !empty($row['editore_nome'])) {
            $row['editori'][] = ['id' => (int) $row['editore_id'], 'nome' => (string) $row['editore_nome']];
        }

        $row['serie_appartenenze'] = $seriesRepo->getBookMemberships($id);
        // PERF-2 (review): reuse the memberships array we just fetched
        // instead of letting getOtherSeriesText fire the same query again.
        $row['altre_collane'] = $seriesRepo->getOtherSeriesText($id, $row['collana'] ?? null, $row['serie_appartenenze']);
        // CRUD-4 (review): if a principal membership exists it is THE source
        // of truth for series metadata. The earlier `LEFT JOIN collane c ON
        // c.nome = l.collana` loaded gruppo/ciclo/tipo/parent from the row
        // matching the legacy varchar — which can disagree with the
        // is_principale=1 membership after a partial rename. Override
        // unconditionally (drop the prior `empty()` guards) so memberships
        // win and the legacy varchar is treated only as a fallback when
        // there is no membership at all.
        foreach ($row['serie_appartenenze'] as $membership) {
            if ((int) ($membership['is_principale'] ?? 0) !== 1) {
                continue;
            }
            $row['collana'] = $membership['nome'] ?? ($row['collana'] ?? '');
            if (!empty($membership['numero_serie'])) {
                $row['numero_serie'] = $membership['numero_serie'];
            }
            $row['gruppo_serie'] = $membership['gruppo_serie'] ?? null;
            $row['ciclo_serie']  = $membership['ciclo'] ?? null;
            $row['ordine_ciclo'] = $membership['ordine_ciclo'] ?? null;
            $row['tipo_collana'] = $membership['tipo'] ?? null;
            $row['serie_padre']  = $membership['parent_nome'] ?? null;
            break;
        }

        // Plugin hook: Allow plugins to extend book data
        $row = \App\Support\Hooks::apply('book.data.get', $row, [$id]);

        return $row;
    }

    public function getByAuthorId(int $authorId): array
    {
        // Ottimizzato: JOIN + GROUP BY invece di subquery nel SELECT
        // Filtro soft delete: esclude libri cancellati
        $sql = "SELECT l.id, l.titolo, l.isbn10, l.isbn13, l.data_acquisizione, l.stato,
                       e.nome AS editore_nome,
                       GROUP_CONCAT(DISTINCT " . \App\Support\AuthorName::displaySql('a2') . " ORDER BY a2.nome SEPARATOR ', ') AS autori
                FROM libri l
                LEFT JOIN editori e ON l.editore_id = e.id
                INNER JOIN libri_autori la ON l.id = la.libro_id AND la.autore_id = ?
                LEFT JOIN libri_autori la2 ON l.id = la2.libro_id AND la2.ruolo IN ('principale','co-autore')
                LEFT JOIN autori a2 ON la2.autore_id = a2.id
                WHERE l.deleted_at IS NULL
                GROUP BY l.id, l.titolo, l.isbn10, l.isbn13, l.data_acquisizione, l.stato, e.nome
                ORDER BY l.titolo ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $authorId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function getByPublisherId(int $publisherId): array
    {
        // Ottimizzato: JOIN + GROUP BY invece di subquery nel SELECT
        // Filtro soft delete: esclude libri cancellati
        // issue #143: gate the junction subquery on table existence so the
        // query degrades to primary-only on pre-migration installs instead of
        // a fatal prepare()=false.
        $hasJunction = \App\Support\SchemaInfo::hasLibriEditori($this->db);
        $exists = $hasJunction
            ? " OR EXISTS (SELECT 1 FROM libri_editori le WHERE le.libro_id = l.id AND le.editore_id = ?)"
            : "";
        $sql = "SELECT l.id, l.titolo, l.isbn10, l.isbn13, l.data_acquisizione, l.stato,
                       e.nome AS editore_nome,
                       GROUP_CONCAT(" . \App\Support\AuthorName::displaySql('a') . " ORDER BY a.nome SEPARATOR ', ') AS autori
                FROM libri l
                LEFT JOIN editori e ON l.editore_id = e.id
                LEFT JOIN libri_autori la ON l.id = la.libro_id AND la.ruolo IN ('principale','co-autore')
                LEFT JOIN autori a ON la.autore_id = a.id
                WHERE (l.editore_id = ?{$exists})
                      AND l.deleted_at IS NULL
                GROUP BY l.id, l.titolo, l.isbn10, l.isbn13, l.data_acquisizione, l.stato, e.nome
                ORDER BY l.titolo ASC";
        $stmt = $this->db->prepare($sql);
        if ($hasJunction) {
            $stmt->bind_param('ii', $publisherId, $publisherId);
        } else {
            $stmt->bind_param('i', $publisherId);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Insert a book row and sync its authors and publishers (multi-publisher,
     * issue #143) from the resolved id lists, returning the new book id.
     *
     * @param array<string, mixed> $data
     */
    public function createBasic(array $data): int
    {
        $hasSottogenere = $this->hasColumn('sottogenere_id');

        $scaffale_id_val = $data['scaffale_id'] ?? null;
        if ($scaffale_id_val !== null) {
            $scaffale_id_val = (int) $scaffale_id_val;
            if ($scaffale_id_val <= 0) {
                $scaffale_id_val = null;
            }
        }

        $mensola_id_val = $data['mensola_id'] ?? null;
        if ($mensola_id_val !== null) {
            $mensola_id_val = (int) $mensola_id_val;
            if ($mensola_id_val <= 0) {
                $mensola_id_val = null;
            }
        }

        $posizione_progressiva_val = $data['posizione_progressiva'] ?? null;
        if ($posizione_progressiva_val !== null) {
            $posizione_progressiva_val = (int) $posizione_progressiva_val;
            if ($posizione_progressiva_val <= 0) {
                $posizione_progressiva_val = null;
            }
        }

        $collocazione = '';
        if (!empty($data['collocazione'])) {
            $collocazione = (string) $data['collocazione'];
        } else {
            $collocazione = $this->buildCollocazioneString($scaffale_id_val, $mensola_id_val, $posizione_progressiva_val);
        }

        $posizione_id_val = !empty($data['posizione_id']) ? (int) $data['posizione_id'] : null;

        // Normalize optional dates: store NULL when empty
        $data_acquisizione = $data['data_acquisizione'] ?? null;
        if ($data_acquisizione === '' || $data_acquisizione === null) {
            $data_acquisizione = null;
        }
        $data_pubblicazione = $data['data_pubblicazione'] ?? null;
        if ($data_pubblicazione === '') {
            $data_pubblicazione = null;
        }

        // Normalize codes to avoid unique conflicts on empty strings
        $isbn10 = trim((string) ($data['isbn10'] ?? ''));
        $isbn10 = $isbn10 === '' ? null : $isbn10;
        $isbn13 = trim((string) ($data['isbn13'] ?? ''));
        $isbn13 = $isbn13 === '' ? null : $isbn13;
        $ean = trim((string) ($data['ean'] ?? ''));
        $ean = $ean === '' ? null : $ean;

        // Scalars that may be nullable
        $peso = $data['peso'] ?? null;
        if ($peso === '' || $peso === null) {
            $peso = null;
        } else {
            $peso = (float) $peso;
        }
        $prezzo = $data['prezzo'] ?? null;
        if ($prezzo === '' || $prezzo === null) {
            $prezzo = null;
        } else {
            $prezzo = (float) $prezzo;
        }

        $genere_id_val = $data['genere_id'] ?? null;
        $sottogenere_id_val = $data['sottogenere_id'] ?? null;
        $editore_id_val = $data['editore_id'] ?? null;

        $copie_totali = isset($data['copie_totali']) ? (int) $data['copie_totali'] : 1;
        $copie_disponibili = isset($data['copie_disponibili']) ? (int) $data['copie_disponibili'] : 1;

        $tipo_acquisizione = $this->sanitizeAcquisitionType($data['tipo_acquisizione'] ?? null);
        $stato = array_key_exists('stato', $data)
            ? $this->normalizeEnumValue($data['stato'], 'stato', 'disponibile')
            : null;

        $fields = [];
        $placeholders = [];
        $typeParts = [];
        $bindParams = [];
        $addField = function (string $column, string $type, $value) use (&$fields, &$placeholders, &$typeParts, &$bindParams) {
            $fields[] = $column;
            $placeholders[] = '?';
            $typeParts[] = $type;
            $bindParams[] = $value;
        };

        $addField('titolo', 's', \App\Support\HtmlHelper::decode($data['titolo'] ?? ''));
        $addField('sottotitolo', 's', \App\Support\HtmlHelper::decode($data['sottotitolo'] ?? null));
        $addField('isbn10', 's', $isbn10);
        $addField('isbn13', 's', $isbn13);
        if ($this->hasColumn('ean')) {
            $addField('ean', 's', $ean);
        }
        $addField('genere_id', 'i', $genere_id_val);
        if ($hasSottogenere) {
            $addField('sottogenere_id', 'i', $sottogenere_id_val);
        }
        $addField('editore_id', 'i', $editore_id_val);
        $addField('data_acquisizione', 's', $data_acquisizione);
        if ($this->hasColumn('data_pubblicazione')) {
            $addField('data_pubblicazione', 's', $data_pubblicazione);
        }
        $addField('tipo_acquisizione', 's', $tipo_acquisizione);
        if ($this->hasColumn('copertina_url')) {
            $addField('copertina_url', 's', $data['copertina_url'] ?? null);
        }
        if ($this->hasColumn('descrizione')) {
            $addField('descrizione', 's', $data['descrizione'] ?? null);
        }
        if ($this->hasColumn('descrizione_plain')) {
            $raw = $data['descrizione'] ?? null;
            $addField('descrizione_plain', 's', $this->toPlainTextDescription($raw));
        }
        if ($this->hasColumn('parole_chiave')) {
            $addField('parole_chiave', 's', $data['parole_chiave'] ?? null);
        }
        if ($this->hasColumn('formato')) {
            $addField('formato', 's', $data['formato'] ?? null);
        }
        if ($this->hasColumn('tipo_media')) {
            $val = \App\Support\MediaLabels::resolveTipoMedia(
                $data['formato'] ?? null,
                $data['tipo_media'] ?? null
            );
            $addField('tipo_media', 's', $this->normalizeEnumValue($val, 'tipo_media', 'libro'));
        }
        if ($this->hasColumn('peso')) {
            $addField('peso', 'd', $peso);
        }
        if ($this->hasColumn('dimensioni')) {
            $addField('dimensioni', 's', $data['dimensioni'] ?? null);
        }
        if ($this->hasColumn('prezzo')) {
            $addField('prezzo', 'd', $prezzo);
        }
        if ($this->hasColumn('scaffale_id')) {
            $addField('scaffale_id', 'i', $scaffale_id_val);
        }
        if ($this->hasColumn('mensola_id')) {
            $addField('mensola_id', 'i', $mensola_id_val);
        }
        if ($this->hasColumn('posizione_progressiva')) {
            $addField('posizione_progressiva', 'i', $posizione_progressiva_val);
        }
        if ($this->hasColumn('copie_totali')) {
            $addField('copie_totali', 'i', $copie_totali);
        }
        if ($this->hasColumn('copie_disponibili')) {
            $addField('copie_disponibili', 'i', $copie_disponibili);
        }
        if ($this->hasColumn('numero_inventario')) {
            $addField('numero_inventario', 's', $data['numero_inventario'] ?? null);
        }
        if ($this->hasColumn('classificazione_dewey')) {
            $addField('classificazione_dewey', 's', $data['classificazione_dewey'] ?? null);
        }
        if ($this->hasColumn('collana')) {
            $addField('collana', 's', $data['collana'] ?? null);
        }
        if ($this->hasColumn('numero_serie')) {
            $addField('numero_serie', 's', $data['numero_serie'] ?? null);
        }
        if ($this->hasColumn('note_varie')) {
            $addField('note_varie', 's', $data['note_varie'] ?? null);
        }
        if ($this->hasColumn('file_url')) {
            $addField('file_url', 's', $data['file_url'] ?? null);
        }
        if ($this->hasColumn('audio_url')) {
            $addField('audio_url', 's', $data['audio_url'] ?? null);
        }
        if ($this->hasColumn('collocazione')) {
            $addField('collocazione', 's', $collocazione);
        }
        if ($this->hasColumn('posizione_id')) {
            $addField('posizione_id', 'i', $posizione_id_val);
        }
        if ($this->hasColumn('stato')) {
            $addField('stato', 's', $stato);
        }
        if ($this->hasColumn('lingua')) {
            $addField('lingua', 's', $data['lingua'] ?? null);
        }
        if ($this->hasColumn('anno_pubblicazione')) {
            $annoRaw = $data['anno_pubblicazione'] ?? null;
            $anno = filter_var($annoRaw, FILTER_VALIDATE_INT);
            $addField('anno_pubblicazione', 'i', $anno === false ? null : $anno);
        }
        if ($this->hasColumn('edizione')) {
            $addField('edizione', 's', $data['edizione'] ?? null);
        }
        if ($this->hasColumn('traduttore') && array_key_exists('traduttore', $data)) {
            $val = $data['traduttore'] ?? null;
            $val = is_string($val) ? trim($val) : null;
            $addField('traduttore', 's', $val !== null && $val !== '' ? \App\Support\AuthorNormalizer::normalize($val) : null);
        }
        if ($this->hasColumn('illustratore') && array_key_exists('illustratore', $data)) {
            $val = $data['illustratore'] ?? null;
            $val = is_string($val) ? trim($val) : null;
            $addField('illustratore', 's', $val !== null && $val !== '' ? \App\Support\AuthorNormalizer::normalize($val) : null);
        }
        if ($this->hasColumn('curatore') && array_key_exists('curatore', $data)) {
            $val = $data['curatore'] ?? null;
            $val = is_string($val) ? trim($val) : null;
            $addField('curatore', 's', $val !== null && $val !== '' ? \App\Support\AuthorNormalizer::normalize($val) : null);
        }
        if ($this->hasColumn('numero_pagine')) {
            $numPagineRaw = $data['numero_pagine'] ?? null;
            $numPagine = filter_var($numPagineRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $addField('numero_pagine', 'i', $numPagine === false ? null : $numPagine);
        }

        // LibraryThing plugin fields (28 unique - includes dewey_wording, entry_date, barcode)
        if ($this->hasColumn('review')) {
            $addField('review', 's', $data['review'] ?? null);
        }
        if ($this->hasColumn('dewey_wording')) {
            $addField('dewey_wording', 's', $data['dewey_wording'] ?? null);
        }
        if ($this->hasColumn('rating')) {
            $rating = isset($data['rating']) && $data['rating'] !== '' ? (int)$data['rating'] : null;
            // Validate CHECK constraint: rating IS NULL OR (rating >= 1 AND rating <= 5)
            // Set to NULL if out of range (don't clamp, as 0 often means "not rated")
            if ($rating !== null && ($rating < 1 || $rating > 5)) {
                $rating = null;
            }
            $addField('rating', 'i', $rating);
        }
        if ($this->hasColumn('comment')) {
            $addField('comment', 's', $data['comment'] ?? null);
        }
        if ($this->hasColumn('private_comment')) {
            $addField('private_comment', 's', $data['private_comment'] ?? null);
        }
        if ($this->hasColumn('physical_description')) {
            $addField('physical_description', 's', $data['physical_description'] ?? null);
        }
        if ($this->hasColumn('lccn')) {
            $addField('lccn', 's', $data['lccn'] ?? null);
        }
        if ($this->hasColumn('lc_classification')) {
            $addField('lc_classification', 's', $data['lc_classification'] ?? null);
        }
        if ($this->hasColumn('other_call_number')) {
            $addField('other_call_number', 's', $data['other_call_number'] ?? null);
        }
        if ($this->hasColumn('entry_date')) {
            $entry_date = isset($data['entry_date']) && $data['entry_date'] !== '' ? $data['entry_date'] : null;
            $addField('entry_date', 's', $entry_date);
        }
        if ($this->hasColumn('date_started')) {
            $date_started = isset($data['date_started']) && $data['date_started'] !== '' ? $data['date_started'] : null;
            $addField('date_started', 's', $date_started);
        }
        if ($this->hasColumn('date_read')) {
            $date_read = isset($data['date_read']) && $data['date_read'] !== '' ? $data['date_read'] : null;
            $addField('date_read', 's', $date_read);
        }
        if ($this->hasColumn('bcid')) {
            $addField('bcid', 's', $data['bcid'] ?? null);
        }
        if ($this->hasColumn('barcode')) {
            $addField('barcode', 's', $data['barcode'] ?? null);
        }
        if ($this->hasColumn('oclc')) {
            $addField('oclc', 's', $data['oclc'] ?? null);
        }
        if ($this->hasColumn('work_id')) {
            $addField('work_id', 's', $data['work_id'] ?? null);
        }
        if ($this->hasColumn('issn')) {
            $addField('issn', 's', $data['issn'] ?? null);
        }
        if ($this->hasColumn('original_languages')) {
            $addField('original_languages', 's', $data['original_languages'] ?? null);
        }
        if ($this->hasColumn('source')) {
            $addField('source', 's', $data['source'] ?? null);
        }
        if ($this->hasColumn('from_where')) {
            $addField('from_where', 's', $data['from_where'] ?? null);
        }
        if ($this->hasColumn('lending_patron')) {
            $addField('lending_patron', 's', $data['lending_patron'] ?? null);
        }
        if ($this->hasColumn('lending_status')) {
            $addField('lending_status', 's', $data['lending_status'] ?? null);
        }
        if ($this->hasColumn('lending_start')) {
            $lending_start = isset($data['lending_start']) && $data['lending_start'] !== '' ? $data['lending_start'] : null;
            $addField('lending_start', 's', $lending_start);
        }
        if ($this->hasColumn('lending_end')) {
            $lending_end = isset($data['lending_end']) && $data['lending_end'] !== '' ? $data['lending_end'] : null;
            $addField('lending_end', 's', $lending_end);
        }
        if ($this->hasColumn('value')) {
            $value = isset($data['value']) && $data['value'] !== '' ? (float)$data['value'] : null;
            $addField('value', 'd', $value);
        }
        if ($this->hasColumn('condition_lt')) {
            $addField('condition_lt', 's', $data['condition_lt'] ?? null);
        }

        $sql = 'INSERT INTO libri (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $this->db->prepare($sql);
        $bindTypes = implode('', $typeParts);

        $this->logDebug('createBasic.bind.pre', [
            'types' => $bindTypes,
            'preview' => [
                'titolo' => $data['titolo'] ?? null,
                'isbn10' => $isbn10,
                'isbn13' => $isbn13,
                'ean' => $ean,
                'genere_id' => $genere_id_val,
                'sottogenere_id' => $sottogenere_id_val,
                'editore_id' => $editore_id_val,
                'tipo_acquisizione' => $tipo_acquisizione,
                'stato' => $stato,
                'posizione_id' => $posizione_id_val,
                'scaffale_id' => $scaffale_id_val,
                'mensola_id' => $mensola_id_val,
                'posizione_progressiva' => $posizione_progressiva_val,
            ],
            'field_count' => count($fields),
            'full_data_keys' => array_keys($data),
        ]);

        $stmt->bind_param($bindTypes, ...$bindParams);
        $savepoint = 'pinakes_create_basic';
        $insideTransaction = $this->beginWriteScope($savepoint);
        try {
            $stmt->execute();
            $this->logDebug('createBasic.execute.ok', ['insert_id' => (int) $this->db->insert_id]);

            $bookId = (int) $this->db->insert_id;
            $this->syncContributors($bookId, $data);
            $this->syncPublishers($bookId, $data['editori_ids'] ?? []);
            $this->commitWriteScope($insideTransaction, $savepoint);
            return $bookId;
        } catch (\Throwable $e) {
            $this->rollbackWriteScope($insideTransaction, $savepoint);
            $this->logDebug('createBasic.execute.error', [
                'error' => $e->getMessage(),
                'code' => (int) $e->getCode(),
                'mysqli_error' => $stmt->error,
            ]);
            throw $e;
        }
    }

    /**
     * Update a book row and re-sync its authors and publishers (multi-publisher,
     * issue #143) from the resolved id lists.
     *
     * @param array<string, mixed> $data
     */
    public function updateBasic(int $id, array $data): bool
    {
        $hasSottogenere = $this->hasColumn('sottogenere_id');

        $scaffale_id_val = $data['scaffale_id'] ?? null;
        if ($scaffale_id_val !== null) {
            $scaffale_id_val = (int) $scaffale_id_val;
            if ($scaffale_id_val <= 0) {
                $scaffale_id_val = null;
            }
        }

        $mensola_id_val = $data['mensola_id'] ?? null;
        if ($mensola_id_val !== null) {
            $mensola_id_val = (int) $mensola_id_val;
            if ($mensola_id_val <= 0) {
                $mensola_id_val = null;
            }
        }

        $posizione_progressiva_val = $data['posizione_progressiva'] ?? null;
        if ($posizione_progressiva_val !== null) {
            $posizione_progressiva_val = (int) $posizione_progressiva_val;
            if ($posizione_progressiva_val <= 0) {
                $posizione_progressiva_val = null;
            }
        }

        $collocazione = '';
        if (!empty($data['collocazione'])) {
            $collocazione = (string) $data['collocazione'];
        } else {
            $collocazione = $this->buildCollocazioneString($scaffale_id_val, $mensola_id_val, $posizione_progressiva_val);
        }

        $posizione_id_val = !empty($data['posizione_id']) ? (int) $data['posizione_id'] : null;

        $data_acquisizione = $data['data_acquisizione'] ?? null;
        if ($data_acquisizione === '' || $data_acquisizione === null) {
            $data_acquisizione = null;
        }
        $data_pubblicazione = $data['data_pubblicazione'] ?? null;
        if ($data_pubblicazione === '') {
            $data_pubblicazione = null;
        }

        $isbn10_upd = trim((string) ($data['isbn10'] ?? ''));
        $isbn10_upd = $isbn10_upd === '' ? null : $isbn10_upd;
        $isbn13_upd = trim((string) ($data['isbn13'] ?? ''));
        $isbn13_upd = $isbn13_upd === '' ? null : $isbn13_upd;
        $ean = trim((string) ($data['ean'] ?? ''));
        $ean = $ean === '' ? null : $ean;

        $peso = $data['peso'] ?? null;
        if ($peso === '' || $peso === null) {
            $peso = null;
        } else {
            $peso = (float) $peso;
        }
        $prezzo = $data['prezzo'] ?? null;
        if ($prezzo === '' || $prezzo === null) {
            $prezzo = null;
        } else {
            $prezzo = (float) $prezzo;
        }

        $genere_id_val = $data['genere_id'] ?? null;
        $sottogenere_id_val = $data['sottogenere_id'] ?? null;
        $editore_id_val = $data['editore_id'] ?? null;

        $copie_totali = isset($data['copie_totali']) ? (int) $data['copie_totali'] : 1;
        $copie_disponibili = isset($data['copie_disponibili']) ? (int) $data['copie_disponibili'] : 1;

        $tipo_acquisizione = $this->sanitizeAcquisitionType($data['tipo_acquisizione'] ?? null);
        $stato = $this->normalizeEnumValue($data['stato'] ?? null, 'stato', 'disponibile');

        $setParts = [];
        $typeParts = [];
        $bindParams = [];
        $addSet = function (string $column, string $type, $value) use (&$setParts, &$typeParts, &$bindParams) {
            $setParts[] = "$column=?";
            $typeParts[] = $type;
            $bindParams[] = $value;
        };

        $addSet('titolo', 's', \App\Support\HtmlHelper::decode($data['titolo'] ?? ''));
        $addSet('sottotitolo', 's', \App\Support\HtmlHelper::decode($data['sottotitolo'] ?? null));
        $addSet('isbn10', 's', $isbn10_upd);
        $addSet('isbn13', 's', $isbn13_upd);
        if ($this->hasColumn('ean')) {
            $addSet('ean', 's', $ean);
        }
        $addSet('genere_id', 'i', $genere_id_val);
        if ($hasSottogenere) {
            $addSet('sottogenere_id', 'i', $sottogenere_id_val);
        }
        $addSet('editore_id', 'i', $editore_id_val);
        $addSet('data_acquisizione', 's', $data_acquisizione);
        if ($this->hasColumn('data_pubblicazione')) {
            $addSet('data_pubblicazione', 's', $data_pubblicazione);
        }
        $addSet('tipo_acquisizione', 's', $tipo_acquisizione);
        if ($this->hasColumn('copertina_url')) {
            $addSet('copertina_url', 's', $data['copertina_url'] ?? null);
        }
        if ($this->hasColumn('descrizione')) {
            $addSet('descrizione', 's', $data['descrizione'] ?? null);
        }
        if ($this->hasColumn('descrizione_plain')) {
            $raw = $data['descrizione'] ?? null;
            $addSet('descrizione_plain', 's', $this->toPlainTextDescription($raw));
        }
        if ($this->hasColumn('parole_chiave')) {
            $addSet('parole_chiave', 's', $data['parole_chiave'] ?? null);
        }
        if ($this->hasColumn('formato')) {
            $addSet('formato', 's', $data['formato'] ?? null);
        }
        if ($this->hasColumn('tipo_media') && array_key_exists('tipo_media', $data) && is_string($data['tipo_media'])) {
            $addSet('tipo_media', 's', $this->normalizeEnumValue($data['tipo_media'], 'tipo_media', 'libro'));
        }
        if ($this->hasColumn('peso')) {
            $addSet('peso', 'd', $peso);
        }
        if ($this->hasColumn('dimensioni')) {
            $addSet('dimensioni', 's', $data['dimensioni'] ?? null);
        }
        if ($this->hasColumn('prezzo')) {
            $addSet('prezzo', 'd', $prezzo);
        }
        if ($this->hasColumn('scaffale_id')) {
            $addSet('scaffale_id', 'i', $scaffale_id_val);
        }
        if ($this->hasColumn('mensola_id')) {
            $addSet('mensola_id', 'i', $mensola_id_val);
        }
        if ($this->hasColumn('posizione_progressiva')) {
            $addSet('posizione_progressiva', 'i', $posizione_progressiva_val);
        }
        if ($this->hasColumn('copie_totali')) {
            $addSet('copie_totali', 'i', $copie_totali);
        }
        if ($this->hasColumn('copie_disponibili')) {
            $addSet('copie_disponibili', 'i', $copie_disponibili);
        }
        if ($this->hasColumn('numero_inventario')) {
            $addSet('numero_inventario', 's', $data['numero_inventario'] ?? null);
        }
        if ($this->hasColumn('classificazione_dewey')) {
            $addSet('classificazione_dewey', 's', $data['classificazione_dewey'] ?? null);
        }
        if ($this->hasColumn('collana')) {
            $addSet('collana', 's', $data['collana'] ?? null);
        }
        if ($this->hasColumn('numero_serie')) {
            $addSet('numero_serie', 's', $data['numero_serie'] ?? null);
        }
        if ($this->hasColumn('note_varie')) {
            $addSet('note_varie', 's', $data['note_varie'] ?? null);
        }
        if ($this->hasColumn('file_url')) {
            $addSet('file_url', 's', $data['file_url'] ?? null);
        }
        if ($this->hasColumn('audio_url')) {
            $addSet('audio_url', 's', $data['audio_url'] ?? null);
        }
        if ($this->hasColumn('collocazione')) {
            $addSet('collocazione', 's', $collocazione);
        }
        if ($this->hasColumn('posizione_id')) {
            $addSet('posizione_id', 'i', $posizione_id_val);
        }
        if ($this->hasColumn('stato') && array_key_exists('stato', $data)) {
            $addSet('stato', 's', $stato);
        }
        if ($this->hasColumn('lingua')) {
            $addSet('lingua', 's', $data['lingua'] ?? null);
        }
        if ($this->hasColumn('anno_pubblicazione')) {
            $annoRaw = $data['anno_pubblicazione'] ?? null;
            $anno = filter_var($annoRaw, FILTER_VALIDATE_INT);
            $addSet('anno_pubblicazione', 'i', $anno === false ? null : $anno);
        }
        if ($this->hasColumn('edizione')) {
            $addSet('edizione', 's', $data['edizione'] ?? null);
        }
        if ($this->hasColumn('traduttore') && array_key_exists('traduttore', $data)) {
            $val = $data['traduttore'] ?? null;
            $val = is_string($val) ? trim($val) : null;
            $addSet('traduttore', 's', $val !== null && $val !== '' ? \App\Support\AuthorNormalizer::normalize($val) : null);
        }
        if ($this->hasColumn('illustratore') && array_key_exists('illustratore', $data)) {
            $val = $data['illustratore'] ?? null;
            $val = is_string($val) ? trim($val) : null;
            $addSet('illustratore', 's', $val !== null && $val !== '' ? \App\Support\AuthorNormalizer::normalize($val) : null);
        }
        if ($this->hasColumn('curatore') && array_key_exists('curatore', $data)) {
            $val = $data['curatore'] ?? null;
            $val = is_string($val) ? trim($val) : null;
            $addSet('curatore', 's', $val !== null && $val !== '' ? \App\Support\AuthorNormalizer::normalize($val) : null);
        }
        if ($this->hasColumn('numero_pagine')) {
            $numPagineRaw = $data['numero_pagine'] ?? null;
            $numPagine = filter_var($numPagineRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $addSet('numero_pagine', 'i', $numPagine === false ? null : $numPagine);
        }

        // LibraryThing plugin fields (28 unique - includes dewey_wording, entry_date, barcode)
        if ($this->hasColumn('review')) {
            $addSet('review', 's', $data['review'] ?? null);
        }
        if ($this->hasColumn('dewey_wording')) {
            $addSet('dewey_wording', 's', $data['dewey_wording'] ?? null);
        }
        if ($this->hasColumn('rating')) {
            $rating = isset($data['rating']) && $data['rating'] !== '' ? (int)$data['rating'] : null;
            // Validate CHECK constraint: rating IS NULL OR (rating >= 1 AND rating <= 5)
            // Set to NULL if out of range (don't clamp, as 0 often means "not rated")
            if ($rating !== null && ($rating < 1 || $rating > 5)) {
                $rating = null;
            }
            $addSet('rating', 'i', $rating);
        }
        if ($this->hasColumn('comment')) {
            $addSet('comment', 's', $data['comment'] ?? null);
        }
        if ($this->hasColumn('private_comment')) {
            $addSet('private_comment', 's', $data['private_comment'] ?? null);
        }
        if ($this->hasColumn('physical_description')) {
            $addSet('physical_description', 's', $data['physical_description'] ?? null);
        }
        if ($this->hasColumn('lccn')) {
            $addSet('lccn', 's', $data['lccn'] ?? null);
        }
        if ($this->hasColumn('lc_classification')) {
            $addSet('lc_classification', 's', $data['lc_classification'] ?? null);
        }
        if ($this->hasColumn('other_call_number')) {
            $addSet('other_call_number', 's', $data['other_call_number'] ?? null);
        }
        if ($this->hasColumn('entry_date')) {
            $entry_date = isset($data['entry_date']) && $data['entry_date'] !== '' ? $data['entry_date'] : null;
            $addSet('entry_date', 's', $entry_date);
        }
        if ($this->hasColumn('date_started')) {
            $date_started = isset($data['date_started']) && $data['date_started'] !== '' ? $data['date_started'] : null;
            $addSet('date_started', 's', $date_started);
        }
        if ($this->hasColumn('date_read')) {
            $date_read = isset($data['date_read']) && $data['date_read'] !== '' ? $data['date_read'] : null;
            $addSet('date_read', 's', $date_read);
        }
        if ($this->hasColumn('bcid')) {
            $addSet('bcid', 's', $data['bcid'] ?? null);
        }
        if ($this->hasColumn('barcode')) {
            $addSet('barcode', 's', $data['barcode'] ?? null);
        }
        if ($this->hasColumn('oclc')) {
            $addSet('oclc', 's', $data['oclc'] ?? null);
        }
        if ($this->hasColumn('work_id')) {
            $addSet('work_id', 's', $data['work_id'] ?? null);
        }
        if ($this->hasColumn('issn')) {
            $addSet('issn', 's', $data['issn'] ?? null);
        }
        if ($this->hasColumn('original_languages')) {
            $addSet('original_languages', 's', $data['original_languages'] ?? null);
        }
        if ($this->hasColumn('source')) {
            $addSet('source', 's', $data['source'] ?? null);
        }
        if ($this->hasColumn('from_where')) {
            $addSet('from_where', 's', $data['from_where'] ?? null);
        }
        if ($this->hasColumn('lending_patron')) {
            $addSet('lending_patron', 's', $data['lending_patron'] ?? null);
        }
        if ($this->hasColumn('lending_status')) {
            $addSet('lending_status', 's', $data['lending_status'] ?? null);
        }
        if ($this->hasColumn('lending_start')) {
            $lending_start = isset($data['lending_start']) && $data['lending_start'] !== '' ? $data['lending_start'] : null;
            $addSet('lending_start', 's', $lending_start);
        }
        if ($this->hasColumn('lending_end')) {
            $lending_end = isset($data['lending_end']) && $data['lending_end'] !== '' ? $data['lending_end'] : null;
            $addSet('lending_end', 's', $lending_end);
        }
        if ($this->hasColumn('value')) {
            $value = isset($data['value']) && $data['value'] !== '' ? (float)$data['value'] : null;
            $addSet('value', 'd', $value);
        }
        if ($this->hasColumn('condition_lt')) {
            $addSet('condition_lt', 's', $data['condition_lt'] ?? null);
        }

        $sql = 'UPDATE libri SET ' . implode(', ', $setParts) . ', updated_at=NOW() WHERE id=? AND deleted_at IS NULL';
        $stmt = $this->db->prepare($sql);

        $bindTypes = implode('', $typeParts) . 'i';
        $bindParams[] = $id;

        $this->logDebug('updateBasic.bind.pre', [
            'types' => $bindTypes,
            'id' => $id,
            'preview' => [
                'titolo' => $data['titolo'] ?? null,
                'tipo_acquisizione' => $tipo_acquisizione,
                'stato' => $stato,
                'posizione_id' => $posizione_id_val,
                'scaffale_id' => $scaffale_id_val,
                'mensola_id' => $mensola_id_val,
                'posizione_progressiva' => $posizione_progressiva_val,
            ],
            'field_count' => count($setParts),
            'full_data_keys' => array_keys($data),
        ]);

        $stmt->bind_param($bindTypes, ...$bindParams);
        $savepoint = 'pinakes_update_basic';
        $insideTransaction = $this->beginWriteScope($savepoint);
        try {
            $ok = $stmt->execute();
            $this->logDebug('updateBasic.execute.ok', ['id' => $id, 'ok' => $ok]);

            $this->syncContributors($id, $data);
            $this->syncPublishers($id, $data['editori_ids'] ?? []);
            $this->commitWriteScope($insideTransaction, $savepoint);
            return $ok;
        } catch (\Throwable $e) {
            $this->rollbackWriteScope($insideTransaction, $savepoint);
            $this->logDebug('updateBasic.execute.error', [
                'error' => $e->getMessage(),
                'code' => (int) $e->getCode(),
                'mysqli_error' => $stmt->error,
            ]);
            throw $e;
        }
    }

    /**
     * Start a transaction owned by this repository call, or a savepoint when
     * the caller already owns one. This keeps the libri row, contributor links,
     * legacy compatibility cache and publisher links in one atomic write.
     */
    private function beginWriteScope(string $savepoint): bool
    {
        $insideTransaction = false;
        $probe = 'pinakes_write_probe';
        try {
            if ($this->db->query("SAVEPOINT {$probe}")
                && $this->db->query("ROLLBACK TO SAVEPOINT {$probe}")
            ) {
                $insideTransaction = true;
                $this->db->query("RELEASE SAVEPOINT {$probe}");
            }
        } catch (\mysqli_sql_exception) {
            $insideTransaction = false;
        }

        if ($insideTransaction) {
            if (!$this->db->query("SAVEPOINT {$savepoint}")) {
                throw new \RuntimeException('Database error: unable to create book write savepoint');
            }
        } elseif (!$this->db->begin_transaction()) {
            throw new \RuntimeException('Database error: unable to begin book write transaction');
        }

        return $insideTransaction;
    }

    private function commitWriteScope(bool $insideTransaction, string $savepoint): void
    {
        if ($insideTransaction) {
            if (!$this->db->query("RELEASE SAVEPOINT {$savepoint}")) {
                throw new \RuntimeException('Database error: unable to release book write savepoint');
            }
        } elseif (!$this->db->commit()) {
            throw new \RuntimeException('Database error: unable to commit book write transaction');
        }
    }

    private function rollbackWriteScope(bool $insideTransaction, string $savepoint): void
    {
        if ($insideTransaction) {
            // A deadlock / lock-wait-timeout aborts the whole transaction and
            // discards every savepoint, so under MYSQLI_REPORT_STRICT these
            // statements can throw 1305 "SAVEPOINT does not exist". Swallow
            // that: the caller is about to rethrow the ORIGINAL error, which
            // must not be masked by rollback housekeeping.
            try {
                $this->db->query("ROLLBACK TO SAVEPOINT {$savepoint}");
                $this->db->query("RELEASE SAVEPOINT {$savepoint}");
            } catch (\Throwable) {
                // best-effort — the server already rolled back
            }
            return;
        }
        try {
            $this->db->rollback();
        } catch (\Throwable) {
            // best-effort — a dropped connection must not mask the original error
        }
    }

    private function normalizeEnumValue(mixed $value, string $column, string $default): string
    {
        if (!$this->hasColumn($column)) {
            return $default;
        }

        $options = $this->getEnumOptions('libri', $column);
        if (!$options) {
            return $default;
        }

        $candidate = $this->stringInput($value);
        if ($candidate === '') {
            return in_array($default, $options, true) ? $default : $options[0];
        }

        foreach ($options as $option) {
            if (strcasecmp($option, $candidate) === 0) {
                return $option;
            }
        }

        return in_array($default, $options, true) ? $default : $options[0];
    }

    /**
     * Sanitize the free-text `tipo_acquisizione` value.
     *
     * Since 0.7.25 the column is VARCHAR(50) (was enum('acquisto','donazione')),
     * so the book form's free-text input — "es. Acquisto, Donazione, Prestito" —
     * is finally persisted as typed instead of being coerced to the default.
     *
     * Stays safe on installs where the 0.7.25 migration hasn't run yet: if the
     * column is still a legacy ENUM, fall back to enum coercion so the
     * INSERT/UPDATE can never fail under strict SQL mode. Once the column is
     * VARCHAR, the typed value is trimmed and capped to the column width; an
     * empty value keeps the historical 'acquisto' default (matching the column
     * default, so the field is never silently blanked).
     */
    private function sanitizeAcquisitionType(mixed $value): string
    {
        // Column not yet widened (migration pending) → coerce to a valid enum
        // value so the write can't be rejected. Once migrated, getEnumOptions
        // returns [] and we treat the column as free text.
        $options = $this->getEnumOptions('libri', 'tipo_acquisizione');
        if ($options) {
            return $this->normalizeEnumValue($value, 'tipo_acquisizione', 'acquisto');
        }

        $candidate = $this->stringInput($value);
        if ($candidate === '') {
            return 'acquisto';
        }

        return mb_substr($candidate, 0, 50);
    }

    private function stringInput(mixed $value): string
    {
        if ($value === null || is_array($value)) {
            return '';
        }

        if (is_object($value) && !method_exists($value, '__toString')) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * Replace the contributor roles explicitly supplied by the caller.
     *
     * `autori_ids` is the creator picker, therefore it is authoritative for both
     * `principale` and `co-autore`. Existing co-author roles are preserved for
     * selected people; only newly selected creators default to `principale`.
     * This avoids turning every co-author into a second principal on form
     * resubmission. Secondary contributor keys remain independently partial.
     *
     * The complete insert/verify/prune sequence runs in its own transaction or
     * savepoint. Inserts intentionally do not use INSERT IGNORE: invalid foreign
     * keys and unmigrated enum values must abort and restore every old link.
     *
     * @param array<string,mixed> $data
     */
    private function syncContributors(int $bookId, array $data): void
    {
        $keyToRole = [
            'illustratori_ids' => 'illustratore',
            'traduttori_ids'   => 'traduttore',
            'curatori_ids'     => 'curatore',
            'coloristi_ids'    => 'colorista',
        ];
        $providedRoles = [];
        if (array_key_exists('autori_ids', $data)) {
            $providedRoles['principale'] = true;
            $providedRoles['co-autore'] = true;
        }
        foreach ($keyToRole as $key => $role) {
            if (array_key_exists($key, $data)) {
                $providedRoles[$role] = true;
            }
        }
        if ($providedRoles === []) {
            return;
        }
        $tracksImportSources = $this->hasColumnInTable('libri_autori_import_sources', 'source');

        // MySQL/MariaDB versions differ on @@in_transaction, while querying
        // information_schema.innodb_trx requires PROCESS. A disposable
        // savepoint is portable: in autocommit mode SAVEPOINT is accepted but
        // immediately disappears, so ROLLBACK TO succeeds only inside a real
        // caller-owned transaction.
        $insideTransaction = false;
        $probe = 'pinakes_sync_probe';
        try {
            if ($this->db->query("SAVEPOINT {$probe}")
                && $this->db->query("ROLLBACK TO SAVEPOINT {$probe}")
            ) {
                $insideTransaction = true;
                $this->db->query("RELEASE SAVEPOINT {$probe}");
            }
        } catch (\mysqli_sql_exception) {
            $insideTransaction = false;
        }
        $savepoint = 'pinakes_sync_contributors';
        if ($insideTransaction) {
            if (!$this->db->query("SAVEPOINT {$savepoint}")) {
                throw new \RuntimeException('Database error: unable to create contributor savepoint');
            }
        } elseif (!$this->db->begin_transaction()) {
            throw new \RuntimeException('Database error: unable to begin contributor transaction');
        }

        try {
            $select = $this->db->prepare('SELECT autore_id, ruolo FROM libri_autori WHERE libro_id = ? FOR UPDATE');
            if ($select === false) {
                throw new \RuntimeException('Database error: unable to prepare current contributor read');
            }
            $select->bind_param('i', $bookId);
            if (!$select->execute()) {
                $error = $select->error;
                $select->close();
                throw new \RuntimeException('Database error reading current contributors: ' . $error);
            }
            $result = $select->get_result();
            if (!($result instanceof \mysqli_result)) {
                $select->close();
                throw new \RuntimeException('Database error: current contributor result unavailable');
            }
            /** @var list<array{autore_id:int|string,ruolo:string}> $current */
            $current = $result->fetch_all(MYSQLI_ASSOC);
            $select->close();

            /** @var array<int,string> $currentCreatorRoles */
            $currentCreatorRoles = [];
            /** @var array<string,true> $currentRoles */
            $currentRoles = [];
            foreach ($current as $row) {
                $authorId = (int)$row['autore_id'];
                $role = (string)$row['ruolo'];
                if ($authorId > 0 && $role !== '') {
                    $currentRoles[$role] = true;
                }
                if ($authorId <= 0 || !in_array($role, ['principale', 'co-autore'], true)) {
                    continue;
                }
                if ($role === 'principale' || !isset($currentCreatorRoles[$authorId])) {
                    $currentCreatorRoles[$authorId] = $role;
                }
            }

            /** @var array<string,array{0:int,1:string}> $desired */
            $desired = [];
            if (array_key_exists('autori_ids', $data)) {
                $creatorIds = $data['autori_ids'];
                if (!is_array($creatorIds)) {
                    throw new \InvalidArgumentException('autori_ids must be an array');
                }
                foreach ($creatorIds as $authorData) {
                    $authorId = $this->processAuthorId($authorData);
                    if ($authorId <= 0) {
                        continue;
                    }
                    $role = $currentCreatorRoles[$authorId] ?? 'principale';
                    $desired[$authorId . ':' . $role] = [$authorId, $role];
                }
            }
            foreach ($keyToRole as $key => $role) {
                if (!array_key_exists($key, $data)) {
                    continue;
                }
                $ids = $data[$key];
                if (!is_array($ids)) {
                    throw new \InvalidArgumentException("{$key} must be an array");
                }
                foreach ($ids as $authorData) {
                    $authorId = $this->processAuthorId($authorData);
                    if ($authorId > 0) {
                        $desired[$authorId . ':' . $role] = [$authorId, $role];
                    }
                }
            }

            // An explicit admin-form submission takes manual ownership of the
            // supplied roles. Otherwise a contributor that an administrator
            // deliberately kept could still be deleted by the next re-import
            // because an older importer provenance row remained attached.
            if ($tracksImportSources) {
                $manualize = $this->db->prepare(
                    'DELETE FROM libri_autori_import_sources WHERE libro_id = ? AND ruolo = ?'
                );
                if ($manualize === false) {
                    throw new \RuntimeException('Database error: unable to prepare contributor ownership release');
                }
                foreach (array_keys($providedRoles) as $role) {
                    $manualize->bind_param('is', $bookId, $role);
                    if (!$manualize->execute()) {
                        throw new \RuntimeException('Database error releasing contributor ownership: ' . $manualize->error);
                    }
                }
                $manualize->close();
            }

            if ($desired !== []) {
                $insert = $this->db->prepare(
                    'INSERT INTO libri_autori (libro_id, autore_id, ruolo) VALUES (?, ?, ?) '
                    . 'ON DUPLICATE KEY UPDATE ruolo = VALUES(ruolo)'
                );
                $verify = $this->db->prepare(
                    'SELECT 1 FROM libri_autori WHERE libro_id = ? AND autore_id = ? AND ruolo = ? LIMIT 1'
                );
                if ($insert === false || $verify === false) {
                    if ($insert instanceof \mysqli_stmt) {
                        $insert->close();
                    }
                    if ($verify instanceof \mysqli_stmt) {
                        $verify->close();
                    }
                    throw new \RuntimeException('Database error: unable to prepare contributor upsert verification');
                }
                foreach ($desired as [$authorId, $role]) {
                    $insert->bind_param('iis', $bookId, $authorId, $role);
                    if (!$insert->execute()) {
                        throw new \RuntimeException('Database error inserting contributor: ' . $insert->error);
                    }
                    $verify->bind_param('iis', $bookId, $authorId, $role);
                    if (!$verify->execute() || $verify->get_result()->fetch_row() === null) {
                        throw new \RuntimeException('Database error: requested contributor was not persisted');
                    }
                }
                $insert->close();
                $verify->close();
            }

            $stale = [];
            foreach ($current as $row) {
                $authorId = (int)$row['autore_id'];
                $role = (string)$row['ruolo'];
                if ($authorId > 0 && isset($providedRoles[$role]) && !isset($desired[$authorId . ':' . $role])) {
                    $stale[] = [$authorId, $role];
                }
            }
            if ($stale !== []) {
                $delete = $this->db->prepare(
                    'DELETE FROM libri_autori WHERE libro_id = ? AND autore_id = ? AND ruolo = ?'
                );
                if ($delete === false) {
                    throw new \RuntimeException('Database error: unable to prepare stale contributor delete');
                }
                foreach ($stale as [$authorId, $role]) {
                    $delete->bind_param('iis', $bookId, $authorId, $role);
                    if (!$delete->execute()) {
                        throw new \RuntimeException('Database error deleting stale contributor: ' . $delete->error);
                    }
                }
                $delete->close();
            }

            // Keep the denormalized free-text columns in sync with the entity
            // links so readers that still consume them — CSV export and the
            // public REST API — show contributors set via the #237 role pickers
            // (issue #237 review, finding F009). Only the roles this call
            // actually synced are refreshed; colorista is intentionally absent
            // (it was added as an enum value only, with no free-text column).
            $freeTextRoles = ['traduttore', 'illustratore', 'curatore'];
            foreach ($freeTextRoles as $column) {
                if (!isset($providedRoles[$column]) || !$this->hasColumn($column)) {
                    continue;
                }
                $names = $this->db->prepare(
                    'SELECT a.nome FROM libri_autori la JOIN autori a ON a.id = la.autore_id '
                    . 'WHERE la.libro_id = ? AND la.ruolo = ? ORDER BY a.nome'
                );
                if ($names === false) {
                    throw new \RuntimeException('Database error: unable to prepare contributor cache read');
                }
                $names->bind_param('is', $bookId, $column);
                if (!$names->execute()) {
                    throw new \RuntimeException('Database error reading contributor names: ' . $names->error);
                }
                $nameRows = [];
                $namesResult = $names->get_result();
                while ($nameRow = $namesResult->fetch_row()) {
                    $nameRows[] = (string) $nameRow[0];
                }
                $names->close();

                // Join in PHP (GROUP_CONCAT silently truncates at
                // group_concat_max_len, cutting a name in half) and cap at the
                // varchar(255) column on a NAME boundary — a strict-mode 1406
                // on this cache write must never abort the whole book save.
                $joined = null;
                foreach ($nameRows as $name) {
                    $candidate = $joined === null ? $name : $joined . '; ' . $name;
                    if (mb_strlen($candidate) > 255) {
                        if ($joined === null) {
                            $joined = mb_substr($name, 0, 255);
                        }
                        break;
                    }
                    $joined = $candidate;
                }

                // No entity existed for this role before the save and none was
                // selected now: this is untouched legacy text waiting for the
                // migration backfill, not an explicit removal. Preserve it as
                // the rollback/recovery safety net promised by issue #237.
                if ($joined === null && !isset($currentRoles[$column])) {
                    continue;
                }

                // $column is drawn only from the hardcoded whitelist above — safe
                // to interpolate into the column position (bind_param can't).
                $update = $this->db->prepare("UPDATE libri SET {$column} = ? WHERE id = ? AND deleted_at IS NULL");
                if ($update === false) {
                    throw new \RuntimeException('Database error: unable to prepare contributor cache write');
                }
                $update->bind_param('si', $joined, $bookId);
                if (!$update->execute()) {
                    throw new \RuntimeException('Database error writing contributor cache: ' . $update->error);
                }
                $update->close();
            }

            if ($insideTransaction) {
                if (!$this->db->query("RELEASE SAVEPOINT {$savepoint}")) {
                    throw new \RuntimeException('Database error: unable to release contributor savepoint');
                }
            } elseif (!$this->db->commit()) {
                throw new \RuntimeException('Database error: unable to commit contributor transaction');
            }
        } catch (\Throwable $e) {
            if ($insideTransaction) {
                $this->db->query("ROLLBACK TO SAVEPOINT {$savepoint}");
                $this->db->query("RELEASE SAVEPOINT {$savepoint}");
            } else {
                $this->db->rollback();
            }
            \App\Support\SecureLogger::error('[BookRepository] contributor sync failed', [
                'book_id' => $bookId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Replace the publisher set for a book (issue #143).
     *
     * Deletes the libri_editori rows then inserts the given publisher ids in order
     * (unlike {@see syncContributors()}, which is insert-first/delete-stale to stay
     * non-destructive on invalid ids). New publishers must already be resolved to
     * numeric ids by the controller. The caller keeps libri.editore_id in sync with
     * the first (primary) publisher.
     *
     * @param array<int, int|string> $publisherIds Ordered, deduplicated by caller
     */
    private function syncPublishers(int $bookId, array $publisherIds): void
    {
        // Backward-compat: skip on installs predating the multi-publisher
        // migration (libri_editori absent) — otherwise the DELETE below errors.
        if (!$this->hasColumnInTable('libri_editori', 'editore_id')) {
            return;
        }

        // Prepare the INSERT BEFORE deleting, so a prepare failure can never
        // leave the book with its publishers wiped (non-destructive on error).
        $insert = null;
        if ($publisherIds) {
            $insert = $this->db->prepare('INSERT INTO libri_editori (libro_id, editore_id, ordine) VALUES (?, ?, ?)');
            if ($insert === false) {
                \App\Support\SecureLogger::error('[BookRepository] prepare failed inserting book publishers', ['book_id' => $bookId]);
                throw new \RuntimeException("Database error: unable to insert book publishers");
            }
        }

        $del = $this->db->prepare('DELETE FROM libri_editori WHERE libro_id = ?');
        if ($del === false) {
            if ($insert !== null) { $insert->close(); }
            \App\Support\SecureLogger::error('[BookRepository] prepare failed deleting book publishers', ['book_id' => $bookId]);
            throw new \RuntimeException("Database error: unable to delete book publishers");
        }
        $del->bind_param('i', $bookId);
        $del->execute();
        $del->close();

        if ($insert === null) {
            return;
        }
        $ordine = 0;
        foreach ($publisherIds as $publisherData) {
            $publisherId = is_numeric($publisherData) ? (int) $publisherData : 0;
            if ($publisherId > 0) {
                $insert->bind_param('iii', $bookId, $publisherId, $ordine);
                if (!$insert->execute()) {
                    throw new \RuntimeException('Database error inserting book publisher: ' . $insert->error);
                }
                $ordine++;
            }
        }
        $insert->close();
    }

    private function processAuthorId(mixed $authorData): int
    {
        // Handle both old format (just ID) and new format (could be temp ID with label)
        if (is_numeric($authorData)) {
            $authorId = (int) $authorData;
            if ($authorId <= 0) {
                throw new \InvalidArgumentException('Contributor ID must be a positive integer');
            }
            return $authorId;
        }

        // Handle new author format from Choices.js (new_timestamp)
        // Note: new authors should be resolved to numeric IDs in LibriController::store()
        // before reaching syncContributors(). If we get here, it indicates a frontend bug.
        if (is_string($authorData) && strpos($authorData, 'new_') === 0) {
            throw new \InvalidArgumentException("Unresolved contributor ID received: {$authorData}");
        }

        if ($authorData === null || $authorData === '') {
            return 0;
        }
        throw new \InvalidArgumentException('Unexpected contributor ID format: ' . gettype($authorData));
    }

    private function getScaffaleLetter(int $scaffaleId): ?string
    {
        $stmt = $this->db->prepare('SELECT lettera FROM scaffali WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $scaffaleId);
        $stmt->execute();
        $stmt->bind_result($lettera);
        if ($stmt->fetch()) {
            return $lettera;
        }
        return null;
    }

    private function getMensolaLevel(int $mensolaId): ?int
    {
        $stmt = $this->db->prepare('SELECT numero_livello FROM mensole WHERE id=? LIMIT 1');
        if (!$stmt) {
            error_log('[BookRepository] getMensolaLevel prepare failed: ' . $this->db->error);
            return null;
        }
        $stmt->bind_param('i', $mensolaId);
        $stmt->execute();
        $stmt->bind_result($level);
        if ($stmt->fetch()) {
            return $level !== null ? (int) $level : null;
        }
        return null;
    }

    private function buildCollocazioneString(?int $scaffaleId, ?int $mensolaId, ?int $posizioneProgressiva): string
    {
        if (!$scaffaleId || !$mensolaId || !$posizioneProgressiva) {
            return '';
        }

        $lettera = $this->getScaffaleLetter($scaffaleId);
        if ($lettera === null || $lettera === '') {
            return '';
        }

        $level = $this->getMensolaLevel($mensolaId);
        if ($level === null) {
            return '';
        }

        return sprintf('%s-%d-%02d', strtoupper(trim($lettera)), $level, $posizioneProgressiva);
    }

    public function delete(int $id): bool
    {
        // Internal SOFT DELETE
        // Nullify unique identifiers to avoid blocking new books with the same ISBN/EAN
        // Also clear collocazione fields to free the shelf position
        // Does not delete related records (kept for history/integrity)
        $stmt = $this->db->prepare('UPDATE libri SET deleted_at = NOW(), isbn10 = NULL, isbn13 = NULL, ean = NULL, scaffale_id = NULL, mensola_id = NULL, posizione_progressiva = NULL, collocazione = NULL WHERE id=?');
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    public function updateOptionals(int $bookId, array $data): void
    {
        $cols = [];
        foreach (['numero_pagine', 'ean', 'data_pubblicazione', 'anno_pubblicazione', 'traduttore', 'illustratore', 'curatore', 'collana', 'edizione', 'tipo_media', 'parole_chiave'] as $c) {
            if ($this->hasColumn($c) && array_key_exists($c, $data) && $data[$c] !== '' && $data[$c] !== null) {
                if ($c === 'numero_pagine') {
                    $validated = filter_var($data[$c], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($validated !== false) {
                        $cols[$c] = $validated;
                    }
                } elseif ($c === 'anno_pubblicazione') {
                    $validated = filter_var($data[$c], FILTER_VALIDATE_INT);
                    if ($validated !== false) {
                        $cols[$c] = $validated;
                    }
                } elseif ($c === 'tipo_media') {
                    if (!is_string($data[$c])) { continue; }
                    $cols[$c] = $this->normalizeEnumValue((string) $data[$c], 'tipo_media', 'libro');
                } elseif (in_array($c, ['traduttore', 'illustratore', 'curatore'], true)) {
                    $cols[$c] = \App\Support\AuthorNormalizer::normalize((string) $data[$c]);
                } else {
                    $cols[$c] = $data[$c];
                }
            }
        }
        // Map scraped_* into columns if present
        if ($this->hasColumn('numero_pagine') && !isset($cols['numero_pagine']) && !empty($data['scraped_pages'])) {
            $validated = filter_var($data['scraped_pages'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($validated !== false) {
                $cols['numero_pagine'] = $validated;
            }
        }
        if ($this->hasColumn('ean') && !isset($cols['ean']) && !empty($data['scraped_ean'])) {
            $cols['ean'] = (string) $data['scraped_ean'];
        }
        if ($this->hasColumn('data_pubblicazione') && !isset($cols['data_pubblicazione']) && !empty($data['scraped_pub_date'])) {
            $cols['data_pubblicazione'] = (string) $data['scraped_pub_date'];
        }
        if ($this->hasColumn('collana') && !isset($cols['collana']) && !empty($data['scraped_series'])) {
            $rawSeries = trim((string) $data['scraped_series']);
            $seriesNum = null;
            // Split "Series Name ; Number" or "Series Name (Number)" into collana + numero_serie
            if (preg_match('/^(.+?)\s*;\s*(\d+)\s*$/', $rawSeries, $sm)) {
                $cols['collana'] = trim($sm[1]);
                $seriesNum = $sm[2];
            } elseif (preg_match('/^(.+?)\s*\((\d+)\)\s*$/', $rawSeries, $sm)) {
                $cols['collana'] = trim($sm[1]);
                $seriesNum = $sm[2];
            } else {
                $cols['collana'] = $rawSeries;
            }
            if ($seriesNum !== null && $this->hasColumn('numero_serie') && empty($data['numero_serie'])) {
                $cols['numero_serie'] = $seriesNum;
            }
        }
        // Manual values are mapped into $cols above. Scraped values are only a
        // fallback, otherwise a form submit carrying both fields would silently
        // overwrite the librarian's typed translator/illustrator. The admission
        // test mirrors the field loop above (array_key_exists + !== '' + !== null),
        // so a manually-entered literal "0" counts as present and is NOT overwritten
        // by the scraped value (empty("0") === true would treat it as absent).
        // When the submit carries the #237 entity role list, the picker is
        // authoritative: syncContributors already wrote the compatibility cache
        // from the entity links, and a stale scraped_* value (e.g. a chip the
        // librarian removed before saving) must not clobber it. The scraped
        // fallback stays for flows that don't send *_ids (legacy imports).
        $traduttoreProvided = array_key_exists('traduttore', $data) && $data['traduttore'] !== '' && $data['traduttore'] !== null;
        if ($this->hasColumn('traduttore') && !$traduttoreProvided
            && !array_key_exists('traduttori_ids', $data)
            && !empty($data['scraped_translator'])) {
            $cols['traduttore'] = \App\Support\AuthorNormalizer::normalize((string) $data['scraped_translator']);
        }
        $illustratoreProvided = array_key_exists('illustratore', $data) && $data['illustratore'] !== '' && $data['illustratore'] !== null;
        if ($this->hasColumn('illustratore') && !$illustratoreProvided
            && !array_key_exists('illustratori_ids', $data)
            && !empty($data['scraped_illustrator'])) {
            $cols['illustratore'] = \App\Support\AuthorNormalizer::normalize((string) $data['scraped_illustrator']);
        }
        if ($this->hasColumn('tipo_media') && !array_key_exists('tipo_media', $cols)) {
            $formato = trim((string) ($data['formato'] ?? ($data['scraped_formato'] ?? '')));
            $scrapedTipoMedia = trim((string) ($data['scraped_tipo_media'] ?? ''));
            $hasMediaSignal = $formato !== '' || $scrapedTipoMedia !== '';

            if ($hasMediaSignal) {
                $val = \App\Support\MediaLabels::resolveTipoMedia(
                    $formato !== '' ? $formato : null,
                    $scrapedTipoMedia !== '' ? $scrapedTipoMedia : null
                );
                $normalized = $this->normalizeEnumValue((string) $val, 'tipo_media', 'libro');
                if ($normalized !== '') {
                    $cols['tipo_media'] = $normalized;
                }
            }
        }
        if (!$cols)
            return;
        $set = [];
        $types = '';
        $vals = [];
        foreach ($cols as $k => $v) {
            $set[] = "$k = ?";
            if ($k === 'numero_pagine' || $k === 'anno_pubblicazione') {
                $types .= 'i';
                $vals[] = (int) $v;
            } else {
                $types .= 's';
                $vals[] = (string) $v;
            }
        }
        $sql = 'UPDATE libri SET ' . implode(', ', $set) . ', updated_at=NOW() WHERE id=? AND deleted_at IS NULL';
        $types .= 'i';
        $vals[] = $bookId;
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$vals);
        $stmt->execute();
    }

    /**
     * Resolve genre hierarchy for the 3-level cascade (Radice → Genere → Sottogenere).
     *
     * Walks up the tree from genere_id to find the full ancestor chain,
     * then maps IDs to the correct cascade levels so the edit form can
     * pre-populate all three dropdowns.
     *
     * Adds COMPUTED, request-only keys prefixed `resolved_` — `resolved_genere_id`
     * and `resolved_sottogenere_id` — that hold the depth-correct ids for the
     * three dropdown slots. They are NOT columns on `libri` (which stores only
     * `genere_id`/`sottogenere_id`); the `resolved_` prefix marks them as a
     * derived view of the stored ids, not duplicates (see discussion #286).
     *
     * @param array<string, mixed> $row Book row (modified in place)
     */
    private function resolveGenreHierarchy(array &$row): void
    {
        // Default values
        $row['radice_id'] = $row['radice_id'] ?? 0;

        $genereId = (int)($row['genere_id'] ?? 0);
        if ($genereId <= 0) {
            return;
        }

        // Walk up the tree from genere_id to collect the full ancestor chain
        $chain = [];
        $currentId = $genereId;
        $maxDepth = 5; // safety limit

        $stmt = $this->db->prepare('SELECT id, nome, parent_id FROM generi WHERE id = ?');
        if (!$stmt) {
            return;
        }
        while ($currentId > 0 && $maxDepth-- > 0) {
            $stmt->bind_param('i', $currentId);
            $stmt->execute();
            $genre = $stmt->get_result()->fetch_assoc();
            if (!$genre) {
                break;
            }
            array_unshift($chain, $genre); // prepend: root first
            $currentId = (int)($genre['parent_id'] ?? 0);
        }
        $stmt->close();

        // Also resolve sottogenere_id chain if present
        $sottogenereId = (int)($row['sottogenere_id'] ?? 0);

        // Map chain to the 3-level cascade based on where genere_id sits
        // chain[0] = root (L1), chain[1] = genre (L2), chain[2] = subgenre (L3)
        $chainLen = count($chain);

        if ($chainLen === 0) {
            // genre_id points to a deleted/missing genre — clear cascade fields
            $row['radice_id'] = 0;
            $row['radice_nome'] = null;
            $row['genere_nome'] = null;
            $row['resolved_genere_id'] = 0;
            $row['sottogenere_nome'] = null;
            $row['resolved_sottogenere_id'] = 0;
            return;
        }

        if ($chainLen === 1) {
            // genere_id points to a ROOT genre (L1)
            $row['radice_id'] = $chain[0]['id'];
            $row['radice_nome'] = $chain[0]['nome'];

            if ($sottogenereId > 0) {
                // Resolve sottogenere ancestry to populate cascade dropdowns (#90)
                $subChain = [];
                $curId = $sottogenereId;
                $depth = 5;
                $subStmt = $this->db->prepare('SELECT id, nome, parent_id FROM generi WHERE id = ?');
                if ($subStmt) {
                    while ($curId > 0 && $depth-- > 0) {
                        $subStmt->bind_param('i', $curId);
                        $subStmt->execute();
                        $sg = $subStmt->get_result()->fetch_assoc();
                        if (!$sg) {
                            break;
                        }
                        array_unshift($subChain, $sg);
                        $curId = (int)($sg['parent_id'] ?? 0);
                    }
                    $subStmt->close();
                }
                // Strip root from subChain (it's already in $chain[0])
                if (!empty($subChain) && (int)$subChain[0]['id'] === (int)$chain[0]['id']) {
                    array_shift($subChain);
                } elseif (!empty($subChain)) {
                    // Root mismatch — skip modifications to avoid corrupting cascade
                    $subChain = [];
                }
                if (count($subChain) >= 2) {
                    // L2 + deepest descendant
                    $deepest = end($subChain);
                    $row['genere_nome'] = $subChain[0]['nome'];
                    $row['resolved_genere_id'] = (int)$subChain[0]['id'];
                    $row['sottogenere_nome'] = $deepest['nome'];
                    $row['resolved_sottogenere_id'] = (int)$deepest['id'];
                } elseif (count($subChain) === 1) {
                    // Direct child of root → L2 only
                    $row['genere_nome'] = $subChain[0]['nome'];
                    $row['resolved_genere_id'] = (int)$subChain[0]['id'];
                    $row['sottogenere_nome'] = null;
                    $row['resolved_sottogenere_id'] = 0;
                } else {
                    $row['genere_nome'] = null;
                    $row['resolved_genere_id'] = 0;
                    $row['sottogenere_nome'] = null;
                    $row['resolved_sottogenere_id'] = 0;
                }
            } else {
                $row['genere_nome'] = null;
                $row['resolved_genere_id'] = 0;
                $row['sottogenere_nome'] = null;
                $row['resolved_sottogenere_id'] = 0;
            }
        } elseif ($chainLen === 2) {
            // genere_id points to L2 genre — standard case
            $row['radice_id'] = $chain[0]['id'];
            $row['radice_nome'] = $chain[0]['nome'];
            $row['genere_nome'] = $chain[1]['nome'];
            $row['resolved_genere_id'] = $chain[1]['id'];
            $row['resolved_sottogenere_id'] = $sottogenereId;
        } else {
            // genere_id points to L3+ — stored at a deeper level
            // Map: root=chain[0], genre=chain[1], sotto=genere_id
            $row['radice_id'] = $chain[0]['id'];
            $row['radice_nome'] = $chain[0]['nome'];
            $row['genere_nome'] = $chain[1]['nome'];
            $row['resolved_genere_id'] = $chain[1]['id'];
            $row['sottogenere_nome'] = $chain[$chainLen - 1]['nome'];
            $row['resolved_sottogenere_id'] = $chain[$chainLen - 1]['id'];
        }
    }

    private function toPlainTextDescription(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }
        $text = preg_replace(
            '/<(?:\/?(?:p|div|li|ul|ol|h[1-6]|blockquote|tr|th|td)\b[^>]*|br\b[^>]*\/?)>/i',
            "\n",
            $html
        );
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', (string) $text);
        $text = preg_replace("/[ \t]+/", ' ', (string) $text);
        $text = preg_replace("/\n{3,}/", "\n\n", (string) $text);
        return trim((string) $text);
    }

    private static array $columnCacheByDb = [];
    private function hasColumn(string $name): bool
    {
        $dbRes = $this->db->query('SELECT DATABASE()');
        $dbName = ($dbRes ? (string) ($dbRes->fetch_row()[0] ?? 'default') : 'default');
        if (!isset(self::$columnCacheByDb[$dbName])) {
            self::$columnCacheByDb[$dbName] = [];
            $res = $this->db->query('SHOW COLUMNS FROM libri');
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    self::$columnCacheByDb[$dbName][$r['Field']] = true;
                }
            }
        }
        return isset(self::$columnCacheByDb[$dbName][$name]);
    }

    private static array $tableColumnCache = [];
    private function hasColumnInTable(string $table, string $name): bool
    {
        // Whitelist di tabelle valide per prevenire SQL injection
        $validTables = [
            'libri',
            'autori',
            'libri_autori',
            'libri_autori_import_sources',
            'editori',
            'libri_editori',
            'generi',
            'utenti',
            'prestiti',
            'prenotazioni',
            'posizioni',
            'scaffali',
            'mensole',
            'collane'
        ];

        if (!in_array($table, $validTables, true)) {
            return false;
        }

        if (!isset(self::$tableColumnCache[$table])) {
            self::$tableColumnCache[$table] = [];
            // Usa prepared statement con INFORMATION_SCHEMA
            $stmt = $this->db->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            if ($stmt) {
                $stmt->bind_param('s', $table);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    self::$tableColumnCache[$table][$r['COLUMN_NAME']] = true;
                }
                $stmt->close();
            }
        }
        return isset(self::$tableColumnCache[$table][$name]);
    }

    // Cache for enum options
    private array $enumCache = [];

    private function getEnumOptions(string $table, string $column): array
    {
        $key = $table . '.' . $column;
        if (isset($this->enumCache[$key]))
            return $this->enumCache[$key];
        $opts = [];
        $stmt = $this->db->prepare('SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('ss', $table, $column);
            if ($stmt->execute()) {
                $stmt->bind_result($columnType);
                if ($stmt->fetch() && preg_match("/enum\\((.*)\\)/i", (string) $columnType, $m)) {
                    $vals = str_getcsv($m[1], ',', "'", "\\");
                    foreach ($vals as $v) {
                        $opts[] = $v;
                    }
                }
            }
            $stmt->close();
        }
        return $this->enumCache[$key] = $opts;
    }
}
